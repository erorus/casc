<?php

namespace Erorus\CASC;

class Root
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
        'enCN' => 0x400,
        'enTW' => 0x800,
        'esMX' => 0x1000,
        'ruRU' => 0x2000,
        'ptBR' => 0x4000,
        'itIT' => 0x8000,
        'ptPT' => 0x10000,
    ];

    private $defaultLocale = '';

    private $blockCache = [];

    private $fileHandle;
    private $fileSize;

    public function __construct(Cache $cache, $hostPath, $hash, $defaultLocale = 'enUS')
    {
        if (!key_exists($defaultLocale, static::LOCALE_FLAGS)) {
            throw new \Exception("Locale $defaultLocale is not supported\n");
        }

        $this->defaultLocale = $defaultLocale;

        $cachePath = 'data/' . $hash;

        $f = $cache->getReadHandle($cachePath);
        if ($f === false) {
            $f = $cache->getWriteHandle($cachePath, true);
            if ($f === false) {
                throw new \Exception("Cannot create temp buffer for root data\n");
            }

            $url = sprintf('%sdata/%s/%s/%s', $hostPath, substr($hash, 0, 2), substr($hash, 2, 2), $hash);
            $success = HTTP::Get($url, $f);
            if (!$success) {
                fclose($f);
                $cache->deletePath($cachePath);
                throw new \Exception("Could not fetch root data at $url\n");
            }
            fclose($f);
            $f = $cache->getReadHandle($cachePath);
        }

        $stat = fstat($f);
        $this->fileSize = $stat['size'];
        $this->fileHandle = $f;
    }

    public function __destruct()
    {
        fclose($this->fileHandle);
    }

    public function GetContentHash($db2OrId, $locale = null)
    {
        if (is_null($locale) || !key_exists($locale, static::LOCALE_FLAGS)) {
            $locale = $this->defaultLocale;
        }
        $locale = static::LOCALE_FLAGS[$locale];

        $hashedName = static::jenkins_hashlittle2(strtoupper($db2OrId));

        fseek($this->fileHandle, 0);
        $blockId = -1;

        while (ftell($this->fileHandle) < $this->fileSize) {
            $blockId++;

            list($numRec, $flags, $blockLocale) = array_values(unpack('lnumrec/Vflags/Vlocale', fread($this->fileHandle, 12)));
            if (($blockLocale & $locale) != $locale) {
                fseek($this->fileHandle, $numRec * 28, SEEK_CUR);
                continue;
            }
            if (!isset($this->blockCache[$blockId])) {
                $fileDataIds = [];
                $records = [];

                $deltas = array_values(unpack('i*', fread($this->fileHandle, 4 * $numRec)));
                $prevId = -1;
                for ($x = 0; $x < $numRec; $x++) {
                    $contentKey = fread($this->fileHandle, 16);
                    $nameHash = fread($this->fileHandle, 8);

                    $prevId = $fileDataId = $deltas[$x] + $prevId + 1;

                    $fileDataIds[$fileDataId] = $nameHash;
                    $records[$nameHash] = $contentKey;
                }
                unset($deltas);
                $this->blockCache[$blockId] = [$fileDataIds, $records];
            } else {
                list($fileDataIds, $records) = $this->blockCache[$blockId];
                fseek($this->fileHandle, $numRec * 28, SEEK_CUR);
            }

            if (isset($fileDataIds[$db2OrId])) {
                $hash = $fileDataIds[$db2OrId];
            } else {
                $hash = $hashedName;
            }

            if (isset($records[$hash])) {
                return $records[$hash];
            }
        }

        return false;
    }

    public static function jenkins_hashlittle2($txt) {
        $Crop = function($x) {
            return current(unpack('L', pack('L', $x)));
        };

        $Rot = function($x,$k) use ($Crop) {
            return $Crop((($x)<<($k)) | (($x)>>(32-($k))));
        };

        $Mix = function(&$a,&$b,&$c) use ($Rot, $Crop) {
            $a = $Crop($a - $c);  $a ^= $Rot($c, 4);  $c = $Crop($c + $b);
            $b = $Crop($b - $a);  $b ^= $Rot($a, 6);  $a = $Crop($a + $c);
            $c = $Crop($c - $b);  $c ^= $Rot($b, 8);  $b = $Crop($b + $a);
            $a = $Crop($a - $c);  $a ^= $Rot($c,16);  $c = $Crop($c + $b);
            $b = $Crop($b - $a);  $b ^= $Rot($a,19);  $a = $Crop($a + $c);
            $c = $Crop($c - $b);  $c ^= $Rot($b, 4);  $b = $Crop($b + $a);
        };

        $Final = function(&$a,&$b,&$c) use ($Rot, $Crop) {
            $c ^= $b; $c = $Crop($c - $Rot($b,14));
            $a ^= $c; $a = $Crop($a - $Rot($c,11));
            $b ^= $a; $b = $Crop($b - $Rot($a,25));
            $c ^= $b; $c = $Crop($c - $Rot($b,16));
            $a ^= $c; $a = $Crop($a - $Rot($c,4));
            $b ^= $a; $b = $Crop($b - $Rot($a,14));
            $c ^= $b; $c = $Crop($c - $Rot($b,24));
        };

        $Ret = function($c, $b) use ($Crop) {
            $c = dechex($c);
            $b = dechex($b);
            return implode('', array_reverse(str_split(hex2bin(str_pad($c, 8, '0', STR_PAD_LEFT) . str_pad($b, 8, '0', STR_PAD_LEFT)))));
        };

        $a = $b = $c = 0xdeadbeef + strlen($txt);

        $pos = 0;
        $length = strlen($txt);
        while ($length > 12) {
            $a = $Crop($a + current(unpack('L', substr($txt, $pos, 4)))); $pos += 4;
            $b = $Crop($b + current(unpack('L', substr($txt, $pos, 4)))); $pos += 4;
            $c = $Crop($c + current(unpack('L', substr($txt, $pos, 4)))); $pos += 4;
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
            case 12: $c=$Crop($c+$k[2]); $b=$Crop($b+$k[1]); $a=$Crop($a+$k[0]); break;
            case 11: $c=$Crop($c+($k[2]&0xffffff)); $b=$Crop($b+$k[1]); $a=$Crop($a+$k[0]); break;
            case 10: $c=$Crop($c+($k[2]&0xffff)); $b=$Crop($b+$k[1]); $a=$Crop($a+$k[0]); break;
            case 9 : $c=$Crop($c+($k[2]&0xff)); $b=$Crop($b+$k[1]); $a=$Crop($a+$k[0]); break;
            case 8 : $b=$Crop($b+$k[1]); $a=$Crop($a+$k[0]); break;
            case 7 : $b=$Crop($b+($k[1]&0xffffff)); $a=$Crop($a+$k[0]); break;
            case 6 : $b=$Crop($b+($k[1]&0xffff)); $a=$Crop($a+$k[0]); break;
            case 5 : $b=$Crop($b+($k[1]&0xff)); $a=$Crop($a+$k[0]); break;
            case 4 : $a=$Crop($a+$k[0]); break;
            case 3 : $a=$Crop($a+($k[0]&0xffffff)); break;
            case 2 : $a=$Crop($a+($k[0]&0xffff)); break;
            case 1 : $a=$Crop($a+($k[0]&0xff)); break;
            case 0 : return $Ret($c,$b);  /* zero length strings require no mixing */
        }

        $Final($a,$b,$c);
        return $Ret($c, $b);
    }

}