<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVault;
use ZabbixPortal\Component\Zabbix\Administrator\Security\KeyVaultException;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\Settings;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\SettingsValidator;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\ValidationFailed;

final class SettingsTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6abcd';

    private static function form(array $changes = []): array
    {
        return $changes + [
            'zabbix_url' => 'https://zabbix.example.org', 'api_token' => '', 'verify_tls' => '1', 'ca_bundle' => '',
            'connect_timeout' => '5', 'timeout' => '20', 'show_links' => '1', 'frontend_url' => '',
            'auto_match' => '1', 'match_mode' => 'username_email', 'refresh_seconds' => '60', 'max_hosts' => '2000',
            'detailed_log' => '1', 'cache_minutes' => '2', 'date_format' => 'd/m/Y', 'time_format' => 'H:i', 'display_timezone' => '',
        ];
    }

    private static function validator(?\Closure $encrypt = null): SettingsValidator
    {
        return new SettingsValidator($encrypt ?? static fn (string $p, string $c): string => 'enc(' . $c . ':' . $p . ')', static fn (string $p): bool => $p === '/etc/ca.pem');
    }

    public function testApiUrlIsNormalised(): void
    {
        self::assertSame('https://z.example.org/api_jsonrpc.php', Settings::normaliseApiUrl('https://z.example.org/'));
        self::assertSame('https://z.example.org/zabbix/api_jsonrpc.php', Settings::normaliseApiUrl('https://z.example.org/zabbix'));
        self::assertSame('https://z.example.org/api_jsonrpc.php', Settings::normaliseApiUrl('https://z.example.org/api_jsonrpc.php'));
        self::assertSame('', Settings::normaliseApiUrl('ftp://z.example.org'));
        self::assertSame('https://z.example.org', (new Settings(['zabbix_url' => 'https://z.example.org']))->frontendUrl());
        self::assertSame('', (new Settings(['zabbix_url' => 'https://z.example.org', 'show_links' => '0']))->frontendUrl());
    }

    public function testNewTokenIsEncryptedAndEmptyKeepsTheStoredOne(): void
    {
        $values = self::validator()->validate(self::form(['api_token' => self::TOKEN]), new Settings());
        self::assertSame('enc(api-token:' . self::TOKEN . ')', $values['api_token_enc']);

        $values = self::validator()->validate(self::form(), new Settings(['api_token_enc' => 'old', 'zabbix_url' => 'https://zabbix.example.org']));
        self::assertArrayNotHasKey('api_token_enc', $values);

        $values = self::validator()->validate(self::form(['clear_api_token' => '1', 'zabbix_url' => '']), new Settings(['api_token_enc' => 'old']));
        self::assertSame('', $values['api_token_enc']);
    }

    public function testTheStoredTokenIsNotSentToANewDestinationWithoutEnteringItAgain(): void
    {
        $stored = new Settings(['api_token_enc' => 'old', 'zabbix_url' => 'https://zabbix.example.org', 'verify_tls' => '1']);

        foreach ([
            'address'      => ['zabbix_url' => 'https://attacker.example.net'],
            'port'         => ['zabbix_port' => '8443'],
            'connect via'  => ['connect_via' => '203.0.113.7'],
            'tls off'      => ['verify_tls' => '0'],
            'ca bundle'    => ['ca_bundle' => '/etc/ca.pem'],
        ] as $case => $change) {
            try {
                self::validator()->validate(self::form($change), $stored);
                self::fail($case . ': the stored token would be sent to a new destination');
            } catch (ValidationFailed $e) {
                self::assertContains('COM_ZABBIX_SETTINGS_ERR_TOKEN_AGAIN', $e->getKeys(), $case);
            }

            // With the token entered again, the change is accepted.
            $values = self::validator()->validate(self::form($change + ['api_token' => self::TOKEN]), $stored);
            self::assertSame('enc(api-token:' . self::TOKEN . ')', $values['api_token_enc'], $case);
        }

        // Other settings can change without the token.
        $values = self::validator()->validate(self::form(['cache_minutes' => '10', 'zabbix_url' => 'https://zabbix.example.org/']), $stored);
        self::assertArrayNotHasKey('api_token_enc', $values);
        self::assertSame(['zabbix_url', 'connect_via'], SettingsValidator::destinationChanges($stored, new Settings(['zabbix_url' => 'https://other.example.org', 'connect_via' => '10.0.0.1'])));
    }

    public function testCaBundleMustBeAPemCertificateFile(): void
    {
        $dir = sys_get_temp_dir() . '/zbx-ca-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/secret.txt', 'DB_PASSWORD=x');
        file_put_contents($dir . '/fake.pem', 'not a certificate');

        self::assertFalse(SettingsValidator::isCaBundle($dir . '/secret.txt'), 'other files are refused (no file probing)');
        self::assertFalse(SettingsValidator::isCaBundle($dir . '/fake.pem'), 'a .pem without a certificate is refused');
        self::assertFalse(SettingsValidator::isCaBundle($dir), 'directories are refused');
        self::assertFalse(SettingsValidator::isCaBundle($dir . '/missing.pem'));

        $system = array_values(array_filter(['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'], 'is_readable'));

        if ($system !== []) {
            self::assertTrue(SettingsValidator::isCaBundle($system[0]), 'the system CA bundle is accepted');
        }

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string}> */
    public static function invalid(): iterable
    {
        yield 'url scheme' => [['zabbix_url' => 'ftp://zabbix'], 'COM_ZABBIX_SETTINGS_ERR_URL'];
        yield 'url credentials' => [['zabbix_url' => 'https://u:p@zabbix'], 'COM_ZABBIX_SETTINGS_ERR_URL'];
        yield 'url query' => [['zabbix_url' => 'https://zabbix/?a=1'], 'COM_ZABBIX_SETTINGS_ERR_URL'];
        yield 'token' => [['api_token' => 'short!'], 'COM_ZABBIX_SETTINGS_ERR_TOKEN'];
        yield 'ca' => [['ca_bundle' => '/nope.pem'], 'COM_ZABBIX_SETTINGS_ERR_CA'];
        yield 'timeouts' => [['connect_timeout' => '30', 'timeout' => '10'], 'COM_ZABBIX_SETTINGS_ERR_TIMEOUTS'];
        yield 'timeout range' => [['timeout' => '500'], 'COM_ZABBIX_SETTINGS_ERR_TIMEOUT_RANGE'];
        yield 'refresh' => [['refresh_seconds' => '7'], 'COM_ZABBIX_SETTINGS_ERR_REFRESH'];
        yield 'cache' => [['cache_minutes' => '99'], 'COM_ZABBIX_SETTINGS_ERR_CACHE'];
        yield 'zone' => [['display_timezone' => 'Mars/Base'], 'COM_ZABBIX_SETTINGS_ERR_TIMEZONE'];
        yield 'token without url' => [['zabbix_url' => '', 'api_token' => self::TOKEN], 'COM_ZABBIX_SETTINGS_ERR_URL_REQUIRED'];
    }

    /** @dataProvider invalid */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidInputIsRefused(array $changes, string $key): void
    {
        try {
            self::validator()->validate(self::form($changes), new Settings());
            self::fail('Not refused');
        } catch (ValidationFailed $e) {
            self::assertContains($key, $e->getKeys());
        }
    }

    public function testValidInputWithCaBundle(): void
    {
        $values = self::validator()->validate(self::form(['ca_bundle' => '/etc/ca.pem', 'match_mode' => 'email', 'refresh_seconds' => '0']), new Settings());

        self::assertSame('/etc/ca.pem', $values['ca_bundle']);
        self::assertSame('email', $values['match_mode']);
        self::assertSame('0', $values['refresh_seconds']);
    }

    public function testNoSecretMeansTheTokenCannotBeStored(): void
    {
        $validator = self::validator(static fn (): string => throw new KeyVaultException('none'));

        try {
            $validator->validate(self::form(['api_token' => self::TOKEN]), new Settings());
            self::fail('Not refused');
        } catch (ValidationFailed $e) {
            self::assertSame(['COM_ZABBIX_SETTINGS_ERR_NO_SECRET'], $e->getKeys());
        }
    }

    public function testKeyVaultRoundTripAndContextBinding(): void
    {
        $vault  = new KeyVault(str_repeat('s', 32));
        $cipher = $vault->encrypt(self::TOKEN, KeyVault::CONTEXT_API_TOKEN);

        self::assertStringStartsWith('v1:', $cipher);
        self::assertStringNotContainsString(self::TOKEN, $cipher);
        self::assertSame(self::TOKEN, $vault->decrypt($cipher, KeyVault::CONTEXT_API_TOKEN));
        self::assertSame(self::TOKEN, (new Settings(['api_token_enc' => $cipher]))->apiToken($vault));

        $this->expectException(KeyVaultException::class);
        (new KeyVault(str_repeat('t', 32)))->decrypt($cipher, KeyVault::CONTEXT_API_TOKEN);
    }

    public function testUndecryptableTokenIsNotConfigured(): void
    {
        $cipher = (new KeyVault(str_repeat('s', 32)))->encrypt(self::TOKEN, KeyVault::CONTEXT_API_TOKEN);

        try {
            (new Settings(['api_token_enc' => $cipher]))->apiToken(new KeyVault(str_repeat('x', 32)));
            self::fail('No exception');
        } catch (ZabbixException $e) {
            self::assertSame(ZabbixException::NOT_CONFIGURED, $e->getKind());
        }
    }

    public function testFormDataNeverContainsTheEncryptedToken(): void
    {
        self::assertArrayNotHasKey('api_token_enc', (new Settings(['api_token_enc' => 'secret']))->toFormData());
    }
}
