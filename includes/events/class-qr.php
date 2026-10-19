<?php
/**
 * QR code encoder (byte mode, versions 1–20) with PNG and SVG output.
 *
 * Self-contained: no GD, no external service. The algorithm follows the
 * QR Code Model 2 standard (ISO/IEC 18004): Reed–Solomon error correction
 * over GF(256), block interleaving, and the mask with the lowest penalty.
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Class QR
 */
class QR {

	/**
	 * Error correction codewords per block: [ L, M, Q, H ][ version ].
	 *
	 * @var int[][]
	 */
	const ECC_PER_BLOCK = array(
		'L' => array( -1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28 ),
		'M' => array( -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26 ),
		'Q' => array( -1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30 ),
		'H' => array( -1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28 ),
	);

	/**
	 * Number of error correction blocks: [ L, M, Q, H ][ version ].
	 *
	 * @var int[][]
	 */
	const NUM_BLOCKS = array(
		'L' => array( -1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8 ),
		'M' => array( -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16 ),
		'Q' => array( -1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20 ),
		'H' => array( -1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25 ),
	);

	/** Format-bit values of each level. */
	const FORMAT_BITS = array(
		'L' => 1,
		'M' => 0,
		'Q' => 3,
		'H' => 2,
	);

	/**
	 * Module grid: [ y ][ x ] => bool (true = dark).
	 *
	 * @var bool[][]
	 */
	protected $modules = array();

	/**
	 * Function-pattern flags: [ y ][ x ] => bool.
	 *
	 * @var bool[][]
	 */
	protected $is_function = array();

	/**
	 * Side length in modules.
	 *
	 * @var int
	 */
	protected $size = 0;

	/**
	 * Version 1–20.
	 *
	 * @var int
	 */
	protected $version = 1;

	/**
	 * Encode text. Returns the module matrix ([ y ][ x ] => bool) or null
	 * when the text is too long for version 20.
	 *
	 * @param string $text  UTF-8 text (encoded as bytes).
	 * @param string $level L, M, Q or H.
	 * @return bool[][]|null
	 */
	public static function matrix( $text, $level = 'M' ) {
		$level = isset( self::FORMAT_BITS[ $level ] ) ? $level : 'M';
		$qr    = new self();
		return $qr->encode( (string) $text, $level ) ? $qr->modules : null;
	}

	/**
	 * Encode into this instance.
	 *
	 * @param string $text  Text.
	 * @param string $level Level.
	 * @return bool
	 */
	protected function encode( $text, $level ) {
		$bytes = array_values( unpack( 'C*', $text ) ? unpack( 'C*', $text ) : array() );
		$len   = count( $bytes );

		$version = 0;
		for ( $v = 1; $v <= 20; $v++ ) {
			$cc_bits  = $v <= 9 ? 8 : 16;
			$capacity = self::data_codewords( $v, $level ) * 8;
			if ( 4 + $cc_bits + 8 * $len <= $capacity ) {
				$version = $v;
				break;
			}
		}
		if ( ! $version ) {
			return false;
		}
		$this->version = $version;
		$this->size    = $version * 4 + 17;

		// Bit stream: mode 0100 (byte), character count, data.
		$bits = array();
		self::append_bits( $bits, 4, 4 );
		self::append_bits( $bits, $len, $version <= 9 ? 8 : 16 );
		foreach ( $bytes as $b ) {
			self::append_bits( $bits, $b, 8 );
		}
		$capacity = self::data_codewords( $version, $level ) * 8;
		self::append_bits( $bits, 0, min( 4, $capacity - count( $bits ) ) );
		self::append_bits( $bits, 0, ( 8 - count( $bits ) % 8 ) % 8 );
		for ( $pad = 0xEC; count( $bits ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
			self::append_bits( $bits, $pad, 8 );
		}
		$data = array();
		foreach ( array_chunk( $bits, 8 ) as $chunk ) {
			$byte = 0;
			foreach ( $chunk as $bit ) {
				$byte = ( $byte << 1 ) | $bit;
			}
			$data[] = $byte;
		}

		// Grid.
		$row               = array_fill( 0, $this->size, false );
		$this->modules     = array_fill( 0, $this->size, $row );
		$this->is_function = array_fill( 0, $this->size, $row );
		$this->draw_function_patterns( $level );
		$this->draw_codewords( $this->add_ecc_and_interleave( $data, $level ) );

		// Pick the mask with the lowest penalty.
		$best       = 0;
		$best_score = PHP_INT_MAX;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$this->apply_mask( $mask );
			$this->draw_format_bits( $level, $mask );
			$score = $this->penalty();
			if ( $score < $best_score ) {
				$best       = $mask;
				$best_score = $score;
			}
			$this->apply_mask( $mask ); // XOR again to undo.
		}
		$this->apply_mask( $best );
		$this->draw_format_bits( $level, $best );
		return true;
	}

