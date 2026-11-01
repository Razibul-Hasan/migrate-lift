<?php
/**
 * Streaming ZIP Archive Writer and Reader.
 *
 * Implements pure-PHP, memory-bounded, streaming ZIP format:
 * - Compatible with PKZIP 2.0 standards, Windows Explorer, macOS Finder, 7-Zip, and unzip
 * - Does not require ext-zip or ZipArchive (functions on any host with standard zlib)
 * - Uses ZipArchive as an accelerated reader when available, with pure-PHP fallback
 * - Supports Method 0 (Store) for already-compressed media to save CPU
 * - Supports Method 8 (Deflate) with selectable compression levels (1, 6, 9)
 * - Safe chunked streaming prevents materializing large files in memory
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

/**
 * Class Migrate_Lift_Zip_Writer
 */
class Migrate_Lift_Zip_Writer {

	/**
	 * File handle.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Active deflation context.
	 *
	 * @var resource|DeflateContext|null
	 */
	private $zlib = null;

	/**
	 * Active entry state.
	 *
	 * @var array|null
	 */
	private $current_entry = null;

	/**
	 * Opens a ZIP file for writing/appending.
	 *
	 * @param string $path Target archive path.
	 * @throws Exception On failure to open file.
	 */
	public function __construct( $path ) {
		$this->handle = @fopen( $path, 'c+b' );
		if ( ! $this->handle ) {
			throw new Exception( 'Could not open ZIP archive for writing. Verify folder permissions.' );
		}
		// Move to end of file for appending
		fseek( $this->handle, 0, SEEK_END );
	}

	/**
	 * Returns current file write offset.
	 *
	 * @return int
	 */
	public function get_offset() {
		return ftell( $this->handle );
	}

	/**
	 * Truncates file back to a given offset (for checkpoint rollback).
	 *
	 * @param int $offset Byte offset.
	 */
	public function truncate_to( $offset ) {
		if ( $this->handle ) {
			ftruncate( $this->handle, $offset );
			fseek( $this->handle, $offset, SEEK_SET );
		}
	}

	/**
	 * Converts Unix timestamp to MS-DOS date and time integers.
	 *
	 * @param int $time Unix timestamp.
	 * @return array array( $dos_date, $dos_time )
	 */
	public static function unix_to_dos_time( $time ) {
		$time = max( 0, (int) $time );
		$date = getdate( $time );
		if ( $date['year'] < 1980 ) {
			return array( ( 1 << 5 ) | 1, 0 );
		}
		$dos_date = ( ( $date['year'] - 1980 ) << 9 ) | ( $date['mon'] << 5 ) | $date['mday'];
		$dos_time = ( $date['hours'] << 11 ) | ( $date['minutes'] << 5 ) | ( $date['seconds'] >> 1 );
		return array( $dos_date, $dos_time );
	}

	/**
	 * Adds a whole entry from a string.
	 *
	 * @param string $name     Relative path inside archive.
	 * @param string $data     Entry contents.
	 * @param bool   $compress Whether to deflate or store.
	 * @param int    $level    Compression level (1-9).
	 * @return array Entry record for Central Directory.
	 * @throws Exception On write failure.
	 */
	public function add_string( $name, $data, $compress = true, $level = 6 ) {
		$name   = str_replace( '\\', '/', ltrim( $name, '/' ) );
		$size   = strlen( $data );
		$crc    = crc32( $data );
		$method = $compress ? 8 : 0;
		$mtime  = time();

		$payload = $data;
		if ( $compress ) {
			$deflated = gzdeflate( $data, $level );
			if ( false !== $deflated && strlen( $deflated ) < $size ) {
				$payload = $deflated;
			} else {
				$method  = 0;
				$payload = $data;
			}
		}

		$comp_size  = strlen( $payload );
		$offset     = ftell( $this->handle );
		list( $dos_date, $dos_time ) = self::unix_to_dos_time( $mtime );

		// Local File Header: PK\x03\x04
		$header = pack(
			'VvvvvvVVVvv',
			0x04034b50,       // Local file header signature
			20,               // Version needed to extract (2.0)
			0x0800,           // General purpose bit flag (UTF-8 filename)
			$method,          // Compression method (0 or 8)
			$dos_time,        // Last mod file time
			$dos_date,        // Last mod file date
			$crc,             // CRC-32
			$comp_size,       // Compressed size
			$size,            // Uncompressed size
			strlen( $name ),  // File name length
			0                 // Extra field length
		) . $name;

		$written = fwrite( $this->handle, $header . $payload );
		if ( false === $written || $written < ( strlen( $header ) + $comp_size ) ) {
			throw new Exception( 'Could not write ZIP entry. Disk space may be exhausted.' );
		}

		return array(
			'name'       => $name,
			'size'       => $size,
			'comp_size'  => $comp_size,
			'crc'        => $crc,
			'method'     => $method,
			'mtime'      => $mtime,
			'dos_date'   => $dos_date,
			'dos_time'   => $dos_time,
			'offset'     => $offset,
		);
	}

