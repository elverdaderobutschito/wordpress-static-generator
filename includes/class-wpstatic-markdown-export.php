<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exports WordPress content as plain .md files (with YAML front matter)
 * - meant for other systems (Hugo, Jekyll, Eleventy, Obsidian vaults,
 * documentation tools etc.), NOT as a replacement for the deployable
 * website generated via the template. Runs completely independently of
 * SFTP/Netlify because of this - the result is a ZIP to download, not a
 * deploy target.
 */
class WPStatic_MarkdownExport {
    /**
     * @return string Absolute path to the generated ZIP file.
     */
    public static function buildZip(array $settings, bool $localizeImages = true): string {
        $exportDir = wp_upload_dir()['basedir'] . '/wpstatic-markdown-export-' . uniqid();
        wp_mkdir_p($exportDir);

        $converter = new \League\HTMLToMarkdown\HtmlConverter([
            'strip_tags' => true, // remove unknown/non-translatable tags instead of passing them through raw
            'hard_break' => true,
        ]);

        $posts = get_posts([
            'post_type' => $settings['post_types'],
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ($posts as $post) {
            self::exportSinglePost($post, $converter, $exportDir, $localizeImages);
        }

        $zipPath = rtrim($exportDir, '/') . '.zip';
        self::zipDirectory($exportDir, $zipPath);
        self::rrmdir($exportDir);

        return $zipPath;
    }

    private static function exportSinglePost(WP_Post $post, \League\HTMLToMarkdown\HtmlConverter $converter, string $exportDir, bool $localizeImages): void {
        // apply_filters('the_content', ...) renders shortcodes/blocks exactly
        // like the frontend does - the same basis the REST API uses for
        // content.rendered.
        $html = apply_filters('the_content', $post->post_content);

        if ($localizeImages) {
            $html = self::localizeImages($html, $exportDir);
        }

        $markdown = trim($converter->convert($html));

        $content = self::buildFrontMatter($post) . "\n" . $markdown . "\n";

        $subDir = rtrim($exportDir, '/') . '/' . $post->post_type;

        if (!is_dir($subDir)) {
            wp_mkdir_p($subDir);
        }

        $filename = $post->post_name !== '' ? $post->post_name : (string) $post->ID;
        file_put_contents($subDir . '/' . $filename . '.md', $content);
    }

    private static function buildFrontMatter(WP_Post $post): string {
        $title = str_replace('"', '\\"', get_the_title($post));
        $excerpt = str_replace('"', '\\"', wp_strip_all_tags(get_the_excerpt($post)));

        $lines = [
            '---',
            'title: "' . $title . '"',
            'date: ' . get_the_date('Y-m-d H:i:s', $post),
            'slug: ' . $post->post_name,
            'status: ' . $post->post_status,
        ];

        if ($excerpt !== '') {
            $lines[] = 'excerpt: "' . $excerpt . '"';
        }

        $lines[] = '---';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Downloads images from the site's own WordPress domain (into
     * wp-content/uploads/... within the export folder, the same
     * structure as the main feature) and rewrites the src paths to
     * relative paths, so the export also works without a running
     * WordPress instance. Images from FOREIGN domains (CDN, external
     * embeds) are left unchanged - those don't belong to "us" and can't
     * meaningfully be downloaded as a blanket rule.
     *
     * All .md files sit exactly one level deep ({post_type}/x.md), so a
     * uniform "../" works as the way back up to the export root
     * directory.
     */
    private static function localizeImages(string $html, string $exportDir): string {
        $homeHost = parse_url(home_url(), PHP_URL_HOST);

        if (!$homeHost) {
            return $html;
        }

        return preg_replace_callback(
            '/<img([^>]*?)\ssrc=["\']([^"\']+)["\']([^>]*)>/i',
            function (array $matches) use ($exportDir, $homeHost) {
                [$full, $before, $src, $after] = $matches;

                if (parse_url($src, PHP_URL_HOST) !== $homeHost) {
                    return $full; // foreign domain - leave unchanged
                }

                $path = parse_url($src, PHP_URL_PATH);

                if (!$path) {
                    return $full;
                }

                $localTarget = rtrim($exportDir, '/') . $path;

                if (!is_dir(dirname($localTarget))) {
                    wp_mkdir_p(dirname($localTarget));
                }

                if (!is_file($localTarget)) {
                    @copy($src, $localTarget);
                }

                $relativePath = '..' . $path;

                return '<img' . $before . ' src="' . $relativePath . '"' . $after . '>';
            },
            $html
        );
    }

    private static function zipDirectory(string $source, string $destination): bool {
        if (!extension_loaded('zip') || !is_dir($source)) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($destination, ZipArchive::CREATE) !== true) {
            return false;
        }

        $source = str_replace('\\', '/', (string) realpath($source));

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $filePath = str_replace('\\', '/', (string) realpath($file->getPathname()));
            $relativePath = ltrim(str_replace($source, '', $filePath), '/');

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
            } else {
                $zip->addFile($filePath, $relativePath);
            }
        }

        return $zip->close();
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
