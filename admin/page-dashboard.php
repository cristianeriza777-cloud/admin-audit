<?php
/**
 * page-dashboard.php — Dashboard principal de PlusExpert Admin Audit.
 *
 * Flujo:
 * 1. El usuario ve el landing con el botón "Escanear ahora"
 * 2. Al hacer clic: animación de escaneo por pasos
 * 3. Llamada AJAX al servidor con el escaneo real
 * 4. Los resultados se inyectan en el panel
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render principal de la página.
 */
function peaa_render_dashboard(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permisos para ver esta página.' );
    }

    $log_stats = peaa_log_stats();
    ?>
    <div class="wrap peaa-wrap">

        <?php // ── CABECERA ──────────────────────────────────────────────────── ?>
        <div class="peaa-header">
            <div class="peaa-header__brand">
                <div class="peaa-header__icon">
                    <span class="dashicons dashicons-shield-alt"></span>
                </div>
                <div class="peaa-header__text">
                    <h1 class="peaa-header__title">PlusExpert Admin Audit</h1>
                    <p class="peaa-header__desc">
                        Detecta administradores ocultos y privilegios peligrosos que pueden pasar desapercibidos en WordPress.
                    </p>
                </div>
            </div>
        </div>

        <?php // ── NAVEGACIÓN POR PESTAÑAS ───────────────────────────────────── ?>
        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG ) ); ?>"
               class="nav-tab nav-tab-active dashicons-before dashicons-search">Escanear</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG . '-log' ) ); ?>"
               class="nav-tab dashicons-before dashicons-list-view">Historial</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . PEAA_SLUG . '-pro' ) ); ?>"
               class="nav-tab dashicons-before dashicons-star-filled">Pro</a>
        </nav>

        <?php // ── NOTICE ────────────────────────────────────────────────────── ?>
        <div id="peaa-notice" class="peaa-notice" style="display:none;" role="alert"></div>

        <?php // ── PANEL DE ESCANEO ──────────────────────────────────────────── ?>
        <div class="peaa-scan-panel">

            <?php // Landing inicial ?>
            <div class="peaa-scan-idle" id="peaa-scan-idle">
                <div class="peaa-scan-idle__icon-wrap">
                    <span class="dashicons dashicons-database-search peaa-scan-idle__main-icon"></span>
                </div>
                <div class="peaa-scan-idle__body">
                    <h2 class="peaa-scan-idle__title">Auditoría de privilegios administrativos</h2>
                    <p class="peaa-scan-idle__desc">
                        Analiza cuentas administrativas, capacidades y señales de manipulación para ayudarte a detectar accesos no autorizados antes de que se conviertan en un problema.
                    </p>
                    <div class="peaa-features">
                        <span class="peaa-feature"><span class="dashicons dashicons-visibility"></span> Admins ocultos</span>
                        <span class="peaa-feature"><span class="dashicons dashicons-warning"></span> Capacidades sospechosas</span>
                        <span class="peaa-feature"><span class="dashicons dashicons-code-standards"></span> Hooks de visibilidad</span>
                        <span class="peaa-feature"><span class="dashicons dashicons-admin-users"></span> Privilegios reales</span>
                    </div>
                </div>
                <div class="peaa-scan-idle__cta">
                    <button id="peaa-btn-scan" class="peaa-btn-scan">
                        <span class="dashicons dashicons-search"></span>
                        Escanear ahora
                    </button>
                    <?php if ( $log_stats['total'] > 0 ) : ?>
                    <p class="peaa-scan-idle__hint">
                        <?php echo esc_html( $log_stats['total'] ); ?> acción<?php echo $log_stats['total'] !== 1 ? 'es' : ''; ?> registrada<?php echo $log_stats['total'] !== 1 ? 's' : ''; ?> en el historial.
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <?php // Estado de escaneo animado ?>
            <div class="peaa-scan-loading" id="peaa-scan-loading" style="display:none;">
                <div class="peaa-scanner">
                    <div class="peaa-scanner__ring">
                        <svg viewBox="0 0 60 60">
                            <circle cx="30" cy="30" r="26" fill="none" stroke-width="3" class="peaa-ring-track"/>
                            <circle cx="30" cy="30" r="26" fill="none" stroke-width="3" class="peaa-ring-progress" id="peaa-ring-progress"/>
                        </svg>
                        <span class="dashicons dashicons-shield-alt peaa-scanner__icon"></span>
                    </div>
                    <div class="peaa-scanner__text">
                        <div class="peaa-scanner__step" id="peaa-scan-step">Iniciando auditoría…</div>
                        <div class="peaa-scan-bar">
                            <div class="peaa-scan-bar__fill" id="peaa-scan-bar-fill"></div>
                        </div>
                        <div class="peaa-scanner__log" id="peaa-scan-log"></div>
                    </div>
                </div>
            </div>

        </div>

        <?php // ── RESULTADOS (inyectados vía JS) ───────────────────────────── ?>
        <div id="peaa-results" style="display:none;"></div>

    </div>
    <?php
}

