<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Access;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\CacheInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;

/**
 * Reads a Zabbix user's user groups (usergroup.get with selectHostGroupRights) and turns them
 * into an AccessScope. Kept in the shared cache for the cache time of Integration Settings, so a
 * change of rights in Zabbix reaches the portal within that time (or at once with Clear Cache).
 */
final class AccessService
{
    public function __construct(
        private readonly JsonRpcClient $client,
        private readonly CacheInterface $cache,
        private readonly AccessResolver $resolver = new AccessResolver()
    ) {
    }

    public function scopeFor(DirectoryUser $user): AccessScope
    {
        if ($user->roleType === AccessScope::ROLE_SUPER_ADMIN) {
            return $this->resolver->resolve($user, []);
        }

        $data = $this->cache->remember(0, 'scope2:' . $user->userid . ':' . implode(',', $user->groupIds), function () use ($user): array {
            $groups = $user->groupIds === [] ? [] : $this->client->call('usergroup.get', [
                'output'                => ['usrgrpid', 'name', 'users_status'],
                'usrgrpids'             => $user->groupIds,
                'selectHostGroupRights' => ['id', 'permission'],
                // Tag-based permissions on problems (Zabbix: User groups → Problem tag filter).
                'selectTagFilters'      => ['groupid', 'tag', 'value'],
            ]);

            return $this->resolver->resolve($user, \is_array($groups) ? array_values($groups) : [])->toArray();
        });

        return AccessScope::fromArray($data);
    }

    /**
     * Names of all host groups the token sees.
     *
     * @return array<string, string> groupid => name
     */
    public function hostGroupNames(): array
    {
        $rows = $this->cache->remember(0, 'hostgroups', function (): array {
            $result = $this->client->call('hostgroup.get', ['output' => ['groupid', 'name'], 'sortfield' => 'name']);
            $names  = [];

            foreach (\is_array($result) ? $result : [] as $row) {
                if (\is_array($row) && isset($row['groupid'])) {
                    $names[(string) $row['groupid']] = (string) ($row['name'] ?? '');
                }
            }

            return $names;
        });

        return array_map('strval', $rows);
    }

    /**
     * Host groups this scope may read, with names, sorted by name.
     *
     * @return array<string, string>
     */
    public function readableGroups(AccessScope $scope): array
    {
        $names = $this->hostGroupNames();

        if (!$scope->all()) {
            $names = array_intersect_key($names, array_flip($scope->readGroupIds));
        }

        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }
}
