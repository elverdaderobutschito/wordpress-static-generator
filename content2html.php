<?php
/**
 * Plugin Name: Content2HTML
 * Plugin URI: https://ub-internetberatung.de
 * Description: Use WordPress as a headless CMS without building a WordPress theme. Upload your HTML template, define your own injection points, and publish the result as a static website.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: elbutschito
 * Author URI: https://profiles.wordpress.org/elbutschito/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: content2html
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
    load_plugin_textdomain('content2html', false, dirname(plugin_basename(__FILE__)) . '/languages');
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
        echo '<div class="notice notice-error"><p>' . esc_html__('Content2HTML requires PHP 7.4 or newer.', 'content2html') . '</p></div>';
    });
    return;
}

if (!file_exists(WPSTATIC_DEPLOY_DIR . 'lib/simple_html_dom.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
            /* translators: %s: file path */
            . sprintf(esc_html__('Content2HTML: %s is missing. See lib/README-simple-html-dom.txt.', 'content2html'), '<code>lib/simple_html_dom.php</code>')
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
