<?php

namespace Erorus\CASC\VersionConfig;

use Erorus\CASC\HTTP as CASCHTTP;
use Erorus\CASC\VersionConfig;

class Ribbit extends VersionConfig {

    private const URL_PREFIX = 'https://us.version.battle.net/v2';

    /**
     * Returns the content of a version config file at the given path, either from cache or by fetching it directly.
     *
     * @param string $file A product info file name, like "cdns" or "versions"
     *
     * @return string|null
     */
    protected function getTACTData(string $file): ?string {
        $cachePath = 'ribbit-v2/' . $this->getProgram() . '/' . $file;

        $data = $this->getCachedResponse($cachePath, static::MAX_CACHE_AGE);
        if (!$data) {
            $url = self::URL_PREFIX . sprintf("/products/%s/%s", $this->getProgram(), $file);
            try {
                $data = CASCHTTP::get($url);
            } catch (\Exception $e) {
                echo "\n - " . $e->getMessage() . " ";
                $data = '';
            }
        }

        if ($data) {
            $this->cache->write($cachePath, $data);
        } else {
            $data = $this->getCachedResponse($cachePath);
        }

        return $data;
    }
}
