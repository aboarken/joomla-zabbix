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
    private ?string $checked = null;

    protected function permission(): string
    {
        return 'zabbix.hosts.view';
    }

    /** @return array<string, mixed>|null null when the host does not exist or the user may not see it */
    public function host(string $hostId): ?array
    {
        $host           = $this->zabbix()->getHostService()->host($this->scope(), $hostId);
        $this->checked  = $host !== null ? $hostId : null;

        return $host;
    }

    /** @return list<array<string, mixed>> */
    public function items(string $hostId): array
    {
        // Only for the host that host() has just found visible to this user.
        if ($this->checked === null || $this->checked !== $hostId) {
            return [];
        }

        return $this->zabbix()->getHostService()->items($hostId);
    }
}
