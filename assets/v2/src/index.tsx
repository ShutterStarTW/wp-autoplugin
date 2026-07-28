import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	Dropdown,
	DropdownMenu,
	Modal,
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
	stylesheet?: string;
	template?: string;
	is_child?: boolean;
	is_block_theme?: boolean;
	parent_ref?: string;
	parent_available?: boolean;
	parent_name?: string;
	parent_version?: string;
	active_as_stylesheet?: boolean;
	active_as_template?: boolean;
	in_use?: boolean;
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
		mode?:
			| 'generate'
			| 'regenerate'
			| 'project'
			| 'fork'
			| 'replacement'
			| 'theme_replacement'
			| 'install_project'
			| 'install_fork'
			| 'modify_original'
			| 'install_theme_copy'
			| 'modify_theme_original';
		plan_artifact_job_id?: number;
		parent_revision_id?: number;
		revision_id?: number;
		expected_latest_revision_id?: number | null;
		focused_path?: string;
		review_report_id?: number;
		parent_report_id?: number;
		finding_ids?: number[];
		auto_re_review?: boolean;
		action?: 'activate' | 'rollback';
		promotion_id?: number;
		prompt_model?: ModelSnapshot;
		reviewer?: ModelSnapshot;
	};
	result?: {
		content?: string;
		structured?: Record< string, unknown >;
		outcome?:
			| 'answer'
			| 'artifact'
			| 'revision'
			| 'blocked'
			| 'report'
			| 'package'
			| 'promotion'
			| 'promotion_action';
		artifact?: {
			type?: string;
			content?: string;
			parent_job_id?: number;
		};
		model?: string;
		provider?: string;
		effort?: string;
		revision_id?: number;
		report_id?: number;
		package_id?: number;
		promotion_id?: number;
		status?: string;
		mode?: string;
		action?: 'activate' | 'rollback';
		artifact_kind?: 'plugin' | 'theme';
		target_ref?: string;
		plugin_file?: string;
		template?: string;
		is_child?: boolean;
		expires_at?: string;
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
	prompt_attachments: PromptAttachment[];
};

type PromptAttachment = {
	id: number;
	filename: string;
	mime_type: string;
	byte_size: number;
	width: number;
	height: number;
	sha256: string;
	preview_path: string;
};

