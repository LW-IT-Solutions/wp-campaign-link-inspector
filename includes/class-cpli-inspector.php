<?php
/**
 * Pure inspection helpers. Never resolve or fetch a supplied URL.
 *
 * @package CampaignLinkInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Campaign-link checks with explicit scope and bounded results. */
class CPLI_Inspector {
	/** Human-readable, translatable rule descriptions.
	 * @return array Rule map.
	 */
	public static function rules() {
		return array(
			'duplicate' => __( 'Repeated campaign parameter; receivers may interpret duplicates differently.', 'campaign-link-inspector' ),
			'empty'     => __( 'Empty campaign value.', 'campaign-link-inspector' ),
			'case'      => __( 'Campaign parameter name uses unexpected capitalization.', 'campaign-link-inspector' ),
			'valuecase' => __( 'Source or medium contains uppercase letters; review naming consistency.', 'campaign-link-inspector' ),
			'missing'   => __( 'Consider supplying source, medium and campaign together; one or more are absent.', 'campaign-link-inspector' ),
			'internal'  => __( 'Campaign parameters on a same-host or relative link: review whether this represents a new campaign interaction. GA4 does not start a new session merely because the campaign changes.', 'campaign-link-inspector' ),
			'space'     => __( 'Campaign value contains leading/trailing whitespace or control characters.', 'campaign-link-inspector' ),
			'encoding'  => __( 'Malformed percent encoding in the query string.', 'campaign-link-inspector' ),
			'fragment'  => __( 'Campaign-looking parameters appear in the fragment, not the query string.', 'campaign-link-inspector' ),
			'sensitive'=> __( 'Possible email address or sensitive parameter name. Review manually; this is not exhaustive detection.', 'campaign-link-inspector' ),
			'limit'    => __( 'This content item was only partly inspected because a documented safety limit was reached.', 'campaign-link-inspector' ),
		);
	}

	/** Short, translatable names of the checks for badges and the chart.
	 * @return array Rule map.
	 */
	public static function labels() {
		return array(
			'duplicate' => __( 'Repeated parameter', 'campaign-link-inspector' ),
			'empty'     => __( 'Empty value', 'campaign-link-inspector' ),
			'case'      => __( 'Parameter name case', 'campaign-link-inspector' ),
			'valuecase' => __( 'Uppercase source/medium', 'campaign-link-inspector' ),
			'missing'   => __( 'Incomplete set', 'campaign-link-inspector' ),
			'internal'  => __( 'Same-host link', 'campaign-link-inspector' ),
			'space'     => __( 'Whitespace', 'campaign-link-inspector' ),
			'encoding'  => __( 'Broken encoding', 'campaign-link-inspector' ),
			'fragment'  => __( 'Parameters in fragment', 'campaign-link-inspector' ),
			'sensitive' => __( 'Possible personal data', 'campaign-link-inspector' ),
			'limit'     => __( 'Partly inspected', 'campaign-link-inspector' ),
		);
	}

	/** Display group of each check: "check" (likely broken), "review" (naming) or "hint" (recommendation, scope).
	 * Only used for colors and order in the report; the checks themselves are unchanged.
	 * @return array Rule map.
	 */
	public static function kinds() {
		return array(
			'sensitive' => 'check',
			'encoding'  => 'check',
			'fragment'  => 'check',
			'duplicate' => 'check',
			'empty'     => 'check',
			'space'     => 'review',
			'case'      => 'review',
			'valuecase' => 'review',
			'internal'  => 'review',
			'missing'   => 'hint',
			'limit'     => 'hint',
		);
	}

