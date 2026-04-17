<?php
/**
 * AI Bot Analytics — detect, log, and query AI crawler visits.
 *
 * Schema v4 (single-table, opaque bot names):
 *
 *   {prefix}aeopugmill_bot_daily
 *     Aggregate table. One row per (day × bot_name × resource_type).
 *     Upserted on every bot visit at WP shutdown. Primary key prevents
 *     row bloat — max rows = bots × 11 × days_retained.
 *     Retained for 90 days.
 *
 * v4 vs v3 changes:
 *   - bot_id (tinyint) → bot_name (varchar(64)). Unknown bots are no longer
 *     collapsed into a single bot_id=0 row — every distinct UA-derived name
 *     gets its own row, preserving identity end-to-end into the network.
 *   - Dropped {prefix}aeopugmill_bot_recent — the recent-hits ring buffer was
 *     unused by the dashboard and added 7 days of single-row-per-visit growth.
 *   - Single shutdown hook for capture instead of init:99 + template_redirect:1.
 *     At shutdown $wp_query is fully populated, so is_singular() works for
 *     type 7 (AEO Post) detection in one place — no static state coordination.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AEOPUGMILL_BOT_DB_VERSION', '4' );

// ── Bot name normalization ───────────────────────────────────────────────────

/**
 * Canonical bot names recognised by aeopugmill_bot_fingerprints().
 *
 * Used by callers (admin dashboard, sender) that want to treat well-known
 * crawlers specially while still preserving every other captured name.
 *
 * @return string[]
 */
function aeopugmill_known_bot_names() {
	static $names = null;
	if ( null === $names ) {
		$names = array_keys( aeopugmill_bot_fingerprints() );
	}
	return $names;
}

/**
 * Normalize a captured bot name to a stable, varchar(64)-safe form.
 *
 * Trims whitespace, lowercases unknown bot strings (so "AhrefsBot" and
 * "ahrefs.com" don't both linger as separate rows after fingerprint matching),
 * and clamps length. Canonical names from aeopugmill_bot_fingerprints() are
 * passed through verbatim because they're already in their preferred form.
 *
 * @param  string $name
 * @return string
 */
function aeopugmill_normalize_bot_name( $name ) {
	$name = trim( (string) $name );
	if ( '' === $name ) {
		return 'unknown';
	}
	if ( in_array( $name, aeopugmill_known_bot_names(), true ) ) {
		return $name;
	}
	// Unknown bot: lowercase + strip control chars + clamp.
	$name = preg_replace( '/[^\x20-\x7e]/', '', $name );
	$name = strtolower( (string) $name );
	if ( strlen( $name ) > 64 ) {
		$name = substr( $name, 0, 64 );
	}
	return $name === '' ? 'unknown' : $name;
}

// ── Resource type map ─────────────────────────────────────────────────────────

/**
 * TINYINT resource ID → human label.
 *
 * 0 — Regular HTML page crawl (no AEO data)
 * 1 — /llms.txt                (AI discovery index)
 * 2 — /llms-full.txt           (paginated AEO content)
 * 3 — /{post}/?aeopugmill_llm=1 (per-post markdown)
 * 4 — /?aeopugmill_llm=1        (site summary markdown)
 * 5 — /sitemap.xml + /wp-sitemap-*.xml (crawl discovery)
 * 6 — /robots.txt              (crawl policy)
 * 7 — AEO Post HTML            (singular post with AEO metadata — FAQPage, entities, etc.)
 * 8 — /aeo/{slug}.jsonld       (standalone JSON-LD file with AEO-unique structured data)
 * 9 — /feed/ and sub-feeds     (RSS/Atom syndication)
 * 10 — /.well-known/*, /ads.txt, /security.txt, etc. (discovery/advertising files)
 *
 * @return array<int, string>
 */
function aeopugmill_resource_type_labels() {
	return array(
		0  => 'HTML Page',
		1  => 'llms.txt',
		2  => 'llms-full.txt',
		3  => 'Post Markdown',
		4  => 'Site Summary',
		5  => 'Sitemap',
		6  => 'Robots.txt',
		7  => 'AEO Post',
		8  => 'AEO JSON-LD',
		9  => 'RSS Feed',
		10 => 'Well-Known',
	);
}

/**
 * Resource type category — used for visual grouping in the dashboard.
 *
 * @return array<int, string>  'aeo' | 'discovery' | 'crawl'
 */
function aeopugmill_resource_type_categories() {
	return array(
		0  => 'crawl',      // HTML Page (plain)
		1  => 'aeo',        // llms.txt
		2  => 'aeo',        // llms-full.txt
		3  => 'aeo',        // Post Markdown
		4  => 'aeo',        // Site Summary
		5  => 'discovery',  // Sitemap
		6  => 'discovery',  // Robots.txt
		7  => 'aeo',        // HTML+AEO — crawl of an AEO-enriched post (secondary AEO signal)
		8  => 'aeo',        // AEO JSON-LD
		9  => 'aeo',        // RSS Feed — now carries AEO content (summary, entities, Q&A via xmlns:aeo)
		10 => 'discovery',  // Well-Known / ads.txt / security.txt — site metadata discovery
	);
}

// ── Bot fingerprints ──────────────────────────────────────────────────────────

/**
 * Map canonical bot names to their UA substrings.
 *
 * Order matters: more-specific entries (Google-Extended) must appear before
 * broader ones (Googlebot) so the correct bot is matched first.
 *
 * @return array<string, string[]>
 */
function aeopugmill_bot_fingerprints() {
	return array(
		// ── AI companies ─────────────────────────────────────────────────
		// Checked before search engines so Google-Extended beats Googlebot.
		'ChatGPT'    => array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot' ),
		'Claude'     => array( 'ClaudeBot', 'Claude-User', 'anthropic-ai' ),
		'Perplexity' => array( 'PerplexityBot', 'Perplexity-User' ),
		'Gemini'     => array( 'Google-Extended' ),
		'Amazonbot'  => array( 'Amazonbot', 'Amzn-User' ),
		'Meta'       => array( 'meta-externalagent', 'Meta-ExternalFetcher' ),
		'Cohere'     => array( 'cohere-ai', 'CohereAI', 'CohereBot' ),
		'DeepSeek'   => array( 'DeepSeekBot' ),
		'Grok'       => array( 'GrokBot', 'xAI-Grok', 'grok-' ),
		'CCBot'      => array( 'CCBot' ),
		'Mistral'    => array( 'MistralAI-User', 'MistralBot', 'mistralai' ),
		// ── Search engines ────────────────────────────────────────────────
		'GoogleOther' => array( 'GoogleOther' ),
		'Googlebot'   => array( 'Googlebot' ),
		'Bingbot'     => array( 'bingbot' ),
		'Applebot'    => array( 'Applebot-Extended', 'Applebot' ),
		'DuckDuckBot' => array( 'DuckDuckBot', 'DuckAssistBot' ),
		'Bytespider'  => array( 'Bytespider' ),
		'YandexBot'   => array( 'YandexBot', 'YaDirectFetcher' ),
		'BaiduBot'    => array( 'Baiduspider', 'BaiduSpider' ),
		// ── Commercial SEO / analytics bots ──────────────────────────────
		'SemrushBot'  => array( 'SemrushBot' ),
		'AhrefsBot'   => array( 'AhrefsBot' ),
		'DotBot'      => array( 'DotBot', 'dotbot' ),
		'MJ12bot'     => array( 'MJ12bot' ),
		'Barkrowler'  => array( 'Barkrowler' ),
		'AI2Bot'      => array( 'AI2Bot', 'Ai2Bot' ),
	);
}

/**
 * Detect if a UA string belongs to a known bot.
 *
 * @param  string       $ua
 * @return string|false  Canonical bot name, or false.
 */
