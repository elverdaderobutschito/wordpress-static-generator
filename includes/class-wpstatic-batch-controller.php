<?php

if (!defined('ABSPATH')) {
    exit;
}

class WPStatic_BatchController {
    public static function getBuildDir(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'wpstatic-build';
    }

    /**
     * @return array<int, array{id:int, post_type:string}>
     */
    public static function buildQueue(): array {
        $settings = WPStatic_Settings::getSettings();

        $ids = get_posts([
            'post_type' => $settings['post_types'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        $queue = [];
        foreach ($ids as $id) {
            $queue[] = ['id' => (int) $id, 'post_type' => get_post_type($id)];
        }

        return $queue;
    }

    public static function resetBuildDir(): void {
        $dir = self::getBuildDir();

        if (is_dir($dir)) {
            self::rrmdir($dir);
        }

        wp_mkdir_p($dir);
    }

    public static function restBaseForPostType(string $postType): string {
        return $postType === 'page' ? 'pages' : 'posts';
    }

    /**
     * Generates the static file(s) for exactly one post into the build
     * directory. Returns the list of files written (relative to the
     * build directory) - delivered directly by the generator (see
     * injectDataIntoTemplate()), not via a before/after directory
     * comparison. This captures both newly created and overwritten files
     * (e.g. when an already-generated post is regenerated because its
     * content changed) as well as any image assets downloaded for that
     * page along the way.
     *
     * @return string[]
     */
    public static function generateSingle(int $postId, string $postType): array {
        $buildDir = self::getBuildDir();

        if (!is_dir($buildDir)) {
            wp_mkdir_p($buildDir);
        }

        $settings = WPStatic_Settings::getSettings();

        $templateId = (string) get_post_meta($postId, '_wpstatic_template_id', true);
        $templateOverride = WPStatic_Settings::resolveTemplatePath($templateId);
        $templatePath = $templateOverride ?? $settings['template_path'];

        if (empty($templatePath) || !is_file($templatePath)) {
            throw new RuntimeException(__('No valid template file configured.', 'wp-static-deploy'));
        }

        $effectiveTemplatePath = $templatePath;
        $tempTemplatePath = null;

        if (!empty($settings['nav_main_enabled']) || !empty($settings['nav_footer_enabled'])) {
            $templateContent = file_get_contents($templatePath);
            $currentUrl = (string) get_permalink($postId);
            $renderedContent = WPStatic_Navigation::renderTemplate($templateContent, $currentUrl, $settings);

            $tempTemplatePath = self::getTempDir() . '/nav-template-' . $postId . '-' . uniqid() . '.html';
            file_put_contents($tempTemplatePath, $renderedContent);
            $effectiveTemplatePath = $tempTemplatePath;
        }

        try {
            $generator = WPStatic_GeneratorFactory::build($buildDir, $effectiveTemplatePath);
            $restBase = self::restBaseForPostType($postType);

            $writtenAbsolutePaths = $generator->injectDataIntoTemplate(
                $restBase . '/' . $postId,
                WPStatic_GeneratorFactory::getDataInjectionRules()
            );
        } finally {
            if ($tempTemplatePath !== null && is_file($tempTemplatePath)) {
                unlink($tempTemplatePath);
            }
        }

        $buildDirNormalized = rtrim($buildDir, '/');
        $relativePaths = [];

        foreach ($writtenAbsolutePaths as $absolutePath) {
            $relativePaths[] = ltrim(str_replace($buildDirNormalized, '', $absolutePath), '/');
        }

        return array_values(array_unique($relativePaths));
    }

    /**
     * Scratch directory for temporary files (e.g. per-page,
     * navigation-processed templates) - deliberately NOT inside the
     * build folder, so these intermediate files don't accidentally get
     * uploaded.
     */
    private static function getTempDir(): string {
        $dir = wp_upload_dir()['basedir'] . '/wpstatic-tmp';

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir;
    }

    public static function buildUploader(): WPStatic_Uploader {
        $settings = WPStatic_Settings::getSettings();

        return $settings['target'] === 'netlify'
            ? new WPStatic_NetlifyUploader($settings)
            : new WPStatic_SftpUploader($settings);
    }

    /**
     * Copies the uploaded assets folder (if present) into the build
     * folder - but ONLY if there are no assets there yet. Purely local,
     * barely costs any time, but still doesn't run again on every
     * single-page update regardless (assets can amount to several
     * hundred files). "Deploy all" empties the build folder completely
     * beforehand anyway (resetBuildDir()), so it always automatically
     * picks up the current state. For a forced re-copy, see
     * uploadAssetsOnly().
     */
    public static function copyAssetsToBuild(): void {
        if (is_dir(self::getBuildDir() . '/assets')) {
            return;
        }

        WPStatic_AssetsManager::copyToBuild(self::getBuildDir());
    }

    /**
     * Generates the PHP form handler in the build directory (only
     * relevant for an SFTP target with forms enabled, see
     * WPStatic_Forms::buildHandlerFile()) as well as the client-side
     * validation script (both targets, see buildValidationScript()).
     */
    public static function buildFormHandler(): void {
        $settings = WPStatic_Settings::getSettings();
        WPStatic_Forms::buildHandlerFile(self::getBuildDir(), $settings);
        WPStatic_Forms::buildValidationScript(self::getBuildDir(), $settings);
    }

    /**
     * A unique identifier for the currently configured deployment target
     * (host+path for SFTP, site ID for Netlify). Used to remember, per
     * target, whether assets have ever been uploaded there before.
     */
    private static function targetIdentityHash(array $settings): string {
        if ($settings['target'] === 'netlify') {
            return 'netlify:' . $settings['netlify_site_id'];
        }

        return 'sftp:' . $settings['sftp_host'] . ':' . $settings['sftp_port'] . ':' . $settings['sftp_remote_base_path'];
    }

    public static function assetsEverUploadedForCurrentTarget(): bool {
        $settings = WPStatic_Settings::getSettings();
        $hash = self::targetIdentityHash($settings);
        $marker = (array) get_option('wpstatic_assets_uploaded_targets', []);

        return !empty($marker[$hash]);
    }

    public static function markAssetsUploadedForCurrentTarget(): void {
        $settings = WPStatic_Settings::getSettings();
        $hash = self::targetIdentityHash($settings);
        $marker = (array) get_option('wpstatic_assets_uploaded_targets', []);
        $marker[$hash] = true;

        update_option('wpstatic_assets_uploaded_targets', $marker, false);
    }

    /**
     * Uploads the entire build folder to the configured target.
     *
     * On Netlify, assets are ALWAYS uploaded (a deploy replaces the
     * entire content - if we left them out, Netlify would actively
     * delete them). On SFTP, $skipAssets=true can be used to skip
     * uploading asset files - but this is ignored (and silently forced
     * back to "false") as long as assets have never been uploaded for
     * this target before (see the agreed first-upload protection).
     *
     * @return array{result: array, assetsIncluded: bool, assetsSkipForced: bool}
     */
    public static function finalizeUpload(bool $skipAssets): array {
        $settings = WPStatic_Settings::getSettings();
        $uploader = self::buildUploader();

        if ($settings['target'] === 'netlify') {
            $result = $uploader->uploadDirectory(self::getBuildDir());
            self::markAssetsUploadedForCurrentTarget();

            return ['result' => $result, 'assetsIncluded' => true, 'assetsSkipForced' => false];
        }

        $assetsSkipForced = false;

        if ($skipAssets && !self::assetsEverUploadedForCurrentTarget()) {
            $skipAssets = false;
            $assetsSkipForced = true;
        }

        if ($skipAssets) {
            $uploadStats = self::uploadBuildDir($uploader, true);
        } else {
            $uploadStats = self::uploadBuildDir($uploader, false);
            self::markAssetsUploadedForCurrentTarget();
        }

        return [
            'result' => [],
            'assetsIncluded' => !$skipAssets,
            'assetsSkipForced' => $assetsSkipForced,
            'uploadStats' => $uploadStats,
            'remoteBasePath' => $settings['sftp_remote_base_path'],
        ];
    }

    /**
     * Uploads only the assets to the target, without regenerating the
     * WordPress content - for cases where only CSS/JS/images have
     * changed. On Netlify this still runs as a full redeploy of the
     * existing build folder (not technically possible otherwise, see the
     * explanation elsewhere), on SFTP only the files under assets/ are
     * uploaded, specifically.
     */
    public static function uploadAssetsOnly(): array {
        WPStatic_AssetsManager::copyToBuild(self::getBuildDir()); // forced re-copy, regardless of the current state

        $settings = WPStatic_Settings::getSettings();
        $uploader = self::buildUploader();

        if ($settings['target'] === 'netlify') {
            $result = $uploader->uploadDirectory(self::getBuildDir());
            self::markAssetsUploadedForCurrentTarget();

            return $result;
        }

        $assetsDir = self::getBuildDir() . '/assets';

        if (is_dir($assetsDir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($assetsDir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $relative = 'assets/' . ltrim(str_replace($assetsDir, '', $file->getPathname()), '/');
                    $uploader->uploadFile($file->getPathname(), $relative);
                }
            }
        }

        self::markAssetsUploadedForCurrentTarget();

        return [];
    }

    /**
     * Uploads the entire build folder file by file via uploadFile()
     * (instead of the blanket uploadDirectory()), so that $excludeAssets
     * can specifically skip files under assets/. Only relevant for SFTP -
     * Netlify always goes through uploadDirectory().
     *
     * @return array{total: int, wp_content_files: string[]}
     */
    private static function uploadBuildDir(WPStatic_Uploader $uploader, bool $excludeAssets): array {
        $buildDir = self::getBuildDir();

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($buildDir, FilesystemIterator::SKIP_DOTS)
        );

        $total = 0;
        $wpContentFiles = [];

        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($buildDir, '', $file->getPathname()), '/');

            if ($excludeAssets && strpos($relative, 'assets/') === 0) {
                continue;
            }

            $uploader->uploadFile($file->getPathname(), $relative);
            $total++;

            if (strpos($relative, 'wp-content/') === 0) {
                $wpContentFiles[] = $relative;
            }
        }

        return ['total' => $total, 'wp_content_files' => $wpContentFiles];
    }

