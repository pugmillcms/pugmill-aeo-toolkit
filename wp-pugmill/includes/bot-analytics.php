<?php
/**
 * AI Bot Analytics — detect, log, and query AI crawler visits.
 *
 * Schema v2 (two-table model):
 *
 *   {prefix}wppugmill_bot_daily
 *     Aggregate table. One row per (bot × resource_type × day).
 *     Upserted on every bot visit. Primary key prevents bloat —
 *     max rows = bots × resource_types × days_retained.
 *     Retained for 90 days.
 *
 *   {prefix}wppugmill_bot_recent
 *     Ring-buffer of individual visits for the "Recent Activity" table.
 *     Stores the actual URL. Pruned to the last 7 days daily.
 *
 * Storage notes vs v1 (single-row-per-visit):
 *   v1: unbounded growth, avg ~150–200 bytes/row, full-table-scan prune
 *   v2: bot_daily capped at ~5k rows/90 days; bot_recent trimmed to 7 days
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPUGMILL_BOT_DB_VERSION', '3' );

// ── Bot ID map ────────────────────────────────────────────────────────────────

/**
 * Canonical bot name → TINYINT ID (0 reserved for unknown bots).
 *
 * IDs 1-12 are legacy — kept stable for backward compatibility with existing
 * bot_daily rows. New bots are assigned from 13 upward.
 *
 * @return array<string, int>
 */
function wppugmill_bot_ids() {
	return array(
		// ── AI companies (training + realtime bundled by company) ──────────
		'ChatGPT'    => 1,   // GPTBot (training), ChatGPT-User, OAI-SearchBot (realtime)
		'Claude'     => 2,   // ClaudeBot (training), Claude-User (realtime)
		'Perplexity' => 3,   // PerplexityBot (training + realtime)
		'Gemini'     => 4,   // Google-Extended
		'Amazonbot'  => 5,   // Amazonbot (training), Amzn-User (realtime)
		'Meta'       => 6,   // Meta-ExternalAgent (training)
		// ── Search engines ────────────────────────────────────────────────
		'Googlebot'   => 7,
		'Bingbot'     => 8,
		'Applebot'    => 9,
		'DuckDuckBot' => 10,  // DuckDuckBot + DuckAssistBot
		'Bytespider'  => 11,  // ByteDance (TikTok) — training crawler
		'GoogleOther' => 12,
		// ── New AI training crawlers ───────────────────────────────────────
		'Cohere'     => 13,   // cohere-ai, CohereAI
		'DeepSeek'   => 14,   // DeepSeekBot
		'Grok'       => 15,   // GrokBot, xAI-Grok
		'CCBot'      => 16,   // Common Crawl (feeds many LLM training sets)
		'Mistral'    => 17,   // MistralAI-User, MistralBot
		// ── New search engines ────────────────────────────────────────────
		'YandexBot'  => 18,
		'BaiduBot'   => 19,
		// ── Commercial SEO / analytics bots ──────────────────────────────
		'SemrushBot' => 20,
		'AhrefsBot'  => 21,
		'DotBot'     => 22,
		'MJ12bot'    => 23,
		'Barkrowler' => 24,
		'AI2Bot'     => 25,
	);
}

/** @return int|null */
function wppugmill_bot_id( $name ) {
	return wppugmill_bot_ids()[ $name ] ?? null;
}

/** @return string */
function wppugmill_bot_name( $id ) {
	static $flip;
	if ( null === $flip ) {
		$flip = array_flip( wppugmill_bot_ids() );
	}
	return $flip[ (int) $id ] ?? 'Unknown';
}

// ── Resource type map ─────────────────────────────────────────────────────────

/**
 * TINYINT resource ID → human label.
 *
 * 0 — Regular HTML page crawl
 * 1 — /llms.txt                (AI discovery index)
 * 2 — /llms-full.txt           (paginated AEO content)
 * 3 — /{post}/?wppugmill_llm=1 (per-post markdown)
 * 4 — /?wppugmill_llm=1        (site summary markdown)
 * 5 — /sitemap.xml             (crawl discovery)
 * 6 — /robots.txt              (crawl policy)
 *
 * @return array<int, string>
 */
function wppugmill_resource_type_labels() {
	return array(
		0 => 'HTML Page',
		1 => 'llms.txt',
		2 => 'llms-full.txt',
		3 => 'Post Markdown',
		4 => 'Site Summary',
		5 => 'Sitemap',
		6 => 'Robots.txt',
	);
}

/**
 * Resource type category — used for visual grouping in the dashboard.
 *
 * @return array<int, string>  'aeo' | 'discovery' | 'crawl'
 */
