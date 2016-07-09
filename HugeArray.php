<?php
/*
 * Copyright 2016 Viacheslav Soroka
 * Version: 2.1.0
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
 * WARNING 1: Do not use same file concurrently in two scripts running at the same time
 * or the array WILL get messed up.
 *
 * WARNING 2: Do not use references to array elements. It won't work as expected.
 *
 * WARNING 3: Do not rely on array_key_exists() function since it does not work properly with ArrayAccess objects.
 * Use exists() method of this class instead.
 */
class HugeArray implements ArrayAccess, Countable {
	const FILE_VERSION = 1;
	const FILE_HEADER_SIZE = 12;
	const FILE_HEADER_COUNTER_OFFSET = 8; // location, where count of array elements is located
	const FILE_HEADER_ID = 'HARR';
	const POINTER_TYPE = 'V'; // 'V' for 32bit pointers or 'P' for 64bit pointers (supported only on 64bit PHP 5.6+).
	const POINTER_SIZE = 4;
	const NODE_SIZE = 13; // = 1 + self::POINTER_SIZE * 3

	const VALUE_TYPE_UNSET = 0;
	const VALUE_TYPE_NULL = 1;
	const VALUE_TYPE_FALSE = 2;
	const VALUE_TYPE_TRUE = 3;
	const VALUE_TYPE_ZERO = 4;
	const VALUE_TYPE_EMPTY_STRING = 5;
	const VALUE_TYPE_EMPTY_ARRAY = 6;
	const VALUE_TYPE_SERIALIZED_DATA = 7;

	/** @var null|string */
	protected $fileName = null;

	/** @var null|resource */
	protected $file = null;

	/** @var int */
	protected $fileEnd = 0;

	/** @var int */
	protected $currentNode = 0;

	/** @var int[] */
	protected $nodePath = [];

	/** @var int */
	protected $itemCount = 0;

	/**
	 * HugeArray constructor.
	 * @param string|null $fileName Path to a file to use as an array storage. If path is not specified a new temporary file is used. Otherwise the array will contain data stored in specified file during previous sessions.
	 * @throws Exception In case if the file could not be open with read+write access, or opened an existing with incorrect header or version.
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
		if( $this->fileEnd == 0 )
			$this->clear();

		if( $this->fileEnd < self::FILE_HEADER_SIZE + self::NODE_SIZE )
			throw new Exception('File ' . $fileName . ' is too small to be a HugeArray file.') ;
		fseek($this->file, 0, SEEK_SET);
		$header = unpack('a4id/Vversion/Vcount', fread($this->file, self::FILE_HEADER_SIZE));
		if( $header['id'] != self::FILE_HEADER_ID )
			throw new Exception('File ' . $fileName . ' is not a HugeArray file.') ;
		if( $header['version'] != self::FILE_VERSION )
			throw new Exception('File ' . $fileName . ' version is not compatible with current HugeArray version. File version = ' . $header['version'] . ', supported versions = ' . self::FILE_VERSION . '.') ;
		$this->itemCount = $header['count'];
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
	 * @param callable $callback (function(bool $bit):bool) A callable that will receive all bits of the given key one by one in their order. Callable will receive only one parameter: bitValue. Callable must return a boolean value. If it returns FALSE the enumeration ends and this method returns FALSE.
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
	 * Writes an empty node bytes at the current file seek offset.
	 *
	 * @return int|false Size of the node in bytes or FALSE if node could not be written.
	 */
	protected function writeNewNode() {
		$size = fwrite($this->file, pack(
			"C" . self::POINTER_TYPE . self::POINTER_TYPE . self::POINTER_TYPE,
			self::VALUE_TYPE_UNSET, // type of the value assigned to the node
			0, // pointer to allocated value block
			0, // pointer to child FALSE node
			0  // pointer to child TRUE node
		));
		return ($size == self::NODE_SIZE) ? self::NODE_SIZE : false;
	}

