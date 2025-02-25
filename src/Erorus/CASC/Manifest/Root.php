<?php

namespace Erorus\CASC\Manifest;

use Erorus\CASC\BLTE;
use Erorus\CASC\Cache;
use Erorus\CASC\DataSource;
use Erorus\CASC\HTTP;
use Erorus\CASC\Manifest;
use Erorus\CASC\Util;
use SplFixedArray;

/**
 * This has the primary method we use to convert most file IDs into content hashes.
 */
class Root extends Manifest {
    /**
     * Maps locale names we gave to the flags that Blizzard uses.
     */
    public const LOCALE_FLAGS = [
        'enUS' => 0x2,
        'koKR' => 0x4,
        'frFR' => 0x10,
        'deDE' => 0x20,
        'zhCN' => 0x40,
        'esES' => 0x80,
        'zhTW' => 0x100,
        'enGB' => 0x200,
        //'enCN' => 0x400,
        //'enTW' => 0x800,
        'esMX' => 0x1000,
        'ruRU' => 0x2000,
        'ptBR' => 0x4000,
        'itIT' => 0x8000,
        'ptPT' => 0x10000,

        //'All'  => 0x1F3F6,
    ];

    /** @var int The max number of records to read when parsing a block. */
    private const CHUNK_RECORD_COUNT = 8192;

    /** @var int The length of content hashes: 16 bytes (md5 hash result). */
    private const CONTENT_HASH_LENGTH = 16;

    /** @var int The length of file IDs. */
    private const FILE_ID_LENGTH = 4;

    /** @var int Block flag which indicate the block has no name hashes. */
    private const FLAG_NO_NAME_HASH = 0x10000000;

    /** @var int The length of name hashes: jenkins96 hash returns 8 bytes. */
    private const NAME_HASH_LENGTH = 8;

    /** @var bool Whether this root file has records without name hashes. */
    private $allowNonNamedFiles = true;

    /** @var array[] Each record is a simple array of [$fileDataIds, $nameHashes] which we previously read from that
     *               block in another call. We cache it for quicker lookups.
     */
    private $blockCache = [];

    /** @var string The name of the locale we'll use when one isn't given for a hash lookup. */
    private $defaultLocale = '';

    /** @var resource The file handle for the root file in our cache. */
    private $fileHandle;

    /** @var int The size of the root file, in bytes. */
    private $fileSize;

    /** @var int The size of the file header, in bytes. */
    private $headerLength = 0;

    /** @var bool Whether this root file uses a legacy (pre 8.2) format with interleaved name hashes. */
    private $useOldRecordFormat = false;

    /** @var int The version of root file. */
    private $version;

    /**
     * Initializes our Root manifest by fetching (and caching) a single Root data file for this version.
     *
     * @param Cache $cache A disk cache where we can find and store raw files we download.
     * @param DataSource[] $dataSources
     * @param iterable $servers Typically a HostList, or an array. CDN hostnames.
     * @param string $cdnPath A product-specific path component from the versionConfig where we get these assets.
     * @param string $hash The hex hash string for the file to read.
     * @param string $defaultLocale One of the keys in self::LOCALE_FLAGS.
     *
     * @throws \Exception
     */
    public function __construct(
        Cache $cache,
        array $dataSources,
        iterable $servers,
        string $cdnPath,
        string $hash,
        string $defaultLocale = 'enUS'
    ) {
        if (!key_exists($defaultLocale, static::LOCALE_FLAGS)) {
            throw new \Exception("Locale $defaultLocale is not supported\n");
        }

        $this->defaultLocale = $defaultLocale;

        $cachePath = 'data/' . $hash;

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
                $f = $cache->getWriteHandle($cachePath, true);
                if (is_null($f)) {
                    throw new \Exception("Cannot create temp buffer for root data\n");
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
                throw new \Exception("Could not fetch root data at $url\n");
            }
        }

