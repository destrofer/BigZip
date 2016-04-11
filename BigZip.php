<?php
/*
 * Copyright 2013 Viacheslav Soroka
 * Author: Viacheslav Soroka
 * 
 * You can get latest version of this file at: https://github.com/destrofer/BigZip
 * 
 * BigZip is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * BigZip is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with BigZip.  If not, see <http://www.gnu.org/licenses/>.
 */

class BigZip {
	private static $handlerRegistered = false;
	
	public $baseDir = "./";
	
	protected $writeMode = false;
	protected $zipFileName = null;
	protected $zipFile = null;
	protected $idxFile = null;
	protected $entryFile = null;
	protected $entryFileExt = null;
	protected $currentOffset = 0;
	protected $indexOffset = 0;
	protected $indexSize = 0;
	protected $entryCount = 0;
	protected $entryName = null;
	protected $entryTime = null;
	protected $entryAttribs = null;
	protected $entrySize = 0;
	protected $entryCrc = 0;
	protected $entryCrcHasher = null;
	
	protected function __construct($fileName, $isWriteMode) {
		if( !self::$handlerRegistered ) {
			stream_wrapper_register("bigzip", "BigZipStream")
				or die("Failed to register bigzip stream wrapper");
			stream_wrapper_register("bigzipint", "BigZipInternalStream")
				or die("Failed to register bigzipint stream wrapper");
			self::$handlerRegistered = true;
		}
		$this->zipFileName = $fileName;
		$this->writeMode = $isWriteMode;
		$this->zipFile = fopen($fileName, $isWriteMode ? "wb" : "rb");
		if( $this->zipFile ) {
			if( $isWriteMode ) {
				$this->idxFile = tmpfile();
				if( !$this->idxFile )
					$this->close();
			}
			else {
				fseek($this->zipFile, -22, SEEK_END);
				$tailId = fread($this->zipFile, 8);
				if( $tailId == "\x50\x4b\x05\x06\x00\x00\x00\x00" ) {
					$upv = unpack("vpart/varc/Vsize/Voffs", fread($this->zipFile, 16));
					if( $upv["part"] == $upv["arc"] ) {
						$this->entryCount = $upv["part"];
						$this->indexSize = $upv["size"];
						$this->currentOffset = $this->indexOffset = $upv["offs"];
					}
					else {
						// Multipart archives are not supported
						$this->close();
					}
				}
				else {
					// This is an invalid end of file, or it has a comment in the end
					// Don't want to traverse through all entries for finding the last
					// entry of the archive to just validate if it's a zip file
					$this->close();
				}
			}
		}
	}
	
	public function __destruct() {
		$this->close();
	}
	
	public static function openForRead($fileName) {
		$zip = new BigZip($fileName, false);
		return $zip->zipFile ? $zip : null;
	}
	
	public static function openForWrite($fileName) {
		$zip = new BigZip($fileName, true);
		return $zip->zipFile ? $zip : null;
	}
	
	public function close() {
		if( $this->entryFile )
			$this->entryClose();			
		if( $this->idxFile ) {
			if( $this->writeMode && $this->zipFile ) {
				$idxSize = ftell($this->idxFile);
				fseek($this->idxFile, 0, SEEK_SET);
				fseek($this->zipFile, $this->currentOffset, SEEK_SET);
				
				self::copyBytes($this->idxFile, $this->zipFile, $idxSize);
				
				fwrite($this->zipFile, pack('VvvvvVVv',
					0x06054B50,					// ( 0) End Of File entry identifier
					0,							// ( 4) version? always seem to be 0
					0,							// ( 6) general purpose flags? always seem to be 0
					$this->entryCount,			// ( 8) files in this archive part
					$this->entryCount,			// (10) total files in archive
					$idxSize,					// (12) size of index
					$this->currentOffset,		// (16) offset to the index
					0							// (20) zip file comment length
				));
			}
			fclose($this->idxFile);
			$this->idxFile = null;
		}
		if( $this->zipFile ) {
			fclose($this->zipFile);
			$this->zipFile = null;
		}
	}
	
	
	
	public function indexRewind() {
		if( $this->writeMode )
			return false; // throw new Exception("Cannot rewind index in write mode");
		$this->currentOffset = $this->indexOffset;
		return true;
	}
	
	public function indexRead() {
		if( $this->writeMode )
			return false; // throw new Exception("Cannot read index in write mode");
		if( $this->currentOffset >= $this->indexOffset + $this->indexSize )
			return false;
		fseek($this->zipFile, $this->currentOffset, SEEK_SET);
		$entry = unpack("Vhead/vcversion/veversion/vbits/vmethod/Vtime/Vcrc/Vcsize/Vsize/vnameLen/vexLen/vcmtLen/vdisk/vinternalAttribs/Vattribs/Voffset", fread($this->zipFile, 46));
		if( $entry["head"] != 0x02014B50 ) {
			return false;
		}
		$entry["name"] = fread($this->zipFile, $entry["nameLen"]);
		$this->currentOffset += 46 + $entry["nameLen"];
		return $entry;
	}
	
