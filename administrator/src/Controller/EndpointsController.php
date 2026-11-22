<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use Joomla\Database\DatabaseInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Audit\AuditLogInterface;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMatcher;

/**
 * Find Endpoints: run the diagnostics (with an optional user check) and keep the report in the
 * administrator's session only.
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
            $settings = $this->component()->getSettingsRepository()->load();
            $report   = $this->component()->getEndpointProbe()->run() + ['via' => $settings->connectVia()];
            $who      = mb_substr(trim($this->input->post->getString('user', '')), 0, 150);

            if ($who !== '') {
                $report['user'] = $this->userCheck($who, $settings->matchMode());
            }

            if (!$settings->verifiesTls() && str_starts_with(strtolower((string) $report['url']), 'https://')) {
                $report['warnings'][] = 'TLS_OFF';
            }
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

    /**
     * Which Zabbix user a Joomla user (username or e-mail) would be matched to now, with the
     * Zabbix users as they are now (not cached), and their stored mapping.
     *
     * @return array<string, mixed>
     */
    private function userCheck(string $who, string $mode): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name', 'username', 'email']))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('username') . ' = :username OR ' . $db->quoteName('email') . ' = :email')
            ->bind(':username', $who)
            ->bind(':email', $who)
            ->setLimit(2);
        $rows = $db->setQuery($query)->loadAssocList();

        if (\count($rows) !== 1) {
            return ['input' => $who, 'joomla' => null, 'many' => \count($rows) > 1];
        }

        $joomla    = $rows[0];
        $directory = $this->component()->getUserDirectory();
        $directory->refresh();
        [$result, $user] = (new UserMatcher())->match((string) $joomla['username'], (string) $joomla['email'], $directory->all(), $mode);
        $mapping   = $this->component()->getUserMapRepository()->find((int) $joomla['id']);

        return [
            'input'   => $who,
            'joomla'  => ['id' => (int) $joomla['id'], 'name' => (string) $joomla['name'], 'username' => (string) $joomla['username'], 'email' => (string) $joomla['email']],
            'mode'    => $mode,
            'result'  => $result,
            'zabbix'  => $user?->username ?? '',
            'mapping' => $mapping?->status ?? 'none',
            'mapped'  => (string) ($mapping?->zabbixUsername ?? ''),
        ];
    }
}
