<?php
/**
 * Cell content: cleaning, cell shortcuts and plain-text versions.
 *
 * A cell holds HTML, like a TablePress cell. Whoever wrote it, it goes
 * through wp_kses() with the tags allowed in posts every time it's shown,
 * so a cell can never carry a script, an iframe or an event handler, not
 * even one imported from TablePress or pasted by an administrator.
 *
 * Cell shortcuts are expanded after that, into markup this class builds
 * itself from validated values:
 *
 *   {yes} {no} {check} {cross}      A check mark or a cross (optional text after it)
 *   {stars 4.5}  {stars 8/10}       Star rating, out of 5 unless a scale is given
 *   {button Buy now | https://...}  Button link; add "| new", "| sponsored" or "| nofollow"
 *   {image 123}  {image 123 medium} Image from the media library, by attachment ID
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cell helpers.
 */
final class Responsive_Tables_Cells {

	/**
	 * Longest cell kept, in characters. Anything longer is cut.
	 */
	const MAX_LENGTH = 20000;

	/**
	 * Image sizes a {image} shortcut may ask for.
	 */
	const IMAGE_SIZES = array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' );

	/**
	 * Matches one cell shortcut. Arguments can't contain braces or line breaks.
	 */
	const TOKEN = '/\{(yes|no|check|cross|stars|button|image)(?:[ \t]+([^{}\r\n]*?))?[ \t]*\}/i';

