<?php

namespace Erorus\CASC\DataSource;

use Erorus\CASC\DataSource;
use Erorus\CASC\DataSource\Location\CASC as CASCLocation;
use Erorus\CASC\Util;

class CASC extends DataSource {
    private $indexPath = false;
    private $files = [];

    public function __construct($wowPath)
    {
        $wowPath = rtrim($wowPath, DIRECTORY_SEPARATOR);

        $this->indexPath = sprintf('%2$s%1$sData%1$sdata', DIRECTORY_SEPARATOR, $wowPath);
        if ( ! is_dir($this->indexPath)) {
            fwrite(STDERR, sprintf("Could not find local indexes at %s\n", $this->indexPath));
            $this->indexPath = false;
            return;
        } else {
            $this->indexPath .= DIRECTORY_SEPARATOR;
        }

        $files = [];
        $idxes = glob($this->indexPath . '*.idx');
        foreach ($idxes as $idxFile) {
            $idxFile = basename($idxFile);
            if ( ! preg_match('/^[0-9a-f]{10}\.idx$/', $idxFile)) {
                continue;
            }
            $x   = hexdec(substr($idxFile, 0, 2));
            $ver = hexdec(substr($idxFile, 2, 8));
            if ( ! isset($files[$x]) || $files[$x] < $ver) {
                $files[$x] = $ver;
            }
        }

        foreach ($files as $x => $ver) {
            $this->fetchIndexHeaders(sprintf('%02x%08x.idx', $x, $ver));
        }
    }

    /**
     * Find a location in this data source for the given encoding hash. Null if not found.
     *
     * @param string $hash An encoding hash, in binary bytes.
     *
     * @return Location|null
     */
    public function findHashInIndexes(string $hash): ?Location {
        if (!$this->indexPath || !$this->files) {
            return null;
        }

        $result = null;
        foreach ($this->files as $fileInfo) {
            if ($result = $this->findHashInIndex($fileInfo, $hash)) {
                break;
            }
        }

        return $result;
    }

    private function fetchIndexHeaders($fileName) {
        $eof = filesize($this->indexPath . $fileName);
        $f = fopen($this->indexPath . $fileName, 'rb');

        $header = unpack('VheaderHashSize/VheaderHash/vunk0/Cbucket/Cunk1/CentrySize/CentryOffset/CentryKey/CarchiveFileHeader/ParchiveTotalSize/a8pad/VentriesSize/VentryHash', fread($f, 0x28));
        $fail = false;
        $fail |= $header['unk0'] != 7;
        $fail |= $header['unk1'] != 0;
        $fail |= $header['entrySize'] != 4;
        $fail |= $header['entryOffset'] != 5;
        $fail |= $header['entryKey'] != 9;
        $fail |= $header['archiveFileHeader'] != 30;
        if ($fail) {
            fwrite(STDERR, sprintf("Expected constants in local index %s do not line up\n", $fileName));
            fclose($f);
            return;
        }

        $this->files[] = [
            'name' => $fileName,
            'eof' => $eof,
            'entriesSize' => $header['entriesSize'],
            'entriesStart' => 0x28,
            'entryLength' => $header['entrySize'] + $header['entryOffset'] + $header['entryKey'],
            'entryKey' => $header['entryKey'],
            'entrySize' => $header['entrySize'],
            'entryOffset' => $header['entryOffset'],
        ];

        fclose($f);
    }

    private function findHashInIndex($fileInfo, string $hash): ?CASCLocation {
        $needle = substr($hash, 0, $fileInfo['entryKey']);

        $f = fopen($this->indexPath . $fileInfo['name'], 'rb');

        $lo = 0;
        $hi = floor(($fileInfo['entriesSize'] - $fileInfo['entriesStart']) / $fileInfo['entryLength']);

        while ($lo <= $hi) {
            $mid = (int)(($hi - $lo) / 2) + $lo;
            fseek($f, $fileInfo['entriesStart'] + $mid * $fileInfo['entryLength']);
            $test = fread($f, $fileInfo['entryKey']);
            $cmp = strcmp($test, $needle);
            if ($cmp < 0) {
                $lo = $mid + 1;
            } elseif ($cmp > 0) {
                $hi = $mid - 1;
            } else {
                $parts = unpack('Coff1/Noff2/Vsize', fread($f, $fileInfo['entryOffset'] + $fileInfo['entrySize']));
                $combo = ($parts['off1'] << 32) | $parts['off2'];

                $offset = $combo & 0x3fffffff;
                $archive = $combo >> 30;

                fclose($f);

                return new CascLocation([
                    'archive' => $archive,
                    'length' => $parts['size'],
                    'offset' => $offset,
                    'hash' => $needle,
                ]);
            }
        }

        fclose($f);

        return null;
    }

    /**
     * Given the location of some content in this data source, extract it to the given destination filesystem path.
     *
     * @param CASCLocation $locationInfo
     * @param string $destPath
     *
     * @return bool Success
     */
    protected function fetchFile(Location $locationInfo, string $destPath): bool {
        if (!is_a($locationInfo, CASCLocation::class)) {
            throw new \Exception("Unexpected location info object type.");
        }

        $readPath = sprintf('%sdata.%03d', $this->indexPath, $locationInfo->archive);
        $readHandle = fopen($readPath, 'rb');
        if (!$readHandle) {
            throw new \Exception(sprintf("Unable to open %s for reading\n", $readPath));
        }

        fseek($readHandle, $locationInfo->offset);

        $hashReversed = fread($readHandle, 16);
        $hashConfirm = implode('', array_reverse(str_split($hashReversed)));
        if (substr($hashConfirm, 0, strlen($locationInfo->hash)) != $locationInfo->hash) {
            fclose($readHandle);
            throw new \Exception(sprintf("Data in local archive didn't match expected hash: %s vs %s\n", bin2hex($hashConfirm), bin2hex($locationInfo->hash)));
        }

        $sizeConfirm = current(unpack('V', fread($readHandle, 4)));
        if ($sizeConfirm != $locationInfo->length) {
            fclose($readHandle);
            throw new \Exception(sprintf("Data in local archive didn't match expected size: %d vs %d\n", $sizeConfirm, $locationInfo->length));
        }

        fseek($readHandle, 10, SEEK_CUR);

        if (!Util::assertParentDir($destPath, 'output')) {
            return false;
        }

        $writePath = 'blte://' . $destPath;
        $writeHandle = fopen($writePath, 'wb');
        if ($writeHandle === false) {
            fclose($readHandle);
            throw new \Exception(sprintf("Unable to open %s for writing\n", $writePath));
        }

        $readLen = $locationInfo->length - 30;
        $pos = 0;
        while ($pos < $readLen) {
            $data = fread($readHandle, min(65536, $readLen - $pos));
            $writtenBytes = fwrite($writeHandle, $data);
            if ($writtenBytes != strlen($data)) {
                if ($writtenBytes == 0) {
                    fclose($readHandle);
                    fclose($writeHandle);
                    unlink($destPath);
                    throw new \Exception(sprintf("Failed to write %d bytes to %s", strlen($data), $writePath));
                }
                fwrite(STDERR, sprintf("Warning: read %d bytes, but wrote %d bytes\n", strlen($data), $writtenBytes));
                fseek($readHandle, $writtenBytes - strlen($data), SEEK_CUR);
            }
            $pos += $writtenBytes;
        }

        fclose($writeHandle);
        fclose($readHandle);

        return true;
    }
}
