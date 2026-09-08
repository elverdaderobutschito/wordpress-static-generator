=== WP Static Deploy ===
Contributors: elbutschito
Tags: static site, static export, netlify, sftp, headless cms
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns your WordPress content into a static HTML site and deploys it to Netlify or any SFTP host - forms, navigation, and Markdown export included.

== Description ==

WP Static Deploy generates a fully static HTML export of your WordPress posts and pages, using WordPress purely as a headless content source, and pushes the result to a deployment target of your choice: Netlify or any standard SFTP host.

**Core features**

* **Template-driven generation** - use your own HTML template with simple `###placeholder###` markers (title, content, date, author, and any custom field via a flexible field-mapping syntax). Assign a different template per post or page when needed.
* **Two deployment targets** - upload over SFTP (password or private key authentication) to any web host, or deploy straight to Netlify via its API. Batch transfer with progress feedback, or push a single page at a time from the post editor.
* **Automatic URL rewriting** - internal links and media URLs are automatically rewritten to root-relative paths, with additional custom find-and-replace rules available for anything else (old domains, CDN paths, and so on).
* **Working contact forms on a static site** - forms keep working after export. On Netlify, forms are automatically wired up for Netlify Forms. On SFTP targets, a small bundled PHP handler emails submissions, with spam-honeypot protection and lightweight client-side validation (required fields, email format) that needs no configuration for accessibility-friendly form markup.
* **Navigation from a WordPress menu** - keep your designer's existing, fully styled navigation markup and let the plugin fill it with the real menu structure from a WordPress menu (multi-level menus supported), instead of maintaining two separate navigations by hand.
* **Markdown export** - export all content as plain `.md` files with YAML front matter, optionally bundling and localizing referenced images, for use with static-site generators such as Hugo, Jekyll, or Eleventy, or for archiving in a note-taking tool.
* **HTML cleanup rules** - remove WordPress-only CSS classes, strip or change arbitrary attributes via simple selector-based rules, and inject custom per-post data into the template.

WP Static Deploy is aimed at agencies and developers who want the editorial convenience of WordPress combined with the speed, security, and low hosting cost of a static site.

= Bundled libraries =

This plugin bundles the following third-party, MIT-licensed libraries so that no separate `composer install` step is required:

* [phpseclib](https://github.com/phpseclib/phpseclib) (3.x) - SFTP support
* [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding) - runtime dependency of phpseclib
* [league/html-to-markdown](https://github.com/thephpleague/html-to-markdown) - Markdown export
* [simplehtmldom](https://sourceforge.net/projects/simplehtmldom/) - HTML parsing/manipulation used throughout the generator

All are MIT-licensed and compatible with this plugin's GPLv2-or-later license. See the LICENSE.txt file for the full plugin license text and the `vendor/` subfolders for each library's own license.

= External services =

This plugin only contacts external services that you explicitly configure and trigger yourself:

* If you configure a Netlify site and click "Deploy", the plugin sends your generated site files to the [Netlify API](https://docs.netlify.com/) using the access token you provide.
* If you configure an SFTP target, the plugin connects to the host you specify to upload files.

No data is sent anywhere without an explicit action from you, and no usage tracking or analytics of any kind is built into this plugin.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wp-static-deploy` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to "Static Deploy" in the admin menu to configure a template, choose a deployment target (SFTP or Netlify), and set up any optional features (forms, navigation, Markdown export).
4. Optional but recommended: define `WPSTATIC_ENCRYPTION_KEY` in `wp-config.php` before entering SFTP/Netlify credentials, so stored secrets are encrypted with a key that isn't stored in the database itself.

== Frequently Asked Questions ==

= Does this replace WordPress on my live site? =

No. WordPress keeps running as your editorial backend. Visitors are served the generated static files instead, from Netlify or your SFTP host.

= Does my hosting need to run PHP for the static site to work? =

Only if you use SFTP as a target and want the built-in contact-form handler to work, since that handler is a small PHP script. Everything else (the static pages themselves) is plain HTML and works on any static host. Netlify deployments do not require PHP at all, including for forms.

= What happens to my forms after export? =

See the "Working contact forms on a static site" feature above - forms are automatically rewired to either Netlify Forms or a bundled PHP mail handler, depending on your chosen target.

= Can I keep my own hand-built navigation instead of generating it from a WordPress menu? =

Yes. The navigation feature is opt-in per navigation (main/footer) and does nothing unless explicitly enabled.

== Screenshots ==

1. Tabbed settings screen (Content, Navigation, Forms, Deployment target, Markdown export).
2. Deployment progress with live status.

== Changelog ==

= 1.0.0 =
* Initial public release: template-based static generation, SFTP and Netlify deployment, automatic and custom URL rewriting, per-post templates, working contact forms (Netlify Forms / PHP mail handler), WordPress-menu-driven navigation, Markdown export, HTML cleanup rules.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
