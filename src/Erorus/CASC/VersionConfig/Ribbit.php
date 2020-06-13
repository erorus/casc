<?php

namespace Erorus\CASC\VersionConfig;

use Erorus\CASC\VersionConfig;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\Part\MessagePart;

class Ribbit extends VersionConfig {

    const RIBBIT_HOST = 'us.version.battle.net';
    const RIBBIT_PORT = 1119;
    const TIMEOUT = 10; // seconds

    protected function getNGDPData($file) {
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
            $this->cache->writePath($cachePath, $data);
        } else {
            $data = $this->getCachedResponse($cachePath);
        }

        return $data;
    }
}
