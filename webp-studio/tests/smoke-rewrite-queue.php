<?php
/**
 * Smoke test for the URL rewrite queue.
 *
 * Reproduces the real bug end to end: a JPEG is uploaded, the converter replaces
 * it with WebP and deletes the original, and everything already stored inside
 * the post is left pointing at a file that no longer exists. The queue then has
 * to put those references back in order without touching anything else.
 *
 * Run with:
 *   wp eval-file wp-content/plugins/webp-studio/tests/smoke-rewrite-queue.php
 *
 * Everything it creates is removed again before it exits, including the queue
 * and log rows, so it can be run repeatedly against the same site.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_set_current_user( 1 );

global $wpdb;

// The queue refuses to work outside cron or AJAX, by design. wp-cli is neither,
// so the same guard the plugin uses is declared here rather than bypassed.
if ( ! defined( 'DOING_CRON' ) ) {
    define( 'DOING_CRON', true );
}

$plugin = new WP_Image_WebP_Converter();
$queue  = $plugin->boot_queue();

$created_post     = 0;
$created_att      = 0;
$created_option   = '';

$GLOBALS['wps_smoke_failures'] = 0;

/**
 * Reports one smoke-test check.
 *
 * @param string $label    What was checked.
 * @param bool   $ok       Whether it held.
 * @param string $observed Observed value.
 */
function smoke_check( $label, $ok, $observed = '' ) {
    // wp eval-file evaluates this file inside a function scope, so a plain
    // file-scope counter would not be the same variable this function sees
    // through `global`. Same reason verify-runtime.php uses $GLOBALS.
    if ( ! $ok ) {
        $GLOBALS['wps_smoke_failures']++;
    }

    printf( "  %s  %-46s %s\n", $ok ? 'PASS' : 'FAIL', $label, $observed );
}

echo "== Prepare ==\n";

smoke_check( 'queue tables ready', $queue->tables_exist(), $queue->queue_table() );

// A real JPEG on disk, produced with GD so the test does not depend on any
// fixture file being present.
$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . '2026/10';

if ( ! is_dir( $dir ) ) {
    wp_mkdir_p( $dir );
}

$name   = 'wps-smoke-' . wp_rand( 100000, 999999 );
$source = $dir . '/' . $name . '.jpg';
$canvas = imagecreatetruecolor( 1200, 800 );

for ( $x = 0; $x < 1200; $x += 40 ) {
    $color = imagecolorallocate( $canvas, $x % 255, ( $x * 2 ) % 255, 128 );
    imagefilledrectangle( $canvas, $x, 0, $x + 20, 800, $color );
}
imagefilledellipse( $canvas, 600, 400, 500, 500, imagecolorallocate( $canvas, 255, 255, 255 ) );
$ok = imagejpeg( $canvas, $source, 92 );
imagedestroy( $canvas );

smoke_check( 'source JPEG written', $ok && file_exists( $source ), basename( $source ) );

// Registered as a real attachment, exactly as an upload would be.
$filetype      = wp_check_filetype( $source );
$attachment_id = wp_insert_attachment(
    array(
        'post_mime_type' => 'image/jpeg',
        'post_title'     => 'Smoke test image',
        'post_content'   => '',
        'post_status'    => 'inherit',
        'guid'           => trailingslashit( $uploads['baseurl'] ) . '2026/10/' . $name . '.jpg',
    ),
    $source
);

wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $source ) );

$created_att = (int) $attachment_id;
$jpg_url     = trailingslashit( $uploads['baseurl'] ) . '2026/10/' . $name . '.jpg';
$thumb_url   = trailingslashit( $uploads['baseurl'] ) . '2026/10/' . $name . '-150x150.jpg';

smoke_check(
    'attachment registered as JPEG',
    $created_att > 0 && 'image/jpeg' === get_post_mime_type( $created_att ),
    'id=' . $created_att . ' mime=' . get_post_mime_type( $created_att )
);

$before = wp_get_attachment_metadata( $created_att );
smoke_check(
    'attachment has a thumbnail',
    isset( $before['sizes']['thumbnail']['file'] ),
    $before['sizes']['thumbnail']['file'] ?? 'none'
);

