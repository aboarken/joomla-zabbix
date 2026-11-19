<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \ZabbixPortal\Component\Zabbix\Site\View\Unmatched\HtmlView $this */

$key = match ($this->status) {
    'pending'   => 'COM_ZABBIX_UNMATCHED_PENDING',
    'suspended' => 'COM_ZABBIX_UNMATCHED_SUSPENDED',
    'ambiguous' => 'COM_ZABBIX_UNMATCHED_AMBIGUOUS',
    default     => 'COM_ZABBIX_UNMATCHED_NOT_FOUND',
};
?>
<div class="com-zabbix zbx-portal" data-zbx-root>
    <h1><?php echo Text::_('COM_ZABBIX_UNMATCHED_TITLE'); ?></h1>
    <?php if ($this->status === 'pending') : ?>
        <div class="zbx-alert zbx-alert--info" role="status"><?php echo Text::_($key); ?></div>
    <?php else : ?>
        <div class="zbx-alert zbx-alert--warning" role="alert"><?php echo Text::_($key); ?></div>
        <p><?php echo Text::_('COM_ZABBIX_UNMATCHED_HELP'); ?></p>
    <?php endif; ?>
</div>
