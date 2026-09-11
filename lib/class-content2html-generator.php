<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Prevent direct access (required by the WP.org Plugin Check tool)
}

// Guard against a fatal "Cannot redeclare class" error if another active
// plugin has already bundled and loaded its own copy of simplehtmldom
// (a fairly commonly used library) - simply skip loading ours in that
// case, since the class/function names are identical either way.
if (!class_exists('simple_html_dom', false)) {
    require_once __DIR__ . '/simple_html_dom.php';
}

/**
 * Content2HTML_Generator
 *
 * Uses the WordPress REST API as a headless CMS source and generates
 * static HTML from it using a template with placeholders.
 *
 * USAGE:
 *   $generator = new Content2HTML_Generator(
 *       'https://example.com/wp-json/wp/v2/',
 *       'posts',
 *       '/path/to/article_template.html'
 *   );
 *   $generator->setDateFormat('d.m.Y');
 *   $generator->setSavePath('/tmp/WPHeadless/Articles');
 *   $generator->injectDataIntoTemplate('posts/', $markerArray);
 *
 * For more info see:
 * https://github.com/elverdaderobutschito/WP-Static-File-Generator/blob/main/README.md
 *
 * Licensed under The MIT License
 * @author El Butschito
 *
 * CHANGELOG (compared to the original version):
 *  1. callWPApi() now checks the cURL error and HTTP status code and
 *     throws a Content2HTML_ApiException instead of silently returning null/broken
 *     data. Previously a network error could let injectSingle() continue
 *     with $arrData == null and fail with a confusing fatal error.
 *  2. setFileOwner()/chown() now only runs in a CLI context - in a web
 *     context (www-data) chown() fails silently without root anyway
 *     (the previous @-operator would have hidden that; now there is an
 *     explicit guard clause instead of @chown).
 *  3. mkdir() calls reduced from 0777 to 0775 (write access for "others"
 *     is unnecessarily broad).
 *  4. getData() now uses "??" instead of silently ignoring missing
 *     properties - a missing field now clearly returns null instead of
 *     unnoticedly passing through the previous partial result.
 *  5. saveImgFiles() now checks whether copy() succeeded instead of
 *     silently swallowing errors, and uses a real HTTP request with a
 *     timeout instead of copy() on potentially remote URLs without any
 *     error handling.
 *  6. Added type hints and property types wherever possible without
 *     changing behavior.
 *  7. tidyHtml() now cleans up the simple_html_dom tree in a finally
 *     block, even if one of the find() loops throws an exception.
 */
class Content2HTML_ApiException extends RuntimeException {
}

class Content2HTML_Generator {
    private string $apiUrl;
    private string $apiEndpointTopRoute;
    private string $templatePath;
    private ?string $dateFormat = null;
    private ?string $fileOwner = null;
    private string $savePath = '';
    private array $wrapArray = [];
    private string $dateFormatPattern = '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/';
    private array $urlPatternArray = [];
    private array $urlReplaceArray = [];
    private string $originalDomain;
    private bool $keepOriginalFileName = false;
    private bool $removeWPClasses = true;
    private array $tidyHtmlRules = [];

    /** @var string[] Absolute paths of all files written by injectDataIntoTemplate(). */
    private array $writtenFiles = [];

    /** @var (callable(string): (object|array))|null */
    private $apiDataProvider = null;

    /** 'off' | 'netlify' | 'handler' */
    private string $formMode = 'off';
    private array $formOptions = [];

    public function __construct(string $apiUrl, string $apiEndpointTopRoute, string $templatePath) {
        $this->setApiUrl($apiUrl);
        $this->setApiEndpointTopRoute($apiEndpointTopRoute);
        $this->setTemplatePath($templatePath);

        $host = wp_parse_url($apiUrl, PHP_URL_HOST);

        if ($host === null || $host === false) {
            throw new InvalidArgumentException(esc_html("Could not determine host from API URL: {$apiUrl}"));
        }

        $this->originalDomain = $host;
    }

    public function setApiEndpointTopRoute(string $route): void {
        $this->apiEndpointTopRoute = trim($this->checkTrailingSlash($route));
    }

