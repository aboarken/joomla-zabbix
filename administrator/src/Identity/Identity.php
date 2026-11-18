<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The logged-in Joomla user and, when mapped, their Zabbix user.
 */
final class Identity
{
    public function __construct(
        public readonly int $joomlaUserId,
        public readonly ?UserMapping $mapping = null,
        public readonly ?DirectoryUser $zabbixUser = null
    ) {
    }

    public function isMatched(): bool
    {
        return $this->mapping !== null && $this->mapping->isActive() && $this->zabbixUser !== null;
    }

    /** Status shown on the "not linked" page: active, suspended, ambiguous, not_found or none. */
    public function status(): string
    {
        return $this->mapping?->status ?? 'none';
    }
}
