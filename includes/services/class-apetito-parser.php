<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Parse an Apetito Nutridata page into a per-field required-or-report struct.
 *
 * PURE: no HTTP, no DB, no WP functions. Every field is either
 * ['ok'=>true,'value'=>...] or ['ok'=>false,'reason'=>...]. A parse miss is
 * NEVER a blank or a guess — the caller surfaces "could not read" and the
 * operator fills it in. This is the K10/K11 silent-blank failure mode, forbidden.
 *
 * Stable fields come from CSS class hooks (producttitle, product-cat,
 * product-code, alergenslist [one L], dietrycodings [misspelled]). Only
 * serving/pack/portions are heading-anchored text nodes — the fragile three.
 *
 * No mb_* anywhere: the local test CLI lacks mbstring. DOMDocument decodes
 * numeric HTML entities (&#xE9;) into UTF-8 text on load, given a charset hint.
 *
 * Class matching uses contains(concat(" ",normalize-space(@class)," ")," token ")
 * rather than @class="exact" so the parser is tolerant of extra classes (e.g.
 * the producttitle spans carry both a base class and a language token, and have
 * a style attribute — the exact-match form works today but the contains form
 * is defensive against a future class list addition).
 */
class MealsDB_Apetito_Parser {

	public static function parse( string $html, string $requested_code ): array {
		$doc = self::load( $html );
		$xp  = new DOMXPath( $doc );

		return [
			'code'   => $requested_code,
			'fields' => [
				'name_en'           => self::text_by_classes( $xp, [ 'producttitle', 'language_en' ] ),
				'name_fr'           => self::text_by_classes( $xp, [ 'producttitle', 'language_fr' ] ),
				'category'          => self::category( $xp, 0 ),
				'subcategory'       => self::category( $xp, 1 ),
				'code_on_page'      => self::code_on_page( $xp ),
				'allergens'         => self::list_items( $xp, 'alergenslist', 'Allergens' ),
				'diet_tags'         => self::list_items( $xp, 'dietrycodings', 'Diet Coding' ),
				'serving_size'      => self::after_heading( $xp, 'Serving Size', false ),
				'pack_size'         => self::after_heading( $xp, 'Pack Size', false ),
				'portions_per_case' => self::after_heading( $xp, 'Portions Per Case', true ),
			],
		];
	}

	private static function load( string $html ): DOMDocument {
		$doc = new DOMDocument();
		// Capture and RESTORE the internal-errors flag. Leaving it flipped to
		// true would silently suppress libxml warnings for every other XML/HTML
		// consumer in the same WP request (DOMPDF, simplexml, WC feeds).
		$prev = libxml_use_internal_errors( true );
		// Charset hint so loadHTML treats bytes as UTF-8 and decodes numeric
		// entities to proper UTF-8 (avoids the classic ISO-8859-1 mangling)
		// without any mb_* dependency.
		$doc->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $doc;
	}

	/**
	 * Trimmed text of the first element carrying ALL the given class tokens.
	 * Uses XPath contains() per-token so extra classes on the node don't break matching.
	 */
	private static function text_by_classes( DOMXPath $xp, array $tokens ): array {
		$conditions = array_map( function ( string $t ): string {
			return sprintf( 'contains(concat(" ",normalize-space(@class)," ")," %s ")', $t );
		}, $tokens );
		$query = sprintf( '//*[%s]', implode( ' and ', $conditions ) );
		$nodes = $xp->query( $query );
		if ( $nodes === false || $nodes->length === 0 ) {
			return [ 'ok' => false, 'reason' => sprintf( 'element with class(es) [%s] not found', implode( ', ', $tokens ) ) ];
		}
		$value = self::clean( $nodes->item( 0 )->textContent );
		if ( $value === '' ) {
			return [ 'ok' => false, 'reason' => sprintf( 'class(es) [%s] present but empty', implode( ', ', $tokens ) ) ];
		}
		return [ 'ok' => true, 'value' => $value ];
	}

	/** product-cat is "A | B"; $idx 0 => category, 1 => subcategory. */
	private static function category( DOMXPath $xp, int $idx ): array {
		$nodes = $xp->query( '//*[contains(concat(" ",normalize-space(@class)," ")," product-cat ")]' );
		if ( $nodes === false || $nodes->length === 0 ) {
			return [ 'ok' => false, 'reason' => 'product-cat not found' ];
		}
		$parts = array_map( [ self::class, 'clean' ], explode( '|', $nodes->item( 0 )->textContent ) );
		if ( ! isset( $parts[ $idx ] ) || $parts[ $idx ] === '' ) {
			return [ 'ok' => false, 'reason' => sprintf( 'product-cat segment %d missing', $idx ) ];
		}
		return [ 'ok' => true, 'value' => $parts[ $idx ] ];
	}

	/** product-code text is "Code: 12212" — strip the prefix. */
	private static function code_on_page( DOMXPath $xp ): array {
		$nodes = $xp->query( '//*[contains(concat(" ",normalize-space(@class)," ")," product-code ")]' );
		if ( $nodes === false || $nodes->length === 0 ) {
			return [ 'ok' => false, 'reason' => 'product-code not found' ];
		}
		$raw = self::clean( $nodes->item( 0 )->textContent );
		// `?? $raw`: preg_replace returns null on a PCRE failure (e.g. bad UTF-8);
		// keep the cleaned text rather than crashing trim() with null.
		$val = trim( preg_replace( '/^\s*Code:\s*/i', '', $raw ) ?? $raw );
		if ( $val === '' ) {
			return [ 'ok' => false, 'reason' => 'product-code present but empty' ];
		}
		return [ 'ok' => true, 'value' => $val ];
	}

	/** <li> texts inside a container class (allergens / diet tags). */
	private static function list_items( DOMXPath $xp, string $container_class, string $label ): array {
		// The allergen list uses class="alergenslist" on the outer div, and
		// diet tags use class="dietrycodings" on a ul directly — both work with
		// the contains() form since we want any element carrying that class token.
		$query = sprintf(
			'//*[contains(concat(" ",normalize-space(@class)," ")," %s ")]//li',
			$container_class
		);
		$lis = $xp->query( $query );
		if ( $lis === false || $lis->length === 0 ) {
			return [ 'ok' => false, 'reason' => sprintf( '%s list (.%s li) not found or empty', $label, $container_class ) ];
		}
		$out = [];
		foreach ( $lis as $li ) {
			$t = self::clean( $li->textContent );
			if ( $t !== '' ) {
				$out[] = $t;
			}
		}
		if ( empty( $out ) ) {
			return [ 'ok' => false, 'reason' => sprintf( '%s list had no readable items', $label ) ];
		}
		return [ 'ok' => true, 'value' => $out ];
	}

	/**
	 * The bare text node(s) following an <h3>{heading}</h3>, up to the next
	 * element. $numeric => cast to int and require a positive integer (used for
	 * portions_per_case, which feeds case_size and the pallet optimiser).
	 *
	 * Walk nextSibling from the <h3> rather than using following-sibling XPath,
	 * because the value is a raw text node between two block elements — not
	 * wrapped in anything — and XPath text() would require knowing the parent.
	 */
	private static function after_heading( DOMXPath $xp, string $heading, bool $numeric ): array {
		// Find the h3 whose trimmed text equals $heading exactly.
		$hs     = $xp->query( '//h3' );
		$target = null;
		foreach ( $hs as $h ) {
			if ( self::clean( $h->textContent ) === $heading ) {
				$target = $h;
				break;
			}
		}
		if ( $target === null ) {
			return [ 'ok' => false, 'reason' => sprintf( 'heading "%s" not found', $heading ) ];
		}
		// Collect following text nodes until the next element node.
		$buf = '';
		for ( $n = $target->nextSibling; $n !== null; $n = $n->nextSibling ) {
			if ( $n->nodeType === XML_ELEMENT_NODE ) {
				break;
			}
			if ( $n->nodeType === XML_TEXT_NODE ) {
				$buf .= $n->textContent;
			}
		}
		$val = self::clean( $buf );
		if ( $val === '' ) {
			return [ 'ok' => false, 'reason' => sprintf( 'no value after heading "%s"', $heading ) ];
		}
		if ( $numeric ) {
			if ( ! preg_match( '/^\d+$/', $val ) || (int) $val < 1 ) {
				return [ 'ok' => false, 'reason' => sprintf( 'value after "%s" is not a positive integer: %s', $heading, $val ) ];
			}
			return [ 'ok' => true, 'value' => (int) $val ];
		}
		return [ 'ok' => true, 'value' => $val ];
	}

	/** Collapse whitespace and decode residual named HTML entities. No mb_*. */
	private static function clean( string $s ): string {
		$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Use /u (unicode mode) for the whitespace collapse so multi-byte
		// characters in the text aren't split. preg_replace with /u does not
		// require mbstring — it uses PCRE's internal UTF-8 support.
		// `?? $s`: on a PCRE failure (invalid UTF-8 byte) preg_replace returns
		// null; keep the pre-collapse text so one bad byte loses only whitespace
		// normalisation rather than crashing trim() with null (TypeError on PHP 9).
		$s = preg_replace( '/\s+/u', ' ', $s ) ?? $s;
		return trim( $s );
	}
}
