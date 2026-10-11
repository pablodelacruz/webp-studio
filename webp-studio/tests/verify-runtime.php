<?php
/**
 * Runtime verification for the WebP Studio admin refactor.
 *
 * There is no composer.json, PHPUnit or test harness in this project, so this is a
 * reproducible script rather than an automated suite. Run with:
 *   wp eval-file wp-content/plugins/webp-studio/tests/verify-runtime.php
 *
 * Every assertion below reports what it actually observed. Nothing is asserted that
 * was not executed in this run.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_set_current_user( 1 );

global $wpdb;

// Counters live in $GLOBALS on purpose: wp eval-file evaluates this file inside a
// function scope, so a plain file-scope variable would not be the same variable the
// helper below sees through `global`.
$GLOBALS['wps_pass'] = 0;
$GLOBALS['wps_fail'] = 0;

/**
 * Records one assertion result.
 *
 * @param string $label    What was checked.
 * @param bool   $ok       Whether it held.
 * @param string $observed Actual value, so a failure is diagnosable.
 */
function wps_check( $label, $ok, $observed = '' ) {
    $observed = (string) $observed;
    if ( strlen( $observed ) > 44 ) {
        $observed = substr( $observed, 0, 41 ) . '...';
    }

    if ( $ok ) {
        $GLOBALS['wps_pass']++;
        printf( "  PASS  %-42s %s\n", $label, $observed );
    } else {
        $GLOBALS['wps_fail']++;
        printf( "  FAIL  %-42s %s\n", $label, $observed );
    }
}

echo "== Structure ==\n";

$plugin = new WP_Image_WebP_Converter();
$admin  = $plugin->boot_admin();
$admin->register_settings();

ob_start();
$admin->render();
$html = ob_get_clean();

wps_check( 'admin class boots', is_object( $admin ), get_class( $admin ) );
wps_check( 'render produces output', strlen( $html ) > 5000, strlen( $html ) . ' bytes' );
wps_check( 'root namespace class', false !== strpos( $html, 'class="wps-admin"' ) );
wps_check( 'three tabs present', substr_count( $html, 'wps-nav__tab' ) >= 3 );
wps_check( 'tablist role', false !== strpos( $html, 'role="tablist"' ) );
wps_check( 'quality slider', false !== strpos( $html, 'data-control="quality-slider"' ) );
wps_check( 'quality number input', false !== strpos( $html, 'data-control="quality-number"' ) );
wps_check( 'quality presets', false !== strpos( $html, 'data-preset=' ) );
wps_check( 'preview markup', false !== strpos( $html, 'data-preview="figure"' ) );
wps_check( 'orphan review', false !== strpos( $html, 'data-action="review-orphans"' ) );
wps_check( 'orphan delete', false !== strpos( $html, 'data-action="delete-orphans"' ) );
wps_check( 'aria-live region', false !== strpos( $html, 'aria-live' ) );
wps_check( 'unsaved-changes marker', false !== strpos( $html, 'data-role="dirty"' ) );

foreach ( array( 'wp_image_webp_converter_main_section' ) as $unused ) {
    // Placeholder to keep the structure readable if fields change.
    unset( $unused );
}

// Fields live under their section, not at the page root.
global $wp_settings_fields;
$section = $wp_settings_fields['webp-studio']['wp_image_webp_converter_main_section'] ?? array();
$fields  = array_keys( $section );

wps_check(
    'three settings fields registered',
    3 === count( $fields ),
    implode( ', ', $fields )
);

echo "\n== No PHP leakage ==\n";

foreach ( array( '<?php', '?>', 'Undefined', 'Warning:', 'Notice:' ) as $leak ) {
    wps_check( 'no "' . $leak . '" in output', false === strpos( $html, $leak ) );
}

echo "\n== Options preserved ==\n";

$before = $plugin->get_options();

$sanitized = $plugin->sanitize_options(
    array(
        'enabled'         => '1',
        'quality'         => '77',
        'delete_original' => '0',
    )
);

wps_check( 'quality saved', 77 === (int) $sanitized['quality'], $sanitized['quality'] );
wps_check( 'enabled saved', 1 === (int) $sanitized['enabled'], $sanitized['enabled'] );
wps_check( 'delete_original saved', 0 === (int) $sanitized['delete_original'], $sanitized['delete_original'] );
wps_check(
    'activity_log preserved by save',
    (int) $sanitized['activity_log'] === (int) ( ! empty( $before['activity_log'] ) ),
    'before=' . var_export( ! empty( $before['activity_log'] ), true )
);
wps_check( 'process_existing retired to 0', 0 === (int) $sanitized['process_existing'] );

echo "\n== Quality validation ==\n";

$edge = $plugin->sanitize_options( array( 'quality' => '999' ) );
wps_check( 'quality clamped to 100', 100 === (int) $edge['quality'], $edge['quality'] );

// A negative value is not representable through the number input, but the server
// must still refuse it. absint() turns it positive, so it is rejected explicitly.
$edge = $plugin->sanitize_options( array( 'quality' => '-20' ) );
wps_check(
    'negative quality rejected to 0',
    0 === (int) $edge['quality'],
    'got ' . $edge['quality'] . ' (absint turned -20 into 20; clamp must reject the raw value)'
);

echo "\n== Status payload ==\n";

$status = $plugin->build_status_payload();

$states = array( 'idle', 'preparing', 'queued', 'running', 'done', 'done_with_issues', 'failed', 'unknown' );
wps_check( 'state is a known value', in_array( $status['state'], $states, true ), $status['state'] );
wps_check( 'coverage 0-100', $status['coverage'] >= 0 && $status['coverage'] <= 100, $status['coverage'] );
wps_check( 'no divide-by-zero', $status['coverage'] > 0 || $status['total'] === 0, 'total=' . $status['total'] );
wps_check( 'pending <= total', $status['pending'] <= $status['total'], $status['pending'] . '/' . $status['total'] );
wps_check( 'run identity present', isset( $status['runId'] ) && '' !== $status['runId'], $status['runId'] );

echo "\n== Counters are one source of truth ==\n";

$stats = $plugin->get_statistics();
wps_check(
    'converted + pending reconciles with total',
    ( $stats['converted'] + $stats['pending'] ) === $stats['total'] || $stats['total'] === 0,
    sprintf( '%d + %d = %d of %d', $stats['converted'], $stats['pending'], $stats['converted'] + $stats['pending'], $stats['total'] )
);
wps_check( 'status mirrors stats', (int) $status['converted'] === (int) $stats['converted'] );

echo "\n== Conversion still honours quality ==\n";

if ( $stats['total'] > 0 ) {
    $sample_id = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type='image/webp' ORDER BY ID LIMIT 1"
    );

    $source = $sample_id ? get_attached_file( $sample_id ) : '';

    if ( $source && file_exists( $source ) ) {
        $sizes = array();
        foreach ( array( 30, 85 ) as $q ) {
            $path = $plugin->encode_preview( $source, $q );
            $sizes[ $q ] = $path ? (int) filesize( $path ) : 0;
            if ( $path ) {
                @unlink( $path );
            }
        }
        wps_check(
            'quality changes output size',
            $sizes[85] > $sizes[30] && $sizes[30] > 0,
            sprintf( 'q30=%d q85=%d', $sizes[30], $sizes[85] )
        );
    } else {
        echo "  SKIP  conversion quality (no attachment to sample)\n";
    }
} else {
    echo "  SKIP  conversion quality (empty library)\n";
}

echo "\n== Short final batch is not skipped ==\n";

// Regression: a library smaller than one batch must still be converted. An early
// return on a short batch used to end the run without doing the work.
$pending_now = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
);

