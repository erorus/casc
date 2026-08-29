<?php

namespace Erorus\CASC\VersionConfig;

use Erorus\CASC\Cache;
use Erorus\CASC\HostList;
use Erorus\CASC\HTTP;

class Ribbit implements VersionConfig
{
    private const URL_PREFIX = 'https://us.version.battle.net/v2';

    /** @var int To protect against querying Ribbit unnecessarily, we cache the responses and consider them fresh
     *           for this many seconds after fetch.
     */
    private const MAX_CACHE_AGE = 3600;

    /** @var string The TACT product code. */
    private readonly string $program;

    /** @var string The TACT region code. */
    private readonly string $region;

    /**** CDN Config Values ****/

    /** @var string The path prefix component used when fetching assets from the CDN. */
    private string $cdnPath = '';

    /** @var string[]|HostList CDN hostnames. */
    private array|HostList $hosts = [];

    /** @var string[]|HostList CDN hosts with protocols. */
    private array|HostList $servers = [];

    /**** Version Config Values ****/

    /** @var string The hex file hash to download the build configuration for this version. */
    private string $buildConfig = '';

    /** @var string The hex file hash to download the CDN configuration for this version. */
    private string $cdnConfig = '';

    /** @var string The full game version name represented by this config. e.g. "8.3.0.34601" */
    private string $version = '';

    /**
     * VersionConfig constructor.
     *
     * @param Cache $cache A disk cache where we can find and store raw files we download.
     * @param string $program The TACT product code.
     * @param string $region The region, as defined in the version config column. One of: us, eu, cn, tw, kr
     */
    public function __construct(
        private readonly Cache $cache,
        string $program = 'wow',
        string $region = 'us'
    ) {
        $this->program = strtolower($program);
        $this->region = strtolower($region);
    }

    /**
     * @return string The TACT product code.
     */
    public function getProgram(): string {
        return $this->program;
    }

    /**
     * @return string The region code.
     */
    public function getRegion(): string {
        return strtoupper($this->region);
    }

    /**** CDN Config ****/

    /**
     * @return string A path component, without leading or trailing slashes.
     */
    public function getCDNPath(): string {
        if (!$this->cdnPath) {
            $this->getCDNs();
        }

        return $this->cdnPath;
    }

    /**
     * @return string[]|HostList A list of CDN hostnames.
     */
    public function getHosts(): array|HostList {
        if (!$this->hosts) {
            $this->getCDNs();
        }

        return $this->hosts;
    }

    /**
     * @return string[]|HostList A list of CDN URL prefixes, e.g. ["http://cdn.example.com/"]
     */
    public function getServers(): array|HostList {
        if (!$this->servers) {
            $this->getCDNs();
        }

        return $this->servers;
    }

    /**** Version Config ****/

    /**
     * @return string The hex file hash to download the build configuration for this version.
     */
    public function getBuildConfig(): string {
        if (!$this->buildConfig) {
            $this->getVersions();
        }

        return $this->buildConfig;
    }

    /**
     * @return string The hex file hash to download the CDN configuration for this version.
     */
    public function getCDNConfig(): string {
        if (!$this->cdnConfig) {
            $this->getVersions();
        }

        return $this->cdnConfig;
    }

    /**
     * @return string The full game version name represented by this config. e.g. "8.3.0.34601"
     */
    public function getVersion(): string {
        if (!$this->version) {
            $this->getVersions();
        }

        return $this->version;
    }

    // ------- //
    // PRIVATE //
    // ------- //

    /**
     * Returns only a cached version config response, or null if no cached data is found.
     *
     * @param string $cachePath
     * @param int|null $maxAge Returns null if the cached response is older than this amount of seconds.
     *
     * @return string|null
     */
    private function getCachedResponse(string $cachePath, ?int $maxAge = null): ?string {
        if (!$this->cache->fileExists($cachePath)) {
            return null;
        }
        if ($maxAge && $this->cache->fileModified($cachePath) < (time() - $maxAge)) {
            return null;
        }

        return $this->cache->read($cachePath);
    }

    /**
     * Fetches and parses the CDNs version file into the properties of this object.
     */
    private function getCDNs(): void {
        foreach ($this->parseVersionCsv($this->getTACTData('cdns') ?? '') as $row) {
            if (!isset($row['name'])) {
                continue;
            }
            if ($row['name'] !== $this->region) {
                continue;
            }

            $this->cdnPath = $row['path'] ?? '';
            $this->hosts = new HostList(explode(' ', $row['hosts'] ?? ''));

            $servers = [];
            if (isset($row['servers'])) {
                foreach (explode(' ', $row['servers']) as $url) {
                    // Strip off the querystring, which seems to be metadata packed into the URL instead of necessary.
                    if (($pos = strpos($url, '?')) !== false) {
                        $url = substr($url, 0, $pos);
                    }
                    // Make sure we always end in a slash, so we can append paths easier.
                    if (substr($url, -1) !== '/') {
                        $url .= '/';
                    }

                    $servers[] = $url;
                };
            } else {
                foreach (explode(' ', $row['hosts'] ?? '') as $host) {
                    $servers[] = "http://{$host}/";
                }
            }
            $this->servers = new HostList($servers);

            break;
        }
    }

    /**
     * Returns the content of a version config file at the given path, either from cache or by fetching it directly.
     *
     * @param string $file A product info file name, like "cdns" or "versions"
     *
     * @return string|null
     */
    private function getTACTData(string $file): ?string {
        $cachePath = 'ribbit-v2/' . $this->getProgram() . '/' . $file;

        $data = $this->getCachedResponse($cachePath, static::MAX_CACHE_AGE);
        if (!$data) {
            $url = self::URL_PREFIX . sprintf("/products/%s/%s", $this->getProgram(), $file);
            try {
                $data = HTTP::get($url);
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

    /**
     * Fetches and parses the Versions version file into the properties of this object.
     */
    private function getVersions(): void {
        foreach ($this->parseVersionCsv($this->getTACTData('versions') ?? '') as $row) {
            if (!isset($row['region'])) {
                continue;
            }
            if ($row['region'] !== $this->region) {
                continue;
            }

            $this->buildConfig = $row['buildconfig'] ?? '';
            $this->cdnConfig = $row['cdnconfig'] ?? '';
            $this->version = $row['versionsname'] ?? '';

            break;
        }
    }

    /**
     * Given Blizzard's special pipe-separated versions file data, returns it formatted into an array of rows, keyed
     * by name.
     *
     * @param string $data
     *
     * @return array[]
     */
    private function parseVersionCsv(string $data): array {
        $result = [];

        $lines = preg_split('/[\r\n]+/', $data);
        if (strpos($lines[0], '|') === false) {
            return [];
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
            $result[] = array_combine($names, $vals);
        }

        return $result;
    }
}