type CodeProgress = {
	mode?: 'generate' | 'regenerate' | 'follow_up';
	phase?:
		| 'analysis'
		| 'files'
		| 'compliance'
		| 'completed'
		| 'failed'
		| 'cancelled';
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
	origin: 'ai' | 'manual' | 'restore' | 'review_fix';
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

type ReviewFindingEvent = {
	id: number;
	event: string;
	actor: string;
	message: string;
	report_id: number | null;
	revision_id: number;
	created_at: string;
};

type ReviewFinding = {
	id: number;
	label: string;
	status: 'open' | 'addressed' | 'resolved' | 'retracted' | 'dismissed';
	priority: 'P0' | 'P1' | 'P2' | 'P3';
	category:
		| 'security'
		| 'correctness'
		| 'compatibility'
		| 'performance'
		| 'maintainability';
	title: string;
	body: string;
	suggested_fix: string;
	path: string | null;
	side: 'staged' | 'base' | null;
	start_line: number | null;
	end_line: number | null;
	source_revision_id: number;
	timeline: ReviewFindingEvent[];
};

type ReviewTest = { title: string; steps: string[]; expected: string };

type ReviewReport = {
	id: number;
	job_id: number;
	workspace_id: number;
	revision_id: number;
	parent_report_id: number | null;
	mode: 'initial' | 'verification' | 'follow_up';
	verdict: 'all_clear' | 'action_required';
	effective_status:
		| 'all_clear'
		| 'cleared_with_dismissals'
		| 'action_required'
		| 'stale'
		| 'in_progress'
		| 'failed'
		| 'not_started';
	summary: string;
	tests: ReviewTest[];
	provider: string;
	model: string;
	effort: string;
	created_at: string;
	findings: ReviewFinding[];
};

type ReviewHistory = {
	items: Array<
		Pick<
			ReviewReport,
			| 'id'
			| 'job_id'
			| 'revision_id'
			| 'parent_report_id'
			| 'mode'
			| 'verdict'
			| 'effective_status'
			| 'summary'
			| 'provider'
			| 'model'
			| 'effort'
			| 'created_at'
		>
	>;
	current: {
		status:
			| ReviewReport[ 'effective_status' ]
			| 'in_progress'
			| 'failed'
			| 'not_started';
		report_id: number | null;
		revision_id: number | null;
		open: number;
		dismissed: number;
	};
	latest_revision_id: number | null;
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

type TokenUsageModel = {
	provider: string;
	model: string;
	input_tokens: number;
	output_tokens: number;
};

type TokenUsageSummary = {
	project_id: number;
	total: {
		input_tokens: number;
		output_tokens: number;
	};
	models: Array<
		TokenUsageModel & {
			job_count: number;
		}
	>;
	executed_jobs: Array< {
		id: number;
		task: string;
		stage: string;
		mode: string;
		status: string;
		input_tokens: number;
		output_tokens: number;
		models: TokenUsageModel[];
		created_at: string;
		started_at: string;
		finished_at: string;
	} >;
};

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
	direct_review: AgentCapability;
	models: ModelSettings;
	release: ReleaseCapability;
};

type ModelSnapshot = {
	provider: string;
	model: string;
	effort: string;
};

type ModelCatalogItem = {
	id: string;
	label: string;
	provider: string;
	provider_label: string;
	configured: boolean;
	available: boolean;
	direct: boolean;
	native_read_tools: boolean;
	images: boolean;
	effort_levels: string[];
	default_effort: string;
	availability_message: string;
};

type ModelRoleSelection = {
	role: 'planner' | 'coder' | 'reviewer';
	configured_model: string;
	inherits_default: boolean;
	model: string;
	label: string;
	provider: string;
	configured: boolean;
	available: boolean;
	direct: boolean;
	native_read_tools: boolean;
	images: boolean;
	effort: string;
	effort_levels: string[];
	default_effort: string;
	availability_message: string;
};

type ModelSettings = {
	catalog: ModelCatalogItem[];
	default: Omit<
		ModelRoleSelection,
		'role' | 'configured_model' | 'inherits_default'
	>;
	roles: Record< ModelRoleSelection[ 'role' ], ModelRoleSelection >;
};

type ModelUpdateHandler = (
	role: ModelRoleSelection[ 'role' ],
	model: string,
	effort: string
) => Promise< boolean >;

type ReleaseCapability = {
	zip: boolean;
	file_modifications: boolean;
	single_site_mutations: boolean;
	can_download: boolean;
	can_install: boolean;
	can_activate: boolean;
	can_modify: boolean;
	can_install_themes: boolean;
	can_modify_themes: boolean;
	themes_url: string;
	disabled_reasons: string[];
};

type AgentCapability = {
	available: boolean;
	provider: string;
	model: string;
	effort: string;
	message: string;
	images: boolean;
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
	is_closed: number;
	closed_at: string | null;
	updated_at: string;
	activity_summary?: {
		total_jobs: number;
		follow_up_jobs: number;
		retry_count: number;
		stages: Partial<
			Record<
				'plan' | 'code' | 'review' | 'chat',
				| 'complete'
				| 'in_progress'
				| 'incomplete'
				| 'not_started'
				| 'all_clear'
				| 'cleared_with_dismissals'
				| 'action_required'
				| 'stale'
				| 'failed'
			>
		>;
	};
};

type ProjectsResponse = {
	items: Workspace[];
	page: number;
	per_page: number;
	total: number;
	total_pages: number;
	has_more: boolean;
};

type DeleteProjectResponse = {
	project_id: number;
	workspace_ids: number[];
	deleted: true;
};

const ACTIVE_WORKSPACE_KEY = 'wp-autoplugin-v2-active-workspace';
const ACTIVE_STAGE_KEY_PREFIX = 'wp-autoplugin-v2-active-stage:';
const DISTRACTION_FREE_KEY = 'wp-autoplugin-v2-distraction-free';
const DISTRACTION_FREE_CLASS = 'wp-autoplugin-v2-distraction-free';
const PROJECTS_PAGE_SIZE = 20;
const PROJECT_SEARCH_DELAY = 300;
const MAX_PROMPT_IMAGES = 6;
const MAX_PROMPT_IMAGE_BYTES = 5 * 1024 * 1024;
const MAX_PROMPT_IMAGE_TOTAL = 20 * 1024 * 1024;
const PROMPT_IMAGE_TYPES = [ 'image/jpeg', 'image/png', 'image/webp' ];
const GENERATED_FILE_TYPES = [
	'php',
	'js',
	'css',
	'json',
	'html',
	'md',
	'txt',
];
let promptComposerSequence = 0;

async function postJob(
	workspaceId: number,
	task: string,
	payload: object,
	images: File[] = [],
	attachmentIds: number[] = []
): Promise< Job > {
	if ( ! images.length && ! attachmentIds.length ) {
		return apiFetch< Job >( {
			path: `${ rest }/jobs`,
			method: 'POST',
			data: { workspace_id: workspaceId, task, payload },
		} );
	}

	const body = new FormData();
	body.append( 'workspace_id', String( workspaceId ) );
	body.append( 'task', task );
	body.append( 'payload', JSON.stringify( payload ) );
	body.append( 'prompt_attachment_ids', JSON.stringify( attachmentIds ) );
	images.forEach( ( file ) =>
		body.append( 'prompt_images[]', file, file.name )
	);
	return apiFetch< Job >( {
		path: `${ rest }/jobs`,
		method: 'POST',
		body,
	} );
}

function StoredPromptImages( {
	attachments,
}: {
	attachments?: PromptAttachment[];
} ) {
	if ( ! attachments?.length ) {
		return null;
	}
	return (
		<div className="prompt-attachments prompt-attachments--stored">
			{ attachments.map( ( attachment ) => (
				<StoredPromptImage
					attachment={ attachment }
					key={ attachment.id }
				/>
			) ) }
		</div>
	);
}

function StoredPromptImage( { attachment }: { attachment: PromptAttachment } ) {
	const [ source, setSource ] = useState( '' );
	const [ failed, setFailed ] = useState( false );
	useEffect( () => {
		let current = true;
		let objectUrl = '';
		setSource( '' );
		setFailed( false );
		apiFetch< Response >( { path: attachment.preview_path, parse: false } )
			.then( ( response ) => response.blob() )
			.then( ( blob ) => {
				if ( current ) {
					objectUrl = URL.createObjectURL( blob );
					setSource( objectUrl );
				}
			} )
			.catch( () => {
				if ( current ) {
					setFailed( true );
				}
			} );
		return () => {
			current = false;
			if ( objectUrl ) {
				URL.revokeObjectURL( objectUrl );
			}
		};
	}, [ attachment.preview_path ] );
	let preview = <Spinner />;
	if ( source ) {
		preview = <img src={ source } alt={ attachment.filename } />;
	} else if ( failed ) {
		preview = (
			<span className="prompt-attachment__preview-error">
				{ __( 'Preview unavailable', 'wp-autoplugin' ) }
			</span>
		);
	}
	return (
		<div className="prompt-attachment prompt-attachment--stored">
			{ preview }
			<div className="prompt-attachment__metadata">
				<small title={ attachment.filename }>
					{ attachment.filename }
				</small>
				<small>
					{ attachment.width }×{ attachment.height } ·{ ' ' }
					{ formatBytes( attachment.byte_size ) }
				</small>
			</div>
		</div>
	);
}

function PromptComposerField( {
	label,
	value,
	files,
	onChange,
	onFilesChange,
	imageEnabled,
	action,
	disabled = false,
	hideLabelFromVision = false,
	placeholder,
	help,
	onKeyDown,
}: {
	label: string;
	value: string;
	files: File[];
	onChange: ( value: string ) => void;
	onFilesChange: ( files: File[] ) => void;
	imageEnabled: boolean;
	action?: React.ReactNode;
	disabled?: boolean;
	hideLabelFromVision?: boolean;
	placeholder?: string;
	help?: string;
	onKeyDown?: ( event: any ) => void;
} ) {
	const input = useRef< HTMLInputElement | null >( null );
	const textarea = useRef< HTMLTextAreaElement | null >( null );
	const dragDepth = useRef( 0 );
	const [ id ] = useState(
		() => `wp-autoplugin-prompt-${ ++promptComposerSequence }`
	);
	const [ error, setError ] = useState( '' );
	const [ dragging, setDragging ] = useState( false );
	const resize = useCallback( () => {
		const field = textarea.current;
		if ( ! field ) {
			return;
		}
		field.style.height = 'auto';
		field.style.height = `${ Math.min( field.scrollHeight, 160 ) }px`;
		field.style.overflowY = field.scrollHeight > 160 ? 'auto' : 'hidden';
	}, [] );
	useEffect( resize, [ resize, value ] );
	const addFiles = ( incoming: File[] ) => {
		if ( ! imageEnabled ) {
			setError(
				__(
					'The selected model does not accept image prompts.',
					'wp-autoplugin'
				)
			);
			return;
		}
		const next = [ ...files ];
		for ( const file of incoming ) {
			if ( next.length >= MAX_PROMPT_IMAGES ) {
				setError(
					__( 'Attach no more than six images.', 'wp-autoplugin' )
				);
				return;
			}
			if ( ! PROMPT_IMAGE_TYPES.includes( file.type ) ) {
				setError(
					__( 'Use JPEG, PNG, or WebP images.', 'wp-autoplugin' )
				);
				return;
			}
			if ( file.size > MAX_PROMPT_IMAGE_BYTES ) {
				setError(
					__(
						'Each image must be 5 MiB or smaller.',
						'wp-autoplugin'
					)
				);
				return;
			}
			if (
				next.reduce( ( total, item ) => total + item.size, 0 ) +
					file.size >
				MAX_PROMPT_IMAGE_TOTAL
			) {
				setError(
					__(
						'Images may use at most 20 MiB in total.',
						'wp-autoplugin'
					)
				);
				return;
			}
			next.push( file );
		}
		setError( '' );
		onFilesChange( next );
	};
	return (
		<div className="prompt-composer-field">
			<label
				htmlFor={ id }
				className={ hideLabelFromVision ? 'screen-reader-text' : '' }
			>
				{ label }
			</label>
			<div className="prompt-composer-field__row">
				<div
					className={ `prompt-composer-field__control ${
						dragging ? 'is-dragging' : ''
					} ${ disabled ? 'is-disabled' : '' } ${
						imageEnabled ? 'has-image-control' : ''
					}` }
					onDragEnter={ ( event ) => {
						event.preventDefault();
						dragDepth.current += 1;
						if (
							! disabled &&
							imageEnabled &&
							Array.from( event.dataTransfer.types ).includes(
								'Files'
							)
						) {
							setDragging( true );
						}
					} }
					onDragOver={ ( event ) => event.preventDefault() }
					onDragLeave={ ( event ) => {
						event.preventDefault();
						dragDepth.current = Math.max(
							0,
							dragDepth.current - 1
						);
						if ( 0 === dragDepth.current ) {
							setDragging( false );
						}
					} }
					onDrop={ ( event ) => {
						event.preventDefault();
						dragDepth.current = 0;
						setDragging( false );
						if ( ! disabled ) {
							addFiles( Array.from( event.dataTransfer.files ) );
						}
					} }
				>
					<textarea
						id={ id }
						ref={ textarea }
						rows={ 1 }
						value={ value }
						placeholder={ placeholder }
						disabled={ disabled }
						onChange={ ( event ) =>
							onChange( event.currentTarget.value )
						}
						onInput={ resize }
						onKeyDown={ onKeyDown }
					/>
					<input
						ref={ input }
						type="file"
						accept={ PROMPT_IMAGE_TYPES.join( ',' ) }
						multiple
						hidden
						disabled={ disabled || ! imageEnabled }
						onChange={ ( event ) => {
							addFiles(
								Array.from( event.currentTarget.files || [] )
							);
							event.currentTarget.value = '';
						} }
					/>
					{ imageEnabled && (
						<button
							type="button"
							className="prompt-composer-field__attach"
							disabled={ disabled }
							onClick={ () => input.current?.click() }
							aria-label={ __(
								'Attach images',
								'wp-autoplugin'
							) }
							title={ __( 'Attach images', 'wp-autoplugin' ) }
						>
							<svg
								viewBox="0 0 24 24"
								aria-hidden="true"
								focusable="false"
							>
								<path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Zm1.5 0v10.6l3.7-3.7a1 1 0 0 1 1.4 0l2.1 2.1 1.7-1.7a1 1 0 0 1 1.4 0l2.7 2.7v-10h-13Zm9.25 5a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" />
							</svg>
						</button>
					) }
					{ files.length > 0 && (
						<div className="prompt-attachments prompt-attachments--pending">
							{ files.map( ( file, index ) => (
								<PendingPromptImage
									file={ file }
									key={ `${ file.name }:${ file.size }:${ file.lastModified }:${ index }` }
									onRemove={ () => {
										setError( '' );
										onFilesChange(
											files.filter(
												( _, itemIndex ) =>
													itemIndex !== index
											)
										);
									} }
								/>
							) ) }
						</div>
					) }
				</div>
				{ action }
			</div>
			{ error && (
				<p className="prompt-composer-field__error" role="alert">
					{ error }
				</p>
			) }
			{ files.length > 0 && ! imageEnabled && (
				<p className="prompt-composer-field__error" role="alert">
					{ __(
						'The selected model does not accept image prompts. Remove the selected images or choose another model.',
						'wp-autoplugin'
					) }
				</p>
			) }
			{ help && <p className="prompt-composer-field__help">{ help }</p> }
		</div>
	);
}

function PendingPromptImage( {
	file,
	onRemove,
}: {
	file: File;
	onRemove: () => void;
} ) {
	const source = useMemo( () => URL.createObjectURL( file ), [ file ] );
	useEffect( () => () => URL.revokeObjectURL( source ), [ source ] );
	return (
		<div className="prompt-attachment prompt-attachment--pending">
			<img src={ source } alt={ file.name } />
			<button
				type="button"
				onClick={ onRemove }
				aria-label={ sprintf(
					/* translators: %s: attached image filename. */
					__( 'Remove %s', 'wp-autoplugin' ),
					file.name
				) }
			>
				×
			</button>
			<div className="prompt-attachment__metadata">
				<small title={ file.name }>{ file.name }</small>
				<small>{ formatBytes( file.size ) }</small>
			</div>
		</div>
	);
}

function workspaceStages( operation?: string ): string[] {
	return operation === 'explain'
		? [ 'explain' ]
		: [ 'plan', 'code', 'review' ];
}

function WorkspaceLoader() {
	return (
		<main
			className="wp-autoplugin-v2-loading"
			role="status"
			aria-live="polite"
			aria-busy="true"
		>
			<header className="wp-autoplugin-v2-loading__header">
				<p className="wp-autoplugin-v2-loading__eyebrow">
					WP-Autoplugin
				</p>
			</header>
			<div className="wp-autoplugin-v2-loading__shell">
				<div
					className="wp-autoplugin-v2-loading__tabs"
					aria-hidden="true"
				>
					<span className="wp-autoplugin-v2-loading__tab">
						<span className="wp-autoplugin-v2-loading__tab-dot" />
						<span className="wp-autoplugin-v2-loading__tab-line" />
					</span>
					<span className="wp-autoplugin-v2-loading__new-tab">+</span>
				</div>
				<div className="wp-autoplugin-v2-loading__canvas">
					<div className="wp-autoplugin-v2-loading__status">
						<div
							className="wp-autoplugin-v2-loading__mark"
							aria-hidden="true"
						>
							&lt;/&gt;
						</div>
						<p className="wp-autoplugin-v2-loading__title">
							{ __(
								'Preparing your workspace',
								'wp-autoplugin'
							) }
						</p>
						<p className="wp-autoplugin-v2-loading__copy">
							{ __(
								'Restoring your projects and recent work…',
								'wp-autoplugin'
							) }
						</p>
						<div
							className="wp-autoplugin-v2-loading__progress"
							aria-hidden="true"
						>
							<span />
						</div>
					</div>
				</div>
			</div>
		</main>
	);
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
	const [ initializing, setInitializing ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ allProjectsOpen, setAllProjectsOpen ] = useState( false );
	const [ distractionFree, setDistractionFree ] = useState(
		() => 'true' === window.localStorage.getItem( DISTRACTION_FREE_KEY )
	);
	const refreshBootstrap = useCallback( async () => {
		const response = await apiFetch< Bootstrap >( {
			path: `${ rest }/bootstrap`,
		} );
		setBootstrap( response );
		return response;
	}, [] );

	useEffect( () => {
		const documentRoots = [ document.documentElement, document.body ];
		documentRoots.forEach( ( element ) =>
			element.classList.toggle( DISTRACTION_FREE_CLASS, distractionFree )
		);
		window.localStorage.setItem(
			DISTRACTION_FREE_KEY,
			String( distractionFree )
		);

		return () =>
			documentRoots.forEach( ( element ) =>
				element.classList.remove( DISTRACTION_FREE_CLASS )
			);
	}, [ distractionFree ] );

	useEffect( () => {
		Promise.all( [
			refreshBootstrap(),
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
			.finally( () => setInitializing( false ) );
	}, [ refreshBootstrap ] );

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
				.then( async ( updatedJobs ) => {
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
					if (
						activeWorkspaceId !== 'new' &&
						updatedJobs.some( ( item ) =>
							[ 'completed', 'failed', 'cancelled' ].includes(
								item.status
							)
						)
					) {
						const refreshed = await apiFetch< { items: Job[] } >( {
							path: `${ rest }/workspaces/${ activeWorkspaceId }/jobs`,
						} );
						setJobs( refreshed.items );
					}
				} )
				.catch( ( reason ) => setError( reason.message ) );
		}, 2000 );
		return () => window.clearInterval( timer );
	}, [ jobs, activeWorkspaceId ] );

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

	async function start( images: File[] = [] ): Promise< boolean > {
		if ( ! target || ( ! request.trim() && ! images.length ) ) {
			return false;
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
			const created = await postJob(
				workspaceId as number,
				operation === 'explain' ? 'explain' : 'plan',
				operation === 'explain' ? { message: request } : {},
				images
			);
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
			return true;
		} catch ( reason: any ) {
			setError( reason.message );
			return false;
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

	async function reopenWorkspace( workspaceId: number ) {
		const reopened = await apiFetch< Workspace >( {
			path: `${ rest }/workspaces/${ workspaceId }/reopen`,
			method: 'POST',
		} );
		setWorkspaces( ( current ) => [
			reopened,
			...current.filter( ( item ) => item.id !== reopened.id ),
		] );
		setActiveWorkspaceId( reopened.id );
	}

	async function openProject( project: Workspace ) {
		if ( project.is_closed ) {
			await reopenWorkspace( project.id );
			return;
		}

		setWorkspaces( ( current ) =>
			current.some( ( item ) => item.id === project.id )
				? current.map( ( item ) =>
						item.id === project.id ? project : item
				  )
				: [ project, ...current ]
		);
		setActiveWorkspaceId( project.id );
	}

	async function deleteProject( project: Workspace ) {
		const deleted = await apiFetch< DeleteProjectResponse >( {
			path: `${ rest }/projects/${ project.project_id }`,
			method: 'DELETE',
		} );
		const deletedWorkspaceIds = new Set( deleted.workspace_ids );
		const remaining = workspaces.filter(
			( item ) => ! deletedWorkspaceIds.has( item.id )
		);

		setWorkspaces( remaining );
		window.localStorage.removeItem(
			`${ ACTIVE_STAGE_KEY_PREFIX }${ deleted.project_id }`
		);
		if (
			activeWorkspaceId !== 'new' &&
			deletedWorkspaceIds.has( activeWorkspaceId )
		) {
			setActiveWorkspaceId( remaining[ 0 ]?.id ?? 'new' );
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
		task:
			| 'plan'
			| 'code'
			| 'review'
			| 'review_fix'
			| 'explain'
			| 'conversation',
		payload = {},
		images: File[] = [],
		attachmentIds: number[] = []
	) {
		if ( ! activeWorkspace ) {
			return null;
		}
		setError( '' );
		try {
			const created = await postJob(
				activeWorkspace.id,
				task,
				payload,
				images,
				attachmentIds
			);
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

	async function updateModelSetting(
		role: ModelRoleSelection[ 'role' ],
		model: string,
		effort: string
	): Promise< boolean > {
		setError( '' );
		try {
			await apiFetch( {
				path: `${ rest }/model-settings/${ role }`,
				method: 'POST',
				data: { model, effort },
			} );
			await refreshBootstrap();
			return true;
		} catch ( reason: any ) {
			setError( reason.message );
			return false;
		}
	}

	async function queueWorkspaceEndpoint( path: string, data: object ) {
		if ( ! activeWorkspace ) {
			return null;
		}
		setError( '' );
		try {
			const created = await apiFetch< Job >( {
				path,
				method: 'POST',
				data,
			} );
			setJobs( ( items ) => [ ...items, created ] );
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

	if ( initializing ) {
		return <WorkspaceLoader />;
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
				modelSettings={ bootstrap?.models ?? null }
				onTargetSearch={ setTargetSearch }
				onTargetSelect={ setTargetKey }
				onTargetKindSelect={ selectTargetKind }
				onOperationSelect={ setOperation }
				onRequestChange={ setRequest }
				onStart={ start }
				onUpdateModel={ updateModelSetting }
			/>
		);
	} else if ( activeWorkspace ) {
		workspaceContent = (
			<WorkspaceView
				workspace={ activeWorkspace }
				jobs={ jobs }
				jobsLoading={ jobsLoading }
				codeCapability={ bootstrap?.direct_code ?? null }
				reviewCapability={ bootstrap?.direct_review ?? null }
				planCapability={ bootstrap?.plan_agent ?? null }
				directPlanCapability={ bootstrap?.direct_plan ?? null }
				explainCapability={ bootstrap?.explain_agent ?? null }
				modelSettings={ bootstrap?.models ?? null }
				releaseCapability={ bootstrap?.release ?? null }
				activeTab={ activeTab }
				onTabSelect={ selectWorkspaceStage }
				onCancel={ cancel }
				onCreateJob={ createJob }
				onQueueEndpoint={ queueWorkspaceEndpoint }
				onSavePlan={ savePlan }
				onUpdateModel={ updateModelSetting }
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
				distractionFree={ distractionFree }
				onSelect={ setActiveWorkspaceId }
				onClose={ closeWorkspace }
				onNew={ () => setActiveWorkspaceId( 'new' ) }
				onLoadProjects={ () => setAllProjectsOpen( true ) }
				onDistractionFreeToggle={ () =>
					setDistractionFree( ( current ) => ! current )
				}
			/>
			{ allProjectsOpen && (
				<AllProjectsModal
					onClose={ () => setAllProjectsOpen( false ) }
					onOpen={ openProject }
					onDelete={ deleteProject }
				/>
			) }
			{ workspaceContent }
		</main>
	);
}

function modelRoleLabel( role: ModelRoleSelection[ 'role' ] ): string {
	if ( role === 'planner' ) {
		return __( 'Planner', 'wp-autoplugin' );
	}
	if ( role === 'coder' ) {
		return __( 'Coder', 'wp-autoplugin' );
	}
	return __( 'Reviewer', 'wp-autoplugin' );
}

function modelIdentity( snapshot?: Partial< ModelSnapshot > | null ): string {
	if ( ! snapshot?.model ) {
		return '';
	}
	return [ snapshot.provider, snapshot.model, snapshot.effort ]
		.filter( Boolean )
		.join( ' · ' );
}

function modelSupportsContext(
	model: ModelCatalogItem | undefined,
	context: 'direct' | 'native'
): boolean {
	return Boolean(
		model?.configured &&
			model.available &&
			model.direct &&
			( context === 'direct' || model.native_read_tools )
	);
}

function StageModelControl( {
	modelRole: role,
	context,
	settings,
	onUpdate,
}: {
	modelRole: ModelRoleSelection[ 'role' ];
	context: 'direct' | 'native';
	settings: ModelSettings;
	onUpdate: ModelUpdateHandler;
} ) {
	const selection = settings.roles[ role ];
	const [ draftModel, setDraftModel ] = useState(
		selection.configured_model
	);
	const [ draftEffort, setDraftEffort ] = useState( selection.effort );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		setDraftModel( selection.configured_model );
		setDraftEffort( selection.effort );
		setSaving( false );
		setError( '' );
	}, [ selection.configured_model, selection.effort ] );

	const groups = useMemo( () => {
		const grouped = new Map< string, ModelCatalogItem[] >();
		settings.catalog.forEach( ( model ) => {
			const items = grouped.get( model.provider_label ) ?? [];
			items.push( model );
			grouped.set( model.provider_label, items );
		} );
		return [ ...grouped.entries() ];
	}, [ settings.catalog ] );
	const currentItem = settings.catalog.find(
		( model ) => model.id === selection.model
	);
	const currentAvailable = modelSupportsContext( currentItem, context );

	return (
		<div className="stage-model-control">
			<Dropdown
				className="stage-model-control__dropdown"
				contentClassName="stage-model-popover"
				renderToggle={ ( { isOpen, onToggle } ) => (
					<Button
						className={ `stage-model-control__chip ${
							currentAvailable ? '' : 'has-warning'
						}` }
						variant="secondary"
						aria-expanded={ isOpen }
						onClick={ () => {
							if ( ! isOpen ) {
								setDraftModel( selection.configured_model );
								setDraftEffort( selection.effort );
								setError( '' );
							}
							onToggle();
						} }
					>
						{ modelIdentity( selection ) ||
							__( 'Choose model', 'wp-autoplugin' ) }
					</Button>
				) }
				renderContent={ ( { onClose } ) => {
					const inherited = draftModel === '';
					const effectiveModel = inherited
						? settings.default.model
						: draftModel;
					const selectedModel = settings.catalog.find(
						( model ) => model.id === effectiveModel
					);
					const canApply = modelSupportsContext(
						selectedModel,
						context
					);
					const effectiveEffort = inherited
						? settings.default.effort
						: draftEffort;
					return (
						<div className="stage-model-popover__body">
							<h3>
								{ sprintf(
									/* translators: %s: AI model role. */
									__( '%s model', 'wp-autoplugin' ),
									modelRoleLabel( role )
								) }
							</h3>
							<label htmlFor={ `stage-model-${ role }` }>
								{ __( 'Model', 'wp-autoplugin' ) }
							</label>
							<select
								id={ `stage-model-${ role }` }
								value={ draftModel }
								disabled={ saving }
								onChange={ ( event ) => {
									const model = event.target.value;
									setDraftModel( model );
									const definition = settings.catalog.find(
										( item ) => item.id === model
									);
									setDraftEffort(
										model
											? definition?.default_effort || ''
											: ''
									);
								} }
							>
								<option
									value=""
									disabled={
										! modelSupportsContext(
											settings.catalog.find(
												( model ) =>
													model.id ===
													settings.default.model
											),
											context
										)
									}
								>
									{ sprintf(
										/* translators: %s: Default model ID. */
										__(
											'Use Default Model (%s)',
											'wp-autoplugin'
										),
										settings.default.model
									) }
								</option>
								{ groups.map( ( [ provider, models ] ) => (
									<optgroup
										label={ provider }
										key={ provider }
									>
										{ models.map( ( model ) => (
											<option
												value={ model.id }
												disabled={
													! modelSupportsContext(
														model,
														context
													)
												}
												key={ model.id }
											>
												{ model.label === model.id
													? model.id
													: `${ model.label } (${ model.id })` }
												{ ! model.available &&
													` — ${ __(
														'unavailable',
														'wp-autoplugin'
													) }` }
											</option>
										) ) }
									</optgroup>
								) ) }
							</select>
							{ inherited ? (
								<p className="stage-model-popover__help">
									{ sprintf(
										/* translators: 1: Provider, 2: model, 3: effort. */
										__(
											'Inherited: %1$s · %2$s%3$s',
											'wp-autoplugin'
										),
										settings.default.provider,
										settings.default.model,
										settings.default.effort
											? ` · ${ settings.default.effort }`
											: ''
									) }
								</p>
							) : (
								selectedModel &&
								selectedModel.effort_levels.length > 0 && (
									<>
										<label
											htmlFor={ `stage-effort-${ role }` }
										>
											{ __( 'Effort', 'wp-autoplugin' ) }
										</label>
										<select
											id={ `stage-effort-${ role }` }
											value={ effectiveEffort }
											disabled={ saving }
											onChange={ ( event ) =>
												setDraftEffort(
													event.target.value
												)
											}
										>
											{ selectedModel.effort_levels.map(
												( effort ) => (
													<option
														value={ effort }
														key={ effort }
													>
														{ effort }
														{ effort ===
														selectedModel.default_effort
															? ` (${ __(
																	'model default',
																	'wp-autoplugin'
															  ) })`
															: '' }
													</option>
												)
											) }
										</select>
									</>
								)
							) }
							{ ! canApply && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ selectedModel?.availability_message ||
										( context === 'native'
											? __(
													'This Plan requires a configured OpenAI, Anthropic, or ChatGPT Subscription model with native source tools.',
													'wp-autoplugin'
											  )
											: __(
													'Configure this model provider before using it.',
													'wp-autoplugin'
											  ) ) }
								</Notice>
							) }
							{ error && (
								<p className="stage-model-popover__error">
									{ error }
								</p>
							) }
							<p className="stage-model-popover__help">
								{ __(
									'Changes apply globally to future jobs. Queued, running, and completed jobs keep their saved model.',
									'wp-autoplugin'
								) }
							</p>
							<a href={ window.wpAutopluginV2.settingsUrl }>
								{ __( 'Provider settings', 'wp-autoplugin' ) }
							</a>
							<div className="stage-model-popover__actions">
								<Button
									variant="tertiary"
									disabled={ saving }
									onClick={ onClose }
								>
									{ __( 'Cancel', 'wp-autoplugin' ) }
								</Button>
								<Button
									variant="primary"
									isBusy={ saving }
									disabled={ saving || ! canApply }
									onClick={ async () => {
										setSaving( true );
										setError( '' );
										if (
											await onUpdate(
												role,
												draftModel,
												draftEffort
											)
										) {
											onClose();
										} else {
											setError(
												__(
													'The model setting could not be saved.',
													'wp-autoplugin'
												)
											);
											setSaving( false );
										}
									} }
								>
									{ __( 'Apply', 'wp-autoplugin' ) }
								</Button>
							</div>
						</div>
					);
				} }
			/>
		</div>
	);
}

function formatTokenCount( count: number ): string {
	return new Intl.NumberFormat().format( count );
}

function tokenUsageJobLabel(
	job: TokenUsageSummary[ 'executed_jobs' ][ number ]
): string {
	if ( job.task === 'conversation' ) {
		return sprintf(
			/* translators: %s: Plan, Code, or Review stage name. */
			__( '%s follow-up', 'wp-autoplugin' ),
			getTabLabel( job.stage )
		);
	}
	if ( job.task === 'plan_structure' ) {
		return __( 'Plan structure update', 'wp-autoplugin' );
	}
	if ( job.task === 'review_fix' ) {
		return __( 'Review fix', 'wp-autoplugin' );
	}
	if ( job.task === 'code' && job.mode === 'regenerate' ) {
		return __( 'Code regeneration', 'wp-autoplugin' );
	}
	if ( job.task === 'code' ) {
		return __( 'Code generation', 'wp-autoplugin' );
	}
	return getTabLabel( job.stage || job.task );
}

function TokenUsageControl( {
	workspaceId,
	jobs,
}: {
	workspaceId: number;
	jobs: Job[];
} ) {
	const [ summary, setSummary ] = useState< TokenUsageSummary | null >(
		null
	);
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const requestSequence = useRef( 0 );
	const refreshKey = jobs
		.map(
			( job ) =>
				`${ job.id }:${ job.status }:${ job.progress }:${
					job.latest_event?.sequence ?? ''
				}:${ job.code_progress?.input_tokens ?? '' }:${
					job.code_progress?.output_tokens ?? ''
				}`
		)
		.join( '|' );

	const load = useCallback( async () => {
		const requestNumber = ++requestSequence.current;
		setLoading( true );
		setError( '' );
		try {
			const response = await apiFetch< TokenUsageSummary >( {
				path: `${ rest }/workspaces/${ workspaceId }/usage`,
			} );
			if ( requestNumber === requestSequence.current ) {
				setSummary( response );
			}
		} catch ( reason: any ) {
			if ( requestNumber === requestSequence.current ) {
				setError(
					reason?.message ||
						__(
							'Token usage could not be loaded.',
							'wp-autoplugin'
						)
				);
			}
		} finally {
			if ( requestNumber === requestSequence.current ) {
				setLoading( false );
			}
		}
	}, [ workspaceId ] );

	useEffect( () => {
		setSummary( null );
		setError( '' );
	}, [ workspaceId ] );

	useEffect( () => {
		void load();
	}, [ load, refreshKey ] );

	const inputTokens = summary?.total.input_tokens ?? 0;
	const outputTokens = summary?.total.output_tokens ?? 0;
	const buttonLabel =
		loading && ! summary
			? __( 'Loading token usage', 'wp-autoplugin' )
			: sprintf(
					/* translators: 1: Total input tokens, 2: Total output tokens. */
					__(
						'%1$s input tokens, %2$s output tokens. Click for token usage breakdown.',
						'wp-autoplugin'
					),
					formatTokenCount( inputTokens ),
					formatTokenCount( outputTokens )
			  );

	return (
		<>
			<Button
				className="token-usage-control"
				variant="secondary"
				aria-label={ buttonLabel }
				title={ buttonLabel }
				onClick={ () => {
					setOpen( true );
					void load();
				} }
			>
				<span aria-hidden="true">⬆️</span>
				<span>
					{ loading && ! summary
						? '…'
						: formatTokenCount( inputTokens ) }
				</span>
				<span className="token-usage-control__separator">|</span>
				<span aria-hidden="true">⬇️</span>
				<span>
					{ loading && ! summary
						? '…'
						: formatTokenCount( outputTokens ) }
				</span>
			</Button>
			{ open && (
				<Modal
					className="token-usage-modal"
					title={ __( 'Project token usage', 'wp-autoplugin' ) }
					size="large"
					onRequestClose={ () => setOpen( false ) }
				>
					<p className="token-usage-modal__intro">
						{ __(
							'Totals include every recorded model request in this project, including retries and provider calls made by failed or cancelled jobs.',
							'wp-autoplugin'
						) }
					</p>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							<p>{ error }</p>
							<Button
								variant="secondary"
								isBusy={ loading }
								onClick={ () => void load() }
							>
								{ __( 'Retry', 'wp-autoplugin' ) }
							</Button>
						</Notice>
					) }
					{ loading && ! summary && (
						<div
							className="token-usage-modal__loading"
							role="status"
						>
							<Spinner />
							{ __( 'Loading token usage…', 'wp-autoplugin' ) }
						</div>
					) }
					{ summary && (
						<>
							<div className="token-usage-totals">
								<div>
									<span aria-hidden="true">⬆️</span>
									<p>
										{ __(
											'Input tokens',
											'wp-autoplugin'
										) }
									</p>
									<strong>
										{ formatTokenCount(
											summary.total.input_tokens
										) }
									</strong>
								</div>
								<div>
									<span aria-hidden="true">⬇️</span>
									<p>
										{ __(
											'Output tokens',
											'wp-autoplugin'
										) }
									</p>
									<strong>
										{ formatTokenCount(
											summary.total.output_tokens
										) }
									</strong>
								</div>
							</div>
							{ summary.models.length === 0 ? (
								<p className="token-usage-modal__empty">
									{ __(
										'No token usage data available yet.',
										'wp-autoplugin'
									) }
								</p>
							) : (
								<>
									<section className="token-usage-section">
										<h3>
											{ __(
												'Usage by model',
												'wp-autoplugin'
											) }
										</h3>
										<div className="token-usage-table-wrap">
											<table>
												<thead>
													<tr>
														<th>
															{ __(
																'Model',
																'wp-autoplugin'
															) }
														</th>
														<th>
															{ __(
																'Jobs',
																'wp-autoplugin'
															) }
														</th>
														<th>
															{ __(
																'Input',
																'wp-autoplugin'
															) }
														</th>
														<th>
															{ __(
																'Output',
																'wp-autoplugin'
															) }
														</th>
													</tr>
												</thead>
												<tbody>
													{ summary.models.map(
														( model ) => (
															<tr
																key={ `${ model.provider }:${ model.model }` }
															>
																<td>
																	<strong>
																		{
																			model.model
																		}
																	</strong>
																	<small>
																		{
																			model.provider
																		}
																	</small>
																</td>
																<td>
																	{
																		model.job_count
																	}
																</td>
																<td>
																	{ formatTokenCount(
																		model.input_tokens
																	) }
																</td>
																<td>
																	{ formatTokenCount(
																		model.output_tokens
																	) }
																</td>
															</tr>
														)
													) }
												</tbody>
											</table>
										</div>
									</section>
									<section className="token-usage-section">
										<div className="token-usage-section__heading">
											<h3>
												{ __(
													'Executed AI jobs',
													'wp-autoplugin'
												) }
											</h3>
											<span>
												{ sprintf(
													/* translators: %d: Number of executed AI jobs. */
													_n(
														'%d job',
														'%d jobs',
														summary.executed_jobs
															.length,
														'wp-autoplugin'
													),
													summary.executed_jobs.length
												) }
											</span>
										</div>
										<div className="token-usage-table-wrap">
											<table>
												<thead>
													<tr>
														<th>
															{ __(
																'Job',
																'wp-autoplugin'
															) }
														</th>
														<th>
															{ __(
																'Status',
																'wp-autoplugin'
															) }
														</th>
														<th>
															{ __(
																'Model',
																'wp-autoplugin'
															) }
														</th>
														<th>
															{ __(
																'Input',
																'wp-autoplugin'
															) }
														</th>
														<th>
															{ __(
																'Output',
																'wp-autoplugin'
															) }
														</th>
													</tr>
												</thead>
												<tbody>
													{ summary.executed_jobs.map(
														( job ) => (
															<tr key={ job.id }>
																<td>
																	<strong>
																		{ tokenUsageJobLabel(
																			job
																		) }
																	</strong>
																	<small>
																		{ sprintf(
																			/* translators: 1: Job ID, 2: Date and time. */
																			__(
																				'#%1$d · %2$s',
																				'wp-autoplugin'
																			),
																			job.id,
																			formatWorkspaceDate(
																				job.created_at
																			)
																		) }
																	</small>
																</td>
																<td>
																	<span
																		className={ `token-usage-job-status status--${ job.status }` }
																	>
																		{
																			job.status
																		}
																	</span>
																</td>
																<td>
																	{ job.models.map(
																		(
																			model
																		) => (
																			<span
																				className="token-usage-job-model"
																				key={ `${ job.id }:${ model.provider }:${ model.model }` }
																			>
																				<strong>
																					{
																						model.model
																					}
																				</strong>
																				<small>
																					{
																						model.provider
																					}
																				</small>
																			</span>
																		)
																	) }
																</td>
																<td>
																	{ formatTokenCount(
																		job.input_tokens
																	) }
																</td>
																<td>
																	{ formatTokenCount(
																		job.output_tokens
																	) }
																</td>
															</tr>
														)
													) }
												</tbody>
											</table>
										</div>
									</section>
								</>
							) }
						</>
					) }
				</Modal>
			) }
		</>
	);
}

