<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Monitoring;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Item values as Zabbix shows them: value mappings, units with K/M/G prefixes (B and Bps with
 * 1024), uptime/s as durations. Pure.
 */
final class ValueFormatter
{
    /** value_type: 0 float, 1 character, 2 log, 3 unsigned, 4 text, 5 binary. */
    public static function isNumeric(int|string $valueType): bool
    {
        return \in_array((int) $valueType, [0, 3], true);
    }

    /**
     * @param array<string, mixed> $item item.get row (value_type, units, valuemap)
     */
    public static function format(array $item, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value  = (string) $value;
        $mapped = self::mapValue($item, $value);

        if ($mapped !== null) {
            return $mapped . ' (' . $value . ')';
        }

        if (!self::isNumeric($item['value_type'] ?? 4) || !is_numeric($value)) {
            return mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
        }

        return self::number((float) $value, (string) ($item['units'] ?? ''));
    }

    public static function number(float $value, string $units): string
    {
        $units = trim($units);

        if ($units === 'unixtime') {
            return gmdate('Y-m-d H:i:s', (int) $value);
        }

        if ($units === 'uptime' || $units === 's') {
            return self::duration($value);
        }

        if (\in_array($units, ['%', 'rpm', 'RPM', 'ms'], true) || $units === '') {
            return self::trim($value) . ($units !== '' ? ($units === '%' ? '%' : ' ' . $units) : '');
        }

        $base     = \in_array($units, ['B', 'Bps'], true) ? 1024 : 1000;
        $prefixes = ['', 'K', 'M', 'G', 'T', 'P'];
        $i        = 0;

        while (abs($value) >= $base && $i < \count($prefixes) - 1) {
            $value /= $base;
            $i++;
        }

        return self::trim($value) . ' ' . $prefixes[$i] . $units;
    }

    public static function duration(float $seconds): string
    {
        $seconds = (int) round($seconds);
        $parts   = [];

        foreach (['d' => 86400, 'h' => 3600, 'm' => 60] as $unit => $length) {
            if ($seconds >= $length) {
                $parts[] = intdiv($seconds, $length) . $unit;
                $seconds %= $length;
            }

            if (\count($parts) === 2) {
                return implode(' ', $parts);
            }
        }

        if ($parts === [] || $seconds > 0) {
            $parts[] = $seconds . 's';
        }

        return implode(' ', \array_slice($parts, 0, 2));
    }

    /** @param array<string, mixed> $item */
    private static function mapValue(array $item, string $value): ?string
    {
        $map = $item['valuemap'] ?? null;

        if (!\is_array($map)) {
            return null;
        }

        foreach ((array) ($map['mappings'] ?? []) as $mapping) {
            // type 0 = equals (the common case); other mapping types are not applied.
            if (\is_array($mapping) && (int) ($mapping['type'] ?? 0) === 0 && (string) ($mapping['value'] ?? '') === $value) {
                return (string) ($mapping['newvalue'] ?? '');
            }
        }

        return null;
    }

    private static function trim(float $value): string
    {
        $text = number_format($value, abs($value) >= 100 ? 0 : 2, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
