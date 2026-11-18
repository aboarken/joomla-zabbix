<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use ZabbixPortal\Component\Zabbix\Administrator\Extension\ZabbixComponent;
use ZabbixPortal\Component\Zabbix\Administrator\Identity\Identity;

/**
 * Shared by the site controllers. The Zabbix user always comes from the server-side mapping,
 * never from the request.
 */
trait PortalTrait
{
    /** @return bool false when a redirect to the login page was set */
    protected function requireLogin(): bool
    {
        $user = $this->app->getIdentity();

        if ($user !== null && !$user->guest) {
            return true;
        }

        $return = base64_encode(Uri::getInstance()->toString(['path', 'query', 'fragment']));
        $this->app->enqueueMessage(Text::_('COM_ZABBIX_LOGIN_REQUIRED'), 'warning');
        $this->setRedirect(Route::_('index.php?option=com_users&view=login&return=' . $return, false));

        return false;
    }

    /**
     * @throws \ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException when Zabbix is unavailable
     */
    protected function currentIdentity(): Identity
    {
        $user = $this->app->getIdentity();

        return $this->zabbix()->getIdentityResolver()->resolve((int) $user->id, (string) $user->username, (string) $user->email);
    }

    protected function zabbix(): ZabbixComponent
    {
        $component = $this->app->bootComponent('com_zabbix');

        if (!$component instanceof ZabbixComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }

    /** Portal pages are personal: never cached by the browser, a proxy or Joomla's page cache. */
    protected function noCache(): void
    {
        $this->app->allowCache(false);
        $this->app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private', true);
        $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
    }
}
