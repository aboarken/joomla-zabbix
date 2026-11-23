<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Sends one HTTP POST. Replaced by a fake in the tests.
 */
interface TransportInterface
{
    /**
     * @param  array<string, string>  $headers
     *
     * @return array{status: int, body: string}
     *
     * @throws ZabbixException  NETWORK or TLS
     */
    public function post(string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] string $body, TransportOptions $options): array;
}