function wppugmill_resource_type_categories() {
	return array(
		0 => 'crawl',
		1 => 'aeo',
		2 => 'aeo',
		3 => 'aeo',
		4 => 'aeo',
		5 => 'discovery',
		6 => 'discovery',
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
function wppugmill_bot_fingerprints() {
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
function wppugmill_detect_ai_bot( $ua ) {
	if ( empty( $ua ) ) {
		return false;
	}
	foreach ( wppugmill_bot_fingerprints() as $bot => $needles ) {
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
function wppugmill_parse_bot_name_from_ua( $ua ) {
	// Prefer the domain from any embedded URL — most bots include their info page.
	// e.g. "AhrefsBot/7.0; +https://ahrefs.com/robot/" → "ahrefs.com"
	if ( preg_match( '/https?:\/\/(?:www\.)?([a-zA-Z0-9][a-zA-Z0-9\-]*\.[a-zA-Z]{2,})/i', $ua, $m ) ) {
		return strtolower( $m[1] );
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
function wppugmill_detect_unknown_bot( $ua ) {
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
			return wppugmill_parse_bot_name_from_ua( $ua );
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
 * @return int  Resource type ID (see wppugmill_resource_type_labels())
 */
function wppugmill_detect_resource_type() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore

	// ?wppugmill_llm=1 is a real GET param (not a rewrite), available early.
	if ( isset( $_GET['wppugmill_llm'] ) ) { // phpcs:ignore
		$request_path = rtrim( (string) parse_url( $uri, PHP_URL_PATH ), '/' );
		$home_path    = rtrim( (string) parse_url( home_url(), PHP_URL_PATH ), '/' );
		return ( $request_path === $home_path ) ? 4 : 3;
	}

	// Clean-URL rewrites: visible in REQUEST_URI even before parse_request.
	if ( false !== strpos( $uri, 'llms-full.txt' ) ) {
		return 2;
	}
	if ( false !== strpos( $uri, 'llms.txt' ) ) {
		return 1;
	}
	if ( false !== strpos( $uri, 'sitemap.xml' ) ) {
		return 5;
	}
	if ( false !== strpos( $uri, 'robots.txt' ) ) {
		return 6;
	}

	return 0;
}

// ── DB table install / upgrade ────────────────────────────────────────────────

/**
 * Create or upgrade the analytics tables.
 * Safe to call repeatedly (uses dbDelta + explicit checks).
 */
function wppugmill_bot_analytics_install() {
	global $wpdb;

	$cc = $wpdb->get_charset_collate();

	// Daily aggregates — one row per (bot × resource_type × day).
	// Primary key is the natural deduplication key; upserts increment count.
	$daily_sql = "CREATE TABLE {$wpdb->prefix}wppugmill_bot_daily (
		bot_id        TINYINT UNSIGNED NOT NULL,
		resource_type TINYINT UNSIGNED NOT NULL DEFAULT 0,
		day           MEDIUMINT UNSIGNED NOT NULL,
		count         MEDIUMINT UNSIGNED NOT NULL DEFAULT 1,
		PRIMARY KEY (bot_id, resource_type, day)
	) {$cc};";

	// Recent visits ring-buffer — actual URLs for the activity table.
	// bot_name stores the parsed UA name for unknown bots (bot_id = 0).
	// Pruned to 7 days daily; never grows large.
	$recent_sql = "CREATE TABLE {$wpdb->prefix}wppugmill_bot_recent (
		id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
		bot_id        TINYINT UNSIGNED NOT NULL,
		bot_name      VARCHAR(64) NOT NULL DEFAULT '',
		resource_type TINYINT UNSIGNED NOT NULL DEFAULT 0,
		url           VARCHAR(500) NOT NULL DEFAULT '',
		visited_at    INT UNSIGNED NOT NULL,
		PRIMARY KEY (id),
		KEY bot_time (bot_id, visited_at)
	) {$cc};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $daily_sql );
	dbDelta( $recent_sql );

	// v3: add bot_name column to bot_recent for unknown bot display names.
	// dbDelta doesn't add columns to existing tables, so we use ALTER TABLE.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$cols = $wpdb->get_col( "DESCRIBE {$wpdb->prefix}wppugmill_bot_recent", 0 );
	if ( ! in_array( 'bot_name', (array) $cols, true ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}wppugmill_bot_recent ADD COLUMN bot_name VARCHAR(64) NOT NULL DEFAULT '' AFTER bot_id" );
	}

	update_option( 'wppugmill_bot_db_version', WPPUGMILL_BOT_DB_VERSION );
}

/**
 * Lazy-install / auto-upgrade check.
 * Runs on plugins_loaded so schema updates apply without manual action.
 */
function wppugmill_bot_analytics_maybe_install() {
	$installed = get_option( 'wppugmill_bot_db_version' );

	if ( $installed === WPPUGMILL_BOT_DB_VERSION ) {
		return;
	}

	wppugmill_bot_analytics_install();

	// Migrate data from the v1 single-visit table if it exists.
	if ( '1' === $installed ) {
		wppugmill_bot_analytics_migrate_v1();
	}
	// v2 → v3: install() already runs the ALTER TABLE for bot_name column.
}
add_action( 'plugins_loaded', 'wppugmill_bot_analytics_maybe_install' );

// ── v1 → v2 migration ────────────────────────────────────────────────────────

/**
 * Migrate data from the old single-row-per-visit table into the new schema.
 *
 * - Aggregates old visits into bot_daily (resource_type = 0, HTML page).
 * - Populates bot_recent from the 200 most recent old rows.
 * - Drops the old table when done.
 */
function wppugmill_bot_analytics_migrate_v1() {
	global $wpdb;

	$old = $wpdb->prefix . 'wppugmill_bot_visits';

	// Check the old table actually exists before touching it.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$old}'" ) !== $old ) {
		return;
	}

	$bot_ids = wppugmill_bot_ids();

	// ── Aggregate into bot_daily ──────────────────────────────────────────
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$agg_rows = $wpdb->get_results(
		"SELECT bot, DATE(visited_at) AS day_str, COUNT(*) AS cnt
		 FROM {$old}
		 GROUP BY bot, DATE(visited_at)",
		ARRAY_A
	);

	foreach ( (array) $agg_rows as $row ) {
		$bot_id = $bot_ids[ $row['bot'] ] ?? null;
		if ( ! $bot_id ) {
			continue;
		}
		$day = (int) floor( strtotime( $row['day_str'] ) / DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}wppugmill_bot_daily
			 (bot_id, resource_type, day, count)
			 VALUES (%d, 0, %d, %d)
			 ON DUPLICATE KEY UPDATE count = count + VALUES(count)",
			$bot_id,
			$day,
			(int) $row['cnt']
		) );
	}

	// ── Seed bot_recent from the 200 newest old visits ────────────────────
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$recent_rows = $wpdb->get_results(
		"SELECT bot, url, visited_at FROM {$old}
		 ORDER BY visited_at DESC LIMIT 200",
		ARRAY_A
	);

	foreach ( (array) $recent_rows as $row ) {
		$bot_id = $bot_ids[ $row['bot'] ] ?? null;
		if ( ! $bot_id ) {
			continue;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'wppugmill_bot_recent',
			array(
				'bot_id'        => $bot_id,
				'resource_type' => 0,
				'url'           => substr( (string) $row['url'], 0, 500 ),
				'visited_at'    => (int) strtotime( $row['visited_at'] ),
			),
			array( '%d', '%d', '%s', '%d' )
		);
	}

	// ── Drop old table ────────────────────────────────────────────────────
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$old}" );
}

