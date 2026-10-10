/**
 * WebP Studio admin UI.
 *
 * Plain ES5-compatible browser JavaScript with jQuery for requests, matching how
 * wp-admin already ships scripts. No build step, no framework: the project has none.
 *
 * Responsibilities:
 * - Tab navigation (roving tabindex, arrow keys, ARIA tablist pattern).
 * - Live job status polling with backoff, pause when the tab is hidden.
 * - Quality slider, number field and presets kept in sync, with debounced preview.
 * - Orphan review, two-step delete.
 * - Log rendering and clearing.
 *
 * All server data arrives through the endpoints; nothing here invents state.
 */
( function ( $, wps ) {
	'use strict';

	if ( ! $ || ! wps ) {
		return;
	}

	var ajax = wps.ajaxUrl;
	var nonce = wps.nonce;

	// Guard against two admin tabs polling and driving the same queue.
	var CHANNEL = 'wps-ui-active';
	var isActiveTab = true;

	function tabIsActive() {
		return isActiveTab;
	}

	/* ------------------------------------------------------------------ */
	/* Requests                                                            */
	/* ------------------------------------------------------------------ */

	function post( action, data, done, fail ) {
		return $.ajax( {
			url: ajax,
			type: 'POST',
			dataType: 'json',
			data: $.extend( {
				action: action,
				_ajax_nonce: nonce
			}, data || {} )
		} ).done( function ( response ) {
			if ( response && response.success ) {
				done( response.data );
			} else if ( fail ) {
				var message = ( response && response.data ) ? response.data : wps.l10n.genericError;
				fail( message );
			}
		} ).fail( function () {
			if ( fail ) {
				fail( wps.l10n.networkError );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Tabs                                                                */
	/* ------------------------------------------------------------------ */

	function initTabs() {
		var $tabs = $( '.wps-nav__tab' );
		var $panels = $( '.wps-panel' );

		if ( ! $tabs.length ) {
			return;
		}

		function select( id, focus ) {
			$tabs.each( function () {
				var $tab = $( this );
				var selected = $tab.attr( 'data-tab' ) === id;
				$tab.attr( 'aria-selected', selected ? 'true' : 'false' );
				$tab.attr( 'tabindex', selected ? '0' : '-1' );
			} );

			$panels.each( function () {
				$( this ).prop( 'hidden', $( this ).attr( 'data-panel' ) !== id );
			} );

			if ( focus ) {
				$tabs.filter( '[data-tab="' + id + '"]' ).trigger( 'focus' );
			}

			try {
				window.sessionStorage.setItem( 'wps-tab', id );
			} catch ( e ) {
				// Storage unavailable; the default tab is fine.
			}
		}

		$tabs.on( 'click', function () {
			select( $( this ).attr( 'data-tab' ) );
		} );

		$tabs.on( 'keydown', function ( e ) {
			var index = $tabs.index( this );
			var next = null;

			if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
				next = ( index + 1 ) % $tabs.length;
			} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
				next = ( index - 1 + $tabs.length ) % $tabs.length;
			} else if ( e.key === 'Home' ) {
				next = 0;
			} else if ( e.key === 'End' ) {
				next = $tabs.length - 1;
			}

			if ( next !== null ) {
				e.preventDefault();
				select( $tabs.eq( next ).attr( 'data-tab' ), true );
			}
		} );

		var initial = $tabs.filter( '[aria-selected="true"]' ).attr( 'data-tab' );
		try {
			var stored = window.sessionStorage.getItem( 'wps-tab' );
			if ( stored && $tabs.filter( '[data-tab="' + stored + '"]' ).length ) {
				initial = stored;
			}
		} catch ( e ) {
			// Ignore and keep the default.
		}

		select( initial );
	}

	/* ------------------------------------------------------------------ */
	/* Status rendering                                                    */
	/* ------------------------------------------------------------------ */

	var STATE_LABELS = {
		idle: wps.l10n.stateIdle,
		preparing: wps.l10n.statePreparing,
		queued: wps.l10n.stateQueued,
		running: wps.l10n.stateRunning,
		done: wps.l10n.stateDone,
		done_with_issues: wps.l10n.stateDoneWithIssues,
		failed: wps.l10n.stateFailed,
		unknown: wps.l10n.stateUnknown
	};

	function renderStatus( data ) {
		var $status = $( '.wps-status' );
		var state = data.state || 'unknown';

		$status.attr( 'data-state', state );
		$( '.wps-status__label' ).text( STATE_LABELS[ state ] || state );
		$( '.wps-status__message' ).text( data.message || '' );

		// Library coverage, distinct from this run's progress.
		if ( typeof data.coverage === 'number' ) {
			$( '.wps-coverage__fill' ).css( 'width', data.coverage + '%' )
				.toggleClass( 'is-complete', data.coverage >= 100 );
			$( '.wps-summary__percent' ).text( data.coverage + '%' );
		}

		// Metrics.
		$( '[data-metric="total"]' ).text( data.total );
		$( '[data-metric="converted"]' ).text( data.converted );
		$( '[data-metric="pending"]' ).text( data.pending );

		// Run metrics, labelled separately so they are not read as inventory.
		$( '[data-run="processed"]' ).text( data.processed );
		$( '[data-run="skipped"]' ).text( data.skipped );
		$( '[data-run="updated"]' ).text( data.updated || wps.l10n.never );

		$( '[data-tab-count="pending"]' ).text( data.pending );

		// Run progress bar only makes sense with a stable total.
		var $job = $( '.wps-job' );
		if ( data.runTotal > 0 ) {
			$job.show();
			$( '.wps-job__fill' ).css( 'width', Math.min( 100, Math.round( ( data.processed / data.runTotal ) * 100 ) ) + '%' );
			$( '.wps-job__text' ).text( wps.l10n.runProgress
				.replace( '%1$d', data.processed )
				.replace( '%2$d', data.runTotal ) );
		} else {
			$job.hide();
		}

		// Convert button reflects the real state and never starts a second job.
		var $convert = $( '[data-action="convert"]' );
		var busy = ( state === 'running' || state === 'preparing' || state === 'queued' );

		$convert.prop( 'disabled', busy || data.pending === 0 );
		$convert.text( data.pending > 0
			? wps.l10n.convertN.replace( '%d', data.pending )
			: wps.l10n.convertedAll );
	}

	/* ------------------------------------------------------------------ */
	/* Polling                                                             */
	/* ------------------------------------------------------------------ */

	var pollTimer = null;
	var pollDelay = 3000;
	var POLL_MAX = 20000;
	var lastRunSignature = '';

	function stopPolling() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function schedulePoll( delay ) {
		stopPolling();
		if ( ! tabIsActive() ) {
			return;
		}
		pollTimer = window.setTimeout( poll, delay || pollDelay );
	}

	function refreshStatus( initial ) {
		if ( ! tabIsActive() ) {
			return;
		}

		post( 'wp_image_webp_converter_get_status', {}, function ( data ) {
			pollDelay = 3000;
			renderStatus( data );

			// Discard stale responses: if the run identity changed, the newer
			// response is the one that counts and we ask again straight away.
			var signature = data.runId + ':' + data.cursor;
			if ( initial || signature !== lastRunSignature ) {
				lastRunSignature = signature;
				refreshLog();
			}

			var active = ( data.state === 'running' || data.state === 'preparing' || data.state === 'queued' );
			schedulePoll( active ? pollDelay : null );
		}, function () {
			// A transport error is not a job failure. Back off and keep asking;
			// never restart work because the browser lost the connection.
			pollDelay = Math.min( POLL_MAX, pollDelay * 2 );
			renderStatus( { state: 'unknown', message: wps.l10n.connectionLost } );
			schedulePoll( pollDelay );
		} );
	}

	function poll() {
		refreshStatus( false );
	}

	function refreshLog( page ) {
		post( 'wp_image_webp_converter_get_history', { page: page || 1 }, function ( data ) {
			renderLog( data.entries || [], data );
		} );
	}

	function renderLog( entries, data ) {
		var $log = $( '.wps-log' );
		$log.empty();

		if ( ! entries.length ) {
			$log.append( '<li class="wps-log__empty">' + wps.l10n.noActivity + '</li>' );
		} else {
			$.each( entries, function ( i, entry ) {
				var $li = $( '<li class="wps-log__entry"></li>' ).attr( 'data-level', entry.level );
				$li.append( $( '<span class="wps-log__meta"></span>' ).text( entry.time ) );
				$li.append( document.createTextNode( entry.message ) );
				$log.append( $li );
			} );
		}

		var $more = $( '[data-action="more-log"]' );
		if ( data && data.total > ( data.page * data.perPage ) ) {
			$more.show().text( wps.l10n.showMore );
		} else {
			$more.hide();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Quality + preview                                                   */
	/* ------------------------------------------------------------------ */

	function initQuality() {
		var $slider = $( '[data-control="quality-slider"]' );
		var $number = $( '[data-control="quality-number"]' );
		var $value = $( '[data-control="quality-value"]' );

		if ( ! $slider.length ) {
			return;
		}

		var timer = null;
		var seq = 0;

		function clamp( value ) {
			value = parseInt( value, 10 );
			if ( isNaN( value ) ) {
				value = 80;
			}
			return Math.max( 0, Math.min( 100, value ) );
		}

		function apply( value ) {
			value = clamp( value );
			$slider.val( value );
			$number.val( value );
			$value.text( value );
			return value;
		}

		function requestPreview( quality ) {
			var mine = ++seq;
			var $item = $( '[data-preview="figure"]' );
			var $status = $( '[data-preview="status"]' );

			$item.addClass( 'is-loading' );
			$status.text( wps.l10n.previewGenerating );

			post( 'wp_image_webp_converter_preview', { quality: quality }, function ( data ) {
				// A slower earlier request must not overwrite a newer result.
				if ( mine !== seq ) {
					return;
				}

				$item.removeClass( 'is-loading' );
				$( '[data-preview="image"]' ).attr( 'src', data.url + '?t=' + Date.now() );
				$( '[data-preview="size"]' ).text( data.sizeLabel );

				var $delta = $( '[data-preview="delta"]' ).removeClass( 'is-good is-warn is-neutral' );

				if ( data.savings > 0 ) {
					$delta.addClass( 'is-good' ).text(
						wps.l10n.previewSaving.replace( '%1$d', data.savings ).replace( '%2$s', data.jpegLabel ).replace( '%3$s', data.sizeLabel )
					);
				} else if ( data.savings < 0 ) {
					$delta.addClass( 'is-warn' ).text(
						wps.l10n.previewHeavier.replace( '%1$d', Math.abs( data.savings ) ).replace( '%2$s', data.jpegLabel )
					);
				} else {
					$delta.addClass( 'is-neutral' ).text( wps.l10n.previewSame );
				}

				$status.text( '' );
			}, function ( message ) {
				if ( mine !== seq ) {
					return;
				}
				$item.removeClass( 'is-loading' );
				$status.text( message || wps.l10n.previewFailed );
			} );
		}

		function schedulePreview( quality ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				requestPreview( quality );
			}, 350 );
		}

		$slider.on( 'input change', function () {
			var value = apply( $( this ).val() );
			schedulePreview( value );
		} );

		$number.on( 'input change', function () {
			var value = apply( $( this ).val() );
			schedulePreview( value );
		} );

		$( '[data-preset]' ).on( 'click', function () {
			var value = apply( $( this ).attr( 'data-preset' ) );
			schedulePreview( value );
		} );

		// First render of the preview uses the stored quality.
		requestPreview( apply( $slider.val() ) );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	function initActions() {
		$( '[data-action="convert"]' ).on( 'click', function () {
			var $btn = $( this );
			var count = parseInt( $( '[data-metric="pending"]' ).text(), 10 ) || 0;

			if ( count <= 0 ) {
				return;
			}

			var message = wps.l10n.confirmConvert.replace( '%d', count );
			if ( $( '[data-control="delete-original"]:checked' ).length ) {
				message += ' ' + wps.l10n.confirmIrreversible;
			}
			message += '\n\n' + wps.l10n.confirmQuality.replace( '%d', $( '[data-control="quality-value"]' ).text() );

			// A native confirm is the accessible baseline here: it blocks, is
			// keyboard operable and needs no focus trap. The mockup's inline
			// dialog is not carried over because it has no focus management.
			if ( ! window.confirm( message ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_start_run', {}, function () {
				lastRunSignature = '';
				pollDelay = 3000;
				refreshStatus( true );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				$( '[data-role="convert-feedback"]' ).text( msg );
			} );
		} );

		// Orphan review: always a dry run first.
		$( '[data-action="review-orphans"]' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_cleanup_orphans', { dry_run: '1' }, function ( data ) {
				$btn.prop( 'disabled', false );

				var $panel = $( '[data-panel="orphans"]' );
				var $result = $( '[data-orphans="result"]' );
				var $list = $( '[data-orphans="list"]' ).empty();
				var $confirm = $( '[data-action="delete-orphans"]' );

				$result.text( data.message );

				if ( ! data.removed ) {
					$panel.hide();
					return;
				}

				$.each( data.files || [], function ( i, file ) {
					$list.append( $( '<li></li>' ).text( file ) );
				} );

				$panel.show();
				$confirm.prop( 'disabled', false );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				$( '[data-orphans="result"]' ).text( msg );
			} );
		} );

		$( '[data-action="cancel-orphans"]' ).on( 'click', function () {
			$( '[data-panel="orphans"]' ).hide();
			$( '[data-action="delete-orphans"]' ).prop( 'disabled', true );
		} );

		$( '[data-action="delete-orphans"]' ).on( 'click', function () {
			var $btn = $( this );

			if ( ! window.confirm( wps.l10n.confirmDeleteOrphans ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			// Re-validated server side: the candidate list is recomputed before
			// anything is removed, so a file that changed in between survives.
			post( 'wp_image_webp_converter_cleanup_orphans', {}, function ( data ) {
				$( '[data-panel="orphans"]' ).hide();
				$( '[data-orphans="result"]' ).text( data.message );
				refreshStatus( true );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				$( '[data-orphans="result"]' ).text( msg );
			} );
		} );

		$( '[data-action="clear-log"]' ).on( 'click', function () {
			if ( ! window.confirm( wps.l10n.confirmClearLog ) ) {
				return;
			}

			post( 'wp_image_webp_converter_clear_history', {}, function () {
				refreshLog();
			} );
		} );

		$( '[data-action="more-log"]' ).on( 'click', function () {
			var page = parseInt( $( this ).attr( 'data-page' ), 10 ) || 1;
			$( this ).attr( 'data-page', page + 1 );
			refreshLog( page + 1 );
		} );

		$( '[data-action="toggle-activity"]' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_toggle_activity', {}, function ( data ) {
				$btn.prop( 'disabled', false );
				$( '[data-role="activity-state"]' ).text( data.enabled ? wps.l10n.enabled : wps.l10n.disabled );
				$( '[data-role="activity-feedback"]' ).text( data.message );
				$btn.toggleClass( 'wps-btn--primary', !! data.enabled );
				$btn.attr( 'aria-pressed', data.enabled ? 'true' : 'false' );
				$btn.text( data.enabled ? wps.l10n.disable : wps.l10n.enable );
			} );
		} );

		// Unsaved-changes marker: configuration must never silently apply to a run.
		var form = $( '[data-form="settings"]' )[0];

		if ( form ) {
			var $dirty = $( '[data-role="dirty"]' );

			$( 'input, select', form ).on( 'change', function () {
				$dirty.text( wps.l10n.unsavedChanges ).show();
			} );

			$( form ).on( 'submit', function () {
				$dirty.hide();
			} );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Rewrite queue                                                        */
	/* ------------------------------------------------------------------ */

	function queueOptions() {
		var $dry = $( '[data-queue-option="dry_run"]:checked' ).length > 0;
		var $restart = $( '[data-queue-option="restart"]:checked' ).length > 0;
		var $revisions = $( '[data-queue-option="include_revisions"]:checked' ).length > 0;

		return { dry_run: dry, restart: $restart, include_revisions: $revisions };
	}

	function renderQueue( data ) {
		if ( ! data ) {
			return;
		}

		var counts = data.counts || {};
		var handled = ( counts.done || 0 ) + ( counts.skipped || 0 ) + ( counts.failed || 0 );
		var total = handled + ( counts.pending || 0 );
		var percent = total > 0 ? Math.round( ( handled / total ) * 100 ) : 0;

		$( '[data-queue="pending"]' ).text( counts.pending || 0 );
		$( '[data-queue="done"]' ).text( counts.done || 0 );
		$( '[data-queue="replacements"]' ).text( data.replacements || 0 );

		// Pending purges. Read from the server payload, never inferred here.
		var purge = data.purge || {};

		$( '[data-queue="purge-pending"]' ).text( purge.pending || 0 );
		$( '[data-queue="purge-waiting"]' ).text( purge.waiting || 0 );
		$( '[data-queue="purge-deleted"]' ).text( data.purgeLogTotal || 0 );

		// The button is only ever enabled for work that actually exists. It is
		// deliberately NOT disabled while the reference index is incomplete:
		// pressing it must be able to explain why nothing was deleted, which is
		// more useful than a control that silently does nothing.
		$( '[data-queue-action="purge-originals"]' ).prop( 'disabled', ! ( purge.pending > 0 ) );

		$( '[data-queue="purge-blocked"]' ).prop( 'hidden', ! ( purge.pending > 0 && purge.ready === false ) );
		$( '[data-queue="purge-open"]' ).prop( 'hidden', ! ( data.purge && data.purge.openRewrite > 0 ) );

		$( '[data-queue="fill"]' ).css( 'width', percent + '%' );
		$( '[data-queue="progress"]' ).text( wps.l10n.queueProgress
			.replace( '%1$d', handled )
			.replace( '%2$d', total ) );

		$( '[data-queue="summary"]' ).text( wps.l10n.queueSummary
			.replace( '%1$d', counts.skipped || 0 )
			.replace( '%2$d', counts.failed || 0 )
			.replace( '%3$s', ( wps.l10n.queueScanPhase || {} )[ data.phase ] || data.phase || '' ) );

		$( '[data-queue-action="install"]' ).prop( 'hidden', !! data.tablesReady );
		$( '[data-queue-action="process"]' ).prop( 'disabled', ! ( counts.pending > 0 ) );
		$( '[data-queue-action="rollback"]' ).prop( 'disabled', ! ( data.logOpen > 0 ) );

		if ( data.last ) {
			$( '[data-queue="last"]' )
				.text( wps.l10n.queueLast
					.replace( '%1$d', data.last.queueId )
					.replace( '%2$d', data.last.replacements )
					.replace( '%3$s', data.last.createdAt ) )
				.show();
		} else {
			$( '[data-queue="last"]' ).hide();
		}

		if ( data.tablesReady ) {
			$( '[data-queue="purge"]' ).prop( 'hidden', ! ( data.logTotal > 0 ) );
		}
	}

	function initQueue() {
		var $card = $( '[data-queue="card"]' );

		if ( ! $card.length ) {
			return;
		}

		var $feedback = $( '[data-queue="feedback"]' );

		function say( message, isError ) {
			$feedback.text( message || '' )
				.toggleClass( 'is-error', !! isError );
		}

		$card.on( 'click', '[data-queue-action="install"]', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_queue_install', {}, function ( data ) {
				$btn.prop( 'disabled', false );
				say( data.message );
				renderQueue( data.progress );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				say( msg, true );
			} );
		} );

		$card.on( 'click', '[data-queue-action="enqueue"]', function () {
			var $btn = $( this );
			var opts = queueOptions();

			if ( ! window.confirm( wps.l10n.queueConfirmEnqueue ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_queue_enqueue', {
				dry_run: opts.dry_run ? '1' : '0',
				restart: opts.restart ? '1' : '0',
				include_revisions: opts.include_revisions ? '1' : '0'
			}, function ( data ) {
				$btn.prop( 'disabled', false );
				say( data.message );
				renderQueue( data.progress );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				say( msg, true );
			} );
		} );

		$card.on( 'click', '[data-queue-action="process"]', function () {
			var $btn = $( this );
			var opts = queueOptions();

			if ( ! opts.dry_run && ! window.confirm( wps.l10n.queueConfirmProcess ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_queue_process', {
				dry_run: opts.dry_run ? '1' : '0'
			}, function ( data ) {
				$btn.prop( 'disabled', false );
				say( data.message );
				renderQueue( data.progress );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				say( msg, true );
			} );
		} );

		$card.on( 'click', '[data-queue-action="rollback"]', function () {
			var $btn = $( this );

			if ( ! window.confirm( wps.l10n.queueConfirmRollback ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_queue_rollback', {}, function ( data ) {
				$btn.prop( 'disabled', false );
				say( data.message );
				renderQueue( data.progress );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				say( msg, true );
			} );
		} );

		// Verified purge of originals. Distinct from the history purge below:
		// that one destroys reversibility, this one deletes files from disk, and
		// mixing the two in one confirmation dialog would make it impossible to
		// warn about the consequence that actually applies.
		$card.on( 'click', '[data-queue-action="purge-originals"]', function () {
			var $btn = $( this );
			var opts = queueOptions();

			if ( ! opts.dry_run && ! window.confirm( wps.l10n.queuePurgeOriginalsConfirm ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_queue_purge_originals', {
				dry_run: opts.dry_run ? '1' : '0'
			}, function ( data ) {
				$btn.prop( 'disabled', false );
				say( data.message );
				$( '[data-queue="purge-feedback"]' ).text( data.message );
				renderQueue( data.progress );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				say( msg, true );
				$( '[data-queue="purge-feedback"]' ).text( msg );
			} );
		} );

		// Purge is the only irreversible action here, so it takes two clicks and
		// states the consequence in words before the second one is even offered.
		$card.on( 'click', '[data-queue-action="purge-step-1"]', function () {
			$( '[data-queue="purge"]' ).prop( 'hidden', false )
				.find( '[data-queue-action="purge-step-2"]' ).prop( 'disabled', false );
		} );

		$card.on( 'click', '[data-queue-action="purge-cancel"]', function () {
			$( '[data-queue="purge"]' ).prop( 'hidden', true )
				.find( '[data-queue-action="purge-step-2"]' ).prop( 'disabled', true );
		} );

		$card.on( 'click', '[data-queue-action="purge-step-2"]', function () {
			var $btn = $( this );

			if ( ! window.confirm( wps.l10n.queuePurgeConfirm ) ) {
				return;
			}

			$btn.prop( 'disabled', true );

			post( 'wp_image_webp_converter_queue_purge', { confirm: '1' }, function ( data ) {
				$btn.prop( 'disabled', true );
				say( data.message );
				$( '[data-queue="purge"]' ).prop( 'hidden', true );
				renderQueue( data.progress );
			}, function ( msg ) {
				$btn.prop( 'disabled', false );
				say( msg, true );
			} );
		} );

		post( 'wp_image_webp_converter_queue_status', {}, function ( data ) {
			renderQueue( data );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Visibility + cross-tab guard                                        */
	/* ------------------------------------------------------------------ */

	function initVisibility() {
		isActiveTab = ( navigator.userAgent.indexOf( 'Firefox' ) === -1 )
			? document.hasFocus()
			: ! document.hidden;

		if ( ! tabIsActive() ) {
			try {
				window.localStorage.removeItem( CHANNEL );
			} catch ( e ) {
				// Ignore.
			}
		}

		document.addEventListener( 'visibilitychange', function () {
			isActiveTab = ! document.hidden;

			if ( tabIsActive() ) {
				// Resume immediately rather than waiting out the old timer.
				refreshStatus( true );
			} else {
				stopPolling();
			}
		} );

		// Best-effort single-writer hint. The server lock is the real guarantee;
		// this only avoids two tabs talking over each other.
		try {
			if ( tabIsActive() ) {
				window.localStorage.setItem( CHANNEL, String( Date.now() ) );
			}
		} catch ( e ) {
			// Ignore.
		}
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	$( function () {
		initTabs();
		initQuality();
		initActions();
		initQueue();
		initVisibility();
		refreshStatus( true );
	} );

} )( window.jQuery, window.wpsStudio );