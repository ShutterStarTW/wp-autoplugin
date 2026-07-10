import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { render, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
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
	result?: {
		content?: string;
		structured?: Record< string, unknown >;
		model?: string;
		usage?: { input_tokens: number; output_tokens: number };
	};
	created_at: string;
};

type Bootstrap = {
	queue: { degraded: boolean; message: string };
};

declare global {
	interface Window {
		wpAutopluginV2: { restPath: string; settingsUrl: string };
	}
}

const rest = window.wpAutopluginV2.restPath;
const operations = [
	{
		label: __( 'Create', 'wp-autoplugin' ),
		description: __( 'Start from a blank plugin.', 'wp-autoplugin' ),
		value: 'create',
	},
	{
		label: __( 'Modify', 'wp-autoplugin' ),
		description: __( 'Add or change functionality.', 'wp-autoplugin' ),
		value: 'modify',
	},
	{
		label: __( 'Fix a bug', 'wp-autoplugin' ),
		description: __( 'Diagnose and repair a problem.', 'wp-autoplugin' ),
		value: 'fix',
	},
	{
		label: __( 'Hook extension', 'wp-autoplugin' ),
		description: __( 'Build against discovered hooks.', 'wp-autoplugin' ),
		value: 'hook_extension',
	},
	{
		label: __( 'Fork or copy', 'wp-autoplugin' ),
		description: __( 'Work in a separate copy.', 'wp-autoplugin' ),
		value: 'fork',
	},
	{
		label: __( 'Explain', 'wp-autoplugin' ),
		description: __( 'Ask questions about the source.', 'wp-autoplugin' ),
		value: 'explain',
	},
];

type Workspace = {
	id: number;
	workspace_id?: number;
	project_id: number;
	project_name: string;
	operation: string;
	status: string;
	request: string;
	latest_job_id: number | null;
	latest_job_status: string | null;
	target_kind: string;
	target_ref: string;
	target_metadata: Target;
	updated_at: string;
};

const ACTIVE_WORKSPACE_KEY = 'wp-autoplugin-v2-active-workspace';

