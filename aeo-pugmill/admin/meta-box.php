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
function aeopugmill_add_meta_box() {
	// Gutenberg sidebar is active — classic meta box not needed.
	if ( aeopugmill_is_block_editor() ) {
		return;
	}

	$post_types = get_post_types( array( 'public' => true ) );

	add_meta_box(
		'aeopugmill_aeo',
		__( 'AEO — Answer Engine Optimization', 'aeo-pugmill' ),
		'aeopugmill_render_meta_box',
		array_values( $post_types ),
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'aeopugmill_add_meta_box' );

/**
 * Render the AEO meta box.
 */
function aeopugmill_render_meta_box( $post ) {
	$aeo = aeopugmill_get_aeo( $post->ID );
	wp_nonce_field( 'aeopugmill_save_aeo', 'aeopugmill_nonce' );

	$summary   = $aeo['summary'];
	$questions = $aeo['questions'];
	$entities  = $aeo['entities'];
	$keywords  = implode( ', ', $aeo['keywords'] );
	$mode      = aeopugmill_mode();
	?>
	<div id="aeopugmill-meta-box" style="font-family: -apple-system, sans-serif;">

		<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
			<p style="color:#666; margin:0;">
				<?php esc_html_e( 'Help AI engines understand and cite your content.', 'aeo-pugmill' ); ?>
				<?php if ( 'free' === $mode ) : ?>
					<?php printf(
						'<a href="%1$s">%2$s</a> %3$s',
						esc_url( admin_url( 'options-general.php?page=aeo-pugmill' ) ),
						esc_html__( 'Get an AEO Pugmill Pro license', 'aeo-pugmill' ),
						esc_html__( 'to auto-generate these fields.', 'aeo-pugmill' )
					); ?>
				<?php endif; ?>
			</p>
			<?php if ( 'ai' === $mode || 'pro' === $mode ) : ?>
				<div style="display:flex; gap:8px; flex-wrap:wrap;">
					<button type="button" id="aeopugmill-generate" class="button button-primary" style="display:flex; align-items:center; gap:6px;">
						<span id="aeopugmill-generate-label"><?php esc_html_e( '✨ Generate with AI', 'aeo-pugmill' ); ?></span>
					</button>
					<button type="button" id="aeopugmill-rewrite" class="button" style="display:flex; align-items:center; gap:6px;" title="<?php echo esc_attr__( 'Rewrite draft into AEO Answer Unit structure', 'aeo-pugmill' ); ?>">
						<span id="aeopugmill-rewrite-label"><?php esc_html_e( '✏ Rewrite from Draft', 'aeo-pugmill' ); ?></span>
					</button>
				</div>
			<?php else : ?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=aeo-pugmill' ) ); ?>" class="button" style="color:#666;">
					<?php esc_html_e( '✨ Unlock AI Generation', 'aeo-pugmill' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<div id="aeopugmill-generate-error" style="display:none; color:#cc1818; margin-bottom:10px; font-size:13px;"></div>
		<div id="aeopugmill-rewrite-error" style="display:none; color:#cc1818; margin-bottom:10px; font-size:13px;"></div>
		<div id="aeopugmill-rewrite-context" style="display:none; margin-bottom:16px; padding:12px; background:#f6f7f7; border:1px solid #ddd; border-radius:4px;">
			<p style="margin:0 0 8px; font-weight:600; font-size:13px;"><?php esc_html_e( '✏ Reformatted Content (Answer Unit body)', 'aeo-pugmill' ); ?></p>
			<p style="margin:0 0 8px; font-size:12px; color:#666;"><?php esc_html_e( 'AEO fields above have been populated. Copy the body below into your post:', 'aeo-pugmill' ); ?></p>
			<textarea id="aeopugmill-rewrite-context-text" rows="8" style="width:100%; font-size:12px;" readonly></textarea>
		</div>

		<!-- Summary -->
		<p><strong><?php esc_html_e( 'AI Summary', 'aeo-pugmill' ); ?></strong><br>
		<small><?php esc_html_e( '2–3 sentences describing this content for AI crawlers.', 'aeo-pugmill' ); ?></small></p>
		<textarea name="aeopugmill_summary" rows="3" style="width:100%;"><?php echo esc_textarea( $summary ); ?></textarea>

		<!-- Q&A Pairs -->
		<p style="margin-top:16px;"><strong><?php esc_html_e( 'Q&amp;A Pairs', 'aeo-pugmill' ); ?></strong><br>
		<small><?php esc_html_e( 'Questions readers might ask, with answers. Generates FAQPage schema.', 'aeo-pugmill' ); ?></small></p>
		<div id="aeopugmill-questions">
			<?php foreach ( $questions as $i => $qa ) : ?>
			<div class="aeopugmill-qa-row" style="display:flex; gap:8px; margin-bottom:8px;">
				<input type="text" name="aeopugmill_questions[<?php echo absint( $i ); ?>][q]"
					placeholder="<?php echo esc_attr__( 'Question', 'aeo-pugmill' ); ?>" value="<?php echo esc_attr( $qa['q'] ?? '' ); ?>"
					style="flex:1;">
				<input type="text" name="aeopugmill_questions[<?php echo absint( $i ); ?>][a]"
					placeholder="<?php echo esc_attr__( 'Answer', 'aeo-pugmill' ); ?>" value="<?php echo esc_attr( $qa['a'] ?? '' ); ?>"
					style="flex:2;">
				<button type="button" class="button aeopugmill-remove-qa"><?php esc_html_e( 'Remove', 'aeo-pugmill' ); ?></button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="button" id="aeopugmill-add-qa"><?php esc_html_e( '+ Add Q&amp;A', 'aeo-pugmill' ); ?></button>

		<!-- Entities -->
		<p style="margin-top:16px;"><strong><?php esc_html_e( 'Named Entities', 'aeo-pugmill' ); ?></strong><br>
		<small><?php esc_html_e( 'Key people, places, organizations, products, or concepts mentioned.', 'aeo-pugmill' ); ?></small></p>
		<div id="aeopugmill-entities">
			<?php foreach ( $entities as $i => $entity ) : ?>
			<div class="aeopugmill-entity-row" style="display:flex; gap:8px; margin-bottom:8px;">
				<input type="text" name="aeopugmill_entities[<?php echo absint( $i ); ?>][name]"
					placeholder="<?php echo esc_attr__( 'Entity name', 'aeo-pugmill' ); ?>" value="<?php echo esc_attr( $entity['name'] ?? '' ); ?>"
					style="flex:2;">
				<select name="aeopugmill_entities[<?php echo absint( $i ); ?>][type]" style="flex:1;">
					<?php foreach ( array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' ) as $type ) : ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $entity['type'] ?? 'Thing', $type ); ?>><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="aeopugmill_entities[<?php echo absint( $i ); ?>][description]"
					placeholder="<?php echo esc_attr__( 'Description (optional)', 'aeo-pugmill' ); ?>" value="<?php echo esc_attr( $entity['description'] ?? '' ); ?>"
					style="flex:2;">
				<button type="button" class="button aeopugmill-remove-entity"><?php esc_html_e( 'Remove', 'aeo-pugmill' ); ?></button>
			</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="button" id="aeopugmill-add-entity"><?php esc_html_e( '+ Add Entity', 'aeo-pugmill' ); ?></button>

		<!-- Keywords -->
		<p style="margin-top:16px;"><strong><?php esc_html_e( 'Keywords', 'aeo-pugmill' ); ?></strong><br>
		<small><?php esc_html_e( 'Comma-separated. 5–15 specific, search-focused terms.', 'aeo-pugmill' ); ?></small></p>
		<input type="text" name="aeopugmill_keywords" value="<?php echo esc_attr( $keywords ); ?>" style="width:100%;" placeholder="<?php echo esc_attr__( 'e.g. AEO, answer engine optimization, llms.txt', 'aeo-pugmill' ); ?>">

	</div>

	<?php
}

/**
 * Save AEO meta box data.
 */
function aeopugmill_save_meta_box( $post_id ) {
	if ( ! isset( $_POST['aeopugmill_nonce'] ) ) {
		return;
	}
	// wp_unslash() before nonce verification — nonce is alphanumeric so slashes
	// won't affect validity, but this is correct per WPCS for all $_POST access.
	if ( ! wp_verify_nonce( wp_unslash( $_POST['aeopugmill_nonce'] ), 'aeopugmill_save_aeo' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is validated via wp_verify_nonce; sanitization would corrupt it.
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
	$summary = sanitize_textarea_field( wp_unslash( $_POST['aeopugmill_summary'] ?? '' ) );

	// Q&A pairs
	$questions = array();
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array sanitized per-element inside the foreach.
	if ( ! empty( $_POST['aeopugmill_questions'] ) && is_array( $_POST['aeopugmill_questions'] ) ) {
		foreach ( wp_unslash( $_POST['aeopugmill_questions'] ) as $qa ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array sanitized per-element inside the foreach.
	if ( ! empty( $_POST['aeopugmill_entities'] ) && is_array( $_POST['aeopugmill_entities'] ) ) {
		foreach ( wp_unslash( $_POST['aeopugmill_entities'] ) as $entity ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
	$keywords_raw = sanitize_text_field( wp_unslash( $_POST['aeopugmill_keywords'] ?? '' ) );
	$keywords     = array_filter( array_map( 'trim', explode( ',', $keywords_raw ) ) );

	aeopugmill_save_aeo( $post_id, array(
		'summary'   => $summary,
		'questions' => $questions,
		'entities'  => $entities,
		'keywords'  => array_values( $keywords ),
	) );
}
add_action( 'save_post', 'aeopugmill_save_meta_box' );

/**
 * Enqueue the classic-editor meta box script and pass PHP data to it.
 *
 * Fires on admin_enqueue_scripts so the script is registered before the
 * meta box renders. Only loads on post edit screens running the classic editor.
 *
 * @param string $hook Current admin page hook suffix.
 */
function aeopugmill_enqueue_meta_box_script( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	// Block editor handles its own assets via editor-assets.php.
	if ( aeopugmill_is_block_editor() ) {
		return;
	}

	$post = get_post();
	if ( ! $post ) {
		return;
	}

	$aeo = aeopugmill_get_aeo( $post->ID );

	wp_enqueue_style(
		'aeopugmill-editor-resize',
		AEOPUGMILL_PLUGIN_URL . 'admin/css/editor-resize.css',
		array(),
		AEOPUGMILL_VERSION
	);

	wp_enqueue_script(
		'aeopugmill-meta-box',
		AEOPUGMILL_PLUGIN_URL . 'admin/js/meta-box.js',
		array(),
		AEOPUGMILL_VERSION,
		true
	);

	wp_localize_script(
		'aeopugmill-meta-box',
		'aeopugmillMetaBox',
		array(
			'qaCount'      => count( $aeo['questions'] ),
			'entityCount'  => count( $aeo['entities'] ),
			'types'        => array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' ),
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'aeopugmill_generate_aeo' ),
			'rewriteNonce' => wp_create_nonce( 'aeopugmill_rewrite_draft' ),
			'postId'       => $post->ID,
		)
	);
}
add_action( 'admin_enqueue_scripts', 'aeopugmill_enqueue_meta_box_script' );
