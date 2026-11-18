<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Cache;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Cache in the component's own table #__zabbix_cache. Keys always start with the Joomla user id,
 * so a user never reads another user's entry. Every entry has an expiry time; expired entries are
 * deleted now and then. Values read on this page load are also kept in memory.
 *
 * A database error never breaks a page: the value is simply read from Zabbix again.
 */
final class DbCache implements CacheInterface
{
    private const TABLE = '#__zabbix_cache';

    /** About one write in this many also deletes expired entries. */
    private const PURGE_EVERY = 20;

    /** Larger entries are not stored. */
    private const MAX_BYTES = 4_000_000;

    private MemoryCache $memory;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param int $ttl seconds; 0 keeps values for this page load only
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = 120,
        ?\Closure $clock = null
    ) {
        $this->memory = new MemoryCache();
        $this->clock  = $clock ?? static fn (): int => time();
    }

    public function get(int $userId, string $key): ?array
    {
        $value = $this->memory->get($userId, $key);

        if ($value !== null || $this->ttl <= 0) {
            return $value;
        }

        $id  = $this->key($userId, $key);
        $now = ($this->clock)();

        try {
            $query = $this->db->createQuery()
                ->select($this->db->quoteName('value'))
                ->from($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('cache_key') . ' = :key')
                ->where($this->db->quoteName('expires') . ' > :now')
                ->bind(':key', $id)
                ->bind(':now', $now, ParameterType::INTEGER);

            $raw = $this->db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            $this->logger->warning('Zabbix cache could not be read: ' . $e::class);

            return null;
        }

        $value = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!\is_array($value)) {
            return null;
        }

        $this->memory->set($userId, $key, $value);

        return $value;
    }

    public function set(int $userId, string $key, array $value, ?int $ttl = null): void
    {
        $this->memory->set($userId, $key, $value);

        if ($this->ttl <= 0) {
            return;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || \strlen($json) > self::MAX_BYTES) {
            return;
        }

        $id  = $this->key($userId, $key);
        $now = ($this->clock)();

        try {
            $this->delete($this->db->quoteName('cache_key') . ' = :key', [':key' => [$id, ParameterType::STRING]]);
            // insertObject() takes the row by reference: it must be a variable.
            $row = (object) [
                'cache_key' => $id,
                'user_id'   => max(0, $userId),
                'expires'   => $now + max(1, $ttl ?? $this->ttl),
                'value'     => $json,
            ];
            $this->db->insertObject(self::TABLE, $row);

            if (random_int(1, self::PURGE_EVERY) === 1) {
                $this->delete($this->db->quoteName('expires') . ' <= :now', [':now' => [$now, ParameterType::INTEGER]]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Zabbix cache could not be written: ' . $e::class);
        }
    }

    public function remember(int $userId, string $key, \Closure $compute, ?int $ttl = null): array
    {
        $value = $this->get($userId, $key);

        if ($value === null) {
            $value = $compute();
            $this->set($userId, $key, $value, $ttl);
        }

        return $value;
    }

    public function clear(?int $userId = null): void
    {
        $this->memory->clear($userId);

        try {
            if ($userId === null) {
                $this->db->setQuery($this->db->createQuery()->delete($this->db->quoteName(self::TABLE)))->execute();
            } else {
                $this->delete($this->db->quoteName('user_id') . ' = :uid', [':uid' => [$userId, ParameterType::INTEGER]]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Zabbix cache could not be cleared: ' . $e::class);
        }
    }

    /**
     * @param array<string, array{0: int|string, 1: string}> $binds
     */
    private function delete(string $where, array $binds): void
    {
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($where);

        foreach ($binds as $name => [$value, $type]) {
            $query->bind($name, $value, $type);
        }

        $this->db->setQuery($query)->execute();
    }

    private function key(int $userId, string $key): string
    {
        $full = $userId . '|' . $key;

        // At most 191 characters (index length): long keys are hashed.
        return \strlen($full) <= 191 ? $full : $userId . '|h:' . hash('sha256', $key);
    }
}
