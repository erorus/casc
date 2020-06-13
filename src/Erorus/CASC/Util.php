<?php

namespace Erorus\CASC;

class Util {
    public static function assertParentDir($fullPath, $type) {
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
}
