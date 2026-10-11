<?php
/**
 * WP-CLI surface for WebP Studio.
 *
 * @package WebP_Studio
 */

/**
 * Shared helpers for the WP-CLI command classes.
 *
 * Each command group is its own class so WP-CLI can nest it properly. A single
 * class registered under one name exposes flat hyphenated subcommands instead:
 * queue_purge_log becomes queue-purge-log. Registering a class under a parent
 * name is what produces the tree an operator expects.
 *
 * @package WebP_Studio
 */
abstract class WP_Image_WebP_Converter_CLI_Base {

	/** @var WP_Image_WebP_Converter */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param WP_Image_WebP_Converter $plugin Plugin instance.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

    /**
     * Reads a boolean flag.
     *
     * WP-CLI hands a valueless flag over as boolean true and a valued one as a
     * string, so both shapes have to be understood. An empty string means the
     * operator wrote `--flag=` with nothing after it, which is not the same as
     * asking for the flag.
     *
     * @param array  $assoc_args Associative arguments as given by WP-CLI.
     * @param string $flag       Flag name.
     * @param bool   $default    Value when the flag is absent.
     * @return bool
     */
    protected function flag( $assoc_args, $flag, $default = false ) {
        if ( ! array_key_exists( $flag, $assoc_args ) ) {
            return $default;
        }

        $value = $assoc_args[ $flag ];

        if ( is_bool( $value ) ) {
            return $value;
        }

        return '' !== $value && '0' !== $value && 'false' !== strtolower( (string) $value );
    }

    /**
     * Reads an integer flag, falling back when absent or not a number.
     *
     * @param array  $assoc_args Associative arguments.
     * @param string $flag       Flag name.
     * @param int    $default    Value when the flag is absent.
     * @return int
     */
    protected function int_flag( $assoc_args, $flag, $default ) {
        if ( ! isset( $assoc_args[ $flag ] ) || '' === $assoc_args[ $flag ] ) {
            return $default;
        }

        return (int) $assoc_args[ $flag ];
    }

    /**
     * Reads a float flag, falling back when absent or not a number.
     *
     * @param array  $assoc_args Associative arguments.
     * @param string $flag       Flag name.
     * @param float  $default    Value when the flag is absent.
     * @return float
     */
    protected function float_flag( $assoc_args, $flag, $default ) {
        if ( ! isset( $assoc_args[ $flag ] ) || '' === $assoc_args[ $flag ] ) {
            return $default;
        }

        return (float) $assoc_args[ $flag ];
    }

    /**
     * Reads a comma-separated list flag as an array of trimmed strings.
     *
     * @param array  $assoc_args Associative arguments.
     * @param string $flag       Flag name.
     * @return array
     */
    protected function csv_flag( $assoc_args, $flag ) {
        if ( ! isset( $assoc_args[ $flag ] ) || '' === $assoc_args[ $flag ] ) {
            return array();
        }

        $parts = array_map( 'trim', explode( ',', (string) $assoc_args[ $flag ] ) );

        return array_values( array_filter( $parts, 'strlen' ) );
    }

    /**
     * Resolves the output format, defaulting to table.
     *
     * @param array $assoc_args Associative arguments.
     * @return string
     */
    protected function format( $assoc_args ) {
        $format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        return in_array( $format, array( 'table', 'json', 'csv', 'yaml', 'count' ), true ) ? $format : 'table';
    }

    /**
     * Prints a flat key/value report.
     *
     * @param array $data    Key/value pairs.
     * @param array $fields  Fields to show, in order.
     * @param array $assoc_args Associative arguments.
     */
    protected function render( array $data, array $fields, $assoc_args ) {
        $format = $this->format( $assoc_args );

        if ( 'count' === $format ) {
            WP_CLI::line( '1' );
            return;
        }

        WP_CLI\Utils\format_items( $format, array( $data ), $fields );
    }

    /**
     * Reports the `reason` key an array-shaped result carries.
     *
     * Every queue method returns a `reason` describing why it returned, including
     * the honest refusals: 'refs_incomplete' means the reference index does not
     * cover the library yet, which is emphatically not the same as "nothing to do".
     * Printing only the counters would turn a refusal into a silent zero.
     *
     * @param string $reason Reason code.
     * @return bool True when the reason describes a refusal rather than normal work.
     */
    protected function reason_is_refusal( $reason ) {
        return in_array(
            $reason,
            array( 'context', 'no_tables', 'locked', 'refs_incomplete', 'rewrite_pending' ),
            true
        );
    }

