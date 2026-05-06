/**
 * Pugmill AEO Toolkit — Gutenberg sidebar plugin entry point.
 *
 * Registers the main document-settings panel and the pre-publish panel.
 * Compiled to build/index.js by @wordpress/scripts (webpack + Babel).
 *
 * @package WPPugmill
 */

import { registerPlugin } from '@wordpress/plugins';
import { Component }      from '@wordpress/element';

import { MainPanel }       from './components/MainPanel';
import { PrePublishPanel } from './components/PrePublishPanel';

/**
 * Catches JavaScript errors anywhere in the Pugmill panel tree and renders
 * a minimal fallback so a crash doesn't blank the entire Document sidebar.
 */
class PugmillErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	static getDerivedStateFromError( error ) {
		return { error };
	}

	render() {
		if ( this.state.error ) {
			return (
				<div style={ { padding: '12px 16px', color: '#dc3232', fontSize: '12px', lineHeight: '1.5' } }>
					<strong>Pugmill AEO Toolkit</strong> encountered an error and could not render.
					Please reload the editor. If this persists, check the browser console for details.
				</div>
			);
		}
		return this.props.children;
	}
}

registerPlugin( 'aeopugmill', {
	render: function() {
		return (
			<PugmillErrorBoundary>
				<MainPanel />
				<PrePublishPanel />
			</PugmillErrorBoundary>
		);
	},
} );
