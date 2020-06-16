<?php

namespace Erorus\CASC\BLTE;

/**
 * BLTE files are encoded into separate chunks. Each chunk may be compressed, or encrypted, or both. This handles
 * parsing a chunk into plaintext.
 */
abstract class Chunk {
    /** @var int Which chunk this is in the file. */
    protected $chunkIndex;

    /** @var int How many chunks are known to be in this file. */
    protected $chunkCount;

    /** @var int|null How many bytes are in this chunk after being decoded. */
    protected $decodedSize;

    /** @var int How many encoded bytes we've consumed so far. */
    protected $encodedBytesWritten = 0;

    /** @var int|null How many bytes are in this encoded chunk. */
    protected $encodedSize;

    /** @var resource The handle to the plaintext file we're writing on disk. */
    protected $fileHandle;

    /**
     * @param string $typeByte The first character of an encoded chunk, which indicates its type.
     * @param array $chunkInfo Metadata about this chunk and the file it belongs to
     * @param resource $fileHandle Where we're writing the decoded data.
     *
     * @return Chunk
     * @throws \Exception
     */
    public static function MakeChunk(string $typeByte, array $chunkInfo, $fileHandle): self {
        switch ($typeByte) {
            case 'N':
                return new Chunk\Plain($chunkInfo, $fileHandle);
            case 'Z':
                return new Chunk\Zlib($chunkInfo, $fileHandle);
            case 'E':
                return new Chunk\Encrypted($chunkInfo, $fileHandle);
        }

        throw new \Exception(sprintf("Unsupported chunk type: %s\n", bin2hex($typeByte)));
    }

    /**
     * Chunk constructor.
     *
     * @param array $chunkInfo Metadata about this chunk and the file it belongs to
     * @param resource $fileHandle Where we're writing the decoded data.
     */
    function __construct(array $chunkInfo, $fileHandle) {
        $this->chunkIndex = $chunkInfo['id'];
        $this->chunkCount = $chunkInfo['chunkCount'] ?? 0;

        $this->encodedSize = (isset($chunkInfo['encodedSize']) && is_numeric($chunkInfo['encodedSize'])) ? $chunkInfo['encodedSize'] - 1 : null;
        $this->decodedSize = $chunkInfo['decodedSize'] ?? null;
        $this->fileHandle = $fileHandle;
    }

    /**
     * Return how many encoded bytes are still expected in this chunk.
     *
     * @return int
     */
    public function getRemainingBytes(): int {
        if (is_null($this->encodedSize)) {
            return 524288;
        }
        return $this->encodedSize - $this->encodedBytesWritten;
    }

    /**
     * Receives encoded bytes, decodes them, and writes them to our $fileHandle.
     *
     * @param string $buffer Encoded bytes.
     * @return int How many encoded bytes were consumed.
     */
    abstract public function Write(string $buffer): int;
}
