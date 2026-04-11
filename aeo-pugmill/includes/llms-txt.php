<?php
/**
 * llms.txt — register endpoints for AI crawler discovery.
 *
 * Endpoints:
 *   /llms.txt                    — site-level index of all published content (cached)
 *   /llms-full.txt               — paginated full content with AEO metadata (cached per page)
 *   /llms-full.txt?page=2
 *   /any-post/?aeopugmill_llm=1   — per-post clean markdown view (no cache)
 *
 * Caching: WordPress transients, 1 hour TTL.
 * Cache is invalidated automatically when any post is saved or deleted.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AEOPUGMILL_LLMS_CACHE_TTL', HOUR_IN_SECONDS );
define( 'AEOPUGMILL_LLMS_FULL_PER_PAGE', 100 );

// -------------------------------------------------------------------------
// Noindex detection — respect SEO plugin exclusions
// -------------------------------------------------------------------------

/**
 * Check whether a post has been marked noindex by any known SEO plugin.
 *
 * Supports: Yoast SEO, Rank Math, SEOPress, All in One SEO.
 *
 * @param  int  $post_id
 * @return bool True if the post should be excluded from llms.txt output.
 */
function aeopugmill_post_is_noindexed( $post_id ) {
	// Yoast SEO — '1' means noindex
	if ( '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
		return true;
	}

	// Rank Math — serialized array may contain 'noindex'
	$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
	if ( ! empty( $rm_robots ) ) {
		$rm_robots = is_array( $rm_robots ) ? $rm_robots : (array) maybe_unserialize( $rm_robots );
		if ( in_array( 'noindex', $rm_robots, true ) ) {
			return true;
		}
	}

	// SEOPress — 'yes' stored in _seopress_robots_index means noindex is checked
	if ( 'yes' === get_post_meta( $post_id, '_seopress_robots_index', true ) ) {
		return true;
	}

	// All in One SEO
	if ( (int) get_post_meta( $post_id, '_aioseo_robots_noindex', true ) === 1 ) {
		return true;
	}

	// AEO Pugmill's own per-post noindex flag
	if ( function_exists( 'aeopugmill_own_noindex' ) && aeopugmill_own_noindex( $post_id ) ) {
		return true;
	}

	return false;
}

// -------------------------------------------------------------------------
// Rewrite rules + query vars
// -------------------------------------------------------------------------

function aeopugmill_llms_rewrite_rules() {
	add_rewrite_rule( '^llms\.txt$', 'index.php?aeopugmill_llms=1', 'top' );
	add_rewrite_rule( '^llms-full\.txt$', 'index.php?aeopugmill_llms_full=1', 'top' );
}
add_action( 'init', 'aeopugmill_llms_rewrite_rules' );

function aeopugmill_llms_query_vars( $vars ) {
	$vars[] = 'aeopugmill_llms';
	$vars[] = 'aeopugmill_llms_full';
	$vars[] = 'aeopugmill_llm';
	return $vars;
}
add_filter( 'query_vars', 'aeopugmill_llms_query_vars' );

// -------------------------------------------------------------------------
// Request handler
// -------------------------------------------------------------------------

function aeopugmill_llms_template_redirect() {
	// Disabled from Settings → Plugin Compatibility
	if ( get_option( 'aeopugmill_disable_llms_txt' ) ) {
		return;
	}

	if ( get_query_var( 'aeopugmill_llms' ) ) {
		aeopugmill_serve_llms_txt();
		exit;
	}

	if ( get_query_var( 'aeopugmill_llms_full' ) ) {
		aeopugmill_serve_llms_full_txt();
		exit;
	}

	if ( get_query_var( 'aeopugmill_llm' ) ) {
		if ( is_singular() ) {
			aeopugmill_serve_post_llm_txt();
			exit;
		}
		if ( is_home() || is_front_page() ) {
			aeopugmill_serve_site_llm_txt();
			exit;
		}
	}
}
add_action( 'template_redirect', 'aeopugmill_llms_template_redirect' );

// -------------------------------------------------------------------------
// Cache invalidation — bust on any post save or delete
// -------------------------------------------------------------------------

function aeopugmill_llms_invalidate_cache( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	delete_transient( 'aeopugmill_llms_txt' );

	// Bust all full-txt pages using stored page count
	$max_pages = (int) get_option( 'aeopugmill_llms_full_page_count', 1 );
	for ( $i = 1; $i <= max( $max_pages, 1 ); $i++ ) {
		delete_transient( "aeopugmill_llms_full_{$i}" );
	}
}
add_action( 'save_post', 'aeopugmill_llms_invalidate_cache' );
add_action( 'before_delete_post', 'aeopugmill_llms_invalidate_cache' );

