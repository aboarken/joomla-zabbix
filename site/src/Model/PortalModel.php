<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use ZabbixPortal\Component\Zabbix\Administrator\Access\AccessScope;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Monitoring\SeverityColours;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\Settings;
use ZabbixPortal\Component\Zabbix\Administrator\Support\ComponentAccess;
use ZabbixPortal\Component\Zabbix\Administrator\Support\Formatter;

/**
 * Base of the portal models: the logged-in user's Zabbix access scope, settings and formatter.
 * DisplayController checks login, permission and the mapping first; scope() checks login and
 * the page's permission again, so a model never gives data when reached another way.
 */
abstract class PortalModel extends BaseDatabaseModel
{
    use ComponentAccess;

    private ?AccessScope $scope = null;

    /** The Joomla permission this page needs (access.xml). */
    abstract protected function permission(): string;

    /** @throws ZabbixException */
    public function scope(): AccessScope
    {
        if ($this->scope !== null) {
            return $this->scope;
        }

        $user = Factory::getApplication()->getIdentity();

        if ($user === null || $user->guest || !$user->authorise($this->permission(), 'com_zabbix')) {
            throw new NotAllowed(Text::_('COM_ZABBIX_NOT_AUTHORISED'), 403);
        }

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

    /**
     * The severity colours set in Zabbix (Zabbix's defaults when they cannot be read: colours
     * never stop a page from being shown).
     *
     * @return list<string>
     */
    public function severityColours(): array
    {
        try {
            return $this->zabbix()->getSeverityColours()->colours();
        } catch (\Throwable) {
            return SeverityColours::DEFAULTS;
        }
    }

    /** Link into the Zabbix front end ('' when links are off). */
    public function zabbixLink(string $path): string
    {
        $base = $this->settings()->frontendUrl();

        return $base === '' ? '' : $base . '/' . ltrim($path, '/');
    }
}
