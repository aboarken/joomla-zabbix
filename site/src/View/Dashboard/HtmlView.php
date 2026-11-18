<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\View\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\DashboardLayout;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Period;
use ZabbixPortal\Component\Zabbix\Site\Model\DashboardModel;
use ZabbixPortal\Component\Zabbix\Site\View\PortalViewTrait;

/**
 * Zabbix Dashboard: one Zabbix dashboard drawn on the page. The layout comes with the page; each
 * widget's data is loaded by media/com_zabbix/js/dashboard.js from DataController::widget().
 *
 * Menu item options: the dashboard to show (or the first the user may open) and whether the user
 * may switch to another of their dashboards.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    /** @var list<array{id: string, name: string}> */
    public array $dashboards = [];

    public ?string $currentId = null;

    public bool $allowChoose = true;

    /** @var array<string, mixed>|null */
    public ?array $layout = null;

    /** Settings for dashboard.js. */
    public array $config = [];

    public string $zabbixLink = '';

    public ?ZabbixException $error = null;

    /** The menu item names a dashboard the user may not open. */
    public bool $denied = false;

    public function display($tpl = null): void
    {
        /** @var DashboardModel $model */
        $model = $this->getModel();
        $this->preparePage($model, 'dashboard', 'COM_ZABBIX_DASHBOARD_TITLE');

        $app    = Factory::getApplication();
        $item   = $app->getMenu()->getActive();
        $params = $item !== null && ($item->query['view'] ?? '') === 'dashboard' ? $item->getParams() : null;
        $fixed  = (string) ($params?->get('dashboard_id', '') ?? '');

        $this->allowChoose = $params === null || (string) $params->get('allow_choose', '1') !== '0';

        try {
            $this->dashboards = $model->dashboards();
            $ids              = array_column($this->dashboards, 'id');
            $wanted           = $this->allowChoose ? (string) $app->getInput()->getUint('dashboard', 0) : '';

            if ($wanted !== '0' && $wanted !== '' && \in_array($wanted, $ids, true)) {
                $this->currentId = $wanted;
            } elseif ($fixed !== '' && $fixed !== '0') {
                $this->currentId = \in_array($fixed, $ids, true) ? $fixed : null;
                $this->denied    = $this->currentId === null;
            } else {
                $this->currentId = $ids[0] ?? null;
            }

            $layout = $this->currentId !== null ? $model->layout($this->currentId) : null;

            if ($layout !== null) {
                $this->layout     = DashboardLayout::forBrowser($layout);
                $this->zabbixLink = $model->zabbixLink('zabbix.php?action=dashboard.view&dashboardid=' . (int) $this->currentId);
            }
        } catch (ZabbixException $e) {
            $this->error = $e;
        }

        $refresh = $params !== null && (string) $params->get('refresh', '') !== '' ? (int) $params->get('refresh') : $model->settings()->refreshSeconds();

        $this->config = [
            'endpoint'  => Route::_('index.php?option=com_zabbix&task=data.widget&format=json&' . Session::getFormToken() . '=1', false),
            'dashboard' => $this->currentId,
            'refresh'   => max(0, $refresh),
            'zabbix'    => $model->settings()->frontendUrl(),
            'hostUrl'   => Route::_('index.php?option=com_zabbix&view=host&hostid=__ID__', false),
            'format'    => $this->f->jsFormats(),
            'periods'   => array_keys(Period::CHOICES),
        ];

        if ($this->layout !== null) {
            $this->getDocument()->getWebAssetManager()->useScript('com_zabbix.dashboard');
        }

        parent::display($tpl);
    }
}