function aeopugmill_detect_ai_bot( $ua ) {
	if ( empty( $ua ) ) {
		return false;
	}
	foreach ( aeopugmill_bot_fingerprints() as $bot => $needles ) {
		foreach ( $needles as $needle ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $bot;
			}
		}
	}
	return false;
}

/**
 * Parse a human-readable bot name from a User-Agent string.
 * Returns the leading token before '/', '(', or whitespace.
 *
 * @param  string $ua
 * @return string
 */
function aeopugmill_parse_bot_name_from_ua( $ua ) {
	// Prefer the domain from any embedded URL — most bots include their info page.
	// e.g. "AhrefsBot/7.0; +https://ahrefs.com/robot/" → "ahrefs.com"
	if ( preg_match( '/https?:\/\/(?:www\.)?([a-zA-Z0-9][a-zA-Z0-9\-]*\.[a-zA-Z]{2,})/i', $ua, $m ) ) {
		return strtolower( $m[1] );
	}
	// If the UA starts with "Mozilla/", the leading token is useless —
	// look inside the parenthetical for the real bot name.
	// e.g. "Mozilla/5.0 (compatible; XBot/1.0)" → "XBot"
	if ( 0 === stripos( $ua, 'Mozilla/' ) ) {
		if ( preg_match( '/\(compatible;\s*([A-Za-z][A-Za-z0-9_\-\.]{1,40})/i', $ua, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/\(\s*([A-Za-z][A-Za-z0-9_\-\.]{1,40})/', $ua, $m ) ) {
			return $m[1];
		}
	}
	// Fall back to the leading alphabetic token (e.g. "curl", "python-requests").
	if ( preg_match( '/^([A-Za-z][A-Za-z0-9_\-\.]{1,40})/', $ua, $m ) ) {
		return $m[1];
	}
	// Truly unidentifiable — return empty so the caller can show "Unknown N".
	return '';
}

/**
 * Detect whether an unrecognised UA string is a bot of some kind.
 *
 * Returns a parsed display name if the UA looks like a bot, false otherwise.
 * Browser-like UAs (Mozilla + Chrome/Safari/Firefox) are always ignored.
 *
 * @param  string       $ua
 * @return string|false  Parsed bot name, or false.
 */
function aeopugmill_detect_unknown_bot( $ua ) {
	if ( empty( $ua ) ) {
		return false;
	}

	// Skip browser-like UAs — they'll always dwarf real bots in volume.
	if ( false !== stripos( $ua, 'Mozilla/' ) ) {
		if ( false !== stripos( $ua, 'Chrome/' ) ||
			 false !== stripos( $ua, 'Safari/' ) ||
			 false !== stripos( $ua, 'Firefox/' ) ) {
			return false;
		}
	}

	// Bot signal keywords — any hit in the UA string = treat as a bot.
	static $signals = array(
		'bot', 'spider', 'crawl', 'fetch', 'scraper', 'checker',
		'scanner', 'monitor', 'wget', 'curl', 'python-requests',
		'python/', 'go-http-client', 'java/', 'okhttp', 'libwww',
		'Slurp', 'ia_archiver', 'archive.org_bot',
	);
	foreach ( $signals as $signal ) {
		if ( false !== stripos( $ua, $signal ) ) {
			return aeopugmill_parse_bot_name_from_ua( $ua );
		}
	}

	return false;
}

// ── Resource type detection ───────────────────────────────────────────────────

/**
 * Determine the resource type for the current request.
 *
 * Called at init priority 99, before WP query vars are fully resolved.
 * Uses REQUEST_URI (always available) and $_GET (real query params only).
 *
 * @return int  Resource type ID (see aeopugmill_resource_type_labels())
 */
function aeopugmill_detect_resource_type() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

	// ?aeopugmill_llm=1 is a real GET param (not a rewrite), available early.
	if ( isset( $_GET['aeopugmill_llm'] ) ) { // phpcs:ignore
		$request_path = rtrim( $path, '/' );
		$home_path    = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		return ( $request_path === $home_path ) ? 4 : 3;
	}

	// Clean-URL rewrites: visible in REQUEST_URI even before parse_request.
	if ( false !== strpos( $uri, 'llms-full.txt' ) ) {
		return 2;
	}
	if ( false !== strpos( $uri, 'llms.txt' ) ) {
		return 1;
	}
	// Match both /sitemap.xml and WordPress-core sitemap sub-files like
	// /wp-sitemap-posts-post-1.xml, /wp-sitemap-taxonomies-category-1.xml, etc.
	if ( preg_match( '#sitemap[^/]*\.xml#i', $path ) ) {
		return 5;
	}
	if ( false !== strpos( $uri, 'robots.txt' ) ) {
		return 6;
	}
	if ( preg_match( '#/aeo/[^/]+\.jsonld#', $uri ) ) {
		return 8;
	}
	// RSS/Atom feeds: /feed/, /feed, /tag/foo/feed/, /category/bar/feed/, etc.
	// Also covers ?feed=rss2 query-based requests.
	if ( preg_match( '#/feed/?$#', $path ) || isset( $_GET['feed'] ) ) { // phpcs:ignore
		return 9;
	}
	// Well-Known / discovery files: /.well-known/*, ads.txt family, trust.txt,
	// security.txt, apple-app-site-association, humans.txt.
	if ( preg_match( '#^/(\.well-known/|ads\.txt|app-ads\.txt|trust\.txt|security\.txt|apple-app-site-association|humans\.txt)#', $path ) ) {
		return 10;
	}

	return 0;
}

// ── DB table install / upgrade ────────────────────────────────────────────────

/**
 * Create or upgrade the analytics table.
 *
 * v4 schema: single bot_daily table keyed on (day, bot_name, resource_type).
 * Safe to call repeatedly (uses dbDelta).
 */
function aeopugmill_bot_analytics_install() {
	global $wpdb;

	$cc = $wpdb->get_charset_collate();

	// Daily aggregates — one row per (day × bot × resource_type).
	// Primary key is the natural deduplication key; upserts increment count.
	$daily_sql = "CREATE TABLE {$wpdb->prefix}aeopugmill_bot_daily (
		day           MEDIUMINT UNSIGNED NOT NULL,
		bot_name      VARCHAR(64) NOT NULL,
		resource_type TINYINT UNSIGNED NOT NULL DEFAULT 0,
		count         MEDIUMINT UNSIGNED NOT NULL DEFAULT 1,
		PRIMARY KEY (day, bot_name, resource_type),
		KEY bot_lookup (bot_name, day)
	) {$cc};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $daily_sql );

	update_option( 'aeopugmill_bot_db_version', AEOPUGMILL_BOT_DB_VERSION );
}

/**
 * Lazy-install / auto-upgrade check.
 *
 * v4 is a clean break from the bot_id (tinyint) schema — there is no in-place
 * migration. We DROP the legacy bot_daily and bot_recent tables and recreate
 * bot_daily with the new shape. Any retained network-side aggregates are
 * unaffected.
 */
function aeopugmill_bot_analytics_maybe_install() {
	$installed = get_option( 'aeopugmill_bot_db_version' );

	if ( $installed === AEOPUGMILL_BOT_DB_VERSION ) {
		return;
	}

	global $wpdb;

	// Hard reset the local capture tables on upgrade to v4. We're moving from
	// an int-keyed schema to a varchar-keyed one; rather than maintain a fragile
	// in-place migration we drop and recreate, since the network-side aggregate
	// is the source of truth for historical data.
	if ( null !== $installed && version_compare( (string) $installed, '4', '<' ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aeopugmill_bot_daily" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aeopugmill_bot_recent" );
		// Drop the long-removed v1 table too, in case it still lingers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aeopugmill_bot_visits" );
	}

	aeopugmill_bot_analytics_install();
}
add_action( 'plugins_loaded', 'aeopugmill_bot_analytics_maybe_install' );

