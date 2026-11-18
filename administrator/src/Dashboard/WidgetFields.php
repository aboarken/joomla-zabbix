<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The fields of one dashboard widget (dashboard.get: [{type, name, value}, ...]). Pure.
 *
 * Zabbix 7.x names list values with an index: "groupids.0", "groupids.1", "ds.0.itemids.0".
 * Fields that refer to another widget ("....._reference") are ignored: the portal shows each
 * widget on its own.
 */
final class WidgetFields
{
    /** @var array<string, string> */
    private array $values = [];

    /** @param list<array<string, mixed>> $fields */
    public function __construct(array $fields)
    {
        foreach ($fields as $field) {
            if (\is_array($field) && isset($field['name']) && \is_scalar($field['value'] ?? null)) {
                $this->values[(string) $field['name']] = (string) $field['value'];
            }
        }
    }

    public function get(string $name, string $default = ''): string
    {
        return $this->values[$name] ?? $default;
    }

    public function int(string $name, int $default = 0): int
    {
        $value = $this->values[$name] ?? null;

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $name, float $default = 0.0): float
    {
        $value = $this->values[$name] ?? null;

        return $value !== null && is_numeric($value) ? (float) $value : $default;
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }

    /**
     * The values of "<prefix>.0", "<prefix>.1", ... in index order.
     *
     * @return list<string>
     */
    public function list(string $prefix): array
    {
        $found = [];
        $quote = preg_quote($prefix, '/');

        foreach ($this->values as $name => $value) {
            if (preg_match('/^' . $quote . '\.(\d+)$/', $name, $m)) {
                $found[(int) $m[1]] = $value;
            }
        }

        ksort($found);

        return array_values($found);
    }

    /** @return list<int> */
    public function intList(string $prefix): array
    {
        return array_map('intval', array_filter($this->list($prefix), 'is_numeric'));
    }

    /**
     * Indexes N of "<prefix>.N.<anything>" (data sets, columns), in order.
     *
     * @return list<int>
     */
    public function indexes(string $prefix): array
    {
        $found = [];
        $quote = preg_quote($prefix, '/');

        foreach (array_keys($this->values) as $name) {
            if (preg_match('/^' . $quote . '\.(\d+)\./', $name, $m)) {
                $found[(int) $m[1]] = true;
            }
        }

        $indexes = array_keys($found);
        sort($indexes);

        return $indexes;
    }
}
