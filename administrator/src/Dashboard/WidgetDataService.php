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
            'clock'                  => ['kind' => 'clock', 'digital' => $fields->int('clock_type') === 1],
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
            $fields->int('show_suppressed') === 1
        );

        $rows = $this->excludeGroups($rows, $fields->list('exclude_groupids'));

        return ['kind' => 'problems', 'rows' => \array_slice($rows, 0, $limit), 'total' => \count($rows)];
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
            'severities' => $shown !== [] ? $shown : ProblemService::SEVERITIES,
        ];
    }

    /** @return array<string, mixed> */
    private function availability(AccessScope $scope, WidgetFields $fields): array
    {
        $hosts = $this->hosts->hosts($scope, $fields->list('groupids'));

        if ($fields->int('maintenance') !== 1) {
            $hosts = array_values(array_filter($hosts, static fn (array $h): bool => !$h['maintenance']));
        }

        $hosts = array_values(array_filter($hosts, static fn (array $h): bool => $h['enabled']));
        $want  = array_map(static fn (int $t): string => Availability::TYPES[$t] ?? '', $fields->intList('interface_type'));
        $want  = array_filter($want) ?: array_values(Availability::TYPES);
        $types = [];

        foreach ($want as $type) {
            $types[$type] = [Availability::AVAILABLE => 0, Availability::UNAVAILABLE => 0, Availability::UNKNOWN => 0, 'total' => 0];
        }

        $totals = [Availability::AVAILABLE => 0, Availability::UNAVAILABLE => 0, Availability::UNKNOWN => 0, Availability::MIXED => 0, 'total' => 0];

        foreach ($hosts as $host) {
            foreach ($host['interfaces'] as $type => $state) {
                if (isset($types[$type])) {
                    $types[$type][$state]++;
                    $types[$type]['total']++;
                }
            }

            if ($host['availability'] !== Availability::NONE) {
                $totals[$host['availability']]++;
                $totals['total']++;
            }
        }

        return ['kind' => 'availability', 'types' => $types, 'totals' => $totals, 'onlyTotals' => $fields->int('only_totals') === 1];
    }

    /** @return array<string, mixed> */
    private function itemValue(AccessScope $scope, WidgetFields $fields): array
    {
        $item = $this->guard->visibleItems($scope, $fields->list('itemid'))[0] ?? null;

        if ($item === null) {
            return ['kind' => 'empty'];
        }

        $last = $item['lastvalue'] ?? '';
        $prev = $item['prevvalue'] ?? '';
        $trend = ValueFormatter::isNumeric($item['value_type']) && is_numeric($last) && is_numeric($prev)
            ? ((float) $last <=> (float) $prev)
            : 0;

        return [
            'kind'  => 'value',
            'name'  => (string) $item['name'],
            'host'  => (string) ($item['hosts'][0]['name'] ?? ''),
            'value' => ValueFormatter::format($item, $last),
            'clock' => (int) ($item['lastclock'] ?? 0),
            'trend' => $trend,
        ];
    }

    /** @return array<string, mixed> */
    private function gauge(AccessScope $scope, WidgetFields $fields): array
    {
        $item = $this->guard->visibleItems($scope, $fields->list('itemid'))[0] ?? null;

        if ($item === null || !ValueFormatter::isNumeric($item['value_type'])) {
            return ['kind' => 'empty'];
        }

        $value = (float) ($item['lastvalue'] ?? 0);

        return [
            'kind'    => 'gauge',
            'name'    => (string) $item['name'],
            'host'    => (string) ($item['hosts'][0]['name'] ?? ''),
            'value'   => $value,
            'display' => ValueFormatter::format($item, $item['lastvalue'] ?? ''),
            'min'     => $fields->float('min', 0.0),
            'max'     => $fields->float('max', 100.0),
            'clock'   => (int) ($item['lastclock'] ?? 0),
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

    /** @return array<string, mixed> */
    private function topHosts(AccessScope $scope, WidgetFields $fields): array
    {
        $hosts = $this->hosts->hosts($scope, $fields->list('groupids'));

        if ($fields->has('hostids.0')) {
            $only  = array_flip($this->hostFilter($scope, $fields));
            $hosts = array_values(array_filter($hosts, static fn (array $h): bool => isset($only[$h['hostid']])));
        }

        $hosts   = \array_slice($hosts, 0, 1000);
        $hostIds = array_column($hosts, 'hostid');
        $columns = [];
        $values  = [];

        foreach ($fields->indexes('columns') as $i) {
            $data = $fields->int('columns.' . $i . '.data', 1);
            $name = $fields->get('columns.' . $i . '.name');
            $col  = ['name' => $name, 'data' => $data];

            if ($data === 1 && $hostIds !== []) {
                $itemName = $fields->get('columns.' . $i . '.item');
                $col['name'] = $name !== '' ? $name : $itemName;
                $rows = $itemName === '' ? [] : $this->client->call('item.get', [
                    'output'         => ['itemid', 'hostid', 'name', 'lastvalue', 'units', 'value_type'],
                    'hostids'        => $hostIds,
                    'filter'         => ['name' => $itemName],
                    'monitored'      => true,
                    'selectValueMap' => ['mappings'],
                ]);

                foreach (\is_array($rows) ? $rows : [] as $row) {
                    $values[$i][(string) $row['hostid']] = [
                        'raw'     => is_numeric($row['lastvalue'] ?? null) ? (float) $row['lastvalue'] : null,
                        'display' => ValueFormatter::format($row, $row['lastvalue'] ?? ''),
                    ];
                }
            } elseif ($data === 3) {
                $col['text'] = $fields->get('columns.' . $i . '.text');
            }

            $columns[$i] = $col;
        }

        if ($columns === []) {
            $columns = [0 => ['name' => 'Host', 'data' => 2]];
        }

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

                if ($i === $fields->int('column')) {
                    $sort = $cell['raw'] ?? $cell['display'];
                }

                $cells[] = $cell;
            }

            $table[] = ['sort' => $sort, 'cells' => $cells];
        }

        $desc = $fields->int('order', 2) !== 3;
        usort($table, static function (array $a, array $b) use ($desc): int {
            $cmp = (\is_float($a['sort']) && \is_float($b['sort'])) ? $a['sort'] <=> $b['sort'] : strnatcasecmp((string) $a['sort'], (string) $b['sort']);

            return $desc ? -$cmp : $cmp;
        });

        return [
            'kind'    => 'table',
            'columns' => array_values(array_map(static fn (array $c): string => $c['name'] !== '' ? $c['name'] : ($c['data'] === 2 ? 'Host' : ''), $columns)),
            'rows'    => array_map(static fn (array $r): array => $r['cells'], \array_slice($table, 0, max(1, min(100, $fields->int('show_lines', 10))))),
        ];
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
