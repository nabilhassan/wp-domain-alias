/**
 * WP Domain Alias settings screen: progressive health check.
 *
 * Without JavaScript the health check still works through normal form posts,
 * a few domains per request. With JavaScript it checks one domain per AJAX
 * request and shows each result as it arrives, so no request runs long and
 * the page stays responsive. All markup is rendered and escaped server-side.
 */
( function () {
	'use strict';

	var data = window.wpdaHealth;
	var start = document.getElementById( 'wpda-health-start' );
	var progress = document.getElementById( 'wpda-health-progress' );
	var results = document.getElementById( 'wpda-health-results' );
	if ( ! data || ! start || ! progress || ! results || ! window.fetch || ! window.FormData ) {
		return;
	}

	function post( action, nonce, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce );
		Object.keys( extra || {} ).forEach( function ( key ) {
			body.append( key, extra[ key ] );
		} );
		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, data: {} };
			} );
		} );
	}

	function format( checked, total ) {
		return data.i18n.progress.replace( '%1$d', checked ).replace( '%2$d', total );
	}

	function setBusy( busy ) {
		document.querySelectorAll( '#wpda-health input[type="submit"], #wpda-health button' ).forEach( function ( button ) {
			button.disabled = busy;
		} );
	}

	function fail( message ) {
		progress.textContent = message || data.i18n.failed;
		setBusy( false );
	}

	function step( run, total ) {
		post( 'wpda_health_step', data.stepNonce, { run: run } ).then( function ( reply ) {
			if ( ! reply || ! reply.success ) {
				fail( reply && reply.data && reply.data.message );
				return;
			}
			results.insertAdjacentHTML( 'beforeend', reply.data.html );
			if ( reply.data.done ) {
				progress.textContent = data.i18n.done;
				var more = document.getElementById( 'wpda-health-continue' );
				if ( more ) {
					more.remove();
				}
				setBusy( false );
				return;
			}
			progress.textContent = format( reply.data.checked, reply.data.total || total );
			step( run, total );
		} ).catch( function () {
			fail();
		} );
	}

	start.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		setBusy( true );
		post( 'wpda_health_start', data.startNonce ).then( function ( reply ) {
			if ( ! reply || ! reply.success ) {
				fail( reply && reply.data && reply.data.message );
				return;
			}
			results.innerHTML = '';
			var more = document.getElementById( 'wpda-health-continue' );
			if ( more ) {
				more.remove();
			}
			progress.textContent = format( 0, reply.data.total );
			step( reply.data.run, reply.data.total );
		} ).catch( function () {
			fail();
		} );
	} );

	var resume = document.getElementById( 'wpda-health-continue' );
	if ( resume ) {
		resume.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			setBusy( true );
			step( '', 0 );
		} );
	}
}() );
