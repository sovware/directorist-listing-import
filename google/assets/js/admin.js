/**
 * Directorist – Google Business Importer
 * Admin JavaScript — search → preview checklist → selective AJAX import
 *
 * Flow:
 *   1. Form submit  → POST dlig_start_import → receive queue_id + annotated place list.
 *   2. Render checklist: each place as a checkbox, duplicates pre-deselected (unless
 *      "Update existing" is checked), "Import N Selected" button.
 *   3. User adjusts selection → clicks "Import N Selected".
 *   4. Loop: POST dlig_import_place for each selected queue index.
 *   5. POST dlig_finish_import → log the run server-side.
 *   6. Render per-listing results table + summary notices.
 *
 * All user-facing strings come from dgbiAjax.i18n (wp_localize_script).
 */
jQuery( function ( $ ) {
	'use strict';

	var $mappingSelects = $( '.dgbi-field-map-select' );
	var $form      = $( '#dgbi-import-form' );
	var $submit    = $form.find( '[name="dgbi_submit"]' );
	var $checklist = $( '#dgbi-checklist' );
	var $progress  = $( '#dgbi-progress' );
	var $bar       = $progress.find( '.dgbi-progress-bar-fill' );
	var $track     = $progress.find( '.dgbi-progress-track' );
	var $status    = $progress.find( '.dgbi-progress-status' );
	var $results   = $( '#dgbi-import-results' );

	initFieldMappingUniqueness();

	if ( ! $form.length ) {
		return;
	}

	var importRunning = false;

	function initFieldMappingUniqueness() {
		if ( ! $mappingSelects.length ) {
			return;
		}

		function syncFieldMapOptions() {
			var selected = {};

			$mappingSelects.each( function () {
				var $select = $( this );
				var value   = $select.val();

				if ( value && value !== 'skip' ) {
					if ( selected[ value ] ) {
						$select.val( 'skip' );
						value = 'skip';
					} else {
						selected[ value ] = true;
					}
				}
			} );

			$mappingSelects.each( function () {
				var currentValue = $( this ).val();

				$( this ).find( 'option' ).each( function () {
					var optionValue = this.value;
					var unavailable = optionValue && optionValue !== 'skip' && selected[ optionValue ] && optionValue !== currentValue;

					this.disabled = !! unavailable;
					this.hidden   = !! unavailable;
				} );
			} );
		}

		$mappingSelects.on( 'change', syncFieldMapOptions );
		syncFieldMapOptions();
	}

	// ── Warn before navigating away mid-import ────────────────────────────────
	$( window ).on( 'beforeunload', function () {
		if ( importRunning ) {
			return dgbiAjax.i18n.confirm_navigate;
		}
	} );

	// ── Form submit → search ──────────────────────────────────────────────────
	$form.on( 'submit', function ( e ) {
		e.preventDefault();

		if ( ! $.trim( $form.find( '[name="keyword"]' ).val() ) ) {
			alert( dgbiAjax.i18n.keyword_required );
			return;
		}

		if ( ! $.trim( $form.find( '[name="location"]' ).val() ) ) {
			alert( dgbiAjax.i18n.location_required );
			return;
		}

		runSearch();
	} );

	// ── Step 1: search ────────────────────────────────────────────────────────
	function runSearch() {
		$submit.prop( 'disabled', true );
		$checklist.attr( 'hidden', '' ).empty();
		$results.empty();
		$progress.removeAttr( 'hidden' );
		setProgress( 0, dgbiAjax.i18n.searching );

		var formData = $form.serializeArray();
		formData.push( { name: 'action', value: 'dlig_start_import' } );
		formData.push( { name: 'nonce',  value: dgbiAjax.nonce } );

		$.post( dgbiAjax.ajaxUrl, formData )
			.done( function ( response ) {
				$progress.attr( 'hidden', '' );

				if ( ! response.success ) {
					showError( response.data && response.data.message
						? response.data.message
						: dgbiAjax.i18n.search_failed );
					$submit.prop( 'disabled', false );
					return;
				}

				var data = response.data;
				if ( ! data.total ) {
					showError( dgbiAjax.i18n.no_results );
					$submit.prop( 'disabled', false );
					return;
				}

				renderChecklist( data.queue_id, data.places );
				$submit.prop( 'disabled', false );
			} )
			.fail( function () {
				$progress.attr( 'hidden', '' );
				showError( dgbiAjax.i18n.ajax_error );
				$submit.prop( 'disabled', false );
			} );
	}

	// ── Step 2: render checklist ──────────────────────────────────────────────
	function renderChecklist( queueId, places ) {
		var updateMode = $form.find( '[name="update_existing"]' ).is( ':checked' );
		var total      = places.length;

		var html = '<div class="dgbi-checklist-wrap">';

		// Header
		html += '<div class="dgbi-checklist-header">';
		html += '<p class="dgbi-checklist-heading">'
			+ escHtml( sprintf( dgbiAjax.i18n.preview_heading, total ) )
			+ '</p>';
		html += '<div class="dgbi-checklist-controls">'
			+ '<a href="#" id="dgbi-select-all">' + escHtml( dgbiAjax.i18n.select_all ) + '</a>'
			+ ' &nbsp;|&nbsp; '
			+ '<a href="#" id="dgbi-deselect-all">' + escHtml( dgbiAjax.i18n.deselect_all ) + '</a>'
			+ '</div>';
		html += '</div>';

		// Place list
		html += '<ul class="dgbi-place-list">';
		$.each( places, function ( i, place ) {
			var isDup     = place.is_duplicate;
			var checked   = ( ! isDup || updateMode ) ? ' checked' : '';
			var badgeHtml = isDup
				? '<span class="dgbi-badge dgbi-badge-dup">' + escHtml( dgbiAjax.i18n.already_imported ) + '</span>'
				: '';

			html += '<li>'
				+ '<label>'
				+ '<input type="checkbox" class="dgbi-place-check" data-index="' + i + '"' + checked + '>'
				+ ' <span class="dgbi-place-name">' + escHtml( place.name ) + '</span>'
				+ badgeHtml
				+ '</label>'
				+ '</li>';
		} );
		html += '</ul>';

		// Footer with Import button
		var initialCount = $form.find( '[name="update_existing"]' ).is( ':checked' )
			? total
			: places.filter( function ( p ) { return ! p.is_duplicate; } ).length;
		initialCount = total; // count all checked by default after above render

		html += '<div class="dgbi-checklist-footer">'
			+ '<button type="button" id="dgbi-import-btn" class="button button-primary" data-queue="' + escHtml( queueId ) + '">'
			+ escHtml( sprintf( dgbiAjax.i18n.import_selected, countChecked() ) )
			+ '</button>'
			+ '</div>';

		html += '</div>';

		$checklist.html( html ).removeAttr( 'hidden' );

		// Recount after render (checkboxes are in DOM now)
		updateImportBtnLabel();

		// ── Checklist event handlers ──────────────────────────────────────────
		$checklist.on( 'change', '.dgbi-place-check', function () {
			updateImportBtnLabel();
		} );

		$checklist.on( 'click', '#dgbi-select-all', function ( e ) {
			e.preventDefault();
			$checklist.find( '.dgbi-place-check' ).prop( 'checked', true );
			updateImportBtnLabel();
		} );

		$checklist.on( 'click', '#dgbi-deselect-all', function ( e ) {
			e.preventDefault();
			$checklist.find( '.dgbi-place-check' ).prop( 'checked', false );
			updateImportBtnLabel();
		} );

		$checklist.on( 'click', '#dgbi-import-btn', function () {
			var selectedIndices = [];
			$checklist.find( '.dgbi-place-check:checked' ).each( function () {
				selectedIndices.push( parseInt( $( this ).data( 'index' ), 10 ) );
			} );

			if ( ! selectedIndices.length ) {
				alert( dgbiAjax.i18n.none_selected );
				return;
			}

			$checklist.attr( 'hidden', '' );
			runImportLoop( queueId, places, selectedIndices );
		} );
	}

	function countChecked() {
		return $checklist.find( '.dgbi-place-check:checked' ).length;
	}

	function updateImportBtnLabel() {
		var n = countChecked();
		$checklist.find( '#dgbi-import-btn' )
			.text( sprintf( dgbiAjax.i18n.import_selected, n ) )
			.prop( 'disabled', n === 0 );
	}

	// ── Step 3: import loop ───────────────────────────────────────────────────
	function runImportLoop( queueId, places, selectedIndices ) {
		importRunning = true;
		$submit.prop( 'disabled', true );
		$results.empty();

		var total          = selectedIndices.length;
		var current        = 0;
		var imported       = 0;
		var updated        = 0;
		var skipped        = 0;
		var reviews        = 0;
		var reviewsCreated = 0;
		var descriptions   = 0;
		var errors         = [];
		var perPlace       = [];  // per-listing result rows for the results table

		function importNext() {
			if ( current >= total ) {
				finishImport( queueId, imported, updated, skipped, reviews, reviewsCreated, descriptions, errors, perPlace );
				return;
			}

			var queueIndex = selectedIndices[ current ];
			var place      = places[ queueIndex ] || {};
			var placeName  = place.name || ( '#' + ( queueIndex + 1 ) );

			setProgress(
				Math.round( ( current / total ) * 100 ),
				sprintf( dgbiAjax.i18n.importing, current + 1, total, placeName )
			);
			$progress.removeAttr( 'hidden' );

			$.post( dgbiAjax.ajaxUrl, {
				action:      'dlig_import_place',
				nonce:       dgbiAjax.nonce,
				queue_id:    queueId,
				place_index: queueIndex,
			} )
				.done( function ( response ) {
					var row = { name: placeName, status: 'ok', note: '' };

					if ( response.success && response.data ) {
						var d = response.data;
						imported       += d.imported        || 0;
						updated        += d.updated         || 0;
						skipped        += d.skipped         || 0;
						reviews        += d.reviews         || 0;
						reviewsCreated += d.reviews_created || 0;
						descriptions   += d.descriptions    || 0;

						if ( d.skipped ) {
							row.status = 'skipped';
						} else if ( d.updated ) {
							row.status = 'updated';
						} else if ( d.imported ) {
							row.status = 'imported';
						}

						if ( d.errors && d.errors.length ) {
							row.status = 'error';
							$.each( d.errors, function ( i, msg ) {
								errors.push( placeName + ': ' + msg );
							} );
							row.note = d.errors.join( '; ' );
						}
					} else {
						var errMsg = ( response.data && response.data.message )
							? response.data.message
							: dgbiAjax.i18n.ajax_error;
						row.status = 'error';
						row.note   = errMsg;
						errors.push( placeName + ': ' + errMsg );
					}

					perPlace.push( row );
					current++;
					importNext();
				} )
				.fail( function () {
					var msg = dgbiAjax.i18n.ajax_error;
					perPlace.push( { name: placeName, status: 'error', note: msg } );
					errors.push( placeName + ': ' + msg );
					current++;
					importNext();
				} );
		}

		importNext();
	}

	// ── Step 4: finish ────────────────────────────────────────────────────────
	function finishImport( queueId, imported, updated, skipped, reviews, reviewsCreated, descriptions, errors, perPlace ) {
		setProgress( 99, dgbiAjax.i18n.finishing );

		$.post( dgbiAjax.ajaxUrl, {
			action:          'dlig_finish_import',
			nonce:           dgbiAjax.nonce,
			queue_id:        queueId,
			imported:        imported,
			updated:         updated,
			skipped:         skipped,
			reviews:         reviews,
			reviews_created: reviewsCreated,
			descriptions:    descriptions,
			errors:          JSON.stringify( errors ),
		} )
			.always( function () {
				setProgress( 100, '' );
				renderResults( imported, updated, skipped, reviewsCreated, descriptions, errors, perPlace );
				resetUI();
			} );
	}

	// ── UI helpers ────────────────────────────────────────────────────────────
	function setProgress( pct, text ) {
		$bar.css( 'width', pct + '%' );
		$track.attr( 'aria-valuenow', pct );
		$status.text( text );
	}

	function renderResults( imported, updated, skipped, reviewsCreated, descriptions, errors, perPlace ) {
		var html = '';

		// Summary notices
		var resultClass = ( imported + updated ) > 0 ? 'notice-success' : 'notice-warning';
		html += '<div class="notice ' + resultClass + ' is-dismissible"><p>'
			+ escHtml( sprintf( dgbiAjax.i18n.done, imported, skipped ) )
			+ '</p></div>';

		if ( updated > 0 ) {
			html += '<div class="notice notice-info is-dismissible"><p>'
				+ escHtml( sprintf( dgbiAjax.i18n.updated, updated ) )
				+ '</p></div>';
		}
		if ( reviewsCreated > 0 ) {
			html += '<div class="notice notice-info is-dismissible"><p>'
				+ escHtml( sprintf( dgbiAjax.i18n.reviews, reviewsCreated ) )
				+ '</p></div>';
		}
		if ( descriptions > 0 ) {
			html += '<div class="notice notice-info is-dismissible"><p>'
				+ escHtml( sprintf( dgbiAjax.i18n.descriptions, descriptions ) )
				+ '</p></div>';
		}
		if ( errors.length ) {
			html += '<div class="notice notice-warning is-dismissible"><p>'
				+ escHtml( dgbiAjax.i18n.errors_heading )
				+ '</p><ul>';
			$.each( errors, function ( i, msg ) {
				html += '<li>' + escHtml( msg ) + '</li>';
			} );
			html += '</ul></div>';
		}

		// Per-listing results table
		if ( perPlace.length ) {
			var statusLabels = { imported: 'Imported', updated: 'Updated', skipped: 'Skipped', error: 'Error', ok: '—' };
			html += '<table class="widefat striped dgbi-results-table"><thead><tr>'
				+ '<th>Listing</th><th>Result</th><th>Note</th>'
				+ '</tr></thead><tbody>';
			$.each( perPlace, function ( i, row ) {
				html += '<tr>'
					+ '<td>' + escHtml( row.name ) + '</td>'
					+ '<td><span class="dgbi-row-status dgbi-status-' + row.status + '">'
					+ escHtml( statusLabels[ row.status ] || row.status )
					+ '</span></td>'
					+ '<td>' + escHtml( row.note ) + '</td>'
					+ '</tr>';
			} );
			html += '</tbody></table>';
		}

		$results.html( html );
	}

	function showError( message ) {
		$results.html( '<div class="notice notice-error"><p>' + escHtml( message ) + '</p></div>' );
	}

	function resetUI() {
		importRunning = false;
		$submit.prop( 'disabled', false );
		setTimeout( function () {
			$progress.attr( 'hidden', '' );
			setProgress( 0, '' );
		}, 1400 );
	}

	// ── Tiny utilities ────────────────────────────────────────────────────────
	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return template.replace( /%(\d+)\$[sd]/g, function ( match, pos ) {
			return args[ parseInt( pos, 10 ) - 1 ];
		} ).replace( /%d/g, function () {
			return args.shift();
		} ).replace( /%s/g, function () {
			return args.shift();
		} );
	}

	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}
} );
