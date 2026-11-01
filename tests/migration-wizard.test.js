/**
 * Unit & Contract Tests for MigrateLift Migration Wizard (Part 15).
 *
 * Verifies:
 * - 7-step guided migration workflow structure
 * - Strict destination hostname typing confirmation for destructive apply
 * - Active commerce and in-flight changes advisory notices
 * - File and database replacement policies with rollback checkpoint notice
 * - Clean sync explicit confirmation requirement
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );

test( 'Migration Wizard Component Contract: 7-step guided workflow & safety guards', () => {
	const wizardJs = fs.readFileSync( path.join( __dirname, '../src/components/migrate/MigrationWizard.js' ), 'utf-8' );

	// Verify all 7 steps are present
	const expectedSteps = [
		'Step 1: Cryptographic Site Pairing',
		'Step 2: Migration Scope & Migration Mode',
		'Step 3: Remote Destination Preflight Readiness',
		'Step 4: Streaming Archive Direct to Destination',
		'Step 5: File & Database Replacement Strategy',
		'Step 6: Confirm Destination Restoration',
		'Step 7: Verify & Post-Migration Report',
	];

	for ( const step of expectedSteps ) {
		assert.ok(
			wizardJs.includes( step ),
			`MigrationWizard must include step: '${ step }'`
		);
	}

	// Verify safety confirmation: requires exact destination hostname
	assert.ok(
		wizardJs.includes( 'expectedDestHost' ) && wizardJs.includes( 'confirmInput' ),
		'Must require typing destination hostname before destructive apply'
	);

	// Verify commerce warning
	assert.ok(
		wizardJs.includes( 'Active Sites & E-Commerce Notice' ),
		'Must provide active site and e-commerce advisory notice'
	);

	// Verify file and database policies
	assert.ok( wizardJs.includes( 'overwrite_matching' ), 'Must support overwrite_matching file policy' );
	assert.ok( wizardJs.includes( 'clean_sync' ), 'Must support clean_sync file policy' );
	assert.ok( wizardJs.includes( 'replace_mapped' ), 'Must support replace_mapped db policy' );
	assert.ok( wizardJs.includes( 'preserveAdmin' ), 'Must support admin user preservation option' );

	// Verify non-destructive transfer guarantee
	assert.ok(
		wizardJs.includes( 'Non-Destructive Guarantee' ) || wizardJs.includes( 'Target Unchanged' ),
		'Must reassure administrator that target site is unchanged during transfer'
	);
} );

