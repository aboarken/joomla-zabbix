<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Log;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Log\Log;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger writing to administrator/logs/com_zabbix.php through the Joomla Log API.
 *
 * Callers must pass messages that are already free of secrets (ZabbixException::clean() scrubs API messages).
 * Context arrays are deliberately ignored so request data is never dumped by accident.
 * With detailed logging off (Integration Settings → System), only warnings and errors are written.
 */
final class JoomlaLogger extends AbstractLogger
{
    public const CATEGORY = 'com_zabbix';

    private static bool $registered = false;

    public function __construct(private readonly bool $detailed = true)
    {
        if (!self::$registered) {
            Log::addLogger(
                ['text_file' => 'com_zabbix.php', 'text_entry_format' => '{DATETIME}	{PRIORITY}	{MESSAGE}'],
                Log::ALL & ~Log::DEBUG,
                [self::CATEGORY]
            );
            self::$registered = true;
        }
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $priority = match ($level) {
            LogLevel::EMERGENCY => Log::EMERGENCY,
            LogLevel::ALERT     => Log::ALERT,
            LogLevel::CRITICAL  => Log::CRITICAL,
            LogLevel::ERROR     => Log::ERROR,
            LogLevel::WARNING   => Log::WARNING,
            LogLevel::NOTICE    => Log::NOTICE,
            LogLevel::DEBUG     => Log::DEBUG,
            default             => Log::INFO,
        };

        if (!$this->detailed && $priority > Log::WARNING) {
            return;
        }

        Log::add((string) $message, $priority, self::CATEGORY);
    }
}