echo "\n== Post with three reference styles ==\n";

// 1. Featured image. Stored as an attachment id, so its URL is produced by the
//    attachment record rather than by the post body.
// 2. A raw <img> written by hand in the body.
// 3. A core/image block, whose URL lives inside a JSON comment.
$block = sprintf(
    '<!-- wp:image {"id":%d,"sizeSlug":"full","link":"%s","className":"","alt":"","url":"%s"} -->
<figure class="wp-block-image size-full"><img src="%s" alt="" class="wp-image-%d"/></figure>
<!-- /wp:image -->',
    $created_att,
    $jpg_url,
    $jpg_url,
    $jpg_url,
    $created_att
);

$body = "<p>Antes:</p>\n<img src=\"{$jpg_url}\" alt=\"cruda\" width=\"1200\" height=\"800\" />\n"
    . $block . "\n"
    // A hot link that must survive untouched: no local attachment owns it.
    . "<p><img src=\"https://efe.com/wp-content/uploads/2019/03/externa.jpg\" alt=\"externa\" /></p>\n"
    . "<p><img src=\"https://cdn.s3.amazonaws.com/otro.png\" alt=\"s3\" /></p>\n";

$post_id = wp_insert_post(
    array(
        'post_title'   => 'Smoke test rewrite',
        'post_content' => $body,
        'post_status'  => 'publish',
    )
);

set_post_thumbnail( $post_id, $created_att );

$created_post = (int) $post_id;

smoke_check( 'post created', $created_post > 0, 'id=' . $created_post );
smoke_check( 'featured image set', (int) get_post_thumbnail_id( $created_post ) === $created_att );

// A custom field and an option, so the postmeta and option branches are covered
// by real data too rather than only by the assertions in verify-runtime.php.
//
// The option is added as an array on purpose: add_option() runs maybe_serialize()
// on whatever it is handed, so passing an already-serialized value stores a
// double-serialized string. That shape is common in the wild and is covered
// separately below.
add_post_meta( $created_post, 'wps_smoke_field', $jpg_url );

$created_option   = 'wps_smoke_option_' . wp_rand( 100000, 999999 );
$created_option2  = 'wps_smoke_doubled_' . wp_rand( 100000, 999999 );

add_option( $created_option, array( 'hero' => $jpg_url, 'count' => 7 ), '', 'yes' );
// Deliberately pre-serialized: WordPress wraps it again on write.
add_option( $created_option2, serialize( array( 'hero' => $jpg_url, 'count' => 7 ) ), '', 'yes' );

$stored = get_post_field( 'post_content', $created_post );

// Four occurrences, not three: the core/image block repeats its URL in the JSON
// attribute and again in the <img> it renders, plus the standalone raw <img>.
$expected_refs = 4;

smoke_check(
    'body still points at JPEG before conversion',
    $expected_refs === substr_count( $stored, $jpg_url ),
    substr_count( $stored, $jpg_url ) . ' occurrences (expected ' . $expected_refs . ')'
);

echo "\n== Convert (the original is deleted) ==\n";

$method = new ReflectionMethod( $plugin, 'convert_attachment' );
$method->setAccessible( true );
$metadata = $method->invoke( $plugin, $created_att, array( 'quality' => 80, 'delete_original' => 1 ) );

if ( is_array( $metadata ) ) {
    wp_update_attachment_metadata( $created_att, $metadata );
}

$webp_relative = get_post_meta( $created_att, '_wp_attached_file', true );
$webp_url      = trailingslashit( $uploads['baseurl'] ) . $webp_relative;

smoke_check( 'attachment converted to WebP', 'image/webp' === get_post_mime_type( $created_att ), $webp_relative );
smoke_check( 'original JPEG deleted from disk', ! file_exists( $source ) );
smoke_check( 'WebP exists on disk', file_exists( trailingslashit( $uploads['basedir'] ) . $webp_relative ) );

// This is the actual defect: the file the content points at is gone.
smoke_check(
    'DEFECT REPRODUCED: content URL is now a 404',
    ! file_exists( trailingslashit( $uploads['basedir'] ) . '2026/10/' . $name . '.jpg' ),
    'body still says .jpg, file is gone'
);

