<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \ZabbixPortal\Component\Zabbix\Site\View\Host\HtmlView $this */

$h = $this->host;
?>
<div class="com-zabbix zbx-portal<?php echo $this->fullWidth ? ' zbx-full-width' : ''; ?>" data-zbx-root>
    <p class="mb-3"><a href="<?php echo $this->backUrl; ?>"><span aria-hidden="true">←</span> <?php echo Text::_('COM_ZABBIX_HOST_BACK'); ?></a></p>

    <?php if ($this->error !== null) : ?>
        <div class="alert alert-danger" role="alert"><?php echo Text::_($this->error->getLanguageKey()); ?></div>
    <?php elseif ($h === null) : ?>
        <div class="alert alert-warning" role="alert"><?php echo Text::_('COM_ZABBIX_HOST_NOT_FOUND'); ?></div>
    <?php else : ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="mb-1"><?php echo $this->escape($h['name']); ?></h1>
                <p class="d-flex flex-wrap align-items-center gap-2 text-body-secondary mb-0">
                    <?php if ($h['host'] !== $h['name']) : ?><span><?php echo $this->escape($h['host']); ?></span><?php endif; ?>
                    <span class="badge <?php echo $this->availabilityClass($h['availability']); ?>"><?php echo Text::_('COM_ZABBIX_AVAIL_' . strtoupper($h['availability'])); ?></span>
                    <?php if (!$h['enabled']) : ?><span class="badge text-bg-secondary"><?php echo Text::_('COM_ZABBIX_HOSTS_DISABLED'); ?></span><?php endif; ?>
                    <?php if ($h['maintenance']) : ?><span class="badge text-bg-info"><?php echo Text::_('COM_ZABBIX_HOSTS_IN_MAINTENANCE'); ?></span><?php endif; ?>
                </p>
            </div>
            <?php if ($this->zabbixLink !== '') : ?>
                <a class="btn btn-outline-secondary" href="<?php echo $this->escape($this->zabbixLink); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo Text::_('COM_ZABBIX_OPEN_IN_ZABBIX'); ?> <span class="icon-out-2" aria-hidden="true"></span>
                </a>
            <?php endif; ?>
        </div>

        <div class="row row-cols-3 row-cols-md-6 g-2 mb-4">
            <?php for ($s = 5; $s >= 0; $s--) : ?>
                <div class="col">
                    <div class="card h-100 zbx-sev-card zbx-sev-card-<?php echo $s; ?>">
                        <div class="card-body p-2 text-center">
                            <div class="fs-4 fw-bold"><?php echo (int) $h['problems'][$s]; ?></div>
                            <div class="small text-body-secondary"><?php echo $this->escape($this->severityName($s)); ?></div>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <section class="card h-100" aria-labelledby="zbx-info">
                    <h2 class="card-header h6" id="zbx-info"><?php echo Text::_('COM_ZABBIX_HOST_INFO'); ?></h2>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_HOSTS_GROUPS'); ?></dt>
                            <dd class="col-sm-8"><?php echo $this->escape(implode(', ', array_column($h['groups'], 'name'))); ?></dd>
                            <?php if ($h['templates'] !== []) : ?>
                                <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_HOST_TEMPLATES'); ?></dt>
                                <dd class="col-sm-8"><?php echo $this->escape(implode(', ', $h['templates'])); ?></dd>
                            <?php endif; ?>
                            <?php if ($h['tags'] !== []) : ?>
                                <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_HOST_TAGS'); ?></dt>
                                <dd class="col-sm-8"><?php foreach ($h['tags'] as $tag) : ?><span class="badge text-bg-light border me-1"><?php echo $this->escape($tag['tag'] . ($tag['value'] !== '' ? ': ' . $tag['value'] : '')); ?></span><?php endforeach; ?></dd>
                            <?php endif; ?>
                            <?php if ($h['description'] !== '') : ?>
                                <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_HOST_DESCRIPTION'); ?></dt>
                                <dd class="col-sm-8 zbx-prewrap"><?php echo $this->escape($h['description']); ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </section>
            </div>

            <div class="col-lg-6">
                <section class="card h-100" aria-labelledby="zbx-ifaces">
                    <h2 class="card-header h6" id="zbx-ifaces"><?php echo Text::_('COM_ZABBIX_HOST_INTERFACES'); ?></h2>
                    <div class="card-body">
                        <?php if ($h['interfaces'] === []) : ?>
                            <p class="text-body-secondary mb-0"><?php echo Text::_('COM_ZABBIX_HOST_NO_INTERFACES'); ?></p>
                        <?php else : ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($h['interfaces'] as $i) : ?>
                                    <li class="list-group-item px-0">
                                        <span class="badge <?php echo $this->availabilityClass($i['state']); ?>"><?php echo Text::_('COM_ZABBIX_IFACE_' . strtoupper($i['type'])); ?></span>
                                        <code><?php echo $this->escape($i['address'] . ':' . $i['port']); ?></code>
                                        <span class="text-body-secondary"><?php echo Text::_('COM_ZABBIX_AVAIL_' . strtoupper($i['state'])); ?></span>
                                        <?php if ($i['error'] !== '') : ?><div class="small text-danger"><?php echo $this->escape($i['error']); ?></div><?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($h['inventory'] !== []) : ?>
                            <h3 class="h6 mt-4"><?php echo Text::_('COM_ZABBIX_HOST_INVENTORY'); ?></h3>
                            <dl class="row mb-0">
                                <?php foreach (\array_slice($h['inventory'], 0, 20, true) as $key => $value) : ?>
                                    <dt class="col-sm-4"><?php echo $this->escape(ucfirst(str_replace('_', ' ', $key))); ?></dt>
                                    <dd class="col-sm-8"><?php echo $this->escape(mb_substr($value, 0, 300)); ?></dd>
                                <?php endforeach; ?>
                            </dl>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>

        <section class="card mb-4" aria-labelledby="zbx-problems">
            <h2 class="card-header h6" id="zbx-problems"><?php echo Text::_('COM_ZABBIX_HOST_PROBLEMS'); ?></h2>
            <?php if ($h['problemList'] === []) : ?>
                <div class="card-body"><p class="text-body-secondary mb-0"><?php echo Text::_('COM_ZABBIX_HOSTS_NO_PROBLEMS'); ?></p></div>
            <?php else : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
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
                                <td class="text-nowrap"><?php echo $this->f->dateTime($p['clock']); ?></td>
                                <td><span class="zbx-sev zbx-sev-<?php echo (int) $p['severity']; ?>"><?php echo $this->escape($this->severityName($p['severity'])); ?></span></td>
                                <td><?php echo $this->escape($p['name']); ?></td>
                                <td class="text-nowrap"><?php echo $this->f->duration($p['duration']); ?></td>
                                <td><?php echo Text::_($p['acknowledged'] ? 'JYES' : 'JNO'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card mb-4" aria-labelledby="zbx-latest">
            <h2 class="card-header h6" id="zbx-latest"><?php echo Text::_('COM_ZABBIX_HOST_LATEST'); ?></h2>
            <div class="card-body">
                <div class="border rounded p-3 mb-3" data-zbx-host-chart hidden>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <strong data-zbx-chart-title></strong>
                        <div class="d-flex gap-2">
                            <label class="visually-hidden" for="zbx-host-period"><?php echo Text::_('COM_ZABBIX_PERIOD'); ?></label>
                            <select id="zbx-host-period" class="form-select form-select-sm w-auto" data-zbx-host-period>
                                <?php foreach ($this->config['periods'] as $p) : ?>
                                    <option value="<?php echo $p; ?>"><?php echo Text::_('COM_ZABBIX_PERIOD_' . strtoupper($p)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-zbx-chart-close><?php echo Text::_('JCLOSE'); ?></button>
                        </div>
                    </div>
                    <div data-zbx-chart-body></div>
                </div>

                <?php if ($this->items === []) : ?>
                    <p class="text-body-secondary mb-0"><?php echo Text::_('COM_ZABBIX_HOST_NO_ITEMS'); ?></p>
                <?php else : ?>
                    <div class="mb-3 zbx-item-filter">
                        <label class="form-label" for="zbx-item-search"><?php echo Text::_('COM_ZABBIX_HOST_FILTER_ITEMS'); ?></label>
                        <input type="search" id="zbx-item-search" class="form-control" data-zbx-item-search>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($this->items !== []) : ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" data-zbx-items>
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
                                <th scope="row" class="fw-normal"><?php echo $this->escape($item['name']); ?>
                                    <div class="small text-body-secondary"><code><?php echo $this->escape($item['key']); ?></code></div></th>
                                <td class="text-nowrap"><?php echo $item['lastclock'] > 0 ? $this->f->dateTime($item['lastclock']) : '—'; ?></td>
                                <td><?php echo $item['state'] === 1 ? '<span class="badge text-bg-secondary">' . Text::_('COM_ZABBIX_HOST_NOT_SUPPORTED') . '</span>' : $this->escape($item['value']); ?></td>
                                <td class="text-end">
                                    <?php if ($item['numeric']) : ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" data-zbx-graph="<?php echo (int) $item['itemid']; ?>"
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
