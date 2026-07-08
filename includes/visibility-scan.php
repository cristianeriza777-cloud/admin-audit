<?php
/**
 * visibility-scan.php — Detecta hooks PHP que podrían estar ocultando usuarios admins.
 *
 * Adaptado desde plusexpert-agent/includes/admin_visibility.php.
 * Busca add_action/add_filter sobre hooks críticos de usuarios en plugins y temas activos.
 *
 * @package PlusExpertAdminAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Escanear plugins activos y tema activo buscando hooks que oculten usuarios.
 *
 * @return array Lista de coincidencias encontradas con hook, archivo y snippet.
 */
function peaa_scan_visibility(): array {
    $results        = [];
    $start_time     = microtime( true );
    $max_time       = 5.0;        // segundos máx.
    $max_file_size  = 500 * 1024; // 500 KB por archivo
    $max_files      = 50;         // archivos por componente

    // Hooks que permiten ocultar usuarios o manipular capacidades
    $hooks = [
        'pre_user_query',      // Modifica la consulta de usuarios antes de ejecutarse
        'user_has_cap',        // Altera capacidades en runtime
        'map_meta_cap',        // Redirige meta-capabilities
        'editable_roles',      // Oculta roles del desplegable
        'views_users',         // Filtra las vistas del listado de usuarios
        'all_plugins',         // Puede ocultar plugins del listado
    ];

    $regex = '/(add_action|add_filter)\s*\(\s*[\'"](' . implode( '|', $hooks ) . ')[\'"]/i';

    $components = [];

    // 1. MU Plugins (cargan automáticamente, vector frecuente de backdoors)
    if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
        $components[] = [ 'path' => WPMU_PLUGIN_DIR, 'type' => 'mu-plugin', 'slug' => 'mu-plugins' ];
    }

    // 2. Plugins activos
    if ( defined( 'WP_PLUGIN_DIR' ) && is_dir( WP_PLUGIN_DIR ) ) {
        foreach ( get_option( 'active_plugins', [] ) as $plugin_path ) {
            $plugin_dir = dirname( WP_PLUGIN_DIR . '/' . $plugin_path );
            if ( $plugin_dir !== WP_PLUGIN_DIR ) {
                $components[] = [
                    'path' => $plugin_dir,
                    'type' => 'plugin',
                    'slug' => dirname( $plugin_path ),
                ];
            } else {
                $components[] = [
                    'path'    => WP_PLUGIN_DIR . '/' . $plugin_path,
                    'type'    => 'plugin',
                    'slug'    => $plugin_path,
                    'is_file' => true,
                ];
            }
        }
    }

    // 3. Tema activo (functions.php)
    $theme_functions = get_template_directory() . '/functions.php';
    if ( file_exists( $theme_functions ) ) {
        $components[] = [
            'path'    => $theme_functions,
            'type'    => 'theme',
            'slug'    => get_template(),
            'is_file' => true,
        ];
    }

    foreach ( $components as $component ) {
        if ( ( microtime( true ) - $start_time ) > $max_time ) {
            break;
        }

        $files = ! empty( $component['is_file'] )
            ? [ $component['path'] ]
            : peaa_get_php_files( $component['path'], $max_files );

        foreach ( $files as $file ) {
            if ( ( microtime( true ) - $start_time ) > $max_time ) {
                break 2;
            }

            if ( ! file_exists( $file ) || filesize( $file ) > $max_file_size ) {
                continue;
            }

            $content = @file_get_contents( $file );
            if ( $content === false ) {
                continue;
            }

            if ( preg_match_all( $regex, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $matches[0] as $index => $match ) {
                    $hook    = $matches[2][ $index ][0];
                    $offset  = $match[1];
                    $start   = max( 0, $offset - 30 );
                    $snippet = substr( $content, $start, 160 );

                    $results[] = [
                        'hook'    => $hook,
                        'type'    => $component['type'],
                        'slug'    => $component['slug'] ?? $component['type'],
                        'file'    => str_replace( ABSPATH, '', $file ),
                        'snippet' => trim( $snippet ),
                    ];
                }
            }
        }
    }

    return $results;
}

/**
 * Obtener archivos PHP de un directorio de forma recursiva con límite.
 *
 * @param string $dir
 * @param int    $limit
 * @return string[]
 */
function peaa_get_php_files( string $dir, int $limit ): array {
    if ( ! is_dir( $dir ) ) {
        return [];
    }

    $files = [];
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() && $file->getExtension() === 'php' ) {
                $files[] = $file->getPathname();
                if ( count( $files ) >= $limit ) {
                    break;
                }
            }
        }
    } catch ( Exception $e ) {
        // Fallback para entornos con restricciones de filesystem
        $files = glob( $dir . '/*.php' ) ?: [];
    }

    return $files;
}

/**
 * Obtener etiqueta legible para cada hook de visibilidad.
 *
 * @param string $hook
 * @return string
 */
function peaa_hook_label( string $hook ): string {
    $labels = [
        'pre_user_query' => 'Modifica consultas de usuarios en la base de datos',
        'user_has_cap'   => 'Altera capacidades de usuario en tiempo real',
        'map_meta_cap'   => 'Redirige meta-capacidades',
        'editable_roles' => 'Modifica los roles visibles en el panel',
        'views_users'    => 'Filtra las vistas del listado de usuarios',
        'all_plugins'    => 'Puede ocultar plugins del listado de plugins',
    ];
    return $labels[ $hook ] ?? $hook;
}
