<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \ZabbixPortal\Component\Zabbix\Site\View\Hosts\HtmlView $this */

$f     = $this->filters;
$cards = [
    'total'       => ['COM_ZABBIX_HOSTS_CARD_TOTAL', 'primary', ['availability' => '', 'problems' => '', 'status' => '']],
    'available'   => ['COM_ZABBIX_HOSTS_CARD_AVAILABLE', 'success', ['availability' => 'available']],
    'unavailable' => ['COM_ZABBIX_HOSTS_CARD_UNAVAILABLE', 'danger', ['availability' => 'unavailable']],
    'problems'    => ['COM_ZABBIX_HOSTS_CARD_PROBLEMS', 'warning', ['problems' => 'with']],
    'maintenance' => ['COM_ZABBIX_HOSTS_CARD_MAINTENANCE', 'info', ['status' => 'maintenance']],
];
$cardUrl = function (array $set) use ($f): string {
    $query = array_filter(array_merge($f, $set), static fn ($v): bool => $v !== '');

    return Route::_('index.php?option=com_zabbix&view=hosts' . ($query ? '&' . http_build_query($query) : ''));
};
?>
<div class="com-zabbix zbx-portal<?php echo $this->fullWidth ? ' zbx-full-width' : ''; ?>" data-zbx-root>
    <?php if ($this->showPageHeading) : ?>
        <h1 class="mb-4"><?php echo $this->escape($this->pageHeading); ?></h1>
    <?php endif; ?>

    <?php if ($this->error !== null) : ?>
        <div class="alert alert-danger" role="alert"><?php echo Text::_($this->error->getLanguageKey()); ?></div>
    <?php else : ?>
        <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
            <?php foreach ($cards as $key => [$label, $tone, $set]) : ?>
                <div class="col">
                    <a class="card h-100 text-decoration-none border-start border-4 border-<?php echo $tone; ?>" href="<?php echo $cardUrl($set); ?>">
                        <div class="card-body">
                            <div class="fs-3 fw-bold text-body"><?php echo (int) ($this->cards[$key] ?? 0); ?></div>
                            <div class="small text-body-secondary"><?php echo Text::_($label); ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?php echo Route::_('index.php?option=com_zabbix&view=hosts'); ?>" class="card card-body mb-4" role="search">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-6 col-lg">
                    <label class="form-label" for="zbx-search"><?php echo Text::_('COM_ZABBIX_HOSTS_SEARCH'); ?></label>
                    <input type="search" id="zbx-search" name="search" class="form-control" value="<?php echo $this->escape($f['search']); ?>" maxlength="100"
                           placeholder="<?php echo $this->escape(Text::_('COM_ZABBIX_HOSTS_SEARCH_HINT')); ?>">
                </div>
                <div class="col-12 col-md-6 col-lg">
                    <label class="form-label" for="zbx-group"><?php echo Text::_('COM_ZABBIX_HOSTS_GROUP'); ?></label>
                    <select id="zbx-group" name="group" class="form-select" data-zbx-autosubmit>
                        <option value=""><?php echo Text::_('COM_ZABBIX_HOSTS_ALL_GROUPS'); ?></option>
                        <?php foreach ($this->groups as $id => $name) : ?>
                            <option value="<?php echo (int) $id; ?>"<?php echo (string) $id === $f['group'] ? ' selected' : ''; ?>><?php echo $this->escape($name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg">
                    <label class="form-label" for="zbx-status"><?php echo Text::_('COM_ZABBIX_HOSTS_STATUS'); ?></label>
                    <select id="zbx-status" name="status" class="form-select" data-zbx-autosubmit>
                        <?php foreach (['' => 'COM_ZABBIX_HOSTS_ANY', 'enabled' => 'COM_ZABBIX_HOSTS_ENABLED', 'disabled' => 'COM_ZABBIX_HOSTS_DISABLED', 'maintenance' => 'COM_ZABBIX_HOSTS_IN_MAINTENANCE'] as $v => $k) : ?>
                            <option value="<?php echo $v; ?>"<?php echo $v === $f['status'] ? ' selected' : ''; ?>><?php echo Text::_($k); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg">
                    <label class="form-label" for="zbx-availability"><?php echo Text::_('COM_ZABBIX_HOSTS_AVAILABILITY'); ?></label>
                    <select id="zbx-availability" name="availability" class="form-select" data-zbx-autosubmit>
                        <?php foreach (['' => 'COM_ZABBIX_HOSTS_ANY', 'available' => 'COM_ZABBIX_AVAIL_AVAILABLE', 'unavailable' => 'COM_ZABBIX_AVAIL_UNAVAILABLE', 'mixed' => 'COM_ZABBIX_AVAIL_MIXED', 'unknown' => 'COM_ZABBIX_AVAIL_UNKNOWN'] as $v => $k) : ?>
                            <option value="<?php echo $v; ?>"<?php echo $v === $f['availability'] ? ' selected' : ''; ?>><?php echo Text::_($k); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg">
                    <label class="form-label" for="zbx-problems"><?php echo Text::_('COM_ZABBIX_HOSTS_PROBLEMS'); ?></label>
                    <select id="zbx-problems" name="problems" class="form-select" data-zbx-autosubmit>
                        <?php foreach (['' => 'COM_ZABBIX_HOSTS_ANY', 'with' => 'COM_ZABBIX_HOSTS_WITH_PROBLEMS', 'without' => 'COM_ZABBIX_HOSTS_WITHOUT_PROBLEMS'] as $v => $k) : ?>
                            <option value="<?php echo $v; ?>"<?php echo $v === $f['problems'] ? ' selected' : ''; ?>><?php echo Text::_($k); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_ZABBIX_HOSTS_APPLY'); ?></button>
                    <a class="btn btn-outline-secondary" href="<?php echo Route::_('index.php?option=com_zabbix&view=hosts'); ?>"><?php echo Text::_('COM_ZABBIX_HOSTS_RESET'); ?></a>
                </div>
            </div>
        </form>

        <?php if ($this->hosts === []) : ?>
            <div class="alert alert-info"><?php echo Text::_('COM_ZABBIX_HOSTS_NONE'); ?></div>
        <?php else : ?>
            <div class="card mb-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <caption class="visually-hidden"><?php echo $this->escape($this->pageHeading); ?></caption>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_HOSTS_NAME'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_HOSTS_GROUPS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_HOSTS_AVAILABILITY'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_HOSTS_STATUS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_HOSTS_PROBLEMS'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->hosts as $h) : ?>
                            <tr>
                                <th scope="row">
                                    <a href="<?php echo $this->hostUrl($h['hostid']); ?>"><?php echo $this->escape($h['name']); ?></a>
                                    <?php if ($h['host'] !== $h['name']) : ?><div class="small text-body-secondary fw-normal"><?php echo $this->escape($h['host']); ?></div><?php endif; ?>
                                </th>
                                <td><?php echo $this->escape(implode(', ', array_column($h['groups'], 'name'))); ?></td>
                                <td>
                                    <?php foreach ($h['interfaces'] as $type => $state) : ?>
                                        <span class="badge <?php echo $this->availabilityClass($state); ?>" title="<?php echo $this->escape(Text::_('COM_ZABBIX_AVAIL_' . strtoupper($state))); ?>">
                                            <?php echo Text::_('COM_ZABBIX_IFACE_' . strtoupper($type)); ?>
                                            <span class="visually-hidden"><?php echo Text::_('COM_ZABBIX_AVAIL_' . strtoupper($state)); ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if ($h['interfaces'] === []) : ?><span class="text-body-secondary">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$h['enabled']) : ?>
                                        <span class="badge text-bg-secondary"><?php echo Text::_('COM_ZABBIX_HOSTS_DISABLED'); ?></span>
                                    <?php elseif ($h['maintenance']) : ?>
                                        <span class="badge text-bg-info"><?php echo Text::_('COM_ZABBIX_HOSTS_IN_MAINTENANCE'); ?></span>
                                    <?php else : ?>
                                        <span class="badge text-bg-success"><?php echo Text::_('COM_ZABBIX_HOSTS_ENABLED'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($h['problemTotal'] === 0) : ?>
                                        <span class="text-body-secondary"><?php echo Text::_('COM_ZABBIX_HOSTS_NO_PROBLEMS'); ?></span>
                                    <?php else : ?>
                                        <?php for ($s = 5; $s >= 0; $s--) : if ($h['problems'][$s] > 0) : ?>
                                            <span class="zbx-sev zbx-sev-<?php echo $s; ?>" title="<?php echo $this->escape($this->severityName($s)); ?>">
                                                <?php echo (int) $h['problems'][$s]; ?>
                                                <span class="visually-hidden"><?php echo $this->escape($this->severityName($s)); ?></span>
                                            </span>
                                        <?php endif; endfor; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($this->pagination !== null && $this->pagination->pagesTotal > 1) : ?>
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <?php echo $this->pagination->getPagesLinks(); ?>
                    <p class="text-body-secondary mb-0"><?php echo $this->pagination->getPagesCounter(); ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
