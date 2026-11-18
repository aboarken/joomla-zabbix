<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \ZabbixPortal\Component\Zabbix\Site\View\Dashboard\HtmlView $this */

$layout = $this->layout;
?>
<div class="com-zabbix zbx-portal" data-zbx-root>
    <div class="zbx-head">
        <div>
            <?php if ($this->showPageHeading) : ?>
                <h1><?php echo $this->escape($this->pageHeading); ?></h1>
            <?php endif; ?>
            <?php if ($layout !== null) : ?>
                <p class="zbx-head-meta"><?php echo $this->escape($layout['name']); ?>
                    <span class="zbx-updated" data-zbx-updated aria-live="polite"></span></p>
            <?php endif; ?>
        </div>

        <?php if ($layout !== null) : ?>
            <div class="zbx-toolbar">
                <?php if ($this->allowChoose && \count($this->dashboards) > 1) : ?>
                    <form method="get" action="<?php echo Route::_('index.php?option=com_zabbix&view=dashboard'); ?>" class="zbx-inline-form">
                        <label class="visually-hidden" for="zbx-dashboard"><?php echo Text::_('COM_ZABBIX_DASHBOARD_CHOOSE'); ?></label>
                        <select id="zbx-dashboard" name="dashboard" class="zbx-select" data-zbx-autosubmit>
                            <?php foreach ($this->dashboards as $d) : ?>
                                <option value="<?php echo (int) $d['id']; ?>"<?php echo $d['id'] === $this->currentId ? ' selected' : ''; ?>><?php echo $this->escape($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <noscript><button type="submit" class="zbx-btn zbx-btn--ghost"><?php echo Text::_('JSUBMIT'); ?></button></noscript>
                    </form>
                <?php endif; ?>

                <label class="visually-hidden" for="zbx-period"><?php echo Text::_('COM_ZABBIX_PERIOD'); ?></label>
                <select id="zbx-period" class="zbx-select" data-zbx-period>
                    <option value=""><?php echo Text::_('COM_ZABBIX_PERIOD_WIDGET'); ?></option>
                    <?php foreach ($this->config['periods'] as $p) : ?>
                        <option value="<?php echo $p; ?>"><?php echo Text::_('COM_ZABBIX_PERIOD_' . strtoupper($p)); ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="zbx-btn zbx-btn--ghost" data-zbx-refresh>
                    <span class="icon-refresh" aria-hidden="true"></span> <?php echo Text::_('COM_ZABBIX_REFRESH'); ?>
                </button>

                <?php if ($this->zabbixLink !== '') : ?>
                    <a class="zbx-btn zbx-btn--ghost" href="<?php echo $this->escape($this->zabbixLink); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo Text::_('COM_ZABBIX_OPEN_IN_ZABBIX'); ?> <span class="icon-out-2" aria-hidden="true"></span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($this->error !== null) : ?>
        <div class="zbx-alert zbx-alert--error" role="alert"><?php echo Text::_($this->error->getLanguageKey()); ?></div>
    <?php elseif ($this->denied) : ?>
        <div class="zbx-alert zbx-alert--warning" role="alert"><?php echo Text::_('COM_ZABBIX_DASHBOARD_DENIED'); ?></div>
    <?php elseif ($layout === null) : ?>
        <div class="zbx-alert zbx-alert--info"><?php echo Text::_('COM_ZABBIX_DASHBOARD_NONE'); ?></div>
    <?php else : ?>
        <?php if (\count($layout['pages']) > 1) : ?>
            <div class="zbx-tabs" role="tablist" aria-label="<?php echo $this->escape(Text::_('COM_ZABBIX_DASHBOARD_PAGES')); ?>">
                <?php foreach ($layout['pages'] as $i => $page) : ?>
                    <button type="button" role="tab" class="zbx-tab" id="zbx-tab-<?php echo $i; ?>"
                            aria-controls="zbx-page-<?php echo $i; ?>" aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>"
                            data-zbx-tab="<?php echo $i; ?>">
                        <?php echo $this->escape($page['name'] !== '' ? $page['name'] : Text::sprintf('COM_ZABBIX_DASHBOARD_PAGE_N', $i + 1)); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div data-zbx-dashboard></div>
        <script type="application/json" data-zbx-config><?php echo json_encode(['layout' => $layout] + $this->config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
        <noscript><div class="zbx-alert zbx-alert--info"><?php echo Text::_('COM_ZABBIX_DASHBOARD_NOSCRIPT'); ?></div></noscript>
    <?php endif; ?>
</div>
