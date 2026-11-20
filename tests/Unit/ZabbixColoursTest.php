<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\ZabbixColours;

final class ZabbixColoursTest extends TestCase
{
    public function testVariationsAreShadesOfTheDataSetColourAsZabbix(): void
    {
        self::assertSame(['#FF465C'], ZabbixColours::variations('#FF465C', 1));
        self::assertSame(['#7F0000', '#D41B31', '#FF7086'], ZabbixColours::variations('#FF465C', 3));
        self::assertCount(7, ZabbixColours::variations('#0EC9AC', 7));
    }

    public function testPaletteTakesEveryThirdUnusedColourOfTheRow(): void
    {
        self::assertSame(['#F48485', '#CA76BE', '#4876B1', '#619F3A'], ZabbixColours::palette(0, 4));
        self::assertSame('#C75566', ZabbixColours::palette(1, 1)[0]);
        self::assertCount(18 * 12 + 5, ZabbixColours::palette(0, 18 * 12 + 5), 'starts over when every colour is used');
        self::assertSame(ZabbixColours::palette(0, 3), ZabbixColours::palette(-4, 3), 'an unknown row is clamped');
    }
}