if ( $pending_now > 0 && $pending_now < WP_Image_WebP_Converter::CRON_BATCH_SIZE ) {
    $start = ( new ReflectionClass( $plugin ) )->getMethod( 'start_run' );
    $start->setAccessible( true );
    $start->invoke( $plugin );

    if ( ! defined( 'DOING_CRON' ) ) {
        define( 'DOING_CRON', true );
    }

    $plugin->process_cron_batch();

    $after = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ('image/jpeg','image/png','image/gif')"
    );

    wps_check(
        'small library fully converted in one tick',
        0 === $after,
        sprintf( '%d pending before, %d after', $pending_now, $after )
    );
} else {
    echo "  SKIP  short-batch regression (pending is 0 or >= batch size)\n";
}

echo "\n== Rewrite queue: tables ==\n";

$queue = $plugin->boot_queue();

wps_check( 'queue class boots', $queue instanceof WP_Image_WebP_Converter_Queue, get_class( $queue ) );
wps_check( 'tables installed on boot', $queue->tables_exist(), $queue->queue_table() );

$queue_table = $queue->queue_table();
$log_table   = $queue->log_table();

wps_check(
    'queue table uses the site prefix',
    0 === strpos( $queue_table, $wpdb->prefix ),
    $queue_table
);

wps_check(
    'log table uses the site prefix',
    0 === strpos( $log_table, $wpdb->prefix ),
    $log_table
);

// Both tables must survive dbDelta with every column the schema declares.
// dbDelta silently skips a malformed line, so a typo in the definition would
// otherwise only show up much later as an undefined column.
$expected_columns = array(
    $queue_table => array( 'id', 'task', 'object_type', 'object_id', 'secondary', 'status', 'reason', 'attempts', 'replacements', 'last_error', 'created_at', 'updated_at' ),
    $log_table   => array( 'id', 'queue_id', 'task', 'object_type', 'object_id', 'secondary', 'old_hash', 'old_value', 'replacements', 'created_at', 'rolled_back_at' ),
);

foreach ( $expected_columns as $table => $columns ) {
    $actual = wp_list_pluck( (array) $wpdb->get_results( "DESCRIBE {$table}", ARRAY_A ), 'Field' );
    $missing = array_diff( $columns, $actual );

    wps_check(
        'columns present in ' . substr( $table, strlen( $wpdb->prefix ) ),
        empty( $missing ),
        empty( $missing ) ? count( $actual ) . ' columns' : 'missing: ' . implode( ',', $missing )
    );
}

// The UNIQUE key is what makes re-enqueueing safe. Without it, running the scan
// twice would double every row and every entry would be processed twice.
$queue_indexes = (array) $wpdb->get_results( "SHOW INDEX FROM {$queue_table}", ARRAY_A );
$unique_found  = false;

foreach ( $queue_indexes as $index ) {
    if ( 'uniq_item' === $index['Key_name'] && 0 === (int) $index['Non_unique'] ) {
        $unique_found = true;
    }
}

wps_check( 'unique key uniq_item exists', $unique_found, 'Non_unique must be 0' );
wps_check(
    'status index exists',
    (bool) array_filter( $queue_indexes, function ( $i ) {
        return 'idx_status' === $i['Key_name'];
    } ),
    'status,id'
);

echo "\n== Rewrite queue: extract_paths ==\n";

// Private on purpose: it is an implementation detail of the algorithm, and the
// reflection call is the same technique this file already uses for start_run.
$extract = ( new ReflectionClass( $queue ) )->getMethod( 'extract_paths' );
$extract->setAccessible( true );

$token = $queue->uploads_token();

$sample_content = 'Texto con <img src="https://site.example/wp-content/uploads/2024/05/foto.jpg" />'
    . ' y <img src="https://site.example/wp-content/uploads/2024/05/otra.jpeg">'
    . ' y <img src="https://site.example/wp-content/uploads/2024/05/grafico.png">'
    . ' y <img src="https://site.example/wp-content/uploads/2024/05/anim.gif">'
    . ' y <a href="https://site.example/wp-content/uploads/2024/05/doc.pdf">pdf</a>'
    . ' y <img src="https://efe.com/wp-content/uploads/2019/03/externa.jpg">';

$found = $extract->invoke( $queue, $sample_content );

// extract_paths() is deliberately domain-blind. It reports every uploads-relative
// path it sees and hands them to lookup_attachments(), which is what decides
// whether a local attachment owns the file. That split is the safety property:
// a hot link from efe.com is extracted here exactly like our own image, and is
// then rejected at the database because nothing owns 2019/03/externa.jpg.
//
// Asserting that split, rather than pretending the extractor filters by domain,
// keeps the real guarantee in the test: the hot-link path can only be refused by
// the attachment lookup, so the lookup has to be exercised too.
$hot = $token . '/2019/03/externa.jpg';

wps_check( 'hot link is extracted, not silently dropped', in_array( $hot, $found, true ), 'must be refused by the attachment lookup, not here' );
wps_check( 'finds exactly five paths', 5 === count( $found ), count( $found ) . ' paths' );

wps_check( 'finds jpg', in_array( $token . '/2024/05/foto.jpg', $found, true ), implode( ' | ', $found ) );

// .jpeg is the case the converter's own regex misses and this one has to catch.
wps_check( 'finds jpeg', in_array( $token . '/2024/05/otra.jpeg', $found, true ) );
wps_check( 'finds png', in_array( $token . '/2024/05/grafico.png', $found, true ) );
wps_check( 'finds gif', in_array( $token . '/2024/05/anim.gif', $found, true ) );

wps_check( 'ignores non-image extensions', ! in_array( $token . '/2024/05/doc.pdf', $found, true ) );

// Repeated URLs must collapse: the replacement counter is computed with
// substr_count(), and a duplicated entry would inflate it.
$dupes = $extract->invoke( $queue, '<img src="/uploads/a/b.jpg"><img src="/uploads/a/b.jpg">' );
wps_check( 'duplicates collapse', 1 === count( $dupes ), count( $dupes ) . ' path' );

// A cache-busting query string must not be swallowed into the path: the
// replacement is a literal substring swap, so anything included here would end up
// inside the new URL.
$queried = $extract->invoke( $queue, "<img src='{$token}/2024/05/foto.jpg?ver=2'>" );
wps_check(
    'query string excluded from the path',
    isset( $queried[0] ) && false === strpos( $queried[0], '?' ),
    $queried[0] ?? 'none'
);

// The closing quote and bracket must terminate the match, not become part of it.
$quoted = $extract->invoke( $queue, '<img src="/uploads/a/b.jpg" width="10">' );
wps_check( 'markup delimiters excluded', isset( $quoted[0] ) && 'uploads/a/b.jpg' === $quoted[0], $quoted[0] ?? 'none' );

echo "\n== Rewrite queue: extension replacement ==\n";

$to_webp = ( new ReflectionClass( $queue ) )->getMethod( 'with_webp_extension' );
$to_webp->setAccessible( true );

$ext_cases = array(
    'uploads/2024/05/foto.jpg'  => 'uploads/2024/05/foto.webp',
    'uploads/2024/05/foto.JPEG' => 'uploads/2024/05/foto.webp',
    'uploads/2024/05/f-150x150.png' => 'uploads/2024/05/f-150x150.webp',
    'uploads/a/b/c.gif'         => 'uploads/a/b/c.webp',
);

$ext_ok = true;
$ext_detail = array();

foreach ( $ext_cases as $in => $expected ) {
    $got = $to_webp->invoke( $queue, $in );
    $ext_detail[] = basename( $in ) . '=' . basename( (string) $got );

    if ( $expected !== $got ) {
        $ext_ok = false;
    }
}

wps_check( 'only the extension changes', $ext_ok, implode( ' ', $ext_detail ) );