// ── Logging ───────────────────────────────────────────────────────────────────

/**
 * Record one bot visit.
 *
 * Single upsert into bot_daily, keyed on (day, bot_name, resource_type). The
 * bot_name is stored verbatim — both well-known crawlers (e.g. "AhrefsBot")
 * and parsed unknown UAs (e.g. "mojeek.com") are kept distinct, never
 * collapsed into an "Other" bucket.
 *
 * @param string $bot_name      Bot name — already canonical or normalized.
 * @param int    $resource_type Resource type ID (see aeopugmill_resource_type_labels()).
 */
function aeopugmill_log_bot_visit( $bot_name, $resource_type = 0 ) {
	if ( ! get_option( 'aeopugmill_analytics_opted_in' ) ) {
		return;
	}

	$bot_name = aeopugmill_normalize_bot_name( $bot_name );
	if ( '' === $bot_name ) {
		return;
	}

	global $wpdb;
	$day = (int) floor( time() / DAY_IN_SECONDS );

	// Upsert aggregate row — primary key prevents duplicate rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}aeopugmill_bot_daily
		 (day, bot_name, resource_type, count)
		 VALUES (%d, %s, %d, 1)
		 ON DUPLICATE KEY UPDATE count = count + 1",
		$day,
		$bot_name,
		(int) $resource_type
	) );
}

/**
 * Single-shot bot capture at WP shutdown.
 *
 * shutdown is the right hook because:
 *  - $wp_query is fully populated, so is_singular() / get_queried_object_id()
 *    work correctly for AEO-post detection (type 7).
 *  - All non-admin frontend request paths reach shutdown — html, llms.txt,
 *    sitemap, markdown, jsonld, feeds — so a single capture point covers
 *    every resource type without dual-hook coordination.
 *  - Shutdown timing (after the response is flushed) means database writes
 *    here don't block the client, even on slow hosts.
 */
function aeopugmill_capture_bot_visit() {
	// Skip non-frontend contexts.
	if ( is_admin() ) {
		return;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}
	if ( ! get_option( 'aeopugmill_analytics_opted_in' ) ) {
		return;
	}

	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
	if ( '' === $ua ) {
		return;
	}

	// Resolve a bot name. Known crawlers go through fingerprint matching first;
	// otherwise we fall back to the heuristic UA parser. Browsers and other
	// human-driven UAs return false from both and never get logged.
	$bot_name = aeopugmill_detect_ai_bot( $ua );
	if ( false === $bot_name ) {
		$bot_name = aeopugmill_detect_unknown_bot( $ua );
		if ( false === $bot_name || '' === $bot_name ) {
			return;
		}
	}

	// Classify the request. URL-based detection covers everything except the
	// type 7 (AEO Post) check, which needs the resolved queried object.
	$resource_type = aeopugmill_detect_resource_type();
	if ( 0 === $resource_type && function_exists( 'is_singular' ) && is_singular() ) {
		$post_id = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
		if ( $post_id && function_exists( 'aeopugmill_get_aeo' ) ) {
			$aeo = aeopugmill_get_aeo( $post_id );
			if ( ! empty( $aeo['summary'] ) || ! empty( $aeo['questions'] ) || ! empty( $aeo['entities'] ) ) {
				$resource_type = 7;
			}
		}
	}

	aeopugmill_log_bot_visit( $bot_name, $resource_type );
}
add_action( 'shutdown', 'aeopugmill_capture_bot_visit', 1 );

// ── Pruning ───────────────────────────────────────────────────────────────────

/**
 * Prune old data. bot_daily is retained for 90 days.
 *
 * Scheduled daily via WP cron; also called on plugin activation.
 */
function aeopugmill_bot_analytics_prune() {
	global $wpdb;

	$oldest_day = (int) floor( time() / DAY_IN_SECONDS ) - 90;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}aeopugmill_bot_daily WHERE day < %d",
		$oldest_day
	) );
}

// ── Data queries ──────────────────────────────────────────────────────────────

/**
 * Total visits per bot over the last N days.
 *
 * @param  int $days
 * @return array<string, int>  bot_name => count
 */
function aeopugmill_bot_analytics_summary( $days = 30 ) {
	global $wpdb;

	$since = (int) floor( time() / DAY_IN_SECONDS ) - (int) $days;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT bot_name, SUM(count) AS cnt
			 FROM {$wpdb->prefix}aeopugmill_bot_daily
			 WHERE day >= %d
			 GROUP BY bot_name",
			$since
		),
		ARRAY_A
	);

	$result = array();
	foreach ( (array) $rows as $row ) {
		$result[ (string) $row['bot_name'] ] = (int) $row['cnt'];
	}
	return $result;
}

/**
 * Visit counts broken down by bot AND resource type over the last N days.
 *
 * Returns a 2-D array: $result[ bot_name ][ resource_type_id ] = count.
 *
 * @param  int $days
 * @return array<string, array<int, int>>
 */
function aeopugmill_bot_analytics_by_resource( $days = 30 ) {
	global $wpdb;

	$since = (int) floor( time() / DAY_IN_SECONDS ) - (int) $days;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT bot_name, resource_type, SUM(count) AS cnt
			 FROM {$wpdb->prefix}aeopugmill_bot_daily
			 WHERE day >= %d
			 GROUP BY bot_name, resource_type",
			$since
		),
		ARRAY_A
	);

	$result = array();
	foreach ( (array) $rows as $row ) {
		$bot  = (string) $row['bot_name'];
		$type = (int) $row['resource_type'];
		if ( ! isset( $result[ $bot ] ) ) {
			$result[ $bot ] = array();
		}
		$result[ $bot ][ $type ] = (int) $row['cnt'];
	}
	return $result;
}

/**
 * Daily visit counts per bot over the last N days.
 *
 * @param  int $days
 * @return array  Each entry: [ 'bot' => string, 'day' => 'YYYY-MM-DD', 'cnt' => int ]
 */
function aeopugmill_bot_analytics_daily( $days = 30 ) {
	global $wpdb;

	$since = (int) floor( time() / DAY_IN_SECONDS ) - (int) $days;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT bot_name, day, SUM(count) AS cnt
			 FROM {$wpdb->prefix}aeopugmill_bot_daily
			 WHERE day >= %d
			 GROUP BY bot_name, day
			 ORDER BY day ASC",
			$since
		),
		ARRAY_A
	);

	$result = array();
	foreach ( (array) $rows as $row ) {
		$result[] = array(
			'bot' => (string) $row['bot_name'],
			'day' => gmdate( 'Y-m-d', (int) $row['day'] * DAY_IN_SECONDS ),
			'cnt' => (int) $row['cnt'],
		);
	}
	return $result;
}

/**
 * All-time total visit count (sum of all aggregate rows).
 *
 * @return int
 */
function aeopugmill_bot_analytics_total() {
	global $wpdb;

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT SUM(count) FROM {$wpdb->prefix}aeopugmill_bot_daily"
	);
}

/**
 * Fetch the Pugmill network aggregate report, with caching.
 *
 * Single entry point for every caller that needs network-wide bot/coverage
 * averages. Uses two transients:
 *
 *   - `aeopugmill_network_report`        24-hour positive cache. The server
 *                                        aggregates once per day, so longer
 *                                        TTLs are lossless and dramatically
 *                                        cut outbound calls to aeopugmill.com.
 *   - `aeopugmill_network_report_failed` 5-minute negative cache. Prevents
 *                                        every admin page load from retrying
 *                                        while aeopugmill.com is unreachable.
 *
 * Returns the raw decoded API response so callers can read
 * `sites_contributing`, `last_30_days`, `categories`, `content_coverage`,
 * and `signals` directly. Returns false when no report is available.
 *
 * @return array|false
 */
