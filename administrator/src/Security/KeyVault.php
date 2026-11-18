<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZabbixPortal\Component\Zabbix\Administrator\Security;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Encrypts the Zabbix API token at rest with AES-256-GCM.
 *
 * The encryption key is derived (HKDF-SHA256) from a master secret that never lives in the
 * database (see SecretProvider). Each ciphertext is bound to a context string through GCM
 * associated data, so a value copied to another setting fails to decrypt.
 *
 * Format: "v1:" . base64(nonce[12] . tag[16] . ciphertext)
 */
final class KeyVault
{
    public const CONTEXT_API_TOKEN = 'api-token';

    private const VERSION = 'v1';
    private const CIPHER  = 'aes-256-gcm';
    private const INFO    = 'com_zabbix/secret-encryption/v1';

    private string $key;

    public function __construct(#[\SensitiveParameter] string $masterSecret)
    {
        if (\strlen($masterSecret) < 16) {
            throw new KeyVaultException('The encryption secret is too short');
        }

        $this->key = hash_hkdf('sha256', $masterSecret, 32, self::INFO);
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext, string $context): string
    {
        $nonce = random_bytes(12);
        $tag   = '';
        $ct    = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);

        if ($ct === false || \strlen($tag) !== 16) {
            throw new KeyVaultException('Encryption failed');
        }

        return self::VERSION . ':' . base64_encode($nonce . $tag . $ct);
    }

    public function decrypt(string $blob, string $context): string
    {
        [$version, $payload] = array_pad(explode(':', $blob, 2), 2, '');

        if ($version !== self::VERSION) {
            throw new KeyVaultException('Unsupported ciphertext version');
        }

        $raw = base64_decode($payload, true);

        if ($raw === false || \strlen($raw) < 29) {
            throw new KeyVaultException('Malformed ciphertext');
        }

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $context);

        if ($plain === false) {
            throw new KeyVaultException('Decryption failed (wrong secret or context)');
        }

        return $plain;
    }

    public function __debugInfo(): array
    {
        return ['key' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('KeyVault cannot be serialised');
    }
}
