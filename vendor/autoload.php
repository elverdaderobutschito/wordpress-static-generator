<?php

/**
 * Minimaler PSR-4-Autoloader für die mitgelieferten Composer-Bibliotheken:
 *  - phpseclib3 (3.0.43, https://github.com/phpseclib/phpseclib, MIT)
 *  - paragonie/constant_time_encoding (Laufzeit-Abhängigkeit von phpseclib3)
 *  - league/html-to-markdown (5.1.1, https://github.com/thephpleague/html-to-markdown, MIT)
 *
 * Das ist bewusst KEIN vollständiger Composer-Autoloader, sondern ein
 * schlankes Äquivalent nur für diese Packages - falls du später weitere
 * Composer-Dependencies brauchst, ersetze diese Datei durch einen echten
 * "composer install"-Lauf (dann wird sie automatisch überschrieben).
 */

if (!defined('ABSPATH') && php_sapi_name() !== 'cli') {
    // Diese Datei ist für den Einsatz innerhalb von WordPress gedacht.
}

spl_autoload_register(function (string $class): void {
    $map = [
        'phpseclib3\\' => __DIR__ . '/phpseclib/phpseclib/phpseclib/',
        'ParagonIE\\ConstantTime\\' => __DIR__ . '/paragonie/constant_time_encoding/src/',
        'League\\HTMLToMarkdown\\' => __DIR__ . '/league/html-to-markdown/src/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

require_once __DIR__ . '/phpseclib/phpseclib/phpseclib/bootstrap.php';