// ── Logging ───────────────────────────────────────────────────────────────────

/**
 * Record one bot visit.
 *
 * Two writes:
 *  1. Upsert into bot_daily (aggregate counter, deduped by PK).
 *     Unknown bots (bot_id = 0) are all aggregated together.
 *  2. Insert into bot_recent (ring-buffer, for the activity table).
 *     Unknown bots store their parsed name in the bot_name column.
 *
 * @param string $bot           Canonical bot name (empty string for unknowns).
 * @param string $url           Request URI (stored in recent only).
 * @param int    $resource_type Resource type ID.
 * @param string $unknown_name  Parsed UA name — used only when $bot is empty.
 */
function wppugmill_log_bot_visit( $bot, $url, $resource_type = 0, $unknown_name = '' ) {
	// Only collect data if the site owner has opted in to the intelligence network.
	if ( ! get_option( 'wppugmill_analytics_opted_in' ) ) {
		return;
	}

	global $wpdb;

	if ( $bot ) {
		$bot_id      = wppugmill_bot_id( $bot );
		$stored_name = '';         // known bots: name derived from ID at read time
		if ( ! $bot_id ) {
			return; // unrecognised canonical name — shouldn't happen
		}
	} else {
		$bot_id      = 0;          // sentinel: unknown bot
		$stored_name = substr( $unknown_name ?: 'Unknown', 0, 64 );
	}

	$day = (int) floor( time() / DAY_IN_SECONDS );

	// Upsert aggregate row — primary key prevents duplicate rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}wppugmill_bot_daily
		 (bot_id, resource_type, day, count)
		 VALUES (%d, %d, %d, 1)
		 ON DUPLICATE KEY UPDATE count = count + 1",
		$bot_id,
		(int) $resource_type,
		$day
	) );

	// Append to recent visits ring-buffer (includes bot_name for unknowns).
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'wppugmill_bot_recent',
		array(
			'bot_id'        => $bot_id,
			'bot_name'      => $stored_name,
			'resource_type' => (int) $resource_type,
			'url'           => substr( (string) $url, 0, 500 ),
			'visited_at'    => time(),
		),
		array( '%d', '%s', '%d', '%s', '%d' )
	);

	// Hard cap: keep only the 500 most recent rows so the table stays bounded
	// regardless of crawl frequency. Deletes the oldest rows if over the limit.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wppugmill_bot_recent" );
	if ( $count > 500 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wppugmill_bot_recent ORDER BY visited_at ASC, id ASC LIMIT %d",
			$count - 500
		) );
	}
}

/**
 * Hook: detect and log AI bot visits on every public request.
 */
function wppugmill_maybe_log_bot_visit() {
	if ( is_admin() ) {
		return;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
	$bot = wppugmill_detect_ai_bot( $ua );

	$resource_type = wppugmill_detect_resource_type();
	$url           = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore

	if ( $bot ) {
		wppugmill_log_bot_visit( $bot, $url, $resource_type );
		return;
	}

	// Unknown-bot fallback: log anything that walks and quacks like a bot
	// but wasn't in the known fingerprint list.
	$unknown_name = wppugmill_detect_unknown_bot( $ua );
	if ( $unknown_name ) {
		wppugmill_log_bot_visit( '', $url, $resource_type, $unknown_name );
	}
}
add_action( 'init', 'wppugmill_maybe_log_bot_visit', 99 );

// ── Pruning ───────────────────────────────────────────────────────────────────

/**
 * Prune old data.
 *
 * - bot_daily: retain 90 days.
 * - bot_recent: retain 7 days. Recent-activity table only.
 *
 * Scheduled daily via WP cron; also called on plugin activation.
 */
function wppugmill_bot_analytics_prune() {
	global $wpdb;

	$oldest_day = (int) floor( time() / DAY_IN_SECONDS ) - 90;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}wppugmill_bot_daily WHERE day < %d",
		$oldest_day
	) );

	$cutoff = time() - ( 7 * DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}wppugmill_bot_recent WHERE visited_at < %d",
		$cutoff
	) );
}

