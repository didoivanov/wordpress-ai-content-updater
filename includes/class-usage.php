<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Token usage + cost tracking.
 *
 * Stores one row per Anthropic API call in a custom table. The Costs admin page
 * aggregates over this table.
 */
class AICR_Usage {

    const TABLE = 'aicr_usage';

    /** Default prices in USD per 1,000,000 tokens. Editable in settings. */
    public static function default_prices() {
        return [
            'claude-opus-4-5'    => [ 'input' => 15.0,  'output' => 75.0 ],
            'claude-sonnet-4-5'  => [ 'input' => 3.0,   'output' => 15.0 ],
            'claude-haiku-4-5'   => [ 'input' => 1.0,   'output' => 5.0 ],
            // Older models kept for back-compat:
            'claude-3-5-sonnet-latest' => [ 'input' => 3.0,  'output' => 15.0 ],
            'claude-3-5-haiku-latest'  => [ 'input' => 0.8,  'output' => 4.0 ],
            'claude-3-opus-latest'     => [ 'input' => 15.0, 'output' => 75.0 ],
        ];
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install() {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            post_type VARCHAR(40) NOT NULL DEFAULT '',
            model VARCHAR(64) NOT NULL DEFAULT '',
            label VARCHAR(191) NOT NULL DEFAULT '',
            input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            cache_creation_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            cache_read_tokens INT UNSIGNED NOT NULL DEFAULT 0,
            input_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
            output_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
            total_cost DECIMAL(12,6) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'ok',
            error_message TEXT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY post_id (post_id),
            KEY model (model)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Compute USD cost from token counts.
     */
    public static function compute_cost( $model, $input_tokens, $output_tokens, $prices = null ) {
        if ( null === $prices ) {
            $opts = get_option( AICR_Settings::OPTION_KEY, [] );
            $prices = isset( $opts['prices'] ) && is_array( $opts['prices'] ) ? $opts['prices'] : self::default_prices();
        }
        $p = isset( $prices[ $model ] ) ? $prices[ $model ] : null;
        if ( ! $p ) {
            // Try a fuzzy match by prefix.
            foreach ( $prices as $key => $val ) {
                if ( 0 === strpos( $model, $key ) || 0 === strpos( $key, $model ) ) {
                    $p = $val; break;
                }
            }
        }
        if ( ! $p ) {
            return [ 'input' => 0.0, 'output' => 0.0, 'total' => 0.0 ];
        }
        $in_cost  = ( (float) $input_tokens / 1000000.0 ) * (float) $p['input'];
        $out_cost = ( (float) $output_tokens / 1000000.0 ) * (float) $p['output'];
        return [
            'input'  => $in_cost,
            'output' => $out_cost,
            'total'  => $in_cost + $out_cost,
        ];
    }

    /**
     * Record one API call.
     *
     * @param array $row Keys: post_id, post_type, model, label, input_tokens, output_tokens,
     *                  cache_creation_tokens, cache_read_tokens, status, error_message.
     */
    public static function record( $row ) {
        global $wpdb;
        $defaults = [
            'post_id'               => 0,
            'post_type'             => '',
            'model'                 => '',
            'label'                 => '',
            'input_tokens'          => 0,
            'output_tokens'         => 0,
            'cache_creation_tokens' => 0,
            'cache_read_tokens'     => 0,
            'status'                => 'ok',
            'error_message'         => null,
        ];
        $row = array_merge( $defaults, $row );
        $cost = self::compute_cost( $row['model'], $row['input_tokens'], $row['output_tokens'] );

        $wpdb->insert( self::table_name(), [
            'created_at'            => current_time( 'mysql', true ),
            'user_id'               => get_current_user_id(),
            'post_id'               => (int) $row['post_id'],
            'post_type'             => substr( (string) $row['post_type'], 0, 40 ),
            'model'                 => substr( (string) $row['model'], 0, 64 ),
            'label'                 => substr( (string) $row['label'], 0, 191 ),
            'input_tokens'          => (int) $row['input_tokens'],
            'output_tokens'         => (int) $row['output_tokens'],
            'cache_creation_tokens' => (int) $row['cache_creation_tokens'],
            'cache_read_tokens'     => (int) $row['cache_read_tokens'],
            'input_cost'            => $cost['input'],
            'output_cost'           => $cost['output'],
            'total_cost'            => $cost['total'],
            'status'                => substr( (string) $row['status'], 0, 20 ),
            'error_message'         => $row['error_message'],
        ] );

        return $wpdb->insert_id;
    }

    public static function totals( $where_sql = '', $args = [] ) {
        global $wpdb;
        $table = self::table_name();
        $sql = "SELECT
            COUNT(*) AS calls,
            COALESCE(SUM(input_tokens),0)  AS input_tokens,
            COALESCE(SUM(output_tokens),0) AS output_tokens,
            COALESCE(SUM(total_cost),0)    AS total_cost
            FROM $table " . ( $where_sql ? "WHERE $where_sql" : '' );
        if ( $args ) {
            $sql = $wpdb->prepare( $sql, $args );
        }
        return $wpdb->get_row( $sql, ARRAY_A );
    }

    public static function group_by( $column, $where_sql = '', $args = [], $limit = 50 ) {
        global $wpdb;
        $table = self::table_name();
        $allowed = [ 'model', 'post_type', 'post_id', 'DATE(created_at)' ];
        if ( ! in_array( $column, $allowed, true ) ) {
            return [];
        }
        $sql = "SELECT $column AS bucket,
            COUNT(*) AS calls,
            COALESCE(SUM(input_tokens),0)  AS input_tokens,
            COALESCE(SUM(output_tokens),0) AS output_tokens,
            COALESCE(SUM(total_cost),0)    AS total_cost
            FROM $table " . ( $where_sql ? "WHERE $where_sql" : '' ) . "
            GROUP BY $column
            ORDER BY total_cost DESC
            LIMIT " . (int) $limit;
        if ( $args ) {
            $sql = $wpdb->prepare( $sql, $args );
        }
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    public static function recent( $limit = 50 ) {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table ORDER BY id DESC LIMIT %d",
            $limit
        ), ARRAY_A );
    }
}