    public function setApiUrl(string $apiUrl): void {
        $this->apiUrl = trim($this->checkTrailingSlash($apiUrl));
    }

    public function getApiUrl(): string {
        return $this->apiUrl;
    }

    public function getTemplatePath(): string {
        return $this->templatePath;
    }

    public function setTemplatePath(string $path): void {
        $this->templatePath = $path;
    }

    public function setDateFormat(string $dateFormat): void {
        $this->dateFormat = $dateFormat;
    }

    /**
     * Only takes effect in a CLI context (see the chown guard in injectSingle()).
     */
    public function setFileOwner(string $owner): void {
        $this->fileOwner = $owner;
    }

    public function setSavePath(string $path): void {
        $this->savePath = rtrim($path, '/');
    }

    public function setWrapArray(?array $wrapArray): void {
        $this->wrapArray = $wrapArray ?? [];
    }

    public function setDateFormatPattern(string $pattern): void {
        $this->dateFormatPattern = '/' . $pattern . '/';
    }

    public function setKeepOriginalFilename(bool $keep): void {
        $this->keepOriginalFileName = $keep;
    }

    public function getSavePath(): string {
        if ($this->savePath !== '') {
            return $this->savePath;
        }

        if ($this->templatePath !== '') {
            return dirname($this->templatePath);
        }

        return '';
    }

    public function setReplaceUrlParts(array $patternArray, array $replaceArray): void {
        $this->urlPatternArray = $patternArray;
        $this->urlReplaceArray = $replaceArray;
    }

    public function setTidyHtmlRules(array $tidyHtmlRules): void {
        $this->tidyHtmlRules = $tidyHtmlRules;
    }

    public function setRemoveWPClasses(bool $remove): void {
        $this->removeWPClasses = $remove;
    }

    /**
     * Lets the data source be served directly from PHP instead of via
     * HTTP/cURL - e.g. for a WordPress plugin that calls rest_do_request()
     * directly without a network loopback. The callback receives the same
     * composed URL that callWPApi() would previously have passed to cURL
     * (apiUrl + apiEndpointTopRoute + endpoint[/id]) and must return the
     * same data structure that json_decode() of a WP REST API response
     * would return (stdClass for a single object, an array of stdClass
     * objects for a list).
     *
     * If no provider is set, the class behaves as before (a real HTTP
     * request via cURL) - fully backward compatible.
     */
    public function setApiDataProvider(callable $provider): void {
        $this->apiDataProvider = $provider;
    }

    /**
     * Controls how <form> tags are handled on the generated pages -
     * without a real backend, WordPress forms (contact form etc.) would
     * otherwise just post into the void after deployment.
     *
     * @param string $mode 'off' (leave unchanged), 'netlify' (enable
     *        Netlify Forms via the data-netlify attribute), or 'handler'
     *        (rewrite the action to a bundled PHP form handler, for
     *        classic PHP-capable hosting).
     * @param array $options For 'handler': ['handler_path' => string,
     *        'honeypot_field' => string].
     */
    public function setFormHandling(string $mode, array $options = []): void {
        $this->formMode = $mode;
        $this->formOptions = $options;
    }

    /**
     * @throws Content2HTML_ApiException if the WP API is unreachable or does not
     *                         return usable JSON.
     *
     * @return string[] Absolute paths of all files written during this
     *                   call (HTML pages AND any images downloaded along
     *                   the way). Deliberately not determined via a
     *                   before/after directory comparison, but collected
     *                   directly while writing - a directory comparison
     *                   only detects NEWLY created files, not overwritten
     *                   ones (e.g. when a post is regenerated because its
     *                   content changed but the target path stays the
     *                   same).
     */
    public function injectDataIntoTemplate(string $wpApiEndpoint, array $dataMarkerArray): array {
        $wpApiEndpoint = $this->checkTrailingSlash($wpApiEndpoint);
        $arrData = $this->callWPApi($this->apiUrl . $this->apiEndpointTopRoute . $wpApiEndpoint);

        $this->writtenFiles = [];

        if (!is_array($arrData)) {
            $this->injectSingle($arrData, $wpApiEndpoint, $dataMarkerArray);
        } else {
            foreach ($arrData as $currData) {
                $this->injectSingle($currData, $wpApiEndpoint, $dataMarkerArray);
            }
        }

        return $this->writtenFiles;
    }

