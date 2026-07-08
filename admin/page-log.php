<?php
/**
 * page-log.php — Historial de acciones de PlusExpert Admin Audit.
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function peaa_render_log(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para ver esta página.' );
    }

    $log   = peaa_get_log();
    $stats = peaa_log_stats();
    ?>
    <div class="wrap peaa-wrap">

        <div class="peaa-header">
            <div class="peaa-header__brand">
                <div class="peaa-header__icon">
                    <span class="dashicons dashicons-list-view"></span>
                </div>
                <div class="peaa-header__text">
                    <h1 class="peaa-header__title">Historial de acciones</h1>
                    <p class="peaa-header__desc">Registro completo de todas las intervenciones realizadas sobre usuarios administrativos.</p>
                </div>
            </div>
        </div>

        <?php // ── NAVEGACIÓN POR PESTAÑAS ───────────────────────────────────── ?>
        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG ) ); ?>"
               class="nav-tab dashicons-before dashicons-search">Escanear</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG . '-log' ) ); ?>"
               class="nav-tab nav-tab-active dashicons-before dashicons-list-view">Historial</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG . '-pro' ) ); ?>"
               class="nav-tab dashicons-before dashicons-star-filled">Pro</a>
        </nav>

        <div class="peaa-summary-bar">
            <div class="peaa-card">
                <div class="peaa-card__icon peaa-card__icon--neutral">
                    <span class="dashicons dashicons-backup"></span>
                </div>
                <div>
                    <div class="peaa-card__val"><?php echo esc_html( $stats['total'] ); ?></div>
                    <div class="peaa-card__label">Acciones registradas</div>
                </div>
            </div>
            <div class="peaa-card <?php echo $stats['active'] > 0 ? 'peaa-card--warn' : ''; ?>">
                <div class="peaa-card__icon <?php echo $stats['active'] > 0 ? 'peaa-card__icon--warn' : 'peaa-card__icon--neutral'; ?>">
                    <span class="dashicons dashicons-clock"></span>
                </div>
                <div>
                    <div class="peaa-card__val"><?php echo esc_html( $stats['active'] ); ?></div>
                    <div class="peaa-card__label">Acciones activas</div>
                </div>
            </div>
            <div class="peaa-card">
                <div class="peaa-card__icon peaa-card__icon--neutral">
                    <span class="dashicons dashicons-undo"></span>
                </div>
                <div>
                    <div class="peaa-card__val"><?php echo esc_html( $stats['reversed'] ); ?></div>
                    <div class="peaa-card__label">Revertidas</div>
                </div>
            </div>
        </div>

        <?php if ( empty( $log ) ) : ?>
            <div class="peaa-empty">
                <span class="dashicons dashicons-yes-alt"></span>
                No hay acciones registradas todavía.
            </div>
        <?php else : ?>
        <div class="peaa-section">
            <div class="peaa-table-wrap">
                <table class="peaa-table wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario afectado</th>
                            <th>Acción</th>
                            <th>Ejecutado por</th>
                            <th>Estado</th>
                            <th>Notas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) :
                            $action_class = peaa_action_class( $entry['action'] ?? '' );
                            $is_reversed  = ! empty( $entry['reversed'] );
                        ?>
                        <tr class="<?php echo $is_reversed ? 'peaa-row--reverted' : ''; ?>">
                            <td class="peaa-text-dim"><?php echo esc_html( $entry['date'] ?? '—' ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $entry['user_login'] ?? '?' ); ?></strong>
                                <span class="peaa-text-dim">&nbsp;ID <?php echo esc_html( $entry['user_id'] ?? '?' ); ?></span>
                            </td>
                            <td>
                                <span class="peaa-badge <?php echo esc_attr( $action_class ); ?>">
                                    <?php echo esc_html( peaa_action_label( $entry['action'] ?? '' ) ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $entry['executor'] ?? '—' ); ?></td>
                            <td>
                                <?php if ( $is_reversed ) : ?>
                                    <span class="peaa-badge peaa-badge--info">
                                        Revertido
                                        <?php if ( ! empty( $entry['reversed_date'] ) ) : ?>
                                        <span class="peaa-text-dim">(<?php echo esc_html( $entry['reversed_date'] ); ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                <?php else : ?>
                                    <span class="peaa-badge peaa-badge--warn">Activo</span>
                                <?php endif; ?>
                            </td>
                            <td class="peaa-text-dim"><?php echo wp_kses_post( $entry['notes'] ?? '—' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

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
