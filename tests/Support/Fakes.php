<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Support;

use Psr\Log\AbstractLogger;
use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Api\TransportInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Api\TransportOptions;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserDirectoryInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapping;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapRepositoryInterface;

/**
 * A Zabbix API in memory: method => result, or method => callable(params): result.
 * A result that is an array with key "__error" becomes a JSON-RPC error object.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{method: string, params: mixed, headers: array<string, string>}> */
    public array $calls = [];

    /** @param array<string, mixed> $routes */
    public function __construct(public array $routes = [], public int $status = 200, public ?string $raw = null)
    {
    }

    public function post(string $url, array $headers, string $body, TransportOptions $options): array
    {
        $request       = json_decode($body, true);
        $this->calls[] = ['method' => $request['method'], 'params' => $request['params'], 'headers' => $headers];

        if ($this->raw !== null) {
            return ['status' => $this->status, 'body' => $this->raw];
        }

        $route = $this->routes[$request['method']] ?? [];
        $result = \is_callable($route) ? $route($request['params']) : $route;

        if (\is_array($result) && isset($result['__error'])) {
            return ['status' => $this->status, 'body' => json_encode(['jsonrpc' => '2.0', 'error' => $result['__error'], 'id' => $request['id']])];
        }

        return ['status' => $this->status, 'body' => json_encode(['jsonrpc' => '2.0', 'result' => $result, 'id' => $request['id']])];
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_column($this->calls, 'method');
    }

    /** @return mixed params of the last call of a method */
    public function lastParams(string $method): mixed
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method) {
                return $call['params'];
            }
        }

        return null;
    }

    public function client(string $token = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6abcd'): JsonRpcClient
    {
        return new JsonRpcClient('https://zabbix.test/api_jsonrpc.php', $token, $this);
    }
}

final class MemoryUserMapRepository implements UserMapRepositoryInterface
{
    /** @var array<int, UserMapping> */
    public array $rows = [];

    public function find(int $joomlaUserId): ?UserMapping
    {
        return $this->rows[$joomlaUserId] ?? null;
    }

    public function save(UserMapping $mapping): void
    {
        $this->rows[$mapping->joomlaUserId] = $mapping;
    }

    public function delete(int $joomlaUserId): void
    {
        unset($this->rows[$joomlaUserId]);
    }
}

final class MemoryAudit implements AuditLogInterface
{
    /** @var list<array{user: int, action: string, result: string, detail: string, actor: int}> */
    public array $records = [];

    public function record(int $userId, string $action, string $result, string $detail = '', int $actorId = 0): void
    {
        $this->records[] = ['user' => $userId, 'action' => $action, 'result' => $result, 'detail' => $detail, 'actor' => $actorId];
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_column($this->records, 'action');
    }
}

final class ArrayDirectory implements UserDirectoryInterface
{
    public int $reads = 0;

    /** @param list<DirectoryUser> $users */
    public function __construct(public array $users = [])
    {
    }

    public function all(): array
    {
        $this->reads++;

        return $this->users;
    }

    public function find(string $userid): ?DirectoryUser
    {
        foreach ($this->users as $user) {
            if ($user->userid === $userid) {
                return $user;
            }
        }

        return null;
    }
}

final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = [(string) $level, (string) $message];
    }
}
