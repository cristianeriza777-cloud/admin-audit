<?php
/**
 * log.php — Trazabilidad y acceso al historial de acciones.
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Obtener el log completo de acciones, ordenado del más reciente al más antiguo.
 *
 * @param int $limit  Máximo de entradas a devolver. 0 = todas.
 * @return array
 */
function peaa_get_log( int $limit = 0 ): array {
    $log = get_option( 'peaa_action_log', [] );
    if ( ! is_array( $log ) ) {
        return [];
    }

    // Más recientes primero
    $log = array_reverse( $log );

    if ( $limit > 0 ) {
        $log = array_slice( $log, 0, $limit );
    }

    return $log;
}

/**
 * Etiquetas legibles para cada acción en el log.
 *
 * @param string $action
 * @return string
 */
function peaa_action_label( string $action ): string {
    $labels = [
        'trust'   => 'Marcado como confiable',
        'degrade' => 'Degradado a suscriptor',
        'block'   => 'Bloqueado',
        'delete'  => 'Eliminado',
        'revert'  => 'Revertido',
    ];
    return $labels[ $action ] ?? $action;
}

/**
 * CSS class para cada acción (para colorear la UI).
 *
 * @param string $action
 * @return string
 */
function peaa_action_class( string $action ): string {
    $classes = [
        'trust'   => 'peaa-action--trust',
        'degrade' => 'peaa-action--degrade',
        'block'   => 'peaa-action--block',
        'delete'  => 'peaa-action--delete',
    ];
    return $classes[ $action ] ?? '';
}



/**
 * Obtener usuarios gestionados con acción activa para mostrarlos aunque ya no sean admins.
 *
 * @return array[]
 */
function peaa_get_managed_users(): array {
    $items = [];

    foreach ( peaa_get_managed_user_ids() as $uid ) {
        $state = peaa_get_managed_state( $uid );
        if ( ! $state ) {
            continue;
        }

        $user = get_userdata( $uid );
        $action = $state['action'] ?? '';

        $items[ $uid ] = [
            'user_id'        => $uid,
            'login'          => $user ? $user->user_login : 'Usuario no disponible',
            'email'          => $user ? $user->user_email : '—',
            'registered'     => $user ? $user->user_registered : '',
            'roles'          => $user ? (array) $user->roles : [],
            'exists'         => (bool) $user,
            'action'         => $action,
            'action_label'   => peaa_action_label( $action ),
            'action_class'   => peaa_action_class( $action ),
            'action_date'    => $state['date'] ?? '',
            'executor'       => $state['executor'] ?? '—',
            'revertible'     => (bool) $user && $action !== 'delete',
        ];
    }

    if ( ! empty( $items ) ) {
        usort( $items, static function ( array $a, array $b ): int {
            return strcmp( (string) $b['action_date'], (string) $a['action_date'] );
        } );
        return array_values( $items );
    }

    // Fallback legacy: reconstruir desde el log si aún no existe metadata persistente.
    $log = get_option( 'peaa_action_log', [] );
    if ( ! is_array( $log ) ) {
        return [];
    }

    foreach ( array_reverse( $log ) as $entry ) {
        if ( empty( $entry['user_id'] ) || ! empty( $entry['reversed'] ) ) {
            continue;
        }

        $uid = (int) $entry['user_id'];
        if ( isset( $items[ $uid ] ) ) {
            continue;
        }

        $user = get_userdata( $uid );
        $action = $entry['action'] ?? '';
        $items[ $uid ] = [
            'user_id'        => $uid,
            'login'          => $entry['user_login'] ?? ( $user ? $user->user_login : 'Usuario no disponible' ),
            'email'          => $user ? $user->user_email : '—',
            'registered'     => $user ? $user->user_registered : '',
            'roles'          => $user ? (array) $user->roles : [],
            'exists'         => (bool) $user,
            'action'         => $action,
            'action_label'   => peaa_action_label( $action ),
            'action_class'   => peaa_action_class( $action ),
            'action_date'    => $entry['date'] ?? '',
            'executor'       => $entry['executor'] ?? '—',
            'revertible'     => (bool) $user && $action !== 'delete',
        ];
    }

    return array_values( $items );
}

/**
 * Limpiar el log (máximo 100 entradas para no inflar wp_options).
 * Se llama automáticamente después de cada escritura.
 */
function peaa_trim_log(): void {
    $log = get_option( 'peaa_action_log', [] );
    if ( count( $log ) > 100 ) {
        $log = array_slice( $log, -100 );
        update_option( 'peaa_action_log', $log );
    }
}

/**
 * Obtener estadísticas resumidas del log.
 *
 * @return array
 */
function peaa_log_stats(): array {
    $log = get_option( 'peaa_action_log', [] );

    $stats = [
        'total'    => count( $log ),
        'active'   => 0,
        'reversed' => 0,
        'by_action'=> [],
    ];

    foreach ( $log as $entry ) {
        $action = $entry['action'] ?? 'unknown';

        if ( $entry['reversed'] ) {
            $stats['reversed']++;
        } else {
            $stats['active']++;
        }

        $stats['by_action'][ $action ] = ( $stats['by_action'][ $action ] ?? 0 ) + 1;
    }

    return $stats;
}