	/**
	 * Moves file pointer to location where offset to data block is stored and returns an address of the data block.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @param bool $create TRUE to create key bit nodes in the file while iterating through them.
	 * @return int|false File offset of the last node if all offset nodes exist or were created. FALSE if node does not exist.
	 * @throws Exception In case of an invalid key or if creating a new node failed.
	 */
	protected function moveToOffsetNode($offset, $create) {
		$pointer = self::FILE_HEADER_SIZE;

		if( !self::EnumerateBits($offset, function($bit) use(&$pointer, $create) {
			$referencePointer = $pointer + 1 + self::POINTER_SIZE * ($bit ? 2 : 1);
			fseek($this->file, $referencePointer, SEEK_SET);
			$pointer = unpack(self::POINTER_TYPE, fread($this->file, self::POINTER_SIZE));
			$pointer = $pointer[1];

			if( !$pointer ) {
				if( !$create )
					return false;
				$pointer = $this->fileEnd;

				fseek($this->file, $pointer, SEEK_SET);
				$size = $this->writeNewNode();
				if( !$size )
					throw new Exception('Failed to add a new node to the key tree.');

				fseek($this->file, $referencePointer, SEEK_SET);
				fwrite($this->file, pack(self::POINTER_TYPE, $pointer));

				$this->fileEnd += $size;
			}

			return true;
		}) ) {
			return false;
		}

		fseek($this->file, $pointer, SEEK_SET);
		return $pointer;
	}

	/**
	 * Resets file pointer to the root node of the tree.
	 *
	 * @return bool Always returns TRUE.
	 */
	public function seekReset() {
		$this->currentNode = self::FILE_HEADER_SIZE;
		$this->nodePath = [];
		return true;
	}

	/**
	 * Changes file pointer to the next TRUE or FALSE child node of the current node depending on provided bit value.
	 *
	 * @param bool $bit TRUE to seek to the TRUE child node and FALSE to seek to the FALSE child node.
	 * @return bool TRUE if seeking was successful or FALSE if node for provided bit does not exist.
	 */
	public function seekToNextNode($bit) {
		fseek($this->file, $this->currentNode + 1 + self::POINTER_SIZE * ($bit ? 2 : 1), SEEK_SET);
		$pointer = unpack(self::POINTER_TYPE, fread($this->file, self::POINTER_SIZE));
		if( !$pointer[1] )
			return false;
		$this->nodePath[] = $this->currentNode;
		$this->currentNode = $pointer[1];
		return true;
	}

	/**
	 * Change file pointer to parent node.
	 *
	 * @return bool TRUE if pointer was successfully changed to parent node or FALSE if there is no parent node.
	 */
	public function seekBack() {
		if( empty($this->nodePath) )
			return false;
		$this->currentNode = array_pop($this->nodePath);
		return true;
	}

	/**
	 * Returns information about value stored in node at current file seek offset.
	 *
	 * @return int[] An array containing two elements: value type and a pointer to an allocated block of serialized data. Value type is one of HugeArray::VALUE_TYPE_* constants. Pointer to data block may be present even if value is not set. This is needed to keep pointer to an unused block that may potentially be reused in the future.
	 */
	protected function readNodeValueInfo() {
		return unpack('Ctype/' . self::POINTER_TYPE . 'pointer', fread($this->file, 1 + self::POINTER_SIZE));
	}

	/**
	 * Returns information about value stored in current node.
	 *
	 * @return int[] An array containing two elements: value type and a pointer to an allocated block of serialized data. Value type is one of HugeArray::VALUE_TYPE_* constants. Pointer to data block may be present even if value is not set. This is needed to keep pointer to an unused block that may potentially be reused in the future.
	 */
	public function getCurrentNodeValueInfo() {
		fseek($this->file, $this->currentNode, SEEK_SET);
		return $this->readNodeValueInfo();
	}

	/**
	 * Reads data block from specified file offset.
	 *
	 * No validation is performed so be sure to provide a valid file offset.
	 *
	 * @param int[] $valueInfo An array containing information about value. May be obtained using HugeArray::getCurrentNodeValueInfo() method.
	 * <code>
	 * int [type] - Value type. One of HugeArray::VALUE_TYPE_* constants.
	 * int [pointer] - Pointer to an allocated data block. Used only when type is HugeArray::VALUE_TYPE_SERIALIZED_DATA.
	 * </code>
	 * @return mixed Value according to given value info. Returns NULL if value type is HugeArray::VALUE_TYPE_UNSET.
	 */
	protected function getValueFromValueInfo($valueInfo) {
		switch( $valueInfo['type'] ) {
			case self::VALUE_TYPE_FALSE: return false;
			case self::VALUE_TYPE_TRUE: return true;
			case self::VALUE_TYPE_ZERO: return 0;
			case self::VALUE_TYPE_EMPTY_STRING: return '';
			case self::VALUE_TYPE_EMPTY_ARRAY: return [];
			case self::VALUE_TYPE_SERIALIZED_DATA: {
				fseek($this->file, $valueInfo['pointer'] + 4, SEEK_SET); // we need used byte count, not allocated, so we add 4 to offset
				$size = unpack('V', fread($this->file, 4));
				$size = $size[1];
				return unserialize(fread($this->file, $size));
			}
		}
		return null;
	}

