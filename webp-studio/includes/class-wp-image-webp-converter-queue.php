<?php
/**
 * Persistent rewrite queue for image URLs already stored inside content.
 *
 * Converting an attachment replaces the file on disk and points the attachment
 * record at the .webp. Anything that referenced the old .jpg URL from inside a
 * post body, a custom field or an option is now a 404. This class rebuilds those
 * references, one entry at a time, with a stored copy of every value it changes.
 *
 * Design rules that are not negotiable, and why:
 *
 * 1. TIME BUDGET, NOT ROW COUNT. process() measures elapsed time and stops when
 *    the budget is spent, never when a row counter runs out. A slow shared host
 *    doing 3 queries/second would otherwise blow past max_execution_time and be
 *    killed mid-write. Budgets are short by design: 20s for WP-Cron (which runs
 *    under the same PHP limits as a page view) and 2s for AJAX, which shares the
 *    request with the browser waiting on it.
 *
 * 2. NEVER DURING A PAGE VIEW. batch_context_ok() accepts DOING_CRON and
 *    wp_doing_ajax() only. A visitor GET or an admin screen load can never
 *    trigger scan or rewrite work, no matter what state the queue is in.
 *
 * 3. DIRECT SQL, NOT wp_update_post(). wp_update_post() fires save_post, and on a
 *    site with an SEO index table plus an analytics plugin that is tens of
 *    thousands of writes per run, all of them from inside a cron tick.
 *    $wpdb->update() fires no hook at all. The price is that nothing invalidates
 *    the caches, so invalidation is done by hand and narrowly: clean_post_cache()
 *    for the one entry that changed. Never wp_cache_flush(), which would empty
 *    the object cache for the whole site in the middle of a batch.
 *
 * 4. LOG BEFORE WRITE. The previous value is stored in the log table before the
 *    new value is written. If the log insert fails, the content is not touched:
 *    an unlogged change is unrecoverable, and "reversible" is the whole point.
 *
 * 5. IDEMPOTENT ENQUEUE. A UNIQUE key over (task, object_type, object_id,
 *    secondary) makes INSERT IGNORE a no-op for work already queued.
 *
 * 6. RESUMABLE. Every finished entry is stamped, and the next tick picks up the
 *    lowest unfinished id. A process that dies mid-tick loses at most the rows
 *    of the page it was holding, and those are still pending.
 *
 * 7. LOCKED. A transient with a TTL, the same shape the converter batch uses.
 *
 * 8. NEVER DELETE A FILE THAT SOMETHING STILL POINTS AT. Converting an
 *    attachment replaces the file on disk, and the stored text that referenced
 *    the old .jpg is not rewritten in the same instant. Deleting on conversion
 *    turns that window into a broken image, so deletion is deferred: the old
 *    files are registered as a pending purge and only removed once the scan
 *    has proven no stored value mentions them. If the proof is unavailable,
 *    nothing is deleted. Leaving 200.000 files behind is a nuisance; deleting
 *    one that a post still uses is an outage.
 *
 * @package webp-studio
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direct access guard.
}

/**
 * Scan, rewrite, roll back and report on stored image references.
 */
class WP_Image_WebP_Converter_Queue {

    /**
     * Table suffixes. Exposed so uninstall and diagnostics can name the tables
     * without duplicating the string.
     */
    const QUEUE_TABLE_SUFFIX = 'webp_queue';
    const LOG_TABLE_SUFFIX   = 'webp_log';

    /**
     * Table holding one row per image stem still mentioned by stored content.
     *
     * Third table, alongside the queue and the log, because this is a different
     * kind of fact: the queue says WHAT to change, the log says what WAS changed,
     * and this says what a stored value still points at. That last question is
     * the one that decides whether deleting an original is safe, and answering
     * it with a LIKE per candidate is the thing rule 8 exists to avoid.
     */
    const REFS_TABLE_SUFFIX = 'webp_refs';

    /**
     * Bumped whenever install_tables() changes. Drives the lazy upgrade check,
     * so an existing install gets its tables without touching the activation hook.
     */
    const SCHEMA_VERSION = '2';

    const CRON_HOOK      = 'wp_image_webp_converter_queue_batch';
    const LOCK_TRANSIENT = 'wp_image_webp_converter_queue_lock';
    const LOCK_TTL       = 300;

    /**
     * Wall-clock budgets, in seconds.
     *
     * See rule 1 at the top of this file. These are upper bounds on how long a
     * single tick may run, not targets.
     */
    const CRON_BUDGET = 20.0;
    const AJAX_BUDGET = 2.0;

    const OPTION_SCHEMA  = 'wp_image_webp_converter_queue_schema';
    const OPTION_CURSOR  = 'wp_image_webp_converter_queue_cursor';
    const OPTION_SCAN    = 'wp_image_webp_converter_queue_scan';
    const OPTION_WORKING = 'wp_image_webp_converter_queue_working';
    const OPTION_ENQUEUED = 'wp_image_webp_converter_queue_enqueued';
    const OPTION_UPDATED = 'wp_image_webp_converter_queue_updated_at';
    const OPTION_REWRITTEN = 'wp_image_webp_converter_queue_replacements';

    const TASK_REWRITE = 'rewrite';

    /**
     * Task recorded in the log for a completed verified deletion.
     *
     * A purge is not reversible, so it gets no queue row and can never be handed
     * to rollback(): what the log keeps is the audit trail of what went away,
     * written after the fact, not the means of undoing it.
     */
    const TASK_PURGE = 'purge';

    /**
     * Attachment meta holding the old-format files still waiting to be purged.
     *
     * Written by the converter the moment it replaces a file, read by
     * purge_verified(). Value is an array: the files, the verdict that is
     * waiting on them and when it was last touched.
     */
    const META_PENDING_PURGE = '_wps_pending_purge';

    /**
     * Denormalised state of the above, kept as its own row purely so the screen
     * can COUNT it.
     *
     * meta_value has no index in wp_postmeta, so counting a serialized payload
     * would mean reading every row of this meta key and unserializing it. One
     * extra indexed row per pending attachment buys a COUNT(*) that answers in
     * milliseconds. Written in exactly one place, next to META_PENDING_PURGE.
     */
    const META_PURGE_STATE = '_wps_purge_state';

    /** Pending-purge verdicts. */
    const PURGE_PENDING = 'pending';
    const PURGE_WAITING = 'waiting';

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE       = 'done';
    const STATUS_FAILED     = 'failed';
    const STATUS_SKIPPED    = 'skipped';

    /** Terminal outcome of a row whose content was changed. */
    const REASON_REWRITTEN = 'rewritten';

    /** Why a pending purge is still holding its files. */
    const PURGE_REASON_REFERENCED = 'still_referenced';

    /** How many times a row may fail for a transient reason before giving up. */
    const MAX_ATTEMPTS = 3;

    /** Queue rows fetched per SELECT. Small, so a tick checks its deadline often. */
    const FETCH_PAGE = 25;

    /** wp_posts rows read per scan query. */
    const SCAN_PAGE = 200;

    /**
     * wp_postmeta rows read per reference-collection query.
     *
     * Postmeta has no index on meta_value, so this is a range scan over the whole
     * table. It is walked once, resumably, and only the stem is kept from each
     * row: the value itself is never stored, never copied into an option and
     * never fed to a rewrite.
     */
    const REFS_SCAN_PAGE = 200;

    /** Attachments examined per purge_verified() tick. */
    const PURGE_PAGE = 50;

    /**
     * Placeholders per attachment lookup query.
     *
     * wp_postmeta has no index on meta_value (core dropped it years ago), so the
     * lookup is a range scan over the ~240k rows that carry _wp_attached_file.
     * That cost is per QUERY, not per value, so every path found in one entry is
     * resolved in a single statement instead of one statement per path.
     */
    const LOOKUP_CHUNK = 150;

    /**
     * Upper bound on the per-request lookup memo.
     *
     * A full stem map for a large library is tens of megabytes and is exactly
     * what must not happen. This is not that: it is a short-lived cache of the
     * verdicts this one tick has already obtained, capped hard so it cannot grow,
     * and it dies with the request. Sites reuse the same header and logo images
     * across thousands of posts, so it removes most of the duplicate lookups
     * without pretending to be an index.
     */
    const MEMO_LIMIT = 500;

    /** Rows removed per purge statement. */
    const PURGE_BATCH = 500;

    /**
     * How many layers of serialization are unwrapped before giving up.
     *
     * One is the normal case. Two happens whenever a pre-serialized value was
     * handed to update_option(). Anything deeper is pathological, and bailing
     * out leaves the value untouched rather than risking a rewrite whose length
     * bookkeeping cannot be trusted.
     */
    const MAX_SERIALIZE_DEPTH = 3;

    /**
     * Post types that never carry renderable content worth rewriting.
     *
     * Revisions are excluded on purpose: they are not displayed, and on a site
     * with hundreds of thousands of them they are the single biggest slice of
     * the scan. They can be included explicitly through $args['include_revisions'].
     */
    private static $skipped_post_types = array(
        'revision',
        'attachment',
        'nav_menu_item',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_global_styles',
        'wp_font_family',
        'wp_font_face',
    );

    /**
     * Option name prefixes this plugin owns and must never scan.
     *
     * This one is not housekeeping, it is correctness. The activity history
     * records the name of the file that was converted, so its own option value
     * contains "uploads/2024/03/foto.jpg" for every image the plugin has ever
     * touched. Scan it as content and every converted image looks permanently
     * referenced, which means purge_verified() could never delete anything at
     * all. The plugin's bookkeeping is not content: nothing renders it, and a
     * browser never fetches it.
     *
     * The same exclusion keeps the rewrite queue from editing its own history,
     * which would rewrite the record of what happened into a fiction.
     */
    const OWN_OPTION_PREFIX = 'wp_image_webp_converter';

    /**
     * Lookup memo for the current request: matched path => verdict.
     *
     * @var array
     */
    private $memo = array();

    /**
     * Cached uploads directory basename, used as the path token in content.
     *
     * @var string|null
     */
    private $uploads_token = null;

    /**
     * Cached uploads base directory.
     *
     * @var string|null
     */
    private $basedir = null;

    public function __construct() {
        // An install that predates these tables never runs the activation hook
        // again, so the schema is reconciled lazily instead. The check is a
        // single cached option read on a page view and no query at all.
        add_action( 'admin_init', array( $this, 'maybe_install' ), 1 );
        add_action( 'init', array( $this, 'maybe_reschedule_cron' ), 6 );

        add_action( self::CRON_HOOK, array( $this, 'run_cron_tick' ) );
    }

    /* ------------------------------------------------------------------ */
    /* Tables                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Fully qualified queue table name.
     *
     * @return string
     */
    public function queue_table() {
        global $wpdb;

        return $wpdb->prefix . self::QUEUE_TABLE_SUFFIX;
    }

    /**
     * Fully qualified log table name.
     *
     * @return string
     */
    public function log_table() {
        global $wpdb;

        return $wpdb->prefix . self::LOG_TABLE_SUFFIX;
    }

    /**
     * Fully qualified reference table name.
     *
     * @return string
     */
    public function refs_table() {
        global $wpdb;

        return $wpdb->prefix . self::REFS_TABLE_SUFFIX;
    }

    /**
     * Creates or reconciles all three tables.
     *
     * Runs from the activation hook and from the lazy upgrade check.
     *
     * @return bool True when every table exists afterwards.
     */
    public function install_tables() {
        global $wpdb;

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $queue    = $this->queue_table();
        $log      = $this->log_table();
        $refs     = $this->refs_table();
        $collate  = $wpdb->get_charset_collate();

        // dbDelta parses the statement line by line and only recognises a column
        // or a key when it starts its own line, so this formatting is required and
        // not decorative.
        $queries = array();

        $queries[] = "CREATE TABLE {$queue} (
  id bigint(20) unsigned NOT NULL auto_increment,
  task varchar(16) NOT NULL,
  object_type varchar(16) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  secondary varchar(191) NOT NULL default '',
  status varchar(16) NOT NULL default 'pending',
  reason varchar(64) NOT NULL default '',
  attempts tinyint(3) unsigned NOT NULL default 0,
  replacements int(10) unsigned NOT NULL default 0,
  last_error text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_item (task,object_type,object_id,secondary),
  KEY idx_status (status,id)
) {$collate};";

