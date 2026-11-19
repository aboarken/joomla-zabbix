<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Api\ConnectTarget;
use ZabbixPortal\Component\Zabbix\Administrator\Api\TransportOptions;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVault;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVaultException;

/**
 * Immutable view of the settings stored in #__zabbix_settings (name/value rows).
 *
 * Settings live in the component's own table, not in com_config params: com_config replaces all
 * params on save, and the API token must be encrypted before it is stored.
 */
final class Settings
{
    public const MAX_CACHE_MINUTES = 60;

    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd M Y'];
    public const TIME_FORMATS = ['H:i', 'H:i:s', 'h:i A'];

    /** How Joomla users are matched to Zabbix users. */
    public const MATCH_MODES = ['username_email', 'username', 'email'];

    /** Dashboard refresh choices, in seconds (0 = never). */
    public const REFRESH_CHOICES = [0, 30, 60, 120, 300, 600, 900];

    public const DEFAULTS = [
        // Connection: Zabbix front-end address (with or without /api_jsonrpc.php)
        'zabbix_url'      => '',
        // Optional port put into the Zabbix address ('' = the address's own, or 443/80)
        'zabbix_port'     => '',
        // Optional address the connection is opened to instead of the Zabbix host:
        // "host", "host:port" or "IP:port" ('' = connect directly)
        'connect_via'     => '',
        // API token, encrypted with KeyVault ('' = not set)
        'api_token_enc'   => '',
        'verify_tls'      => '1',
        'ca_bundle'       => '',
        'connect_timeout' => '5',
        'timeout'         => '20',
        // Address used for "Open in Zabbix" links ('' = the API address without /api_jsonrpc.php)
        'frontend_url'    => '',
        'show_links'      => '1',
        // User mappings
        'auto_match'      => '1',
        // New users: 1 = a match works at once, 0 = it waits for an administrator (User Mappings → Confirm)
        'auto_approve'    => '1',
        'match_mode'      => 'username_email',
        // Portal
        'refresh_seconds' => '60',
        'max_hosts'       => '2000',
        // System
        'detailed_log'    => '1',
        'cache_minutes'   => '5',
        'date_format'     => 'd/m/Y',
        'time_format'     => 'H:i',
        'display_timezone' => '',
    ];

    /** Names never sent to the browser. */
    public const SECRET_NAMES = ['api_token_enc'];

    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = [])
    {
    }

    public function get(string $name): string
    {
        return $this->values[$name] ?? self::DEFAULTS[$name] ?? '';
    }

    /** The JSON-RPC endpoint: <front-end>[:port]/api_jsonrpc.php, or '' when not set. */
    public function apiUrl(): string
    {
        $url = self::normaliseApiUrl($this->get('zabbix_url'));

        return $url === '' ? '' : ConnectTarget::withPort($url, $this->port());
    }

    /** The optional Port setting (0 = not set). */
    public function port(): int
    {
        $port = (int) $this->get('zabbix_port');

        return $port >= 1 && $port <= 65535 ? $port : 0;
    }

    /** The optional Connect via address as "host:port", or ''. */
    public function connectVia(): string
    {
        $url = $this->apiUrl();

        try {
            return $url === '' ? '' : ConnectTarget::normaliseConnectVia($this->get('connect_via'), ConnectTarget::port($url));
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    public static function normaliseApiUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || !preg_match('#^https?://[^\s/?\#]+#i', $url)) {
            return '';
        }

        $url = rtrim($url, '/');

        return str_ends_with(strtolower($url), '/api_jsonrpc.php') ? $url : $url . '/api_jsonrpc.php';
    }

    /** Base address of the Zabbix front end for links ('' when links are switched off). */
    public function frontendUrl(): string
    {
        if (!$this->showLinks()) {
            return '';
        }

        $url = trim($this->get('frontend_url'));

        if ($url === '') {
            $url = preg_replace('#/api_jsonrpc\.php$#i', '', $this->apiUrl()) ?? '';
        }

        return preg_match('#^https?://#i', $url) ? rtrim($url, '/') : '';
    }

    public function showLinks(): bool
    {
        return $this->get('show_links') !== '0';
    }

    public function hasToken(): bool
    {
        return $this->get('api_token_enc') !== '';
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl() !== '' && $this->hasToken();
    }

    /**
     * @throws ZabbixException NOT_CONFIGURED when there is no token or it cannot be decrypted
     */
    public function apiToken(KeyVault $vault): string
    {
        if (!$this->hasToken()) {
            throw new ZabbixException(ZabbixException::NOT_CONFIGURED, 'No API token');
        }

        try {
            return $vault->decrypt($this->get('api_token_enc'), KeyVault::CONTEXT_API_TOKEN);
        } catch (KeyVaultException $e) {
            throw new ZabbixException(ZabbixException::NOT_CONFIGURED, 'The API token cannot be decrypted', 'Encryption secret changed? Enter the token again.', '', $e);
        }
    }

    public function verifiesTls(): bool
    {
        return $this->get('verify_tls') !== '0';
    }

    public function transportOptions(): TransportOptions
    {
        $timeout = max(1, min(120, (int) $this->get('timeout')));

        return new TransportOptions(
            max(1, min(30, $timeout, (int) $this->get('connect_timeout'))),
            $timeout,
            $this->get('verify_tls') !== '0',
            trim($this->get('ca_bundle')),
            ConnectTarget::connectToEntry($this->apiUrl(), $this->get('connect_via'))
        );
    }

    public function autoMatch(): bool
    {
        return $this->get('auto_match') !== '0';
    }

    /** New users: approve automatic matches at once (true) or wait for an administrator (false). */
    public function autoApprove(): bool
    {
        return $this->get('auto_approve') !== '0';
    }

    public function matchMode(): string
    {
        $mode = $this->get('match_mode');

        return \in_array($mode, self::MATCH_MODES, true) ? $mode : 'username_email';
    }

    public function refreshSeconds(): int
    {
        $value = (int) $this->get('refresh_seconds');

        return \in_array($value, self::REFRESH_CHOICES, true) ? $value : 60;
    }

    public function maxHosts(): int
    {
        return max(10, min(20000, (int) $this->get('max_hosts')));
    }

    public function detailedLog(): bool
    {
        return $this->get('detailed_log') !== '0';
    }

    /**
     * How long Zabbix users, their rights, host group names and dashboard definitions are kept,
     * in seconds; 0 = for one page load only. Live data (problems, values, hosts) is never kept.
     */
    public function cacheTtl(): int
    {
        return max(0, min(self::MAX_CACHE_MINUTES, (int) $this->get('cache_minutes'))) * 60;
    }

    public function dateFormat(): string
    {
        $format = $this->get('date_format');

        return \in_array($format, self::DATE_FORMATS, true) ? $format : 'd/m/Y';
    }

    public function timeFormat(): string
    {
        $format = $this->get('time_format');

        return \in_array($format, self::TIME_FORMATS, true) ? $format : 'H:i';
    }

    /** '' = Joomla's own (user or site) time zone. */
    public function displayTimezone(): string
    {
        $zone = $this->get('display_timezone');

        return $zone !== '' && \in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : '';
    }

    /**
     * Values for the Integration Settings form; never the encrypted token.
     *
     * @return array<string, string>
     */
    public function toFormData(): array
    {
        $data = [];

        foreach (array_keys(self::DEFAULTS) as $name) {
            if (!\in_array($name, self::SECRET_NAMES, true)) {
                $data[$name] = $this->get($name);
            }
        }

        return $data;
    }
}