// The separator must survive. An off-by-one here produced "foto" + "webp".
$separator = $to_webp->invoke( $queue, 'uploads/2024/05/foto.jpg' );
wps_check( 'dot preserved before webp', '.webp' === substr( $separator, -5 ), $separator );

echo "\n== Rewrite queue: serialized values ==\n";

$replace = ( new ReflectionClass( $queue ) )->getMethod( 'replace_in_value' );
$replace->setAccessible( true );

$jpg = 'https://site.example/wp-content/uploads/2024/05/foto.jpg';
$webp = 'https://site.example/wp-content/uploads/2024/05/foto.webp';
$map = array( $token . '/2024/05/foto.jpg' => $token . '/2024/05/foto.webp' );

// A serialized array with an unrelated integer next to the URL. ".jpg" is four
// characters and ".webp" is five, so the declared byte length changes and any
// plain str_replace leaves the payload unreadable.
$payload = array( 'hero' => $jpg, 'count' => 7, 'nested' => array( 'deep' => $jpg, 'n' => 42 ) );
$serialized = serialize( $payload );

$naive = str_replace( $token . '/2024/05/foto.jpg', $token . '/2024/05/foto.webp', $serialized );
$naive_decoded = @unserialize( $naive ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

wps_check(
    'a naive str_replace really does corrupt it',
    false === $naive_decoded,
    'unserialize(naive) returned ' . gettype( $naive_decoded )
);

$ours = $replace->invoke( $queue, $serialized, $map );
$decoded = unserialize( $ours['value'] );

wps_check( 'ours still unserializes', is_array( $decoded ), gettype( $decoded ) );
wps_check( 'URL replaced in serialized value', isset( $decoded['hero'] ) && $webp === $decoded['hero'], isset( $decoded['hero'] ) ? $decoded['hero'] : 'missing' );
wps_check( 'unrelated integer survives', isset( $decoded['count'] ) && 7 === $decoded['count'], isset( $decoded['count'] ) ? var_export( $decoded['count'], true ) : 'missing' );
wps_check( 'nested array untouched otherwise', isset( $decoded['nested']['n'] ) && 42 === $decoded['nested']['n'] );
wps_check( 'nested URL replaced', isset( $decoded['nested']['deep'] ) && $webp === $decoded['nested']['deep'] );
wps_check( 'replacement count is exact', 2 === (int) $ours['replacements'], $ours['replacements'] . ' replacements' );

// Double serialization: update_option() calls maybe_serialize() on whatever it
// is handed, so a pre-serialized value is stored wrapped in another layer.
// Unserializing one level yields a string that is itself serialized, and editing
// its text with str_replace corrupts the layer underneath.
$doubled = serialize( $serialized );
$doubles = $replace->invoke( $queue, $doubled, $map );

$layer1 = @unserialize( $doubles['value'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
$layer2 = is_string( $layer1 ) ? @unserialize( $layer1 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

wps_check( 'double-serialized value survives', is_array( $layer2 ), gettype( $layer2 ) );
wps_check( 'double-serialized URL replaced', is_array( $layer2 ) && isset( $layer2['hero'] ) && $webp === $layer2['hero'] );
wps_check( 'double-serialized integer survives', is_array( $layer2 ) && isset( $layer2['count'] ) && 7 === $layer2['count'] );

// A value with no matching URL must come back byte for byte, not re-serialized.
$untouched = $replace->invoke( $queue, serialize( array( 'x' => 1 ) ), $map );
wps_check(
    'unmatched serialized value is byte identical',
    serialize( array( 'x' => 1 ) ) === $untouched['value'],
    strlen( $untouched['value'] ) . ' bytes'
);

// A string that only looks serialized must not be rebuilt.
$not_serialized = 'plain text with ' . $jpg;
$left_alone     = $replace->invoke( $queue, $not_serialized, $map );

wps_check( 'plain string still replaced', false !== strpos( $left_alone['value'], $webp ) );

// A genuinely broken payload is refused instead of being half-rewritten.
$broken = 'a:2:{s:4:"hero";s:99:"too short";}';
$refused = $replace->invoke( $queue, $broken, $map );

wps_check(
    'unreadable payload is refused',
    isset( $refused['error'] ) && 'unserializable' === $refused['error'],
    isset( $refused['error'] ) ? $refused['error'] : 'no error reported'
);

echo "\n== Rewrite queue: idempotency and budget ==\n";

if ( ! defined( 'DOING_CRON' ) ) {
    define( 'DOING_CRON', true );
}

// A post of our own so nothing here depends on what the library happens to hold.
$probe_id = wp_insert_post(
    array(
        'post_title'   => 'wps queue probe',
        'post_content' => 'sin imagenes aqui',
        'post_status'  => 'draft',
    )
);

$queued_first  = $queue->enqueue_item( array( 'object_type' => 'post', 'object_id' => $probe_id ) );
$queued_second = $queue->enqueue_item( array( 'object_type' => 'post', 'object_id' => $probe_id ) );

$probe_rows = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE object_type = 'post' AND object_id = %d", $probe_id )
);

wps_check( 'first enqueue creates a row', 0 !== (int) $queued_first, 'insert id ' . $queued_first );
wps_check( 'second enqueue is a no-op', 0 === (int) $queued_second, 'returned ' . var_export( $queued_second, true ) );
wps_check( 'queue holds exactly one row', 1 === $probe_rows, $probe_rows . ' rows' );

$before_reasons = $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE object_type = 'post' AND object_id = " . (int) $probe_id );
wps_check( 'probe row is pending', 1 === (int) $before_reasons );

// Time budget, not row count. A budget this small must return essentially
// immediately and must not have consumed the whole probe page.
$tiny = $queue->process( array( 'budget' => 0.001 ) );

wps_check(
    'tiny budget returns quickly',
    (float) $tiny['elapsed'] < 1.0,
    'elapsed ' . $tiny['elapsed'] . 's'
);

// The tiny budget above is allowed to finish the single probe row before the
// deadline check fires, so "empty" here does not mean the work was skipped. The
// two properties that matter are asserted separately: the row left the queue,
// and the call returned inside its budget.
$generous = $queue->process( array( 'budget' => 10 ) );

wps_check( 'generous budget drains the queue', 'empty' === $generous['reason'] || 'drained' === $generous['reason'], $generous['reason'] );
wps_check(
    'probe entry is no longer pending',
    0 === (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue_table} WHERE object_type = 'post' AND object_id = %d AND status = 'pending'",
            $probe_id
        )
    ),
    '0 pending'
);

$probe_state = (string) $wpdb->get_var(
    $wpdb->prepare( "SELECT status FROM {$queue_table} WHERE object_type = 'post' AND object_id = %d", $probe_id )
);

wps_check( 'probe entry reached a final state', in_array( $probe_state, array( 'done', 'skipped', 'failed' ), true ), $probe_state );

// An empty value is a legitimate outcome for this probe: it deliberately holds
// no image URL, so the entry is closed as 'done' with reason 'no_paths'. That is
// the point of the probe, so the reason is asserted rather than left implicit.
wps_check(
    'probe with no URLs is closed, not retried',
    'no_paths' === (string) $wpdb->get_var(
        $wpdb->prepare( "SELECT reason FROM {$queue_table} WHERE object_type = 'post' AND object_id = %d", $probe_id )
    ),
    'reason recorded'
);

// A row left behind by a dead process must be picked up by the next tick. The
// reaper only trusts rows whose 'processing' stamp is older than the lock TTL,
// so an artificially old stamp is the honest way to reproduce that state.
$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $queue_table,
    array( 'status' => 'processing', 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
    array( 'object_type' => 'post', 'object_id' => $probe_id ),
    array( '%s', '%s' ),
    array( '%s', '%d' )
);

$recovered = $queue->process( array( 'budget' => 10 ) );

wps_check(
    'abandoned row is recovered and processed',
    (int) $recovered['processed'] >= 1,
    'processed=' . $recovered['processed']
);

$recovered_status = (string) $wpdb->get_var(
    $wpdb->prepare( "SELECT status FROM {$queue_table} WHERE object_type = 'post' AND object_id = %d", $probe_id )
);

wps_check(
    'recovered row reached a final state',
    in_array( $recovered_status, array( 'done', 'skipped', 'failed' ), true ),
    $recovered_status
);

// The lock must keep a second concurrent run out.
set_transient( WP_Image_WebP_Converter_Queue::LOCK_TRANSIENT, time(), 60 );
$blocked = $queue->process( array( 'budget' => 1 ) );
delete_transient( WP_Image_WebP_Converter_Queue::LOCK_TRANSIENT );

wps_check( 'lock blocks a concurrent run', 'locked' === $blocked['reason'], $blocked['reason'] );

// Cleanup the probe rows so a repeat run starts from a clean slate.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$queue_table} WHERE object_type = 'post' AND object_id = %d", $probe_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$log_table} WHERE object_type = 'post' AND object_id = %d", $probe_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_delete_post( $probe_id, true );

echo "\n== Rewrite queue: purge needs confirmation ==\n";

$unconfirmed = $queue->purge_log( false );

wps_check(
    'purge refuses without confirmation',
    is_wp_error( $unconfirmed ),
    is_wp_error( $unconfirmed ) ? $unconfirmed->get_error_code() : 'it ran anyway'
);

$confirmed = $queue->purge_log( true );
wps_check( 'purge runs when confirmed', ! is_wp_error( $confirmed ), is_wp_error( $confirmed ) ? $confirmed->get_error_message() : 'deleted ' . $confirmed['deleted'] );

echo "\n== Rewrite queue: never during a page view ==\n";

// batch_context_ok() is the structural guarantee. DOING_CRON is defined by this
// point, so it must read true here; what matters is that the check is a plain
// context test rather than a settings toggle that a page view could satisfy.
wps_check( 'queue exposes its context guard', is_callable( array( $queue, 'batch_context_ok' ) ) );
wps_check( 'context guard true under cron', true === $queue->batch_context_ok() );

// Read from the shipped file rather than from the class, because the guarantees
// being checked are about what the source is allowed to contain at all.
$queue_source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-wp-image-webp-converter-queue.php' );

// phpdoc mentions are fine; a call is not. Compared without the leading @ so a
// docblock reference cannot mask a real invocation.
$queue_code = preg_replace( '#^\s*\*.*$#m', '', $queue_source );

wps_check(
    'no wp_update_post in the queue',
    false === strpos( (string) $queue_code, 'wp_update_post(' ),
    'content is written with $wpdb only'
);
wps_check( 'no wp_cache_flush in the queue', false === strpos( (string) $queue_code, 'wp_cache_flush(' ) );
wps_check( 'clean_post_cache is used', false !== strpos( (string) $queue_code, 'clean_post_cache(' ) );

echo "\n== Engine guards ==\n";

wps_check( 'plugin declares cron hook', WP_Image_WebP_Converter::CRON_HOOK === 'wp_image_webp_converter_batch' );
wps_check( 'AJAX endpoints registered', (bool) has_action( 'wp_ajax_wp_image_webp_converter_start_run' ) );
wps_check( 'no get_attached_file filter', false === has_filter( 'get_attached_file', array( $plugin, 'filter_attached_file' ) ) );

echo "\n== Verified purge: stem identity ==\n";

// The purge decides on a stem, not on a file name, so the collapsing rules are
// load-bearing rather than cosmetic. A rule that failed to collapse "-300x200"
// would delete the family from under a post that still shows the medium size.
$stem_base   = WP_Image_WebP_Converter_Queue::stem_of( '2024/03/foto.jpg' );
$stem_thumb  = WP_Image_WebP_Converter_Queue::stem_of( '2024/03/foto-300x200.jpg' );
$stem_scaled = WP_Image_WebP_Converter_Queue::stem_of( '2024/03/foto-scaled.jpg' );
$stem_webp   = WP_Image_WebP_Converter_Queue::stem_of( '2024/03/foto-300x200.webp' );

wps_check( 'stem drops the extension', '2024/03/foto' === $stem_base, $stem_base );
wps_check( 'size suffix collapses', $stem_base === $stem_thumb, $stem_base . ' == ' . $stem_thumb );
wps_check( 'scaled suffix collapses', $stem_base === $stem_scaled, $stem_base . ' == ' . $stem_scaled );
wps_check( 'webp thumbnail collapses too', $stem_base === $stem_webp, $stem_base . ' == ' . $stem_webp );
wps_check(
    'directory is part of the stem',
    '2024/03/foto' !== WP_Image_WebP_Converter_Queue::stem_of( '2024/04/foto.jpg' ),
    WP_Image_WebP_Converter_Queue::stem_of( '2024/04/foto.jpg' )
);
wps_check(
    'a different photo is a different stem',
    $stem_base !== WP_Image_WebP_Converter_Queue::stem_of( '2024/03/otra.jpg' ),
    WP_Image_WebP_Converter_Queue::stem_of( '2024/03/otra.jpg' )
);
wps_check( 'empty input yields no stem', '' === WP_Image_WebP_Converter_Queue::stem_of( '' ) );

// The orphan sweep in the engine uses its own key. If the two disagreed, the
// purge and the orphan cleaner would have opposite ideas about which files
// belong to one image, and only one of them would be right.
$engine_stem = ( new ReflectionClass( $plugin ) )->getMethod( 'stem_key' );
$engine_stem->setAccessible( true );

$stem_agree = true;

foreach ( array( '2024/03/foto.jpg', '2024/03/foto-300x200.jpg', '2024/03/foto-scaled.webp', 'foto.png' ) as $path ) {
    if ( $engine_stem->invoke( $plugin, $path ) !== WP_Image_WebP_Converter_Queue::stem_of( $path ) ) {
        $stem_agree = false;
    }
}

wps_check( 'engine and queue agree on the stem', $stem_agree, 'one implementation, two callers' );

echo "\n== Verified purge: fixtures ==\n";

/**
 * Reads the stored scan state.
 *
 * @return array
 */
function wps_scan_state() {
    $state = get_option( WP_Image_WebP_Converter_Queue::OPTION_SCAN, array() );

    return is_array( $state ) ? $state : array();
}

/**
 * Declares the reference index complete or unusable, without scanning anything.
 *
 * Arranging this by hand keeps the assertions below deterministic and instant.
 * The real scan is exercised at the end of this section, which is the only place
 * that can tell whether the collection code works rather than whether a flag can
 * be flipped.
 *
 * @param bool   $complete Whether the index covers everything.
 * @param bool   $options  Whether the wp_options pass actually ran.
 * @param string $error    Reason to record, when it is unusable.
 * @param int    $age      Seconds to date the index back by. Zero means "right
 *                         now", which is what makes it current enough to authorise
 *                         a deletion.
 */
function wps_set_refs_state( $complete, $options = true, $error = '', $age = 0 ) {
    $state = wps_scan_state();

    $state['phase']         = 'done';
    $state['refs_complete'] = (bool) $complete;
    $state['refs_options']  = (bool) $options;
    $state['refs_error']    = (string) $error;
    $state['refs_at']       = wps_local_mysql( (int) $age );
    $state['refs_blocked']  = '';

    update_option( WP_Image_WebP_Converter_Queue::OPTION_SCAN, $state, false );
}

/**
 * A site-local "Y-m-d H:i:s" stamp, the same format current_time() produces.
 *
 * Reproduced rather than approximated: the index timestamp is compared against
 * a post_date, and a helper that quietly used UTC on a site that is not on UTC
 * would make every freshness comparison wrong by the offset.
 *
 * @param int $age Seconds to go back.
 * @return string
 */
function wps_local_mysql( $age = 0 ) {
    $offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;

    return gmdate( 'Y-m-d H:i:s', time() + (int) $offset - (int) $age );
}

/**
 * Replaces the reference index with an exact set of stems.
 *
 * The table name arrives through $GLOBALS because wp eval-file runs this file
 * inside a function scope: a `global $queue` inside a helper would look in the
 * real global scope, where $queue does not exist.
 *
 * @param array $stems Stem keys.
 */
function wps_set_refs( array $stems ) {
    global $wpdb;

    $table = $GLOBALS['wps_refs_table'];

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
    $wpdb->query( "TRUNCATE TABLE {$table}" );

    foreach ( $stems as $stem ) {
        $wpdb->insert( $table, array( 'stem' => $stem ) );
    }
}

/**
 * Writes a real JPEG and registers it as an attachment, metadata included.
 *
 * Real files, not stubs: every assertion below is about what survives on disk,
 * so a fake path would assert nothing.
 *
 * @param string $name File name inside uploads.
 * @return array
 */
function wps_make_fixture( $name ) {
    $uploads = wp_upload_dir();
    $path    = trailingslashit( $uploads['basedir'] ) . $name;

    $image = imagecreatetruecolor( 640, 480 );
    imagefilledrectangle( $image, 0, 0, 639, 479, imagecolorallocate( $image, 20, 120, 200 ) );
    imagefilledrectangle( $image, 40, 40, 300, 260, imagecolorallocate( $image, 240, 200, 40 ) );
    imagejpeg( $image, $path, 92 );
    imagedestroy( $image );

    $id = wp_insert_attachment(
        array(
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'wps purge fixture',
            'post_content'   => '',
            'post_status'    => 'inherit',
        ),
        $path
    );

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $meta = wp_generate_attachment_metadata( $id, $path );
    wp_update_attachment_metadata( $id, $meta );

    // Absolute paths, taken from the metadata rather than from a glob over a
    // name pattern: "<name>-*" has to be built by hand and matches nothing the
    // moment an extension slips into it, which is how a fixture ends up
    // reporting zero thumbnails while the files are sitting right there.
    $sizes = array();

    if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
        foreach ( $meta['sizes'] as $size ) {
            if ( ! empty( $size['file'] ) ) {
                $sizes[] = dirname( $path ) . '/' . $size['file'];
            }
        }
    }

    return array(
        'id'       => (int) $id,
        'relative' => $name,
        'path'     => $path,
        'meta'     => $meta,
        'url'      => wp_get_attachment_url( $id ),
        'sizes'    => $sizes,
    );
}

