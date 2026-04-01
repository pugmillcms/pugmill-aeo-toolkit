<?php
/**
 * JSON-LD structured data and social meta tags.
 *
 * Schema output uses a single @graph array per page (one <script> tag),
 * which is the format Google and other parsers prefer.
 *
 * Singular posts/pages:
 *   @graph: Article (posts) | WebPage (pages), FAQPage (when Q&A present), BreadcrumbList
 *   Meta:   title tag handled by on-page-seo.php; description/OG/Twitter output here
 *           using the cascade: _wppugmill_seo fields → AEO summary → excerpt/bloginfo
 *
 * Home / front page:
 *   @graph: WebSite, Organization (when org configured)
 *   Meta:   site-level description/OG/Twitter
 *
 * Note: canonical URL and meta robots are output by on-page-seo.php, not here.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// JSON-LD
// =========================================================================

/**
 * Output JSON-LD structured data on singular and home/front-page views.
 */
function wppugmill_output_json_ld() {
	if ( get_option( 'wppugmill_disable_json_ld' ) ) {
		return;
	}

	if ( is_singular() ) {
		wppugmill_output_singular_json_ld();
	}

	if ( is_home() || is_front_page() ) {
		wppugmill_output_home_json_ld();
	}
}
add_action( 'wp_head', 'wppugmill_output_json_ld' );

/**
 * Single @graph block for singular post/page views.
 *
 * Nodes: Article, FAQPage (conditional), BreadcrumbList
 */
