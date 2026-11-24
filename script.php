<?php

/**
 * Installer script for com_zabbix.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface {
                private const MIN_PHP    = '8.3.0';
                private const MIN_JOOMLA = '6.0.0';

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $app = Factory::getApplication();

                    if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
                        $app->enqueueMessage(Text::sprintf('COM_ZABBIX_INSTALL_MIN_PHP', self::MIN_PHP), 'error');

                        return false;
                    }

                    if (version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
                        $app->enqueueMessage(Text::sprintf('COM_ZABBIX_INSTALL_MIN_JOOMLA', self::MIN_JOOMLA), 'error');

                        return false;
                    }

                    if (!\extension_loaded('openssl') || !\in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
                        $app->enqueueMessage(Text::_('COM_ZABBIX_INSTALL_NO_OPENSSL'), 'error');

                        return false;
                    }

                    if (!\extension_loaded('curl')) {
                        $app->enqueueMessage(Text::_('COM_ZABBIX_INSTALL_NO_CURL'), 'error');

                        return false;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'install') {
                        Factory::getApplication()->enqueueMessage(Text::_('COM_ZABBIX_INSTALL_NEXT_STEPS'), 'info');
                    }

                    // Cached Zabbix answers (users, rights, dashboards) were written by the old
                    // version: the new one reads them afresh, so a changed format or rule
                    // applies at once.
                    if ($type === 'update') {
                        try {
                            $db = Factory::getContainer()->get(DatabaseInterface::class);
                            $db->setQuery($db->createQuery()->delete($db->quoteName('#__zabbix_cache')))->execute();
                        } catch (\Throwable) {
                            // The cache expires by itself (Cache (minutes) in Integration Settings).
                        }
                    }

                    return true;
                }
            }
        );
    }
};
