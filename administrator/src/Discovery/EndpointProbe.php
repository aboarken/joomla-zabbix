<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Discovery;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\WidgetTypes;

/**
 * Find Endpoints: what the Zabbix API offers this integration.
 *
 *  - Server: API address, version, response time.
 *  - Token: its user, role and role type; the role's API rules when the token may read them.
 *  - Methods: each method the portal uses is called once with a harmless limit and reported as
 *    allowed, denied or error.
 *  - Inventory: how many host groups, hosts, users, dashboards and problems the token sees,
 *    and which widget types the dashboards use.
 *
 * Only read methods are called. The report holds names and counts, never secrets.
 */
final class EndpointProbe
{
    public const STATUS_ALLOWED = 'allowed';
    public const STATUS_DENIED  = 'denied';
    public const STATUS_ERROR   = 'error';

    /** method => [feature (language key suffix), params, required] */
    public const METHODS = [
        'apiinfo.version'          => ['CONNECTION', [], true],
        'user.checkAuthentication' => ['CONNECTION', null, true],
        'user.get'                 => ['MAPPINGS', ['output' => ['userid'], 'limit' => 1], true],
        'usergroup.get'            => ['MAPPINGS', ['output' => ['usrgrpid'], 'limit' => 1], true],
        'role.get'                 => ['DIAGNOSTICS', ['output' => ['roleid'], 'limit' => 1], false],
        'hostgroup.get'            => ['HOSTS', ['output' => ['groupid'], 'limit' => 1], true],
        'host.get'                 => ['HOSTS', ['output' => ['hostid'], 'limit' => 1], true],
        'item.get'                 => ['HOSTS', ['output' => ['itemid'], 'limit' => 1], true],
        'history.get'              => ['CHARTS', ['output' => ['itemid'], 'history' => 0, 'itemids' => ['0'], 'limit' => 1], true],
        'trend.get'                => ['CHARTS', ['output' => ['itemid'], 'itemids' => ['0'], 'limit' => 1], true],
        'problem.get'              => ['PROBLEMS', ['output' => ['eventid'], 'limit' => 1], true],
        'trigger.get'              => ['PROBLEMS', ['output' => ['triggerid'], 'limit' => 1], true],
        'dashboard.get'            => ['DASHBOARDS', ['output' => ['dashboardid'], 'limit' => 1], true],
        'graph.get'                => ['DASHBOARDS', ['output' => ['graphid'], 'limit' => 1], false],
        'settings.get'             => ['PROBLEMS', ['output' => ['severity_color_0']], false],
    ];

    /** Inventory counts (method => language key suffix). */
    public const COUNTS = [
        'hostgroup.get' => 'HOSTGROUPS',
        'host.get'      => 'HOSTS',
        'template.get'  => 'TEMPLATES',
        'user.get'      => 'USERS',
        'dashboard.get' => 'DASHBOARDS',
        'problem.get'   => 'PROBLEMS',
    ];

    public function __construct(private readonly JsonRpcClient $client)
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ZabbixException when the server cannot be reached at all
     */
    public function run(): array
    {
        $report = ['url' => $this->client->url(), 'at' => time()];

        // Server.
        $started           = hrtime(true);
        $report['version'] = $this->client->version();
        $report['ms']      = (int) ((hrtime(true) - $started) / 1e6);
        $report['version_ok'] = version_compare($report['version'], '7.4', '>=') && version_compare($report['version'], '7.5', '<');

        // Token.
        $report['token'] = $this->tokenInfo();

        // Methods.
        $report['methods'] = [];

        foreach (self::METHODS as $method => [$feature, $params, $required]) {
            $report['methods'][] = ['method' => $method, 'feature' => $feature, 'required' => $required] + $this->probe($method, $params);
        }

        // Inventory.
        $report['counts'] = [];

        foreach (self::COUNTS as $method => $label) {
            try {
                $params = ['countOutput' => true];

                if ($method === 'problem.get') {
                    $params += ['source' => 0, 'object' => 0];
                }

                $report['counts'][$label] = (int) $this->client->call($method, $params);
            } catch (ZabbixException) {
                $report['counts'][$label] = null;
            }
        }

        $report['dashboards'] = $this->dashboards();
        $report['warnings']   = $this->warnings($report);

        return $report;
    }

