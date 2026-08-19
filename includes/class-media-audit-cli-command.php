<?php
/**
 * WP-CLI command for detecting likely stray uploads.
 *
 * @package GPMediaAudit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audits uploads and reports files without references.
 */
class Media_Audit_CLI_Command {

		/**
		 * WordPress database abstraction.
		 *
		 * @var wpdb
		 */
		private $wpdb;

		/**
		 * Existing table names in current database.
		 *
		 * @var array<string,bool>
		 */
		private $existing_tables = array();

		/**
		 * Constructor.
		 */
	public function __construct() {
		global $wpdb;
		$this->wpdb            = $wpdb;
		$this->existing_tables = $this->load_existing_tables();
	}

		/**
		 * Scan uploads and detect likely stray files.
		 *
		 * ## OPTIONS
		 *
		 * [--uploads-subdir=<prefix>]
		 * : Restrict scan to a sub-path under uploads (e.g. 2025/08).
		 *
		 * [--limit=<number>]
		 * : Max number of candidate files to DB-check. Default: 0 (no limit).
		 *
		 * [--min-size=<kb>]
		 * : Only report candidates at least this many kilobytes.
		 *
		 * [--older-than=<days>]
		 * : Only report candidates modified more than this many days ago.
		 *
		 * [--all-files]
		 * : Include non-media extensions too.
		 *
		 * [--skip-db-check]
		 * : Skip DB text-reference checks and only use attachment metadata.
		 *
		 * [--ignore=<patterns>]
		 * : Comma-separated ignore patterns (glob), e.g. "cache/*,tmp/*.jpg".
		 *
		 * [--ignore-file=<path>]
		 * : Path to newline-delimited ignore patterns file.
		 *
		 * [--custom-tables=<list>]
		 * : Extra table checks, e.g. "wp_plugin_assets:url,wp_x:data".
		 *
		 * [--scan-all-tables]
		 * : Also scan all non-core tables text columns (can be slow).
		 *
		 * [--quarantine]
		 * : Move likely stray files to quarantine.
		 *
		 * [--delete]
		 * : Permanently delete findings. Requires --yes unless --dry-run is used.
		 *
		 * [--backup-delete]
		 * : Copy findings to a verified timestamped backup, then remove the originals.
		 *
		 * [--yes]
		 * : Confirm a permanent --delete operation.
		 *
		 * [--dry-run]
		 * : With --quarantine, show planned moves without changing files.
		 *
		 * [--quarantine-dir=<path>]
		 * : Quarantine base under uploads. Default: .media-audit-quarantine.
		 *
		 * [--format=<format>]
		 * : Render format for result rows: table, csv, json, yaml. Default: table.
		 *
		 * [--summary-only]
		 * : Print the summary without individual finding rows.
		 *
		 * [--fail-on-findings]
		 * : Exit with status 1 when likely stray files are found (useful in CI).
		 *
		 * ## EXAMPLES
		 *
		 *     wp gp media-audit
		 *     wp gp media-audit --uploads-subdir=2024 --format=json
		 *     wp gp media-audit --skip-db-check
		 *     wp gp media-audit --ignore="cache/*,temp/*"
		 *     wp gp media-audit --custom-tables="wp_plugin_assets:url"
		 *     wp gp media-audit --quarantine --dry-run
		 *     wp gp media-audit --older-than=90 --min-size=100 --summary-only
		 *     wp gp media-audit --delete --dry-run
		 *     wp gp media-audit --backup-delete --dry-run
		 *
		 * @subcommand media-audit
		 *
		 * @param array<string,string|bool> $args Positional args (unused).
		 * @param array<string,string|bool> $assoc_args Command options.
		 */
	public function media_audit( $args, $assoc_args ) {
		unset( $args );
		$quarantine       = $this->get_bool_flag( $assoc_args, 'quarantine', false );
		$delete           = $this->get_bool_flag( $assoc_args, 'delete', false );
		$backup_delete    = $this->get_bool_flag( $assoc_args, 'backup-delete', false );
		$dry_run          = $this->get_bool_flag( $assoc_args, 'dry-run', false );
		$summary_only     = $this->get_bool_flag( $assoc_args, 'summary-only', false );
		$fail_on_findings = $this->get_bool_flag( $assoc_args, 'fail-on-findings', false );
		$format           = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( (int) $quarantine + (int) $delete + (int) $backup_delete > 1 ) {
			$this->cli_error( 'Choose only one of --quarantine, --backup-delete, or --delete.' );
		}
		if ( $delete && ! $dry_run && ! $this->get_bool_flag( $assoc_args, 'yes', false ) ) {
			$this->cli_error( 'Permanent deletion requires --yes. Run with --delete --dry-run first.' );
		}
		if ( $backup_delete && ! $dry_run && ! $this->get_bool_flag( $assoc_args, 'yes', false ) ) {
			$this->cli_error( 'Backup and remove requires --yes. Run with --backup-delete --dry-run first.' );
		}

		$this->cli_log( 'Scanning uploads and building the attachment reference index...' );
		$findings = $this->get_audit_results( $assoc_args );
		if ( ! empty( $findings['error'] ) ) {
			$this->cli_error( (string) $findings['error'] );
		}
		$stray_rows = isset( $findings['stray_rows'] ) ? $findings['stray_rows'] : array();

		$this->cli_log( '' );
		$this->cli_log( 'Summary' );
		$this->cli_log( '-------' );
		$this->cli_log( 'Scan root: ' . (string) $findings['scan_root'] );
		$this->cli_log( 'Total files found: ' . (string) $findings['total_files'] );
		$this->cli_log( 'Ignored by pattern: ' . (string) $findings['ignored_files'] );
		$this->cli_log( 'Matched via attachments: ' . (string) $findings['attachment_matched'] );
		$this->cli_log( 'DB checks executed: ' . (string) $findings['checked_db'] );
		$this->cli_log( 'Matched via DB text refs: ' . (string) $findings['db_matched'] );
		$this->cli_log( 'Likely stray files: ' . (string) count( $stray_rows ) );
		$this->cli_log( 'Potential space: ' . size_format( (int) $findings['stray_size_bytes'], 2 ) );
		$this->cli_log( 'Elapsed: ' . number_format( (float) $findings['duration_seconds'], 2 ) . 's' );

		if ( ! empty( $findings['limited_out'] ) ) {
			$this->cli_warning( 'Stopped early because --limit was reached.' );
		}

		if ( ! empty( $stray_rows ) && ! $summary_only ) {
			$this->render_rows( $stray_rows, $format, array( 'path', 'extension', 'size_kb', 'modified', 'reason' ) );
		}

		if ( ( $quarantine || $backup_delete || $delete ) && ! empty( $stray_rows ) ) {
			$action      = $delete ? 'delete' : ( $backup_delete ? 'backup-delete' : 'quarantine' );
			$action_rows = $this->apply_action_to_paths( wp_list_pluck( $stray_rows, 'path' ), $action, $dry_run, (string) $findings['quarantine_dir'] );
			$this->cli_log( '' );
			$this->cli_log( ucfirst( $action ) . ' results' );
			$this->cli_log( str_repeat( '-', strlen( $action ) + 8 ) );
			$this->render_rows( $action_rows, $format, array( 'path', 'action', 'destination', 'message' ) );
		}

		if ( empty( $stray_rows ) && empty( $findings['limited_out'] ) ) {
			$this->cli_success( 'No likely stray files found in scanned scope.' );
			return;
		}

		$this->cli_warning(
			'Treat results as candidates. Custom tables, external services, hardcoded URLs, or encoded references may still point to these files.'
		);
		if ( $fail_on_findings && ! empty( $stray_rows ) ) {
			$this->cli_error( 'Likely stray files found.' );
		}
	}

		/**
		 * Run audit and return results for admin UI.
		 *
		 * @param array<string,mixed> $assoc_args Command options.
		 * @return array<string,mixed>
		 */
	public function get_audit_results( $assoc_args ) {
		$started_at = microtime( true );
		$uploads    = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return array(
				'error' => 'Could not resolve uploads directory/base URL.',
			);
		}

		$uploads_dir     = (string) $uploads['basedir'];
		$uploads_url     = untrailingslashit( (string) $uploads['baseurl'] );
		$plugin_settings = $this->get_plugin_settings();

