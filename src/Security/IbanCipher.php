<?php
namespace Gibbon\Module\Sepa\Security;

/**
 * Encrypts / decrypts bank account numbers (IBAN) stored in the database.
 *
 * - Algorithm: AES-256-GCM (authenticated encryption), random IV for every value.
 * - Stored format: "enc:v1:" . base64( iv(12) . tag(16) . ciphertext )
 * - Key: 32 random bytes, base64 encoded, taken from the environment variable
 *   SEPA_IBAN_ENCRYPTION_KEY, or from a ".env" file (see README.md). The key is never stored
 *   in the database, so a database dump alone does not expose any IBAN.
 *
 * Values that do not start with the "enc:v1:" prefix (e.g. rows written before encryption was
 * introduced) are treated as legacy values and returned unchanged by decrypt().
 */
class IbanCipher
{
    const PREFIX = 'enc:v1:';
    const KEY_NAME = 'SEPA_IBAN_ENCRYPTION_KEY';
    const CIPHER = 'aes-256-gcm';

    private static $key = null;

    /**
     * Normalise an IBAN as typed by a person: no spaces / punctuation, upper case.
     */
    public static function normalize($iban)
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $iban));
    }

    public static function isEncrypted($value)
    {
        return is_string($value) && strpos($value, self::PREFIX) === 0;
    }

    /**
     * @throws \RuntimeException when no valid key is configured or encryption fails
     */
    public static function encrypt($iban)
    {
        // Already encrypted (e.g. a record copied from the database): never encrypt twice
        if (self::isEncrypted($iban)) {
            return $iban;
        }
        $iban = self::normalize($iban);
        if ($iban === '') {
            return null;
        }

        $key = self::getKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($iban, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipherText === false) {
            throw new \RuntimeException('IBAN encryption failed.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    /**
     * Returns the clear IBAN. Legacy (unencrypted) values are returned as they are.
     * Returns null when the value cannot be decrypted (wrong / missing key, corrupted data).
     */
    public static function decrypt($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!self::isEncrypted($value)) {
            return $value;
        }

        try {
            $key = self::getKey();
        } catch (\RuntimeException $e) {
            return null;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));

        return $plain === false ? null : $plain;
    }

    /**
     * Decrypt for display: falls back to a visible marker instead of null.
     */
    public static function decryptForDisplay($value)
    {
        $plain = self::decrypt($value);
        return $plain === null ? '[' . __('cannot decrypt - check the encryption key') . ']' : $plain;
    }

    /**
     * Space separated groups of four characters (DE12 3456 ...), for display only.
     */
    public static function format($iban)
    {
        return trim(chunk_split(self::normalize($iban), 4, ' '));
    }

    /**
     * Validates length, characters and the ISO 13616 mod-97 checksum.
     */
    public static function isValid($iban)
    {
        $iban = self::normalize($iban);
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }
        return $remainder === 1;
    }

    public static function hasKey()
    {
        try {
            self::getKey();
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Key lookup order: process environment, $_ENV / $_SERVER, then ".env" files
     * (module folder first, then the Gibbon root folder).
     */
    private static function getKey()
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $value = getenv(self::KEY_NAME);
        if (empty($value)) {
            $value = $_ENV[self::KEY_NAME] ?? $_SERVER[self::KEY_NAME] ?? '';
        }
        if (empty($value)) {
            $moduleDir = dirname(__DIR__, 2);
            foreach ([$moduleDir . '/.env', dirname($moduleDir, 2) . '/.env'] as $file) {
                $value = self::readEnvFile($file);
                if (!empty($value)) {
                    break;
                }
            }
        }

        $key = $value ? base64_decode(trim($value), true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException(self::KEY_NAME . ' is missing or invalid. It must be 32 random bytes, base64 encoded (see the README.md of the SEPA module).');
        }

        return self::$key = $key;
    }

    private static function readEnvFile($file)
    {
        if (!is_readable($file)) {
            return '';
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            list($name, $val) = explode('=', $line, 2);
            if (trim($name) === self::KEY_NAME) {
                return trim(trim($val), "\"'");
            }
        }
        return '';
    }
}
