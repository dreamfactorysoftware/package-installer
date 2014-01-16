<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) Package Installer
 * Copyright 2012-2013 DreamFactory Software, Inc. {@email support@dreamfactory.com}
 *
 * DreamFactory Services Platform(tm) Package Installer {@link http://github.com/dreamfactorysoftware/package-installer}
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
namespace DreamFactory\Tools\Composer\Package;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use DreamFactory\Tools\Composer\Enums\PackageTypeNames;
use Kisma\Core\Exceptions\FileSystemException;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;

/**
 * Installer
 * Class/plug-in/library/jetpack installer
 */
class Installer extends LibraryInstaller
{
	//*************************************************************************
	//* Constants
	//*************************************************************************

	/**
	 * @var string
	 */
	const ALLOWED_PACKAGE_PREFIX = 'dreamfactory';
	/**
	 * @var string The base installation path
	 */
	const DEFAULT_INSTALL_PATH = '/storage';
	/**
	 * @var string
	 */
	const DEFAULT_PLUGIN_LINK_PATH = '/web';
	/**
	 * @var int The default package type
	 */
	const DEFAULT_PACKAGE_TYPE = PackageTypeNames::APPLICATION;
	/**
	 * @var string
	 */
	const FABRIC_MARKER = '/var/www/.fabric_hosted';
	/**
	 * @type string The name of the log file
	 */
	const LOG_FILE_NAME = 'package-installer.log';

	//*************************************************************************
	//* Members
	//*************************************************************************

	/**
	 * @var bool
	 */
	protected $_fabricHosted = false;
	/**
	 * @var array The types of packages I can install. Can be changed via composer.json:extra.supported-types[]
	 */
	protected $_supportedTypes = array(
		PackageTypeNames::APPLICATION => '/applications',
		PackageTypeNames::JETPACK     => '/lib',
		PackageTypeNames::LIBRARY     => '/lib',
		PackageTypeNames::PLUGIN      => '/plugins',
	);
	/**
	 * @var int string package type
	 */
	protected $_packageType = self::DEFAULT_PACKAGE_TYPE;
	/**
	 * @var string The full name of the package, i.e. "dreamfactory/portal-sandbox"
	 */
	protected $_packageName;
	/**
	 * @var string The package prefix, i.e. "dreamfactory"
	 */
	protected $_packagePrefix;
	/**
	 * @var string The non-vendor portion of the package name, i.e. "dreamfactory/portal-sandbox" is package name, package suffix is "portal-sandbox"
	 */
	protected $_packageSuffix;
	/**
	 * @var string The base installation path, where composer.json lives
	 */
	protected $_baseInstallPath;
	/**
	 * @var string The path of the install relative to $installBasePath, i.e. /storage/[.private/library|applications]/full-package-name
	 */
	protected $_packageInstallPath;
	/**
	 * @var array
	 */
	protected $_config = array();

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
		$this->_baseInstallPath = \getcwd();
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 */
	public function install( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_validatePackage( $package );

		parent::install( $repo, $package );

		$this->_createLinks( $package );
		$this->_addToManifest( $package );
		$this->_runPackageScripts( $package );
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
		$this->_validatePackage( $initial );

		parent::update( $repo, $initial, $target );

		//	Out with the old...
		$this->_deleteLinks( $initial );
		$this->_removeFromManifest( $initial );

		//	In with the new...
		$this->_createLinks( $target );
		$this->_addToManifest( $target );
		$this->_runPackageScripts( $target );
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 */
	public function uninstall( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_validatePackage( $package );

		parent::uninstall( $repo, $package );

		$this->_deleteLinks( $package );
		$this->_removeFromManifest( $package );
		$this->_runPackageScripts( $package );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath( PackageInterface $package )
	{
		$this->_validatePackage( $package );

		return $this->_packageInstallPath;
	}

	/**
	 * @param PackageInterface $package
	 * @param PackageInterface $initial Initial package if operation was an update
	 */
	protected function _addToManifest( PackageInterface $package, PackageInterface $initial = null )
	{
	}

	/**
	 * @param PackageInterface $package
	 */
	protected function _removeFromManifest( PackageInterface $package )
	{
	}

	/**
	 * @param PackageInterface $package
	 */
	protected function _runPackageScripts( PackageInterface $package )
	{
	}

	/**
	 * @param PackageInterface $package
	 *
	 * @throws \InvalidArgumentException
	 * @return bool
	 */
	protected function _validatePackage( PackageInterface $package )
	{
		$this->_enableLogging();

		//	Link path for plug-ins
		$this->_parseConfiguration( $package );

		Log::info( 'DreamFactory Package Installer > ' . $this->_packageName . ' > Version ' . $package->getVersion() );

		//	Only install DreamFactory packages if not a plug-in
		if ( static::ALLOWED_PACKAGE_PREFIX != $this->_packagePrefix )
		{
			Log::error( '  * Invalid package type' );
			throw new \InvalidArgumentException( 'This package is not one that can be installed by this installer.' .
												 PHP_EOL .
												 '  * Name: ' .
												 $this->_packageName );
		}

		//	Get supported types
		if ( null !== ( $_types = Option::get( $this->_config, 'supported-types' ) ) )
		{
			foreach ( $_types as $_type => $_path )
			{
				if ( !array_key_exists( $_type, $this->_supportedTypes ) )
				{
					$this->_supportedTypes[$_type] = $_path;
					Log::debug( '  * Added package type "' . $_type . '"' );
				}
			}
		}

		//	Build the installation path...
		$this->_buildInstallPath( $this->_packagePrefix, $this->_packageSuffix );

		Log::debug( '  * Install type: ' . $package->getType() );
		Log::debug( '  * Install path: ' . $this->_packageInstallPath );

		if ( null !== ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			foreach ( $_links as $_link )
			{
				Log::debug(
					'  *   Link found: ' .
					Option::get( $_link, 'target', $this->_packageInstallPath ) .
					' -> ' .
					Option::get( $_link, 'link', static::DEFAULT_PLUGIN_LINK_PATH )
				);
			}
		}

		return $_validated = true;
	}

	/**
	 * Parse a package configuration
	 *
	 * @param PackageInterface $package
	 *
	 * @throws \InvalidArgumentException
	 * @return array
	 */
	protected function _parseConfiguration( PackageInterface $package )
	{
		$_parts = explode( '/', $this->_packageName = $package->getPrettyName(), 2 );

		if ( 2 != count( $_parts ) )
		{
			throw new \InvalidArgumentException( 'The package "' . $this->_packageName . '" package name is malformed or cannot be parsed.' );
		}

		$this->_packagePrefix = $_parts[0];
		$this->_packageSuffix = $_parts[1];
		$this->_packageType = $package->getType();

		//	Get the extra stuff
		$_extra = Option::clean( $package->getExtra() );
		$_config = array();

		//	Read configuration section. Can be an array or name of file to include
		if ( null !== ( $_configFile = Option::get( $_extra, 'config' ) ) )
		{
			if ( is_string( $_configFile ) && is_file( $_configFile ) && is_readable( $_configFile ) )
			{
				/** @noinspection PhpIncludeInspection */
				if ( false === ( $_config = @include( $_configFile ) ) )
				{
					Log::error( 'File system error reading package configuration file: ' . $_configFile );
					$_config = array();
				}

				if ( !is_array( $_config ) )
				{
					Log::error( 'The "config" file specified in this package is invalid: ' . $_configFile );
					throw new \InvalidArgumentException( 'The "config" file specified in this package is invalid.' );
				}
			}
		}

		//	Merge any config with the extra data
		$_config = array_merge( $_extra, $_config );

		//	Pull out the links
		$_links = Option::get( $_config, 'links' );

		//	If no links found, create default for plugin
		if ( empty( $_links ) && PackageTypeNames::PLUGIN == $this->_packageType )
		{
			$_links = array(
				array(
					'target' => null,
					'link'   => Option::get( $_config, 'api_name', $this->_packageSuffix ),
				),
			);

			$_config['links'] = $_links;
		}

		Log::debug( 'Config completed: ' . print_r( $_config, true ) );

		return $this->_config = $_config;
	}

	/**
	 * Build the install path
	 *
	 * User libraries are installed into /storage/.private/library/<vendor>/<package>
	 * Applications   are installed into /storage/applications/<vendor>/<package>
	 *
	 * @param string $vendor
	 * @param string $package
	 * @param bool   $createIfMissing
	 *
	 * @throws \InvalidArgumentException
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 * @return string
	 */
	protected function _buildInstallPath( $vendor, $package, $createIfMissing = true )
	{
		//	Build path
		$_basePath = \realpath( getcwd() );
		$_subPath = Option::get( $this->_supportedTypes, $this->_packageType );

		//	Construct relative install path (base + sub + package name = /storage/[sub]/vendor/package-name). Remove leading/trailing slashes and spaces
		$_installPath = trim(
			Option::get( $this->_config, 'base-install-path', static::DEFAULT_INSTALL_PATH ) . $_subPath . '/' . $vendor . '/' . $package,
			' /'
		/** intentional space */
		);

		if ( $createIfMissing && !is_dir( $_basePath . '/' . $_installPath ) )
		{
			if ( false === @mkdir( $_basePath . '/' . $_installPath, 0775, true ) )
			{
				throw new FileSystemException( 'Unable to create installation path "' . $_basePath . '/' . $_installPath . '"' );
			}
		}

		return $this->_packageInstallPath = $_installPath;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( $packageType )
	{
		return \array_key_exists( $packageType, $this->_supportedTypes );
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _createLinks( PackageInterface $package )
	{
		if ( null === ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			Log::debug( '  * No links in package to create' );

			return;
		}

		Log::info( '  * Creating links for package "' . $package->getPrettyName() . ' ' . $package->getVersion() );

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			$_target = Option::get( $_link, 'target', $this->_packageInstallPath );
			$_linkName = static::DEFAULT_PLUGIN_LINK_PATH . '/' . Option::get( $_link, 'link', $this->_packageSuffix );

			//	Already linked?
			if ( \is_link( $_linkName ) )
			{
				Log::debug( '  * Package "' . $this->_packageName . '" already linked.' );
				continue;
			}
			else if ( false === @\symlink( $_target, $_linkName ) )
			{
				Log::error( '  * File system error creating symlink "' . $_linkName . '".' );
				throw new FileSystemException( 'Unable to create symlink: ' . $_linkName );
			}

			Log::debug( '  * Package "' . $this->_packageName . '" linked.', array( 'target' => $_target, 'link' => $_linkName ) );
		}
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _deleteLinks( PackageInterface $package )
	{
		if ( null === ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			Log::debug( '  * No links in package to remove' );

			return;
		}

		Log::info( '  * Removing links for package "' . $package->getPrettyName() . ' ' . $package->getVersion() );

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			$_target = Option::get( $_link, 'target', $this->_packageInstallPath );
			$_linkName = static::DEFAULT_PLUGIN_LINK_PATH . '/' . Option::get( $_link, 'link', $this->_packageSuffix );

			//	Already linked?
			if ( !\is_link( $_linkName ) )
			{
				Log::warning( '  * Package "' . $this->_packageName . '" link not found.' );
				continue;
			}
			else if ( false === @\unlink( $_linkName ) )
			{
				Log::error( '  * File system error removing symlink "' . $_linkName . '".' );
				throw new FileSystemException( 'Unable to remove symlink: ' . $_linkName );
			}

			Log::debug( '  * Package "' . $this->_packageName . '" link removed.', array( 'target' => $_target, 'link' => $_linkName ) );
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

		return is_dir( $this->_packageInstallPath );
	}

	protected function _enableLogging()
	{
		$_fs = new Filesystem();

		$_logDir = $this->_baseInstallPath . '/log/';
		$_fs->ensureDirectoryExists( $_logDir );

		Log::setDefaultLog( $_logDir . '/' . static::LOG_FILE_NAME );
	}

}
