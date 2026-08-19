<?php
/**
 * AJAX-powered admin experience for Media Audit.
 *
 * @package GPMediaAudit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Tools screen and owns its per-user AJAX scan lifecycle.
 *
 * Scan jobs are isolated by both user ID and a random token. The browser never
 * submits findings back as trusted data; every batch and file action is resolved
 * from server-side transients.
 */
class Media_Audit_Admin_Page {

	/** Option containing reference-detection and quarantine settings. */
	const OPTION_KEY = 'media_audit_settings';

	/** Prefix for the current user's most recently displayable findings. */
	const FINDINGS_TRANSIENT_PREFIX = 'media_audit_last_findings_';

	/** Maximum finding rows stored in one object-cache/transient value. */
	const FINDINGS_CHUNK_SIZE = 500;

	/** Prefix for serialized, resumable scan-job state. */
	const JOB_TRANSIENT_PREFIX = 'media_audit_scan_job_';

	/** Prefix for short-lived cancellation markers used to avoid stop races. */
	const STOP_TRANSIENT_PREFIX = 'media_audit_scan_stop_';

	/** Nonce action shared by authenticated Media Audit AJAX requests. */
	const NONCE_ACTION = 'media_audit_ajax';

	/** Persistent dashboard cleanup counters. */
	const STATS_OPTION = 'media_audit_cleanup_stats';

	/** Current user's resumable Media Library integrity state. */
	const INTEGRITY_TRANSIENT_PREFIX = 'media_audit_integrity_';

	/** Register settings, screen assets, and authenticated AJAX endpoints. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_media_audit_run', array( __CLASS__, 'ajax_run_audit' ) );
		add_action( 'wp_ajax_media_audit_start', array( __CLASS__, 'ajax_start_audit' ) );
		add_action( 'wp_ajax_media_audit_step', array( __CLASS__, 'ajax_step_audit' ) );
		add_action( 'wp_ajax_media_audit_stop', array( __CLASS__, 'ajax_stop_audit' ) );
		add_action( 'wp_ajax_media_audit_apply', array( __CLASS__, 'ajax_apply_action' ) );
		add_action( 'wp_ajax_media_audit_clear', array( __CLASS__, 'ajax_clear_findings' ) );
		add_action( 'wp_ajax_media_audit_list_quarantine', array( __CLASS__, 'ajax_list_quarantine' ) );
		add_action( 'wp_ajax_media_audit_restore', array( __CLASS__, 'ajax_restore_quarantined_file' ) );
		add_action( 'wp_ajax_media_audit_delete_quarantine', array( __CLASS__, 'ajax_delete_quarantined_files' ) );
		add_action( 'wp_ajax_media_audit_integrity_start', array( __CLASS__, 'ajax_integrity_start' ) );
		add_action( 'wp_ajax_media_audit_integrity_step', array( __CLASS__, 'ajax_integrity_step' ) );
		add_action( 'wp_ajax_media_audit_integrity_delete', array( __CLASS__, 'ajax_integrity_delete' ) );
		add_action( 'admin_post_media_audit_download_backup', array( __CLASS__, 'download_backup' ) );
	}

	/** Add Media Audit below the WordPress Tools menu. */
	public static function register_menu() {
		add_management_page( __( 'Media Audit', 'media-audit' ), __( 'Media Audit', 'media-audit' ), 'manage_options', 'media-audit', array( __CLASS__, 'render_page' ) );
	}

