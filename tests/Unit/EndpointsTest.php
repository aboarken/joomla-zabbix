<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Discovery\ApiRules;
use ZabbixPortal\Component\Zabbix\Administrator\Discovery\EndpointProbe;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

final class EndpointsTest extends TestCase
{
    public function testApiRules(): void
    {
        $readOnly = ApiRules::fromRole(['api.access' => '1', 'api.mode' => '1', 'api' => ['*.get']]);
        self::assertTrue($readOnly->allows('host.get'));
        self::assertFalse($readOnly->allows('host.create'));
        self::assertSame([], $readOnly->writeMethodsAllowed());

        $denyList = ApiRules::fromRole(['api.access' => '1', 'api.mode' => '0', 'api' => ['host.*']]);
        self::assertFalse($denyList->allows('host.get'));
        self::assertTrue($denyList->allows('item.get'));
        self::assertContains('user.update', $denyList->writeMethodsAllowed());

        self::assertFalse(ApiRules::fromRole(['api.access' => '0'])->allows('host.get'));
        self::assertTrue(ApiRules::matches('*', 'anything.get'));
    }

    public function testProbeReportsAllowedDeniedAndWarnings(): void
    {
        $fake = new FakeTransport([
            'apiinfo.version'          => '7.4.3',
            'user.checkAuthentication' => ['userid' => '3', 'username' => 'portal', 'type' => 2, 'roleid' => '2'],
            'role.get'                 => ['__error' => ['code' => -32500, 'message' => 'Application error.', 'data' => 'No permissions to referred object or it does not exist!']],
            'history.get'              => ['__error' => ['code' => -32500, 'message' => 'Application error.', 'data' => 'No permissions to call "history.get".']],
            'host.get'                 => static fn (array $p): mixed => isset($p['countOutput']) ? '12' : [],
            'dashboard.get'            => static fn (array $p): mixed => isset($p['countOutput']) ? '1' : [
                ['dashboardid' => '1', 'name' => 'Global view', 'private' => '0', 'pages' => [['widgets' => [['type' => 'problems'], ['type' => 'map'], ['type' => 'problems']]]]],
            ],
        ]);

        $report = (new EndpointProbe($fake->client()))->run();
        $status = array_column($report['methods'], 'status', 'method');

        self::assertSame('7.4.3', $report['version']);
        self::assertTrue($report['version_ok']);
        self::assertSame('allowed', $status['host.get']);
        self::assertSame('denied', $status['history.get']);
        self::assertSame('denied', $status['role.get']);
        self::assertSame(12, $report['counts']['HOSTS']);
        self::assertSame(['map' => 1, 'problems' => 2], $report['dashboards'][0]['widgets']);
        self::assertSame(['map'], $report['dashboards'][0]['unsupported']);
        self::assertContains('NOT_SUPER_ADMIN', $report['warnings']);
        self::assertContains('METHODS', $report['warnings']);
        self::assertNull($report['token']['rules']);

        foreach ($fake->methods() as $method) {
            self::assertMatchesRegularExpression('/\.get$|^apiinfo\.version$|^user\.checkAuthentication$/', $method, 'Only read methods');
        }

        self::assertStringNotContainsString('a1b2c3d4', json_encode($report), 'No token in the report');
    }
}