function WorkspaceTabBar( {
	workspaces,
	activeWorkspaceId,
	distractionFree,
	onSelect,
	onClose,
	onNew,
	onLoadProjects,
	onDistractionFreeToggle,
}: {
	workspaces: Workspace[];
	activeWorkspaceId: number | 'new';
	distractionFree: boolean;
	onSelect: ( id: number ) => void;
	onClose: ( id: number ) => void;
	onNew: () => void;
	onLoadProjects: () => void;
	onDistractionFreeToggle: () => void;
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
							{ __( 'New project', 'wp-autoplugin' ) }
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
					aria-label={ __( 'Start a new project', 'wp-autoplugin' ) }
					title={ __( 'New project', 'wp-autoplugin' ) }
				>
					+
				</button>
			</div>
			<DropdownMenu
				className="workspace-options-menu"
				icon="ellipsis"
				label={ __( 'Workspace options', 'wp-autoplugin' ) }
				toggleProps={ {
					className: 'workspace-options-menu__toggle',
				} }
				controls={ [
					[
						{
							title: __( 'New project', 'wp-autoplugin' ),
							icon: 'plus',
							onClick: onNew,
						},
						{
							title: __( 'All projects', 'wp-autoplugin' ),
							icon: 'open-folder',
							onClick: onLoadProjects,
						},
					],
					[
						{
							title: __(
								'Distraction-free mode',
								'wp-autoplugin'
							),
							icon: distractionFree
								? 'fullscreen-exit-alt'
								: 'fullscreen-alt',
							isActive: distractionFree,
							role: 'menuitemcheckbox',
							onClick: onDistractionFreeToggle,
						},
					],
				] }
			/>
		</div>
	);
}