function aeopugmill_get_network_report() {
	$cached = get_transient( 'aeopugmill_network_report' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	if ( ! get_option( 'aeopugmill_analytics_opted_in' ) ) {
		return false;
	}

	// Respect a recent failure to avoid thrashing aeopugmill.com during an outage.
	if ( get_transient( 'aeopugmill_network_report_failed' ) ) {
		return false;
	}

	$response = wp_remote_get(
		'https://aeopugmill.com/api/report',
		array( 'timeout' => 5, 'sslverify' => true )
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( 'aeopugmill_network_report_failed', 1, 5 * MINUTE_IN_SECONDS );
		return false;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		set_transient( 'aeopugmill_network_report_failed', 1, 5 * MINUTE_IN_SECONDS );
		return false;
	}

	set_transient( 'aeopugmill_network_report', $data, DAY_IN_SECONDS );
	return $data;
}

/**
 * Build a rich analytics context payload for the AI insights prompt.
 *
 * Includes: 30-day summary, AEO conversion rate, 15-day trend split,
 * network benchmark (if opted in), and top posts.
 *
 * @return array
 */
function aeopugmill_bot_analytics_insights_context() {
	global $wpdb;

	$days        = 30;
	$summary     = aeopugmill_bot_analytics_summary( $days );
	$by_resource = aeopugmill_bot_analytics_by_resource( $days );
	$total       = aeopugmill_bot_analytics_total();
	$labels      = aeopugmill_resource_type_labels();
	$daily       = aeopugmill_bot_analytics_daily( $days );

	// Flatten resource breakdown to bot → label → count.
	$reach = array();
	foreach ( $by_resource as $bot => $types ) {
		foreach ( $types as $type_id => $cnt ) {
			$reach[ $bot ][ $labels[ $type_id ] ?? 'Unknown' ] = $cnt;
		}
	}

	// ── AEO conversion rate ──────────────────────────────────────────────────
	// AEO endpoints: llms.txt (1), llms-full.txt (2), Post Markdown (3),
	// Site Summary (4), AEO JSON-LD (8). HTML+AEO (7) is a crawl, not an AEO
	// endpoint hit, so it is excluded from the conversion numerator.
	$aeo_types   = array( 1, 2, 3, 4, 8 );
	$total_30    = 0;
	$aeo_hits_30 = 0;
	foreach ( $by_resource as $types ) {
		foreach ( $types as $type_id => $cnt ) {
			$total_30 += $cnt;
			if ( in_array( $type_id, $aeo_types, true ) ) {
				$aeo_hits_30 += $cnt;
			}
		}
	}
	$aeo_conversion_pct = $total_30 > 0 ? round( $aeo_hits_30 / $total_30 * 100, 1 ) : 0.0;

	// ── Traffic trend: first 15 days vs last 15 days ─────────────────────────
	$now       = (int) floor( time() / DAY_IN_SECONDS );
	$split_day = $now - 15;
	$period1   = array(); // days -30 to -16
	$period2   = array(); // days -15 to now
	foreach ( $daily as $row ) {
		$row_day = (int) floor( strtotime( $row['day'] ) / DAY_IN_SECONDS );
		$bot     = $row['bot'];
		$cnt     = (int) $row['cnt'];
		if ( $row_day < $split_day ) {
			$period1[ $bot ] = ( $period1[ $bot ] ?? 0 ) + $cnt;
		} else {
			$period2[ $bot ] = ( $period2[ $bot ] ?? 0 ) + $cnt;
		}
	}
	$trends          = array();
	$all_trend_bots  = array_unique( array_merge( array_keys( $period1 ), array_keys( $period2 ) ) );
	foreach ( $all_trend_bots as $bot ) {
		$p1  = $period1[ $bot ] ?? 0;
		$p2  = $period2[ $bot ] ?? 0;
		$dir = $p2 > $p1 ? 'rising' : ( $p2 < $p1 ? 'falling' : 'flat' );
		$pct = $p1 > 0 ? (int) round( ( $p2 - $p1 ) / $p1 * 100 ) : ( $p2 > 0 ? 100 : 0 );
		$trends[ $bot ] = array(
			'first_15_days' => $p1,
			'last_15_days'  => $p2,
			'direction'     => $dir,
			'change_pct'    => $pct,
		);
	}

	// ── Local crawl intelligence signals ─────────────────────────────────────
	$local_signals = array();
	if ( function_exists( 'aeopugmill_intel_get_signals_30d' ) ) {
		$local_signals = aeopugmill_intel_get_signals_30d( 30 );
	}

	// ── Network benchmark ────────────────────────────────────────────────────
	// Reuse the cached report fetched by the Dashboard. aeopugmill_get_network_report()
	// handles the opted-in check, 24-hour positive cache, and short negative cache.
	$network_context = null;
	$net_data        = aeopugmill_get_network_report();
	if ( is_array( $net_data ) ) {
		$network_sites = (int) ( $net_data['sites_contributing'] ?? 0 );
		if ( $network_sites >= 1 && ! empty( $net_data['last_30_days'] ) ) {
				$benchmarks = array();
				$zero_bots  = array();
				foreach ( $net_data['last_30_days'] as $bot => $resources ) {
					$net_avg  = (int) round( array_sum( $resources ) / $network_sites );
					$my_count = $summary[ $bot ] ?? 0;
					if ( 0 === $my_count && $net_avg > 0 ) {
						$zero_bots[] = array( 'bot' => $bot, 'network_avg_per_site' => $net_avg );
					} else {
						$ratio  = $net_avg > 0 ? round( $my_count / $net_avg, 2 ) : null;
						$signal = null;
						if ( null !== $ratio ) {
							if ( $ratio >= 2.0 )      { $signal = 'well_above_average'; }
							elseif ( $ratio >= 1.1 )  { $signal = 'above_average'; }
							elseif ( $ratio >= 0.9 )  { $signal = 'at_average'; }
							elseif ( $ratio >= 0.5 )  { $signal = 'below_average'; }
							else                       { $signal = 'well_below_average'; }
						}
						$benchmarks[ $bot ] = array(
							'your_count'  => $my_count,
							'network_avg' => $net_avg,
							'ratio'       => $ratio,
							'signal'      => $signal,
						);
					}
				}

				// Network crawl intelligence signals (per-bot averages across contributing sites).
				$net_signals = array();
				if ( ! empty( $net_data['signals'] ) ) {
					foreach ( $net_data['signals'] as $bot => $metrics ) {
						if ( ! is_array( $metrics ) ) {
							continue;
						}
						foreach ( $metrics as $metric => $buckets ) {
							if ( ! is_array( $buckets ) ) {
								continue;
							}
							foreach ( $buckets as $bucket => $vals ) {
								if ( isset( $vals['tally_sum'], $vals['site_count'] ) && $vals['site_count'] > 0 ) {
									$net_signals[ $bot ][ $metric ][ $bucket ] = array(
										'network_avg_per_site' => round( $vals['tally_sum'] / $vals['site_count'], 1 ),
										'site_count'           => (int) $vals['site_count'],
									);
								}
							}
						}
					}
				}

			$network_context = array(
				'sites_in_network'  => $network_sites,
				'note'              => 'Pugmill AEO Intelligence Network: per-site 30-day averages',
				'benchmarks'        => $benchmarks,
				'zero_visit_bots'   => $zero_bots,
				'crawl_signals'     => $net_signals,
			);
		}
	}

	// ── AEO field coverage ──────────────────────────────────────────────────
	// How many published posts have each AEO field populated.
	$aeo_field_rows_ctx = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_aeopugmill_aeo'
		 AND LENGTH(meta_value) > 10",
		ARRAY_A
	);
	$ctx_field_summary   = 0;
	$ctx_field_questions = 0;
	$ctx_field_entities  = 0;
	$ctx_field_keywords  = 0;
	foreach ( (array) $aeo_field_rows_ctx as $ctx_aeo_row ) {
		$ctx_aeo = json_decode( $ctx_aeo_row['meta_value'], true );
		if ( ! is_array( $ctx_aeo ) ) { continue; }
		if ( ! empty( $ctx_aeo['summary'] ) )   { $ctx_field_summary++; }
		if ( ! empty( $ctx_aeo['questions'] ) ) { $ctx_field_questions++; }
		if ( ! empty( $ctx_aeo['entities'] ) )  { $ctx_field_entities++; }
		if ( ! empty( $ctx_aeo['keywords'] ) )  { $ctx_field_keywords++; }
	}
	$ctx_aeo_count  = max( $ctx_field_summary, $ctx_field_questions, $ctx_field_entities, $ctx_field_keywords );
	$ctx_posts_total = (int) wp_count_posts()->publish;

	$context = array(
		'site'               => get_bloginfo( 'name' ),
		'url'                => home_url(),
		'period_days'        => $days,
		'total_all_time'     => $total,
		'total_30_days'      => $total_30,
		'posts_total'        => $ctx_posts_total,
		'posts_with_aeo'     => $ctx_aeo_count,
		'aeo_field_coverage' => array(
			'ai_summary'   => $ctx_field_summary,
			'qa_pairs'     => $ctx_field_questions,
			'named_entities' => $ctx_field_entities,
			'keywords'     => $ctx_field_keywords,
		),
		'visits_by_bot'      => $summary,
		'aeo_conversion_pct' => $aeo_conversion_pct,
		'content_reach'      => $reach,
		'traffic_trend'      => $trends,
	);

	if ( ! empty( $local_signals ) ) {
		$context['crawl_signals'] = $local_signals;
	}

	if ( null !== $network_context ) {
		$context['network_benchmark'] = $network_context;
	}

	return $context;
}

