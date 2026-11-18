<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Api\ConnectTarget;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVault;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVaultException;

/**
 * Turns the posted Integration Settings form into the values to store. Pure: no Joomla, no
 * database, so every rule is covered by unit tests.
 *
 * - A new API token is encrypted; an empty field keeps the stored one.
 * - "Remove stored API token" deletes it.
 * - All errors are collected and returned as language keys.
 */
final class SettingsValidator
{
    /** @var \Closure(string): bool */
    private \Closure $fileExists;

    /**
     * @param  \Closure(string, string): string  $encrypt     (plaintext, context) => ciphertext; may throw KeyVaultException
     * @param  (\Closure(string): bool)|null     $fileExists  Injected for tests
     */
    public function __construct(private readonly \Closure $encrypt, ?\Closure $fileExists = null)
    {
        $this->fileExists = $fileExists ?? static fn (string $path): bool => is_file($path) && is_readable($path);
    }

    /**
     * @param  array<string, mixed>  $input  Raw posted values (jform)
     *
     * @return array<string, string>  Values to store
     *
     * @throws ValidationFailed
     */
    public function validate(array $input, Settings $current): array
    {
        $errors = [];
        $values = [];

        // Zabbix address.
        $url = trim(self::str($input['zabbix_url'] ?? ''));

        if ($url !== '' && !self::validUrl($url)) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_URL';
        }

        $values['zabbix_url'] = $url;

        // Optional port, put into the address.
        $portText = trim(self::str($input['zabbix_port'] ?? ''));
        $port     = self::int($portText);

        if ($portText !== '' && ($port === null || $port < 1 || $port > 65535)) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_PORT';
            $port     = null;
        }

        $values['zabbix_port'] = $port !== null && $portText !== '' ? (string) $port : '';

        // Optional Connect via: stored as "host:port" (the port defaults to the address's port).
        $via = trim(self::str($input['connect_via'] ?? ''));

        if ($via !== '') {
            $apiUrl = Settings::normaliseApiUrl($url);
            $apiUrl = $apiUrl !== '' && $values['zabbix_port'] !== '' ? ConnectTarget::withPort($apiUrl, (int) $values['zabbix_port']) : $apiUrl;

            try {
                $via = ConnectTarget::normaliseConnectVia($via, $apiUrl !== '' ? ConnectTarget::port($apiUrl) : 443);
            } catch (\InvalidArgumentException) {
                $errors[] = 'COM_ZABBIX_SETTINGS_ERR_CONNECT_VIA';
                $via      = '';
            }
        }

        $values['connect_via'] = $via;

        $frontend = trim(self::str($input['frontend_url'] ?? ''));

        if ($frontend !== '' && !self::validUrl($frontend)) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_FRONTEND_URL';
        }

        $values['frontend_url'] = rtrim($frontend, '/');
        $values['show_links']   = self::flag($input['show_links'] ?? '1') ? '1' : '0';

        // API token: a new one replaces the stored one; empty keeps it.
        $token = trim(self::str($input['api_token'] ?? ''));

        if (self::flag($input['clear_api_token'] ?? '0')) {
            $values['api_token_enc'] = '';
        } elseif ($token !== '') {
            if (!preg_match('/^[A-Za-z0-9]{32,128}$/', $token)) {
                $errors[] = 'COM_ZABBIX_SETTINGS_ERR_TOKEN';
            } else {
                try {
                    $values['api_token_enc'] = ($this->encrypt)($token, KeyVault::CONTEXT_API_TOKEN);
                } catch (KeyVaultException) {
                    $errors[] = 'COM_ZABBIX_SETTINGS_ERR_NO_SECRET';
                }
            }
        }

        // TLS.
        $values['verify_tls'] = self::flag($input['verify_tls'] ?? '1') ? '1' : '0';
        $ca                   = trim(self::str($input['ca_bundle'] ?? ''));

        if ($ca !== '' && !($this->fileExists)($ca)) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_CA';
        }

        $values['ca_bundle'] = $ca;

        // Timeouts.
        $connect = self::int($input['connect_timeout'] ?? null);
        $total   = self::int($input['timeout'] ?? null);

        if ($connect === null || $connect < 1 || $connect > 30 || $total === null || $total < 1 || $total > 120) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_TIMEOUT_RANGE';
        } elseif ($connect > $total) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_TIMEOUTS';
        }

        $values['connect_timeout'] = (string) ($connect ?? 5);
        $values['timeout']         = (string) ($total ?? 20);

        // User mappings.
        $values['auto_match'] = self::flag($input['auto_match'] ?? '1') ? '1' : '0';
        $mode                 = self::str($input['match_mode'] ?? '');
        $values['match_mode'] = \in_array($mode, Settings::MATCH_MODES, true) ? $mode : 'username_email';

        // Portal.
        $refresh = self::int($input['refresh_seconds'] ?? null);

        if ($refresh === null || !\in_array($refresh, Settings::REFRESH_CHOICES, true)) {
            $errors[]  = 'COM_ZABBIX_SETTINGS_ERR_REFRESH';
            $refresh   = 60;
        }

        $values['refresh_seconds'] = (string) $refresh;

        $max = self::int($input['max_hosts'] ?? null);

        if ($max === null || $max < 10 || $max > 20000) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_MAX_HOSTS';
        }

        $values['max_hosts'] = (string) max(10, min(20000, $max ?? 2000));

        // System.
        $values['detailed_log'] = self::flag($input['detailed_log'] ?? '1') ? '1' : '0';
        $cache                  = self::int($input['cache_minutes'] ?? null);

        if ($cache === null || $cache < 0 || $cache > Settings::MAX_CACHE_MINUTES) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_CACHE';
        }

        $values['cache_minutes'] = (string) max(0, min(Settings::MAX_CACHE_MINUTES, $cache ?? 5));

        $date                  = self::str($input['date_format'] ?? '');
        $time                  = self::str($input['time_format'] ?? '');
        $values['date_format'] = \in_array($date, Settings::DATE_FORMATS, true) ? $date : 'd/m/Y';
        $values['time_format'] = \in_array($time, Settings::TIME_FORMATS, true) ? $time : 'H:i';

        $zone = trim(self::str($input['display_timezone'] ?? ''));

        if ($zone !== '' && !\in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_TIMEZONE';
            $zone     = '';
        }

        $values['display_timezone'] = $zone;

        // A connection needs both the address and a token (stored or new).
        $hasToken = \array_key_exists('api_token_enc', $values) ? $values['api_token_enc'] !== '' : $current->hasToken();

        if ($url === '' && $hasToken) {
            $errors[] = 'COM_ZABBIX_SETTINGS_ERR_URL_REQUIRED';
        }

        if ($errors !== []) {
            throw new ValidationFailed(array_values(array_unique($errors)));
        }

        return $values;
    }

    public static function validUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts)
            && \in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
            && !isset($parts['user'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && !preg_match('/\s/', $url);
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && preg_match('/^\s*-?\d{1,9}\s*$/', $value) ? (int) $value : null;
    }

    private static function flag(mixed $value): bool
    {
        return \in_array(self::str($value), ['1', 'true', 'on', 'yes'], true);
    }
}
