<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

final class TransportOptions
{
    public function __construct(
        public readonly int $connectTimeout = 5,
        public readonly int $timeout = 20,
        public readonly bool $verifyTls = true,
        public readonly string $caBundle = ''
    ) {
    }
}