	public function indexReadAll() {
		$entries = Array();
		$this->indexRewind();
		while( $e = $this->indexRead() )
			$entries[] = $e;
		return $entries;
	}
	
	public function indexFind($fileName) {
		if( $this->writeMode )
			return false; // throw new Exception("Cannot read index in write mode");
		$offset = $this->currentOffset;
		$this->indexRewind();
		$entry = null;
		while( $e = $this->indexRead() ) {
			if( $e["name"] == $fileName ) {
				$entry = $e;
				break;
			}
		}
		$this->currentOffset = $offset;
		return $entry;
	}
	
	
	
	/**
	* Opens a file entry inside the archive either for reading or writing.
	* 
	* Be aware, that calling this function automatically closes a previously open entry.
	*
	* @param string $name An internal path and name of the file. Avoid using './', '../' and '/' in the beginning of the name.
	* @param integer $time A unix time of the file (write only).
	* @param integer $attribs File attribute bit flags (default is 32).
	* @returns resource A file stream resource which allows you to use fread() or fwrite() to access an archived file "directly". In case of an error it will return FALSE.
	*/
	public function entryOpen($name, $time = null, $attribs = null) {
		if( $this->entryFile )
			$this->entryClose();
		$name = str_replace('\\', '/', $name);
		if( $this->writeMode ) {
			if( $attribs === null )
				$attribs = 32; // archive

			$this->entryTime = self::timeconv(($time === null) ? time() : $time);
			$this->entrySize = 0;
			$this->crc32_init();
			$this->entryName = $name;
			$this->entryAttribs = intval($attribs);

			BigZipInternalStream::$resource = $this->zipFile;
			BigZipInternalStream::$dataOffset = $this->currentOffset + 30 + strlen($name) - 10; // -10, because this part has to be cut off from the beginning
			$this->entryFile = gzopen("bigzipint://null", "w9");
			BigZipInternalStream::$resource = null;
			BigZipInternalStream::$dataOffset = 0;
		}
		else {
			$entry = $this->indexFind($name);
			if( !$entry )
				return false;
			$this->entrySize = $entry["size"];
			$this->entryFile = fopen("zip://{$this->zipFileName}#{$name}", "rb");
		}
		
		if( !$this->entryFile )
			return false;
		
		BigZipStream::$instance = $this;
		$this->entryFileExt = fopen("bigzip://null", "rw");
		BigZipStream::$instance = null;
		return $this->entryFileExt;
	}
	
	public function entryWrite($data) {
		if( !$this->entryFile )
			throw new Exception("Use BigZip::entryOpen() before BigZip::entryWrite()");
		if( !$this->writeMode )
			throw new Exception("Cannot write in read mode");
		$written = gzwrite($this->entryFile, $data);
		$this->entrySize += $written;
		$this->crc32_add($data);
		return $written;
	}
	
	public function entryRead($count) {
		if( !$this->entryFile )
			throw new Exception("Use BigZip::entryOpen() before BigZip::entryRead()");
		if( $this->writeMode )
			throw new Exception("Cannot read in write mode");
		return fread($this->entryFile, $count);
	}
	
	public function entryClose() {
		if( $this->entryFile != null ) {
			if( $this->writeMode ) {
				gzclose($this->entryFile);
				
				fflush($this->zipFile);
				$offset = filesize($this->zipFileName); // ftell() does not work (points to start) and gztell() doesn't work as needed
				
				$compressedSize = $offset - ($this->currentOffset + 30 + strlen($this->entryName)) - 9; // -9, because this part has to be cut off from the end
				fseek($this->zipFile, $this->currentOffset, SEEK_SET);

				$ds = 0;
				
				$this->crc32_finalize();
				
				// write file entry header (overwrites first 10 bytes of compressed data, since it must be cut off)
				$ds += fwrite($this->zipFile, pack('VvvvVVVVvva*', 
					0x04034B50,					// ( 0) file entry identifier
					20, 						// ( 4) version (0x0014)
					0,							// ( 6) general purpose flags
					8, 							// ( 8) compression algorithm (0x0008)
					$this->entryTime,			// (10) time in dos format
					$this->entryCrc,			// (14) crc32 checksum
					$compressedSize,			// (18) compressed size
					$this->entrySize,			// (22) uncompressed size
					strlen($this->entryName),	// (26) file name length
					0,							// (28) extra field length
					$this->entryName			// (30) name of the file
				));
				
				// write index entry
				$this->indexSize += fwrite($this->idxFile, pack('VvvvvVVVVvvvvvVVa*',
					0x02014B50,					// ( 0) index entry identifier
					0,							// ( 4) version of creator?
					20,							// ( 6) version (0x0014)
					0,							// ( 8) general purpose flags
					8,							// (10) compression method (0x0008)
					$this->entryTime,			// (12) time in dos format
					$this->entryCrc,			// (16) crc32 checksum
					$compressedSize,			// (20) compressed size
					$this->entrySize,			// (24) uncompressed size
					strlen($this->entryName),	// (28) file name length
					0,							// (30) extra field length
					0,							// (32) comment length
					0,							// (34) first archive part number
					0,							// (36) compressed data attributes
					$this->entryAttribs,		// (38) file attributes
					$this->currentOffset,		// (42) offset to the file entry
					$this->entryName			// (46) name of the file
				));
				
				$this->currentOffset += $ds + $compressedSize;
				fseek($this->zipFile, $this->currentOffset, SEEK_SET);
				$this->entryCount++;
			}
			else {
				fclose($this->entryFile);
			}
			$this->entryFile = null;
			@fclose($this->entryFileExt);
			$this->entryFileExt = null;
		}
	}
	
