<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Security;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Supplies the master secret used by KeyVault. The secret is never stored in the database.
 *
 * Order:
 *  1. Environment variable ZABBIX_PORTAL_SECRET (recommended; at least 32 characters).
 *  2. The "secret" value from Joomla's configuration.php (file on disk, not the database).
 *
 * Changing the secret makes stored secrets unreadable: the administrator must enter the API token
 * again.
 */
final class SecretProvider
{
    public const ENV_NAME        = 'ZABBIX_PORTAL_SECRET';
    public const SOURCE_ENV      = 'environment';
    public const SOURCE_JOOMLA   = 'joomla_configuration';
    public const SOURCE_NONE     = 'none';
    public const MIN_ENV_LENGTH  = 32;

    /** @var \Closure(string): (string|false) */
    private \Closure $envReader;

    /**
     * @param  (callable(string): (string|false))|null  $envReader  Injected for tests
     */
    public function __construct(
        #[\SensitiveParameter] private readonly string $joomlaSecret,
        ?callable $envReader = null
    ) {
        $this->envReader = $envReader !== null
            ? \Closure::fromCallable($envReader)
            : static fn (string $name): string|false => getenv($name);
    }

    public function getSource(): string
    {
        if (\strlen($this->env()) >= self::MIN_ENV_LENGTH) {
            return self::SOURCE_ENV;
        }

        return \strlen($this->joomlaSecret) >= 16 ? self::SOURCE_JOOMLA : self::SOURCE_NONE;
    }

    /** true when the variable is set but too short to be used (the page warns about it). */
    public function envTooShort(): bool
    {
        $length = \strlen($this->env());

        return $length > 0 && $length < self::MIN_ENV_LENGTH;
    }

    public function getSecret(): string
    {
        return match ($this->getSource()) {
            self::SOURCE_ENV    => $this->env(),
            self::SOURCE_JOOMLA => $this->joomlaSecret,
            default             => throw new KeyVaultException('No encryption secret available'),
        };
    }

    public function __debugInfo(): array
    {
        return ['source' => $this->getSource()];
    }

    private function env(): string
    {
        $value = ($this->envReader)(self::ENV_NAME);

        return \is_string($value) ? trim($value) : '';
    }
}
