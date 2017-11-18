<?php

namespace Erorus\CASC;

class HTTP {
    private static $curl_handle = null;
    private static $curl_seen_hosts = [];

    public static $writeProgressToStream = null;
    private static $progress;

    private static $connectionTracking = [
        'connections' => 0,
        'requests' => 0,
    ];

    private static function GetCurl() {
        static $registeredShutdown = false;

        if (is_null(static::$curl_handle)) {
            static::$curl_handle = curl_init();
        } else {
            curl_reset(static::$curl_handle);
        }

        if (!$registeredShutdown) {
            $registeredShutdown = true;
            register_shutdown_function([HTTP::class, 'CloseCurl']);
        }

        return static::$curl_handle;
    }

    public static function CloseCurl() {
        if (!is_null(static::$curl_handle)) {
            curl_close(static::$curl_handle);
            static::$curl_handle = null;
        }
    }

    public static function ResetStats() {
        static::$connectionTracking = [
            'connections' => 0,
            'requests' => 0,
        ];
    }

    public static function GetStats() {
        return static::$connectionTracking;
    }

    private static function NeedsNewConnection($url) {
        $urlParts = parse_url($url);
        if (!isset($urlParts['host'])) {
            return true;
        }
        if (!isset($urlParts['port'])) {
            $urlParts['port'] = '';
            if (isset($urlParts['scheme'])) {
                switch ($urlParts['scheme']) {
                    case 'http':
                        $urlParts['port'] = 80;
                        break;
                    case 'https':
                        $urlParts['port'] = 443;
                        break;
                }
            }
        }
        $hostKey = $urlParts['host'].':'.$urlParts['port'];
        if (isset(static::$curl_seen_hosts[$hostKey])) {
            return false;
        }
        static::$curl_seen_hosts[$hostKey] = true;
        return true;
    }

    public static function AbandonConnections() {
        $oldHosts = array_keys(static::$curl_seen_hosts);
        static::$curl_seen_hosts = [];
        return $oldHosts;
    }

    private static function CurlProgress($ch = false, $totalDown = false, $transmittedDown = false, $totalUp = false, $transmittedUp = false) {
        if ($ch === false) {
            if (!isset(static::$progress['last'])) {
                return;
            }
            fwrite(static::$writeProgressToStream, "\x1B[K");

            $pk = static::$progress['progress'] / 1048576;
            $tk = static::$progress['total'] / 1048576;
            $pct = round(static::$progress['progress'] / static::$progress['total'] * 100);

            $speed = static::$progress['progress'] / 1048576 / (microtime(true) - static::$progress['started']);

            $line = sprintf("%.1fM / %.1fM - %d%% (%.2f MBps)\n", $pk, $tk, $pct, $speed);
            fwrite(static::$writeProgressToStream, $line);

            return;
        }

        static::$progress['total']    = $totalDown;
        static::$progress['progress'] = $transmittedDown;

        if ($totalDown < 262144 || static::$progress['updated'] + 0.25 > microtime(true)) {
            return;
        }

        if (isset(static::$progress['last'])) {
            fwrite(static::$writeProgressToStream, "\x1B[K");
        } else {
            fwrite(static::$writeProgressToStream, sprintf(" - %s ", static::$progress['url']));
        }

        if (isset(static::$progress['lastProgress'])) {
            $speed = ($transmittedDown - static::$progress['lastProgress']) / 1048576 / (microtime(true) - static::$progress['updated']);
        } else {
            $speed = '0';
        }

        $pk = $transmittedDown / 1048576;
        $tk = $totalDown / 1048576;
        $pct = round($transmittedDown / $totalDown * 100);

        $line = sprintf("%.1fM / %.1fM - %d%% (%.2f MBps)", $pk, $tk, $pct, $speed);

        static::$progress['last'] = $line;
        static::$progress['updated'] = microtime(true);
        static::$progress['lastProgress'] = $transmittedDown;

        fwrite(static::$writeProgressToStream, $line);
        fwrite(static::$writeProgressToStream, sprintf("\x1B[%dD", strlen($line)));
    }

