( function () {
	'use strict';

	const root = document.getElementById( 'wp-autoplugin-chatgpt-provider' );
	if ( ! root || ! window.wp?.apiFetch || ! window.wpAutopluginChatGPT ) {
		return;
	}

	const config = window.wpAutopluginChatGPT;
	const strings = config.strings;
	const elements = {
		status: root.querySelector( '.wp-autoplugin-chatgpt-status' ),
		notice: root.querySelector( '.wp-autoplugin-chatgpt-notice' ),
		account: root.querySelector( '.wp-autoplugin-chatgpt-account' ),
		device: root.querySelector( '.wp-autoplugin-chatgpt-device' ),
		code: root.querySelector( '.wp-autoplugin-chatgpt-code' ),
		open: root.querySelector( '.wp-autoplugin-chatgpt-open' ),
		connect: root.querySelector( '.wp-autoplugin-chatgpt-connect' ),
		copy: root.querySelector( '.wp-autoplugin-chatgpt-copy' ),
		cancel: root.querySelector( '.wp-autoplugin-chatgpt-cancel' ),
		refresh: root.querySelector( '.wp-autoplugin-chatgpt-refresh' ),
		disconnect: root.querySelector( '.wp-autoplugin-chatgpt-disconnect' ),
	};
	const renderedModelSync = Number( root.dataset.modelSyncedAt || 0 );
	let session = null;
	let pollTimer = null;

	function request( path = '', options = {} ) {
		return window.wp.apiFetch( {
			path: `${ config.path }${ path }`,
			...options,
		} );
	}

	function setNotice( message = '', type = 'error' ) {
		elements.notice.hidden = ! message;
		elements.notice.className = `description wp-autoplugin-chatgpt-notice is-${ type }`;
		elements.notice.textContent = message;
	}

	function setBusy( busy ) {
		root.querySelectorAll( 'button' ).forEach( ( button ) => {
			button.disabled = busy;
		} );
	}

	function render( state ) {
		const pending =
			state.session &&
			[ 'pending', 'exchanging' ].includes( state.session.status );
		const reconnectRequired = Boolean( state.reconnect_required );
		let statusState = 'disconnected';
		let statusText = strings.disconnected;

		if ( pending ) {
			statusState = 'pending';
			statusText = strings.waiting;
		} else if ( reconnectRequired ) {
			statusState = 'error';
			statusText = strings.reconnectRequired;
		} else if ( state.connected ) {
			statusState = 'connected';
			statusText = strings.connected;
		}

		session = pending ? state.session : null;
		elements.status.dataset.state = statusState;
		elements.status.textContent = statusText;
		elements.account.hidden = ! state.connected || ! state.account_label;
		elements.account.textContent = state.connected
			? state.account_label
			: '';
		elements.device.hidden = ! pending;
		elements.code.textContent = pending ? state.session.user_code : '';
		elements.open.href = pending ? state.session.verification_url : '#';
		elements.connect.hidden =
			( state.connected && ! reconnectRequired ) || pending;
		elements.connect.textContent = reconnectRequired
			? strings.reconnect
			: strings.connect;
		elements.cancel.hidden = ! pending;
		elements.refresh.hidden = ! state.connected || reconnectRequired;
		elements.disconnect.hidden = ! state.connected;
		if (
			! pending &&
			Number( state.models?.last_synced_at || 0 ) > renderedModelSync
		) {
			window.location.reload();
			return;
		}
		if ( state.error ) {
			setNotice( state.error );
		}
		if ( pending ) {
			schedulePoll(
				Math.max(
					1,
					state.session.retry_after || state.session.interval || 5
				)
			);
		}
	}

	function schedulePoll( seconds ) {
		window.clearTimeout( pollTimer );
		pollTimer = window.setTimeout( poll, seconds * 1000 );
	}

	async function load() {
		try {
			render( await request() );
		} catch ( error ) {
			setNotice( error.message || strings.genericError );
		}
	}

	async function connect() {
		setBusy( true );
		setNotice( '' );
		elements.status.dataset.state = 'pending';
		elements.status.textContent = strings.connecting;
		try {
			const started = await request( '/oauth/start', { method: 'POST' } );
			session = started;
			render( { connected: false, session: started, models: {} } );
		} catch ( error ) {
			setNotice( error.message || strings.genericError );
		} finally {
			setBusy( false );
		}
	}

	async function poll() {
		if ( ! session ) {
			return;
		}
		try {
			const result = await request( '/oauth/poll', {
				method: 'POST',
				data: { session_id: session.session_id },
			} );
			if ( result.status === 'pending' ) {
				schedulePoll( result.retry_after || session.interval || 5 );
				return;
			}
			window.location.reload();
		} catch ( error ) {
			if (
				error.code === 'chatgpt_oauth_poll_early' ||
				error.code === 'chatgpt_oauth_locked'
			) {
				schedulePoll(
					error.data?.retry_after || session.interval || 5
				);
				return;
			}
			setNotice( error.message || strings.genericError );
			if ( error.data?.retryable ) {
				schedulePoll(
					error.data?.retry_after || session.interval || 5
				);
			}
		}
	}

	async function cancel() {
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
		} catch ( error ) {
			setNotice( error.message || strings.genericError );
		}
	}

	async function disconnect() {
		// WordPress uses a native confirmation for destructive Settings actions.
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( strings.confirmDisconnect ) ) {
			return;
		}
		setBusy( true );
		try {
			await request( '/connection', { method: 'DELETE' } );
			window.location.reload();
		} catch ( error ) {
			setNotice( error.message || strings.genericError );
		} finally {
			setBusy( false );
		}
	}

	async function refresh() {
		setBusy( true );
		try {
			await request( '/models/refresh', { method: 'POST' } );
			window.location.reload();
		} catch ( error ) {
			setNotice( error.message || strings.genericError );
		} finally {
			setBusy( false );
		}
	}

	elements.connect.addEventListener( 'click', connect );
	elements.cancel.addEventListener( 'click', cancel );
	elements.disconnect.addEventListener( 'click', disconnect );
	elements.refresh.addEventListener( 'click', refresh );
	elements.copy.addEventListener( 'click', async () => {
		try {
			await window.navigator.clipboard.writeText(
				elements.code.textContent
			);
			setNotice( strings.copied, 'success' );
		} catch ( error ) {
			setNotice( strings.copyFailed, 'warning' );
		}
	} );

	load();
} )();
