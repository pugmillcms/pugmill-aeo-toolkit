<?php
/**
 * Pugmill AEO Toolkit — Pugmill AEO Intelligence Network: rich signal capture.
 *
 * Captures anonymized, server-side-only signals from known AI bot visits
 * and appends them to the daily intelligence payload sent to aeopugmill.com.
 *
 * No JS injection. No DOM manipulation. No user data. Every signal is either
 * a bucketed count or an aggregate statistic derived from data WordPress
 * already computed to serve the request.
 *
 * Signals captured per bot per day:
 *   content_type      post | page | product | cpt | archive | home | other
 *   content_freshness 0-7d | 8-30d | 31-180d | 180d+  (days since last modified)
 *   word_count        <500 | 500-1500 | 1500+          (raw post content)
 *   url_depth         0–9 (path segment count, capped at 9)
 *   url_type          clean | parameterized
 *   request_type      html | feed | search | 404
 *   http_status       200 | 404
 *   fact_density      low | medium | high               (structured-tag ratio)
 *   bot_searched      count of bot-initiated /?s= searches
 *   search_query_words 1 | 2-3 | 4+                    (word count of query)
 *   php_gen_ms_*      running sum + count for average PHP generation time
 *
 * DB schema: (day, bot, metric, bucket, tally) — v2 added bot dimension.
 *
 * Site-level fields included once per daily payload (cached 24 h):
 *   site_size_tier    <50 | 50-500 | 500-5000 | 5000+
 *   industry          value of aeopugmill_industry option (admin-set)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AEOPUGMILL_SIGNAL_DB_VERSION', '2' );

// ── Static bot flag ────────────────────────────────────────────────────────────

/**
 * Get or set the canonical bot name detected for the current request.
 *
 * @param  string|null $set  Pass a bot name to store it; omit to read.
 * @return string|false
 */
function aeopugmill_intel_current_bot( $set = null ) {
	static $bot = false;
	if ( null !== $set ) {
		$bot = $set;
	}
	return $bot;
}

// ── DB table install ───────────────────────────────────────────────────────────

/**
 * Create the signal_daily table.
 *
 * Schema: one row per (day × metric × bucket). The ON DUPLICATE KEY UPDATE
 * upsert pattern means this table self-deduplicates — no unbounded growth.
 *
 * Max rows ≈ 90 days × ~15 metrics × ~10 buckets ≈ 13,500 rows (~700 KB).
 */
function aeopugmill_signal_install() {
	global $wpdb;

	// v1 → v2: PRIMARY KEY changed to include bot column.
	// dbDelta cannot alter existing PKs so we drop and recreate.
	// Data loss is acceptable — signals re-accumulate within 24 hours.
	if ( '1' === get_option( 'aeopugmill_signal_db_version' ) ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aeopugmill_signal_daily" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	$cc  = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$wpdb->prefix}aeopugmill_signal_daily (
		day         MEDIUMINT UNSIGNED NOT NULL,
		bot         VARCHAR(48)        NOT NULL DEFAULT '',
		metric      VARCHAR(48)        NOT NULL,
		bucket      VARCHAR(32)        NOT NULL DEFAULT '',
		tally       INT UNSIGNED       NOT NULL DEFAULT 1,
		PRIMARY KEY (day, bot, metric, bucket)
	) {$cc};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'aeopugmill_signal_db_version', AEOPUGMILL_SIGNAL_DB_VERSION );
}

/**
 * Lazy-install: run schema creation on plugins_loaded if not up to date.
 */
function aeopugmill_signal_maybe_install() {
	if ( get_option( 'aeopugmill_signal_db_version' ) !== AEOPUGMILL_SIGNAL_DB_VERSION ) {
		aeopugmill_signal_install();
	}
}
add_action( 'plugins_loaded', 'aeopugmill_signal_maybe_install' );

// ── DB write helpers ───────────────────────────────────────────────────────────

/**
 * Increment a tally bucket by 1.
 *
 * @param int    $day
 * @param string $bot
 * @param string $metric
 * @param string $bucket
 */
function aeopugmill_intel_tally( $day, $bot, $metric, $bucket ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}aeopugmill_signal_daily (day, bot, metric, bucket, tally)
		 VALUES (%d, %s, %s, %s, 1)
		 ON DUPLICATE KEY UPDATE tally = tally + 1",
		$day,
		$bot,
		$metric,
		$bucket
	) );
}

/**
 * Add a numeric value to a tally bucket (for running sums, e.g. php_gen_ms).
 *
 * @param int    $day
 * @param string $bot
 * @param string $metric
 * @param string $bucket
 * @param int    $value
 */
