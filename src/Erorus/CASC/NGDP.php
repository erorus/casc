<?php

namespace Erorus\CASC;

use Erorus\CASC\DataSource\CASC;
use Erorus\CASC\DataSource\TACT;
use Erorus\CASC\NameLookup\Install;
use Erorus\CASC\NameLookup\Root;
use Erorus\CASC\VersionConfig\HTTP as HTTPVersionConfig;
use Erorus\CASC\VersionConfig\Ribbit;
use Erorus\DB2\Reader;

/**
 * The main entry point of this library, you instantiate an NGDP object to extract files from CASC/TACT.
 */
class NGDP {
    /** @var Cache Our class to manage our filesystem cache, shared by all parts of this app. */
    private $cache;

    /** @var DataSource[] Where we convert encoding hashes to actual file data. Step 3/3. */
    private $dataSources = [];

    /** @var Encoding Where we convert content hashes into encoding hashes. Step 2/3. */
    private $encoding;

    /** @var NameLookup[] Where we convert file names and IDs into content hashes. Step 1/3. */
    private $nameSources = [];

    public function __construct($cachePath, $wowPath, $program = 'wow', $region = 'us', $locale = 'enUS') {
        if (PHP_INT_MAX < 8589934590) {
            throw new \Exception("Requires 64-bit PHP");
        }
        if (!in_array('blte', stream_get_wrappers())) {
            stream_wrapper_register('blte', BLTE::class);
        }

        HTTP::$writeProgressToStream = STDOUT;

        $this->cache = new Cache($cachePath);

        // Step 0: Download the latest version config, for CDN hostnames and pointers to this version's other configs.

        $versionConfig = new HTTPVersionConfig($this->cache, $program, $region);
        $hosts = $versionConfig->getHosts();

        $ribbit = new Ribbit($this->cache, $program, $region);
        if (count($ribbit->getHosts()) >= count($hosts)) {
            // We prefer Ribbit results, as long as it has at least as many hostnames.
            $versionConfig = $ribbit;
            $hosts = $ribbit->getHosts();
        }

        if (!count($hosts)) {
            throw new \Exception(sprintf("No hosts returned from NGDP for program '%s' region '%s'\n", $program, $region));
        }

        echo sprintf("%s %s version %s\n", $versionConfig->getRegion(), $versionConfig->getProgram(), $versionConfig->getVersion());

        // Step 1: Download the build config.

        $buildConfig = new Config($this->cache, $hosts, $versionConfig->getCDNPath(), $versionConfig->getBuildConfig());
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
        $this->encoding = new Encoding($this->cache, $hosts, $versionConfig->getCDNPath(), $buildConfig->encoding[1]);
        echo "\n";

        echo "Loading install..";
        $installContentMap = $this->encoding->getContentMap(hex2bin($buildConfig->install[0]));
        if (!$installContentMap) {
            throw new \Exception("Could not find install header in Encoding\n");
        }
        $this->nameSources['Install'] = new Install($this->cache, $hosts, $versionConfig->getCDNPath(), bin2hex($installContentMap->getEncodedHashes()[0]));
        echo "\n";

        echo "Loading root..";
        $rootContentMap = $this->encoding->getContentMap(hex2bin($buildConfig->root[0]));
        if (!$rootContentMap) {
            throw new \Exception("Could not find root header in Encoding\n");
        }
        $this->nameSources['Root'] = new Root($this->cache, $hosts, $versionConfig->getCDNPath(), bin2hex($rootContentMap->getEncodedHashes()[0]), $locale);
        echo "\n";

        $cdnConfig = new Config($this->cache, $hosts, $versionConfig->getCDNPath(), $versionConfig->getCDNConfig());

        if ($wowPath) {
            echo "Loading local indexes..";
            $this->dataSources['Local'] = new CASC($wowPath);
            echo "\n";
        }

        echo "Loading remote indexes..";
        $this->dataSources['Remote'] = new TACT($this->cache, $hosts, $versionConfig->getCDNPath(), $cdnConfig->archives, $wowPath ? $wowPath : null);
        echo "\n";

        try {
            $this->fetchTactKey();
        } catch (\Exception $e) {
            echo " Failed: ", $e->getMessage(), "\n";
        }
    }

