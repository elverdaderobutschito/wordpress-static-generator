<?php

if (!defined('ABSPATH')) {
    exit;
}

class Content2HTML_AjaxController {
    private const NONCE_ACTION = 'wpstatic_deploy_ajax';
    private const QUEUE_TRANSIENT_PREFIX = 'wpstatic_deploy_queue_';
    private const FRONT_FILE_TRANSIENT_PREFIX = 'wpstatic_deploy_frontfile_';

    public function __construct() {
        add_action('wp_ajax_wpstatic_deploy_start', [$this, 'handleStart']);
        add_action('wp_ajax_wpstatic_deploy_batch', [$this, 'handleBatch']);
        add_action('wp_ajax_wpstatic_deploy_finalize', [$this, 'handleFinalize']);
        add_action('wp_ajax_wpstatic_deploy_single', [$this, 'handleSingle']);
        add_action('wp_ajax_wpstatic_deploy_assets_only', [$this, 'handleAssetsOnly']);
        add_action('wp_ajax_wpstatic_deploy_test_sftp', [$this, 'handleTestSftp']);
        add_action('wp_ajax_wpstatic_deploy_test_netlify', [$this, 'handleTestNetlify']);
    }

    private function checkAccess(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'content2html')], 403);
        }
    }

    private function queueTransientKey(): string {
        return self::QUEUE_TRANSIENT_PREFIX . get_current_user_id();
    }

    private function frontFileTransientKey(): string {
        return self::FRONT_FILE_TRANSIENT_PREFIX . get_current_user_id();
    }

    // -----------------------------------------------------------------
    // "Deploy all" flow: start -> N x batch -> finalize
    // -----------------------------------------------------------------

    public function handleStart(): void {
        $this->checkAccess();

        try {
            Content2HTML_BatchController::resetBuildDir();
            Content2HTML_BatchController::copyAssetsToBuild();
            Content2HTML_BatchController::buildFormHandler();
            $queue = Content2HTML_BatchController::buildQueue();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        // In a transient rather than the options table - this is
        // temporary state, not permanent configuration.
        set_transient($this->queueTransientKey(), $queue, HOUR_IN_SECONDS);
        delete_transient($this->frontFileTransientKey());

        wp_send_json_success([
            'total' => count($queue),
            'assets_ever_uploaded' => Content2HTML_BatchController::assetsEverUploadedForCurrentTarget(),
            'has_assets' => Content2HTML_AssetsManager::hasAssets(),
        ]);
    }

    public function handleBatch(): void {
        $this->checkAccess();

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $batchSize = isset($_POST['batch_size']) ? max(1, (int) $_POST['batch_size']) : 5;

        $queue = get_transient($this->queueTransientKey());

        if (!is_array($queue)) {
            wp_send_json_error(['message' => __('No deployment in progress found. Please start again.', 'content2html')]);
        }

        $slice = array_slice($queue, $offset, $batchSize);
        $errors = [];
        $frontPageId = Content2HTML_BatchController::getFrontPageId();

        foreach ($slice as $item) {
            try {
                $newFiles = Content2HTML_BatchController::generateSingle((int) $item['id'], (string) $item['post_type']);

                if ($frontPageId > 0 && (int) $item['id'] === $frontPageId) {
                    foreach ($newFiles as $newFile) {
                        if (substr($newFile, -5) === '.html') {
                            set_transient($this->frontFileTransientKey(), $newFile, HOUR_IN_SECONDS);
                            break;
                        }
                    }
                }
            } catch (Throwable $e) {
                $errors[] = "Post #{$item['id']}: " . $e->getMessage();
            }
        }

        wp_send_json_success([
            'processed' => $offset + count($slice),
            'total' => count($queue),
            'done' => ($offset + count($slice)) >= count($queue),
            'errors' => $errors,
        ]);
    }

    public function handleFinalize(): void {
        $this->checkAccess();

        $skipAssets = sanitize_text_field(wp_unslash($_POST['skip_assets'] ?? '')) === '1';

        try {
            $frontFile = get_transient($this->frontFileTransientKey());

            if (is_string($frontFile) && $frontFile !== '') {
                Content2HTML_BatchController::copyToRootIndex($frontFile);
            }

            $upload = Content2HTML_BatchController::finalizeUpload($skipAssets);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        delete_transient($this->queueTransientKey());
        delete_transient($this->frontFileTransientKey());

        $response = $upload['result'] ?: ['message' => __('Deployment complete.', 'content2html')];
        $response['assets_included'] = $upload['assetsIncluded'];

        if (isset($upload['uploadStats'])) {
            $stats = $upload['uploadStats'];
            $wpContentCount = count($stats['wp_content_files']);

            $response['message'] = sprintf(
                /* translators: 1: file count, 2: target path, 3: wp-content file count */
                __('Deployment complete: %1$d files to "%2$s". Of these, %3$d under wp-content/ (e.g. images).', 'content2html'),
                $stats['total'],
                $upload['remoteBasePath'] ?? '',
                $wpContentCount
            );

            $response['wp_content_files_sample'] = array_slice($stats['wp_content_files'], 0, 10);
        }

        if ($upload['assetsSkipForced']) {
            $response['message'] = ($response['message'] ?? '') . ' (' . __('Note: assets were uploaded anyway, since they had never been uploaded for this target before.', 'content2html') . ')';
        }

        wp_send_json_success($response);
    }

    // -----------------------------------------------------------------
    // Single-page deployment (meta box button)
    // -----------------------------------------------------------------

    public function handleSingle(): void {
        $this->checkAccess();

        $postId = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post = $postId ? get_post($postId) : null;

        if (!$post) {
            wp_send_json_error(['message' => __('Post not found.', 'content2html')]);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => __('Insufficient permissions for this post.', 'content2html')], 403);
        }

        $settings = Content2HTML_Settings::getSettings();

        try {
            if ($settings['target'] === 'netlify') {
                // Netlify has no granular single-file update - a deploy always
                // replaces the entire site content (see the note in the
                // settings). So here: regenerate all pages and do a full
                // redeploy.
                set_time_limit(0); // can take a while on larger sites

                Content2HTML_BatchController::resetBuildDir();
                Content2HTML_BatchController::copyAssetsToBuild(); // resetBuildDir() just deleted them
                Content2HTML_BatchController::buildFormHandler();
                $queue = Content2HTML_BatchController::buildQueue();
                $frontPageId = Content2HTML_BatchController::getFrontPageId();
                $frontFile = null;

                foreach ($queue as $item) {
                    $newFiles = Content2HTML_BatchController::generateSingle((int) $item['id'], (string) $item['post_type']);

                    if ($frontPageId > 0 && (int) $item['id'] === $frontPageId) {
                        foreach ($newFiles as $newFile) {
                            if (substr($newFile, -5) === '.html') {
                                $frontFile = $newFile;
                                break;
                            }
                        }
                    }
                }

                if ($frontFile !== null) {
                    Content2HTML_BatchController::copyToRootIndex($frontFile);
                }

                $uploader = Content2HTML_BatchController::buildUploader();
                $result = $uploader->uploadDirectory(Content2HTML_BatchController::getBuildDir());
                Content2HTML_BatchController::markAssetsUploadedForCurrentTarget();

                wp_send_json_success(array_merge(
                    ['message' => __('Netlify does not support single-page deploys - the entire site was rebuilt and redeployed.', 'content2html')],
                    $result
                ));
            }

            // SFTP: only generate and specifically upload this one page.
            Content2HTML_BatchController::copyAssetsToBuild(); // in case this is the very first operation ever
            $newFiles = Content2HTML_BatchController::generateSingle($postId, $post->post_type);

            // If the page being edited happens to be the configured front
            // page, additionally refresh and upload the index.html in the
            // root directory as well.
            if ($postId === Content2HTML_BatchController::getFrontPageId()) {
                foreach ($newFiles as $newFile) {
                    if (substr($newFile, -5) === '.html' && Content2HTML_BatchController::copyToRootIndex($newFile)) {
                        $newFiles[] = 'index.html';
                        break;
                    }
                }
            }

            if (empty($newFiles)) {
                wp_send_json_error(['message' => __('No new files were generated - please check the configuration.', 'content2html')]);
            }

            $uploader = Content2HTML_BatchController::buildUploader();
            $buildDir = Content2HTML_BatchController::getBuildDir();

            foreach ($newFiles as $relativePath) {
                $uploader->uploadFile($buildDir . '/' . $relativePath, $relativePath);
            }

            wp_send_json_success([
                'message' => __('Page deployed.', 'content2html'),
                'files' => $newFiles,
            ]);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // -----------------------------------------------------------------
    // Deploy assets only (without regenerating the WordPress content)
    // -----------------------------------------------------------------

    public function handleAssetsOnly(): void {
        $this->checkAccess();

        if (!Content2HTML_AssetsManager::hasAssets()) {
            wp_send_json_error(['message' => __('No assets set up - please upload an assets.zip first.', 'content2html')]);
        }

        try {
            $result = Content2HTML_BatchController::uploadAssetsOnly();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success($result ?: ['message' => __('Assets deployed.', 'content2html')]);
    }

    // -----------------------------------------------------------------
    // Connection tests (SFTP / Netlify) - use the current, not-yet-saved
    // form values; secret fields left empty fall back to the already
    // saved (decrypted) value, mirroring the save logic in
    // Content2HTML_Settings::handleSave().
    // -----------------------------------------------------------------

    public function handleTestSftp(): void {
        $this->checkAccess();

        $existing = Content2HTML_Settings::getSettings();

        $rawAuthMethod = sanitize_key(wp_unslash($_POST['sftp_auth_method'] ?? ''));

        // Deliberately NOT run through sanitize_text_field() or similar:
        // these are opaque secret values (password/private
        // key/passphrase) where such sanitization could corrupt the
        // exact value needed to authenticate (e.g. stripping newlines
        // from a multi-line PEM private key). wp_unslash() alone is
        // sufficient - never echoed back as HTML, only used to attempt
        // an SFTP connection.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $rawPassword = wp_unslash($_POST['sftp_password'] ?? '');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $rawPrivateKey = wp_unslash($_POST['sftp_private_key'] ?? '');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $rawPassphrase = wp_unslash($_POST['sftp_passphrase'] ?? '');

        $settings = [
            'sftp_host' => sanitize_text_field(wp_unslash($_POST['sftp_host'] ?? '')),
            'sftp_port' => max(1, absint(wp_unslash($_POST['sftp_port'] ?? 22))),
            'sftp_username' => sanitize_text_field(wp_unslash($_POST['sftp_username'] ?? '')),
            'sftp_auth_method' => in_array($rawAuthMethod, ['password', 'key'], true)
                ? $rawAuthMethod
                : $existing['sftp_auth_method'],
            'sftp_password' => $rawPassword !== '' ? $rawPassword : $existing['sftp_password'],
            'sftp_private_key' => $rawPrivateKey !== '' ? $rawPrivateKey : $existing['sftp_private_key'],
            'sftp_passphrase' => $rawPassphrase !== '' ? $rawPassphrase : $existing['sftp_passphrase'],
            'sftp_remote_base_path' => '/' . ltrim(sanitize_text_field(wp_unslash($_POST['sftp_remote_base_path'] ?? '/')), '/'),
        ];

        try {
            $uploader = new Content2HTML_SftpUploader($settings);
            $uploader->testConnection();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success(['message' => __('Connection successful, target directory is writable.', 'content2html')]);
    }

    public function handleTestNetlify(): void {
        $this->checkAccess();

        $existing = Content2HTML_Settings::getSettings();

        // Deliberately NOT sanitize_text_field()'d - an opaque API
        // token, never echoed back as HTML (see the same note above for
        // the SFTP credentials).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $rawToken = wp_unslash($_POST['netlify_token'] ?? '');

        $settings = [
            'netlify_site_id' => sanitize_text_field(wp_unslash($_POST['netlify_site_id'] ?? '')),
            'netlify_token' => $rawToken !== '' ? $rawToken : $existing['netlify_token'],
        ];

        try {
            $uploader = new Content2HTML_NetlifyUploader($settings);
            $uploader->testConnection();
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        wp_send_json_success(['message' => __('Connection successful, site found.', 'content2html')]);
    }
}
