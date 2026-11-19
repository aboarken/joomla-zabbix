<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Monitoring;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;

/**
 * Hosts a portal user may see, with their availability and current problems.
 */
final class HostService
{
    public function __construct(
        private readonly JsonRpcClient $client,
        private readonly ProblemService $problems
    ) {
    }

    /**
     * All visible hosts (the Zabbix API has no offset, so the list is read once and paged by the
     * page itself), with problem counts per severity.
     *
     * @param  list<string>  $groupIds  [] = every group the user may see
     *
     * @return list<array<string, mixed>>  sorted by name
     */
    public function hosts(AccessScope $scope, array $groupIds = [], string $search = '', int $max = 2000): array
    {
        $groups = $scope->groupFilter($groupIds);

        if ($groups === []) {
            return [];
        }

        $params = [
            'output'           => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'active_available'],
            'selectInterfaces' => ['type', 'available', 'main', 'ip', 'dns', 'useip', 'port'],
            'selectHostGroups' => ['groupid', 'name'],
            'sortfield'        => 'name',
            'limit'            => max(1, $max),
        ];

        if ($groups !== null) {
            $params['groupids'] = $groups;
        }

        $search = trim($search);

        if ($search !== '') {
            $params['search']      = ['name' => $search, 'host' => $search];
            $params['searchByAny'] = true;
        }

        $rows   = $this->client->call('host.get', $params);
        $hosts  = [];
        $counts = $this->problemCounts($scope, $groupIds);

        foreach (\is_array($rows) ? $rows : [] as $row) {
            $hostGroups = array_values(array_map(
                static fn ($g): array => ['groupid' => (string) ($g['groupid'] ?? ''), 'name' => (string) ($g['name'] ?? '')],
                (array) ($row['hostgroups'] ?? [])
            ));

            if (!$scope->allowsHostGroups(array_column($hostGroups, 'groupid'))) {
                continue;
            }

            $hosts[] = self::summary($row, $scope->namedGroups($hostGroups), $counts[(string) $row['hostid']] ?? array_fill(0, 6, 0));
        }

