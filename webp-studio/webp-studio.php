<?php
/**
 * Plugin Name:       WebP Studio
 * Plugin URI:        https://example.com/webp-studio
 * Description:       Convierte automáticamente JPEG, PNG y GIF a WebP y elimina el original, haciendo que WordPress sirva únicamente WebP. La conversión de imágenes existentes se ejecuta en segundo plano mediante WP-Cron.
 * Version:           2.1.4
 * Requires at least: 5.0
 * Requires PHP:      7.0
 * Author:            Assistant
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webp-studio
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direct access guard.
}

/**
 * Converts attachments to WebP and keeps the attachment record consistent.
 */
class WP_Image_WebP_Converter {

    const OPTION_NAME      = 'wp_image_webp_converter_settings';
    const OPTION_TOTAL     = 'wp_image_webp_converter_total_images';
    const OPTION_CURSOR    = 'wp_image_webp_converter_last_processed_id';
    const OPTION_PROCESSED = 'wp_image_webp_converter_processed_count';
    const OPTION_FAILED    = 'wp_image_webp_converter_failed_count';
    const OPTION_ACTIVE    = 'wp_image_webp_converter_processing_active';

    /**
     * Timestamp of the last batch, shown as "last updated" in the interface.
     */
    const OPTION_UPDATED = 'wp_image_webp_converter_updated_at';

    const CRON_HOOK          = 'wp_image_webp_converter_batch';
    const LOCK_TRANSIENT     = 'wp_image_webp_converter_batch_lock';
    const LOCK_TTL           = 300;
    const CRON_BATCH_SIZE    = 5;
    const REQUEST_BATCH_SIZE = 3;

    const CONVERTED_META = '_wp_image_webp_converted';
    const MAX_PIXELS     = 40000000;

    /**
     * Visible activity history. Not autoloaded: it is only read on the settings page.
     */
    const OPTION_HISTORY = 'wp_image_webp_converter_history';
    const HISTORY_LIMIT  = 200;

    /**
     * The plugin's own reference image, used by the quality preview. It is generated
     * on activation and lives in the uploads root so the browser can load it directly.
     * It is not an attachment and never appears in the media library.
     */
    const SAMPLE_NAME   = 'webp-converter-sample.png';
    const SAMPLE_WIDTH  = 1000;
    const SAMPLE_HEIGHT = 800;

    /**
     * Folder inside uploads holding generated quality previews.
     */
    const PREVIEW_DIR = 'webp-converter-previews';

    /**
     * Number of skipped reasons the batch runner remembers, so the settings page can
     * explain a run that did not reach 100%.
     */
    const OPTION_SKIPS = 'wp_image_webp_converter_skip_reasons';
    const SKIPS_LIMIT  = 20;

    const SUPPORTED_MIMES = array( 'image/jpeg', 'image/png', 'image/gif' );

    /**
     * Reentrancy guard. The bulk path calls wp_update_attachment_metadata() itself,
     * so without this the upload filter would recurse into a second conversion.
     *
     * @var bool
     */
    private $converting = false;

    /**
     * Per-request options cache. Avoids repeated get_option() calls from filters
     * that can run hundreds of times per request.
     *
     * @var array|null
     */
    private $options_cache = null;

    /**
     * Set when the stored settings could not be read as an array.
     *
     * Kept per request so the screen can say so out loud instead of quietly behaving as
     * if every option were off. Null means "not checked yet, or readable".
     *
     * @var string|null
     */
    private $settings_problem = null;

    /**
     * Attachment IDs queued during this request by queue_attachment().
     *
     * @var array
     */
    private $queued_uploads = array();

    /**
     * Rewrite queue for URLs stored inside content.
     *
     * The queue owns its own tables, its own lock and its own cron hook, so the
     * conversion engine above stays exactly as it was.
     *
     * @var WP_Image_WebP_Converter_Queue|null
     */
    private $queue = null;

    public function __construct() {
        // Fail closed rather than silently doing nothing: an admin who cannot delete
        // source files would otherwise accumulate WebP duplicates forever.
        add_action( 'admin_init', array( $this, 'check_filesystem_writable' ) );
        // Upload path. The ID is queued on add_attachment and converted on shutdown,
// once core has finished generating and storing the JPEG metadata. Converting
// earlier is unsafe: wp_create_image_subsizes() writes metadata once per size
// while still reading the source file, and core performs a final write of its
// own afterwards that would erase the WebP metadata this plugin produced.
        add_action( 'add_attachment', array( $this, 'queue_attachment' ), 10, 1 );
        add_action( 'shutdown', array( $this, 'process_queued_uploads' ), 5 );

        // Narrow URL-level fallback only. There is deliberately no get_attached_file
        // filter: hijacking it breaks wp_delete_attachment() and the image editor.
        add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 10, 2 );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        // The admin screen lives in its own class and file.
        add_action( 'plugins_loaded', array( $this, 'boot_admin' ) );

        // Background processing.
        add_action( 'init', array( $this, 'maybe_reschedule_cron' ), 5 );
        add_action( self::CRON_HOOK, array( $this, 'process_cron_batch' ) );

