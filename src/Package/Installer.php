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
use Composer\Json\JsonFile;
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
 * Under each DSP   lies a /storage directory. This plug-in installs DreamFactory DSP packages into this space
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
	 * @type bool If true, installer will attempt to update the local DSP's database directly.
	 */
	const ENABLE_DATABASE_ACCESS = false;
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
	 * @var array The types of packages I can install. Can be changed via composer.json:extra.supported-types[]
	 */
	protected $_supportedTypes = array(
		PackageTypes::APPLICATION => '/applications',
		PackageTypes::JETPACK     => '/plugins',
		PackageTypes::LIBRARY     => '/plugins',
		PackageTypes::PLUGIN      => '/plugins',
	);
	/**
	 * @var string The base directory of the DSP installation relative to manifest directory
	 */
	protected static $_platformBasePath = '../../../../';
	/**
	 * @var string The base installation path, where composer.json lives: ./
	 */
	protected $_baseInstallPath = './';
	/**
	 * @var string The path of the package installation relative to manifest directory: ../../[applications|plugins]/vendor/package-name
	 */
	protected $_packageInstallPath = '../../';
	/**
	 * @var string The target of the package link relative to /web
	 */
	protected $_packageLinkBasePath = '../storage/';
	/**
	 * @var bool True if this install was started with "require-dev", false if "no-dev"
	 */
	protected static $_devMode = true;

	//*************************************************************************
	//* Methods
	//*************************************************************************

	/**
	 * @param IOInterface $io
	 * @param Composer    $composer
	 * @param string      $type
	 *
	 * @throws \Exception
	 */
	public function __construct( IOInterface $io, Composer $composer, $type = 'library' )
	{
		parent::__construct( $io, $composer, $type );

		if ( file_exists( static::FABRIC_MARKER ) )
		{
			throw new \Exception( 'This installer cannot be used on a hosted DSP system.', 500 );
		}

		$this->_baseInstallPath = \getcwd();

		//	Make sure proper storage paths are available
		$this->_validateInstallationTree();
	}

	/**
	 * @param boolean $devMode
	 */
	public static function setDevMode( $devMode )
	{
		static::$_devMode = $devMode;
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
		if ( $event->getIO()->isDebug() )
		{
			$event->getIO()->write( '  - <info>' . $event->getName() . '</info> event fired' );
		}

		static::$_devMode = $devMode;
		static::$_platformBasePath = static::_findPlatformBasePath( $event->getIO(), \getcwd() );

		$event->getIO()->write(
			'  - <info>' . ( static::$_devMode ? 'require-dev' : 'no-dev' ) . '</info>" mode: ' . static::$_platformBasePath
		);
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 */
	public function install( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_log( 'Installing package <info>' . $package->getPrettyName() . '</info>', true );

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
		$this->_log( 'Updating package <info>' . $initial->getPrettyName() . '</info>', true );

		parent::update( $repo, $initial, $target );

		//	Out with the old...
		$this->_deleteLinks( $initial );
		$this->_deleteApplication( $initial );

		//	In with the new...
		$this->_createLinks( $target );
		$this->_addApplication( $target );
	}

	/**
	 * @param InstalledRepositoryInterface $repo
	 * @param PackageInterface             $package
	 */
	public function uninstall( InstalledRepositoryInterface $repo, PackageInterface $package )
	{
		$this->_log( 'Removing package <info>' . $package->getPrettyName() . '</info>', true );

		parent::uninstall( $repo, $package );

		$this->_deleteLinks( $package );
		$this->_deleteApplication( $package );
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( $packageType )
	{
		$this->_log( 'Installer::supports called.', true );

		return \array_key_exists( $packageType, $this->_supportedTypes );
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
		$_path = $startPath ? : __DIR__;

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
	 * @param PackageInterface $package
	 *
	 * @return string
	 */
	protected function getPackageBasePath( PackageInterface $package )
	{
		$this->_log( 'Installer::getPackageBasePath called.', true );
		$this->_validatePackage( $package );

		return
			rtrim( $this->_packageInstallPath, '/' ) .
			'/' .
			trim( $this->_getPackageTypeSubPath( $package->getType() ), '/' ) .
			'/' .
			$package->getPrettyName();
	}

	/**
	 * @param string $basePath
	 *
	 * @return bool|string
	 */
	protected function _checkDatabase( $basePath = null )
	{
		$this->_log( 'Installer::_checkDatabase called.', true );

		$_configFile = ( $basePath ? : static::$_platformBasePath ) . static::DEFAULT_DATABASE_CONFIG_FILE;

		if ( !file_exists( $_configFile ) )
		{
			$this->_log( 'No database configuration found. <info>Registration not complete</info>.' );

			return false;
		}

		/** @noinspection PhpIncludeInspection */
		if ( false === ( $_dbConfig = @include( $_configFile ) ) )
		{
			$this->_log( 'Not registered. Unable to read database configuration file: <error>' . $_configFile . '</error></error>' );

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
		static $_supportedData = 'application';

		$_packageData = $this->_getPackageConfig( $package, 'data' );

		if ( empty( $_packageData ) || null === ( $_records = Option::get( $_packageData, $_supportedData ) ) )
		{
			$this->_log( 'No registration requested', true );

			return false;
		}

		if ( static::ENABLE_DATABASE_ACCESS && !$this->_checkDatabase() )
		{
			if ( $this->io->isDebug() )
			{
				$this->_log( 'Registration requested, but <warning>no database connection available</warning>.', true );

				return false;
			}
		}

		return $_records;
	}

	/**
	 * @param \Composer\Package\PackageInterface $package
	 *
	 * @return bool
	 */
	protected function _addApplication( PackageInterface $package )
	{
		$this->_log( 'Installer::_addApplication called.', true );

		if ( false === ( $_app = $this->_getRegistrationInfo( $package ) ) )
		{
			return false;
		}

		$_defaultApiName = $this->_getPackageConfig( $package, '_suffix' );

		$_payload = array(
			'api_name'                => $_apiName = Option::get( $_app, 'api-name', $_defaultApiName ),
			'name'                    => Option::get( $_app, 'name', $_defaultApiName ),
			'description'             => Option::get( $_app, 'description' ),
			'is_active'               => Option::getBool( $_app, 'is-active', false ),
			'url'                     => Option::get( $_app, 'url' ),
			'is_url_external'         => Option::getBool( $_app, 'is-url-external' ),
			'import_url'              => Option::get( $_app, 'import-url' ),
			'requires_fullscreen'     => Option::getBool( $_app, 'requires-fullscreen' ),
			'allow_fullscreen_toggle' => Option::getBool( $_app, 'allow-fullscreen-toggle' ),
			'toggle_location'         => Option::get( $_app, 'toggle-location' ),
			'requires_plugin'         => 1,
		);

		$this->_writePackageData( $package, $_payload );

		if ( !static::ENABLE_DATABASE_ACCESS )
		{
			return true;
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
			':api_name'                => $_apiName = Option::get( $_app, 'api-name', $_defaultApiName ),
			':name'                    => Option::get( $_app, 'name', $_defaultApiName ),
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

		$this->_log( 'Inserting row into database', true );

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
			$this->_log( 'Package registration error with payload: ' . $_ex->getMessage() );

			return false;
		}

		$this->_log( 'Package registered as "<info>' . $_apiName . '</info>" with DSP.' );

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
		$this->_log( 'Installer::_deleteApplication called.', true );

		if ( false === ( $_app = $this->_getRegistrationInfo( $package ) ) )
		{
			return false;
		}

		$this->_writePackageData( $package, false );

		if ( static::ENABLE_DATABASE_ACCESS )
		{
			return true;
		}

		$_sql = <<<SQL
UPDATE df_sys_app SET
	`is_active` = :is_active
WHERE
	`api_name` = :api_name
SQL;

		$_data = array(
			':api_name'  => $_apiName = Option::get( $_app, 'api-name', $this->_getPackageConfig( $package, '_suffix' ) ),
			':is_active' => 0
		);

		$this->_log( 'Soft-deleting row in database', true );

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
			$this->_log( 'Package registration error with payload: ' . $_ex->getMessage() );

			return false;
		}

		$this->_log( 'Package "<info>' . $_apiName . '</info>" unregistered from DSP.' );

		return true;
	}

	/**
	 * @param PackageInterface $package
	 * @param array            $data The package data. Set $data = false to remove the package data file
	 *
	 * @return string The name of the file data to which data was written
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _writePackageData( PackageInterface $package, $data = array() )
	{
		$_fileName = './package.data/' . $package->getUniqueName() . '.manifest.json';

		//	Remove package data...
		if ( false === $data )
		{
			if ( file_exists( $_fileName ) && false === @unlink( $_fileName ) )
			{
				$this->_log( 'Error removing package data file <error>' . $_fileName . '</error>' );
			}

			return null;
		}

		$_file = new JsonFile( $_fileName );
		$_file->write( (array)$data );

		$this->_log( 'Package data written to "<info>' . $_fileName . '</info>"' );

		return $_fileName;
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _createLinks( PackageInterface $package )
	{
		$this->_log( 'Installer::_createLinks called.', true );

		if ( null === ( $_links = $this->_getPackageConfig( $package, 'links' ) ) )
		{
			$this->_log( 'Package contains no links', true );

			return;
		}

		$this->_log( 'Creating package symlinks' );

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			//	Adjust relative directory to absolute
			list( $_target, $_linkName ) = $this->_normalizeLink( $package, $_link );

			if ( \is_link( $_linkName ) )
			{
				if ( $_target == ( $_priorTarget = readlink( $_linkName ) ) )
				{
					$this->_log( 'Package link exists: <info>' . $_linkName . '</info>' );
				}
				else
				{
					$this->_log( 'Link exists but target "<error>' . $_priorTarget . '</error>" is incorrect. Package links not created.' );
				}

				continue;
			}

			if ( false === @\symlink( $_target, $_linkName ) )
			{
				$this->_log( 'File system error creating symlink: <error>' . $_linkName . '</error>' );
				throw new FileSystemException( 'Unable to create symlink: ' . $_linkName );
			}

			$this->_log( 'Package linked to "<info>' . $_linkName . '</info>"' );
		}
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _deleteLinks( PackageInterface $package )
	{
		$this->_log( 'Installer::_deleteLinks called.', true );

		if ( null === ( $_links = $this->_getPackageConfig( $package, 'links' ) ) )
		{
			$this->_log( 'Package contains no links', true );

			return;
		}

		$this->_log( 'Removing package symlinks' );

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			//	Adjust relative directory to absolute
			list( $_target, $_linkName ) = $this->_normalizeLink( $package, $_link );

			//	Already linked?
			if ( !\is_link( $_linkName ) )
			{
				$this->_log(
					'Expected link "<warning>' . $_linkName . '</warning>" not found. Ignoring.Package link <warning>' . $_linkName . '</warning>'
				);
				continue;
			}

			if ( false === @\unlink( $_linkName ) )
			{
				$this->_log( 'File system error removing symlink: <error>' . $_linkName . '</error>' );
				throw new FileSystemException( 'Unable to remove symlink: ' . $_linkName );
			}

			$this->_log( 'Package links removed' );
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
		static $_validated;

		if ( $_validated !== ( $_packageName = $package->getPrettyName() ) )
		{
			$this->_log( 'Installer::_validatePackage called.', true );

			//	Link path for plug-ins
			$_config = $this->_parseConfiguration( $package );

			$this->_log( '<info>' . ( static::$_devMode ? 'require-dev' : 'no-dev' ) . '</info> installation: ' . static::$_platformBasePath, true );

			//	Get supported types
			if ( null !== ( $_types = Option::get( $_config, 'supported-types' ) ) )
			{
				foreach ( $_types as $_type => $_path )
				{
					if ( !array_key_exists( $_type, $this->_supportedTypes ) )
					{
						$this->_supportedTypes[$_type] = $_path;
						$this->_log( 'Added support for package type "<info>' . $_type . '</info>"' );
					}
				}
			}

			$_validated = $_packageName;
		}
	}

	/**
	 * @param PackageInterface $package
	 * @param string           $key
	 * @param mixed            $defaultValue
	 *
	 * @throws \InvalidArgumentException
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 * @return array
	 */
	protected function _getPackageConfig( PackageInterface $package, $key = null, $defaultValue = null )
	{
		static $_cache = array();

		if ( null === ( $_packageConfig = Option::get( $_cache, $_packageName = $package->getPrettyName() ) ) )
		{
			//	Get the extra stuff
			$_extra = Option::clean( $package->getExtra() );
			$_extraConfig = array();

			//	Read configuration section. Can be an array or name of file to include
			if ( null !== ( $_configFile = Option::get( $_extra, 'config' ) ) )
			{
				if ( is_string( $_configFile ) && is_file( $_configFile ) && is_readable( $_configFile ) )
				{
					/** @noinspection PhpIncludeInspection */
					if ( false === ( $_extraConfig = @include( $_configFile ) ) )
					{
						$this->_log( '<error>File system error reading package configuration file: ' . $_configFile . '</error>' );
						throw new FileSystemException( 'File system error reading package configuration file' );
					}

					if ( !is_array( $_extraConfig ) )
					{
						$this->_log( 'The "config" file specified in this package is invalid: <error>' . $_configFile . '</error>' );
						throw new \InvalidArgumentException( 'The "config" file specified in this package is invalid.' );
					}
				}
			}

			$_cache[$_packageName] = $_packageConfig = array_merge( $_extra, $_extraConfig );
		}

		//	Merge any config with the extra data
		return $key ? Option::get( $_packageConfig, $key, $defaultValue ) : $_packageConfig;
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
		$_parts = explode( '/', $_packageName = $package->getPrettyName(), 2 );

		if ( 2 != count( $_parts ) )
		{
			throw new \InvalidArgumentException( 'The package "' . $_packageName . '" package name is malformed or cannot be parsed.' );
		}

		//	Only install DreamFactory packages if not a plug-in
		if ( static::ALLOWED_PACKAGE_PREFIX != $_parts[0] )
		{
			$this->_log( 'Package is not supported by this installer' );
			throw new \InvalidArgumentException( 'The package "' . $_packageName . '" is not supported by this installer.' );
		}

		$_config = $this->_getPackageConfig( $package );
		$_links = Option::get( $_config, 'links', array() );

		//	If no links found, create default for plugin
		if ( empty( $_links ) && PackageTypes::PLUGIN == $package->getType() )
		{
			$_link = array(
				'target' => null,
				'link'   => Option::get( $_config, 'api_name', $_parts[1] )
			);

			$_config['links'] = array( $this->_normalizeLink( $package, $_link ) );
		}

		$_config['_prefix'] = $_parts[0];
		$_config['_suffix'] = $_parts[1];

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
		//	Build path the link target
		$_target =
			rtrim( $this->_packageLinkBasePath, '/' ) .
			'/' .
			trim( $this->_getPackageTypeSubPath( $package->getType() ), '/' ) .
			'/' .
			trim( $package->getPrettyName(), '/' ) .
			'/' .
			trim( Option::get( $link, 'target' ), '/' );

		//	And the link
		$_linkName =
			rtrim( static::$_platformBasePath, '/' ) .
			'/' .
			trim( static::DEFAULT_PLUGIN_LINK_PATH, '/' ) .
			'/' .
			trim( Option::get( $link, 'link', $this->_getPackageConfig( $package, '_suffix' ) ), '/' );

		return array( $_target, $_linkName );
	}

	/**
	 * @param string $type
	 *
	 * @return string
	 */
	protected function _getPackageTypeSubPath( $type )
	{
		return Option::get( $this->_supportedTypes, $type );
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _validateInstallationTree()
	{
		$_basePath = realpath( static::$_platformBasePath = static::_findPlatformBasePath( $this->io ) );

		foreach ( $this->_supportedTypes as $_type => $_path )
		{
			$this->filesystem->ensureDirectoryExists( $_basePath . '/storage/' . $_path . '/.manifest' );
			$this->_log( '* Type "<info>' . $_type . '</info>" installation tree validated.', true );
		}

		$this->_log( 'Installation tree validated.', true );
	}

	/**
	 * @param string $message
	 * @param bool   $debug
	 */
	protected function _log( $message, $debug = false )
	{
		if ( false !== $debug && !$this->io->isDebug() )
		{
			return;
		}

		$this->io->write( '  - ' . ( $debug ? ' <info>**</info> ' : null ) . $message );
	}

}
