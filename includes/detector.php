<?php
/**
 * detector.php — Detección de administradores reales y sospechosos.
 *
 * Adaptado desde plusexpert-agent/includes/users.php.
 * Esta versión no depende del sistema Audit externo.
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Punto de entrada principal del detector.
 * Devuelve todos los admins reales detectados desde la DB y el análisis de heurísticas.
 *
 * @return array {
 *   admins_detected    array  Todos los admins encontrados en DB
 *   suspicious_admins  array  Admins con razones de sospecha (excluyendo gestionados)
 *   hidden_admins      array  Admins con capacidades inconsistentes
 *   admin_default      bool   Si existe el login "admin"
 *   summary            array  Totales y flags resumen
 * }
 */
function peaa_scan(): array {
    $db_scan      = peaa_scan_db();
    $hidden       = peaa_scan_hidden_admins();

    // IDs ya gestionados persistidos por el plugin.
    $managed_ids = peaa_get_managed_user_ids();

    // Filtrar sospechosos pendientes (no gestionados)
    $pending_suspicious = array_filter(
        $db_scan['suspicious_admins'],
        fn( $s ) => ! in_array( (int) $s['id'], $managed_ids, true )
    );

    return [
        'admins_detected'   => array_values( $db_scan['admins_detected'] ),
        'suspicious_admins' => array_values( $db_scan['suspicious_admins'] ),
        'pending_suspicious'=> array_values( $pending_suspicious ),
        'hidden_admins'     => $hidden,
        'admin_default'     => $db_scan['summary']['default_admin_present'],
        'managed_ids'       => $managed_ids,
        'summary'           => [
            'total_admins'       => count( $db_scan['admins_detected'] ),
            'total_suspicious'   => count( $db_scan['suspicious_admins'] ),
            'pending_suspicious' => count( $pending_suspicious ),
            'hidden_count'       => count( $hidden ),
            'admin_login_exists' => $db_scan['summary']['default_admin_present'],
            'notes'              => $db_scan['summary']['notes'],
        ],
    ];
}

/**
 * Escanear la base de datos directamente para encontrar todos los usuarios
 * con capacidad de administrador, independientemente de lo que muestre la API de WP.
 * Respeta prefijos de tabla personalizados.
 */
function peaa_scan_db(): array {
    global $wpdb;

    $admins_detected   = [];
    $suspicious_admins = [];
    $default_admin     = false;
    $limit             = 100;

    // Consulta directa a usermeta buscando capabilities que contengan 'administrator'.
    // Usar $wpdb->usermeta y $wpdb->users respeta el prefijo de tabla configurado.
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT u.ID, u.user_login, u.user_email, u.user_registered,
                    m.meta_key, m.meta_value
             FROM {$wpdb->users} u
             JOIN {$wpdb->usermeta} m ON u.ID = m.user_id
             WHERE m.meta_key LIKE %s
               AND m.meta_value LIKE %s
             LIMIT %d",
            '%_capabilities',
            '%administrator%',
            $limit + 1
        )
    );

    $notes = '';
    if ( count( $results ) > $limit ) {
        $notes = 'limit_reached';
        array_pop( $results );
    }

    foreach ( $results as $row ) {
        $caps = maybe_unserialize( $row->meta_value );

        // Solo contar si realmente tiene administrator = true en sus caps
        if ( ! is_array( $caps ) || empty( $caps['administrator'] ) ) {
            continue;
        }

        $admin_data = [
            'id'          => (int) $row->ID,
            'login'       => $row->user_login,
            'email'       => $row->user_email,
            'registered'  => $row->user_registered,
            'cap_key'     => $row->meta_key,
            'display_name'=> get_userdata( $row->ID )->display_name ?? $row->user_login,
        ];

        $admins_detected[] = $admin_data;

        if ( $row->user_login === 'admin' ) {
            $default_admin = true;
        }

        // ID 1 = superadmin original. Nunca se marca como sospechoso.
        if ( (int) $row->ID === 1 ) {
            continue;
        }

        $reasons = peaa_apply_heuristics( $row );

        if ( ! empty( $reasons ) ) {
            $suspicious_admins[] = array_merge( $admin_data, [ 'reasons' => $reasons ] );
        }
    }

    return [
        'admins_detected'   => $admins_detected,
        'suspicious_admins' => $suspicious_admins,
        'summary'           => [
            'total_admins_detected'  => count( $admins_detected ),
            'total_suspicious_admins'=> count( $suspicious_admins ),
            'default_admin_present'  => $default_admin,
            'notes'                  => $notes,
        ],
    ];
}

