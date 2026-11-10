/**
 * Behavioral tests for BackupJobDrawer's auto-start effect.
 *
 * Regression: with the drawer left open after a successful backup, the parent's
 * active-job poll changed `activeJob` (job -> null) and the effect started a
 * brand-new backup, looping forever and filling the disk.
 *
 * The component is compiled with Babel and run on a tiny hook runtime that follows
 * React's rules for useState bail-out and useEffect dependency comparison, so the
 * real effect logic is exercised without a DOM.
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const babel = require( '@babel/core' );

const DRAWER_SRC = path.join( __dirname, '../src/components/backups/BackupJobDrawer.js' );

function sameDeps( a, b ) {
	return !! a && !! b && a.length === b.length && a.every( ( v, i ) => Object.is( v, b[ i ] ) );
}

function createRuntime() {
	const hooks = [];
	let idx = 0;
	let pendingEffects = [];
	let rendering = false;
	let dirty = false;
	let Component = null;
	let props = null;
	let tree = null;

	const flushRender = () => {
		rendering = true;
		do {
			dirty = false;
			idx = 0;
			tree = Component( props );
			const effects = pendingEffects;
			pendingEffects = [];
			effects.forEach( ( fn ) => fn() );
		} while ( dirty );
		rendering = false;
	};

	const element = {
		useState( initial ) {
			const i = idx++;
			if ( ! ( i in hooks ) ) {
				hooks[ i ] = { value: typeof initial === 'function' ? initial() : initial };
			}
			const slot = hooks[ i ];
			const set = ( next ) => {
				const value = typeof next === 'function' ? next( slot.value ) : next;
				if ( Object.is( value, slot.value ) ) return;
				slot.value = value;
				if ( rendering ) {
					dirty = true;
				} else {
					flushRender();
				}
			};
			return [ slot.value, set ];
		},
		useRef( initial ) {
			const i = idx++;
			if ( ! ( i in hooks ) ) hooks[ i ] = { current: initial };
			return hooks[ i ];
		},
		useMemo( factory, deps ) {
			const i = idx++;
			if ( ! hooks[ i ] || ! sameDeps( hooks[ i ].deps, deps ) ) {
				hooks[ i ] = { value: factory(), deps };
			}
			return hooks[ i ].value;
		},
		useCallback( fn, deps ) {
			return element.useMemo( () => fn, deps );
		},
		useEffect( fn, deps ) {
			const i = idx++;
			if ( ! hooks[ i ] || ! sameDeps( hooks[ i ].deps, deps ) ) {
				hooks[ i ] = { deps };
				pendingEffects.push( fn );
			}
		},
	};

	return {
		element,
		mount( Comp ) {
			Component = Comp;
		},
		render( nextProps ) {
			props = nextProps;
			flushRender();
		},
		get tree() {
			return tree;
		},
	};
}

function loadDrawer( runtime, api ) {
	const { code } = babel.transformSync( fs.readFileSync( DRAWER_SRC, 'utf-8' ), {
		filename: DRAWER_SRC,
		babelrc: false,
		configFile: false,
		presets: [ [ require.resolve( '@babel/preset-react' ), { pragma: 'h' } ] ],
		plugins: [ require.resolve( '@babel/plugin-transform-modules-commonjs' ) ],
	} );

	const stub = () => null;
	const mocks = {
		'@wordpress/element': runtime.element,
		'@wordpress/i18n': { __: ( s ) => s },
		'../common/Icons': new Proxy( {}, { get: () => stub } ),
		'../common/ProgressBar': { __esModule: true, default: stub },
		'../../utils/api': api,
	};
	const fakeRequire = ( id ) => {
		if ( ! ( id in mocks ) ) throw new Error( `Unmocked import: ${ id }` );
		return mocks[ id ];
	};
	const h = ( type, elProps, ...children ) => ( { type, props: { ...( elProps || {} ), children } } );

	const module = { exports: {} };
	// eslint-disable-next-line no-new-func
	new Function( 'require', 'module', 'exports', 'h', code )( fakeRequire, module, module.exports, h );
	return module.exports.default;
}

function textOf( node ) {
	if ( node === null || node === undefined || typeof node === 'boolean' ) return '';
	if ( typeof node === 'string' || typeof node === 'number' ) return String( node );
	if ( Array.isArray( node ) ) return node.map( textOf ).join( '' );
	return textOf( node.props?.children );
}

function findButton( node, label ) {
	if ( ! node || typeof node !== 'object' ) return null;
	if ( Array.isArray( node ) ) {
		for ( const child of node ) {
			const found = findButton( child, label );
			if ( found ) return found;
		}
		return null;
	}
	if ( node.type === 'button' && textOf( node ).includes( label ) ) return node;
	return findButton( node.props?.children, label );
}

const flush = () => new Promise( ( resolve ) => setImmediate( resolve ) );

function setup() {
	const calls = [];
	const api = {
		formatBytes: () => '',
		pauseJob: async () => {},
		resumeJob: async () => {},
		cancelJob: async () => {},
		runRestJob( kind, params, onProgress, existingJobId ) {
			let resolve;
			let reject;
			const promise = new Promise( ( res, rej ) => {
				resolve = res;
				reject = rej;
			} );
			calls.push( { kind, existingJobId, resolve, reject } );
			return promise;
		},
	};
	const runtime = createRuntime();
	runtime.mount( loadDrawer( runtime, api ) );

	let successCount = 0;
	const base = {
		onClose: () => {},
		onSuccess: () => {
			successCount++;
		},
	};
	const render = ( p ) => runtime.render( { ...base, ...p } );
	return { calls, runtime, render, successes: () => successCount };
}

test( 'BackupJobDrawer: a finished backup does not start another while the drawer stays open', async () => {
	const { calls, runtime, render, successes } = setup();

	render( { isOpen: true, activeJob: null } );
	assert.equal( calls.length, 1, 'Opening the drawer starts one backup' );
	assert.equal( calls[ 0 ].existingJobId, null, 'The first backup is a new job' );

	// The parent's 10 s poll picks up the running job.
	render( { isOpen: true, activeJob: { id: 'mljob_a', state: 'running', percentage: 40 } } );
	render( { isOpen: true, activeJob: { id: 'mljob_a', state: 'running', percentage: 80 } } );
	assert.equal( calls.length, 1, 'Poll updates during the run start nothing' );

	calls[ 0 ].resolve( { job: { output: { archive: 'site.tgz', size: 1 } } } );
	await flush();
	assert.equal( successes(), 1 );

	// onSuccess -> checkActiveJob() -> no active job any more.
	render( { isOpen: true, activeJob: null } );
	await flush();
	// Further polls keep returning fresh results.
	render( { isOpen: true, activeJob: { id: 'mljob_a', state: 'completed', output: { archive: 'site.tgz' } } } );
	render( { isOpen: true, activeJob: null } );
	await flush();

	assert.equal( calls.length, 1, 'No new backup is started after success' );
	assert.ok( textOf( runtime.tree ).includes( 'Backup Complete' ), 'Drawer stays on the completed state' );
} );

test( 'BackupJobDrawer: closing and reopening allows one more backup', async () => {
	const { calls, render } = setup();

	render( { isOpen: true, activeJob: null } );
	calls[ 0 ].resolve( { job: { output: { archive: 'one.tgz' } } } );
	await flush();
	render( { isOpen: true, activeJob: { id: 'x', state: 'running' } } );
	render( { isOpen: true, activeJob: null } );
	assert.equal( calls.length, 1 );

	render( { isOpen: false, activeJob: null } );
	render( { isOpen: true, activeJob: null } );
	assert.equal( calls.length, 2, 'Reopening the drawer starts exactly one new backup' );
	assert.equal( calls[ 1 ].existingJobId, null );
} );

test( 'BackupJobDrawer: View Progress resumes the running job instead of creating one', async () => {
	const { calls, render } = setup();

	render( { isOpen: true, activeJob: { id: 'mljob_r', state: 'running', percentage: 30 } } );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].existingJobId, 'mljob_r', 'Attaches to the existing job' );

	render( { isOpen: true, activeJob: { id: 'mljob_r', state: 'running', percentage: 60 } } );
	calls[ 0 ].resolve( { job: { output: { archive: 'r.tgz' } } } );
	await flush();
	render( { isOpen: true, activeJob: null } );
	await flush();
	assert.equal( calls.length, 1, 'No second job after the resumed one completes' );
} );

test( 'BackupJobDrawer: Retry Backup after an error starts exactly one job', async () => {
	const { calls, runtime, render } = setup();

	render( { isOpen: true, activeJob: null } );
	calls[ 0 ].reject( new Error( 'Storage folder is not writable.' ) );
	await flush();
	render( { isOpen: true, activeJob: null } );
	assert.equal( calls.length, 1, 'An error does not trigger an automatic restart' );

	const retry = findButton( runtime.tree, 'Retry Backup' );
	assert.ok( retry, 'Retry Backup button is shown' );
	retry.props.onClick();
	assert.equal( calls.length, 2, 'Retry starts one job' );
	retry.props.onClick();
	assert.equal( calls.length, 2, 'A double click does not start a second job' );
} );
