<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) Library Installer
 * Copyright 2012-2013 DreamFactory Software, Inc. {@email support@dreamfactory.com}
 *
 * DreamFactory Services Platform(tm) Library Installer {@link http://github.com/dreamfactorysoftware/package-installer}
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
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\CommandEvent;

/**
 * Plugin
 * DreamFactory Services Platform installer injection plug-in
 */
class Plugin implements PluginInterface, EventSubscriberInterface
{
	/**
	 * @var bool False if "--no-dev" is specified
	 */
	protected static $_devMode = true;
	/**
	 * @var string The base directory of the DSP installation
	 */
	protected static $_platformBasePath = '../../../';

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * @param Composer    $composer
	 * @param IOInterface $io
	 */
	public function activate( Composer $composer, IOInterface $io )
	{
		$_installer = new Installer( $io, $composer );
		$composer->getInstallationManager()->addInstaller( $_installer );
	}

	/**
	 * {@InheritDoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			PluginEvents::COMMAND => array( array( 'onCommand', 0 ) ),
		);
	}

	/**
	 * @param CommandEvent $event
	 */
	public static function onCommand( CommandEvent $event )
	{
		static $_commands = array( 'update', 'install' );

		if ( !in_array( $event->getCommandName(), $_commands ) )
		{
			return;
		}

		if ( true !== ( static::$_devMode = !$event->isDevMode() ) )
		{
			static::$_platformBasePath = static::_findPlatformBasePath( $event->getIO(), \getcwd() );
			$event->getIO()->write( '  - <info>Production installation: ' . static::$_platformBasePath . '</info>' );
		}
	}

	/**
	 * Locates the installed DSP's base directory
	 *
	 * @param \Composer\IO\IOInterface $io
	 * @param string                   $startPath
	 *
	 * @throws \ErrorException
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
				throw new \ErrorException( 'Unable to find the DSP installation directory.' );

				break;
			}
		}

		return $_path;
	}

}