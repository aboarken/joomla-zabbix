<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\View\Host;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Period;
use ZabbixPortal\Component\Zabbix\Site\Model\HostModel;
use ZabbixPortal\Component\Zabbix\Site\View\PortalViewTrait;

/**
 * Host details: status, interfaces, groups, tags, inventory, current problems, latest data and
 * a chart of the chosen item. Opened from the Hosts page (and from dashboard widgets).
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    /** @var array<string, mixed>|null */
    public ?array $host = null;

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public array $config = [];

    public string $zabbixLink = '';

    public string $backUrl = '';

    public ?ZabbixException $error = null;

    public function display($tpl = null): void
    {
        /** @var HostModel $model */
        $model = $this->getModel();
        $this->preparePage($model, 'host', 'COM_ZABBIX_HOST_TITLE');

        $hostId        = (string) Factory::getApplication()->getInput()->getUint('hostid', 0);
        $this->backUrl = Route::_('index.php?option=com_zabbix&view=hosts');

        try {
            $this->host = $model->host($hostId);

            if ($this->host !== null) {
                $this->items      = $model->items($hostId);
                $this->pageHeading = (string) $this->host['name'];
                $this->setPortalTitle((string) $this->host['name']);
                $this->zabbixLink = $model->zabbixLink('zabbix.php?action=host.view&filter_host=' . rawurlencode((string) $this->host['host']) . '&filter_set=1');
            }
        } catch (ZabbixException $e) {
            $this->error = $e;
        }

        $this->config = [
            'endpoint' => Route::_('index.php?option=com_zabbix&task=data.history&format=json', false),
            'token'     => Session::getFormToken(),
            'host'     => $hostId,
            'format'   => $this->f->jsFormats(),
            'periods'  => array_keys(Period::CHOICES),
        ];

        if ($this->host !== null) {
            $this->getDocument()->getWebAssetManager()->useScript('com_zabbix.host');
        }

        parent::display($tpl);
    }
}
