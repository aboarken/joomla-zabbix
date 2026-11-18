<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Dispatcher;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Language\Text;

/**
 * Backend entrance of the component. Every administrator page and task needs core.admin (Super
 * User level), checked here once for every request, whatever the task or view: Joomla's own check
 * is only core.manage, and every controller also has a display task (task=<controller>.display)
 * that would otherwise skip the controllers' own checks.
 */
final class Dispatcher extends ComponentDispatcher
{
    protected function checkAccess(): void
    {
        parent::checkAccess();

        if (!$this->app->getIdentity()?->authorise('core.admin', $this->option)) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
