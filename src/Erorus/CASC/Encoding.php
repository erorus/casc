<?php

namespace Erorus\CASC;

use Erorus\CASC\Encoding\ContentMap;

/**
 * This is where we map content hashes to encoding hashes, which are themselves used as keys in the DataSource.
 */
class Encoding {
    /** @var int How many bytes into a content table entry you find the start of the content hash. */
    private const CONTENT_TABLE_ENTRY_CONTENT_HASH_OFFSET = 6;

    /** @var int The number of bytes in a page index, excluding the size of the hash used for that table type. */
    private const INDEX_ENTRY_DATA_LENGTH = 16;

    /** @var string[] The first content hash in each content table page.  */
    private $entryMap = [];

    /** @var int Where the content table data starts. */
    private $entryStart;

    /** @var resource The file handle for the encoding cache file. */
    private $fileHandle;

    /** @var int[] Various fields read from the header of the encoding file. */
    private $header;

    /**
     * Fetches and parses the encoding file to map content hashes to encoding hashes.
     *
     * @param Cache $cache A disk cache where we can find and store raw files we download.
     * @param \Iterator $servers Typically a HostList, or an array. CDN hostnames.
     * @param string $cdnPath A product-specific path component from the versionConfig where we get these assets.
     * @param string $hash The hex hash string for the file to read.
     * @param bool $isBLTE Whether the file identified by $hash is BLTE-encoded.
     *
     * @throws \Exception
     */
    public function __construct(Cache $cache, \Iterator $servers, string $cdnPath, string $hash, bool $isBLTE) {
        $cachePath = 'data/' . $hash;

        $f = $cache->getReadHandle($cachePath);
        if (is_null($f)) {
            foreach ($servers as $server) {
                $f = $cache->getWriteHandle($cachePath, $isBLTE);
                if (is_null($f)) {
                    throw new \Exception("Cannot create cache location for encoding data\n");
                }

                $url = Util::buildTACTUrl($server, $cdnPath, 'data', $hash);
                try {
                    $success = HTTP::get($url, $f);
                } catch (BLTE\Exception $e) {
                    $success = false;
                } catch (\Exception $e) {
                    echo "\n - " . $e->getMessage() . " ";
                    $success = false;
                }
                if (!$success) {
                    fclose($f);
                    $cache->delete($cachePath);
                    continue;
                }
                fclose($f);
                $f = $cache->getReadHandle($cachePath);
                break;
            }
            if (!$success) {
                throw new \Exception("Could not fetch encoding data at $url\n");
            }
        }

        if (fread($f, 2) != 'EN') {
            fclose($f);
            throw new \Exception("Encoding file did not have expected header\n");
        }

        $headerFormat = [
            'Cversion',
            'CcontentHashSize',
            'CencodingHashSize',
            'ncontentTableSizeKb',
            'nencodingSpecTableSizeKb',
            'NcontentTablePageCount',
            'NencodingSpecTablePageCount',
            'Cunk',
            'NstringBlockSize',
        ];

        $this->header = unpack(implode('/', $headerFormat), fread($f, 20));

        // Skip string block, only used in encoding spec table.
        fseek($f, $this->header['stringBlockSize'], SEEK_CUR);

        // Skip content table index.
        $indexEntrySize = $this->header['contentHashSize'] + self::INDEX_ENTRY_DATA_LENGTH;
        fseek($f, $indexEntrySize * $this->header['contentTablePageCount'], SEEK_CUR);

        // Remember where the data starts.
        $this->entryStart = ftell($f);

        // Build our own index. Why don't we use the one we just skipped past? I don't know.
        $pageSize = $this->header['contentTableSizeKb'] * 1024;
        for ($x = 0; $x < $this->header['contentTablePageCount']; $x++) {
            // Remember where we started this page.
            $pageStart = ftell($f);

            // Read the first content hash in this (first) entry of the page.
            fseek($f, self::CONTENT_TABLE_ENTRY_CONTENT_HASH_OFFSET, SEEK_CUR);
            $this->entryMap[] = fread($f, $this->header['contentHashSize']);

            // Jump to the start of the next page.
            fseek($f, $pageStart + $pageSize);
        }

        $this->fileHandle = $f;
    }

    /**
     * Closes the file handle when destructing the object.
     */
    public function __destruct() {
        fclose($this->fileHandle);
    }

    /**
     * Returns the content map for the given content hash. Returns null when none is found.
     *
     * @param string $contentHash The content hash in binary bytes.
     *
     * @return ContentMap|null
     */
    public function getContentMap(string $contentHash): ?ContentMap {
        $idx = $this->findInMap($contentHash);
        if ($idx < 0) {
            return null;
        }

        $pageSize = $this->header['contentTableSizeKb'] * 1024;
        fseek($this->fileHandle, $this->entryStart + $idx * $pageSize);

        return $this->findContentMapInPage($contentHash, fread($this->fileHandle, $pageSize));
    }

    /**
     * Return the content map for the given content hash found in the given page bytes, or null if not found.
     *
     * @param string $contentHash
     * @param string $pageBytes
     *
     * @return ContentMap|null
     */
    private function findContentMapInPage(string $contentHash, string $pageBytes): ?ContentMap {
        $pos = 0;
        while ($pos < strlen($pageBytes)) {
            $keyCount = ord(substr($pageBytes, $pos++, 1));
            if ($keyCount == 0) {
                break;
            }

            $fileSize = unpack('J', str_repeat(chr(0), 3) . substr($pageBytes, $pos, 5))[1];
            $pos += 5;
            if ($fileSize == 0) {
                break;
            }

            $hash = substr($pageBytes, $pos, $this->header['contentHashSize']);
            $pos += $this->header['contentHashSize'];

            if ($hash === $contentHash) {
                $encodedHashes = [];
                for ($x = 0; $x < $keyCount; $x++) {
                    $encodedHashes[] = substr($pageBytes, $pos, $this->header['encodingHashSize']);
                    $pos += $this->header['encodingHashSize'];
                }

                return new ContentMap([
                    'contentHash' => $contentHash,
                    'encodedHashes' => $encodedHashes,
                    'fileSize' => $fileSize,
                ]);
            } else {
                $pos += $this->header['encodingHashSize'] * $keyCount;
            }
        }

        return null;
    }

    /**
     * Return the page index which is likely to contain the content hash $needle. May return -1 if the hash comes before
     * the first hash in the index.
     *
     * @param string $needle A content hash.
     *
     * @return int
     */
    private function findInMap(string $needle): int {
        $map = $this->entryMap;

        $lo = 0;
        $hi = count($map) - 1;

        while ($lo <= $hi) {
            $mid = (int)(($hi - $lo) / 2) + $lo;
            $cmp = strcmp($map[$mid], $needle);
            if ($cmp < 0) {
                $lo = $mid + 1;
            } elseif ($cmp > 0) {
                $hi = $mid - 1;
            } else {
                return $mid;
            }
        }

        return $lo - 1;
    }
}
