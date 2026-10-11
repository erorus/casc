<?php

namespace Erorus\CASC;

/**
 * This patch manifest carries information on where to get instructions to patch existing files to get up-to-date files.
 */
class Patch {
    /** @var [string, int][] The last content hash and offset of each block.  */
    private $entryMap = [];

    /** @var resource The file handle for the encoding cache file. */
    private $fileHandle;

    /** @var int[] Various fields read from the header of the encoding file. */
    private $header;

    /** @var int The most number of bytes a block can take up. */
    private $maxBlockSize;

    /**
     * Fetches and parses the patch file to map content hashes to patch encoding hashes.
     *
     * @param Cache $cache A disk cache where we can find and store raw files we download.
     * @param DataSource[] $dataSources
     * @param iterable $servers Typically a HostList, or an array. CDN hostnames.
     * @param string $cdnPath A product-specific path component from the versionConfig where we get these assets.
     * @param string $hash The hex hash string for the file to read.
     *
     * @throws \Exception
     */
    public function __construct(Cache $cache, array $dataSources, iterable $servers, string $cdnPath, string $hash) {
        $cachePath = 'patch/' . $hash;

        $f = $cache->getReadHandle($cachePath);
        if (is_null($f)) {
            foreach ($dataSources as $dataSource) {
                $loc = $dataSource->findHashInIndexes(hex2bin($hash));
                if ($loc) {
                    $fullCachePath = $cache->getFullPath($cachePath);
                    if ($dataSource->extractFile($loc, $fullCachePath)) {
                        $f = $cache->getReadHandle($cachePath);
                        break;
                    } else if (file_exists($fullCachePath)) {
                        unlink($fullCachePath);
                    }
                }
            }
        }
        if (is_null($f)) {
            foreach ($servers as $server) {
                $f = $cache->getWriteHandle($cachePath);
                if (is_null($f)) {
                    throw new \Exception("Cannot create cache location for patch data\n");
                }

                $url = Util::buildTACTUrl($server, $cdnPath, 'patch', $hash);
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
                throw new \Exception("Could not fetch patch data at $url\n");
            }
        }

        if (fread($f, 2) != 'PA') {
            fclose($f);
            throw new \Exception("Patch file did not have expected header\n");
        }

        $headerFormat = [
            'Cversion',
            'CcontentHashSize',
            'CoKeySize',
            'CpatchKeySize',
            'CblockSizeBits',
            'nblockCount',
            'Cflags',
        ];
        $this->header = unpack(implode('/', $headerFormat), fread($f, 8));
        $this->maxBlockSize = 2**$this->header['blockSizeBits'];

        // Skip encoding metadata
        fseek($f, 16 + 16 + 4 + 4, SEEK_CUR);
        $especLength = unpack('C', fread($f, 1))[1];
        fseek($f, $especLength, SEEK_CUR);

        // Read the block TOC
        for ($x = 0; $x < $this->header['blockCount']; $x++) {
            $lastFileContentKey = fread($f, $this->header['contentHashSize']);
            // skip block MD5
            fseek($f, 16, SEEK_CUR);
            $blockOffset = unpack('N', fread($f, 4))[1];
            $this->entryMap[] = [$lastFileContentKey, $blockOffset];
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
     * Returns how many bytes are in the patch file for the target content hash.
     *
     * @param string $targetContentHash The content hash (in binary) we want to end up with after the patch.
     * @param ?string $sourceEncodingHash The encoding hash (in binary) of the file we're patching.
     * @return int|null
     */
    public function getPatchSize(string $targetContentHash, ?string $sourceEncodingHash): ?int {
        $fileOffset = $this->findInMap($targetContentHash);
        if ($fileOffset === null) {
            return null;
        }

        $f = $this->fileHandle;
        fseek($f, $fileOffset);

        $patchRecordSize = $this->header['contentHashSize'] + 5 + $this->header['patchKeySize'] + 4 + 1;

        $offset = 0;
        while ($offset < $this->maxBlockSize) {
            $patchCount = unpack('C', fread($f, 1))[1];
            if ($patchCount === 0) {
                break;
            }

            $targetFileContentKey = fread($f, $this->header['contentHashSize']);
            if ($targetFileContentKey !== $targetContentHash) {
                fseek($f, 5 + $patchRecordSize * $patchCount, SEEK_CUR);
                continue;
            }

            fseek($f, 5, SEEK_CUR);
            //$targetDecodedSize = unpack('J', "\0\0\0" . fread($f, 5))[1];

            for ($patchIndex = 0; $patchIndex < $patchCount; $patchIndex++) {
                $sourceFileEncodingKey = fread($f, $this->header['contentHashSize']);
                if (($sourceEncodingHash ?? $sourceFileEncodingKey) !== $sourceFileEncodingKey) {
                    fseek($f, 5 + $this->header['patchKeySize'] + 4 + 1, SEEK_CUR);
                    continue;
                }

                fseek($f, 5, SEEK_CUR);
                //$sourceFileDecodedSize = unpack('J', "\0\0\0" . fread($f, 5))[1];

                fseek($f, $this->header['patchKeySize'], SEEK_CUR);
                //$patchEncodingKey = fread($f, $this->header['patchKeySize']);

                $patchSize = unpack('N', fread($f, 4))[1];
                return $patchSize;

                //$patchOrdinal = unpack('C', fread($f, 1))[1];
            }
        }

        return null;
    }

    /**
     * Returns the file offset of the block that may contain the target content hash.
     *
     * @param string $needle A content hash.
     * @return int|null
     */
    private function findInMap(string $needle): ?int {
        $map = $this->entryMap;

        $lo = 0;
        $hi = count($map) - 1;

        $result = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            [$blockLastHash, $blockOffset] = $map[$mid];
            if (strcmp($blockLastHash, $needle) >= 0) {
                $result = $blockOffset;
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }

        return $result;
    }
}
