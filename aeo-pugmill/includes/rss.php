<?php
/**
 * AEO-enriched RSS — adds structured AEO data to WordPress RSS 2.0 feeds.
 *
 * Adds a custom XML namespace to the feed and injects per-post AEO elements
 * (summary, entities, Q&A pairs) via standard WordPress RSS hooks. Purely
 * additive — does not modify content:encoded or any existing feed elements.
 *
 * The <aeo:> namespace mirrors the pattern of Dublin Core (dc:) and content
 * (content:) namespaces common in RSS feeds. AI crawlers that consume RSS
 * will have the full AEO metadata alongside the post content.
 *
 * Can be disabled from the Compatibility tab if another plugin is already
 * enriching the feed in a conflicting way.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AEOPUGMILL_RSS_NS', 'https://aeopugmill.com/ns/rss/1.0' );

/**
 * Return true if AEO RSS enrichment is currently enabled.
 */
function aeopugmill_rss_enrichment_enabled() {
	return ! (bool) get_option( 'aeopugmill_disable_rss_enrichment', 0 );
}

/**
 * Add the AEO XML namespace declaration to the RSS 2.0 feed root element.
 *
 * WordPress fires rss2_ns inside the <rss> opening tag, so the output here
 * becomes a namespace attribute on that element.
 */
function aeopugmill_rss_add_namespace() {
	if ( ! aeopugmill_rss_enrichment_enabled() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo 'xmlns:aeo="' . esc_url( AEOPUGMILL_RSS_NS ) . '"' . "\n\t";
}
add_action( 'rss2_ns', 'aeopugmill_rss_add_namespace' );

/**
 * Output AEO custom elements inside each RSS <item>.
 *
 * Elements added per post (only when data exists):
 *   <aeo:summary>    — one-sentence AEO summary
 *   <aeo:entity>     — one element per named entity (name + type attributes)
 *   <aeo:qa>         — one element per Q&A pair, with nested question/answer
 *
 * All text values are wrapped in CDATA so special characters are safe in XML.
 */
function aeopugmill_rss_add_item_data() {
	if ( ! aeopugmill_rss_enrichment_enabled() ) {
		return;
	}

	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return;
	}

	$aeo = aeopugmill_get_aeo( $post_id );

	// Nothing to add for posts without AEO data yet.
	if ( empty( $aeo['summary'] ) && empty( $aeo['entities'] ) && empty( $aeo['questions'] ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

	// Summary
	if ( ! empty( $aeo['summary'] ) ) {
		echo "\t\t<aeo:summary><![CDATA[" . wp_strip_all_tags( $aeo['summary'] ) . "]]></aeo:summary>\n";
	}

	// Named entities — one self-closing element per entity, name + type as attributes
	if ( ! empty( $aeo['entities'] ) && is_array( $aeo['entities'] ) ) {
		foreach ( $aeo['entities'] as $entity ) {
			if ( empty( $entity['name'] ) ) {
				continue;
			}
			$name = esc_attr( $entity['name'] );
			$type = ! empty( $entity['type'] ) ? esc_attr( $entity['type'] ) : 'Thing';
			echo "\t\t<aeo:entity name=\"{$name}\" type=\"{$type}\" />\n";
		}
	}

	// Q&A pairs
	if ( ! empty( $aeo['questions'] ) && is_array( $aeo['questions'] ) ) {
		foreach ( $aeo['questions'] as $qa ) {
			if ( empty( $qa['question'] ) || empty( $qa['answer'] ) ) {
				continue;
			}
			echo "\t\t<aeo:qa>\n";
			echo "\t\t\t<aeo:question><![CDATA[" . wp_strip_all_tags( $qa['question'] ) . "]]></aeo:question>\n";
			echo "\t\t\t<aeo:answer><![CDATA[" . wp_strip_all_tags( $qa['answer'] ) . "]]></aeo:answer>\n";
			echo "\t\t</aeo:qa>\n";
		}
	}

	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'rss2_item', 'aeopugmill_rss_add_item_data' );
