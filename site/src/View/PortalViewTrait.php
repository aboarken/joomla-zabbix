<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\View;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use ZabbixPortal\Component\Zabbix\Administrator\Support\Formatter;
use ZabbixPortal\Component\Zabbix\Site\Model\PortalModel;

/**
 * Shared by the portal views:
 *  - heading and browser title from the menu item (title, Page Heading, Browser Page Title,
 *    Show Page Heading), as in Joomla core;
 *  - noindex/nofollow, the portal stylesheet and scripts, and the texts the scripts use.
 */
trait PortalViewTrait
{
    public string $pageHeading = '';

    public bool $showPageHeading = true;

    public Formatter $f;

    /** Severity names (0-5), translated. */
    public static function severityName(int $severity): string
    {
        return Text::_('COM_ZABBIX_SEVERITY_' . max(0, min(5, $severity)));
    }

    protected function preparePage(PortalModel $model, string $view, string $titleKey): void
    {
        $heading = $title = Text::_($titleKey);
        $show    = true;
        $item    = Factory::getApplication()->getMenu()->getActive();

        if ($item !== null && ($item->query['option'] ?? '') === 'com_zabbix' && ($item->query['view'] ?? '') === $view) {
            $params    = $item->getParams();
            $menuTitle = trim((string) $item->title);

            if ($menuTitle !== '') {
                $heading = $title = $menuTitle;
            }

            $heading = trim((string) $params->get('page_heading', '')) ?: $heading;
            $title   = trim((string) $params->get('page_title', '')) ?: $title;
            $show    = (string) $params->get('show_page_heading', '') !== '0';
        }

        $this->pageHeading     = $heading;
        $this->showPageHeading = $show;
        $this->f               = $model->formatter();

        $this->setPortalTitle($title);

        $document = $this->getDocument();
        $document->setMetaData('robots', 'noindex, nofollow');
        $assets = $document->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_zabbix');
        $assets->useStyle('com_zabbix.portal');
        $assets->useScript('com_zabbix.portal');

        foreach (['0', '1', '2', '3', '4', '5'] as $s) {
            Text::script('COM_ZABBIX_SEVERITY_' . $s);
        }

        foreach ([
            'COM_ZABBIX_SEVERITY', 'COM_ZABBIX_JS_LOADING', 'COM_ZABBIX_JS_ERROR', 'COM_ZABBIX_JS_NO_DATA', 'COM_ZABBIX_JS_NOT_DRAWN',
            'COM_ZABBIX_JS_OPEN_IN_ZABBIX', 'COM_ZABBIX_JS_TIME', 'COM_ZABBIX_JS_HOST', 'COM_ZABBIX_JS_PROBLEM',
            'COM_ZABBIX_JS_DURATION', 'COM_ZABBIX_JS_ACK', 'COM_ZABBIX_JS_YES', 'COM_ZABBIX_JS_NO', 'COM_ZABBIX_JS_VALUE',
            'COM_ZABBIX_JS_ITEM', 'COM_ZABBIX_JS_TOTAL', 'COM_ZABBIX_JS_HOSTGROUP', 'COM_ZABBIX_JS_AVAILABLE',
            'COM_ZABBIX_JS_UNAVAILABLE', 'COM_ZABBIX_JS_UNKNOWN', 'COM_ZABBIX_JS_MIXED', 'COM_ZABBIX_JS_MAINTENANCE',
            'COM_ZABBIX_JS_MORE', 'COM_ZABBIX_JS_UPDATED', 'COM_ZABBIX_JS_ERROR_NOT_LINKED', 'COM_ZABBIX_JS_ERROR_LOGIN',
            'COM_ZABBIX_JS_NONE', 'COM_ZABBIX_JS_AGENT', 'COM_ZABBIX_JS_SNMP', 'COM_ZABBIX_JS_IPMI', 'COM_ZABBIX_JS_JMX',
        ] as $key) {
            Text::script($key);
        }
    }

    /**
     * The browser title, with the site name before or after it as the Global Configuration asks.
     */
    public function setPortalTitle(string $title): void
    {
        $app  = Factory::getApplication();
        $site = trim((string) $app->get('sitename', ''));

        $title = match ($site === '' ? 0 : (int) $app->get('sitename_pagetitles', 0)) {
            1       => $site . ' - ' . $title,
            2       => $title . ' - ' . $site,
            default => $title,
        };

        $this->getDocument()->setTitle($title);
    }

    public function url(string $query): string
    {
        return Route::_('index.php?option=com_zabbix&' . $query);
    }
}