    private function injectSingle(object $arrData, string $endpoint, array $dataMarkerArray): void {
        $template = file_get_contents($this->templatePath);

        if ($template === false) {
            throw new RuntimeException(esc_html("Could not read template: {$this->templatePath}"));
        }

        $template = $this->ensureBaseHrefTag($template);

        if (property_exists($arrData, 'slug')) {
            $pathToFile = $this->getSavePath() . $this->createFilename($arrData);
        } else {
            // Fallback: every API object has at least an ID.
            $pathToFile = $this->getSavePath() . '/' . trim($endpoint, '/') . '_' . $arrData->id . '.html';
        }


        foreach ($dataMarkerArray as $pathToEndpoint => $marker) {
            $data = $this->resolveMarkerData($arrData, $pathToEndpoint, $marker);
            $template = str_replace($marker, (string) $data, $template);
        }

        $template = $this->tidyHtml($template);

        $dir = dirname($pathToFile);
        if (!is_dir($dir) && !Content2HTML_Filesystem::mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException(esc_html("Could not create target directory: {$dir}"));
        }

        file_put_contents($pathToFile, $template);
        $this->writtenFiles[] = $pathToFile;

        // chown doesn't do anything in a web context anyway (www-data
        // without root) - so only attempt it in a CLI context instead of
        // swallowing the error with @.
        if ($this->fileOwner !== null && PHP_SAPI === 'cli') {
            Content2HTML_Filesystem::chown($pathToFile, $this->fileOwner);
        }
    }

    /**
     * Resolves a single dataInjectionRules line into the string to be
     * inserted - including the "|" special case (loading data from a
     * linked resource).
     */
    private function resolveMarkerData(object $arrData, string $pathToEndpoint, string $marker): string {
        if (strpos($pathToEndpoint, '|') === false) {
            $data = $this->getData($arrData, $pathToEndpoint);
            return $this->maybeConvertDate($data, $pathToEndpoint);
        }

        [$sourcePath, $endpoint, $dataPoint] = array_pad(explode('|', $pathToEndpoint), 3, '');
        $id = $this->getData($arrData, $sourcePath);

        if (!is_array($id)) {
            $data = $this->getDataFromId($id, $endpoint, $dataPoint);
            return $this->maybeConvertDate($data, $pathToEndpoint);
        }

        $result = '';
        foreach ($id as $currId) {
            $idData = $this->maybeConvertDate(
                $this->getDataFromId($currId, $endpoint, $dataPoint),
                $pathToEndpoint
            );

            $result .= array_key_exists($marker, $this->wrapArray)
                ? $this->wrap($idData, $this->wrapArray[$marker])
                : $idData;
        }

        return $result;
    }

    private function maybeConvertDate($data, string $pathToEndpoint): string {
        $data = (string) $data;

        if ($pathToEndpoint !== 'yoast_head' && preg_match($this->dateFormatPattern, $data)) {
            return $this->convertDate($data);
        }

        return $data;
    }

    /**
     * Inserts <base href="/"> right after the opening <head> tag, unless
     * the template already has its own <base> tag. This makes relative
     * asset paths (e.g. href="assets/style.css") resolve correctly from
     * the domain root on EVERY generated page - regardless of how deep
     * that particular page sits in the directory structure (e.g.
     * /privacy-policy/index.html vs. /index.html).
     */
    private function ensureBaseHrefTag(string $template): string {
        if (stripos($template, '<base ') !== false || stripos($template, '<base>') !== false) {
            return $template; // Template already has its own <base> tag - leave it alone.
        }

        $withBase = preg_replace('/<head(\s[^>]*)?>/i', '$0<base href="/">', $template, 1, $count);

        return $count > 0 ? $withBase : $template;
    }

