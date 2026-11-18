<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A Zabbix API call failed. The kind decides the message users see (a language key); the detail
 * is short, technical and secret-free, for the log and the administrator pages only.
 */
final class ZabbixException extends \RuntimeException
{
    /** No URL or no API token in Integration Settings, or the token cannot be decrypted. */
    public const NOT_CONFIGURED = 'not_configured';
    /** DNS, connection refused, timeout. */
    public const NETWORK = 'network';
    /** The TLS certificate was refused. */
    public const TLS = 'tls';
    /** Non-200 HTTP status (wrong URL, proxy, web server). */
    public const HTTP = 'http';
    /** The token was refused (unknown, expired, disabled user). */
    public const AUTH = 'auth';
    /** The token's role may not call this method. */
    public const PERMISSION = 'permission';
    /** Any other JSON-RPC error object. */
    public const API = 'api';
    /** Not JSON, or not a JSON-RPC answer. */
    public const INVALID_RESPONSE = 'invalid_response';

    public const KINDS = [
        self::NOT_CONFIGURED, self::NETWORK, self::TLS, self::HTTP, self::AUTH,
        self::PERMISSION, self::API, self::INVALID_RESPONSE,
    ];

    public function __construct(
        private readonly string $kind,
        string $message,
        private readonly string $detail = '',
        private readonly string $method = '',
        ?\Throwable $previous = null
    ) {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown error kind ' . $kind);
        }

        parent::__construct($message, 0, $previous);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /** Secret-free technical detail, e.g. Zabbix's own error text. */
    public function getDetail(): string
    {
        return self::clean($this->detail);
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getLanguageKey(): string
    {
        return 'COM_ZABBIX_ERROR_' . strtoupper($this->kind);
    }

    /**
     * One line of at most 250 characters, with anything that looks like a token or password removed.
     */
    public static function clean(string $text): string
    {
        $text = preg_replace('/\b[a-f0-9]{32,128}\b/i', '[redacted]', $text) ?? '';
        $text = preg_replace('/(bearer|token|password|auth)(["\']?\s*[:=]\s*["\']?)[^\s"\',}]+/i', '$1$2[redacted]', $text) ?? '';
        $text = preg_replace('/\s+/', ' ', $text) ?? '';

        return mb_substr(trim($text), 0, 250);
    }
}