function wppugmill_output_singular_json_ld() {
	$post_id   = get_the_ID();
	$post      = get_post( $post_id );
	$post_type = get_post_type( $post_id );
	$is_page   = ( 'page' === $post_type );
	$aeo       = wppugmill_get_aeo( $post_id );
	$seo       = wppugmill_get_seo( $post_id );

	// Description: SEO meta desc → AEO summary → excerpt
	$description = wppugmill_resolve_description( $seo, $aeo, $post );

	// Pages use WebPage schema; posts (and other types) use BlogPosting.
	// WebPage omits datePublished and author — pages are evergreen site
	// content, not time-stamped editorial pieces.
	$permalink = get_permalink( $post_id );
	$article   = array(
		'@type'        => $is_page ? 'WebPage' : 'BlogPosting',
		'@id'          => $permalink . ( $is_page ? '#webpage' : '#article' ),
		'headline'     => get_the_title( $post_id ),
		'url'          => $permalink,
		'dateModified' => get_the_modified_date( 'c', $post ),
	);

	if ( ! $is_page ) {
		$article['datePublished']    = get_the_date( 'c', $post );
		$article['mainEntityOfPage'] = array( '@type' => 'WebPage', '@id' => $permalink );
		$article['wordCount']        = str_word_count( strip_tags( $post->post_content ) );
		$cats = get_the_category( $post_id );
		if ( $cats ) {
			$article['articleSection'] = $cats[0]->name;
		}
	}

	if ( $description ) {
		$article['description'] = $description;
	}

	// Featured image as ImageObject with dimensions for richer results.
	$thumbnail_id  = get_post_thumbnail_id( $post_id );
	$thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url( (int) $thumbnail_id, 'large' ) : '';
	if ( $thumbnail_url ) {
		$image_node = array( '@type' => 'ImageObject', 'url' => $thumbnail_url );
		$img_meta   = wp_get_attachment_metadata( (int) $thumbnail_id );
		if ( ! empty( $img_meta['sizes']['large'] ) ) {
			$image_node['width']  = $img_meta['sizes']['large']['width'];
			$image_node['height'] = $img_meta['sizes']['large']['height'];
		} elseif ( ! empty( $img_meta['width'] ) ) {
			$image_node['width']  = $img_meta['width'];
			$image_node['height'] = $img_meta['height'];
		}
		$article['image'] = $image_node;
	}

	// Publisher (valid on both BlogPosting and WebPage)
	$org_name  = get_option( 'wppugmill_org_name', '' ) ?: get_bloginfo( 'name' );
	$publisher = array(
		'@type' => get_option( 'wppugmill_org_type', '' ) ?: 'Organization',
		'name'  => $org_name,
	);
	$logo_url = wppugmill_get_site_image_url();
	if ( $logo_url ) {
		$publisher['logo'] = array( '@type' => 'ImageObject', 'url' => $logo_url );
	}
	$article['publisher'] = $publisher;

	// Author — posts only; pages are site-level content, not author-attributed
	if ( ! $is_page && $post->post_author ) {
		$author_id  = (int) $post->post_author;
		$author     = array(
			'@type' => 'Person',
			'name'  => get_the_author_meta( 'display_name', $author_id ),
			'url'   => get_author_posts_url( $author_id ),
		);
		$same_as_raw = get_option( 'wppugmill_author_same_as', '' );
		if ( $same_as_raw ) {
			$same_as = array_filter( array_map( 'trim', explode( "\n", $same_as_raw ) ) );
			if ( ! empty( $same_as ) ) {
				$author['sameAs'] = array_values( $same_as );
			}
		}
		$article['author'] = $author;
	}

	// Entity mentions
	if ( ! empty( $aeo['entities'] ) ) {
		$schema_types    = array(
			'Person'       => 'Person',
			'Organization' => 'Organization',
			'Product'      => 'Product',
			'Place'        => 'Place',
			'Event'        => 'Event',
			'Technology'   => 'SoftwareApplication',
			'DefinedTerm'  => 'DefinedTerm',
			'Thing'        => 'Thing',
		);
		$article['mentions'] = array_map( function( $entity ) use ( $schema_types ) {
			$type = isset( $schema_types[ $entity['type'] ?? '' ] ) ? $schema_types[ $entity['type'] ] : 'Thing';
			$item = array( '@type' => $type, 'name' => $entity['name'] );
			if ( ! empty( $entity['description'] ) ) {
				$item['description'] = $entity['description'];
			}
			if ( ! empty( $entity['same_as'] ) && filter_var( $entity['same_as'], FILTER_VALIDATE_URL ) ) {
				$item['sameAs'] = esc_url_raw( $entity['same_as'] );
			}
			return $item;
		}, $aeo['entities'] );
	}

	// Keywords
	if ( ! empty( $aeo['keywords'] ) ) {
		$article['keywords'] = implode( ', ', $aeo['keywords'] );
	}

	// Citations — auto-extract external links from post content (free tier).
	if ( ! $is_page && ! empty( $post->post_content ) ) {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $post->post_content, $matches );
		$citations = array();
		foreach ( $matches[1] as $idx => $href ) {
			$href = esc_url_raw( trim( $href ) );
			if ( empty( $href ) ) {
				continue;
			}
			$parsed = wp_parse_url( $href );
			// Skip non-http, anchors, and same-domain links.
			if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
				continue;
			}
			if ( ! empty( $parsed['host'] ) && rtrim( $parsed['host'], '.' ) === rtrim( $site_host, '.' ) ) {
				continue;
			}
			// Require meaningful anchor text (not just an icon, image, or URL).
			$anchor = wp_strip_all_tags( $matches[2][ $idx ] );
			$anchor = trim( $anchor );
			if ( empty( $anchor ) || strlen( $anchor ) < 3 || filter_var( $anchor, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$citation = array( '@type' => 'WebPage', 'url' => $href );
			$citation['name'] = $anchor;
			$citations[]      = $citation;
		}
		if ( ! empty( $citations ) ) {
			$article['citation'] = array_values( array_unique( $citations, SORT_REGULAR ) );
		}
	}

	// Build graph
	$graph = array( $article );

	// FAQPage node — only when valid Q&A pairs exist
	$questions = array_filter( $aeo['questions'], function( $q ) {
		return ! empty( $q['q'] ) && ! empty( $q['a'] );
	} );
	if ( ! empty( $questions ) ) {
		$graph[] = array(
			'@type'      => 'FAQPage',
			'@id'        => $permalink . '#faqpage',
			'mainEntity' => array_values( array_map( function( $q ) {
				return array(
					'@type'          => 'Question',
					'name'           => $q['q'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $q['a'],
					),
				);
			}, $questions ) ),
		);
	}

	// Extended schema type node (HowTo, Product, Event, LocalBusiness, VideoObject)
	$extended = wppugmill_build_extended_schema_node( $post_id, $post );
	if ( $extended ) {
		$graph[] = $extended;
	}

	// BreadcrumbList node
	$breadcrumbs = wppugmill_build_breadcrumb_schema( $post_id );
	if ( ! empty( $breadcrumbs['itemListElement'] ) ) {
		$graph[] = $breadcrumbs;
	}

	$output = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
}

