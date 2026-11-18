<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Identity;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finds the Zabbix user of a Joomla user. Pure.
 *
 * Modes (Integration Settings → User Mappings → Match by):
 *  - username_email: the same username (case-insensitive), otherwise the same e-mail address in
 *    the Zabbix user's media;
 *  - username: only the username;
 *  - email: only the e-mail address.
 * Two or more candidates give "ambiguous": nobody is mapped until an administrator chooses.
 */
final class UserMatcher
{
    public const MATCHED   = 'matched';
    public const AMBIGUOUS = 'ambiguous';
    public const NOT_FOUND = 'not_found';

    /**
     * @param  list<DirectoryUser>  $users
     *
     * @return array{0: string, 1: ?DirectoryUser}  [result, user]
     */
    public function match(string $username, string $email, array $users, string $mode = 'username_email'): array
    {
        if ($mode !== 'email') {
            $wanted = mb_strtolower(trim($username));
            $found  = $wanted === '' ? [] : array_values(array_filter(
                $users,
                static fn (DirectoryUser $u): bool => mb_strtolower($u->username) === $wanted
            ));

            if (\count($found) === 1) {
                return [self::MATCHED, $found[0]];
            }

            if (\count($found) > 1) {
                return [self::AMBIGUOUS, null];
            }

            if ($mode === 'username') {
                return [self::NOT_FOUND, null];
            }
        }

        $wanted = self::normaliseEmail($email);

        if ($wanted === '') {
            return [self::NOT_FOUND, null];
        }

        $found = array_values(array_filter(
            $users,
            static fn (DirectoryUser $u): bool => \in_array($wanted, $u->emails, true)
        ));

        return match (\count($found)) {
            0       => [self::NOT_FOUND, null],
            1       => [self::MATCHED, $found[0]],
            default => [self::AMBIGUOUS, null],
        };
    }

    public static function normaliseEmail(string $email): string
    {
        $email = strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