	/** Register the single array-valued plugin option and sanitizer. */
	public static function register_settings() {
		register_setting(
			'media_audit_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);
	}

	/**
	 * Enqueue the application only on its own Tools screen.
	 *
	 * @param string $hook_suffix Current WordPress admin screen hook.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_media-audit' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'media-audit-admin', MEDIA_AUDIT_URL . 'assets/admin.css', array(), MEDIA_AUDIT_VERSION );
		wp_enqueue_script( 'media-audit-admin', MEDIA_AUDIT_URL . 'assets/admin.js', array(), MEDIA_AUDIT_VERSION, true );
		wp_localize_script(
			'media-audit-admin',
			'MediaAudit',
			array(
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'nonce'              => wp_create_nonce( self::NONCE_ACTION ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This core Settings API flag only selects the visible tab.
				'initialTab'         => isset( $_GET['settings-updated'] ) ? 'settings' : 'scan',
				'findings'           => self::get_last_findings(),
				'stats'              => self::get_cleanup_stats(),
				'integrity'          => self::get_integrity_state(),
				'actionBatchSize'    => (int) self::get_settings()['action_batch_size'],
				'actionDelayMs'      => (int) self::get_settings()['action_delay_ms'],
				'consoleDiagnostics' => ! empty( self::get_settings()['console_diagnostics'] ),
				'uiRowBatchSize'     => (int) self::get_settings()['ui_row_batch_size'],
				'i18n'               => array(
					'running'                    => __( 'Scanning uploads and checking references…', 'media-audit' ),
					'stopping'                   => __( 'Stopping after the current batch…', 'media-audit' ),
					'stopped'                    => __( 'Scan stopped. Partial findings have been saved.', 'media-audit' ),
					'working'                    => __( 'Applying the selected action…', 'media-audit' ),
					'quarantining'               => __( 'Moving files to quarantine…', 'media-audit' ),
					'preparingZip'               => __( 'Creating and verifying the backup ZIP…', 'media-audit' ),
					'deletingFiles'              => __( 'Permanently deleting files…', 'media-audit' ),
					'deletingAllFindings'        => __( 'Deleting all findings…', 'media-audit' ),
					'loadingQuarantine'          => __( 'Loading quarantined files…', 'media-audit' ),
					'restoringFile'              => __( 'Restoring quarantined file…', 'media-audit' ),
					'deletingQuarantine'         => __( 'Deleting quarantined files…', 'media-audit' ),
					'filesQueued'                => __( 'files queued; results update after each completed batch.', 'media-audit' ),
					'integrityScanning'          => __( 'Checking Media Library records and local files…', 'media-audit' ),
					'integrityDeleteConfirm'     => __( 'Permanently delete the selected missing-file attachment records using WordPress? Remaining generated files may also be removed. Confirm these are not valid offloaded media. This cannot be undone.', 'media-audit' ),
					'integrityDeleteAllConfirm'  => __( 'Permanently delete every reported missing-file attachment record using WordPress? Remaining generated files may also be removed. This may delete valid offloaded-media records and cannot be undone.', 'media-audit' ),
					'requestFailed'              => __( 'The request failed. Check the server logs and try again.', 'media-audit' ),
					'selectFiles'                => __( 'Select at least one file first.', 'media-audit' ),
					'deleteConfirm'              => __( 'Permanently delete the selected files? This cannot be undone.', 'media-audit' ),
					'backupConfirm'              => __( 'Create and verify a downloadable ZIP, then remove the selected originals?', 'media-audit' ),
					'deleteAllFindingsConfirm'   => __( 'Permanently delete every file in the current findings? This cannot be undone.', 'media-audit' ),
					'quarantineDeleteConfirm'    => __( 'Permanently delete the selected quarantined files? This cannot be undone.', 'media-audit' ),
					'quarantineDeleteAllConfirm' => __( 'Permanently delete every recoverable quarantined file? Backup ZIPs are not included. This cannot be undone.', 'media-audit' ),
					'clearConfirm'               => __( 'Clear the saved results from this screen?', 'media-audit' ),
					'noResults'                  => __( 'No likely stray files were found in this scan.', 'media-audit' ),
					'noMatches'                  => __( 'No results match the current filter.', 'media-audit' ),
					'copied'                     => __( 'Copied!', 'media-audit' ),
					'copyFailed'                 => __( 'Could not copy automatically.', 'media-audit' ),
					'settingsSaved'              => __( 'Settings saved.', 'media-audit' ),
				),
			)
		);
	}

	/**
	 * Return canonical settings used by both the dashboard and CLI.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			'ignore_patterns'     => '',
			'custom_tables'       => '',
			'scan_all_tables'     => 0,
			'quarantine_dir'      => '.media-audit-quarantine',
			'revalidate_actions'  => 1,
			'scan_batch_size'     => 25,
			'action_batch_size'   => 20,
			'action_delay_ms'     => 100,
			'console_diagnostics' => 0,
			'ui_row_batch_size'   => 250,
		);
	}

	/**
	 * Read saved settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::default_settings() );
	}

	/** Return normalized cumulative dashboard cleanup counters. */
	private static function get_cleanup_stats() {
		$defaults = array(
			'reclaimed_bytes'   => 0,
			'removed_files'     => 0,
			'quarantined_files' => 0,
			'restored_files'    => 0,
		);
		$stats    = get_option( self::STATS_OPTION, array() );
		$stats    = wp_parse_args( is_array( $stats ) ? $stats : array(), $defaults );
		foreach ( $defaults as $key => $unused ) {
			$stats[ $key ] = max( 0, (int) $stats[ $key ] );
		}
		return $stats;
	}

	/**
	 * Add successful filesystem result rows to persistent cleanup counters.
	 *
	 * @param array<int,array<string,mixed>> $rows Completed file-action result rows.
	 * @return array<string,int> Updated cleanup statistics.
	 */
	private static function record_cleanup_rows( $rows ) {
		$stats = self::get_cleanup_stats();
		foreach ( (array) $rows as $row ) {
			$action = isset( $row['action'] ) ? (string) $row['action'] : '';
			$size   = isset( $row['size_bytes'] ) ? max( 0, (int) $row['size_bytes'] ) : 0;
			if ( in_array( $action, array( 'deleted', 'backed-up-and-removed' ), true ) ) {
				$stats['reclaimed_bytes'] += $size;
				++$stats['removed_files'];
			} elseif ( 'moved' === $action ) {
				++$stats['quarantined_files'];
			}
		}
		update_option( self::STATS_OPTION, $stats, false );
		return $stats;
	}

	/**
	 * Sanitize settings before WordPress persists them.
	 *
	 * @param mixed $input Raw Settings API payload.
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( $input ) {
		$input          = is_array( $input ) ? $input : array();
		$quarantine_dir = isset( $input['quarantine_dir'] ) ? self::sanitize_relative_path( $input['quarantine_dir'] ) : '';
		return array(
			'ignore_patterns'     => isset( $input['ignore_patterns'] ) ? sanitize_textarea_field( (string) $input['ignore_patterns'] ) : '',
			'custom_tables'       => isset( $input['custom_tables'] ) ? sanitize_text_field( (string) $input['custom_tables'] ) : '',
			'scan_all_tables'     => empty( $input['scan_all_tables'] ) ? 0 : 1,
			'quarantine_dir'      => '' !== $quarantine_dir ? $quarantine_dir : '.media-audit-quarantine',
			'revalidate_actions'  => empty( $input['revalidate_actions'] ) ? 0 : 1,
			'scan_batch_size'     => isset( $input['scan_batch_size'] ) ? max( 1, min( 100, (int) $input['scan_batch_size'] ) ) : 25,
			'action_batch_size'   => isset( $input['action_batch_size'] ) ? max( 1, min( 100, (int) $input['action_batch_size'] ) ) : 20,
			'action_delay_ms'     => isset( $input['action_delay_ms'] ) ? max( 0, min( 3000, (int) $input['action_delay_ms'] ) ) : 100,
			'console_diagnostics' => empty( $input['console_diagnostics'] ) ? 0 : 1,
			'ui_row_batch_size'   => isset( $input['ui_row_batch_size'] ) ? max( 50, min( 1000, (int) $input['ui_row_batch_size'] ) ) : 250,
		);
	}

	/** Render the dashboard shell; JavaScript renders dynamic findings. */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get_settings();
		?>
		<div class="wrap media-audit-wrap">
			<header class="media-audit-hero">
				<div><p class="media-audit-eyebrow"><?php esc_html_e( 'UPLOADS INTELLIGENCE', 'media-audit' ); ?></p><h1><?php esc_html_e( 'Media Audit', 'media-audit' ); ?></h1><p><?php esc_html_e( 'Find files that appear to be unreferenced, inspect the evidence, then quarantine them before considering permanent deletion.', 'media-audit' ); ?></p></div>
				<div class="media-audit-safety"><span class="dashicons dashicons-shield"></span><strong><?php esc_html_e( 'Safety first', 'media-audit' ); ?></strong><span><?php esc_html_e( 'Results are candidates, never a guarantee.', 'media-audit' ); ?></span></div>
			</header>
			<nav class="nav-tab-wrapper media-audit-tabs" aria-label="<?php esc_attr_e( 'Media Audit sections', 'media-audit' ); ?>"><button type="button" class="nav-tab nav-tab-active" data-tab="scan"><?php esc_html_e( 'Scan & results', 'media-audit' ); ?></button><button type="button" class="nav-tab" data-tab="integrity"><?php esc_html_e( 'Library integrity', 'media-audit' ); ?></button><button type="button" class="nav-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'media-audit' ); ?></button><button type="button" class="nav-tab" data-tab="cli"><?php esc_html_e( 'CLI reference', 'media-audit' ); ?></button><button type="button" class="nav-tab" data-tab="help"><?php esc_html_e( 'How it works', 'media-audit' ); ?></button></nav>

			<section class="media-audit-panel is-active" data-panel="scan">
				<section id="media-audit-impact-panel" class="media-audit-card media-audit-impact-panel" aria-labelledby="media-audit-impact-title" hidden><div class="media-audit-impact-head"><div><p class="media-audit-eyebrow"><?php esc_html_e( 'STORAGE IMPACT', 'media-audit' ); ?></p><h2 id="media-audit-impact-title"><?php esc_html_e( 'Cleanup statistics', 'media-audit' ); ?></h2><p><?php esc_html_e( 'Cumulative dashboard actions tracked from version 0.11.0 onward. Quarantine moves do not count as reclaimed space.', 'media-audit' ); ?></p></div></div><div id="media-audit-impact-stats" class="media-audit-stats media-audit-impact-stats"></div></section>
				<div class="media-audit-grid">
					<div class="media-audit-card media-audit-scan-card">
						<div class="media-audit-card-heading"><div><h2><?php esc_html_e( 'Run a new audit', 'media-audit' ); ?></h2><p><?php esc_html_e( 'Start narrow, review the findings, then broaden the scan.', 'media-audit' ); ?></p></div><span class="dashicons dashicons-search"></span></div>
						<form id="media-audit-run-form">
							<div class="media-audit-field-row"><label><span><?php esc_html_e( 'Uploads subdirectory', 'media-audit' ); ?></span><input type="text" name="uploads_subdir" placeholder="2026/08" /><small><?php esc_html_e( 'Optional; relative to uploads.', 'media-audit' ); ?></small></label><label><span><?php esc_html_e( 'Database check limit', 'media-audit' ); ?></span><input type="number" name="limit" min="0" value="0" /><small><?php esc_html_e( '0 checks every candidate.', 'media-audit' ); ?></small></label></div>
							<div class="media-audit-options"><label><input type="checkbox" name="all_files" value="1" /> <span><?php esc_html_e( 'Include non-media files', 'media-audit' ); ?></span></label><label><input type="checkbox" name="skip_db_check" value="1" /> <span><?php esc_html_e( 'Fast scan (skip database text references)', 'media-audit' ); ?></span></label></div>
							<button type="submit" class="button button-primary button-hero"><span class="dashicons dashicons-search"></span><?php esc_html_e( 'Run audit', 'media-audit' ); ?></button>
						</form>
					</div>
					<aside class="media-audit-card media-audit-guidance"><h2><?php esc_html_e( 'Recommended workflow', 'media-audit' ); ?></h2><ol><li><?php esc_html_e( 'Scan a recent year or month first.', 'media-audit' ); ?></li><li><?php esc_html_e( 'Review and dry-run selected candidates.', 'media-audit' ); ?></li><li><?php esc_html_e( 'Quarantine before permanently deleting.', 'media-audit' ); ?></li></ol></aside>
				</div>
				<div id="media-audit-progress" class="media-audit-progress" hidden><span class="spinner is-active"></span><strong></strong><button type="button" class="button" id="media-audit-stop"><?php esc_html_e( 'Stop scan', 'media-audit' ); ?></button><div><i></i></div><small><?php esc_html_e( 'Stopping preserves all findings completed through the latest batch.', 'media-audit' ); ?></small></div>
				<div class="media-audit-section-notice" data-notice-section="scan" aria-live="polite"></div>

				<section id="media-audit-results" class="media-audit-results" hidden>
					<div class="media-audit-results-head"><div><p class="media-audit-eyebrow"><?php esc_html_e( 'LATEST RUN', 'media-audit' ); ?></p><div class="media-audit-title-line"><h2><?php esc_html_e( 'Audit results', 'media-audit' ); ?></h2><span id="media-audit-result-status" class="media-audit-status"></span></div><p id="media-audit-run-meta"></p></div><button type="button" class="button" id="media-audit-clear"><?php esc_html_e( 'Clear results', 'media-audit' ); ?></button></div><div id="media-audit-partial-warning" class="notice notice-warning inline" hidden><p><?php esc_html_e( 'This is a partial result. Only candidates processed before the scan stopped are listed; unprocessed files have not been classified.', 'media-audit' ); ?></p></div>
					<div class="media-audit-stats" id="media-audit-stats"></div>
					<div class="media-audit-toolbar"><label class="media-audit-search"><span class="dashicons dashicons-search"></span><input type="search" id="media-audit-filter" placeholder="<?php esc_attr_e( 'Filter by path, type, or reason…', 'media-audit' ); ?>" /></label><label><?php esc_html_e( 'Sort', 'media-audit' ); ?><select id="media-audit-sort"><option value="path"><?php esc_html_e( 'Path', 'media-audit' ); ?></option><option value="size_desc"><?php esc_html_e( 'Largest first', 'media-audit' ); ?></option><option value="modified_desc"><?php esc_html_e( 'Newest first', 'media-audit' ); ?></option><option value="modified_asc"><?php esc_html_e( 'Oldest first', 'media-audit' ); ?></option></select></label><span id="media-audit-selection-count"></span></div>
					<div class="media-audit-table-wrap"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" id="media-audit-select-all" aria-label="<?php esc_attr_e( 'Select all visible files', 'media-audit' ); ?>" /></td><th><?php esc_html_e( 'File', 'media-audit' ); ?></th><th><?php esc_html_e( 'Size', 'media-audit' ); ?></th><th><?php esc_html_e( 'Modified (UTC)', 'media-audit' ); ?></th><th><?php esc_html_e( 'Finding', 'media-audit' ); ?></th></tr></thead><tbody id="media-audit-result-rows"></tbody></table></div>
					<div id="media-audit-table-footer" class="media-audit-table-footer" hidden><span id="media-audit-table-status"></span><button type="button" class="button button-small" id="media-audit-load-more"><?php esc_html_e( 'Load more', 'media-audit' ); ?></button></div>
					<div id="media-audit-empty" class="media-audit-empty" hidden></div>
					<div class="media-audit-action-bar"><label><input type="checkbox" id="media-audit-dry-run" /> <?php esc_html_e( 'Dry run only', 'media-audit' ); ?></label><div><button type="button" class="button" data-file-action="quarantine" title="<?php esc_attr_e( 'Move files out of their public uploads paths while keeping them available for restoration.', 'media-audit' ); ?>"><span class="dashicons dashicons-archive"></span><?php esc_html_e( 'Move to quarantine', 'media-audit' ); ?></button><button type="button" class="button" data-file-action="backup-delete"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Download ZIP & remove', 'media-audit' ); ?></button><button type="button" class="button media-audit-delete" data-file-action="delete"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Delete selected', 'media-audit' ); ?></button><button type="button" class="button media-audit-delete" data-file-action="delete" data-file-scope="all"><span class="dashicons dashicons-trash"></span><?php esc_html_e( 'Delete all findings', 'media-audit' ); ?></button></div></div>
					<p class="media-audit-action-help"><?php esc_html_e( 'Quarantine moves files out of their original uploads paths without deleting them. They can be restored below if the original path is still available.', 'media-audit' ); ?></p>
					<?php
					if ( empty( $settings['revalidate_actions'] ) ) :
						?>
						<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Reference revalidation is disabled.', 'media-audit' ); ?></strong> <?php esc_html_e( 'Actions still validate saved findings and safe uploads paths, but references added after the scan will not be detected.', 'media-audit' ); ?></p></div><?php endif; ?>
					<div id="media-audit-action-progress" class="media-audit-operation-progress" hidden aria-live="polite"><span class="spinner is-active"></span><div><strong></strong><small></small></div><i></i></div>
					<div id="media-audit-action-results" hidden></div>
				</section>
				<section class="media-audit-card media-audit-quarantine-panel" aria-labelledby="media-audit-quarantine-title"><div class="media-audit-quarantine-head"><div><p class="media-audit-eyebrow"><?php esc_html_e( 'RECOVERY', 'media-audit' ); ?></p><h2 id="media-audit-quarantine-title"><?php esc_html_e( 'Quarantined files', 'media-audit' ); ?></h2><p><?php esc_html_e( 'Restore files to their original uploads paths or permanently remove them. Existing files are never overwritten.', 'media-audit' ); ?></p></div><div class="media-audit-quarantine-actions"><button type="button" class="button" id="media-audit-refresh-quarantine"><?php esc_html_e( 'Refresh', 'media-audit' ); ?></button><button type="button" class="button media-audit-delete" id="media-audit-delete-quarantine-selected" disabled><?php esc_html_e( 'Delete selected', 'media-audit' ); ?></button><button type="button" class="button media-audit-delete" id="media-audit-delete-quarantine-all" disabled><?php esc_html_e( 'Delete all', 'media-audit' ); ?></button></div></div><div class="media-audit-section-notice" data-notice-section="quarantine" aria-live="polite"></div><div id="media-audit-quarantine-progress" class="media-audit-operation-progress" hidden aria-live="polite"><span class="spinner is-active"></span><div><strong></strong><small><?php esc_html_e( 'Please keep this page open until the operation finishes.', 'media-audit' ); ?></small></div><i></i></div><div id="media-audit-quarantine-content"><p class="description"><?php esc_html_e( 'Loading quarantined files…', 'media-audit' ); ?></p></div></section>
			</section>