/**
 * Aplicar heurísticas para detectar administradores sospechosos.
 * Devuelve array de razones encontradas (vacío = no sospechoso).
 *
 * @param object $row Fila de resultado de la consulta DB.
 * @return string[]
 */
function peaa_apply_heuristics( object $row ): array {
    $reasons = [];

    // 1. Login con patrón de nombre de sistema o genérico
    if ( preg_match( '/wpcron|backup|support|adm\b|administrator|root|sys\b|test\b/i', $row->user_login ) ) {
        $reasons[] = 'login_pattern_suspicious';
    }

    // 2. Login parece un hash aleatorio (hex largo) o nombre auto-generado
    if (
        preg_match( '/[a-f0-9]{10,}/i', $row->user_login ) ||
        ( strlen( $row->user_login ) > 18 &&
          preg_match( '/[0-9]/', $row->user_login ) &&
          preg_match( '/[a-zA-Z]/', $row->user_login ) )
    ) {
        $reasons[] = 'login_looks_random_hash';
    }

    // 3. Email de proveedor gratuito (inusual para una cuenta admin real)
    // 4. Registrado hace menos de 30 días
    if ( strtotime( $row->user_registered ) > ( time() - ( 30 * DAY_IN_SECONDS ) ) ) {
        $reasons[] = 'registered_recent';
    }

    return $reasons;
}

/**
 * Detectar administradores con capacidades inconsistentes:
 * - Tiene rol administrator pero le faltan capabilities clave
 * - Tiene manage_options sin ser administrator
 *
 * Estos son potencialmente usuarios manipulados para evadir detección.
 */
function peaa_scan_hidden_admins(): array {
    $hidden = [];

    // Admins con rol pero sin capabilities estándar
    $admins = get_users( [ 'role' => 'administrator' ] );
    foreach ( $admins as $user ) {
        if (
            ! user_can( $user->ID, 'manage_options' ) ||
            ! user_can( $user->ID, 'activate_plugins' )
        ) {
            $hidden[] = [
                'id'    => $user->ID,
                'login' => $user->user_login,
                'issue' => 'admin_without_standard_caps',
                'label' => 'Rol administrador sin capacidades estándar',
            ];
            continue;
        }

        $caps = $user->caps;
        if ( ! isset( $caps['administrator'] ) || ! $caps['administrator'] ) {
            $hidden[] = [
                'id'    => $user->ID,
                'login' => $user->user_login,
                'issue' => 'inconsistent_role_definition',
                'label' => 'Definición de rol inconsistente',
            ];
        }
    }

    // Usuarios con manage_options que NO son administrators (privilegio escalado)
    $all_users = get_users( [ 'number' => 200 ] );
    foreach ( $all_users as $user ) {
        if (
            user_can( $user->ID, 'manage_options' ) &&
            ! in_array( 'administrator', (array) $user->roles, true )
        ) {
            $hidden[] = [
                'id'    => $user->ID,
                'login' => $user->user_login,
                'issue' => 'manage_options_without_admin_role',
                'label' => 'Tiene manage_options sin ser administrador',
            ];
        }
    }

    return $hidden;
}

/**
 * Mapa de etiquetas legibles para cada razón de sospecha.
 *
 * @param string $reason
 * @return string
 */
function peaa_reason_label( string $reason ): string {
    $labels = [
        'login_pattern_suspicious'  => 'Login con patrón sospechoso',
        'login_looks_random_hash'   => 'Login parece hash generado automáticamente',
        'registered_recent'         => 'Registrado hace menos de 30 días',
        'admin_without_standard_caps' => 'Sin capacidades estándar',
        'inconsistent_role_definition' => 'Definición de rol inconsistente',
        'manage_options_without_admin_role' => 'manage_options sin rol admin',
    ];

    return $labels[ $reason ] ?? $reason;
}