	/**
	 * Append the low $count bits of $value, most significant first.
	 *
	 * @param int[] $bits  Bit list.
	 * @param int   $value Value.
	 * @param int   $count Bits.
	 */
	protected static function append_bits( array &$bits, $value, $count ) {
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$bits[] = ( $value >> $i ) & 1;
		}
	}

	/**
	 * Data modules available in a version (after function patterns).
	 *
	 * @param int $ver Version.
	 * @return int
	 */
	protected static function raw_modules( $ver ) {
		$result = ( 16 * $ver + 128 ) * $ver + 64;
		if ( $ver >= 2 ) {
			$align   = intdiv( $ver, 7 ) + 2;
			$result -= ( 25 * $align - 10 ) * $align - 55;
			if ( $ver >= 7 ) {
				$result -= 36;
			}
		}
		return $result;
	}

	/**
	 * Data codewords (excluding error correction) for a version and level.
	 *
	 * @param int    $ver   Version.
	 * @param string $level Level.
	 * @return int
	 */
	protected static function data_codewords( $ver, $level ) {
		return intdiv( self::raw_modules( $ver ), 8 ) - self::ECC_PER_BLOCK[ $level ][ $ver ] * self::NUM_BLOCKS[ $level ][ $ver ];
	}

	/**
	 * Set a function module.
	 *
	 * @param int  $x    Column.
	 * @param int  $y    Row.
	 * @param bool $dark Dark.
	 */
	protected function set_function( $x, $y, $dark ) {
		$this->modules[ $y ][ $x ]     = (bool) $dark;
		$this->is_function[ $y ][ $x ] = true;
	}

	/**
	 * Finder, timing and alignment patterns, plus reserved format/version areas.
	 *
	 * @param string $level Level.
	 */
	protected function draw_function_patterns( $level ) {
		$size = $this->size;
		for ( $i = 0; $i < $size; $i++ ) {
			$this->set_function( 6, $i, 0 === $i % 2 );
			$this->set_function( $i, 6, 0 === $i % 2 );
		}
		foreach ( array( array( 3, 3 ), array( $size - 4, 3 ), array( 3, $size - 4 ) ) as $c ) {
			for ( $dy = -4; $dy <= 4; $dy++ ) {
				for ( $dx = -4; $dx <= 4; $dx++ ) {
					$dist = max( abs( $dx ), abs( $dy ) );
					$xx   = $c[0] + $dx;
					$yy   = $c[1] + $dy;
					if ( $xx >= 0 && $xx < $size && $yy >= 0 && $yy < $size ) {
						$this->set_function( $xx, $yy, 2 !== $dist && 4 !== $dist );
					}
				}
			}
		}
		$pos   = $this->alignment_positions();
		$count = count( $pos );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = 0; $j < $count; $j++ ) {
				if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $count - 1 === $j ) || ( $count - 1 === $i && 0 === $j ) ) {
					continue;
				}
				for ( $dy = -2; $dy <= 2; $dy++ ) {
					for ( $dx = -2; $dx <= 2; $dx++ ) {
						$this->set_function( $pos[ $i ] + $dx, $pos[ $j ] + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
					}
				}
			}
		}
		$this->draw_format_bits( $level, 0 ); // Reserve; redrawn after masking.
		$this->draw_version();
	}

	/**
	 * Alignment pattern centre coordinates.
	 *
	 * @return int[]
	 */
	protected function alignment_positions() {
		$ver = $this->version;
		if ( 1 === $ver ) {
			return array();
		}
		$count  = intdiv( $ver, 7 ) + 2;
		$step   = intdiv( $ver * 4 + $count * 2 + 1, $count * 2 - 2 ) * 2;
		$result = array_fill( 0, $count, 0 );
		$result[0] = 6;
		for ( $i = $count - 1, $pos = $this->size - 7; $i >= 1; $i--, $pos -= $step ) {
			$result[ $i ] = $pos;
		}
		return $result;
	}

	/**
	 * Format bits (level + mask) in both copies.
	 *
	 * @param string $level Level.
	 * @param int    $mask  Mask 0–7.
	 */
	protected function draw_format_bits( $level, $mask ) {
		$data = ( self::FORMAT_BITS[ $level ] << 3 ) | $mask;
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
		$size = $this->size;
		$bit  = static function ( $i ) use ( $bits ) {
			return 1 === ( ( $bits >> $i ) & 1 );
		};

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function( 8, $i, $bit( $i ) );
		}
		$this->set_function( 8, 7, $bit( 6 ) );
		$this->set_function( 8, 8, $bit( 7 ) );
		$this->set_function( 7, 8, $bit( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$this->set_function( 14 - $i, 8, $bit( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$this->set_function( $size - 1 - $i, 8, $bit( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$this->set_function( 8, $size - 15 + $i, $bit( $i ) );
		}
		$this->set_function( 8, $size - 8, true );
	}

	/**
	 * Version information (versions 7+).
	 */
	protected function draw_version() {
		if ( $this->version < 7 ) {
			return;
		}
		$rem = $this->version;
		for ( $i = 0; $i < 12; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
		}
		$bits = ( $this->version << 12 ) | $rem;
		for ( $i = 0; $i < 18; $i++ ) {
			$dark = 1 === ( ( $bits >> $i ) & 1 );
			$a    = $this->size - 11 + $i % 3;
			$b    = intdiv( $i, 3 );
			$this->set_function( $a, $b, $dark );
			$this->set_function( $b, $a, $dark );
		}
	}

	/**
	 * Multiply in GF(256) modulo x^8 + x^4 + x^3 + x^2 + 1.
	 *
	 * @param int $x Byte.
	 * @param int $y Byte.
	 * @return int
	 */
	protected static function gf_mul( $x, $y ) {
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D ) ) & 0xFF;
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	/**
	 * Reed–Solomon generator polynomial of a degree.
	 *
	 * @param int $degree Degree.
	 * @return int[]
	 */
	protected static function rs_divisor( $degree ) {
		$result                = array_fill( 0, $degree, 0 );
		$result[ $degree - 1 ] = 1;
		$root                  = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::gf_mul( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::gf_mul( $root, 0x02 );
		}
		return $result;
	}

	/**
	 * Reed–Solomon remainder (the error correction codewords).
	 *
	 * @param int[] $data    Data codewords.
	 * @param int[] $divisor Generator.
	 * @return int[]
	 */
	protected static function rs_remainder( array $data, array $divisor ) {
		$result = array_fill( 0, count( $divisor ), 0 );
		foreach ( $data as $b ) {
			$factor   = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coef ) {
				$result[ $i ] ^= self::gf_mul( $coef, $factor );
			}
		}
		return $result;
	}

	/**
	 * Split into blocks, add error correction, interleave.
	 *
	 * @param int[]  $data  Data codewords.
	 * @param string $level Level.
	 * @return int[]
	 */
	protected function add_ecc_and_interleave( array $data, $level ) {
		$ver        = $this->version;
		$num_blocks = self::NUM_BLOCKS[ $level ][ $ver ];
		$ecc_len    = self::ECC_PER_BLOCK[ $level ][ $ver ];
		$raw        = intdiv( self::raw_modules( $ver ), 8 );
		$num_short  = $num_blocks - $raw % $num_blocks;
		$short_len  = intdiv( $raw, $num_blocks );
		$divisor    = self::rs_divisor( $ecc_len );

		$blocks = array();
		$k      = 0;
		for ( $i = 0; $i < $num_blocks; $i++ ) {
			$take = $short_len - $ecc_len + ( $i < $num_short ? 0 : 1 );
			$dat  = array_slice( $data, $k, $take );
			$k   += $take;
			$ecc  = self::rs_remainder( $dat, $divisor );
			if ( $i < $num_short ) {
				$dat[] = 0;
			}
			$blocks[] = array_merge( $dat, $ecc );
		}

		$result = array();
		$width  = count( $blocks[0] );
		for ( $i = 0; $i < $width; $i++ ) {
			foreach ( $blocks as $j => $block ) {
				if ( $i !== $short_len - $ecc_len || $j >= $num_short ) {
					$result[] = $block[ $i ];
				}
			}
		}
		return $result;
	}

	/**
	 * Place codewords in the zig-zag order.
	 *
	 * @param int[] $data Codewords.
	 */
	protected function draw_codewords( array $data ) {
		$size  = $this->size;
		$total = count( $data ) * 8;
		$i     = 0;
		for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
			if ( 6 === $right ) {
				$right = 5;
			}
			for ( $vert = 0; $vert < $size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x      = $right - $j;
					$upward = 0 === ( ( $right + 1 ) & 2 );
					$y      = $upward ? $size - 1 - $vert : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $i < $total ) {
						$this->modules[ $y ][ $x ] = 1 === ( ( $data[ $i >> 3 ] >> ( 7 - ( $i & 7 ) ) ) & 1 );
						++$i;
					}
				}
			}
		}
	}

	/**
	 * XOR a mask over the data modules.
	 *
	 * @param int $mask Mask 0–7.
	 */
	protected function apply_mask( $mask ) {
		$size = $this->size;
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $this->is_function[ $y ][ $x ] ) {
					continue;
				}
				switch ( $mask ) {
					case 0:
						$invert = 0 === ( $x + $y ) % 2;
						break;
					case 1:
						$invert = 0 === $y % 2;
						break;
					case 2:
						$invert = 0 === $x % 3;
						break;
					case 3:
						$invert = 0 === ( $x + $y ) % 3;
						break;
					case 4:
						$invert = 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
						break;
					case 5:
						$invert = 0 === ( $x * $y % 2 + $x * $y % 3 );
						break;
					case 6:
						$invert = 0 === ( $x * $y % 2 + $x * $y % 3 ) % 2;
						break;
					default:
						$invert = 0 === ( ( $x + $y ) % 2 + $x * $y % 3 ) % 2;
				}
				if ( $invert ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	/**
	 * Penalty score (rules 1, 2, 3 and 4 of the standard).
	 *
	 * @return int
	 */
	protected function penalty() {
		$size   = $this->size;
		$score  = 0;
		$dark   = 0;
		$lines  = array();
		for ( $y = 0; $y < $size; $y++ ) {
			$row = '';
			$col = '';
			for ( $x = 0; $x < $size; $x++ ) {
				$row .= $this->modules[ $y ][ $x ] ? '1' : '0';
				$col .= $this->modules[ $x ][ $y ] ? '1' : '0';
				if ( $this->modules[ $y ][ $x ] ) {
					++$dark;
				}
				// Rule 2: 2×2 blocks of one colour.
				if ( $x < $size - 1 && $y < $size - 1 ) {
					$c = $this->modules[ $y ][ $x ];
					if ( $c === $this->modules[ $y ][ $x + 1 ] && $c === $this->modules[ $y + 1 ][ $x ] && $c === $this->modules[ $y + 1 ][ $x + 1 ] ) {
						$score += 3;
					}
				}
			}
			$lines[] = $row;
			$lines[] = $col;
		}
		foreach ( $lines as $line ) {
			// Rule 1: runs of 5+ same-coloured modules.
			if ( preg_match_all( '/0{5,}|1{5,}/', $line, $m ) ) {
				foreach ( $m[0] as $run ) {
					$score += 3 + strlen( $run ) - 5;
				}
			}
			// Rule 3: finder-like 1011101 with four light modules on a side.
			$score += 40 * ( substr_count( $line, '00001011101' ) + substr_count( $line, '10111010000' ) );
		}
		// Rule 4: balance of dark and light.
		$total  = $size * $size;
		$k      = (int) ceil( abs( $dark * 20 - $total * 10 ) / $total ) - 1;
		$score += max( 0, $k ) * 10;
		return $score;
	}

	/**
	 * QR code as an SVG string (crisp at any size).
	 *
	 * @param string $text  Text.
	 * @param int    $px    Rendered width/height in CSS pixels.
	 * @param string $color Dark colour.
	 * @return string SVG markup (safe: only numbers and the colour).
	 */
	public static function svg( $text, $px = 200, $color = '#000000' ) {
		$m = self::matrix( $text );
		if ( ! $m ) {
			return '';
		}
		$quiet = 4;
		$n     = count( $m ) + $quiet * 2;
		$path  = '';
		foreach ( $m as $y => $row ) {
			$x   = 0;
			$len = count( $row );
			while ( $x < $len ) {
				if ( ! $row[ $x ] ) {
					++$x;
					continue;
				}
				$start = $x;
				while ( $x < $len && $row[ $x ] ) {
					++$x;
				}
				$path .= 'M' . ( $start + $quiet ) . ' ' . ( $y + $quiet ) . 'h' . ( $x - $start ) . 'v1h-' . ( $x - $start ) . 'z';
			}
		}
		$color = sanitize_hex_color( $color ) ? sanitize_hex_color( $color ) : '#000000';
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . (int) $px . '" height="' . (int) $px . '" shape-rendering="crispEdges" role="img"><rect width="100%" height="100%" fill="#ffffff"/><path fill="' . esc_attr( $color ) . '" d="' . $path . '"/></svg>';
	}

	/**
	 * QR code as PNG bytes (1-bit greyscale, zlib-compressed; no GD needed).
	 *
	 * @param string $text  Text.
	 * @param int    $scale Pixels per module.
	 * @return string PNG binary, or '' when it can't be encoded.
	 */
	public static function png( $text, $scale = 6 ) {
		$m = self::matrix( $text );
		if ( ! $m ) {
			return '';
		}
		$quiet = 4;
		$scale = max( 1, (int) $scale );
		$n     = count( $m ) + $quiet * 2;
		$px    = $n * $scale;
		$rows  = array();
		for ( $y = 0; $y < $n; $y++ ) {
			$bits = '';
			for ( $x = 0; $x < $n; $x++ ) {
				$mx    = $x - $quiet;
				$my    = $y - $quiet;
				$is    = $mx >= 0 && $my >= 0 && $mx < $n - $quiet * 2 && $my < $n - $quiet * 2 && $m[ $my ][ $mx ];
				$bits .= str_repeat( $is ? '0' : '1', $scale ); // 0 = black in greyscale.
			}
			$bits .= str_repeat( '1', ( 8 - strlen( $bits ) % 8 ) % 8 );
			$line  = "\0"; // Filter type: none.
			foreach ( str_split( $bits, 8 ) as $byte ) {
				$line .= chr( bindec( $byte ) );
			}
			for ( $s = 0; $s < $scale; $s++ ) {
				$rows[] = $line;
			}
		}
		return self::png_bytes( $px, $px, implode( '', $rows ) );
	}

	/**
	 * Assemble a 1-bit greyscale PNG.
	 *
	 * @param int    $w   Width.
	 * @param int    $h   Height.
	 * @param string $raw Filtered scanlines.
	 * @return string
	 */
	public static function png_bytes( $w, $h, $raw ) {
		$chunk = static function ( $type, $data ) {
			return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
		};
		return "\x89PNG\r\n\x1a\n"
			. $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 1, 0, 0, 0, 0 ) )
			. $chunk( 'IDAT', (string) gzcompress( $raw, 9 ) )
			. $chunk( 'IEND', '' );
	}
}
