<?php

namespace App\Services;

class MessageEncryptionService
{
    /**
     * Prefix marker to identify encrypted message payloads.
     */
    private const ENCRYPTED_PREFIX = 'ENC::';

    /**
     * Encrypt a plain message string using the user's encryption key (AES-256-CBC).
     */
    public static function encrypt(string $plainText, string $userKey): string
    {
        if (trim($plainText) === '') {
            return $plainText;
        }

        $secretKey = hash('sha256', $userKey, true);
        $iv = random_bytes(16);
        $cipherText = openssl_encrypt($plainText, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);

        if ($cipherText === false) {
            return $plainText;
        }

        return self::ENCRYPTED_PREFIX.base64_encode($iv.$cipherText);
    }

    /**
     * Decrypt an encrypted message payload using the sender's encryption key.
     */
    public static function decrypt(?string $payload, string $userKey): ?string
    {
        if ($payload === null || ! str_starts_with($payload, self::ENCRYPTED_PREFIX)) {
            return $payload;
        }

        $raw = base64_decode(substr($payload, strlen(self::ENCRYPTED_PREFIX)), true);

        if ($raw === false || strlen($raw) <= 16) {
            return $payload;
        }

        $iv = substr($raw, 0, 16);
        $cipherText = substr($raw, 16);
        $secretKey = hash('sha256', $userKey, true);

        $decrypted = openssl_decrypt($cipherText, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? $payload : $decrypted;
    }

    /**
     * Binary prefix marker to identify encrypted attachment binary files.
     */
    private const BINARY_ENCRYPTED_PREFIX = 'ENC_FILE::';

    /**
     * Encrypt raw binary data (e.g. file content) using the user's encryption key.
     */
    public static function encryptBinary(string $data, string $userKey): string
    {
        $secretKey = hash('sha256', $userKey, true);
        $iv = random_bytes(16);
        $cipherText = openssl_encrypt($data, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);

        if ($cipherText === false) {
            return $data;
        }

        return self::BINARY_ENCRYPTED_PREFIX.$iv.$cipherText;
    }

    /**
     * Decrypt raw binary data using the sender's encryption key.
     */
    public static function decryptBinary(string $data, string $userKey): string
    {
        $prefix = self::BINARY_ENCRYPTED_PREFIX;
        if (! str_starts_with($data, $prefix)) {
            return $data;
        }

        $encrypted = substr($data, strlen($prefix));
        if (strlen($encrypted) <= 16) {
            return $data;
        }

        $iv = substr($encrypted, 0, 16);
        $cipherText = substr($encrypted, 16);
        $secretKey = hash('sha256', $userKey, true);

        $decrypted = openssl_decrypt($cipherText, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? $data : $decrypted;
    }
}
