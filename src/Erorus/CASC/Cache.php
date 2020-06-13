<?php

namespace Erorus\CASC;

class Cache {

    private $cacheRoot = '';

    public function __construct($cacheRoot) {
        $this->cacheRoot = rtrim($cacheRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ( ! is_dir($this->cacheRoot)) {
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
    }

    private static function sanitizePath($path) {
        // not foolproof

        $path = str_replace('/', DIRECTORY_SEPARATOR, $path);

        $delim = preg_quote(DIRECTORY_SEPARATOR, '#');

        return preg_replace('#(?:^|' . $delim . ')\.\.(?:' . $delim . '|$)#', DIRECTORY_SEPARATOR, $path);
    }

    public function getFullPath($path) {
        return $this->cacheRoot . static::sanitizePath($path);
    }

    public function fileExists($path) {
        return file_exists($this->getFullPath($path));
    }

    public function fileModified($path) {
        return filemtime($this->getFullPath($path));
    }

    public function readPath($path) {
        $fullPath = $this->getFullPath($path);
        if (file_exists($fullPath)) {
            return file_get_contents($fullPath);
        }
        return false;
    }

    public function writePath($path, $data) {
        $fullPath = $this->getFullPath($path);

        Util::assertParentDir($fullPath, 'cache');

        return file_put_contents($fullPath, $data, LOCK_EX);
    }

    public function getReadHandle($path) {
        $fullPath = $this->getFullPath($path);
        if (file_exists($fullPath)) {
            return fopen($fullPath, 'rb');
        }
        return false;
    }

    public function getWriteHandle($path, $blte = false) {
        $sanPath = static::sanitizePath($path);

        $fullPath = $this->cacheRoot . $sanPath;
        if (file_exists($fullPath)) {
            return false;
        }

        Util::assertParentDir($fullPath, 'cache');

        if ($blte) {
            $fullPath = 'blte://' . $fullPath;
        }
        $f = fopen($fullPath, 'xb');
        if ($f === false) {
            fwrite(STDERR, "Cannot write to cache path $sanPath\n");
        }
        return $f;
    }

    public function deletePath($path) {
        $fullPath = $this->getFullPath($path);
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }
}