function AllProjectsModal( {
	onClose,
	onOpen,
	onDelete,
}: {
	onClose: () => void;
	onOpen: ( project: Workspace ) => Promise< void >;
	onDelete: ( project: Workspace ) => Promise< void >;
} ) {
	const [ projects, setProjects ] = useState< Workspace[] >( [] );
	const [ search, setSearch ] = useState( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ loadingMore, setLoadingMore ] = useState( false );
	const [ nextPage, setNextPage ] = useState( 2 );
	const [ total, setTotal ] = useState( 0 );
	const [ hasMore, setHasMore ] = useState( false );
	const [ openingId, setOpeningId ] = useState< number | null >( null );
	const [ deletingId, setDeletingId ] = useState< number | null >( null );
	const [ projectToDelete, setProjectToDelete ] =
		useState< Workspace | null >( null );
	const [ deleteError, setDeleteError ] = useState( '' );
	const [ loadError, setLoadError ] = useState( '' );
	const [ reloadSequence, setReloadSequence ] = useState( 0 );
	const requestSequence = useRef( 0 );
	const loadingMoreRef = useRef( false );
	const loadMoreTriggerRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		const timer = window.setTimeout(
			() => setDebouncedSearch( search.trim() ),
			PROJECT_SEARCH_DELAY
		);

		return () => window.clearTimeout( timer );
	}, [ search ] );

	useEffect( () => {
		let current = true;
		const requestNumber = ++requestSequence.current;
		loadingMoreRef.current = false;
		setProjects( [] );
		setLoading( true );
		setLoadingMore( false );
		setLoadError( '' );

		apiFetch< ProjectsResponse >( {
			path: getProjectsPath( debouncedSearch, 1 ),
		} )
			.then( ( response ) => {
				if ( current && requestNumber === requestSequence.current ) {
					setProjects( response.items );
					setNextPage( 2 );
					setTotal( response.total );
					setHasMore( response.has_more );
				}
			} )
			.catch( ( reason ) => {
				if ( current && requestNumber === requestSequence.current ) {
					setLoadError( reason.message );
					setHasMore( false );
				}
			} )
			.finally( () => {
				if ( current && requestNumber === requestSequence.current ) {
					setLoading( false );
				}
			} );

		return () => {
			current = false;
		};
	}, [ debouncedSearch, reloadSequence ] );

	const loadMore = useCallback( async () => {
		if ( loading || loadingMoreRef.current || ! hasMore ) {
			return;
		}

		const requestNumber = requestSequence.current;
		const requestedPage = nextPage;
		loadingMoreRef.current = true;
		setLoadingMore( true );
		setLoadError( '' );
		try {
			const response = await apiFetch< ProjectsResponse >( {
				path: getProjectsPath( debouncedSearch, requestedPage ),
			} );
			if ( requestNumber !== requestSequence.current ) {
				return;
			}
			setProjects( ( current ) => {
				const loadedIds = new Set( current.map( ( item ) => item.id ) );
				return [
					...current,
					...response.items.filter(
						( item ) => ! loadedIds.has( item.id )
					),
				];
			} );
			setNextPage( requestedPage + 1 );
			setTotal( response.total );
			setHasMore( response.has_more );
		} catch ( reason: any ) {
			if ( requestNumber === requestSequence.current ) {
				setLoadError( reason.message );
				setHasMore( false );
			}
		} finally {
			if ( requestNumber === requestSequence.current ) {
				loadingMoreRef.current = false;
				setLoadingMore( false );
			}
		}
	}, [ debouncedSearch, hasMore, loading, nextPage ] );

	useEffect( () => {
		const trigger = loadMoreTriggerRef.current;
		if (
			! trigger ||
			! hasMore ||
			loading ||
			! ( 'IntersectionObserver' in window )
		) {
			return;
		}

		const observer = new window.IntersectionObserver(
			( entries ) => {
				if ( entries[ 0 ]?.isIntersecting ) {
					void loadMore();
				}
			},
			{ rootMargin: '240px 0px' }
		);
		observer.observe( trigger );

		return () => observer.disconnect();
	}, [ hasMore, loadMore, loading ] );

	async function open( project: Workspace ) {
		setOpeningId( project.id );
		setLoadError( '' );
		try {
			await onOpen( project );
			onClose();
		} catch ( reason: any ) {
			setLoadError( reason.message );
			setOpeningId( null );
		}
	}

	function requestDelete( project: Workspace ) {
		setDeleteError( '' );
		setProjectToDelete( project );
	}

	function closeDeleteConfirmation() {
		if ( null === deletingId ) {
			setDeleteError( '' );
			setProjectToDelete( null );
		}
	}

	async function deleteSelectedProject() {
		if ( ! projectToDelete ) {
			return;
		}

		setDeletingId( projectToDelete.id );
		setDeleteError( '' );
		try {
			await onDelete( projectToDelete );
			setProjects( ( current ) =>
				current.filter(
					( project ) =>
						project.project_id !== projectToDelete.project_id
				)
			);
			setTotal( ( current ) => Math.max( 0, current - 1 ) );
			setProjectToDelete( null );
			setReloadSequence( ( current ) => current + 1 );
		} catch ( reason: any ) {
			setDeleteError( reason.message );
		} finally {
			setDeletingId( null );
		}
	}

	const busy = null !== openingId || null !== deletingId;

	return (
		<Modal
			className="all-projects-modal"
			title={ __( 'All projects', 'wp-autoplugin' ) }
			size="large"
			isDismissible={ ! busy && null === projectToDelete }
			onRequestClose={ onClose }
		>
			<p className="all-projects__intro">
				{ __(
					'Browse every project, switch to an open workspace, reopen a closed one, or permanently delete its history.',
					'wp-autoplugin'
				) }
			</p>
			<div className="all-projects__filter">
				<TextControl
					label={ __( 'Search projects', 'wp-autoplugin' ) }
					value={ search }
					onChange={ setSearch }
					placeholder={ __(
						'Search by project, request, or target',
						'wp-autoplugin'
					) }
				/>
				{ ! loading && (
					<span
						className="all-projects__result-count"
						aria-live="polite"
					>
						{ sprintf(
							/* translators: %d: Number of projects found. */
							_n(
								'%d project',
								'%d projects',
								total,
								'wp-autoplugin'
							),
							total
						) }
					</span>
				) }
			</div>
			{ loadError && (
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
			) }
			{ loading && (
				<div className="all-projects__loading" role="status">
					<Spinner />
					{ __( 'Loading projects…', 'wp-autoplugin' ) }
				</div>
			) }
			{ ! loading && ! loadError && ! projects.length && (
				<div className="all-projects__empty">
					<strong>
						{ debouncedSearch
							? __( 'No matching projects', 'wp-autoplugin' )
							: __( 'No projects yet', 'wp-autoplugin' ) }
					</strong>
					<p>
						{ debouncedSearch
							? __(
									'Try a different project name, request, or target.',
									'wp-autoplugin'
							  )
							: __(
									'Projects will appear here after you create a workspace.',
									'wp-autoplugin'
							  ) }
					</p>
				</div>
			) }
			{ ! loading && projects.length > 0 && (
				<ul className="all-projects__list">
					{ projects.map( ( project ) => {
						const targetName =
							project.target_metadata?.name ||
							project.project_name;
						const isClosed = Boolean( project.is_closed );
						const actionAriaLabel = isClosed
							? sprintf(
									/* translators: %s: Project name. */
									__( 'Reopen %s', 'wp-autoplugin' ),
									project.project_name
							  )
							: sprintf(
									/* translators: %s: Project name. */
									__( 'Open %s', 'wp-autoplugin' ),
									project.project_name
							  );
						return (
							<li
								className="all-projects__item"
								key={ project.id }
							>
								<div className="all-projects__details">
									<div className="all-projects__heading">
										<div>
											<strong>
												{ project.project_name }
											</strong>
											<small>
												{ getTargetKindLabel(
													project.target_kind
												) }
											</small>
										</div>
										<span
											className={ `all-projects__state is-${
												isClosed ? 'closed' : 'open'
											}` }
										>
											{ isClosed
												? __(
														'Closed',
														'wp-autoplugin'
												  )
												: __(
														'Open',
														'wp-autoplugin'
												  ) }
										</span>
									</div>
									<p className="all-projects__request">
										{ project.request }
									</p>
									<dl>
										<div>
											<dt>
												{ __(
													'Operation',
													'wp-autoplugin'
												) }
											</dt>
											<dd>
												{ getOperationLabel(
													project.operation
												) }
											</dd>
										</div>
										<div>
											<dt>
												{ __(
													'Target',
													'wp-autoplugin'
												) }
											</dt>
											<dd>{ targetName }</dd>
										</div>
										<div>
											<dt>
												{ isClosed
													? __(
															'Closed',
															'wp-autoplugin'
													  )
													: __(
															'Updated',
															'wp-autoplugin'
													  ) }
											</dt>
											<dd>
												{ formatWorkspaceDate(
													project.closed_at ||
														project.updated_at
												) }
											</dd>
										</div>
									</dl>
								</div>
								<ProjectActivity project={ project } />
								<div className="all-projects__actions">
									<Button
										variant="secondary"
										isBusy={ openingId === project.id }
										disabled={ busy }
										onClick={ () => open( project ) }
										aria-label={ actionAriaLabel }
									>
										{ isClosed
											? __( 'Reopen', 'wp-autoplugin' )
											: __( 'Open', 'wp-autoplugin' ) }
									</Button>
									<Button
										variant="tertiary"
										isDestructive
										disabled={ busy }
										onClick={ () =>
											requestDelete( project )
										}
										aria-label={ sprintf(
											/* translators: %s: Project name. */
											__( 'Delete %s', 'wp-autoplugin' ),
											project.project_name
										) }
									>
										{ __( 'Delete', 'wp-autoplugin' ) }
									</Button>
								</div>
							</li>
						);
					} ) }
				</ul>
			) }
			{ ! loading && hasMore && (
				<div
					className="all-projects__load-more"
					ref={ loadMoreTriggerRef }
				>
					<Button
						variant="tertiary"
						isBusy={ loadingMore }
						disabled={ loadingMore }
						onClick={ loadMore }
					>
						{ loadingMore
							? __( 'Loading more projects…', 'wp-autoplugin' )
							: __( 'Load more projects', 'wp-autoplugin' ) }
					</Button>
				</div>
			) }
			{ projectToDelete && (
				<Modal
					className="delete-project-modal"
					title={ sprintf(
						/* translators: %s: Project name. */
						__( 'Delete “%s”?', 'wp-autoplugin' ),
						projectToDelete.project_name
					) }
					isDismissible={ null === deletingId }
					onRequestClose={ closeDeleteConfirmation }
				>
					{ deleteError && (
						<Notice status="error" isDismissible={ false }>
							{ deleteError }
						</Notice>
					) }
					<p>
						{ __(
							'This permanently deletes all workspaces, jobs, conversations, revisions, reviews, and release history for this project.',
							'wp-autoplugin'
						) }
					</p>
					<p>
						{ __(
							'Installed plugins and themes will not be removed or reverted. This action cannot be undone.',
							'wp-autoplugin'
						) }
					</p>
					<div className="delete-project-modal__actions">
						<Button
							variant="tertiary"
							disabled={ null !== deletingId }
							onClick={ closeDeleteConfirmation }
						>
							{ __( 'Cancel', 'wp-autoplugin' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							isBusy={ deletingId === projectToDelete.id }
							disabled={ null !== deletingId }
							onClick={ deleteSelectedProject }
						>
							{ __( 'Delete project', 'wp-autoplugin' ) }
						</Button>
					</div>
				</Modal>
			) }
		</Modal>
	);
}

function getProjectsPath( search: string, page: number ): string {
	return `${ rest }/projects?search=${ encodeURIComponent(
		search
	) }&page=${ page }&per_page=${ PROJECTS_PAGE_SIZE }`;
}

function ProjectActivity( { project }: { project: Workspace } ) {
	const summary = project.activity_summary ?? {
		total_jobs: 0,
		follow_up_jobs: 0,
		retry_count: 0,
		stages: {},
	};
	const stages: Array< 'plan' | 'code' | 'review' | 'chat' > =
		project.operation === 'explain'
			? [ 'chat' ]
			: [ 'plan', 'code', 'review' ];
	const counts = [
		sprintf(
			/* translators: %d: Number of durable jobs. */
			_n(
				'%d job total',
				'%d jobs total',
				summary.total_jobs,
				'wp-autoplugin'
			),
			summary.total_jobs
		),
		sprintf(
			/* translators: %d: Number of follow-up conversation jobs. */
			_n(
				'%d follow-up',
				'%d follow-ups',
				summary.follow_up_jobs,
				'wp-autoplugin'
			),
			summary.follow_up_jobs
		),
		sprintf(
			/* translators: %d: Number of retry attempts. */
			_n(
				'%d retry',
				'%d retries',
				summary.retry_count,
				'wp-autoplugin'
			),
			summary.retry_count
		),
	];

	return (
		<div className="all-projects__progress">
			<ul aria-label={ __( 'Project progress', 'wp-autoplugin' ) }>
				{ stages.map( ( stage ) => {
					const status = summary.stages[ stage ] ?? 'not_started';
					return (
						<li className={ `status--${ status }` } key={ stage }>
							<span
								className="all-projects__stage-marker"
								aria-hidden="true"
							>
								{ getActivityMarker( status ) }
							</span>
							<strong>{ getActivityStageLabel( stage ) }</strong>
							<small>
								{ getActivityStatusLabel( stage, status ) }
							</small>
						</li>
					);
				} ) }
			</ul>
			<p>{ counts.join( ' · ' ) }</p>
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
	modelSettings,
	onTargetSearch,
	onTargetSelect,
	onTargetKindSelect,
	onOperationSelect,
	onRequestChange,
	onStart,
	onUpdateModel,
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
	modelSettings: ModelSettings | null;
	onTargetSearch: ( value: string ) => void;
	onTargetSelect: ( value: string ) => void;
	onTargetKindSelect: ( value: string ) => void;
	onOperationSelect: ( value: string ) => void;
	onRequestChange: ( value: string ) => void;
	onStart: ( images: File[] ) => Promise< boolean >;
	onUpdateModel: ModelUpdateHandler;
} ) {
	const [ images, setImages ] = useState< File[] >( [] );
	const requiresPlan = !! target && operation !== 'explain';
	const effectivePlanCapability =
		target?.kind === 'new_plugin' ? directPlanCapability : planCapability;
	const imageCapability =
		operation === 'explain' ? explainCapability : effectivePlanCapability;
	const submit = async () => {
		if ( await onStart( images ) ) {
			setImages( [] );
		}
	};
	return (
		<div className="workspace-new-canvas">
			<Card className="workspace-launcher">
				<CardBody>
					<div className="workspace-launcher__heading">
						<p>{ __( 'New project', 'wp-autoplugin' ) }</p>
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
						<PromptComposerField
							label={
								operations.find(
									( item ) => item.value === operation
								)?.requestLabel ??
								__( 'What should the AI do?', 'wp-autoplugin' )
							}
							value={ request }
							files={ images }
							onChange={ onRequestChange }
							onFilesChange={ setImages }
							imageEnabled={ !! imageCapability?.images }
							disabled={ busy }
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
					<div className="workspace-launcher__actions">
						{ ( requiresPlan || operation === 'explain' ) &&
							modelSettings && (
								<StageModelControl
									modelRole={
										operation === 'explain'
											? 'reviewer'
											: 'planner'
									}
									context={
										operation !== 'explain' &&
										target?.kind === 'new_plugin'
											? 'direct'
											: 'native'
									}
									settings={ modelSettings }
									onUpdate={ onUpdateModel }
								/>
							) }
						<Button
							variant="primary"
							disabled={
								busy ||
								( ! request.trim() && ! images.length ) ||
								( images.length > 0 &&
									! imageCapability?.images ) ||
								( operation === 'explain' &&
									! explainCapability?.available ) ||
								( requiresPlan &&
									! effectivePlanCapability?.available )
							}
							isBusy={ busy }
							onClick={ submit }
						>
							{ operation === 'explain'
								? __( 'Explain', 'wp-autoplugin' )
								: __( 'Create plan', 'wp-autoplugin' ) }
						</Button>
					</div>
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
	reviewCapability,
	planCapability,
	directPlanCapability,
	explainCapability,
	modelSettings,
	releaseCapability,
	activeTab,
	onTabSelect,
	onCancel,
	onCreateJob,
	onQueueEndpoint,
	onSavePlan,
	onUpdateModel,
}: {
	workspace: Workspace;
	jobs: Job[];
	jobsLoading: boolean;
	codeCapability: AgentCapability | null;
	reviewCapability: AgentCapability | null;
	planCapability: AgentCapability | null;
	directPlanCapability: AgentCapability | null;
	explainCapability: AgentCapability | null;
	modelSettings: ModelSettings | null;
	releaseCapability: ReleaseCapability | null;
	activeTab: string;
	onTabSelect: ( tab: string ) => void;
	onCancel: ( job: Job ) => void;
	onCreateJob: (
		task:
			| 'plan'
			| 'code'
			| 'review'
			| 'review_fix'
			| 'explain'
			| 'conversation',
		payload?: object,
		images?: File[],
		attachmentIds?: number[]
	) => Promise< Job | null >;
	onQueueEndpoint: ( path: string, data: object ) => Promise< Job | null >;
	onSavePlan: ( job: Job, content: string ) => Promise< boolean >;
	onUpdateModel: ModelUpdateHandler;
} ) {
	const target = workspace.target_metadata;
	const tabs =
		workspace.operation === 'explain'
			? [ 'explain' ]
			: [ 'plan', 'code', 'review' ];
	const isWorkflow = tabs.length === 3;
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
	const reviewConversationJobs = jobs.filter(
		( job ) => job.task === 'conversation' && job.payload.stage === 'review'
	);
	const initialPromptJob = jobs.find( ( job ) =>
		[ 'plan', 'explain' ].includes( job.task )
	);
	const effectivePlanCapability =
		workspace.target_kind === 'new_plugin'
			? directPlanCapability
			: planCapability;
	let activeModelRole: ModelRoleSelection[ 'role' ] | null = null;
	if ( activeTab === 'plan' ) {
		activeModelRole = 'planner';
	} else if ( activeTab === 'code' ) {
		activeModelRole = 'coder';
	} else if ( activeTab === 'review' ) {
		activeModelRole = 'reviewer';
	}
	const activeModelContext =
		activeTab === 'plan' && workspace.target_kind !== 'new_plugin'
			? 'native'
			: 'direct';
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
					{ activeModelRole && modelSettings && (
						<StageModelControl
							modelRole={ activeModelRole }
							context={ activeModelContext }
							settings={ modelSettings }
							onUpdate={ onUpdateModel }
						/>
					) }
					{ activeModelRole && (
						<TokenUsageControl
							key={ workspace.id }
							workspaceId={ workspace.id }
							jobs={ jobs }
						/>
					) }
				</div>
			</header>
			<div className="workspace-editor__request">
				<strong>{ __( 'Request', 'wp-autoplugin' ) }</strong>
				<p>
					{ workspace.request ||
						__( 'Image-only request', 'wp-autoplugin' ) }
				</p>
				<StoredPromptImages
					attachments={ initialPromptJob?.prompt_attachments }
				/>
			</div>
			<div className="workspace-stage-flow">
				<nav
					className={ `workspace-stage-tabs ${
						isWorkflow ? 'is-workflow' : 'is-single'
					}` }
					aria-label={ __( 'Workspace stages', 'wp-autoplugin' ) }
				>
					{ tabs.map( ( tab, index ) => (
						<button
							type="button"
							key={ tab }
							className={ activeTab === tab ? 'is-active' : '' }
							aria-current={
								activeTab === tab ? 'step' : undefined
							}
							onClick={ () => onTabSelect( tab ) }
						>
							{ isWorkflow ? (
								<>
									<span
										className="workspace-stage-tabs__number"
										aria-hidden="true"
									>
										{ index + 1 }
									</span>
									<span className="workspace-stage-tabs__copy">
										<strong>{ getTabLabel( tab ) }</strong>
										<small>
											{ getTabDescription( tab ) }
										</small>
									</span>
								</>
							) : (
								getTabLabel( tab )
							) }
						</button>
					) ) }
				</nav>
			</div>
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
							capability={ effectivePlanCapability }
							onCancel={ onCancel }
							onCreate={ ( attachmentIds = [] ) =>
								onCreateJob( 'plan', {}, [], attachmentIds )
							}
							onSave={ onSavePlan }
							onContinue={ () => onTabSelect( 'code' ) }
							onFollowUp={ (
								message,
								artifactJobId,
								images,
								attachmentIds
							) =>
								onCreateJob(
									'conversation',
									{
										stage: 'plan',
										message,
										artifact_job_id: artifactJobId,
									},
									images,
									attachmentIds
								)
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
							onFollowUp={ (
								message,
								revisionId,
								focusedPath,
								images,
								attachmentIds
							) =>
								onCreateJob(
									'conversation',
									{
										stage: 'code',
										message,
										revision_id: revisionId,
										expected_latest_revision_id: revisionId,
										focused_path: focusedPath || undefined,
									},
									images,
									attachmentIds
								)
							}
							onContinue={ () => onTabSelect( 'review' ) }
						/>
					) }
					{ ! jobsLoading && activeTab === 'review' && (
						<ReviewStage
							workspace={ workspace }
							jobs={ jobs }
							conversationJobs={ reviewConversationJobs }
							capability={ reviewCapability }
							releaseCapability={ releaseCapability }
							onCancel={ onCancel }
							onCreateJob={ onCreateJob }
							onQueueEndpoint={ onQueueEndpoint }
						/>
					) }
					{ ! jobsLoading && activeTab === 'explain' && (
						<ExplainStage
							jobs={ explainConversationJobs }
							initialMessage={ workspace.request }
							capability={ explainCapability }
							onCancel={ onCancel }
							onFollowUp={ ( message, images, attachmentIds ) =>
								onCreateJob(
									'conversation',
									{
										stage: 'explain',
										message,
									},
									images,
									attachmentIds
								)
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

function jobModelSnapshot( job: Job ): ModelSnapshot | null {
	if (
		job.task === 'review' ||
		( job.task === 'conversation' && job.payload.stage === 'review' )
	) {
		if ( job.payload.reviewer ) {
			return job.payload.reviewer;
		}
	} else if ( job.payload.prompt_model ) {
		return job.payload.prompt_model;
	}
	if ( job.result?.model ) {
		return {
			provider: job.result.provider || '',
			model: job.result.model,
			effort: job.result.effort || '',
		};
	}
	return null;
}

function JobModelMeta( { job }: { job: Job } ) {
	const identity = modelIdentity( jobModelSnapshot( job ) );
	if ( ! identity ) {
		return null;
	}
	const terminal = [ 'completed', 'failed', 'cancelled' ].includes(
		job.status
	);
	return (
		<small className="job-model-meta">
			{ terminal
				? sprintf(
						/* translators: %s: Stored provider, model, and effort. */
						__( 'Used: %s', 'wp-autoplugin' ),
						identity
				  )
				: sprintf(
						/* translators: %s: Stored provider, model, and effort. */
						__( 'Running: %s', 'wp-autoplugin' ),
						identity
				  ) }
		</small>
	);
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
			<JobModelMeta job={ job } />
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
	capability,
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
	capability: AgentCapability | null;
	onCancel: ( job: Job ) => void;
	onCreate: ( attachmentIds?: number[] ) => void;
	onSave: ( job: Job, content: string ) => Promise< boolean >;
	onContinue: () => void;
	onFollowUp: (
		message: string,
		artifactJobId: number,
		images?: File[],
		attachmentIds?: number[]
	) => Promise< Job | null >;
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
						<Button
							variant="secondary"
							disabled={
								! capability?.available ||
								( ( latestRun.prompt_attachments?.length ||
									0 ) > 0 &&
									! capability?.images )
							}
							onClick={ () =>
								onCreate(
									latestRun.prompt_attachments?.map(
										( item ) => item.id
									)
								)
							}
						>
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
			capability={ capability }
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
	capability,
	regenerationJob,
	onCancel,
	onSave,
	onRetry,
	onContinue,
	onFollowUp,
}: {
	job: Job;
	conversationJobs: Job[];
	capability: AgentCapability | null;
	regenerationJob: Job | null;
	onCancel: ( job: Job ) => void;
	onSave: ( job: Job, content: string ) => Promise< boolean >;
	onRetry: ( attachmentIds?: number[] ) => void;
	onContinue: () => void;
	onFollowUp: (
		message: string,
		artifactJobId: number,
		images?: File[],
		attachmentIds?: number[]
	) => Promise< Job | null >;
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
					<JobModelMeta job={ job } />
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
							<Button
								variant="secondary"
								disabled={
									! capability?.available ||
									( ( job.prompt_attachments?.length || 0 ) >
										0 &&
										! capability?.images )
								}
								onClick={ () =>
									onRetry(
										job.prompt_attachments?.map(
											( item ) => item.id
										)
									)
								}
							>
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
				capability={ capability }
				onCancel={ onCancel }
				onFollowUp={ (
					message,
					_artifactJobId,
					images,
					attachmentIds
				) => onFollowUp( message, job.id, images, attachmentIds ) }
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
	onContinue,
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
		revisionId: number,
		focusedPath?: string,
		images?: File[],
		attachmentIds?: number[]
	) => Promise< Job | null >;
	onContinue: () => void;
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
	const [ treeCollapsed, setTreeCollapsed ] = useState( false );
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
	const codeWorkJobs = [
		...codeJobs,
		...conversationJobs,
		...jobs.filter( ( job ) => job.task === 'review_fix' ),
	].sort( ( left, right ) => left.id - right.id );
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
						( file ) =>
							!! file.change_type &&
							file.path === selectedFilePath.current
					) ??
					files.find( ( file ) => !! file.change_type ) ??
					files[ 0 ];
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
			type: file.type,
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
			<div
				className={ `code-stage ${
					treeCollapsed ? 'is-tree-collapsed' : ''
				}` }
			>
				<aside className="code-stage__tree">
					<div className="code-stage__tree-header">
						<strong>{ treeLabel }</strong>
						<button
							type="button"
							className="code-stage__tree-toggle"
							aria-expanded={ ! treeCollapsed }
							aria-label={
								treeCollapsed
									? __(
											'Expand project structure',
											'wp-autoplugin'
									  )
									: __(
											'Collapse project structure',
											'wp-autoplugin'
									  )
							}
							onClick={ () =>
								setTreeCollapsed( ! treeCollapsed )
							}
						>
							<span aria-hidden="true">
								{ treeCollapsed ? '+' : '−' }
							</span>
						</button>
					</div>
					{ ! treeCollapsed && (
						<FileTree
							files={ treeFiles }
							directories={ manifest?.target_directories ?? [] }
							selectedId={ selectedFileId }
							dirtyPaths={ dirtyPaths }
							problemPaths={
								new Set(
									problems.map( ( issue ) => issue.path )
								)
							}
							progress={ progressMap }
							onSelect={ manifest ? selectFile : () => undefined }
						/>
					) }
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
								<div className="code-file-header__view-switcher">
									<span>
										{ __( 'View', 'wp-autoplugin' ) }
									</span>
									<div
										className="code-file-header__modes"
										role="group"
										aria-label={ __(
											'File view',
											'wp-autoplugin'
										) }
									>
										<button
											type="button"
											className={
												mode === 'code'
													? 'is-active'
													: ''
											}
											aria-pressed={ mode === 'code' }
											onClick={ () => setMode( 'code' ) }
										>
											{ __( 'Code', 'wp-autoplugin' ) }
										</button>
										<button
											type="button"
											className={
												mode === 'changes'
													? 'is-active'
													: ''
											}
											aria-pressed={ mode === 'changes' }
											disabled={
												dirtyPaths.size > 0 ||
												! selectedManifestFile?.change_type
											}
											onClick={ () =>
												setMode( 'changes' )
											}
										>
											{ __( 'Changes', 'wp-autoplugin' ) }
										</button>
									</div>
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
						{ __( 'Next: revision-bound Review', 'wp-autoplugin' ) }
					</span>
					<Button variant="primary" onClick={ onContinue }>
						{ __( 'Continue to Review', 'wp-autoplugin' ) }
					</Button>
				</div>
			) }
			{ manifest && latestRevisionId && (
				<CodeConversation
					jobs={ conversationJobs }
					revisions={ revisions }
					latestRevisionId={ latestRevisionId }
					selectedRevisionId={ selectedRevisionId }
					editing={ editing }
					dirty={ dirtyPaths.size > 0 }
					capability={ capability }
					activeCodeWork={ activeCodeWork }
					focusedPath={ selectedManifestFile?.path || '' }
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
	focusedPath,
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
	focusedPath: string;
	onCancel: ( job: Job ) => void;
	onFollowUp: (
		message: string,
		revisionId: number,
		focusedPath?: string,
		images?: File[],
		attachmentIds?: number[]
	) => Promise< Job | null >;
} ) {
	const [ message, setMessage ] = useState( '' );
	const [ images, setImages ] = useState< File[] >( [] );
	const [ submitting, setSubmitting ] = useState( false );
	const historical = selectedRevisionId !== latestRevisionId;
	const imagesUnsupported = images.length > 0 && ! capability?.images;
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

	const send = async (
		value = message,
		contextPath = focusedPath,
		promptImages = images,
		attachmentIds: number[] = []
	) => {
		if (
			disabled ||
			( ( promptImages.length > 0 || attachmentIds.length > 0 ) &&
				! capability?.images ) ||
			( ! value.trim() &&
				! promptImages.length &&
				! attachmentIds.length )
		) {
			return;
		}
		setSubmitting( true );
		const created = await onFollowUp(
			value.trim(),
			latestRevisionId,
			contextPath || undefined,
			promptImages,
			attachmentIds
		);
		setSubmitting( false );
		if ( created && value === message && promptImages === images ) {
			setMessage( '' );
			setImages( [] );
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
							'Questions use the configured coder. Change requests may use multiple billable calls and create a new staged revision without writing to an installed target.',
							'wp-autoplugin'
						) }
					</p>
				</div>
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
										{ job.payload.focused_path && (
											<small>
												{ job.payload.focused_path }
											</small>
										) }
									</div>
									<p>
										{ job.payload.message ||
											__(
												'Image-only message',
												'wp-autoplugin'
											) }
									</p>
									<StoredPromptImages
										attachments={ job.prompt_attachments }
									/>
								</div>
								<div className="code-conversation__answer">
									<strong>
										{ __(
											'Code assistant',
											'wp-autoplugin'
										) }
									</strong>
									<JobModelMeta job={ job } />
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
													disabled={
														disabled ||
														( job.prompt_attachments
															.length > 0 &&
															! capability?.images )
													}
													onClick={ () =>
														send(
															job.payload
																.message || '',
															job.payload
																.focused_path ||
																'',
															[],
															job.prompt_attachments.map(
																( item ) =>
																	item.id
															)
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
												disabled={
													disabled ||
													( job.prompt_attachments
														.length > 0 &&
														! capability?.images )
												}
												onClick={ () =>
													send(
														job.payload.message ||
															'',
														job.payload
															.focused_path || '',
														[],
														job.prompt_attachments.map(
															( item ) => item.id
														)
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
				{ focusedPath && (
					<p className="code-conversation__focus">
						{ sprintf(
							/* translators: %s: selected source file path. */
							__( 'Current file context: %s', 'wp-autoplugin' ),
							focusedPath
						) }
					</p>
				) }
				<PromptComposerField
					label={ __( 'Message', 'wp-autoplugin' ) }
					value={ message }
					files={ images }
					onFilesChange={ setImages }
					imageEnabled={ !! capability?.images }
					action={
						<Button
							variant="primary"
							isBusy={ submitting }
							disabled={
								disabled ||
								imagesUnsupported ||
								( ! message.trim() && ! images.length )
							}
							onClick={ () => send() }
						>
							{ __( 'Send', 'wp-autoplugin' ) }
						</Button>
					}
					disabled={ disabled }
					help={
						( imagesUnsupported
							? __(
									'The selected Coder does not accept image prompts. Remove the images or choose another model.',
									'wp-autoplugin'
							  )
							: disabledCopy ) ||
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
								'This Plan needs a valid main plugin file and 1–20 added PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, or plain-text files. Regenerate the Plan structure before generating Code.',
								'wp-autoplugin'
						  )
						: __(
								'This Plan needs 1–20 valid Add, Update, or Delete actions for PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, or plain-text files. Regenerate the Plan structure before generating Code.',
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
	const checkingRequest = progress?.phase === 'compliance';
	let heading = __( 'Generating staged revision', 'wp-autoplugin' );
	if ( analyzing ) {
		heading = __( 'Analyzing Code follow-up', 'wp-autoplugin' );
	} else if ( checkingRequest ) {
		heading = __( 'Checking the latest request', 'wp-autoplugin' );
	} else if ( progress?.mode === 'follow_up' ) {
		heading = __( 'Generating Code changes', 'wp-autoplugin' );
	}
	let progressLabel = progress
		? sprintf(
				/* translators: 1: completed file count, 2: total file count. */
				__( '%1$d of %2$d files', 'wp-autoplugin' ),
				progress.completed,
				progress.total
		  )
		: '';
	if ( analyzing ) {
		progressLabel = __( 'Analysis', 'wp-autoplugin' );
	} else if ( checkingRequest ) {
		progressLabel = __( 'Request compliance', 'wp-autoplugin' );
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
					<span>{ progressLabel }</span>
					<span>{ modelIdentity( progress ) }</span>
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
	markerEndLine = focusLine,
	onChange,
}: {
	value: string;
	type: string;
	readOnly: boolean;
	focusLine: number;
	markerEndLine?: number;
	onChange: ( value: string ) => void;
} ) {
	const textarea = useRef< HTMLTextAreaElement >( null );
	const editor = useRef< any >( null );
	const onChangeRef = useRef( onChange );
	const initialValue = useRef( value );
	const marker = useRef< any >( null );
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
			marker.current?.clear?.();
			marker.current = editor.current.markText(
				{ line: focusLine - 1, ch: 0 },
				{
					line: Math.max( focusLine, markerEndLine ) - 1,
					ch:
						editor.current.getLine(
							Math.max( focusLine, markerEndLine ) - 1
						)?.length ?? 0,
				},
				{ className: 'review-source-marker' }
			);
		} else if ( focusLine > 0 && textarea.current ) {
			const lines = value.split( /\r\n|\r|\n/ );
			const offset = lines
				.slice( 0, Math.max( 0, focusLine - 1 ) )
				.reduce( ( total, line ) => total + line.length + 1, 0 );
			textarea.current.setSelectionRange( offset, offset );
			textarea.current.scrollTop = Math.max( 0, ( focusLine - 3 ) * 18 );
		}
		return () => marker.current?.clear?.();
	}, [ focusLine, markerEndLine, value ] );
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
						GENERATED_FILE_TYPES.includes( file.type ) &&
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
	if ( 'review_fix' === origin ) {
		return __( 'Review fix', 'wp-autoplugin' );
	}
	return __( 'AI generated', 'wp-autoplugin' );
}

function ReviewStage( {
	workspace,
	jobs,
	conversationJobs,
	capability,
	releaseCapability,
	onCancel,
	onCreateJob,
	onQueueEndpoint,
}: {
	workspace: Workspace;
	jobs: Job[];
	conversationJobs: Job[];
	capability: AgentCapability | null;
	releaseCapability: ReleaseCapability | null;
	onCancel: ( job: Job ) => void;
	onCreateJob: (
		task: 'review' | 'review_fix' | 'conversation',
		payload?: object,
		images?: File[],
		attachmentIds?: number[]
	) => Promise< Job | null >;
	onQueueEndpoint: ( path: string, data: object ) => Promise< Job | null >;
} ) {
	const [ history, setHistory ] = useState< ReviewHistory | null >( null );
	const [ report, setReport ] = useState< ReviewReport | null >( null );
	const [ revision, setRevision ] = useState< RevisionManifest | null >(
		null
	);
	const [ revisions, setRevisions ] = useState< RevisionSummary[] >( [] );
	const [ selectedFindingId, setSelectedFindingId ] = useState<
		number | null
	>( null );
	const [ message, setMessage ] = useState( '' );
	const [ images, setImages ] = useState< File[] >( [] );
	const [ submitting, setSubmitting ] = useState( false );
	const imagesUnsupported = images.length > 0 && ! capability?.images;
	const [ historyFinding, setHistoryFinding ] =
		useState< ReviewFinding | null >( null );
	const [ showReportHistory, setShowReportHistory ] = useState( false );
	const [ showConversation, setShowConversation ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ actionError, setActionError ] = useState( '' );
	const [ pendingDownloadJobIds, setPendingDownloadJobIds ] = useState<
		Set< number >
	>( new Set() );
	const handledDownloadJobIds = useRef< Set< number > >( new Set() );
	const [ forkSlug, setForkSlug ] = useState( () =>
		slugify(
			`${
				workspace.target_ref.split( '/' )[ 0 ] || 'plugin'
			}-wp-autoplugin-fork`
		)
	);
	const [ themeCopySlug, setThemeCopySlug ] = useState( () =>
		slugify(
			`${
				workspace.target_metadata.stylesheet ||
				workspace.target_ref ||
				'theme'
			}-wp-autoplugin-copy`
		)
	);
	const [ viewer, setViewer ] = useState< {
		file: RevisionFile;
		finding: ReviewFinding;
	} | null >( null );
	const [ checkedTests, setCheckedTests ] = useState< Set< number > >(
		new Set()
	);
	const refreshKey = jobs
		.map(
			( job ) =>
				`${ job.id }:${ job.status }:${ job.result?.report_id ?? '' }:${
					job.result?.revision_id ?? ''
				}`
		)
		.join( '|' );

	const load = useCallback( async () => {
		setLoading( true );
		try {
			const [ revisionResponse, reviewResponse ] = await Promise.all( [
				apiFetch< {
					items: RevisionSummary[];
					latest_revision_id: number | null;
				} >( {
					path: `${ rest }/workspaces/${ workspace.id }/revisions`,
				} ),
				apiFetch< ReviewHistory >( {
					path: `${ rest }/workspaces/${ workspace.id }/review-reports`,
				} ),
			] );
			setRevisions( revisionResponse.items );
			setHistory( reviewResponse );
			const [ latestRevision, currentReport ] = await Promise.all( [
				revisionResponse.latest_revision_id
					? apiFetch< RevisionManifest >( {
							path: `${ rest }/revisions/${ revisionResponse.latest_revision_id }`,
					  } )
					: Promise.resolve( null ),
				reviewResponse.current.report_id
					? apiFetch< ReviewReport >( {
							path: `${ rest }/review-reports/${ reviewResponse.current.report_id }`,
					  } )
					: Promise.resolve( null ),
			] );
			setRevision( latestRevision );
			setReport( currentReport );
		} catch ( reason: any ) {
			setActionError( reason.message );
		} finally {
			setLoading( false );
		}
	}, [ workspace.id ] );

	useEffect( () => {
		load();
	}, [ load, refreshKey ] );

	useEffect( () => {
		setCheckedTests( new Set() );
	}, [ report?.id ] );

	const downloadPackage = useCallback( async ( packageId: number ) => {
		try {
			const response = ( await apiFetch( {
				path: `${ rest }/release-packages/${ packageId }/download`,
				parse: false,
			} as any ) ) as Response;
			const blob = await response.blob();
			const url = window.URL.createObjectURL( blob );
			const disposition =
				response.headers.get( 'Content-Disposition' ) || '';
			const filenameMatch = disposition.match( /filename="?([^";]+)"?/i );
			const anchor = document.createElement( 'a' );
			anchor.href = url;
			anchor.download =
				filenameMatch?.[ 1 ] || 'wp-autoplugin-release.zip';
			document.body.appendChild( anchor );
			anchor.click();
			anchor.remove();
			window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
		} catch ( reason: any ) {
			let downloadErrorMessage =
				reason?.message ||
				__( 'The ZIP could not be downloaded.', 'wp-autoplugin' );
			if ( reason instanceof Response ) {
				try {
					const error = await reason.json();
					downloadErrorMessage =
						error?.message || downloadErrorMessage;
				} catch {
					// Keep the generic download error for a non-JSON response.
				}
			}
			setActionError( downloadErrorMessage );
		}
	}, [] );

	useEffect( () => {
		const handledIds: number[] = [];
		pendingDownloadJobIds.forEach( ( jobId ) => {
			const packageJob = jobs.find( ( job ) => job.id === jobId );
			if (
				! packageJob ||
				handledDownloadJobIds.current.has( jobId ) ||
				! [ 'completed', 'failed', 'cancelled' ].includes(
					packageJob.status
				)
			) {
				return;
			}
			handledDownloadJobIds.current.add( jobId );
			handledIds.push( jobId );
			if (
				packageJob.status === 'completed' &&
				packageJob.result?.package_id
			) {
				void downloadPackage( packageJob.result.package_id );
			} else if ( packageJob.status === 'completed' ) {
				setActionError(
					__(
						'The ZIP was built, but its download could not be started.',
						'wp-autoplugin'
					)
				);
			}
		} );
		if ( handledIds.length ) {
			setPendingDownloadJobIds( ( current ) => {
				const next = new Set( current );
				handledIds.forEach( ( jobId ) => next.delete( jobId ) );
				return next;
			} );
		}
	}, [ downloadPackage, jobs, pendingDownloadJobIds ] );

	const activeArtifactJob = [ ...jobs ]
		.reverse()
		.find(
			( job ) =>
				[ 'queued', 'running', 'retrying' ].includes( job.status ) &&
				( [
					'code',
					'review',
					'review_fix',
					'package',
					'promotion',
				].includes( job.task ) ||
					( job.task === 'conversation' &&
						[ 'code', 'review' ].includes(
							job.payload.stage || ''
						) ) )
		);
	const reportJob = report
		? jobs.find( ( job ) => job.id === report.job_id )
		: null;
	const latestReviewJob = [ ...jobs ]
		.reverse()
		.find(
			( job ) =>
				job.task === 'review' &&
				job.payload.revision_id === history?.latest_revision_id
		);
	const latestReviewFixJob = [ ...jobs ]
		.reverse()
		.find(
			( job ) =>
				job.task === 'review_fix' &&
				job.payload.review_report_id === report?.id
		);
	const openFindings = ( report?.findings ?? [] ).filter(
		( finding ) => finding.status === 'open'
	);
	const actionableFindings = ( report?.findings ?? [] ).filter( ( finding ) =>
		[ 'open', 'addressed' ].includes( finding.status )
	);
	const historyFindings = ( report?.findings ?? [] ).filter( ( finding ) =>
		[ 'resolved', 'retracted', 'dismissed' ].includes( finding.status )
	);
	const priorityOrder = { P0: 0, P1: 1, P2: 2, P3: 3 };
	const visibleFindings = [ ...actionableFindings ].sort(
		( first, second ) =>
			priorityOrder[ first.priority ] - priorityOrder[ second.priority ]
	);
	const selectedFinding =
		visibleFindings.find(
			( finding ) => finding.id === selectedFindingId
		) ??
		visibleFindings[ 0 ] ??
		null;
	const latestConversationJob = [ ...conversationJobs ].reverse()[ 0 ];
	const currentReport =
		!! report && report.revision_id === history?.latest_revision_id;
	const reviewedRevisionNumber = report
		? revisions.find( ( item ) => item.id === report.revision_id )
				?.revision_number ?? report.revision_id
		: 0;
	const reviewRevisionLabel = ( id: number ) => {
		const revisionItem = revisions.find( ( item ) => item.id === id );
		return revisionItem
			? sprintf(
					/* translators: %d: immutable revision number. */
					__( 'Revision %d', 'wp-autoplugin' ),
					revisionItem.revision_number
			  )
			: sprintf(
					/* translators: %d: revision record ID. */
					__( 'Revision ID %d', 'wp-autoplugin' ),
					id
			  );
	};
	const releaseSafe = [ 'all_clear', 'cleared_with_dismissals' ].includes(
		history?.current.status || ''
	);
	const themeChanges =
		revision?.project_manifest?.scope === 'changes' &&
		revision.project_manifest.artifact_kind === 'theme';
	const pluginProject =
		revision?.project_manifest?.scope === 'project' &&
		revision.project_manifest.artifact_kind === 'plugin';
	const pluginChanges =
		revision?.project_manifest?.scope === 'changes' &&
		revision.project_manifest.artifact_kind === 'plugin';
	const releaseJobs = jobs.filter( ( job ) =>
		[ 'package', 'promotion' ].includes( job.task )
	);

	async function startReview() {
		if ( ! history?.latest_revision_id ) {
			return;
		}
		let mode = 'initial';
		if ( report ) {
			mode =
				report.revision_id === history.latest_revision_id
					? 'follow_up'
					: 'verification';
		}
		await onCreateJob( 'review', {
			revision_id: history.latest_revision_id,
			expected_latest_revision_id: history.latest_revision_id,
			mode,
			parent_report_id: report?.id || undefined,
		} );
	}

	async function fix( ids: number[], autoReReview: boolean ) {
		if ( ! report || ! history?.latest_revision_id || ! ids.length ) {
			return;
		}
		await onCreateJob( 'review_fix', {
			revision_id: history.latest_revision_id,
			expected_latest_revision_id: history.latest_revision_id,
			review_report_id: report.id,
			finding_ids: ids,
			auto_re_review: autoReReview,
		} );
	}

	async function transition(
		finding: ReviewFinding,
		action: 'dismiss' | 'reopen'
	) {
		if ( ! report || ! currentReport ) {
			return;
		}
		let reason = '';
		if ( action === 'dismiss' ) {
			if (
				// eslint-disable-next-line no-alert
				! window.confirm(
					__(
						'Dismiss this issue? You can reopen it later.',
						'wp-autoplugin'
					)
				)
			) {
				return;
			}
			reason =
				// eslint-disable-next-line no-alert
				window.prompt(
					__( 'Optional dismissal reason', 'wp-autoplugin' ),
					''
				) || '';
		}
		setActionError( '' );
		try {
			await apiFetch( {
				path: `${ rest }/review-findings/${ finding.id }/${ action }`,
				method: 'POST',
				data: {
					report_id: report.id,
					revision_id: report.revision_id,
					reason,
				},
			} );
			await load();
		} catch ( reasonValue: any ) {
			setActionError( reasonValue.message );
		}
	}

	async function openFinding( finding: ReviewFinding ) {
		if ( ! report || ! finding.path || ! finding.side ) {
			return;
		}
		try {
			const sourceRevision: RevisionManifest =
				revision && finding.source_revision_id === revision.id
					? revision
					: await apiFetch< RevisionManifest >( {
							path: `${ rest }/revisions/${ finding.source_revision_id }`,
					  } );
			const manifestFile = sourceRevision.files.find(
				( file ) => file.path === finding.path
			);
			if ( ! manifestFile ) {
				return;
			}
			const file = await apiFetch< RevisionFile >( {
				path: `${ rest }/revisions/${ finding.source_revision_id }/files/${ manifestFile.id }?side=${ finding.side }`,
			} );
			setViewer( { file, finding } );
		} catch ( reason: any ) {
			setActionError( reason.message );
		}
	}

	async function sendMessage(
		value = message,
		promptImages = images,
		attachmentIds: number[] = []
	) {
		if (
			! report ||
			! capability?.available ||
			( ( promptImages.length > 0 || attachmentIds.length > 0 ) &&
				! capability.images ) ||
			( ! value.trim() &&
				! promptImages.length &&
				! attachmentIds.length ) ||
			! currentReport
		) {
			return;
		}
		setSubmitting( true );
		const created = await onCreateJob(
			'conversation',
			{
				stage: 'review',
				message: value.trim(),
				revision_id: report.revision_id,
				expected_latest_revision_id: report.revision_id,
				review_report_id: report.id,
			},
			promptImages,
			attachmentIds
		);
		setSubmitting( false );
		if ( created && value === message && promptImages === images ) {
			setMessage( '' );
			setImages( [] );
		}
	}

	function reviewOverride(): boolean | null {
		if ( releaseSafe ) {
			return false;
		}
		const priorities = actionableFindings.reduce<
			Record< string, number >
		>(
			( counts, finding ) => ( {
				...counts,
				[ finding.priority ]: ( counts[ finding.priority ] || 0 ) + 1,
			} ),
			{}
		);
		// eslint-disable-next-line no-alert
		return window.confirm(
			sprintf(
				/* translators: 1: Review status. 2: Open finding priority counts. */
				__(
					'Proceed without current all-clear Review? Status: %1$s. Open findings: %2$s.',
					'wp-autoplugin'
				),
				history?.current.status || 'not_started',
				Object.entries( priorities )
					.map( ( [ key, value ] ) => `${ key }: ${ value }` )
					.join( ', ' ) || __( 'none', 'wp-autoplugin' )
			)
		)
			? true
			: null;
	}

	async function queuePackage(
		mode: 'project' | 'fork' | 'replacement' | 'theme_replacement'
	) {
		if ( ! revision ) {
			return;
		}
		const override = reviewOverride();
		if ( override === null ) {
			return;
		}
		let destinationSlug = slugify(
			revision.project_manifest?.plugin_name || ''
		);
		if ( mode === 'fork' ) {
			destinationSlug = forkSlug;
		} else if ( mode === 'theme_replacement' ) {
			destinationSlug = workspace.target_ref;
		}
		const created = await onQueueEndpoint(
			`${ rest }/revisions/${ revision.id }/release-packages`,
			{
				expected_latest_revision_id: revision.id,
				mode,
				destination_slug: destinationSlug,
				review_report_id: report?.id || undefined,
				review_override: override,
			}
		);
		if ( created ) {
			setPendingDownloadJobIds( ( current ) =>
				new Set( current ).add( created.id )
			);
		}
	}

	async function queuePromotion(
		mode:
			| 'install_project'
			| 'install_fork'
			| 'modify_original'
			| 'install_theme_copy'
			| 'modify_theme_original'
	) {
		if ( ! revision ) {
			return;
		}
		const override = reviewOverride();
		if ( override === null ) {
			return;
		}
		let targetConfirmation = '';
		if ( mode === 'modify_original' || mode === 'modify_theme_original' ) {
			if (
				// eslint-disable-next-line no-alert
				! window.confirm(
					mode === 'modify_theme_original'
						? __(
								'Modify the original inactive theme directly? Upstream updates remain enabled and may overwrite these changes. Rollback restores files only, not database or runtime side effects, and becomes unavailable if affected files drift.',
								'wp-autoplugin'
						  )
						: __(
								'Modify the original plugin directly? Upstream updates remain enabled and may overwrite these changes. Rollback restores files only, not database or runtime side effects, and becomes unavailable if affected files drift.',
								'wp-autoplugin'
						  )
				)
			) {
				return;
			}
			targetConfirmation =
				// eslint-disable-next-line no-alert
				window.prompt(
					sprintf(
						/* translators: %s: Exact target reference. */
						__( 'Type %s to confirm', 'wp-autoplugin' ),
						workspace.target_ref
					),
					''
				) || '';
			if ( targetConfirmation !== workspace.target_ref ) {
				return;
			}
		}
		let destinationSlug = slugify(
			revision.project_manifest?.plugin_name || ''
		);
		if ( mode === 'install_fork' ) {
			destinationSlug = forkSlug;
		} else if ( mode === 'install_theme_copy' ) {
			destinationSlug = themeCopySlug;
		}
		await onQueueEndpoint(
			`${ rest }/revisions/${ revision.id }/promotions`,
			{
				expected_latest_revision_id: revision.id,
				mode,
				destination_slug: destinationSlug,
				review_report_id: report?.id || undefined,
				review_override: override,
				target_confirmation: targetConfirmation,
			}
		);
	}

	if ( loading && ! history ) {
		return (
			<div className="review-stage__loading" role="status">
				<Spinner /> { __( 'Loading Review…', 'wp-autoplugin' ) }
			</div>
		);
	}
	let startReviewLabel = __( 'Start Review', 'wp-autoplugin' );
	if ( report ) {
		startReviewLabel =
			revision?.origin === 'review_fix'
				? __( 'Verify fixes', 'wp-autoplugin' )
				: __( 'Review latest', 'wp-autoplugin' );
	}
	let reviewComposerHelp = currentReport
		? __( 'To change code, use Fix on an issue.', 'wp-autoplugin' )
		: __(
				'Review latest before asking another question.',
				'wp-autoplugin'
		  );
	if ( imagesUnsupported ) {
		reviewComposerHelp = __(
			'The selected Reviewer does not accept image prompts. Remove the images or choose another model.',
			'wp-autoplugin'
		);
	}
	const reviewNumber = report
		? reviewedRevisionNumber
		: revision?.revision_number ?? 0;
	const reviewStatus = history?.current.status || 'not_started';
	const reviewVerdictLabel =
		reviewStatus === 'action_required'
			? sprintf(
					/* translators: %d: Number of unresolved Review findings. */
					_n(
						'%d issue requires action',
						'%d issues require action',
						actionableFindings.length,
						'wp-autoplugin'
					),
					actionableFindings.length
			  )
			: reviewStatusLabel( reviewStatus );
	const historyPreview = historyFindings
		.slice( 0, 2 )
		.map( ( finding ) => finding.title )
		.join( '; ' );
	let historyHeading = sprintf(
		/* translators: %d: Number of findings no longer current. */
		_n(
			'%d previous issue',
			'%d previous issues',
			historyFindings.length,
			'wp-autoplugin'
		),
		historyFindings.length
	);
	if (
		historyFindings.length > 0 &&
		historyFindings.every( ( finding ) => finding.status !== 'dismissed' )
	) {
		historyHeading = sprintf(
			/* translators: %d: Number of resolved or retracted findings. */
			_n(
				'✓ %d earlier issue resolved',
				'✓ %d earlier issues resolved',
				historyFindings.length,
				'wp-autoplugin'
			),
			historyFindings.length
		);
	}
	let latestFollowUpPreview = __(
		'No follow-up questions yet. Ask the reviewer for clarification or reconsideration.',
		'wp-autoplugin'
	);
	if ( latestConversationJob ) {
		if ( latestConversationJob.status === 'completed' ) {
			latestFollowUpPreview =
				latestConversationJob.result?.outcome === 'report'
					? __(
							'The reviewer updated this report after the latest follow-up.',
							'wp-autoplugin'
					  )
					: latestConversationJob.result?.content ||
					  __( 'The reviewer replied.', 'wp-autoplugin' );
		} else {
			latestFollowUpPreview = sprintf(
				/* translators: 1: Job number. 2: Job status. */
				__( 'Follow-up #%1$d is %2$s.', 'wp-autoplugin' ),
				latestConversationJob.id,
				latestConversationJob.status
			);
		}
	}
	function reconsiderFinding( finding: ReviewFinding ) {
		setMessage(
			sprintf(
				/* translators: 1: Finding label. 2: Finding title. */
				__( 'Please reconsider %1$s: %2$s', 'wp-autoplugin' ),
				finding.label,
				finding.title
			)
		);
		setShowConversation( true );
	}

	return (
		<div className="review-stage">
			{ actionError && (
				<Notice status="error" onRemove={ () => setActionError( '' ) }>
					{ actionError }
				</Notice>
			) }
			<header className="review-overview">
				<div className="review-overview__identity">
					<span
						className={ `review-verdict review-verdict--${ reviewStatus }` }
					>
						{ reviewVerdictLabel }
					</span>
					<div className="review-overview__copy">
						<h3>
							{ reviewNumber
								? sprintf(
										/* translators: %d: Reviewed revision number. */
										__(
											'Review for Revision %d',
											'wp-autoplugin'
										),
										reviewNumber
								  )
								: __( 'Review', 'wp-autoplugin' ) }
						</h3>
						{ report ? (
							<div className="review-overview__summary">
								<Markdown content={ report.summary } />
							</div>
						) : (
							<p>
								{ __(
									'Review the staged revision for issues before release.',
									'wp-autoplugin'
								) }
							</p>
						) }
						{ report && (
							<small>
								{ historyFindings.length > 0 && (
									<>
										{ sprintf(
											/* translators: %d: Number of findings no longer current. */
											_n(
												'%d earlier issue',
												'%d earlier issues',
												historyFindings.length,
												'wp-autoplugin'
											),
											historyFindings.length
										) }
										{ ' · ' }
									</>
								) }
								{ sprintf(
									/* translators: %s: Stored provider, model, and effort. */
									__( 'Used: %s', 'wp-autoplugin' ),
									modelIdentity(
										reportJob
											? jobModelSnapshot( reportJob )
											: report
									)
								) }
							</small>
						) }
					</div>
				</div>
				<div className="review-overview__actions">
					{ revision && (
						<Button
							variant="secondary"
							disabled={
								!! activeArtifactJob || ! capability?.available
							}
							onClick={ startReview }
						>
							{ currentReport
								? __( 'Run review again', 'wp-autoplugin' )
								: startReviewLabel }
						</Button>
					) }
					{ currentReport && selectedFinding?.status === 'open' && (
						<>
							<Button
								variant="primary"
								disabled={ !! activeArtifactJob }
								onClick={ () =>
									fix( [ selectedFinding.id ], true )
								}
							>
								{ __( 'Fix issue', 'wp-autoplugin' ) }
							</Button>
							<DropdownMenu
								icon="ellipsis"
								label={ __(
									'More fix options',
									'wp-autoplugin'
								) }
								controls={ [
									[
										{
											title: __(
												'Fix this issue without review',
												'wp-autoplugin'
											),
											isDisabled: !! activeArtifactJob,
											onClick: () =>
												fix(
													[ selectedFinding.id ],
													false
												),
										},
										{
											title: __(
												'Fix all issues',
												'wp-autoplugin'
											),
											isDisabled:
												!! activeArtifactJob ||
												openFindings.length < 2,
											onClick: () =>
												fix(
													openFindings.map(
														( finding ) =>
															finding.id
													),
													true
												),
										},
									],
								] }
							/>
						</>
					) }
				</div>
			</header>

			{ ! revision && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Complete Code before starting Review.',
						'wp-autoplugin'
					) }
				</Notice>
			) }
			{ capability && ! capability.available && (
				<Notice status="warning" isDismissible={ false }>
					{ capability.message }
				</Notice>
			) }
			{ report && ! currentReport && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This Review is stale because a newer revision exists. Review latest before relying on its verdict.',
						'wp-autoplugin'
					) }
				</Notice>
			) }
			{ latestReviewJob?.status === 'failed' && (
				<Notice status="error" isDismissible={ false }>
					<span className="job-error-message">
						{ latestReviewJob.error_message ||
							__(
								'The Review did not complete. Try again or check the selected Reviewer configuration.',
								'wp-autoplugin'
							) }
					</span>
				</Notice>
			) }
			{ activeArtifactJob && (
				<JobStatus job={ activeArtifactJob } onCancel={ onCancel } />
			) }
			{ ! activeArtifactJob &&
				latestReviewFixJob?.status === 'completed' &&
				latestReviewFixJob.result?.outcome === 'blocked' && (
					<Notice status="warning" isDismissible={ false }>
						<strong>
							{ __(
								'No safe material fix was staged',
								'wp-autoplugin'
							) }
						</strong>
						<Markdown
							content={ latestReviewFixJob.result?.content || '' }
						/>
					</Notice>
				) }
			{ ! activeArtifactJob &&
				latestReviewFixJob &&
				[ 'failed', 'cancelled' ].includes(
					latestReviewFixJob.status
				) && (
					<Notice status="error" isDismissible={ false }>
						{ latestReviewFixJob.error_message ||
							__(
								'The Review fix did not complete. The staged revision was not changed.',
								'wp-autoplugin'
							) }
					</Notice>
				) }
			{ report && (
				<>
					<section className="review-findings-board">
						<aside className="review-findings-nav">
							<header>
								<strong>
									{ __(
										'Current findings',
										'wp-autoplugin'
									) }
								</strong>
								<span>
									{ visibleFindings.length
										? sprintf(
												/* translators: 1: Selected finding position. 2: Finding count. */
												__(
													'%1$d of %2$d',
													'wp-autoplugin'
												),
												Math.max(
													1,
													visibleFindings.findIndex(
														( finding ) =>
															finding.id ===
															selectedFinding?.id
													) + 1
												),
												visibleFindings.length
										  )
										: __( '0 issues', 'wp-autoplugin' ) }
								</span>
							</header>
							<div
								className="review-findings-nav__list"
								role="group"
								aria-label={ __(
									'Current Review findings',
									'wp-autoplugin'
								) }
							>
								{ visibleFindings.map( ( finding ) => (
									<button
										key={ finding.id }
										type="button"
										aria-pressed={
											finding.id === selectedFinding?.id
										}
										className={
											finding.id === selectedFinding?.id
												? 'is-selected'
												: ''
										}
										onClick={ () =>
											setSelectedFindingId( finding.id )
										}
									>
										<i
											className={ `review-findings-nav__marker review-findings-nav__marker--${ finding.priority.toLowerCase() }` }
											aria-hidden="true"
										/>
										<span>
											<strong>{ finding.title }</strong>
											<small>
												{ finding.category } ·{ ' ' }
												{ sprintf(
													/* translators: %d: Revision number. */
													__(
														'Revision %d',
														'wp-autoplugin'
													),
													reviewedRevisionNumber
												) }
											</small>
										</span>
										<b>{ finding.priority }</b>
									</button>
								) ) }
								{ visibleFindings.length === 0 && (
									<p>
										{ __(
											'No current findings.',
											'wp-autoplugin'
										) }
									</p>
								) }
							</div>
						</aside>
						<div className="review-finding-detail-wrap">
							{ selectedFinding ? (
								<ReviewFindingDetail
									finding={ selectedFinding }
									current={ currentReport }
									active={ !! activeArtifactJob }
									onOpen={ () =>
										openFinding( selectedFinding )
									}
									onFix={ () =>
										fix( [ selectedFinding.id ], true )
									}
									onFixWithoutReview={ () =>
										fix( [ selectedFinding.id ], false )
									}
									onAsk={ () => setShowConversation( true ) }
									onReconsider={ () =>
										reconsiderFinding( selectedFinding )
									}
									onDismiss={ () =>
										transition( selectedFinding, 'dismiss' )
									}
									onHistory={ () =>
										setHistoryFinding( selectedFinding )
									}
								/>
							) : (
								<div className="review-finding-detail__empty">
									<strong>
										{ __(
											'No issues to address',
											'wp-autoplugin'
										) }
									</strong>
									<p>
										{ __(
											'The current Review has no unresolved findings.',
											'wp-autoplugin'
										) }
									</p>
								</div>
							) }
						</div>
					</section>

					{ ( historyFindings.length > 0 ||
						( history?.items.length ?? 0 ) > 1 ) && (
						<section className="review-summary-row">
							<div>
								<strong>
									{ historyFindings.length
										? historyHeading
										: __(
												'Review history',
												'wp-autoplugin'
										  ) }
								</strong>
								<p>
									{ historyPreview ||
										__(
											'Earlier reviewer reports remain available for reference.',
											'wp-autoplugin'
										) }
								</p>
							</div>
							<Button
								variant="secondary"
								onClick={ () => setShowReportHistory( true ) }
							>
								{ __( 'View history', 'wp-autoplugin' ) }
							</Button>
						</section>
					) }

					<section className="review-summary-row">
						<div>
							<strong>
								{ latestConversationJob
									? __(
											'Latest reviewer follow-up',
											'wp-autoplugin'
									  )
									: __(
											'Reviewer follow-up',
											'wp-autoplugin'
									  ) }
							</strong>
							<p>{ latestFollowUpPreview }</p>
						</div>
						<Button
							variant="secondary"
							onClick={ () => setShowConversation( true ) }
						>
							{ conversationJobs.length
								? __( 'View conversation', 'wp-autoplugin' )
								: __( 'Ask reviewer', 'wp-autoplugin' ) }
						</Button>
					</section>

					{ viewer && (
						<Modal
							className="review-source-modal"
							title={ `${ viewer.finding.label } · ${ viewer.file.path }` }
							onRequestClose={ () => setViewer( null ) }
						>
							<p className="review-modal__meta">
								{ viewer.finding.side } ·{ ' ' }
								{ viewer.finding.start_line }–
								{ viewer.finding.end_line }
							</p>
							<CodeBufferEditor
								key={ `${ viewer.file.revision_id }:${ viewer.file.id }:${ viewer.finding.side }` }
								value={ viewer.file.content }
								type={ viewer.file.type }
								readOnly
								focusLine={ viewer.finding.start_line || 1 }
								markerEndLine={
									viewer.finding.end_line ||
									viewer.finding.start_line ||
									1
								}
								onChange={ () => undefined }
							/>
						</Modal>
					) }

					{ showConversation && (
						<Modal
							className="review-conversation-modal"
							title={ __(
								'Reviewer conversation',
								'wp-autoplugin'
							) }
							onRequestClose={ () =>
								setShowConversation( false )
							}
						>
							<section className="review-conversation">
								<header>
									<h4>
										{ __(
											'Follow-up questions',
											'wp-autoplugin'
										) }
									</h4>
									<p>
										{ __(
											'Ask the reviewer to explain a finding or reconsider the current Review.',
											'wp-autoplugin'
										) }
									</p>
								</header>
								{ conversationJobs.length > 0 && (
									<div className="review-conversation__messages">
										{ conversationJobs.map( ( job ) => (
											<article
												className="review-conversation__message"
												key={ job.id }
											>
												<div className="review-conversation__question">
													<div className="review-conversation__question-meta">
														<strong>
															{ __(
																'You',
																'wp-autoplugin'
															) }
														</strong>
														{ ( job.result
															?.revision_id ??
															job.payload
																.revision_id ??
															0 ) > 0 && (
															<small>
																{ reviewRevisionLabel(
																	job.result
																		?.revision_id ??
																		job
																			.payload
																			.revision_id ??
																		0
																) }
															</small>
														) }
													</div>
													<p>
														{ job.payload.message ||
															__(
																'Image-only question',
																'wp-autoplugin'
															) }
													</p>
													<StoredPromptImages
														attachments={
															job.prompt_attachments
														}
													/>
												</div>
												<div className="review-conversation__answer">
													<strong>
														{ __(
															'Reviewer',
															'wp-autoplugin'
														) }
													</strong>
													<JobModelMeta job={ job } />
													{ job.status ===
														'completed' &&
														job.result?.outcome ===
															'report' && (
															<Notice
																status="info"
																isDismissible={
																	false
																}
															>
																{ __(
																	'The review was updated.',
																	'wp-autoplugin'
																) }
															</Notice>
														) }
													{ job.status ===
														'completed' &&
														job.result?.outcome !==
															'report' && (
															<Markdown
																content={
																	job.result
																		?.content ||
																	''
																}
															/>
														) }
													{ job.status !==
														'completed' && (
														<>
															<JobStatus
																job={ job }
																onCancel={
																	onCancel
																}
															/>
															{ [
																'failed',
																'cancelled',
															].includes(
																job.status
															) && (
																<Button
																	variant="secondary"
																	disabled={
																		!! activeArtifactJob ||
																		submitting ||
																		( job
																			.prompt_attachments
																			.length >
																			0 &&
																			! capability?.images )
																	}
																	onClick={ () =>
																		sendMessage(
																			job
																				.payload
																				.message ||
																				'',
																			[],
																			job.prompt_attachments.map(
																				(
																					item
																				) =>
																					item.id
																			)
																		)
																	}
																>
																	{ __(
																		'Retry',
																		'wp-autoplugin'
																	) }
																</Button>
															) }
														</>
													) }
												</div>
											</article>
										) ) }
									</div>
								) }
								<div className="review-conversation__composer">
									<PromptComposerField
										label={ __(
											'Ask a follow-up',
											'wp-autoplugin'
										) }
										placeholder={ __(
											'Ask a question, request reconsideration, or ask for another area to be inspected…',
											'wp-autoplugin'
										) }
										value={ message }
										files={ images }
										onChange={ setMessage }
										onFilesChange={ setImages }
										imageEnabled={ !! capability?.images }
										action={
											<Button
												variant="primary"
												isBusy={ submitting }
												disabled={
													( ! message.trim() &&
														! images.length ) ||
													! currentReport ||
													!! activeArtifactJob ||
													! capability?.available ||
													submitting ||
													imagesUnsupported
												}
												onClick={ () => sendMessage() }
											>
												{ __(
													'Send',
													'wp-autoplugin'
												) }
											</Button>
										}
										onKeyDown={ ( event ) => {
											if (
												'Enter' === event.key &&
												! event.shiftKey &&
												! event.nativeEvent.isComposing
											) {
												event.preventDefault();
												sendMessage();
											}
										} }
										disabled={
											! currentReport ||
											!! activeArtifactJob ||
											! capability?.available ||
											submitting ||
											imagesUnsupported
										}
										help={ reviewComposerHelp }
									/>
								</div>
							</section>
						</Modal>
					) }
				</>
			) }

			{ revision && (
				<ReleasePanel
					revision={ revision }
					capability={ releaseCapability }
					active={ !! activeArtifactJob }
					themeChanges={ themeChanges }
					themeTarget={
						themeChanges ? workspace.target_metadata : null
					}
					pluginProject={ pluginProject }
					pluginChanges={ pluginChanges }
					forkSlug={ forkSlug }
					onForkSlug={ setForkSlug }
					themeCopySlug={ themeCopySlug }
					onThemeCopySlug={ setThemeCopySlug }
					onPackage={ queuePackage }
					onPromotion={ queuePromotion }
					releaseJobs={ releaseJobs }
					onQueueEndpoint={ onQueueEndpoint }
					onDownload={ downloadPackage }
					manualTests={ report?.tests ?? [] }
					manualTestStatus={ reviewStatus }
					unresolvedFindings={ actionableFindings.length }
					checkedTests={ checkedTests }
					onToggleTest={ ( index, checked ) =>
						setCheckedTests( ( current ) => {
							const next = new Set( current );
							if ( checked ) {
								next.add( index );
							} else {
								next.delete( index );
							}
							return next;
						} )
					}
				/>
			) }
			{ historyFinding && (
				<Modal
					className="review-history-modal"
					title={ sprintf(
						/* translators: %s: Issue label. */
						__( '%s history', 'wp-autoplugin' ),
						historyFinding.label
					) }
					onRequestClose={ () => setHistoryFinding( null ) }
				>
					<ol className="review-modal-timeline">
						{ historyFinding.timeline.map( ( event ) => (
							<li key={ event.id }>
								<strong>
									{ event.event.replace( /_/g, ' ' ) }
								</strong>
								<small>{ event.created_at } UTC</small>
								{ event.message && <p>{ event.message }</p> }
							</li>
						) ) }
					</ol>
				</Modal>
			) }
			{ showReportHistory && history && (
				<Modal
					className="review-history-modal"
					title={ __( 'Review history', 'wp-autoplugin' ) }
					onRequestClose={ () => setShowReportHistory( false ) }
				>
					{ historyFindings.length > 0 && (
						<section className="review-history-modal__section">
							<h3>
								{ __( 'Previous issues', 'wp-autoplugin' ) }
							</h3>
							{ historyFindings.map( ( finding ) => (
								<ReviewFindingCard
									key={ finding.id }
									finding={ finding }
									selected={ false }
									selectable={ false }
									onSelect={ () => undefined }
									onOpen={ () => {
										setShowReportHistory( false );
										openFinding( finding );
									} }
									onReopen={
										finding.status === 'dismissed'
											? () =>
													transition(
														finding,
														'reopen'
													)
											: undefined
									}
									onHistory={ () => {
										setShowReportHistory( false );
										setHistoryFinding( finding );
									} }
								/>
							) ) }
						</section>
					) }
					<section className="review-history-modal__section">
						<h3>{ __( 'Review reports', 'wp-autoplugin' ) }</h3>
						<ul className="review-report-list">
							{ history.items.map( ( item ) => (
								<li key={ item.id }>
									<div>
										<strong>
											{ sprintf(
												/* translators: %d: Revision number. */
												__(
													'Revision %d',
													'wp-autoplugin'
												),
												revisions.find(
													( revisionItem ) =>
														revisionItem.id ===
														item.revision_id
												)?.revision_number ??
													item.revision_id
											) }
										</strong>
										<small>{ item.created_at } UTC</small>
									</div>
									<span>
										{ reviewStatusLabel(
											item.effective_status
										) }
									</span>
									<p>{ item.summary }</p>
								</li>
							) ) }
						</ul>
					</section>
				</Modal>
			) }
		</div>
	);
}

