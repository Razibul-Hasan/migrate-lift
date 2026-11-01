<?php
/**
 * Streaming .tar.gz writer and reader.
 *
 * The writer appends one complete gzip member per request, which is still a valid .tgz
 * (7-Zip, tar and gzip read concatenated members). Nothing is held in memory, so archive
 * size is only limited by disk space.
 *
 * @package MigrateLift
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread

/**
 * Appends tar entries to a .tgz file.
 */
class Migrate_Lift_Tar_Writer {

	/**
	 * File handle.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Deflate context.
	 *
	 * @var resource|DeflateContext
	 */
	private $zlib;

	/**
	 * Opens the archive for appending.
	 *
	 * @param string $path  Archive path.
	 * @param int    $level Gzip level.
	 */
	public function __construct( $path, $level = 6 ) {
		$this->handle = fopen( $path, 'ab' );
		$this->zlib   = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => $level ) );
		if ( ! $this->handle || ! $this->zlib ) {
			throw new Exception( 'Could not open the archive for writing.' );
		}
	}

	/**
	 * Writes an entry header. Names longer than 99 bytes get a GNU long-name entry first.
	 *
	 * @param string $name  Path inside the archive.
	 * @param int    $size  Size in bytes.
	 * @param int    $mtime Modified time.
	 */
	public function header( $name, $size, $mtime ) {
		if ( strlen( $name ) > 99 ) {
			$this->write( $this->block( '././@LongLink', strlen( $name ) + 1, 0, 'L' ) );
			$this->write( $name . "\0" );
			$this->pad( strlen( $name ) + 1 );
			$name = substr( $name, 0, 99 );
		}
		$this->write( $this->block( $name, $size, $mtime, '0' ) );
	}

	/**
	 * Adds a whole entry from a string.
	 *
	 * @param string $name Path inside the archive.
	 * @param string $data Contents.
	 */
	public function add_string( $name, $data ) {
		$this->header( $name, strlen( $data ), time() );
		$this->write( $data );
		$this->pad( strlen( $data ) );
	}

	/**
	 * Writes raw bytes.
	 *
	 * @param string $bytes Bytes.
	 */
	public function write( $bytes ) {
		$out = deflate_add( $this->zlib, $bytes, ZLIB_NO_FLUSH );
		if ( '' !== $out && false === fwrite( $this->handle, $out ) ) {
			throw new Exception( 'Could not write the archive. Is the disk full?' );
		}
	}

	/**
	 * Pads an entry's data to the 512-byte block size.
	 *
	 * @param int $size Entry size.
	 */
	public function pad( $size ) {
		$rest = $size % 512;
		if ( $rest ) {
			$this->write( str_repeat( "\0", 512 - $rest ) );
		}
	}

	/**
	 * Writes the end-of-archive marker.
	 */
	public function finish() {
		$this->write( str_repeat( "\0", 1024 ) );
	}

	/**
	 * Ends this gzip member and closes the file.
	 */
	public function close() {
		fwrite( $this->handle, deflate_add( $this->zlib, '', ZLIB_FINISH ) );
		fclose( $this->handle );
	}

	/**
	 * Builds a GNU tar header block.
	 *
	 * @param string $name  Name.
	 * @param int    $size  Size.
	 * @param int    $mtime Modified time.
	 * @param string $type  Type flag.
	 * @return string
	 */
	private function block( $name, $size, $mtime, $type ) {
		$block = pack(
			'a100a8a8a8a12a12a8a1a100a8a32a32a8a8a167',
			$name,
			'0000644',
			'0000000',
			'0000000',
			sprintf( '%011o', $size ),
			sprintf( '%011o', max( 0, (int) $mtime ) ),
			'        ',
			$type,
			'',
			'ustar  ',
			'',
			'',
			'',
			'',
			''
		);
		$sum = array_sum( unpack( 'C*', $block ) );
		return substr_replace( $block, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}
}

/**
 * Reads tar entries from a .tgz (or plain .tar) file.
 */
