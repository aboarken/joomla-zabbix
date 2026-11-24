<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Support;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\ValueFormatter;

/**
 * Dates and durations for the portal pages, in the formats of Integration Settings → System.
 */
final class Formatter
{
    public function __construct(
        private readonly \DateTimeZone $zone,
        private readonly string $dateFormat = 'd/m/Y',
        private readonly string $timeFormat = 'H:i'
    ) {
    }

    /**
     * The portal's time zone: the one of Integration Settings, else the user's, else the site's;
     * UTC when none of them is a time zone.
     */
    public static function zone(string $setting, string $user, string $site): \DateTimeZone
    {
        foreach ([$setting, $user, $site] as $name) {
            if (trim($name) !== '') {
                // timezone_open() answers false for an unknown zone; no exception is involved
                // (with Xdebug loaded, PHP 8.3's DateInvalidTimeZoneException raises an Error).
                $zone = @timezone_open(trim($name));

                if ($zone === false) {
                    break;
                }

                return $zone;
            }
        }

        return new \DateTimeZone('UTC');
    }

    public function dateTime(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone)->format($this->dateFormat . ' ' . $this->timeFormat);
    }

    public function duration(int $seconds): string
    {
        return ValueFormatter::duration($seconds);
    }

    public function zoneName(): string
    {
        return $this->zone->getName();
    }

    /** Formats for the browser (dashboard.js), as Intl options. */
    public function jsFormats(): array
    {
        return [
            'zone'   => $this->zone->getName(),
            'hour12' => str_contains($this->timeFormat, 'A'),
            'secs'   => str_contains($this->timeFormat, 's'),
            'date'   => $this->dateFormat,
        ];
    }
}
