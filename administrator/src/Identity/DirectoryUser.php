<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One Zabbix user, as user.get returns it (with selectMedias, selectUsrgrps and selectRole).
 */
final class DirectoryUser
{
    /**
     * @param list<string> $emails    e-mail addresses of the user's media
     * @param list<string> $groupIds  user group ids
     */
    public function __construct(
        public readonly string $userid,
        public readonly string $username,
        public readonly string $name = '',
        public readonly string $surname = '',
        public readonly array $emails = [],
        public readonly int $roleType = 1,
        public readonly string $roleName = '',
        public readonly array $groupIds = [],
        public readonly bool $disabled = false
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromApi(array $row): self
    {
        $emails = [];

        foreach ((array) ($row['medias'] ?? []) as $media) {
            // An e-mail media's "Send to" is a list of addresses; a text field for other media.
            foreach ((array) (\is_array($media) ? ($media['sendto'] ?? []) : []) as $to) {
                $address = \is_string($to) ? UserMatcher::normaliseEmail($to) : '';

                if ($address !== '') {
                    $emails[] = $address;
                }
            }
        }

        $groups   = [];
        $disabled = false;

        foreach ((array) ($row['usrgrps'] ?? []) as $group) {
            if (\is_array($group) && isset($group['usrgrpid'])) {
                $groups[] = (string) $group['usrgrpid'];
                $disabled = $disabled || (string) ($group['users_status'] ?? '0') === '1';
            }
        }

        $role = \is_array($row['role'] ?? null) ? $row['role'] : [];

        return new self(
            (string) ($row['userid'] ?? ''),
            (string) ($row['username'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['surname'] ?? ''),
            array_values(array_unique($emails)),
            (int) ($role['type'] ?? 1),
            (string) ($role['name'] ?? ''),
            $groups,
            $disabled
        );
    }

    public function fullName(): string
    {
        return trim($this->name . ' ' . $this->surname);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'userid'   => $this->userid,
            'username' => $this->username,
            'name'     => $this->name,
            'surname'  => $this->surname,
            'emails'   => $this->emails,
            'role'     => $this->roleType,
            'roleName' => $this->roleName,
            'groups'   => $this->groupIds,
            'disabled' => $this->disabled,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['userid'] ?? ''),
            (string) ($data['username'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['surname'] ?? ''),
            array_values(array_map('strval', (array) ($data['emails'] ?? []))),
            (int) ($data['role'] ?? 1),
            (string) ($data['roleName'] ?? ''),
            array_values(array_map('strval', (array) ($data['groups'] ?? []))),
            (bool) ($data['disabled'] ?? false)
        );
    }
}
