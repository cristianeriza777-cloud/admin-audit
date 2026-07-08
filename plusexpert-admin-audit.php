<?php
/**
 * Plugin Name:       PlusExpert Admin Audit
 * Plugin URI:        https://plusexpert.cl/plugins/
 * Description:       Monitor suspicious administrator activity and improve visibility over sensitive WordPress admin access.
 * Version:           1.8.4
 * Author:            PlusExpert
 * Author URI:        https://plusexpert.cl
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       plusexpert-admin-audit
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PEAA_VERSION',     '1.8.4' );
define( 'PEAA_DIR',         plugin_dir_path( __FILE__ ) );
define( 'PEAA_URL',         plugin_dir_url( __FILE__ ) );
define( 'PEAA_SLUG',        'plusexpert-admin-audit' );
define( 'PEXP_ROOT_SLUG',   'plusexpert' );

// ── Módulos core (lógica sin cambios) ────────────────────────────────────────
require_once PEAA_DIR . 'includes/detector.php';
require_once PEAA_DIR . 'includes/actions.php';
require_once PEAA_DIR . 'includes/log.php';
require_once PEAA_DIR . 'includes/visibility-scan.php';

// ── Panel de administración ───────────────────────────────────────────────────
require_once PEAA_DIR . 'admin/menu.php';
require_once PEAA_DIR . 'admin/page-dashboard.php';
require_once PEAA_DIR . 'admin/page-log.php';
require_once PEAA_DIR . 'admin/page-pro.php';
require_once PEAA_DIR . 'admin/ajax.php';

/**
 * Inicializar el plugin.
 */
function peaa_init(): void {
    add_action( 'admin_menu',            'peaa_register_menu' );
    add_action( 'admin_enqueue_scripts', 'peaa_enqueue_assets' );

    add_action( 'wp_ajax_peaa_run_action', 'peaa_ajax_run_action' );
    add_action( 'wp_ajax_peaa_do_scan',    'peaa_ajax_scan' );

    peaa_maybe_migrate_persistent_states();
}
add_action( 'plugins_loaded', 'peaa_init' );

/**
 * Migrar estados legacy desde options/log hacia user_meta persistente.
 */
function peaa_maybe_migrate_persistent_states(): void {
    $migrated = get_option( 'peaa_persistent_state_version' );
    if ( $migrated === PEAA_VERSION ) {
        return;
    }

    $trusted = get_option( 'peaa_trusted_users', [] );
    foreach ( (array) $trusted as $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id > 0 && ! get_user_meta( $user_id, 'peaa_managed_status', true ) ) {
            update_user_meta( $user_id, 'peaa_managed_status', 'trust' );
            update_user_meta( $user_id, 'peaa_last_action', 'trust' );
        }
    }

    $log = get_option( 'peaa_action_log', [] );
    if ( is_array( $log ) ) {
        foreach ( $log as $entry ) {
            if ( empty( $entry['user_id'] ) || ! empty( $entry['reversed'] ) ) {
                continue;
            }
            $user_id = (int) $entry['user_id'];
            if ( $user_id <= 0 ) {
                continue;
            }
            update_user_meta( $user_id, 'peaa_managed_status', (string) ( $entry['action'] ?? '' ) );
            update_user_meta( $user_id, 'peaa_last_action', (string) ( $entry['action'] ?? '' ) );
            if ( ! empty( $entry['date'] ) ) {
                update_user_meta( $user_id, 'peaa_action_date', (string) $entry['date'] );
            }
            if ( ! empty( $entry['executor'] ) ) {
                update_user_meta( $user_id, 'peaa_executor', (string) $entry['executor'] );
            }
            if ( ! empty( $entry['original_roles'] ) ) {
                update_user_meta( $user_id, 'peaa_original_roles', array_values( (array) $entry['original_roles'] ) );
            }
        }
    }

    update_option( 'peaa_persistent_state_version', PEAA_VERSION );
}


/**
 * Registrar assets solo en las páginas del plugin.
 */
function peaa_enqueue_assets( string $hook ): void {
    if ( strpos( $hook, PEAA_SLUG ) === false && $hook !== 'toplevel_page_' . PEXP_ROOT_SLUG ) {
        return;
    }

    wp_enqueue_style(
        'peaa-style',
        PEAA_URL . 'assets/style.css',
        [],
        PEAA_VERSION
    );

    wp_enqueue_script(
        'peaa-script',
        PEAA_URL . 'assets/script.js',
        [ 'jquery' ],
        PEAA_VERSION,
        true
    );

    wp_localize_script( 'peaa-script', 'peaaConfig', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'peaa_action' ),
        'i18n'    => [
            'confirm_block'   => '¿Confirmas que deseas bloquear este usuario? Se revocará su acceso de inmediato.',
            'confirm_degrade' => '¿Confirmas que deseas degradar este usuario a suscriptor?',
            'confirm_delete'  => '¿Confirmas que deseas ELIMINAR este usuario? Esta acción no se puede deshacer.',
            'confirm_revert'  => '¿Confirmas que deseas revertir la última acción sobre este usuario?',
            'confirm_trust'   => '¿Confirmas que deseas marcar este usuario como confiable?',
            'loading'         => 'Aplicando…',
            'error_generic'   => 'Ocurrió un error. Inténtalo de nuevo.',
            'scan_step1'      => 'Leyendo usuarios de la base de datos…',
            'scan_step2'      => 'Comparando capacidades y roles…',
            'scan_step3'      => 'Detectando inconsistencias…',
            'scan_step4'      => 'Analizando hooks de visibilidad…',
            'scan_step5'      => 'Preparando resultados…',
            'scan_done_clean' => 'Escaneo completado: no se detectaron administradores sospechosos.',
            'scan_done_warn'  => 'Escaneo completado: se detectaron elementos que conviene revisar.',
        ],
    ] );
}

function peaa_menu_styles(): void {
    ?>
    <style id="peaa-menu-styles">
        #adminmenu #toplevel_page_plusexpert .wp-submenu a[href="admin.php?page=<?php echo esc_attr( PEXP_ROOT_SLUG ); ?>"] {
            display: none !important;
        }
    </style>
    <?php
}

add_action( 'admin_head', 'peaa_menu_styles' );

register_activation_hook( __FILE__, function (): void {
    add_option( 'peaa_action_log',    [] );
    add_option( 'peaa_trusted_users', [] );
} );


