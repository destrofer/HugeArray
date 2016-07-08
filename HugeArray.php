<?php
/*
 * Copyright 2016 Viacheslav Soroka
 * Version: 1.0.1
 * 
 * This file is part of HugeArray project <https://github.com/destrofer/HugeArray>.
 * 
 * HugeArray is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * HugeArray is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with HugeArray.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * PHP class that implements array functionality but uses disk instead of memory to store data.
 *
 * WARNING: Do not use same file concurrently in two scripts running at the same time
 * or the array WILL get messed up.
 */
class HugeArray implements ArrayAccess {
	protected $fileName = null;
	protected $file = null;
	protected $fileEnd = 0;
	protected $currentNode = 0;
	protected $nodePath = [];

	/**
	 * HugeArray constructor.
	 * @param string|null $fileName Path to a file to use as an array storage. If path is not specified a new temporary file is used. Otherwise the array will contain data stored in specified file during previous sessions.
	 * @throws Exception In case if the file could not be open with read+write access.
	 */
	public function __construct($fileName = null) {
		$this->fileName = $fileName;
		$this->file = ($this->fileName === null) ? tmpfile() : fopen($fileName, 'cr+');
		if( !$this->file ) {
			if( $this->fileName === null )
				throw new Exception('Could not create the temporary file.');
			throw new Exception('Could not open the file ' . $fileName . ' for read+write.');
		}
		fseek($this->file, 0, SEEK_END);
		$this->fileEnd = ftell($this->file);
		if( $this->fileEnd == 0 ) {
			fwrite($this->file, "\0\0\0\0\0\0\0\0\0\0\0\0");
			$this->fileEnd = 12;
		}
		fseek($this->file, 0, SEEK_SET);
	}

	public function __destruct() {
		if( $this->file ) {
			fclose($this->file);
			if( $this->fileName !== null )
				chmod($this->fileName, 0777);
		}
	}

	/**
	 * Enumerates the bits of the given key.
	 *
	 * @param string|int|bool|null $key The key to be enumerated.
	 * @param callable $callback A callable that will receive all bits of the given key one by one in their order. Callable will receive only one parameter: bitValue. Callable must return a boolean value. If it returns FALSE the enumeration ends and this method returns FALSE.
	 * @return bool TRUE if enumeration ends successfully or FALSE if enumeration if stopped by $callback returning FALSE.
	 * @throws Exception In case of an invalid key.
	 */
	public static function EnumerateBits($key, $callback) {
		if( $key === null || $key === '' )
			return true;

		if( $key === false )
			$key = '0';
		else if( $key === true )
			$key = '1';
		else if( is_numeric($key) )
			$key = (string)$key;
		else if( !is_string($key) )
			throw new Exception('Cannot use ' . gettype($key) . ' as offset.');

		for( $i = 0, $len = strlen($key); $i < $len; $i++ ) {
			$code = ord($key[$i]);
			$bit = 128;
			for( $j = 0; $j < 8; $j++, $bit >>= 1 ) {
				if (!$callback((int)!!($code & $bit)))
					return false;
			}
		}
		return true;
	}

	/**
	 * Moves file pointer to location where offset to data block is stored and returns an address of the data block.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @param bool $create TRUE to create key bit nodes in the file while iterating through them.
	 * @return int|null Will return either an address of an allocated block of data, if it exists, 0 if all key nodes of the offset exist, but no data is allocated and NULL if some of key nodes are missing (only if $create=FALSE).
	 * @throws Exception In case of an invalid key.
	 */
	protected function seekToOffset($offset, $create) {
		$referencePointer = 0;
		$pointer = 0;

		// echo "starting at 0\n";
		if( !self::EnumerateBits($offset, function($bit) use(&$referencePointer, &$pointer, $create) {
			$referencePointer = $pointer + ($bit ? 8 : 4);
			fseek($this->file, $referencePointer, SEEK_SET);
			$pointer = unpack('V', fread($this->file, 4));
			$pointer = $pointer[1];
			// echo " read pointer {$pointer} from {$referencePointer} (bit = {$bit})\n";

			if( !$pointer ) {
				if( !$create )
					return false;
				$pointer = $this->fileEnd;

				// echo " storing pointer {$pointer} to {$referencePointer}\n";
				fseek($this->file, $referencePointer, SEEK_SET);
				fwrite($this->file, pack('V', $pointer));

				$this->fileEnd = $pointer + 12;
				fseek($this->file, $pointer, SEEK_SET);
				fwrite($this->file, "\0\0\0\0\0\0\0\0\0\0\0\0");
			}

			return true;
		}) ) {
			return null;
		}

		// echo "finished at {$pointer}\n";

		fseek($this->file, $pointer, SEEK_SET);
		$pointer = unpack('V', fread($this->file, 4));
		fseek($this->file, -4, SEEK_CUR);

		$pointer = $pointer[1];
		// echo "data at {$pointer}\n";

		return $pointer;
	}

	/**
	 * Resets file pointer to the root node of the tree.
	 *
	 * @return bool Always return TRUE.
	 */
	public function seekReset() {
		$this->currentNode = 0;
		// echo "resetting to root node at {$this->currentNode}\n";
		$this->nodePath = [];
		return true;
	}

