#!/usr/bin/php 
<?php
	// get library
	require_once('featurechecker.class.php');

	// Simply create an instance of FeatureChecker to check a project's
	// directory recursively.
	// All featurecheck.ini files under this directory will be parsed
	$chk = new FeatureChecker('PATH_TO_APP_ROOT');
?>
