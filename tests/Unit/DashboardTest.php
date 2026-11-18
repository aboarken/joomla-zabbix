<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\MemoryCache;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\DashboardLayout;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\DashboardService;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\WidgetFields;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

final class DashboardTest extends TestCase
{
    public function testWidgetFields(): void
    {
        $f = new WidgetFields([
            ['type' => '2', 'name' => 'groupids.1', 'value' => '20'],
            ['type' => '2', 'name' => 'groupids.0', 'value' => '10'],
            ['type' => '0', 'name' => 'severities.0', 'value' => '4'],
            ['type' => '0', 'name' => 'show_lines', 'value' => '7'],
            ['type' => '4', 'name' => 'ds.0.itemids.0', 'value' => '100'],
            ['type' => '1', 'name' => 'ds.2.hosts.0', 'value' => 'web*'],
            ['type' => '1', 'name' => 'groupids._reference', 'value' => 'ABCDE._hostgroupids'],
        ]);

        self::assertSame(['10', '20'], $f->list('groupids'));
        self::assertSame([4], $f->intList('severities'));
        self::assertSame(7, $f->int('show_lines', 25));
        self::assertSame(25, $f->int('missing', 25));
        self::assertSame([0, 2], $f->indexes('ds'));
        self::assertSame(['100'], $f->list('ds.0.itemids'));
    }

    public function testLayoutFromApiAndForBrowser(): void
    {
        $layout = DashboardLayout::fromApi([
            'dashboardid' => '5', 'name' => 'Main',
            'pages' => [[
                'dashboard_pageid' => '9', 'name' => '',
                'widgets' => [
                    ['widgetid' => '2', 'type' => 'map', 'name' => '', 'x' => '36', 'y' => '0', 'width' => '36', 'height' => '5', 'view_mode' => '0', 'fields' => []],
                    ['widgetid' => '1', 'type' => 'problems', 'name' => 'Now', 'x' => '0', 'y' => '0', 'width' => '200', 'height' => '5', 'view_mode' => '1',
                     'fields' => [['type' => '0', 'name' => 'show_lines', 'value' => '5']]],
                    ['widgetid' => '3', 'type' => 'clock', 'name' => '', 'x' => '0', 'y' => '5', 'width' => '12', 'height' => '3', 'fields' => [['type' => '0', 'name' => 'clock_type', 'value' => '1']]],
                ],
            ]],
        ]);

        $widgets = $layout['pages'][0]['widgets'];
        self::assertSame(['1', '2', '3'], array_column($widgets, 'id'), 'Sorted by row, then column');
        self::assertSame(72, $widgets[0]['width'], 'Clamped to the 72-column grid');
        self::assertTrue($widgets[0]['hideHeader']);
        self::assertFalse($widgets[1]['supported']);
        self::assertSame('Map', $widgets[1]['title']);

        $browser = DashboardLayout::forBrowser($layout);
        self::assertArrayNotHasKey('fields', $browser['pages'][0]['widgets'][0], 'Widget fields stay on the server');
        self::assertSame(['digital' => true], $browser['pages'][0]['widgets'][2]['clock']);
        self::assertSame('problems', DashboardLayout::widget($layout, '1')['type']);
        self::assertNull(DashboardLayout::widget($layout, '99'));
    }

    public function testServiceHidesDashboardsTheUserMayNotSee(): void
    {
        $fake = new FakeTransport(['dashboard.get' => static fn (array $p): array => isset($p['dashboardids'])
            ? [['dashboardid' => $p['dashboardids'][0], 'name' => 'X', 'pages' => [['widgets' => []]]]]
            : [
                ['dashboardid' => '1', 'name' => 'Public', 'userid' => '1', 'private' => '0', 'users' => [], 'userGroups' => []],
                ['dashboardid' => '2', 'name' => 'Secret', 'userid' => '1', 'private' => '1', 'users' => [], 'userGroups' => []],
                ['dashboardid' => '3', 'name' => 'Shared', 'userid' => '1', 'private' => '1', 'users' => [], 'userGroups' => [['usrgrpid' => '7', 'permission' => '2']]],
            ]]);
        $service = new DashboardService($fake->client(), new MemoryCache());
        $scope   = new AccessScope('42', 'alice', 1, ['10'], [], ['7']);

        self::assertSame(['Public', 'Shared'], array_column($service->visibleTo($scope), 'name'));
        self::assertNull($service->layout($scope, '2'));
        self::assertNull($service->layout($scope, 'abc'));
        self::assertSame('3', $service->layout($scope, '3')['id']);
    }
}
