<?php
/**
 * Admin settings page — Pugmill AEO Toolkit settings under Settings menu.
 *
 * Contains both the Settings tab and the Bot Analytics tab.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue an inline JavaScript fragment via the WordPress script API.
 *
 * Registers a stub handle on first call, then attaches each fragment to it.
 * This routes inline JS through the proper enqueue system so WordPress can
 * apply async/defer/CSP attributes consistently.
 *
 * @param string $js Inline JavaScript (no <script> tags).
 * @return void
 */
function aeopugmill_inline_js( $js ) {
	static $registered = false;
	if ( ! $registered ) {
		wp_register_script( 'aeopugmill-settings-inline', '', array(), AEOPUGMILL_VERSION, true );
		wp_enqueue_script( 'aeopugmill-settings-inline' );
		$registered = true;
	}
	wp_add_inline_script( 'aeopugmill-settings-inline', $js );
}

/**
 * Enqueue an inline CSS fragment via the WordPress style API.
 *
 * Registers a stub handle on first call, then attaches each fragment to it.
 *
 * @param string $css Inline CSS (no <style> tags).
 * @return void
 */
function aeopugmill_inline_css( $css ) {
	static $registered = false;
	if ( ! $registered ) {
		wp_register_style( 'aeopugmill-settings-inline', false, array(), AEOPUGMILL_VERSION );
		wp_enqueue_style( 'aeopugmill-settings-inline' );
		$registered = true;
	}
	wp_add_inline_style( 'aeopugmill-settings-inline', $css );
}

/**
 * Gather plugin conflict and robots.txt advisory data for the settings page.
 *
 * @return array{
 *   json_ld_conflicts: string[],
 *   llms_txt_conflicts: string[],
 *   robots: array{discourage: bool, has_file: bool, blocks_all: bool, blocked_bots: string[]}
 * }
 */
function aeopugmill_get_compatibility_data() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$data = array(
		'json_ld_conflicts'  => array(),
		'llms_txt_conflicts' => array(),
		'sitemap_conflicts'  => array(), // array of [ 'name' => string, 'instruction' => string ]
		'robots_conflicts'   => array(), // array of [ 'name' => string, 'instruction' => string ]
		'rss_conflicts'      => array(), // array of plugin display names modifying the RSS feed
		'robots'             => array(
			'discourage'   => false,
			'has_file'     => false,
			'blocks_all'   => false,
			'blocked_bots' => array(),
		),
	);

	// ── JSON-LD conflicts ─────────────────────────────────────────────────
	$json_ld_plugins = array(
		'wordpress-seo/wp-seo.php'                                                => 'Yoast SEO',
		'seo-by-rank-math/rank-math.php'                                          => 'Rank Math SEO',
		'wp-seopress/seopress.php'                                                => 'SEOPress',
		'all-in-one-seo-pack/all_in_one_seo_pack.php'                             => 'All in One SEO',
		'schema-and-structured-data-for-wp/schema-and-structured-data-for-wp.php' => 'Schema & Structured Data for WP',
	);
	foreach ( $json_ld_plugins as $slug => $name ) {
		if ( is_plugin_active( $slug ) ) {
			$data['json_ld_conflicts'][] = $name;
		}
	}

	// ── llms.txt conflicts ────────────────────────────────────────────────
	// Plugin-slug checks for dedicated llms.txt plugins.
	$llms_plugins = array(
		'llms-txt/llms-txt.php'               => array(
			'name'        => 'LLMs.txt',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve your AEO-enriched /llms.txt — with summaries, Q&A pairs, and entity data — deactivate the LLMs.txt plugin.', 'aeo-pugmill' ),
		),
		'llmstxt/llmstxt.php'                 => array(
			'name'        => 'LLMs.txt',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve your AEO-enriched /llms.txt — with summaries, Q&A pairs, and entity data — deactivate the LLMs.txt plugin.', 'aeo-pugmill' ),
		),
		'ai-llms-txt/ai-llms-txt.php'         => array(
			'name'        => 'AI LLMs.txt',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve your AEO-enriched /llms.txt — with summaries, Q&A pairs, and entity data — deactivate the AI LLMs.txt plugin.', 'aeo-pugmill' ),
		),
		'llms-txt-for-wp/llms-txt-for-wp.php' => array(
			'name'        => 'LLMs.txt for WP',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve your AEO-enriched /llms.txt — with summaries, Q&A pairs, and entity data — deactivate the LLMs.txt for WP plugin.', 'aeo-pugmill' ),
		),
	);
	foreach ( $llms_plugins as $slug => $info ) {
		if ( is_plugin_active( $slug ) ) {
			$data['llms_txt_conflicts'][] = $info;
		}
	}

	// Fetch the live /llms.txt to detect any plugin (e.g. Yoast SEO, Rank Math)
	// that is serving its own version — those won't match the slug list above.
	// Result cached for 1 hour so it doesn't slow every admin page load.
	$llms_url      = home_url( '/llms.txt' );
	$llms_body_key = 'aeopugmill_llms_txt_conflict_check';
	$llms_body     = get_transient( $llms_body_key );
	if ( false === $llms_body ) {
		$response  = wp_remote_get( $llms_url, array( 'timeout' => 4, 'sslverify' => false ) );
		$llms_body = ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 )
			? ''
			: wp_remote_retrieve_body( $response );
		set_transient( $llms_body_key, $llms_body, HOUR_IN_SECONDS );
	}

	// Identify which plugin authored the live /llms.txt (if not ours).
	// Pugmill AEO Toolkit's own output starts with "# {Site Name}" and does NOT contain
	// "Generated by Yoast" or similar attribution lines.
	if ( $llms_body ) {
		$llms_signatures = array(
			'Yoast SEO'  => 'yoast',
			'Rank Math'  => 'rankmath',
			'All in One SEO' => 'aioseo',
			'SEOPress'   => 'seopress',
		);
		foreach ( $llms_signatures as $plugin_name => $keyword ) {
			if ( stripos( $llms_body, $keyword ) !== false && stripos( $llms_body, 'Generated by' ) !== false ) {
				// Don't duplicate a slug-based conflict already added above.
				$already_listed = false;
				foreach ( $data['llms_txt_conflicts'] as $existing ) {
					if ( $existing['name'] === $plugin_name ) {
						$already_listed = true;
						break;
					}
				}
				if ( ! $already_listed ) {
					$data['llms_txt_conflicts'][] = array(
						'name'        => $plugin_name,
						/* translators: %s: plugin name */
						'instruction' => sprintf(
							/* translators: %s: SEO plugin name */
							__( 'If you want Pugmill AEO Toolkit to serve your AEO-enriched /llms.txt — with summaries, Q&A pairs, and entity data — disable the llms.txt feature in %2$s settings. Right now %1$s is generating it without that metadata.', 'aeo-pugmill' ),
							$plugin_name,
							$plugin_name
						),
					);
				}
				break; // Only report one plugin at a time.
			}
		}
	}

	// ── Sitemap conflicts ─────────────────────────────────────────────────
	// Note: WordPress core's built-in sitemap (since 5.5) serves /wp-sitemap.xml —
	// a different URL from Pugmill AEO Toolkit's /sitemap.xml, so it is not a conflict.

	$sitemap_plugins = array(
		'jetpack/jetpack.php'                         => array(
			'name'        => 'Jetpack',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve /sitemap.xml, turn off XML Sitemaps in Jetpack → Settings → Traffic.', 'aeo-pugmill' ),
			'module_check' => function() {
				// Only flag Jetpack if its sitemap module is actually active.
				return class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' )
					? Jetpack::is_module_active( 'sitemaps' )
					: class_exists( 'Jetpack_Sitemap_Manager' );
			},
		),
		'wordpress-seo/wp-seo.php'                    => array(
			'name'        => 'Yoast SEO',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve /sitemap.xml, turn off XML Sitemaps in Yoast SEO → Features.', 'aeo-pugmill' ),
		),
		'seo-by-rank-math/rank-math.php'              => array(
			'name'        => 'Rank Math SEO',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve /sitemap.xml, disable the Sitemap module in Rank Math → Dashboard → Modules.', 'aeo-pugmill' ),
		),
		'all-in-one-seo-pack/all_in_one_seo_pack.php' => array(
			'name'        => 'All in One SEO',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve /sitemap.xml, turn off XML Sitemap in All in One SEO → Sitemaps.', 'aeo-pugmill' ),
		),
		'google-sitemap-generator/sitemap.php'        => array(
			'name'        => 'Google XML Sitemaps',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve /sitemap.xml, deactivate the Google XML Sitemaps plugin.', 'aeo-pugmill' ),
		),
		'xml-sitemap-feed/xml-sitemap.php'            => array(
			'name'        => 'XML Sitemap & Google News',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve /sitemap.xml, deactivate the XML Sitemap & Google News plugin.', 'aeo-pugmill' ),
		),
		'wp-sitemap-page/wp-sitemap-page.php'         => array(
			'name'        => 'WP Sitemap Page',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to serve /sitemap.xml, deactivate the WP Sitemap Page plugin.', 'aeo-pugmill' ),
		),
	);
	foreach ( $sitemap_plugins as $slug => $info ) {
		if ( ! is_plugin_active( $slug ) ) {
			continue;
		}
		// Some plugins (Jetpack) only conflict when a specific module is enabled.
		if ( isset( $info['module_check'] ) && ! call_user_func( $info['module_check'] ) ) {
			continue;
		}
		$data['sitemap_conflicts'][] = array(
			'name'        => $info['name'],
			'instruction' => $info['instruction'],
		);
	}

	// ── Robots.txt filter conflicts ───────────────────────────────────────
	// These plugins hook into robots_txt and add their own Sitemap: directives,
	// resulting in duplicates when Pugmill AEO Toolkit additions are also enabled.
	$robots_plugins = array(
		'jetpack/jetpack.php'                         => array(
			'name'        => 'Jetpack',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to control the Sitemap: line in robots.txt, turn off XML Sitemaps in Jetpack → Settings → Traffic. If you\'d rather keep Jetpack handling it, disable Pugmill AEO Toolkit\'s robots.txt additions below.', 'aeo-pugmill' ),
			// Only warn when the sitemaps module is actually active — Jetpack itself does not add any robots.txt entries otherwise.
			'module_check' => function() {
				return class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' )
					? Jetpack::is_module_active( 'sitemaps' )
					: class_exists( 'Jetpack_Sitemap_Manager' );
			},
		),
		'wordpress-seo/wp-seo.php'                    => array(
			'name'        => 'Yoast SEO',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to control the Sitemap: line in robots.txt, turn off XML Sitemaps in Yoast SEO → Features. If you\'d rather keep Yoast handling it, disable Pugmill AEO Toolkit\'s robots.txt additions below.', 'aeo-pugmill' ),
		),
		'seo-by-rank-math/rank-math.php'              => array(
			'name'        => 'Rank Math SEO',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to control the Sitemap: line in robots.txt, disable the Sitemap module in Rank Math → Dashboard → Modules. If you\'d rather keep Rank Math handling it, disable Pugmill AEO Toolkit\'s robots.txt additions below.', 'aeo-pugmill' ),
		),
		'all-in-one-seo-pack/all_in_one_seo_pack.php' => array(
			'name'        => 'All in One SEO',
			'instruction' => __( 'If you want Pugmill AEO Toolkit to control the Sitemap: line in robots.txt, disable the sitemap feature in All in One SEO → Sitemaps. If you\'d rather keep All in One SEO handling it, disable Pugmill AEO Toolkit\'s robots.txt additions below.', 'aeo-pugmill' ),
		),
	);
	foreach ( $robots_plugins as $slug => $info ) {
		if ( ! is_plugin_active( $slug ) ) {
			continue;
		}
		// Some plugins (Jetpack) only add robots.txt directives when a specific module is on.
		if ( isset( $info['module_check'] ) && ! call_user_func( $info['module_check'] ) ) {
			continue;
		}
		$data['robots_conflicts'][] = array(
			'name'        => $info['name'],
			'instruction' => $info['instruction'],
		);
	}

	// ── robots.txt analysis ───────────────────────────────────────────────
	$data['robots']['discourage'] = ( '0' === get_option( 'blog_public' ) );

	$ai_bots     = array( 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'anthropic-ai', 'cohere-ai', 'ChatGPT-User', 'OAI-SearchBot' );
	$robots_file = ABSPATH . 'robots.txt';

	if ( file_exists( $robots_file ) ) {
		$data['robots']['has_file'] = true;
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		$content = $wp_filesystem->get_contents( $robots_file );

		// Split into directive blocks (blank-line separated)
		$blocks = preg_split( '/\n\s*\n/', $content );
		foreach ( $blocks as $block ) {
			$agents            = array();
			$has_disallow_root = false;
			foreach ( preg_split( '/\n/', $block ) as $line ) {
				$line = trim( $line );
				if ( preg_match( '/^User-agent:\s*(.+)$/i', $line, $m ) ) {
					$agents[] = trim( $m[1] );
				}
				if ( preg_match( '/^Disallow:\s*\/\s*$/i', $line ) ) {
					$has_disallow_root = true;
				}
			}
			if ( $has_disallow_root ) {
				if ( in_array( '*', $agents, true ) ) {
					$data['robots']['blocks_all'] = true;
				}
				foreach ( $ai_bots as $bot ) {
					if ( in_array( $bot, $agents, true ) ) {
						$data['robots']['blocked_bots'][] = $bot;
					}
				}
			}
		}
	}

	// ── RSS enrichment conflicts ──────────────────────────────────────────
	// Purely informational — our enrichment is additive (new namespace elements)
	// so there is no XML conflict, but the admin should know both are active.
	$rss_plugins = aeopugmill_detected_rss_plugins();
	foreach ( $rss_plugins as $display_name ) {
		$data['rss_conflicts'][] = $display_name;
	}

	return $data;
}

/**
 * Generate a short preview of what Pugmill AEO Toolkit's sitemap.xml output looks like.
 * Returns the XML string (first 4 URL entries) for display in the settings UI.
 *
 * @return string
 */
function aeopugmill_preview_sitemap_xml() {
	if ( ! function_exists( 'aeopugmill_sitemap_collect_urls' ) || ! function_exists( 'aeopugmill_own_noindex' ) ) {
		return '<!-- Sitemap generator not available -->';
	}
	$urls   = array_slice( aeopugmill_sitemap_collect_urls(), 0, 4 );
	$lines  = array();
	$lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
	$lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	foreach ( $urls as $entry ) {
		$lines[] = '  <url>';
		$lines[] = '    <loc>' . esc_html( $entry['loc'] ) . '</loc>';
		$lines[] = '    <lastmod>' . esc_html( $entry['lastmod'] ) . '</lastmod>';
		$lines[] = '    <changefreq>' . esc_html( $entry['changefreq'] ) . '</changefreq>';
		$lines[] = '    <priority>' . esc_html( $entry['priority'] ) . '</priority>';
		$lines[] = '  </url>';
	}
	$lines[] = '  <!-- ... more entries ... -->';
	$lines[] = '</urlset>';
	return implode( "\n", $lines );
}

/**
 * Generate a short llms.txt preview (header + first 3 post links).
 * Checks the cached transient first; falls back to a minimal inline build.
 *
 * @return string
 */