// ── AJAX: AI insights ─────────────────────────────────────────────────────────

/**
 * Return (or generate and cache) an AI-written analysis of bot analytics.
 * Cached for 6 hours. Pass refresh=1 to bust the cache.
 */
function aeopugmill_ajax_analytics_insights() {
	check_ajax_referer( 'aeopugmill_analytics_insights', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'aeo-pugmill' ), 403 );
	}

	$provider = get_option( 'aeopugmill_ai_provider', 'anthropic' );
	$api_key  = aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error( __( 'No API key configured. Add your key in Settings → AEO Pugmill.', 'aeo-pugmill' ) );
	}

	$cache_key = 'aeopugmill_ai_analytics_insights';
	$refresh   = ! empty( $_POST['refresh'] );

	if ( ! $refresh ) {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}
	}

	$context  = aeopugmill_bot_analytics_insights_context();
	$ctx_json = wp_json_encode( $context, JSON_PRETTY_PRINT );

	$system = "You are an expert in AI search and Answer Engine Optimization (AEO). You receive bot traffic data from a WordPress site using the AEO Pugmill AEO plugin. Analyze the data and write a concise, insightful report in plain text — no markdown except the section headings below.

Structure your response with exactly these six section headings, each on its own line preceded by '## ':

## Bot Activity
Which bots are most active and what that signals (citation activity, indexing depth, content discovery phase). Inside content_reach, treat these resource types as strong positive AEO signals because they mean a bot is reading optimized content directly: llms.txt, llms-full.txt, Post Markdown, Site Summary, AEO JSON-LD, and RSS Feed (the feed now carries AEO summaries, entity tags, and Q&A pairs via the xmlns:aeo namespace — a bot hitting the feed is consuming AEO content). Treat the AEO Post type (HTML page with AEO metadata embedded) as a secondary positive signal. Plain HTML Page without AEO is neutral crawl traffic. Well-Known hits (robots.txt, .well-known/*, ads.txt, etc.) are pure discovery signals — the bot is probing site metadata, not reading content. Mention the AEO conversion percentage (share of visits that hit AEO endpoints — llms.txt, llms-full.txt, Post Markdown, Site Summary, AEO JSON-LD, RSS Feed, AEO Post — vs generic crawling) and whether it is healthy.

## Traffic Trend
Compare each bot's first 15 days versus last 15 days. Name which bots are rising, falling, or flat and what that implies. If a bot appears only in the second half, call it out as newly active — that is worth watching.

## Crawl Intelligence
If crawl_signals data is present in the site data: interpret what the bots are actually reading. Signal meanings — word_count buckets: <500 = short posts, 500-1500 = standard posts, 1500+ = long-form; content_freshness buckets: 0-7d = very fresh, 8-30d = recent, 31-180d = aging, 180d+ = stale; fact_density: high = lots of structured markup (tables, lists, headings), medium = some, low = mostly prose; url_depth: 0-1 = homepage/top-level, 2-3 = standard pages, 4+ = deep crawl; url_type: clean = SEO-friendly URLs, parameterized = query string URLs. Note dominant patterns per bot and what they imply about content preferences. If network_benchmark.crawl_signals is also present, compare this site to network averages — call out meaningful differences (e.g., bots here reading much fresher or longer content than network average). If no crawl_signals data is present, skip this section entirely.

## Network Benchmark
If network_benchmark data is present: for each bot, state whether this site is well above average, above average, at average, below average, or well below average compared to the Pugmill AEO Intelligence Network (the ratio field tells you: ≥ 2.0 = well above, ≥ 1.1 = above, 0.9–1.1 = at average, ≥ 0.5 = below, < 0.5 = well below). For every bot listed in zero_visit_bots (bots the network sees but this site has zero visits from), name them and say the typical site gets N visits — this is a gap. If no network_benchmark data is present, skip this section entirely.

## Content Coverage
Which resource types in content_reach are hit most, and any patterns worth noting. Compare the mix: heavy HTML Page hits with little AEO Post activity means bots are landing on non-AEO content — an optimization gap. Heavy AEO Post, RSS Feed, or AEO endpoint hits means bots are reaching enriched content. Call out ignored sections, repeat visits on specific posts, or bots that only touch pure discovery signals (Well-Known, Robots.txt, Sitemap) without reading any content.

## Recommendations
Give 3–5 specific, prioritized actions. Use aeo_field_coverage to identify gaps: if posts_with_aeo is much less than posts_total, recommend running Bulk AEO Generation; if qa_pairs is low relative to ai_summary, recommend adding Q&A pairs to existing posts; if named_entities or keywords lag behind ai_summary, call that out specifically. For any bot that is below average or a zero-visit gap, give a targeted fix. Where crawl_signals reveal a pattern, turn it into a recommendation (e.g., if bots are mostly reading short posts, recommend expanding key posts to 1500+ words; if freshness skews stale, recommend updating top posts). If content_reach shows high HTML Page hits but low AEO Post hits, the bots are crawling non-enriched pages — recommend extending AEO coverage to the specific posts they are landing on. Use this bot-specific guidance: ChatGPT — enrich llms.txt with Q&A pairs and AEO summaries, ChatGPT reads it directly; Perplexity — prioritize AEO summaries on high-traffic posts, Perplexity cites in real-time so freshness matters; ClaudeBot — keep sitemap current and add AEO markup to all posts; Gemini — Schema.org JSON-LD is key (watch AEO JSON-LD hits in content_reach); Bingbot — clean sitemaps and solid meta descriptions; if AEO conversion rate is under 10%, recommend running Bulk AEO Generation on top posts first. Only mention bots present in the data or identified as network gaps.

Rules: blank line between each heading and its paragraph. No bullet lists. 2–4 sentences per section. Total response under 550 words.";

	$user = "Site: " . get_bloginfo( 'name' ) . " (" . home_url() . ")\n\nBot analytics data:\n\n" . $ctx_json . "\n\nProvide your analysis.";

	$result = aeopugmill_call_ai( $provider, $api_key, $system, $user, 900 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}

	$payload = array(
		'text'      => wp_kses_post( trim( $result ) ),
		'generated' => time(),
	);

	set_transient( $cache_key, $payload, 6 * HOUR_IN_SECONDS );
	wp_send_json_success( $payload );
}
add_action( 'wp_ajax_aeopugmill_analytics_insights', 'aeopugmill_ajax_analytics_insights' );

// ── AJAX: CSV export ──────────────────────────────────────────────────────────

/**
 * Stream a CSV of daily aggregate data (all retained data, newest first).
 */
function aeopugmill_ajax_export_csv_daily() {
	check_ajax_referer( 'aeopugmill_export_csv', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'aeo-pugmill' ), 403 );
	}

	global $wpdb;

	$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT day, bot_name, resource_type, count
		 FROM {$wpdb->prefix}aeopugmill_bot_daily
		 ORDER BY day DESC, bot_name ASC",
		ARRAY_A
	);

	$labels = aeopugmill_resource_type_labels();

	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="aeopugmill-bot-daily-' . gmdate( 'Y-m-d' ) . '.csv"' );
	header( 'Pragma: no-cache' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Date', 'Bot', 'Resource Type', 'Count' ) );

	foreach ( $rows as $row ) {
		fputcsv( $out, array(
			gmdate( 'Y-m-d', (int) $row['day'] * DAY_IN_SECONDS ),
			(string) $row['bot_name'],
			$labels[ (int) $row['resource_type'] ] ?? 'Unknown',
			(int) $row['count'],
		) );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream for CSV download; WP_Filesystem does not handle output streams.
	fclose( $out );
	exit;
}
add_action( 'wp_ajax_aeopugmill_export_csv_daily', 'aeopugmill_ajax_export_csv_daily' );

// ── Pugmill Intelligence — registration & daily send ─────────────────────────

/**
 * Register this site with the Pugmill AEO Intelligence Network.
 *
 * Called once when the user opts in. Sends a signed registration request to
 * the Pugmill CMS server. On success, stores the returned network_token
 * (encrypted) so `aeopugmill_intelligence_send()` can authenticate each
 * daily submission.
 *
 * The HMAC proves this request came from real plugin code — the network secret
 * is baked into the plugin build and is not transmitted to the end-user's site.
 */
function aeopugmill_intelligence_register() {
	$network_secret = AEOPUGMILL_NETWORK_SECRET;

	$site_id     = hash( 'sha256', home_url() . aeopugmill_instance_id() );
	$opted_in_at = gmdate( 'c' );
	$nonce       = bin2hex( random_bytes( 16 ) );

	// Registration HMAC — proves the request originated from the real plugin.
	$reg_hmac = hash_hmac( 'sha256', "{$site_id}:{$opted_in_at}:{$nonce}", $network_secret );

	$response = wp_remote_post(
		'https://aeopugmill.com/api/ingest/register',
		array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $reg_hmac,
			),
			'body' => wp_json_encode( array(
				'site_id'        => $site_id,
				'opted_in_at'    => $opted_in_at,
				'nonce'          => $nonce,
				'plugin_version' => AEOPUGMILL_VERSION,
			) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response->get_error_message();
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== (int) $code ) {
		return 'HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['network_token'] ) ) {
		return 'No token in response: ' . wp_remote_retrieve_body( $response );
	}

	// Persist the token (encrypted) so it survives across cron runs.
	aeopugmill_save_encrypted_option( 'aeopugmill_network_token', $data['network_token'] );
	return true;
}

/**
 * Fire registration when the user opts in.
 * The option value transitions from 0 → 1 at opt-in.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $new_value New option value.
 */
function aeopugmill_on_analytics_opt_in( $old_value, $new_value ) {
	if ( (int) $new_value === 1 && (int) $old_value !== 1 ) {
		aeopugmill_intelligence_register(); // return value intentionally ignored
	}
}
add_action( 'update_option_aeopugmill_analytics_opted_in', 'aeopugmill_on_analytics_opt_in', 10, 2 );

// ── Pugmill Intelligence — daily send ─────────────────────────────────────────

/**
 * Resource type ID → slug for the intelligence network payload.
 *
 * @return array<int, string>
 */
function aeopugmill_intelligence_resource_slugs() {
	return array(
		0  => 'html',
		1  => 'llms_txt',
		2  => 'llms_full',
		3  => 'post_markdown',
		4  => 'site_summary',
		5  => 'sitemap',
		6  => 'robots_txt',
		7  => 'aeo_post',
		8  => 'aeo_jsonld',
		9  => 'rss_feed',
		10 => 'well_known',
	);
}

/**
 * Send yesterday's aggregated bot visit data to the Pugmill AEO Intelligence Network.
 * Fires daily via WP-Cron. Silent on failure — never affects the site.
 */
function aeopugmill_intelligence_send() {
	if ( ! get_option( 'aeopugmill_analytics_opted_in' ) ) {
		return;
	}

	global $wpdb;

	$yesterday     = (int) floor( ( time() - DAY_IN_SECONDS ) / DAY_IN_SECONDS );
	$resource_slugs = aeopugmill_intelligence_resource_slugs();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_name, resource_type, count
		 FROM {$wpdb->prefix}aeopugmill_bot_daily
		 WHERE day = %d",
		$yesterday
	), ARRAY_A );

	if ( empty( $rows ) ) {
		return;
	}

	// Build bots payload: { bot_name: { resource_slug: count } }.
	// Every distinct captured bot_name is preserved verbatim — no 'Other'
	// collapse, no whitelist filter. The network classifies on the server side.
	$bots = array();
	foreach ( $rows as $row ) {
		$bot_name = (string) $row['bot_name'];
		$resource = $resource_slugs[ (int) $row['resource_type'] ] ?? null;
		if ( ! $resource || '' === $bot_name ) {
			continue;
		}
		if ( ! isset( $bots[ $bot_name ] ) ) {
			$bots[ $bot_name ] = array();
		}
		$bots[ $bot_name ][ $resource ] = (int) $row['count'];
	}

	if ( empty( $bots ) ) {
		return;
	}

	// AEO tier: how complete is this site's AEO data?
	// 0 = no posts with AEO, 1 = 1-9 posts, 2 = 10+ posts
	$aeo_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_aeopugmill_aeo'
		 AND LENGTH(meta_value) > 50"
	);
	$aeo_tier = 0;
	if ( $aeo_count >= 10 ) {
		$aeo_tier = 2;
	} elseif ( $aeo_count >= 1 ) {
		$aeo_tier = 1;
	}

	// Per-field coverage counts for network intelligence.
	$aeo_field_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_aeopugmill_aeo'
		 AND LENGTH(meta_value) > 10",
		ARRAY_A
	);
	$field_summary         = 0;
	$field_summary_quality = 0;
	$field_questions       = 0;
	$field_questions_3plus = 0;
	$field_entities        = 0;
	$field_keywords        = 0;
	foreach ( (array) $aeo_field_rows as $aeo_field_row ) {
		$aeo_data = json_decode( $aeo_field_row['meta_value'], true );
		if ( ! is_array( $aeo_data ) ) { continue; }
		if ( ! empty( $aeo_data['summary'] ) ) {
			$field_summary++;
			if ( strlen( $aeo_data['summary'] ) >= 50 ) { $field_summary_quality++; }
		}
		if ( ! empty( $aeo_data['questions'] ) ) {
			$qa_count = is_array( $aeo_data['questions'] ) ? count( $aeo_data['questions'] ) : 0;
			$field_questions++;
			if ( $qa_count >= 3 ) { $field_questions_3plus++; }
		}
		if ( ! empty( $aeo_data['entities'] ) )  { $field_entities++; }
		if ( ! empty( $aeo_data['keywords'] ) )  { $field_keywords++; }
	}

	// Hash is salted with the site's private instance ID (stored only in their DB,
	// never transmitted). This prevents rainbow table attacks — even a full list of
	// known domains cannot reverse the hash without each site's unique UUID.
	$site_id       = hash( 'sha256', home_url() . aeopugmill_instance_id() );
	$date          = gmdate( 'Y-m-d', $yesterday * DAY_IN_SECONDS );
	$network_token = aeopugmill_get_encrypted_option( 'aeopugmill_network_token', '' );

	// If opted in but not yet registered (e.g. first run after activation), try now.
	if ( empty( $network_token ) ) {
		if ( aeopugmill_intelligence_register() ) {
			$network_token = aeopugmill_get_encrypted_option( 'aeopugmill_network_token', '' );
		}
	}

	// Cannot proceed without a token — site is not registered.
	if ( empty( $network_token ) ) {
		return;
	}

	// Submission HMAC — signs this specific day's payload with the site's token.
	$submission_hmac = hash_hmac( 'sha256', "{$site_id}:{$date}:" . AEOPUGMILL_VERSION, $network_token );

	// Per-bot crawl intelligence signals for yesterday.
	// Structure: { BotName: { metric: { bucket: tally } } }
	// Only included when the function exists (requires bot-intelligence.php v2+).
	$signals = array();
	if ( function_exists( 'aeopugmill_intel_get_signals_30d' ) ) {
		$raw_signals = aeopugmill_intel_get_signals_30d( 1 ); // yesterday only
		// Strip internal-only keys (e.g. '_site') before transmitting.
		// Cast every bucket map to (object) so json_encode always emits
		// {"0": 3, "1": 8} — never [3, 8]. PHP's json_encode collapses
		// arrays with contiguous int-like keys starting from 0 into JSON
		// arrays, which breaks the server-side jsonb_each processor.
		foreach ( $raw_signals as $bot_key => $bot_signals ) {
			if ( substr( $bot_key, 0, 1 ) === '_' ) {
				continue;
			}
			foreach ( $bot_signals as $metric => $buckets ) {
				if ( is_array( $buckets ) ) {
					$bot_signals[ $metric ] = (object) $buckets;
				}
			}
			$signals[ $bot_key ] = $bot_signals;
		}
	}

	// ── Schema v4 enrichment fields ───────────────────────────────────────
	// All optional / backward-compatible. Server ignores unknown keys from
	// older clients; older servers ignore new keys from newer clients.

	// Detected SEO plugin (slug or null).
	$seo_plugins     = function_exists( 'aeopugmill_detected_seo_plugins' ) ? aeopugmill_detected_seo_plugins() : array();
	$seo_plugin_slug = ! empty( $seo_plugins ) ? array_key_first( $seo_plugins ) : null;

	// Posts with full AEO data vs total published posts.
	$posts_with_aeo = $aeo_count; // already computed above
	$posts_total    = (int) wp_count_posts()->publish;

	// Bot visits to markdown endpoints yesterday (resource_type 3).
	$markdown_assets_served = 0;
	foreach ( $rows as $row ) {
		if ( (int) $row['resource_type'] === 3 ) {
			$markdown_assets_served += (int) $row['count'];
		}
	}

	// Which Pugmill output types are currently active (informational).
	$pugmill_outputs_active = function_exists( 'aeopugmill_active_outputs' ) ? aeopugmill_active_outputs() : array();

	$payload = array(
		'schema_ver'             => 4,
		'site_id'                => $site_id,
		'date'                   => $date,
		'plugin_version'         => AEOPUGMILL_VERSION,
		'aeo_tier'               => $aeo_tier,
		'seo_plugin'             => $seo_plugin_slug,
		'posts_with_aeo'         => $posts_with_aeo,
		'posts_total'            => $posts_total,
		'markdown_assets_served' => $markdown_assets_served,
		'pugmill_outputs_active' => $pugmill_outputs_active,
		'field_coverage'         => array(
			'summary'          => $field_summary,
			'summary_quality'  => $field_summary_quality,
			'questions'        => $field_questions,
			'questions_3plus'  => $field_questions_3plus,
			'entities'         => $field_entities,
			'keywords'         => $field_keywords,
		),
		'bots'                   => $bots,
		'network_token'          => $network_token,
	);

	if ( ! empty( $signals ) ) {
		$payload['signals'] = $signals;
	}

	/**
	 * Filter the intelligence network payload before transmission.
	 *
	 * @param array $payload   Payload array.
	 * @param int   $yesterday Unix-day integer for the reported day.
	 */
	$payload = apply_filters( 'aeopugmill_intelligence_payload', $payload, $yesterday );

	$response = wp_remote_post(
		'https://aeopugmill.com/api/ingest',
		array(
			'timeout'     => 10,
			'sslverify'   => true,
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $submission_hmac,
			),
			'body'        => wp_json_encode( $payload ),
			'blocking'    => true,
		)
	);

	// Record successful send timestamp for dashboard display.
	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		update_option( 'aeopugmill_last_network_send', time(), false );
	}

	// Auto-recover from stale or corrupted tokens: if the server returns 401,
	// re-register to get a fresh token and retry once.
	if ( ! is_wp_error( $response ) && 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
		if ( aeopugmill_intelligence_register() === true ) {
			$fresh_token = aeopugmill_get_encrypted_option( 'aeopugmill_network_token', '' );
			if ( ! empty( $fresh_token ) ) {
				$retry_hmac               = hash_hmac( 'sha256', "{$site_id}:{$date}:" . AEOPUGMILL_VERSION, $fresh_token );
				$payload['network_token'] = $fresh_token;

				wp_remote_post(
					'https://aeopugmill.com/api/ingest',
					array(
						'timeout'   => 10,
						'sslverify' => true,
						'headers'   => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $retry_hmac,
						),
						'body'      => wp_json_encode( $payload ),
						'blocking'  => false,
					)
				);
			}
		}
	}
}
add_action( 'aeopugmill_intelligence_send', 'aeopugmill_intelligence_send' );

