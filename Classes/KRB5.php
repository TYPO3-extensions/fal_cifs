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

class KRB5 {

	public function __construct() {
		if (!extension_loaded("krb5")){
			throw new \Exception('You need to install php-pecl-krb5 to access kerberos-authenticated CIFS shares');
		}
	}

	public function authenticate($configuration) {
		if (!extension_loaded("krb5")){
			throw new \Exception('You need to install php-pecl-krb5 to access kerberos-authenticated CIFS shares');
		}

		$cacheFile = getenv("KRB5CCNAME");
		if (!$cacheFile) {
			$cacheFile = "FILE:/tmp/krb5cc_" . getmyuid() . "_typo3_storage_" . $configuration['storageUid'];
			putenv("KRB5CCNAME=" . $cacheFile);
		}

		$krb5 = new \KRB5CCache();
		try {
			$krb5->open($cacheFile);
			$krb5->isValid();
		} catch(\Exception $e) {
			// Cached ticket not found or expired
			if ($configuration['keytab']) {
				if (!$krb5->initKeytab($configuration['principal'], $configuration['keytab'])) {
					throw new \Exception("Failed authenticating using Kerberos with keytab", 0, $e);
				}
			} else {
				if (!$krb5->initPassword($configuration['user'], $configuration['password'])) {
					throw new \Exception("Failed authenticating using Kerberos with user name", 0, $e);
				}
			}

			$krb5->save($cacheFile);
		}
	}
}

