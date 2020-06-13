<?php

namespace Erorus\CASC;

/**
 * DataSources are from where we can extract data for specific files.
 */
abstract class DataSource {
    /** @var bool True to allow extracted files with errors to remain on the filesystem, false to remove them. */
    private static $ignoreErrors = false;

    abstract public function findHashInIndexes($hash);
    abstract protected function fetchFile($locationInfo, $destPath);

    public function extractFile($locationInfo, string $destPath, ?string $contentHash = null): bool {
        $success = $this->fetchFile($locationInfo, $destPath);

        $success &= file_exists($destPath);
        $success &= filesize($destPath) > 0;
        $success &= !$contentHash || ($contentHash === md5_file($destPath, true));

        if (!$success && !self::$ignoreErrors) {
            unlink($destPath);
        }

        return $success;
    }

    /**
     * Get/Set whether we ignore errors during extraction.
     *
     * @param bool $doIgnore
     *
     * @return bool The current state of whether errors are ignored.
     */
    public static function ignoreErrors(?bool $doIgnore = null): bool {
        if (!is_null($doIgnore)) {
            self::$ignoreErrors = $doIgnore;
        }

        return self::$ignoreErrors;
    }
}
