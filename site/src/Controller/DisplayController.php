<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;

/**
 * Every portal page passes through here:
 *  1. login required;
 *  2. a known view and its Joomla permission (403 otherwise);
 *  3. a mapped Zabbix user (otherwise only the "not linked" page).
 */
final class DisplayController extends BaseController
{
    use PortalTrait;

    /** view => required permission */
    public const VIEWS = [
        'dashboard' => 'zabbix.dashboard.view',
        'hosts'     => 'zabbix.hosts.view',
        'host'      => 'zabbix.hosts.view',
    ];

    protected $default_view = 'dashboard';

    public function display($cachable = false, $urlparams = [])
    {
        $this->noCache();

        if (!$this->requireLogin()) {
            return $this;
        }

        $view = $this->input->getCmd('view', $this->default_view);

        if (!isset(self::VIEWS[$view])) {
            throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        if (!$this->app->getIdentity()->authorise(self::VIEWS[$view], 'com_zabbix')) {
            throw new NotAllowed(Text::_('COM_ZABBIX_NOT_AUTHORISED'), 403);
        }

        try {
            $identity = $this->currentIdentity();
        } catch (ZabbixException $e) {
            $this->zabbix()->getLogger()->warning('Portal: Zabbix unavailable (' . $e->getKind() . ')');
            $this->input->set('view', 'unmatched');
            $this->input->set('layout', 'unavailable');
            $this->input->set('kind', $e->getKind());

            return parent::display(false, $urlparams);
        }

        if (!$identity->isMatched()) {
            $this->input->set('view', 'unmatched');
            $this->input->set('layout', 'default');
            $this->input->set('status', $identity->status());
        }

        return parent::display(false, $urlparams);
    }
}
