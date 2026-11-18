<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Availability;

/**
 * Zabbix Hosts page: the hosts the user may see, filtered, with summary counts.
 */
final class HostsModel extends PortalModel
{
    public const STATUSES       = ['', 'enabled', 'disabled', 'maintenance'];
    public const AVAILABILITIES = ['', Availability::AVAILABLE, Availability::UNAVAILABLE, Availability::UNKNOWN, Availability::MIXED];
    public const PROBLEMS       = ['', 'with', 'without'];

    /** @return array<string, string> groupid => name */
    public function groups(): array
    {
        return $this->zabbix()->getAccessService()->readableGroups($this->scope());
    }

    /**
     * @param  array{group: string, search: string, status: string, availability: string, problems: string}  $filters
     *
     * @return array{hosts: list<array<string, mixed>>, cards: array<string, int>}
     */
    public function hosts(array $filters): array
    {
        $groups = $filters['group'] !== '' ? [$filters['group']] : [];
        $all    = $this->zabbix()->getHostService()->hosts($this->scope(), $groups, $filters['search'], $this->settings()->maxHosts());

        $cards = [
            'total'       => \count($all),
            'available'   => 0,
            'unavailable' => 0,
            'maintenance' => 0,
            'problems'    => 0,
        ];

        foreach ($all as $host) {
            $cards['available']   += $host['availability'] === Availability::AVAILABLE ? 1 : 0;
            $cards['unavailable'] += \in_array($host['availability'], [Availability::UNAVAILABLE, Availability::MIXED], true) ? 1 : 0;
            $cards['maintenance'] += $host['maintenance'] ? 1 : 0;
            $cards['problems']    += $host['problemTotal'] > 0 ? 1 : 0;
        }

        $hosts = array_values(array_filter($all, static function (array $h) use ($filters): bool {
            return match ($filters['status']) {
                'enabled'     => $h['enabled'],
                'disabled'    => !$h['enabled'],
                'maintenance' => $h['maintenance'],
                default       => true,
            } && ($filters['availability'] === '' || $h['availability'] === $filters['availability'])
              && match ($filters['problems']) {
                  'with'    => $h['problemTotal'] > 0,
                  'without' => $h['problemTotal'] === 0,
                  default   => true,
              };
        }));

        return ['hosts' => $hosts, 'cards' => $cards];
    }
}