/**
 * @graph block for the home page / blog index.
 *
 * Nodes: WebSite, Organization (conditional)
 */
function wppugmill_output_home_json_ld() {
	$org_name    = get_option( 'wppugmill_org_name', '' ) ?: get_bloginfo( 'name' );
	$site_url    = home_url( '/' );
	$description = get_option( 'wppugmill_site_summary', get_bloginfo( 'description' ) );

	$website = array(
		'@type'           => 'WebSite',
		'name'            => $org_name,
		'url'             => $site_url,
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => home_url( '/?s={search_term_string}' ),
			),
			'query-input' => 'required name=search_term_string',
		),
	);
	if ( $description ) {
		$website['description'] = $description;
	}

	$graph = array( $website );

	// Organization node — only when org settings are configured
	if ( get_option( 'wppugmill_org_name', '' ) ) {
		$org = array(
			'@type' => get_option( 'wppugmill_org_type', '' ) ?: 'Organization',
			'name'  => $org_name,
			'url'   => $site_url,
		);
		if ( $description ) {
			$org['description'] = $description;
		}
		$logo_url = wppugmill_get_site_image_url();
		if ( $logo_url ) {
			$org['logo'] = $logo_url;
		}
		$graph[] = $org;
	}

	$output = array(
		'@context' => 'https://schema.org',
		'@graph'   => $graph,
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
}

// =========================================================================
// Breadcrumb schema builder
// =========================================================================

/**
 * Build a BreadcrumbList schema node for a post or page.
 *
 * Posts:  Home > Primary category > Post
 * Pages:  Home > Parent page(s) > Page
 *
 * @param  int   $post_id
 * @return array BreadcrumbList schema node.
 */
function wppugmill_build_breadcrumb_schema( $post_id ) {
	$items    = array();
	$position = 1;

	// Home
	$items[] = array(
		'@type'    => 'ListItem',
		'position' => $position++,
		'name'     => get_bloginfo( 'name' ),
		'item'     => home_url( '/' ),
	);

	$post_type = get_post_type( $post_id );

	if ( 'post' === $post_type ) {
		// Primary category for standard posts
		$cats = get_the_category( $post_id );
		if ( $cats ) {
			$cat     = $cats[0];
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => $cat->name,
				'item'     => get_category_link( $cat->term_id ),
			);
		}
	} elseif ( 'page' === $post_type ) {
		// Ancestor chain for hierarchical pages (root-first)
		$ancestors = array_reverse( get_post_ancestors( $post_id ) );
		foreach ( $ancestors as $ancestor_id ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $position++,
				'name'     => get_the_title( $ancestor_id ),
				'item'     => get_permalink( $ancestor_id ),
			);
		}
	}

	// Current post/page
	$items[] = array(
		'@type'    => 'ListItem',
		'position' => $position,
		'name'     => get_the_title( $post_id ),
		'item'     => get_permalink( $post_id ),
	);

	return array(
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	);
}

