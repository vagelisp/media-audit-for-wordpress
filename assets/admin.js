/* global MediaAudit */
/* eslint-disable jsdoc/require-param-type -- Dashboard callbacks document semantics; localized data has a runtime-defined shape. */
/* eslint-disable no-alert -- Native confirmation dialogs guard every destructive action. */

( function () {
	'use strict';

	// All mutable UI and scan coordination state lives here. scanSequence acts as
	// a generation ID so late responses from a stopped scan cannot overwrite a
	// newer scan or a stop response.
	const uiRowBatchSize = Math.max(
		50,
		Math.min( 1000, Number( MediaAudit.uiRowBatchSize || 250 ) )
	);
	const state = {
		findings: MediaAudit.findings || {},
		stats: MediaAudit.stats || {},
		integrity: MediaAudit.integrity || {},
		selected: {},
		quarantineSelected: {},
		integritySelected: {},
		resultLimit: uiRowBatchSize,
		integrityLimit: uiRowBatchSize,
		integrityVariantLimit: uiRowBatchSize,
		actionRows: [],
		actionLimit: uiRowBatchSize,
		busy: false,
		scanActive: false,
		scanToken: '',
		scanSequence: 0,
		scanStarted: 0,
		scanHeartbeat: null,
		stopRequested: false,
		stopInFlight: false,
		integrityScanning: false,
		integrityScanActive: false,
		integrityStopRequested: false,
	};
	/**
	 * Retrieve one required dashboard element by its unique ID.
	 * @param id
	 */
	const byId = function ( id ) {
		return document.getElementById( id );
	};
	const results = byId( 'media-audit-results' );
	if ( ! results ) {
		return;
	}
	const diagnosticsEnabled = MediaAudit.consoleDiagnostics === true;

	/**
	 * Avoid even constructing large diagnostic payloads while logging is off.
	 * @param method
	 */
	function diagnostic( method ) {
		if (
			! diagnosticsEnabled ||
			! window.console ||
			typeof window.console[ method ] !== 'function'
		) {
			return;
		}
		window.console[ method ].apply(
			window.console,
			Array.prototype.slice.call( arguments, 1 )
		);
	}

	/**
	 * Lazily construct console tables only when diagnostics are enabled.
	 * @param factory
	 */
	function diagnosticTable( factory ) {
		if (
			! diagnosticsEnabled ||
			! window.console ||
			typeof window.console.table !== 'function'
		) {
			return;
		}
		window.console.table(
			typeof factory === 'function' ? factory() : factory
		);
	}

	/**
	 * Escape dynamic values before placing them into an HTML template.
	 * @param value
	 */
	function escapeHtml( value ) {
		return String(
			value === null || value === undefined ? '' : value
		).replace( /[&<>'"]/g, function ( character ) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				"'": '&#039;',
				'"': '&quot;',
			}[ character ];
		} );
	}

	/**
	 * Convert the engine's kilobyte values into compact human-readable sizes.
	 * @param kb
	 */
	function formatSize( kb ) {
		const value = Number( kb || 0 );
		if ( value >= 1048576 ) {
			return ( value / 1048576 ).toFixed( 2 ) + ' GB';
		}
		if ( value >= 1024 ) {
			return ( value / 1024 ).toFixed( 2 ) + ' MB';
		}
		return (
			value.toLocaleString( undefined, { maximumFractionDigits: 1 } ) +
			' KB'
		);
	}

	/**
	 * Render a WordPress notice beside the workflow that produced it.
	 * @param message
	 * @param type
	 * @param section
	 */
	function notice( message, type, section ) {
		const target = section
			? document.querySelector(
					'[data-notice-section="' + section + '"]'
			  )
			: document.querySelector(
					'.media-audit-panel.is-active [data-notice-section]'
			  );
		if ( ! target ) {
			return;
		}
		target.innerHTML =
			'<div class="notice notice-' +
			escapeHtml( type || 'info' ) +
			' is-dismissible"><p>' +
			escapeHtml( message ) +
			'</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button></div>';
		target
			.querySelector( '.notice-dismiss' )
			.addEventListener( 'click', function () {
				target.innerHTML = '';
			} );
	}

	/**
	 * Lock interactive controls during scans/actions.
	 * The stop button remains available only while a batched scan is active.
	 * @param busy
	 * @param message
	 * @param showProgress
	 */
	function setBusy( busy, message, showProgress ) {
		state.busy = busy;
		const progress = byId( 'media-audit-progress' );
		progress.hidden = ! busy || showProgress === false;
		progress.querySelector( 'strong' ).textContent = message || '';
		byId( 'media-audit-stop' ).hidden = ! busy || ! state.scanActive;
		byId( 'media-audit-stop' ).disabled = ! busy || ! state.scanActive;
		document
			.querySelectorAll(
				'#media-audit-run-form button, #media-audit-run-form input, #media-audit-clear, [data-file-action], #media-audit-dry-run'
			)
			.forEach( function ( element ) {
				element.disabled = busy;
			} );
	}

	/**
	 * Show an honest indeterminate indicator for one-request filesystem work.
	 * @param id
	 * @param busy
	 * @param message
	 * @param detail
	 * @param percent
	 */
	function setOperationProgress( id, busy, message, detail, percent ) {
		const progress = byId( id );
		if ( ! progress ) {
			return;
		}
		progress.hidden = ! busy;
		progress.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		progress.setAttribute( 'role', 'progressbar' );
		if ( busy ) {
			progress.setAttribute( 'aria-valuetext', message );
		} else {
			progress.removeAttribute( 'aria-valuetext' );
		}
		progress.querySelector( 'strong' ).textContent = busy ? message : '';
		const small = progress.querySelector( 'small' );
		if ( small && detail !== undefined ) {
			small.textContent = detail || '';
		}
		const bar = progress.querySelector( 'i' );
		const determinate = busy && typeof percent === 'number';
		progress.classList.toggle( 'is-determinate', determinate );
		if ( bar ) {
			bar.style.width = determinate
				? Math.max( 0, Math.min( 100, percent ) ) + '%'
				: '';
		}
	}

	/**
	 * Select a specific operation label instead of the generic working state.
	 * @param action
	 * @param allFindings
	 */
	function actionProgressMessage( action, allFindings ) {
		if ( action === 'quarantine' ) {
			return MediaAudit.i18n.quarantining;
		}
		if ( action === 'backup-delete' ) {
			return MediaAudit.i18n.preparingZip;
		}
		if ( action === 'delete' && allFindings ) {
			return MediaAudit.i18n.deletingAllFindings;
		}
		if ( action === 'delete' ) {
			return MediaAudit.i18n.deletingFiles;
		}
		return MediaAudit.i18n.working;
	}

	/**
	 * Run ordinary file actions in bounded requests with real completed progress.
	 * @param action
	 * @param paths
	 * @param dryRun
	 * @param message
	 * @param allFindings
	 */
	function runActionBatches( action, paths, dryRun, message, allFindings ) {
		const batchSize = Math.max(
			1,
			Math.min( 100, Number( MediaAudit.actionBatchSize || 20 ) )
		);
		const delay = Math.max(
			0,
			Math.min( 3000, Number( MediaAudit.actionDelayMs || 0 ) )
		);
		const batches = [];
		if ( action === 'backup-delete' ) {
			batches.push( paths.slice() );
		} else {
			for ( let index = 0; index < paths.length; index += batchSize ) {
				batches.push( paths.slice( index, index + batchSize ) );
			}
		}
		let completed = 0;
		let combinedRows = [];
		let lastResponse = null;
		function next( batchIndex ) {
			if ( batchIndex >= batches.length ) {
				lastResponse.rows = combinedRows;
				return Promise.resolve( lastResponse );
			}
			const batch = batches[ batchIndex ];
			return request( 'media_audit_apply', {
				target_action: action,
				dry_run: dryRun ? '1' : '',
				file_scope: allFindings ? 'all' : 'selected',
				paths: batch,
			} ).then( function ( response ) {
				lastResponse = response;
				combinedRows = combinedRows.concat( response.rows || [] );
				completed += batch.length;
				state.findings = response.findings || state.findings;
				const removed = {};
				( response.removed_paths || [] ).forEach( function ( path ) {
					removed[ path ] = true;
				} );
				if (
					state.findings &&
					Array.isArray( state.findings.stray_rows ) &&
					Object.keys( removed ).length
				) {
					state.findings.stray_rows =
						state.findings.stray_rows.filter( function ( row ) {
							return ! removed[ row.path ];
						} );
				}
				state.stats = response.stats || state.stats;
				const percent = paths.length
					? ( completed / paths.length ) * 100
					: 100;
				setOperationProgress(
					'media-audit-action-progress',
					true,
					message,
					completed.toLocaleString() +
						' / ' +
						paths.length.toLocaleString() +
						' files processed',
					percent
				);
				if ( batchIndex + 1 >= batches.length || ! delay ) {
					return next( batchIndex + 1 );
				}
				return new Promise( function ( resolve ) {
					window.setTimeout( resolve, delay );
				} ).then( function () {
					return next( batchIndex + 1 );
				} );
			} );
		}
		return next( 0 );
	}

	/**
	 * Update deterministic batch progress; this is not an estimated timer.
	 * @param findings
	 * @param message
	 */
	function updateScanProgress( findings, message ) {
		const processed = Number( findings.processed_candidates || 0 );
		const total = Number( findings.total_candidates || 0 );
		const percent = Number( findings.progress_percent || 0 );
		const progress = byId( 'media-audit-progress' );
		progress.querySelector( 'strong' ).textContent =
			( message || MediaAudit.i18n.running ) +
			' ' +
			processed.toLocaleString() +
			' / ' +
			total.toLocaleString() +
			' (' +
			percent +
			'%)';
		const bar = progress.querySelector( 'i' );
		bar.style.animation = 'none';
		bar.style.transform = 'none';
		bar.style.width = percent + '%';
		progress.setAttribute( 'role', 'progressbar' );
		progress.setAttribute( 'aria-valuemin', '0' );
		progress.setAttribute( 'aria-valuemax', '100' );
		progress.setAttribute( 'aria-valuenow', String( percent ) );
		progress.setAttribute(
			'aria-valuetext',
			processed.toLocaleString() +
				' of ' +
				total.toLocaleString() +
				' candidates checked'
		);
	}

	/**
	 * Finalize client state exactly once and invalidate outstanding responses.
	 * @param response
	 * @param noticeType
	 */
	function finishScan( response, noticeType ) {
		state.scanSequence += 1;
		if ( response && response.findings ) {
			state.findings = response.findings;
			state.selected = {};
			renderFindings();
			results.scrollIntoView( { behavior: 'smooth' } );
		}
		if ( response && response.message ) {
			notice( response.message, noticeType || 'success', 'scan' );
		}
		window.clearInterval( state.scanHeartbeat );
		state.scanHeartbeat = null;
		diagnostic(
			'info',
			'[Media Audit] Scan request sequence finished after ' +
				( ( Date.now() - state.scanStarted ) / 1000 ).toFixed( 1 ) +
				's'
		);
		if ( response && response.findings ) {
			diagnosticTable( {
				files_scanned: response.findings.total_files,
				candidates_processed: response.findings.processed_candidates,
				candidates_total: response.findings.total_candidates,
				database_matches: response.findings.db_matched,
				likely_stray: ( response.findings.stray_rows || [] ).length,
				stopped: !! response.findings.stopped,
				duration_seconds: response.findings.duration_seconds,
			} );
		}
		diagnostic( 'groupEnd' );
		state.scanToken = '';
		state.stopRequested = false;
		state.stopInFlight = false;
		state.scanActive = false;
		setBusy( false );
	}

	/**
	 * Persist cancellation on the server.
	 * stopInFlight prevents the click handler and a finishing batch from sending
	 * duplicate stop requests for the same token.
	 * @param sequence
	 */
	function stopCurrentScan( sequence ) {
		if ( ! state.scanToken || state.stopInFlight ) {
			return;
		}
		state.stopInFlight = true;
		byId( 'media-audit-stop' ).disabled = true;
		byId( 'media-audit-progress' ).querySelector( 'strong' ).textContent =
			MediaAudit.i18n.stopping;
		request( 'media_audit_stop', { token: state.scanToken } )
			.then( function ( response ) {
				if ( sequence !== state.scanSequence ) {
					return;
				}
				finishScan( response, 'warning' );
			} )
			.catch( function ( error ) {
				if ( sequence !== state.scanSequence ) {
					return;
				}
				diagnostic(
					'error',
					'[Media Audit] Stop request failed',
					error
				);
				state.stopInFlight = false;
				state.scanActive = false;
				notice( error.message, 'error', 'scan' );
				setBusy( false );
			} );
	}

	/**
	 * Process batches serially so concurrent requests cannot advance one cursor.
	 * @param sequence
	 */
	function runScanStep( sequence ) {
		if ( sequence !== state.scanSequence || state.stopRequested ) {
			stopCurrentScan( sequence );
			return;
		}
		request( 'media_audit_step', { token: state.scanToken } )
			.then( function ( response ) {
				if ( sequence !== state.scanSequence ) {
					return;
				}
				state.findings = response.findings;
				renderFindings();
				updateScanProgress(
					response.findings,
					MediaAudit.i18n.running
				);
				diagnostic( 'info', '[Media Audit] Batch complete', {
					processed: response.findings.processed_candidates,
					total: response.findings.total_candidates,
					percent: response.findings.progress_percent,
					findings: ( response.findings.stray_rows || [] ).length,
				} );
				if ( response.done ) {
					finishScan(
						response,
						response.stopped ? 'warning' : 'success'
					);
				} else {
					runScanStep( sequence );
				}
			} )
			.catch( function ( error ) {
				if ( sequence !== state.scanSequence ) {
					return;
				}
				diagnostic( 'error', '[Media Audit] Scan batch failed', error );
				notice( error.message, 'error', 'scan' );
				window.clearInterval( state.scanHeartbeat );
				diagnostic( 'groupEnd' );
				setBusy( false );
			} );
	}

	/**
	 * Send an authenticated admin-ajax request and normalize WordPress errors.
	 * @param action
	 * @param data
	 */
	function request( action, data ) {
		const body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', MediaAudit.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			const value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );
		return fetch( MediaAudit.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type':
					'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( MediaAudit.i18n.requestFailed );
				} );
			} )
			.then( function ( payload ) {
				if ( ! payload.success ) {
					throw new Error(
						payload.data && payload.data.message
							? payload.data.message
							: MediaAudit.i18n.requestFailed
					);
				}
				return payload.data;
			} );
	}

	/**
	 * Start a named attachment download without replacing the admin document.
	 * @param url
	 * @param filename
	 */
	function startDownload( url, filename ) {
		if ( ! url ) {
			return;
		}
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = filename || 'media-audit-backup.zip';
		link.setAttribute( 'aria-hidden', 'true' );
		link.style.position = 'fixed';
		link.style.width = '1px';
		link.style.height = '1px';
		link.style.opacity = '0';
		link.style.pointerEvents = 'none';
		document.body.appendChild( link );
		link.click();
		window.setTimeout( function () {
			link.remove();
		}, 1000 );
	}

	/** Return the read-only candidate array without duplicating large datasets. */
	function rows() {
		return state.findings && Array.isArray( state.findings.stray_rows )
			? state.findings.stray_rows
			: [];
	}

	/** Apply the client-only search and sort controls to current findings. */
	function matchingRows() {
		const query = byId( 'media-audit-filter' ).value.trim().toLowerCase();
		const sort = byId( 'media-audit-sort' ).value;
		const filtered = rows().filter( function ( row ) {
			return (
				! query ||
				[ row.path, row.reason, row.extension || '' ]
					.join( ' ' )
					.toLowerCase()
					.indexOf( query ) !== -1
			);
		} );
		filtered.sort( function ( a, b ) {
			if ( sort === 'size_desc' ) {
				return Number( b.size_kb ) - Number( a.size_kb );
			}
			if ( sort === 'modified_desc' ) {
				return String( b.modified ).localeCompare(
					String( a.modified )
				);
			}
			if ( sort === 'modified_asc' ) {
				return String( a.modified ).localeCompare(
					String( b.modified )
				);
			}
			return String( a.path ).localeCompare( String( b.path ) );
		} );
		return filtered;
	}

	/** Limit DOM work while retaining the complete matching data in memory. */
	function visibleRows() {
		return matchingRows().slice( 0, state.resultLimit );
	}

	/** Keep selection feedback and select-all state synchronized. */
	function updateSelection() {
		const count = Object.keys( state.selected ).filter( function ( path ) {
			return state.selected[ path ];
		} ).length;
		byId( 'media-audit-selection-count' ).textContent = count
			? count + ' selected'
			: '';
		const visible = visibleRows();
		byId( 'media-audit-select-all' ).checked =
			visible.length > 0 &&
			visible.every( function ( row ) {
				return !! state.selected[ row.path ];
			} );
	}

	/** Render candidate rows; every dynamic value is escaped above. */
	function renderRows() {
		const body = byId( 'media-audit-result-rows' );
		const matching = matchingRows();
		const visible = matching.slice( 0, state.resultLimit );
		body.innerHTML = visible
			.map( function ( row ) {
				const extension = String( row.path )
					.split( '.' )
					.pop()
					.toUpperCase();
				return (
					'<tr><th scope="row" class="check-column"><input type="checkbox" class="media-audit-row-check" data-path="' +
					escapeHtml( row.path ) +
					'" ' +
					( state.selected[ row.path ] ? 'checked' : '' ) +
					' /></th>' +
					'<td><div class="media-audit-file"><span>' +
					escapeHtml( extension ) +
					'</span><code title="' +
					escapeHtml( row.path ) +
					'">' +
					escapeHtml( row.path ) +
					'</code></div></td>' +
					'<td>' +
					escapeHtml( formatSize( row.size_kb ) ) +
					'</td><td>' +
					escapeHtml( row.modified ) +
					'</td><td><span class="media-audit-badge">' +
					escapeHtml( row.reason ) +
					'</span></td></tr>'
				);
			} )
			.join( '' );
		const empty = byId( 'media-audit-empty' );
		empty.hidden = visible.length > 0;
		empty.textContent = rows().length
			? MediaAudit.i18n.noMatches
			: MediaAudit.i18n.noResults;
		body.closest( '.media-audit-table-wrap' ).hidden = visible.length === 0;
		const footer = byId( 'media-audit-table-footer' );
		footer.hidden = matching.length === 0;
		byId( 'media-audit-table-status' ).textContent =
			'Showing ' +
			visible.length.toLocaleString() +
			' of ' +
			matching.length.toLocaleString() +
			' matching files';
		byId( 'media-audit-load-more' ).hidden =
			visible.length >= matching.length;
		body.querySelectorAll( '.media-audit-row-check' ).forEach(
			function ( checkbox ) {
				checkbox.addEventListener( 'change', function () {
					state.selected[ this.dataset.path ] = this.checked;
					updateSelection();
				} );
			}
		);
		updateSelection();
	}

	/** Render full, running, or stopped findings using one stable payload shape. */
	function renderFindings() {
		if ( ! state.findings || ! Object.keys( state.findings ).length ) {
			results.hidden = true;
			return;
		}
		results.hidden = false;
		const findings = state.findings;
		const candidates = rows();
		const inProgress =
			findings.completed === false &&
			! findings.stopped &&
			! findings.limited_out;
		let candidateLabel = 'Likely stray';
		if ( inProgress ) {
			candidateLabel = 'Findings so far';
		} else if ( findings.stopped ) {
			candidateLabel = 'Partial findings';
		}
		const spaceLabel =
			inProgress || findings.stopped ? 'Space so far' : 'Potential space';
		const candidateKb = candidates.reduce( function ( sum, row ) {
			return sum + Number( row.size_kb || 0 );
		}, 0 );
		const stats = [
			[
				'Files scanned',
				Number( findings.total_files || 0 ).toLocaleString(),
			],
			[
				'Attachment matches',
				Number( findings.attachment_matched || 0 ).toLocaleString(),
			],
			[
				'Database matches',
				Number( findings.db_matched || 0 ).toLocaleString(),
			],
			[ candidateLabel, candidates.length.toLocaleString() ],
			[ spaceLabel, formatSize( candidateKb ) ],
		];
		byId( 'media-audit-stats' ).innerHTML = stats
			.map( function ( stat, index ) {
				return (
					'<div class="' +
					( index === 3 ? 'is-alert' : '' ) +
					'"><span>' +
					escapeHtml( stat[ 0 ] ) +
					'</span><strong>' +
					escapeHtml( stat[ 1 ] ) +
					'</strong></div>'
				);
			} )
			.join( '' );
		let meta = 'Scope: ' + ( findings.scope_label || 'uploads' );
		if ( findings.completed_at ) {
			meta += findings.stopped
				? ' · Stopped ' + findings.completed_at
				: ' · Completed ' + findings.completed_at;
		}
		if ( findings.stopped ) {
			meta +=
				' · Partial result: scan stopped at ' +
				Number( findings.progress_percent || 0 ) +
				'%';
		} else if ( findings.completed === false && ! findings.limited_out ) {
			meta +=
				' · Scan in progress: ' +
				Number( findings.progress_percent || 0 ) +
				'%';
		}
		if ( findings.limited_out ) {
			meta += ' · Database check limit reached';
		}
		byId( 'media-audit-run-meta' ).textContent = meta;
		const status = byId( 'media-audit-result-status' );
		let statusClass = 'is-complete';
		let statusLabel = 'Complete';
		if ( inProgress ) {
			statusClass = 'is-live';
			statusLabel = 'Live';
		} else if ( findings.stopped ) {
			statusClass = 'is-partial';
			statusLabel = 'Partial';
		} else if ( findings.limited_out ) {
			statusClass = 'is-partial';
			statusLabel = 'Limited';
		}
		status.className = 'media-audit-status ' + statusClass;
		status.textContent = statusLabel;
		byId( 'media-audit-partial-warning' ).hidden = ! findings.stopped;
		renderRows();
	}

	/**
	 * Render per-file action outcomes, highlighting blocked/failed operations.
	 * @param actionRows
	 */
	function renderActionResults( actionRows ) {
		state.actionRows = actionRows.slice();
		state.actionLimit = uiRowBatchSize;
		renderActionResultRows();
	}

	/** Incrementally render large action reports instead of creating every node. */
	function renderActionResultRows() {
		const target = byId( 'media-audit-action-results' );
		target.hidden = false;
		const displayed = state.actionRows.slice( 0, state.actionLimit );
		target.innerHTML =
			'<div class="media-audit-action-results-head"><h3>Action results</h3><span>Showing ' +
			displayed.length.toLocaleString() +
			' of ' +
			state.actionRows.length.toLocaleString() +
			' files</span></div><div class="media-audit-action-list" tabindex="0" aria-label="Per-file action results">' +
			displayed
				.map( function ( row ) {
					const failed =
						row.action === 'failed' ||
						row.action === 'blocked' ||
						row.action === 'deleted-with-file-errors';
					return (
						'<div class="' +
						( failed ? 'is-failed' : '' ) +
						'"><strong>' +
						escapeHtml( row.action ) +
						'</strong><code>' +
						escapeHtml( row.path ) +
						'</code><span>' +
						escapeHtml( row.message || '' ) +
						'</span></div>'
					);
				} )
				.join( '' ) +
			'</div>' +
			( displayed.length < state.actionRows.length
				? '<button type="button" class="button media-audit-action-load-more" id="media-audit-action-load-more">Load more results</button>'
				: '' );
	}

	/**
	 * Render persistent cleanup impact without treating quarantine as free space.
	 * @param stats
	 */
	function renderImpactStats( stats ) {
		const target = byId( 'media-audit-impact-stats' );
		if ( ! target ) {
			return;
		}
		stats = stats || {};
		const panel = byId( 'media-audit-impact-panel' );
		const hasActivity = [
			'reclaimed_bytes',
			'removed_files',
			'quarantined_files',
			'restored_files',
		].some( function ( key ) {
			return Number( stats[ key ] || 0 ) > 0;
		} );
		if ( panel ) {
			panel.hidden = ! hasActivity;
		}
		if ( ! hasActivity ) {
			target.innerHTML = '';
			return;
		}
		const items = [
			[
				'Space reclaimed',
				formatSize( Number( stats.reclaimed_bytes || 0 ) / 1024 ),
			],
			[
				'Files permanently removed',
				Number( stats.removed_files || 0 ).toLocaleString(),
			],
			[
				'Files moved to quarantine',
				Number( stats.quarantined_files || 0 ).toLocaleString(),
			],
			[
				'Files restored',
				Number( stats.restored_files || 0 ).toLocaleString(),
			],
		];
		target.innerHTML = items
			.map( function ( item, index ) {
				return (
					'<div class="' +
					( index === 0 ? 'is-saved' : '' ) +
					'"><span>' +
					escapeHtml( item[ 0 ] ) +
					'</span><strong>' +
					escapeHtml( item[ 1 ] ) +
					'</strong></div>'
				);
			} )
			.join( '' );
	}

	/**
	 * Render the recoverable quarantine inventory and bind guarded restores.
	 * @param files
	 */
	function renderQuarantine( files ) {
		const target = byId( 'media-audit-quarantine-content' );
		if ( ! target ) {
			return;
		}
		const available = {};
		files.forEach( function ( file ) {
			available[ file.quarantine_path ] = true;
		} );
		Object.keys( state.quarantineSelected ).forEach( function ( path ) {
			if ( ! available[ path ] ) {
				delete state.quarantineSelected[ path ];
			}
		} );
		if ( ! files.length ) {
			target.innerHTML =
				'<div class="media-audit-empty">No files are currently quarantined.</div>';
			updateQuarantineSelection( files );
			return;
		}
		target.innerHTML =
			'<div class="media-audit-quarantine-table"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" data-quarantine-select-all aria-label="Select all quarantined files shown"></td><th>Original uploads path</th><th>Quarantine run</th><th>Size</th><th></th></tr></thead><tbody>' +
			files
				.map( function ( file ) {
					return (
						'<tr><th scope="row" class="check-column"><input type="checkbox" data-quarantine-check="' +
						escapeHtml( file.quarantine_path ) +
						'" ' +
						( state.quarantineSelected[ file.quarantine_path ]
							? 'checked'
							: '' ) +
						'></th><td><code>' +
						escapeHtml( file.path ) +
						'</code></td><td>' +
						escapeHtml( file.run ) +
						'</td><td>' +
						escapeHtml( formatSize( file.size_kb ) ) +
						'</td><td><button type="button" class="button button-small" data-restore-path="' +
						escapeHtml( file.quarantine_path ) +
						'">Restore</button></td></tr>'
					);
				} )
				.join( '' ) +
			'</tbody></table></div>';
		updateQuarantineSelection( files );
	}

	/**
	 * Synchronize quarantine selection count, select-all, and destructive controls.
	 * @param files
	 */
	function updateQuarantineSelection( files ) {
		const selectedCount = Object.keys( state.quarantineSelected ).filter(
			function ( path ) {
				return state.quarantineSelected[ path ];
			}
		).length;
		byId( 'media-audit-delete-quarantine-selected' ).disabled =
			selectedCount === 0;
		byId( 'media-audit-delete-quarantine-all' ).disabled =
			files.length === 0;
		const selectAll = document.querySelector(
			'[data-quarantine-select-all]'
		);
		if ( selectAll ) {
			selectAll.checked =
				files.length > 0 &&
				files.every( function ( file ) {
					return !! state.quarantineSelected[ file.quarantine_path ];
				} );
		}
	}

	/** Refresh quarantine independently from scan findings. */
	function loadQuarantine() {
		const target = byId( 'media-audit-quarantine-content' );
		if ( ! target ) {
			return Promise.resolve();
		}
		target.setAttribute( 'aria-busy', 'true' );
		setOperationProgress(
			'media-audit-quarantine-progress',
			true,
			MediaAudit.i18n.loadingQuarantine
		);
		return request( 'media_audit_list_quarantine', {} )
			.then( function ( response ) {
				renderQuarantine( response.files || [] );
			} )
			.catch( function ( error ) {
				target.innerHTML =
					'<div class="notice notice-error inline"><p>' +
					escapeHtml( error.message ) +
					'</p></div>';
			} )
			.finally( function () {
				target.removeAttribute( 'aria-busy' );
				setOperationProgress(
					'media-audit-quarantine-progress',
					false
				);
			} );
	}

	/** Return server-owned missing-original rows from the latest integrity check. */
	function integrityOriginalRows() {
		return state.integrity &&
			Array.isArray( state.integrity.missing_originals )
			? state.integrity.missing_originals
			: [];
	}

	/** Return report-only generated-file findings. */
	function integrityVariantRows() {
		return state.integrity &&
			Array.isArray( state.integrity.missing_variants )
			? state.integrity.missing_variants
			: [];
	}

	/**
	 * Merge compact batch progress without retransmitting cumulative arrays.
	 * @param progress
	 */
	function mergeIntegrityProgress( progress ) {
		Object.keys( progress || {} ).forEach( function ( key ) {
			state.integrity[ key ] = progress[ key ];
		} );
	}

	/** Keep integrity controls and selection state synchronized. */
	function updateIntegritySelection() {
		const integrityRows = integrityOriginalRows();
		const available = {};
		integrityRows.forEach( function ( row ) {
			available[ String( row.id ) ] = true;
		} );
		Object.keys( state.integritySelected ).forEach( function ( id ) {
			if ( ! available[ id ] ) {
				delete state.integritySelected[ id ];
			}
		} );
		const selected = Object.keys( state.integritySelected ).filter(
			function ( id ) {
				return state.integritySelected[ id ];
			}
		);
		const visible = integrityRows.slice( 0, state.integrityLimit );
		byId( 'media-audit-integrity-selection' ).textContent = selected.length
			? selected.length.toLocaleString() + ' selected'
			: '';
		byId( 'media-audit-integrity-select-all' ).checked =
			visible.length > 0 &&
			visible.every( function ( row ) {
				return !! state.integritySelected[ String( row.id ) ];
			} );
		byId( 'media-audit-integrity-delete-selected' ).disabled =
			state.integrityScanning || selected.length === 0;
		byId( 'media-audit-integrity-delete-all' ).disabled =
			state.integrityScanning || integrityRows.length === 0;
	}

	/** Render a complete or partial Media Library integrity result efficiently. */
	function renderIntegrity() {
		const result = byId( 'media-audit-integrity-results' );
		if (
			! result ||
			! state.integrity ||
			! Object.keys( state.integrity ).length
		) {
			if ( result ) {
				result.hidden = true;
			}
			return;
		}
		result.hidden = false;
		const originals = integrityOriginalRows();
		const variants = integrityVariantRows();
		const missingVariantFiles = variants.reduce( function ( sum, row ) {
			return sum + Number( row.missing_count || 0 );
		}, 0 );
		const stats = [
			[
				'Records checked',
				Number( state.integrity.processed || 0 ).toLocaleString(),
			],
			[ 'Missing originals', originals.length.toLocaleString() ],
			[ 'Affected by missing sizes', variants.length.toLocaleString() ],
			[ 'Generated files absent', missingVariantFiles.toLocaleString() ],
		];
		byId( 'media-audit-integrity-stats' ).innerHTML = stats
			.map( function ( item, index ) {
				return (
					'<div class="' +
					( index === 1 ? 'is-alert' : '' ) +
					'"><span>' +
					escapeHtml( item[ 0 ] ) +
					'</span><strong>' +
					escapeHtml( item[ 1 ] ) +
					'</strong></div>'
				);
			} )
			.join( '' );
		let meta = state.integrity.completed
			? 'Completed ' + ( state.integrity.completed_at || '' )
			: 'Incomplete check';
		meta +=
			' · ' +
			Number( state.integrity.processed || 0 ).toLocaleString() +
			' of ' +
			Number( state.integrity.total || 0 ).toLocaleString() +
			' attachment records checked';
		byId( 'media-audit-integrity-meta' ).textContent = meta;

		const visibleOriginals = originals.slice( 0, state.integrityLimit );
		const originalBody = byId( 'media-audit-integrity-rows' );
		originalBody.innerHTML = visibleOriginals
			.map( function ( row ) {
				const remaining = Number( row.remaining_files || 0 )
					? Number( row.remaining_files ).toLocaleString() +
					  ' (' +
					  formatSize( row.remaining_kb || 0 ) +
					  ')'
					: 'None detected';
				return (
					'<tr><th scope="row" class="check-column"><input type="checkbox" data-integrity-check="' +
					escapeHtml( row.id ) +
					'" ' +
					( state.integritySelected[ String( row.id ) ]
						? 'checked'
						: '' ) +
					'></th><td><strong>' +
					escapeHtml( row.title ) +
					'</strong><small>#' +
					escapeHtml( row.id ) +
					( row.mime ? ' · ' + escapeHtml( row.mime ) : '' ) +
					'</small></td><td><code title="' +
					escapeHtml( row.path ) +
					'">' +
					escapeHtml( row.path ) +
					'</code><small>' +
					escapeHtml( row.reason || '' ) +
					'</small></td><td>' +
					escapeHtml( remaining ) +
					'</td></tr>'
				);
			} )
			.join( '' );
		originalBody.closest( '.media-audit-table-wrap' ).hidden =
			originals.length === 0;
		byId( 'media-audit-integrity-empty' ).hidden = originals.length > 0;
		byId( 'media-audit-integrity-footer' ).hidden = originals.length === 0;
		byId( 'media-audit-integrity-row-status' ).textContent =
			'Showing ' +
			visibleOriginals.length.toLocaleString() +
			' of ' +
			originals.length.toLocaleString() +
			' records';
		byId( 'media-audit-integrity-load-more' ).hidden =
			visibleOriginals.length >= originals.length;

		const visibleVariants = variants.slice(
			0,
			state.integrityVariantLimit
		);
		const variantBody = byId( 'media-audit-integrity-variant-rows' );
		variantBody.innerHTML = visibleVariants
			.map( function ( row ) {
				const names = Array.isArray( row.missing_files )
					? row.missing_files.join( ', ' )
					: '';
				return (
					'<tr><td><strong>' +
					escapeHtml( row.title ) +
					'</strong><small>#' +
					escapeHtml( row.id ) +
					'</small></td><td><code title="' +
					escapeHtml( row.path ) +
					'">' +
					escapeHtml( row.path ) +
					'</code></td><td><span class="media-audit-badge">' +
					escapeHtml( row.missing_count ) +
					' missing</span><small title="' +
					escapeHtml( names ) +
					'">' +
					escapeHtml( names ) +
					'</small></td></tr>'
				);
			} )
			.join( '' );
		variantBody.closest( '.media-audit-table-wrap' ).hidden =
			variants.length === 0;
		byId( 'media-audit-integrity-variants-empty' ).hidden =
			variants.length > 0;
		byId( 'media-audit-integrity-variant-footer' ).hidden =
			variants.length === 0;
		byId( 'media-audit-integrity-variant-status' ).textContent =
			'Showing ' +
			visibleVariants.length.toLocaleString() +
			' of ' +
			variants.length.toLocaleString() +
			' affected attachments';
		byId( 'media-audit-integrity-variant-load-more' ).hidden =
			visibleVariants.length >= variants.length;
		updateIntegritySelection();
	}

	/**
	 * Toggle only the controls owned by the integrity workflow.
	 * @param busy
	 * @param message
	 * @param detail
	 * @param percent
	 */
	function setIntegrityBusy( busy, message, detail, percent ) {
		state.integrityScanning = busy;
		byId( 'media-audit-integrity-run' ).disabled = busy;
		byId( 'media-audit-integrity-stop' ).hidden =
			! busy || ! state.integrityScanActive;
		byId( 'media-audit-integrity-stop' ).disabled =
			! busy || ! state.integrityScanActive;
		setOperationProgress(
			'media-audit-integrity-progress',
			busy,
			message || MediaAudit.i18n.integrityScanning,
			detail,
			percent
		);
		updateIntegritySelection();
	}

	/** Update integrity scan progress from completed server-side records. */
	function updateIntegrityProgress() {
		const processed = Number( state.integrity.processed || 0 );
		const total = Number( state.integrity.total || 0 );
		const percent = Number( state.integrity.progress_percent || 0 );
		setOperationProgress(
			'media-audit-integrity-progress',
			true,
			MediaAudit.i18n.integrityScanning,
			processed.toLocaleString() +
				' / ' +
				total.toLocaleString() +
				' records checked',
			percent
		);
	}

	/** Continue serial integrity batches until complete or stopped by the user. */
	function runIntegrityStep() {
		if ( state.integrityStopRequested ) {
			state.integrityScanActive = false;
			setIntegrityBusy( false );
			notice(
				'Media Library check stopped. Findings completed so far remain available.',
				'warning',
				'integrity'
			);
			return;
		}
		request( 'media_audit_integrity_step', {} )
			.then( function ( response ) {
				mergeIntegrityProgress( response.progress );
				state.integrity.missing_originals =
					integrityOriginalRows().concat(
						response.missing_originals || []
					);
				state.integrity.missing_variants =
					integrityVariantRows().concat(
						response.missing_variants || []
					);
				renderIntegrity();
				updateIntegrityProgress();
				if ( response.done ) {
					state.integrityScanActive = false;
					setIntegrityBusy( false );
					notice( response.message, 'success', 'integrity' );
				} else {
					runIntegrityStep();
				}
			} )
			.catch( function ( error ) {
				state.integrityScanActive = false;
				setIntegrityBusy( false );
				notice( error.message, 'error', 'integrity' );
			} );
	}

	/**
	 * Render bounded per-record outcomes for integrity cleanup.
	 * @param resultRows
	 */
	function renderIntegrityActionResults( resultRows ) {
		const target = byId( 'media-audit-integrity-action-results' );
		target.hidden = false;
		target.innerHTML =
			'<div class="media-audit-action-results-head"><h3>Record cleanup results</h3><span>' +
			resultRows.length.toLocaleString() +
			' processed</span></div><div class="media-audit-action-list" tabindex="0">' +
			resultRows
				.slice( 0, uiRowBatchSize )
				.map( function ( row ) {
					const failed =
						row.action === 'failed' ||
						row.action === 'blocked' ||
						row.action === 'deleted-with-file-errors';
					return (
						'<div class="' +
						( failed ? 'is-failed' : '' ) +
						'"><strong>' +
						escapeHtml( row.action ) +
						'</strong><code>#' +
						escapeHtml( row.id ) +
						' · ' +
						escapeHtml( row.title ) +
						'</code><span>' +
						escapeHtml( row.message || '' ) +
						'</span></div>'
					);
				} )
				.join( '' ) +
			'</div>';
	}

	/**
	 * Delete attachment records in slow-server-friendly, measurable batches.
	 * @param ids
	 * @param deleteAll
	 */
	function deleteIntegrityRecords( ids, deleteAll ) {
		if (
			! ids.length ||
			! window.confirm(
				deleteAll
					? MediaAudit.i18n.integrityDeleteAllConfirm
					: MediaAudit.i18n.integrityDeleteConfirm
			)
		) {
			return;
		}
		const batchSize = Math.max(
			1,
			Math.min( 100, Number( MediaAudit.actionBatchSize || 20 ) )
		);
		const delay = Math.max(
			0,
			Math.min( 3000, Number( MediaAudit.actionDelayMs || 0 ) )
		);
		const batches = [];
		for ( let index = 0; index < ids.length; index += batchSize ) {
			batches.push( ids.slice( index, index + batchSize ) );
		}
		let completed = 0;
		let outcomes = [];
		state.integrityScanActive = false;
		setIntegrityBusy(
			true,
			'Deleting missing-file attachment records…',
			ids.length.toLocaleString() + ' records queued',
			0
		);
		function next( batchIndex ) {
			if ( batchIndex >= batches.length ) {
				return Promise.resolve();
			}
			return request( 'media_audit_integrity_delete', {
				attachment_ids: batches[ batchIndex ],
			} ).then( function ( response ) {
				mergeIntegrityProgress( response.progress );
				const removed = {};
				( response.removed_ids || [] ).forEach( function ( id ) {
					removed[ String( id ) ] = true;
				} );
				state.integrity.missing_originals =
					integrityOriginalRows().filter( function ( row ) {
						return ! removed[ String( row.id ) ];
					} );
				state.integrity.missing_variants =
					integrityVariantRows().filter( function ( row ) {
						return ! removed[ String( row.id ) ];
					} );
				state.stats = response.stats || state.stats;
				outcomes = outcomes.concat( response.rows || [] );
				completed += batches[ batchIndex ].length;
				renderIntegrity();
				renderImpactStats( state.stats );
				setOperationProgress(
					'media-audit-integrity-progress',
					true,
					'Deleting missing-file attachment records…',
					completed.toLocaleString() +
						' / ' +
						ids.length.toLocaleString() +
						' records processed',
					( completed / ids.length ) * 100
				);
				if ( batchIndex + 1 >= batches.length || ! delay ) {
					return next( batchIndex + 1 );
				}
				return new Promise( function ( resolve ) {
					window.setTimeout( resolve, delay );
				} ).then( function () {
					return next( batchIndex + 1 );
				} );
			} );
		}
		next( 0 )
			.then( function () {
				state.integritySelected = {};
				renderIntegrity();
				renderIntegrityActionResults( outcomes );
				notice(
					'Media Library record cleanup complete.',
					'success',
					'integrity'
				);
			} )
			.catch( function ( error ) {
				notice( error.message, 'error', 'integrity' );
			} )
			.finally( function () {
				setIntegrityBusy( false );
			} );
	}

	/**
	 * Activate one dashboard section without changing the page URL or scroll.
	 * @param tabName
	 */
	function activateTab( tabName ) {
		document.querySelectorAll( '[data-tab]' ).forEach( function ( item ) {
			item.classList.toggle(
				'nav-tab-active',
				item.dataset.tab === tabName
			);
		} );
		document
			.querySelectorAll( '[data-panel]' )
			.forEach( function ( panel ) {
				panel.classList.toggle(
					'is-active',
					panel.dataset.panel === tabName
				);
			} );
	}

	// Dashboard tabs are intentionally client-only; settings retain WordPress's
	// native Settings API submit flow and reopen after its redirect.
	document.querySelectorAll( '[data-tab]' ).forEach( function ( tab ) {
		tab.addEventListener( 'click', function () {
			activateTab( tab.dataset.tab );
		} );
	} );
	activateTab( MediaAudit.initialTab || 'scan' );
	if ( MediaAudit.initialTab === 'settings' ) {
		notice( MediaAudit.i18n.settingsSaved, 'success', 'settings' );
	}

	// Integrity checks are independent of stray-file scans and preserve each
	// completed server batch if the administrator stops issuing requests.
	byId( 'media-audit-integrity-run' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			state.integritySelected = {};
			state.integrityLimit = uiRowBatchSize;
			state.integrityVariantLimit = uiRowBatchSize;
			state.integrityStopRequested = false;
			state.integrityScanActive = true;
			setIntegrityBusy(
				true,
				MediaAudit.i18n.integrityScanning,
				'Preparing attachment inventory…',
				0
			);
			request( 'media_audit_integrity_start', {} )
				.then( function ( response ) {
					state.integrity = response.integrity || {};
					renderIntegrity();
					updateIntegrityProgress();
					if ( response.done ) {
						state.integrityScanActive = false;
						setIntegrityBusy( false );
						notice( response.message, 'success', 'integrity' );
					} else {
						runIntegrityStep();
					}
				} )
				.catch( function ( error ) {
					state.integrityScanActive = false;
					setIntegrityBusy( false );
					notice( error.message, 'error', 'integrity' );
				} );
		}
	);

	byId( 'media-audit-integrity-stop' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			state.integrityStopRequested = true;
			this.disabled = true;
			setOperationProgress(
				'media-audit-integrity-progress',
				true,
				'Stopping after the current attachment batch…',
				'Completed findings will remain available.'
			);
		}
	);

	byId( 'media-audit-integrity-rows' ).addEventListener(
		'change',
		function ( event ) {
			const checkbox = event.target.closest( '[data-integrity-check]' );
			if ( ! checkbox ) {
				return;
			}
			state.integritySelected[
				String( checkbox.dataset.integrityCheck )
			] = checkbox.checked;
			updateIntegritySelection();
		}
	);

	byId( 'media-audit-integrity-select-all' ).addEventListener(
		'change',
		function () {
			const checked = this.checked;
			integrityOriginalRows()
				.slice( 0, state.integrityLimit )
				.forEach( function ( row ) {
					state.integritySelected[ String( row.id ) ] = checked;
				} );
			renderIntegrity();
		}
	);

	byId( 'media-audit-integrity-load-more' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			state.integrityLimit += uiRowBatchSize;
			renderIntegrity();
		}
	);

	byId( 'media-audit-integrity-variant-load-more' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			state.integrityVariantLimit += uiRowBatchSize;
			renderIntegrity();
		}
	);

	byId( 'media-audit-integrity-delete-selected' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			const ids = Object.keys( state.integritySelected ).filter(
				function ( id ) {
					return state.integritySelected[ id ];
				}
			);
			deleteIntegrityRecords( ids, false );
		}
	);

	byId( 'media-audit-integrity-delete-all' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			deleteIntegrityRecords(
				integrityOriginalRows().map( function ( row ) {
					return row.id;
				} ),
				true
			);
		}
	);

	// Clipboard API requires a secure context, so retain execCommand as an admin
	// compatibility fallback for local HTTP development sites.
	document
		.querySelectorAll( '[data-copy-command]' )
		.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				const command = button.dataset.copyCommand;
				const originalLabel = button.textContent;
				const showCopied = function () {
					button.textContent = MediaAudit.i18n.copied;
					window.setTimeout( function () {
						button.textContent = originalLabel;
					}, 1400 );
				};
				const fallbackCopy = function () {
					const input = document.createElement( 'textarea' );
					input.value = command;
					input.setAttribute( 'readonly', 'readonly' );
					input.style.position = 'fixed';
					input.style.opacity = '0';
					document.body.appendChild( input );
					input.select();
					const copied = document.execCommand( 'copy' );
					document.body.removeChild( input );
					if ( copied ) {
						showCopied();
					} else {
						notice( MediaAudit.i18n.copyFailed, 'warning' );
					}
				};
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard
						.writeText( command )
						.then( showCopied )
						.catch( fallbackCopy );
				} else {
					fallbackCopy();
				}
			} );
		} );

	// A scan consists of one preparation request followed by serial step requests.
	byId( 'media-audit-run-form' ).addEventListener(
		'submit',
		function ( event ) {
			event.preventDefault();
			const form = new FormData( this );
			const data = {};
			form.forEach( function ( value, key ) {
				data[ key ] = value;
			} );
			state.scanSequence += 1;
			const sequence = state.scanSequence;
			state.scanStarted = Date.now();
			state.scanActive = true;
			state.stopRequested = false;
			state.stopInFlight = false;
			state.scanToken = '';
			state.resultLimit = uiRowBatchSize;
			diagnostic( 'groupCollapsed', '[Media Audit] Scan started' );
			diagnostic( 'info', 'Options', data );
			state.scanHeartbeat = diagnosticsEnabled
				? window.setInterval( function () {
						diagnostic(
							'info',
							'[Media Audit] Scan still running — ' +
								Math.round(
									( Date.now() - state.scanStarted ) / 1000
								) +
								's elapsed'
						);
				  }, 5000 )
				: null;
			setBusy( true, MediaAudit.i18n.running );
			request( 'media_audit_start', data )
				.then( function ( response ) {
					if ( sequence !== state.scanSequence ) {
						return;
					}
					state.scanToken = response.token;
					state.findings = response.findings;
					renderFindings();
					updateScanProgress(
						response.findings,
						MediaAudit.i18n.running
					);
					if ( response.done ) {
						finishScan( response, 'success' );
					} else if ( state.stopRequested ) {
						stopCurrentScan( sequence );
					} else {
						runScanStep( sequence );
					}
				} )
				.catch( function ( error ) {
					if ( sequence !== state.scanSequence ) {
						return;
					}
					diagnostic( 'error', '[Media Audit] Scan failed', error );
					notice( error.message, 'error', 'scan' );
					window.clearInterval( state.scanHeartbeat );
					diagnostic( 'groupEnd' );
					state.scanActive = false;
					setBusy( false );
				} );
		}
	);

	// Stop cannot interrupt PHP mid-request; it marks cancellation and preserves
	// everything committed through the latest server-side batch.
	byId( 'media-audit-stop' ).addEventListener( 'click', function () {
		if ( ! state.busy || state.stopRequested ) {
			return;
		}
		state.stopRequested = true;
		this.disabled = true;
		byId( 'media-audit-progress' ).querySelector( 'strong' ).textContent =
			MediaAudit.i18n.stopping;
		diagnostic(
			'warn',
			'[Media Audit] Stop requested; preserving completed batches.'
		);
		if ( state.scanToken ) {
			stopCurrentScan( state.scanSequence );
		}
	} );

	let filterTimer = null;
	byId( 'media-audit-filter' ).addEventListener( 'input', function () {
		window.clearTimeout( filterTimer );
		filterTimer = window.setTimeout( function () {
			state.resultLimit = uiRowBatchSize;
			renderRows();
		}, 160 );
	} );
	byId( 'media-audit-sort' ).addEventListener( 'change', function () {
		state.resultLimit = uiRowBatchSize;
		renderRows();
	} );
	byId( 'media-audit-load-more' ).addEventListener( 'click', function () {
		state.resultLimit += uiRowBatchSize;
		renderRows();
	} );
	byId( 'media-audit-select-all' ).addEventListener( 'change', function () {
		const checked = this.checked;
		visibleRows().forEach( function ( row ) {
			state.selected[ row.path ] = checked;
		} );
		renderRows();
	} );

	byId( 'media-audit-action-results' ).addEventListener(
		'click',
		function ( event ) {
			if ( ! event.target.closest( '#media-audit-action-load-more' ) ) {
				return;
			}
			state.actionLimit += uiRowBatchSize;
			renderActionResultRows();
		}
	);

	// File actions submit paths for identification only. The server intersects
	// them with saved findings and applies the configured revalidation policy.
	document
		.querySelectorAll( '[data-file-action]' )
		.forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				const allFindings = button.dataset.fileScope === 'all';
				const paths = allFindings
					? rows().map( function ( row ) {
							return row.path;
					  } )
					: Object.keys( state.selected ).filter( function ( path ) {
							return state.selected[ path ];
					  } );
				if ( ! paths.length ) {
					notice( MediaAudit.i18n.selectFiles, 'warning', 'scan' );
					return;
				}
				const action = button.dataset.fileAction;
				const dryRun = byId( 'media-audit-dry-run' ).checked;
				if (
					action === 'delete' &&
					! dryRun &&
					! window.confirm(
						allFindings
							? MediaAudit.i18n.deleteAllFindingsConfirm
							: MediaAudit.i18n.deleteConfirm
					)
				) {
					return;
				}
				if (
					action === 'backup-delete' &&
					! dryRun &&
					! window.confirm( MediaAudit.i18n.backupConfirm )
				) {
					return;
				}
				const viewportY = window.scrollY;
				const actionBar = button.closest( '.media-audit-action-bar' );
				const actionBarTop = actionBar
					? actionBar.getBoundingClientRect().top
					: null;
				button.blur();
				diagnostic( 'info', '[Media Audit] File action started', {
					action,
					dryRun,
					files: paths.length,
				} );
				setBusy( true, MediaAudit.i18n.working, false );
				setOperationProgress(
					'media-audit-action-progress',
					true,
					actionProgressMessage( action, allFindings ),
					paths.length.toLocaleString() +
						' ' +
						MediaAudit.i18n.filesQueued
				);
				window.requestAnimationFrame( function () {
					window.scrollTo( 0, viewportY );
				} );
				runActionBatches(
					action,
					paths,
					dryRun,
					actionProgressMessage( action, allFindings ),
					allFindings
				)
					.then( function ( response ) {
						state.findings = response.findings || state.findings;
						state.selected = {};
						renderFindings();
						renderActionResults( response.rows || [] );
						notice( response.message, 'success', 'scan' );
						state.stats = response.stats || state.stats;
						renderImpactStats( state.stats );
						startDownload(
							response.download_url || '',
							response.download_name || ''
						);
						if ( action === 'quarantine' && ! dryRun ) {
							loadQuarantine();
						}
						diagnosticTable( function () {
							return ( response.rows || [] )
								.slice( 0, 100 )
								.map( function ( row ) {
									return {
										file: row.path,
										action: row.action,
										result: row.message,
									};
								} );
						} );
					} )
					.catch( function ( error ) {
						renderFindings();
						renderImpactStats( state.stats );
						diagnostic(
							'error',
							'[Media Audit] File action failed',
							error
						);
						notice( error.message, 'error', 'scan' );
					} )
					.finally( function () {
						setOperationProgress(
							'media-audit-action-progress',
							false
						);
						setBusy( false );
						window.requestAnimationFrame( function () {
							if ( actionBar && actionBarTop !== null ) {
								window.scrollBy(
									0,
									actionBar.getBoundingClientRect().top -
										actionBarTop
								);
							} else {
								window.scrollTo( 0, viewportY );
							}
						} );
					} );
			} );
		} );

	byId( 'media-audit-refresh-quarantine' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			loadQuarantine();
		}
	);

	byId( 'media-audit-quarantine-content' ).addEventListener(
		'click',
		function ( event ) {
			const selectAll = event.target.closest(
				'[data-quarantine-select-all]'
			);
			if ( selectAll ) {
				document
					.querySelectorAll( '[data-quarantine-check]' )
					.forEach( function ( checkbox ) {
						state.quarantineSelected[
							checkbox.dataset.quarantineCheck
						] = selectAll.checked;
						checkbox.checked = selectAll.checked;
					} );
				const shownFiles = Array.prototype.map.call(
					document.querySelectorAll( '[data-quarantine-check]' ),
					function ( item ) {
						return {
							quarantine_path: item.dataset.quarantineCheck,
						};
					}
				);
				updateQuarantineSelection( shownFiles );
				return;
			}
			const checkbox = event.target.closest( '[data-quarantine-check]' );
			if ( checkbox ) {
				state.quarantineSelected[ checkbox.dataset.quarantineCheck ] =
					checkbox.checked;
				const visibleFiles = Array.prototype.map.call(
					document.querySelectorAll( '[data-quarantine-check]' ),
					function ( item ) {
						return {
							quarantine_path: item.dataset.quarantineCheck,
						};
					}
				);
				updateQuarantineSelection( visibleFiles );
				return;
			}
			const button = event.target.closest( '[data-restore-path]' );
			if ( ! button ) {
				return;
			}
			event.preventDefault();
			const viewportY = window.scrollY;
			button.disabled = true;
			setOperationProgress(
				'media-audit-quarantine-progress',
				true,
				MediaAudit.i18n.restoringFile
			);
			request( 'media_audit_restore', {
				quarantine_path: button.dataset.restorePath,
			} )
				.then( function ( response ) {
					renderQuarantine( response.files || [] );
					state.stats = response.stats || state.stats;
					renderImpactStats( state.stats );
					notice( response.message, 'success', 'quarantine' );
				} )
				.catch( function ( error ) {
					button.disabled = false;
					notice( error.message, 'error', 'quarantine' );
				} )
				.finally( function () {
					setOperationProgress(
						'media-audit-quarantine-progress',
						false
					);
					window.requestAnimationFrame( function () {
						window.scrollTo( 0, viewportY );
					} );
				} );
		}
	);

	/**
	 * Permanently remove selected or all recoverable quarantine entries.
	 * @param deleteAll
	 */
	function deleteQuarantine( deleteAll ) {
		const paths = Object.keys( state.quarantineSelected ).filter(
			function ( path ) {
				return state.quarantineSelected[ path ];
			}
		);
		if ( ! deleteAll && ! paths.length ) {
			return;
		}
		const confirmation = deleteAll
			? MediaAudit.i18n.quarantineDeleteAllConfirm
			: MediaAudit.i18n.quarantineDeleteConfirm;
		if ( ! window.confirm( confirmation ) ) {
			return;
		}
		const selectedButton = byId( 'media-audit-delete-quarantine-selected' );
		const allButton = byId( 'media-audit-delete-quarantine-all' );
		selectedButton.disabled = true;
		allButton.disabled = true;
		setOperationProgress(
			'media-audit-quarantine-progress',
			true,
			MediaAudit.i18n.deletingQuarantine,
			deleteAll
				? 'All recoverable quarantine entries are queued.'
				: paths.length.toLocaleString() + ' files queued.'
		);
		request( 'media_audit_delete_quarantine', {
			delete_all: deleteAll ? '1' : '',
			quarantine_paths: paths,
		} )
			.then( function ( response ) {
				state.quarantineSelected = {};
				renderQuarantine( response.files || [] );
				state.stats = response.stats || state.stats;
				renderImpactStats( state.stats );
				notice( response.message, 'success', 'quarantine' );
			} )
			.catch( function ( error ) {
				notice( error.message, 'error', 'quarantine' );
				return loadQuarantine();
			} )
			.finally( function () {
				setOperationProgress(
					'media-audit-quarantine-progress',
					false
				);
			} );
	}

	byId( 'media-audit-delete-quarantine-selected' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			deleteQuarantine( false );
		}
	);
	byId( 'media-audit-delete-quarantine-all' ).addEventListener(
		'click',
		function ( event ) {
			event.preventDefault();
			deleteQuarantine( true );
		}
	);

	byId( 'media-audit-clear' ).addEventListener( 'click', function () {
		if ( ! window.confirm( MediaAudit.i18n.clearConfirm ) ) {
			return;
		}
		request( 'media_audit_clear', {} )
			.then( function ( response ) {
				state.findings = {};
				state.selected = {};
				results.hidden = true;
				notice( response.message, 'success', 'scan' );
			} )
			.catch( function ( error ) {
				notice( error.message, 'error', 'scan' );
			} );
	} );

	renderFindings();
	renderIntegrity();
	renderImpactStats( state.stats );
	loadQuarantine();
} )();
