<?php
/**
 * Plugin Name:       AI Content Rewriter
 * Plugin URI:        https://github.com/didoivanov/wordpress-ai-content-updater
 * Description:       Rewrite WordPress pages, posts and custom post types (including ACF Pro flexible content / repeaters) with Anthropic Claude. Per-CPT prompts, preview & approve workflow, self-updating from GitHub.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            K Web ltd
 * Author URI:        https://github.com/didoivanov
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-content-rewriter
 * Domain Path:       /languages
 * GitHub Plugin URI: didoivanov/wordpress-ai-content-updater
 * Primary Branch:    main
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AICR_VERSION', '0.2.0' );
define( 'AICR_FILE', __FILE__ );
define( 'AICR_PATH', plugin_dir_path( __FILE__ ) );
define( 'AICR_URL', plugin_dir_url( __FILE__ ) );
define( 'AICR_BASENAME', plugin_basename( __FILE__ ) );
define( 'AICR_SLUG', 'ai-content-rewriter' );
define( 'AICR_GH_USER', 'didoivanov' );
define( 'AICR_GH_REPO', 'wordpress-ai-content-updater' );

require_once AICR_PATH . 'includes/class-plugin.php';
require_once AICR_PATH . 'includes/class-settings.php';
require_once AICR_PATH . 'includes/class-anthropic-client.php';
require_once AICR_PATH . 'includes/class-rewriter.php';
require_once AICR_PATH . 'includes/class-metabox.php';
require_once AICR_PATH . 'includes/class-ajax.php';
require_once AICR_PATH . 'includes/class-updater.php';

register_activation_hook( __FILE__, [ 'AICR_Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'AICR_Plugin', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    AICR_Plugin::instance()->init();
} );