        $queries[] = "CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL auto_increment,
  queue_id bigint(20) unsigned NOT NULL,
  task varchar(16) NOT NULL,
  object_type varchar(16) NOT NULL,
  object_id bigint(20) unsigned NOT NULL,
  secondary varchar(191) NOT NULL default '',
  old_hash char(40) NOT NULL,
  old_value longtext NULL,
  replacements int(10) unsigned NOT NULL default 0,
  created_at datetime NOT NULL,
  rolled_back_at datetime NULL default NULL,
  PRIMARY KEY  (id),
  KEY idx_queue (queue_id),
  KEY idx_object (object_type,object_id),
  KEY idx_hash (old_hash)
) {$collate};";

        // One column and one key, deliberately. This table answers a membership
        // question ("is this stem still mentioned?") thousands of times per run,
        // and a secondary column would only make the insert wider for no gain.
        $queries[] = "CREATE TABLE {$refs} (
  stem varchar(191) NOT NULL,
  PRIMARY KEY  (stem)
) {$collate};";

        $wpdb->suppress_errors();
        dbDelta( $queries );
        $wpdb->suppress_errors( false );

        $ready = $this->tables_exist();

        if ( $ready ) {
            update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
        }

        return $ready;
    }

    /**
     * Installs the tables when the stored schema version does not match.
     */
    public function maybe_install() {
        if ( get_option( self::OPTION_SCHEMA ) === self::SCHEMA_VERSION ) {
            return;
        }

        $this->install_tables();
    }

    /**
     * Whether every table is present.
     *
     * @return bool
     */
    public function tables_exist() {
        global $wpdb;

        foreach ( array( $this->queue_table(), $this->log_table(), $this->refs_table() ) as $table ) {
            $found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

            if ( empty( $found ) ) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Guards                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Whether this request is allowed to do queue work at all.
     *
     * The structural guarantee behind rule 2. There is no code path from a
     * visitor page view or an admin screen load into scan(), process() or the
     * cron re-arm beyond this check.
     *
     * @return bool
     */
    public function batch_context_ok() {
        return ( defined( 'DOING_CRON' ) && DOING_CRON ) || wp_doing_ajax();
    }

    /**
     * Takes the lock, or reports that somebody else holds it.
     *
     * @return bool True when the caller now owns the lock.
     */
    private function acquire_lock() {
        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            return false;
        }

        set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TTL );

        return true;
    }

    /**
     * Releases the lock. Safe to call when it was never taken.
     */
    private function release_lock() {
        delete_transient( self::LOCK_TRANSIENT );
    }

    /* ------------------------------------------------------------------ */
    /* Cron                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * Arms the next tick when there is work and nothing is scheduled.
     *
     * Runs on init, so it must stay cheap on a page view. It reads one
     * autoloaded-style option and only queries when that flag says work exists.
     */
    public function maybe_reschedule_cron() {
        if ( ! get_option( self::OPTION_WORKING, 0 ) ) {
            return;
        }

        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        wp_schedule_single_event( time() + 5, self::CRON_HOOK );
    }

    /**
     * WP-Cron entry point: advance the scan, process the queue, purge originals.
     */
    public function run_cron_tick() {
        $this->enqueue_from_scan( array( 'budget' => self::CRON_BUDGET * 0.4 ) );
        $this->process( array( 'budget' => self::CRON_BUDGET * 0.5 ) );

        // Last, never first. The purge only runs once the scan says the reference
        // index is complete AND no rewrite row is still open, and both are only
        // true after the two calls above have had their turn.
        $this->purge_verified( array( 'budget' => self::CRON_BUDGET * 0.1 ) );
    }

    /* ------------------------------------------------------------------ */
    /* Scanning                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Builds the queue from the database, a bounded slice at a time.
     *
     * The scan walks wp_posts by id, then the requested postmeta keys, then
     * wp_options, and finally a read-only pass over wp_postmeta that only
     * collects image stems. Every phase shares the same time budget as the
     * rewriter, because a full pass over a large library is minutes of query
     * time even when nothing is written.
     *
     * Recognised $args:
     *   dry_run          (bool)   Count matches, insert nothing.
     *   budget           (float)  Seconds for this call.
     *   meta_keys        (array)  meta_key allowlist for REWRITING. Empty means
     *                              postmeta is not queued at all, which is the
     *                              default: the table holds tens of millions of
     *                              rows on a site with an analytics plugin, and
     *                              rewriting there is unsafe. It does not stop
     *                              the reference pass, which only ever reads.
     *   include_revisions(bool)   Also queue revisions. Off by default.
     *   include_options  (bool)   Also queue wp_options rows. On by default.
     *   restart         (bool)   Start the scan from the beginning.
     *
     * @param array $args Options.
     * @return array Counts and the reason the call returned.
     */
    public function enqueue_from_scan( array $args = array() ) {
        global $wpdb;

        $out = array(
            'scanned'      => 0,
            'queued'       => 0,
            'duplicates'   => 0,
            'refs'         => 0,
            'phases_done'  => array(),
            'dry_run'      => ! empty( $args['dry_run'] ),
            'reason'       => '',
            'elapsed'      => 0.0,
            'restarted'    => false,
        );

        if ( ! $this->batch_context_ok() ) {
            $out['reason'] = 'context';
            return $out;
        }

        if ( ! $this->tables_exist() ) {
            $this->install_tables();
            if ( ! $this->tables_exist() ) {
                $out['reason'] = 'no_tables';
                return $out;
            }
        }

        $state = $this->scan_state( $args );
        $out['restarted'] = $state['restarted'];

        $budget = isset( $args['budget'] ) ? (float) $args['budget'] : self::CRON_BUDGET;
        if ( $budget <= 0 ) {
            $budget = self::CRON_BUDGET;
        }

        $started = microtime( true );

        // Rule 1, applied to the scan as well: time budget, not row count.
        $deadline = microtime( true ) + $budget;

        $queue = $this->queue_table();
        $now   = current_time( 'mysql' );
        $task  = self::TASK_REWRITE;

        while ( microtime( true ) < $deadline ) {
            if ( 'post' === $state['phase'] ) {
                $this->scan_posts_page( $state, $deadline, $args, $out, $queue, $task, $now );
            } elseif ( 'postmeta' === $state['phase'] ) {
                $this->scan_meta_page( $state, $deadline, $args, $out, $queue, $task, $now );
            } elseif ( 'option' === $state['phase'] ) {
                $this->scan_options_page( $state, $deadline, $args, $out, $queue, $task, $now );
            } elseif ( 'refs' === $state['phase'] ) {
                $this->scan_refs_meta_page( $state, $deadline, $args, $out );
            } else {
                break;
            }

            if ( ! $state['continue'] ) {
                break;
            }
        }

        $this->save_scan_state( $state );

        $out['elapsed'] = round( microtime( true ) - $started, 3 );
        $out['finished'] = ( 'done' === $state['phase'] );
        $out['refs_ready'] = $this->refs_ready();

        if ( $out['finished'] ) {
            update_option( self::OPTION_WORKING, 0, false );
        } else {
            update_option( self::OPTION_WORKING, 1, false );
            $this->schedule_next_tick();
        }

        return $out;
    }

    /**
     * Reads (or starts) the scan cursors.
     *
     * @param array $args Caller options.
     * @return array
     */
    private function scan_state( array $args ) {
        $fresh = ! empty( $args['restart'] );

        $meta_keys = array();
        if ( isset( $args['meta_keys'] ) && is_array( $args['meta_keys'] ) ) {
            foreach ( $args['meta_keys'] as $key ) {
                $key = sanitize_key( (string) $key );
                if ( '' !== $key && ! in_array( $key, $meta_keys, true ) ) {
                    $meta_keys[] = $key;
                }
            }
        }

        $stored = get_option( self::OPTION_SCAN, array() );
        $stored = is_array( $stored ) ? $stored : array();

        $defaults = array(
            'phase'            => 'post',
            'post'             => 0,
            'meta'             => 0,
            'option'           => 0,
            'keys'             => $meta_keys,
            'continue'         => false,
            'restarted'        => false,
            'started_at'       => current_time( 'mysql' ),
            'revisions'        => ! empty( $args['include_revisions'] ),
            'options'          => array_key_exists( 'include_options', $args ) ? ! empty( $args['include_options'] ) : true,
            // Reference collection, below. Everything here exists so that a
            // deletion can be proven safe; see rule 8 at the top of this file.
            'refs_meta'        => 0,
            'refs_found'       => 0,
            'refs_complete'    => false,
            'refs_error'       => '',
            'refs_at'          => '',
            'refs_options'     => false,
            'refs_blocked'     => '',
        );

        if ( $fresh ) {
            $state = array_merge( $defaults, $stored );
            $state['phase']    = 'post';
            $state['post']     = 0;
            $state['meta']     = 0;
            $state['option']   = 0;
            $state['continue'] = false;
            $state['restarted'] = true;
            $state['started_at'] = current_time( 'mysql' );

            // A restart invalidates the previous reference index. Marking it
            // incomplete is what makes the purge refuse to run in the window
            // between this call and the table actually being emptied; every
            // other reader of refs_ready() sees "no proof" rather than a stale
            // answer that would authorise a deletion.
            $this->invalidate_refs( $state );

            return $state;
        }

        $state = array_merge( $defaults, $stored );

        // A new meta key list means the meta phase has to be walked again from
        // the start, otherwise the newly allowed keys would never be scanned.
        if ( ! empty( $meta_keys ) && $meta_keys !== $state['keys'] ) {
            $state['keys']      = $meta_keys;
            $state['meta']      = 0;
            $state['continue']  = false;
            $state['restarted'] = true;

            if ( 'post' === $state['phase'] ) {
                // Rewind so the post phase runs again under the new settings.
                $state['post'] = 0;
            }
        }

        if ( empty( $meta_keys ) && 'postmeta' === $state['phase'] ) {
            $state['phase'] = 'option';
        }

        if ( empty( $state['keys'] ) && 'postmeta' === $state['phase'] ) {
            $state['phase']    = 'option';
            $state['continue'] = false;
        }

        if ( ! $state['options'] && 'option' === $state['phase'] ) {
            $state['phase'] = 'refs';
        }

        return $state;
    }

    /**
     * Marks the reference index unusable and empties it.
     *
     * @param array $state Scan state, by reference.
     */
    private function invalidate_refs( array &$state ) {
        $state['refs_meta']     = 0;
        $state['refs_found']    = 0;
        $state['refs_complete'] = false;
        $state['refs_error']    = '';
        $state['refs_at']       = '';
        $state['refs_options']  = false;
        $state['refs_blocked']  = '';

        global $wpdb;

        $refs = $this->refs_table();
        // TRUNCATE does not accept placeholders and there is nothing to
        // interpolate: the table name is built from $wpdb->prefix alone.
        $wpdb->query( "TRUNCATE TABLE {$refs}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Marks the reference index out of date after content was rewritten.
     *
     * The index answers "what did the content say when it was read". The moment a
     * rewrite changes an answer, that reading is history: it still lists the .jpg
     * stems the rewriter just replaced, so a purge would hold files back for
     * good, and worse, a stem it does not list might have been created by the
     * rewrite itself. There is no cheap way to patch a snapshot, so the snapshot
     * is declared stale and the next scan rebuilds it.
     *
     * That is what makes the workflow two passes: scan, rewrite, scan again,
     * then delete. It is not ceremony. It is the difference between "nothing was
     * referenced" and "nothing is referenced now".
     */
    private function expire_refs() {
        $state = $this->current_scan_state();

        $state['refs_complete'] = false;
        $state['refs_at']       = '';
        $state['refs_blocked']  = 'content_changed';

        update_option( self::OPTION_SCAN, $state, false );
    }

    /**
     * The stored scan state as a plain array.
     *
     * @return array
     */
    private function current_scan_state() {
        $state = get_option( self::OPTION_SCAN, array() );

        return is_array( $state ) ? $state : array();
    }

    /**
     * Whether stored content has been mapped completely enough to delete a file.
     *
     * Three things have to be true, and all three fail closed:
     *
     * - the reference pass over wp_postmeta reached the end of the table;
     * - the wp_options pass reached the end and was actually enabled, because an
     *   option nobody looked at could still name the file;
     * - the last run did not hit a database error part way through, since a
     *   partial pass looks exactly like a pass that found nothing.
     *
     * @return bool
     */
    public function refs_ready() {
        $state = get_option( self::OPTION_SCAN, array() );
        $state = is_array( $state ) ? $state : array();

        if ( ! empty( $state['refs_error'] ) ) {
            return false;
        }

        return ! empty( $state['refs_complete'] ) && ! empty( $state['refs_options'] );
    }

    /**
     * Why refs_ready() is false, in words fit for the interface.
     *
     * @return string
     */
    public function refs_blocker() {
        $state = get_option( self::OPTION_SCAN, array() );
        $state = is_array( $state ) ? $state : array();

        if ( ! empty( $state['refs_error'] ) ) {
            return (string) $state['refs_error'];
        }

        if ( empty( $state['refs_complete'] ) ) {
            return isset( $state['refs_blocked'] ) && 'content_changed' === $state['refs_blocked']
                ? 'content_changed'
                : 'scan_incomplete';
        }

        if ( empty( $state['refs_options'] ) ) {
            return 'options_skipped';
        }

        return '';
    }

    /**
     * Persists the scan cursors.
     *
     * @param array $state Scan state.
     */
    private function save_scan_state( array $state ) {
        unset( $state['restarted'] );
        unset( $state['started_at'] );

        update_option( self::OPTION_SCAN, $state, false );
    }

    /**
     * Queues wp_posts rows whose content mentions the uploads folder.
     *
     * The LIKE narrows the result set to posts that can possibly contain a path
     * at all. It is a range scan either way, which is why the id cursor exists:
     * the cost is paid once across many ticks instead of in one enormous query.
     *
     * @param array  $state    Scan state, by reference.
     * @param float  $deadline Time budget end.
     * @param array  $args     Caller options.
     * @param array  $out      Result counters, by reference.
     * @param string $queue    Queue table.
     * @param string $task     Task name.
     * @param string $now      MySQL timestamp.
     */
    private function scan_posts_page( array &$state, $deadline, array $args, array &$out, $queue, $task, $now ) {
        global $wpdb;

        $types = self::$skipped_post_types;

        if ( ! empty( $state['revisions'] ) ) {
            $types = array_values( array_diff( $types, array( 'revision' ) ) );
        }

        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $like         = '%' . $wpdb->esc_like( $this->uploads_token() ) . '%';

        // post_content travels with the id because this page is now the only
        // place that reads it: the stems it mentions are exactly the set that
        // must not be deleted, and a second query per post to recover a value
        // already filtered by the LIKE above would double the scan for nothing.
        //
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE ID > %d
                   AND post_type NOT IN ({$placeholders})
                   AND post_content LIKE %s
                 ORDER BY ID ASC
                 LIMIT %d",
                array_merge(
                    array( (int) $state['post'] ),
                    $types,
                    array( $like, (int) self::SCAN_PAGE )
                )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! empty( $wpdb->last_error ) ) {
            $this->fail_refs( $state, 'db_error: ' . $wpdb->last_error );
            $state['continue'] = false;

            return;
        }

        if ( empty( $rows ) ) {
            $state['phase']    = empty( $state['keys'] ) ? 'option' : 'postmeta';
            $state['continue'] = false;
            $out['phases_done'][] = 'post';

            return;
        }

        foreach ( $rows as $row ) {
            $id = (int) $row['ID'];

            if ( $id > $state['post'] ) {
                $state['post'] = $id;
            }

            $out['scanned']++;

            $out['refs'] += $this->collect_refs( $state, (string) $row['post_content'] );

            $this->queue_item(
                array(
                    'task'        => $task,
                    'object_type' => 'post',
                    'object_id'   => $id,
                    'secondary'   => '',
                ),
                $out,
                $args,
                $queue,
                $now
            );

            // Freed as the row is consumed: the next page of 200 posts would
            // otherwise add another full page of bodies on top of this one.
            $row['post_content'] = '';

            if ( microtime( true ) >= $deadline ) {
                return;
            }
        }

        $state['continue'] = count( $rows ) >= self::SCAN_PAGE;
    }

    /**
     * Queues postmeta rows for an explicit meta_key allowlist.
     *
     * @param array  $state    Scan state, by reference.
     * @param float  $deadline Time budget end.
     * @param array  $args     Caller options.
     * @param array  $out      Result counters, by reference.
     * @param string $queue    Queue table.
     * @param string $task     Task name.
     * @param string $now      MySQL timestamp.
     */
    private function scan_meta_page( array &$state, $deadline, array $args, array &$out, $queue, $task, $now ) {
        global $wpdb;

        if ( empty( $state['keys'] ) ) {
            $state['phase']    = 'option';
            $state['continue'] = false;

            return;
        }

        $keys        = $state['keys'];
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_key FROM {$wpdb->postmeta}
                 WHERE meta_id > %d
                   AND meta_key IN ({$placeholders})
                   AND meta_value <> ''
                 ORDER BY meta_id ASC
                 LIMIT %d",
                array_merge(
                    array( (int) $state['meta'] ),
                    $keys,
                    array( (int) self::SCAN_PAGE )
                )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( empty( $rows ) ) {
            $state['phase']    = $state['options'] ? 'option' : 'refs';
            $state['continue'] = false;
            $out['phases_done'][] = 'postmeta';

            return;
        }

        $seen = array();

        foreach ( $rows as $row ) {
            $post_id = (int) $row['post_id'];
            $key     = (string) $row['meta_key'];

            if ( $post_id > $state['meta'] ) {
                $state['meta'] = $post_id;
            }

            // The queue key is (post, meta_key), so repeated rows for the same
            // pair are counted once. Skipping them here keeps the counter honest.
            $pair = $post_id . '|' . $key;
            if ( isset( $seen[ $pair ] ) ) {
                continue;
            }
            $seen[ $pair ] = true;

            $out['scanned']++;

            $this->queue_item(
                array(
                    'task'        => $task,
                    'object_type' => 'postmeta',
                    'object_id'   => $post_id,
                    'secondary'   => $key,
                ),
                $out,
                $args,
                $queue,
                $now
            );

            if ( microtime( true ) >= $deadline ) {
                return;
            }
        }

        $state['continue'] = count( $rows ) >= self::SCAN_PAGE;
    }

    /**
     * Queues wp_options rows that mention the uploads folder.
     *
     * Transients are excluded: they are regenerated constantly and rewriting them
     * would fight whatever wrote them.
     *
     * @param array  $state    Scan state, by reference.
     * @param float  $deadline Time budget end.
     * @param array  $args     Caller options.
     * @param array  $out      Result counters, by reference.
     * @param string $queue    Queue table.
     * @param string $task     Task name.
     * @param string $now      MySQL timestamp.
     */
    private function scan_options_page( array &$state, $deadline, array $args, array &$out, $queue, $task, $now ) {
        global $wpdb;

        $like        = '%' . $wpdb->esc_like( $this->uploads_token() ) . '%';
        $transient   = $wpdb->esc_like( '_transient_' ) . '%';
        $site_trans  = $wpdb->esc_like( '_site_transient_' ) . '%';
        $own         = $wpdb->esc_like( self::OWN_OPTION_PREFIX ) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_id, option_name, option_value FROM {$wpdb->options}
                 WHERE option_id > %d
                   AND option_name NOT LIKE %s
                   AND option_name NOT LIKE %s
                   AND option_name NOT LIKE %s
                   AND option_value LIKE %s
                 ORDER BY option_id ASC
                 LIMIT %d",
                (int) $state['option'],
                $transient,
                $site_trans,
                $own,
                $like,
                (int) self::SCAN_PAGE
            ),
            ARRAY_A
        );

        if ( ! empty( $wpdb->last_error ) ) {
            $this->fail_refs( $state, 'db_error: ' . $wpdb->last_error );
            $state['continue'] = false;

            return;
        }

        if ( empty( $rows ) ) {
            // Options are one of the three places a stored image URL can live.
            // Reaching the end of this table is what makes the purge willing to
            // run at all; a scan that skipped options has not proved anything.
            $state['refs_options'] = ! empty( $state['options'] );
            $state['phase']        = 'refs';
            $state['continue']     = false;
            $out['phases_done'][]  = 'option';

            return;
        }

        foreach ( $rows as $row ) {
            $option_id = (int) $row['option_id'];

            if ( $option_id > $state['option'] ) {
                $state['option'] = $option_id;
            }

            $out['scanned']++;

            $out['refs'] += $this->collect_refs( $state, (string) $row['option_value'] );

            $this->queue_item(
                array(
                    'task'        => $task,
                    'object_type' => 'option',
                    'object_id'   => $option_id,
                    'secondary'   => (string) $row['option_name'],
                ),
                $out,
                $args,
                $queue,
                $now
            );

            $row['option_value'] = '';

            if ( microtime( true ) >= $deadline ) {
                return;
            }
        }

        $state['continue'] = count( $rows ) >= self::SCAN_PAGE;
    }

    /**
     * Walks wp_postmeta once, collecting only the stems it mentions.
     *
     * This pass exists because the rewrite pass refuses to touch postmeta
     * without an allowlist, and that refusal has to stop here. Rewriting an
     * arbitrary custom field is unsafe: analytics rows, plugin state blobs and
     * serialized payloads all live in the same table. READING them to learn
     * which image stems they mention carries no such risk at all, and skipping
     * it would mean the purge deletes a file that a custom field still points
     * at. Failing to look is exactly the hole rule 8 exists to close.
     *
     * Only the stem is kept. The value is read, reduced to a key and dropped.
     *
     * @param array $state    Scan state, by reference.
     * @param float $deadline Time budget end.
     * @param array $args     Caller options.
     * @param array $out      Result counters, by reference.
     */
    private function scan_refs_meta_page( array &$state, $deadline, array $args, array &$out ) {
        global $wpdb;

        $like = '%' . $wpdb->esc_like( $this->uploads_token() ) . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_id > %d
                   AND meta_value <> ''
                   AND meta_value LIKE %s
                 ORDER BY meta_id ASC
                 LIMIT %d",
                (int) $state['refs_meta'],
                $like,
                (int) self::REFS_SCAN_PAGE
            ),
            ARRAY_A
        );

        if ( ! empty( $wpdb->last_error ) ) {
            $this->fail_refs( $state, 'db_error: ' . $wpdb->last_error );
            $state['continue'] = false;

            return;
        }

        if ( empty( $rows ) ) {
            $state['phase']        = 'done';
            $state['continue']     = false;
            $out['phases_done'][]  = 'refs';

            // The last gate closes here. Until this exact line runs, every other
            // reader of refs_ready() gets "no proof" and the purge deletes
            // nothing, which is the whole point of the ordering.
            $state['refs_complete'] = true;
            $state['refs_at']       = current_time( 'mysql' );

            return;
        }

        foreach ( $rows as $row ) {
            $meta_id = (int) $row['meta_id'];

            if ( $meta_id > $state['refs_meta'] ) {
                $state['refs_meta'] = $meta_id;
            }

            $out['refs'] += $this->collect_refs( $state, (string) $row['meta_value'] );

            $row['meta_value'] = '';

            if ( microtime( true ) >= $deadline ) {
                return;
            }
        }

        $state['continue'] = count( $rows ) >= self::REFS_SCAN_PAGE;
    }

    /**
     * Extracts every image stem from a stored value and records them.
     *
     * @param array  $state Scan state, by reference.
     * @param string $value Stored value.
     * @return int New stems added.
     */
    private function collect_refs( array &$state, $value ) {
        if ( ! is_string( $value ) || '' === $value ) {
            return 0;
        }

        $paths = $this->extract_paths( $value );

        if ( empty( $paths ) ) {
            return 0;
        }

        $stems = array();

        foreach ( $paths as $path ) {
            $stem = self::stem_of( substr( $path, strlen( $this->uploads_token() ) + 1 ) );

            if ( '' !== $stem ) {
                $stems[ $stem ] = true;
            }
        }

        if ( empty( $stems ) ) {
            return 0;
        }

        return $this->store_refs( $state, array_keys( $stems ) );
    }

    /**
     * Inserts stems, ignoring the ones already recorded.
     *
     * @param array $state Scan state, by reference.
     * @param array $stems Stem keys.
     * @return int Rows actually inserted.
     */
    private function store_refs( array &$state, array $stems ) {
        global $wpdb;

        $refs = $this->refs_table();
        $sent = 0;

        foreach ( $stems as $stem ) {
            // The column is the primary key and stops at 191 characters, the
            // longest index prefix utf8mb4 allows. A path longer than that is
            // truncated by WordPress itself on upload, so anything longer here
            // belongs to no file we could delete anyway.
            $stem = substr( (string) $stem, 0, 191 );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $written = $wpdb->query(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                    "INSERT IGNORE INTO {$refs} (stem) VALUES (%s)",
                    $stem
                )
            );

            if ( false === $written ) {
                $this->fail_refs( $state, 'db_error: ' . (string) $wpdb->last_error );

                return $sent;
            }

            $sent += (int) $written;
        }

        return $sent;
    }

    /**
     * Records why the reference index cannot be trusted.
     *
     * @param array  $state   Scan state, by reference.
     * @param string $message Machine-readable reason.
     */
    private function fail_refs( array &$state, $message ) {
        $state['refs_complete'] = false;
        $state['refs_error']    = (string) $message;
    }

    /**
     * Inserts one queue entry, tolerating duplicates.
     *
     * INSERT IGNORE alone would make a re-run useless: an entry that was skipped
     * last time would stay skipped and never be retried. So a duplicate that is
     * not finished is woken up, while a finished one is left alone. That is what
     * makes running the whole thing twice safe: the second run finds the work
     * already done and does nothing.
     *
     * @param array  $item  Entry: task, object_type, object_id, secondary.
     * @param array  $out   Counters, by reference.
     * @param array  $args  Caller options (dry_run).
     * @param string $queue Queue table.
     * @param string $now   MySQL timestamp.
     * @return int|false Insert id, false on failure, null in dry-run.
     */
    private function queue_item( array $item, array &$out, array $args, $queue, $now ) {
        global $wpdb;

        if ( ! empty( $args['dry_run'] ) ) {
            $out['queued']++;

            return null;
        }

        // The three ON DUPLICATE assignments are ordered so that each one reads
        // the status the row had before any of them ran, which is how MySQL
        // evaluates them. The visible consequence is the affected-rows count:
        //
        //   pending / processing / done  -> nothing changes, 0 rows affected,
        //                                   counted as a duplicate. Running the
        //                                   scan twice therefore queues nothing
        //                                   new and re-does nothing.
        //   skipped / failed             -> reactivated, 2 rows affected,
        //                                   counted as newly queued. This is what
        //                                   makes a second pass possible after the
        //                                   missing WebP files finally exist.
        $inserted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                "INSERT IGNORE INTO {$queue}
                    (task, object_type, object_id, secondary, status, reason, attempts, replacements, last_error, created_at, updated_at)
                 VALUES (%s, %s, %d, %s, %s, %s, 0, 0, NULL, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    reason = IF(status IN ('" . self::STATUS_SKIPPED . "','" . self::STATUS_FAILED . "'), '', reason),
                    updated_at = IF(status IN ('" . self::STATUS_SKIPPED . "','" . self::STATUS_FAILED . "'), %s, updated_at),
                    status = IF(status IN ('" . self::STATUS_SKIPPED . "','" . self::STATUS_FAILED . "'), '" . self::STATUS_PENDING . "', status)",
                $item['task'],
                $item['object_type'],
                (int) $item['object_id'],
                (string) $item['secondary'],
                self::STATUS_PENDING,
                '',
                $now,
                $now,
                $now
            )
        );

        if ( false === $inserted ) {
            return false;
        }

        if ( $inserted > 0 ) {
            $out['queued']++;
        } else {
            $out['duplicates']++;
        }

        return $inserted;
    }

    /**
     * Queues a single entry, bypassing the scan.
     *
     * Public because the verification script needs a deterministic entry point
     * that does not depend on what the library happens to contain.
     *
     * @param array $item Entry: task, object_type, object_id, secondary.
     * @return int|false Insert id on a new row, 0 when it already existed.
     */
    public function enqueue_item( array $item ) {
        $out   = array(
            'queued'      => 0,
            'duplicates'  => 0,
            'dry_run'     => false,
        );
        $queue = $this->queue_table();
        $now   = current_time( 'mysql' );

        $defaults = array(
            'task'        => self::TASK_REWRITE,
            'object_type' => 'post',
            'object_id'   => 0,
            'secondary'   => '',
        );

        $item = array_merge( $defaults, $item );

        $result = $this->queue_item( $item, $out, array(), $queue, $now );

        update_option( self::OPTION_WORKING, 1, false );
        $this->schedule_next_tick();

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /* Processing                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Rewrites pending entries until the time budget is spent.
     *
     * Recognised $args:
     *   budget  (float)  Seconds for this call. Defaults to the AJAX budget.
     *   dry_run (bool)   Count what would change and write nothing at all:
     *                     no log row, no content write, no cache invalidation.
     *
     * @param array $args Options.
     * @return array Counters and the reason the call returned.
     */
    public function process( array $args = array() ) {
        global $wpdb;

        $out = array(
            'processed'    => 0,
            'rewritten'    => 0,
            'done'         => 0,
            'skipped'      => 0,
            'failed'       => 0,
            'replacements' => 0,
            'candidates'   => 0,
            'dry_run'      => ! empty( $args['dry_run'] ),
            'reason'       => '',
            'elapsed'      => 0.0,
        );

        // Rule 2.
        if ( ! $this->batch_context_ok() ) {
            $out['reason'] = 'context';
            return $out;
        }

        if ( ! $this->tables_exist() ) {
            $this->install_tables();
            if ( ! $this->tables_exist() ) {
                $out['reason'] = 'no_tables';
                return $out;
            }
        }

        if ( ! $this->acquire_lock() ) {
            $out['reason'] = 'locked';
            return $out;
        }

        $started = microtime( true );

        try {
            $this->requeue_stale();

            $budget = isset( $args['budget'] ) ? (float) $args['budget'] : self::AJAX_BUDGET;
            if ( $budget <= 0 ) {
                $budget = self::AJAX_BUDGET;
            }

            $queue  = $this->queue_table();
            $cursor = $this->lowest_pending_id();

            if ( $cursor <= 0 ) {
                $out['reason'] = 'empty';
                update_option( self::OPTION_CURSOR, 0, false );

                return $out;
            }

            // $cursor is the id of the lowest row still pending, and the page
            // query below is inclusive (id >= $cursor) so that row is not skipped
            // by the very comparison meant to find it. After each row it advances
            // to that row's id plus one, which makes the following page query
            // behave exactly like an exclusive cursor from there on.

            // Rule 1. The loop is bounded by the clock, never by a row counter:
            // a slow host that manages three queries per second has to leave the
            // tick on time rather than be killed halfway through a write.
            $deadline = microtime( true ) + $budget;

            while ( microtime( true ) < $deadline ) {
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                        "SELECT id, object_type, object_id, secondary, attempts
                         FROM {$queue}
                         WHERE status = %s AND id >= %d
                         ORDER BY id ASC
                         LIMIT %d",
                        self::STATUS_PENDING,
                        $cursor,
                        (int) self::FETCH_PAGE
                    ),
                    ARRAY_A
                );

                if ( empty( $rows ) ) {
                    $out['reason'] = 'empty';
                    break;
                }

                $stop = false;

                foreach ( $rows as $row ) {
                    $this->handle_row( $row, $out, $args );

                    $row_id = (int) $row['id'];

                    // Exclusive from here on, so a failed row that went back to
                    // 'pending' is not retried inside the same tick.
                    if ( $row_id >= $cursor ) {
                        $cursor = $row_id + 1;
                    }

                    if ( microtime( true ) >= $deadline ) {
                        $stop = true;
                        break;
                    }
                }

                // Persisted once per page rather than once per row. Losing the
                // last page to a crash costs nothing: those rows are still
                // pending, and rewriting an already rewritten value is a no-op.
                update_option( self::OPTION_CURSOR, $cursor, false );

                if ( $stop || count( $rows ) < self::FETCH_PAGE ) {
                    if ( ! $stop && 'empty' !== $out['reason'] ) {
                        $out['reason'] = 'drained';
                    }
                    break;
                }
            }

            if ( '' === $out['reason'] ) {
                $out['reason'] = 'budget';
            }

            update_option( self::OPTION_UPDATED, current_time( 'mysql' ), false );

            if ( ! empty( $out['rewritten'] ) ) {
                update_option(
                    self::OPTION_REWRITTEN,
                    (int) get_option( self::OPTION_REWRITTEN, 0 ) + (int) $out['replacements'],
                    false
                );

                $this->expire_refs();
            }

            $this->update_working_flag();
            if ( $this->lowest_pending_id() > 0 ) {
                $this->schedule_next_tick();
            }
        } finally {
            $this->release_lock();
        }

        $out['elapsed'] = round( microtime( true ) - $started, 3 );

        return $out;
    }

    /**
     * Lowest unfinished queue id, or 0 when there is none.
     *
     * Recomputed at the start of every tick rather than trusted from the stored
     * cursor: a row that was skipped, failed or left behind by an interrupted
     * process must never be stranded behind a cursor that already passed it.
     *
     * @return int
     */
    private function lowest_pending_id() {
        global $wpdb;

        $queue = $this->queue_table();

        $id = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                "SELECT MIN(id) FROM {$queue} WHERE status = %s",
                self::STATUS_PENDING
            )
        );

        return (int) $id;
    }

    /**
     * Returns rows abandoned mid-flight by a dead process to the pending pool.
     *
     * A row is only stale once it has been sitting in 'processing' for longer
     * than the lock TTL, which is longer than any tick can legitimately run.
     *
     * @return int Rows recovered.
     */
    private function requeue_stale() {
        global $wpdb;

        $queue = $this->queue_table();
        $cut   = gmdate( 'Y-m-d H:i:s', time() - self::LOCK_TTL );

        return (int) $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                "UPDATE {$queue}
                 SET status = %s, updated_at = %s
                 WHERE status = %s AND updated_at < %s",
                self::STATUS_PENDING,
                current_time( 'mysql' ),
                self::STATUS_PROCESSING,
                $cut
            )
        );
    }

    /**
     * Clears the working flag once nothing is left to do.
     */
    private function update_working_flag() {
        $scanning = 'done' !== $this->scan_phase();

        update_option( self::OPTION_WORKING, ( $scanning || $this->lowest_pending_id() > 0 ) ? 1 : 0, false );
    }

    /**
     * Current scan phase.
     *
     * @return string
     */
    private function scan_phase() {
        $state = get_option( self::OPTION_SCAN, array() );
        $state = is_array( $state ) ? $state : array();

        return isset( $state['phase'] ) ? (string) $state['phase'] : 'done';
    }

    /**
     * Schedules the next tick when one is not already armed.
     */
    private function schedule_next_tick() {
        if ( wp_next_scheduled( self::CRON_HOOK ) ) {
            return;
        }

        wp_schedule_single_event( time() + 5, self::CRON_HOOK );
    }

    /* ------------------------------------------------------------------ */
    /* One entry                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Rewrites or dismisses a single queue entry.
     *
     * Every branch records a reason, because an entry that quietly produces no
     * explanation is indistinguishable from a bug.
     *
     * The 'processing' marker is only written once a change is actually
     * imminent. Reading, extracting and looking up are pure reads, and the
     * majority of entries on a real site end there: they reference external
     * images, or already reference WebP. Those cost a single UPDATE instead of
     * two. The marker still exists where it matters, because it is what lets a
     * dead process be detected and its row recovered.
     *
     * @param array $row  Queue row.
     * @param array $out  Counters, by reference.
     * @param array $args Caller options.
     */
    private function handle_row( array $row, array &$out, array $args ) {
        global $wpdb;

        $id          = (int) $row['id'];
        $object_id   = (int) $row['object_id'];
        $object_type = (string) $row['object_type'];
        $secondary   = (string) $row['secondary'];
        $attempts    = (int) $row['attempts'] + 1;
        $dry_run     = ! empty( $args['dry_run'] );

        $out['processed']++;

        $current = $this->read_value( $object_type, $object_id, $secondary );

        if ( ! $current['found'] ) {
            $this->finish( $id, self::STATUS_DONE, 'missing', 0, $out, $dry_run );
            return;
        }

        $value = $current['value'];

        if ( '' === $value || '0' === $value ) {
            $this->finish( $id, self::STATUS_DONE, 'empty', 0, $out, $dry_run );
            return;
        }

        // A post can legitimately hold the same meta key on several meta_id rows.
        // The log has one previous value per entry, so restoring "the" value
        // would be ambiguous. Refuse rather than half-rewrite.
        if ( 'postmeta' === $object_type && ! empty( $current['extra']['rows'] ) && $current['extra']['rows'] > 1 ) {
            $this->finish( $id, self::STATUS_SKIPPED, 'duplicate_meta', 0, $out, $dry_run );
            return;
        }

        $paths = $this->extract_paths( $value );

        if ( empty( $paths ) ) {
            $this->finish( $id, self::STATUS_DONE, 'no_paths', 0, $out, $dry_run );
            return;
        }

        $out['candidates'] += count( $paths );

        $verdicts = $this->lookup_attachments( $paths );

        $safe = array();
        foreach ( $verdicts as $found => $verdict ) {
            if ( ! empty( $verdict['ok'] ) ) {
                $safe[ $found ] = $verdict['target'];
            }
        }

        if ( empty( $safe ) ) {
            $this->finish( $id, self::STATUS_SKIPPED, $this->dominant_reason( $verdicts ), 0, $out, $dry_run );
            return;
        }

        $result = $this->replace_in_value( $value, $safe );

        if ( ! empty( $result['error'] ) ) {
            $this->finish( $id, self::STATUS_SKIPPED, (string) $result['error'], 0, $out, $dry_run );
            return;
        }

        if ( empty( $result['replacements'] ) || $result['value'] === $value ) {
            $this->finish( $id, self::STATUS_DONE, 'no_change', 0, $out, $dry_run );
            return;
        }

        if ( $dry_run ) {
            // Reported and counted, and nothing else. No log row, no content
            // write, no queue row consumed: the entry stays pending so a real run
            // still sees it.
            $out['rewritten']++;
            $out['replacements'] += (int) $result['replacements'];
            $out['done']++;
            return;
        }

        $this->mark( $id, self::STATUS_PROCESSING, '', $attempts, 0 );

        // Rule 4. The previous value is stored first. If this fails, the content
        // is left exactly as it is: an unlogged change cannot be undone.
        $log_id = $this->write_log(
            array(
                'queue_id'     => $id,
                'task'         => self::TASK_REWRITE,
                'object_type'  => $object_type,
                'object_id'    => $object_id,
                'secondary'    => $secondary,
                'old_hash'     => sha1( $value ),
                'old_value'    => $value,
                'replacements' => (int) $result['replacements'],
            )
        );

        if ( ! $log_id ) {
            $this->fail( $id, 'log_failed', 'No se pudo guardar el valor anterior.', $attempts, $out );
            return;
        }

        $written = $this->write_value( $object_type, $object_id, $secondary, (string) $result['value'] );

        if ( false === $written ) {
            // Nothing changed, so nothing needs undoing. The log row goes with it.
            $wpdb->delete( $this->log_table(), array( 'id' => $log_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->fail( $id, 'write_failed', 'No se pudo escribir el nuevo valor.', $attempts, $out );
            return;
        }

        $this->invalidate_cache( $object_type, $object_id, $secondary );

        $out['rewritten']++;
        $out['replacements'] += (int) $result['replacements'];

        $this->finish( $id, self::STATUS_DONE, self::REASON_REWRITTEN, (int) $result['replacements'], $out );
    }

    /**
     * Records a terminal outcome and updates the counters.
     *
     * @param int    $id           Queue id.
     * @param string $status       New status.
     * @param string $reason       Reason key.
     * @param int    $replacements URLs changed.
     * @param array  $out          Counters, by reference.
     * @param bool   $dry_run      When true, count only and persist nothing.
     */
    private function finish( $id, $status, $reason, $replacements, array &$out, $dry_run = false ) {
        if ( self::STATUS_SKIPPED === $status ) {
            $out['skipped']++;
        } elseif ( self::STATUS_DONE === $status ) {
            $out['done']++;
        }

        if ( $dry_run ) {
            return;
        }

        $this->mark( $id, $status, $reason, 0, (int) $replacements );
    }

    /**
     * Records a failure, retrying while attempts remain.
     *
     * @param int    $id      Queue id.
     * @param string $reason  Reason key.
     * @param string $message Human-readable detail stored in last_error.
     * @param int    $attempts Attempts so far.
     * @param array  $out     Counters, by reference.
     */
    private function fail( $id, $reason, $message, $attempts, array &$out ) {
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            $out['failed']++;
            $this->mark( $id, self::STATUS_FAILED, $reason, $attempts, 0, $message );
            return;
        }

        // Back to pending so the next tick tries again, with the cursor already
        // past it so this tick does not spin on the same row.
        $this->mark( $id, self::STATUS_PENDING, $reason, $attempts, 0, $message );
    }

    /**
     * Writes status, reason and counters for one row.
     *
     * @param int         $id           Queue id.
     * @param string      $status       New status.
     * @param string      $reason       Reason key.
     * @param int         $attempts     Attempts counter.
     * @param int         $replacements URLs changed.
     * @param string|null $message      Optional last_error.
     */
    private function mark( $id, $status, $reason, $attempts = 0, $replacements = 0, $message = null ) {
        global $wpdb;

        $data = array(
            'status'       => $status,
            'reason'       => (string) $reason,
            'attempts'     => (int) $attempts,
            'replacements' => (int) $replacements,
            'updated_at'   => current_time( 'mysql' ),
        );

        if ( null !== $message ) {
            $data['last_error'] = (string) $message;
        }

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->queue_table(),
            $data,
            array( 'id' => (int) $id ),
            array( '%s', '%s', '%d', '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * The reason worth reporting when nothing in an entry could be rewritten.
     *
     * A mixed row reports whichever reason explains the most blocked paths, so
     * the interface can say "most of these are external images" rather than a
     * coin flip between two true statements.
     *
     * @param array $verdicts Path => verdict.
     * @return string
     */
    private function dominant_reason( array $verdicts ) {
        $counts = array();

        foreach ( $verdicts as $verdict ) {
            $reason = isset( $verdict['reason'] ) ? (string) $verdict['reason'] : 'no_local_attachment';
            if ( ! isset( $counts[ $reason ] ) ) {
                $counts[ $reason ] = 0;
            }
            $counts[ $reason ]++;
        }

        arsort( $counts );
        $top = key( $counts );

        return $top ? $top : 'no_local_attachment';
    }

    /* ------------------------------------------------------------------ */
    /* Values                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Reads the current stored value for a queue entry.
     *
     * postmeta rows are read as a full result rather than with get_var(),
     * because get_var() cannot tell "no such row" from "the value is NULL" and
     * those two need very different outcomes.
     *
     * @param string $object_type post|postmeta|option.
     * @param int    $object_id   Post id, meta post id, or option id.
     * @param string $secondary   meta_key or option_name.
     * @return array {found: bool, value: string, extra: array}
     */
    private function read_value( $object_type, $object_id, $secondary ) {
        global $wpdb;

        if ( 'post' === $object_type ) {
            $value = $wpdb->get_var(
                $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", (int) $object_id )
            );

            return array(
                'found' => ( null !== $value ),
                'value' => ( null === $value ) ? '' : (string) $value,
                'extra' => array(),
            );
        }

        if ( 'postmeta' === $object_type ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
                     WHERE post_id = %d AND meta_key = %s
                     ORDER BY meta_id ASC",
                    (int) $object_id,
                    $secondary
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                return array(
                    'found' => false,
                    'value' => '',
                    'extra' => array(),
                );
            }

            return array(
                'found' => true,
                'value' => ( null === $rows[0]['meta_value'] ) ? '' : (string) $rows[0]['meta_value'],
                'extra' => array( 'meta_id' => (int) $rows[0]['meta_id'], 'rows' => count( $rows ) ),
            );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_id = %d",
                (int) $object_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return array(
                'found' => false,
                'value' => '',
                'extra' => array(),
            );
        }

        return array(
            'found' => true,
            'value' => ( null === $row['option_value'] ) ? '' : (string) $row['option_value'],
            'extra' => array( 'option_name' => $secondary ),
        );
    }

    /**
     * Writes the new value with a direct UPDATE.
     *
     * Rule 3. wp_update_post() is never used here, so no save_post, no
     * wp_after_insert_post and no SEO reindex is triggered by a bulk rewrite.
     * post_modified is deliberately left alone: this is a repair, not an edit,
     * and pretending it happened at a later time would reorder archives.
     *
     * @param string $object_type post|postmeta|option.
     * @param int    $object_id   Post id, meta post id, or option id.
     * @param string $secondary   meta_key or option_name.
     * @param string $value       New value.
     * @return int|false Rows affected, or false on error.
     */
    private function write_value( $object_type, $object_id, $secondary, $value ) {
        global $wpdb;

        if ( 'post' === $object_type ) {
            return $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->posts,
                array( 'post_content' => $value ),
                array( 'ID' => (int) $object_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        if ( 'postmeta' === $object_type ) {
            return $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->postmeta,
                array( 'meta_value' => $value ),
                array( 'post_id' => (int) $object_id, 'meta_key' => $secondary ),
                array( '%s' ),
                array( '%d', '%s' )
            );
        }

        return $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->options,
            array( 'option_value' => $value ),
            array( 'option_id' => (int) $object_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Drops the caches that a direct UPDATE left stale.
     *
     * Narrow on purpose. wp_cache_flush() would evict the whole object cache for
     * every visitor mid-batch, which is a far larger outage than the one being
     * repaired.
     *
     * @param string $object_type post|postmeta|option.
     * @param int    $object_id   Post id, meta post id, or option id.
     * @param string $secondary   meta_key or option_name.
     */
    private function invalidate_cache( $object_type, $object_id, $secondary ) {
        if ( 'post' === $object_type ) {
            clean_post_cache( (int) $object_id );
            return;
        }

        if ( 'postmeta' === $object_type ) {
            wp_cache_delete( (int) $object_id, 'post_meta' );
            return;
        }

        wp_cache_delete( $secondary, 'options' );

        // An autoloaded option also lives inside the alloptions blob.
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }

    /**
     * Inserts one history row.
     *
     * @param array $data Log columns.
     * @return int|false Insert id, false on failure.
     */
    private function write_log( array $data ) {
        global $wpdb;

        $inserted = $wpdb->insert(
            $this->log_table(),
            array(
                'queue_id'     => (int) $data['queue_id'],
                'task'         => (string) $data['task'],
                'object_type'  => (string) $data['object_type'],
                'object_id'    => (int) $data['object_id'],
                'secondary'    => (string) $data['secondary'],
                'old_hash'     => (string) $data['old_hash'],
                'old_value'    => (string) $data['old_value'],
                'replacements' => (int) $data['replacements'],
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
        );

        return $inserted ? (int) $inserted : false;
    }

    /* ------------------------------------------------------------------ */
    /* Path extraction                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * The folder name that appears inside stored URLs, e.g. "uploads".
     *
     * Derived from the site rather than hardcoded, because UPLOADS can point the
     * media folder somewhere else and every path in every post would then fail
     * to match.
     *
     * @return string
     */
    public function uploads_token() {
        if ( null !== $this->uploads_token ) {
            return $this->uploads_token;
        }

        $uploads = wp_upload_dir();
        $token   = basename( untrailingslashit( (string) $uploads['basedir'] ) );

        $this->basedir       = (string) $uploads['basedir'];
        $this->uploads_token = $token ? $token : 'uploads';

        return $this->uploads_token;
    }

    /**
     * Absolute uploads directory, cached alongside the token.
     *
     * @return string
     */
    private function basedir() {
        if ( null === $this->basedir ) {
            $this->uploads_token();
        }

        return (string) $this->basedir;
    }

    /**
     * Every uploads-relative image path inside a stored value.
     *
     * The returned substrings start at the uploads folder and keep whatever
     * scheme, host, port and directory prefix preceded it. That matters twice
     * over: a hot-linked URL on a third-party host can never match a local
     * attachment by accident, and the eventual replacement only has to swap the
     * final suffix, leaving the rest of the URL byte for byte identical.
     *
     * Covers jpg, jpeg, png and gif. ".jpeg" is included because real content is
     * full of it and the converter's own regex only ever matched ".jpe?g".
     *
     * @param string $value Stored value.
     * @return array List of unique path substrings, in order of appearance.
     */
    private function extract_paths( $value ) {
        if ( ! is_string( $value ) || '' === $value ) {
            return array();
        }

        $token = preg_quote( $this->uploads_token(), '#' );

        // Starts at the folder name, stops at anything that cannot be part of a
        // URL path. The lookbehind stops "myuploads/photo.jpg" from being read as
        // an uploads path at all.
        $pattern = '#(?<![A-Za-z0-9_-])' . $token . '/[^\s"\'<>\\\\()\[\]]+\.(?:jpe?g|png|gif)#i';

        if ( ! preg_match_all( $pattern, $value, $matches ) ) {
            return array();
        }

        $found = array();

        foreach ( $matches[0] as $match ) {
            $found[ $match ] = true;
        }

        return array_keys( $found );
    }

    /**
     * Reduces an uploads-relative path to the key that identifies its image.
     *
     * "uploads/2024/03/foto.jpg" and "uploads/2024/03/foto-300x200.jpg" are one
     * image, not two, and both must collapse to "2024/03/foto". Two suffixes
     * disappear for that reason:
     *
     * - "-<W>x<H>", written by wp_create_image_subsizes() for every intermediate
     *   size. A post that uses the medium size references a file that is not the
     *   attachment's own, so without collapsing, that post's reference would
     *   never be recognised and the family would be deleted underneath it.
     * - "-scaled", added by wp_get_image_editor() when the upload exceeds
     *   big_image_size_threshold. The canonical file becomes "photo-scaled.webp"
     *   while the full-size "photo.jpg" stays beside it.
     *
     * One implementation, used by the rewrite lookup, the reference index and
     * the purge. Two implementations would eventually disagree about what "the
     * same image" means, and the disagreement would show up as a deleted file
     * that something still points at.
     *
     * @param string $relative Uploads-relative path.
     * @return string
     */
    public static function stem_of( $relative ) {
        $relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );

        if ( '' === $relative ) {
            return '';
        }

        $dir  = trim( dirname( $relative ), '.' );
        $stem = preg_replace( '/\.(jpe?g|png|gif|webp)$/i', '', wp_basename( $relative ) );
        $stem = preg_replace( '/-\d+x\d+$/', '', (string) $stem );
        $stem = preg_replace( '/-scaled$/', '', (string) $stem );

        $stem = trim( (string) $stem, '-' );

        if ( '' === $stem ) {
            return '';
        }

        return ( '.' === $dir || '' === $dir ) ? $stem : $dir . '/' . $stem;
    }

    /* ------------------------------------------------------------------ */
    /* Attachment lookup                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Decides, per path, whether it may be rewritten to .webp.
     *
     * Two independent checks, because either one alone is unsafe:
     *
     *   - A local attachment must own the file. This is what separates a hot
     *     link to another domain from our own media, and it is answered by the
     *     database rather than by a guess from the shape of the URL.
     *   - The .webp must actually exist on disk. An attachment record alone is
     *     not enough: if the file was never converted, rewriting the URL now
     *     would replace a working image with a 404.
     *
     * @param array $paths Paths from extract_paths().
     * @return array Path => array(ok, reason, target, attachment_id).
     */
    private function lookup_attachments( array $paths ) {
        $verdicts = array();
        $unknown  = array();

        // Resolved candidates per path, so one query can answer many paths.
        $wanted = array();

        foreach ( $paths as $path ) {
            $memo_key = $this->memo_key( $path );

            if ( array_key_exists( $memo_key, $this->memo ) ) {
                $verdicts[ $path ] = $this->memo[ $memo_key ];
                continue;
            }

            $wanted[ $path ] = $this->relative_candidates( $path );
        }

        if ( empty( $wanted ) ) {
            return $verdicts;
        }

        // Everything the lookup has to ask about, flattened and de-duplicated.
        $relatives = array();
        foreach ( $wanted as $candidates ) {
            foreach ( $candidates as $relative ) {
                $relatives[ $relative ] = true;
            }
        }

        $relatives = array_keys( $relatives );
        $owners    = array();

        foreach ( array_chunk( $relatives, self::LOOKUP_CHUNK ) as $chunk ) {
            foreach ( $this->query_owners( $chunk ) as $relative => $attachment_id ) {
                $owners[ $relative ] = $attachment_id;
            }
        }

        foreach ( $wanted as $path => $candidates ) {
            $owner = 0;
            foreach ( $candidates as $relative ) {
                if ( isset( $owners[ $relative ] ) ) {
                    $owner = $owners[ $relative ];
                    break;
                }
            }

            $verdict = $this->judge( $path, $owner );

            $this->remember( $this->memo_key( $path ), $verdict );
            $verdicts[ $path ] = $verdict;
        }

        return $verdicts;
    }

    /**
     * Candidate `_wp_attached_file` values for a path found in content.
     *
     * Intermediate sizes ("photo-150x150.jpg") are generated files that never
     * appear in `_wp_attached_file`, so the parent name is tried as well. Any of
     * the five supported extensions counts, because after conversion the record
     * points at .webp and the old .jpg is no longer there to be found.
     *
     * @param string $path Path substring starting at the uploads folder.
     * @return array Relative candidates, most specific first.
     */
    private function relative_candidates( $path ) {
        $token   = $this->uploads_token();
        $offset  = strlen( $token ) + 1;
        $relative = substr( $path, $offset );

        if ( '' === $relative ) {
            return array();
        }

        $slash = strrpos( $relative, '/' );
        $dir   = ( false === $slash ) ? '' : substr( $relative, 0, $slash + 1 );
        $base  = ( false === $slash ) ? $relative : substr( $relative, $slash + 1 );

        $stem = preg_replace( '/\.(jpe?g|png|gif)$/i', '', $base );

        $names = array( $stem );

        $parent = preg_replace( '/-\d+x\d+$/', '', $stem );
        if ( $parent && $parent !== $stem ) {
            $names[] = $parent;
        }

        $candidates = array();

        foreach ( $names as $name ) {
            foreach ( array( 'webp', 'jpg', 'jpeg', 'png', 'gif' ) as $extension ) {
                $candidate = $dir . $name . '.' . $extension;
                if ( ! in_array( $candidate, $candidates, true ) ) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * Resolves relative file names to attachment ids, in as few queries as the
     * chunk size allows.
     *
     * @param array $relatives Uploads-relative file names.
     * @return array relative => attachment id.
     */
    private function query_owners( array $relatives ) {
        global $wpdb;

        if ( empty( $relatives ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $relatives ), '%s' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.meta_value AS relative, MIN(m.post_id) AS attachment_id
                 FROM {$wpdb->postmeta} m
                 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = '_wp_attached_file'
                   AND p.post_type = 'attachment'
                   AND m.meta_value IN ({$placeholders})
                 GROUP BY m.meta_value",
                $relatives
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $owners = array();

        foreach ( (array) $rows as $row ) {
            $owners[ (string) $row['relative'] ] = (int) $row['attachment_id'];
        }

        return $owners;
    }

    /**
     * Turns an ownership result into a rewrite verdict.
     *
     * @param string $path  Path substring.
     * @param int    $owner Attachment id, or 0 when nothing owns it.
     * @return array
     */
    private function judge( $path, $owner ) {
        if ( $owner <= 0 ) {
            return array(
                'ok'            => false,
                'reason'        => 'no_local_attachment',
                'target'        => '',
                'attachment_id' => 0,
            );
        }

        $target = $this->with_webp_extension( $path );

        if ( '' === $target ) {
            return array(
                'ok'            => false,
                'reason'        => 'no_local_attachment',
                'target'        => '',
                'attachment_id' => $owner,
            );
        }

        $absolute = trailingslashit( $this->basedir() ) . substr( $target, strlen( $this->uploads_token() ) + 1 );

        if ( ! file_exists( $absolute ) ) {
            return array(
                'ok'            => false,
                'reason'        => 'no_webp_file',
                'target'        => $target,
                'attachment_id' => $owner,
            );
        }

        return array(
            'ok'            => true,
            'reason'        => '',
            'target'        => $target,
            'attachment_id' => $owner,
        );
    }

    /**
     * Replaces only the final extension of a path.
     *
     * Domain, scheme, port and directories are returned untouched, character for
     * character. That is what makes the change safe to apply to a URL that was
     * matched by a local attachment but written in some other shape, such as a
     * protocol-relative URL or a query string carried elsewhere in the markup.
     *
     * @param string $path Path substring.
     * @return string New path, or '' when there is no known extension.
     */
    private function with_webp_extension( $path ) {
        // Replaces the extension together with its leading dot. Cutting at the
        // dot and appending a bare "webp" instead is the tempting version and it
        // is wrong: it drops the separator and yields "photo.jpg" as "photowebp".
        $replaced = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $path, 1 );

        if ( null === $replaced || $replaced === $path ) {
            return '';
        }

        return $replaced;
    }

    /**
     * Memo key for a path, short and stable across requests.
     *
     * @param string $path Path substring.
     * @return string
     */
    private function memo_key( $path ) {
        return sha1( $path );
    }

    /**
     * Stores a verdict, keeping the memo inside its ceiling.
     *
     * @param string $key     Memo key.
     * @param array  $verdict Verdict.
     */
    private function remember( $key, array $verdict ) {
        if ( count( $this->memo ) >= self::MEMO_LIMIT ) {
            // Dropped whole rather than evicted one by one: a rough ceiling is
            // enough, and tracking recency would cost more than it saves.
            $this->memo = array();
        }

        $this->memo[ $key ] = $verdict;
    }

    /* ------------------------------------------------------------------ */
    /* Replacement                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Applies the approved replacements to a stored value.
     *
     * Serialized values are unpacked, edited and packed again. A plain
     * str_replace() over a serialized string is not merely inelegant, it destroys
     * data: the payload carries byte lengths, and ".jpeg" turning into ".webp"
     * adds a character without updating the length that precedes it. unserialize()
     * then returns false and whatever read that value gets nothing instead of
     * an array. Two fields and a nested structure are enough to lose a whole
     * settings blob.
     *
     * @param string $value  Stored value.
     * @param array  $safe   Old path => new path.
     * @return array {value, replacements} or {error}.
     */
    private function replace_in_value( $value, array $safe ) {
        if ( is_serialized( $value ) ) {
            return $this->replace_in_serialized( $value, $safe );
        }

        $replacements = 0;
        $updated      = $value;

        // Longest first. Two paths can share a prefix, and replacing the shorter
        // one first would corrupt the longer one's match.
        $paths = array_keys( $safe );
        usort( $paths, array( __CLASS__, 'compare_by_length' ) );

        foreach ( $paths as $path ) {
            $count = substr_count( $updated, $path );
            if ( $count > 0 ) {
                $updated = str_replace( $path, $safe[ $path ], $updated );
                $replacements += $count;
            }
        }

        return array(
            'value'        => $updated,
            'replacements' => $replacements,
        );
    }

    /**
     * Serialized variant of replace_in_value().
     *
     * WordPress stores double-serialized values routinely. add_option() and
     * update_option() run maybe_serialize() on whatever they are handed, so
     * passing an already-serialized array produces a serialized STRING whose
     * contents are themselves a serialized array. That is not a curiosity: it
     * is what happens on any site where a plugin or a WP-CLI command has ever
     * saved a pre-serialized value.
     *
     * It matters because unserializing one layer of such a value returns a plain
     * string, and replacing text inside that string is precisely the naive
     * str_replace this method exists to avoid. Nesting is therefore handled
     * explicitly, one level at a time, and the same length bookkeeping is
     * applied at every level.
     *
     * @param string $value Serialized value.
     * @param array  $safe  Old path => new path.
     * @param int    $depth Current nesting level.
     * @return array {value, replacements} or {error}.
     */
    private function replace_in_serialized( $value, array $safe, $depth = 0 ) {
        if ( $depth >= self::MAX_SERIALIZE_DEPTH ) {
            return array( 'error' => 'unserializable' );
        }

        // PHP 7.0 accepts the options argument. Objects are refused rather than
        // rebuilt: an incomplete class re-serializes to something that is not the
        // original object, and a settings blob is not worth that risk.
        $data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( false === $data && 'b:0;' !== $value ) {
            return array( 'error' => 'unserializable' );
        }

        $state = array(
            'safe'         => $safe,
            'replacements' => 0,
            'changed'      => false,
            'refuse'       => false,
            'depth'        => $depth,
        );

        $walked = $this->walk_replace( $data, $state );

        if ( $state['refuse'] ) {
            return array( 'error' => 'unserializable' );
        }

        if ( ! $state['changed'] ) {
            // Nothing matched. The original bytes are returned untouched rather
            // than a re-serialization, so a value this plugin does not
            // understand keeps whatever shape it arrived in.
            return array(
                'value'        => $value,
                'replacements' => 0,
            );
        }

        return array(
            'value'        => serialize( $walked ),
            'replacements' => $state['replacements'],
        );
    }

    /**
     * Walks an unserialized structure replacing URLs in its string leaves.
     *
     * @param mixed $node  Current node.
     * @param array $state Mutable counters and flags.
     * @return mixed
     */
    private function walk_replace( $node, array &$state ) {
        if ( is_array( $node ) ) {
            foreach ( $node as $key => $child ) {
                $node[ $key ] = $this->walk_replace( $child, $state );
            }

            return $node;
        }

        if ( is_object( $node ) ) {
            $state['refuse'] = true;
            return $node;
        }

        if ( ! is_string( $node ) || '' === $node ) {
            return $node;
        }

        // Another layer of serialization inside this one. Descend into it rather
        // than editing its text: a plain replace here would leave the byte
        // lengths declared inside it describing the old, longer URLs.
        if ( is_serialized( $node ) ) {
            $inner = $this->replace_in_serialized( $node, $state['safe'], (int) $state['depth'] + 1 );

            if ( ! empty( $inner['error'] ) ) {
                $state['refuse'] = true;
                return $node;
            }

            if ( $inner['value'] !== $node ) {
                $state['changed']      = true;
                $state['replacements'] += (int) $inner['replacements'];
            }

            return $inner['value'];
        }

        $paths = array_keys( $state['safe'] );
        usort( $paths, array( __CLASS__, 'compare_by_length' ) );

        foreach ( $paths as $path ) {
            $count = substr_count( $node, $path );
            if ( $count > 0 ) {
                $node = str_replace( $path, $state['safe'][ $path ], $node );
                $state['replacements'] += $count;
                $state['changed']      = true;
            }
        }

        return $node;
    }

    /**
     * Longest-first comparator for usort().
     *
     * @param string $a First path.
     * @param string $b Second path.
     * @return int
     */
    public static function compare_by_length( $a, $b ) {
        $diff = strlen( $b ) - strlen( $a );

        return 0 !== $diff ? $diff : strcmp( $a, $b );
    }

    /* ------------------------------------------------------------------ */
    /* Progress                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Counters and the most recent reversible change, for the interface.
     *
     * @return array
     */
    public function get_progress() {
        global $wpdb;

        $queue = $this->queue_table();
        $log   = $this->log_table();

        $counts = array(
            'pending'    => 0,
            'processing' => 0,
            'done'       => 0,
            'failed'     => 0,
            'skipped'    => 0,
            'total'      => 0,
        );

        $replacements = 0;

        if ( $this->tables_exist() ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
            $rows = $wpdb->get_results(
                "SELECT status, COUNT(*) AS rows_count, SUM(replacements) AS replacements
                 FROM {$queue}
                 GROUP BY status",
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            foreach ( (array) $rows as $row ) {
                $status = (string) $row['status'];
                $counts[ $status ] = isset( $counts[ $status ] )
                    ? $counts[ $status ] + (int) $row['rows_count']
                    : (int) $row['rows_count'];
                $counts['total'] += (int) $row['rows_count'];

                if ( self::STATUS_DONE === $status ) {
                    $replacements += (int) $row['replacements'];
                }
            }

            $reasons = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                "SELECT reason, COUNT(*) AS rows_count
                 FROM {$queue}
                 WHERE status IN ('" . self::STATUS_SKIPPED . "','" . self::STATUS_FAILED . "')
                   AND reason <> ''
                 GROUP BY reason
                 ORDER BY rows_count DESC
                 LIMIT 12",
                ARRAY_A
            );

            $last = $wpdb->get_row(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                    "SELECT queue_id, object_type, object_id, secondary, replacements, created_at
                     FROM {$log}
                     WHERE rolled_back_at IS NULL AND task = %s
                     ORDER BY id DESC
                     LIMIT 1",
                    self::TASK_REWRITE
                ),
                ARRAY_A
            );

            // Scoped to the rewrite task on purpose. A verified purge writes an
            // audit row with queue_id 0 and no queue entry behind it: counting it
            // here would light up "Deshacer la última entrada" for a change that
            // has nothing to undo, and would advertise a rollback that then fails.
            $log_total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
                    "SELECT COUNT(*) FROM {$log} WHERE task = %s",
                    self::TASK_REWRITE
                )
            );

            $log_open = (int) $wpdb->get_var(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
                    "SELECT COUNT(*) FROM {$log} WHERE task = %s AND rolled_back_at IS NULL",
                    self::TASK_REWRITE
                )
            );

            $purge_log_total = (int) $wpdb->get_var(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
                    "SELECT COUNT(*) FROM {$log} WHERE task = %s",
                    self::TASK_PURGE
                )
            );
        } else {
            $reasons         = array();
            $last            = null;
            $log_total       = 0;
            $log_open        = 0;
            $purge_log_total = 0;
        }

        $reasons_list = array();
        foreach ( (array) $reasons as $row ) {
            $reasons_list[] = array(
                'reason' => (string) $row['reason'],
                'count'  => (int) $row['rows_count'],
            );
        }

        $state = get_option( self::OPTION_SCAN, array() );
        $state = is_array( $state ) ? $state : array();

        $purge = $this->get_purge_state();

        return array(
            'counts'       => $counts,
            'reasons'      => $reasons_list,
            'replacements' => $replacements,
            'cursor'       => (int) get_option( self::OPTION_CURSOR, 0 ),
            'enqueued'     => (int) get_option( self::OPTION_ENQUEUED, 0 ),
            'updated'      => (string) get_option( self::OPTION_UPDATED, '' ),
            'scanning'     => isset( $state['phase'] ) && 'done' !== $state['phase'],
            'phase'        => isset( $state['phase'] ) ? (string) $state['phase'] : 'done',
            'keys'         => isset( $state['keys'] ) && is_array( $state['keys'] ) ? $state['keys'] : array(),
            'logTotal'     => $log_total,
            'logOpen'      => $log_open,
            'purgeLogTotal' => $purge_log_total,
            'tablesReady'  => $this->tables_exist(),
            'purge'        => $purge,
            'refs'         => array(
                'ready'     => $this->refs_ready(),
                'blocker'   => $this->refs_blocker(),
                'found'     => isset( $state['refs_found'] ) ? (int) $state['refs_found'] : 0,
                'at'        => isset( $state['refs_at'] ) ? (string) $state['refs_at'] : '',
            ),
            'last'         => $last ? array(
                'queueId'      => (int) $last['queue_id'],
                'objectType'   => (string) $last['object_type'],
                'objectId'     => (int) $last['object_id'],
                'secondary'    => (string) $last['secondary'],
                'replacements' => (int) $last['replacements'],
                'createdAt'    => (string) $last['created_at'],
            ) : null,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Rollback                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Restores the value stored in the log for one entry.
     *
     * @param int $queue_id Queue entry, or 0 for the most recent un-undone change.
     * @return array|WP_Error
     */
    public function rollback( $queue_id = 0 ) {
        global $wpdb;

        if ( ! $this->tables_exist() ) {
            return new WP_Error( 'wps_queue_no_tables', __( 'Las tablas de la cola no existen.', 'webp-studio' ) );
        }

        $log = $this->log_table();

        // Scoped to the rewrite task. A verified purge also writes a log row, and
        // the "most recent change" query below would happily pick one up and try
        // to restore a comma-separated list of filenames into post_content.
        if ( $queue_id > 0 ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                    "SELECT * FROM {$log}
                     WHERE queue_id = %d AND rolled_back_at IS NULL AND task = %s
                     ORDER BY id DESC LIMIT 1",
                    (int) $queue_id,
                    self::TASK_REWRITE
                ),
                ARRAY_A
            );
        } else {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                    "SELECT * FROM {$log} WHERE rolled_back_at IS NULL AND task = %s ORDER BY id DESC LIMIT 1",
                    self::TASK_REWRITE
                ),
                ARRAY_A
            );
        }

        if ( ! $row ) {
            return new WP_Error(
                'wps_queue_nothing_to_undo',
                __( 'No hay ninguna reescritura pendiente de deshacer.', 'webp-studio' )
            );
        }

        $object_type = (string) $row['object_type'];
        $object_id   = (int) $row['object_id'];
        $secondary   = (string) $row['secondary'];
        $old_value   = ( null === $row['old_value'] ) ? '' : (string) $row['old_value'];

        $current = $this->read_value( $object_type, $object_id, $secondary );

        if ( ! $current['found'] ) {
            return new WP_Error(
                'wps_queue_target_gone',
                __( 'El contenido que se iba a restaurar ya no existe.', 'webp-studio' )
            );
        }

        if ( sha1( $current['value'] ) === sha1( $old_value ) ) {
            return new WP_Error(
                'wps_queue_already_restored',
                __( 'Ese valor ya está en su estado anterior.', 'webp-studio' )
            );
        }

        $written = $this->write_value( $object_type, $object_id, $secondary, $old_value );

        if ( false === $written ) {
            return new WP_Error(
                'wps_queue_rollback_failed',
                __( 'No se pudo restaurar el valor anterior.', 'webp-studio' )
            );
        }

        $this->invalidate_cache( $object_type, $object_id, $secondary );

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $log,
            array( 'rolled_back_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $row['id'] ),
            array( '%s' ),
            array( '%d' )
        );

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $this->queue_table(),
            array(
                'status'       => self::STATUS_SKIPPED,
                'reason'       => 'rolled_back',
                'replacements' => 0,
                'updated_at'   => current_time( 'mysql' ),
            ),
            array( 'id' => (int) $row['queue_id'] ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );

        return array(
            'restored'  => true,
            'queueId'   => (int) $row['queue_id'],
            'objectId'  => $object_id,
            'restoredBytes' => strlen( $old_value ),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Verified purge of originals                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Registers the old-format files a conversion replaced, instead of deleting them.
     *
     * Called by the converter the moment it writes the .webp. The files stay on
     * disk until purge_verified() has proved that no stored value points at
     * them, because "not referenced right now" is not proof: the reference can
     * be written afterwards, and the media library writes the .webp URL into a
     * post only when the post is saved, which is after the upload.
     *
     * The whole family is registered together, never the single original. The
     * -300x200.jpg of an image is exactly the file a theme or a block layout
     * most often links to, and a rule that deleted the full-size file while
     * keeping its thumbnails would remove the wrong half of the problem.
     *
     * @param int         $attachment_id Attachment post ID.
     * @param string      $source        Absolute path of the file just replaced.
     * @param array|null  $old_metadata  Previous attachment metadata.
     * @return bool True when the files were removed on the spot, false when they
     *              were registered for a later verified purge.
     */
    public function defer_original_purge( $attachment_id, $source, $old_metadata = null ) {
        $files = $this->old_format_family( $source, $old_metadata );

        if ( empty( $files ) ) {
            return true;
        }

        $referenced = $this->family_is_referenced( $files, $attachment_id );

        if ( null === $referenced ) {
            // No proof available. That is not the same as "not referenced", and
            // treating it as one is precisely the bug this class exists to fix.
            // The reason says which of the two ways the proof failed, because
            // "no scan yet" and "the scan is older than this image" call for
            // completely different next steps on the screen.
            $reason = $this->refs_ready() ? 'refs_stale' : 'refs_unknown';

            $this->write_pending_purge( $attachment_id, $files, self::PURGE_PENDING, $reason );
            $this->arm_purge_work();

            return false;
        }

        if ( $referenced ) {
            $this->write_pending_purge( $attachment_id, $files, self::PURGE_WAITING, self::PURGE_REASON_REFERENCED );

            return false;
        }

        // Proven free: nobody's text mentions this image, and the reference
        // index is at least as new as the attachment, so nothing can have been
        // written since. Delete now and forget about it.
        $this->remove_files( $files );
        delete_post_meta( $attachment_id, self::META_PENDING_PURGE );
        delete_post_meta( $attachment_id, self::META_PURGE_STATE );

        return true;
    }

    /**
     * Every old-format file belonging to one attachment, relative to uploads.
     *
     * Built from the previous metadata and then from the directory itself, so a
     * subsize the metadata forgot about is still covered. Only the four source
     * extensions are ever named, and only for this attachment's own stem: no
     * prefix glob, because a glob in a directory holding real content has
     * already destroyed user files once in this project.
     *
     * @param string     $source       Absolute path of the replaced file.
     * @param array|null $old_metadata Previous attachment metadata.
     * @return array Uploads-relative paths, without duplicates.
     */
    private function old_format_family( $source, $old_metadata ) {
        $base = trailingslashit( $this->basedir() );
        $dir  = dirname( $source );

        $relative_source = ltrim( str_replace( $base, '', $source ), '/' );

        if ( '' === $relative_source ) {
            return array();
        }

        $found = array( $relative_source => true );

        if ( is_array( $old_metadata ) && ! empty( $old_metadata['sizes'] ) && is_array( $old_metadata['sizes'] ) ) {
            foreach ( $old_metadata['sizes'] as $size ) {
                if ( empty( $size['file'] ) ) {
                    continue;
                }

                $name = wp_basename( $size['file'] );

                if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $name ) ) {
                    continue;
                }

                $found[ ltrim( str_replace( $base, '', $dir . '/' . $name ), '/' ) ] = true;
            }
        }

        // The stem, with any -scaled marker removed. Every subsize and the
        // WordPress-side unscaled original sit in the same directory as the file
        // that was just converted, so only the name varies from here on.
        $name = self::stem_of( wp_basename( $relative_source ) );

        if ( '' === $name ) {
            return array();
        }

        $resolved = array();

        foreach ( $found as $relative => $unused ) {
            if ( '' !== $relative ) {
                $resolved[ $relative ] = true;
            }
        }

        foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $ext ) {
            $candidate = ltrim( str_replace( $base, '', $dir . '/' . $name . '.' . $ext ), '/' );

            if ( file_exists( $base . $candidate ) ) {
                $resolved[ $candidate ] = true;
            }

            // Anchored to this attachment's exact stem, never to a shared prefix.
            // A glob like "<stem>*" in a directory holding real content has
            // already destroyed user files once in this project.
            foreach ( (array) glob( $dir . '/' . $name . '-*.' . $ext ) as $match ) {
                $resolved[ ltrim( str_replace( $base, '', $match ), '/' ) ] = true;
            }
        }

        // Only files that are actually there. Registering a path that does not
        // exist would make the next purge report a deletion that never happened.
        $existing = array();

        foreach ( $resolved as $relative => $unused ) {
            if ( file_exists( $base . $relative ) ) {
                $existing[ $relative ] = true;
            }
        }

        unset( $wpdb );

        return array_keys( $existing );
    }

    /**
     * Whether any stored value still mentions this family of files.
     *
     * Returns three states, not two, and the third is the important one:
     *
     *   true   at least one stem is in the reference index;
     *   false  the index is complete, current and no stem is in it;
     *   null   the answer cannot be trusted, so nothing may be deleted.
     *
     * Collapsing null into false is the bug: it turns "we have not looked yet"
     * into "we looked and found nothing", and that is how a live image loses its
     * file.
     *
     * @param array $files         Uploads-relative paths.
     * @param int   $attachment_id Attachment the files belong to.
     * @return bool|null
     */
    private function family_is_referenced( array $files, $attachment_id ) {
        if ( ! $this->refs_ready() || ! $this->refs_cover( $attachment_id ) ) {
            return null;
        }

        $stems = array();

        foreach ( $files as $relative ) {
            $stem = self::stem_of( $relative );

            if ( '' !== $stem ) {
                $stems[ $stem ] = true;
            }
        }

        if ( empty( $stems ) ) {
            return false;
        }

        return ! empty( $this->refs_present( array_keys( $stems ) ) );
    }

    /**
     * Whether the reference index was built after this attachment existed.
     *
     * This is not a detail, it is the whole reason the immediate deletion is
     * safe at all.
     *
     * The index is a snapshot. "The stem is not in it" only means "nothing
     * mentioned the image WHEN THE SCAN RAN". For a freshly uploaded image that
     * is a statement about the past: the reference has not been written yet. The
     * editor uploads, the plugin converts and deletes during that same request,
     * and the URL only lands in post_content when the post is saved, which is
     * afterwards. Deleting on the strength of the snapshot breaks the page
     * every single time.
     *
     * So absence is only evidence when the snapshot is at least as new as the
     * image. For a new upload it never is, and the deletion waits. For an old
     * attachment being converted by a backfill run against a scan that already
     * finished, it is, and nothing waits.
     *
     * @param int $attachment_id Attachment post ID.
     * @return bool
     */
    private function refs_cover( $attachment_id ) {
        $state = get_option( self::OPTION_SCAN, array() );
        $state = is_array( $state ) ? $state : array();

        $built = isset( $state['refs_at'] ) ? (string) $state['refs_at'] : '';

        if ( '' === $built ) {
            return false;
        }

        $created = get_post_field( 'post_date', (int) $attachment_id );

        if ( ! $created ) {
            return false;
        }

        // Both values are site-local "Y-m-d H:i:s" strings, so both go through
        // the same conversion and the comparison means what it looks like.
        return strtotime( $built ) >= strtotime( $created );
    }

    /**
     * Which of these stems the reference index knows about.
     *
     * @param array $stems Stem keys.
     * @return array Subset of $stems that is present.
     */
    private function refs_present( array $stems ) {
        global $wpdb;

        if ( empty( $stems ) ) {
            return array();
        }

        $refs = $this->refs_table();
        $hits = array();

        foreach ( array_chunk( $stems, 400 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT stem FROM {$refs} WHERE stem IN ({$placeholders})",
                    $chunk
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            foreach ( (array) $rows as $row ) {
                $hits[ (string) $row ] = true;
            }
        }

        return array_keys( $hits );
    }

    /**
     * Stores the pending purge for one attachment.
     *
     * Both meta rows are written here and nowhere else, so the indexed state
     * column can never drift away from the payload it summarises.
     *
     * @param int    $attachment_id Attachment post ID.
     * @param array  $files         Uploads-relative paths.
     * @param string $state         pending|waiting.
     * @param string $reason        Machine-readable reason.
     */
    private function write_pending_purge( $attachment_id, array $files, $state, $reason ) {
        update_post_meta(
            (int) $attachment_id,
            self::META_PENDING_PURGE,
            array(
                'files'   => array_values( $files ),
                'state'   => (string) $state,
                'reason'  => (string) $reason,
                'updated' => current_time( 'mysql' ),
            )
        );

        update_post_meta( (int) $attachment_id, self::META_PURGE_STATE, (string) $state );
    }

    /**
     * Makes sure a cron tick will run so the pending purge can resolve itself.
     *
     * The lock and the working flag make this idempotent: a second conversion
     * while a scan is already running changes nothing.
     */
    private function arm_purge_work() {
        update_option( self::OPTION_WORKING, 1, false );
        $this->schedule_next_tick();
    }

    /**
     * Removes files from disk.
     *
     * @param array $files Uploads-relative paths.
     * @return int Files actually removed.
     */
    private function remove_files( array $files ) {
        $base = trailingslashit( $this->basedir() );
        $gone = 0;

        foreach ( $files as $relative ) {
            $path = $base . ltrim( (string) $relative, '/' );

            // One more check at the last possible moment. The decision was taken
            // from the reference index a moment ago; this makes the destructive
            // step itself depend on the file still being the one we decided about.
            if ( ! file_exists( $path ) ) {
                continue;
            }

            if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink
                $gone++;
            }
        }

        return $gone;
    }

    /**
     * Deletes the originals whose reference check came back clean.
     *
     * Recognised $args:
     *   budget  (float)  Seconds for this call. Defaults to the AJAX budget.
     *   dry_run (bool)   Report what would go and delete nothing.
     *
     * Nothing at all is deleted unless BOTH gates open:
     *
     *   1. the reference index is complete (refs_ready());
     *   2. no rewrite entry is still pending or in flight.
     *
     * The second one matters as much as the first. A pending rewrite means
     * somebody's post still holds a .jpg URL for this very image, so the whole
     * point of deleting the file would be to break it one tick earlier.
     *
     * Idempotent by construction: a file that is already gone is not counted,
     * and an attachment with no pending meta is simply not in the page.
     *
     * @param array $args Options.
     * @return array Counters and the reason the call returned.
     */
    public function purge_verified( array $args = array() ) {
        global $wpdb;

        $out = array(
            'scanned'   => 0,
            'purged'    => 0,
            'files'     => 0,
            'waiting'   => 0,
            'skipped'   => 0,
            'remaining' => 0,
            'dry_run'   => ! empty( $args['dry_run'] ),
            'reason'    => '',
            'elapsed'   => 0.0,
        );

        // Rule 2.
        if ( ! $this->batch_context_ok() ) {
            $out['reason'] = 'context';
            return $out;
        }

        if ( ! $this->tables_exist() ) {
            $this->install_tables();
            if ( ! $this->tables_exist() ) {
                $out['reason'] = 'no_tables';
                return $out;
            }
        }

        $out['remaining'] = $this->pending_purge_count();

        if ( 0 === $out['remaining'] ) {
            $out['reason'] = 'empty';
            return $out;
        }

        if ( ! $this->acquire_lock() ) {
            $out['reason'] = 'locked';
            return $out;
        }

        $started = microtime( true );

        try {
            if ( ! $this->refs_ready() ) {
                $out['reason']  = 'refs_incomplete';
                $out['elapsed'] = round( microtime( true ) - $started, 3 );
                return $out;
            }

            $open = $this->open_rewrite_count();

            if ( $open > 0 ) {
                $out['reason']      = 'rewrite_pending';
                $out['openRewrite'] = $open;
                $out['elapsed']     = round( microtime( true ) - $started, 3 );
                return $out;
            }

            $budget = isset( $args['budget'] ) ? (float) $args['budget'] : self::AJAX_BUDGET;
            if ( $budget <= 0 ) {
                $budget = self::AJAX_BUDGET;
            }

            $deadline = microtime( true ) + $budget;

            $rows = $this->pending_purge_rows( self::PURGE_PAGE );

            foreach ( $rows as $row ) {
                $attachment_id = (int) $row['post_id'];
                $payload        = $this->decode_pending_purge( $row['meta_value'] );

                $out['scanned']++;

                if ( empty( $payload['files'] ) ) {
                    // A record with no files is a record of nothing. Clearing it
                    // keeps the counter honest and the next page from re-reading it.
                    if ( ! $out['dry_run'] ) {
                        $this->clear_pending_purge( $attachment_id );
                    }

                    continue;
                }

                // Re-checked per row, not once per call. The index can be
                // invalidated by a restart between two rows of the same page,
                // and the whole point is that it never acts on a stale answer.
                if ( ! $this->refs_ready() ) {
                    $out['reason'] = 'refs_incomplete';
                    break;
                }

                $referenced = $this->family_is_referenced( $payload['files'], $attachment_id );

                if ( null === $referenced ) {
                    $out['reason'] = 'refs_incomplete';
                    break;
                }

                if ( $referenced ) {
                    $out['waiting']++;

                    if ( ! $out['dry_run'] ) {
                        $this->write_pending_purge(
                            $attachment_id,
                            $payload['files'],
                            self::PURGE_WAITING,
                            self::PURGE_REASON_REFERENCED
                        );
                    }

                    if ( microtime( true ) >= $deadline ) {
                        break;
                    }

                    continue;
                }

                if ( $out['dry_run'] ) {
                    $out['purged']++;
                    $out['files'] += count( $payload['files'] );

                    if ( microtime( true ) >= $deadline ) {
                        break;
                    }

                    continue;
                }

                $removed = $this->remove_files( $payload['files'] );

                // The log row is written for what went, not for what was
                // attempted: a purge cannot be undone, so the audit trail has to
                // be true or it is worse than nothing.
                $this->write_purge_log( $attachment_id, $payload['files'], $removed );

                $this->clear_pending_purge( $attachment_id );

                if ( $removed > 0 ) {
                    $out['purged']++;
                    $out['files'] += $removed;
                } else {
                    // Nothing left on disk under those names. The record is
                    // still cleared, otherwise it would be re-examined forever.
                    $out['skipped']++;
                }

                if ( microtime( true ) >= $deadline ) {
                    break;
                }
            }

            if ( '' === $out['reason'] ) {
                $out['reason'] = ( count( $rows ) >= self::PURGE_PAGE ) ? 'budget' : 'drained';
            }

            $out['remaining'] = $this->pending_purge_count();

            if ( $out['remaining'] > 0 && 'budget' === $out['reason'] && ! $out['dry_run'] ) {
                $this->arm_purge_work();
            }
        } finally {
            $this->release_lock();
        }

        $out['elapsed'] = round( microtime( true ) - $started, 3 );

        return $out;
    }

    /**
     * A page of attachments holding a pending purge, oldest row first.
     *
     * @param int $limit Maximum rows.
     * @return array
     */
    private function pending_purge_rows( $limit ) {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                "SELECT m.post_id, m.meta_value
                 FROM {$wpdb->postmeta} m
                 WHERE m.meta_key = %s
                 ORDER BY m.meta_id ASC
                 LIMIT %d",
                self::META_PENDING_PURGE,
                (int) $limit
            ),
            ARRAY_A
        );
    }

    /**
     * How many attachments still hold a pending purge, in either state.
     *
     * @return int
     */
    private function pending_purge_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::META_PENDING_PURGE
            )
        );
    }

    /**
     * How many pending purges are waiting because something still points at them.
     *
     * @return int
     */
    private function waiting_purge_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
                self::META_PURGE_STATE,
                self::PURGE_WAITING
            )
        );
    }

    /**
     * Rewrite entries that are pending or were abandoned mid-flight.
     *
     * @return int
     */
    private function open_rewrite_count() {
        global $wpdb;

        $queue = $this->queue_table();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
                "SELECT COUNT(*) FROM {$queue}
                 WHERE task = %s AND status IN (%s, %s)",
                self::TASK_REWRITE,
                self::STATUS_PENDING,
                self::STATUS_PROCESSING
            )
        );
    }

    /**
     * Reads a stored pending purge, tolerating anything that is not one.
     *
     * @param mixed $value Raw meta_value.
     * @return array
     */
    private function decode_pending_purge( $value ) {
        $payload = maybe_unserialize( $value );

        if ( ! is_array( $payload ) || empty( $payload['files'] ) || ! is_array( $payload['files'] ) ) {
            return array(
                'files'  => array(),
                'state'  => self::PURGE_PENDING,
                'reason' => '',
            );
        }

        $files = array();

        foreach ( $payload['files'] as $relative ) {
            $relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );

            if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
                continue;
            }

            $files[ $relative ] = true;
        }

        return array(
            'files'  => array_keys( $files ),
            'state'  => isset( $payload['state'] ) ? (string) $payload['state'] : self::PURGE_PENDING,
            'reason' => isset( $payload['reason'] ) ? (string) $payload['reason'] : '',
        );
    }

    /**
     * Forgets a pending purge completely.
     *
     * @param int $attachment_id Attachment post ID.
     */
    private function clear_pending_purge( $attachment_id ) {
        delete_post_meta( (int) $attachment_id, self::META_PENDING_PURGE );
        delete_post_meta( (int) $attachment_id, self::META_PURGE_STATE );
    }

    /**
     * Records a completed deletion in the log.
     *
     * queue_id is 0 because a purge has no queue entry: there is nothing to
     * undo. That also keeps purge_log(), which joins the log to the queue, from
     * ever sweeping these rows away, which is the correct outcome for an
     * irreversible action's audit trail.
     *
     * @param int   $attachment_id Attachment post ID.
     * @param array $files         Files the purge was meant to remove.
     * @param int   $removed       Files actually removed.
     */
    private function write_purge_log( $attachment_id, array $files, $removed ) {
        $list = implode( ',', $files );

        $this->write_log(
            array(
                'queue_id'     => 0,
                'task'         => self::TASK_PURGE,
                'object_type'  => 'attachment',
                'object_id'    => (int) $attachment_id,
                'secondary'    => '',
                'old_hash'     => sha1( $list ),
                'old_value'    => $list,
                'replacements' => (int) $removed,
            )
        );
    }

    /**
     * Counters for the pending-purge card.
     *
     * @return array
     */
    public function get_purge_state() {
        $pending = $this->pending_purge_count();
        $waiting = $this->waiting_purge_count();

        return array(
            'pending'     => $pending,
            'waiting'     => $waiting,
            // Nothing to do is not the same as blocked. A card that says "cannot
            // verify" when there is nothing waiting would train the admin to
            // ignore the warning that matters.
            'ready'       => ( $pending > 0 ) ? $this->refs_ready() : true,
            'blocker'     => $this->refs_blocker(),
            'openRewrite' => $this->tables_exist() ? $this->open_rewrite_count() : 0,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Purge                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Deletes the history rows whose entry finished, permanently.
     *
     * This is the one operation here that cannot be undone, so it refuses to run
     * without an explicit confirmation and the interface asks twice.
     *
     * @param bool   $confirm Must be true. Anything else changes nothing.
     * @param int    $limit   Maximum rows to remove in this call.
     * @return array|WP_Error
     */
    public function purge_log( $confirm, $limit = self::PURGE_BATCH ) {
        global $wpdb;

        if ( empty( $confirm ) ) {
            return new WP_Error(
                'wps_queue_purge_unconfirmed',
                __( 'Hay que confirmar el borrado del historial.', 'webp-studio' )
            );
        }

        if ( ! $this->tables_exist() ) {
            return new WP_Error( 'wps_queue_no_tables', __( 'Las tablas de la cola no existen.', 'webp-studio' ) );
        }

        $limit  = max( 1, (int) $limit );
        $queue  = $this->queue_table();
        $log    = $this->log_table();

        // Only finished entries. A failed or skipped entry still has a stored
        // value that somebody might need.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
                "SELECT l.id
                 FROM {$log} l
                 INNER JOIN {$queue} q ON q.id = l.queue_id
                 WHERE l.rolled_back_at IS NULL AND q.status = %s
                 ORDER BY l.id ASC
                 LIMIT %d",
                self::STATUS_DONE,
                $limit
            )
        );

        if ( empty( $ids ) ) {
            return array(
                'deleted'   => 0,
                'remaining' => $this->count_purgeable_log( $queue, $log ),
            );
        }

        $in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $args  = array_merge( array( self::STATUS_DONE ), $ids );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE l FROM {$log} l
                 INNER JOIN {$queue} q ON q.id = l.queue_id
                 WHERE l.rolled_back_at IS NULL
                   AND q.status = %s
                   AND l.id IN ({$in})",
                $args
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array(
            'deleted'   => ( false === $deleted ) ? 0 : (int) $deleted,
            'remaining' => $this->count_purgeable_log( $queue, $log ),
        );
    }

    /**
     * Counts the log rows purge_log() would still remove.
     *
     * @param string $queue Queue table.
     * @param string $log   Log table.
     * @return int
     */
    private function count_purgeable_log( $queue, $log ) {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
                "SELECT COUNT(*)
                 FROM {$log} l
                 INNER JOIN {$queue} q ON q.id = l.queue_id
                 WHERE l.rolled_back_at IS NULL AND q.status = %s",
                self::STATUS_DONE
            )
        );
    }

    /**
     * Removes every table. Used by uninstall.php.
     */
    public static function drop_tables() {
        global $wpdb;

        $queue = $wpdb->prefix . self::QUEUE_TABLE_SUFFIX;
        $log   = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
        $refs  = $wpdb->prefix . self::REFS_TABLE_SUFFIX;

        $wpdb->query( "DROP TABLE IF EXISTS {$queue}" ); // phpcs:ignore WordPress.DB
        $wpdb->query( "DROP TABLE IF EXISTS {$log}" ); // phpcs:ignore WordPress.DB
        $wpdb->query( "DROP TABLE IF EXISTS {$refs}" ); // phpcs:ignore WordPress.DB

        delete_option( 'wp_image_webp_converter_queue_schema' );
        delete_option( 'wp_image_webp_converter_queue_cursor' );
        delete_option( 'wp_image_webp_converter_queue_scan' );
        delete_option( 'wp_image_webp_converter_queue_working' );
        delete_option( 'wp_image_webp_converter_queue_enqueued' );
        delete_option( 'wp_image_webp_converter_queue_updated_at' );
        delete_option( 'wp_image_webp_converter_queue_replacements' );
    }
}
