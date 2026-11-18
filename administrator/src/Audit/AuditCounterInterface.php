<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Audit;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

interface AuditCounterInterface
{
    /**
     * @param  list<string>  $results
     */
    public function countSince(int $userId, string $action, \DateTimeImmutable $since, array $results): int;
}
