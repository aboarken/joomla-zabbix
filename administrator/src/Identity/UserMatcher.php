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
 *    the Zabbix user's media, or as the Zabbix username;
 *  - username: only the username;
 *  - email: only the e-mail address (media or a username that is an e-mail address).
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

        // The e-mail addresses of the user's media, or the username when it is an e-mail address
        // (common with LDAP or SAML sign-in, e.g. Microsoft Entra ID, where users have no media).
        $found = array_values(array_filter(
            $users,
            static fn (DirectoryUser $u): bool => \in_array($wanted, $u->emails, true) || self::normaliseEmail($u->username) === $wanted
        ));

        return match (\count($found)) {
            0       => [self::NOT_FOUND, null],
            1       => [self::MATCHED, $found[0]],
            default => [self::AMBIGUOUS, null],
        };
    }

    /**
     * The address in lower case, or '' when it is not one. Zabbix also accepts an address with a
     * display name, "Ahmed Moussa <a.moussa@example.com>" (CEmailValidator); the address in
     * angle brackets is used. International addresses (e.g. "dépôt@exemple.fr") are accepted.
     */
    public static function normaliseEmail(string $email): string
    {
        $email = trim($email);

        if (preg_match('/<([^<>]+)>\s*$/u', $email, $m) === 1) {
            $email = trim($m[1]);
        }

        $email = mb_strtolower($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false ? $email : '';
    }
}
