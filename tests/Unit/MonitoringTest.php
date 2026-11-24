<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\ZabbixColours;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\WidgetDataService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\AccessGuard;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Availability;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HistoryService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HostService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Period;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ProblemService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ValueFormatter;
use ZabbixPortal\Component\Zabbix\Administrator\Support\Formatter;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

/**
 * A small Zabbix: host 1 "web" in group 10, host 2 "db" in groups 10 and 12, host 3 "hr" in
 * group 11. Alice may read groups 10 and 11 but is denied group 12, so she sees web and hr only.
 */
final class MonitoringTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private FakeTransport $fake;

    private AccessScope $alice;

    protected function setUp(): void
    {
        $hosts = [
            '1' => ['hostid' => '1', 'host' => 'web', 'name' => 'Web', 'status' => '0', 'maintenance_status' => '0', 'active_available' => '0',
                    'interfaces' => [['type' => '1', 'available' => '1']], 'hostgroups' => [['groupid' => '10', 'name' => 'Servers']]],
            '2' => ['hostid' => '2', 'host' => 'db', 'name' => 'DB', 'status' => '0', 'maintenance_status' => '1', 'active_available' => '0',
                    'interfaces' => [['type' => '1', 'available' => '2']], 'hostgroups' => [['groupid' => '10', 'name' => 'Servers'], ['groupid' => '12', 'name' => 'Secret']]],
            '3' => ['hostid' => '3', 'host' => 'hr', 'name' => 'HR', 'status' => '0', 'maintenance_status' => '0', 'active_available' => '2',
                    'interfaces' => [['type' => '2', 'available' => '1']], 'hostgroups' => [['groupid' => '11', 'name' => 'Office']]],
        ];
        $groupsOf = ['1' => ['10'], '2' => ['10', '12'], '3' => ['11']];
        $triggers = [
            '100' => ['triggerid' => '100', 'hosts' => [['hostid' => '1', 'name' => 'Web']], 'hostgroups' => [['groupid' => '10', 'name' => 'Servers']]],
            '200' => ['triggerid' => '200', 'hosts' => [['hostid' => '2', 'name' => 'DB']], 'hostgroups' => [['groupid' => '10', 'name' => 'Servers'], ['groupid' => '12', 'name' => 'Secret']]],
            '300' => ['triggerid' => '300', 'hosts' => [['hostid' => '3', 'name' => 'HR']], 'hostgroups' => [['groupid' => '11', 'name' => 'Office']]],
        ];
        $items = [
            '1001' => ['itemid' => '1001', 'hostid' => '1', 'name' => 'CPU', 'key_' => 'cpu', 'value_type' => '0', 'units' => '%', 'lastvalue' => '42.5', 'prevvalue' => '40', 'lastclock' => '1799999990', 'hosts' => [['hostid' => '1', 'name' => 'Web']]],
            '2001' => ['itemid' => '2001', 'hostid' => '2', 'name' => 'CPU', 'key_' => 'cpu', 'value_type' => '0', 'units' => '%', 'lastvalue' => '99', 'prevvalue' => '99', 'lastclock' => '1799999990', 'hosts' => [['hostid' => '2', 'name' => 'DB']]],
        ];
        $inGroups = static fn (array $hostGroups, ?array $wanted): bool => $wanted === null || array_intersect($hostGroups, $wanted) !== [];

        $this->fake = new FakeTransport([
            'host.get' => static function (array $p) use ($hosts, $groupsOf, $inGroups): array {
                $out = [];

                foreach ($hosts as $id => $h) {
                    $id = (string) $id;

                    if ((isset($p['hostids']) && !\in_array($id, $p['hostids'], true)) || !$inGroups($groupsOf[$id], $p['groupids'] ?? null)) {
                        continue;
                    }

                    if (isset($p['search']['name']) && \is_string($p['search']['name']) && stripos($h['name'], $p['search']['name']) === false && stripos($h['host'], $p['search']['name']) === false) {
                        continue;
                    }

                    $out[] = $h;
                }

                return $out;
            },
            'problem.get' => static function (array $p) use ($groupsOf, $inGroups): array {
                $rows = [
                    ['eventid' => '3', 'objectid' => '300', 'clock' => (string) (self::NOW - 60), 'name' => 'HR down', 'severity' => '5', 'acknowledged' => '0', 'suppressed' => '0', 'host' => '3'],
                    ['eventid' => '2', 'objectid' => '200', 'clock' => (string) (self::NOW - 120), 'name' => 'DB slow', 'severity' => '4', 'acknowledged' => '1', 'suppressed' => '0', 'host' => '2'],
                    ['eventid' => '1', 'objectid' => '100', 'clock' => (string) (self::NOW - 3600), 'name' => 'Web CPU', 'severity' => '2', 'acknowledged' => '0', 'suppressed' => '0', 'host' => '1'],
                ];

                return array_values(array_filter($rows, static fn (array $r): bool => $inGroups($groupsOf[$r['host']], $p['groupids'] ?? null)
                    && (!isset($p['hostids']) || \in_array($r['host'], $p['hostids'], true))
                    && (!isset($p['severities']) || \in_array((int) $r['severity'], $p['severities'], true))));
            },
            'trigger.get' => static fn (array $p): array => array_intersect_key($triggers, array_flip($p['triggerids'] ?? array_keys($triggers))),
            'item.get' => static fn (array $p): array => array_values(array_filter($items, static fn (array $i): bool => (!isset($p['itemids']) || \in_array($i['itemid'], $p['itemids'], true))
                && $inGroups($groupsOf[$i['hostid']], $p['groupids'] ?? null))),
            'history.get' => static fn (array $p): array => array_merge(...array_map(static fn (string $id): array => [
                ['itemid' => $id, 'clock' => (string) (self::NOW - 120), 'value' => '10'],
                ['itemid' => $id, 'clock' => (string) (self::NOW - 60), 'value' => '20'],
            ], $p['itemids'])),
            'trend.get' => static fn (array $p): array => [['itemid' => $p['itemids'][0], 'clock' => (string) (self::NOW - 86400), 'value_avg' => '5']],
        ]);

        $this->alice = new AccessScope('42', 'alice', 1, ['10', '11'], ['12'], ['7']);
    }

    private function widgets(): WidgetDataService
    {
        $client   = $this->fake->client();
        $problems = new ProblemService($client, static fn (): int => self::NOW);

        return new WidgetDataService($client, new AccessGuard($client), $problems, new HostService($client, $problems), new HistoryService($client, static fn (): int => self::NOW));
    }

    private static function widget(string $type, array $fields = []): array
    {
        $list = [];

        foreach ($fields as $name => $value) {
            $list[] = ['type' => '0', 'name' => $name, 'value' => (string) $value];
        }

        return ['type' => $type, 'fields' => $list];
    }

    public function testProblemsAreLimitedToTheUsersGroupsAndDenyWins(): void
    {
        $data = $this->widgets()->data($this->alice, self::widget('problems'));

        self::assertSame('problems', $data['kind']);
        self::assertSame(['HR down', 'Web CPU'], array_column($data['rows'], 'name'), 'DB is in the denied group 12');
        self::assertSame(['10', '11'], $this->fake->lastParams('problem.get')['groupids']);
        self::assertSame(60, $data['rows'][0]['duration']);
    }

    public function testWidgetGroupsAreIntersectedWithTheUsersGroups(): void
    {
        $data = $this->widgets()->data($this->alice, self::widget('problems', ['groupids.0' => '12']));

        self::assertSame([], $data['rows']);
        self::assertNotContains('problem.get', $this->fake->methods(), 'Nothing asked for when nothing may be shown');
    }

    public function testWidgetHostTheUserMayNotSeeGivesNothing(): void
    {
        $data = $this->widgets()->data($this->alice, self::widget('problems', ['hostids.0' => '2']));

        self::assertSame([], $data['rows']);
    }

    public function testProblemsBySeverity(): void
    {
        $data = $this->widgets()->data($this->alice, self::widget('problemsbysv'));

        self::assertSame([0, 0, 1, 0, 0, 1], $data['totals']);
        self::assertSame(['Office', 'Servers'], array_column($data['groups'], 'name'));
    }

    public function testItemValueOnlyForVisibleItems(): void
    {
        $widgets = $this->widgets();

        $data = $widgets->data($this->alice, self::widget('item', ['itemid.0' => '1001']));
        self::assertSame('value', $data['kind']);
        self::assertSame(['42.50', '%'], [$data['number'], $data['units']], 'Decimal places 2, as Zabbix');
        self::assertSame('CPU', $data['description'], 'Default description {ITEM.NAME}');
        self::assertSame([1, 2, 3, 4], $data['show']);
        self::assertSame(1, $data['trend']);

        self::assertSame(['kind' => 'empty'], $widgets->data($this->alice, self::widget('item', ['itemid.0' => '2001'])), 'DB item is hidden');
    }

    public function testGraphWithItemListReadsHistory(): void
    {
        $data = $this->widgets()->data($this->alice, self::widget('svggraph', [
            'ds.0.dataset_type' => '0', 'ds.0.itemids.0' => '1001', 'ds.0.itemids.1' => '2001', 'ds.0.color.0' => 'FF0000',
        ]), Period::from('1h'));

        self::assertSame('chart', $data['kind']);
        self::assertCount(1, $data['series']);
        self::assertSame('#FF0000', $data['series'][0]['color']);
        self::assertSame([[self::NOW - 120, 10.0], [self::NOW - 60, 20.0]], $data['series'][0]['points']);
        self::assertSame(['1001'], $this->fake->lastParams('history.get')['itemids']);
    }

    public function testPatternDataSetColoursAreZabbixShadesOrPalette(): void
    {
        $shades = $this->widgets()->data($this->alice, self::widget('svggraph', [
            'ds.0.dataset_type' => '1', 'ds.0.hosts.0' => '*', 'ds.0.items.0' => '*', 'ds.0.color' => 'FF465C',
        ]), Period::from('1h'));
        $count = \count($shades['series']);

        self::assertGreaterThan(0, $count);
        self::assertSame(ZabbixColours::variations('#FF465C', $count), array_column($shades['series'], 'color'));

        $palette = $this->widgets()->data($this->alice, self::widget('svggraph', [
            'ds.0.dataset_type' => '1', 'ds.0.hosts.0' => '*', 'ds.0.items.0' => '*', 'ds.0.color_palette' => '2',
        ]), Period::from('1h'));

        self::assertSame(ZabbixColours::palette(2, $count), array_column($palette['series'], 'color'));
    }

    public function testChartsReadTheNewestHistoryAndKeepColoursPerItem(): void
    {
        // Item 1001 is numeric; a text item between two numeric ones must not shift the colours.
        $this->fake->routes['item.get'] = static fn (array $p): array => array_values(array_filter([
            ['itemid' => '1001', 'hostid' => '1', 'name' => 'CPU', 'value_type' => '0', 'units' => '%', 'hosts' => [['hostid' => '1', 'name' => 'Web']]],
            ['itemid' => '1002', 'hostid' => '1', 'name' => 'Version', 'value_type' => '4', 'units' => '', 'hosts' => [['hostid' => '1', 'name' => 'Web']]],
            ['itemid' => '1003', 'hostid' => '1', 'name' => 'Load', 'value_type' => '0', 'units' => '', 'hosts' => [['hostid' => '1', 'name' => 'Web']]],
        ], static fn (array $i): bool => !isset($p['itemids']) || \in_array($i['itemid'], $p['itemids'], true)));
        $this->fake->routes['host.get'] = static fn (): array => [['hostid' => '1', 'hostgroups' => [['groupid' => '10']]]];

        $data = $this->widgets()->data($this->alice, self::widget('svggraph', [
            'ds.0.dataset_type' => '0', 'ds.0.itemids.0' => '1001', 'ds.0.itemids.1' => '1002', 'ds.0.itemids.2' => '1003',
            'ds.0.color.0' => 'FF0000', 'ds.0.color.1' => '00FF00', 'ds.0.color.2' => '0000FF',
        ]), Period::from('1h'));

        self::assertSame(['1001' => '#FF0000', '1003' => '#0000FF'], array_column($data['series'], 'color', 'itemid'));
        self::assertSame('DESC', $this->fake->lastParams('history.get')['sortorder'], 'newest rows first, so a limit drops the oldest');
        self::assertSame([[self::NOW - 120, 10.0], [self::NOW - 60, 20.0]], $data['series'][0]['points'], 'drawn in time order');
    }

    public function testHostWidgetsWithoutProblemsDoNotReadProblems(): void
    {
        $this->widgets()->data($this->alice, self::widget('hostavail'));

        self::assertNotContains('problem.get', $this->fake->methods());
        self::assertNotContains('trigger.get', $this->fake->methods());
    }

    public function testSystemInformationDoesNotCountHostsInADeniedGroup(): void
    {
        $this->fake->routes['apiinfo.version'] = '7.4.3';
        $data  = $this->widgets()->data($this->alice, self::widget('systeminfo'));
        $hosts = array_column($data['rows'], 'value', 'name')['hosts'];

        self::assertSame([2, 0], $hosts, 'web and hr; db is also in denied group 12 (Deny wins)');
        self::assertSame(['1', '3'], $this->fake->lastParams('item.get')['hostids'], 'items counted on the visible hosts only');
    }

    public function testUnixtimeValuesUseThePortalTimeZone(): void
    {
        $item = ['value_type' => '3', 'units' => 'unixtime'];

        ValueFormatter::useTimezone(null);
        self::assertSame('2027-01-15 08:00:00', ValueFormatter::format($item, '1800000000'), 'UTC until a time zone is set');

        ValueFormatter::useTimezone(Formatter::zone('', 'Asia/Riyadh', 'UTC'));
        self::assertSame('2027-01-15 11:00:00', ValueFormatter::format($item, '1800000000'));
        ValueFormatter::useTimezone(null);

        self::assertSame('Europe/Paris', Formatter::zone('Europe/Paris', 'Asia/Riyadh', 'UTC')->getName(), 'the setting wins');
        self::assertSame('UTC', Formatter::zone('', '', 'Not/AZone')->getName());
    }

    public function testLongPeriodsReadTrends(): void
    {
        $this->widgets()->data($this->alice, self::widget('svggraph', ['ds.0.dataset_type' => '0', 'ds.0.itemids.0' => '1001']), Period::from('7d'));

        self::assertContains('trend.get', $this->fake->methods());
        self::assertNotContains('history.get', $this->fake->methods());
    }

    public function testHostsListAndAvailability(): void
    {
        $client   = $this->fake->client();
        $problems = new ProblemService($client, static fn (): int => self::NOW);
        $hosts    = (new HostService($client, $problems))->hosts($this->alice);

        self::assertSame(['Web', 'HR'], array_column($hosts, 'name'));
        self::assertSame(Availability::AVAILABLE, $hosts[0]['availability']);
        self::assertSame(Availability::MIXED, $hosts[1]['availability'], 'SNMP up, active agent down');
        self::assertSame([0, 0, 0, 0, 0, 1], $hosts[1]['problems']);

        self::assertNull((new HostService($client, $problems))->host($this->alice, '2'), 'Denied host');
    }

    public function testHostAvailabilityWidget(): void
    {
        $data = $this->widgets()->data($this->alice, self::widget('hostavail'));

        self::assertSame(1, $data['types']['agent']['available']);
        self::assertSame(1, $data['types']['snmp']['available']);
        self::assertSame(2, $data['totals']['total']);
    }

    public function testUnknownWidgetIsUnsupported(): void
    {
        self::assertSame(['kind' => 'unsupported'], $this->widgets()->data($this->alice, self::widget('map')));
        self::assertSame([], $this->fake->calls);
    }

    public function testDownsample(): void
    {
        $points = [];

        for ($i = 0; $i < 1000; $i++) {
            $points[] = [$i, (float) $i];
        }

        $out = HistoryService::downsample($points, 0, 1000, 100);

        self::assertCount(100, $out);
        self::assertSame(4.5, $out[0][1]);
    }

    public function testValueFormatter(): void
    {
        // As Zabbix's convertUnits(): units after a space, K/M/G (1024 for B), no prefix for %.
        self::assertSame('1.5 KB', ValueFormatter::number(1536, 'B'));
        self::assertSame('2.5 Mbps', ValueFormatter::number(2_500_000, 'bps'));
        self::assertSame('12.345 %', ValueFormatter::number(12.345, '%'));
        self::assertSame('226.57 vps', ValueFormatter::number(226.57, '!vps'), '"!" = no prefix, not shown');
        // Widgets: exact decimal places.
        self::assertSame('58.19 %', ValueFormatter::number(58.1912, '%', 2));
        self::assertSame('42.00 °C', ValueFormatter::number(42, '°C', 2));
        self::assertSame('1.50 KB', ValueFormatter::number(1536, 'B', 2));
        // Time units.
        self::assertSame('64 days, 07:02:31', ValueFormatter::number(5554951, 'uptime'));
        self::assertSame('1 day, 02:00:00', ValueFormatter::number(93600, 'uptime'));
        self::assertSame('00:05:00', ValueFormatter::number(300, 'uptime'));
        self::assertSame('1h 38m 51s', ValueFormatter::number(5931, 's'));
        self::assertSame('21d 3h 42m', ValueFormatter::number(21 * 86400 + 3 * 3600 + 42 * 60 + 10, 's'));
        self::assertSame('2M 21d 3h', ValueFormatter::number(81 * 86400 + 3 * 3600 + 42 * 60, 's'), 'Zabbix counts 30-day months');
        self::assertSame('1m 5s 120ms', ValueFormatter::number(65.12, 's'));
        self::assertSame('0', ValueFormatter::number(0, 's'));
        // Value mappings keep the formatted value; unsigned integers have no decimals.
        $map = ['mappings' => [['type' => '0', 'value' => '1', 'newvalue' => 'available']]];
        self::assertSame('available (1.00)', ValueFormatter::format(['value_type' => '0', 'valuemap' => $map], '1', 2));
        self::assertSame('Up (1)', ValueFormatter::format(['value_type' => '3', 'valuemap' => ['mappings' => [['type' => '0', 'value' => '1', 'newvalue' => 'Up']]]], '1'));
        self::assertSame('214', ValueFormatter::format(['value_type' => '3', 'units' => ''], '214'), 'Integers without widget decimals');
        self::assertSame('214.00', ValueFormatter::format(['value_type' => '3', 'units' => ''], '214', 2), 'Widget decimals apply to integers too, as Zabbix');
        self::assertSame('214', ValueFormatter::format(['value_type' => '3', 'units' => ''], '214', 0));
        self::assertSame('available (1.00)', ValueFormatter::format(['value_type' => '3', 'valuemap' => $map], '1', 2), 'As the Agent column in Zabbix');
        self::assertSame('text', ValueFormatter::format(['value_type' => '4'], 'text'));
    }

    public function testPeriod(): void
    {
        self::assertSame('1h', Period::from('bogus')->name);
        self::assertSame('3h', Period::fromZabbix('now-2h')->name);
        self::assertSame('7d', Period::fromZabbix('now-1w')->name);
        self::assertSame('1h', Period::fromZabbix('2026-01-01 00:00:00')->name);
        self::assertTrue(Period::from('7d')->usesTrends());
        self::assertFalse(Period::from('24h')->usesTrends());
    }
}
