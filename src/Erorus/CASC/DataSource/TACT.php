<?php

namespace Erorus\CASC\DataSource;

use Erorus\CASC\BLTE;
use Erorus\CASC\Cache;
use Erorus\CASC\DataSource;
use Erorus\CASC\DataSource\Location\TACT as TACTLocation;
use Erorus\CASC\HTTP;
use Erorus\CASC\Util;

/**
 * This extracts data from the remote archives on the CDN.
 */
class TACT extends DataSource {
    /** @var int Our way to keep track of where various indexes are available. */
    private const LOCATION_NONE = 0;
    private const LOCATION_CACHE = 1;
    private const LOCATION_WOW = 2;

    /** @var Cache A disk cache where we can find and store raw files we download. */
    private $cache;

    /** @var string A product-specific CDN path component from the versionConfig where we get these assets. */
    private $cdnPath;

    /** @var int[] Keyed by hex hash strings as index names, the self::LOCATION_ constant describing its whereabouts. */
    private $indexLocations = [];

    /** @var string|null The local filesystem path for indexes cached by the WoW installation. */
    private $indexPath = null;

    /** @var array[] Keyed by hex hash strings as index names, the key size and block size used in that index. */
    private $indexProperties = [];

    /** @var array[] Keyed by hex hash strings as index names, the first key in each block of each index.  */
    private $hashMapCache = [];

    /** @var iterable The CDN host list. */
    private $servers;

    /**
     * Given information on what archives are available on the CDN (as well as an optional local WoW install), assembles
     * it and assigns locations to the available indexes for later use.
     *
     * @param Cache $cache A disk cache where we can find and store raw files we download.
     * @param iterable $servers Typically a HostList, or an array. CDN hostnames.
     * @param string $cdnPath A product-specific path component from the versionConfig where we get these assets.
     * @param string[] $hashes The hex hash strings for the files to read.
     * @param string|null $wowPath The filesystem path to the WoW install.
     *
     * @throws \Exception
     */
    public function __construct(
        Cache $cache,
        iterable $servers,
        string $cdnPath,
        array $hashes,
        ?string $wowPath = null
    ) {
        $this->cache   = $cache;
        $this->servers = $servers;
        $this->cdnPath = $cdnPath;

        if (!is_null($wowPath)) {
            $wowPath = rtrim($wowPath, DIRECTORY_SEPARATOR);

            $this->indexPath = sprintf('%2$s%1$sData%1$sindices', DIRECTORY_SEPARATOR, $wowPath);
            if (!is_dir($this->indexPath)) {
                fwrite(STDERR, sprintf("Could not find remote indexes locally at %s\n", $this->indexPath));
                $this->indexPath = null;
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

    /**
     * Find a location in this data source for the given encoding hash. Null if not found.
     *
     * @param string $hash An encoding hash, in binary bytes.
     *
     * @return Location|null
     */
    public function findHashInIndexes(string $hash): ?Location {
        $result = null;
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
                    }
                    break;
            }
            if ($result) {
                break;
            }
        }
        if (!$result) {
            $result = $this->findHashOnCDN($hash);
        }

        return $result;
    }

    /**
     * Given the location of some content in this data source, extract it to the given destination filesystem path.
     *
     * @param TACTLocation $locationInfo
     * @param string $destPath
     *
     * @return bool Success
     */
    protected function fetchFile(Location $locationInfo, string $destPath): bool {
        if (!is_a($locationInfo, TACTLocation::class)) {
            throw new \Exception("Unexpected location info object type.");
        }

        if (!Util::assertParentDir($destPath, 'output')) {
            return false;
        }

        $hash = $locationInfo->archive;
        foreach ($this->servers as $server) {
            $url = Util::buildTACTUrl($server, $this->cdnPath, 'data', $hash);

            $writePath = 'blte://' . $destPath;
            $writeHandle = fopen($writePath, 'wb');
            if ($writeHandle === false) {
                throw new \Exception(sprintf("Unable to open %s for writing\n", $writePath));
            }

            $range = isset($locationInfo->offset) ? sprintf('%d-%d', $locationInfo->offset,
                $locationInfo->offset + $locationInfo->length - 1) : null;
            try {
                $success = HTTP::get($url, $writeHandle, $range);
            } catch (BLTE\Exception $e) {
                $success = false;
            } catch (\Exception $e) {
                $success = false;
                echo "\n - " . $e->getMessage() . " ";
            }

            fclose($writeHandle);
            if (!$success) {
                unlink($destPath);
            } else {
                break;
            }
        }

        return !!$success;
    }

