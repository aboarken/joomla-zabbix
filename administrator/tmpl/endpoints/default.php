<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \ZabbixPortal\Component\Zabbix\Administrator\View\Endpoints\HtmlView $this */

$r      = $this->report;
$status = ['allowed' => 'bg-success', 'denied' => 'bg-danger', 'error' => 'bg-warning text-dark'];
?>
<form action="<?php echo Route::_('index.php?option=com_zabbix&view=endpoints'); ?>" method="post" name="adminForm" id="adminForm">
    <p><?php echo Text::_('COM_ZABBIX_ENDPOINTS_HELP'); ?></p>

    <?php if (!$this->configured) : ?>
        <div class="alert alert-info">
            <?php echo Text::_('COM_ZABBIX_ENDPOINTS_NOT_CONFIGURED'); ?>
            <a href="<?php echo Route::_('index.php?option=com_zabbix&view=integrationsettings'); ?>"><?php echo Text::_('COM_ZABBIX_SUBMENU_SETTINGS'); ?></a>
        </div>
    <?php elseif ($r === null) : ?>
        <div class="alert alert-info"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_NOT_RUN'); ?></div>
    <?php elseif (isset($r['error'])) : ?>
        <div class="alert alert-danger">
            <?php echo Text::_('COM_ZABBIX_ERROR_' . strtoupper((string) $r['error'])); ?>
            <?php if (($r['detail'] ?? '') !== '') : ?><div class="small"><code><?php echo $this->escape((string) $r['detail']); ?></code></div><?php endif; ?>
        </div>
    <?php else : ?>
        <?php foreach ($r['warnings'] as $warning) : ?>
            <div class="alert alert-warning"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_WARN_' . $warning); ?></div>
        <?php endforeach; ?>

        <div class="row">
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_SERVER'); ?></h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_SETTINGS_API_ENDPOINT'); ?></dt>
                            <dd class="col-sm-8"><code><?php echo $this->escape((string) $r['url']); ?></code></dd>
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_SETTINGS_CONNECT_VIA_STATUS'); ?></dt>
                            <dd class="col-sm-8"><?php echo ($r['via'] ?? '') !== '' ? '<code>' . $this->escape((string) $r['via']) . '</code>' : Text::_('COM_ZABBIX_SETTINGS_DIRECT'); ?></dd>
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_TEST_VERSION'); ?></dt>
                            <dd class="col-sm-8"><?php echo $this->escape((string) $r['version']); ?>
                                <?php if (!$r['version_ok']) : ?><span class="badge bg-warning text-dark"><?php echo Text::_('COM_ZABBIX_TEST_VERSION_WARN'); ?></span><?php endif; ?></dd>
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_TEST_TIME'); ?></dt>
                            <dd class="col-sm-8"><?php echo (int) $r['ms']; ?> ms</dd>
                            <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_CHECKED_AT'); ?></dt>
                            <dd class="col-sm-8"><?php echo HTMLHelper::_('date', '@' . (int) $r['at'], Text::_('DATE_FORMAT_LC6')); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-body">
                        <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_TOKEN'); ?></h2>
                        <?php if (!$r['token']['ok']) : ?>
                            <div class="alert alert-danger mb-0"><?php echo Text::_('COM_ZABBIX_ERROR_' . strtoupper((string) $r['token']['error'])); ?></div>
                        <?php else :
                            $t = $r['token']; ?>
                            <dl class="row mb-0">
                                <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_TEST_TOKEN_USER'); ?></dt>
                                <dd class="col-sm-8"><?php echo $this->escape((string) $t['username']); ?></dd>
                                <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_ACCESS_ROLE'); ?></dt>
                                <dd class="col-sm-8"><?php echo $this->escape((string) ($t['role'] ?: '#' . $t['roleid'])); ?>
                                    (<?php echo Text::_('COM_ZABBIX_USERTYPE_' . (int) $t['type']); ?>)</dd>
                                <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_API_RULES'); ?></dt>
                                <dd class="col-sm-8">
                                    <?php if ($t['rules'] === null) : ?>
                                        <span class="text-muted"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_RULES_UNKNOWN'); ?></span>
                                    <?php elseif (!$t['rules']['enabled']) : ?>
                                        <span class="badge bg-danger"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_API_DISABLED'); ?></span>
                                    <?php else : ?>
                                        <?php echo Text::_('COM_ZABBIX_ENDPOINTS_MODE_' . strtoupper($t['rules']['mode'])); ?>:
                                        <code><?php echo $this->escape($t['rules']['methods'] === [] ? '—' : implode(', ', $t['rules']['methods'])); ?></code>
                                        <?php if ($t['rules']['write'] === []) : ?>
                                            <div><span class="badge bg-success"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_READ_ONLY'); ?></span></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </dd>
                            </dl>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_METHODS'); ?></h2>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_METHOD'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_USED_FOR'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_RESULT'); ?></th>
                                <th scope="col" class="text-end"><?php echo Text::_('COM_ZABBIX_TEST_TIME'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_DETAIL'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($r['methods'] as $m) : ?>
                            <tr>
                                <th scope="row"><code><?php echo $this->escape($m['method']); ?></code>
                                    <?php if (!$m['required']) : ?><span class="small text-muted">(<?php echo Text::_('COM_ZABBIX_ENDPOINTS_OPTIONAL'); ?>)</span><?php endif; ?></th>
                                <td><?php echo Text::_('COM_ZABBIX_ENDPOINTS_FEATURE_' . $m['feature']); ?></td>
                                <td><span class="badge <?php echo $status[$m['status']]; ?>"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_STATUS_' . strtoupper($m['status'])); ?></span></td>
                                <td class="text-end"><?php echo (int) $m['ms']; ?> ms</td>
                                <td class="small"><?php echo $this->escape($m['detail']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_INVENTORY'); ?></h2>
                <div class="zbx-counts">
                    <?php foreach ($r['counts'] as $label => $count) : ?>
                        <div class="zbx-count">
                            <span class="zbx-count__value"><?php echo $count === null ? '—' : (int) $count; ?></span>
                            <span class="zbx-count__label"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_COUNT_' . $label); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h3 class="h6 mt-3"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING'); ?></h3>
                <?php $m = $r['matching'] ?? null; ?>
                <?php if (!\is_array($m)) : ?>
                    <p class="text-muted"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING_UNKNOWN'); ?></p>
                <?php else : ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING_USERS'); ?></dt>
                        <dd class="col-sm-8"><?php echo (int) $m['users']; ?></dd>
                        <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING_WITH_EMAIL'); ?></dt>
                        <dd class="col-sm-8"><?php echo (int) $m['with_email']; ?></dd>
                        <dt class="col-sm-4"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING_SHARED'); ?></dt>
                        <dd class="col-sm-8"><?php echo (int) $m['shared']; ?></dd>
                    </dl>
                <?php endif; ?>

                <h3 class="h6 mt-3"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_DASHBOARDS'); ?></h3>
                <?php if ($r['dashboards'] === []) : ?>
                    <p class="text-muted"><?php echo Text::_('COM_ZABBIX_ACCESS_NO_DASHBOARDS'); ?></p>
                <?php else : ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_DASHBOARD'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_SHARING'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_PAGES'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_WIDGETS'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($r['dashboards'] as $d) : ?>
                                <tr>
                                    <td><?php echo (int) $d['id']; ?></td>
                                    <th scope="row"><?php echo $this->escape($d['name']); ?></th>
                                    <td><?php echo Text::_($d['public'] ? 'COM_ZABBIX_ENDPOINTS_PUBLIC' : 'COM_ZABBIX_ENDPOINTS_PRIVATE'); ?></td>
                                    <td><?php echo (int) $d['pages']; ?></td>
                                    <td>
                                        <?php foreach ($d['widgets'] as $type => $n) : ?>
                                            <span class="badge <?php echo \in_array($type, $d['unsupported'], true) ? 'bg-light text-dark border' : 'bg-info text-dark'; ?>"
                                                  <?php if (\in_array($type, $d['unsupported'], true)) : ?>title="<?php echo $this->escape(Text::_('COM_ZABBIX_ENDPOINTS_NOT_DRAWN')); ?>"<?php endif; ?>>
                                                <?php echo $this->escape($type); ?> × <?php echo (int) $n; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_WIDGETS_NOTE'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_DIAGNOSTICS'); ?></h2>
                <textarea id="zbx-diagnostics" class="form-control font-monospace small" rows="10" readonly><?php echo $this->escape($this->diagnostics()); ?></textarea>
                <button type="button" class="btn btn-secondary mt-2" data-zbx-copy="#zbx-diagnostics"
                        data-zbx-copied="<?php echo $this->escape(Text::_('COM_ZABBIX_ENDPOINTS_COPIED')); ?>">
                    <span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_ZABBIX_ENDPOINTS_COPY'); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
