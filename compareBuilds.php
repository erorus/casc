<?php

require_once __DIR__ . '/vendor/autoload.php';

use Erorus\CASC;

function getHomeDir() {
    $home = getenv('HOME');
    if (!empty($home)) {
        // home should never end with a trailing slash.
        $home = rtrim($home, '/');
    }
    elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
        // home on windows
        $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
        // If HOMEPATH is a root directory the path can end with a slash. Make sure
        // that doesn't happen.
        $home = rtrim($home, '\\/');
    }
    return empty($home) ? false : $home;
}

function main()
{
    ini_set('memory_limit', '1G');

    $cachePath = $defaultCachePath = (getHomeDir() ?? __DIR__) . DIRECTORY_SEPARATOR . '.casc-cache';
    $program = 'wow';
    $region = 'us';
    $locale = 'enUS';
    $oldVersionsPath = null;
    $newVersionsPath = null;

    $shortOpts = 'c:p:r:l:o:n:h';
    $longOpts = [
        'cache:',
        'program:',
        'region:',
        'locale:',
        'old:',
        'new:',
        'help',
        'no-check-certificate',
    ];

    $opts = getopt($shortOpts, $longOpts);
    foreach ($opts as $k => $v) {
        switch ($k) {
            case 'h':
            case 'help':
                printHelp($defaultCachePath);
                return 0;
            case 'c':
            case 'cache':
                $cachePath = $v;
                break;
            case 'p':
            case 'program':
                $program = $v;
                break;
            case 'r':
            case 'region':
                $region = $v;
                break;
            case 'l':
            case 'locale':
                $locale = $v;
                break;
            case 'o':
            case 'old':
                $oldVersionsPath = $v;
                break;
            case 'n':
            case 'new':
                $newVersionsPath = $v;
                break;
            case 'no-check-certificate':
                CASC\HTTP::setCertVerification(false);
                break;
        }
    }

    if ($oldVersionsPath === null) {
        echo "Required option 'old' not found, aborting.\n";
        printHelp($defaultCachePath);
        return 1;
    }

    if ($newVersionsPath === null) {
        echo "Required option 'new' not found, aborting.\n";
        printHelp($defaultCachePath);
        return 1;
    }

    $cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);

    $cache = new CASC\Cache($cachePath);
    try {
        $newVersions = new CASC\VersionConfig\RibbitVersionsFile($newVersionsPath, $cache, $program, $region);
        $newNgdp = new CASC\NGDP($cachePath, null, $program, $region, $locale, false, null, $newVersions);
        $newContentHashes = $newNgdp->getContentHashes();

        $oldVersions = new CASC\VersionConfig\RibbitVersionsFile($oldVersionsPath, $cache, $program, $region);
        $oldNgdp = new CASC\NGDP($cachePath, null, $program, $region, $locale, false, null, $oldVersions);
        $oldContentHashes = $oldNgdp->getContentHashes();
    } catch (\Exception $e) {
        echo $e->getMessage(), "\n";
        return 1;
    }
    unset($cache);

    $added = ['count' => 0, 'bytes' => 0];
    $changed = ['count' => 0, 'bytes' => 0];
    $removed = ['count' => 0, 'bytes' => 0];

    echo sprintf(
        "\n%s old content hashes\n%s new content hashes\n",
        number_format(count($oldContentHashes)),
        number_format(count($newContentHashes)),
    );
    foreach ($oldContentHashes as $key => $hash) {
        if (!isset($newContentHashes[$key])) {
            $removed['count']++;
            $removed['bytes'] += $oldNgdp->getContentMap($hash)?->getFileSize() ?? 0;
        } elseif ($hash !== $newContentHashes[$key]) {
            $changed['count']++;
            $changed['bytes'] += $newNgdp->getContentMap($newContentHashes[$key])?->getFileSize() ?? 0;
        }
    }
    foreach ($newContentHashes as $key => $hash) {
        if (!isset($oldContentHashes[$key])) {
            $added['count']++;
            $added['bytes'] += $newNgdp->getContentMap($hash)?->getFileSize() ?? 0;
        }
    }
    echo sprintf(
        "%s (%sMB) added\n%s (%sMB) changed\n%s (%sMB) removed\n",
        number_format($added['count']),
        number_format(ceil($added['bytes'] / 1048576)),
        number_format($changed['count']),
        number_format(ceil($changed['bytes'] / 1048576)),
        number_format($removed['count']),
        number_format(ceil($removed['bytes'] / 1048576)),
    );

    echo "\n";

    $httpStats = CASC\HTTP::getStats();
    echo sprintf("%d remote connection%s made for %d remote request%s\n",
        $httpStats['connections'], $httpStats['connections'] == 1 ? '' : 's',
        $httpStats['requests'], $httpStats['requests'] == 1 ? '' : 's');
    echo 'Peak memory usage: ' . number_format(memory_get_peak_usage() / 1048576) . "MB\n";

    fwrite(STDOUT, json_encode([
        'locale' => $locale,
        'prior' => [
            'product' => $oldVersions->getProgram(),
            'region' => $oldVersions->getRegion(),
            'version' => $oldVersions->getVersion(),
            'buildConfig' => $oldVersions->getBuildConfig(),
            'contentHashCount' => count($oldContentHashes),
        ],
        'current' => [
            'product' => $newVersions->getProgram(),
            'region' => $newVersions->getRegion(),
            'version' => $newVersions->getVersion(),
            'buildConfig' => $newVersions->getBuildConfig(),
            'contentHashCount' => count($newContentHashes),
        ],
        'stats' => [
            'addedFiles' => $added['count'],
            'addedBytes' => $added['bytes'],
            'removedFiles' => $removed['count'],
            'removedBytes' => $removed['bytes'],
            'changedFiles' => $changed['count'],
            'changedBytes' => $changed['bytes'],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

    return 0;
}

function printHelp($cachePath) {
    global $argv;

    $me = $argv[0];

    $locales = CASC\Manifest\Root::LOCALE_FLAGS;
    ksort($locales);
    $locales = implode(", ", array_keys($locales));

    echo <<<EOF
    
Usage: 
  php $me --old <path> --new <path> [--cache <path>] [--program <wow>] [--region <us>] [--locale <enUS>]
  php $me --help

-o, --old      The path to the older versions file.
-n, --new      The path to the newer versions file.

-c, --cache    Optional. Path for CASC's file cache. [default: $cachePath]
-p, --program  Optional. The NGDP program code (wow, wow_beta, wowt). [default: wow] 
-r, --region   Optional. Region. (us, eu, cn, tw, kr) [default: us]
-l, --locale   Optional. Locale. ($locales) [default: enUS]

--no-check-certificate  Optional. Ignores SSL certificate issues when connecting to HTTPS hosts.

-h, --help     This help message.

EOF;

}

ob_start(static function (string $chunk): string {
    fwrite(STDERR, $chunk);
    return '';
}, 1);
$retCode = main();
ob_end_flush();

exit($retCode);
