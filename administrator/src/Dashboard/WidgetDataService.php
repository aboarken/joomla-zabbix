<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\AccessGuard;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Availability;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HistoryService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\HostService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Period;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ProblemService;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ValueFormatter;

/**
 * The data of one dashboard widget, read with the token and limited to what the portal user may
 * see. Every host and item a widget names is checked (AccessGuard) before its data is read.
 *
 * The answer is plain data with a "kind" that media/com_zabbix/js/dashboard.js draws:
 * problems, severity, availability, value, gauge, history, chart, pie, table, hosts, clock,
 * unsupported.
 */
final class WidgetDataService
{
    /** Colours used when a data set has none (Zabbix's own palette order). */
    public const PALETTE = ['#2774a4', '#e57373', '#43a047', '#ffa000', '#8e24aa', '#00897b', '#6d4c41', '#3949ab', '#c0ca33', '#d81b60'];

    private const MAX_PATTERN_ITEMS = 50;

    public function __construct(
        private readonly JsonRpcClient $client,
        private readonly AccessGuard $guard,
        private readonly ProblemService $problems,
        private readonly HostService $hosts,
        private readonly HistoryService $history
    ) {
    }

    /**
     * @param array<string, mixed> $widget  from DashboardLayout (type, fields)
     *
     * @return array<string, mixed>
     */
    public function data(AccessScope $scope, array $widget, ?Period $period = null): array
    {
        $fields = new WidgetFields((array) ($widget['fields'] ?? []));
        $period ??= Period::fromZabbix($fields->get('time_period.from'), '1h');

        return match ((string) ($widget['type'] ?? '')) {
            'problems'               => $this->problemList($scope, $fields),
            'problemsbysv'           => $this->bySeverity($scope, $fields),
            'hostavail'              => $this->availability($scope, $fields),
            'item'                   => $this->itemValue($scope, $fields),
            'gauge'                  => $this->gauge($scope, $fields),
            'itemhistory'            => $this->itemHistory($scope, $fields, 'columns'),
            'plaintext'              => $this->itemHistory($scope, $fields, 'itemids'),
            'svggraph'               => $this->chart($scope, $fields, $period),
            'graph'                  => $this->classicGraph($scope, $fields, $period),
            'piechart'               => $this->pie($scope, $fields),
            'tophosts'               => $this->topHosts($scope, $fields),
            'hostnavigator'          => $this->hostList($scope, $fields),
            'clock'                  => self::clock($fields),
            'systeminfo'             => $this->systemInfo($scope, $fields),
            default                  => ['kind' => 'unsupported'],
        };
    }

    /** @return array<string, mixed> */
    private function problemList(AccessScope $scope, WidgetFields $fields): array
    {
        $limit   = max(1, min(100, $fields->int('show_lines', 25)));
        $hostIds = $this->hostFilter($scope, $fields);

        // The widget names hosts, but the user may see none of them.
        if ($fields->has('hostids.0') && $hostIds === []) {
            return ['kind' => 'problems', 'rows' => [], 'total' => 0];
        }

        $rows = $this->problems->problems(
            $scope,
            $fields->list('groupids'),
            $hostIds,
            $fields->intList('severities'),
            $limit * 2,
            $fields->int('acknowledgement_status'),
            $fields->int('show_suppressed') === 1,
            $fields->int('show_symptoms') === 1,
            $fields->int('show_tags') > 0
        );

        $rows = $this->excludeGroups($rows, $fields->list('exclude_groupids'));

        $showTags = max(0, min(3, $fields->int('show_tags')));
        $format   = $fields->int('tag_name_format');
        $rows     = array_map(static function (array $row) use ($showTags, $format): array {
            $row['tags'] = array_map(static fn (array $t): string => match ($format) {
                1       => mb_substr($t['tag'], 0, 3) . ($t['value'] !== '' ? ': ' . $t['value'] : ''),
                2       => $t['value'],
                default => $t['tag'] . ($t['value'] !== '' ? ': ' . $t['value'] : ''),
            }, \array_slice($row['tags'], 0, $showTags));

            return $row;
        }, \array_slice($rows, 0, $limit));

        return ['kind' => 'problems', 'rows' => $rows, 'total' => \count($rows), 'highlightRow' => $fields->int('highlight_row') === 1];
    }

