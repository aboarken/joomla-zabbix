<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The optional Port and Connect via settings. Pure.
 *
 * - Port: put into the Zabbix address (https://zabbix.example.org + 8443 gives
 *   https://zabbix.example.org:8443), for a Zabbix front end published on another port.
 * - Connect via: the address Joomla actually opens the connection to (host, host:port or
 *   IP:port), for a Zabbix server that is hosted locally and reached from outside through
 *   another address, e.g. a public IP with port forwarding. The host name of the Zabbix address
 *   stays in the TLS certificate check (SNI) and the Host header, through cURL's
 *   CURLOPT_CONNECT_TO, so the existing certificate stays valid.
 */
final class ConnectTarget
{
    /**
     * The URL with its port replaced by $port (0 = unchanged).
     */
    public static function withPort(string $url, int $port): string
    {
        if ($port <= 0) {
            return $url;
        }

        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[') ? '[' . $parts['host'] . ']' : $parts['host'];

        return strtolower($parts['scheme']) . '://' . $host . ':' . $port . ($parts['path'] ?? '');
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    public static function port(string $url): int
    {
        $port = parse_url($url, PHP_URL_PORT);

        return $port ? (int) $port : (str_starts_with(strtolower($url), 'https://') ? 443 : 80);
    }

    /**
     * Accepts "host", "host:port", "203.0.113.10:8443" or "[2001:db8::1]:8443"; without a port
     * the Zabbix address's port is used. Returns "host:port", or '' for empty input.
     *
     * @throws \InvalidArgumentException
     */
    public static function normaliseConnectVia(string $value, int $defaultPort): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        if (!preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?$/', $value, $m)) {
            throw new \InvalidArgumentException('Connect via must be host or host:port');
        }

        $host = $m[1];
        $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : $defaultPort;
        $bare = trim($host, '[]');

        $isIp   = filter_var($bare, FILTER_VALIDATE_IP) !== false;
        $isName = !str_starts_with($host, '[')
            && preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host) === 1;

        if ((!$isIp && !$isName) || ($host !== $bare && !filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Connect via must be host or host:port');
        }

        return $host . ':' . $port;
    }

    /**
     * The CURLOPT_CONNECT_TO entry "url-host:url-port:via-host:via-port", or '' when not used.
     */
    public static function connectToEntry(string $url, string $connectVia): string
    {
        if ($connectVia === '' || $url === '') {
            return '';
        }

        try {
            $via = self::normaliseConnectVia($connectVia, self::port($url));
        } catch (\InvalidArgumentException) {
            return '';
        }

        $host = self::host($url);

        return ($host !== '' && str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host) . ':' . self::port($url) . ':' . $via;
    }
}