        // AJAX.
        add_action( 'wp_ajax_wp_image_webp_converter_get_status', array( $this, 'ajax_get_status' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_run_batch', array( $this, 'ajax_run_batch' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_cleanup_orphans', array( $this, 'ajax_cleanup_orphans' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_clear_history', array( $this, 'ajax_clear_history' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_get_history', array( $this, 'ajax_get_history' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_preview', array( $this, 'ajax_preview' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_toggle_activity', array( $this, 'ajax_toggle_activity_log' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_start_run', array( $this, 'ajax_start_run' ) );

        // URL rewrite queue. Loaded in the same request that loads the plugin,
        // for the same reason the rest of this constructor runs here: a hook
        // registered from a plugins_loaded callback is not guaranteed to run.
        add_action( 'plugins_loaded', array( $this, 'boot_queue' ), 11 );

        add_action( 'wp_ajax_wp_image_webp_converter_queue_status', array( $this, 'ajax_queue_status' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_queue_install', array( $this, 'ajax_queue_install' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_queue_enqueue', array( $this, 'ajax_queue_enqueue' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_queue_process', array( $this, 'ajax_queue_process' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_queue_rollback', array( $this, 'ajax_queue_rollback' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_queue_purge', array( $this, 'ajax_queue_purge' ) );
        add_action( 'wp_ajax_wp_image_webp_converter_queue_purge_originals', array( $this, 'ajax_queue_purge_originals' ) );
    }

    /**
     * Loads and instantiates the rewrite queue.
     *
     * Kept apart from the engine on purpose: the queue is a repair job with its
     * own lifecycle, and nothing in it can take the converter down.
     *
     * @return WP_Image_WebP_Converter_Queue|null
     */
    public function boot_queue() {
        if ( $this->queue instanceof WP_Image_WebP_Converter_Queue ) {
            return $this->queue;
        }

        require_once __DIR__ . '/includes/class-wp-image-webp-converter-queue.php';

        $this->queue = new WP_Image_WebP_Converter_Queue();

        return $this->queue;
    }

    /**
     * The rewrite queue, booting it on first use.
     *
     * @return WP_Image_WebP_Converter_Queue
     */
    public function queue() {
        if ( ! $this->queue instanceof WP_Image_WebP_Converter_Queue ) {
            $this->boot_queue();
        }

        return $this->queue;
    }

    /**
     * Boots the admin screen.
     *
     * @return WP_Image_WebP_Converter_Admin
     */
    public function boot_admin() {
        require_once __DIR__ . '/includes/class-wp-image-webp-converter-admin.php';

        $admin = new WP_Image_WebP_Converter_Admin( $this );

        // Adds a "Ajustes" link next to the plugin on the Plugins screen.
        //
        // Without this the only route to the settings is the Ajustes submenu, which is
        // easy to miss and was repeatedly reported as "I cannot find the plugin". The
        // Plugins list is where someone looks first, so the link belongs there too.
        add_filter(
            'plugin_action_links_' . plugin_basename( __FILE__ ),
            array( $admin, 'add_settings_action_link' )
        );

        return $admin;
    }

    /**
     * Sanitizes settings.
     *
     * Persists configuration only. It never starts work: the conversion action is a
     * separate endpoint, so saving the form can never trigger a bulk run.
     *
     * @param array $input Raw form input.
     * @return array
     */
    public function sanitize_options( $input ) {
        $previous = $this->get_options();

        $sanitized = array(
            'enabled'          => ! empty( $input['enabled'] ) ? 1 : 0,
            // Clamp on the raw value, before absint(): absint(-20) is 20, so validating
            // afterwards would silently turn a negative into a usable quality.
            'quality'          => isset( $input['quality'] )
                ? max( 0, min( 100, (int) $input['quality'] ) )
                : 80,
            'delete_original'  => isset( $input['delete_original'] ) ? absint( $input['delete_original'] ) : 0,
            // Retired: conversion is now started by its own action, not by a checkbox.
            'process_existing' => 0,
            // Carried over, not read from the form: toggled from the diagnostics card.
            'activity_log'     => ! empty( $previous['activity_log'] ) ? 1 : 0,
        );

        // Warn before a destructive run is even possible, not after.
        if ( ! empty( $sanitized['delete_original'] ) && empty( $previous['delete_original'] ) ) {
            $this->history(
                'warning',
                __( 'Has activado la eliminación de originales. Al convertir se perderán de forma permanente y no podrán recuperarse.', 'webp-studio' )
            );
        }

        return $sanitized;
    }

    /**
     * Plugin version, read from the file header so it has one source of truth.
     *
     * @return string
     */
    public function get_version() {
        $header = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

        return isset( $header['Version'] ) ? $header['Version'] : '0';
    }

    /**
     * Notices the admin when uploads cannot be written to.
     *
     * Conversion can still succeed when uploads are read-only, but the original
     * cannot be removed, so the "delete original" setting cannot be honoured.
     */
    public function check_filesystem_writable() {
        static $checked = false;

        if ( $checked || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $checked = true;

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) || ! wp_is_writable( $uploads['basedir'] ) ) {
            add_action(
                'admin_notices',
                function () {
                    echo '<div class="notice notice-warning"><p>' .
                        esc_html__( 'WP Image WebP Converter: la carpeta de subidas no tiene permisos de escritura. La conversión funciona, pero los originales no se podrán eliminar.', 'webp-studio' ) .
                        '</p></div>';
                }
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Records a visible history entry.
     *
     * Every outcome the plugin reaches is written here, not just successes. A skipped
     * image with no recorded reason is indistinguishable from a silent failure, so
     * skips are logged too. The table is capped, and only kept while debugging, so it
     * cannot grow without bound.
     *
     * @param string $level   One of info|success|warning|error.
     * @param string $message Human-readable message.
     * @param array  $context Optional extra data (attachment id, filename, reason).
     */
    private function history( $level, $message, $context = array() ) {
        $entry = array(
            'time'    => current_time( 'mysql' ),
            'level'   => $level,
            'message' => $message,
        );

        if ( ! empty( $context ) ) {
            $entry['context'] = $context;
        }

        if ( ! $this->history_enabled() ) {
            $this->log( '[' . $level . '] ' . $message );

            return;
        }

        $entries   = get_option( self::OPTION_HISTORY, array() );
        $entries   = is_array( $entries ) ? $entries : array();
        $entries[] = $entry;

        // Keep the tail; the newest entries are the useful ones.
        if ( count( $entries ) > self::HISTORY_LIMIT ) {
            $entries = array_slice( $entries, -self::HISTORY_LIMIT );
        }

        update_option( self::OPTION_HISTORY, $entries, false );
    }

    /**
     * Whether the visible history is recorded.
     *
     * Errors are always recorded. Routine informational entries require WP_DEBUG, so a
     * production site does not accumulate noise.
     *
     * @return bool
     */
    private function history_enabled() {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            return true;
        }

        $options = $this->get_options();

        return ! empty( $options['activity_log'] );
    }

    /**
     * Returns the recorded history, newest first.
     *
     * @param int $limit Maximum entries.
     * @return array
     */
    public function get_history_entries( $limit = 50 ) {
        $entries = get_option( self::OPTION_HISTORY, array() );
        $entries = is_array( $entries ) ? $entries : array();

        return array_reverse( array_slice( $entries, -$limit ) );
    }

    /**
     * Writes a log line only when WP_DEBUG is enabled.
     *
     * @param string $message Message to log.
     */
    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WP Image WebP Converter: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Returns the plugin options, cached for the current request.
     *
     * @return array
     */
    public function get_options() {
        if ( null !== $this->options_cache ) {
            return $this->options_cache;
        }

        $stored = get_option( self::OPTION_NAME, null );

        if ( is_array( $stored ) ) {
            // Fill in anything an older version never wrote, so a partially saved
            // record does not read as "off" for every missing key.
            $this->options_cache = array_merge( self::default_options(), $stored );

            return $this->options_cache;
        }

        if ( null === $stored ) {
            // Never written: a partial install, or the option was deleted by hand.
            // The documented defaults apply, rather than everything silently off.
            $this->options_cache = self::default_options();
            add_option( self::OPTION_NAME, $this->options_cache );

            $this->report_settings_problem(
                __( 'Los ajustes no estaban guardados, así que se usaron los valores por defecto.', 'webp-studio' ),
                'missing'
            );

            return $this->options_cache;
        }

        // Present but not an array. Anything can put a string or a serialized object
        // here: a wp option update from the command line, a botched import, another
        // plugin sharing the name. Silently returning array() makes the whole plugin
        // look disabled with nothing in the log to explain why, so it is reported.

        // The cache must be assigned before reporting: report_settings_problem()
        // writes to the activity history, which calls history_enabled(), which calls
        // get_options(). Without this the report would recurse.
        $this->options_cache = self::default_options();

        $this->report_settings_problem(
            sprintf(
                /* translators: %s: the PHP type actually stored. */
                __( 'Los ajustes guardados no se pudieron leer (son de tipo %s), así que se usaron los valores por defecto. Vuelve a guardarlos desde esta pantalla.', 'webp-studio' ),
                gettype( $stored )
            ),
            'corrupt'
        );

        return $this->options_cache;
    }

    /**
     * The settings a fresh install starts from.
     *
     * Single source of truth: activation writes these, and get_options() falls back to
     * them. Previously each had its own inline copy, so the two could disagree.
     *
     * @return array
     */
    public static function default_options() {
        return array(
            'enabled'         => 1,
            'quality'         => 80,
            'delete_original' => 1,
            'activity_log'    => 1,
        );
    }

    /**
     * Records that the stored settings were unusable.
     *
     * Goes to the debug log always, and to the activity history when that is enabled.
     * Called only on the first read of a request, so a filter that reads options
     * hundreds of times cannot flood the log.
     *
     * @param string $message Human-readable explanation.
     * @param string $kind     Machine-readable kind: 'corrupt' or 'missing'.
     */
    private function report_settings_problem( $message, $kind ) {
        $this->settings_problem = $message;

        $this->log( 'Settings problem (' . $kind . '): ' . $message );

        $this->history(
            'warning',
            $message,
            array( 'settings' => $kind )
        );
    }

    /**
     * The unreadable-settings explanation for this request, or null.
     *
     * @return string|null
     */
    public function settings_problem() {
        // Reading the options is what triggers the detection, so a screen asking here
        // is enough to have it populated.
        $this->get_options();

        return $this->settings_problem;
    }

    /**
     * Whether conversion is enabled.
     *
     * @return bool
     */
    private function is_enabled() {
        $options = $this->get_options();

        return ! empty( $options['enabled'] );
    }

    /**
     * Absolute path for an attachment-relative path.
     *
     * @param string $relative Path relative to the uploads base dir.
     * @return string
     */
    private function abs_path( $relative ) {
        $uploads = wp_upload_dir();

        return trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/' );
    }

    /**
     * Whether conversion may run in the current request.
     *
     * This is the structural guarantee that a visitor page view or an admin page
     * load can never trigger batch conversion work.
     *
     * @return bool
     */
    private function batch_context_ok() {
        return ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_ajax();
    }

    /* ------------------------------------------------------------------ */
    /* Upload path                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Queues a freshly created attachment for deferred conversion.
     *
     * Only records the ID. No file work happens here: core is still generating
     * thumbnails for the uploaded file at this point.
     *
     * @param int $attachment_id Attachment post ID.
     */
    public function queue_attachment( $attachment_id ) {
        $this->queued_uploads[] = (int) $attachment_id;
    }

    /**
     * Converts queued uploads once the request has finished its media work.
     *
     * This is the only safe moment. wp_create_image_subsizes() writes attachment
     * metadata once per generated size while it is still reading the source file,
     * and media_handle_upload()/media_handle_sideload() then perform a final
     * wp_update_attachment_metadata() write of their own. Converting any earlier
     * means deleting the source out from under that code, and core's final write
     * then erases the WebP metadata that this plugin produced.
     *
     * On shutdown nothing writes afterwards, so the WebP record is the one that sticks.
     */
    public function process_queued_uploads() {
        if ( empty( $this->queued_uploads ) ) {
            return;
        }

        $queued               = array_unique( $this->queued_uploads );
        $this->queued_uploads = array();

        if ( ! $this->is_enabled() ) {
            return;
        }

        $options = $this->get_options();

        foreach ( $queued as $attachment_id ) {
            $post = get_post( $attachment_id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                continue;
            }

            if ( 'image/webp' === $post->post_mime_type ) {
                continue;
            }

            if ( ! in_array( $post->post_mime_type, self::SUPPORTED_MIMES, true ) ) {
                continue;
            }

            if ( get_post_meta( $attachment_id, self::CONVERTED_META, true ) ) {
                continue;
            }

            // Core's own metadata for the uploaded file is now complete and final,
            // so it is a reliable source for the thumbnails that must be removed.
            // Passing it explicitly matters: on this path it is the only place the
            // original file size is known, since the file is deleted during conversion.
            $existing = wp_get_attachment_metadata( $attachment_id );
            $metadata = $this->convert_attachment( $attachment_id, $options, $existing );

            if ( is_array( $metadata ) ) {
                wp_update_attachment_metadata( $attachment_id, $metadata );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Conversion core                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Converts one attachment to WebP and rewrites its record.
     *
     * The replacement file and its full metadata set are produced BEFORE anything
     * is deleted, so a failure at any step leaves the original file and its
     * metadata completely intact.
     *
     * @param int        $attachment_id Attachment post ID.
     * @param array      $options       Plugin options.
     * @param array|null $old_metadata  Previous metadata; read from DB when null.
     * @return array|false New WebP metadata, or false on any failure.
     */
    private function convert_attachment( $attachment_id, $options, $old_metadata = null ) {
        // Guard the whole conversion. wp_generate_attachment_metadata() reaches image
        // helpers that can re-enter the upload filter, so a narrow guard around only the
        // metadata writes would still recurse.
        $this->converting = true;
        try {
            return $this->convert_attachment_locked( $attachment_id, $options, $old_metadata );
        } finally {
            $this->converting = false;
        }
    }

    /**
     * Performs the conversion. Callers must hold the reentrancy guard.
     *
     * @param int        $attachment_id Attachment post ID.
     * @param array      $options       Plugin options.
     * @param array|null $old_metadata  Previous metadata; read from DB when null.
     * @return array|false
     */
    private function convert_attachment_locked( $attachment_id, $options, $old_metadata = null ) {
        $attachment_id = (int) $attachment_id;
        $relative      = get_post_meta( $attachment_id, '_wp_attached_file', true );

        // Every early return records why, so a run that does not reach 100% can always
        // be explained to the user instead of appearing as a silent failure.
        if ( ! $relative ) {
            $this->skip( $attachment_id, 'no_file', __( 'El registro no tiene archivo asociado.', 'webp-studio' ) );
            return false;
        }

        $source = $this->abs_path( $relative );
        if ( ! file_exists( $source ) ) {
            $this->skip( $attachment_id, 'file_missing', __( 'El archivo original no existe en el disco.', 'webp-studio' ) );
            return false;
        }

        $mime = get_post_mime_type( $attachment_id );

        // Capture the source size now: the original is deleted further down, so it
        // cannot be measured afterwards. This is what makes the reported saving real
        // rather than a comparison of a file against itself.
        $source_size = (int) filesize( $source );

        // Animated GIFs cannot survive conversion: no editor reads past frame 1.
        if ( 'image/gif' === $mime && $this->is_animated_gif( $source ) ) {
            $this->skip( $attachment_id, 'animated_gif', __( 'GIF animado: se conserva para no perder los fotogramas.', 'webp-studio' ) );
            return false;
        }

        $info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $info ) {
            $this->skip( $attachment_id, 'unreadable', __( 'El archivo no se pudo leer como imagen.', 'webp-studio' ) );
            return false;
        }

        $pixels = (int) $info[0] * (int) $info[1];
        if ( $pixels > self::MAX_PIXELS ) {
            $this->skip(
                $attachment_id,
                'too_large',
                sprintf(
                    /* translators: %s: megapixel count. */
                    __( 'Supera el límite de seguridad (%s MP).', 'webp-studio' ),
                    number_format_i18n( round( $pixels / 1000000, 1 ) )
                )
            );
            return false;
        }

        // Ask WordPress whether it can actually produce WebP, instead of assuming GD.
        if ( ! $this->editor_supports_webp() ) {
            $this->skip( $attachment_id, 'no_webp_support', __( 'El servidor no puede generar WebP.', 'webp-studio' ) );
            return false;
        }

        $webp_relative = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $relative );
        $webp_abs     = $this->abs_path( $webp_relative );
        $quality      = isset( $options['quality'] ) ? (int) $options['quality'] : 80;

        if ( ! $this->encode_webp( $source, $webp_abs, $mime, $quality ) ) {
            $this->skip( $attachment_id, 'encode_failed', __( 'Falló la codificación; el original se conservó.', 'webp-studio' ) );
            return false;
        }

        // Generate the replacement metadata before deleting anything.
        $new_metadata = wp_generate_attachment_metadata( $attachment_id, $webp_abs );
        if ( ! is_array( $new_metadata ) || empty( $new_metadata['width'] ) ) {
            $this->skip( $attachment_id, 'metadata_failed', __( 'No se pudieron generar las miniaturas; el original se conservó.', 'webp-studio' ) );
            @unlink( $webp_abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return false;
        }

        // Scaling may have produced a "-scaled" file that is now the canonical one.
        if ( ! empty( $new_metadata['file'] ) ) {
            $webp_relative = $new_metadata['file'];
        }

        if ( null === $old_metadata ) {
            $old_metadata = wp_get_attachment_metadata( $attachment_id );
        }

        // The previous format is NOT removed here any more. It becomes a pending
        // purge instead.
        //
        // Deleting the .jpg the instant the .webp is written breaks every post
        // that still points at the .jpg URL, and that text is not rewritten in
        // the same instant: the URL arrives in a post body when the post is
        // saved, which for a fresh upload is AFTER this line runs. Deferring is
        // the only ordering in which "converted" and "nothing is broken" can both
        // be true. See the queue class, rule 8.
        //
        // The reported saving below is computed against the file size captured
        // before this point, so deferring the deletion does not flatter it.
        $removed_now = false;

        if ( ! empty( $options['delete_original'] ) ) {
            $removed_now = $this->queue()->defer_original_purge( $attachment_id, $source, $old_metadata );
        }

        update_post_meta( $attachment_id, '_wp_attached_file', $webp_relative );

        wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => 'image/webp',
            )
        );

        $this->update_guid( $attachment_id, $source, $this->abs_path( $webp_relative ) );
        update_post_meta( $attachment_id, self::CONVERTED_META, '1' );

        // $source_size was captured before the original was deleted.
        $before = ! empty( $old_metadata['filesize'] ) ? (int) $old_metadata['filesize'] : $source_size;
        $after  = isset( $new_metadata['filesize'] ) ? (int) $new_metadata['filesize'] : 0;

        if ( $after <= 0 && file_exists( $this->abs_path( $webp_relative ) ) ) {
            $after = (int) filesize( $this->abs_path( $webp_relative ) );
        }

        $pct = ( $before > 0 && $after > 0 ) ? (int) round( ( ( $before - $after ) / $before ) * 100 ) : 0;

        $kept   = empty( $options['delete_original'] );
        $deferred = ( ! $kept && ! $removed_now );

        // Report the outcome honestly. A WebP can legitimately be larger than its
        // source (solid colours compress better as PNG/GIF), so a negative figure is
        // stated rather than hidden.
        if ( $pct > 0 ) {
            /* translators: 1: attachment id, 2: file name, 3: percentage saved. */
            $text = sprintf(
                __( 'Imagen #%1$d (%2$s) convertida a WebP, %3$d%% más ligera.', 'webp-studio' ),
                $attachment_id,
                wp_basename( $relative ),
                $pct
            );
        } elseif ( $pct < 0 ) {
            /* translators: 1: attachment id, 2: file name, 3: percentage increase. */
            $text = sprintf(
                __( 'Imagen #%1$d (%2$s) convertida a WebP; pesa %3$d%% más que el original.', 'webp-studio' ),
                $attachment_id,
                wp_basename( $relative ),
                abs( $pct )
            );
        } else {
            /* translators: 1: attachment id, 2: file name. */
            $text = sprintf(
                __( 'Imagen #%1$d (%2$s) convertida a WebP.', 'webp-studio' ),
                $attachment_id,
                wp_basename( $relative )
            );
        }

        if ( $kept ) {
            $text .= ' ' . __( 'El original se conservó.', 'webp-studio' );
        } elseif ( $deferred ) {
            $text .= ' ' . __( 'El original queda pendiente: se borrará cuando se compruebe que ningún contenido lo sigue usando.', 'webp-studio' );
        } else {
            $text .= ' ' . __( 'Ningún contenido lo usaba, así que el original ya está eliminado.', 'webp-studio' );
        }

        $this->history(
            'success',
            $text,
            array(
                'attachment' => $attachment_id,
                'file'       => $relative,
                'saved_pct'  => $pct,
                'original_removed'  => $removed_now,
                'original_pending'  => $deferred,
            )
        );

        $this->log( "Attachment {$attachment_id} converted to WebP ({$webp_relative})." );

        return $new_metadata;
    }

    /**
     * Records a skip with a machine-readable reason and a human explanation.
     *
     * @param int    $attachment_id Attachment post ID.
     * @param string $reason       Machine-readable reason key.
     * @param string $message      Human-readable explanation.
     */
    private function skip( $attachment_id, $reason, $message ) {
        $this->history(
            'warning',
            sprintf(
                /* translators: 1: attachment id, 2: reason. */
                __( 'Imagen #%1$d omitida: %2$s', 'webp-studio' ),
                $attachment_id,
                $message
            ),
            array(
                'attachment' => $attachment_id,
                'reason'     => $reason,
            )
        );

        // Aggregate so a 500-image run does not store 500 entries.
        $skips = get_option( self::OPTION_SKIPS, array() );
        $skips = is_array( $skips ) ? $skips : array();

        if ( ! isset( $skips[ $reason ] ) ) {
            $skips[ $reason ] = array(
                'count'   => 0,
                'message' => $message,
            );
        }
        $skips[ $reason ]['count']++;

        if ( count( $skips ) > self::SKIPS_LIMIT ) {
            $skips = array_slice( $skips, 0, self::SKIPS_LIMIT, true );
        }

        update_option( self::OPTION_SKIPS, $skips, false );
        $this->log( "Attachment {$attachment_id} skipped ({$reason})." );
    }

    /**
     * Whether this server's image editors can produce WebP.
     *
     * @return bool
     */
    public function editor_supports_webp() {
        if ( function_exists( 'imagewebp' ) ) {
            return true;
        }

        // An Imagick build may support WebP even when GD does not.
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            try {
                $formats = ( new Imagick() )->queryFormats( 'WEBP' );

                return ! empty( $formats );
            } catch ( Exception $e ) {
                return false;
            }
        }

        return false;
    }

    /**
     * Encodes a source image to WebP.
     *
     * @param string $source     Absolute source path.
     * @param string $target     Absolute WebP target path.
     * @param string $mime       Source MIME type.
     * @param int    $quality    WebP quality 0-100.
     * @return bool
     */
    private function encode_webp( $source, $target, $mime, $quality ) {
        if ( ! in_array( $mime, self::SUPPORTED_MIMES, true ) ) {
            return false;
        }

        // GD cannot encode palette images to WebP: imagewebp() reports success while
        // writing a 0-byte file. Most GIFs and some PNGs are palette images, so
        // promote them to truecolor first and encode that instead.
        $editable = $source;
        $temp     = null;

        if ( 'image/gif' === $mime || 'image/png' === $mime ) {
            $promoted = $this->to_truecolor_temp( $source, $mime );
            if ( is_wp_error( $promoted ) ) {
                return false;
            }
            $editable = $promoted;
            $temp     = $promoted;
        }

        try {
            // WP_Image_Editor rather than raw GD calls. This is the API WordPress
            // itself uses, so the conversion honours site filters
            // (image_editor_output_format, wp_image_editors, wp_image_editor_*),
            // works on Imagick-only hosts, and picks up fixes such as lossless WebP
            // detection that a direct imagewebp() call bypasses.
            $editor = wp_get_image_editor( $editable );
            if ( is_wp_error( $editor ) ) {
                $this->log( 'Editor could not load source: ' . $editor->get_error_message() );
                return false;
            }

            $editor->set_quality( (int) $quality );

            // WordPress resets the quality inside save() whenever the output mime
            // differs from the source mime: get_output_format() calls set_quality()
            // with no argument, restoring the site default. Every real conversion goes
            // JPEG/PNG/GIF -> WebP, so without this the chosen quality is discarded and
            // everything is encoded at whatever the site default is. The filter is
            // evaluated inside _save(), after that reset.
            $apply_quality = function () use ( $quality ) {
                return (int) $quality;
            };
            add_filter( 'wp_editor_set_quality', $apply_quality, 999 );

            // Pass the destination explicitly so the editor does not append its own suffix.
            $saved = $editor->save( $target, 'image/webp' );

            remove_filter( 'wp_editor_set_quality', $apply_quality, 999 );

            if ( is_wp_error( $saved ) ) {
                $this->log( 'Editor save failed: ' . $saved->get_error_message() );
                return false;
            }

            // The editor may have written a differently-named file (for example a
            // "-scaled" variant); normalise it onto the expected target.
            if ( ! empty( $saved['path'] ) && $saved['path'] !== $target && file_exists( $saved['path'] ) ) {
                if ( file_exists( $target ) ) {
                    @unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
                if ( ! @rename( $saved['path'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    return false;
                }
            }
        } finally {
            if ( $temp && file_exists( $temp ) ) {
                @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        return $this->webp_is_valid( $target );
    }

    /**
     * Whether the produced file is a real, readable WebP.
     *
     * Checking size and readability is not paranoia. GD returns true from imagewebp()
     * while writing a 0-byte file for inputs it cannot encode, which would otherwise
     * leave a corrupt WebP in place of a working original.
     *
     * @param string $path Candidate WebP path.
     * @return bool
     */
    private function webp_is_valid( $path ) {
        if ( ! file_exists( $path ) ) {
            return false;
        }

        $size = filesize( $path );
        if ( ! $size || $size < 32 ) {
            $this->log( 'Encoder produced an empty or truncated file: ' . wp_basename( $path ) );
            return false;
        }

        $check = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $check || empty( $check['mime'] ) || 'image/webp' !== $check['mime'] ) {
            $this->log( 'Encoder produced a file that is not a valid WebP: ' . wp_basename( $path ) );
            return false;
        }

        return true;
    }

    /**
     * Promotes a palette image to truecolor and writes it to a temporary file.
     *
     * The caller encodes that temporary file and deletes it. Nothing is written next
     * to the original, and the original is never modified.
     *
     * Transparency in a palette PNG is composited onto white, because promoting it to
     * truecolor through GD would otherwise drop the alpha channel entirely.
     *
     * @param string $source Absolute source path.
     * @param string $mime   Source MIME type.
     * @return string|WP_Error Temporary file path, WP_Error on failure.
     */
    private function to_truecolor_temp( $source, $mime ) {
        if ( 'image/gif' === $mime ) {
            $image = @imagecreatefromgif( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        } else {
            $image = @imagecreatefrompng( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( ! $image ) {
            return new WP_Error( 'wcfn_load_failed', __( 'No se pudo leer la imagen.', 'webp-studio' ) );
        }

        // Already truecolor: the caller can encode the original directly.
        if ( imageistruecolor( $image ) ) {
            imagedestroy( $image );
            return $source;
        }

        $width  = imagesx( $image );
        $height = imagesy( $image );
        $canvas = imagecreatetruecolor( $width, $height );

        if ( ! $canvas ) {
            imagedestroy( $image );
            return new WP_Error( 'wcfn_alloc_failed', __( 'No se pudo reservar memoria para la imagen.', 'webp-studio' ) );
        }

        if ( 'image/png' === $mime ) {
            $white = imagecolorallocate( $canvas, 255, 255, 255 );
            imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, $white );
            imagealphablending( $canvas, false );
            imagesavealpha( $canvas, true );
        }

        imagecopy( $canvas, $image, 0, 0, 0, 0, $width, $height );
        imagedestroy( $image );

        $temp = wp_tempnam( $source );
        if ( ! $temp ) {
            imagedestroy( $canvas );
            return new WP_Error( 'wcfn_temp_failed', __( 'No se pudo crear el archivo temporal.', 'webp-studio' ) );
        }

        $written = imagejpeg( $canvas, $temp, 100 );
        imagedestroy( $canvas );

        if ( ! $written ) {
            @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new WP_Error( 'wcfn_promote_failed', __( 'No se pudo preparar la imagen para convertir.', 'webp-studio' ) );
        }

        return $temp;
    }

    /**
     * Detects multi-frame GIFs.
     *
     * @param string $path Absolute GIF path.
     * @return bool
     */
    private function is_animated_gif( $path ) {
        $info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( is_array( $info ) && isset( $info['frames'] ) && (int) $info['frames'] > 1 ) {
            return true;
        }

        $handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $handle ) {
            return false;
        }
        $head = fread( $handle, 262144 );
        fclose( $handle );

        return substr_count( (string) $head, "\x00\x21\xF9\x04" ) > 1;
    }

    /**
     * Rewrites the stored guid.
     *
     * wp_insert_post() forces $guid = get_post_field( 'guid', $post_id ) on update,
     * so wp_update_post( array( 'guid' => ... ) ) is silently ignored. The guid is
     * written directly, then the post cache is invalidated.
     *
     * @param int    $attachment_id Attachment post ID.
     * @param string $old_abs       Absolute path of the previous file.
     * @param string $new_abs       Absolute path of the new file.
     */
    private function update_guid( $attachment_id, $old_abs, $new_abs ) {
        global $wpdb;

        $guid = get_post_field( 'guid', $attachment_id );
        if ( ! $guid ) {
            return;
        }

        $updated = str_replace( wp_basename( $old_abs ), wp_basename( $new_abs ), $guid );
        if ( $updated === $guid ) {
            return;
        }

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->posts,
            array( 'guid' => $updated ),
            array( 'ID' => $attachment_id ),
            array( '%s' ),
            array( '%d' )
        );

        clean_post_cache( $attachment_id );
    }

    /* ------------------------------------------------------------------ */
    /* Background processing                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Arms the background job whenever a run is pending and none is scheduled.
     *
     * Runs on every init so an interrupted run resumes on its own.
     */
    public function maybe_reschedule_cron() {
        if ( ! get_option( self::OPTION_ACTIVE, 0 ) ) {
            return;
        }

        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        wp_schedule_single_event( time() + 5, self::CRON_HOOK );
    }

    /**
     * WP-Cron entry point.
     */
    public function process_cron_batch() {
        $this->process_batch( self::CRON_BATCH_SIZE );
    }

    /**
     * Converts a bounded batch, then re-arms itself if work remains.
     *
     * @param int $limit Maximum attachments to convert in this tick.
     * @return int Number of successful conversions.
     */
    public function process_batch( $limit ) {
        // Structural guarantee: never convert during a visitor or admin page view.
        if ( ! $this->batch_context_ok() ) {
            return 0;
        }

        if ( ! get_option( self::OPTION_ACTIVE, 0 ) ) {
            return 0;
        }

        // Prevent two concurrent runs from processing the same cursor range.
        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            return 0;
        }
        set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );

        try {
            return $this->run_batch( (int) $limit );
        } finally {
            delete_transient( self::LOCK_TRANSIENT );
        }
    }

    /**
     * Performs the batch. Callers must hold the lock.
     *
     * @param int $limit Batch size.
     * @return int Successful conversions.
     */
    private function run_batch( $limit ) {
        global $wpdb;

        $options = $this->get_options();
        $cursor  = (int) get_option( self::OPTION_CURSOR, 0 );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'attachment'
                   AND post_mime_type IN ('image/jpeg','image/png','image/gif')
                   AND ID > %d
                 ORDER BY ID ASC
                 LIMIT %d",
                $cursor,
                $limit
            )
        );

        $ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();

        // Nothing left to do.
        if ( empty( $ids ) ) {
            $this->finish_run();

            return 0;
        }

        // A short batch is processed normally and only then ends the run. Bailing out
        // early here used to skip the conversions entirely, so the final batch of a
        // small library was silently never done.

        // A run started from the interface processes regardless of the "convert new
            // uploads" toggle: that option governs future uploads, not an explicit
            // request to convert the existing library.
            $options['enabled'] = 1;
        $succeeded          = 0;
        $failed             = 0;
        $highest            = $cursor;

        foreach ( $ids as $id ) {
            $metadata = $this->convert_attachment( $id, $options, null );

            if ( is_array( $metadata ) ) {
                wp_update_attachment_metadata( $id, $metadata );
                $succeeded++;
            } else {
                $failed++;
            }

            if ( $id > $highest ) {
                $highest = $id;
            }
        }

        update_option( self::OPTION_CURSOR, $highest );
        update_option( self::OPTION_PROCESSED, (int) get_option( self::OPTION_PROCESSED, 0 ) + $succeeded );
        update_option( self::OPTION_FAILED, (int) get_option( self::OPTION_FAILED, 0 ) + $failed );
        update_option( self::OPTION_UPDATED, current_time( 'mysql' ) );

        // Terminate on "nothing convertible left" rather than on count arithmetic,
        // which could never complete when a conversion failed.
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
        );

        // End the run when nothing convertible is left. A short batch is also the end of
        // the queue: there is no next page to fetch, so scheduling another tick would
        // only spin until the empty-query branch closes it.
        if ( 0 === $remaining || count( $ids ) < $limit ) {
            $this->finish_run( $succeeded, $failed );
        } else {
            wp_schedule_single_event( time() + 5, self::CRON_HOOK );
        }

        $this->log( "Batch done: {$succeeded} converted, {$failed} failed, {$remaining} remaining." );

        return $succeeded;
    }

    /**
     * Marks a run as finished and reports the outcome.
     *
     * A finished run always produces a closing entry, so the user is never left with a
     * run that silently stopped. Skipped images are listed with their reasons rather
     * than just counted, because "5 skipped" alone explains nothing.
     *
     * @param int $succeeded Images converted in the final batch.
     * @param int $failed    Images that failed in the final batch.
     */
    private function finish_run( $succeeded = 0, $failed = 0 ) {
        update_option( self::OPTION_ACTIVE, 0 );

        $scheduled = wp_next_scheduled( self::CRON_HOOK );
        if ( $scheduled ) {
            wp_unschedule_event( $scheduled, self::CRON_HOOK );
        }

        $processed = (int) get_option( self::OPTION_PROCESSED, 0 );
        $skipped   = (int) get_option( self::OPTION_FAILED, 0 );
        $skips     = get_option( self::OPTION_SKIPS, array() );
        $skips     = is_array( $skips ) ? $skips : array();

        $summary = sprintf(
            /* translators: 1: converted count, 2: skipped count. */
            __( 'Conversión finalizada: %1$d convertidas, %2$d omitidas.', 'webp-studio' ),
            $processed,
            $skipped
        );

        if ( $skips ) {
            $parts = array();
            foreach ( $skips as $reason ) {
                $parts[] = sprintf(
                    /* translators: 1: skip count, 2: reason message. */
                    __( '%1$d — %2$s', 'webp-studio' ),
                    (int) $reason['count'],
                    $reason['message']
                );
            }
            $summary .= ' ' . implode( ' ', $parts );
        }

        $this->history(
            $skipped > 0 ? 'warning' : 'success',
            $summary,
            array(
                'converted' => $processed,
                'skipped'   => $skipped,
                'reasons'   => $skips,
            )
        );
    }

    /**
     * Starts a new background run.
     */
    private function start_run() {
        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
        );

        update_option( self::OPTION_TOTAL, $total );
        update_option( self::OPTION_CURSOR, 0 );
        update_option( self::OPTION_PROCESSED, 0 );
        update_option( self::OPTION_FAILED, 0 );
        update_option( self::OPTION_ACTIVE, $total > 0 ? 1 : 0 );

        $scheduled = wp_next_scheduled( self::CRON_HOOK );
        if ( $scheduled ) {
            wp_unschedule_event( $scheduled, self::CRON_HOOK );
        }
        if ( $total > 0 ) {
            wp_schedule_single_event( time() + 5, self::CRON_HOOK );
        }

        // Reset the per-run skip aggregation so it describes this run only.
        delete_option( self::OPTION_SKIPS );

        $this->history(
            'info',
            0 === $total
                ? __( 'No quedan imágenes por convertir.', 'webp-studio' )
                : sprintf(
                    /* translators: %d: number of images. */
                    _n(
                        'Conversión en segundo plano iniciada: %d imagen pendiente. Puedes cerrar esta página.',
                        'Conversión en segundo plano iniciada: %d imágenes pendientes. Puedes cerrar esta página.',
                        $total,
                        'webp-studio'
                    ),
                    $total
                ),
            array( 'total' => $total )
        );

        $this->log( "Background run started for {$total} attachments." );
    }

    /* ------------------------------------------------------------------ */
    /* AJAX                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Verifies the request origin for admin AJAX.
     *
     * Both checks are required: the nonce proves the request came from a screen this
     * plugin rendered, and the capability check stops a logged-in user without
     * manage_options from reusing a nonce they observed elsewhere.
     */
    private function require_nonce() {
        check_ajax_referer( 'webp_converter_for_news', '_ajax_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes', 'webp-studio' ), 403 );
        }
    }

    /**
     * Current run status.
     */
    public function ajax_get_status() {
        $this->require_nonce();
        wp_send_json_success( $this->build_status() );
    }

    /**
     * Advances one small batch from the settings page.
     *
     * This exists so progress continues even when WP-Cron has no traffic to run on.
     */
    public function ajax_run_batch() {
        $this->require_nonce();

        if ( ! get_option( self::OPTION_ACTIVE, 0 ) ) {
            $this->start_run();
        }

        $this->process_batch( self::REQUEST_BATCH_SIZE );

        wp_send_json_success( $this->build_status() );
    }

    /**
     * Removes thumbnail files that no attachment references.
     */
    public function ajax_cleanup_orphans() {
        $this->require_nonce();

        $dry_run = isset( $_REQUEST['dry_run'] )
            && '1' === sanitize_text_field( wp_unslash( $_REQUEST['dry_run'] ) );
        $result  = $this->cleanup_orphans( $dry_run );

        wp_send_json_success(
            array(
                'removed' => $result['removed'],
                'files'   => $result['files'],
                'message' => $dry_run
                    ? sprintf(
                        /* translators: %d: number of files. */
                        __( '%d archivos huérfanos serían eliminados. Ejecuta la limpieza para confirmarlo.', 'webp-studio' ),
                        $result['removed']
                    )
                    : sprintf(
                        /* translators: %d: number of files. */
                        __( '%d archivos huérfanos eliminados.', 'webp-studio' ),
                        $result['removed']
                    ),
            )
        );
    }

    /**
     * Builds the status payload.
     *
     * A boolean flag is returned instead of a translated string so the browser
     * side never compares against a localized literal.
     *
     * @return array
     */
    private function build_status() {
        return $this->build_status_payload();
    }

    /**
     * Builds the single status payload shared by the screen and the AJAX endpoint.
     *
     * One source of truth: the counters a card shows and the sentence next to it are
     * computed here, so they can never disagree. Library inventory (total/converted/
     * pending) is kept separate from the last run's work figures, because adding the
     * two would double-count anything already converted in an earlier run.
     *
     * @return array
     */
    /**
     * Encodes a copy of a file as WebP at the given quality.
     *
     * Public so the verification script can assert that quality actually changes the
     * output size, rather than trusting that it does.
     *
     * @param string $source  Absolute source path.
     * @param int    $quality Quality 0-100.
     * @return string|false Path to a temporary WebP, or false.
     */
    public function encode_preview( $source, $quality ) {
        $editor = wp_get_image_editor( $source );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $editor->set_quality( (int) $quality );

        $apply_quality = function () use ( $quality ) {
            return (int) $quality;
        };
        add_filter( 'wp_editor_set_quality', $apply_quality, 999 );

        $saved = $editor->save( wp_tempnam( 'wps-preview.webp' ), 'image/webp' );

        remove_filter( 'wp_editor_set_quality', $apply_quality, 999 );

        return is_wp_error( $saved ) || empty( $saved['path'] ) ? false : $saved['path'];
    }

    /**
     * Convenience wrapper returning a usable path even when encoding fails.
     *
     * @param string $source  Absolute source path.
     * @param int    $quality Quality 0-100.
     * @return string
     */
    public function get_preview_path( $source, $quality ) {
        $path = $this->encode_preview( $source, $quality );

        return $path ? $path : $source;
    }

    public function build_status_payload() {
        $stats     = $this->get_statistics();
        $pending   = (int) $stats['pending'];
        $total     = (int) $stats['total'];
        $converted = (int) $stats['converted'];

        $active    = (int) get_option( self::OPTION_ACTIVE, 0 );
        $processed = (int) get_option( self::OPTION_PROCESSED, 0 );
        $skipped   = (int) get_option( self::OPTION_FAILED, 0 );
        $run_total = (int) get_option( self::OPTION_TOTAL, 0 );
        $cursor    = (int) get_option( self::OPTION_CURSOR, 0 );
        $skips     = get_option( self::OPTION_SKIPS, array() );
        $skips     = is_array( $skips ) ? $skips : array();
        $updated   = get_option( self::OPTION_UPDATED, '' );

        $coverage = $total > 0 ? (int) round( ( $converted / $total ) * 100 ) : 0;
        $scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );

        // Unreadable settings outrank every other state. Until they are saved again
        // the form shows defaults that are not what will actually run, so "todo
        // convertido" or "procesando" would both be a lie about the configuration.
        $settings_problem = $this->settings_problem();

        // Distinct states, so the interface never claims "completed" while work is
        // pending, and never shows a percentage it cannot support.
        if ( ! $this->editor_supports_webp() ) {
            $state = 'failed';
            $message = __( 'Este servidor no puede generar WebP, así que no se puede convertir nada.', 'webp-studio' );
        } elseif ( null !== $settings_problem ) {
            $state = 'misconfigured';
            $message = $settings_problem;
        } elseif ( $active && $pending > 0 && 0 === $processed ) {
            $state = 'preparing';
            $message = __( 'Preparando la conversión en segundo plano…', 'webp-studio' );
        } elseif ( $active && $pending > 0 && ! $scheduled && $processed > 0 ) {
            $state = 'queued';
            $message = __( 'Trabajo en cola. El planificador del sistema lo retomará automáticamente.', 'webp-studio' );
        } elseif ( $active && $pending > 0 ) {
            $state = 'running';
            $message = sprintf(
                /* translators: 1: processed count, 2: pending count. */
                __( 'Procesando en segundo plano: %1$d convertidas, %2$d pendientes.', 'webp-studio' ),
                $processed,
                $pending
            );
        } elseif ( $pending > 0 ) {
            $state = 'idle';
            $message = sprintf(
                /* translators: %d: pending count. */
                _n(
                    'Queda %d imagen sin convertir. Usa "Convertir %d imágenes" para continuar.',
                    'Quedan %d imágenes sin convertir. Usa "Convertir %d imágenes" para continuar.',
                    $pending,
                    'webp-studio'
                ),
                $pending,
                $pending
            );
        } elseif ( $skipped > 0 ) {
            $state = 'done_with_issues';
            $message = sprintf(
                /* translators: 1: converted count, 2: skipped count. */
                __( 'La última ejecución convirtió %1$d imágenes y conservó %2$d sin tocar. Revisa el registro de actividad para ver los motivos.', 'webp-studio' ),
                $processed,
                $skipped
            );
        } else {
            $state = 'done';
            $message = __( 'Todo convertido. No queda ninguna imagen sin procesar.', 'webp-studio' );
        }

        return array(
            'state'     => $state,
            'running'   => in_array( $state, array( 'preparing', 'queued', 'running' ), true ),
            'coverage'  => $coverage,
            'total'     => $total,
            'converted' => $converted,
            'pending'   => $pending,
            // Work figures for the last run, not library inventory.
            'processed' => $processed,
            'skipped'   => $skipped,
            'runTotal'  => $run_total,
            'skips'     => $skips,
            'updated'   => $updated,
            'message'   => $message,
            // Identity of the current work, so a stale response can be discarded.
            'runId'     => $active ? (string) $run_total . ':' . $cursor : 'idle',
            'cursor'    => $cursor,
        );
    }

    /**
     * Starts a background run from the interface.
     *
     * Separate from the settings form on purpose: saving configuration must never
     * start work, and the confirmation belongs to the action, not to the save.
     */
    public function ajax_start_run() {
        $this->require_nonce();

        $options = $this->get_options();

        // The run uses saved configuration. Unsaved form values are not applied, and
        // the interface says so rather than pretending otherwise.
        if ( get_option( self::OPTION_ACTIVE, 0 ) ) {
            wp_send_json_error( __( 'Ya hay una conversión en curso.', 'webp-studio' ), 409 );
        }

        $this->start_run();

        wp_send_json_success(
            array(
                'message' => __( 'Conversión en segundo plano iniciada. Puedes cerrar esta página.', 'webp-studio' ),
                'status'  => $this->build_status_payload(),
            )
        );
    }

    /**
     * Re-encodes the sample image at the chosen quality and returns the result.
     *
     * Cached per (attachment, quality) so dragging the slider does not re-encode on every
     * step. Nothing is added to the media library: the file is a temporary preview that
     * WordPress cleans up on its own.
     */
    public function ajax_preview() {
        $this->require_nonce();

        $quality = isset( $_REQUEST['quality'] )
            ? max( 0, min( 100, absint( $_REQUEST['quality'] ) ) )
            : 80;

        // The preview re-encodes the plugin's own sample, not a real attachment.
        $sample = $this->get_sample_image();
        if ( ! $sample ) {
            wp_send_json_error( __( 'No se pudo preparar la imagen de ejemplo.', 'webp-studio' ), 500 );
        }

        $file = $sample['path'];

        $key    = 'wcfn_preview_' . $quality;
        $cached = get_transient( $key );

        if ( is_array( $cached ) && ! empty( $cached['path'] ) && file_exists( $cached['path'] ) ) {
            wp_send_json_success( $this->preview_payload( $cached['path'], $file, $quality ) );
        }

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            wp_send_json_error( $editor->get_error_message(), 500 );
        }

        $editor->set_quality( $quality );

        // WordPress resets the quality inside save() whenever the output mime differs
        // from the source mime: get_output_format() calls set_quality() with no
        // argument, restoring the site default (86 on this host). A PNG or JPEG source
        // saved as WebP therefore ignores whatever was requested. The filter is applied
        // inside _save(), after that reset, so it is the only lever that survives.
        $apply_quality = function () use ( $quality ) {
            return $quality;
        };
        add_filter( 'wp_editor_set_quality', $apply_quality, 999 );

        // Write straight into the preview folder so the returned URL is fetchable.
        // Naming it by quality also keeps repeated previews idempotent.
        $target = trailingslashit( $this->preview_dir() ) . 'q' . $quality . '.webp';
        $saved  = $editor->save( $target, 'image/webp' );

        remove_filter( 'wp_editor_set_quality', $apply_quality, 999 );

        if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
            wp_send_json_error(
                is_wp_error( $saved )
                    ? $saved->get_error_message()
                    : __( 'No se pudo generar la vista previa.', 'webp-studio' ),
                500
            );
        }

        $path = $saved['path'];

        if ( ! $this->webp_is_valid( $path ) ) {
            @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            wp_send_json_error( __( 'La vista previa no se pudo generar.', 'webp-studio' ), 500 );
        }

        if ( $path !== $target && file_exists( $target ) ) {

            @unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }


        set_transient( $key, array( $path, $path ), DAY_IN_SECONDS );
        wp_send_json_success( $this->preview_payload( $path, $file, $quality ) );


}
    public function ajax_toggle_activity_log() {
        $this->require_nonce();

        $options = $this->get_options();
        $options["activity_log"] = empty( $options["activity_log"] ) ? 1 : 0;

        update_option( self::OPTION_NAME, $options );
        wp_send_json_success(
            array(
                "message" => __( "Registro de actividad " . ( $options["activity_log"] ? "activado" : "desactivado" ), "webp-studio" ),
                "activity_log" => $options["activity_log"],
            )
        );
    }

    /**
     * Builds the JSON payload for a generated preview.
     *
     * @param string $path Preview file path.
     * @param string $file Original file path.
     * @return array
     */
    private function preview_payload( $path, $file, $quality = 80 ) {
        $uploads  = wp_upload_dir();
        $size     = (int) filesize( $path );
        $original = (int) filesize( $file );

        // Comparing a WebP against a PNG source would always read as a loss, because PNG
        // stores this image losslessly. The honest comparison is against the JPEG the
        // plugin would realistically convert: same image, lossy, at the same quality.
        $jpeg_size  = $this->jpeg_reference_size( $file, $quality );
        $delta      = ( $jpeg_size > 0 && $size > 0 )
            ? (int) round( ( ( $jpeg_size - $size ) / $jpeg_size ) * 100 )
            : 0;

        return array(
            // The URL must address the real file. Earlier the preview was written to
            // /tmp by wp_tempnam() while the URL pointed at uploads, so the image never
            // loaded. Previews live in one dedicated folder and the name is derived
            // from the actual file.
            'url'           => trailingslashit( $uploads['baseurl'] ) . self::PREVIEW_DIR . '/' . wp_basename( $path ),
            'size'          => $size,
            'sizeLabel'     => size_format( $size ),
            'jpegSize'      => $jpeg_size,
            'jpegLabel'     => $jpeg_size > 0 ? size_format( $jpeg_size ) : '',
            'savings'       => $delta,
        );
    }

    /**
     * Encodes the sample as JPEG at the same quality, as a like-for-like reference.
     *
     * @param string $file    Sample image path.
     * @param int    $quality Quality to encode at.
     * @return int Bytes, or 0 on failure.
     */
    private function jpeg_reference_size( $file, $quality = 80 ) {
        static $cache = array();

        if ( isset( $cache[ $file ] ) ) {
            return $cache[ $file ];
        }

        $image = @imagecreatefrompng( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $image ) {
            $cache[ $file ] = 0;

            return 0;
        }

        $temp = wp_tempnam( 'wcfn-ref' );
        $ok   = $temp ? imagejpeg( $image, $temp, (int) $quality ) : false;
        imagedestroy( $image );

        $size              = ( $ok && file_exists( $temp ) ) ? (int) filesize( $temp ) : 0;
        $cache[ $file ]    = $size;

        if ( $temp ) {
            @unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        return $size;
    }

    /**
     * Ensures the plugin's uploads subfolder exists and returns its path.
     *
     * @return string
     */
    private function preview_dir() {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . self::PREVIEW_DIR;

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        return $dir;
    }

    /**
     * Empties the activity history.
     */
    public function ajax_clear_history() {
        $this->require_nonce();

        delete_option( self::OPTION_HISTORY );
        delete_option( self::OPTION_SKIPS );

        wp_send_json_success(
            array( 'message' => __( 'Registro vaciado.', 'webp-studio' ) )
        );
    }

    /**
     * Returns recent history entries for live polling.
     *
     * Paged so the screen never has to load the entire history to render.
     */
    public function ajax_get_history() {
        $this->require_nonce();

        $page    = isset( $_REQUEST['page'] ) ? max( 1, absint( $_REQUEST['page'] ) ) : 1;
        $per     = 10;
        $entries = array_slice( $this->get_history_entries( $page * $per ), ( $page - 1 ) * $per, $per );

        wp_send_json_success(
            array(
                'entries' => $entries,
                'page'    => $page,
                'perPage' => $per,
                'total'   => count( $this->get_history_entries( PHP_INT_MAX ) ),
            )
        );
    }

    /* ------------------------------------------------------------------ */
    /* Rewrite queue                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Reads a request flag as a boolean.
     *
     * @param string $key Request key.
     * @return bool
     */
    private function request_flag( $key ) {
        return isset( $_REQUEST[ $key ] ) && '1' === sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
    }

    /**
     * Queue counters for the settings screen.
     */
    public function ajax_queue_status() {
        $this->require_nonce();

        wp_send_json_success( $this->queue()->get_progress() );
    }

    /**
     * Creates or reconciles the queue tables on demand.
     *
     * The lazy check in the queue class already covers an upgrade, but an admin
     * whose dbDelta failed under a restrictive DDL privilege needs a way to try
     * again without reactivating the plugin.
     */
    public function ajax_queue_install() {
        $this->require_nonce();

        $queue = $this->queue();
        $ready = $queue->install_tables();

        if ( ! $ready ) {
            wp_send_json_error(
                __( 'No se pudieron crear las tablas de la cola. Comprueba los permisos de la base de datos.', 'webp-studio' ),
                500
            );
        }

        wp_send_json_success(
            array(
                'message' => __( 'Tablas de la cola listas.', 'webp-studio' ),
                'progress' => $queue->get_progress(),
            )
        );
    }

    /**
     * Builds the queue from the database.
     *
     * Bounded by the AJAX budget, like every other entry point here: a scan of a
     * large library cannot finish inside one request, and it resumes from its
     * own cursor on the next tick.
     */
    public function ajax_queue_enqueue() {
        $this->require_nonce();

        $queue = $this->queue();

        $meta_keys = array();
        if ( isset( $_REQUEST['meta_keys'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_REQUEST['meta_keys'] ) );
            foreach ( explode( ',', $raw ) as $key ) {
                $key = trim( $key );
                if ( '' !== $key ) {
                    $meta_keys[] = sanitize_key( $key );
                }
            }
        }

        $result = $queue->enqueue_from_scan(
            array(
                'dry_run'           => $this->request_flag( 'dry_run' ),
                'meta_keys'         => $meta_keys,
                'include_revisions' => $this->request_flag( 'include_revisions' ),
                'include_options'   => ! $this->request_flag( 'skip_options' ),
                'restart'           => $this->request_flag( 'restart' ),
                'budget'            => WP_Image_WebP_Converter_Queue::AJAX_BUDGET,
            )
        );

        wp_send_json_success(
            array(
                'result'   => $result,
                'progress' => $queue->get_progress(),
                'message'  => $this->queue_enqueue_message( $result ),
            )
        );
    }

    /**
     * Human-readable summary of a scan slice.
     *
     * @param array $result Scan result.
     * @return string
     */
    private function queue_enqueue_message( array $result ) {
        if ( ! empty( $result['dry_run'] ) ) {
            return sprintf(
                /* translators: 1: posts inspected, 2: entries that would be queued. */
                __( 'Simulación: %1$d entradas revisadas, %2$d se encolarían. No se ha escrito nada.', 'webp-studio' ),
                (int) $result['scanned'],
                (int) $result['queued']
            );
        }

        $message = sprintf(
            /* translators: 1: posts inspected, 2: entries queued, 3: duplicates ignored. */
            __( 'Encolado: %1$d entradas revisadas, %2$d en la cola, %3$d ya estaban.', 'webp-studio' ),
            (int) $result['scanned'],
            (int) $result['queued'],
            (int) $result['duplicates']
        );

        if ( empty( $result['finished'] ) ) {
            $message .= ' ' . __( 'El escaneo continúa en segundo plano.', 'webp-studio' );
        }

        return $message;
    }

    /**
     * Rewrites pending entries for as long as the AJAX budget lasts.
     */
    public function ajax_queue_process() {
        $this->require_nonce();

        $queue  = $this->queue();
        $result = $queue->process(
            array(
                'dry_run' => $this->request_flag( 'dry_run' ),
                'budget'  => WP_Image_WebP_Converter_Queue::AJAX_BUDGET,
            )
        );

        wp_send_json_success(
            array(
                'result'   => $result,
                'progress' => $queue->get_progress(),
                'message'  => $this->queue_process_message( $result ),
            )
        );
    }

    /**
     * Human-readable summary of a processing slice.
     *
     * @param array $result Process result.
     * @return string
     */
    private function queue_process_message( array $result ) {
        if ( 'context' === $result['reason'] ) {
            return __( 'La reescritura solo se ejecuta desde el planificador o desde esta pantalla, nunca durante la carga de una página.', 'webp-studio' );
        }

        if ( 'locked' === $result['reason'] ) {
            return __( 'Ya hay otra ejecución en curso. Se reintentará en el siguiente ciclo.', 'webp-studio' );
        }

        if ( 'empty' === $result['reason'] ) {
            return __( 'No queda nada pendiente en la cola.', 'webp-studio' );
        }

        if ( ! empty( $result['dry_run'] ) ) {
            return sprintf(
                /* translators: 1: entries inspected, 2: URLs that would change. */
                __( 'Simulación: %1$d entradas revisadas, %2$d URLs cambiarían. No se ha escrito nada.', 'webp-studio' ),
                (int) $result['processed'],
                (int) $result['replacements']
            );
        }

        return sprintf(
            /* translators: 1: entries processed, 2: entries rewritten, 3: URLs changed, 4: entries skipped, 5: elapsed seconds. */
            __( 'Procesadas %1$d entradas, %2$d reescritas (%3$d URLs), %4$d omitidas. Han pasado %5$s s.', 'webp-studio' ),
            (int) $result['processed'],
            (int) $result['rewritten'],
            (int) $result['replacements'],
            (int) $result['skipped'],
            number_format_i18n( (float) $result['elapsed'], 2 )
        );
    }

    /**
     * Restores the previous value of one entry.
     */
    public function ajax_queue_rollback() {
        $this->require_nonce();

        $queue  = $this->queue();
        $result = $queue->rollback( isset( $_REQUEST['queue_id'] ) ? absint( $_REQUEST['queue_id'] ) : 0 );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success(
            array(
                'restored' => true,
                'progress' => $queue->get_progress(),
                'message'  => sprintf(
                    /* translators: %d: entry id. */
                    __( 'Entrada #%d restaurada a su valor anterior.', 'webp-studio' ),
                    (int) $result['queueId']
                ),
            )
        );
    }

    /**
     * Deletes the finished history rows.
     *
     * Requires an explicit confirm flag on the server as well as twice in the
     * browser. This is the only irreversible action in the whole feature, and a
     * single mis-clicked button must not be able to destroy the ability to undo.
     */
    public function ajax_queue_purge() {
        $this->require_nonce();

        if ( ! $this->request_flag( 'confirm' ) ) {
            wp_send_json_error( __( 'El borrado del historial necesita una confirmación explícita.', 'webp-studio' ), 400 );
        }

        $queue  = $this->queue();
        $result = $queue->purge_log( true );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success(
            array(
                'deleted'   => (int) $result['deleted'],
                'remaining' => isset( $result['remaining'] ) ? (int) $result['remaining'] : 0,
                'progress'  => $queue->get_progress(),
                'message'   => sprintf(
                    /* translators: %d: number of history rows removed. */
                    __( 'Historial purgado: %d entradas eliminadas.', 'webp-studio' ),
                    (int) $result['deleted']
                ),
            )
        );
    }

    /**
     * Runs one slice of the verified purge of originals.
     *
     * Separate from ajax_queue_purge(), which purges the REWRITE HISTORY and is
     * untouched by this: that one destroys the ability to undo a rewrite, this
     * one deletes files from disk. Two different consequences, two different
     * buttons, two different endpoints.
     *
     * @return void
     */
    public function ajax_queue_purge_originals() {
        $this->require_nonce();

        $queue  = $this->queue();
        $result = $queue->purge_verified(
            array(
                'dry_run' => $this->request_flag( 'dry_run' ),
                'budget'  => WP_Image_WebP_Converter_Queue::AJAX_BUDGET,
            )
        );

        wp_send_json_success(
            array(
                'result'   => $result,
                'progress' => $queue->get_progress(),
                'message'  => $this->queue_purge_message( $result ),
            )
        );
    }

    /**
     * Human-readable summary of a verified-purge slice.
     *
     * The refusal messages matter as much as the successful one. "No se ha
     * borrado nada porque todavía no se ha comprobado el contenido" is the only
     * honest answer when the reference index is unusable, and the interface has
     * to say it rather than report zero deletions as if there were nothing to do.
     *
     * @param array $result Purge result.
     * @return string
     */
    private function queue_purge_message( array $result ) {
        if ( 'context' === $result['reason'] ) {
            return __( 'El borrado verificado solo se ejecuta desde el planificador o desde esta pantalla, nunca durante la carga de una página.', 'webp-studio' );
        }

        if ( 'locked' === $result['reason'] ) {
            return __( 'Ya hay otra ejecución en curso. Se reintentará en el siguiente ciclo.', 'webp-studio' );
        }

        if ( 'rewrite_pending' === $result['reason'] ) {
            return sprintf(
                /* translators: %d: pending rewrite entries. */
                __( 'No se ha borrado nada: quedan %d entradas de reescritura sin terminar. Un contenido que aún apunta al .jpg se quedaría con una imagen rota si se borrara ahora.', 'webp-studio' ),
                isset( $result['openRewrite'] ) ? (int) $result['openRewrite'] : 0
            );
        }

        if ( 'refs_incomplete' === $result['reason'] && 'content_changed' === $this->queue()->refs_blocker() ) {
            return __( 'No se ha borrado nada: se acaba de reescribir contenido, así que la revisión anterior ya no describe lo que hay guardado. Vuelve a escanear desde el principio y vuelve a intentarlo.', 'webp-studio' );
        }

        if ( 'refs_incomplete' === $result['reason'] ) {
            return __( 'No se ha borrado nada: todavía no se ha revisado todo el contenido del sitio, así que no es posible demostrar que esos originales estén libres. Ejecuta "Escanear y encolar" hasta que termine.', 'webp-studio' );
        }

        if ( 'empty' === $result['reason'] ) {
            return __( 'No hay ningún original pendiente de borrar.', 'webp-studio' );
        }

        if ( ! empty( $result['dry_run'] ) ) {
            return sprintf(
                /* translators: 1: attachments examined, 2: files that would be removed, 3: held back. */
                __( 'Simulación: %1$d revisados, %2$d archivos se borrarían y %3$d se guardarían porque algo los sigue usando. No se ha escrito nada.', 'webp-studio' ),
                (int) $result['scanned'],
                (int) $result['files'],
                (int) $result['waiting']
            );
        }

        return sprintf(
            /* translators: 1: attachments reviewed, 2: originals deleted, 3: held back because still referenced. */
            __( 'Verificados %1$d originales: %2$d eliminados y %3$d conservados porque algún contenido los sigue usando.', 'webp-studio' ),
            (int) $result['scanned'],
            (int) $result['purged'],
            (int) $result['waiting']
        );
    }

    /**
     * Loads translations.
     *
     * Not needed for wordpress.org-hosted plugins since 4.6, still required for
     * self-hosted installs and custom language packs.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'webp-studio',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Orphan maintenance                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Deletes old-format uploads that no attachment references.
     *
     * Scoped to images still referenced by a non-WebP attachment, plus thumbnails
     * whose attachment is gone or already converted.
     *
     * @return int Files removed.
     */
    private function cleanup_orphans( $dry_run = false ) {
        $uploads = wp_upload_dir();
        $base    = trailingslashit( $uploads['basedir'] );

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        // One query for the whole scan instead of one per file.
        $index = $this->build_stem_index();

        $removed = array();
        $scanned = 0;

        foreach ( $iterator as $file ) {
            if ( ++$scanned > 20000 ) {
                break;
            }

            if ( ! $file->isFile() ) {
                continue;
            }

            $ext = strtolower( $file->getExtension() );
            if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ), true ) ) {
                continue;
            }

            if ( $this->orphan_in_use( $base, $file->getPathname(), $index ) ) {
                continue;
            }

            if ( $dry_run ) {
                $removed[] = ltrim( str_replace( $base, '', $file->getPathname() ), '/' );
                continue;
            }

            if ( @unlink( $file->getPathname() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
                $removed[] = ltrim( str_replace( $base, '', $file->getPathname() ), '/' );
            }
        }

        if ( ! $dry_run && $removed ) {
            wp_cache_flush();
            $this->log( 'Orphan cleanup removed ' . count( $removed ) . ' files.' );
        }

        return array(
            'removed' => count( $removed ),
            'files'   => array_slice( $removed, 0, 25 ),
        );
    }