	/**
	 * Returns value stored in the current node.
	 *
	 * @return mixed Value stored in the current node. Returns NULL if node value is not set.
	 */
	public function getCurrentNodeValue() {
		return $this->getValueFromValueInfo($this->getCurrentNodeValueInfo());
	}

	/**
	 * Checks if array offset exists.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @return bool TRUE if offset exists. FALSE otherwise.
	 * @throws Exception In case of an invalid key.
	 */
	public function exists($offset) {
		$pointer = $this->moveToOffsetNode($offset, false);
		if( !$pointer )
			return false;
		$valueInfo = unpack('C', fread($this->file, 1));
		return $valueInfo[1] != self::VALUE_TYPE_UNSET;
	}

	/**
	 * Checks if array offset exists ant it is not NULL.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @return bool TRUE if offset exists and not NULL. FALSE otherwise.
	 * @throws Exception In case of an invalid key.
	 */
	public function offsetExists($offset) {
		$pointer = $this->moveToOffsetNode($offset, false);
		if( !$pointer )
			return false;
		$valueInfo = unpack('C', fread($this->file, 1));
		return $valueInfo[1] != self::VALUE_TYPE_UNSET && $valueInfo[1] != self::VALUE_TYPE_NULL;
	}

	/**
	 * Returns the value stored in the array.
	 *
	 * This method triggers E_USER_NOTICE if the specified offset does not exist in the array.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @return mixed|null Value stored in the array or NULL if the key does not exist.
	 * @throws Exception In case of an invalid key.
	 */
	public function offsetGet($offset) {
		$pointer = $this->moveToOffsetNode($offset, false);
		if( $pointer ) {
			$valueInfo = $this->readNodeValueInfo();
			if( $valueInfo['type'] != self::VALUE_TYPE_UNSET )
				return $this->getValueFromValueInfo($valueInfo);
		}
		trigger_error("Offset '{$offset}' does not exist in the array.", E_USER_NOTICE);
		return null;
	}

	/**
	 * Returns the value stored in the array.
	 *
	 * Unlike offsetGet() method this method will return provided default value instead of triggering E_USER_NOTICE for non-existing offset.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @param mixed $default The value to return if offset does not exist.
	 * @return mixed|null Value stored in the array or NULL if the key does not exist.
	 * @throws Exception In case of an invalid key.
	 */
	public function tryGet($offset, $default = null) {
		$pointer = $this->moveToOffsetNode($offset, false);
		if( $pointer ) {
			$valueInfo = $this->readNodeValueInfo();
			if( $valueInfo['type'] != self::VALUE_TYPE_UNSET )
				return $this->getValueFromValueInfo($valueInfo);
		}
		return $default;
	}

