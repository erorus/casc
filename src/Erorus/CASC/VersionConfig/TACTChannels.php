<?php

namespace Erorus\CASC\VersionConfig;

use Erorus\CASC\Cache;
use Erorus\CASC\HostList;
use Erorus\CASC\HTTP;
use stdClass;
use Throwable;

readonly class TACTChannels implements VersionConfig
{
    private const HOST = 'https://distribution.version.battle.net/';

    private string $root;
    private stdClass $summary;

    private HostList $hosts;
    private HostList $servers;

    /**
     * VersionConfig constructor.
     *
     * @param Cache $cache A disk cache where we can find and store raw files we download.
     * @param string $program The TACT product code.
     * @param string $region The region, as defined in the version config column. One of: us, eu, cn, tw, kr
     */
    public function __construct(
        private Cache $cache,
        private string $program = 'wow',
        private string $region = 'us'
    ) {}

    public function getProgram(): string {
        return $this->program;
    }

    public function getRegion(): string {
        return strtoupper($this->region);
    }

    public function getCDNPath(): string {
        $build = $this->getSummary()->builds[0] ?? (object)[];

        return $build->path ?? '';
    }

    public function getHosts(): array|HostList {
        if (isset($this->hosts)) {
            return $this->hosts;
        }

        $hosts = [];

        $cdn = $this->getSummary()->regions[0] ?? (object)[];
        foreach ($cdn->hosts ?? [] as $host) {
            $url = $host->host ?? '';
            if (preg_match('~^https?://~', $url)) {
                $hostname = parse_url($url, PHP_URL_HOST);
                $hosts[$hostname] = $hostname;
            }
        }

        return $this->hosts = new HostList($hosts);
    }

    public function getServers(): array|HostList {
        if (isset($this->servers)) {
            return $this->servers;
        }

        $servers = [];

        $cdn = $this->getSummary()->regions[0] ?? (object)[];
        foreach ($cdn->hosts ?? [] as $host) {
            $url = $host->host ?? '';
            if (preg_match('~^https?://~', $url)) {
                $parts = parse_url($url);
                $origin = $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '/');
                $servers[$origin] = $origin;
            }
        }

        return $this->servers = new HostList($servers);
    }

    public function getBuildConfig(): string {
        $build = $this->getSummary()->builds[0] ?? (object)[];

        return $build->buildKey ?? '';
    }

    public function getCDNConfig(): string {
        $build = $this->getSummary()->builds[0] ?? (object)[];

        return $build->cdnKey ?? '';
    }

    public function getVersion(): string {
        $build = $this->getSummary()->builds[0] ?? (object)[];

        return $build->versionString ?? '0';
    }

    private function getSummary(): stdClass {
        if (isset($this->summary)) {
            return $this->summary;
        }

        $fail = fn () => $this->summary = (object)['builds' => [], 'regions' => []];

        try {
            $pointerJson = json_decode($this->cachedFetch('summary') ?? '{}', flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $fail();
        }

        $this->root = $pointerJson->path ?? '/';
        $hash = $pointerJson->public?->channel ?? null;
        if (!$hash) {
            return $fail();
        }

        try {
            $fullSummary = json_decode($this->cachedFetchHash($hash) ?? '{"products": []}', flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $fail();
        }

        $branchTest = fn (stdClass $data): bool => in_array($this->region, $data->branches ?? [], strict: true);

        foreach ($fullSummary->products ?? [] as $product) {
            if ($product->variant !== $this->program) {
                continue;
            }

            $product->builds = array_values(array_filter($product->builds ?? [], $branchTest));
            $product->regions = array_values(array_filter($product->regions ?? [], $branchTest));

            foreach ($product->builds as &$build) {
                $build = (object)array_merge(
                    (array)$build,
                    (array)json_decode($this->cachedFetchHash($build->definition) ?? '{}', flags: JSON_THROW_ON_ERROR),
                );
            }
            unset($build);

            foreach ($product->regions as &$cdn) {
                $cdn = (object)array_merge(
                    (array)$cdn,
                    (array)json_decode($this->cachedFetchHash($cdn->cdns) ?? '{}', flags: JSON_THROW_ON_ERROR),
                );
            }
            unset($cdn);

            return $this->summary = $product;
        }

        return $fail();
    }

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

    private function cachedFetch(string $path): ?string {
        $cachePath = 'tact-channels/' . $path;

        $maxAge = $path === 'summary' ? 10 : 2592000;

        $data = $this->getCachedResponse($cachePath, $maxAge);
        if (!$data) {
            $url = self::HOST . $path;
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

    private function cachedFetchHash(string $hash): ?string {
        return $this->cachedFetch(sprintf('%s%s/%s/%s', $this->root, substr($hash, 0, 2), substr($hash, 2, 2), $hash));
    }
}
