<?php

namespace Erorus\CASC;

class Install extends AbstractNameLookup
{
    private $hashes = [];

    public function __construct(Cache $cache, $hosts, $cdnPath, $hash)
    {
        $cachePath = 'data/' . $hash;

        $f = $cache->getReadHandle($cachePath);
        if ($f === false) {
            foreach ($hosts as $host) {
                $f = $cache->getWriteHandle($cachePath, true);
                if ($f === false) {
                    throw new \Exception("Cannot create cache location for install data\n");
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
                throw new \Exception("Could not fetch install data at $url\n");
            }
        }

        $magic = fread($f, 2);
        if ($magic != 'IN') {
            $cache->deletePath($cachePath);
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
