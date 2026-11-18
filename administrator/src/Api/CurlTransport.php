<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * HTTP POST with cURL. One handle is kept per page load, so the TLS connection to Zabbix is
 * reused by the several API calls a page makes. Redirects are not followed (a redirect would
 * resend the token to another address).
 */
final class CurlTransport implements TransportInterface
{
    /** Larger answers are refused (a page never needs this much). */
    private const MAX_BYTES = 32 * 1024 * 1024;

    /**
     * cURL error numbers of TLS failures: 35 SSL connect error, 51 peer failed verification,
     * 58 client certificate problem, 60 certificate not trusted, 77 CA file unreadable.
     * Numbers, because PHP does not define a constant for each of them.
     */
    private const TLS_ERRORS = [35, 51, 58, 60, 77];

    /** cURL error 23: the write callback stopped the transfer (answer too large). */
    private const WRITE_ERROR = 23;

    private ?\CurlHandle $handle = null;

    public function post(string $url, array $headers, string $body, TransportOptions $options): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new ZabbixException(ZabbixException::NOT_CONFIGURED, 'Invalid Zabbix URL');
        }

        $this->handle ??= curl_init();
        $ch = $this->handle;
        curl_reset($ch);

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $buffer = '';
        $opts   = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $lines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, $options->connectTimeout),
            CURLOPT_TIMEOUT        => max(1, $options->timeout),
            CURLOPT_SSL_VERIFYPEER => $options->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $options->verifyTls ? 2 : 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Joomla-com_zabbix/1.0.1',
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$buffer): int {
                if (\strlen($buffer) + \strlen($chunk) > self::MAX_BYTES) {
                    return 0;
                }

                $buffer .= $chunk;

                return \strlen($chunk);
            },
        ];

        // Connect via: open the connection to another address, keep the URL's host name for the
        // certificate check (SNI) and the Host header.
        if ($options->connectTo !== '') {
            $opts[CURLOPT_CONNECT_TO] = [$options->connectTo];
        }

        if ($options->verifyTls && $options->caBundle !== '') {
            $opts[CURLOPT_CAINFO] = $options->caBundle;
        }

        curl_setopt_array($ch, $opts);

        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);

        if ($ok === false || $errno !== 0) {
            $error = curl_error($ch);
            $kind  = \in_array($errno, self::TLS_ERRORS, true)
                ? ZabbixException::TLS
                : ZabbixException::NETWORK;

            if ($errno === self::WRITE_ERROR) {
                $error = 'Answer larger than ' . (self::MAX_BYTES >> 20) . ' MB';
                $kind  = ZabbixException::INVALID_RESPONSE;
            }

            throw new ZabbixException($kind, 'Zabbix could not be reached', 'cURL ' . $errno . ': ' . $error);
        }

        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $buffer];
    }
}
