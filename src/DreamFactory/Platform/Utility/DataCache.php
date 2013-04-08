<?php
namespace DreamFactory\Platform\Utility;

use Kisma\Core\Utility\Hasher;
use Kisma\Core\Utility\Log;

/**
 * DataCache
 *
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright (c) 2012-2013 DreamFactory Software, Inc. <developer-support@dreamfactory.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
class DataCache
{
	//*************************************************************************
	//	Constants
	//*************************************************************************

	/**
	 * @var string
	 */
	const CACHE_PATH = '/tmp';
	/**
	 * @var string
	 */
	const SALTY_GOODNESS = '/%S9DE,h4|e0O70v)K-[;,_bA4sC<shV4wd3qX!T-bW~WasVRjCLt(chb9mVp$7f';
	/**
	 * @var int
	 */
	const CACHE_TTL = 30;

	//*************************************************************************
	//* Methods
	//*************************************************************************

	/**
	 * @param string $key
	 * @param mixed  $data
	 *
	 * @return bool|string
	 */
	public static function load( $key, $data = null )
	{
		if ( file_exists( $_fileName = static::_getCacheFileName( $key ) ) )
		{
			if ( ( time() - fileatime( $_fileName ) ) > static::CACHE_TTL )
			{
				unlink( $_fileName );
				//Log::debug( 'Cache file expired: ' . $_fileName );
			}
			else
			{
				$_data = json_decode( Hasher::decryptString( file_get_contents( $_fileName ), static::SALTY_GOODNESS ), true );
				//Log::debug( 'Cache data found.' );
				touch( $_fileName );

				return $_data;
			}
		}

		if ( !empty( $data ) )
		{
			return static::store( $key, $data );
		}

		return false;
	}

	/**
	 * @param string $key
	 * @param mixed  $data
	 *
	 * @return bool
	 */
	public static function store( $key, $data )
	{
		if ( file_exists( $_fileName = static::_getCacheFileName( $key ) ) )
		{
			unlink( $_fileName );
			//Log::debug( 'Removing old cache file: ' . $_fileName );
		}

		if ( !is_string( $data ) )
		{
			$data = json_encode( $data );
		}

		//Log::debug( 'Cached data: ' . $_fileName );

		return file_put_contents( $_fileName, Hasher::encryptString( $data, static::SALTY_GOODNESS ) );
	}

	/**
	 * @param string $key
	 *
	 * @return string
	 */
	protected static function _getCacheFileName( $key )
	{
		return static::CACHE_PATH . '/' . sha1( $key ) . '.dsp.cache';
	}
}