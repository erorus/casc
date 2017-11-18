<?php

namespace Erorus\CASC;

class Archive extends AbstractDataSource
{
    private $cache;

    private $indexPath = false;
    private $indexLocations = [];
    private $indexProperties = [];

    private $hashMapCache = [];

    private $hostPath;

    const LOCATION_NONE = 0;
    const LOCATION_CACHE = 1;
    const LOCATION_WOW = 2;

    public function __construct(Cache $cache, $hostPath, $hashes, $wowPath = null)
    {
        $this->cache = $cache;
        $this->hostPath = $hostPath;

        if (!is_null($wowPath)) {
            $wowPath = rtrim($wowPath, DIRECTORY_SEPARATOR);

            $this->indexPath = sprintf('%2$s%1$sData%1$sindices', DIRECTORY_SEPARATOR, $wowPath);
            if (!is_dir($this->indexPath)) {
                fwrite(STDERR, sprintf("Could not find remote indexes locally at %s\n", $this->indexPath));
                $this->indexPath = false;
            } else {
                $this->indexPath .= DIRECTORY_SEPARATOR;
            }
        }

        foreach ($hashes as $hash) {
            if ($this->indexPath && file_exists($this->indexPath . $hash . '.index')) {
                $this->indexLocations[$hash] = static::LOCATION_WOW;
            } elseif ($cache->fileExists(static::buildCacheLocation($hash))) {
                $this->indexLocations[$hash] = static::LOCATION_CACHE;
            } else {
                $this->indexLocations[$hash] = static::LOCATION_NONE;
            }
        }

        arsort($this->indexLocations);
    }

    private static function buildCacheLocation($hash) {
        return 'data/' . $hash . '.index';
    }

    public function findHashInIndexes($hash) {
        $result = false;
        foreach ($this->indexLocations as $index => $location) {
            switch ($location) {
                case static::LOCATION_WOW:
                    $result = $this->findHashInIndex($index, $this->indexPath . $index . '.index', $hash);
                    break;
                case static::LOCATION_CACHE:
                    $result = $this->findHashInIndex($index, $this->cache->getFullPath(static::buildCacheLocation($index)), $hash);
                    break;
                case static::LOCATION_NONE:
                    if ($this->fetchIndex($index)) {
                        $result = $this->findHashInIndex($index, $this->cache->getFullPath(static::buildCacheLocation($index)), $hash);
                    } else {
                        $result = false;
                    }
                    break;
            }
            if ($result !== false) {
                break;
            }
        }
        if (!$result) {
            $result = $this->findHashOnCDN($hash);
        }
        return $result;
    }

    private function findHashInIndex($indexHash, $indexPath, $hash)
    {
        $f = false;
        if ( ! isset($this->hashMapCache[$indexHash])) {
            $f = $this->populateIndexHashMapCache($indexHash, $indexPath);
            if ($f === false) {
                return false;
            }
        }

        $x = static::FindInMap($this->hashMapCache[$indexHash], $hash);
        if ($x < 0) {
            if ($f !== false) {
                fclose($f);
            }
            return false;
        }

        list($keySize, $blockSize) = $this->indexProperties[$indexHash];

        $empty = str_repeat(chr(0), $keySize);

        if ($f === false) {
            $f = fopen($indexPath, 'rb');
        }
        if ($f === false) {
            fwrite(STDERR, "Could not open for reading $indexPath\n");
            return false;
        }
        fseek($f, $x * $blockSize);
        for ($pos = 0; $pos < $blockSize; $pos += ($keySize + 8)) {
            $test = fread($f, $keySize);
            if ($test == $empty) {
                break;
            }
            if ($test == $hash) {
                $entry = unpack('N*', fread($f, 8));
                fclose($f);

                return [
                    'archive' => $indexHash,
                    'length' => $entry[1],
                    'offset' => $entry[2],
                ];
            }
            fseek($f, 8, SEEK_CUR);
        }
        fclose($f);

        return false;
    }