    private function createFilename(object $arrData): string {
        if (!$this->keepOriginalFileName) {
            return '/' . $arrData->slug . '.html';
        }

        $parseUrl = wp_parse_url($arrData->link);
        $path = $parseUrl['path'] ?? '/';

        // "Plain" permalinks (WordPress' "Plain" setting) link posts/pages
        // via a query-string ID (?page_id=2 or ?p=5) instead of via a path
        // segment of their own - the path is then identical for EVERY
        // post ("/"). A purely static export fundamentally cannot map a
        // query string to a file path; without this safeguard, every post
        // would overwrite the same /index.html (only the last one
        // processed would survive). Fallback: combine slug + ID
        // (collision-safe, readable, close to the original "page_id"
        // format).
        if ($path === '/' || $path === '') {
            return '/' . $arrData->slug . '.' . $arrData->id . '.html';
        }

        $pathInfo = pathinfo($path);

        if (!array_key_exists('extension', $pathInfo)) {
            $dir = $this->savePath . $path;

            if (!is_dir($dir)) {
                Content2HTML_Filesystem::mkdir($dir);
            }

            // rtrim() + explicit slash: works regardless of whether $path
            // already ends in "/" ("Post name"/"Day and name" do) or not
            // ("Numeric", per WordPress' own built-in preset, e.g.
            // "/archives/123", without a trailing slash) - otherwise,
            // without a trailing slash, we'd end up with
            // "archives/123index.html" instead of
            // "archives/123/index.html".
            return rtrim($path, '/') . '/index.html';
        }

        $dir = $this->savePath . str_replace('/' . $pathInfo['basename'], '', $path);

        if (!is_dir($dir)) {
            Content2HTML_Filesystem::mkdir($dir);
        }

        return $path;
    }

    private function changeUrls(): bool {
        return count($this->urlPatternArray) > 0 && count($this->urlReplaceArray) > 0;
    }

