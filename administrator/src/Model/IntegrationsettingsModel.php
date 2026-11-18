<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\FormModel;
use ZabbixPortal\Component\Zabbix\Administrator\Controller\SettingsController;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\Settings;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\SettingsValidator;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\ValidationFailed;
use ZabbixPortal\Component\Zabbix\Administrator\Support\ComponentAccess;

final class IntegrationsettingsModel extends FormModel
{
    use ComponentAccess;

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_zabbix.settings', 'settings', ['control' => 'jform', 'load_data' => $loadData]);

        return $form ?: false;
    }

    protected function loadFormData()
    {
        $state = Factory::getApplication()->getUserState(SettingsController::STATE_KEY, []);
        $data  = \is_array($state) && $state !== [] ? $state : $this->settings()->toFormData();

        // Secret fields are always empty: the stored token is never sent back to the browser.
        $data['api_token']       = '';
        $data['clear_api_token'] = '0';

        return $data;
    }

    public function settings(): Settings
    {
        return $this->zabbix()->getSettingsRepository()->load();
    }

    public function secretSource(): string
    {
        return $this->zabbix()->getSecretProvider()->getSource();
    }

    public function secretEnvTooShort(): bool
    {
        return $this->zabbix()->getSecretProvider()->envTooShort();
    }

    /**
     * Validate, encrypt a new API token and store.
     *
     * @param  array<string, mixed>  $data  Raw posted jform data
     *
     * @throws ValidationFailed  carrying language keys
     */
    public function save(array $data): void
    {
        $component = $this->zabbix();
        $validator = new SettingsValidator(
            static fn (string $plain, string $context): string => $component->getKeyVault()->encrypt($plain, $context)
        );

        $component->getSettingsRepository()->save($validator->validate($data, $this->settings()));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException
     */
    public function testConnection(): array
    {
        $client  = $this->zabbix()->getClient();
        $started = hrtime(true);
        $version = $client->version();
        $ms      = (int) ((hrtime(true) - $started) / 1e6);
        $user    = $client->tokenUser();

        return [
            'error'      => null,
            'version'    => $version,
            'version_ok' => version_compare($version, '7.4', '>=') && version_compare($version, '7.5', '<'),
            'ms'         => $ms,
            'username'   => (string) ($user['username'] ?? ''),
            'type'       => (int) ($user['type'] ?? 0),
            'url'        => $client->url(),
            'at'         => time(),
        ];
    }
}