	/**
	 * Stores a value to the array.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @param mixed|null $value Value to store.
	 * @throws Exception In case of an invalid key or if the value could not be stored due to file write errors.
	 */
	public function offsetSet($offset, $value) {
		$pointer = $this->moveToOffsetNode($offset, true);
		$valueInfo = $this->readNodeValueInfo();
		$newValueInfo = $valueInfo;

		if( $valueInfo['type'] != self::VALUE_TYPE_UNSET && $valueInfo['type'] != self::VALUE_TYPE_SERIALIZED_DATA ) {
			$currentValue = $this->getValueFromValueInfo($valueInfo);
			if( $currentValue === $value )
				return; // stored value is not something that needs to be serialized and it is identical to value that is being stored
		}

		if( $value === null )
			$newValueInfo['type'] = self::VALUE_TYPE_NULL;
		else if( $value === false )
			$newValueInfo['type'] = self::VALUE_TYPE_FALSE;
		else if( $value === true )
			$newValueInfo['type'] = self::VALUE_TYPE_TRUE;
		else if( $value === 0 )
			$newValueInfo['type'] = self::VALUE_TYPE_ZERO;
		else if( $value === '' )
			$newValueInfo['type'] = self::VALUE_TYPE_EMPTY_STRING;
		else if( $value === [] )
			$newValueInfo['type'] = self::VALUE_TYPE_EMPTY_ARRAY;
		else {
			$newValueInfo['type'] = self::VALUE_TYPE_SERIALIZED_DATA;

			$allocatedSize = 0;
			if( $valueInfo['pointer'] ) {
				fseek($this->file, $valueInfo['pointer'], SEEK_SET);
				$allocatedSize = unpack('V', fread($this->file, 4));
				$allocatedSize = $allocatedSize[1];
			}

			$serialized = serialize($value);
			$serializedSize = strlen($serialized);

			if( $allocatedSize < $serializedSize )
				$newValueInfo['pointer'] = $this->fileEnd;

			if( $newValueInfo['pointer'] != $valueInfo['pointer'] ) {
				fseek($this->file, $newValueInfo['pointer'], SEEK_SET);
				if( 4 != fwrite($this->file, pack('V', $serializedSize)) ) {// store the number of bytes allocated in the block
					ftruncate($this->file, $this->fileEnd);
					throw new Exception('Error storing the value.');
				}
			}
			else
				fseek($this->file, $newValueInfo['pointer'] + 4, SEEK_SET);

			if( 4 != fwrite($this->file, pack('V', $serializedSize)) ) { // store number of bytes used by serialized data
				ftruncate($this->file, $this->fileEnd);
				throw new Exception('Error storing the value.');
			}

			if( $serializedSize != fwrite($this->file, $serialized) ) {
				ftruncate($this->file, $this->fileEnd);
				throw new Exception('Error storing the value.');
			}

			if( $newValueInfo['pointer'] != $valueInfo['pointer'] )
				$this->fileEnd += $serializedSize + 8;
		}

		if( $valueInfo['type'] != $newValueInfo['type'] ) {
			if( $valueInfo['pointer'] != $newValueInfo['pointer'] ) {
				fseek($this->file, $pointer, SEEK_SET);
				fwrite($this->file, pack('C' . self::POINTER_TYPE, $newValueInfo['type'], $newValueInfo['pointer']));
			}
			else {
				fseek($this->file, $pointer, SEEK_SET);
				fwrite($this->file, pack('C', $newValueInfo['type']));
			}
		}
		else if( $valueInfo['pointer'] != $newValueInfo['pointer'] ) {
			fseek($this->file, $pointer + 1, SEEK_SET);
			fwrite($this->file, pack(self::POINTER_TYPE, $newValueInfo['pointer']));
		}

		if( $valueInfo['type'] == self::VALUE_TYPE_UNSET ) {
			$this->itemCount++;
			fseek($this->file, self::FILE_HEADER_COUNTER_OFFSET, SEEK_SET);
			fwrite($this->file, pack('V', $this->itemCount));
		}

		fflush($this->file);
	}