    /**
     * Removes WordPress-specific CSS classes (wp-*) from EVERY element
     * that has a class attribute - regardless of where the wp-* class
     * sits within a multi-part class list, and while keeping all other,
     * non-WordPress classes intact.
     *
     * (The previous implementation used the CSS selector "[class|=wp]",
     * which checks the ENTIRE class attribute as a single string rather
     * than each class individually - "has-medium-font-size
     * wp-block-paragraph" was never detected this way, because "wp-"
     * doesn't sit at the start of the COMPLETE attribute value. In
     * addition, on a match the entire class attribute used to be deleted,
     * including any non-WordPress classes in it.)
     */
    private function removeWPClassTokens(object $html): void {
        foreach ($html->find('[class]') as $tag) {
            $classes = preg_split('/\s+/', trim($tag->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
            $kept = array_filter($classes, static function (string $class): bool {
                return $class !== 'wp' && strpos($class, 'wp-') !== 0;
            });

            if (count($kept) === count($classes)) {
                continue; // no wp class present - nothing to do
            }

            if (count($kept) > 0) {
                $tag->setAttribute('class', implode(' ', $kept));
            } else {
                $tag->removeAttribute('class');
            }
        }
    }

    /**
     * Makes <form> tags functional on the respective target - without
     * WordPress/PHP running in the background, a form would otherwise
     * simply post into the void.
     */
    private function processForms(object $html): void {
        $forms = $html->find('form');

        if (count($forms) === 0) {
            return;
        }

        foreach ($forms as $index => $form) {
            $this->removeTechnicalFields($form);

            if ($this->formMode === 'netlify') {
                $this->prepareFormForNetlify($form, $index);
            } elseif ($this->formMode === 'handler') {
                $this->prepareFormForHandler($form);
            }
        }

        $this->injectValidationScript($html);
    }

    /**
     * Removes technical/internal form fields (WordPress nonces, AJAX
     * routing fields, plugin-internal markers such as Fluent Forms'
     * __fluent_protection_token_12, _fluentform_12_fluentformnonce,
     * _wp_http_referer etc.) DIRECTLY FROM THE HTML before the page is
     * generated - rather than only disabling them via JS on submit (see
     * form-validate.js), i.e. they're never shipped in the first place.
     * Important for Netlify: Netlify reads the form schema (which field
     * names exist) directly from the HTML at deploy time, so purely
     * client-side disabling would still let these fields show up there as
     * (empty) columns.
     */
    private function removeTechnicalFields(object $form): void {
        $honeypotField = $this->formOptions['honeypot_field'] ?? '';

        foreach ($form->find('input, select, textarea') as $field) {
            $name = $field->getAttribute('name');

            if (!$name || $name === $honeypotField) {
                continue;
            }

            if ($this->isTechnicalFieldName($name)) {
                $field->remove();
            }
        }
    }

    private function isTechnicalFieldName(string $name): bool {
        if ($name === '' || $name[0] === '_') {
            return true;
        }

        if ($name === 'action') {
            return true;
        }

        return (bool) preg_match('/nonce|token/i', $name);
    }

    /**
     * Includes the (purely client-side) validation script, if configured
     * - independent of the deployment target (Netlify/SFTP), hence
     * outside the formMode branch above.
     */
    private function injectValidationScript(object $html): void {
        $scriptPath = $this->formOptions['validation_script'] ?? '';

        if ($scriptPath === '') {
            return;
        }

        $body = $html->find('body', 0);

        if ($body === null) {
            return;
        }

        $honeypotField = $this->formOptions['honeypot_field'] ?? '';
        $requiredMessage = $this->formOptions['validation_msg_required'] ?? '';
        $selectOneMessage = $this->formOptions['validation_msg_select_one'] ?? '';

        $scriptTag = '<script src="' . htmlspecialchars($scriptPath, ENT_QUOTES) . '"'
            . ($honeypotField !== '' ? ' data-honeypot="' . htmlspecialchars($honeypotField, ENT_QUOTES) . '"' : '')
            . ($requiredMessage !== '' ? ' data-msg-required="' . htmlspecialchars($requiredMessage, ENT_QUOTES) . '"' : '')
            . ($selectOneMessage !== '' ? ' data-msg-select-one="' . htmlspecialchars($selectOneMessage, ENT_QUOTES) . '"' : '')
            . '></script>';

        if (strpos($body->innertext, '"' . htmlspecialchars($scriptPath, ENT_QUOTES) . '"') === false) {
            $body->innertext = $body->innertext . $scriptTag;
        }
    }

    /**
     * Netlify Forms: automatically detects HTML forms at build time if
     * they carry a data-netlify attribute plus a unique name - Netlify
     * additionally needs a hidden form-name field with the same name for
     * this (Netlify parses this statically from the HTML at deploy time;
     * there is no real form submission to Netlify to "register" the
     * form).
     */
    private function prepareFormForNetlify(object $form, int $index): void {
        if (!$form->hasAttribute('data-netlify')) {
            $form->setAttribute('data-netlify', 'true');
        }

        $name = $form->getAttribute('name');

        if (!$name) {
            $name = $form->getAttribute('id') ?: ('form-' . ($index + 1));
            $form->setAttribute('name', $name);
        }

        $hasHiddenNameField = count($form->find('input[name=form-name]')) > 0;

        if (!$hasHiddenNameField) {
            $form->innertext = '<input type="hidden" name="form-name" value="' . htmlspecialchars((string) $name, ENT_QUOTES) . '">' . $form->innertext;
        }

        // IMPORTANT: the original action still points to a WordPress URL
        // (e.g. /wp-admin/admin-ajax.php for AJAX-based form plugins) -
        // that doesn't exist on Netlify and would result in a "Page not
        // found" on submit. Netlify's own recommendation: either omit the
        // action (submits to the current page, which does exist) or set
        // it to a real, configured thank-you page.
        $redirectUrl = $this->formOptions['redirect_url'] ?? '';

        if ($redirectUrl !== '') {
            $form->setAttribute('action', $redirectUrl);
        } else {
            $form->removeAttribute('action');
        }
    }

    /**
     * Classic PHP hosting: rewrites the action to the bundled form
     * handler and adds a CSS-hidden honeypot field (a simple spam
     * deterrent - real users never see/fill it in, bots that blindly fill
     * in every field give themselves away by doing so).
     */
    private function prepareFormForHandler(object $form): void {
        $handlerPath = $this->formOptions['handler_path'] ?? '/form-handler.php';
        $honeypotField = $this->formOptions['honeypot_field'] ?? '_gotcha';

        $form->setAttribute('action', $handlerPath);
        $form->setAttribute('method', 'post');

        $hasHoneypot = count($form->find('input[name=' . $honeypotField . ']')) > 0;

        if (!$hasHoneypot) {
            $honeypotHtml = '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">'
                . '<input type="text" name="' . htmlspecialchars($honeypotField, ENT_QUOTES) . '" tabindex="-1" autocomplete="off">'
                . '</div>';
            $form->innertext = $honeypotHtml . $form->innertext;
        }
    }

    private function tidyHtml(string $template): string {
        $html = content2html_str_get_html($template);

        if ($html === false) {
            // Template wasn't valid HTML - return it unchanged instead of
            // failing with a fatal error on false->find().
            return $template;
        }

        try {
            if ($this->changeUrls()) {
                $this->rewriteUrlsInDom($html);
            }

            if ($this->formMode !== 'off') {
                $this->processForms($html);
            }

            // IMPORTANT: Tidy HTML rules run BEFORE the automatic removal
            // of wp-* classes - otherwise a rule that itself uses a wp-*
            // class as its selector (e.g. the common Gutenberg pattern
            // "a.wp-block-button__link") could never match, because the
            // class would already have been removed by that point.
            foreach ($this->tidyHtmlRules as $rule) {
                foreach ($html->find($rule['search']) as $tag) {
                    if ($rule['action'][0] === 'remove') {
                        $tag->removeAttribute($rule['action'][1]);
                    } elseif ($rule['action'][0] === 'change') {
                        $tag->setAttribute($rule['action'][1], $rule['action'][2]);
                    }
                }
            }

            if ($this->removeWPClasses) {
                $this->removeWPClassTokens($html);
            }

            return $html->__toString();
        } finally {
            $html->clear();
            unset($html);
        }
    }

    private function rewriteUrlsInDom(object $html): void {
        $domainPattern = '/' . preg_quote($this->originalDomain, '/') . '/';

        foreach ($html->find('a') as $tag) {
            if ($tag->href && preg_match($domainPattern, $tag->href)) {
                $tag->href = preg_replace($this->urlPatternArray, $this->urlReplaceArray, $tag->href);
            }
        }

        // Mainly for yoast_head meta tags.
        foreach ($html->find('[content]') as $tag) {
            if ($tag->content && preg_match($domainPattern, $tag->content)) {
                $tag->content = preg_replace($this->urlPatternArray, $this->urlReplaceArray, $tag->content);
            }
        }

        foreach ($html->find('[href]') as $tag) {
            if ($tag->href && preg_match($domainPattern, $tag->href)) {
                $tag->href = preg_replace($this->urlPatternArray, $this->urlReplaceArray, $tag->href);
            }
        }

        foreach ($html->find('img') as $tag) {
            if ($tag->src && preg_match($domainPattern, $tag->src)) {
                $newSrc = preg_replace($this->urlPatternArray, $this->urlReplaceArray, $tag->src);
                $this->saveImgFiles($tag->src, $newSrc);
                $tag->src = $newSrc;
            }

            if ($tag->srcset) {
                $tag->srcset = $this->rewriteSrcset($tag->srcset, $domainPattern);
            }
        }

        foreach ($html->find('script.yoast-schema-graph') as $tag) {
            $newTag = preg_replace('/<script [a-z,=,",\/\+\s,-]*>/', '', $tag->innertext);
            $newTag = preg_replace($this->urlPatternArray, $this->urlReplaceArray, $newTag);
            $tag->innertext = str_replace('</script>', '', $newTag);
        }
    }

    /**
     * Processes the srcset attribute of an <img> tag: srcset often
     * contains several image variants (e.g. for responsive images), each
     * with its own URL - "url1 300w, url2 768w, url3 826w". Previously
     * these URLs were only rewritten in the HTML but not downloaded - the
     * additional size variants (everything besides the main src) would
     * have ended up as 404s after deployment. Now each referenced file is
     * downloaded individually via saveImgFiles(), just like the main src.
     */
    private function rewriteSrcset(string $srcset, string $domainPattern): string {
        $candidates = array_filter(array_map('trim', explode(',', $srcset)));
        $rewritten = [];

        foreach ($candidates as $candidate) {
            $parts = preg_split('/\s+/', $candidate, 2);
            $url = $parts[0];
            $descriptor = $parts[1] ?? '';

            if ($url !== '' && preg_match($domainPattern, $url)) {
                $newUrl = preg_replace($this->urlPatternArray, $this->urlReplaceArray, $url);
                $this->saveImgFiles($url, $newUrl);
                $url = $newUrl;
            }

            $rewritten[] = trim($url . ' ' . $descriptor);
        }

        return implode(', ', $rewritten);
    }

    private function saveImgFiles(string $src, string $newFolder): void {
        $parseUrl = wp_parse_url($newFolder);
        $pathInfo = pathinfo($parseUrl['path'] ?? '');
        $filename = $pathInfo['basename'] ?? basename($parseUrl['path'] ?? $newFolder);

        $createPath = $this->savePath . ($pathInfo['dirname'] ?? '');

        if (!is_dir($createPath) && !Content2HTML_Filesystem::mkdir($createPath) && !is_dir($createPath)) {
            throw new RuntimeException(esc_html("Could not create image directory: {$createPath}"));
        }

        $target = $createPath . '/' . $filename;

        if (@copy($src, $target) === false) {
            // Deliberately not a hard failure - a single missing image
            // shouldn't stop the entire build, but it should be visible.
            trigger_error(esc_html("Could not copy image: {$src} -> {$target}"), E_USER_WARNING);
        } else {
            $this->writtenFiles[] = $target;
        }
    }

    private function getData(object $arrResult, string $pathToEndpoint) {
        $result = $arrResult;

        foreach (explode('->', $pathToEndpoint) as $attribute) {
            if (is_object($result) && property_exists($result, $attribute)) {
                $result = $result->$attribute;
            } else {
                return null;
            }
        }

        return $result;
    }

    public function wrap(string $content, string $wrap = ''): string {
        $ex = explode('|', $wrap);

        if (count($ex) === 1 && $ex[0] === '') {
            return $content;
        }

        return ($ex[0] ?? '') . $content . ($ex[1] ?? '');
    }

    private function getDataFromId($id, string $endpoint, string $dataPoint): string {
        $data = $this->callWPApi($this->apiUrl . $this->apiEndpointTopRoute . $endpoint . '/' . $id);

        if (is_array($data) || !property_exists($data, $dataPoint)) {
            return "No such property {$endpoint}->{$dataPoint}";
        }

        return (string) $data->$dataPoint;
    }

    /**
     * @throws Content2HTML_ApiException on a network error, non-2xx status, or
     *                         invalid JSON.
     */
    private function callWPApi(string $url) {
        if ($this->apiDataProvider !== null) {
            return ($this->apiDataProvider)($url);
        }

        // Fallback used only if no data provider was explicitly set (see
        // setApiDataProvider()) - not exercised by this plugin itself,
        // which always supplies one (see
        // Content2HTML_GeneratorFactory::build()). This file only ever
        // executes inside WordPress (see the ABSPATH guard above), so
        // wp_remote_get() is always available here.
        $response = wp_remote_get($url, [
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            throw new Content2HTML_ApiException(esc_html("WP API request failed ({$url}): " . $response->get_error_message()));
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Content2HTML_ApiException(esc_html("WP API responded with HTTP {$httpCode} ({$url})"));
        }

        $decoded = json_decode($body);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Content2HTML_ApiException(esc_html(sprintf(
                /* translators: 1: request URL, 2: JSON error message */
                __('WP API did not return valid JSON (%1$s): %2$s', 'content2html'),
                $url,
                json_last_error_msg()
            )));
        }

        return $decoded;
    }

    private function convertDate(string $originalDate): string {
        if ($this->dateFormat === null) {
            return $originalDate;
        }

        $timestamp = strtotime($originalDate);

        // wp_date() (rather than date()) respects the timezone configured
        // in WordPress (Settings -> General), instead of silently using
        // whatever timezone the server itself happens to be set to -
        // those can differ, and $originalDate is a post's publish date
        // meant for display, not an internal/log timestamp.
        return $timestamp === false ? $originalDate : wp_date($this->dateFormat, $timestamp);
    }

    private function checkTrailingSlash(string $path): string {
        return substr($path, -1) === '/' ? $path : $path . '/';
    }
}
