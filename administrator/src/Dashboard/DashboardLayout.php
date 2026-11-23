<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Dashboard;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns a dashboard.get row (with selectPages) into pages of widgets on Zabbix's grid. Pure.
 *
 * Zabbix 7.x dashboards are 72 columns wide (x 0-71, width 1-72) and up to 64 rows high, each
 * row 70 px (y 0-63, height 1-64).
 */
final class DashboardLayout
{
    public const COLUMNS = 72;
    public const ROWS    = 64;

    /**
     * @param  array<string, mixed>  $dashboard
     *
     * @return array{id: string, name: string, pages: list<array<string, mixed>>}
     */
    public static function fromApi(array $dashboard): array
    {
        $pages = [];

        foreach ((array) ($dashboard['pages'] ?? []) as $index => $page) {
            if (!\is_array($page)) {
                continue;
            }

            $widgets = [];

            foreach ((array) ($page['widgets'] ?? []) as $widget) {
                if (!\is_array($widget) || !isset($widget['widgetid'], $widget['type'])) {
                    continue;
                }

                $type  = (string) $widget['type'];
                $x     = max(0, min(self::COLUMNS - 1, (int) ($widget['x'] ?? 0)));
                $y     = max(0, min(self::ROWS - 1, (int) ($widget['y'] ?? 0)));
                $name  = trim((string) ($widget['name'] ?? ''));
                $fields = (array) ($widget['fields'] ?? []);

                $widgets[] = [
                    'id'        => (string) $widget['widgetid'],
                    'type'      => $type,
                    'title'     => $name !== '' ? $name : WidgetTypes::defaultTitle($type),
                    'x'         => $x,
                    'y'         => $y,
                    'width'     => max(1, min(self::COLUMNS - $x, (int) ($widget['width'] ?? 12))),
                    'height'    => max(1, min(self::ROWS - $y, (int) ($widget['height'] ?? 5))),
                    'hideHeader' => (string) ($widget['view_mode'] ?? '0') === '1',
                    'supported' => WidgetTypes::isSupported($type),
                    'fields'    => array_values(array_filter($fields, 'is_array')),
                ];
            }

            usort($widgets, static fn (array $a, array $b): int => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

            $pages[] = [
                'id'      => (string) ($page['dashboard_pageid'] ?? $index),
                'name'    => trim((string) ($page['name'] ?? '')),
                'widgets' => $widgets,
            ];
        }

        return [
            'id'    => (string) ($dashboard['dashboardid'] ?? ''),
            'name'  => (string) ($dashboard['name'] ?? ''),
            'pages' => $pages,
        ];
    }

    /**
     * The layout sent to the browser: everything but the widget fields (which stay on the server),
     * plus the clock settings the browser needs.
     *
     * @param  array{id: string, name: string, pages: list<array<string, mixed>>}  $layout
     * @return array<string, mixed>
     */
    public static function forBrowser(array $layout): array
    {
        foreach ($layout['pages'] as &$page) {
            foreach ($page['widgets'] as &$widget) {
                if ($widget['type'] === 'clock') {
                    $widget['clock'] = WidgetDataService::clock(new WidgetFields($widget['fields']));
                }

                unset($widget['fields']);
            }
        }

        return $layout;
    }

    /**
     * Find a widget by id.
     *
     * @param  array{pages: list<array<string, mixed>>}  $layout
     * @return array<string, mixed>|null
     */
    public static function widget(array $layout, string $widgetId): ?array
    {
        foreach ($layout['pages'] as $page) {
            foreach ($page['widgets'] as $widget) {
                if ($widget['id'] === $widgetId) {
                    return $widget;
                }
            }
        }

        return null;
    }
}
