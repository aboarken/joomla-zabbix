<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Api\CurlTransport;
use ZabbixPortal\Component\Zabbix\Administrator\Api\TransportOptions;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;

/**
 * Real cURL, no server: a refused connection must become a ZabbixException (NETWORK), never a
 * PHP error.
 */
final class CurlTransportTest extends TestCase
{
    public function testRefusedConnectionIsANetworkError(): void
    {
        try {
            (new CurlTransport())->post('http://127.0.0.1:9/api_jsonrpc.php', [], '{}', new TransportOptions(1, 2, true, '', ''));
            self::fail('No exception');
        } catch (ZabbixException $e) {
            self::assertSame(ZabbixException::NETWORK, $e->getKind());
            self::assertStringStartsWith('cURL ', $e->getDetail());
        }
    }

    public function testConnectViaToARefusedAddressIsANetworkError(): void
    {
        $this->expectException(ZabbixException::class);

        (new CurlTransport())->post('http://zabbix.invalid/api_jsonrpc.php', [], '{}', new TransportOptions(1, 2, true, '', 'zabbix.invalid:80:127.0.0.1:9'));
    }

    public function testOnlyHttpAddressesAreUsed(): void
    {
        try {
            (new CurlTransport())->post('file:///etc/passwd', [], '{}', new TransportOptions());
            self::fail('No exception');
        } catch (ZabbixException $e) {
            self::assertSame(ZabbixException::NOT_CONFIGURED, $e->getKind());
        }
    }
}
