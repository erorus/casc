<?php

namespace Erorus\CASC;

class Config {

    private $props = [];

    public function __construct(Cache $cache, $hostPath, $hash) {
        $cachePath = 'config/' . $hash;

        $data = $cache->readPath($cachePath);
        if ($data === false) {
            $url = sprintf('%sconfig/%s/%s/%s', $hostPath, substr($hash, 0, 2), substr($hash, 2, 2), $hash);
            $data = HTTP::Get($url);

            $f = $cache->getWriteHandle($cachePath);
            if ($f !== false) {
                fwrite($f, $data);
                fclose($f);
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

    public function __get($nm) {
        return $this->props[$nm] ?? [];
    }

}