    /** @return array<string, mixed> */
    private function bySeverity(AccessScope $scope, WidgetFields $fields): array
    {
        $groupIds = $fields->list('groupids');
        $hostIds  = $this->hostFilter($scope, $fields);
        $rows     = $fields->has('hostids.0') && $hostIds === []
            ? []
            : $this->problems->problems($scope, $groupIds, $hostIds, $fields->intList('severities'), ProblemService::MAX);
        $rows     = $this->excludeGroups($rows, $fields->list('exclude_groupids'));
        $only     = $groupIds !== [] ? $groupIds : ($scope->all() ? null : $scope->readGroupIds);
        $counts   = ProblemService::countBySeverity($rows, $only);
        $shown    = $fields->intList('severities');

        return [
            'kind'       => 'severity',
            'totals'     => $counts['totals'],
            'groups'     => $counts['groups'],
            'showGroups' => $fields->int('show_type') !== 1,
            'vertical'   => $fields->int('layout') === 1,
            'severities' => $shown !== [] ? $shown : ProblemService::SEVERITIES,
            // Clicking a severity lists its problems (newest first), as Zabbix's pop-up does.
            'lists'      => self::listsBySeverity($rows),
        ];
    }

    /** Most problems (or hosts) listed when a count box is clicked. */
    public const LIST_MAX = 100;

    /**
     * @param  list<array<string, mixed>>  $rows  problems, newest first
     * @return array<int, list<array<string, mixed>>>  severity => problems
     */
    public static function listsBySeverity(array $rows): array
    {
        $lists = [];

        foreach ($rows as $row) {
            $severity = (int) $row['severity'];

            if (\count($lists[$severity] ?? []) < self::LIST_MAX) {
                $lists[$severity][] = [
                    'clock'    => $row['clock'],
                    'duration' => $row['duration'],
                    'name'     => $row['name'],
                    'hosts'    => $row['hosts'],
                    'ack'      => $row['acknowledged'],
                ];
            }
        }

        return $lists;
    }

    /**
     * Host availability, as Zabbix's widget: per interface type (field interface_type: 1 agent,
     * 2 SNMP, 3 IPMI, 4 JMX, 5 agent active checks; none = all) the hosts that are available,
     * not available, mixed or unknown. Only monitored hosts; hosts in maintenance only when the
     * widget asks for them.
     *
     * @return array<string, mixed>
     */
    private function availability(AccessScope $scope, WidgetFields $fields): array
    {
        $hosts = array_values(array_filter(
            $this->hosts->hosts($scope, $fields->list('groupids')),
            static fn (array $h): bool => $h['enabled'] && ($fields->int('maintenance') === 1 || !$h['maintenance'])
        ));

        $wanted = array_values(array_filter(array_map(
            static fn (int $t): string => Availability::TYPES[$t] ?? '',
            $fields->intList('interface_type')
        )));
        $wanted = $wanted !== [] ? $wanted : ['agent_active', 'agent', 'snmp', 'jmx', 'ipmi'];
        $empty  = [Availability::AVAILABLE => 0, Availability::UNAVAILABLE => 0, Availability::MIXED => 0, Availability::UNKNOWN => 0, 'total' => 0];
        $types  = array_fill_keys($wanted, $empty);
        $totals = $empty;
        // Clicking a count lists its hosts: [type or 'total'][state] => [{hostid, name}].
        $lists  = [];
        $add    = static function (string $key, string $state, array $host) use (&$lists): void {
            if (\count($lists[$key][$state] ?? []) < self::LIST_MAX) {
                $lists[$key][$state][] = ['hostid' => $host['hostid'], 'name' => $host['name']];
            }
        };

        foreach ($hosts as $host) {
            $states = [];

            foreach ($wanted as $type) {
                if (isset($host['interfaces'][$type])) {
                    $state = $host['interfaces'][$type];
                    $types[$type][$state]++;
                    $types[$type]['total']++;
                    $add($type, $state, $host);
                    $states[] = match ($state) {
                        Availability::AVAILABLE   => [1],
                        Availability::UNAVAILABLE => [2],
                        Availability::MIXED       => [1, 2],
                        default                   => [0],
                    };
                }
            }

            if ($states !== []) {
                $state = Availability::typeState(array_merge(...$states));
                $totals[$state]++;
                $totals['total']++;
                $add('total', $state, $host);
            }
        }

        return [
            'kind'       => 'availability',
            'types'      => $types,
            'totals'     => $totals,
            // One interface type, or "Show only totals": coloured boxes; else a table.
            'boxes'      => $fields->int('only_totals') === 1 || \count($wanted) === 1,
            'single'     => \count($wanted) === 1 ? $wanted[0] : '',
            'vertical'   => $fields->int('layout') === 1,
            'lists'      => $lists,
        ];
    }

