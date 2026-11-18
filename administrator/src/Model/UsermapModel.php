<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Model\FormModel;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\DirectoryUser;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\UserMapping;
use ZabbixPortal\Component\Zabbix\Administrator\Support\ComponentAccess;

final class UsermapModel extends FormModel
{
    use ComponentAccess;

    public const STATE_KEY = 'com_zabbix.edit.usermap.data';

    /** Set when the Zabbix user list could not be read. */
    public ?ZabbixException $error = null;

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_zabbix.usermap', 'usermap', ['control' => 'jform', 'load_data' => $loadData]);

        if (!$form instanceof Form) {
            return false;
        }

        if ($this->requestedUserId() > 0) {
            $form->setFieldAttribute('joomla_user_id', 'readonly', 'true');
        }

        // The Zabbix users as options of the list field.
        $options = '';

        foreach ($this->zabbixUsers() as $user) {
            $label    = $user->username . ($user->fullName() !== '' ? ' (' . $user->fullName() . ')' : '') . ($user->emails !== [] ? ' — ' . $user->emails[0] : '');
            $options .= '<option value="' . htmlspecialchars($user->userid, ENT_QUOTES | ENT_XML1) . '">'
                . htmlspecialchars($label, ENT_QUOTES | ENT_XML1) . '</option>';
        }

        // The whole field is replaced (same attributes as forms/usermap.xml, plus the options).
        $form->load(
            '<form><fieldset name="mapping"><field name="zabbix_userid" type="list" label="COM_ZABBIX_MAPPING_ZABBIX_USER"'
            . ' description="COM_ZABBIX_MAPPING_ZABBIX_USER_DESC" required="true" filter="integer"'
            . ' layout="joomla.form.field.list-fancy-select">'
            . '<option value="">COM_ZABBIX_MAPPING_SELECT_ZABBIX_USER</option>' . $options
            . '</field></fieldset></form>',
            true
        );

        return $form;
    }

    public function getMapping(): ?UserMapping
    {
        $id = $this->requestedUserId();

        return $id > 0 ? $this->zabbix()->getUserMapRepository()->find($id) : null;
    }

    /** @return list<DirectoryUser> */
    public function zabbixUsers(): array
    {
        try {
            return $this->zabbix()->getUserDirectory()->all();
        } catch (ZabbixException $e) {
            $this->error = $e;

            return [];
        }
    }

    /**
     * What the mapped Zabbix user may see in the portal: their role, host groups and dashboards.
     *
     * @return array<string, mixed>|null
     */
    public function effectiveAccess(): ?array
    {
        $mapping = $this->getMapping();

        if ($mapping === null || $mapping->zabbixUserId === null) {
            return null;
        }

        try {
            $component = $this->zabbix();
            $user      = $component->getUserDirectory()->find($mapping->zabbixUserId);

            if ($user === null) {
                return ['missing' => true];
            }

            $access = $component->getAccessService();
            $scope  = $access->scopeFor($user);
            $names  = $access->hostGroupNames();

            return [
                'missing'    => false,
                'user'       => $user,
                'scope'      => $scope,
                'readable'   => $scope->all() ? [] : array_values(array_map(static fn (string $id): string => $names[$id] ?? ('#' . $id), $scope->readGroupIds)),
                'denied'     => array_values(array_map(static fn (string $id): string => $names[$id] ?? ('#' . $id), $scope->deniedGroupIds)),
                'dashboards' => array_column($component->getDashboardService()->visibleTo($scope), 'name'),
            ];
        } catch (ZabbixException $e) {
            $this->error = $e;

            return null;
        }
    }

    protected function loadFormData()
    {
        $state = Factory::getApplication()->getUserState(self::STATE_KEY, []);

        if (\is_array($state) && $state !== []) {
            return $state;
        }

        $mapping = $this->getMapping();

        return [
            'joomla_user_id' => $this->requestedUserId() ?: '',
            'zabbix_userid'  => $mapping?->zabbixUserId ?? '',
        ];
    }

    private function requestedUserId(): int
    {
        return max(0, Factory::getApplication()->getInput()->getInt('joomla_user_id', 0));
    }

    public static function roleLabel(int $type): string
    {
        return 'COM_ZABBIX_USERTYPE_' . (\in_array($type, [AccessScope::ROLE_USER, AccessScope::ROLE_ADMIN, AccessScope::ROLE_SUPER_ADMIN], true) ? $type : 0);
    }
}
