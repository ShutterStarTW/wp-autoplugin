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
import {
	render,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
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
	workspace_id: number;
	task: string;
	status: string;
	progress: number;
	error_message?: string;
	payload: {
		message?: string;
		stage?: string;
		artifact_job_id?: number;
		mode?: 'generate' | 'regenerate';
		plan_artifact_job_id?: number;
		parent_revision_id?: number;
		revision_id?: number;
		expected_latest_revision_id?: number | null;
	};
	result?: {
		content?: string;
		structured?: Record< string, unknown >;
		outcome?: 'answer' | 'artifact' | 'revision';
		artifact?: {
			type?: string;
			content?: string;
			parent_job_id?: number;
		};
		model?: string;
		provider?: string;
		effort?: string;
		revision_id?: number;
		base_revision_id?: number;
		added_paths?: string[];
		updated_paths?: string[];
		deleted_paths?: string[];
		plan_artifact_job_id?: number;
		parent_revision_id?: number | null;
		files_count?: number;
		usage?: { input_tokens: number; output_tokens: number };
		agent?: {
			model_turns: number;
			tool_calls: number;
			source_bytes: number;
		};
	};
	created_at: string;
	latest_event?: {
		event: string;
		message: string;
		level: string;
		sequence: number;
	} | null;
	code_progress?: CodeProgress | null;
};

type CodeProgress = {
	mode?: 'generate' | 'regenerate' | 'follow_up';
	phase?: 'analysis' | 'files' | 'completed' | 'failed' | 'cancelled';
	outcome?: 'answer' | 'revision' | null;
	total: number;
	completed: number;
	current: number;
	provider: string;
	model: string;
	effort: string;
	input_tokens: number;
	output_tokens: number;
	deleted_paths?: string[];
	files: Array< {
		path: string;
		type: string;
		operation: 'add' | 'update' | 'delete';
		status: 'pending' | 'generating' | 'completed' | 'failed';
		error?: string;
	} >;
};

type RevisionSummary = {
	id: number;
	workspace_id: number;
	revision_number: number;
	status: string;
	origin: 'ai' | 'manual' | 'restore';
	plan_job_id: number | null;
	source_job_id: number | null;
	parent_revision_id: number | null;
	restored_from_revision_id: number | null;
	files_count: number;
	aggregate_size: number;
	adds: number;
	updates: number;
	deletes: number;
	created_at: string;
};

type RevisionFileManifest = {
	id: number;
	path: string;
	type: string;
	change_type: 'add' | 'update' | 'delete' | null;
	content_hash: string;
	size: number;
};

type RevisionManifest = RevisionSummary & {
	files: RevisionFileManifest[];
	project_manifest: {
		scope?: 'project' | 'changes';
		artifact_kind?: 'plugin' | 'theme';
		operation?: string;
		plugin_name: string;
		main_file: string;
		files: Array< {
			path: string;
			type: string;
			description: string;
			operation?: 'add' | 'update' | 'delete';
		} >;
	} | null;
	plan_structure_matches: boolean;
	validation: { status: string; issues: CodeIssue[] };
	target_files?: RevisionFileManifest[];
	target_directories?: string[];
	target_tree_error?: string;
};

type RevisionFile = RevisionFileManifest & {
	revision_id: number;
	content: string;
	diff_html: string;
};

function revisionVisibleFiles(
	manifest: RevisionManifest
): RevisionFileManifest[] {
	const files = new Map< string, RevisionFileManifest >();
	( manifest.target_files ?? [] ).forEach( ( file ) =>
		files.set( file.path, file )
	);
	manifest.files.forEach( ( file ) => files.set( file.path, file ) );
	return [ ...files.values() ].sort( ( left, right ) =>
		left.path.localeCompare( right.path )
	);
}

type CodeIssue = { path: string; line: number; code: string; message: string };

type JobEvent = {
	id: number;
	sequence: number;
	level: string;
	event: string;
	message: string;
	context: Record< string, unknown >;
	created_at: string;
};

type PlanSaveResponse = {
	artifact: Job;
	regeneration_job: Job | null;
};

type Bootstrap = {
	queue: { degraded: boolean; message: string };
	explain_agent: AgentCapability;
	plan_agent: AgentCapability;
	direct_plan: AgentCapability;
	direct_code: AgentCapability;
};

type AgentCapability = {
	available: boolean;
	provider: string;
	model: string;
	message: string;
};

declare global {
	interface Window {
		wpAutopluginV2: {
			restPath: string;
			settingsUrl: string;
			codeEditorSettings?: Record< string, Record< string, unknown > >;
		};
		wp?: {
			codeEditor?: {
				initialize: (
					element: HTMLTextAreaElement,
					settings: Record< string, unknown >
				) => { codemirror?: any };
			};
		};
	}
}

