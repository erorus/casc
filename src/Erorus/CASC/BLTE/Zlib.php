<?php

namespace Erorus\CASC\BLTE;

class Zlib extends ChunkType {
    private $context = null;

    private $headerBytes = '';

    const HEADER_SIZE = 2;

    public function Write($buffer) {
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

    public function __destruct()
    {
        fwrite($this->fileHandle, inflate_add($this->context, '', ZLIB_FINISH));
        //return parent::__destruct();
    }
}
