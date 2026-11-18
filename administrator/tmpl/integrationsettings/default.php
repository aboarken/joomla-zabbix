<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use ZabbixPortal\Component\Zabbix\Administrator\Security\SecretProvider;

/** @var \ZabbixPortal\Component\Zabbix\Administrator\View\Integrationsettings\HtmlView $this */

$this->getDocument()->getWebAssetManager()
    ->useScript('keepalive')
    ->useScript('form.validate');

$secretLabel = match ($this->secretSource) {
    SecretProvider::SOURCE_ENV    => 'COM_ZABBIX_SETTINGS_SECRET_ENV',
    SecretProvider::SOURCE_JOOMLA => 'COM_ZABBIX_SETTINGS_SECRET_JOOMLA',
    default                       => 'COM_ZABBIX_SETTINGS_SECRET_NONE',
};
$test = $this->test;
?>
<form action="<?php echo Route::_('index.php?option=com_zabbix&view=integrationsettings'); ?>"
      method="post" name="adminForm" id="adminForm" class="form-validate" autocomplete="off">

    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5"><?php echo Text::_('COM_ZABBIX_SETTINGS_STATUS'); ?></h2>
            <ul class="list-unstyled mb-0">
                <li><?php echo Text::_('COM_ZABBIX_SETTINGS_API_ENDPOINT'); ?>:
                    <strong><?php echo $this->apiUrl !== '' ? $this->escape($this->apiUrl) : Text::_('COM_ZABBIX_SETTINGS_NOT_SET'); ?></strong></li>
                <li><?php echo Text::_('COM_ZABBIX_SETTINGS_CONNECT_VIA_STATUS'); ?>:
                    <strong><?php echo $this->connectVia !== '' ? $this->escape($this->connectVia) : Text::_('COM_ZABBIX_SETTINGS_DIRECT'); ?></strong></li>
                <li><?php echo Text::_('COM_ZABBIX_SETTINGS_TOKEN_STATUS'); ?>:
                    <strong><?php echo Text::_($this->hasToken ? 'COM_ZABBIX_SETTINGS_STORED' : 'COM_ZABBIX_SETTINGS_NOT_SET'); ?></strong></li>
                <li><?php echo Text::_('COM_ZABBIX_SETTINGS_SECRET_SOURCE'); ?>: <strong><?php echo Text::_($secretLabel); ?></strong></li>
            </ul>
        </div>
    </div>

    <?php if ($this->secretEnvTooShort) : ?>
        <div class="alert alert-warning"><?php echo Text::sprintf('COM_ZABBIX_SETTINGS_WARN_ENV_SHORT', SecretProvider::ENV_NAME, SecretProvider::MIN_ENV_LENGTH); ?></div>
    <?php elseif ($this->secretSource !== SecretProvider::SOURCE_ENV) : ?>
        <div class="alert alert-info"><?php echo Text::sprintf('COM_ZABBIX_SETTINGS_HINT_ENV_SECRET', SecretProvider::ENV_NAME, SecretProvider::MIN_ENV_LENGTH); ?></div>
    <?php endif; ?>

    <?php if (str_starts_with(strtolower($this->apiUrl), 'http://')) : ?>
        <div class="alert alert-warning"><?php echo Text::_('COM_ZABBIX_SETTINGS_WARN_HTTP'); ?></div>
    <?php elseif ($this->apiUrl !== '' && !$this->verifiesTls) : ?>
        <div class="alert alert-warning"><?php echo Text::_('COM_ZABBIX_SETTINGS_WARN_TLS_OFF'); ?></div>
    <?php endif; ?>

    <fieldset class="options-form">
        <legend><?php echo Text::_('COM_ZABBIX_SETTINGS_FIELDSET_CONNECTION'); ?></legend>
        <p class="small"><?php echo Text::_('COM_ZABBIX_SETTINGS_FIELDSET_CONNECTION_HELP'); ?></p>
        <?php echo $this->form->renderFieldset('connection'); ?>
    </fieldset>

    <?php if ($test !== null) : ?>
        <div class="card mb-3" id="zabbix-test">
            <div class="card-body">
                <h2 class="h5"><?php echo Text::_('COM_ZABBIX_TEST_TITLE'); ?></h2>
                <?php if (!empty($test['error'])) : ?>
                    <div class="alert alert-danger mb-0">
                        <?php echo Text::_('COM_ZABBIX_ERROR_' . strtoupper((string) $test['error'])); ?>
                        <?php if (\in_array($test['error'], ['network', 'tls', 'http', 'invalid_response'], true)) : ?>
                            <div class="small mt-1"><?php echo Text::_('COM_ZABBIX_TEST_HINT_NETWORK'); ?></div>
                        <?php endif; ?>
                        <?php if (($test['detail'] ?? '') !== '') : ?>
                            <div class="small mt-1"><code><?php echo $this->escape((string) $test['detail']); ?></code></div>
                        <?php endif; ?>
                    </div>
                <?php else : ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-3"><?php echo Text::_('COM_ZABBIX_TEST_VERSION'); ?></dt>
                        <dd class="col-sm-9"><?php echo $this->escape((string) $test['version']); ?>
                            <?php if (empty($test['version_ok'])) : ?><span class="badge bg-warning text-dark"><?php echo Text::_('COM_ZABBIX_TEST_VERSION_WARN'); ?></span><?php endif; ?></dd>
                        <dt class="col-sm-3"><?php echo Text::_('COM_ZABBIX_TEST_TOKEN_USER'); ?></dt>
                        <dd class="col-sm-9"><?php echo $this->escape((string) $test['username']); ?>
                            (<?php echo Text::_('COM_ZABBIX_USERTYPE_' . (int) $test['type']); ?>)
                            <?php if ((int) $test['type'] !== 3) : ?><span class="badge bg-warning text-dark"><?php echo Text::_('COM_ZABBIX_TEST_NOT_SUPER_ADMIN'); ?></span><?php endif; ?></dd>
                        <dt class="col-sm-3"><?php echo Text::_('COM_ZABBIX_TEST_TIME'); ?></dt>
                        <dd class="col-sm-9"><?php echo (int) $test['ms']; ?> ms</dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php foreach (['mapping', 'portal', 'system'] as $set) : ?>
        <fieldset class="options-form">
            <legend><?php echo Text::_('COM_ZABBIX_SETTINGS_FIELDSET_' . strtoupper($set)); ?></legend>
            <?php echo $this->form->renderFieldset($set); ?>
        </fieldset>
    <?php endforeach; ?>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
