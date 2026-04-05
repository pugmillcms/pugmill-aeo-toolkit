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
		// AI assistants / LLM crawlers
		'ChatGPT'    => 1,
		'Claude'     => 2,
		'Perplexity' => 3,
		'Gemini'     => 4,
		'Amazonbot'  => 5,
		'Meta'       => 6,
		// Traditional search spiders
		'GoogleOther' => 12,
		'Googlebot'   => 7,
		'Bingbot'     => 8,
		'Applebot'    => 9,
		'DuckDuckBot' => 10,
		'Bytespider'  => 11,
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
		// AI assistants — checked first so Google-Extended (Gemini) beats Googlebot
		'ChatGPT'    => array( 'GPTBot', 'ChatGPT-User', 'OAI-SearchBot' ),
		'Claude'     => array( 'ClaudeBot', 'anthropic-ai' ),
		'Perplexity' => array( 'PerplexityBot' ),
		'Gemini'     => array( 'Google-Extended' ),
		'Amazonbot'  => array( 'Amazonbot' ),
		'Meta'       => array( 'meta-externalagent' ),
		// Traditional search spiders — checked after AI bots
		'GoogleOther' => array( 'GoogleOther' ),
		'Googlebot'   => array( 'Googlebot' ),
		'Bingbot'     => array( 'bingbot' ),
		'Applebot'    => array( 'Applebot' ),
		'DuckDuckBot' => array( 'DuckDuckBot' ),
		'Bytespider'  => array( 'Bytespider' ),
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
	// Only collect data if the site owner has opted in to the intelligence network.
	if ( ! get_option( 'wppugmill_analytics_opted_in' ) ) {
		return;
	}

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
 * Build a compact analytics context payload for the AI insights prompt.
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

	// Flatten resource breakdown to bot → label → count.
	$reach = array();
	foreach ( $by_resource as $bot => $types ) {
		foreach ( $types as $type_id => $cnt ) {
			$reach[ $bot ][ $labels[ $type_id ] ?? 'Unknown' ] = $cnt;
		}
	}

	return array(
		'site'          => get_bloginfo( 'name' ),
		'url'           => home_url(),
		'period_days'   => $days,
		'total_visits'  => $total,
		'visits_by_bot' => $summary,
		'content_reach' => $reach,
		'top_posts'     => array_map( function( $p ) {
			return array(
				'url'     => $p['url'],
				'total'   => $p['total'],
				'aeo_hit' => $p['aeo'],
				'by_bot'  => $p['bots'],
			);
		}, $top_posts ),
	);
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

	$system = "You are an expert in AI search and web crawler analytics. You will receive bot traffic data from a WordPress blog using the WP Pugmill SEO+AEO plugin. Analyze the data and write a concise, insightful report.

Structure your response using exactly these four section headings, each on its own line preceded by '## ':

## Bot Activity
Which bots are most active and what that signals (e.g. citation activity, indexing depth, content discovery phase). Note any AEO endpoint hits (?wppugmill_llm=1 means a bot read your optimized markdown directly — flag this as a strong positive signal).

## Content Coverage
Which pages or post types are being crawled most, and what patterns you notice (repeat visits, ignored sections, etc.).

## AI vs Search Bots
Differences between AI crawlers and traditional search spiders if both are present; what each group's behavior implies.

## Recommendations
Give 2-3 specific, actionable recommendations tailored to the bots actually present in the data. Use this guidance: if ClaudeBot is active and hitting sitemaps heavily, recommend keeping the sitemap current and ensuring all posts have AEO markup; if ChatGPT/GPTBot is active, recommend enriching the llms.txt file with more complete AEO summaries and Q&A pairs since ChatGPT is known to read it directly; if Perplexity is active, recommend prioritising AEO summaries and Q&A pairs on high-traffic posts since Perplexity cites content in real-time answers; if only traditional search bots are present with no AI crawlers, recommend completing the llms.txt and AEO metadata setup to attract AI crawlers. Only mention bots that appear in the data.

Rules: use a blank line between the heading and its paragraph. No bullet lists. Keep each section to 2-4 sentences. Total response under 350 words.";

	$user = "Site: " . get_bloginfo( 'name' ) . " (" . home_url() . ")\n\nBot analytics data:\n\n" . $ctx_json . "\n\nProvide your analysis.";

	$result = wppugmill_call_ai( $provider, $api_key, $system, $user, 600 );

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
		"SELECT bot_id, resource_type, url, visited_at
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
		fputcsv( $out, array(
			gmdate( 'Y-m-d H:i:s', (int) $row['visited_at'] ),
			wppugmill_bot_name( (int) $row['bot_id'] ),
			$labels[ (int) $row['resource_type'] ] ?? 'Unknown',
			$row['url'],
		) );
	}

	fclose( $out );
	exit;
}
add_action( 'wp_ajax_wppugmill_export_csv_recent', 'wppugmill_ajax_export_csv_recent' );

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
	$payload = array(
		'site_id'        => hash( 'sha256', home_url() . wppugmill_instance_id() ),
		'date'           => gmdate( 'Y-m-d', $yesterday * DAY_IN_SECONDS ),
		'plugin_version' => WPPUGMILL_VERSION,
		'aeo_tier'       => $aeo_tier,
		'bots'           => $bots,
	);

	wp_remote_post(
		'https://pugmill.dev/api/ingest',
		array(
			'timeout'     => 10,
			'sslverify'   => true,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload ),
			'blocking'    => false, // fire-and-forget — don't slow down cron
		)
	);
}
add_action( 'wppugmill_intelligence_send', 'wppugmill_intelligence_send' );