echo "\n== Dry run reports without writing ==\n";

// Three object types: post body, a custom field, and a serialized option.
$queue->enqueue_item( array( 'object_type' => 'post', 'object_id' => $created_post ) );
$queue->enqueue_item( array( 'object_type' => 'postmeta', 'object_id' => $created_post, 'secondary' => 'wps_smoke_field' ) );

$option_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $created_option ) );
$queue->enqueue_item( array( 'object_type' => 'option', 'object_id' => $option_id, 'secondary' => $created_option ) );

$option2_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $created_option2 ) );
$queue->enqueue_item( array( 'object_type' => 'option', 'object_id' => $option2_id, 'secondary' => $created_option2 ) );

$content_before_dry  = get_post_field( 'post_content', $created_post );
$field_before_dry    = get_post_meta( $created_post, 'wps_smoke_field', true );
$option_before_dry   = get_option( $created_option );
$option2_before_dry  = get_option( $created_option2 );

// 4 in the post body, 1 in the custom field, 1 in each option.
$expected_total = $expected_refs + 3;

$dry               = $queue->process( array( 'dry_run' => true, 'budget' => 5 ) );
$content_after_dry = get_post_field( 'post_content', $created_post );

smoke_check(
    'dry run counted every reference',
    $expected_total === (int) $dry['replacements'],
    $dry['replacements'] . ' URLs would change (expected ' . $expected_total . ')'
);
smoke_check( 'dry run wrote no content', $content_before_dry === $content_after_dry );
smoke_check( 'dry run wrote no custom field', $field_before_dry === get_post_meta( $created_post, 'wps_smoke_field', true ) );
smoke_check( 'dry run wrote no option', $option_before_dry === get_option( $created_option ) );

echo "\n== Rewrite ==\n";

$result = $queue->process( array( 'budget' => 10 ) );
$after  = get_post_field( 'post_content', $created_post );

echo "  process: " . wp_json_encode( $result ) . "\n\n";

// Post body, custom field, and the two options.
smoke_check( 'four entries rewritten', 4 === (int) $result['rewritten'], 'rewritten=' . $result['rewritten'] );
smoke_check( 'all references became WebP', $expected_refs === substr_count( $after, $webp_url ), substr_count( $after, $webp_url ) . ' occurrences' );
smoke_check( 'no JPEG URL left in the body', false === strpos( $after, $jpg_url ) );

// Each of the three styles, named.
smoke_check( 'raw <img> rewritten', false !== strpos( $after, '<img src="' . $webp_url . '" alt="cruda"' ) );
smoke_check( 'core/image block rewritten', false !== strpos( $after, '"url":"' . $webp_url . '"' ) );
smoke_check(
    'featured image resolves to WebP',
    false !== strpos( (string) wp_get_attachment_url( $created_att ), '.webp' ),
    wp_get_attachment_url( $created_att )
);

echo "\n== Custom field and serialized options ==\n";

$field_after = get_post_meta( $created_post, 'wps_smoke_field', true );
smoke_check( 'custom field rewritten', $field_after === $webp_url, $field_after );

// get_option() runs maybe_unserialize() itself, so it hands back the array.
$option = get_option( $created_option );

smoke_check( 'option still unserializes', is_array( $option ), gettype( $option ) );
smoke_check( 'option hero rewritten', isset( $option['hero'] ) && $option['hero'] === $webp_url, isset( $option['hero'] ) ? $option['hero'] : 'missing' );
smoke_check( 'option integer survived', isset( $option['count'] ) && 7 === $option['count'], isset( $option['count'] ) ? var_export( $option['count'], true ) : 'missing' );

