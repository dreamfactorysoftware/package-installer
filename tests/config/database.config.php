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
 * database.config.php
 * The database configuration file for the DSP
 */
if ( Fabric::fabricHosted() )
{
	return Fabric::initialize();
}

/**
 * Database names vary by type of DSP:
 *
 *        1. Free Edition/Hosted:   DSP name
 *        2. Hosted Private:        hpp_<DSP Name>
 *        3. All others:            dreamfactory
 *
 */

if ( false !== ( $_host = Fabric::hostedPrivatePlatform( true ) ) )
{
	$_dbName = 'hpp_' . str_ireplace( array( '.dreamfactory.com', '-', '.cloud', '.' ), array( null, '_', null, '_' ), $_host );
}
else
{
	$_dbName = 'dreamfactory';
}

$_dbUser = 'dsp_user';
$_dbPassword = 'dsp_user';

return array(
	'connectionString'      => 'mysql:host=localhost;port=3306;dbname=' . $_dbName,
	'username'              => $_dbUser,
	'password'              => $_dbPassword,
	'emulatePrepare'        => true,
	'charset'               => 'utf8',
	'enableProfiling'       => defined( YII_DEBUG ),
	'enableParamLogging'    => defined( YII_DEBUG ),
	'schemaCachingDuration' => 3600,
);