    /**
     * Item value, with the widget's settings: what to show (description, value, time, change
     * indicator, sparkline), positions, sizes (% of the widget height), decimals, units,
     * colours and thresholds.
     *
     * @return array<string, mixed>
     */
    private function itemValue(AccessScope $scope, WidgetFields $fields): array
    {
        $item = $this->guard->visibleItems($scope, $fields->list('itemid'))[0] ?? null;

        if ($item === null) {
            return ['kind' => 'empty'];
        }

        $show     = $fields->intList('show') ?: [1, 2, 3, 4];
        $last     = $item['lastvalue'] ?? '';
        $prev     = $item['prevvalue'] ?? '';
        $numeric  = ValueFormatter::isNumeric($item['value_type']) && is_numeric($last);
        $units    = $fields->int('units_show', 1) === 1 ? ($fields->has('units') ? $fields->get('units') : (string) $item['units']) : '';
        $decimals = max(0, min(10, $fields->int('decimal_places', 2)));
        $mapped   = ValueFormatter::mapValue($item, (string) $last);

        if ($mapped !== null) {
            [$number, $unit] = [$mapped, ''];
        } elseif ($numeric) {
            [$number, $unit] = ValueFormatter::numberParts((float) $last, $units, $decimals, (int) $item['value_type'] === 3);
        } else {
            [$number, $unit] = [ValueFormatter::format($item, $last), ''];
        }

        $colour = $numeric ? self::thresholdColour((float) $last, self::thresholds($fields, 'thresholds')) : null;

        return [
            'kind'        => 'value',
            'show'        => $show,
            'description' => self::macros($fields->get('description', '{ITEM.NAME}'), $item),
            'number'      => $number,
            'units'       => $unit,
            'unitsPos'    => $fields->int('units_pos', 2),
            'clock'       => (int) ($item['lastclock'] ?? 0),
            'trend'       => $numeric && is_numeric($prev) ? ((float) $last <=> (float) $prev) : 0,
            'bg'          => $colour ?? self::colour($fields->get('bg_color')),
            'desc'        => self::textStyle($fields, 'desc', 15, false, 1, 2),
            'value'       => self::textStyle($fields, 'value', 45, true, 1, 1) + ['decimalSize' => $fields->int('decimal_size', 35)],
            'unitStyle'   => self::textStyle($fields, 'units', 35, true, 1, 1),
            'time'        => self::textStyle($fields, 'time', 15, false, 1, 0),
            'up'          => self::colour($fields->get('up_color')) ?? '#3BC97D',
            'down'        => self::colour($fields->get('down_color')) ?? '#E45959',
            'updown'      => self::colour($fields->get('updown_color')) ?? '#4796C4',
            'sparkline'   => \in_array(5, $show, true) && $numeric ? [
                'points' => $this->history->series([$item], Period::fromZabbix($fields->get('sparkline.time_period.from', 'now-1h')))[0]['points'] ?? [],
                'color'  => self::colour($fields->get('sparkline.color')) ?? '#42A5F5',
                'width'  => max(0, min(10, $fields->int('sparkline.width', 1))),
                'fill'   => max(0, min(10, $fields->int('sparkline.fill', 3))),
            ] : null,
        ];
    }

    /**
     * Gauge, with the widget's settings: angle, min/max, what to show (description, value,
     * needle, scale, value arc), thresholds (colours, arc, labels), decimals, units and colours.
     *
     * @return array<string, mixed>
     */
    private function gauge(AccessScope $scope, WidgetFields $fields): array
    {
        $item = $this->guard->visibleItems($scope, $fields->list('itemid'))[0] ?? null;

        if ($item === null || !ValueFormatter::isNumeric($item['value_type']) || !is_numeric($item['lastvalue'] ?? null)) {
            return ['kind' => 'empty'];
        }

        $value      = (float) $item['lastvalue'];
        $units      = $fields->int('units_show', 1) === 1 ? ($fields->has('units') ? $fields->get('units') : (string) $item['units']) : '';
        $thresholds = self::thresholds($fields, 'thresholds');
        [$number, $unit] = ValueFormatter::numberParts($value, $units, max(0, min(10, $fields->int('decimal_places', 2))), (int) $item['value_type'] === 3);
        $scaleUnits = $fields->int('scale_show_units', 1) === 1 ? $units : '';
        $scaleDec   = max(0, min(10, $fields->int('scale_decimal_places', 0)));
        $label      = static fn (float $v): string => ValueFormatter::number($v, $scaleUnits, $scaleDec);
        $min        = $fields->float('min', 0.0);
        $max        = $fields->float('max', 100.0);

        return [
            'kind'        => 'gauge',
            'show'        => $fields->intList('show') ?: [1, 2, 4, 5],
            'angle'       => $fields->int('angle', 180) === 270 ? 270 : 180,
            'value'       => $value,
            'number'      => $number,
            'units'       => $unit,
            'min'         => $min,
            'max'         => $max > $min ? $max : $min + 100,
            'minLabel'    => $label($min),
            'maxLabel'    => $label($max > $min ? $max : $min + 100),
            'description' => self::macros($fields->get('description', '{ITEM.NAME}'), $item),
            'descTop'     => $fields->int('desc_v_pos', 1) === 0,
            'descSize'    => $fields->int('desc_size', 15),
            'valueSize'   => $fields->int('value_size', 25),
            'arcSize'     => $fields->int('value_arc_size', 20),
            'thresholds'  => array_map(static fn (array $t): array => $t + ['label' => $label($t['threshold'])], $thresholds),
            'thArc'       => $fields->int('th_show_arc') === 1,
            'thArcSize'   => $fields->int('th_arc_size', 5),
            'thLabels'    => $fields->int('th_show_labels') === 1,
            'arcColor'    => self::thresholdColour($value, $thresholds) ?? self::colour($fields->get('value_arc_color')),
            'emptyColor'  => self::colour($fields->get('empty_color')),
            'needleColor' => self::colour($fields->get('needle_color')),
            'bg'          => self::colour($fields->get('bg_color')),
        ];
    }

