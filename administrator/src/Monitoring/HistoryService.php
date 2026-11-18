<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Monitoring;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;

/**
 * Time series of numeric items for the charts (drawn in the browser: the Zabbix API has no graph
 * images). Periods up to two days read history.get, longer ones trend.get (hourly averages).
 * Each series is reduced to at most MAX_POINTS points.
 *
 * Items must already be checked with AccessGuard::visibleItems().
 */
final class HistoryService
{
    public const MAX_POINTS = 300;

    public const MAX_ITEMS = 20;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(private readonly JsonRpcClient $client, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param  list<array<string, mixed>>  $items  item.get rows (itemid, name, value_type, units, hosts)
     *
     * @return list<array{itemid: string, name: string, host: string, units: string, points: list<array{0: int, 1: float}>}>
     */
    public function series(array $items, Period $period): array
    {
        $items = \array_slice(array_values(array_filter($items, static fn ($i): bool => ValueFormatter::isNumeric($i['value_type'] ?? 4))), 0, self::MAX_ITEMS);

        if ($items === []) {
            return [];
        }

        $till   = ($this->clock)();
        $from   = $till - $period->seconds();
        $points = [];

        if ($period->usesTrends()) {
            $rows = $this->client->call('trend.get', [
                'output'    => ['itemid', 'clock', 'value_avg'],
                'itemids'   => array_map(static fn ($i): string => (string) $i['itemid'], $items),
                'time_from' => $from,
                'time_till' => $till,
            ]);

            foreach (\is_array($rows) ? $rows : [] as $row) {
                $points[(string) $row['itemid']][] = [(int) $row['clock'], (float) $row['value_avg']];
            }
        } else {
            $byType = [];

            foreach ($items as $item) {
                $byType[(int) $item['value_type']][] = (string) $item['itemid'];
            }

            foreach ($byType as $type => $ids) {
                $rows = $this->client->call('history.get', [
                    'output'    => ['itemid', 'clock', 'value'],
                    'history'   => $type,
                    'itemids'   => $ids,
                    'time_from' => $from,
                    'time_till' => $till,
                    'sortfield' => 'clock',
                    'sortorder' => 'ASC',
                    'limit'     => 100000,
                ]);

                foreach (\is_array($rows) ? $rows : [] as $row) {
                    $points[(string) $row['itemid']][] = [(int) $row['clock'], (float) $row['value']];
                }
            }
        }

        $series = [];

        foreach ($items as $item) {
            $list = $points[(string) $item['itemid']] ?? [];
            usort($list, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $host = \is_array($item['hosts'][0] ?? null) ? (string) ($item['hosts'][0]['name'] ?? '') : '';

            $series[] = [
                'itemid' => (string) $item['itemid'],
                'name'   => (string) ($item['name'] ?? ''),
                'host'   => $host,
                'units'  => (string) ($item['units'] ?? ''),
                'points' => self::downsample($list, $from, $till),
            ];
        }

        return $series;
    }

    /**
     * Average the points into at most MAX_POINTS equal time buckets.
     *
     * @param  list<array{0: int, 1: float}>  $points  sorted by time
     * @return list<array{0: int, 1: float}>
     */
    public static function downsample(array $points, int $from, int $till, int $max = self::MAX_POINTS): array
    {
        if (\count($points) <= $max || $till <= $from) {
            return $points;
        }

        $width   = ($till - $from) / $max;
        $buckets = [];

        foreach ($points as [$clock, $value]) {
            $i = min($max - 1, max(0, (int) floor(($clock - $from) / $width)));
            $buckets[$i][] = [$clock, $value];
        }

        $out = [];

        foreach ($buckets as $bucket) {
            $out[] = [
                (int) round(array_sum(array_column($bucket, 0)) / \count($bucket)),
                array_sum(array_column($bucket, 1)) / \count($bucket),
            ];
        }

        return $out;
    }
}