const rest = window.wpAutopluginV2.restPath;
const operations = [
	{
		label: __( 'Create', 'wp-autoplugin' ),
		description: __( 'Start from a blank plugin.', 'wp-autoplugin' ),
		requestLabel: __( 'What should the plugin do?', 'wp-autoplugin' ),
		value: 'create',
	},
	{
		label: __( 'Modify', 'wp-autoplugin' ),
		description: __( 'Add or change functionality.', 'wp-autoplugin' ),
		requestLabel: __( 'What would you like to change?', 'wp-autoplugin' ),
		value: 'modify',
	},
	{
		label: __( 'Fix a bug', 'wp-autoplugin' ),
		description: __( 'Diagnose and repair a problem.', 'wp-autoplugin' ),
		requestLabel: __( 'What problem should the AI fix?', 'wp-autoplugin' ),
		value: 'fix',
	},
	{
		label: __( 'Create extension', 'wp-autoplugin' ),
		description: __( 'Build against discovered hooks.', 'wp-autoplugin' ),
		requestLabel: __( 'What should the extension do?', 'wp-autoplugin' ),
		value: 'hook_extension',
	},
	{
		label: __( 'Explain', 'wp-autoplugin' ),
		description: __( 'Ask questions about the source.', 'wp-autoplugin' ),
		requestLabel: __( 'What would you like to know?', 'wp-autoplugin' ),
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
const ACTIVE_STAGE_KEY_PREFIX = 'wp-autoplugin-v2-active-stage:';

function workspaceStages( operation?: string ): string[] {
	return operation === 'explain'
		? [ 'explain' ]
		: [ 'plan', 'code', 'review' ];
}

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
	const [ jobs, setJobs ] = useState< Job[] >( [] );
	const [ jobsLoading, setJobsLoading ] = useState( false );
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
		if (
			! activeWorkspace ||
			activeWorkspace.target_kind !== 'new_plugin' ||
			activeWorkspace.operation !== 'create'
		) {
			return;
		}

		const plan = latestPlanArtifact( jobs );
		const structure = plan ? planArtifactStructure( plan ) : null;
		const pluginName =
			typeof structure?.plugin_name === 'string'
				? structure.plugin_name.trim()
				: '';
		if ( ! pluginName || pluginName === activeWorkspace.project_name ) {
			return;
		}

		setWorkspaces( ( items ) =>
			items.map( ( item ) =>
				item.id === activeWorkspace.id
					? { ...item, project_name: pluginName }
					: item
			)
		);
	}, [ jobs, activeWorkspace ] );

	useEffect( () => {
		if ( 'new' === activeWorkspaceId ) {
			window.localStorage.removeItem( ACTIVE_WORKSPACE_KEY );
			setJobs( [] );
			setJobsLoading( false );
			return;
		}

		window.localStorage.setItem(
			ACTIVE_WORKSPACE_KEY,
			String( activeWorkspaceId )
		);
		const availableStages = workspaceStages( activeWorkspace?.operation );
		const activeProjectId = activeWorkspace?.project_id;
		const savedStage = activeProjectId
			? window.localStorage.getItem(
					`${ ACTIVE_STAGE_KEY_PREFIX }${ activeProjectId }`
			  )
			: null;
		setActiveTab(
			savedStage && availableStages.includes( savedStage )
				? savedStage
				: availableStages[ 0 ]
		);

		let current = true;
		setJobsLoading( true );
		apiFetch< { items: Job[] } >( {
			path: `${ rest }/workspaces/${ activeWorkspaceId }/jobs`,
		} )
			.then( ( response ) => {
				if ( current ) {
					setJobs( response.items );
				}
			} )
			.catch( ( reason ) => {
				if ( current ) {
					setError( reason.message );
				}
			} )
			.finally( () => {
				if ( current ) {
					setJobsLoading( false );
				}
			} );

		return () => {
			current = false;
		};
	}, [
		activeWorkspaceId,
		activeWorkspace?.operation,
		activeWorkspace?.project_id,
	] );

	function selectWorkspaceStage( stage: string ) {
		if (
			! workspaceStages( activeWorkspace?.operation ).includes( stage )
		) {
			return;
		}
		setActiveTab( stage );
		if ( activeWorkspace ) {
			window.localStorage.setItem(
				`${ ACTIVE_STAGE_KEY_PREFIX }${ activeWorkspace.project_id }`,
				stage
			);
		}
	}

	useEffect( () => {
		const activeJobs = jobs.filter( ( item ) =>
			[ 'queued', 'running', 'retrying' ].includes( item.status )
		);
		if ( ! activeJobs.length ) {
			return;
		}
		const timer = window.setInterval( () => {
			Promise.all(
				activeJobs.map( ( item ) =>
					apiFetch< Job >( { path: `${ rest }/jobs/${ item.id }` } )
				)
			)
				.then( ( updatedJobs ) => {
					setJobs( ( items ) =>
						items.map(
							( item ) =>
								updatedJobs.find(
									( updated ) => updated.id === item.id
								) ?? item
						)
					);
					const latestJob = updatedJobs[ updatedJobs.length - 1 ];
					if ( latestJob ) {
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
				.catch( ( reason ) => setError( reason.message ) );
		}, 2000 );
		return () => window.clearInterval( timer );
	}, [ jobs ] );

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
				[ 'modify', 'fix', 'hook_extension', 'explain' ].includes(
					item.value
				)
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
					payload:
						operation === 'explain' ? { message: request } : {},
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
			setJobs( [ created ] );
			setRequest( '' );
			setActiveTab( operation === 'explain' ? 'explain' : 'plan' );
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

	async function cancel( job: Job ) {
		const updated = await apiFetch< Job >( {
			path: `${ rest }/jobs/${ job.id }/cancel`,
			method: 'POST',
		} );
		setJobs( ( items ) =>
			items.map( ( item ) => ( item.id === updated.id ? updated : item ) )
		);
		setWorkspaces( ( items ) =>
			items.map( ( item ) =>
				item.id === updated.workspace_id
					? { ...item, latest_job_status: updated.status }
					: item
			)
		);
	}

	async function createJob(
		task: 'plan' | 'code' | 'explain' | 'conversation',
		payload = {}
	) {
		if ( ! activeWorkspace ) {
			return null;
		}
		setError( '' );
		try {
			const created = await apiFetch< Job >( {
				path: `${ rest }/jobs`,
				method: 'POST',
				data: { workspace_id: activeWorkspace.id, task, payload },
			} );
			setJobs( ( items ) => [ ...items, created ] );
			setWorkspaces( ( items ) =>
				items.map( ( item ) =>
					item.id === activeWorkspace.id
						? {
								...item,
								latest_job_id: created.id,
								latest_job_status: created.status,
						  }
						: item
				)
			);
			return created;
		} catch ( reason: any ) {
			setError( reason.message );
			return null;
		}
	}

	async function savePlan( job: Job, content: string ) {
		try {
			const saved = await apiFetch< PlanSaveResponse >( {
				path: `${ rest }/jobs/${ job.id }/plan`,
				method: 'POST',
				data: { content },
			} );
			setJobs( ( items ) => [
				...items,
				saved.artifact,
				...( saved.regeneration_job ? [ saved.regeneration_job ] : [] ),
			] );
			setWorkspaces( ( items ) =>
				items.map( ( item ) =>
					item.id === saved.artifact.workspace_id
						? {
								...item,
								latest_job_id:
									saved.regeneration_job?.id ??
									saved.artifact.id,
								latest_job_status:
									saved.regeneration_job?.status ??
									saved.artifact.status,
						  }
						: item
				)
			);
			return true;
		} catch ( reason: any ) {
			setError( reason.message );
			return false;
		}
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
				explainCapability={ bootstrap?.explain_agent ?? null }
				planCapability={ bootstrap?.plan_agent ?? null }
				directPlanCapability={ bootstrap?.direct_plan ?? null }
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
				jobs={ jobs }
				jobsLoading={ jobsLoading }
				codeCapability={ bootstrap?.direct_code ?? null }
				activeTab={ activeTab }
				onTabSelect={ selectWorkspaceStage }
				onCancel={ cancel }
				onCreateJob={ createJob }
				onSavePlan={ savePlan }
			/>
		);
	}

	return (
		<main className="wp-autoplugin-v2">
			<header className="wp-autoplugin-v2__header">
				<div>
					<p className="eyebrow">WP-Autoplugin</p>
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
	explainCapability,
	planCapability,
	directPlanCapability,
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
	explainCapability: AgentCapability | null;
	planCapability: AgentCapability | null;
	directPlanCapability: AgentCapability | null;
	onTargetSearch: ( value: string ) => void;
	onTargetSelect: ( value: string ) => void;
	onTargetKindSelect: ( value: string ) => void;
	onOperationSelect: ( value: string ) => void;
	onRequestChange: ( value: string ) => void;
	onStart: () => void;
} ) {
	const requiresPlan = !! target && operation !== 'explain';
	const effectivePlanCapability =
		target?.kind === 'new_plugin' ? directPlanCapability : planCapability;
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
					{ operation === 'explain' &&
						explainCapability &&
						! explainCapability.available && (
							<Notice status="warning" isDismissible={ false }>
								{ explainCapability.message }
							</Notice>
						) }
					{ requiresPlan &&
						effectivePlanCapability &&
						! effectivePlanCapability.available && (
							<Notice status="warning" isDismissible={ false }>
								{ effectivePlanCapability.message }
							</Notice>
						) }
					<div className="workspace-request">
						<TextareaControl
							label={
								operations.find(
									( item ) => item.value === operation
								)?.requestLabel ??
								__( 'What should the AI do?', 'wp-autoplugin' )
							}
							value={ request }
							rows={ 2 }
							onChange={ onRequestChange }
							help={
								target?.kind === 'new_plugin'
									? __(
											'No source files are changed until you approve a staged revision.',
											'wp-autoplugin'
									  )
									: __(
											'No source files are changed until you approve a staged revision. You can then save it as new or overwrite the existing target.',
											'wp-autoplugin'
									  )
							}
						/>
					</div>
					<Button
						variant="primary"
						disabled={
							busy ||
							! request.trim() ||
							( operation === 'explain' &&
								! explainCapability?.available ) ||
							( requiresPlan &&
								! effectivePlanCapability?.available )
						}
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
	jobs,
	jobsLoading,
	codeCapability,
	activeTab,
	onTabSelect,
	onCancel,
	onCreateJob,
	onSavePlan,
}: {
	workspace: Workspace;
	jobs: Job[];
	jobsLoading: boolean;
	codeCapability: AgentCapability | null;
	activeTab: string;
	onTabSelect: ( tab: string ) => void;
	onCancel: ( job: Job ) => void;
	onCreateJob: (
		task: 'plan' | 'code' | 'explain' | 'conversation',
		payload?: object
	) => Promise< Job | null >;
	onSavePlan: ( job: Job, content: string ) => Promise< boolean >;
} ) {
	const target = workspace.target_metadata;
	const tabs =
		workspace.operation === 'explain'
			? [ 'explain' ]
			: [ 'plan', 'code', 'review' ];
	const latestPlan = latestPlanArtifact( jobs );
	const latestPlanRun = latestJobForTask( jobs, 'plan' );
	const latestStructureRun = latestJobForTask( jobs, 'plan_structure' );
	const planConversationJobs = jobs.filter(
		( job ) => job.task === 'conversation' && job.payload.stage === 'plan'
	);
	const explainConversationJobs = jobs.filter(
		( job ) =>
			job.task === 'explain' ||
			( job.task === 'conversation' && job.payload.stage === 'explain' )
	);
	const codeConversationJobs = jobs.filter(
		( job ) => job.task === 'conversation' && job.payload.stage === 'code'
	);
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
				{ tabs.map( ( tab ) => (
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
					{ jobsLoading && (
						<div className="workspace-job-loading">
							<Spinner />{ ' ' }
							{ __( 'Loading job…', 'wp-autoplugin' ) }
						</div>
					) }
					{ ! jobsLoading && activeTab === 'plan' && (
						<PlanStage
							job={ latestPlan }
							latestRun={ latestPlanRun }
							regenerationJob={ latestStructureRun }
							conversationJobs={ planConversationJobs }
							onCancel={ onCancel }
							onCreate={ () => onCreateJob( 'plan' ) }
							onSave={ onSavePlan }
							onContinue={ () => onTabSelect( 'code' ) }
							onFollowUp={ ( message, artifactJobId ) =>
								onCreateJob( 'conversation', {
									stage: 'plan',
									message,
									artifact_job_id: artifactJobId,
								} )
							}
						/>
					) }
					{ ! jobsLoading && activeTab === 'code' && (
						<CodeStage
							workspace={ workspace }
							plan={ latestPlan }
							jobs={ jobs }
							capability={ codeCapability }
							onCancel={ onCancel }
							conversationJobs={ codeConversationJobs }
							onCreateCode={ ( payload ) =>
								onCreateJob( 'code', payload )
							}
							onFollowUp={ ( message, revisionId ) =>
								onCreateJob( 'conversation', {
									stage: 'code',
									message,
									revision_id: revisionId,
									expected_latest_revision_id: revisionId,
								} )
							}
						/>
					) }
					{ ! jobsLoading && activeTab === 'review' && (
						<ReviewStage plan={ latestPlan } />
					) }
					{ ! jobsLoading && activeTab === 'explain' && (
						<ExplainStage
							jobs={ explainConversationJobs }
							initialMessage={ workspace.request }
							onCancel={ onCancel }
							onFollowUp={ ( message ) =>
								onCreateJob( 'conversation', {
									stage: 'explain',
									message,
								} )
							}
						/>
					) }
				</CardBody>
			</Card>
		</section>
	);
}

function latestPlanArtifact( jobs: Job[] ): Job | null {
	return (
		[ ...jobs ]
			.reverse()
			.find(
				( job ) =>
					job.status === 'completed' &&
					( ( job.task === 'plan' && !! job.result?.content ) ||
						( job.task === 'plan_structure' &&
							job.result?.artifact?.type === 'plan' &&
							!! job.result.artifact.content ) ||
						( job.task === 'conversation' &&
							job.payload.stage === 'plan' &&
							job.result?.outcome === 'artifact' &&
							job.result.artifact?.type === 'plan' &&
							!! job.result.artifact.content ) )
			) ?? null
	);
}

function latestJobForTask( jobs: Job[], task: string ): Job | null {
	return [ ...jobs ].reverse().find( ( job ) => job.task === task ) ?? null;
}

function planArtifactContent( job: Job ): string {
	return job.result?.artifact?.content || job.result?.content || '';
}

function planArtifactStructure( job: Job ): Record< string, unknown > | null {
	if ( job.result?.structured ) {
		return job.result.structured;
	}

	try {
		const parsed = JSON.parse( planArtifactContent( job ) );
		return parsed && typeof parsed === 'object' ? parsed : null;
	} catch ( error ) {
		return null;
	}
}

function planMarkdown(
	content: string,
	structured: Record< string, unknown > | null
): string {
	if ( ! structured || ! content.trim().startsWith( '{' ) ) {
		return content;
	}

	const title =
		typeof structured.plugin_name === 'string'
			? structured.plugin_name
			: __( 'Implementation plan', 'wp-autoplugin' );
	const sections = [ `# ${ title }` ];
	Object.entries( structured ).forEach( ( [ key, value ] ) => {
		if (
			'plugin_name' === key ||
			'project_structure' === key ||
			typeof value !== 'string' ||
			! value.trim()
		) {
			return;
		}
		sections.push(
			`## ${ key
				.replace( /_/g, ' ' )
				.replace( /\b\w/g, ( letter ) =>
					letter.toUpperCase()
				) }\n\n${ value.trim() }`
		);
	} );
	return sections.join( '\n\n' );
}

function JobStatus( {
	job,
	onCancel,
}: {
	job: Job;
	onCancel: ( job: Job ) => void;
} ) {
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
			{ ! terminal && job.latest_event?.message && (
				<p className="job-status__event">
					{ job.latest_event.message }
				</p>
			) }
			{ job.error_message && (
				<Notice status="error" isDismissible={ false }>
					<span className="job-error-message">
						{ job.error_message }
					</span>
				</Notice>
			) }
			{ job.status === 'completed' && job.result && (
				<Result result={ job.result } />
			) }
			{ ! terminal && (
				<Button
					variant="secondary"
					isDestructive
					onClick={ () => onCancel( job ) }
				>
					{ __( 'Cancel job', 'wp-autoplugin' ) }
				</Button>
			) }
		</div>
	);
}

function AgentActivity( { job }: { job: Job } ) {
	const [ expanded, setExpanded ] = useState( false );
	const [ events, setEvents ] = useState< JobEvent[] >( [] );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const loadEvents = useCallback( () => {
		setLoading( true );
		setError( '' );
		apiFetch< { items: JobEvent[] } >( {
			path: `${ rest }/jobs/${ job.id }/events`,
		} )
			.then( ( response ) => setEvents( response.items ) )
			.catch( ( reason ) => setError( reason.message ) )
			.finally( () => setLoading( false ) );
	}, [ job.id ] );

	useEffect( () => {
		if ( expanded ) {
			loadEvents();
		}
	}, [ expanded, job.latest_event?.sequence, loadEvents ] );

	const visibleEvents = events.filter(
		( item ) =>
			item.event.startsWith( 'agent_' ) ||
			[
				'started',
				'completed',
				'failed',
				'cancelled',
				'cancel_requested',
			].includes( item.event )
	);
	const agent = job.result?.agent;

	return (
		<details
			className="agent-activity"
			onToggle={ ( event ) => setExpanded( event.currentTarget.open ) }
		>
			<summary>
				<span>{ __( 'Agent activity', 'wp-autoplugin' ) }</span>
				{ agent && (
					<small>
						{ sprintf(
							/* translators: 1: model turns, 2: tool calls, 3: source bytes. */
							__(
								'%1$d model turns · %2$d tool calls · %3$s inspected',
								'wp-autoplugin'
							),
							agent.model_turns,
							agent.tool_calls,
							formatBytes( agent.source_bytes )
						) }
					</small>
				) }
			</summary>
			<div className="agent-activity__body">
				{ loading && (
					<p className="agent-activity__loading">
						<Spinner />
						{ __( 'Loading agent activity…', 'wp-autoplugin' ) }
					</p>
				) }
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ ! loading && ! error && ! visibleEvents.length && (
					<p>
						{ __(
							'No agent activity metadata is available for this job.',
							'wp-autoplugin'
						) }
					</p>
				) }
				{ ! error && visibleEvents.length > 0 && (
					<ol className="agent-activity__events">
						{ visibleEvents.map( ( item ) => {
							const hasContext =
								item.context &&
								Object.keys( item.context ).length > 0;
							return (
								<li
									className={ `agent-activity__event agent-activity__event--${ item.level }` }
									key={ item.id }
								>
									<div>
										<strong>{ item.message }</strong>
										<time>{ item.created_at } UTC</time>
									</div>
									{ hasContext && (
										<details>
											<summary>
												{ __(
													'View details',
													'wp-autoplugin'
												) }
											</summary>
											<pre>
												{ JSON.stringify(
													item.context,
													null,
													2
												) }
											</pre>
										</details>
									) }
								</li>
							);
						} ) }
					</ol>
				) }
			</div>
		</details>
	);
}