	public function entrySeek($offset, $whence) {
		if( !$this->entryFile )
			return false;
		return $this->writeMode ? gzseek($this->entryFile, $offset, $whence) : fseek($this->entryFile, $offset, $whence);
	}
	
	public function entryTell() {
		if( !$this->entryFile )
			return false;
		return $this->writeMode ? gztell($this->entryFile) : ftell($this->entryFile);
	}
	
	public function entryEOF() {
		if( !$this->entryFile )
			return true;
		return $this->writeMode ? gzeof($this->entryFile) : feof($this->entryFile);
	}
	
	public function entryFileSize() {
		return $this->entrySize;
	}
	
	public function entryWriteFromFile($from, $byteCount, $blockSize = 65536) {
		$left = $byteCount;
		if( $blockSize < 1 )
			$blockSize = 1;
		while( $left > 0 ) {
			$sz = min($left, $blockSize);
			$rbytes = fread($from, $sz);
			$wbytes = $this->entryWrite($rbytes);
			if( $wbytes < $sz ) {
				if( $wbytes <= 0 )
					break; // some error occured while writing (out of disk space?)
				fseek($this->idxFile, $left - $wbytes, SEEK_CUR);
			}
			$left -= $wbytes;
		}
		return $byteCount - $left;
	}
	
	public function entryReadToFile($to, $byteCount, $blockSize = 65536) {
		$left = $byteCount;
		if( $blockSize < 1 )
			$blockSize = 1;
		while( $left > 0 ) {
			$sz = min($left, $blockSize);
			$rbytes = $this->entryRead($sz);
			$wbytes = fwrite($to, $rbytes);
			if( $wbytes < $sz ) {
				if( $wbytes <= 0 )
					break; // some error occured while writing (out of disk space?)
				fseek($this->idxFile, $left - $wbytes, SEEK_CUR);
			}
			$left -= $wbytes;
		}
		return $byteCount - $left;
	}
	
	public function addFile($fileName, $internalName = null, $time = null, $attributes = null) {
		$fileName = str_replace('\\', '/', $fileName);
		if( $internalName === null ) {
			$baseDir = @realpath(rtrim(str_replace("\\", "/", $this->baseDir), "/") . "/");
			$name = @realpath(ltrim(str_replace("\\", "/", $fileName), "/"));
			if( $baseDir && $name && substr($name, 0, strlen($baseDir)) == $baseDir )
				$internalName = ltrim(substr($name, strlen($baseDir)), "/");
			if( !$internalName )
				$internalName = preg_replace('#^(/|./|../)+#', '', $fileName);
		}
		if( $time === null )
			$time = filemtime($fileName);
		$fp = fopen($fileName, "rb");
		if( !$fp )
			return false;
		$size = filesize($fileName);
		$this->entryOpen($internalName, $time, $attributes);
		$bytes = $this->entryWriteFromFile($fp, $size);
		$this->entryClose();
		fclose($fp);
		return $bytes === $size;
	}
	
	public function addString($internalName, $data, $time = null, $attributes = null) {
		$this->entryOpen($internalName, $time, $attributes);
		$bytes = $this->entryWrite($data);
		$this->entryClose();
		return strlen($data) === $bytes;
	}
	
	
	public function extractString($internalName) {
		return file_get_contents("zip://{$this->zipFileName}#{$internalName}");
	}
	