		$requested_subdir = isset( $assoc_args['uploads-subdir'] ) ? trim( (string) $assoc_args['uploads-subdir'] ) : '';
		$subdir           = '' !== $requested_subdir ? $this->normalize_relative_path( $requested_subdir ) : '';
		if ( '' !== $requested_subdir && '' === $subdir ) {
			return array( 'error' => 'Uploads subdirectory must be a safe relative path.' );
		}
		$scan_root = $uploads_dir;
		if ( '' !== $subdir ) {
			$scan_root = trailingslashit( $uploads_dir ) . $subdir;
		}
		$scope_label = '' !== $subdir ? 'uploads/' . $subdir : 'uploads';

		if ( ! is_dir( $scan_root ) ) {
			return array(
				'error' => 'Uploads path to scan does not exist: ' . $scan_root,
			);
		}

		$include_all_files    = $this->get_bool_flag( $assoc_args, 'all-files', false );
		$skip_db_check        = $this->get_bool_flag( $assoc_args, 'skip-db-check', false );
		$scan_all_tables      = array_key_exists( 'scan-all-tables', $assoc_args )
			? $this->get_bool_flag( $assoc_args, 'scan-all-tables', false )
			: ! empty( $plugin_settings['scan_all_tables'] );
		$limit                = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$min_size_kb          = isset( $assoc_args['min-size'] ) ? max( 0, (float) $assoc_args['min-size'] ) : 0;
		$older_than_days      = isset( $assoc_args['older-than'] ) ? max( 0, (int) $assoc_args['older-than'] ) : 0;
		$oldest_allowed_mtime = $older_than_days > 0 ? time() - ( $older_than_days * DAY_IN_SECONDS ) : 0;
		$quarantine_dir       = isset( $assoc_args['quarantine-dir'] )
			? trim( (string) $assoc_args['quarantine-dir'] )
			: trim( (string) $plugin_settings['quarantine_dir'] );
		if ( '' === $quarantine_dir ) {
			$quarantine_dir = '.media-audit-quarantine';
		}

		$ignore_patterns  = $this->get_ignore_patterns( $assoc_args );
		$custom_db_checks = $this->build_custom_db_checks( $assoc_args, $scan_all_tables, $plugin_settings );

		$files = $this->collect_upload_files( $uploads_dir, $scan_root, $include_all_files );
		$this->cli_log( 'Inventory collected: ' . count( $files ) . ' file(s).' );
		$attachment_refs = $this->collect_attachment_references();
		$this->cli_log( 'Attachment reference index: ' . count( $attachment_refs ) . ' path(s).' );

		$stray_rows         = array();
		$total_files        = count( $files );
		$total_size_bytes   = array_sum(
			array_map(
				function ( $meta ) {
					return isset( $meta['size'] ) ? (int) $meta['size'] : 0;
				},
				$files
			)
		);
		$stray_size_bytes   = 0;
		$checked_db         = 0;
		$attachment_matched = 0;
		$db_matched         = 0;
		$ignored_files      = 0;
		$limited_out        = false;