        $stat = fstat($f);
        $this->fileSize = $stat['size'];
        $this->fileHandle = $f;

        fseek($this->fileHandle, 0);
        $sig = fread($this->fileHandle, 4);
        if ($sig !== 'TSFM') {
            $this->allowNonNamedFiles = false;
            $this->useOldRecordFormat = true;
            $this->version = 0;
        } else {
            [$this->headerLength, $this->version] = array_values(unpack('l*', fread($this->fileHandle, 8)));
            switch ($this->version) {
                case 1:
                case 2:
                    [$countTotal, $countWithNameHash] = array_values(unpack('l*', fread($this->fileHandle, 8)));
                    break;

                default:
                    $countTotal         = $this->headerLength;
                    $countWithNameHash  = $this->version;
                    $this->headerLength = 12;
                    $this->version      = 0;
                    break;
            }
            $this->allowNonNamedFiles = $countTotal !== $countWithNameHash;
            $this->useOldRecordFormat = false;
        }
   }

    /**
     * Close the open file handle.
     */
    public function __destruct() {
        fclose($this->fileHandle);
    }

    /**
     * Given the name (including any path components) of a file, or the numeric file ID, return its content hash.
     * Returns null when not found.
     *
     * @param string $nameOrId You'll likely need the numeric file ID to find its content hash.
     * @param string|null $locale A key from self::LOCALE_FLAGS
     *
     * @return string|null A content hash, in binary bytes (not hex).
     */
    public function getContentHash(string $nameOrId, ?string $locale = null): ?string {
        if (is_null($locale) || !key_exists($locale, static::LOCALE_FLAGS)) {
            $locale = $this->defaultLocale;
        }
        $locale = static::LOCALE_FLAGS[$locale];

        $hashedName = static::jenkins_hashlittle2(strtoupper(str_replace('/', '\\', $nameOrId)));

        fseek($this->fileHandle, $this->headerLength);

        $blockId = -1;
        while (ftell($this->fileHandle) < $this->fileSize) {
            $blockId++;

            // Read the block header.
            if ($this->version < 2) {
                [$numRec, $flags, $blockLocale] =
                    array_values(unpack('lnumrec/Vflags/Vlocale', fread($this->fileHandle, 12)));
            } else {
                [$numRec, $blockLocale, $flags1, $flags2, $flags3] =
                    array_values(unpack(
                        'lnumrec/Vlocale/Vflags1/Vflags2/Cflags3',
                        fread($this->fileHandle, 17)
                    ));
                $flags = $flags1 | $flags2 | ($flags3 << 17);
            }

            $blockHasNameHashes = !($this->allowNonNamedFiles && ($flags & self::FLAG_NO_NAME_HASH));

            // Calculate how many bytes remain in this block, in case we need to skip past it.
            $blockDataLength = $numRec * self::FILE_ID_LENGTH + $numRec * self::CONTENT_HASH_LENGTH;
            if ($blockHasNameHashes) {
                $blockDataLength += $numRec * self::NAME_HASH_LENGTH;
            }

            if (($blockLocale & $locale) !== $locale) {
                // This block doesn't support the locale we're using. Skip it.
                fseek($this->fileHandle, $blockDataLength, SEEK_CUR);
                continue;
            }
            if (!isset($this->blockCache[$blockId])) {
                // We haven't read this block yet. Do so, and cache it.
                $fileDataIds = [];
                $nameHashes = [];

                // Read the file ID deltas.
                $deltas = SplFixedArray::fromArray(
                    unpack('i*', fread($this->fileHandle, self::FILE_ID_LENGTH * $numRec)),
                    false
                );

                if ($this->useOldRecordFormat) {
                    // Legacy format: each record is a content hash and a name hash.
                    $recLength = static::CONTENT_HASH_LENGTH + static::NAME_HASH_LENGTH;

                    $prevId = -1;
                    for ($chunkOffset = 0; $chunkOffset < $numRec; $chunkOffset += $chunkSize) {
                        $chunkSize = min(static::CHUNK_RECORD_COUNT, $numRec - $chunkOffset);

                        $data = SplFixedArray::fromArray(
                            str_split(
                                fread($this->fileHandle, $recLength * $chunkSize),
                                $recLength
                            ),
                            false
                        );
                        for ($pos = 0; $pos < $chunkSize; $pos++) {
                            [$contentKey, $nameHash] = str_split($data[$pos], 16);

                            $fileDataIds[$prevId = $deltas[$chunkOffset + $pos] + $prevId + 1] = $contentKey;
                            $nameHashes[$nameHash] = $contentKey;
                        }
                        unset($data);
                    }
                } else {
                    // Modern format: a list of content hashes, and then a list of name hashes (if flagged with such).
                    $prevId = -1;
                    for ($chunkOffset = 0; $chunkOffset < $numRec; $chunkOffset += $chunkSize) {
                        $chunkSize = min(static::CHUNK_RECORD_COUNT, $numRec - $chunkOffset);

                        $data = SplFixedArray::fromArray(
                            str_split(
                                fread($this->fileHandle, self::CONTENT_HASH_LENGTH * $chunkSize),
                                self::CONTENT_HASH_LENGTH
                            ),
                            false
                        );
                        for ($pos = 0; $pos < $chunkSize; $pos++) {
                            $contentKey = $data[$pos];
                            $fileDataIds[$prevId = $deltas[$chunkOffset + $pos] + $prevId + 1] = $contentKey;
                        }
                        unset($data);
                    }

                    if ($blockHasNameHashes) {
                        $prevId = -1;
                        for ($chunkOffset = 0; $chunkOffset < $numRec; $chunkOffset += $chunkSize) {
                            $chunkSize = min(static::CHUNK_RECORD_COUNT, $numRec - $chunkOffset);

                            $data = SplFixedArray::fromArray(
                                str_split(
                                    fread($this->fileHandle, self::NAME_HASH_LENGTH * $chunkSize),
                                    self::NAME_HASH_LENGTH
                                ),
                                false
                            );
                            for ($pos = 0; $pos < $chunkSize; $pos++) {
                                $nameHash = $data[$pos];
                                $nameHashes[$nameHash] = $fileDataIds[$prevId = $deltas[$chunkOffset + $pos] + $prevId + 1];
                            }
                            unset($data);
                        }
                    }
                }

                unset($deltas);
                $this->blockCache[$blockId] = [$fileDataIds, $nameHashes];
            } else {
                // We can get the data from our block cache. Do that, and skip ahead to the next block.
                [$fileDataIds, $nameHashes] = $this->blockCache[$blockId];
                fseek($this->fileHandle, $blockDataLength, SEEK_CUR);
            }

            if (isset($fileDataIds[$nameOrId])) {
                return $fileDataIds[$nameOrId];
            }

            if (isset($nameHashes[$hashedName])) {
                return $nameHashes[$hashedName];
            }
        }

        return null;
    }

    /**
     * Hashes some text with an algorithm used by Blizzard to hash file names.
     *
     * @param string $txt
     *
     * @return string
     */
    private static function jenkins_hashlittle2(string $txt): string {
        $Rot = function($x,$k) {
            return 0xFFFFFFFF & ((($x)<<($k)) | (($x)>>(32-($k))));
        };

        $Mix = function(&$a,&$b,&$c) use ($Rot) {
            $a = 0xFFFFFFFF & ($a - $c);  $a ^= $Rot($c, 4);  $c = 0xFFFFFFFF & ($c + $b);
            $b = 0xFFFFFFFF & ($b - $a);  $b ^= $Rot($a, 6);  $a = 0xFFFFFFFF & ($a + $c);
            $c = 0xFFFFFFFF & ($c - $b);  $c ^= $Rot($b, 8);  $b = 0xFFFFFFFF & ($b + $a);
            $a = 0xFFFFFFFF & ($a - $c);  $a ^= $Rot($c,16);  $c = 0xFFFFFFFF & ($c + $b);
            $b = 0xFFFFFFFF & ($b - $a);  $b ^= $Rot($a,19);  $a = 0xFFFFFFFF & ($a + $c);
            $c = 0xFFFFFFFF & ($c - $b);  $c ^= $Rot($b, 4);  $b = 0xFFFFFFFF & ($b + $a);
        };

        $Final = function(&$a,&$b,&$c) use ($Rot) {
            $c ^= $b; $c = 0xFFFFFFFF & ($c - $Rot($b,14));
            $a ^= $c; $a = 0xFFFFFFFF & ($a - $Rot($c,11));
            $b ^= $a; $b = 0xFFFFFFFF & ($b - $Rot($a,25));
            $c ^= $b; $c = 0xFFFFFFFF & ($c - $Rot($b,16));
            $a ^= $c; $a = 0xFFFFFFFF & ($a - $Rot($c,4));
            $b ^= $a; $b = 0xFFFFFFFF & ($b - $Rot($a,14));
            $c ^= $b; $c = 0xFFFFFFFF & ($c - $Rot($b,24));
        };

        $Ret = function($c, $b) {
            $c = dechex($c);
            $b = dechex($b);
            return implode('', array_reverse(str_split(hex2bin(str_pad($c, 8, '0', STR_PAD_LEFT) . str_pad($b, 8, '0', STR_PAD_LEFT)))));
        };

        $a = $b = $c = 0xdeadbeef + strlen($txt);

        $pos = 0;
        $length = strlen($txt);
        while ($length > 12) {
            $vals = unpack('V*', substr($txt, $pos, 12));
            $pos += 12;

            $a = 0xFFFFFFFF & ($a + $vals[1]);
            $b = 0xFFFFFFFF & ($b + $vals[2]);
            $c = 0xFFFFFFFF & ($c + $vals[3]);

            $Mix($a,$b,$c);
            $length -= 12;
        }

        $last = substr($txt, $pos);
        $leftover = (strlen($last) % 4);
        if ($leftover != 0) {
            $last .= str_repeat(chr(0), 4 - $leftover);
        }
        $k = array_values(unpack('L*', $last));

        switch($length)
        {
            case 12: $c=0xFFFFFFFF & ($c+$k[2]); $b=0xFFFFFFFF & ($b+$k[1]); $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 11: $c=0xFFFFFFFF & ($c+($k[2]&0xffffff)); $b=0xFFFFFFFF & ($b+$k[1]); $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 10: $c=0xFFFFFFFF & ($c+($k[2]&0xffff)); $b=0xFFFFFFFF & ($b+$k[1]); $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 9 : $c=0xFFFFFFFF & ($c+($k[2]&0xff)); $b=0xFFFFFFFF & ($b+$k[1]); $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 8 : $b=0xFFFFFFFF & ($b+$k[1]); $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 7 : $b=0xFFFFFFFF & ($b+($k[1]&0xffffff)); $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 6 : $b=0xFFFFFFFF & ($b+($k[1]&0xffff)); $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 5 : $b=0xFFFFFFFF & ($b+($k[1]&0xff)); $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 4 : $a=0xFFFFFFFF & ($a+$k[0]); break;
            case 3 : $a=0xFFFFFFFF & ($a+($k[0]&0xffffff)); break;
            case 2 : $a=0xFFFFFFFF & ($a+($k[0]&0xffff)); break;
            case 1 : $a=0xFFFFFFFF & ($a+($k[0]&0xff)); break;
            case 0 : return $Ret($c,$b);  /* zero length strings require no mixing */
        }

        $Final($a,$b,$c);
        return $Ret($c, $b);
    }
}
