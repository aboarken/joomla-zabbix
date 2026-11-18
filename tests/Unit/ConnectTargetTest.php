<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ConnectTarget;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\Settings;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\SettingsValidator;
use ZabbixPortal\Component\Zabbix\Administrator\Settings\ValidationFailed;

final class ConnectTargetTest extends TestCase
{
    public function testPortIsPutIntoTheAddress(): void
    {
        self::assertSame('https://zabbix.example.org:8443/api_jsonrpc.php', ConnectTarget::withPort('https://zabbix.example.org/api_jsonrpc.php', 8443));
        self::assertSame('https://zabbix.example.org:8443/zabbix/api_jsonrpc.php', ConnectTarget::withPort('https://zabbix.example.org:9000/zabbix/api_jsonrpc.php', 8443), 'Replaces a port in the address');
        self::assertSame('https://zabbix.example.org/api_jsonrpc.php', ConnectTarget::withPort('https://zabbix.example.org/api_jsonrpc.php', 0));
        self::assertSame('https://[2001:db8::1]:8443/api_jsonrpc.php', ConnectTarget::withPort('https://[2001:db8::1]/api_jsonrpc.php', 8443));

        $settings = new Settings(['zabbix_url' => 'https://zabbix.example.org', 'zabbix_port' => '8443']);
        self::assertSame('https://zabbix.example.org:8443/api_jsonrpc.php', $settings->apiUrl());
        self::assertSame('https://zabbix.example.org:8443', $settings->frontendUrl(), 'Links use the same port');
    }

    public function testConnectViaIsNormalised(): void
    {
        self::assertSame('203.0.113.10:8443', ConnectTarget::normaliseConnectVia(' 203.0.113.10:8443 ', 443));
        self::assertSame('203.0.113.10:443', ConnectTarget::normaliseConnectVia('203.0.113.10', 443), 'Port of the Zabbix address by default');
        self::assertSame('gateway.example.org:8080', ConnectTarget::normaliseConnectVia('Gateway.Example.org:8080', 443));
        self::assertSame('[2001:db8::1]:8443', ConnectTarget::normaliseConnectVia('[2001:db8::1]:8443', 443));
        self::assertSame('', ConnectTarget::normaliseConnectVia('', 443));

        foreach (['https://1.2.3.4', '1.2.3.4:99999', 'bad host', '1.2.3.4:', '[zz::1]:80', '-bad.example.org'] as $bad) {
            try {
                ConnectTarget::normaliseConnectVia($bad, 443);
                self::fail($bad . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testConnectToEntryKeepsTheZabbixHostName(): void
    {
        $settings = new Settings([
            'zabbix_url'  => 'https://zabbix.school.local',
            'zabbix_port' => '8443',
            'connect_via' => '203.0.113.10:9443',
        ]);

        self::assertSame('zabbix.school.local:8443:203.0.113.10:9443', $settings->transportOptions()->connectTo);
        self::assertSame('203.0.113.10:9443', $settings->connectVia());

        $direct = new Settings(['zabbix_url' => 'https://zabbix.school.local']);
        self::assertSame('', $direct->transportOptions()->connectTo);

        $defaultPort = new Settings(['zabbix_url' => 'http://zabbix.school.local', 'connect_via' => '203.0.113.10']);
        self::assertSame('zabbix.school.local:80:203.0.113.10:80', $defaultPort->transportOptions()->connectTo);
    }

    public function testValidatorStoresPortAndConnectVia(): void
    {
        $validator = new SettingsValidator(static fn (string $p, string $c): string => 'enc', static fn (): bool => true);
        $base      = [
            'zabbix_url' => 'https://zabbix.school.local', 'connect_timeout' => '5', 'timeout' => '20', 'refresh_seconds' => '60',
            'max_hosts' => '2000', 'cache_minutes' => '2',
        ];

        $values = $validator->validate($base + ['zabbix_port' => '8443', 'connect_via' => '203.0.113.10'], new Settings());
        self::assertSame('8443', $values['zabbix_port']);
        self::assertSame('203.0.113.10:8443', $values['connect_via'], 'Port of the address (with the Port setting) by default');

        $values = $validator->validate($base + ['zabbix_port' => '', 'connect_via' => ''], new Settings());
        self::assertSame('', $values['zabbix_port']);
        self::assertSame('', $values['connect_via']);

        foreach ([['zabbix_port' => '70000'], ['zabbix_port' => 'abc'], ['connect_via' => 'not a host']] as $bad) {
            try {
                $validator->validate($bad + $base, new Settings());
                self::fail('Accepted ' . json_encode($bad));
            } catch (ValidationFailed $e) {
                self::assertContains(isset($bad['connect_via']) ? 'COM_ZABBIX_SETTINGS_ERR_CONNECT_VIA' : 'COM_ZABBIX_SETTINGS_ERR_PORT', $e->getKeys());
            }
        }
    }
}
