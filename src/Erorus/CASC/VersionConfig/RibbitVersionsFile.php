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
     * @param string|null $region The region, as defined in the version config column. Null to use the latest version.
     */
    public function __construct(
        string $versionsFilePath,
        Cache $cache,
        string $program = 'wow',
        ?string $region = null,
    ) {
        $this->versionsFilePath = $versionsFilePath;

        if ($region === null) {
            $lines = $this->parseVersionCsv(file_get_contents($this->versionsFilePath));
            foreach ($lines as $index => &$line) {
                $line['index'] = $index;
            }
            unset($line);
            usort($lines, static fn (array $a, array $b): int =>
                version_compare($b['versionsname'] ?? '', $a['versionsname'] ?? '') ?:
                ((int)($b['buildid'] ?? '0') - (int)($a['buildid'] ?? '0')) ?:
                ((($a['region'] ?? '') === 'us' ? 0 : 1) - (($b['region'] ?? '') === 'us' ? 0 : 1)) ?:
                ((($a['name'] ?? '') === 'us' ? 0 : 1) - (($b['name'] ?? '') === 'us' ? 0 : 1)) ?:
                ($a['index'] - $b['index']));

            $region = $lines[0]['region'];
        }

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