    /**
     * Returns the post ID of the page configured as the front page, or 0
     * if "Homepage displays: your latest posts" is set (a dynamic
     * archive view, not a single REST API object - this tool cannot
     * represent that statically).
     */
    public static function getFrontPageId(): int {
        if (get_option('show_on_front') !== 'page') {
            return 0;
        }

        return (int) get_option('page_on_front');
    }

    /**
     * For the page configured as the front page, the REST API still
     * returns its OWN permalink (e.g. /home-page/), not the root URL -
     * WordPress resolves "/" internally via rewrite rules, not via the
     * permalink itself. Our generator builds the directory structure to
     * exactly match the permalink, which means an index.html at the
     * root never gets created automatically.
     *
     * Additionally copies an already-generated file as index.html into
     * the root directory. $relativeSourceFile must be the ACTUALLY
     * generated path (e.g. from the return value of generateSingle()) -
     * we deliberately don't guess the path ourselves based on
     * get_permalink(), since that turned out to be too error-prone in
     * practice (permalink structure, trailing slashes, rewrite quirks
     * etc.).
     */
    public static function copyToRootIndex(string $relativeSourceFile): bool {
        $buildDir = self::getBuildDir();
        $sourceFile = $buildDir . '/' . ltrim($relativeSourceFile, '/');
        $rootIndex = $buildDir . '/index.html';

        if ($sourceFile === $rootIndex) {
            return true; // already in the right place
        }

        if (!is_file($sourceFile)) {
            return false;
        }

        return copy($sourceFile, $rootIndex);
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