// =========================================================================
// Social meta tags (description, Open Graph, Twitter Cards)
// =========================================================================

/**
 * Output meta description, Open Graph, and Twitter Card tags.
 */
function wppugmill_output_meta_tags() {
	if ( is_singular() ) {
		wppugmill_output_singular_meta_tags();
		return;
	}

	if ( is_home() || is_front_page() ) {
		wppugmill_output_home_meta_tags();
	}
}
add_action( 'wp_head', 'wppugmill_output_meta_tags', 2 );

/**
 * Meta tags for singular post/page views.
 *
 * Cascades: _wppugmill_seo fields → AEO summary → post excerpt/title
 */
function wppugmill_output_singular_meta_tags() {
	$post_id = get_the_ID();
	$post    = get_post( $post_id );
	$aeo     = wppugmill_get_aeo( $post_id );
	$seo     = wppugmill_get_seo( $post_id );

	// Title cascade: seo.title → post title
	$seo_title = trim( $seo['title'] ) ?: get_the_title( $post_id );

	// Description cascade: seo.meta_desc → AEO summary → excerpt
	$description = wppugmill_resolve_description( $seo, $aeo, $post );

	// OG title cascade: seo.og_title → seo_title
	$og_title = trim( $seo['og_title'] ) ?: $seo_title;

	// OG description cascade: seo.og_desc → description
	$og_desc = trim( $seo['og_desc'] ) ?: $description;

	// OG image cascade: seo.og_image → featured image
	$og_image = trim( $seo['og_image'] ) ?: get_the_post_thumbnail_url( $post_id, 'large' );

	$url     = get_permalink( $post_id );
	$og_type = ( is_page() && is_front_page() ) ? 'website' : 'article';

	if ( $description ) {
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	}

	echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
	echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	if ( $og_desc ) {
		echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
	}
	// OG image alt: custom OG image → featured image alt from Media Library
	$og_image_alt = '';
	if ( $og_image ) {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			$og_image_alt = trim( get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) );
		}
		echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
		if ( $og_image_alt ) {
			echo '<meta property="og:image:alt" content="' . esc_attr( $og_image_alt ) . '">' . "\n";
		}
	}

	$twitter_card = $og_image ? 'summary_large_image' : 'summary';
	echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . '">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '">' . "\n";
	if ( $og_desc ) {
		echo '<meta name="twitter:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
	}
	if ( $og_image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . '">' . "\n";
		if ( $og_image_alt ) {
			echo '<meta name="twitter:image:alt" content="' . esc_attr( $og_image_alt ) . '">' . "\n";
		}
	}

	// Reading time estimate — surfaces in Twitter/X link previews.
	$word_count   = str_word_count( strip_tags( $post->post_content ) );
	$reading_time = max( 1, (int) round( $word_count / 200 ) );
	echo '<meta name="twitter:label1" content="Reading time">' . "\n";
	echo '<meta name="twitter:data1" content="' . esc_attr( $reading_time . ' min' ) . '">' . "\n";
}

/**
 * Meta tags for the home page / blog index.
 */
function wppugmill_output_home_meta_tags() {
	$org_name    = get_option( 'wppugmill_org_name', '' );
	$title       = $org_name ?: get_bloginfo( 'name' );
	$description = get_option( 'wppugmill_site_summary', get_bloginfo( 'description' ) );
	$url         = home_url( '/' );
	$image_url   = wppugmill_get_site_image_url();

	if ( $description ) {
		echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
	}

	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:type" content="website">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	if ( $description ) {
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	}
	if ( $image_url ) {
		echo '<meta property="og:image" content="' . esc_url( $image_url ) . '">' . "\n";
	}

	$twitter_card = $image_url ? 'summary_large_image' : 'summary';
	echo '<meta name="twitter:card" content="' . esc_attr( $twitter_card ) . '">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( $description ) {
		echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	}
	if ( $image_url ) {
		echo '<meta name="twitter:image" content="' . esc_url( $image_url ) . '">' . "\n";
	}
}

