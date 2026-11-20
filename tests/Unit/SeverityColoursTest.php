<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\MemoryCache;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\SeverityColours;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

final class SeverityColoursTest extends TestCase
{
    public function testColoursSetInZabbixAreUsedAndCached(): void
    {
        $transport = new FakeTransport(['settings.get' => [
            'severity_color_0' => '97AAB3', 'severity_color_1' => '7499ff', 'severity_color_2' => 'FFC859',
            'severity_color_3' => 'FFA059', 'severity_color_4' => 'E97659', 'severity_color_5' => 'C00000',
        ]]);
        $service = new SeverityColours($transport->client(), new MemoryCache());

        self::assertSame(['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#C00000'], $service->colours());
        self::assertSame(['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#C00000'], $service->colours());
        self::assertSame(['settings.get'], $transport->methods(), 'read once, then from the cache');
        self::assertContains('severity_color_5', $transport->lastParams('settings.get')['output']);
    }

    public function testInvalidOrMissingColoursFallBackToZabbixDefaults(): void
    {
        self::assertSame(SeverityColours::DEFAULTS, SeverityColours::fromSettings([]));
        self::assertSame(
            ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'],
            SeverityColours::fromSettings(['severity_color_4' => 'red;}', 'severity_color_5' => '#E4595'])
        );
    }

    public function testDefaultsWhenZabbixCannotBeAsked(): void
    {
        $transport = new FakeTransport(['settings.get' => ['__error' => ['code' => -32602, 'message' => 'Invalid params.', 'data' => 'No permissions.']]]);

        self::assertSame(SeverityColours::DEFAULTS, (new SeverityColours($transport->client(), new MemoryCache()))->colours());
    }
}