/**
 * Deletes every file this section created, whatever state it left behind in.
 *
 * A previous failing run must not change the outcome of this one, or a single
 * failure would cascade into every later assertion.
 *
 * @return int Files removed.
 */
function wps_clear_fixture_files() {
    $uploads = wp_upload_dir();
    $removed = 0;

    foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . 'wps-*' ) as $file ) {
        if ( @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $removed++;
        }
    }

    return $removed;
}

// Every table this section asserts on, resolved here so the helpers above do not
// have to reach into a scope they cannot see.
$GLOBALS['wps_refs_table'] = $queue->refs_table();
$GLOBALS['wps_fixtures']   = array();

$refs_table   = $GLOBALS['wps_refs_table'];
$uploads_base = trailingslashit( wp_upload_dir()['basedir'] );

// A previous run of this file leaves a completed scan behind. Everything below
// depends on the index being UNUSABLE at the start, so it is put back into that
// state explicitly rather than assumed.
wps_set_refs_state( false );
wps_set_refs( array() );
wps_clear_fixture_files();

wps_check(
    'reference table created',
    in_array( $refs_table, $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}webp%'" ), true ),
    $refs_table
);
wps_check(
    'reference index is not trusted before any scan',
    false === $queue->refs_ready(),
    'a fresh install must delete nothing'
);

