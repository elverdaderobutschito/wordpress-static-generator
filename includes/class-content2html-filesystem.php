<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin wrapper around WP_Filesystem, used throughout this plugin instead
 * of direct PHP filesystem functions (mkdir/unlink/rmdir/rename/readfile)
 * - initializes the global $wp_filesystem lazily (once) and exposes a
 * small set of methods matching the operations this plugin actually
 * needs, so call sites don't each have to repeat the
 * "is $wp_filesystem set up yet" boilerplate.
 *
 * On a typical web host (PHP can write to wp-content directly), this
 * transparently uses the 'direct' transport - no FTP credentials prompt.
 * On hosts where it can't determine direct access is possible,
 * WP_Filesystem() returns false; in that case these methods return
 * false/empty as well rather than silently falling back to raw PHP
 * calls, so a permissions problem surfaces clearly instead of being
 * masked.
 */
class Content2HTML_Filesystem {
    private static ?WP_Filesystem_Base $filesystem = null;

    private static function get(): ?WP_Filesystem_Base {
        if (self::$filesystem !== null) {
            return self::$filesystem;
        }

        global $wp_filesystem;

        if (empty($wp_filesystem)) {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            WP_Filesystem();
        }

        self::$filesystem = $wp_filesystem instanceof WP_Filesystem_Base ? $wp_filesystem : null;

        return self::$filesystem;
    }

    /**
     * Creates a directory, including any missing parent directories -
     * WP_Filesystem_Base::mkdir() does NOT do this recursively by
     * itself (unlike PHP's native mkdir($path, $mode, true)), so this
     * walks the path and creates each missing segment in turn.
     */
    public static function mkdir(string $path, int $chmod = 0775): bool {
        $fs = self::get();

        if ($fs === null) {
            return false;
        }

        $path = rtrim($path, '/');

        if ($path === '' || $fs->is_dir($path)) {
            return true;
        }

        // Ensure the parent exists first (recursing up), then create
        // this segment.
        if (!self::mkdir(dirname($path), $chmod)) {
            return false;
        }

        return $fs->mkdir($path, $chmod);
    }

    /**
     * Deletes a single file.
     */
    public static function deleteFile(string $path): bool {
        $fs = self::get();

        return $fs !== null && $fs->delete($path, false, 'f');
    }

    /**
     * Deletes a directory and everything in it (recursively).
     */
    public static function deleteDir(string $path): bool {
        $fs = self::get();

        return $fs !== null && $fs->delete($path, true, 'd');
    }

    public static function move(string $source, string $destination): bool {
        $fs = self::get();

        return $fs !== null && $fs->move($source, $destination, true);
    }

    /**
     * Reads and returns a file's full contents - used in place of
     * readfile() (which streams directly to output instead of
     * returning a string; the one call site that used it, an
     * admin-triggered ZIP download, is small enough that loading it
     * fully into memory first is not a concern).
     */
    public static function getContents(string $path) {
        $fs = self::get();

        return $fs !== null ? $fs->get_contents($path) : false;
    }

    public static function isDir(string $path): bool {
        $fs = self::get();

        return $fs !== null && $fs->is_dir($path);
    }

    /**
     * Only meaningful in a CLI context (see the PHP_SAPI guard at the
     * one call site) - in a typical web request, PHP runs as a
     * different user (e.g. www-data) that generally can't chown()
     * anyway, WP_Filesystem or not.
     */
    public static function chown(string $path, string $owner): bool {
        $fs = self::get();

        return $fs !== null && method_exists($fs, 'chown') && $fs->chown($path, $owner);
    }
}
