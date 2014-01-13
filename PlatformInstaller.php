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
namespace DreamFactory\Composer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
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
	const BASE_INSTALL_PATH = '/storage/applications';
	/**
	 * @var string
	 */
	const PLUG_IN_INSTALL_PATH = '/storage/.private/library';
	/**
	 * @var string
	 */
	const PLUG_IN_LINK_PATH = '/web';
	/**
	 * @var string
	 */
	const FABRIC_MARKER = '/var/www/.fabric_hosted';
	/**
	 * @var string
	 */
	const DSP_PLUG_IN = 'dsp-plugin';

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
			self::DSP_PLUG_IN,
		);
	/**
	 * @var bool If true, install into user-space library
	 */
	protected $_plugIn = false;
	/**
	 * @var string The package vendor
	 */
	protected $_vendor;
	/**
	 * @var string The name of the package
	 */
	protected $_packageName;
	/**
	 * @var string
	 */
	protected $_installPath;
	/**
	 * @var string
	 */
	protected $_linkName;
	/**
	 * @var string
	 */
	protected $_linkPath;

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
	public function getPackageBasePath( PackageInterface $package )
	{
		$this->_validatePackage( $package );

		return $this->_installPath;
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 */
	public function install( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_validatePackage( $package );

		parent::install( $repo, $package );
		$this->_linkPlugIn( $this->_installPath, $this->_linkName );
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $initial
	 * @param PackageInterface             $target
	 *
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	public function update( InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target )
	{
		$this->_validatePackage( $target );

		$this->_unlinkPlugIn();
		parent::update( $repo, $initial, $target );
		$this->_linkPlugIn();
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 *
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	public function uninstall( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_validatePackage( $package );

		parent::uninstall( $repo, $package );
		$this->_unlinkPlugIn();
	}

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath( PackageInterface $package )
	{
		$this->_validatePackage( $package );

		return $this->_installPath;
	}

	/**
	 * @param PackageInterface $package
	 *
	 * @throws \InvalidArgumentException
	 * @return string
	 */
	protected function _validatePackage( PackageInterface $package )
	{
		$this->_plugIn = ( static::DSP_PLUG_IN == $package->getType() );
		$_parts = explode( '/', $_packageName = $package->getPrettyName(), 2 );

		//	Only install DreamFactory packages if not a plug-in
		if ( static::PACKAGE_PREFIX != ( $_prefix = @current( $_parts ) ) && !$this->_plugIn )
		{
			throw new \InvalidArgumentException(
				'This package is not a DreamFactory package and cannot be installed by this installer.' . PHP_EOL .
				'  * Name: ' . $package->getPrettyName() . PHP_EOL .
				'  * Parts: ' . print_r( $_parts, true ) . PHP_EOL
			);
		}

		//	Effectively /docRoot/shared/[vendor]/[namespace]/[package]
		$this->_installPath = $this->_buildInstallPath(
			dirname( $this->vendorDir ) . ( $this->_plugIn ? static::PLUG_IN_INSTALL_PATH : static::BASE_INSTALL_PATH ),
			$_prefix,
			$_parts[1]
		);

		//	Link path for plug-ins
		$this->_linkName = $_parts[1];
		$this->_linkPath = dirname( $this->vendorDir ) . static::PLUG_IN_LINK_PATH . '/' . $_parts[1];

		return true;
	}

	/**
	 * Build the install path
	 *
	 * @param string $baseInstallPath
	 * @param string $vendor
	 * @param string $package
	 * @param bool   $createIfMissing
	 *
	 * @throws \InvalidArgumentException
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 * @return string
	 */
	protected function _buildInstallPath( $baseInstallPath, $vendor, $package, $createIfMissing = true )
	{
		/**
		 * User libraries are installed into /storage/.private/library/<vendor>/<package>
		 *
		 * $baseInstall : /storage/.private/library
		 * $vendor      : dreamfactory
		 * $package     : abc-xyz
		 */
		if ( !is_dir( $baseInstallPath ) || false === realpath( $baseInstallPath ) )
		{
			if ( false === @mkdir( $baseInstallPath, 0777, true ) )
			{
				throw new FileSystemException( 'Unable to create directory: ' . $baseInstallPath );
			}
		}

		//	Build path
		$_fullPath =
			rtrim( realpath( $baseInstallPath ) . '/' . $vendor, ' /' ) . '/' .
			( $this->_plugIn ? static::PLUG_IN_INSTALL_PATH : static::BASE_INSTALL_PATH ) . '/' .
			$package;

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

	/**
	 * @param string $target
	 * @param string $link
	 *
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _linkPlugIn( $target = null, $link = null )
	{
		$target = $target ? : $this->_installPath;
		$link = $link ? : $this->_linkPath;

		//	Already linked?
		if ( \is_link( $link ) )
		{
			return;
		}

		if ( false === @\symlink( $target, $link ) )
		{
			throw new FileSystemException( 'Unable to create link: ' . $link );
		}
	}

	/**
	 * @param string $link
	 *
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _unlinkPlugIn( $link = null )
	{
		$link = $link ? : $this->_linkPath;

		//	Unlink from linked root
		if ( $this->_plugIn && \is_link( $link ) )
		{
			if ( false === @\unlink( $link ) )
			{
				throw new FileSystemException( 'Unable to unlink link: ' . $link );
			}
		}
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 *
	 * @return bool
	 */
	public function isInstalled( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_validatePackage( $package );

		return \is_link( $this->_linkPath );
	}
}