<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Settings were refused. Carries language keys (translated by the controller), never entered values.
 */
final class ValidationFailed extends \RuntimeException
{
    /** @param list<string> $keys */
    public function __construct(private readonly array $keys)
    {
        parent::__construct(implode(' ', $keys));
    }

    /** @return list<string> */
    public function getKeys(): array
    {
        return $this->keys;
    }
}
