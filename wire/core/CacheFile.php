<?php

/**
 * ProcessWire CacheFile
 *
 * Class to manage individual cache files 
 * 
 * Each cache file creates it's own directory based on the '$id' given.
 * The dir is created so that secondary cache files can be created too, 
 * and these are automatically removed when the remove() method is called.
 *
 * ProcessWire 2.x 
 * Copyright (C) 2011 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */
class CacheFile {

	const cacheFileExtension = ".cache";
	const globalExpireFilename = "lastgood";

	/**
	 * Max secondaryID cache files that will be allowed in a directory before it starts removing them. 
	 *
	 */
	const maxCacheFiles = 999;

	protected $path; 
	protected $primaryID = '';
	protected $secondaryID = '';
	protected $cacheTimeSeconds = 0; 
	protected $globalExpireFile = '';
	protected $globalExpireTime = 0;
        protected $chmodFile = "0666";
        protected $chmodDir = "0777";


	/**
	 * Construct the CacheFile
	 * 
	 * @param string $path Path where cache files will be created 
	 * @param string|int $id An identifier for this particular cache, unique from others. 
	 * 	Or leave blank if you are instantiating this class for no purpose except to expire cache files (optional).
	 * @param int The number of seconds that this cache file remains valid
	 *
	 */ 
	public function __construct($path, $id, $cacheTimeSeconds) {

		$path = rtrim($path, '/') . '/';
		$this->globalExpireFile = $path . self::globalExpireFilename; 
		$this->path = $id ? $path . $id . '/' : $path;

		if(!is_dir($path)) {
			if(!@mkdir($path)) throw new WireException("Unable to create path: $path"); 
			if($this->chmodDir) chmod($path, octdec($this->chmodDir));
		}

		if(!is_dir($this->path)) {
			if(!@mkdir($this->path)) throw new WireException("Unable to create path: {$this->path}"); 
			if($this->chmodDir) chmod($this->path, octdec($this->chmodDir));
		}

		if(is_file($this->globalExpireFile)) {
			$this->globalExpireTime = @filemtime($this->globalExpireFile); 
		}

		$this->primaryID = $id ? $id : 'primaryID'; 
		$this->cacheTimeSeconds = (int) $cacheTimeSeconds; 
	}

	/**
	 * An extra part to be appended to the filename
	 *
	 * When a call to remove the cache is included, then all 'secondary' versions of it will be included too
	 *
	 */
	public function setSecondaryID($id) {
		if(!ctype_alnum("$id")) {
			$id = preg_replace('/[^-+_a-zA-Z0-9]/', '_', $id); 
		}
		$this->secondaryID = $id; 
		
	}

	/**
	 * Build a filename for use by the cache
	 *
	 * Filename takes this form: /path/primaryID/primaryID.cache
	 * Or /path/primaryID/secondaryID.cache
	 *
	 */
	protected function buildFilename() {
		$filename = $this->path; 
		if($this->secondaryID) $filename .= $this->secondaryID; 
			else $filename .= $this->primaryID; 
		$filename .= self::cacheFileExtension; 
		return $filename; 
	}

	/**
	 * Does the cache file exist?
	 *
	 */
	public function exists() {
		$filename = $this->buildFilename(); 	
		return is_file($filename); 
	}

	/**
	 * Get the contents of the cache based on the primary or secondary ID
	 *
	 */
	public function get() {

		$filename = $this->buildFilename();
		if(self::isCacheFile($filename) && $this->isCacheFileExpired($filename)) {
			$this->removeFilename($filename); 
			return false;
		}

		// note file_get_contents returns boolean false if file can't be opened (i.e. if it's locked from a write)
		return @file_get_contents($filename); 
	}

	/**
	 * Is the given cache filename expired?
	 *
	 */
	protected function isCacheFileExpired($filename) {
		if(!$mtime = @filemtime($filename)) return false;
		if(($mtime + $this->cacheTimeSeconds < time()) || ($this->globalExpireTime && $mtime < $this->globalExpireTime)) {
			return true;
		}
		return false;
	}