    /**
     * Downloads the index named $hash from the CDN and stores it in the cache.
     *
     * @param string $hash
     *
     * @return bool True on success.
     * @throws \Exception
     */
    private function fetchIndex(string $hash): bool {
        $cachePath = static::buildCacheLocation($hash);
        if ($this->cache->fileExists($cachePath)) {
            return true;
        }

        $line = " - Fetching remote index $hash ";
        echo $line, sprintf("\x1B[%dD", strlen($line));

        $oldProgressOutput = HTTP::$writeProgressToStream;
        HTTP::$writeProgressToStream = null;
        $success = false;
        foreach ($this->servers as $server) {
            $url = Util::buildTACTUrl($server, $this->cdnPath, 'data', $hash) . '.index';

            $f = $this->cache->getWriteHandle($cachePath);
            if (is_null($f)) {
                throw new \Exception("Cannot create write handle for index file at $cachePath\n");
            }

            try {
                $success = HTTP::get($url, $f);
            } catch (\Exception $e) {
                echo "\n - " . $e->getMessage() . "\n";
                $success = false;
            }

            fclose($f);

            if (!$success) {
                $this->cache->delete($cachePath);
            } else {
                break;
            }
        }

        HTTP::$writeProgressToStream = $oldProgressOutput;
        echo "\x1B[K";

        if (!$success) {
            return false;
        }

        $this->indexLocations[$hash] = static::LOCATION_CACHE;

        return true;
    }

    /**
     * Find a location in this data source and the given index for the given encoding hash. Null if not found.
     *
     * @param string $indexHash The hex index name.
     * @param string $indexPath The full filesystem path to this index file, either in our cache or the WoW install.
     * @param string $hash An encoding hash, in binary bytes.
     *
     * @return TACTLocation|null
     */
    private function findHashInIndex(string $indexHash, string $indexPath, string $hash): ?TACTLocation {
        $f = null;
        if (!isset($this->hashMapCache[$indexHash])) {
            $f = $this->populateIndexHashMapCache($indexHash, $indexPath);
            if (is_null($f)) {
                return null;
            }
        }

        $x = static::findInMap($this->hashMapCache[$indexHash], $hash);
        if ($x < 0) {
            if (!is_null($f)) {
                fclose($f);
            }
            return null;
        }

        list($keySize, $blockSize) = $this->indexProperties[$indexHash];

        $empty = str_repeat(chr(0), $keySize);

        if (is_null($f)) {
            $f = fopen($indexPath, 'rb');
        }
        if (is_null($f)) {
            fwrite(STDERR, "Could not open for reading $indexPath\n");

            return null;
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

                return new TACTLocation([
                    'archive' => $indexHash,
                    'length' => $entry[1],
                    'offset' => $entry[2],
                ]);
            }
            fseek($f, 8, SEEK_CUR);
        }
        fclose($f);

        return null;
    }

    /**
     * Sometimes content won't be embedded in an archive file, and is fetched at its own URL. See if a file exists for
     * the given encoding hash, and return it as a location if one does.
     *
     * @param string $hash A binary encoding hash.
     *
     * @return TACTLocation|null
     * @throws \Exception
     */
    private function findHashOnCDN(string $hash): ?TACTLocation {
        $hash = bin2hex($hash);
        foreach ($this->servers as $server) {
            $headers = HTTP::head(Util::buildTACTUrl($server, $this->cdnPath, 'data', $hash));
            if ($headers['responseCode'] === 200) {
                return new TACTLocation(['archive' => $hash]);
            }
        }

        return null;
    }

    /**
     * Reads the index to store in memory the first encoding hash in each block, for quicker binary searches later.
     *
     * @param string $indexHash The hex index name.
     * @param string $indexPath The full filesystem path to this index file, either in our cache or the WoW install.
     *
     * @return resource|null A file handle to the index, or null on error.
     */
    private function populateIndexHashMapCache(string $indexHash, string $indexPath) {
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

            return null;
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

            return null;
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

    /**
     * Given an index name (expressed as a hex string), return the filesystem path where we store it in the cache.
     *
     * @param string $hash
     *
     * @return string
     */
    private static function buildCacheLocation(string $hash): string {
        return 'data/' . $hash . '.index';
    }

    /**
     * Return the index in $map which points to the block likely to contain the encoding hash $needle. May return -1 if
     * not found.
     *
     * @param string[] $map
     * @param string $needle A content hash.
     *
     * @return int
     */
    private static function findInMap(array $map, string $needle): int {
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

    /**
     * A simple calculation to get the expected size of the index's TOC block.
     *
     * @param int $keySize
     * @param int $checksumSize
     * @param int $blockCount
     *
     * @return int
     */
    private static function getTocSize(int $keySize, int $checksumSize, int $blockCount): int {
        return ($blockCount * $keySize) + (($blockCount - 1) * $checksumSize);
    }
}