/**
 * Clear the llms.txt conflict-check transient whenever plugin settings are saved,
 * so the Compatibility tab re-fetches /llms.txt and reflects any changes the user made.
 */
function aeopugmill_clear_llms_conflict_transient() {
	delete_transient( 'aeopugmill_llms_txt_conflict_check' );
}
add_action( 'update_option_aeopugmill_disable_llms_txt', 'aeopugmill_clear_llms_conflict_transient' );

// -------------------------------------------------------------------------
// /llms.txt
// -------------------------------------------------------------------------

function aeopugmill_serve_llms_txt() {
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: public, max-age=3600' );

	$cached = get_transient( 'aeopugmill_llms_txt' );
	if ( false !== $cached ) {
		echo (string) $cached;
		return;
	}

	$site_name    = get_bloginfo( 'name' );
	$site_url     = home_url();
	$site_summary = get_option( 'aeopugmill_site_summary', get_bloginfo( 'description' ) );

	/**
	 * Maximum number of posts included in /llms.txt.
	 *
	 * Defaults to 500 — enough for most sites without loading thousands
	 * of posts into memory at once. Override via filter if needed.
	 *
	 * @param int $limit
	 */
	$limit = (int) apply_filters( 'aeopugmill_llms_txt_limit', 500 );

	$posts = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => $limit,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );

	ob_start();

	echo "# {$site_name}\n\n";
	echo "> {$site_url}\n\n";

	if ( $site_summary ) {
		echo html_entity_decode( $site_summary, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . "\n\n";
	}

	$post_entries = array();
	$page_entries = array();

	foreach ( $posts as $post ) {
		if ( aeopugmill_post_is_noindexed( $post->ID ) ) {
			continue;
		}
		$aeo     = aeopugmill_get_aeo( $post->ID );
		$summary = ! empty( $aeo['summary'] )
			? $aeo['summary']
			: wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 20 );
		$summary = html_entity_decode( $summary, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url     = get_permalink( $post->ID );
		$title   = html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$llm_url = add_query_arg( 'aeopugmill_llm', '1', $url );
		$line    = "- [{$title}]({$url}): {$summary}\n  Markdown: {$llm_url}\n";

		if ( 'page' === $post->post_type ) {
			$page_entries[] = $line;
		} else {
			$post_entries[] = $line;
		}
	}

	if ( ! empty( $post_entries ) ) {
		echo "## Posts\n\n";
		foreach ( $post_entries as $line ) {
			echo $line;
		}
		echo "\n";
	}

	if ( ! empty( $page_entries ) ) {
		echo "## Pages\n\n";
		foreach ( $page_entries as $line ) {
			echo $line;
		}
		echo "\n";
	}

	echo "\n---\n";
	echo "Generated by AEO Pugmill — https://aeopugmill.com\n";

	$output = ob_get_clean();

	set_transient( 'aeopugmill_llms_txt', $output, AEOPUGMILL_LLMS_CACHE_TTL );

	echo $output;
}

// -------------------------------------------------------------------------
// /llms-full.txt (paginated, 100 posts per page)
// -------------------------------------------------------------------------

