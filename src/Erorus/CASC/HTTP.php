<?php

namespace Erorus\CASC;

/**
 * Uses cURL to perform HTTP requests, optionally printing progress to a stream. Favors persistent connections.
 */
class HTTP {
    /** @var int Downloads below this total size, in bytes, will not print progress updates. */
    private const PROGRESS_MINIMUM_FILE_SIZE = 256 * 1024;

    /** @var float Number of seconds to wait initially before printing progress updates. */
    private const PROGRESS_UPDATE_DELAY = 0.5;

    /** @var float Number of seconds between printing progress updates. */
    private const PROGRESS_UPDATE_INTERVAL = 0.25;

    /** @var resource|null Where to write download progress (typically STDOUT) or null to mute. */
    public static $writeProgressToStream = null;

    /** @var int[] Statistics about how many requests and connections we made. */
    private static $connectionTracking = [
        'connections' => 0,
        'requests' => 0,
    ];

    /** @var resource|null A handle to the persistent curl instance. */
    private static $curlHandle = null;

    /** @var bool[] Keyed by host:port, the hosts we've connected to recently.  */
    private static $curlSeenHosts = [];

    /** @var int How many bytes we downloaded in the current operation. */
    private static $downloaded = 0;

    /** @var int How many bytes we downloaded when we last printed a progress message. */
    private static $progressPrinted = 0;

    /** @var int UNIX timestamp when the last progress message was printed. */
    private static $progressPrintedTime = 0;

    /** @var bool Whether we registered the shutdown function to close our curl handle. */
    private static $registeredShutdown = false;

    /** @var string The URL we're currently downloading. */
    private static $url = '';

    /** @var bool True when curl verifies SSL certs, false to ignore bad certs. */
    private static $verifyCert = true;

    /**
     * Avoids reusing any currently-open connections to hosts on future requests. Returns the hostnames for which we
     * would have attempted to reuse connections.
     *
     * @return string[]
     */
    public static function abandonConnections(): array {
        $oldHosts = array_keys(static::$curlSeenHosts);
        static::$curlSeenHosts = [];

        return $oldHosts;
    }

    /**
     * Closes our current curl handle. Happens automatically on shutdown, normally no need to call this.
     */
    public static function closeCurl(): void {
        if (!is_null(static::$curlHandle)) {
            curl_close(static::$curlHandle);
            static::$curlHandle = null;
        }
    }

    /**
     * Returns statistics about how many requests and connections we made.
     *
     * @return int[]
     */
    public static function getStats(): array {
        return static::$connectionTracking;
    }