    private function populateIndexHashMapCache($indexHash, $indexPath) {
        $lof = filesize($indexPath);
        $f = fopen($indexPath, 'rb');

        $foundSize = false;
        for ($checksumSize = 16; $checksumSize >= 0; $checksumSize--) {
            $checksumSizeFieldPos = $lof - $checksumSize - 4 - 1;
            fseek($f, $checksumSizeFieldPos);
            $possibleChecksumSize = current(unpack('C', fread($f, 1)));
            if ($possibleChecksumSize == $checksumSize) {
                fseek($f, $lof - (0x14 + $checksumSize));
                $archiveNameCheck = md5(fread($f, (0x14 + $checksumSize)));

                if ($archiveNameCheck == $indexHash) {
                    $foundSize = true;
                    break;
                }
            }
        }
        if (!$foundSize) {
            fclose($f);
            fwrite(STDERR, "Could not find checksum size in $indexPath\n");
            return false;
        }

        $footerSize = 12 + $checksumSize * 3;
        $footerPos = $lof - $footerSize;

        fseek($f, $footerPos);
        $bytes = fread($f, $footerSize);

        $footer = [
            'index_block_hash' => substr($bytes, 0, $checksumSize),
            'toc_hash' => substr($bytes, $checksumSize, $checksumSize),
            'lower_md5_footer' => substr($bytes, $footerSize - $checksumSize),
        ];
        $footer = array_merge($footer, unpack('C4unk/Coffset/Csize/CkeySize/CchecksumSize/InumElements', substr($bytes, $checksumSize * 2, 12)));

        $blockSize = 4096;

        for ($blockCount = floor(($lof - $footerSize) / $blockSize);
            ($blockCount * $blockSize + static::getTocSize($footer['keySize'], $footer['checksumSize'], $blockCount)) > $footerPos;
            $blockCount--);

        $tocPosition = $blockCount * $blockSize;
        $tocSize = static::getTocSize($footer['keySize'], $footer['checksumSize'], $blockCount);
        if ($tocPosition + $tocSize != $footerPos) {
            fclose($f);
            fwrite(STDERR, "Could not place toc in $indexPath\n");
            return false;
        }

        $keySize = $footer['keySize'];

        $this->hashMapCache[$indexHash] = [];
        for ($x = 0; $x < $blockCount; $x++) {
            fseek($f, $x * $blockSize);
            $this->hashMapCache[$indexHash][$x] = fread($f, $keySize);
        }

        $this->indexProperties[$indexHash] = [$keySize, $blockSize];

        return $f;
    }

    private static function getTocSize($keySize, $checksumSize, $blockCount) {
        return ($blockCount * $keySize) + (($blockCount - 1) * $checksumSize);
    }

    private static function FindInMap($map, $needle) {
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

    private function fetchIndex($hash) {
        $cachePath = static::buildCacheLocation($hash);
        if ($this->cache->fileExists($cachePath)) {
            return true;
        }

        $f = $this->cache->getWriteHandle($cachePath);
        if ($f === false) {
            throw new \Exception("Cannot create write handle for index file at $cachePath\n");
        }

        $url = sprintf('%sdata/%s/%s/%s.index', $this->hostPath, substr($hash, 0, 2), substr($hash, 2, 2), $hash);

        $line = " - Fetching remote index $hash ";
        echo $line, sprintf("\x1B[%dD", strlen($line));

        $oldProgressOutput = HTTP::$writeProgressToStream;
        HTTP::$writeProgressToStream = null;

        $success = HTTP::Get($url, $f);

        HTTP::$writeProgressToStream = $oldProgressOutput;
        echo "\x1B[K";

        if (!$success) {
            fclose($f);
            $this->cache->deletePath($cachePath);
            return false;
        }
        fclose($f);

        $this->indexLocations[$hash] = static::LOCATION_CACHE;

        return true;
    }

    private function findHashOnCDN($hash) {
        $hash = bin2hex($hash);
        $url = sprintf('%sdata/%s/%s/%s', $this->hostPath, substr($hash, 0, 2), substr($hash, 2, 2), $hash);

        $headers = HTTP::Head($url);
        if ($headers['responseCode'] !== 200) {
            return false;
        }

        return ['archive' => $hash];
    }

    protected function fetchFile($locationInfo, $destPath) {
        $hash = $locationInfo['archive'];
        $url = sprintf('%sdata/%s/%s/%s', $this->hostPath, substr($hash, 0, 2), substr($hash, 2, 2), $hash);

        if (!CASC::assertParentDir($destPath, 'output')) {
            return false;
        }

        $writePath = 'blte://' . $destPath;
        $writeHandle = fopen($writePath, 'wb');
        if ($writeHandle === false) {
            throw new \Exception(sprintf("Unable to open %s for writing\n", $writePath));
        }

        $range = isset($locationInfo['offset']) ? sprintf('%d-%d', $locationInfo['offset'], $locationInfo['offset'] + $locationInfo['length'] - 1) : null;
        $success = HTTP::Get($url, $writeHandle, $range);

        fclose($writeHandle);

        return !!$success;
    }
}