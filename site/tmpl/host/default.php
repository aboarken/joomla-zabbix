<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \ZabbixPortal\Component\Zabbix\Site\View\Host\HtmlView $this */

$h = $this->host;
?>
<div class="com-zabbix zbx-portal" data-zbx-root>
    <a class="zbx-back" href="<?php echo $this->backUrl; ?>"><span aria-hidden="true">←</span> <?php echo Text::_('COM_ZABBIX_HOST_BACK'); ?></a>

    <?php if ($this->error !== null) : ?>
        <div class="zbx-alert zbx-alert--error" role="alert"><?php echo Text::_($this->error->getLanguageKey()); ?></div>
    <?php elseif ($h === null) : ?>
        <div class="zbx-alert zbx-alert--warning" role="alert"><?php echo Text::_('COM_ZABBIX_HOST_NOT_FOUND'); ?></div>
    <?php else : ?>
        <div class="zbx-head">
            <div>
                <h1 class="h2"><?php echo $this->escape($h['name']); ?></h1>
                <p class="zbx-head-meta">
                    <?php if ($h['host'] !== $h['name']) : ?><span><?php echo $this->escape($h['host']); ?></span><?php endif; ?>
                    <span class="zbx-pill zbx-avail-<?php echo $h['availability']; ?>"><?php echo Text::_('COM_ZABBIX_AVAIL_' . strtoupper($h['availability'])); ?></span>
                    <?php if (!$h['enabled']) : ?><span class="zbx-pill zbx-tone-muted"><?php echo Text::_('COM_ZABBIX_HOSTS_DISABLED'); ?></span><?php endif; ?>
                    <?php if ($h['maintenance']) : ?><span class="zbx-pill zbx-tone-purple"><?php echo Text::_('COM_ZABBIX_HOSTS_IN_MAINTENANCE'); ?></span><?php endif; ?>
                </p>
            </div>
            <?php if ($this->zabbixLink !== '') : ?>
                <div class="zbx-toolbar">
                    <a class="zbx-btn zbx-btn--ghost" href="<?php echo $this->escape($this->zabbixLink); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo Text::_('COM_ZABBIX_OPEN_IN_ZABBIX'); ?> <?php echo $this->icon('external'); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="zbx-cards">
            <?php for ($s = 5; $s >= 0; $s--) : ?>
                <div class="zbx-card zbx-card--sev zbx-sev-card-<?php echo $s; ?>">
                    <span class="zbx-card__value"><?php echo (int) $h['problems'][$s]; ?></span>
                    <span class="zbx-card__label"><?php echo $this->escape($this->severityName($s)); ?></span>
                </div>
            <?php endfor; ?>
        </div>

        <div class="zbx-grid-2">
            <section class="zbx-panel" aria-labelledby="zbx-info">
                <h2 class="zbx-panel__title" id="zbx-info"><?php echo Text::_('COM_ZABBIX_HOST_INFO'); ?></h2>
                <dl class="zbx-dl">
                    <dt><?php echo Text::_('COM_ZABBIX_HOSTS_GROUPS'); ?></dt>
                    <dd><?php echo $this->escape(implode(', ', array_column($h['groups'], 'name'))); ?></dd>
                    <?php if ($h['templates'] !== []) : ?>
                        <dt><?php echo Text::_('COM_ZABBIX_HOST_TEMPLATES'); ?></dt>
                        <dd><?php echo $this->escape(implode(', ', $h['templates'])); ?></dd>
                    <?php endif; ?>
                    <?php if ($h['tags'] !== []) : ?>
                        <dt><?php echo Text::_('COM_ZABBIX_HOST_TAGS'); ?></dt>
                        <dd><?php foreach ($h['tags'] as $tag) : ?><span class="zbx-tag"><?php echo $this->escape($tag['tag'] . ($tag['value'] !== '' ? ': ' . $tag['value'] : '')); ?></span> <?php endforeach; ?></dd>
                    <?php endif; ?>
                    <?php if ($h['description'] !== '') : ?>
                        <dt><?php echo Text::_('COM_ZABBIX_HOST_DESCRIPTION'); ?></dt>
                        <dd class="zbx-prewrap"><?php echo $this->escape($h['description']); ?></dd>
                    <?php endif; ?>
                </dl>
            </section>

            <section class="zbx-panel" aria-labelledby="zbx-ifaces">
                <h2 class="zbx-panel__title" id="zbx-ifaces"><?php echo Text::_('COM_ZABBIX_HOST_INTERFACES'); ?></h2>
                <?php if ($h['interfaces'] === []) : ?>
                    <p class="zbx-muted"><?php echo Text::_('COM_ZABBIX_HOST_NO_INTERFACES'); ?></p>
                <?php else : ?>
                    <ul class="zbx-list">
                        <?php foreach ($h['interfaces'] as $i) : ?>
                            <li>
                                <span class="zbx-pill zbx-avail-<?php echo $i['state']; ?>"><?php echo Text::_('COM_ZABBIX_IFACE_' . strtoupper($i['type'])); ?></span>
                                <code><?php echo $this->escape($i['address'] . ':' . $i['port']); ?></code>
                                <span class="zbx-muted"><?php echo Text::_('COM_ZABBIX_AVAIL_' . strtoupper($i['state'])); ?></span>
                                <?php if ($i['error'] !== '') : ?><div class="zbx-error-text"><?php echo $this->escape($i['error']); ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($h['inventory'] !== []) : ?>
                    <h3 class="zbx-subtitle"><?php echo Text::_('COM_ZABBIX_HOST_INVENTORY'); ?></h3>
                    <dl class="zbx-dl">
                        <?php foreach (\array_slice($h['inventory'], 0, 20, true) as $key => $value) : ?>
                            <dt><?php echo $this->escape(ucfirst(str_replace('_', ' ', $key))); ?></dt>
                            <dd><?php echo $this->escape(mb_substr($value, 0, 300)); ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </section>
        </div>

        <section class="zbx-panel" aria-labelledby="zbx-problems">
            <h2 class="zbx-panel__title" id="zbx-problems"><?php echo Text::_('COM_ZABBIX_HOST_PROBLEMS'); ?></h2>
            <?php if ($h['problemList'] === []) : ?>
                <p class="zbx-muted"><?php echo Text::_('COM_ZABBIX_HOSTS_NO_PROBLEMS'); ?></p>
            <?php else : ?>
                <div class="zbx-table-wrap">
                    <table class="zbx-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_JS_TIME'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_SEVERITY'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_JS_PROBLEM'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_JS_DURATION'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_JS_ACK'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($h['problemList'] as $p) : ?>
                            <tr>
                                <td class="zbx-nowrap"><?php echo $this->f->dateTime($p['clock']); ?></td>
                                <td><span class="zbx-sev zbx-sev-<?php echo (int) $p['severity']; ?>"><?php echo $this->escape($this->severityName($p['severity'])); ?></span></td>
                                <td><?php echo $this->escape($p['name']); ?></td>
                                <td class="zbx-nowrap"><?php echo $this->f->duration($p['duration']); ?></td>
                                <td><?php echo Text::_($p['acknowledged'] ? 'JYES' : 'JNO'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="zbx-panel" aria-labelledby="zbx-latest">
            <h2 class="zbx-panel__title" id="zbx-latest"><?php echo Text::_('COM_ZABBIX_HOST_LATEST'); ?></h2>

            <div class="zbx-chart-box" data-zbx-host-chart hidden>
                <div class="zbx-chart-head">
                    <strong data-zbx-chart-title></strong>
                    <div class="zbx-toolbar">
                        <label class="visually-hidden" for="zbx-host-period"><?php echo Text::_('COM_ZABBIX_PERIOD'); ?></label>
                        <span class="zbx-select-wrap"><select id="zbx-host-period" class="zbx-select" data-zbx-host-period>
                            <?php foreach ($this->config['periods'] as $p) : ?>
                                <option value="<?php echo $p; ?>"><?php echo Text::_('COM_ZABBIX_PERIOD_' . strtoupper($p)); ?></option>
                            <?php endforeach; ?>
                        </select></span>
                        <button type="button" class="zbx-btn zbx-btn--ghost zbx-btn--sm" data-zbx-chart-close><?php echo Text::_('JCLOSE'); ?></button>
                    </div>
                </div>
                <div class="zbx-chart" data-zbx-chart-body></div>
            </div>

            <?php if ($this->items === []) : ?>
                <p class="zbx-muted"><?php echo Text::_('COM_ZABBIX_HOST_NO_ITEMS'); ?></p>
            <?php else : ?>
                <label class="zbx-filter zbx-filter--inline" for="zbx-item-search">
                    <span><?php echo Text::_('COM_ZABBIX_HOST_FILTER_ITEMS'); ?></span>
                    <input type="search" id="zbx-item-search" class="zbx-input" data-zbx-item-search>
                </label>
                <div class="zbx-table-wrap">
                    <table class="zbx-table" data-zbx-items>
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_JS_ITEM'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_HOST_LAST_CHECK'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_JS_VALUE'); ?></th>
                                <th scope="col"><span class="visually-hidden"><?php echo Text::_('COM_ZABBIX_HOST_GRAPH'); ?></span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($this->items as $item) : ?>
                            <tr data-zbx-item-row data-name="<?php echo $this->escape(mb_strtolower($item['name'] . ' ' . $item['key'])); ?>">
                                <th scope="row"><?php echo $this->escape($item['name']); ?>
                                    <div class="zbx-muted zbx-small"><code><?php echo $this->escape($item['key']); ?></code></div></th>
                                <td class="zbx-nowrap"><?php echo $item['lastclock'] > 0 ? $this->f->dateTime($item['lastclock']) : '—'; ?></td>
                                <td><?php echo $item['state'] === 1 ? '<span class="zbx-pill zbx-tone-muted">' . Text::_('COM_ZABBIX_HOST_NOT_SUPPORTED') . '</span>' : $this->escape($item['value']); ?></td>
                                <td>
                                    <?php if ($item['numeric']) : ?>
                                        <button type="button" class="zbx-btn zbx-btn--ghost zbx-btn--sm" data-zbx-graph="<?php echo (int) $item['itemid']; ?>"
                                                data-zbx-graph-title="<?php echo $this->escape($item['name']); ?>"><?php echo Text::_('COM_ZABBIX_HOST_GRAPH'); ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <script type="application/json" data-zbx-config><?php echo json_encode($this->config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
    <?php endif; ?>
</div>
