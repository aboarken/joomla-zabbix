<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\View\Usermap;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapping;
use ZabbixPortal\Component\Zabbix\Administrator\Model\UsermapModel;

final class HtmlView extends BaseHtmlView
{
    public Form $form;

    public ?UserMapping $mapping = null;

    /** @var array<string, mixed>|null */
    public ?array $access = null;

    public ?ZabbixException $error = null;

    public function display($tpl = null): void
    {
        /** @var UsermapModel $model */
        $model = $this->getModel();

        $this->form    = $model->getForm();
        $this->mapping = $model->getMapping();
        $this->access  = $model->effectiveAccess();
        $this->error   = $model->error;

        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        ToolbarHelper::title(Text::_('COM_ZABBIX_MAPPING_EDIT_TITLE'), 'users');

        $toolbar = $this->getDocument()->getToolbar();
        $toolbar->save('usermap.save');
        $toolbar->cancel('usermap.cancel', 'JTOOLBAR_CLOSE');

        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_zabbix');
        $assets->useStyle('com_zabbix.admin');

        parent::display($tpl);
    }
}
