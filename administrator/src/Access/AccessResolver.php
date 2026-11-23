<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Access;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;

/**
 * Works out a Zabbix user's AccessScope from their user groups, as Zabbix itself does. Pure.
 *
 * Host group permissions (usergroup.get, selectHostGroupRights): 0 = deny, 2 = read,
 * 3 = read-write. A group denied through any user group is denied; otherwise the highest
 * permission counts. A user in a disabled user group (users_status = 1) is disabled.
 * Tag-based permissions are not applied (see README, Limitations).
 */
final class AccessResolver
{
    public const PERM_DENY       = 0;
    public const PERM_READ       = 2;
    public const PERM_READ_WRITE = 3;

    /**
     * @param list<array<string, mixed>> $userGroups  usergroup.get rows for the user's groups
     */
    public function resolve(DirectoryUser $user, array $userGroups): AccessScope
    {
        $disabled = $user->disabled;
        $best     = [];
        $denied   = [];
        $tags     = [];

        foreach ($userGroups as $group) {
            if (!\is_array($group) || !\in_array((string) ($group['usrgrpid'] ?? ''), $user->groupIds, true)) {
                continue;
            }

            if ((string) ($group['users_status'] ?? '0') === '1') {
                $disabled = true;
            }

            foreach ((array) ($group['tag_filters'] ?? []) as $filter) {
                if (\is_array($filter) && isset($filter['groupid'])) {
                    $tags[(string) $filter['groupid']][] = ['tag' => (string) ($filter['tag'] ?? ''), 'value' => (string) ($filter['value'] ?? '')];
                }
            }

            foreach ((array) ($group['hostgroup_rights'] ?? []) as $right) {
                if (!\is_array($right) || !isset($right['id'])) {
                    continue;
                }

                $id         = (string) $right['id'];
                $permission = (int) ($right['permission'] ?? self::PERM_DENY);

                if ($permission === self::PERM_DENY) {
                    $denied[$id] = true;
                } elseif ($permission >= self::PERM_READ) {
                    $best[$id] = max($best[$id] ?? 0, $permission);
                }
            }
        }

        $read = array_keys(array_diff_key($best, $denied));
        sort($read, SORT_NATURAL);
        $deniedIds = array_keys($denied);
        sort($deniedIds, SORT_NATURAL);

        return new AccessScope(
            $user->userid,
            $user->username,
            $user->roleType,
            array_map('strval', $read),
            array_map('strval', $deniedIds),
            $user->groupIds,
            $disabled,
            $tags
        );
    }
}
