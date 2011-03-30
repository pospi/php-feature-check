<?php
/**
 * This script acts as a sandbox for testing requirement check code blocks.
 * 
 * It should return integral values corresponding to the following:
 *	0 => requirement evaluated fine & failed
 *	1 => requirement ok
 *	2 => checking requirement caused error or exception
 *	3 => checking requirement raised a warning
 *	4 => requirement improperly coded, does not return any value or throw an error
 *
 * In the case of hosts running PHP 4.0.0, there is no error handling mechanism
 * and so any string returned in this case is interpreted as an error.
 *
 * @author pospi <pospi@spadgos.com>
 */

	if (sizeof($_GET)) {
		die('This script must be executed via CLI');
	}

	// set an error handler to detect fatal errors etc encountered in the eval()
	// Note that this is optional - without an error handler present, any string
	// output is determined to be a fatal error for PHP4.0.0 compatibility. The
	// system will not be able to differentiate errors from warnings if this is so.
	function errorHandler($errno, $errstr)
	{
		global $hasWarnings;

		if (in_array($errno, array(E_WARNING, E_NOTICE, E_STRICT, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING, E_USER_NOTICE))) {
			$hasWarnings = true;
		} else {
			echo '2';
			die;
		}
	}
	$hasWarnings = false;
	if (function_exists('set_error_handler')) {
		set_error_handler('errorHandler');
	}

	// we also set a shutdown function to output a 2 for any fatal error encountered
	function fatalErrorCheck()
	{
		global $evalDone, $hasWarnings;
		if (!$evalDone) {
			ob_clean();
			echo $hasWarnings ? '3' : '2';
			die;
		}
	}
	register_shutdown_function('fatalErrorCheck');

	// now, execute it
	$eval = $_SERVER['argv'][1];

	ob_start();
	$evalDone = false;
	$retVal = eval($eval);
	$evalDone = true;
	ob_clean();

	if ($retVal !== null) {			// feature check returns a value
		echo $hasWarnings ? '3' : ($retVal ? '1' : '0');
	} else {						// nothing returned, and no error
		echo $hasWarnings ? '3' : '4';
	}
?>
