<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Monitoring;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Host availability from its interfaces (available: 0 unknown, 1 available, 2 not available) and
 * active agent checks (active_available, same values). Pure.
 */
final class Availability
{
    public const AVAILABLE   = 'available';
    public const UNAVAILABLE = 'unavailable';
    public const UNKNOWN     = 'unknown';
    public const MIXED       = 'mixed';
    public const NONE        = 'none';

    /** Interface types. */
    public const TYPES = [1 => 'agent', 2 => 'snmp', 3 => 'ipmi', 4 => 'jmx'];

    /**
     * @param list<array<string, mixed>> $interfaces
     */
    public static function of(array $interfaces, int|string|null $activeAvailable = 0): string
    {
        $states = [];

        foreach ($interfaces as $interface) {
            if (\is_array($interface)) {
                $states[] = (int) ($interface['available'] ?? 0);
            }
        }

        if ((int) $activeAvailable !== 0) {
            $states[] = (int) $activeAvailable;
        }

        if ($states === []) {
            return self::NONE;
        }

        $up   = \in_array(1, $states, true);
        $down = \in_array(2, $states, true);

        return match (true) {
            $up && $down => self::MIXED,
            $down        => self::UNAVAILABLE,
            $up          => self::AVAILABLE,
            default      => self::UNKNOWN,
        };
    }

    public static function interfaceState(int|string|null $available): string
    {
        return match ((int) $available) {
            1       => self::AVAILABLE,
            2       => self::UNAVAILABLE,
            default => self::UNKNOWN,
        };
    }
}
