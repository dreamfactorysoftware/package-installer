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
namespace DreamFactory\Tools\Composer;

use Composer\Composer;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Kisma\Core\Exceptions\FileSystemException;
use Kisma\Core\Utility\Inflector;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;

Log::setDefaultLog( './log/package.installer.log' );

/**
 * PackageInstaller
 * Class/plug-in/library/jetpack installer
 */
class PackageInstaller extends LibraryInstaller
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
	 * @var string The package installation path relative to base
	 */
	const DEFAULT_PACKAGE_INSTALL_PATH = '/applications';
	/**
	 * @var string The plugin installation path relative to base
	 */
	const DEFAULT_PLUGIN_INSTALL_PATH = '/.private/library';
	/**
	 * @var string
	 */
	const DEFAULT_PLUGIN_LINK_PATH = '/web';
	/**
	 * @var int The default package type
	 */
	const DEFAULT_PACKAGE_TYPE = PackageTypes::APPLICATION;
	/**
	 * @var string
	 */
	const FABRIC_MARKER = '/var/www/.fabric_hosted';

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
		//	Application
		'dreamfactory-application',
		//	Code library
		'dreamfactory-jetpack',
		//	Code/app hybrid
		'dreamfactory-plugin',
	);
	/**
	 * @var bool If true, install into user-space library
	 */
	protected $_packageType = PackageTypes::APPLICATION;
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

		$_logDir = $this->_baseInstallPath . '/log';
		@mkdir( $_logDir, 0777, true );

		Log::setDefaultLog( $_logDir . '/package.installer.log' );
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 */
	public function install( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_validatePackage( $package );

		parent::install( $repo, $package );

		Log::info( 'Creating links for package "' . $package->getPrettyName() . ' ' . $package->getVersion() );
		$this->_createLinks();
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 */
	public function uninstall( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_validatePackage( $package );

		parent::uninstall( $repo, $package );

		Log::info( 'Removing package "' . $this->_packageName . ' ' . $package->getVersion() );
		$this->_deleteLinks();
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
		$this->_deleteLinks();

		parent::update( $repo, $initial, $target );

		Log::info( 'Update package "' . $initial->getPrettyName() . ' ' . $initial->getVersion() );
		$this->_createLinks();
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
	 *
	 * @throws \InvalidArgumentException
	 * @return bool
	 */
	protected function _validatePackage( PackageInterface $package )
	{
		static $_validated = false;

		//	Don't do more than once...
		if ( $_validated )
		{
			return true;
		}

		//	Link path for plug-ins
		$this->_parseConfiguration( $package );

		Log::info( 'DreamFactory Package Installer > ' . $this->_packageName . ' > Version ' . $package->getVersion() );

		//	Only install DreamFactory packages if not a plug-in
		if ( static::ALLOWED_PACKAGE_PREFIX != $this->_packagePrefix && $this->_packageType == PackageTypes::APPLICATION )
		{
			Log::error( '  * Invalid package type' );
			throw new \InvalidArgumentException( 'This package is not one that can be installed by this installer.' . PHP_EOL . '  * Name: ' .
												 $this->_packageName );
		}

		//	Build the installation path...
		$this->_buildInstallPath( $this->_packagePrefix, $this->_packageSuffix );

		//	Get supported types
		if ( null !== ( $_types = Option::get( $this->_config, 'supported-types' ) ) )
		{
			foreach ( $_types as $_type )
			{
				if ( !in_array( $this->_supportedTypes, $_type ) )
				{
					$this->_supportedTypes[] = $_type;
					Log::debug( '  * Added package type "' . $_type . '"' );
				}
			}
		}

		Log::debug( '  * Install type: ' . Inflector::display( PlatformTypes::nameOf( $this->_packageType ) ) );
		Log::debug( '  * Install path: ' . $this->_packageInstallPath );

		if ( null !== ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			foreach ( $_links as $_link )
			{
				Log::debug(
					'  *   Link found: ' . Option::get( $_link, 'target', $this->_packageInstallPath ) . ' -> ' .
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

		$_extra = Option::clean( $package->getExtra() );

		//	Get the extra stuff
		if ( !empty( $_extra ) )
		{
			//	Read configuration section. Can be an array or name of file to include
			if ( null !== ( $_config = Option::get( $_extra, 'config' ) ) )
			{
				if ( !is_array( $_config ) && is_file( $_config ) && is_readable( $_config ) )
				{
					/** @noinspection PhpIncludeInspection */
					if ( false === ( $_config = @include( $_config ) ) )
					{
						Log::error( 'File system error reading package configuration file: ' . $_config );
						$_config = array();
					}
				}
			}
		}

		if ( empty( $_config ) )
		{
			$_config = array(
				'name'      => $this->_packageSuffix,
				'bootstrap' => 'autoload.php',
				'links'     => array(),
				'routes'    => array(),
			);
		}
		else if ( !isset( $_config['links'] ) || empty( $_config['links'] ) )
		{
			$_config['links'] = array(
				array(
					'target' => null,
					'link'   => $this->_packageSuffix,
				),
			);
		}

		//	Check the type...
		$this->_packageType = $_config['type'] = Option::get( $_config, 'type', PackageTypes::APPLICATION );

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
		$_subPath = $this->_getInstallSubPath();

		//	Construct relative install path (base + sub + package name = /storage/[sub]/vendor/package-name). Remove leading/trailing slashes and spaces
		$_installPath = trim( static::DEFAULT_INSTALL_PATH . $_subPath . '/' . $vendor . '/' . $package, ' /' /** intentional space */ );

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
	 * Constructs the relative path (from composer.json) to the install directory
	 *
	 * @return string
	 */
	protected function _getInstallSubPath()
	{
		switch ( $this->_packageType )
		{
			case PackageTypes::APPLICATION:
				$_path = static::DEFAULT_PACKAGE_INSTALL_PATH;
				break;

			default:
				$_path = static::DEFAULT_PLUGIN_INSTALL_PATH;
				break;
		}

		return $_path;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( $packageType )
	{
		return in_array( $packageType, $this->_supportedTypes );
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _createLinks()
	{
		if ( null === ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			return;
		}

		//	Only link for non-apps
		if ( PackageTypes::APPLICATION == $this->_packageType )
		{
			return;
		}

		//	Make the links
		foreach ( $_links as $_link )
		{
			$_target = Option::get( $_link, 'target', $this->_packageInstallPath );
			$_linkName = Option::get( $_link, 'link', static::DEFAULT_PLUGIN_LINK_PATH . '/' . $this->_packageSuffix );

			//	Already linked?
			if ( \is_link( $_linkName ) )
			{
				Log::debug( '  * Package "' . $this->_packageName . '" already linked.' );
			}
			else if ( false === @\symlink( $_target, $_linkName ) )
			{
				throw new FileSystemException( 'Unable to create link: ' . $_linkName );
			}

			Log::debug( '  * Package "' . $this->_packageName . '" linked.', array( 'target' => $_target, 'link' => $_linkName ) );
		}
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _deleteLinks()
	{
		if ( null === ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			return;
		}

		//	Only link for non-apps
		if ( PackageTypes::APPLICATION == $this->_packageType )
		{
			return;
		}

		//	Make the links
		foreach ( $_links as $_link )
		{
			$_target = Option::get( $_link, 'target', $this->_packageInstallPath );
			$_linkName = Option::get( $_link, 'link', static::DEFAULT_PLUGIN_LINK_PATH . '/' . $this->_packageSuffix );

			//	Already linked?
			if ( !\is_link( $_linkName ) )
			{
				Log::warning( '  * Package "' . $this->_packageName . '" link not found.' );
			}
			else if ( false === @\unlink( $_linkName ) )
			{
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

}