function ReviewFindingDetail( {
	finding,
	current,
	active,
	onOpen,
	onFix,
	onFixWithoutReview,
	onAsk,
	onReconsider,
	onDismiss,
	onHistory,
}: {
	finding: ReviewFinding;
	current: boolean;
	active: boolean;
	onOpen: () => void;
	onFix: () => void;
	onFixWithoutReview: () => void;
	onAsk: () => void;
	onReconsider: () => void;
	onDismiss: () => void;
	onHistory: () => void;
} ) {
	const canFix = current && ! active && finding.status === 'open';
	const canChangeStatus =
		current &&
		! active &&
		[ 'open', 'addressed' ].includes( finding.status );

	return (
		<article
			className={ `review-finding-detail review-finding-detail--${ finding.priority.toLowerCase() }` }
		>
			<header>
				<div>
					<span className="review-finding__priority">
						{ finding.priority }
					</span>
					<strong>{ finding.title }</strong>
				</div>
				<span className="review-finding-detail__category">
					{ finding.category }
					{ finding.status !== 'open'
						? ` · ${ finding.status.replace( /_/g, ' ' ) }`
						: '' }
				</span>
			</header>
			<Markdown content={ finding.body } />
			<div className="review-finding-detail__source">
				{ finding.path ? (
					<Button variant="link" onClick={ onOpen }>
						<code>
							{ finding.path }
							{ finding.start_line
								? `:${ finding.start_line }`
								: '' }
						</code>
					</Button>
				) : (
					<small>
						{ __( 'Project-level finding', 'wp-autoplugin' ) }
					</small>
				) }
			</div>
			{ finding.suggested_fix && (
				<details className="review-finding__fix">
					<summary>
						{ __( 'Suggested fix', 'wp-autoplugin' ) }
					</summary>
					<Markdown content={ finding.suggested_fix } />
				</details>
			) }
			<footer>
				<div className="review-finding-detail__links">
					<Button
						variant="tertiary"
						disabled={ ! current || active }
						onClick={ onAsk }
					>
						{ __( 'Ask reviewer', 'wp-autoplugin' ) }
					</Button>
					<Button
						variant="tertiary"
						disabled={ ! current || active }
						onClick={ onReconsider }
					>
						{ __( 'Reconsider', 'wp-autoplugin' ) }
					</Button>
					{ finding.timeline?.length > 1 && (
						<Button variant="tertiary" onClick={ onHistory }>
							{ __( 'History', 'wp-autoplugin' ) }
						</Button>
					) }
					{ canChangeStatus && (
						<Button variant="tertiary" onClick={ onDismiss }>
							{ __( 'Dismiss', 'wp-autoplugin' ) }
						</Button>
					) }
				</div>
				{ finding.status === 'open' && (
					<div className="review-finding-detail__fix-actions">
						<Button
							variant="primary"
							disabled={ ! canFix }
							onClick={ onFix }
						>
							{ __( 'Fix this issue', 'wp-autoplugin' ) }
						</Button>
						<DropdownMenu
							icon="ellipsis"
							label={ __( 'More fix options', 'wp-autoplugin' ) }
							controls={ [
								[
									{
										title: __(
											'Fix without review',
											'wp-autoplugin'
										),
										isDisabled: ! canFix,
										onClick: onFixWithoutReview,
									},
								],
							] }
						/>
					</div>
				) }
			</footer>
		</article>
	);
}