    /**
     * Extracts a file.
     *
     * @param string $sourceId Ideally a numeric file ID, also supports some filenames (e.g. from Install)
     * @param string $destPath The filesystem path where to save the file. If it exists and matches our content hash,
     *                         we can skip downloading/extracting it.
     * @param string|null $locale The locale to use. Null to use the default locale.
     *
     * @return null|string Null on any failure. A string naming the data source on success. When
     *                     DataSource::ignoreErrors is true, this will still return null even if errors were ignored and
     *                     the file was updated.
     */
    public function fetchFile(string $sourceId, string $destPath, ?string $locale = null): ?string {
        $sourceId = strtr($sourceId, ['/' => '\\']);
        $contentHash = $this->getContentHash($sourceId, $locale);
        if (is_null($contentHash)) {
            return null;
        }
        if (file_exists($destPath) && md5_file($destPath, true) === $contentHash) {
            return 'Already Exists';
        }

        return $this->fetchContentHash($contentHash, $destPath);
    }

    /**
     * Downloads the tactKey DB2 files and adds their keys to our list of known encryption keys.
     */
    private function fetchTactKey(): void {
        echo "Loading encryption keys..";

        $files = [
            'tactKey' => 1302850,
            'tactKeyLookup' => 1302851,
        ];

        /**
         * Converts an array of numbers into a string of ascii characters.
         *
         * @param int[] $bytes
         * @return string
         */
        $byteArrayToString = function (array $bytes): string {
            $str = '';
            for ($x = 0; $x < count($bytes); $x++) {
                $str .= chr($bytes[$x]);
            }

            return $str;
        };

        /** @var Reader[] $db2s */
        $db2s = [];

        foreach ($files as $id => $path) {
            $contentHash = $this->nameSources['Root']->getContentHash($path, null);
            if (!$contentHash) {
                throw new \Exception("Could not find $id file");
            }

            $cachePath = 'keys/' . bin2hex($contentHash);
            $fullCachePath = $this->cache->getFullPath($cachePath);
            if (!$this->cache->fileExists($cachePath)) {
                $success = $this->fetchContentHash($contentHash, $fullCachePath);
                if (!$success) {
                    $this->cache->delete($cachePath);

                    throw new \Exception("Could not fetch $id file");
                }
            }

            try {
                $db2s[$id] = new Reader($fullCachePath);
            } catch (\Exception $e) {
                $this->cache->delete($cachePath);

                throw new \Exception("Could not open $id file: " . $e->getMessage());
            }
        }

        $keys = [];
        foreach ($db2s['tactKeyLookup']->generateRecords() as $id => $lookupRec) {
            $keyRec = $db2s['tactKey']->getRecord($id);
            if ($keyRec) {
                $keys[$byteArrayToString($lookupRec[0])] = $byteArrayToString($keyRec[0]);
            }
        }

        BLTE::loadEncryptionKeys($keys);

        echo sprintf(" OK (+%d)\n", count($keys));
    }

    public function getContentHash(string $file, ?string $locale = null): ?string {
        $contentHash = null;
        foreach ($this->nameSources as $nameSourceName => $nameSource) {
            if ($contentHash = $nameSource->getContentHash($file, $locale)) {
                break;
            }
        }

        return $contentHash;
    }

    /**
     * Saves the data for the given content hash to the given filesystem location.
     *
     * @param string $contentHash The content hash, in binary bytes.
     * @param string $destPath Where to save the file.
     *
     * @return string|null Returns the name of the data source which provided it when successful, null on failure.
     */
    private function fetchContentHash(string $contentHash, string $destPath): ?string {
        $contentMap = $this->encoding->getContentMap($contentHash);
        if (!$contentMap) {
            return null;
        }

        foreach ($this->dataSources as $dataSourceName => $dataSource) {
            foreach ($contentMap->getEncodedHashes() as $hash) {
                try {
                    if ($location = $dataSource->findHashInIndexes($hash)) {
                        if ($dataSource->extractFile($location, $destPath, $contentHash)) {
                            return $dataSourceName;
                        }
                    }
                } catch (\Exception $e) {
                    echo sprintf(" - %s ", trim($e->getMessage()));
                }
            }
        }

        return null;
    }
}
