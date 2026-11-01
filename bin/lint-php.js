#!/usr/bin/env node

/**
 * Cross-platform PHP syntax linter for MigrateLift.
 * Discovers available PHP runtime (including Local WP on Windows) and executes php -l.
 */

const { execSync, spawnSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

function findPhpBinary() {
	if ( process.env.PHP_BIN && fs.existsSync( process.env.PHP_BIN ) ) {
		return process.env.PHP_BIN;
	}

	// Try standard system php
	try {
		const test = spawnSync( 'php', [ '-v' ], { stdio: 'pipe' } );
		if ( test.status === 0 ) {
			return 'php';
		}
	} catch ( _err ) {
		// Continue search
	}

	// On Windows, check Local by Flywheel lightning-services directory
	if ( process.platform === 'win32' && process.env.APPDATA ) {
		const localServicesDir = path.join( process.env.APPDATA, 'Local', 'lightning-services' );
		if ( fs.existsSync( localServicesDir ) ) {
			const entries = fs.readdirSync( localServicesDir );
			for ( const entry of entries ) {
				if ( entry.startsWith( 'php-' ) ) {
					const candidates = [
						path.join( localServicesDir, entry, 'bin', 'win64', 'php.exe' ),
						path.join( localServicesDir, entry, 'bin', 'win32', 'php.exe' ),
					];
					for ( const candidate of candidates ) {
						if ( fs.existsSync( candidate ) ) {
							return candidate;
						}
					}
				}
			}
		}
	}

	return null;
}

function collectPhpFiles( dir, fileList = [] ) {
	const entries = fs.readdirSync( dir, { withFileTypes: true } );
	for ( const entry of entries ) {
		const fullPath = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			if ( entry.name !== 'node_modules' && entry.name !== 'vendor' && entry.name !== '.git' ) {
				collectPhpFiles( fullPath, fileList );
			}
		} else if ( entry.isFile() && entry.name.endsWith( '.php' ) ) {
			fileList.push( fullPath );
		}
	}
	return fileList;
}

const phpBin = findPhpBinary();
if ( ! phpBin ) {
	console.error( 'Error: PHP executable not found. Please install PHP or set the PHP_BIN environment variable.' );
	process.exit( 1 );
}

const rootDir = path.resolve( __dirname, '..' );
const filesToLint = [
	path.join( rootDir, 'migrate-lift.php' ),
	path.join( rootDir, 'uninstall.php' ),
	...collectPhpFiles( path.join( rootDir, 'includes' ) ),
];

console.log( `Linting ${ filesToLint.length } PHP files with: ${ phpBin }` );

let hasError = false;
for ( const file of filesToLint ) {
	const relPath = path.relative( rootDir, file );
	const result = spawnSync( phpBin, [ '-l', file ], { encoding: 'utf-8' } );
	if ( result.status !== 0 ) {
		hasError = true;
		console.error( `[FAIL] ${ relPath }:\n${ result.stderr || result.stdout }` );
	} else {
		console.log( `[OK] ${ relPath }` );
	}
}

if ( hasError ) {
	console.error( '\nPHP lint failed with syntax errors.' );
	process.exit( 1 );
} else {
	console.log( '\nAll PHP files passed syntax check successfully.' );
	process.exit( 0 );
}

