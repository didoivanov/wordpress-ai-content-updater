<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Edit-screen meta box with preview & approve workflow.
 */
class AICR_MetaBox {

    /** @var AICR_Settings */
    private $settings;

    public function __construct( AICR_Settings $settings ) {
        $this->settings = $settings;
    }

    public function register() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_post_prompt' ], 10, 2 );
    }

    public function add_meta_boxes() {
        $enabled = (array) $this->settings->get( 'enabled_types', [] );
        foreach ( $enabled as $pt ) {
            add_meta_box(
                'aicr_metabox',
                __( 'AI Content Rewriter', 'ai-content-rewriter' ),
                [ $this, 'render' ],
                $pt,
                'normal',
                'high'
            );
        }
    }

    public function render( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }
        wp_nonce_field( 'aicr_metabox_' . $post->ID, 'aicr_metabox_nonce' );
        $per_post = get_post_meta( $post->ID, '_aicr_extra_prompt', true );
        ?>
        <div class="aicr-metabox" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
            <p>
                <label for="aicr_extra_prompt"><strong><?php esc_html_e( 'Extra instructions for this post (optional)', 'ai-content-rewriter' ); ?></strong></label>
                <textarea id="aicr_extra_prompt" name="aicr_extra_prompt" rows="3" class="large-text code" placeholder="<?php esc_attr_e( 'e.g. Focus on UK gambling regulations, use British English, tone: confident and concise.', 'ai-content-rewriter' ); ?>"><?php echo esc_textarea( $per_post ); ?></textarea>
                <span class="description"><?php esc_html_e( 'Appended after the global + post-type prompts.', 'ai-content-rewriter' ); ?></span>
            </p>

            <p class="aicr-actions">
                <button type="button" class="button button-secondary" id="aicr-select-fields">
                    <?php esc_html_e( 'Select fields', 'ai-content-rewriter' ); ?>
                </button>
                <button type="button" class="button button-primary" id="aicr-generate">
                    <?php esc_html_e( 'Generate preview', 'ai-content-rewriter' ); ?>
                </button>
                <span class="spinner aicr-spinner" style="float:none;margin:0 4px;"></span>
                <span class="aicr-status" aria-live="polite"></span>
            </p>

            <div class="aicr-field-picker" hidden>
                <p><strong><?php esc_html_e( 'Choose which fields to rewrite', 'ai-content-rewriter' ); ?></strong></p>
                <div class="aicr-field-list"></div>
            </div>

            <div class="aicr-log" hidden>
                <header class="aicr-log-header">
                    <strong><?php esc_html_e( 'Progress', 'ai-content-rewriter' ); ?></strong>
                </header>
                <div class="aicr-log-body" aria-live="polite"></div>
            </div>

            <div class="aicr-preview" hidden>
                <h3><?php esc_html_e( 'Preview', 'ai-content-rewriter' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Review each field. Approve the ones you want to write back, then click Apply. Original content remains until you save the post.', 'ai-content-rewriter' ); ?></p>
                <div class="aicr-preview-items"></div>
                <p class="aicr-apply-actions">
                    <button type="button" class="button button-primary" id="aicr-apply">
                        <?php esc_html_e( 'Apply approved fields', 'ai-content-rewriter' ); ?>
                    </button>
                    <button type="button" class="button" id="aicr-discard">
                        <?php esc_html_e( 'Discard preview', 'ai-content-rewriter' ); ?>
                    </button>
                </p>
            </div>
        </div>
        <?php
    }

    public function save_post_prompt( $post_id, $post ) {
        if ( ! isset( $_POST['aicr_metabox_nonce'] ) ) { return; }
        if ( ! wp_verify_nonce( wp_unslash( $_POST['aicr_metabox_nonce'] ), 'aicr_metabox_' . $post_id ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        if ( isset( $_POST['aicr_extra_prompt'] ) ) {
            $val = sanitize_textarea_field( wp_unslash( $_POST['aicr_extra_prompt'] ) );
            if ( '' === $val ) {
                delete_post_meta( $post_id, '_aicr_extra_prompt' );
            } else {
                update_post_meta( $post_id, '_aicr_extra_prompt', $val );
            }
        }
    }
}
