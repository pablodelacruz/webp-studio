<?php
/**
 * Uninstall handler: removes every option this plugin created.
 *
 * Deliberately does NOT delete converted images. Restoring the original JPEG from
 * a WebP is not possible (the source was deleted), so removing files here could
 * destroy user content irreversibly.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$wcfn_options = array(
    'wp_image_webp_converter_settings',
    'wp_image_webp_converter_total_images',
    'wp_image_webp_converter_last_processed_id',
    'wp_image_webp_converter_processed_count',
    'wp_image_webp_converter_failed_count',
    'wp_image_webp_converter_processing_active',
    'wp_image_webp_converter_updated_at',
    'wp_image_webp_converter_history',
    'wp_image_webp_converter_skip_reasons',
);

foreach ( $wcfn_options as $wcfn_option ) {
    delete_option( $wcfn_option );
}

delete_transient( 'wp_image_webp_converter_batch_lock' );

$wcfn_scheduled = wp_next_scheduled( 'wp_image_webp_converter_batch' );
while ( $wcfn_scheduled ) {
    wp_unschedule_event( $wcfn_scheduled, 'wp_image_webp_converter_batch' );
    $wcfn_scheduled = wp_next_scheduled( 'wp_image_webp_converter_batch' );
}

/* ------------------------------------------------------------------------- */
/* Rewrite queue                                                             */
/* ------------------------------------------------------------------------- */

$wcfn_queue_options = array(
    'wp_image_webp_converter_queue_schema',
    'wp_image_webp_converter_queue_cursor',
    'wp_image_webp_converter_queue_scan',
    'wp_image_webp_converter_queue_working',
    'wp_image_webp_converter_queue_enqueued',
    'wp_image_webp_converter_queue_updated_at',
    'wp_image_webp_converter_queue_replacements',
);

foreach ( $wcfn_queue_options as $wcfn_queue_option ) {
    delete_option( $wcfn_queue_option );
}

delete_transient( 'wp_image_webp_converter_queue_lock' );

$wcfn_queue_scheduled = wp_next_scheduled( 'wp_image_webp_converter_queue_batch' );
while ( $wcfn_queue_scheduled ) {
    wp_unschedule_event( $wcfn_queue_scheduled, 'wp_image_webp_converter_queue_batch' );
    $wcfn_queue_scheduled = wp_next_scheduled( 'wp_image_webp_converter_queue_batch' );
}

global $wpdb;

// The queue and its history are plugin bookkeeping and hold no user content, so
// they are removed with the plugin. Destructive only if reinstalled afterwards:
// the stored "previous value" that makes a rewrite reversible goes with them.
//
// webp_refs is the reference index and is dropped here too. install_tables()
// creates three tables, and dropping only two left the index orphaned in the
// database of anyone who ever uninstalled the plugin. queue->drop_tables()
// already removed all three; this file simply had not kept up.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}webp_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}webp_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}webp_refs" );

// Drop the per-attachment marker. This is plugin bookkeeping only; the image files
// and all other attachment metadata are left alone.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wp_image_webp_converted'" );

// Remove the plugin's own generated artifacts. These were created by this plugin, so
// they are safe to delete. No user content is touched.
$wcfn_uploads = wp_upload_dir();
if ( empty( $wcfn_uploads['error'] ) ) {
    $wcfn_base = trailingslashit( $wcfn_uploads['basedir'] );

    // Reference image used by the quality preview.
    if ( file_exists( $wcfn_base . 'webp-converter-sample.png' ) ) {
        @unlink( $wcfn_base . 'webp-converter-sample.png' );
    }

    // Generated quality previews.
    $wcfn_preview_dir = $wcfn_base . 'webp-converter-previews';
    if ( is_dir( $wcfn_preview_dir ) ) {
        foreach ( (array) glob( $wcfn_preview_dir . '/*' ) as $wcfn_file ) {
            if ( is_file( $wcfn_file ) ) {
                @unlink( $wcfn_file );
            }
        }
        @rmdir( $wcfn_preview_dir );
    }
}

// Preview caches.
foreach ( range( 0, 100 ) as $wcfn_q ) {
    delete_transient( 'wcfn_preview_' . $wcfn_q );
}
