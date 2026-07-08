<?php
/**
 * menu.php — Registro del menú de administración.
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function peaa_register_menu(): void {
    global $menu;

    $root_exists = false;
    if ( is_array( $menu ) ) {
        foreach ( $menu as $item ) {
            if ( isset( $item[2] ) && $item[2] === PEXP_ROOT_SLUG ) {
                $root_exists = true;
                break;
            }
        }
    }

    if ( ! $root_exists ) {
        add_menu_page(
            'PlusExpert',
            'PlusExpert',
            'manage_options',
            PEXP_ROOT_SLUG,
            'peaa_render_dashboard',
            'dashicons-shield-alt',
            75
        );
    }

    add_submenu_page(
        PEXP_ROOT_SLUG,
        'Auditoría de Administradores — PlusExpert Admin Audit',
        'Admin Audit',
        'manage_options',
        PEAA_SLUG,
        'peaa_render_dashboard'
    );

    add_submenu_page(
        null,
        'Historial de Acciones — PlusExpert Admin Audit',
        'Historial',
        'manage_options',
        PEAA_SLUG . '-log',
        'peaa_render_log'
    );

    add_submenu_page(
        null,
        'PlusExpert Admin Audit Pro',
        'Pro',
        'manage_options',
        PEAA_SLUG . '-pro',
        'peaa_render_pro'
    );

    remove_submenu_page( PEXP_ROOT_SLUG, PEXP_ROOT_SLUG );
}