    /**
     * Whether a file must be kept because an attachment still needs the old format.
     *
     * Matching is done on the shared filename stem rather than the full file name.
     * An intermediate size is named "<stem>-<W>x<H>.<ext>", so its own basename will
     * never match the attachment's "_wp_attached_file" value; the stem does.
     *
     * @param string $base Uploads base directory.
     * @param string $path Absolute file path.
     * @return bool True when the file must be kept.
     */
    private function orphan_in_use( $base, $path, $index = null ) {
        $relative = ltrim( str_replace( $base, '', $path ), '/' );
        $stem_key = $this->stem_key( $relative );

        // Without an index, fall back to a single targeted lookup. This path is only
        // used by diagnostics; cleanup_orphans() always passes a prebuilt index.
        if ( ! is_array( $index ) ) {
            $index = $this->build_stem_index();
        }

        if ( ! isset( $index[ $stem_key ] ) ) {
            return true; // Nothing references this stem: orphan.
        }

        foreach ( $index[ $stem_key ] as $mime ) {
            if ( 'image/webp' !== $mime && in_array( $mime, self::SUPPORTED_MIMES, true ) ) {
                return true; // Still needed by a not-yet-converted attachment.
            }
        }

        return false;
    }

    /**
     * Reduces an uploads-relative path to the key that identifies its owner.
     *
     * Delegates to the queue on purpose. The rule also decides which files a
     * verified purge is allowed to delete, and two implementations of "what
     * counts as the same image" would eventually disagree. The disagreement
     * would not look like a disagreement either: it would look like a deleted
     * file that a post still points at.
     *
     * @param string $relative Uploads-relative path.
     * @return string
     */
    private function stem_key( $relative ) {
        $this->boot_queue();

        return WP_Image_WebP_Converter_Queue::stem_of( $relative );
    }

