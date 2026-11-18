<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZabbixPortal\Component\Zabbix\Administrator\Api\JsonRpcClient;
use ZabbixPortal\Component\Zabbix\Administrator\Api\ZabbixException;
use ZabbixPortal\Component\Zabbix\Tests\Support\FakeTransport;

final class JsonRpcClientTest extends TestCase
{
    public function testSendsTheTokenAsBearerHeaderAndNeverInTheBody(): void
    {
        $fake   = new FakeTransport(['host.get' => [['hostid' => '1']]]);
        $result = $fake->client('tok3n000000000000000000000000000000000000000000000000000000000000')->call('host.get', ['output' => ['hostid']]);

        self::assertSame([['hostid' => '1']], $result);
        self::assertSame('Bearer tok3n000000000000000000000000000000000000000000000000000000000000', $fake->calls[0]['headers']['Authorization']);
        self::assertSame('application/json-rpc', $fake->calls[0]['headers']['Content-Type']);
        self::assertArrayNotHasKey('auth', $fake->calls[0]['params']);
    }

    public function testApiinfoVersionIsSentWithoutAuthorization(): void
    {
        $fake = new FakeTransport(['apiinfo.version' => '7.4.2']);

        self::assertSame('7.4.2', $fake->client()->version());
        self::assertArrayNotHasKey('Authorization', $fake->calls[0]['headers']);
    }

    public function testCheckAuthenticationPassesTheTokenAsParameterWithoutHeader(): void
    {
        $fake = new FakeTransport(['user.checkAuthentication' => ['userid' => '5', 'username' => 'portal', 'type' => 3]]);

        self::assertSame('portal', $fake->client('x1x1x1x1x1x1x1x1x1x1x1x1x1x1x1x1')->tokenUser()['username']);
        self::assertSame(['token' => 'x1x1x1x1x1x1x1x1x1x1x1x1x1x1x1x1'], $fake->calls[0]['params']);
        self::assertArrayNotHasKey('Authorization', $fake->calls[0]['headers']);
    }

    public function testWriteMethodsAreRefusedBeforeAnythingIsSent(): void
    {
        $fake = new FakeTransport();

        foreach (['host.create', 'host.update', 'event.acknowledge', 'script.execute', 'user.login'] as $method) {
            try {
                $fake->client()->call($method, []);
                self::fail($method . ' was not refused');
            } catch (ZabbixException $e) {
                self::assertSame(ZabbixException::PERMISSION, $e->getKind());
            }
        }

        self::assertSame([], $fake->calls);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function errors(): iterable
    {
        yield 'no permission' => [['code' => -32500, 'message' => 'Application error.', 'data' => 'No permissions to call "host.get".'], ZabbixException::PERMISSION];
        yield 'bad token' => [['code' => -32602, 'message' => 'Invalid params.', 'data' => 'Not authorized.'], ZabbixException::AUTH];
        yield 'expired token' => [['code' => -32500, 'message' => 'Application error.', 'data' => 'API token expired.'], ZabbixException::AUTH];
        yield 'other' => [['code' => -32602, 'message' => 'Invalid params.', 'data' => 'Invalid parameter "/1": unexpected parameter "x".'], ZabbixException::API];
    }

    /** @dataProvider errors */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrorObjectsBecomeKinds(array $error, string $kind): void
    {
        $fake = new FakeTransport(['host.get' => ['__error' => $error]]);

        try {
            $fake->client()->call('host.get', []);
            self::fail('No exception');
        } catch (ZabbixException $e) {
            self::assertSame($kind, $e->getKind());
            self::assertSame('host.get', $e->getMethod());
            self::assertSame('COM_ZABBIX_ERROR_' . strtoupper($kind), $e->getLanguageKey());
        }
    }

    public function testNonJsonAnswerIsInvalidResponseOrHttp(): void
    {
        try {
            (new FakeTransport([], 404, '<html>Not found</html>'))->client()->call('host.get', []);
            self::fail('No exception');
        } catch (ZabbixException $e) {
            self::assertSame(ZabbixException::HTTP, $e->getKind());
        }

        try {
            (new FakeTransport([], 200, '<html>login</html>'))->client()->call('host.get', []);
            self::fail('No exception');
        } catch (ZabbixException $e) {
            self::assertSame(ZabbixException::INVALID_RESPONSE, $e->getKind());
        }
    }

    public function testWithoutTokenNothingIsSent(): void
    {
        $fake = new FakeTransport();

        $this->expectException(ZabbixException::class);

        try {
            (new JsonRpcClient('https://zabbix.test/api_jsonrpc.php', '', $fake))->call('host.get', []);
        } finally {
            self::assertSame([], $fake->calls);
        }
    }

    public function testCleanRemovesTokens(): void
    {
        $clean = ZabbixException::clean("Bearer a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6abcd\n token=secret123");

        self::assertStringNotContainsString('a1b2c3d4', $clean);
        self::assertStringNotContainsString('secret123', $clean);
        self::assertStringNotContainsString("\n", $clean);
    }

    public function testDebugInfoHidesTheToken(): void
    {
        self::assertStringNotContainsString('a1b2c3', print_r((new FakeTransport())->client(), true));
    }
}
