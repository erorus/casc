<?php

namespace Erorus\CASC\NameLookup;

use Erorus\CASC\BLTE;
use Erorus\CASC\Cache;
use Erorus\CASC\HTTP;
use Erorus\CASC\NameLookup;
use Erorus\CASC\Util;

class Install extends NameLookup {
    private $hashes = [];

    public function __construct(Cache $cache, $hosts, $cdnPath, $hash)
    {
        $cachePath = 'data/' . $hash;

        $f = $cache->getReadHandle($cachePath);
        if (is_null($f)) {
            foreach ($hosts as $host) {
                $f = $cache->getWriteHandle($cachePath, true);
                if (is_null($f)) {
                    throw new \Exception("Cannot create cache location for install data\n");
                }

                $url = Util::buildTACTUrl($host, $cdnPath, 'data', $hash);
                try {
                    $success = HTTP::Get($url, $f);
                } catch (BLTE\Exception $e) {
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
                throw new \Exception("Could not fetch install data at $url\n");
            }
        }

        $magic = fread($f, 2);
        if ($magic != 'IN') {
            $cache->delete($cachePath);
            throw new \Exception("Install file did not have expected magic signature IN\n");
        }
        $header = unpack('Cunk/ChashSize/ntags/Nentries', fread($f, 8));

        for ($x = 0; $x < $header['tags']; $x++) {
            $name = stream_get_line($f, 8192, "\x00");
            fseek($f, 2 + ceil($header['entries'] / 8), SEEK_CUR);
        }

        for ($x = 0; $x < $header['entries']; $x++) {
            $name = stream_get_line($f, 8192, "\x00");
            $hash = fread($f, $header['hashSize']);
            fseek($f, 4, SEEK_CUR); //$size = current(unpack('N', fread($f, 4)));

            $this->hashes[strtolower($name)] = $hash;
        }

        fclose($f);
    }

    public function GetContentHash($db2OrId, $locale = null)
    {
        return $this->hashes[strtolower($db2OrId)] ?? false;
    }
}