    /**
     * Clock settings for the browser: type, what to show, time zone, seconds, 12/24 hours.
     *
     * @return array<string, mixed>
     */
    public static function clock(WidgetFields $fields): array
    {
        $zone = $fields->get('tzone_timezone', 'local');

        return [
            'kind'     => 'clock',
            'digital'  => $fields->int('clock_type') === 1,
            'show'     => $fields->intList('show') ?: [2],
            'seconds'  => $fields->int('time_sec', 1) === 1,
            'hour12'   => $fields->int('time_format') === 1,
            // 'local' = the viewer's browser; 'system'/'default' = the portal's time zone.
            'timezone' => \in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : ($zone === 'local' ? 'local' : ''),
            'tzFull'   => $fields->int('tzone_format') === 1,
            'bg'       => self::colour($fields->get('bg_color')),
            'colors'   => [
                'date'  => self::colour($fields->get('date_color')),
                'time'  => self::colour($fields->get('time_color')),
                'tzone' => self::colour($fields->get('tzone_color')),
            ],
            'bold'     => [
                'date'  => $fields->int('date_bold') === 1,
                'time'  => $fields->int('time_bold') === 1,
                'tzone' => $fields->int('tzone_bold') === 1,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function itemHistory(AccessScope $scope, WidgetFields $fields, string $source): array
    {
        $ids = $source === 'columns'
            ? array_map(static fn (int $i): string => $fields->get('columns.' . $i . '.itemid'), $fields->indexes('columns'))
            : $fields->list('itemids');
        $items = $this->guard->visibleItems($scope, array_filter($ids));
        $limit = max(1, min(100, $fields->int('show_lines', 25)));
        $rows  = [];

        $byType = [];

        foreach ($items as $item) {
            $byType[(int) $item['value_type']][] = $item;
        }

        foreach ($byType as $type => $list) {
            $byId = [];

            foreach ($list as $item) {
                $byId[(string) $item['itemid']] = $item;
            }

            $history = $this->client->call('history.get', [
                'output'    => ['itemid', 'clock', 'value'],
                'history'   => $type,
                'itemids'   => array_keys($byId),
                'sortfield' => 'clock',
                'sortorder' => 'DESC',
                'limit'     => $limit,
            ]);

            foreach (\is_array($history) ? $history : [] as $row) {
                $item   = $byId[(string) $row['itemid']] ?? null;
                $rows[] = [
                    'clock' => (int) $row['clock'],
                    'host'  => (string) ($item['hosts'][0]['name'] ?? ''),
                    'name'  => (string) ($item['name'] ?? ''),
                    'value' => $item !== null ? ValueFormatter::format($item, $row['value']) : '',
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['clock'] <=> $a['clock']);

        return ['kind' => 'history', 'rows' => \array_slice($rows, 0, $limit)];
    }

    /** @return array<string, mixed> */
    private function chart(AccessScope $scope, WidgetFields $fields, Period $period): array
    {
        $series = [];
        $colour = 0;

        foreach ($this->dataSets($scope, $fields) as $set) {
            foreach ($this->history->series($set['items'], $period) as $i => $line) {
                $line['color'] = $set['colors'][$i] ?? $set['color'] ?? self::PALETTE[$colour % \count(self::PALETTE)];
                $series[]      = $line;
                $colour++;
            }
        }

        return ['kind' => 'chart', 'period' => $period->name, 'series' => \array_slice($series, 0, HistoryService::MAX_ITEMS)];
    }

    /** @return array<string, mixed> */
    private function classicGraph(AccessScope $scope, WidgetFields $fields, Period $period): array
    {
        $colors = [];

        if ($fields->int('source_type') === 1) {
            $itemIds = $fields->list('itemid');
        } else {
            $graphId = $fields->list('graphid')[0] ?? '';
            $groups  = $scope->groupFilter();
            $itemIds = [];

            if (ctype_digit($graphId) && $groups !== []) {
                $params = ['output' => ['graphid'], 'graphids' => [$graphId], 'selectGraphItems' => ['itemid', 'color']];

                if ($groups !== null) {
                    $params['groupids'] = $groups;
                }

                $graphs = $this->client->call('graph.get', $params);

                foreach ((array) (\is_array($graphs) ? ($graphs[0]['gitems'] ?? []) : []) as $gitem) {
                    $itemIds[] = (string) $gitem['itemid'];
                    $colour    = self::colour((string) ($gitem['color'] ?? ''));

                    if ($colour !== null) {
                        $colors[(string) $gitem['itemid']] = $colour;
                    }
                }
            }
        }

        $series = $this->history->series($this->guard->visibleItems($scope, $itemIds), $period);

        foreach ($series as $i => &$line) {
            $line['color'] = $colors[$line['itemid']] ?? self::PALETTE[$i % \count(self::PALETTE)];
        }

        return ['kind' => 'chart', 'period' => $period->name, 'series' => $series];
    }

    /** @return array<string, mixed> */
    private function pie(AccessScope $scope, WidgetFields $fields): array
    {
        $slices = [];
        $colour = 0;

        foreach ($this->dataSets($scope, $fields) as $set) {
            foreach ($set['items'] as $i => $item) {
                if (!ValueFormatter::isNumeric($item['value_type']) || !is_numeric($item['lastvalue'] ?? null)) {
                    continue;
                }

                $slices[] = [
                    'name'    => (string) ($item['hosts'][0]['name'] ?? '') . ': ' . (string) $item['name'],
                    'value'   => max(0.0, (float) $item['lastvalue']),
                    'display' => ValueFormatter::format($item, $item['lastvalue']),
                    'color'   => $set['colors'][$i] ?? $set['color'] ?? self::PALETTE[$colour % \count(self::PALETTE)],
                ];
                $colour++;
            }
        }

        return ['kind' => 'pie', 'slices' => $slices, 'doughnut' => $fields->int('draw_type') === 1];
    }

    /**
     * Top hosts, as Zabbix's widget: one row per monitored host (hosts in maintenance only when
     * the widget asks for them), columns of item values (formatted with the column's decimal
     * places), host names or text; values shown as is, as a bar or as indicators, coloured by the
     * column's thresholds or base colour; sorted by the "Order by" column, top or bottom N.
     *
     * @return array<string, mixed>
     */
    private function topHosts(AccessScope $scope, WidgetFields $fields): array
    {
        $hosts = array_values(array_filter(
            $this->hosts->hosts($scope, $fields->list('groupids')),
            static fn (array $h): bool => $h['enabled'] && ($fields->int('maintenance') === 1 || !$h['maintenance'])
        ));

        if ($fields->has('hostids.0')) {
            $only  = array_flip($this->hostFilter($scope, $fields));
            $hosts = array_values(array_filter($hosts, static fn (array $h): bool => isset($only[$h['hostid']])));
        }

        $hosts   = \array_slice($hosts, 0, 1000);
        $hostIds = array_column($hosts, 'hostid');
        $columns = [];
        $values  = [];

        foreach ($fields->indexes('columns') as $i) {
            $p    = 'columns.' . $i . '.';
            $data = $fields->int($p . 'data', 1);
            $col  = [
                'name'     => $fields->get($p . 'name'),
                'data'     => $data,
                'display'  => \in_array($fields->int($p . 'display', 1), [2, 3], true) ? $fields->int($p . 'display', 1) : 1,
                'decimals' => max(0, min(10, $fields->int($p . 'decimal_places', 2))),
                'base'     => self::colour($fields->get($p . 'base_color')),
                'min'      => $fields->get($p . 'min'),
                'max'      => $fields->get($p . 'max'),
                // Zabbix stores a column's thresholds as columnsthresholds.<column>.color.<n>.
                'thresholds' => self::thresholds($fields, 'columnsthresholds.' . $i, true),
            ];

            if ($data === 1 && $hostIds !== []) {
                $itemName    = $fields->get($p . 'item');
                $col['name'] = $col['name'] !== '' ? $col['name'] : $itemName;
                $rows        = $itemName === '' ? [] : $this->client->call('item.get', [
                    'output'         => ['itemid', 'hostid', 'name', 'lastvalue', 'units', 'value_type'],
                    'hostids'        => $hostIds,
                    'filter'         => ['name' => $itemName],
                    'monitored'      => true,
                    'webitems'       => true,
                    'selectValueMap' => ['mappings'],
                ]);

                foreach (\is_array($rows) ? $rows : [] as $row) {
                    $numeric = ValueFormatter::isNumeric($row['value_type'] ?? 4) && is_numeric($row['lastvalue'] ?? null);

                    $values[$i][(string) $row['hostid']] = [
                        'raw'     => $numeric ? (float) $row['lastvalue'] : null,
                        'display' => ValueFormatter::format($row, $row['lastvalue'] ?? '', $col['decimals']),
                    ];
                }
            } elseif ($data === 3) {
                $col['text'] = $fields->get($p . 'text');
            }

            $columns[$i] = $col;
        }

        if ($columns === []) {
            $columns = [0 => ['name' => 'Host', 'data' => 2, 'display' => 1, 'base' => null, 'thresholds' => []]];
        }

        // Bars: min/max of the column, or of its values when not set.
        foreach ($columns as $i => &$col) {
            if ($col['data'] === 1 && $col['display'] !== 1) {
                $raw         = array_filter(array_column($values[$i] ?? [], 'raw'), 'is_float');
                $col['min']  = is_numeric($col['min']) ? (float) $col['min'] : min(0.0, $raw === [] ? 0.0 : min($raw));
                $col['max']  = is_numeric($col['max']) ? (float) $col['max'] : ($raw === [] ? 100.0 : max($raw));
                $col['max']  = $col['max'] > $col['min'] ? $col['max'] : $col['min'] + 1;
            }
        }

        unset($col);

        $order = $fields->int('column', array_key_first($columns));
        $table = [];

        foreach ($hosts as $host) {
            $cells = [];
            $sort  = null;

            foreach ($columns as $i => $col) {
                $cell = match ($col['data']) {
                    2       => ['display' => $host['name'], 'raw' => null, 'hostid' => $host['hostid']],
                    3       => ['display' => (string) ($col['text'] ?? ''), 'raw' => null],
                    default => $values[$i][$host['hostid']] ?? ['display' => '', 'raw' => null],
                };

                $colour = $cell['raw'] !== null ? self::thresholdColour($cell['raw'], $col['thresholds']) : null;
                $colour ??= $col['base'];

                if ($col['data'] === 1 && $col['display'] !== 1 && $cell['raw'] !== null) {
                    $cell['bar'] = [
                        'value'      => $cell['raw'],
                        'min'        => $col['min'],
                        'max'        => $col['max'],
                        'indicators' => $col['display'] === 3,
                        'fill'       => $colour ?? '#97AAB3',
                        'thresholds' => $col['thresholds'],
                    ];
                } elseif ($colour !== null && $cell['display'] !== '') {
                    $cell['bg'] = $colour;
                }

                if ($i === $order) {
                    $sort = $cell['raw'] ?? $cell['display'];
                }

                $cells[] = $cell;
            }

            $table[] = ['sort' => $sort, 'cells' => $cells];
        }

        $desc = $fields->int('order', 2) !== 3;
        usort($table, static function (array $a, array $b) use ($desc): int {
            // Hosts without a value come last, whatever the order.
            if ($a['sort'] === null || $a['sort'] === '' || $b['sort'] === null || $b['sort'] === '') {
                return ($a['sort'] === null || $a['sort'] === '') <=> ($b['sort'] === null || $b['sort'] === '');
            }

            $cmp = (\is_float($a['sort']) && \is_float($b['sort'])) ? $a['sort'] <=> $b['sort'] : strnatcasecmp((string) $a['sort'], (string) $b['sort']);

            return $desc ? -$cmp : $cmp;
        });

        return [
            'kind'    => 'table',
            'columns' => array_values(array_map(static fn (array $c): array => [
                'name' => $c['name'] !== '' ? $c['name'] : ($c['data'] === 2 ? 'Host' : ''),
                'bar'  => $c['data'] === 1 && $c['display'] !== 1,
            ], $columns)),
            'rows'    => array_map(static fn (array $r): array => $r['cells'], \array_slice($table, 0, max(1, min(100, $fields->int('show_lines', 10))))),
        ];
    }

    /**
     * System information (System stats), from what the API can tell: frontend/API version and
     * the numbers of hosts, templates, items, triggers and users. "Zabbix server is running",
     * the server version and the required performance come from the Zabbix server itself, which
     * the API does not report; they are left out. Hosts, items and triggers are counted in the
     * host groups the user may read; templates and users only for Super admins.
     *
     * @return array<string, mixed>
     */
    private function systemInfo(AccessScope $scope, WidgetFields $fields): array
    {
        if ($fields->int('info_type') === 1) {
            return ['kind' => 'unsupported'];
        }

        $groups = $scope->groupFilter();

        if ($groups === []) {
            return ['kind' => 'systeminfo', 'rows' => []];
        }

        $count = function (string $method, array $params) use ($groups): int {
            $params += ['countOutput' => true];

            if ($groups !== null) {
                $params['groupids'] = $groups;
            }

            return (int) $this->client->call($method, $params);
        };

        $rows = [['name' => 'version', 'value' => $this->client->version()]];

        $rows[] = ['name' => 'hosts', 'value' => [
            $count('host.get', ['filter' => ['status' => 0]]),
            $count('host.get', ['filter' => ['status' => 1]]),
        ]];

        if ($scope->all()) {
            $rows[] = ['name' => 'templates', 'value' => (int) $this->client->call('template.get', ['countOutput' => true])];
        }

        $items  = ['templated' => false, 'webitems' => true];
        $rows[] = ['name' => 'items', 'value' => [
            $count('item.get', $items + ['filter' => ['status' => 0, 'state' => 0]]),
            $count('item.get', $items + ['filter' => ['status' => 1]]),
            $count('item.get', $items + ['filter' => ['status' => 0, 'state' => 1]]),
        ]];

        $triggers = ['templated' => false];
        $rows[]   = ['name' => 'triggers', 'value' => [
            $count('trigger.get', $triggers + ['filter' => ['status' => 0]]),
            $count('trigger.get', $triggers + ['filter' => ['status' => 1]]),
            $count('trigger.get', $triggers + ['filter' => ['status' => 0, 'value' => 1]]),
            $count('trigger.get', $triggers + ['filter' => ['status' => 0, 'value' => 0]]),
        ]];

        if ($scope->all()) {
            $rows[] = ['name' => 'users', 'value' => (int) $this->client->call('user.get', ['countOutput' => true])];
        }

        return ['kind' => 'systeminfo', 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private function hostList(AccessScope $scope, WidgetFields $fields): array
    {
        $hosts = $this->hosts->hosts($scope, $fields->list('groupids'));

        if ($fields->has('hostids.0')) {
            $only  = array_flip($this->hostFilter($scope, $fields));
            $hosts = array_values(array_filter($hosts, static fn (array $h): bool => isset($only[$h['hostid']])));
        }

        $hosts = \array_slice($hosts, 0, max(1, min(500, $fields->int('show_lines', 100))));

        return ['kind' => 'hosts', 'rows' => array_map(static fn (array $h): array => [
            'hostid'       => $h['hostid'],
            'name'         => $h['name'],
            'availability' => $h['availability'],
            'maintenance'  => $h['maintenance'],
            'problems'     => $h['problems'],
            'worst'        => $h['worst'],
        ], $hosts)];
    }

    /**
     * Thresholds of a widget, sorted by value: "<prefix>.<n>.color" / "<prefix>.<n>.threshold"
     * (Item value, Gauge), or "<prefix>.color.<n>" / "<prefix>.threshold.<n>" (Top hosts columns).
     *
     * @return list<array{threshold: float, color: string}>
     */
    private static function thresholds(WidgetFields $fields, string $prefix, bool $indexLast = false): array
    {
        $list = [];

        foreach (range(0, 49) as $n) {
            [$c, $t] = $indexLast
                ? [$prefix . '.color.' . $n, $prefix . '.threshold.' . $n]
                : [$prefix . '.' . $n . '.color', $prefix . '.' . $n . '.threshold'];

            if (!$fields->has($t)) {
                continue;
            }

            $colour    = self::colour($fields->get($c));
            $threshold = self::numeric($fields->get($t));

            if ($colour !== null && $threshold !== null) {
                $list[] = ['threshold' => $threshold, 'color' => $colour];
            }
        }

        usort($list, static fn (array $a, array $b): int => $a['threshold'] <=> $b['threshold']);

        return $list;
    }

    /**
     * The colour of the highest threshold the value reaches, as Zabbix does, or null.
     *
     * @param list<array{threshold: float, color: string}> $thresholds
     */
    public static function thresholdColour(float $value, array $thresholds): ?string
    {
        $colour = null;

        foreach ($thresholds as $threshold) {
            if ($value < $threshold['threshold']) {
                break;
            }

            $colour = $threshold['color'];
        }

        return $colour;
    }

    /** A threshold value: a number, optionally with a K/M/G/T suffix (as Zabbix allows). */
    private static function numeric(string $value): ?float
    {
        $value = trim($value);

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(-?\d+(?:\.\d+)?)([KMGT])$/', $value, $m)) {
            return (float) $m[1] * 1000 ** (strpos('KMGT', $m[2]) + 1);
        }

        return null;
    }

    /**
     * The common macros of a widget description; anything else stays as written.
     *
     * @param array<string, mixed> $item
     */
    public static function macros(string $text, array $item): string
    {
        $host = \is_array($item['hosts'][0] ?? null) ? (string) ($item['hosts'][0]['name'] ?? '') : '';

        return strtr($text, [
            '{ITEM.NAME}'      => (string) ($item['name'] ?? ''),
            '{ITEM.NAME1}'     => (string) ($item['name'] ?? ''),
            '{ITEM.KEY}'       => (string) ($item['key_'] ?? ''),
            '{ITEM.LASTVALUE}' => ValueFormatter::format($item, $item['lastvalue'] ?? ''),
            '{ITEM.VALUE}'     => ValueFormatter::format($item, $item['lastvalue'] ?? ''),
            '{HOST.NAME}'      => $host,
            '{HOST.NAME1}'     => $host,
            '{HOST.HOST}'      => $host,
        ]);
    }

    /**
     * Position, size (% of the widget height), weight and colour of one part of an Item value
     * widget (desc, value, units, time).
     *
     * @return array<string, mixed>
     */
    private static function textStyle(WidgetFields $fields, string $part, int $size, bool $bold, int $h, int $v): array
    {
        return [
            'h'     => max(0, min(2, $fields->int($part . '_h_pos', $h))),
            'v'     => max(0, min(2, $fields->int($part . '_v_pos', $v))),
            'size'  => max(1, min(100, $fields->int($part . '_size', $size))),
            'bold'  => $fields->int($part . '_bold', $bold ? 1 : 0) === 1,
            'color' => self::colour($fields->get($part . '_color')),
        ];
    }

    /**
     * A colour from a widget's settings as "#RRGGBB", or null when it is not a hex colour.
     * Widget settings are written by Zabbix users; the value goes into style and SVG attributes
     * in the browser, so nothing but a hex colour is passed on.
     */
    public static function colour(string $value): ?string
    {
        $value = ltrim(trim($value), '#');

        return preg_match('/^[0-9A-Fa-f]{6}$/', $value) ? '#' . strtoupper($value) : null;
    }

    /**
     * The data sets of a Graph or Pie chart widget: item lists (ds.N.itemids.M) or host and item
     * name patterns (ds.N.hosts.M, ds.N.items.M, "*" as wildcard).
     *
     * @return list<array{items: list<array<string, mixed>>, color?: string, colors: array<int, string>}>
     */
    private function dataSets(AccessScope $scope, WidgetFields $fields): array
    {
        $sets = [];

        foreach ($fields->indexes('ds') as $n) {
            $prefix = 'ds.' . $n;
            $colors = [];

            if ($fields->int($prefix . '.dataset_type', 1) === 0) {
                $ids   = $fields->list($prefix . '.itemids');
                $items = $this->guard->visibleItems($scope, $ids);
                $order = array_flip($ids);
                usort($items, static fn ($a, $b): int => ($order[(string) $a['itemid']] ?? 0) <=> ($order[(string) $b['itemid']] ?? 0));

                foreach ($items as $i => $item) {
                    $colour = $fields->get($prefix . '.color.' . ($order[(string) $item['itemid']] ?? $i));

                    if (self::colour($colour) !== null) {
                        $colors[$i] = self::colour($colour);
                    }
                }
            } else {
                $items = $this->patternItems($scope, $fields->list($prefix . '.hosts'), $fields->list($prefix . '.items'));
            }

            $set    = ['items' => $items, 'colors' => $colors];
            $colour = $fields->get($prefix . '.color');

            if (self::colour($colour) !== null) {
                $set['color'] = self::colour($colour);
            }

            $sets[] = $set;
        }

        return $sets;
    }

    /**
     * @param  list<string>  $hostPatterns
     * @param  list<string>  $itemPatterns
     * @return list<array<string, mixed>>
     */
    private function patternItems(AccessScope $scope, array $hostPatterns, array $itemPatterns): array
    {
        $groups = $scope->groupFilter();

        if ($hostPatterns === [] || $itemPatterns === [] || $groups === []) {
            return [];
        }

        $params = [
            'output'                 => ['hostid'],
            'search'                 => ['name' => $hostPatterns],
            'searchWildcardsEnabled' => true,
            'searchByAny'            => true,
            'limit'                  => 200,
        ];

        if ($groups !== null) {
            $params['groupids'] = $groups;
        }

        $hosts   = $this->client->call('host.get', $params);
        $hostIds = $this->guard->visibleHostIds($scope, array_map(static fn ($h): string => (string) $h['hostid'], \is_array($hosts) ? $hosts : []));

        if ($hostIds === []) {
            return [];
        }

        $items = $this->client->call('item.get', [
            'output'                 => ['itemid'],
            'hostids'                => $hostIds,
            'search'                 => ['name' => $itemPatterns],
            'searchWildcardsEnabled' => true,
            'searchByAny'            => true,
            'monitored'              => true,
            'filter'                 => ['value_type' => [0, 3]],
            'limit'                  => self::MAX_PATTERN_ITEMS,
        ]);

        return $this->guard->visibleItems($scope, array_map(static fn ($i): string => (string) $i['itemid'], \is_array($items) ? $items : []));
    }

    /**
     * The widget's hosts that the user may see ([] when the widget names none).
     *
     * @return list<string>
     */
    private function hostFilter(AccessScope $scope, WidgetFields $fields): array
    {
        $ids = $fields->list('hostids');

        return $ids === [] ? [] : $this->guard->visibleHostIds($scope, $ids);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>                $exclude
     * @return list<array<string, mixed>>
     */
    private function excludeGroups(array $rows, array $exclude): array
    {
        if ($exclude === []) {
            return $rows;
        }

        return array_values(array_filter($rows, static fn (array $p): bool => array_intersect(array_column($p['groups'], 'groupid'), $exclude) === []));
    }
}