// The double-serialized option. get_option() strips one layer, so what comes
// back is still a serialized string; it has to be readable as such. A naive
// replace would have grown the URLs without updating the byte lengths declared
// inside, and both unserialize() calls below would return false.
$option2 = get_option( $created_option2 );
$inner   = is_string( $option2 ) ? @unserialize( $option2 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

smoke_check( 'double-serialized option readable', is_array( $inner ), gettype( $inner ) );
smoke_check( 'double-serialized hero rewritten', is_array( $inner ) && isset( $inner['hero'] ) && $inner['hero'] === $webp_url, is_array( $inner ) && isset( $inner['hero'] ) ? $inner['hero'] : 'missing' );
smoke_check( 'double-serialized integer survived', is_array( $inner ) && isset( $inner['count'] ) && 7 === $inner['count'], is_array( $inner ) && isset( $inner['count'] ) ? var_export( $inner['count'], true ) : 'missing' );

// And the raw bytes really are valid serialized data at both layers.
$raw2 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $created_option2 ) );
$raw2_ok = @unserialize( $raw2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
$raw2_ok = is_string( $raw2_ok ) ? @unserialize( $raw2_ok ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

smoke_check( 'raw stored bytes unserialize twice', is_array( $raw2_ok ), strlen( $raw2 ) . ' bytes' );

echo "\n== Hot links are untouched ==\n";

smoke_check( 'efe.com URL untouched', false !== strpos( $after, 'https://efe.com/wp-content/uploads/2019/03/externa.jpg' ) );
smoke_check( 's3.amazonaws.com URL untouched', false !== strpos( $after, 'https://cdn.s3.amazonaws.com/otro.png' ) );

echo "\n== Attachment intact ==\n";

// get_post(), not wp_get_attachment(): the latter is not a core function and
// calling it here would abort the run on an undefined-function fatal.
$still = get_post( $created_att );

smoke_check( 'attachment still exists', (bool) $still, 'id=' . $created_att );
smoke_check( 'attached file is the WebP', get_post_meta( $created_att, '_wp_attached_file', true ) === $webp_relative );
smoke_check( 'mime still WebP', 'image/webp' === $still->post_mime_type );
smoke_check( 'guid rewritten too', false !== strpos( $still->guid, $name . '.webp' ), $still->guid );

$meta_now = wp_get_attachment_metadata( $created_att );
smoke_check(
    'thumbnail metadata intact',
    isset( $meta_now['sizes']['thumbnail']['file'] ),
    $meta_now['sizes']['thumbnail']['file'] ?? 'none'
);
smoke_check(
    'thumbnail file exists on disk',
    file_exists( trailingslashit( $uploads['basedir'] ) . dirname( $webp_relative ) . '/' . $meta_now['sizes']['thumbnail']['file'] ),
    $meta_now['sizes']['thumbnail']['file'] ?? 'none'
);

echo "\n== Log and rollback ==\n";

global $wpdb;
$log_table = $queue->log_table();
// Scoped to the post body: the same object id is also used by the postmeta entry,
// and ORDER BY id DESC alone would hand back whichever of them ran last.
$log_row = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$log_table} WHERE object_type = 'post' AND object_id = %d ORDER BY id DESC LIMIT 1",
        $created_post
    ),
    ARRAY_A
);

smoke_check( 'log row written', (bool) $log_row, $log_row ? 'replacements=' . $log_row['replacements'] : 'none' );
smoke_check( 'log stores the full previous value', $log_row && $log_row['old_value'] === $content_before_dry, strlen( (string) $log_row['old_value'] ) . ' bytes' );
smoke_check( 'log hash matches the previous value', $log_row && $log_row['old_hash'] === sha1( $content_before_dry ) );

$rollback = $queue->rollback( (int) $log_row['queue_id'] );

smoke_check( 'rollback succeeded', ! is_wp_error( $rollback ) && $rollback['restored'], is_wp_error( $rollback ) ? $rollback->get_error_message() : 'ok' );

$restored = get_post_field( 'post_content', $created_post );
smoke_check( 'body restored byte for byte', $restored === $content_before_dry, md5( $restored ) === md5( $content_before_dry ) ? 'md5 match' : 'MISMATCH' );

// Rolling back one entry must leave its siblings alone. The option is a separate
// queue row with its own log row, and it was not the one undone.
$untouched_option = get_option( $created_option );
smoke_check(
    'rollback leaves other entries alone',
    is_array( $untouched_option )
        && isset( $untouched_option['hero'] )
        && $untouched_option['hero'] === $webp_url,
    is_array( $untouched_option ) && isset( $untouched_option['hero'] )
        ? ( $untouched_option['hero'] === $webp_url ? 'option still webp' : 'option changed: ' . $untouched_option['hero'] )
        : 'option unreadable'
);