/**
 * AJAX handler: manually trigger an intelligence send and return the server response.
 * Admin-only. Used by the "Send now" button on the Analytics tab for testing.
 */
function aeopugmill_ajax_manual_send() {
	check_ajax_referer( 'aeopugmill_manual_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'aeo-pugmill' ) );
	}

	if ( ! get_option( 'aeopugmill_analytics_opted_in' ) ) {
		wp_send_json_error( __( 'Not opted in to the Pugmill AEO Intelligence Network.', 'aeo-pugmill' ) );
	}

	global $wpdb;

	$yesterday      = (int) floor( ( time() - DAY_IN_SECONDS ) / DAY_IN_SECONDS );
	$resource_slugs = aeopugmill_intelligence_resource_slugs();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_name, resource_type, count
		 FROM {$wpdb->prefix}aeopugmill_bot_daily
		 WHERE day = %d",
		$yesterday
	), ARRAY_A );

	if ( empty( $rows ) ) {
		wp_send_json_error( __( 'No bot data recorded for yesterday — nothing to send.', 'aeo-pugmill' ) );
	}

	// Every distinct captured bot_name is preserved verbatim — no whitelist
	// filter, no 'Other' collapse. Server-side taxonomy classifies on receipt.
	$bots = array();
	foreach ( $rows as $row ) {
		$bot_name = (string) $row['bot_name'];
		$resource = $resource_slugs[ (int) $row['resource_type'] ] ?? null;
		if ( ! $resource || '' === $bot_name ) {
			continue;
		}
		if ( ! isset( $bots[ $bot_name ] ) ) {
			$bots[ $bot_name ] = array();
		}
		$bots[ $bot_name ][ $resource ] = (int) $row['count'];
	}

	if ( empty( $bots ) ) {
		wp_send_json_error( __( 'No recognised bot activity for yesterday.', 'aeo-pugmill' ) );
	}

	$aeo_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_aeopugmill_aeo'
		 AND LENGTH(meta_value) > 50"
	);
	$aeo_tier = 0;
	if ( $aeo_count >= 10 ) {
		$aeo_tier = 2;
	} elseif ( $aeo_count >= 1 ) {
		$aeo_tier = 1;
	}

	// Per-field coverage counts for network intelligence.
	$aeo_field_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_aeopugmill_aeo'
		 AND LENGTH(meta_value) > 10",
		ARRAY_A
	);
	$field_summary         = 0;
	$field_summary_quality = 0;
	$field_questions       = 0;
	$field_questions_3plus = 0;
	$field_entities        = 0;
	$field_keywords        = 0;
	foreach ( (array) $aeo_field_rows as $aeo_field_row ) {
		$aeo_data = json_decode( $aeo_field_row['meta_value'], true );
		if ( ! is_array( $aeo_data ) ) { continue; }
		if ( ! empty( $aeo_data['summary'] ) ) {
			$field_summary++;
			if ( strlen( $aeo_data['summary'] ) >= 50 ) { $field_summary_quality++; }
		}
		if ( ! empty( $aeo_data['questions'] ) ) {
			$qa_count = is_array( $aeo_data['questions'] ) ? count( $aeo_data['questions'] ) : 0;
			$field_questions++;
			if ( $qa_count >= 3 ) { $field_questions_3plus++; }
		}
		if ( ! empty( $aeo_data['entities'] ) )  { $field_entities++; }
		if ( ! empty( $aeo_data['keywords'] ) )  { $field_keywords++; }
	}

	$site_id       = hash( 'sha256', home_url() . aeopugmill_instance_id() );
	$date          = gmdate( 'Y-m-d', $yesterday * DAY_IN_SECONDS );
	$network_token = aeopugmill_get_encrypted_option( 'aeopugmill_network_token', '' );

	if ( empty( $network_token ) ) {
		$reg_result = aeopugmill_intelligence_register();
		if ( true === $reg_result || ( is_string( $reg_result ) && empty( $reg_result ) ) ) {
			$network_token = aeopugmill_get_encrypted_option( 'aeopugmill_network_token', '' );
		} else {
			wp_send_json_error( 'Registration failed: ' . ( is_string( $reg_result ) ? $reg_result : 'unknown error' ) );
		}
	}

	if ( empty( $network_token ) ) {
		wp_send_json_error( __( 'Registration failed — token missing after register. Check your connection to aeopugmill.com.', 'aeo-pugmill' ) );
	}

	$submission_hmac = hash_hmac( 'sha256', "{$site_id}:{$date}:" . AEOPUGMILL_VERSION, $network_token );

	$signals = array();
	if ( function_exists( 'aeopugmill_intel_get_signals_30d' ) ) {
		$raw_signals = aeopugmill_intel_get_signals_30d( 1 );
		foreach ( $raw_signals as $bot_key => $bot_signals ) {
			if ( substr( $bot_key, 0, 1 ) === '_' ) {
				continue;
			}
			$signals[ $bot_key ] = $bot_signals;
		}
	}

	// Schema v3 enrichment (mirrors aeopugmill_intelligence_send()).
	$seo_plugins_ajax     = function_exists( 'aeopugmill_detected_seo_plugins' ) ? aeopugmill_detected_seo_plugins() : array();
	$seo_plugin_slug_ajax = ! empty( $seo_plugins_ajax ) ? array_key_first( $seo_plugins_ajax ) : null;
	$posts_total_ajax     = (int) wp_count_posts()->publish;
	$markdown_ajax        = 0;
	foreach ( $rows as $row ) {
		if ( (int) $row['resource_type'] === 3 ) {
			$markdown_ajax += (int) $row['count'];
		}
	}
	$pugmill_outputs_ajax = function_exists( 'aeopugmill_active_outputs' ) ? aeopugmill_active_outputs() : array();

	$payload = array(
		'schema_ver'             => 4,
		'site_id'                => $site_id,
		'date'                   => $date,
		'plugin_version'         => AEOPUGMILL_VERSION,
		'aeo_tier'               => $aeo_tier,
		'seo_plugin'             => $seo_plugin_slug_ajax,
		'posts_with_aeo'         => $aeo_count,
		'posts_total'            => $posts_total_ajax,
		'markdown_assets_served' => $markdown_ajax,
		'pugmill_outputs_active' => $pugmill_outputs_ajax,
		'field_coverage'         => array(
			'summary'          => $field_summary,
			'summary_quality'  => $field_summary_quality,
			'questions'        => $field_questions,
			'questions_3plus'  => $field_questions_3plus,
			'entities'         => $field_entities,
			'keywords'         => $field_keywords,
		),
		'bots'                   => $bots,
		'network_token'          => $network_token,
	);

	if ( ! empty( $signals ) ) {
		$payload['signals'] = $signals;
	}

	/** This filter is documented in aeopugmill_intelligence_send(). */
	$payload = apply_filters( 'aeopugmill_intelligence_payload', $payload, $yesterday );

	// Blocking so we can report success/failure back to the UI.
	$response = wp_remote_post(
		'https://aeopugmill.com/api/ingest',
		array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $submission_hmac,
			),
			'body'      => wp_json_encode( $payload ),
			'blocking'  => true,
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( $response->get_error_message() );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 === $code ) {
		update_option( 'aeopugmill_last_network_send', time(), false );
		// Bust the cached network report (and any pending failure flag) so the
		// Dashboard immediately reflects fresh averages after a manual send.
		delete_transient( 'aeopugmill_network_report' );
		delete_transient( 'aeopugmill_network_report_failed' );
		wp_send_json_success(
			/* translators: %s: date string */
			sprintf( __( 'Sent successfully for %s.', 'aeo-pugmill' ), $date )
		);
	}

	// If the server rejected with 401, the stored token is likely stale or corrupted
	// (e.g. double-encrypted from a pre-1.0.1 install). Re-register to get a fresh
	// token and retry the send once before giving up.
	if ( 401 === $code ) {
		$rereg = aeopugmill_intelligence_register();
		if ( true === $rereg ) {
			$fresh_token = aeopugmill_get_encrypted_option( 'aeopugmill_network_token', '' );
			if ( ! empty( $fresh_token ) ) {
				// Recalculate HMAC with the fresh token and resend.
				$retry_hmac            = hash_hmac( 'sha256', "{$site_id}:{$date}:" . AEOPUGMILL_VERSION, $fresh_token );
				$payload['network_token'] = $fresh_token;

				$retry_response = wp_remote_post(
					'https://aeopugmill.com/api/ingest',
					array(
						'timeout'   => 15,
						'sslverify' => true,
						'headers'   => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $retry_hmac,
						),
						'body'      => wp_json_encode( $payload ),
						'blocking'  => true,
					)
				);

				if ( ! is_wp_error( $retry_response ) && 200 === (int) wp_remote_retrieve_response_code( $retry_response ) ) {
					update_option( 'aeopugmill_last_network_send', time(), false );
					delete_transient( 'aeopugmill_network_report' );
					delete_transient( 'aeopugmill_network_report_failed' );
					wp_send_json_success(
						/* translators: %s: date string */
						sprintf( __( 'Re-registered and sent successfully for %s.', 'aeo-pugmill' ), $date )
					);
				}
			}
		}
	}

	$server_error = $body['error'] ?? __( 'Unknown error', 'aeo-pugmill' );
	/* translators: 1: HTTP status code, 2: error message from server */
	wp_send_json_error( sprintf( __( 'Server returned %1$d: %2$s', 'aeo-pugmill' ), $code, $server_error ) );
}
add_action( 'wp_ajax_aeopugmill_manual_send', 'aeopugmill_ajax_manual_send' );
