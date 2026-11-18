<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\Settings;
use ZabbixPortal\Component\Zabbix\Administrator\Support\ComponentAccess;
use ZabbixPortal\Component\Zabbix\Administrator\Support\Formatter;

/**
 * Base of the portal models: the logged-in user's Zabbix access scope, settings and formatter.
 * The display controller has already checked login, permission and the mapping.
 */
abstract class PortalModel extends BaseDatabaseModel
{
    use ComponentAccess;

    private ?AccessScope $scope = null;

    /** @throws ZabbixException */
    public function scope(): AccessScope
    {
        if ($this->scope !== null) {
            return $this->scope;
        }

        $user     = Factory::getApplication()->getIdentity();
        $identity = $this->zabbix()->getIdentityResolver()->resolve((int) $user->id, (string) $user->username, (string) $user->email);

        if ($identity->zabbixUser === null) {
            throw new ZabbixException(ZabbixException::AUTH, 'Not linked to a Zabbix user');
        }

        return $this->scope = $this->zabbix()->getAccessService()->scopeFor($identity->zabbixUser);
    }

    public function settings(): Settings
    {
        return $this->zabbix()->getSettingsRepository()->load();
    }

    public function formatter(): Formatter
    {
        $settings = $this->settings();
        $zone     = $settings->displayTimezone();

        if ($zone === '') {
            $user = Factory::getApplication()->getIdentity();
            $zone = (string) ($user?->getParam('timezone', '') ?: Factory::getApplication()->get('offset', 'UTC'));
        }

        try {
            $tz = new \DateTimeZone($zone);
        } catch (\Exception) {
            $tz = new \DateTimeZone('UTC');
        }

        return new Formatter($tz, $settings->dateFormat(), $settings->timeFormat());
    }

    /** Link into the Zabbix front end ('' when links are off). */
    public function zabbixLink(string $path): string
    {
        $base = $this->settings()->frontendUrl();

        return $base === '' ? '' : $base . '/' . ltrim($path, '/');
    }
}