function App() {
	const [ bootstrap, setBootstrap ] = useState< Bootstrap | null >( null );
	const [ targets, setTargets ] = useState< Target[] >( [] );
	const [ workspaces, setWorkspaces ] = useState< Workspace[] >( [] );
	const [ activeWorkspaceId, setActiveWorkspaceId ] = useState<
		number | 'new'
	>( 'new' );
	const [ targetKey, setTargetKey ] = useState( 'new_plugin:new' );
	const [ targetSearch, setTargetSearch ] = useState( '' );
	const [ operation, setOperation ] = useState( 'create' );
	const [ request, setRequest ] = useState( '' );
	const [ job, setJob ] = useState< Job | null >( null );
	const [ jobLoading, setJobLoading ] = useState( false );
	const [ activeTab, setActiveTab ] = useState( 'plan' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( true );

	useEffect( () => {
		Promise.all( [
			apiFetch< Bootstrap >( { path: `${ rest }/bootstrap` } ),
			apiFetch< { items: Target[] } >( { path: `${ rest }/targets` } ),
			apiFetch< { items: Workspace[] } >( {
				path: `${ rest }/workspaces`,
			} ),
		] )
			.then( ( [ boot, targetResponse, workspaceResponse ] ) => {
				setBootstrap( boot );
				setTargets( targetResponse.items );
				setWorkspaces( workspaceResponse.items );

				const savedId = Number(
					window.localStorage.getItem( ACTIVE_WORKSPACE_KEY )
				);
				const savedWorkspace = workspaceResponse.items.find(
					( item ) => item.id === savedId
				);
				setActiveWorkspaceId(
					savedWorkspace?.id ??
						workspaceResponse.items[ 0 ]?.id ??
						'new'
				);
			} )
			.catch( ( reason ) => setError( reason.message ) )
			.finally( () => setBusy( false ) );
	}, [] );

	const activeWorkspace = useMemo(
		() =>
			'new' === activeWorkspaceId
				? null
				: workspaces.find(
						( item ) => item.id === activeWorkspaceId
				  ) ?? null,
		[ activeWorkspaceId, workspaces ]
	);

	useEffect( () => {
		if ( 'new' === activeWorkspaceId ) {
			window.localStorage.removeItem( ACTIVE_WORKSPACE_KEY );
			setJob( null );
			setJobLoading( false );
			return;
		}

		window.localStorage.setItem(
			ACTIVE_WORKSPACE_KEY,
			String( activeWorkspaceId )
		);
		setActiveTab(
			'explain' === activeWorkspace?.operation ? 'review' : 'plan'
		);

		if ( ! activeWorkspace?.latest_job_id ) {
			setJob( null );
			setJobLoading( false );
			return;
		}

		let current = true;
		setJobLoading( true );
		apiFetch< Job >( {
			path: `${ rest }/jobs/${ activeWorkspace.latest_job_id }`,
		} )
			.then( ( latestJob ) => {
				if ( current ) {
					setJob( latestJob );
					setWorkspaces( ( items ) =>
						items.map( ( item ) =>
							item.id === latestJob.workspace_id
								? {
										...item,
										latest_job_status: latestJob.status,
								  }
								: item
						)
					);
				}
			} )
			.catch( ( reason ) => {
				if ( current ) {
					setError( reason.message );
				}
			} )
			.finally( () => {
				if ( current ) {
					setJobLoading( false );
				}
			} );

		return () => {
			current = false;
		};
	}, [
		activeWorkspaceId,
		activeWorkspace?.latest_job_id,
		activeWorkspace?.operation,
	] );

	useEffect( () => {
		if (
			! job ||
			[ 'completed', 'failed', 'cancelled' ].includes( job.status )
		) {
			return;
		}
		const timer = window.setInterval( () => {
			apiFetch< Job >( { path: `${ rest }/jobs/${ job.id }` } )
				.then( ( updatedJob ) => {
					setJob( updatedJob );
					setWorkspaces( ( items ) =>
						items.map( ( item ) =>
							item.id === updatedJob.workspace_id
								? {
										...item,
										latest_job_status: updatedJob.status,
								  }
								: item
						)
					);
				} )
				.catch( ( reason ) => setError( reason.message ) );
		}, 2000 );
		return () => window.clearInterval( timer );
	}, [ job ] );

	const target = useMemo(
		() =>
			targets.find(
				( item ) => `${ item.kind }:${ item.ref }` === targetKey
			),
		[ targets, targetKey ]
	);
	const allowedOperations = useMemo( () => {
		if ( target?.kind === 'new_plugin' ) {
			return operations.filter( ( item ) => item.value === 'create' );
		}
		if ( target?.kind === 'theme' ) {
			return operations.filter( ( item ) =>
				[
					'modify',
					'fix',
					'hook_extension',
					'fork',
					'explain',
				].includes( item.value )
			);
		}
		return operations.filter( ( item ) => item.value !== 'create' );
	}, [ target?.kind ] );

	useEffect( () => {
		if (
			! allowedOperations.some( ( item ) => item.value === operation )
		) {
			setOperation( allowedOperations[ 0 ]?.value || 'explain' );
		}
	}, [ allowedOperations, operation ] );

	function selectTargetKind( kind: string ) {
		setTargetSearch( '' );
		const nextTarget = targets.find( ( item ) => item.kind === kind );
		if ( nextTarget ) {
			setTargetKey( `${ nextTarget.kind }:${ nextTarget.ref }` );
		}
	}

	async function start() {
		if ( ! target || ! request.trim() ) {
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			const workspace = await apiFetch< Workspace >( {
				path: `${ rest }/workspaces`,
				method: 'POST',
				data: {
					target_kind: target.kind,
					target_ref: target.ref,
					operation,
					request,
				},
			} );
			const workspaceId = workspace.id || workspace.workspace_id;
			const created = await apiFetch< Job >( {
				path: `${ rest }/jobs`,
				method: 'POST',
				data: {
					workspace_id: workspaceId,
					task: operation === 'explain' ? 'explain' : 'plan',
					payload: {},
				},
			} );
			const openedWorkspace: Workspace = {
				...workspace,
				id: workspaceId as number,
				latest_job_id: created.id,
				latest_job_status: created.status,
			};
			setWorkspaces( ( current ) => [
				openedWorkspace,
				...current.filter( ( item ) => item.id !== openedWorkspace.id ),
			] );
			setActiveWorkspaceId( openedWorkspace.id );
			setJob( created );
			setRequest( '' );
			setActiveTab( operation === 'explain' ? 'review' : 'plan' );
		} catch ( reason: any ) {
			setError( reason.message );
		} finally {
			setBusy( false );
		}
	}

	async function closeWorkspace( workspaceId: number ) {
		try {
			await apiFetch( {
				path: `${ rest }/workspaces/${ workspaceId }/close`,
				method: 'POST',
			} );
			const remaining = workspaces.filter(
				( item ) => item.id !== workspaceId
			);
			setWorkspaces( remaining );
			if ( activeWorkspaceId === workspaceId ) {
				setActiveWorkspaceId( remaining[ 0 ]?.id ?? 'new' );
			}
		} catch ( reason: any ) {
			setError( reason.message );
		}
	}

	async function cancel() {
		if ( ! job ) {
			return;
		}
		const updated = await apiFetch< Job >( {
			path: `${ rest }/jobs/${ job.id }/cancel`,
			method: 'POST',
		} );
		setJob( updated );
		setWorkspaces( ( items ) =>
			items.map( ( item ) =>
				item.id === updated.workspace_id
					? { ...item, latest_job_status: updated.status }
					: item
			)
		);
	}

	if ( busy && ! bootstrap ) {
		return (
			<div className="wp-autoplugin-v2-loading">
				<Spinner /> { __( 'Loading workspace…', 'wp-autoplugin' ) }
			</div>
		);
	}

	let workspaceContent = null;
	if ( 'new' === activeWorkspaceId ) {
		workspaceContent = (
			<WorkspaceLauncher
				targets={ targets }
				target={ target }
				targetKey={ targetKey }
				targetSearch={ targetSearch }
				operation={ operation }
				allowedOperations={ allowedOperations }
				request={ request }
				busy={ busy }
				onTargetSearch={ setTargetSearch }
				onTargetSelect={ setTargetKey }
				onTargetKindSelect={ selectTargetKind }
				onOperationSelect={ setOperation }
				onRequestChange={ setRequest }
				onStart={ start }
			/>
		);
	} else if ( activeWorkspace ) {
		workspaceContent = (
			<WorkspaceView
				workspace={ activeWorkspace }
				job={ job }
				jobLoading={ jobLoading }
				activeTab={ activeTab }
				onTabSelect={ setActiveTab }
				onCancel={ cancel }
			/>
		);
	}

	return (
		<main className="wp-autoplugin-v2">
			<header className="wp-autoplugin-v2__header">
				<div>
					<p className="eyebrow">WP-Autoplugin v2</p>
					<h1>{ __( 'Local AI workspace', 'wp-autoplugin' ) }</h1>
				</div>
				<a href={ window.wpAutopluginV2.settingsUrl }>
					{ __( 'Provider settings', 'wp-autoplugin' ) }
				</a>
			</header>
			{ bootstrap?.queue.degraded && (
				<Notice status="warning" isDismissible={ false }>
					{ bootstrap.queue.message }
				</Notice>
			) }
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<WorkspaceTabBar
				workspaces={ workspaces }
				activeWorkspaceId={ activeWorkspaceId }
				onSelect={ setActiveWorkspaceId }
				onClose={ closeWorkspace }
				onNew={ () => setActiveWorkspaceId( 'new' ) }
			/>
			{ workspaceContent }
		</main>
	);
}

function WorkspaceTabBar( {
	workspaces,
	activeWorkspaceId,
	onSelect,
	onClose,
	onNew,
}: {
	workspaces: Workspace[];
	activeWorkspaceId: number | 'new';
	onSelect: ( id: number ) => void;
	onClose: ( id: number ) => void;
	onNew: () => void;
} ) {
	return (
		<div className="workspace-tab-shell">
			<div
				className="workspace-tab-list"
				role="tablist"
				aria-label={ __( 'Open workspaces', 'wp-autoplugin' ) }
			>
				{ 'new' === activeWorkspaceId && (
					<button
						type="button"
						role="tab"
						aria-selected="true"
						className="workspace-file-tab is-active is-new"
					>
						<span
							className="workspace-file-tab__icon"
							aria-hidden="true"
						>
							+
						</span>
						<strong>
							{ __( 'New workspace', 'wp-autoplugin' ) }
						</strong>
					</button>
				) }
				{ workspaces.map( ( workspace ) => {
					const selected = activeWorkspaceId === workspace.id;
					return (
						<div
							className={ `workspace-file-tab ${
								selected ? 'is-active' : ''
							}` }
							key={ workspace.id }
						>
							<button
								type="button"
								role="tab"
								aria-selected={ selected }
								onClick={ () => onSelect( workspace.id ) }
							>
								<span
									className={ `workspace-file-tab__status status--${
										workspace.latest_job_status || 'draft'
									}` }
									aria-hidden="true"
								/>
								<span className="workspace-file-tab__label">
									<strong>{ workspace.project_name }</strong>
									<small>
										{ getOperationLabel(
											workspace.operation
										) }
									</small>
								</span>
							</button>
							<button
								type="button"
								className="workspace-file-tab__close"
								onClick={ () => onClose( workspace.id ) }
								aria-label={ sprintf(
									/* translators: %s: Workspace name. */
									__( 'Close %s workspace', 'wp-autoplugin' ),
									workspace.project_name
								) }
							>
								×
							</button>
						</div>
					);
				} ) }
				<button
					type="button"
					className="workspace-new-tab"
					onClick={ onNew }
					aria-label={ __( 'Open a new workspace', 'wp-autoplugin' ) }
					title={ __( 'New workspace', 'wp-autoplugin' ) }
				>
					+
				</button>
			</div>
		</div>
	);
}

function WorkspaceLauncher( {
	targets,
	target,
	targetKey,
	targetSearch,
	operation,
	allowedOperations,
	request,
	busy,
	onTargetSearch,
	onTargetSelect,
	onTargetKindSelect,
	onOperationSelect,
	onRequestChange,
	onStart,
}: {
	targets: Target[];
	target: Target | undefined;
	targetKey: string;
	targetSearch: string;
	operation: string;
	allowedOperations: typeof operations;
	request: string;
	busy: boolean;
	onTargetSearch: ( value: string ) => void;
	onTargetSelect: ( value: string ) => void;
	onTargetKindSelect: ( value: string ) => void;
	onOperationSelect: ( value: string ) => void;
	onRequestChange: ( value: string ) => void;
	onStart: () => void;
} ) {
	return (
		<div className="workspace-new-canvas">
			<Card className="workspace-launcher">
				<CardBody>
					<div className="workspace-launcher__heading">
						<p>{ __( 'New workspace', 'wp-autoplugin' ) }</p>
						<h2>
							{ __(
								'What would you like to work on?',
								'wp-autoplugin'
							) }
						</h2>
					</div>
					<TargetPicker
						targets={ targets }
						selectedKey={ targetKey }
						selectedKind={ target?.kind || 'new_plugin' }
						search={ targetSearch }
						onSearch={ onTargetSearch }
						onSelect={ onTargetSelect }
						onSelectKind={ onTargetKindSelect }
					/>
					{ target && <TargetSummary target={ target } /> }
					<OperationPicker
						operations={ allowedOperations }
						selected={ operation }
						onSelect={ onOperationSelect }
					/>
					<div className="workspace-request">
						<TextareaControl
							label={ __(
								'What should the AI do?',
								'wp-autoplugin'
							) }
							value={ request }
							rows={ 2 }
							onChange={ onRequestChange }
							help={ __(
								'No source files are changed until you approve a staged revision.',
								'wp-autoplugin'
							) }
						/>
					</div>
					<Button
						variant="primary"
						disabled={ busy || ! request.trim() }
						isBusy={ busy }
						onClick={ onStart }
					>
						{ operation === 'explain'
							? __( 'Explain', 'wp-autoplugin' )
							: __( 'Create plan', 'wp-autoplugin' ) }
					</Button>
				</CardBody>
			</Card>
		</div>
	);
}

function WorkspaceView( {
	workspace,
	job,
	jobLoading,
	activeTab,
	onTabSelect,
	onCancel,
}: {
	workspace: Workspace;
	job: Job | null;
	jobLoading: boolean;
	activeTab: string;
	onTabSelect: ( tab: string ) => void;
	onCancel: () => void;
} ) {
	const target = workspace.target_metadata;
	return (
		<section className="workspace-editor">
			<header className="workspace-editor__header">
				<div>
					<p>
						{ getOperationLabel( workspace.operation ) } ·{ ' ' }
						{ getTargetKindLabel( workspace.target_kind ) }
					</p>
					<h2>{ workspace.project_name }</h2>
				</div>
				<div className="workspace-editor__metrics">
					<span>{ workspace.status }</span>
					{ target?.version && <span>v{ target.version }</span> }
					{ target?.source_files > 0 && (
						<span>
							{ sprintf(
								/* translators: %d: Number of source files. */
								__( '%d files', 'wp-autoplugin' ),
								target.source_files
							) }
						</span>
					) }
				</div>
			</header>
			<div className="workspace-editor__request">
				<strong>{ __( 'Request', 'wp-autoplugin' ) }</strong>
				<p>{ workspace.request }</p>
			</div>
			<nav
				className="workspace-stage-tabs"
				aria-label={ __( 'Workspace stages', 'wp-autoplugin' ) }
			>
				{ [ 'plan', 'code', 'review' ].map( ( tab ) => (
					<button
						type="button"
						key={ tab }
						className={ activeTab === tab ? 'is-active' : '' }
						onClick={ () => onTabSelect( tab ) }
					>
						{ getTabLabel( tab ) }
					</button>
				) ) }
			</nav>
			<Card className="workspace-editor__panel">
				<CardBody>
					{ jobLoading && (
						<div className="workspace-job-loading">
							<Spinner />{ ' ' }
							{ __( 'Loading job…', 'wp-autoplugin' ) }
						</div>
					) }
					{ ! jobLoading && ! job && (
						<div className="empty-stage">
							<h2>{ getTabLabel( activeTab ) }</h2>
							<p>
								{ __(
									'No job has been started for this stage yet.',
									'wp-autoplugin'
								) }
							</p>
						</div>
					) }
					{ ! jobLoading && job && (
						<JobStatus job={ job } onCancel={ onCancel } />
					) }
				</CardBody>
			</Card>
		</section>
	);
}

function JobStatus( { job, onCancel }: { job: Job; onCancel: () => void } ) {
	const terminal = [ 'completed', 'failed', 'cancelled' ].includes(
		job.status
	);
	return (
		<div className="job-status">
			<div>
				<span className={ `status status--${ job.status }` }>
					{ job.status }
				</span>
				<strong>
					{ sprintf(
						/* translators: %d: Background job ID. */
						__( 'Job #%d', 'wp-autoplugin' ),
						job.id
					) }
				</strong>
			</div>
			<progress max="100" value={ job.progress } />
			<p>
				{ sprintf(
					/* translators: %d: Job completion percentage. */
					__( '%d%% complete', 'wp-autoplugin' ),
					job.progress
				) }
			</p>
			{ job.error_message && (
				<Notice status="error" isDismissible={ false }>
					{ job.error_message }
				</Notice>
			) }
			{ job.status === 'completed' && job.result && (
				<Result result={ job.result } />
			) }
			{ ! terminal && (
				<Button variant="secondary" isDestructive onClick={ onCancel }>
					{ __( 'Cancel job', 'wp-autoplugin' ) }
				</Button>
			) }
		</div>
	);
}

function getOperationLabel( operation: string ) {
	return (
		operations.find( ( item ) => item.value === operation )?.label ??
		operation
	);
}

function getTargetKindLabel( kind: string ) {
	switch ( kind ) {
		case 'plugin':
			return __( 'Plugin', 'wp-autoplugin' );
		case 'theme':
			return __( 'Theme', 'wp-autoplugin' );
		default:
			return __( 'New plugin', 'wp-autoplugin' );
	}
}

function getTabLabel( tab: string ) {
	switch ( tab ) {
		case 'plan':
			return __( 'Plan', 'wp-autoplugin' );
		case 'code':
			return __( 'Code', 'wp-autoplugin' );
		default:
			return __( 'Review', 'wp-autoplugin' );
	}
}

function TargetPicker( {
	targets,
	selectedKey,
	selectedKind,
	search,
	onSearch,
	onSelect,
	onSelectKind,
}: {
	targets: Target[];
	selectedKey: string;
	selectedKind: string;
	search: string;
	onSearch: ( value: string ) => void;
	onSelect: ( value: string ) => void;
	onSelectKind: ( value: string ) => void;
} ) {
	const kinds = [
		{ value: 'new_plugin', label: __( 'New plugin', 'wp-autoplugin' ) },
		{ value: 'plugin', label: __( 'Plugins', 'wp-autoplugin' ) },
		{ value: 'theme', label: __( 'Themes', 'wp-autoplugin' ) },
	];
	const visibleTargets = targets.filter(
		( item ) =>
			item.kind === selectedKind &&
			( ! search ||
				`${ item.name } ${ item.ref }`
					.toLowerCase()
					.includes( search.toLowerCase() ) )
	);
	const searchable = 'new_plugin' !== selectedKind;

	return (
		<section
			className="target-picker"
			aria-labelledby="target-picker-label"
		>
			<div className="picker-heading">
				<h3 id="target-picker-label">
					{ __( 'Choose a target', 'wp-autoplugin' ) }
				</h3>
				{ 'new_plugin' !== selectedKind && (
					<span>
						{ sprintf(
							/* translators: %d: Number of matching targets. */
							_n(
								'%d item',
								'%d items',
								visibleTargets.length,
								'wp-autoplugin'
							),
							visibleTargets.length
						) }
					</span>
				) }
			</div>
			<div className="target-kind-tabs" role="tablist">
				{ kinds.map( ( kind ) => {
					const count = targets.filter(
						( item ) => item.kind === kind.value
					).length;
					return (
						<button
							type="button"
							role="tab"
							aria-selected={ selectedKind === kind.value }
							disabled={ 0 === count }
							className={
								selectedKind === kind.value ? 'is-active' : ''
							}
							onClick={ () => onSelectKind( kind.value ) }
							key={ kind.value }
						>
							<span>{ kind.label }</span>
							{ 'new_plugin' !== kind.value && (
								<small>{ count }</small>
							) }
						</button>
					);
				} ) }
			</div>
			{ searchable && (
				<TextControl
					className="target-search"
					hideLabelFromVision
					label={
						selectedKind === 'plugin'
							? __( 'Search plugins', 'wp-autoplugin' )
							: __( 'Search themes', 'wp-autoplugin' )
					}
					placeholder={
						selectedKind === 'plugin'
							? __( 'Search plugins…', 'wp-autoplugin' )
							: __( 'Search themes…', 'wp-autoplugin' )
					}
					value={ search }
					onChange={ onSearch }
					type="search"
				/>
			) }
			<div
				className={ `target-list ${
					searchable ? '' : 'target-list--single'
				}` }
				role="listbox"
				aria-label={ __( 'Available targets', 'wp-autoplugin' ) }
			>
				{ visibleTargets.map( ( item ) => {
					const key = `${ item.kind }:${ item.ref }`;
					const selected = key === selectedKey;
					return (
						<button
							type="button"
							role="option"
							aria-selected={ selected }
							className={ `target-option ${
								selected ? 'is-selected' : ''
							}` }
							onClick={ () => onSelect( key ) }
							key={ key }
						>
							<span
								className="target-option__marker"
								aria-hidden="true"
							/>
							<span className="target-option__text">
								<strong>{ item.name }</strong>
								<small>
									{ 'new_plugin' === item.kind
										? item.description
										: item.ref }
								</small>
							</span>
							<span className="target-option__meta">
								{ item.active && (
									<em>{ __( 'Active', 'wp-autoplugin' ) }</em>
								) }
								{ item.version && (
									<small>v{ item.version }</small>
								) }
							</span>
						</button>
					);
				} ) }
				{ 0 === visibleTargets.length && (
					<p className="target-list__empty">
						{ __( 'No matching targets.', 'wp-autoplugin' ) }
					</p>
				) }
			</div>
		</section>
	);
}

function TargetSummary( { target }: { target: Target } ) {
	if ( 'new_plugin' === target.kind ) {
		return null;
	}
	return (
		<div className="target-summary">
			<div className="target-summary__heading">
				<div>
					<span>
						{ 'plugin' === target.kind
							? __( 'Selected plugin', 'wp-autoplugin' )
							: __( 'Selected theme', 'wp-autoplugin' ) }
					</span>
					<h3>{ target.name }</h3>
				</div>
				{ target.active && (
					<strong>{ __( 'Active', 'wp-autoplugin' ) }</strong>
				) }
			</div>
			{ target.description && (
				<p title={ target.description }>{ target.description }</p>
			) }
			<dl>
				<div>
					<dt>{ __( 'Files', 'wp-autoplugin' ) }</dt>
					<dd>{ target.source_files }</dd>
				</div>
				<div>
					<dt>{ __( 'Lines', 'wp-autoplugin' ) }</dt>
					<dd>{ target.lines.toLocaleString() }</dd>
				</div>
				<div>
					<dt>{ __( 'Tokens', 'wp-autoplugin' ) }</dt>
					<dd>~{ target.tokens.toLocaleString() }</dd>
				</div>
				<div>
					<dt>{ __( 'Hooks', 'wp-autoplugin' ) }</dt>
					<dd>{ target.hooks }</dd>
				</div>
			</dl>
		</div>
	);
}

function OperationPicker( {
	operations: choices,
	selected,
	onSelect,
}: {
	operations: typeof operations;
	selected: string;
	onSelect: ( value: string ) => void;
} ) {
	return (
		<fieldset
			className={ `operation-picker ${
				1 === choices.length ? 'operation-picker--single' : ''
			}` }
		>
			<legend>{ __( 'Choose an operation', 'wp-autoplugin' ) }</legend>
			<div>
				{ choices.map( ( choice ) => (
					<button
						type="button"
						aria-pressed={ selected === choice.value }
						className={
							selected === choice.value ? 'is-selected' : ''
						}
						onClick={ () => onSelect( choice.value ) }
						key={ choice.value }
					>
						<span
							className="operation-picker__check"
							aria-hidden="true"
						>
							✓
						</span>
						<strong>{ choice.label }</strong>
						<small>{ choice.description }</small>
					</button>
				) ) }
			</div>
		</fieldset>
	);
}

function Result( { result }: { result: NonNullable< Job[ 'result' ] > } ) {
	const usage = result.usage;
	if ( result.structured ) {
		return (
			<div className="job-result">
				{ Object.entries( result.structured ).map(
					( [ key, value ] ) => (
						<section key={ key }>
							<h3>{ key.replace( /_/g, ' ' ) }</h3>
							{ typeof value === 'string' ? (
								<p>{ value }</p>
							) : (
								<pre>{ JSON.stringify( value, null, 2 ) }</pre>
							) }
						</section>
					)
				) }
				{ usage && (
					<small>
						{ sprintf(
							/* translators: 1: Input token count, 2: Output token count. */
							__(
								'%1$d input · %2$d output tokens',
								'wp-autoplugin'
							),
							usage.input_tokens,
							usage.output_tokens
						) }
					</small>
				) }
			</div>
		);
	}
	const marked = ( window as any ).marked;
	const purify = ( window as any ).DOMPurify;
	const html =
		marked && purify
			? purify.sanitize( marked.parse( result.content || '' ) )
			: '';
	return (
		<div className="job-result">
			{ html ? (
				<div dangerouslySetInnerHTML={ { __html: html } } />
			) : (
				<pre>{ result.content }</pre>
			) }
			{ usage && (
				<small>
					{ sprintf(
						/* translators: 1: Input token count, 2: Output token count. */
						__(
							'%1$d input · %2$d output tokens',
							'wp-autoplugin'
						),
						usage.input_tokens,
						usage.output_tokens
					) }
				</small>
			) }
		</div>
	);
}

const root = document.getElementById( 'wp-autoplugin-v2-root' );
if ( root ) {
	render( <App />, root );
}
