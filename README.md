# WordPress Static Generator

Generates static HTML files from WordPress content (posts/pages) using
`WPHeadlessStaticGenerator.php` - directly, internally, via
`rest_do_request()`, without an HTTP loopback - and uploads them via
**SFTP** or **Netlify** to a configurable target, preserving the
directory structure (permalink paths) defined in WordPress.

## Installation

1. **`lib/simple_html_dom.php` is already included** - the same version
   used in the `generateStatic.php` setup (classic single-file API:
   `class simple_html_dom`, `str_get_html()`), for consistent behavior
   between the demo and the plugin (see: https://github.com/elverdaderobutschito/WP-Static-File-Generator).

2. **phpseclib is already included** (version 3.0.43, sourced directly
   from [github.com/phpseclib/phpseclib](https://github.com/phpseclib/phpseclib),
   MIT license, including the required dependency
   `paragonie/constant_time_encoding`). A slim, custom PSR-4 autoloader
   lives at `vendor/autoload.php` - **no** `composer install` needed. If
   you later extend the plugin with more Composer packages, replace
   `vendor/` with a real `composer install` run (the autoloader will
   then be overwritten automatically).

3. **Add `WPSTATIC_ENCRYPTION_KEY` to `wp-config.php`** (recommended,
   before entering credentials):
   ```php
   define('WPSTATIC_ENCRYPTION_KEY', 'a-long-random-value');
   ```
   Generate one e.g. via `php -r "echo bin2hex(random_bytes(32));"`.
   Without this constant, the plugin still works (with an automatically
   generated key stored in the database), but that's weaker.

4. Upload the plugin folder to `wp-content/plugins/wordpress-static-generator/` and
   activate it in WordPress.

5. Configure under **Static Deploy** (its own menu item):
   - Post types (posts/pages)
   - Upload a template file
   - Rules (data injection, change URL, tidy HTML) - same format as in
     `generateStatic.php`
   - Target: SFTP or Netlify, including credentials

## Usage

- **"Deploy all"** on the settings page: generates all configured post
  types and uploads them in one go (with a progress indicator, runs in
  batches via AJAX to avoid PHP timeouts).
- **"Deploy" button** in the meta box on every post/page edit screen:
  deploys just that one page.
  - On **SFTP**: a real single-file transfer (including any newly
    generated image assets for that page).
  - On **Netlify**: since a Netlify deploy always replaces the entire
    site content, this button triggers a full rebuild + redeploy of all
    pages instead (with a corresponding note in the backend).

## Per-page templates

Besides the default template, additional named templates (e.g. "Landing
page", "Contact") can be uploaded under Static Deploy → "Additional
templates". When editing a post/page, a dropdown then appears in the
"Static Deploy" meta box, letting you choose a different template than
the default for just that one page. The selection is applied when the
page is saved normally (not only when clicking "Deploy").

## Navigation from WordPress menus

Takes navigation HTML that the designer has already styled in the
template (including CSS classes, buttons etc.) and automatically fills
it with the real menu items of a WordPress menu - marker-based, no
design changes needed.

**How the designer marks up their template** (marker names are freely
chosen, entered in the plugin settings):

```html
<!-- ###MAINMENU### Start -->
<ul class="navbar-nav">
  <!-- ###ITEM### Start -->
  <li class="nav-item"><a class="nav-link" href="#">Demo item</a></li>
  <!-- ###ITEM### End -->
</ul>
<!-- ###MAINMENU### End -->
```

For multi-level menus, additionally a parent item template (with a
nested submenu wrapper + submenu item) - see the example in the plugin
settings under "Navigation". These three additional markers apply
recursively at every nesting depth.

If the item marker pair doesn't appear in the template at all (e.g. a
footer navigation where every column has sub-items and there isn't a
single "flat" menu item), the parent marker is automatically used as the
insertion point instead. If a single top-level menu item in WordPress
happens to have no children, the "shell" of the parent template
(title/link without the sub-item list) is used as a fallback instead of
letting the menu item disappear.

A detailed step-by-step explanation with an example sits directly on the
settings page above the navigation fields.

Can be enabled separately per navigation (main/footer) - if the toggle
is off, the template is left untouched at that spot. The menu item for
the page currently being generated is automatically marked with a
configurable CSS class (default: `active`).

## Markdown export

Independent of SFTP/Netlify: under Static Deploy → "Markdown export",
one click downloads a ZIP containing all posts/pages as plain `.md`
files with YAML front matter (title, date, slug, status, excerpt) -
meant for other systems (Hugo, Jekyll, Eleventy, Obsidian vault,
documentation tools), **not** as a replacement for the deployable
website generated via the template. The HTML-to-Markdown conversion runs
via `league/html-to-markdown` (MIT license, bundled with the plugin, no
further dependencies besides PHP's standard `dom` and `xml`
extensions).

## Forms

Without WordPress running in the background, `<form>` tags on the
generated pages would otherwise just post into the void. Can be enabled
under Static Deploy → "Forms":

- **Netlify**: forms automatically get `data-netlify="true"` and a
  hidden `form-name` field - Netlify Forms then detects and processes
  them on its own (viewable separately in Netlify under Site → Forms).
  No server code of your own needed.
- **SFTP**: the form's `action` is automatically rewritten to a bundled
  PHP handler (`form-handler.php`, freshly generated and uploaded on
  every "Deploy all" run). The handler emails the form data and then
  redirects. Requirement: **the target hosting must be able to run
  PHP** (this doesn't work on pure static hosting without PHP). A
  simple, CSS-hidden honeypot field deters basic spam bots.

Recipient address, sender, subject prefix, thank-you page, and honeypot
field name are configurable on the settings page.

**Custom form target (SFTP only)**: if the target server doesn't run PHP
(or you want to use your own/an external endpoint), a custom URL can be
set under "Custom form target" - the forms' `action` then points there,
and our `form-handler.php` isn't generated/uploaded at all in that case.

**Client-side validation** (Netlify + SFTP, automatically active as soon
as forms are enabled): a lightweight JS file
(`wpstatic-form-validate.js`) checks before submitting whether fields
with `aria-required="true"` are filled in (for checkbox/radio groups: at
least one option) and whether `type="email"` fields look like a valid
address - using the browser's native validation UI (`reportValidity()`)
for this, no custom error styling needed. `aria-required` is already set
automatically by most accessible form plugins (Fluent Forms, WPForms,
Gravity Forms) - no configuration needed. If a form plugin doesn't set
this attribute, the check simply doesn't apply (data is then submitted
unvalidated, as before).

Directly at generation time (not only on submit in the browser), the
generator additionally removes technical/internal fields completely from
the HTML (WordPress nonces, AJAX routing fields, form-plugin-internal
markers such as `_wp_http_referer`, `__fluent_protection_token_*` etc. -
detected by a leading underscore or "nonce"/"token" in the field name).
Important for Netlify: Netlify reads the form schema (which field names
exist) directly from the HTML at deploy time - purely client-side
disabling would still let these fields show up there as (empty) columns.
The honeypot field itself is exempt from this and is kept.
`wpstatic-form-validate.js` additionally disables the same fields on
submit as a safety net, in case one slips through anyway.

## Assets (CSS/JS/fonts/images)

The template typically references resources like
`assets/bootstrap/css/bootstrap.min.css`. These need to be provided in
addition to the template:

1. Zip your local `assets/` folder - **zip the folder itself, not just
   its contents** (the ZIP root must be a folder named `assets/`).
2. Upload it under Static Deploy → Assets.
3. The plugin automatically copies the assets into the build folder on
   every generation run. Whether they're actually **uploaded** to the
   target too is controlled by the "Upload assets when deploying"
   checkbox next to the "Deploy all" button:
   - **SFTP**: can be unchecked to avoid repeated uploads when the
     assets haven't changed - except the very first time for a new
     target (first-upload protection, the checkbox is then forced on).
   - **Netlify**: assets are always uploaded, since a Netlify deploy
     fundamentally replaces the entire site content - the checkbox has
     no effect there.
4. **"Deploy assets only"** button: updates only the assets on the
   target, without regenerating the WordPress content - handy after a
   pure CSS/JS change. On Netlify this still technically runs as a full
   redeploy (see above).

Relative asset paths work on every page regardless of its directory
depth (e.g. `/privacy-policy/index.html` vs. `/index.html`), because the
plugin automatically inserts a `<base href="/">` into `<head>`, unless
the template already has one of its own.

## Security notes

- The SFTP password/key and Netlify token are stored **encrypted** (not
  in plain text) in `wp_options`, see
  `includes/class-wpstatic-crypto.php`.
- Only users with `manage_options` (administrators) can change the
  settings and trigger "Deploy all". The single-page button additionally
  checks `edit_post` for the respective post.
- The local build directory lives at
  `wp-content/uploads/wpstatic-build/` and is emptied and refilled on
  every "Deploy all" run.

## License notes for bundled libraries

- `vendor/phpseclib/phpseclib` - MIT license, see the `LICENSE` file
  inside it.
- `vendor/paragonie/constant_time_encoding` - MIT license, see the
  `LICENSE.txt` file inside it.
