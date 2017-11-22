<?php

namespace Erorus\CASC\BLTE;

abstract class ChunkType {
    protected $fileHandle;
    protected $chunkIndex;
    protected $chunkCount;

    protected $encodedSize;
    protected $decodedSize;
    protected $encodedBytesWritten = 0;

    public static function MakeChunk($typeByte, $chunkInfo, $fileHandle) {
        switch ($typeByte) {
            case 'N':
                return new Plain($chunkInfo, $fileHandle);
            case 'Z':
                return new Zlib($chunkInfo, $fileHandle);
            case 'E':
                return new Encrypted($chunkInfo, $fileHandle);
        }

        throw new \Exception(sprintf("Unsupported chunk type: %s\n", bin2hex($typeByte)));
    }

    function __construct($chunkInfo, $fileHandle) {
        $this->chunkIndex = $chunkInfo['id'];
        $this->chunkCount = $chunkInfo['chunkCount'] ?? 0;

        $this->encodedSize = (isset($chunkInfo['encodedSize']) && is_numeric($chunkInfo['encodedSize'])) ? $chunkInfo['encodedSize'] - 1 : null;
        $this->decodedSize = $chunkInfo['decodedSize'] ?? null;
        $this->fileHandle = $fileHandle;
    }

    public function getRemainingBytes() {
        if (is_null($this->encodedSize)) {
            return 524288;
        }
        return $this->encodedSize - $this->encodedBytesWritten;
    }

    abstract public function Write($buffer);
}