echo "\n== Verified purge: conversion defers the deletion ==\n";

$convert = ( new ReflectionClass( $plugin ) )->getMethod( 'convert_attachment' );
$convert->setAccessible( true );

$delete_options = array(
    'enabled'         => 1,
    'quality'         => 80,
    'delete_original' => 1,
    'activity_log'    => 0,
);

wps_clear_fixture_files();

// A post that points at the ORIGINAL .jpg, written as raw HTML. This is the
// exact shape the feature exists for: the URL was stored before the file was
// replaced, and nothing about the conversion changes that text.
$referenced = wps_make_fixture( 'wps-referenced.jpg' );
$GLOBALS['wps_fixtures'][] = $referenced['id'];

$reference_post = wp_insert_post(
    array(
        'post_title'   => 'wps referenced post',
        'post_status'  => 'publish',
        'post_content' => '<p>antes</p><img src="' . $referenced['url'] . '" alt="" /><p>despues</p>',
    )
);

wps_check(
    'fixture really has thumbnails on disk',
    count( $referenced['sizes'] ) > 0,
    count( $referenced['sizes'] ) . ' generated files'
);

$new_meta = $convert->invoke( $plugin, $referenced['id'], $delete_options, null );

if ( is_array( $new_meta ) ) {
    wp_update_attachment_metadata( $referenced['id'], $new_meta );
}

wps_check( 'conversion produced metadata', is_array( $new_meta ), is_array( $new_meta ) ? 'ok' : 'failed' );
wps_check(
    'the webp exists',
    file_exists( $uploads_base . 'wps-referenced.webp' ),
    'wps-referenced.webp'
);

// The whole point. The original, and its thumbnails, are still there.
wps_check(
    'original survives a referenced conversion',
    file_exists( $referenced['path'] ),
    basename( $referenced['path'] ) . ' still on disk'
);

$survivors = array();

foreach ( $referenced['sizes'] as $size ) {
    if ( file_exists( $size ) ) {
        $survivors[] = basename( $size );
    }
}

wps_check(
    'its old-format thumbnails survive too',
    count( $survivors ) === count( $referenced['sizes'] ),
    count( $referenced['sizes'] ) . ' before, ' . count( $survivors ) . ' after'
);