    /**
     * Builds a stem => MIME map for every attachment in one query.
     *
     * The previous implementation queried per candidate file, which made cleanup
     * cost scale linearly with the number of files on disk (142 files => 142 queries).
     * One query plus in-memory matching keeps it flat.
     *
     * @return array<string, string[]>
     */
    private function build_stem_index() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT m.meta_value AS path, p.post_mime_type AS mime
             FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = '_wp_attached_file'
               AND p.post_type = 'attachment'",
            ARRAY_A
        );

        $index = array();
        foreach ( (array) $rows as $row ) {
            if ( empty( $row['path'] ) ) {
                continue;
            }
            $key = $this->stem_key( $row['path'] );
            if ( ! isset( $index[ $key ] ) ) {
                $index[ $key ] = array();
            }
            $index[ $key ][] = $row['mime'];
        }

        return $index;
    }

    /* ------------------------------------------------------------------ */
    /* Statistics                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Image statistics from a single query with consistent denominators.
     *
     * @return array
     */
    public function get_statistics() {
        global $wpdb;

        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM( CASE WHEN post_mime_type = 'image/webp' THEN 1 ELSE 0 END ) AS converted,
                SUM( CASE WHEN post_mime_type IN ('image/jpeg','image/png','image/gif') THEN 1 ELSE 0 END ) AS pending
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'",
            ARRAY_A
        );

        return array(
            'total'     => isset( $row['total'] ) ? (int) $row['total'] : 0,
            'converted' => isset( $row['converted'] ) ? (int) $row['converted'] : 0,
            'pending'   => isset( $row['pending'] ) ? (int) $row['pending'] : 0,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Settings page                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Sample image data for the quality preview.
     *
     * Generated on activation so it exists before anyone opens the screen, and
     * repaired on demand if the uploads folder was cleared meanwhile.
     *
     * @return array|null
     */
    public function get_sample_image() {
        $path = $this->sample_path();

        if ( ! file_exists( $path ) && ! $this->generate_sample() ) {
            return null;
        }

        $uploads = wp_upload_dir();

        return array(
            'id'     => 0,
            'path'   => $path,
            'url'    => trailingslashit( $uploads['baseurl'] ) . self::SAMPLE_NAME,
            'width'  => self::SAMPLE_WIDTH,
            'height' => self::SAMPLE_HEIGHT,
            'label'  => __( 'imagen de prueba del plugin', 'webp-studio' ),
        );
    }

    /**
     * Generates the sample image if missing.
     *
     * @return bool
     */
    public function generate_sample() {
        $path = $this->sample_path();

        if ( file_exists( $path ) ) {
            return true;
        }

        return $this->create_sample_image( $path );
    }

    /**
     * Absolute path of the sample image.
     *
     * @return string
     */
    private function sample_path() {
        $uploads = wp_upload_dir();

        return trailingslashit( $uploads['basedir'] ) . self::SAMPLE_NAME;
    }

    /**
     * Draws the reference image used by the quality preview.
     *
     * It mixes content that exposes lossy compression: smooth gradients reveal
     * banding, a fine grid reveals moire, saturated edges reveal ringing, and text
     * reveals legibility loss. It is PNG so the source is lossless and the preview
     * shows genuine WebP artefacts rather than compounding an already-compressed file.
     *
     * @param string $path Destination path.
     * @return bool
     */
    private function create_sample_image( $path ) {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            return false;
        }

        $w     = self::SAMPLE_WIDTH;
        $h     = self::SAMPLE_HEIGHT;
        $image = imagecreatetruecolor( $w, $h );

        if ( ! $image ) {
            return false;
        }

        for ( $y = 0; $y < $h; $y++ ) {
            $t     = $y / max( 1, $h - 1 );
            $color = imagecolorallocate(
                $image,
                (int) ( 40 + $t * 90 ),
                (int) ( 90 + $t * 110 ),
                (int) ( 200 - $t * 90 )
            );
            imageline( $image, 0, $y, $w, $y, $color );
        }

        $blocks = array(
            array( 40, 40, 220, 150, array( 220, 40, 60 ) ),
            array( 280, 40, 460, 150, array( 30, 160, 90 ) ),
            array( 520, 40, 700, 150, array( 250, 200, 30 ) ),
            array( 760, 40, 940, 150, array( 120, 60, 210 ) ),
        );
        foreach ( $blocks as $b ) {
            imagefilledrectangle( $image, $b[0], $b[1], $b[2], $b[3], imagecolorallocate( $image, $b[4][0], $b[4][1], $b[4][2] ) );
        }

        imagefilledellipse( $image, 500, 420, 300, 300, imagecolorallocate( $image, 255, 255, 255 ) );
        imagefilledellipse( $image, 500, 420, 170, 170, imagecolorallocate( $image, 25, 25, 30 ) );
        imagefilledellipse( $image, 500, 420, 70, 70, imagecolorallocate( $image, 240, 70, 120 ) );

        $grid = imagecolorallocate( $image, 255, 255, 255 );
        for ( $x = 0; $x < $w; $x += 6 ) {
            imagesetpixel( $image, $x, 600, $grid );
        }
        for ( $x = 0; $x < $w; $x += 3 ) {
            imagesetpixel( $image, $x, 604, $grid );
            imagesetpixel( $image, $x, 608, $grid );
        }
        for ( $y = 620; $y < 760; $y += 6 ) {
            imageline( $image, 20, $y, 980, $y, $grid );
        }

        $dark = imagecolorallocate( $image, 20, 20, 25 );
        for ( $i = 0; $i < 48; $i++ ) {
            $angle = ( $i * M_PI * 2 ) / 48;
            imageline( $image, 500, 420, (int) ( 500 + cos( $angle ) * 420 ), (int) ( 420 + sin( $angle ) * 420 ), $dark );
        }

        $white = imagecolorallocate( $image, 255, 255, 255 );
        imagestring( $image, 5, 30, 170, 'WEBP quality preview', $white );
        imagestring( $image, 3, 30, 200, 'JPEG / PNG / GIF -> WebP', $white );
        imagestring( $image, 4, 30, 790, 'The quick brown fox jumps over the lazy dog 0123456789', $white );

        $ok = imagepng( $image, $path );
        imagedestroy( $image );

        return (bool) $ok && file_exists( $path );
    }

    /**
     * Narrow URL fallback.
     *
     * @param string $url     Original URL.
     * @param int    $post_id Attachment ID.
     * @return string
     */
    public function filter_attachment_url( $url, $post_id ) {
        if ( ! $this->is_enabled() ) {
            return $url;
        }

        $mime = get_post_mime_type( $post_id );
        if ( ! $mime || 'image/webp' === $mime || ! in_array( $mime, self::SUPPORTED_MIMES, true ) ) {
            return $url;
        }

        $relative = get_post_meta( $post_id, '_wp_attached_file', true );
        if ( ! $relative ) {
            return $url;
        }

        $uploads = wp_upload_dir();
        $webp    = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $relative );

        if ( file_exists( trailingslashit( $uploads['basedir'] ) . $webp ) ) {
            return trailingslashit( $uploads['baseurl'] ) . $webp;
        }

        return $url;
    }
}

