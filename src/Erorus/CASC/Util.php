<?php

namespace Erorus\CASC;

/**
 * Miscellaneous standalone utility functions.
 */
class Util {
    /**
     * Given a filesystem path, make sure the parent directory of that path exists and is writable.
     *
     * @param string $fullPath
     * @param string $type The "type" of directory you're trying to use. Only used in error messages.
     *
     * @return bool True when everything is okay, false on error.
     */
    public static function assertParentDir(string $fullPath, string $type): bool {
        $parentDir = dirname($fullPath);
        if (!is_dir($parentDir)) {
            if (!mkdir($parentDir, 0755, true)) {
                fwrite(STDERR, "Cannot create $type dir $parentDir\n");
                return false;
            }
        } elseif (!is_writable($parentDir)) {
            fwrite(STDERR, "Cannot write in $type dir $parentDir\n");
            return false;
        }

        return true;
    }

    /**
     * @param string $host     A hostname, or a URL prefix with protocol, host, and path.
     * @param string $cdnPath  A product-specific path component from the versionConfig where we get these assets.
     * @param string $pathType "config" or "data", typically "data".
     * @param string $hash     A hex-encoded hash of the file you're trying to fetch.
     *
     * @return string
     */
    public static function buildTACTUrl(string $host, string $cdnPath, string $pathType, string $hash): string {
        if (preg_match('/[^0-9a-f]/', $hash)) {
            throw new \Exception("Invalid hash format: expected lowercase hex!");
        }

        if (strpos($host, '://') === false) {
            $host = "http://{$host}/";
        }

        return sprintf(
            '%s%s/%s/%s/%s/%s',
            $host,
            $cdnPath,
            $pathType,
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            $hash
        );
    }
}
