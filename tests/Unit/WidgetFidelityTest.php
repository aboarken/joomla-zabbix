<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\WidgetDataService;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\WidgetFields;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\AccessGuard;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Availability;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HistoryService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HostService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ProblemService;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

/**
 * Widgets drawn as Zabbix 7.4 draws them, with the field names of Zabbix's widget forms.
 */
final class WidgetFidelityTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private FakeTransport $fake;

    private AccessScope $root;

    protected function setUp(): void
    {
        $host = static fn (string $id, string $name, array $interfaces, int $active = 0, int $maintenance = 0): array => [
            'hostid' => $id, 'host' => $name, 'name' => $name, 'status' => '0', 'maintenance_status' => (string) $maintenance,
            'active_available' => (string) $active, 'interfaces' => $interfaces, 'hostgroups' => [['groupid' => '10', 'name' => 'Servers']],
        ];
        $hosts = [
            $host('1', 'bak3', [['type' => '1', 'available' => '1']], 1),
            $host('2', 'bak5', [['type' => '1', 'available' => '1'], ['type' => '1', 'available' => '2']]),
            $host('3', 'hv1', [['type' => '1', 'available' => '2']]),
            $host('4', 'maint', [['type' => '1', 'available' => '1']], 0, 1),
        ];
        $items = [
            ['itemid' => '11', 'hostid' => '1', 'name' => 'CPU utilization', 'key_' => 'system.cpu.util', 'lastvalue' => '58.1912', 'prevvalue' => '50', 'lastclock' => '1799999990', 'units' => '%', 'value_type' => '0', 'hosts' => [['hostid' => '1', 'name' => 'bak3']]],
            ['itemid' => '21', 'hostid' => '2', 'name' => 'CPU utilization', 'key_' => 'system.cpu.util', 'lastvalue' => '34.5', 'prevvalue' => '34.5', 'lastclock' => '1799999990', 'units' => '%', 'value_type' => '0', 'hosts' => [['hostid' => '2', 'name' => 'bak5']]],
            ['itemid' => '12', 'hostid' => '1', 'name' => 'Uptime', 'key_' => 'system.uptime', 'lastvalue' => '5554951', 'prevvalue' => '5554900', 'lastclock' => '1799999990', 'units' => 'uptime', 'value_type' => '3', 'hosts' => [['hostid' => '1', 'name' => 'bak3']]],
        ];

        $this->fake = new FakeTransport([
            'host.get' => static fn (array $p): mixed => isset($p['countOutput']) ? '7' : array_values(array_filter($hosts, static fn ($h): bool => !isset($p['hostids']) || \in_array($h['hostid'], $p['hostids'], true))),
            'item.get' => static fn (array $p): mixed => isset($p['countOutput']) ? '120' : array_values(array_filter($items, static fn ($i): bool => (!isset($p['itemids']) || \in_array($i['itemid'], $p['itemids'], true))
                && (!isset($p['filter']['name']) || $i['name'] === $p['filter']['name'])
                && (!isset($p['hostids']) || \in_array($i['hostid'], $p['hostids'], true)))),
            'problem.get' => [],
            'trigger.get' => static fn (array $p): mixed => isset($p['countOutput']) ? '40' : [],
            'template.get' => '300',
            'user.get' => '25',
            'apiinfo.version' => '7.4.16',
            'history.get' => [],
        ]);

        $this->root = new AccessScope('1', 'Admin', AccessScope::ROLE_SUPER_ADMIN);
    }

    private function data(string $type, array $fields, ?AccessScope $scope = null): array
    {
        $client   = $this->fake->client();
        $problems = new ProblemService($client, static fn (): int => self::NOW);
        $service  = new WidgetDataService($client, new AccessGuard($client), $problems, new HostService($client, $problems), new HistoryService($client, static fn (): int => self::NOW));
        $list     = [];

        foreach ($fields as $name => $value) {
            $list[] = ['type' => '0', 'name' => $name, 'value' => (string) $value];
        }

        return $service->data($scope ?? $this->root, ['type' => $type, 'fields' => $list]);
    }

    public function testTopHostsBarsThresholdsAndDecimals(): void
    {
        $data = $this->data('tophosts', [
            'columns.0.name' => 'Host name', 'columns.0.data' => 2,
            'columns.1.name' => 'CPU', 'columns.1.data' => 1, 'columns.1.item' => 'CPU utilization', 'columns.1.display' => 3,
            'columns.1.min' => 0, 'columns.1.max' => 100,
            'columnsthresholds.1.color.0' => 'FFFF00', 'columnsthresholds.1.threshold.0' => 50,
            'columnsthresholds.1.color.1' => 'FF0000', 'columnsthresholds.1.threshold.1' => 90,
            'columns.2.name' => 'Uptime', 'columns.2.data' => 1, 'columns.2.item' => 'Uptime',
            'column' => 1, 'order' => 2,
        ]);

        self::assertSame(['Host name', 'CPU', 'Uptime'], array_column($data['columns'], 'name'));
        self::assertTrue($data['columns'][1]['bar']);
        self::assertSame(['bak3', 'bak5', 'hv1'], array_column(array_column($data['rows'], 0), 'display'), 'Sorted by CPU, top N; hosts without a value last; hosts in maintenance left out');
        self::assertArrayNotHasKey('bar', $data['rows'][2][1], 'No value, no bar');
        $cpu = $data['rows'][0][1];
        self::assertSame('58.19 %', $cpu['display']);
        self::assertTrue($cpu['bar']['indicators']);
        self::assertSame('#FFFF00', $cpu['bar']['fill'], 'Colour of the highest threshold reached');
        self::assertSame([0.0, 100.0], [$cpu['bar']['min'], $cpu['bar']['max']]);
        self::assertSame('#97AAB3', $data['rows'][1][1]['bar']['fill'], 'Zabbix default fill below the thresholds');
        self::assertSame('#97AAB3', $cpu['bar']['base'], 'Indicator segments below the first threshold: Zabbix default fill');
        self::assertSame('64 days, 07:02:31', $data['rows'][0][2]['display']);
    }

    public function testItemValueSettings(): void
    {
        $data = $this->data('item', [
            'itemid.0' => '11', 'show.0' => 1, 'show.1' => 2, 'show.2' => 5,
            'description' => 'Load on {HOST.NAME}', 'desc_h_pos' => 0, 'desc_v_pos' => 0, 'decimal_places' => 1,
            'bg_color' => 'FFCCCC', 'thresholds.0.color' => 'FF0000', 'thresholds.0.threshold' => 90,
        ]);

        self::assertSame('Load on bak3', $data['description']);
        self::assertSame(['58.2', '%'], [$data['number'], $data['units']]);
        self::assertSame([0, 0], [$data['desc']['h'], $data['desc']['v']]);
        self::assertSame('#FFCCCC', $data['bg'], 'Below the threshold: the background colour');
        self::assertNotNull($data['sparkline']);
        self::assertSame('#42A5F5', $data['sparkline']['color'], 'Zabbix default sparkline colour');
        self::assertSame(['#3DC51D', '#FC5A5A', '#3DC51D'], [$data['up'], $data['down'], $data['updown']], 'Zabbix change indicator colours');

        $hot = $this->data('item', ['itemid.0' => '11', 'thresholds.0.color' => 'FF0000', 'thresholds.0.threshold' => 50]);
        self::assertSame('#FF0000', $hot['bg']);
        self::assertNull($hot['sparkline'], 'Not in the default Show list');
    }

    public function testGaugeSettings(): void
    {
        $data = $this->data('gauge', [
            'itemid.0' => '11', 'min' => 0, 'max' => 100,
            'thresholds.0.color' => '4CAF50', 'thresholds.0.threshold' => 0,
            'thresholds.1.color' => 'FFEB3B', 'thresholds.1.threshold' => 40,
            'thresholds.2.color' => 'F44336', 'thresholds.2.threshold' => 80,
            'th_show_arc' => 1, 'th_show_labels' => 1, 'show.0' => 1, 'show.1' => 2, 'show.2' => 3,
        ]);

        self::assertSame(['58.19', '%'], [$data['number'], $data['units']]);
        self::assertSame('#FFEB3B', $data['arcColor']);
        self::assertTrue($data['thArc'] && $data['thLabels']);
        self::assertSame(['0 %', '40 %', '80 %'], array_column($data['thresholds'], 'label'));
        self::assertSame([1, 2, 3], $data['show'], 'Needle on');
        self::assertSame(180, $data['angle']);
    }

    public function testHostAvailabilityCountsMixedAndActiveAgent(): void
    {
        $data = $this->data('hostavail', ['interface_type.0' => 1]);

        self::assertTrue($data['boxes'], 'One interface type: coloured boxes, as Zabbix');
        self::assertSame(
            [Availability::AVAILABLE => 1, Availability::UNAVAILABLE => 1, Availability::MIXED => 1, Availability::UNKNOWN => 0, 'total' => 3],
            $data['types']['agent'],
            'The host in maintenance is left out'
        );

        $all = $this->data('hostavail', []);
        self::assertFalse($all['boxes']);
        self::assertSame(1, $all['types']['agent_active']['available']);
    }

    public function testSystemInformation(): void
    {
        $rows = array_column($this->data('systeminfo', [])['rows'], 'value', 'name');

        self::assertSame('7.4.16', $rows['version']);
        self::assertSame([7, 7], $rows['hosts']);
        self::assertSame(300, $rows['templates']);
        self::assertSame(25, $rows['users']);

        $user = array_column($this->data('systeminfo', [], new AccessScope('42', 'alice', 1, ['10']))['rows'], 'name');
        self::assertNotContains('templates', $user, 'Only for Super admins');
        self::assertNotContains('users', $user);
        self::assertSame(['10'], $this->fake->lastParams('trigger.get')['groupids'], 'Counted in the user\'s host groups');
    }

    public function testProblemsLeaveSymptomsOut(): void
    {
        $this->data('problems', []);
        self::assertFalse($this->fake->lastParams('problem.get')['symptom']);

        $this->data('problemsbysv', []);
        self::assertFalse($this->fake->lastParams('problem.get')['symptom'], 'As Zabbix\'s Problems by severity');

        $this->data('problems', ['show_symptoms' => 1, 'show_tags' => 3]);
        self::assertArrayNotHasKey('symptom', $this->fake->lastParams('problem.get'));
        self::assertSame(['tag', 'value'], $this->fake->lastParams('problem.get')['selectTags']);
    }

    public function testClockSettings(): void
    {
        $clock = WidgetDataService::clock(new WidgetFields([
            ['name' => 'clock_type', 'value' => '1'], ['name' => 'show.0', 'value' => '1'], ['name' => 'show.1', 'value' => '2'],
            ['name' => 'show.2', 'value' => '3'], ['name' => 'tzone_timezone', 'value' => 'America/Denver'], ['name' => 'time_sec', 'value' => '0'],
        ]));

        self::assertSame([true, [1, 2, 3], 'America/Denver', false], [$clock['digital'], $clock['show'], $clock['timezone'], $clock['seconds']]);
        self::assertSame('local', WidgetDataService::clock(new WidgetFields([]))['timezone']);
        self::assertSame('', WidgetDataService::clock(new WidgetFields([['name' => 'tzone_timezone', 'value' => 'Mars/Base']]))['timezone']);
    }

    public function testProblemsSkipUnmonitoredAndDependentTriggersAsZabbix(): void
    {
        $this->fake->routes['problem.get'] = [['eventid' => '1', 'objectid' => '100', 'clock' => '1', 'name' => 'Link down', 'severity' => '2', 'acknowledged' => '0', 'suppressed' => '0']];
        $this->data('problemsbysv', []);

        $params = $this->fake->lastParams('trigger.get');
        self::assertTrue($params['monitored']);
        self::assertTrue($params['skipDependent'], 'e.g. "speed changed" depending on "link down" is not counted');
    }

    public function testCountsComeWithTheirLists(): void
    {
        $avail = $this->data('hostavail', ['interface_type.0' => 1]);
        self::assertSame([['hostid' => '3', 'name' => 'hv1']], $avail['lists']['agent']['unavailable'], 'Not available: the hosts that are down');
        self::assertSame('bak5', $avail['lists']['agent']['mixed'][0]['name']);
        self::assertCount(1, $avail['lists']['total']['available']);

        $rows = [
            ['severity' => 4, 'clock' => 10, 'duration' => 5, 'name' => 'Unavailable by ICMP ping', 'hosts' => [['hostid' => '3', 'name' => 'hv1']], 'acknowledged' => false],
            ['severity' => 2, 'clock' => 9, 'duration' => 6, 'name' => 'Link down', 'hosts' => [['hostid' => '1', 'name' => 'bak3']], 'acknowledged' => true],
        ];
        $lists = WidgetDataService::listsBySeverity($rows);
        self::assertSame('hv1', $lists[4][0]['hosts'][0]['name'], 'High: the devices with high problems');
        self::assertTrue($lists[2][0]['ack']);
        self::assertArrayNotHasKey(5, $lists);

        $many = array_fill(0, 150, $rows[0]);
        self::assertCount(WidgetDataService::LIST_MAX, WidgetDataService::listsBySeverity($many)[4], 'Lists are capped');
    }

    public function testThresholdColour(): void
    {
        $t = [['threshold' => 0.0, 'color' => '#00FF00'], ['threshold' => 80.0, 'color' => '#FF0000']];

        self::assertNull(WidgetDataService::thresholdColour(-1, $t));
        self::assertSame('#00FF00', WidgetDataService::thresholdColour(79.9, $t));
        self::assertSame('#FF0000', WidgetDataService::thresholdColour(80, $t));
    }
}
