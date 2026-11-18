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
 * Checks hosts and items named by a dashboard widget or a URL against the portal user's scope
 * before any of their data is read.
 */
final class AccessGuard
{
    /** @var array<string, bool> */
    private array $hostVisible = [];

    public function __construct(private readonly JsonRpcClient $client)
    {
    }

    /**
     * @param  list<string|int>  $hostIds
     * @return list<string>  the visible ones
     */
    public function visibleHostIds(AccessScope $scope, array $hostIds): array
    {
        $hostIds = array_values(array_unique(array_filter(array_map('strval', $hostIds), static fn (string $id): bool => ctype_digit($id))));
        $unknown = array_values(array_filter($hostIds, fn (string $id): bool => !isset($this->hostVisible[$scope->userid . ':' . $id])));

        if ($unknown !== []) {
            $groups = $scope->groupFilter();
            $params = ['output' => ['hostid'], 'hostids' => $unknown, 'selectHostGroups' => ['groupid']];

            if ($groups === []) {
                $rows = [];
            } else {
                if ($groups !== null) {
                    $params['groupids'] = $groups;
                }

                $rows = $this->client->call('host.get', $params);
            }

            foreach ($unknown as $id) {
                $this->hostVisible[$scope->userid . ':' . $id] = false;
            }

            foreach (\is_array($rows) ? $rows : [] as $row) {
                $ids = array_map(static fn ($g): string => (string) ($g['groupid'] ?? ''), (array) ($row['hostgroups'] ?? []));
                $this->hostVisible[$scope->userid . ':' . (string) $row['hostid']] = $scope->allowsHostGroups($ids);
            }
        }

        return array_values(array_filter($hostIds, fn (string $id): bool => $this->hostVisible[$scope->userid . ':' . $id] ?? false));
    }

    /**
     * Items (with their host) the scope may read.
     *
     * @param  list<string|int>  $itemIds
     * @return list<array<string, mixed>>
     */
    public function visibleItems(AccessScope $scope, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('strval', $itemIds), static fn (string $id): bool => ctype_digit($id))));
        $groups  = $scope->groupFilter();

        if ($itemIds === [] || $groups === []) {
            return [];
        }

        $params = [
            'output'         => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units', 'lastvalue', 'lastclock', 'prevvalue', 'state', 'status'],
            'itemids'        => $itemIds,
            'selectHosts'    => ['hostid', 'name'],
            'selectValueMap' => ['mappings'],
            'webitems'       => true,
        ];

        if ($groups !== null) {
            $params['groupids'] = $groups;
        }

        $items   = \is_array($rows = $this->client->call('item.get', $params)) ? $rows : [];
        $visible = array_flip($this->visibleHostIds($scope, array_map(static fn ($i): string => (string) ($i['hostid'] ?? ''), $items)));

        return array_values(array_filter($items, static fn ($i): bool => isset($visible[(string) ($i['hostid'] ?? '')])));
    }
}
