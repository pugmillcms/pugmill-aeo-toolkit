/**
 * WP Pugmill — Custom React hooks for reading/writing post meta.
 *
 * @package WPPugmill
 */

import { useSelect, useDispatch } from '@wordpress/data';
import { useEntityProp }          from '@wordpress/core-data';
import { useState, useCallback, useMemo } from '@wordpress/element';
import { SCHEMA_DEFAULTS } from './constants';

// ── AEO meta ─────────────────────────────────────────────────────────────────

const AEO_DEFAULTS = {
	summary:   '',
	questions: [],
	entities:  [],
	keywords:  [],
};

/**
 * Read and write the _wppugmill_aeo post meta field.
 *
 * @return {{ aeo: Object, updateAeo: Function, postId: number, meta: Object, setMeta: Function }}
 */
export function useAeoMeta() {
	const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
	const postId   = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(),   [] );
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	const aeo = useMemo( () => {
		const raw = meta && meta._wppugmill_aeo;
		if ( ! raw ) return { ...AEO_DEFAULTS };
		try {
			return { ...AEO_DEFAULTS, ...JSON.parse( raw ) };
		} catch {
			return { ...AEO_DEFAULTS };
		}
	}, [ meta ] );

	const updateAeo = useCallback( ( updates ) => {
		const next = { ...aeo, ...updates };
		setMeta( { ...meta, _wppugmill_aeo: JSON.stringify( next ) } );
	}, [ aeo, meta, setMeta ] );

	return { aeo, updateAeo, postId, meta, setMeta };
}

// ── SEO meta ──────────────────────────────────────────────────────────────────

const SEO_DEFAULTS = {
	title:     '',
	meta_desc: '',
	canonical: '',
	noindex:   false,
	nofollow:  false,
	og_title:  '',
	og_desc:   '',
	og_image:  '',
};

/**
 * Read and write the _wppugmill_seo post meta field.
 *
 * @return {{ seo: Object, updateSeo: Function }}
 */
export function useSeoMeta() {
	const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	const seo = useMemo( () => {
		const raw = meta && meta._wppugmill_seo;
		if ( ! raw ) return { ...SEO_DEFAULTS };
		try {
			return { ...SEO_DEFAULTS, ...JSON.parse( raw ) };
		} catch {
			return { ...SEO_DEFAULTS };
		}
	}, [ meta ] );

	const updateSeo = useCallback( ( updates ) => {
		const next = { ...seo, ...updates };
		setMeta( { ...meta, _wppugmill_seo: JSON.stringify( next ) } );
	}, [ seo, meta, setMeta ] );

	return { seo, updateSeo };
}

// ── Schema meta ───────────────────────────────────────────────────────────────

/**
 * Read and write the _wppugmill_schema post meta field.
 *
 * @return {{ schema: Object, updateSchema: Function, updateSchemaType: Function }}
 */
export function useSchemaData() {
	const postType = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostType(), [] );
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	const schema = useMemo( () => {
		const raw = meta && meta._wppugmill_schema;
		if ( ! raw ) return { ...SCHEMA_DEFAULTS };
		try {
			const parsed = JSON.parse( raw );
			return {
				...SCHEMA_DEFAULTS,
				...parsed,
				howto:          { ...SCHEMA_DEFAULTS.howto,          ...( parsed.howto          || {} ) },
				product:        { ...SCHEMA_DEFAULTS.product,        ...( parsed.product        || {} ) },
				event:          { ...SCHEMA_DEFAULTS.event,          ...( parsed.event          || {} ) },
				local_business: { ...SCHEMA_DEFAULTS.local_business, ...( parsed.local_business || {} ) },
				video:          { ...SCHEMA_DEFAULTS.video,          ...( parsed.video          || {} ) },
				review:         { ...SCHEMA_DEFAULTS.review,         ...( parsed.review         || {} ) },
			};
		} catch {
			return { ...SCHEMA_DEFAULTS };
		}
	}, [ meta ] );

	const updateSchema = useCallback( ( updates ) => {
		const next = { ...schema, ...updates };
		setMeta( { ...meta, _wppugmill_schema: JSON.stringify( next ) } );
	}, [ schema, meta, setMeta ] );

	const updateSchemaType = useCallback( ( type, updates ) => {
		updateSchema( { [ type ]: { ...schema[ type ], ...updates } } );
	}, [ schema, updateSchema ] );

	return { schema, updateSchema, updateSchemaType };
}