/**
 * Renderizar el bloque de resultados.
 */
function peaa_render_results( array $data, array $vis_data ): void {
    $total      = $data['summary']['total_admins'];
    $suspicious = $data['summary']['total_suspicious'];
    $pending    = $data['summary']['pending_suspicious'];
    $hidden     = $data['summary']['hidden_count'];
    $current_admin_ids = array_map( static function ( array $admin ): int {
        return (int) $admin['id'];
    }, (array) $data['admins_detected'] );

    $managed = array_values( array_filter( peaa_get_managed_users(), static function ( array $item ) use ( $current_admin_ids ): bool {
        return ! in_array( (int) $item['user_id'], $current_admin_ids, true );
    } ) );

    $banner_class = 'peaa-status-banner--ok';
    $banner_title = 'No se detectaron administradores sospechosos';
    $banner_desc  = 'El escaneo terminó correctamente y, por ahora, no se observan privilegios administrativos anómalos.';

    if ( $pending > 0 ) {
        $banner_class = 'peaa-status-banner--danger';
        $banner_title = 'Hay administradores o privilegios que requieren revisión';
        $banner_desc  = 'Se encontraron cuentas o capacidades con indicadores de riesgo. Revisa el detalle y aplica una acción si corresponde.';
    } elseif ( $suspicious > 0 || $hidden > 0 || $data['admin_default'] ) {
        $banner_class = 'peaa-status-banner--warn';
        $banner_title = 'Se detectaron indicadores que conviene revisar';
        $banner_desc  = 'No todo implica un compromiso, pero sí hay señales que merecen validación manual para descartar accesos no autorizados.';
    }
    ?>

    <?php // ── Aviso de escaneo truncado ─────────────────────────────────────── ?>
    <?php if ( ( $data['summary']['notes'] ?? '' ) === 'limit_reached' ) : ?>
        <div class="notice notice-warning peaa-notice-inline" style="margin:12px 0;padding:10px 14px;border-left-color:#dba617;">
            <span class="dashicons dashicons-warning" style="color:#dba617;vertical-align:middle;margin-right:6px;"></span>
            <strong>Escaneo truncado:</strong> se encontraron más administradores de los que muestra este panel. El análisis está limitado a los primeros 100 resultados. Pueden existir cuentas con privilegios que no aparecen aquí. Revisa manualmente desde <a href="<?php echo esc_url( admin_url( 'users.php?role=administrator' ) ); ?>">Usuarios → Administradores</a>.
        </div>
    <?php endif; ?>

    <div class="peaa-status-banner <?php echo esc_attr( $banner_class ); ?>">
        <div class="peaa-status-banner__icon">
            <span class="dashicons <?php echo $banner_class === 'peaa-status-banner--ok' ? 'dashicons-yes-alt' : ( $banner_class === 'peaa-status-banner--danger' ? 'dashicons-warning' : 'dashicons-search' ); ?>"></span>
        </div>
        <div class="peaa-status-banner__content">
            <strong><?php echo esc_html( $banner_title ); ?></strong>
            <p><?php echo esc_html( $banner_desc ); ?></p>
        </div>
    </div>

    <?php // ── Cards resumen ─────────────────────────────────────────────────── ?>
    <div class="peaa-summary-bar">

        <div class="peaa-card">
            <div class="peaa-card__icon peaa-card__icon--neutral">
                <span class="dashicons dashicons-admin-users"></span>
            </div>
            <div>
                <div class="peaa-card__val"><?php echo esc_html( $total ); ?></div>
                <div class="peaa-card__label">Admins en base de datos</div>
            </div>
        </div>

        <div class="peaa-card <?php echo $suspicious > 0 ? 'peaa-card--warn' : ''; ?>">
            <div class="peaa-card__icon <?php echo $suspicious > 0 ? 'peaa-card__icon--warn' : 'peaa-card__icon--neutral'; ?>">
                <span class="dashicons dashicons-flag"></span>
            </div>
            <div>
                <div class="peaa-card__val"><?php echo esc_html( $suspicious ); ?></div>
                <div class="peaa-card__label">Con indicadores de riesgo</div>
            </div>
        </div>

        <div class="peaa-card <?php echo $pending > 0 ? 'peaa-card--danger' : 'peaa-card--ok'; ?>">
            <div class="peaa-card__icon <?php echo $pending > 0 ? 'peaa-card__icon--danger' : 'peaa-card__icon--ok'; ?>">
                <span class="dashicons <?php echo $pending > 0 ? 'dashicons-dismiss' : 'dashicons-yes-alt'; ?>"></span>
            </div>
            <div>
                <div class="peaa-card__val"><?php echo esc_html( $pending ); ?></div>
                <div class="peaa-card__label">Pendientes de revisión</div>
            </div>
        </div>

        <div class="peaa-card <?php echo $hidden > 0 ? 'peaa-card--warn' : ''; ?>">
            <div class="peaa-card__icon <?php echo $hidden > 0 ? 'peaa-card__icon--warn' : 'peaa-card__icon--neutral'; ?>">
                <span class="dashicons dashicons-hidden"></span>
            </div>
            <div>
                <div class="peaa-card__val"><?php echo esc_html( $hidden ); ?></div>
                <div class="peaa-card__label">Capacidades inconsistentes</div>
            </div>
        </div>

        <div class="peaa-card <?php echo $data['admin_default'] ? 'peaa-card--warn' : ''; ?>">
            <div class="peaa-card__icon <?php echo $data['admin_default'] ? 'peaa-card__icon--warn' : 'peaa-card__icon--neutral'; ?>">
                <span class="dashicons dashicons-lock"></span>
            </div>
            <div>
                <div class="peaa-card__val"><?php echo $data['admin_default'] ? 'Sí' : 'No'; ?></div>
                <div class="peaa-card__label">Login "admin" presente</div>
            </div>
        </div>

    </div>

    <?php // ── Barra de re-escaneo ───────────────────────────────────────────── ?>
    <div class="peaa-rescan-bar">
        <button id="peaa-btn-scan" class="peaa-btn-rescan button">
            <span class="dashicons dashicons-update"></span> Volver a escanear
        </button>
        <span class="peaa-rescan-ts">
            Escaneo completado: <strong id="peaa-last-ts"></strong>
        </span>
    </div>

    <?php // ── Tabla principal ───────────────────────────────────────────────── ?>
    <div class="peaa-section">
        <div class="peaa-section__header">
            <h2><span class="dashicons dashicons-database"></span> Administradores detectados actualmente</h2>
            <p class="peaa-section__desc">
                Listado de cuentas con privilegios administrativos activos detectados durante el análisis.
            </p>
        </div>

        <?php if ( empty( $data['admins_detected'] ) ) : ?>
            <div class="peaa-empty">
                <span class="dashicons dashicons-yes-alt"></span>
                No se detectaron administradores en la base de datos.
            </div>
        <?php else : ?>
        <div class="peaa-table-wrap">
            <table class="peaa-table wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="col-id">ID</th>
                        <th class="col-login">Usuario</th>
                        <th class="col-email">Email</th>
                        <th class="col-reg">Registrado</th>
                        <th class="col-status">Estado</th>
                        <th class="col-act">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $data['admins_detected'] as $admin ) :
                    $uid         = (int) $admin['id'];
                    $is_current  = ( get_current_user_id() === $uid );
                    $is_original = ( $uid === 1 );
                    $is_trusted  = peaa_is_trusted( $uid );
                    $active_act  = peaa_get_active_action( $uid );
                    $is_managed  = in_array( $uid, $data['managed_ids'], true );

                    $suspicious_entry = null;
                    foreach ( $data['suspicious_admins'] as $s ) {
                        if ( (int) $s['id'] === $uid ) { $suspicious_entry = $s; break; }
                    }

                    $row_class = '';
                    if ( $is_current || $is_original ) {
                        $row_class = 'peaa-row--protected';
                    } elseif ( $suspicious_entry && ! $is_managed ) {
                        $row_class = 'peaa-row--suspicious';
                    } elseif ( $is_managed ) {
                        $row_class = 'peaa-row--managed';
                    }

                    global $wpdb;
                    $cap_custom = ( $admin['cap_key'] !== $wpdb->prefix . 'capabilities' );
                ?>
                <tr class="<?php echo esc_attr( $row_class ); ?>" id="peaa-row-<?php echo esc_attr( $uid ); ?>">

                    <td class="col-id"><span class="peaa-uid"><?php echo esc_html( $uid ); ?></span></td>

                    <td class="col-login">
                        <div class="peaa-user-cell">
                            <?php echo get_avatar( $uid, 32, '', '', [ 'class' => 'peaa-avatar' ] ); ?>
                            <div class="peaa-user-meta">
                                <strong><?php echo esc_html( $admin['login'] ); ?></strong>
                                <?php if ( ! empty( $admin['display_name'] ) && $admin['display_name'] !== $admin['login'] ) : ?>
                                    <span class="peaa-displayname"><?php echo esc_html( $admin['display_name'] ); ?></span>
                                <?php endif; ?>
                                <div class="peaa-user-badges">
                                    <?php if ( $is_current ) : ?>
                                        <span class="peaa-badge peaa-badge--info">Tu cuenta</span>
                                    <?php elseif ( $is_original ) : ?>
                                        <span class="peaa-badge peaa-badge--info">Cuenta administrativa original</span>
                                    <?php endif; ?>
                                    <?php if ( $is_trusted && ! $is_original && ! $is_current ) : ?>
                                        <span class="peaa-badge peaa-badge--ok">Confiable</span>
                                    <?php endif; ?>
                                    <?php if ( $active_act && $active_act['action'] !== 'trust' ) : ?>
                                        <span class="peaa-badge peaa-badge--warn"><?php echo esc_html( peaa_action_label( $active_act['action'] ) ); ?></span>
                                    <?php endif; ?>
                                    <?php if ( $cap_custom ) : ?>
                                        <span class="peaa-badge peaa-badge--warn" title="Prefijo: <?php echo esc_attr( $admin['cap_key'] ); ?>">Prefijo no estándar</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>

                    <td class="col-email"><?php echo esc_html( $admin['email'] ); ?></td>

                    <td class="col-reg">
                        <?php
                        $ts       = strtotime( $admin['registered'] );
                        $days_ago = floor( ( time() - $ts ) / DAY_IN_SECONDS );
                        echo esc_html( date_i18n( 'd/m/Y', $ts ) );
                        if ( $days_ago < 30 ) {
                            echo ' <span class="peaa-badge peaa-badge--warn">hace ' . esc_html( $days_ago ) . 'd</span>';
                        }
                        ?>
                    </td>

                    <td class="col-status">
                        <?php if ( $suspicious_entry && ! $is_managed ) : ?>
                            <span class="peaa-badge peaa-badge--danger"><span class="dashicons dashicons-warning"></span> Sospechoso</span>
                            <div class="peaa-reasons">
                                <?php foreach ( $suspicious_entry['reasons'] as $r ) : ?>
                                    <span class="peaa-reason"><?php echo esc_html( peaa_reason_label( $r ) ); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ( $is_managed ) : ?>
                            <?php $managed_label = $active_act ? peaa_action_label( $active_act['action'] ) : 'Gestionado'; ?>
                            <?php $managed_class = 'peaa-badge--ok'; ?>
                            <?php if ( $active_act && in_array( $active_act['action'], [ 'block' ], true ) ) { $managed_class = 'peaa-badge--danger'; } ?>
                            <?php if ( $active_act && in_array( $active_act['action'], [ 'degrade' ], true ) ) { $managed_class = 'peaa-badge--warn'; } ?>
                            <span class="peaa-badge <?php echo esc_attr( $managed_class ); ?>">
                                <span class="dashicons <?php echo esc_attr( $active_act && $active_act['action'] === 'block' ? 'dashicons-lock' : ( $active_act && $active_act['action'] === 'degrade' ? 'dashicons-arrow-down-alt' : ( $active_act && $active_act['action'] === 'trust' ? 'dashicons-yes' : 'dashicons-yes' ) ) ); ?>"></span>
                                <?php echo esc_html( $managed_label ); ?>
                            </span>
                            <?php if ( $suspicious_entry ) : ?>
                                <div class="peaa-reasons peaa-reasons--dim">
                                    <?php foreach ( $suspicious_entry['reasons'] as $r ) : ?>
                                        <span class="peaa-reason peaa-reason--dim"><?php echo esc_html( peaa_reason_label( $r ) ); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ( $is_current || $is_original ) : ?>
                            <span class="peaa-badge peaa-badge--info"><span class="dashicons dashicons-shield"></span> <?php echo $is_current ? 'Tu sesión' : 'Admin original'; ?></span>
                        <?php else : ?>
                            <span class="peaa-badge peaa-badge--ok"><span class="dashicons dashicons-yes-alt"></span> Sin indicadores</span>
                        <?php endif; ?>
                    </td>

                    <td class="col-act peaa-actions">
                        <?php if ( ! $is_current && ! $is_original ) : ?>
                            <?php if ( $active_act ) : ?>
                                <button class="peaa-btn peaa-btn--revert button"
                                    data-uid="<?php echo esc_attr( $uid ); ?>" data-action="revert"
                                    data-login="<?php echo esc_attr( $admin['login'] ); ?>">
                                    <span class="dashicons dashicons-undo"></span> Revertir
                                </button>
                            <?php else : ?>
                                <?php if ( ! $is_trusted ) : ?>
                                <button class="peaa-btn peaa-btn--trust button"
                                    data-uid="<?php echo esc_attr( $uid ); ?>" data-action="trust"
                                    data-login="<?php echo esc_attr( $admin['login'] ); ?>">
                                    <span class="dashicons dashicons-yes"></span> Confiable
                                </button>
                                <?php endif; ?>
                                <button class="peaa-btn peaa-btn--degrade button"
                                    data-uid="<?php echo esc_attr( $uid ); ?>" data-action="degrade"
                                    data-login="<?php echo esc_attr( $admin['login'] ); ?>">
                                    <span class="dashicons dashicons-arrow-down-alt"></span> Degradar
                                </button>
                                <button class="peaa-btn peaa-btn--block button"
                                    data-uid="<?php echo esc_attr( $uid ); ?>" data-action="block"
                                    data-login="<?php echo esc_attr( $admin['login'] ); ?>">
                                    <span class="dashicons dashicons-lock"></span> Bloquear
                                </button>
                                <button class="peaa-btn peaa-btn--delete button"
                                    data-uid="<?php echo esc_attr( $uid ); ?>" data-action="delete"
                                    data-login="<?php echo esc_attr( $admin['login'] ); ?>">
                                    <span class="dashicons dashicons-trash"></span> Eliminar
                                </button>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="peaa-no-action">—</span>
                        <?php endif; ?>
                    </td>

                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>


    <?php // ── Usuarios gestionados ───────────────────────────────────────── ?>
    <?php if ( ! empty( $managed ) ) : ?>
    <div class="peaa-section peaa-section--managed">
        <div class="peaa-section__header">
            <h2><span class="dashicons dashicons-backup"></span> Usuarios gestionados</h2>
            <p class="peaa-section__desc">Usuarios sobre los que ya aplicaste una acción y que ya no aparecen en la lista principal de administradores. Se mantienen visibles por trazabilidad y para poder revertir cambios si fue necesario.</p>
        </div>
        <div class="peaa-table-wrap">
            <table class="peaa-table wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Estado actual</th>
                        <th>Última acción</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $managed as $item ) : ?>
                    <tr id="peaa-managed-row-<?php echo esc_attr( $item['user_id'] ); ?>">
                        <td><?php echo esc_html( $item['user_id'] ); ?></td>
                        <td>
                            <strong><?php echo esc_html( $item['login'] ); ?></strong>
                            <?php if ( ! $item['exists'] ) : ?>
                                <div class="peaa-row-sub">El usuario ya no existe en WordPress</div>
                            <?php elseif ( ! empty( $item['roles'] ) ) : ?>
                                <div class="peaa-row-sub">Rol actual: <?php echo esc_html( implode( ', ', $item['roles'] ) ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $item['email'] ); ?></td>
                        <td>
                            <span class="peaa-badge <?php echo esc_attr( $item['action'] === 'block' ? 'peaa-badge--danger' : ( $item['action'] === 'degrade' ? 'peaa-badge--warn' : 'peaa-badge--ok' ) ); ?>">
                                <span class="dashicons <?php echo esc_attr( $item['action'] === 'block' ? 'dashicons-lock' : ( $item['action'] === 'degrade' ? 'dashicons-arrow-down-alt' : 'dashicons-yes' ) ); ?>"></span>
                                <?php echo esc_html( $item['action_label'] ); ?>
                            </span>
                        </td>
                        <td>
                            <div><?php echo esc_html( $item['action_date'] ? date_i18n( 'd/m/Y H:i', strtotime( $item['action_date'] ) ) : '—' ); ?></div>
                            <div class="peaa-row-sub">Por: <?php echo esc_html( $item['executor'] ); ?></div>
                        </td>
                        <td class="peaa-actions">
                            <?php if ( $item['revertible'] ) : ?>
                                <button class="peaa-btn peaa-btn--revert button"
                                    data-uid="<?php echo esc_attr( $item['user_id'] ); ?>" data-action="revert"
                                    data-login="<?php echo esc_attr( $item['login'] ); ?>">
                                    <span class="dashicons dashicons-undo"></span> Revertir
                                </button>
                            <?php else : ?>
                                <span class="peaa-no-action">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php // ── Capacidades inconsistentes ──────────────────────────────────── ?>
    <?php if ( ! empty( $data['hidden_admins'] ) ) : ?>
    <div class="peaa-section peaa-section--warn">
        <div class="peaa-section__header">
            <h2><span class="dashicons dashicons-warning"></span> Usuarios con capacidades inconsistentes</h2>
            <p class="peaa-section__desc">Usuarios cuyo rol o capacidades no siguen el patrón estándar. Pueden indicar manipulación directa de la base de datos.</p>
        </div>
        <div class="peaa-table-wrap">
            <table class="peaa-table wp-list-table widefat fixed striped">
                <thead><tr><th>ID</th><th>Login</th><th>Problema detectado</th></tr></thead>
                <tbody>
                    <?php foreach ( $data['hidden_admins'] as $h ) : ?>
                    <tr>
                        <td><?php echo esc_html( $h['id'] ); ?></td>
                        <td><strong><?php echo esc_html( $h['login'] ); ?></strong></td>
                        <td><span class="peaa-badge peaa-badge--warn"><?php echo esc_html( $h['label'] ); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php // ── Hooks de visibilidad ──────────────────────────────────────────── ?>
    <?php if ( ! empty( $vis_data ) ) : ?>
    <div class="peaa-section peaa-section--warn">
        <div class="peaa-section__header">
            <h2><span class="dashicons dashicons-code-standards"></span> Hooks de visibilidad detectados</h2>
            <p class="peaa-section__desc">Hooks que pueden modificar qué usuarios son visibles o qué capacidades tienen. Requieren revisión manual.</p>
        </div>
        <div class="peaa-table-wrap">
            <table class="peaa-table wp-list-table widefat fixed striped">
                <thead><tr><th>Hook</th><th>Tipo</th><th>Origen</th><th>Archivo</th><th>Posible efecto</th></tr></thead>
                <tbody>
                    <?php foreach ( $vis_data as $v ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $v['hook'] ); ?></code></td>
                        <td><?php echo esc_html( $v['type'] ); ?></td>
                        <td><?php echo esc_html( $v['slug'] ); ?></td>
                        <td><span class="peaa-path" title="<?php echo esc_attr( $v['file'] ); ?>">…<?php echo esc_html( substr( $v['file'], -55 ) ); ?></span></td>
                        <td class="peaa-text-dim"><?php echo esc_html( peaa_hook_label( $v['hook'] ) ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php // ── Footer de contacto ────────────────────────────────────────────── ?>
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
            <a href="https://plusexpert.cl/plugins/" target="_blank" rel="noopener noreferrer">
                <span class="dashicons dashicons-info-outline"></span> Soporte PlusExpert
            </a>
        </div>
    </div>

    <?php
}
