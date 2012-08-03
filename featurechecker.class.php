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

	// array of output strings (for both HTML and text mode)
	var $TEMPLATES = array(
		'heading' => array(
			'<h1>%s</h1>',
			"\n%s\n",
		),
		'blockstart' => array(
			'<table cellpadding="0" cellspacing="0"><tr class="head"><th>Requirement</th><th>Status</th><th>Error message</th></tr>',
			"",
		),
		'blockend' => array(
			'</table><p class="%s">%d/%d requirement checks passed</p>',
			"\nRequirements %s (%d/%d passed)\n",
		),
		'categorystart' => array(
			"<tr><th colspan=\"3\">%s: %s</th></tr>\n",
			"    %s: %s\n",
		),
		'categoryend' => array(
			"",
			"",
		),
		'line' => array(
			"<tr class=\"err%4\$s %3\$s\"><td>%1\$s</td><td>[%2\$s]</td><td>%6\$s</td></tr>\n",
			"%5\$s%1\$s\t[%2\$s]\t%6\$s\n",
		),
	);

	var $checkerFiles = array();		// all INI filenames to parse & check
	var $ORedCategories = array();

	var $requirements = array();	// all requirement errors, descriptions etc.
									// stored under filename & requirement name

	// error handling - either ignore missing paths (default), or exit the current script
	var $abortOnError = false;
	var $sourcePath;

	function FeatureChecker($dir = null, $recurse = true)
	{
		$this->sourcePath = $dir;

		if (isset($dir)) {
			$this->getFeatureCheckFiles($dir, $recurse);
			$this->processRequirements();
		}
	}

	function setAbortOnErrors($abort = true)
	{
		$this->abortOnError = $abort;
	}

	//==========================================================================
	//	Directory traversal. Reads all feature INI files recursively, to
	//	load requirements for top-level project and any dependencies.

	function getFeatureCheckFiles($dir, $recurse = true)
	{
		$dir = $this->_fixDir($dir);

		$dh = @opendir($dir);
		if (!$dh) {
			if ($this->abortOnError) {
				echo "Source directory '{$this->sourcePath}' not found! Aborting.";
				exit(1);
			}
			return false;
		}

		while (($file = readdir($dh)) !== false) {
			if($file == $this->INI_FILENAME) {
				$this->checkerFiles[] = $dir . $file;
			} else if ($recurse && $file != '.' && $file != '..' && is_dir($dir . $file)) {
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
		if ($this->abortOnError && !count($this->checkerFiles)) {
			echo "No featurecheck.ini files found in {$this->sourcePath}! Aborting.";
			exit(1);
		}
		foreach ($this->checkerFiles as $file) {
			$this->checkRequirementINI($file);
		}
	}

	function checkRequirementINI($file)
	{
		$currHeading = null;	// currently parsing INI header name
		$inComments = false;	// true when still parsing a header's comment lines

		$currDescription = '';
		$currEval = '';

		$lastCategory = '';		// used to store 'anyincategory' parameter values

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
					$metaValue = rtrim($matches[2]);
					if ($metaValue == 'true') {
						$metaValue = true;
					} else if ($metaValue == 'false') {
						$metaValue = false;
					}

					// determine category ORness
					if ($matches[1] == 'category') {
						$lastCategory = $metaValue;
					}

					if ($matches[1] == 'anyincategory') {
						$this->ORedCategories[$lastCategory] = false;
					} else {
						$this->_storeRequirementValue($file, $currHeading, $matches[1], $metaValue);
					}
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
	//	Output & rendering
	//		:TODO: do this a lot more cleanly

	function getOutput()
	{
		$this->sortRequirements();

		$output = '';
		$checked = 0;
		$passed = 0;

		foreach ($this->requirements as $filename => $features)
		{
			$fileDir = dirname($filename);
			$lastCategory = null;			// previous category being processed
			$categoryIsOR = false;			// true if category should be considered as a single condition

			$output .= $this->drawTemplateString('heading', array(dirname($filename)));
			$output .= $this->drawTemplateString('blockstart');

			foreach ($features as $name => $attrs) {
				if (isset($attrs['category']) && $attrs['category'] != $lastCategory) {
					if ($lastCategory !== null) {
						$output .= $this->drawTemplateString('categoryend');
						if ($categoryIsOR && $this->ORedCategories[$lastCategory]) {
							$passed++;
						}
					}

					if (isset($this->ORedCategories[$attrs['category']])) {
						$categoryIsOR = true;
						$checked++;
					} else {
						$categoryIsOR = false;
					}

					$output .= $this->drawTemplateString('categorystart', array($attrs['category'], $categoryIsOR ? ' one must pass' : ' all must pass'));
					$lastCategory = $attrs['category'];
				}

				switch($attrs['error']) {
					case 0:
						$errorString = "Failed";
						break;
					case 2:
						$errorString = "Failed due to error";
						break;
					case 3:
						$errorString = "Warning";
						if (isset($attrs['allowwarning']) && $attrs['allowwarning']) {
							if (!$categoryIsOR) {
								$passed++;
							} else {
								$this->ORedCategories[$lastCategory] = true;
							}
							$errorString .= ", ok";
						}
						break;
					case 4:
						$errorString = "No result (did you forget a 'return'?)";
						break;
					default:
						$errorString = "OK";
						if (!$categoryIsOR) {
							$passed++;
						} else {
							$this->ORedCategories[$lastCategory] = true;
						}
						break;
				}
				if (isset($attrs['optional']) && $attrs['optional']) {
					$errorString .= ", optional";
				}
				$isOptional = isset($attrs['optional']) && $attrs['optional'];

				$output .= $this->drawTemplateString('line', array(
					$name,
					$errorString,
					$isOptional ? 'optional' : '',
					$isOptional && $attrs['error'] != 1 ? 3 : $attrs['error'],
					isset($attrs['category']) ? '        ' : '    ',		// indentation for CLI
					$attrs['error'] == 1 ? '' : $attrs['desc'],
				));

				if (!$categoryIsOR) {
					$checked++;
				}
			}

			if ($lastCategory !== null) {
				$output .= $this->drawTemplateString('categoryend');
				if ($categoryIsOR && $this->ORedCategories[$lastCategory]) {
					$passed++;
				}
			}
		}

		return $output . $this->drawTemplateString('blockend', array($passed < $checked ? 'failed' : 'succeeded', $passed, $checked));
	}

	function sortRequirements()
	{
		ksort($this->requirements);

		foreach ($this->requirements as $inifile => $features)
		{
			uasort($features, array($this, '_requirementsSort'));
			$this->requirements[$inifile] = $features;
		}
	}

	// callback for the above
	function _requirementsSort($a, $b)
	{
		$cata = isset($a['category']) ? $a['category'] : '';
		$catb = isset($b['category']) ? $b['category'] : '';

	    if ($cata == $catb) {
	        return 0;
	    }
	    return ($cata < $catb) ? -1 : 1;
	}

	function drawTemplateString($name, $params = array())
	{
		$i = $this->inCLI() ? 1 : 0;
		return vsprintf($this->TEMPLATES[$name][$i], $params);
	}

	//==========================================================================
	//	Util

	// ensures that a directory path has a trailing slash
	function _fixDir($dir)
	{
		$last = $dir[strlen($dir)-1];
		return $last == '/' || $last == '\\' ? $dir : $dir . '/';
	}

	function inCLI()
	{
		return PHP_SAPI == 'cli' || (substr(PHP_SAPI, 0, 3) == 'cgi' && empty($_SERVER['REQUEST_URI']));
	}

	//========================================================================================
	// These are helper functions for feature detection. Since this class is always loaded
	// when parsing your INI files, you may call these methods statically if desired.

	static function SPLInterfaceExists($name, $onlyClasses = false)
	{
	    if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
	        return true;
	    } else if (version_compare(PHP_VERSION, '5.0.0', '<')) {
	        return false;
	    } else if ($onlyClasses || version_compare(PHP_VERSION, '5.0.1', '<=')) {
	        return class_exists($name);
	    } else {
	        return interface_exists($name);
	    }
	}

	static function SPLClassExists($name)
	{
		return FeatureChecker::SPLInterfaceExists($name, true);
	}

	static function ProgramInstalled($executableName)
	{
		$command = 'which ' . escapeshellarg($executableName);
		$fork = popen($command, 'r');

		$output = '';
		while (!feof($fork)) {
			$output .= fread($fork, 1024);
		}

		pclose($fork);

		return $output ? true : false;
	}
}
?>