    public static function Get($url, $fileHandle = null, $range = null) {
        $ch = static::GetCurl();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FRESH_CONNECT  => static::NeedsNewConnection($url),
            CURLOPT_SSLVERSION     => 6, //CURL_SSLVERSION_TLSv1_2,
            CURLOPT_TIMEOUT        => PHP_SAPI == 'cli' ? 30 : 8,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_ENCODING       => 'gzip',
        ]);
        if (!is_null($fileHandle)) {
            curl_setopt($ch, CURLOPT_FILE, $fileHandle);
        } else {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
            ]);
        }
        if (!is_null(static::$writeProgressToStream)) {
            static::$progress = [
                'url' => $url,
                'updated' => microtime(true) + 0.25,
                'started' => microtime(true),
            ];
            curl_setopt_array($ch, [
                CURLOPT_PROGRESSFUNCTION => [static::class, 'CurlProgress'],
                CURLOPT_NOPROGRESS => false,
            ]);
        }
        if (!is_null($range)) {
            curl_setopt($ch, CURLOPT_RANGE, $range);
        }

        $data = curl_exec($ch);
        $errMsg = curl_error($ch);
        if (!is_null(static::$writeProgressToStream)) {
            static::CurlProgress();
        }

        static::$connectionTracking['connections'] += curl_getinfo($ch, CURLINFO_NUM_CONNECTS);
        static::$connectionTracking['requests']++;

        if ($errMsg) {
            throw new \Exception(sprintf("cURL error fetching %s - %d %s", $url, curl_errno($ch), $errMsg));
        }

        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_null($fileHandle)) {
            fseek($fileHandle, 0);
        } else {
            do {
                $pos = strpos($data, "\r\n\r\n");
                if ($pos === false) {
                    break;
                }
                $headerLines = explode("\r\n", substr($data, 0, $pos));
                $data        = substr($data, $pos + 4);
            } while ($data &&
                     preg_match('/^HTTP\/\d+\.\d+ (\d+)/', $headerLines[0], $res) &&
                     ($res[1] != $responseCode)); // mostly to handle 100 Continue, maybe 30x redirects too
        }

        if (preg_match('/^2\d\d$/', $responseCode) > 0) {
            return $data;
        }

        if (!is_null($fileHandle)) {
            ftruncate($fileHandle, 0);
        }
        return false;
    }

    public static function Head($url) {
        $ch = static::GetCurl();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY         => true,
            CURLOPT_FRESH_CONNECT  => static::NeedsNewConnection($url),
            CURLOPT_SSLVERSION     => 6, //CURL_SSLVERSION_TLSv1_2,
            CURLOPT_TIMEOUT        => PHP_SAPI == 'cli' ? 30 : 8,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
        ]);

        $data = curl_exec($ch);
        $errMsg = curl_error($ch);

        static::$connectionTracking['connections'] += curl_getinfo($ch, CURLINFO_NUM_CONNECTS);
        static::$connectionTracking['requests']++;

        if ($errMsg) {
            throw new \Exception(sprintf("cURL error fetching %s - %d %s", $url, curl_errno($ch), $errMsg));
        }

        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $headerLines = [];
        do {
            $pos = strpos($data, "\r\n\r\n");
            if ($pos === false) {
                break;
            }
            $headerLines = explode("\r\n", substr($data, 0, $pos));
            $data = substr($data, $pos + 4);
        } while ($data &&
                 preg_match('/^HTTP\/\d+\.\d+ (\d+)/', $headerLines[0], $res) &&
                 ($res[1] != $responseCode)); // mostly to handle 100 Continue, maybe 30x redirects too

        $headers = [];
        foreach ($headerLines as $headerLine) {
            if (preg_match('/^([^:]+):\s*([\w\W]+)/', $headerLine, $headerLineParts)) {
                $headers[$headerLineParts[1]] = $headerLineParts[2];
            }
        }
        $outHeaders = array_merge(['responseCode' => $responseCode], $headers);

        return $outHeaders;
    }
}
