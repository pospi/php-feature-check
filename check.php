<?php
 /*===============================================================================
	PHP Feature Checker - main script
	----------------------------------------------------------------------------
	This script will recursively churn through a directory, finding all 
	PHPFeatureCheck INI files. These are then evaluated and a report generated.
	This script can be run from either CLI or a normal webpage.

	For security reasons, DON'T put this script in a publicly accessible
	web directory on a production server!!

	Usage:
		http://path.to/phpfeaturecheck/check.php?project=MY_APP_ROOT
					or
		php /path.to/phpfeaturecheck/check.php MY_APP_ROOT
	----------------------------------------------------------------------------
	@author		Sam Pospischil <pospi@spadgos.com>
  ===============================================================================*/

	// get library
	require_once('featurechecker.class.php');

	if (FeatureChecker::inCLI()) {
		$path = $_SERVER['argv'][1];
	} else if (isset($_GET['project'])) {
		$path = $_GET['project'];
	} else {
		die("Project directory not specified.\n");
	}
	
	// Simply create an instance of FeatureChecker to check a project's
	// directory recursively.
	// All featurecheck.ini files under this directory will be parsed
	$chk = new FeatureChecker($path);

	if (!FeatureChecker::inCLI()) {
?>
<html>
<head>
<title>Checking PHP Features...</title>
<link rel="stylesheet" type="text/css" href="featurecheck.css" />
</head>
<body>
<?php
	}

	echo $chk->getOutput();

	if (!FeatureChecker::inCLI()) {
?>
</body>
</html>
<?php
	}
?>