	/**
	 * Starts streaming a new file entry.
	 *
	 * @param string $name     Relative path inside archive.
	 * @param int    $size     Uncompressed file size.
	 * @param int    $mtime    File modification timestamp.
	 * @param bool   $compress Whether to deflate or store.
	 * @param int    $level    Compression level.
	 * @throws Exception On failure.
	 */
	public function start_entry( $name, $size, $mtime, $compress = true, $level = 6 ) {
		$name   = str_replace( '\\', '/', ltrim( $name, '/' ) );
		$offset = ftell( $this->handle );
		$method = $compress ? 8 : 0;
		list( $dos_date, $dos_time ) = self::unix_to_dos_time( $mtime );

		// Placeholder Local Header with bit 3 (data descriptor) or pre-allocated space
		// To allow random-access updates and standard parsers, write full header placeholder
		$header = pack(
			'VvvvvvVVVvv',
			0x04034b50,
			20,
			0x0800,
			$method,
			$dos_time,
			$dos_date,
			0, // CRC will be backpatched upon finish
			0, // Comp size backpatched
			$size,
			strlen( $name ),
			0
		) . $name;

		$written = fwrite( $this->handle, $header );
		if ( false === $written ) {
			throw new Exception( 'Failed writing local ZIP entry header.' );
		}

		$this->current_entry = array(
			'name'        => $name,
			'size'        => $size,
			'mtime'       => $mtime,
			'dos_date'    => $dos_date,
			'dos_time'    => $dos_time,
			'method'      => $method,
			'level'       => $level,
			'offset'      => $offset,
			'header_size' => strlen( $header ),
			'data_offset' => $offset + strlen( $header ),
			'comp_size'   => 0,
			'crc_context' => hash_init( 'crc32b' ),
		);

		if ( 8 === $method ) {
			$this->zlib = deflate_init( ZLIB_ENCODING_RAW, array( 'level' => $level ) );
			if ( ! $this->zlib ) {
				throw new Exception( 'Failed to initialize Deflate context.' );
			}
		}
	}

	/**
	 * Writes a chunk of uncompressed data for the current entry.
	 *
	 * @param string $chunk Uncompressed raw bytes.
	 * @throws Exception On write error.
	 */
	public function write_chunk( $chunk ) {
		if ( ! $this->current_entry ) {
			throw new Exception( 'No active ZIP entry to write chunk to.' );
		}

		hash_update( $this->current_entry['crc_context'], $chunk );

		if ( 8 === $this->current_entry['method'] ) {
			$compressed = deflate_add( $this->zlib, $chunk, ZLIB_NO_FLUSH );
			if ( '' !== $compressed ) {
				$written = fwrite( $this->handle, $compressed );
				if ( false === $written ) {
					throw new Exception( 'Could not write compressed chunk to ZIP. Disk may be full.' );
				}
				$this->current_entry['comp_size'] += strlen( $compressed );
			}
		} else {
			$written = fwrite( $this->handle, $chunk );
			if ( false === $written ) {
				throw new Exception( 'Could not write stored chunk to ZIP. Disk may be full.' );
			}
			$this->current_entry['comp_size'] += strlen( $chunk );
		}
	}

	/**
	 * Finishes the current entry, flushes compression, and backpatches local header.
	 *
	 * @return array Entry record for Central Directory.
	 * @throws Exception On failure.
	 */
	public function end_entry() {
		if ( ! $this->current_entry ) {
			throw new Exception( 'No active ZIP entry to finalize.' );
		}

		if ( 8 === $this->current_entry['method'] ) {
			$final = deflate_add( $this->zlib, '', ZLIB_FINISH );
			if ( '' !== $final ) {
				$written = fwrite( $this->handle, $final );
				if ( false === $written ) {
					throw new Exception( 'Could not flush final compressed block to ZIP.' );
				}
				$this->current_entry['comp_size'] += strlen( $final );
			}
			$this->zlib = null;
		}

		// Calculate final CRC-32 integer
		$crc_hex = hash_final( $this->current_entry['crc_context'] );
		$crc_int = hexdec( $crc_hex );

		// Backpatch CRC and compressed size in local file header
		$current_pos = ftell( $this->handle );
		fseek( $this->handle, $this->current_entry['offset'] + 14, SEEK_SET );
		fwrite( $this->handle, pack( 'VVV', $crc_int, $this->current_entry['comp_size'], $this->current_entry['size'] ) );
		fseek( $this->handle, $current_pos, SEEK_SET );

		$entry = array(
			'name'       => $this->current_entry['name'],
			'size'       => $this->current_entry['size'],
			'comp_size'  => $this->current_entry['comp_size'],
			'crc'        => $crc_int,
			'method'     => $this->current_entry['method'],
			'mtime'      => $this->current_entry['mtime'],
			'dos_date'   => $this->current_entry['dos_date'],
			'dos_time'   => $this->current_entry['dos_time'],
			'offset'     => $this->current_entry['offset'],
		);

		$this->current_entry = null;
		return $entry;
	}

