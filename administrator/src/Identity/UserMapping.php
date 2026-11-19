<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One row of #__zabbix_usermap: one Joomla user mapped to one Zabbix user.
 */
final class UserMapping
{
    public const STATUS_ACTIVE    = 'active';
    /** Found automatically, waiting for an administrator to confirm it (User Mappings → Confirm). */
    public const STATUS_PENDING   = 'pending';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_AMBIGUOUS, self::STATUS_NOT_FOUND, self::STATUS_SUSPENDED];

    public const SOURCE_AUTO  = 'auto';
    public const SOURCE_ADMIN = 'admin';

    public const SOURCES = [self::SOURCE_AUTO, self::SOURCE_ADMIN];

    public function __construct(
        public readonly int $joomlaUserId,
        public readonly ?string $zabbixUserId,
        public readonly ?string $zabbixUsername,
        public readonly string $email,
        public readonly string $status,
        public readonly string $source,
        public readonly ?\DateTimeImmutable $matchedAt,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly int $modifiedBy = 0
    ) {
        if ($joomlaUserId <= 0) {
            throw new \InvalidArgumentException('Invalid Joomla user id');
        }

        if (!\in_array($status, self::STATUSES, true) || !\in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Invalid mapping status or source');
        }

        if ($zabbixUserId !== null && !preg_match('/^[1-9][0-9]{0,19}$/', $zabbixUserId)) {
            throw new \InvalidArgumentException('Invalid Zabbix user id');
        }

        if ($zabbixUsername !== null && ($zabbixUsername === '' || mb_strlen($zabbixUsername) > 100)) {
            throw new \InvalidArgumentException('Invalid Zabbix username');
        }

        if (\in_array($status, [self::STATUS_ACTIVE, self::STATUS_PENDING], true) && ($zabbixUserId === null || $zabbixUsername === null)) {
            throw new \InvalidArgumentException('An active or pending mapping needs a Zabbix user');
        }
    }

    /**
     * Copy with changes. Keys: zabbixUserId, zabbixUsername, email, status, source, matchedAt, checkedAt, modifiedBy.
     *
     * @param array<string, mixed> $changes
     */
    public function with(array $changes): self
    {
        $get = fn (string $k, mixed $current): mixed => \array_key_exists($k, $changes) ? $changes[$k] : $current;

        return new self(
            $this->joomlaUserId,
            $get('zabbixUserId', $this->zabbixUserId),
            $get('zabbixUsername', $this->zabbixUsername),
            $get('email', $this->email),
            $get('status', $this->status),
            $get('source', $this->source),
            $get('matchedAt', $this->matchedAt),
            $get('checkedAt', $this->checkedAt),
            $get('modifiedBy', $this->modifiedBy)
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