    /**
     * Turns a queue WP_Error into a CLI error with a non-zero exit code.
     *
     * @param WP_Error $error   Error to report.
     * @param string   $context Command that produced it.
     */
    protected function fail( $error, $context ) {
        WP_CLI::error(
            sprintf(
                /* translators: 1: command name, 2: error code, 3: error message. */
                __( 'wp webp-studio %1$s: [%2$s] %3$s', 'webp-studio' ),
                $context,
                $error->get_error_code(),
                $error->get_error_message()
            )
        );
    }

    /**
     * Turns a result array into success or warning, reporting the reason.
     *
     * @param array  $result  Result array carrying counters and a reason.
     * @param string $message Success message when the work actually happened.
     */
    protected function report( array $result, $message ) {
        if ( ! empty( $result['reason'] ) && $this->reason_is_refusal( $result['reason'] ) ) {
            WP_CLI::warning(
                sprintf(
                    /* translators: 1: reason code, 2: detail message. */
                    __( 'Motivo de la interrupción: %1$s. %2$s', 'webp-studio' ),
                    $result['reason'],
                    $message
                )
            );
            return;
        }

        if ( ! empty( $result['reason'] ) && 'budget' === $result['reason'] ) {
            WP_CLI::log( $message );
            WP_CLI::warning(
                __( 'Se agotó el presupuesto de tiempo. Vuelve a ejecutarlo para continuar.', 'webp-studio' )
            );
            return;
        }

        WP_CLI::success( $message );
    }

}

/**
 * Inspects and drives the WebP conversion.
 *
 * @package WebP_Studio
 */