function aeopugmill_serve_llms_full_txt() {
	$page      = min( max( 1, absint( wp_unslash( $_GET['page'] ?? 1 ) ) ), 9999 );
	$cache_key = "aeopugmill_llms_full_{$page}";

	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: public, max-age=3600' );

	$cached = get_transient( $cache_key );
	if ( false !== $cached ) {
		echo (string) $cached;
		return;
	}

	$site_name = get_bloginfo( 'name' );
	$site_url  = home_url();

	$query = new WP_Query( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => AEOPUGMILL_LLMS_FULL_PER_PAGE,
		'paged'          => $page,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'no_found_rows'  => false,
	) );

	$total_pages = $query->max_num_pages;
	update_option( 'aeopugmill_llms_full_page_count', $total_pages, false );

	if ( $page > $total_pages && $total_pages > 0 ) {
		status_header( 404 );
		echo "# Not Found\n\nPage {$page} does not exist. Total pages: {$total_pages}\n";
		return;
	}

	ob_start();

	echo "# {$site_name} — Full Content Index\n\n";
	echo "> {$site_url}\n\n";
	echo "Page {$page} of {$total_pages}";

	if ( $page < $total_pages ) {
		$next_url = add_query_arg( 'page', $page + 1, home_url( '/llms-full.txt' ) );
		echo " — Next: {$next_url}";
	}

	echo "\n\n";

	foreach ( $query->posts as $post ) {
		if ( aeopugmill_post_is_noindexed( $post->ID ) ) {
			continue;
		}
		$aeo     = aeopugmill_get_aeo( $post->ID );
		$url     = get_permalink( $post->ID );
		$title   = html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$llm_url = add_query_arg( 'aeopugmill_llm', '1', $url );

		echo "---\n\n";
		echo "## {$title}\n";
		echo "URL: {$url}\n";
		echo "Markdown: {$llm_url}\n";
		echo "Published: {$post->post_date}\n";
		echo "Modified: {$post->post_modified}\n\n";

		if ( ! empty( $aeo['summary'] ) ) {
			echo "**Summary:** {$aeo['summary']}\n\n";
		}

		if ( ! empty( $aeo['questions'] ) ) {
			echo "### Q&A\n\n";
			foreach ( $aeo['questions'] as $qa ) {
				if ( ! empty( $qa['q'] ) && ! empty( $qa['a'] ) ) {
					echo "**Q: {$qa['q']}**\n{$qa['a']}\n\n";
				}
			}
		}

		if ( ! empty( $aeo['entities'] ) ) {
			echo "### Entities\n\n";
			foreach ( $aeo['entities'] as $entity ) {
				$desc = ! empty( $entity['description'] ) ? " — {$entity['description']}" : '';
				echo "- {$entity['name']} ({$entity['type']}){$desc}\n";
			}
			echo "\n";
		}

		if ( ! empty( $aeo['keywords'] ) ) {
			echo "**Keywords:** " . implode( ', ', $aeo['keywords'] ) . "\n\n";
		}

		$rendered = apply_filters( 'the_content', $post->post_content );
		echo aeopugmill_html_to_markdown( $rendered ) . "\n\n";
	}

	echo "---\n";
	echo "Generated by AEO Pugmill — https://aeopugmill.com\n";

	$output = ob_get_clean();

	set_transient( $cache_key, $output, AEOPUGMILL_LLMS_CACHE_TTL );

	echo $output;
}

// -------------------------------------------------------------------------
// Per-post ?aeopugmill_llm=1 — clean markdown view of a single post
// -------------------------------------------------------------------------

/**
 * Convert post HTML content to clean markdown text.
 *
 * Strips WordPress block comments, converts structural HTML (headings, lists,
 * bold, italic, links, code) to markdown equivalents, then strips all remaining
 * tags and decodes entities. No external library required.
 *
 * @param string $html Rendered post HTML.
 * @return string Clean markdown text.
 */
function aeopugmill_html_to_markdown( $html ) {
	// Strip WordPress block delimiter comments
	$md = preg_replace( '/<!--\s*\/?wp:[^>]*-->/i', '', $html );

	// Strip inline style attributes (block-library noise)
	$md = preg_replace( '/\s+style="[^"]*"/i', '', $md );

	// Convert headings to markdown
	foreach ( array( 6, 5, 4, 3, 2, 1 ) as $level ) {
		$hashes = str_repeat( '#', $level );
		$md     = preg_replace( '/<h' . $level . '[^>]*>(.*?)<\/h' . $level . '>/is', "\n\n{$hashes} $1\n\n", $md );
	}

	// Convert lists — items first, then containers
	$md = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "\n- $1", $md );
	$md = preg_replace( '/<\/[ou]l>/i', "\n", $md );
	$md = preg_replace( '/<[ou]l[^>]*>/i', "\n", $md );

	// Convert inline formatting
	$md = preg_replace( '/<strong[^>]*>(.*?)<\/strong>/is', '**$1**', $md );
	$md = preg_replace( '/<b[^>]*>(.*?)<\/b>/is', '**$1**', $md );
	$md = preg_replace( '/<em[^>]*>(.*?)<\/em>/is', '*$1*', $md );
	$md = preg_replace( '/<i[^>]*>(.*?)<\/i>/is', '*$1*', $md );
	$md = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $md );

	// Convert links — preserve href
	$md = preg_replace( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $md );

	// Paragraph and line breaks
	$md = preg_replace( '/<\/p>/i', "\n\n", $md );
	$md = preg_replace( '/<br\s*\/?>/i', "\n", $md );

	// Blockquotes
	$md = preg_replace( '/<blockquote[^>]*>(.*?)<\/blockquote>/is', "\n\n> $1\n\n", $md );

	// Strip all remaining HTML tags
	$md = wp_strip_all_tags( $md );

	// Decode HTML entities
	$md = html_entity_decode( $md, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	// Collapse 3+ blank lines to 2
	$md = preg_replace( '/\n{3,}/', "\n\n", trim( $md ) );

	return $md;
}

/**
 * Serve a clean markdown representation of a single post.
 *
 * URL: /any-post/?aeopugmill_llm=1
 * WordPress resolves the post normally via its permalink; template_redirect
 * intercepts before the theme renders when the query var is present.
 */
