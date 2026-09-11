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
class Content2HTML_NetlifyUploader implements Content2HTML_Uploader {
    private string $siteId;
    private string $token;

    public function __construct(array $settings) {
        if (empty($settings['netlify_site_id']) || empty($settings['netlify_token'])) {
            throw new RuntimeException(esc_html__('Netlify Site ID or token is missing from the settings.', 'content2html'));
        }

        $this->siteId = $settings['netlify_site_id'];
        $this->token = $settings['netlify_token'];
    }

    /**
     * Checks the site ID and token against the real Netlify API (a GET on
     * the site resource) - deliberately does NOT trigger a deploy.
     */
    public function testConnection(): void {
        $response = wp_remote_get("https://api.netlify.com/api/v1/sites/{$this->siteId}", [
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException(esc_html(sprintf(/* translators: %s: HTTP error message */ __('Could not reach Netlify: %s', 'content2html'), $response->get_error_message())));
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($httpCode === 401) {
            throw new RuntimeException(esc_html__('Netlify token invalid or expired. Please enter a new Personal Access Token.', 'content2html'));
        }

        if ($httpCode === 404) {
            throw new RuntimeException(esc_html(sprintf(/* translators: %s: Netlify site ID */ __('No Netlify site found with the ID "%s". Please check the site ID (found under Site settings -> General -> Site details in Netlify).', 'content2html'), $this->siteId)));
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(esc_html(sprintf(/* translators: %d: HTTP status code */ __('Netlify responded with HTTP %d.', 'content2html'), $httpCode)));
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || ($decoded['id'] ?? null) !== $this->siteId) {
            throw new RuntimeException(esc_html__('Unexpected response from Netlify - please check the site ID.', 'content2html'));
        }
    }

    public function uploadFile(string $localPath, string $relativePath): void {
        // See the class docs: Netlify can't update a single file. The
        // calling code (see Content2HTML_AjaxController) should instead
        // always call uploadDirectory() on the entire build directory for
        // Netlify.
        throw new RuntimeException(
            esc_html__('Netlify does not support single-file transfer - please use uploadDirectory().', 'content2html')
        );
    }

    public function uploadDirectory(string $localDir): array {
        $zipPath = rtrim($localDir, '/') . '.zip';

        if (!$this->zipDirectory($localDir, $zipPath)) {
            throw new RuntimeException(esc_html__('Could not zip the build directory for the Netlify deploy.', 'content2html'));
        }

        try {
            return $this->deploy($zipPath);
        } finally {
            if (is_file($zipPath)) {
                Content2HTML_Filesystem::deleteFile($zipPath);
            }
        }
    }

    private function deploy(string $zipPath): array {
        $zipData = Content2HTML_Filesystem::getContents($zipPath);

        if ($zipData === false) {
            throw new RuntimeException(esc_html__('Could not read the ZIP file for the Netlify deploy.', 'content2html'));
        }

        $response = wp_remote_request("https://api.netlify.com/api/v1/sites/{$this->siteId}/deploys", [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/zip',
                'Authorization' => 'Bearer ' . $this->token,
            ],
            'body' => $zipData,
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            throw new RuntimeException(esc_html(sprintf(/* translators: %s: HTTP error message */ __('Netlify deploy failed: %s', 'content2html'), $response->get_error_message())));
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
            throw new RuntimeException(esc_html(sprintf(/* translators: 1: HTTP status code, 2: response body excerpt */ __('Netlify responded with HTTP %1$d: %2$s', 'content2html'), $httpCode, substr($body, 0, 500))));
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
