<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Monitoring;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Cache\CacheInterface;

/**
 * The severity colours set in Zabbix (Administration → General → Trigger displaying options),
 * read with settings.get, so the portal shows problems in the same colours as Zabbix. Kept in the
 * shared cache for the cache time of Integration Settings; Zabbix's defaults when they cannot be
 * read.
 */
final class SeverityColours
{
    /** Zabbix's default severity colours: Not classified, Information, Warning, Average, High, Disaster. */
    public const DEFAULTS = ['#97AAB3', '#7499FF', '#FFC859', '#FFA059', '#E97659', '#E45959'];

    private const FIELDS = ['severity_color_0', 'severity_color_1', 'severity_color_2', 'severity_color_3', 'severity_color_4', 'severity_color_5'];

    public function __construct(
        private readonly JsonRpcClient $client,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return list<string> six '#RRGGBB' colours, severity 0 to 5
     */
    public function colours(): array
    {
        try {
            $row = $this->cache->remember(0, 'severity-colours', function (): array {
                $result = $this->client->call('settings.get', ['output' => self::FIELDS]);

                return \is_array($result) ? $result : [];
            });
        } catch (ZabbixException) {
            return self::DEFAULTS;
        }

        return self::fromSettings($row);
    }

    /**
     * Colours from a settings.get answer ("E45959", without "#"); a missing or invalid colour is
     * Zabbix's default.
     *
     * @param  array<string, mixed>  $settings
     *
     * @return list<string>
     */
    public static function fromSettings(array $settings): array
    {
        $colours = [];

        foreach (self::DEFAULTS as $severity => $default) {
            $value      = ltrim(trim((string) ($settings['severity_color_' . $severity] ?? '')), '#');
            $colours[] = preg_match('/^[0-9A-Fa-f]{6}$/', $value) === 1 ? '#' . strtoupper($value) : $default;
        }

        return $colours;
    }
}
