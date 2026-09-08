<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SFTP uploader based on phpseclib3 (\phpseclib3\Net\SFTP).
 *
 * phpseclib is deliberately NOT copied into the plugin as raw code, but
 * included as a real dependency via Composer (see composer.json +
 * README). For a security-relevant library (SSH/crypto), a managed,
 * verifiable source matters more than "saving a few KB".
 */
class WPStatic_SftpUploader implements WPStatic_Uploader {
    private \phpseclib3\Net\SFTP $sftp;
    private string $remoteBasePath;

    public function __construct(array $settings) {
        if (!class_exists(\phpseclib3\Net\SFTP::class)) {
            throw new RuntimeException(
                __('phpseclib3 was not found. Please run "composer install" in the plugin directory (see README.md) or upload the vendor folder manually.', 'wp-static-deploy')
            );
        }

        if (trim($settings['sftp_host']) === '') {
            throw new RuntimeException(__('No host specified.', 'wp-static-deploy'));
        }

        $this->remoteBasePath = '/' . trim($settings['sftp_remote_base_path'], '/');

        $this->sftp = new \phpseclib3\Net\SFTP($settings['sftp_host'], (int) $settings['sftp_port']);

        $loggedIn = $settings['sftp_auth_method'] === 'key'
            ? $this->loginWithKey($settings)
            : $this->sftp->login($settings['sftp_username'], $settings['sftp_password']);

        if ($loggedIn !== true) {
            // is_connected() distinguishes "host/port unreachable" from
            // "connected, but login rejected" - helps with diagnostics.
            if (!$this->sftp->isConnected()) {
                throw new RuntimeException(
                    sprintf(
                        /* translators: 1: host, 2: port */
                        __('Could not connect to %1$s:%2$s. Please check host, port and firewall/network.', 'wp-static-deploy'),
                        $settings['sftp_host'],
                        $settings['sftp_port']
                    )
                );
            }

            throw new RuntimeException(
                __('Connected to the server, but login failed. Please check the username and', 'wp-static-deploy') . ' '
                . ($settings['sftp_auth_method'] === 'key' ? __('private key/passphrase', 'wp-static-deploy') : __('password', 'wp-static-deploy')) . '.'
            );
        }
    }

    private function loginWithKey(array $settings): bool {
        $key = \phpseclib3\Crypt\PublicKeyLoader::load(
            $settings['sftp_private_key'],
            $settings['sftp_passphrase'] !== '' ? $settings['sftp_passphrase'] : false
        );

        return $this->sftp->login($settings['sftp_username'], $key);
    }

    /**
     * Doesn't just check the login (that already happens in the
     * constructor), but also whether the configured target directory is
     * reachable and writable - exactly what matters for the real upload.
     * Creates the directory as a test (if it doesn't exist yet, the same
     * way a regular upload would) and writes/deletes a small test file.
     */
    public function testConnection(): void {
        try {
            $this->ensureRemoteDirExists($this->remoteBasePath);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %s: target directory path */
                    __('Target directory "%s" could not be created/found. Please check the path and permissions of the SFTP user.', 'wp-static-deploy'),
                    $this->remoteBasePath
                )
            );
        }

        if (!$this->sftp->is_dir($this->remoteBasePath)) {
            throw new RuntimeException(
                sprintf(
                    /* translators: %s: target directory path */
                    __('"%s" exists, but is not a directory. Please check the path.', 'wp-static-deploy'),
                    $this->remoteBasePath
                )
            );
        }

        $testFile = rtrim($this->remoteBasePath, '/') . '/.wpstatic-connection-test-' . uniqid();

        if (!$this->sftp->put($testFile, 'wp-static-deploy connection test', \phpseclib3\Net\SFTP::SOURCE_STRING)) {
            throw new RuntimeException(
                sprintf(
                    /* translators: 1: target directory path, 2: SFTP error */
                    __('Connection/login successful, but "%1$s" is not writable (%2$s). Please check the directory permissions for the SFTP user.', 'wp-static-deploy'),
                    $this->remoteBasePath,
                    $this->sftp->getLastSFTPError()
                )
            );
        }

        $this->sftp->delete($testFile);
    }

    public function uploadFile(string $localPath, string $relativePath): void {
        $remotePath = $this->remoteBasePath . '/' . ltrim($relativePath, '/');
        $remoteDir = dirname($remotePath);

        $this->ensureRemoteDirExists($remoteDir);

        $ok = $this->sftp->put($remotePath, $localPath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE);

        if (!$ok) {
            throw new RuntimeException(
                sprintf(
                    /* translators: 1: relative file path, 2: SFTP error */
                    __('Could not transfer file via SFTP: %1$s (%2$s)', 'wp-static-deploy'),
                    $relativePath,
                    $this->sftp->getLastSFTPError()
                )
            );
        }
    }

    public function uploadDirectory(string $localDir): array {
        $localDir = rtrim($localDir, '/');

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($localDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $relativePath = ltrim(str_replace($localDir, '', $file->getPathname()), '/');
            $this->uploadFile($file->getPathname(), $relativePath);
        }

        return [];
    }

    /**
     * phpseclib bietet kein natives "mkdir -p" - Verzeichnisebenen also
     * einzeln anlegen und bereits existierende Verzeichnisse ignorieren.
     */
    private function ensureRemoteDirExists(string $remoteDir): void {
        $parts = explode('/', trim($remoteDir, '/'));
        $current = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $current .= '/' . $part;

            if (!$this->sftp->is_dir($current)) {
                $this->sftp->mkdir($current);
            }
        }
    }
}
