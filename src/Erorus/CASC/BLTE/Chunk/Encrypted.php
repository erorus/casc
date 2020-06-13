<?php

namespace Erorus\CASC\BLTE\Chunk;

use Erorus\CASC\BLTE;
use Erorus\CASC\BLTE\Chunk;

class Encrypted extends Chunk {

    private $keyNameLength = 0;
    private $keyName = '';
    private $ivLength = 0;
    private $iv = '';
    private $encType = '';
    private $key = null;

    private $chainedChunk = null;

    private $salsaPool = '';
    private $salsaIn = false;

    private $keyParts = [];
    private $constParts = [];

    private $statusText = '';

    const SALSA_CONST = 'expand 16-byte k';
    const SALSA_ROUNDS = 20;

    public function __construct($chunkInfo, $fileHandle)
    {
        parent::__construct($chunkInfo, $fileHandle);

        echo $this->statusText = sprintf(" -> Decrypting %d bytes in chunk %d of %d ", $this->encodedSize, $this->chunkIndex + 1, $this->chunkCount);
    }

    public function __destruct()
    {
        echo sprintf('%1$s[%2$dD%3$s%1$s[%2$dD', "\e", strlen($this->statusText), str_repeat(' ', strlen($this->statusText)));
    }

    public function Write($buffer) {
        if (!$buffer) {
            return 0;
        }
        
        $this->encodedBytesWritten += ($written = strlen($buffer));
        
        while ($buffer && !$this->encType) {
            if (!$this->keyNameLength) {
                $this->keyNameLength = current(unpack('C', substr($buffer, 0, 1)));
                $buffer = substr($buffer, 1);
                continue;
            }
            if (strlen($this->keyName) < $this->keyNameLength) {
                $length = min(strlen($buffer), $this->keyNameLength - strlen($this->keyName));
                $this->keyName = substr($buffer, 0, $length);
                $buffer = substr($buffer, $length);
                continue;
            }

            if (!$this->ivLength) {
                $this->ivLength = current(unpack('C', substr($buffer, 0, 1)));
                $buffer = substr($buffer, 1);
                continue;
            }
            if (strlen($this->iv) < $this->ivLength) {
                $length = min(strlen($buffer), $this->ivLength - strlen($this->iv));
                $this->iv = substr($buffer, 0, $length);
                $buffer = substr($buffer, $length);
                continue;
            }

            $this->encType = substr($buffer, 0, 1);
            $buffer = substr($buffer, 1);
        }
        if (!$this->encType) {
            return $written;
        }
        if (is_null($this->key)) {
            $this->key = BLTE::getEncryptionKey($this->keyName);
            if (!$this->key) {
                fwrite(STDERR, sprintf("Could not find key %s\n", bin2hex($this->keyName)));
                fwrite($this->fileHandle, str_repeat("\0", $this->decodedSize));
            }
        }

        if (!$this->key) {
            return $written;
        }

        if ($this->encType != 'S') {
            $this->key = false; // so we don't spam the error message
            fwrite(STDERR, sprintf("Unknown encryption type: %s\n", $this->encType));
            return $written;
        }

        if (is_null($this->chainedChunk)) {
            if (!$buffer) {
                return $written;
            }

            $chainedChunkType = substr($buffer, 0, 1) ^ $this->GetSalsaBytes(1);
            $buffer = substr($buffer, 1);

            $this->chainedChunk = Chunk::MakeChunk($chainedChunkType, [
                'id' => $this->chunkIndex,
                'encodedSize' => $this->decodedSize + 1,
            ], $this->fileHandle);
        }

        if ($buffer) {
            $this->chainedChunk->Write($buffer ^ $this->GetSalsaBytes(strlen($buffer)));
        }

        return $written;
    }

    /* Salsa code borrowed and modified from ParagonIE_Sodium_Core_Salsa20 */