function ReviewFindingCard( {
	finding,
	selected,
	selectable,
	onSelect,
	onOpen,
	onFix,
	onDismiss,
	onReopen,
	onHistory,
}: {
	finding: ReviewFinding;
	selected: boolean;
	selectable: boolean;
	onSelect: ( checked: boolean ) => void;
	onOpen: () => void;
	onFix?: () => void;
	onDismiss?: () => void;
	onReopen?: () => void;
	onHistory?: () => void;
} ) {
	return (
		<article
			className={ `review-finding review-finding--${ finding.priority.toLowerCase() } status--${
				finding.status
			}` }
		>
			<header>
				{ selectable && (
					<input
						type="checkbox"
						checked={ selected }
						onChange={ ( event ) =>
							onSelect( event.target.checked )
						}
						aria-label={ sprintf(
							/* translators: %s: Finding label. */
							__( 'Select %s', 'wp-autoplugin' ),
							finding.label
						) }
					/>
				) }
				<div>
					<span className="review-finding__priority">
						{ finding.priority }
					</span>
					<span className="review-finding__id">
						{ finding.label }
					</span>
					<strong>{ finding.title }</strong>
				</div>
				<small>
					{ finding.category }
					{ finding.status !== 'open'
						? ` · ${ finding.status.replace( /_/g, ' ' ) }`
						: '' }
				</small>
			</header>
			<Markdown content={ finding.body } />
			{ finding.suggested_fix && (
				<details className="review-finding__fix">
					<summary>
						{ __( 'Suggested fix', 'wp-autoplugin' ) }
					</summary>
					<Markdown content={ finding.suggested_fix } />
				</details>
			) }
			<div className="review-finding__footer">
				{ finding.path ? (
					<Button variant="link" onClick={ onOpen }>
						<code>
							{ finding.path }
							{ finding.start_line
								? `:${ finding.start_line }`
								: '' }
						</code>
					</Button>
				) : (
					<small>
						{ __( 'Project-level finding', 'wp-autoplugin' ) }
					</small>
				) }
				<div>
					{ onHistory && finding.timeline?.length > 1 && (
						<Button variant="tertiary" onClick={ onHistory }>
							{ __( 'History', 'wp-autoplugin' ) }
						</Button>
					) }
					{ onFix && finding.status === 'open' && (
						<Button variant="secondary" onClick={ onFix }>
							{ __( 'Fix', 'wp-autoplugin' ) }
						</Button>
					) }
					{ onDismiss &&
						[ 'open', 'addressed' ].includes( finding.status ) && (
							<Button variant="tertiary" onClick={ onDismiss }>
								{ __( 'Dismiss', 'wp-autoplugin' ) }
							</Button>
						) }
					{ onReopen && (
						<Button variant="tertiary" onClick={ onReopen }>
							{ __( 'Reopen', 'wp-autoplugin' ) }
						</Button>
					) }
				</div>
			</div>
		</article>
	);
}

