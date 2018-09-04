<?php

namespace Erorus\CASC;

class NGDP extends AbstractVersionConfig {

    const NGDP_HOST = 'http://us.patch.battle.net:1119/';

    protected function getNGDPData($file) {
        $cachePath = 'ngdp/' . $this->getProgram() . '/' . $file;

        $data = $this->getCachedResponse($cachePath, static::MAX_CACHE_AGE);
        if (!$data) {
            $url  = sprintf('%s%s/%s', static::NGDP_HOST, $this->getProgram(), $file);
            $data = HTTP::Get($url);
            if (!$data) {
                $data = $this->getCachedResponse($cachePath);
            } else {
                $this->cache->writePath($cachePath, $data);
            }
        }

        return $data;
    }
}
