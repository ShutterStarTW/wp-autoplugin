import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardBody, Notice, SelectControl, Spinner, TextareaControl } from '@wordpress/components';
import { render, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import './style.scss';

type Target = {
	kind: string;
	ref: string;
	name: string;
	version: string;
	author: string;
	description: string;
	active: boolean;
	source_files: number;
	lines: number;
	tokens: number;
	hooks: number;
};

type Job = {
	id: number;
	status: string;
	progress: number;
	error_message?: string;
	result?: { content?: string; structured?: Record<string, unknown>; model?: string; usage?: { input_tokens: number; output_tokens: number } };
	created_at: string;
};

type Bootstrap = {
	queue: { degraded: boolean; message: string };
	migration: { completed: boolean; tracked_count: number };
};

declare global {
	interface Window {
		wpAutopluginV2: { restPath: string; settingsUrl: string };
	}
}

const rest = window.wpAutopluginV2.restPath;
const operations = [
	{ label: __( 'Create', 'wp-autoplugin' ), value: 'create' },
	{ label: __( 'Modify', 'wp-autoplugin' ), value: 'modify' },
	{ label: __( 'Fix a bug', 'wp-autoplugin' ), value: 'fix' },
	{ label: __( 'Hook extension', 'wp-autoplugin' ), value: 'hook_extension' },
	{ label: __( 'Fork or copy', 'wp-autoplugin' ), value: 'fork' },
	{ label: __( 'Explain', 'wp-autoplugin' ), value: 'explain' },
];

function App() {
	const [bootstrap, setBootstrap] = useState<Bootstrap | null>( null );
	const [targets, setTargets] = useState<Target[]>( [] );
	const [targetKey, setTargetKey] = useState( 'new_plugin:new' );
	const [operation, setOperation] = useState( 'create' );
	const [request, setRequest] = useState( '' );
	const [job, setJob] = useState<Job | null>( null );
	const [activeTab, setActiveTab] = useState( 'plan' );
	const [error, setError] = useState( '' );
	const [busy, setBusy] = useState( true );

	useEffect( () => {
		Promise.all( [
			apiFetch<Bootstrap>( { path: `${ rest }/bootstrap` } ),
			apiFetch<{ items: Target[] }>( { path: `${ rest }/targets` } ),
		] ).then( ( [ boot, response ] ) => {
			setBootstrap( boot );
			setTargets( response.items );
		} ).catch( ( reason ) => setError( reason.message ) ).finally( () => setBusy( false ) );
	}, [] );

	useEffect( () => {
		if ( ! job || [ 'completed', 'failed', 'cancelled' ].includes( job.status ) ) return;
		const timer = window.setInterval( () => {
			apiFetch<Job>( { path: `${ rest }/jobs/${ job.id }` } ).then( setJob ).catch( ( reason ) => setError( reason.message ) );
		}, 2000 );
		return () => window.clearInterval( timer );
	}, [ job?.id, job?.status ] );

	const target = useMemo( () => targets.find( ( item ) => `${ item.kind }:${ item.ref }` === targetKey ), [ targets, targetKey ] );
	const allowedOperations = target?.kind === 'new_plugin'
		? operations.filter( ( item ) => item.value === 'create' )
		: target?.kind === 'theme'
			? operations.filter( ( item ) => [ 'hook_extension', 'fork', 'explain' ].includes( item.value ) )
			: operations.filter( ( item ) => item.value !== 'create' );

	useEffect( () => {
		if ( ! allowedOperations.some( ( item ) => item.value === operation ) ) setOperation( allowedOperations[ 0 ]?.value || 'explain' );
	}, [ targetKey ] );

	async function start() {
		if ( ! target || ! request.trim() ) return;
		setBusy( true );
		setError( '' );
		try {
			const workspace = await apiFetch<{ workspace_id: number }>( {
				path: `${ rest }/workspaces`, method: 'POST', data: {
					target_kind: target.kind, target_ref: target.ref, operation, request,
				},
			} );
			const created = await apiFetch<Job>( {
				path: `${ rest }/jobs`, method: 'POST', data: {
					workspace_id: workspace.workspace_id,
					task: operation === 'explain' ? 'explain' : 'plan',
					payload: {},
				},
			} );
			setJob( created );
			setActiveTab( operation === 'explain' ? 'review' : 'plan' );
		} catch ( reason: any ) {
			setError( reason.message );
		} finally {
			setBusy( false );
		}
	}

	async function cancel() {
		if ( ! job ) return;
		const updated = await apiFetch<Job>( { path: `${ rest }/jobs/${ job.id }/cancel`, method: 'POST' } );
		setJob( updated );
	}

	async function importLegacy() {
		setBusy( true );
		try {
			await apiFetch( { path: `${ rest }/migration/import`, method: 'POST' } );
			const refreshed = await apiFetch<Bootstrap>( { path: `${ rest }/bootstrap` } );
			setBootstrap( refreshed );
		} catch ( reason: any ) {
			setError( reason.message );
		} finally {
			setBusy( false );
		}
	}

	if ( busy && ! bootstrap ) return <div className="wp-autoplugin-v2-loading"><Spinner /> { __( 'Loading workspace…', 'wp-autoplugin' ) }</div>;

	return <main className="wp-autoplugin-v2">
		<header className="wp-autoplugin-v2__header">
			<div><p className="eyebrow">WP-Autoplugin v2</p><h1>{ __( 'Local AI workspace', 'wp-autoplugin' ) }</h1></div>
			<a href={ window.wpAutopluginV2.settingsUrl }>{ __( 'Provider settings', 'wp-autoplugin' ) }</a>
		</header>
		{ bootstrap?.queue.degraded && <Notice status="warning" isDismissible={ false }>{ bootstrap.queue.message }</Notice> }
		{ bootstrap && ! bootstrap.migration.completed && bootstrap.migration.tracked_count > 0 && <Notice status="info" isDismissible={ false }><p>{ sprintf( __( '%d legacy tracked plugins are available to import. No plugin files will be changed.', 'wp-autoplugin' ), bootstrap.migration.tracked_count ) }</p><Button variant="secondary" disabled={ busy } onClick={ importLegacy }>{ __( 'Import tracking data', 'wp-autoplugin' ) }</Button></Notice> }
		{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
		<div className="wp-autoplugin-v2__layout">
			<Card><CardBody>
				<h2>{ __( 'Start a workspace', 'wp-autoplugin' ) }</h2>
				<SelectControl label={ __( 'Target', 'wp-autoplugin' ) } value={ targetKey } options={ targets.map( ( item ) => ( { label: item.name, value: `${ item.kind }:${ item.ref }` } ) ) } onChange={ setTargetKey } />
				<SelectControl label={ __( 'Operation', 'wp-autoplugin' ) } value={ operation } options={ allowedOperations } onChange={ setOperation } />
				{ target && <div className="target-summary">
					<h3>{ target.name }</h3><p>{ target.description }</p>
					<dl><div><dt>{ __( 'Version', 'wp-autoplugin' ) }</dt><dd>{ target.version || '—' }</dd></div><div><dt>{ __( 'Source files', 'wp-autoplugin' ) }</dt><dd>{ target.source_files }</dd></div><div><dt>{ __( 'Lines', 'wp-autoplugin' ) }</dt><dd>{ target.lines.toLocaleString() }</dd></div><div><dt>{ __( 'Estimated tokens', 'wp-autoplugin' ) }</dt><dd>{ target.tokens.toLocaleString() }</dd></div><div><dt>{ __( 'Hooks', 'wp-autoplugin' ) }</dt><dd>{ target.hooks }</dd></div></dl>
				</div> }
				<TextareaControl label={ __( 'What should the AI do?', 'wp-autoplugin' ) } value={ request } rows={ 2 } onChange={ setRequest } help={ __( 'No source files are changed until you approve a staged revision.', 'wp-autoplugin' ) } />
				<Button variant="primary" disabled={ busy || ! request.trim() } isBusy={ busy } onClick={ start }>{ operation === 'explain' ? __( 'Explain', 'wp-autoplugin' ) : __( 'Create plan', 'wp-autoplugin' ) }</Button>
			</CardBody></Card>
			<section className="workspace-stage">
				<nav className="workspace-tabs" aria-label={ __( 'Workspace stages', 'wp-autoplugin' ) }>{ [ 'plan', 'code', 'review' ].map( ( tab ) => <Button key={ tab } variant={ activeTab === tab ? 'primary' : 'tertiary' } onClick={ () => setActiveTab( tab ) }>{ tab === 'plan' ? __( 'Plan', 'wp-autoplugin' ) : tab === 'code' ? __( 'Code', 'wp-autoplugin' ) : __( 'Review', 'wp-autoplugin' ) }</Button> ) }</nav>
				<Card><CardBody>
					{ ! job && <div className="empty-stage"><h2>{ sprintf( __( '%s stage', 'wp-autoplugin' ), activeTab.charAt( 0 ).toUpperCase() + activeTab.slice( 1 ) ) }</h2><p>{ __( 'Start a workspace to create a durable background job. You can navigate away and return while it runs.', 'wp-autoplugin' ) }</p></div> }
					{ job && <div className="job-status"><div><span className={ `status status--${ job.status }` }>{ job.status }</span><strong>{ sprintf( __( 'Job #%d', 'wp-autoplugin' ), job.id ) }</strong></div><progress max="100" value={ job.progress } /><p>{ sprintf( __( '%d%% complete', 'wp-autoplugin' ), job.progress ) }</p>{ job.error_message && <Notice status="error" isDismissible={ false }>{ job.error_message }</Notice> }{ job.status === 'completed' && job.result && <Result result={ job.result } /> }{ ! [ 'completed', 'failed', 'cancelled' ].includes( job.status ) && <Button variant="secondary" isDestructive onClick={ cancel }>{ __( 'Cancel job', 'wp-autoplugin' ) }</Button> }</div> }
				</CardBody></Card>
			</section>
		</div>
	</main>;
}

function Result( { result }: { result: NonNullable<Job['result']> } ) {
	const usage = result.usage;
	if ( result.structured ) {
		return <div className="job-result">{ Object.entries( result.structured ).map( ( [ key, value ] ) => <section key={ key }><h3>{ key.replace( /_/g, ' ' ) }</h3>{ typeof value === 'string' ? <p>{ value }</p> : <pre>{ JSON.stringify( value, null, 2 ) }</pre> }</section> ) }{ usage && <small>{ sprintf( __( '%1$d input · %2$d output tokens', 'wp-autoplugin' ), usage.input_tokens, usage.output_tokens ) }</small> }</div>;
	}
	const marked = ( window as any ).marked;
	const purify = ( window as any ).DOMPurify;
	const html = marked && purify ? purify.sanitize( marked.parse( result.content || '' ) ) : '';
	return <div className="job-result">{ html ? <div dangerouslySetInnerHTML={ { __html: html } } /> : <pre>{ result.content }</pre> }{ usage && <small>{ sprintf( __( '%1$d input · %2$d output tokens', 'wp-autoplugin' ), usage.input_tokens, usage.output_tokens ) }</small> }</div>;
}

const root = document.getElementById( 'wp-autoplugin-v2-root' );
if ( root ) render( <App />, root );
