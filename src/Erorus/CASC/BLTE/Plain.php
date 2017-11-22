<?php

namespace Erorus\CASC\BLTE;

class Plain extends ChunkType {
    public function Write($buffer) {
        $written = fwrite($this->fileHandle, $buffer);
        $this->encodedBytesWritten += $written;

        return $written;
    }
}
