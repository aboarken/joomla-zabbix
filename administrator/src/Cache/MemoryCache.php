<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Cache;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Cache for one page load only (used when caching is switched off, and by the tests).
 */
final class MemoryCache implements CacheInterface
{
    /** @var array<string, array<mixed>> */
    private array $values = [];

    public function get(int $userId, string $key): ?array
    {
        return $this->values[$userId . '|' . $key] ?? null;
    }

    public function set(int $userId, string $key, array $value, ?int $ttl = null): void
    {
        $this->values[$userId . '|' . $key] = $value;
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
        if ($userId === null) {
            $this->values = [];

            return;
        }

        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $userId . '|')) {
                unset($this->values[$key]);
            }
        }
    }
}
