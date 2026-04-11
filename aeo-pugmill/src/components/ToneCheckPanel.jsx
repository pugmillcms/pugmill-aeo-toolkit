/**
 * AEO Pugmill — Tone Check panel component.
 *
 * Displays tone-check results and per-passage "Apply Fix" buttons.
 * The fix logic (which accesses the block editor) lives in MainPanel
 * and is passed in as the `onApplyFix` callback.
 *
 * @package WPPugmill
 */

import { PanelBody, Button, Notice } from '@wordpress/components';

import { BUTTON_STYLE } from '../constants';

/**
 * @param {{
 *   open:           boolean,
 *   onToggle:       () => void,
 *   loading:        boolean,
 *   error:          string,
 *   results:        Array<{ issue: string, quote: string, suggestion: string }> | null,
 *   applied:        Record<number, boolean>,
 *   swapErrs:       Record<number, string>,
 *   onCheck:        () => void,
 *   onApplyFix:     (quote: string, suggestion: string, index: number) => void,
 *   onDismissError: () => void,
 *   onDismissAll:   () => void,
 * }} props
 */
export function ToneCheckPanel( {
	open,
	onToggle,
	loading,
	error,
	results,
	applied,
	swapErrs,
	onCheck,
	onApplyFix,
	onDismissError,
	onDismissAll,
	locked,
} ) {
	return (
		<PanelBody title="Tone Check" opened={ open } onToggle={ onToggle }>
			<p style={ { fontSize: '12px', color: '#555', margin: '0 0 10px' } }>
				Checks the post against your <strong>Author Voice guide</strong> and flags passages that deviate.
				Set your voice guide in <strong>Settings → AEO Pugmill</strong>.
			</p>
			{ error && (
				<Notice status="error" isDismissible onDismiss={ onDismissError } style={ { marginBottom: '8px' } }>
					{ error }
				</Notice>
			) }
			{ results !== null && results.length === 0 && (
				<Notice status="success" isDismissible onDismiss={ onDismissAll } style={ { marginBottom: '8px' } }>
					Content matches your voice guide well.
				</Notice>
			) }
			{ results && results.length > 0 && (
				<div style={ { marginBottom: '8px' } }>
					{ results.map( ( item, i ) => (
						<div key={ i } style={ {
							padding:      '8px',
							background:   '#fff8e1',
							borderLeft:   '3px solid ' + ( applied[ i ] ? '#46b450' : '#d97706' ),
							borderRadius: '0 3px 3px 0',
							marginBottom: '6px',
						} }>
							<p style={ { fontSize: '12px', fontWeight: '600', color: '#1e1e1e', margin: '0 0 4px' } }>{ item.issue }</p>
							<div style={ { display: 'flex', alignItems: 'flex-start', gap: '6px', margin: '0 0 4px' } }>
								<p style={ { fontSize: '11px', color: '#555', fontStyle: 'italic', margin: 0, lineHeight: '1.4', flex: 1 } }>
									"{ item.quote }"
								</p>
								<button
									onClick={ () => window.find( item.quote, false, false, true ) }
									style={ { fontSize: '10px', padding: '1px 6px', background: '#f0f0f0', border: '1px solid #ccc', borderRadius: '3px', cursor: 'pointer', whiteSpace: 'nowrap', flexShrink: 0 } }
								>
									Find
								</button>
							</div>
							<p style={ { fontSize: '12px', color: '#1e1e1e', margin: '0 0 6px', lineHeight: '1.4' } }>{ item.suggestion }</p>
							{ applied[ i ] ? (
								<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Applied</span>
							) : (
								<Button
									variant="secondary"
									onClick={ () => onApplyFix( item.quote, item.suggestion, i ) }
									style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
								>
									⇄ Apply Fix
								</Button>
							) }
							{ swapErrs[ i ] && (
								<p style={ { fontSize: '11px', color: '#dc3232', margin: '4px 0 0' } }>{ swapErrs[ i ] }</p>
							) }
						</div>
					) ) }
					<button
						type="button"
						onClick={ onDismissAll }
						style={ { fontSize: '11px', color: '#999', background: 'none', border: 'none', padding: 0, cursor: 'pointer' } }
					>
						Dismiss all
					</button>
				</div>
			) }
			<Button
				variant="secondary"
				isBusy={ loading }
				disabled={ locked || loading }
				onClick={ onCheck }
				style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE, ...( locked ? { opacity: 0.4 } : {} ) } }
			>
				{ loading ? 'Checking…' : '🎨 Check Tone' }
			</Button>
		</PanelBody>
	);
}
