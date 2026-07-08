<?php
/**
 * actions.php — Acciones de remediación sobre usuarios.
 *
 * Adaptado desde plusexpert-agent/includes/user_actions.php.
 * Desacoplado del sistema REST Audit. Opera directamente en wp-admin.
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * Persistir el estado gestionado de un usuario para reconstruirlo incluso tras reinstalar el plugin.
 */
function peaa_set_managed_state( int $user_id, string $action, array $payload = [] ): void {
    update_user_meta( $user_id, 'peaa_managed_status', $action );
    update_user_meta( $user_id, 'peaa_last_action', $action );
    update_user_meta( $user_id, 'peaa_action_date', current_time( 'Y-m-d H:i:s' ) );
    update_user_meta( $user_id, 'peaa_executor', wp_get_current_user()->user_login );

    if ( isset( $payload['original_roles'] ) ) {
        update_user_meta( $user_id, 'peaa_original_roles', array_values( (array) $payload['original_roles'] ) );
    }
}

/**
 * Limpiar el estado gestionado persistente de un usuario.
 */
function peaa_clear_managed_state( int $user_id ): void {
    delete_user_meta( $user_id, 'peaa_managed_status' );
    delete_user_meta( $user_id, 'peaa_last_action' );
    delete_user_meta( $user_id, 'peaa_action_date' );
    delete_user_meta( $user_id, 'peaa_executor' );
    delete_user_meta( $user_id, 'peaa_original_roles' );
}

/**
 * Obtener el estado gestionado persistente de un usuario.
 */
function peaa_get_managed_state( int $user_id ): ?array {
    $status = get_user_meta( $user_id, 'peaa_managed_status', true );
    if ( empty( $status ) ) {
        return null;
    }

    return [
        'action'         => (string) $status,
        'date'           => (string) get_user_meta( $user_id, 'peaa_action_date', true ),
        'executor'       => (string) get_user_meta( $user_id, 'peaa_executor', true ),
        'original_roles' => (array) get_user_meta( $user_id, 'peaa_original_roles', true ),
    ];
}

/**
 * Obtener IDs de usuarios gestionados persistidos.
 */
function peaa_get_managed_user_ids(): array {
    $users = get_users([
        'fields'     => 'ids',
        'meta_key'   => 'peaa_managed_status',
        'meta_compare' => 'EXISTS',
        'number'     => -1,
    ]);
    return array_map( 'intval', (array) $users );
}


/**
 * Ejecutar una acción de remediación sobre un usuario.
 *
 * @param int    $user_id  ID del usuario objetivo.
 * @param string $action   Una de: trust | degrade | block | delete | revert
 * @return array { ok: bool, message: string, ?error: string }
 */