    /**
     * Performs an HTTP GET request.
     *
     * @param string $url The full URL to fetch.
     * @param resource|null $fileHandle When not null, writes the response to this handle.
     * @param string|null $range
     *
     * @return mixed False on failure. The fetched data as a string, or boolean true when using $fileHandle.
     * @throws \Exception
     */
    public static function get(string $url, $fileHandle = null, ?string $range = null) {
        $ch = static::getCurl();

        curl_setopt_array($ch, [
            CURLOPT_URL            => static::$url = $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_FRESH_CONNECT  => static::needsNewConnection($url),
            CURLOPT_SSLVERSION     => 6, //CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => static::$verifyCert,
            CURLOPT_SSL_VERIFYHOST => static::$verifyCert ? 2 : 0,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_LOW_SPEED_LIMIT => 50 * 1024,
            CURLOPT_LOW_SPEED_TIME => 20,
        ]);
        if (!is_null($fileHandle)) {
            curl_setopt($ch, CURLOPT_FILE, $fileHandle);
        } else {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
            ]);
        }
        static::$downloaded = 0;
        static::$progressPrinted = 0;
        if (!is_null(static::$writeProgressToStream)) {
            static::$progressPrintedTime = microtime(true) + static::PROGRESS_UPDATE_DELAY - static::PROGRESS_UPDATE_INTERVAL;
            curl_setopt_array($ch, [
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION =>
                    function ($ch, $totalDown, $transmittedDown, $totalUp, $transmittedUp): int {
                        static::onCurlProgress($totalDown, $transmittedDown);
                        return 0;
                    },
            ]);
        }
        if (!is_null($range)) {
            curl_setopt($ch, CURLOPT_RANGE, $range);
        }

        $started = microtime(true);
        $data = curl_exec($ch);
        $finished = microtime(true);
        $errMsg = curl_error($ch);

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
                $data = substr($data, $pos + 4);
            } while (
                $data &&
                preg_match('/^HTTP\/\d+\.\d+ (\d+)/', $headerLines[0], $res) &&
                ($res[1] != $responseCode)
            ); // mostly to handle 100 Continue, maybe 30x redirects too
        }

        // Finish progress printing.
        if (static::$progressPrinted) {
            // Wipe out previous progress message.
            fwrite(static::$writeProgressToStream, "\x1B[K");

            $downloaded = static::$downloaded / 1048576;
            $speed = $downloaded / ($finished - $started);

            $line = sprintf("%.1fM (%.2f MBps)", $downloaded, $speed);
            fwrite(static::$writeProgressToStream, $line);
        }

        static::$url = '';

        if (preg_match('/^2\d\d$/', $responseCode) > 0) {
            return $data;
        }

        if (!is_null($fileHandle)) {
            ftruncate($fileHandle, 0);
        }
        return false;
    }

    /**
     * Performs an HTTP HEAD request.
     *
     * @param string $url The full URL to fetch.
     *
     * @return string[] HTTP headers, keyed by header name.
     * @throws \Exception
     */
    public static function head(string $url): array {
        $ch = static::getCurl();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY         => true,
            CURLOPT_FRESH_CONNECT  => static::needsNewConnection($url),
            CURLOPT_SSLVERSION     => 6, //CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => static::$verifyCert,
            CURLOPT_SSL_VERIFYHOST => static::$verifyCert ? 2 : 0,
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
        } while (
            $data &&
            preg_match('/^HTTP\/\d+\.\d+ (\d+)/', $headerLines[0], $res) &&
            ($res[1] != $responseCode)
        ); // mostly to handle 100 Continue, maybe 30x redirects too

        $headers = [];
        foreach ($headerLines as $headerLine) {
            if (preg_match('/^([^:]+):\s*([\w\W]+)/', $headerLine, $headerLineParts)) {
                $headers[$headerLineParts[1]] = $headerLineParts[2];
            }
        }
        $outHeaders = array_merge(['responseCode' => $responseCode], $headers);

        return $outHeaders;
    }

    /**
     * Sets whether we verify SSL certs.
     *
     * @param bool $verify
     */
    public static function setCertVerification(bool $verify): void {
        static::$verifyCert = $verify;
    }

    /**
     * Returns our current curl handle, reset for a new request.
     *
     * @return resource
     */
    private static function getCurl() {
        if (is_null(static::$curlHandle)) {
            static::$curlHandle = curl_init();
        } else {
            curl_reset(static::$curlHandle);
        }

        if (!static::$registeredShutdown) {
            static::$registeredShutdown = true;
            register_shutdown_function([static::class, 'closeCurl']);
        }

        return static::$curlHandle;
    }

    /**
     * Given a URL, returns true when curl should initiate a new connection to the host for the next request.
     *
     * @param string $url
     *
     * @return bool
     */
    private static function needsNewConnection(string $url): bool {
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
        if (isset(static::$curlSeenHosts[$hostKey])) {
            return false;
        }
        static::$curlSeenHosts[$hostKey] = true;

        return true;
    }

    /**
     * Handler for curl progress update events during a download. Prints progress messages as appropriate.
     *
     * @param int $totalDown
     * @param int $transmittedDown
     */
    private static function onCurlProgress(int $totalDown, int $transmittedDown): void {
        static::$downloaded = $transmittedDown;

        if (
            $totalDown < static::PROGRESS_MINIMUM_FILE_SIZE ||
            static::$progressPrintedTime + static::PROGRESS_UPDATE_INTERVAL > microtime(true)
        ) {
            return;
        }

        if (static::$progressPrinted) {
            // Erase from the cursor to the end of the line -- wipe out the previous progress message.
            fwrite(static::$writeProgressToStream, "\x1B[K");

            // Calculate a download speed since our last message.
            $speed = ($transmittedDown - static::$progressPrinted) / 1048576 /
                     (microtime(true) - static::$progressPrintedTime);
        } else {
            // Starting to write progress messages. Write the URL.
            fwrite(static::$writeProgressToStream, sprintf(" - %s ", static::$url));

            // Don't bother with calculating a speed, we'll update it in the next interval.
            $speed = 0;
        }

        $line = sprintf(
            "%.1fM / %.1fM - %d%% (%.2f MBps)",
            $transmittedDown / 1048576,
            $totalDown / 1048576,
            round($transmittedDown / $totalDown * 100),
            $speed
        );

        fwrite(static::$writeProgressToStream, $line);

        // Move the cursor back to where we started printing this progress message.
        fwrite(static::$writeProgressToStream, sprintf("\x1B[%dD", strlen($line)));

        static::$progressPrinted = $transmittedDown;
        static::$progressPrintedTime = microtime(true);
    }
}
