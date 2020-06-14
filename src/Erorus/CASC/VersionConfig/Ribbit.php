<?php

namespace Erorus\CASC\VersionConfig;

use Erorus\CASC\VersionConfig;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\Part\MessagePart;

class Ribbit extends VersionConfig {

    private const RIBBIT_HOST = 'us.version.battle.net';
    private const RIBBIT_PORT = 1119;
    private const TIMEOUT = 10; // seconds

    /**
     * Returns the content of a version config file at the given path, either from cache or by fetching it directly.
     *
     * @param string $file A product info file name, like "cdns" or "versions"
     *
     * @return string|null
     */
    protected function getTACTData(string $file): ?string {
        $cachePath = 'ribbit/' . $this->getProgram() . '/' . $file;

        $command = sprintf("v1/products/%s/%s\n", $this->getProgram(), $file);

        $data = $this->getCachedResponse($cachePath, static::MAX_CACHE_AGE);
        if ($data) {
            return $data;
        }

        $errNum = 0;
        $errMsg = '';
        $handle = fsockopen(static::RIBBIT_HOST, static::RIBBIT_PORT, $errNum, $errMsg, static::TIMEOUT);
        if ($handle !== false) {
            stream_set_timeout($handle, static::TIMEOUT);

            fwrite($handle, $command);

            $parser = new MailMimeParser();
            $message = $parser->parse($handle);

            fclose($handle);

            /** @var MessagePart[] $attachments */
            $attachments = $message->getAllAttachmentParts();
            foreach ($attachments as $attachment) {
                // If we look for "cdns", the content-disposition will be "cdn"
                if (strpos($file, $attachment->getContentDisposition()) !== 0) {
                    continue;
                }

                $data = $attachment->getContent();
                break;
            }
        }

        if ($data) {
            $this->cache->write($cachePath, $data);
        } else {
            $data = $this->getCachedResponse($cachePath);
        }

        return $data;
    }
}
