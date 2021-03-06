<?php

/**
 * ProcessWire WireUpload
 *
 * Saves uploads of single or multiple files, saving them to the destination path.
 * If the destination path does not exist, it will be created. 
 * 
 * ProcessWire 2.x 
 * Copyright (C) 2010 by Ryan Cramer 
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 * 
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

class WireUpload extends Wire {
	

	protected $name;
	protected $destinationPath; 
	protected $maxFiles;
	protected $completedFilenames = array(); 
	protected $overwrite; 
	protected $overwriteFilename = ''; // if specified, only this filename may be overwritten
	protected $targetFilename = '';
	protected $extractArchives = false; 
	protected $validExtensions = array(); 
	protected $badExtensions = array('php', 'php3', 'phtml', 'exe', 'cfm', 'shtml', 'asp', 'pl', 'cgi', 'sh'); 

	static protected $unzipCommand = 'unzip -j -qq -n /src/ -x __MACOSX .* -d /dst/';

	protected $errorInfo = array(
		UPLOAD_ERR_OK => 'Successful Upload',
		UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
		UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
		UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
		UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
		UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
		UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
		UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.'
	);

	
	public function __construct($name) {

		$this->setName($name); 
		$this->maxFiles = 0; 
		$this->overwrite = false; 
		$this->destinationPath = '';

		if($this->config->uploadBadExtensions) {
			$badExtensions = $this->config->uploadBadExtensions; 
			if(is_string($badExtensions) && $badExtensions) $badExtensions = explode(' ', $badExtensions); 
			if(is_array($badExtensions)) $this->badExtensions = array_merge($this->badExtensions, $badExtensions); 			
		}	

		if($this->config->uploadUnzipCommand) {
			self::setUnzipCommand($this->config->uploadUnzipCommand); 
		}
	
	}

	public function execute() {

		// returns array of files (multi file upload)

		if(!$this->name) throw new WireException("You must set the name for WireUpload before executing it"); 
		if(!$this->destinationPath) throw new WireException("You must set the destination path for WireUpload before executing it");

		$files = array();
		if(empty($_FILES) || !count($_FILES)) return $files; 

		$f =& $_FILES[$this->name]; 

		if(is_array($f['name'])) {
			// multi file upload
			$cnt = 0;
			foreach($f['name'] as $key => $name) {
				if(!$this->isValidUpload($f['name'][$key], $f['size'][$key], $f['error'][$key])) continue; 
				if(!$this->saveUpload($f['tmp_name'][$key], $f['name'][$key])) continue; 
				if($this->maxFiles && (++$cnt >= $this->maxFiles)) break;
			}

			$files = $this->completedFilenames; 

		} else {
			// single file upload
			if($this->isValidUpload($f['name'], $f['size'], $f['error'])) {
				$this->saveUpload($f['tmp_name'], $f['name']);  // returns filename or false
				$files = $this->completedFilenames; 
			}
		}


		return $files; 
	}

	protected function isValidExtension($name) {
		$pathInfo = pathinfo($name); 
		$extension = strtolower($pathInfo['extension']);

		if(in_array($extension, $this->badExtensions)) return false;
		if(in_array($extension, $this->validExtensions)) return true; 
		return false; 
	}

	protected function isValidUpload($name, $size, $error) { 
		$valid = false;

		if($error && $error != UPLOAD_ERR_NO_FILE) $this->error($this->errorInfo[$error]); 
			else if(!$size) $valid = false; // no data
			else if(!$this->isValidExtension($name)) $this->error('Invalid file extension, pleas use one of: ' . implode(', ', $this->validExtensions)); 
			else if($name[0] == '.') $valid = false; 
			else $valid = true; 

		return $valid; 
	}


	protected function checkDestinationPath() {
		if(!is_dir($this->destinationPath)) {
			$this->error("Destination path does not exist {$this->destinationPath}"); 
			/*
			if(!mkdir($this->destinationPath)) {
				$this->error("Unable to create directory: " . $this->destinationPath); 
				return false;
			}
			*/
		}
		return true; 
	}

	protected function getUniqueFilename($destination) {

		$cnt = 0; 
		$p = pathinfo($destination); 
		$basename = basename($p['basename'], ".$p[extension]"); 

		while(file_exists($destination)) {
			$cnt++; 
			$filename = "$basename-$cnt.$p[extension]"; 
			$destination = "$p[dirname]/$filename"; 
		}
	
		return $destination; 	
	}

        public function validateFilename($value, $extensions = array()) {

                $value = strtolower(basename($value));
                $value = preg_replace('/[^-a-zA-Z0-9_\.]/', '_', $value);
                $value = preg_replace('/__+/', '_', $value);
                $value = trim($value, "_");

		$p = pathinfo($value);
		$extension = $p['extension'];
		$basename = basename($p['basename'], ".$extension"); 
		// replace any dots in the basename with underscores
		$basename = trim(str_replace(".", "_", $basename), "_"); 
		$value = "$basename.$extension";

                if(count($extensions)) {
                        if(!in_array($extension, $extensions)) $value = false;
                }

                return $value;
        }


	protected function saveUpload($tmp_name, $filename) {

		if(!$this->checkDestinationPath()) return false; 
		$filename = $this->getTargetFilename($filename); 
		$filename = strtolower($this->validateFilename($filename));
		$destination = $this->destinationPath . $filename;
		$p = pathinfo($destination); 

		if(!$this->overwrite && $filename != $this->overwriteFilename) {
			// overwrite not allowed, so find a new name for it
			$destination = $this->getUniqueFilename($destination); 
			$filename = basename($destination); 
		}

		if(!move_uploaded_file($tmp_name, $destination)) {
			$this->error("Unable to move uploaded file to: $destination");
			return false;
		}

		if($this->config->chmodFile) chmod($destination, octdec($this->config->chmodFile));

	
		if($p['extension'] == 'zip' && $this->maxFiles != 1 && $this->extractArchives) {

			if(!$this->saveUploadZip($destination)) {
				$this->completedFilenames[] = $filename;

			} else {
				if(count($this->completedFilenames) == 1) return $this->completedFilenames[0];
			}

			return $this->completedFilenames; 

		} else {
			$this->completedFilenames[] = $filename; 
			return $filename; 
		}


	}

	protected function saveUploadZip($zipFile) {

		// unzip with command line utility

		$files = array(); 
		if(!self::$unzipCommand) return false; 

		$dir = dirname($zipFile) . '/';
		$tmpDir = $dir . '.zip_tmp/'; 

		if(!mkdir($tmpDir)) return $files; 

		$unzipCommand = self::$unzipCommand;	
		$unzipCommand = str_replace('/src/', escapeshellarg($zipFile), $unzipCommand); 
		$unzipCommand = str_replace('/dst/', $tmpDir, $unzipCommand); 
		$str = exec($unzipCommand); 
		
		$files = new DirectoryIterator($tmpDir); 	
		$cnt = 0; 

		foreach($files as $file) {

			if($file->isDot() || $file->isDir()) continue; 

			if(!$this->isValidUpload($file->getFilename(), $file->getSize(), UPLOAD_ERR_OK)) {
				unlink($file->getPathname()); 
				continue; 
			}

			//$destination = $dir . $file->getFilename(); 
			$basename = $file->getFilename(); 
			$basename = $this->validateFilename($basename, $this->validExtensions); 

			if($basename) $destination = $this->getUniqueFilename($dir . $basename); 
				else $destination = '';

			if($destination && rename($file->getPathname(), $destination)) {
				$this->completedFilenames[] = basename($destination); 
				$cnt++; 
			} else {
				unlink($file->getPathname()); 
			}
		}

		rmdir($tmpDir); 
		if(!$cnt) return false; 

		unlink($zipFile); 
		return true; 	
	}

	public function getCompletedFilenames() {
		return $this->completedFilenames; 
	}

	public function setTargetFilename($filename) {
		// target filename as destination
		// only useful for single uploads
		$this->targetFilename = $filename; 
	}

	protected function getTargetFilename($filename) {
		// given a filename, takes it's extension and combines it with that
		// if the targetFilename (if set). Otherwise returns the filename you gave it
		if(!$this->targetFilename) return $filename; 
		$pathInfo = pathinfo($filename); 
		$targetPathInfo = pathinfo($this->targetFilename); 
		return rtrim(basename($this->targetFilename, $targetPathInfo['extension']), ".") . "." . $pathInfo['extension'];
	}

	public function setOverwriteFilename($filename) {
		// only this filename may be overwritten if specified, i.e. myphoto.jpg
		// only useful for single uploads
		$this->overwrite = false; // required
		$this->overwriteFilename = strtolower($filename); 
		return $this; 
	}

	static public function setUnzipCommand($unzipCommand) {
		if(strpos($unzipCommand, '/src/') && strpos($unzipCommand, '/dst/')) 
			self::$unzipCommand = $unzipCommand; 
	}
	
	static public function getUnzipCommand() {
		return self::$unzipCommand; 
	}

	public function setValidExtensions(array $extensions) {
		foreach($extensions as $ext) $this->validExtensions[] = strtolower($ext); 
		return $this; 
	}

	public function setMaxFiles($maxFiles) {
		$this->maxFiles = (int) $maxFiles; 
		return $this; 
	}

	public function setOverwrite($overwrite) {
		$this->overwrite = $overwrite ? true : false; 
		return $this; 
	}

	public function setDestinationPath($destinationPath) {
		$this->destinationPath = $destinationPath; 
		return $this; 
	}

	public function setExtractArchives($extract = true) {
		$this->extractArchives = $extract; 
		$this->validExtensions[] = 'zip';
		return $this; 
	}

	public function setName($name) {
		$this->name = $this->fuel('sanitizer')->fieldName($name); 
		return $this; 
	}


}