function aeopugmill_intel_tally_add( $day, $bot, $metric, $bucket, $value ) {
	global $wpdb;
	$v = (int) $value;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"INSERT INTO {$wpdb->prefix}aeopugmill_signal_daily (day, bot, metric, bucket, tally)
		 VALUES (%d, %s, %s, %s, %d)
		 ON DUPLICATE KEY UPDATE tally = tally + %d",
		$day,
		$bot,
		$metric,
		$bucket,
		$v,
		$v
	) );
}

// ── Init: bot detection + downstream hook registration ────────────────────────

/**
 * Detect the current bot at init priority 100 (just after the existing analytics
 * logger at 99). If a known bot is found, register the template_redirect capture
 * hook and a shutdown function for PHP generation time.
 *
 * Non-bot requests incur zero additional overhead beyond this one UA check.
 */
function aeopugmill_intel_init() {
	if ( is_admin() ) {
		return;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}
	if ( ! get_option( 'aeopugmill_analytics_opted_in' ) ) {
		return; // Honour the opt-in gate — no data leaves without consent.
	}

	$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$bot = aeopugmill_detect_ai_bot( $ua );

	if ( ! $bot ) {
		return;
	}

	aeopugmill_intel_current_bot( $bot );

	// PHP generation time — measured from AEOPUGMILL_REQUEST_START (defined in
	// aeo-pugmill.php at plugin bootstrap) to PHP process shutdown.
	register_shutdown_function( 'aeopugmill_intel_shutdown' );

	// Rich per-request signals — WP query functions are reliable at template_redirect.
	add_action( 'template_redirect', 'aeopugmill_intel_capture', 10 );
}
add_action( 'init', 'aeopugmill_intel_init', 100 );

// ── Signal capture at template_redirect ───────────────────────────────────────

/**
 * Capture rich signals for the current bot request.
 *
 * Runs at template_redirect where all WP conditional tags (is_singular,
 * is_feed, is_404, etc.) are fully reliable.
 */
function aeopugmill_intel_capture() {
	$bot = aeopugmill_intel_current_bot();
	if ( ! $bot ) {
		return;
	}

	$day = (int) floor( time() / DAY_IN_SECONDS );
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	// ── 404 ───────────────────────────────────────────────────────────────
	if ( is_404() ) {
		aeopugmill_intel_tally( $day, $bot, 'http_status',   '404' );
		aeopugmill_intel_tally( $day, $bot, 'request_type',  '404' );
		return;
	}

	// ── Feed (RSS / Atom / JSON feed) ─────────────────────────────────────
	if ( is_feed() ) {
		aeopugmill_intel_tally( $day, $bot, 'http_status',  '200' );
		aeopugmill_intel_tally( $day, $bot, 'request_type', 'feed' );
		return;
	}

	// ── Site search ───────────────────────────────────────────────────────
	if ( is_search() ) {
		aeopugmill_intel_tally( $day, $bot, 'http_status',  '200' );
		aeopugmill_intel_tally( $day, $bot, 'request_type', 'search' );
		aeopugmill_intel_tally( $day, $bot, 'bot_searched', 'true' );
		// Query word count bucket — never log the actual query text.
		$q        = (string) get_search_query( false );
		$q_words  = $q ? str_word_count( $q ) : 0;
		if ( $q_words <= 1 ) {
			$q_bucket = '1';
		} elseif ( $q_words <= 3 ) {
			$q_bucket = '2-3';
		} else {
			$q_bucket = '4+';
		}
		aeopugmill_intel_tally( $day, $bot, 'search_query_words', $q_bucket );
		return;
	}

	// ── Standard HTML request ─────────────────────────────────────────────
	aeopugmill_intel_tally( $day, $bot, 'http_status',  '200' );
	aeopugmill_intel_tally( $day, $bot, 'request_type', 'html' );

	// URL depth (path segment count, capped at 9 to bound the bucket space).
	$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$depth = max( 0, substr_count( rtrim( $path, '/' ), '/' ) );
	aeopugmill_intel_tally( $day, $bot, 'url_depth', (string) min( $depth, 9 ) );

	// URL type: flag non-WP query parameters as "parameterized".
	$qs = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( $qs ) {
		parse_str( $qs, $params );
		$wp_reserved = array_flip( array(
			'p', 'page_id', 's', 'cat', 'tag', 'author', 'paged',
			'page', 'preview', 'm', 'aeopugmill_llm',
		) );
		$url_type = empty( array_diff_key( $params, $wp_reserved ) ) ? 'clean' : 'parameterized';
	} else {
		$url_type = 'clean';
	}
	aeopugmill_intel_tally( $day, $bot, 'url_type', $url_type );

	// ── Non-singular (archives, home, etc.) ──────────────────────────────
	if ( ! is_singular() ) {
		if ( is_archive() ) {
			$content_type = 'archive';
		} elseif ( is_home() || is_front_page() ) {
			$content_type = 'home';
		} else {
			$content_type = 'other';
		}
		aeopugmill_intel_tally( $day, $bot, 'content_type', $content_type );
		return;
	}

	// ── Singular post signals ─────────────────────────────────────────────
	$post = get_queried_object();
	if ( ! ( $post instanceof WP_Post ) ) {
		return;
	}

	// Content type bucket.
	$pt = get_post_type( $post );
	if ( 'post' === $pt || 'page' === $pt || 'product' === $pt ) {
		$ct_bucket = $pt;
	} else {
		$ct_bucket = 'cpt';
	}
	aeopugmill_intel_tally( $day, $bot, 'content_type', $ct_bucket );

	// Content freshness — days since last modified, bucketed.
	$modified = (int) get_post_modified_time( 'U', false, $post );
	$age_days = ( $modified > 0 ) ? max( 0, (int) floor( ( time() - $modified ) / DAY_IN_SECONDS ) ) : 0;
	if ( $age_days <= 7 ) {
		$freshness = '0-7d';
	} elseif ( $age_days <= 30 ) {
		$freshness = '8-30d';
	} elseif ( $age_days <= 180 ) {
		$freshness = '31-180d';
	} else {
		$freshness = '180d+';
	}
	aeopugmill_intel_tally( $day, $bot, 'content_freshness', $freshness );

	// Word count — raw DB field avoids running shortcodes or block rendering.
	$raw     = (string) get_post_field( 'post_content', $post->ID );
	$wc      = str_word_count( wp_strip_all_tags( $raw ) );
	if ( $wc < 500 ) {
		$wc_bucket = '<500';
	} elseif ( $wc <= 1500 ) {
		$wc_bucket = '500-1500';
	} else {
		$wc_bucket = '1500+';
	}
	aeopugmill_intel_tally( $day, $bot, 'word_count', $wc_bucket );

	// Fact density — ratio of structured-data tags to paragraph tags.
	$data_tags = (int) preg_match_all( '/<(?:table|ul|ol|dl)[\s>]/i', $raw );
	$p_tags    = (int) preg_match_all( '/<p[\s>]/i', $raw );
	$total     = $data_tags + $p_tags;
	if ( $total > 0 ) {
		$ratio = $data_tags / $total;
		if ( $ratio >= 0.35 ) {
			$fd = 'high';
		} elseif ( $ratio >= 0.15 ) {
			$fd = 'medium';
		} else {
			$fd = 'low';
		}
		aeopugmill_intel_tally( $day, $bot, 'fact_density', $fd );
	}
}

