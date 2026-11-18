<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessService;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\MemoryCache;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\WidgetDataService;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HostService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ProblemService;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\Settings;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

/**
 * Findings of the 1.0.2 audit.
 */
final class HardeningTest extends TestCase
{
    public function testOnlyHexColoursReachTheBrowser(): void
    {
        self::assertSame('#FF0000', WidgetDataService::colour('ff0000'));
        self::assertSame('#1A2B3C', WidgetDataService::colour('#1a2b3c'));

        foreach (['', 'red', 'FF00', 'FF0000;background:url(https://evil.example/x)', 'FF0000"onload="x', 'url(x)', 'GG0000'] as $bad) {
            self::assertNull(WidgetDataService::colour($bad), $bad);
        }
    }

    public function testGroupsTheUserCannotReadAreNotNamed(): void
    {
        $groups = [['groupid' => '10', 'name' => 'Servers'], ['groupid' => '13', 'name' => 'Finance (no rights)']];

        self::assertSame([['groupid' => '10', 'name' => 'Servers']], (new AccessScope('42', 'alice', 1, ['10']))->namedGroups($groups));
        self::assertSame($groups, (new AccessScope('1', 'root', AccessScope::ROLE_SUPER_ADMIN))->namedGroups($groups));
        self::assertSame([], (new AccessScope('42', 'alice', 1, ['10'], [], [], true))->namedGroups($groups));
    }

    public function testHostAndProblemRowsNameOnlyReadableGroups(): void
    {
        $fake = new FakeTransport([
            'host.get' => [['hostid' => '1', 'host' => 'web', 'name' => 'Web', 'status' => '0', 'interfaces' => [],
                            'hostgroups' => [['groupid' => '10', 'name' => 'Servers'], ['groupid' => '13', 'name' => 'Finance']]]],
            'problem.get' => [['eventid' => '1', 'objectid' => '100', 'clock' => '100', 'name' => 'P', 'severity' => '3', 'acknowledged' => '0', 'suppressed' => '0']],
            'trigger.get' => ['100' => ['triggerid' => '100', 'hosts' => [['hostid' => '1', 'name' => 'Web']],
                                        'hostgroups' => [['groupid' => '10', 'name' => 'Servers'], ['groupid' => '13', 'name' => 'Finance']]]],
        ]);
        $client   = $fake->client();
        $problems = new ProblemService($client);
        $alice    = new AccessScope('42', 'alice', 1, ['10']);

        self::assertSame(['Servers'], array_column($problems->problems($alice)[0]['groups'], 'name'));
        self::assertSame(['Servers'], array_column((new HostService($client, $problems))->hosts($alice)[0]['groups'], 'name'));
        self::assertStringNotContainsString('Finance', json_encode((new HostService($client, $problems))->host($alice, '1')));
    }

    public function testRightsAreKeptForTheConfiguredCacheTimeOnly(): void
    {
        $cache = new class () extends \stdClass implements \ZabbixPortal\Component\Zabbix\Administrator\Cache\CacheInterface {
            /** @var list<?int> */
            public array $ttls = [];

            public function get(int $userId, string $key): ?array
            {
                return null;
            }

            public function set(int $userId, string $key, array $value, ?int $ttl = null): void
            {
                $this->ttls[] = $ttl;
            }

            public function remember(int $userId, string $key, \Closure $compute, ?int $ttl = null): array
            {
                $this->ttls[] = $ttl;

                return $compute();
            }

            public function clear(?int $userId = null): void
            {
            }
        };

        $fake = new FakeTransport(['usergroup.get' => [], 'hostgroup.get' => []]);
        $service = new AccessService($fake->client(), $cache);
        $service->scopeFor(new DirectoryUser('42', 'alice', groupIds: ['7']));
        $service->hostGroupNames();

        self::assertSame([null, null], $cache->ttls, 'No fixed lifetime: the Cache (minutes) setting decides');
        self::assertSame(300, (new Settings())->cacheTtl());
        self::assertSame(0, (new Settings(['cache_minutes' => '0']))->cacheTtl());
        self::assertInstanceOf(MemoryCache::class, new MemoryCache());
    }

    public function testTlsVerificationSetting(): void
    {
        self::assertTrue((new Settings())->verifiesTls());
        self::assertFalse((new Settings(['verify_tls' => '0']))->verifiesTls());
        self::assertFalse((new Settings(['verify_tls' => '0']))->transportOptions()->verifyTls);
    }
}