function aeopugmill_preview_llms_txt_snippet() {
	// Try the cached full version — pull first 20 lines.
	$cached = get_transient( 'aeopugmill_llms_txt' );
	if ( $cached ) {
		$lines = explode( "\n", $cached );
		return implode( "\n", array_slice( $lines, 0, 20 ) ) . "\n# … (truncated)";
	}

	// Build a minimal preview on the fly.
	$site_title = get_bloginfo( 'name' );
	$site_desc  = get_bloginfo( 'description' );
	$lines      = array();
	$lines[]    = '# ' . $site_title;
	if ( $site_desc ) {
		$lines[] = '> ' . $site_desc;
	}
	$lines[] = '';

	$posts = get_posts( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 3,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
	if ( $posts ) {
		$lines[] = '## Posts';
		foreach ( $posts as $post ) {
			$lines[] = '- [' . esc_html( $post->post_title ) . '](' . get_permalink( $post->ID ) . ')';
		}
	}
	$lines[] = '';
	$lines[] = '# … (truncated — visit /llms.txt for full file)';
	return implode( "\n", $lines );
}

/**
 * Return the lines that Pugmill AEO Toolkit would append to robots.txt (Sitemap + LLMs-Txt directives).
 *
 * @return string
 */
function aeopugmill_preview_robots_additions() {
	$lines   = array();
	$lines[] = "\nSitemap: " . home_url( '/sitemap.xml' );
	$lines[] = '';
	$lines[] = '# AI content index';
	$lines[] = 'LLMs-Txt: ' . home_url( '/llms.txt' );
	return implode( "\n", $lines );
}

/**
 * Remove third-party admin notices from Pugmill AEO Toolkit's own settings page.
 * Some plugins (e.g. AIOSEO) inject upsell banners into every admin page via
 * admin_notices / all_admin_notices. Strip them so they don't appear on ours.
 */
function aeopugmill_suppress_foreign_notices() {
	$screen = get_current_screen();
	if ( $screen && $screen->id === 'settings_page_aeo-pugmill' ) {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}
}
add_action( 'admin_head', 'aeopugmill_suppress_foreign_notices', 1 );

function aeopugmill_add_settings_page() {
	add_menu_page(
		__( 'Pugmill AEO Toolkit Settings', 'aeo-pugmill' ),
		__( 'Pugmill AEO', 'aeo-pugmill' ),
		'manage_options',
		'aeo-pugmill',
		'aeopugmill_render_settings_page',
		'dashicons-admin-site-alt3',
		80
	);
}
add_action( 'admin_menu', 'aeopugmill_add_settings_page' );

// Fire intelligence send whenever the admin visits the settings page — this
// catches any days the wp-cron didn't fire due to low traffic.
add_action( 'load-settings_page_aeo-pugmill', 'aeopugmill_intelligence_send' );

/**
 * Enqueue shared plugin CSS on the Pugmill AEO Toolkit settings page so the
 * barber pole loading animation (.aeopugmill-loading) is available.
 *
 * @param string $hook Current admin page hook suffix.
 */
function aeopugmill_enqueue_settings_assets( $hook ) {
	if ( 'settings_page_aeo-pugmill' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'aeopugmill-editor-resize',
		AEOPUGMILL_PLUGIN_URL . 'admin/css/editor-resize.css',
		array(),
		AEOPUGMILL_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'aeopugmill_enqueue_settings_assets' );

function aeopugmill_render_settings_page() {
	$is_pro_active  = defined( 'AEOPUGMILL_PRO_ACTIVE' ) && AEOPUGMILL_PRO_ACTIVE;
	$api_key        = aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' );
	$has_api_key    = ! empty( $api_key );
	$mode           = $is_pro_active ? 'ai' : ( $has_api_key ? 'ai' : 'free' );

	// Detect active tab — default is 'dashboard'
	$allowed_tabs = array( 'dashboard', 'site-aeo', 'audit-aeo', 'bulk-aeo', 'compatibility' );
	$active_tab   = isset( $_GET['tab'] ) && in_array( sanitize_key( $_GET['tab'] ), $allowed_tabs, true ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		? sanitize_key( $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: 'dashboard';

	// Shared styles
	$h2_style      = 'font-size:14px; font-weight:600; color:#1d2327; padding-bottom:10px; border-bottom:1px solid #ddd; margin:28px 0 16px;';
	$p_style       = 'color:#555; font-size:13px; max-width:760px; margin:0 0 14px; line-height:1.6;';
	$card_style    = 'background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:16px;';
	$section_label = 'font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:0 0 12px;';

	// Helper: tab URL
	$tab_url = function( $tab ) {
		$base = admin_url( 'options-general.php?page=aeo-pugmill' );
		return 'dashboard' === $tab ? $base : $base . '&tab=' . rawurlencode( $tab );
	};
	?>
	<div class="wrap">

		<!-- ── Page header ──────────────────────────────────────────── -->
		<div style="display:flex; align-items:center; gap:12px; margin-bottom:4px; margin-top:16px;">
			<img src="<?php echo esc_url( AEOPUGMILL_PLUGIN_URL . 'assets/pugmill-logo.svg' ); ?>"
				alt="Pugmill AEO Toolkit"
				width="36" height="36"
				style="display:block; flex-shrink:0;">
			<h1 style="margin:0; padding:0; line-height:1;"><?php esc_html_e( 'Pugmill AEO Toolkit Settings', 'aeo-pugmill' ); ?> <span style="font-size:13px; font-weight:normal; color:#666;">v<?php echo esc_html( AEOPUGMILL_VERSION ); ?></span></h1>
		</div>

		<!-- ── Tab navigation ──────────────────────────────────────── -->
		<nav class="nav-tab-wrapper" style="margin-top:16px;">
			<?php
			$tabs = array(
				'dashboard'     => __( 'Dashboard', 'aeo-pugmill' ),
				'site-aeo'      => __( 'Site AEO', 'aeo-pugmill' ),
				'audit-aeo'     => __( 'Audit AEO', 'aeo-pugmill' ),
				'bulk-aeo'      => __( 'Bulk AEO', 'aeo-pugmill' ),
				'compatibility' => __( 'Compatibility', 'aeo-pugmill' ),
			);
			foreach ( $tabs as $tab_id => $tab_label ) :
			?>
			<a href="<?php echo esc_url( $tab_url( $tab_id ) ); ?>"
				class="nav-tab<?php echo $active_tab === $tab_id ? ' nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab_label ); ?>
			</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( 'dashboard' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     DASHBOARD TAB
		     ════════════════════════════════════════════════════════════ -->

		<?php
		// ── Stale intelligence notice ──────────────────────────────────────────
		if ( get_option( 'aeopugmill_analytics_opted_in' ) ) :
			$_last_day  = (int) get_option( 'aeopugmill_last_sent_day', 0 );
			$_yesterday = (int) floor( ( time() - DAY_IN_SECONDS ) / DAY_IN_SECONDS );
			$_days_late = $_last_day > 0 ? ( $_yesterday - $_last_day ) : 0;
			if ( $_days_late >= 2 ) : ?>
		<div id="aeopugmill-stale-notice" style="background:#fffbeb; border:1px solid #fcd34d; border-left:4px solid #f59e0b; border-radius:6px; padding:12px 16px; margin-bottom:16px; display:flex; align-items:center; justify-content:space-between; gap:12px; font-size:13px;">
			<span style="color:#92400e;">
				<?php printf(
					/* translators: %d: number of days since last intelligence send */
					esc_html__( 'Intelligence data is %d day(s) behind \u2014 wp-cron may not have fired on this site.', 'aeo-pugmill' ),
					(int) $_days_late
				); ?>
			</span>
			<span style="white-space:nowrap;">
				<a href="#" id="aeopugmill-stale-send-link" style="color:#7c3aed; font-weight:600; text-decoration:underline;"><?php esc_html_e( 'Send now', 'aeo-pugmill' ); ?></a>
				<span id="aeopugmill-stale-send-result" style="margin-left:6px; font-weight:700;"></span>
			</span>
		</div>
		<?php ob_start(); ?>
		(function() {
			var link   = document.getElementById( 'aeopugmill-stale-send-link' );
			var result = document.getElementById( 'aeopugmill-stale-send-result' );
			var notice = document.getElementById( 'aeopugmill-stale-notice' );
			if ( ! link ) return;
			link.addEventListener( 'click', function( e ) {
				e.preventDefault();
				link.style.pointerEvents = 'none'; link.style.opacity = '0.5';
				link.textContent = '<?php echo esc_js( __( 'Sendingâ¦', 'aeo-pugmill' ) ); ?>';
				var data = new FormData();
				data.append( 'action', 'aeopugmill_manual_send' );
				data.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'aeopugmill_manual_send' ) ); ?>' );
				fetch( ajaxurl, { method: 'POST', body: data } )
					.then( function( r ) { return r.json(); } )
					.then( function( json ) {
						if ( json.success ) {
							result.textContent = 'â'; result.style.color = '#16a34a';
							setTimeout( function() { if (notice) notice.style.display = 'none'; }, 1500 );
						} else {
							result.textContent = 'â'; result.style.color = '#dc2626';
							link.style.pointerEvents = ''; link.style.opacity = '1';
							link.textContent = '<?php echo esc_js( __( 'Retry', 'aeo-pugmill' ) ); ?>';
						}
					} ).catch( function() {
						result.textContent = 'â'; result.style.color = '#dc2626';
						link.style.pointerEvents = ''; link.style.opacity = '1';
						link.textContent = '<?php echo esc_js( __( 'Retry', 'aeo-pugmill' ) ); ?>';
					} );
			} );
		})();
		<?php aeopugmill_inline_js( ob_get_clean() ); ?>
		<?php endif; // days_late ?>
		<?php endif; // opted_in ?>

		<!-- ── Setup Cards ─────────────────────────────────────── -->
		<?php
		$saved_provider  = ! empty( $api_key ) ? get_option( 'aeopugmill_ai_provider', '' ) : '';
		$provider_labels = array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)', 'gemini' => 'Google Gemini' );
		$provider_name   = $provider_labels[ $saved_provider ] ?? '';
		$has_voice       = (bool) get_option( 'aeopugmill_author_voice', '' );
		$voice_text      = get_option( 'aeopugmill_author_voice', '' );
		$social_lines    = array_filter( array_map( 'trim', explode( "\n", get_option( 'aeopugmill_author_same_as', '' ) ) ) );
		$social_count    = count( $social_lines );
		?>
		<?php $settings_force_open = ( empty( $api_key ) && ! $has_voice && 'free' === $mode ) ? '1' : '0'; ?>
		<div class="aeo-setup-card" data-card="settings" data-force-open="<?php echo esc_attr( $settings_force_open ); ?>" style="background:#fff; border:1px solid #ddd; border-radius:8px; overflow:hidden; margin-top:24px;">
			<div class="aeo-setup-header" style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; cursor:pointer; user-select:none;">
				<div style="display:flex; align-items:center; gap:8px;">
					<span class="aeo-chevron" style="font-size:11px; color:#9ca3af; transition:transform .15s;">&#9660;</span>
					<span style="font-size:14px; font-weight:600; color:#1d2327;"><?php esc_html_e( 'Preferences', 'aeo-pugmill' ); ?></span>
					<span style="display:flex; align-items:center; gap:10px;">
						<?php if ( $api_key ) : ?>
						<span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#16a34a;">&#10003; <?php esc_html_e( 'AI Connected', 'aeo-pugmill' ); ?></span>
						<?php else : ?>
						<span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#d97706;">&#9675; <?php esc_html_e( 'AI Not Connected', 'aeo-pugmill' ); ?></span>
						<?php endif; ?>
						<?php if ( $has_voice ) : ?>
						<span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#16a34a;">&#10003; <?php esc_html_e( 'Voice Set', 'aeo-pugmill' ); ?></span>
						<?php else : ?>
						<span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#d97706;">&#9675; <?php esc_html_e( 'No Voice', 'aeo-pugmill' ); ?></span>
						<?php endif; ?>
						<?php if ( $is_pro_active ) : ?>
						<span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#16a34a;">&#10003; <?php esc_html_e( 'Pro', 'aeo-pugmill' ); ?></span>
						<?php else : ?>
						<span style="font-size:10px; font-weight:400; text-transform:uppercase; letter-spacing:.05em; color:#d1d5db;">&#9675; <?php esc_html_e( 'Pro inactive', 'aeo-pugmill' ); ?></span>
						<?php endif; ?>
					</span>
				</div>
			</div>
			<div class="aeo-setup-body" style="border-top:1px solid #f0f0f0;">
		<div class="pugmill-prefs-grid">

			<!-- Card 1 — AI Provider -->
			<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:14px 16px;">
				<div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
					<span style="font-size:13px; font-weight:600; color:#1d2327;"><?php esc_html_e( 'AI Provider', 'aeo-pugmill' ); ?></span>
				</div>
					<?php if ( $api_key ) : ?>
					<p style="font-size:12px; color:#6b7280; margin:10px 0 8px;"><?php echo esc_html( $provider_name ); ?></p>
					<details style="font-size:12px;">
						<summary style="cursor:pointer; color:#7c3aed; font-weight:600; font-size:11px;"><?php esc_html_e( 'Change provider or key', 'aeo-pugmill' ); ?></summary>
						<div style="margin-top:12px;">
							<form method="post" action="options.php">
								<?php settings_fields( 'aeopugmill_settings' ); ?>
								<label style="display:block; font-size:11px; font-weight:600; color:#374151; margin-bottom:4px;"><?php esc_html_e( 'Provider', 'aeo-pugmill' ); ?></label>
								<select id="aeopugmill_ai_provider" name="aeopugmill_ai_provider" style="width:100%; margin-bottom:8px;">
									<option value=""><?php esc_html_e( '— Select —', 'aeo-pugmill' ); ?></option>
									<?php foreach ( array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)', 'gemini' => 'Google Gemini' ) as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_provider, $val ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<label style="display:block; font-size:11px; font-weight:600; color:#374151; margin-bottom:4px;"><?php esc_html_e( 'API Key', 'aeo-pugmill' ); ?></label>
								<input type="password" id="aeopugmill_ai_api_key" name="aeopugmill_ai_api_key" value="<?php echo esc_attr( aeopugmill_mask_secret( $api_key ) ); ?>" style="width:100%; margin-bottom:4px;" placeholder="sk-...">
								<input type="hidden" name="aeopugmill_api_key_changed" id="aeopugmill_api_key_changed" value="0">
								<div style="display:flex; gap:8px; align-items:center; margin-top:6px;">
									<?php submit_button( __( 'Save', 'aeo-pugmill' ), 'small', 'submit', false ); ?>
									<a class="aeopugmill-provider-link" href="#" target="_blank" style="display:none; font-size:11px; color:#7c3aed;"><?php esc_html_e( 'Manage account â', 'aeo-pugmill' ); ?></a>
								</div>
							</form>
						</div>
					</details>
					<?php else : ?>
					<p style="font-size:12px; color:#6b7280; margin:10px 0 10px;"><?php esc_html_e( 'Connect Anthropic, OpenAI, or Google Gemini to enable AI generation.', 'aeo-pugmill' ); ?></p>
					<form method="post" action="options.php">
						<?php settings_fields( 'aeopugmill_settings' ); ?>
						<label style="display:block; font-size:11px; font-weight:600; color:#374151; margin-bottom:4px;"><?php esc_html_e( 'Provider', 'aeo-pugmill' ); ?></label>
						<select id="aeopugmill_ai_provider" name="aeopugmill_ai_provider" style="width:100%; margin-bottom:8px;">
							<option value=""><?php esc_html_e( '— Select —', 'aeo-pugmill' ); ?></option>
							<?php foreach ( array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)', 'gemini' => 'Google Gemini' ) as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_provider, $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label style="display:block; font-size:11px; font-weight:600; color:#374151; margin-bottom:4px;"><?php esc_html_e( 'API Key', 'aeo-pugmill' ); ?></label>
						<input type="password" id="aeopugmill_ai_api_key" name="aeopugmill_ai_api_key" value="" style="width:100%; margin-bottom:4px;" placeholder="sk-...">
						<input type="hidden" name="aeopugmill_api_key_changed" id="aeopugmill_api_key_changed" value="0">
						<div style="display:flex; gap:8px; align-items:center; margin-top:6px;">
							<?php submit_button( __( 'Save', 'aeo-pugmill' ), 'small', 'submit', false ); ?>
							<a class="aeopugmill-provider-link" href="#" target="_blank" style="display:none; font-size:11px; color:#7c3aed;"><?php esc_html_e( 'Get a key →', 'aeo-pugmill' ); ?></a>
						</div>
					</form>
					<?php endif; ?>
			</div>

			<!-- Card 2 — Author Voice -->
			<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:14px 16px;">
				<div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
					<span style="font-size:13px; font-weight:600; color:#1d2327;"><?php esc_html_e( 'Author Voice', 'aeo-pugmill' ); ?></span>
				</div>
					<?php if ( $has_voice ) : ?>
					<p style="font-size:12px; color:#6b7280; margin:10px 0 2px; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;"><?php echo esc_html( $voice_text ); ?></p>
					<?php if ( $social_count > 0 ) : ?>
					<p style="font-size:11px; color:#9ca3af; margin:4px 0 8px;"><?php /* translators: %d: number of social profiles */ printf( esc_html( _n( '%d social profile', '%d social profiles', $social_count, 'aeo-pugmill' ) ), (int) $social_count ); ?></p>
					<?php else : ?>
					<p style="font-size:11px; color:#d97706; margin:4px 0 8px;"><?php esc_html_e( 'No social profiles added', 'aeo-pugmill' ); ?></p>
					<?php endif; ?>
					<details style="font-size:12px;">
						<summary style="cursor:pointer; color:#7c3aed; font-weight:600; font-size:11px;"><?php esc_html_e( 'Edit voice & socials', 'aeo-pugmill' ); ?></summary>
						<div style="margin-top:12px;">
							<form method="post" action="options.php">
								<?php settings_fields( 'aeopugmill_settings' ); ?>
								<label style="display:block; font-size:11px; font-weight:600; color:#374151; margin-bottom:4px;"><?php esc_html_e( 'Voice Guide', 'aeo-pugmill' ); ?></label>
								<textarea name="aeopugmill_author_voice" rows="4" style="width:100%; font-size:12px; margin-bottom:8px;" placeholder="<?php echo esc_attr__( 'Describe your writing tone, audience, style…', 'aeo-pugmill' ); ?>"><?php echo esc_textarea( $voice_text ); ?></textarea>
								<label style="display:block; font-size:11px; font-weight:600; color:#374151; margin-bottom:4px;"><?php esc_html_e( 'Social Profiles (one URL per line)', 'aeo-pugmill' ); ?></label>
								<textarea name="aeopugmill_author_same_as" rows="3" style="width:100%; font-size:12px; margin-bottom:6px;" placeholder="https://twitter.com/you&#10;https://linkedin.com/in/you"><?php echo esc_textarea( get_option( 'aeopugmill_author_same_as', '' ) ); ?></textarea>
								<?php submit_button( __( 'Save', 'aeo-pugmill' ), 'small', 'submit', false ); ?>
							</form>
						</div>
					</details>
					<?php else : ?>
					<p style="font-size:12px; color:#6b7280; margin:10px 0 10px;"><?php esc_html_e( 'Describe your writing style so AI-generated content matches your voice.', 'aeo-pugmill' ); ?></p>
					<form method="post" action="options.php">
						<?php settings_fields( 'aeopugmill_settings' ); ?>
						<label style="display:block; font-size:11px; font-weight:600; color:#374151; margin-bottom:4px;"><?php esc_html_e( 'Voice Guide', 'aeo-pugmill' ); ?></label>
						<textarea name="aeopugmill_author_voice" rows="4" style="width:100%; font-size:12px; margin-bottom:8px;" placeholder="<?php echo esc_attr__( 'Describe your writing tone, audience, style…', 'aeo-pugmill' ); ?>"></textarea>
						<label style="display:block; font-size:11px; font-weight:600; color:#374151; margin-bottom:4px;"><?php esc_html_e( 'Social Profiles (one URL per line)', 'aeo-pugmill' ); ?></label>
						<textarea name="aeopugmill_author_same_as" rows="3" style="width:100%; font-size:12px; margin-bottom:6px;" placeholder="https://twitter.com/you&#10;https://linkedin.com/in/you"></textarea>
						<?php submit_button( __( 'Save', 'aeo-pugmill' ), 'small', 'submit', false ); ?>
					</form>
					<?php endif; ?>
			</div>

			<!-- Card 3 — Pugmill AEO Toolkit Pro (informational) -->
			<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:14px 16px;">
				<div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
					<span style="font-size:13px; font-weight:600; color:#1d2327;"><?php esc_html_e( 'Pugmill AEO Toolkit Pro', 'aeo-pugmill' ); ?></span>
				</div>
				<p style="font-size:12px; color:#6b7280; margin:0 0 10px; line-height:1.5;"><?php esc_html_e( 'One-click Generate AEO, Tone Check, Bulk AEO, Internal Links, Social Drafts, and more.', 'aeo-pugmill' ); ?></p>
				<a href="https://aeopugmill.com/pricing" target="_blank" rel="noopener noreferrer"
					style="display:inline-block; font-size:11px; font-weight:600; color:#fff; background:#7c3aed; border-radius:9999px; padding:4px 14px; text-decoration:none;">
					<?php esc_html_e( 'Get Pugmill AEO Toolkit Pro →', 'aeo-pugmill' ); ?>
				</a>
			</div>

		</div><!-- /setup cards grid -->
		</div><!-- /aeo-setup-body -->
		</div><!-- /settings card -->
		<?php ob_start(); ?>
		/* Toggle setup card open/closed, persist to localStorage */
		function aeoToggleCard( header ) {
			var card = header.closest( '.aeo-setup-card' );
			var body = card.querySelector( '.aeo-setup-body' );
			var chev = card.querySelector( '.aeo-chevron' );
			var key  = 'aeo_card_' + card.dataset.card;
			var open = body.style.display !== 'none';
			body.style.display = open ? 'none' : '';
			chev.style.transform = open ? 'rotate(-90deg)' : '';
			try { localStorage.setItem( key, open ? '0' : '1' ); } catch(e) {}
		}
		/* Attach toggle click handlers to all setup card headers */
		(function() {
			document.querySelectorAll( '.aeo-setup-header' ).forEach( function( header ) {
				header.addEventListener( 'click', function() { aeoToggleCard( header ); } );
			} );
		})();
		/* Restore saved state on load — respect data-force-open for fresh installs */
		(function() {
			var cards = document.querySelectorAll( '.aeo-setup-card' );
			cards.forEach( function( card ) {
				var key  = 'aeo_card_' + card.dataset.card;
				var body = card.querySelector( '.aeo-setup-body' );
				var chev = card.querySelector( '.aeo-chevron' );
				/* If nothing is configured yet, keep open regardless of stale localStorage */
				if ( card.dataset.forceOpen === '1' ) {
					try { localStorage.removeItem( key ); } catch(e) {}
					return;
				}
				try {
					var saved = localStorage.getItem( key );
					if ( saved === '0' ) {
						body.style.display = 'none';
						chev.style.transform = 'rotate(-90deg)';
					}
				} catch(e) {}
			});
		})();
		/* AI Provider form — flag key changes + disable Save until both fields filled */
		(function() {
			var keyField  = document.getElementById( 'aeopugmill_ai_api_key' );
			var flagField = document.getElementById( 'aeopugmill_api_key_changed' );
			var provSel   = document.getElementById( 'aeopugmill_ai_provider' );
			if ( ! keyField || ! flagField ) return;

			/* Mark key as changed on any user interaction */
			[ 'input', 'change', 'keyup', 'paste' ].forEach( function( evt ) {
				keyField.addEventListener( evt, function() { flagField.value = '1'; } );
			} );

			/* Find the submit button in the same form */
			var form = keyField.closest( 'form' );
			var btn  = form ? form.querySelector( 'input[type="submit"], button[type="submit"]' ) : null;

			function checkCanSave() {
				if ( ! btn ) return;
				var provOk = ! provSel || ( provSel.value !== '' );
				var keyOk  = keyField.value.trim().length > 0;
				btn.disabled = ! ( provOk && keyOk );
				btn.style.opacity = btn.disabled ? '0.5' : '1';
			}

			/* Safety net: on form submit, if key is non-empty and flag is still 0, set it */
			if ( form ) {
				form.addEventListener( 'submit', function() {
					if ( keyField.value.trim().length > 0 && flagField.value === '0' ) {
						flagField.value = '1';
					}
				} );
			}

			/* Listen for changes on both fields */
			if ( provSel ) {
				provSel.addEventListener( 'change', checkCanSave );
			}
			keyField.addEventListener( 'input', checkCanSave );
			keyField.addEventListener( 'paste', function() { setTimeout( checkCanSave, 0 ); } );

			/* Initial state */
			checkCanSave();
		})();

		/* AI Provider — update "manage / get a key" links when provider changes */
		(function() {
			var PROVIDER_URLS = {
				anthropic: 'https://console.anthropic.com/settings/keys',
			openai: 'https://platform.openai.com/api-keys',
				gemini: 'https://aistudio.google.com/app/apikey'
			};
			var provSel = document.getElementById( 'aeopugmill_ai_provider' );
			function syncProviderLinks() {
				var prov = provSel ? provSel.value : '';
				var url  = PROVIDER_URLS[ prov ] || '#';
				document.querySelectorAll( '.aeopugmill-provider-link' ).forEach( function( el ) {
					el.href = url;
					el.style.display = prov ? '' : 'none';
				} );
			}
			if ( provSel ) {
				provSel.addEventListener( 'change', syncProviderLinks );
			}
			syncProviderLinks();
		})();
		<?php aeopugmill_inline_js( ob_get_clean() ); ?>

		<!-- ── Bot Analytics (inline in dashboard) ──────────────── -->

<?php if ( ! get_option( 'aeopugmill_analytics_opted_in' ) ) : ?>
		<!-- Top opt-in CTA — visible while not joined to the network -->
		<div style="margin-top:24px; background:#faf5ff; border:1px solid #e9d5ff; border-radius:8px; padding:18px 22px; display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap;">
			<div style="flex:1; min-width:260px;">
				<p style="margin:0 0 4px; font-size:14px; font-weight:700; color:#6b21a8;">
					📡 <?php esc_html_e( 'Unlock Network Benchmarks', 'aeo-pugmill' ); ?>
				</p>
				<p style="margin:0; font-size:12px; color:#374151; line-height:1.5;">
					<?php esc_html_e( 'Compare your bot traffic to other Pugmill AEO Toolkit sites and discover crawlers your peers are seeing. Free — opt in to share aggregate visit counts with the Pugmill AEO Intelligence Network.', 'aeo-pugmill' ); ?>
				</p>
			</div>
			<form method="post" action="options.php" style="margin:0; flex-shrink:0;">
				<?php settings_fields( 'aeopugmill_analytics' ); ?>
				<input type="hidden" name="aeopugmill_analytics_opted_in" value="1">
				<?php submit_button( __( 'Join the Network', 'aeo-pugmill' ), 'primary', 'submit', false, array( 'style' => 'background:#7c3aed; border-color:#7c3aed; font-size:13px; height:34px; padding:0 18px;' ) ); ?>
			</form>
		</div>
		<?php endif; ?>
		<?php
		$days            = 30;
		$summary         = aeopugmill_bot_analytics_summary( $days );
		$daily           = aeopugmill_bot_analytics_daily( $days );
		$total           = aeopugmill_bot_analytics_total();
		$by_resource     = aeopugmill_bot_analytics_by_resource( $days );
		$resource_labels = aeopugmill_resource_type_labels();
		$resource_cats   = aeopugmill_resource_type_categories();
		$all_bots        = aeopugmill_bot_config();
		$intel_signals   = function_exists( 'aeopugmill_intel_get_signals_30d' ) ? aeopugmill_intel_get_signals_30d( $days ) : array();

		// Build the renderable bot set for the selected period. Known bots
		// come with curated label/color/category. Any bot_name in $summary
		// not found in the known config is an unclassified crawler — we
		// fabricate a display entry for it so it renders in the "Other"
		// category instead of being silently dropped (v4: classify, don't
		// filter — identity is preserved end-to-end).
		$bots = array();
		foreach ( $summary as $bot_key => $count ) {
			if ( (int) $count <= 0 ) {
				continue;
			}
			if ( isset( $all_bots[ $bot_key ] ) ) {
				$bots[ $bot_key ] = $all_bots[ $bot_key ];
			} else {
				$bots[ $bot_key ] = array(
					'label'    => $bot_key,
					'color'    => '#94a3b8',
					'type'     => 'other',
					'category' => 'other',
				);
			}
		}

		$ai_bots       = array_filter( $bots, function( $b ) { return 'ai'       === ( $b['category'] ?? $b['type'] ); } );
		$training_bots = array_filter( $bots, function( $b ) { return 'training' === ( $b['category'] ?? $b['type'] ); } );
		$search_bots   = array_filter( $bots, function( $b ) { return 'search'   === ( $b['category'] ?? $b['type'] ); } );
		$seo_bots      = array_filter( $bots, function( $b ) { return 'seo'      === ( $b['category'] ?? $b['type'] ); } );
		$other_bots    = array_filter( $bots, function( $b ) { return 'other'    === ( $b['category'] ?? $b['type'] ); } );

		// 30-day totals per category
		$ai_total_30       = 0;
		$training_total_30 = 0;
		$search_total_30   = 0;
		$seo_total_30      = 0;
		foreach ( $ai_bots       as $k => $_ ) { $ai_total_30       += $summary[ $k ] ?? 0; }
		foreach ( $training_bots as $k => $_ ) { $training_total_30 += $summary[ $k ] ?? 0; }
		foreach ( $search_bots   as $k => $_ ) { $search_total_30   += $summary[ $k ] ?? 0; }
		foreach ( $seo_bots      as $k => $_ ) { $seo_total_30      += $summary[ $k ] ?? 0; }
		$insights_nonce  = wp_create_nonce( 'aeopugmill_analytics_insights' );
		$export_nonce    = wp_create_nonce( 'aeopugmill_export_csv' );
		$cached_insights = get_transient( 'aeopugmill_ai_analytics_insights' );
		$has_api_key     = ! empty( aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' ) );

		// ── AEO content coverage (for donut chart) ────────────────────────────────
		// Uses COUNT(*) for the total (no LIMIT) and a single JOIN to fetch all AEO
		// meta rows, so the numbers match what Bulk AEO reports on large sites.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Static SQL with no user input; literal table names and constants only.
		$cov_total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			 AND post_type IN ('post','page')"
		);
		$cov_full            = 0;
		$cov_partial         = 0;
		$cov_field_summary   = 0;
		$cov_field_questions = 0;
		$cov_field_entities  = 0;
		$cov_field_keywords  = 0;
		if ( $cov_total > 0 ) {
			// Single JOIN — no LIMIT — accurate across all published posts.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Static SQL with no user input; literal table names and a hard-coded meta_key.
			$cov_rows = $wpdb->get_results(
				"SELECT pm.meta_value
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_aeopugmill_aeo'
				 AND p.post_status = 'publish'
				 AND p.post_type IN ('post','page')
				 AND LENGTH(pm.meta_value) > 10"
			);
			$cov_field_summary      = 0;
			$cov_field_summary_long = 0;
			$cov_field_questions    = 0;
			$cov_field_questions_3  = 0;
			$cov_field_entities     = 0;
			$cov_field_keywords     = 0;
			foreach ( (array) $cov_rows as $cov_row ) {
				$aeo = json_decode( $cov_row->meta_value, true );
				if ( ! is_array( $aeo ) ) { continue; }
				$has_summary      = ! empty( $aeo['summary'] );
				$has_summary_long = $has_summary && strlen( trim( $aeo['summary'] ) ) >= 50;
				$has_questions    = ! empty( $aeo['questions'] );
				$qa_count         = $has_questions ? count( array_filter( (array) $aeo['questions'], function( $q ) { return ! empty( $q['q'] ) && ! empty( $q['a'] ); } ) ) : 0;
				$has_questions_3  = $qa_count >= 3;
				$has_entities     = ! empty( $aeo['entities'] );
				$has_keywords     = ! empty( $aeo['keywords'] );
				if ( $has_summary )      { $cov_field_summary++; }
				if ( $has_summary_long ) { $cov_field_summary_long++; }
				if ( $has_questions )    { $cov_field_questions++; }
				if ( $has_questions_3 )  { $cov_field_questions_3++; }
				if ( $has_entities )     { $cov_field_entities++; }
				if ( $has_keywords )     { $cov_field_keywords++; }
				$fields_set = (int) $has_summary + (int) $has_questions + (int) $has_entities + (int) $has_keywords;
				if ( 4 === $fields_set ) {
					$cov_full++;
				} elseif ( $fields_set > 0 ) {
					$cov_partial++;
				}
			}
		}

		// ── SEO field coverage (effective, with cascade) ─────────────────────
		// Reports what is actually being emitted as meta/OG tags on rendered
		// pages, not just what the user has explicitly typed into the SeoPanel.
		// Cascades mirror includes/on-page-seo.php and includes/json-ld.php:
		//   meta_desc : _aeopugmill_seo.meta_desc → _aeopugmill_aeo.summary → post_excerpt
		//   og_desc   : _aeopugmill_seo.og_desc   → meta_desc cascade above
		//   og_image  : _aeopugmill_seo.og_image  → featured image (_thumbnail_id)
		//
		// State detection: if Pugmill is deferring meta output to a detected
		// SEO plugin (Yoast / Rank Math / etc.), the cascade below is never
		// actually emitted — that plugin owns the tags. In that case we skip
		// counting entirely and render an informational "SEO plugin active"
		// state in the panel, to avoid numbers that contradict the SEO plugin's
		// own reports.
		$seo_cov_plugins_detected = function_exists( 'aeopugmill_detected_seo_plugins' ) ? aeopugmill_detected_seo_plugins() : array();
		$seo_cov_disable_meta     = (bool) get_option( 'aeopugmill_disable_seo_meta', 0 );
		$seo_cov_deferring        = ! empty( $seo_cov_plugins_detected ) && $seo_cov_disable_meta;
		$seo_cov_plugin_display   = ! empty( $seo_cov_plugins_detected ) ? reset( $seo_cov_plugins_detected ) : '';

		$cov_seo_meta_desc         = 0;
		$cov_seo_meta_desc_quality = 0;
		$cov_seo_og_image          = 0;
		$cov_seo_og_desc           = 0;

		if ( $cov_total > 0 && ! $seo_cov_deferring ) {
			// Single LEFT-JOIN query: pulls every published post plus its SEO /
			// AEO meta rows and its featured-image meta row. Missing rows come
			// back as NULL — that's the point, we need to walk every post to
			// evaluate cascades, not just the ones with _aeopugmill_seo set.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Static SQL with no user input; literal table names and hard-coded meta_keys.
			$cov_seo_rows = $wpdb->get_results(
				"SELECT p.ID, p.post_excerpt,
				        seo.meta_value   AS seo_raw,
				        aeo.meta_value   AS aeo_raw,
				        thumb.meta_value AS thumb_id
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} seo   ON seo.post_id   = p.ID AND seo.meta_key   = '_aeopugmill_seo'
				 LEFT JOIN {$wpdb->postmeta} aeo   ON aeo.post_id   = p.ID AND aeo.meta_key   = '_aeopugmill_aeo'
				 LEFT JOIN {$wpdb->postmeta} thumb ON thumb.post_id = p.ID AND thumb.meta_key = '_thumbnail_id'
				 WHERE p.post_status = 'publish'
				 AND p.post_type IN ('post','page')"
			);
			foreach ( (array) $cov_seo_rows as $cov_seo_row ) {
				$seo = ! empty( $cov_seo_row->seo_raw ) ? json_decode( $cov_seo_row->seo_raw, true ) : null;
				$aeo = ! empty( $cov_seo_row->aeo_raw ) ? json_decode( $cov_seo_row->aeo_raw, true ) : null;
				if ( ! is_array( $seo ) ) { $seo = array(); }
				if ( ! is_array( $aeo ) ) { $aeo = array(); }

				// meta_desc cascade.
				$md = isset( $seo['meta_desc'] ) ? trim( (string) $seo['meta_desc'] ) : '';
				if ( '' === $md ) { $md = isset( $aeo['summary'] ) ? trim( (string) $aeo['summary'] ) : ''; }
				if ( '' === $md ) { $md = trim( (string) $cov_seo_row->post_excerpt ); }

				// og_desc cascade — explicit og_desc, else reuse meta_desc cascade.
				$ogd = isset( $seo['og_desc'] ) ? trim( (string) $seo['og_desc'] ) : '';
				if ( '' === $ogd ) { $ogd = $md; }

				// og_image cascade — explicit og_image, else featured image.
				$og_img_explicit = isset( $seo['og_image'] ) ? trim( (string) $seo['og_image'] ) : '';
				$has_og_image    = ( '' !== $og_img_explicit ) || ! empty( $cov_seo_row->thumb_id );

				if ( '' !== $md ) {
					$cov_seo_meta_desc++;
					$len = mb_strlen( $md );
					if ( $len >= 120 && $len <= 160 ) { $cov_seo_meta_desc_quality++; }
				}
				if ( $has_og_image )  { $cov_seo_og_image++; }
				if ( '' !== $ogd )    { $cov_seo_og_desc++; }
			}
		}

		$cov_none        = max( 0, $cov_total - $cov_full - $cov_partial );
		$cov_any         = $cov_full + $cov_partial;
		$cov_any_pct     = $cov_total > 0 ? (int) round( $cov_any     / $cov_total * 100 ) : 0;
		$cov_full_pct    = $cov_total > 0 ? (int) round( $cov_full    / $cov_total * 100 ) : 0;
		$cov_partial_pct = $cov_total > 0 ? (int) round( $cov_partial / $cov_total * 100 ) : 0;
		$cov_none_pct    = $cov_total > 0 ? (int) round( $cov_none    / $cov_total * 100 ) : 0;

		// Fetch network averages if opted in and enough sites are contributing
		$network_avgs          = array();         // bot → total 30-day avg
		$network_resource_avgs = array();         // bot → type_id → per-resource avg
		$network_sites         = 0;
		// Maps the network's resource slugs (from ingest.js) to local type IDs.
		$network_slug_to_type  = array(
			'html'          => 0,
			'llms_txt'      => 1,
			'llms_full'     => 2,
			'post_markdown' => 3,
			'site_summary'  => 4,
			'sitemap'       => 5,
			'robots_txt'    => 6,
		);
		$network_categories = array();  // category → { total, prior_total, change_pct, sites }
		$network_coverage   = array();  // content_coverage block from API

		// Fetch the cached network report. aeopugmill_get_network_report() handles
		// the opted-in check, the 24-hour positive cache (server aggregates daily),
		// and a short negative cache so an unreachable aeopugmill.com doesn't thrash
		// every admin page load.
		$cached_report = function_exists( 'aeopugmill_get_network_report' ) ? aeopugmill_get_network_report() : false;
		if ( is_array( $cached_report ) ) {
			$network_sites = (int) ( $cached_report['sites_contributing'] ?? 0 );
			if ( $network_sites >= 1 && ! empty( $cached_report['last_30_days'] ) ) {
				foreach ( $cached_report['last_30_days'] as $bot => $resources ) {
						$network_avgs[ $bot ] = (int) round( array_sum( $resources ) / $network_sites );
						foreach ( $resources as $slug => $total ) {
							if ( isset( $network_slug_to_type[ $slug ] ) ) {
								$type_id = $network_slug_to_type[ $slug ];
								$network_resource_avgs[ $bot ][ $type_id ] = round( (float) $total / $network_sites, 1 );
							}
						}
					}
				}
				// Category-level network trends — remap API keys ('AI Answer Engine' etc.)
				// to local quadrant keys ('ai', 'training', 'search', 'seo').
				if ( ! empty( $cached_report['categories'] ) ) {
					$net_cat_map = array(
						'AI Answer Engine' => 'ai',
						'AI Training'      => 'training',
						'Search Engine'    => 'search',
						'SEO Tool'         => 'seo',
					);
					foreach ( $cached_report['categories'] as $api_cat => $cat_data ) {
						$local_key = $net_cat_map[ $api_cat ] ?? null;
						if ( $local_key ) {
							$network_categories[ $local_key ] = $cat_data;
						}
					}
				}
			// Capture network content coverage for AEO field comparison.
			if ( ! empty( $cached_report['content_coverage'] ) ) {
				$network_coverage = $cached_report['content_coverage'];
			}
		}

		// ── Build chart data: date labels + per-bot series ────────────────────
		$date_labels = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date_labels[] = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
		}

		// Index daily rows by bot → day → count
		$daily_index = array();
		foreach ( $daily as $row ) {
			$daily_index[ $row['bot'] ][ $row['day'] ] = (int) $row['cnt'];
		}

		// Build one dataset per bot (fill gaps with 0)
		$chart_datasets = array();
		foreach ( $bots as $bot_key => $bot_info ) {
			$values = array();
			foreach ( $date_labels as $date ) {
				$values[] = isset( $daily_index[ $bot_key ][ $date ] ) ? $daily_index[ $bot_key ][ $date ] : 0;
			}
			$chart_datasets[ $bot_key ] = array(
				'label'  => $bot_info['label'],
				'color'  => $bot_info['color'],
				'values' => $values,
			);
		}

		// Compact date labels for display (M-D)
		$chart_labels = array_map( function( $d ) {
			return gmdate( 'M j', strtotime( $d ) );
		}, $date_labels );
		?>

		<!-- ── AI Insights ──────────────────────────────────────────────── -->
		<?php
		$just_activated = get_transient( 'aeopugmill_analytics_just_activated' );
		if ( $just_activated ) {
			delete_transient( 'aeopugmill_analytics_just_activated' );
		}
		?>
		<?php if ( $just_activated ) : ?>
		<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:28px 32px; margin:24px 0 0; text-align:center;">
			<div style="font-size:28px; margin-bottom:12px;">📡</div>
			<h3 style="margin:0 0 8px; font-size:16px; font-weight:600; color:#166534;">
				<?php esc_html_e( 'Bot Analytics Activated', 'aeo-pugmill' ); ?>
			</h3>
			<p style="margin:0; font-size:13px; color:#555; line-height:1.7; max-width:520px; display:inline-block;">
				<?php esc_html_e( 'We\'re now watching the watchers that visit your website. This page will update automatically with metrics in real time as bots explore your content.', 'aeo-pugmill' ); ?>
			</p>
		</div>
		<?php else : ?>
		<div style="display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:24px 0 0;">
				<?php if ( $has_api_key ) : ?>
				<button id="aeopugmill-insights-btn" type="button"
					style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600;
					       background:#7c3aed; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap;">
					✨ <?php echo $cached_insights ? esc_html__( 'Refresh Analysis', 'aeo-pugmill' ) : esc_html__( 'Generate AI Analysis', 'aeo-pugmill' ); ?>
				</button>
				<?php else : ?>
				<button type="button" disabled
					style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600;
					       background:#e5e7eb; color:#9ca3af; border:none; border-radius:4px; cursor:not-allowed; white-space:nowrap;">
					✨ <?php esc_html_e( 'Generate AI Analysis', 'aeo-pugmill' ); ?>
					<span style="font-size:9px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; background:#f3e8ff; color:#7c3aed; padding:1px 6px; border-radius:3px; line-height:1.4;">AI</span>
				</button>
				<?php endif; ?>
			</div>

			<?php if ( $cached_insights ) : ?>
			<div id="aeopugmill-insights-output" class="pugmill-card" style="margin-top:16px;">
				<div id="aeopugmill-insights-text" style="font-size:14px; color:#374151; line-height:1.7;">
					<?php
					$lines  = explode( "\n", $cached_insights['text'] );
					$html   = '';
					$para   = '';
					foreach ( $lines as $line ) {
						if ( preg_match( '/^## (.+)$/', $line, $m ) ) {
							if ( '' !== $para ) {
								$html .= '<p style="margin:4px 0 10px;">' . nl2br( esc_html( rtrim( $para ) ) ) . '</p>';
								$para  = '';
							}
							$html .= '<p style="font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 2px;">' . esc_html( $m[1] ) . '</p>';
						} elseif ( '' === trim( $line ) ) {
							if ( '' !== $para ) {
								$html .= '<p style="margin:4px 0 10px;">' . nl2br( esc_html( rtrim( $para ) ) ) . '</p>';
								$para  = '';
							}
						} else {
							$para .= ( '' !== $para ? "\n" : '' ) . $line;
						}
					}
					if ( '' !== $para ) {
						$html .= '<p style="margin:4px 0 10px;">' . nl2br( esc_html( rtrim( $para ) ) ) . '</p>';
					}
					echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline above
					?>
				</div>
				<p style="font-size:11px; color:#9ca3af; margin:8px 0 0;">
					<?php
					/* translators: %s: human time difference */
					printf( esc_html__( 'Generated %s ago', 'aeo-pugmill' ), esc_html( human_time_diff( $cached_insights['generated'] ) ) );
					?>
					&nbsp;·&nbsp;
					<span id="aeopugmill-insights-status"></span>
				</p>
			</div>
			<?php else : ?>
			<div id="aeopugmill-insights-output" class="pugmill-card" style="display:none; margin-top:16px;">
				<div id="aeopugmill-insights-text" style="font-size:14px; color:#374151; line-height:1.7;"></div>
				<p style="font-size:11px; color:#9ca3af; margin:8px 0 0;">
					<span id="aeopugmill-insights-status"></span>
				</p>
			</div>
			<?php endif; ?>
		<?php endif; /* just_activated welcome vs AI Analysis */ ?>


		<?php if ( $total > 0 ) : ?>
		<?php
		// ── Two-ring donut: categories (inner) + individual bots (outer) ────────
		$cat_colors = array(
			'ai'       => '#7c3aed',
			'training' => '#0891b2',
			'search'   => '#0369a1',
			'seo'      => '#d97706',
		);
		$cat_light = array(
			'ai'       => '#c4b5fd',
			'training' => '#a5f3fc',
			'search'   => '#bae6fd',
			'seo'      => '#fde68a',
		);
		$total_visits = $ai_total_30 + $training_total_30 + $search_total_30 + $seo_total_30;

		// ── Semi-gauge: AEO infrastructure completeness ──────────────────────────
		$seo_plugins_g    = aeopugmill_detected_seo_plugins();
		$seo_name_g       = ! empty( $seo_plugins_g ) ? reset( $seo_plugins_g ) : '';
		$defer_json_ld_g  = (bool) get_option( 'aeopugmill_disable_json_ld', 0 );
		$defer_bc_g       = $defer_json_ld_g || (bool) get_option( 'aeopugmill_disable_breadcrumbs', 0 );
		$defer_meta_g     = (bool) get_option( 'aeopugmill_disable_seo_meta', 0 );
		$llms_off_g       = (bool) get_option( 'aeopugmill_disable_llms_txt', 0 );
		$robots_off_g     = (bool) get_option( 'aeopugmill_disable_robots_append', 0 );
		$rss_off_g        = (bool) get_option( 'aeopugmill_disable_rss_enrichment', 0 );

		// Count features by owner
		$gauge_pugmill  = 0; // Pugmill exclusive, active
		$gauge_seo      = 0; // Handled by SEO plugin
		$gauge_disabled = 0; // Pugmill feature disabled
		$gauge_coop     = 0; // Pugmill handling cooperative

		// Pugmill exclusive
		if ( ! $llms_off_g )   { $gauge_pugmill++; } else { $gauge_disabled++; } // llms.txt
		if ( ! $llms_off_g )   { $gauge_pugmill++; } else { $gauge_disabled++; } // llms-full.txt
		if ( ! $rss_off_g )    { $gauge_pugmill++; } else { $gauge_disabled++; } // RSS+AEO Feed
		$gauge_pugmill += 4; // per-post markdown, site summary, bot analytics, FAQPage schema
		if ( ! $robots_off_g ) { $gauge_pugmill++; } else { $gauge_disabled++; } // robots.txt
		$gauge_pugmill += 2; // citations, entity graph

		// Cooperative
		if ( $defer_json_ld_g && $seo_name_g ) { $gauge_seo++; } else { $gauge_coop++; }  // Article schema
		if ( $defer_bc_g      && $seo_name_g ) { $gauge_seo++; } else { $gauge_coop++; }  // Breadcrumbs
		if ( $defer_meta_g    && $seo_name_g ) { $gauge_seo += 2; } else { $gauge_coop += 2; } // Meta + OG

		$gauge_total   = $gauge_pugmill + $gauge_seo + $gauge_disabled + $gauge_coop;
		$gauge_active  = $gauge_pugmill + $gauge_seo + $gauge_coop;
		$gauge_pct     = $gauge_total > 0 ? (int) round( $gauge_active / $gauge_total * 100 ) : 0;

		// ── Donut 1: Bot category mix ──────────────────────────────────────────
		$donut_cats = array_values( array_filter( array(
			array( 'label' => __( 'AI Answer Engines',      'aeo-pugmill' ), 'value' => $ai_total_30,       'color' => '#7c3aed' ),
			array( 'label' => __( 'AI Training Crawlers', 'aeo-pugmill' ), 'value' => $training_total_30, 'color' => '#0891b2' ),
			array( 'label' => __( 'Search Engines',    'aeo-pugmill' ), 'value' => $search_total_30,   'color' => '#0369a1' ),
			array( 'label' => __( 'SEO Tools',            'aeo-pugmill' ), 'value' => $seo_total_30,      'color' => '#374151' ),
		), function( $s ) { return $s['value'] > 0; } ) );

		// ── Donut 2: AEO content coverage ────────────────────────────────────────
		$donut_aeo_data = array_values( array_filter( array(
			array( 'label' => __( 'Complete (all 4 fields)', 'aeo-pugmill' ), 'value' => $cov_full,    'pct' => $cov_full_pct,    'color' => '#16a34a' ),
			array( 'label' => __( 'Partial (1–2 fields)',   'aeo-pugmill' ), 'value' => $cov_partial, 'pct' => $cov_partial_pct, 'color' => '#d97706' ),
			array( 'label' => __( 'None',                    'aeo-pugmill' ), 'value' => $cov_none,    'pct' => $cov_none_pct,    'color' => '#e5e7eb' ),
		), function( $s ) { return $s['value'] > 0; } ) );
		// Center shows % with any AEO (complete + partial), not just % complete.
		$donut_aeo_pct = $cov_any_pct;

		// ── Donut 3: Top crawlers by volume ───────────────────────────────────
		$donut_top_sorted = $summary;
		arsort( $donut_top_sorted );
		$donut_top3     = array_slice( $donut_top_sorted, 0, 3, true );
		$donut_top_rest = (int) array_sum( array_slice( $donut_top_sorted, 3, null, true ) );
		$donut_topbots  = array();
		foreach ( $donut_top3 as $bot_key => $cnt ) {
			$donut_topbots[] = array(
				'label' => isset( $all_bots[ $bot_key ]['label'] ) ? $all_bots[ $bot_key ]['label'] : $bot_key,
				'value' => (int) $cnt,
				'color' => isset( $all_bots[ $bot_key ]['color'] ) ? $all_bots[ $bot_key ]['color'] : '#94a3b8',
			);
		}
		if ( $donut_top_rest > 0 ) {
			$donut_topbots[] = array( 'label' => __( 'Others', 'aeo-pugmill' ), 'value' => $donut_top_rest, 'color' => '#d1d5db' );
		}
		$donut_top_grand = (int) array_sum( $summary );
		$donut_top_pct   = ( ! empty( $donut_topbots ) && $donut_top_grand > 0 ) ? (int) round( $donut_topbots[0]['value'] / $donut_top_grand * 100 ) : 0;
		$donut_top_short = ! empty( $donut_topbots ) ? preg_replace( '/\s*\/.*$/', '', $donut_topbots[0]['label'] ) : '';
		?>

		<?php if ( 0 === $total ) : ?>
		<!-- ── Empty state — no bot visits recorded yet ── -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:28px 32px; margin:24px 0; display:flex; align-items:flex-start; gap:20px;">
			<div style="font-size:36px; line-height:1; flex-shrink:0;">👁</div>
			<div>
				<h3 style="margin:0 0 8px; font-size:15px; font-weight:600; color:#1d2327;">
					<?php esc_html_e( 'Waiting for your first bot visit', 'aeo-pugmill' ); ?>
				</h3>
				<p style="margin:0 0 10px; color:#555; font-size:14px; line-height:1.6;">
					<?php esc_html_e( 'AI crawlers, search bots, and SEO tools visit most websites within hours to days of going live. As they arrive, Pugmill AEO Toolkit logs each visit and this dashboard will fill with data — which bots showed up, how often, which pages and AEO resources they fetched.', 'aeo-pugmill' ); ?>
				</p>
				<p style="margin:0; color:#888; font-size:13px; line-height:1.5;">
					<?php esc_html_e( 'Nothing to configure — tracking is automatic. Check back after your site receives some traffic.', 'aeo-pugmill' ); ?>
				</p>
			</div>
		</div>
		<?php endif; ?>

		<!-- ── 3-column Summary Row: Bot Activity | AEO Content Coverage | AEO Infrastructure ── -->
		<?php ob_start(); ?>
		/* ── Mobile: Preferences cards ── */
		.pugmill-prefs-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
			gap: 16px;
			padding: 16px;
		}
		/* ── Mobile: Audit table ── */
		.pugmill-audit-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
		@media (max-width: 782px) {
			.aeopugmill-audit-generate { font-size: 11px !important; padding: 3px 8px !important; }
			.pugmill-audit-table th,
			.pugmill-audit-table td { white-space: nowrap; }
		}
		/* ── Crawl Intelligence: sticky first column ── */
		.ci-col-bot-th {
			position: sticky; left: 0; z-index: 2;
			background: #f6f7f7;
		}
		.ci-col-bot-td {
			position: sticky; left: 0; z-index: 1;
			background: inherit;
		}
		.pugmill-summary-row {
			display: grid;
			grid-template-columns: 1fr 1fr 1fr;
			gap: 16px;
			margin: 24px 0;
		}
		@media (max-width: 1000px) {
			.pugmill-summary-row { grid-template-columns: 1fr 1fr; }
		}
		@media (max-width: 640px) {
			.pugmill-summary-row { grid-template-columns: 1fr; }
		}
		.pugmill-card {
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 8px;
			padding: 18px 20px;
		}
		.pugmill-card h3 {
			margin: 0 0 4px;
			font-size: 14px;
			font-weight: 600;
		}
		.pugmill-card .card-sub {
			margin: 0 0 12px;
			font-size: 11px;
			color: #6b7280;
		}
		.pugmill-feat-group {
			font-size: 10px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: .06em;
			margin: 10px 0 4px;
			padding-bottom: 3px;
			border-bottom: 1px solid #f0f0f0;
		}
		.pugmill-feat-group:first-child { margin-top: 2px; }
		.pugmill-feat-row {
			display: flex;
			align-items: center;
			gap: 7px;
			font-size: 11px;
			padding: 3px 0;
		}
		.pugmill-feat-dot {
			width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0;
		}
		.pugmill-feat-name { flex: 1; color: #374151; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.pugmill-feat-badge {
			font-size: 9px;
			font-weight: 600;
			padding: 1px 5px;
			border-radius: 3px;
			white-space: nowrap;
		}
		.pugmill-feat-badge.pm  { background: #dcfce7; color: #15803d; }
		.pugmill-feat-badge.seo { background: #dbeafe; color: #1d4ed8; }
		.pugmill-feat-badge.off { background: #f3f4f6; color: #9ca3af; }
		<?php aeopugmill_inline_css( ob_get_clean() ); ?>

		<div class="pugmill-summary-row">

			<!-- Col 1: Bot Activity ─────────────────────── -->
			<div class="pugmill-card">
				<div style="display:flex; align-items:baseline; justify-content:space-between;">
					<h3 style="margin:0;"><?php esc_html_e( 'Bot Activity', 'aeo-pugmill' ); ?></h3>
					<?php if ( get_option( 'aeopugmill_analytics_opted_in' ) ) : ?>
					<span style="font-size:10px; color:#6b7280;">
						<a href="#" id="aeopugmill-resync-link" style="color:#6d28d9; text-decoration:underline;"><?php esc_html_e( 'Resync now', 'aeo-pugmill' ); ?></a>
						<span id="aeopugmill-resync-result" style="font-weight:700;"></span>
					</span>
					<?php endif; ?>
				</div>
				<p class="card-sub"><?php esc_html_e( 'Crawler traffic share across the 4 categories.', 'aeo-pugmill' ); ?></p>

				<?php
				$cat_order_c1  = array( 'ai', 'search', 'seo', 'training' );
				$cat_labels_c1 = array(
					'ai'       => __( 'AI Answer Engines',    'aeo-pugmill' ),
					'training' => __( 'AI Training Crawlers', 'aeo-pugmill' ),
					'search'   => __( 'Search Engines',       'aeo-pugmill' ),
					'seo'      => __( 'SEO Tools',            'aeo-pugmill' ),
				);
				$cat_short_c1  = array(
					'ai'       => __( 'AI Answer',  'aeo-pugmill' ),
					'training' => __( 'Training',   'aeo-pugmill' ),
					'search'   => __( 'Search',     'aeo-pugmill' ),
					'seo'      => __( 'SEO Tools',  'aeo-pugmill' ),
				);
				$cat_bots_c1 = array(
					'ai'       => $ai_bots,
					'training' => $training_bots,
					'search'   => $search_bots,
					'seo'      => $seo_bots,
				);
				$cat_bg_c1 = array(
					'ai'       => '#faf7ff',
					'training' => '#f0fdff',
					'search'   => '#f0f9ff',
					'seo'      => '#f9fafb',
				);
				$cat_border_c1 = array(
					'ai'       => '#d4c8f0',
					'training' => '#a5f3fc',
					'search'   => '#bae0fd',
					'seo'      => '#e5e7eb',
				);
				$cat_totals_c1 = array();
				foreach ( $cat_order_c1 as $ck ) {
					$t = 0;
					foreach ( $cat_bots_c1[ $ck ] as $bk => $_ ) { $t += (int) ( $summary[ $bk ] ?? 0 ); }
					$cat_totals_c1[ $ck ] = $t;
				}
				$grand_total_c1 = max( 1, array_sum( $cat_totals_c1 ) );
				?>

				<?php
				// Fixed order: AI Answer Engines, Search Engines, SEO Tools, AI Training Crawlers.
				$sorted_c1 = $cat_order_c1;
				?>

				<!-- Unified grouped list — 3-col rows (name | metric | bar) styled like AEO Content Coverage -->
				<div style="display:flex; flex-direction:column; gap:8px; margin-top:4px;">
				<?php foreach ( $sorted_c1 as $ck ) :
					$d_c1      = $cat_totals_c1[ $ck ];
					$bots_here = array();
					foreach ( $cat_bots_c1[ $ck ] as $bk => $_ ) {
						$bots_here[ $bk ] = (int) ( $summary[ $bk ] ?? 0 );
					}
					arsort( $bots_here );
					$net_vals_here = array_map( fn( $bk_c ) => (int) ( $network_avgs[ $bk_c ] ?? 0 ), array_keys( $bots_here ) );
					$cat_max_here  = max( 1,
						! empty( $bots_here ) ? max( array_values( $bots_here ) ) : 0,
						! empty( $net_vals_here ) ? max( $net_vals_here ) : 0
					);
					$has_any_visits = ( $d_c1 > 0 );
				?>
				<div>
					<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?php echo esc_attr( $cat_colors[ $ck ] ); ?>;padding:4px 0 4px;border-bottom:1px solid <?php echo esc_attr( $cat_border_c1[ $ck ] ); ?>;margin-bottom:5px;">
						<?php echo esc_html( $cat_labels_c1[ $ck ] ); ?>
					</div>
					<?php foreach ( $bots_here as $bk => $bv ) :
						if ( $bv === 0 ) { continue; }
						$bi_here    = $cat_bots_c1[ $ck ][ $bk ];
						$pct_here   = (int) round( $bv / $cat_max_here * 100 );
						$net_avg_ba = (int) ( $network_avgs[ $bk ] ?? 0 );
						$avg_pct_ba = $net_avg_ba > 0 ? (int) round( $net_avg_ba / $cat_max_here * 100 ) : null;
					?>
					<div style="display:grid;grid-template-columns:minmax(0,1fr) 38px minmax(0,1.4fr);align-items:center;gap:6px;margin-bottom:4px;">
						<span style="font-size:11px;color:#374151;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $bi_here['label'] ); ?></span>
						<span style="font-size:11px;font-weight:700;color:<?php echo esc_attr( $cat_colors[ $ck ] ); ?>;text-align:right;"><?php echo esc_html( number_format_i18n( $bv ) ); ?></span>
						<div style="position:relative;">
							<div style="position:relative;height:16px;border-radius:3px;overflow:hidden;background:#f3f4f6;">
								<div style="position:absolute;top:50%;transform:translateY(-50%);left:0;width:<?php echo (int) max( 2, $pct_here ); ?>%;height:8px;background:<?php echo esc_attr( $bi_here['color'] ); ?>;border-radius:2px;"></div>
							</div>
							<?php if ( null !== $avg_pct_ba && get_option( 'aeopugmill_analytics_opted_in' ) ) : ?>
							<div style="position:absolute;top:8px;left:<?php echo (int) max( 0, min( 100, $avg_pct_ba ) ); ?>%;transform:translate(-50%,-50%);width:8px;height:8px;border-radius:50%;background:#374151;border:1.5px solid #fff;" title="<?php echo esc_attr( sprintf( 'Network avg: %s', number_format_i18n( $net_avg_ba ) ) ); ?>"></div>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
					<?php if ( ! $has_any_visits ) : ?>
					<div style="font-size:11px;color:#9ca3af;font-style:italic;padding:2px 0;"><?php esc_html_e( 'No visits recorded', 'aeo-pugmill' ); ?></div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
				</div>

				<?php if ( get_option( 'aeopugmill_analytics_opted_in' ) ) : ?>
				<div style="display:flex; align-items:center; gap:5px; margin-top:6px; padding-top:7px; border-top:1px solid #f0f0f0; font-size:10px; color:#6b7280;">
					<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#374151; flex-shrink:0;"></span>
					<?php esc_html_e( 'Dot marker = Pugmill network average', 'aeo-pugmill' ); ?>
				</div>
				<?php endif; ?>
			</div>

			<!-- Col 2: AEO + SEO Content Coverage (stacked) ─── -->
			<div style="display:flex; flex-direction:column; gap:16px;">
			<div class="pugmill-card">
				<h3><?php esc_html_e( 'AEO Content Coverage', 'aeo-pugmill' ); ?></h3>
				<p class="card-sub"><?php esc_html_e( 'AEO field coverage across your published posts.', 'aeo-pugmill' ); ?></p>
				<div style="display:flex; flex-direction:column; gap:8px; margin-top:4px;">
					<?php
					$radar_fields = array(
						array( 'section' => __( 'AEO Fields', 'aeo-pugmill' ), 'first' => true ),
						array( 'label' => __( 'AI Summary',          'aeo-pugmill' ), 'net_key' => 'summary',          'count' => $cov_field_summary,      'pct' => ( $cov_total > 0 ? (int) round( $cov_field_summary      / $cov_total * 100 ) : 0 ) ),
						array( 'label' => __( 'Summary quality (50+ chars)', 'aeo-pugmill' ), 'net_key' => 'summary_quality', 'count' => $cov_field_summary_long, 'pct' => ( $cov_total > 0 ? (int) round( $cov_field_summary_long / $cov_total * 100 ) : 0 ) ),
						array( 'label' => __( 'Q&A Pairs (1+)',      'aeo-pugmill' ), 'net_key' => 'questions',       'count' => $cov_field_questions,    'pct' => ( $cov_total > 0 ? (int) round( $cov_field_questions    / $cov_total * 100 ) : 0 ) ),
						array( 'label' => __( 'Q&A Pairs (3+)',      'aeo-pugmill' ), 'net_key' => 'questions_3plus', 'count' => $cov_field_questions_3,  'pct' => ( $cov_total > 0 ? (int) round( $cov_field_questions_3  / $cov_total * 100 ) : 0 ) ),
						array( 'label' => __( 'Named Entities',      'aeo-pugmill' ), 'net_key' => 'entities',  'count' => $cov_field_entities,     'pct' => ( $cov_total > 0 ? (int) round( $cov_field_entities     / $cov_total * 100 ) : 0 ) ),
						array( 'label' => __( 'Keywords (5+)',        'aeo-pugmill' ), 'net_key' => 'keywords',  'count' => $cov_field_keywords,     'pct' => ( $cov_total > 0 ? (int) round( $cov_field_keywords     / $cov_total * 100 ) : 0 ) ),
						array( 'section' => __( 'RSS+AEO Feed', 'aeo-pugmill' ) ),
						array( 'label' => __( 'Posts emitting AEO feed elements', 'aeo-pugmill' ), 'net_key' => null, 'count' => $cov_any, 'pct' => $cov_any_pct, 'rss_disabled' => $rss_off_g ),
					);
					$opted_in_network = (bool) get_option( 'aeopugmill_analytics_opted_in' );
					foreach ( $radar_fields as $rf ) :
						if ( isset( $rf['section'] ) ) :
							$border = empty( $rf['first'] ) ? 'margin-top:4px; padding-top:8px; border-top:1px solid #f0f0f0;' : '';
							?>
							<div style="font-size:10px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.06em; <?php echo esc_attr( $border ); ?>">
								<?php echo esc_html( $rf['section'] ); ?>
							</div>
							<?php
							continue;
						endif;
						$rss_disabled = ! empty( $rf['rss_disabled'] );
						$rc           = $rss_disabled ? '#9ca3af' : ( $rf['pct'] >= 75 ? '#16a34a' : ( $rf['pct'] >= 40 ? '#d97706' : '#e11d48' ) );
						$has_net_key  = ! empty( $rf['net_key'] );
						$net_pct      = null;
						if ( $has_net_key ) {
							$raw = $network_coverage['fields'][ $rf['net_key'] ]['pct'] ?? null;
							if ( null !== $raw && '' !== $raw ) {
								$net_pct = (int) $raw;
							}
						}
						$show_avg = $has_net_key && $opted_in_network;
					?>
					<div style="display:grid;grid-template-columns:minmax(0,1fr) 64px minmax(0,1.4fr);align-items:center;gap:6px;<?php echo $rss_disabled ? 'opacity:0.5;' : ''; ?>">
						<span style="font-size:11px;color:#374151;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $rf['label'] ); ?>"><?php echo esc_html( $rf['label'] ); ?><?php if ( $rss_disabled ) : ?> <span style="font-weight:400;color:#9ca3af;">(<?php esc_html_e( 'feed enrichment off', 'aeo-pugmill' ); ?>)</span><?php endif; ?></span>
						<span style="font-size:11px;text-align:right;color:<?php echo esc_attr( $rc ); ?>;font-weight:700;"><?php echo (int) $rf['pct']; ?>% <span style="color:#9ca3af;font-weight:400;font-size:10px;">(<?php echo esc_html( number_format_i18n( $rf['count'] ) ); ?>)</span></span>
						<div style="position:relative;">
							<div style="position:relative;height:16px;border-radius:3px;overflow:hidden;background:#f3f4f6;">
								<div style="position:absolute;top:50%;transform:translateY(-50%);left:0;width:<?php echo (int) $rf['pct']; ?>%;height:8px;background:<?php echo esc_attr( $rc ); ?>;border-radius:2px;"></div>
							</div>
							<?php if ( $show_avg && null !== $net_pct ) : ?>
							<div style="position:absolute;top:8px;left:<?php echo (int) $net_pct; ?>%;transform:translate(-50%,-50%);width:8px;height:8px;border-radius:50%;background:#374151;border:1.5px solid #fff;" title="<?php echo esc_attr( sprintf( 'Network avg: %d%%', (int) $net_pct ) ); ?>"></div>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $opted_in_network ) : ?>
				<div style="display:flex; align-items:center; gap:5px; margin-top:10px; font-size:10px; color:#6b7280;">
					<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#374151; flex-shrink:0;"></span>
					<?php esc_html_e( 'Dot marker = Pugmill network average', 'aeo-pugmill' ); ?>
				</div>
				<?php endif; ?>
				<div style="margin-top:6px; padding-top:7px; border-top:1px solid #f0f0f0; font-size:10px; color:#9ca3af;">
					<?php echo esc_html( number_format_i18n( $cov_total ) . ' ' . _n( 'post/page total', 'posts/pages total', $cov_total, 'aeo-pugmill' ) ); ?>
					<?php if ( null !== ( $network_coverage['with_aeo_pct'] ?? null ) ) : ?>
					&nbsp;&middot;&nbsp;<span style="color:#7c3aed;"><?php echo (int) $network_coverage['with_aeo_pct']; ?>% <?php esc_html_e( 'of network posts have any AEO data', 'aeo-pugmill' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<!-- SEO Content Coverage (stacked below AEO Content Coverage) ── -->
			<div class="pugmill-card">
				<h3><?php esc_html_e( 'SEO Content Coverage', 'aeo-pugmill' ); ?></h3>
				<?php if ( $seo_cov_deferring ) : ?>
					<?php
					// ── State B: Pugmill is deferring meta output to an SEO plugin ──
					// We don't count or display bars because the numbers would conflict
					// with what the SEO plugin's own reports show.
					$seo_cov_plugin_slugs = array_keys( $seo_cov_plugins_detected );
					$seo_cov_first_slug   = $seo_cov_plugin_slugs[0] ?? '';
					$seo_cov_dash_urls    = array(
						'yoast'    => admin_url( 'admin.php?page=wpseo_dashboard' ),
						'rankmath' => admin_url( 'admin.php?page=rank-math' ),
						'aioseo'   => admin_url( 'admin.php?page=aioseo' ),
						'tsf'      => admin_url( 'admin.php?page=theseoframework-settings' ),
						'seopress' => admin_url( 'admin.php?page=seopress-option' ),
					);
					$seo_cov_dash_url = $seo_cov_dash_urls[ $seo_cov_first_slug ] ?? '';
					?>
					<p class="card-sub"><?php /* translators: %s: SEO plugin name */ printf( esc_html__( 'Pugmill is deferring meta output to %s.', 'aeo-pugmill' ), '<strong>' . esc_html( $seo_cov_plugin_display ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- <strong> is a literal safe tag; the plugin name is esc_html'd. ?></p>
					<div style="margin-top:10px; padding:12px; background:#f9fafb; border:1px solid #e5e7eb; border-left:3px solid #0369a1; border-radius:4px; font-size:11px; color:#374151; line-height:1.5;">
						<?php
						$seo_cov_plugin_short = esc_html( preg_replace( '/\s+\d.*$/', '', $seo_cov_plugin_display ) );
						/* translators: %s: SEO plugin name */
						printf( esc_html__( '%s is managing meta descriptions and Open Graph tags. Pugmill defers here to avoid duplicates.', 'aeo-pugmill' ), $seo_cov_plugin_short ); // phpcs:ignore WordPress.Security.EscapeOutput -- $seo_cov_plugin_short is already esc_html'd above.
						?>
						<?php if ( $seo_cov_dash_url ) : ?>
						<div style="margin-top:8px;">
							<a href="<?php echo esc_url( $seo_cov_dash_url ); ?>" style="font-size:11px; font-weight:600; color:#0369a1; text-decoration:none;">
								<?php /* translators: %s: SEO plugin name */ printf( esc_html__( 'Manage in %s →', 'aeo-pugmill' ), $seo_cov_plugin_short ); // phpcs:ignore WordPress.Security.EscapeOutput -- $seo_cov_plugin_short is already esc_html'd above. ?>
							</a>
						</div>
						<?php endif; ?>
					</div>
					<div style="margin-top:10px; padding-top:7px; border-top:1px solid #f0f0f0; font-size:10px; color:#9ca3af;">
						<?php esc_html_e( 'To see Pugmill-measured coverage here instead, disable the "Defer meta output to SEO plugin" toggle in the Compatibility tab.', 'aeo-pugmill' ); ?>
					</div>
				<?php elseif ( 0 === $cov_total ) : ?>
					<p class="card-sub"><?php esc_html_e( 'Effective SEO field coverage across your published posts.', 'aeo-pugmill' ); ?></p>
					<div style="margin-top:10px; font-size:11px; color:#9ca3af; font-style:italic;">
						<?php esc_html_e( 'No published posts yet.', 'aeo-pugmill' ); ?>
					</div>
				<?php else : ?>
					<?php
					// ── State A: Pugmill is the SEO authority (or co-emitting) ──
					// Bars count effective coverage via cascade. If an SEO plugin is
					// detected but not being deferred to, flag the double-emit risk.
					$seo_cov_double_emit = ! empty( $seo_cov_plugins_detected ) && ! $seo_cov_disable_meta;
					?>
					<p class="card-sub"><?php esc_html_e( 'Effective SEO field coverage — counts explicit values plus cascade fallbacks (AEO summary, excerpt, featured image).', 'aeo-pugmill' ); ?></p>
					<div style="display:flex; flex-direction:column; gap:8px; margin-top:4px;">
						<?php
						$seo_cov_fields = array(
							array( 'section' => __( 'SEO Fields', 'aeo-pugmill' ), 'first' => true ),
							array( 'label' => __( 'Meta Description',             'aeo-pugmill' ), 'count' => $cov_seo_meta_desc,         'pct' => (int) round( $cov_seo_meta_desc         / $cov_total * 100 ) ),
							array( 'label' => __( 'Meta quality (120–160 chars)', 'aeo-pugmill' ), 'count' => $cov_seo_meta_desc_quality, 'pct' => (int) round( $cov_seo_meta_desc_quality / $cov_total * 100 ) ),
							array( 'label' => __( 'Open Graph Image',             'aeo-pugmill' ), 'count' => $cov_seo_og_image,          'pct' => (int) round( $cov_seo_og_image          / $cov_total * 100 ) ),
							array( 'label' => __( 'Open Graph Description',       'aeo-pugmill' ), 'count' => $cov_seo_og_desc,           'pct' => (int) round( $cov_seo_og_desc           / $cov_total * 100 ) ),
						);
						foreach ( $seo_cov_fields as $sf ) :
							if ( isset( $sf['section'] ) ) :
								$border = empty( $sf['first'] ) ? 'margin-top:4px; padding-top:8px; border-top:1px solid #f0f0f0;' : '';
								?>
								<div style="font-size:10px; font-weight:700; color:#0369a1; text-transform:uppercase; letter-spacing:.06em; <?php echo esc_attr( $border ); ?>">
									<?php echo esc_html( $sf['section'] ); ?>
								</div>
								<?php
								continue;
							endif;
							$src = $sf['pct'] >= 75 ? '#16a34a' : ( $sf['pct'] >= 40 ? '#d97706' : '#e11d48' );
						?>
						<div style="display:grid;grid-template-columns:minmax(0,1fr) 64px minmax(0,1.4fr);align-items:center;gap:6px;">
							<span style="font-size:11px;color:#374151;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $sf['label'] ); ?>"><?php echo esc_html( $sf['label'] ); ?></span>
							<span style="font-size:11px;text-align:right;color:<?php echo esc_attr( $src ); ?>;font-weight:700;"><?php echo (int) $sf['pct']; ?>% <span style="color:#9ca3af;font-weight:400;font-size:10px;">(<?php echo esc_html( number_format_i18n( $sf['count'] ) ); ?>)</span></span>
							<div style="position:relative;">
								<div style="position:relative;height:16px;border-radius:3px;overflow:hidden;background:#f3f4f6;">
									<div style="position:absolute;top:50%;transform:translateY(-50%);left:0;width:<?php echo (int) $sf['pct']; ?>%;height:8px;background:<?php echo esc_attr( $src ); ?>;border-radius:2px;"></div>
								</div>
							</div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php if ( $seo_cov_double_emit ) : ?>
					<div style="margin-top:10px; padding:8px 10px; background:#fffbeb; border:1px solid #fde68a; border-left:3px solid #d97706; border-radius:4px; font-size:11px; color:#92400e; line-height:1.5;">
						<strong><?php esc_html_e( 'Heads up:', 'aeo-pugmill' ); ?></strong>
						<?php
						$compat_tab_link = '<a href="' . esc_url( $tab_url( 'compatibility' ) ) . '" style="color:#92400e; font-weight:600; text-decoration:underline;">' . esc_html__( 'Compatibility tab', 'aeo-pugmill' ) . '</a>';
						printf(
							/* translators: 1: SEO plugin display name, 2: link to the Compatibility tab */
							esc_html__( 'Detected %1$s, but Pugmill is still emitting meta description and Open Graph tags. This may produce duplicate tags in your page head. Review the %2$s.', 'aeo-pugmill' ),
							esc_html( $seo_cov_plugin_display ),
							$compat_tab_link // phpcs:ignore WordPress.Security.EscapeOutput
						); // phpcs:ignore WordPress.Security.EscapeOutput
						?>
					</div>
					<?php endif; ?>
					<div style="margin-top:10px; padding-top:7px; border-top:1px solid #f0f0f0; font-size:10px; color:#9ca3af;">
						<?php echo esc_html( number_format_i18n( $cov_total ) . ' ' . _n( 'post/page total', 'posts/pages total', $cov_total, 'aeo-pugmill' ) ); ?>
					</div>
				<?php endif; ?>
			</div>
			</div><!-- /col 2 stack -->

			<!-- Col 3: AEO Infrastructure ─────────────────────── -->
			<div class="pugmill-card">
				<h3><?php esc_html_e( 'AEO Infrastructure', 'aeo-pugmill' ); ?></h3>
				<p class="card-sub"><?php esc_html_e( 'Active AEO outputs — endpoints, schema, and meta. Shows which outputs Pugmill handles and which are delegated to other SEO plugins running on this site.', 'aeo-pugmill' ); ?></p>
				<?php
				$seo_short_g3 = $seo_name_g ? preg_replace( '/\s+\d.*$/', '', $seo_name_g ) : 'SEO';
				$infra_groups = array(
					array(
						'heading' => 'AEO Endpoints',
						'color'   => '#7c3aed',
						'items'   => array(
							array( 'name' => 'llms.txt',      'type' => $llms_off_g   ? 'off' : 'pm' ),
							array( 'name' => 'llms-full.txt', 'type' => $llms_off_g   ? 'off' : 'pm' ),
							array( 'name' => 'RSS+AEO Feed',  'type' => $rss_off_g    ? 'off' : 'pm' ),
							array( 'name' => 'robots.txt',    'type' => $robots_off_g ? 'off' : 'pm' ),
							array( 'name' => 'Post Markdown', 'type' => 'pm' ),
							array( 'name' => 'Site Summary',  'type' => 'pm' ),
							array( 'name' => 'HTML+AEO',      'type' => 'pm' ),
						),
					),
					array(
						'heading' => 'Structured Data',
						'color'   => '#16a34a',
						'items'   => array(
							array( 'name' => 'Q&A Pairs',    'type' => 'pm' ),
							array( 'name' => 'Named Entities','type' => 'pm' ),
							array( 'name' => 'Citation JSON-LD', 'type' => 'pm' ),
							array( 'name' => 'Article JSON-LD',  'type' => ( $defer_json_ld_g && $seo_name_g ) ? 'seo' : 'pm' ),
							array( 'name' => 'Breadcrumbs',   'type' => ( $defer_bc_g      && $seo_name_g ) ? 'seo' : 'pm' ),
						),
					),
					array(
						'heading' => 'Meta Tags',
						'color'   => '#0369a1',
						'items'   => array(
							array( 'name' => 'Meta Description', 'type' => ( $defer_meta_g && $seo_name_g ) ? 'seo' : 'pm' ),
							array( 'name' => 'Open Graph',       'type' => ( $defer_meta_g && $seo_name_g ) ? 'seo' : 'pm' ),
						),
					),
				);
				foreach ( $infra_groups as $grp ) :
				?>
				<div class="pugmill-feat-group" style="color:<?php echo esc_attr( $grp['color'] ); ?>;"><?php echo esc_html( $grp['heading'] ); ?></div>
				<?php foreach ( $grp['items'] as $feat ) :
					$fdot = $feat['type'] === 'pm'  ? '#16a34a' : ( $feat['type'] === 'seo' ? '#3b82f6' : '#d1d5db' );
					$flbl = $feat['type'] === 'pm'  ? 'Pugmill' : ( $feat['type'] === 'seo' ? $seo_short_g3 : 'Off' );
				?>
				<div class="pugmill-feat-row">
					<span class="pugmill-feat-dot" style="background:<?php echo esc_attr( $fdot ); ?>;"></span>
					<span class="pugmill-feat-name"><?php echo esc_html( $feat['name'] ); ?></span>
					<span class="pugmill-feat-badge <?php echo esc_attr( $feat['type'] ); ?>"><?php echo esc_html( $flbl ); ?></span>
				</div>
				<?php endforeach; ?>
				<?php endforeach; ?>
				<div style="margin-top:8px; padding-top:7px; border-top:1px solid #f0f0f0; font-size:10px; color:#9ca3af;">
					<?php echo esc_html( $gauge_active . ' of ' . $gauge_total . ' ' . __( 'outputs active', 'aeo-pugmill' ) ); ?>
				</div>
			</div>

		</div><!-- /.pugmill-summary-row -->

		<?php endif; ?>





		<!-- Content Reach ───────────────────────────────────────────────────── -->
		<?php
		// Category label + badge colour maps
		$cat_labels = array( 'aeo' => 'AEO Endpoints', 'discovery' => 'Discovery', 'crawl' => 'Page Crawls' );
		$cat_badge  = array( 'aeo' => '#16a34a',       'discovery' => '#2563eb',   'crawl' => '#9ca3af'   );
		$cat_bg_cr  = array( 'aeo' => '#f0fdf4',       'discovery' => '#eff6ff',   'crawl' => '#f9fafb'   );

		// Fixed column order — all tracked resource types, grouped by category.
		$col_order_by_cat = array(
			'aeo'       => array( 1, 2, 3, 4, 8, 11, 7 ), // llms.txt, llms-full, Markdown, Summary, JSON-LD, RSS+AEO, HTML+AEO
			'discovery' => array( 5, 6, 10 ),              // Sitemap, Robots.txt, Well-Known
			'crawl'     => array( 0, 9 ),                  // HTML (plain, no AEO), RSS Feed (plain)
		);
		$col_order_ordered = array();
		foreach ( $col_order_by_cat as $_cat => $_ids ) {
			foreach ( $_ids as $_tid ) { $col_order_ordered[] = $_tid; }
		}

		// Short column labels for the table (full names shown on hover).
		$short_labels = array(
			1  => 'llms.txt',
			2  => 'llms-full',
			3  => 'Markdown',
			4  => 'Summary',
			8  => 'JSON-LD',
			5  => 'Sitemap',
			6  => 'Robots',
			11 => 'RSS+AEO',
			9  => 'RSS Feed',
			10 => 'Well-Known',
			0  => 'HTML',
			7  => 'HTML+AEO',
		);

		// Endpoint card metadata — name, URL pattern, and category for each tracked type.
		$endpoint_meta = array(
			1  => array( 'group' => 'aeo',       'name' => 'llms.txt',      'url' => '/llms.txt' ),
			2  => array( 'group' => 'aeo',       'name' => 'llms-full.txt', 'url' => '/llms-full.txt' ),
			3  => array( 'group' => 'aeo',       'name' => 'Post Markdown', 'url' => '/*/?aeopugmill_llm=1' ),
			4  => array( 'group' => 'aeo',       'name' => 'Site Summary',  'url' => '/?aeopugmill_llm=1' ),
			8  => array( 'group' => 'aeo',       'name' => 'AEO JSON-LD',   'url' => '/aeo/*.jsonld' ),
			5  => array( 'group' => 'discovery', 'name' => 'Sitemap',       'url' => '/sitemap.xml, /wp-sitemap-*.xml' ),
			6  => array( 'group' => 'discovery', 'name' => 'Robots.txt',    'url' => '/robots.txt' ),
			11 => array( 'group' => 'aeo',       'name' => 'RSS+AEO',       'url' => '/feed/ — carries AEO summaries, entities, Q&amp;A' ),
			7  => array( 'group' => 'aeo',       'name' => 'HTML + AEO',    'url' => __( 'Posts with AEO metadata', 'aeo-pugmill' ) ),
			10 => array( 'group' => 'discovery', 'name' => 'Well-Known',    'url' => '/.well-known/*, /ads.txt, …' ),
			0  => array( 'group' => 'crawl',     'name' => 'HTML',          'url' => __( 'Plain post/page crawl', 'aeo-pugmill' ) ),
			9  => array( 'group' => 'crawl',     'name' => 'RSS Feed',      'url' => __( 'Plain RSS feed (no AEO enrichment)', 'aeo-pugmill' ) ),
		);

		// Aggregate totals per endpoint + identify top bot for each.
		$endpoint_totals         = array_fill_keys( array_keys( $endpoint_meta ), 0 );
		$endpoint_top_bot        = array_fill_keys( array_keys( $endpoint_meta ), null );
		$endpoint_top_bot_count  = array_fill_keys( array_keys( $endpoint_meta ), 0 );
		foreach ( $by_resource as $bot_key => $types ) {
			foreach ( $types as $tid => $cnt ) {
				$tid = (int) $tid;
				$cnt = (int) $cnt;
				if ( ! isset( $endpoint_totals[ $tid ] ) ) { continue; }
				$endpoint_totals[ $tid ] += $cnt;
				if ( $cnt > $endpoint_top_bot_count[ $tid ] ) {
					$endpoint_top_bot_count[ $tid ] = $cnt;
					$endpoint_top_bot[ $tid ]       = $bot_key;
				}
			}
		}

		// Metric strip — overall AEO share + AI-bot AEO share.
		$aeo_ids    = array( 1, 2, 3, 4, 8, 11 );
		$ms_total   = 0;
		$ms_aeo     = 0;
		$ms_ai_all  = 0;
		$ms_ai_aeo  = 0;
		foreach ( $by_resource as $bot_key => $types ) {
			$is_ai = isset( $ai_bots[ $bot_key ] );
			foreach ( $types as $tid => $cnt ) {
				$tid = (int) $tid;
				$cnt = (int) $cnt;
				$ms_total += $cnt;
				$is_aeo    = in_array( $tid, $aeo_ids, true );
				if ( $is_aeo ) { $ms_aeo += $cnt; }
				if ( $is_ai ) {
					$ms_ai_all += $cnt;
					if ( $is_aeo ) { $ms_ai_aeo += $cnt; }
				}
			}
		}
		$ms_aeo_pct    = $ms_total  > 0 ? round( $ms_aeo    / $ms_total  * 100, 1 ) : null;
		$ms_ai_aeo_pct = $ms_ai_all > 0 ? round( $ms_ai_aeo / $ms_ai_all * 100, 1 ) : null;
		?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:24px;">
			<h3 style="margin:0 0 4px; font-size:14px; font-weight:600;">
				<?php esc_html_e( 'Content Reach', 'aeo-pugmill' ); ?>
			</h3>
			<p style="margin:0 0 16px; font-size:12px; color:#666;">
				<?php esc_html_e( 'Every endpoint the plugin exposes, with 30-day visit counts. Zero is a real signal — if an endpoint shows no hits, it is either undiscovered or unreachable.', 'aeo-pugmill' ); ?>
			</p>

			<!-- ── Endpoint cards: compact single-row grid, wraps responsively ── -->
			<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(80px, 1fr)); gap:6px; margin-bottom:14px;">
				<?php foreach ( $col_order_ordered as $tid ) :
					$meta    = $endpoint_meta[ $tid ];
					$cat_key = $meta['group'];
					$cnt     = (int) $endpoint_totals[ $tid ];
					$is_zero = ( $cnt === 0 );
					// Zero state: darker grey (WCAG-readable), not the near-invisible #d1d5db.
					$num_col = $is_zero ? '#6b7280' : '#111827';
					$bg_col  = $is_zero ? '#fafafa' : $cat_bg_cr[ $cat_key ];
					$br_col  = $is_zero ? '#e5e7eb' : $cat_badge[ $cat_key ] . '55';
				?>
				<div title="<?php echo esc_attr( $meta['name'] . ' — ' . $meta['url'] ); ?>"
				     style="background:<?php echo esc_attr( $bg_col ); ?>;
				            border:1px solid <?php echo esc_attr( $br_col ); ?>;
				            border-top:2px solid <?php echo esc_attr( $cat_badge[ $cat_key ] ); ?>;
				            border-radius:6px; padding:8px 6px; text-align:center;">
					<div style="font-size:10px; font-weight:600; color:#6b7280; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-bottom:4px;">
						<?php echo esc_html( $short_labels[ $tid ] ?? $meta['name'] ); ?>
					</div>
					<div style="font-size:18px; font-weight:800; color:<?php echo esc_attr( $num_col ); ?>; line-height:1;">
						<?php echo esc_html( number_format_i18n( $cnt ) ); ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- ── Metric strip: AEO share summaries ── -->
			<div style="display:flex; flex-wrap:wrap; gap:18px; padding:12px 14px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; margin:6px 0 14px; font-size:11px; color:#6b7280;">
				<span>
					<strong style="color:#374151;"><?php esc_html_e( '30-day snapshot:', 'aeo-pugmill' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $ms_total ) ); ?> <?php esc_html_e( 'total bot visits', 'aeo-pugmill' ); ?>
				</span>
				<span>
					<?php if ( null !== $ms_aeo_pct ) : ?>
					<?php echo esc_html( number_format_i18n( $ms_aeo ) ); ?> <?php esc_html_e( 'hit AEO endpoints', 'aeo-pugmill' ); ?>
					<strong style="color:#16a34a;">(<?php echo esc_html( $ms_aeo_pct ); ?>%)</strong>
					<?php else : ?>
					— <?php esc_html_e( 'AEO share', 'aeo-pugmill' ); ?>
					<?php endif; ?>
				</span>
				<span>
					<?php if ( null !== $ms_ai_aeo_pct ) : ?>
					<strong style="color:#7c3aed;"><?php echo esc_html( $ms_ai_aeo_pct ); ?>%</strong>
					<?php esc_html_e( 'of AI-bot traffic went to AEO', 'aeo-pugmill' ); ?>
					<?php else : ?>
					— <?php esc_html_e( 'AI-bot AEO share', 'aeo-pugmill' ); ?>
					<?php endif; ?>
				</span>
			</div>

			<!-- ── Per-bot × endpoint table ── -->
			<div style="overflow-x:auto;">
			<table class="widefat" style="font-size:12px; border-collapse:collapse;">
				<thead>
					<!-- Group header row -->
					<tr style="background:#f6f7f7;">
						<th style="padding:8px 12px; text-align:left; font-weight:600; white-space:nowrap; width:160px; border-bottom:1px solid #e5e7eb; border-right:2px solid #e5e7eb; position:sticky; left:0; background:#f6f7f7; z-index:2;" rowspan="2">
							<?php esc_html_e( 'Bot', 'aeo-pugmill' ); ?>
						</th>
						<?php foreach ( $col_order_by_cat as $cat => $cols ) : ?>
						<th colspan="<?php echo count( $cols ); ?>"
							style="padding:6px 12px; text-align:center; font-size:10px; font-weight:700;
							       text-transform:uppercase; letter-spacing:.06em;
							       color:<?php echo esc_attr( $cat_badge[ $cat ] ); ?>;
							       border-bottom:1px solid #e5e7eb;">
							<?php echo esc_html( $cat_labels[ $cat ] ); ?>
						</th>
						<?php endforeach; ?>
						<th style="padding:8px 12px; text-align:center; font-weight:600; white-space:nowrap; border-left:2px solid #e5e7eb; border-bottom:1px solid #e5e7eb;" rowspan="2">
							<?php esc_html_e( 'Total', 'aeo-pugmill' ); ?>
						</th>
					</tr>
					<!-- Column name row -->
					<tr style="background:#f6f7f7;">
						<?php foreach ( $col_order_ordered as $type_id ) : ?>
						<th title="<?php echo esc_attr( $resource_labels[ $type_id ] ?? '' ); ?>"
							style="padding:6px 10px; text-align:center; font-weight:500; white-space:nowrap; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb;">
							<?php echo esc_html( $short_labels[ $type_id ] ?? ( $resource_labels[ $type_id ] ?? '' ) ); ?>
						</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php $bot_row_idx = 0; foreach ( $bots as $bot_key => $bot_info ) :
					$row_total = 0;
					foreach ( $col_order_ordered as $type_id ) {
						$row_total += (int) ( $by_resource[ $bot_key ][ $type_id ] ?? 0 );
					}
					if ( 0 === $row_total ) continue;
					$row_bg = ( 0 === $bot_row_idx % 2 ) ? '#fff' : '#f9fafb';
					$bot_row_idx++;
				?>
				<tr style="background:<?php echo esc_attr( $row_bg ); ?>;">
					<!-- Sticky bot name column -->
					<td style="padding:8px 12px; white-space:nowrap; border-right:2px solid #e5e7eb; position:sticky; left:0; background:<?php echo esc_attr( $row_bg ); ?>; z-index:1;">
						<span style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#374151;">
							<span style="width:8px; height:8px; border-radius:50%; background:<?php echo esc_attr( $bot_info['color'] ); ?>; flex-shrink:0;"></span>
							<?php echo esc_html( $bot_info['label'] ); ?>
						</span>
					</td>
					<!-- One cell per tracked content type -->
					<?php foreach ( $col_order_ordered as $type_id ) :
						$cnt         = (int) ( $by_resource[ $bot_key ][ $type_id ] ?? 0 );
						$net_res_avg = $network_resource_avgs[ $bot_key ][ $type_id ] ?? null;
						$cell_arrow  = '';
						if ( null !== $net_res_avg && $net_res_avg >= 1 ) {
							$ratio = $cnt / $net_res_avg;
							if ( $ratio >= 1.2 ) {
								$cell_arrow = '<span style="font-size:9px; color:#16a34a; margin-left:2px;">&#8593;</span>';
							} elseif ( $ratio <= 0.8 ) {
								$cell_arrow = '<span style="font-size:9px; color:#d97706; margin-left:2px;">&#8595;</span>';
							}
						} elseif ( null !== $net_res_avg && $net_res_avg < 1 && $cnt > 0 ) {
							$cell_arrow = '<span style="font-size:9px; color:#16a34a; margin-left:2px;">&#8593;</span>';
						}
					?>
					<td style="padding:7px 10px; text-align:center; color:<?php echo $cnt > 0 ? esc_attr( $bot_info['color'] ) : '#d1d5db'; ?>; font-weight:<?php echo $cnt > 0 ? '600' : '400'; ?>; white-space:nowrap;">
						<?php echo $cnt > 0 ? esc_html( number_format_i18n( $cnt ) ) : '—'; ?>
						<?php echo wp_kses( $cell_arrow, array( 'span' => array( 'style' => array() ) ) ); ?>
					</td>
					<?php endforeach; ?>
					<!-- Row total -->
					<td style="padding:7px 10px; text-align:center; font-weight:600; color:#374151; border-left:2px solid #e5e7eb;">
						<?php echo esc_html( number_format_i18n( $row_total ) ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr style="background:#f6f7f7; border-top:2px solid #e5e7eb;">
						<td style="padding:8px 12px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#374151; border-right:2px solid #e5e7eb; position:sticky; left:0; background:#f6f7f7; z-index:1;">
							<?php esc_html_e( 'Total', 'aeo-pugmill' ); ?>
						</td>
						<?php $grand_total = 0; foreach ( $col_order_ordered as $type_id ) :
							$col_total    = (int) $endpoint_totals[ $type_id ];
							$grand_total += $col_total;
						?>
						<td style="padding:8px 10px; text-align:center; font-weight:700; color:<?php echo $col_total > 0 ? '#374151' : '#d1d5db'; ?>; white-space:nowrap;">
							<?php echo $col_total > 0 ? esc_html( number_format_i18n( $col_total ) ) : '—'; ?>
						</td>
						<?php endforeach; ?>
						<td style="padding:8px 10px; text-align:center; font-weight:800; color:#111827; border-left:2px solid #e5e7eb;">
							<?php echo esc_html( number_format_i18n( $grand_total ) ); ?>
						</td>
					</tr>
				</tfoot>
			</table>
			</div>
			<?php if ( ! empty( $network_avgs ) ) : ?>
			<p style="font-size:11px; color:#9ca3af; margin:8px 0 0;">
				<?php esc_html_e( '↑ ↓ = above / below network average for that content type', 'aeo-pugmill' ); ?>
			</p>
			<?php endif; ?>
		</div>

		<!-- Crawl Intelligence ───────────────────────────────────────────────── -->
		<?php
		// Label + colour maps for each signal
		$wc_labels  = array( '<500' => 'Short', '500-1500' => 'Medium', '1500+' => 'Long' );
		$fr_labels  = array( '0-7d' => 'Fresh', '8-30d' => 'Recent', '31-180d' => 'Mature', '180d+' => 'Archive' );
		$fd_labels  = array( 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High' );
		$fd_colors  = array( 'low' => '#9ca3af', 'medium' => '#d97706', 'high' => '#16a34a' );

		// Helper: return key of the bucket with the highest tally, or null.
		$dom = function( $dist ) {
			if ( empty( $dist ) ) return null;
			arsort( $dist );
			reset( $dist );
			return key( $dist );
		};

		// Filter intel signals to bots we know about
		$intel_bots = array_filter( $intel_signals, function( $_, $k ) use ( $bots ) {
			return isset( $bots[ $k ] );
		}, ARRAY_FILTER_USE_BOTH );

		$ci_row_idx = 0;
		?>
		<?php if ( ! empty( $intel_bots ) ) : ?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:24px;">
			<h3 style="margin:0 0 4px; font-size:14px; font-weight:600;">
				<?php esc_html_e( 'Crawl Intelligence', 'aeo-pugmill' ); ?>
			</h3>
			<p style="margin:0 0 16px; font-size:12px; color:#666;">
				<?php esc_html_e( 'What bots are actually finding when they visit — content quality, crawl behaviour, and performance signals, last 30 days.', 'aeo-pugmill' ); ?>
			</p>

			<!-- ── Content quality summary bars (aggregated across all bots) ── -->
			<?php
			// Aggregate signal distributions across every bot into site-wide totals.
			$agg_wc = array( '<500' => 0, '500-1500' => 0, '1500+' => 0 );
			$agg_fr = array( '0-7d' => 0, '8-30d' => 0, '31-180d' => 0, '180d+' => 0 );
			$agg_fd = array( 'low' => 0, 'medium' => 0, 'high' => 0 );
			foreach ( $intel_bots as $bot_key => $sig ) {
				foreach ( $sig['word_count'] ?? array() as $bk => $tv ) {
					if ( isset( $agg_wc[ $bk ] ) ) $agg_wc[ $bk ] += (int) $tv;
				}
				foreach ( $sig['content_freshness'] ?? array() as $bk => $tv ) {
					if ( isset( $agg_fr[ $bk ] ) ) $agg_fr[ $bk ] += (int) $tv;
				}
				foreach ( $sig['fact_density'] ?? array() as $bk => $tv ) {
					if ( isset( $agg_fd[ $bk ] ) ) $agg_fd[ $bk ] += (int) $tv;
				}
			}

			$quality_bars = array(
				array(
					'title'   => __( 'Word Count', 'aeo-pugmill' ),
					'buckets' => $agg_wc,
					'labels'  => $wc_labels,
					'colors'  => array( '<500' => '#f59e0b', '500-1500' => '#7c3aed', '1500+' => '#4c1d95' ),
				),
				array(
					'title'   => __( 'Freshness', 'aeo-pugmill' ),
					'buckets' => $agg_fr,
					'labels'  => $fr_labels,
					'colors'  => array( '0-7d' => '#16a34a', '8-30d' => '#65a30d', '31-180d' => '#d97706', '180d+' => '#ef4444' ),
				),
				array(
					'title'   => __( 'Fact Density', 'aeo-pugmill' ),
					'buckets' => $agg_fd,
					'labels'  => $fd_labels,
					'colors'  => array( 'low' => '#ef4444', 'medium' => '#d97706', 'high' => '#16a34a' ),
				),
			);
			?>
			<div style="display:flex; flex-direction:column; gap:8px; margin-bottom:20px;">
			<?php foreach ( $quality_bars as $qb ) :
				$qb_total = array_sum( $qb['buckets'] );
			?>
				<div>
					<div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:3px;">
						<span style="font-size:11px; font-weight:600; color:#374151;"><?php echo esc_html( $qb['title'] ); ?></span>
						<?php if ( $qb_total > 0 ) :
							// Find dominant bucket and show it as the headline.
							arsort( $qb['buckets'] );
							$dom_key = key( $qb['buckets'] );
							$dom_pct = round( $qb['buckets'][ $dom_key ] / $qb_total * 100 );
							ksort( $qb['buckets'] ); // restore order for bar rendering
						?>
						<span style="font-size:10px; color:<?php echo esc_attr( $qb['colors'][ $dom_key ] ); ?>; font-weight:700;"><?php echo esc_html( $dom_pct . '% ' . $qb['labels'][ $dom_key ] ); ?></span>
						<?php else : ?>
						<span style="font-size:10px; color:#d1d5db; font-style:italic;"><?php esc_html_e( 'no data', 'aeo-pugmill' ); ?></span>
						<?php endif; ?>
					</div>
					<div style="position:relative; height:16px; border-radius:3px; background:#f3f4f6;">
					<?php if ( $qb_total > 0 ) : ?>
					<div style="position:absolute; top:50%; transform:translateY(-50%); left:0; right:0; height:8px; display:flex; border-radius:2px; overflow:hidden;">
					<?php foreach ( $qb['buckets'] as $bk => $bv ) :
						$seg_pct = round( $bv / $qb_total * 100, 1 );
						if ( $seg_pct <= 0 ) continue;
					?>
						<div style="width:<?php echo esc_attr( $seg_pct ); ?>%; background:<?php echo esc_attr( $qb['colors'][ $bk ] ); ?>;"></div>
					<?php endforeach; ?>
					</div>
					<?php endif; ?>
					</div>
					<?php if ( $qb_total > 0 ) : ?>
					<div style="display:flex; gap:10px; margin-top:3px;">
						<?php foreach ( $qb['buckets'] as $bk => $bv ) :
							$seg_pct = round( $bv / $qb_total * 100 );
							if ( $seg_pct <= 0 ) continue;
						?>
						<span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; color:#6b7280;">
							<span style="width:8px; height:8px; border-radius:2px; background:<?php echo esc_attr( $qb['colors'][ $bk ] ); ?>; flex-shrink:0;"></span>
							<?php echo esc_html( $qb['labels'][ $bk ] ); ?>
						</span>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			</div>

			<div style="overflow-x:auto;">
			<table class="widefat" style="font-size:12px; border-collapse:collapse;">
				<thead>
					<!-- Group header row -->
					<tr style="background:#f6f7f7;">
						<th class="ci-col-bot-th" style="padding:8px 12px; text-align:left; font-weight:600; white-space:nowrap; width:160px; border-bottom:1px solid #e5e7eb; border-right:2px solid #e5e7eb;" rowspan="2">
							<?php esc_html_e( 'Bot', 'aeo-pugmill' ); ?>
						</th>
						<th colspan="3" style="padding:6px 12px; text-align:center; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#7c3aed; border-bottom:1px solid #e5e7eb;">
							<?php esc_html_e( 'Content Quality', 'aeo-pugmill' ); ?>
						</th>
						<th colspan="3" style="padding:6px 12px; text-align:center; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#0369a1; border-bottom:1px solid #e5e7eb; border-left:2px solid #e5e7eb;">
							<?php esc_html_e( 'Crawl Behavior', 'aeo-pugmill' ); ?>
						</th>
						<th colspan="1" style="padding:6px 12px; text-align:center; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#374151; border-bottom:1px solid #e5e7eb; border-left:2px solid #e5e7eb;">
							<?php esc_html_e( 'Performance', 'aeo-pugmill' ); ?>
						</th>
					</tr>
					<!-- Column names -->
					<tr style="background:#f6f7f7;">
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( 'Word Count', 'aeo-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( 'Freshness', 'aeo-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( 'Fact Density', 'aeo-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap; border-left:2px solid #e5e7eb;"><?php esc_html_e( 'URL Depth', 'aeo-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( 'URL Type', 'aeo-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( '404 Rate', 'aeo-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap; border-left:2px solid #e5e7eb;"><?php esc_html_e( 'Avg ms', 'aeo-pugmill' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $intel_bots as $bot_key => $sig ) :
					if ( ! isset( $bots[ $bot_key ] ) ) continue;
					$bot_info = $bots[ $bot_key ];
					$ci_bg    = ( 0 === $ci_row_idx % 2 ) ? '#fff' : '#f9fafb';
					$ci_row_idx++;

					// ── Content Quality ──────────────────────────────────────
					$wc_dom  = $dom( $sig['word_count'] ?? array() );
					$wc_lbl  = $wc_labels[ $wc_dom ] ?? '—';

					$fr_dom  = $dom( $sig['content_freshness'] ?? array() );
					$fr_lbl  = $fr_labels[ $fr_dom ] ?? '—';

					$fd_dom  = $dom( $sig['fact_density'] ?? array() );
					$fd_lbl  = $fd_labels[ $fd_dom ] ?? '—';
					$fd_col  = $fd_colors[ $fd_dom ] ?? '#9ca3af';

					// ── Crawl Behavior ───────────────────────────────────────
					$depth_dom = $dom( $sig['url_depth'] ?? array() );
					$depth_lbl = ( null !== $depth_dom ) ? 'Depth ' . $depth_dom : '—';

					$url_dist  = $sig['url_type'] ?? array();
					$url_clean = (int) ( $url_dist['clean'] ?? 0 );
					$url_param = (int) ( $url_dist['parameterized'] ?? 0 );
					$url_total = $url_clean + $url_param;
					if ( $url_total > 0 ) {
						$url_is_clean = $url_param / $url_total <= 0.2;
						$url_lbl = $url_is_clean ? 'Clean' : 'Mixed';
						$url_col = $url_is_clean ? '#16a34a' : '#d97706';
					} else {
						$url_lbl = '—';
						$url_col = '#9ca3af';
					}

					$status_dist = $sig['http_status'] ?? array();
					$s_ok  = (int) ( $status_dist['200'] ?? 0 );
					$s_404 = (int) ( $status_dist['404'] ?? 0 );
					$s_tot = $s_ok + $s_404;
					if ( $s_tot > 0 ) {
						$rate_404  = round( $s_404 / $s_tot * 100 );
						$r404_tier = $rate_404 >= 10 ? 'High' : ( $rate_404 >= 3 ? 'Moderate' : 'Low' );
						$r404_lbl  = $rate_404 . '% (' . $r404_tier . ')';
						$r404_col  = $rate_404 >= 10 ? '#dc2626' : ( $rate_404 >= 3 ? '#d97706' : '#16a34a' );
					} else {
						$r404_lbl = '—';
						$r404_col = '#9ca3af';
					}

					// ── Performance ──────────────────────────────────────────
					$gen_sum   = (int) ( $sig['php_gen_ms_sum']['all'] ?? 0 );
					$gen_count = (int) ( $sig['php_gen_ms_count']['all'] ?? 0 );
					if ( $gen_count > 0 ) {
						$avg_ms      = (int) round( $gen_sum / $gen_count );
						$avg_ms_tier = $avg_ms >= 1000 ? 'Slow' : ( $avg_ms >= 500 ? 'Moderate' : 'Fast' );
						$avg_ms_lbl  = number_format_i18n( $avg_ms ) . ' ms (' . $avg_ms_tier . ')';
						$avg_ms_col  = $avg_ms >= 1000 ? '#dc2626' : ( $avg_ms >= 500 ? '#d97706' : '#16a34a' );
					} else {
						$avg_ms_lbl = '—';
						$avg_ms_col = '#9ca3af';
					}
				?>
				<tr style="background:<?php echo esc_attr( $ci_bg ); ?>;">
					<td class="ci-col-bot-td" style="padding:8px 12px; white-space:nowrap; border-right:2px solid #e5e7eb;">
						<span style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#374151;">
							<span style="width:8px; height:8px; border-radius:50%; background:<?php echo esc_attr( $bot_info['color'] ); ?>; flex-shrink:0;"></span>
							<?php echo esc_html( $bot_info['label'] ); ?>
						</span>
					</td>
					<!-- Word Count -->
					<td style="padding:7px 12px; text-align:center; color:<?php echo ( '—' !== $wc_lbl ) ? esc_attr( $bot_info['color'] ) : '#d1d5db'; ?>; font-weight:<?php echo ( '—' !== $wc_lbl ) ? '600' : '400'; ?>;">
						<?php echo esc_html( $wc_lbl ); ?>
					</td>
					<!-- Freshness -->
					<td style="padding:7px 12px; text-align:center; color:<?php echo ( '—' !== $fr_lbl ) ? esc_attr( $bot_info['color'] ) : '#d1d5db'; ?>; font-weight:<?php echo ( '—' !== $fr_lbl ) ? '600' : '400'; ?>;">
						<?php echo esc_html( $fr_lbl ); ?>
					</td>
					<!-- Fact Density -->
					<td style="padding:7px 12px; text-align:center; color:<?php echo esc_attr( $fd_col ); ?>; font-weight:<?php echo ( '—' !== $fd_lbl ) ? '600' : '400'; ?>;">
						<?php echo esc_html( $fd_lbl ); ?>
					</td>
					<!-- URL Depth -->
					<td style="padding:7px 12px; text-align:center; color:<?php echo ( '—' !== $depth_lbl ) ? '#374151' : '#d1d5db'; ?>; font-weight:<?php echo ( '—' !== $depth_lbl ) ? '600' : '400'; ?>; border-left:2px solid #e5e7eb;">
						<?php echo esc_html( $depth_lbl ); ?>
					</td>
					<!-- URL Type -->
					<td style="padding:7px 12px; text-align:center; color:<?php echo esc_attr( $url_col ); ?>; font-weight:<?php echo ( '—' !== $url_lbl ) ? '600' : '400'; ?>;">
						<?php echo esc_html( $url_lbl ); ?>
					</td>
					<!-- 404 Rate -->
					<td style="padding:7px 12px; text-align:center; color:<?php echo esc_attr( $r404_col ); ?>; font-weight:<?php echo ( '—' !== $r404_lbl ) ? '600' : '400'; ?>;">
						<?php echo esc_html( $r404_lbl ); ?>
					</td>
					<!-- Avg ms -->
					<td style="padding:7px 12px; text-align:center; color:<?php echo esc_attr( $avg_ms_col ); ?>; font-weight:<?php echo ( '—' !== $avg_ms_lbl ) ? '600' : '400'; ?>; border-left:2px solid #e5e7eb;">
						<?php echo esc_html( $avg_ms_lbl ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p style="font-size:11px; color:#9ca3af; margin:8px 0 0;">
				<?php esc_html_e( 'Dominant value shown per signal. Each cell uses color and a label together to indicate status. Thresholds: Fact Density — High / Medium / Low structured content. URL Type — Clean (clean URLs) / Mixed (many parameterized URLs). 404 Rate — Low < 3%, Moderate 3–99%, High ≥10%. Avg ms — Fast < 500ms, Moderate 500–999ms, Slow ≥1s.', 'aeo-pugmill' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<!-- 30-day trend chart -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:24px;">
			<h3 style="margin:0 0 16px; font-size:14px; font-weight:600;">
				<?php esc_html_e( 'Last 30 Days', 'aeo-pugmill' ); ?>
			</h3>

			<?php if ( 0 === $total ) : ?>
			<p style="color:#9ca3af; font-size:13px; text-align:center; padding:40px 0;">
				<?php esc_html_e( 'No AI bot visits recorded yet. Visits will appear here as AI crawlers discover your site.', 'aeo-pugmill' ); ?>
			</p>
			<?php else : ?>

			<canvas id="aeopugmill-bot-chart" width="860" height="220"
				style="width:100%; height:auto; display:block;"></canvas>

			<!-- Legend -->
			<div style="display:flex; flex-wrap:wrap; gap:12px 20px; margin-top:14px; justify-content:center;">
				<?php foreach ( $bots as $bot_key => $bot_info ) :
					if ( ! isset( $daily_index[ $bot_key ] ) ) continue;
				?>
				<span style="display:flex; align-items:center; gap:6px; font-size:12px; color:#555;">
					<span style="width:20px; height:3px; background:<?php echo esc_attr( $bot_info['color'] ); ?>; border-radius:2px; display:inline-block;"></span>
					<?php echo esc_html( $bot_info['label'] ); ?>
				</span>
				<?php endforeach; ?>
			</div>

			<?php endif; ?>
		</div>

		<!-- Download Data ───────────────────────────────────────────────────── -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 24px; margin-bottom:24px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
			<span style="font-size:13px; font-weight:600; color:#374151; flex:0 0 auto;"><?php esc_html_e( 'Download Data', 'aeo-pugmill' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=aeopugmill_export_csv_daily&nonce=' . $export_nonce ) ); ?>"
			   style="display:inline-flex; align-items:center; gap:5px; padding:6px 14px; font-size:12px; font-weight:600;
			          background:#f6f7f7; color:#374151; border:1px solid #ddd; border-radius:4px; text-decoration:none;">
				⬇ <?php esc_html_e( 'Daily Aggregates CSV', 'aeo-pugmill' ); ?>
			</a>
			<span style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Daily aggregates retained for 90 days.', 'aeo-pugmill' ); ?></span>
		</div>

		<!-- Footer note -->
		<p style="color:#9ca3af; font-size:12px;">
			<?php esc_html_e( 'AI crawlers: ChatGPT (GPTBot, ChatGPT-User, OAI-SearchBot), Claude (ClaudeBot, anthropic-ai), Perplexity (PerplexityBot), Gemini (Google-Extended), Amazonbot, Meta (meta-externalagent). Search spiders: Googlebot, Bingbot, Applebot (Apple Intelligence), DuckDuckBot, Bytespider (ByteDance). Daily aggregates retained 90 days. Recent visit details kept 7 days.', 'aeo-pugmill' ); ?>
		</p>

		<?php if ( $total > 0 ) : ?>
		<?php ob_start(); ?>
		(function() {
			var canvas = document.getElementById( 'aeopugmill-bot-chart' );
			if ( ! canvas || ! canvas.getContext ) { return; }

			var labels = <?php echo wp_json_encode( array_values( $chart_labels ) ); ?>;
			var sets   = <?php echo wp_json_encode( array_values( $chart_datasets ) ); ?>;

			// Pre-compute global max (constant across redraws)
			var maxVal = 1;
			sets.forEach( function( ds ) {
				ds.values.forEach( function( v ) { if ( v > maxVal ) { maxVal = v; } } );
			} );

			// Round up to nearest 1/2/5 × 10^n for intuitive axis breaks (e.g. 6486 → 7000)
			(function() {
				var mag = Math.pow( 10, Math.floor( Math.log10( Math.max( maxVal, 1 ) ) ) );
				var factors = [ 1, 2, 5, 10 ];
				for ( var i = 0; i < factors.length; i++ ) {
					if ( factors[ i ] * mag >= maxVal ) { maxVal = factors[ i ] * mag; break; }
				}
			})();

			function drawChart() {
				var ctx  = canvas.getContext( '2d' );
				var dpr  = window.devicePixelRatio || 1;
				var cssW = canvas.parentElement.clientWidth - 48; // subtract card padding (24px each side)
				var cssH = Math.max( 180, Math.round( cssW * 0.22 ) ); // ~22% aspect ratio, min 180px

				canvas.width        = cssW * dpr;
				canvas.height       = cssH * dpr;
				canvas.style.width  = cssW + 'px';
				canvas.style.height = cssH + 'px';
				ctx.scale( dpr, dpr );

				var pad    = { top: 12, right: 16, bottom: 28, left: 40 };
				var chartW = cssW - pad.left - pad.right;
				var chartH = cssH - pad.top  - pad.bottom;
				var n      = labels.length;

				// Horizontal grid lines + Y-axis labels
				ctx.strokeStyle = '#f3f4f6';
				ctx.lineWidth   = 1;
				var gridLines = 4;
				for ( var g = 0; g <= gridLines; g++ ) {
					var gy = pad.top + chartH - ( g / gridLines ) * chartH;
					ctx.beginPath();
					ctx.moveTo( pad.left, gy );
					ctx.lineTo( pad.left + chartW, gy );
					ctx.stroke();

					ctx.fillStyle = '#9ca3af';
					ctx.font      = '10px sans-serif';
					ctx.textAlign = 'right';
					ctx.fillText( Math.round( ( g / gridLines ) * maxVal ), pad.left - 6, gy + 3 );
				}

				// X-axis labels (every 7 days + last day)
				ctx.fillStyle = '#9ca3af';
				ctx.font      = '10px sans-serif';
				ctx.textAlign = 'center';
				labels.forEach( function( label, i ) {
					if ( i % 7 === 0 || i === n - 1 ) {
						ctx.fillText( label, pad.left + ( i / ( n - 1 ) ) * chartW, cssH - pad.bottom + 14 );
					}
				} );

				// Dataset lines + dots
				sets.forEach( function( ds ) {
					if ( ! ds.values.some( function( v ) { return v > 0; } ) ) { return; }

					ctx.strokeStyle = ds.color;
					ctx.lineWidth   = 2;
					ctx.lineJoin    = 'round';
					ctx.beginPath();
					var started = false;
					ds.values.forEach( function( v, i ) {
						var px = pad.left + ( i / ( n - 1 ) ) * chartW;
						var py = pad.top  + chartH - ( v / maxVal ) * chartH;
						if ( ! started ) { ctx.moveTo( px, py ); started = true; }
						else              { ctx.lineTo( px, py ); }
					} );
					ctx.stroke();

					ds.values.forEach( function( v, i ) {
						if ( v === 0 ) { return; }
						var px = pad.left + ( i / ( n - 1 ) ) * chartW;
						var py = pad.top  + chartH - ( v / maxVal ) * chartH;
						ctx.beginPath();
						ctx.arc( px, py, 3, 0, Math.PI * 2 );
						ctx.fillStyle = ds.color;
						ctx.fill();
					} );
				} );
			}

			drawChart();

			// Redraw on resize (debounced)
			var resizeTimer;
			window.addEventListener( 'resize', function() {
				clearTimeout( resizeTimer );
				resizeTimer = setTimeout( drawChart, 100 );
			} );
		}() );
		<?php aeopugmill_inline_js( ob_get_clean() ); ?>
		<?php endif; ?>

		<?php if ( $has_api_key ) : ?>
		<?php ob_start(); ?>
		(function() {
			var btn    = document.getElementById( 'aeopugmill-insights-btn' );
			var output = document.getElementById( 'aeopugmill-insights-output' );
			var text   = document.getElementById( 'aeopugmill-insights-text' );
			var status = document.getElementById( 'aeopugmill-insights-status' );

			if ( ! btn ) { return; }

			btn.addEventListener( 'click', function() {
				var isRefresh = btn.innerHTML.indexOf( 'Refresh' ) !== -1;
				btn.disabled  = true;
				btn.classList.add( 'aeopugmill-loading' );
				btn.innerHTML = 'Analyzing…';
				output.style.display = 'block';
				if ( isRefresh ) {
					// Fade existing report instead of blanking it.
					text.style.opacity = '0.35';
					text.style.pointerEvents = 'none';
				} else {
					text.innerHTML = '<span style="color:#9ca3af;font-size:13px;">Asking AI to analyze your bot traffic…</span>';
				}
				if ( status ) { status.textContent = ''; }

				var body = new URLSearchParams( {
					action:  'aeopugmill_analytics_insights',
					nonce:   <?php echo wp_json_encode( $insights_nonce ); ?>,
					refresh: isRefresh ? '1' : '0',
				} );

				fetch( ajaxurl, { method: 'POST', body: body } )
					.then( function( r ) { return r.json(); } )
					.then( function( data ) {
						btn.disabled  = false;
						btn.classList.remove( 'aeopugmill-loading' );
						text.style.opacity = '';
						text.style.pointerEvents = '';
						if ( data.success ) {
							btn.innerHTML = '✨ Refresh Analysis';
							// Render ## headings as styled labels, paragraphs as <p> blocks.
							var lines    = data.data.text.split( '\n' );
							var parts    = [];
							var paraLines = [];
							function flushPara() {
								if ( paraLines.length ) {
									parts.push( '<p style="margin:4px 0 10px;">' + paraLines.join( '<br>' ) + '</p>' );
									paraLines = [];
								}
							}
							lines.forEach( function( line ) {
								if ( /^## /.test( line ) ) {
									flushPara();
									parts.push( '<p style="font-size:12px;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 2px;">' + line.replace( /^## /, '' ) + '</p>' );
								} else if ( line.trim() === '' ) {
									flushPara();
								} else {
									paraLines.push( line );
								}
							} );
							flushPara();
							text.innerHTML = parts.join( '' );
							if ( status ) {
								var ago = Math.round( ( Date.now() / 1000 - data.data.generated ) / 60 );
								status.textContent = ago < 1
									? '<?php echo esc_js( __( 'Generated just now', 'aeo-pugmill' ) ); ?>'
									: ago + ' <?php echo esc_js( __( 'minutes ago', 'aeo-pugmill' ) ); ?>';
							}
						} else {
							btn.innerHTML = '✨ Generate AI Analysis';
							text.innerHTML = '<span style="color:#dc3232;font-size:13px;">' + ( data.data || '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'aeo-pugmill' ) ); ?>' ) + '</span>';
						}
					} )
					.catch( function() {
						btn.disabled  = false;
						btn.classList.remove( 'aeopugmill-loading' );
						text.style.opacity = '';
						text.style.pointerEvents = '';
						btn.innerHTML = '✨ Generate AI Analysis';
						text.innerHTML  = '<span style="color:#dc3232;font-size:13px;"><?php echo esc_js( __( 'Request failed. Please try again.', 'aeo-pugmill' ) ); ?></span>';
					} );
			} );
		}() );
		<?php aeopugmill_inline_js( ob_get_clean() ); ?>
		<?php endif; ?>

		<?php if ( ! get_option( 'aeopugmill_analytics_opted_in' ) ) : ?>
		<!-- Bottom opt-in CTA — visible while not joined to the network -->
		<div style="margin-top:20px; background:#faf5ff; border:1px solid #e9d5ff; border-radius:8px; padding:18px 22px; display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap;">
			<div style="flex:1; min-width:260px;">
				<p style="margin:0 0 4px; font-size:13px; font-weight:700; color:#6b21a8;">
					<?php esc_html_e( 'Want network benchmarks?', 'aeo-pugmill' ); ?>
				</p>
				<p style="margin:0; font-size:12px; color:#374151; line-height:1.5;">
					<?php esc_html_e( 'Join the Pugmill AEO Intelligence Network to see how your bot traffic compares to similar sites. Free — opt out anytime, your historical data stays on your site.', 'aeo-pugmill' ); ?>
				</p>
			</div>
			<form method="post" action="options.php" style="margin:0; flex-shrink:0;">
				<?php settings_fields( 'aeopugmill_analytics' ); ?>
				<input type="hidden" name="aeopugmill_analytics_opted_in" value="1">
				<?php submit_button( __( 'Join the Network', 'aeo-pugmill' ), 'primary', 'submit', false, array( 'style' => 'background:#7c3aed; border-color:#7c3aed; font-size:13px; height:34px; padding:0 18px;' ) ); ?>
			</form>
		</div>
		<?php endif; ?>

		<?php if ( 'dashboard' === $active_tab && get_option( 'aeopugmill_analytics_opted_in' ) ) : ?>
		<div style="margin-top:8px; display:flex; align-items:center; gap:8px; font-size:12px; color:#9ca3af;">
			<span>&#10003; <?php
				if ( $network_sites >= 1 ) {
					printf(
						/* translators: %d: number of contributing sites */
						esc_html__( 'Network averages from %d participating sites', 'aeo-pugmill' ),
						(int) $network_sites
					);
				} else {
					esc_html_e( 'Pugmill AEO Intelligence Network — network averages appear once 10+ sites contribute', 'aeo-pugmill' );
				}
			?></span>
			<span style="color:#ddd;">|</span>
			<form id="aeopugmill-leave-network-form" method="post" action="options.php" style="margin:0; padding:0;">
				<?php settings_fields( 'aeopugmill_analytics' ); ?>
				<input type="hidden" name="aeopugmill_analytics_opted_in" value="0">
				<button type="submit" style="background:none; border:none; padding:0; color:#dc2626; font-size:12px; cursor:pointer; text-decoration:underline;">
					<?php esc_html_e( 'Leave network', 'aeo-pugmill' ); ?>
				</button>
			</form>
		</div>
		<?php ob_start(); ?>
		( function() {
			/* Confirm before leaving the intelligence network */
			var leaveForm = document.getElementById( 'aeopugmill-leave-network-form' );
			if ( leaveForm ) {
				leaveForm.addEventListener( 'submit', function( e ) {
					if ( ! window.confirm( <?php echo wp_json_encode( __( 'Leave the Pugmill AEO Intelligence Network? This will also disable Bot Analytics — your historical data stays on your site but you will no longer see crawler or spider activity.', 'aeo-pugmill' ) ); ?> ) ) {
						e.preventDefault();
					}
				} );
			}

			var link   = document.getElementById( 'aeopugmill-resync-link' );
			var result = document.getElementById( 'aeopugmill-resync-result' );
			if ( ! link ) return;
			link.addEventListener( 'click', function( e ) {
				e.preventDefault();
				link.style.pointerEvents = 'none';
				link.style.opacity = '0.5';
				link.textContent = '<?php echo esc_js( __( 'Syncing…', 'aeo-pugmill' ) ); ?>';
				result.textContent = '';
				var data = new FormData();
				data.append( 'action', 'aeopugmill_manual_send' );
				data.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'aeopugmill_manual_send' ) ); ?>' );
				fetch( ajaxurl, { method: 'POST', body: data } )
					.then( function( r ) { return r.json(); } )
					.then( function( json ) {
						if ( json.success ) {
							result.textContent = '✓';
							result.style.color = '#16a34a';
						} else {
							result.textContent = '✗';
							result.style.color = '#dc2626';
						}
					} )
					.catch( function() {
						result.textContent = '✗';
						result.style.color = '#dc2626';
					} )
					.finally( function() {
						link.style.pointerEvents = '';
						link.style.opacity = '';
						link.textContent = '<?php echo esc_js( __( 'Resync now', 'aeo-pugmill' ) ); ?>';
					} );
			} );
		}() );
		<?php aeopugmill_inline_js( ob_get_clean() ); ?>
		<?php endif; ?>

		<?php elseif ( 'site-aeo' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     SITE AEO TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php
		// Pre-fill org name from blog name when never set
		$org_name_saved   = get_option( 'aeopugmill_org_name', '' );
		$org_name_display = $org_name_saved !== '' ? $org_name_saved : get_bloginfo( 'name' );
		// Site summary generation is free with BYOK — only requires an API key.
		$ai_available     = ! empty( aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' ) );
		?>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:24px;">
			<?php esc_html_e( 'Site AEO metadata describes your organization to AI crawlers at a site-wide level. The summary and organization details are published in your /llms.txt file and embedded in Organization schema in every page header. Setting these accurately gives AI answer engines — ChatGPT, Perplexity, Gemini — a reliable source of truth about who you are and what your site covers.', 'aeo-pugmill' ); ?>
		</p>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:16px;">
		<form method="post" action="options.php">
			<?php settings_fields( 'aeopugmill_settings' ); ?>
			<table class="form-table">
				<tr>
					<th style="vertical-align:top; padding-top:12px;"><label for="aeopugmill_site_summary"><?php esc_html_e( 'Site Summary', 'aeo-pugmill' ); ?></label></th>
					<td>
						<textarea id="aeopugmill_site_summary" name="aeopugmill_site_summary" rows="7" style="width:100%; max-width:600px; font-family:monospace; font-size:13px;"><?php echo esc_textarea( get_option( 'aeopugmill_site_summary', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Used in /llms.txt and Organization schema. Describe your site for AI crawlers.', 'aeo-pugmill' ); ?></p>
						<?php if ( $ai_available ) : ?>
						<p style="margin-top:8px;">
							<button type="button" id="aeopugmill-gen-site-summary" style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600; background:#7c3aed; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap;">
								✨ <?php esc_html_e( 'Draft with AI', 'aeo-pugmill' ); ?>
							</button>
							<span id="aeopugmill-site-summary-status" style="margin-left:10px; font-size:13px; color:#666;"></span>
						</p>
						<?php else : ?>
						<p style="margin-top:8px;">
							<button type="button" disabled
								style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600;
								       background:#e5e7eb; color:#9ca3af; border:none; border-radius:4px; cursor:not-allowed; white-space:nowrap;">
								✨ <?php esc_html_e( 'Draft with AI', 'aeo-pugmill' ); ?>
								<span style="font-size:9px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; background:#f3e8ff; color:#7c3aed; padding:1px 6px; border-radius:3px; line-height:1.4;">AI</span>
							</button>
						</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="aeopugmill_org_name"><?php esc_html_e( 'Organization Name', 'aeo-pugmill' ); ?></label></th>
					<td>
						<input type="text" id="aeopugmill_org_name" name="aeopugmill_org_name"
							value="<?php echo esc_attr( $org_name_display ); ?>"
							style="width:300px;">
						<?php if ( $org_name_saved === '' ) : ?>
						<p class="description"><?php esc_html_e( 'Pre-filled from your site title — save to confirm.', 'aeo-pugmill' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="aeopugmill_org_type"><?php esc_html_e( 'Organization Type', 'aeo-pugmill' ); ?></label></th>
					<td>
						<select id="aeopugmill_org_type" name="aeopugmill_org_type">
							<?php foreach ( array( 'Person', 'Organization', 'Corporation', 'LocalBusiness', 'EducationalOrganization', 'NGO' ) as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( get_option( 'aeopugmill_org_type', 'Organization' ), $type ); ?>><?php echo esc_html( $type ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		</div><!-- /site-aeo card -->

		<?php if ( $ai_available ) : ?>
		<?php ob_start(); ?>
		(function() {
			var btn     = document.getElementById( 'aeopugmill-gen-site-summary' );
			var textarea = document.getElementById( 'aeopugmill_site_summary' );
			var status  = document.getElementById( 'aeopugmill-site-summary-status' );
			if ( ! btn || ! textarea ) { return; }

			btn.addEventListener( 'click', function() {
				btn.disabled  = true;
				btn.classList.add( 'aeopugmill-loading' );
				btn.innerHTML = '<?php echo esc_js( __( 'Drafting…', 'aeo-pugmill' ) ); ?>';
				status.textContent = '';
				status.style.color = '';

				var body = new URLSearchParams();
				body.append( 'action', 'aeopugmill_generate_site_summary' );
				body.append( 'nonce',  <?php echo wp_json_encode( wp_create_nonce( 'aeopugmill_generate_site_summary' ) ); ?> );

				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        body.toString(),
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( res ) {
					if ( res.success && res.data && res.data.summary ) {
						textarea.value     = res.data.summary;
						status.textContent = '<?php echo esc_js( __( '✓ Drafted — review and save.', 'aeo-pugmill' ) ); ?>';
						status.style.color = '#46b450';
					} else {
						var msg = res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Generation failed. Please try again.', 'aeo-pugmill' ) ); ?>';
						status.innerHTML   = msg;
						status.style.color = '#dc3232';
					}
				} )
				.catch( function() {
					status.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'aeo-pugmill' ) ); ?>';
					status.style.color = '#dc3232';
				} )
				.finally( function() {
					btn.disabled  = false;
					btn.classList.remove( 'aeopugmill-loading' );
					btn.innerHTML = '✨ <?php echo esc_js( __( 'Draft with AI', 'aeo-pugmill' ) ); ?>';
				} );
			} );
		}());
		<?php aeopugmill_inline_js( ob_get_clean() ); ?>
		<?php endif; ?>

		<?php elseif ( 'compatibility' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     COMPATIBILITY TAB — AEO-centric layout
		     ════════════════════════════════════════════════════════════ -->
		<?php
		$compat               = aeopugmill_get_compatibility_data();
		$detected_seo         = aeopugmill_detected_seo_plugins();
		$has_seo_plugin       = ! empty( $detected_seo );
		$disable_robots          = (int) get_option( 'aeopugmill_disable_robots_append', 0 );
		$disable_json_ld         = (int) get_option( 'aeopugmill_disable_json_ld', 0 );
		$disable_seo_meta        = (int) get_option( 'aeopugmill_disable_seo_meta', 0 );
		$disable_breadcrumbs     = (int) get_option( 'aeopugmill_disable_breadcrumbs', 0 );
		$disable_rss_enrichment  = (int) get_option( 'aeopugmill_disable_rss_enrichment', 0 );
		?>

		<!-- ── SEO Plugin Status ─────────────────────────────────────── -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:24px;">
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:0;"><?php esc_html_e( 'SEO Plugin Status', 'aeo-pugmill' ); ?></h2>

		<?php if ( $has_seo_plugin ) : ?>
		<div style="background:#fef9ec; border-left:4px solid #ffb900; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:16px;">
			<strong style="font-size:13px;">⚠ <?php esc_html_e( 'SEO Plugin Detected', 'aeo-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
			<?php printf(
				/* translators: %s: active SEO plugin name */
				esc_html__( '%s is active. Pugmill AEO Toolkit focuses on AEO — the AI layer that SEO plugins don\'t cover. Review the settings below to avoid duplicate output.', 'aeo-pugmill' ),
				esc_html( aeopugmill_seo_plugin_names() )
			); ?>
			</span>
		</div>
		<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:0;">
			<?php foreach ( $detected_seo as $slug => $display_name ) : ?>
			<span style="display:inline-flex; align-items:center; gap:6px; background:#f0f6ff; border:1px solid #b3d1f5; border-radius:4px; padding:5px 12px; font-size:12px; font-weight:600; color:#2271b1;">
				&#10003; <?php echo esc_html( $display_name ); ?>
			</span>
			<?php endforeach; ?>
		</div>
		<?php else : ?>
		<div style="display:flex; align-items:center; gap:10px; padding:12px 16px; background:#f0faf0; border:1px solid #46b450; border-radius:4px;">
			<span style="color:#46b450; font-size:18px;">&#10003;</span>
			<span style="font-size:13px; color:#1e6e2e; font-weight:600;"><?php esc_html_e( 'No conflicts — no other SEO plugin detected. Pugmill AEO Toolkit is managing all outputs.', 'aeo-pugmill' ); ?></span>
		</div>
		<?php endif; ?>
		</div><!-- /seo plugin status card -->

		<!-- ── Overlapping SEO Outputs ────────────────────────────────── -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:16px;">
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:0;"><?php esc_html_e( 'Overlapping SEO Outputs', 'aeo-pugmill' ); ?></h2>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:0;">
			<?php if ( $has_seo_plugin ) : ?>
			<?php printf(
				/* translators: %s: active SEO plugin name */
				esc_html__( 'The outputs below are also generated by %s. Disable Pugmill AEO Toolkit\'s versions to avoid duplicate tags in your page source. Your AEO content is always saved — only the &lt;head&gt; output changes.', 'aeo-pugmill' ),
				esc_html( aeopugmill_seo_plugin_names() )
			); ?>
			<?php else : ?>
			<?php esc_html_e( 'Pugmill AEO Toolkit is the only plugin managing these outputs. No conflicts detected.', 'aeo-pugmill' ); ?>
			<?php endif; ?>
		</p>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:0; color:#666;">
			<?php esc_html_e( 'Pugmill AEO Toolkit automatically detects: Yoast SEO, Rank Math, All in One SEO, The SEO Framework, and SEOPress. If you use a different SEO plugin, manage these outputs manually.', 'aeo-pugmill' ); ?>
		</p>

		<form method="post" action="options.php" style="margin:0;">
		<?php settings_fields( 'aeopugmill_settings' ); ?>

		<table style="width:100%; border-collapse:collapse;">
		<thead>
			<tr style="border-bottom:2px solid #e5e7eb;">
				<th style="text-align:left; padding:8px 0; font-size:12px; color:#6b7280; font-weight:600; width:40%;"><?php esc_html_e( 'Output', 'aeo-pugmill' ); ?></th>
				<th style="text-align:left; padding:8px 0; font-size:12px; color:#6b7280; font-weight:600; width:30%;"><?php esc_html_e( 'Handled by', 'aeo-pugmill' ); ?></th>
				<th style="text-align:left; padding:8px 0; font-size:12px; color:#6b7280; font-weight:600; width:30%;"><?php esc_html_e( 'Pugmill AEO Toolkit', 'aeo-pugmill' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php
		$seo_plugin_label = $has_seo_plugin ? esc_html( aeopugmill_seo_plugin_names() ) : '<span style="color:#6b7280">' . esc_html__( 'No plugin', 'aeo-pugmill' ) . '</span>';
		$plugin_badge     = $has_seo_plugin ? '<span style="background:#f0f6ff; border:1px solid #b3d1f5; border-radius:3px; padding:2px 8px; font-size:11px; font-weight:600; color:#2271b1;">' . esc_html( aeopugmill_seo_plugin_names() ) . '</span>' : '<span style="color:#6b7280; font-size:12px;">' . esc_html__( 'None active', 'aeo-pugmill' ) . '</span>';

		$overlap_rows = array(
			array(
				'label'       => __( 'Meta Description', 'aeo-pugmill' ),
				'desc'        => __( '<meta name="description">', 'aeo-pugmill' ),
				'option'      => 'aeopugmill_disable_seo_meta',
				'current_val' => $disable_seo_meta,
				'group_size'  => 3,
			),
			array(
				'label'  => __( 'Open Graph Tags', 'aeo-pugmill' ),
				'desc'   => __( 'og:title, og:description, og:image', 'aeo-pugmill' ),
				'linked' => true,
			),
			array(
				'label'     => __( 'Twitter / X Cards', 'aeo-pugmill' ),
				'desc'      => __( 'twitter:card, twitter:title, twitter:description', 'aeo-pugmill' ),
				'linked'    => true,
				'group_end' => true,
			),
			array(
				'label'       => __( 'Article JSON-LD', 'aeo-pugmill' ),
				'desc'        => __( 'BlogPosting / WebPage schema node', 'aeo-pugmill' ),
				'option'      => 'aeopugmill_disable_json_ld',
				'current_val' => $disable_json_ld,
			),
			array(
				'label'       => __( 'Breadcrumb Schema', 'aeo-pugmill' ),
				'desc'        => __( 'BreadcrumbList JSON-LD', 'aeo-pugmill' ),
				'option'      => 'aeopugmill_disable_breadcrumbs',
				'current_val' => $disable_breadcrumbs,
			),
			array(
				'label'       => __( 'RSS+AEO Enrichment', 'aeo-pugmill' ),
				'desc'        => __( 'xmlns:aeo namespace + summary, entity, Q&A elements per item', 'aeo-pugmill' ),
				'option'      => 'aeopugmill_disable_rss_enrichment',
				'current_val' => $disable_rss_enrichment,
				'rss_note'    => ! empty( $compat['rss_conflicts'] ),
			),
		);
		?>
		<?php
		foreach ( $overlap_rows as $row ) :
			$is_linked    = ! empty( $row['linked'] );
			$is_disabled  = ! $is_linked && (bool) $row['current_val'];
			$status_style = $is_disabled ? 'color:#6b7280; text-decoration:line-through;' : 'color:#111827;';
			$rowspan      = ! empty( $row['group_size'] ) ? ' rowspan="' . (int) $row['group_size'] . '"' : '';
			$has_rss_note = ! empty( $row['rss_note'] );
			$badge_text   = $is_disabled
				? esc_html__( 'Suppressed', 'aeo-pugmill' )
				: ( $has_rss_note ? esc_html__( 'Active — additive', 'aeo-pugmill' ) : ( $has_seo_plugin ? esc_html__( 'Active — overlap', 'aeo-pugmill' ) : esc_html__( 'Active', 'aeo-pugmill' ) ) );
			$badge_color  = $is_disabled
				? '#6b7280'
				: ( $has_rss_note ? '#0369a1' : ( $has_seo_plugin ? '#d97706' : '#16a34a' ) );
			$in_group      = ! empty( $row['group_size'] ) || $is_linked;
		$close_group   = ! empty( $row['group_end'] );
		$border_bottom = ( $in_group && ! $close_group ) ? 'border-bottom:none;' : 'border-bottom:1px solid #f3f4f6;';
		?>
		<tr style="<?php echo esc_attr( $border_bottom ); ?>">
			<td style="padding:10px 0;">
				<div style="font-size:13px; font-weight:600; <?php echo $status_style; // phpcs:ignore ?>"><?php echo esc_html( $row['label'] ); ?></div>
				<div style="font-size:11px; color:#9ca3af; font-family:monospace;"><?php echo esc_html( $row['desc'] ); ?></div>
			</td>
			<?php if ( ! $is_linked ) : ?>
			<td style="padding:10px 0; vertical-align:middle;"<?php echo $rowspan; // phpcs:ignore ?>>
				<?php if ( $has_rss_note ) : ?>
					<span style="background:#e0f2fe; border:1px solid #7dd3fc; border-radius:3px; padding:2px 8px; font-size:11px; font-weight:600; color:#0369a1;"><?php echo esc_html( implode( ', ', $compat['rss_conflicts'] ) ); ?></span>
					<span style="display:block; font-size:10px; color:#6b7280; margin-top:2px;"><?php esc_html_e( 'Additive — no XML conflict', 'aeo-pugmill' ); ?></span>
				<?php else : ?>
					<?php echo $plugin_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</td>
			<td style="padding:10px 0; vertical-align:middle;"<?php echo $rowspan; // phpcs:ignore ?>>
				<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
					<span style="font-size:11px; font-weight:600; color:<?php echo esc_attr( $badge_color ); ?>;"><?php echo $badge_text; // phpcs:ignore ?></span>
					<label style="display:flex; align-items:center; gap:5px; font-size:12px; cursor:pointer; margin:0;">
						<input type="hidden" name="<?php echo esc_attr( $row['option'] ); ?>" value="0">
						<input type="checkbox" name="<?php echo esc_attr( $row['option'] ); ?>" value="1"<?php checked( 1, $row['current_val'] ); ?> style="margin:0;">
						<?php echo $has_seo_plugin
							? esc_html( sprintf( /* translators: %s: SEO plugin name e.g. "Yoast SEO" */ __( 'Let %s handle this', 'aeo-pugmill' ), aeopugmill_seo_plugin_names() ) )
							: esc_html__( 'Disable', 'aeo-pugmill' ); ?>
					</label>
				</div>
			</td>
			<?php endif; ?>
		</tr>
		<?php endforeach; ?>
		</tbody>
		</table>

		<?php if ( $has_seo_plugin ) : ?>
		<div style="margin-top:14px; padding:10px 14px; background:#f0faf0; border:1px solid #c3e6c3; border-radius:4px; font-size:12px; color:#1e6e2e;">
			&#128161; <?php printf(
				/* translators: %s: active SEO plugin name */
				esc_html__( 'Recommended: Disable Meta Description, Open Graph Tags, Twitter Cards, Article JSON-LD, and Breadcrumb Schema — let %s handle them. Pugmill AEO Toolkit will still output its unique AEO additions (FAQPage, entity graph, citations) alongside it.', 'aeo-pugmill' ),
				esc_html( aeopugmill_seo_plugin_names() )
			); ?>
		</div>
		<?php endif; ?>

		<?php submit_button( __( 'Save Changes', 'aeo-pugmill' ) ); ?>
		</form>
		</div><!-- /overlapping outputs card -->

		<!-- ── OUTPUT FILES ─────────────────────────────────────────── -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:16px;">
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:0;"><?php esc_html_e( 'Output Files', 'aeo-pugmill' ); ?></h2>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:0;"><?php esc_html_e( 'Pugmill AEO Toolkit generates three key crawl files: an XML sitemap, a robots.txt, and an llms.txt. View live file previews and resolve conflicts if another plugin is already handling one of these files.', 'aeo-pugmill' ); ?></p>

		<?php ob_start(); ?>
		.aeopugmill-col-preview {
			margin: 0;
			padding: 10px 14px;
			font-size: 11px;
			line-height: 1.5;
			max-height: 180px;
			overflow: auto;
			background: #fff;
			white-space: pre;
			overflow-x: auto;
			font-family: Consolas, 'Courier New', monospace;
			color: #333;
			border-top: 1px solid #eee;
		}
		.aeopugmill-file-row {
			margin-bottom: 28px;
		}
		.aeopugmill-file-row h3 {
			font-size: 14px;
			font-weight: 600;
			margin: 0 0 4px;
		}
		.aeopugmill-preview-card {
			border: 2px solid #46b450;
			border-radius: 6px;
			overflow: hidden;
			margin-top: 12px;
		}
		.aeopugmill-preview-card.has-conflict {
			border-color: #ddd;
		}
		.aeopugmill-preview-card-header {
			padding: 10px 14px;
			background: #f0faf0;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.aeopugmill-preview-card.has-conflict .aeopugmill-preview-card-header {
			background: #f6f7f7;
		}
		.aeopugmill-conflict-block {
			margin-top: 12px;
			border: 1px solid #f5c542;
			border-left: 4px solid #ffb900;
			border-radius: 0 4px 4px 0;
			background: #fff8e1;
			padding: 12px 16px;
		}
		.aeopugmill-conflict-block-title {
			font-weight: 600;
			font-size: 13px;
			margin-bottom: 10px;
		}
		.aeopugmill-conflict-item {
			margin-bottom: 8px;
			font-size: 13px;
			line-height: 1.5;
			color: #444;
		}
		.aeopugmill-conflict-item:last-child { margin-bottom: 0; }
		.aeopugmill-conflict-item strong { color: #222; }
		.aeopugmill-no-conflict {
			font-size: 12px;
			color: #46b450;
			margin-top: 8px;
		}
		<?php aeopugmill_inline_css( ob_get_clean() ); ?>

		<form method="post" action="options.php">
		<?php settings_fields( 'aeopugmill_settings' ); ?>

		<!-- ── sitemap.xml ─────────────────────────────────────────── -->
		<div class="aeopugmill-file-row">
			<h3>/sitemap.xml</h3>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'Lists all your public posts and pages so search engines and AI crawlers can discover them. Pugmill AEO Toolkit\'s version adds an xhtml:link alternate to each entry, pointing crawlers directly to the structured AEO version of each page.', 'aeo-pugmill' ); ?></p>
			<p style="margin:0 0 12px;">
				<a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" target="_blank" class="button button-secondary">
					<?php esc_html_e( 'View sitemap.xml →', 'aeo-pugmill' ); ?>
				</a>
			</p>

			<div class="aeopugmill-preview-card <?php echo ! empty( $compat['sitemap_conflicts'] ) ? 'has-conflict' : ''; ?>">
				<div class="aeopugmill-preview-card-header">
					<?php if ( empty( $compat['sitemap_conflicts'] ) ) : ?>
					<span style="color:#46b450; font-weight:bold;">&#10003;</span>
					<span style="font-weight:600; font-size:13px; color:#1e6e2e;"><?php esc_html_e( 'No sitemap conflicts', 'aeo-pugmill' ); ?></span>
					<?php else : ?>
					<span style="font-weight:600; font-size:13px; color:#333;">
						<?php printf(
							/* translators: %d: number of sitemap conflicts detected */
							esc_html( _n( '%d conflict detected', '%d conflicts detected', count( $compat['sitemap_conflicts'] ), 'aeo-pugmill' ) ),
							(int) count( $compat['sitemap_conflicts'] )
						); ?>
					</span>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $compat['sitemap_conflicts'] ) ) : ?>
			<div class="aeopugmill-conflict-block">
				<div class="aeopugmill-conflict-block-title">
					&#9888; <?php esc_html_e( 'Sitemap conflict — another plugin may be serving /sitemap.xml', 'aeo-pugmill' ); ?>
				</div>
				<?php foreach ( $compat['sitemap_conflicts'] as $conflict ) : ?>
				<div class="aeopugmill-conflict-item">
					<strong><?php echo esc_html( $conflict['name'] ); ?>:</strong> <?php echo esc_html( $conflict['instruction'] ); ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div><!-- /sitemap row -->

		<!-- ── llms.txt ────────────────────────────────────────────── -->
		<div class="aeopugmill-file-row">
			<h3>/llms.txt</h3>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'Serves a structured index of your content in the format AI assistants and LLMs expect. Pugmill\'s version includes your AEO metadata — summaries, Q&A pairs, and entity data.', 'aeo-pugmill' ); ?></p>
			<p style="margin:0 0 12px;">
				<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" class="button button-secondary">
					<?php esc_html_e( 'View llms.txt →', 'aeo-pugmill' ); ?>
				</a>
			</p>

			<div class="aeopugmill-preview-card <?php echo ! empty( $compat['llms_txt_conflicts'] ) ? 'has-conflict' : ''; ?>">
				<div class="aeopugmill-preview-card-header">
					<?php if ( empty( $compat['llms_txt_conflicts'] ) ) : ?>
					<span style="color:#46b450; font-weight:bold;">&#10003;</span>
					<span style="font-weight:600; font-size:13px; color:#1e6e2e;"><?php esc_html_e( 'No llms.txt conflicts', 'aeo-pugmill' ); ?></span>
					<?php else : ?>
					<span style="font-weight:600; font-size:13px; color:#333;">
						<?php printf(
							/* translators: %d: number of llms.txt conflicts detected */
							esc_html( _n( '%d conflict detected', '%d conflicts detected', count( $compat['llms_txt_conflicts'] ), 'aeo-pugmill' ) ),
							(int) count( $compat['llms_txt_conflicts'] )
						); ?>
					</span>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $compat['llms_txt_conflicts'] ) ) : ?>
			<div class="aeopugmill-conflict-block">
				<div class="aeopugmill-conflict-block-title">
					&#9888; <?php esc_html_e( 'llms.txt conflict — another plugin may be serving /llms.txt', 'aeo-pugmill' ); ?>
				</div>
				<?php foreach ( $compat['llms_txt_conflicts'] as $conflict ) : ?>
				<div class="aeopugmill-conflict-item">
					<strong><?php echo esc_html( $conflict['name'] ); ?>:</strong> <?php echo esc_html( $conflict['instruction'] ); ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div><!-- /llms.txt row -->

		<!-- ── robots.txt ──────────────────────────────────────────── -->
		<div class="aeopugmill-file-row">
			<h3>/robots.txt</h3>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'Every bot category — AI answer crawlers, training crawlers, search indexers, and SEO tools — checks robots.txt before accessing any content. It is not an SEO-only concern; it is the universal access control layer for all automated web traffic.', 'aeo-pugmill' ); ?></p>
			<p style="margin:0 0 12px;">
				<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" class="button button-secondary">
					<?php esc_html_e( 'View robots.txt →', 'aeo-pugmill' ); ?>
				</a>
			</p>

			<div class="aeopugmill-preview-card">
				<div class="aeopugmill-preview-card-header">
					<span style="font-weight:600; font-size:13px; color:#333;"><?php esc_html_e( 'Pugmill AEO Toolkit additions', 'aeo-pugmill' ); ?></span>
				</div>
				<pre class="aeopugmill-col-preview" id="aeopugmill-live-robots"><?php esc_html_e( 'Loading live robots.txt…', 'aeo-pugmill' ); ?></pre>
			</div>

			<?php if ( ! empty( $compat['robots_conflicts'] ) ) : ?>
			<div class="aeopugmill-conflict-block">
				<div class="aeopugmill-conflict-block-title">
					&#9888; <?php esc_html_e( 'Potential duplicate directives in robots.txt', 'aeo-pugmill' ); ?>
				</div>
				<?php foreach ( $compat['robots_conflicts'] as $conflict ) : ?>
				<div class="aeopugmill-conflict-item">
					<strong><?php echo esc_html( $conflict['name'] ); ?>:</strong> <?php echo esc_html( $conflict['instruction'] ); ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div style="margin-top:16px;">
				<p style="<?php echo esc_attr( $p_style ); ?> margin:0 0 6px;"><?php esc_html_e( 'Override WordPress\'s virtual robots.txt with your own content. Pugmill AEO Toolkit appends a Sitemap: directive automatically. Leave blank to use WordPress defaults.', 'aeo-pugmill' ); ?></p>
				<textarea
					name="aeopugmill_robots_txt_custom"
					id="aeopugmill_robots_txt_custom"
					rows="10"
					style="width:100%; font-family:monospace; font-size:12px; box-sizing:border-box;"
					placeholder="User-agent: *&#10;Disallow:&#10;&#10;Sitemap: <?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>"
				><?php echo esc_textarea( get_option( 'aeopugmill_robots_txt_custom', '' ) ); ?></textarea>
				<p class="description" style="margin-top:6px;">
					<?php printf(
						/* translators: 1: URL of robots.txt, 2: display text for robots.txt link */
						wp_kses( __( 'Live robots.txt: <a href="%1$s" target="_blank">%2$s</a>', 'aeo-pugmill' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
						esc_url( home_url( '/robots.txt' ) ),
						esc_html( home_url( '/robots.txt' ) )
					); ?>
				</p>
			</div>
			<label style="display:block; margin-top:12px; font-size:13px;">
				<input type="hidden" name="aeopugmill_disable_robots_append" value="0">
				<input type="checkbox" name="aeopugmill_disable_robots_append" value="1" <?php checked( 1, $disable_robots ); ?>>
				<?php esc_html_e( 'Disable Pugmill AEO Toolkit robots.txt additions', 'aeo-pugmill' ); ?>
			</label>
			<p style="<?php echo esc_attr( $p_style ); ?> margin:4px 0 0 20px; font-size:12px;"><?php esc_html_e( 'Check this if another plugin is already adding Sitemap: directives and you want to avoid duplicates.', 'aeo-pugmill' ); ?></p>
		</div><!-- /robots.txt row -->

		</div><!-- /output files card -->

		<!-- ── CRAWLER ACCESS ────────────────────────────────────── -->
		<?php if ( $compat['robots']['discourage'] || $compat['robots']['blocks_all'] || ! empty( $compat['robots']['blocked_bots'] ) ) : ?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:16px;">
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:0;"><?php esc_html_e( 'Crawler Access', 'aeo-pugmill' ); ?></h2>
		<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'AI answer engines (ChatGPT, Perplexity, Claude) use web crawlers to index content. Unlike older SEO bots, these are worth allowing — they cite and surface your content in AI-generated answers.', 'aeo-pugmill' ); ?></p>

		<?php if ( $compat['robots']['discourage'] ) : ?>
		<div style="background:#fcf0f1; border-left:4px solid #dc3232; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:12px;">
			<strong><?php esc_html_e( '✗ Search engines are discouraged site-wide', 'aeo-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
				<?php esc_html_e( 'WordPress Settings → Reading has "Discourage search engines" enabled. This outputs Disallow: / for all crawlers — including AI answer engines — blocking your content from AEO indexing entirely.', 'aeo-pugmill' ); ?>
			</span><br><br>
			<a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Fix in Reading Settings →', 'aeo-pugmill' ); ?></a>
		</div>
		<?php endif; ?>
		<?php if ( $compat['robots']['blocks_all'] ) : ?>
		<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:12px;">
			<strong><?php esc_html_e( '⚠ robots.txt blocks all crawlers (Disallow: /)', 'aeo-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
				<?php esc_html_e( 'Your robots.txt has a wildcard User-agent: * rule with Disallow: /. This blocks all web crawlers including AI answer engines. Consider replacing it with specific rules that allow GPTBot, ClaudeBot, PerplexityBot, and Google-Extended.', 'aeo-pugmill' ); ?>
			</span>
		</div>
		<?php endif; ?>
		<?php if ( ! empty( $compat['robots']['blocked_bots'] ) ) : ?>
		<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:12px;">
			<strong><?php esc_html_e( '⚠ AI crawlers blocked in robots.txt', 'aeo-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
			<?php printf(
				/* translators: %s: comma-separated list of blocked AI crawler names (e.g. "GPTBot, ClaudeBot") */
				wp_kses( __( 'The following AI crawlers are explicitly blocked: %s. Remove or adjust these Disallow rules to improve AEO discoverability.', 'aeo-pugmill' ), array( 'strong' => array() ) ),
				'<strong>' . esc_html( implode( ', ', $compat['robots']['blocked_bots'] ) ) . '</strong>'
			); ?>
			</span>
		</div>
		<?php endif; ?>
		</div><!-- /crawler access card -->
		<?php endif; ?>

		<?php submit_button( __( 'Save Changes', 'aeo-pugmill' ) ); ?>
		</form>

		<?php ob_start(); ?>
		(function () {
			// Load live robots.txt into the preview panel.
			var el = document.getElementById( 'aeopugmill-live-robots' );
			if ( el ) {
				fetch( <?php echo wp_json_encode( home_url( '/robots.txt' ) ); ?>, { credentials: 'omit', cache: 'no-store' } )
					.then( function ( r ) { return r.text(); } )
					.then( function ( text ) {
						var lines = text.split( '\n' ).slice( 0, 60 );
						if ( text.split( '\n' ).length > 60 ) { lines.push( '… (truncated)' ); }
						el.textContent = lines.join( '\n' );
					} )
					.catch( function () {
						el.textContent = '(Could not load)';
					} );
			}

		}());
		<?php aeopugmill_inline_js( ob_get_clean() ); ?>

		<?php elseif ( 'audit-aeo' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     AUDIT AEO TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php
		$is_ai_mode          = ( 'ai' === $mode );
		$can_generate_fields = $has_api_key || $is_pro_active;
		$audit_nonce         = wp_create_nonce( 'aeopugmill_generate_aeo' );
		$field_nonces        = array(
			'summary'  => wp_create_nonce( 'aeopugmill_generate_summary' ),
			'qa'       => wp_create_nonce( 'aeopugmill_generate_qa' ),
			'entities' => wp_create_nonce( 'aeopugmill_generate_entities' ),
			'keywords' => wp_create_nonce( 'aeopugmill_generate_keywords' ),
		);
		$per_page     = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page = isset( $_GET['audit_page'] ) ? max( 1, (int) $_GET['audit_page'] ) : 1;
		$offset       = ( $current_page - 1 ) * $per_page;

		// ── Sort params ───────────────────────────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) && 'score' === $_GET['orderby'] ? 'score' : 'date';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';

		// ── Post type filter ──────────────────────────────────────────────────────
		global $wpdb;
		$post_types_raw = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types_raw['attachment'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_pt = isset( $_GET['audit_pt'] ) && isset( $post_types_raw[ $_GET['audit_pt'] ] )
			? sanitize_key( $_GET['audit_pt'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$show_pt_filter = count( $post_types_raw ) > 1;

		$active_pt_list = $filter_pt ? array( $filter_pt ) : array_keys( $post_types_raw );
		$pt_in          = implode( "','", array_map( 'esc_sql', $active_pt_list ) );

		// ── ORDER BY clause ───────────────────────────────────────────────────────
		// Default: newest posts first (matches edit.php). Score sort: by stored AEO score.
		if ( 'score' === $orderby ) {
			$order_clause = "COALESCE( ts.meta_value + 0, 0 ) {$order}";
		} else {
			$order_clause = "p.post_date {$order}";
		}

		// ── Base URL for sorting/pagination links ─────────────────────────────────
		$audit_base = admin_url( 'options-general.php?page=aeo-pugmill&tab=audit-aeo' );
		if ( $filter_pt ) $audit_base .= '&audit_pt=' . rawurlencode( $filter_pt );

		$sort_url = function( $col ) use ( $audit_base, $orderby, $order ) {
			$new_order = ( $orderby === $col && 'DESC' === $order ) ? 'asc' : 'desc';
			return $audit_base . '&orderby=' . rawurlencode( $col ) . '&order=' . $new_order;
		};

		$sort_indicator = function( $col ) use ( $orderby, $order ) {
			if ( $orderby !== $col ) return '';
			return ' <span class="sorting-indicator">' . ( 'DESC' === $order ? '▼' : '▲' ) . '</span>';
		};

		// ── Count query ───────────────────────────────────────────────────────────
		// $pt_in is built from get_post_types() names mapped through esc_sql(); safe for interpolation.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_posts = (int) $wpdb->get_var(
			"SELECT COUNT( DISTINCT p.ID )
			 FROM {$wpdb->posts} p
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ('{$pt_in}')"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// ── Main query ────────────────────────────────────────────────────────────
		// Fetches the stored AEO score alongside post data. Scores are written to
		// post meta on every save_post (via aeo-meta.php). Rows with no stored score
		// (posts never saved since the plugin was installed) render a "—" placeholder;
		// the user can click ↻ Recalc or visit the post editor to generate a score.
		// $pt_in uses esc_sql(); $order_clause is built from safelisted 'ASC'/'DESC' only.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$audit_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type,
			        ts.meta_value  AS total_score_raw,
			        aeo.meta_value AS aeo_raw
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} ts  ON ts.post_id  = p.ID AND ts.meta_key  = '_aeopugmill_score'
			 LEFT JOIN {$wpdb->postmeta} aeo ON aeo.post_id = p.ID AND aeo.meta_key = '_aeopugmill_aeo'
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ('{$pt_in}')
			 ORDER BY {$order_clause}
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total_pages = (int) ceil( $total_posts / $per_page );

		?>
		<div style="margin-top:24px;">
			<p style="<?php echo esc_attr( $p_style ); ?>">
				<?php esc_html_e( 'Review every published post through the lens of AEO. Scores are calculated fresh on each page load — the same way the post editor sidebar calculates them — so what you see here always matches what you see when editing a post.', 'aeo-pugmill' ); ?>
			</p>

			<?php if ( ! $is_pro_active ) : ?>
			<div style="background:#faf5ff; border:1px solid #e9d5ff; border-radius:6px; padding:12px 16px; margin-bottom:20px; font-size:13px; color:#6b21a8;">
				✨ <strong><?php esc_html_e( 'Generate AEO is a Pro feature.', 'aeo-pugmill' ); ?></strong>
				<?php printf(
					/* translators: %s: URL to the pricing/upgrade page */
					wp_kses( __( '<a href="%s" target="_blank">Upgrade to Pugmill AEO Toolkit Pro</a> to generate Summary, Q&amp;A, Entities and Keywords for any post without leaving this page.', 'aeo-pugmill' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
					esc_url( 'https://aeopugmill.com/pricing' )
				); ?>
			</div>
			<?php endif; ?>

			<?php if ( $show_pt_filter ) : ?>
			<div style="margin-bottom:12px;">
				<?php
				$all_url = $audit_base . ( 'score' === $orderby ? '&orderby=score&order=' . strtolower( $order ) : '' );
				?>
				<a href="<?php echo esc_url( $all_url ); ?>"
					class="button<?php echo ! $filter_pt ? ' button-primary' : ''; ?>"
					style="margin-right:4px;">
					<?php esc_html_e( 'All', 'aeo-pugmill' ); ?>
				</a>
				<?php foreach ( $post_types_raw as $pt_slug => $_ ) :
					$pt_obj  = get_post_type_object( $pt_slug );
					$pt_label = $pt_obj ? $pt_obj->labels->name : $pt_slug;
					$pt_url   = admin_url( 'options-general.php?page=aeo-pugmill&tab=audit-aeo&audit_pt=' . rawurlencode( $pt_slug ) );
					if ( 'score' === $orderby ) $pt_url .= '&orderby=score&order=' . strtolower( $order );
				?>
				<a href="<?php echo esc_url( $pt_url ); ?>"
					class="button<?php echo $filter_pt === $pt_slug ? ' button-primary' : ''; ?>"
					style="margin-right:4px;">
					<?php echo esc_html( $pt_label ); ?>
				</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if ( empty( $audit_rows ) ) : ?>
				<p style="color:#666;"><?php esc_html_e( 'No published posts found.', 'aeo-pugmill' ); ?></p>
			<?php else : ?>

			<div class="pugmill-audit-wrap">
			<table class="wp-list-table widefat fixed striped pugmill-audit-table" style="margin-top:0;">
				<thead>
					<tr>
						<th style="width:30%;"><?php esc_html_e( 'Title', 'aeo-pugmill' ); ?></th>
						<th style="width:8%;"><?php esc_html_e( 'Type', 'aeo-pugmill' ); ?></th>
						<th style="width:8%; text-align:center;" class="sortable <?php echo 'score' === $orderby ? ( 'ASC' === $order ? 'asc' : 'desc' ) : 'asc'; ?>">
							<a href="<?php echo esc_url( $sort_url( 'score' ) ); ?>" style="display:block; text-align:center;">
								<?php esc_html_e( 'Score', 'aeo-pugmill' ); ?>
								<?php echo $sort_indicator( 'score' ); // phpcs:ignore ?>
							</a>
						</th>
						<th><?php esc_html_e( 'Missing AEO Fields', 'aeo-pugmill' ); ?></th>
						<th style="width:16%; text-align:center;">
							<?php esc_html_e( 'Generate AEO', 'aeo-pugmill' ); ?>
							<?php if ( ! $is_pro_active ) : ?>
								<span style="display:block; font-size:10px; font-weight:400; color:#9ca3af;"><?php esc_html_e( 'Pro', 'aeo-pugmill' ); ?></span>
							<?php endif; ?>
						</th>
					</tr>
				</thead>
				<tbody>
				<?php
				// Derive badge colour from stored score — mirrors health.php thresholds exactly.
				$score_color = function( $n ) {
					if ( $n >= 90 ) return '#46b450';
					if ( $n >= 70 ) return '#00a0d2';
					if ( $n >= 40 ) return '#ffb900';
					return '#dc3232';
				};
				$row_index = 0;
				foreach ( $audit_rows as $row ) :
					$is_first_row = ( 0 === $row_index );
					$row_index++;
					$edit_url = get_edit_post_link( $row->ID );
					$s_raw    = $row->total_score_raw;
					$s_val    = ( null !== $s_raw && '' !== $s_raw ) ? (int) $s_raw : null;
					$s_color  = null !== $s_val ? $score_color( $s_val ) : null;

					// Derive missing AEO fields from stored meta — no extra query needed.
					$aeo_defaults = array( 'summary' => '', 'questions' => array(), 'entities' => array(), 'keywords' => array() );
					$aeo_decoded  = ! empty( $row->aeo_raw ) ? json_decode( $row->aeo_raw, true ) : null;
					$aeo_data     = ( is_array( $aeo_decoded ) ) ? wp_parse_args( $aeo_decoded, $aeo_defaults ) : $aeo_defaults;

					$missing_fields = array();
					if ( empty( trim( $aeo_data['summary'] ) ) ) {
						$missing_fields[] = 'Summary';
					}
					$qa_count = count( array_filter( $aeo_data['questions'], function( $q ) { return ! empty( $q['q'] ) && ! empty( $q['a'] ); } ) );
					if ( $qa_count < 1 ) {
						$missing_fields[] = 'Q&amp;A';
					}
					$entity_count = count( array_filter( $aeo_data['entities'], function( $e ) { return ! empty( $e['name'] ); } ) );
					if ( $entity_count < 1 ) {
						$missing_fields[] = 'Entities';
					}
					$kw_list = array_values( array_filter( $aeo_data['keywords'], 'strlen' ) );
					if ( count( $kw_list ) < 5 ) {
						$missing_fields[] = 'Keywords';
					}
				?>
				<tr data-post-id="<?php echo absint( $row->ID ); ?>">
					<td>
						<a href="<?php echo esc_url( $edit_url ); ?>" style="font-weight:600;">
							<?php echo esc_html( $row->post_title ?: __( '(no title)', 'aeo-pugmill' ) ); ?>
						</a>
					</td>
					<td style="color:#666; font-size:12px;"><?php echo esc_html( $row->post_type ); ?></td>
					<td style="text-align:center;" class="pugmill-score-cell">
						<?php if ( null !== $s_val ) : ?>
							<span class="pugmill-score-pill" style="display:inline-block;padding:2px 10px;border-radius:999px;background:<?php echo esc_attr( $s_color ); ?>1a;color:<?php echo esc_attr( $s_color ); ?>;font-size:12px;font-weight:700;"><?php echo absint( $s_val ); ?></span>
						<?php else : ?>
							<span class="pugmill-score-null" style="color:#9ca3af;font-size:12px;" title="<?php esc_attr_e( 'Edit this post or click the refresh icon to generate a score', 'aeo-pugmill' ); ?>">—</span>
						<?php endif; ?>
						<button type="button"
							class="button-link aeopugmill-audit-recalc"
							data-post-id="<?php echo absint( $row->ID ); ?>"
							title="<?php esc_attr_e( 'Recalculate score', 'aeo-pugmill' ); ?>"
							style="margin-left:4px;color:#9ca3af;background:none;border:none;padding:0;cursor:pointer;vertical-align:middle;line-height:1;">
							<span class="dashicons dashicons-update-alt" style="font-size:14px;width:14px;height:14px;margin-top:-1px;"></span>
						</button>
					</td>
					<td class="pugmill-missing-cell">
						<?php if ( empty( $missing_fields ) ) : ?>
							<span style="color:#46b450;font-size:12px;">&#10003; <?php esc_html_e( 'All AEO fields complete', 'aeo-pugmill' ); ?></span>
						<?php elseif ( $can_generate_fields && ! empty( $api_key ) ) : ?>
							<?php foreach ( $missing_fields as $mf ) : ?>
								<button type="button" class="pugmill-field-gen" data-field="<?php echo esc_attr( $mf ); ?>"
									style="display:inline-block;margin:2px 3px 2px 0;padding:1px 8px;border-radius:4px;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:600;border:none;cursor:pointer;"
									title="<?php esc_attr_e( 'Click to generate', 'aeo-pugmill' ); ?>">
									<?php echo esc_html( $mf ); ?>
								</button>
							<?php endforeach; ?>
						<?php elseif ( $can_generate_fields ) : ?>
							<?php foreach ( $missing_fields as $mf ) : ?>
								<button type="button" disabled
									style="display:inline-flex;align-items:center;gap:4px;margin:2px 3px 2px 0;padding:1px 8px;border-radius:4px;background:#e5e7eb;color:#9ca3af;font-size:11px;font-weight:600;border:none;cursor:not-allowed;"
									title="<?php esc_attr_e( 'Add an AI API key in Settings to generate this field', 'aeo-pugmill' ); ?>">
									<?php echo esc_html( $mf ); ?>
									<span style="font-size:9px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;background:#f3e8ff;color:#7c3aed;padding:1px 5px;border-radius:3px;line-height:1.4;">AI</span>
								</button>
							<?php endforeach; ?>
						<?php else : ?>
							<?php foreach ( $missing_fields as $mf ) : ?>
								<span style="display:inline-block;margin:2px 3px 2px 0;padding:1px 8px;border-radius:4px;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:600;"><?php echo esc_html( $mf ); ?></span>
							<?php endforeach; ?>
						<?php endif; ?>
					</td>
					<td style="text-align:center;">
						<?php if ( $is_pro_active ) : ?>
						<button type="button"
							class="button aeopugmill-audit-generate"
							data-post-id="<?php echo absint( $row->ID ); ?>"
							data-nonce="<?php echo esc_attr( $audit_nonce ); ?>"
							style="font-size:12px;">
							✨ <?php esc_html_e( 'Generate AEO', 'aeo-pugmill' ); ?>
						</button>
						<span class="aeopugmill-audit-status" style="display:block; font-size:11px; margin-top:4px; color:#666;"></span>
						<?php else : ?>
						<span style="font-size:11px; color:#999;"><?php esc_html_e( 'Pro Feature', 'aeo-pugmill' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div><!-- /.pugmill-audit-wrap -->

			<?php if ( $total_pages > 1 ) :
				// Preserve sort and filter state across pagination.
				$page_base  = $audit_base;
				if ( 'score' === $orderby ) $page_base .= '&orderby=score&order=' . strtolower( $order );
				$page_base .= '&audit_page=%#%';
				echo '<div style="margin-top:16px;">';
				echo wp_kses_post( paginate_links( array(
					'base'      => $page_base,
					'format'    => '',
					'current'   => $current_page,
					'total'     => $total_pages,
					'prev_text' => '&laquo; ' . __( 'Previous', 'aeo-pugmill' ),
					'next_text' => __( 'Next', 'aeo-pugmill' ) . ' &raquo;',
				) ) );
				echo '</div>';
			endif; ?>

			<?php endif; ?>
		</div>

		<?php ob_start(); ?>
		( function() {
			var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var scoreNonce   = <?php echo wp_json_encode( wp_create_nonce( 'aeopugmill_calculate_scores' ) ); ?>;
			<?php if ( $is_pro_active ) : ?>
			var generateNonce = <?php echo wp_json_encode( $audit_nonce ); ?>;
			<?php endif; ?>
			<?php if ( $can_generate_fields ) : ?>
			var canGenerateFields = true;
			var fieldNonces = <?php echo wp_json_encode( $field_nonces ); ?>;
			// Map label → { action, nonce key }
			var fieldActionMap = {
				'Summary'  : { action: 'aeopugmill_generate_summary',  nonce: fieldNonces.summary  },
				'Q&A'      : { action: 'aeopugmill_generate_qa',       nonce: fieldNonces.qa       },
				'Entities' : { action: 'aeopugmill_generate_entities', nonce: fieldNonces.entities },
				'Keywords' : { action: 'aeopugmill_generate_keywords', nonce: fieldNonces.keywords },
			};
			<?php else : ?>
			var canGenerateFields = false;
			<?php endif; ?>
			var hasApiKey = <?php echo wp_json_encode( ! empty( $api_key ) ); ?>;

			// ── Shared: update a row with calculated score data ─────────────────────
			function applyScoreToRow( row, data ) {
				var scoreCell   = row.querySelector( '.pugmill-score-cell' );
				var missingCell = row.querySelector( '.pugmill-missing-cell' );
				var color       = data.color;

				// Score pill + recalc icon
				if ( scoreCell ) {
					var postId = row.dataset.postId;
					scoreCell.innerHTML =
						'<span class="pugmill-score-pill" style="display:inline-block;padding:2px 10px;border-radius:999px;background:' + color + '1a;color:' + color + ';font-size:12px;font-weight:700;">' + data.score + '</span>' +
						'<button type="button" class="button-link aeopugmill-audit-recalc" data-post-id="' + postId + '" title="<?php echo esc_js( __( 'Recalculate score', 'aeo-pugmill' ) ); ?>" style="margin-left:4px;color:#9ca3af;background:none;border:none;padding:0;cursor:pointer;vertical-align:middle;line-height:1;"><span class="dashicons dashicons-update-alt" style="font-size:14px;width:14px;height:14px;margin-top:-1px;"></span></button>';
				}

				// Missing field tags
				if ( missingCell ) {
					if ( ! data.missing || data.missing.length === 0 ) {
						missingCell.innerHTML = '<span style="color:#46b450;font-size:12px;">✓ <?php echo esc_js( __( 'All AEO fields complete', 'aeo-pugmill' ) ); ?></span>';
					} else if ( canGenerateFields && hasApiKey ) {
						// Clickable generate buttons for each missing field
						missingCell.innerHTML = data.missing.map( function( f ) {
							return '<button type="button" class="pugmill-field-gen" data-field="' + f + '" style="display:inline-block;margin:2px 3px 2px 0;padding:1px 8px;border-radius:4px;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:600;border:none;cursor:pointer;" title="<?php echo esc_js( __( 'Click to generate', 'aeo-pugmill' ) ); ?>">' + f + '</button>';
						} ).join( '' );
						attachFieldGenListeners( row );
					} else if ( canGenerateFields && ! hasApiKey ) {
						// Disabled — no API key configured
						missingCell.innerHTML = data.missing.map( function( f ) {
							return '<button type="button" disabled style="display:inline-flex;align-items:center;gap:4px;margin:2px 3px 2px 0;padding:1px 8px;border-radius:4px;background:#e5e7eb;color:#9ca3af;font-size:11px;font-weight:600;border:none;cursor:not-allowed;" title="<?php echo esc_js( __( 'Add an AI API key in Settings to generate this field', 'aeo-pugmill' ) ); ?>">' + f + '<span style="font-size:9px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;background:#f3e8ff;color:#7c3aed;padding:1px 5px;border-radius:3px;line-height:1.4;">AI</span></button>';
						} ).join( '' );
					} else {
						missingCell.innerHTML = data.missing.map( function( f ) {
							return '<span style="display:inline-block;margin:2px 3px 2px 0;padding:1px 8px;border-radius:4px;background:#fee2e2;color:#b91c1c;font-size:11px;font-weight:600;">' + f + '</span>';
						} ).join( '' );
					}
				}

				row.removeAttribute( 'data-unscored' );
			}

			// ── Per-field generate (free + Pro users) ───────────────────────────────
			function attachFieldGenListeners( row ) {
				if ( ! canGenerateFields ) { return; }
				var postId = row.dataset.postId;
				row.querySelectorAll( '.pugmill-field-gen' ).forEach( function( btn ) {
					btn.addEventListener( 'click', function() {
						var field  = btn.dataset.field;
						var map    = fieldActionMap[ field ];
						if ( ! map ) { return; }

						btn.disabled    = true;
						btn.textContent = '…';
						btn.style.background = '#fef9c3';
						btn.style.color      = '#854d0e';

						fetch( ajaxUrl, {
							method:  'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body:    new URLSearchParams( { action: map.action, nonce: map.nonce, post_id: postId, autosave: '1' } ),
						} )
						.then( function( r ) { return r.json(); } )
						.then( function( res ) {
							if ( res.success ) {
								btn.textContent      = '✓ ' + field;
								btn.style.background = '#dcfce7';
								btn.style.color      = '#15803d';
								btn.disabled         = true;
								btn.style.cursor     = 'default';
							} else {
								var msg = ( res.data && res.data.message ) ? res.data.message : '<?php echo esc_js( __( 'Failed', 'aeo-pugmill' ) ); ?>';
								btn.textContent      = '✗ ' + field;
								btn.style.background = '#fee2e2';
								btn.style.color      = '#b91c1c';
								btn.title            = msg;
								btn.disabled         = false;
								btn.style.cursor     = 'pointer';
							}
						} )
						.catch( function() {
							btn.textContent      = '✗ ' + field;
							btn.style.background = '#fee2e2';
							btn.style.color      = '#b91c1c';
							btn.disabled         = false;
							btn.style.cursor     = 'pointer';
						} );
					} );
				} );
			}

			// ── Per-row ↻ Recalc icon ──────────────────────────────────────────────
			// Uses event delegation so icons re-rendered by applyScoreToRow still work.
			document.addEventListener( 'click', function( e ) {
				var btn = e.target.closest( '.aeopugmill-audit-recalc' );
				if ( ! btn ) { return; }

				var postId = btn.dataset.postId;
				var row    = btn.closest( 'tr' );
				if ( ! postId || ! row ) { return; }

				btn.disabled = true;
				btn.style.opacity = '0.4';

				var body = new URLSearchParams( { action: 'aeopugmill_calculate_scores', nonce: scoreNonce } );
				body.append( 'post_ids[]', postId );

				fetch( ajaxUrl, {
					method:  'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:    body.toString(),
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( res ) {
					if ( res.success && res.data[ postId ] ) {
						applyScoreToRow( row, res.data[ postId ] );
						// btn is replaced by applyScoreToRow — no need to restore
					} else {
						btn.disabled = false;
						btn.style.opacity = '';
					}
				} )
				.catch( function() {
					btn.disabled = false;
					btn.style.opacity = '';
				} );
			} );

			<?php if ( $is_pro_active ) : ?>
			// ── 3. Per-row Generate All ─────────────────────────────────────────────
			document.querySelectorAll( '.aeopugmill-audit-generate' ).forEach( function( btn ) {
				btn.addEventListener( 'click', function() {
					var postId = btn.dataset.postId;
					var row    = btn.closest( 'tr' );
					var status = row.querySelector( '.aeopugmill-audit-status' );

					btn.disabled       = true;
					btn.textContent    = '<?php echo esc_js( __( 'Generating…', 'aeo-pugmill' ) ); ?>';
					status.textContent = '';

					fetch( ajaxUrl, {
						method:  'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body:    new URLSearchParams( { action: 'aeopugmill_generate_aeo', nonce: generateNonce, post_id: postId, autosave: '1' } ),
					} )
					.then( function( r ) { return r.json(); } )
					.then( function( res ) {
						if ( res.success ) {
							status.textContent = '✓ <?php echo esc_js( __( 'Generated', 'aeo-pugmill' ) ); ?>';
							status.style.color = '#46b450';
							// Refresh score pill and missing-fields breakdown for this row.
							var recalcBody = new URLSearchParams( { action: 'aeopugmill_calculate_scores', nonce: scoreNonce } );
							recalcBody.append( 'post_ids[]', postId );
							fetch( ajaxUrl, {
								method:  'POST',
								headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
								body:    recalcBody.toString(),
							} )
							.then( function( r ) { return r.json(); } )
							.then( function( res2 ) {
								if ( res2.success && res2.data[ postId ] ) {
									applyScoreToRow( row, res2.data[ postId ] );
								}
							} );
						} else {
							status.textContent = ( res.data && res.data.message ) ? res.data.message : '<?php echo esc_js( __( 'Generation failed.', 'aeo-pugmill' ) ); ?>';
							status.style.color = '#dc3232';
						}
						btn.disabled    = false;
						btn.textContent = '✨ <?php echo esc_js( __( 'Generate AEO', 'aeo-pugmill' ) ); ?>';
					} )
					.catch( function() {
						status.textContent = '<?php echo esc_js( __( 'Network error — please try again.', 'aeo-pugmill' ) ); ?>';
						status.style.color = '#dc3232';
						btn.disabled    = false;
						btn.textContent = '✨ <?php echo esc_js( __( 'Generate AEO', 'aeo-pugmill' ) ); ?>';
					} );
				} );
			} );
			<?php endif; ?>

			// ── Wire up field-gen buttons rendered server-side on page load ────────
			if ( canGenerateFields && hasApiKey ) {
				document.querySelectorAll( 'tr[data-post-id]' ).forEach( function( row ) {
					attachFieldGenListeners( row );
				} );
			}

		}() );
		<?php aeopugmill_inline_js( ob_get_clean() ); ?>

		<?php elseif ( 'bulk-aeo' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     BULK AEO TAB
		     ════════════════════════════════════════════════════════════ -->
		<div style="margin-top:24px;">

		<?php if ( ! $is_pro_active ) : ?>

			<div style="background:#faf5ff; border:1px solid #e9d5ff; border-radius:8px; padding:28px 32px; max-width:560px;">
				<p style="font-size:15px; font-weight:700; color:#6b21a8; margin:0 0 10px;">
					<?php esc_html_e( 'Bulk AEO', 'aeo-pugmill' ); ?>
				</p>
				<p style="font-size:13px; color:#374151; margin:0 0 8px; line-height:1.6;">
					<?php esc_html_e( 'Generate Summary, Q&A Pairs, Entities, and Keywords for every published post and page in one run. Posts that already have AEO data are skipped automatically.', 'aeo-pugmill' ); ?>
				</p>
				<p style="font-size:13px; color:#374151; margin:0 0 20px; line-height:1.6;">
					<?php esc_html_e( 'Available with Pugmill AEO Toolkit Pro.', 'aeo-pugmill' ); ?>
				</p>
				<a href="https://aeopugmill.com/pricing" target="_blank" rel="noopener noreferrer"
					style="display:inline-block; background:#7c3aed; border:1px solid #7c3aed; color:#fff; border-radius:4px; padding:0 20px; height:34px; line-height:34px; font-size:13px; font-weight:600; text-decoration:none;">
					<?php esc_html_e( 'Get Pugmill AEO Toolkit Pro', 'aeo-pugmill' ); ?>
				</a>
			</div>

		<?php else : ?>

			<p style="<?php echo esc_attr( $p_style ); ?>">
				<?php esc_html_e( 'Generating a summary, Q&A pairs, entities, and keywords for every published post and page. These four fields are bot-facing — they appear in JSON-LD schema and /llms.txt, not on the page itself — which is why bulk generation is safe. Human-facing elements (SEO titles, meta descriptions, excerpts) are available only from the individual post editor.', 'aeo-pugmill' ); ?>
			</p>
			<p style="<?php echo esc_attr( $p_style ); ?>">
				<?php esc_html_e( 'Posts are processed one at a time with a pause between requests to stay within server and API rate limits. Posts that already have AEO data are skipped automatically. For large sites, running in multiple sessions works — pick up where you left off.', 'aeo-pugmill' ); ?>
			</p>
			<p style="<?php echo esc_attr( $p_style ); ?>">
				<strong><?php esc_html_e( 'Check your AI spend first.', 'aeo-pugmill' ); ?></strong>
				<?php esc_html_e( 'Generating AEO for hundreds of posts adds up. Review your provider\'s usage dashboard before running on a large site.', 'aeo-pugmill' ); ?>
			</p>

			<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:4px;">

				<!-- Options — row 1: Content + Skip -->
				<div style="display:flex; gap:32px; align-items:flex-start; flex-wrap:wrap; margin-bottom:16px;">
					<fieldset style="border:none; margin:0; padding:0;">
						<legend style="font-size:12px; font-weight:600; color:#374151; margin-bottom:8px;"><?php esc_html_e( 'Content', 'aeo-pugmill' ); ?></legend>
						<label style="display:block; font-size:13px; color:#374151; margin-bottom:4px; cursor:pointer;">
							<input type="radio" name="aeopugmill_bulk_post_types" value="all" checked style="margin-right:5px;">
							<?php esc_html_e( 'Posts + Pages', 'aeo-pugmill' ); ?>
						</label>
						<label style="display:block; font-size:13px; color:#374151; margin-bottom:4px; cursor:pointer;">
							<input type="radio" name="aeopugmill_bulk_post_types" value="post" style="margin-right:5px;">
							<?php esc_html_e( 'Posts only', 'aeo-pugmill' ); ?>
						</label>
						<label style="display:block; font-size:13px; color:#374151; cursor:pointer;">
							<input type="radio" name="aeopugmill_bulk_post_types" value="page" style="margin-right:5px;">
							<?php esc_html_e( 'Pages only', 'aeo-pugmill' ); ?>
						</label>
					</fieldset>
					<div>
						<p style="font-size:12px; font-weight:600; color:#374151; margin:0 0 8px;"><?php esc_html_e( 'Options', 'aeo-pugmill' ); ?></p>
						<label style="display:block; font-size:13px; color:#374151; cursor:pointer;">
							<input type="checkbox" id="aeopugmill-bulk-skip-existing" checked style="margin-right:5px;">
							<?php esc_html_e( 'Skip posts that already have AEO data', 'aeo-pugmill' ); ?>
						</label>
					</div>
				</div>

				<!-- Options — row 2: Priority + Request Delay + Batch Size -->
				<div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap; margin-bottom:16px; padding-top:12px; border-top:1px solid #f0f0f0;">
					<fieldset style="border:none; margin:0; padding:0;">
						<legend style="font-size:12px; font-weight:600; color:#374151; margin-bottom:6px;"><?php esc_html_e( 'Priority', 'aeo-pugmill' ); ?></legend>
						<select id="aeopugmill-bulk-sort" style="font-size:13px; height:28px;">
							<option value="newest"   ><?php esc_html_e( 'Newest first',         'aeo-pugmill' ); ?></option>
							<option value="commented"><?php esc_html_e( 'Most commented first', 'aeo-pugmill' ); ?></option>
							<option value="oldest"   ><?php esc_html_e( 'Oldest first',         'aeo-pugmill' ); ?></option>
						</select>
					</fieldset>
					<fieldset style="border:none; margin:0; padding:0;">
						<legend style="font-size:12px; font-weight:600; color:#374151; margin-bottom:6px;"><?php esc_html_e( 'Request Delay', 'aeo-pugmill' ); ?></legend>
						<select id="aeopugmill-bulk-speed" style="font-size:13px; height:28px;">
							<option value="1500"><?php esc_html_e( 'Fast (1.5s)',   'aeo-pugmill' ); ?></option>
							<option value="3000" selected><?php esc_html_e( 'Normal (3s)', 'aeo-pugmill' ); ?></option>
							<option value="6000"><?php esc_html_e( 'Careful (6s)', 'aeo-pugmill' ); ?></option>
						</select>
					</fieldset>
					<fieldset style="border:none; margin:0; padding:0;">
						<legend style="font-size:12px; font-weight:600; color:#374151; margin-bottom:6px;"><?php esc_html_e( 'Batch Size', 'aeo-pugmill' ); ?></legend>
						<select id="aeopugmill-bulk-batch" style="font-size:13px; height:28px;">
							<option value="50"><?php esc_html_e( '50 posts',  'aeo-pugmill' ); ?></option>
							<option value="100" selected><?php esc_html_e( '100 posts', 'aeo-pugmill' ); ?></option>
							<option value="250"><?php esc_html_e( '250 posts', 'aeo-pugmill' ); ?></option>
							<option value="500"><?php esc_html_e( '500 posts', 'aeo-pugmill' ); ?></option>
							<option value="0"><?php esc_html_e( 'All',        'aeo-pugmill' ); ?></option>
						</select>
					</fieldset>
					<p style="font-size:11px; color:#9ca3af; margin:0; align-self:flex-end; padding-bottom:4px;"><?php esc_html_e( 'Run again after each batch to continue.', 'aeo-pugmill' ); ?></p>
				</div>

				<!-- Stats -->
				<p id="aeopugmill-bulk-stats" style="font-size:12px; color:#9ca3af; margin:0 0 16px;">Loading…</p>

				<!-- Start button -->
				<button
					id="aeopugmill-bulk-start"
					class="button"
					style="background:#7c3aed; border-color:#7c3aed; color:#fff; border-radius:4px; padding:0 18px; height:32px; line-height:30px; font-size:13px;"
				>
					<?php esc_html_e( 'Generate AEO for All Content', 'aeo-pugmill' ); ?>
				</button>

				<!-- Progress (hidden until running) -->
				<div id="aeopugmill-bulk-progress" style="display:none; margin-top:20px;">
					<div style="background:#e5e7eb; border-radius:3px; height:6px; overflow:hidden; margin-bottom:10px;">
						<div id="aeopugmill-bulk-bar-fill" style="height:100%; background:#7c3aed; border-radius:3px; width:0%; transition:width 0.3s ease;"></div>
					</div>
					<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
						<div style="display:flex; gap:16px; align-items:center;">
							<span id="aeopugmill-bulk-counter" style="font-size:12px; color:#6b7280;"></span>
							<span id="aeopugmill-bulk-rate"    style="font-size:12px; color:#9ca3af;"></span>
						</div>
						<div style="display:flex; gap:8px;">
							<button id="aeopugmill-bulk-pause"  class="button button-secondary" style="font-size:11px; padding:0 10px; height:26px; line-height:24px;"><?php esc_html_e( 'Pause', 'aeo-pugmill' ); ?></button>
							<button id="aeopugmill-bulk-cancel" class="button"                  style="font-size:11px; padding:0 10px; height:26px; line-height:24px; color:#dc2626; border-color:#fca5a5;"><?php esc_html_e( 'Cancel', 'aeo-pugmill' ); ?></button>
						</div>
					</div>
					<p id="aeopugmill-bulk-current" style="font-size:12px; color:#6b7280; margin:0 0 6px; min-height:18px;"></p>
					<p style="font-size:12px; color:#6b7280; margin:0 0 6px;">
						<span style="color:#46b450;">&#10003;</span> <span id="aeopugmill-bulk-success">0</span> generated &nbsp;
						<span style="color:#dc3232;">&#10007;</span> <span id="aeopugmill-bulk-failed">0</span> failed &nbsp;
						<span style="color:#9ca3af;">&#8618;</span> <span id="aeopugmill-bulk-skipped">0</span> skipped
					</p>
					<p style="font-size:11px; color:#9ca3af; margin:0;"><?php esc_html_e( 'Keep this page open while the run is in progress — navigating away will stop processing.', 'aeo-pugmill' ); ?></p>
				</div>

				<!-- Completion message -->
				<p id="aeopugmill-bulk-complete" style="display:none; margin-top:14px; font-size:13px; color:#374151;"></p>

			</div><!-- /card -->

		<?php endif; ?>

		</div>

		<?php endif; // end tab switch ?>

		<!-- ── Shared footer ───────────────────────────────────────── -->
		<hr>
		<p style="color:#999; font-size:12px;">
			<?php
			printf(
				/* translators: 1: Pugmill link 2: Docs link */
				wp_kses( __( 'Pugmill AEO Toolkit by %1$s &mdash; %2$s', 'aeo-pugmill' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
				'<a href="' . esc_url( 'https://aeopugmill.com' ) . '" target="_blank">Pugmill</a>',
				'<a href="' . esc_url( 'https://aeopugmill.com/docs' ) . '" target="_blank">' . esc_html__( 'Documentation', 'aeo-pugmill' ) . '</a>'
			);
			?>
		</p>
	</div><!-- .wrap -->
	<?php
}
