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
		noticeText: root.querySelector( '.wp-autoplugin-chatgpt-notice p' ),
		account: root.querySelector( '.wp-autoplugin-chatgpt-account' ),
		device: root.querySelector( '.wp-autoplugin-chatgpt-device' ),
		code: root.querySelector( '.wp-autoplugin-chatgpt-code' ),
		open: root.querySelector( '.wp-autoplugin-chatgpt-open' ),
		connect: root.querySelector( '.wp-autoplugin-chatgpt-connect' ),
		copy: root.querySelector( '.wp-autoplugin-chatgpt-copy' ),
		cancel: root.querySelector( '.wp-autoplugin-chatgpt-cancel' ),
		refresh: root.querySelector( '.wp-autoplugin-chatgpt-refresh' ),
		disconnect: root.querySelector( '.wp-autoplugin-chatgpt-disconnect' ),
		modelStatus: root.querySelector(
			'.wp-autoplugin-chatgpt-model-status'
		),
	};
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
		elements.notice.className = `notice inline wp-autoplugin-chatgpt-notice notice-${ type }`;
		elements.noticeText.textContent = message;
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
		session = pending ? state.session : null;
		elements.status.dataset.state = state.connected
			? 'connected'
			: 'disconnected';
		elements.status.textContent = state.connected
			? strings.connected
			: strings.disconnected;
		elements.account.hidden = ! state.connected;
		elements.account.textContent = state.connected
			? `${ strings.account } ${ state.account_label }`
			: '';
		elements.device.hidden = ! pending;
		elements.code.textContent = pending ? state.session.user_code : '';
		elements.open.href = pending ? state.session.verification_url : '#';
		elements.connect.hidden = state.connected || pending;
		elements.cancel.hidden = ! pending;
		elements.refresh.hidden = ! state.connected;
		elements.disconnect.hidden = ! state.connected;
		const models = state.models || {};
		const count = Object.keys( models.models || {} ).length;
		elements.modelStatus.textContent =
			models.error ||
			( models.last_synced_at
				? `${ count } ${ strings.modelsAvailable }`
				: strings.modelsNotSynced );
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
		setNotice( strings.connecting, 'info' );
		try {
			const started = await request( '/oauth/start', { method: 'POST' } );
			session = started;
			setNotice( strings.waiting, 'info' );
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
			setNotice(
				result.notice || '',
				result.notice ? 'warning' : 'success'
			);
			await load();
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
			setNotice( '' );
			await load();
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
			setNotice( strings.modelsRefreshed, 'success' );
			await load();
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
