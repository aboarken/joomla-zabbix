<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Monitoring;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A time period ending now: 1h, 3h, 6h, 12h, 24h, 7d or 30d.
 */
final class Period
{
    public const CHOICES = [
        '1h'  => 3600,
        '3h'  => 10800,
        '6h'  => 21600,
        '12h' => 43200,
        '24h' => 86400,
        '7d'  => 604800,
        '30d' => 2592000,
    ];

    /** Longer periods read trends (hourly) instead of history. */
    public const TRENDS_AFTER = 172800;

    private function __construct(public readonly string $name)
    {
    }

    public static function from(string $name, string $default = '1h'): self
    {
        return new self(isset(self::CHOICES[$name]) ? $name : (isset(self::CHOICES[$default]) ? $default : '1h'));
    }

    /**
     * From a Zabbix relative time ("now-1h", "now-7d", "now-30m"), to the nearest choice that
     * is at least as long; '' or anything else gives the default.
     */
    public static function fromZabbix(string $from, string $default = '1h'): self
    {
        if (!preg_match('/^now-(\d+)([smhdwMy])$/', trim($from), $m)) {
            return self::from($default);
        }

        $seconds = (int) $m[1] * match ($m[2]) {
            's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, 'M' => 2592000, default => 31536000,
        };

        foreach (self::CHOICES as $name => $length) {
            if ($length >= $seconds) {
                return new self($name);
            }
        }

        return new self('30d');
    }

    public function seconds(): int
    {
        return self::CHOICES[$this->name];
    }

    public function usesTrends(): bool
    {
        return $this->seconds() > self::TRENDS_AFTER;
    }
}
