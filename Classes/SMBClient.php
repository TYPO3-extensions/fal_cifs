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

namespace WorldDirect\FalCifs;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class SMBClient {
	/**
	 * @var resource
	 */
	protected $connection;

	/**
	 * @param \array $configuration
	 */
	public function __construct($configuration) {
		if (!function_exists('smbclient_state_new')) {
			throw new \Exception('libsmbclient-php is not installed!');
		}

		$this->connection = smbclient_state_new();
		if (!$this->connection) {
			throw new \Exception("Could not create a SMB connection");
		}

		smbclient_option_set($this->connection, SMBCLIENT_OPT_URLENCODE_READDIR_ENTRIES, true);

		if ($configuration['kerberos']) {
			$krb5 = GeneralUtility::makeInstance('WorldDirect\FalCifs\KRB5', $configuration);
			$krb5->authenticate($configuration);

			smbclient_option_set($this->connection, SMBCLIENT_OPT_USE_KERBEROS, true);
			if (!smbclient_state_init($this->connection, null /*$configuration['domain']*/, $configuration['principal'])) {
				throw $this->errnoToException('Kerberos authentication');
			}
		} else {
			if (!smbclient_state_init($this->connection, $configuration['domain'], $configuration['user'], $configuration['password'])) {
				throw $this->errnoToException('User/Password authentication');
			}
		}
	}

	public function __destruct() {
		if ($this->connection) {
			smbclient_state_free($this->connection);
		}
	}

	/**
	 * @param string $url
	 * @return array
	 */
	public function stat($url) {
		$stat = smbclient_stat($this->connection, $url);
		if (!$stat) {
			throw $this->errnoToException('stat: ' . $url);
		}
		return $stat;
	}

	/**
	 * @param string $url
	 * @param boolean $recursive
	 */
	public function mkdir($url, $recursive = FALSE) {
		if (!smbclient_mkdir($this->connection, $url)) {
			throw $this->errnoToException('Error creating folder ' . $url);
		}
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

	/**
	 * Translate errno to exception
	 *
	 * @param string $message
	 * @param int errno
	 * @return TYPO3\CMS\Core\Resource\Exception
	 */
	protected function errnoToException($message, $errno = null) {
		if (!$errno) {
			$errno = smbclient_state_errno($this->connection);
		}

		$message = 'CIFS-FAL: ' . $message . ': ' . $this->getLastErrorMessage($errno);

		switch($errno) {
		case 1:  // EPERM
		case 13: // EACCESS
			return new \TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException($message);
		case 2:  // ENOENT
			return new \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException($message);
		case 30: // EROFS
			return new \TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException($message);
		default:
			return new \TYPO3\CMS\Core\Resource\Exception($message);
		}
	}

	/**
	 * @param string $url
	 * @param string fileName
	 * @return file content if no file name given
	 */
	public function getFile($url, $fileName = null) {
		$remoteHandle = smbclient_open($this->connection, $url, 'r');
		if (!$remoteHandle) {
			throw $this->errnoToException("Couldn't open file " . $url);
		}

		$content = '';
		if ($fileName) {
			$localHandle = fopen($fileName, 'wb');
			if (!$localHandle) {
				throw $this->errnoToException("Couldn't open local temp file " . $fileName);
			}
		}

		while ($chunk = smbclient_read($this->connection, $remoteHandle, 0x10000)) {
			if ($fileName) {
				if (!fwrite($localHandle, $chunk)) {
					fclose($localHandle);
					unlink($fileName);
					throw $this->errnoToException("Couldn't write to local temp file");
				}
			} else {
				$content .= $chunk;
			}
		}

		if ($chunk === false) {
			throw $this->errnoToException("failed reading chunk");
		}

		smbclient_close($this->connection, $remoteHandle);

		if ($fileName) {
			fclose($localHandle);
			GeneralUtility::fixPermissions($fileName);
		} else {
			return $content;
		}
	}

	/**
	 * Generic wrapper for extracting a list of items from a path.
	 *
	 * @param string $baseUrl
	 * @param string $folderUrl
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
	public function getDirectoryItemList($baseUrl, $folderUrl, $start = 0, $numberOfItems = 0, $filterCallback, array $filterMethods, $includeFiles = TRUE, $includeDirs = TRUE, $recursive = FALSE) {
		if(!$this->connection) {
			if ($folderUrl == '/') {
				return;
			} else {
				throw $this->errnoToException("getDirectoryItemList($folderUrl): Not connected");
			}
			return array();
		}

		$handle = @smbclient_opendir($this->connection, $baseUrl . $folderUrl);
		if (!$handle) {
			throw $this->errnoToException('Failed to open ' . $folderUrl);
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

			$stat = @smbclient_stat($this->connection, $baseUrl . $folderUrl . $entry['name']);
			if (!$stat) {
				// Some files cannot be stat'd - do not even list them!
				continue;
			}

			$name = rtrim($folderUrl, '/') . '/' . $entry['name'];
			if ($type == 'directory')
				$name .= '/';

			if (!call_user_func($filterCallback, $filterMethods, $entry['name'], $name, $folderUrl)) {
				continue;
			}

			$items[$name] = $name;
		}
		smbclient_closedir($this->connection, $handle);
		return $items;
	}

}

