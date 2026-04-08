<?php
/**
 * Admin settings page — WP Pugmill settings under Settings menu.
 *
 * Contains both the Settings tab and the Bot Analytics tab.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
function wppugmill_get_compatibility_data() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$data = array(
		'json_ld_conflicts'  => array(),
		'llms_txt_conflicts' => array(),
		'sitemap_conflicts'  => array(), // array of [ 'name' => string, 'instruction' => string ]
		'robots_conflicts'   => array(), // array of [ 'name' => string, 'instruction' => string ]
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
	$llms_plugins = array(
		'llms-txt/llms-txt.php'               => array(
			'name'        => 'LLMs.txt',
			'instruction' => __( 'Deactivate the LLMs.txt plugin so WP Pugmill can serve /llms.txt with your full AEO metadata.', 'wp-pugmill' ),
		),
		'llmstxt/llmstxt.php'                 => array(
			'name'        => 'LLMs.txt',
			'instruction' => __( 'Deactivate the LLMs.txt plugin so WP Pugmill can serve /llms.txt with your full AEO metadata.', 'wp-pugmill' ),
		),
		'ai-llms-txt/ai-llms-txt.php'         => array(
			'name'        => 'AI LLMs.txt',
			'instruction' => __( 'Deactivate the AI LLMs.txt plugin so WP Pugmill can serve /llms.txt with your full AEO metadata.', 'wp-pugmill' ),
		),
		'llms-txt-for-wp/llms-txt-for-wp.php' => array(
			'name'        => 'LLMs.txt for WP',
			'instruction' => __( 'Deactivate the LLMs.txt for WP plugin so WP Pugmill can serve /llms.txt with your full AEO metadata.', 'wp-pugmill' ),
		),
	);
	foreach ( $llms_plugins as $slug => $info ) {
		if ( is_plugin_active( $slug ) ) {
			$data['llms_txt_conflicts'][] = $info;
		}
	}

	// ── Sitemap conflicts ─────────────────────────────────────────────────
	// Note: WordPress core's built-in sitemap (since 5.5) serves /wp-sitemap.xml —
	// a different URL from WP Pugmill's /sitemap.xml, so it is not a conflict.

	$sitemap_plugins = array(
		'jetpack/jetpack.php'                         => array(
			'name'        => 'Jetpack',
			'instruction' => __( 'Jetpack includes a sitemap module. Disable it in Jetpack → Settings → Traffic by turning off the Sitemap option.', 'wp-pugmill' ),
			'module_check' => function() {
				// Only flag Jetpack if its sitemap module is actually active.
				return class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' )
					? Jetpack::is_module_active( 'sitemaps' )
					: class_exists( 'Jetpack_Sitemap_Manager' );
			},
		),
		'wordpress-seo/wp-seo.php'                    => array(
			'name'        => 'Yoast SEO',
			'instruction' => __( 'Turn off XML Sitemaps in Yoast SEO → Features so WP Pugmill can serve /sitemap.xml.', 'wp-pugmill' ),
		),
		'seo-by-rank-math/rank-math.php'              => array(
			'name'        => 'Rank Math SEO',
			'instruction' => __( 'Disable the Sitemap module in Rank Math → Dashboard → Modules so WP Pugmill can serve /sitemap.xml.', 'wp-pugmill' ),
		),
		'all-in-one-seo-pack/all_in_one_seo_pack.php' => array(
			'name'        => 'All in One SEO',
			'instruction' => __( 'Turn off XML Sitemap in All in One SEO → Sitemaps so WP Pugmill can serve /sitemap.xml.', 'wp-pugmill' ),
		),
		'google-sitemap-generator/sitemap.php'        => array(
			'name'        => 'Google XML Sitemaps',
			'instruction' => __( 'Deactivate the Google XML Sitemaps plugin so WP Pugmill can serve /sitemap.xml.', 'wp-pugmill' ),
		),
		'xml-sitemap-feed/xml-sitemap.php'            => array(
			'name'        => 'XML Sitemap & Google News',
			'instruction' => __( 'Deactivate the XML Sitemap & Google News plugin so WP Pugmill can serve /sitemap.xml.', 'wp-pugmill' ),
		),
		'wp-sitemap-page/wp-sitemap-page.php'         => array(
			'name'        => 'WP Sitemap Page',
			'instruction' => __( 'Deactivate the WP Sitemap Page plugin so WP Pugmill can serve /sitemap.xml.', 'wp-pugmill' ),
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
	// resulting in duplicates when WP Pugmill additions are also enabled.
	$robots_plugins = array(
		'jetpack/jetpack.php'                         => array(
			'name'        => 'Jetpack',
			'instruction' => __( 'Jetpack adds its own Sitemap: directive to robots.txt. If WP Pugmill additions are also enabled you will have duplicate Sitemap: entries. Disable the Jetpack sitemap module (Jetpack → Settings → Traffic) to resolve this.', 'wp-pugmill' ),
		),
		'wordpress-seo/wp-seo.php'                    => array(
			'name'        => 'Yoast SEO',
			'instruction' => __( 'Yoast SEO adds its own Sitemap: directive to robots.txt. Disable WP Pugmill\'s robots.txt additions below to avoid duplicates, or turn off Yoast\'s sitemap feature.', 'wp-pugmill' ),
		),
		'seo-by-rank-math/rank-math.php'              => array(
			'name'        => 'Rank Math SEO',
			'instruction' => __( 'Rank Math adds its own Sitemap: directive to robots.txt. Disable WP Pugmill\'s robots.txt additions below to avoid duplicates, or turn off Rank Math\'s sitemap module.', 'wp-pugmill' ),
		),
		'all-in-one-seo-pack/all_in_one_seo_pack.php' => array(
			'name'        => 'All in One SEO',
			'instruction' => __( 'All in One SEO adds its own directives to robots.txt. Disable WP Pugmill\'s robots.txt additions below to avoid duplicates.', 'wp-pugmill' ),
		),
	);
	foreach ( $robots_plugins as $slug => $info ) {
		if ( is_plugin_active( $slug ) ) {
			$data['robots_conflicts'][] = array(
				'name'        => $info['name'],
				'instruction' => $info['instruction'],
			);
		}
	}

	// ── robots.txt analysis ───────────────────────────────────────────────
	$data['robots']['discourage'] = ( '0' === get_option( 'blog_public' ) );

	$ai_bots     = array( 'GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'anthropic-ai', 'cohere-ai', 'ChatGPT-User', 'OAI-SearchBot' );
	$robots_file = ABSPATH . 'robots.txt';

	if ( file_exists( $robots_file ) ) {
		$data['robots']['has_file'] = true;
		$content = file_get_contents( $robots_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

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

	return $data;
}

/**
 * Generate a short preview of what WP Pugmill's sitemap.xml output looks like.
 * Returns the XML string (first 4 URL entries) for display in the settings UI.
 *
 * @return string
 */
