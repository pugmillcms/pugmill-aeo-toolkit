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
		'llms-txt/llms-txt.php'               => 'LLMs.txt',
		'llmstxt/llmstxt.php'                 => 'LLMs.txt',
		'ai-llms-txt/ai-llms-txt.php'         => 'AI LLMs.txt',
		'llms-txt-for-wp/llms-txt-for-wp.php' => 'LLMs.txt for WP',
	);
	foreach ( $llms_plugins as $slug => $name ) {
		if ( is_plugin_active( $slug ) ) {
			$data['llms_txt_conflicts'][] = $name;
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

function wppugmill_render_settings_page() {
	$mode           = wppugmill_mode();
	$license_status = wppugmill_license_status();
	$license_key    = wppugmill_get_encrypted_option( 'wppugmill_license_key', '' );
	$api_key        = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	// Detect active tab — default is 'license'
	$allowed_tabs = array( 'license', 'ai-provider', 'site-aeo', 'author-voice', 'compatibility', 'sitemap', 'analytics' );
	$active_tab   = isset( $_GET['tab'] ) && in_array( sanitize_key( $_GET['tab'] ), $allowed_tabs, true ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		? sanitize_key( $_GET['tab'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		: 'license';

	// Shared styles
	$h2_style = 'font-size:14px; font-weight:600; color:#1d2327; padding-bottom:10px; border-bottom:1px solid #ddd; margin:28px 0 16px;';
	$p_style  = 'color:#666; font-size:13px; max-width:650px; margin:0 0 16px;';

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
					<strong><?php esc_html_e( 'AI Connector active.', 'wp-pugmill' ); ?></strong>
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
					<strong><?php esc_html_e( 'Free mode.', 'wp-pugmill' ); ?></strong> <?php esc_html_e( 'Manual AEO tools are active.', 'wp-pugmill' ); ?>
					<?php printf(
						'<a href="%1$s" target="_blank">%2$s</a> %3$s',
						esc_url( 'https://wppugmill.com/pricing' ),
						esc_html__( 'Upgrade to AI Connector', 'wp-pugmill' ),
						esc_html__( 'to unlock AI-powered generation with your own API key.', 'wp-pugmill' )
					); ?>
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
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:24px;">
			<?php esc_html_e( 'WP Pugmill works in Free mode out of the box — you can manually fill in AEO metadata for every post. Upgrading to AI Connector unlocks one-click AI generation for summaries, Q&A pairs, entities, keywords, SEO fields, social drafts, and more. You bring your own API key from Anthropic, OpenAI, or Google, so your content never passes through our servers.', 'wp-pugmill' ); ?>
		</p>
		<form method="post" action="options.php" style="margin-top:16px;">
			<?php settings_fields( 'wppugmill_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="wppugmill_license_key"><?php esc_html_e( 'License Key', 'wp-pugmill' ); ?></label></th>
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
							echo esc_html__( 'Enter your WP Pugmill AI Connector license key.', 'wp-pugmill' );
							echo ' ';
							printf( '<a href="%s" target="_blank">%s</a>', esc_url( 'https://wppugmill.com/pricing' ), esc_html__( 'Get a license →', 'wp-pugmill' ) );
							?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<?php elseif ( 'ai-provider' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     AI PROVIDER TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php if ( in_array( $mode, array( 'ai', 'free' ), true ) ) : ?>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:24px;">
			<?php esc_html_e( 'WP Pugmill uses a bring-your-own-key model — you connect directly to Anthropic, OpenAI, or Google Gemini using your own API account. Your key is encrypted and stored server-side only, never exposed to visitors or transmitted through our servers. Usage is billed directly by your chosen AI provider.', 'wp-pugmill' ); ?>
		</p>
		<form method="post" action="options.php" style="margin-top:16px;">
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
						<?php
						$desc_links = sprintf(
							/* translators: 1: Anthropic link 2: OpenAI link 3: Google AI Studio link */
							esc_html__( 'Get your key from %1$s, %2$s, or %3$s.', 'wp-pugmill' ),
							'<a href="' . esc_url( 'https://console.anthropic.com' ) . '" target="_blank">Anthropic</a>',
							'<a href="' . esc_url( 'https://platform.openai.com' ) . '" target="_blank">OpenAI</a>',
							'<a href="' . esc_url( 'https://aistudio.google.com' ) . '" target="_blank">Google AI Studio</a>'
						);
						echo '<p class="description">' . wp_kses( $desc_links, array( 'a' => array( 'href' => array(), 'target' => array() ) ) ) . '</p>';
						?>
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
			<strong><?php esc_html_e( 'AI generation is available with an AI Connector license.', 'wp-pugmill' ); ?></strong><br>
			<span style="color:#666;"><?php esc_html_e( 'Connect Claude, GPT-4, or Gemini to auto-generate your AEO metadata with one click.', 'wp-pugmill' ); ?></span><br><br>
			<a href="<?php echo esc_url( 'https://wppugmill.com/pricing' ); ?>" target="_blank" class="button button-primary"><?php esc_html_e( 'Get AI Connector License →', 'wp-pugmill' ); ?></a>
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
		<form method="post" action="options.php" style="margin-top:16px;">
			<?php settings_fields( 'wppugmill_settings' ); ?>
			<table class="form-table">
				<tr>
					<th style="vertical-align:top; padding-top:12px;"><label for="wppugmill_site_summary"><?php esc_html_e( 'Site Summary', 'wp-pugmill' ); ?></label></th>
					<td>
						<textarea id="wppugmill_site_summary" name="wppugmill_site_summary" rows="7" style="width:100%; max-width:600px; font-family:monospace; font-size:13px;"><?php echo esc_textarea( get_option( 'wppugmill_site_summary', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Used in /llms.txt and Organization schema. Describe your site for AI crawlers.', 'wp-pugmill' ); ?></p>
						<?php if ( $ai_available ) : ?>
						<p style="margin-top:8px;">
							<button type="button" id="wppugmill-gen-site-summary" class="button button-secondary">
								✨ <?php esc_html_e( 'Draft with AI', 'wp-pugmill' ); ?>
							</button>
							<span id="wppugmill-site-summary-status" style="margin-left:10px; font-size:13px; color:#666;"></span>
						</p>
						<?php else : ?>
						<p style="margin-top:6px; font-size:12px; color:#9ca3af;">
							<?php printf(
								wp_kses( __( 'Add an <a href="%s">AI Connector license and API key</a> to draft this with AI.', 'wp-pugmill' ), array( 'a' => array( 'href' => array() ) ) ),
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

		<?php if ( $ai_available ) : ?>
		<script>
		(function() {
			var btn     = document.getElementById( 'wppugmill-gen-site-summary' );
			var textarea = document.getElementById( 'wppugmill_site_summary' );
			var status  = document.getElementById( 'wppugmill-site-summary-status' );
			if ( ! btn || ! textarea ) { return; }

			btn.addEventListener( 'click', function() {
				btn.disabled    = true;
				btn.textContent = '<?php echo esc_js( __( 'Drafting…', 'wp-pugmill' ) ); ?>';
				status.textContent = '';

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
					btn.disabled    = false;
					btn.textContent = '✨ <?php echo esc_js( __( 'Draft with AI', 'wp-pugmill' ) ); ?>';
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

			<div style="margin-top:16px; padding-top:16px; border-top:1px solid #e8e0f7; display:grid; grid-template-columns:1fr 1fr; gap:0 32px;">
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
		</div>

		<?php elseif ( 'author-voice' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     AUTHOR VOICE TAB
		     ════════════════════════════════════════════════════════════ -->
		<form method="post" action="options.php" style="margin-top:24px;">
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

		<?php elseif ( 'compatibility' === $active_tab ) : ?>
		<!-- ════════════════════════════════════════════════════════════
		     PLUGIN COMPATIBILITY TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php
		$compat     = wppugmill_get_compatibility_data();
		$has_issues = ! empty( $compat['json_ld_conflicts'] ) || ! empty( $compat['llms_txt_conflicts'] )
			|| $compat['robots']['discourage'] || $compat['robots']['blocks_all'] || ! empty( $compat['robots']['blocked_bots'] );
		?>
		<p style="<?php echo esc_attr( $p_style ); ?> margin-top:24px;">
			<?php esc_html_e( 'WP Pugmill outputs structured data (JSON-LD schema), a site summary file (/llms.txt), and on-page SEO tags. If you are running another SEO plugin alongside it, some of these outputs may overlap. Use the controls below to disable specific WP Pugmill outputs and defer to the other plugin instead — your AEO metadata is always preserved regardless of which plugin handles the front-end output.', 'wp-pugmill' ); ?>
		</p>
		<form method="post" action="options.php" style="margin-top:16px;">
			<?php settings_fields( 'wppugmill_settings' ); ?>

			<?php if ( ! $has_issues ) : ?>
			<p style="color:#46b450;">&#10003; <?php esc_html_e( 'No conflicts detected. WP Pugmill is running cleanly alongside your other plugins.', 'wp-pugmill' ); ?></p>
			<?php else : ?>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'WP Pugmill detected potential conflicts with other plugins or your site configuration. Review each item below and adjust if needed.', 'wp-pugmill' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $compat['json_ld_conflicts'] ) ) : ?>
			<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; max-width:650px; border-radius:0 4px 4px 0; margin-bottom:12px;">
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
				<input type="checkbox" name="wppugmill_disable_json_ld" value="1" <?php checked( 1, get_option( 'wppugmill_disable_json_ld', 0 ) ); ?>>
				<?php printf(
					esc_html__( 'Disable WP Pugmill JSON-LD output (defer to %s)', 'wp-pugmill' ),
					esc_html( implode( ' / ', $compat['json_ld_conflicts'] ) )
				); ?>
			</label>
			<p style="<?php echo esc_attr( $p_style ); ?> margin-bottom:20px;"><?php esc_html_e( 'Your AEO metadata (summary, Q&A, entities) will still be saved and used by the AI generation tools — only the &lt;head&gt; schema output is disabled.', 'wp-pugmill' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $compat['llms_txt_conflicts'] ) ) : ?>
			<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; max-width:650px; border-radius:0 4px 4px 0; margin-bottom:12px;">
				<strong><?php esc_html_e( '⚠ Duplicate llms.txt conflict', 'wp-pugmill' ); ?></strong><br>
				<span style="color:#555; font-size:13px;">
				<?php printf(
					esc_html__( '%s is also generating /llms.txt. Two plugins serving the same URL will cause unpredictable results for AI crawlers.', 'wp-pugmill' ),
					esc_html( implode( ', ', $compat['llms_txt_conflicts'] ) )
				); ?>
				</span>
			</div>
			<label style="display:block; margin-bottom:20px;">
				<input type="hidden" name="wppugmill_disable_llms_txt" value="0">
				<input type="checkbox" name="wppugmill_disable_llms_txt" value="1" <?php checked( 1, get_option( 'wppugmill_disable_llms_txt', 0 ) ); ?>>
				<?php printf(
					esc_html__( 'Disable WP Pugmill llms.txt output (defer to %s)', 'wp-pugmill' ),
					esc_html( implode( ' / ', $compat['llms_txt_conflicts'] ) )
				); ?>
			</label>
			<?php endif; ?>

			<?php if ( $compat['robots']['discourage'] || $compat['robots']['blocks_all'] || ! empty( $compat['robots']['blocked_bots'] ) ) : ?>
			<?php if ( $compat['robots']['discourage'] ) : ?>
			<div style="background:#fcf0f1; border-left:4px solid #dc3232; padding:12px 16px; max-width:650px; border-radius:0 4px 4px 0; margin-bottom:12px;">
				<strong><?php esc_html_e( '✗ Search engines are discouraged site-wide', 'wp-pugmill' ); ?></strong><br>
				<span style="color:#555; font-size:13px;">
					<?php esc_html_e( 'WordPress Settings → Reading has "Discourage search engines" enabled. This outputs Disallow: / for all crawlers — including AI answer engines — blocking your content from AEO indexing entirely.', 'wp-pugmill' ); ?>
				</span><br><br>
				<a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Fix in Reading Settings →', 'wp-pugmill' ); ?></a>
			</div>
			<?php endif; ?>
			<?php if ( $compat['robots']['blocks_all'] ) : ?>
			<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; max-width:650px; border-radius:0 4px 4px 0; margin-bottom:12px;">
				<strong><?php esc_html_e( '⚠ robots.txt blocks all crawlers (Disallow: /)', 'wp-pugmill' ); ?></strong><br>
				<span style="color:#555; font-size:13px;">
					<?php esc_html_e( 'Your robots.txt has a wildcard User-agent: * rule with Disallow: /. This blocks all web crawlers including AI answer engines. Consider replacing it with specific rules that allow GPTBot, ClaudeBot, PerplexityBot, and Google-Extended.', 'wp-pugmill' ); ?>
				</span>
			</div>
			<?php endif; ?>
			<?php if ( ! empty( $compat['robots']['blocked_bots'] ) ) : ?>
			<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; max-width:650px; border-radius:0 4px 4px 0; margin-bottom:12px;">
				<strong><?php esc_html_e( '⚠ AI crawlers blocked in robots.txt', 'wp-pugmill' ); ?></strong><br>
				<span style="color:#555; font-size:13px;">
				<?php printf(
					esc_html__( 'The following AI crawlers are explicitly blocked: %s. Remove or adjust these Disallow rules to improve AEO discoverability.', 'wp-pugmill' ),
					'<strong>' . esc_html( implode( ', ', $compat['robots']['blocked_bots'] ) ) . '</strong>'
				); ?>
				</span>
			</div>
			<?php endif; ?>
			<p style="<?php echo esc_attr( $p_style ); ?> margin-bottom:20px;"><?php esc_html_e( 'AI answer engines (ChatGPT, Perplexity, Claude) use web crawlers to index content. Unlike older SEO bots, these are worth allowing — they cite and surface your content in AI-generated answers.', 'wp-pugmill' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $compat['json_ld_conflicts'] ) ) : ?>
			<div style="background:#fff8e1; border-left:4px solid #ffb900; padding:12px 16px; max-width:650px; border-radius:0 4px 4px 0; margin-bottom:12px;">
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
				<input type="checkbox" name="wppugmill_disable_seo_meta" value="1" <?php checked( 1, get_option( 'wppugmill_disable_seo_meta', 0 ) ); ?>>
				<?php printf(
					esc_html__( 'Disable WP Pugmill title/meta/canonical output (defer to %s)', 'wp-pugmill' ),
					esc_html( implode( ' / ', $compat['json_ld_conflicts'] ) )
				); ?>
			</label>
			<p style="<?php echo esc_attr( $p_style ); ?> margin-bottom:20px;"><?php esc_html_e( 'SEO field values you enter in the editor will still be saved — only the &lt;head&gt; output is suppressed.', 'wp-pugmill' ); ?></p>
			<?php endif; ?>

			<?php if ( $has_issues ) : ?>
			<?php submit_button(); ?>
			<?php endif; ?>
		</form>

		<!-- ── Import from Another SEO Plugin ───────────────────────── -->
		<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:32px;"><?php esc_html_e( 'Import from Another SEO Plugin', 'wp-pugmill' ); ?></h2>
		<p style="<?php echo esc_attr( $p_style ); ?>">
			<?php esc_html_e( 'WP Pugmill can import titles, meta descriptions, canonical URLs, robots settings, and OG fields from Yoast, Rank Math, All in One SEO, and SEOPress. Posts that already have WP Pugmill data are skipped by default.', 'wp-pugmill' ); ?>
		</p>

		<?php
		$migration_sources = wppugmill_migration_sources();
		if ( empty( $migration_sources ) ) :
		?>
		<p style="color:#46b450;">&#10003; <?php esc_html_e( 'No importable data found from Yoast, Rank Math, AIOSEO, or SEOPress.', 'wp-pugmill' ); ?></p>
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
		<form method="post" action="options.php" style="margin-top:16px;">
			<?php settings_fields( 'wppugmill_settings' ); ?>

			<h2 style="<?php echo esc_attr( $h2_style ); ?> margin-top:0;"><?php esc_html_e( 'XML Sitemap', 'wp-pugmill' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'XML Sitemap', 'wp-pugmill' ); ?></th>
					<td>
						<?php $sitemap_url = home_url( '/sitemap.xml' ); ?>
						<p>
							<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" class="button button-secondary">
								<?php esc_html_e( 'View sitemap.xml →', 'wp-pugmill' ); ?>
							</a>
						</p>
						<p class="description">
							<?php esc_html_e( 'WP Pugmill automatically generates /sitemap.xml covering all public, published posts and pages. noindex posts are excluded. Search engines are pinged on publish.', 'wp-pugmill' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2 style="<?php echo esc_attr( $h2_style ); ?>"><?php esc_html_e( 'Robots.txt', 'wp-pugmill' ); ?></h2>
			<p style="<?php echo esc_attr( $p_style ); ?>"><?php esc_html_e( 'Optionally override WordPress\'s virtual robots.txt with your own content. WP Pugmill will automatically append a Sitemap: directive when using the default. Leave blank to use WordPress defaults.', 'wp-pugmill' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th style="vertical-align:top;"><?php esc_html_e( 'Custom robots.txt', 'wp-pugmill' ); ?></th>
					<td>
						<textarea
							name="wppugmill_robots_txt_custom"
							id="wppugmill_robots_txt_custom"
							rows="12"
							style="width:100%; max-width:650px; font-family:monospace; font-size:12px;"
							placeholder="User-agent: *&#10;Disallow:&#10;&#10;Sitemap: <?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>"
						><?php echo esc_textarea( get_option( 'wppugmill_robots_txt_custom', '' ) ); ?></textarea>
						<p class="description">
							<?php printf(
								wp_kses( __( 'Live robots.txt: <a href="%s" target="_blank">%s</a>', 'wp-pugmill' ), array( 'a' => array( 'href' => array(), 'target' => array() ) ) ),
								esc_url( home_url( '/robots.txt' ) ),
								esc_html( home_url( '/robots.txt' ) )
							); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<?php else : ?>
		<!-- ════════════════════════════════════════════════════════════
		     BOT ANALYTICS TAB
		     ════════════════════════════════════════════════════════════ -->
		<?php
		$days            = 30;
		$summary         = wppugmill_bot_analytics_summary( $days );
		$daily           = wppugmill_bot_analytics_daily( $days );
		$recent          = wppugmill_bot_analytics_recent( 50 );
		$total           = wppugmill_bot_analytics_total();
		$by_resource     = wppugmill_bot_analytics_by_resource( $days );
		$resource_labels = wppugmill_resource_type_labels();
		$resource_cats   = wppugmill_resource_type_categories();
		$bots            = wppugmill_bot_config();
		$top_posts       = wppugmill_bot_analytics_top_posts( 10 );
		$ai_bots         = array_filter( $bots, function( $b ) { return 'ai'     === $b['type']; } );
		$search_bots     = array_filter( $bots, function( $b ) { return 'search' === $b['type']; } );
		$insights_nonce  = wp_create_nonce( 'wppugmill_analytics_insights' );
		$export_nonce    = wp_create_nonce( 'wppugmill_export_csv' );
		$cached_insights = get_transient( 'wppugmill_ai_analytics_insights' );
		$has_api_key     = ! empty( wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' ) );

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
				<style>
				@keyframes wppugmill-spin { to { transform: rotate(360deg); } }
				.wppugmill-btn-spinner {
					display: inline-block;
					width: 12px; height: 12px;
					border: 2px solid rgba(255,255,255,0.35);
					border-top-color: #fff;
					border-radius: 50%;
					animation: wppugmill-spin 0.7s linear infinite;
					flex-shrink: 0;
				}
				</style>
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

		<!-- AI crawlers label + cards -->
		<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:24px 0 8px;">
			<?php esc_html_e( 'AI Crawlers', 'wp-pugmill' ); ?>
		</p>
		<div style="display:flex; flex-wrap:wrap; gap:14px; margin-bottom:20px;">

			<!-- All-time total card -->
			<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:140px; flex:1; text-align:center;">
				<div style="font-size:28px; font-weight:700; color:#1d2327; line-height:1.1;">
					<?php echo esc_html( number_format_i18n( $total ) ); ?>
				</div>
				<div style="font-size:12px; color:#666; margin-top:4px;"><?php esc_html_e( 'All-time visits', 'wp-pugmill' ); ?></div>
			</div>

			<?php foreach ( $ai_bots as $bot_key => $bot_info ) :
				$count = isset( $summary[ $bot_key ] ) ? $summary[ $bot_key ] : 0;
			?>
			<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:16px 20px; min-width:110px; flex:1; text-align:center; border-top:3px solid <?php echo esc_attr( $bot_info['color'] ); ?>;">
				<div style="font-size:26px; font-weight:700; color:<?php echo esc_attr( $count > 0 ? $bot_info['color'] : '#9ca3af' ); ?>; line-height:1.1;">
					<?php echo esc_html( number_format_i18n( $count ) ); ?>
				</div>
				<div style="font-size:12px; color:#666; margin-top:4px;"><?php echo esc_html( $bot_info['label'] ); ?></div>
				<div style="font-size:11px; color:#9ca3af;"><?php esc_html_e( 'last 30 days', 'wp-pugmill' ); ?></div>
			</div>
			<?php endforeach; ?>

		</div>

		<!-- Search spiders label + cards -->
		<p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; margin:0 0 8px;">
			<?php esc_html_e( 'Search Spiders', 'wp-pugmill' ); ?>
		</p>
		<div style="display:flex; flex-wrap:wrap; gap:14px; margin-bottom:24px;">
			<?php foreach ( $search_bots as $bot_key => $bot_info ) :
				$count = isset( $summary[ $bot_key ] ) ? $summary[ $bot_key ] : 0;
			?>
			<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:12px 16px; min-width:100px; flex:1; text-align:center; border-top:3px solid <?php echo esc_attr( $bot_info['color'] ); ?>;">
				<div style="font-size:22px; font-weight:700; color:<?php echo esc_attr( $count > 0 ? $bot_info['color'] : '#9ca3af' ); ?>; line-height:1.1;">
					<?php echo esc_html( number_format_i18n( $count ) ); ?>
				</div>
				<div style="font-size:11px; color:#666; margin-top:3px;"><?php echo esc_html( $bot_info['label'] ); ?></div>
				<div style="font-size:10px; color:#9ca3af;"><?php esc_html_e( 'last 30 days', 'wp-pugmill' ); ?></div>
			</div>
			<?php endforeach; ?>
		</div>

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
			<div style="display:flex; flex-wrap:wrap; gap:12px 20px; margin-top:14px;">
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

		<!-- Content reach — resource type breakdown ──────────────────────── -->
		<?php
		// Build a flat list of resource type IDs that actually have data
		$active_types = array();
		foreach ( $by_resource as $bot_res ) {
			foreach ( $bot_res as $type_id => $cnt ) {
				if ( $cnt > 0 ) {
					$active_types[ $type_id ] = true;
				}
			}
		}
		ksort( $active_types );
		$bot_keys = array_keys( $bots );
		?>
		<?php if ( ! empty( $active_types ) ) : ?>
		<div style="background:#fff; border:1px solid #ddd; border-radius:8px; padding:20px 24px; margin-bottom:24px;">
			<h3 style="margin:0 0 4px; font-size:14px; font-weight:600;">
				<?php esc_html_e( 'Content Reach', 'wp-pugmill' ); ?>
			</h3>
			<p style="margin:0 0 16px; font-size:12px; color:#666;">
				<?php esc_html_e( 'Which content types each bot is consuming — last 30 days. AEO endpoints show bots reading your optimized content directly.', 'wp-pugmill' ); ?>
			</p>
			<div style="overflow-x:auto;">
			<table class="widefat" style="font-size:12px; border-collapse:collapse;">
				<thead>
					<tr style="background:#f6f7f7;">
						<th style="padding:8px 12px; text-align:left; font-weight:600; white-space:nowrap; width:160px;"><?php esc_html_e( 'Content Type', 'wp-pugmill' ); ?></th>
						<?php foreach ( $bots as $bot_key => $bot_info ) : ?>
						<th style="padding:8px 12px; text-align:center; font-weight:600; white-space:nowrap;">
							<span style="display:inline-flex; align-items:center; gap:5px;">
								<span style="width:8px; height:8px; border-radius:50%; background:<?php echo esc_attr( $bot_info['color'] ); ?>; flex-shrink:0;"></span>
								<?php echo esc_html( $bot_info['label'] ); ?>
							</span>
						</th>
						<?php endforeach; ?>
						<th style="padding:8px 12px; text-align:center; font-weight:600;"><?php esc_html_e( 'Total', 'wp-pugmill' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				// Group rows by category for visual separation
				$cat_order    = array( 'aeo', 'discovery', 'crawl' );
				$cat_labels   = array( 'aeo' => 'AEO Endpoints', 'discovery' => 'Discovery', 'crawl' => 'Page Crawls' );
				$cat_colors   = array( 'aeo' => '#f0fdf4', 'discovery' => '#eff6ff', 'crawl' => '#fafafa' );
				$cat_badge    = array( 'aeo' => '#16a34a', 'discovery' => '#2563eb', 'crawl' => '#9ca3af' );
				$printed_cat  = array();
				foreach ( $cat_order as $cat ) :
					// Collect types in this category that have data
					$cat_types = array();
					foreach ( $active_types as $type_id => $_ ) {
						if ( isset( $resource_cats[ $type_id ] ) && $resource_cats[ $type_id ] === $cat ) {
							$cat_types[] = $type_id;
						}
					}
					if ( empty( $cat_types ) ) continue;
				?>
					<tr style="background:#f6f7f7;">
						<td colspan="<?php echo count( $bots ) + 2; ?>"
						    style="padding:6px 12px;">
							<span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
							      color:<?php echo esc_attr( $cat_badge[ $cat ] ); ?>;">
								<?php echo esc_html( $cat_labels[ $cat ] ); ?>
							</span>
						</td>
					</tr>
					<?php foreach ( $cat_types as $type_id ) :
						$row_total = 0;
					?>
					<tr style="background:<?php echo esc_attr( $cat_colors[ $cat ] ); ?>;">
						<td style="padding:7px 12px; font-weight:500; white-space:nowrap;">
							<?php echo esc_html( $resource_labels[ $type_id ] ?? '' ); ?>
						</td>
						<?php foreach ( $bot_keys as $bot_key ) :
							$cnt = $by_resource[ $bot_key ][ $type_id ] ?? 0;
							$row_total += $cnt;
						?>
						<td style="padding:7px 12px; text-align:center; color:<?php echo $cnt > 0 ? esc_attr( $bots[ $bot_key ]['color'] ) : '#d1d5db'; ?>; font-weight:<?php echo $cnt > 0 ? '600' : '400'; ?>;">
							<?php echo $cnt > 0 ? esc_html( number_format_i18n( $cnt ) ) : '—'; ?>
						</td>
						<?php endforeach; ?>
						<td style="padding:7px 12px; text-align:center; font-weight:600; color:#374151;">
							<?php echo esc_html( number_format_i18n( $row_total ) ); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
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
					<td style="padding:8px 12px; word-break:break-all; font-family:monospace; font-size:11px;">
						<?php if ( $post_row['aeo'] ) : ?>
						<span style="color:#16a34a; font-weight:700; margin-right:4px;" title="<?php esc_attr_e( 'AEO markdown endpoint was read', 'wp-pugmill' ); ?>">★</span>
						<?php endif; ?>
						<?php echo esc_html( $post_row['url'] ); ?>
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
				btn.innerHTML = '<span class="wppugmill-btn-spinner"></span> Analyzing…';
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
						btn.innerHTML = '✨ Get AI Analysis';
						text.innerHTML  = '<span style="color:#dc3232;font-size:13px;"><?php echo esc_js( __( 'Request failed. Please try again.', 'wp-pugmill' ) ); ?></span>';
					} );
			} );
		}() );
		</script>
		<?php endif; ?>

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