	/**
	 * Changes file pointer to the next TRUE or FALSE node of the current node depending on provided bit value.
	 *
	 * @param bool $bit TRUE to seek to the TRUE node and FALSE to seek to the FALSE node.
	 * @return bool TRUE if seeking was successful or FALSE if node for provided bit does not exist.
	 */
	public function seekToNextNode($bit) {
		// echo "trying to seek to next {$bit} node\n";
		fseek($this->file, $this->currentNode + ($bit ? 8 : 4), SEEK_SET);
		$pointer = unpack('V', fread($this->file, 4));
		if( !$pointer[1] ) {
			// echo " no such node\n";
			return false;
		}
		$this->nodePath[] = $this->currentNode;
		$this->currentNode = $pointer[1];
		// echo " node found at {$this->currentNode}\n";
		return true;
	}

	public function seekBack() {
		if( empty($this->nodePath) )
			return false;
		$this->currentNode = array_pop($this->nodePath);
		// echo "traversing back to parent node at {$this->currentNode}\n";
		return true;
	}

	/**
	 * Returns pointer stored in current node to the allocated data block.
	 *
	 * @return int 0 if current node has no data block. File offset of the allocated data block otherwise.
	 */
	public function getCurrentNodeDataPointer() {
		fseek($this->file, $this->currentNode, SEEK_SET);
		$pointer = unpack('V', fread($this->file, 4));
		// echo "getting current node data pointer from {$this->currentNode} => {$pointer[1]}\n";
		return $pointer[1];
	}

	/**
	 * Reads data block from specified file offset.
	 *
	 * No validation is performed so be sure to provide a valid file offset.
	 *
	 * @param int $pointer Offset in the file.
	 * @return mixed|null NULL if offset evaluates to FALSE. Otherwise returns data stored in the specified block.
	 */
	public function getDataFromPointer($pointer) {
		if( !$pointer )
			return null;
		// echo "getting data from block at {$pointer}\n";
		fseek($this->file, $pointer + 4, SEEK_SET); // we need used byte count, not allocated, so we add 4 to offset
		$size = unpack('V', fread($this->file, 4));
		$size = $size[1];
		// echo "reading block of size {$size} from {$pointer}\n";
		$data = unserialize(fread($this->file, $size));
		return $data;
	}

	/**
	 * Reads data block stored at offset set in the current node.
	 *
	 * @return mixed|null NULL if node seeking is invalid or if data offset in the node is 0. Otherwise returns data stored in the allocated block.
	 */
	public function getCurrentNodeData() {
		return $this->getDataFromPointer($this->getCurrentNodeDataPointer());
	}

	/**
	 * Checks if array key exists and it is not NULL.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @return bool TRUE if element exists and not NULL. FALSE otherwise.
	 * @throws Exception In case of an invalid key.
	 */
	public function offsetExists($offset) {
		$result = !!$this->seekToOffset($offset, false);
		return $result;
	}

	/**
	 * Returns the data stored in the array.
	 *
	 * Please note that the method does not generate notice if the specified offset does not exist in the array.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @return mixed|null Data stored in the array or NULL if the key does not exist.
	 * @throws Exception In case of an invalid key.
	 */
	public function offsetGet($offset) {
		$pointer = $this->seekToOffset($offset, false);
		return $this->getDataFromPointer($pointer);
	}

	/**
	 * Stores data to the array.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @param mixed|null $value Data to store.
	 * @throws Exception In case of an invalid key.
	 */
	public function offsetSet($offset, $value) {
		$pointer = $this->seekToOffset($offset, $value !== null);
		if( $value === null ) {
			// setting to null is same as removing
			if( $pointer ) {
				// echo "removing pointer at " . ftell($this->file) . "\n";
				fwrite($this->file, pack('V', 0));
			}
			return;
		}

		$serialized = serialize($value);
		$serializedSize = strlen($serialized);

		$referenceAt = null;
		$size = 0;
		if( $pointer ) {
			// data for this offset already exists so read existing data block size
			$referenceAt = ftell($this->file);
			fseek($this->file, $pointer, SEEK_SET);
			$size = unpack('V', fread($this->file, 4));
			$size = $size[1];
			// echo "allocated block of {$size} bytes already exists at {$pointer}\n";
		}

		if( $size < $serializedSize ) {
			// the current block size is not enough to store a new value
			if( $referenceAt !== null ) // cursor position changed so we have to restore it
				fseek($this->file, $referenceAt, SEEK_SET);
			$pointer = $this->fileEnd;
			fwrite($this->file, pack('V', $pointer));
			$this->fileEnd += 8 + $serializedSize;
			// echo "existing block is not big enough ({$size}). creating another block of {$serializedSize} bytes at {$pointer} and storing new pointer to {$referenceAt}\n";
			fseek($this->file, $pointer, SEEK_SET); // set cursor to new block
			fwrite($this->file, pack('V', $serializedSize)); // since this is a newly allocated file block we must write down its size so later we would know what its max size is
		}
		else
			fseek($this->file, $pointer + 4, SEEK_SET); // set cursor to used block size

		// echo "storing {$serializedSize} bytes block at {$pointer}\n";
		fwrite($this->file, pack('V', $serializedSize));
		fwrite($this->file, $serialized);
		fflush($this->file);
	}

	/**
	 * Removes data from the array.
	 *
	 * Same as setting an element to NULL.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @throws Exception In case of an invalid key.
	 */
	public function offsetUnset($offset) {
		$this->offsetSet($offset, null);
	}

	public function clear() {
		ftruncate($this->file, 0);
		fseek($this->file, 0, SEEK_SET);
		fwrite($this->file, "\0\0\0\0\0\0\0\0\0\0\0\0");
		fseek($this->file, 0, SEEK_SET);
		$this->fileEnd = 12;
		$this->seekReset();
	}
}