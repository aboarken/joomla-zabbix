<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Integration;

use Joomla\Database\DatabaseFactory;
use Joomla\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\DbAuditLog;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\DbCache;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DbUserMapRepository;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapping;
use ZabbixPortal\Component\Zabbix\Administrator\Security\RateLimiter;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\SettingsRepository;
use ZabbixPortal\Component\Zabbix\Tests\Support\ArrayLogger;

/**
 * The component's database classes against a real database, with the tables made by the
 * component's own install script.
 *
 * - Default: SQLite in memory (the PostgreSQL install script, with its two PostgreSQL-only
 *   column types translated).
 * - PostgreSQL or MySQL/MariaDB: set ZABBIX_TEST_PGSQL or ZABBIX_TEST_MYSQL, e.g.
 *   "host=127.0.0.1;port=5432;user=postgres;password=;database=zbxtest". The install and
 *   uninstall scripts of that database are then run exactly as shipped.
 */
final class DatabaseTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private DatabaseInterface $db;

    /** 'postgresql' or 'mysql' when a real server is used, '' for SQLite. */
    private string $server = '';

    protected function setUp(): void
    {
        $server = (string) getenv('ZABBIX_TEST_PGSQL') !== '' ? 'postgresql' : ((string) getenv('ZABBIX_TEST_MYSQL') !== '' ? 'mysql' : '');
        $dsn    = (string) getenv($server === 'mysql' ? 'ZABBIX_TEST_MYSQL' : 'ZABBIX_TEST_PGSQL');

        if ($server !== '') {
            $options = ['prefix' => 'zt' . bin2hex(random_bytes(3)) . '_'];

            foreach (explode(';', $dsn) as $pair) {
                [$name, $value] = explode('=', $pair, 2) + [1 => ''];
                $options[trim($name)] = $value;
            }

            $this->server = $server;
            $this->db     = (new DatabaseFactory())->getDriver($server === 'mysql' ? 'mysqli' : 'pgsql', $options);
            $sql          = (string) file_get_contents(self::ROOT . '/administrator/sql/install.' . $server . '.utf8.sql');
        } else {
            if (!\extension_loaded('pdo_sqlite')) {
                self::markTestSkipped('pdo_sqlite is not available');
            }

            $this->db = (new DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'zt_']);
            $sql      = (string) file_get_contents(self::ROOT . '/administrator/sql/install.postgresql.utf8.sql');
            // SQLite: an auto-increment key is declared on the column.
            $sql = str_replace('"id" serial NOT NULL,', '"id" INTEGER PRIMARY KEY AUTOINCREMENT,', $sql);
            $sql = preg_replace('/,\s*PRIMARY KEY \("id"\)/', '', $sql);
            // Joomla's SQLite driver leaves "#__" alone inside double quotes (its PostgreSQL
            // driver, used by the installer for this file, replaces it).
            $sql = str_replace('#__', 'zt_', (string) $sql);
        }

        $this->runSql((string) $sql);
        // Installing over an existing installation (method="upgrade") must not fail or lose data.
        $this->runSql((string) $sql);
    }

    protected function tearDown(): void
    {
        if ($this->server !== '') {
            $this->runSql((string) file_get_contents(self::ROOT . '/administrator/sql/uninstall.' . $this->server . '.utf8.sql'));

            // The uninstall script removes every table of the component.
            $left = array_filter($this->db->getTableList(), fn (string $t): bool => str_starts_with($t, $this->db->getPrefix() . 'zabbix_'));
            self::assertSame([], array_values($left));
        }

        $this->db->disconnect();
    }

    private function runSql(string $sql): void
    {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $this->db->setQuery($statement)->execute();
        }
    }

    public function testSettingsAreStoredAndReplaced(): void
    {
        $repo = new SettingsRepository($this->db);

        self::assertSame('', $repo->load()->get('zabbix_url'), 'defaults before anything is saved');

        $repo->save(['zabbix_url' => 'https://zabbix.example.org', 'cache_minutes' => '7', 'not_a_setting' => 'x']);
        $repo->save(['cache_minutes' => '9']);

        $settings = (new SettingsRepository($this->db))->load();

        self::assertSame('https://zabbix.example.org', $settings->get('zabbix_url'));
        self::assertSame(540, $settings->cacheTtl(), 'the second save replaced the value');
        self::assertSame(2, (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__zabbix_settings'))->loadResult(), 'unknown names are not stored');
    }

    public function testUserMappingsRoundTrip(): void
    {
        $repo = new DbUserMapRepository($this->db);
        $now  = new \DateTimeImmutable('2026-11-24 09:30:00', new \DateTimeZone('UTC'));

        self::assertNull($repo->find(10));

        $repo->save(new UserMapping(10, '2', 'alice', 'alice@example.org', UserMapping::STATUS_ACTIVE, UserMapping::SOURCE_AUTO, $now, $now));
        $repo->save(new UserMapping(11, '2', 'alice', 'x@example.org', UserMapping::STATUS_PENDING, UserMapping::SOURCE_AUTO, null, $now));
        $repo->save(new UserMapping(12, '2', 'alice', 'y@example.org', UserMapping::STATUS_SUSPENDED, UserMapping::SOURCE_ADMIN, $now, $now, 99));
        $repo->save(new UserMapping(13, null, null, 'z@example.org', UserMapping::STATUS_NOT_FOUND, UserMapping::SOURCE_AUTO, null, $now));

        $found = $repo->find(10);

        self::assertNotNull($found);
        self::assertSame(['2', 'alice', 'alice@example.org', UserMapping::STATUS_ACTIVE], [$found->zabbixUserId, $found->zabbixUsername, $found->email, $found->status]);
        self::assertSame('2026-11-24 09:30:00', $found->matchedAt?->format('Y-m-d H:i:s'));
        self::assertNull($repo->find(13)?->zabbixUserId);
        self::assertSame(99, $repo->find(12)?->modifiedBy);

        $linked = $repo->linkedTo('2');
        sort($linked);
        self::assertSame([10, 11, 12], $linked, 'every mapping that names the Zabbix user, also a suspended one');
        self::assertSame([], $repo->linkedTo('404'));

        // Saving again updates the row.
        $repo->save($found->with(['status' => UserMapping::STATUS_SUSPENDED]));
        self::assertSame(UserMapping::STATUS_SUSPENDED, $repo->find(10)?->status);
        $repo->delete(12);
        $linked = $repo->linkedTo('2');
        sort($linked);
        self::assertSame([10, 11], $linked);

        $repo->delete(10);
        self::assertNull($repo->find(10));
    }

    public function testCacheKeepsUsersApartAndExpires(): void
    {
        $now   = 1_800_000_000;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $cache = fn (): DbCache => new DbCache($this->db, new ArrayLogger(), 120, $clock);

        $cache()->set(5, 'scope', ['who' => 'five']);
        $cache()->set(6, 'scope', ['who' => 'six']);
        $cache()->set(0, 'shared', ['n' => 1]);
        $long = str_repeat('k', 400);
        $cache()->set(5, $long, ['long' => true]);

        // A new instance has no memory copy: these come from the table.
        self::assertSame(['who' => 'five'], $cache()->get(5, 'scope'));
        self::assertSame(['who' => 'six'], $cache()->get(6, 'scope'), 'one user never gets another user\'s entry');
        self::assertNull($cache()->get(7, 'scope'));
        self::assertSame(['long' => true], $cache()->get(5, $long), 'long keys are hashed to fit the index');

        $cache()->set(5, 'scope', ['who' => 'five again']);
        self::assertSame(['who' => 'five again'], $cache()->get(5, 'scope'), 'set replaces');

        $cache()->clear(5);
        self::assertNull($cache()->get(5, 'scope'));
        self::assertSame(['who' => 'six'], $cache()->get(6, 'scope'), 'clearing one user leaves the others');

        $now += 121;
        self::assertNull($cache()->get(6, 'scope'), 'expired');
        self::assertNull($cache()->get(0, 'shared'));

        $calls = 0;
        $value = $cache()->remember(0, 'again', static function () use (&$calls): array {
            $calls++;

            return ['v' => 1];
        });
        $cache()->remember(0, 'again', static function () use (&$calls): array {
            $calls++;

            return ['v' => 2];
        });
        self::assertSame([['v' => 1], 1], [$value, $calls]);

        $cache()->clear();
        self::assertNull($cache()->get(0, 'again'));
    }

    public function testAuditLogAndRateLimit(): void
    {
        $audit = new DbAuditLog($this->db, new ArrayLogger());
        $token = str_repeat('ab12', 16);

        $audit->record(7, 'settings.save', AuditLogInterface::RESULT_OK, "<b>destination</b>: zabbix_url\nBearer " . $token, 7);

        $row = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName('#__zabbix_audit'))->loadObject();

        self::assertSame('settings.save', $row->action);
        self::assertStringNotContainsString($token, $row->detail, 'tokens never reach the audit log');
        self::assertStringNotContainsString('<b>', $row->detail);
        self::assertStringNotContainsString("\n", $row->detail);

        // Three tests an hour: the fourth is refused, another user and another action are not.
        $limiter = new RateLimiter($audit, $audit);

        self::assertTrue($limiter->attempt(7, 'settings.test', 3));
        self::assertTrue($limiter->attempt(7, 'settings.test', 3));
        self::assertTrue($limiter->attempt(7, 'settings.test', 3));
        self::assertFalse($limiter->attempt(7, 'settings.test', 3), 'counted before the work is done, so parallel requests cannot all pass');
        self::assertFalse($limiter->attempt(7, 'settings.test', 3), 'refused attempts do not count, but stay refused');
        self::assertTrue($limiter->attempt(8, 'settings.test', 3));
        self::assertTrue($limiter->attempt(7, 'endpoints.run', 3));

        $since = new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'));
        self::assertSame(3, $audit->countSince(7, 'settings.test', $since, [AuditLogInterface::RESULT_ATTEMPT]));
        self::assertSame(2, $audit->countSince(7, 'settings.test', $since, [AuditLogInterface::RESULT_DENIED]), 'refusals are on record');

        // An hour later the limit is free again.
        $later = new RateLimiter($audit, $audit, static fn (): \DateTimeImmutable => new \DateTimeImmutable('+61 minutes', new \DateTimeZone('UTC')));
        self::assertTrue($later->attempt(7, 'settings.test', 3));
    }
}
