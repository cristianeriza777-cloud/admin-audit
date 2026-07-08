<?php
/**
 * page-pro.php — Página informativa de PlusExpert Admin Audit Pro.
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function peaa_render_pro(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para ver esta p&aacute;gina.' );
    }

    $features = [
        [ 'label' => 'Escaneo de administradores',                           'free' => true,  'pro' => true ],
        [ 'label' => 'Reparaci&oacute;n de administradores sospechosos',      'free' => true,  'pro' => true ],
        [ 'label' => 'Detecci&oacute;n de hooks de visibilidad',              'free' => true,  'pro' => true ],
        [ 'label' => 'Historial de eventos',                                  'free' => false, 'pro' => true ],
        [ 'label' => 'Alertas por email',                                     'free' => false, 'pro' => true ],
        [ 'label' => 'Nuevo administrador creado',                            'free' => false, 'pro' => true ],
        [ 'label' => 'Usuario promovido a administrador',                     'free' => false, 'pro' => true ],
        [ 'label' => 'Login de administrador',                                'free' => false, 'pro' => true ],
        [ 'label' => 'Login administrador desde nueva IP',                    'free' => false, 'pro' => true ],
        [ 'label' => 'Cambio de email administrador',                         'free' => false, 'pro' => true ],
        [ 'label' => 'Cambio del correo principal del sitio',                 'free' => false, 'pro' => true ],
        [ 'label' => 'Cambio de contrase&ntilde;a administrador',              'free' => false, 'pro' => true ],
        [ 'label' => 'Plugin activado',                                       'free' => false, 'pro' => true ],
        [ 'label' => 'Plugin desactivado',                                    'free' => false, 'pro' => true ],
        [ 'label' => 'M&uacute;ltiples intentos fallidos admin',               'free' => false, 'pro' => true ],
        [ 'label' => 'Soporte prioritario',                                   'free' => false, 'pro' => true ],
    ];
    ?>
    <div class="wrap peaa-wrap">

        <div class="peaa-header">
            <div class="peaa-header__brand">
                <div class="peaa-header__icon">
                    <span class="dashicons dashicons-star-filled"></span>
                </div>
                <div class="peaa-header__text">
                    <h1 class="peaa-header__title">PlusExpert Admin Audit Pro</h1>
                    <p class="peaa-header__desc">
                        Obt&eacute;n monitoreo avanzado y alertas inteligentes para proteger tus sitios WordPress.
                    </p>
                </div>
            </div>
        </div>

        <?php // ── NAVEGACIÓN POR PESTAÑAS ───────────────────────────────────── ?>
        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG ) ); ?>"
               class="nav-tab dashicons-before dashicons-search">Escanear</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG . '-log' ) ); ?>"
               class="nav-tab dashicons-before dashicons-list-view">Historial</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG . '-pro' ) ); ?>"
               class="nav-tab nav-tab-active dashicons-before dashicons-star-filled">Pro</a>
        </nav>

        <div class="peaa-section">
            <div class="peaa-section__header">
                <h2><span class="dashicons dashicons-chart-bar"></span> Comparativa Free vs Pro</h2>
                <p class="peaa-section__desc">
                    La versi&oacute;n gratuita incluye herramientas esenciales de auditor&iacute;a. La versi&oacute;n Pro agrega monitoreo proactivo, alertas por email y soporte prioritario.
                </p>
            </div>

            <div class="peaa-table-wrap">
                <table class="peaa-table wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Funci&oacute;n</th>
                            <th class="peaa-col-check">Free</th>
                            <th class="peaa-col-check">Pro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $features as $f ) : ?>
                        <tr>
                            <td><?php echo esc_html( $f['label'] ); ?></td>
                            <td class="peaa-col-check">
                                <?php if ( $f['free'] ) : ?>
                                    <span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
                                <?php else : ?>
                                    <span style="color:#ccc;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="peaa-col-check">
                                <span class="dashicons dashicons-yes-alt" style="color:#4f46e5;"></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="peaa-upgrade-card">
            <div class="peaa-upgrade-card__content">
                <h2>&iquest;Listo para monitoreo avanzado?</h2>
                <p>Obt&eacute;n notificaciones por email, historial de eventos y soporte prioritario con PlusExpert Admin Audit Pro.</p>
            </div>
            <div class="peaa-upgrade-card__action">
                <a href="<?php echo esc_url( 'https://plusexpert.cl/plugins/' ); ?>"
                   class="button button-primary button-hero"
                   target="_blank"
                   rel="noopener noreferrer">
                    Obtener PlusExpert Pro
                </a>
            </div>
        </div>

        <div class="peaa-footer-info">
            <div class="peaa-footer-info__col">
                <strong>PlusExpert Admin Audit</strong>
                <span class="peaa-version-pill">v<?php echo esc_html( PEAA_VERSION ); ?></span>
            </div>
            <div class="peaa-footer-info__links">
                <a href="https://plusexpert.cl" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-admin-site-alt3"></span> plusexpert.cl
                </a>
                <a href="mailto:hola@plusexpert.cl">
                    <span class="dashicons dashicons-email-alt"></span> hola@plusexpert.cl
                </a>
            </div>
        </div>

    </div>
    <?php
}