	/**
	 * The HTML for one cell.
	 *
	 * @param string $raw        Cell content as stored.
	 * @param bool   $shortcodes Whether to run shortcodes in it.
	 * @return array{html:string,sort:string|null} Cell HTML, plus a sort value when the cell is a single shortcut (stars, yes/no).
	 */
	public static function render( $raw, $shortcodes ) {
		$raw = self::clip( $raw );

		if ( '' === trim( $raw ) ) {
			return array(
				'html' => '',
				'sort' => null,
			);
		}

		$html = wp_kses( $raw, self::allowed_html() );
		$sort = null;

		if ( false !== strpos( $html, '{' ) ) {
			$found = array();
			$parts = preg_split( '/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );

			// Only in text between tags: a shortcut inside an attribute
			// (title="{yes}") would break the attribute open.
			foreach ( $parts as $i => $part ) {
				if ( '' === $part || '<' === $part[0] || false === strpos( $part, '{' ) ) {
					continue;
				}

				$parts[ $i ] = (string) preg_replace_callback(
					self::TOKEN,
					function ( $match ) use ( &$found ) {
						return Responsive_Tables_Cells::token( $match, $found );
					},
					$part
				);
			}

			$html = implode( '', $parts );

			// A cell that's only a rating or a check mark sorts by its value.
			if ( 1 === count( $found ) && null !== $found[0] && '' === trim( (string) preg_replace( self::TOKEN, '', wp_strip_all_tags( wp_kses( $raw, array() ) ) ) ) ) {
				$sort = $found[0];
			}
		}

		if ( $shortcodes && false !== strpos( $html, '[' ) ) {
			$html = do_shortcode( $html );
		}

		if ( false !== strpos( $html, "\n" ) && ! preg_match( '#<(?:p|div|ul|ol|li|h[1-6]|table|blockquote|pre)[\s>]#i', $html ) ) {
			$html = nl2br( trim( $html ) );
		}

		return array(
			'html' => $html,
			'sort' => $sort,
		);
	}

	/**
	 * Plain text of a cell, for labels and aria-labels.
	 *
	 * @param string $raw Cell content as stored.
	 * @return string Unescaped text; escape it before printing.
	 */
	public static function text( $raw ) {
		$raw  = self::clip( $raw );
		$text = (string) preg_replace( self::TOKEN, ' ', $raw );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Tags allowed in cells: the same as in post content.
	 *
	 * @return array<string,mixed>
	 */
	public static function allowed_html() {
		/**
		 * Filters the HTML tags and attributes allowed in table cells.
		 *
		 * @param array $allowed Defaults to the tags allowed in posts (wp_kses_allowed_html( 'post' )).
		 */
		return apply_filters( 'responsive_tables_cell_allowed_html', wp_kses_allowed_html( 'post' ) );
	}

	/**
	 * Expand one cell shortcut.
	 *
	 * Public only so the preg_replace_callback closure can reach it.
	 *
	 * @param string[]          $match Regex match: whole token, name, arguments.
	 * @param array<int,string> $found Sort values of the shortcuts found so far (by reference).
	 * @return string
	 */
	public static function token( $match, &$found ) {
		$name = strtolower( $match[1] );
		$args = isset( $match[2] ) ? trim( html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ) ) : '';

		switch ( $name ) {
			case 'yes':
			case 'check':
				$found[] = '1';
				return self::mark( true, $args );

			case 'no':
			case 'cross':
				$found[] = '0';
				return self::mark( false, $args );

			case 'stars':
				$rating = self::parse_rating( $args );
				if ( null === $rating ) {
					$found[] = null;
					return esc_html( $match[0] );
				}
				$found[] = (string) ( $rating['value'] / $rating['scale'] );
				return self::stars( $rating['value'], $rating['scale'] );

			case 'button':
				$found[] = null;
				return self::button( $args );

			case 'image':
				$found[] = null;
				return self::image( $args );
		}

		return esc_html( $match[0] );
	}

	/**
	 * A check mark or a cross, with optional text after it.
	 *
	 * @param bool   $yes  Check mark (true) or cross (false).
	 * @param string $text Text shown after the icon.
	 * @return string
	 */
	public static function mark( $yes, $text = '' ) {
		$word = $yes ? __( 'Yes', 'responsive-tables' ) : __( 'No', 'responsive-tables' );
		$path = $yes ? '<path d="M20 6 9 17l-5-5"/>' : '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>';
		$icon = '<svg class="rtbl-mark-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';

		if ( '' === $text ) {
			return '<span class="rtbl-mark ' . ( $yes ? 'rtbl-yes' : 'rtbl-no' ) . '" role="img" aria-label="' . esc_attr( $word ) . '">' . $icon . '</span>';
		}

		return '<span class="rtbl-mark ' . ( $yes ? 'rtbl-yes' : 'rtbl-no' ) . ' has-text">' . $icon . '<span class="rtbl-sr">' . esc_html( $word ) . ': </span><span class="rtbl-mark-text">' . esc_html( $text ) . '</span></span>';
	}

	/**
	 * Read "4.5", "4,5", "4.5/5" or "8/10".
	 *
	 * @param string $args Shortcut arguments.
	 * @return array{value:float,scale:int}|null
	 */
	public static function parse_rating( $args ) {
		if ( ! preg_match( '#^(\d{1,2}(?:[.,]\d{1,2})?)\s*(?:/\s*(\d{1,2}))?#', $args, $m ) ) {
			return null;
		}

		$scale = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 5;
		$scale = max( 1, min( 10, $scale ) );
		$value = (float) str_replace( ',', '.', $m[1] );

		return array(
			'value' => max( 0, min( (float) $scale, $value ) ),
			'scale' => $scale,
		);
	}

	/**
	 * Star rating: five stars filled to the value, and the number.
	 *
	 * @param float $value Rating.
	 * @param int   $scale Out of.
	 * @return string
	 */
	public static function stars( $value, $scale = 5 ) {
		$decimals = floor( $value ) === $value ? 0 : 1;
		$number   = number_format_i18n( $value, $decimals );
		$percent  = round( $value / $scale * 100, 1 );
		$label    = sprintf(
			/* translators: 1: rating, 2: highest possible rating. */
			__( 'Rated %1$s out of %2$s', 'responsive-tables' ),
			$number,
			number_format_i18n( $scale )
		);

		return '<span class="rtbl-stars" role="img" aria-label="' . esc_attr( $label ) . '">'
			. '<span class="rtbl-stars-bar" style="--rtbl-p:' . esc_attr( (string) $percent ) . '%"></span>'
			. '<span class="rtbl-stars-num">' . esc_html( 5 === $scale ? $number : $number . '/' . $scale ) . '</span>'
			. '</span>';
	}

	/**
	 * Button link: "Text | URL | flags".
	 *
	 * @param string $args Shortcut arguments.
	 * @return string
	 */
	public static function button( $args ) {
		$parts = array_map( 'trim', explode( '|', $args ) );
		$text  = isset( $parts[0] ) ? $parts[0] : '';
		$url   = isset( $parts[1] ) ? esc_url( $parts[1], array( 'http', 'https', 'mailto', 'tel' ) ) : '';
		$flags = isset( $parts[2] ) ? strtolower( implode( ' ', array_slice( $parts, 2 ) ) ) : '';

		if ( '' === $text ) {
			$text = __( 'Learn more', 'responsive-tables' );
		}

		if ( '' === $url ) {
			return '<span class="rtbl-button is-disabled">' . esc_html( $text ) . '</span>';
		}

		$rel    = array();
		$target = '';

		if ( false !== strpos( $flags, 'new' ) ) {
			$target = ' target="_blank"';
			$rel[]  = 'noopener';
		}
		if ( false !== strpos( $flags, 'sponsored' ) ) {
			$rel[] = 'sponsored';
		}
		if ( false !== strpos( $flags, 'nofollow' ) ) {
			$rel[] = 'nofollow';
		}

		return '<a class="rtbl-button" href="' . $url . '"' . $target . ( $rel ? ' rel="' . esc_attr( implode( ' ', $rel ) ) . '"' : '' ) . '>' . esc_html( $text ) . '</a>';
	}

	/**
	 * Image from the media library: "123" or "123 medium".
	 *
	 * @param string $args Shortcut arguments.
	 * @return string
	 */
	public static function image( $args ) {
		$parts = preg_split( '/\s+/', $args );
		$id    = isset( $parts[0] ) ? absint( $parts[0] ) : 0;
		$size  = isset( $parts[1] ) && in_array( $parts[1], self::IMAGE_SIZES, true ) ? $parts[1] : 'thumbnail';

		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return '';
		}

		return (string) wp_get_attachment_image(
			$id,
			$size,
			false,
			array(
				'class'   => 'rtbl-img',
				'loading' => 'lazy',
			)
		);
	}

	/**
	 * Cell content as a string, cut to the maximum length.
	 *
	 * @param mixed $raw Cell value.
	 * @return string
	 */
	public static function clip( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$raw = (string) $raw;

		if ( strlen( $raw ) > self::MAX_LENGTH ) {
			$raw = function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, self::MAX_LENGTH, 'UTF-8' ) : substr( $raw, 0, self::MAX_LENGTH );
		}

		return $raw;
	}
}
