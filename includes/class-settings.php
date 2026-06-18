<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Settings page + options access.
 */
class AICR_Settings {

    const OPTION_KEY = 'aicr_settings';
    const CAPABILITY = 'manage_options';

    public static function default_options() {
        return [
            'api_key'          => '',
            'model'            => 'claude-sonnet-4-5',
            'max_tokens'       => 16000,
            'temperature'      => 0.7,
            'auto_retry_truncated' => 1,
            'type_max_tokens'  => [],
            'enabled_types'    => [ 'page', 'post' ],
            'global_prompt'    => "You are an expert SEO copywriter. Rewrite the provided HTML content to improve clarity, readability, and SEO. Preserve all HTML tags and structure. Do not invent facts. Output only the rewritten HTML, nothing else.",
            'type_prompts'     => [
                'page' => '',
                'post' => '',
            ],
            'system_prompt'    => "You are an expert content rewriter. Respond ONLY with the rewritten content using the same format and structure as the input. Never include explanations, prefaces, or markdown code fences.",
            'rewrite_title'    => 0,
            'rewrite_excerpt'  => 0,
            'rewrite_acf'      => 1,
            'acf_field_types'  => [ 'text', 'textarea', 'wysiwyg' ],
            'prices'           => AICR_Usage::default_prices(),
        ];
    }

