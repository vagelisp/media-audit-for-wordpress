<?php
/**
 * Plugin Name: Media Audit
 * Description: Find files that may be unused in WordPress uploads, spot missing Media Library files, and safely review, quarantine, back up, or delete them.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Vagelis P.
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: media-audit
 *
 * @package GPMediaAudit
 */

defined( 'ABSPATH' ) || exit;

/** Current asset and release version. */
define( 'MEDIA_AUDIT_VERSION', '1.0.1' );

/** Absolute plugin bootstrap path. */
define( 'MEDIA_AUDIT_FILE', __FILE__ );

/** Public base URL used for dashboard assets. */
define( 'MEDIA_AUDIT_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-media-audit-cli-command.php';
require_once __DIR__ . '/includes/class-media-audit-admin-page.php';

/**
 * Register only the public leaf command.
 *
 * Registering the engine class as a namespace would expose its public admin-job
 * methods as accidental commands, so the callable targets media_audit directly.
 */
function media_audit_register_cli_commands() {
	if ( ! class_exists( 'Media_Audit_CLI_Command' ) ) {
		return;
	}

	if ( is_callable( array( 'WP_CLI', 'add_command' ) ) ) {
		$command = new Media_Audit_CLI_Command();
		call_user_func( array( 'WP_CLI', 'add_command' ), 'gp media-audit', array( $command, 'media_audit' ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'cli_init', 'media_audit_register_cli_commands' );
}

if ( is_admin() ) {
	if ( class_exists( 'Media_Audit_Admin_Page' ) ) {
		Media_Audit_Admin_Page::init();
	}
}
