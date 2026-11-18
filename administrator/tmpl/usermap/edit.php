<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use ZabbixPortal\Component\Zabbix\Administrator\Model\UsermapModel;

/** @var \ZabbixPortal\Component\Zabbix\Administrator\View\Usermap\HtmlView $this */

$this->getDocument()->getWebAssetManager()
    ->useScript('keepalive')
    ->useScript('form.validate');

$mapping = $this->mapping;
$access  = $this->access;
$action  = 'index.php?option=com_zabbix&view=usermap&layout=edit'
    . ($mapping ? '&joomla_user_id=' . $mapping->joomlaUserId : '');
?>
<form action="<?php echo Route::_($action); ?>" method="post" name="adminForm" id="adminForm" class="form-validate" autocomplete="off">

    <?php if ($this->error !== null) : ?>
        <div class="alert alert-danger">
            <?php echo Text::_($this->error->getLanguageKey()); ?>
            <?php if ($this->error->getDetail() !== '') : ?><div class="small"><code><?php echo $this->escape($this->error->getDetail()); ?></code></div><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <?php if ($mapping) : ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <h2 class="h5"><?php echo Text::_('COM_ZABBIX_MAPPING_CURRENT'); ?></h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_MAPPING_STATUS'); ?></dt>
                            <dd class="col-sm-8"><?php echo Text::_('COM_ZABBIX_MAPSTATUS_' . strtoupper($mapping->status)); ?></dd>
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_MAPPING_ZABBIX_USER'); ?></dt>
                            <dd class="col-sm-8"><?php echo $this->escape((string) ($mapping->zabbixUsername ?? '—')); ?></dd>
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_MAPPING_SOURCE'); ?></dt>
                            <dd class="col-sm-8"><?php echo Text::_('COM_ZABBIX_SOURCE_' . strtoupper($mapping->source)); ?></dd>
                        </dl>
                    </div>
                </div>
            <?php endif; ?>

            <p><?php echo Text::_('COM_ZABBIX_MAPPING_EDIT_HELP'); ?></p>
            <?php echo $this->form->renderFieldset('mapping'); ?>
        </div>

        <div class="col-lg-6">
            <?php if ($access !== null) : ?>
                <div class="card mb-3 zbx-access">
                    <div class="card-body">
                        <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ACCESS_TITLE'); ?></h2>
                        <?php if ($access['missing']) : ?>
                            <div class="alert alert-warning mb-0"><?php echo Text::_('COM_ZABBIX_ACCESS_USER_MISSING'); ?></div>
                        <?php else :
                            $scope = $access['scope']; ?>
                            <dl class="row">
                                <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_ACCESS_ROLE'); ?></dt>
                                <dd class="col-sm-8"><?php echo $this->escape($access['user']->roleName); ?>
                                    (<?php echo Text::_(UsermapModel::roleLabel($scope->roleType)); ?>)</dd>
                                <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_ACCESS_STATE'); ?></dt>
                                <dd class="col-sm-8">
                                    <?php if ($scope->disabled) : ?>
                                        <span class="badge bg-danger"><?php echo Text::_('COM_ZABBIX_ACCESS_DISABLED'); ?></span>
                                    <?php else : ?>
                                        <span class="badge bg-success"><?php echo Text::_('COM_ZABBIX_ACCESS_ENABLED'); ?></span>
                                    <?php endif; ?>
                                </dd>
                            </dl>

                            <h3 class="h6"><?php echo Text::_('COM_ZABBIX_ACCESS_HOSTGROUPS'); ?></h3>
                            <?php if ($scope->all()) : ?>
                                <p><?php echo Text::_('COM_ZABBIX_ACCESS_ALL_GROUPS'); ?></p>
                            <?php elseif ($access['readable'] === []) : ?>
                                <p class="text-danger"><?php echo Text::_('COM_ZABBIX_ACCESS_NO_GROUPS'); ?></p>
                            <?php else : ?>
                                <ul class="zbx-chips"><?php foreach ($access['readable'] as $name) : ?><li><?php echo $this->escape($name); ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>

                            <?php if ($access['denied'] !== []) : ?>
                                <h3 class="h6"><?php echo Text::_('COM_ZABBIX_ACCESS_DENIED_GROUPS'); ?></h3>
                                <ul class="zbx-chips zbx-chips--deny"><?php foreach ($access['denied'] as $name) : ?><li><?php echo $this->escape($name); ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>

                            <h3 class="h6"><?php echo Text::_('COM_ZABBIX_ACCESS_DASHBOARDS'); ?></h3>
                            <?php if ($access['dashboards'] === []) : ?>
                                <p class="text-muted"><?php echo Text::_('COM_ZABBIX_ACCESS_NO_DASHBOARDS'); ?></p>
                            <?php else : ?>
                                <ul class="zbx-chips"><?php foreach ($access['dashboards'] as $name) : ?><li><?php echo $this->escape($name); ?></li><?php endforeach; ?></ul>
                            <?php endif; ?>
                            <p class="small text-muted mb-0"><?php echo Text::_('COM_ZABBIX_ACCESS_NOTE'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