        return $hosts;
    }

    /**
     * One host with interfaces, groups, tags, templates and inventory, or null when the user may
     * not see it (or it does not exist).
     *
     * @return array<string, mixed>|null
     */
    public function host(AccessScope $scope, string $hostId): ?array
    {
        $groups = $scope->groupFilter();

        if (!ctype_digit($hostId) || $groups === []) {
            return null;
        }

        $params = [
            'output'                => ['hostid', 'host', 'name', 'status', 'maintenance_status', 'active_available', 'description', 'inventory_mode'],
            'hostids'               => [$hostId],
            'selectInterfaces'      => ['interfaceid', 'type', 'available', 'main', 'ip', 'dns', 'useip', 'port', 'error'],
            'selectHostGroups'      => ['groupid', 'name'],
            'selectTags'            => ['tag', 'value'],
            'selectParentTemplates' => ['templateid', 'name'],
            'selectInventory'       => 'extend',
        ];

        if ($groups !== null) {
            $params['groupids'] = $groups;
        }

        $rows = $this->client->call('host.get', $params);
        $row  = \is_array($rows) && \is_array($rows[0] ?? null) ? $rows[0] : null;

        if ($row === null) {
            return null;
        }

        $hostGroups = array_values(array_map(
            static fn ($g): array => ['groupid' => (string) ($g['groupid'] ?? ''), 'name' => (string) ($g['name'] ?? '')],
            (array) ($row['hostgroups'] ?? [])
        ));

        if (!$scope->allowsHostGroups(array_column($hostGroups, 'groupid'))) {
            return null;
        }

        $problems = $this->problems->problems($scope, [], [$hostId], [], 200);
        $counts   = array_fill(0, 6, 0);

        foreach ($problems as $problem) {
            $counts[$problem['severity']]++;
        }

        $host = self::summary($row, $scope->namedGroups($hostGroups), $counts);

        $host['description'] = (string) ($row['description'] ?? '');
        $host['interfaces']  = array_values(array_map(static fn ($i): array => [
            'type'      => Availability::TYPES[(int) ($i['type'] ?? 0)] ?? 'other',
            'address'   => (string) (((string) ($i['useip'] ?? '1')) === '1' ? ($i['ip'] ?? '') : ($i['dns'] ?? '')),
            'port'      => (string) ($i['port'] ?? ''),
            'main'      => (string) ($i['main'] ?? '0') === '1',
            'state'     => Availability::interfaceState($i['available'] ?? 0),
            'error'     => (string) ($i['error'] ?? ''),
        ], (array) ($row['interfaces'] ?? [])));
        $host['tags']      = array_values(array_map(static fn ($t): array => ['tag' => (string) ($t['tag'] ?? ''), 'value' => (string) ($t['value'] ?? '')], (array) ($row['tags'] ?? [])));
        $host['templates'] = array_values(array_map(static fn ($t): string => (string) ($t['name'] ?? ''), (array) ($row['parentTemplates'] ?? [])));
        $host['inventory'] = array_filter(
            \is_array($row['inventory'] ?? null) ? array_map('strval', $row['inventory']) : [],
            static fn (string $v, string $k): bool => $v !== '' && !\in_array($k, ['hostid', 'inventory_mode'], true),
            ARRAY_FILTER_USE_BOTH
        );
        $host['problemList'] = $problems;

        return $host;
    }

    /**
     * Latest data of a host's monitored items (the host must have been checked with host()).
     *
     * @return list<array<string, mixed>>
     */
    public function items(string $hostId, int $limit = 1000): array
    {
        $rows = $this->client->call('item.get', [
            'output'         => ['itemid', 'name', 'key_', 'lastvalue', 'lastclock', 'units', 'value_type', 'state'],
            'hostids'        => [$hostId],
            'monitored'      => true,
            'webitems'       => true,
            'selectValueMap' => ['mappings'],
            'sortfield'      => 'name',
            'limit'          => $limit,
        ]);

        $items = [];

        foreach (\is_array($rows) ? $rows : [] as $row) {
            $items[] = [
                'itemid'    => (string) $row['itemid'],
                'name'      => (string) ($row['name'] ?? ''),
                'key'       => (string) ($row['key_'] ?? ''),
                'value'     => ValueFormatter::format($row, $row['lastvalue'] ?? ''),
                'lastclock' => (int) ($row['lastclock'] ?? 0),
                'numeric'   => ValueFormatter::isNumeric($row['value_type'] ?? 4),
                'units'     => (string) ($row['units'] ?? ''),
                'state'     => (int) ($row['state'] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * Problems per host and severity.
     *
     * @param  list<string>  $groupIds
     * @return array<string, array<int, int>>
     */
    public function problemCounts(AccessScope $scope, array $groupIds = []): array
    {
        $counts = [];

        foreach ($this->problems->problems($scope, $groupIds, [], [], ProblemService::MAX) as $problem) {
            foreach ($problem['hosts'] as $host) {
                $counts[$host['hostid']] ??= array_fill(0, 6, 0);
                $counts[$host['hostid']][$problem['severity']]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>                            $row
     * @param  list<array{groupid: string, name: string}>      $groups
     * @param  array<int, int>                                 $counts
     * @return array<string, mixed>
     */
    private static function summary(array $row, array $groups, array $counts): array
    {
        $worst = -1;

        foreach ($counts as $severity => $count) {
            if ($count > 0) {
                $worst = max($worst, (int) $severity);
            }
        }

        // State of each interface type, as Zabbix's Host availability widget counts it.
        $byType = [];

        foreach ((array) ($row['interfaces'] ?? []) as $interface) {
            $type            = Availability::TYPES[(int) ($interface['type'] ?? 0)] ?? 'other';
            $byType[$type][] = (int) ($interface['available'] ?? 0);
        }

        $types = array_map(static fn (array $states): string => Availability::typeState($states), $byType);

        // Active agent checks: the host's own active_available (0 = no active checks / unknown).
        if ((int) ($row['active_available'] ?? 0) !== 0) {
            $types['agent_active'] = Availability::interfaceState($row['active_available']);
        }

        return [
            'hostid'        => (string) $row['hostid'],
            'host'          => (string) ($row['host'] ?? ''),
            'name'          => (string) ($row['name'] ?? $row['host'] ?? ''),
            'enabled'       => (string) ($row['status'] ?? '0') === '0',
            'maintenance'   => (string) ($row['maintenance_status'] ?? '0') === '1',
            'availability'  => Availability::of((array) ($row['interfaces'] ?? []), $row['active_available'] ?? 0),
            'interfaces'    => $types,
            'groups'        => $groups,
            'problems'      => array_values($counts),
            'problemTotal'  => array_sum($counts),
            'worst'         => $worst,
        ];
    }
}
