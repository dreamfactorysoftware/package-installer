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
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;

/**
 * Plugin
 * DreamFactory Services Platform installer injection plug-in
 */
class Plugin implements EventSubscriberInterface, PluginInterface
{
	//*************************************************************************
	//	Members
	//*************************************************************************

	/**
	 * @var Installer
	 */
	protected static $_installer;
	/**
	 * @var bool require-dev or no-dev
	 */
	protected static $_devMode = true;

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * @param Composer    $composer
	 * @param IOInterface $io
	 */
	public function activate( Composer $composer, IOInterface $io )
	{
		static::$_installer = new Installer( $io, $composer );
		static::$_installer->setDevMode( static::$_devMode );

		$composer->getInstallationManager()->addInstaller( static::$_installer );
	}

	/**
	 * {@InheritDoc}
	 */
	public static function getSubscribedEvents()
	{
		return array(
			PluginEvents::COMMAND => array(
				array( 'onCommand', 0 )
			),
		);
	}

	/**
	 * @param CommandEvent $event
	 * @param bool         $devMode
	 */
	public static function onCommand( CommandEvent $event, $devMode = true )
	{
		static::$_devMode = $devMode;
	}
}