	/**
	 * Writes the Central Directory and End of Central Directory record.
	 *
	 * @param array $entries Array of entry records produced by add_string/end_entry.
	 * @throws Exception On write failure.
	 */
	public function finalize_central_directory( array $entries ) {
		$cd_offset = ftell( $this->handle );
		$cd_size   = 0;

		foreach ( $entries as $entry ) {
			$name = $entry['name'];
			$cd_header = pack(
				'VvvvvvvVVVvvvvvVV',
				0x02014b50,       // Central directory file header signature
				20,               // Version made by (2.0)
				20,               // Version needed to extract (2.0)
				0x0800,           // General purpose bit flag (UTF-8)
				$entry['method'], // Compression method
				$entry['dos_time'],
				$entry['dos_date'],
				$entry['crc'],
				$entry['comp_size'],
				$entry['size'],
				strlen( $name ),  // File name length
				0,                // Extra field length
				0,                // File comment length
				0,                // Disk number start
				0,                // Internal file attributes
				0100644 << 16,    // External file attributes
				$entry['offset']  // Relative offset of local header
			) . $name;

			$written = fwrite( $this->handle, $cd_header );
			if ( false === $written ) {
				throw new Exception( 'Could not write Central Directory header to ZIP.' );
			}
			$cd_size += strlen( $cd_header );
		}

		$total_entries = count( $entries );

		// End of Central Directory Record (EOCD): PK\x05\x06
		$eocd = pack(
			'VvvvvVVv',
			0x06054b50,       // EOCD signature
			0,                // Number of this disk
			0,                // Disk where central directory starts
			$total_entries,   // Number of central directory records on this disk
			$total_entries,   // Total number of central directory records
			$cd_size,         // Size of central directory
			$cd_offset,       // Offset of start of central directory
			0                 // ZIP comment length
		);

		$written = fwrite( $this->handle, $eocd );
		if ( false === $written ) {
			throw new Exception( 'Could not write End of Central Directory to ZIP.' );
		}
	}

	/**
	 * Closes the file handle.
	 */
	public function close() {
		if ( $this->handle ) {
			fflush( $this->handle );
			fclose( $this->handle );
			$this->handle = null;
		}
	}
}

/**
 * Class Migrate_Lift_Zip_Reader
 *
 * Verifies and reads contents of standard ZIP archives.
 */
class Migrate_Lift_Zip_Reader {

	/**
	 * Archive file path.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * File handle for pure-PHP reading.
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Parsed Central Directory entries.
	 *
	 * @var array|null
	 */
	private $entries = null;

	/**
	 * Opens a ZIP file for inspection.
	 *
	 * @param string $path Archive path.
	 * @throws Exception On invalid archive.
	 */
	public function __construct( $path ) {
		clearstatcache( true, $path );
		$fp = @fopen( $path, 'rb' );
		if ( ! file_exists( $path ) || false === $fp ) {
			throw new Exception( 'ZIP archive does not exist or is unreadable.' );
		}
		fclose( $fp );
		$this->path = $path;
		$this->read_central_directory();
	}

