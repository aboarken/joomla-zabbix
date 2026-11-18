<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \ZabbixPortal\Component\Zabbix\Site\View\Unmatched\HtmlView $this */

$key = $this->kind === 'not_configured' ? 'COM_ZABBIX_UNAVAILABLE_NOT_CONFIGURED' : 'COM_ZABBIX_UNAVAILABLE_TEXT';
?>
<div class="com-zabbix zbx-portal" data-zbx-root>
    <h1><?php echo Text::_('COM_ZABBIX_UNAVAILABLE_TITLE'); ?></h1>
    <div class="zbx-alert zbx-alert--error" role="alert"><?php echo Text::_($key); ?></div>
</div>
