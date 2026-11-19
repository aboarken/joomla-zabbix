<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Monitoring;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Item values as the Zabbix 7.4 front end shows them (convertUnits() and friends). Pure.
 *
 * - Units follow the number after a space ("58.19 %", "42.00 °C").
 * - K/M/G/T/P prefixes, with 1024 for B and Bps; no prefix for %, ms, rpm, RPM, and for units
 *   that start with "!" (the "!" is not shown).
 * - "uptime": "64 days, 01:54:51"; "s": the 3 largest units ("1h 38m 51s"); "unixtime": a date.
 * - Widgets pass their "Decimal places" and keep trailing zeros ("1.00"); elsewhere Zabbix shows
 *   up to 4 decimals without trailing zeros (2 with a prefix).
 * - Value mappings: "available (1.00)".
 */
final class ValueFormatter
{
    private const PREFIXES = ['', 'K', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];

    /** value_type: 0 float, 1 character, 2 log, 3 unsigned, 4 text, 5 binary. */
    public static function isNumeric(int|string $valueType): bool
    {
        return \in_array((int) $valueType, [0, 3], true);
    }

    /**
     * @param array<string, mixed> $item      item.get row (value_type, units, valuemap)
     * @param int|null             $decimals  widget "Decimal places" (shown exactly), or null
     * @param string|null          $units     units to use instead of the item's ('' = none)
     */
    public static function format(array $item, mixed $value, ?int $decimals = null, ?string $units = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value   = (string) $value;
        $numeric = self::isNumeric($item['value_type'] ?? 4) && is_numeric($value);

        if (!$numeric) {
            $mapped = self::mapValue($item, $value);

            if ($mapped !== null) {
                return $mapped . ' (' . $value . ')';
            }

            return mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
        }

        // Unsigned integers have no decimals unless a prefix makes them fractional.
        $integer   = (int) ($item['value_type'] ?? 0) === 3;
        $formatted = self::number((float) $value, $units ?? (string) ($item['units'] ?? ''), $decimals, $integer);
        $mapped    = self::mapValue($item, $value);

        return $mapped !== null ? $mapped . ' (' . $formatted . ')' : $formatted;
    }

    /**
     * A number with units, as Zabbix's convertUnits().
     *
     * @param int|null $decimals  exact decimals (widgets), or null for Zabbix's default (4, or 2 with a prefix)
     */
    public static function number(float $value, string $units, ?int $decimals = null, bool $integer = false): string
    {
        [$number, $unit] = self::numberParts($value, $units, $decimals, $integer);

        return $unit !== '' ? $number . ' ' . $unit : $number;
    }

    /**
     * The number and the units separately (for widgets that draw them apart).
     *
     * @return array{0: string, 1: string}
     */
    public static function numberParts(float $value, string $units, ?int $decimals = null, bool $integer = false): array
    {
        $units = trim($units);

        if ($units === 'unixtime') {
            return [gmdate('Y-m-d H:i:s', (int) $value), ''];
        }

        if ($units === 'uptime') {
            return [self::uptime($value), ''];
        }

        if ($units === 's') {
            return [self::duration($value), ''];
        }

        $noPrefix = ['%', 'ms', 'rpm', 'RPM'];

        if ($units !== '' && $units[0] === '!') {
            $units      = substr($units, 1);
            $noPrefix[] = $units;
        }

        if ($units === '' || \in_array($units, $noPrefix, true) || abs($value) < 1) {
            return [self::float($value, $integer ? 0 : ($decimals ?? 4), $decimals !== null), $units];
        }

        $base   = \in_array($units, ['B', 'Bps'], true) ? 1024 : 1000;
        $power  = 0;
        $scaled = $value;

        while (abs($scaled) >= $base && $power < \count(self::PREFIXES) - 1) {
            $scaled /= $base;
            $power++;
        }

        $places = $decimals ?? ($power === 0 ? 4 : 2);

        if ($integer && $power === 0) {
            $places = 0;
        }

        return [self::float($scaled, $places, $decimals !== null), ($scaled == 0 ? '' : self::PREFIXES[$power]) . $units];
    }

    /** "64 days, 01:54:51" (Zabbix's convertUnitsUptime()). */
    public static function uptime(float $seconds): string
    {
        $seconds = (int) round($seconds);
        $sign    = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);
        $days    = intdiv($seconds, 86400);
        $rest    = $seconds - $days * 86400;
        $time    = \sprintf('%02d:%02d:%02d', intdiv($rest, 3600), intdiv($rest % 3600, 60), $rest % 60);

        if ($days === 0) {
            return $sign . $time;
        }

        return $sign . $days . ($days === 1 ? ' day, ' : ' days, ') . $time;
    }

    /**
     * The 3 largest units, empty ones left out: "1y 2M 3d", "81d 3h 42m", "1h 38m 51s", "5s 120ms"
     * (Zabbix's convertUnitsS()).
     */
    public static function duration(float $seconds): string
    {
        $sign  = $seconds < 0 ? '-' : '';
        $abs   = abs($seconds);
        $whole = (int) floor($abs);
        $units = ['y' => 31536000, 'M' => 2592000, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $parts = [];
        $first = null;
        $level = 0;

        foreach ($units as $unit => $length) {
            $count = intdiv($whole, $length);

            if ($count > 0) {
                $parts[] = $count . $unit;
                $whole  -= $count * $length;
                $first ??= $level;
            }

            if ($first !== null && $level - $first >= 2) {
                break;
            }

            $level++;
        }

        // Milliseconds when the period is short (the largest unit is minutes or smaller).
        if ($first === null || $first >= 4) {
            $ms = (int) round(fmod($abs, 1) * 1000);

            if ($ms > 0) {
                $parts[] = $ms . 'ms';
            }
        }

        return $parts === [] ? '0' : $sign . implode(' ', $parts);
    }

    /**
     * @param bool $exact keep trailing zeros (widgets with "Decimal places")
     */
    public static function float(float $value, int $decimals, bool $exact = false): string
    {
        $text = number_format($value, max(0, $decimals), '.', '');

        if (!$exact && str_contains($text, '.')) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }

    /**
     * The value mapping's text for this value, or null.
     *
     * @param array<string, mixed> $item
     */
    public static function mapValue(array $item, string $value): ?string
    {
        $map = $item['valuemap'] ?? null;

        if (!\is_array($map)) {
            return null;
        }

        foreach ((array) ($map['mappings'] ?? []) as $mapping) {
            // type 0 = equals (the common case); other mapping types are not applied.
            if (\is_array($mapping) && (int) ($mapping['type'] ?? 0) === 0) {
                $from = (string) ($mapping['value'] ?? '');

                if ($from === $value || (is_numeric($from) && is_numeric($value) && (float) $from === (float) $value)) {
                    return (string) ($mapping['newvalue'] ?? '');
                }
            }
        }

        return null;
    }
}