// =========================================================================
// Extended schema types
// =========================================================================

/**
 * Get extended schema data for a post.
 *
 * @param  int $post_id
 * @return array
 */
function wppugmill_get_schema( $post_id ) {
	$defaults = array(
		'type'           => '',
		'howto'          => array( 'description' => '', 'total_time' => '', 'steps' => array() ),
		'product'        => array( 'name' => '', 'description' => '', 'price' => '', 'currency' => 'USD', 'availability' => 'InStock', 'brand' => '' ),
		'event'          => array( 'name' => '', 'description' => '', 'start_date' => '', 'end_date' => '', 'location_name' => '', 'location_address' => '', 'organizer' => '' ),
		'local_business' => array( 'name' => '', 'description' => '', 'address' => '', 'phone' => '', 'hours' => '', 'price_range' => '', 'business_type' => 'LocalBusiness' ),
		'video'          => array( 'name' => '', 'description' => '', 'upload_date' => '', 'duration' => '', 'thumbnail_url' => '', 'embed_url' => '' ),
		'review'         => array( 'item_name' => '', 'item_type' => 'Book', 'item_author' => '', 'rating_value' => '5', 'best_rating' => '5', 'review_body' => '' ),
	);

	$raw = get_post_meta( (int) $post_id, '_wppugmill_schema', true );
	if ( empty( $raw ) ) {
		return $defaults;
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return $defaults;
	}

	// Deep-merge nested type arrays so missing keys always have a default.
	foreach ( array( 'howto', 'product', 'event', 'local_business', 'video', 'review' ) as $key ) {
		if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
			$data[ $key ] = array_merge( $defaults[ $key ], $data[ $key ] );
		}
	}

	return wp_parse_args( $data, $defaults );
}

/**
 * Build the extended schema node for a post, or return null if none selected.
 *
 * The returned node is inserted into the singular @graph array.
 *
 * @param  int     $post_id
 * @param  WP_Post $post
 * @return array|null
 */
function wppugmill_build_extended_schema_node( $post_id, $post ) {
	$schema = wppugmill_get_schema( $post_id );
	$type   = $schema['type'] ?? '';

	switch ( $type ) {
		case 'HowTo':
			return wppugmill_build_howto_node( $post_id, $post, $schema['howto'] );
		case 'Product':
			return wppugmill_build_product_node( $post_id, $post, $schema['product'] );
		case 'Event':
			return wppugmill_build_event_node( $post_id, $post, $schema['event'] );
		case 'LocalBusiness':
			return wppugmill_build_local_business_node( $post_id, $post, $schema['local_business'] );
		case 'VideoObject':
			return wppugmill_build_video_node( $post_id, $post, $schema['video'] );
		case 'Review':
			return wppugmill_build_review_node( $post_id, $post, $schema['review'] );
		default:
			return null;
	}
}

/**
 * HowTo schema node.
 */
function wppugmill_build_howto_node( $post_id, $post, $data ) {
	$node = array(
		'@type' => 'HowTo',
		'name'  => get_the_title( $post_id ),
	);

	$desc = ! empty( $data['description'] ) ? $data['description'] : get_the_excerpt( $post );
	if ( $desc ) {
		$node['description'] = $desc;
	}

	if ( ! empty( $data['total_time'] ) ) {
		$node['totalTime'] = sanitize_text_field( $data['total_time'] );
	}

	$steps = array_filter( $data['steps'] ?? array(), fn( $s ) => ! empty( $s['text'] ) );
	if ( ! empty( $steps ) ) {
		$node['step'] = array_values( array_map( function( $s ) {
			$step = array( '@type' => 'HowToStep', 'text' => $s['text'] );
			if ( ! empty( $s['name'] ) ) {
				$step['name'] = $s['name'];
			}
			return $step;
		}, $steps ) );
	}

	return $node;
}

