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
namespace DreamFactory\Tools\Composer\Enums;

use Kisma\Core\Enums\SeedEnum;

/**
 * PackageTypes
 * Class/plug-in/library/jetpack installer
 */
class PackageTypes extends SeedEnum
{
	//*************************************************************************
	//* Constants
	//*************************************************************************

	/**
	 * @type int Indicates the package is an application (HTML5/javascript)
	 */
	const APPLICATION = 'dreamfactory-application';
	/**
	 * @type int Indicates the package is code/app hybrid
	 */
	const PLUGIN = 'dreamfactory-plugin';
	/**
	 * @type int Indicates the package is a code library
	 */
	const LIBRARY = 'dreamfactory-library';
	/**
	 * @type int Indicates the package is a JetPack
	 */
	const JETPACK = 'dreamfactory-jetpack';
}
