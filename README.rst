==========================
SMB/CIFS support for TYPO3
==========================

Content:

- Usage_
- Installation_

Usage
=====

This extension adds SMB / CIFS folders to your TYPO3 backend.

After Installation_, you can add a new file storage by specifying the "CIFS filesystem" driver.
Then, you have the following configuration options:

URL
~~~

an SMB url. Can be forward slashed or backslashed URL. Exxamples:

    smb://fileserver01/files/

    \\fileserver01\files

Domain
~~~~~~

The domain

User
~~~~

User name for login (not needed with Kerberos)

Password
~~~~~~~~

Password for login (not needed with Kerberos)

Use Kerberos
~~~~~~~~~~~~

Check this if you want to use Kerberos login using a keytab file

Kerberos keytab
~~~~~~~~~~~~~~~

Path of your keytab file

Kerberos principal
~~~~~~~~~~~~~~~~~~

The principal for kerberos login. Example:

    HTTP/webserver01.domain

Prefix for public URLs
~~~~~~~~~~~~~~~~~~~~~~

Prefix used when generating public URLs. This can be used to link the files directly inside an intranet


Installation
============

PHP requirements:
-----------------

- PHP package libsmbclient-php  
  Can be installed using package `php-smbclient` in both RPM and DEB systems
- (If Kerberos support is needed) PECL package krb5  
  Can be installed using RPM package `php-pecl-krb5` or using `pecl` otherwise

SELinux permissions
-------------------

These permissions _might_ have to be checked if you use SELinux:

    setsebool -P httpd_can_network_connect 1
    setsebool -P nis_enabled 1