// ── Data queries ──────────────────────────────────────────────────────────────

/**
 * Total visits per bot over the last N days.
 *
 * @param  int $days
 * @return array<string, int>  bot_name => count
 */
function wppugmill_bot_analytics_summary( $days = 30 ) {
	global $wpdb;

	$since = (int) floor( time() / DAY_IN_SECONDS ) - (int) $days;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT bot_id, SUM(count) AS cnt
			 FROM {$wpdb->prefix}wppugmill_bot_daily
			 WHERE day >= %d
			 GROUP BY bot_id",
			$since
		),
		ARRAY_A
	);

	$result = array();
	foreach ( (array) $rows as $row ) {
		$result[ wppugmill_bot_name( $row['bot_id'] ) ] = (int) $row['cnt'];
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
function wppugmill_bot_analytics_by_resource( $days = 30 ) {
	global $wpdb;

	$since = (int) floor( time() / DAY_IN_SECONDS ) - (int) $days;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT bot_id, resource_type, SUM(count) AS cnt
			 FROM {$wpdb->prefix}wppugmill_bot_daily
			 WHERE day >= %d
			 GROUP BY bot_id, resource_type",
			$since
		),
		ARRAY_A
	);

	$result = array();
	foreach ( (array) $rows as $row ) {
		$bot  = wppugmill_bot_name( $row['bot_id'] );
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
function wppugmill_bot_analytics_daily( $days = 30 ) {
	global $wpdb;

	$since = (int) floor( time() / DAY_IN_SECONDS ) - (int) $days;

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT bot_id, day, SUM(count) AS cnt
			 FROM {$wpdb->prefix}wppugmill_bot_daily
			 WHERE day >= %d
			 GROUP BY bot_id, day
			 ORDER BY day ASC",
			$since
		),
		ARRAY_A
	);

	$result = array();
	foreach ( (array) $rows as $row ) {
		$result[] = array(
			'bot' => wppugmill_bot_name( $row['bot_id'] ),
			'day' => gmdate( 'Y-m-d', (int) $row['day'] * DAY_IN_SECONDS ),
			'cnt' => (int) $row['cnt'],
		);
	}
	return $result;
}

/**
 * Most recent individual visits from the ring-buffer table.
 *
 * @param  int $limit
 * @return array  Each entry: [ 'bot', 'resource_type' (int), 'resource_label', 'url', 'visited_at' (int, Unix) ]
 */
function wppugmill_bot_analytics_recent( $limit = 50 ) {
	global $wpdb;

	$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT bot_id, bot_name, resource_type, url, visited_at
			 FROM {$wpdb->prefix}wppugmill_bot_recent
			 ORDER BY visited_at DESC, id DESC
			 LIMIT %d",
			(int) $limit
		),
		ARRAY_A
	);

	$labels = wppugmill_resource_type_labels();
	$result = array();
	foreach ( $rows as $row ) {
		$type   = (int) $row['resource_type'];
		$bot_id = (int) $row['bot_id'];
		// bot_id = 0 → unknown bot; use bot_name stored at log time.
		$bot    = ( 0 === $bot_id )
			? ( ! empty( $row['bot_name'] ) ? $row['bot_name'] : 'Unknown' )
			: wppugmill_bot_name( $bot_id );
		$result[] = array(
			'bot'            => $bot,
			'resource_type'  => $type,
			'resource_label' => $labels[ $type ] ?? 'Unknown',
			'url'            => $row['url'],
			'visited_at'     => (int) $row['visited_at'],
		);
	}
	return $result;
}

/**
 * All-time total visit count (sum of all aggregate rows).
 *
 * @return int
 */
function wppugmill_bot_analytics_total() {
	global $wpdb;

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT SUM(count) FROM {$wpdb->prefix}wppugmill_bot_daily"
	);
}

/**
 * Top posts by bot visit count from the recent ring-buffer.
 *
 * Aggregates HTML page (0) and Post Markdown (3) visits from bot_recent,
 * normalises URLs (strips query strings), skips system paths, and returns
 * the most-visited content URLs sorted by total count.
 *
 * @param  int $limit
 * @return array  [ [ 'url', 'total', 'aeo', 'bots' => [ bot_name => count ] ], ... ]
 */
