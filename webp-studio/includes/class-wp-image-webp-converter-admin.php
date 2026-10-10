<?php
/**
 * Admin screen for WebP Studio.
 *
 * @package webp-studio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the tabbed settings screen and owns its assets.
 *
 * The conversion engine stays in WP_Image_WebP_Converter. This class only reads
 * state from it and talks to its AJAX endpoints; it never converts anything.
 */
class WP_Image_WebP_Converter_Admin {

    /**
     * Engine instance.
     *
     * @var WP_Image_WebP_Converter
     */
    private $plugin;

    /**
     * Admin page hook returned by add_options_page().
     *
     * @var string
     */
    private $hook_suffix = '';

    /**
     * Constructor.
     *
     * @param WP_Image_WebP_Converter $plugin Engine instance.
     */
    public function __construct( $plugin ) {
        $this->plugin = $plugin;

        add_action( 'admin_menu', array( $this, 'add_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    /**
     * Registers the settings page.
     */
    public function add_page() {
        $hook = add_options_page(
            __( 'WebP Studio', 'webp-studio' ),
            __( 'WebP Studio', 'webp-studio' ),
            'manage_options',
            'webp-studio',
            array( $this, 'render' )
        );

        // add_options_page() returns the hook suffix, but only when a page is actually
        // added; it returns false if the menu entry was rejected. Guarding keeps
        // enqueue() from comparing against an empty string and loading on every screen.
        $this->hook_suffix = is_string( $hook ) ? $hook : '';
    }

    /**
     * Adds a settings link next to the plugin on the Plugins screen.
     *
     * This is a filter, not an action, and WordPress only fires it once the page is
     * actually being rendered, so the capability check below always has a real user to
     * evaluate. The link is prepended so it sits before "Desactivar", where the eye
     * lands first.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public function add_settings_action_link( $links ) {
        if ( ! is_array( $links ) ) {
            $links = array();
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return $links;
        }

        // Never emit a link to a page that was not registered. A rejected add_options_page()
        // would otherwise leave a dead URL in the Plugins list.
        if ( '' === $this->hook_suffix ) {
            return $links;
        }

        array_unshift(
            $links,
            sprintf(
                '<a href="%s">%s</a>',
                esc_url( admin_url( 'options-general.php?page=webp-studio' ) ),
                esc_html__( 'Ajustes', 'webp-studio' )
            )
        );

        return $links;
    }

    /**
     * Loads assets on this screen only.
     *
     * Scoped by the page hook suffix, so neither file is requested anywhere else in
     * wp-admin.
     */
    public function enqueue( $hook_suffix ) {
        if ( $hook_suffix !== $this->hook_suffix ) {
            return;
        }

        $dir = plugin_dir_path( __DIR__ );
        $url = plugin_dir_url( __DIR__ );

        wp_enqueue_style(
            'wp-image-webp-converter-admin',
            $url . 'assets/css/webp-studio-admin.css',
            array(),
            $this->version( $dir . 'assets/css/webp-studio-admin.css' )
        );

        wp_enqueue_script(
            'wp-image-webp-converter-admin',
            $url . 'assets/js/webp-studio-admin.js',
            array( 'jquery' ),
            $this->version( $dir . 'assets/js/webp-studio-admin.js' ),
            true
        );

        wp_localize_script(
            'wp-image-webp-converter-admin',
            'wpsStudio',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'webp_converter_for_news' ),
                'l10n'    => $this->strings(),
            )
        );
    }

    /**
     * File modification time as cache-busting version.
     *
     * @param string $path Absolute path.
     * @return string
     */
    private function version( $path ) {
        return file_exists( $path ) ? (string) filemtime( $path ) : '1.0.0';
    }

    /**
     * Translatable strings used by the admin JavaScript.
     *
     * @return array
     */
    private function strings() {
        return array(
            'stateIdle'            => __( 'Sin trabajo en curso', 'webp-studio' ),
            'statePreparing'       => __( 'Preparando', 'webp-studio' ),
            'stateQueued'          => __( 'En cola', 'webp-studio' ),
            'stateRunning'         => __( 'En curso', 'webp-studio' ),
            'stateDone'            => __( 'Completado', 'webp-studio' ),
            'stateDoneWithIssues'  => __( 'Completado con incidencias', 'webp-studio' ),
            'stateFailed'          => __( 'Fallido', 'webp-studio' ),
            'stateUnknown'         => __( 'Estado no verificable', 'webp-studio' ),
            'runProgress'          => __( 'Procesadas %1$d de %2$d imágenes.', 'webp-studio' ),
            'convertN'             => __( 'Convertir %d imágenes', 'webp-studio' ),
            'convertedAll'         => __( 'Biblioteca convertida', 'webp-studio' ),
            'never'                => __( 'Nunca', 'webp-studio' ),
            'connectionLost'       => __( 'Se perdió la conexión con el servidor. El trabajo puede seguir en curso; reintentando.', 'webp-studio' ),
            'networkError'         => __( 'No se pudo contactar con el servidor.', 'webp-studio' ),
            'genericError'         => __( 'Error desconocido.', 'webp-studio' ),
            'noActivity'           => __( 'Todavía no hay actividad registrada.', 'webp-studio' ),
            'showMore'             => __( 'Ver más registros', 'webp-studio' ),
            'previewGenerating'    => __( 'Generando ejemplo…', 'webp-studio' ),
            'previewFailed'        => __( 'No se pudo generar la vista previa.', 'webp-studio' ),
            'previewSaving'        => __( 'Ahorras %1$d%% frente al mismo JPEG (%2$s a %3$s).', 'webp-studio' ),
            'previewHeavier'       => __( 'Pesa %1$d%% más que el mismo JPEG (%2$s). Baja la calidad si quieres aligerarlo.', 'webp-studio' ),
            'previewSame'          => __( 'Mismo tamaño que el JPEG equivalente.', 'webp-studio' ),
            'confirmConvert'       => __( 'Se convertirán %d imágenes con la configuración guardada.', 'webp-studio' ),
            'confirmIrreversible'  => __( 'La operación eliminará los originales de forma permanente. Confirma que tienes una copia de seguridad.', 'webp-studio' ),
            'confirmQuality'       => __( 'Calidad guardada: %d.', 'webp-studio' ),
            'confirmDeleteOrphans' => __( 'Se eliminarán archivos de forma permanente. Esta acción no se puede deshacer. ¿Continuar?', 'webp-studio' ),
            'confirmClearLog'      => __( 'Se vaciará el registro de actividad. ¿Continuar?', 'webp-studio' ),
            'unsavedChanges'       => __( 'Tienes cambios sin guardar. La conversión usará la configuración guardada, no estos valores.', 'webp-studio' ),
            'enabled'              => __( 'Activado', 'webp-studio' ),
            'disabled'             => __( 'Desactivado', 'webp-studio' ),
            'enable'               => __( 'Activar', 'webp-studio' ),
            'disable'              => __( 'Desactivar', 'webp-studio' ),

            // Rewrite queue.
            'queueConfirmEnqueue'  => __( 'Se revisará todo el contenido del sitio y se añadirá a la cola lo que quede pendiente. Es una operación de solo lectura: no reescribe nada todavía. ¿Continuar?', 'webp-studio' ),
            'queueConfirmProcess'  => __( 'Se reescribirán las URLs guardadas en el contenido. Antes de cada cambio se guarda el valor anterior, así que todo es reversible. ¿Continuar?', 'webp-studio' ),
            'queueConfirmRollback' => __( 'Se restaurará el valor anterior de la última entrada reescrita. ¿Continuar?', 'webp-studio' ),
            'queuePurgeWarning'    => __( 'ATENCIÓN: al purgar el historial se pierde la posibilidad de deshacer las reescrituras. Las imágenes seguirán en WebP y el contenido ya no volverá atrás por sí solo. Es la única acción de esta pantalla que no se puede deshacer.', 'webp-studio' ),
            'queuePurgeConfirm'    => __( 'Confirmo que entiendo que se pierde la reversión. ¿Purgar el historial?', 'webp-studio' ),
            'queuePurgeOriginalsConfirm' => __( 'Se revisará todo el contenido del sitio y se borrará cada original que nada siga usando. Los originales que todavía estén referenciados se conservarán. Esta acción no se puede deshacer. ¿Continuar?', 'webp-studio' ),
            'queueNotReady'        => __( 'Las tablas de la cola no están creadas.', 'webp-studio' ),
            'queueProgress'        => __( 'Revisadas %1$d de %2$d entradas.', 'webp-studio' ),
            'queueSummary'         => __( '%1$d omitidas, %2$d con error. Escaneo: %3$s.', 'webp-studio' ),
            'queueLast'            => __( 'Último cambio: entrada #%1$d, %2$d URLs, %3$s.', 'webp-studio' ),
            'queueScanPhase'       => array(
                'post'     => __( 'entradas', 'webp-studio' ),
                'postmeta' => __( 'campos personalizados', 'webp-studio' ),
                'option'   => __( 'opciones', 'webp-studio' ),
                'done'     => __( 'escaneo completado', 'webp-studio' ),
            ),
        );
    }

    /**
     * Registers options, sections and fields.
     */
    public function register_settings() {
        register_setting(
            'wp_image_webp_converter_options_group',
            WP_Image_WebP_Converter::OPTION_NAME,
            array( $this->plugin, 'sanitize_options' )
        );

        add_settings_section(
            'wp_image_webp_converter_main_section',
            __( 'Configuración de conversión', 'webp-studio' ),
            '__return_false',
            'webp-studio'
        );

        // Title is emitted by each callback as its own label, so the API title column
        // is left empty to avoid a duplicated heading.
        $fields = array(
            'wp_image_webp_enabled'        => 'field_enabled_cb',
            'wp_image_webp_quality'         => 'field_quality_cb',
            'wp_image_webp_delete_original' => 'field_delete_original_cb',
        );

        foreach ( $fields as $id => $callback ) {
            add_settings_field(
                $id,
                '',
                array( $this, $callback ),
                'webp-studio',
                'wp_image_webp_converter_main_section'
            );
        }
    }

    /**
     * Automatic conversion field.
     */
    public function field_enabled_cb() {
        $options = $this->plugin->get_options();
        ?>
        <div class="wps-field">
            <div class="wps-field__row">
                <div>
                    <label class="wps-field__label" for="wps-enabled">
                        <?php esc_html_e( 'Conversión automática', 'webp-studio' ); ?>
                    </label>
                    <span class="wps-field__hint">
                        <?php esc_html_e( 'Aplica a las imágenes que se suban en adelante. No convierte la biblioteca existente.', 'webp-studio' ); ?>
                    </span>
                </div>
                <input type="checkbox" id="wps-enabled"
                    name="<?php echo esc_attr( WP_Image_WebP_Converter::OPTION_NAME ); ?>[enabled]"
                    value="1" <?php checked( ! empty( $options['enabled'] ) ); ?> />
            </div>
        </div>
        <?php
    }

    /**
     * Quality field: slider, number input and presets.
     *
     * Presets are UI shortcuts only. They never write to the option until the form
     * is saved, so an unsaved preset cannot leak into a background run.
     */
    public function field_quality_cb() {
        $options = $this->plugin->get_options();
        $quality = isset( $options['quality'] ) ? (int) $options['quality'] : 80;
        $quality = max( 0, min( 100, $quality ) );
        $presets = array(
            65 => __( 'Más compresión', 'webp-studio' ),
            85 => __( 'Equilibrado', 'webp-studio' ),
            95 => __( 'Alta calidad', 'webp-studio' ),
        );
        ?>
        <div class="wps-field">
            <label class="wps-field__label" for="wps-quality-slider">
                <?php esc_html_e( 'Calidad WebP', 'webp-studio' ); ?>
            </label>

            <div class="wps-quality">
                <div class="wps-quality__slider">
                    <input type="range" id="wps-quality-slider" data-control="quality-slider"
                        min="0" max="100" step="1" value="<?php echo esc_attr( $quality ); ?>"
                        aria-describedby="wps-quality-hint" />
                    <div class="wps-quality__ticks">
                        <span><?php esc_html_e( 'Menor tamaño', 'webp-studio' ); ?></span>
                        <span><?php esc_html_e( 'Mayor calidad', 'webp-studio' ); ?></span>
                    </div>
                </div>
                <output class="wps-quality__value" data-control="quality-value"
                    for="wps-quality-slider"><?php echo esc_html( $quality ); ?></output>
                <input type="number" class="wps-quality__number" data-control="quality-number"
                    name="<?php echo esc_attr( WP_Image_WebP_Converter::OPTION_NAME ); ?>[quality]"
                    min="0" max="100" step="1" value="<?php echo esc_attr( $quality ); ?>"
                    aria-label="<?php esc_attr_e( 'Calidad WebP, valor numérico', 'webp-studio' ); ?>" />
            </div>

            <div class="wps-presets">
                <?php foreach ( $presets as $value => $label ) : ?>
                    <button type="button" class="wps-btn" data-preset="<?php echo esc_attr( $value ); ?>">
                        <?php echo esc_html( $label . ' · ' . $value ); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <span class="wps-field__hint" id="wps-quality-hint">
                <?php esc_html_e( 'Una calidad más alta produce archivos más pesados. No se garantiza un porcentaje concreto de ahorro: depende de cada imagen.', 'webp-studio' ); ?>
            </span>
        </div>
        <?php
    }

    /**
     * Originals policy field.
     */
    public function field_delete_original_cb() {
        $options = $this->plugin->get_options();
        $delete  = ! empty( $options['delete_original'] );
        ?>
        <div class="wps-field">
            <span class="wps-field__label" id="wps-originals-label">
                <?php esc_html_e( 'Archivos originales', 'webp-studio' ); ?>
            </span>

            <label class="wps-radio">
                <input type="radio" name="<?php echo esc_attr( WP_Image_WebP_Converter::OPTION_NAME ); ?>[delete_original]"
                    value="0" data-control="delete-original"
                    <?php checked( ! $delete ); ?> />
                <?php esc_html_e( 'Conservar originales', 'webp-studio' ); ?>
            </label>

            <label class="wps-radio">
                <input type="radio" name="<?php echo esc_attr( WP_Image_WebP_Converter::OPTION_NAME ); ?>[delete_original]"
                    value="1" data-control="delete-original"
                    <?php checked( $delete ); ?> />
                <?php esc_html_e( 'Eliminar después de convertir', 'webp-studio' ); ?>
            </label>

            <div class="wps-notice wps-notice--warning" id="wps-originals-warning" <?php echo $delete ? '' : 'hidden'; ?>>
                <p>
                    <?php esc_html_e( 'La eliminación es permanente: una imagen WebP no puede volver a convertirse al formato original. El conversor solo borra el original después de validar el archivo convertido y sus miniaturas, pero la decisión es tuya. Haz una copia de seguridad antes de aplicarlo a tu biblioteca.', 'webp-studio' ); ?>
                </p>
            </div>

            <span class="wps-field__hint">
                <?php esc_html_e( 'Guardar esta opción no convierte ni elimina nada por sí solo.', 'webp-studio' ); ?>
            </span>
        </div>
        <?php
    }

    /**
     * Live quality preview.
     */
    public function render_preview() {
        $sample = $this->plugin->get_sample_image();
        ?>
        <div class="wps-card">
            <h2><?php esc_html_e( 'Vista previa', 'webp-studio' ); ?></h2>
            <p class="wps-card__hint">
                <?php esc_html_e( 'Compara el resultado con la calidad elegida, usando una imagen del propio plugin.', 'webp-studio' ); ?>
            </p>

            <?php if ( $sample ) : ?>
                <div class="wps-preview">
                    <div class="wps-preview__grid">
                        <figure class="wps-preview__figure">
                            <img src="<?php echo esc_url( $sample['url'] ); ?>"
                                alt="<?php echo esc_attr( $sample['label'] ); ?>" />
                            <figcaption class="wps-preview__caption">
                                <?php esc_html_e( 'Original (PNG)', 'webp-studio' ); ?>
                            </figcaption>
                        </figure>

                        <figure class="wps-preview__figure" data-preview="figure">
                            <img data-preview="image" src="<?php echo esc_url( $sample['url'] ); ?>"
                                alt="<?php esc_attr_e( 'Vista previa en WebP', 'webp-studio' ); ?>" />
                            <figcaption class="wps-preview__caption">
                                <span class="wps-badge">WebP</span>
                                <span data-preview="size">—</span>
                            </figcaption>
                        </figure>
                    </div>

                    <p class="wps-preview__delta" data-preview="delta"></p>
                    <p class="wps-preview__status" data-preview="status" role="status"></p>
                </div>
            <?php else : ?>
                <p class="wps-preview__status">
                    <?php esc_html_e( 'Vista previa no disponible: no se pudo generar la imagen de ejemplo.', 'webp-studio' ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Library summary, shared by every tab.
     *
     * @param string $class Extra class for the wrapper.
     */
    public function render_summary( $class = '' ) {
        $status = $this->plugin->build_status_payload();
        $percent = $status['coverage'];

        echo '<div class="wps-card ' . esc_attr( $class ) . '">';
        echo '<h2>' . esc_html__( 'Resumen de la biblioteca', 'webp-studio' ) . '</h2>';

        echo '<div class="wps-grid wps-grid--thirds">';

        printf(
            '<div class="wps-metric"><span class="wps-metric__value" data-metric="total">%s</span><span class="wps-metric__label">%s</span></div>',
            esc_html( $status['total'] ),
            esc_html__( 'Total', 'webp-studio' )
        );

        printf(
            '<div class="wps-metric wps-metric--done"><span class="wps-metric__value" data-metric="converted">%s</span><span class="wps-metric__label">%s</span></div>',
            esc_html( $status['converted'] ),
            esc_html__( 'En WebP', 'webp-studio' )
        );

        printf(
            '<div class="wps-metric wps-metric--pending"><span class="wps-metric__value" data-metric="pending">%s</span><span class="wps-metric__label">%s</span></div>',
            esc_html( $status['pending'] ),
            esc_html__( 'Pendientes', 'webp-studio' )
        );

        echo '</div>';

        printf(
            '<div class="wps-coverage"><div class="wps-coverage__fill%s" style="width:%1$d%%"></div></div>',
            $percent,
            $percent >= 100 ? ' is-complete' : ''
        );

        printf(
            '<div class="wps-summary__line"><span>%s</span><span class="wps-summary__percent">%1$d%%</span></div>',
            esc_html__( 'de la biblioteca está en WebP', 'webp-studio' ),
            $percent
        );

        echo '<p class="wps-card__hint">' . esc_html__( 'Los tres contadores se refieren a adjuntos de imagen, no a archivos ni miniaturas.', 'webp-studio' ) . '</p>';

        echo '</div>';
    }

    /**
     * Renders the screen.
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'webp-studio' ) );
        }

        $options  = $this->plugin->get_options();
        $status   = $this->plugin->build_status_payload();
        $history  = $this->plugin->get_history_entries( 5 );
        $webp_ok  = $this->plugin->editor_supports_webp();
        ?>
        <div class="wrap">
            <div class="wps-admin">

                <header class="wps-header">
                    <h1><?php esc_html_e( 'WebP Studio', 'webp-studio' ); ?></h1>
                    <p><?php esc_html_e( 'Optimización de imágenes, sin complicaciones.', 'webp-studio' ); ?></p>
                    <span class="wps-header__badge">
                        <?php
                        printf(
                            /* translators: %s: plugin version. */
                            esc_html__( 'WebP Studio · versión %s', 'webp-studio' ),
                            esc_html( $this->plugin->get_version() )
                        );
                        ?>
                    </span>
                </header>

                <?php if ( ! $webp_ok ) : ?>
                    <div class="wps-notice wps-notice--danger">
                        <p><?php esc_html_e( 'Este servidor no puede generar imágenes WebP. El plugin no puede convertir nada hasta que se instale una biblioteca compatible.', 'webp-studio' ); ?></p>
                    </div>
                <?php elseif ( ! empty( $options['delete_original'] ) ) : ?>
                    <div class="wps-notice wps-notice--info">
                        <p><?php esc_html_e( 'Al convertir se elimina el archivo original de forma permanente. Mantén una copia de seguridad de tu carpeta de subidas antes de convertir imágenes existentes.', 'webp-studio' ); ?></p>
                    </div>
                <?php endif; ?>

                <nav class="wps-nav" role="tablist" aria-label="<?php esc_attr_e( 'Secciones de WebP Studio', 'webp-studio' ); ?>">
                    <button type="button" class="wps-nav__tab" role="tab" data-tab="config"
                        id="wps-tab-config" aria-controls="wps-panel-config"
                        aria-selected="true" tabindex="0">
                        <?php esc_html_e( 'Configuración', 'webp-studio' ); ?>
                    </button>
                    <button type="button" class="wps-nav__tab" role="tab" data-tab="library"
                        id="wps-tab-library" aria-controls="wps-panel-library"
                        aria-selected="false" tabindex="-1">
                        <?php esc_html_e( 'Biblioteca', 'webp-studio' ); ?>
                        <span class="wps-nav__count" data-tab-count="pending"><?php echo esc_html( $status['pending'] ); ?></span>
                    </button>
                    <button type="button" class="wps-nav__tab" role="tab" data-tab="diagnostics"
                        id="wps-tab-diagnostics" aria-controls="wps-panel-diagnostics"
                        aria-selected="false" tabindex="-1">
                        <?php esc_html_e( 'Diagnóstico', 'webp-studio' ); ?>
                    </button>
                </nav>

                <!-- Configuración -->
                <div class="wps-panel" data-panel="config" id="wps-panel-config" role="tabpanel"
                    aria-labelledby="wps-tab-config" tabindex="0">
                    <?php $this->render_summary( 'wps-summary--config' ); ?>

                    <div class="wps-grid wps-grid--split">
                        <div class="wps-card">
                            <h2><?php esc_html_e( 'Configuración de conversión', 'webp-studio' ); ?></h2>

                            <form method="post" action="options.php" data-form="settings">
                                <?php
                                settings_fields( 'wp_image_webp_converter_options_group' );
                                do_settings_sections( 'webp-studio' );
                                ?>

                                <p class="wps-feedback" data-role="dirty" role="status" style="display:none"></p>

                                <div class="wps-actions">
                                    <?php submit_button( __( 'Guardar ajustes', 'webp-studio' ), 'primary', 'submit', false ); ?>
                                    <p class="wps-actions__note">
                                        <?php esc_html_e( 'Guardar solo persiste la configuración. No inicia ninguna conversión.', 'webp-studio' ); ?>
                                    </p>
                                </div>
                            </form>

                            <script>
                            ( function () {
                                var warn = document.getElementById( 'wps-originals-warning' );
                                var radios = document.querySelectorAll( '[data-control="delete-original"]' );
                                Array.prototype.forEach.call( radios, function ( radio ) {
                                    radio.addEventListener( 'change', function () {
                                        warn.hidden = radio.value !== '1';
                                    } );
                                } );
                            } )();
                            </script>
                        </div>

                        <?php $this->render_preview(); ?>
                    </div>

                    <?php $this->render_orphans(); ?>
                </div>

                <!-- Biblioteca -->
                <div class="wps-panel" data-panel="library" id="wps-panel-library" role="tabpanel"
                    aria-labelledby="wps-tab-library" tabindex="0" hidden>
                    <?php $this->render_summary( 'wps-summary--library' ); ?>

                    <div class="wps-grid wps-grid--split">
                        <div class="wps-card">
                            <h2><?php esc_html_e( 'Convertir biblioteca existente', 'webp-studio' ); ?></h2>
                            <p class="wps-card__hint">
                                <?php esc_html_e( 'Usa la configuración guardada. Si hay cambios sin guardar en Configuración, no se aplicarán aquí.', 'webp-studio' ); ?>
                            </p>

                            <div class="wps-actions">
                                <button type="button" class="wps-btn wps-btn--primary" data-action="convert">
                                    <?php
                                    printf(
                                        /* translators: %d: pending count. */
                                        esc_html__( 'Convertir %d imágenes', 'webp-studio' ),
                                        (int) $status['pending']
                                    );
                                    ?>
                                </button>
                            </div>
                            <p class="wps-feedback" data-role="convert-feedback" role="status"></p>

                            <p class="wps-card__hint">
                                <?php esc_html_e( 'El trabajo continúa en segundo plano aunque cierres esta página. Si el servidor no recibe visitas, el avance puede retrasarse porque depende del planificador del sistema.', 'webp-studio' ); ?>
                            </p>
                        </div>

                        <?php $this->render_queue(); ?>

                        <div class="wps-card">
                            <h2><?php esc_html_e( 'Progreso del trabajo', 'webp-studio' ); ?></h2>

                            <div class="wps-status" data-state="idle">
                                <span class="wps-status__dot" aria-hidden="true"></span>
                                <span class="wps-status__label"><?php esc_html_e( 'Sin trabajo en curso', 'webp-studio' ); ?></span>
                            </div>
                            <p class="wps-status__message" aria-live="polite"><?php esc_html_e( 'Sin trabajo en curso.', 'webp-studio' ); ?></p>

                            <div class="wps-job">
                                <div class="wps-job__track">
                                    <div class="wps-job__fill"></div>
                                </div>
                                <p class="wps-job__text"><?php esc_html_e( 'Procesadas 0 de 0 imágenes.', 'webp-studio' ); ?></p>
                            </div>

                            <table class="widefat striped" style="margin-top:16px">
                                <tbody>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Convertidas en esta ejecución', 'webp-studio' ); ?></th>
                                        <td data-run="processed"><?php echo esc_html( $status['processed'] ); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Omitidas en esta ejecución', 'webp-studio' ); ?></th>
                                        <td data-run="skipped"><?php echo esc_html( $status['skipped'] ); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php esc_html_e( 'Última actualización', 'webp-studio' ); ?></th>
                                        <td data-run="updated"><?php echo esc_html( $status['updated'] ? $status['updated'] : __( 'Nunca', 'webp-studio' ) ); ?></td>
                                    </tr>
                                </tbody>
                            </table>

                            <p class="wps-card__hint">
                                <?php esc_html_e( 'Estas cifras describen el último trabajo ejecutado. No son el inventario de la biblioteca.', 'webp-studio' ); ?>
                            </p>
                            <p class="wps-card__hint">
                                <?php esc_html_e( 'No hay pausa ni cancelación: el motor procesa lotes pequeños de forma continua y el cursor se guarda entre ejecuciones.', 'webp-studio' ); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Diagnóstico -->
                <div class="wps-panel" data-panel="diagnostics" id="wps-panel-diagnostics" role="tabpanel"
                    aria-labelledby="wps-tab-diagnostics" tabindex="0" hidden>
                    <?php $this->render_summary( 'wps-summary--diagnostics' ); ?>

                    <div class="wps-grid wps-grid--split">
                        <div class="wps-card">
                            <h2><?php esc_html_e( 'Actividad reciente', 'webp-studio' ); ?></h2>

                            <?php if ( ! empty( $history ) ) : ?>
                                <ul class="wps-log">
                                    <?php foreach ( $history as $entry ) : ?>
                                        <li class="wps-log__entry" data-level="<?php echo esc_attr( $entry['level'] ); ?>">
                                            <span class="wps-log__meta"><?php echo esc_html( $entry['time'] ); ?></span>
                                            <?php echo esc_html( $entry['message'] ); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p class="wps-log__empty"><?php esc_html_e( 'Todavía no hay actividad registrada.', 'webp-studio' ); ?></p>
                            <?php endif; ?>

                            <div class="wps-log__more">
                                <button type="button" class="wps-btn" data-action="more-log" data-page="1" style="display:none">
                                    <?php esc_html_e( 'Ver más registros', 'webp-studio' ); ?>
                                </button>
                            </div>

                            <div class="wps-actions">
                                <button type="button" class="wps-btn wps-btn--danger" data-action="clear-log">
                                    <?php esc_html_e( 'Vaciar registro', 'webp-studio' ); ?>
                                </button>
                            </div>
                        </div>

                        <div class="wps-card">
                            <h2><?php esc_html_e( 'Registro de actividad', 'webp-studio' ); ?></h2>
                            <p class="wps-card__hint">
                                <?php esc_html_e( 'Guarda cada conversión y cada omisión con su motivo.', 'webp-studio' ); ?>
                            </p>

                            <div class="wps-actions" style="margin-top:0">
                                <button type="button"
                                    class="wps-btn <?php echo ! empty( $options['activity_log'] ) ? 'wps-btn--primary' : ''; ?>"
                                    data-action="toggle-activity"
                                    aria-pressed="<?php echo ! empty( $options['activity_log'] ) ? 'true' : 'false'; ?>">
                                    <?php echo ! empty( $options['activity_log'] )
                                        ? esc_html__( 'Desactivar', 'webp-studio' )
                                        : esc_html__( 'Activar', 'webp-studio' ); ?>
                                </button>
                                <span class="wps-toggle-state" data-role="activity-state">
                                    <?php echo ! empty( $options['activity_log'] )
                                        ? esc_html__( 'Activado', 'webp-studio' )
                                        : esc_html__( 'Desactivado', 'webp-studio' ); ?>
                                </span>
                            </div>

                            <p class="wps-feedback" data-role="activity-feedback" role="status"></p>

                            <p class="wps-card__hint">
                                <?php esc_html_e( 'Los errores y avisos siempre se registran aunque el registro esté desactivado. Al desactivarlo solo se omiten las conversiones correctas. Las entradas se conservan en la base de datos y no se borran al actualizar el plugin.', 'webp-studio' ); ?>
                            </p>

                            <?php if ( ! empty( $status['skips'] ) ) : ?>
                                <h3><?php esc_html_e( 'Motivos de omisión registrados', 'webp-studio' ); ?></h3>
                                <ul>
                                    <?php foreach ( $status['skips'] as $reason ) : ?>
                                        <li>
                                            <?php
                                            printf(
                                                /* translators: 1: count, 2: reason description. */
                                                esc_html__( '%1$d — %2$s', 'webp-studio' ),
                                                (int) $reason['count'],
                                                esc_html( $reason['message'] )
                                            );
                                            ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <h3><?php esc_html_e( 'Mantenimiento', 'webp-studio' ); ?></h3>
                            <p class="wps-card__hint">
                                <?php esc_html_e( 'La conversión en segundo plano depende del planificador del sistema. Si el sitio recibe pocas visitas, el avance puede demorarse.', 'webp-studio' ); ?>
                            </p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * URL rewrite queue card.
     *
     * Every figure shown here is read from the queue tables rather than from a
     * local variable, so the screen and the AJAX endpoint can never disagree
     * about what has been done.
     */
    private function render_queue() {
        $queue = $this->plugin->queue();
        $state = $queue->get_progress();

        $counts  = $state['counts'];
        $pending = (int) $counts['pending'];

        // Done plus skipped accounts for every entry the worker has touched.
        // Progress is expressed against that, not against pending, so a value
        // that stays pending forever because its WebP does not exist yet never
        // reads as "0% done".
        $handled = (int) $counts['done'] + (int) $counts['skipped'] + (int) $counts['failed'];
        $total   = $handled + $pending;
        $percent = $total > 0 ? (int) round( ( $handled / $total ) * 100 ) : 0;

        $purge = isset( $state['purge'] ) ? $state['purge'] : array(
            'pending'     => 0,
            'waiting'     => 0,
            'ready'       => false,
            'blocker'     => 'scan_incomplete',
            'openRewrite' => 0,
        );
        ?>
        <div class="wps-card wps-queue" data-queue="card">
            <h2><?php esc_html_e( 'URLs dentro del contenido', 'webp-studio' ); ?></h2>
            <p class="wps-card__hint">
                <?php esc_html_e( 'Al convertir, el archivo original se conserva hasta que se compruebe que ya nadie lo usa. Esta cola busca esas referencias dentro de los posts, los campos personalizados y las opciones, y cambia solo la extensión de las que tienen un adjunto local cuyo WebP existe en el servidor. Las imágenes alojadas en otros dominios no se tocan.', 'webp-studio' ); ?>
            </p>

            <?php if ( ! $state['tablesReady'] ) : ?>
                <div class="wps-notice wps-notice--warning">
                    <p><?php esc_html_e( 'Las tablas de la cola todavía no están creadas. Pulsa "Crear tablas" para intentarlo.', 'webp-studio' ); ?></p>
                </div>
            <?php endif; ?>

            <div class="wps-grid wps-grid--thirds">
                <div class="wps-metric wps-metric--pending">
                    <span class="wps-metric__value" data-queue="pending"><?php echo esc_html( $pending ); ?></span>
                    <span class="wps-metric__label"><?php esc_html_e( 'Pendientes', 'webp-studio' ); ?></span>
                </div>
                <div class="wps-metric wps-metric--done">
                    <span class="wps-metric__value" data-queue="done"><?php echo esc_html( (int) $counts['done'] ); ?></span>
                    <span class="wps-metric__label"><?php esc_html_e( 'Reescritas', 'webp-studio' ); ?></span>
                </div>
                <div class="wps-metric">
                    <span class="wps-metric__value" data-queue="replacements"><?php echo esc_html( (int) $state['replacements'] ); ?></span>
                    <span class="wps-metric__label"><?php esc_html_e( 'URLs cambiadas', 'webp-studio' ); ?></span>
                </div>
            </div>

            <div class="wps-job">
                <div class="wps-job__track">
                    <div class="wps-job__fill" data-queue="fill" style="width:<?php echo esc_attr( $percent ); ?>%"></div>
                </div>
                <p class="wps-job__text" data-queue="progress">
                    <?php
                    printf(
                        /* translators: 1: handled entries, 2: total entries. */
                        esc_html__( 'Revisadas %1$d de %2$d entradas.', 'webp-studio' ),
                        (int) $handled,
                        (int) $total
                    );
                    ?>
                </p>
            </div>

            <div class="wps-actions">
                <button type="button" class="wps-btn" data-queue-action="install" <?php echo $state['tablesReady'] ? 'hidden' : ''; ?>>
                    <?php esc_html_e( 'Crear tablas', 'webp-studio' ); ?>
                </button>
                <button type="button" class="wps-btn wps-btn--primary" data-queue-action="enqueue">
                    <?php esc_html_e( 'Escanear y encolar', 'webp-studio' ); ?>
                </button>
                <button type="button" class="wps-btn" data-queue-action="process" <?php disabled( 0, $pending ); ?>>
                    <?php esc_html_e( 'Reescribir ahora', 'webp-studio' ); ?>
                </button>
            </div>

            <div class="wps-actions">
                <label class="wps-radio">
                    <input type="checkbox" data-queue-option="dry_run" />
                    <?php esc_html_e( 'Simulación: contar y reportar sin escribir nada', 'webp-studio' ); ?>
                </label>
                <label class="wps-radio">
                    <input type="checkbox" data-queue-option="restart" />
                    <?php esc_html_e( 'Empezar el escaneo desde el principio', 'webp-studio' ); ?>
                </label>
                <label class="wps-radio">
                    <input type="checkbox" data-queue-option="include_revisions" />
                    <?php esc_html_e( 'Incluir revisiones', 'webp-studio' ); ?>
                </label>
            </div>

            <p class="wps-feedback" data-queue="feedback" role="status"></p>

            <p class="wps-card__hint" data-queue="summary">
                <?php
                printf(
                    /* translators: 1: skipped count, 2: failed count, 3: scan phase. */
                    esc_html__( '%1$d omitidas, %2$d con error. Escaneo: %3$s.', 'webp-studio' ),
                    (int) $counts['skipped'],
                    (int) $counts['failed'],
                    esc_html( $this->queue_phase_label( $state ) )
                );
                ?>
            </p>

            <?php if ( ! empty( $state['reasons'] ) ) : ?>
                <table class="widefat striped">
                    <tbody>
                        <?php foreach ( $state['reasons'] as $reason ) : ?>
                            <tr>
                                <th scope="row"><?php echo esc_html( $this->queue_reason_label( $reason['reason'] ) ); ?></th>
                                <td><?php echo esc_html( number_format_i18n( $reason['count'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Originales pendientes de borrar', 'webp-studio' ); ?></h3>

            <p class="wps-card__hint">
                <?php esc_html_e( 'Borrar un original ahora dejaría rotas las imágenes que un post todavía apunta a: la URL .jpg se escribe en el contenido cuando se guarda la entrada, que es después de convertir la imagen. Por eso el borrado no es inmediato. Primero se revisa todo el contenido, se reescriben las URLs que quedan y solo después se comprueba, original por original, que nada lo sigue usando. Lo que no se puede comprobar no se borra.', 'webp-studio' ); ?>
            </p>

            <div class="wps-grid wps-grid--thirds">
                <div class="wps-metric wps-metric--pending">
                    <span class="wps-metric__value" data-queue="purge-pending"><?php echo esc_html( (int) $purge['pending'] ); ?></span>
                    <span class="wps-metric__label"><?php esc_html_e( 'Pendientes de borrar', 'webp-studio' ); ?></span>
                </div>
                <div class="wps-metric">
                    <span class="wps-metric__value" data-queue="purge-waiting"><?php echo esc_html( (int) $purge['waiting'] ); ?></span>
                    <span class="wps-metric__label"><?php esc_html_e( 'En espera porque se siguen usando', 'webp-studio' ); ?></span>
                </div>
                <div class="wps-metric">
                    <span class="wps-metric__value" data-queue="purge-deleted"><?php echo esc_html( isset( $state['purgeLogTotal'] ) ? (int) $state['purgeLogTotal'] : 0 ); ?></span>
                    <span class="wps-metric__label"><?php esc_html_e( 'Ya eliminados', 'webp-studio' ); ?></span>
                </div>
            </div>

            <?php
            // Both notices are always rendered and toggled with `hidden`, never
            // omitted. A card that only prints them when they apply cannot show
            // them later without a full page reload, and the whole point is that
            // the button explains its own refusal the moment it is pressed.
            $blocked = ( ! empty( $purge['pending'] ) && empty( $purge['ready'] ) );
            ?>
            <div class="wps-notice wps-notice--warning" data-queue="purge-blocked" <?php echo $blocked ? '' : 'hidden'; ?>>
                <p>
                    <?php
                        if ( 'rewrite_pending' === $purge['blocker'] ) {
                            printf(
                                /* translators: %d: pending rewrite entries. */
                                esc_html__( 'Quedan %d entradas de reescritura sin terminar. Vuelve a escanear desde el principio y ejecuta "Reescribir ahora": hasta que la reescritura no esté hecha no se puede comprobar ningún original.', 'webp-studio' ),
                                (int) $purge['openRewrite']
                            );
                        } elseif ( 'content_changed' === $purge['blocker'] ) {
                            esc_html_e( 'Se acaba de reescribir contenido, así que la revisión anterior ya no describe lo que hay guardado. Vuelve a escanear desde el principio y después podrás verificar los originales.', 'webp-studio' );
                        } elseif ( 'options_skipped' === $purge['blocker'] ) {
                            esc_html_e( 'No se puede comprobar nada todavía: el escaneo se hizo sin revisar las opciones del sitio. Vuelve a escanear con la revisión de opciones activada.', 'webp-studio' );
                        } elseif ( ! empty( $purge['blocker'] ) && 'scan_incomplete' !== $purge['blocker'] ) {
                            printf(
                                /* translators: %s: technical reason. */
                                esc_html__( 'La última revisión del contenido falló (%s), así que no se borrará nada. Vuelve a escanear para rehacerla.', 'webp-studio' ),
                                esc_html( $purge['blocker'] )
                            );
                        } else {
                            esc_html_e( 'Aún no se ha terminado de revisar todo el contenido del sitio, así que no se borrará nada todavía. Pulsa "Escanear y encolar" y deja que termine antes de verificar.', 'webp-studio' );
                        }
                    ?>
                </p>
            </div>

            <p class="wps-card__hint" data-queue="purge-open" <?php echo empty( $purge['openRewrite'] ) ? 'hidden' : ''; ?>>
                <?php
                printf(
                    /* translators: %d: pending rewrite entries. */
                    esc_html__( 'Quedan %d entradas de reescritura sin terminar. Mientras las haya no se borra ningún original, porque un contenido que todavía apunta al .jpg se quedaría sin imagen.', 'webp-studio' ),
                    (int) $purge['openRewrite']
                );
                ?>
            </p>

            <div class="wps-actions">
                <button type="button" class="wps-btn wps-btn--primary" data-queue-action="purge-originals"
                    <?php disabled( 0, (int) $purge['pending'] ); ?>>
                    <?php esc_html_e( 'Verificar y borrar originales', 'webp-studio' ); ?>
                </button>
            </div>

            <p class="wps-card__hint" data-queue="refs-at">
                <?php
                if ( ! empty( $state['refs']['at'] ) ) {
                    printf(
                        /* translators: %s: date of the last complete content review. */
                        esc_html__( 'Última revisión completa del contenido: %s. Lo que se guardó después de esa fecha todavía no está comprobado.', 'webp-studio' ),
                        esc_html( $state['refs']['at'] )
                    );
                } else {
                    esc_html_e( 'Todavía no se ha hecho ninguna revisión completa del contenido, así que no hay nada comprobado que permita borrar.', 'webp-studio' );
                }
                ?>
            </p>

            <p class="wps-card__hint">
                <?php esc_html_e( 'Cada original se comprueba por separado contra todo el contenido del sitio, incluidas las miniaturas: si el nombre en miniatura (-300x200) está en algún sitio, el original entero se conserva. Esta operación no borra el historial de la cola ni deshace reescrituras anteriores.', 'webp-studio' ); ?>
            </p>

            <p class="wps-feedback" data-queue="purge-feedback" role="status"></p>

            <h3><?php esc_html_e( 'Reversión', 'webp-studio' ); ?></h3>
            <p class="wps-card__hint">
                <?php esc_html_e( 'Cada cambio guarda el valor anterior completo, así que deshacer restaura el contenido exactamente como estaba. El historial es permanente hasta que lo purgues.', 'webp-studio' ); ?>
            </p>

            <?php if ( $state['last'] ) : ?>
                <p class="wps-card__hint" data-queue="last">
                    <?php
                    printf(
                        /* translators: 1: entry id, 2: replacements count, 3: date. */
                        esc_html__( 'Último cambio: entrada #%1$d, %2$d URLs, %3$s.', 'webp-studio' ),
                        (int) $state['last']['queueId'],
                        (int) $state['last']['replacements'],
                        esc_html( $state['last']['createdAt'] )
                    );
                    ?>
                </p>
            <?php endif; ?>

            <div class="wps-actions">
                <button type="button" class="wps-btn" data-queue-action="rollback" <?php disabled( 0, (int) $state['logOpen'] ); ?>>
                    <?php esc_html_e( 'Deshacer la última entrada', 'webp-studio' ); ?>
                </button>
                <button type="button" class="wps-btn wps-btn--danger" data-queue-action="purge-step-1">
                    <?php esc_html_e( 'Purgar historial', 'webp-studio' ); ?>
                </button>
            </div>

            <div class="wps-notice wps-notice--warning" data-queue="purge" hidden>
                <p data-queue="purge-text"><?php esc_html_e( 'ATENCIÓN: al purgar el historial se pierde la posibilidad de deshacer las reescrituras. Las imágenes seguirán en WebP y el contenido no volverá atrás por sí solo. Es la única acción de esta pantalla que no se puede deshacer.', 'webp-studio' ); ?></p>
                <div class="wps-actions">
                    <button type="button" class="wps-btn wps-btn--danger" data-queue-action="purge-step-2">
                        <?php esc_html_e( 'Sí, purgar el historial', 'webp-studio' ); ?>
                    </button>
                    <button type="button" class="wps-btn" data-queue-action="purge-cancel">
                        <?php esc_html_e( 'Cancelar', 'webp-studio' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Spanish label for a scan phase key.
     *
     * @param array $state Progress payload.
     * @return string
     */
    private function queue_phase_label( array $state ) {
        $labels = array(
            'post'     => __( 'entradas', 'webp-studio' ),
            'postmeta' => __( 'campos personalizados', 'webp-studio' ),
            'option'   => __( 'opciones', 'webp-studio' ),
            'done'     => __( 'terminado', 'webp-studio' ),
        );

        $phase = isset( $state['phase'] ) ? (string) $state['phase'] : 'done';

        return isset( $labels[ $phase ] ) ? $labels[ $phase ] : $phase;
    }

    /**
     * Spanish label for a machine-readable skip or failure reason.
     *
     * Every reason the queue can record is named here. An unnamed reason would
     * render as its raw key, which tells the reader nothing.
     *
     * @param string $reason Reason key.
     * @return string
     */
    private function queue_reason_label( $reason ) {
        $labels = array(
            'rewritten'         => __( 'Reescritas correctamente', 'webp-studio' ),
            'no_local_attachment' => __( 'Sin adjunto local (imagen de otro dominio)', 'webp-studio' ),
            'no_webp_file'      => __( 'El archivo WebP todavía no existe', 'webp-studio' ),
            'unserializable'    => __( 'Valor serializado que no se puede leer', 'webp-studio' ),
            'duplicate_meta'    => __( 'El campo está repetido en el mismo post', 'webp-studio' ),
            'no_paths'          => __( 'Sin rutas de imagen', 'webp-studio' ),
            'no_change'         => __( 'Nada que cambiar', 'webp-studio' ),
            'empty'             => __( 'Valor vacío', 'webp-studio' ),
            'missing'           => __( 'El contenido ya no existe', 'webp-studio' ),
            'rolled_back'       => __( 'Deshechas manualmente', 'webp-studio' ),
            'log_failed'        => __( 'No se pudo guardar el valor anterior', 'webp-studio' ),
            'write_failed'      => __( 'No se pudo escribir el nuevo valor', 'webp-studio' ),
        );

        $reason = (string) $reason;

        return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
    }

    /**
     * Orphan review panel.
     */
    private function render_orphans() {
        ?>
        <div class="wps-card">
            <h2><?php esc_html_e( 'Miniaturas huérfanas', 'webp-studio' ); ?></h2>
            <p class="wps-card__hint">
                <?php esc_html_e( 'Archivos de imágenes que ya no referencia ningún adjunto. Revisa la lista antes de eliminar nada.', 'webp-studio' ); ?>
            </p>

            <div class="wps-actions" style="margin-top:0">
                <button type="button" class="wps-btn" data-action="review-orphans">
                    <?php esc_html_e( 'Revisar archivos', 'webp-studio' ); ?>
                </button>
            </div>

            <p class="wps-feedback" data-orphans="result" role="status"></p>

            <div data-panel="orphans" style="display:none">
                <ul class="wps-card__hint" data-orphans="list" style="max-height:200px;overflow:auto"></ul>
                <div class="wps-actions">
                    <button type="button" class="wps-btn wps-btn--danger" data-action="delete-orphans" disabled>
                        <?php esc_html_e( 'Eliminar los archivos listados', 'webp-studio' ); ?>
                    </button>
                    <button type="button" class="wps-btn" data-action="cancel-orphans">
                        <?php esc_html_e( 'Cancelar', 'webp-studio' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
}