class WP_Image_WebP_Converter_CLI extends WP_Image_WebP_Converter_CLI_Base {
    /**
     * Shows the current conversion status and queue progress.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp webp-studio status
     *     wp webp-studio status --format=json
     *
     * @subcommand status
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function status( $args, $assoc_args ) {
        $payload = $this->plugin->build_status_payload();
        $queue   = $this->plugin->queue()->get_progress();

        $data = array(
            'state'         => $payload['state'],
            'running'       => $payload['running'],
            'message'       => $payload['message'],
            'coverage'      => $payload['coverage'],
            'total'         => $payload['total'],
            'converted'     => $payload['converted'],
            'pending'       => $payload['pending'],
            'processed'     => $payload['processed'],
            'skipped'       => $payload['skipped'],
            'queuePending'  => $queue['counts']['pending'],
            'queueDone'     => $queue['counts']['done'],
            'queueFailed'   => $queue['counts']['failed'],
            'queueSkipped'  => $queue['counts']['skipped'],
            'refsReady'     => $queue['refs']['ready'],
            'tablesReady'   => $queue['tablesReady'],
            'updated'       => $payload['updated'],
        );

        $fields = array_keys( $data );

        if ( 'json' === $this->format( $assoc_args ) ) {
            WP_CLI::line( (string) wp_json_encode( $data ) );
            return;
        }

        $this->render( $data, $fields, $assoc_args );

        if ( ! empty( $payload['skips'] ) ) {
            WP_CLI::log( __( 'Motivos de omisión de la última ejecución:', 'webp-studio' ) );
            foreach ( (array) $payload['skips'] as $reason => $count ) {
                WP_CLI::log( sprintf( '  - %s: %d', $reason, (int) $count ) );
            }
        }
    }

    /**
     * Shows image library statistics.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - count
     * ---
     *
     * @subcommand statistics
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function statistics( $args, $assoc_args ) {
        $stats = $this->plugin->get_statistics();

        $data = array(
            'total'     => (int) $stats['total'],
            'converted' => (int) $stats['converted'],
            'pending'   => (int) $stats['pending'],
        );

        $this->render( $data, array( 'total', 'converted', 'pending' ), $assoc_args );
    }

    /**
     * Shows the activity history, newest first.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : How many entries to show.
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - count
     * ---
     *
     * [--count]
     * : Only display the total number of entries.
     *
     * ## EXAMPLES
     *
     *     wp webp-studio history --limit=10
     *
     * @subcommand history
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function history( $args, $assoc_args ) {
        $limit   = max( 1, $this->int_flag( $assoc_args, 'limit', 50 ) );
        $entries = $this->plugin->get_history_entries( $limit );

        $format = $this->format( $assoc_args );

        if ( 'count' === $format || $this->flag( $assoc_args, 'count' ) ) {
            WP_CLI::line( (string) count( $entries ) );
            return;
        }

        $rows = array();
        foreach ( $entries as $entry ) {
            $context = isset( $entry['context'] ) ? (string) wp_json_encode( $entry['context'] ) : '';

            $rows[] = array(
                'time'    => isset( $entry['time'] ) ? (string) $entry['time'] : '',
                'level'   => isset( $entry['level'] ) ? (string) $entry['level'] : '',
                'message' => isset( $entry['message'] ) ? (string) $entry['message'] : '',
                'context' => $context,
            );
        }

        if ( 'json' === $format ) {
            WP_CLI::line( (string) wp_json_encode( $rows ) );
            return;
        }

        WP_CLI\Utils\format_items( $format, $rows, array( 'time', 'level', 'message', 'context' ) );
    }

    /**
     * Reports whether the installation can convert and purge at all.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - count
     * ---
     *
     * @subcommand doctor
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function doctor( $args, $assoc_args ) {
        $queue           = $this->plugin->queue();
        $settings_problem = $this->plugin->settings_problem();
        $webp            = $this->plugin->editor_supports_webp();
        $tables          = $queue->tables_exist();

        $uploads = wp_upload_dir();
        $writable = empty( $uploads['error'] ) && wp_is_writable( $uploads['basedir'] );

        $refs       = $queue->refs_ready();
        $blocker    = $queue->refs_blocker();

        $checks = array(
            array(
                'check'  => 'webp_support',
                'ok'     => $webp,
                'detail' => $webp
                    ? __( 'El servidor puede generar WebP.', 'webp-studio' )
                    : __( 'Ni GD ni Imagick pueden generar WebP: no se puede convertir nada.', 'webp-studio' ),
            ),
            array(
                'check'  => 'settings',
                'ok'     => ( null === $settings_problem ),
                'detail' => ( null === $settings_problem )
                    ? __( 'Los ajustes son legibles.', 'webp-studio' )
                    : $settings_problem,
            ),
            array(
                'check'  => 'uploads_writable',
                'ok'     => $writable,
                'detail' => $writable
                    ? __( 'La carpeta de subidas tiene permisos de escritura.', 'webp-studio' )
                    : __( 'La carpeta de subidas NO tiene permisos de escritura: los originales no se podrán eliminar.', 'webp-studio' ),
            ),
            array(
                'check'  => 'queue_tables',
                'ok'     => $tables,
                'detail' => $tables
                    ? __( 'Las tablas de la cola existen.', 'webp-studio' )
                    : __( 'Las tablas de la cola no existen. Ejecuta "wp webp-studio queue install".', 'webp-studio' ),
            ),
            array(
                'check'  => 'reference_index',
                'ok'     => $refs,
                'detail' => $refs
                    ? __( 'El índice de referencias está completo.', 'webp-studio' )
                    : sprintf(
                        /* translators: %s: blocker code. */
                        __( 'El índice de referencias no está completo (%s): el borrado verificado no podrá demostrar nada todavía.', 'webp-studio' ),
                        $blocker
                    ),
            ),
        );

        $format = $this->format( $assoc_args );

        if ( 'json' === $format ) {
            WP_CLI::line( (string) wp_json_encode( $checks ) );
        } else {
            WP_CLI\Utils\format_items( $format, $checks, array( 'check', 'ok', 'detail' ) );
        }

        $failed = 0;
        foreach ( $checks as $check ) {
            if ( empty( $check['ok'] ) ) {
                $failed++;
            }
        }

        if ( $failed > 0 ) {
            WP_CLI::error(
                sprintf(
                    /* translators: %d: number of failed checks. */
                    _n( '%d comprobación falló.', '%d comprobaciones fallaron.', $failed, 'webp-studio' ),
                    $failed
                )
            );
        }

        WP_CLI::success( __( 'Todo correcto: la instalación puede convertir y purgar.', 'webp-studio' ) );
    }

    /**
     * Deletes old-format uploads that no attachment references. Irreversible.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would be deleted and delete nothing.
     *
     * [--yes]
     * : Answer yes to all prompts.
     *
     * ## EXAMPLES
     *
     *     wp webp-studio cleanup-orphans --dry-run
     *     wp webp-studio cleanup-orphans --yes
     *
     * @subcommand cleanup-orphans
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function cleanup_orphans( $args, $assoc_args ) {
        $dry_run = $this->flag( $assoc_args, 'dry-run' );

        if ( ! $dry_run ) {
            WP_CLI::confirm(
                __( 'Se eliminarán archivos huérfanos de la carpeta de subidas de forma permanente.', 'webp-studio' ),
                $assoc_args
            );
        }

        // dry_run is handed to the method rather than simulated here: the scan is
        // what identifies the files, so a CLI-side simulation could only report
        // files the method never looked at.
        $result = $this->plugin->cleanup_orphans( $dry_run );

        WP_CLI::success(
            $dry_run
                ? sprintf(
                    /* translators: %d: number of files. */
                    __( '%d archivos huérfanos serían eliminados. No se ha borrado nada.', 'webp-studio' ),
                    (int) $result['removed']
                )
                : sprintf(
                    /* translators: %d: number of files. */
                    __( '%d archivos huérfanos eliminados.', 'webp-studio' ),
                    (int) $result['removed']
                )
        );

        if ( ! empty( $result['files'] ) ) {
            WP_CLI::log( __( 'Muestra:', 'webp-studio' ) );
            foreach ( (array) $result['files'] as $file ) {
                WP_CLI::log( '  - ' . $file );
            }
        }
    }
}

