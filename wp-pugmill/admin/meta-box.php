<?php
/**
 * Admin meta box — AEO metadata editor on post/page edit screens.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the AEO meta box on all public post types.
 *
 * Suppressed in the block editor — the Gutenberg sidebar handles it there.
 */
function wppugmill_add_meta_box() {
	// Gutenberg sidebar is active — classic meta box not needed.
	if ( wppugmill_is_block_editor() ) {
		return;
	}

	$post_types = get_post_types( array( 'public' => true ) );

	add_meta_box(
		'wppugmill_aeo',
		__( 'AEO — Answer Engine Optimization', 'wp-pugmill' ),
		'wppugmill_render_meta_box',
		array_values( $post_types ),
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'wppugmill_add_meta_box' );

/**
 * Render the AEO meta box.
 */
function wppugmill_render_meta_box( $post ) {
	$aeo = wppugmill_get_aeo( $post->ID );
	wp_nonce_field( 'wppugmill_save_aeo', 'wppugmill_nonce' );

	$summary   = $aeo['summary'];
	$questions = $aeo['questions'];
	$entities  = $aeo['entities'];
	$keywords  = implode( ', ', $aeo['keywords'] );
	$mode      = wppugmill_mode();
	?>
	<div id="wppugmill-meta-box" style="font-family: -apple-system, sans-serif;">

		<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
			<p style="color:#666; margin:0;">
				<?php esc_html_e( 'Help AI engines understand and cite your content.', 'wp-pugmill' ); ?>
				<?php if ( 'free' === $mode ) : ?>
					<?php printf(
						'<a href="%1$s">%2$s</a> %3$s',
						esc_url( admin_url( 'options-general.php?page=wp-pugmill' ) ),
						esc_html__( 'Get an AI Connector license', 'wp-pugmill' ),
						esc_html__( 'to auto-generate these fields.', 'wp-pugmill' )
					); ?>
				<?php endif; ?>
			</p>
			<?php if ( 'ai' === $mode || 'pro' === $mode ) : ?>
				<div style="display:flex; gap:8px; flex-wrap:wrap;">
					<button type="button" id="wppugmill-generate" class="button button-primary" style="display:flex; align-items:center; gap:6px;">
						<span id="wppugmill-generate-label"><?php esc_html_e( '✨ Generate with AI', 'wp-pugmill' ); ?></span>
						<span id="wppugmill-generate-spinner" style="display:none;"><?php esc_html_e( 'Generating…', 'wp-pugmill' ); ?></span>
					</button>
					<button type="button" id="wppugmill-rewrite" class="button" style="display:flex; align-items:center; gap:6px;" title="<?php echo esc_attr__( 'Rewrite draft into AEO Answer Unit structure', 'wp-pugmill' ); ?>">
						<span id="wppugmill-rewrite-label"><?php esc_html_e( '✏ Write from Draft', 'wp-pugmill' ); ?></span>
						<span id="wppugmill-rewrite-spinner" style="display:none;"><?php esc_html_e( 'Rewriting…', 'wp-pugmill' ); ?></span>
					</button>
				</div>
			<?php else : ?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-pugmill' ) ); ?>" class="button" style="color:#666;">
					<?php esc_html_e( '✨ Unlock AI Generation', 'wp-pugmill' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<div id="wppugmill-generate-error" style="display:none; color:#cc1818; margin-bottom:10px; font-size:13px;"></div>
		<div id="wppugmill-rewrite-error" style="display:none; color:#cc1818; margin-bottom:10px; font-size:13px;"></div>
		<div id="wppugmill-rewrite-context" style="display:none; margin-bottom:16px; padding:12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
			<p style="margin:0 0 8px; font-weight:600; font-size:13px;"><?php esc_html_e( '✏ Reformatted Content (Answer Unit body)', 'wp-pugmill' ); ?></p>
			<p style="margin:0 0 8px; font-size:12px; color:#666;"><?php esc_html_e( 'AEO fields above have been populated. Copy the body below into your post:', 'wp-pugmill' ); ?></p>
			<textarea id="wppugmill-rewrite-context-text" rows="8" style="width:100%; font-size:12px;" readonly></textarea>
		</div>

		<!-- Summary -->
		<p><strong><?php esc_html_e( 'AI Summary', 'wp-pugmill' ); ?></strong><br>
		<small><?php esc_html_e( '2–3 sentences describing this content for AI crawlers.', 'wp-pugmill' ); ?></small></p>
		<textarea name="wppugmill_summary" rows="3" style="width:100%;"><?php echo esc_textarea( $summary ); ?></textarea>

		<!-- Q&A Pairs -->
		<p style="margin-top:16px;"><strong><?php esc_html_e( 'Q&amp;A Pairs', 'wp-pugmill' ); ?></strong><br>
		<small><?php esc_html_e( 'Questions readers might ask, with answers. Generates FAQPage schema.', 'wp-pugmill' ); ?></small></p>
		<div id="wppugmill-questions">
			<?php foreach ( $questions as $i => $qa ) : ?>
			<div class="wppugmill-qa-row" style="display:flex; gap:8px; margin-bottom:8px;">
				<input type="text" name="wppugmill_questions[<?php echo absint( $i ); ?>][q]"
					placeholder="<?php echo esc_attr__( 'Question', 'wp-pugmill' ); ?>" value="<?php echo esc_attr( $qa['q'] ?? '' ); ?>"
					style="flex:1;">
				<input type="text" name="wppugmill_questions[<?php echo absint( $i ); ?>][a]"
					placeholder="<?php echo esc_attr__( 'Answer', 'wp-pugmill' ); ?>" value="<?php echo esc_attr( $qa['a'] ?? '' ); ?>"
					style="flex:2;">
				<button type="button" class="button wppugmill-remove-qa"><?php esc_html_e( 'Remove', 'wp-pugmill' ); ?></button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="button" id="wppugmill-add-qa"><?php esc_html_e( '+ Add Q&amp;A', 'wp-pugmill' ); ?></button>

		<!-- Entities -->
		<p style="margin-top:16px;"><strong><?php esc_html_e( 'Named Entities', 'wp-pugmill' ); ?></strong><br>
		<small><?php esc_html_e( 'Key people, places, organizations, products, or concepts mentioned.', 'wp-pugmill' ); ?></small></p>
		<div id="wppugmill-entities">
			<?php foreach ( $entities as $i => $entity ) : ?>
			<div class="wppugmill-entity-row" style="display:flex; gap:8px; margin-bottom:8px;">
				<input type="text" name="wppugmill_entities[<?php echo absint( $i ); ?>][name]"
					placeholder="<?php echo esc_attr__( 'Entity name', 'wp-pugmill' ); ?>" value="<?php echo esc_attr( $entity['name'] ?? '' ); ?>"
					style="flex:2;">
				<select name="wppugmill_entities[<?php echo absint( $i ); ?>][type]" style="flex:1;">
					<?php foreach ( array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' ) as $type ) : ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $entity['type'] ?? 'Thing', $type ); ?>><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="wppugmill_entities[<?php echo absint( $i ); ?>][description]"
					placeholder="<?php echo esc_attr__( 'Description (optional)', 'wp-pugmill' ); ?>" value="<?php echo esc_attr( $entity['description'] ?? '' ); ?>"
					style="flex:2;">
				<button type="button" class="button wppugmill-remove-entity"><?php esc_html_e( 'Remove', 'wp-pugmill' ); ?></button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="button" id="wppugmill-add-entity"><?php esc_html_e( '+ Add Entity', 'wp-pugmill' ); ?></button>

		<!-- Keywords -->
		<p style="margin-top:16px;"><strong><?php esc_html_e( 'Keywords', 'wp-pugmill' ); ?></strong><br>
		<small><?php esc_html_e( 'Comma-separated. 5–15 specific, search-focused terms.', 'wp-pugmill' ); ?></small></p>
		<input type="text" name="wppugmill_keywords" value="<?php echo esc_attr( $keywords ); ?>" style="width:100%;" placeholder="<?php echo esc_attr__( 'e.g. AEO, answer engine optimization, llms.txt', 'wp-pugmill' ); ?>">

	</div>

	<?php
}

/**
 * Save AEO meta box data.
 */
function wppugmill_save_meta_box( $post_id ) {
	if ( ! isset( $_POST['wppugmill_nonce'] ) ) {
		return;
	}
	// wp_unslash() before nonce verification — nonce is alphanumeric so slashes
	// won't affect validity, but this is correct per WPCS for all $_POST access.
	if ( ! wp_verify_nonce( wp_unslash( $_POST['wppugmill_nonce'] ), 'wppugmill_save_aeo' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// wp_unslash() all $_POST data before sanitization.
	// WordPress runs wp_magic_quotes() at boot, adding slashes to $_POST.
	// Sanitizing without unslashing stores "It\'s a draft" instead of "It's a draft".

	// Summary
	$summary = sanitize_textarea_field( wp_unslash( $_POST['wppugmill_summary'] ?? '' ) );

	// Q&A pairs
	$questions = array();
	if ( ! empty( $_POST['wppugmill_questions'] ) && is_array( $_POST['wppugmill_questions'] ) ) {
		foreach ( wp_unslash( $_POST['wppugmill_questions'] ) as $qa ) {
			$q = sanitize_text_field( $qa['q'] ?? '' );
			$a = sanitize_textarea_field( $qa['a'] ?? '' );
			if ( ! empty( $q ) && ! empty( $a ) ) {
				$questions[] = array( 'q' => $q, 'a' => $a );
			}
		}
	}

	// Entities
	$entities      = array();
	$allowed_types = array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' );
	if ( ! empty( $_POST['wppugmill_entities'] ) && is_array( $_POST['wppugmill_entities'] ) ) {
		foreach ( wp_unslash( $_POST['wppugmill_entities'] ) as $entity ) {
			$name = sanitize_text_field( $entity['name'] ?? '' );
			$type = sanitize_text_field( $entity['type'] ?? 'Thing' );
			$desc = sanitize_text_field( $entity['description'] ?? '' );
			if ( $name ) {
				$entities[] = array(
					'name'        => $name,
					'type'        => in_array( $type, $allowed_types, true ) ? $type : 'Thing',
					'description' => $desc,
				);
			}
		}
	}

	// Keywords
	$keywords_raw = sanitize_text_field( wp_unslash( $_POST['wppugmill_keywords'] ?? '' ) );
	$keywords     = array_filter( array_map( 'trim', explode( ',', $keywords_raw ) ) );

	wppugmill_save_aeo( $post_id, array(
		'summary'   => $summary,
		'questions' => $questions,
		'entities'  => $entities,
		'keywords'  => array_values( $keywords ),
	) );
}
add_action( 'save_post', 'wppugmill_save_meta_box' );

/**
 * Enqueue the classic-editor meta box script and pass PHP data to it.
 *
 * Fires on admin_enqueue_scripts so the script is registered before the
 * meta box renders. Only loads on post edit screens running the classic editor.
 *
 * @param string $hook Current admin page hook suffix.
 */
function wppugmill_enqueue_meta_box_script( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	// Block editor handles its own assets via editor-assets.php.
	if ( wppugmill_is_block_editor() ) {
		return;
	}

	$post = get_post();
	if ( ! $post ) {
		return;
	}

	$aeo = wppugmill_get_aeo( $post->ID );

	wp_enqueue_script(
		'wppugmill-meta-box',
		WPPUGMILL_PLUGIN_URL . 'admin/js/meta-box.js',
		array(),
		WPPUGMILL_VERSION,
		true
	);

	wp_localize_script(
		'wppugmill-meta-box',
		'wppugmillMetaBox',
		array(
			'qaCount'      => count( $aeo['questions'] ),
			'entityCount'  => count( $aeo['entities'] ),
			'types'        => array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' ),
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'wppugmill_generate_aeo' ),
			'rewriteNonce' => wp_create_nonce( 'wppugmill_rewrite_draft' ),
			'postId'       => $post->ID,
		)
	);
}
add_action( 'admin_enqueue_scripts', 'wppugmill_enqueue_meta_box_script' );
