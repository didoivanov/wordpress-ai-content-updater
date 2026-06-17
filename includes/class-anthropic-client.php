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
     * Send a single rewrite request.
     *
     * @param string $system   System prompt.
     * @param string $prompt   User instructions.
     * @param string $payload  The actual content to rewrite (will be passed as the user message body).
     * @return array{ok:bool,text?:string,error?:string,raw?:array}
     */
    public function rewrite( $system, $prompt, $payload ) {
        $opts = $this->settings->get();
        $api_key = isset( $opts['api_key'] ) ? trim( $opts['api_key'] ) : '';
        if ( '' === $api_key ) {
            return [ 'ok' => false, 'error' => __( 'Anthropic API key is not configured.', 'ai-content-rewriter' ) ];
        }

        $user_msg  = $prompt;
        $user_msg .= "\n\n--- CONTENT TO REWRITE ---\n";
        $user_msg .= $payload;
        $user_msg .= "\n--- END CONTENT ---";

        $body = [
            'model'       => $opts['model'],
            'max_tokens'  => (int) $opts['max_tokens'],
            'temperature' => (float) $opts['temperature'],
            'system'      => $system,
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => $user_msg,
                ],
            ],
        ];

        $response = wp_remote_post( self::API_URL, [
            'timeout' => 90,
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
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
                if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
                    $text .= $block['text'];
                }
            }
        }

        if ( '' === $text ) {
            return [ 'ok' => false, 'error' => __( 'Empty response from Anthropic.', 'ai-content-rewriter' ), 'raw' => $decoded ];
        }

        return [ 'ok' => true, 'text' => $this->strip_code_fences( $text ), 'raw' => $decoded ];
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
        // Strip wrapping ```lang ... ``` if present.
        if ( preg_match( '/^```[a-zA-Z0-9_-]*\s*\n(.*)\n```\s*$/s', $text, $m ) ) {
            return trim( $m[1] );
        }
        return $text;
    }
}