function wppugmill_bot_analytics_top_posts( $limit = 10 ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = (array) $wpdb->get_results(
		"SELECT bot_id, resource_type, url FROM {$wpdb->prefix}wppugmill_bot_recent WHERE resource_type IN (0, 3)",
		ARRAY_A
	);

	// System path prefixes to skip — not content pages.
	$skip_prefixes = array( '/wp-', '/feed', '/author/', '/tag/', '/category/', '/page/' );

	$aggregated = array();

	foreach ( $rows as $row ) {
		$parsed_path = (string) parse_url( $row['url'], PHP_URL_PATH );
		$path        = rtrim( $parsed_path, '/' );

		if ( '' === $path || '/' === $path ) {
			continue;
		}

		$skip = false;
		foreach ( $skip_prefixes as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				$skip = true;
				break;
			}
		}
		// Skip bare /?p= style IDs — they're usually drafts.
		if ( ! $skip && false !== strpos( $row['url'], '?p=' ) ) {
			$skip = true;
		}
		if ( $skip ) {
			continue;
		}

		$bot    = wppugmill_bot_name( $row['bot_id'] );
		$is_aeo = ( 3 === (int) $row['resource_type'] );

		if ( ! isset( $aggregated[ $path ] ) ) {
			$aggregated[ $path ] = array( 'url' => $path, 'total' => 0, 'aeo' => false, 'bots' => array() );
		}
		$aggregated[ $path ]['total']++;
		$aggregated[ $path ]['bots'][ $bot ] = ( $aggregated[ $path ]['bots'][ $bot ] ?? 0 ) + 1;
		if ( $is_aeo ) {
			$aggregated[ $path ]['aeo'] = true;
		}
	}

	usort( $aggregated, function( $a, $b ) { return $b['total'] - $a['total']; } );

	return array_slice( array_values( $aggregated ), 0, $limit );
}

/**
 * Build a rich analytics context payload for the AI insights prompt.
 *
 * Includes: 30-day summary, AEO conversion rate, 15-day trend split,
 * network benchmark (if opted in), and top posts.
 *
 * @return array
 */
function wppugmill_bot_analytics_insights_context() {
	$days        = 30;
	$summary     = wppugmill_bot_analytics_summary( $days );
	$by_resource = wppugmill_bot_analytics_by_resource( $days );
	$total       = wppugmill_bot_analytics_total();
	$top_posts   = wppugmill_bot_analytics_top_posts( 5 );
	$labels      = wppugmill_resource_type_labels();
	$daily       = wppugmill_bot_analytics_daily( $days );

	// Flatten resource breakdown to bot → label → count.
	$reach = array();
	foreach ( $by_resource as $bot => $types ) {
		foreach ( $types as $type_id => $cnt ) {
			$reach[ $bot ][ $labels[ $type_id ] ?? 'Unknown' ] = $cnt;
		}
	}

	// ── AEO conversion rate ──────────────────────────────────────────────────
	// AEO endpoints: llms.txt (1), llms-full.txt (2), Post Markdown (3), Site Summary (4).
	$aeo_types   = array( 1, 2, 3, 4 );
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

	// ── Network benchmark ────────────────────────────────────────────────────
	$network_context = null;
	if ( get_option( 'wppugmill_analytics_opted_in' ) ) {
		$net_response = wp_remote_get( 'https://pugmill.dev/api/report', array( 'timeout' => 8, 'sslverify' => true ) );
		if ( ! is_wp_error( $net_response ) ) {
			$net_data      = json_decode( wp_remote_retrieve_body( $net_response ), true ) ?: array();
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
				$network_context = array(
					'sites_in_network' => $network_sites,
					'note'             => 'Pugmill Intelligence Network: per-site 30-day averages',
					'benchmarks'       => $benchmarks,
					'zero_visit_bots'  => $zero_bots,
				);
			}
		}
	}

	$context = array(
		'site'               => get_bloginfo( 'name' ),
		'url'                => home_url(),
		'period_days'        => $days,
		'total_all_time'     => $total,
		'total_30_days'      => $total_30,
		'visits_by_bot'      => $summary,
		'aeo_conversion_pct' => $aeo_conversion_pct,
		'content_reach'      => $reach,
		'traffic_trend'      => $trends,
		'top_posts'          => array_map( function( $p ) {
			return array(
				'url'     => $p['url'],
				'total'   => $p['total'],
				'aeo_hit' => $p['aeo'],
				'by_bot'  => $p['bots'],
			);
		}, $top_posts ),
	);

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
function wppugmill_ajax_analytics_insights() {
	check_ajax_referer( 'wppugmill_analytics_insights', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'wp-pugmill' ), 403 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error( __( 'No API key configured. Add your key in Settings → WP Pugmill.', 'wp-pugmill' ) );
	}

	$cache_key = 'wppugmill_ai_analytics_insights';
	$refresh   = ! empty( $_POST['refresh'] );

	if ( ! $refresh ) {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success( $cached );
		}
	}

	$context  = wppugmill_bot_analytics_insights_context();
	$ctx_json = wp_json_encode( $context, JSON_PRETTY_PRINT );

	$system = "You are an expert in AI search and Answer Engine Optimization (AEO). You receive bot traffic data from a WordPress site using the WP Pugmill AEO plugin. Analyze the data and write a concise, insightful report in plain text — no markdown except the section headings below.

Structure your response with exactly these five section headings, each on its own line preceded by '## ':

## Bot Activity
Which bots are most active and what that signals (citation activity, indexing depth, content discovery phase). Note AEO endpoint hits (llms.txt, llms-full.txt, Post Markdown, Site Summary) as strong positive signals — they mean a bot is reading your optimized content directly. Mention the AEO conversion percentage (what share of visits hit AEO endpoints vs generic HTML/sitemap crawling) and whether it is healthy.

## Traffic Trend
Compare each bot's first 15 days versus last 15 days. Name which bots are rising, falling, or flat and what that implies. If a bot appears only in the second half, call it out as newly active — that is worth watching.