function peaa_run_action( int $user_id, string $action ): array {
    // Validar acción permitida
    $allowed = [ 'trust', 'degrade', 'block', 'delete', 'revert' ];
    if ( ! in_array( $action, $allowed, true ) ) {
        return [ 'ok' => false, 'error' => 'Acción no reconocida.' ];
    }

    // Cargar usuario
    $user = get_userdata( $user_id );
    if ( ! $user ) {
        return [ 'ok' => false, 'error' => 'Usuario no encontrado.' ];
    }

    // ── Protecciones críticas ─────────────────────────────────────────────────

    // No modificar al usuario que está ejecutando la acción
    if ( get_current_user_id() === $user_id ) {
        return [ 'ok' => false, 'error' => 'No puedes aplicar esta acción sobre tu propio usuario.' ];
    }

    // El superadmin original (ID 1) está siempre protegido
    if ( $user_id === 1 ) {
        return [ 'ok' => false, 'error' => 'El administrador original del sistema está protegido y no puede modificarse desde aquí.' ];
    }

    // En multisitio, no tocar superadmins de red
    if ( is_multisite() && is_super_admin( $user_id ) ) {
        return [ 'ok' => false, 'error' => 'Este usuario es superadministrador de red. Gestiona este caso desde la administración de red.' ];
    }

    // ── Cargar log ────────────────────────────────────────────────────────────
    $log_key  = 'peaa_action_log';
    $full_log = get_option( $log_key, [] );
    if ( ! is_array( $full_log ) ) {
        $full_log = [];
    }

    // Buscar si hay una acción previa sin revertir para este usuario
    $prev_index  = null;
    $prev_action = null;
    foreach ( $full_log as $i => $entry ) {
        if ( (int) $entry['user_id'] === $user_id && ! $entry['reversed'] ) {
            $prev_index  = $i;
            $prev_action = $entry;
            break;
        }
    }

    // ── Ejecutar acción ───────────────────────────────────────────────────────
    switch ( $action ) {

        // ── TRUST: marcar como confiable sin tocar el usuario ─────────────────
        case 'trust':
            $entry = [
                'user_id'       => $user_id,
                'user_login'    => $user->user_login,
                'action'        => 'trust',
                'original_role' => implode( ',', (array) $user->roles ),
                'date'          => current_time( 'Y-m-d H:i:s' ),
                'reversed'      => false,
                'executor'      => wp_get_current_user()->user_login,
                'notes'         => 'Marcado como confiable manualmente',
            ];
            $full_log[] = $entry;
            update_option( $log_key, $full_log );
            peaa_set_managed_state( $user_id, 'trust' );

            // Lista de confiables separada para consulta rápida
            $trusted = get_option( 'peaa_trusted_users', [] );
            if ( ! in_array( $user_id, $trusted, true ) ) {
                $trusted[] = $user_id;
                update_option( 'peaa_trusted_users', $trusted );
            }

            return [
                'ok'      => true,
                'message' => sprintf( 'Usuario <strong>%s</strong> marcado como confiable.', esc_html( $user->user_login ) ),
            ];

        // ── DEGRADE: bajar a subscriber ──────────────────────────────────────
        case 'degrade':
            $original_roles = (array) $user->roles;

            $entry = [
                'user_id'        => $user_id,
                'user_login'     => $user->user_login,
                'action'         => 'degrade',
                'original_roles' => $original_roles,
                'date'           => current_time( 'Y-m-d H:i:s' ),
                'reversed'       => false,
                'executor'       => wp_get_current_user()->user_login,
                'notes'          => 'Degradado a subscriber manualmente',
            ];

            $user_obj = new WP_User( $user_id );
            foreach ( $original_roles as $role ) {
                $user_obj->remove_role( $role );
            }
            $user_obj->add_role( 'subscriber' );

            // Revocar sesiones activas
            WP_Session_Tokens::get_instance( $user_id )->destroy_all();

            $full_log[] = $entry;
            update_option( $log_key, $full_log );
            peaa_set_managed_state( $user_id, 'degrade', [ 'original_roles' => $original_roles ] );

            return [
                'ok'      => true,
                'message' => sprintf(
                    'Usuario <strong>%s</strong> degradado a suscriptor. Sesiones activas revocadas.',
                    esc_html( $user->user_login )
                ),
            ];

        // ── BLOCK: revocar rol + invalidar sesiones + nueva contraseña ────────
        case 'block':
            $original_roles = (array) $user->roles;

            $entry = [
                'user_id'        => $user_id,
                'user_login'     => $user->user_login,
                'action'         => 'block',
                'original_roles' => $original_roles,
                'date'           => current_time( 'Y-m-d H:i:s' ),
                'reversed'       => false,
                'executor'       => wp_get_current_user()->user_login,
                'notes'          => 'Bloqueado: rol removido + contraseña regenerada + sesiones revocadas',
            ];

            $user_obj = new WP_User( $user_id );
            foreach ( $original_roles as $role ) {
                $user_obj->remove_role( $role );
            }
            // Sin rol = acceso mínimo, no puede hacer nada administrativo

            // Contraseña aleatoria segura — el usuario no podrá iniciar sesión
            wp_set_password( wp_generate_password( 64, true, true ), $user_id );

            // Revocar todas las sesiones activas
            WP_Session_Tokens::get_instance( $user_id )->destroy_all();

            $full_log[] = $entry;
            update_option( $log_key, $full_log );
            peaa_set_managed_state( $user_id, 'block', [ 'original_roles' => $original_roles ] );

            return [
                'ok'      => true,
                'message' => sprintf(
                    'Usuario <strong>%s</strong> bloqueado. Contraseña regenerada y sesiones revocadas.',
                    esc_html( $user->user_login )
                ),
            ];

        // ── DELETE: eliminar usuario permanentemente ──────────────────────────
        case 'delete':
            require_once ABSPATH . 'wp-admin/includes/user.php';

            // Pasar los contenidos al usuario actual por defecto
            $reassign_to = get_current_user_id();

            $original_roles = (array) $user->roles;

            $entry = [
                'user_id'        => $user_id,
                'user_login'     => $user->user_login,
                'action'         => 'delete',
                'original_roles' => $original_roles,
                'date'           => current_time( 'Y-m-d H:i:s' ),
                'reversed'       => true, // No reversible
                'executor'       => wp_get_current_user()->user_login,
                'notes'          => sprintf( 'Eliminado. Contenido reasignado al usuario ID %d.', $reassign_to ),
            ];

            $deleted = wp_delete_user( $user_id, $reassign_to );

            if ( ! $deleted ) {
                return [ 'ok' => false, 'error' => 'No se pudo eliminar el usuario. Verifica los permisos.' ];
            }

            // Quitar de confiables si estaba
            $trusted = get_option( 'peaa_trusted_users', [] );
            $trusted = array_values( array_diff( $trusted, [ $user_id ] ) );
            update_option( 'peaa_trusted_users', $trusted );

            $full_log[] = $entry;
            update_option( $log_key, $full_log );

            return [
                'ok'      => true,
                'message' => sprintf(
                    'Usuario <strong>%s</strong> eliminado permanentemente.',
                    esc_html( $user->user_login )
                ),
            ];

        // ── REVERT: deshacer la última acción activa ──────────────────────────
        case 'revert':
            if ( $prev_action === null ) {
                return [ 'ok' => false, 'error' => 'No hay ninguna acción activa para revertir sobre este usuario.' ];
            }

            if ( $prev_action['action'] === 'trust' ) {
                // Solo quitar de confiables
                $trusted = get_option( 'peaa_trusted_users', [] );
                $trusted = array_values( array_diff( $trusted, [ $user_id ] ) );
                update_option( 'peaa_trusted_users', $trusted );
                peaa_clear_managed_state( $user_id );

                $full_log[ $prev_index ]['reversed']      = true;
                $full_log[ $prev_index ]['reversed_date'] = current_time( 'Y-m-d H:i:s' );
                update_option( $log_key, $full_log );

                return [
                    'ok'      => true,
                    'message' => sprintf(
                        'Usuario <strong>%s</strong> removido de la lista de confiables.',
                        esc_html( $user->user_login )
                    ),
                ];
            }

            // Restaurar roles originales
            $original_roles = $prev_action['original_roles'] ?? [ 'administrator' ];
            $user_obj       = new WP_User( $user_id );

            foreach ( (array) $user->roles as $role ) {
                $user_obj->remove_role( $role );
            }
            foreach ( $original_roles as $role ) {
                $user_obj->add_role( $role );
            }

            $full_log[ $prev_index ]['reversed']      = true;
            $full_log[ $prev_index ]['reversed_date'] = current_time( 'Y-m-d H:i:s' );
            update_option( $log_key, $full_log );
            peaa_clear_managed_state( $user_id );

            $note = ( $prev_action['action'] === 'block' )
                ? ' <em>Nota: la contraseña no se restaura automáticamente — deberás restablecerla manualmente.</em>'
                : '';

            return [
                'ok'      => true,
                'message' => sprintf(
                    'Usuario <strong>%s</strong> restaurado con roles: %s.%s',
                    esc_html( $user->user_login ),
                    esc_html( implode( ', ', $original_roles ) ),
                    $note
                ),
            ];
    }

    return [ 'ok' => false, 'error' => 'Acción no ejecutada.' ];
}

/**
 * Obtener el estado actual de un usuario: su acción activa si existe.
 * Útil para saber si mostrar "Revertir" en la UI.
 *
 * @param int $user_id
 * @return array|null  Entrada del log si hay acción activa, null si no.
 */
function peaa_get_active_action( int $user_id ): ?array {
    $state = peaa_get_managed_state( $user_id );
    if ( $state ) {
        return $state;
    }

    $log = get_option( 'peaa_action_log', [] );
    foreach ( $log as $entry ) {
        if ( (int) $entry['user_id'] === $user_id && ! $entry['reversed'] ) {
            return $entry;
        }
    }
    return null;
}

/**
 * Verificar si un usuario está en la lista de confiables.
 *
 * @param int $user_id
 * @return bool
 */
function peaa_is_trusted( int $user_id ): bool {
    $state = peaa_get_managed_state( $user_id );
    if ( $state && ( $state['action'] ?? '' ) === 'trust' ) {
        return true;
    }

    $trusted = get_option( 'peaa_trusted_users', [] );
    return in_array( $user_id, array_map( 'intval', $trusted ), true );
}