echo "\n== Idempotency ==\n";

$queue->enqueue_item( array( 'object_type' => 'post', 'object_id' => $created_post ) );

$rows_for_post = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$queue->queue_table()} WHERE object_type = 'post' AND object_id = %d", $created_post )
);
smoke_check( 'enqueue twice yields one row', 1 === $rows_for_post, $rows_for_post . ' rows' );

// Re-enqueuing an entry that was rolled back must wake it up and rewrite again,
// otherwise the undo would be a one-way door with no way back in.
//
// Only the post was rolled back, so only the post is reactivated. The options are
// still 'done' and stay untouched, which is what the "finished entry is not
// reprocessed" check below confirms from the other direction.
$queue->enqueue_item( array( 'object_type' => 'post', 'object_id' => $created_post ) );
$queue->enqueue_item( array( 'object_type' => 'option', 'object_id' => $option_id, 'secondary' => $created_option ) );

$reapplied = $queue->process( array( 'budget' => 10 ) );
$after_re  = get_post_field( 'post_content', $created_post );

smoke_check(
    'rolled-back entry is rewritten again',
    1 === (int) $reapplied['rewritten'] && $expected_refs === substr_count( $after_re, $webp_url ),
    'rewritten=' . $reapplied['rewritten']
);

// Enqueuing an entry that already finished must change nothing at all.
$queue->enqueue_item( array( 'object_type' => 'post', 'object_id' => $created_post ) );

$third = $queue->process( array( 'budget' => 10 ) );

smoke_check(
    'finished entry is not reprocessed',
    0 === (int) $third['processed'] && 'empty' === $third['reason'],
    'processed=' . $third['processed'] . ' reason=' . $third['reason']
);
smoke_check(
    'content stable across the third pass',
    get_post_field( 'post_content', $created_post ) === $after_re,
    md5( get_post_field( 'post_content', $created_post ) ) === md5( $after_re ) ? 'md5 match' : 'MISMATCH'
);

echo "\n== Cleanup ==\n";

if ( $created_option ) {
    delete_option( $created_option );
    delete_option( $created_option2 );
}

if ( $created_post ) {
    // Force removal: the files were already deleted by the conversion above, so
    // wp_delete_attachment() must not be allowed to abort on the missing file.
    wp_delete_post( $created_post, true );
}

if ( $created_att ) {
    wp_delete_attachment( $created_att, true );
}

// Queue and log rows for every object type this test created.
foreach ( array( $log_table, $queue->queue_table() ) as $table ) {
    $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->prepare(
            "DELETE FROM {$table}
             WHERE object_id IN (%d, %d)
                OR (object_type = 'option' AND secondary IN (%s, %s))",
            $created_post,
            $created_att,
            $created_option,
            $created_option2
        )
    );
}

if ( file_exists( $source ) ) {
    @unlink( $source );
}

foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . '2026/10/' . $name . '*' ) as $leftover ) {
    @unlink( $leftover );
}

$gone_post = ! get_post( $created_post ) && ! get_post( $created_att );
smoke_check( 'test post and attachment removed', $gone_post );

$residue = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$log_table}
         WHERE object_id IN (%d, %d) OR (object_type = 'option' AND secondary IN (%s, %s))",
        $created_post,
        $created_att,
        $created_option,
        $created_option2
    )
);
smoke_check( 'no log rows left behind', 0 === $residue, $residue . ' rows' );

$queue_residue = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$queue->queue_table()}
         WHERE object_id IN (%d, %d) OR (object_type = 'option' AND secondary IN (%s, %s))",
        $created_post,
        $created_att,
        $created_option,
        $created_option2
    )
);
smoke_check( 'no queue rows left behind', 0 === $queue_residue, $queue_residue . ' rows' );

$smoke_failures = (int) $GLOBALS['wps_smoke_failures'];

printf( "\nSMOKE RESULT: %s\n", $smoke_failures > 0 ? $smoke_failures . ' FAILED' : 'all checks passed' );

if ( $smoke_failures > 0 ) {
    exit( 1 );
}
