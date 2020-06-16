<?php

namespace Erorus\CASC\BLTE\Chunk;

use Erorus\CASC\BLTE\Chunk;

/**
 * A chunk compressed with Zlib.
 */
class Zlib extends Chunk {
    /** @var int How many additional bytes are in the chunk header for this chunk type. */
    private const HEADER_SIZE = 2;

    /** @var resource An incremental inflate context. Think of it as another write handle. It keeps track of the
     *                compression state as we continue to receive bytes.
     */
    private $context = null;

    /** @var string Additional metadata bytes in this chunk's header. */
    private $headerBytes = '';

    /**
     * Receives encoded bytes, decodes them, and writes them to our $fileHandle.
     *
     * @param string $buffer Encoded bytes.
     * @return int How many encoded bytes were consumed.
     */
    public function Write(string $buffer): int {
        $headerBytesToAdd = 0;
        if (is_null($this->context)) {
            $headerBytesToAdd = static::HEADER_SIZE - strlen($this->headerBytes);
            if (strlen($buffer) < $headerBytesToAdd) {
                $this->headerBytes .= $buffer;
                $this->encodedBytesWritten += strlen($buffer);

                return strlen($buffer);
            }

            $this->headerBytes .= substr($buffer, 0, $headerBytesToAdd);
            $buffer = substr($buffer, $headerBytesToAdd);

            $this->context = inflate_init(ZLIB_ENCODING_RAW);
        }

        fwrite($this->fileHandle, inflate_add($this->context, $buffer));

        $written = strlen($buffer) + $headerBytesToAdd;
        $this->encodedBytesWritten += $written;

        return $written;
    }

    /**
     * Make sure we finished decompressing this data.
     */
    public function __destruct() {
        fwrite($this->fileHandle, inflate_add($this->context, '', ZLIB_FINISH));
        //return parent::__destruct();
    }
}