function ReleasePanel( {
	revision,
	capability,
	active,
	themeChanges,
	themeTarget,
	pluginProject,
	pluginChanges,
	forkSlug,
	onForkSlug,
	themeCopySlug,
	onThemeCopySlug,
	onPackage,
	onPromotion,
	releaseJobs,
	onQueueEndpoint,
	onDownload,
	manualTests,
	manualTestStatus,
	unresolvedFindings,
	checkedTests,
	onToggleTest,
}: {
	revision: RevisionManifest;
	capability: ReleaseCapability | null;
	active: boolean;
	themeChanges: boolean;
	themeTarget: Target | null;
	pluginProject: boolean;
	pluginChanges: boolean;
	forkSlug: string;
	onForkSlug: ( value: string ) => void;
	themeCopySlug: string;
	onThemeCopySlug: ( value: string ) => void;
	onPackage: (
		mode: 'project' | 'fork' | 'replacement' | 'theme_replacement'
	) => void;
	onPromotion: (
		mode:
			| 'install_project'
			| 'install_fork'
			| 'modify_original'
			| 'install_theme_copy'
			| 'modify_theme_original'
	) => void;
	releaseJobs: Job[];
	onQueueEndpoint: ( path: string, data: object ) => Promise< Job | null >;
	onDownload: ( packageId: number ) => void;
	manualTests: ReviewTest[];
	manualTestStatus: ReviewHistory[ 'current' ][ 'status' ];
	unresolvedFindings: number;
	checkedTests: Set< number >;
	onToggleTest: ( index: number, checked: boolean ) => void;
} ) {
	const [ showAllActivity, setShowAllActivity ] = useState( false );
	const installedPlugin = [ ...releaseJobs ]
		.filter(
			( candidate ) =>
				! releaseJobs.some(
					( action ) =>
						action.task === 'promotion' &&
						action.result?.outcome === 'promotion_action' &&
						action.result?.action === 'activate' &&
						action.result?.promotion_id ===
							candidate.result?.promotion_id &&
						[ 'activated', 'switched' ].includes(
							action.result?.status || ''
						)
				)
		)
		.reverse()
		.find(
			( job ) =>
				job.task === 'promotion' &&
				job.result?.outcome === 'promotion' &&
				job.result.status === 'installed' &&
				[ 'install_project', 'install_fork' ].includes(
					job.result.mode || ''
				)
		);
	const installedTheme = [ ...releaseJobs ]
		.reverse()
		.find(
			( job ) =>
				job.task === 'promotion' &&
				job.result?.outcome === 'promotion' &&
				job.result.status === 'installed' &&
				job.result.mode === 'install_theme_copy'
		);
	const directPlugin = [ ...releaseJobs ]
		.filter(
			( candidate ) =>
				! releaseJobs.some(
					( action ) =>
						action.task === 'promotion' &&
						action.result?.outcome === 'promotion_action' &&
						action.result?.action === 'rollback' &&
						action.result?.promotion_id ===
							candidate.result?.promotion_id &&
						action.result?.status === 'rolled_back'
				)
		)
		.reverse()
		.find(
			( job ) =>
				job.task === 'promotion' &&
				job.result?.mode === 'modify_original' &&
				job.result.status === 'completed'
		);
	const directTheme = [ ...releaseJobs ]
		.filter(
			( candidate ) =>
				! releaseJobs.some(
					( action ) =>
						action.task === 'promotion' &&
						action.result?.outcome === 'promotion_action' &&
						action.result?.action === 'rollback' &&
						action.result?.promotion_id ===
							candidate.result?.promotion_id &&
						action.result?.status === 'rolled_back'
				)
		)
		.reverse()
		.find(
			( job ) =>
				job.task === 'promotion' &&
				job.result?.mode === 'modify_theme_original' &&
				job.result.status === 'completed'
		);
	const disabled = active || ! revision.id;
	const themeInUse = !! themeTarget?.in_use;
	const completedTests = manualTests.reduce(
		( count, _test, index ) =>
			checkedTests.has( index ) ? count + 1 : count,
		0
	);
	const activityJobs = [ ...releaseJobs ].reverse();
	const visibleActivityJobs = showAllActivity
		? activityJobs
		: activityJobs.slice( 0, 3 );
	let releaseTitle = __( 'Review release options', 'wp-autoplugin' );
	if ( pluginProject ) {
		releaseTitle = __( 'Package and deploy this plugin', 'wp-autoplugin' );
	} else if ( pluginChanges ) {
		releaseTitle = __(
			'Package and deploy these changes',
			'wp-autoplugin'
		);
	} else if ( themeChanges ) {
		releaseTitle = __(
			'Package and deploy these theme changes',
			'wp-autoplugin'
		);
	}
	let reviewTone = 'is-neutral';
	let reviewHeading = __( 'Review not run', 'wp-autoplugin' );
	let reviewMessage = __(
		'You can release this revision, but it has not been reviewed yet.',
		'wp-autoplugin'
	);
	if ( manualTestStatus === 'action_required' ) {
		reviewTone = 'is-warning';
		reviewHeading = sprintf(
			/* translators: %d: Number of unresolved Review findings. */
			_n(
				'%d unresolved review issue',
				'%d unresolved review issues',
				unresolvedFindings,
				'wp-autoplugin'
			),
			unresolvedFindings
		);
		reviewMessage = __(
			'You can release this revision, but it has not passed Review.',
			'wp-autoplugin'
		);
	} else if (
		[ 'all_clear', 'cleared_with_dismissals' ].includes( manualTestStatus )
	) {
		reviewTone = 'is-clear';
		reviewHeading =
			manualTestStatus === 'cleared_with_dismissals'
				? __( 'Review cleared with dismissals', 'wp-autoplugin' )
				: __( 'Review passed', 'wp-autoplugin' );
		reviewMessage = __(
			'This revision has no unresolved Review issues.',
			'wp-autoplugin'
		);
	} else if ( manualTestStatus === 'stale' ) {
		reviewTone = 'is-warning';
		reviewHeading = __( 'Review is out of date', 'wp-autoplugin' );
		reviewMessage = __(
			'The available Review belongs to an earlier revision. Releasing requires confirmation.',
			'wp-autoplugin'
		);
	} else if ( manualTestStatus === 'in_progress' ) {
		reviewTone = 'is-neutral';
		reviewHeading = __( 'Review in progress', 'wp-autoplugin' );
		reviewMessage = __(
			'Release tools remain available while the current Review runs.',
			'wp-autoplugin'
		);
	} else if ( manualTestStatus === 'failed' ) {
		reviewTone = 'is-error';
		reviewHeading = __( 'Review failed', 'wp-autoplugin' );
		reviewMessage = __(
			'The latest Review did not complete. Releasing requires confirmation.',
			'wp-autoplugin'
		);
	}

	return (
		<section className="release-panel">
			<header className="release-panel__header">
				<div className="release-panel__intro">
					<p>
						{ sprintf(
							/* translators: %d: Revision number. */
							__( 'Release · Revision %d', 'wp-autoplugin' ),
							revision.revision_number
						) }
					</p>
					<h3>{ releaseTitle }</h3>
					<span>
						{ __(
							'Release tools remain available regardless of Review status.',
							'wp-autoplugin'
						) }
					</span>
				</div>
				<div
					className={ `release-panel__review-status ${ reviewTone }` }
				>
					<strong>{ reviewHeading }</strong>
					<p>{ reviewMessage }</p>
				</div>
			</header>
			<div className="release-panel__workspace">
				<section className="release-panel__action-column">
					<h4>{ __( 'Release actions', 'wp-autoplugin' ) }</h4>
					{ capability?.disabled_reasons.map( ( reason ) => (
						<Notice
							status="warning"
							isDismissible={ false }
							key={ reason }
						>
							{ reason }
						</Notice>
					) ) }

					{ installedPlugin?.result?.promotion_id && (
						<Notice status="success" isDismissible={ false }>
							<p>
								{ __(
									'Installed. Click Activate to enable the plugin on this site.',
									'wp-autoplugin'
								) }
							</p>
							<Button
								variant="primary"
								disabled={
									active || ! capability?.can_activate
								}
								onClick={ () =>
									onQueueEndpoint(
										`${ rest }/promotions/${ installedPlugin.result?.promotion_id }/activate`,
										{}
									)
								}
							>
								{ installedPlugin.result.mode === 'install_fork'
									? __( 'Switch to fork', 'wp-autoplugin' )
									: __( 'Activate', 'wp-autoplugin' ) }
							</Button>
						</Notice>
					) }

					{ installedTheme?.result?.promotion_id && (
						<Notice status="success" isDismissible={ false }>
							<p>
								{ __(
									'The theme copy was installed and left inactive. Preview or activate it from Appearance → Themes.',
									'wp-autoplugin'
								) }
							</p>
							<Button
								variant="primary"
								href={ capability?.themes_url }
							>
								{ __( 'Open Themes', 'wp-autoplugin' ) }
							</Button>
						</Notice>
					) }

					{ themeChanges && (
						<>
							{ themeInUse && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ themeTarget?.active_as_stylesheet
										? __(
												'This theme is active. ZIP download and copy installation remain available, but direct modification and rollback are blocked while it is in use.',
												'wp-autoplugin'
										  )
										: __(
												'This theme is the parent of the active child theme. ZIP download and copy installation remain available, but direct modification and rollback are blocked while it is in use.',
												'wp-autoplugin'
										  ) }
								</Notice>
							) }
							<div className="release-panel__fork-intro">
								<strong>
									{ __(
										'Install an inactive theme copy',
										'wp-autoplugin'
									) }
								</strong>
								<p>
									{ themeTarget?.is_child
										? sprintf(
												/* translators: %s: Parent theme name or slug. */
												__(
													'The copy remains a child of %s and preserves its Template header.',
													'wp-autoplugin'
												),
												themeTarget.parent_name ||
													themeTarget.parent_ref ||
													themeTarget.template ||
													''
										  )
										: __(
												'The copy is a complete standalone theme fork.',
												'wp-autoplugin'
										  ) }
								</p>
								<TextControl
									label={ __(
										'Theme copy slug',
										'wp-autoplugin'
									) }
									value={ themeCopySlug }
									onChange={ ( value ) =>
										onThemeCopySlug( slugify( value ) )
									}
								/>
							</div>
							<div className="release-panel__action-card">
								<Button
									variant="secondary"
									disabled={ disabled || ! capability?.zip }
									onClick={ () =>
										onPackage( 'theme_replacement' )
									}
								>
									{ __(
										'Download theme ZIP',
										'wp-autoplugin'
									) }
								</Button>
								<p>
									{ __(
										'Create a replacement package with the original theme slug for manual deployment.',
										'wp-autoplugin'
									) }
								</p>
							</div>
							<div className="release-panel__action-card">
								<Button
									variant="primary"
									disabled={
										disabled ||
										! capability?.can_install_themes ||
										! themeCopySlug
									}
									onClick={ () =>
										onPromotion( 'install_theme_copy' )
									}
								>
									{ __( 'Install as copy', 'wp-autoplugin' ) }
								</Button>
								<p>
									{ __(
										'Install the verified copy without activating or switching the current theme.',
										'wp-autoplugin'
									) }
								</p>
							</div>
							<p className="release-panel__testing-empty">
								{ __(
									'Theme files only are released. Customizer, Site Editor, global styles, templates, and other database-held settings are not copied.',
									'wp-autoplugin'
								) }
							</p>
							<details className="release-panel__fork-details">
								<summary>
									{ __( 'Copy details', 'wp-autoplugin' ) }
								</summary>
								<dl className="release-panel__transform-preview">
									<div>
										<dt>
											{ __(
												'Theme Name',
												'wp-autoplugin'
											) }
										</dt>
										<dd>
											{ themeTarget?.name } —{ ' ' }
											WP-Autoplugin Copy
										</dd>
									</div>
									<div>
										<dt>
											{ __(
												'Update URI',
												'wp-autoplugin'
											) }
										</dt>
										<dd>
											<code>
												https://wp-autoplugin.local/theme-copy/
												{ themeCopySlug }
											</code>
										</dd>
									</div>
									<div>
										<dt>
											{ __( 'Version', 'wp-autoplugin' ) }
										</dt>
										<dd>
											{ __(
												'Patch version bump',
												'wp-autoplugin'
											) }
										</dd>
									</div>
								</dl>
							</details>
							<details className="release-panel__advanced">
								<summary>
									{ __(
										'Advanced: modify original',
										'wp-autoplugin'
									) }
								</summary>
								<p>
									{ __(
										'Upstream theme updates may overwrite direct changes. File rollback cannot undo database or runtime side effects and is blocked whenever the theme is active or is the parent of the active child theme.',
										'wp-autoplugin'
									) }
								</p>
								<Button
									variant="secondary"
									isDestructive
									disabled={
										disabled ||
										themeInUse ||
										! capability?.can_modify_themes
									}
									onClick={ () =>
										onPromotion( 'modify_theme_original' )
									}
								>
									{ __(
										'Modify original theme',
										'wp-autoplugin'
									) }
								</Button>
							</details>
						</>
					) }

					{ pluginProject && (
						<>
							<div className="release-panel__action-card">
								<Button
									variant="secondary"
									disabled={ disabled || ! capability?.zip }
									onClick={ () => onPackage( 'project' ) }
								>
									{ __( 'Download ZIP', 'wp-autoplugin' ) }
								</Button>
								<p>
									{ __(
										'Create a portable ZIP package without changing this site.',
										'wp-autoplugin'
									) }
								</p>
							</div>
							<div className="release-panel__action-card">
								<Button
									variant="primary"
									disabled={
										disabled || ! capability?.can_install
									}
									onClick={ () =>
										onPromotion( 'install_project' )
									}
								>
									{ __(
										'Install on this site',
										'wp-autoplugin'
									) }
								</Button>
								<p>
									{ __(
										'Install this revision on the current WordPress site. Activation remains a separate step.',
										'wp-autoplugin'
									) }
								</p>
							</div>
						</>
					) }

					{ pluginChanges && (
						<>
							<div className="release-panel__fork-intro">
								<strong>
									{ __(
										'Recommended: release as a fork',
										'wp-autoplugin'
									) }
								</strong>
								<p>
									{ __(
										'The fork and original share plugin data and must not be active together.',
										'wp-autoplugin'
									) }
								</p>
								<TextControl
									label={ __(
										'Fork plugin slug',
										'wp-autoplugin'
									) }
									value={ forkSlug }
									onChange={ ( value ) =>
										onForkSlug( slugify( value ) )
									}
								/>
							</div>
							<div className="release-panel__action-card">
								<Button
									variant="secondary"
									disabled={
										disabled ||
										! capability?.zip ||
										! forkSlug
									}
									onClick={ () => onPackage( 'fork' ) }
								>
									{ __(
										'Download fork ZIP',
										'wp-autoplugin'
									) }
								</Button>
								<p>
									{ __(
										'Create a separately named plugin package while leaving the original untouched.',
										'wp-autoplugin'
									) }
								</p>
							</div>
							<div className="release-panel__action-card">
								<Button
									variant="primary"
									disabled={
										disabled ||
										! capability?.can_install ||
										! forkSlug
									}
									onClick={ () =>
										onPromotion( 'install_fork' )
									}
								>
									{ __( 'Install as fork', 'wp-autoplugin' ) }
								</Button>
								<p>
									{ __(
										'Install the fork on this site. Switching activation remains a separate step.',
										'wp-autoplugin'
									) }
								</p>
							</div>
							<details className="release-panel__fork-details">
								<summary>
									{ __( 'Fork details', 'wp-autoplugin' ) }
								</summary>
								<dl className="release-panel__transform-preview">
									<div>
										<dt>
											{ __(
												'Plugin Name',
												'wp-autoplugin'
											) }
										</dt>
										<dd>
											{
												revision.project_manifest
													?.plugin_name
											}{ ' ' }
											—{ ' ' }
											{ __(
												'WP-Autoplugin Fork',
												'wp-autoplugin'
											) }
										</dd>
									</div>
									<div>
										<dt>
											{ __(
												'Update URI',
												'wp-autoplugin'
											) }
										</dt>
										<dd>
											<code>
												https://wp-autoplugin.local/fork/
												{ forkSlug }
											</code>
										</dd>
									</div>
									<div>
										<dt>
											{ __( 'Version', 'wp-autoplugin' ) }
										</dt>
										<dd>
											{ __(
												'Patch version bump',
												'wp-autoplugin'
											) }
										</dd>
									</div>
								</dl>
							</details>
							<details className="release-panel__advanced">
								<summary>
									{ __(
										'Advanced: replace or modify the original',
										'wp-autoplugin'
									) }
								</summary>
								<p>
									{ __(
										'The replacement ZIP is for manual deployment. Upstream updates may overwrite direct changes, and file rollback cannot undo database or runtime side effects.',
										'wp-autoplugin'
									) }
								</p>
								<div className="release-panel__advanced-actions">
									<Button
										variant="secondary"
										disabled={
											disabled || ! capability?.zip
										}
										onClick={ () =>
											onPackage( 'replacement' )
										}
									>
										{ __(
											'Download replacement ZIP',
											'wp-autoplugin'
										) }
									</Button>
									<Button
										variant="secondary"
										isDestructive
										disabled={
											disabled || ! capability?.can_modify
										}
										onClick={ () =>
											onPromotion( 'modify_original' )
										}
									>
										{ __(
											'Modify original',
											'wp-autoplugin'
										) }
									</Button>
								</div>
							</details>
						</>
					) }

					{ directPlugin?.result?.promotion_id && (
						<Notice status="warning" isDismissible={ false }>
							<p>
								{ __(
									'The original plugin files were modified. Rollback remains available only while every affected file matches the promoted state.',
									'wp-autoplugin'
								) }
							</p>
							<Button
								variant="secondary"
								isDestructive
								disabled={ active || ! capability?.can_modify }
								onClick={ () =>
									onQueueEndpoint(
										`${ rest }/promotions/${ directPlugin.result?.promotion_id }/rollback`,
										{}
									)
								}
							>
								{ __( 'Rollback files', 'wp-autoplugin' ) }
							</Button>
						</Notice>
					) }

					{ directTheme?.result?.promotion_id && (
						<Notice status="warning" isDismissible={ false }>
							<p>
								{ __(
									'The original theme files were modified. Rollback remains available only while the theme is not in use and every affected file matches the promoted state.',
									'wp-autoplugin'
								) }
							</p>
							<Button
								variant="secondary"
								isDestructive
								disabled={
									active ||
									themeInUse ||
									! capability?.can_modify_themes
								}
								onClick={ () =>
									onQueueEndpoint(
										`${ rest }/promotions/${ directTheme.result?.promotion_id }/rollback`,
										{}
									)
								}
							>
								{ __(
									'Rollback theme files',
									'wp-autoplugin'
								) }
							</Button>
						</Notice>
					) }
				</section>

				<section className="release-panel__testing">
					<header>
						<div>
							<h4>{ __( 'Manual testing', 'wp-autoplugin' ) }</h4>
							<p>
								{ sprintf(
									/* translators: 1: Completed checks. 2: Total checks. */
									__(
										'%1$d of %2$d complete',
										'wp-autoplugin'
									),
									completedTests,
									manualTests.length
								) }
							</p>
						</div>
						<progress
							value={ completedTests }
							max={ Math.max( 1, manualTests.length ) }
							aria-label={ __(
								'Manual testing progress',
								'wp-autoplugin'
							) }
						/>
					</header>
					{ manualTestStatus === 'stale' &&
						manualTests.length > 0 && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'These suggestions belong to an earlier revision. Review latest to refresh them before testing.',
									'wp-autoplugin'
								) }
							</Notice>
						) }
					{ manualTests.length > 0 ? (
						<ol className="release-panel__test-list">
							{ manualTests.map( ( test, index ) => (
								<li key={ `${ test.title }:${ index }` }>
									<label
										htmlFor={ `release-test-${ revision.id }-${ index }` }
									>
										<input
											id={ `release-test-${ revision.id }-${ index }` }
											type="checkbox"
											checked={ checkedTests.has(
												index
											) }
											onChange={ ( event ) =>
												onToggleTest(
													index,
													event.target.checked
												)
											}
										/>
										<strong>{ test.title }</strong>
									</label>
									<div className="release-panel__test-details">
										<ul>
											{ test.steps.map( ( step ) => (
												<li key={ step }>{ step }</li>
											) ) }
										</ul>
										<p>
											<strong>
												{ __(
													'Expected:',
													'wp-autoplugin'
												) }
											</strong>{ ' ' }
											{ test.expected }
										</p>
									</div>
								</li>
							) ) }
						</ol>
					) : (
						<p className="release-panel__testing-empty">
							{ manualTestingEmptyLabel( manualTestStatus ) }
						</p>
					) }
					{ manualTests.length > 0 && (
						<small>
							{ __(
								'Checklist progress is kept only for this browser session.',
								'wp-autoplugin'
							) }
						</small>
					) }
				</section>
			</div>

			<footer className="release-panel__activity">
				<header>
					<div>
						<h4>{ __( 'Release activity', 'wp-autoplugin' ) }</h4>
						<p>
							{ __(
								'Recent packages, installations, copies, direct changes, rollbacks, and errors.',
								'wp-autoplugin'
							) }
						</p>
					</div>
					{ releaseJobs.length > 3 && (
						<Button
							variant="secondary"
							onClick={ () =>
								setShowAllActivity( ! showAllActivity )
							}
						>
							{ showAllActivity
								? __( 'Show recent', 'wp-autoplugin' )
								: __( 'View all', 'wp-autoplugin' ) }
						</Button>
					) }
				</header>
				{ visibleActivityJobs.length > 0 ? (
					<ul>
						{ visibleActivityJobs.map( ( job ) => {
							const tone = releaseActivityTone( job );
							let icon = '•';
							if ( tone === 'is-success' ) {
								icon = '✓';
							} else if ( tone === 'is-error' ) {
								icon = '!';
							}
							return (
								<li key={ job.id }>
									<time>{ job.created_at } UTC</time>
									<span
										className={ `release-panel__activity-icon ${ tone }` }
										aria-hidden="true"
									>
										{ icon }
									</span>
									<div>
										<strong>
											{ releaseActivityTitle( job ) }
										</strong>
										<p>
											{ releaseActivityDescription(
												job
											) }
										</p>
									</div>
									{ job.status === 'completed' &&
										job.result?.package_id && (
											<Button
												variant="link"
												onClick={ () =>
													onDownload(
														job.result
															?.package_id || 0
													)
												}
											>
												{ __(
													'Download ZIP',
													'wp-autoplugin'
												) }
											</Button>
										) }
								</li>
							);
						} ) }
					</ul>
				) : (
					<p className="release-panel__activity-empty">
						{ __(
							'No release activity yet. Packages and deployments will appear here.',
							'wp-autoplugin'
						) }
					</p>
				) }
			</footer>
		</section>
	);
}