/**
 * Product schema node.
 */
function wppugmill_build_product_node( $post_id, $post, $data ) {
	$node = array(
		'@type' => 'Product',
		'name'  => ! empty( $data['name'] ) ? $data['name'] : get_the_title( $post_id ),
	);

	$desc = ! empty( $data['description'] ) ? $data['description'] : get_the_excerpt( $post );
	if ( $desc ) {
		$node['description'] = $desc;
	}

	if ( ! empty( $data['brand'] ) ) {
		$node['brand'] = array( '@type' => 'Brand', 'name' => $data['brand'] );
	}

	$image = get_the_post_thumbnail_url( $post_id, 'large' );
	if ( $image ) {
		$node['image'] = $image;
	}

	if ( ! empty( $data['price'] ) ) {
		$avail_map = array(
			'InStock'    => 'https://schema.org/InStock',
			'OutOfStock' => 'https://schema.org/OutOfStock',
			'PreOrder'   => 'https://schema.org/PreOrder',
		);
		$node['offers'] = array(
			'@type'         => 'Offer',
			'price'         => $data['price'],
			'priceCurrency' => ! empty( $data['currency'] ) ? strtoupper( $data['currency'] ) : 'USD',
			'availability'  => $avail_map[ $data['availability'] ] ?? 'https://schema.org/InStock',
		);
	}

	return $node;
}

/**
 * Event schema node.
 */
function wppugmill_build_event_node( $post_id, $post, $data ) {
	$node = array(
		'@type'     => 'Event',
		'name'      => ! empty( $data['name'] ) ? $data['name'] : get_the_title( $post_id ),
		'eventStatus' => 'https://schema.org/EventScheduled',
	);

	$desc = ! empty( $data['description'] ) ? $data['description'] : get_the_excerpt( $post );
	if ( $desc ) {
		$node['description'] = $desc;
	}

	if ( ! empty( $data['start_date'] ) ) {
		$node['startDate'] = $data['start_date'];
	}
	if ( ! empty( $data['end_date'] ) ) {
		$node['endDate'] = $data['end_date'];
	}

	if ( ! empty( $data['location_name'] ) ) {
		$location = array( '@type' => 'Place', 'name' => $data['location_name'] );
		if ( ! empty( $data['location_address'] ) ) {
			$location['address'] = $data['location_address'];
		}
		$node['location'] = $location;
	}

	if ( ! empty( $data['organizer'] ) ) {
		$node['organizer'] = array( '@type' => 'Organization', 'name' => $data['organizer'] );
	}

	return $node;
}

/**
 * LocalBusiness schema node.
 */
