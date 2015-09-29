<?php

/***************************************************************
 * Extension Manager/Repository config file for ext "fal_ftp".
 *
 * Auto generated 03-09-2015 07:04
 *
 * Manual updates:
 * Only the data in the array - everything else is removed by next
 * writing. "version" and "dependencies" must not be touched!
 ***************************************************************/

$EM_CONF[$_EXTKEY] = array (
	'title' => 'FAL CIFS Driver',
	'description' => 'Provides a CIFS driver for the TYPO3 File Abstraction Layer (FAL) to manage files via filemanager (filelist).',
	'category' => 'plugin',
	'version' => '0.1',
	'state' => 'stable',
	'uploadfolder' => false,
	'createDirs' => '',
	'clearcacheonload' => true,
	'author' => 'Christian Plattner',
	'author_email' => 'Christian.Plattner@world-direct.at',
	'author_company' => 'World-Direct eBusiness GmbH',
	'constraints' => 
	array (
		'depends' => 
		array (
			'php' => '5.3.3-0.0.0',
			'typo3' => '6.2.0-6.2.99',
			// PHP Library libsmbclient
		),
		'conflicts' => 
		array (
		),
		'suggests' => 
		array (
		),
	),
);

