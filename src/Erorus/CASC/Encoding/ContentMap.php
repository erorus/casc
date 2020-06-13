<?php

namespace Erorus\CASC\Encoding;

/**
 * This describes a mapping between a content hash and one or many encoded-file hashes.
 */
class ContentMap {
    /** @var string The content hash, in binary bytes (not hex). */
    private $contentHash = '';

    /** @var string[] Encoding hashes, in binary bytes, used by this content hash. */
    private $encodedHashes = [];

    /** @var int The size, in bytes, of the non-encoded version of the file. */
    private $fileSize = 0;

    /**
     * ContentMap constructor.
     *
     * @param string $contentHash
     * @param array $encodedHashes
     * @param int $fileSize
     */
    public function __construct(string $contentHash, array $encodedHashes, int $fileSize) {
        $this->contentHash = $contentHash;
        $this->encodedHashes = $encodedHashes;
        $this->fileSize = $fileSize;
    }

    /**
     * @return string
     */
    public function getContentHash(): string {
        return $this->contentHash;
    }

    /**
     * @return string[]
     */
    public function getEncodedHashes(): array {
        return $this->encodedHashes;
    }

    /**
     * @return int
     */
    public function getFileSize(): int {
        return $this->fileSize;
    }
}
