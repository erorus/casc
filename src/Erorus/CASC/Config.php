<?php

namespace Erorus\CASC;

/**
 * Blizzard's TACT config files (Build and CDN) follow a common format, both in their URLs and their contents. This
 * fetches those config files and provides a common interface for reading their data.
 */
class Config {
    /** @var array[] Keyed by property name, the data strings for each.  */
    private $props = [];

    /**
     * @param Cache $cache A disk cache where we can find and store raw configs we download.
     * @param \Iterator $hosts Typically a HostList, or an array. CDN hostnames.
     * @param string $cdnPath A product-specific path component from the versionConfig where we get these assets
     * @param string $hash The hex hash string for the file to read.
     *
     * @throws \Exception
     */
    public function __construct(Cache $cache, \Iterator $hosts, string $cdnPath, string $hash) {
        $cachePath = 'config/' . $hash;

        $data = $cache->read($cachePath);
        if (is_null($data)) {
            // Fetch it and cache it.
            foreach ($hosts as $host) {
                $url = Util::buildTACTUrl($host, $cdnPath, 'config', $hash);
                $data = HTTP::get($url);

                if (!$data) {
                    continue;
                }

                $f = $cache->getWriteHandle($cachePath);
                if (!is_null($f)) {
                    fwrite($f, $data);
                    fclose($f);
                }
                break;
            }

            if (!$data) {
                throw new \Exception("Could not fetch config at $url\n");
            }
        }

        $lines = preg_split('/[\r\n]+/', $data);
        foreach ($lines as $line) {
            $line = preg_replace('/#[\w\W]*/', '', $line);
            if (!preg_match('/^\s*([^ ]+)\s*=\s*([\w\W]+)/', $line, $res)) {
                continue;
            }
            $this->props[$res[1]] = explode(' ', $res[2]);
        }
    }

    /**
     * Returns all the values under a property name.
     *
     * @param string $name The property name.
     *
     * @return string[]
     */
    public function __get(string $name): array {
        return $this->props[$name] ?? [];
    }
}