function hasAgentActivity( job: Job ): boolean {
	return (
		!! job.result?.agent ||
		!! job.latest_event?.event.startsWith( 'agent_' )
	);
}

function formatBytes( bytes: number ): string {
	if ( bytes < 1024 ) {
		return `${ bytes } B`;
	}
	if ( bytes < 1024 * 1024 ) {
		return `${ ( bytes / 1024 ).toFixed( 1 ) } KB`;
	}
	return `${ ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) } MB`;
}

function PlanStage( {
	job,
	latestRun,
	regenerationJob,
	conversationJobs,
	onCancel,
	onCreate,
	onSave,
	onContinue,
	onFollowUp,
}: {
	job: Job | null;
	latestRun: Job | null;
	regenerationJob: Job | null;
	conversationJobs: Job[];
	onCancel: ( job: Job ) => void;
	onCreate: () => void;
	onSave: ( job: Job, content: string ) => Promise< boolean >;
	onContinue: () => void;
	onFollowUp: ( message: string, artifactJobId: number ) => void;
} ) {
	if ( ! job ) {
		if ( latestRun ) {
			return (
				<>
					<JobStatus job={ latestRun } onCancel={ onCancel } />
					{ hasAgentActivity( latestRun ) && (
						<AgentActivity job={ latestRun } />
					) }
					{ [ 'failed', 'cancelled' ].includes(
						latestRun.status
					) && (
						<Button variant="secondary" onClick={ onCreate }>
							{ __( 'Retry plan', 'wp-autoplugin' ) }
						</Button>
					) }
				</>
			);
		}
		return (
			<EmptyStage
				action={ __( 'Create plan', 'wp-autoplugin' ) }
				onAction={ onCreate }
				title={ __( 'Plan', 'wp-autoplugin' ) }
			/>
		);
	}
	if ( job.status !== 'completed' ) {
		return (
			<>
				<JobStatus job={ job } onCancel={ onCancel } />
				{ [ 'failed', 'cancelled' ].includes( job.status ) && (
					<Button variant="secondary" onClick={ onCreate }>
						{ __( 'Retry plan', 'wp-autoplugin' ) }
					</Button>
				) }
			</>
		);
	}
	return (
		<PlanEditor
			job={ job }
			conversationJobs={ conversationJobs }
			regenerationJob={ regenerationJob }
			onCancel={ onCancel }
			onSave={ onSave }
			onRetry={ onCreate }
			onContinue={ onContinue }
			onFollowUp={ onFollowUp }
		/>
	);
}

