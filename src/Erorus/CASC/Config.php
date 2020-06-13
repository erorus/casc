<?php

namespace Erorus\CASC;

class Config {

    private $props = [];

    public function __construct(Cache $cache, $hosts, $cdnPath, $hash) {
        $cachePath = 'config/' . $hash;

        $data = $cache->read($cachePath);
        if (is_null($data)) {
            foreach ($hosts as $host) {
                $url = sprintf('http://%s/%s/config/%s/%s/%s', $host, $cdnPath, substr($hash, 0, 2),
                    substr($hash, 2, 2), $hash);
                $data = HTTP::Get($url);

                if ( ! $data) {
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

    public function __get($nm) {
        return $this->props[$nm] ?? [];
    }

}
