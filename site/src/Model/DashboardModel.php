<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Zabbix Dashboard page: the dashboards the user may open and the chosen one's layout.
 */
final class DashboardModel extends PortalModel
{
    protected function permission(): string
    {
        return 'zabbix.dashboard.view';
    }

    /** @return list<array{id: string, name: string}> */
    public function dashboards(): array
    {
        return $this->zabbix()->getDashboardService()->visibleTo($this->scope());
    }

    /** @return array{id: string, name: string, pages: list<array<string, mixed>>}|null */
    public function layout(string $dashboardId): ?array
    {
        return $this->zabbix()->getDashboardService()->layout($this->scope(), $dashboardId);
    }
}
