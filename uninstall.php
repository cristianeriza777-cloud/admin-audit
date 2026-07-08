<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// Preservar datos por defecto para permitir reinstalación y recuperación.
// Si deseas limpieza total, define PEAA_DELETE_DATA_ON_UNINSTALL en wp-config.php.
if ( ! defined( 'PEAA_DELETE_DATA_ON_UNINSTALL' ) || ! PEAA_DELETE_DATA_ON_UNINSTALL ) {
    return;
}

delete_option( 'peaa_action_log' );
delete_option( 'peaa_trusted_users' );
delete_option( 'peaa_persistent_state_version' );

$users = get_users([
    'fields'       => 'ids',
    'number'       => -1,
    'meta_key'     => 'peaa_managed_status',
    'meta_compare' => 'EXISTS',
]);

foreach ( (array) $users as $user_id ) {
    delete_user_meta( $user_id, 'peaa_managed_status' );
    delete_user_meta( $user_id, 'peaa_last_action' );
    delete_user_meta( $user_id, 'peaa_action_date' );
    delete_user_meta( $user_id, 'peaa_executor' );
    delete_user_meta( $user_id, 'peaa_original_roles' );
}