/**
 * Boots the plugin.
 *
 * Uses an anonymous closure rather than a named global function: a named one would
 * collide with any other plugin that declares the same symbol, and this plugin has
 * already been bitten by a duplicate-declaration fatal once.
 *
 * Instantiated while this file is being loaded, NOT from a plugins_loaded callback.
 *
 * This matters and was a real bug. WordPress loads active plugin files in
 * wp-settings.php and only then fires plugins_loaded. So the constructor ran *inside*
 * that action, and every hook it registered on plugins_loaded itself
 * (boot_admin, load_textdomain) was added after the hook had already begun executing.
 * WP_Hook only picks up callbacks added at a priority it has not passed yet, so
 * neither ever ran in a real request: the settings menu was never registered, and the
 * translations were never loaded.
 *
 * CLI tests hid this completely. wp-cli fires plugins_loaded during bootstrap and a
 * test that fires it a second time runs the constructor again, this time with both
 * callbacks already queued, so everything appears to work.
 *
 * Instantiating here means the constructor's own hooks are registered before
 * plugins_loaded fires, which is the only order a real HTTP request ever produces.
 */
new WP_Image_WebP_Converter();

/**
 * Runs on activation.
 *
 * Clears any schedule left behind by a previous run. Without this, a run that was
 * active at deactivation time keeps firing against a deactivated plugin.
 */