## Network Benchmark
If network_benchmark data is present: for each bot, state whether this site is well above average, above average, at average, below average, or well below average compared to the Pugmill Intelligence Network (the ratio field tells you: ≥ 2.0 = well above, ≥ 1.1 = above, 0.9–1.1 = at average, ≥ 0.5 = below, < 0.5 = well below). For every bot listed in zero_visit_bots (bots the network sees but this site has zero visits from), name them and say the typical site gets N visits — this is a gap. If no network_benchmark data is present, skip this section entirely.

## Content Coverage
Which pages or post types are crawled most, which resource types are hit most, and any patterns worth noting (ignored sections, repeat visits on specific posts, etc.).

## Recommendations
Give 3–4 specific, prioritized actions. For any bot that is below average or a zero-visit gap, give a targeted fix. Use this guidance: ChatGPT — enrich llms.txt with Q&A pairs and AEO summaries, ChatGPT reads it directly; Perplexity — prioritize AEO summaries on high-traffic posts, Perplexity cites in real-time so freshness matters; ClaudeBot — keep sitemap current and add AEO markup to all posts; Gemini — Schema.org JSON-LD is key; Bingbot — clean sitemaps and solid meta descriptions; if AEO conversion rate is under 10%, recommend running Generate All AEO on top posts first. Only mention bots present in the data or identified as network gaps.

Rules: blank line between each heading and its paragraph. No bullet lists. 2–4 sentences per section. Total response under 450 words.";

	$user = "Site: " . get_bloginfo( 'name' ) . " (" . home_url() . ")\n\nBot analytics data:\n\n" . $ctx_json . "\n\nProvide your analysis.";

	$result = wppugmill_call_ai( $provider, $api_key, $system, $user, 750 );

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
add_action( 'wp_ajax_wppugmill_analytics_insights', 'wppugmill_ajax_analytics_insights' );

// ── AJAX: CSV export ──────────────────────────────────────────────────────────

/**
 * Stream a CSV of daily aggregate data (all retained data, newest first).
 */
function wppugmill_ajax_export_csv_daily() {
	check_ajax_referer( 'wppugmill_export_csv', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'wp-pugmill' ), 403 );
	}

	global $wpdb;

	$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT bot_id, resource_type, day, count
		 FROM {$wpdb->prefix}wppugmill_bot_daily
		 ORDER BY day DESC, bot_id ASC",
		ARRAY_A
	);

	$labels = wppugmill_resource_type_labels();

	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="wppugmill-bot-daily-' . gmdate( 'Y-m-d' ) . '.csv"' );
	header( 'Pragma: no-cache' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Date', 'Bot', 'Resource Type', 'Count' ) );

	foreach ( $rows as $row ) {
		fputcsv( $out, array(
			gmdate( 'Y-m-d', (int) $row['day'] * DAY_IN_SECONDS ),
			wppugmill_bot_name( (int) $row['bot_id'] ),
			$labels[ (int) $row['resource_type'] ] ?? 'Unknown',
			(int) $row['count'],
		) );
	}

	fclose( $out );
	exit;
}
add_action( 'wp_ajax_wppugmill_export_csv_daily', 'wppugmill_ajax_export_csv_daily' );

/**
 * Stream a CSV of the recent visit ring-buffer.
 */
function wppugmill_ajax_export_csv_recent() {
	check_ajax_referer( 'wppugmill_export_csv', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'wp-pugmill' ), 403 );
	}

	global $wpdb;

	$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT bot_id, bot_name, resource_type, url, visited_at
		 FROM {$wpdb->prefix}wppugmill_bot_recent
		 ORDER BY visited_at DESC",
		ARRAY_A
	);

	$labels = wppugmill_resource_type_labels();

	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="wppugmill-bot-visits-' . gmdate( 'Y-m-d' ) . '.csv"' );
	header( 'Pragma: no-cache' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Timestamp (UTC)', 'Bot', 'Resource Type', 'URL' ) );

	foreach ( $rows as $row ) {
		$bot_id  = (int) $row['bot_id'];
		$display = ( 0 === $bot_id )
			? ( ! empty( $row['bot_name'] ) ? $row['bot_name'] : 'Unknown' )
			: wppugmill_bot_name( $bot_id );
		fputcsv( $out, array(
			gmdate( 'Y-m-d H:i:s', (int) $row['visited_at'] ),
			$display,
			$labels[ (int) $row['resource_type'] ] ?? 'Unknown',
			$row['url'],
		) );
	}

	fclose( $out );
	exit;
}
add_action( 'wp_ajax_wppugmill_export_csv_recent', 'wppugmill_ajax_export_csv_recent' );

// ── Pugmill Intelligence — registration & daily send ─────────────────────────

/**
 * Register this site with the Pugmill Intelligence Network.
 *
 * Called once when the user opts in. Sends a signed registration request to
 * the Pugmill CMS server. On success, stores the returned network_token
 * (encrypted) so `wppugmill_intelligence_send()` can authenticate each
 * daily submission.
 *
 * The HMAC proves this request came from real plugin code — the network secret
 * is baked into the plugin build and is not transmitted to the end-user's site.
 */
