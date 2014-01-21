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
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use DreamFactory\Tools\Composer\Enums\PackageTypes;
use Kisma\Core\Exceptions\FileSystemException;
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility\Sql;

/**
 * Installer
 * DreamFactory Package Installer
 *
 * Under each DSP installation lies a /storage directory. This plug-in installs DreamFactory DSP packages into this space
 *
 * /storage/plugins                                    Installation base (plug-in vendors)
 * /storage/plugins/.manifest/composer.json            Main config file
 *
 */
class Installer extends LibraryInstaller implements EventSubscriberInterface
{
	//*************************************************************************
	//* Constants
	//*************************************************************************

	/**
	 * @var string
	 */
	const ALLOWED_PACKAGE_PREFIX = 'dreamfactory';
	/**
	 * @var string
	 */
	const DEFAULT_DATABASE_CONFIG_FILE = '/config/database.config.php';
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
		PackageTypes::APPLICATION => '/applications',
		PackageTypes::JETPACK     => '/plugins',
		PackageTypes::LIBRARY     => '/plugins',
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
	protected $_baseInstallPath = './';
	/**
	 * @var string The path of the install relative to $installBasePath, i.e. ../../[applications|plugins]/vendor/package-name
	 */
	protected $_packageInstallPath = '../../';
	/**
	 * @var bool True if this install was started with "require-dev", false if "no-dev"
	 */
	protected static $_devMode = true;
	/**
	 * @var string The base directory of the DSP installation
	 */
	protected static $_platformBasePath = '../../../../';

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