    public function register() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function register_settings() {
        register_setting(
            'aicr_settings_group',
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => self::default_options(),
            ]
        );
    }

    public function get( $key = null, $default = null ) {
        $opts = get_option( self::OPTION_KEY, self::default_options() );
        $opts = is_array( $opts ) ? array_merge( self::default_options(), $opts ) : self::default_options();
        if ( null === $key ) {
            return $opts;
        }
        return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
    }

    public function sanitize( $input ) {
        $out = self::default_options();
        if ( ! is_array( $input ) ) {
            return $out;
        }
        $out['api_key']     = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
        $out['model']       = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $out['model'];
        $out['max_tokens']  = isset( $input['max_tokens'] ) ? max( 256, min( 64000, (int) $input['max_tokens'] ) ) : $out['max_tokens'];
        $out['auto_retry_truncated'] = ! empty( $input['auto_retry_truncated'] ) ? 1 : 0;
        $out['type_max_tokens'] = [];
        if ( isset( $input['type_max_tokens'] ) && is_array( $input['type_max_tokens'] ) ) {
            foreach ( $input['type_max_tokens'] as $type => $val ) {
                $type = sanitize_key( $type );
                if ( '' === $type ) { continue; }
                $val = (int) $val;
                if ( $val > 0 ) {
                    $out['type_max_tokens'][ $type ] = max( 256, min( 64000, $val ) );
                }
            }
        }
        $out['temperature'] = isset( $input['temperature'] ) ? max( 0, min( 1, (float) $input['temperature'] ) ) : $out['temperature'];

        $enabled = isset( $input['enabled_types'] ) && is_array( $input['enabled_types'] )
            ? array_map( 'sanitize_key', $input['enabled_types'] )
            : [];
        $out['enabled_types'] = array_values( array_unique( $enabled ) );

        $out['global_prompt'] = isset( $input['global_prompt'] ) ? (string) $input['global_prompt'] : $out['global_prompt'];
        $out['system_prompt'] = isset( $input['system_prompt'] ) ? (string) $input['system_prompt'] : $out['system_prompt'];

        $out['type_prompts'] = [];
        if ( isset( $input['type_prompts'] ) && is_array( $input['type_prompts'] ) ) {
            foreach ( $input['type_prompts'] as $type => $prompt ) {
                $type = sanitize_key( $type );
                if ( '' === $type ) { continue; }
                $out['type_prompts'][ $type ] = (string) $prompt;
            }
        }

        $out['rewrite_title']   = ! empty( $input['rewrite_title'] ) ? 1 : 0;
        $out['rewrite_excerpt'] = ! empty( $input['rewrite_excerpt'] ) ? 1 : 0;
        $out['rewrite_acf']     = ! empty( $input['rewrite_acf'] ) ? 1 : 0;

        $allowed_acf_types = [ 'text', 'textarea', 'wysiwyg', 'url', 'email' ];
        $acf_types = isset( $input['acf_field_types'] ) && is_array( $input['acf_field_types'] )
            ? array_intersect( $allowed_acf_types, array_map( 'sanitize_key', $input['acf_field_types'] ) )
            : [];
        $out['acf_field_types'] = array_values( $acf_types );

        // Pricing.
        $prices = AICR_Usage::default_prices();
        if ( isset( $input['prices'] ) && is_array( $input['prices'] ) ) {
            foreach ( $input['prices'] as $model => $vals ) {
                $model = sanitize_text_field( $model );
                if ( '' === $model || ! is_array( $vals ) ) { continue; }
                $prices[ $model ] = [
                    'input'  => isset( $vals['input'] )  ? max( 0, (float) $vals['input'] )  : 0,
                    'output' => isset( $vals['output'] ) ? max( 0, (float) $vals['output'] ) : 0,
                ];
            }
        }
        $out['prices'] = $prices;

        return $out;
    }

    /**
     * Return all post types eligible for rewriting (public + the built-in 'post' and 'page').
     */
    public function get_eligible_post_types() {
        $types = get_post_types( [ 'show_ui' => true ], 'objects' );
        // Exclude attachments etc.
        unset( $types['attachment'] );
        return $types;
    }

    /**
     * Return the effective max output tokens for a given post type.
     */
    public function get_max_tokens_for_type( $post_type ) {
        $opts = $this->get();
        $per_type = isset( $opts['type_max_tokens'][ $post_type ] ) ? (int) $opts['type_max_tokens'][ $post_type ] : 0;
        if ( $per_type > 0 ) {
            return $per_type;
        }
        return (int) $opts['max_tokens'];
    }

    /**
     * Build the effective prompt for a given post type.
     */
    public function get_prompt_for_type( $post_type ) {
        $opts = $this->get();
        $type_prompts = isset( $opts['type_prompts'] ) && is_array( $opts['type_prompts'] ) ? $opts['type_prompts'] : [];
        $specific = isset( $type_prompts[ $post_type ] ) ? trim( $type_prompts[ $post_type ] ) : '';
        $global   = isset( $opts['global_prompt'] ) ? trim( $opts['global_prompt'] ) : '';
        if ( $specific && $global ) {
            return $global . "\n\n--- POST TYPE INSTRUCTIONS (" . $post_type . ") ---\n" . $specific;
        }
        return $specific ?: $global;
    }

    public function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        $opts  = $this->get();
        $types = $this->get_eligible_post_types();
        ?>
        <div class="wrap aicr-settings">
            <h1><?php esc_html_e( 'AI Content Rewriter', 'ai-content-rewriter' ); ?></h1>
            <p><?php esc_html_e( 'Configure your Anthropic API credentials and rewriting prompts. Per-post-type prompts are appended to the global prompt.', 'ai-content-rewriter' ); ?></p>

            <form method="post" action="options.php">
                <?php settings_fields( 'aicr_settings_group' ); ?>

                <h2><?php esc_html_e( 'Anthropic API', 'ai-content-rewriter' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aicr_api_key"><?php esc_html_e( 'API key', 'ai-content-rewriter' ); ?></label></th>
                        <td>
                            <input type="password" id="aicr_api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" value="<?php echo esc_attr( $opts['api_key'] ); ?>" class="regular-text" autocomplete="off" />
                            <p class="description"><?php esc_html_e( 'Stored in wp_options. Use an Anthropic API key with permission to call the Messages API.', 'ai-content-rewriter' ); ?></p>
                            <button type="button" class="button" id="aicr-test-key"><?php esc_html_e( 'Test connection', 'ai-content-rewriter' ); ?></button>
                            <span id="aicr-test-result" style="margin-left:10px;"></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aicr_model"><?php esc_html_e( 'Model', 'ai-content-rewriter' ); ?></label></th>
                        <td>
                            <input type="text" id="aicr_model" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[model]" value="<?php echo esc_attr( $opts['model'] ); ?>" class="regular-text" />
                            <p class="description"><?php echo wp_kses_post( __( 'Examples: <code>claude-sonnet-4-5</code>, <code>claude-opus-4-5</code>, <code>claude-haiku-4-5</code>.', 'ai-content-rewriter' ) ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aicr_max_tokens"><?php esc_html_e( 'Max output tokens', 'ai-content-rewriter' ); ?></label></th>
                        <td>
                            <input type="number" min="256" max="64000" step="256" id="aicr_max_tokens" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_tokens]" value="<?php echo esc_attr( $opts['max_tokens'] ); ?>" />
                            <p class="description">
                                <?php esc_html_e( 'Hard cap on output per field. Range 256–64000. Recommended: 8000–16000 for posts/short pages, 16000–32000 for long Gutenberg/ACF pages, 32000–64000 for very long documents.', 'ai-content-rewriter' ); ?>
                                <br>
                                <?php esc_html_e( 'Note: Anthropic charges per output token, so higher caps don’t cost more unless the model actually uses them.', 'ai-content-rewriter' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Auto-retry on truncation', 'ai-content-rewriter' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[auto_retry_truncated]" value="1" <?php checked( ! empty( $opts['auto_retry_truncated'] ) ); ?> />
                                <?php esc_html_e( 'If the model stops because of max_tokens, automatically retry the same field once with double the budget (capped at 64000).', 'ai-content-rewriter' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aicr_temperature"><?php esc_html_e( 'Temperature', 'ai-content-rewriter' ); ?></label></th>
                        <td><input type="number" min="0" max="1" step="0.05" id="aicr_temperature" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[temperature]" value="<?php echo esc_attr( $opts['temperature'] ); ?>" /></td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Enabled post types', 'ai-content-rewriter' ); ?></h2>
                <p class="description"><?php esc_html_e( 'The rewriter meta box will appear on the edit screen of these post types.', 'ai-content-rewriter' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Post types', 'ai-content-rewriter' ); ?></th>
                        <td>
                            <?php foreach ( $types as $pt ) :
                                $checked = in_array( $pt->name, (array) $opts['enabled_types'], true ); ?>
                                <label style="display:inline-block;margin-right:14px;">
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled_types][]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( $checked ); ?> />
                                    <?php echo esc_html( $pt->labels->singular_name ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Prompts', 'ai-content-rewriter' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="aicr_system_prompt"><?php esc_html_e( 'System prompt', 'ai-content-rewriter' ); ?></label></th>
                        <td>
                            <textarea id="aicr_system_prompt" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[system_prompt]" rows="3" class="large-text code"><?php echo esc_textarea( $opts['system_prompt'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Sent as Anthropic system message. Defines model behaviour for all requests.', 'ai-content-rewriter' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aicr_global_prompt"><?php esc_html_e( 'Global rewriting prompt', 'ai-content-rewriter' ); ?></label></th>
                        <td>
                            <textarea id="aicr_global_prompt" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[global_prompt]" rows="5" class="large-text code"><?php echo esc_textarea( $opts['global_prompt'] ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Used as the base instruction for every rewrite. Per-type prompts below are appended.', 'ai-content-rewriter' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e( 'Per post type prompts &amp; token caps', 'ai-content-rewriter' ); ?></h3>
                <p class="description"><?php esc_html_e( 'Per-type max tokens overrides the global cap above. Leave 0 / empty to use the global value.', 'ai-content-rewriter' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php foreach ( $types as $pt ) :
                        $val = isset( $opts['type_prompts'][ $pt->name ] ) ? $opts['type_prompts'][ $pt->name ] : '';
                        $mt  = isset( $opts['type_max_tokens'][ $pt->name ] ) ? (int) $opts['type_max_tokens'][ $pt->name ] : 0; ?>
                        <tr>
                            <th scope="row">
                                <label for="aicr_tp_<?php echo esc_attr( $pt->name ); ?>">
                                    <?php echo esc_html( $pt->labels->singular_name ); ?><br>
                                    <code><?php echo esc_html( $pt->name ); ?></code>
                                </label>
                            </th>
                            <td>
                                <textarea id="aicr_tp_<?php echo esc_attr( $pt->name ); ?>" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[type_prompts][<?php echo esc_attr( $pt->name ); ?>]" rows="4" class="large-text code"><?php echo esc_textarea( $val ); ?></textarea>
                                <p>
                                    <label>
                                        <?php esc_html_e( 'Max output tokens for this type:', 'ai-content-rewriter' ); ?>
                                        <input type="number" min="0" max="64000" step="256" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[type_max_tokens][<?php echo esc_attr( $pt->name ); ?>]" value="<?php echo esc_attr( $mt ?: '' ); ?>" placeholder="<?php esc_attr_e( 'global default', 'ai-content-rewriter' ); ?>" style="width:120px;" />
                                    </label>
                                </p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2><?php esc_html_e( 'Field handling', 'ai-content-rewriter' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Rewrite extras', 'ai-content-rewriter' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rewrite_title]" value="1" <?php checked( $opts['rewrite_title'], 1 ); ?> /> <?php esc_html_e( 'Rewrite post title', 'ai-content-rewriter' ); ?></label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rewrite_excerpt]" value="1" <?php checked( $opts['rewrite_excerpt'], 1 ); ?> /> <?php esc_html_e( 'Rewrite excerpt', 'ai-content-rewriter' ); ?></label><br>
                            <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[rewrite_acf]" value="1" <?php checked( $opts['rewrite_acf'], 1 ); ?> /> <?php esc_html_e( 'Rewrite ACF Pro fields (flexible content & repeaters supported)', 'ai-content-rewriter' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'ACF field types', 'ai-content-rewriter' ); ?></th>
                        <td>
                            <?php
                            $allowed = [ 'text' => 'Text', 'textarea' => 'Textarea', 'wysiwyg' => 'WYSIWYG', 'url' => 'URL', 'email' => 'Email' ];
                            $selected = (array) $opts['acf_field_types'];
                            foreach ( $allowed as $key => $label ) : ?>
                                <label style="display:inline-block;margin-right:14px;">
                                    <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[acf_field_types][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( 'Only ACF fields of these types are sent to Claude. URL/Email are usually unchecked to avoid mangling links.', 'ai-content-rewriter' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Pricing (USD per 1,000,000 tokens)', 'ai-content-rewriter' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Used to estimate spend on the Costs page. Update these whenever Anthropic adjusts prices.', 'ai-content-rewriter' ); ?></p>
                <table class="form-table" role="presentation">
                    <?php $prices = isset( $opts['prices'] ) && is_array( $opts['prices'] ) ? $opts['prices'] : AICR_Usage::default_prices(); ?>
                    <?php foreach ( $prices as $model => $p ) : ?>
                        <tr>
                            <th scope="row"><code><?php echo esc_html( $model ); ?></code></th>
                            <td>
                                <label><?php esc_html_e( 'Input', 'ai-content-rewriter' ); ?>
                                    $<input type="number" step="0.01" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[prices][<?php echo esc_attr( $model ); ?>][input]" value="<?php echo esc_attr( $p['input'] ); ?>" style="width:90px;" />
                                </label>
                                &nbsp;
                                <label><?php esc_html_e( 'Output', 'ai-content-rewriter' ); ?>
                                    $<input type="number" step="0.01" min="0" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[prices][<?php echo esc_attr( $model ); ?>][output]" value="<?php echo esc_attr( $p['output'] ); ?>" style="width:90px;" />
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Updates', 'ai-content-rewriter' ); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s repo */
                    esc_html__( 'This plugin auto-updates from %s. Releases tagged on GitHub are detected automatically.', 'ai-content-rewriter' ),
                    '<code>' . esc_html( AICR_GH_USER . '/' . AICR_GH_REPO ) . '</code>'
                );
                ?>
            </p>
            <p>
                <button type="button" class="button" id="aicr-check-updates"><?php esc_html_e( 'Check for updates now', 'ai-content-rewriter' ); ?></button>
                <span id="aicr-update-result" style="margin-left:10px;"></span>
            </p>
        </div>
        <?php
    }
}