function PlanEditor( {
	job,
	conversationJobs,
	regenerationJob,
	onCancel,
	onSave,
	onRetry,
	onContinue,
	onFollowUp,
}: {
	job: Job;
	conversationJobs: Job[];
	regenerationJob: Job | null;
	onCancel: ( job: Job ) => void;
	onSave: ( job: Job, content: string ) => Promise< boolean >;
	onRetry: () => void;
	onContinue: () => void;
	onFollowUp: ( message: string, artifactJobId: number ) => void;
} ) {
	const structure = planArtifactStructure( job );
	const currentContent = planMarkdown(
		planArtifactContent( job ),
		structure
	);
	const [ editing, setEditing ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ content, setContent ] = useState( currentContent );
	const cancelEditing = useCallback( () => {
		setContent( currentContent );
		setEditing( false );
	}, [ currentContent ] );
	useEffect( () => {
		setContent( currentContent );
		setEditing( false );
		setSaving( false );
	}, [ currentContent ] );
	useEffect( () => {
		if ( ! editing || saving ) {
			return;
		}
		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				cancelEditing();
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ editing, saving, cancelEditing ] );
	const structureRegenerating = Boolean(
		regenerationJob &&
			[ 'queued', 'running', 'retrying' ].includes(
				regenerationJob.status
			)
	);
	return (
		<div className="plan-stage">
			<div className="stage-toolbar">
				<div>
					<strong>
						{ __( 'Implementation plan', 'wp-autoplugin' ) }
					</strong>
					<small>
						{ __( 'Saved with this plan job', 'wp-autoplugin' ) }
					</small>
				</div>
				<div>
					{ editing ? (
						<>
							<Button
								variant="secondary"
								onClick={ cancelEditing }
								disabled={ saving }
							>
								{ __( 'Cancel', 'wp-autoplugin' ) }
							</Button>
							<Button
								variant="primary"
								disabled={ saving }
								isBusy={ saving }
								onClick={ async () => {
									setSaving( true );
									if ( await onSave( job, content ) ) {
										setEditing( false );
									}
									setSaving( false );
								} }
							>
								{ __( 'Save plan', 'wp-autoplugin' ) }
							</Button>
						</>
					) : (
						<>
							<Button variant="secondary" onClick={ onRetry }>
								{ __( 'Retry plan', 'wp-autoplugin' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ () => setEditing( true ) }
							>
								{ __( 'Edit plan', 'wp-autoplugin' ) }
							</Button>
						</>
					) }
				</div>
			</div>
			{ job.task === 'plan' && hasAgentActivity( job ) && (
				<AgentActivity job={ job } />
			) }
			{ regenerationJob && regenerationJob.status !== 'completed' && (
				<div className="plan-stage__regeneration">
					<strong>
						{ __( 'Updating project structure', 'wp-autoplugin' ) }
					</strong>
					<JobStatus job={ regenerationJob } onCancel={ onCancel } />
				</div>
			) }
			<div className="plan-stage__overview">
				<div className="plan-stage__content">
					{ editing ? (
						<TextareaControl
							hideLabelFromVision
							label={ __( 'Plan Markdown', 'wp-autoplugin' ) }
							value={ content }
							onChange={ setContent }
							rows={ 20 }
						/>
					) : (
						<Markdown content={ content } />
					) }
				</div>
				<ProjectStructure structure={ structure } />
			</div>
			<div className="stage-next">
				<span>
					{ __(
						'Open Code to explicitly generate a validated staged revision from this Plan.',
						'wp-autoplugin'
					) }
				</span>
				<Button
					variant="primary"
					disabled={ structureRegenerating }
					onClick={ onContinue }
				>
					{ __( 'Continue to Code', 'wp-autoplugin' ) }
				</Button>
			</div>
			<StageConversation
				stage="plan"
				jobs={ conversationJobs }
				artifactJobId={ job.id }
				onCancel={ onCancel }
				onFollowUp={ ( message ) => onFollowUp( message, job.id ) }
			/>
		</div>
	);
}

function ProjectStructure( {
	structure,
}: {
	structure: Record< string, unknown > | null;
} ) {
	const projectStructure = structure?.project_structure as
		| Record< string, unknown >
		| undefined;
	const directories = Array.isArray( projectStructure?.directories )
		? projectStructure.directories.filter(
				( directory ): directory is string =>
					typeof directory === 'string'
		  )
		: [];
	const files = Array.isArray( projectStructure?.files )
		? projectStructure.files.filter(
				( file ): file is Record< string, unknown > =>
					!! file && typeof file === 'object'
		  )
		: [];

	return (
		<aside
			className="project-structure"
			aria-label={ __( 'Project structure', 'wp-autoplugin' ) }
		>
			<div className="project-structure__heading">
				<strong>{ __( 'Project structure', 'wp-autoplugin' ) }</strong>
				<small>
					{ __( 'Files proposed by this plan', 'wp-autoplugin' ) }
				</small>
			</div>
			{ directories.length > 0 && (
				<ul className="project-structure__directories">
					{ directories.map( ( directory ) => (
						<li key={ directory }>{ directory }</li>
					) ) }
				</ul>
			) }
			{ files.length > 0 ? (
				<ul className="project-structure__files">
					{ files.map( ( file, index ) => (
						<li key={ `${ String( file.path || '' ) }-${ index }` }>
							<code>
								{ String(
									file.path ||
										__( 'Unnamed file', 'wp-autoplugin' )
								) }
							</code>
							<div>
								{ typeof file.action === 'string' && (
									<span>{ file.action }</span>
								) }
								{ typeof file.type === 'string' && (
									<span>{ file.type }</span>
								) }
							</div>
							{ typeof file.description === 'string' && (
								<p>{ file.description }</p>
							) }
						</li>
					) ) }
				</ul>
			) : (
				<p className="project-structure__empty">
					{ __(
						'This plan does not include a file structure.',
						'wp-autoplugin'
					) }
				</p>
			) }
		</aside>
	);
}

function CodeStage( {
	workspace,
	plan,
	jobs,
	capability,
	onCancel,
	conversationJobs,
	onCreateCode,
	onFollowUp,
}: {
	workspace: Workspace;
	plan: Job | null;
	jobs: Job[];
	capability: AgentCapability | null;
	onCancel: ( job: Job ) => void;
	conversationJobs: Job[];
	onCreateCode: ( payload: object ) => Promise< Job | null >;
	onFollowUp: (
		message: string,
		revisionId: number
	) => Promise< Job | null >;
} ) {
	const [ revisions, setRevisions ] = useState< RevisionSummary[] >( [] );
	const [ latestRevisionId, setLatestRevisionId ] = useState< number | null >(
		null
	);
	const [ selectedRevisionId, setSelectedRevisionId ] = useState<
		number | null
	>( null );
	const [ manifest, setManifest ] = useState< RevisionManifest | null >(
		null
	);
	const [ selectedFileId, setSelectedFileId ] = useState< number | null >(
		null
	);
	const selectedFilePath = useRef( '' );
	const [ fileBodies, setFileBodies ] = useState<
		Record< string, RevisionFile >
	>( {} );
	const [ mode, setMode ] = useState< 'code' | 'changes' >( 'code' );
	const [ editing, setEditing ] = useState( false );
	const [ buffers, setBuffers ] = useState< Record< string, string > >( {} );
	const [ problems, setProblems ] = useState< CodeIssue[] >( [] );
	const [ focusIssue, setFocusIssue ] = useState< CodeIssue | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ actionBusy, setActionBusy ] = useState( false );
	const [ localError, setLocalError ] = useState( '' );

	const codeJobs = jobs.filter( ( job ) => 'code' === job.task );
	const latestCodeJob = codeJobs[ codeJobs.length - 1 ] ?? null;
	const activeCodeJob =
		[ ...codeJobs ]
			.reverse()
			.find( ( job ) =>
				[ 'queued', 'running', 'retrying' ].includes( job.status )
			) ?? null;
	const codeWorkJobs = [ ...codeJobs, ...conversationJobs ].sort(
		( left, right ) => left.id - right.id
	);
	const activeCodeWork =
		[ ...codeWorkJobs ]
			.reverse()
			.find( ( job ) =>
				[ 'queued', 'running', 'retrying' ].includes( job.status )
			) ?? null;
	const latestCodeConversation =
		conversationJobs[ conversationJobs.length - 1 ] ?? null;
	const structureRun = latestJobForTask( jobs, 'plan_structure' );
	const structureActive =
		!! structureRun &&
		[ 'queued', 'running', 'retrying' ].includes( structureRun.status );
	const displayedPlan = activeCodeJob
		? jobs.find(
				( job ) => job.id === activeCodeJob.payload.plan_artifact_job_id
		  ) ?? plan
		: plan;
	const normalizesProjectRoot = [ 'create', 'hook_extension' ].includes(
		workspace.operation
	);
	const planned = planFiles( displayedPlan, normalizesProjectRoot );
	const currentPlanFiles = planFiles( plan, normalizesProjectRoot );
	const selectedMainFile = planMainFile(
		plan,
		currentPlanFiles,
		normalizesProjectRoot
	);
	const requiresMainFile = normalizesProjectRoot;
	const planValid =
		!! plan &&
		( ! requiresMainFile || !! selectedMainFile ) &&
		( ! requiresMainFile ||
			currentPlanFiles.every( ( file ) => file.action === 'add' ) ) &&
		currentPlanFiles.length > 0 &&
		currentPlanFiles.length <= 20;
	const failedLatestCode =
		!! latestCodeJob &&
		[ 'failed', 'cancelled' ].includes( latestCodeJob.status ) &&
		! latestCodeJob.result?.revision_id;
	const visibleFiles = useMemo(
		() => ( manifest ? revisionVisibleFiles( manifest ) : [] ),
		[ manifest ]
	);

	const loadRevisions = useCallback(
		async ( preferredId?: number ) => {
			const response = await apiFetch< {
				items: RevisionSummary[];
				latest_revision_id: number | null;
			} >( { path: `${ rest }/workspaces/${ workspace.id }/revisions` } );
			setRevisions( response.items );
			setLatestRevisionId( response.latest_revision_id );
			setSelectedRevisionId( ( current ) => {
				if (
					preferredId &&
					preferredId === response.latest_revision_id
				) {
					return preferredId;
				}
				if (
					current &&
					response.items.some( ( item ) => item.id === current )
				) {
					return current;
				}
				return response.latest_revision_id;
			} );
		},
		[ workspace.id ]
	);

	useEffect( () => {
		setLoading( true );
		loadRevisions()
			.catch( ( reason ) => setLocalError( reason.message ) )
			.finally( () => setLoading( false ) );
	}, [ loadRevisions ] );

	useEffect( () => {
		const revisionId = latestCodeJob?.result?.revision_id;
		if ( latestCodeJob?.status === 'completed' && revisionId ) {
			loadRevisions( revisionId ).catch( ( reason ) =>
				setLocalError( reason.message )
			);
		}
	}, [
		latestCodeJob?.status,
		latestCodeJob?.result?.revision_id,
		loadRevisions,
	] );

	useEffect( () => {
		const revisionId = latestCodeConversation?.result?.revision_id;
		if (
			latestCodeConversation?.status === 'completed' &&
			latestCodeConversation.result?.outcome === 'revision' &&
			revisionId
		) {
			setFileBodies( {} );
			loadRevisions( revisionId ).catch( ( reason ) =>
				setLocalError( reason.message )
			);
		}
	}, [
		latestCodeConversation?.status,
		latestCodeConversation?.result?.outcome,
		latestCodeConversation?.result?.revision_id,
		loadRevisions,
	] );

	useEffect( () => {
		if ( ! selectedRevisionId ) {
			setManifest( null );
			setSelectedFileId( null );
			selectedFilePath.current = '';
			return;
		}
		let current = true;
		apiFetch< RevisionManifest >( {
			path: `${ rest }/revisions/${ selectedRevisionId }`,
		} )
			.then( ( response ) => {
				if ( ! current ) {
					return;
				}
				const files = revisionVisibleFiles( response );
				const selectedFile =
					files.find(
						( file ) => file.path === selectedFilePath.current
					) ?? files[ 0 ];
				setManifest( response );
				setSelectedFileId( selectedFile?.id ?? null );
				selectedFilePath.current = selectedFile?.path ?? '';
				setMode( 'code' );
				setProblems( [] );
			} )
			.catch( ( reason ) => current && setLocalError( reason.message ) );
		return () => {
			current = false;
		};
	}, [ selectedRevisionId ] );

	const loadFile = useCallback(
		async ( revisionId: number, file: RevisionFileManifest ) => {
			const key = `${ revisionId }:${ file.id }`;
			if ( fileBodies[ key ] ) {
				return fileBodies[ key ];
			}
			const response = await apiFetch< RevisionFile >( {
				path: file.change_type
					? `${ rest }/revisions/${ revisionId }/files/${ file.id }`
					: `${ rest }/revisions/${ revisionId }/target-file?path=${ encodeURIComponent(
							file.path
					  ) }`,
			} );
			const loaded = {
				...response,
				id: file.id,
				change_type: file.change_type,
			};
			setFileBodies( ( current ) => ( {
				...current,
				[ key ]: loaded,
			} ) );
			return loaded;
		},
		[ fileBodies ]
	);

	useEffect( () => {
		if (
			selectedRevisionId &&
			selectedFileId &&
			manifest?.id === selectedRevisionId &&
			visibleFiles.some( ( file ) => file.id === selectedFileId )
		) {
			const file = visibleFiles.find(
				( item ) => item.id === selectedFileId
			);
			if ( file ) {
				loadFile( selectedRevisionId, file ).catch( ( reason ) =>
					setLocalError( reason.message )
				);
			}
		}
	}, [
		selectedRevisionId,
		selectedFileId,
		manifest,
		visibleFiles,
		loadFile,
	] );

	const selectFile = useCallback(
		( fileId: number ) => {
			const file = visibleFiles.find( ( item ) => item.id === fileId );
			selectedFilePath.current = file?.path ?? '';
			setSelectedFileId( fileId );
			if ( ! file?.change_type ) {
				setMode( 'code' );
			}
		},
		[ visibleFiles ]
	);

	const baseContents = useMemo( () => {
		const values: Record< string, string > = {};
		if ( ! manifest ) {
			return values;
		}
		visibleFiles.forEach( ( file ) => {
			const loaded = fileBodies[ `${ manifest.id }:${ file.id }` ];
			if ( loaded ) {
				values[ file.path ] = loaded.content;
			}
		} );
		return values;
	}, [ manifest, visibleFiles, fileBodies ] );
	const dirtyPaths = useMemo(
		() =>
			new Set(
				Object.keys( buffers ).filter(
					( path ) => buffers[ path ] !== baseContents[ path ]
				)
			),
		[ buffers, baseContents ]
	);

	useEffect( () => {
		if ( ! dirtyPaths.size ) {
			return;
		}
		const warn = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirtyPaths.size ] );

	const selectedManifestFile =
		visibleFiles.find( ( file ) => file.id === selectedFileId ) ?? null;
	const selectedFile =
		manifest && selectedFileId
			? fileBodies[ `${ manifest.id }:${ selectedFileId }` ] ?? null
			: null;
	const displayedContent = selectedManifestFile
		? buffers[ selectedManifestFile.path ] ?? selectedFile?.content ?? ''
		: '';

	function beginEdit() {
		if ( ! manifest || manifest.id !== latestRevisionId ) {
			return;
		}
		setBuffers( {} );
		setEditing( true );
		setMode( 'code' );
		setProblems( [] );
	}

	function cancelEdits() {
		let confirmed = true;
		if ( dirtyPaths.size ) {
			// eslint-disable-next-line no-alert -- Explicit confirmation protects a multi-file edit session.
			confirmed = window.confirm(
				__(
					'Discard all unsaved changes in this edit session?',
					'wp-autoplugin'
				)
			);
		}
		if ( ! confirmed ) {
			return;
		}
		setEditing( false );
		setBuffers( {} );
		setProblems( [] );
		setFocusIssue( null );
	}

	async function saveRevision() {
		if ( ! manifest || ! latestRevisionId || ! dirtyPaths.size ) {
			return;
		}
		setActionBusy( true );
		setProblems( [] );
		setLocalError( '' );
		try {
			const created = await apiFetch< { id: number } >( {
				path: `${ rest }/revisions/${ manifest.id }/successors`,
				method: 'POST',
				data: {
					expected_latest_revision_id: latestRevisionId,
					changes: [ ...dirtyPaths ].map( ( path ) => ( {
						path,
						content: buffers[ path ],
						base_content_hash:
							fileBodies[
								`${ manifest.id }:${
									visibleFiles.find(
										( file ) => file.path === path
									)?.id ?? ''
								}`
							]?.content_hash ?? '',
					} ) ),
				},
			} );
			setEditing( false );
			setBuffers( {} );
			setFileBodies( {} );
			await loadRevisions( created.id );
		} catch ( reason: any ) {
			const issues = reason?.data?.issues;
			if ( Array.isArray( issues ) ) {
				setProblems( issues );
			}
			setLocalError(
				reason?.code === 'revision_conflict'
					? __(
							'A newer revision exists. Your buffers are preserved; reload the latest revision before retrying.',
							'wp-autoplugin'
					  )
					: reason.message
			);
		} finally {
			setActionBusy( false );
		}
	}

	async function restoreSelected() {
		if (
			! manifest ||
			! latestRevisionId ||
			manifest.id === latestRevisionId
		) {
			return;
		}
		// eslint-disable-next-line no-alert -- Restore is an explicit immutable-history operation.
		const confirmed = window.confirm(
			__(
				'Restore this revision as a new latest revision?',
				'wp-autoplugin'
			)
		);
		if ( ! confirmed ) {
			return;
		}
		setActionBusy( true );
		try {
			const created = await apiFetch< { id: number } >( {
				path: `${ rest }/revisions/${ manifest.id }/restore`,
				method: 'POST',
				data: { expected_latest_revision_id: latestRevisionId },
			} );
			setFileBodies( {} );
			await loadRevisions( created.id );
		} catch ( reason: any ) {
			setLocalError( reason.message );
		} finally {
			setActionBusy( false );
		}
	}

	async function generate( regenerate = false ) {
		if ( ! plan ) {
			return;
		}
		let confirmed = true;
		if ( regenerate ) {
			// eslint-disable-next-line no-alert -- Regeneration starts explicit billable work.
			confirmed = window.confirm(
				requiresMainFile
					? __(
							'Regenerate every file using the latest Plan? This starts new billable model requests and preserves the current revision in history.',
							'wp-autoplugin'
					  )
					: __(
							'Regenerate every planned target change using the latest Plan? This starts new billable model requests and preserves the current revision in history.',
							'wp-autoplugin'
					  )
			);
		}
		if ( ! confirmed ) {
			return;
		}
		setActionBusy( true );
		setLocalError( '' );
		await onCreateCode(
			regenerate
				? {
						mode: 'regenerate',
						plan_artifact_job_id: plan.id,
						parent_revision_id: latestRevisionId,
						expected_latest_revision_id: latestRevisionId,
				  }
				: {
						mode: 'generate',
						plan_artifact_job_id: plan.id,
						expected_latest_revision_id: null,
				  }
		);
		setActionBusy( false );
	}

	const progressFiles =
		( activeCodeJob ?? latestCodeJob )?.code_progress?.files ?? [];
	const progressMap = Object.fromEntries(
		progressFiles.map( ( file ) => [ file.path, file.status ] )
	);
	const treeFiles =
		( manifest ? visibleFiles : null ) ??
		planned.map( ( file, index ) => ( {
			id: index,
			path: file.path,
			type: file.type as 'php' | 'js' | 'css',
			change_type: file.action as 'add' | 'update' | 'delete',
			content_hash: '',
			size: 0,
		} ) );
	let treeLabel = __( 'PLANNED FILES', 'wp-autoplugin' );
	if ( manifest?.project_manifest?.scope === 'changes' ) {
		treeLabel = __( 'TARGET FILES', 'wp-autoplugin' );
	} else if ( manifest ) {
		treeLabel = __( 'STAGED FILES', 'wp-autoplugin' );
	}
	let regenerateLabel = failedLatestCode
		? __( 'Generate again', 'wp-autoplugin' )
		: __( 'Regenerate all code', 'wp-autoplugin' );
	if ( ! failedLatestCode && ! requiresMainFile ) {
		regenerateLabel = __( 'Regenerate planned changes', 'wp-autoplugin' );
	}
	let toolbarActions = null;
	if ( editing ) {
		toolbarActions = (
			<>
				<Button
					variant="secondary"
					disabled={ actionBusy }
					onClick={ cancelEdits }
				>
					{ __( 'Cancel edits', 'wp-autoplugin' ) }
				</Button>
				<Button
					variant="primary"
					isBusy={ actionBusy }
					disabled={ actionBusy || ! dirtyPaths.size }
					onClick={ saveRevision }
				>
					{ __( 'Save revision', 'wp-autoplugin' ) }
				</Button>
			</>
		);
	} else if ( manifest?.id === latestRevisionId ) {
		toolbarActions = (
			<>
				<Button
					variant="secondary"
					disabled={ actionBusy || !! activeCodeWork }
					onClick={ beginEdit }
				>
					{ __( 'Edit revision', 'wp-autoplugin' ) }
				</Button>
				<Button
					variant="primary"
					disabled={
						actionBusy ||
						!! activeCodeWork ||
						! planValid ||
						structureActive ||
						! capability?.available
					}
					onClick={ () => generate( true ) }
				>
					{ regenerateLabel }
				</Button>
			</>
		);
	} else {
		toolbarActions = (
			<Button
				variant="primary"
				isBusy={ actionBusy }
				disabled={ actionBusy || !! activeCodeWork }
				onClick={ restoreSelected }
			>
				{ __( 'Restore as latest', 'wp-autoplugin' ) }
			</Button>
		);
	}
	let filePanel = (
		<div className="code-editor-loading">
			<Spinner /> { __( 'Loading file…', 'wp-autoplugin' ) }
		</div>
	);
	if (
		selectedFile &&
		selectedManifestFile?.change_type === 'delete' &&
		'code' === mode
	) {
		filePanel = (
			<Notice status="info" isDismissible={ false }>
				{ __(
					'This staged action deletes the target file. Open Changes to review the removed source.',
					'wp-autoplugin'
				) }
			</Notice>
		);
	} else if ( selectedFile && 'changes' === mode ) {
		filePanel = <DiffView html={ selectedFile.diff_html } />;
	} else if ( selectedFile ) {
		filePanel = (
			<CodeBufferEditor
				key={ `${ manifest?.id }:${ selectedFile.id }:${ editing }` }
				value={ displayedContent }
				type={ selectedManifestFile?.type ?? 'php' }
				readOnly={ ! editing }
				focusLine={
					focusIssue?.path === selectedFile.path ? focusIssue.line : 0
				}
				onChange={ ( content ) => {
					if ( selectedManifestFile ) {
						setBuffers( ( current ) => ( {
							...current,
							[ selectedManifestFile.path ]: content,
						} ) );
					}
				} }
			/>
		);
	}

	if ( loading ) {
		return (
			<div className="workspace-job-loading">
				<Spinner /> { __( 'Loading Code workspace…', 'wp-autoplugin' ) }
			</div>
		);
	}

	return (
		<div className="code-workspace">
			{ localError && (
				<Notice status="error" onRemove={ () => setLocalError( '' ) }>
					{ localError }
				</Notice>
			) }
			{ manifest && plan && manifest.plan_job_id !== plan.id && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'The Plan has changed since this revision.',
						'wp-autoplugin'
					) }
				</Notice>
			) }
			{ manifest && ! manifest.plan_structure_matches && (
				<Notice status="warning" isDismissible={ false }>
					{ requiresMainFile
						? __(
								'This revision’s file structure differs from its Plan. Regenerate all code to return to the latest Plan structure.',
								'wp-autoplugin'
						  )
						: __(
								'This revision’s change set differs from its Plan. Regenerate the planned changes to return to the latest Plan structure.',
								'wp-autoplugin'
						  ) }
				</Notice>
			) }
			{ manifest?.target_tree_error && (
				<Notice status="warning" isDismissible={ false }>
					{ manifest.target_tree_error }
				</Notice>
			) }
			{ manifest && failedLatestCode && (
				<Notice status="error" isDismissible={ false }>
					{ latestCodeJob?.error_message ||
						__(
							'Code generation ended without creating a revision. The existing revision remains unchanged.',
							'wp-autoplugin'
						) }
				</Notice>
			) }
			{ manifest && (
				<div className="code-workspace__toolbar">
					<label htmlFor="code-revision-select">
						<span>{ __( 'Revision', 'wp-autoplugin' ) }</span>
						<select
							id="code-revision-select"
							value={ selectedRevisionId ?? '' }
							disabled={ editing }
							onChange={ ( event ) =>
								setSelectedRevisionId(
									Number( event.target.value )
								)
							}
						>
							{ revisions.map( ( revision ) => (
								<option
									value={ revision.id }
									key={ revision.id }
								>
									{ sprintf(
										/* translators: %d: immutable revision number. */
										__( 'Revision %d', 'wp-autoplugin' ),
										revision.revision_number
									) }{ ' ' }
									· { revisionOrigin( revision.origin ) }
								</option>
							) ) }
						</select>
					</label>
					<div className="code-workspace__provenance">
						<strong>{ revisionOrigin( manifest.origin ) }</strong>
						<span>
							{ sprintf(
								/* translators: %d: Plan job ID. */
								__( 'Plan job #%d', 'wp-autoplugin' ),
								manifest.plan_job_id ?? 0
							) }
						</span>
						<span className="is-valid">
							{ __( 'Valid', 'wp-autoplugin' ) }
						</span>
					</div>
					<div className="code-workspace__actions">
						{ toolbarActions }
					</div>
				</div>
			) }
			<div className="code-stage">
				<aside className="code-stage__tree">
					<strong>{ treeLabel }</strong>
					<FileTree
						files={ treeFiles }
						directories={ manifest?.target_directories ?? [] }
						selectedId={ selectedFileId }
						dirtyPaths={ dirtyPaths }
						problemPaths={
							new Set( problems.map( ( issue ) => issue.path ) )
						}
						progress={ progressMap }
						onSelect={ manifest ? selectFile : () => undefined }
					/>
				</aside>
				<section className="code-stage__editor">
					{ ! manifest ? (
						<CodeGenerationPanel
							workspace={ workspace }
							plan={ displayedPlan }
							planned={ planned }
							job={ activeCodeJob ?? latestCodeJob }
							capability={ capability }
							planValid={ planValid }
							requiresMainFile={ requiresMainFile }
							disabled={
								actionBusy ||
								structureActive ||
								!! activeCodeWork
							}
							onGenerate={ () => generate( false ) }
							onCancel={ onCancel }
						/>
					) : (
						<>
							{ activeCodeJob && (
								<CodeProgress
									job={ activeCodeJob }
									onCancel={ onCancel }
									compact
								/>
							) }
							<div className="code-file-header">
								<div>
									<strong>
										{ selectedManifestFile?.path ??
											__(
												'Select a file',
												'wp-autoplugin'
											) }
									</strong>
									{ selectedManifestFile && (
										<small>
											{ selectedManifestFile.type }
											{ ( selectedManifestFile.change_type ||
												dirtyPaths.has(
													selectedManifestFile.path
												) ) && (
												<>
													{ ' · ' }
													<OperationBadge
														operation={
															selectedManifestFile.change_type ??
															'update'
														}
													/>
												</>
											) }
										</small>
									) }
								</div>
								<div className="code-file-header__modes">
									<Button
										variant={
											mode === 'code'
												? 'primary'
												: 'secondary'
										}
										onClick={ () => setMode( 'code' ) }
									>
										{ __( 'Code', 'wp-autoplugin' ) }
									</Button>
									<Button
										variant={
											mode === 'changes'
												? 'primary'
												: 'secondary'
										}
										disabled={
											dirtyPaths.size > 0 ||
											! selectedManifestFile?.change_type
										}
										onClick={ () => setMode( 'changes' ) }
									>
										{ __( 'Changes', 'wp-autoplugin' ) }
									</Button>
								</div>
							</div>
							{ filePanel }
							{ problems.length > 0 && (
								<ProblemsPanel
									issues={ problems }
									onSelect={ ( issue ) => {
										const file = visibleFiles.find(
											( item ) => item.path === issue.path
										);
										if ( file ) {
											selectFile( file.id );
											setMode( 'code' );
											setFocusIssue( issue );
										}
									} }
								/>
							) }
						</>
					) }
				</section>
			</div>
			{ manifest && (
				<div className="code-statusbar">
					<span>
						{ sprintf(
							/* translators: %d: number of files. */
							__( '%d files', 'wp-autoplugin' ),
							manifest.files_count
						) }
					</span>
					<span>{ formatBytes( manifest.aggregate_size ) }</span>
					<span>{ __( 'Validation: valid', 'wp-autoplugin' ) }</span>
					<span>
						A { manifest.adds ?? manifest.files_count } · M{ ' ' }
						{ manifest.updates ?? 0 } · D { manifest.deletes ?? 0 }
					</span>
					<span className="code-statusbar__next">
						{ __(
							'Review is the next unimplemented stage.',
							'wp-autoplugin'
						) }
					</span>
					<Button variant="primary" disabled>
						{ __( 'Continue to Review', 'wp-autoplugin' ) }
					</Button>
				</div>
			) }
			{ manifest &&
				latestRevisionId &&
				workspace.target_kind === 'new_plugin' &&
				workspace.operation === 'create' && (
					<CodeConversation
						jobs={ conversationJobs }
						revisions={ revisions }
						latestRevisionId={ latestRevisionId }
						selectedRevisionId={ selectedRevisionId }
						editing={ editing }
						dirty={ dirtyPaths.size > 0 }
						capability={ capability }
						activeCodeWork={ activeCodeWork }
						onCancel={ onCancel }
						onFollowUp={ onFollowUp }
					/>
				) }
		</div>
	);
}