		foreach ( $files as $relative => $meta ) {
			if ( $this->is_ignored_file( $relative, $ignore_patterns ) ) {
				++$ignored_files;
				continue;
			}

			if ( isset( $attachment_refs[ $relative ] ) ) {
				++$attachment_matched;
				continue;
			}

			$db_match = null;
			if ( ! $skip_db_check ) {
				if ( 0 !== $limit && $checked_db >= $limit ) {
					$limited_out = true;
					break;
				}

				$db_match = $this->find_database_reference( $relative, $uploads_url, $custom_db_checks );
				++$checked_db;
				if ( 0 === $checked_db % 250 ) {
					$this->cli_log( 'Database candidates checked: ' . $checked_db . '.' );
				}

				if ( null !== $db_match ) {
					++$db_matched;
					continue;
				}
			}

			if ( $min_size_kb > 0 && ( (int) $meta['size'] ) < ( $min_size_kb * 1024 ) ) {
				continue;
			}

			if ( $oldest_allowed_mtime > 0 && ( (int) $meta['mtime'] ) >= $oldest_allowed_mtime ) {
				continue;
			}

			$stray_size_bytes += (int) $meta['size'];
			$stray_rows[]      = array(
				'path'       => $relative,
				'extension'  => strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) ),
				'size_bytes' => (int) $meta['size'],
				'size_kb'    => round( ( (int) $meta['size'] ) / 1024, 1 ),
				'modified'   => gmdate( 'Y-m-d H:i:s', (int) $meta['mtime'] ),
				'reason'     => $skip_db_check ? 'not in attachment metadata' : 'no attachment/db reference found',
			);
		}

		return array(
			'uploads_dir'         => $uploads_dir,
			'quarantine_dir'      => $quarantine_dir,
			'scan_root'           => $scan_root,
			'scope_label'         => $scope_label,
			'total_files'         => $total_files,
			'total_size_bytes'    => $total_size_bytes,
			'ignored_files'       => $ignored_files,
			'attachment_matched'  => $attachment_matched,
			'checked_db'          => $checked_db,
			'db_matched'          => $db_matched,
			'limited_out'         => $limited_out,
			'custom_checks_count' => count( $custom_db_checks ),
			'stray_size_bytes'    => $stray_size_bytes,
			'duration_seconds'    => round( microtime( true ) - $started_at, 4 ),
			'filters'             => array(
				'min_size_kb'     => $min_size_kb,
				'older_than_days' => $older_than_days,
			),
			'stray_rows'          => $stray_rows,
		);
	}

		/**
		 * Prepare a resumable audit job for the admin batch runner.
		 *
		 * Filesystem inventory and attachment matching happen once. Remaining
		 * database candidates are then processed by process_audit_job_batch().
		 *
		 * @internal
		 * @param array<string,mixed> $assoc_args Audit options.
		 * @return array<string,mixed>
		 */
	public function prepare_audit_job( $assoc_args ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return array( 'error' => 'Could not resolve uploads directory/base URL.' );
		}

		$uploads_dir      = (string) $uploads['basedir'];
		$uploads_url      = untrailingslashit( (string) $uploads['baseurl'] );
		$settings         = $this->get_plugin_settings();
		$requested_subdir = isset( $assoc_args['uploads-subdir'] ) ? trim( (string) $assoc_args['uploads-subdir'] ) : '';
		$subdir           = '' !== $requested_subdir ? $this->normalize_relative_path( $requested_subdir ) : '';
		if ( '' !== $requested_subdir && '' === $subdir ) {
			return array( 'error' => 'Uploads subdirectory must be a safe relative path.' );
		}
		$scan_root   = '' !== $subdir ? trailingslashit( $uploads_dir ) . $subdir : $uploads_dir;
		$scope_label = '' !== $subdir ? 'uploads/' . $subdir : 'uploads';
		if ( ! is_dir( $scan_root ) ) {
			return array( 'error' => 'Uploads path to scan does not exist: ' . $scan_root );
		}

		$include_all_files    = $this->get_bool_flag( $assoc_args, 'all-files', false );
		$skip_db_check        = $this->get_bool_flag( $assoc_args, 'skip-db-check', false );
		$scan_all_tables      = array_key_exists( 'scan-all-tables', $assoc_args )
			? $this->get_bool_flag( $assoc_args, 'scan-all-tables', false )
			: ! empty( $settings['scan_all_tables'] );
		$limit                = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$min_size_kb          = isset( $assoc_args['min-size'] ) ? max( 0, (float) $assoc_args['min-size'] ) : 0;
		$older_than_days      = isset( $assoc_args['older-than'] ) ? max( 0, (int) $assoc_args['older-than'] ) : 0;
		$oldest_allowed_mtime = $older_than_days > 0 ? time() - ( $older_than_days * DAY_IN_SECONDS ) : 0;
		$quarantine_dir       = isset( $assoc_args['quarantine-dir'] ) ? trim( (string) $assoc_args['quarantine-dir'] ) : trim( (string) $settings['quarantine_dir'] );
		if ( '' === $quarantine_dir ) {
			$quarantine_dir = '.media-audit-quarantine';
		}

		// Preparation intentionally performs the filesystem and attachment passes
		// once. Persisting only unresolved candidates keeps later AJAX batches
		// deterministic and avoids rebuilding large reference indexes.
		$ignore_patterns    = $this->get_ignore_patterns( $assoc_args );
		$custom_checks      = $this->build_custom_db_checks( $assoc_args, $scan_all_tables, $settings );
		$files              = $this->collect_upload_files( $uploads_dir, $scan_root, $include_all_files );
		$attachment_refs    = $this->collect_attachment_references();
		$candidates         = array();
		$ignored_files      = 0;
		$attachment_matched = 0;
		$filtered_files     = 0;
		$total_size_bytes   = 0;

		foreach ( $files as $relative => $meta ) {
			$total_size_bytes += (int) $meta['size'];
			if ( $this->is_ignored_file( $relative, $ignore_patterns ) ) {
				++$ignored_files;
				continue;
			}
			if ( isset( $attachment_refs[ $relative ] ) ) {
				++$attachment_matched;
				continue;
			}
			if ( ( $min_size_kb > 0 && (int) $meta['size'] < ( $min_size_kb * 1024 ) ) || ( $oldest_allowed_mtime > 0 && (int) $meta['mtime'] >= $oldest_allowed_mtime ) ) {
				++$filtered_files;
				continue;
			}
			$candidates[] = array(
				'path'  => $relative,
				'size'  => (int) $meta['size'],
				'mtime' => (int) $meta['mtime'],
			);
		}

		// This entire value must remain serializable: the admin controller stores
		// it in a user/token-scoped transient between requests.
		return array(
			'uploads_dir'         => $uploads_dir,
			'uploads_url'         => $uploads_url,
			'quarantine_dir'      => $quarantine_dir,
			'scan_root'           => $scan_root,
			'scope_label'         => $scope_label,
			'total_files'         => count( $files ),
			'total_size_bytes'    => $total_size_bytes,
			'ignored_files'       => $ignored_files,
			'attachment_matched'  => $attachment_matched,
			'filtered_files'      => $filtered_files,
			'checked_db'          => 0,
			'db_matched'          => 0,
			'limited_out'         => false,
			'custom_checks_count' => count( $custom_checks ),
			'custom_checks'       => $custom_checks,
			'skip_db_check'       => $skip_db_check,
			'limit'               => $limit,
			'candidates'          => $candidates,
			'cursor'              => 0,
			'stray_rows'          => array(),
			'stray_size_bytes'    => 0,
			'started_at'          => microtime( true ),
			'stopped'             => false,
			'completed'           => empty( $candidates ),
			'filters'             => array(
				'min_size_kb'     => $min_size_kb,
				'older_than_days' => $older_than_days,
			),
		);
	}

		/**
		 * Process the next group of candidates in a resumable audit job.
		 *
		 * @internal
		 * @param array<string,mixed> $job Mutable serialized job state.
		 * @param int                 $batch_size Maximum candidates to process.
		 * @return array<string,mixed>
		 */
	public function process_audit_job_batch( $job, $batch_size ) {
		if ( ! empty( $job['completed'] ) || ! empty( $job['stopped'] ) ) {
			return $job;
		}
		$batch_size = max( 1, min( 100, (int) $batch_size ) );
		$total      = count( $job['candidates'] );
		$processed  = 0;

		// Advance one cursor serially. The browser never starts the next request
		// until the previous state has been committed by the controller.
		while ( (int) $job['cursor'] < $total && $processed < $batch_size ) {
			if ( empty( $job['skip_db_check'] ) && 0 !== (int) $job['limit'] && (int) $job['checked_db'] >= (int) $job['limit'] ) {
				$job['limited_out'] = true;
				$job['completed']   = true;
				break;
			}
			$meta     = $job['candidates'][ (int) $job['cursor'] ];
			$relative = (string) $meta['path'];
			$db_match = null;
			if ( empty( $job['skip_db_check'] ) ) {
				$db_match = $this->find_database_reference( $relative, (string) $job['uploads_url'], (array) $job['custom_checks'] );
				++$job['checked_db'];
			}
			if ( null !== $db_match ) {
				++$job['db_matched'];
			} else {
				$job['stray_size_bytes'] += (int) $meta['size'];
				$job['stray_rows'][]      = array(
					'path'       => $relative,
					'extension'  => strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) ),
					'size_bytes' => (int) $meta['size'],
					'size_kb'    => round( ( (int) $meta['size'] ) / 1024, 1 ),
					'modified'   => gmdate( 'Y-m-d H:i:s', (int) $meta['mtime'] ),
					'reason'     => ! empty( $job['skip_db_check'] ) ? 'not in attachment metadata' : 'no attachment/db reference found',
				);
			}
			++$job['cursor'];
			++$processed;
		}

		if ( (int) $job['cursor'] >= $total ) {
			$job['completed'] = true;
		}
		return $job;
	}

		/**
		 * Convert serialized job state into the standard findings payload.
		 *
		 * @internal
		 * @param array<string,mixed> $job Audit job state.
		 * @return array<string,mixed>
		 */
	public function finalize_audit_job( $job ) {
		// Strip candidate/query internals before exposing or persisting findings.
		// Stopped/limited scans are partial even though no more batches will run.
		$total_candidates = isset( $job['candidates'] ) && is_array( $job['candidates'] ) ? count( $job['candidates'] ) : 0;
		$cursor           = isset( $job['cursor'] ) ? (int) $job['cursor'] : 0;
		return array(
			'uploads_dir'          => (string) $job['uploads_dir'],
			'quarantine_dir'       => (string) $job['quarantine_dir'],
			'scan_root'            => (string) $job['scan_root'],
			'scope_label'          => isset( $job['scope_label'] ) ? (string) $job['scope_label'] : 'uploads',
			'total_files'          => (int) $job['total_files'],
			'total_size_bytes'     => (int) $job['total_size_bytes'],
			'ignored_files'        => (int) $job['ignored_files'],
			'attachment_matched'   => (int) $job['attachment_matched'],
			'filtered_files'       => isset( $job['filtered_files'] ) ? (int) $job['filtered_files'] : 0,
			'checked_db'           => (int) $job['checked_db'],
			'db_matched'           => (int) $job['db_matched'],
			'limited_out'          => ! empty( $job['limited_out'] ),
			'custom_checks_count'  => (int) $job['custom_checks_count'],
			'stray_size_bytes'     => (int) $job['stray_size_bytes'],
			'duration_seconds'     => round( microtime( true ) - (float) $job['started_at'], 4 ),
			'filters'              => (array) $job['filters'],
			'stray_rows'           => array_values( (array) $job['stray_rows'] ),
			'completed'            => ! empty( $job['completed'] ) && empty( $job['stopped'] ) && empty( $job['limited_out'] ),
			'partial'              => ! empty( $job['stopped'] ) || ! empty( $job['limited_out'] ),
			'stopped'              => ! empty( $job['stopped'] ),
			'processed_candidates' => $cursor,
			'total_candidates'     => $total_candidates,
			'progress_percent'     => 0 === $total_candidates ? 100 : min( 100, round( ( $cursor / $total_candidates ) * 100, 1 ) ),
		);
	}

		/**
		 * Apply a file action to selected paths.
		 *
		 * @param array<int,string> $paths Relative upload paths.
		 * @param string            $action Supported: quarantine, backup-delete, delete.
		 * @param bool              $dry_run Whether to simulate operation.
		 * @param string            $quarantine_dir Quarantine dir under uploads.
		 * @param bool              $revalidate Whether to rebuild current attachment/database references.
		 * @return array<int,array<string,string>>
		 */
	public function apply_action_to_paths( $paths, $action, $dry_run, $quarantine_dir, $revalidate = true ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return array(
				array(
					'path'        => '',
					'action'      => 'failed',
					'destination' => '',
					'message'     => 'uploads dir unavailable',
				),
			);
		}

		$stray_rows = array();
		foreach ( $paths as $path ) {
			$path = $this->normalize_relative_path( (string) $path );
			if ( '' === $path ) {
				continue;
			}
			$stray_rows[] = array( 'path' => $path );
		}

		$validation   = $revalidate ? $this->revalidate_action_rows( $stray_rows, $uploads ) : array(
			'safe_rows'    => $stray_rows,
			'blocked_rows' => array(),
		);
		$stray_rows   = $validation['safe_rows'];
		$blocked_rows = $validation['blocked_rows'];

		if ( 'quarantine' === $action ) {
			return array_merge( $blocked_rows, $this->quarantine_stray_files( $stray_rows, (string) $uploads['basedir'], $quarantine_dir, $dry_run ) );
		}

		if ( 'backup-delete' === $action ) {
			return array_merge( $blocked_rows, $this->backup_and_delete_files( $stray_rows, (string) $uploads['basedir'], $quarantine_dir, $dry_run ) );
		}

		if ( 'delete' === $action ) {
			return array_merge( $blocked_rows, $this->delete_stray_files( $stray_rows, (string) $uploads['basedir'], $dry_run ) );
		}

		return array(
			array(
				'path'        => '',
				'action'      => 'failed',
				'destination' => '',
				'message'     => 'unsupported action',
			),
		);
	}

		/**
		 * Build one verified ZIP and only then remove its original files.
		 *
		 * This dashboard-specific variant keeps the CLI's existing directory backup
		 * behavior intact while giving browser users a single downloadable artifact.
		 * All submitted paths are normalized and reference-checked immediately before
		 * the archive is created.
		 *
		 * @param array<int,string> $paths Relative upload paths.
		 * @param bool              $dry_run Whether to simulate operation.
		 * @param string            $quarantine_dir Safety directory below uploads.
		 * @param bool              $revalidate Whether to rebuild current attachment/database references.
		 * @return array{rows:array<int,array<string,string>>,archive:string}
		 */
	public function apply_zip_backup_to_paths( $paths, $dry_run, $quarantine_dir, $revalidate = true ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return array(
				'rows'    => array(
					array(
						'path'        => '',
						'action'      => 'failed',
						'destination' => '',
						'message'     => 'uploads dir unavailable',
					),
				),
				'archive' => '',
			);
		}

		$stray_rows = array();
		foreach ( $paths as $path ) {
			$path = $this->normalize_relative_path( (string) $path );
			if ( '' !== $path ) {
				$stray_rows[] = array( 'path' => $path );
			}
		}

		$validation     = $revalidate ? $this->revalidate_action_rows( $stray_rows, $uploads ) : array(
			'safe_rows'    => $stray_rows,
			'blocked_rows' => array(),
		);
		$result         = $this->zip_backup_and_delete_files( $validation['safe_rows'], (string) $uploads['basedir'], $quarantine_dir, $dry_run );
		$result['rows'] = array_merge( $validation['blocked_rows'], $result['rows'] );
		return $result;
	}

		/**
		 * Recheck every candidate immediately before a filesystem action.
		 *
		 * Files that are now registered attachments or have a current database
		 * reference are refused. Registered attachments must be managed through
		 * the Media Library, where WordPress uses wp_delete_attachment().
		 *
		 * @param array<int,array<string,mixed>> $rows Candidate rows.
		 * @param array<string,mixed>            $uploads WordPress uploads information.
		 * @return array{safe_rows:array<int,array<string,mixed>>,blocked_rows:array<int,array<string,string>>}
		 */
	private function revalidate_action_rows( $rows, $uploads ) {
		// Never trust a scan snapshot at mutation time. Content may have changed
		// during review or between AJAX batches, so rebuild current references.
		$attachment_refs = $this->collect_attachment_references();
		$settings        = $this->get_plugin_settings();
		$custom_checks   = $this->build_custom_db_checks( array(), ! empty( $settings['scan_all_tables'] ), $settings );
		$uploads_url     = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : '';
		$safe_rows       = array();
		$blocked_rows    = array();

		foreach ( $rows as $row ) {
			$relative = isset( $row['path'] ) ? $this->normalize_relative_path( (string) $row['path'] ) : '';
			if ( '' === $relative ) {
				continue;
			}
			if ( isset( $attachment_refs[ $relative ] ) ) {
				$blocked_rows[] = array(
					'path'        => $relative,
					'action'      => 'blocked',
					'destination' => '',
					'message'     => 'now referenced by attachment metadata; manage it through the Media Library',
				);
				continue;
			}

			$reference = '' !== $uploads_url ? $this->find_database_reference( $relative, $uploads_url, $custom_checks ) : null;
			if ( null !== $reference ) {
				$blocked_rows[] = array(
					'path'        => $relative,
					'action'      => 'blocked',
					'destination' => '',
					'message'     => 'current database reference: ' . (string) $reference['source'] . ' row ' . (string) $reference['row_id'],
				);
				continue;
			}

			$safe_rows[] = array( 'path' => $relative );
		}

		return array(
			'safe_rows'    => $safe_rows,
			'blocked_rows' => $blocked_rows,
		);
	}

		/**
		 * Get plugin settings saved via admin UI.
		 *
		 * @return array<string,mixed>
		 */
	private function get_plugin_settings() {
		$defaults = array(
			'ignore_patterns' => '',
			'custom_tables'   => '',
			'scan_all_tables' => 0,
			'quarantine_dir'  => '.media-audit-quarantine',
		);

		$settings = get_option( 'media_audit_settings', array() );
		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		return wp_parse_args( $settings, $defaults );
	}

		/**
		 * Render output rows in requested format.
		 *
		 * @param array<int,array<string,mixed>> $rows Output rows.
		 * @param string                         $format Output format.
		 * @param array<int,string>              $fields Ordered field names to display.
		 */
	private function render_rows( $rows, $format, $fields ) {
		$format_fn = 'WP_CLI\\Utils\\format_items';
		if ( function_exists( $format_fn ) ) {
			call_user_func( $format_fn, $format, $rows, $fields );
			return;
		}

		foreach ( $rows as $row ) {
			$chunks = array();
			foreach ( $fields as $field ) {
				$chunks[] = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
			}
			$this->cli_log( implode( ' | ', $chunks ) );
		}
	}

		/**
		 * Get ignore patterns from option/filter/CLI args.
		 *
		 * @param array<string,mixed> $assoc_args CLI options.
		 * @return array<int,string>
		 */
	private function get_ignore_patterns( $assoc_args ) {
		$patterns        = array();
		$plugin_settings = $this->get_plugin_settings();
		$quarantine_dir  = isset( $plugin_settings['quarantine_dir'] ) ? $this->normalize_relative_path( (string) $plugin_settings['quarantine_dir'] ) : '';
		if ( '' !== $quarantine_dir ) {
			$patterns[] = $quarantine_dir . '/';
		}

		if ( ! empty( $plugin_settings['ignore_patterns'] ) && is_string( $plugin_settings['ignore_patterns'] ) ) {
			$plugin_pattern_lines = preg_split( '/\r\n|\r|\n/', $plugin_settings['ignore_patterns'] );
			if ( is_array( $plugin_pattern_lines ) ) {
				foreach ( $plugin_pattern_lines as $line ) {
					$line = trim( (string) $line );
					if ( '' !== $line ) {
						$patterns[] = $line;
					}
				}
			}
		}

		$option_patterns = get_option( 'media_audit_ignore_patterns', array() );
		if ( is_array( $option_patterns ) ) {
			foreach ( $option_patterns as $item ) {
				if ( is_string( $item ) && '' !== trim( $item ) ) {
					$patterns[] = trim( $item );
				}
			}
		} elseif ( is_string( $option_patterns ) ) {
			$patterns = array_merge( $patterns, $this->parse_pattern_csv( $option_patterns ) );
		}

		if ( ! empty( $assoc_args['ignore'] ) ) {
			$patterns = array_merge( $patterns, $this->parse_pattern_csv( (string) $assoc_args['ignore'] ) );
		}

		if ( ! empty( $assoc_args['ignore-file'] ) ) {
			$ignore_file = (string) $assoc_args['ignore-file'];
			if ( is_file( $ignore_file ) && is_readable( $ignore_file ) ) {
				$lines = file( $ignore_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
				if ( is_array( $lines ) ) {
					foreach ( $lines as $line ) {
						$line = trim( (string) $line );
						if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
							continue;
						}
						$patterns[] = $line;
					}
				}
			} else {
				$this->cli_warning( 'Ignore file not readable: ' . $ignore_file );
			}
		}

		if ( has_filter( 'media_audit_ignore_patterns' ) ) {
			$patterns = (array) apply_filters( 'media_audit_ignore_patterns', $patterns );
		}

		$normalized = array();
		foreach ( $patterns as $pattern ) {
			$pattern = trim( wp_normalize_path( (string) $pattern ) );
			if ( '' !== $pattern ) {
				$normalized[] = $pattern;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

		/**
		 * Parse comma-separated patterns.
		 *
		 * @param string $csv CSV pattern string.
		 * @return array<int,string>
		 */
	private function parse_pattern_csv( $csv ) {
		$items  = explode( ',', (string) $csv );
		$result = array();
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$result[] = $item;
			}
		}

		return $result;
	}

		/**
		 * Check if a relative upload path should be ignored.
		 *
		 * @param string            $relative Relative path.
		 * @param array<int,string> $patterns Ignore patterns.
		 * @return bool
		 */
	private function is_ignored_file( $relative, $patterns ) {
		if ( empty( $patterns ) ) {
			return false;
		}

		$relative = wp_normalize_path( $relative );
		$base     = basename( $relative );

		foreach ( $patterns as $pattern ) {
			$pattern = wp_normalize_path( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}

			if ( '/' === substr( $pattern, -1 ) ) {
				$dir_prefix = rtrim( $pattern, '/' );
				if ( '' !== $dir_prefix && 0 === strpos( $relative, $dir_prefix . '/' ) ) {
					return true;
				}
			}

			if ( fnmatch( $pattern, $relative ) || fnmatch( $pattern, $base ) ) {
				return true;
			}
		}

		return false;
	}

		/**
		 * Read a boolean CLI flag from assoc args.
		 *
		 * @param array<string,mixed> $assoc_args Options.
		 * @param string              $key Option key.
		 * @param bool                $fallback Value used when the option is absent.
		 * @return bool
		 */
	private function get_bool_flag( $assoc_args, $key, $fallback ) {
		if ( ! isset( $assoc_args[ $key ] ) ) {
			return $fallback;
		}

		$value = $assoc_args[ $key ];
		if ( true === $value || '1' === $value || 1 === $value || 'true' === $value ) {
			return true;
		}

		if ( false === $value || '0' === $value || 0 === $value || 'false' === $value ) {
			return false;
		}

		return (bool) $value;
	}

		/**
		 * Proxy logger for WP-CLI log output.
		 *
		 * @param string $message Message.
		 */
	private function cli_log( $message ) {
		if ( is_callable( array( 'WP_CLI', 'log' ) ) ) {
			call_user_func( array( 'WP_CLI', 'log' ), $message );
		}
	}

		/**
		 * Proxy success output.
		 *
		 * @param string $message Message.
		 */
	private function cli_success( $message ) {
		if ( is_callable( array( 'WP_CLI', 'success' ) ) ) {
			call_user_func( array( 'WP_CLI', 'success' ), $message );
		}
	}

		/**
		 * Proxy warning output.
		 *
		 * @param string $message Message.
		 */
	private function cli_warning( $message ) {
		if ( is_callable( array( 'WP_CLI', 'warning' ) ) ) {
			call_user_func( array( 'WP_CLI', 'warning' ), $message );
		}
	}

		/**
		 * Proxy error output.
		 *
		 * @param string $message Message.
		 */
	private function cli_error( $message ) {
		if ( is_callable( array( 'WP_CLI', 'error' ) ) ) {
			call_user_func( array( 'WP_CLI', 'error' ), $message );
			return;
		}

		wp_die( esc_html( (string) $message ) );
	}

		/**
		 * Recursively collect uploads files.
		 *
		 * @param string $uploads_dir Uploads base directory.
		 * @param string $scan_root Directory to scan.
		 * @param bool   $include_all_files Whether to include non-media extensions.
		 * @return array<string,array{size:int,mtime:int}>
		 */
	private function collect_upload_files( $uploads_dir, $scan_root, $include_all_files ) {
		$uploads_dir = untrailingslashit( wp_normalize_path( $uploads_dir ) );
		$scan_root   = wp_normalize_path( $scan_root );
		$files       = array();

		$flags    = \FilesystemIterator::SKIP_DOTS;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $scan_root, $flags ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
				continue;
			}

			$full_path = wp_normalize_path( $file_info->getPathname() );
			$relative  = ltrim( str_replace( $uploads_dir, '', $full_path ), '/' );

			if ( '' === $relative ) {
				continue;
			}

			if ( ! $include_all_files && ! $this->is_media_file( $relative ) ) {
				continue;
			}

			$files[ $relative ] = array(
				'size'  => (int) $file_info->getSize(),
				'mtime' => (int) $file_info->getMTime(),
			);
		}

		ksort( $files );
		return $files;
	}

		/**
		 * Build a set of upload files referenced by attachment records and metadata.
		 *
		 * @return array<string,bool>
		 */
	private function collect_attachment_references() {
		$refs = array();

		$sql = "
				SELECT attached_meta.meta_value AS attached_file, attachment_meta.meta_value AS attachment_metadata
				FROM {$this->wpdb->posts} AS p
				INNER JOIN {$this->wpdb->postmeta} AS attached_meta
					ON attached_meta.post_id = p.ID
					AND attached_meta.meta_key = '_wp_attached_file'
				LEFT JOIN {$this->wpdb->postmeta} AS attachment_meta
					ON attachment_meta.post_id = p.ID
					AND attachment_meta.meta_key = '_wp_attachment_metadata'
				WHERE p.post_type = 'attachment'
			";

		// The query only interpolates WordPress-owned core table names and contains no input values.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Core table identifiers cannot use placeholders on supported WordPress 6.0.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( empty( $rows ) ) {
			return $refs;
		}

		foreach ( $rows as $row ) {
			$attached = $this->normalize_relative_path( isset( $row['attached_file'] ) ? (string) $row['attached_file'] : '' );
			if ( '' !== $attached ) {
				$refs[ $attached ] = true;
			}

			if ( empty( $row['attachment_metadata'] ) ) {
				continue;
			}

			$metadata = maybe_unserialize( $row['attachment_metadata'] );
			if ( ! is_array( $metadata ) ) {
				continue;
			}

			$this->append_attachment_metadata_refs( $refs, $metadata, $attached );
		}

		return $refs;
	}

		/**
		 * Add file references found in attachment metadata.
		 *
		 * @param array<string,bool>  $refs Reference set.
		 * @param array<string,mixed> $metadata Attachment metadata.
		 * @param string              $attached Fallback attachment relative path.
		 */
	private function append_attachment_metadata_refs( &$refs, $metadata, $attached ) {
		$base_file = isset( $metadata['file'] ) ? $this->normalize_relative_path( (string) $metadata['file'] ) : $attached;
		if ( '' !== $base_file ) {
			$refs[ $base_file ] = true;
		}

		$base_dir = '';
		if ( '' !== $base_file ) {
			$base_dir = dirname( $base_file );
			if ( '.' === $base_dir ) {
				$base_dir = '';
			}
		}

		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$original = $this->join_relative_path( $base_dir, $metadata['original_image'] );
			if ( '' !== $original ) {
				$refs[ $original ] = true;
			}
		}

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( ! is_array( $size_data ) || empty( $size_data['file'] ) || ! is_string( $size_data['file'] ) ) {
					continue;
				}

				$size_file = $this->join_relative_path( $base_dir, $size_data['file'] );
				if ( '' !== $size_file ) {
					$refs[ $size_file ] = true;
				}
			}
		}

		if ( ! empty( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ) {
			foreach ( $metadata['backup_sizes'] as $size_data ) {
				if ( ! is_array( $size_data ) || empty( $size_data['file'] ) || ! is_string( $size_data['file'] ) ) {
					continue;
				}

				$backup_file = $this->join_relative_path( $base_dir, $size_data['file'] );
				if ( '' !== $backup_file ) {
					$refs[ $backup_file ] = true;
				}
			}
		}
	}

		/**
		 * Check whether the candidate file appears in common DB text locations.
		 *
		 * Includes core places where ACF commonly stores references: postmeta,
		 * termmeta, usermeta, and options.
		 *
		 * @param string                         $relative Relative path under uploads.
		 * @param string                         $uploads_url Uploads base URL without trailing slash.
		 * @param array<int,array<string,mixed>> $custom_checks Validated table/column checks.
		 * @return array<string,mixed>|null
		 */
	private function find_database_reference( $relative, $uploads_url, $custom_checks ) {
		$relative = $this->normalize_relative_path( $relative );
		if ( '' === $relative ) {
			return null;
		}

		$url              = $uploads_url . '/' . str_replace( '%2F', '/', rawurlencode( $relative ) );
		$plain_url        = $uploads_url . '/' . $relative;
		$uploads_fragment = '/uploads/' . $relative;
		$patterns         = array_unique( array_filter( array( $url, $plain_url, $uploads_fragment, $relative ) ) );

		$checks = array(
			array(
				'label'     => 'posts.post_content',
				'table'     => $this->wpdb->posts,
				'id_col'    => 'ID',
				'value_col' => 'post_content',
			),
			array(
				'label'     => 'posts.post_excerpt',
				'table'     => $this->wpdb->posts,
				'id_col'    => 'ID',
				'value_col' => 'post_excerpt',
			),
			array(
				'label'     => 'posts.guid',
				'table'     => $this->wpdb->posts,
				'id_col'    => 'ID',
				'value_col' => 'guid',
			),
			array(
				'label'     => 'postmeta.meta_value',
				'table'     => $this->wpdb->postmeta,
				'id_col'    => 'meta_id',
				'value_col' => 'meta_value',
			),
			array(
				'label'            => 'options.option_value',
				'table'            => $this->wpdb->options,
				'id_col'           => 'option_id',
				'value_col'        => 'option_value',
				'exclude_col'      => 'option_name',
				// Saved results contain candidate paths and must not count as usage.
				'exclude_prefixes' => array( 'media_audit_', '_transient_media_audit_', '_transient_timeout_media_audit_' ),
			),
			array(
				'label'     => 'termmeta.meta_value',
				'table'     => $this->wpdb->termmeta,
				'id_col'    => 'meta_id',
				'value_col' => 'meta_value',
			),
			array(
				'label'     => 'usermeta.meta_value',
				'table'     => $this->wpdb->usermeta,
				'id_col'    => 'umeta_id',
				'value_col' => 'meta_value',
			),
			array(
				'label'     => 'commentmeta.meta_value',
				'table'     => $this->wpdb->commentmeta,
				'id_col'    => 'meta_id',
				'value_col' => 'meta_value',
			),
		);

		if ( ! empty( $custom_checks ) ) {
			$checks = array_merge( $checks, $custom_checks );
		}

		foreach ( $checks as $check ) {
			$table = (string) $check['table'];
			if ( empty( $table ) || ! isset( $this->existing_tables[ $table ] ) ) {
				continue;
			}

			$id_col    = (string) $check['id_col'];
			$value_col = (string) $check['value_col'];

			$where_chunks = array();
			$params       = array();
			foreach ( $patterns as $pattern ) {
				$where_chunks[] = "{$value_col} LIKE %s";
				$params[]       = '%' . $this->wpdb->esc_like( $pattern ) . '%';
			}

			$where_sql   = implode( ' OR ', $where_chunks );
			$exclude_sql = '';
			if ( ! empty( $check['exclude_col'] ) && ! empty( $check['exclude_prefixes'] ) && is_array( $check['exclude_prefixes'] ) ) {
				$exclude_col = (string) $check['exclude_col'];
				foreach ( $check['exclude_prefixes'] as $excluded_prefix ) {
					$exclude_sql .= " AND {$exclude_col} NOT LIKE %s";
					$params[]     = $this->wpdb->esc_like( (string) $excluded_prefix ) . '%';
				}
			}
			$sql = "SELECT {$id_col} AS row_id FROM {$table} WHERE ({$where_sql}){$exclude_sql} LIMIT 1";
			// Identifiers are restricted to the previously loaded schema map; values use placeholders.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Safe identifiers cannot use %i on the minimum supported WordPress 6.0.
			$prepared = $this->wpdb->prepare( $sql, ...$params );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- The query was prepared immediately above with schema-whitelisted identifiers.
			$found_id = $this->wpdb->get_var( $prepared );
			if ( null !== $found_id ) {
				return array(
					'source' => (string) $check['label'],
					'row_id' => (string) $found_id,
				);
			}
		}

		return null;
	}

		/**
		 * Build custom DB checks for plugin tables.
		 *
		 * @param array<string,mixed> $assoc_args CLI options.
		 * @param bool                $scan_all_tables Include non-core tables automatically.
		 * @param array<string,mixed> $plugin_settings Saved Media Audit settings.
		 * @return array<int,array<string,string>>
		 */
	private function build_custom_db_checks( $assoc_args, $scan_all_tables, $plugin_settings ) {
		$checks = array();
		$seen   = array();

		$custom_tables = isset( $assoc_args['custom-tables'] )
			? trim( (string) $assoc_args['custom-tables'] )
			: trim( (string) $plugin_settings['custom_tables'] );
		if ( '' !== $custom_tables ) {
			$items = explode( ',', $custom_tables );
			foreach ( $items as $item ) {
				$item = trim( (string) $item );
				if ( '' === $item ) {
					continue;
				}

				$parts = explode( ':', $item, 2 );
				$table = $this->normalize_table_name( trim( $parts[0] ) );
				if ( '' === $table || ! isset( $this->existing_tables[ $table ] ) ) {
					$this->cli_warning( 'Custom table not found: ' . $table );
					continue;
				}

				if ( ! empty( $parts[1] ) ) {
					$column = trim( (string) $parts[1] );
					if ( ! $this->table_has_column( $table, $column ) ) {
						$this->cli_warning( 'Column not found for custom table check: ' . $table . '.' . $column );
						continue;
					}

					$key = $table . '|' . $column;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}

					$checks[]     = array(
						'label'     => 'custom.' . $table . '.' . $column,
						'table'     => $table,
						'id_col'    => $column,
						'value_col' => $column,
					);
					$seen[ $key ] = true;
					continue;
				}

				$columns = $this->detect_text_columns( $table );
				foreach ( $columns as $column ) {
					$key = $table . '|' . $column;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$checks[]     = array(
						'label'     => 'custom.' . $table . '.' . $column,
						'table'     => $table,
						'id_col'    => $column,
						'value_col' => $column,
					);
					$seen[ $key ] = true;
				}
			}
		}

		if ( $scan_all_tables ) {
			$core_tables = array(
				(string) $this->wpdb->posts,
				(string) $this->wpdb->postmeta,
				(string) $this->wpdb->options,
				(string) $this->wpdb->termmeta,
				(string) $this->wpdb->usermeta,
				(string) $this->wpdb->commentmeta,
				(string) $this->wpdb->terms,
				(string) $this->wpdb->term_taxonomy,
				(string) $this->wpdb->term_relationships,
				(string) $this->wpdb->comments,
				(string) $this->wpdb->users,
			);

			foreach ( array_keys( $this->existing_tables ) as $table ) {
				if ( in_array( $table, $core_tables, true ) ) {
					continue;
				}

				$columns = $this->detect_text_columns( $table );
				foreach ( $columns as $column ) {
					$key = $table . '|' . $column;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$checks[]     = array(
						'label'     => 'auto.' . $table . '.' . $column,
						'table'     => $table,
						'id_col'    => $column,
						'value_col' => $column,
					);
					$seen[ $key ] = true;
				}
			}
		}

		return $checks;
	}

		/**
		 * Normalize user-provided table name.
		 *
		 * @param string $table Table string from CLI.
		 * @return string
		 */
	private function normalize_table_name( $table ) {
		$table = trim( (string) $table );
		if ( '' === $table ) {
			return '';
		}

		if ( isset( $this->existing_tables[ $table ] ) ) {
			return $table;
		}

		$prefixed = $this->wpdb->prefix . ltrim( $table, '_' );
		if ( isset( $this->existing_tables[ $prefixed ] ) ) {
			return $prefixed;
		}

		return $table;
	}

		/**
		 * Determine if a table has a specific column.
		 *
		 * @param string $table Table name.
		 * @param string $column Column name.
		 * @return bool
		 */
	private function table_has_column( $table, $column ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table was matched against the loaded database schema.
		$columns = $this->wpdb->get_col( 'SHOW COLUMNS FROM ' . $table );
		if ( empty( $columns ) ) {
			return false;
		}

		return in_array( $column, $columns, true );
	}

		/**
		 * Detect text-like columns for table scanning.
		 *
		 * @param string $table Table name.
		 * @return array<int,string>
		 */
	private function detect_text_columns( $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table was matched against the loaded database schema.
		$rows = $this->wpdb->get_results( 'SHOW COLUMNS FROM ' . $table, ARRAY_A );
		if ( empty( $rows ) ) {
			return array();
		}

		$text_columns = array();
		foreach ( $rows as $row ) {
			if ( empty( $row['Field'] ) || empty( $row['Type'] ) ) {
				continue;
			}

			$type = strtolower( (string) $row['Type'] );
			if (
				false !== strpos( $type, 'char' )
				|| false !== strpos( $type, 'text' )
				|| false !== strpos( $type, 'json' )
			) {
				$text_columns[] = (string) $row['Field'];
			}
		}

		return $text_columns;
	}

		/**
		 * Quarantine likely stray files.
		 *
		 * @param array<int,array<string,mixed>> $stray_rows Stray rows.
		 * @param string                         $uploads_dir Uploads base dir.
		 * @param string                         $quarantine_dir Quarantine dir relative to uploads.
		 * @param bool                           $dry_run Whether to skip actual moves.
		 * @return array<int,array<string,string>>
		 */
	private function quarantine_stray_files( $stray_rows, $uploads_dir, $quarantine_dir, $dry_run ) {
		$results        = array();
		$uploads_dir    = untrailingslashit( wp_normalize_path( $uploads_dir ) );
		$quarantine_dir = $this->normalize_relative_path( $quarantine_dir );
		if ( '' === $quarantine_dir ) {
			$quarantine_dir = '.media-audit-quarantine';
		}

		$run_dir = $uploads_dir . '/' . $quarantine_dir . '/' . gmdate( 'Ymd-His' );
		if ( ! $dry_run && ! is_dir( $run_dir ) && ! wp_mkdir_p( $run_dir ) ) {
			$this->cli_warning( 'Failed to create quarantine dir: ' . $run_dir );
			return $results;
		}

		foreach ( $stray_rows as $row ) {
			$relative = isset( $row['path'] ) ? $this->normalize_relative_path( (string) $row['path'] ) : '';
			if ( '' === $relative ) {
				continue;
			}

			$source          = $uploads_dir . '/' . $relative;
			$destination     = $run_dir . '/' . $relative;
			$destination_dir = dirname( $destination );
			$source_size     = is_file( $source ) ? (int) filesize( $source ) : 0;

			if ( ! file_exists( $source ) ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'skipped',
					'destination' => $destination,
					'message'     => 'source not found',
				);
				continue;
			}

			if ( $dry_run ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'would-move',
					'destination' => $destination,
					'message'     => 'dry-run',
				);
				continue;
			}

			if ( ! is_dir( $destination_dir ) && ! wp_mkdir_p( $destination_dir ) ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'failed',
					'destination' => $destination,
					'message'     => 'could not create destination dir',
				);
				continue;
			}

			do_action( 'media_audit_before_file_action', $source, $relative, 'quarantine' );
			// A same-filesystem rename is atomic and preserves a recoverable original path.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- WP_Filesystem may request credentials during a WP-CLI operation.
			if ( rename( $source, $destination ) ) {
				do_action( 'media_audit_file_quarantined', $source, $destination, $relative );
				$results[] = array(
					'path'        => $relative,
					'action'      => 'moved',
					'destination' => $destination,
					'message'     => 'ok',
					'size_bytes'  => $source_size,
				);
			} else {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'failed',
					'destination' => $destination,
					'message'     => 'rename failed',
				);
			}
		}

		return $results;
	}

		/**
		 * Create and verify a ZIP before removing any original candidate.
		 *
		 * Archive verification reads each uncompressed entry back from the finished
		 * ZIP and compares both its byte count and SHA-256 hash with the source. If
		 * any entry fails, the archive is discarded and every original is retained.
		 *
		 * @param array<int,array<string,mixed>> $stray_rows Candidate rows.
		 * @param string                         $uploads_dir Uploads base directory.
		 * @param string                         $quarantine_dir Configured safety directory under uploads.
		 * @param bool                           $dry_run Whether to simulate the operation.
		 * @return array{rows:array<int,array<string,string>>,archive:string}
		 */
	private function zip_backup_and_delete_files( $stray_rows, $uploads_dir, $quarantine_dir, $dry_run ) {
		$results        = array();
		$uploads_dir    = untrailingslashit( wp_normalize_path( $uploads_dir ) );
		$quarantine_dir = $this->normalize_relative_path( $quarantine_dir );
		if ( '' === $quarantine_dir ) {
			$quarantine_dir = '.media-audit-quarantine';
		}
		$archive_dir  = $uploads_dir . '/' . $quarantine_dir . '/backups';
		$archive_name = 'media-audit-backup-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) ) . '.zip';
		$archive_path = $archive_dir . '/' . $archive_name;

		if ( $dry_run ) {
			foreach ( $stray_rows as $row ) {
				$relative = isset( $row['path'] ) ? $this->normalize_relative_path( (string) $row['path'] ) : '';
				if ( '' !== $relative ) {
					$results[] = array(
						'path'        => $relative,
						'action'      => 'would-back-up-and-remove',
						'destination' => $archive_name,
						'message'     => 'would add to verified ZIP, download, and remove',
					);
				}
			}
			return array(
				'rows'    => $results,
				'archive' => '',
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array(
				'rows'    => array(
					array(
						'path'        => '',
						'action'      => 'failed',
						'destination' => '',
						'message'     => 'PHP ZipArchive extension is required',
					),
				),
				'archive' => '',
			);
		}
		if ( ! is_dir( $archive_dir ) && ! wp_mkdir_p( $archive_dir ) ) {
			return array(
				'rows'    => array(
					array(
						'path'        => '',
						'action'      => 'failed',
						'destination' => $archive_path,
						'message'     => 'could not create backup directory',
					),
				),
				'archive' => '',
			);
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::EXCL ) ) {
			return array(
				'rows'    => array(
					array(
						'path'        => '',
						'action'      => 'failed',
						'destination' => $archive_path,
						'message'     => 'could not create ZIP archive',
					),
				),
				'archive' => '',
			);
		}

		$expected = array();
		foreach ( $stray_rows as $row ) {
			$relative = isset( $row['path'] ) ? $this->normalize_relative_path( (string) $row['path'] ) : '';
			$source   = '' !== $relative ? $uploads_dir . '/' . $relative : '';
			if ( '' === $relative || ! is_file( $source ) || is_link( $source ) ) {
				if ( '' !== $relative ) {
					$results[] = array(
						'path'        => $relative,
						'action'      => 'skipped',
						'destination' => $archive_path,
						'message'     => 'source missing or symbolic link',
					);
				}
				continue;
			}
			$size = filesize( $source );
			$hash = hash_file( 'sha256', $source );
			if ( false === $size || false === $hash || ! $zip->addFile( $source, $relative ) ) {
				$zip->close();
				wp_delete_file( $archive_path );
				return array(
					'rows'    => array_merge(
						$results,
						array(
							array(
								'path'        => $relative,
								'action'      => 'failed',
								'destination' => $archive_path,
								'message'     => 'could not add source to ZIP; all originals retained',
							),
						)
					),
					'archive' => '',
				);
			}
			$expected[ $relative ] = array(
				'source' => $source,
				'size'   => (int) $size,
				'hash'   => (string) $hash,
			);
		}

		if ( empty( $expected ) || ! $zip->close() ) {
			wp_delete_file( $archive_path );
			return array(
				'rows'    => array_merge(
					$results,
					array(
						array(
							'path'        => '',
							'action'      => 'failed',
							'destination' => $archive_path,
							'message'     => 'ZIP contains no eligible files or could not be finalized',
						),
					)
				),
				'archive' => '',
			);
		}

		$verified_zip = new \ZipArchive();
		if ( true !== $verified_zip->open( $archive_path ) ) {
			wp_delete_file( $archive_path );
			return array(
				'rows'    => array_merge(
					$results,
					array(
						array(
							'path'        => '',
							'action'      => 'failed',
							'destination' => $archive_path,
							'message'     => 'ZIP could not be reopened for verification; all originals retained',
						),
					)
				),
				'archive' => '',
			);
		}
		$archive_valid = true;
		foreach ( $expected as $relative => $item ) {
			$stream = $verified_zip->getStream( $relative );
			if ( false === $stream ) {
				$archive_valid = false;
				break;
			}
			$context = hash_init( 'sha256' );
			$bytes   = 0;
			while ( ! feof( $stream ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- ZipArchive streams must be read incrementally for hash verification.
				$chunk = fread( $stream, 1048576 );
				if ( false === $chunk ) {
					$archive_valid = false;
					break;
				}
				$bytes += strlen( $chunk );
				hash_update( $context, $chunk );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the ZipArchive entry stream opened above.
			fclose( $stream );
			$archive_hash = hash_final( $context );
			if ( ! $archive_valid || $bytes !== $item['size'] || ! hash_equals( $item['hash'], $archive_hash ) ) {
				$archive_valid = false;
				break;
			}
		}
		$verified_zip->close();
		if ( ! $archive_valid ) {
			wp_delete_file( $archive_path );
			return array(
				'rows'    => array_merge(
					$results,
					array(
						array(
							'path'        => '',
							'action'      => 'failed',
							'destination' => $archive_path,
							'message'     => 'ZIP verification failed; all originals retained',
						),
					)
				),
				'archive' => '',
			);
		}

		foreach ( $expected as $relative => $item ) {
			$current_size = is_file( $item['source'] ) ? filesize( $item['source'] ) : false;
			$current_hash = is_file( $item['source'] ) ? hash_file( 'sha256', $item['source'] ) : false;
			if ( false === $current_size || false === $current_hash || $current_size !== $item['size'] || ! hash_equals( $item['hash'], (string) $current_hash ) ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'blocked',
					'destination' => $archive_name,
					'message'     => 'source changed after ZIP verification; original retained',
				);
				continue;
			}
			do_action( 'media_audit_before_file_action', $item['source'], $relative, 'backup-delete' );
			if ( wp_delete_file( $item['source'] ) && ! file_exists( $item['source'] ) ) {
				do_action( 'media_audit_file_backed_up_and_removed', $item['source'], $archive_path, $relative );
				$results[] = array(
					'path'        => $relative,
					'action'      => 'backed-up-and-removed',
					'destination' => $archive_name,
					'message'     => 'verified in downloadable ZIP',
					'size_bytes'  => $item['size'],
				);
			} else {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'failed',
					'destination' => $archive_name,
					'message'     => 'ZIP verified but original could not be removed',
				);
			}
		}

		return array(
			'rows'    => $results,
			'archive' => $archive_path,
		);
	}

		/**
		 * Copy candidates to a verified backup and then remove their originals.
		 *
		 * The backup preserves each path below a unique timestamped directory.
		 * An original is only removed after both size and SHA-256 checks succeed.
		 *
		 * @param array<int,array<string,mixed>> $stray_rows Candidate rows.
		 * @param string                         $uploads_dir Uploads base directory.
		 * @param string                         $quarantine_dir Configured safety directory under uploads.
		 * @param bool                           $dry_run Whether to simulate the operation.
		 * @return array<int,array<string,string>>
		 */
	private function backup_and_delete_files( $stray_rows, $uploads_dir, $quarantine_dir, $dry_run ) {
		$results        = array();
		$uploads_dir    = untrailingslashit( wp_normalize_path( $uploads_dir ) );
		$quarantine_dir = $this->normalize_relative_path( $quarantine_dir );
		if ( '' === $quarantine_dir ) {
			$quarantine_dir = '.media-audit-quarantine';
		}

		$run_suffix = $dry_run ? 'preview' : strtolower( wp_generate_password( 6, false, false ) );
		$run_dir    = $uploads_dir . '/' . $quarantine_dir . '/backups/' . gmdate( 'Ymd-His' ) . '-' . $run_suffix;
		if ( ! $dry_run && ! is_dir( $run_dir ) && ! wp_mkdir_p( $run_dir ) ) {
			return array(
				array(
					'path'        => '',
					'action'      => 'failed',
					'destination' => $run_dir,
					'message'     => 'could not create backup directory',
				),
			);
		}

		foreach ( $stray_rows as $row ) {
			$relative = isset( $row['path'] ) ? $this->normalize_relative_path( (string) $row['path'] ) : '';
			if ( '' === $relative ) {
				continue;
			}
			$source      = $uploads_dir . '/' . $relative;
			$source_size = is_file( $source ) ? (int) filesize( $source ) : 0;
			$destination = $run_dir . '/' . $relative;

			if ( ! is_file( $source ) || is_link( $source ) ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'skipped',
					'destination' => $destination,
					'message'     => 'source missing or symbolic link',
				);
				continue;
			}
			if ( $dry_run ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'would-back-up-and-remove',
					'destination' => $destination,
					'message'     => 'dry-run',
				);
				continue;
			}

			$destination_dir = dirname( $destination );
			if ( ! is_dir( $destination_dir ) && ! wp_mkdir_p( $destination_dir ) ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'failed',
					'destination' => $destination,
					'message'     => 'could not create backup path',
				);
				continue;
			}

			// Removal is conditional on two independent equality checks. A partial or
			// changed copy is discarded and the original is retained.
			$source_size = filesize( $source );
			$source_hash = hash_file( 'sha256', $source );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- The copied bytes are verified before the source can be removed.
			if ( ! copy( $source, $destination ) ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'failed',
					'destination' => $destination,
					'message'     => 'backup copy failed; original retained',
				);
				continue;
			}

			$backup_size = filesize( $destination );
			$backup_hash = hash_file( 'sha256', $destination );
			if ( false === $source_size || false === $source_hash || $source_size !== $backup_size || ! hash_equals( (string) $source_hash, (string) $backup_hash ) ) {
				wp_delete_file( $destination );
				$results[] = array(
					'path'        => $relative,
					'action'      => 'failed',
					'destination' => $destination,
					'message'     => 'backup verification failed; original retained',
				);
				continue;
			}

			do_action( 'media_audit_before_file_action', $source, $relative, 'backup-delete' );
			if ( wp_delete_file( $source ) && ! file_exists( $source ) ) {
				do_action( 'media_audit_file_backed_up_and_removed', $source, $destination, $relative );
				$results[] = array(
					'path'        => $relative,
					'action'      => 'backed-up-and-removed',
					'destination' => $destination,
					'message'     => 'SHA-256 verified',
				);
			} else {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'failed',
					'destination' => $destination,
					'message'     => 'backup verified but original could not be removed',
				);
			}
		}

		return $results;
	}

		/**
		 * Permanently delete likely stray files.
		 *
		 * @param array<int,array<string,mixed>> $stray_rows Stray rows.
		 * @param string                         $uploads_dir Uploads base dir.
		 * @param bool                           $dry_run Whether to simulate deletes.
		 * @return array<int,array<string,string>>
		 */
	private function delete_stray_files( $stray_rows, $uploads_dir, $dry_run ) {
		$results     = array();
		$uploads_dir = untrailingslashit( wp_normalize_path( $uploads_dir ) );

		foreach ( $stray_rows as $row ) {
			$relative = isset( $row['path'] ) ? $this->normalize_relative_path( (string) $row['path'] ) : '';
			if ( '' === $relative ) {
				continue;
			}

			$source = $uploads_dir . '/' . $relative;

			if ( ! file_exists( $source ) ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'skipped',
					'destination' => '',
					'message'     => 'source not found',
				);
				continue;
			}
			$source_size = is_file( $source ) ? (int) filesize( $source ) : 0;

			if ( $dry_run ) {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'would-delete',
					'destination' => '',
					'message'     => 'dry-run',
				);
				continue;
			}

			do_action( 'media_audit_before_file_action', $source, $relative, 'delete' );
			if ( wp_delete_file( $source ) && ! file_exists( $source ) ) {
				do_action( 'media_audit_file_deleted', $source, $relative );
				$results[] = array(
					'path'        => $relative,
					'action'      => 'deleted',
					'destination' => '',
					'message'     => 'deleted with wp_delete_file()',
					'size_bytes'  => $source_size,
				);
			} else {
				$results[] = array(
					'path'        => $relative,
					'action'      => 'failed',
					'destination' => '',
					'message'     => 'wp_delete_file() failed or was blocked by a filter',
				);
			}
		}

		return $results;
	}

		/**
		 * Determine whether file extension is a known media type.
		 *
		 * @param string $relative Relative upload path.
		 * @return bool
		 */
	private function is_media_file( $relative ) {
		$extension = strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) );
		if ( '' === $extension ) {
			return false;
		}

		$mimes = wp_get_mime_types();
		foreach ( $mimes as $ext_pattern => $mime ) {
			unset( $mime );
			$parts = explode( '|', (string) $ext_pattern );
			if ( in_array( $extension, $parts, true ) ) {
				return true;
			}
		}

		return false;
	}

		/**
		 * Normalize relative upload path.
		 *
		 * @param string $path File path.
		 * @return string
		 */
	private function normalize_relative_path( $path ) {
		$path = wp_normalize_path( trim( (string) $path ) );
		$path = ltrim( $path, '/' );
		if ( '' === $path || $this->has_unsafe_path_segment( $path ) || preg_match( '/[\x00-\x1F]/', $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Return true only for actual current/parent traversal path segments.
	 *
	 * @param string $path Relative path to inspect.
	 * @return bool
	 */
	private function has_unsafe_path_segment( $path ) {
		foreach ( explode( '/', wp_normalize_path( (string) $path ) ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return true;
			}
		}
		return false;
	}

		/**
		 * Join a base relative directory and file name.
		 *
		 * @param string $base_dir Relative base directory.
		 * @param string $file_name File name.
		 * @return string
		 */
	private function join_relative_path( $base_dir, $file_name ) {
		$file_name = trim( (string) $file_name );
		if ( '' === $file_name ) {
			return '';
		}

		if ( '' === $base_dir ) {
			return $this->normalize_relative_path( $file_name );
		}

		return $this->normalize_relative_path( $base_dir . '/' . ltrim( $file_name, '/' ) );
	}

		/**
		 * Load current database tables into a lookup map.
		 *
		 * @return array<string,bool>
		 */
	private function load_existing_tables() {
		$tables = $this->wpdb->get_col( 'SHOW TABLES' );
		if ( empty( $tables ) ) {
			return array();
		}

		$existing = array();
		foreach ( $tables as $table ) {
			$existing[ (string) $table ] = true;
		}

		return $existing;
	}
}