/**
 * Reads or writes a single plugin setting.
 *
 * @package WebP_Studio
 */
class WP_Image_WebP_Converter_CLI_Config extends WP_Image_WebP_Converter_CLI_Base {
    /**
     * Reads one setting, or all of them.
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function get( $args, $assoc_args ) {
        $options = $this->plugin->get_options();
        $key     = isset( $args[0] ) ? (string) $args[0] : '';

        if ( '' !== $key ) {
            if ( ! array_key_exists( $key, $options ) ) {
                WP_CLI::error(
                    sprintf(
                        /* translators: 1: setting name, 2: list of valid names. */
                        __( 'Ajuste desconocido: %1$s. Los válidos son: %2$s.', 'webp-studio' ),
                        $key,
                        implode( ', ', array_keys( $options ) )
                    )
                );
            }

            $value = $options[ $key ];

            if ( 'json' === $this->format( $assoc_args ) ) {
                WP_CLI::line( (string) wp_json_encode( $value ) );
            } else {
                WP_CLI::line( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
            }
            return;
        }

        $rows = array();
        foreach ( $options as $name => $value ) {
            $rows[] = array(
                'key'   => (string) $name,
                'value' => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ),
            );
        }

        if ( 'json' === $this->format( $assoc_args ) ) {
            WP_CLI::line( (string) wp_json_encode( $rows ) );
            return;
        }

        WP_CLI\Utils\format_items( $this->format( $assoc_args ), $rows, array( 'key', 'value' ) );
    }

    /**
     * Writes one setting.
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function set( $args, $assoc_args ) {
        $key   = isset( $args[0] ) ? (string) $args[0] : '';
        $value = isset( $args[1] ) ? (string) $args[1] : '';

        if ( '' === $key || '' === $value ) {
            WP_CLI::error( __( 'Falta la clave o el valor. Usa "wp webp-studio config set <clave> <valor>".', 'webp-studio' ) );
        }

        $current = $this->plugin->get_options();

        if ( ! array_key_exists( $key, $current ) ) {
            WP_CLI::error(
                sprintf(
                    /* translators: 1: setting name, 2: list of valid names. */
                    __( 'Ajuste desconocido: %1$s. Los válidos son: %2$s.', 'webp-studio' ),
                    $key,
                    implode( ', ', array_keys( $current ) )
                )
            );
        }

        // Coerce to the type the stored value already has, so sanitize_options()
        // receives a boolean rather than the string "0", which is truthy.
        $existing = $current[ $key ];
        if ( is_bool( $existing ) ) {
            $value = ( '1' === $value || 'true' === strtolower( $value ) || 'yes' === strtolower( $value ) ) ? 1 : 0;
        } elseif ( is_int( $existing ) ) {
            $value = (int) $value;
        }

        $merged = array_merge( $current, array( $key => $value ) );

        $sanitized = $this->plugin->sanitize_options( $merged );

        update_option( WP_Image_WebP_Converter::OPTION_NAME, $sanitized );

        WP_CLI::success(
            sprintf(
                /* translators: 1: setting name, 2: new stored value. */
                __( 'Ajuste actualizado: %1$s = %2$s', 'webp-studio' ),
                $key,
                (string) $sanitized[ $key ]
            )
        );
    }
}