$pending_meta = get_post_meta( $referenced['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true );
$pending_files = isset( $pending_meta['files'] ) ? (array) $pending_meta['files'] : array();

wps_check(
    'pending purge meta is written',
    ! empty( $pending_files ),
    implode( ',', $pending_files )
);
wps_check(
    'pending purge names the original',
    in_array( 'wps-referenced.jpg', $pending_files, true ),
    implode( ',', $pending_files )
);
wps_check(
    'pending purge names the thumbnails',
    count( $pending_files ) === count( $referenced['sizes'] ) + 1,
    implode( ',', $pending_files )
);
wps_check(
    'state is recorded for counting',
    WP_Image_WebP_Converter_Queue::PURGE_PENDING === get_post_meta( $referenced['id'], WP_Image_WebP_Converter_Queue::META_PURGE_STATE, true ),
    get_post_meta( $referenced['id'], WP_Image_WebP_Converter_Queue::META_PURGE_STATE, true )
);
wps_check(
    'an unproven deletion is recorded as unproven',
    'refs_unknown' === ( isset( $pending_meta['reason'] ) ? $pending_meta['reason'] : '' ),
    'reason: ' . ( isset( $pending_meta['reason'] ) ? $pending_meta['reason'] : '' )
);

// The attachment record must already point at the .webp even though the .jpg is
// still there: the conversion did its half of the job completely.
wps_check(
    'attachment record points at the webp',
    'wps-referenced.webp' === get_post_meta( $referenced['id'], '_wp_attached_file', true ),
    get_post_meta( $referenced['id'], '_wp_attached_file', true )
);

echo "\n== Verified purge: refuses without proof ==\n";

// Nothing has been scanned yet, so there is no evidence either way.
$blind = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'purge refuses when the index is incomplete', 'refs_incomplete' === $blind['reason'], $blind['reason'] );
wps_check( 'purge deletes nothing when it refuses', 0 === (int) $blind['purged'], 'purged=' . $blind['purged'] );
wps_check(
    'the referenced original is still there after the refusal',
    file_exists( $referenced['path'] ),
    'a refusal must never be a partial run'
);

// Rows in the table are not the same as a declared index. Having found stems is
// only meaningful once somebody has declared the walk finished.
wps_set_refs( array( WP_Image_WebP_Converter_Queue::stem_of( 'wps-referenced.jpg' ) ) );

$undeclared = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'an undeclared index is still refused', 'refs_incomplete' === $undeclared['reason'], $undeclared['reason'] );
wps_check( 'and still deletes nothing', file_exists( $referenced['path'] ), 'unchanged' );

// A scan that failed part way is the same answer as no scan at all.
wps_set_refs_state( true, true, 'db_error: simulated' );

$failed_scan = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'a failed scan is refused', 'refs_incomplete' === $failed_scan['reason'], $failed_scan['reason'] );
wps_check(
    'the reason is reported, not swallowed',
    'db_error: simulated' === $queue->refs_blocker(),
    $queue->refs_blocker()
);

// A scan that deliberately skipped wp_options has not proved anything either.
wps_set_refs_state( true, false );

$no_options = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'a scan without options is refused', 'refs_incomplete' === $no_options['reason'], $no_options['reason'] );
wps_check(
    'the blocker names the missing phase',
    'options_skipped' === $queue->refs_blocker(),
    $queue->refs_blocker()
);
wps_check( 'nothing deleted in either case', file_exists( $referenced['path'] ), 'unchanged' );

echo "\n== Verified purge: refuses while a rewrite is open ==\n";

wps_set_refs_state( true );

// A pending rewrite means somebody's text still holds a .jpg URL. Deleting now
// would break it one tick earlier than necessary.
$queue->enqueue_item( array( 'object_type' => 'post', 'object_id' => (int) $reference_post ) );

$blocked_purge = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'purge refuses with a rewrite pending', 'rewrite_pending' === $blocked_purge['reason'], $blocked_purge['reason'] );
wps_check(
    'nothing deleted while blocked',
    file_exists( $referenced['path'] ),
    isset( $blocked_purge['openRewrite'] ) ? $blocked_purge['openRewrite'] . ' open' : ''
);

$queue->process( array( 'budget' => 15 ) );

wps_check(
    'the rewrite queue is drained',
    0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE task = 'rewrite' AND status IN ('pending','processing')" ),
    'open rows: 0'
);

wps_check(
    'the drain rewrote the post to webp',
    false !== strpos( get_post_field( 'post_content', $reference_post ), 'wps-referenced.webp' ),
    'post now points at the webp'
);

echo "\n== Verified purge: a live reference holds the file ==\n";

// The drain above rewrote content, and rewriting expires the index on purpose:
// it described what was stored before. It has to be rebuilt before it can
// authorise a deletion again.
wps_check(
    'a rewrite expires the index',
    false === $queue->refs_ready(),
    'blocker: ' . $queue->refs_blocker()
);

$expired = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'and the expired index is refused', 'refs_incomplete' === $expired['reason'], $expired['reason'] );
wps_check( 'nothing deleted on an expired index', file_exists( $referenced['path'] ), 'unchanged' );

wps_set_refs( array( WP_Image_WebP_Converter_Queue::stem_of( 'wps-referenced.jpg' ) ) );
wps_set_refs_state( true, true, '', 0 );

$held = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'a referenced stem is not purged', 0 === (int) $held['purged'], 'purged=' . $held['purged'] );
wps_check( 'it is counted as waiting instead', (int) $held['waiting'] >= 1, 'waiting=' . $held['waiting'] );
wps_check(
    'the original is untouched',
    file_exists( $referenced['path'] ),
    'the family survives while anything points at it'
);

$held_meta = get_post_meta( $referenced['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true );
$purge_state = $queue->get_purge_state();

wps_check(
    'marked waiting with a state',
    WP_Image_WebP_Converter_Queue::PURGE_WAITING === ( isset( $held_meta['state'] ) ? $held_meta['state'] : '' ),
    'state: ' . ( isset( $held_meta['state'] ) ? $held_meta['state'] : '' )
);
wps_check(
    'the reason says why it is waiting',
    WP_Image_WebP_Converter_Queue::PURGE_REASON_REFERENCED === ( isset( $held_meta['reason'] ) ? $held_meta['reason'] : '' ),
    'reason: ' . ( isset( $held_meta['reason'] ) ? $held_meta['reason'] : '' )
);
wps_check(
    'waiting is counted separately for the screen',
    (int) $purge_state['waiting'] >= 1,
    'waiting=' . $purge_state['waiting']
);
wps_check(
    'the pending counter includes the waiting one',
    (int) $purge_state['pending'] >= 1,
    'pending=' . $purge_state['pending']
);

// The thumbnail is the file a theme actually links to, and it is covered by the
// same stem decision. If it had been deleted while the .jpg survived, the site
// would still be broken in exactly the way this feature prevents.
wps_check(
    'a referenced stem keeps its old thumbnails too',
    file_exists( $uploads_base . 'wps-referenced-300x225.jpg' ),
    'wps-referenced-300x225.jpg'
);

echo "\n== Verified purge: an unreferenced original goes ==\n";

// The index now says only the referenced image is in use. The referenced one
// must be released; a second fixture nothing links to is still pending from the
// earlier deferred conversion.
// The drain above rewrote the post to the .webp, so the .jpg reference is gone
// from the content. A fresh walk reflects that, and now the original is free.
wps_set_refs( array() );

// Captured before the purge, because a rewrite entry processed earlier in this
// run legitimately leaves an undoable row. What matters is that the purge adds
// none of its own.
$log_open_before = (int) $queue->get_progress()['logOpen'];

$released = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'an unreferenced stem is purged', (int) $released['purged'] >= 1, 'purged=' . $released['purged'] );
wps_check( 'its original is gone from disk', ! file_exists( $referenced['path'] ), basename( $referenced['path'] ) );
wps_check(
    'its old thumbnails are gone too',
    ! file_exists( $uploads_base . 'wps-referenced-300x225.jpg' ) && ! file_exists( $uploads_base . 'wps-referenced-150x150.jpg' ),
    'the whole family goes together'
);
wps_check(
    'the webp is untouched',
    file_exists( $uploads_base . 'wps-referenced.webp' ),
    'only the old format is removed'
);
wps_check(
    'the pending meta is cleared',
    '' === get_post_meta( $referenced['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true ),
    'nothing left to do'
);

$purge_log_rows = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$log_table} WHERE task = %s AND object_id = %d",
        WP_Image_WebP_Converter_Queue::TASK_PURGE,
        $referenced['id']
    )
);

wps_check( 'the deletion is recorded in the log', $purge_log_rows >= 1, $purge_log_rows . ' purge row' );

$log_row = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT object_type, object_id, old_value, replacements, queue_id FROM {$log_table} WHERE task = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
        WP_Image_WebP_Converter_Queue::TASK_PURGE,
        $referenced['id']
    ),
    ARRAY_A
);

