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
    ini_set('memory_limit', '512M');

    $dest = null;
    $cachePath = $defaultCachePath = (getHomeDir() ?? __DIR__) . DIRECTORY_SEPARATOR . '.casc-cache';
    $wowPath = false;
    $program = 'wow';
    $region = 'us';
    $locale = 'enUS';
    $listfile = '';

    $shortOpts = 'o:c:p:r:l:f:w:hi';
    $longOpts = ['out:','cache:','program:','region:','locale:','files:','wow:', 'help', 'ignore'];

    $opts = getopt($shortOpts, $longOpts);
    foreach ($opts as $k => $v) {
        switch ($k) {
            case 'h':
            case 'help':
                printHelp($defaultCachePath);
                return 0;
            case 'i':
            case 'ignore':
                CASC\DataSource::$ignoreErrors = true;
                break;
            case 'o':
            case 'out':
                $dest = $v;
                break;
            case 'w':
            case 'wow':
                $wowPath = $v;
                break;
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
            case 'f':
            case 'files':
                $listfile = $v;
                break;
        }
    }

    if (is_null($dest)) {
        echo "Required option 'out' not found, aborting.\n";
        printHelp($defaultCachePath);
        return 1;
    }

    if (is_null($listfile)) {
        echo "Required option 'files' not found, aborting.\n";
        printHelp($defaultCachePath);
        return 1;
    }

    $dest = rtrim($dest, DIRECTORY_SEPARATOR);
    if (!is_dir($dest) || !is_writable($dest)) {
        echo "Out directory $dest is not found/writable, aborting.\n";
        return 1;
    }

    if ($wowPath) {
        $wowPath = rtrim($wowPath, DIRECTORY_SEPARATOR);
        if ( ! is_dir($wowPath)) {
            echo "WoW directory $wowPath is not found, aborting.\n";
            return 1;
        }
    }

    $cachePath = rtrim($cachePath, DIRECTORY_SEPARATOR);

    if (!file_exists($listfile) || !is_readable($listfile)) {
        echo "Files list $listfile is not readable, aborting.\n";
        return 1;
    }

    $files = file($listfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    echo "Output dir: $dest\n";
    echo "Cache dir: $cachePath\n";

    try {
        $ngdp = new CASC\NGDP($cachePath, $wowPath, $program, $region, $locale);
    } catch (\Exception $e) {
        echo $e->getMessage(), "\n";
        return 1;
    }

    echo "\n";

    $successCount = 0;
    $totalCount = 0;

    foreach ($files as $file) {
        $file = trim($file);
        if (!$file) {
            continue;
        }

        if (preg_match('/^(\d+)\W*([\w\W]+)$/', $file, $res)) {
            $destName = $res[2];
            $file = $res[1];
        } else {
            $destName = $file;
        }
        $slash = DIRECTORY_SEPARATOR;
        $destPath = $dest . $slash . strtr($destName, ['/' => $slash, '\\' => $slash]);

        $totalCount++;
        echo $destName;

        $success = $ngdp->fetchFile($file, $destPath);
        $successCount += $success ? 1 : 0;

        $success = $success ? sprintf('OK (%s)', $success) : 'Failed';
        echo sprintf(" -> %s\n", $success);
    }

    echo "\n";
    echo sprintf("%d file%s out of %d extracted successfully\n", $successCount, $successCount == 1 ? '' : 's', $totalCount);
    $httpStats = CASC\HTTP::GetStats();
    echo sprintf("%d remote connection%s made for %d remote request%s\n",
        $httpStats['connections'], $httpStats['connections'] == 1 ? '' : 's',
        $httpStats['requests'], $httpStats['requests'] == 1 ? '' : 's');

    return $successCount > 0 ? 1 : 0;
}

function printHelp($cachePath) {
    global $argv;

    $me = $argv[0];

    $locales = CASC\NameLookup\Root::LOCALE_FLAGS;
    ksort($locales);
    $locales = implode(", ", array_keys($locales));

    echo <<<EOF
    
Usage: 
  php $me --files <path> --out <path> [--wow <path>] [--cache <path>] [--program <wow>] [--region <us>] [--locale <enUS>] [--ignore]
  php $me --help

-f, --files    Required. Path of a text file listing the files to extract, one per line. 
-o, --out      Required. Destination path for extracted files.

-w, --wow      Recommended. Path to World of Warcraft's install directory.

-c, --cache    Optional. Path for CASC's file cache. [default: $cachePath]
-p, --program  Optional. The NGDP program code (wow, wow_beta, wowt). [default: wow] 
-r, --region   Optional. Region. (us, eu, cn, tw, kr) [default: us]
-l, --locale   Optional. Locale. ($locales) [default: enUS]
-i, --ignore   Optional. Ignore extraction errors (useful to keep partially encrypted files)

-h, --help     This help message.

EOF;

}

exit(main());
