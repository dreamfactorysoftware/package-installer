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
namespace DreamFactory\Tools\Composer\Components;

use Kisma\Core\Seed;
use Kisma\Core\Utility\Inflector;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;

/**
 * Package
 * Represents a single package
 */
class Package extends Seed
{
	//*************************************************************************
	//* Members
	//*************************************************************************

	/**
	 * @var string
	 */
	protected $_versionDate;
	/**
	 * @var string
	 */
	protected $_installDate;
	/**
	 * @var string
	 */
	protected $_installPath;
	/**
	 * @var array|\stdClass
	 */
	protected $_links;
	/**
	 * @var array|\stdClass
	 */
	protected $_routes;
	/**
	 * @var array|\stdClass
	 */
	protected $_composerInfo;
	/**
	 * @var \Composer\Package\Package
	 */
	protected $_package;

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * Constructor
	 */
	public function __construct( $settings = array() )
	{
		if ( null !== ( $_package = Option::get( $settings, 'package' ) ) )
		{
		}

		parent::__construct( $settings );
	}

	/**
	 * @param PackageInterface $package
	 * @param PackageInterface $initial Initial package if operation was an update
	 */
	public function addPackage( PackageInterface $package, PackageInterface $initial = null )
	{
	}

	/**
	 * @param PackageInterface $package
	 */
	public function removeManifest( PackageInterface $package )
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
		static $_validated = false;

		//	Don't do more than once...
		if ( $_validated )
		{
			return true;
		}

		//	Link path for plug-ins
		$this->_parseConfiguration( $package );

		Log::info(
			'DreamFactory Package Installer > ' . $this->_packageName .
			' > Version ' . $package->getVersion()
		);

		//	Only install DreamFactory packages if not a plug-in
		if ( static::ALLOWED_PACKAGE_PREFIX != $this->_packagePrefix )
		{
			Log::error( '  * Invalid package type' );
			throw new \InvalidArgumentException( 'This package is not one that can be installed by this installer.' .
												 PHP_EOL . '  * Name: ' .
												 $this->_packageName );
		}

		//	Get supported types
		if ( null !==
			 ( $_types = Option::get( $this->_config, 'supported-types' ) )
		)
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
		$this->_buildInstallPath(
			$this->_packagePrefix,
			$this->_packageSuffix
		);

		Log::debug(
			'  * Install type: ' .
			Inflector::display( PlatformTypes::nameOf( $this->_packageType ) )
		);
		Log::debug( '  * Install path: ' . $this->_packageInstallPath );

		if ( null !== ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			foreach ( $_links as $_link )
			{
				Log::debug(
					'  *   Link found: ' . Option::get(
						$_link,
						'target',
						$this->_packageInstallPath
					) . ' -> ' . Option::get(
						$_link,
						'link',
						static::DEFAULT_PLUGIN_LINK_PATH
					)
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
		$_parts =
			explode( '/', $this->_packageName = $package->getPrettyName(), 2 );

		if ( 2 != count( $_parts ) )
		{
			throw new \InvalidArgumentException( 'The package "' .
												 $this->_packageName .
												 '" package name is malformed or cannot be parsed.' );
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
				if ( !is_array( $_config ) && is_file( $_config ) &&
					 is_readable( $_config )
				)
				{
					/** @noinspection PhpIncludeInspection */
					if ( false === ( $_config = @include( $_config ) ) )
					{
						Log::error(
							'File system error reading package configuration file: ' .
							$_config
						);
						$_config = array();
					}
				}
			}
		}

		if ( empty( $_config ) )
		{
			$_config = array(
				'name'   => $this->_packageSuffix,
				'links'  => array(),
				'routes' => array(),
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
		$this->_packageType =
		$_config['type'] =
			Option::get( $_config, 'type', static::DEFAULT_PACKAGE_TYPE );

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
			Option::get(
				$this->_config,
				'base-install-path',
				static::DEFAULT_INSTALL_PATH
			) . $_subPath . '/' . $vendor . '/' . $package,
			' /'
		/** intentional space */
		);

		if ( $createIfMissing && !is_dir( $_basePath . '/' . $_installPath ) )
		{
			if ( false ===
				 @mkdir( $_basePath . '/' . $_installPath, 0775, true )
			)
			{
				throw new FileSystemException( 'Unable to create installation path "' .
											   $_basePath . '/' .
											   $_installPath . '"' );
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
			return;
		}

		Log::info(
			'  * Creating links for package "' . $package->getPrettyName() .
			' ' . $package->getVersion()
		);

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			$_target =
				Option::get( $_link, 'target', $this->_packageInstallPath );
			$_linkName = Option::get(
				$_link,
				'link',
				static::DEFAULT_PLUGIN_LINK_PATH . '/' . $this->_packageSuffix
			);

			//	Already linked?
			if ( \is_link( $_linkName ) )
			{
				Log::debug(
					'  * Package "' . $this->_packageName . '" already linked.'
				);
				continue;
			}
			else if ( false === @\symlink( $_target, $_linkName ) )
			{
				Log::error(
					'  * File system error creating symlink "' . $_linkName .
					'".'
				);
				throw new FileSystemException( 'Unable to create symlink: ' .
											   $_linkName );
			}

			Log::debug(
				'  * Package "' . $this->_packageName . '" linked.',
				array( 'target' => $_target, 'link' => $_linkName )
			);
		}
	}

	/**
	 * @throws \Kisma\Core\Exceptions\FileSystemException
	 */
	protected function _deleteLinks( PackageInterface $package )
	{
		if ( null === ( $_links = Option::get( $this->_config, 'links' ) ) )
		{
			return;
		}

		Log::info(
			'  * Removing links for package "' . $package->getPrettyName() .
			' ' . $package->getVersion()
		);

		//	Make the links
		foreach ( Option::clean( $_links ) as $_link )
		{
			$_target =
				Option::get( $_link, 'target', $this->_packageInstallPath );
			$_linkName = Option::get(
				$_link,
				'link',
				static::DEFAULT_PLUGIN_LINK_PATH . '/' . $this->_packageSuffix
			);

			//	Already linked?
			if ( !\is_link( $_linkName ) )
			{
				Log::warning(
					'  * Package "' . $this->_packageName . '" link not found.'
				);
				continue;
			}
			else if ( false === @\unlink( $_linkName ) )
			{
				Log::error(
					'  * File system error removing symlink "' . $_linkName .
					'".'
				);
				throw new FileSystemException( 'Unable to remove symlink: ' .
											   $_linkName );
			}

			Log::debug(
				'  * Package "' . $this->_packageName . '" link removed.',
				array( 'target' => $_target, 'link' => $_linkName )
			);
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
