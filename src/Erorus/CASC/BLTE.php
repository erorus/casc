<?php

namespace Erorus\CASC;

class BLTE
{
    public $context;

    private $fileHandle;
    private $reportErrors;

    private $streamPosition;

    private $rawBuf = '';
    private $headerSize = false;
    private $chunkCount = 0;
    private $chunkInfo = [];
    private $chunkIndex = 0;

    private static $encryptionKeys = [];

    public function stream_open($path, $mode, $options, $opened_path) {
        $this->reportErrors = !!($options & STREAM_REPORT_ERRORS);

        if (!preg_match('/^blte:\/\/([\w\W]+)/i', $path, $res)) {
            return false;
        }
        switch ($mode) {
            case 'w':
            case 'x':
                $mode .= 'b';
                break;
            case 'wb':
            case 'xb':
                break;
            default:
                if ($this->reportErrors) {
                    trigger_error("BLTE: Write-only stream", E_USER_ERROR);
                }
                return false;
        }

        $filePath = $res[1];

        $this->fileHandle = fopen($filePath, $mode, !!($options & STREAM_USE_PATH), $this->context);
        if ($this->fileHandle === false) {
            return false;
        }

        $this->streamPosition = 0;

        return true;
    }

    public function stream_close() {
        $this->writeChunk(true);
        fclose($this->fileHandle);
    }

    public function stream_seek($offset, $whence) {
        switch ($whence) {
            case SEEK_SET:
                return ($offset == $this->streamPosition);
                break;
            case SEEK_CUR:
            case SEEK_END:
                return ($offset == 0);
                break;
        }
        return false;
    }

    public function stream_tell() {
        return $this->streamPosition;
    }

    public function stream_eof() {
        return true;
    }

    public function stream_stat() {
        return [
            'size' => $this->streamPosition
        ];
    }

    public function stream_write($data) {
        $this->rawBuf .= $data;
        $this->streamPosition += strlen($data);

        if ($this->headerSize === false) {
            if ($this->streamPosition < 8) {
                return strlen($data);
            }
            if (substr($this->rawBuf, 0, 4) !== 'BLTE') {
                throw new \Exception("Stream is not BLTE encoded\n");
            }
            $this->headerSize = current(unpack('N', substr($this->rawBuf, 4, 4)));
            $this->rawBuf = substr($this->rawBuf, 8);
        }
        if (!$this->chunkCount) {
            if ($this->headerSize == 0) {
                $this->chunkCount = 1;
                $this->chunkInfo[] = ['compressedSize' => '*'];
            } else {
                if ($this->streamPosition < 12) {
                    return strlen($data);
                }
                $flags            = current(unpack('C', substr($this->rawBuf, 0, 1)));
                $this->chunkCount = current(unpack('N', "\x00" . substr($this->rawBuf, 1, 3)));
                $this->rawBuf     = substr($this->rawBuf, 4);

                if ($this->chunkCount <= 0) {
                    throw new \Exception("BLTE Data is badly formatted: 0 chunks\n");
                }
            }
        }
        while (($this->chunkCount > count($this->chunkInfo)) && strlen($this->rawBuf) >= 24){
            $this->chunkInfo[] = unpack('NcompressedSize/NdecompressedSize/h32checksum', substr($this->rawBuf, 0, 24));
            $this->rawBuf = substr($this->rawBuf, 24);
        }
        if ($this->chunkCount > count($this->chunkInfo)) {
            return strlen($data);
        }

        $this->writeChunk();

        return strlen($data);
    }

    private function writeChunk($ending = false) {
        while ($this->chunkIndex < count($this->chunkInfo)) {
            $bytesForThisChunk = $this->chunkInfo[$this->chunkIndex]['compressedSize'];
            if ($bytesForThisChunk == '*') {
                if ($ending) {
                    $bytesForThisChunk = strlen($this->rawBuf);
                } else {
                    break;
                }
            }
            if (strlen($this->rawBuf) >= $bytesForThisChunk) {
                fwrite($this->fileHandle, static::parseBLTEChunk(substr($this->rawBuf, 0, $bytesForThisChunk)));
                $this->chunkIndex++;
                $this->rawBuf = substr($this->rawBuf, $bytesForThisChunk);
            } else {
                break;
            }
        }
    }

    public function stream_flush() {
        return fflush($this->fileHandle);
    }

    public static function parseBLTE($data)
    {
        if (substr($data, 0, 4) !== 'BLTE') {
            fwrite(STDERR, "Data is not BLTE encoded\n");

            return false;
        }

        $headerSize = current(unpack('N', substr($data, 4, 4)));
        $chunkInfo  = [];
        $pos        = 8;
        if ($headerSize) {
            $flags      = current(unpack('C', substr($data, $pos++, 1)));
            $chunkCount = current(unpack('N', "\x00" . substr($data, $pos, 3)));
            $pos += 3;
            if ($chunkCount == 0) {
                fwrite(STDERR, "BLTE Data is badly formatted: 0 chunks\n");

                return false;
            }
            for ($x = 0; $x < $chunkCount; $x++) {
                $chunkInfo[$x] = unpack('NcompressedSize/NdecompressedSize/h32checksum', substr($data, $pos, 24));
                $pos += 24;
            }
        } else {
            $chunkInfo = [['compressedSize' => strlen($data) - $pos]];
        }

        $out = '';
        foreach ($chunkInfo as $ci) {
            $out .= static::parseBLTEChunk(substr($data, $pos, $ci['compressedSize']));
            $pos += $ci['compressedSize'];
        }

        return $out;
    }

    private static function parseBLTEChunk($data)
    {
        switch (substr($data, 0, 1)) {
            case 'N':
                // plain
                return substr($data, 1);
            case 'Z':
                // zlib
                $zlibHeader = substr($data, 1, 2);

                return gzinflate(substr($data, 3));
            case 'F':
                // recursively encoded blte
                return static::parseBLTE(substr($data, 1));
            case 'E':
                $keyNameLength = ord(substr($data, 1, 1));
                $keyName = substr($data, 2, $keyNameLength);
                $ivLength = ord(substr($data, $keyNameLength + 2, 1));
                $iv = substr($data, $keyNameLength + 3, $ivLength);
                $type = substr($data, $keyNameLength + 3 + $ivLength, 1);

                if (isset(static::$encryptionKeys[$keyName])) {
                    if ($type == 'S') {
                        return static::decryptSalsa(substr($data, $keyNameLength + $ivLength + 4), static::$encryptionKeys[$keyName], $iv);
                    }
                }
                fwrite(STDERR, "Encrypted chunk, skipping!\n");

                return '';
            default:
                fwrite(STDERR, sprintf("Unknown chunk type %s, skipping!\n", substr($data, 0, 1)));

                return '';
        }
    }

    public static function loadEncryptionKeys($keys) {
        foreach ($keys as $k => $v) {
            static::$encryptionKeys[$k] = $v;
            echo sprintf("%s -> %s\n", bin2hex($k), bin2hex($v));
        }
    }

    private static function decryptSalsa($data, $key, $iv) {
        return '';
    }
}
