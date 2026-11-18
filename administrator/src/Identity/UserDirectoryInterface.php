<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The Zabbix users the API token can see.
 */
interface UserDirectoryInterface
{
    /**
     * @return list<DirectoryUser>
     *
     * @throws \ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException
     */
    public function all(): array;

    public function find(string $userid): ?DirectoryUser;
}