	/**
	 * Is the given filename a cache file?
	 *
	 */
	static protected function isCacheFile($filename) {
		$ext = self::cacheFileExtension; 
		if(is_file($filename) && substr($filename, -1 * strlen($ext)) == $ext) return true; 
		return false;
	}

	/**
	 * Saves $data to the cache
	 *
	 */
	public function save($data) {
		$filename = $this->buildFilename();

		if(!is_file($filename)) {
			$dirname = dirname($filename);
			$files = glob("$dirname/*.*"); 
			$numFiles = count($files); 
			if($numFiles >= self::maxCacheFiles) {
				// if the cache file doesn't already exist, and there are too many files here
				// then abort the cache save for security (we don't want to fill up the disk)
				// also go through and remove any expired files while we are here, to avoid
				// this limit interrupting more cache saves. 
				$o = '';
				foreach($files as $file) {
					if(self::isCacheFile($file) && $this->isCacheFileExpired($file)) 
						$this->removeFilename($file);
				}
				return false;
			}
		}

		$result = file_put_contents($filename, $data); 
		if($this->chmodFile) chmod($filename, octdec($this->chmodFile));
		return $result;
	}

	/**
	 * Removes all cache files for primaryID
	 *
	 * If any secondaryIDs were used, those are removed too
	 *
	 */
	public function remove() {

		$dir = new DirectoryIterator($this->path); 
		foreach($dir as $file) {
			if($file->isDir() || $file->isDot()) continue; 
			//if(strpos($file->getFilename(), self::cacheFileExtension)) @unlink($file->getPathname()); 
			if(self::isCacheFile($file->getPathname())) @unlink($file->getPathname()); 
		}

		return @rmdir($this->path); 
	}

	/**
	 * Removes just the given file, as opposed to remove() which removes the entire cache for primaryID
	 *
	 */
	protected function removeFilename($filename) {
		@unlink($filename); 
	}


	/**
	 * Remove all cache files in the given path, recursively
	 *
	 * @param string $path Full path to the directory you want to clear out 
	 * @param bool $rmdir Set to true if you want to also remove the directory
	 * @return int Number of files/dirs removed
	 *
	 */
	static public function removeAll($path, $rmdir = false) {

		$dir = new DirectoryIterator($path); 
		$numRemoved = 0;
	
		foreach($dir as $file) {
			
			if($file->isDot()) continue;

			$pathname = $file->getPathname();

			if($file->isDir()) {
				$numRemoved += self::removeAll($pathname, true); 

			} else if($file->isFile() && (self::isCacheFile($pathname) || ($file->getFilename() == self::globalExpireFilename))) {
				if(unlink($pathname)) $numRemoved++;
			}
		}

		if($rmdir && rmdir($path)) $numRemoved++;

		return $numRemoved;
	}

	/**
	 * Causes all cache files in this type to be immediately expired
 	 *
	 * Note it does not remove any files, only places a globalExpireFile with an mtime newer than the cache files
	 *
	 */
	public function expireAll() {
		$note = "The modification time of this file represents the time of the last usable cache file. " . 
			"Cache files older than this file are considered expired. " . date('m/d/y H:i:s');
		file_put_contents($this->globalExpireFile, $note); 
	}

	/**
	 * Set the octal mode for files created by CacheFile
	 *
	 */
	public function setChmodFile($mode) {
		$this->chmodFile = $mode;
	}

	/**
	 * Set the octal mode for dirs created by CacheFile
	 *
	 */
	public function setChmodDir($mode) {
		$this->chmodDir = $mode;
	}

	/**
	 * CacheFile classes return a string of their cache filename
	 *
	 */
	public function __toString() {
		return $this->buildFilename();
	}
}