function wp_image_webp_converter_activate() {
    $scheduled = wp_next_scheduled( WP_Image_WebP_Converter::CRON_HOOK );
    while ( $scheduled ) {
        wp_unschedule_event( $scheduled, WP_Image_WebP_Converter::CRON_HOOK );
        $scheduled = wp_next_scheduled( WP_Image_WebP_Converter::CRON_HOOK );
    }

    if ( false === get_option( WP_Image_WebP_Converter::OPTION_NAME, false ) ) {
        add_option(
            WP_Image_WebP_Converter::OPTION_NAME,
            WP_Image_WebP_Converter::default_options()
        );
    }

    // Generate the reference image used by the quality preview, so it exists before
    // anyone opens the settings page.
    $plugin = new WP_Image_WebP_Converter();
    $plugin->generate_sample();

    // Create the rewrite queue tables. dbDelta is idempotent, so running this on
    // every activation reconciles an existing install rather than failing on it.
    $plugin->boot_queue()->install_tables();
}
register_activation_hook( __FILE__, 'wp_image_webp_converter_activate' );

/**
 * Runs on deactivation.
 *
 * Stops background work and marks any in-flight run as paused. The counters are
 * kept so the run can be resumed, and the lock is cleared because its holder is gone.
 */
function wp_image_webp_converter_deactivate() {
    $scheduled = wp_next_scheduled( WP_Image_WebP_Converter::CRON_HOOK );
    while ( $scheduled ) {
        wp_unschedule_event( $scheduled, WP_Image_WebP_Converter::CRON_HOOK );
        $scheduled = wp_next_scheduled( WP_Image_WebP_Converter::CRON_HOOK );
    }

    update_option( WP_Image_WebP_Converter::OPTION_ACTIVE, 0 );
    delete_transient( WP_Image_WebP_Converter::LOCK_TRANSIENT );

    // Same treatment for the rewrite queue: queued work and history are kept so
    // the run resumes on reactivation, but nothing fires while deactivated.
    $queue_scheduled = wp_next_scheduled( WP_Image_WebP_Converter_Queue::CRON_HOOK );
    while ( $queue_scheduled ) {
        wp_unschedule_event( $queue_scheduled, WP_Image_WebP_Converter_Queue::CRON_HOOK );
        $queue_scheduled = wp_next_scheduled( WP_Image_WebP_Converter_Queue::CRON_HOOK );
    }

    update_option( WP_Image_WebP_Converter_Queue::OPTION_WORKING, 0 );
    delete_transient( WP_Image_WebP_Converter_Queue::LOCK_TRANSIENT );
}
register_deactivation_hook( __FILE__, 'wp_image_webp_converter_deactivate' );