function wppugmill_intelligence_register() {
	$network_secret = WPPUGMILL_NETWORK_SECRET;

	$site_id     = hash( 'sha256', home_url() . wppugmill_instance_id() );
	$opted_in_at = gmdate( 'c' );
	$nonce       = bin2hex( random_bytes( 16 ) );

	// Registration HMAC — proves the request originated from the real plugin.
	$reg_hmac = hash_hmac( 'sha256', "{$site_id}:{$opted_in_at}:{$nonce}", $network_secret );

	$response = wp_remote_post(
		'https://pugmill.dev/api/ingest/register',
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
				'plugin_version' => WPPUGMILL_VERSION,
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
	wppugmill_save_encrypted_option( 'wppugmill_network_token', $data['network_token'] );
	return true;
}

/**
 * Fire registration when the user opts in.
 * The option value transitions from 0 → 1 at opt-in.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $new_value New option value.
 */
function wppugmill_on_analytics_opt_in( $old_value, $new_value ) {
	if ( (int) $new_value === 1 && (int) $old_value !== 1 ) {
		wppugmill_intelligence_register(); // return value intentionally ignored
	}
}
add_action( 'update_option_wppugmill_analytics_opted_in', 'wppugmill_on_analytics_opt_in', 10, 2 );

// ── Pugmill Intelligence — daily send ─────────────────────────────────────────

/**
 * Resource type ID → slug for the intelligence network payload.
 *
 * @return array<int, string>
 */
function wppugmill_intelligence_resource_slugs() {
	return array(
		0 => 'html',
		1 => 'llms_txt',
		2 => 'llms_full',
		3 => 'post_markdown',
		4 => 'site_summary',
		5 => 'sitemap',
		6 => 'robots_txt',
	);
}

/**
 * Send yesterday's aggregated bot visit data to the Pugmill Intelligence network.
 * Fires daily via WP-Cron. Silent on failure — never affects the site.
 */
function wppugmill_intelligence_send() {
	if ( ! get_option( 'wppugmill_analytics_opted_in' ) ) {
		return;
	}

	global $wpdb;

	$yesterday     = (int) floor( ( time() - DAY_IN_SECONDS ) / DAY_IN_SECONDS );
	$resource_slugs = wppugmill_intelligence_resource_slugs();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_id, resource_type, count
		 FROM {$wpdb->prefix}wppugmill_bot_daily
		 WHERE day = %d",
		$yesterday
	), ARRAY_A );

	if ( empty( $rows ) ) {
		return;
	}

	// Build bots payload: { BotName: { resource_slug: count } }
	// Unknown bots (bot_id = 0) are sent as 'Other' — the server can aggregate.
	$bots = array();
	foreach ( $rows as $row ) {
		$bot_id   = (int) $row['bot_id'];
		$bot_name = ( 0 === $bot_id ) ? 'Other' : wppugmill_bot_name( $bot_id );
		$resource = $resource_slugs[ (int) $row['resource_type'] ] ?? null;
		if ( ! $resource ) {
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
		 WHERE meta_key = '_wppugmill_aeo'
		 AND LENGTH(meta_value) > 50"
	);
	$aeo_tier = 0;
	if ( $aeo_count >= 10 ) {
		$aeo_tier = 2;
	} elseif ( $aeo_count >= 1 ) {
		$aeo_tier = 1;
	}

	// Hash is salted with the site's private instance ID (stored only in their DB,
	// never transmitted). This prevents rainbow table attacks — even a full list of
	// known domains cannot reverse the hash without each site's unique UUID.
	$site_id       = hash( 'sha256', home_url() . wppugmill_instance_id() );
	$date          = gmdate( 'Y-m-d', $yesterday * DAY_IN_SECONDS );
	$network_token = wppugmill_get_encrypted_option( 'wppugmill_network_token', '' );

	// If opted in but not yet registered (e.g. first run after activation), try now.
	if ( empty( $network_token ) ) {
		if ( wppugmill_intelligence_register() ) {
			$network_token = wppugmill_get_encrypted_option( 'wppugmill_network_token', '' );
		}
	}

	// Cannot proceed without a token — site is not registered.
	if ( empty( $network_token ) ) {
		return;
	}

	// Submission HMAC — signs this specific day's payload with the site's token.
	$submission_hmac = hash_hmac( 'sha256', "{$site_id}:{$date}:" . WPPUGMILL_VERSION, $network_token );

	// Per-bot crawl intelligence signals for yesterday.
	// Structure: { BotName: { metric: { bucket: tally } } }
	// Only included when the function exists (requires bot-intelligence.php v2+).
	$signals = array();
	if ( function_exists( 'wppugmill_intel_get_signals_30d' ) ) {
		$raw_signals = wppugmill_intel_get_signals_30d( 1 ); // yesterday only
		// Strip internal-only keys (e.g. '_site') before transmitting.
		foreach ( $raw_signals as $bot_key => $bot_signals ) {
			if ( substr( $bot_key, 0, 1 ) === '_' ) {
				continue;
			}
			$signals[ $bot_key ] = $bot_signals;
		}
	}

	$payload = array(
		'site_id'        => $site_id,
		'date'           => $date,
		'plugin_version' => WPPUGMILL_VERSION,
		'aeo_tier'       => $aeo_tier,
		'bots'           => $bots,
		'network_token'  => $network_token,
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
	$payload = apply_filters( 'wppugmill_intelligence_payload', $payload, $yesterday );

	wp_remote_post(
		'https://pugmill.dev/api/ingest',
		array(
			'timeout'     => 10,
			'sslverify'   => true,
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $submission_hmac,
			),
			'body'        => wp_json_encode( $payload ),
			'blocking'    => false, // fire-and-forget — don't slow down cron
		)
	);
}
add_action( 'wppugmill_intelligence_send', 'wppugmill_intelligence_send' );

