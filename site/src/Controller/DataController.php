<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Session\Session;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Dashboard\DashboardLayout;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\Period;

/**
 * JSON data for the portal pages' scripts:
 *  - task=data.widget&dashboard=ID&widget=ID[&period=1h]  one dashboard widget;
 *  - task=data.history&host=ID&item=ID&period=1h        a host's item chart.
 *
 * Same checks as the pages (login, permission, mapped Zabbix user) plus the session token, sent
 * in the X-CSRF-Token header, and every answer is limited to what the mapped Zabbix user may see.
 */
final class DataController extends BaseController
{
    use PortalTrait;

    /**
     * This controller shows no pages. Joomla gives every controller a "display" task (also used
     * for unknown tasks), which would render a portal view without the checks of
     * DisplayController; it is refused here.
     */
    public function display($cachable = false, $urlparams = [])
    {
        throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
    }

    public function widget(): void
    {
        $scope = $this->scope('zabbix.dashboard.view');

        if ($scope === null) {
            return;
        }

        $dashboardId = (string) $this->input->getUint('dashboard', 0);
        $widgetId    = (string) $this->input->getUint('widget', 0);
        $period      = $this->input->getCmd('period', '');

        try {
            $layout = $this->zabbix()->getDashboardService()->layout($scope, $dashboardId);
            $widget = $layout !== null ? DashboardLayout::widget($layout, $widgetId) : null;

            if ($widget === null) {
                $this->send(['error' => 'not_found'], 404);

                return;
            }

            $data = $this->zabbix()->getWidgetDataService()->data(
                $scope,
                $widget,
                $period !== '' && isset(Period::CHOICES[$period]) ? Period::from($period) : null
            );

            $this->send(['data' => $data]);
        } catch (ZabbixException $e) {
            $this->failed($e);
        } catch (\Throwable $e) {
            $this->crashed($e);
        }
    }

    public function history(): void
    {
        $scope = $this->scope('zabbix.hosts.view');

        if ($scope === null) {
            return;
        }

        $hostId = (string) $this->input->getUint('host', 0);
        $itemId = (string) $this->input->getUint('item', 0);

        try {
            $items = array_values(array_filter(
                $this->zabbix()->getAccessGuard()->visibleItems($scope, [$itemId]),
                static fn (array $item): bool => (string) $item['hostid'] === $hostId
            ));

            if ($items === []) {
                $this->send(['error' => 'not_found'], 404);

                return;
            }

            $period = Period::from($this->input->getCmd('period', '1h'));
            $this->send(['data' => [
                'kind'   => 'chart',
                'period' => $period->name,
                'series' => array_map(
                    static fn (array $s): array => $s + ['color' => '#2774a4'],
                    $this->zabbix()->getHistoryService()->series($items, $period)
                ),
            ]]);
        } catch (ZabbixException $e) {
            $this->failed($e);
        } catch (\Throwable $e) {
            $this->crashed($e);
        }
    }

    private function scope(string $permission): ?AccessScope
    {
        $this->noCache();
        $user = $this->app->getIdentity();

        if ($user === null || $user->guest) {
            $this->send(['error' => 'login'], 401);

            return null;
        }

        // The token comes in the X-CSRF-Token header (checkToken() reads it first), so it is
        // never part of a URL that ends up in logs or the browser history.
        if (!Session::checkToken('get') || !$user->authorise($permission, 'com_zabbix')) {
            $this->send(['error' => 'forbidden'], 403);

            return null;
        }

        try {
            $identity = $this->currentIdentity();

            if (!$identity->isMatched() || $identity->zabbixUser === null) {
                $this->send(['error' => 'not_linked'], 403);

                return null;
            }

            return $this->zabbix()->getAccessService()->scopeFor($identity->zabbixUser);
        } catch (ZabbixException $e) {
            $this->failed($e);

            return null;
        }
    }

    private function failed(ZabbixException $e): void
    {
        $this->zabbix()->getLogger()->warning('Portal data: ' . $e->getKind() . ' ' . $e->getMethod());
        $this->send(['error' => $e->getKind()], 502);
    }

    /** Anything unexpected: a JSON error without details, never an HTML error page. */
    private function crashed(\Throwable $e): void
    {
        $this->zabbix()->getLogger()->error('Portal data: ' . $e::class);
        $this->send(['error' => 'error'], 500);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload, int $status = 200): void
    {
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->setHeader('status', (string) $status, true);
        $this->app->sendHeaders();
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->app->close();
    }
}