	/** Inspect a single href.
	 * @param string $href Decoded HTML attribute.
	 * @param string $home Site base URL.
	 * @return array Rule identifiers.
	 */
	public static function link( $href, $home ) {
		$parts = wp_parse_url( trim( $href ) );
		if ( false === $parts || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) ) {
			return array();
		}
		$issues = array();
		$query  = isset( $parts['query'] ) ? $parts['query'] : '';
		$params = array();
		$utm    = false;
		if ( preg_match( '/(?:^|[?&])utm_[a-z_]+=/i', isset( $parts['fragment'] ) ? $parts['fragment'] : '' ) ) {
			$issues[] = 'fragment';
		}
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$pieces = explode( '=', $pair, 2 );
			$key    = urldecode( $pieces[0] );
			$value  = urldecode( isset( $pieces[1] ) ? $pieces[1] : '' );
			$lower  = strtolower( $key );
			if ( preg_match( '/(?:email|e-mail|password|passwd|token|secret|authorization|api[_-]?key)/i', $key ) || preg_match( '/[^\s@]+@[^\s@]+\.[^\s@]+/u', $value ) ) {
				$issues[] = 'sensitive';
			}
			if ( 0 !== strpos( $lower, 'utm_' ) ) {
				continue;
			}
			$utm = true;
			if ( array_key_exists( $lower, $params ) ) {
				$issues[] = 'duplicate';
			}
			$params[ $lower ] = $value;
			if ( $key !== $lower ) {
				$issues[] = 'case';
			}
			if ( '' === trim( $value ) ) {
				$issues[] = 'empty';
			}
			if ( $value !== trim( $value ) || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
				$issues[] = 'space';
			}
			if ( in_array( $lower, array( 'utm_source', 'utm_medium' ), true ) && ! preg_match( '/%(?![0-9a-f]{2})/i', $pair ) && preg_match( '/[A-Z]/', $value ) ) {
				$issues[] = 'valuecase';
			}
		}
		if ( $utm ) {
			if ( preg_match( '/%(?![0-9a-f]{2})/i', $query ) ) {
				$issues[] = 'encoding';
			}
			foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign' ) as $required ) {
				if ( ! isset( $params[ $required ] ) || '' === trim( $params[ $required ] ) ) {
					$issues[] = 'missing';
					break;
				}
			}
			$site_host = wp_parse_url( $home, PHP_URL_HOST );
			if ( empty( $parts['host'] ) || ( $site_host && 0 === strcasecmp( rtrim( $parts['host'], '.' ), rtrim( $site_host, '.' ) ) ) ) {
				$issues[] = 'internal';
			}
		}
		return array_values( array_unique( $issues ) );
	}

	/** Remove every query value, userinfo and fragment from the report URL.
	 * @param string $href Source URL.
	 * @return string Non-clickable display value; path may still be sensitive.
	 */
	public static function report_url( $href ) {
		$parts = wp_parse_url( trim( $href ) );
		if ( false === $parts ) {
			return '[unparseable URL]';
		}
		$out = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		if ( isset( $parts['host'] ) ) {
			$out .= ( '' === $out ? '//' : '' ) . $parts['host'];
			$out .= isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		}
		$out .= isset( $parts['path'] ) ? $parts['path'] : '';
		if ( isset( $parts['query'] ) ) {
			$out .= '?[values omitted]';
		}
		return $out;
	}

	/** Inspect stored HTML without rendering blocks or executing shortcodes.
	 * @param string $html Stored content.
	 * @param string $home Site URL.
	 * @return array Rows, link count and completeness.
	 */
	public static function content( $html, $home ) {
		$result = array( 'rows' => array(), 'links' => 0, 'limited' => false );
		if ( strlen( $html ) > 2 * 1024 * 1024 ) {
			$result['limited'] = true;
		} else {
			$processor = new WP_HTML_Tag_Processor( $html );
			while ( $processor->next_tag( 'A' ) ) {
				if ( $result['links'] >= 5000 || count( $result['rows'] ) >= 100 ) {
					$result['limited'] = true;
					break;
				}
				++$result['links'];
				$href = $processor->get_attribute( 'href' );
				if ( ! is_string( $href ) ) {
					continue;
				}
				$issues = self::link( $href, $home );
				if ( $issues ) {
					$result['rows'][] = array(
						'position' => $result['links'],
						'url'      => self::report_url( $href ),
						'codes'    => $issues,
						'messages' => array_values( array_intersect_key( self::rules(), array_flip( $issues ) ) ),
					);
				}
			}
		}
		if ( $result['limited'] ) {
			$rules            = self::rules();
			$result['rows'][] = array( 'position' => 0, 'url' => '', 'codes' => array( 'limit' ), 'messages' => array( $rules['limit'] ) );
		}
		return $result;
	}
}
