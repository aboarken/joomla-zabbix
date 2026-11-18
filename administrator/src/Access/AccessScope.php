<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Access;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What one Zabbix user may see, worked out from their user groups (AccessResolver).
 *
 * The API token always sees what its own user sees, and the Zabbix API cannot act as another
 * user, so the portal applies these rights itself to every call made for a portal user:
 *  - Super admin role: everything the token sees.
 *  - Otherwise: hosts in host groups with Read or Read-write permission through any of the user's
 *    groups, minus hosts in any group with Deny (Deny wins, as in Zabbix).
 *  - Dashboards: public ones, the user's own, and those shared with the user or their groups.
 */
final class AccessScope
{
    public const ROLE_USER        = 1;
    public const ROLE_ADMIN       = 2;
    public const ROLE_SUPER_ADMIN = 3;

    /**
     * @param list<string> $readGroupIds    host groups with read or read-write permission (not denied)
     * @param list<string> $deniedGroupIds  host groups denied through any user group
     * @param list<string> $userGroupIds    the user's Zabbix user groups
     */
    public function __construct(
        public readonly string $userid,
        public readonly string $username,
        public readonly int $roleType,
        public readonly array $readGroupIds = [],
        public readonly array $deniedGroupIds = [],
        public readonly array $userGroupIds = [],
        public readonly bool $disabled = false
    ) {
    }

    /** Sees every host group (super admin). */
    public function all(): bool
    {
        return !$this->disabled && $this->roleType === self::ROLE_SUPER_ADMIN;
    }

    public function canSeeAnything(): bool
    {
        return !$this->disabled && ($this->all() || $this->readGroupIds !== []);
    }

    /**
     * Host group filter for an API call.
     *
     * @param  list<string>  $requested  groups a widget or filter asks for ([] = no filter)
     *
     * @return list<string>|null  null = no filter needed; [] = nothing may be shown
     */
    public function groupFilter(array $requested = []): ?array
    {
        $requested = array_values(array_unique(array_map('strval', $requested)));

        if ($this->disabled) {
            return [];
        }

        if ($this->all()) {
            return $requested === [] ? null : $requested;
        }

        if ($requested === []) {
            return $this->readGroupIds;
        }

        return array_values(array_intersect($requested, $this->readGroupIds));
    }

    /**
     * May a host in these host groups be shown? (Read in at least one, Deny in none.)
     *
     * @param list<string|int> $groupIds
     */
    public function allowsHostGroups(array $groupIds): bool
    {
        if ($this->disabled) {
            return false;
        }

        if ($this->all()) {
            return true;
        }

        $groupIds = array_map('strval', $groupIds);

        return array_intersect($groupIds, $this->deniedGroupIds) === []
            && array_intersect($groupIds, $this->readGroupIds) !== [];
    }

    /**
     * The groups of a visible host that may be named to this user: for anyone but a Super admin,
     * only host groups they can read. (A host can also be in groups the user has no rights on;
     * those names are not shown.)
     *
     * @param  list<array{groupid: string, name: string}>  $groups
     * @return list<array{groupid: string, name: string}>
     */
    public function namedGroups(array $groups): array
    {
        if ($this->disabled) {
            return [];
        }

        if ($this->all()) {
            return array_values($groups);
        }

        return array_values(array_filter($groups, fn (array $g): bool => \in_array((string) $g['groupid'], $this->readGroupIds, true)));
    }

    /**
     * Is a dashboard (from dashboard.get with selectUsers and selectUserGroups) visible?
     *
     * @param array<string, mixed> $dashboard
     */
    public function canSeeDashboard(array $dashboard): bool
    {
        if ($this->disabled) {
            return false;
        }

        if ($this->all()
            || (string) ($dashboard['private'] ?? '1') === '0'
            || (string) ($dashboard['userid'] ?? '') === $this->userid) {
            return true;
        }

        foreach ((array) ($dashboard['users'] ?? []) as $share) {
            if (\is_array($share) && (string) ($share['userid'] ?? '') === $this->userid) {
                return true;
            }
        }

        foreach ((array) ($dashboard['userGroups'] ?? []) as $share) {
            if (\is_array($share) && \in_array((string) ($share['usrgrpid'] ?? ''), $this->userGroupIds, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'userid'   => $this->userid,
            'username' => $this->username,
            'role'     => $this->roleType,
            'read'     => $this->readGroupIds,
            'denied'   => $this->deniedGroupIds,
            'groups'   => $this->userGroupIds,
            'disabled' => $this->disabled,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $list = static fn (mixed $v): array => array_values(array_map('strval', \is_array($v) ? $v : []));

        return new self(
            (string) ($data['userid'] ?? ''),
            (string) ($data['username'] ?? ''),
            (int) ($data['role'] ?? self::ROLE_USER),
            $list($data['read'] ?? []),
            $list($data['denied'] ?? []),
            $list($data['groups'] ?? []),
            (bool) ($data['disabled'] ?? false)
        );
    }
}
