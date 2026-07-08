/**
 * PlusExpert Admin Audit — script.js
 * Animación de escaneo + acciones de remediación vía AJAX.
 */
/* global peaaConfig, jQuery */
( function ( $ ) {
    'use strict';

    var cfg   = peaaConfig;
    var ajax  = cfg.ajaxUrl;
    var nonce = cfg.nonce;
    var i18n  = cfg.i18n;

    var SCAN_STEPS = [
        i18n.scan_step1, i18n.scan_step2, i18n.scan_step3,
        i18n.scan_step4, i18n.scan_step5
    ];
    var ANIM_DURATION = 2800;
    var STEP_DURATION = ANIM_DURATION / SCAN_STEPS.length;

    function showNotice( message, type ) {
        var $notice = $( '#peaa-notice' );
        $notice.removeClass( 'peaa-notice--success peaa-notice--error' )
               .addClass( 'peaa-notice--' + type ).html( message ).show();
        $( 'html, body' ).animate( { scrollTop: $notice.offset().top - 60 }, 200 );
        if ( type === 'success' ) {
            setTimeout( function () { $notice.fadeOut( 400 ); }, 6000 );
        }
    }

    function runScanAnimation() {
        var $step  = $( '#peaa-scan-step' );
        var $bar   = $( '#peaa-scan-bar-fill' );
        var $ring  = $( '#peaa-ring-progress' );
        var $log   = $( '.peaa-scanner__log' );
        var CIRCUM = 163.4;

        $bar.css( 'width', '0%' );
        $ring.css( 'stroke-dashoffset', CIRCUM );
        $log.empty();

        var currentStep = 0;

        function advanceStep() {
            if ( currentStep >= SCAN_STEPS.length ) return;
            var label    = SCAN_STEPS[ currentStep ];
            var progress = ( ( currentStep + 1 ) / SCAN_STEPS.length ) * 100;
            var offset   = CIRCUM - ( CIRCUM * ( currentStep + 1 ) / SCAN_STEPS.length );

            $step.text( label );
            $bar.css( 'width', progress + '%' );
            $ring.css( 'stroke-dashoffset', offset );

            if ( currentStep > 0 ) {
                var $item = $( '<div class="peaa-scanner__log-item"></div>' );
                $item.append( $( '<span class="dashicons dashicons-yes"></span>' ) );
                $item.append( document.createTextNode( ' ' + SCAN_STEPS[ currentStep - 1 ] ) );
                $log.append( $item );
            }
            currentStep++;
        }

        advanceStep();
        var interval = setInterval( function () {
            advanceStep();
            if ( currentStep > SCAN_STEPS.length ) clearInterval( interval );
        }, STEP_DURATION );

        return new $.Deferred( function ( dfd ) {
            setTimeout( function () { dfd.resolve(); }, ANIM_DURATION );
        } ).promise();
    }

    function doScan() {
        $( '#peaa-scan-idle' ).fadeOut( 150, function () {
            $( '#peaa-scan-loading' ).fadeIn( 150 );
        } );

        var animDfd = runScanAnimation();
        var ajaxDfd = $.ajax( {
            url   : ajax,
            method: 'POST',
            data  : { action: 'peaa_do_scan', nonce: nonce }
        } );

        $.when( animDfd, ajaxDfd ).done( function ( animVal, ajaxArgs ) {
            var res = ajaxArgs[0];
            if ( res && res.success ) {
                var data = res.data;
                if ( data.ts ) $( '#peaa-last-ts' ).text( data.ts );
                var $results = $( '#peaa-results' );
                $results.html( data.html );
                $( '#peaa-scan-loading' ).fadeOut( 200, function () {
                    $results.fadeIn( 300 );
                } );

                var summary = data.summary || {};
                if ( parseInt( summary.pending_suspicious || 0, 10 ) > 0 || parseInt( summary.total_suspicious || 0, 10 ) > 0 || parseInt( summary.hidden_count || 0, 10 ) > 0 ) {
                    showNotice( '⚠ ' + i18n.scan_done_warn, 'success' );
                } else {
                    showNotice( '✓ ' + i18n.scan_done_clean, 'success' );
                }
            } else {
                var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Error en el escaneo.';
                $( '#peaa-scan-loading' ).fadeOut( 200, function () {
                    $( '#peaa-scan-idle' ).fadeIn( 200 );
                } );
                showNotice( '✗ ' + msg, 'error' );
            }
        } ).fail( function () {
            $( '#peaa-scan-loading' ).fadeOut( 200, function () {
                $( '#peaa-scan-idle' ).fadeIn( 200 );
            } );
            showNotice( '✗ No se pudo conectar con el servidor.', 'error' );
        } );
    }

    function runAction( uid, action, $btn, login ) {
        var confirmMessages = {
            trust  : i18n.confirm_trust,
            degrade: i18n.confirm_degrade,
            block  : i18n.confirm_block,
            'delete': i18n.confirm_delete,
            revert : i18n.confirm_revert
        };
        var confirmMsg = confirmMessages[ action ] || '¿Confirmas esta acción?';
        if ( ! window.confirm( confirmMsg ) ) return;

        var origHtml = $btn.html();
        $btn.prop( 'disabled', true ).text( i18n.loading );
        $( '#peaa-row-' + uid + ' .peaa-btn' ).prop( 'disabled', true );

        $.ajax( {
            url   : ajax,
            method: 'POST',
            data  : { action: 'peaa_run_action', nonce: nonce, user_id: uid, user_action: action },
            success: function ( res ) {
                if ( res.success ) {
                    showNotice( '✓ ' + res.data.message, 'success' );
                    setTimeout( function () {
                        doScan();
                    }, 700 );
                } else {
                    var msg = ( res.data && res.data.message ) ? res.data.message : i18n.error_generic;
                    showNotice( '✗ ' + msg, 'error' );
                    $( '#peaa-row-' + uid + ' .peaa-btn' ).prop( 'disabled', false );
                    $btn.html( origHtml );
                }
            },
            error: function () {
                showNotice( '✗ ' + i18n.error_generic, 'error' );
                $( '#peaa-row-' + uid + ' .peaa-btn' ).prop( 'disabled', false );
                $btn.html( origHtml );
            }
        } );
    }

    $( function () {
        $( document ).on( 'click', '#peaa-btn-scan', function () {
            var $results = $( '#peaa-results' );
            if ( $results.is( ':visible' ) ) {
                $results.fadeOut( 150, function () {
                    $( '#peaa-scan-idle' ).fadeIn( 150, function () { doScan(); } );
                } );
            } else {
                doScan();
            }
        } );

        $( document ).on( 'click', '.peaa-btn[data-action]', function ( e ) {
            e.preventDefault();
            var $btn   = $( this );
            var uid    = parseInt( $btn.data( 'uid' ), 10 );
            var action = $btn.data( 'action' );
            var login  = $btn.data( 'login' ) || 'este usuario';
            if ( ! uid || ! action ) return;
            runAction( uid, action, $btn, login );
        } );

        $( document ).on( 'click', '.peaa-notice', function () {
            $( this ).fadeOut( 200 );
        } );
    } );

} )( jQuery );
