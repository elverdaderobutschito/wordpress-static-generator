<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once WPSTATIC_DEPLOY_DIR . 'lib/WPHeadlessStaticGenerator.php';

/**
 * Builds a WPHeadlessStaticGenerator instance that does NOT fetch its
 * data via a real HTTP request, but directly through WordPress' internal
 * REST dispatch function rest_do_request(). This returns exactly the
 * same data structure as a real /wp-json/ call (including Yoast fields
 * etc., if the respective plugins enrich the REST response accordingly),
 * but without a network loopback - faster, and it also works on hosting
 * environments that block self-referencing HTTP requests.
 */
class WPStatic_GeneratorFactory {
    /**
     * @param string|null $templateOverridePath Optional path to a
     *        per-page template (see WPStatic_Settings::resolveTemplatePath()).
     *        If null or invalid, the default template is used.
     *
     * @throws RuntimeException if no valid template file can be
     *                          determined.
     */
    public static function build(string $savePath, ?string $templateOverridePath = null): WPHeadlessStaticGenerator {
        $settings = WPStatic_Settings::getSettings();

        $templatePath = $templateOverridePath ?? $settings['template_path'];

        if (empty($templatePath) || !is_file($templatePath)) {
            throw new RuntimeException(__('No valid template file configured.', 'wordpress-static-generator'));
        }

        // apiUrl is only used as a prefix to work the REST route back out
        // again in the provider callback - no real HTTP call is made to
        // it.
        $apiUrl = rest_url(); // e.g. https://example.com/wp-json/

        $generator = new WPHeadlessStaticGenerator($apiUrl, 'wp/v2', $templatePath);
        $generator->setSavePath($savePath);
        $generator->setKeepOriginalFilename(true); // preserve the directory structure (see point C)

        if (!empty($settings['date_format'])) {
            $generator->setDateFormat($settings['date_format']);
        }

        // Map the checkbox state directly, 1:1, onto the behavior:
        // checked ("Remove WP CSS classes") = remove, unchecked = leave
        // untouched. This used to be inverted (see CHANGELOG) - a
        // leftover from the original generateStatic.php logic that was
        // never questioned during the refactor.
        $generator->setRemoveWPClasses($settings['remove_wp_tags'] === 'on');

        $generator->setTidyHtmlRules(self::parseTidyHtmlRules($settings['tidy_html_rules']));

        [$autoPatterns, $autoReplacements] = self::buildAutoDomainRule();
        [$patterns, $replacements] = self::parseChangeUrlRules($settings['change_url_rules']);

        $generator->setReplaceUrlParts(
            array_merge($autoPatterns, $patterns),
            array_merge($autoReplacements, $replacements)
        );

        $generator->setApiDataProvider([self::class, 'provideData']);

        WPStatic_Forms::applyToGenerator($generator, $settings);

        return $generator;
    }

    /**
     * Automatically builds a change-URL rule that shortens all URLs
     * pointing to the site's own WordPress domain (images, downloads
     * etc.) to a root-relative path - e.g.
     * https://example.com/wp-content/uploads/foo.jpg becomes
     * /wp-content/uploads/foo.jpg. Works thanks to the automatically
     * inserted <base href="/"> on every generated page, regardless of
     * its directory depth.
     *
     * Without this rule, image URLs would keep pointing unchanged at the
     * WordPress source instance instead of the target (SFTP
     * server/Netlify), where the images are actually uploaded to as well
     * (see saveImgFiles() in WPHeadlessStaticGenerator.php).
     *
     * @return array{0: string[], 1: string[]} [$patterns, $replacements]
     */
    private static function buildAutoDomainRule(): array {
        $home = home_url();
        $host = parse_url($home, PHP_URL_HOST);
        $port = parse_url($home, PHP_URL_PORT);

        if ($host === null || $host === false) {
            return [[], []];
        }

        $hostPort = $host . ($port ? ':' . $port : '');
        $pattern = '/https?:\/\/' . preg_quote($hostPort, '/') . '/';

        return [[$pattern], ['']];
    }

    public static function getDataInjectionRules(): array {
        $settings = WPStatic_Settings::getSettings();
        return self::parseKeyValueRules($settings['data_injection_rules']);
    }

    /**
     * Callback for WPHeadlessStaticGenerator::setApiDataProvider().
     * Receives the same composed "URL" that the cURL path would build
     * (rest_url() + 'wp/v2/' + endpoint[/id]) and translates it back into
     * a REST route for rest_do_request().
     */
    public static function provideData(string $url) {
        $restPrefix = rest_url(); // e.g. https://example.com/wp-json/
        $route = '/' . ltrim(str_replace($restPrefix, '', $url), '/');
        $route = rtrim($route, '/');

        $request = new WP_REST_Request('GET', $route);
        $request->set_param('context', 'view');

        $response = rest_do_request($request);

        if ($response->is_error()) {
            $error = $response->as_error();
            throw new WPApiException(sprintf(
                /* translators: 1: REST route, 2: error message */
                __('WP REST route returned an error (%1$s): %2$s', 'wordpress-static-generator'),
                $route,
                $error->get_error_message()
            ));
        }

        $server = rest_get_server();
        $data = $server->response_to_data($response, false);

        // Convert via json_encode/json_decode into the same object
        // structure that WPHeadlessStaticGenerator would previously have
        // gotten from json_decode() of a real HTTP response (stdClass for
        // a single object, an array of stdClass for a list).
        return json_decode((string) wp_json_encode($data));
    }

    private static function parseKeyValueRules(string $raw): array {
        $result = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = explode('=>', $line);

            if (count($parts) === 2) {
                $result[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $result;
    }

    private static function parseChangeUrlRules(string $raw): array {
        $patterns = [];
        $replacements = [];

        foreach (explode("\n", $raw) as $line) {
            $line = preg_replace('/\s+/', '', $line);

            if ($line === '' || $line === null) {
                continue;
            }

            $parts = explode('=>', $line);

            if (count($parts) !== 2) {
                continue;
            }

            $pattern = trim($parts[0]);

            if (!preg_match('/\/.*\//', $pattern) || @preg_match($pattern, '') === false) {
                continue;
            }

            $patterns[] = $pattern;
            $replacements[] = trim($parts[1]);
        }

        return [$patterns, $replacements];
    }

    private static function parseTidyHtmlRules(string $raw): array {
        $rules = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $split = explode('|', $line, 2);

            if (count($split) !== 2) {
                continue;
            }

            $action = array_map('trim', explode(',', $split[1]));

            if (!in_array($action[0], ['remove', 'change'], true)) {
                continue;
            }

            $rules[] = ['search' => $split[0], 'action' => $action];
        }

        return $rules;
    }
}