// ── Shutdown: PHP generation time ─────────────────────────────────────────────

/**
 * Record PHP generation time for this bot request.
 *
 * Stores a running sum and count so the aggregator can compute an average.
 * Values outside a sane range (0–30 s) are silently discarded.
 */
function aeopugmill_intel_shutdown() {
	if ( ! defined( 'AEOPUGMILL_REQUEST_START' ) ) {
		return;
	}
	if ( ! aeopugmill_intel_current_bot() ) {
		return;
	}

	$gen_ms = (int) round( ( microtime( true ) - AEOPUGMILL_REQUEST_START ) * 1000 );
	if ( $gen_ms <= 0 || $gen_ms > 30000 ) {
		return;
	}

	$bot = aeopugmill_intel_current_bot();
	$day = (int) floor( time() / DAY_IN_SECONDS );
	aeopugmill_intel_tally_add( $day, $bot, 'php_gen_ms_sum',   'all', $gen_ms );
	aeopugmill_intel_tally(     $day, $bot, 'php_gen_ms_count', 'all' );
}

// ── Payload builder ────────────────────────────────────────────────────────────

/**
 * Read the signal_daily rows for a given day and return a structured array
 * suitable for inclusion in the intelligence network payload.
 *
 * @param  int   $day  Unix-day integer (floor(time() / DAY_IN_SECONDS)).
 * @return array       Empty array if no data exists for that day.
 */
