<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Thin wrapper around the Anthropic Messages API.
 */
class AICR_Anthropic_Client {

    const API_URL = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';

    /** @var AICR_Settings */
    private $settings;

    public function __construct( AICR_Settings $settings ) {
        $this->settings = $settings;
    }

    /**
     * Rewrite a SINGLE field using tool-use for guaranteed structured output.
     * This is dramatically more reliable than asking Claude to return JSON in plain text,
     * especially with HTML-heavy Gutenberg / ACF content.
     *
     * @param string $system  System prompt.
     * @param string $prompt  User instructions (global + per-type + extra).
     * @param string $label   Human-readable field label (for prompt context).
     * @param string $format  'text' or 'html'.
     * @param string $value   The actual content to rewrite.
     * @return array{ok:bool,text?:string,error?:string,raw?:array}
     */
    public function rewrite_field( $system, $prompt, $label, $format, $value ) {
        $opts = $this->settings->get();
        $api_key = isset( $opts['api_key'] ) ? trim( $opts['api_key'] ) : '';
        if ( '' === $api_key ) {
            return [ 'ok' => false, 'error' => __( 'Anthropic API key is not configured.', 'ai-content-rewriter' ) ];
        }

        $format_note = ( 'html' === $format )
            ? 'The content is HTML. Preserve ALL HTML tags, attributes, Gutenberg block comments (lines like <!-- wp:... --> and <!-- /wp:... -->), shortcodes, and overall structure exactly. Only change the human-readable text inside.'
            : 'The content is plain text. Return plain text only.';

        $user_msg  = $prompt;
        $user_msg .= "\n\nField label: " . $label;
        $user_msg .= "\nFormat: " . $format;
        $user_msg .= "\n\n" . $format_note;
        $user_msg .= "\n\nCall the submit_rewrite tool with the rewritten content as the `value` parameter. Do not respond in plain text.";
        $user_msg .= "\n\n--- CONTENT TO REWRITE ---\n";
        $user_msg .= $value;
        $user_msg .= "\n--- END CONTENT ---";

        $tool = [
            'name'         => 'submit_rewrite',
            'description'  => 'Submit the rewritten field value. Always use this tool to return your output.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'value' => [
                        'type'        => 'string',
                        'description' => 'The rewritten content. For HTML format, include all original HTML tags and Gutenberg block markers verbatim, only changing text inside.',
                    ],
                ],
                'required' => [ 'value' ],
            ],
        ];

        $body = [
            'model'       => $opts['model'],
            'max_tokens'  => (int) $opts['max_tokens'],
            'temperature' => (float) $opts['temperature'],
            'system'      => $system,
            'tools'       => [ $tool ],
            'tool_choice' => [ 'type' => 'tool', 'name' => 'submit_rewrite' ],
            'messages'    => [
                [ 'role' => 'user', 'content' => $user_msg ],
            ],
        ];

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 180,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false, 'error' => $response->get_error_message() ];
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $raw     = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = is_array( $decoded ) && isset( $decoded['error']['message'] )
                ? $decoded['error']['message']
                : sprintf( 'HTTP %d', $code );
            return [ 'ok' => false, 'error' => $msg, 'raw' => $decoded ];
        }

        $text = '';
        if ( is_array( $decoded ) && isset( $decoded['content'] ) && is_array( $decoded['content'] ) ) {
            foreach ( $decoded['content'] as $block ) {
                if ( isset( $block['type'] ) && 'tool_use' === $block['type'] && isset( $block['input']['value'] ) ) {
                    $text = (string) $block['input']['value'];
                    break;
                }
            }
            // Fallback: if the model returned plain text despite tool_choice, take it.
            if ( '' === $text ) {
                foreach ( $decoded['content'] as $block ) {
                    if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
                        $text .= $block['text'];
                    }
                }
                $text = $this->strip_code_fences( $text );
            }
        }

        if ( '' === $text ) {
            $stop = isset( $decoded['stop_reason'] ) ? $decoded['stop_reason'] : 'unknown';
            return [
                'ok'    => false,
                'error' => sprintf( __( 'Empty response from Anthropic (stop_reason: %s). Try lowering input size or raising max tokens.', 'ai-content-rewriter' ), $stop ),
                'raw'   => $decoded,
            ];
        }

        return [ 'ok' => true, 'text' => $text, 'raw' => $decoded ];
    }

    /**
     * Quick connectivity test (1 token).
     */
    public function ping() {
        $opts = $this->settings->get();
        if ( empty( $opts['api_key'] ) ) {
            return [ 'ok' => false, 'error' => __( 'No API key set.', 'ai-content-rewriter' ) ];
        }
        $resp = wp_remote_post( self::API_URL, [
            'timeout' => 20,
            'headers' => [
                'x-api-key'         => $opts['api_key'],
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'      => $opts['model'],
                'max_tokens' => 16,
                'messages'   => [ [ 'role' => 'user', 'content' => 'ping' ] ],
            ] ),
        ] );

        if ( is_wp_error( $resp ) ) {
            return [ 'ok' => false, 'error' => $resp->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code >= 200 && $code < 300 ) {
            return [ 'ok' => true ];
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        $msg  = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
        return [ 'ok' => false, 'error' => $msg ];
    }

    private function strip_code_fences( $text ) {
        $text = trim( $text );
        if ( preg_match( '/^```[a-zA-Z0-9_-]*\s*\n(.*)\n```\s*$/s', $text, $m ) ) {
            return trim( $m[1] );
        }
        return $text;
    }
}
