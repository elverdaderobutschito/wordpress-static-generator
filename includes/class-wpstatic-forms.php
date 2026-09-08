<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes WordPress forms (Contact Form 7, Gravity Forms, native Gutenberg
 * form blocks etc.) functional on the generated static page - without
 * WordPress/PHP running in the background, they would otherwise just
 * post into the void.
 *
 * Two strategies, depending on the deployment target:
 *  - Netlify: HTML forms automatically get data-netlify="true" plus a
 *    hidden form-name field (Netlify Forms then detects them
 *    automatically at deploy time, no server code needed).
 *  - SFTP (classic PHP hosting): the form's action is rewritten to a
 *    bundled PHP handler that emails the submitted data.
 */
class WPStatic_Forms {
    private const HANDLER_TEMPLATE_PATH = WPSTATIC_DEPLOY_DIR . 'includes/form-handler-template.php';
    private const HANDLER_FILENAME = 'form-handler.php';
    private const VALIDATION_SCRIPT_SOURCE = WPSTATIC_DEPLOY_DIR . 'includes/form-validate.js';
    private const VALIDATION_SCRIPT_FILENAME = 'wpstatic-form-validate.js';

    /**
     * Configures the generator to match the current target and form
     * settings. No-op if forms are not enabled.
     */
    public static function applyToGenerator(WPHeadlessStaticGenerator $generator, array $settings): void {
        if (empty($settings['forms_enabled'])) {
            return;
        }

        // Client-side required-field/email validation runs along
        // regardless of the target - purely in the browser, no server
        // overhead. The messages are translated here (server-side, where
        // WordPress' i18n is available) and passed to the static JS via
        // data attributes, so the generated site shows validation
        // messages in whatever language this WordPress install is
        // configured for - not hard-coded to one language regardless of
        // the site's actual audience.
        $validationScriptPath = '/' . self::VALIDATION_SCRIPT_FILENAME;
        $validationMessages = [
            'validation_msg_required' => __('This field is required.', 'wordpress-static-generator'),
            'validation_msg_select_one' => __('Please select at least one option.', 'wordpress-static-generator'),
        ];

        if ($settings['target'] === 'netlify') {
            $generator->setFormHandling('netlify', array_merge([
                'redirect_url' => self::safeguardNetlifyRedirect($settings['form_redirect_url']),
                'validation_script' => $validationScriptPath,
                'honeypot_field' => $settings['form_honeypot_field'] !== '' ? $settings['form_honeypot_field'] : '_gotcha',
            ], $validationMessages));
            return;
        }

        $customAction = trim($settings['form_custom_action']);

        $generator->setFormHandling('handler', array_merge([
            'handler_path' => $customAction !== '' ? $customAction : '/' . self::HANDLER_FILENAME,
            'honeypot_field' => $settings['form_honeypot_field'] !== '' ? $settings['form_honeypot_field'] : '_gotcha',
            'redirect_url' => $settings['form_redirect_url'],
            'validation_script' => $validationScriptPath,
        ], $validationMessages));
    }

    /**
     * Prevents a "thank you" page accidentally pointing to the site's own
     * WordPress domain from being shipped on Netlify - that would make
     * the form's action point to WordPress instead of the Netlify site,
     * which would make the request completely bypass Netlify's form
     * capture (the form appears to "work", but never shows up in
     * Netlify's Forms overview). Such a case is already flagged with a
     * warning when saving the settings (see
     * WPStatic_Settings::handleSave()), and additionally guarded against
     * here at runtime, in case the faulty setting was saved anyway.
     */
    private static function safeguardNetlifyRedirect(string $redirectUrl): string {
        if ($redirectUrl === '') {
            return '';
        }

        $redirectHost = parse_url($redirectUrl, PHP_URL_HOST);
        $ownHost = parse_url(home_url(), PHP_URL_HOST);

        if ($redirectHost !== null && $redirectHost === $ownHost) {
            return ''; // ignore it -> the generator removes the action, the form submits to itself
        }

        return $redirectUrl;
    }

    /**
     * Generates the configured PHP form handler in the build directory -
     * only relevant for SFTP targets (Netlify doesn't need any server
     * code of its own, see applyToGenerator()), and only if no custom
     * form target is configured (otherwise our handler would go unused
     * anyway and would just get uploaded needlessly).
     */
    public static function buildHandlerFile(string $buildDir, array $settings): void {
        if (empty($settings['forms_enabled']) || $settings['target'] === 'netlify') {
            return;
        }

        if (trim($settings['form_custom_action']) !== '') {
            return; // a custom target is configured - our handler isn't needed
        }

        $template = file_get_contents(self::HANDLER_TEMPLATE_PATH);

        if ($template === false) {
            return;
        }

        $replacements = [
            '{{RECIPIENT}}' => self::escapeForSingleQuotedPhpString($settings['form_recipient_email']),
            '{{FROM_EMAIL}}' => self::escapeForSingleQuotedPhpString($settings['form_from_email']),
            '{{SUBJECT_PREFIX}}' => self::escapeForSingleQuotedPhpString($settings['form_subject_prefix']),
            '{{REDIRECT_URL}}' => self::escapeForSingleQuotedPhpString($settings['form_redirect_url']),
            '{{HONEYPOT_FIELD}}' => self::escapeForSingleQuotedPhpString(
                $settings['form_honeypot_field'] !== '' ? $settings['form_honeypot_field'] : '_gotcha'
            ),
        ];

        $content = strtr($template, $replacements);

        file_put_contents(rtrim($buildDir, '/') . '/' . self::HANDLER_FILENAME, $content);

        self::ensureLogProtection($buildDir);
    }

    /**
     * Copies the static validation script into the build directory -
     * relevant for BOTH targets (Netlify and SFTP), since it's purely
     * client-side.
     */
    public static function buildValidationScript(string $buildDir, array $settings): void {
        if (empty($settings['forms_enabled'])) {
            return;
        }

        $source = self::VALIDATION_SCRIPT_SOURCE;

        if (!is_file($source)) {
            return;
        }

        copy($source, rtrim($buildDir, '/') . '/' . self::VALIDATION_SCRIPT_FILENAME);
    }

    /**
     * Protects form-handler.log (contains recipient addresses/subject
     * lines) from direct web access. Appends the rule to an existing
     * .htaccess in the build root instead of overwriting it, if one
     * already exists.
     */
    private static function ensureLogProtection(string $buildDir): void {
        $rule = "\n<Files \"form-handler.log\">\n    Require all denied\n</Files>\n";
        $htaccessPath = rtrim($buildDir, '/') . '/.htaccess';

        $existing = is_file($htaccessPath) ? (string) file_get_contents($htaccessPath) : '';

        if (strpos($existing, 'form-handler.log') !== false) {
            return; // rule already present
        }

        file_put_contents($htaccessPath, $existing . $rule);
    }

    /**
     * Prevents values from the settings (e.g. an email address containing
     * an apostrophe) from breaking out of the surrounding PHP string
     * literal in the generated handler.
     */
    private static function escapeForSingleQuotedPhpString(string $value): string {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    }
}
