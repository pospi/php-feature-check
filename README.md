Usage
-----
Simply place `featurecheck.ini` in any of your project's directories. The featurechecker
script is invoked with a path to a directory to process - it will recurse all directories
and run all checks in any `featurecheck.ini` files encountered.

`php check.php /my/project/root`, 
<br />or<br />
`check.php?project=/my/project/root`

featurecheck.ini Format
-----------------------
Format is that of regular *.ini files, with the following differences:

-	Header sections are used as the name of each requirement
-	Sections may specify the following parameters *before* their comment section:
	-	**optional** (*bool*): specifies an optional requirement
	-	**category** (*string*): specifies the name of a group to show this requirement under.
 	 	Use the same category string for multiple requirements to group them together
	-	**allowwarning** (*bool*): interprets a raised warning on this requirement as success
		rather than failure
	-	**anyincategory** (*bool*): modifies the behaviour of all requirements assigned to
		this category so that only 1 must pass to validate. This parameter MUST
		be specified AFTER the category name.
-	Comments (lines starting with #) immediately following headers & params will
	display as the error text of the feature when it is not met
-	All non-comment lines before the next header section are PHP code to
	evaluate for the test. Generally this code should use 'return' to pass back
	the success status of the requirement.

Requirements
------------
FeatureCheck itself requires the following (fairly minimal) prerequisites:

* PHP >= 4.0.0
	* however prior to 4.0.1 warnings cannot be differentiated from fatal errors 
* Not running in safe mode
* Sockets enabled

Known Issues
------------
When running requirement checks from CLI, any errors from the requirement checks 
will be output before the final results list.
