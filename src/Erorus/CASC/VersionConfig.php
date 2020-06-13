<?php

namespace Erorus\CASC;

abstract class VersionConfig {
    const MAX_CACHE_AGE = 3600; // 1 hour

    private $region;
    private $program;

    private $hosts = [];
    private $cdnPath = '';
    private $configPath = '';

    private $buildConfig = '';
    private $cdnConfig = '';
    private $build = '';
    private $version = '';

    protected $cache;

    public function __construct(Cache $cache, $program='wow', $region='us') {
        $this->cache = $cache;

        $this->program = strtolower($program);
        $this->region = strtolower($region);
    }

    public function getProgram() {
        return $this->program;
    }

    public function getRegion() {
        return strtoupper($this->region);
    }

    public function getHosts() {
        if (!$this->hosts) {
            $this->getCDNs();
        }

        return $this->hosts;
    }

    public function getCDNPath() {
        if (!$this->cdnPath) {
            $this->getCDNs();
        }

        return $this->cdnPath;
    }

    public function getConfigPath() {
        if (!$this->configPath) {
            $this->getCDNs();
        }

        return $this->configPath;
    }

    public function getBuildConfig() {
        if (!$this->buildConfig) {
            $this->getVersions();
        }

        return $this->buildConfig;
    }

    public function getCDNConfig() {
        if (!$this->cdnConfig) {
            $this->getVersions();
        }

        return $this->cdnConfig;
    }

    public function getBuild() {
        if (!$this->build) {
            $this->getVersions();
        }

        return $this->build;
    }

    public function getVersion() {
        if (!$this->version) {
            $this->getVersions();
        }

        return $this->version;
    }

    protected function getCachedResponse($cachePath, $maxAge = false) {
        if (!$this->cache->fileExists($cachePath)) {
            return false;
        }
        if ($maxAge && $this->cache->fileModified($cachePath) < (time() - $maxAge)) {
            return false;
        }
        return $this->cache->readPath($cachePath);
    }

    abstract protected function getTACTData($file);

    private function getCDNs()
    {
        $data = $this->getTACTData('cdns');
        if ( ! $data) {
            return;
        }

        $lines = preg_split('/[\r\n]+/', $data);
        if (strpos($lines[0], '|') === false) {
            return;
        }
        $cols = explode('|', strtolower($lines[0]));
        $names = [];
        foreach ($cols as $col) {
            $name = $col;
            if (($pos = strpos($name, '!')) !== false) {
                $name = substr($name, 0, $pos);
            }
            $names[] = $name;
        }

        for ($x = 1; $x < count($lines); $x++) {
            $vals = explode('|', $lines[$x]);
            if (count($vals) != count($names)) {
                continue;
            }
            $row = array_combine($names, $vals);
            if (!isset($row['name'])) {
                continue;
            }
            if ($row['name'] != $this->region) {
                continue;
            }
            if (isset($row['path'])) {
                $this->cdnPath = $row['path'];
            }
            if (isset($row['hosts'])) {
                $this->hosts = new HostList(explode(' ', $row['hosts']));
            }
            if (isset($row['configpath'])) {
                $this->configPath = $row['configpath'];
            }
            break;
        }
    }

    private function getVersions()
    {
        $data = $this->getTACTData('versions');
        if ( ! $data) {
            return;
        }

        $lines = preg_split('/[\r\n]+/', $data);
        if (strpos($lines[0], '|') === false) {
            return;
        }
        $cols = explode('|', strtolower($lines[0]));
        $names = [];
        foreach ($cols as $col) {
            $name = $col;
            if (($pos = strpos($name, '!')) !== false) {
                $name = substr($name, 0, $pos);
            }
            $names[] = $name;
        }

        for ($x = 1; $x < count($lines); $x++) {
            $vals = explode('|', $lines[$x]);
            if (count($vals) != count($names)) {
                continue;
            }
            $row = array_combine($names, $vals);
            if (!isset($row['region'])) {
                continue;
            }
            if ($row['region'] != $this->region) {
                continue;
            }
            if (isset($row['buildconfig'])) {
                $this->buildConfig = $row['buildconfig'];
            }
            if (isset($row['cdnconfig'])) {
                $this->cdnConfig = $row['cdnconfig'];
            }
            if (isset($row['buildid'])) {
                $this->build = $row['buildid'];
            }
            if (isset($row['versionsname'])) {
                $this->version = $row['versionsname'];
            }
            break;
        }
    }
}
