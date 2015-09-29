<?php
if (!defined ('TYPO3_MODE')) die ('Access denied.');

$registerDriver = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Driver\\DriverRegistry');
$registerDriver->registerDriverClass(
	'WorldDirect\\FalCifs\\Driver\\CIFSDriver',
	'CIFS',
	'CIFS filesystem',
	'FILE:EXT:fal_cifs/Configuration/FlexForm/CIFSDriver.xml'
);

?>
