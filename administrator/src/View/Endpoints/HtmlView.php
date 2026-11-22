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
use ZabbixPortal\Component\Zabbix\Administrator\Discovery\EndpointProbe;
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


        $toolbar->linkButton('settings', 'COM_ZABBIX_SUBMENU_SETTINGS')
            ->url('index.php?option=com_zabbix&view=integrationsettings')
            ->icon('icon-cog');
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

    /**
     * The API methods the portal calls, with their documentation page and the result of the last
     * diagnostics (null before the first run).
     *
     * @return list<array{method: string, feature: string, required: bool, doc: string, result: ?array<string, mixed>}>
     */
    public function methods(): array
    {
        $results = [];

        foreach ((array) ($this->report['methods'] ?? []) as $m) {
            $results[(string) $m['method']] = $m;
        }

        $list = [];

        foreach (EndpointProbe::METHODS as $method => [$feature, , $required]) {
            $list[] = [
                'method'   => $method,
                'feature'  => $feature,
                'required' => $required,
                'doc'      => EndpointProbe::doc($method),
                'result'   => $results[$method] ?? null,
            ];
        }

        return $list;
    }

    /** The report as text for "Copy diagnostics" (names and counts only, and the user checked). */
    public function diagnostics(): string
    {
        if ($this->report === null || isset($this->report['error'])) {
            return '';
        }

        $r     = $this->report;
        $lines = [
            'com_zabbix Find Endpoints ' . gmdate('Y-m-d H:i', (int) $r['at']) . ' UTC',
            'API: ' . $r['url'],
            'Connect via: ' . (($r['via'] ?? '') !== '' ? $r['via'] : 'direct'),
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

        $m = $r['matching'] ?? null;
        $lines[] = '';
        $lines[] = 'Matching by e-mail: ' . (\is_array($m) ? $m['users'] . ' Zabbix users, ' . $m['with_email'] . ' with an e-mail, ' . $m['shared'] . ' sharing one' : 'users not readable');

        if (isset($r['user'])) {
            $u       = $r['user'];
            $lines[] = 'User check "' . $u['input'] . '": ' . ($u['joomla'] === null
                ? (($u['many'] ?? false) ? 'more than one Joomla user' : 'no Joomla user')
                : 'Joomla ' . $u['joomla']['username'] . ' -> ' . $u['result'] . ($u['zabbix'] !== '' ? ' (Zabbix ' . $u['zabbix'] . ')' : '') . ', match by ' . $u['mode'] . ', mapping ' . $u['mapping']);
        }

        $lines[] = '';
        $lines[] = 'Warnings: ' . ($r['warnings'] === [] ? 'none' : implode(', ', $r['warnings']));

        return implode("\n", $lines);
    }
}
