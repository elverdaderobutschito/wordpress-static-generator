<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Netlify uploader. Unlike SFTP, Netlify can't granularly update a
 * single file via the simple ZIP deploy method - every deploy replaces
 * the entire site content. uploadFile() therefore only exists for the
 * sake of completeness (interface conformance) and also deploys the
 * entire parent directory.
 */
class WPStatic_NetlifyUploader implements WPStatic_Uploader {
    private string $siteId;
    private string $token;

    public function __construct(array $settings) {
        if (empty($settings['netlify_site_id']) || empty($settings['netlify_token'])) {
            throw new RuntimeException(__('Netlify Site ID or token is missing from the settings.', 'wp-static-deploy'));
        }

        $this->siteId = $settings['netlify_site_id'];
        $this->token = $settings['netlify_token'];
    }

    /**
     * Closes a cURL handle - safe across PHP versions. Since PHP 8.0,
     * cURL handles are objects, so curl_close() has done nothing since
     * then and is marked deprecated as of PHP 8.5; on PHP 7.4 (see
     * "Requires PHP" in the plugin header), the call is still necessary,
     * however.
     */
    private static function closeCurlHandle($curl): void {
        if (PHP_VERSION_ID < 80000) {
            curl_close($curl);
        }
    }

    /**
     * Checks the site ID and token against the real Netlify API (a GET on
     * the site resource) - deliberately does NOT trigger a deploy.
     */
    public function testConnection(): void {
        $curl = curl_init("https://api.netlify.com/api/v1/sites/{$this->siteId}");

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            $error = curl_error($curl);
            self::closeCurlHandle($curl);
            throw new RuntimeException(sprintf(/* translators: %s: cURL error message */ __('Could not reach Netlify: %s', 'wp-static-deploy'), $error));
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        self::closeCurlHandle($curl);

        if ($httpCode === 401) {
            throw new RuntimeException(__('Netlify token invalid or expired. Please enter a new Personal Access Token.', 'wp-static-deploy'));
        }

        if ($httpCode === 404) {
            throw new RuntimeException(sprintf(/* translators: %s: Netlify site ID */ __('No Netlify site found with the ID "%s". Please check the site ID (found under Site settings -> General -> Site details in Netlify).', 'wp-static-deploy'), $this->siteId));
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf(/* translators: %d: HTTP status code */ __('Netlify responded with HTTP %d.', 'wp-static-deploy'), $httpCode));
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded) || ($decoded['id'] ?? null) !== $this->siteId) {
            throw new RuntimeException(__('Unexpected response from Netlify - please check the site ID.', 'wp-static-deploy'));
        }
    }

    public function uploadFile(string $localPath, string $relativePath): void {
        // See the class docs: Netlify can't update a single file. The
        // calling code (see WPStatic_AjaxController) should instead
        // always call uploadDirectory() on the entire build directory for
        // Netlify.
        throw new RuntimeException(
            __('Netlify does not support single-file transfer - please use uploadDirectory().', 'wp-static-deploy')
        );
    }

    public function uploadDirectory(string $localDir): array {
        $zipPath = rtrim($localDir, '/') . '.zip';

        if (!$this->zipDirectory($localDir, $zipPath)) {
            throw new RuntimeException(__('Could not zip the build directory for the Netlify deploy.', 'wp-static-deploy'));
        }

        try {
            return $this->deploy($zipPath);
        } finally {
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    private function deploy(string $zipPath): array {
        $zipData = file_get_contents($zipPath);

        if ($zipData === false) {
            throw new RuntimeException(__('Could not read the ZIP file for the Netlify deploy.', 'wp-static-deploy'));
        }

        $curl = curl_init("https://api.netlify.com/api/v1/sites/{$this->siteId}/deploys");

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $zipData,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/zip',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            $error = curl_error($curl);
            self::closeCurlHandle($curl);
            throw new RuntimeException(sprintf(/* translators: %s: cURL error message */ __('Netlify deploy failed: %s', 'wp-static-deploy'), $error));
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        self::closeCurlHandle($curl);

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
            throw new RuntimeException(sprintf(/* translators: 1: HTTP status code, 2: response body excerpt */ __('Netlify responded with HTTP %1$d: %2$s', 'wp-static-deploy'), $httpCode, substr((string) $response, 0, 500)));
        }

        return [
            'deploy_id' => $decoded['id'] ?? null,
            'state' => $decoded['state'] ?? null,
            'deploy_url' => $decoded['deploy_ssl_url'] ?? $decoded['ssl_url'] ?? $decoded['url'] ?? null,
        ];
    }

    private function zipDirectory(string $source, string $destination): bool {
        if (!extension_loaded('zip') || !is_dir($source)) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($destination, ZipArchive::CREATE) !== true) {
            return false;
        }

        $source = str_replace('\\', '/', (string) realpath($source));

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $filePath = str_replace('\\', '/', (string) realpath($file->getPathname()));
            $relativePath = ltrim(str_replace($source, '', $filePath), '/');

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }

        return $zip->close();
    }
}
