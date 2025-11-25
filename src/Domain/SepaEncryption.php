<?php
namespace Gibbon\Module\Sepa\Domain;

/**
 * SEPA Encryption Helper
 *
 * Provides AES-256-GCM encryption/decryption for sensitive SEPA data
 * Uses environment variable SEPA_ENCRYPTION_KEY for the encryption key
 *
 * @version v2.1.0
 * @since   v2.1.0
 */
class SepaEncryption
{
    private const CIPHER_METHOD = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private string $encryptionKey;

    /**
     * Constructor - loads encryption key from environment or generates one
     */
    public function __construct()
    {
        // Try to get key from environment variable
        $key = getenv('SEPA_ENCRYPTION_KEY');

        if (empty($key)) {
            // If no key exists, generate a secure one
            // In production, this should be set in environment configuration
            $key = base64_encode(random_bytes(32));

            // Log warning that a temporary key is being used
            error_log('WARNING: SEPA_ENCRYPTION_KEY not set in environment. Using temporary key. Please set SEPA_ENCRYPTION_KEY in your configuration.');
        }

        // Decode if it's base64 encoded, otherwise use as-is
        $decodedKey = base64_decode($key, true);
        $this->encryptionKey = ($decodedKey !== false && strlen($decodedKey) === 32)
            ? $decodedKey
            : hash('sha256', $key, true);
    }

    /**
     * Encrypt data using AES-256-GCM
     *
     * @param string|null $data The data to encrypt
     * @return string|null Base64 encoded encrypted data with IV and tag, or null if input is null/empty
     */
    public function encrypt(?string $data): ?string
    {
        if (empty($data)) {
            return null;
        }

        // Generate a random initialization vector
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER_METHOD));

        // Encrypt the data
        $tag = '';
        $encrypted = openssl_encrypt(
            $data,
            self::CIPHER_METHOD,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Combine IV + encrypted data + tag and encode as base64
        return base64_encode($iv . $encrypted . $tag);
    }

    /**
     * Decrypt data using AES-256-GCM
     *
     * @param string|null $encryptedData Base64 encoded encrypted data with IV and tag
     * @return string|null Decrypted data, or null if input is null/empty
     */
    public function decrypt(?string $encryptedData): ?string
    {
        if (empty($encryptedData)) {
            return null;
        }

        try {
            // Decode from base64
            $decoded = base64_decode($encryptedData, true);
            if ($decoded === false) {
                throw new \RuntimeException('Invalid base64 encoding');
            }

            // Extract IV length
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);

            // Extract IV, ciphertext, and tag
            $iv = substr($decoded, 0, $ivLength);
            $tag = substr($decoded, -self::TAG_LENGTH);
            $ciphertext = substr($decoded, $ivLength, -self::TAG_LENGTH);

            // Decrypt the data
            $decrypted = openssl_decrypt(
                $ciphertext,
                self::CIPHER_METHOD,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new \RuntimeException('Decryption failed: ' . openssl_error_string());
            }

            return $decrypted;
        } catch (\Exception $e) {
            error_log('SEPA Decryption Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Encrypt multiple fields in an array
     *
     * @param array $data Array of field => value pairs
     * @param array $fieldsToEncrypt List of field names to encrypt
     * @return array Array with specified fields encrypted
     */
    public function encryptFields(array $data, array $fieldsToEncrypt): array
    {
        $result = $data;

        foreach ($fieldsToEncrypt as $field) {
            if (isset($result[$field])) {
                $result[$field] = $this->encrypt($result[$field]);
            }
        }

        return $result;
    }

    /**
     * Decrypt multiple fields in an array
     *
     * @param array $data Array of field => value pairs
     * @param array $fieldsToDecrypt List of field names to decrypt
     * @return array Array with specified fields decrypted
     */
    public function decryptFields(array $data, array $fieldsToDecrypt): array
    {
        $result = $data;

        foreach ($fieldsToDecrypt as $field) {
            if (isset($result[$field])) {
                $result[$field] = $this->decrypt($result[$field]);
            }
        }

        return $result;
    }

    /**
     * Generate a new encryption key (for initial setup)
     *
     * @return string Base64 encoded 256-bit encryption key
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }
}
