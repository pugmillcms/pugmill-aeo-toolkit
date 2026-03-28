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
 *     Retained for 2 years (730 days).
 *
 *   {prefix}wppugmill_bot_recent
 *     Ring-buffer of individual visits for the "Recent Activity" table.
 *     Stores the actual URL. Pruned to the last 7 days daily.
 *
 * Storage notes vs v1 (single-row-per-visit):
 *   v1: unbounded growth, avg ~150–200 bytes/row, full-table-scan prune
 *   v2: bot_daily capped at ~30k rows/2 years; bot_recent trimmed to 7 days
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPUGMILL_BOT_DB_VERSION', '2' );

// ── Bot ID map ────────────────────────────────────────────────────────────────

/**
 * Canonical bot name → TINYINT ID.
 * Stored as 1 byte instead of varchar(32) (~12 bytes) in the old schema.
 *
 * @return array<string, int>
 */
function wppugmill_bot_ids() {
	return array(
		'ChatGPT'    => 1,
		'Claude'     => 2,
		'Perplexity' => 3,
		'Gemini'     => 4,
		'Amazonbot'  => 5,
		'Meta'       => 6,
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
 * @return array<string, string[]>
 */
function wppugmill_bot_fingerprints() {
	return array(
		'ChatGPT'    => array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot' ),
		'Claude'     => array( 'ClaudeBot', 'anthropic-ai' ),
		'Perplexity' => array( 'PerplexityBot' ),
		'Gemini'     => array( 'Google-Extended' ),
		'Amazonbot'  => array( 'Amazonbot' ),
		'Meta'       => array( 'meta-externalagent' ),
	);
}

/**
 * Detect if a UA string belongs to a known AI bot.
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
	// Pruned to 7 days daily; never grows large.
	$recent_sql = "CREATE TABLE {$wpdb->prefix}wppugmill_bot_recent (
		id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
		bot_id        TINYINT UNSIGNED NOT NULL,
		resource_type TINYINT UNSIGNED NOT NULL DEFAULT 0,
		url           VARCHAR(500) NOT NULL DEFAULT '',
		visited_at    INT UNSIGNED NOT NULL,
		PRIMARY KEY (id),
		KEY bot_time (bot_id, visited_at)
	) {$cc};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $daily_sql );
	dbDelta( $recent_sql );

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
 *  2. Insert into bot_recent (ring-buffer, for the activity table).
 *
 * @param string $bot           Canonical bot name.
 * @param string $url           Request URI (stored in recent only).
 * @param int    $resource_type Resource type ID.
 */
function wppugmill_log_bot_visit( $bot, $url, $resource_type = 0 ) {
	global $wpdb;

	$bot_id = wppugmill_bot_id( $bot );
	if ( ! $bot_id ) {
		return;
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

	// Append to recent visits ring-buffer.
	$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prefix . 'wppugmill_bot_recent',
		array(
			'bot_id'        => $bot_id,
			'resource_type' => (int) $resource_type,
			'url'           => substr( (string) $url, 0, 500 ),
			'visited_at'    => time(),
		),
		array( '%d', '%d', '%s', '%d' )
	);
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

	if ( ! $bot ) {
		return;
	}

	$resource_type = wppugmill_detect_resource_type();
	$url           = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore

	wppugmill_log_bot_visit( $bot, $url, $resource_type );
}
add_action( 'init', 'wppugmill_maybe_log_bot_visit', 99 );

// ── Pruning ───────────────────────────────────────────────────────────────────

/**
 * Prune old data.
 *
 * - bot_daily: retain 730 days (2 years). Tiny table; long retention is cheap.
 * - bot_recent: retain 7 days. Recent-activity table only.
 *
 * Scheduled daily via WP cron; also called on plugin activation.
 */
function wppugmill_bot_analytics_prune() {
	global $wpdb;

	$oldest_day = (int) floor( time() / DAY_IN_SECONDS ) - 730;

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
			"SELECT bot_id, resource_type, url, visited_at
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
		$type     = (int) $row['resource_type'];
		$result[] = array(
			'bot'            => wppugmill_bot_name( $row['bot_id'] ),
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
