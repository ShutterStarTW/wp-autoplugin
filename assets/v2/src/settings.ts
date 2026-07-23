import apiFetch from '@wordpress/api-fetch';
import './settings.scss';

type CustomModel = {
	name: string;
	url: string;
	modelParameter: string;
	apiKey: string;
	headers: string[];
};

type EffortCapability = {
	levels: string[];
	default: string;
};

type SettingsStrings = {
	showSpecialized: string;
	hideSpecialized: string;
	modelDefault: string;
	efforts: Record< string, string >;
	customModels: string;
	details: string;
	url: string;
	modelParameter: string;
	apiKey: string;
	headers: string;
	remove: string;
	fillOutFields: string;
	removeModel: string;
	connected: string;
	disconnected: string;
	waiting: string;
	connecting: string;
	connect: string;
	reconnect: string;
	reconnectRequired: string;
	copied: string;
	copyFailed: string;
	confirmDisconnect: string;
	genericError: string;
};

type SettingsConfig = {
	chatgptPath: string;
	effortCapabilities: Record< string, EffortCapability >;
	selectedEfforts: Record< string, string >;
	strings: SettingsStrings;
};

type ChatGPTSession = {
	session_id: string;
	status: string;
	user_code: string;
	verification_url: string;
	retry_after?: number;
	interval?: number;
};

type ChatGPTState = {
	connected: boolean;
	account_label?: string;
	reconnect_required?: boolean;
	error?: string;
	session?: ChatGPTSession | null;
	models?: {
		last_synced_at?: number;
	};
};

type ApiError = {
	code?: string;
	message?: string;
	data?: {
		retry_after?: number;
		retryable?: boolean;
	};
};

declare global {
	interface Window {
		wpAutopluginV2Settings?: SettingsConfig;
	}
}

const config = window.wpAutopluginV2Settings;

if ( config ) {
	initializeModelSettings( config );
	initializeCustomModels( config );
	initializeChatGPT( config );
}

function initializeModelSettings( settings: SettingsConfig ) {
	const specialized = document.querySelector< HTMLElement >(
		'.wp-autoplugin-per-step-models'
	);
	const toggle = document.getElementById( 'toggle-specialized-models' );
	const toggleLabel = toggle?.querySelector< HTMLElement >(
		'.wp-autoplugin-toggle-label'
	);
	const toggleIcon = toggle?.querySelector< HTMLElement >( '.dashicons' );

	const setSpecializedVisible = ( visible: boolean ) => {
		if ( ! specialized || ! toggle || ! toggleLabel || ! toggleIcon ) {
			return;
		}

		specialized.hidden = ! visible;
		toggle.setAttribute( 'aria-expanded', visible ? 'true' : 'false' );
		toggleLabel.textContent = visible
			? settings.strings.hideSpecialized
			: settings.strings.showSpecialized;
		toggleIcon.classList.toggle( 'dashicons-arrow-up-alt2', visible );
		toggleIcon.classList.toggle( 'dashicons-arrow-down-alt2', ! visible );
	};

	toggle?.addEventListener( 'click', () => {
		setSpecializedVisible( specialized?.hidden ?? true );
	} );

	const updateEffortControl = ( select: HTMLSelectElement ) => {
		const role = select.dataset.modelRole || '';
		const wrapper = document.querySelector< HTMLElement >(
			`.wp-autoplugin-model-effort[data-effort-role="${ role }"]`
		);
		const effortSelect =
			wrapper?.querySelector< HTMLSelectElement >( 'select' );
		const capability = settings.effortCapabilities[ select.value ];

		if ( ! wrapper || ! effortSelect || ! capability ) {
			if ( wrapper ) {
				wrapper.hidden = true;
			}
			return;
		}

		const saved = settings.selectedEfforts[ role ] || '';
		const effort = capability.levels.includes( saved )
			? saved
			: capability.default;

		effortSelect.replaceChildren(
			...capability.levels.map( ( level ) => {
				const option = document.createElement( 'option' );
				const suffix =
					level === capability.default
						? ` (${ settings.strings.modelDefault })`
						: '';
				option.value = level;
				option.textContent = `${
					settings.strings.efforts[ level ] || level
				}${ suffix }`;
				option.selected = level === effort;
				return option;
			} )
		);
		settings.selectedEfforts[ role ] = effort;
		wrapper.hidden = false;
	};

	document
		.querySelectorAll< HTMLSelectElement >( '[data-model-select]' )
		.forEach( ( select ) => {
			select.addEventListener( 'change', () =>
				updateEffortControl( select )
			);
			updateEffortControl( select );
		} );

	document
		.querySelectorAll< HTMLSelectElement >(
			'.wp-autoplugin-model-effort-select'
		)
		.forEach( ( select ) => {
			select.addEventListener( 'change', () => {
				const role =
					select.closest< HTMLElement >(
						'.wp-autoplugin-model-effort'
					)?.dataset.effortRole || '';
				settings.selectedEfforts[ role ] = select.value;
			} );
		} );
}

