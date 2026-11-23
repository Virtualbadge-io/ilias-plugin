<?php

declare(strict_types=1);

/**
 * This file is part of the Virtualbadge.io plugin for ILIAS.
 *
 * @copyright 2026 FutureNext GmbH {@link https://www.virtualbadge.io}
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace Virtualbadge\Core\Config;

/**
 * Encrypts secrets such as API keys with libsodium before they are stored in the database.
 *
 * ILIAS has no general secret store. The key lives in a file outside the web root
 * (the client data directory), so a database dump alone does not reveal the API keys.
 */
class SecretBox
{
    public const PREFIX = 'xvbd1:';

    /** @var \Closure(): string */
    private \Closure $key;

    /**
     * @param callable(): string $key returns the 32 byte key, called only when needed
     */
    public function __construct(callable $key)
    {
        $this->key = \Closure::fromCallable($key);
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $this->key());
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * @throws \RuntimeException if the value was encrypted with another key or is damaged
     */
    public function decrypt(string $value): string
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted value is damaged.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key());
        if ($plain === false) {
            throw new \RuntimeException('Encrypted value cannot be decrypted with the current key.');
        }
        return $plain;
    }

    private function key(): string
    {
        $key = ($this->key)();
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid encryption key.');
        }
        return $key;
    }

    /**
     * Key stored in a file, created with mode 0600 on first use.
     *
     * @return callable(): string
     */
    public static function fileKey(string $path): callable
    {
        return static function () use ($path): string {
            if (!is_file($path)) {
                $dir = dirname($path);
                if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Cannot create directory for the encryption key.');
                }
                $old = umask(0077);
                try {
                    $handle = @fopen($path, 'x');
                    if ($handle !== false) {
                        fwrite($handle, bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
                        fclose($handle);
                    }
                } finally {
                    umask($old);
                }
            }
            $hex = trim((string) @file_get_contents($path));
            $key = $hex !== '' ? @hex2bin($hex) : false;
            if ($key === false) {
                throw new \RuntimeException('The encryption key file cannot be read.');
            }
            return $key;
        };
    }
}
