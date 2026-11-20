<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

/** @var \ZabbixPortal\Component\Zabbix\Administrator\View\Usermappings\HtmlView $this */

$this->getDocument()->getWebAssetManager()->useScript('multiselect');

$badge = [
    'active'    => 'bg-success',
    'pending'   => 'bg-info text-dark',
    'suspended' => 'bg-danger',
    'not_found' => 'bg-secondary',
    'ambiguous' => 'bg-warning text-dark',
];
$date = static fn (?string $v): string => $v ? HTMLHelper::_('date', $v, Text::_('DATE_FORMAT_LC5')) : '—';
?>
<form action="<?php echo Route::_('index.php?option=com_zabbix&view=usermappings'); ?>" method="post" name="adminForm" id="adminForm">
    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

    <?php if ($this->pending > 0) : ?>
        <div class="alert alert-info">
            <?php echo $this->escape(Text::plural('COM_ZABBIX_MAPPINGS_PENDING_NOTICE', $this->pending)); ?>
            <a class="alert-link" href="<?php echo Route::_('index.php?option=com_zabbix&view=usermappings&filter[status]=pending'); ?>"><?php echo Text::_('COM_ZABBIX_MAPPINGS_PENDING_SHOW'); ?></a>
        </div>
    <?php endif; ?>

    <?php if ($this->items === []) : ?>
        <div class="alert alert-info"><?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?></div>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table table-striped" id="usermapList">
            <caption class="visually-hidden"><?php echo Text::_('COM_ZABBIX_MAPPINGS_TITLE'); ?></caption>
            <thead>
                <tr>
                    <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                    <th scope="col"><?php echo Text::_('COM_ZABBIX_MAPPING_JOOMLA_USER'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ZABBIX_MAPPING_JOOMLA_EMAIL'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ZABBIX_MAPPING_ZABBIX_USER'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ZABBIX_MAPPING_STATUS'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ZABBIX_MAPPING_SOURCE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ZABBIX_MAPPING_MATCHED_AT'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_ZABBIX_MAPPING_CHECKED_AT'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) :
                $editUrl    = Route::_('index.php?option=com_zabbix&view=usermap&layout=edit&joomla_user_id=' . (int) $item->joomla_user_id);
                $hasMapping = (string) $item->status !== '';
                ?>
                <tr>
                    <td class="text-center">
                        <?php if ($hasMapping) : ?>
                            <?php echo HTMLHelper::_('grid.id', $i, (int) $item->joomla_user_id, false, 'cid', 'cb', $this->escape((string) $item->name)); ?>
                        <?php endif; ?>
                    </td>
                    <th scope="row">
                        <?php if ($item->name === null) : ?>
                            <em><?php echo Text::sprintf('COM_ZABBIX_MAPPING_DELETED_USER', (int) $item->joomla_user_id); ?></em>
                        <?php else : ?>
                            <a href="<?php echo $editUrl; ?>"><?php echo $this->escape($item->name); ?></a>
                            <div class="small text-muted"><?php echo $this->escape((string) $item->username); ?></div>
                        <?php endif; ?>
                    </th>
                    <td><?php echo $this->escape((string) $item->joomla_email); ?></td>
                    <td>
                        <?php echo $this->escape((string) ($item->zabbix_username ?? '')) ?: '—'; ?>
                        <?php if (!empty($item->zabbix_userid)) : ?>
                            <div class="small text-muted">ID <?php echo (int) $item->zabbix_userid; ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($hasMapping) : ?>
                            <span class="badge <?php echo $badge[$item->status] ?? 'bg-secondary'; ?>"><?php echo Text::_('COM_ZABBIX_MAPSTATUS_' . strtoupper($item->status)); ?></span>
                        <?php else : ?>
                            <span class="badge bg-light text-dark border"><?php echo Text::_('COM_ZABBIX_MAPSTATUS_NONE'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $hasMapping ? Text::_('COM_ZABBIX_SOURCE_' . strtoupper($item->source)) : '—'; ?></td>
                    <td><?php echo $date($item->matched_at); ?></td>
                    <td><?php echo $date($item->checked_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
