<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\CacheInterface;

/**
 * Zabbix dashboards (dashboard.get) and who may see them (AccessScope::canSeeDashboard()).
 * Definitions are kept in the shared cache; whether a user may see one is checked every time.
 */
final class DashboardService
{
    private const TTL = 300;

    public function __construct(
        private readonly JsonRpcClient $client,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * Every dashboard the token sees, with sharing.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->cache->remember(0, 'dashboards', function (): array {
            $rows = $this->client->call('dashboard.get', [
                'output'           => ['dashboardid', 'name', 'userid', 'private'],
                'selectUsers'      => ['userid', 'permission'],
                'selectUserGroups' => ['usrgrpid', 'permission'],
                'sortfield'        => 'name',
            ]);

            return array_values(array_filter(\is_array($rows) ? $rows : [], 'is_array'));
        }, self::TTL);
    }

    /**
     * Dashboards this user may open, sorted by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function visibleTo(AccessScope $scope): array
    {
        $list = [];

        foreach ($this->all() as $dashboard) {
            if ($scope->canSeeDashboard($dashboard)) {
                $list[] = ['id' => (string) $dashboard['dashboardid'], 'name' => (string) ($dashboard['name'] ?? '')];
            }
        }

        usort($list, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $list;
    }

    /**
     * One dashboard's layout (DashboardLayout::fromApi()), or null when it does not exist or the
     * user may not see it.
     *
     * @return array{id: string, name: string, pages: list<array<string, mixed>>}|null
     */
    public function layout(AccessScope $scope, string $dashboardId): ?array
    {
        if (!ctype_digit($dashboardId)) {
            return null;
        }

        $meta = null;

        foreach ($this->all() as $dashboard) {
            if ((string) $dashboard['dashboardid'] === $dashboardId) {
                $meta = $dashboard;
            }
        }

        if ($meta === null || !$scope->canSeeDashboard($meta)) {
            return null;
        }

        $layout = $this->cache->remember(0, 'dashboard:' . $dashboardId, function () use ($dashboardId): array {
            $rows = $this->client->call('dashboard.get', [
                'output'       => ['dashboardid', 'name'],
                'dashboardids' => [$dashboardId],
                'selectPages'  => 'extend',
            ]);

            return \is_array($rows) && \is_array($rows[0] ?? null) ? DashboardLayout::fromApi($rows[0]) : [];
        }, self::TTL);

        return isset($layout['pages']) ? $layout : null;
    }
}
