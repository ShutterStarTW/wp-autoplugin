( function ( wp, config ) {
	'use strict';

	const h = wp.element.createElement;
	const { useEffect, useMemo, useState } = wp.element;
	const { Button, Card, CardBody, Notice, SelectControl, Spinner, TextareaControl } = wp.components;
	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const rest = config.restPath;
	const operations = [
		{ label: __( 'Create', 'wp-autoplugin' ), value: 'create' },
		{ label: __( 'Modify', 'wp-autoplugin' ), value: 'modify' },
		{ label: __( 'Fix a bug', 'wp-autoplugin' ), value: 'fix' },
		{ label: __( 'Hook extension', 'wp-autoplugin' ), value: 'hook_extension' },
		{ label: __( 'Fork or copy', 'wp-autoplugin' ), value: 'fork' },
		{ label: __( 'Explain', 'wp-autoplugin' ), value: 'explain' },
	];

	function App() {
		const [ bootstrap, setBootstrap ] = useState( null );
		const [ targets, setTargets ] = useState( [] );
		const [ targetKey, setTargetKey ] = useState( 'new_plugin:new' );
		const [ operation, setOperation ] = useState( 'create' );
		const [ request, setRequest ] = useState( '' );
		const [ job, setJob ] = useState( null );
		const [ activeTab, setActiveTab ] = useState( 'plan' );
		const [ error, setError ] = useState( '' );
		const [ busy, setBusy ] = useState( true );

		useEffect( function () {
			Promise.all( [ apiFetch( { path: rest + '/bootstrap' } ), apiFetch( { path: rest + '/targets' } ) ] )
				.then( function ( response ) {
					setBootstrap( response[ 0 ] );
					setTargets( response[ 1 ].items );
				} )
				.catch( function ( reason ) { setError( reason.message ); } )
				.finally( function () { setBusy( false ); } );
		}, [] );

		useEffect( function () {
			if ( ! job || [ 'completed', 'failed', 'cancelled' ].includes( job.status ) ) {
				return undefined;
			}
			const timer = window.setInterval( function () {
				apiFetch( { path: rest + '/jobs/' + job.id } ).then( setJob ).catch( function ( reason ) { setError( reason.message ); } );
			}, 2000 );
			return function () { window.clearInterval( timer ); };
		}, [ job && job.id, job && job.status ] );

		const target = useMemo( function () {
			return targets.find( function ( item ) { return item.kind + ':' + item.ref === targetKey; } );
		}, [ targets, targetKey ] );
		const allowedOperations = target && target.kind === 'new_plugin'
			? operations.filter( function ( item ) { return item.value === 'create'; } )
			: target && target.kind === 'theme'
				? operations.filter( function ( item ) { return [ 'hook_extension', 'fork', 'explain' ].includes( item.value ); } )
				: operations.filter( function ( item ) { return item.value !== 'create'; } );

		useEffect( function () {
			if ( ! allowedOperations.some( function ( item ) { return item.value === operation; } ) ) {
				setOperation( allowedOperations[ 0 ] ? allowedOperations[ 0 ].value : 'explain' );
			}
		}, [ targetKey ] );

		function start() {
			if ( ! target || ! request.trim() ) return;
			setBusy( true );
			setError( '' );
			apiFetch( {
				path: rest + '/workspaces', method: 'POST', data: {
					target_kind: target.kind, target_ref: target.ref, operation: operation, request: request,
				},
			} ).then( function ( workspace ) {
				return apiFetch( {
					path: rest + '/jobs', method: 'POST', data: {
						workspace_id: workspace.workspace_id,
						task: operation === 'explain' ? 'explain' : 'plan', payload: {},
					},
				} );
			} ).then( function ( created ) {
				setJob( created );
				setActiveTab( operation === 'explain' ? 'review' : 'plan' );
			} ).catch( function ( reason ) {
				setError( reason.message );
			} ).finally( function () { setBusy( false ); } );
		}

		function cancel() {
			if ( ! job ) return;
			apiFetch( { path: rest + '/jobs/' + job.id + '/cancel', method: 'POST' } ).then( setJob ).catch( function ( reason ) { setError( reason.message ); } );
		}

		function importLegacy() {
			setBusy( true );
			apiFetch( { path: rest + '/migration/import', method: 'POST' } )
				.then( function () { return apiFetch( { path: rest + '/bootstrap' } ); } )
				.then( setBootstrap )
				.catch( function ( reason ) { setError( reason.message ); } )
				.finally( function () { setBusy( false ); } );
		}

		if ( busy && ! bootstrap ) {
			return h( 'div', { className: 'wp-autoplugin-v2-loading' }, h( Spinner ), ' ', __( 'Loading workspace…', 'wp-autoplugin' ) );
		}

		const summary = target ? h( 'div', { className: 'target-summary' },
			h( 'h3', null, target.name ), h( 'p', null, target.description ),
			h( 'dl', null,
				stat( __( 'Version', 'wp-autoplugin' ), target.version || '—' ),
				stat( __( 'Source files', 'wp-autoplugin' ), target.source_files ),
				stat( __( 'Lines', 'wp-autoplugin' ), Number( target.lines ).toLocaleString() ),
				stat( __( 'Estimated tokens', 'wp-autoplugin' ), Number( target.tokens ).toLocaleString() ),
				stat( __( 'Hooks', 'wp-autoplugin' ), target.hooks )
			)
		) : null;

		return h( 'main', { className: 'wp-autoplugin-v2' },
			h( 'header', { className: 'wp-autoplugin-v2__header' },
				h( 'div', null, h( 'p', { className: 'eyebrow' }, 'WP-Autoplugin v2' ), h( 'h1', null, __( 'Local AI workspace', 'wp-autoplugin' ) ) ),
				h( 'a', { href: config.settingsUrl }, __( 'Provider settings', 'wp-autoplugin' ) )
			),
			bootstrap && bootstrap.queue.degraded ? h( Notice, { status: 'warning', isDismissible: false }, bootstrap.queue.message ) : null,
			bootstrap && ! bootstrap.migration.completed && bootstrap.migration.tracked_count > 0 ? h( Notice, { status: 'info', isDismissible: false },
				h( 'p', null, sprintf( __( '%d legacy tracked plugins are available to import. No plugin files will be changed.', 'wp-autoplugin' ), bootstrap.migration.tracked_count ) ),
				h( Button, { variant: 'secondary', disabled: busy, onClick: importLegacy }, __( 'Import tracking data', 'wp-autoplugin' ) )
			) : null,
			error ? h( Notice, { status: 'error', onRemove: function () { setError( '' ); } }, error ) : null,
			h( 'div', { className: 'wp-autoplugin-v2__layout' },
				h( Card, null, h( CardBody, null,
					h( 'h2', null, __( 'Start a workspace', 'wp-autoplugin' ) ),
					h( SelectControl, { label: __( 'Target', 'wp-autoplugin' ), value: targetKey, options: targets.map( function ( item ) { return { label: item.name, value: item.kind + ':' + item.ref }; } ), onChange: setTargetKey } ),
					h( SelectControl, { label: __( 'Operation', 'wp-autoplugin' ), value: operation, options: allowedOperations, onChange: setOperation } ),
					summary,
					h( TextareaControl, { label: __( 'What should the AI do?', 'wp-autoplugin' ), value: request, rows: 2, onChange: setRequest, help: __( 'No source files are changed until you approve a staged revision.', 'wp-autoplugin' ) } ),
					h( Button, { variant: 'primary', disabled: busy || ! request.trim(), isBusy: busy, onClick: start }, operation === 'explain' ? __( 'Explain', 'wp-autoplugin' ) : __( 'Create plan', 'wp-autoplugin' ) )
				) ),
				h( 'section', { className: 'workspace-stage' },
					h( 'nav', { className: 'workspace-tabs', 'aria-label': __( 'Workspace stages', 'wp-autoplugin' ) }, [ 'plan', 'code', 'review' ].map( function ( tab ) {
						const label = tab === 'plan' ? __( 'Plan', 'wp-autoplugin' ) : tab === 'code' ? __( 'Code', 'wp-autoplugin' ) : __( 'Review', 'wp-autoplugin' );
						return h( Button, { key: tab, variant: activeTab === tab ? 'primary' : 'tertiary', onClick: function () { setActiveTab( tab ); } }, label );
					} ) ),
					h( Card, null, h( CardBody, null, job ? jobView( job, cancel ) : emptyView( activeTab ) ) )
				)
			)
		);
	}

	function stat( label, value ) {
		return h( 'div', { key: label }, h( 'dt', null, label ), h( 'dd', null, value ) );
	}

	function emptyView( tab ) {
		return h( 'div', { className: 'empty-stage' },
			h( 'h2', null, sprintf( __( '%s stage', 'wp-autoplugin' ), tab.charAt( 0 ).toUpperCase() + tab.slice( 1 ) ) ),
			h( 'p', null, __( 'Start a workspace to create a durable background job. You can navigate away and return while it runs.', 'wp-autoplugin' ) )
		);
	}

	function jobView( job, cancel ) {
		const terminal = [ 'completed', 'failed', 'cancelled' ].includes( job.status );
		return h( 'div', { className: 'job-status' },
			h( 'div', null, h( 'span', { className: 'status status--' + job.status }, job.status ), h( 'strong', null, sprintf( __( 'Job #%d', 'wp-autoplugin' ), job.id ) ) ),
			h( 'progress', { max: 100, value: job.progress } ),
			h( 'p', null, sprintf( __( '%d%% complete', 'wp-autoplugin' ), job.progress ) ),
			job.error_message ? h( Notice, { status: 'error', isDismissible: false }, job.error_message ) : null,
			job.status === 'completed' && job.result ? resultView( job.result ) : null,
			! terminal ? h( Button, { variant: 'secondary', isDestructive: true, onClick: cancel }, __( 'Cancel job', 'wp-autoplugin' ) ) : null
		);
	}

	function resultView( result ) {
		let content;
		if ( result.structured ) {
			content = Object.keys( result.structured ).map( function ( key ) {
				const value = result.structured[ key ];
				return h( 'section', { key: key },
					h( 'h3', null, key.replace( /_/g, ' ' ) ),
					typeof value === 'string' ? h( 'p', null, value ) : h( 'pre', null, JSON.stringify( value, null, 2 ) )
				);
			} );
		} else if ( window.marked && window.DOMPurify ) {
			content = h( 'div', { dangerouslySetInnerHTML: { __html: window.DOMPurify.sanitize( window.marked.parse( result.content || '' ) ) } } );
		} else {
			content = h( 'pre', null, result.content || '' );
		}
		return h( 'div', { className: 'job-result' }, content,
			result.usage ? h( 'small', null, sprintf( __( '%1$d input · %2$d output tokens', 'wp-autoplugin' ), result.usage.input_tokens, result.usage.output_tokens ) ) : null
		);
	}

	const root = document.getElementById( 'wp-autoplugin-v2-root' );
	if ( root ) {
		wp.element.render( h( App ), root );
	}
}( window.wp, window.wpAutopluginV2 ) );
