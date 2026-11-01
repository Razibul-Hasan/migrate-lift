/**
 * SettingsView Component
 * Hosts maintenance tools, storage diagnostics, and system settings.
 * Implements Part 06: Compression & Granular Exclusions management.
 */
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Button,
	RadioControl,
	CheckboxControl,
	TextareaControl,
	Notice,
	Spinner,
	Flex,
	FlexItem,
} from '@wordpress/components';
import PageHeader from '../components/common/PageHeader';
import SectionCard from '../components/common/SectionCard';
import StatusPill from '../components/common/StatusPill';
import CleanTemporary from '../components/CleanTemporary';
import { IconShield, IconHarddrive } from '../components/common/Icons';
import { getCompressionSettings, saveCompressionSettings } from '../utils/api';

const cfg = ( typeof window !== 'undefined' && window.migrateLift ) || {};

export default function SettingsView( { isBusy, setBusy } ) {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ feedback, setFeedback ] = useState( null );

	// Settings state
	const [ mode, setMode ] = useState( 'balanced' );
	const [ format, setFormat ] = useState( 'tgz' );
	const [ mediaBypass, setMediaBypass ] = useState( true );
	const [ excludeCache, setExcludeCache ] = useState( true );
	const [ excludeLogs, setExcludeLogs ] = useState( true );
	const [ excludeOtherBackups, setExcludeOtherBackups ] = useState( true );
	const [ customExclusions, setCustomExclusions ] = useState( '' );

	// Host capabilities
	const [ capabilities, setCapabilities ] = useState( {
		zlib: true,
		zip_extension: false,
		zip_supported: true,
		zstd_supported: false,
		zstd_notice: '',
		media_disclaimer: '',
	} );

	useEffect( () => {
		let isMounted = true;

		async function loadSettings() {
			try {
				setLoading( true );
				const data = await getCompressionSettings();
				if ( ! isMounted || ! data ) return;

				if ( data.settings ) {
					setMode( data.settings.mode || 'balanced' );
					setFormat( data.settings.format || 'tgz' );
					setMediaBypass( data.settings.media_bypass !== false );
					setExcludeCache( data.settings.exclude_cache !== false );
					setExcludeLogs( data.settings.exclude_logs !== false );
					setExcludeOtherBackups( data.settings.exclude_other_backups !== false );

					const custom = data.settings.custom_exclusions;
					if ( Array.isArray( custom ) ) {
						setCustomExclusions( custom.join( '\n' ) );
					} else if ( typeof custom === 'string' ) {
						setCustomExclusions( custom );
					}
				}

				if ( data.capabilities ) {
					setCapabilities( data.capabilities );
				}
			} catch ( err ) {
				// eslint-disable-next-line no-console
				console.warn( '[MigrateLift] Could not fetch compression settings:', err );
			} finally {
				if ( isMounted ) {
					setLoading( false );
				}
			}
		}

		loadSettings();

		return () => {
			isMounted = false;
		};
	}, [] );

	const handleSave = async ( e ) => {
		if ( e && e.preventDefault ) e.preventDefault();
		setFeedback( null );
		setSaving( true );

		try {
			const res = await saveCompressionSettings( {
				mode,
				format,
				media_bypass: mediaBypass,
				exclude_cache: excludeCache,
				exclude_logs: excludeLogs,
				exclude_other_backups: excludeOtherBackups,
				custom_exclusions: customExclusions,
			} );

			setFeedback( {
				type: 'success',
				message: __( 'Compression and exclusion settings saved successfully.', 'migrate-lift' ),
			} );

			if ( res?.settings ) {
				setMode( res.settings.mode );
				setFormat( res.settings.format );
				setMediaBypass( res.settings.media_bypass );
				setExcludeCache( res.settings.exclude_cache );
				setExcludeLogs( res.settings.exclude_logs );
				setExcludeOtherBackups( res.settings.exclude_other_backups );
				if ( Array.isArray( res.settings.custom_exclusions ) ) {
					setCustomExclusions( res.settings.custom_exclusions.join( '\n' ) );
				}
			}
		} catch ( err ) {
			setFeedback( {
				type: 'error',
				message: err.message || __( 'Failed to save settings. Please verify administrator permissions.', 'migrate-lift' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="ml-settings-view">
			<PageHeader
				overline={ __( 'System & Configuration', 'migrate-lift' ) }
				title={ __( 'Settings & Maintenance', 'migrate-lift' ) }
				description={ __(
					'Configure backup compression algorithms, granular exclusion lists, and storage boundaries.',
					'migrate-lift'
				) }
			/>

			<div style={ { display: 'flex', flexDirection: 'column', gap: '24px' } }>
				{ /* Compression & Exclusions Section (Part 06) */ }
				<SectionCard
					title={ __( 'Compression & Granular Exclusions', 'migrate-lift' ) }
					subtitle={ __( 'Archive formats, multi-mode compression ratios, and safety rules', 'migrate-lift' ) }
				>
					{ loading ? (
						<div style={ { padding: '24px', textAlign: 'center' } }>
							<Spinner />
							<div style={ { marginTop: '8px', fontSize: '13px', color: 'var(--ml-muted)' } }>
								{ __( 'Loading compression configuration…', 'migrate-lift' ) }
							</div>
						</div>
					) : (
						<form onSubmit={ handleSave } style={ { display: 'flex', flexDirection: 'column', gap: '20px' } }>
							{ feedback && (
								<Notice
									status={ feedback.type }
									isDismissible={ true }
									onDismiss={ () => setFeedback( null ) }
								>
									{ feedback.message }
								</Notice>
							) }

							{ /* Realistic expectations banner */ }
							<div
								style={ {
									padding: '12px 16px',
									backgroundColor: 'var(--ml-canvas, #f8fafc)',
									border: '1px solid var(--ml-line, #e2e8f0)',
									borderRadius: 'var(--ml-radius-md, 6px)',
									fontSize: '13px',
									color: 'var(--ml-ink-secondary, #475569)',
									lineHeight: 1.5,
								} }
							>
								<strong>{ __( 'Truth in Compression:', 'migrate-lift' ) }</strong>{ ' ' }
								{ __(
									'Pre-compressed media (JPEG, WebP, MP4, ZIP) shrink very little with Deflate. MigrateLift avoids wasteful CPU re-compression without altering or transcoding your original assets.',
									'migrate-lift'
								) }
							</div>

							{ /* Compression Mode */ }
							<div>
								<h3 style={ { fontSize: '14px', fontWeight: 600, margin: '0 0 8px', color: 'var(--ml-ink-primary)' } }>
									{ __( 'Compression Mode', 'migrate-lift' ) }
								</h3>
								<RadioControl
									selected={ mode }
									options={ [
										{
											label: __( 'Balanced (Default) — Standard Deflate (Level 6). Recommended balance of archive size and server CPU.', 'migrate-lift' ),
											value: 'balanced',
										},
										{
											label: __( 'Fastest — Low Deflate (Level 1). Quickest archiving with lowest CPU utilization for shared hosting.', 'migrate-lift' ),
											value: 'fastest',
										},
										{
											label: __( 'Smallest Practical — Maximum Deflate (Level 9). Best achievable reduction for compressible files (higher CPU usage).', 'migrate-lift' ),
											value: 'smallest',
										},
									] }
									onChange={ ( val ) => setMode( val ) }
								/>
							</div>

							{ /* Archive Format */ }
							<div>
								<h3 style={ { fontSize: '14px', fontWeight: 600, margin: '0 0 8px', color: 'var(--ml-ink-primary)' } }>
									{ __( 'Archive Format', 'migrate-lift' ) }
								</h3>
								<RadioControl
									selected={ format }
									options={ [
										{
											label: __( 'TAR.GZ (.tgz) — Standard streaming compressed tar archive (Recommended).', 'migrate-lift' ),
											value: 'tgz',
										},
										{
											label: __( 'Standard ZIP (.zip) — Universally portable PKZIP archive format.', 'migrate-lift' ),
											value: 'zip',
										},
									] }
									onChange={ ( val ) => setFormat( val ) }
								/>
							</div>

							{ /* Media Bypass Toggle */ }
							<div>
								<CheckboxControl
									label={ __( 'Enable extension-aware media bypass (Recommended)', 'migrate-lift' ) }
									help={ __(
										'Stores already-compressed files (images, video, archives) without redundant Deflate passes to save time and host CPU.',
										'migrate-lift'
									) }
									checked={ mediaBypass }
									onChange={ ( val ) => setMediaBypass( val ) }
								/>
							</div>

							{ /* Default Exclusions */ }
							<div>
								<h3 style={ { fontSize: '14px', fontWeight: 600, margin: '0 0 10px', color: 'var(--ml-ink-primary)' } }>
									{ __( 'Safe Exclusions', 'migrate-lift' ) }
								</h3>
								<div style={ { display: 'flex', flexDirection: 'column', gap: '8px' } }>
									<CheckboxControl
										label={ __( 'Exclude cache directories (wp-content/cache, LiteSpeed, Wordfence logs, etc.)', 'migrate-lift' ) }
										checked={ excludeCache }
										onChange={ ( val ) => setExcludeCache( val ) }
									/>
									<CheckboxControl
										label={ __( 'Exclude debug and error logs (debug.log, *.log, php_errorlog)', 'migrate-lift' ) }
										checked={ excludeLogs }
										onChange={ ( val ) => setExcludeLogs( val ) }
									/>
									<CheckboxControl
										label={ __( 'Exclude third-party backup plugin archives (UpdraftPlus, All-in-One WP Migration, WPvivid)', 'migrate-lift' ) }
										checked={ excludeOtherBackups }
										onChange={ ( val ) => setExcludeOtherBackups( val ) }
									/>
								</div>
							</div>

							{ /* Custom Exclusions */ }
							<div>
								<h3 style={ { fontSize: '14px', fontWeight: 600, margin: '0 0 4px', color: 'var(--ml-ink-primary)' } }>
									{ __( 'Custom Exclusion Rules', 'migrate-lift' ) }
								</h3>
								<div style={ { fontSize: '12px', color: 'var(--ml-muted)', marginBottom: '8px' } }>
									{ __( 'Enter relative paths or wildcards inside wp-content (one per line). Example: uploads/large-temp-folder or *.bak', 'migrate-lift' ) }
								</div>
								<TextareaControl
									rows={ 4 }
									value={ customExclusions }
									onChange={ ( val ) => setCustomExclusions( val ) }
									placeholder={ "custom-cache/\nsecret/*.tmp" }
								/>
							</div>

							{ /* Host Capabilities & Safety Info */ }
							<div
								style={ {
									padding: '12px 14px',
									background: 'var(--ml-canvas, #f8fafc)',
									border: '1px solid var(--ml-line, #e2e8f0)',
									borderRadius: 'var(--ml-radius-sm, 4px)',
									fontSize: '12px',
									display: 'flex',
									flexDirection: 'column',
									gap: '6px',
								} }
							>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
									<span><strong>{ __( 'Host Zlib Support:', 'migrate-lift' ) }</strong></span>
									<StatusPill status={ capabilities.zlib ? 'success' : 'danger' } label={ capabilities.zlib ? 'Active' : 'Missing' } />
								</div>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
									<span><strong>{ __( 'ZIP Archive Support:', 'migrate-lift' ) }</strong></span>
									<StatusPill status="success" label={ capabilities.zip_extension ? 'Native (ext-zip)' : 'Portable Streaming' } />
								</div>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
									<span><strong>{ __( 'Zstandard (zstd):', 'migrate-lift' ) }</strong></span>
									<StatusPill status="neutral" label="Disabled (Universal Portability)" />
								</div>
								<div style={ { color: 'var(--ml-muted)', fontSize: '11px', marginTop: '4px' } }>
									{ capabilities.zstd_notice || __( 'Zstandard is disabled to guarantee universal restorability across target hosts.', 'migrate-lift' ) }
								</div>
							</div>

							{ /* Save Button */ }
							<Flex justify="flex-start">
								<FlexItem>
									<Button
										isPrimary
										type="submit"
										disabled={ saving || isBusy }
									>
										{ saving ? __( 'Saving Settings…', 'migrate-lift' ) : __( 'Save Compression Settings', 'migrate-lift' ) }
									</Button>
								</FlexItem>
							</Flex>
						</form>
					) }
				</SectionCard>

				{ /* Maintenance & Cleanup */ }
				<CleanTemporary isBusy={ isBusy } setBusy={ setBusy } />

				{ /* System Diagnostics */ }
				<SectionCard
					title={ __( 'Storage & Environment Diagnostics', 'migrate-lift' ) }
					subtitle={ __( 'Host server runtime variables and storage boundaries', 'migrate-lift' ) }
				>
					<div style={ { display: 'flex', flexDirection: 'column', gap: '14px', fontSize: '13px' } }>
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 14px', background: 'var(--ml-canvas)', borderRadius: 'var(--ml-radius-sm)' } }>
							<div style={ { display: 'flex', alignItems: 'center', gap: '10px' } }>
								<IconHarddrive style={ { width: 18, height: 18, color: 'var(--ml-teal)' } } />
								<div>
									<strong>{ __( 'Chunked Upload Buffer Size', 'migrate-lift' ) }</strong>
									<div style={ { fontSize: '11px', color: 'var(--ml-muted)' } }>{ __( 'Optimized for hosting upload ceilings', 'migrate-lift' ) }</div>
								</div>
							</div>
							<StatusPill
								status="info"
								label={ cfg.chunk ? `${ Math.round( cfg.chunk / 1024 / 1024 ) } MB per chunk` : '4 MB' }
							/>
						</div>

						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 14px', background: 'var(--ml-canvas)', borderRadius: 'var(--ml-radius-sm)' } }>
							<div style={ { display: 'flex', alignItems: 'center', gap: '10px' } }>
								<IconShield style={ { width: 18, height: 18, color: 'var(--ml-teal)' } } />
								<div>
									<strong>{ __( 'Plugin API Namespace', 'migrate-lift' ) }</strong>
									<div style={ { fontSize: '11px', color: 'var(--ml-muted)' } }>{ __( 'Secured with manage_options permission callbacks', 'migrate-lift' ) }</div>
								</div>
							</div>
							<StatusPill status="neutral" label="migrate-lift/v1" />
						</div>

						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '12px 14px', background: 'var(--ml-canvas)', borderRadius: 'var(--ml-radius-sm)' } }>
							<div style={ { display: 'flex', alignItems: 'center', gap: '10px' } }>
								<IconShield style={ { width: 18, height: 18, color: 'var(--ml-teal)' } } />
								<div>
									<strong>{ __( 'Site Home URL', 'migrate-lift' ) }</strong>
									<div style={ { fontSize: '11px', color: 'var(--ml-muted)' } }>{ __( 'Target baseline for serialization search & replace', 'migrate-lift' ) }</div>
								</div>
							</div>
							<span style={ { fontSize: '12px', fontWeight: 600, color: 'var(--ml-ink-secondary)' } }>{ cfg.home || '—' }</span>
						</div>
					</div>
				</SectionCard>
			</div>
		</div>
	);
}
