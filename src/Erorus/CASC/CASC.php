<?php

namespace Erorus\CASC;

class CASC {
    
    /** @var Cache */
    private $cache;

    /** @var Encoding */
    private $encoding;

    /** @var AbstractNameLookup[] */
    private $nameSources = [];

    /** @var AbstractDataSource[] */
    private $dataSources = [];

    private $ready = false;
    
    public function __construct($cachePath, $wowPath, $program = 'wow', $region = 'us', $locale = 'enUS') {
        if (!in_array('blte', stream_get_wrappers())) {
            stream_wrapper_register('blte', BLTE::class);
        }

        HTTP::$writeProgressToStream = STDOUT;

        $this->cache = new Cache($cachePath);

        $ngdp = new NGDP($this->cache, $program, $region);
        $hosts = $ngdp->getHosts();
        if ( ! count($hosts) || ! $hosts[0]) {
            throw new \Exception(sprintf("No hosts returned from NGDP for program '%s' region '%s'\n", $program, $region));
        }

        echo sprintf("%s %s version %s\n", $ngdp->getRegion(), $ngdp->getProgram(), $ngdp->getVersion());

        $cdnHost = sprintf('http://%s/%s/', $hosts[0], $ngdp->getCDNPath());

        $buildConfig = new Config($this->cache, $cdnHost, $ngdp->getBuildConfig());
        if (!isset($buildConfig->encoding[1])) {
            throw new \Exception("Could not find encoding value in build config\n");
        }
        if (!isset($buildConfig->root[0])) {
            throw new \Exception("Could not find root value in build config\n");
        }
        if (!isset($buildConfig->install[0])) {
            throw new \Exception("Could not find install value in build config\n");
        }

        echo "Loading encoding..";
        $this->encoding = new Encoding($this->cache, $cdnHost, $buildConfig->encoding[1]);
        echo "\n";

        echo "Loading install..";
        $installHeader = $this->encoding->GetHeaderHash(hex2bin($buildConfig->install[0]));
        if (!$installHeader) {
            throw new \Exception("Could not find install header in Encoding\n");
        }
        $this->nameSources['Install'] = new Install($this->cache, $cdnHost, bin2hex($installHeader['headers'][0]));
        echo "\n";

        echo "Loading root..";
        $rootHeader = $this->encoding->GetHeaderHash(hex2bin($buildConfig->root[0]));
        if (!$rootHeader) {
            throw new \Exception("Could not find root header in Encoding\n");
        }
        $this->nameSources['Root'] = new Root($this->cache, $cdnHost, bin2hex($rootHeader['headers'][0]), $locale);
        echo "\n";

        $cdnConfig = new Config($this->cache, $cdnHost, $ngdp->getCDNConfig());

        if ($wowPath) {
            echo "Loading local indexes..";
            $this->dataSources['Local'] = new Index($wowPath);
            echo "\n";
        }

        echo "Loading remote indexes..";
        $this->dataSources['Remote'] = new Archive($this->cache, $cdnHost, $cdnConfig->archives, $wowPath ? $wowPath : null);
        echo "\n";

        $this->ready = true;
    }

    public function fetchFile($file, $destRoot, $locale = null) {
        if (!$this->ready) {
            return false;
        }

        $path = $destRoot;
        $sep = DIRECTORY_SEPARATOR;
        if (substr($path, -1 * strlen($sep)) != $sep) {
            $path .= $sep;
        }
        $path .= str_replace('\\', DIRECTORY_SEPARATOR, $file);

        $contentHash = false;
        foreach ($this->nameSources as $nameSourceName => $nameSource) {
            if ($contentHash = $nameSource->GetContentHash($file, $locale)) {
                break;
            }
        }
        if (!$contentHash) {
            return false;
        }

        //echo sprintf("Searching for content %s in encoding\n", bin2hex($contentHash));
        $headerHashes = $this->encoding->GetHeaderHash($contentHash);
        if (!$headerHashes) {
            //echo "Header hash for content ", bin2hex($contentHash), " not found\n";
            return false;
        }

        foreach ($this->dataSources as $dataSourceName => $dataSource) {
            foreach ($headerHashes['headers'] as $hash) {
                try {
                    if ($location = $dataSource->findHashInIndexes($hash)) {
                        if ($dataSource->extractFile($location, $path, $contentHash)) {
                            return $dataSourceName;
                        }
                    }
                } catch (\Exception $e) {
                    echo sprintf(" - %s ", trim($e->getMessage()));
                }
            }
        }

        return false;
    }

    public static function assertParentDir($fullPath, $type) {
        $parentDir = dirname($fullPath);
        if (!is_dir($parentDir)) {
            if (!mkdir($parentDir, 0755, true)) {
                fwrite(STDERR, "Cannot create $type dir $parentDir\n");
                return false;
            }
        } elseif (!is_writable($parentDir)) {
            fwrite(STDERR, "Cannot write in $type dir $parentDir\n");
            return false;
        }

        return true;
    }
}