function releaseActivityTone( job: Job ): string {
	if ( [ 'failed', 'cancelled' ].includes( job.status ) ) {
		return 'is-error';
	}
	return job.status === 'completed' ? 'is-success' : 'is-progress';
}

function releaseActivityTitle( job: Job ): string {
	const mode = job.result?.mode || job.payload.mode;
	if ( job.status === 'failed' ) {
		return __( 'Release action failed', 'wp-autoplugin' );
	}
	if ( job.status === 'cancelled' ) {
		return __( 'Release action cancelled', 'wp-autoplugin' );
	}
	if ( job.task === 'package' ) {
		if ( mode === 'theme_replacement' ) {
			return job.status === 'completed'
				? __( 'Theme ZIP created', 'wp-autoplugin' )
				: __( 'Creating theme ZIP', 'wp-autoplugin' );
		}
		return job.status === 'completed'
			? __( 'ZIP package created', 'wp-autoplugin' )
			: __( 'Creating ZIP package', 'wp-autoplugin' );
	}
	if ( job.result?.outcome === 'promotion_action' ) {
		if ( job.result.status === 'activated' ) {
			return __( 'Plugin activated', 'wp-autoplugin' );
		}
		if ( job.result.status === 'switched' ) {
			return __( 'Switched to fork', 'wp-autoplugin' );
		}
		if ( job.result.status === 'rolled_back' ) {
			return job.result.artifact_kind === 'theme'
				? __( 'Theme files rolled back', 'wp-autoplugin' )
				: __( 'Plugin files rolled back', 'wp-autoplugin' );
		}
	}
	if ( mode === 'install_theme_copy' ) {
		return job.status === 'completed'
			? __( 'Theme copy installed', 'wp-autoplugin' )
			: __( 'Installing theme copy', 'wp-autoplugin' );
	}
	if ( mode === 'modify_theme_original' ) {
		return job.status === 'completed'
			? __( 'Original theme modified', 'wp-autoplugin' )
			: __( 'Modifying original theme', 'wp-autoplugin' );
	}
	if ( mode === 'install_fork' ) {
		return job.status === 'completed'
			? __( 'Fork installed', 'wp-autoplugin' )
			: __( 'Installing fork', 'wp-autoplugin' );
	}
	if ( mode === 'modify_original' ) {
		return job.status === 'completed'
			? __( 'Original plugin modified', 'wp-autoplugin' )
			: __( 'Modifying original plugin', 'wp-autoplugin' );
	}
	return job.status === 'completed'
		? __( 'Plugin installed', 'wp-autoplugin' )
		: __( 'Installing plugin', 'wp-autoplugin' );
}

function releaseActivityDescription( job: Job ): string {
	if ( job.error_message ) {
		return job.error_message;
	}
	if ( job.task === 'package' && job.status === 'completed' ) {
		return __( 'The private ZIP is ready to download.', 'wp-autoplugin' );
	}
	if (
		job.result?.outcome === 'promotion' &&
		job.result.status === 'installed'
	) {
		if ( job.result.artifact_kind === 'theme' ) {
			return __(
				'Installed successfully and left inactive. Preview or activate it from Appearance → Themes.',
				'wp-autoplugin'
			);
		}
		return __(
			'Installed successfully. Activation remains a separate action.',
			'wp-autoplugin'
		);
	}
	if (
		job.result?.outcome === 'promotion' &&
		job.result.status === 'completed'
	) {
		return job.result.artifact_kind === 'theme'
			? __(
					'The inactive original theme files now match this revision; drift-safe file rollback is available.',
					'wp-autoplugin'
			  )
			: __(
					'The original plugin files now match this revision; drift-safe file rollback is available.',
					'wp-autoplugin'
			  );
	}
	if (
		job.result?.outcome === 'promotion_action' &&
		job.result.status === 'rolled_back'
	) {
		return job.result.artifact_kind === 'theme'
			? __(
					'The direct theme file changes were restored.',
					'wp-autoplugin'
			  )
			: __(
					'The direct plugin file changes were restored.',
					'wp-autoplugin'
			  );
	}
	if ( job.latest_event?.message ) {
		return job.latest_event.message;
	}
	return sprintf(
		/* translators: 1: Job number. 2: Job status. */
		__( 'Release job #%1$d is %2$s.', 'wp-autoplugin' ),
		job.id,
		job.status
	);
}

function slugify( value: string ): string {
	return value
		.toLowerCase()
		.normalize( 'NFD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' )
		.slice( 0, 100 );
}

function reviewStatusLabel( status: string ): string {
	switch ( status ) {
		case 'all_clear':
			return __( 'No issues', 'wp-autoplugin' );
		case 'cleared_with_dismissals':
			return __( 'Dismissed issues', 'wp-autoplugin' );
		case 'action_required':
			return __( 'Action required', 'wp-autoplugin' );
		case 'stale':
			return __( 'Stale', 'wp-autoplugin' );
		case 'in_progress':
			return __( 'In progress', 'wp-autoplugin' );
		case 'failed':
			return __( 'Failed', 'wp-autoplugin' );
		default:
			return __( 'Not started', 'wp-autoplugin' );
	}
}

function manualTestingEmptyLabel(
	status: ReviewHistory[ 'current' ][ 'status' ]
): string {
	if ( status === 'not_started' ) {
		return __(
			'Start Review to generate suggested manual checks for this revision.',
			'wp-autoplugin'
		);
	}
	if ( status === 'in_progress' ) {
		return __(
			'Suggested manual checks will appear when Review completes.',
			'wp-autoplugin'
		);
	}
	if ( status === 'failed' ) {
		return __(
			'Retry Review to generate suggested manual checks.',
			'wp-autoplugin'
		);
	}
	if ( status === 'stale' ) {
		return __(
			'Review latest to generate manual checks for this revision.',
			'wp-autoplugin'
		);
	}
	return __(
		'The current Review did not include any manual test cases.',
		'wp-autoplugin'
	);
}

function ExplainStage( {
	jobs,
	initialMessage,
	capability,
	onCancel,
	onFollowUp,
}: {
	jobs: Job[];
	initialMessage: string;
	capability: AgentCapability | null;
	onCancel: ( job: Job ) => void;
	onFollowUp: (
		message: string,
		images?: File[],
		attachmentIds?: number[]
	) => Promise< Job | null >;
} ) {
	return (
		<StageConversation
			stage="explain"
			jobs={ jobs }
			initialMessage={ initialMessage }
			capability={ capability }
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
	capability,
	onCancel,
	onFollowUp,
}: {
	stage: 'plan' | 'explain';
	jobs: Job[];
	initialMessage?: string;
	artifactJobId?: number;
	capability: AgentCapability | null;
	onCancel: ( job: Job ) => void;
	onFollowUp: (
		message: string,
		artifactJobId?: number,
		images?: File[],
		attachmentIds?: number[]
	) => Promise< Job | null >;
} ) {
	const [ message, setMessage ] = useState( '' );
	const [ images, setImages ] = useState< File[] >( [] );
	const [ submitting, setSubmitting ] = useState( false );
	const isPlan = stage === 'plan';
	const disabled = submitting || ! capability?.available;
	const imagesUnsupported = images.length > 0 && ! capability?.images;
	const submitMessage = async () => {
		if (
			disabled ||
			imagesUnsupported ||
			( ! message.trim() && ! images.length )
		) {
			return;
		}
		setSubmitting( true );
		const created = await onFollowUp(
			message.trim(),
			artifactJobId,
			images
		);
		setSubmitting( false );
		if ( created ) {
			setMessage( '' );
			setImages( [] );
		}
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
									__(
										'Image-only question',
										'wp-autoplugin'
									) }
							</p>
							<StoredPromptImages
								attachments={ job.prompt_attachments }
							/>
						</div>
						<div className="explain-message__answer">
							<strong>
								{ isPlan
									? __( 'Plan assistant', 'wp-autoplugin' )
									: __( 'Explain', 'wp-autoplugin' ) }
							</strong>
							<JobModelMeta job={ job } />
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
										disabled={
											disabled ||
											( job.prompt_attachments.length >
												0 &&
												! capability?.images )
										}
										onClick={ () =>
											onFollowUp(
												job.payload.message || '',
												artifactJobId,
												[],
												job.prompt_attachments.map(
													( item ) => item.id
												)
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
											disabled={
												disabled ||
												( job.prompt_attachments
													.length > 0 &&
													! capability?.images )
											}
											onClick={ () =>
												onFollowUp(
													job.payload.message || '',
													artifactJobId,
													[],
													job.prompt_attachments.map(
														( item ) => item.id
													)
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
			{ capability && ! capability.available && (
				<Notice status="warning" isDismissible={ false }>
					{ capability.message }
				</Notice>
			) }
			<div className="explain-stage__composer">
				<PromptComposerField
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
					files={ images }
					disabled={ disabled }
					onChange={ setMessage }
					onFilesChange={ setImages }
					imageEnabled={ !! capability?.images }
					action={
						<Button
							variant="primary"
							isBusy={ submitting }
							disabled={
								disabled ||
								imagesUnsupported ||
								( ! message.trim() && ! images.length )
							}
							onClick={ submitMessage }
						>
							{ isPlan
								? __( 'Send', 'wp-autoplugin' )
								: __( 'Ask', 'wp-autoplugin' ) }
						</Button>
					}
					help={
						imagesUnsupported
							? __(
									'The selected model does not accept image prompts. Remove the images or choose another model.',
									'wp-autoplugin'
							  )
							: undefined
					}
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
				/>
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

function getActivityStageLabel( stage: 'plan' | 'code' | 'review' | 'chat' ) {
	switch ( stage ) {
		case 'plan':
			return __( 'Plan', 'wp-autoplugin' );
		case 'code':
			return __( 'Code', 'wp-autoplugin' );
		case 'review':
			return __( 'Review', 'wp-autoplugin' );
		default:
			return __( 'Chat', 'wp-autoplugin' );
	}
}

function getActivityStatusLabel(
	stage: 'plan' | 'code' | 'review' | 'chat',
	status:
		| 'complete'
		| 'in_progress'
		| 'incomplete'
		| 'not_started'
		| 'all_clear'
		| 'cleared_with_dismissals'
		| 'action_required'
		| 'stale'
		| 'failed'
) {
	if (
		stage === 'review' &&
		! [ 'complete', 'incomplete' ].includes( status )
	) {
		return reviewStatusLabel( status );
	}
	if ( status === 'in_progress' ) {
		return __( 'In progress', 'wp-autoplugin' );
	}
	if ( stage === 'chat' ) {
		if ( status === 'complete' ) {
			return __( 'History available', 'wp-autoplugin' );
		}
		return status === 'incomplete'
			? __( 'Needs attention', 'wp-autoplugin' )
			: __( 'No messages', 'wp-autoplugin' );
	}
	if ( status === 'complete' ) {
		return __( 'Complete', 'wp-autoplugin' );
	}
	return status === 'incomplete'
		? __( 'Incomplete', 'wp-autoplugin' )
		: __( 'Not started', 'wp-autoplugin' );
}

function getActivityMarker(
	status:
		| 'complete'
		| 'in_progress'
		| 'incomplete'
		| 'not_started'
		| 'all_clear'
		| 'cleared_with_dismissals'
		| 'action_required'
		| 'stale'
		| 'failed'
) {
	switch ( status ) {
		case 'complete':
		case 'all_clear':
		case 'cleared_with_dismissals':
			return '✓';
		case 'in_progress':
			return '…';
		case 'incomplete':
		case 'action_required':
		case 'stale':
		case 'failed':
			return '!';
		default:
			return '○';
	}
}

function formatWorkspaceDate( value: string ) {
	const normalized = value.includes( 'T' )
		? value
		: `${ value.replace( ' ', 'T' ) }Z`;
	const date = new Date( normalized );
	if ( Number.isNaN( date.getTime() ) ) {
		return value;
	}

	return new Intl.DateTimeFormat( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
	} ).format( date );
}

function getTabLabel( tab: string ) {
	switch ( tab ) {
		case 'plan':
			return __( 'Plan', 'wp-autoplugin' );
		case 'code':
			return __( 'Code', 'wp-autoplugin' );
		case 'review':
			return __( 'Review', 'wp-autoplugin' );
		default:
			return __( 'Explain', 'wp-autoplugin' );
	}
}

function getTabDescription( tab: string ) {
	switch ( tab ) {
		case 'plan':
			return __( 'Define the work', 'wp-autoplugin' );
		case 'code':
			return __( 'Build from the Plan', 'wp-autoplugin' );
		default:
			return __( 'Assess staged Code', 'wp-autoplugin' );
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
