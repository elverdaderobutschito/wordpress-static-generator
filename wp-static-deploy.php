<?php
/**
 * Plugin Name: WP Static Deploy
 * Plugin URI: https://wordpress-static-generator.com
 * Description: Turns your WordPress content into a static HTML site and deploys it to Netlify or any SFTP host - forms, navigation, and Markdown export included.
 * Version: 1.0.0
 * Requires at least: 7.1
 * Requires PHP: 7.4
 * Author: Butsch
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-static-deploy
 * Domain Path: /languages
 *
 * INSTALLATION / DEPENDENCIES:
 *  1. lib/simple_html_dom.php is already included (identical version to
 *     the generateStatic.php setup, for consistent behavior).
 *  2. For SFTP: phpseclib is already included with its own autoloader
 *     (see vendor/), no composer install needed.
 *  3. Recommended: define WPSTATIC_ENCRYPTION_KEY in wp-config.php (see
 *     README.md) before entering SFTP/Netlify credentials.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPSTATIC_DEPLOY_VERSION', '1.0.0');
define('WPSTATIC_DEPLOY_DIR', plugin_dir_path(__FILE__));
define('WPSTATIC_DEPLOY_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
    load_plugin_textdomain('wp-static-deploy', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// -----------------------------------------------------------------------
// Guard checks FIRST, before anything is loaded that transitively depends
// on them. WPHeadlessStaticGenerator.php unconditionally requires
// simple_html_dom.php - if this check ran later (e.g. on plugins_loaded),
// the file would already have failed fatally before the check ever got a
// chance to run.
// -----------------------------------------------------------------------

if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>' . esc_html__('WP Static Deploy requires PHP 7.4 or newer.', 'wp-static-deploy') . '</p></div>';
    });
    return;
}

if (!file_exists(WPSTATIC_DEPLOY_DIR . 'lib/simple_html_dom.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
            /* translators: %s: file path */
            . sprintf(esc_html__('WP Static Deploy: %s is missing. See lib/README-simple-html-dom.txt.', 'wp-static-deploy'), '<code>lib/simple_html_dom.php</code>')
            . '</p></div>';
    });
    return;
}

// Composer autoloader (phpseclib etc.), if present. If missing, only the
// SFTP functionality is limited - the rest of the plugin (generation,
// Netlify) still works.
if (file_exists(WPSTATIC_DEPLOY_DIR . 'vendor/autoload.php')) {
    require_once WPSTATIC_DEPLOY_DIR . 'vendor/autoload.php';
}

require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-crypto.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-settings.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-generator-factory.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-assets-manager.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-forms.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-markdown-export.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-navigation.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/interface-wpstatic-uploader.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-sftp-uploader.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-netlify-uploader.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-batch-controller.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-ajax.php';
require_once WPSTATIC_DEPLOY_DIR . 'includes/class-wpstatic-metabox.php';

add_action('plugins_loaded', function () {
    new WPStatic_Settings();
    new WPStatic_AjaxController();
    new WPStatic_MetaBox();
});