	/**
	 * Locates and reads Central Directory from archive.
	 *
	 * @throws Exception On corruption.
	 */
	private function read_central_directory() {
		// If ZipArchive is available, use it for quick cataloging
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			$res = $zip->open( $this->path );
			if ( true === $res ) {
				$this->entries = array();
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$stat = $zip->statIndex( $i );
					if ( $stat ) {
						$this->entries[ $stat['name'] ] = array(
							'name'      => $stat['name'],
							'size'      => $stat['size'],
							'comp_size' => $stat['comp_size'],
							'crc'       => $stat['crc'],
							'index'     => $i,
						);
					}
				}
				$zip->close();
				return;
			}
		}

		// Fallback: Pure PHP Central Directory Parser
		$this->handle = @fopen( $this->path, 'rb' );
		if ( ! $this->handle ) {
			throw new Exception( 'Could not open ZIP file for reading.' );
		}

		$file_size = filesize( $this->path );
		if ( $file_size < 22 ) {
			throw new Exception( 'ZIP archive is too small to contain valid headers.' );
		}

		// Scan last 65KB + 22 bytes for EOCD signature: PK\x05\x06
		$scan_len = min( $file_size, 65557 );
		fseek( $this->handle, -$scan_len, SEEK_END );
		$buf = fread( $this->handle, $scan_len );

		$eocd_pos = strrpos( $buf, "PK\x05\x06" );
		if ( false === $eocd_pos ) {
			throw new Exception( 'End of Central Directory (EOCD) signature not found. Archive is corrupted or truncated.' );
		}

		$eocd_data = substr( $buf, $eocd_pos );
		if ( strlen( $eocd_data ) < 22 ) {
			throw new Exception( 'Truncated EOCD record in ZIP archive.' );
		}

		$eocd = unpack(
			'Vsig/vdisk/vdisk_start/ventries_disk/ventries_total/Vcd_size/Vcd_offset/vcomment_len',
			substr( $eocd_data, 0, 22 )
		);

		$total_entries = (int) $eocd['entries_total'];
		$cd_offset     = (int) $eocd['cd_offset'];
		$cd_size       = (int) $eocd['cd_size'];

		if ( $cd_offset + $cd_size > $file_size ) {
			throw new Exception( 'Corrupt ZIP archive: Central Directory offset exceeds archive file size.' );
		}

		fseek( $this->handle, $cd_offset, SEEK_SET );
		$this->entries = array();

		for ( $i = 0; $i < $total_entries; $i++ ) {
			$header_raw = fread( $this->handle, 46 );
			if ( strlen( $header_raw ) < 46 ) {
				break;
			}

			$cd = unpack(
				'Vsig/vver_made/vver_needed/vflag/vmethod/vtime/vdate/Vcrc/Vcomp_size/Vsize/vname_len/vextra_len/vcomment_len/vdisk/vattr_int/Vattr_ext/Voffset',
				$header_raw
			);

			if ( 0x02014b50 !== $cd['sig'] ) {
				throw new Exception( 'Corrupted Central Directory header entry.' );
			}

			$name = fread( $this->handle, $cd['name_len'] );
			if ( $cd['extra_len'] > 0 ) {
				fseek( $this->handle, $cd['extra_len'], SEEK_CUR );
			}
			if ( $cd['comment_len'] > 0 ) {
				fseek( $this->handle, $cd['comment_len'], SEEK_CUR );
			}

			$this->entries[ $name ] = array(
				'name'      => $name,
				'size'      => (int) $cd['size'],
				'comp_size' => (int) $cd['comp_size'],
				'crc'       => (int) $cd['crc'],
				'method'    => (int) $cd['method'],
				'offset'    => (int) $cd['offset'],
			);
		}
	}

	/**
	 * Returns array of all entry names.
	 *
	 * @return array
	 */
	public function get_entry_names() {
		return array_keys( $this->entries ?? array() );
	}

	/**
	 * Returns array of all entries and their metadata.
	 *
	 * @return array
	 */
	public function get_entries() {
		return $this->entries ?? array();
	}

	/**
	 * Checks if an entry exists.
	 *
	 * @param string $name Entry path.
	 * @return bool
	 */
	public function has_entry( $name ) {
		return isset( $this->entries[ $name ] );
	}

	/**
	 * Reads entry contents into a string.
	 *
	 * @param string $name Entry name.
	 * @return string|null Content or null if not found.
	 * @throws Exception On read error.
	 */
	public function get_entry_contents( $name ) {
		if ( ! $this->has_entry( $name ) ) {
			return null;
		}

		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $this->path ) ) {
				$data = $zip->getFromName( $name );
				$zip->close();
				if ( false !== $data ) {
					return $data;
				}
			}
		}

		$entry = $this->entries[ $name ];
		if ( ! $this->handle ) {
			$this->handle = fopen( $this->path, 'rb' );
		}

		fseek( $this->handle, $entry['offset'], SEEK_SET );
		$lh_raw = fread( $this->handle, 30 );
		if ( strlen( $lh_raw ) < 30 ) {
			throw new Exception( esc_html( 'Could not read local file header for entry: ' . $name ) );
		}

		$lh = unpack( 'Vsig/vver/vflag/vmethod/vtime/vdate/Vcrc/Vcomp_size/Vsize/vname_len/vextra_len', $lh_raw );
		if ( 0x04034b50 !== $lh['sig'] ) {
			throw new Exception( esc_html( 'Invalid local file header signature for: ' . $name ) );
		}

		// Skip filename and extra fields
		fseek( $this->handle, $lh['name_len'] + $lh['extra_len'], SEEK_CUR );

		$compressed = fread( $this->handle, $entry['comp_size'] );
		if ( 8 === $entry['method'] ) {
			$uncompressed = @gzinflate( $compressed );
			if ( false === $uncompressed ) {
				throw new Exception( esc_html( 'Failed inflating compressed entry: ' . $name ) );
			}
			return $uncompressed;
		}

		return $compressed;
	}

	/**
	 * Closes file handle.
	 */
	public function close() {
		if ( $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * Destructor closes open handle.
	 */
	public function __destruct() {
		$this->close();
	}
}