		//	Make sure proper storage paths are available
		$this->_validateInstallationTree( $io, $composer );
	}

	/**
	 * {@InheritDoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			ScriptEvents::PRE_INSTALL_CMD => array( array( 'onOperation', 0 ) ),
			ScriptEvents::PRE_UPDATE_CMD  => array( array( 'onOperation', 0 ) ),
		);
	}

	/**
	 * @param Event $event
	 * @param bool  $devMode
	 */
	public static function onOperation( Event $event, $devMode )
	{
		$event->getIO()->write( '  - <info>Operation event fired</info>' );

		static::$_devMode = $devMode;
		static::$_platformBasePath = static::_findPlatformBasePath( $event->getIO(), \getcwd() );

		$event->getIO()->write(
			'  - ' . ( static::$_devMode ? 'Development' : 'Production' ) . ' installation: ' . static::$_platformBasePath
		);
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 */
	public function install( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
//		$this->_validatePackage( $package );

		parent::install( $repo, $package );

		$this->_createLinks( $package );
		$this->_addApplication( $package );
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
//		$this->_validatePackage( $initial );

		parent::update( $repo, $initial, $target );

		//	Out with the old...
		$this->_deleteLinks( $initial );
		$this->_deleteApplication( $initial );

		//	In with the new...
		$this->_validatePackage( $target );
		$this->_createLinks( $target );
		$this->_addApplication( $target );
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
		$this->_deleteApplication( $package );
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( $packageType )
	{
		return \array_key_exists( $packageType, $this->_supportedTypes );
	}

	/**
	 * @param PackageInterface $package
	 *
	 * @return string
	 */
	protected function getPackageBasePath( PackageInterface $package )
	{
		$this->_validatePackage( $package );

		return $this->_packageInstallPath . $this->_getPackageTypeSubPath( $package->getType() ) . '/' . $this->_packageName;
	}

	/**
	 * @param string $basePath
	 *
	 * @return bool|string
	 */
	protected function _checkDatabase( $basePath = null )
	{
		$_configFile = ( $basePath ? : static::$_platformBasePath ) . static::DEFAULT_DATABASE_CONFIG_FILE;

		if ( !file_exists( $_configFile ) )
		{
			$this->io->write( '  - No database configuration found. Registration not complete.</info>' );

			return false;
		}

		/** @noinspection PhpIncludeInspection */
		if ( false === ( $_dbConfig = @require( $_configFile ) ) )
		{
			$this->io->write( '  - <error>Unable to read database configuration file. Registration not complete.</error>' );

			return false;
		}

		Sql::setConnectionString(
			Option::get( $_dbConfig, 'connectionString' ),
			Option::get( $_dbConfig, 'username' ),
			Option::get( $_dbConfig, 'password' )
		);

		return true;
	}

	/**
	 * @param \Composer\Package\PackageInterface $package
	 *
	 * @return bool|mixed
	 */
	protected function _getRegistrationInfo( PackageInterface $package )
	{
		$_config = $this->_getPackageConfig( $package );

		if ( empty( $_config ) || null === ( $_app = Option::get( $_config, 'application' ) ) )
		{
			$this->io->write( '  - No registration requested' );

			return false;
		}

		if ( !$this->_checkDatabase() )
		{
			if ( $this->io->isDebug() )
			{
				$this->io->write( '  - Registration requested, but <warning>no database connection available</warning>.' );

				return false;
			}
		}

		return $_app;
	}

	/**
	 * @param \Composer\Package\PackageInterface $package
	 *
	 * @return bool
	 */
	protected function _addApplication( PackageInterface $package )
	{
		$this->io->write( '_addApplication' );

		if ( false === ( $_app = $this->_getRegistrationInfo( $package ) ) )
		{
			$this->io->write( '_addApplication: nope' );

			return false;
		}

		$this->io->write( '_addApplication: yep' );

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
ON DUPLICATE KEY UPDATE
  `description` = VALUES(`description`),
  `is_active` = VALUES(`is_active`),
  `url` = VALUES(`url`),
  `is_url_external` = VALUES(`is_url_external`),
  `import_url` = VALUES(`import_url`),
  `storage_service_id` = VALUES(`storage_service_id`),
  `storage_container` = VALUES(`storage_container`),
  `requires_fullscreen` = VALUES(`requires_fullscreen`),
  `allow_fullscreen_toggle` = VALUES(`allow_fullscreen_toggle`),
  `toggle_location` = VALUES(`toggle_location`),
  `requires_plugin` = VALUES(`require_plugin`)
SQL;

		$_data = array(
			':api_name'                => $_apiName = Option::get( $_app, 'api-name', $this->_packageSuffix ),
			':name'                    => Option::get( $_app, 'name', $this->_packageSuffix ),
			':description'             => Option::get( $_app, 'description' ),
			':is_active'               => Option::getBool( $_app, 'is-active', false ),
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
			if ( false === ( $_result = Sql::execute( $_sql, $_data ) ) )
			{
				$_message =
					( null === ( $_statement = Sql::getStatement() )
						? 'Unknown database error' : 'Database error: ' . print_r( $_statement->errorInfo(), true ) );

				throw new \Exception( $_message );
			}
		}
		catch ( \Exception $_ex )
		{
			$this->io->write( '  - Package registration error with payload: ' . $_ex->getMessage() );

			return false;
		}

		$this->io->write( '  - Package registered as "<info>' . $_apiName . '</info>" with DSP.' );

		return true;
	}

	/**
	 * Soft-deletes a registered package
	 *
	 * @param \Composer\Package\PackageInterface $package
	 *
	 * @return bool
	 */
	protected function _deleteApplication( PackageInterface $package )
	{
		if ( false === ( $_app = $this->_getRegistrationInfo( $package ) ) )
		{
			return false;
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
			if ( false === ( $_result = Sql::execute( $_sql, $_data ) ) )
			{
				$_message =
					( null === ( $_statement = Sql::getStatement() )
						? 'Unknown database error' : 'Database error: ' . print_r( $_statement->errorInfo(), true ) );

				throw new \Exception( $_message );
			}
		}
		catch ( \Exception $_ex )
		{
			$this->io->write( '  - Package registration error with payload: ' . $_ex->getMessage() );

			return false;
		}

		$this->io->write( '  - Package "<info>' . $_apiName . '</info>" unregistered from DSP.' );

		return true;
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _createLinks( PackageInterface $package )
	{
		if ( null === ( $_links = $this->_getPackageConfig( $package, 'links' ) ) )
		{
			if ( $this->io->isDebug() )
			{
				$this->io->write( '  - Package contains no links' );
			}

			return;
		}

		$this->io->write( '  - Creating package symlinks' );

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			//	Adjust relative directory to absolute
			list( $_target, $_linkName ) = $this->_normalizeLink( $package, $_link );

			if ( \is_link( $_linkName ) )
			{
				if ( $_target == ( $_priorTarget = readlink( $_linkName ) ) )
				{
					$this->io->write( '  - Package link exists: <info>' . $_linkName . '</info>' );
					continue;
				}

				$this->io->write( '  - Link exists but target "<error>' . $_priorTarget . '</error>" is incorrect. Package links not created.' );
				continue;
			}

			if ( false === @\symlink( $_target, $_linkName ) )
			{
				$this->io->write( '  - File system error creating symlink: <error>' . $_linkName . '</error>' );
				throw new FileSystemException( 'Unable to create symlink: ' . $_linkName );
			}

			$this->io->write( '  - Package linked to "<info>' . $_linkName . '</info>"' );
		}
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _deleteLinks( PackageInterface $package )
	{
		if ( null === ( $_links = $this->_getPackageConfig( $package, 'links' ) ) )
		{
			if ( $this->io->isDebug() )
			{
				$this->io->write( '  - Package contains no links' );
			}

			return;
		}

		$this->io->write( '  - Removing package symlinks' );

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			//	Adjust relative directory to absolute
			list( $_target, $_linkName ) = $this->_normalizeLink( $package, $_link );

			//	Already linked?
			if ( !\is_link( $_linkName ) )
			{
				$this->io->write(
					'  - Expected link "<warning>' . $_linkName . '</warning>" not found. Ignoring.Package link <warning>' . $_linkName . '</warning>'
				);
				continue;
			}

			if ( false === @\unlink( $_linkName ) )
			{
				$this->io->write( '  - File system error removing symlink: <error>' . $_linkName . '</error>' );
				throw new FileSystemException( 'Unable to remove symlink: ' . $_linkName );
			}

			$this->io->write( '  - Package links removed' );
		}
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
		$_config = $this->_parseConfiguration( $package );

		if ( $this->io->isDebug() )
		{
			$this->io->write( '  - Validating package payload' );
		}

		$this->io->write(
			'  - ' . ( static::$_devMode ? 'Development' : 'Production' ) . ' installation: ' . static::$_platformBasePath
		);

		//	Only install DreamFactory packages if not a plug-in
		if ( static::ALLOWED_PACKAGE_PREFIX != $this->_packagePrefix )
		{
			$this->io->write( '  - Package type "<error>' . $this->_packagePrefix . '</error>" invalid' );
			throw new \InvalidArgumentException( 'The package "' . $this->_packageName . '" cannot be installed by this installer.' );
		}

		//	Get supported types
		if ( null !== ( $_types = Option::get( $_config, 'supported-types' ) ) )
		{
			foreach ( $_types as $_type => $_path )
			{
				if ( !array_key_exists( $_type, $this->_supportedTypes ) )
				{
					$this->_supportedTypes[$_type] = $_path;
					$this->io->write( '  - <info>Added support for package type "' . $_type . '"</info>' );
				}
			}
		}
	}

	/**
	 * @param PackageInterface $package
	 * @param string           $key
	 * @param mixed            $defaultValue
	 *
	 * @throws \InvalidArgumentException
	 * @return array
	 */
	protected function _getPackageConfig( PackageInterface $package, $key = null, $defaultValue = null )
	{
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
					$this->io->write( '  - <error>File system error reading package configuration file: ' . $_configFile . '</error>' );
					$_config = array();
				}

				if ( !is_array( $_config ) )
				{
					$this->io->write( '  - The "config" file specified in this package is invalid: <error>' . $_configFile . '</error>' );
					throw new \InvalidArgumentException( 'The "config" file specified in this package is invalid.' );
				}
			}
		}

		$_config = array_merge( $_extra, $_config );

		//	Merge any config with the extra data
		return $key ? Option::get( $_config, $key, $defaultValue ) : $_config;
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

		$_config = $this->_getPackageConfig( $package );

		//	Pull out the links
		$_links = Option::get( $_config, 'links' );

		//	If no links found, create default for plugin
		if ( empty( $_links ) && PackageTypes::PLUGIN == $this->_packageType )
		{
			$_link = array(
				'target' => null,
				'link'   => Option::get( $_config, 'api_name', $this->_packageSuffix )
			);

			$_config['links'] = $_links = array( $this->_normalizeLink( $package, $_link ) );
		}

		return $_config;
	}

	/**
	 * @param \Composer\Package\PackageInterface $package
	 * @param array                              $link
	 *
	 * @return array
	 */
	protected function _normalizeLink( PackageInterface $package, $link )
	{
		//	Adjust relative directory to absolute
		$_target = $this->getInstallPath( $package ) . '/' . Option::get( $link, 'target' );
		$_linkName = static::$_platformBasePath . static::DEFAULT_PLUGIN_LINK_PATH . '/' . Option::get( $link, 'link', $this->_packageSuffix );

		return array( $_target, $_linkName );
	}

	/**
	 * @param string $type
	 *
	 * @return string
	 */
	protected function _getPackageTypeSubPath( $type = null )
	{
		return Option::get( $this->_supportedTypes, $type ? : $this->_packageType );
	}

	/**
	 * Locates the installed DSP's base directory
	 *
	 * @param \Composer\IO\IOInterface $io
	 * @param string                   $startPath
	 *
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 * @return string
	 */
	protected static function _findPlatformBasePath( IOInterface $io, $startPath = null )
	{
		$_path = $startPath ? : dirname( __DIR__ );

		while ( true )
		{
			if ( file_exists( $_path . '/config/schema/system_schema.json' ) && is_dir( $_path . '/storage/.private' ) )
			{
				break;
			}

			//	If we get to the root, ain't no DSP...
			if ( '/' == ( $_path = dirname( $_path ) ) )
			{
				$io->write( '  - <error>Unable to find the DSP installation directory.</error>' );

				if ( !static::$_devMode )
				{
					throw new FileSystemException( 'Unable to find the DSP installation directory.' );
				}

				break;
			}
		}

		return $_path;
	}

	/**
	 * @param IOInterface $io
	 * @param Composer    $composer
	 *
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _validateInstallationTree( IOInterface $io, Composer $composer )
	{
		if ( file_exists( static::FABRIC_MARKER ) )
		{
			$this->io->write( '  - <warning>This installer is not available for DreamFactory-hosted DSPs</warning>' );
			throw new FileSystemException( 'Installation not possible on hosted DSPs.' );
		}

		$_basePath = realpath( static::$_platformBasePath = static::_findPlatformBasePath( $io ) );
		$this->filesystem->ensureDirectoryExists( $_basePath . '/storage/plugins/.manifest' );
	}

	/**
	 * @param boolean $devMode
	 */
	public static function setDevMode( $devMode )
	{
		static::$_devMode = $devMode;
	}

}
