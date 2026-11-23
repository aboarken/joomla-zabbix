<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\View\Integrationsettings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use ZabbixPortal\Component\Zabbix\Administrator\Controller\SettingsController;
use ZabbixPortal\Component\Zabbix\Administrator\Model\IntegrationsettingsModel;

final class HtmlView extends BaseHtmlView
{
    public Form $form;

    public string $secretSource = '';

    public bool $secretEnvTooShort = false;

    public bool $hasToken = false;

    public string $apiUrl = '';

    public bool $verifiesTls = true;

    /**
     * Joomla allows anyone to register while new users are matched and approved automatically: a
     * newcomer could pick the username or e-mail of a Zabbix user who has not used the portal yet.
     */
    public bool $openRegistration = false;

    /** Connect via as "host:port", or ''. */
    public string $connectVia = '';

    /** @var array<string, mixed>|null the last Test Connection result */
    public ?array $test = null;

    public function display($tpl = null): void
    {
        /** @var IntegrationsettingsModel $model */
        $model    = $this->getModel();
        $settings = $model->settings();

        $this->form              = $model->getForm();
        $this->secretSource      = $model->secretSource();
        $this->secretEnvTooShort = $model->secretEnvTooShort();
        $this->hasToken          = $settings->hasToken();
        $this->apiUrl            = $settings->apiUrl();
        $this->verifiesTls       = $settings->verifiesTls();
        $this->connectVia        = $settings->connectVia();
        $this->openRegistration  = $settings->autoMatch() && $settings->autoApprove()
            && (int) ComponentHelper::getParams('com_users')->get('allowUserRegistration', 0) === 1;
        $test                    = Factory::getApplication()->getUserState(SettingsController::STATE_TEST);
        $this->test              = \is_array($test) ? $test : null;

        $this->addToolbar();

        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_zabbix');
        $assets->useStyle('com_zabbix.admin');

        parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_ZABBIX_SETTINGS_TITLE'), 'cog');

        $toolbar = $this->getDocument()->getToolbar();

        $toolbar->apply('settings.save', 'JTOOLBAR_APPLY');

        $toolbar->standardButton('test', 'COM_ZABBIX_TEST_CONNECTION')
            ->task('settings.test')
            ->icon('icon-plug')
            ->listCheck(false);

        $toolbar->standardButton('clearcache', 'COM_ZABBIX_CLEAR_CACHE')
            ->task('settings.clearCache')
            ->icon('icon-trash')
            ->listCheck(false);

        $toolbar->linkButton('close', 'JTOOLBAR_CLOSE')
            ->url('index.php')
            ->icon('icon-times');

        $toolbar->preferences('com_zabbix');
    }
}
