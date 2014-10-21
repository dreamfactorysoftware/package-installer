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
use Composer\Util\Filesystem;
use DreamFactory\Library\Utility\IfSet;
use DreamFactory\Library\Utility\Includer;
use DreamFactory\Tools\Composer\Enums\PackageTypes;
use Kisma\Core\Enums\Verbosity;
use Kisma\Core\Exceptions\FileSystemException;
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
    const ENABLE_DATABASE_ACCESS = true;
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

    /** @inheritdoc */
    public function __construct( IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null )
    {
        if ( file_exists( static::FABRIC_MARKER ) )
        {
            throw new \Exception( 'This installer cannot be used on a hosted DSP system.', 500 );
        }

        parent::__construct( $io, $composer, $type, $filesystem );

        //	Set from IOInterface
        static::$_verbosity = $io->isVerbose()
            ? Verbosity::VERBOSE
            : $io->isVeryVerbose()
                ? Verbosity::VERY_VERBOSE
                : $io->isDebug()
                    ? Verbosity::DEBUG : Verbosity::NORMAL;
    }

    /**
     * {@InheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::PRE_PACKAGE_INSTALL   => array(array('onOperation', 0)),
            ScriptEvents::PRE_PACKAGE_UPDATE    => array(array('onOperation', 0)),
            ScriptEvents::PRE_PACKAGE_UNINSTALL => array(array('onOperation', 0)),
        );
    }

    /**
     * @param Event $event
     * @param bool  $devMode
     */
    public static function onOperation( Event $event, $devMode )
    {
        static::$_requireDev = $devMode;

        if ( false !== ( $_path = static::_findPlatformBasePath( $event->getIO(), __DIR__, false ) ) )
        {
            static::$_platformBasePath = $_path;
        }
    }

    /**
     * @param InstalledRepositoryInterface $repo
     * @param PackageInterface             $package
     */
    public function install( InstalledRepositoryInterface $repo, PackageInterface $package )
    {
        $this->_log( 'Installing package <info>' . $package->getPrettyName() . '</info>', Verbosity::DEBUG );

        //	Make sure proper storage paths are available
        $this->_validateInstallationTree();

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

        //	Make sure proper storage paths are available
        $this->_validateInstallationTree();

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
        //	Make sure proper storage paths are available
        $this->_validateInstallationTree();

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
            'We ' . ( $_does ? 'loveses' : 'hateses' ) . ' packageses of type <info>' . $packageType . '</info>!',
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

        $this->_log( 'Package base path is <comment>' . $_path . '</comment>', Verbosity::DEBUG );

        return $_path;
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

        if ( empty( $_packageData ) || null === ( $_records = IfSet::get( $_packageData, $_supportedData ) ) )
        {
            $this->_log( 'Package does not require database registration.', Verbosity::VERY_VERBOSE );

            return false;
        }

        if ( static::ENABLE_DATABASE_ACCESS && !$this->_checkDatabase() )
        {
            $this->_log( 'Package registration not enabled or connection not available.', Verbosity::VERBOSE );

            return false;
        }

        return $_records;
    }

    /**
     * @param string $basePath
     *
     * @return bool|string
     */
    protected function _checkDatabase( $basePath = null )
    {
        $_configFile = ( $basePath ?: static::$_platformBasePath ) . static::DEFAULT_DATABASE_CONFIG_FILE;

        if ( false === ( $_dbConfig = Includer::includeIfExists( $_configFile ) ) || !is_array( $_dbConfig ) )
        {
            $this->_log(
                'No database specified and config file found. Using default "localhost" config.',
                Verbosity::VERBOSE
            );

            $_dbConfig = array(
                'connectionString' => 'mysql:host=localhost;port=3306;dbname=dreamfactory',
                'username'         => 'dsp_user',
                'password'         => 'dsp_user',
            );
        }

        Sql::setConnectionString(
            IfSet::get( $_dbConfig, 'connectionString' ),
            IfSet::get( $_dbConfig, 'username' ),
            IfSet::get( $_dbConfig, 'password' )
        );

        return true;
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
            'api_name'                => $_apiName = IfSet::get( $_app, 'api-name', $_defaultApiName ),
            'name'                    => IfSet::get( $_app, 'name', $_defaultApiName ),
            'description'             => IfSet::get( $_app, 'description' ),
            'is_active'               => IfSet::getBool( $_app, 'is-active', false ),
            'url'                     => IfSet::get( $_app, 'url' ),
            'is_url_external'         => IfSet::getBool( $_app, 'is-url-external' ),
            'import_url'              => IfSet::get( $_app, 'import-url' ),
            'requires_fullscreen'     => IfSet::getBool( $_app, 'requires-fullscreen' ),
            'allow_fullscreen_toggle' => IfSet::getBool( $_app, 'allow-fullscreen-toggle' ),
            'toggle_location'         => IfSet::get( $_app, 'toggle-location' ),
            'requires_plugin'         => 1,
        );

        $this->_writePackageData( $package, $_payload );

        if ( !static::ENABLE_DATABASE_ACCESS )
        {
            return true;
        }

        $this->_log( 'Looking for existing row in database', Verbosity::DEBUG );

        try
        {
            if ( false === ( $_row = $this->_findApp( $_apiName ) ) )
            {
                $this->_log( '  - Not found, inserting.', Verbosity::DEBUG );
                $_row = $_payload;
            }
            else
            {
                $this->_log( '  - Found, updating.', Verbosity::DEBUG );
                $_row = array_merge( $_row, $_payload );
            }

            if ( false !== ( $_result = $this->_upsertApp( $_row ) ) )
            {
                $this->_log( 'Package <info>' . $_apiName . '</info> registered.', Verbosity::DEBUG );
            }

            return $_result;
        }
        catch ( \Exception $_ex )
        {
            $this->_log( 'Package <info>' . $_apiName . '</info> may or may not be registered: ' . $_ex->getMessage() );

            return false;
        }
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
        $this->_log( 'deleting application', Verbosity::DEBUG );

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
                IfSet::get( $_app, 'api-name', $this->_getPackageConfig( $package, '_suffix' ) ),
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

        $this->_log( 'Package data written to <info>' . $_fileName . '</info>', Verbosity::VERBOSE );

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
        if ( is_bool( $_links = $this->_checkPackageLinks( $package ) ) )
        {
            return $_links;
        }

        $this->_log( 'Adding package links', Verbosity::VERBOSE );

        //	Make the links
        foreach ( Option::clean( $_links ) as $_link )
        {
            //	Adjust relative directory to absolute
            list( $_target, $_linkName ) = $this->_normalizeLink( $package, $_link );

            if ( \is_link( $_linkName ) )
            {
                if ( $_target != ( $_priorTarget = readlink( $_linkName ) ) )
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

            $this->_log( '  - link <info>' . $_linkName . '</info> created', Verbosity::VERBOSE );
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
        if ( is_bool( $_links = $this->_checkPackageLinks( $package ) ) )
        {
            return $_links;
        }

        $this->_log( 'removing package links.' );

        //	Make the links
        foreach ( Option::clean( $_links ) as $_link )
        {
            //	Adjust relative directory to absolute
            list( $_target, $_linkName ) = $this->_normalizeLink( $package, $_link );

            //	Already linked?
            if ( !\is_link( $_linkName ) )
            {
                $this->_log( 'expected link "<warning>' . $_linkName . '</warning>" not found. Ignoring.' );
                continue;
            }

            if ( false === @\unlink( $_linkName ) )
            {
                $this->_log( 'cannot remove symlink <error>' . $_linkName . '</error>' );

                throw new FileSystemException( 'Unable to remove symlink: ' . $_linkName );
            }

            $this->_log( '  - link <info>' . $_linkName . '</info> removed', Verbosity::VERBOSE );
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
            $this->_log( 'checking package validity', Verbosity::DEBUG );

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
            if ( null !== ( $_types = IfSet::get( $_config, 'supported-types' ) ) )
            {
                $this->_log( 'I can install the following types of packages:', Verbosity::VERBOSE );

                foreach ( $_types as $_type => $_path )
                {
                    if ( !array_key_exists( $_type, static::$_supportedTypes ) )
                    {
                        static::$_supportedTypes[$_type] = $_path;
                        $this->_log( '  <info>' . $_type . '</info>', Verbosity::VERBOSE );
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

        if ( null === ( $_packageConfig = IfSet::get( $_cache, $_packageName = $package->getPrettyName() ) ) )
        {
            //	Get the extra stuff
            $_extra = Option::clean( $package->getExtra() );
            $_extraConfig = array();

            //	Read configuration section. Can be an array or name of file to include
            if ( null !== ( $_configFile = IfSet::get( $_extra, 'config' ) ) )
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
        return $key ? IfSet::get( $_packageConfig, $key, $defaultValue ) : $_packageConfig;
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
        $_links = IfSet::get( $_config, 'links', array() );

        //	If no links found, create default for plugin
        if ( empty( $_links ) && PackageTypes::PLUGIN == $package->getType() )
        {
            $_link = array(
                'target' => null,
                'link'   => IfSet::get( $_config, 'api_name', $_parts[1] )
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
            trim( IfSet::get( $link, 'target' ), '/' );

        //	And the link
        $_linkName =
            rtrim( static::$_platformBasePath, '/' ) .
            '/' .
            trim( static::DEFAULT_PLUGIN_LINK_PATH, '/' ) .
            '/' .
            trim( IfSet::get( $link, 'link', $this->_getPackageConfig( $package, '_suffix' ) ), '/' );

        return array($_target, $_linkName);
    }

    /**
     * @param string $type
     *
     * @return string
     */
    protected function _getPackageTypeSubPath( $type )
    {
        return IfSet::get( static::$_supportedTypes, $type );
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
     * @param string $apiName
     * @param string $select Comma-separated list of columns to retrieve. Defaults to "*"
     *
     * @return bool|array
     */
    protected function _findApp( $apiName, $select = '*' )
    {
        try
        {
            $_sql = 'SELECT ' . $select . ' FROM df_sys_app WHERE api_name = :api_name';

            if ( false === ( $_app = Sql::find( $_sql, array(':api_name' => $apiName) ) ) || empty( $_app ) )
            {
                $this->_log( 'No app found with api_name <comment>' . $apiName . '</comment>.', Verbosity::VERBOSE );

                return false;
            }

            $this->_log( 'App registered: ' . print_r( $_app, true ), Verbosity::DEBUG );

            return $_app;
        }
        catch ( \Exception $_ex )
        {
            $this->_log( 'App lookup error: ' . $_ex->getMessage() );

            return false;
        }
    }

    /**
     * @param array $values Array of values to upsert
     *
     * @return bool|int
     */
    protected function _upsertApp( $values )
    {
        $_params = $values;
        Option::prefixKeys( ':', $_params );

        $_id = isset( $values['id'] ) ? $values['id'] : null;

        $_pairs = array();

        foreach ( $values as $_key => $_value )
        {
            $_pairs[] = $_key . ' = :' . $_key;
        }

        if ( $_id )
        {
            $_pairs = implode( ', ', $_pairs );

            $_sql = <<<MYSQL
UPDATE df_sys_app SET
    {$_pairs}
WHERE
    id = :id
MYSQL;
        }
        else
        {
            $_columns = array_keys( $values );
            $_binds = array_keys( $_params );

            $_sql = <<<MYSQL
INSERT INTO df_sys_app
(
    {$_columns}
)
VALUES
(
    {$_binds}
)
MYSQL;

        }

        try
        {
            if ( false === ( $_app = Sql::execute( $_sql, $_params ) ) || empty( $_app ) )
            {
                $_message =
                    null === ( $_statement = Sql::getStatement() )
                        ? 'Unknown database error'
                        : print_r( $_statement->errorInfo(), Verbosity::DEBUG );

                throw new \Exception( $_message );
            }

            return $_app;
        }
        catch ( \Exception $_ex )
        {
            $this->_log( 'Exception during registration: ' . $_ex->getMessage() );

            return false;
        }
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
     * @param bool                     $required If false, method returns false if no
     *                                           {
     *                                           directory} found
     *
     * @throws FileSystemException
     * @return string
     */
    protected static function _findPlatformBasePath( IOInterface $io, $startPath = null, $required = true )
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
                if ( !$required )
                {
                    return false;
                }

                throw new FileSystemException( 'Unable to find a DSP installation directory.' );
            }
        }

        if ( $io->isVerbose() )
        {
            $io->write( '    DSP base found: <comment>' . $_path . '</comment>' );
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
