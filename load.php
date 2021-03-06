<?php
/**
 * This file is the entry point for ResourceLoader.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Roan Kattouw
 * @author Trevor Parscal
 */

use MediaWiki\Logger\LoggerFactory;

require __DIR__ . '/includes/WebStart.php';


// URL safety checks
if ( !$wgRequest->checkUrlExtension() ) {
	return;
}

// Respond to resource loading request.
// foo()->bar() syntax is not supported in PHP4, and this file needs to *parse* in PHP4.
$configFactory = ConfigFactory::getDefaultInstance();
$resourceLoader = new ResourceLoader(
	$configFactory->makeConfig( 'main' ),
	LoggerFactory::getInstance( 'resourceloader' )
);
$resourceLoader->respond( new ResourceLoaderContext( $resourceLoader, $wgRequest ) );

Profiler::instance()->setTemplated( true );

$mediawiki = new MediaWiki();
$mediawiki->doPostOutputShutdown( 'fast' );
