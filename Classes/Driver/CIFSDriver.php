<?php
/*
 * TYPO3 extension fal_cifs
 * Copyright (C) 2015  Christian Plattner <Christian.Plattner@world-direct.at>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace WorldDirect\FalCifs\Driver;

use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;

class CIFSDriver extends AbstractHierarchicalFilesystemDriver {
	/**
	 * A list of all supported hash algorithms, written all lower case and
	 * without any dashes etc. (e.g. sha1 instead of SHA-1)
	 * Be sure to set this in inherited classes!
	 *
	 * @var array
	 */
	protected $supportedHashAlgorithms = array('sha1', 'md5');

	/**
	 * @var resource
	 */
	protected $connection;

	/**
	 * @var string
	 */
	protected $url;
	
	/**
	 * @var array
	 */
	protected $urlParts;
	
	public function __construct(array $configuration = array()) {
		parent::__construct($configuration);
		$this->capabilities =
			ResourceStorage::CAPABILITY_BROWSABLE |
			ResourceStorage::CAPABILITY_PUBLIC |
			ResourceStorage::CAPABILITY_WRITABLE;
	}

	public function __destruct() {
		if ($this->connection) {
			smbclient_state_free($this->connection);
		}
	}

	/**
	 * Processes the configuration for this driver.
	 * @return void
	 */
	public function processConfiguration() {
		if (preg_match("/^[\\\\\\/]{2}([^\\\\\\/]+)(.*)$/", $this->configuration['url'], $matches)) {
			$path = str_replace("\\", '/', $matches[2]);
			$this->urlParts = array(
				'host' => $matches[1],
				'path' => $path,
			);
			$this->url = "smb://" . $matches[1] . rtrim($path, '/');
		} else {
			$this->urlParts = parse_url($this->configuration['url']);
			$this->url = rtrim($this->configuration['url'], '/');
		}
	}

	/**
	 * Initializes this object. This is called by the storage after the driver
	 * has been attached.
	 *
	 * @return void
	 */
	public function initialize() {
		if (!function_exists('smbclient_state_new')) {
			$this->addFlashMessage('CIFS-FAL: libsmbclient-php is not installed!');
		} else {
			$this->connection = smbclient_state_new();
			if (!$this->connection) {
				throw new \Exception("Could not create a SMB connection");
			}

			smbclient_option_set($this->connection, SMBCLIENT_OPT_URLENCODE_READDIR_ENTRIES, true);

			if ($this->configuration['kerberos']) {
				if (!extension_loaded("krb5")){
					$this->addFlashMessage('CIFS-FAL: You need to install php-pecl-krb5 to access kerberos-authenticated CIFS shares');
					return;
				}

				$cacheFile = getenv("KRB5CCNAME");
				if (!$cacheFile) {
					$cacheFile = "FILE:/tmp/krb5cc_" . getmyuid() . "_typo3_storage_" . $this->storageUid;
					putenv("KRB5CCNAME=" . $cacheFile);
				}

				$krb5 = new \KRB5CCache();
				try {
					$krb5->open($cacheFile);
					$krb5->isValid();
				} catch(\Exception $e) {
					// Cached ticket not found or expired
					try {
						if ($this->configuration['keytab']) {
							if (!$krb5->initKeytab($this->configuration['principal'], $this->configuration['keytab'])) {
								$this->addFlashMessage("FIFS-FAL: Failed authenticating using Kerberos with keytab");
								$this->connection = NULL;
								return;
							}
						} else {
							if (!$krb5->initPassword($this->configuration['user'], $this->configuration['password'])) {
								$this->addFlashMessage("FIFS-FAL: Failed authenticating using Kerberos with user name");
								$this->connection = NULL;
								return;
							}
						}

						$krb5->save($cacheFile);
					} catch(\Exception $e) {
						$this->addFlashMessage("FIFS-FAL: Kerberos: " . $e->getMessage());
						$this->connection = NULL;
						return;
					}
				}

				smbclient_option_set($this->connection, SMBCLIENT_OPT_USE_KERBEROS, true);
				if (!smbclient_state_init($this->connection, null /*$this->configuration['domain']*/, $this->configuration['principal'])) {
					throw new \Exception('SMB: errno ' . smbclient_state_errno($this->connection) . ' while authenticating');
				}
			} else {
				if (!smbclient_state_init($this->connection, $this->configuration['domain'], $this->configuration['user'], $this->configuration['password'])) {
					throw new \Exception('SMB: errno ' . smbclient_state_errno($this->connection) . ' while authenticating');
				}
			}
		}
	}

	/**
	 * Merges the capabilites merged by the user at the storage
	 * configuration into the actual capabilities of the driver
	 * and returns the result.
	 *
	 * @param integer $capabilities
	 *
	 * @return integer
	 */
	public function mergeConfigurationCapabilities($capabilities) {
		$this->capabilities &= $capabilities;
		return $this->capabilities;
	}

	/**
	 * Returns the identifier of the root level folder of the storage.
	 *
	 * @return string
	 */
	public function getRootLevelFolder() {
		if (!$this->connection) {
			return;
		}
		return '/';
	}

	/**
	 * Returns the identifier of the default folder new files should be put into.
	 *
	 * @return string
	 */
	public function getDefaultFolder() {
		// TODO implement
	}

	/**
	 * Returns the identifier of the folder the file resides in
	 *
	 * @param string $fileIdentifier
	 *
	 * @return string
	 */
	public function getParentFolderIdentifierOfIdentifier($fileIdentifier) {
		// TODO use backslash instead of forward slash?
		$result = parent::getParentFolderIdentifierOfIdentifier($fileIdentifier);
		return $result;
	}

	/**
	 * Returns the public URL to a file.
	 * Either fully qualified URL or relative to PATH_site (rawurlencoded).
	 *
	 *
	 * @param string $identifier
	 * @return string
	 */
	public function getPublicUrl($identifier) {
		// TODO implement
	}

	/**
	 * Creates a folder, within a parent folder.
	 * If no parent folder is given, a root level folder will be created
	 *
	 * @param string $newFolderName
	 * @param string $parentFolderIdentifier
	 * @param boolean $recursive
	 * @return string the Identifier of the new folder
	 */
	public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = FALSE) {
		if (!$this->connection) {
			throw new \Exception("CIFS-FAL: createFolder($newFolderName): Not connected");
		}

		if (!$this->hasCapability(CAPABILITY_WRITABLE)) {
			throw new InsufficientFolderWritePermissionsException("File mount is not writeable");
		}

		$newFolderIdentifier = rtrim($parentFolderIdentifier, '/') . '/' . rawurlencode($newFolderName);

		if (!smbclient_mkdir($this->connection, $this->url . $newFolderIdentifier)) {
			if ($newFolderName != '_processed_') {
				$this->addFlashMessage('Error creating folder "' . $this->url . $newFolderIdentifier . '": ' . $this->getLastErrorMessage());
			}
			return;
		}

		return $newFolderIdentifier;
	}

	/**
	 * Renames a folder in this storage.
	 *
	 * @param string $folderIdentifier
	 * @param string $newName
	 * @return array A map of old to new file identifiers of all affected resources
	 */
	public function renameFolder($folderIdentifier, $newName) {
		// TODO implement
	}

	/**
	 * Removes a folder in filesystem.
	 *
	 * @param string $folderIdentifier
	 * @param boolean $deleteRecursively
	 * @return boolean
	 */
	public function deleteFolder($folderIdentifier, $deleteRecursively = FALSE) {
		// TODO implement
	}

	/**
	 * Checks if a file exists.
	 *
	 * @param string $fileIdentifier
	 *
	 * @return boolean
	 */
	public function fileExists($fileIdentifier) {
		if(!$this->connection) {
			throw new \Exception("CIFS-FAL: Not connected");
		}

		$stat = smbclient_stat($this->connection, $this->url . $folderIdentifier);
		if (!$stat)
			return false;

		return ($stat['mode'] & 040000) ? false : true;
	}

	/**
	 * Checks if a folder exists.
	 *
	 * @param string $folderIdentifier
	 *
	 * @return boolean
	 */
	public function folderExists($folderIdentifier) {
		if(!$this->connection) {
			if ($folderIdentifier == '/' || $folderIdentifier == '_processed_') {
				return;
			} else {
				throw new \Exception("CIFS-FAL: folderExists($folderIdentifier): Not connected");
			}
		}

		$stat = @smbclient_stat($this->connection, $this->url . $folderIdentifier);
		return ($stat['mode'] & 040000) ? true : false;
	}

	/**
	 * Checks if a folder contains files and (if supported) other folders.
	 *
	 * @param string $folderIdentifier
	 * @return boolean TRUE if there are no files and folders within $folder
	 */
	public function isFolderEmpty($folderIdentifier) {
		// TODO implement
	}

	/**
	 * Adds a file from the local server hard disk to a given path in TYPO3s
	 * virtual file system. This assumes that the local file exists, so no
	 * further check is done here! After a successful the original file must
	 * not exist anymore.
	 *
	 * @param string $localFilePath (within PATH_site)
	 * @param string $targetFolderIdentifier
	 * @param string $newFileName optional, if not given original name is used
	 * @param boolean $removeOriginal if set the original file will be removed
	 *                                after successful operation
	 * @return string the identifier of the new file
	 */
	public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = TRUE) {
		// TODO implement
	}

	/**
	 * Creates a new (empty) file and returns the identifier.
	 *
	 * @param string $fileName
	 * @param string $parentFolderIdentifier
	 * @return string
	 */
	public function createFile($fileName, $parentFolderIdentifier) {
		// TODO implement
	}

	/**
	 * Copies a file *within* the current storage.
	 * Note that this is only about an inner storage copy action,
	 * where a file is just copied to another folder in the same storage.
	 *
	 * @param string $fileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $fileName
	 * @return string the Identifier of the new file
	 */
	public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName) {
		// TODO implement
	}

	/**
	 * Renames a file in this storage.
	 *
	 * @param string $fileIdentifier
	 * @param string $newName The target path (including the file name!)
	 * @return string The identifier of the file after renaming
	 */
	public function renameFile($fileIdentifier, $newName) {
		// TODO implement
	}

	/**
	 * Replaces a file with file in local file system.
	 *
	 * @param string $fileIdentifier
	 * @param string $localFilePath
	 * @return boolean TRUE if the operation succeeded
	 */
	public function replaceFile($fileIdentifier, $localFilePath) {
		// TODO implement
	}

	/**
	 * Removes a file from the filesystem. This does not check if the file is
	 * still used or if it is a bad idea to delete it for some other reason
	 * this has to be taken care of in the upper layers (e.g. the Storage)!
	 *
	 * @param string $fileIdentifier
	 * @return boolean TRUE if deleting the file succeeded
	 */
	public function deleteFile($fileIdentifier) {
		// TODO implement
	}

	/**
	 * Creates a hash for a file.
	 *
	 * @param string $fileIdentifier
	 * @param string $hashAlgorithm The hash algorithm to use
	 * @return string
	 */
	public function hash($fileIdentifier, $hashAlgorithm) {
		$temporaryFile = $this->getFileForLocalProcessing($fileIdentifier);

		switch ($hashAlgorithm) {
		case 'sha1':
			$hash = sha1_file($temporaryFile);
			break;
		case 'md5':
			$hash = md5_file($temporaryFile);
			break;
		default:
			throw new \RuntimeException('Hash algorithm ' . $hashAlgorithm . ' is not implemented.', 1408550582);
		}
	}


	/**
	 * Moves a file *within* the current storage.
	 * Note that this is only about an inner-storage move action,
	 * where a file is just moved to another folder in the same storage.
	 *
	 * @param string $fileIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFileName
	 *
	 * @return string
	 */
	public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName) {
		// TODO implement
	}


	/**
	 * Folder equivalent to moveFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 *
	 * @return array All files which are affected, map of old => new file identifiers
	 */
	public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {
		// TODO implement
	}

	/**
	 * Folder equivalent to copyFileWithinStorage().
	 *
	 * @param string $sourceFolderIdentifier
	 * @param string $targetFolderIdentifier
	 * @param string $newFolderName
	 *
	 * @return boolean
	 */
	public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName) {
		// TODO implement
	}

	/**
	 * Returns the contents of a file. Beware that this requires to load the
	 * complete file into memory and also may require fetching the file from an
	 * external location. So this might be an expensive operation (both in terms
	 * of processing resources and money) for large files.
	 *
	 * @param string $fileIdentifier
	 * @return string The file contents
	 */
	public function getFileContents($fileIdentifier) {
		// TODO implement
	}

	/**
	 * Sets the contents of a file to the specified value.
	 *
	 * @param string $fileIdentifier
	 * @param string $contents
	 * @return integer The number of bytes written to the file
	 */
	public function setFileContents($fileIdentifier, $contents) {
		// TODO implement
	}

	/**
	 * Checks if a file inside a folder exists
	 *
	 * @param string $fileName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function fileExistsInFolder($fileName, $folderIdentifier) {
		// TODO implement
	}

	/**
	 * Checks if a folder inside a folder exists.
	 *
	 * @param string $folderName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function folderExistsInFolder($folderName, $folderIdentifier) {
		// TODO implement
	}

	/**
	 * Returns a path to a local copy of a file for processing it. When changing the
	 * file, you have to take care of replacing the current version yourself!
	 *
	 * @param string $fileIdentifier
	 * @param bool $writable Set this to FALSE if you only need the file for read
	 *                       operations. This might speed up things, e.g. by using
	 *                       a cached local version. Never modify the file if you
	 *                       have set this flag!
	 * @return string The path to the file on the local disk
	 */
	public function getFileForLocalProcessing($fileIdentifier, $writable = TRUE) {
		$hash = sha1($this->storageUid . ':' . $fileIdentifier);
		$tempFileName = PATH_site . 'typo3temp/fal_cifs_' . $hash;
		if (!file_exists($tempFileName)) {	// TODO || filemtime($tempFileName) < ?
			$remoteHandle = smbclient_open($this->connection, $this->url . $fileIdentifier, 'r');
			if (!$remoteHandle) {
				throw new \Exception("CIFS-FAL: Couldn't open file " . $this->url . $fileIdentifier . ': ' . $this->getLastErrorMessage());
			}

			$localHandle = fopen($tempFileName, 'wb');
			if (!$localHandle) {
				throw new \Exception("CIFS-FAL: Couldn't open local temp file " . $tempFileName);
			}

			while ($chunk = smbclient_read($this->connection, $remoteHandle, 0x10000)) {
				if (!fwrite($localHandle, $chunk)) {
					fclose($localHandle);
					unlink($tempFileName);
					throw new \Exception("CIFS-FAL: Couldn't write to local temp file");
				}
			}

			if ($chunk === false) {
				throw new \Exception("CIFS-FAL: failed reading chunk");
			}

			fclose($localHandle);
			smbclient_close($this->connection, $remoteHandle);

			GeneralUtility::fixPermissions($tempFileName);
		}

		return $tempFileName;
	}

	/**
	 * Returns the permissions of a file/folder as an array
	 * (keys r, w) of boolean flags
	 *
	 * @param string $identifier
	 * @return array
	 */
	public function getPermissions($identifier) {
		if (!$this->connection) {
			if ($identifier == '/') {
				return;
			} else {
				throw new \Exception("CIFS-FAL: Not connected");
			}
			return;
		}

		$stat = @smbclient_stat($this->connection, $this->url . $folderIdentifier);

		if ($stat === false && $identifier == '/') {
			// The share itself is not a file which "exists"
			return array('r' => true);
		}

		return array(
			'r' => ($stat['mode'] & 4) ? true : false,
			'w' => ($stat['mode'] & 2) ? true : false,
		);
	}

	/**
	 * Directly output the contents of the file to the output
	 * buffer. Should not take care of header files or flushing
	 * buffer before. Will be taken care of by the Storage.
	 *
	 * @param string $identifier
	 * @return void
	 */
	public function dumpFileContents($identifier) {
		// TODO implement
	}

	/**
	 * Checks if a given identifier is within a container, e.g. if
	 * a file or folder is within another folder.
	 * This can e.g. be used to check for web-mounts.
	 *
	 * Hint: this also needs to return TRUE if the given identifier
	 * matches the container identifier to allow access to the root
	 * folder of a filemount.
	 *
	 * @param string $folderIdentifier
	 * @param string $identifier identifier to be checked against $folderIdentifier
	 * @return boolean TRUE if $content is within or matches $folderIdentifier
	 */
	public function isWithin($folderIdentifier, $identifier) {
		if (!$this->connection) {
			throw new \Exception("CIFS-FAL: isWithin($folderIdentifier, $identifier): Not connected");
		}

		$folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
		$entryIdentifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
		if ($folderIdentifier === $entryIdentifier) {
			return TRUE;
		}
		return GeneralUtility::isFirstPartOfStr($entryIdentifier, $folderIdentifier);
	}

	/**
	 * Returns information about a file.
	 *
	 * @param string $fileIdentifier
	 * @param array $propertiesToExtract Array of properties which are be extracted
	 *                                   If empty all will be extracted
	 * @return array
	 */
	public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = array()) {
		$stat = smbclient_stat($this->connection, $this->url . $fileIdentifier);
		return array(
			'storage' => $this->storageUid,
			'identifier' => $fileIdentifier,
			'identifier_hash' => $this->hashIdentifier($fileIdentifier),
			'name' => rawurldecode(PathUtility::basename($fileIdentifier)),
			'size' => $stat['size'],
			'creation_date' => $stat['ctime'],
			'modification_date' => $stat['mtime'],
		);
	}

	/**
	 * Returns information about a file.
	 *
	 * @param string $folderIdentifier
	 * @return array
	 */
	public function getFolderInfoByIdentifier($folderIdentifier) {
		if (!$this->connection) {
			if ($folderIdentifier == '//' || $folderIdentifier == '_processed_') {
				return;
			} else {
				throw new \Exception("CIFS-FAL: getFolderInfoByIdentifier ($folderIdentifier): Not connected");
			}
			return;
		}

		$folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
		if (!$this->folderExists($folderIdentifier)) {
			throw new \TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException('Folder ' . $folderIdentifier . ' does not exist.', 1441277676);
		}

		return array(
			'identifier' => $folderIdentifier,
			'name' => rawurldecode(PathUtility::basename($folderIdentifier)),
			'storage' => $this->storageUid
		);
	}

	/**
	 * Returns a list of files inside the specified path
	 *
	 * @param string $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param boolean $recursive
	 * @param array $filenameFilterCallbacks callbacks for filtering the items
	 *
	 * @return array of FileIdentifiers
	 */
	public function getFilesInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE, array $filenameFilterCallbacks = array()) {
		return $this->getDirectoryItemList($folderIdentifier, $start, $numberOfItems, $filenameFilterCallbacks, TRUE, FALSE, $recursive);
	}

	/**
	 * Returns a list of folders inside the specified path
	 *
	 * @param string $folderIdentifier
	 * @param integer $start
	 * @param integer $numberOfItems
	 * @param boolean $recursive
	 * @param array $folderNameFilterCallbacks callbacks for filtering the items
	 *
	 * @return array of Folder Identifier
	 */
	public function getFoldersInFolder($folderIdentifier, $start = 0, $numberOfItems = 0, $recursive = FALSE, array $folderNameFilterCallbacks = array()) {
		return $this->getDirectoryItemList($folderIdentifier, $start, $numberOfItems, $folderNameFilterCallbacks, FALSE, TRUE, $recursive);
	}


	/**
	 * Generic wrapper for extracting a list of items from a path.
	 *
	 * @param string $folderIdentifier
	 * @param integer $start The position to start the listing; if not set, start from the beginning
	 * @param integer $numberOfItems The number of items to list; if set to zero, all items are returned
	 * @param array $filterMethods The filter methods used to filter the directory items
	 * @param boolean $includeFiles
	 * @param boolean $includeDirs
	 * @param boolean $recursive
	 *
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	protected function getDirectoryItemList($folderIdentifier, $start = 0, $numberOfItems = 0, array $filterMethods, $includeFiles = TRUE, $includeDirs = TRUE, $recursive = FALSE) {
		if(!$this->connection) {
			if ($folderIdentifier == '/') {
				return;
			} else {
				throw new \Exception("CIFS-FAL: getDirectoryItemList($folderIdentifier): Not connected");
			}
			return array();
		}

		$handle = @smbclient_opendir($this->connection, $this->url . $folderIdentifier);
		if (!$handle) {
			$this->addFlashMessage($this->getLastErrorMessage() . ' while opening ' . $this->url . $folderIdentifier);
			return array();
		}
		$items = array();
		while (($entry = smbclient_readdir($this->connection, $handle)) !== false) {
			switch($entry['type']) {
			case 'directory':
			case 'file share':
				$type = 'directory';
				break;
			case 'file':
				$type = 'file';
				break;
			default:
				$type = '';
			}

			if (!$type) {
				continue;
			}

			if (!$includeDirs && $type == 'directory')
				continue;

			if (!$includeFiles && $type == 'file')
				continue;

			$stat = @smbclient_stat($this->connection, $this->url . $folderIdentifier . $entry['name']);
			if (!$stat) {
				// Some files cannot be stat'd - do not even list them!
				continue;
			}

			$name = rtrim($folderIdentifier, '/') . '/' . $entry['name'];
			if ($type == 'directory')
				$name .= '/';

			if (!$this->applyFilterMethodsToDirectoryItem($filterMethods, $entry['name'], $name, $folderIdentifier)) {
				continue;
			}

			$items[$name] = $name;
		}
		smbclient_closedir($this->connection, $handle);
		return $items;
	}


	/**
	 * Add flash message to message queue.
	 *
	 * @param string $message
	 * @param integer $severity
	 * @return void
	 */
	protected function addFlashMessage($message, $severity = \TYPO3\CMS\Core\Messaging\FlashMessage::ERROR) {
		$flashMessage = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
			'TYPO3\\CMS\\Core\\Messaging\\FlashMessage',
			$message,
			'',
			$severity,
			TRUE
		);
		/** @var $defaultFlashMessageQueue \TYPO3\CMS\Core\Messaging\FlashMessageQueue */
		$defaultFlashMessageQueue = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessageService')->getMessageQueueByIdentifier();
		$defaultFlashMessageQueue->enqueue($flashMessage);
	}

	/**
	 * Applies a set of filter methods to a file name to find out if it should be used or not. This is e.g. used by
	 * directory listings.
	 *
	 * @param array $filterMethods The filter methods to use
	 * @param string $itemName
	 * @param string $itemIdentifier
	 * @param string $parentIdentifier
	 * @throws \RuntimeException
	 * @return boolean
	 */
	protected function applyFilterMethodsToDirectoryItem(array $filterMethods, $itemName, $itemIdentifier, $parentIdentifier) {
		foreach ($filterMethods as $filter) {
			if (is_array($filter)) {
				$result = call_user_func($filter, $itemName, $itemIdentifier, $parentIdentifier, array(), $this);
				// We have to use -1 as the don't include return value, as call_user_func() will return FALSE
				// If calling the method succeeded and thus we can't use that as a return value.
				if ($result === -1) {
					return FALSE;
				} elseif ($result === FALSE) {
					throw new \RuntimeException('Could not apply file/folder name filter ' . $filter[0] . '::' . $filter[1]);
				}
			}
		}
		return TRUE;
	}

	/**
	 * Get an error message from last error reported from libsmbclient
	 *
	 * @return string
	 */
	protected function getLastErrorMessage() {
		$errno = smbclient_state_errno($this->connection);
		return posix_strerror($errno);
	}
}
