<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;

/**
 * Find Endpoints: run the API discovery and keep the report in the administrator's session.
 */
final class EndpointsController extends BaseController
{
    use ComponentTrait;

    public const STATE_REPORT = 'com_zabbix.endpoints.report';

    private const URL = 'index.php?option=com_zabbix&view=endpoints';

    private const RUNS_PER_HOUR = 20;

    public function run(): void
    {
        $this->guard();

        $audit = $this->component()->getAuditLog();

        if (!$this->component()->getRateLimiter()->allow($this->actorId(), 'endpoints.run', self::RUNS_PER_HOUR)) {
            $this->app->enqueueMessage(Text::_('COM_ZABBIX_ERROR_TOO_MANY_TESTS'), 'warning');
            $this->setRedirect(Route::_(self::URL, false));

            return;
        }

        try {
            $report = $this->component()->getEndpointProbe()->run()
                + ['via' => $this->component()->getSettingsRepository()->load()->connectVia()];
            $this->app->setUserState(self::STATE_REPORT, $report);
            $audit->record($this->actorId(), 'endpoints.run', AuditLogInterface::RESULT_OK, 'Zabbix ' . $report['version'], $this->actorId());
            $this->app->enqueueMessage(
                Text::_($report['warnings'] === [] ? 'COM_ZABBIX_ENDPOINTS_DONE' : 'COM_ZABBIX_ENDPOINTS_DONE_WARNINGS'),
                $report['warnings'] === [] ? 'success' : 'warning'
            );
        } catch (ZabbixException $e) {
            $this->app->setUserState(self::STATE_REPORT, ['error' => $e->getKind(), 'detail' => $e->getDetail(), 'at' => time()]);
            $audit->record($this->actorId(), 'endpoints.run', AuditLogInterface::RESULT_ERROR, $e->getKind(), $this->actorId());
            $this->app->enqueueMessage(Text::_($e->getLanguageKey()), 'error');
        }

        $this->setRedirect(Route::_(self::URL, false));
    }
}
