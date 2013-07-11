<?php
namespace DreamFactory\Platform\Utility;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

/**
 * Class/Plug-in/FuelCell installer
 *
 * @package DreamFactory\Platform\Utility
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
	const DEFAULT_INSTALL_NAMESPACE = 'app';
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
	 * @var array The types of packages I can install
	 */
	protected $_supportPackageTypes
		= array(
			'dreamfactory-platform',
			'dreamfactory-jetpack',
			'dreamfactory-fuelcell',
		);

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
		$_parts = explode( '/', $package->getPrettyName(), 2 );

		if ( static::PACKAGE_PREFIX != ( $_prefix = @current( $_parts ) ) )
		{
			throw new \InvalidArgumentException(
				'This package is not a DreamFactory package and cannot be installed by this installer.' . PHP_EOL .
				'  * Name: ' . $package->getPrettyName() . PHP_EOL .
				'  * Parts: ' . print_r( $_parts, true ) . PHP_EOL
			);
		}

		//	Effectively /docRoot/shared/[vendor]/[namespace]/[package]
		return $this->_buildInstallPath(
			dirname( $this->vendorDir ) . static::BASE_INSTALL_PATH,
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
	 * @return string
	 */
	protected function _buildInstallPath( $baseInstallPath, $prefix, $package, $createIfMissing = true )
	{
		/**
		 *    Package like dreamfactory/app-xyz or dreamfactory/lib-abc will
		 *    go into ./apps/app-xyz and ./lib/lib-abc respectively)
		 *
		 * baseInstall  : ./shared
		 * prefix       : dreamfactory
		 * parts        : abc-xyz
		 */
		if ( !is_dir( $baseInstallPath ) || false === realpath( $baseInstallPath ) )
		{
			@mkdir( $baseInstallPath, 0777, true );
		}

		//	i.e. /var/www/dsp-share/dreamfactory/
		$_fullPath = realpath( $baseInstallPath ) . '/' . $prefix . '/';

		//	Split package type off of front (app-*, lib-*, web-*, jetpack-*, fuelcell-*, etc.)
		$_subparts = explode( '-', $package, 2 );
		$_namespace = empty( $_subparts ) ? static::DEFAULT_INSTALL_NAMESPACE : current( $_subparts );

		//	/path/to/project/shared/[vendor]/[namespace]/[package]
		$_fullPath .= $_namespace . '/' . $package;

		if ( $createIfMissing && !is_dir( $_fullPath ) )
		{
			if ( false === @mkdir( $_fullPath, 0777, true ) )
			{
				throw new \InvalidArgumentException( 'Unable to create installation path "' . $_fullPath . '"' );
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