	/**
	 * Reads a value from the array, calls callback function and updates the value in the array depending on response returned from the callback.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @param callable $callback (function(bool $exists, mixed $value):array) A callable that will receive two parameters: wherther offset exists and a stored value if it exists. Callback must return an indexed array [$exists, $value], where $exists tells if value must exist after update and $value contains the value that must be stored in place of previous one.
	 * @param bool $create If this parameter is set to FALSE (default) then the callback will not be called if offset does not exist. If set to TRUE then all offset nodes for given offset will be created in the file if the offset doesn't exist. Even if callback returns a response specifying that the value must be removed.
	 * @throws Exception In case of an invalid key or if the value could not be stored due to file write errors, or callback returns bad response.
	 */
	public function update($offset, $callback, $create = false) {
		$pointer = $this->moveToOffsetNode($offset, $create);
		if( !$pointer )
			return;

		$valueInfo = $this->readNodeValueInfo();

		$exists = $valueInfo['type'] != self::VALUE_TYPE_UNSET;
		$currentValue = $this->getValueFromValueInfo($valueInfo);
		$response = $callback($exists, $currentValue);
		if( !is_array($response) || !array_key_exists(0, $response) || !array_key_exists(1, $response) )
			throw new Exception('Callback must return an indexed array with two elements [exists, value].');

		if( $exists == $response[0] && (!$exists || $currentValue === $response[1]) )
			return; // value does not change, so we don't have to do anything
		unset($exists, $currentValue);

		$newValueInfo = $valueInfo;

		if( !$response[0] )
			$newValueInfo['type'] = self::VALUE_TYPE_UNSET;
		else if( $response[1] === null )
			$newValueInfo['type'] = self::VALUE_TYPE_NULL;
		else if( $response[1] === false )
			$newValueInfo['type'] = self::VALUE_TYPE_FALSE;
		else if( $response[1] === true )
			$newValueInfo['type'] = self::VALUE_TYPE_TRUE;
		else if( $response[1] === 0 )
			$newValueInfo['type'] = self::VALUE_TYPE_ZERO;
		else if( $response[1] === '' )
			$newValueInfo['type'] = self::VALUE_TYPE_EMPTY_STRING;
		else if( $response[1] === [] )
			$newValueInfo['type'] = self::VALUE_TYPE_EMPTY_ARRAY;
		else {
			$newValueInfo['type'] = self::VALUE_TYPE_SERIALIZED_DATA;

			$allocatedSize = 0;
			if( $valueInfo['pointer'] ) {
				fseek($this->file, $valueInfo['pointer'], SEEK_SET);
				$allocatedSize = unpack('V', fread($this->file, 4));
				$allocatedSize = $allocatedSize[1];
			}

			$serialized = serialize($response[1]);
			unset($response);

			$serializedSize = strlen($serialized);

			if( $allocatedSize < $serializedSize )
				$newValueInfo['pointer'] = $this->fileEnd;

			if( $newValueInfo['pointer'] != $valueInfo['pointer'] ) {
				fseek($this->file, $newValueInfo['pointer'], SEEK_SET);
				if( 4 != fwrite($this->file, pack('V', $serializedSize)) ) {// store the number of bytes allocated in the block
					ftruncate($this->file, $this->fileEnd);
					throw new Exception('Error storing the value.');
				}
			}
			else
				fseek($this->file, $newValueInfo['pointer'] + 4, SEEK_SET);

			if( 4 != fwrite($this->file, pack('V', $serializedSize)) ) { // store number of bytes used by serialized data
				ftruncate($this->file, $this->fileEnd);
				throw new Exception('Error storing the value.');
			}

			if( $serializedSize != fwrite($this->file, $serialized) ) {
				ftruncate($this->file, $this->fileEnd);
				throw new Exception('Error storing the value.');
			}

			if( $newValueInfo['pointer'] != $valueInfo['pointer'] )
				$this->fileEnd += $serializedSize + 8;
		}

		if( $valueInfo['type'] != $newValueInfo['type'] ) {
			if( $valueInfo['pointer'] != $newValueInfo['pointer'] ) {
				fseek($this->file, $pointer, SEEK_SET);
				fwrite($this->file, pack('C' . self::POINTER_TYPE, $newValueInfo['type'], $newValueInfo['pointer']));
			}
			else {
				fseek($this->file, $pointer, SEEK_SET);
				fwrite($this->file, pack('C', $newValueInfo['type']));
			}
		}
		else if( $valueInfo['pointer'] != $newValueInfo['pointer'] ) {
			fseek($this->file, $pointer + 1, SEEK_SET);
			fwrite($this->file, pack(self::POINTER_TYPE, $newValueInfo['pointer']));
		}

		if( $valueInfo['type'] == self::VALUE_TYPE_UNSET ) {
			$this->itemCount++;
			fseek($this->file, self::FILE_HEADER_COUNTER_OFFSET, SEEK_SET);
			fwrite($this->file, pack('V', $this->itemCount));
		}
		else if( $newValueInfo['type'] == self::VALUE_TYPE_UNSET ) {
			$this->itemCount--;
			fseek($this->file, self::FILE_HEADER_COUNTER_OFFSET, SEEK_SET);
			fwrite($this->file, pack('V', $this->itemCount));
		}

		fflush($this->file);
	}

	/**
	 * Removes an element from the array.
	 *
	 * @param string|int|bool|null $offset The key of the array.
	 * @throws Exception In case of an invalid key.
	 */
	public function offsetUnset($offset) {
		$pointer = $this->moveToOffsetNode($offset, false);
		if( !$pointer )
			return;

		$valueInfo = $this->readNodeValueInfo();
		if( $valueInfo['type'] == self::VALUE_TYPE_UNSET )
			return;

		fseek($this->file, $pointer, SEEK_SET);
		fwrite($this->file, pack('C', self::VALUE_TYPE_UNSET));

		$this->itemCount--;
		fseek($this->file, self::FILE_HEADER_COUNTER_OFFSET, SEEK_SET);
		fwrite($this->file, pack('V', $this->itemCount));
		fflush($this->file);
	}

	public function clear() {
		ftruncate($this->file, 0);
		fseek($this->file, 0, SEEK_SET);
		fwrite($this->file, pack(
			"a4VV",
			"HARR", // file type header
			self::FILE_VERSION, // version number
			0 // item count
		));
		$this->writeNewNode();
		fseek($this->file, self::FILE_HEADER_SIZE, SEEK_SET);
		$this->fileEnd = self::FILE_HEADER_SIZE + self::NODE_SIZE;
		$this->itemCount = 0;
		$this->seekReset();
	}

	public function count() {
		return $this->itemCount;
	}
}
