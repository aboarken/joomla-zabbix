<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\CacheInterface;

/**
 * Zabbix users from user.get, kept in the shared cache for ten minutes. The token's user must be
 * a Super admin to see other users (Find Endpoints checks this).
 */
final class ZabbixUserDirectory implements UserDirectoryInterface
{
    private const TTL = 600;

    /** @var array<string, DirectoryUser>|null */
    private ?array $users = null;

    public function __construct(
        private readonly JsonRpcClient $client,
        private readonly CacheInterface $cache
    ) {
    }

    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $userid): ?DirectoryUser
    {
        return $this->load()[$userid] ?? null;
    }

    /** Forget the cached list (after Auto-match all). */
    public function refresh(): void
    {
        $this->users = null;
        $this->cache->set(0, 'directory', [], 1);
    }

    /** @return array<string, DirectoryUser> */
    private function load(): array
    {
        if ($this->users !== null) {
            return $this->users;
        }

        $rows = $this->cache->get(0, 'directory');

        if ($rows === null || $rows === []) {
            $result = $this->client->call('user.get', [
                'output'        => ['userid', 'username', 'name', 'surname'],
                'selectMedias'  => ['mediatypeid', 'sendto'],
                'selectUsrgrps' => ['usrgrpid', 'name', 'users_status'],
                'selectRole'    => ['roleid', 'name', 'type'],
                'sortfield'     => 'username',
            ]);

            $rows = [];

            foreach (\is_array($result) ? $result : [] as $row) {
                if (\is_array($row) && isset($row['userid'], $row['username'])) {
                    $rows[] = DirectoryUser::fromApi($row)->toArray();
                }
            }

            $this->cache->set(0, 'directory', $rows, self::TTL);
        }

        $this->users = [];

        foreach ($rows as $row) {
            $user                       = DirectoryUser::fromArray((array) $row);
            $this->users[$user->userid] = $user;
        }

        return $this->users;
    }
}
