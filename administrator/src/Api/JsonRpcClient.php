<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Psr\Log\LoggerInterface;

/**
 * Zabbix 7.4 JSON-RPC 2.0 client.
 *
 * - Authenticates with an API token in the "Authorization: Bearer" header (Zabbix 7.2 removed
 *   the "auth" property of the request body).
 * - apiinfo.version and user.checkAuthentication are sent without the header, as Zabbix requires.
 * - Error objects become ZabbixException with a kind (auth, permission, api, ...).
 * - Read-only: methods other than *.get and the two above are refused before anything is sent.
 */
final class JsonRpcClient
{
    /** Methods that are sent without the Authorization header. */
    public const UNAUTHENTICATED = ['apiinfo.version', 'user.checkAuthentication'];

    private int $id = 0;

    private int $calls = 0;

    private float $elapsedMs = 0.0;

    public function __construct(
        private readonly string $url,
        #[\SensitiveParameter] private readonly string $token,
        private readonly TransportInterface $transport,
        private readonly TransportOptions $options = new TransportOptions(),
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $params
     *
     * @throws ZabbixException
     */
    public function call(string $method, array $params = []): mixed
    {
        if (!self::isReadOnly($method)) {
            throw new ZabbixException(ZabbixException::PERMISSION, 'Only read methods are used', 'Refused locally: ' . $method, $method);
        }

        $authenticated = !\in_array($method, self::UNAUTHENTICATED, true);

        if ($this->url === '' || ($authenticated && $this->token === '')) {
            throw new ZabbixException(ZabbixException::NOT_CONFIGURED, 'Zabbix connection is not set up', '', $method);
        }

        $headers = [
            'Content-Type' => 'application/json-rpc',
            'Accept'       => 'application/json',
        ];

        if ($authenticated) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $body = json_encode(
            ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params, 'id' => ++$this->id],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $started = hrtime(true);

        try {
            $response = $this->transport->post($this->url, $headers, $body, $this->options);
        } catch (ZabbixException $e) {
            $this->logger?->warning(\sprintf('Zabbix %s failed: %s %s', $method, $e->getKind(), $e->getDetail()));

            throw new ZabbixException($e->getKind(), $e->getMessage(), $e->getDetail(), $method, $e);
        } finally {
            $this->calls++;
            $this->elapsedMs += (hrtime(true) - $started) / 1e6;
        }

        return $this->decode($method, $response['status'], $response['body']);
    }

    /**
     * The token's own user (user.checkAuthentication with the token).
     *
     * @return array<string, mixed>
     */
    public function tokenUser(): array
    {
        if ($this->token === '') {
            throw new ZabbixException(ZabbixException::NOT_CONFIGURED, 'Zabbix connection is not set up', '', 'user.checkAuthentication');
        }

        $user = $this->call('user.checkAuthentication', ['token' => $this->token]);

        return \is_array($user) ? $user : [];
    }

    public function version(): string
    {
        $version = $this->call('apiinfo.version', []);

        return \is_string($version) ? $version : '';
    }

    /** Number of calls sent on this page load. */
    public function callCount(): int
    {
        return $this->calls;
    }

    public function elapsedMs(): int
    {
        return (int) round($this->elapsedMs);
    }

    public function url(): string
    {
        return $this->url;
    }

    public static function isReadOnly(string $method): bool
    {
        return \in_array($method, self::UNAUTHENTICATED, true) || (bool) preg_match('/^[a-z]+\.get$/i', $method);
    }

    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'token' => '[redacted]', 'calls' => $this->calls];
    }

    private function decode(string $method, int $status, string $body): mixed
    {
        $data = json_decode($body, true);

        if ($status !== 200 && !\is_array($data)) {
            throw new ZabbixException(ZabbixException::HTTP, 'Unexpected HTTP status', 'HTTP ' . $status, $method);
        }

        if (!\is_array($data) || ($data['jsonrpc'] ?? null) !== '2.0') {
            throw new ZabbixException(ZabbixException::INVALID_RESPONSE, 'Not a Zabbix API answer', 'HTTP ' . $status . ', ' . \strlen($body) . ' bytes', $method);
        }

        if (isset($data['error']) && \is_array($data['error'])) {
            $message = (string) ($data['error']['message'] ?? '');
            $detail  = (string) ($data['error']['data'] ?? '');
            $text    = trim($message . ' ' . $detail);

            $kind = match (true) {
                (bool) preg_match('/no permissions to call|no permissions to referred object|permission denied/i', $text) => ZabbixException::PERMISSION,
                (bool) preg_match('/not authori[sz]ed|session terminated|api token expired|login name or password|account is blocked|re-login/i', $text) => ZabbixException::AUTH,
                default => ZabbixException::API,
            };

            $this->logger?->warning(\sprintf('Zabbix %s: %s', $method, ZabbixException::clean($text)));

            throw new ZabbixException($kind, 'Zabbix refused the request', $text, $method);
        }

        if (!\array_key_exists('result', $data)) {
            throw new ZabbixException(ZabbixException::INVALID_RESPONSE, 'No result in the answer', '', $method);
        }

        return $data['result'];
    }
}
