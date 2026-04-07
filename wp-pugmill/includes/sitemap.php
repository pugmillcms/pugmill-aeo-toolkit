<?php
/**
 * XML Sitemap — on-demand generation at /sitemap.xml.
 *
 * Design decisions (inspired by Rank Math's approach, implemented independently):
 *  - No static file written to disk — generated in-memory on request.
 *  - Respects WP Pugmill's own noindex flag and third-party SEO plugin noindex meta.
 *  - Covers all public, published post types.
 *  - Pings Google and Bing on post publish/update.
 *  - Appends a Sitemap: directive to the virtual robots.txt.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// Rewrite rule
// =========================================================================

/**
 * Register the /sitemap.xml rewrite rule.
 */
function wppugmill_sitemap_rewrite() {
	add_rewrite_rule( '^sitemap\.xml$', 'index.php?wppugmill_sitemap=1', 'top' );
	add_rewrite_tag( '%wppugmill_sitemap%', '([0-9]+)' );
}
add_action( 'init', 'wppugmill_sitemap_rewrite' );

// =========================================================================
// Serve the sitemap
// =========================================================================

/**
 * Intercept /sitemap.xml requests and output the XML document.
 */
function wppugmill_maybe_serve_sitemap() {
	if ( intval( get_query_var( 'wppugmill_sitemap' ) ) !== 1 ) {
		return;
	}

	// User has chosen to let another plugin handle the sitemap — step aside.
	if ( get_option( 'wppugmill_disable_sitemap' ) ) {
		return;
	}

	// Prevent WordPress redirect_canonical from 301-ing us away.
	remove_filter( 'template_redirect', 'redirect_canonical' );

	header( 'Content-Type: application/xml; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex, follow' );

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

	foreach ( wppugmill_sitemap_collect_urls() as $entry ) {
		echo "\t<url>\n";
		echo "\t\t<loc>" . esc_url( $entry['loc'] ) . "</loc>\n";
		echo "\t\t<lastmod>" . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
		echo "\t\t<changefreq>" . esc_html( $entry['changefreq'] ) . "</changefreq>\n";
		echo "\t\t<priority>" . esc_html( $entry['priority'] ) . "</priority>\n";
		if ( ! empty( $entry['markdown'] ) ) {
			echo "\t\t<xhtml:link rel=\"alternate\" type=\"text/markdown\" href=\"" . esc_url( $entry['markdown'] ) . "\"/>\n";
		}
		echo "\t</url>\n";
	}

	echo '</urlset>';
	exit;
}
add_action( 'template_redirect', 'wppugmill_maybe_serve_sitemap', 1 );

// =========================================================================
// URL collection
// =========================================================================

/**
 * Build the list of URLs to include in the sitemap.
 *
 * @return array<array{loc:string, lastmod:string, changefreq:string, priority:string}>
 */
function wppugmill_sitemap_collect_urls() {
	$urls = array();

	// Home page
	$urls[] = array(
		'loc'        => home_url( '/' ),
		'lastmod'    => wppugmill_sitemap_date( get_lastpostmodified( 'GMT' ) ),
		'changefreq' => 'daily',
		'priority'   => '1.0',
	);

	// llms.txt — AI content index; high priority so AI crawlers discover it via sitemap.
	if ( ! get_option( 'wppugmill_disable_llms_txt' ) ) {
		$urls[] = array(
			'loc'        => home_url( '/llms.txt' ),
			'lastmod'    => wppugmill_sitemap_date( get_lastpostmodified( 'GMT' ) ),
			'changefreq' => 'daily',
			'priority'   => '0.9',
		);
	}

	// All public, published post types
	$post_types = get_post_types( array( 'public' => true ), 'names' );

	foreach ( $post_types as $post_type ) {
		$posts = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1000, // Reasonable upper bound; large sites can filter with the hook below.
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		foreach ( $posts as $post ) {
			// Skip noindexed posts (own flag + third-party SEO plugins)
			if ( wppugmill_own_noindex( $post->ID ) || wppugmill_post_is_noindexed( $post->ID ) ) {
				continue;
			}

			$is_front  = ( (int) get_option( 'page_on_front' ) === $post->ID );
			$permalink = get_permalink( $post->ID );

			$urls[] = array(
				'loc'        => $permalink,
				'lastmod'    => wppugmill_sitemap_date( $post->post_modified_gmt ),
				'changefreq' => 'weekly',
				'priority'   => $is_front ? '1.0' : ( 'post' === $post_type ? '0.8' : '0.6' ),
				'markdown'   => add_query_arg( 'wppugmill_llm', '1', $permalink ),
			);
		}
	}

	/**
	 * Filter the sitemap URL entries before output.
	 *
	 * @param array $urls Array of URL entry arrays.
	 */
	return apply_filters( 'wppugmill/sitemap/urls', $urls );
}

/**
 * Normalise a date string to W3C format (YYYY-MM-DD) for sitemap lastmod.
 *
 * @param  string $date MySQL datetime string (UTC).
 * @return string
 */
function wppugmill_sitemap_date( $date ) {
	$ts = strtotime( $date );
	return $ts ? gmdate( 'Y-m-d', $ts ) : gmdate( 'Y-m-d' );
}

