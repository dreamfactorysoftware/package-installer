<?php
namespace DreamFactory\Platform\Utility;

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
	const BASE_INSTALL_PATH = '../../dsp-share';
	/**
	 * @var string
	 */
	const DEFAULT_INSTALL_NAMESPACE = 'app';

	//*************************************************************************
	//* Methods
	//*************************************************************************

	/**
	 * {@inheritDoc}
	 */
	public function getInstallPath( PackageInterface $package )
	{
		$_parts = explode( '/', $package->getPrettyName(), 2 );

		if ( static::PACKAGE_PREFIX != ( $_prefix = @current( $_parts ) ) )
		{
			throw new \InvalidArgumentException(
				'This package is not a DreamFactory package and cannot be installed.' . PHP_EOL .
					'  * Name: ' . $package->getPrettyName() . PHP_EOL .
					'  * Parts: ' . print_r( $_parts, true ) . PHP_EOL
			);
		}

		/**
		 *    Package like dreamfactory/app-xyz or dreamfactory/lib-abc will
		 *    go into ./apps/app-xyz and ./lib/lib-abc respectively)
		 */

		return static::BASE_INSTALL_PATH . '/' . $_prefix . '/' .
			( @current( @explode( '-', end( $_parts ), 2 ) ) ? :
				static::DEFAULT_INSTALL_NAMESPACE ) . '/' . $_parts[1];
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( $packageType )
	{
		return 'dreamfactory-platform' === $packageType;
	}
}