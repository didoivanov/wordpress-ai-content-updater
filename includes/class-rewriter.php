<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Core rewriting logic. Builds a structured payload (post content + selected ACF fields),
 * sends it to Claude, then unpacks the response back into the same structure.
 *
 * Strategy:
 *  - We pack everything into a JSON document where each piece of content is keyed by a unique id.
 *  - We instruct Claude to return the SAME JSON shape with rewritten values only.
 *  - On apply, we walk the JSON and write each value back to its origin (post field or ACF field).
 *
 * This keeps ACF Pro flexible-content / repeater structures intact while still letting the model
 * see the whole context in one call.
 */
class AICR_Rewriter {

    /** @var AICR_Anthropic_Client */
    private $client;
    /** @var AICR_Settings */
    private $settings;

    public function __construct( AICR_Anthropic_Client $client, AICR_Settings $settings ) {
        $this->client   = $client;
        $this->settings = $settings;
    }

    /**
     * Build the structured payload for a post.
     *
     * @return array{items: array<int,array{id:string,label:string,type:string,format:string,value:string,origin:array}>}
     */
    public function build_payload( $post_id, $field_selection = null ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'items' => [] ];
        }
        $opts  = $this->settings->get();
        $items = [];

        // Title
        if ( ! empty( $opts['rewrite_title'] ) && ( null === $field_selection || in_array( 'post_title', $field_selection, true ) ) ) {
            $items[] = [
                'id'     => 'post_title',
                'label'  => 'Post title',
                'type'   => 'post_field',
                'format' => 'text',
                'value'  => (string) $post->post_title,
                'origin' => [ 'kind' => 'post', 'field' => 'post_title' ],
            ];
        }
        // Excerpt
        if ( ! empty( $opts['rewrite_excerpt'] ) && ( null === $field_selection || in_array( 'post_excerpt', $field_selection, true ) ) ) {
            $items[] = [
                'id'     => 'post_excerpt',
                'label'  => 'Excerpt',
                'type'   => 'post_field',
                'format' => 'text',
                'value'  => (string) $post->post_excerpt,
                'origin' => [ 'kind' => 'post', 'field' => 'post_excerpt' ],
            ];
        }

        // Content
        if ( null === $field_selection || in_array( 'post_content', $field_selection, true ) ) {
            $content = (string) $post->post_content;
            if ( '' !== trim( $content ) ) {
                $items[] = [
                    'id'     => 'post_content',
                    'label'  => 'Post content',
                    'type'   => 'post_field',
                    'format' => 'html',
                    'value'  => $content,
                    'origin' => [ 'kind' => 'post', 'field' => 'post_content' ],
                ];
            }
        }

        // ACF
        if ( ! empty( $opts['rewrite_acf'] ) && function_exists( 'get_field_objects' ) ) {
            $acf_items = $this->collect_acf_fields( $post_id, $field_selection );
            $items = array_merge( $items, $acf_items );
        }

        return [ 'items' => $items ];
    }

    /**
     * Recursively walk ACF fields (including flexible content and repeaters) and pick rewritable ones.
     */
    private function collect_acf_fields( $post_id, $field_selection = null ) {
        $opts = $this->settings->get();
        $allowed_types = (array) $opts['acf_field_types'];
        $items = [];

        $objects = get_field_objects( $post_id, false );
        if ( ! is_array( $objects ) ) {
            return $items;
        }

        foreach ( $objects as $name => $field ) {
            $this->walk_acf_field( $field, $name, $allowed_types, $items, $field_selection );
        }
        return $items;
    }

    private function walk_acf_field( $field, $path, $allowed_types, &$items, $field_selection ) {
        if ( ! is_array( $field ) || empty( $field['type'] ) ) {
            return;
        }
        $type = $field['type'];

        if ( in_array( $type, [ 'repeater', 'group' ], true ) ) {
            $value = isset( $field['value'] ) ? $field['value'] : [];
            if ( 'group' === $type ) {
                $rows = [ $value ];
            } else {
                $rows = is_array( $value ) ? $value : [];
            }
            if ( empty( $field['sub_fields'] ) || ! is_array( $field['sub_fields'] ) ) {
                return;
            }
            foreach ( $rows as $i => $row ) {
                foreach ( $field['sub_fields'] as $sub ) {
                    $sub_name = isset( $sub['name'] ) ? $sub['name'] : '';
                    if ( '' === $sub_name ) { continue; }
                    $sub_field = $sub;
                    $sub_field['value'] = isset( $row[ $sub_name ] ) ? $row[ $sub_name ] : null;
                    $sub_path = 'group' === $type
                        ? $path . '.' . $sub_name
                        : $path . '[' . $i . '].' . $sub_name;
                    $this->walk_acf_field( $sub_field, $sub_path, $allowed_types, $items, $field_selection );
                }
            }
            return;
        }

        if ( 'flexible_content' === $type ) {
            $rows    = isset( $field['value'] ) && is_array( $field['value'] ) ? $field['value'] : [];
            $layouts = [];
            if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
                foreach ( $field['layouts'] as $layout ) {
                    if ( isset( $layout['name'] ) ) {
                        $layouts[ $layout['name'] ] = $layout;
                    }
                }
            }
            foreach ( $rows as $i => $row ) {
                if ( ! is_array( $row ) || empty( $row['acf_fc_layout'] ) ) { continue; }
                $layout_name = $row['acf_fc_layout'];
                if ( empty( $layouts[ $layout_name ]['sub_fields'] ) ) { continue; }
                foreach ( $layouts[ $layout_name ]['sub_fields'] as $sub ) {
                    $sub_name = isset( $sub['name'] ) ? $sub['name'] : '';
                    if ( '' === $sub_name ) { continue; }
                    $sub_field = $sub;
                    $sub_field['value'] = isset( $row[ $sub_name ] ) ? $row[ $sub_name ] : null;
                    $sub_path = $path . '[' . $i . '|' . $layout_name . '].' . $sub_name;
                    $this->walk_acf_field( $sub_field, $sub_path, $allowed_types, $items, $field_selection );
                }
            }
            return;
        }

        if ( ! in_array( $type, $allowed_types, true ) ) {
            return;
        }
        $value = isset( $field['value'] ) ? $field['value'] : '';
        if ( ! is_string( $value ) ) {
            return;
        }
        if ( '' === trim( $value ) ) {
            return;
        }

        $id = 'acf:' . $path;
        if ( null !== $field_selection && ! in_array( $id, $field_selection, true ) ) {
            return;
        }

        $items[] = [
            'id'     => $id,
            'label'  => isset( $field['label'] ) ? $field['label'] : $path,
            'type'   => 'acf',
            'format' => ( 'wysiwyg' === $type ) ? 'html' : 'text',
            'value'  => $value,
            'origin' => [ 'kind' => 'acf', 'path' => $path, 'field_type' => $type ],
        ];
    }

    /**
     * Streaming variant: invokes $emit(event, data) at each step so the UI can show fine-grained progress.
     * Records usage rows for both successful and failed calls.
     */
    public function stream_preview( $post_id, $field_selection = null, $extra_instructions = '', $emit = null ) {
        if ( ! is_callable( $emit ) ) {
            $emit = function ( $e, $d = [] ) {};
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            $emit( 'error', [ 'message' => __( 'Post not found.', 'ai-content-rewriter' ) ] );
            return [ 'ok' => false, 'error' => __( 'Post not found.', 'ai-content-rewriter' ) ];
        }

        $emit( 'status', [ 'message' => __( 'Collecting fields...', 'ai-content-rewriter' ) ] );
        $payload = $this->build_payload( $post_id, $field_selection );
        if ( empty( $payload['items'] ) ) {
            $msg = __( 'Nothing to rewrite. Enable fields in settings or select fields on the edit screen.', 'ai-content-rewriter' );
            $emit( 'error', [ 'message' => $msg ] );
            return [ 'ok' => false, 'error' => $msg ];
        }

        $opts        = $this->settings->get();
        $system      = $opts['system_prompt'];
        $user_prompt = $this->settings->get_prompt_for_type( $post->post_type );
        if ( $extra_instructions ) {
            $user_prompt .= "\n\n--- ADDITIONAL INSTRUCTIONS ---\n" . $extra_instructions;
        }

        $total = count( $payload['items'] );
        $emit( 'status', [ 'message' => sprintf( _n( 'Found %d field to rewrite.', 'Found %d fields to rewrite.', $total, 'ai-content-rewriter' ), $total ) ] );

        $preview    = [];
        $originals  = [];
        $meta       = [];
        $errors     = [];
        $total_cost = 0.0;

        $index = 0;
        foreach ( $payload['items'] as $item ) {
            $index++;
            $originals[ $item['id'] ] = $item['value'];
            $meta[ $item['id'] ] = [
                'label'  => $item['label'],
                'format' => $item['format'],
                'origin' => $item['origin'],
            ];

            $emit( 'field_start', [
                'index'  => $index,
                'total'  => $total,
                'id'     => $item['id'],
                'label'  => $item['label'],
                'format' => $item['format'],
                'chars'  => strlen( $item['value'] ),
            ] );
            $emit( 'field_sending', [ 'id' => $item['id'], 'model' => $opts['model'] ] );

            $started = microtime( true );
            $resp = $this->client->rewrite_field(
                $system,
                $user_prompt,
                $item['label'],
                $item['format'],
                $item['value']
            );
            $elapsed = microtime( true ) - $started;

            $usage = isset( $resp['usage'] ) ? $resp['usage'] : [ 'input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_tokens' => 0, 'cache_read_tokens' => 0 ];
            $model_used = isset( $resp['model'] ) ? $resp['model'] : $opts['model'];

            if ( empty( $resp['ok'] ) ) {
                $err = isset( $resp['error'] ) ? $resp['error'] : 'Unknown error';
                $errors[ $item['id'] ] = $err;
                $preview[ $item['id'] ] = $item['value'];
                AICR_Usage::record( [
                    'post_id'               => $post_id,
                    'post_type'             => $post->post_type,
                    'model'                 => $model_used,
                    'label'                 => $item['label'],
                    'input_tokens'          => $usage['input_tokens'],
                    'output_tokens'         => $usage['output_tokens'],
                    'cache_creation_tokens' => $usage['cache_creation_tokens'],
                    'cache_read_tokens'     => $usage['cache_read_tokens'],
                    'status'                => 'error',
                    'error_message'         => $err,
                ] );
                $emit( 'field_error', [
                    'id'      => $item['id'],
                    'message' => $err,
                    'elapsed' => round( $elapsed, 2 ),
                ] );
                continue;
            }

            $preview[ $item['id'] ] = $resp['text'];
            $cost = AICR_Usage::compute_cost( $model_used, $usage['input_tokens'], $usage['output_tokens'] );
            $total_cost += $cost['total'];

            AICR_Usage::record( [
                'post_id'               => $post_id,
                'post_type'             => $post->post_type,
                'model'                 => $model_used,
                'label'                 => $item['label'],
                'input_tokens'          => $usage['input_tokens'],
                'output_tokens'         => $usage['output_tokens'],
                'cache_creation_tokens' => $usage['cache_creation_tokens'],
                'cache_read_tokens'     => $usage['cache_read_tokens'],
                'status'                => 'ok',
            ] );

            $emit( 'field_done', [
                'id'             => $item['id'],
                'elapsed'        => round( $elapsed, 2 ),
                'input_tokens'   => $usage['input_tokens'],
                'output_tokens'  => $usage['output_tokens'],
                'cost'           => round( $cost['total'], 5 ),
                'preview_chars'  => strlen( $resp['text'] ),
            ] );
        }

        $all_failed = count( $errors ) === count( $payload['items'] );
        if ( $all_failed ) {
            $first_err = $errors ? reset( $errors ) : __( 'No preview generated.', 'ai-content-rewriter' );
            $emit( 'error', [ 'message' => $first_err ] );
            return [ 'ok' => false, 'error' => $first_err ];
        }

        $emit( 'status', [ 'message' => sprintf( __( 'Done. Estimated cost: $%s', 'ai-content-rewriter' ), number_format( $total_cost, 5 ) ) ] );

        return [
            'ok'         => true,
            'preview'    => $preview,
            'originals'  => $originals,
            'meta'       => $meta,
            'errors'     => $errors,
            'total_cost' => $total_cost,
        ];
    }

    /**
     * Send each field to Claude in its own request (using tool-use for guaranteed structured output)
     * and assemble a preview map. Per-field calls eliminate the giant-JSON-roundtrip class of
     * parsing failures, especially with Gutenberg / ACF HTML.
     */
    public function generate_preview( $post_id, $field_selection = null, $extra_instructions = '' ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [ 'ok' => false, 'error' => __( 'Post not found.', 'ai-content-rewriter' ) ];
        }
        $payload = $this->build_payload( $post_id, $field_selection );
        if ( empty( $payload['items'] ) ) {
            return [ 'ok' => false, 'error' => __( 'Nothing to rewrite. Enable fields in settings or select fields on the edit screen.', 'ai-content-rewriter' ) ];
        }

        $opts   = $this->settings->get();
        $system = $opts['system_prompt'];

        $user_prompt = $this->settings->get_prompt_for_type( $post->post_type );
        if ( $extra_instructions ) {
            $user_prompt .= "\n\n--- ADDITIONAL INSTRUCTIONS ---\n" . $extra_instructions;
        }

        $preview   = [];
        $originals = [];
        $meta      = [];
        $errors    = [];

        foreach ( $payload['items'] as $item ) {
            $originals[ $item['id'] ] = $item['value'];
            $meta[ $item['id'] ] = [
                'label'  => $item['label'],
                'format' => $item['format'],
                'origin' => $item['origin'],
            ];

            $resp = $this->client->rewrite_field(
                $system,
                $user_prompt,
                $item['label'],
                $item['format'],
                $item['value']
            );

            if ( empty( $resp['ok'] ) ) {
                $errors[ $item['id'] ] = isset( $resp['error'] ) ? $resp['error'] : 'Unknown error';
                // Fall back to original so the UI still shows the field with a notice.
                $preview[ $item['id'] ] = $item['value'];
                continue;
            }
            $preview[ $item['id'] ] = $resp['text'];
        }

        // If every single field failed, surface the first error.
        $all_failed = count( $errors ) === count( $payload['items'] );
        if ( $all_failed ) {
            $first_err = $errors ? reset( $errors ) : __( 'No preview generated.', 'ai-content-rewriter' );
            return [ 'ok' => false, 'error' => $first_err ];
        }

        return [
            'ok'        => true,
            'preview'   => $preview,
            'originals' => $originals,
            'meta'      => $meta,
            'errors'    => $errors,
        ];
    }

    /**
     * Apply approved rewrites to the post.
     *
     * @param int   $post_id
     * @param array $approved  Map of id => rewritten string (only ids the user approved).
     * @param array $meta      Meta info captured at preview time (origins).
     * @return array{ok:bool,error?:string,applied?:int}
     */
    public function apply( $post_id, $approved, $meta ) {
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return [ 'ok' => false, 'error' => __( 'Permission denied.', 'ai-content-rewriter' ) ];
        }
        if ( empty( $approved ) || ! is_array( $approved ) ) {
            return [ 'ok' => false, 'error' => __( 'Nothing approved.', 'ai-content-rewriter' ) ];
        }

        $applied = 0;
        $post_update = [];
        foreach ( $approved as $id => $value ) {
            $info = isset( $meta[ $id ]['origin'] ) ? $meta[ $id ]['origin'] : null;
            if ( ! $info || empty( $info['kind'] ) ) { continue; }
            if ( 'post' === $info['kind'] ) {
                $field = isset( $info['field'] ) ? $info['field'] : '';
                if ( in_array( $field, [ 'post_title', 'post_content', 'post_excerpt' ], true ) ) {
                    $post_update[ $field ] = $value;
                    $applied++;
                }
            } elseif ( 'acf' === $info['kind'] && function_exists( 'update_field' ) ) {
                $path = isset( $info['path'] ) ? $info['path'] : '';
                if ( '' === $path ) { continue; }
                if ( $this->update_acf_by_path( $path, $value, $post_id ) ) {
                    $applied++;
                }
            }
        }

        if ( $post_update ) {
            $post_update['ID'] = $post_id;
            wp_update_post( $post_update, true );
        }

        return [ 'ok' => true, 'applied' => $applied ];
    }

    /**
     * Update an ACF field given its dotted/bracketed path.
     * Supports: name, name.sub, name[0].sub, name[0|layout].sub for flexible content.
     */
    private function update_acf_by_path( $path, $value, $post_id ) {
        // For flexible_content paths the layout marker exists; ACF still accepts
        // sub_field updates via update_sub_field for repeaters and flex content
        // when we know the row index. We use update_sub_field with an array path.

        $segments = $this->parse_path( $path );
        if ( count( $segments ) === 1 && is_string( $segments[0] ) ) {
            return (bool) update_field( $segments[0], $value, $post_id );
        }

        // Build selector array as update_sub_field expects: [ 'parent', row_index, 'subfield', ... ].
        $selector = [];
        foreach ( $segments as $seg ) {
            if ( is_array( $seg ) ) {
                // [index, ?layout, subname]
                $selector[] = $seg['parent'];
                $selector[] = $seg['index'] + 1; // ACF rows are 1-indexed for update_sub_field.
                if ( isset( $seg['child'] ) ) {
                    $selector[] = $seg['child'];
                }
            } else {
                $selector[] = $seg;
            }
        }
        if ( function_exists( 'update_sub_field' ) ) {
            return (bool) update_sub_field( $selector, $value, $post_id );
        }
        return false;
    }

    /**
     * Parse "name[0|layout].sub" into segments suitable for update_sub_field.
     * Returns array of strings (top-level fields) or arrays describing rows.
     */
    private function parse_path( $path ) {
        $out = [];
        $tokens = preg_split( '/(?<!\[)\.(?!\d)/', $path );
        foreach ( $tokens as $token ) {
            if ( preg_match( '/^([^\[]+)\[(\d+)(?:\|[^\]]+)?\](?:\.(.+))?$/', $token, $m ) ) {
                $entry = [
                    'parent' => $m[1],
                    'index'  => (int) $m[2],
                ];
                if ( isset( $m[3] ) && '' !== $m[3] ) {
                    $entry['child'] = $m[3];
                }
                $out[] = $entry;
            } else {
                $out[] = $token;
            }
        }
        return $out;
    }
}