			<section class="media-audit-panel" data-panel="integrity">
				<section class="media-audit-card media-audit-integrity-intro" aria-labelledby="media-audit-integrity-title">
					<div class="media-audit-integrity-head">
						<div><p class="media-audit-eyebrow"><?php esc_html_e( 'DATABASE HYGIENE', 'media-audit' ); ?></p><h2 id="media-audit-integrity-title"><?php esc_html_e( 'Media Library integrity', 'media-audit' ); ?></h2><p><?php esc_html_e( 'Find attachment records whose local original file is missing, plus generated image sizes that are absent while their original still exists.', 'media-audit' ); ?></p></div>
						<div class="media-audit-integrity-run-actions"><button type="button" class="button button-primary" id="media-audit-integrity-run"><span class="dashicons dashicons-update"></span><?php esc_html_e( 'Check Media Library', 'media-audit' ); ?></button><button type="button" class="button" id="media-audit-integrity-stop" hidden><?php esc_html_e( 'Stop check', 'media-audit' ); ?></button></div>
					</div>
					<div class="media-audit-section-notice" data-notice-section="integrity" aria-live="polite"></div>
					<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Using offloaded media?', 'media-audit' ); ?></strong> <?php esc_html_e( 'S3, CDN, and other offload plugins may intentionally remove local files. Do not delete reported attachment records until you have confirmed they are not valid remote media.', 'media-audit' ); ?></p></div>
					<div id="media-audit-integrity-progress" class="media-audit-operation-progress" hidden aria-live="polite"><span class="spinner is-active"></span><div><strong></strong><small><?php esc_html_e( 'Please keep this page open until the current operation finishes.', 'media-audit' ); ?></small></div><i></i></div>
				</section>

				<section id="media-audit-integrity-results" class="media-audit-card media-audit-integrity-results" hidden>
					<div class="media-audit-integrity-results-head"><div><p class="media-audit-eyebrow"><?php esc_html_e( 'LATEST CHECK', 'media-audit' ); ?></p><h2><?php esc_html_e( 'Integrity results', 'media-audit' ); ?></h2><p id="media-audit-integrity-meta"></p></div></div>
					<div id="media-audit-integrity-stats" class="media-audit-stats media-audit-integrity-stats"></div>

					<div class="media-audit-integrity-section-head"><div><h3><?php esc_html_e( 'Missing local originals', 'media-audit' ); ?></h3><p><?php esc_html_e( 'These database records can be removed through WordPress after review. The original is checked again immediately before deletion.', 'media-audit' ); ?></p></div><span id="media-audit-integrity-selection"></span></div>
					<div class="media-audit-table-wrap media-audit-integrity-table-wrap"><table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" id="media-audit-integrity-select-all" aria-label="<?php esc_attr_e( 'Select all missing-original records shown', 'media-audit' ); ?>" /></td><th><?php esc_html_e( 'Attachment', 'media-audit' ); ?></th><th><?php esc_html_e( 'Expected file', 'media-audit' ); ?></th><th><?php esc_html_e( 'Remaining local files', 'media-audit' ); ?></th></tr></thead><tbody id="media-audit-integrity-rows"></tbody></table></div><div id="media-audit-integrity-footer" class="media-audit-table-footer" hidden><span id="media-audit-integrity-row-status"></span><button type="button" class="button button-small" id="media-audit-integrity-load-more"><?php esc_html_e( 'Load more', 'media-audit' ); ?></button></div>
					<div id="media-audit-integrity-empty" class="media-audit-empty" hidden><?php esc_html_e( 'No attachment records with missing local originals were found.', 'media-audit' ); ?></div>
					<div class="media-audit-integrity-actions"><p><?php esc_html_e( 'Deletion uses wp_delete_attachment(), so WordPress hooks, metadata cleanup, and removal of known generated files are honored.', 'media-audit' ); ?></p><div><button type="button" class="button media-audit-delete" id="media-audit-integrity-delete-selected" disabled><?php esc_html_e( 'Delete selected records', 'media-audit' ); ?></button><button type="button" class="button media-audit-delete" id="media-audit-integrity-delete-all" disabled><?php esc_html_e( 'Delete all missing records', 'media-audit' ); ?></button></div></div>
					<div id="media-audit-integrity-action-results" hidden></div>

					<div class="media-audit-integrity-section-head media-audit-integrity-variants-head"><div><h3><?php esc_html_e( 'Missing generated files', 'media-audit' ); ?></h3><p><?php esc_html_e( 'Report only: the original exists, but one or more sizes recorded in attachment metadata do not. Regenerate thumbnails after confirming the source image is healthy.', 'media-audit' ); ?></p></div></div>
					<div class="media-audit-table-wrap media-audit-integrity-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Attachment', 'media-audit' ); ?></th><th><?php esc_html_e( 'Original', 'media-audit' ); ?></th><th><?php esc_html_e( 'Missing generated files', 'media-audit' ); ?></th></tr></thead><tbody id="media-audit-integrity-variant-rows"></tbody></table></div><div id="media-audit-integrity-variant-footer" class="media-audit-table-footer" hidden><span id="media-audit-integrity-variant-status"></span><button type="button" class="button button-small" id="media-audit-integrity-variant-load-more"><?php esc_html_e( 'Load more', 'media-audit' ); ?></button></div>
					<div id="media-audit-integrity-variants-empty" class="media-audit-empty" hidden><?php esc_html_e( 'No missing generated files were found.', 'media-audit' ); ?></div>
				</section>
			</section>

