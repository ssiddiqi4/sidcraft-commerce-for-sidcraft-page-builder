<?php
/**
 * A small PDF writer for tickets: text in the standard fonts, filled
 * rectangles, lines, links, JPEG images, and vector QR codes / barcodes.
 *
 * No library needed. Coordinates are in points from the top-left corner.
 * Text uses the PDF standard fonts with WinAnsi encoding, so Latin scripts
 * print correctly; characters outside Windows-1252 print as "?".
 *
 * @package SidcraftCommerce
 */

namespace SidcraftCommerce\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Class PDF
 */
class PDF {

	/**
	 * Pages: each [ w, h, content, annots[], images[] ].
	 *
	 * @var array[]
	 */
	protected $pages = array();

	/**
	 * Images: key => [ data, w, h, cs ].
	 *
	 * @var array
	 */
	protected $images = array();

	/**
	 * Document title.
	 *
	 * @var string
	 */
	protected $title = '';

	/**
	 * Helvetica glyph widths (1/1000 em) for ASCII 32–126.
	 *
	 * @var int[]
	 */
	const WIDTHS = array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 );

	/**
	 * Constructor.
	 *
	 * @param string $title Document title.
	 */
	public function __construct( $title = '' ) {
		$this->title = (string) $title;
	}

	/**
	 * Start a page (default A4).
	 *
	 * @param float $w Width in points.
	 * @param float $h Height in points.
	 */
	public function add_page( $w = 595.28, $h = 841.89 ) {
		$this->pages[] = array(
			'w'      => (float) $w,
			'h'      => (float) $h,
			'c'      => '',
			'annots' => array(),
			'images' => array(),
		);
	}

	/**
	 * Current page index.
	 *
	 * @return int
	 */
	protected function cur() {
		if ( ! $this->pages ) {
			$this->add_page();
		}
		return count( $this->pages ) - 1;
	}

	/**
	 * Append raw content to the current page.
	 *
	 * @param string $ops PDF operators.
	 */
	protected function out( $ops ) {
		$this->pages[ $this->cur() ]['c'] .= $ops . "\n";
	}

	/**
	 * Page height (to flip y).
	 *
	 * @return float
	 */
	protected function ph() {
		return $this->pages[ $this->cur() ]['h'];
	}

	/**
	 * Format a number.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	protected static function n( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Hex colour to "r g b" in 0–1.
	 *
	 * @param string $hex Colour.
	 * @return string
	 */
	protected static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			$hex = '000000';
		}
		return self::n( hexdec( substr( $hex, 0, 2 ) ) / 255 ) . ' ' . self::n( hexdec( substr( $hex, 2, 2 ) ) / 255 ) . ' ' . self::n( hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	/**
	 * UTF-8 to Windows-1252 for the standard fonts.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function encode( $s ) {
		$s = (string) $s;
		$s = str_replace( array( "\u{2009}", "\u{202F}", "\u{00A0}" ), ' ', $s );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$out = @mb_convert_encoding( $s, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unmappable characters only.
			if ( is_string( $out ) ) {
				return $out;
			}
		}
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'Windows-1252//TRANSLIT', $s ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $out ) {
				return $out;
			}
		}
		return (string) preg_replace( '/[^\x20-\x7E]/', '?', $s );
	}

	/**
	 * Escape for a PDF string literal (already encoded).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	protected static function esc( $s ) {
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', '', ' ' ), $s );
	}

	/**
	 * Width of text in points (Helvetica metrics; bold is ~6% wider; Courier fixed).
	 *
	 * @param string $s    UTF-8 text.
	 * @param float  $size Font size.
	 * @param string $font regular|bold|mono.
	 * @return float
	 */
	public function width( $s, $size, $font = 'regular' ) {
		$enc = self::encode( $s );
		if ( 'mono' === $font ) {
			return strlen( $enc ) * 600 * $size / 1000;
		}
		$w   = 0;
		$len = strlen( $enc );
		for ( $i = 0; $i < $len; $i++ ) {
			$c  = ord( $enc[ $i ] );
			$w += ( $c >= 32 && $c <= 126 ) ? self::WIDTHS[ $c - 32 ] : 556;
		}
		return $w * $size / 1000 * ( 'bold' === $font ? 1.06 : 1 );
	}

	/**
	 * Split text into lines that fit a width.
	 *
	 * @param string $s     Text.
	 * @param float  $size  Size.
	 * @param float  $max   Max width.
	 * @param string $font  Font.
	 * @param int    $limit Max lines (0 = any); the last line gets "…".
	 * @return string[]
	 */
	public function wrap( $s, $size, $max, $font = 'regular', $limit = 0 ) {
		$lines = array();
		foreach ( preg_split( '/\R/u', (string) $s ) as $para ) {
			$line = '';
			foreach ( preg_split( '/\s+/u', trim( $para ) ) as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( '' !== $line && $this->width( $try, $size, $font ) > $max ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $try;
				}
				// A single word longer than the line: hard-break it.
				while ( $this->width( $line, $size, $font ) > $max && mb_strlen( $line ) > 1 ) {
					$cut = mb_strlen( $line );
					while ( $cut > 1 && $this->width( mb_substr( $line, 0, $cut ), $size, $font ) > $max ) {
						--$cut;
					}
					$lines[] = mb_substr( $line, 0, $cut );
					$line    = mb_substr( $line, $cut );
				}
			}
			$lines[] = $line;
		}
		if ( $limit > 0 && count( $lines ) > $limit ) {
			$lines             = array_slice( $lines, 0, $limit );
			$lines[ $limit - 1 ] = rtrim( $lines[ $limit - 1 ] ) . '…';
		}
		return $lines;
	}

	/**
	 * Draw text with its baseline at y.
	 *
	 * @param float  $x     X.
	 * @param float  $y     Baseline Y from top.
	 * @param string $s     UTF-8 text.
	 * @param float  $size  Size.
	 * @param string $font  regular|bold|mono.
	 * @param string $color Hex.
	 * @param string $align left|right|center (x is the anchor).
	 */
	public function text( $x, $y, $s, $size = 11, $font = 'regular', $color = '#1a1a1f', $align = 'left' ) {
		if ( 'right' === $align ) {
			$x -= $this->width( $s, $size, $font );
		} elseif ( 'center' === $align ) {
			$x -= $this->width( $s, $size, $font ) / 2;
		}
		$f = 'bold' === $font ? 'F2' : ( 'mono' === $font ? 'F3' : 'F1' );
		$this->out( sprintf( 'BT /%s %s Tf %s rg %s %s Td (%s) Tj ET', $f, self::n( $size ), self::rgb( $color ), self::n( $x ), self::n( $this->ph() - $y ), self::esc( self::encode( $s ) ) ) );
	}

	/**
	 * Draw wrapped text; returns the y below the last line.
	 *
	 * @param float  $x       X.
	 * @param float  $y       First baseline.
	 * @param string $s       Text.
	 * @param float  $size    Size.
	 * @param float  $max     Max width.
	 * @param string $font    Font.
	 * @param string $color   Colour.
	 * @param float  $leading Line height factor.
	 * @param int    $limit   Max lines.
	 * @return float
	 */
	public function paragraph( $x, $y, $s, $size, $max, $font = 'regular', $color = '#1a1a1f', $leading = 1.35, $limit = 0 ) {
		foreach ( $this->wrap( $s, $size, $max, $font, $limit ) as $line ) {
			$this->text( $x, $y, $line, $size, $font, $color );
			$y += $size * $leading;
		}
		return $y;
	}

	/**
	 * Filled rectangle (top-left origin).
	 *
	 * @param float  $x     X.
	 * @param float  $y     Y.
	 * @param float  $w     W.
	 * @param float  $h     H.
	 * @param string $color Fill.
	 */
	public function rect( $x, $y, $w, $h, $color = '#000000' ) {
		$this->out( sprintf( '%s rg %s %s %s %s re f', self::rgb( $color ), self::n( $x ), self::n( $this->ph() - $y - $h ), self::n( $w ), self::n( $h ) ) );
	}

	/**
	 * Position in the current page's content (for rect_behind()).
	 *
	 * @return int
	 */
	public function mark() {
		return strlen( $this->pages[ $this->cur() ]['c'] );
	}

	/**
	 * Filled rectangle painted behind everything drawn after mark().
	 *
	 * @param int    $mark  From mark().
	 * @param float  $x     X.
	 * @param float  $y     Y.
	 * @param float  $w     W.
	 * @param float  $h     H.
	 * @param string $color Fill.
	 */
	public function rect_behind( $mark, $x, $y, $w, $h, $color ) {
		$i   = $this->cur();
		$ops = sprintf( "%s rg %s %s %s %s re f\n", self::rgb( $color ), self::n( $x ), self::n( $this->ph() - $y - $h ), self::n( $w ), self::n( $h ) );
		$this->pages[ $i ]['c'] = substr( $this->pages[ $i ]['c'], 0, $mark ) . $ops . substr( $this->pages[ $i ]['c'], $mark );
	}

	/**
	 * Line, optionally dashed.
	 *
	 * @param float  $x1     X1.
	 * @param float  $y1     Y1.
	 * @param float  $x2     X2.
	 * @param float  $y2     Y2.
	 * @param string $color  Colour.
	 * @param float  $width  Line width.
	 * @param bool   $dashed Dashed.
	 */
	public function line( $x1, $y1, $x2, $y2, $color = '#cccccc', $width = 1, $dashed = false ) {
		$this->out( sprintf( 'q %s RG %s w %s %s %s m %s %s l S Q', self::rgb( $color ), self::n( $width ), $dashed ? '[4 3] 0 d' : '[] 0 d', self::n( $x1 ), self::n( $this->ph() - $y1 ), self::n( $x2 ), self::n( $this->ph() - $y2 ) ) );
	}

	/**
	 * Clickable link area.
	 *
	 * @param float  $x   X.
	 * @param float  $y   Y (top).
	 * @param float  $w   W.
	 * @param float  $h   H.
	 * @param string $url URL.
	 */
	public function link( $x, $y, $w, $h, $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return;
		}
		$ph = $this->ph();
		$this->pages[ $this->cur() ]['annots'][] = sprintf( '<< /Type /Annot /Subtype /Link /Rect [%s %s %s %s] /Border [0 0 0] /A << /Type /Action /S /URI /URI (%s) >> >>', self::n( $x ), self::n( $ph - $y - $h ), self::n( $x + $w ), self::n( $ph - $y ), self::esc( $url ) );
	}

	/**
	 * Vector QR code.
	 *
	 * @param float  $x    X.
	 * @param float  $y    Y.
	 * @param float  $size Size (including quiet zone).
	 * @param string $text Content.
	 * @return bool
	 */
	public function qr( $x, $y, $size, $text ) {
		$m = QR::matrix( $text );
		if ( ! $m ) {
			return false;
		}
		$n   = count( $m ) + 8;
		$mod = $size / $n;
		$this->rect( $x, $y, $size, $size, '#ffffff' );
		$ops = '0 0 0 rg';
		foreach ( $m as $r => $row ) {
			$c   = 0;
			$len = count( $row );
			while ( $c < $len ) {
				if ( ! $row[ $c ] ) {
					++$c;
					continue;
				}
				$start = $c;
				while ( $c < $len && $row[ $c ] ) {
					++$c;
				}
				$ops .= sprintf( ' %s %s %s %s re', self::n( $x + ( $start + 4 ) * $mod ), self::n( $this->ph() - $y - ( $r + 5 ) * $mod ), self::n( ( $c - $start ) * $mod + 0.02 ), self::n( $mod + 0.02 ) );
			}
		}
		$this->out( $ops . ' f' );
		return true;
	}

	/**
	 * Vector Code 128 barcode.
	 *
	 * @param float  $x    X.
	 * @param float  $y    Y.
	 * @param float  $w    Width (bars only, quiet zone added outside).
	 * @param float  $h    Height.
	 * @param string $text ASCII content.
	 * @return bool
	 */
	public function barcode( $x, $y, $w, $h, $text ) {
		$runs = Barcode::runs( $text );
		if ( ! $runs ) {
			return false;
		}
		$mod = $w / array_sum( $runs );
		$cx  = $x;
		$ops = '0 0 0 rg';
		foreach ( $runs as $i => $run ) {
			if ( 0 === $i % 2 ) {
				$ops .= sprintf( ' %s %s %s %s re', self::n( $cx ), self::n( $this->ph() - $y - $h ), self::n( $run * $mod ), self::n( $h ) );
			}
			$cx += $run * $mod;
		}
		$this->out( $ops . ' f' );
		return true;
	}

	/**
	 * JPEG image, scaled to cover the box and clipped to it.
	 *
	 * @param float  $x    X.
	 * @param float  $y    Y.
	 * @param float  $w    W.
	 * @param float  $h    H.
	 * @param string $file Local JPEG path.
	 * @return bool
	 */
	public function jpeg( $x, $y, $w, $h, $file ) {
		$info = is_readable( $file ) ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
			return false;
		}
		$key = 'Im' . ( count( $this->images ) + 1 );
		$cs  = ( isset( $info['channels'] ) && 4 === (int) $info['channels'] ) ? 'DeviceCMYK' : ( ( isset( $info['channels'] ) && 1 === (int) $info['channels'] ) ? 'DeviceGray' : 'DeviceRGB' );
		$this->images[ $key ] = array(
			'data' => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local upload.
			'w'    => (int) $info[0],
			'h'    => (int) $info[1],
			'cs'   => $cs,
		);
		$this->pages[ $this->cur() ]['images'][] = $key;

		// Cover: scale to fill, centre, clip.
		$scale = max( $w / $info[0], $h / $info[1] );
		$dw    = $info[0] * $scale;
		$dh    = $info[1] * $scale;
		$dx    = $x + ( $w - $dw ) / 2;
		$dy    = $y + ( $h - $dh ) / 2;
		$ph    = $this->ph();
		$this->out( sprintf( 'q %s %s %s %s re W n %s 0 0 %s %s %s cm /%s Do Q', self::n( $x ), self::n( $ph - $y - $h ), self::n( $w ), self::n( $h ), self::n( $dw ), self::n( $dh ), self::n( $dx ), self::n( $ph - $dy - $dh ), $key ) );
		return true;
	}

	/**
	 * Build the PDF.
	 *
	 * @return string
	 */
	public function output() {
		if ( ! $this->pages ) {
			$this->add_page();
		}
		$objects = array();
		$add     = static function ( $body ) use ( &$objects ) {
			$objects[] = $body;
			return count( $objects );
		};

		$catalog = $add( '' ); // Filled in below.
		$pages   = $add( '' );
		$f1      = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );
		$f2      = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' );
		$f3      = $add( '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>' );

		$image_ids = array();
		foreach ( $this->images as $key => $img ) {
			$image_ids[ $key ] = $add( sprintf( "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode%s /Length %d >>\nstream\n%s\nendstream", $img['w'], $img['h'], $img['cs'], 'DeviceCMYK' === $img['cs'] ? ' /Decode [1 0 1 0 1 0 1 0]' : '', strlen( $img['data'] ), $img['data'] ) );
		}

		$kids = array();
		foreach ( $this->pages as $page ) {
			$stream  = function_exists( 'gzcompress' ) ? gzcompress( $page['c'] ) : $page['c'];
			$filter  = function_exists( 'gzcompress' ) ? ' /Filter /FlateDecode' : '';
			$content = $add( sprintf( "<< /Length %d%s >>\nstream\n%s\nendstream", strlen( $stream ), $filter, $stream ) );
			$xobj    = '';
			foreach ( array_unique( $page['images'] ) as $key ) {
				$xobj .= ' /' . $key . ' ' . $image_ids[ $key ] . ' 0 R';
			}
			$annots = $page['annots'] ? ' /Annots [' . implode( ' ', $page['annots'] ) . ']' : '';
			$kids[] = $add(
				sprintf(
					'<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R /F3 %d 0 R >>%s >> /Contents %d 0 R%s >>',
					$pages,
					self::n( $page['w'] ),
					self::n( $page['h'] ),
					$f1,
					$f2,
					$f3,
					'' !== $xobj ? ' /XObject <<' . $xobj . ' >>' : '',
					$content,
					$annots
				)
			);
		}
		$objects[ $catalog - 1 ] = sprintf( '<< /Type /Catalog /Pages %d 0 R >>', $pages );
		$objects[ $pages - 1 ]   = sprintf( '<< /Type /Pages /Kids [%s] /Count %d >>', implode( ' ', array_map( static function ( $id ) {
			return $id . ' 0 R';
		}, $kids ) ), count( $kids ) );
		$info = $add( sprintf( '<< /Title (%s) /Producer (Sidcraft Commerce) /CreationDate (D:%s) >>', self::esc( self::encode( $this->title ) ), gmdate( 'YmdHis' ) . 'Z' ) );

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $i => $body ) {
			$offsets[ $i + 1 ] = strlen( $pdf );
			$pdf              .= ( $i + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( "%010d 00000 n \n", $off );
		}
		$pdf .= sprintf( "trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", count( $objects ) + 1, $catalog, $info, $xref );
		return $pdf;
	}
}
