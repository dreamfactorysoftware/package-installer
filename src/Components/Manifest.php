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

use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use Composer\Util\RemoteFilesystem;
use Kisma\Core\Seed;
use Kisma\Core\SeedBag;
use Kisma\Core\Utility\Log;
use Kisma\Core\Utility\Option;

/**
 * Manifest
 * Maintain the installation manifest and autoload.php file for a package
 */
class Manifest extends SeedBag
{
	//*************************************************************************
	//* Members
	//*************************************************************************

	/**
	 * @var string
	 */
	protected $_filePath;
	/**
	 * @var string
	 */
	protected $_vendorPath;
	/**
	 * @var string
	 */
	protected $_fileName;

	//*************************************************************************
	//	Methods
	//*************************************************************************

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
	public function removePackage( PackageInterface $package )
	{
	}

	/**
	 * Saves off manifest file
	 */
	public function save( InstalledRepositoryInterface $localRepo, PackageInterface $mainPackage, InstallationManager $installationManager, $targetDir )
	{
		$_manifest = array();
		$_fs = new Filesystem();

		$this->_filePath = $_fs->normalizePath( $this->_filePath );
		$_fs->ensureDirectoryExists( $this->_filePath );
		$_basePath = $_fs->normalizePath( realpath( getcwd() ) );
		$_vendorPath = $_fs->normalizePath( realpath( $this->_vendorPath ) );
		$_targetDir = $_vendorPath . '/' . $targetDir;
		$_fs->ensureDirectoryExists( $_targetDir );

		$_map = $this->_buildMap( $mainPackage, $localRepo, $installationManager );

		$_file = new JsonFile( $this->_filePath . '/' . $this->_fileName );

		if ( $_file->exists() )
		{
			$_manifest = $_file->read();
		}

		$_file->write( $_map );
	}

	public function load()
	{
		$_file = new JsonFile( $this->_filePath . '/' . $this->_fileName );
	}

	/**
	 * Builds a package map
	 *
	 * @param PackageInterface             $main
	 * @param InstalledRepositoryInterface $repo
	 * @param InstallationManager          $installer
	 *
	 * @return array
	 */
	protected function _buildMap( PackageInterface $main, InstalledRepositoryInterface $repo, InstallationManager $installer )
	{
		$_map = array( array( $main, '' ) );

		foreach ( $repo->getCanonicalPackages() as $_package )
		{
			if ( $_package instanceof AliasPackage )
			{
				continue;
			}

			$_map[] = array(
				$_package,
				$installer->getInstallPath( $_package )
			);
		}

		return $_map;

	}

}