			<section class="media-audit-panel" data-panel="settings"><div class="media-audit-card media-audit-settings-card"><div class="media-audit-section-notice" data-notice-section="settings" aria-live="polite"><?php settings_errors( self::OPTION_KEY ); ?></div><h2><?php esc_html_e( 'Reference and safety settings', 'media-audit' ); ?></h2><form method="post" action="options.php"><?php settings_fields( 'media_audit_settings_group' ); ?><label><span><?php esc_html_e( 'Ignore patterns', 'media-audit' ); ?></span><textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ignore_patterns]" rows="8" class="large-text code"><?php echo esc_textarea( (string) $settings['ignore_patterns'] ); ?></textarea><small><?php esc_html_e( 'One glob per line, for example: cache/*, tmp/, *.webp', 'media-audit' ); ?></small></label><label><span><?php esc_html_e( 'Custom table checks', 'media-audit' ); ?></span><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_tables]" value="<?php echo esc_attr( (string) $settings['custom_tables'] ); ?>" class="large-text code" /><small><?php esc_html_e( 'Comma-separated table:column pairs. The current WordPress prefix can be omitted.', 'media-audit' ); ?></small></label><label><span><?php esc_html_e( 'Quarantine directory', 'media-audit' ); ?></span><input type="text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[quarantine_dir]" value="<?php echo esc_attr( (string) $settings['quarantine_dir'] ); ?>" class="regular-text code" /><small><?php esc_html_e( 'A relative path under uploads.', 'media-audit' ); ?></small></label><label class="media-audit-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scan_all_tables]" value="1" <?php checked( (int) $settings['scan_all_tables'], 1 ); ?> /><span><strong><?php esc_html_e( 'Scan all non-core tables', 'media-audit' ); ?></strong><small><?php esc_html_e( 'Checks text-like columns automatically. Accurate but potentially slow.', 'media-audit' ); ?></small></span></label><h3><?php esc_html_e( 'Slow-server and browser tuning', 'media-audit' ); ?></h3><div class="media-audit-tuning-grid"><label><span><?php esc_html_e( 'Scan batch size', 'media-audit' ); ?></span><input type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[scan_batch_size]" value="<?php echo esc_attr( (string) $settings['scan_batch_size'] ); ?>" /><small><?php esc_html_e( 'Candidates checked per scan request. Lower this if scans time out.', 'media-audit' ); ?></small></label><label><span><?php esc_html_e( 'File-action batch size', 'media-audit' ); ?></span><input type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[action_batch_size]" value="<?php echo esc_attr( (string) $settings['action_batch_size'] ); ?>" /><small><?php esc_html_e( 'Files deleted or quarantined per request.', 'media-audit' ); ?></small></label><label><span><?php esc_html_e( 'Pause between batches (ms)', 'media-audit' ); ?></span><input type="number" min="0" max="3000" step="50" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[action_delay_ms]" value="<?php echo esc_attr( (string) $settings['action_delay_ms'] ); ?>" /><small><?php esc_html_e( 'A short pause reduces sustained load on slower servers.', 'media-audit' ); ?></small></label><label><span><?php esc_html_e( 'Rows rendered per view', 'media-audit' ); ?></span><input type="number" min="50" max="1000" step="50" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ui_row_batch_size]" value="<?php echo esc_attr( (string) $settings['ui_row_batch_size'] ); ?>" /><small><?php esc_html_e( 'Lower values reduce browser memory and layout work for long lists.', 'media-audit' ); ?></small></label></div><label class="media-audit-check media-audit-warning-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[revalidate_actions]" value="1" <?php checked( (int) $settings['revalidate_actions'], 1 ); ?> /><span><strong><?php esc_html_e( 'Revalidate references before file actions', 'media-audit' ); ?></strong><small><?php esc_html_e( 'Recommended. Disable only when speed is more important than detecting references added since the scan. Path and saved-finding checks still apply.', 'media-audit' ); ?></small></span></label><label class="media-audit-check"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[console_diagnostics]" value="1" <?php checked( (int) $settings['console_diagnostics'], 1 ); ?> /><span><strong><?php esc_html_e( 'Browser console diagnostics', 'media-audit' ); ?></strong><small><?php esc_html_e( 'Off by default. Enable temporarily to log scan batches, heartbeats, errors, and capped file-action samples in browser developer tools.', 'media-audit' ); ?></small></span></label><?php submit_button( __( 'Save settings', 'media-audit' ) ); ?></form></div></section>

			<section class="media-audit-panel" data-panel="cli"><?php self::render_cli_reference(); ?></section>

			<section class="media-audit-panel" data-panel="help"><div class="media-audit-card media-audit-help"><h2><?php esc_html_e( 'How confidence is built', 'media-audit' ); ?></h2><div class="media-audit-steps"><div><b>1</b><h3><?php esc_html_e( 'Inventory', 'media-audit' ); ?></h3><p><?php esc_html_e( 'Files are collected from the selected uploads scope.', 'media-audit' ); ?></p></div><div><b>2</b><h3><?php esc_html_e( 'Attachment match', 'media-audit' ); ?></h3><p><?php esc_html_e( 'Originals, generated sizes, edited copies, and backup sizes are indexed.', 'media-audit' ); ?></p></div><div><b>3</b><h3><?php esc_html_e( 'Reference search', 'media-audit' ); ?></h3><p><?php esc_html_e( 'Core content, metadata, options, and configured custom tables are searched.', 'media-audit' ); ?></p></div><div><b>4</b><h3><?php esc_html_e( 'Candidate review', 'media-audit' ); ?></h3><p><?php esc_html_e( 'Only files without a detected reference are shown for human review.', 'media-audit' ); ?></p></div></div><div class="notice notice-warning inline"><p><?php esc_html_e( 'Encoded data, external systems, theme files, and hardcoded references can evade detection. Keep backups and prefer quarantine.', 'media-audit' ); ?></p></div></div></section>
		</div>
		<?php
	}

	/** Render the complete WP-CLI command reference from maintained data maps. */
	private static function render_cli_reference() {
		$options  = array(
			'--uploads-subdir=<path>' => __( 'Restrict the scan to a relative directory below uploads, such as 2026/08.', 'media-audit' ),
			'--limit=<number>'        => __( 'Maximum candidates to database-check. Use 0 for unlimited.', 'media-audit' ),
			'--min-size=<kb>'         => __( 'Only report candidates at least this many kilobytes.', 'media-audit' ),
			'--older-than=<days>'     => __( 'Only report candidates older than this many days.', 'media-audit' ),
			'--all-files'             => __( 'Include extensions outside the WordPress media MIME map.', 'media-audit' ),
			'--skip-db-check'         => __( 'Skip textual database searches and use attachment metadata only.', 'media-audit' ),
			'--ignore=<patterns>'     => __( 'Add comma-separated glob patterns.', 'media-audit' ),
			'--ignore-file=<path>'    => __( 'Read newline-separated ignore patterns from a file.', 'media-audit' ),
			'--custom-tables=<list>'  => __( 'Add comma-separated table:column reference checks.', 'media-audit' ),
			'--scan-all-tables'       => __( 'Search detected text columns in all non-core tables.', 'media-audit' ),
			'--format=<format>'       => __( 'Render findings as table, csv, json, or yaml.', 'media-audit' ),
			'--summary-only'          => __( 'Print summary metrics without individual finding rows.', 'media-audit' ),
			'--quarantine'            => __( 'Move findings into a timestamped quarantine directory.', 'media-audit' ),
			'--backup-delete'         => __( 'Copy and SHA-256 verify findings, then remove originals.', 'media-audit' ),
			'--delete'                => __( 'Permanently remove findings.', 'media-audit' ),
			'--dry-run'               => __( 'Simulate the selected filesystem action.', 'media-audit' ),
			'--yes'                   => __( 'Confirm a real backup-and-remove or permanent deletion.', 'media-audit' ),
			'--quarantine-dir=<path>' => __( 'Override the relative safety directory below uploads.', 'media-audit' ),
			'--fail-on-findings'      => __( 'Exit with status 1 when likely stray files are found.', 'media-audit' ),
		);
		$examples = array(
			array( __( 'Standard audit', 'media-audit' ), 'wp gp media-audit' ),
			array( __( 'Scan one year as JSON', 'media-audit' ), 'wp gp media-audit --uploads-subdir=2025 --format=json' ),
			array( __( 'Find older, larger candidates', 'media-audit' ), 'wp gp media-audit --older-than=90 --min-size=100' ),
			array( __( 'Use custom reference tables', 'media-audit' ), 'wp gp media-audit --custom-tables="plugin_assets:url,other_table:data"' ),
			array( __( 'Load ignore rules from a file', 'media-audit' ), 'wp gp media-audit --ignore-file=/path/to/media-audit.ignore' ),
			array( __( 'Fast attachment-only scan', 'media-audit' ), 'wp gp media-audit --skip-db-check --summary-only' ),
			array( __( 'Preview quarantine', 'media-audit' ), 'wp gp media-audit --quarantine --dry-run' ),
			array( __( 'Quarantine findings', 'media-audit' ), 'wp gp media-audit --quarantine' ),
			array( __( 'Preview verified backup and removal', 'media-audit' ), 'wp gp media-audit --backup-delete --dry-run' ),
			array( __( 'Run verified backup and removal', 'media-audit' ), 'wp gp media-audit --backup-delete --yes' ),
			array( __( 'Preview permanent deletion', 'media-audit' ), 'wp gp media-audit --delete --dry-run' ),
			array( __( 'Permanently delete findings', 'media-audit' ), 'wp gp media-audit --delete --yes' ),
			array( __( 'CI or scheduled audit', 'media-audit' ), 'wp gp media-audit --summary-only --fail-on-findings' ),
			array( __( 'Show built-in WP-CLI help', 'media-audit' ), 'wp help gp media-audit' ),
		);
		?>
		<div class="media-audit-card media-audit-cli">
			<div class="media-audit-cli-heading"><div><p class="media-audit-eyebrow"><?php esc_html_e( 'DEVELOPER REFERENCE', 'media-audit' ); ?></p><h2><?php esc_html_e( 'WP-CLI commands', 'media-audit' ); ?></h2><p><?php esc_html_e( 'Run audits, produce machine-readable reports, and perform guarded filesystem actions from the terminal.', 'media-audit' ); ?></p></div><span class="dashicons dashicons-editor-code"></span></div>
			<div class="media-audit-section-notice" data-notice-section="cli" aria-live="polite"></div>
			<div class="media-audit-cli-syntax"><span><?php esc_html_e( 'Base command', 'media-audit' ); ?></span><code>wp gp media-audit [options]</code><button type="button" class="button" data-copy-command="wp gp media-audit"><?php esc_html_e( 'Copy', 'media-audit' ); ?></button></div>
			<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Safety:', 'media-audit' ); ?></strong> <?php esc_html_e( 'Use --dry-run first. Before every action, attachment metadata and database references are checked again. Files that are now referenced are blocked. --backup-delete and --delete require --yes when they make real changes.', 'media-audit' ); ?></p></div>

			<h3><?php esc_html_e( 'All options', 'media-audit' ); ?></h3>
			<div class="media-audit-cli-options"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Option', 'media-audit' ); ?></th><th><?php esc_html_e( 'Description', 'media-audit' ); ?></th></tr></thead><tbody>
			<?php
			foreach ( $options as $option => $description ) :
				?>
				<tr><td><code><?php echo esc_html( $option ); ?></code></td><td><?php echo esc_html( $description ); ?></td></tr><?php endforeach; ?></tbody></table></div>

			<h3><?php esc_html_e( 'Copy-ready recipes', 'media-audit' ); ?></h3>
			<div class="media-audit-cli-examples">
			<?php
			foreach ( $examples as $example ) :
				?>
				<div><span><?php echo esc_html( $example[0] ); ?></span><code><?php echo esc_html( $example[1] ); ?></code><button type="button" class="button button-small" data-copy-command="<?php echo esc_attr( $example[1] ); ?>"><?php esc_html_e( 'Copy', 'media-audit' ); ?></button></div><?php endforeach; ?></div>
			<p class="description"><?php esc_html_e( 'Global WP-CLI parameters such as --path, --url, --user, --ssh, and --quiet may also be appended.', 'media-audit' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Legacy one-request audit endpoint retained for backward compatibility.
	 *
	 * New dashboard clients use start/step/stop so long database passes can be
	 * observed and cancelled without discarding completed work.
	 */
	public static function ajax_run_audit() {
		self::verify_ajax_request();
		$args     = self::get_audit_request_args();
		$runner   = new Media_Audit_CLI_Command();
		$findings = $runner->get_audit_results( $args );
		if ( ! empty( $findings['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $findings['error'] ), 400 );
		}
		$findings['completed_at'] = current_time( 'mysql' );
		self::save_last_findings( $findings );
		wp_send_json_success(
			array(
				'message'  => __( 'Audit complete.', 'media-audit' ),
				'findings' => $findings,
			)
		);
	}

	/**
	 * Start a persistent, resumable AJAX scan job.
	 *
	 * The preparation request inventories files and removes attachment matches.
	 * It stores only remaining candidates, counters, and query configuration.
	 */
	public static function ajax_start_audit() {
		self::verify_ajax_request();
		$runner = new Media_Audit_CLI_Command();
		$job    = $runner->prepare_audit_job( self::get_audit_request_args() );
		if ( ! empty( $job['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $job['error'] ), 400 );
		}

		$token = strtolower( wp_generate_password( 20, false, false ) );
		set_transient( self::get_job_key( $token ), $job, 30 * MINUTE_IN_SECONDS );
		delete_transient( self::get_stop_key( $token ) );
		$findings = $runner->finalize_audit_job( $job );
		if ( ! empty( $job['completed'] ) ) {
			$findings['completed_at'] = current_time( 'mysql' );
			self::save_last_findings( $findings );
			delete_transient( self::get_job_key( $token ) );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Scan inventory prepared.', 'media-audit' ),
				'token'    => $token,
				'done'     => ! empty( $job['completed'] ),
				'findings' => $findings,
			)
		);
	}

	/**
	 * Process one bounded batch of an AJAX scan.
	 *
	 * A stop marker is checked before and after processing to close the race where
	 * a cancellation request arrives while this PHP request is already running.
	 */
	public static function ajax_step_audit() {
		self::verify_ajax_request();
		$token   = self::get_request_token();
		$job_key = self::get_job_key( $token );
		$job     = get_transient( $job_key );
		if ( ! is_array( $job ) ) {
			wp_send_json_error( array( 'message' => __( 'The scan job expired or was already stopped.', 'media-audit' ) ), 410 );
		}

		$runner = new Media_Audit_CLI_Command();
		if ( get_transient( self::get_stop_key( $token ) ) ) {
			$job['stopped'] = true;
		} else {
			$settings = self::get_settings();
			$job      = $runner->process_audit_job_batch( $job, (int) $settings['scan_batch_size'] );
			if ( get_transient( self::get_stop_key( $token ) ) ) {
				$job['stopped'] = true;
			}
		}

		$done     = ! empty( $job['completed'] ) || ! empty( $job['stopped'] );
		$findings = $runner->finalize_audit_job( $job );
		if ( $done ) {
			$findings['completed_at'] = current_time( 'mysql' );
			self::save_last_findings( $findings );
			delete_transient( $job_key );
			delete_transient( self::get_stop_key( $token ) );
		} else {
			set_transient( $job_key, $job, 30 * MINUTE_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'message'  => ! empty( $job['stopped'] ) ? __( 'Scan stopped. Partial findings saved.', 'media-audit' ) : ( $done ? __( 'Audit complete.', 'media-audit' ) : __( 'Scan batch complete.', 'media-audit' ) ),
				'done'     => $done,
				'stopped'  => ! empty( $job['stopped'] ),
				'findings' => $findings,
			)
		);
	}

	/**
	 * Request cancellation and save results processed through the latest batch.
	 *
	 * Stopped findings are explicitly marked partial. They remain actionable only
	 * after the engine performs its independent last-moment reference validation.
	 */
	public static function ajax_stop_audit() {
		self::verify_ajax_request();
		$token = self::get_request_token();
		set_transient( self::get_stop_key( $token ), 1, MINUTE_IN_SECONDS );
		$job_key = self::get_job_key( $token );
		$job     = get_transient( $job_key );
		if ( ! is_array( $job ) ) {
			wp_send_json_success(
				array(
					'message'  => __( 'The scan has stopped.', 'media-audit' ),
					'done'     => true,
					'stopped'  => true,
					'findings' => self::get_last_findings(),
				)
			);
		}

		$job['stopped']           = true;
		$runner                   = new Media_Audit_CLI_Command();
		$findings                 = $runner->finalize_audit_job( $job );
		$findings['completed_at'] = current_time( 'mysql' );
		self::save_last_findings( $findings );
		delete_transient( $job_key );
		wp_send_json_success(
			array(
				'message'  => __( 'Scan stopped. Partial findings saved.', 'media-audit' ),
				'done'     => true,
				'stopped'  => true,
				'findings' => $findings,
			)
		);
	}

	/** Start a fresh, user-scoped audit of attachment records and local files. */
	public static function ajax_integrity_start() {
		self::verify_ajax_request();
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fresh count starts each user-requested integrity scan.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN (%s, %s)",
				'attachment',
				'trash',
				'auto-draft'
			)
		);
		$state = array(
			'processed'         => 0,
			'total'             => $total,
			'last_id'           => 0,
			'missing_originals' => array(),
			'missing_variants'  => array(),
			'completed'         => 0 === $total,
			'started_at'        => current_time( 'mysql' ),
			'completed_at'      => 0 === $total ? current_time( 'mysql' ) : '',
		);
		self::save_integrity_state( $state );
		wp_send_json_success(
			array(
				'message'   => 0 === $total ? __( 'The Media Library is empty.', 'media-audit' ) : __( 'Media Library check started.', 'media-audit' ),
				'done'      => 0 === $total,
				'integrity' => self::normalize_integrity_state( $state ),
			)
		);
	}

	/** Process one bounded attachment-integrity batch. */
	public static function ajax_integrity_step() {
		self::verify_ajax_request();
		$state = self::get_integrity_state();
		if ( empty( $state ) || ! empty( $state['completed'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No Media Library check is waiting to continue.', 'media-audit' ) ), 410 );
		}

		global $wpdb;
		$batch_size = (int) self::get_settings()['scan_batch_size'];
		$last_id    = isset( $state['last_id'] ) ? max( 0, (int) $state['last_id'] ) : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cursor batches must reflect current attachment records.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN (%s, %s) AND ID > %d ORDER BY ID ASC LIMIT %d",
				'attachment',
				'trash',
				'auto-draft',
				$last_id,
				$batch_size
			)
		);

		$new_missing_originals = array();
		$new_missing_variants  = array();
		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$inspection    = self::inspect_attachment_integrity( $attachment_id );
			if ( ! empty( $inspection['missing_original'] ) ) {
				$state['missing_originals'][] = $inspection['missing_original'];
				$new_missing_originals[]      = $inspection['missing_original'];
			} elseif ( ! empty( $inspection['missing_variants'] ) ) {
				$state['missing_variants'][] = $inspection['missing_variants'];
				$new_missing_variants[]      = $inspection['missing_variants'];
			}
			$state['last_id'] = $attachment_id;
		}

		$state['processed'] = min( (int) $state['total'], (int) $state['processed'] + count( $ids ) );
		$done               = count( $ids ) < $batch_size || (int) $state['processed'] >= (int) $state['total'];
		if ( $done ) {
			$state['completed']    = true;
			$state['completed_at'] = current_time( 'mysql' );
		}
		self::save_integrity_state( $state );

		wp_send_json_success(
			array(
				'message'           => $done ? __( 'Media Library integrity check complete.', 'media-audit' ) : __( 'Media Library batch checked.', 'media-audit' ),
				'done'              => $done,
				'progress'          => self::get_integrity_progress( $state ),
				'missing_originals' => $new_missing_originals,
				'missing_variants'  => $new_missing_variants,
			)
		);
	}

	/**
	 * Delete saved missing-original attachment records through WordPress.
	 *
	 * IDs supplied by the browser are identifiers only. Each one must still be in
	 * the server-owned findings and its original must still be absent immediately
	 * before wp_delete_attachment() runs.
	 */
	public static function ajax_integrity_delete() {
		self::verify_ajax_request();
		$state   = self::get_integrity_state();
		$missing = isset( $state['missing_originals'] ) && is_array( $state['missing_originals'] ) ? $state['missing_originals'] : array();
		$allowed = array();
		foreach ( $missing as $row ) {
			if ( ! empty( $row['id'] ) ) {
				$allowed[ (int) $row['id'] ] = $row;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by self::verify_ajax_request() above.
		$requested = isset( $_POST['attachment_ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['attachment_ids'] ) ) : array();
		$ids       = array();
		foreach ( $requested as $requested_id ) {
			$id = absint( $requested_id );
			if ( $id && isset( $allowed[ $id ] ) ) {
				$ids[ $id ] = $id;
			}
		}
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid missing-file attachment records were selected.', 'media-audit' ) ), 400 );
		}

		$rows              = array();
		$remove_from_state = array();
		$removed_files     = 0;
		$reclaimed_bytes   = 0;
		foreach ( $ids as $attachment_id ) {
			$saved = $allowed[ $attachment_id ];
			$post  = get_post( $attachment_id );
			$title = isset( $saved['title'] ) ? (string) $saved['title'] : sprintf(
				/* translators: %d: attachment post ID. */
				__( 'Attachment #%d', 'media-audit' ),
				$attachment_id
			);
			if ( ! $post ) {
				$remove_from_state[ $attachment_id ] = true;
				$rows[]                              = array(
					'id'      => $attachment_id,
					'title'   => $title,
					'action'  => 'already-removed',
					'message' => __( 'The attachment record no longer exists.', 'media-audit' ),
				);
				continue;
			}
			if ( 'attachment' !== $post->post_type ) {
				$remove_from_state[ $attachment_id ] = true;
				$rows[]                              = array(
					'id'      => $attachment_id,
					'title'   => $title,
					'action'  => 'blocked',
					'message' => __( 'The record is no longer an attachment.', 'media-audit' ),
				);
				continue;
			}

			$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$original = get_attached_file( $attachment_id, true );
			if ( '' !== $attached && is_string( $original ) && '' !== $original && is_file( $original ) ) {
				$remove_from_state[ $attachment_id ] = true;
				$rows[]                              = array(
					'id'      => $attachment_id,
					'title'   => $title,
					'action'  => 'blocked',
					'message' => __( 'Deletion blocked because the local original now exists.', 'media-audit' ),
				);
				continue;
			}

			$before_files = self::get_existing_attachment_files( $attachment_id );
			$deleted      = wp_delete_attachment( $attachment_id, true );
			if ( false === $deleted || null === $deleted ) {
				$rows[] = array(
					'id'      => $attachment_id,
					'title'   => $title,
					'action'  => 'failed',
					'message' => __( 'WordPress could not delete the attachment record.', 'media-audit' ),
				);
				continue;
			}

			$files_left = 0;
			foreach ( $before_files as $path => $size ) {
				if ( ! file_exists( $path ) ) {
					++$removed_files;
					$reclaimed_bytes += (int) $size;
				} else {
					++$files_left;
				}
			}
			$remove_from_state[ $attachment_id ] = true;
			$rows[]                              = array(
				'id'      => $attachment_id,
				'title'   => $title,
				'action'  => $files_left > 0 ? 'deleted-with-file-errors' : 'deleted',
				'message' => $files_left > 0
					? sprintf(
						/* translators: %d: number of known generated files still on disk. */
						__( 'Attachment record deleted, but %d known companion file(s) remain on disk.', 'media-audit' ),
						$files_left
					)
					: __( 'Attachment record and known local companion files deleted through WordPress.', 'media-audit' ),
			);
		}

		if ( ! empty( $remove_from_state ) ) {
			$state['missing_originals'] = array_values(
				array_filter(
					$missing,
					function ( $row ) use ( $remove_from_state ) {
						return empty( $remove_from_state[ (int) $row['id'] ] );
					}
				)
			);
			$variants                   = isset( $state['missing_variants'] ) && is_array( $state['missing_variants'] ) ? $state['missing_variants'] : array();
			$state['missing_variants']  = array_values(
				array_filter(
					$variants,
					function ( $row ) use ( $remove_from_state ) {
						return empty( $remove_from_state[ (int) $row['id'] ] );
					}
				)
			);
			self::save_integrity_state( $state );
		}

		$stats = self::get_cleanup_stats();
		if ( $removed_files > 0 || $reclaimed_bytes > 0 ) {
			$stats['removed_files']   += $removed_files;
			$stats['reclaimed_bytes'] += $reclaimed_bytes;
			update_option( self::STATS_OPTION, $stats, false );
		}
		wp_send_json_success(
			array(
				'message'     => sprintf(
					/* translators: %d: number of processed attachment records. */
					__( 'Processed %d attachment record(s).', 'media-audit' ),
					count( $rows )
				),
				'rows'        => $rows,
				'progress'    => self::get_integrity_progress( $state ),
				'removed_ids' => array_map( 'intval', array_keys( $remove_from_state ) ),
				'stats'       => $stats,
			)
		);
	}

	/**
	 * Inspect one attachment without applying WordPress display filters.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string,array<string,mixed>> Integrity findings for the attachment.
	 */
	private static function inspect_attachment_integrity( $attachment_id ) {
		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$original = get_attached_file( $attachment_id, true );
		$title    = get_the_title( $attachment_id );
		$title    = '' !== trim( (string) $title ) ? (string) $title : sprintf(
			/* translators: %d: attachment post ID. */
			__( 'Untitled attachment #%d', 'media-audit' ),
			$attachment_id
		);
		$base = array(
			'id'    => (int) $attachment_id,
			'title' => $title,
			'mime'  => (string) get_post_mime_type( $attachment_id ),
			'path'  => '' !== $attached ? wp_normalize_path( $attached ) : __( '(no attached file value)', 'media-audit' ),
		);

		if ( '' === $attached || ! is_string( $original ) || '' === $original || ! is_file( $original ) ) {
			$companions              = self::get_existing_attachment_files( $attachment_id );
			$base['reason']          = '' === $attached ? __( 'Missing _wp_attached_file metadata.', 'media-audit' ) : __( 'Local original file is missing.', 'media-audit' );
			$base['remaining_files'] = count( $companions );
			$base['remaining_kb']    = round( array_sum( $companions ) / 1024, 1 );
			return array(
				'missing_original' => $base,
				'missing_variants' => array(),
			);
		}

		$metadata      = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		$missing_files = array();
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				$file = isset( $size_data['file'] ) ? wp_basename( (string) $size_data['file'] ) : '';
				if ( '' !== $file && ! is_file( dirname( $original ) . '/' . $file ) ) {
					$missing_files[] = $file;
				}
			}
		}
		$missing_files = array_values( array_unique( $missing_files ) );
		if ( empty( $missing_files ) ) {
			return array(
				'missing_original' => array(),
				'missing_variants' => array(),
			);
		}

		$base['missing_count'] = count( $missing_files );
		$base['missing_files'] = array_slice( $missing_files, 0, 20 );
		return array(
			'missing_original' => array(),
			'missing_variants' => $base,
		);
	}

	/**
	 * Return existing generated/backup files WordPress may remove with a record.
	 *
	 * The missing original itself is intentionally excluded. Paths are unique and
	 * mapped to their pre-deletion byte size for accurate cleanup statistics.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return array<string,int> Absolute file paths mapped to byte sizes.
	 */
	private static function get_existing_attachment_files( $attachment_id ) {
		$original = get_attached_file( $attachment_id, true );
		$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		if ( is_string( $original ) && '' !== $original ) {
			$directory = dirname( wp_normalize_path( $original ) );
		} elseif ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
			$uploads       = wp_get_upload_dir();
			$metadata_path = ltrim( wp_normalize_path( (string) $metadata['file'] ), '/' );
			if ( self::has_unsafe_path_segment( $metadata_path ) || preg_match( '/[\x00-\x1F]/', $metadata_path ) ) {
				return array();
			}
			$directory = dirname( untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) . '/' . $metadata_path );
		} else {
			return array();
		}
		$names = array();
		if ( is_array( $metadata ) ) {
			foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array() as $size_data ) {
				if ( ! empty( $size_data['file'] ) ) {
					$names[] = wp_basename( (string) $size_data['file'] );
				}
			}
			if ( ! empty( $metadata['original_image'] ) ) {
				$names[] = wp_basename( (string) $metadata['original_image'] );
			}
		}
		$backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		foreach ( is_array( $backups ) ? $backups : array() as $backup ) {
			if ( ! empty( $backup['file'] ) ) {
				$names[] = wp_basename( (string) $backup['file'] );
			}
		}

		$files = array();
		foreach ( array_unique( $names ) as $name ) {
			$path = $directory . '/' . $name;
			if ( is_file( $path ) && ! is_link( $path ) ) {
				$files[ $path ] = (int) filesize( $path );
			}
		}
		return $files;
	}

	/**
	 * Apply a guarded filesystem action to paths from saved findings.
	 *
	 * Client paths are intersected with the server-owned finding set. Successful
	 * real actions are removed from the saved result; blocked/failed paths remain.
	 */
	public static function ajax_apply_action() {
		self::verify_ajax_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by self::verify_ajax_request() above.
		$action = isset( $_POST['target_action'] ) ? sanitize_key( wp_unslash( $_POST['target_action'] ) ) : '';
		if ( ! in_array( $action, array( 'quarantine', 'backup-delete', 'delete' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid file action.', 'media-audit' ) ), 400 );
		}
		$findings = self::get_last_findings();
		$allowed  = array();
		foreach ( isset( $findings['stray_rows'] ) && is_array( $findings['stray_rows'] ) ? $findings['stray_rows'] : array() as $row ) {
			$path = isset( $row['path'] ) ? self::sanitize_relative_path( $row['path'] ) : '';
			if ( '' !== $path ) {
				$allowed[ $path ] = true;
			}
		}
		$paths = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by self::verify_ajax_request() above.
		$scope = isset( $_POST['file_scope'] ) ? sanitize_key( wp_unslash( $_POST['file_scope'] ) ) : 'selected';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by self::verify_ajax_request() above.
		$requested = isset( $_POST['paths'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['paths'] ) ) : array();
		foreach ( $requested as $requested_path ) {
			$path = self::sanitize_relative_path( wp_unslash( $requested_path ) );
			if ( isset( $allowed[ $path ] ) ) {
				$paths[ $path ] = $path;
			}
		}
		// Delete all still runs in browser-sized batches. If the browser's paths
		// became stale between renders, fall back to an equally sized batch from
		// the server-owned finding set instead of losing the authorized all scope.
		if ( 'delete' === $action && 'all' === $scope && empty( $paths ) && ! empty( $allowed ) ) {
			$fallback_size = ! empty( $requested ) ? count( $requested ) : (int) self::get_settings()['action_batch_size'];
			foreach ( array_slice( array_keys( $allowed ), 0, max( 1, $fallback_size ) ) as $allowed_path ) {
				$paths[ $allowed_path ] = $allowed_path;
			}
		}
		if ( empty( $paths ) ) {
			$message = 'all' === $scope && empty( $allowed )
				? __( 'The saved findings are empty or expired. Run a new scan before using Delete all.', 'media-audit' )
				: __( 'No valid files were selected.', 'media-audit' );
			wp_send_json_error( array( 'message' => $message ), 400 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by self::verify_ajax_request() above.
		$dry_run        = ! empty( $_POST['dry_run'] );
		$settings       = self::get_settings();
		$quarantine_dir = isset( $findings['quarantine_dir'] ) ? (string) $findings['quarantine_dir'] : (string) $settings['quarantine_dir'];
		$runner         = new Media_Audit_CLI_Command();
		$revalidate     = ! empty( $settings['revalidate_actions'] );
		$download_url   = '';
		$download_name  = '';
		if ( 'backup-delete' === $action ) {
			$backup_result = $runner->apply_zip_backup_to_paths( array_values( $paths ), $dry_run, $quarantine_dir, $revalidate );
			$rows          = $backup_result['rows'];
			if ( ! $dry_run && ! empty( $backup_result['archive'] ) && is_file( $backup_result['archive'] ) ) {
				$token         = strtolower( wp_generate_password( 32, false, false ) );
				$download_name = basename( (string) $backup_result['archive'] );
				set_transient(
					'media_audit_download_' . get_current_user_id() . '_' . $token,
					array(
						'path' => (string) $backup_result['archive'],
						'name' => $download_name,
					),
					HOUR_IN_SECONDS
				);
				// wp_nonce_url() returns an HTML-escaped URL containing `&amp;`, which
				// must not be placed directly in JSON. Build the raw query URL instead.
				$download_url = add_query_arg(
					array(
						'action'   => 'media_audit_download_backup',
						'token'    => $token,
						'_wpnonce' => wp_create_nonce( 'media_audit_download_' . $token ),
					),
					admin_url( 'admin-post.php' )
				);
			}
		} else {
			$rows = $runner->apply_action_to_paths( array_values( $paths ), $action, $dry_run, $quarantine_dir, $revalidate );
		}
		$stats = self::get_cleanup_stats();
		if ( ! $dry_run ) {
			$changed = array();
			foreach ( $rows as $row ) {
				if ( in_array( isset( $row['action'] ) ? $row['action'] : '', array( 'moved', 'backed-up-and-removed', 'deleted' ), true ) ) {
					$changed[ (string) $row['path'] ] = true;
				}
			}
			self::remove_saved_finding_paths( $changed );
			$stats = self::record_cleanup_rows( $rows );
		}
		wp_send_json_success(
			array(
				'message'       => __( 'File action complete.', 'media-audit' ),
				'rows'          => $rows,
				'removed_paths' => array_keys( isset( $changed ) ? $changed : array() ),
				'download_url'  => $download_url,
				'download_name' => $download_name,
				'stats'         => $stats,
			)
		);
	}

	/**
	 * Stream a short-lived ZIP prepared for the current administrator.
	 *
	 * The opaque token is scoped to the logged-in user and protected by its own
	 * nonce. The temporary server copy is removed after it has been streamed.
	 */
	public static function download_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this backup.', 'media-audit' ), '', array( 'response' => 403 ) );
		}
		$token = isset( $_GET['token'] ) ? sanitize_key( wp_unslash( $_GET['token'] ) ) : '';
		if ( '' === $token || ! check_admin_referer( 'media_audit_download_' . $token ) ) {
			wp_die( esc_html__( 'The backup download link is invalid or expired.', 'media-audit' ), '', array( 'response' => 403 ) );
		}
		$key      = 'media_audit_download_' . get_current_user_id() . '_' . $token;
		$download = get_transient( $key );
		$path     = is_array( $download ) && isset( $download['path'] ) ? wp_normalize_path( (string) $download['path'] ) : '';
		$name     = is_array( $download ) && isset( $download['name'] ) ? sanitize_file_name( (string) $download['name'] ) : 'media-audit-backup.zip';
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The backup archive is no longer available.', 'media-audit' ), '', array( 'response' => 404 ) );
		}

		delete_transient( $key );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- The authenticated ZIP must be streamed without loading it into memory.
		readfile( $path );
		wp_delete_file( $path );
		exit;
	}

	/** Clear only the current administrator's saved display findings. */
	public static function ajax_clear_findings() {
		self::verify_ajax_request();
		self::delete_saved_findings();
		wp_send_json_success( array( 'message' => __( 'Saved results cleared.', 'media-audit' ) ) );
	}

	/** Return recoverable files from timestamped quarantine runs. */
	public static function ajax_list_quarantine() {
		self::verify_ajax_request();
		wp_send_json_success( array( 'files' => self::get_quarantined_files() ) );
	}

	/** Restore one quarantined file without ever overwriting its original path. */
	public static function ajax_restore_quarantined_file() {
		self::verify_ajax_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by self::verify_ajax_request() above.
		$quarantine_path = isset( $_POST['quarantine_path'] ) ? self::sanitize_relative_path( sanitize_text_field( wp_unslash( $_POST['quarantine_path'] ) ) ) : '';
		$parts           = '' !== $quarantine_path ? explode( '/', $quarantine_path, 2 ) : array();
		if ( 2 !== count( $parts ) || 'backups' === $parts[0] ) {
			wp_send_json_error( array( 'message' => __( 'Invalid quarantine path.', 'media-audit' ) ), 400 );
		}
		$original    = self::sanitize_relative_path( $parts[1] );
		$locations   = self::get_quarantine_locations();
		$source      = $locations['root'] . '/' . $quarantine_path;
		$destination = $locations['uploads'] . '/' . $original;
		if ( '' === $original || ! is_file( $source ) || is_link( $source ) ) {
			wp_send_json_error( array( 'message' => __( 'The quarantined file no longer exists.', 'media-audit' ) ), 404 );
		}
		if ( file_exists( $destination ) ) {
			wp_send_json_error( array( 'message' => __( 'Restore blocked because a file already exists at the original path.', 'media-audit' ) ), 409 );
		}
		if ( ! is_dir( dirname( $destination ) ) && ! wp_mkdir_p( dirname( $destination ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not recreate the original directory.', 'media-audit' ) ), 500 );
		}
		// A same-filesystem rename is atomic and restore must not trigger a credentials prompt.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Source and destination are validated paths beneath uploads.
		if ( ! rename( $source, $destination ) ) {
			wp_send_json_error( array( 'message' => __( 'The file could not be restored.', 'media-audit' ) ), 500 );
		}
		do_action( 'media_audit_file_restored', $source, $destination, $original );
		$stats = self::get_cleanup_stats();
		++$stats['restored_files'];
		update_option( self::STATS_OPTION, $stats, false );
		wp_send_json_success(
			array(
				'message' => __( 'File restored to its original uploads path.', 'media-audit' ),
				'files'   => self::get_quarantined_files(),
				'stats'   => $stats,
			)
		);
	}

	/** Permanently remove selected or all recoverable quarantine entries. */
	public static function ajax_delete_quarantined_files() {
		self::verify_ajax_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by self::verify_ajax_request() above.
		$delete_all = ! empty( $_POST['delete_all'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by self::verify_ajax_request() above.
		$requested       = isset( $_POST['quarantine_paths'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['quarantine_paths'] ) ) : array();
		$requested_paths = array();
		foreach ( $requested as $requested_path ) {
			$path = self::sanitize_relative_path( wp_unslash( $requested_path ) );
			if ( '' !== $path ) {
				$requested_paths[ $path ] = true;
			}
		}

		$inventory     = self::get_quarantined_files( 0 );
		$locations     = self::get_quarantine_locations();
		$deleted       = 0;
		$failed        = 0;
		$deleted_bytes = 0;
		foreach ( $inventory as $file ) {
			$quarantine_path = (string) $file['quarantine_path'];
			if ( ! $delete_all && empty( $requested_paths[ $quarantine_path ] ) ) {
				continue;
			}
			$source      = $locations['root'] . '/' . $quarantine_path;
			$source_size = is_file( $source ) ? (int) filesize( $source ) : 0;
			if ( is_file( $source ) && ! is_link( $source ) && wp_delete_file( $source ) && ! file_exists( $source ) ) {
				++$deleted;
				$deleted_bytes += $source_size;
				do_action( 'media_audit_quarantined_file_deleted', $source, (string) $file['path'], $quarantine_path );
			} else {
				++$failed;
			}
		}
		if ( 0 === $deleted && 0 === $failed ) {
			wp_send_json_error( array( 'message' => __( 'No matching quarantined files were found.', 'media-audit' ) ), 404 );
		}
		$message = sprintf(
			/* translators: 1: deleted count, 2: failed count. */
			__( 'Deleted %1$d quarantined file(s); %2$d failed.', 'media-audit' ),
			$deleted,
			$failed
		);
		$stats                     = self::get_cleanup_stats();
		$stats['reclaimed_bytes'] += $deleted_bytes;
		$stats['removed_files']   += $deleted;
		update_option( self::STATS_OPTION, $stats, false );
		wp_send_json_success(
			array(
				'message' => $message,
				'files'   => self::get_quarantined_files(),
				'stats'   => $stats,
			)
		);
	}

	/** Resolve normalized uploads and quarantine roots from current settings. */
	private static function get_quarantine_locations() {
		$uploads        = wp_get_upload_dir();
		$uploads_dir    = ! empty( $uploads['basedir'] ) ? untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) ) : '';
		$settings       = self::get_settings();
		$quarantine_dir = self::sanitize_relative_path( (string) $settings['quarantine_dir'] );
		if ( '' === $quarantine_dir ) {
			$quarantine_dir = '.media-audit-quarantine';
		}
		return array(
			'uploads' => $uploads_dir,
			'root'    => $uploads_dir . '/' . $quarantine_dir,
		);
	}

	/**
	 * Inventory quarantined files while excluding ZIP/CLI backups.
	 *
	 * @param int $limit Maximum rows, or zero for the complete inventory.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_quarantined_files( $limit = 500 ) {
		$locations = self::get_quarantine_locations();
		if ( '' === $locations['uploads'] || ! is_dir( $locations['root'] ) ) {
			return array();
		}
		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $locations['root'], FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$quarantine_path = ltrim( str_replace( $locations['root'], '', wp_normalize_path( $file->getPathname() ) ), '/' );
			$parts           = explode( '/', $quarantine_path, 2 );
			if ( 2 !== count( $parts ) || 'backups' === $parts[0] ) {
				continue;
			}
			$original = self::sanitize_relative_path( $parts[1] );
			if ( '' === $original ) {
				continue;
			}
			$files[] = array(
				'quarantine_path' => $quarantine_path,
				'path'            => $original,
				'run'             => $parts[0],
				'size_kb'         => round( $file->getSize() / 1024, 1 ),
			);
			if ( 0 < $limit && $limit <= count( $files ) ) {
				break;
			}
		}
		usort(
			$files,
			function ( $a, $b ) {
				$run_order = strcmp( (string) $b['run'], (string) $a['run'] );
				return 0 !== $run_order ? $run_order : strcmp( (string) $a['path'], (string) $b['path'] );
			}
		);
		return $files;
	}

	/**
	 * Build normalized engine arguments from the current request.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_audit_request_args() {
		$args = array();
		// All callers verify the shared AJAX nonce before building these arguments.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling AJAX endpoint.
		$subdir = isset( $_POST['uploads_subdir'] ) ? self::sanitize_relative_path( sanitize_text_field( wp_unslash( $_POST['uploads_subdir'] ) ) ) : '';
		if ( '' !== $subdir ) {
			$args['uploads-subdir'] = $subdir;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling AJAX endpoint.
		$args['limit'] = isset( $_POST['limit'] ) ? max( 0, absint( wp_unslash( $_POST['limit'] ) ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling AJAX endpoint.
		$args['all-files'] = ! empty( $_POST['all_files'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling AJAX endpoint.
		$args['skip-db-check'] = ! empty( $_POST['skip_db_check'] );
		return $args;
	}

	/**
	 * Read and validate an opaque scan token.
	 *
	 * @return string
	 */
	private static function get_request_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling AJAX endpoint.
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		if ( '' === $token || strlen( $token ) > 40 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid scan token.', 'media-audit' ) ), 400 );
		}
		return $token;
	}

	/**
	 * Build a user-scoped scan-job transient key.
	 *
	 * @param string $token Opaque scan token.
	 * @return string
	 */
	private static function get_job_key( $token ) {
		return self::JOB_TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
	}

	/**
	 * Build a user-scoped cancellation-marker transient key.
	 *
	 * @param string $token Opaque scan token.
	 * @return string
	 */
	private static function get_stop_key( $token ) {
		return self::STOP_TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
	}

	/** Require administrator capability and the shared AJAX nonce. */
	private static function verify_ajax_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage media audits.', 'media-audit' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Persist displayable full or partial findings for the current user.
	 *
	 * @param array<string,mixed> $findings Standard engine findings payload.
	 */
	private static function save_last_findings( $findings ) {
		$key = self::FINDINGS_TRANSIENT_PREFIX . get_current_user_id();
		self::delete_saved_findings();
		$rows   = isset( $findings['stray_rows'] ) && is_array( $findings['stray_rows'] ) ? array_values( $findings['stray_rows'] ) : array();
		$chunks = array_chunk( $rows, self::FINDINGS_CHUNK_SIZE );
		foreach ( $chunks as $index => $chunk ) {
			set_transient( $key . '_chunk_' . $index, $chunk, 12 * HOUR_IN_SECONDS );
		}
		$findings['stray_rows']         = array();
		$findings['_stray_chunk_count'] = count( $chunks );
		set_transient( $key, $findings, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Read the current user's most recent findings.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_last_findings() {
		$key   = self::FINDINGS_TRANSIENT_PREFIX . get_current_user_id();
		$value = get_transient( $key );
		if ( ! is_array( $value ) ) {
			return array();
		}
		if ( isset( $value['_stray_chunk_count'] ) ) {
			$value['stray_rows'] = array();
			for ( $index = 0; $index < (int) $value['_stray_chunk_count']; $index++ ) {
				$chunk = get_transient( $key . '_chunk_' . $index );
				if ( is_array( $chunk ) ) {
					$value['stray_rows'] = array_merge( $value['stray_rows'], $chunk );
				}
			}
			unset( $value['_stray_chunk_count'] );
		}
		return $value;
	}

	/**
	 * Remove successful action paths without rewriting every findings chunk.
	 *
	 * @param array<string,bool> $removed Relative paths keyed to true.
	 */
	private static function remove_saved_finding_paths( $removed ) {
		if ( empty( $removed ) ) {
			return;
		}
		$key      = self::FINDINGS_TRANSIENT_PREFIX . get_current_user_id();
		$manifest = get_transient( $key );
		if ( ! is_array( $manifest ) ) {
			return;
		}
		if ( isset( $manifest['_stray_chunk_count'] ) ) {
			for ( $index = 0; $index < (int) $manifest['_stray_chunk_count']; $index++ ) {
				$chunk_key = $key . '_chunk_' . $index;
				$chunk     = get_transient( $chunk_key );
				if ( ! is_array( $chunk ) ) {
					continue;
				}
				$filtered = array_values(
					array_filter(
						$chunk,
						function ( $row ) use ( $removed ) {
							return empty( $removed[ (string) $row['path'] ] );
						}
					)
				);
				if ( count( $filtered ) !== count( $chunk ) ) {
					set_transient( $chunk_key, $filtered, 12 * HOUR_IN_SECONDS );
				}
			}
			return;
		}

		$manifest['stray_rows'] = array_values(
			array_filter(
				isset( $manifest['stray_rows'] ) && is_array( $manifest['stray_rows'] ) ? $manifest['stray_rows'] : array(),
				function ( $row ) use ( $removed ) {
					return empty( $removed[ (string) $row['path'] ] );
				}
			)
		);
		set_transient( $key, $manifest, 12 * HOUR_IN_SECONDS );
	}

	/** Remove the findings manifest and all chunks owned by the current user. */
	private static function delete_saved_findings() {
		$key         = self::FINDINGS_TRANSIENT_PREFIX . get_current_user_id();
		$manifest    = get_transient( $key );
		$chunk_count = is_array( $manifest ) && isset( $manifest['_stray_chunk_count'] ) ? (int) $manifest['_stray_chunk_count'] : 0;
		for ( $index = 0; $index < $chunk_count; $index++ ) {
			delete_transient( $key . '_chunk_' . $index );
		}
		delete_transient( $key );
	}

	/**
	 * Persist the current administrator's Media Library integrity state.
	 *
	 * @param array<string,mixed> $state Normalized integrity state.
	 */
	private static function save_integrity_state( $state ) {
		set_transient( self::INTEGRITY_TRANSIENT_PREFIX . get_current_user_id(), $state, 12 * HOUR_IN_SECONDS );
	}

	/** Return the current administrator's latest integrity state. */
	private static function get_integrity_state() {
		$value = get_transient( self::INTEGRITY_TRANSIENT_PREFIX . get_current_user_id() );
		return is_array( $value ) ? self::normalize_integrity_state( $value ) : array();
	}

	/**
	 * Normalize integrity payloads before storage or browser serialization.
	 *
	 * @param array<string,mixed> $state Raw or previously persisted state.
	 * @return array<string,mixed>
	 */
	private static function normalize_integrity_state( $state ) {
		$state                      = wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'processed'         => 0,
				'total'             => 0,
				'last_id'           => 0,
				'missing_originals' => array(),
				'missing_variants'  => array(),
				'completed'         => false,
				'started_at'        => '',
				'completed_at'      => '',
			)
		);
		$state['processed']         = max( 0, (int) $state['processed'] );
		$state['total']             = max( 0, (int) $state['total'] );
		$state['last_id']           = max( 0, (int) $state['last_id'] );
		$state['missing_originals'] = is_array( $state['missing_originals'] ) ? array_values( $state['missing_originals'] ) : array();
		$state['missing_variants']  = is_array( $state['missing_variants'] ) ? array_values( $state['missing_variants'] ) : array();
		$state['completed']         = ! empty( $state['completed'] );
		$state['progress_percent']  = $state['total'] > 0 ? round( min( 100, ( $state['processed'] / $state['total'] ) * 100 ), 1 ) : 100;
		return $state;
	}

	/**
	 * Return progress fields without retransmitting cumulative result arrays.
	 *
	 * @param array<string,mixed> $state Integrity state containing result arrays.
	 * @return array<string,mixed> Compact progress fields.
	 */
	private static function get_integrity_progress( $state ) {
		$state = self::normalize_integrity_state( $state );
		unset( $state['missing_originals'], $state['missing_variants'] );
		return $state;
	}

	/**
	 * Normalize a user-provided relative uploads path and reject traversal.
	 *
	 * @param mixed $path Candidate path.
	 * @return string Empty when invalid.
	 */
	private static function sanitize_relative_path( $path ) {
		$path = ltrim( trim( wp_normalize_path( (string) $path ) ), '/' );
		if ( '' === $path || self::has_unsafe_path_segment( $path ) || preg_match( '/[\x00-\x1F]/', $path ) ) {
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
	private static function has_unsafe_path_segment( $path ) {
		foreach ( explode( '/', wp_normalize_path( (string) $path ) ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return true;
			}
		}
		return false;
	}
}