/**
 * Manages the URL rewrite queue.
 *
 * @package WebP_Studio
 */
class WP_Image_WebP_Converter_CLI_Queue extends WP_Image_WebP_Converter_CLI_Base {
    /**
     * Creates or reconciles the queue tables.
     *
     * Idempotent: it runs dbDelta, so it repairs an existing install rather than
     * failing on it.
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function install( $args, $assoc_args ) {
        $ok = $this->plugin->queue()->install_tables();

        if ( ! $ok ) {
            WP_CLI::error( __( 'No se pudieron crear las tablas de la cola.', 'webp-studio' ) );
        }

        WP_CLI::success( __( 'Tablas de la cola creadas o actualizadas.', 'webp-studio' ) );
    }

    /**
     * Scans the database and enqueues references to rewrite.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Count what matches and write nothing at all.
     *
     * [--budget=<seconds>]
     * : Time budget for this call.
     * ---
     * default: 300
     * ---
     *
     * [--meta-keys=<keys>]
     * : Comma-separated postmeta keys to queue for rewriting. Empty means
     * postmeta is not queued at all, which is the safe default on a large site.
     *
     * [--include-revisions]
     * : Also queue revisions. Off by default.
     *
     * [--no-include-options]
     * : Do not queue wp_options rows. On by default.
     *
     * [--restart]
     * : Start the scan from the beginning, discarding the stored cursors.
     *
     * @subcommand scan
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function scan( $args, $assoc_args ) {
        $result = $this->plugin->queue()->enqueue_from_scan(
            array(
                'dry_run'           => $this->flag( $assoc_args, 'dry-run' ),
                'budget'            => $this->float_flag( $assoc_args, 'budget', self::CLI_BUDGET ),
                'meta_keys'         => $this->csv_flag( $assoc_args, 'meta-keys' ),
                'include_revisions' => $this->flag( $assoc_args, 'include-revisions' ),
                // Inverted on purpose: the queue defaults this to on, and WP-CLI
                // expresses "off" as --no-include-options.
                'include_options'   => ! $this->flag( $assoc_args, 'no-include-options' ),
                'restart'           => $this->flag( $assoc_args, 'restart' ),
            )
        );

        $message = $result['dry_run']
            ? sprintf(
                /* translators: 1: entries reviewed, 2: entries that would be queued. */
                __( 'Simulación: %1$d entradas revisadas, %2$d se encolarían. No se ha escrito nada.', 'webp-studio' ),
                (int) $result['scanned'],
                (int) $result['queued']
            )
            : sprintf(
                /* translators: 1: entries reviewed, 2: entries queued, 3: duplicates. */
                __( 'Encolado: %1$d entradas revisadas, %2$d en la cola, %3$d ya estaban.', 'webp-studio' ),
                (int) $result['scanned'],
                (int) $result['queued'],
                (int) $result['duplicates']
            );

        $this->report( $result, $message );

        if ( empty( $result['finished'] ) && ! $this->reason_is_refusal( (string) $result['reason'] ) ) {
            WP_CLI::warning( __( 'El escaneo no ha terminado. Vuelve a ejecutarlo hasta que lo indique.', 'webp-studio' ) );
        }
    }

    /**
     * Rewrites pending queue entries until the budget is spent.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would change and write nothing at all.
     *
     * [--budget=<seconds>]
     * : Time budget for this call.
     * ---
     * default: 300
     * ---
     *
     * @subcommand process
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function process( $args, $assoc_args ) {
        $result = $this->plugin->queue()->process(
            array(
                'dry_run' => $this->flag( $assoc_args, 'dry-run' ),
                'budget'  => $this->float_flag( $assoc_args, 'budget', self::CLI_BUDGET ),
            )
        );

        $message = $result['dry_run']
            ? sprintf(
                /* translators: 1: entries examined, 2: that would be rewritten. */
                __( 'Simulación: %1$d entradas examinadas, %2$d se reescribirían. No se ha escrito nada.', 'webp-studio' ),
                (int) $result['processed'],
                (int) $result['rewritten']
            )
            : sprintf(
                /* translators: 1: entries examined, 2: rewritten, 3: skipped, 4: failed. */
                __( 'Procesadas %1$d entradas: %2$d reescritas, %3$d omitidas, %4$d con error.', 'webp-studio' ),
                (int) $result['processed'],
                (int) $result['rewritten'],
                (int) $result['skipped'],
                (int) $result['failed']
            );

        $this->report( $result, $message );
    }

    /**
     * Restores the value stored for one queue entry.
     *
     * ## OPTIONS
     *
     * [<id>]
     * : Queue entry to restore. Omit to undo the most recent change.
     *
     * [--yes]
     * : Answer yes to all prompts.
     *
     * @subcommand rollback
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function rollback( $args, $assoc_args ) {
        $id = isset( $args[0] ) && '' !== $args[0] ? (int) $args[0] : 0;

        $target = $id > 0
            ? sprintf(
                /* translators: %d: queue entry id. */
                __( 'la entrada %d de la cola', 'webp-studio' ),
                $id
            )
            : __( 'la última entrada reescrita', 'webp-studio' );

        WP_CLI::confirm( (string) $target, $assoc_args );

        $result = $this->plugin->queue()->rollback( $id );

        if ( is_wp_error( $result ) ) {
            $this->fail( $result, 'queue rollback' );
        }

        WP_CLI::success(
            sprintf(
                /* translators: 1: queue entry id, 2: object id, 3: restored bytes. */
                __( 'Restaurada la entrada %1$d del objeto %2$d (%3$d bytes).', 'webp-studio' ),
                (int) $result['queueId'],
                (int) $result['objectId'],
                (int) $result['restoredBytes']
            )
        );
    }

    /**
     * Deletes queue history rows for finished entries. Irreversible.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Maximum rows to delete in this call.
     * ---
     * default: 500
     * ---
     *
     * [--yes]
     * : Answer yes to all prompts.
     *
     * @subcommand purge-log
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function purge_log( $args, $assoc_args ) {
        $limit = max( 1, $this->int_flag( $assoc_args, 'limit', WP_Image_WebP_Converter_Queue::PURGE_BATCH ) );

        WP_CLI::confirm(
            sprintf(
                /* translators: %d: maximum rows. */
                __( 'Se borrarán hasta %d entradas del historial, sin posibilidad de deshacerlo.', 'webp-studio' ),
                $limit
            ),
            $assoc_args
        );

        // Passed as true because the operator has just confirmed: purge_log()
        // refuses to do anything otherwise, and that refusal must stay meaningful
        // for the web layer.
        $result = $this->plugin->queue()->purge_log( true, $limit );

        if ( is_wp_error( $result ) ) {
            $this->fail( $result, 'queue purge-log' );
        }

        WP_CLI::success(
            sprintf(
                /* translators: 1: deleted rows, 2: rows still remaining. */
                __( 'Borradas %1$d entradas del historial. Quedan %2$d.', 'webp-studio' ),
                (int) $result['deleted'],
                (int) $result['remaining']
            )
        );
    }

    /**
     * Deletes originals proven unreferenced. Irreversible.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would be deleted and delete nothing.
     *
     * [--budget=<seconds>]
     * : Time budget for this call.
     * ---
     * default: 300
     * ---
     *
     * [--yes]
     * : Answer yes to all prompts.
     *
     * @subcommand purge-originals
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function purge_originals( $args, $assoc_args ) {
        $dry_run = $this->flag( $assoc_args, 'dry-run' );

        if ( ! $dry_run ) {
            WP_CLI::confirm(
                __( 'Se eliminarán originales del disco de forma permanente. No hay forma de recuperarlos.', 'webp-studio' ),
                $assoc_args
            );
        }

        $result = $this->plugin->queue()->purge_verified(
            array(
                'dry_run' => $dry_run,
                'budget'  => $this->float_flag( $assoc_args, 'budget', self::CLI_BUDGET ),
            )
        );

        $message = $result['dry_run']
            ? sprintf(
                /* translators: 1: attachments examined, 2: files that would go, 3: kept because referenced. */
                __( 'Simulación: %1$d revisados, %2$d archivos se borrarían y %3$d se guardarían porque algo los sigue usando. No se ha escrito nada.', 'webp-studio' ),
                (int) $result['scanned'],
                (int) $result['files'],
                (int) $result['waiting']
            )
            : sprintf(
                /* translators: 1: attachments examined, 2: deleted, 3: kept because referenced. */
                __( 'Verificados %1$d originales: %2$d eliminados y %3$d conservados porque algún contenido los sigue usando.', 'webp-studio' ),
                (int) $result['scanned'],
                (int) $result['purged'],
                (int) $result['waiting']
            );

        // The refusals matter more than the counters here: refs_incomplete and
        // rewrite_pending mean nothing was deleted on purpose, and reporting them
        // as "0 purged" would read as a successful no-op.
        $this->report( $result, $message );
    }

    /**
     * Drops every queue table. Irreversible.
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Answer yes to all prompts.
     *
     * @subcommand drop-tables
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function drop_tables( $args, $assoc_args ) {
        WP_CLI::confirm(
            __( 'Se eliminarán TODAS las tablas de la cola, con todo el historial y el índice de referencias. No hay forma de recuperarlos.', 'webp-studio' ),
            $assoc_args
        );

        WP_Image_WebP_Converter_Queue::drop_tables();

        WP_CLI::success( __( 'Tablas de la cola eliminadas.', 'webp-studio' ) );
    }
}

/**
 * Starts or advances the background conversion run.
 *
 * @package WebP_Studio
 */
