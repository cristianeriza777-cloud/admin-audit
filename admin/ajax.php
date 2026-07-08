<?php
/**
 * ajax.php — Handlers AJAX.
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ejecutar una acción de remediación sobre un usuario.
 */
function peaa_ajax_run_action(): void {
    if ( ! check_ajax_referer( 'peaa_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Verificación de seguridad fallida. Recarga la página.' ], 403 );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'No tienes permisos para ejecutar esta acción.' ], 403 );
    }

    $user_id = isset( $_POST['user_id'] )    ? (int) $_POST['user_id'] : 0;
    $action  = isset( $_POST['user_action'] ) ? sanitize_text_field( $_POST['user_action'] ) : '';

    if ( ! $user_id || ! $action ) {
        wp_send_json_error( [ 'message' => 'Parámetros incompletos.' ], 400 );
    }

    $result = peaa_run_action( $user_id, $action );
    peaa_trim_log();

    if ( $result['ok'] ) {
        wp_send_json_success( [
            'message' => $result['message'],
            'user_id' => $user_id,
            'action'  => $action,
        ] );
    } else {
        wp_send_json_error( [ 'message' => $result['error'] ?? 'Error desconocido.' ], 400 );
    }
}

/**
 * Ejecutar escaneo y devolver HTML renderizado para refrescar el panel.
 */
function peaa_ajax_scan(): void {
    if ( ! check_ajax_referer( 'peaa_action', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'Verificación de seguridad fallida.' ], 403 );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
    }

    $data     = peaa_scan();
    $vis_data = peaa_scan_visibility();

    ob_start();
    peaa_render_results( $data, $vis_data );
    $html = ob_get_clean();

    wp_send_json_success( [
        'html'    => $html,
        'ts'      => current_time( 'd/m/Y H:i:s' ),
        'summary' => $data['summary'],
    ] );
}
