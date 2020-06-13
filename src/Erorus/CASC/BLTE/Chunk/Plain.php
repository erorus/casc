<?php

namespace Erorus\CASC\BLTE\Chunk;

use Erorus\CASC\BLTE\Chunk;

class Plain extends Chunk {
    public function Write($buffer) {
        $written = fwrite($this->fileHandle, $buffer);
        $this->encodedBytesWritten += $written;

        return $written;
    }
}