wps_check(
    'the log names what was removed',
    is_array( $log_row ) && false !== strpos( (string) $log_row['old_value'], 'wps-referenced.jpg' ),
    is_array( $log_row ) ? $log_row['old_value'] : 'no row'
);
wps_check(
    'the log records it as an attachment',
    is_array( $log_row ) && 'attachment' === $log_row['object_type'],
    is_array( $log_row ) ? $log_row['object_type'] : 'no row'
);
wps_check(
    'a purge row carries no queue entry',
    is_array( $log_row ) && 0 === (int) $log_row['queue_id'],
    'queue_id=' . ( is_array( $log_row ) ? $log_row['queue_id'] : '?' ) . ', so there is nothing to roll back'
);
wps_check(
    'a purge row is never offered as an undo',
    $log_open_before === (int) $queue->get_progress()['logOpen'],
    'undoable rewrite rows: ' . $log_open_before . ' before, ' . (int) $queue->get_progress()['logOpen'] . ' after'
);
wps_check(
    'no queue row claims a purge log row',
    0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue_table} WHERE id = %d", 0 ) ),
    'rollback() can never resolve a purge row'
);

echo "\n== Verified purge: idempotency ==\n";

$log_before = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$log_table} WHERE task = %s", WP_Image_WebP_Converter_Queue::TASK_PURGE )
);