/**
 * AJAX handler: manually trigger an intelligence send and return the server response.
 * Admin-only. Used by the "Send now" button on the Analytics tab for testing.
 */
function wppugmill_ajax_manual_send() {
	check_ajax_referer( 'wppugmill_manual_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Insufficient permissions.', 'wp-pugmill' ) );
	}

	if ( ! get_option( 'wppugmill_analytics_opted_in' ) ) {
		wp_send_json_error( __( 'Not opted in to the Pugmill Intelligence Network.', 'wp-pugmill' ) );
	}

	global $wpdb;

	$yesterday      = (int) floor( ( time() - DAY_IN_SECONDS ) / DAY_IN_SECONDS );
	$resource_slugs = wppugmill_intelligence_resource_slugs();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT bot_id, resource_type, count
		 FROM {$wpdb->prefix}wppugmill_bot_daily
		 WHERE day = %d",
		$yesterday
	), ARRAY_A );

	if ( empty( $rows ) ) {
		wp_send_json_error( __( 'No bot data recorded for yesterday — nothing to send.', 'wp-pugmill' ) );
	}

	$bots = array();
	foreach ( $rows as $row ) {
		$bot_name = wppugmill_bot_name( (int) $row['bot_id'] );
		$resource = $resource_slugs[ (int) $row['resource_type'] ] ?? null;
		if ( ! $resource || 'Unknown' === $bot_name ) {
			continue;
		}
		if ( ! isset( $bots[ $bot_name ] ) ) {
			$bots[ $bot_name ] = array();
		}
		$bots[ $bot_name ][ $resource ] = (int) $row['count'];
	}

	if ( empty( $bots ) ) {
		wp_send_json_error( __( 'No recognised bot activity for yesterday.', 'wp-pugmill' ) );
	}

	$aeo_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT COUNT(*) FROM {$wpdb->postmeta}
		 WHERE meta_key = '_wppugmill_aeo'
		 AND LENGTH(meta_value) > 50"
	);
	$aeo_tier = 0;
	if ( $aeo_count >= 10 ) {
		$aeo_tier = 2;
	} elseif ( $aeo_count >= 1 ) {
		$aeo_tier = 1;
	}

	$site_id       = hash( 'sha256', home_url() . wppugmill_instance_id() );
	$date          = gmdate( 'Y-m-d', $yesterday * DAY_IN_SECONDS );
	$network_token = wppugmill_get_encrypted_option( 'wppugmill_network_token', '' );

	if ( empty( $network_token ) ) {
		$reg_result = wppugmill_intelligence_register();
		if ( true === $reg_result || ( is_string( $reg_result ) && empty( $reg_result ) ) ) {
			$network_token = wppugmill_get_encrypted_option( 'wppugmill_network_token', '' );
		} else {
			wp_send_json_error( 'Registration failed: ' . ( is_string( $reg_result ) ? $reg_result : 'unknown error' ) );
		}
	}

	if ( empty( $network_token ) ) {
		wp_send_json_error( __( 'Registration failed — token missing after register. Check your connection to pugmill.dev.', 'wp-pugmill' ) );
	}

	$submission_hmac = hash_hmac( 'sha256', "{$site_id}:{$date}:" . WPPUGMILL_VERSION, $network_token );

	$signals = array();
	if ( function_exists( 'wppugmill_intel_get_signals_30d' ) ) {
		$raw_signals = wppugmill_intel_get_signals_30d( 1 );
		foreach ( $raw_signals as $bot_key => $bot_signals ) {
			if ( substr( $bot_key, 0, 1 ) === '_' ) {
				continue;
			}
			$signals[ $bot_key ] = $bot_signals;
		}
	}

	$payload = array(
		'site_id'        => $site_id,
		'date'           => $date,
		'plugin_version' => WPPUGMILL_VERSION,
		'aeo_tier'       => $aeo_tier,
		'bots'           => $bots,
		'network_token'  => $network_token,
	);

	if ( ! empty( $signals ) ) {
		$payload['signals'] = $signals;
	}

	/** This filter is documented in wppugmill_intelligence_send(). */
	$payload = apply_filters( 'wppugmill_intelligence_payload', $payload, $yesterday );

	// Blocking so we can report success/failure back to the UI.
	$response = wp_remote_post(
		'https://pugmill.dev/api/ingest',
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
		wp_send_json_success(
			/* translators: %s: date string */
			sprintf( __( 'Sent successfully for %s.', 'wp-pugmill' ), $date )
		);
	}

	$server_error = $body['error'] ?? __( 'Unknown error', 'wp-pugmill' );
	/* translators: 1: HTTP status code, 2: error message from server */
	wp_send_json_error( sprintf( __( 'Server returned %1$d: %2$s', 'wp-pugmill' ), $code, $server_error ) );
}
add_action( 'wp_ajax_wppugmill_manual_send', 'wppugmill_ajax_manual_send' );
