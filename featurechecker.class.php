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
			"<tr><th colspan=\"3\">Category: %s</th></tr>\n",
			"    %s\n",
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
					$metaValue = rtrim($matches[2]);
					if ($metaValue == 'true') {
						$metaValue = true;
					} else if ($metaValue == 'false') {
						$metaValue = false;
					}
					$this->_storeRequirementValue($file, $currHeading, $matches[1], $metaValue);
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

	function getOutput()
	{
		$this->sortRequirements();

		$output = '';
		$checked = 0;
		$passed = 0;

		foreach ($this->requirements as $filename => $features)
		{
			$fileDir = dirname($filename);
			$lastCategory = null;

			$output .= $this->drawTemplateString('heading', array(dirname($filename)));
			$output .= $this->drawTemplateString('blockstart');

			foreach ($features as $name => $attrs) {
				if (isset($attrs['category']) && $attrs['category'] != $lastCategory) {
					if ($lastCategory !== null) {
						$output .= $this->drawTemplateString('categoryend');
					}
					$output .= $this->drawTemplateString('categorystart', array($attrs['category']));
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
							$passed++;
							$errorString .= ", ok";
						}
						break;
					case 4:
						$errorString = "No result (did you forget a 'return'?)";
						break;
					default:
						$errorString = "OK";
						$passed++;
						break;
				}
				if (isset($attrs['optional']) && $attrs['optional']) {
					$errorString .= ", optional";
				}

				$output .= $this->drawTemplateString('line', array(
					$name,
					$errorString,
					isset($attrs['optional']) && $attrs['optional'] ? 'optional' : '',
					$attrs['error'],
					isset($attrs['category']) ? '        ' : '    ',		// indentation for CLI
					$attrs['error'] == 1 ? '' : $attrs['desc'],
				));

				$checked++;
			}

			if ($lastCategory !== null) {
				$output .= $this->drawTemplateString('categoryend');
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
		return sizeof($_SERVER['argv']) > 0;
	}
}
?>
