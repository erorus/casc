<?php

namespace Erorus\CASC\NameLookup;

use Erorus\CASC\BLTE;
use Erorus\CASC\Cache;
use Erorus\CASC\HTTP;
use Erorus\CASC\NameLookup;
use Erorus\CASC\Util;

/**
 * The Install file gives us a lookup of some filenames to content hashes. Blizzard typically uses this to bootstrap
 * (hence the name "Install"), and the names available are limited, though there are some important ones (like wow.exe).
 */
class Install extends NameLookup {
    /** @var string[] Content hashes, keyed by lowercase filename. */
    private $hashes = [];

    /**
     * @param Cache $cache A disk cache where we can find and store raw files we download.
     * @param \Iterator $servers Typically a HostList, or an array. CDN hostnames.
     * @param string $cdnPath A product-specific path component from the versionConfig where we get these assets.
     * @param string $hash The hex hash string for the file to read.
     *
     * @throws \Exception
     */
    public function __construct(Cache $cache, \Iterator $servers, string $cdnPath, string $hash) {
        $cachePath = 'data/' . $hash;

        $f = $cache->getReadHandle($cachePath);
        if (is_null($f)) {
            foreach ($servers as $server) {
                $f = $cache->getWriteHandle($cachePath, true);
                if (is_null($f)) {
                    throw new \Exception("Cannot create cache location for install data\n");
                }

                $url = Util::buildTACTUrl($server, $cdnPath, 'data', $hash);
                try {
                    $success = HTTP::get($url, $f);
                } catch (BLTE\Exception $e) {
                    $success = false;
                } catch (\Exception $e) {
                    echo " - " . $e->getMessage();
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
            $name = stream_get_line($f, 8192, "\0");
            fseek($f, 2 + ceil($header['entries'] / 8), SEEK_CUR);
        }

        for ($x = 0; $x < $header['entries']; $x++) {
            $name = stream_get_line($f, 8192, "\0");
            $hash = fread($f, $header['hashSize']);

            // Skip the file size.
            fseek($f, 4, SEEK_CUR);

            $this->hashes[strtolower($name)] = $hash;
        }

        fclose($f);
    }

    /**
     * Given the name (including any path components) of a file, return its content hash. Returns null when not found.
     *
     * @param string $name
     * @param string|null $locale The NameLookup class requires this parameter, though locales are not used here.
     *
     * @return string|null A content hash, in binary bytes (not hex).
     */
    public function getContentHash(string $name, ?string $locale = null): ?string {
        return $this->hashes[strtolower($name)] ?? null;
    }
}