// =========================================================================
// Search engine pings
// =========================================================================

/**
 * Ping Google, Bing, and IndexNow when a post is published.
 *
 * @param int     $post_id
 * @param WP_Post $post
 * @param bool    $update
 */
function wppugmill_ping_search_engines( $post_id, $post, $update ) {
	// Don't ping with our sitemap URL if another plugin is handling the sitemap.
	if ( get_option( 'wppugmill_disable_sitemap' ) ) {
		return;
	}
	if ( 'publish' !== $post->post_status ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	// Throttle: one burst per 30 minutes to avoid flooding on bulk updates.
	$throttle_key = 'wppugmill_ping_throttle';
	if ( get_transient( $throttle_key ) ) {
		return;
	}
	set_transient( $throttle_key, 1, 30 * MINUTE_IN_SECONDS );

	$sitemap_url = home_url( '/sitemap.xml' );
	$post_url    = get_permalink( $post_id );

	// Google sitemap ping
	wp_remote_get(
		'https://www.google.com/ping?sitemap=' . rawurlencode( $sitemap_url ),
		array( 'timeout' => 5, 'blocking' => false )
	);

	// Bing sitemap ping
	wp_remote_get(
		'https://www.bing.com/ping?sitemap=' . rawurlencode( $sitemap_url ),
		array( 'timeout' => 5, 'blocking' => false )
	);

	// IndexNow — instant indexing notification for Bing/Microsoft ecosystem.
	$indexnow_key = wppugmill_indexnow_key();
	wp_remote_get(
		add_query_arg(
			array(
				'url' => rawurlencode( $post_url ),
				'key' => $indexnow_key,
			),
			'https://api.indexnow.org/indexnow'
		),
		array( 'timeout' => 5, 'blocking' => false )
	);
}
add_action( 'save_post', 'wppugmill_ping_search_engines', 10, 3 );

// =========================================================================
// IndexNow key management
// =========================================================================

/**
 * Return (and lazily generate) the site's IndexNow verification key.
 *
 * The key is a random 32-character hex string stored as a WordPress option.
 *
 * @return string
 */
function wppugmill_indexnow_key() {
	$key = get_option( 'wppugmill_indexnow_key', '' );
	if ( '' === $key ) {
		$key = bin2hex( random_bytes( 16 ) ); // 32 hex chars
		update_option( 'wppugmill_indexnow_key', $key, false );
	}
	return $key;
}

/**
 * Register the rewrite rule that serves the IndexNow key verification file.
 *
 * IndexNow requires a text file at /{key}.txt containing the key itself.
 */
function wppugmill_indexnow_rewrite() {
	$key = wppugmill_indexnow_key();
	add_rewrite_rule( '^' . preg_quote( $key, '/' ) . '\.txt$', 'index.php?wppugmill_indexnow=1', 'top' );
	add_rewrite_tag( '%wppugmill_indexnow%', '([0-9]+)' );
}
add_action( 'init', 'wppugmill_indexnow_rewrite' );

/**
 * Serve the IndexNow key file when requested.
 */
function wppugmill_serve_indexnow_key() {
	if ( intval( get_query_var( 'wppugmill_indexnow' ) ) !== 1 ) {
		return;
	}
	remove_filter( 'template_redirect', 'redirect_canonical' );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo esc_html( wppugmill_indexnow_key() );
	exit;
}
add_action( 'template_redirect', 'wppugmill_serve_indexnow_key', 1 );

// =========================================================================
// robots.txt integration
// =========================================================================

/**
 * Filter WordPress's virtual robots.txt.
 *
 * If the user has saved custom robots.txt content in Settings, that content
 * is used as the full replacement. Otherwise the WordPress default is kept
 * and a Sitemap: directive is appended when the site is public.
 *
 * @param  string $output  Existing robots.txt content from WordPress.
 * @param  bool   $public  Whether the site is set to public.
 * @return string
 */
function wppugmill_filter_robots_txt( $output, $public ) {
	$custom = trim( get_option( 'wppugmill_robots_txt_custom', '' ) );

	if ( $custom !== '' ) {
		// Full replacement — user is in control.
		return $custom;
	}

	// User has chosen not to let WP Pugmill append to robots.txt — leave it alone.
	if ( get_option( 'wppugmill_disable_robots_append' ) ) {
		return $output;
	}

	// Default: append Sitemap: and LLMs-Txt: directives when public.
	if ( $public ) {
		$sitemap_url = home_url( '/sitemap.xml' );
		if ( strpos( $output, $sitemap_url ) === false ) {
			$output .= "\nSitemap: " . $sitemap_url . "\n";
		}

		// AI content index — signals to AI crawlers that AEO-optimized content
		// is available. Bots reading robots.txt in full (ClaudeBot, GPTBot, etc.)
		// will discover llms.txt and from there the per-post ?wppugmill_llm=1 endpoints.
		$llms_url = home_url( '/llms.txt' );
		if ( strpos( $output, $llms_url ) === false ) {
			$output .= "\n# AI content index\nLLMs-Txt: " . $llms_url . "\n";
		}
	}

	return $output;
}
add_filter( 'robots_txt', 'wppugmill_filter_robots_txt', 10, 2 );
