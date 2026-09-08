<?php

if (!defined('ABSPATH')) {
    exit;
}

interface WPStatic_Uploader {
    /**
     * Uploads a single file to the target.
     *
     * @param string $localPath    Absolute path of the locally generated
     *                             file.
     * @param string $relativePath Target path relative to the configured
     *                             base directory (preserves the directory
     *                             structure defined in WordPress).
     *
     * @throws RuntimeException on transfer errors.
     */
    public function uploadFile(string $localPath, string $relativePath): void;

    /**
     * Uploads an entire local directory (recursively) to the target. For
     * SFTP: file by file via uploadFile(). For Netlify: as a single ZIP
     * deploy (see the class docs there), because Netlify doesn't offer
     * granular single-file transfer through this mechanism.
     *
     * @return array Additional info about the result (e.g. the deploy
     *               URL on Netlify), empty for SFTP.
     *
     * @throws RuntimeException on transfer errors.
     */
    public function uploadDirectory(string $localDir): array;
}
