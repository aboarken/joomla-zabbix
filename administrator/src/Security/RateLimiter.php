<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Security;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditCounterInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;

/**
 * Sliding one-hour limit per user and action, counted from the audit table.
 *
 * attempt() writes an "attempt" row before the caller does the work (a call to Zabbix can take
 * up to two minutes), and counts those rows: requests sent at the same time cannot all pass
 * while the first is still waiting for Zabbix. A refused request is recorded as "denied" and
 * does not count.
 */
final class RateLimiter
{
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    public function __construct(
        private readonly AuditCounterInterface $counter,
        private readonly AuditLogInterface $audit,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * May this user do the action now? Records the attempt when allowed.
     */
    public function attempt(int $userId, string $action, int $perHour): bool
    {
        $since = ($this->clock)()->modify('-1 hour');
        $used  = $this->counter->countSince($userId, $action, $since, [AuditLogInterface::RESULT_ATTEMPT]);

        if ($used >= max(1, $perHour)) {
            $this->audit->record($userId, $action, AuditLogInterface::RESULT_DENIED, 'rate limit', $userId);

            return false;
        }

        $this->audit->record($userId, $action, AuditLogInterface::RESULT_ATTEMPT, '', $userId);

        return true;
    }
}
