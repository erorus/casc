<?php

namespace Erorus\CASC;

/**
 * This turns filenames (or, more often, numeric file IDs) into content hashes.
 */
abstract class NameLookup {
    /**
     * Given the name (including any path components) of a file, or the numeric file ID, return its content hash.
     * Returns null when not found.
     *
     * @param string $nameOrId Nowadays, a file name typically only works for the Install list, and a numeric file ID
     *                         is required for most other files which are found in the Root list.
     * @param string|null $locale e.g. "enUS". See Root for the entire list.
     *
     * @return string|null A content hash, in binary bytes (not hex).
     */
    abstract public function getContentHash(string $nameOrId, ?string $locale = null): ?string;
}
