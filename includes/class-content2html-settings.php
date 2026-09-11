<?php

if (!defined('ABSPATH')) {
    exit;
}

class Content2HTML_Settings {
    public const OPTION_KEY = 'wpstatic_deploy_settings';
    private const NONCE_ACTION = 'wpstatic_deploy_settings_save';

    // Fields whose values are stored encrypted in the DB (see
    // Content2HTML_Crypto). Everything else is stored as plain text in
    // wp_options (those aren't secrets).
    private const ENCRYPTED_FIELDS = [
        'netlify_token',
        'sftp_password',
        'sftp_private_key',
        'sftp_passphrase',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_wpstatic_deploy_save_settings', [$this, 'handleSave']);
        add_action('admin_post_wpstatic_export_markdown', [$this, 'handleMarkdownExport']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public static function getSettings(): array {
        $stored = (array) get_option(self::OPTION_KEY, []);

        $defaults = [
            'target' => 'sftp', // 'sftp' | 'netlify'
            'post_types' => ['post', 'page'],
            'template_path' => '',
            'extra_templates' => [], // [['id'=>..,'name'=>..,'path'=>..], ...] - for per-page templates
            'forms_enabled' => '',
            'form_recipient_email' => '',
            'form_from_email' => '',
            'form_subject_prefix' => '',
            'form_redirect_url' => '',
            'form_honeypot_field' => '_gotcha',
            'form_custom_action' => '', // leer = unser form-handler.php verwenden

            'nav_main_enabled' => '',
            'nav_main_menu_id' => 0,
            'nav_main_wrapper_marker' => '###MAINMENU###',
            'nav_main_item_marker' => '###ITEM###',
            'nav_main_parent_item_marker' => '',
            'nav_main_submenu_wrapper_marker' => '',
            'nav_main_submenu_item_marker' => '',

            'nav_footer_enabled' => '',
            'nav_footer_menu_id' => 0,
            'nav_footer_wrapper_marker' => '###FOOTERMENU###',
            'nav_footer_item_marker' => '###FOOTERITEM###',
            'nav_footer_parent_item_marker' => '',
            'nav_footer_submenu_wrapper_marker' => '',
            'nav_footer_submenu_item_marker' => '',

            'nav_active_class' => 'active',
            'date_format' => '',
            'remove_wp_tags' => '',
            'data_injection_rules' => '',
            'change_url_rules' => '',
            'tidy_html_rules' => '',

            'netlify_site_id' => '',
            'netlify_token' => '',

            'sftp_host' => '',
            'sftp_port' => 22,
            'sftp_username' => '',
            'sftp_auth_method' => 'password', // 'password' | 'key'
            'sftp_password' => '',
            'sftp_private_key' => '',
            'sftp_passphrase' => '',
            'sftp_remote_base_path' => '/',
        ];

        $settings = wp_parse_args($stored, $defaults);

        // Decrypt the encrypted fields for use in the code. CAUTION:
        // getSettings() therefore returns plain-text secrets - never echo
        // it directly into HTML (see renderPage(), which uses
        // getRawSettingsForForm() instead).
        foreach (self::ENCRYPTED_FIELDS as $field) {
            if (!empty($settings[$field])) {
                $settings[$field] = Content2HTML_Crypto::decrypt($settings[$field]);
            }
        }

        return $settings;
    }

    /**
     * For display in the form: secrets are NOT written into the HTML in
     * plain text (password fields stay empty, with placeholder text
     * "kept as-is if left empty").
     */
    private function getRawSettingsForForm(): array {
        $stored = (array) get_option(self::OPTION_KEY, []);
        return wp_parse_args($stored, self::getSettings());
    }

    /**
     * Resolves an additional template ID (from the per-page selection in
     * the meta box) into the actual file path. Returns null if the ID is
     * empty/unknown or the file doesn't (or no longer) exists - the
     * caller then automatically falls back to the default template.
     */
    public static function resolveTemplatePath(string $templateId): ?string {
        if ($templateId === '') {
            return null;
        }

        $settings = self::getSettings();

        foreach ($settings['extra_templates'] as $tpl) {
            if ($tpl['id'] === $templateId) {
                return is_file($tpl['path']) ? $tpl['path'] : null;
            }
        }

        return null;
    }

    public function registerMenu(): void {
        add_menu_page(
            __('Content2HTML', 'content2html'),
            __('Content2HTML', 'content2html'),
            'manage_options',
            'wpstatic-deploy',
            [$this, 'renderPage'],
            'dashicons-migrate'
        );
    }

    public function enqueueAssets(string $hook): void {
        if ($hook !== 'toplevel_page_wpstatic-deploy') {
            return;
        }

        wp_enqueue_media(); // for the template file upload
        wp_enqueue_style('wpstatic-deploy-admin', WPSTATIC_DEPLOY_URL . 'assets/css/admin.css', [], WPSTATIC_DEPLOY_VERSION);
        wp_enqueue_script('wpstatic-deploy-admin', WPSTATIC_DEPLOY_URL . 'assets/js/admin.js', ['jquery'], WPSTATIC_DEPLOY_VERSION, true);

        wp_localize_script('wpstatic-deploy-admin', 'wpStaticDeploy', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpstatic_deploy_ajax'),
            'batchSize' => 5,
            'i18n' => [
                'testConnection' => __('Test connection', 'content2html'),
                'deployingToTarget' => __('Deploying to target …', 'content2html'),
                'deployAll' => __('Deploy all', 'content2html'),
                'running' => __('Running …', 'content2html'),
                'deployingAssets' => __('Deploying assets …', 'content2html'),
                'deployAssetsOnly' => __('Deploy assets only', 'content2html'),
                'unsavedChangesConfirm' => __('You have unsaved changes in the settings.', 'content2html') . "\n\n"
                    /* translators: %s: name of the action button being confirmed, e.g. "Deploy all" */
                    . __('"%s" uses the last SAVED settings, not your current input.', 'content2html') . "\n\n"
                    . __('Continue anyway?', 'content2html'),
                'unknownError' => __('Unknown error', 'content2html'),
                'unknownErrorPeriod' => __('Unknown error.', 'content2html'),
                'errorPrefix' => __('Error:', 'content2html'),
                'errorRequestFailed' => __('Error processing the request.', 'content2html'),
                'starting' => __('Starting …', 'content2html'),
                'noMatchingPosts' => __('No matching posts/pages found.', 'content2html'),
                'errorStartingDeploy' => __('Error starting the deployment.', 'content2html'),
                'errorBatchProcessing' => __('Error during batch processing.', 'content2html'),
                'errorFinalizing' => __('Error during final upload.', 'content2html'),
                'assetsDeployedWithUrl' => __('Assets deployed. Deploy:', 'content2html'),
                'assetsDeployed' => __('Assets deployed.', 'content2html'),
                'deploymentComplete' => __('Deployment complete.', 'content2html'),
                'testing' => __('Testing …', 'content2html'),
                'requestToWordPressFailed' => __('Request to WordPress failed.', 'content2html'),
            ],
        ]);
    }

    public function handleSave(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'content2html'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $existing = (array) get_option(self::OPTION_KEY, []);

        $postTypes = array_values(array_intersect(
            array_map('sanitize_text_field', wp_unslash((array) ($_POST['post_types'] ?? []))),
            ['post', 'page']
        ));

        $rawTarget = sanitize_key(wp_unslash($_POST['target'] ?? ''));
        $rawSftpAuthMethod = sanitize_key(wp_unslash($_POST['sftp_auth_method'] ?? ''));

        $newValues = [
            'target' => in_array($rawTarget, ['sftp', 'netlify'], true) ? $rawTarget : 'sftp',
            'post_types' => !empty($postTypes) ? $postTypes : ['post', 'page'],
            'date_format' => sanitize_text_field(wp_unslash($_POST['date_format'] ?? '')),
            'remove_wp_tags' => !empty($_POST['remove_wp_tags']) ? 'on' : '',
            'data_injection_rules' => sanitize_textarea_field(wp_unslash($_POST['data_injection_rules'] ?? '')),
            'change_url_rules' => sanitize_textarea_field(wp_unslash($_POST['change_url_rules'] ?? '')),
            'tidy_html_rules' => sanitize_textarea_field(wp_unslash($_POST['tidy_html_rules'] ?? '')),

            'netlify_site_id' => sanitize_text_field(wp_unslash($_POST['netlify_site_id'] ?? '')),

            'sftp_host' => sanitize_text_field(wp_unslash($_POST['sftp_host'] ?? '')),
            'sftp_port' => max(1, absint(wp_unslash($_POST['sftp_port'] ?? 22))),
            'sftp_username' => sanitize_text_field(wp_unslash($_POST['sftp_username'] ?? '')),
            'sftp_auth_method' => in_array($rawSftpAuthMethod, ['password', 'key'], true) ? $rawSftpAuthMethod : 'password',
            'sftp_remote_base_path' => '/' . ltrim(sanitize_text_field(wp_unslash($_POST['sftp_remote_base_path'] ?? '/')), '/'),

            'forms_enabled' => !empty($_POST['forms_enabled']) ? 'on' : '',
            'form_recipient_email' => sanitize_text_field(wp_unslash($_POST['form_recipient_email'] ?? '')),
            'form_from_email' => sanitize_text_field(wp_unslash($_POST['form_from_email'] ?? '')),
            'form_subject_prefix' => sanitize_text_field(wp_unslash($_POST['form_subject_prefix'] ?? '')),
            'form_redirect_url' => esc_url_raw(wp_unslash($_POST['form_redirect_url'] ?? '')),
            'form_honeypot_field' => preg_replace('/[^a-zA-Z0-9_-]/', '', sanitize_text_field(wp_unslash($_POST['form_honeypot_field'] ?? ''))) ?: '_gotcha',
            'form_custom_action' => sanitize_text_field(wp_unslash($_POST['form_custom_action'] ?? '')),

            'nav_main_enabled' => !empty($_POST['nav_main_enabled']) ? 'on' : '',
            'nav_main_menu_id' => absint(wp_unslash($_POST['nav_main_menu_id'] ?? 0)),
            'nav_main_wrapper_marker' => sanitize_text_field(wp_unslash($_POST['nav_main_wrapper_marker'] ?? '')),
            'nav_main_item_marker' => sanitize_text_field(wp_unslash($_POST['nav_main_item_marker'] ?? '')),
            'nav_main_parent_item_marker' => sanitize_text_field(wp_unslash($_POST['nav_main_parent_item_marker'] ?? '')),
            'nav_main_submenu_wrapper_marker' => sanitize_text_field(wp_unslash($_POST['nav_main_submenu_wrapper_marker'] ?? '')),
            'nav_main_submenu_item_marker' => sanitize_text_field(wp_unslash($_POST['nav_main_submenu_item_marker'] ?? '')),

            'nav_footer_enabled' => !empty($_POST['nav_footer_enabled']) ? 'on' : '',
            'nav_footer_menu_id' => absint(wp_unslash($_POST['nav_footer_menu_id'] ?? 0)),
            'nav_footer_wrapper_marker' => sanitize_text_field(wp_unslash($_POST['nav_footer_wrapper_marker'] ?? '')),
            'nav_footer_item_marker' => sanitize_text_field(wp_unslash($_POST['nav_footer_item_marker'] ?? '')),
            'nav_footer_parent_item_marker' => sanitize_text_field(wp_unslash($_POST['nav_footer_parent_item_marker'] ?? '')),
            'nav_footer_submenu_wrapper_marker' => sanitize_text_field(wp_unslash($_POST['nav_footer_submenu_wrapper_marker'] ?? '')),
            'nav_footer_submenu_item_marker' => sanitize_text_field(wp_unslash($_POST['nav_footer_submenu_item_marker'] ?? '')),

            'nav_active_class' => preg_replace('/[^a-zA-Z0-9_ -]/', '', sanitize_text_field(wp_unslash($_POST['nav_active_class'] ?? ''))) ?: 'active',
        ];

        // File upload for the template (via wp_handle_upload, not via a
        // media library attachment - this keeps it independent of whether
        // the user uses the file upload button or the media library
        // picker).
        if (!empty($_FILES['template_file']['tmp_name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $uploaded = wp_handle_upload($_FILES['template_file'], [
                'test_form' => false,
                'mimes' => ['html' => 'text/html', 'htm' => 'text/html'],
            ]);

            if (isset($uploaded['file'])) {
                $newValues['template_path'] = $uploaded['file'];
            } else {
                set_transient('wpstatic_template_upload_error', $uploaded['error'] ?? __('Unknown error while uploading the default template.', 'content2html'), MINUTE_IN_SECONDS * 5);
            }
        } elseif (!empty($_POST['template_path_existing'])) {
            $newValues['template_path'] = sanitize_text_field(wp_unslash($_POST['template_path_existing']));
        }

        // Additional (named) templates for the per-page selection: existing
        // ones can be removed via a checkbox, a new one can optionally be
        // added with a name + file.
        $keepTemplates = [];

        foreach ((array) ($existing['extra_templates'] ?? []) as $tpl) {
            if (!empty($_POST['remove_template_' . $tpl['id']])) {
                continue; // deselected -> remove
            }
            $keepTemplates[] = $tpl;
        }

        if (!empty($_POST['new_template_name'])) {
            if (!empty($_FILES['new_template_file']['tmp_name'])) {
                require_once ABSPATH . 'wp-admin/includes/file.php';

                $uploadedTemplate = wp_handle_upload($_FILES['new_template_file'], [
                    'test_form' => false,
                    // Forces .html as the allowed extension for this upload,
                    // regardless of the rest of WP's mime configuration - see
                    // the explanation below for why this can be necessary.
                    'mimes' => ['html' => 'text/html', 'htm' => 'text/html'],
                ]);

                if (isset($uploadedTemplate['file'])) {
                    $keepTemplates[] = [
                        'id' => 'tpl_' . uniqid(),
                        'name' => sanitize_text_field(wp_unslash($_POST['new_template_name'])),
                        'path' => $uploadedTemplate['file'],
                    ];
                } else {
                    set_transient('wpstatic_template_upload_error', $uploadedTemplate['error'] ?? __('Unknown error while uploading.', 'content2html'), MINUTE_IN_SECONDS * 5);
                }
            } elseif (isset($_FILES['new_template_file']['error']) && $_FILES['new_template_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                set_transient('wpstatic_template_upload_error', self::uploadErrorMessage((int) $_FILES['new_template_file']['error']), MINUTE_IN_SECONDS * 5);
            } else {
                set_transient('wpstatic_template_upload_error', __('A name for the new template was given, but no file was selected.', 'content2html'), MINUTE_IN_SECONDS * 5);
            }
        } elseif (!empty($_FILES['new_template_file']['tmp_name'])) {
            // A common user error (the exact one we ran into ourselves):
            // a file was selected, but no name was given - previously
            // ignored silently, now with a clear message.
            set_transient('wpstatic_template_upload_error', __('Please enter a name for the new template (required) - the file was therefore not saved.', 'content2html'), MINUTE_IN_SECONDS * 5);
        }

        $newValues['extra_templates'] = $keepTemplates;

        // Encrypted fields: only overwrite if a new value was actually
        // entered (an empty field means "leave unchanged", so you don't
        // have to retype every password on every save).
        foreach (self::ENCRYPTED_FIELDS as $field) {
            // Deliberately NOT run through sanitize_text_field() or
            // similar: these are opaque secret values (password/private
            // key/passphrase/API token) where such sanitization could
            // corrupt the exact value the user needs to authenticate
            // (e.g. stripping newlines from a multi-line PEM private
            // key). wp_unslash() alone is sufficient here - the value is
            // never echoed back as HTML and goes straight into
            // Content2HTML_Crypto::encrypt().
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw = wp_unslash($_POST[$field] ?? '');

            if ($raw !== '') {
                $newValues[$field] = Content2HTML_Crypto::encrypt($raw);
            } else {
                $newValues[$field] = $existing[$field] ?? '';
            }
        }

        if ($newValues['forms_enabled'] === 'on' && $newValues['target'] === 'sftp' && $newValues['form_recipient_email'] === '') {
            set_transient(
                'wpstatic_forms_warning',
                __('Forms are enabled, but no recipient email address has been set - the form handler will reject incoming submissions until this is fixed.', 'content2html'),
                MINUTE_IN_SECONDS * 5
            );
        }

        if (
            $newValues['forms_enabled'] === 'on'
            && $newValues['target'] === 'netlify'
            && $newValues['form_redirect_url'] !== ''
        ) {
            $redirectHost = wp_parse_url($newValues['form_redirect_url'], PHP_URL_HOST);
            $ownHost = wp_parse_url(home_url(), PHP_URL_HOST);

            if ($redirectHost !== null && $redirectHost === $ownHost) {
                set_transient(
                    'wpstatic_forms_warning',
                    sprintf(
                        /* translators: %s: hostname */
                        __('The form "thank you" page points to your WordPress domain (%s). With Netlify as the target, the form would then submit DIRECTLY to WordPress instead of Netlify - the submission would never show up in Netlify\'s Forms overview. Please leave it empty or enter a relative address on the Netlify site itself (e.g. /thank-you/).', 'content2html'),
                        $redirectHost
                    ),
                    MINUTE_IN_SECONDS * 5
                );
            }
        }

        update_option(self::OPTION_KEY, array_merge($existing, $newValues));

        if (isset($_FILES['assets_zip']['error']) && $_FILES['assets_zip']['error'] !== UPLOAD_ERR_NO_FILE) {
            $error = (int) $_FILES['assets_zip']['error'];

            if ($error !== UPLOAD_ERR_OK) {
                $assetsResult = [
                    'ok' => false,
                    'message' => self::uploadErrorMessage($error),
                    'warnings' => [],
                ];
            } else {
                $assetsResult = isset($_FILES['assets_zip']['tmp_name'])
                    ? Content2HTML_AssetsManager::extractZip(sanitize_text_field(wp_unslash($_FILES['assets_zip']['tmp_name'])))
                    : ['ok' => false, 'message' => __('Unknown error while uploading.', 'content2html'), 'warnings' => []];
            }

            set_transient('wpstatic_assets_upload_result', $assetsResult, MINUTE_IN_SECONDS * 5);
        } elseif (!empty($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > self::iniSizeToBytes(ini_get('post_max_size'))) {
            // post_max_size exceeded: PHP then discards the entire request
            // body including $_FILES, without any error code - this is
            // the only way to detect it indirectly.
            set_transient('wpstatic_assets_upload_result', [
                'ok' => false,
                'message' => sprintf(
                    /* translators: %s: post_max_size ini value */
                    __('The request exceeds the server limit post_max_size (currently: %s). The file is too large to upload in this form.', 'content2html'),
                    ini_get('post_max_size')
                ),
                'warnings' => [],
            ], MINUTE_IN_SECONDS * 5);
        }

        wp_safe_redirect(add_query_arg(['page' => 'wpstatic-deploy', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    private static function iniSizeToBytes(string $value): int {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        switch ($unit) {
            case 'g':
                return $number * 1024 * 1024 * 1024;
            case 'm':
                return $number * 1024 * 1024;
            case 'k':
                return $number * 1024;
            default:
                return $number;
        }
    }

    public function handleMarkdownExport(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'content2html'));
        }

        check_admin_referer('wpstatic_export_markdown');

        $settings = self::getSettings();
        $localizeImages = !empty($_POST['markdown_localize_images']);

        try {
            $zipPath = Content2HTML_MarkdownExport::buildZip($settings, $localizeImages);
        } catch (Throwable $e) {
            wp_die(esc_html__('Export failed:', 'content2html') . ' ' . esc_html($e->getMessage()));
        }

        if (!is_file($zipPath)) {
            wp_die(esc_html__('Export failed: the ZIP file was not created.', 'content2html'));
        }

        $downloadName = 'markdown-export-' . gmdate('Ymd_His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($zipPath));

        // This is a raw binary ZIP file download (Content-Type/
        // Content-Disposition/Content-Length headers above already
        // mark it as such) - not HTML output. Running it through
        // esc_html() would corrupt the binary data and break the
        // download.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo Content2HTML_Filesystem::getContents($zipPath);

        Content2HTML_Filesystem::deleteFile($zipPath);
        exit;
    }

    /**
     * Outputs the form fields for a navigation (main or footer).
     * $prefix is the settings key prefix ("nav_main_" / "nav_footer_").
     */
    private function renderNavigationFields(string $prefix, string $label, array $settings): void {
        $menus = wp_get_nav_menus();
        ?>
        <h3><?php echo esc_html($label); ?></h3>
        <table class="form-table wpstatic-nav-fields" data-prefix="<?php echo esc_attr($prefix); ?>">
            <tr>
                <th><?php esc_html_e('Generate automatically', 'content2html'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" class="wpstatic-nav-enabled" name="<?php echo esc_attr($prefix); ?>enabled" <?php checked('on', $settings[$prefix . 'enabled']); ?>>
                        <?php esc_html_e('Replace navigation markers in the template with real menu items', 'content2html'); ?>
                    </label>
                </td>
            </tr>
            <tr class="wpstatic-nav-detail">
                <th><label for="<?php echo esc_attr($prefix); ?>menu_id"><?php esc_html_e('WordPress menu', 'content2html'); ?></label></th>
                <td>
                    <select id="<?php echo esc_attr($prefix); ?>menu_id" name="<?php echo esc_attr($prefix); ?>menu_id">
                        <option value="0">– <?php esc_html_e('Select a menu', 'content2html'); ?> –</option>
                        <?php foreach ($menus as $menu): ?>
                            <option value="<?php echo esc_attr((string) $menu->term_id); ?>" <?php selected((int) $settings[$prefix . 'menu_id'], $menu->term_id); ?>>
                                <?php echo esc_html($menu->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($menus)): ?>
                        <p class="description"><?php esc_html_e('No menus found - create one first under Appearance → Menus.', 'content2html'); ?></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="wpstatic-nav-detail">
                <th><label for="<?php echo esc_attr($prefix); ?>wrapper_marker"><?php esc_html_e('Wrapper marker', 'content2html'); ?></label></th>
                <td>
                    <input type="text" id="<?php echo esc_attr($prefix); ?>wrapper_marker" name="<?php echo esc_attr($prefix); ?>wrapper_marker" value="<?php echo esc_attr($settings[$prefix . 'wrapper_marker']); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Wraps the ENTIRE menu area in the template (e.g. the outer <ul>).', 'content2html'); ?></p>
                </td>
            </tr>
            <tr class="wpstatic-nav-detail">
                <th><label for="<?php echo esc_attr($prefix); ?>item_marker"><?php esc_html_e('Item marker', 'content2html'); ?></label></th>
                <td>
                    <input type="text" id="<?php echo esc_attr($prefix); ?>item_marker" name="<?php echo esc_attr($prefix); ?>item_marker" value="<?php echo esc_attr($settings[$prefix . 'item_marker']); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Wraps EXACTLY ONE demo menu item without sub-items (e.g. one <li>) - duplicated for each real menu item.', 'content2html'); ?></p>
                </td>
            </tr>
            <tr class="wpstatic-nav-detail">
                <th><label for="<?php echo esc_attr($prefix); ?>parent_item_marker"><?php esc_html_e('Parent item marker (optional)', 'content2html'); ?></label></th>
                <td>
                    <input type="text" id="<?php echo esc_attr($prefix); ?>parent_item_marker" name="<?php echo esc_attr($prefix); ?>parent_item_marker" value="<?php echo esc_attr($settings[$prefix . 'parent_item_marker']); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Only needed for multi-level menus. Without this marker, sub-items are ignored (flat display).', 'content2html'); ?></p>
                </td>
            </tr>
            <tr class="wpstatic-nav-detail">
                <th><label for="<?php echo esc_attr($prefix); ?>submenu_wrapper_marker"><?php esc_html_e('Submenu wrapper marker', 'content2html'); ?></label></th>
                <td>
                    <input type="text" id="<?php echo esc_attr($prefix); ?>submenu_wrapper_marker" name="<?php echo esc_attr($prefix); ?>submenu_wrapper_marker" value="<?php echo esc_attr($settings[$prefix . 'submenu_wrapper_marker']); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Only relevant if "Parent item marker" is set - wraps the container for sub-items INSIDE the parent template.', 'content2html'); ?></p>
                </td>
            </tr>
            <tr class="wpstatic-nav-detail">
                <th><label for="<?php echo esc_attr($prefix); ?>submenu_item_marker"><?php esc_html_e('Submenu item marker (optional)', 'content2html'); ?></label></th>
                <td>
                    <input type="text" id="<?php echo esc_attr($prefix); ?>submenu_item_marker" name="<?php echo esc_attr($prefix); ?>submenu_item_marker" value="<?php echo esc_attr($settings[$prefix . 'submenu_item_marker']); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Leave empty to use the same template as "Item marker" for sub-items.', 'content2html'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Translates PHP upload error codes into understandable messages -
     * especially the size-limit cases, which otherwise fail silently
     * (an empty $_FILES['tmp_name'] with no explanation for the user).
     */
    private static function uploadErrorMessage(int $error): string {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
                return sprintf(
                    /* translators: %s: upload_max_filesize ini value */
                    __('The file exceeds the server limit upload_max_filesize (currently: %s). Please shrink the ZIP or ask your host to raise the limit.', 'content2html'),
                    ini_get('upload_max_filesize')
                );
            case UPLOAD_ERR_FORM_SIZE:
                return __('The file exceeds the maximum allowed by the form.', 'content2html');
            case UPLOAD_ERR_PARTIAL:
                return __('The file was only partially uploaded. Please try again.', 'content2html');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Server error: no temporary directory available for uploads.', 'content2html');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Server error: the file could not be written to disk.', 'content2html');
            case UPLOAD_ERR_EXTENSION:
                return __('The upload was stopped by a PHP extension.', 'content2html');
            default:
                return sprintf(
                    /* translators: %d: PHP upload error code */
                    __('Unknown upload error (code %d).', 'content2html'),
                    $error
                );
        }
    }

    public function renderPage(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->getRawSettingsForForm();
        $hasVendor = file_exists(WPSTATIC_DEPLOY_DIR . 'vendor/autoload.php');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Content2HTML – Settings', 'content2html'); ?></h1>

            <div id="wpstatic-unsaved-notice" class="notice notice-warning" style="display:none;">
                <p>
                    <strong><?php esc_html_e('You have unsaved changes.', 'content2html'); ?></strong>
                    <?php esc_html_e('"Deploy all" and "Deploy assets only" use the last', 'content2html'); ?>
                    <strong><?php esc_html_e('saved', 'content2html'); ?></strong> <?php esc_html_e('settings, not your current input - please click "Save settings" first.', 'content2html'); ?>
                </p>
            </div>

            <?php
            // Purely a display flag (shows a "Settings saved" notice
            // after the redirect that follows a successful save) - no
            // state change happens here, the actual save already went
            // through check_admin_referer() in handleSave(). Nothing to
            // verify a nonce against.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'content2html'); ?></p></div>
            <?php endif; ?>

            <?php
            $assetsUploadResult = get_transient('wpstatic_assets_upload_result');
            if ($assetsUploadResult) {
                delete_transient('wpstatic_assets_upload_result');
                $noticeClass = $assetsUploadResult['ok'] ? 'notice-success' : 'notice-error';
                ?>
                <div class="notice <?php echo esc_attr($noticeClass); ?> is-dismissible">
                    <p><?php echo esc_html($assetsUploadResult['message']); ?></p>
                    <?php if (!empty($assetsUploadResult['warnings'])): ?>
                        <p><strong><?php esc_html_e('Note: potentially executable file types were found and removed from the assets for security reasons', 'content2html'); ?></strong>:</p>
                        <ul style="list-style: disc; margin-left: 20px;">
                            <?php foreach ($assetsUploadResult['warnings'] as $warning): ?>
                                <li><code><?php echo esc_html($warning); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php
            }
            ?>

            <?php
            $templateUploadError = get_transient('wpstatic_template_upload_error');
            if ($templateUploadError) {
                delete_transient('wpstatic_template_upload_error');
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>Template-Upload fehlgeschlagen:</strong> <?php echo esc_html($templateUploadError); ?></p>
                </div>
                <?php
            }

            $formsWarning = get_transient('wpstatic_forms_warning');
            if ($formsWarning) {
                delete_transient('wpstatic_forms_warning');
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p><?php echo esc_html($formsWarning); ?></p>
                </div>
                <?php
            }
            ?>

            <?php if (!Content2HTML_Crypto::usesConfigKey()): ?>
                <div class="notice notice-warning">
                    <p>
                        <?php esc_html_e('No', 'content2html'); ?> <code>WPSTATIC_ENCRYPTION_KEY</code> <?php esc_html_e('found in', 'content2html'); ?> <code>wp-config.php</code>.
                        <?php esc_html_e('Secrets are still stored encrypted, but with an auto-generated key that also lives in the database. Recommended:', 'content2html'); ?>
                        <code>define('WPSTATIC_ENCRYPTION_KEY', '<?php esc_html_e('a-long-random-value', 'content2html'); ?>');</code>
                        <?php esc_html_e('add to wp-config.php.', 'content2html'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ($settings['target'] === 'sftp' && !$hasVendor): ?>
                <div class="notice notice-error">
                    <p>
                        <?php esc_html_e('SFTP target selected, but', 'content2html'); ?> <code>vendor/phpseclib</code> <?php esc_html_e('was not found. See README.md in the plugin directory for installation.', 'content2html'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form id="wpstatic-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="wpstatic_deploy_save_settings">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>

                <h2 class="nav-tab-wrapper wpstatic-tabs">
                    <a href="#" class="nav-tab" data-tab="inhalte"><?php esc_html_e('Content', 'content2html'); ?></a>
                    <a href="#" class="nav-tab" data-tab="navigation"><?php esc_html_e('Navigation', 'content2html'); ?></a>
                    <a href="#" class="nav-tab" data-tab="formulare"><?php esc_html_e('Forms', 'content2html'); ?></a>
                    <a href="#" class="nav-tab" data-tab="ziel"><?php esc_html_e('Deployment target', 'content2html'); ?></a>
                    <a href="#" class="nav-tab" data-tab="markdown"><?php esc_html_e('Markdown export', 'content2html'); ?></a>
                </h2>

                <div class="wpstatic-tab-panel" data-tab-panel="inhalte">
                <h2 class="title"><?php esc_html_e('Content', 'content2html'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Post Types', 'content2html'); ?></th>
                        <td>
                            <label><input type="checkbox" name="post_types[]" value="post" <?php checked(in_array('post', $settings['post_types'], true)); ?>> <?php esc_html_e('Posts', 'content2html'); ?></label><br>
                            <label><input type="checkbox" name="post_types[]" value="page" <?php checked(in_array('page', $settings['post_types'], true)); ?>> <?php esc_html_e('Pages', 'content2html'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wpstatic_template"><?php esc_html_e('Template file', 'content2html'); ?></label></th>
                        <td>
                            <?php if (!empty($settings['template_path'])): ?>
                                <p><?php esc_html_e('Current:', 'content2html'); ?> <code><?php echo esc_html(basename($settings['template_path'])); ?></code></p>
                                <input type="hidden" name="template_path_existing" value="<?php echo esc_attr($settings['template_path']); ?>">
                            <?php endif; ?>
                            <input type="file" id="wpstatic_template" name="template_file" accept=".html,.htm">
                            <p class="description"><?php esc_html_e('HTML file with placeholders (e.g.', 'content2html'); ?> <code>###title###</code>). <?php esc_html_e('Leave empty to keep the current file.', 'content2html'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Additional templates', 'content2html'); ?></th>
                        <td>
                            <?php if (!empty($settings['extra_templates'])): ?>
                                <table class="widefat" style="max-width: 500px; margin-bottom: 10px;">
                                    <thead><tr><th><?php esc_html_e('Name', 'content2html'); ?></th><th><?php esc_html_e('File', 'content2html'); ?></th><th><?php esc_html_e('Remove', 'content2html'); ?></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($settings['extra_templates'] as $tpl): ?>
                                            <tr>
                                                <td><?php echo esc_html($tpl['name']); ?></td>
                                                <td><code><?php echo esc_html(basename($tpl['path'])); ?></code></td>
                                                <td><label><input type="checkbox" name="remove_template_<?php echo esc_attr($tpl['id']); ?>" value="1"></label></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p class="description"><?php esc_html_e('No additional templates set up yet.', 'content2html'); ?></p>
                            <?php endif; ?>

                            <p>
                                <label for="new_template_name"><?php esc_html_e('Add new template (name required):', 'content2html'); ?></label><br>
                                <input type="text" id="new_template_name" name="new_template_name" class="regular-text" placeholder="<?php echo esc_attr__('e.g. Landing page (required so it appears in the dropdown)', 'content2html'); ?>">
                                <input type="file" id="new_template_file" name="new_template_file" accept=".html,.htm">
                            </p>
                            <p class="description">
                                <?php esc_html_e('Then available per post/page as an alternative to the default template (dropdown in the "Content2HTML" meta box on the edit screen).', 'content2html'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wpstatic_assets">Assets (CSS/JS/Fonts/Images)</label></th>
                        <td>
                            <?php $assetsStatus = Content2HTML_AssetsManager::getStatus(); ?>
                            <?php if ($assetsStatus['exists']): ?>
                                <p>
                                    <?php esc_html_e('Currently set up:', 'content2html'); ?> <strong><?php echo esc_html((string) $assetsStatus['file_count']); ?> <?php esc_html_e('files', 'content2html'); ?></strong>
                                    (<?php echo esc_html(size_format($assetsStatus['total_size'])); ?>)
                                    <?php if (!empty($assetsStatus['updated_at'])): ?>
                                        &ndash; <?php esc_html_e('last updated on', 'content2html'); ?> <?php echo esc_html(date_i18n('d.m.Y H:i', $assetsStatus['updated_at'])); ?>
                                    <?php endif; ?>
                                </p>
                            <?php else: ?>
                                <p><?php esc_html_e('No assets set up yet.', 'content2html'); ?></p>
                            <?php endif; ?>
                            <input type="file" id="wpstatic_assets" name="assets_zip" accept=".zip">
                            <p class="description">
                                <?php esc_html_e('ZIP file whose root already is a folder named', 'content2html'); ?> <code>assets/</code>
                                (<?php esc_html_e('i.e. zip the assets folder itself, not just its contents', 'content2html'); ?>).
                                <?php esc_html_e('Copied 1:1 into the build folder so template references like', 'content2html'); ?>
                                <code>href="assets/css/styles.css"</code> <?php esc_html_e('work.', 'content2html'); ?>
                            </p>
                            <p class="description">
                                <?php esc_html_e('Server upload limit: max.', 'content2html'); ?> <code><?php echo esc_html(ini_get('upload_max_filesize')); ?></code>
                                <?php esc_html_e('per file,', 'content2html'); ?> <code><?php echo esc_html(ini_get('post_max_size')); ?></code> <?php esc_html_e('per form submission overall. If your ZIP is bigger, please compress/trim assets first (e.g. remove unused font weights) or ask your host to raise the limit.', 'content2html'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="date_format"><?php esc_html_e('Date format', 'content2html'); ?></label></th>
                        <td><input type="text" id="date_format" name="date_format" value="<?php echo esc_attr($settings['date_format']); ?>" class="regular-text" placeholder="d.m.Y"></td>
                    </tr>
                    <tr>
                        <th><label for="remove_wp_tags"><?php esc_html_e('Remove WP CSS classes', 'content2html'); ?></label></th>
                        <td><label><input type="checkbox" id="remove_wp_tags" name="remove_wp_tags" <?php checked('on', $settings['remove_wp_tags']); ?>> <?php esc_html_e('active', 'content2html'); ?></label></td>
                    </tr>
                    <tr>
                        <th><label for="data_injection_rules"><?php esc_html_e('Data injection rules', 'content2html'); ?></label></th>
                        <td><textarea id="data_injection_rules" name="data_injection_rules" rows="6" class="large-text code" placeholder="title->rendered => ###title###"><?php echo esc_textarea($settings['data_injection_rules']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="change_url_rules"><?php esc_html_e('Change-URL rules', 'content2html'); ?></label></th>
                        <td><textarea id="change_url_rules" name="change_url_rules" rows="4" class="large-text code"><?php echo esc_textarea($settings['change_url_rules']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="tidy_html_rules"><?php esc_html_e('Tidy HTML rules', 'content2html'); ?></label></th>
                        <td><textarea id="tidy_html_rules" name="tidy_html_rules" rows="4" class="large-text code"><?php echo esc_textarea($settings['tidy_html_rules']); ?></textarea></td>
                    </tr>
                </table>
                </div>

                <div class="wpstatic-tab-panel" data-tab-panel="formulare">
                <h2 class="title"><?php esc_html_e('Forms', 'content2html'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Enable forms', 'content2html'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="forms_enabled" <?php checked('on', $settings['forms_enabled']); ?>>
                                <?php esc_html_e('Make &lt;form&gt; tags on generated pages functional', 'content2html'); ?>
                            </label>
                            <p class="description">
                                <strong>Netlify:</strong> <?php esc_html_e('forms automatically get', 'content2html'); ?> <code>data-netlify="true"</code>
                                <?php esc_html_e('and a hidden', 'content2html'); ?> <code>form-name</code> <?php esc_html_e('field - Netlify Forms then detects and processes them on its own, no server code needed.', 'content2html'); ?><br>
                                <strong>SFTP:</strong> <?php esc_html_e('the form', 'content2html'); ?> <code>action</code> <?php esc_html_e('is rewritten to a bundled PHP handler', 'content2html'); ?> (<code>form-handler.php</code>) <?php esc_html_e('that emails submissions. Requires the target server to run PHP.', 'content2html'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="form_recipient_email"><?php esc_html_e('Recipient email (SFTP only)', 'content2html'); ?></label></th>
                        <td><input type="email" id="form_recipient_email" name="form_recipient_email" value="<?php echo esc_attr($settings['form_recipient_email']); ?>" class="regular-text" placeholder="kontakt@example.com"></td>
                    </tr>
                    <tr>
                        <th><label for="form_from_email"><?php esc_html_e('Sender address (SFTP only)', 'content2html'); ?></label></th>
                        <td>
                            <input type="email" id="form_from_email" name="form_from_email" value="<?php echo esc_attr($settings['form_from_email']); ?>" class="regular-text" placeholder="noreply@example.com">
                            <p class="description"><?php esc_html_e('Leave empty to use the server default address. Many hosts only accept sender addresses from their own domain.', 'content2html'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="form_subject_prefix"><?php esc_html_e('Subject prefix (SFTP only)', 'content2html'); ?></label></th>
                        <td><input type="text" id="form_subject_prefix" name="form_subject_prefix" value="<?php echo esc_attr($settings['form_subject_prefix']); ?>" class="regular-text" placeholder="[Kontaktformular]"></td>
                    </tr>
                    <tr>
                        <th><label for="form_redirect_url"><?php esc_html_e('Thank-you page (Netlify + SFTP)', 'content2html'); ?></label></th>
                        <td>
                            <input type="url" id="form_redirect_url" name="form_redirect_url" value="<?php echo esc_attr($settings['form_redirect_url']); ?>" class="regular-text" placeholder="https://example.com/danke/">
                            <p class="description">
                                <?php esc_html_e('Leave empty to redirect back to the originating page after submission automatically. Important for Netlify: the form\'s original', 'content2html'); ?> <code>action</code> (<?php esc_html_e('points to a WordPress URL', 'content2html'); ?>) <?php esc_html_e('is always replaced - either by this thank-you page, or, if empty, by removing the', 'content2html'); ?> <code>action</code> (<?php esc_html_e('submits to the current page', 'content2html'); ?>).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="form_honeypot_field"><?php esc_html_e('Honeypot field name (SFTP only)', 'content2html'); ?></label></th>
                        <td>
                            <input type="text" id="form_honeypot_field" name="form_honeypot_field" value="<?php echo esc_attr($settings['form_honeypot_field']); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Simple spam deterrent: a CSS-hidden field that only bots fill in. Letters/numbers/underscore/hyphen only.', 'content2html'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="form_custom_action"><?php esc_html_e('Custom form target (SFTP only)', 'content2html'); ?></label></th>
                        <td>
                            <input type="url" id="form_custom_action" name="form_custom_action" value="<?php echo esc_attr($settings['form_custom_action']); ?>" class="regular-text" placeholder="https://example.com/mein-eigener-handler.php">
                            <p class="description">
                                <?php esc_html_e('Leave empty to use our bundled PHP handler', 'content2html'); ?> (<code>/form-handler.php</code>) - <?php esc_html_e('requires the target server to run PHP. If something else runs there (e.g. your own endpoint, an external form service), enter its URL here - the form', 'content2html'); ?> <code>action</code> <?php esc_html_e('is then rewritten to it, and our', 'content2html'); ?> <code>form-handler.php</code> <?php esc_html_e('is not created/uploaded in that case.', 'content2html'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Client-side validation', 'content2html'); ?></h3>
                <p class="description">
                    <?php esc_html_e('Runs automatically as soon as forms are enabled above (for Netlify AND SFTP) - no extra toggle needed. Checks in the browser BEFORE submitting: fields with', 'content2html'); ?>
                    <code>aria-required="true"</code> <?php esc_html_e('must be filled in (for checkbox/radio groups: at least one option),', 'content2html'); ?>
                    <code>type="email"</code> <?php esc_html_e('fields must look like an email address. Most accessible form plugins (Fluent Forms, WPForms, Gravity Forms etc.) already set these attributes automatically - no configuration needed. If your form plugin doesn\'t set these attributes, the check simply doesn\'t apply (data is then submitted unvalidated, as before).', 'content2html'); ?>
                </p>
                </div>

                <div class="wpstatic-tab-panel" data-tab-panel="navigation">
                <h2 class="title"><?php esc_html_e('Navigation', 'content2html'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Replaces marker comments in the template with real menu items from a WordPress menu. Details for each marker are right next to the respective fields below.', 'content2html'); ?>
                </p>
                <?php $this->renderNavigationFields('nav_main_', __('Main navigation', 'content2html'), $settings); ?>
                <?php $this->renderNavigationFields('nav_footer_', __('Footer navigation', 'content2html'), $settings); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="nav_active_class"><?php esc_html_e('CSS class for active page', 'content2html'); ?></label></th>
                        <td>
                            <input type="text" id="nav_active_class" name="nav_active_class" value="<?php echo esc_attr($settings['nav_active_class']); ?>" class="regular-text" placeholder="active">
                            <p class="description"><?php esc_html_e('Set on the first &lt;a&gt; tag of the menu item matching the page currently being generated - applies to main and footer navigation alike.', 'content2html'); ?></p>
                        </td>
                    </tr>
                </table>
                </div>

                <div class="wpstatic-tab-panel" data-tab-panel="ziel">
                <h2 class="title"><?php esc_html_e('Deployment target', 'content2html'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Target', 'content2html'); ?></th>
                        <td>
                            <label><input type="radio" name="target" value="sftp" <?php checked('sftp', $settings['target']); ?> class="wpstatic-target-radio"> SFTP</label>
                            &nbsp;&nbsp;
                            <label><input type="radio" name="target" value="netlify" <?php checked('netlify', $settings['target']); ?> class="wpstatic-target-radio"> Netlify</label>
                        </td>
                    </tr>
                </table>

                <div id="wpstatic-target-netlify" class="wpstatic-target-section">
                    <h3>Netlify</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="netlify_site_id">Site ID</label></th>
                            <td><input type="text" id="netlify_site_id" name="netlify_site_id" value="<?php echo esc_attr($settings['netlify_site_id']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="netlify_token">Personal Access Token</label></th>
                            <td>
                                <input type="password" id="netlify_token" name="netlify_token" value="" class="regular-text" autocomplete="new-password">
                                <p class="description"><?php echo !empty($settings['netlify_token']) ? esc_html__('A token is already saved. Leave empty to keep it.', 'content2html') : esc_html__('No token saved yet.', 'content2html'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" class="button" id="wpstatic-test-netlify-btn"><?php esc_html_e('Test connection', 'content2html'); ?></button>
                        <span id="wpstatic-test-netlify-result" class="wpstatic-test-result"></span>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Important: Netlify deploys always replace the entire site content. The single-page button (on every post/page edit screen) therefore triggers a full rebuild + redeploy on Netlify, not just an update of that one page.', 'content2html'); ?>
                    </p>
                </div>

                <div id="wpstatic-target-sftp" class="wpstatic-target-section">
                    <h3>SFTP</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="sftp_host">Host</label></th>
                            <td><input type="text" id="sftp_host" name="sftp_host" value="<?php echo esc_attr($settings['sftp_host']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sftp_port">Port</label></th>
                            <td><input type="number" id="sftp_port" name="sftp_port" value="<?php echo esc_attr((string) $settings['sftp_port']); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><label for="sftp_username"><?php esc_html_e('Username', 'content2html'); ?></label></th>
                            <td><input type="text" id="sftp_username" name="sftp_username" value="<?php echo esc_attr($settings['sftp_username']); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Authentication', 'content2html'); ?></th>
                            <td>
                                <label><input type="radio" name="sftp_auth_method" value="password" <?php checked('password', $settings['sftp_auth_method']); ?> class="wpstatic-sftp-auth-radio"> <?php esc_html_e('Password', 'content2html'); ?></label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="sftp_auth_method" value="key" <?php checked('key', $settings['sftp_auth_method']); ?> class="wpstatic-sftp-auth-radio"> <?php esc_html_e('Private key', 'content2html'); ?></label>
                            </td>
                        </tr>
                        <tr class="wpstatic-sftp-auth-password">
                            <th><label for="sftp_password"><?php esc_html_e('Password', 'content2html'); ?></label></th>
                            <td>
                                <input type="password" id="sftp_password" name="sftp_password" value="" class="regular-text" autocomplete="new-password">
                                <p class="description"><?php echo !empty($settings['sftp_password']) ? esc_html__('Already saved. Leave empty to keep it.', 'content2html') : ''; ?></p>
                            </td>
                        </tr>
                        <tr class="wpstatic-sftp-auth-key">
                            <th><label for="sftp_private_key"><?php esc_html_e('Private key (PEM)', 'content2html'); ?></label></th>
                            <td>
                                <textarea id="sftp_private_key" name="sftp_private_key" rows="6" class="large-text code" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"></textarea>
                                <p class="description"><?php echo !empty($settings['sftp_private_key']) ? esc_html__('Already saved. Leave empty to keep it.', 'content2html') : ''; ?></p>
                            </td>
                        </tr>
                        <tr class="wpstatic-sftp-auth-key">
                            <th><label for="sftp_passphrase"><?php esc_html_e('Passphrase (if any)', 'content2html'); ?></label></th>
                            <td><input type="password" id="sftp_passphrase" name="sftp_passphrase" value="" class="regular-text" autocomplete="new-password"></td>
                        </tr>
                        <tr>
                            <th><label for="sftp_remote_base_path"><?php esc_html_e('Target directory on the server', 'content2html'); ?></label></th>
                            <td><input type="text" id="sftp_remote_base_path" name="sftp_remote_base_path" value="<?php echo esc_attr($settings['sftp_remote_base_path']); ?>" class="regular-text" placeholder="/httpdocs/"></td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" class="button" id="wpstatic-test-sftp-btn"><?php esc_html_e('Test connection', 'content2html'); ?></button>
                        <span id="wpstatic-test-sftp-result" class="wpstatic-test-result"></span>
                    </p>
                </div>
                </div>

                <div id="wpstatic-save-button-wrap">
                <?php submit_button(__('Save settings', 'content2html')); ?>
                </div>
            </form>

            <hr>

            <div id="wpstatic-transfer-section">
            <h2 class="title"><?php esc_html_e('Deployment', 'content2html'); ?></h2>
            <p>
                <button type="button" class="button button-primary button-hero" id="wpstatic-deploy-all-btn">
                    <?php esc_html_e('Deploy all', 'content2html'); ?>
                </button>
                <button type="button" class="button" id="wpstatic-deploy-assets-only-btn" <?php echo $assetsStatus['exists'] ? '' : 'disabled'; ?>>
                    <?php esc_html_e('Deploy assets only', 'content2html'); ?>
                </button>
            </p>
            <p>
                <label>
                    <input type="checkbox" id="wpstatic-skip-assets" checked>
                    <?php esc_html_e('Upload assets when deploying', 'content2html'); ?>
                </label>
                <?php if ($settings['target'] === 'netlify'): ?>
                    <br><span class="description"><?php esc_html_e('On Netlify, assets are always uploaded (a deploy replaces the entire content) - this option has no effect here.', 'content2html'); ?></span>
                <?php endif; ?>
            </p>
            <div id="wpstatic-deploy-progress" style="display:none; max-width: 500px;">
                <progress id="wpstatic-deploy-progress-bar" value="0" max="100" style="width:100%;"></progress>
                <p id="wpstatic-deploy-progress-text"></p>
            </div>
            <div id="wpstatic-deploy-result"></div>
            </div>

            <hr id="wpstatic-transfer-hr">

            <div class="wpstatic-tab-panel" data-tab-panel="markdown">
            <h2 class="title"><?php esc_html_e('Markdown export', 'content2html'); ?></h2>
            <p class="description">
                <?php esc_html_e('Exports all posts/pages as plain', 'content2html'); ?> <code>.md</code> <?php esc_html_e('files with YAML front matter (title, date, slug) - meant for other systems (Hugo, Jekyll, Eleventy, Obsidian vault etc.),', 'content2html'); ?>
                <strong><?php esc_html_e('not', 'content2html'); ?></strong> <?php esc_html_e('as a deployable website. Runs independently of SFTP/Netlify; the result is a direct ZIP download.', 'content2html'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wpstatic_export_markdown">
                <?php wp_nonce_field('wpstatic_export_markdown'); ?>
                <p>
                    <label>
                        <input type="checkbox" name="markdown_localize_images" value="1" checked>
                        <?php esc_html_e('Download images and rewrite references as relative paths', 'content2html'); ?>
                    </label>
                    <br>
                    <span class="description">
                        <?php esc_html_e('Enabled: images are additionally included in the ZIP (under', 'content2html'); ?> <code>wp-content/uploads/...</code>), <?php esc_html_e('references become relative - the export then also works without a running WordPress instance. Disabled: image links stay unchanged and keep pointing to your WordPress site.', 'content2html'); ?>
                    </span>
                </p>
                <?php submit_button(__('Export as Markdown now', 'content2html'), 'secondary'); ?>
            </form>
            </div>
        </div>
        <?php
    }
}
