<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) Library Installer
 * Copyright 2012-2013 DreamFactory Software, Inc. {@email support@dreamfactory.com}
 *
 * DreamFactory Services Platform(tm) Library Installer {@link http://github.com/dreamfactorysoftware/lib-platform-installer}
 * DreamFactory Services Platform(tm) {@link http://github.com/dreamfactorysoftware/dsp-core}
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace DreamFactory\Composer\Utility;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Kisma\Core\Exceptions\FileSystemException;

/**
 * PlatformInstaller
 * Class/plug-in/library/jetpack installer
 */
class PlatformInstaller extends LibraryInstaller
{
	//*************************************************************************
	//* Constants
	//*************************************************************************

	/**
	 * @var string
	 */
	const PACKAGE_PREFIX = 'dreamfactory';
	/**
	 * @var string
	 */
	const BASE_INSTALL_PATH = '/shared';
	/**
	 * @var string
	 */
	const USER_INSTALL_PATH = '/storage/library';
	/**
	 * @var string
	 */
	const DEFAULT_INSTALL_NAMESPACE = 'app';
	/**
	 * @var string
	 */
	const FABRIC_MARKER = '/var/www/.fabric_hosted';
	/**
	 * @var string
	 */
	const DSP_USER_LIBRARY = 'dsp-user-library';

	//*************************************************************************
	//* Members
	//*************************************************************************

	/**
	 * @var bool
	 */
	protected $_fabricHosted = false;
	/**
	 * @var array The types of packages I can install
	 */
	protected $_supportPackageTypes
		= array(
			'dreamfactory-platform',
			'dreamfactory-jetpack',
			'dreamfactory-fuelcell',
			self::DSP_USER_LIBRARY,
		);
	/**
	 * @var bool If true, install into user-space library
	 */
	protected $_userPackage = false;

	//*************************************************************************
	//* Methods
	//*************************************************************************

	/**
	 * @param IOInterface $io
	 * @param Composer    $composer
	 * @param string      $type
	 */
	public function __construct( IOInterface $io, Composer $composer, $type = 'library' )
	{
		parent::__construct( $io, $composer, $type );
		$this->_fabricHosted = file_exists( static::FABRIC_MARKER );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath( PackageInterface $package )
	{
		$_parts = explode( '/', $_packageName = $package->getPrettyName(), 2 );

		$this->_userPackage = ( static::DSP_USER_LIBRARY == $package->getType() );

		if ( static::PACKAGE_PREFIX != ( $_prefix = @current( $_parts ) ) && !$this->_userPackage )
		{
			throw new \InvalidArgumentException(
				'This package is not a DreamFactory package and cannot be installed by this installer.' . PHP_EOL .
				'  * Name: ' . $package->getPrettyName() . PHP_EOL .
				'  * Parts: ' . print_r( $_parts, true ) . PHP_EOL
			);
		}

		//	Effectively /docRoot/shared/[vendor]/[namespace]/[package]
		return $this->_buildInstallPath(
			dirname( $this->vendorDir ) . ( $this->_userPackage ? static::USER_INSTALL_PATH : static::BASE_INSTALL_PATH ),
			$_prefix,
			$_parts[1]
		);
	}

	/**
	 * Build the install path
	 *
	 * @param string $baseInstallPath
	 * @param string $prefix
	 * @param string $package
	 * @param bool   $createIfMissing
	 *
	 * @throws \InvalidArgumentException
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 * @return string
	 */
	protected function _buildInstallPath( $baseInstallPath, $prefix, $package, $createIfMissing = true )
	{
		/**
		 *    Package like dreamfactory/app-xyz or dreamfactory/lib-abc will
		 *    go into ./apps/app-xyz and ./lib/lib-abc respectively)
		 *
		 * User libraries are installed into /storage/library/<prefix>/<package>
		 *
		 * baseInstall  : ./shared
		 * prefix       : dreamfactory
		 * parts        : abc-xyz
		 */
		if ( !is_dir( $baseInstallPath ) || false === realpath( $baseInstallPath ) )
		{
			if ( false === @mkdir( $baseInstallPath, 0777, true ) )
			{
				throw new FileSystemException( 'Unable to create directory: ' . $baseInstallPath );
			}
		}

		//	i.e. /var/www/dsp-share/dreamfactory/
		$_fullPath = rtrim( realpath( $baseInstallPath ) . '/' . $prefix, ' /' ) . '/';

		//	Split package type off of front (app-*, lib-*, web-*, jetpack-*, fuelcell-*, etc.)
		if ( !$this->_userPackage )
		{
			$_subparts = explode( '-', $package, 2 );
			//	/path/to/project/shared/[vendor]/[namespace]/[package]
			$_fullPath .= empty( $_subparts ) ? static::DEFAULT_INSTALL_NAMESPACE : current( $_subparts );
		}

		$_fullPath .= '/' . $package;

		if ( $createIfMissing && !is_dir( $_fullPath ) )
		{
			if ( false === @mkdir( $_fullPath, 0777, true ) )
			{
				throw new FileSystemException( 'Unable to create installation path "' . $_fullPath . '"' );
			}
		}

		return $_fullPath;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( $packageType )
	{
		return in_array( $packageType, $this->_supportPackageTypes );
	}
}
