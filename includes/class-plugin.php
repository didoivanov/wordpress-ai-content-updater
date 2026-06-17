<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Main bootstrap / loader.
 */
class AICR_Plugin {

    private static $instance = null;

    /** @var AICR_Settings */
    public $settings;
    /** @var AICR_Anthropic_Client */
    public $client;
    /** @var AICR_Rewriter */
    public $rewriter;
    /** @var AICR_MetaBox */
    public $metabox;
    /** @var AICR_Ajax */
    public $ajax;
    /** @var AICR_Updater */
    public $updater;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        load_plugin_textdomain( 'ai-content-rewriter', false, dirname( AICR_BASENAME ) . '/languages' );

        $this->settings = new AICR_Settings();
        $this->client   = new AICR_Anthropic_Client( $this->settings );
        $this->rewriter = new AICR_Rewriter( $this->client, $this->settings );
        $this->metabox  = new AICR_MetaBox( $this->settings );
        $this->ajax     = new AICR_Ajax( $this->rewriter, $this->settings );
        $this->updater  = new AICR_Updater();

        $this->settings->register();
        $this->metabox->register();
        $this->ajax->register();
        $this->updater->register();

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function enqueue_admin_assets( $hook ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_edit = $screen && in_array( $screen->base, [ 'post' ], true );
        $is_settings = isset( $_GET['page'] ) && $_GET['page'] === AICR_SLUG;
        if ( ! $is_edit && ! $is_settings ) {
            return;
        }
        wp_enqueue_style(
            'aicr-admin',
            AICR_URL . 'assets/admin.css',
            [],
            AICR_VERSION
        );
        wp_enqueue_script(
            'aicr-admin',
            AICR_URL . 'assets/admin.js',
            [ 'jquery', 'wp-util' ],
            AICR_VERSION,
            true
        );
        wp_localize_script( 'aicr-admin', 'AICR', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'aicr_nonce' ),
            'i18n'     => [
                'generating'        => __( 'Generating preview...', 'ai-content-rewriter' ),
                'apply_confirm'     => __( 'Replace the current content with the rewritten preview? The original will be saved as a revision.', 'ai-content-rewriter' ),
                'applied'           => __( 'Content applied. Remember to update the post.', 'ai-content-rewriter' ),
                'error'             => __( 'Error: ', 'ai-content-rewriter' ),
                'no_preview'        => __( 'No preview yet.', 'ai-content-rewriter' ),
                'select_fields'     => __( 'Select fields to rewrite', 'ai-content-rewriter' ),
            ],
        ] );
    }

    public static function activate() {
        // Default options.
        $defaults = AICR_Settings::default_options();
        $existing = get_option( AICR_Settings::OPTION_KEY );
        if ( ! is_array( $existing ) ) {
            update_option( AICR_Settings::OPTION_KEY, $defaults );
        } else {
            update_option( AICR_Settings::OPTION_KEY, array_merge( $defaults, $existing ) );
        }
    }

    public static function deactivate() {
        // No-op for now.
    }
}
