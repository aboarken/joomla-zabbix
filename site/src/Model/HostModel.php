<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Host details page.
 */
final class HostModel extends PortalModel
{
    /** @return array<string, mixed>|null null when the host does not exist or the user may not see it */
    public function host(string $hostId): ?array
    {
        return $this->zabbix()->getHostService()->host($this->scope(), $hostId);
    }

    /** @return list<array<string, mixed>> */
    public function items(string $hostId): array
    {
        return $this->zabbix()->getHostService()->items($hostId);
    }
}
