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
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;

final class DisplayController extends BaseController
{
    protected $default_view = 'integrationsettings';

    /** Administrator views that exist in this version. */
    private const VIEWS = ['integrationsettings', 'usermappings', 'usermap', 'endpoints'];

    public function display($cachable = false, $urlparams = [])
    {
        // Settings, mappings and diagnostics are security-sensitive: super-user level only.
        if (!$this->app->getIdentity()->authorise('core.admin', 'com_zabbix')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if (!\in_array($this->input->getCmd('view', $this->default_view), self::VIEWS, true)) {
            throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        return parent::display(false, $urlparams);
    }
}