	public function extractFile($internalName, $fileName = null) {
		if( !$fileName )
			$fileName = rtrim(str_replace("\\", "/", $this->baseDir), "/") . "/" . ltrim(str_replace("\\", "/", $internalName), "/");
		$fp = fopen($fileName, "wb");
		if( !$fp )
			return false;
		$entry = $this->indexFind($internalName);
		if( !$entry )
			return false;
		
		$this->entryOpen($internalName);
		$bytes = $this->entryReadToFile($fp, $entry["size"]);
		$this->entryClose();
		fclose($fp);
		return $bytes === $entry["size"];
	}
	
	
	
	protected function crc32_init() {
		if ($this->entryCrcHasher) {
			$this->crc32_finalize();
		}
		$this->entryCrc = 0xFFFFFFFF;
		$this->entryCrcHasher = hash_init("crc32b");
	}
	
	protected function crc32_finalize() {
		if ($this->entryCrcHasher) {
			$this->entryCrc = hexdec(hash_final($this->entryCrcHasher, false));
		}
		$this->entryCrcHasher = null;
	}
	
	protected function crc32_add($str) {
		if ($this->entryCrcHasher) hash_update($this->entryCrcHasher, $str);
	}
	
	public static function copyBytes($from, $to, $byteCount, $blockSize = 65536) {
		// Do not use stream_copy_to_stream(). For some reason it hangs up the script.
		$left = $byteCount;
		if( $blockSize < 1 )
			$blockSize = 1;
		while( $left > 0 ) {
			$sz = min($left, $blockSize);
			$rbytes = fread($from, $sz);
			$wbytes = fwrite($to, $rbytes);
			if( $wbytes < $sz ) {
				if( $wbytes <= 0 )
					break; // some error occured while writing (out of disk space?)
				fseek($this->idxFile, $left - $wbytes, SEEK_CUR);
			}
			$left -= $wbytes;
		}
		return $byteCount - $left;
	}

	public static function timeconv($unixtime) {
		$arr = getdate($unixtime);
		$arr["year"] -= 1980;
		if( $arr['year'] < 0 ) {
			$arr['year'] = $arr['hours'] = $arr['minutes']	= $arr['seconds'] = 0;
			$arr['mon'] = $arr['mday'] = 1;
		}
		return ($arr['year'] << 25) | ($arr['mon'] << 21) | ($arr['mday'] << 16) | ($arr['hours'] << 11) | ($arr['minutes'] << 5) | ($arr['seconds'] >> 1);
	}
}
 
// http://www.php.net/manual/en/class.streamwrapper.php
// This class is needed if you want to use fread/fwrite on files, archived inside the zip.
// It may help when you need to pass a file resource to some parsing functions.
final class BigZipStream {
	public static $instance = null;
	private $zip = null;

	function stream_open($path, $mode, $options, &$opened_path) {
		if( !self::$instance || !is_a(self::$instance, "BigZip") )
			return false;
		$this->zip = self::$instance;
		return true;
	}
	
	function stream_close() {
		$this->zip->entryClose();
	}
	
	function stream_read($count) {
		return $this->zip->entryRead($count);
	}
	
	function stream_write($data) {
		return $this->zip->entryWrite($data);
	}
	
	function stream_tell() {
		return $this->zip->entryTell();
	}
	
	function stream_eof() {
		return $this->zip->entryEOF();
	}
	
	function stream_seek($offset, $whence) {
		return $this->zip->entrySeek($offset, $whence);
	}
	
	function stream_metadata($path, $option, $var) {
		if( $option == STREAM_META_TOUCH ) {
			return true;
		}
		return false;
	}
	
	function stream_cast($castAs) {
		return false;
	}
}
	
final class BigZipInternalStream {
	public static $resource = null;
	public static $dataOffset = 0;
	
	private $res;
	private $offset;
	
	function stream_open($path, $mode, $options, &$opened_path) {
		$this->res = self::$resource;
		$this->offset = self::$dataOffset;
		if( $this->res )
			fseek($this->res, $this->offset, SEEK_SET);
		return $this->res ? true : false;
	}
	
	function stream_close() {
		$this->res = null;
		$this->offset = 0;
	}
	
	function stream_read($count) {
		return fread($this->res, $count);
	}
	
	function stream_write($data) {
		return fwrite($this->res, $data);
	}
	
	function stream_tell() {
		return ftell($this->res) - $this->offset;
	}
	
	function stream_eof() {
		return true;
	}
	
	function stream_seek($offset, $whence) {
		if( $whence == SEEK_SET )
			return fseek($this->res, $this->offset + $offset, $whence);
		return fseek($this->res, $offset, $whence);
	}
	
	function stream_metadata($path, $option, $var) {
		if( $option == STREAM_META_TOUCH ) {
			return true;
		}
		return false;
	}
	
	function stream_cast($castAs) {
		return $this->res;
	}
}
