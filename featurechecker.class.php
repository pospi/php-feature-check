<?php
 /*===============================================================================
	PHP Feature Checker - main class
	----------------------------------------------------------------------------
	Recursively reads a directory for the presence of featurecheck.ini files.
	Any found files are parsed, and any encountered errors stored.

	This class should be as widely compatible as possible, since it is used for
	checking server features.
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
  ===============================================================================*/

class FeatureChecker
{
	// configuration / globals
	var $INI_FILENAME = 'featurecheck.ini';
	var $PHP_EXE = 'php';				// path to PHP executable

	var $checkerFiles = array();		// all INI filenames to parse & check

	var $requirements = array();	// all requirement errors, descriptions etc.
									// stored under filename & requirement name

	function FeatureChecker($dir = null)
	{
		if (isset($dir)) {
			$this->getFeatureCheckFiles($dir);
			$this->processRequirements();
		}
	}

	//==========================================================================
	//	Directory traversal. Reads all feature INI files recursively, to
	//	load requirements for top-level project and any dependencies.

	function getFeatureCheckFiles($dir)
	{
		$dir = $this->_fixDir($dir);

		$dh = @opendir($dir);
		if (!$dh) {
			return false;
		}

		while (($file = readdir($dh)) !== false) {
			if($file == $this->INI_FILENAME) {
				$this->checkerFiles[] = $dir . $file;
			} else if ($file != '.' && $file != '..' && is_dir($dir . $file)) {
				$this->getFeatureCheckFiles($dir . $file);
			}
		}
		closedir($dh);

		return true;
	}

	//==========================================================================
	//	INI file reading & parsing

	function processRequirements()
	{
		foreach ($this->checkerFiles as $file)
		{
			$this->checkRequirementINI($file);
		}
	}

	function checkRequirementINI($file)
	{
		$currHeading = null;	// currently parsing INI header name
		$inComments = false;	// true when still parsing a header's comment lines

		$currDescription = '';
		$currEval = '';

		$fileHandle = fopen($file, 'r');

		while(false !== $line = fgets($fileHandle)) {
			$line = rtrim($line, "\r\n");
			if($line === '') continue;

			if (preg_match('/^\s*\[([^\]]+)\]\s*$/i', $line, $matches)) {	// header found, assign it
				if (isset($currHeading)) {
					// finish off the old heading first, by storing its values
					$this->_storeRequirementValue($file, $currHeading, 'desc', $currDescription);
					
					$error = $this->_runRequirement($currEval);
					if ($error !== '0' && $error !== '1' && $error !== '2' && $error !== '3' && $error !== '4') {
						$error = '2';	// any string echoed must mean we are running PHP4.0.0 and it's exploded
					}
					$this->_storeRequirementValue($file, $currHeading, 'error', intval($error));
				}
				$currHeading = $matches[1];
				$inComments = true;
				$currDescription = '';
				$currEval = '';
			} else if ($currHeading) {									// not still parsing file header comments
				if ($inComments && preg_match('/^\s*#(.*)$/', $line, $matches)) {
					$currDescription .= trim($matches[1] . ' ');
				} else if ($inComments && preg_match('/^\s*(\w+)\s*=\s*(.+)\s*$/', $line, $matches)) {
					$this->_storeRequirementValue($file, $currHeading, $matches[1], rtrim($matches[2]));
				} else {
					$inComments = false;
					$currEval .= $line;
				}
			}
		}
			
		// also process the last heading
		if (isset($currHeading)) {
			$this->_storeRequirementValue($file, $currHeading, 'desc', $currDescription);
			
			$error = $this->_runRequirement($currEval);
			if ($error !== '0' && $error !== '1' && $error !== '2' && $error !== '3' && $error !== '4') {
				$error = '2';	// any string echoed must mean we are running PHP4.0.0 and it's exploded
			}
			$this->_storeRequirementValue($file, $currHeading, 'error', intval($error));
		}

		fclose($fileHandle);
	}

	function _storeRequirementValue($file, $reqName, $name, $value)
	{
		if (!isset($this->requirements[$file])) {
			$this->requirements[$file] = array();
		}
		if (!isset($this->requirements[$file][$reqName])) {
			$this->requirements[$file][$reqName] = array();
		}
		$this->requirements[$file][$reqName][$name] = $value;
	}

	function _runRequirement($code)
	{
		$command = $this->PHP_EXE . ' ' . dirname(__FILE__) . '/feature-test.php ' . escapeshellarg($code);
		$fork = popen($command, 'r');

		$output = '';
		while (!feof($fork)) {
			$output .= fread($fork, 1024);
		}

		pclose($fork);

		return $output;
	}

	//==========================================================================
	//	Util

	// ensures that a directory path has a trailing slash
	function _fixDir($dir)
	{
		$last = $dir[strlen($dir)-1];
		return $last == '/' || $last == '\\' ? $dir : $dir . '/';
	}
}
?>
