<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AJAX endpoints for preview, apply, field listing, key test, update check.
 *
 * Preview data is stored in a transient keyed by user + post so the apply step
 * has a verified server-side copy of meta + originals (we don't trust client to send origins).
 */
class AICR_Ajax {

    /** @var AICR_Rewriter */
    private $rewriter;
    /** @var AICR_Settings */
    private $settings;

    public function __construct( AICR_Rewriter $rewriter, AICR_Settings $settings ) {
        $this->rewriter = $rewriter;
        $this->settings = $settings;
    }

    public function register() {
        add_action( 'wp_ajax_aicr_list_fields',  [ $this, 'ajax_list_fields' ] );
        add_action( 'wp_ajax_aicr_preview',      [ $this, 'ajax_preview' ] );
        add_action( 'wp_ajax_aicr_apply',        [ $this, 'ajax_apply' ] );
        add_action( 'wp_ajax_aicr_test_key',     [ $this, 'ajax_test_key' ] );
        add_action( 'wp_ajax_aicr_check_update', [ $this, 'ajax_check_update' ] );
    }

    private function verify( $cap = 'edit_posts', $post_id = 0 ) {
        check_ajax_referer( 'aicr_nonce', 'nonce' );
        if ( $post_id ) {
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-rewriter' ) ], 403 );
            }
        } elseif ( ! current_user_can( $cap ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'ai-content-rewriter' ) ], 403 );
        }
    }

    public function ajax_list_fields() {
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $this->verify( 'edit_posts', $post_id );

        // Force include all eligible fields (ignore selection) for the picker.
        $payload = $this->rewriter->build_payload( $post_id, null );
        // Settings flags affect what's included; offer them in the picker.
        $items = [];
        foreach ( $payload['items'] as $item ) {
            $preview = mb_substr( wp_strip_all_tags( $item['value'] ), 0, 120 );
            $items[] = [
                'id'      => $item['id'],
                'label'   => $item['label'],
                'type'    => $item['type'],
                'format'  => $item['format'],
                'preview' => $preview,
            ];
        }
        wp_send_json_success( [ 'items' => $items ] );
    }

    public function ajax_preview() {
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $this->verify( 'edit_posts', $post_id );

        $extra = isset( $_POST['extra'] ) ? wp_kses_post( wp_unslash( $_POST['extra'] ) ) : '';
        $selection = null;
        if ( ! empty( $_POST['fields'] ) ) {
            $raw = wp_unslash( $_POST['fields'] );
            if ( is_array( $raw ) ) {
                $selection = array_map( 'sanitize_text_field', $raw );
            }
        }

        $result = $this->rewriter->generate_preview( $post_id, $selection, $extra );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [ 'message' => isset( $result['error'] ) ? $result['error'] : 'Error' ] );
        }

        // Store originals + meta server-side so apply can validate.
        $user_id = get_current_user_id();
        $key     = 'aicr_pv_' . $user_id . '_' . $post_id;
        set_transient( $key, [
            'preview'   => $result['preview'],
            'originals' => $result['originals'],
            'meta'      => $result['meta'],
            'ts'        => time(),
        ], HOUR_IN_SECONDS );

        // Build payload for UI.
        $errors = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : [];
        $ui = [];
        foreach ( $result['meta'] as $id => $m ) {
            $ui[] = [
                'id'        => $id,
                'label'     => $m['label'],
                'format'    => $m['format'],
                'original'  => isset( $result['originals'][ $id ] ) ? $result['originals'][ $id ] : '',
                'rewritten' => isset( $result['preview'][ $id ] ) ? $result['preview'][ $id ] : '',
                'error'     => isset( $errors[ $id ] ) ? $errors[ $id ] : '',
            ];
        }

        wp_send_json_success( [ 'items' => $ui ] );
    }

    public function ajax_apply() {
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $this->verify( 'edit_posts', $post_id );

        $approved_ids = [];
        if ( ! empty( $_POST['approved'] ) ) {
            $raw = wp_unslash( $_POST['approved'] );
            if ( is_array( $raw ) ) {
                $approved_ids = array_map( 'sanitize_text_field', $raw );
            }
        }
        // Allow client to send edited rewritten values (so users can tweak before applying).
        $edits = [];
        if ( ! empty( $_POST['values'] ) ) {
            $raw = wp_unslash( $_POST['values'] );
            if ( is_array( $raw ) ) {
                foreach ( $raw as $id => $val ) {
                    $edits[ sanitize_text_field( $id ) ] = wp_kses_post( $val );
                }
            }
        }

        $key   = 'aicr_pv_' . get_current_user_id() . '_' . $post_id;
        $cache = get_transient( $key );
        if ( ! is_array( $cache ) || empty( $cache['meta'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Preview expired. Please regenerate the preview.', 'ai-content-rewriter' ) ] );
        }

        $approved = [];
        foreach ( $approved_ids as $id ) {
            if ( ! isset( $cache['meta'][ $id ] ) ) { continue; }
            $value = isset( $edits[ $id ] )
                ? $edits[ $id ]
                : ( isset( $cache['preview'][ $id ] ) ? $cache['preview'][ $id ] : '' );
            $approved[ $id ] = $value;
        }

        $result = $this->rewriter->apply( $post_id, $approved, $cache['meta'] );
        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [ 'message' => isset( $result['error'] ) ? $result['error'] : 'Error' ] );
        }

        delete_transient( $key );
        wp_send_json_success( [ 'applied' => $result['applied'] ] );
    }

    public function ajax_test_key() {
        $this->verify( 'manage_options' );
        /** @var AICR_Plugin $plugin */
        $plugin = AICR_Plugin::instance();
        $resp = $plugin->client->ping();
        if ( empty( $resp['ok'] ) ) {
            wp_send_json_error( [ 'message' => isset( $resp['error'] ) ? $resp['error'] : 'Error' ] );
        }
        wp_send_json_success( [ 'message' => __( 'Connection OK.', 'ai-content-rewriter' ) ] );
    }

    public function ajax_check_update() {
        $this->verify( 'manage_options' );
        /** @var AICR_Plugin $plugin */
        $plugin = AICR_Plugin::instance();
        $info = $plugin->updater->fetch_remote_info( true );
        if ( empty( $info ) ) {
            wp_send_json_error( [ 'message' => __( 'Could not reach GitHub.', 'ai-content-rewriter' ) ] );
        }
        $latest  = isset( $info['version'] ) ? $info['version'] : '';
        $current = AICR_VERSION;
        $newer   = version_compare( $latest, $current, '>' );
        wp_send_json_success( [
            'current' => $current,
            'latest'  => $latest,
            'newer'   => $newer,
            'url'     => isset( $info['html_url'] ) ? $info['html_url'] : '',
        ] );
    }
}
