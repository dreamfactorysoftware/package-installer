<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) Package Installer
 * Copyright 2012-201r DreamFactory Software, Inc. {@email support@dreamfactory.com}
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
use DreamFactory\Platform\Utility\ResourceStore;
use DreamFactory\Tools\Composer\Enums\PackageTypes;
use Kisma\Core\Enums\Verbosity;
use Kisma\Core\Exceptions\FileSystemException;
use Kisma\Core\Exceptions\StorageException;
use Kisma\Core\Utility\Option;
use Kisma\Core\Utility\Sql;

/**
 * Installer
 * DreamFactory Package Installer
 *
 * Under each DSP lies a /storage directory. This plug-in installs DreamFactory DSP packages into this space
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
     * @type string
     */
    const ALLOWED_PACKAGE_PREFIX = 'dreamfactory';
    /**
     * @type bool If true, installer will attempt to update the local DSP's database directly.
     */
    const ENABLE_DATABASE_ACCESS = false;
    /**
     * @type string
     */
    const DEFAULT_DATABASE_CONFIG_FILE = '/config/database.config.php';
    /**
     * @type string The manifest file name
     */
    const MANIFEST_PATH_NAME = '/.manifest';
    /**
     * @type string
     */
    const DEFAULT_PLUGIN_LINK_PATH = '/web';
    /**
     * @type string
     */
    const DEFAULT_STORAGE_BASE_PATH = '/storage';
    /**
     * @type string
     */
    const DEFAULT_PRIVATE_PATH = '/storage/.private';
    /**
     * @type int The default package type
     */
    const DEFAULT_PACKAGE_TYPE = PackageTypes::APPLICATION;
    /**
     * @type string
     */
    const FABRIC_MARKER = '/var/www/.fabric_hosted';
    /**
     * @type string
     */
    const ROOT_MARKER = '/.dreamfactory.php';
    /**
     * @type string
     */
    const REQUIRE_DEV_BASE_PATH = '/dev';
    /**
     * @type bool
     */
    const ENABLE_LOCAL_DEV_STORAGE = true;

    //*************************************************************************
    //* Members
    //*************************************************************************

    /**
     * @var int Reflects the command's verbosity
     */
    protected static $_verbosity = Verbosity::NORMAL;
    /**
     * @var bool True if this install was started with "require-dev", false if "no-dev"
     */
    protected static $_requireDev = true;
    /**
     * @var array The types of packages I can install. Can be changed via composer.json:extra.supported-types[]
     */
    protected static $_supportedTypes = array(
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
     * @var string The target of the package link relative to /web
     */
    protected static $_packageLinkBasePath = '../storage/';

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
        if ( file_exists( static::FABRIC_MARKER ) )
        {
            throw new \Exception( 'This installer cannot be used on a hosted DSP system.', 500 );
        }

        parent::__construct( $io, $composer, $type );

        //	Set from IOInterface
        static::$_verbosity = $io->isVerbose()
            ? Verbosity::VERBOSE
            : $io->isVeryVerbose()
                ? Verbosity::VERY_VERBOSE
                : $io->isDebug()
                    ? Verbosity::DEBUG : Verbosity::NORMAL;

        //	Make sure proper storage paths are available
        $this->_validateInstallationTree();
    }

    /**
     * {@InheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::PRE_INSTALL_CMD => array(array('onOperation', 0)),
            ScriptEvents::PRE_UPDATE_CMD  => array(array('onOperation', 0)),
        );
    }

    /**
     * @param Event $event
     * @param bool  $devMode
     */
    public static function onOperation( Event $event, $devMode )
    {
        if ( static::$_verbosity >= Verbosity::DEBUG )
        {
            $event->getIO()->write(
                'DreamFactory Package Installer: <info>' . $event->getName() . '</info> event received'
            );
        }

        static::$_requireDev = $devMode;
        static::$_platformBasePath = static::_findPlatformBasePath( $event->getIO(), __DIR__ );
    }

    /**
     * @param InstalledRepositoryInterface $repo
     * @param PackageInterface             $package
     */
    public function install( InstalledRepositoryInterface $repo, PackageInterface $package )
    {
        $this->_log( 'Installing package <info>' . $package->getPrettyName() . '</info>', Verbosity::DEBUG );

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
        $this->_log( 'Updating package <info>' . $initial->getPrettyName() . '</info>', Verbosity::DEBUG );

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
        $this->_log( 'Removing package <info>' . $package->getPrettyName() . '</info>', Verbosity::DEBUG );

        parent::uninstall( $repo, $package );

        $this->_deleteLinks( $package );
        $this->_deleteApplication( $package );
    }

    /**
     * {@inheritDoc}
     */
    public function supports( $packageType )
    {
        $_does = \array_key_exists( $packageType, static::$_supportedTypes );

        $this->_log(
            'We ' . ( $_does ? 'loves' : 'hates' ) . ' packages\'s of type "<info>' . $packageType . '</info>"',
            Verbosity::VERY_VERBOSE
        );

        return $_does;
    }

    /**
     * @param PackageInterface $package
     *
     * @return string
     */
    protected function getPackageBasePath( PackageInterface $package )
    {
        $this->_validatePackage( $package );

        $_path = static::$_platformBasePath . static::DEFAULT_STORAGE_BASE_PATH .
            $this->_getPackageTypeSubPath( $package->getType() ) . '/' .
            $package->getPrettyName();

        $this->io->write( 'Package base path will be: ' . $_path );

        return $_path;
    }

    /**
     * @param string $basePath
     *
     * @return bool|string
     */
    protected function _checkDatabase( $basePath = null )
    {
        $_configFile = ( $basePath ?: static::$_platformBasePath ) . static::DEFAULT_DATABASE_CONFIG_FILE;

        if ( !file_exists( $_configFile ) )
        {
            $this->_log( 'No database configuration found. <info>Registration not complete</info>.' );

            return false;
        }

        /** @noinspection PhpIncludeInspection */
        if ( false === ( $_dbConfig = @include( $_configFile ) ) )
        {
            $this->_log(
                'Not registered. Unable to read database configuration file: <error>' . $_configFile . '</error>'
            );

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
            $this->_log( 'No registration requested', Verbosity::VERY_VERBOSE );

            return false;
        }

        if ( static::ENABLE_DATABASE_ACCESS && !$this->_checkDatabase() )
        {
            if ( static::$_requireDev )
            {
                $this->_log( 'Registration requested, but <warning>no database connection available</warning>.' );

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
        //  Add to composer file
        $this->_addToComposer( $package );

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

        $this->_log( 'Inserting row into database', Verbosity::DEBUG );

        try
        {
            //  Make this a parameter array
            Option::prefixKeys( ':', $_payload );

            //  Write with the store
            if ( !ResourceStore::model( 'service' )->upsert( array('api_name' => $_apiName), $_payload ) )
            {
                throw new StorageException( 'Error saving application to database.' );
            }
        }
        catch ( \Exception $_ex )
        {
            $this->_log( 'Package registration error with payload: ' . $_ex->getMessage() );

            return false;
        }

        $this->_log( '<info>Package "' . $_apiName . '" installed on DSP</info>' );

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
        $this->_log( 'Installer::_deleteApplication called.', Verbosity::DEBUG );

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
            ':api_name'  => $_apiName =
                Option::get( $_app, 'api-name', $this->_getPackageConfig( $package, '_suffix' ) ),
            ':is_active' => 0
        );

        $this->_log( 'Soft-deleting row in database', Verbosity::DEBUG );

        try
        {
            if ( false === ( $_result = Sql::execute( $_sql, $_data ) ) )
            {
                $_message =
                    ( null === ( $_statement = Sql::getStatement() )
                        ? 'Unknown database error'
                        : 'Database error: ' . print_r( $_statement->errorInfo(), Verbosity::DEBUG ) );

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
     *
     * @return bool
     */
    protected function _addToComposer( PackageInterface $package )
    {
        $_manifestPath = $this->_getManifestPath( $package->getType() );

        $_command =
            'composer require --quiet --no-ansi --working-dir ' .
            $_manifestPath .
            ' --no-update ' .
            escapeshellarg( $package->getPrettyName() . ':' . $package->getPrettyVersion() );

        exec( $_command, $_output, $_returnVar );

        return ( 0 == $_returnVar );
    }

    /**
     * Return the manifest path for a package type
     *
     * @param string $type
     * @param bool   $createIfMissing
     *
     * @throws \Kisma\Core\Exceptions\FileSystemException
     * @return string
     */
    protected function _getManifestPath( $type, $createIfMissing = true )
    {
        $_manifestPath =
            static::$_platformBasePath .
            static::DEFAULT_STORAGE_BASE_PATH .
            $this->_getPackageTypeSubPath( $type ) .
            static::MANIFEST_PATH_NAME;

        if ( $createIfMissing )
        {
            $this->filesystem->ensureDirectoryExists( $_manifestPath );
        }

        return $_manifestPath;
    }

    /**
     * @param PackageInterface $package
     * @param array|bool       $data The package data. Set $data = false to remove the package data file
     *
     * @return string The name of the file data to which data was written
     * @throws \Kisma\Core\Exceptions\FileSystemException
     */
    protected function _writePackageData( PackageInterface $package, $data = array() )
    {
        $_packageDataPath = $this->_getManifestPath( $package->getType(), false ) . '/packages';

        $this->filesystem->ensureDirectoryExists( $_packageDataPath );

        $this->_log( 'Package manifest directory is ' . $_packageDataPath, Verbosity::VERBOSE );

        $_fileName = $_packageDataPath . '/' . $package->getUniqueName() . '.json';

        $this->_log( 'Package manifest file is ' . $_fileName, Verbosity::VERBOSE );

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

        $this->_log( 'Package data written to <info>' . $_fileName . '</info>' );

        return $_fileName;
    }

    /**
     * @param PackageInterface $package
     *
     * @return array|bool
     * @throws FileSystemException
     */
    protected function _checkPackageLinks( $package )
    {
        if ( null === ( $_links = $this->_getPackageConfig( $package, 'links' ) ) )
        {
            $this->_log( 'Package contains no links', Verbosity::VERBOSE );

            return false;
        }

        if ( static::$_requireDev )
        {
//            $this->_log( 'Linking skipped because of "<info>require-dev</info>"' );
//
//            return true;
        }

        return $_links;
    }

    /**
     * @param PackageInterface $package
     *
     * @return bool|void
     * @throws FileSystemException
     */
    protected function _createLinks( PackageInterface $package )
    {
        $this->_log( '-- Create Links', Verbosity::DEBUG );
        $this->_log( '---------------', Verbosity::DEBUG );

        if ( is_bool( $_links = $this->_checkPackageLinks( $package ) ) )
        {
            return $_links;
        }

        $this->_log( 'Examining package links', Verbosity::VERBOSE );

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
                    $this->_log(
                        'Link exists but target "<error>' .
                        $_priorTarget .
                        '</error>" is incorrect. Link "' . $_linkName . '" not created.'
                    );
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
     * @param PackageInterface $package
     *
     * @return array|bool
     * @throws FileSystemException
     */
    protected function _deleteLinks( PackageInterface $package )
    {
        $this->_log( '-- Delete Links', Verbosity::DEBUG );
        $this->_log( '---------------', Verbosity::DEBUG );

        if ( is_bool( $_links = $this->_checkPackageLinks( $package ) ) )
        {
            return $_links;
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
                $this->_log( 'Expected link "<warning>' . $_linkName . '</warning>" not found. Ignoring.' );
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
            $this->_log( 'Installer::_validatePackage called.', Verbosity::DEBUG );

            //	Link path for plug-ins
            $_config = $this->_parseConfiguration( $package );

            $this->_log(
                '<info>' .
                ( static::$_requireDev ? 'require-dev' : 'no-dev' ) .
                '</info> installation: ' .
                static::$_platformBasePath,
                Verbosity::VERBOSE
            );

            //	Get supported types
            if ( null !== ( $_types = Option::get( $_config, 'supported-types' ) ) )
            {
                foreach ( $_types as $_type => $_path )
                {
                    if ( !array_key_exists( $_type, static::$_supportedTypes ) )
                    {
                        static::$_supportedTypes[$_type] = $_path;
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
                        $this->_log(
                            '<error>File system error reading package configuration file: ' . $_configFile . '</error>'
                        );
                        throw new FileSystemException( 'File system error reading package configuration file' );
                    }

                    if ( !is_array( $_extraConfig ) )
                    {
                        $this->_log(
                            'The "config" file specified in this package is invalid: <error>' .
                            $_configFile .
                            '</error>'
                        );
                        throw new \InvalidArgumentException(
                            'The "config" file specified in this package is invalid.'
                        );
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
            throw new \InvalidArgumentException(
                'The package "' . $_packageName . '" package name is malformed or cannot be parsed.'
            );
        }

        //	Only install DreamFactory packages if not a plug-in
        if ( static::ALLOWED_PACKAGE_PREFIX != $_parts[0] )
        {
            $this->_log( 'Package is not supported by this installer' );
            throw new \InvalidArgumentException(
                'The package "' . $_packageName . '" is not supported by this installer.'
            );
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

            $_config['links'] = array($this->_normalizeLink( $package, $_link ));
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
            rtrim( static::$_packageLinkBasePath, '/' ) .
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

        return array($_target, $_linkName);
    }

    /**
     * @param string $type
     *
     * @return string
     */
    protected function _getPackageTypeSubPath( $type )
    {
        return Option::get( static::$_supportedTypes, $type );
    }

    /**
     * @throws \Kisma\Core\Exceptions\FileSystemException
     */
    protected function _validateInstallationTree()
    {
        $_basePath = static::_findPlatformBasePath( $this->io );

        $this->_log(
            'Platform base path is "<info>' . $_basePath . '</info>"',
            Verbosity::VERBOSE
        );

        //	Make sure the private storage base is there...
        $this->filesystem->ensureDirectoryExists( $_basePath . static::DEFAULT_PRIVATE_PATH );

        foreach ( static::$_supportedTypes as $_type => $_path )
        {
            //  Construct and validate each type's manifest path
            $this->_getManifestPath( $_type );
            $this->_log( 'Type "<info>' . $_type . '</info>" installation tree validated.', Verbosity::DEBUG );
        }

        $this->_log( 'Platform installation directory structure validated', Verbosity::VERBOSE );
    }

    /**
     * @param string $message
     * @param int    $verbosity
     */
    protected function _log( $message, $verbosity = Verbosity::NORMAL )
    {
        if ( $verbosity <= static::$_verbosity )
        {
            $this->io->write( '    ' . $message );
        }
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
        //  Start path given or this file's directory
        $_path = $startPath ?: __DIR__;

        while ( true )
        {
            $_path = rtrim( $_path, ' /' );

            if ( file_exists( $_path . static::ROOT_MARKER ) )
            {
                if ( is_dir( $_path . static::DEFAULT_PRIVATE_PATH ) )
                {
                    //  This is our platform root
                    break;
                }
            }

            //  Too low, go up a level
            $_path = dirname( $_path );

            //	If we get to the root, ain't no DSP...
            if ( '/' == $_path || empty( $_path ) )
            {
                throw new FileSystemException( 'Unable to find a DSP installation directory.' );
            }
        }

        if ( $io->isVerbose() )
        {
            $io->write( 'Installation path found at <comment>' . $_path . '</comment>' );
        }

        return static::$_platformBasePath = realpath( $_path );
    }

    /**
     * @param int|\Composer\IO\IOInterface $verbosity
     */
    public static function setVerbosity( $verbosity )
    {
        static::$_verbosity = $verbosity;
    }

    /**
     * @return int
     */
    public static function getVerbosity()
    {
        return static::$_verbosity;
    }

    /**
     * @param boolean $requireDev
     */
    public static function setRequireDev( $requireDev )
    {
        static::$_requireDev = $requireDev;
    }

    /**
     * @return boolean
     */
    public static function getRequireDev()
    {
        return static::$_requireDev;
    }

}
