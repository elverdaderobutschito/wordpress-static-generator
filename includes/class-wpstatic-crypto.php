<?php

/**
 * Simple, reversible encryption for secrets (SFTP password, Netlify
 * token) stored in wp_options.
 *
 * IMPORTANT: this is encryption, not a one-way hash function (like
 * password hashes) - we MUST be able to recover the original values in
 * order to log in to Netlify/SFTP with them. So it protects against
 * "someone accidentally reads the database/a backup", not against
 * someone who also has access to wp-config.php (they'd have the key
 * too, in that case).
 *
 * Key management:
 *  - Preferred: WPSTATIC_ENCRYPTION_KEY as a constant in wp-config.php,
 *    e.g. define('WPSTATIC_ENCRYPTION_KEY', bin2hex(random_bytes(32)));
 *    (generate once via PHP CLI, same as with the API key earlier)
 *  - Fallback: if no key is found, the plugin automatically generates
 *    one on first save and stores it in wp_options - this is weaker
 *    (the key and the ciphertext then live in the same database), but
 *    still better than plain text. An admin notice points this out.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPStatic_Crypto {
    private const CIPHER = 'aes-256-cbc';
    private const FALLBACK_KEY_OPTION = 'wpstatic_deploy_fallback_key';

    public static function encrypt(string $plaintext): string {
        if ($plaintext === '') {
            return '';
        }

        $key = self::getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            return '';
        }

        // The IV is prepended so we have it again when decrypting - IVs
        // don't need to be secret, only unique per value.
        return base64_encode($iv . $ciphertext);
    }

    public static function decrypt(string $encoded): string {
        if ($encoded === '') {
            return '';
        }

        $key = self::getKey();
        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            return '';
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * True if the key comes from wp-config.php (recommended) rather than
     * the automatically generated DB fallback.
     */
    public static function usesConfigKey(): bool {
        return defined('WPSTATIC_ENCRYPTION_KEY') && WPSTATIC_ENCRYPTION_KEY !== '';
    }

    private static function getKey(): string {
        if (self::usesConfigKey()) {
            return hash('sha256', WPSTATIC_ENCRYPTION_KEY, true);
        }

        $fallback = get_option(self::FALLBACK_KEY_OPTION);

        if (!is_string($fallback) || $fallback === '') {
            $fallback = bin2hex(random_bytes(32));
            update_option(self::FALLBACK_KEY_OPTION, $fallback, false);
        }

        return hash('sha256', $fallback, true);
    }
}
