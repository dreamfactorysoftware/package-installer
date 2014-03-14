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
use DreamFactory\Platform\Utility\Fabric;

/**
 * web.php
 * This is the main configuration file for the DreamFactory Services Platform server application.
 */

/**
 * Load up the database and common configurations between the web and background apps,
 * setting globals whilst at it.
 */
$_dbConfig = require( __DIR__ . '/database.config.php' );
$_commonConfig = require( __DIR__ . '/common.config.php' );

//.........................................................................
//. The configuration himself (like Raab)
//.........................................................................

return array(
	/**
	 * Basics
	 */
	'basePath'           => $_docRoot,
	'name'               => $_appName,
	'runtimePath'        => $_logFilePath,
	/**
	 * Service Handling: The default system resource namespaces
	 *
	 * @todo have ResourceStore::resource() scan sub-directories based on $_REQUEST['path']  -- GHA
	 */
	'resourceNamespaces' => array(
		'DreamFactory\\Platform\\Resources',
		'DreamFactory\\Platform\\Resources\\System',
		'DreamFactory\\Platform\\Resources\\Portal',
		'DreamFactory\\Platform\\Resources\\User',
	),
	/**
	 * Service Handling: The default system model namespaces
	 *
	 * @todo have ResourceStore::model() scan sub-directories based on $_REQUEST['path'] -- GHA
	 */
	'modelNamespaces'    => array(
		'DreamFactory\\Platform\\Yii\\Models',
	),
	/**
	 * CORS Configuration
	 */
	'corsWhitelist'      => array( '*' ),
	'autoAddHeaders'     => true,
	'extendedHeaders'    => true,
	/**
	 * Preloads
	 */
	'preload'            => array( 'log' ),
	/**
	 * Imports
	 */
	'import'             => array(
		'system.utils.*',
		'application.models.*',
		'application.models.forms.*',
		'application.components.*',
	),
	/**
	 * Modules
	 */
	'modules'            => array(),
	/**
	 * Components
	 */
	'components'         => array(
		//	Asset management
		'assetManager' => array(
			'class'      => 'CAssetManager',
			'basePath'   => 'assets',
			'baseUrl'    => '/assets',
			'linkAssets' => true,
		),
		//	Database configuration
		'db'           => $_dbConfig,
		//	Error management
		'errorHandler' => array(
			'errorAction' => $_defaultController . '/error',
		),
		//	Route configuration
		'urlManager'   => array(
			'caseSensitive'  => false,
			'urlFormat'      => 'path',
			'showScriptName' => false,
			'rules'          => array(
				// REST patterns
				array( 'rest/get', 'pattern' => 'rest/<path:[_0-9a-zA-Z-\/. ]+>', 'verb' => 'GET' ),
				array( 'rest/post', 'pattern' => 'rest/<path:[_0-9a-zA-Z-\/. ]+>', 'verb' => 'POST' ),
				array( 'rest/put', 'pattern' => 'rest/<path:[_0-9a-zA-Z-\/. ]+>', 'verb' => 'PUT' ),
				array( 'rest/merge', 'pattern' => 'rest/<path:[_0-9a-zA-Z-\/. ]+>', 'verb' => 'PATCH,MERGE' ),
				array( 'rest/delete', 'pattern' => 'rest/<path:[_0-9a-zA-Z-\/. ]+>', 'verb' => 'DELETE' ),
				// Other controllers
				'<controller:\w+>/<id:\d+>'              => '<controller>/view',
				'<controller:\w+>/<action:\w+>/<id:\d+>' => '<controller>/<action>',
				'<controller:\w+>/<action:\w+>'          => '<controller>/<action>',
				// fall through to storage services for direct access
				array( 'admin/<action>', 'pattern' => 'admin/<resource:[_0-9a-zA-Z-]+>/<action>/<id:[_0-9a-zA-Z-\/. ]+>' ),
				array( 'storage/get', 'pattern' => '<service:[_0-9a-zA-Z-]+>/<path:[_0-9a-zA-Z-\/. ]+>', 'verb' => 'GET' ),
			),
		),
		//	User configuration
		'user'         => array(
			'allowAutoLogin' => true,
			'loginUrl'       => array( $_defaultController . '/activate' ),
		),
		'clientScript' => array(
			'scriptMap' => array(
				'jquery.js'     => false,
				'jquery.min.js' => false,
			),
		),
		//	Logging configuration
		'log'          => array(
			'class'  => 'CLogRouter',
			'routes' => array(
				array(
					'class'       => 'DreamFactory\\Yii\\Logging\\LiveLogRoute',
					'maxFileSize' => '102400',
					'logFile'     => $_logFileName,
					'logPath'     => $_logFilePath,
					'levels'      => 'error, warning, trace, info, debug, notice',
				),
				array(
					'class'         => 'CWebLogRoute',
					'categories'    => 'system.db.CDbCommand',
					'showInFireBug' => true,
				),
			),
		),
		//	Database Cache
		'cache'        => $_dbCache,
	),
	//.........................................................................
	//. Global application parameters
	//.........................................................................

	'params'             => $_commonConfig,
);
