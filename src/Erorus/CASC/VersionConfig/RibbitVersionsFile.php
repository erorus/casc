<?php

namespace Erorus\CASC\VersionConfig;

use Erorus\CASC\Cache;

class RibbitVersionsFile extends Ribbit {
    private string $versionsFilePath;

    /**
     * VersionConfig constructor.
     *
     * @param string $versionsFilePath The path on disk to the /versions config file.
     * @param Cache $cache A disk cache where we can find and store raw files we download.
     * @param string $program The TACT product code.
     * @param string $region The region, as defined in the version config column. One of: us, eu, cn, tw, kr
     */
    public function __construct(
        string $versionsFilePath,
        Cache $cache,
        string $program = 'wow',
        string $region = 'us',
    ) {
        $this->versionsFilePath = $versionsFilePath;

        parent::__construct($cache, $program, $region);
    }

    /**
     * Returns the content of a version config file at the given path, either from cache or by fetching it directly.
     *
     * @param string $file A product info file name, like "cdns" or "versions"
     *
     * @return string|null
     */
    protected function getTACTData(string $file): ?string {
        if ($file === 'versions') {
            return file_get_contents($this->versionsFilePath) ?: null;
        }

        return parent::getTACTData($file);
    }
}