function wppugmill_preview_sitemap_xml() {
	if ( ! function_exists( 'wppugmill_sitemap_collect_urls' ) || ! function_exists( 'wppugmill_own_noindex' ) ) {
		return '<!-- Sitemap generator not available -->';
	}
	$urls   = array_slice( wppugmill_sitemap_collect_urls(), 0, 4 );
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
function wppugmill_preview_llms_txt_snippet() {
	// Try the cached full version — pull first 20 lines.
	$cached = get_transient( 'wppugmill_llms_txt' );
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
 * Return the lines that WP Pugmill would append to robots.txt (Sitemap + LLMs-Txt directives).
 *
 * @return string
 */
function wppugmill_preview_robots_additions() {
	$lines   = array();
	$lines[] = "\nSitemap: " . home_url( '/sitemap.xml' );
	$lines[] = '';
	$lines[] = '# AI content index';
	$lines[] = 'LLMs-Txt: ' . home_url( '/llms.txt' );
	return implode( "\n", $lines );
}

function wppugmill_add_settings_page() {
	add_options_page(
		__( 'WP Pugmill Settings', 'wp-pugmill' ),
		__( 'WP Pugmill', 'wp-pugmill' ),
		'manage_options',
		'wp-pugmill',
		'wppugmill_render_settings_page'
	);
}
add_action( 'admin_menu', 'wppugmill_add_settings_page' );

/**
 * Enqueue shared plugin CSS on the WP Pugmill settings page so the
 * barber pole loading animation (.wppugmill-loading) is available.
 *
 * @param string $hook Current admin page hook suffix.
 */
function wppugmill_enqueue_settings_assets( $hook ) {
	if ( 'settings_page_wp-pugmill' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'wppugmill-editor-resize',
		WPPUGMILL_PLUGIN_URL . 'admin/css/editor-resize.css',
		array(),
		WPPUGMILL_VERSION
	);
	wp_enqueue_script(
		'wppugmill-bulk-aeo',
		WPPUGMILL_PLUGIN_URL . 'admin/js/bulk-aeo.js',
		array(),
		WPPUGMILL_VERSION,
		true
	);
	wp_localize_script(
		'wppugmill-bulk-aeo',
		'wppugmillBulk',
		array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'wppugmill_bulk_aeo' ),
			'isProMode'  => ( 'ai' === wppugmill_mode() ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'wppugmill_enqueue_settings_assets' );

function wppugmill_render_settings_page() {
	$mode           = wppugmill_mode();
	$license_status = wppugmill_license_status();
	$license_key    = wppugmill_get_encrypted_option( 'wppugmill_license_key', '' );
	$api_key        = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	// Detect active tab — default is 'license'
	$allowed_tabs = array( 'license', 'ai-provider', 'site-aeo', 'author-voice', 'compatibility', 'sitemap', 'analytics', 'bulk-aeo' );
	$active_tab   = isset( $_GET['tab'] ) && in_array( sanitize_key( $_GET['tab'] ), $allowed_tabs, true ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		? sanitize_key( $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: 'license';

	// Shared styles
	$h2_style      = 'font-size:14px; font-weight:600; color:#1d2327; padding-bottom:10px; border-bottom:1px solid #ddd; margin:28px 0 16px;';
	$p_style       = 'color:#555; font-size:13px; max-width:760px; margin:0 0 14px; line-height:1.6;';
	$card_style    = 'background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:16px;';
	$section_label = 'font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:0 0 12px;';

	// Helper: tab URL
	$tab_url = function( $tab ) {
		$base = admin_url( 'options-general.php?page=wp-pugmill' );
		return 'license' === $tab ? $base : $base . '&tab=' . rawurlencode( $tab );
	};
	?>
	<div class="wrap">

		<!-- ── Page header ──────────────────────────────────────────── -->
		<div style="display:flex; align-items:center; gap:12px; margin-bottom:4px; margin-top:16px;">
			<img src="<?php echo esc_url( WPPUGMILL_PLUGIN_URL . 'assets/pugmill-logo.svg' ); ?>"
				alt="WP Pugmill"
				width="36" height="36"
				style="display:block; flex-shrink:0;">
			<h1 style="margin:0; padding:0; line-height:1;"><?php esc_html_e( 'WP Pugmill Settings', 'wp-pugmill' ); ?> <span style="font-size:13px; font-weight:normal; color:#666;">v<?php echo esc_html( WPPUGMILL_VERSION ); ?></span></h1>
		</div>

		<!-- ── License status notice (license tab only) ─────────────── -->
		<?php if ( 'license' === $active_tab ) : ?>
		<?php if ( 'ai' === $mode ) : ?>
			<div class="notice notice-success inline" style="margin-top:12px;">
				<p>
					<strong><?php esc_html_e( 'WP Pugmill Pro active.', 'wp-pugmill' ); ?></strong>
					<?php if ( ! empty( $license_status['customer_email'] ) ) : ?>
						<?php printf(
							wp_kses( __( 'Licensed to <strong>%s</strong>.', 'wp-pugmill' ), array( 'strong' => array() ) ),
							esc_html( $license_status['customer_email'] )
						); ?>
					<?php endif; ?>
					<?php
					$expires_ts = ! empty( $license_status['expires_at'] ) ? strtotime( $license_status['expires_at'] ) : false;
					if ( $expires_ts ) :
					?>
						<?php printf(
							esc_html__( 'Renews %s.', 'wp-pugmill' ),
							esc_html( date( 'F j, Y', $expires_ts ) ) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
						); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php elseif ( ! empty( $license_key ) && 'free' === $mode ) : ?>
			<div class="notice notice-error inline" style="margin-top:12px;">
				<p><strong><?php esc_html_e( 'License key invalid.', 'wp-pugmill' ); ?></strong> <?php echo esc_html( $license_status['error'] ?? __( 'Please check your key and try again.', 'wp-pugmill' ) ); ?></p>
			</div>
		<?php else : ?>
			<div class="notice notice-warning inline" style="margin-top:12px;">
				<p>
					<strong><?php esc_html_e( 'Free mode.', 'wp-pugmill' ); ?></strong>
					<?php if ( $api_key ) : ?>
						<?php esc_html_e( 'API key connected — basic AEO generation active. Upgrade to WP Pugmill Pro to unlock the full feature set.', 'wp-pugmill' ); ?>
					<?php else : ?>
						<?php printf(
							wp_kses( __( 'Manual AEO tools active. <a href="%1$s">Add an API key</a> to enable basic AI generation for free, or <a href="%2$s" target="_blank">upgrade to WP Pugmill Pro</a> for the full feature set.', 'wp-pugmill' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
							esc_url( admin_url( 'options-general.php?page=wp-pugmill&tab=ai-provider' ) ),
							esc_url( 'https://wppugmill.com/pricing' )
						); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>
		<?php endif; ?>

		<!-- ── Tab navigation ──────────────────────────────────────── -->
		<nav class="nav-tab-wrapper" style="margin-top:16px;">
			<?php
			$tabs = array(
				'license'       => __( 'License', 'wp-pugmill' ),
				'ai-provider'   => __( 'AI Provider', 'wp-pugmill' ),
				'site-aeo'      => __( 'Site AEO', 'wp-pugmill' ),
				'bulk-aeo'      => __( 'Bulk AEO', 'wp-pugmill' ),
				'author-voice'  => __( 'Author Voice', 'wp-pugmill' ),
				'compatibility' => __( 'Plugin Compatibility', 'wp-pugmill' ),
				'sitemap'       => __( 'Sitemap & Robots', 'wp-pugmill' ),
				'analytics'     => __( 'Bot Analytics', 'wp-pugmill' ),
			);
			foreach ( $tabs as $tab_id => $tab_label ) :
			?>
			<a href="<?php echo esc_url( $tab_url( $tab_id ) ); ?>"
				class="nav-tab<?php echo $active_tab === $tab_id ? ' nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tab_label ); ?>
			</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( 'license' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     LICENSE TAB
		     ════════════════════════════════════════════════════════════ -->
		<div style="display:grid; grid-template-columns:1fr 280px; gap:28px; align-items:start; margin-top:24px;">
		<div><!-- left column -->
		<p style="<?php echo esc_attr( $p_style ); ?>">
			<?php esc_html_e( 'A pugmill in a pottery studio turns slop into usable clay — de-aired, wedged, and ready to work. This plugin does the same for your content: takes the good parts of your existing SEO and transforms them into structured, AI-ready signal that answer engines can consume and cite.', 'wp-pugmill' ); ?>
		</p>
		<p style="<?php echo esc_attr( $p_style ); ?>">
			<?php esc_html_e( 'WP Pugmill is free to use — manually fill in AEO metadata for every post, or connect your own AI Provider (Anthropic, OpenAI, or Google). The AI Provider key you use is encrypted on your server — usage is billed directly by your AI provider.', 'wp-pugmill' ); ?>
		</p>
		<h3 style="font-size:13px; font-weight:700; color:#1d2327; margin:20px 0 6px;"><?php esc_html_e( 'Upgrade to WP Pugmill Pro', 'wp-pugmill' ); ?></h3>
		<p style="<?php echo esc_attr( $p_style ); ?>">
			<?php esc_html_e( 'Upgrading to WP Pugmill Pro unlocks the full feature set: Generate All AEO, SEO Generation, Tone Check, Topic Focus, Social Media Draftss, and more (see below).', 'wp-pugmill' ); ?>
		</p>
		<form method="post" action="options.php" style="margin-top:16px;">
			<?php settings_fields( 'wppugmill_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="wppugmill_license_key"><?php esc_html_e( 'WP Pugmill Pro License Key', 'wp-pugmill' ); ?></label></th>
					<td>
						<input type="text"
							id="wppugmill_license_key"
							name="wppugmill_license_key"
							value="<?php echo esc_attr( wppugmill_mask_secret( $license_key ) ); ?>"
							style="width:420px;"
							placeholder="XXXX-XXXX-XXXX-XXXX">
						<?php if ( 'ai' === $mode ) : ?>
							<span style="color:#46b450; margin-left:8px;">&#10003; <?php esc_html_e( 'Active', 'wp-pugmill' ); ?></span>
						<?php elseif ( ! empty( $license_key ) ) : ?>
							<span style="color:#cc1818; margin-left:8px;">&#10007; <?php esc_html_e( 'Invalid', 'wp-pugmill' ); ?></span>
						<?php endif; ?>
						<p class="description">
							<?php
							echo esc_html__( 'Enter your WP Pugmill WP Pugmill Pro license key.', 'wp-pugmill' );
							echo ' ';
							printf( '<a href="%s" target="_blank">%s</a>', esc_url( 'https://wppugmill.com/pricing' ), esc_html__( 'Get a license →', 'wp-pugmill' ) );
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		</div><!-- /left column -->

		<div><!-- right column — current plan -->
			<?php if ( 'ai' === $mode ) : ?>
			<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:18px 20px;">
				<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#16a34a; margin:0 0 6px;"><?php esc_html_e( 'Current Plan', 'wp-pugmill' ); ?></p>
				<p style="font-size:17px; font-weight:700; color:#1d2327; margin:0 0 10px;"><?php esc_html_e( 'WP Pugmill Pro', 'wp-pugmill' ); ?></p>
				<ul style="font-size:12px; color:#374151; margin:0; padding-left:16px; line-height:1.9;">
					<li><?php esc_html_e( 'All AI generation features', 'wp-pugmill' ); ?></li>
					<li><?php esc_html_e( 'Generate All (one-click)', 'wp-pugmill' ); ?></li>
					<li><?php esc_html_e( 'Tone Check &amp; editorial suite', 'wp-pugmill' ); ?></li>
					<li><?php esc_html_e( 'Social Media Drafts', 'wp-pugmill' ); ?></li>
				</ul>
			</div>
			<?php elseif ( ! empty( $api_key ) ) : ?>
			<div style="background:#fefce8; border:1px solid #fde68a; border-radius:8px; padding:18px 20px;">
				<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#d97706; margin:0 0 6px;"><?php esc_html_e( 'Current Plan', 'wp-pugmill' ); ?></p>
				<p style="font-size:17px; font-weight:700; color:#1d2327; margin:0 0 10px;"><?php esc_html_e( 'Free + AI Provider', 'wp-pugmill' ); ?></p>
				<ul style="font-size:12px; color:#374151; margin:0 0 14px; padding-left:16px; line-height:1.9;">
					<li><?php esc_html_e( 'Core AEO generators', 'wp-pugmill' ); ?></li>
					<li><?php esc_html_e( 'Manual schema &amp; SEO', 'wp-pugmill' ); ?></li>
					<li><?php esc_html_e( 'Bot Analytics', 'wp-pugmill' ); ?></li>
				</ul>
				<a href="<?php echo esc_url( 'https://wppugmill.com/pricing' ); ?>" target="_blank" class="button button-primary" style="width:100%; text-align:center; box-sizing:border-box;"><?php esc_html_e( 'Upgrade to WP Pugmill Pro', 'wp-pugmill' ); ?></a>
			</div>
			<?php else : ?>
			<div style="background:#f6f7f7; border:1px solid #ddd; border-radius:8px; padding:18px 20px;">
				<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:0 0 6px;"><?php esc_html_e( 'Current Plan', 'wp-pugmill' ); ?></p>
				<p style="font-size:17px; font-weight:700; color:#1d2327; margin:0 0 10px;"><?php esc_html_e( 'Free', 'wp-pugmill' ); ?></p>
				<ul style="font-size:12px; color:#374151; margin:0 0 14px; padding-left:16px; line-height:1.9;">
					<li><?php esc_html_e( 'Manual AEO editing', 'wp-pugmill' ); ?></li>
					<li><?php esc_html_e( 'Manual schema &amp; SEO', 'wp-pugmill' ); ?></li>
					<li><?php esc_html_e( 'Bot Analytics', 'wp-pugmill' ); ?></li>
				</ul>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-pugmill&tab=ai-provider' ) ); ?>" class="button" style="width:100%; text-align:center; box-sizing:border-box; margin-bottom:8px;"><?php esc_html_e( 'Connect API Key →', 'wp-pugmill' ); ?></a>
				<a href="<?php echo esc_url( 'https://wppugmill.com/pricing' ); ?>" target="_blank" class="button button-primary" style="width:100%; text-align:center; box-sizing:border-box;"><?php esc_html_e( 'Get WP Pugmill Pro →', 'wp-pugmill' ); ?></a>
			</div>
			<?php endif; ?>
		</div><!-- /right column -->
		</div><!-- /two-column grid -->

		<!-- ── Feature comparison table ─────────────────────────────────── -->
		<div style="margin-top:32px; background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px;">
			<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:0 0 16px;">
				<?php esc_html_e( 'What\'s included', 'wp-pugmill' ); ?>
			</p>
			<table class="widefat" style="font-size:13px;">
				<thead style="background:#f6f7f7;">
					<tr>
						<th style="padding:10px 16px; color:#1d2327; font-weight:600; width:58%;"><?php esc_html_e( 'Feature', 'wp-pugmill' ); ?></th>
						<th style="text-align:center; padding:10px 16px; color:#1d2327; font-weight:600; width:21%;"><?php esc_html_e( 'Free', 'wp-pugmill' ); ?></th>
						<th style="text-align:center; padding:10px 16px; color:#7c3aed; font-weight:600; width:21%;"><?php esc_html_e( 'WP Pugmill Pro', 'wp-pugmill' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$row = function( $label, $free, $paid, $note = '' ) {
						$tick  = '<span style="color:#46b450; font-weight:700;">&#10003;</span>';
						$dash  = '<span style="color:#ccc;">&#8212;</span>';
						$free_cell = $free  ? $tick : $dash;
						$paid_cell = $paid  ? $tick : $dash;
						static $alt = false;
						$alt = !$alt;
						$bg = $alt ? 'background:#fff;' : 'background:#f9fafb;';
						echo '<tr style="' . esc_attr( $bg ) . '">';
						echo '<td style="padding:10px 16px; color:#374151;">' . esc_html( $label );
						if ( $note ) {
							echo ' <span style="font-size:11px; color:#9ca3af;">' . esc_html( $note ) . '</span>';
						}
						echo '</td>';
						echo '<td style="text-align:center; padding:10px 16px;">' . wp_kses_post( $free_cell ) . '</td>';
						echo '<td style="text-align:center; padding:10px 16px;">' . wp_kses_post( $paid_cell ) . '</td>';
						echo '</tr>';
					};
					$section = function( $label ) {
						echo '<tr style="background:#faf7ff;"><td colspan="3" style="padding:10px 16px 8px; font-size:11px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.06em; border-top:1px solid #ede9fb;">' . esc_html( $label ) . '</td></tr>';
					};

					$section( __( 'Core tools', 'wp-pugmill' ) );
					$row( __( 'Manual AEO editing (Summary, Q&A, Entities, Keywords)', 'wp-pugmill' ),  true, true );
					$row( __( 'Manual SEO editing (Title, Meta, Canonical, OG…)', 'wp-pugmill' ),      true, true );
					$row( __( 'Manual Schema markup (Article, HowTo, Product, Event…)', 'wp-pugmill' ), true, true );
					$row( __( 'AEO Health score', 'wp-pugmill' ),                                       true, true );
					$row( __( '/llms.txt, XML Sitemap & IndexNow', 'wp-pugmill' ),                      true, true );
					$row( __( 'Bot Analytics', 'wp-pugmill' ),                                          true, true );
					$row( __( 'Plugin Compatibility checker', 'wp-pugmill' ),                           true, true );

					$section( __( 'Basic AI generation — free with your own API key', 'wp-pugmill' ) );
					$row( __( 'Connect Anthropic, OpenAI, or Google Gemini key', 'wp-pugmill' ),        true, true );
					$row( __( 'Generate AEO Summary', 'wp-pugmill' ),                                   true, true );
					$row( __( 'Generate Q&A Pairs', 'wp-pugmill' ),                                     true, true );
					$row( __( 'Generate Entities', 'wp-pugmill' ),                                      true, true );
					$row( __( 'Generate Keywords', 'wp-pugmill' ),                                      true, true );
					$row( __( 'Draft Site Summary with AI (Settings)', 'wp-pugmill' ),                  true, true );
					$row( __( 'AI llms.txt Improvement Tips (Settings)', 'wp-pugmill' ),                true, true );

					$section( __( 'Full AI generation — WP Pugmill Pro license required', 'wp-pugmill' ) );
					$row( __( 'Generate All (one-click, 7 steps)', 'wp-pugmill' ),           false, true );
					$row( __( 'Generate SEO/AEO Title & Description', 'wp-pugmill' ),            false, true );
					$row( __( 'Schema AI Type (Article, HowTo, Product, Event, LocalBusiness, VideoObject, Review)', 'wp-pugmill' ), false, true );
					$row( __( 'Tone Check', 'wp-pugmill' ),                                  false, true );
					$row( __( 'Topic Focus & Refine', 'wp-pugmill' ),                        false, true );
					$row( __( 'Internal Links', 'wp-pugmill' ),                              false, true );
					$row( __( 'Reading Level', 'wp-pugmill' ),                               false, true );
										$row( __( 'Excerpt Generator', 'wp-pugmill' ),                           false, true );
					$row( __( 'Social Media Drafts', 'wp-pugmill' ),                          false, true );
					$row( __( 'Bulk AEO Generator (all posts in one run)', 'wp-pugmill' ),   false, true );
					?>
				</tbody>
			</table>
			<?php if ( 'free' === $mode ) : ?>
			<p style="margin-top:16px; font-size:13px;">
				<?php if ( empty( $api_key ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-pugmill&tab=ai-provider' ) ); ?>" class="button"><?php esc_html_e( 'Connect API Key — free →', 'wp-pugmill' ); ?></a>
					&nbsp;
				<?php endif; ?>
				<a href="<?php echo esc_url( 'https://wppugmill.com/pricing' ); ?>" target="_blank" class="button button-primary"><?php esc_html_e( 'Get WP Pugmill Pro →', 'wp-pugmill' ); ?></a>
			</p>
			<?php endif; ?>
		</div>

		<?php elseif ( 'ai-provider' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     AI PROVIDER TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php if ( in_array( $mode, array( 'ai', 'free' ), true ) ) : ?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:24px;">
		<p style="<?php echo esc_attr( $p_style ); ?>">
			<?php esc_html_e( 'WP Pugmill uses a bring-your-own-key model — you connect directly to Anthropic, OpenAI, or Google Gemini using your own API account. Your key is encrypted and stored server-side only, never exposed to visitors or transmitted through our servers. Usage is billed directly by your chosen AI provider.', 'wp-pugmill' ); ?>
		</p>

		<div style="margin:20px 0 4px;">
			<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:0 0 10px;">
				<?php esc_html_e( 'How to connect', 'wp-pugmill' ); ?>
			</p>
			<ol style="font-size:13px; color:#444; margin:0 0 20px; padding-left:20px; line-height:1.8;">
				<li><?php esc_html_e( 'Choose a provider and create a free account (you only pay for what you use).', 'wp-pugmill' ); ?></li>
				<li><?php esc_html_e( 'Generate an API key in your provider\'s console (links below).', 'wp-pugmill' ); ?></li>
				<li><?php esc_html_e( 'Paste the key in the form and click Save Settings.', 'wp-pugmill' ); ?></li>
			</ol>

			<div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px;">
				<div style="padding:18px 20px; background:#f9f6ff; border:1px solid #e8d5fd; border-radius:8px; border-top:3px solid #7c3aed; display:flex; flex-direction:column;">
					<p style="font-size:13px; font-weight:700; color:#1e1e1e; margin:0 0 6px;">Anthropic — Claude</p>
					<p style="font-size:12px; color:#555; margin:0 0 12px; line-height:1.6; flex:1;"><?php esc_html_e( 'Excellent reasoning and long-form writing. Recommended for AEO and editorial tasks.', 'wp-pugmill' ); ?></p>
					<a href="<?php echo esc_url( 'https://console.anthropic.com/settings/keys' ); ?>" target="_blank" style="font-size:12px; font-weight:600; color:#7c3aed; text-decoration:none;"><?php esc_html_e( 'Get API key →', 'wp-pugmill' ); ?></a>
				</div>
				<div style="padding:18px 20px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; border-top:3px solid #16a34a; display:flex; flex-direction:column;">
					<p style="font-size:13px; font-weight:700; color:#1e1e1e; margin:0 0 6px;">OpenAI — GPT</p>
					<p style="font-size:12px; color:#555; margin:0 0 12px; line-height:1.6; flex:1;"><?php esc_html_e( 'Widely used and reliable. Strong across summarization, SEO, and structured output.', 'wp-pugmill' ); ?></p>
					<a href="<?php echo esc_url( 'https://platform.openai.com/api-keys' ); ?>" target="_blank" style="font-size:12px; font-weight:600; color:#16a34a; text-decoration:none;"><?php esc_html_e( 'Get API key →', 'wp-pugmill' ); ?></a>
				</div>
				<div style="padding:18px 20px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; border-top:3px solid #2563eb; display:flex; flex-direction:column;">
					<p style="font-size:13px; font-weight:700; color:#1e1e1e; margin:0 0 6px;">Google — Gemini</p>
					<p style="font-size:12px; color:#555; margin:0 0 12px; line-height:1.6; flex:1;"><?php esc_html_e( 'Google\'s multimodal model. Well-suited for search-aware content and entity recognition.', 'wp-pugmill' ); ?></p>
					<a href="<?php echo esc_url( 'https://aistudio.google.com/apikey' ); ?>" target="_blank" style="font-size:12px; font-weight:600; color:#2563eb; text-decoration:none;"><?php esc_html_e( 'Get API key →', 'wp-pugmill' ); ?></a>
				</div>
			</div>
		</div>

		<form method="post" action="options.php" style="margin-top:24px;">
			<?php settings_fields( 'wppugmill_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="wppugmill_ai_provider"><?php esc_html_e( 'Provider', 'wp-pugmill' ); ?></label></th>
					<td>
						<select id="wppugmill_ai_provider" name="wppugmill_ai_provider">
							<option value=""><?php esc_html_e( '— Select provider —', 'wp-pugmill' ); ?></option>
							<?php foreach ( array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)', 'gemini' => 'Google Gemini' ) as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( get_option( 'wppugmill_ai_provider', '' ), $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="wppugmill_ai_api_key"><?php esc_html_e( 'API Key', 'wp-pugmill' ); ?></label></th>
					<td>
						<input type="password"
							id="wppugmill_ai_api_key"
							name="wppugmill_ai_api_key"
							value="<?php echo esc_attr( wppugmill_mask_secret( $api_key ) ); ?>"
							style="width:420px;"
							placeholder="sk-...">
						<p class="description"><?php esc_html_e( 'Paste the API key from your chosen provider above. The key is encrypted before storage.', 'wp-pugmill' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="wppugmill_ai_rate_limit"><?php esc_html_e( 'Hourly Call Limit', 'wp-pugmill' ); ?></label></th>
					<td>
						<select id="wppugmill_ai_rate_limit" name="wppugmill_ai_rate_limit">
							<?php foreach ( array( 50, 100, 200 ) as $limit_option ) : ?>
								<option value="<?php echo esc_attr( $limit_option ); ?>" <?php selected( (int) get_option( 'wppugmill_ai_rate_limit', 50 ), $limit_option ); ?>><?php echo esc_html( $limit_option ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Maximum number of AI generations any editor can make per hour. Lower values help keep your API spend predictable — each generation calls your provider\'s API and is billed to your account. Resets automatically after 60 minutes.', 'wp-pugmill' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<p style="margin-top:4px;">
				<?php submit_button( null, 'primary', 'submit', false ); ?>
				<button type="button" id="wppugmill-test-api-key" class="button" style="margin-left:8px;">
					<?php esc_html_e( 'Test Connection', 'wp-pugmill' ); ?>
				</button>
				<span id="wppugmill-test-api-key-status" style="margin-left:10px; font-size:13px;"></span>
			</p>
		</form>
		</div><!-- /ai-provider card -->
		<script>
		(function() {
			var btn    = document.getElementById( 'wppugmill-test-api-key' );
			var status = document.getElementById( 'wppugmill-test-api-key-status' );
			if ( ! btn ) { return; }

			btn.addEventListener( 'click', function() {
				btn.disabled       = true;
				btn.textContent    = '<?php echo esc_js( __( 'Testing\u2026', 'wp-pugmill' ) ); ?>';
				status.textContent = '';
				status.style.color = '';

				var body = new URLSearchParams();
				body.append( 'action', 'wppugmill_test_api_key' );
				body.append( 'nonce',  <?php echo wp_json_encode( wp_create_nonce( 'wppugmill_test_api_key' ) ); ?> );

				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        body.toString(),
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( res ) {
					if ( res.success ) {
						status.textContent = '\u2713 ' + res.data.message;
						status.style.color = '#46b450';
					} else {
						var msg = ( res.data && res.data.message ) ? res.data.message : '<?php echo esc_js( __( 'Connection test failed.', 'wp-pugmill' ) ); ?>';
						status.textContent = '\u2717 ' + msg;
						status.style.color = '#dc3232';
					}
				} )
				.catch( function() {
					status.textContent = '<?php echo esc_js( __( 'Network error \u2014 could not reach provider.', 'wp-pugmill' ) ); ?>';
					status.style.color = '#dc3232';
				} )
				.finally( function() {
					btn.disabled    = false;
					btn.textContent = '<?php echo esc_js( __( 'Test Connection', 'wp-pugmill' ) ); ?>';
				} );
			} );

			// Auto-run the test after a key save on this tab.
			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			btn.click();
			<?php endif; ?>
		}());
		</script>
		<?php else : ?>
		<div style="margin-top:24px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px; padding:16px; max-width:600px;">
			<strong><?php esc_html_e( 'AI generation is available with an WP Pugmill Pro license.', 'wp-pugmill' ); ?></strong><br>
			<span style="color:#666;"><?php esc_html_e( 'Connect Claude, GPT-4, or Gemini to auto-generate your AEO metadata with one click.', 'wp-pugmill' ); ?></span><br><br>
			<a href="<?php echo esc_url( 'https://wppugmill.com/pricing' ); ?>" target="_blank" class="button button-primary"><?php esc_html_e( 'Get WP Pugmill Pro License →', 'wp-pugmill' ); ?></a>
		</div>
		<?php endif; ?>

		<?php elseif ( 'site-aeo' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     SITE AEO TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php
		// Pre-fill org name from blog name when never set
		$org_name_saved   = get_option( 'wppugmill_org_name', '' );
		$org_name_display = $org_name_saved !== '' ? $org_name_saved : get_bloginfo( 'name' );
		// Site summary generation is free with BYOK — only requires an API key.
		$ai_available     = ! empty( wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' ) );
		?>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:24px;">
			<?php esc_html_e( 'Site AEO metadata describes your organization to AI crawlers at a site-wide level. The summary and organization details are published in your /llms.txt file and embedded in Organization schema in every page header. Setting these accurately gives AI answer engines — ChatGPT, Perplexity, Gemini — a reliable source of truth about who you are and what your site covers.', 'wp-pugmill' ); ?>
		</p>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:16px;">
		<form method="post" action="options.php">
			<?php settings_fields( 'wppugmill_settings' ); ?>
			<table class="form-table">
				<tr>
					<th style="vertical-align:top; padding-top:12px;"><label for="wppugmill_site_summary"><?php esc_html_e( 'Site Summary', 'wp-pugmill' ); ?></label></th>
					<td>
						<textarea id="wppugmill_site_summary" name="wppugmill_site_summary" rows="7" style="width:100%; max-width:600px; font-family:monospace; font-size:13px;"><?php echo esc_textarea( get_option( 'wppugmill_site_summary', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Used in /llms.txt and Organization schema. Describe your site for AI crawlers.', 'wp-pugmill' ); ?></p>
						<?php if ( $ai_available ) : ?>
						<p style="margin-top:8px;">
							<button type="button" id="wppugmill-gen-site-summary" style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600; background:#7c3aed; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap;">
								✨ <?php esc_html_e( 'Draft with AI', 'wp-pugmill' ); ?>
							</button>
							<span id="wppugmill-site-summary-status" style="margin-left:10px; font-size:13px; color:#666;"></span>
						</p>
						<?php else : ?>
						<p style="margin-top:6px; font-size:12px; color:#9ca3af;">
							<?php printf(
								wp_kses( __( 'Add an <a href="%s">API key</a> to draft this with AI.', 'wp-pugmill' ), array( 'a' => array( 'href' => array() ) ) ),
								esc_url( admin_url( 'options-general.php?page=wp-pugmill&tab=ai-provider' ) )
							); ?>
						</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="wppugmill_org_name"><?php esc_html_e( 'Organization Name', 'wp-pugmill' ); ?></label></th>
					<td>
						<input type="text" id="wppugmill_org_name" name="wppugmill_org_name"
							value="<?php echo esc_attr( $org_name_display ); ?>"
							style="width:300px;">
						<?php if ( $org_name_saved === '' ) : ?>
						<p class="description"><?php esc_html_e( 'Pre-filled from your site title — save to confirm.', 'wp-pugmill' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="wppugmill_org_type"><?php esc_html_e( 'Organization Type', 'wp-pugmill' ); ?></label></th>
					<td>
						<select id="wppugmill_org_type" name="wppugmill_org_type">
							<?php foreach ( array( 'Person', 'Organization', 'Corporation', 'LocalBusiness', 'EducationalOrganization', 'NGO' ) as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>" <?php selected( get_option( 'wppugmill_org_type', 'Organization' ), $type ); ?>><?php echo esc_html( $type ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		</div><!-- /site-aeo card -->

		<?php if ( $ai_available ) : ?>
		<script>
		(function() {
			var btn     = document.getElementById( 'wppugmill-gen-site-summary' );
			var textarea = document.getElementById( 'wppugmill_site_summary' );
			var status  = document.getElementById( 'wppugmill-site-summary-status' );
			if ( ! btn || ! textarea ) { return; }

			btn.addEventListener( 'click', function() {
				btn.disabled  = true;
				btn.classList.add( 'wppugmill-loading' );
				btn.innerHTML = '<?php echo esc_js( __( 'Drafting…', 'wp-pugmill' ) ); ?>';
				status.textContent = '';
				status.style.color = '';

				var body = new URLSearchParams();
				body.append( 'action', 'wppugmill_generate_site_summary' );
				body.append( 'nonce',  <?php echo wp_json_encode( wp_create_nonce( 'wppugmill_generate_site_summary' ) ); ?> );

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
						status.textContent = '<?php echo esc_js( __( '✓ Drafted — review and save.', 'wp-pugmill' ) ); ?>';
						status.style.color = '#46b450';
					} else {
						var msg = res.data && res.data.message ? res.data.message : '<?php echo esc_js( __( 'Generation failed. Please try again.', 'wp-pugmill' ) ); ?>';
						status.innerHTML   = msg;
						status.style.color = '#dc3232';
					}
				} )
				.catch( function() {
					status.textContent = '<?php echo esc_js( __( 'Network error. Please try again.', 'wp-pugmill' ) ); ?>';
					status.style.color = '#dc3232';
				} )
				.finally( function() {
					btn.disabled  = false;
					btn.classList.remove( 'wppugmill-loading' );
					btn.innerHTML = '✨ <?php echo esc_js( __( 'Draft with AI', 'wp-pugmill' ) ); ?>';
				} );
			} );
		}());
		</script>
		<?php endif; ?>

		<?php
		// ── llms.txt Completeness Score ───────────────────────────────────────
		global $wpdb;

		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			 AND post_type IN ('post', 'page')
			 ORDER BY post_modified DESC
			 LIMIT 500"
		);
		$total = count( $post_ids );

		$with_summary  = 0;
		$with_qa       = 0;
		$with_keywords = 0;
		$with_entities = 0;

		if ( $total > 0 ) {
			// Single query for all AEO meta — avoids N+1 per-post reads.
			$ids_in = implode( ',', array_map( 'intval', $post_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				"SELECT meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_wppugmill_aeo'
				 AND post_id IN ({$ids_in})"
			);
			foreach ( (array) $rows as $row ) {
				$aeo = json_decode( $row->meta_value, true );
				if ( ! is_array( $aeo ) ) { continue; }
				if ( ! empty( $aeo['summary'] ) )   { $with_summary++; }
				if ( ! empty( $aeo['questions'] ) )  { $with_qa++; }
				if ( ! empty( $aeo['keywords'] ) )   { $with_keywords++; }
				if ( ! empty( $aeo['entities'] ) )   { $with_entities++; }
			}
		}

		$has_site_summary = '' !== get_option( 'wppugmill_site_summary', '' );
		$has_org_name     = '' !== get_option( 'wppugmill_org_name', '' );
		$summary_pct      = $total > 0 ? round( $with_summary  / $total * 100 ) : 0;
		$qa_pct           = $total > 0 ? round( $with_qa       / $total * 100 ) : 0;
		$keywords_pct     = $total > 0 ? round( $with_keywords / $total * 100 ) : 0;
		$entities_pct     = $total > 0 ? round( $with_entities / $total * 100 ) : 0;

		$score  = 0;
		$score += $has_site_summary ? 20 : 0;
		$score += $has_org_name     ? 5  : 0;
		$score += (int) round( $summary_pct  / 100 * 30 );
		$score += (int) round( $qa_pct       / 100 * 20 );
		$score += (int) round( $keywords_pct / 100 * 15 );
		$score += (int) round( $entities_pct / 100 * 10 );

		$score_color = $score >= 80 ? '#46b450' : ( $score >= 50 ? '#d97706' : '#cc1818' );
		$score_label = $score >= 80
			? __( 'Strong', 'wp-pugmill' )
			: ( $score >= 50 ? __( 'Developing', 'wp-pugmill' ) : __( 'Needs Work', 'wp-pugmill' ) );

		/**
		 * Render one coverage bar row.
		 *
		 * @param string $label   Human label.
		 * @param int    $pct     Percentage filled (0–100).
		 * @param int    $count   Posts with this field set.
		 * @param int    $total   Total published posts.
		 */
		$coverage_row = function( $label, $pct, $count, $total ) {
			$bar_color = $pct >= 70 ? '#7c3aed' : ( $pct >= 40 ? '#d97706' : '#cc1818' );
			?>
			<div style="margin:10px 0;">
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
					<span style="font-size:12px; color:#374151;"><?php echo esc_html( $label ); ?></span>
					<span style="font-size:12px; color:#6b7280;"><?php echo esc_html( $count . '/' . $total . ' (' . $pct . '%)' ); ?></span>
				</div>
				<div style="height:6px; background:#e5e7eb; border-radius:9999px; overflow:hidden;">
					<div style="height:100%; width:<?php echo esc_attr( $pct ); ?>%; background:<?php echo esc_attr( $bar_color ); ?>; border-radius:9999px;"></div>
				</div>
			</div>
			<?php
		};
		?>

		<div style="background:#faf7ff; border:1px solid #d4c8f0; border-radius:8px; padding:20px 24px; margin:24px 0 0;">
			<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
				<div>
					<h3 style="margin:0 0 4px; font-size:14px; font-weight:600; color:#1d2327;">
						<?php esc_html_e( 'llms.txt Quality Score', 'wp-pugmill' ); ?>
					</h3>
					<p style="margin:0; font-size:12px; color:#6b7280;">
						<?php
						printf(
							/* translators: %d: number of posts analyzed */
							esc_html__( 'Based on %d published posts. Higher scores mean richer content for AI crawlers.', 'wp-pugmill' ),
							(int) $total
						);
						?>
					</p>
				</div>
				<div style="text-align:right; flex-shrink:0;">
					<span style="font-size:28px; font-weight:700; color:<?php echo esc_attr( $score_color ); ?>; line-height:1;"><?php echo (int) $score; ?></span>
					<span style="font-size:14px; color:#9ca3af;">/100</span>
					<p style="margin:2px 0 0; font-size:11px; font-weight:600; color:<?php echo esc_attr( $score_color ); ?>; text-transform:uppercase; letter-spacing:.05em;">
						<?php echo esc_html( $score_label ); ?>
					</p>
				</div>
			</div>

			<div style="margin-top:16px; padding-top:16px; border-top:1px solid #e8e0f7; display:grid; grid-template-columns:1fr 1fr 3fr; gap:0 24px;">
				<div>
					<p style="font-size:11px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.06em; margin:0 0 8px;">
						<?php esc_html_e( 'Site Level', 'wp-pugmill' ); ?>
					</p>
					<p style="font-size:12px; margin:4px 0; color:#374151;">
						<?php echo $has_site_summary ? '✓' : '✗'; ?>
						<?php esc_html_e( 'Site summary', 'wp-pugmill' ); ?>
					</p>
					<p style="font-size:12px; margin:4px 0; color:#374151;">
						<?php echo $has_org_name ? '✓' : '✗'; ?>
						<?php esc_html_e( 'Organization name', 'wp-pugmill' ); ?>
					</p>
				</div>
				<div>
					<p style="font-size:11px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.06em; margin:0 0 8px;">
						<?php esc_html_e( 'How this is scored', 'wp-pugmill' ); ?>
					</p>
					<ul style="margin:0; padding:0; list-style:none; font-size:11px; color:#6b7280;">
						<li style="padding:2px 0;"><?php esc_html_e( 'Site summary — 20 pts', 'wp-pugmill' ); ?></li>
						<li style="padding:2px 0;"><?php esc_html_e( 'Post summaries — up to 30 pts', 'wp-pugmill' ); ?></li>
						<li style="padding:2px 0;"><?php esc_html_e( 'Organization name — 5 pts', 'wp-pugmill' ); ?></li>
						<li style="padding:2px 0;"><?php esc_html_e( 'Q&A pairs — up to 20 pts', 'wp-pugmill' ); ?></li>
						<li style="padding:2px 0;"><?php esc_html_e( 'Keywords — up to 15 pts', 'wp-pugmill' ); ?></li>
						<li style="padding:2px 0;"><?php esc_html_e( 'Entities — up to 10 pts', 'wp-pugmill' ); ?></li>
					</ul>
				</div>
				<div>
					<p style="font-size:11px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.06em; margin:0 0 6px;">
						<?php esc_html_e( 'Post Coverage', 'wp-pugmill' ); ?>
					</p>
					<?php if ( $total > 0 ) : ?>
					<?php $coverage_row( __( 'Summaries', 'wp-pugmill' ),  $summary_pct,  $with_summary,  $total ); ?>
					<?php $coverage_row( __( 'Q&A pairs', 'wp-pugmill' ),  $qa_pct,       $with_qa,       $total ); ?>
					<?php $coverage_row( __( 'Keywords',  'wp-pugmill' ),  $keywords_pct, $with_keywords, $total ); ?>
					<?php $coverage_row( __( 'Entities',  'wp-pugmill' ),  $entities_pct, $with_entities, $total ); ?>
					<?php else : ?>
					<p style="font-size:12px; color:#9ca3af;"><?php esc_html_e( 'No published posts yet.', 'wp-pugmill' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div style="margin-top:16px; padding-top:16px; border-top:1px solid #e8e0f7;">
				<p style="font-size:12px; color:#6b7280; margin:0 0 12px; line-height:1.5;">
					<?php esc_html_e( 'AI answer engines read /llms.txt to understand your site. Richer metadata means more accurate AI-generated answers and summaries that attribute content back to you.', 'wp-pugmill' ); ?>
				</p>
				<?php if ( $ai_available ) : ?>
				<button id="wppugmill-improve-llms-btn" type="button"
					data-score="<?php echo esc_attr( (int) $score ); ?>"
					data-total="<?php echo esc_attr( (int) $total ); ?>"
					data-has-summary="<?php echo esc_attr( $has_site_summary ? '1' : '0' ); ?>"
					data-has-org="<?php echo esc_attr( $has_org_name ? '1' : '0' ); ?>"
					data-summary-pct="<?php echo esc_attr( (int) $summary_pct ); ?>"
					data-qa-pct="<?php echo esc_attr( (int) $qa_pct ); ?>"
					data-keywords-pct="<?php echo esc_attr( (int) $keywords_pct ); ?>"
					data-entities-pct="<?php echo esc_attr( (int) $entities_pct ); ?>"
					style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600; background:#7c3aed; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap;">
					✨ <?php esc_html_e( 'Get AI Improvement Tips', 'wp-pugmill' ); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $ai_available ) : ?>
		<div id="wppugmill-improve-llms-output" style="display:none; margin-top:12px; padding:16px 20px; background:#f9f6ff; border:1px solid #d4c8f0; border-radius:8px;">
			<p style="font-size:11px; font-weight:700; color:#7c3aed; text-transform:uppercase; letter-spacing:.06em; margin:0 0 10px;">
				<?php esc_html_e( 'AI Improvement Tips', 'wp-pugmill' ); ?>
			</p>
			<div id="wppugmill-improve-llms-text" style="font-size:13px; color:#1d2327; line-height:1.6;"></div>
		</div>
		<script>
		(function() {
			var btn    = document.getElementById( 'wppugmill-improve-llms-btn' );
			var output = document.getElementById( 'wppugmill-improve-llms-output' );
			var text   = document.getElementById( 'wppugmill-improve-llms-text' );
			if ( ! btn || ! output || ! text ) { return; }

			btn.addEventListener( 'click', function() {
				btn.disabled  = true;
				btn.classList.add( 'wppugmill-loading' );
				btn.innerHTML = '<?php echo esc_js( __( 'Analyzing…', 'wp-pugmill' ) ); ?>';
				output.style.display = 'block';
				text.innerHTML = '<span style="color:#9ca3af;font-size:13px;"><?php echo esc_js( __( 'Asking AI to review your score…', 'wp-pugmill' ) ); ?></span>';

				var body = new URLSearchParams( {
					action:       'wppugmill_improve_llms_score',
					nonce:        <?php echo wp_json_encode( wp_create_nonce( 'wppugmill_improve_llms_score' ) ); ?>,
					score:        btn.dataset.score,
					total:        btn.dataset.total,
					has_summary:  btn.dataset.hasSummary,
					has_org:      btn.dataset.hasOrg,
					summary_pct:  btn.dataset.summaryPct,
					qa_pct:       btn.dataset.qaPct,
					keywords_pct: btn.dataset.keywordsPct,
					entities_pct: btn.dataset.entitiesPct,
				} );

				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        body.toString(),
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( data ) {
					if ( data.success && data.data && data.data.text ) {
						var tips = data.data.text.split( /\n\n+/ ).filter( Boolean );
						text.innerHTML = tips.map( function( t, i ) {
							return '<p style="margin:' + ( i === 0 ? '0' : '10px' ) + ' 0 0; font-size:13px; color:#1d2327; line-height:1.6;"><strong>' + ( i + 1 ) + '.</strong> ' + t.trim().replace( /</g, '&lt;' ).replace( />/g, '&gt;' ) + '</p>';
						} ).join( '' );
					} else {
						text.innerHTML = '<span style="color:#dc3232;font-size:13px;">' + ( ( data.data && data.data.message ) || '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'wp-pugmill' ) ); ?>' ) + '</span>';
					}
				} )
				.catch( function() {
					text.innerHTML = '<span style="color:#dc3232;font-size:13px;"><?php echo esc_js( __( 'Request failed. Please try again.', 'wp-pugmill' ) ); ?></span>';
				} )
				.finally( function() {
					btn.disabled  = false;
					btn.classList.remove( 'wppugmill-loading' );
					btn.innerHTML = '✨ <?php echo esc_js( __( 'Get AI Improvement Tips', 'wp-pugmill' ) ); ?>';
				} );
			} );
		}());
		</script>
		<?php endif; ?>

		<?php elseif ( 'author-voice' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     AUTHOR VOICE TAB
		     ════════════════════════════════════════════════════════════ -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:24px;">
		<form method="post" action="options.php">
			<?php settings_fields( 'wppugmill_settings' ); ?>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'Describe your writing style, tone, and audience in plain language. When AI generation is active, this guide is injected into every prompt so the AI matches your voice rather than using a generic default.', 'wp-pugmill' ); ?></p>
			<table class="form-table">
				<tr>
					<th style="vertical-align:top; padding-top:12px;"><label for="wppugmill_author_voice"><?php esc_html_e( 'Voice Guide', 'wp-pugmill' ); ?></label></th>
					<td>
						<textarea
							id="wppugmill_author_voice"
							name="wppugmill_author_voice"
							rows="7"
							style="width:100%; max-width:600px; font-family:monospace; font-size:13px;"
							placeholder="<?php echo esc_attr__( 'Example: Write in a conversational but authoritative tone. Use short paragraphs. Avoid jargon — my audience is non-technical small business owners. Prefer active voice and concrete real-world examples. Never use bullet-point listicles. End posts with a clear call to action.', 'wp-pugmill' ); ?>"
						><?php echo esc_textarea( get_option( 'wppugmill_author_voice', '' ) ); ?></textarea>
						<p class="description" style="max-width:600px;">
							<?php esc_html_e( "Describe your tone, audience, style preferences, and things to avoid. The AI uses this verbatim as a style constraint. You can be as specific as you like — the more detail, the better the match. Leave blank to use the AI provider's default tone.", 'wp-pugmill' ); ?>
						</p>
						<?php if ( get_option( 'wppugmill_author_voice', '' ) ) : ?>
							<p style="color:#46b450; font-size:12px; margin-top:4px;">&#10003; <?php esc_html_e( 'Voice guide active — used in all AI generation.', 'wp-pugmill' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top; padding-top:12px;"><label for="wppugmill_author_same_as"><?php esc_html_e( 'Author Social Profiles', 'wp-pugmill' ); ?></label></th>
					<td>
						<textarea
							id="wppugmill_author_same_as"
							name="wppugmill_author_same_as"
							rows="4"
							style="width:100%; max-width:600px; font-family:monospace; font-size:13px;"
							placeholder="https://twitter.com/yourhandle&#10;https://linkedin.com/in/yourprofile&#10;https://yoursite.com/about"
						><?php echo esc_textarea( get_option( 'wppugmill_author_same_as', '' ) ); ?></textarea>
						<p class="description" style="max-width:600px;">
							<?php esc_html_e( 'One URL per line. Added to the author\'s Person schema as sameAs — linking your identity across the web helps AI engines establish entity authority.', 'wp-pugmill' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		</div><!-- /author-voice card -->

		<?php elseif ( 'compatibility' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     PLUGIN COMPATIBILITY TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php
		$compat           = wppugmill_get_compatibility_data();
		$disable_robots   = (int) get_option( 'wppugmill_disable_robots_append', 0 );
		$disable_json_ld  = (int) get_option( 'wppugmill_disable_json_ld', 0 );
		$disable_seo_meta = (int) get_option( 'wppugmill_disable_seo_meta', 0 );
		?>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:24px;">
			<?php esc_html_e( 'WP Pugmill generates three key files for AI crawlers and search engines. If another plugin is already handling one of these files, you\'ll need to disable that feature in the other plugin to let WP Pugmill\'s version serve. This page shows what WP Pugmill has ready and flags any conflicts it detects.', 'wp-pugmill' ); ?>
		</p>

		<style>
		.wppugmill-col-preview {
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
		.wppugmill-file-row {
			margin-bottom: 28px;
		}
		.wppugmill-file-row h3 {
			font-size: 14px;
			font-weight: 600;
			margin: 0 0 4px;
		}
		.wppugmill-preview-card {
			border: 2px solid #46b450;
			border-radius: 6px;
			overflow: hidden;
			margin-top: 12px;
		}
		.wppugmill-preview-card.has-conflict {
			border-color: #ddd;
		}
		.wppugmill-preview-card-header {
			padding: 10px 14px;
			background: #f0faf0;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.wppugmill-preview-card.has-conflict .wppugmill-preview-card-header {
			background: #f6f7f7;
		}
		.wppugmill-conflict-block {
			margin-top: 12px;
			border: 1px solid #f5c542;
			border-left: 4px solid #ffb900;
			border-radius: 0 4px 4px 0;
			background: #fff8e1;
			padding: 12px 16px;
		}
		.wppugmill-conflict-block-title {
			font-weight: 600;
			font-size: 13px;
			margin-bottom: 10px;
		}
		.wppugmill-conflict-item {
			margin-bottom: 8px;
			font-size: 13px;
			line-height: 1.5;
			color: #444;
		}
		.wppugmill-conflict-item:last-child { margin-bottom: 0; }
		.wppugmill-conflict-item strong { color: #222; }
		.wppugmill-no-conflict {
			font-size: 12px;
			color: #46b450;
			margin-top: 8px;
		}
		.wppugmill-compat-tips-result {
			margin-top: 8px;
			font-size: 12px;
			color: #333;
			background: #f9f4ff;
			border-left: 3px solid #7c3aed;
			padding: 8px 12px;
			border-radius: 0 4px 4px 0;
			white-space: pre-line;
			display: none;
		}
		</style>

		<form method="post" action="options.php">
		<?php settings_fields( 'wppugmill_settings' ); ?>

		<!-- ── OUTPUT FILES ─────────────────────────────────────────── -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:0;">
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:0;"><?php esc_html_e( 'Output Files', 'wp-pugmill' ); ?></h2>

		<!-- ── sitemap.xml ─────────────────────────────────────────── -->
		<div class="wppugmill-file-row">
			<h3>/sitemap.xml</h3>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'Lists all your public posts and pages so search engines and AI crawlers can discover them. WP Pugmill\'s version adds an xhtml:link alternate to each entry, pointing crawlers directly to the structured AEO version of each page.', 'wp-pugmill' ); ?></p>

			<div class="wppugmill-preview-card <?php echo ! empty( $compat['sitemap_conflicts'] ) ? 'has-conflict' : ''; ?>">
				<div class="wppugmill-preview-card-header">
					<?php if ( empty( $compat['sitemap_conflicts'] ) ) : ?>
					<span style="color:#46b450; font-weight:600; font-size:13px;">&#10003; WP Pugmill</span>
					<span style="font-size:12px; color:#666;"><?php esc_html_e( 'No conflicts detected', 'wp-pugmill' ); ?></span>
					<?php else : ?>
					<span style="font-weight:600; font-size:13px; color:#333;">WP Pugmill</span>
					<span style="font-size:12px; color:#888;"><?php esc_html_e( 'Preview', 'wp-pugmill' ); ?></span>
					<?php endif; ?>
				</div>
				<pre class="wppugmill-col-preview"><?php echo esc_html( wppugmill_preview_sitemap_xml() ); ?></pre>
			</div>

			<?php if ( ! empty( $compat['sitemap_conflicts'] ) ) : ?>
			<div class="wppugmill-conflict-block">
				<div class="wppugmill-conflict-block-title">
					&#9888; <?php printf(
						esc_html( _n( '%d conflict detected', '%d conflicts detected', count( $compat['sitemap_conflicts'] ), 'wp-pugmill' ) ),
						count( $compat['sitemap_conflicts'] )
					); ?> &mdash; <?php esc_html_e( 'another plugin is also generating /sitemap.xml', 'wp-pugmill' ); ?>
				</div>
				<?php foreach ( $compat['sitemap_conflicts'] as $conflict ) : ?>
				<div class="wppugmill-conflict-item">
					<strong><?php echo esc_html( $conflict['name'] ); ?>:</strong> <?php echo esc_html( $conflict['instruction'] ); ?>
					<?php if ( 'ai' === $mode && ! empty( $api_key ) ) : ?>
					<br><button type="button"
						class="wppugmill-compat-tips-btn"
						data-plugin="<?php echo esc_attr( $conflict['name'] ); ?>"
						data-instruction="<?php echo esc_attr( $conflict['instruction'] ); ?>"
						style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600; background:#7c3aed; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap; margin-top:6px;"
					>&#10024; <?php esc_html_e( 'Get steps', 'wp-pugmill' ); ?></button>
					<div class="wppugmill-compat-tips-result"></div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php else : ?>
			<p class="wppugmill-no-conflict">&#10003; <?php esc_html_e( 'WP Pugmill is the only sitemap generator active.', 'wp-pugmill' ); ?></p>
			<?php endif; ?>
		</div><!-- /sitemap row -->

		<!-- ── llms.txt ────────────────────────────────────────────── -->
		<div class="wppugmill-file-row">
			<h3>/llms.txt</h3>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'An index file for AI language models — listing your posts with titles, summaries, and direct links. WP Pugmill\'s version includes per-post AEO data (summaries, Q&A, entities) that generic llms.txt plugins don\'t generate.', 'wp-pugmill' ); ?></p>

			<div class="wppugmill-preview-card <?php echo ! empty( $compat['llms_txt_conflicts'] ) ? 'has-conflict' : ''; ?>">
				<div class="wppugmill-preview-card-header">
					<?php if ( empty( $compat['llms_txt_conflicts'] ) ) : ?>
					<span style="color:#46b450; font-weight:600; font-size:13px;">&#10003; WP Pugmill</span>
					<span style="font-size:12px; color:#666;"><?php esc_html_e( 'No conflicts detected', 'wp-pugmill' ); ?></span>
					<?php else : ?>
					<span style="font-weight:600; font-size:13px; color:#333;">WP Pugmill</span>
					<span style="font-size:12px; color:#888;"><?php esc_html_e( 'Preview', 'wp-pugmill' ); ?></span>
					<?php endif; ?>
				</div>
				<pre class="wppugmill-col-preview"><?php echo esc_html( wppugmill_preview_llms_txt_snippet() ); ?></pre>
			</div>

			<?php if ( ! empty( $compat['llms_txt_conflicts'] ) ) : ?>
			<div class="wppugmill-conflict-block">
				<div class="wppugmill-conflict-block-title">
					&#9888; <?php printf(
						esc_html( _n( '%d conflict detected', '%d conflicts detected', count( $compat['llms_txt_conflicts'] ), 'wp-pugmill' ) ),
						count( $compat['llms_txt_conflicts'] )
					); ?> &mdash; <?php esc_html_e( 'another plugin is also generating /llms.txt', 'wp-pugmill' ); ?>
				</div>
				<?php foreach ( $compat['llms_txt_conflicts'] as $conflict ) : ?>
				<div class="wppugmill-conflict-item">
					<strong><?php echo esc_html( $conflict['name'] ); ?>:</strong> <?php echo esc_html( $conflict['instruction'] ); ?>
					<?php if ( 'ai' === $mode && ! empty( $api_key ) ) : ?>
					<br><button type="button"
						class="wppugmill-compat-tips-btn"
						data-plugin="<?php echo esc_attr( $conflict['name'] ); ?>"
						data-instruction="<?php echo esc_attr( $conflict['instruction'] ); ?>"
						style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600; background:#7c3aed; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap; margin-top:6px;"
					>&#10024; <?php esc_html_e( 'Get steps', 'wp-pugmill' ); ?></button>
					<div class="wppugmill-compat-tips-result"></div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php else : ?>
			<p class="wppugmill-no-conflict">&#10003; <?php esc_html_e( 'No other llms.txt plugins detected.', 'wp-pugmill' ); ?></p>
			<?php endif; ?>
		</div><!-- /llms.txt row -->

		<!-- ── robots.txt ──────────────────────────────────────────── -->
		<div class="wppugmill-file-row" style="margin-bottom:0;">
			<h3>/robots.txt</h3>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'WP Pugmill appends two lines to your existing robots.txt: a Sitemap: directive pointing to /sitemap.xml, and an LLMs-Txt: directive pointing to /llms.txt. This is additive — it does not replace what\'s already there.', 'wp-pugmill' ); ?></p>

			<div class="wppugmill-preview-card <?php echo ! empty( $compat['robots_conflicts'] ) ? 'has-conflict' : ''; ?>" style="margin-top:12px;">
				<div class="wppugmill-preview-card-header">
					<span style="font-weight:600; font-size:13px; color:#333;"><?php esc_html_e( 'WP Pugmill additions', 'wp-pugmill' ); ?></span>
				</div>
				<pre class="wppugmill-col-preview" id="wppugmill-live-robots"><?php esc_html_e( 'Loading live robots.txt…', 'wp-pugmill' ); ?></pre>
			</div>

			<?php if ( ! empty( $compat['robots_conflicts'] ) ) : ?>
			<div class="wppugmill-conflict-block">
				<div class="wppugmill-conflict-block-title">
					&#9888; <?php esc_html_e( 'Potential duplicate directives in robots.txt', 'wp-pugmill' ); ?>
				</div>
				<?php foreach ( $compat['robots_conflicts'] as $conflict ) : ?>
				<div class="wppugmill-conflict-item">
					<strong><?php echo esc_html( $conflict['name'] ); ?>:</strong> <?php echo esc_html( $conflict['instruction'] ); ?>
					<?php if ( 'ai' === $mode && ! empty( $api_key ) ) : ?>
					<br><button type="button"
						class="wppugmill-compat-tips-btn"
						data-plugin="<?php echo esc_attr( $conflict['name'] ); ?>"
						data-instruction="<?php echo esc_attr( $conflict['instruction'] ); ?>"
						style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600; background:#7c3aed; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap; margin-top:6px;"
					>&#10024; <?php esc_html_e( 'Get steps', 'wp-pugmill' ); ?></button>
					<div class="wppugmill-compat-tips-result"></div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<label style="display:block; margin-top:12px; font-size:13px;">
				<input type="hidden" name="wppugmill_disable_robots_append" value="0">
				<input type="checkbox" name="wppugmill_disable_robots_append" value="1" <?php checked( 1, $disable_robots ); ?>>
				<?php esc_html_e( 'Disable WP Pugmill robots.txt additions', 'wp-pugmill' ); ?>
			</label>
			<p style="<?php echo esc_attr( $p_style ); ?> margin:4px 0 0 20px; font-size:12px;"><?php esc_html_e( 'Check this if another plugin is already adding Sitemap: directives and you want to avoid duplicates.', 'wp-pugmill' ); ?></p>
		</div><!-- /robots.txt row -->

		</div><!-- /output files card -->

		<!-- ── STRUCTURED DATA & ON-PAGE SEO ─────────────────────── -->
		<?php if ( ! empty( $compat['json_ld_conflicts'] ) ) : ?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:20px;">
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:0;"><?php esc_html_e( 'Structured Data &amp; On-Page SEO', 'wp-pugmill' ); ?></h2>
		<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'These plugins overlap with WP Pugmill\'s &lt;head&gt; output. Your AEO metadata is always saved regardless of which plugin renders the front-end tags.', 'wp-pugmill' ); ?></p>

		<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:16px;">
			<strong><?php esc_html_e( '⚠ Duplicate structured data risk', 'wp-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
			<?php printf(
				esc_html__( '%s is also outputting JSON-LD structured data. Running multiple structured data plugins on the same page can produce duplicate schema warnings in Google Search Console.', 'wp-pugmill' ),
				esc_html( implode( ', ', $compat['json_ld_conflicts'] ) )
			); ?>
			</span>
		</div>
		<label style="display:block; margin-bottom:4px;">
			<input type="hidden" name="wppugmill_disable_json_ld" value="0">
			<input type="checkbox" name="wppugmill_disable_json_ld" value="1" <?php checked( 1, $disable_json_ld ); ?>>
			<?php printf(
				esc_html__( 'Disable WP Pugmill JSON-LD output (defer to %s)', 'wp-pugmill' ),
				esc_html( implode( ' / ', $compat['json_ld_conflicts'] ) )
			); ?>
		</label>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-bottom:16px;"><?php esc_html_e( 'Your AEO metadata (summary, Q&A, entities) will still be saved and used by AI generation tools — only the &lt;head&gt; schema output is disabled.', 'wp-pugmill' ); ?></p>

		<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:12px;">
			<strong><?php esc_html_e( '⚠ On-page SEO conflict', 'wp-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
			<?php printf(
				esc_html__( '%s is active and likely managing title tags, meta descriptions, and canonical URLs. Enabling WP Pugmill\'s on-page SEO output alongside it may produce duplicate tags.', 'wp-pugmill' ),
				esc_html( implode( ', ', $compat['json_ld_conflicts'] ) )
			); ?>
			</span>
		</div>
		<label style="display:block; margin-bottom:4px;">
			<input type="hidden" name="wppugmill_disable_seo_meta" value="0">
			<input type="checkbox" name="wppugmill_disable_seo_meta" value="1" <?php checked( 1, $disable_seo_meta ); ?>>
			<?php printf(
				esc_html__( 'Disable WP Pugmill title/meta/canonical output (defer to %s)', 'wp-pugmill' ),
				esc_html( implode( ' / ', $compat['json_ld_conflicts'] ) )
			); ?>
		</label>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-bottom:0;"><?php esc_html_e( 'SEO field values you enter in the editor will still be saved — only the &lt;head&gt; output is suppressed.', 'wp-pugmill' ); ?></p>
		</div><!-- /structured data card -->
		<?php endif; ?>

		<!-- ── CRAWLER ACCESS ────────────────────────────────────── -->
		<?php if ( $compat['robots']['discourage'] || $compat['robots']['blocks_all'] || ! empty( $compat['robots']['blocked_bots'] ) ) : ?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:20px;">
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:0;"><?php esc_html_e( 'Crawler Access', 'wp-pugmill' ); ?></h2>
		<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'AI answer engines (ChatGPT, Perplexity, Claude) use web crawlers to index content. Unlike older SEO bots, these are worth allowing — they cite and surface your content in AI-generated answers.', 'wp-pugmill' ); ?></p>

		<?php if ( $compat['robots']['discourage'] ) : ?>
		<div style="background:#fcf0f1; border-left:4px solid #dc3232; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:12px;">
			<strong><?php esc_html_e( '✗ Search engines are discouraged site-wide', 'wp-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
				<?php esc_html_e( 'WordPress Settings → Reading has "Discourage search engines" enabled. This outputs Disallow: / for all crawlers — including AI answer engines — blocking your content from AEO indexing entirely.', 'wp-pugmill' ); ?>
			</span><br><br>
			<a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Fix in Reading Settings →', 'wp-pugmill' ); ?></a>
		</div>
		<?php endif; ?>
		<?php if ( $compat['robots']['blocks_all'] ) : ?>
		<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:12px;">
			<strong><?php esc_html_e( '⚠ robots.txt blocks all crawlers (Disallow: /)', 'wp-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
				<?php esc_html_e( 'Your robots.txt has a wildcard User-agent: * rule with Disallow: /. This blocks all web crawlers including AI answer engines. Consider replacing it with specific rules that allow GPTBot, ClaudeBot, PerplexityBot, and Google-Extended.', 'wp-pugmill' ); ?>
			</span>
		</div>
		<?php endif; ?>
		<?php if ( ! empty( $compat['robots']['blocked_bots'] ) ) : ?>
		<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; border-radius:0 4px 4px 0; margin-bottom:12px;">
			<strong><?php esc_html_e( '⚠ AI crawlers blocked in robots.txt', 'wp-pugmill' ); ?></strong><br>
			<span style="color:#555; font-size:13px;">
			<?php printf(
				esc_html__( 'The following AI crawlers are explicitly blocked: %s. Remove or adjust these Disallow rules to improve AEO discoverability.', 'wp-pugmill' ),
				'<strong>' . esc_html( implode( ', ', $compat['robots']['blocked_bots'] ) ) . '</strong>'
			); ?>
			</span>
		</div>
		<?php endif; ?>
		</div><!-- /crawler access card -->
		<?php endif; ?>

		<?php submit_button( __( 'Save Changes', 'wp-pugmill' ) ); ?>
		</form>

		<script>
		(function () {
			// Load live robots.txt into the preview panel.
			var el = document.getElementById( 'wppugmill-live-robots' );
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

		<?php if ( 'ai' === $mode && ! empty( $api_key ) ) : ?>
		// ── Compat tips buttons ──────────────────────────────────────
		(function () {
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wppugmill_compat_tips' ) ); ?>;
			document.querySelectorAll( '.wppugmill-compat-tips-btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var resultEl = btn.nextElementSibling;
					var originalHTML = btn.innerHTML;
					btn.disabled = true;
					btn.classList.add( 'wppugmill-loading' );
					btn.innerHTML = '<?php echo esc_js( __( 'Getting steps…', 'wp-pugmill' ) ); ?>';
					resultEl.style.display = 'none';
					var body = new FormData();
					body.append( 'action', 'wppugmill_compat_tips' );
					body.append( 'nonce', nonce );
					body.append( 'plugin_name', btn.dataset.plugin );
					body.append( 'instruction', btn.dataset.instruction );
					fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( data ) {
							if ( data.success ) {
								resultEl.textContent = data.data.steps;
							} else {
								resultEl.textContent = ( data.data && data.data.message ) ? data.data.message : 'An error occurred.';
							}
							resultEl.style.display = 'block';
						} )
						.catch( function () {
							resultEl.textContent = 'Request failed.';
							resultEl.style.display = 'block';
						} )
						.finally( function () {
							btn.classList.remove( 'wppugmill-loading' );
							btn.disabled = false;
							btn.innerHTML = originalHTML;
						} );
				} );
			} );
		}());
		<?php endif; ?>
		</script>

		<!-- ── Import from Another SEO Plugin ───────────────────────── -->
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:32px;"><?php esc_html_e( 'Import from Another SEO Plugin', 'wp-pugmill' ); ?></h2>
		<p style="<?php echo esc_attr( $p_style ); ?>">
			<?php esc_html_e( 'WP Pugmill can import titles, meta descriptions, canonical URLs, robots settings, and OG fields from Yoast, Rank Math, All in One SEO, and SEOPress. Posts that already have WP Pugmill data are skipped by default.', 'wp-pugmill' ); ?>
		</p>

		<?php
		$migration_sources = wppugmill_migration_sources();
		if ( empty( $migration_sources ) ) :
		?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px;">
			<p style="color:#46b450; margin:0;">&#10003; <?php esc_html_e( 'No importable data found from Yoast, Rank Math, AIOSEO, or SEOPress.', 'wp-pugmill' ); ?></p>
		</div>
		<?php else : ?>

		<div id="wppugmill-migration-wrap" style="max-width:700px;">
			<?php foreach ( $migration_sources as $source_key => $source ) : ?>
			<div class="wppugmill-migration-card"
				id="wppugmill-card-<?php echo esc_attr( $source_key ); ?>"
				style="background:#fff; border:1px solid #ddd; border-radius:6px; padding:16px 20px; margin-bottom:14px;">

				<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
					<div>
						<strong style="font-size:14px;"><?php echo esc_html( $source['label'] ); ?></strong>
						<span style="color:#666; font-size:13px; margin-left:8px;">
							<?php printf(
								esc_html( _n( '%d post with SEO data', '%d posts with SEO data', $source['count'], 'wp-pugmill' ) ),
								(int) $source['count']
							); ?>
						</span>
						<?php if ( ! $source['active'] ) : ?>
						<span style="background:#f0f0f1; color:#666; font-size:11px; padding:2px 7px; border-radius:3px; margin-left:6px;"><?php esc_html_e( 'Inactive', 'wp-pugmill' ); ?></span>
						<?php endif; ?>
					</div>
					<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
						<label style="font-size:12px; color:#555; display:flex; align-items:center; gap:4px;">
							<input type="checkbox"
								class="wppugmill-migration-overwrite"
								data-source="<?php echo esc_attr( $source_key ); ?>"
								style="margin:0;">
							<?php esc_html_e( 'Overwrite existing', 'wp-pugmill' ); ?>
						</label>
						<button type="button"
							class="button button-primary wppugmill-migration-btn"
							data-source="<?php echo esc_attr( $source_key ); ?>"
							data-total="<?php echo esc_attr( $source['count'] ); ?>">
							<?php esc_html_e( 'Import', 'wp-pugmill' ); ?>
						</button>
					</div>
				</div>

				<div class="wppugmill-migration-progress" id="wppugmill-progress-<?php echo esc_attr( $source_key ); ?>" style="display:none; margin-top:14px;">
					<div style="background:#f0f0f1; border-radius:4px; height:8px; overflow:hidden; margin-bottom:8px;">
						<div class="wppugmill-progress-bar"
							style="background:#2271b1; height:100%; width:0%; border-radius:4px; transition:width 0.3s ease;"></div>
					</div>
					<div class="wppugmill-progress-label" style="font-size:12px; color:#555;"></div>
				</div>

			</div>
			<?php endforeach; ?>
		</div><!-- #wppugmill-migration-wrap -->

		<script>
		(function() {
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wppugmill_migration' ) ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			function runBatch( source, offset, overwrite, total, migrated, skipped ) {
				var card     = document.getElementById( 'wppugmill-card-' + source );
				var progress = document.getElementById( 'wppugmill-progress-' + source );
				var bar      = progress.querySelector( '.wppugmill-progress-bar' );
				var label    = progress.querySelector( '.wppugmill-progress-label' );
				var btn      = card.querySelector( '.wppugmill-migration-btn' );

				progress.style.display = 'block';
				btn.disabled = true;

				var pct = total > 0 ? Math.min( 100, Math.round( ( offset / total ) * 100 ) ) : 0;
				bar.style.width = pct + '%';
				label.textContent = offset + ' / ' + total + ' processed\u2026';

				var body = new URLSearchParams();
				body.append( 'action',    'wppugmill_run_migration' );
				body.append( 'nonce',     nonce );
				body.append( 'source',    source );
				body.append( 'offset',    offset );
				body.append( 'overwrite', overwrite ? '1' : '0' );

				fetch( ajaxUrl, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        body.toString(),
				} )
				.then( function( r ) { return r.json(); } )
				.then( function( res ) {
					if ( ! res.success ) {
						label.textContent = '\u2717 Error: ' + ( res.data && res.data.message ? res.data.message : 'Unknown error.' );
						btn.disabled = false;
						return;
					}
					var d = res.data;
					migrated += d.migrated;
					skipped  += d.skipped;
					var newOffset = offset + d.processed;
					if ( d.done ) {
						bar.style.width      = '100%';
						bar.style.background = '#46b450';
						label.style.color    = '#46b450';
						label.textContent    = '\u2713 Done \u2014 ' + migrated + ' imported, ' + skipped + ' skipped.';
						btn.disabled         = false;
						btn.textContent      = 'Re-import';
					} else {
						runBatch( source, newOffset, overwrite, total, migrated, skipped );
					}
				} )
				.catch( function() {
					label.textContent = '\u2717 Network error. Please try again.';
					btn.disabled = false;
				} );
			}

			document.addEventListener( 'click', function( e ) {
				if ( ! e.target.classList.contains( 'wppugmill-migration-btn' ) ) { return; }
				var btn       = e.target;
				var source    = btn.getAttribute( 'data-source' );
				var total     = parseInt( btn.getAttribute( 'data-total' ), 10 ) || 0;
				var card      = document.getElementById( 'wppugmill-card-' + source );
				var overwrite = card.querySelector( '.wppugmill-migration-overwrite' ).checked;
				runBatch( source, 0, overwrite, total, 0, 0 );
			} );
		}());
		</script>
		<?php endif; ?>

		<?php elseif ( 'sitemap' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     SITEMAP & ROBOTS TAB
		     ════════════════════════════════════════════════════════════ -->
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:24px;">
			<?php esc_html_e( 'WP Pugmill automatically generates an XML sitemap and manages your robots.txt. The sitemap lists all public published posts and pages so search engines and AI crawlers can discover your content. The robots.txt controls which crawlers are allowed in — AI answer engines like ChatGPT, Perplexity, and Gemini use their own bots, and allowing them is key to AEO discoverability.', 'wp-pugmill' ); ?>
		</p>
		<style>
		@media (max-width:900px) {
			.wppugmill-sitemap-grid { grid-template-columns: 1fr !important; }
		}
		</style>
		<form method="post" action="options.php" style="margin-top:20px;">
			<?php settings_fields( 'wppugmill_settings' ); ?>
			<div class="wppugmill-sitemap-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start;">

			<!-- LEFT: XML Sitemap -->
			<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px;">
				<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:0 0 12px;"><?php esc_html_e( 'XML Sitemap', 'wp-pugmill' ); ?></p>
				<?php $sitemap_url = home_url( '/sitemap.xml' ); ?>
				<p style="margin:0 0 10px;">
					<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" class="button button-secondary">
						<?php esc_html_e( 'View sitemap.xml →', 'wp-pugmill' ); ?>
					</a>
				</p>
				<p style="font-size:13px; color:#555; line-height:1.6; margin:0;">
					<?php esc_html_e( 'WP Pugmill automatically generates /sitemap.xml covering all public, published posts and pages. noindex posts are excluded. Search engines are pinged on publish.', 'wp-pugmill' ); ?>
				</p>
			</div>

			<!-- RIGHT: Robots.txt -->
			<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px;">
				<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:0 0 12px;"><?php esc_html_e( 'Custom Robots.txt', 'wp-pugmill' ); ?></p>
				<p style="font-size:13px; color:#555; line-height:1.6; margin:0 0 10px;"><?php esc_html_e( 'Override WordPress\'s virtual robots.txt with your own content. WP Pugmill appends a Sitemap: directive automatically. Leave blank to use WordPress defaults.', 'wp-pugmill' ); ?></p>
				<textarea
					name="wppugmill_robots_txt_custom"
					id="wppugmill_robots_txt_custom"
					rows="10"
					style="width:100%; font-family:monospace; font-size:12px; box-sizing:border-box;"
					placeholder="User-agent: *&#10;Disallow:&#10;&#10;Sitemap: <?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>"
				><?php echo esc_textarea( get_option( 'wppugmill_robots_txt_custom', '' ) ); ?></textarea>
				<p class="description" style="margin-top:6px;">
					<?php printf(
						wp_kses( __( 'Live robots.txt: <a href="%s" target="_blank">%s</a>', 'wp-pugmill' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
						esc_url( home_url( '/robots.txt' ) ),
						esc_html( home_url( '/robots.txt' ) )
					); ?>
				</p>
			</div>

			</div><!-- /grid -->
			<?php submit_button(); ?>
		</form>

		<?php elseif ( 'analytics' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     BOT ANALYTICS TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php if ( ! get_option( 'wppugmill_analytics_opted_in' ) ) : ?>
		<div style="max-width:600px; margin:40px auto; padding:32px; background:#fff; border:1px solid #ddd; border-radius:8px; text-align:center;">
			<div style="font-size:32px; margin-bottom:16px;">📡</div>
			<h2 style="margin:0 0 12px; font-size:20px;"><?php esc_html_e( 'Activate Bot Analytics', 'wp-pugmill' ); ?></h2>
			<p style="color:#555; font-size:14px; line-height:1.7; margin:0 0 20px;">
				<?php esc_html_e( 'See exactly which AI crawlers and search spiders are visiting your site, which pages they read, and whether they\'re engaging with your AEO content.', 'wp-pugmill' ); ?>
			</p>
			<p style="color:#555; font-size:14px; line-height:1.7; margin:0 0 24px;">
				<?php esc_html_e( 'By opting in, you also join the Pugmill Intelligence network — we watch which bots visit, which of your AEO resources they accessed, how many times, and the date. Those counts are shared with network participants. Your site is identified only by a salted private hash that cannot be traced back to your domain — not even by us. No URLs, no content, no visitor data is ever collected.', 'wp-pugmill' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'wppugmill_analytics' ); ?>
				<input type="hidden" name="wppugmill_analytics_opted_in" value="1">
				<?php submit_button( __( 'Activate Analytics + Join Network', 'wp-pugmill' ), 'primary', 'submit', false, array( 'style' => 'font-size:15px; height:40px; padding:0 24px;' ) ); ?>
			</form>
			<p style="color:#aaa; font-size:12px; margin:16px 0 0;">
				<?php esc_html_e( 'You can opt out at any time from this tab. Your historical data stays on your site.', 'wp-pugmill' ); ?>
			</p>
		</div>
		<?php else : ?>
		<?php
		$days            = 30;
		$summary         = wppugmill_bot_analytics_summary( $days );
		$daily           = wppugmill_bot_analytics_daily( $days );
		$recent          = wppugmill_bot_analytics_recent( 50 );
		$total           = wppugmill_bot_analytics_total();
		$by_resource     = wppugmill_bot_analytics_by_resource( $days );
		$resource_labels = wppugmill_resource_type_labels();
		$resource_cats   = wppugmill_resource_type_categories();
		$all_bots        = wppugmill_bot_config();
		$top_posts       = wppugmill_bot_analytics_top_posts( 10 );
		$intel_signals   = function_exists( 'wppugmill_intel_get_signals_30d' ) ? wppugmill_intel_get_signals_30d( $days ) : array();

		// Only render bots that have actual visits in the selected period —
		// no zero rows. Unknown bots (bot_id = 0) are added as 'Other'.
		$bots = array_filter( $all_bots, function( $b, $k ) use ( $summary ) {
			return isset( $summary[ $k ] ) && (int) $summary[ $k ] > 0;
		}, ARRAY_FILTER_USE_BOTH );
		// Add 'Other' entry if any unknown bots were logged.
		if ( isset( $summary['Unknown'] ) && (int) $summary['Unknown'] > 0 ) {
			$bots['Unknown'] = array( 'label' => 'Other Bots', 'color' => '#94a3b8', 'type' => 'other', 'category' => 'other' );
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
		$insights_nonce  = wp_create_nonce( 'wppugmill_analytics_insights' );
		$export_nonce    = wp_create_nonce( 'wppugmill_export_csv' );
		$cached_insights = get_transient( 'wppugmill_ai_analytics_insights' );
		$has_api_key     = ! empty( wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' ) );

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

		if ( get_option( 'wppugmill_analytics_opted_in' ) ) {
			$net_response = wp_remote_get( 'https://pugmill.dev/api/report', array( 'timeout' => 5, 'sslverify' => true ) );
			if ( ! is_wp_error( $net_response ) ) {
				$net_data      = json_decode( wp_remote_retrieve_body( $net_response ), true ) ?: array();
				$network_sites = (int) ( $net_data['sites_contributing'] ?? 0 );
				if ( $network_sites >= 1 && ! empty( $net_data['last_30_days'] ) ) {
					foreach ( $net_data['last_30_days'] as $bot => $resources ) {
						$network_avgs[ $bot ] = (int) round( array_sum( $resources ) / $network_sites );
						foreach ( $resources as $slug => $total ) {
							if ( isset( $network_slug_to_type[ $slug ] ) ) {
								$type_id = $network_slug_to_type[ $slug ];
								$network_resource_avgs[ $bot ][ $type_id ] = round( (float) $total / $network_sites, 1 );
							}
						}
					}
				}
				// Category-level network trends (new in v1.0.15)
				if ( ! empty( $net_data['categories'] ) ) {
					$network_categories = $net_data['categories'];
				}
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
		<div style="background:#faf7ff; border:1px solid #d4c8f0; border-radius:8px; padding:20px 24px; margin:24px 0 0;">
			<div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap;">
				<div>
					<h3 style="margin:0 0 4px; font-size:14px; font-weight:600; color:#1d2327;">
						<?php esc_html_e( 'AI Insights', 'wp-pugmill' ); ?>
					</h3>
					<p style="margin:0; font-size:12px; color:#6b7280;">
						<?php esc_html_e( 'Send your bot traffic data to your configured AI provider for analysis and recommendations.', 'wp-pugmill' ); ?>
					</p>
				</div>
				<?php if ( $has_api_key ) : ?>
				<button id="wppugmill-insights-btn" type="button"
					style="display:inline-flex; align-items:center; gap:6px; padding:7px 16px; font-size:12px; font-weight:600;
					       background:#7c3aed; color:#fff; border:none; border-radius:4px; cursor:pointer; white-space:nowrap; flex-shrink:0;">
					✨ <?php echo $cached_insights ? esc_html__( 'Refresh Analysis', 'wp-pugmill' ) : esc_html__( 'Get AI Analysis', 'wp-pugmill' ); ?>
				</button>
				<?php else : ?>
				<p style="font-size:12px; color:#9ca3af; margin:0; flex-shrink:0;">
					<?php esc_html_e( 'Configure an API key to enable AI insights.', 'wp-pugmill' ); ?>
				</p>
				<?php endif; ?>
			</div>

			<?php if ( $cached_insights ) : ?>
			<div id="wppugmill-insights-output" style="margin-top:16px; padding-top:16px; border-top:1px solid #e8e0f7;">
				<div id="wppugmill-insights-text" style="font-size:14px; color:#374151; line-height:1.7;">
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
					printf( esc_html__( 'Generated %s ago', 'wp-pugmill' ), human_time_diff( $cached_insights['generated'] ) );
					?>
					&nbsp;·&nbsp;
					<span id="wppugmill-insights-status"></span>
				</p>
			</div>
			<?php else : ?>
			<div id="wppugmill-insights-output" style="display:none; margin-top:16px; padding-top:16px; border-top:1px solid #e8e0f7;">
				<div id="wppugmill-insights-text" style="font-size:14px; color:#374151; line-height:1.7;"></div>
				<p style="font-size:11px; color:#9ca3af; margin:8px 0 0;">
					<span id="wppugmill-insights-status"></span>
				</p>
			</div>
			<?php endif; ?>
		</div>

		<!-- ── 2×2 Bot Quadrants ──────────────────────────────────────────── -->
		<?php
		$quadrant_defs = array(
			'ai'       => array(
				'label'     => __( 'AI Crawlers', 'wp-pugmill' ),
				'desc'      => __( 'Answer engines like ChatGPT and Perplexity that read your content to respond to user queries in real time.', 'wp-pugmill' ),
				'bots'      => $ai_bots,
				'total'     => $ai_total_30,
				'accent'    => '#7c3aed',
				'bg'        => '#faf7ff',
				'border'    => '#d4c8f0',
				'empty_msg' => __( 'No AI crawler visits detected yet.', 'wp-pugmill' ),
			),
			'search'   => array(
				'label'     => __( 'Search Engines', 'wp-pugmill' ),
				'desc'      => __( 'Traditional search spiders indexing your content for results pages on Google, Bing, and others.', 'wp-pugmill' ),
				'bots'      => $search_bots,
				'total'     => $search_total_30,
				'accent'    => '#0369a1',
				'bg'        => '#f0f9ff',
				'border'    => '#bae0fd',
				'empty_msg' => __( 'No search engine visits detected yet.', 'wp-pugmill' ),
			),
			'seo'      => array(
				'label'     => __( 'SEO Bots', 'wp-pugmill' ),
				'desc'      => __( 'Commercial tools like Semrush and Ahrefs auditing backlinks, rankings, and site health — not related to AI or search indexing.', 'wp-pugmill' ),
				'bots'      => $seo_bots,
				'total'     => $seo_total_30,
				'accent'    => '#374151',
				'bg'        => '#f9fafb',
				'border'    => '#e5e7eb',
				'empty_msg' => __( 'No SEO bot visits detected yet.', 'wp-pugmill' ),
			),
			'training' => array(
				'label'     => __( 'Training Crawlers', 'wp-pugmill' ),
				'desc'      => __( 'Bots collecting data to train foundation models. Not tied to live user queries — your content may end up in a future AI\'s knowledge base.', 'wp-pugmill' ),
				'bots'      => $training_bots,
				'total'     => $training_total_30,
				'accent'    => '#0891b2',
				'bg'        => '#f0fdff',
				'border'    => '#a5f3fc',
				'empty_msg' => __( 'No training crawler visits detected yet.', 'wp-pugmill' ),
			),
		);
		?>
		<style>
		.wppugmill-quadrant-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 16px;
			margin: 24px 0 4px;
		}
		@media (max-width: 782px) {
			.wppugmill-quadrant-grid { grid-template-columns: 1fr; }
		}
		</style>
		<div class="wppugmill-quadrant-grid">
		<?php foreach ( $quadrant_defs as $cat_key => $q ) :

			// Sort bots by local visit count descending
			$q_sorted = array();
			foreach ( $q['bots'] as $bot_key => $_ ) {
				$q_sorted[ $bot_key ] = (int) ( $summary[ $bot_key ] ?? 0 );
			}
			arsort( $q_sorted );

			// Scale max: highest of (local count, network avg) across all bots in quadrant
			$q_max = 1;
			foreach ( $q_sorted as $bot_key => $cnt ) {
				$q_max = max( $q_max, $cnt, (int) ( $network_avgs[ $bot_key ] ?? 0 ) );
			}

			// Network trend for this quadrant
			$nc        = $network_categories[ $cat_key ] ?? null;
			$nc_change = ( $nc && isset( $nc['change_pct'] ) && null !== $nc['change_pct'] ) ? (int) $nc['change_pct'] : null;
			$nc_arrow  = '';
			$nc_col    = '#9ca3af';
			if ( null !== $nc_change ) {
				if ( $nc_change > 0 )     { $nc_arrow = '&#8593;'; $nc_col = '#16a34a'; }
				elseif ( $nc_change < 0 ) { $nc_arrow = '&#8595;'; $nc_col = '#dc2626'; }
				else                      { $nc_arrow = '&#8212;'; $nc_col = '#9ca3af'; }
			}
		?>
		<div style="background:<?php echo esc_attr( $q['bg'] ); ?>; border:1px solid <?php echo esc_attr( $q['border'] ); ?>; border-radius:8px; padding:18px 20px;">

			<!-- Quadrant header -->
			<div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:<?php echo empty( $q_sorted ) ? '8' : '14'; ?>px;">
				<div>
					<div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:<?php echo esc_attr( $q['accent'] ); ?>; margin-bottom:2px;">
						<?php echo esc_html( $q['label'] ); ?>
					</div>
					<div style="font-size:26px; font-weight:700; color:<?php echo esc_attr( $q['accent'] ); ?>; line-height:1.1;">
						<?php echo esc_html( number_format_i18n( $q['total'] ) ); ?>
						<span style="font-size:13px; font-weight:400; color:#6b7280;"><?php esc_html_e( 'Visits', 'wp-pugmill' ); ?></span>
					</div>
					<div style="font-size:11px; color:#6b7280; line-height:1.5; margin-top:4px;">
						<?php echo esc_html( $q['desc'] ); ?>
					</div>
				</div>
				<?php if ( null !== $nc_change ) : ?>
				<div style="text-align:right; font-size:12px; font-weight:600; color:<?php echo esc_attr( $nc_col ); ?>; padding-top:2px; flex-shrink:0;">
					<?php echo wp_kses_post( $nc_arrow ); ?>&nbsp;<?php echo esc_html( abs( $nc_change ) ); ?>%
					<div style="font-size:10px; font-weight:400; color:#9ca3af;"><?php esc_html_e( 'network', 'wp-pugmill' ); ?></div>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( empty( $q_sorted ) ) : ?>
			<p style="font-size:12px; color:#9ca3af; margin:0; padding-bottom:4px;">
				<?php echo esc_html( $q['empty_msg'] ); ?>
			</p>
			<?php else : ?>
			<?php foreach ( $q_sorted as $bot_key => $count ) :
				$bot_info = $q['bots'][ $bot_key ];
				$net_avg  = isset( $network_avgs[ $bot_key ] ) ? (int) $network_avgs[ $bot_key ] : null;
				$my_pct   = (int) round( $count / $q_max * 100 );
				$avg_pct  = ( null !== $net_avg ) ? (int) round( $net_avg / $q_max * 100 ) : 0;
			?>
			<div style="margin-bottom:10px;">
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:3px;">
					<span style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#374151; min-width:0; overflow:hidden;">
						<span style="width:8px; height:8px; border-radius:50%; background:<?php echo esc_attr( $bot_info['color'] ); ?>; flex-shrink:0;"></span>
						<span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html( $bot_info['label'] ); ?></span>
					</span>
					<span style="font-size:13px; font-weight:700; color:<?php echo esc_attr( $bot_info['color'] ); ?>; flex-shrink:0; margin-left:8px;">
						<?php echo esc_html( number_format_i18n( $count ) ); ?>
					</span>
				</div>
				<!-- You bar -->
				<div style="display:flex; align-items:center; gap:5px; margin-bottom:2px;">
					<span style="font-size:9px; color:#9ca3af; width:18px; flex-shrink:0;"><?php esc_html_e( 'you', 'wp-pugmill' ); ?></span>
					<div style="flex:1; background:#e5e7eb; border-radius:2px; height:6px; overflow:hidden;">
						<div style="width:<?php echo esc_attr( $my_pct ); ?>%; height:100%; background:<?php echo esc_attr( $bot_info['color'] ); ?>; border-radius:2px;"></div>
					</div>
				</div>
				<?php if ( null !== $net_avg ) : ?>
				<!-- Avg bar -->
				<div style="display:flex; align-items:center; gap:5px;">
					<span style="font-size:9px; color:#7c3aed; width:18px; flex-shrink:0;"><?php esc_html_e( 'avg', 'wp-pugmill' ); ?></span>
					<div style="flex:1; background:#e5e7eb; border-radius:2px; height:6px; overflow:hidden;">
						<div style="width:<?php echo esc_attr( $avg_pct ); ?>%; height:100%; background:#7c3aed; border-radius:2px;"></div>
					</div>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>

		</div>
		<?php endforeach; ?>
		</div>

		<!-- Legend -->
		<p style="font-size:11px; color:#9ca3af; margin:4px 0 24px;">
			<?php esc_html_e( 'Last 30 days', 'wp-pugmill' ); ?>
			<?php if ( ! empty( $network_avgs ) ) : ?>
			&nbsp;&middot;&nbsp; <?php esc_html_e( 'Thin purple bar = network average', 'wp-pugmill' ); ?>
			<?php endif; ?>
			<?php if ( $network_sites > 0 ) :
				printf(
					' &middot; ' . esc_html( _n( 'Network: %d site', 'Network: %d sites', $network_sites, 'wp-pugmill' ) ),
					(int) $network_sites
				);
			endif; ?>
		</p>

		<!-- 30-day trend chart -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:24px;">
			<h3 style="margin:0 0 16px; font-size:14px; font-weight:600;">
				<?php esc_html_e( 'Last 30 Days', 'wp-pugmill' ); ?>
			</h3>

			<?php if ( 0 === $total ) : ?>
			<p style="color:#9ca3af; font-size:13px; text-align:center; padding:40px 0;">
				<?php esc_html_e( 'No AI bot visits recorded yet. Visits will appear here as AI crawlers discover your site.', 'wp-pugmill' ); ?>
			</p>
			<?php else : ?>

			<canvas id="wppugmill-bot-chart" width="860" height="220"
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

		<!-- Content Reach ───────────────────────────────────────────────────── -->
		<?php
		// Build active_types from by_resource data (which columns have any visits)
		$active_types = array();
		foreach ( $by_resource as $bot_res ) {
			foreach ( $bot_res as $type_id => $cnt ) {
				if ( $cnt > 0 ) {
					$active_types[ $type_id ] = true;
				}
			}
		}
		ksort( $active_types );

		// Category label + colour maps (shared by Content Reach and legacy code)
		$cat_labels = array( 'aeo' => 'AEO Endpoints', 'discovery' => 'Discovery', 'crawl' => 'Page Crawls' );
		$cat_badge  = array( 'aeo' => '#16a34a',        'discovery' => '#2563eb',   'crawl' => '#9ca3af' );

		// Column order: AEO group first, then Discovery, then Page Crawls
		$col_order_by_cat = array(
			'aeo'       => array( 1, 2, 3, 4 ),
			'discovery' => array( 5, 6 ),
			'crawl'     => array( 0 ),
		);

		// Filter to columns that have actual data
		$active_cols_by_cat = array();
		foreach ( $col_order_by_cat as $cat => $type_ids ) {
			foreach ( $type_ids as $type_id ) {
				if ( isset( $active_types[ $type_id ] ) ) {
					$active_cols_by_cat[ $cat ][] = $type_id;
				}
			}
		}

		// Flat ordered list of active columns
		$active_cols_ordered = array();
		foreach ( $active_cols_by_cat as $cat => $cols ) {
			foreach ( $cols as $type_id ) {
				$active_cols_ordered[] = $type_id;
			}
		}
		?>
		<?php if ( ! empty( $active_cols_ordered ) ) : ?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:24px;">
			<h3 style="margin:0 0 4px; font-size:14px; font-weight:600;">
				<?php esc_html_e( 'Content Reach', 'wp-pugmill' ); ?>
			</h3>
			<p style="margin:0 0 16px; font-size:12px; color:#666;">
				<?php esc_html_e( 'Which content types each bot is consuming — last 30 days. AEO endpoints show bots reading your optimised content directly.', 'wp-pugmill' ); ?>
			</p>
			<div style="overflow-x:auto;">
			<table class="widefat" style="font-size:12px; border-collapse:collapse;">
				<thead>
					<!-- Group header row -->
					<tr style="background:#f6f7f7;">
						<th style="padding:8px 12px; text-align:left; font-weight:600; white-space:nowrap; width:160px; border-bottom:1px solid #e5e7eb; border-right:2px solid #e5e7eb;" rowspan="2">
							<?php esc_html_e( 'Bot', 'wp-pugmill' ); ?>
						</th>
						<?php foreach ( $active_cols_by_cat as $cat => $cols ) :
							if ( empty( $cols ) ) continue;
						?>
						<th colspan="<?php echo count( $cols ); ?>"
							style="padding:6px 12px; text-align:center; font-size:10px; font-weight:700;
							       text-transform:uppercase; letter-spacing:.06em;
							       color:<?php echo esc_attr( $cat_badge[ $cat ] ); ?>;
							       border-bottom:1px solid #e5e7eb;">
							<?php echo esc_html( $cat_labels[ $cat ] ); ?>
						</th>
						<?php endforeach; ?>
						<th style="padding:8px 12px; text-align:center; font-weight:600; white-space:nowrap; border-left:2px solid #e5e7eb; border-bottom:1px solid #e5e7eb;" rowspan="2">
							<?php esc_html_e( 'Total', 'wp-pugmill' ); ?>
						</th>
					</tr>
					<!-- Column name row -->
					<tr style="background:#f6f7f7;">
						<?php foreach ( $active_cols_ordered as $type_id ) : ?>
						<th style="padding:6px 12px; text-align:center; font-weight:500; white-space:nowrap; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb;">
							<?php echo esc_html( $resource_labels[ $type_id ] ?? '' ); ?>
						</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $bots as $bot_key => $bot_info ) :
					// Row total across all active columns
					$row_total = 0;
					foreach ( $active_cols_ordered as $type_id ) {
						$row_total += (int) ( $by_resource[ $bot_key ][ $type_id ] ?? 0 );
					}
					if ( 0 === $row_total ) continue;
					$row_bg = ( 0 === ( $bot_row_idx ?? 0 ) % 2 ) ? '#fff' : '#f9fafb';
					$bot_row_idx = ( $bot_row_idx ?? 0 ) + 1;
				?>
				<tr style="background:<?php echo esc_attr( $row_bg ); ?>;">
					<!-- Bot name with colour dot -->
					<td style="padding:8px 12px; white-space:nowrap; border-right:2px solid #e5e7eb;">
						<span style="display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#374151;">
							<span style="width:8px; height:8px; border-radius:50%; background:<?php echo esc_attr( $bot_info['color'] ); ?>; flex-shrink:0;"></span>
							<?php echo esc_html( $bot_info['label'] ); ?>
						</span>
					</td>
					<!-- One cell per active content type -->
					<?php foreach ( $active_cols_ordered as $type_id ) :
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
					<td style="padding:7px 12px; text-align:center; color:<?php echo $cnt > 0 ? esc_attr( $bot_info['color'] ) : '#d1d5db'; ?>; font-weight:<?php echo $cnt > 0 ? '600' : '400'; ?>; white-space:nowrap;">
						<?php echo $cnt > 0 ? esc_html( number_format_i18n( $cnt ) ) : '—'; ?>
						<?php echo $cell_arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built from literals ?>
					</td>
					<?php endforeach; ?>
					<!-- Row total -->
					<td style="padding:7px 12px; text-align:center; font-weight:600; color:#374151; border-left:2px solid #e5e7eb;">
						<?php echo esc_html( number_format_i18n( $row_total ) ); ?>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php if ( ! empty( $network_avgs ) ) : ?>
			<p style="font-size:11px; color:#9ca3af; margin:8px 0 0;">
				<?php esc_html_e( '↑ ↓ = above / below network average for that content type', 'wp-pugmill' ); ?>
			</p>
			<?php endif; ?>
		</div>
		<?php endif; ?>

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
				<?php esc_html_e( 'Crawl Intelligence', 'wp-pugmill' ); ?>
			</h3>
			<p style="margin:0 0 16px; font-size:12px; color:#666;">
				<?php esc_html_e( 'What bots are actually finding when they visit — content quality, crawl behaviour, and performance signals, last 30 days.', 'wp-pugmill' ); ?>
			</p>
			<div style="overflow-x:auto;">
			<table class="widefat" style="font-size:12px; border-collapse:collapse;">
				<thead>
					<!-- Group header row -->
					<tr style="background:#f6f7f7;">
						<th style="padding:8px 12px; text-align:left; font-weight:600; white-space:nowrap; width:160px; border-bottom:1px solid #e5e7eb; border-right:2px solid #e5e7eb;" rowspan="2">
							<?php esc_html_e( 'Bot', 'wp-pugmill' ); ?>
						</th>
						<th colspan="3" style="padding:6px 12px; text-align:center; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#7c3aed; border-bottom:1px solid #e5e7eb;">
							<?php esc_html_e( 'Content Quality', 'wp-pugmill' ); ?>
						</th>
						<th colspan="3" style="padding:6px 12px; text-align:center; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#0369a1; border-bottom:1px solid #e5e7eb; border-left:2px solid #e5e7eb;">
							<?php esc_html_e( 'Crawl Behavior', 'wp-pugmill' ); ?>
						</th>
						<th colspan="1" style="padding:6px 12px; text-align:center; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#374151; border-bottom:1px solid #e5e7eb; border-left:2px solid #e5e7eb;">
							<?php esc_html_e( 'Performance', 'wp-pugmill' ); ?>
						</th>
					</tr>
					<!-- Column names -->
					<tr style="background:#f6f7f7;">
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( 'Word Count', 'wp-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( 'Freshness', 'wp-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( 'Fact Density', 'wp-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap; border-left:2px solid #e5e7eb;"><?php esc_html_e( 'URL Depth', 'wp-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( 'URL Type', 'wp-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap;"><?php esc_html_e( '404 Rate', 'wp-pugmill' ); ?></th>
						<th style="padding:6px 12px; text-align:center; font-weight:500; font-size:11px; color:#555; border-bottom:1px solid #e5e7eb; white-space:nowrap; border-left:2px solid #e5e7eb;"><?php esc_html_e( 'Avg ms', 'wp-pugmill' ); ?></th>
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
						$url_lbl = $url_param / $url_total <= 0.2 ? 'Clean' : 'Mixed';
						$url_col = 'Clean' === $url_lbl ? '#16a34a' : '#d97706';
					} else {
						$url_lbl = '—';
						$url_col = '#9ca3af';
					}

					$status_dist = $sig['http_status'] ?? array();
					$s_ok  = (int) ( $status_dist['200'] ?? 0 );
					$s_404 = (int) ( $status_dist['404'] ?? 0 );
					$s_tot = $s_ok + $s_404;
					if ( $s_tot > 0 ) {
						$rate_404 = round( $s_404 / $s_tot * 100 );
						$r404_lbl = $rate_404 . '%';
						$r404_col = $rate_404 >= 10 ? '#dc2626' : ( $rate_404 >= 3 ? '#d97706' : '#16a34a' );
					} else {
						$r404_lbl = '—';
						$r404_col = '#9ca3af';
					}

					// ── Performance ──────────────────────────────────────────
					$gen_sum   = (int) ( $sig['php_gen_ms_sum']['all'] ?? 0 );
					$gen_count = (int) ( $sig['php_gen_ms_count']['all'] ?? 0 );
					if ( $gen_count > 0 ) {
						$avg_ms     = (int) round( $gen_sum / $gen_count );
						$avg_ms_lbl = number_format_i18n( $avg_ms ) . ' ms';
						$avg_ms_col = $avg_ms >= 1000 ? '#dc2626' : ( $avg_ms >= 500 ? '#d97706' : '#16a34a' );
					} else {
						$avg_ms_lbl = '—';
						$avg_ms_col = '#9ca3af';
					}
				?>
				<tr style="background:<?php echo esc_attr( $ci_bg ); ?>;">
					<td style="padding:8px 12px; white-space:nowrap; border-right:2px solid #e5e7eb;">
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
				<?php esc_html_e( 'Dominant value shown per signal. Fact Density: green = high structured content, amber = medium, grey = low. 404 Rate: green < 3%, amber 3–9%, red ≥ 10%. Avg ms: green < 500ms, amber 500–999ms, red ≥ 1s.', 'wp-pugmill' ); ?>
			</p>
		</div>
		<?php endif; ?>

		<!-- Top Posts by AI visits ──────────────────────────────────────────── -->
		<?php if ( ! empty( $top_posts ) ) : ?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:24px;">
			<h3 style="margin:0 0 4px; font-size:14px; font-weight:600;"><?php esc_html_e( 'Top Posts', 'wp-pugmill' ); ?></h3>
			<p style="margin:0 0 14px; font-size:12px; color:#666;">
				<?php esc_html_e( 'Most-visited content pages — last 7 days. ★ marks posts where a bot read your AEO markdown endpoint directly.', 'wp-pugmill' ); ?>
			</p>
			<table class="widefat" style="font-size:12px; border-collapse:collapse;">
				<thead>
					<tr style="background:#f6f7f7;">
						<th style="padding:8px 12px; text-align:left; font-weight:600;"><?php esc_html_e( 'URL', 'wp-pugmill' ); ?></th>
						<th style="padding:8px 12px; text-align:center; font-weight:600; width:60px;"><?php esc_html_e( 'Visits', 'wp-pugmill' ); ?></th>
						<th style="padding:8px 12px; text-align:left; font-weight:600;"><?php esc_html_e( 'By Bot', 'wp-pugmill' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $top_posts as $i => $post_row ) : ?>
				<tr style="background:<?php echo 0 === $i % 2 ? '#fff' : '#f9fafb'; ?>;">
					<td style="padding:8px 12px; word-break:break-all; font-size:11px;">
						<?php if ( $post_row['aeo'] ) : ?>
						<span style="color:#16a34a; font-weight:700; margin-right:4px;" title="<?php esc_attr_e( 'AEO markdown endpoint was read', 'wp-pugmill' ); ?>">★</span>
						<?php endif; ?>
						<a href="<?php echo esc_url( home_url( $post_row['url'] ) ); ?>" target="_blank" rel="noopener"
						   style="font-family:monospace; color:#1d2327; text-decoration:none;"
						   onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
							<?php echo esc_html( $post_row['url'] ); ?>
						</a>
						<?php
						$post_id = url_to_postid( home_url( $post_row['url'] ) );
						if ( $post_id ) :
						?>
						<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"
						   style="margin-left:6px; font-size:10px; color:#7c3aed; text-decoration:none; white-space:nowrap;"
						   title="<?php esc_attr_e( 'Edit post', 'wp-pugmill' ); ?>">
							<?php esc_html_e( 'Edit', 'wp-pugmill' ); ?> ›
						</a>
						<?php endif; ?>
					</td>
					<td style="padding:8px 12px; text-align:center; font-weight:700; color:#374151;">
						<?php echo esc_html( number_format_i18n( $post_row['total'] ) ); ?>
					</td>
					<td style="padding:8px 12px;">
						<span style="display:flex; flex-wrap:wrap; gap:4px;">
						<?php foreach ( $post_row['bots'] as $bot_name => $cnt ) :
							$bc = isset( $bots[ $bot_name ] ) ? $bots[ $bot_name ]['color'] : '#9ca3af';
						?>
						<span style="display:inline-flex; align-items:center; gap:3px; font-size:11px; padding:1px 6px; border-radius:9999px; background:<?php echo esc_attr( $bc ); ?>1a; color:<?php echo esc_attr( $bc ); ?>; font-weight:600;">
							<span style="width:6px; height:6px; border-radius:50%; background:<?php echo esc_attr( $bc ); ?>;"></span>
							<?php echo esc_html( $bot_name ); ?> <?php echo esc_html( $cnt ); ?>
						</span>
						<?php endforeach; ?>
						</span>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<!-- Recent visits table -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:32px;">
			<h3 style="margin:0 0 14px; font-size:14px; font-weight:600;">
				<?php esc_html_e( 'Recent Visits', 'wp-pugmill' ); ?>
				<span style="font-weight:normal; color:#9ca3af; font-size:12px; margin-left:6px;">
					<?php esc_html_e( 'last 50', 'wp-pugmill' ); ?>
				</span>
			</h3>

			<?php if ( empty( $recent ) ) : ?>
			<p style="color:#9ca3af; font-size:13px;"><?php esc_html_e( 'No visits recorded yet.', 'wp-pugmill' ); ?></p>
			<?php else : ?>
			<table class="widefat striped" style="font-size:13px;">
				<thead>
					<tr>
						<th style="width:110px;"><?php esc_html_e( 'Bot', 'wp-pugmill' ); ?></th>
						<th style="width:130px;"><?php esc_html_e( 'Type', 'wp-pugmill' ); ?></th>
						<th><?php esc_html_e( 'URL', 'wp-pugmill' ); ?></th>
						<th style="width:160px;"><?php esc_html_e( 'Time', 'wp-pugmill' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent as $visit ) :
						$bot_color = isset( $bots[ $visit['bot'] ] ) ? $bots[ $visit['bot'] ]['color'] : '#9ca3af';
					?>
					<?php
					$r_cat   = $resource_cats[ $visit['resource_type'] ] ?? 'crawl';
					$r_badge = array( 'aeo' => '#16a34a', 'discovery' => '#2563eb', 'crawl' => '#9ca3af' );
					$r_bg    = array( 'aeo' => '#f0fdf4', 'discovery' => '#eff6ff', 'crawl' => '#f9fafb' );
					?>
					<tr>
						<td>
							<span style="display:inline-flex; align-items:center; gap:6px;">
								<span style="width:8px; height:8px; border-radius:50%; background:<?php echo esc_attr( $bot_color ); ?>; flex-shrink:0;"></span>
								<?php echo esc_html( $visit['bot'] ); ?>
							</span>
						</td>
						<td>
							<span style="display:inline-block; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:600;
							      background:<?php echo esc_attr( $r_bg[ $r_cat ] ); ?>;
							      color:<?php echo esc_attr( $r_badge[ $r_cat ] ); ?>;">
								<?php echo esc_html( $visit['resource_label'] ); ?>
							</span>
						</td>
						<td style="font-family:monospace; font-size:12px; word-break:break-all;">
							<?php
							$display_url = strlen( $visit['url'] ) > 80
								? substr( $visit['url'], 0, 80 ) . '…'
								: $visit['url'];
							echo esc_html( $display_url );
							?>
						</td>
						<td style="color:#666; white-space:nowrap;">
							<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $visit['visited_at'] ) ); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>

		<!-- Download Data ───────────────────────────────────────────────────── -->
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 24px; margin-bottom:24px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
			<span style="font-size:13px; font-weight:600; color:#374151; flex:0 0 auto;"><?php esc_html_e( 'Download Data', 'wp-pugmill' ); ?></span>
			<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wppugmill_export_csv_daily&nonce=' . $export_nonce ) ); ?>"
			   style="display:inline-flex; align-items:center; gap:5px; padding:6px 14px; font-size:12px; font-weight:600;
			          background:#f6f7f7; color:#374151; border:1px solid #ddd; border-radius:4px; text-decoration:none;">
				⬇ <?php esc_html_e( 'Daily Aggregates CSV', 'wp-pugmill' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wppugmill_export_csv_recent&nonce=' . $export_nonce ) ); ?>"
			   style="display:inline-flex; align-items:center; gap:5px; padding:6px 14px; font-size:12px; font-weight:600;
			          background:#f6f7f7; color:#374151; border:1px solid #ddd; border-radius:4px; text-decoration:none;">
				⬇ <?php esc_html_e( 'Recent Visits CSV', 'wp-pugmill' ); ?>
			</a>
			<span style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'Daily data retained for 90 days. Recent visits retained for 7 days.', 'wp-pugmill' ); ?></span>
		</div>

		<!-- Footer note -->
		<p style="color:#9ca3af; font-size:12px;">
			<?php esc_html_e( 'AI crawlers: ChatGPT (GPTBot, ChatGPT-User, OAI-SearchBot), Claude (ClaudeBot, anthropic-ai), Perplexity (PerplexityBot), Gemini (Google-Extended), Amazonbot, Meta (meta-externalagent). Search spiders: Googlebot, Bingbot, Applebot (Apple Intelligence), DuckDuckBot, Bytespider (ByteDance). Daily aggregates retained 90 days. Recent visit details kept 7 days.', 'wp-pugmill' ); ?>
		</p>

		<?php if ( $total > 0 ) : ?>
		<script>
		(function() {
			var canvas = document.getElementById( 'wppugmill-bot-chart' );
			if ( ! canvas || ! canvas.getContext ) { return; }

			var labels = <?php echo wp_json_encode( array_values( $chart_labels ) ); ?>;
			var sets   = <?php echo wp_json_encode( array_values( $chart_datasets ) ); ?>;

			// Pre-compute global max (constant across redraws)
			var maxVal = 1;
			sets.forEach( function( ds ) {
				ds.values.forEach( function( v ) { if ( v > maxVal ) { maxVal = v; } } );
			} );

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
		</script>
		<?php endif; ?>

		<?php if ( $has_api_key ) : ?>
		<script>
		(function() {
			var btn    = document.getElementById( 'wppugmill-insights-btn' );
			var output = document.getElementById( 'wppugmill-insights-output' );
			var text   = document.getElementById( 'wppugmill-insights-text' );
			var status = document.getElementById( 'wppugmill-insights-status' );

			if ( ! btn ) { return; }

			btn.addEventListener( 'click', function() {
				var isRefresh = btn.innerHTML.indexOf( 'Refresh' ) !== -1;
				btn.disabled  = true;
				btn.classList.add( 'wppugmill-loading' );
				btn.innerHTML = 'Analyzing…';
				output.style.display = 'block';
				text.innerHTML = '<span style="color:#9ca3af;font-size:13px;">Asking AI to analyze your bot traffic…</span>';
				if ( status ) { status.textContent = ''; }

				var body = new URLSearchParams( {
					action:  'wppugmill_analytics_insights',
					nonce:   <?php echo wp_json_encode( $insights_nonce ); ?>,
					refresh: isRefresh ? '1' : '0',
				} );

				fetch( ajaxurl, { method: 'POST', body: body } )
					.then( function( r ) { return r.json(); } )
					.then( function( data ) {
						btn.disabled  = false;
						btn.classList.remove( 'wppugmill-loading' );
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
									? '<?php echo esc_js( __( 'Generated just now', 'wp-pugmill' ) ); ?>'
									: ago + ' <?php echo esc_js( __( 'minutes ago', 'wp-pugmill' ) ); ?>';
							}
						} else {
							btn.innerHTML = '✨ Get AI Analysis';
							text.innerHTML = '<span style="color:#dc3232;font-size:13px;">' + ( data.data || '<?php echo esc_js( __( 'Something went wrong. Please try again.', 'wp-pugmill' ) ); ?>' ) + '</span>';
						}
					} )
					.catch( function() {
						btn.disabled  = false;
						btn.classList.remove( 'wppugmill-loading' );
						btn.innerHTML = '✨ Get AI Analysis';
						text.innerHTML  = '<span style="color:#dc3232;font-size:13px;"><?php echo esc_js( __( 'Request failed. Please try again.', 'wp-pugmill' ) ); ?></span>';
					} );
			} );
		}() );
		</script>
		<?php endif; ?>
		<?php endif; // end opted-in else (analytics tab) ?>

		<?php if ( 'analytics' === $active_tab && get_option( 'wppugmill_analytics_opted_in' ) ) : ?>
		<div style="margin-top:8px; display:flex; align-items:center; gap:8px; font-size:12px; color:#9ca3af;">
			<span>&#10003; <?php
				if ( $network_sites >= 1 ) {
					printf(
						/* translators: %d: number of contributing sites */
						esc_html__( 'Network averages from %d participating sites', 'wp-pugmill' ),
						$network_sites
					);
				} else {
					esc_html_e( 'Pugmill Intelligence Network — network averages appear once 10+ sites contribute', 'wp-pugmill' );
				}
			?></span>
			<span style="color:#ddd;">|</span>
			<button id="wppugmill-send-now" style="background:none; border:none; padding:0; color:#6b7280; font-size:12px; cursor:pointer; text-decoration:underline;">
				<?php esc_html_e( 'Send now', 'wp-pugmill' ); ?>
			</button>
			<span id="wppugmill-send-now-result" style="font-size:11px;"></span>
			<span style="color:#ddd;">|</span>
			<form method="post" action="options.php" style="margin:0; padding:0;">
				<?php settings_fields( 'wppugmill_analytics' ); ?>
				<input type="hidden" name="wppugmill_analytics_opted_in" value="0">
				<button type="submit" onclick="return confirm('<?php echo esc_js( __( 'Leave the Pugmill Intelligence Network? This will also disable Bot Analytics — your historical data stays on your site but you will no longer see crawler or spider activity.', 'wp-pugmill' ) ); ?>')" style="background:none; border:none; padding:0; color:#dc2626; font-size:12px; cursor:pointer; text-decoration:underline;">
					<?php esc_html_e( 'Leave network', 'wp-pugmill' ); ?>
				</button>
			</form>
		</div>
		<script>
		( function() {
			var btn    = document.getElementById( 'wppugmill-send-now' );
			var result = document.getElementById( 'wppugmill-send-now-result' );
			if ( ! btn ) return;
			btn.addEventListener( 'click', function() {
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Sending…', 'wp-pugmill' ) ); ?>';
				result.textContent = '';
				result.style.color = '#9ca3af';
				var data = new FormData();
				data.append( 'action', 'wppugmill_manual_send' );
				data.append( 'nonce', '<?php echo esc_js( wp_create_nonce( 'wppugmill_manual_send' ) ); ?>' );
				fetch( ajaxurl, { method: 'POST', body: data } )
					.then( function( r ) { return r.json(); } )
					.then( function( json ) {
						if ( json.success ) {
							result.textContent = '✓ ' + json.data;
							result.style.color = '#16a34a';
						} else {
							result.textContent = '✗ ' + json.data;
							result.style.color = '#dc2626';
						}
					} )
					.catch( function() {
						result.textContent = '✗ Request failed';
						result.style.color = '#dc2626';
					} )
					.finally( function() {
						btn.disabled = false;
						btn.textContent = '<?php echo esc_js( __( 'Send now', 'wp-pugmill' ) ); ?>';
					} );
			} );
		}() );
		</script>
		<?php endif; ?>

		<?php elseif ( 'bulk-aeo' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     BULK AEO TAB
		     ════════════════════════════════════════════════════════════ -->
		<div style="margin-top:24px;">
			<p style="<?php echo esc_attr( $p_style ); ?>">
				<?php esc_html_e( 'Generate a summary, Q&amp;A pairs, entities, and keywords for every published post and page using your connected AI provider.', 'wp-pugmill' ); ?>
			</p>
			<div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:12px 16px; margin-bottom:16px; font-size:12px; color:#6b7280; line-height:1.6;">
				<strong style="color:#374151;"><?php esc_html_e( 'How it works:', 'wp-pugmill' ); ?></strong>
				<?php esc_html_e( 'Posts are processed one at a time with a short pause between each request. This prevents your WordPress server from being overwhelmed by simultaneous requests and keeps your AI provider usage within rate limits — most accounts start on lower-tier plans with strict per-minute caps. For large sites, you can run Bulk AEO in multiple sessions: posts that already have AEO data are skipped automatically when you run again.', 'wp-pugmill' ); ?>
				<br><br>
				<strong style="color:#374151;"><?php esc_html_e( 'Check your AI spend first:', 'wp-pugmill' ); ?></strong>
				<?php esc_html_e( 'Generating AEO for hundreds of posts can add up quickly. Before running on a large site, review your AI provider\'s usage dashboard so there are no surprises on your bill.', 'wp-pugmill' ); ?>
			</div>

			<?php if ( 'ai' !== $mode ) : ?>
			<!-- Locked state — not Pro -->
			<div style="opacity:0.5; pointer-events:none; user-select:none;">
			<?php endif; ?>

			<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-top:4px;">

				<!-- Options -->
				<div style="display:flex; gap:32px; align-items:flex-start; flex-wrap:wrap; margin-bottom:16px;">
					<fieldset style="border:none; margin:0; padding:0;">
						<legend style="font-size:12px; font-weight:600; color:#374151; margin-bottom:8px;"><?php esc_html_e( 'Content', 'wp-pugmill' ); ?></legend>
						<label style="display:block; font-size:13px; color:#374151; margin-bottom:4px; cursor:pointer;">
							<input type="radio" name="wppugmill_bulk_post_types" value="all" checked style="margin-right:5px;">
							<?php esc_html_e( 'Posts + Pages', 'wp-pugmill' ); ?>
						</label>
						<label style="display:block; font-size:13px; color:#374151; margin-bottom:4px; cursor:pointer;">
							<input type="radio" name="wppugmill_bulk_post_types" value="post" style="margin-right:5px;">
							<?php esc_html_e( 'Posts only', 'wp-pugmill' ); ?>
						</label>
						<label style="display:block; font-size:13px; color:#374151; cursor:pointer;">
							<input type="radio" name="wppugmill_bulk_post_types" value="page" style="margin-right:5px;">
							<?php esc_html_e( 'Pages only', 'wp-pugmill' ); ?>
						</label>
					</fieldset>
					<div>
						<p style="font-size:12px; font-weight:600; color:#374151; margin:0 0 8px;"><?php esc_html_e( 'Options', 'wp-pugmill' ); ?></p>
						<label style="display:block; font-size:13px; color:#374151; cursor:pointer;">
							<input type="checkbox" id="wppugmill-bulk-skip-existing" checked style="margin-right:5px;">
							<?php esc_html_e( 'Skip posts that already have AEO data', 'wp-pugmill' ); ?>
						</label>
					</div>
					<fieldset style="border:none; margin:0; padding:0;">
						<legend style="font-size:12px; font-weight:600; color:#374151; margin-bottom:8px;"><?php esc_html_e( 'Priority', 'wp-pugmill' ); ?></legend>
						<select id="wppugmill-bulk-sort" style="font-size:13px; height:28px;">
							<option value="newest"   ><?php esc_html_e( 'Newest first',         'wp-pugmill' ); ?></option>
							<option value="commented"><?php esc_html_e( 'Most commented first', 'wp-pugmill' ); ?></option>
							<option value="oldest"   ><?php esc_html_e( 'Oldest first',         'wp-pugmill' ); ?></option>
						</select>
						<p style="font-size:11px; color:#9ca3af; margin:4px 0 0;"><?php esc_html_e( 'Recent posts are most likely still getting traffic.', 'wp-pugmill' ); ?></p>
					</fieldset>
					<fieldset style="border:none; margin:0; padding:0;">
						<legend style="font-size:12px; font-weight:600; color:#374151; margin-bottom:8px;"><?php esc_html_e( 'Request Delay', 'wp-pugmill' ); ?></legend>
						<select id="wppugmill-bulk-speed" style="font-size:13px; height:28px;">
							<option value="1500"><?php esc_html_e( 'Fast (1.5s between posts)', 'wp-pugmill' ); ?></option>
							<option value="3000" selected><?php esc_html_e( 'Normal (3s between posts)', 'wp-pugmill' ); ?></option>
							<option value="6000"><?php esc_html_e( 'Careful (6s between posts)', 'wp-pugmill' ); ?></option>
						</select>
						<p style="font-size:11px; color:#9ca3af; margin:4px 0 0;"><?php esc_html_e( 'Longer delays reduce rate limit risk and AI spend rate.', 'wp-pugmill' ); ?></p>
					</fieldset>
				</div>

				<!-- Stats -->
				<p id="wppugmill-bulk-stats" style="font-size:12px; color:#9ca3af; margin:0 0 16px;">Loading…</p>

				<!-- Start button — purple pill, matches sidebar AI buttons -->
				<button
					id="wppugmill-bulk-start"
					class="button"
					style="background:#7c3aed; border-color:#7c3aed; color:#fff; border-radius:9999px; padding:0 18px; height:32px; line-height:30px; font-size:13px;<?php echo 'ai' !== $mode ? ' opacity:0.4;' : ''; ?>"
					<?php echo 'ai' !== $mode ? 'disabled' : ''; ?>
				>
					<?php esc_html_e( 'Generate AEO for All Content', 'wp-pugmill' ); ?>
				</button>

				<!-- Progress (hidden until running) -->
				<div id="wppugmill-bulk-progress" style="display:none; margin-top:20px;">
					<div style="background:#e5e7eb; border-radius:3px; height:6px; overflow:hidden; margin-bottom:10px;">
						<div id="wppugmill-bulk-bar-fill" style="height:100%; background:#7c3aed; border-radius:3px; width:0%; transition:width 0.3s ease;"></div>
					</div>
					<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
						<div style="display:flex; gap:16px; align-items:center;">
							<span id="wppugmill-bulk-counter" style="font-size:12px; color:#6b7280;"></span>
							<span id="wppugmill-bulk-rate"    style="font-size:12px; color:#9ca3af;"></span>
						</div>
						<div style="display:flex; gap:8px;">
							<button id="wppugmill-bulk-pause"  class="button button-secondary" style="font-size:11px; padding:0 10px; height:26px; line-height:24px;"><?php esc_html_e( 'Pause', 'wp-pugmill' ); ?></button>
							<button id="wppugmill-bulk-cancel" class="button"                  style="font-size:11px; padding:0 10px; height:26px; line-height:24px; color:#dc2626; border-color:#fca5a5;"><?php esc_html_e( 'Cancel', 'wp-pugmill' ); ?></button>
						</div>
					</div>
					<p id="wppugmill-bulk-current" style="font-size:12px; color:#6b7280; margin:0 0 6px; min-height:18px;"></p>
					<p style="font-size:12px; color:#6b7280; margin:0 0 6px;">
						<span style="color:#46b450;">&#10003;</span> <span id="wppugmill-bulk-success">0</span> generated &nbsp;
						<span style="color:#dc3232;">&#10007;</span> <span id="wppugmill-bulk-failed">0</span> failed &nbsp;
						<span style="color:#9ca3af;">&#8618;</span> <span id="wppugmill-bulk-skipped">0</span> skipped
					</p>
					<p style="font-size:11px; color:#9ca3af; margin:0;"><?php esc_html_e( 'Keep this page open while the run is in progress — navigating away will stop processing.', 'wp-pugmill' ); ?></p>
				</div>

				<!-- Completion message -->
				<p id="wppugmill-bulk-complete" style="display:none; margin-top:14px; font-size:13px; color:#374151;"></p>

			</div><!-- /card -->

			<?php if ( 'ai' !== $mode ) : ?>
			</div><!-- /locked overlay -->
			<p style="margin-top:8px; font-size:12px; color:#9ca3af;"><?php esc_html_e( 'Available with WP Pugmill Pro.', 'wp-pugmill' ); ?></p>
			<?php endif; ?>

		</div>

		<?php endif; // end tab switch ?>

		<!-- ── Shared footer ───────────────────────────────────────── -->
		<hr>
		<p style="color:#999; font-size:12px;">
			<?php
			printf(
				/* translators: 1: Pugmill link 2: Docs link */
				wp_kses( __( 'WP Pugmill by %1$s &mdash; %2$s', 'wp-pugmill' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
				'<a href="' . esc_url( 'https://wppugmill.com' ) . '" target="_blank">Pugmill</a>',
				'<a href="' . esc_url( 'https://wppugmill.com/docs' ) . '" target="_blank">' . esc_html__( 'Documentation', 'wp-pugmill' ) . '</a>'
			);
			?>
		</p>
	</div><!-- .wrap -->
	<?php
}
