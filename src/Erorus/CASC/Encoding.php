<?php

namespace Erorus\CASC;

use Erorus\CASC\Encoding\ContentMap;

/**
 * This is where we map content hashes to encoding hashes, which are themselves used as keys in the DataSource.
 */
class Encoding {
    private $entryMap = [];
    private $entryStart;

    private $fileHandle;
    private $header;

    public function __construct(Cache $cache, $hosts, $cdnPath, $hash)
    {
        $cachePath = 'data/' . $hash;

        $f = $cache->getReadHandle($cachePath);
        if (is_null($f)) {
            foreach ($hosts as $host) {
                $f = $cache->getWriteHandle($cachePath, true);
                if (is_null($f)) {
                    throw new \Exception("Cannot create cache location for encoding data\n");
                }

                $url = sprintf('http://%s/%s/data/%s/%s/%s', $host, $cdnPath, substr($hash, 0, 2),
                    substr($hash, 2, 2), $hash);
                try {
                    $success = HTTP::Get($url, $f);
                } catch (BLTE\Exception $e) {
                    $success = false;
                }
                if ( ! $success) {
                    fclose($f);
                    $cache->delete($cachePath);
                    continue;
                }
                fclose($f);
                $f = $cache->getReadHandle($cachePath);
                break;
            }
            if ( ! $success) {
                throw new \Exception("Could not fetch encoding data at $url\n");
            }
        }

        if (fread($f, 2) != 'EN') {
            fclose($f);
            throw new \Exception("Encoding file did not have expected header\n");
        }

        $this->header = unpack('Cunk/CchecksumSizeA/CchecksumSizeB/vflagsA/vflagsB/NnumEntriesA/NnumEntriesB', fread($f, 15));
        $this->header['stringBlockSize'] = current(unpack('J', str_repeat(chr(0), 3) . fread($f, 5)));

        fseek($f, $this->header['stringBlockSize'], SEEK_CUR); // skip string block
        fseek($f, 2 * $this->header['checksumSizeA'] * $this->header['numEntriesA'], SEEK_CUR); // skip encoding table header

        $this->entryStart = ftell($f);

        for ($x = 0; $x < $this->header['numEntriesA']; $x++) {
            fseek($f, 6, SEEK_CUR);
            $this->entryMap[] = fread($f, $this->header['checksumSizeA']);
            fseek($f, 4096 - 6 - $this->header['checksumSizeA'], SEEK_CUR);
        }

        $this->fileHandle = $f;
    }

    public function __destruct()
    {
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
        $idx = $this->FindInMap($contentHash);
        if ($idx < 0) {
            return null;
        }

        fseek($this->fileHandle, $this->entryStart + $idx * 4096);

        $block = $this->parseMapABlock(fread($this->fileHandle, 4096));

        if (isset($block[$contentHash])) {
            return new ContentMap($contentHash, $block[$contentHash][1], $block[$contentHash][0]);
        }

        return null;
    }

    private function FindInMap($needle)
    {
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

    private function parseMapABlock($bytes) {
        $checksumSize = $this->header['checksumSizeA'];
        $block = [];

        $pos = 0;
        while ($pos < strlen($bytes)) {
            $keyCount = ord(substr($bytes, $pos++, 1));
            if ($keyCount == 0) {
                break;
            }
            $fileSize = current(unpack('J', str_repeat(chr(0), 3) . substr($bytes, $pos, 5))); $pos += 5;
            if ($fileSize == 0) {
                break;
            }

            $hash = substr($bytes, $pos, $checksumSize);
            $pos += $checksumSize;

            $rec = [$fileSize, []];
            for ($x = 0; $x < $keyCount; $x++) {
                $rec[1][] = substr($bytes, $pos, $checksumSize);
                $pos += $checksumSize;
            }
            $block[$hash] = $rec;
        }

        return $block;
    }

}
