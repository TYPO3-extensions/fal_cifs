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
use TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;

class CIFSDriver extends AbstractHierarchicalFilesystemDriver {

	/**
	 * @var WorldDirect\FalCifs\SMBClient
	 */
	protected $smbClient;

	/**
	 * A list of all supported hash algorithms, written all lower case and
	 * without any dashes etc. (e.g. sha1 instead of SHA-1)
	 * Be sure to set this in inherited classes!
	 *
	 * @var array
	 */
	protected $supportedHashAlgorithms = array('sha1', 'md5');

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
		try {
			$this->configuration['storageUid'] = $this->storageUid;
			$this->smbClient = GeneralUtility::makeInstance('WorldDirect\FalCifs\SMBClient', $this->configuration);
		} catch(\Exception $e) {
			$this->addFlashMessage('CIFS-FAL: ' . $e->getMessage());
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
		if (!$this->smbClient) {
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
		if ($localFileName = $this->getLocalProcessedFile($identifier)) {
			return 'typo3temp/fal_cifs' . $identifier;
		}

		if ($this->configuration['publicUrlPrefix']) {
			return $this->configuration['publicUrlPrefix'] . $identifier;
		} else {
			return $this->url . $identifier;
		}
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
		if (!$this->smbClient) {
			throw new \Exception("CIFS-FAL: createFolder($newFolderName): Not connected");
		}

		$newFolderIdentifier = rtrim($parentFolderIdentifier, '/') . '/' . rawurlencode($newFolderName);

		if ($newFolderName == '_processed_') {
			return $newFolderIdentifier;
		}
		if (!$this->hasCapability(CAPABILITY_WRITABLE)) {
			throw new InsufficientFolderWritePermissionsException("File mount is not writeable");
		}

		try {
			$this->smbClient->mkdir($this->url . $newFolderIdentifier, $recursive);
		} catch(\Exception $e) {
			$this->addFlashMessage($e->getMessage());
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
		if(!$this->smbClient) {
			throw new \Exception("CIFS-FAL: Not connected");
		}

		$fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
		if ($localFileName = $this->getLocalProcessedFile($fileIdentifier)) {
			return file_exists($localFileName);
		}

		try {
			$stat = $this->smbClient->stat($this->url . $folderIdentifier);
			return true;
		} catch(\Exception $e) {
			return false;
		}
	}

	/**
	 * Checks if a folder exists.
	 *
	 * @param string $folderIdentifier
	 *
	 * @return boolean
	 */
	public function folderExists($folderIdentifier) {
		if(!$this->smbClient) {
			if ($folderIdentifier == '/' || $folderIdentifier == '_processed_') {
				return;
			} else {
				throw new \Exception("CIFS-FAL: folderExists($folderIdentifier): Not connected");
			}
		}

		try {
			$stat = @$this->smbClient->stat($this->url . $folderIdentifier);
			return true;
		} catch(FileDoesNotExistException $e) {
			return false;
		}
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
		if (!$newFileName) {
			$newFileName = basename($localFilePath);
		}

		$newFileIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . rawurlencode($newFileName);

		if ($newLocalFileName = $this->getLocalProcessedFile($newFileIdentifier)) {
			rename($localFilePath, $newLocalFileName);
			GeneralUtility::fixPermissions($newLocalFileName);
		} else {
			$this->smbClient->putFile($this->url . $newFileIdentifier, $localFilePath);
			if ($removeOriginal) {
				unlink($localFilePath);
			}
		}

		return $newFileIdentifier;
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
		$fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
		if ($localFileName = $this->getLocalProcessedFile($fileIdentifier)) {
			unlink($localFileName);
		} else {
			return $this->smbClient->unlinkFile($this->url . $fileIdentifier);
		}
	}

	/**
	 * Creates a hash for a file.
	 *
	 * @param string $fileIdentifier
	 * @param string $hashAlgorithm The hash algorithm to use
	 * @return string
	 */
	public function hash($fileIdentifier, $hashAlgorithm) {
		$stat = $this->smbClient->stat($this->url . $this->canonicalizeAndCheckFileIdentifier($fileIdentifier));
		$uniqueString = $this->storageUid . $fileIdentifier . $stat['dev'] . $stat['size'] . $stat['ctime'] . $stat['mtime'];

		switch ($hashAlgorithm) {
		case 'sha1':
			return sha1($uniqueString);
		case 'md5':
			return md5($uniqueString);
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
		$tempFileName = $this->tempFileName($fileIdentifier);
		if (!file_exists($tempFileName)) {
			return $this->smbClient->getFile($this->url . $fileIdentifier);
		} else {
			return file_get_contents($tempFileName);
		}
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
		return $this->fileExists($folderIdentifier . $fileName);
	}

	/**
	 * Checks if a folder inside a folder exists.
	 *
	 * @param string $folderName
	 * @param string $folderIdentifier
	 * @return boolean
	 */
	public function folderExistsInFolder($folderName, $folderIdentifier) {
		return $this->folderExists($folderIdentifier . $folderName);
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
		$tempFileName = $this->tempFileName($fileIdentifier);
		if (!file_exists($tempFileName)) {
			$this->smbClient->getFile($this->url . $fileIdentifier, $tempFileName);
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
		if (!$this->smbClient) {
			if ($identifier == '/') {
				return;
			} else {
				throw new \Exception("CIFS-FAL: Not connected");
			}
			return;
		}

		try {
			$stat = @$this->smbClient->stat($this->url . $folderIdentifier);

			return array(
				'r' => ($stat['mode'] & 4) ? true : false,
				'w' => ($stat['mode'] & 2) ? true : false,
			);
		} catch(\Exception $e) {
			if ($stat === false && $identifier == '/') {
				// The share itself is not a file which "exists"
				return array('r' => true);
			} else {
				throw $e;
			}
		}
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
		if (!$this->smbClient) {
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
		try {
			$stat = $this->smbClient->stat($this->url . $this->canonicalizeAndCheckFileIdentifier($fileIdentifier));
		} catch(FileDoesNotExistException $e) {
			return array();
		}
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
		if (!$this->smbClient) {
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
		try {
			return $this->smbClient->getDirectoryItemList(
				$this->url, $folderIdentifier, $start, $numberOfItems, array($this, 'applyFilterMethodsToDirectoryItem'), $filenameFilterCallbacks, TRUE, FALSE, $recursive);
		} catch(InsufficientFileAccessPermissionsException $e) {
			return array();
		}
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
		try {
			return $this->smbClient->getDirectoryItemList(
				$this->url, $folderIdentifier, $start, $numberOfItems, array($this, 'applyFilterMethodsToDirectoryItem'), $folderNameFilterCallbacks, FALSE, TRUE, $recursive);
		} catch(InsufficientFileAccessPermissionsException $e) {
			return array();
		}
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
	public function applyFilterMethodsToDirectoryItem(array $filterMethods, $itemName, $itemIdentifier, $parentIdentifier) {
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
	 * @param string $fileIdentifier
	 * @return string
	 */
	protected function tempFileName($fileIdentifier) {
		$hash = $this->hash($fileIdentifier, 'sha1');
		if (!is_dir(PATH_site . 'typo3temp/fal_cifs')) {
			mkdir (PATH_site . 'typo3temp/fal_cifs');
		}
		return PATH_site . 'typo3temp/fal_cifs/' . $hash . '.' . PathUtility::pathinfo($fileIdentifier, PATHINFO_EXTENSION);
	}

	/**
	 * @param string $fileIdentifier
	 * @return mixed
	 */
	protected function getLocalProcessedFile($fileIdentifier) {
		if (substr($fileIdentifier, 0, 13) != '/_processed_/') {
			return false;
		}
		if (!is_dir(PATH_site . 'typo3temp/fal_cifs/_processed_')) {
			mkdir (PATH_site . 'typo3temp/fal_cifs');
			mkdir (PATH_site . 'typo3temp/fal_cifs/_processed_');
		}
		return PATH_site . 'typo3temp/fal_cifs' . $fileIdentifier;
	}
}
