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
use Kisma\Core\Utility\Log;

/**
 * bootstrap.php
 * Bootstrap script for PHPUnit tests
 */
$_basePath = dirname( __DIR__ );

//	Composer
$_autoloader = require( $_basePath . '/vendor/autoload.php' );

//	Load up Yii
require_once $_basePath . '/vendor/dreamfactory/yii/framework/yii.php';

//	Yii debug settings
defined( 'YII_DEBUG' ) or define( 'YII_DEBUG', true );
defined( 'YII_TRACE_LEVEL' ) or define( 'YII_TRACE_LEVEL', 3 );

$_config = require( __DIR__ . '/config/package-installer.config.php' );

//	Testing keys
if ( file_exists( __DIR__ . '/config/keys.php' ) )
{
	/** @noinspection PhpIncludeInspection */
	require_once __DIR__ . '/config/keys.php';
}

Log::setDefaultLog( __DIR__ . '/log/package-installer.tests.log' );

//	Create the application but don't run (false at the end)
$_app = DreamFactory\Yii\Utility\Pii::run(
	__DIR__,
	$_autoloader,
	'DreamFactory\\Platform\\Yii\\Components\\PlatformConsoleApplication',
	$_config,
	false
);