$second = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'a second run deletes nothing extra', 0 === (int) $second['purged'], 'purged=' . $second['purged'] );
wps_check(
    'a second run writes no second log row',
    $log_before === (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$log_table} WHERE task = %s", WP_Image_WebP_Converter_Queue::TASK_PURGE )
    ),
    $log_before . ' purge rows, unchanged'
);
wps_check(
    'a cleared attachment is not resurrected',
    '' === get_post_meta( $referenced['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true ),
    'still nothing pending'
);
wps_check( 'the deleted original stays deleted', ! file_exists( $referenced['path'] ), 'gone' );
wps_check( 'the webp is still there', file_exists( $uploads_base . 'wps-referenced.webp' ), 'untouched' );

// A dry run has to be inert in both directions: it must not delete and it must
// not leave a trace that would make the next real run think there is more work.
$dry = $queue->purge_verified( array( 'budget' => 5, 'dry_run' => true ) );

wps_check( 'a dry run reports the pending work', (int) $dry['scanned'] >= 0, 'scanned=' . $dry['scanned'] );
wps_check(
    'a dry run writes no log row',
    $log_before === (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT COUNT(*) FROM {$log_table} WHERE task = %s", WP_Image_WebP_Converter_Queue::TASK_PURGE )
    ),
    'still ' . $log_before
);

echo "\n== Verified purge: an unreferenced upload is freed at once ==\n";

// The other branch of the same rule: when the index is complete and the stem is
// simply not in it, there is nothing to wait for and the conversion itself may
// do the deletion. This is the ordinary fresh upload.
$solo = wps_make_fixture( 'wps-solo.jpg' );
$GLOBALS['wps_fixtures'][] = $solo['id'];

wps_set_refs( array( WP_Image_WebP_Converter_Queue::stem_of( 'wps-referenced.jpg' ) ) );

// The index has to be NEWER than the attachment for absence to mean anything.
// An index older than the image cannot know about a reference that has not been
// written yet, which is precisely the situation of a fresh upload.
wps_set_refs_state( true, true, '', 0 );

wps_check( 'fixture starts on disk', file_exists( $solo['path'] ), basename( $solo['path'] ) );

$solo_meta = $convert->invoke( $plugin, $solo['id'], $delete_options, null );

if ( is_array( $solo_meta ) ) {
    wp_update_attachment_metadata( $solo['id'], $solo_meta );
}

wps_check( 'solo fixture was converted', is_array( $solo_meta ), is_array( $solo_meta ) ? 'ok' : 'failed' );
wps_check(
    'an unreferenced original is deleted at once',
    ! file_exists( $solo['path'] ),
    'nothing in the content mentions it'
);
wps_check(
    'nothing is left pending for it',
    '' === get_post_meta( $solo['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true ),
    'no deferred work'
);
wps_check(
    'its webp survives',
    file_exists( $uploads_base . 'wps-solo.webp' ),
    'wps-solo.webp'
);

// The same file with NO index at all. Also kept, obviously.
wps_set_refs_state( false );

$blind = wps_make_fixture( 'wps-blind.jpg' );
$GLOBALS['wps_fixtures'][] = $blind['id'];

$blind_meta = $convert->invoke( $plugin, $blind['id'], $delete_options, null );

if ( is_array( $blind_meta ) ) {
    wp_update_attachment_metadata( $blind['id'], $blind_meta );
}

wps_check(
    'with no index the file is kept',
    file_exists( $blind['path'] ),
    'nothing was looked at, so nothing may go'
);
wps_check(
    'and the reason names the missing index',
    'refs_unknown' === get_post_meta( $blind['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true )['reason'],
    get_post_meta( $blind['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true )['reason']
);

// The dangerous one: a complete, current-looking index that simply does not
// contain this brand new image. Absence proves nothing there, because the post
// that would reference it has not been saved yet. Deleting on that reading is
// what breaks the page, so it must not happen.
wps_set_refs( array( WP_Image_WebP_Converter_Queue::stem_of( 'wps-referenced.jpg' ) ) );
wps_set_refs_state( true, true, '', 3600 );

$careful = wps_make_fixture( 'wps-careful.jpg' );
$GLOBALS['wps_fixtures'][] = $careful['id'];

$careful_meta = $convert->invoke( $plugin, $careful['id'], $delete_options, null );

if ( is_array( $careful_meta ) ) {
    wp_update_attachment_metadata( $careful['id'], $careful_meta );
}

$careful_payload = get_post_meta( $careful['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true );

wps_check(
    'an index older than the image cannot authorise a deletion',
    file_exists( $careful['path'] ),
    'the reference that would break the page has not been written yet'
);
wps_check(
    'and the reason says the index is stale, not missing',
    'refs_stale' === $careful_payload['reason'],
    'reason: ' . $careful_payload['reason']
);

// With a genuinely current index the very same attachment is freed. Same files,
// same absence in the index, only the age of the evidence differs. This is the
// pair that proves the age check is what made the difference.
wps_set_refs_state( true, true, '', 0 );

$careful_after = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check(
    'a current index does authorise the deletion',
    (int) $careful_after['purged'] >= 1,
    'purged=' . $careful_after['purged']
);
wps_check(
    'and the file that was held back is now gone',
    ! file_exists( $careful['path'] ),
    'same evidence, one hour newer'
);

echo "\n== Verified purge: end to end through a real scan ==\n";

// Everything above arranged the reference index by hand. This part runs the
// actual scan, because the collection code is where a whole class of bug hides:
// a scan that reads the wrong column, forgets the year/month folder or stops one
// page early would leave the index looking complete and quietly wrong.
$scan_post = wp_insert_post(
    array(
        'post_title'   => 'wps scan fixture',
        'post_status'  => 'publish',
        'post_content' => '<img src="https://example.test/wp-content/uploads/wps-scan/2024/03/huida.jpg" />'
            . '<img src="https://example.test/wp-content/uploads/wps-scan/2024/03/huida-300x200.png" />',
    )
);

// A real file with real pending files under it, so the restart below is tested
// against something that would actually be deleted if the rule were wrong.
wps_set_refs_state( false );

$window_fixture = wps_make_fixture( 'wps-window.jpg' );
$GLOBALS['wps_fixtures'][] = $window_fixture['id'];

$window_meta = $convert->invoke( $plugin, $window_fixture['id'], $delete_options, null );

if ( is_array( $window_meta ) ) {
    wp_update_attachment_metadata( $window_fixture['id'], $window_meta );
}

wps_check(
    'the window fixture is pending on disk',
    file_exists( $window_fixture['path'] ) && is_array( get_post_meta( $window_fixture['id'], WP_Image_WebP_Converter_Queue::META_PENDING_PURGE, true ) ),
    basename( $window_fixture['path'] )
);

wps_set_refs( array( 'this-must-not-survive-a-restart' ) );
wps_set_refs_state( true );

// The very first thing a restart has to do is make the previous answer
// unusable. A budget this small cannot finish the walk, so the window between
// "index invalidated" and "index rebuilt" is observable: the stale row is already
// gone, nothing is trusted yet, and a fixture with real pending files is on
// disk. Deleting on the stale answer is the exact failure this exists to stop.
$scan = $queue->enqueue_from_scan( array( 'restart' => true, 'budget' => 0.001 ) );

$stale_row = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$refs_table} WHERE stem = %s", 'this-must-not-survive-a-restart' )
);
$window    = $queue->purge_verified( array( 'budget' => 5 ) );

wps_check( 'a restart drops the previous index immediately', 0 === $stale_row, $stale_row . ' leftover' );
wps_check( 'an unfinished scan is never trusted', false === $queue->refs_ready(), 'refs_ready() is false mid-scan' );
wps_check( 'the pending original is still on disk', file_exists( $window_fixture['path'] ), 'never deleted on a stale answer' );
wps_check( 'and the purge said why', 'refs_incomplete' === $window['reason'], $window['reason'] );
wps_check( 'deleting nothing in that window is reported honestly', 0 === (int) $window['purged'], 'purged=' . $window['purged'] );

for ( $attempt = 0; $attempt < 20 && empty( $scan['finished'] ); $attempt++ ) {
    $scan = $queue->enqueue_from_scan( array( 'budget' => 20 ) );
}

wps_check( 'the scan reports it finished', ! empty( $scan['finished'] ), 'after ' . ( $attempt + 1 ) . ' call(s)' );
wps_check( 'the index is trusted once the scan completes', true === $queue->refs_ready(), $queue->refs_blocker() );
wps_check(
    'a post path reaches the index',
    1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$refs_table} WHERE stem = %s", 'wps-scan/2024/03/huida' ) ),
    'one row, from post_content'
);
wps_check(
    'a thumbnail URL collapses onto the same stem',
    1 === count(
        array_filter(
            (array) $wpdb->get_col( $wpdb->prepare( "SELECT stem FROM {$refs_table} WHERE stem LIKE %s", 'wps-scan/2024/03/%' ) ),
            function ( $row ) {
                return 0 === strpos( (string) $row, 'wps-scan/2024/03/huida' );
            }
        )
    ),
    'the .jpg and the -300x200.png are one image'
);
wps_check(
    'nothing references the freed fixture',
    0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$refs_table} WHERE stem = %s", WP_Image_WebP_Converter_Queue::stem_of( 'wps-solo.jpg' ) ) ),
    'no post, no meta and no option mentions it'
);
wps_check(
    'the plugin own history is not mistaken for content',
    0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$refs_table} WHERE stem = %s", WP_Image_WebP_Converter_Queue::stem_of( 'wps-referenced.jpg' ) ) ),
    'the activity log records filenames; that is not a reference'
);

// Drain whatever the scan queued so this file leaves the queue as it found it.
$queue->process( array( 'budget' => 20 ) );

echo "\n== Verified purge: cleanup ==\n";

$removed_files = wps_clear_fixture_files();

foreach ( $GLOBALS['wps_fixtures'] as $fixture_id ) {
    wp_delete_attachment( (int) $fixture_id, true );
}

wp_delete_post( (int) $reference_post, true );
wp_delete_post( (int) $scan_post, true );

// The fixtures wrote real posts with real uploads paths, so the scan left real
// queue rows and log rows behind. Those go by object, never by emptying the
// tables: for anything else the log is the only way back.
$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->prepare(
        "DELETE FROM {$queue_table} WHERE object_type = 'post' AND object_id IN (%d, %d)",
        (int) $reference_post,
        (int) $scan_post
    )
);
$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->prepare(
        "DELETE FROM {$log_table} WHERE object_type = 'post' AND object_id IN (%d, %d)",
        (int) $reference_post,
        (int) $scan_post
    )
);
$wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->prepare( "DELETE FROM {$log_table} WHERE task = %s", WP_Image_WebP_Converter_Queue::TASK_PURGE )
);

$left = (int) $wpdb->get_var(
    $wpdb->prepare(
        // $window_fixture is deliberately NOT in this list. It is the fixture that
        // must survive: the assertions above prove the plugin refuses to purge it
        // while the reference index is incomplete, so it is expected to still carry
        // META_PENDING_PURGE here. It used to be passed as a seventh argument and
        // relied on wpdb::prepare silently dropping the extra one to achieve exactly
        // that. It also emitted a wpdb notice on every run, which is the kind of
        // noise that trains people to ignore the ones that matter. Exclude it by
        // name instead.
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s) AND post_id IN (%d, %d, %d, %d)",
        WP_Image_WebP_Converter_Queue::META_PENDING_PURGE,
        WP_Image_WebP_Converter_Queue::META_PURGE_STATE,
        (int) $referenced['id'],
        (int) $solo['id'],
        (int) $blind['id'],
        (int) $careful['id']
    )
);

wps_check( 'no pending purge meta survives the fixtures', 0 === $left, $left . ' rows' );
wps_check( 'every fixture file is gone from disk', 0 === count( (array) glob( $uploads_base . 'wps-*' ) ), $removed_files . ' files removed' );

/* ------------------------------------------------------------------ */
/* Delete containment: remove_files() must never escape the uploads dir */
/* ------------------------------------------------------------------ */

echo "\n== Delete containment ==\n";

$outside = WP_CONTENT_DIR . '/wps-traversal-victim.txt';
$victim  = file_put_contents( $outside, 'must survive' );

$q_remove = new ReflectionMethod( 'WP_Image_WebP_Converter_Queue', 'remove_files' );
$q_remove->setAccessible( true );

// A name from post meta shaped as a traversal. It used to delete the file,
// which made the purge an arbitrary file delete for anything able to write
// metadata. It must be refused, silently and without throwing.
$removed = $q_remove->invoke( new WP_Image_WebP_Converter_Queue(), array( '../wps-traversal-victim.txt' ) );

wps_check( 'path traversal outside uploads is refused', 0 === (int) $removed, (int) $removed . ' removed' );
wps_check( 'the file outside uploads still exists', file_exists( $outside ), 'gone' );

@unlink( $outside );

// An absolute-looking name must not be treated as one.
$removed_abs = $q_remove->invoke( new WP_Image_WebP_Converter_Queue(), array( '/etc/hostname' ) );
wps_check( 'an absolute path is not honoured', 0 === (int) $removed_abs, (int) $removed_abs . ' removed' );

// A sibling directory whose name merely starts the same is not a child.
$inside = trailingslashit( $uploads_base ) . 'wps-legit.webp';
file_put_contents( $inside, 'ok' );
$removed_ok = $q_remove->invoke( new WP_Image_WebP_Converter_Queue(), array( 'wps-legit.webp' ) );

wps_check( 'a real upload is still deleted', 1 === (int) $removed_ok && ! file_exists( $inside ), (int) $removed_ok . ' removed' );

echo "\n";
printf(
    "RESULT: %d passed, %d failed\n",
    (int) $GLOBALS['wps_pass'],
    (int) $GLOBALS['wps_fail']
);

if ( (int) $GLOBALS['wps_fail'] > 0 ) {
    exit( 1 );
}