function initializeCustomModels( settings: SettingsConfig ) {
	const hidden = document.getElementById(
		'wp_autoplugin_custom_models'
	) as HTMLInputElement | null;
	const list = document.querySelector< HTMLElement >(
		'.custom-models-items'
	);
	const add = document.getElementById( 'add-custom-model' );
	if ( ! hidden || ! list || ! add ) {
		return;
	}

	let customModels: CustomModel[] = [];
	try {
		const parsed: unknown = JSON.parse( hidden.value || '[]' );
		if ( Array.isArray( parsed ) ) {
			customModels = parsed.filter( isCustomModel );
		}
	} catch {
		customModels = [];
	}

	const fields = {
		name: document.getElementById(
			'custom-model-name'
		) as HTMLInputElement | null,
		url: document.getElementById(
			'custom-model-url'
		) as HTMLInputElement | null,
		modelParameter: document.getElementById(
			'custom-model-parameter'
		) as HTMLInputElement | null,
		apiKey: document.getElementById(
			'custom-model-api-key'
		) as HTMLInputElement | null,
		headers: document.getElementById(
			'custom-model-headers'
		) as HTMLTextAreaElement | null,
	};

	const appendDetail = (
		container: HTMLElement,
		label: string,
		value: string
	) => {
		const paragraph = document.createElement( 'p' );
		const strong = document.createElement( 'strong' );
		strong.textContent = `${ label }: `;
		paragraph.append( strong, document.createTextNode( value ) );
		container.append( paragraph );
	};

	const render = () => {
		const selections = new Map< HTMLSelectElement, string >();
		document
			.querySelectorAll< HTMLSelectElement >( '[data-model-select]' )
			.forEach( ( select ) => selections.set( select, select.value ) );

		list.replaceChildren();
		customModels.forEach( ( model, index ) => {
			const item = document.createElement( 'div' );
			item.className = 'custom-model-item';

			const name = document.createElement( 'strong' );
			name.textContent = model.name;

			const details = document.createElement( 'details' );
			const summary = document.createElement( 'summary' );
			summary.textContent = settings.strings.details;
			details.append( summary );
			appendDetail( details, settings.strings.url, model.url );
			appendDetail(
				details,
				settings.strings.modelParameter,
				model.modelParameter || model.name
			);
			appendDetail(
				details,
				settings.strings.apiKey,
				`***${ model.apiKey.slice( -3 ) }`
			);
			appendDetail(
				details,
				settings.strings.headers,
				model.headers.join( ', ' ) || '—'
			);

			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button remove-model';
			remove.textContent = settings.strings.remove;
			remove.addEventListener( 'click', () => {
				// WordPress uses a native confirmation for destructive Settings actions.
				// eslint-disable-next-line no-alert
				if ( window.confirm( settings.strings.removeModel ) ) {
					customModels.splice( index, 1 );
					render();
				}
			} );

			item.append( name, details, remove );
			list.append( item );
		} );

		selections.forEach( ( selected, select ) => {
			select
				.querySelectorAll( '[data-custom-model-group]' )
				.forEach( ( group ) => group.remove() );

			if ( customModels.length ) {
				const group = document.createElement( 'optgroup' );
				group.label = settings.strings.customModels;
				group.dataset.customModelGroup = '';
				customModels.forEach( ( model ) => {
					const option = document.createElement( 'option' );
					option.value = model.name;
					option.textContent = model.name;
					group.append( option );
				} );
				select.append( group );
			}

			const option = Array.from( select.options ).find(
				( candidate ) => candidate.value === selected
			);
			if ( option ) {
				select.value = selected;
			} else if ( select.dataset.modelRole === 'default' ) {
				select.selectedIndex = 0;
			} else {
				select.value = '';
			}
			select.dispatchEvent( new Event( 'change' ) );
		} );

		hidden.value = JSON.stringify( customModels );
	};

	add.addEventListener( 'click', () => {
		const name = fields.name?.value.trim() || '';
		const url = fields.url?.value.trim() || '';
		const modelParameter = fields.modelParameter?.value.trim() || '';
		const apiKey = fields.apiKey?.value.trim() || '';
		let validUrl = false;

		try {
			validUrl = [ 'http:', 'https:' ].includes(
				new URL( url ).protocol
			);
		} catch {
			validUrl = false;
		}

		const duplicate =
			customModels.some( ( model ) => model.name === name ) ||
			Array.from(
				document.querySelectorAll< HTMLOptionElement >(
					'[data-model-select] option'
				)
			).some( ( option ) => option.value === name );

		if ( ! name || ! url || ! apiKey || ! validUrl || duplicate ) {
			// eslint-disable-next-line no-alert
			window.alert( settings.strings.fillOutFields );
			return;
		}

		const headers = ( fields.headers?.value || '' )
			.split( '\n' )
			.map( ( header ) => header.trim() )
			.filter( Boolean );
		customModels.push( {
			name,
			url,
			modelParameter,
			apiKey,
			headers,
		} );
		Object.values( fields ).forEach( ( field ) => {
			if ( field ) {
				field.value = '';
			}
		} );
		render();
	} );

	render();
}

