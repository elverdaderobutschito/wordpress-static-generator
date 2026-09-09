<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the uploaded assets folder (CSS/JS/fonts/images for the
 * template). Expects a ZIP file whose root already is an "assets/"
 * folder (see README) - this way, the extracted structure matches
 * exactly what the template references (assets/bootstrap/css/...).
 */
class WPStatic_AssetsManager {
    private const DANGEROUS_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht',
        'phar', 'cgi', 'pl', 'asp', 'aspx', 'jsp',
    ];

    public static function getStorageDir(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'wpstatic-assets';
    }

    /**
     * The actual "assets" folder that gets copied into the build.
     */
    public static function getAssetsSourceDir(): string {
        return self::getStorageDir() . '/assets';
    }

    public static function hasAssets(): bool {
        return is_dir(self::getAssetsSourceDir());
    }

    public static function getStatus(): array {
        if (!self::hasAssets()) {
            return ['exists' => false];
        }

        $dir = self::getAssetsSourceDir();
        $fileCount = 0;
        $totalSize = 0;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $fileCount++;
                $totalSize += $file->getSize();
            }
        }

        return [
            'exists' => true,
            'file_count' => $fileCount,
            'total_size' => $totalSize,
            'updated_at' => (int) get_option('wpstatic_assets_updated_at', 0),
        ];
    }

    /**
     * Extracts an uploaded ZIP file and replaces the existing assets
     * folder with it.
     *
     * @return array{ok: bool, message: string, warnings: string[]}
     */
    public static function extractZip(string $zipTmpPath): array {
        if (!extension_loaded('zip')) {
            return ['ok' => false, 'message' => __('The PHP zip extension is not active.', 'content2html'), 'warnings' => []];
        }

        $zip = new ZipArchive();

        if ($zip->open($zipTmpPath) !== true) {
            return ['ok' => false, 'message' => __('The ZIP file could not be opened.', 'content2html'), 'warnings' => []];
        }

        // Safety check: does the ZIP even contain an "assets/" root folder?
        $hasAssetsRoot = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (strpos($zip->getNameIndex($i), 'assets/') === 0) {
                $hasAssetsRoot = true;
                break;
            }
        }

        if (!$hasAssetsRoot) {
            $zip->close();
            return [
                'ok' => false,
                'message' => __('The ZIP file does not contain an "assets/" folder as its root. Please zip the assets folder itself (not just its contents).', 'content2html'),
                'warnings' => [],
            ];
        }

        $stagingDir = self::getStorageDir() . '-staging-' . uniqid();

        if (!wp_mkdir_p($stagingDir)) {
            $zip->close();
            return ['ok' => false, 'message' => __('Could not create a temporary directory.', 'content2html'), 'warnings' => []];
        }

        $extracted = $zip->extractTo($stagingDir);
        $zip->close();

        if (!$extracted) {
            self::rrmdir($stagingDir);
            return ['ok' => false, 'message' => __('Extracting the ZIP file failed.', 'content2html'), 'warnings' => []];
        }

        $stagedAssetsDir = $stagingDir . '/assets';

        if (!is_dir($stagedAssetsDir)) {
            self::rrmdir($stagingDir);
            return ['ok' => false, 'message' => __('No "assets/" folder found after extraction.', 'content2html'), 'warnings' => []];
        }

        // Safety check: suspicious, potentially executable file types. Does
        // NOT block the upload (there could be a legitimate reason for
        // it), but warns clearly.
        $warnings = self::scanForDangerousFiles($stagedAssetsDir);

        // Replace the old assets folder (atomic enough for this purpose:
        // prepare the new folder first, then remove the old one, then
        // rename).
        $storageDir = self::getStorageDir();

        if (is_dir($storageDir)) {
            self::rrmdir($storageDir);
        }

        wp_mkdir_p($storageDir);
        $moved = rename($stagedAssetsDir, $storageDir . '/assets');

        self::rrmdir($stagingDir); // clean up any ZIP extras (__MACOSX etc.)

        if (!$moved) {
            return ['ok' => false, 'message' => __('Could not move assets to the target location.', 'content2html'), 'warnings' => $warnings];
        }

        update_option('wpstatic_assets_updated_at', time(), false);

        // New assets were uploaded -> for all previous targets, the
        // "have already been uploaded" marker must be reset, otherwise
        // the NEW assets might accidentally never get uploaded to the
        // SFTP target (see WPStatic_BatchController).
        update_option('wpstatic_assets_uploaded_targets', [], false);

        return ['ok' => true, 'message' => __('Assets updated successfully.', 'content2html'), 'warnings' => $warnings];
    }

    public static function copyToBuild(string $buildDir): void {
        if (!self::hasAssets()) {
            return;
        }

        $source = self::getAssetsSourceDir();
        $destination = rtrim($buildDir, '/') . '/assets';

        // Empty it completely beforehand instead of just overwriting, so
        // removed/renamed assets don't stick around as leftovers.
        if (is_dir($destination)) {
            self::rrmdir($destination);
        }

        wp_mkdir_p($destination);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $relative = ltrim(str_replace($source, '', $file->getPathname()), '/');
            $target = $destination . '/' . $relative;

            if ($file->isDir()) {
                wp_mkdir_p($target);
            } else {
                copy($file->getPathname(), $target);
            }
        }
    }

    private static function scanForDangerousFiles(string $dir): array {
        $warnings = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

            if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
                $warnings[] = ltrim(str_replace($dir, '', $file->getPathname()), '/');
            }
        }

        return $warnings;
    }

    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }

            $path = $dir . '/' . $object;

            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
