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
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Utilities\ArrayHelper;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\MappingException;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\MappingManager;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMatcher;
use ZabbixPortal\Component\Zabbix\Administrator\Model\UsermapModel;

/**
 * Administrator actions on User Mappings. All tasks need POST + CSRF token + core.admin.
 */
final class UsermapController extends BaseController
{
    use ComponentTrait;

    private const LIST_URL = 'index.php?option=com_zabbix&view=usermappings';
    private const EDIT_URL = 'index.php?option=com_zabbix&view=usermap&layout=edit';

    public function add(): void
    {
        $this->guard();
        $this->app->setUserState(UsermapModel::STATE_KEY, null);
        $this->setRedirect(Route::_(self::EDIT_URL, false));
    }

    public function cancel(): void
    {
        $this->guard();
        $this->app->setUserState(UsermapModel::STATE_KEY, null);
        $this->setRedirect(Route::_(self::LIST_URL, false));
    }

    public function save(): void
    {
        $this->guard();

        $data     = $this->input->post->get('jform', [], 'array');
        $joomlaId = (int) ($data['joomla_user_id'] ?? 0);
        $zabbixId = trim((string) ($data['zabbix_userid'] ?? ''));
        $user     = $this->loadUser($joomlaId);
        $backUrl  = self::EDIT_URL . ($joomlaId > 0 ? '&joomla_user_id=' . $joomlaId : '');

        if ($user === null) {
            $this->fail(Text::_('COM_ZABBIX_MAPPING_ERR_USER'), $data, $backUrl);

            return;
        }

        try {
            $zabbixUser = $this->manager()->assign($joomlaId, (string) $user->email, $zabbixId, $this->actorId());
        } catch (MappingException $e) {
            $this->fail(Text::_($e->getMessage()), $data, $backUrl);

            return;
        } catch (ZabbixException $e) {
            $this->fail(Text::_($e->getLanguageKey()), $data, $backUrl);

            return;
        }

        $this->app->setUserState(UsermapModel::STATE_KEY, null);
        $this->app->enqueueMessage(Text::sprintf(
            'COM_ZABBIX_MAPPING_SAVED',
            $this->escape((string) $user->name),
            $this->escape($zabbixUser->username)
        ), 'success');

        $this->setRedirect(Route::_(self::EDIT_URL . '&joomla_user_id=' . $joomlaId, false));
    }

    public function confirm(): void
    {
        $this->forEachSelected(
            fn (int $id, MappingManager $manager) => $manager->confirm($id, $this->actorId()),
            'COM_ZABBIX_MAPPING_N_CONFIRMED'
        );
    }

    public function suspend(): void
    {
        $this->forEachSelected(
            fn (int $id, MappingManager $manager) => $manager->suspend($id, $this->actorId()),
            'COM_ZABBIX_MAPPING_N_SUSPENDED'
        );
    }

    public function rematch(): void
    {
        $fresh = false;

        $this->forEachSelected(function (int $id, MappingManager $manager) use (&$fresh): void {
            // Once, after the token and permission checks: match against Zabbix's users as they
            // are now, not as cached (a user just added, or given an e-mail, in Zabbix).
            if (!$fresh) {
                $this->component()->getUserDirectory()->refresh();
                $fresh = true;
            }

            $user   = $this->loadUser($id) ?? throw new MappingException('COM_ZABBIX_MAPPING_ERR_USER');
            $result = $manager->rematch($id, (string) $user->username, (string) $user->email, $this->actorId());

            // Say what was compared, and with how many Zabbix users: few users means the API
            // token's user may not see them all (it must be a Super admin).
            if ($result === UserMatcher::NOT_FOUND || $result === UserMatcher::AMBIGUOUS) {
                $this->app->enqueueMessage(Text::sprintf(
                    $result === UserMatcher::NOT_FOUND ? 'COM_ZABBIX_MAPPING_REMATCH_NOT_FOUND' : 'COM_ZABBIX_MAPPING_REMATCH_AMBIGUOUS',
                    $this->escape((string) $user->name),
                    $this->escape((string) $user->username),
                    $this->escape((string) $user->email),
                    \count($this->component()->getUserDirectory()->all()),
                    Text::_('COM_ZABBIX_SETTINGS_MATCH_' . strtoupper($this->component()->getSettingsRepository()->load()->matchMode()))
                ), 'warning');
            }
        }, 'COM_ZABBIX_MAPPING_N_REMATCHED');
    }

    public function remove(): void
    {
        $this->forEachSelected(
            fn (int $id, MappingManager $manager) => $manager->remove($id, $this->actorId()),
            'COM_ZABBIX_MAPPING_N_REMOVED'
        );
    }

    /**
     * @param  callable(int, MappingManager): void  $action
     */
    private function forEachSelected(callable $action, string $doneKey): void
    {
        $this->guard();

        $ids  = array_filter(ArrayHelper::toInteger($this->input->post->get('cid', [], 'array')), static fn (int $id) => $id > 0);
        $done = 0;

        foreach ($ids as $id) {
            try {
                $action($id, $this->manager());
                $done++;
            } catch (MappingException $e) {
                $this->app->enqueueMessage(Text::sprintf('COM_ZABBIX_MAPPING_ERR_FOR_USER', $id, Text::_($e->getMessage())), 'error');
            } catch (ZabbixException $e) {
                $this->app->enqueueMessage(Text::sprintf('COM_ZABBIX_MAPPING_ERR_FOR_USER', $id, Text::_($e->getLanguageKey())), 'error');
            }
        }

        if ($ids === []) {
            $this->app->enqueueMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');
        } elseif ($done > 0) {
            $this->app->enqueueMessage(Text::plural($doneKey, $done), 'success');
        }

        $this->setRedirect(Route::_(self::LIST_URL, false));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fail(string $message, array $data, string $url): void
    {
        $this->app->setUserState(UsermapModel::STATE_KEY, $data);
        $this->app->enqueueMessage($message, 'error');
        $this->setRedirect(Route::_($url, false));
    }

    private function loadUser(int $id): ?User
    {
        if ($id <= 0) {
            return null;
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($id);

        return $user->id > 0 ? $user : null;
    }

    private function manager(): MappingManager
    {
        return $this->component()->getMappingManager();
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