function wppugmill_build_local_business_node( $post_id, $post, $data ) {
	$org_name = get_option( 'wppugmill_org_name', '' ) ?: get_bloginfo( 'name' );
	$node = array(
		'@type' => ! empty( $data['business_type'] ) ? $data['business_type'] : 'LocalBusiness',
		'name'  => ! empty( $data['name'] ) ? $data['name'] : $org_name,
		'url'   => get_permalink( $post_id ),
	);

	$desc = ! empty( $data['description'] ) ? $data['description'] : get_the_excerpt( $post );
	if ( $desc ) {
		$node['description'] = $desc;
	}

	if ( ! empty( $data['address'] ) ) {
		$node['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $data['address'] );
	}

	if ( ! empty( $data['phone'] ) ) {
		$node['telephone'] = $data['phone'];
	}

	if ( ! empty( $data['hours'] ) ) {
		$node['openingHours'] = $data['hours'];
	}

	if ( ! empty( $data['price_range'] ) ) {
		$node['priceRange'] = $data['price_range'];
	}

	$logo = wppugmill_get_site_image_url();
	if ( $logo ) {
		$node['logo'] = $logo;
	}

	return $node;
}

/**
 * VideoObject schema node.
 */
function wppugmill_build_video_node( $post_id, $post, $data ) {
	$node = array(
		'@type' => 'VideoObject',
		'name'  => ! empty( $data['name'] ) ? $data['name'] : get_the_title( $post_id ),
	);

	$desc = ! empty( $data['description'] ) ? $data['description'] : get_the_excerpt( $post );
	if ( $desc ) {
		$node['description'] = $desc;
	}

	if ( ! empty( $data['upload_date'] ) ) {
		$node['uploadDate'] = $data['upload_date'];
	}

	if ( ! empty( $data['duration'] ) ) {
		$node['duration'] = $data['duration'];
	}

	$thumb = ! empty( $data['thumbnail_url'] )
		? $data['thumbnail_url']
		: get_the_post_thumbnail_url( $post_id, 'large' );
	if ( $thumb ) {
		$node['thumbnailUrl'] = $thumb;
	}

	if ( ! empty( $data['embed_url'] ) ) {
		$node['embedUrl'] = $data['embed_url'];
	}

	return $node;
}

/**
 * Review schema node.
 */
function wppugmill_build_review_node( $post_id, $post, $data ) {
	$item_type_map = array(
		'Book'                => 'Book',
		'Movie'               => 'Movie',
		'Product'             => 'Product',
		'SoftwareApplication' => 'SoftwareApplication',
		'Course'              => 'Course',
		'Game'                => 'Game',
		'MusicRecording'      => 'MusicRecording',
		'Restaurant'          => 'Restaurant',
		'Thing'               => 'Thing',
	);

	$item_name = ! empty( $data['item_name'] ) ? $data['item_name'] : get_the_title( $post_id );
	$item_type = isset( $item_type_map[ $data['item_type'] ?? '' ] ) ? $data['item_type'] : 'Thing';

	$item = array(
		'@type' => $item_type,
		'name'  => $item_name,
	);
	if ( ! empty( $data['item_author'] ) ) {
		$item['author'] = array( '@type' => 'Person', 'name' => $data['item_author'] );
	}

	$node = array(
		'@type'       => 'Review',
		'@id'         => get_permalink( $post_id ) . '#review',
		'name'        => get_the_title( $post_id ),
		'itemReviewed' => $item,
		'author'      => array(
			'@type' => 'Person',
			'name'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
		),
		'reviewRating' => array(
			'@type'       => 'Rating',
			'ratingValue' => ! empty( $data['rating_value'] ) ? $data['rating_value'] : '5',
			'bestRating'  => ! empty( $data['best_rating'] )  ? $data['best_rating']  : '5',
		),
	);

	if ( ! empty( $data['review_body'] ) ) {
		$node['reviewBody'] = $data['review_body'];
	} else {
		$excerpt = get_the_excerpt( $post );
		if ( $excerpt ) {
			$node['reviewBody'] = $excerpt;
		}
	}

	return $node;
}

// =========================================================================
// Helpers
// =========================================================================

/**
 * Resolve the best available description for a post.
 *
 * Priority: SEO meta desc → AEO summary → post excerpt.
 *
 * @param  array   $seo  _wppugmill_seo data.
 * @param  array   $aeo  _wppugmill_aeo data.
 * @param  WP_Post $post
 * @return string
 */
function wppugmill_resolve_description( $seo, $aeo, $post ) {
	if ( ! empty( $seo['meta_desc'] ) ) {
		return $seo['meta_desc'];
	}
	if ( ! empty( $aeo['summary'] ) ) {
		return $aeo['summary'];
	}
	return get_the_excerpt( $post );
}

/**
 * Retrieve the site-level image URL for OG/schema use.
 *
 * Prefers the WordPress custom logo set via the Customizer.
 *
 * @return string Attachment image URL, or empty string.
 */
function wppugmill_get_site_image_url() {
	$logo_id = get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$url = wp_get_attachment_image_url( (int) $logo_id, 'large' );
		if ( $url ) {
			return $url;
		}
	}
	return '';
}
