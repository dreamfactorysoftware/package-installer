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
use DreamFactory\Yii\Utility\Pii;

/**
 * aliases.config.php
 * A single location for all your aliasing needs!
 */

$_libPath = dirname( dirname( __DIR__ ) );
$_vendorPath = $_libPath . '/vendor';

Pii::setPathOfAlias( 'vendor', $_vendorPath );

//	lib-php-common-yii
Pii::alias( 'DreamFactory.Yii.*', $_vendorPath . '/dreamfactory/lib-php-common-yii/DreamFactory/Yii' );
Pii::alias( 'DreamFactory.Yii.Components', $_vendorPath . '/dreamfactory/lib-php-common-yii/DreamFactory/Yii/Components' );
Pii::alias( 'DreamFactory.Yii.Behaviors', $_vendorPath . '/dreamfactory/lib-php-common-yii/DreamFactory/Yii/Behaviors' );
Pii::alias( 'DreamFactory.Yii.Utility', $_vendorPath . '/dreamfactory/lib-php-common-yii/DreamFactory/Yii/Utility' );
Pii::alias( 'DreamFactory.Yii.Logging', $_vendorPath . '/dreamfactory/lib-php-common-yii/DreamFactory/Yii/Logging' );

//	lib-php-common-platform
Pii::alias( 'DreamFactory.Platform.Services', $_libPath . '/Services' );
Pii::alias( 'DreamFactory.Platform.Services.Portal', $_libPath . '/Services/Portal' );
Pii::alias( 'DreamFactory.Platform.Yii.Behaviors', $_libPath . '/Yii/Behaviors' );
Pii::alias( 'DreamFactory.Platform.Yii.Models', $_libPath . '/Yii/Models' );

//	Vendors
Pii::alias( 'Swift', $_vendorPath . '/swiftmailer/swiftmailer/lib/classes' );
