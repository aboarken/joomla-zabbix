<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Language\Text;
use ZabbixPortal\Component\Zabbix\Administrator\Extension\ZabbixComponent;

/**
 * Menu item option: one of the Zabbix dashboards the API token sees.
 */
final class ZabbixdashboardField extends ListField
{
    protected $type = 'Zabbixdashboard';

    protected function getOptions()
    {
        $options = parent::getOptions();

        try {
            $component = Factory::getApplication()->bootComponent('com_zabbix');

            if ($component instanceof ZabbixComponent) {
                foreach ($component->getDashboardService()->all() as $dashboard) {
                    $options[] = (object) [
                        'value' => (string) $dashboard['dashboardid'],
                        'text'  => (string) ($dashboard['name'] ?? '') . ' (#' . $dashboard['dashboardid'] . ')',
                    ];
                }
            }
        } catch (\Throwable) {
            $options[] = (object) ['value' => '', 'text' => Text::_('COM_ZABBIX_FIELD_DASHBOARDS_UNAVAILABLE'), 'disable' => true];
        }

        return $options;
    }
}
