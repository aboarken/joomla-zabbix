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
use ZabbixPortal\Component\Zabbix\Administrator\Model\IntegrationsettingsModel;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\ValidationFailed;

/**
 * Integration Settings tasks. Every task needs POST, a valid CSRF token and core.admin.
 */
final class SettingsController extends BaseController
{
    use ComponentTrait;

    public const STATE_KEY = 'com_zabbix.edit.settings.data';

    /** The last Test Connection result, kept in the administrator's session. */
    public const STATE_TEST = 'com_zabbix.settings.test';

    private const URL = 'index.php?option=com_zabbix&view=integrationsettings';

    /** Connection tests per administrator and hour. */
    private const TESTS_PER_HOUR = 30;

    public function save(): void
    {
        $this->guard();

        $data = $this->input->post->get('jform', [], 'array');

        try {
            $this->model()->save($data);
            $this->app->setUserState(self::STATE_KEY, null);
            $this->app->setUserState(self::STATE_TEST, null);
            $this->component()->getCache()->clear();
            $this->component()->getAuditLog()->record($this->actorId(), 'settings.save', AuditLogInterface::RESULT_OK, '', $this->actorId());
            $this->app->enqueueMessage(Text::_('COM_ZABBIX_SETTINGS_SAVED'), 'success');
        } catch (ValidationFailed $e) {
            // Keep the entered values for the form, but never the token.
            unset($data['api_token']);
            $this->app->setUserState(self::STATE_KEY, $data);

            foreach ($e->getKeys() as $key) {
                $this->app->enqueueMessage(Text::_($key), 'error');
            }
        }

        $this->setRedirect(Route::_(self::URL, false));
    }

    /** Ask Zabbix for its version and the token's user, with the saved settings. */
    public function test(): void
    {
        $this->guard();

        $audit = $this->component()->getAuditLog();

        if (!$this->component()->getRateLimiter()->allow($this->actorId(), 'settings.test', self::TESTS_PER_HOUR)) {
            $this->app->enqueueMessage(Text::_('COM_ZABBIX_ERROR_TOO_MANY_TESTS'), 'warning');
            $this->setRedirect(Route::_(self::URL, false));

            return;
        }

        try {
            $result = $this->model()->testConnection();
            $this->app->setUserState(self::STATE_TEST, $result);
            $audit->record($this->actorId(), 'settings.test', AuditLogInterface::RESULT_OK, 'Zabbix ' . $result['version'], $this->actorId());
            $this->app->enqueueMessage(Text::sprintf('COM_ZABBIX_TEST_OK', $result['version'], $result['ms']), $result['version_ok'] ? 'success' : 'warning');
        } catch (ZabbixException $e) {
            $this->app->setUserState(self::STATE_TEST, ['error' => $e->getKind(), 'detail' => $e->getDetail(), 'at' => time()]);
            $audit->record($this->actorId(), 'settings.test', AuditLogInterface::RESULT_ERROR, $e->getKind(), $this->actorId());
            $this->component()->getLogger()->warning('Test Connection: ' . $e->getKind() . ($e->getDetail() !== '' ? ' (' . $e->getDetail() . ')' : ''));
            $this->app->enqueueMessage(Text::_($e->getLanguageKey()), 'error');
        }

        $this->setRedirect(Route::_(self::URL . '#zabbix-test', false));
    }

    /** Forget every cached Zabbix answer. */
    public function clearCache(): void
    {
        $this->guard();

        $this->component()->getCache()->clear();
        $this->component()->getAuditLog()->record($this->actorId(), 'settings.clearcache', AuditLogInterface::RESULT_OK, '', $this->actorId());
        $this->app->enqueueMessage(Text::_('COM_ZABBIX_CACHE_CLEARED'), 'success');
        $this->setRedirect(Route::_(self::URL, false));
    }

    private function model(): IntegrationsettingsModel
    {
        /** @var IntegrationsettingsModel $model */
        $model = $this->getModel('Integrationsettings', 'Administrator', ['ignore_request' => true]);

        return $model;
    }
}
