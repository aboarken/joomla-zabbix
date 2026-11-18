<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Cache;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Cache of Zabbix answers. Entries are kept per Joomla user ($userId); 0 is for entries that are
 * the same for everyone (what the API token itself sees, e.g. the Zabbix user list).
 */
interface CacheInterface
{
    /** @return array<mixed>|null */
    public function get(int $userId, string $key): ?array;

    /** @param array<mixed> $value */
    public function set(int $userId, string $key, array $value, ?int $ttl = null): void;

    /**
     * Return the cached value, or compute, store and return it.
     *
     * @param  \Closure(): array<mixed>  $compute
     * @return array<mixed>
     */
    public function remember(int $userId, string $key, \Closure $compute, ?int $ttl = null): array;

    /** Delete everything (Integration Settings → Clear Cache), or one user's entries. */
    public function clear(?int $userId = null): void;
}
