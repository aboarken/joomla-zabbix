<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Audit;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Psr\Log\LoggerInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;

final class DbAuditLog implements AuditLogInterface, AuditCounterInterface
{
    private const TABLE = '#__zabbix_audit';

    /** Entries older than this are deleted (about one write in PURGE_EVERY does it). */
    public const KEEP_DAYS = 365;

    private const PURGE_EVERY = 50;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LoggerInterface $logger
    ) {
    }

    public function record(int $userId, string $action, string $result, string $detail = '', int $actorId = 0): void
    {
        $row = (object) [
            'user_id'  => max(0, $userId),
            'actor_id' => max(0, $actorId),
            'action'   => substr(preg_replace('/[^a-z0-9_.]/', '', $action) ?? '', 0, 50),
            'result'   => substr($result, 0, 20),
            // One line, no markup, anything that looks like a password removed.
            'detail'   => mb_substr(ZabbixException::clean(strip_tags($detail)), 0, 250),
            'created'  => gmdate('Y-m-d H:i:s'),
        ];

        try {
            $this->db->insertObject(self::TABLE, $row);
        } catch (\Throwable) {
            $this->logger->error(\sprintf('Audit write failed for action %s, user %d', $row->action, $row->user_id));

            return;
        }

        try {
            if (random_int(1, self::PURGE_EVERY) === 1) {
                $before = gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400);
                $query  = $this->db->createQuery()
                    ->delete($this->db->quoteName(self::TABLE))
                    ->where($this->db->quoteName('created') . ' < :before')
                    ->bind(':before', $before);
                $this->db->setQuery($query)->execute();
            }
        } catch (\Throwable) {
            $this->logger->warning('Old audit entries could not be deleted');
        }
    }

    public function countSince(int $userId, string $action, \DateTimeImmutable $since, array $results): int
    {
        if ($results === []) {
            return 0;
        }

        $sinceUtc = $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('user_id') . ' = :uid')
            ->where($this->db->quoteName('action') . ' = :action')
            ->where($this->db->quoteName('created') . ' >= :since')
            ->whereIn($this->db->quoteName('result'), $results, ParameterType::STRING)
            ->bind(':uid', $userId, ParameterType::INTEGER)
            ->bind(':action', $action)
            ->bind(':since', $sinceUtc);

        return (int) $this->db->setQuery($query)->loadResult();
    }
}
