<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use ZabbixPortal\Component\Zabbix\Administrator\Extension\ZabbixComponent;

/**
 * Shared by the administrator controllers: POST + CSRF token + core.admin for every task.
 */
trait ComponentTrait
{
    private function guard(): void
    {
        $this->checkToken();

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_zabbix')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    private function actorId(): int
    {
        return (int) $this->app->getIdentity()->id;
    }

    private function component(): ZabbixComponent
    {
        $component = $this->app->bootComponent('com_zabbix');

        if (!$component instanceof ZabbixComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }
}
