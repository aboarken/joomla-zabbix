<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \ZabbixPortal\Component\Zabbix\Administrator\View\Endpoints\HtmlView $this */

// Laid out as the ServiceDesk Plus component's Find Endpoints: the form, then after a run the
// warnings, the Server and API token cards, the methods the portal uses, the user check, the
// report, and under "Advanced" the dashboards and their widgets.
$action = Route::_('index.php?option=com_zabbix&view=endpoints');
$r      = $this->report;
$ok     = $r !== null && !isset($r['error']);
$e      = fn (string $t): string => $this->escape($t);
$badge  = ['allowed' => 'bg-success', 'denied' => 'bg-danger', 'error' => 'bg-warning text-dark'];
?>
<div class="zbx-endpoints">
    <p><?php echo Text::_('COM_ZABBIX_ENDPOINTS_HELP'); ?></p>

    <?php if (!$this->configured) : ?>
        <div class="alert alert-info">
            <?php echo Text::_('COM_ZABBIX_ENDPOINTS_NOT_CONFIGURED'); ?>
            <a href="<?php echo Route::_('index.php?option=com_zabbix&view=integrationsettings'); ?>"><?php echo Text::_('COM_ZABBIX_SUBMENU_SETTINGS'); ?></a>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo $action; ?>" name="adminForm" id="adminForm" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="task" value="endpoints.run">
        <?php echo HTMLHelper::_('form.token'); ?>
        <div class="col-12 col-md-9">
            <label class="form-label" for="zbx-diag-user"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_DIAG_USER'); ?></label>
            <input class="form-control" id="zbx-diag-user" name="user" maxlength="150" autocomplete="off"
                   value="<?php echo $e((string) ($r['user']['input'] ?? '')); ?>" placeholder="jdoe, j.doe@example.com">
        </div>
        <div class="col-12 col-md-3">
            <button class="btn btn-primary w-100" type="submit" id="zbx-diag-run"<?php echo $this->configured ? '' : ' disabled'; ?>>
                <span class="icon-search" aria-hidden="true"></span> <?php echo Text::_('COM_ZABBIX_ENDPOINTS_RUN'); ?>
            </button>
        </div>
    </form>

    <?php if ($r === null) : ?>
        <?php if ($this->configured) : ?>
            <div class="alert alert-info"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_NOT_RUN'); ?></div>
        <?php endif; ?>
    <?php elseif (!$ok) : ?>
        <div class="alert alert-danger">
            <?php echo Text::_('COM_ZABBIX_ERROR_' . strtoupper((string) $r['error'])); ?>
            <?php if (($r['detail'] ?? '') !== '') : ?><div class="small"><code><?php echo $e((string) $r['detail']); ?></code></div><?php endif; ?>
        </div>
    <?php else :
        $srv = $r['server'] ?? ['https' => str_starts_with((string) $r['url'], 'https://'), 'tls' => 'verified', 'timeout' => 0, 'connect' => 0];
        $prt = $r['portal'] ?? null;
        $t   = $r['token'];
        ?>
        <?php foreach ($r['warnings'] as $warning) : ?>
            <div class="alert alert-warning"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_WARN_' . $warning); ?></div>
        <?php endforeach; ?>

        <?php // 1rem between the two cards, the same as between the cards below them. ?>
        <div class="row g-3 mb-3">
            <div class="col-lg-6">
                <section class="card h-100">
                    <div class="card-body">
                        <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_SERVER'); ?></h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_SETTINGS_API_ENDPOINT'); ?></dt>
                            <dd class="col-sm-7"><code><?php echo $e((string) $r['url']); ?></code>
                                <?php if (!$srv['https']) : ?><span class="badge bg-warning text-dark">http</span><?php endif; ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_CERT'); ?></dt>
                            <dd class="col-sm-7"><?php echo $srv['https'] ? Text::_('COM_ZABBIX_ENDPOINTS_CERT_' . strtoupper((string) $srv['tls'])) : '—'; ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_SETTINGS_CONNECT_VIA_STATUS'); ?></dt>
                            <dd class="col-sm-7"><?php echo ($r['via'] ?? '') !== '' ? '<code>' . $e((string) $r['via']) . '</code>' : Text::_('COM_ZABBIX_SETTINGS_DIRECT'); ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_TEST_VERSION'); ?></dt>
                            <dd class="col-sm-7"><?php echo $e((string) $r['version']); ?>
                                <?php if (!$r['version_ok']) : ?><span class="badge bg-warning text-dark"><?php echo Text::_('COM_ZABBIX_TEST_VERSION_WARN'); ?></span><?php endif; ?></dd>
                            <?php if ((int) $srv['timeout'] > 0) : ?>
                                <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_TIMEOUTS'); ?></dt>
                                <dd class="col-sm-7"><?php echo Text::sprintf('COM_ZABBIX_ENDPOINTS_TIMEOUTS_VALUE', (int) $srv['timeout'], (int) $srv['connect']); ?></dd>
                            <?php endif; ?>
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_TEST_TIME'); ?></dt>
                            <dd class="col-sm-7"><?php echo (int) $r['ms']; ?> ms</dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_CHECKED_AT'); ?></dt>
                            <dd class="col-sm-7"><?php echo HTMLHelper::_('date', '@' . (int) $r['at'], Text::_('DATE_FORMAT_LC6')); ?></dd>
                        </dl>
                    </div>
                </section>
            </div>
            <div class="col-lg-6">
                <section class="card h-100">
                    <div class="card-body">
                        <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_TOKEN'); ?></h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_TOKEN_STATUS'); ?></dt>
                            <dd class="col-sm-7"><?php echo $t['ok']
                                ? '<span class="badge bg-success">' . Text::_('COM_ZABBIX_ENDPOINTS_TOKEN_ACCEPTED') . '</span>'
                                : '<span class="badge bg-danger">' . Text::_('COM_ZABBIX_ERROR_' . strtoupper((string) $t['error'])) . '</span>'; ?></dd>
                            <?php if ($t['ok']) : ?>
                                <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_TEST_TOKEN_USER'); ?></dt>
                                <dd class="col-sm-7"><?php echo $e((string) $t['username']); ?></dd>
                                <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ACCESS_ROLE'); ?></dt>
                                <dd class="col-sm-7"><?php echo $e((string) ($t['role'] ?: '#' . $t['roleid'])); ?>
                                    (<?php echo Text::_('COM_ZABBIX_USERTYPE_' . (int) $t['type']); ?>)</dd>
                                <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_API_RULES'); ?></dt>
                                <dd class="col-sm-7">
                                    <?php if ($t['rules'] === null) : ?>
                                        <span class="text-muted"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_RULES_UNKNOWN'); ?></span>
                                    <?php elseif (!$t['rules']['enabled']) : ?>
                                        <span class="badge bg-danger"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_API_DISABLED'); ?></span>
                                    <?php else : ?>
                                        <?php echo Text::_('COM_ZABBIX_ENDPOINTS_MODE_' . strtoupper($t['rules']['mode'])); ?>:
                                        <code><?php echo $e($t['rules']['methods'] === [] ? '—' : implode(', ', $t['rules']['methods'])); ?></code>
                                        <?php if ($t['rules']['write'] === []) : ?><span class="badge bg-success"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_READ_ONLY'); ?></span><?php endif; ?>
                                    <?php endif; ?>
                                </dd>
                            <?php endif; ?>
                            <?php if ($prt !== null) : ?>
                                <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_SETTINGS_MATCH_MODE'); ?></dt>
                                <dd class="col-sm-7"><?php echo $prt['auto'] ? Text::_('COM_ZABBIX_SETTINGS_MATCH_' . strtoupper((string) $prt['mode'])) : Text::_('COM_ZABBIX_ENDPOINTS_MATCH_ADMIN'); ?></dd>
                                <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_SETTINGS_AUTO_APPROVE'); ?></dt>
                                <dd class="col-sm-7"><?php echo Text::_($prt['approve'] ? 'COM_ZABBIX_SETTINGS_AUTO_APPROVE_AUTO' : 'COM_ZABBIX_SETTINGS_AUTO_APPROVE_WAIT'); ?></dd>
                                <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_SETTINGS_CACHE_MINUTES'); ?></dt>
                                <dd class="col-sm-7"><?php echo (int) $prt['cache']; ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </section>
            </div>
        </div>

        <section class="card mb-3">
            <div class="card-body">
                <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_STEP1'); ?></h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
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
                        <?php foreach ($this->methods() as $m) :
                            $res = $m['result']; ?>
                            <tr>
                                <th scope="row">
                                    <code><?php echo $e($m['method']); ?></code>
                                    <a href="<?php echo $e($m['doc']); ?>" target="_blank" rel="noopener noreferrer"><span class="icon-out-2" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::sprintf('COM_ZABBIX_ENDPOINTS_DOC_LINK', $e($m['method'])); ?></span></a>
                                    <?php if (!$m['required']) : ?><span class="small text-muted">(<?php echo Text::_('COM_ZABBIX_ENDPOINTS_OPTIONAL'); ?>)</span><?php endif; ?>
                                </th>
                                <td><?php echo Text::_('COM_ZABBIX_ENDPOINTS_FEATURE_' . $m['feature']); ?></td>
                                <td><?php echo $res === null ? '—' : '<span class="badge ' . $badge[$res['status']] . '">' . Text::_('COM_ZABBIX_ENDPOINTS_STATUS_' . strtoupper($res['status'])) . '</span>'; ?></td>
                                <td class="text-end text-nowrap"><?php echo $res === null ? '' : (int) $res['ms'] . ' ms'; ?></td>
                                <td class="small"><?php echo $res === null ? '' : $e((string) $res['detail']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card mb-3">
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
                <?php $mt = $r['matching'] ?? null; ?>
                <?php if (!\is_array($mt)) : ?>
                    <p class="text-muted mb-0"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING_UNKNOWN'); ?></p>
                <?php else : ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING_USERS'); ?></dt>
                        <dd class="col-sm-7"><?php echo (int) $mt['users']; ?></dd>
                        <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING_WITH_EMAIL'); ?></dt>
                        <dd class="col-sm-7"><?php echo (int) $mt['with_email']; ?></dd>
                        <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_MATCHING_SHARED'); ?></dt>
                        <dd class="col-sm-7"><?php echo (int) $mt['shared']; ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </section>

        <?php if (isset($r['user'])) :
            $u = $r['user']; ?>
            <section class="card mb-3">
                <div class="card-body">
                    <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_USER_CHECK'); ?></h2>
                    <?php if ($u['joomla'] === null) : ?>
                        <p class="mb-0 text-danger"><?php echo Text::sprintf(($u['many'] ?? false) ? 'COM_ZABBIX_ENDPOINTS_USER_MANY' : 'COM_ZABBIX_ENDPOINTS_USER_NONE', $e((string) $u['input'])); ?></p>
                    <?php else : ?>
                        <dl class="row mb-0">
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_USER_JOOMLA'); ?></dt>
                            <dd class="col-sm-7"><?php echo $e($u['joomla']['name']); ?> (<?php echo $e($u['joomla']['username']); ?>, <?php echo $e($u['joomla']['email']); ?>)</dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_SETTINGS_MATCH_MODE'); ?></dt>
                            <dd class="col-sm-7"><?php echo Text::_('COM_ZABBIX_SETTINGS_MATCH_' . strtoupper((string) $u['mode'])); ?></dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_USER_RESULT'); ?></dt>
                            <dd class="col-sm-7 <?php echo $u['result'] === 'matched' ? '' : 'text-danger'; ?>">
                                <?php echo Text::_('COM_ZABBIX_ENDPOINTS_USER_' . strtoupper((string) $u['result'])); ?><?php echo $u['zabbix'] !== '' ? ': ' . Text::sprintf('COM_ZABBIX_ENDPOINTS_USER_ZABBIX', $e((string) $u['zabbix'])) : ''; ?>
                            </dd>
                            <dt class="col-sm-5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_USER_MAPPING'); ?></dt>
                            <dd class="col-sm-7"><?php echo Text::_('COM_ZABBIX_MAPSTATUS_' . strtoupper((string) $u['mapping'])); ?><?php echo $u['mapped'] !== '' ? ' (' . $e((string) $u['mapped']) . ')' : ''; ?>
                                · <a href="<?php echo Route::_('index.php?option=com_zabbix&view=usermap&layout=edit&id=' . (int) $u['joomla']['id']); ?>"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_USER_OPEN'); ?></a></dd>
                        </dl>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="card mb-3" id="zbx-diag">
            <div class="card-body">
                <h2 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_DIAGNOSTICS'); ?></h2>
                <textarea id="zbx-diagnostics" class="form-control font-monospace small" rows="12" readonly aria-label="<?php echo $e(Text::_('COM_ZABBIX_ENDPOINTS_DIAGNOSTICS')); ?>"><?php echo $e($this->diagnostics()); ?></textarea>
                <button type="button" class="btn btn-secondary mt-2" data-zbx-copy="#zbx-diagnostics"
                        data-zbx-copied="<?php echo $e(Text::_('COM_ZABBIX_ENDPOINTS_COPIED')); ?>">
                    <span class="icon-copy" aria-hidden="true"></span> <?php echo Text::_('COM_ZABBIX_ENDPOINTS_COPY'); ?>
                </button>
                <p class="small text-muted mt-2 mb-0"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_DIAG_PRIVACY'); ?></p>
            </div>
        </section>

        <details class="card mb-3" id="zbx-advanced">
            <summary class="card-header h5 mb-0"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_ADVANCED'); ?></summary>
            <div class="card-body">
                <h3 class="h5"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_DASHBOARDS'); ?></h3>
                <?php if ($r['dashboards'] === []) : ?>
                    <p class="text-muted mb-0"><?php echo Text::_('COM_ZABBIX_ACCESS_NO_DASHBOARDS'); ?></p>
                <?php else : ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-sm align-middle">
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
                                    <th scope="row"><?php echo $e($d['name']); ?></th>
                                    <td><?php echo Text::_($d['public'] ? 'COM_ZABBIX_ENDPOINTS_PUBLIC' : 'COM_ZABBIX_ENDPOINTS_PRIVATE'); ?></td>
                                    <td><?php echo (int) $d['pages']; ?></td>
                                    <td>
                                        <?php foreach ($d['widgets'] as $type => $n) :
                                            $drawn = !\in_array($type, $d['unsupported'], true); ?>
                                            <span class="badge <?php echo $drawn ? 'bg-info text-dark' : 'bg-light text-dark border'; ?>"<?php echo $drawn ? '' : ' title="' . $e(Text::_('COM_ZABBIX_ENDPOINTS_NOT_DRAWN')) . '"'; ?>><?php echo $e((string) $type); ?> × <?php echo (int) $n; ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mb-0"><?php echo Text::_('COM_ZABBIX_ENDPOINTS_WIDGETS_NOTE'); ?></p>
                <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>
</div>