function aeopugmill_intel_build_signals( $day ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT bot, metric, bucket, tally
		 FROM {$wpdb->prefix}aeopugmill_signal_daily
		 WHERE day = %d",
		$day
	), ARRAY_A );

	if ( empty( $rows ) ) {
		return array();
	}

	// Index by bot → metric → bucket → tally.
	$raw = array();
	foreach ( $rows as $row ) {
		$raw[ $row['bot'] ][ $row['metric'] ][ $row['bucket'] ] = (int) $row['tally'];
	}

	$dist_metrics = array(
		'content_type', 'content_freshness', 'word_count', 'url_depth',
		'url_type', 'request_type', 'http_status', 'fact_density',
		'bot_searched', 'search_query_words',
	);

	$signals = array();

	foreach ( $raw as $bot => $bot_raw ) {
		$bot_signals = array();

		// Distribution metrics — pass through as bucket → count maps.
		foreach ( $dist_metrics as $metric ) {
			if ( ! empty( $bot_raw[ $metric ] ) ) {
				$bot_signals[ $metric ] = $bot_raw[ $metric ];
			}
		}

		// PHP generation time — convert sum + count into average.
		if ( isset( $bot_raw['php_gen_ms_sum']['all'], $bot_raw['php_gen_ms_count']['all'] ) ) {
			$count = (int) $bot_raw['php_gen_ms_count']['all'];
			$sum   = (int) $bot_raw['php_gen_ms_sum']['all'];
			if ( $count > 0 ) {
				$bot_signals['php_gen_ms_avg'] = (int) round( $sum / $count );
				$bot_signals['php_gen_sample'] = $count;
			}
		}

		if ( ! empty( $bot_signals ) ) {
			$signals[ $bot ] = $bot_signals;
		}
	}

	// Site-level static fields — appended once, not per bot.
	$site_meta = aeopugmill_intel_site_meta();
	if ( ! empty( $site_meta['size_tier'] ) ) {
		$signals['_site']['size_tier'] = $site_meta['size_tier'];
	}
	if ( ! empty( $site_meta['industry'] ) ) {
		$signals['_site']['industry'] = $site_meta['industry'];
	}

	return $signals;
}

/**
 * Return per-bot signal distributions aggregated over the last $days days.
 * Used by the Crawl Intelligence UI table on the Bot Analytics page.
 *
 * @param  int   $days  Number of days to look back (default 30).
 * @return array        [ bot => [ metric => [ bucket => tally ] ] ]
 */
function aeopugmill_intel_get_signals_30d( $days = 30 ) {
	global $wpdb;

	$cutoff = (int) floor( ( time() - ( $days * DAY_IN_SECONDS ) ) / DAY_IN_SECONDS );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT bot, metric, bucket, SUM(tally) AS tally
		 FROM {$wpdb->prefix}aeopugmill_signal_daily
		 WHERE day >= %d
		 GROUP BY bot, metric, bucket",
		$cutoff
	), ARRAY_A );

	if ( empty( $rows ) ) {
		return array();
	}

	$out = array();
	foreach ( $rows as $row ) {
		$out[ $row['bot'] ][ $row['metric'] ][ $row['bucket'] ] = (int) $row['tally'];
	}

	return $out;
}

/**
 * Compute and cache site-level metadata used in every daily payload.
 *
 * @return array{size_tier: string, industry: string}
 */
function aeopugmill_intel_site_meta() {
	$cached = get_transient( 'aeopugmill_intel_site_meta' );
	if ( false !== $cached ) {
		return $cached;
	}

	$posts = wp_count_posts( 'post' );
	$pages = wp_count_posts( 'page' );
	$total = (int) ( $posts->publish ?? 0 ) + (int) ( $pages->publish ?? 0 );

	if ( $total < 50 ) {
		$size_tier = '<50';
	} elseif ( $total < 500 ) {
		$size_tier = '50-500';
	} elseif ( $total < 5000 ) {
		$size_tier = '500-5000';
	} else {
		$size_tier = '5000+';
	}

	$meta = array(
		'size_tier' => $size_tier,
		'industry'  => sanitize_text_field( (string) get_option( 'aeopugmill_industry', '' ) ),
	);

	set_transient( 'aeopugmill_intel_site_meta', $meta, DAY_IN_SECONDS );
	return $meta;
}

// ── Intelligence payload filter ────────────────────────────────────────────────

/**
 * Append the signals sub-array to the outbound intelligence payload.
 *
 * Hooked via the 'aeopugmill_intelligence_payload' filter added in
 * bot-analytics.php to both the cron send and the manual-send AJAX handler.
 *
 * @param  array $payload  Existing payload.
 * @param  int   $day      Unix-day integer for the day being reported.
 * @return array
 */
function aeopugmill_intel_filter_payload( $payload, $day ) {
	$signals = aeopugmill_intel_build_signals( $day );
	if ( ! empty( $signals ) ) {
		$payload['signals'] = $signals;
	}
	return $payload;
}
add_filter( 'aeopugmill_intelligence_payload', 'aeopugmill_intel_filter_payload', 10, 2 );

// ── Pruning ────────────────────────────────────────────────────────────────────

/**
 * Prune signal_daily rows older than 90 days.
 * Piggybacked on the existing aeopugmill_daily_prune cron event.
 */
function aeopugmill_intel_prune() {
	global $wpdb;
	$cutoff = (int) floor( ( time() - ( 90 * DAY_IN_SECONDS ) ) / DAY_IN_SECONDS );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->prefix}aeopugmill_signal_daily WHERE day < %d",
		$cutoff
	) );
}
add_action( 'aeopugmill_daily_prune', 'aeopugmill_intel_prune' );
