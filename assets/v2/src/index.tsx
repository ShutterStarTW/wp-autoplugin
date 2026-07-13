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
	};
	result?: {
		content?: string;
		structured?: Record< string, unknown >;
		outcome?: 'answer' | 'artifact';
		artifact?: {
			type?: string;
			content?: string;
			parent_job_id?: number;
		};
		model?: string;
		usage?: { input_tokens: number; output_tokens: number };
	};
	created_at: string;
};

type PlanSaveResponse = {
	artifact: Job;
	regeneration_job: Job | null;
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
		label: __( 'Hook extension', 'wp-autoplugin' ),
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
		setActiveTab(
			'explain' === activeWorkspace?.operation ? 'explain' : 'plan'
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
	}, [ activeWorkspaceId, activeWorkspace?.operation ] );

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
		task: 'plan' | 'explain' | 'conversation',
		payload = {}
	) {
		if ( ! activeWorkspace ) {
			return;
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
		} catch ( reason: any ) {
			setError( reason.message );
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
				activeTab={ activeTab }
				onTabSelect={ setActiveTab }
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
	jobs,
	jobsLoading,
	activeTab,
	onTabSelect,
	onCancel,
	onCreateJob,
	onSavePlan,
}: {
	workspace: Workspace;
	jobs: Job[];
	jobsLoading: boolean;
	activeTab: string;
	onTabSelect: ( tab: string ) => void;
	onCancel: ( job: Job ) => void;
	onCreateJob: (
		task: 'plan' | 'explain' | 'conversation',
		payload?: object
	) => void;
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
						<CodeStage plan={ latestPlan } />
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
			{ job.error_message && (
				<Notice status="error" isDismissible={ false }>
					{ job.error_message }
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

function PlanStage( {
	job,
	latestRun,
	regenerationJob,
	conversationJobs,
	onCancel,
	onCreate,
	onSave,
	onFollowUp,
}: {
	job: Job | null;
	latestRun: Job | null;
	regenerationJob: Job | null;
	conversationJobs: Job[];
	onCancel: ( job: Job ) => void;
	onCreate: () => void;
	onSave: ( job: Job, content: string ) => Promise< boolean >;
	onFollowUp: ( message: string, artifactJobId: number ) => void;
} ) {
	if ( ! job ) {
		if ( latestRun ) {
			return (
				<>
					<JobStatus job={ latestRun } onCancel={ onCancel } />
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
	onFollowUp,
}: {
	job: Job;
	conversationJobs: Job[];
	regenerationJob: Job | null;
	onCancel: ( job: Job ) => void;
	onSave: ( job: Job, content: string ) => Promise< boolean >;
	onRetry: () => void;
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
						'Code generation will be available once the staged-revision worker is connected.',
						'wp-autoplugin'
					) }
				</span>
				<Button variant="primary" disabled>
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

function CodeStage( { plan }: { plan: Job | null } ) {
	return (
		<div className="code-stage">
			<div className="code-stage__tree">
				<strong>{ __( 'STAGED FILES', 'wp-autoplugin' ) }</strong>
				<p>{ __( 'No staged revision yet.', 'wp-autoplugin' ) }</p>
			</div>
			<div className="code-stage__editor">
				<div className="stage-toolbar">
					<div>
						<strong>
							{ __( 'Code editor', 'wp-autoplugin' ) }
						</strong>
						<small>
							{ __(
								'Files and change markers will appear here.',
								'wp-autoplugin'
							) }
						</small>
					</div>
				</div>
				<Notice status="info" isDismissible={ false }>
					{ plan?.status === 'completed'
						? __(
								'The plan is ready. Code generation needs the v2 staged-revision worker, which is not available yet.',
								'wp-autoplugin'
						  )
						: __(
								'Complete the plan before generating a staged revision.',
								'wp-autoplugin'
						  ) }
				</Notice>
				<pre className="code-stage__placeholder">
					{ __(
						'// Generated files will be editable here with add, modify, and delete markers.',
						'wp-autoplugin'
					) }
				</pre>
				<div className="stage-next">
					<span>
						{ __(
							'Code remains read-only until a staged revision exists.',
							'wp-autoplugin'
						) }
					</span>
					<Button variant="primary" disabled>
						{ __( 'Continue to Review', 'wp-autoplugin' ) }
					</Button>
				</div>
			</div>
		</div>
	);
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
							'Review generation needs a staged code revision, which is not available yet.',
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
					rows={ 3 }
				/>
				<Button
					variant="primary"
					disabled={ ! message.trim() }
					onClick={ () => {
						onFollowUp( message, artifactJobId );
						setMessage( '' );
					} }
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