function CodeConversation( {
	jobs,
	revisions,
	latestRevisionId,
	selectedRevisionId,
	editing,
	dirty,
	capability,
	activeCodeWork,
	onCancel,
	onFollowUp,
}: {
	jobs: Job[];
	revisions: RevisionSummary[];
	latestRevisionId: number;
	selectedRevisionId: number | null;
	editing: boolean;
	dirty: boolean;
	capability: AgentCapability | null;
	activeCodeWork: Job | null;
	onCancel: ( job: Job ) => void;
	onFollowUp: (
		message: string,
		revisionId: number
	) => Promise< Job | null >;
} ) {
	const [ message, setMessage ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const historical = selectedRevisionId !== latestRevisionId;
	const disabled =
		historical ||
		editing ||
		dirty ||
		!! activeCodeWork ||
		! capability?.available ||
		submitting;
	let disabledCopy = '';
	if ( historical ) {
		disabledCopy = __(
			'Select the latest revision to send a message.',
			'wp-autoplugin'
		);
	} else if ( editing || dirty ) {
		disabledCopy = __(
			'Save or cancel the edit session before sending a message.',
			'wp-autoplugin'
		);
	} else if ( activeCodeWork ) {
		disabledCopy = __(
			'Wait for the active Code work to finish.',
			'wp-autoplugin'
		);
	} else if ( ! capability?.available ) {
		disabledCopy =
			capability?.message ||
			__( 'Configure a coder model to send a message.', 'wp-autoplugin' );
	}

	const revisionLabel = ( id: number ) => {
		const revision = revisions.find( ( item ) => item.id === id );
		return revision
			? sprintf(
					/* translators: %d: immutable revision number. */
					__( 'Revision %d', 'wp-autoplugin' ),
					revision.revision_number
			  )
			: sprintf(
					/* translators: %d: revision record ID. */
					__( 'Revision ID %d', 'wp-autoplugin' ),
					id
			  );
	};

	const send = async ( value = message ) => {
		if ( disabled || ! value.trim() ) {
			return;
		}
		setSubmitting( true );
		const created = await onFollowUp( value.trim(), latestRevisionId );
		setSubmitting( false );
		if ( created && value === message ) {
			setMessage( '' );
		}
	};

	return (
		<section className="code-conversation">
			<header className="code-conversation__header">
				<div>
					<h3>
						{ __(
							'Ask about or change the code',
							'wp-autoplugin'
						) }
					</h3>
					<p>
						{ __(
							'Questions use the configured coder. Change requests may use multiple billable calls and create a staged revision immediately.',
							'wp-autoplugin'
						) }
					</p>
				</div>
				{ capability?.available && (
					<small>
						{ capability.provider } · { capability.model }
					</small>
				) }
			</header>
			{ jobs.length > 0 && (
				<div className="code-conversation__messages">
					{ jobs.map( ( job ) => {
						const anchor =
							job.result?.base_revision_id ??
							job.payload.revision_id ??
							0;
						const active = [
							'queued',
							'running',
							'retrying',
						].includes( job.status );
						return (
							<div
								className="code-conversation__message"
								key={ job.id }
							>
								<div className="code-conversation__question">
									<div>
										<strong>
											{ __( 'You', 'wp-autoplugin' ) }
										</strong>
										{ anchor > 0 && (
											<small>
												{ revisionLabel( anchor ) }
											</small>
										) }
									</div>
									<p>{ job.payload.message }</p>
								</div>
								<div className="code-conversation__answer">
									<strong>
										{ __(
											'Code assistant',
											'wp-autoplugin'
										) }
									</strong>
									{ active && (
										<CodeProgress
											job={ job }
											onCancel={ onCancel }
											compact
										/>
									) }
									{ job.status === 'completed' &&
										job.result?.outcome === 'answer' && (
											<>
												<Markdown
													content={
														job.result.content || ''
													}
												/>
												<Button
													variant="secondary"
													disabled={ disabled }
													onClick={ () =>
														send(
															job.payload
																.message || ''
														)
													}
												>
													{ __(
														'Retry answer',
														'wp-autoplugin'
													) }
												</Button>
											</>
										) }
									{ job.status === 'completed' &&
										job.result?.outcome === 'revision' && (
											<>
												<Notice
													status="success"
													isDismissible={ false }
												>
													{ sprintf(
														/* translators: %s: immutable revision label. */
														__(
															'Created %s.',
															'wp-autoplugin'
														),
														revisionLabel(
															job.result
																.revision_id ||
																0
														)
													) }
												</Notice>
												<Markdown
													content={
														job.result.content || ''
													}
												/>
												<CodeChangePaths
													added={
														job.result
															.added_paths || []
													}
													updated={
														job.result
															.updated_paths || []
													}
													deleted={
														job.result
															.deleted_paths || []
													}
												/>
											</>
										) }
									{ [ 'failed', 'cancelled' ].includes(
										job.status
									) && (
										<>
											<Notice
												status={
													job.status === 'failed'
														? 'error'
														: 'warning'
												}
												isDismissible={ false }
											>
												{ job.error_message ||
													__(
														'The Code follow-up was cancelled.',
														'wp-autoplugin'
													) }
											</Notice>
											<Button
												variant="secondary"
												disabled={ disabled }
												onClick={ () =>
													send(
														job.payload.message ||
															''
													)
												}
											>
												{ __(
													'Retry',
													'wp-autoplugin'
												) }
											</Button>
										</>
									) }
								</div>
							</div>
						);
					} ) }
				</div>
			) }
			<div className="code-conversation__composer">
				<TextareaControl
					label={ __( 'Message', 'wp-autoplugin' ) }
					value={ message }
					disabled={ disabled }
					help={
						disabledCopy ||
						__(
							'Ask a question or describe the exact change you want.',
							'wp-autoplugin'
						)
					}
					onChange={ setMessage }
					onKeyDown={ ( event ) => {
						if (
							'Enter' === event.key &&
							! event.shiftKey &&
							! event.nativeEvent.isComposing
						) {
							event.preventDefault();
							send();
						}
					} }
				/>
				<Button
					variant="primary"
					isBusy={ submitting }
					disabled={ disabled || ! message.trim() }
					onClick={ () => send() }
				>
					{ __( 'Send', 'wp-autoplugin' ) }
				</Button>
			</div>
		</section>
	);
}

function CodeChangePaths( {
	added,
	updated,
	deleted,
}: {
	added: string[];
	updated: string[];
	deleted: string[];
} ) {
	const groups = [
		{ operation: 'add' as const, paths: added },
		{ operation: 'update' as const, paths: updated },
		{ operation: 'delete' as const, paths: deleted },
	].filter( ( group ) => group.paths.length > 0 );
	return (
		<div className="code-change-paths">
			{ groups.map( ( group ) => (
				<div key={ group.operation }>
					<OperationBadge operation={ group.operation } />
					<ul>
						{ group.paths.map( ( path ) => (
							<li key={ path }>
								<code>{ path }</code>
							</li>
						) ) }
					</ul>
				</div>
			) ) }
		</div>
	);
}

function CodeGenerationPanel( {
	workspace,
	plan,
	planned,
	job,
	capability,
	planValid,
	requiresMainFile,
	disabled,
	onGenerate,
	onCancel,
}: {
	workspace: Workspace;
	plan: Job | null;
	planned: Array< {
		path: string;
		type: string;
		action: string;
		description: string;
	} >;
	job: Job | null;
	capability: AgentCapability | null;
	planValid: boolean;
	requiresMainFile: boolean;
	disabled: boolean;
	onGenerate: () => void;
	onCancel: ( job: Job ) => void;
} ) {
	if ( job && [ 'queued', 'running', 'retrying' ].includes( job.status ) ) {
		return <CodeProgress job={ job } onCancel={ onCancel } />;
	}
	return (
		<div className="code-generation">
			<div>
				<p className="eyebrow">
					{ __( 'Ready to generate', 'wp-autoplugin' ) }
				</p>
				<h3>{ planPluginName( plan, workspace.project_name ) }</h3>
				<p>
					{ plan
						? sprintf(
								/* translators: 1: Plan job ID, 2: planned file count. */
								__(
									'Plan job #%1$d · %2$d planned files',
									'wp-autoplugin'
								),
								plan.id,
								planned.length
						  )
						: __(
								'Complete the Plan before generating Code.',
								'wp-autoplugin'
						  ) }
				</p>
				{ capability && (
					<p>
						{ sprintf(
							/* translators: 1: provider name, 2: coder model name. */
							__( 'Coder: %1$s · %2$s', 'wp-autoplugin' ),
							capability.provider,
							capability.model
						) }
					</p>
				) }
			</div>
			{ capability && ! capability.available && (
				<Notice status="warning" isDismissible={ false }>
					{ capability.message }
				</Notice>
			) }
			{ plan && ! planValid && (
				<Notice status="warning" isDismissible={ false }>
					{ requiresMainFile
						? __(
								'This Plan needs a valid main plugin file and 1–20 added PHP, JavaScript, or CSS files. Regenerate the Plan structure before generating Code.',
								'wp-autoplugin'
						  )
						: __(
								'This Plan needs 1–20 valid Add, Update, or Delete actions for PHP, JavaScript, or CSS files. Regenerate the Plan structure before generating Code.',
								'wp-autoplugin'
						  ) }
				</Notice>
			) }
			{ job && [ 'failed', 'cancelled' ].includes( job.status ) && (
				<Notice status="error" isDismissible={ false }>
					{ job.error_message ||
						__(
							'Code generation ended without a revision. The Plan is unchanged and you can generate again.',
							'wp-autoplugin'
						) }
				</Notice>
			) }
			<Button
				variant="primary"
				disabled={ disabled || ! planValid || ! capability?.available }
				onClick={ onGenerate }
			>
				{ job
					? __( 'Generate again', 'wp-autoplugin' )
					: __( 'Generate Code', 'wp-autoplugin' ) }
			</Button>
		</div>
	);
}

function CodeProgress( {
	job,
	onCancel,
	compact = false,
}: {
	job: Job;
	onCancel: ( job: Job ) => void;
	compact?: boolean;
} ) {
	const progress = job.code_progress;
	const analyzing = progress?.phase === 'analysis';
	let heading = __( 'Generating staged revision', 'wp-autoplugin' );
	if ( analyzing ) {
		heading = __( 'Analyzing Code follow-up', 'wp-autoplugin' );
	} else if ( progress?.mode === 'follow_up' ) {
		heading = __( 'Generating Code changes', 'wp-autoplugin' );
	}
	return (
		<div className={ `code-progress ${ compact ? 'is-compact' : '' }` }>
			<div className="code-progress__heading">
				<div>
					<strong>{ heading }</strong>
					<small>
						{ job.latest_event?.message ||
							__( 'Preparing generation…', 'wp-autoplugin' ) }
					</small>
				</div>
				<Button variant="secondary" onClick={ () => onCancel( job ) }>
					{ __( 'Cancel', 'wp-autoplugin' ) }
				</Button>
			</div>
			<progress
				value={
					progress && progress.total > 0
						? progress.completed
						: job.progress
				}
				max={ progress && progress.total > 0 ? progress.total : 100 }
			/>
			{ progress && (
				<div className="code-progress__meta">
					<span>
						{ analyzing
							? __( 'Analysis', 'wp-autoplugin' )
							: sprintf(
									/* translators: 1: completed file count, 2: total file count. */
									__( '%1$d of %2$d files', 'wp-autoplugin' ),
									progress.completed,
									progress.total
							  ) }
					</span>
					<span>
						{ progress.provider } · { progress.model }
					</span>
					<span>
						{ sprintf(
							/* translators: %d: cumulative token count. */
							__( '%d tokens', 'wp-autoplugin' ),
							progress.input_tokens + progress.output_tokens
						) }
					</span>
				</div>
			) }
			{ progress && progress.files.length > 0 && (
				<ul className="code-progress__files">
					{ progress.files.map( ( file ) => (
						<li key={ file.path }>
							<OperationBadge operation={ file.operation } />
							<code>{ file.path }</code>
							<span>{ file.status }</span>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

function OperationBadge( {
	operation,
}: {
	operation: 'add' | 'update' | 'delete';
} ) {
	let label = 'D';
	if ( 'add' === operation ) {
		label = 'A';
	} else if ( 'update' === operation ) {
		label = 'M';
	}
	return (
		<span
			className={ `operation-badge operation-badge--${ operation }` }
			title={ operation }
		>
			{ label }
		</span>
	);
}

type FileTreeItem = RevisionFileManifest;
type TreeNode = { directories: Map< string, TreeNode >; files: FileTreeItem[] };

function FileTree( {
	files,
	directories,
	selectedId,
	dirtyPaths,
	problemPaths,
	progress,
	onSelect,
}: {
	files: FileTreeItem[];
	directories: string[];
	selectedId: number | null;
	dirtyPaths: Set< string >;
	problemPaths: Set< string >;
	progress: Record< string, string >;
	onSelect: ( id: number ) => void;
} ) {
	const root = useMemo( () => {
		const node: TreeNode = { directories: new Map(), files: [] };
		directories.forEach( ( path ) => {
			let cursor = node;
			path.split( '/' ).forEach( ( directory ) => {
				if ( ! directory ) {
					return;
				}
				if ( ! cursor.directories.has( directory ) ) {
					cursor.directories.set( directory, {
						directories: new Map(),
						files: [],
					} );
				}
				cursor = cursor.directories.get( directory ) as TreeNode;
			} );
		} );
		files.forEach( ( file ) => {
			const parts = file.path.split( '/' );
			const name = parts.pop() || file.path;
			let cursor = node;
			parts.forEach( ( directory ) => {
				if ( ! cursor.directories.has( directory ) ) {
					cursor.directories.set( directory, {
						directories: new Map(),
						files: [],
					} );
				}
				cursor = cursor.directories.get( directory ) as TreeNode;
			} );
			cursor.files.push( {
				...file,
				path: [ ...parts, name ].join( '/' ),
			} );
		} );
		return node;
	}, [ files, directories ] );
	return (
		<div
			className="file-tree"
			role="tree"
			tabIndex={ 0 }
			onKeyDown={ treeKeyboardNavigation }
		>
			<FileTreeBranch
				node={ root }
				depth={ 0 }
				selectedId={ selectedId }
				dirtyPaths={ dirtyPaths }
				problemPaths={ problemPaths }
				progress={ progress }
				onSelect={ onSelect }
			/>
		</div>
	);
}

function FileTreeBranch( {
	node,
	depth,
	selectedId,
	dirtyPaths,
	problemPaths,
	progress,
	onSelect,
}: {
	node: TreeNode;
	depth: number;
	selectedId: number | null;
	dirtyPaths: Set< string >;
	problemPaths: Set< string >;
	progress: Record< string, string >;
	onSelect: ( id: number ) => void;
} ) {
	return (
		<>
			{ [ ...node.directories.entries() ].map( ( [ name, child ] ) => (
				<FileTreeDirectory
					key={ name }
					name={ name }
					node={ child }
					depth={ depth }
					selectedId={ selectedId }
					dirtyPaths={ dirtyPaths }
					problemPaths={ problemPaths }
					progress={ progress }
					onSelect={ onSelect }
				/>
			) ) }
			{ node.files.map( ( file ) => {
				const status = progress[ file.path ];
				const dirty = dirtyPaths.has( file.path );
				const operation =
					file.change_type ?? ( dirty ? 'update' : null );
				return (
					<button
						type="button"
						role="treeitem"
						className={ `file-tree__file ${
							selectedId === file.id ? 'is-selected' : ''
						} ${ ! operation ? 'is-untouched' : '' }` }
						style={ {
							paddingInlineStart: `${ 10 + depth * 14 }px`,
						} }
						onClick={ () => onSelect( file.id ) }
						key={ file.id }
					>
						{ operation && (
							<OperationBadge operation={ operation } />
						) }
						<span>{ file.path.split( '/' ).pop() }</span>
						{ dirty && (
							<b
								title={ __(
									'Unsaved changes',
									'wp-autoplugin'
								) }
							>
								●
							</b>
						) }
						{ problemPaths.has( file.path ) && (
							<b
								className="has-problem"
								title={ __(
									'Validation problem',
									'wp-autoplugin'
								) }
							>
								!
							</b>
						) }
						{ status && (
							<i
								className={ `file-status file-status--${ status }` }
							>
								{ fileStatusContent( status ) }
							</i>
						) }
					</button>
				);
			} ) }
		</>
	);
}

function FileTreeDirectory( props: {
	name: string;
	node: TreeNode;
	depth: number;
	selectedId: number | null;
	dirtyPaths: Set< string >;
	problemPaths: Set< string >;
	progress: Record< string, string >;
	onSelect: ( id: number ) => void;
} ) {
	const [ open, setOpen ] = useState( true );
	const untouched = ! treeHasChanges( props.node, props.dirtyPaths );
	return (
		<div role="group">
			<button
				type="button"
				className={ `file-tree__directory ${
					untouched ? 'is-untouched' : ''
				}` }
				aria-expanded={ open }
				style={ { paddingInlineStart: `${ 6 + props.depth * 14 }px` } }
				onClick={ () => setOpen( ! open ) }
			>
				<span aria-hidden="true">{ open ? '⌄' : '›' }</span>
				{ props.name }
			</button>
			{ open && (
				<FileTreeBranch { ...props } depth={ props.depth + 1 } />
			) }
		</div>
	);
}

function treeHasChanges( node: TreeNode, dirtyPaths: Set< string > ): boolean {
	if (
		node.files.some(
			( file ) => !! file.change_type || dirtyPaths.has( file.path )
		)
	) {
		return true;
	}
	return [ ...node.directories.values() ].some( ( child ) =>
		treeHasChanges( child, dirtyPaths )
	);
}

function treeKeyboardNavigation( event: any ) {
	if ( ! [ 'ArrowDown', 'ArrowUp' ].includes( event.key ) ) {
		return;
	}
	const buttons = [
		...event.currentTarget.querySelectorAll< HTMLButtonElement >(
			'button:not([disabled])'
		),
	];
	const index = buttons.indexOf(
		event.currentTarget.ownerDocument.activeElement as HTMLButtonElement
	);
	const next =
		'ArrowDown' === event.key
			? Math.min( buttons.length - 1, index + 1 )
			: Math.max( 0, index - 1 );
	if ( index >= 0 && buttons[ next ] ) {
		event.preventDefault();
		buttons[ next ].focus();
	}
}

function fileStatusContent( status: string ) {
	if ( 'generating' === status ) {
		return <Spinner />;
	}
	if ( 'completed' === status ) {
		return '✓';
	}
	return 'failed' === status ? '!' : '○';
}

function CodeBufferEditor( {
	value,
	type,
	readOnly,
	focusLine,
	onChange,
}: {
	value: string;
	type: string;
	readOnly: boolean;
	focusLine: number;
	onChange: ( value: string ) => void;
} ) {
	const textarea = useRef< HTMLTextAreaElement >( null );
	const editor = useRef< any >( null );
	const onChangeRef = useRef( onChange );
	const initialValue = useRef( value );
	onChangeRef.current = onChange;
	useEffect( () => {
		if ( ! textarea.current || ! window.wp?.codeEditor ) {
			return;
		}
		const base = window.wpAutopluginV2.codeEditorSettings?.[ type ] ?? {};
		let lineSeparator = '\n';
		if ( initialValue.current.includes( '\r\n' ) ) {
			lineSeparator = '\r\n';
		} else if ( initialValue.current.includes( '\r' ) ) {
			lineSeparator = '\r';
		}
		const settings = {
			...base,
			codemirror: {
				...( ( base.codemirror as object ) ?? {} ),
				readOnly: readOnly ? 'nocursor' : false,
				lineSeparator,
			},
		};
		const initialized = window.wp.codeEditor.initialize(
			textarea.current,
			settings
		);
		editor.current = initialized.codemirror;
		if ( editor.current && ! readOnly ) {
			editor.current.on( 'change', ( instance: any ) =>
				onChangeRef.current( instance.getValue() )
			);
		}
		return () => {
			if ( editor.current?.toTextArea ) {
				editor.current.toTextArea();
			}
			editor.current = null;
		};
	}, [ readOnly, type ] );
	useEffect( () => {
		if ( focusLine > 0 && editor.current ) {
			editor.current.setCursor( { line: focusLine - 1, ch: 0 } );
			editor.current.focus();
		}
	}, [ focusLine ] );
	return (
		<div className="code-buffer">
			<textarea
				ref={ textarea }
				defaultValue={ value }
				readOnly={ readOnly }
				spellCheck={ false }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		</div>
	);
}

function DiffView( { html }: { html: string } ) {
	const purify = ( window as any ).DOMPurify;
	const sanitized = purify ? purify.sanitize( html ) : '';
	return sanitized ? (
		<div
			className="code-diff"
			dangerouslySetInnerHTML={ { __html: sanitized } }
		/>
	) : (
		<pre className="code-diff__fallback">
			{ __( 'No changes for this file.', 'wp-autoplugin' ) }
		</pre>
	);
}

function ProblemsPanel( {
	issues,
	onSelect,
}: {
	issues: CodeIssue[];
	onSelect: ( issue: CodeIssue ) => void;
} ) {
	return (
		<section className="code-problems">
			<strong>
				{ sprintf(
					/* translators: %d: number of validation problems. */
					_n(
						'%d problem',
						'%d problems',
						issues.length,
						'wp-autoplugin'
					),
					issues.length
				) }
			</strong>
			<ul>
				{ issues.map( ( issue, index ) => (
					<li key={ `${ issue.path }:${ issue.line }:${ index }` }>
						<button
							type="button"
							onClick={ () => onSelect( issue ) }
						>
							<code>
								{ issue.path }
								{ issue.line ? `:${ issue.line }` : '' }
							</code>
							<span>{ issue.message }</span>
						</button>
					</li>
				) ) }
			</ul>
		</section>
	);
}

type PlannedFile = {
	path: string;
	type: string;
	action: string;
	description: string;
};

function planFiles(
	plan: Job | null,
	normalizeProjectRoot = false
): PlannedFile[] {
	const structure = plan ? planArtifactStructure( plan ) : null;
	const project = structure?.project_structure as
		| Record< string, unknown >
		| undefined;
	const files = Array.isArray( project?.files )
		? project.files
				.filter(
					( file ): file is Record< string, unknown > =>
						!! file && 'object' === typeof file
				)
				.map( ( file ) => ( {
					path: String( file.path ?? '' ),
					type: String( file.type ?? '' ),
					action: String( file.action ?? '' ),
					description: String( file.description ?? '' ),
				} ) )
				.filter(
					( file ) =>
						!! file.path &&
						[ 'php', 'js', 'css' ].includes( file.type ) &&
						[ 'add', 'update', 'delete' ].includes( file.action )
				)
		: [];
	if ( ! normalizeProjectRoot || ! files.length ) {
		return files;
	}

	const parts = files.map( ( file ) => file.path.split( '/' ) );
	const prefix = parts[ 0 ][ 0 ];
	if (
		! prefix ||
		parts.some(
			( pathParts ) => pathParts.length < 2 || pathParts[ 0 ] !== prefix
		)
	) {
		return files;
	}
	const unwrapped = files.map( ( file, index ) => ( {
		...file,
		path: parts[ index ].slice( 1 ).join( '/' ),
	} ) );
	const explicitMain =
		'string' === typeof structure?.main_file ? structure.main_file : '';
	const normalizedMain = explicitMain.startsWith( `${ prefix }/` )
		? explicitMain.slice( prefix.length + 1 )
		: explicitMain;
	const rootPhp = unwrapped.filter(
		( file ) => 'php' === file.type && ! file.path.includes( '/' )
	);
	const inferredMain = rootPhp.find(
		( file ) => file.path === `${ prefix }.php`
	)?.path;
	const mainValid = normalizedMain
		? unwrapped.some(
				( file ) =>
					file.path === normalizedMain &&
					'php' === file.type &&
					! file.path.includes( '/' )
		  )
		: 1 === rootPhp.length || !! inferredMain;
	return mainValid ? unwrapped : files;
}

function planMainFile(
	plan: Job | null,
	files: PlannedFile[],
	normalizeProjectRoot = false
): string {
	const structure = plan ? planArtifactStructure( plan ) : null;
	const explicitMain =
		'string' === typeof structure?.main_file ? structure.main_file : '';
	const candidates = [ explicitMain ];
	if ( normalizeProjectRoot && explicitMain.includes( '/' ) ) {
		candidates.push( explicitMain.split( '/' ).slice( 1 ).join( '/' ) );
	}
	const selected = candidates.find(
		( candidate ) =>
			!! candidate &&
			files.some(
				( file ) =>
					file.path === candidate &&
					'php' === file.type &&
					! file.path.includes( '/' )
			)
	);
	if ( selected ) {
		return selected;
	}
	const rootPhp = files.filter(
		( file ) => 'php' === file.type && ! file.path.includes( '/' )
	);
	if ( 1 === rootPhp.length ) {
		return rootPhp[ 0 ].path;
	}
	const project = structure?.project_structure as
		| Record< string, unknown >
		| undefined;
	const firstRawPath =
		Array.isArray( project?.files ) &&
		project.files[ 0 ] &&
		'object' === typeof project.files[ 0 ] &&
		'string' ===
			typeof ( project.files[ 0 ] as Record< string, unknown > ).path
			? String( ( project.files[ 0 ] as Record< string, unknown > ).path )
			: '';
	const prefix = firstRawPath.includes( '/' )
		? firstRawPath.split( '/' )[ 0 ]
		: '';
	const inferred = rootPhp.find(
		( file ) => !! prefix && file.path === `${ prefix }.php`
	);
	return inferred?.path ?? '';
}

function planPluginName( plan: Job | null, fallback = '' ): string {
	const structure = plan ? planArtifactStructure( plan ) : null;
	return 'string' === typeof structure?.plugin_name
		? structure.plugin_name
		: fallback || __( 'Staged changes', 'wp-autoplugin' );
}

function revisionOrigin( origin: RevisionSummary[ 'origin' ] ): string {
	if ( 'manual' === origin ) {
		return __( 'Edited', 'wp-autoplugin' );
	}
	if ( 'restore' === origin ) {
		return __( 'Restored', 'wp-autoplugin' );
	}
	return __( 'AI generated', 'wp-autoplugin' );
}

function ReviewStage( { plan }: { plan: Job | null } ) {
	return (
		<div className="review-stage">
			<div className="review-stage__summary">
				<span>✓</span>
				<div>
					<strong>{ __( 'Review queue', 'wp-autoplugin' ) }</strong>
					<p>
						{ __(
							'A GitHub-style review will show an all-clear verdict or priority comments pinned to staged code.',
							'wp-autoplugin'
						) }
					</p>
				</div>
			</div>
			<Notice status="info" isDismissible={ false }>
				{ plan?.status === 'completed'
					? __(
							'AI Review is the next stage and is not implemented yet. Staged Code remains available in revision history.',
							'wp-autoplugin'
					  )
					: __(
							'Complete Plan and Code before starting Review.',
							'wp-autoplugin'
					  ) }
			</Notice>
		</div>
	);
}

function ExplainStage( {
	jobs,
	initialMessage,
	onCancel,
	onFollowUp,
}: {
	jobs: Job[];
	initialMessage: string;
	onCancel: ( job: Job ) => void;
	onFollowUp: ( message: string ) => void;
} ) {
	return (
		<StageConversation
			stage="explain"
			jobs={ jobs }
			initialMessage={ initialMessage }
			onCancel={ onCancel }
			onFollowUp={ onFollowUp }
		/>
	);
}

function StageConversation( {
	stage,
	jobs,
	initialMessage = '',
	artifactJobId,
	onCancel,
	onFollowUp,
}: {
	stage: 'plan' | 'explain';
	jobs: Job[];
	initialMessage?: string;
	artifactJobId?: number;
	onCancel: ( job: Job ) => void;
	onFollowUp: ( message: string, artifactJobId?: number ) => void;
} ) {
	const [ message, setMessage ] = useState( '' );
	const isPlan = stage === 'plan';
	const submitMessage = () => {
		if ( ! message.trim() ) {
			return;
		}
		onFollowUp( message, artifactJobId );
		setMessage( '' );
	};
	return (
		<div
			className={ `stage-conversation ${
				isPlan ? 'stage-conversation--plan' : 'explain-stage'
			}` }
		>
			<div className="explain-stage__messages">
				{ jobs.map( ( job ) => (
					<div className="explain-message" key={ job.id }>
						<div className="explain-message__question">
							<strong>{ __( 'You', 'wp-autoplugin' ) }</strong>
							<p>
								{ job.payload.message ||
									initialMessage ||
									__( 'Initial question', 'wp-autoplugin' ) }
							</p>
						</div>
						<div className="explain-message__answer">
							<strong>
								{ isPlan
									? __( 'Plan assistant', 'wp-autoplugin' )
									: __( 'Explain', 'wp-autoplugin' ) }
							</strong>
							{ job.status === 'completed' && job.result ? (
								<>
									{ job.result.outcome === 'artifact' ? (
										<Notice
											status="success"
											isDismissible={ false }
										>
											{ __(
												'Plan updated. The new version is now active above.',
												'wp-autoplugin'
											) }
										</Notice>
									) : (
										<Markdown
											content={ job.result.content || '' }
										/>
									) }
									<Button
										variant="secondary"
										onClick={ () =>
											onFollowUp(
												job.payload.message || '',
												artifactJobId
											)
										}
									>
										{ __(
											'Retry answer',
											'wp-autoplugin'
										) }
									</Button>
								</>
							) : (
								<>
									<JobStatus
										job={ job }
										onCancel={ onCancel }
									/>
									{ [ 'failed', 'cancelled' ].includes(
										job.status
									) && (
										<Button
											variant="secondary"
											onClick={ () =>
												onFollowUp(
													job.payload.message || '',
													artifactJobId
												)
											}
										>
											{ __(
												'Retry answer',
												'wp-autoplugin'
											) }
										</Button>
									) }
								</>
							) }
							{ hasAgentActivity( job ) && (
								<AgentActivity job={ job } />
							) }
						</div>
					</div>
				) ) }
			</div>
			<div className="explain-stage__composer">
				<TextareaControl
					hideLabelFromVision
					label={
						isPlan
							? __(
									'Ask about or change the plan',
									'wp-autoplugin'
							  )
							: __( 'Ask a follow-up question', 'wp-autoplugin' )
					}
					placeholder={
						isPlan
							? __(
									'Ask a question or request a change…',
									'wp-autoplugin'
							  )
							: __( 'Ask a follow-up question…', 'wp-autoplugin' )
					}
					value={ message }
					onChange={ setMessage }
					onKeyDown={ ( event ) => {
						if (
							'Enter' === event.key &&
							! event.shiftKey &&
							! event.nativeEvent.isComposing
						) {
							event.preventDefault();
							submitMessage();
						}
					} }
					rows={ 3 }
				/>
				<Button
					variant="primary"
					disabled={ ! message.trim() }
					onClick={ submitMessage }
				>
					{ isPlan
						? __( 'Send', 'wp-autoplugin' )
						: __( 'Ask', 'wp-autoplugin' ) }
				</Button>
			</div>
		</div>
	);
}

function EmptyStage( {
	action,
	onAction,
	title,
}: {
	action: string;
	onAction: () => void;
	title: string;
} ) {
	return (
		<div className="empty-stage">
			<h2>{ title }</h2>
			<p>
				{ __(
					'No job has been started for this stage yet.',
					'wp-autoplugin'
				) }
			</p>
			<Button variant="primary" onClick={ onAction }>
				{ action }
			</Button>
		</div>
	);
}

function Markdown( { content }: { content: string } ) {
	const marked = ( window as any ).marked;
	const purify = ( window as any ).DOMPurify;
	const html =
		marked && purify ? purify.sanitize( marked.parse( content ) ) : '';
	return (
		<div className="job-result">
			{ html ? (
				<div dangerouslySetInnerHTML={ { __html: html } } />
			) : (
				<pre>{ content }</pre>
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
					<dd>{ formatCompactNumber( target.tokens ) }</dd>
				</div>
				<div>
					<dt>{ __( 'Hooks', 'wp-autoplugin' ) }</dt>
					<dd>{ target.hooks }</dd>
				</div>
			</dl>
		</div>
	);
}

function formatCompactNumber( value: number ) {
	return new Intl.NumberFormat( undefined, {
		notation: 'compact',
		maximumFractionDigits: 0,
	} ).format( value );
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
