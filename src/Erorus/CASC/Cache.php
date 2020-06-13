<?php

namespace Erorus\CASC;

/**
 * This class helps manage a disk cache of various files for this application.
 */
class Cache {
    /** @var string The absolute path to the cache directory. */
    private $cacheRoot = '';

    /**
     * Cache constructor.
     *
     * @param string $cacheRoot The path to the cache directory. It will be created if it doesn't exist.
     *
     * @throws \Exception
     */
    public function __construct(string $cacheRoot) {
        $this->cacheRoot = rtrim($cacheRoot, DIRECTORY_SEPARATOR);

        if (!is_dir($this->cacheRoot)) {
            if (file_exists($this->cacheRoot)) {
                throw new \Exception(sprintf("Cache path %s already exists as a file. It needs to be a writable directory.\n", $this->cacheRoot));
            }
            if (!mkdir($this->cacheRoot, 0755, true)) {
                throw new \Exception(sprintf("Cannot create cache path %s\n", $this->cacheRoot));
            }
        }

        if (!is_writable($this->cacheRoot)) {
            throw new \Exception(sprintf("Cannot write to cache path %s", $this->cacheRoot));
        }

        $this->cacheRoot = realpath($this->cacheRoot) . DIRECTORY_SEPARATOR;
    }

    /**
     * Given a path component, removes it from the cache. Returns true on success.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path): bool {
        $fullPath = $this->getFullPath($path);
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }

        return false;
    }

    /**
     * Given a path component, return true when it exists in the cache.
     *
     * @param string $path
     *
     * @return bool
     */
    public function fileExists(string $path): bool {
        return file_exists($this->getFullPath($path));
    }

    /**
     * Given a path component, return the UNIX timestamp when it was last modified.
     *
     * @param string $path
     *
     * @return int
     */
    public function fileModified(string $path): int {
        return filemtime($this->getFullPath($path));
    }

    /**
     * Given a path component, return its absolute path in the cache directory.
     *
     * @param string $path
     *
     * @return string
     */
    public function getFullPath(string $path): string {
        return $this->cacheRoot . static::sanitizePath($path);
    }

    /**
     * Given a path component, returns a read-only file handle to that existing file in the cache, or null on error.
     *
     * @param string $path
     *
     * @return resource|null
     */
    public function getReadHandle(string $path) {
        $fullPath = $this->getFullPath($path);
        if (file_exists($fullPath)) {
            $result = fopen($fullPath, 'rb');

            return ($result === false) ? null : $result;
        }

        return null;
    }

    /**
     * Given a path component, returns a writable file handle to that file in the cache, or null on error.
     *
     * @param string $path
     * @param bool $blte True when we'll write BLTE-encoded data, which should be decoded before being stored on disk.
     *
     * @return resource|null
     */
    public function getWriteHandle(string $path, bool $blte = false) {
        $fullPath = $this->getFullPath($path);
        if (file_exists($fullPath)) {
            return null;
        }

        Util::assertParentDir($fullPath, 'cache');

        if ($blte) {
            $fullPath = 'blte://' . $fullPath;
        }

        $handle = fopen($fullPath, 'xb');
        if ($handle === false) {
            fwrite(STDERR, "Cannot write to cache path $fullPath\n");

            return null;
        }

        return $handle;
    }

    /**
     * Given a path component, return its contents from the cache. Returns null when not found.
     *
     * @param string $path
     *
     * @return null|string
     */
    public function read(string $path): ?string {
        $fullPath = $this->getFullPath($path);
        if (file_exists($fullPath)) {
            $data = file_get_contents($fullPath);

            return ($data === false) ? null : $data;
        }

        return null;
    }

    /**
     * Given a path component, set the contents of the cache file there to the given $data. Returns the number of bytes
     * written, or null on error.
     *
     * @param string $path
     * @param string $data
     *
     * @return int|null
     */
    public function write(string $path, string $data): ?int {
        $fullPath = $this->getFullPath($path);

        Util::assertParentDir($fullPath, 'cache');

        $result = file_put_contents($fullPath, $data, LOCK_EX);

        return ($result === false) ? null : $result;
    }

    /**
     * Given a path component, remove any parent directory traversal from it ('../'). It's not foolproof, though.
     *
     * @param string $path
     *
     * @return string
     */
    private static function sanitizePath(string $path): string {
        $path = str_replace('/', DIRECTORY_SEPARATOR, $path);

        $delim = preg_quote(DIRECTORY_SEPARATOR, '#');

        return preg_replace('#(?:^|' . $delim . ')\.\.(?:' . $delim . '|$)#', DIRECTORY_SEPARATOR, $path);
    }
}
