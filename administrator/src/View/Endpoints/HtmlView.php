<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\View\Endpoints;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use ZabbixPortal\Component\Zabbix\Administrator\Controller\EndpointsController;
use ZabbixPortal\Component\Zabbix\Administrator\Support\ComponentAccess;

final class HtmlView extends BaseHtmlView
{
    use ComponentAccess;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public bool $configured = false;

    public function display($tpl = null): void
    {
        $report           = Factory::getApplication()->getUserState(EndpointsController::STATE_REPORT);
        $this->report     = \is_array($report) ? $report : null;
        $this->configured = $this->zabbix()->getSettingsRepository()->load()->isConfigured();

        ToolbarHelper::title(Text::_('COM_ZABBIX_ENDPOINTS_TITLE'), 'search');

        $toolbar = $this->getDocument()->getToolbar();

        if ($this->configured) {
            $toolbar->standardButton('run', 'COM_ZABBIX_ENDPOINTS_RUN')
                ->task('endpoints.run')
                ->icon('icon-search')
                ->listCheck(false);
        }

        $toolbar->linkButton('close', 'JTOOLBAR_CLOSE')
            ->url('index.php')
            ->icon('icon-times');

        $toolbar->preferences('com_zabbix');

        $assets = $this->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_zabbix');
        $assets->useStyle('com_zabbix.admin');
        $assets->useScript('com_zabbix.admin');

        parent::display($tpl);
    }

    /** The report as text for "Copy diagnostics" (names and counts only). */
    public function diagnostics(): string
    {
        if ($this->report === null || isset($this->report['error'])) {
            return '';
        }

        $r     = $this->report;
        $lines = [
            'com_zabbix Find Endpoints ' . gmdate('Y-m-d H:i', (int) $r['at']) . ' UTC',
            'API: ' . $r['url'],
            'Zabbix version: ' . $r['version'] . ' (' . $r['ms'] . ' ms)',
            'Token user: ' . ($r['token']['ok'] ? $r['token']['username'] . ', type ' . $r['token']['type'] . ', role ' . ($r['token']['role'] ?: $r['token']['roleid']) : 'refused (' . $r['token']['error'] . ')'),
            '',
            'Methods:',
        ];

        foreach ($r['methods'] as $m) {
            $lines[] = \sprintf('  %-26s %-8s %5d ms %s', $m['method'], $m['status'], $m['ms'], $m['detail']);
        }

        $lines[] = '';
        $lines[] = 'Counts:';

        foreach ($r['counts'] as $label => $count) {
            $lines[] = '  ' . strtolower($label) . ': ' . ($count ?? 'n/a');
        }

        $lines[] = '';
        $lines[] = 'Dashboards: ' . \count($r['dashboards']);

        foreach ($r['dashboards'] as $d) {
            $lines[] = '  #' . $d['id'] . ' ' . $d['name'] . ' - ' . implode(', ', array_map(static fn ($t, $n) => $t . ' x' . $n, array_keys($d['widgets']), $d['widgets']))
                . ($d['unsupported'] !== [] ? ' [not drawn: ' . implode(', ', $d['unsupported']) . ']' : '');
        }

        $lines[] = '';
        $lines[] = 'Warnings: ' . ($r['warnings'] === [] ? 'none' : implode(', ', $r['warnings']));

        return implode("\n", $lines);
    }
}