function isCustomModel( value: unknown ): value is CustomModel {
	if ( ! value || typeof value !== 'object' ) {
		return false;
	}
	const model = value as Partial< CustomModel >;
	return (
		typeof model.name === 'string' &&
		typeof model.url === 'string' &&
		typeof model.modelParameter === 'string' &&
		typeof model.apiKey === 'string' &&
		Array.isArray( model.headers )
	);
}

function initializeChatGPT( settings: SettingsConfig ) {
	const root = document.getElementById( 'wp-autoplugin-chatgpt-provider' );
	if ( ! root ) {
		return;
	}

	const status = root.querySelector< HTMLElement >(
		'.wp-autoplugin-chatgpt-status'
	);
	const notice = root.querySelector< HTMLElement >(
		'.wp-autoplugin-chatgpt-notice'
	);
	const account = root.querySelector< HTMLElement >(
		'.wp-autoplugin-chatgpt-account'
	);
	const device = root.querySelector< HTMLElement >(
		'.wp-autoplugin-chatgpt-device'
	);
	const code = root.querySelector< HTMLElement >(
		'.wp-autoplugin-chatgpt-code'
	);
	const open = root.querySelector< HTMLAnchorElement >(
		'.wp-autoplugin-chatgpt-open'
	);
	const connect = root.querySelector< HTMLButtonElement >(
		'.wp-autoplugin-chatgpt-connect'
	);
	const copy = root.querySelector< HTMLButtonElement >(
		'.wp-autoplugin-chatgpt-copy'
	);
	const cancelButton = root.querySelector< HTMLButtonElement >(
		'.wp-autoplugin-chatgpt-cancel'
	);
	const refresh = root.querySelector< HTMLButtonElement >(
		'.wp-autoplugin-chatgpt-refresh'
	);
	const disconnect = root.querySelector< HTMLButtonElement >(
		'.wp-autoplugin-chatgpt-disconnect'
	);

	if (
		! status ||
		! notice ||
		! account ||
		! device ||
		! code ||
		! open ||
		! connect ||
		! copy ||
		! cancelButton ||
		! refresh ||
		! disconnect
	) {
		return;
	}

	const renderedModelSync = Number( root.dataset.modelSyncedAt || 0 );
	let session: ChatGPTSession | null = null;
	let pollTimer: number | undefined;

	const request = < T >(
		path = '',
		options: Record< string, unknown > = {}
	): Promise< T > =>
		apiFetch< T >( {
			path: `${ settings.chatgptPath }${ path }`,
			...options,
		} );

	const error = ( caught: unknown ): ApiError =>
		caught && typeof caught === 'object' ? ( caught as ApiError ) : {};

	const setNotice = ( message = '', type = 'error' ) => {
		notice.hidden = ! message;
		notice.className = `description wp-autoplugin-chatgpt-notice is-${ type }`;
		notice.textContent = message;
	};

	const setBusy = ( busy: boolean ) => {
		root.querySelectorAll< HTMLButtonElement >( 'button' ).forEach(
			( button ) => {
				button.disabled = busy;
			}
		);
	};

	const schedulePoll = ( seconds: number ) => {
		window.clearTimeout( pollTimer );
		pollTimer = window.setTimeout( poll, seconds * 1000 );
	};

	const render = ( state: ChatGPTState ) => {
		const pending =
			state.session &&
			[ 'pending', 'exchanging' ].includes( state.session.status );
		const reconnectRequired = Boolean( state.reconnect_required );
		let statusState = 'disconnected';
		let statusText = settings.strings.disconnected;

		if ( pending ) {
			statusState = 'pending';
			statusText = settings.strings.waiting;
		} else if ( reconnectRequired ) {
			statusState = 'error';
			statusText = settings.strings.reconnectRequired;
		} else if ( state.connected ) {
			statusState = 'connected';
			statusText = settings.strings.connected;
		}

		session = pending ? state.session || null : null;
		status.dataset.state = statusState;
		status.textContent = statusText;
		account.hidden = ! state.connected || ! state.account_label;
		account.textContent = state.connected ? state.account_label || '' : '';
		device.hidden = ! pending;
		code.textContent = pending ? state.session?.user_code || '' : '';
		open.href = pending ? state.session?.verification_url || '#' : '#';
		connect.hidden =
			( state.connected && ! reconnectRequired ) || Boolean( pending );
		connect.textContent = reconnectRequired
			? settings.strings.reconnect
			: settings.strings.connect;
		cancelButton.hidden = ! pending;
		refresh.hidden = ! state.connected || reconnectRequired;
		disconnect.hidden = ! state.connected;

		if (
			! pending &&
			Number( state.models?.last_synced_at || 0 ) > renderedModelSync
		) {
			window.location.reload();
			return;
		}
		setNotice( state.error || '' );
		if ( pending && state.session ) {
			schedulePoll(
				Math.max(
					1,
					state.session.retry_after || state.session.interval || 5
				)
			);
		}
	};

	const load = async () => {
		try {
			render( await request< ChatGPTState >() );
		} catch ( caught ) {
			setNotice(
				error( caught ).message || settings.strings.genericError
			);
		}
	};

	const poll = async () => {
		if ( ! session ) {
			return;
		}
		try {
			const result = await request< ChatGPTSession >( '/oauth/poll', {
				method: 'POST',
				data: { session_id: session.session_id },
			} );
			if ( result.status === 'pending' ) {
				schedulePoll( result.retry_after || session.interval || 5 );
				return;
			}
			window.location.reload();
		} catch ( caught ) {
			const apiError = error( caught );
			if (
				apiError.code === 'chatgpt_oauth_poll_early' ||
				apiError.code === 'chatgpt_oauth_locked'
			) {
				schedulePoll(
					apiError.data?.retry_after || session.interval || 5
				);
				return;
			}
			setNotice( apiError.message || settings.strings.genericError );
			if ( apiError.data?.retryable ) {
				schedulePoll(
					apiError.data.retry_after || session.interval || 5
				);
			}
		}
	};

	connect.addEventListener( 'click', async () => {
		setBusy( true );
		setNotice();
		status.dataset.state = 'pending';
		status.textContent = settings.strings.connecting;
		try {
			const started = await request< ChatGPTSession >( '/oauth/start', {
				method: 'POST',
			} );
			session = started;
			render( {
				connected: false,
				session: started,
				models: {},
			} );
		} catch ( caught ) {
			setNotice(
				error( caught ).message || settings.strings.genericError
			);
		} finally {
			setBusy( false );
		}
	} );

	cancelButton.addEventListener( 'click', async () => {
		if ( ! session ) {
			return;
		}
		window.clearTimeout( pollTimer );
		try {
			await request( '/oauth/cancel', {
				method: 'POST',
				data: { session_id: session.session_id },
			} );
			session = null;
			await load();
		} catch ( caught ) {
			setNotice(
				error( caught ).message || settings.strings.genericError
			);
		}
	} );

	disconnect.addEventListener( 'click', async () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( settings.strings.confirmDisconnect ) ) {
			return;
		}
		setBusy( true );
		try {
			await request( '/connection', { method: 'DELETE' } );
			window.location.reload();
		} catch ( caught ) {
			setNotice(
				error( caught ).message || settings.strings.genericError
			);
		} finally {
			setBusy( false );
		}
	} );

	refresh.addEventListener( 'click', async () => {
		setBusy( true );
		try {
			await request( '/models/refresh', { method: 'POST' } );
			window.location.reload();
		} catch ( caught ) {
			setNotice(
				error( caught ).message || settings.strings.genericError
			);
		} finally {
			setBusy( false );
		}
	} );

	copy.addEventListener( 'click', async () => {
		try {
			await window.navigator.clipboard.writeText(
				code.textContent || ''
			);
			setNotice( settings.strings.copied, 'success' );
		} catch {
			setNotice( settings.strings.copyFailed, 'warning' );
		}
	} );

	load();
}
