<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Zabbix 7.4 widget types and which of them the portal draws itself.
 */
final class WidgetTypes
{
    /** type => default title (used when the widget has no name). */
    public const SUPPORTED = [
        'problems'      => 'Problems',
        'problemsbysv'  => 'Problems by severity',
        'hostavail'     => 'Host availability',
        'item'          => 'Item value',
        'gauge'         => 'Gauge',
        'itemhistory'   => 'Item history',
        'plaintext'     => 'Plain text',
        'svggraph'      => 'Graph',
        'graph'         => 'Graph (classic)',
        'piechart'      => 'Pie chart',
        'tophosts'      => 'Top hosts',
        'hostnavigator' => 'Host navigator',
        'clock'         => 'Clock',
        'systeminfo'    => 'System information',
    ];

    /** Known but not drawn: an "Open in Zabbix" placeholder is shown. */
    public const UNSUPPORTED = [
        'actionlog'     => 'Action log',
        'dataover'      => 'Data overview',
        'discovery'     => 'Discovery status',
        'favgraphs'     => 'Favorite graphs',
        'favmaps'       => 'Favorite maps',
        'geomap'        => 'Geomap',
        'honeycomb'     => 'Honeycomb',
        'hostcard'      => 'Host card',
        'itemcard'      => 'Item card',
        'itemnavigator' => 'Item navigator',
        'map'           => 'Map',
        'navtree'       => 'Map navigation tree',
        'problemhosts'  => 'Problem hosts',
        'scatterplot'   => 'Scatter plot',
        'slareport'     => 'SLA report',
        'trigover'      => 'Trigger overview',
        'url'           => 'URL',
        'web'           => 'Web monitoring',
    ];

    public static function isSupported(string $type): bool
    {
        return isset(self::SUPPORTED[$type]);
    }

    public static function defaultTitle(string $type): string
    {
        return self::SUPPORTED[$type] ?? self::UNSUPPORTED[$type] ?? ucfirst($type);
    }
}