class Migrate_Lift_Tar_Reader {

	/**
	 * Position in the uncompressed tar stream.
	 *
	 * @var int
	 */
	public $pos = 0;

	/**
	 * Gzip handle.
	 *
	 * @var resource
	 */
	private $gz;

	/**
	 * Opens the archive and moves to a saved position.
	 *
	 * @param string $path Archive path.
	 * @param int    $pos  Uncompressed position to resume from.
	 */
	public function __construct( $path, $pos = 0 ) {
		$this->gz = gzopen( $path, 'rb' );
		if ( ! $this->gz ) {
			throw new Exception( 'Could not open the archive.' );
		}
		$this->skip( $pos );
	}

	/**
	 * Closes the archive.
	 */
	public function close() {
		gzclose( $this->gz );
	}

	/**
	 * Reads up to $length bytes.
	 *
	 * @param int $length Bytes.
	 * @return string
	 */
	public function read( $length ) {
		$out = '';
		while ( strlen( $out ) < $length ) {
			$chunk = gzread( $this->gz, min( 4194304, $length - strlen( $out ) ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$out .= $chunk;
		}
		$this->pos += strlen( $out );
		return $out;
	}

	/**
	 * Reads exactly $length bytes or throws.
	 *
	 * @param int $length Bytes.
	 * @return string
	 */
	public function read_exact( $length ) {
		$data = $this->read( $length );
		if ( strlen( $data ) !== $length ) {
			throw new Exception( 'The archive is incomplete or damaged. Upload it again.' );
		}
		return $data;
	}

	/**
	 * Skips bytes.
	 *
	 * @param int $length Bytes.
	 */
	public function skip( $length ) {
		while ( $length > 0 ) {
			$step = min( 4194304, $length );
			$this->read_exact( $step );
			$length -= $step;
		}
	}

	/**
	 * Skips the padding after an entry's data.
	 *
	 * @param int $size Entry size.
	 */
	public function skip_pad( $size ) {
		$rest = $size % 512;
		if ( $rest ) {
			$this->skip( 512 - $rest );
		}
	}

	/**
	 * Reads the next entry header.
	 *
	 * @return array|null name, size, type, mtime; null at the end-of-archive marker.
	 */
	public function next() {
		$long = null;
		while ( true ) {
			$block = $this->read_exact( 512 );
			if ( '' === trim( $block, "\0" ) ) {
				return null;
			}

			$h   = unpack( 'Z100name/Z8mode/Z8uid/Z8gid/Z12size/Z12mtime/Z8sum/Z1type/Z100link/Z6magic/Z2version/Z32uname/Z32gname/Z8major/Z8minor/Z155prefix', $block );
			$sum = array_sum( unpack( 'C*', substr_replace( $block, '        ', 148, 8 ) ) );
			if ( octdec( trim( $h['sum'] ) ) !== $sum ) {
				throw new Exception( 'The archive is damaged (bad header checksum).' );
			}

			$size = (int) octdec( trim( $h['size'] ) );
			$type = '' === $h['type'] ? '0' : $h['type'];

			if ( 'L' === $type ) { // GNU long name.
				$long = rtrim( $this->read_exact( $size ), "\0" );
				$this->skip_pad( $size );
				continue;
			}
			if ( 'x' === $type || 'g' === $type ) { // PAX headers.
				$pax = $this->read_exact( $size );
				$this->skip_pad( $size );
				if ( 'x' === $type && preg_match( '/^\d+ path=(.*)$/m', $pax, $m ) ) {
					$long = $m[1];
				}
				continue;
			}

			if ( null !== $long ) {
				$name = $long;
			} elseif ( 'ustar' === $h['magic'] && '' !== $h['prefix'] ) {
				$name = $h['prefix'] . '/' . $h['name'];
			} else {
				$name = $h['name'];
			}

			return array(
				'name'  => $name,
				'size'  => $size,
				'type'  => $type,
				'mtime' => (int) octdec( trim( $h['mtime'] ) ),
			);
		}
	}
}

