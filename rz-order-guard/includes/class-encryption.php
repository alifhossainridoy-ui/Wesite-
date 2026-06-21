<?php
namespace RZOG;

defined('ABSPATH') || exit;

/**
 * Encrypts/decrypts stored API credentials (courier keys, fraud API key)
 * using AES-256-GCM with a key derived from WordPress's own salts.
 * Same approach as the old plugin's CredentialEncryption — rewritten clean,
 * no obfuscation, since this is your own plugin now.
 */
class Encryption {

    private static function get_key(): string {
        $material = wp_salt('AUTH_KEY') . wp_salt('SECURE_AUTH_KEY') . 'rzog_credential_encryption';
        return hash('sha256', $material, true);
    }

    public static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }

        $key = self::get_key();
        $iv  = openssl_random_pseudo_bytes(16);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            return '';
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string {
        if ($encoded === '') {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 32) {
            return '';
        }

        $iv         = substr($raw, 0, 16);
        $tag        = substr($raw, 16, 16);
        $ciphertext = substr($raw, 32);

        $key = self::get_key();
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? '' : $plaintext;
    }

    public static function is_encrypted(string $value): bool {
        if ($value === '') {
            return false;
        }
        $decoded = base64_decode($value, true);
        return $decoded !== false && strlen($decoded) >= 32;
    }

    /** Read a possibly-encrypted option, returning the plain value either way. */
    public static function read_option(string $option_name): string {
        $raw = (string) get_option($option_name, '');
        return self::is_encrypted($raw) ? self::decrypt($raw) : $raw;
    }
}
