<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Support;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use ZabbixPortal\Component\Zabbix\Administrator\Extension\ZabbixComponent;

/**
 * For models and views: the booted com_zabbix component and its services.
 */
trait ComponentAccess
{
    protected function zabbix(): ZabbixComponent
    {
        $component = Factory::getApplication()->bootComponent('com_zabbix');

        if (!$component instanceof ZabbixComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
