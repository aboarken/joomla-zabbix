<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Discovery;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The API part of a Zabbix user role (role.get with selectRules): whether the API is enabled and
 * which methods the allow list or deny list names ("host.get", "host.*", "*.get", "*"). Pure.
 */
final class ApiRules
{
    public const MODE_DENY  = 0;
    public const MODE_ALLOW = 1;

    /** Write methods checked to tell whether a token could change Zabbix. */
    public const WRITE_SAMPLES = [
        'host.create', 'host.update', 'host.delete', 'item.update', 'user.update', 'usergroup.update',
        'event.acknowledge', 'script.execute', 'configuration.import', 'maintenance.create', 'token.generate',
    ];

    /** @param list<string> $methods */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $mode = self::MODE_DENY,
        public readonly array $methods = []
    ) {
    }

    /** @param array<string, mixed> $rules role.get "rules" */
    public static function fromRole(array $rules): self
    {
        $methods = [];

        foreach ((array) ($rules['api'] ?? []) as $method) {
            if (\is_string($method) && $method !== '') {
                $methods[] = strtolower($method);
            }
        }

        return new self(
            (string) ($rules['api.access'] ?? '1') !== '0',
            (int) ($rules['api.mode'] ?? self::MODE_DENY) === self::MODE_ALLOW ? self::MODE_ALLOW : self::MODE_DENY,
            $methods
        );
    }

    public function allows(string $method): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $listed = false;

        foreach ($this->methods as $pattern) {
            if (self::matches($pattern, strtolower($method))) {
                $listed = true;
                break;
            }
        }

        return $this->mode === self::MODE_ALLOW ? $listed : !$listed;
    }

    /**
     * Write methods the role does not block (role rules only; the user type and object
     * permissions may still refuse them).
     *
     * @return list<string>
     */
    public function writeMethodsAllowed(): array
    {
        return array_values(array_filter(self::WRITE_SAMPLES, fn (string $m): bool => $this->allows($m)));
    }

    public static function matches(string $pattern, string $method): bool
    {
        if ($pattern === '*' || $pattern === $method) {
            return true;
        }

        [$pService, $pAction] = array_pad(explode('.', $pattern, 2), 2, '');
        [$service, $action]   = array_pad(explode('.', $method, 2), 2, '');

        return ($pService === '*' || $pService === $service) && ($pAction === '*' || $pAction === $action);
    }
}