function aeopugmill_serve_post_llm_txt() {
	// get_queried_object_id() is reliable at template_redirect; get_the_ID()
	// reads $GLOBALS['post'] which may not be set yet at this point.
	$post_id = get_queried_object_id();
	$post    = $post_id ? get_post( $post_id ) : null;

	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );

	if ( ! $post || 'publish' !== $post->post_status ) {
		status_header( 404 );
		echo "# Not Found\n\nThis content is not available.\n";
		return;
	}

	if ( aeopugmill_post_is_noindexed( $post_id ) ) {
		status_header( 404 );
		echo "# Not Found\n\nThis content is not available.\n";
		return;
	}

	header( 'Cache-Control: public, max-age=3600' );

	$aeo   = aeopugmill_get_aeo( $post_id );
	$url   = get_permalink( $post_id );
	$title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

	echo "# {$title}\n\n";
	echo "URL: {$url}\n";
	echo "Published: {$post->post_date}\n";
	echo "Modified: {$post->post_modified}\n";

	$thumbnail_url = get_the_post_thumbnail_url( $post_id, 'large' );
	if ( $thumbnail_url ) {
		echo "Image: {$thumbnail_url}\n";
	}

	echo "\n";

	if ( ! empty( $aeo['summary'] ) ) {
		echo "## Summary\n\n";
		echo html_entity_decode( $aeo['summary'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . "\n\n";
	}

	if ( ! empty( $aeo['keywords'] ) ) {
		echo "**Keywords:** " . implode( ', ', $aeo['keywords'] ) . "\n\n";
	}

	if ( ! empty( $aeo['entities'] ) ) {
		echo "## Entities\n\n";
		foreach ( $aeo['entities'] as $entity ) {
			$desc = ! empty( $entity['description'] ) ? " — {$entity['description']}" : '';
			$type = ! empty( $entity['type'] ) ? " ({$entity['type']})" : '';
			echo "- {$entity['name']}{$type}{$desc}\n";
		}
		echo "\n";
	}

	$questions = array_filter( $aeo['questions'] ?? array(), function( $q ) {
		return ! empty( $q['q'] ) && ! empty( $q['a'] );
	} );
	if ( ! empty( $questions ) ) {
		echo "## Q&A\n\n";
		foreach ( $questions as $qa ) {
			echo "**Q: {$qa['q']}**\n{$qa['a']}\n\n";
		}
	}

	echo "## Content\n\n";
	$rendered = apply_filters( 'the_content', $post->post_content );
	echo aeopugmill_html_to_markdown( $rendered ) . "\n\n";

	echo "---\n";
	echo "Generated by AEO Pugmill — https://aeopugmill.com\n";
}

// -------------------------------------------------------------------------
// Home / front-page ?aeopugmill_llm=1 — site-level markdown summary
// -------------------------------------------------------------------------

/**
 * Serve a clean markdown summary of the site when ?aeopugmill_llm=1 is requested
 * on the home page or blog index.
 *
 * Outputs: site name, URL, description, and the 5 most recent published posts
 * with their titles and permalinks — giving AI crawlers an instant site index.
 *
 * URL: /?aeopugmill_llm=1
 */
function aeopugmill_serve_site_llm_txt() {
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Cache-Control: public, max-age=3600' );

	$org_name    = get_option( 'aeopugmill_org_name', '' );
	$site_name   = $org_name ? $org_name : get_bloginfo( 'name' );
	$site_url    = home_url( '/' );
	$description = get_option( 'aeopugmill_site_summary', get_bloginfo( 'description' ) );

	echo "# " . html_entity_decode( $site_name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . "\n\n";
	echo "URL: {$site_url}\n\n";

	if ( $description ) {
		echo html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . "\n\n";
	}

	$recent = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => 5,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );

	if ( ! empty( $recent ) ) {
		echo "## Recent Content\n\n";
		foreach ( $recent as $post ) {
			if ( aeopugmill_post_is_noindexed( $post->ID ) ) {
				continue;
			}
			$title = html_entity_decode( get_the_title( $post->ID ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$url   = get_permalink( $post->ID );
			$aeo   = aeopugmill_get_aeo( $post->ID );
			echo "- [{$title}]({$url})";
			if ( ! empty( $aeo['summary'] ) ) {
				echo ': ' . html_entity_decode( $aeo['summary'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			echo "\n";
		}
		echo "\n";
	}

	echo "## Full Content Index\n\n";
	echo "- Full index: {$site_url}llms.txt\n";
	echo "- Full content: {$site_url}llms-full.txt\n\n";

	echo "---\n";
	echo "Generated by AEO Pugmill — https://aeopugmill.com\n";
}
