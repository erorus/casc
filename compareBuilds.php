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

    echo "Cache dir: $cachePath\n";

    $cache = new CASC\Cache($cachePath);
    try {
        $versions = new CASC\VersionConfig\RibbitVersionsFile($newVersionsPath, $cache, $program, $region);
        $ngdp = new CASC\NGDP($cachePath, null, $program, $region, $locale, $versions);
        $newContentHashes = $ngdp->getContentHashes();
        unset($versions, $ngdp);

        $versions = new CASC\VersionConfig\RibbitVersionsFile($oldVersionsPath, $cache, $program, $region);
        $ngdp = new CASC\NGDP($cachePath, null, $program, $region, $locale, $versions);
        $oldContentHashes = $ngdp->getContentHashes();
        unset($versions, $ngdp);
    } catch (\Exception $e) {
        echo $e->getMessage(), "\n";
        return 1;
    }
    unset($cache);

    $added = $changed = $removed = 0;
    echo sprintf("\n%d old content hashes, %d new content hashes\n", count($oldContentHashes), count($newContentHashes));
    foreach ($oldContentHashes as $key => $hash) {
        if (!isset($newContentHashes[$key])) {
            $removed++;
        } elseif ($hash !== $newContentHashes[$key]) {
            $changed++;
        }
    }
    foreach ($newContentHashes as $key => $hash) {
        if (!isset($oldContentHashes[$key])) {
            $added++;
        }
    }
    echo sprintf("%d added, %d changed, %d removed\n", $added, $changed, $removed);

    echo "\n";

    $httpStats = CASC\HTTP::getStats();
    echo sprintf("%d remote connection%s made for %d remote request%s\n",
        $httpStats['connections'], $httpStats['connections'] == 1 ? '' : 's',
        $httpStats['requests'], $httpStats['requests'] == 1 ? '' : 's');

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

exit(main());
