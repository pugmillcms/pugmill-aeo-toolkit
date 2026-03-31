/**
 * WP Pugmill — Gutenberg sidebar plugin entry point.
 *
 * Registers the main document-settings panel and the pre-publish panel.
 * Compiled to build/index.js by @wordpress/scripts (webpack + Babel).
 *
 * @package WPPugmill
 */

import { registerPlugin } from '@wordpress/plugins';

import { MainPanel }       from './components/MainPanel';
import { PrePublishPanel } from './components/PrePublishPanel';

registerPlugin( 'wppugmill', {
	render: function() {
		return (
			<>
				<MainPanel />
				<PrePublishPanel />
			</>
		);
	},
} );
