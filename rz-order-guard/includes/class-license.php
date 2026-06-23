<?php
namespace RZOG;

defined('ABSPATH') || exit;

/**
 * Per-install license check using Ed25519 signatures (libsodium, built into
 * PHP since 7.2 -- no extra extension needed).
 *
 * Why this and not the domain-whitelist or a shared-secret check: both of
 * those put the "secret" inside the plugin's own readable source, so anyone
 * with the files can extract it and forge their own valid key. Here, the
 * plugin only ever ships the PUBLIC key (below) -- it can verify a
 * signature but cannot be used to create one. Only YOU can mint new valid
 * license keys, using the PRIVATE key that never leaves your own computer
 * (see tools/generate-keypair.php and tools/issue-license.php -- run those
 * locally, never upload them or the private key to the server).
 *
 * Every install needs an explicit license key entered in
 * Settings -> RZ Order Guard -- there is no auto-detection of domains here.
 */
class License {

    // Paste the PUBLIC key printed by tools/generate-keypair.php here.
    // This value is safe to ship -- it cannot be used to forge a license.
    const PUBLIC_KEY_B64 = '06sRQgHEe+7aJ7V4fBDEAfWJOgU6CO/MH4BDs0Oq0e4=';

    public static function is_valid(): bool {
        $payload = self::verified_payload();
        return $payload !== null;
    }

    public static function status_message(): string {
        if (self::PUBLIC_KEY_B64 === 'PASTE_YOUR_PUBLIC_KEY_HERE') {
            return 'No public key configured yet -- run tools/generate-keypair.php and paste the public key into includes/class-license.php.';
        }

        $key = trim((string) get_option('rzog_license_key', ''));
        if ($key === '') {
            return 'No license key entered.';
        }

        $payload = self::verified_payload();
        if ($payload === null) {
            return 'License key is invalid, expired, or does not match this domain.';
        }

        $expiry = !empty($payload['expires_at']) ? (' (expires ' . $payload['expires_at'] . ')') : ' (no expiry)';
        return 'Valid for ' . $payload['domain'] . $expiry . '.';
    }

    /**
     * Verifies the stored license key's signature, domain match, and
     * expiry. Returns the decoded payload on success, null otherwise.
     */
    private static function verified_payload(): ?array {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return null; // PHP build without libsodium -- fail closed.
        }

        $license_key = trim((string) get_option('rzog_license_key', ''));
        if ($license_key === '') {
            return null;
        }

        $parts = explode('.', $license_key);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload_b64, $sig_b64] = $parts;

        $signature  = self::b64url_decode($sig_b64);
        $public_key = base64_decode(self::PUBLIC_KEY_B64, true);

        if ($signature === false || $public_key === false || strlen($public_key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }

        if (!sodium_crypto_sign_verify_detached($signature, $payload_b64, $public_key)) {
            return null; // signature doesn't match -- forged or corrupted key
        }

        $json = self::b64url_decode($payload_b64);
        if ($json === false) {
            return null;
        }

        $payload = json_decode((string) $json, true);
        if (!is_array($payload) || empty($payload['domain'])) {
            return null;
        }

        // Domain check: this exact key only validates on the domain it was issued for.
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/^www\./', '', $host);
        $licensed_domain = strtolower(preg_replace('/^www\./', '', (string) $payload['domain']));

        if ($host === '' || $host !== $licensed_domain) {
            return null;
        }

        // Expiry check (optional -- omit expires_at when issuing for a perpetual key).
        if (!empty($payload['expires_at'])) {
            $expires = strtotime($payload['expires_at'] . ' 23:59:59 UTC');
            if ($expires !== false && time() > $expires) {
                return null;
            }
        }

        return $payload;
    }

    private static function b64url_decode(string $data) {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($data, true);
    }
}
