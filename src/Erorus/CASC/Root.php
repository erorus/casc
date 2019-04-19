<?php

namespace Erorus\CASC;

class Root extends AbstractNameLookup
{
    const LOCALE_FLAGS = [
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

    const CHUNK_RECORD_COUNT = 8192;

    private $defaultLocale = '';

    private $blockCache = [];

    private $fileHandle;
    private $fileSize;

    public function __construct(Cache $cache, $hosts, $cdnPath, $hash, $defaultLocale = 'enUS')
    {
        if (!key_exists($defaultLocale, static::LOCALE_FLAGS)) {
            throw new \Exception("Locale $defaultLocale is not supported\n");
        }

        $this->defaultLocale = $defaultLocale;

        $cachePath = 'data/' . $hash;

        $f = $cache->getReadHandle($cachePath);
        if ($f === false) {
            foreach ($hosts as $host) {
                $f = $cache->getWriteHandle($cachePath, true);
                if ($f === false) {
                    throw new \Exception("Cannot create temp buffer for root data\n");
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
                    $cache->deletePath($cachePath);
                    continue;
                }
                fclose($f);
                $f = $cache->getReadHandle($cachePath);
                break;
            }
            if ( ! $success) {
                throw new \Exception("Could not fetch root data at $url\n");
            }
        }

        $stat = fstat($f);
        $this->fileSize = $stat['size'];
        $this->fileHandle = $f;
    }

    public function __destruct()
    {
        fclose($this->fileHandle);
    }

    public function GetContentHash($db2OrId, $locale) {
        if (is_null($locale) || !key_exists($locale, static::LOCALE_FLAGS)) {
            $locale = $this->defaultLocale;
        }
        $locale = static::LOCALE_FLAGS[$locale];

        $blockId = -1;

        fseek($this->fileHandle, 0);
        $sig = fread($this->fileHandle, 4);
        if ($sig !== 'TSFM') {
            throw new \Exception("Unknown root file signature: " . bin2hex($sig));
        }

        $totalNameRecs = 0;

        while (ftell($this->fileHandle) < $this->fileSize) {
            list($totalRecs, $nameRecs) = array_values(unpack('l*', fread($this->fileHandle, 8)));
            $contentRecs = $totalRecs - $nameRecs;
            $totalNameRecs += $nameRecs;

            $runningTotal = 0;
            while ($runningTotal < $contentRecs) {
                $blockId++;

                list($numRec, $flags, $blockLocale) = array_values(unpack('lnumrec/Vflags/Vlocale',
                    fread($this->fileHandle, 12)));
                $runningTotal += $numRec;

                if (($blockLocale & $locale) != $locale) {
                    fseek($this->fileHandle, $numRec * 20, SEEK_CUR);
                    continue;
                }
                if ( ! isset($this->blockCache[$blockId])) {
                    $fileDataIds = [];

                    $deltas = \SplFixedArray::fromArray(unpack('i*', fread($this->fileHandle, 4 * $numRec)), false);
                    $prevId = -1;

                    for ($chunkOffset = 0; $chunkOffset < $numRec; $chunkOffset += $chunkSize) {
                        $chunkSize = min(static::CHUNK_RECORD_COUNT, $numRec - $chunkOffset);

                        $data = \SplFixedArray::fromArray(str_split(fread($this->fileHandle, 16 * $chunkSize), 16),
                            false);
                        for ($pos = 0; $pos < $chunkSize; $pos++) {
                            $contentKey = $data[$pos];

                            $fileDataIds[$prevId = $deltas[$chunkOffset + $pos] + $prevId + 1] = $contentKey;
                        }
                        unset($data);
                    }
                    unset($deltas);
                    $this->blockCache[$blockId] = $fileDataIds;
                } else {
                    $fileDataIds = $this->blockCache[$blockId];
                    fseek($this->fileHandle, $numRec * 20, SEEK_CUR);
                }

                if (isset($fileDataIds[$db2OrId])) {
                    return $fileDataIds[$db2OrId];
                }
            }

            // TODO: whatever name hashes we have left
            break;
        }

        return false;
    }

    public static function jenkins_hashlittle2($txt) {
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