    private function GetSalsaBytes($len) {
        $in = $this->salsaIn;
        if ($in === false) {
            // first iteration

            if (strlen($this->key) != 16) {
                throw new \Exception('Expected key length 16, received key length ' . strlen($this->key));
            }
            $this->key .= $this->key; // expand 16-byte key to 32 bytes

            $this->keyParts = unpack('V*', $this->key);
            $this->constParts = unpack('V*', static::SALSA_CONST);

            $in = str_split($this->iv);
            for ($x = 0; $x < 4; $x++) {
                $in[$x] ^= chr(($this->chunkIndex >> ($x * 8)) & 0xFF);
            }
            $in = implode('', $in);
            $in .= str_repeat("\0", 16 - strlen($in));
        }

        while (strlen($this->salsaPool) < $len) {
            $this->salsaPool .= $this->core_salsa20($in);

            $u = 1;
            // Internal counter.
            for ($i = 8; $i < 16; ++$i) {
                $u += ord($in[$i]);
                $in[$i] = chr($u & 0xff);
                $u >>= 8;
            }
        }

        $bytes = substr($this->salsaPool, 0, $len);
        $this->salsaPool = substr($this->salsaPool, $len);
        $this->salsaIn = $in;

        return $bytes;
    }

    private function core_salsa20($in)
    {
        $j0  = $x0  = $this->constParts[1];
        $j5  = $x5  = $this->constParts[2];
        $j10 = $x10 = $this->constParts[3];
        $j15 = $x15 = $this->constParts[4];

        $j1  = $x1  = $this->keyParts[1];
        $j2  = $x2  = $this->keyParts[2];
        $j3  = $x3  = $this->keyParts[3];
        $j4  = $x4  = $this->keyParts[4];

        $j11 = $x11 = $this->keyParts[5];
        $j12 = $x12 = $this->keyParts[6];
        $j13 = $x13 = $this->keyParts[7];
        $j14 = $x14 = $this->keyParts[8];

        $unpacked = unpack('V*', $in);
        $j6  = $x6  = $unpacked[1];
        $j7  = $x7  = $unpacked[2];
        $j8  = $x8  = $unpacked[3];
        $j9  = $x9  = $unpacked[4];

        for ($i = static::SALSA_ROUNDS; $i > 0; $i -= 2) {
            $x4 ^= 0xFFFFFFFF & ((($z = ($x0 + $x12)) << 7) | (($z & 0xFFFFFFFF) >> 25));
            $x8 ^= 0xFFFFFFFF & ((($z = ($x4 + $x0)) << 9) | (($z & 0xFFFFFFFF) >> 23));
            $x12 ^= 0xFFFFFFFF & ((($z = ($x8 + $x4)) << 13) | (($z & 0xFFFFFFFF) >> 19));
            $x0 ^= 0xFFFFFFFF & ((($z = ($x12 + $x8)) << 18) | (($z & 0xFFFFFFFF) >> 14));

            $x9 ^= 0xFFFFFFFF & ((($z = ($x5 + $x1)) << 7) | (($z & 0xFFFFFFFF) >> 25));
            $x13 ^= 0xFFFFFFFF & ((($z = ($x9 + $x5)) << 9) | (($z & 0xFFFFFFFF) >> 23));
            $x1 ^= 0xFFFFFFFF & ((($z = ($x13 + $x9)) << 13) | (($z & 0xFFFFFFFF) >> 19));
            $x5 ^= 0xFFFFFFFF & ((($z = ($x1 + $x13)) << 18) | (($z & 0xFFFFFFFF) >> 14));

            $x14 ^= 0xFFFFFFFF & ((($z = ($x10 + $x6)) << 7) | (($z & 0xFFFFFFFF) >> 25));
            $x2 ^= 0xFFFFFFFF & ((($z = ($x14 + $x10)) << 9) | (($z & 0xFFFFFFFF) >> 23));
            $x6 ^= 0xFFFFFFFF & ((($z = ($x2 + $x14)) << 13) | (($z & 0xFFFFFFFF) >> 19));
            $x10 ^= 0xFFFFFFFF & ((($z = ($x6 + $x2)) << 18) | (($z & 0xFFFFFFFF) >> 14));

            $x3 ^= 0xFFFFFFFF & ((($z = ($x15 + $x11)) << 7) | (($z & 0xFFFFFFFF) >> 25));
            $x7 ^= 0xFFFFFFFF & ((($z = ($x3 + $x15)) << 9) | (($z & 0xFFFFFFFF) >> 23));
            $x11 ^= 0xFFFFFFFF & ((($z = ($x7 + $x3)) << 13) | (($z & 0xFFFFFFFF) >> 19));
            $x15 ^= 0xFFFFFFFF & ((($z = ($x11 + $x7)) << 18) | (($z & 0xFFFFFFFF) >> 14));

            $x1 ^= 0xFFFFFFFF & ((($z = ($x0 + $x3)) << 7) | (($z & 0xFFFFFFFF) >> 25));
            $x2 ^= 0xFFFFFFFF & ((($z = ($x1 + $x0)) << 9) | (($z & 0xFFFFFFFF) >> 23));
            $x3 ^= 0xFFFFFFFF & ((($z = ($x2 + $x1)) << 13) | (($z & 0xFFFFFFFF) >> 19));
            $x0 ^= 0xFFFFFFFF & ((($z = ($x3 + $x2)) << 18) | (($z & 0xFFFFFFFF) >> 14));

            $x6 ^= 0xFFFFFFFF & ((($z = ($x5 + $x4)) << 7) | (($z & 0xFFFFFFFF) >> 25));
            $x7 ^= 0xFFFFFFFF & ((($z = ($x6 + $x5)) << 9) | (($z & 0xFFFFFFFF) >> 23));
            $x4 ^= 0xFFFFFFFF & ((($z = ($x7 + $x6)) << 13) | (($z & 0xFFFFFFFF) >> 19));
            $x5 ^= 0xFFFFFFFF & ((($z = ($x4 + $x7)) << 18) | (($z & 0xFFFFFFFF) >> 14));

            $x11 ^= 0xFFFFFFFF & ((($z = ($x10 + $x9)) << 7) | (($z & 0xFFFFFFFF) >> 25));
            $x8 ^= 0xFFFFFFFF & ((($z = ($x11 + $x10)) << 9) | (($z & 0xFFFFFFFF) >> 23));
            $x9 ^= 0xFFFFFFFF & ((($z = ($x8 + $x11)) << 13) | (($z & 0xFFFFFFFF) >> 19));
            $x10 ^= 0xFFFFFFFF & ((($z = ($x9 + $x8)) << 18) | (($z & 0xFFFFFFFF) >> 14));

            $x12 ^= 0xFFFFFFFF & ((($z = ($x15 + $x14)) << 7) | (($z & 0xFFFFFFFF) >> 25));
            $x13 ^= 0xFFFFFFFF & ((($z = ($x12 + $x15)) << 9) | (($z & 0xFFFFFFFF) >> 23));
            $x14 ^= 0xFFFFFFFF & ((($z = ($x13 + $x12)) << 13) | (($z & 0xFFFFFFFF) >> 19));
            $x15 ^= 0xFFFFFFFF & ((($z = ($x14 + $x13)) << 18) | (($z & 0xFFFFFFFF) >> 14));
        }

        $x0  += $j0;
        $x1  += $j1;
        $x2  += $j2;
        $x3  += $j3;
        $x4  += $j4;
        $x5  += $j5;
        $x6  += $j6;
        $x7  += $j7;
        $x8  += $j8;
        $x9  += $j9;
        $x10 += $j10;
        $x11 += $j11;
        $x12 += $j12;
        $x13 += $j13;
        $x14 += $j14;
        $x15 += $j15;

        return pack('V*', $x0, $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9, $x10, $x11, $x12, $x13, $x14, $x15);
    }
}