class WP_Image_WebP_Converter_CLI_Run extends WP_Image_WebP_Converter_CLI_Base {
    /**
     * Starts a background run over the whole library.
     *
     * Schedules the work and arms WP-Cron. It does not convert anything itself.
     *
     * @subcommand start
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function start( $args, $assoc_args ) {
        $this->plugin->start_run();

        $payload = $this->plugin->build_status_payload();

        WP_CLI::success(
            sprintf(
                /* translators: %d: number of pending attachments. */
                __( 'Ejecución iniciada: %d imágenes pendientes.', 'webp-studio' ),
                (int) $payload['pending']
            )
        );

        if ( (int) $payload['pending'] === 0 ) {
            WP_CLI::warning( __( 'No queda ninguna imagen por convertir.', 'webp-studio' ) );
        }
    }

    /**
     * Converts one bounded batch.
     *
     * ## OPTIONS
     *
     * [--limit=<n>]
     * : Maximum attachments to convert in this call.
     * ---
     * default: 50
     * ---
     *
     * @subcommand batch
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     */
    public function batch( $args, $assoc_args ) {
        $limit = max( 1, $this->int_flag( $assoc_args, 'limit', self::CLI_BATCH_SIZE ) );

        if ( ! get_option( WP_Image_WebP_Converter::OPTION_ACTIVE, 0 ) ) {
            WP_CLI::warning( __( 'No hay ninguna ejecución activa. Usa "wp webp-studio run start" antes.', 'webp-studio' ) );
            return;
        }

        $converted = $this->plugin->process_batch( $limit );

        WP_CLI::success(
            sprintf(
                /* translators: %d: number of converted attachments. */
                _n( '%d imagen convertida.', '%d imágenes convertidas.', (int) $converted, 'webp-studio' ),
                (int) $converted
            )
        );

        $payload = $this->plugin->build_status_payload();

        if ( ! empty( $payload['running'] ) ) {
            WP_CLI::log( $payload['message'] );
        }
    }
}
