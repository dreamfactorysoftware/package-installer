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
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use DreamFactory\Tools\Composer\Enums\PackageTypes;
use Kisma\Core\Exceptions\DataStoreException;
use Kisma\Core\Exceptions\FileSystemException;
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility\Sql;

/**
 * Installer
 * Class/plug-in/library/jetpack installer
 */

/** @noinspection PhpDocMissingThrowsInspection */
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
	const DEFAULT_PACKAGE_TYPE = PackageTypes::APPLICATION;
	/**
	 * @var string
	 */
	const FABRIC_MARKER = '/var/www/.fabric_hosted';
	/**
	 * @var string
	 */
	const DEFAULT_MANIFEST_PATH = '/.manifest';

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
		PackageTypes::APPLICATION => '/applications',
		PackageTypes::JETPACK     => '/lib',
		PackageTypes::LIBRARY     => '/lib',
		PackageTypes::PLUGIN      => '/plugins',
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
	/**
	 * @var string
	 */
	protected $_manifestPath = self::DEFAULT_MANIFEST_PATH;
	/**
	 * @var IOInterface
	 */
	protected $_io;

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

		$this->_io = $io;
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

		$this->_addApplication( $package );
		$this->_createLinks( $package );
		$this->_addManifest( $package );
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
		$this->_deleteApplication( $initial );
		$this->_deleteLinks( $initial );
		$this->_removeManifest( $initial );
		$this->_runPackageScripts( $initial );

		//	In with the new...
		$this->_addApplication( $target );
		$this->_createLinks( $target );
		$this->_addManifest( $target );
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

		$this->_deleteApplication( $package );
		$this->_deleteLinks( $package );
		$this->_removeManifest( $package );
		$this->_runPackageScripts( $package );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath( PackageInterface $package )
	{
		if ( empty( $this->_packageInstallPath ) )
		{
			$this->_validatePackage( $package );
		}

		return $this->_packageInstallPath;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( $packageType )
	{
		return \array_key_exists( $packageType, $this->_supportedTypes );
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 *
	 * @return bool
	 */
	public function isInstalled( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		if ( empty( $this->_packageInstallPath ) )
		{
			$this->_validatePackage( $package );
		}

		return is_dir( $this->_packageInstallPath . '/.git' );
	}

	/**
	 * @param PackageInterface $package
	 *
	 * @throws Exception
	 * @throws \Composer\Downloader\FilesystemException
	 */
	protected function _addManifest( PackageInterface $package )
	{
		$_fileName = $this->_getManifestName( $package );
		$_manifest = $this->_buildManifest( $package );

		if ( false === \file_put_contents( $_fileName, $_manifest ) )
		{
			$this->_io->write( '  - <error>File system error writing manifest file</error>' );
			throw new \Composer\Downloader\FilesystemException( 'File system error writing manifest file: ' . $_fileName );
		}
	}

	/**
	 * @param PackageInterface $package
	 *
	 * @throws Exception
	 * @throws \Composer\Downloader\FilesystemException
	 */
	protected function _removeManifest( PackageInterface $package )
	{
		/** @var Filesystem $_fs */
		$_fileName = $this->_getManifestName( $package, $_fs );

		if ( file_exists( $_fileName ) && false === $_fs->remove( $_fileName ) )
		{
			$this->_io->write( '  - <error>File system error while removing manifest entry: ' . $_fileName . '</error>' );
		}
	}

	/**
	 * @param PackageInterface $package
	 *
	 * @throws \Exception
	 * @return string
	 */
	protected function _buildManifest( PackageInterface $package )
	{
		$_options = 0;

		if ( defined( \JSON_UNESCAPED_SLASHES ) )
		{
			$_options += \JSON_UNESCAPED_SLASHES;
		}

		if ( defined( \JSON_PRETTY_PRINT ) )
		{
			$_options += \JSON_PRETTY_PRINT;
		}

		$_dumper = new ArrayDumper();
		$_dumpData = $_dumper->dump( $package );

		if ( false === ( $_jsonData = json_encode( $_dumpData, $_options ) ) )
		{
			$this->_io->write( '  - <error>Failure while encoding manifest data</error>' );
			throw new \Exception( 'Failure encoding manifest data: ' . print_r( $_dumpData, true ) );
		}

		return $_jsonData;
	}

	/**
	 * @param PackageInterface $package
	 *
	 * @return bool
	 */
	protected function _addApplication( PackageInterface $package )
	{
		if ( null !== ( $_app = Option::get( $this->_config, 'application' ) ) )
		{
			$this->_io->write( '  - <info>No registration requested</info>' );

			return;
		}

		$_sql = <<<SQL
INSERT INTO df_sys_app (
  `api_name`,
  `name`,
  `description`,
  `is_active`,
  `url`,
  `is_url_external`,
  `import_url`,
  `storage_service_id`,
  `storage_container`,
  `requires_fullscreen`,
  `allow_fullscreen_toggle`,
  `toggle_location`,
  `requires_plugin`
) VALUES (
  :api_name,
  :name,
  :description,
  :is_active,
  :url,
  :is_url_external,
  :import_url,
  :storage_service_id,
  :storage_container,
  :requires_fullscreen,
  :allow_fullscreen_toggle,
  :toggle_location,
  :requires_plugin
)
SQL;
		$_data = array(
			':api_name'                => $_apiName = Option::get( $_app, 'api-name', $this->_packageSuffix ),
			':name'                    => Option::get( $_app, 'name', $this->_packageSuffix ),
			':description'             => Option::get( $_app, 'description' ),
			':is_active'               => Option::getBool( $_app, 'is-active' ),
			':url'                     => Option::get( $_app, 'url' ),
			':is_url_external'         => Option::getBool( $_app, 'is-url-external' ),
			':import_url'              => Option::get( $_app, 'import-url' ),
			':requires_fullscreen'     => Option::getBool( $_app, 'requires-fullscreen' ),
			':allow_fullscreen_toggle' => Option::getBool( $_app, 'allow-fullscreen-toggle' ),
			':toggle_location'         => Option::get( $_app, 'toggle-location' ),
			':requires_plugin'         => 1,
		);

		try
		{
			/** @noinspection PhpIncludeInspection */
			$_dbConfig = require( 'config/database.config.php' );

			Sql::setConnectionString(
				Option::get( $_dbConfig, 'connectionString' ),
				Option::get( $_dbConfig, 'username' ),
				Option::get( $_dbConfig, 'password' )
			);

			if ( false === ( $_result = Sql::execute( $_sql, $_data ) ) )
			{
				$this->_io->write( '  - <error>Error storing application to database: </error>' . print_r( $_data, true ) );
				throw new DataStoreException( 'Error saving application row: ' . print_r( $_data, true ), 500 );
			}
		}
		catch ( Exception $_ex )
		{
			$this->_io->write( '  - <error>Error registering package. Manual registration required.' );

			return false;
		}

		$this->_io->write( '  - <info>Package registered as "' . $_apiName . '" with DSP.</info>' );

		return true;
	}

	/**
	 * @param PackageInterface $package
	 *
	 * @return bool
	 */
	protected function _deleteApplication( PackageInterface $package )
	{
		if ( null === ( $_app = Option::get( $this->_config, 'application' ) ) )
		{
			$this->_io->write( '  - <info>No registration requested</info>' );

			return;
		}

		$_sql = <<<SQL
UPDATE df_sys_app SET
	`is_active` = :is_active
WHERE
	`api_name` = :api_name
SQL;

		$_data = array(
			':api_name'  => $_apiName = Option::get( $_app, 'api-name', $this->_packageSuffix ),
			':is_active' => 0
		);

		try
		{
			/** @noinspection PhpIncludeInspection */
			$_dbConfig = require( 'config/database.config.php' );

			Sql::setConnectionString(
				Option::get( $_dbConfig, 'connectionString' ),
				Option::get( $_dbConfig, 'username' ),
				Option::get( $_dbConfig, 'password' )
			);

			if ( false === ( $_result = Sql::execute( $_sql, $_data ) ) )
			{
				$this->_io->write( '  - <error>Error storing application to database: </error>' . print_r( $_data, true ) );
				throw new DataStoreException( 'Error saving application row: ' . print_r( $_data, true ), 500 );
			}
		}
		catch ( Exception $_ex )
		{
			$this->_io->write( '  - <error>Error unregistering package. Manual unregistration required.</error>' );

			return false;
		}

		$this->_io->write( '  - <info>Package unregistered as "' . $_apiName . '" from DSP.</info>' );

		return true;
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
		//	Link path for plug-ins
		$this->_parseConfiguration( $package );

		if ( $this->_io->isDebug() )
		{
			$this->_io->write( '  - Validating package payload' );
		}

		//	Only install DreamFactory packages if not a plug-in
		if ( static::ALLOWED_PACKAGE_PREFIX != $this->_packagePrefix )
		{
			$this->_io->write( '  - <error>Package type "' . $this->_packagePrefix . '" invalid</error>' );
			throw new \InvalidArgumentException( 'This package is not one that can be installed by this installer.' . PHP_EOL . '  * Name: ' . $this->_packageName );
		}

		//	Get supported types
		if ( null !== ( $_types = Option::get( $this->_config, 'supported-types' ) ) )
		{
			foreach ( $_types as $_type => $_path )
			{
				if ( !array_key_exists( $_type, $this->_supportedTypes ) )
				{
					$this->_supportedTypes[$_type] = $_path;
					$this->_io->write( '  - <info>Added support for package type "' . $_type . '"</info>' );
				}
			}
		}

		//	Build the installation path...
		$this->_buildInstallPath( $this->_packagePrefix, $this->_packageSuffix );
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
					$this->_io->write( '  - <error>File system error reading package configuration file: ' . $_configFile . '</error>' );
					$_config = array();
				}

				if ( !is_array( $_config ) )
				{
					$this->_io->write( '  - <error>The "config" file specified in this package is invalid: ' . $_configFile . '</error>' );
					throw new \InvalidArgumentException( 'The "config" file specified in this package is invalid.' );
				}
			}
		}

		//	Merge any config with the extra data
		$_config = array_merge( $_extra, $_config );

		//	Pull out the links
		$_links = Option::get( $_config, 'links' );

		//	If no links found, create default for plugin
		if ( empty( $_links ) && PackageTypes::PLUGIN == $this->_packageType )
		{
			$_link = array(
				'target' => null,
				'link'   => Option::get( $_config, 'api_name', $this->_packageSuffix )
			);

			$_config['links'] = $_links = array( $this->_normalizeLink( $_link ) );
		}

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
	 * @param array $link
	 *
	 * @return array
	 */
	protected function _normalizeLink( $link )
	{
		//	Adjust relative directory to absolute
		$_target =
			rtrim( $this->_baseInstallPath, '/' ) . '/' . trim( $this->_packageInstallPath, '/' ) . '/' . ltrim( Option::get( $link, 'target', $this->_packageSuffix ), '/' );

		$_linkName = trim( static::DEFAULT_PLUGIN_LINK_PATH, '/' ) . '/' . Option::get( $link, 'link', $this->_packageSuffix );

		return array( $_target, $_linkName );
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _createLinks( PackageInterface $package )
	{
		if ( null === ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			if ( $this->_io->isDebug() )
			{
				$this->_io->write( '  - <info>Package contains no links</info>' );
			}

			return;
		}

		$this->_io->write( '  - Creating package symlinks' );

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			//	Adjust relative directory to absolute
			list( $_target, $_linkName ) = $this->_normalizeLink( $_link );

			if ( \is_link( $_linkName ) )
			{
				$this->_io->write( '  - <info>Package already linked</info>' );
				continue;
			}

			if ( false === @\symlink( $_target, $_linkName ) )
			{
				$this->_io->write( '  - <error>File system error creating symlink: ' . $_linkName . '</error>' );
				throw new FileSystemException( 'Unable to create symlink: ' . $_linkName );
			}

			$this->_io->write( '  - <info>Package links created</info>' );
		}
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _deleteLinks( PackageInterface $package )
	{
		if ( null === ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			if ( $this->_io->isDebug() )
			{
				$this->_io->write( '  - <info>Package contains no links</info>' );
			}

			return;
		}

		$this->_io->write( '  - Removing package symlinks' );

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			//	Adjust relative directory to absolute
			list( $_target, $_linkName ) = $this->_normalizeLink( $_link );

			//	Already linked?
			if ( !\is_link( $_linkName ) )
			{
				$this->_io->write( '  - <warning>Package link not found to remove:</warning> <info>' . $_linkName . '</info>' );
				continue;
			}

			if ( false === @\unlink( $_linkName ) )
			{
				$this->_io->write( '  - <error>File system error removing symlink: ' . $_linkName . '</error>' );
				throw new FileSystemException( 'Unable to remove symlink: ' . $_linkName );
			}

			$this->_io->write( '  - <info>Package links removed</info>' );
		}
	}

	/**
	 * Creates a manifest file name
	 *
	 * @param PackageInterface $package
	 * @param Filesystem       $fs
	 *
	 * @return string
	 */
	protected function _getManifestName( PackageInterface $package, &$fs = null )
	{
		$_fullPath = $this->_baseInstallPath . Option::get( $this->_supportedTypes, $this->_packageType ) . $this->_manifestPath;

		$fs = $_fs = $fs ? : new Filesystem();
		$_fs->ensureDirectoryExists( $_fullPath );

		return $_fullPath . '/' . str_replace( array( ' ', '/', '\\', '[', ']' ), '_', $package->getUniqueName() ) . '.json';
	}

	/**
	 * @return string
	 */
	public function getBaseInstallPath()
	{
		return $this->_baseInstallPath;
	}

	/**
	 * @return array
	 */
	public function getConfig()
	{
		return $this->_config;
	}

	/**
	 * @return boolean
	 */
	public function getFabricHosted()
	{
		return $this->_fabricHosted;
	}

	/**
	 * @return \Composer\IO\IOInterface
	 */
	public function getIo()
	{
		return $this->_io;
	}

	/**
	 * @return string
	 */
	public function getManifestPath()
	{
		return $this->_manifestPath;
	}

	/**
	 * @return string
	 */
	public function getPackageInstallPath()
	{
		return $this->_packageInstallPath;
	}

	/**
	 * @return string
	 */
	public function getPackageName()
	{
		return $this->_packageName;
	}

	/**
	 * @return string
	 */
	public function getPackagePrefix()
	{
		return $this->_packagePrefix;
	}

	/**
	 * @return string
	 */
	public function getPackageSuffix()
	{
		return $this->_packageSuffix;
	}

	/**
	 * @return int
	 */
	public function getPackageType()
	{
		return $this->_packageType;
	}

	/**
	 * @return array
	 */
	public function getSupportedTypes()
	{
		return $this->_supportedTypes;
	}

}