    /** @return array<string, mixed> */
    private function tokenInfo(): array
    {
        try {
            $user = $this->client->tokenUser();
        } catch (ZabbixException $e) {
            return ['ok' => false, 'error' => $e->getKind(), 'detail' => $e->getDetail()];
        }

        $info = [
            'ok'       => true,
            'userid'   => (string) ($user['userid'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'type'     => (int) ($user['type'] ?? 0),
            'roleid'   => (string) ($user['roleid'] ?? ''),
            'role'     => '',
            'rules'    => null,
        ];

        try {
            $roles = $this->client->call('role.get', [
                'output'      => ['roleid', 'name', 'type'],
                'roleids'     => [$info['roleid']],
                'selectRules' => ['api.access', 'api.mode', 'api'],
            ]);
            $role = \is_array($roles) && \is_array($roles[0] ?? null) ? $roles[0] : null;

            if ($role !== null) {
                $info['role'] = (string) ($role['name'] ?? '');
                $rules        = ApiRules::fromRole((array) ($role['rules'] ?? []));
                $info['rules'] = [
                    'enabled' => $rules->enabled,
                    'mode'    => $rules->mode === ApiRules::MODE_ALLOW ? 'allow' : 'deny',
                    'methods' => $rules->methods,
                    'write'   => $rules->writeMethodsAllowed(),
                ];
            }
        } catch (ZabbixException) {
            // Only Super admins may read roles; the probe below still shows what works.
        }

        return $info;
    }

    /**
     * @param  array<string, mixed>|null  $params  null = user.checkAuthentication with the token
     * @return array{status: string, ms: int, detail: string}
     */
    private function probe(string $method, ?array $params): array
    {
        $started = hrtime(true);

        try {
            if ($params === null) {
                $this->client->tokenUser();
            } else {
                $this->client->call($method, $params);
            }

            $status = self::STATUS_ALLOWED;
            $detail = '';
        } catch (ZabbixException $e) {
            $status = $e->getKind() === ZabbixException::PERMISSION ? self::STATUS_DENIED : self::STATUS_ERROR;
            $detail = $e->getDetail() !== '' ? $e->getDetail() : $e->getKind();
        }

        return ['status' => $status, 'ms' => (int) ((hrtime(true) - $started) / 1e6), 'detail' => $detail];
    }

    /** @return list<array<string, mixed>> */
    private function dashboards(): array
    {
        try {
            $rows = $this->client->call('dashboard.get', [
                'output'      => ['dashboardid', 'name', 'userid', 'private'],
                'selectPages' => ['widgets'],
                'sortfield'   => 'name',
            ]);
        } catch (ZabbixException) {
            return [];
        }

        $list = [];

        foreach (\is_array($rows) ? $rows : [] as $row) {
            $types = [];

            foreach ((array) ($row['pages'] ?? []) as $page) {
                foreach ((array) ($page['widgets'] ?? []) as $widget) {
                    $type         = (string) ($widget['type'] ?? '');
                    $types[$type] = ($types[$type] ?? 0) + 1;
                }
            }

            ksort($types);
            $list[] = [
                'id'          => (string) $row['dashboardid'],
                'name'        => (string) ($row['name'] ?? ''),
                'public'      => (string) ($row['private'] ?? '1') === '0',
                'pages'       => \count((array) ($row['pages'] ?? [])),
                'widgets'     => $types,
                'unsupported' => array_values(array_filter(array_keys($types), static fn (string $t): bool => !WidgetTypes::isSupported($t))),
            ];
        }

        return $list;
    }

    /**
     * Language key suffixes of problems found.
     *
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function warnings(array $report): array
    {
        $warnings = [];

        if (!$report['version_ok']) {
            $warnings[] = 'VERSION';
        }

        if (!($report['token']['ok'] ?? false)) {
            $warnings[] = 'TOKEN';
        } elseif (($report['token']['type'] ?? 0) !== 3) {
            $warnings[] = 'NOT_SUPER_ADMIN';
        }

        if (($report['token']['rules']['write'] ?? []) !== []) {
            $warnings[] = 'WRITE_ALLOWED';
        }

        foreach ($report['methods'] as $method) {
            if ($method['required'] && $method['status'] !== self::STATUS_ALLOWED) {
                $warnings[] = 'METHODS';
                break;
            }
        }

        if (str_starts_with(strtolower((string) $report['url']), 'http://')) {
            $warnings[] = 'HTTP';
        }

        return $warnings;
    }
}
