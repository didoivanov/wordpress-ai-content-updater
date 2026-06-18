<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Top-level admin menu with Configuration + Costs subpages.
 * The Configuration page reuses the form rendered by AICR_Settings.
 */
class AICR_Admin {

    const PARENT_SLUG = 'ai-content-rewriter';
    const COSTS_SLUG  = 'ai-content-rewriter-costs';

    /** @var AICR_Settings */
    private $settings;

    public function __construct( AICR_Settings $settings ) {
        $this->settings = $settings;
    }

    public function register() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
    }

    public function add_menu() {
        $cap = AICR_Settings::CAPABILITY;

        // Top-level entry.
        add_menu_page(
            __( 'AI Content Rewriter', 'ai-content-rewriter' ),
            __( 'AI Rewriter', 'ai-content-rewriter' ),
            $cap,
            self::PARENT_SLUG,
            [ $this->settings, 'render_page' ],
            'dashicons-edit-large',
            58
        );

        // Configuration (first item — same as parent).
        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Configuration', 'ai-content-rewriter' ),
            __( 'Configuration', 'ai-content-rewriter' ),
            $cap,
            self::PARENT_SLUG,
            [ $this->settings, 'render_page' ]
        );

        // Costs.
        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Costs', 'ai-content-rewriter' ),
            __( 'Costs', 'ai-content-rewriter' ),
            $cap,
            self::COSTS_SLUG,
            [ $this, 'render_costs' ]
        );
    }

    public function render_costs() {
        if ( ! current_user_can( AICR_Settings::CAPABILITY ) ) { return; }

        $totals_all   = AICR_Usage::totals();
        $totals_30d   = AICR_Usage::totals( 'created_at >= %s', [ gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ] );
        $totals_today = AICR_Usage::totals( 'created_at >= %s', [ gmdate( 'Y-m-d 00:00:00' ) ] );

        $by_model   = AICR_Usage::group_by( 'model' );
        $by_type    = AICR_Usage::group_by( 'post_type' );
        $by_day     = AICR_Usage::group_by( 'DATE(created_at)', 'created_at >= %s', [ gmdate( 'Y-m-d 00:00:00', time() - 30 * DAY_IN_SECONDS ) ] );
        $recent     = AICR_Usage::recent( 30 );

        $opts   = $this->settings->get();
        $prices = isset( $opts['prices'] ) && is_array( $opts['prices'] ) ? $opts['prices'] : AICR_Usage::default_prices();
        ?>
        <div class="wrap aicr-costs">
            <h1><?php esc_html_e( 'AI Content Rewriter — Costs', 'ai-content-rewriter' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Estimated spend based on Anthropic token usage reported by the API. Edit prices in Configuration → Pricing.', 'ai-content-rewriter' ); ?>
            </p>

            <div class="aicr-cards">
                <div class="aicr-card">
                    <div class="aicr-card-label"><?php esc_html_e( 'Today', 'ai-content-rewriter' ); ?></div>
                    <div class="aicr-card-value">$<?php echo esc_html( number_format( (float) $totals_today['total_cost'], 4 ) ); ?></div>
                    <div class="aicr-card-sub"><?php
                        /* translators: %1$d calls, %2$s tokens */
                        echo esc_html( sprintf( __( '%1$d calls · %2$s tokens out', 'ai-content-rewriter' ),
                            (int) $totals_today['calls'],
                            number_format_i18n( (int) $totals_today['output_tokens'] )
                        ) );
                    ?></div>
                </div>
                <div class="aicr-card">
                    <div class="aicr-card-label"><?php esc_html_e( 'Last 30 days', 'ai-content-rewriter' ); ?></div>
                    <div class="aicr-card-value">$<?php echo esc_html( number_format( (float) $totals_30d['total_cost'], 4 ) ); ?></div>
                    <div class="aicr-card-sub"><?php
                        echo esc_html( sprintf( __( '%1$d calls · %2$s tokens out', 'ai-content-rewriter' ),
                            (int) $totals_30d['calls'],
                            number_format_i18n( (int) $totals_30d['output_tokens'] )
                        ) );
                    ?></div>
                </div>
                <div class="aicr-card">
                    <div class="aicr-card-label"><?php esc_html_e( 'All time', 'ai-content-rewriter' ); ?></div>
                    <div class="aicr-card-value">$<?php echo esc_html( number_format( (float) $totals_all['total_cost'], 4 ) ); ?></div>
                    <div class="aicr-card-sub"><?php
                        echo esc_html( sprintf( __( '%1$d calls · %2$s tokens out', 'ai-content-rewriter' ),
                            (int) $totals_all['calls'],
                            number_format_i18n( (int) $totals_all['output_tokens'] )
                        ) );
                    ?></div>
                </div>
            </div>

            <h2><?php esc_html_e( 'Spend by model', 'ai-content-rewriter' ); ?></h2>
            <?php $this->render_table( $by_model, __( 'Model', 'ai-content-rewriter' ) ); ?>

            <h2><?php esc_html_e( 'Spend by post type', 'ai-content-rewriter' ); ?></h2>
            <?php $this->render_table( $by_type, __( 'Post type', 'ai-content-rewriter' ) ); ?>

            <h2><?php esc_html_e( 'Daily spend (last 30 days)', 'ai-content-rewriter' ); ?></h2>
            <?php $this->render_table( $by_day, __( 'Date', 'ai-content-rewriter' ) ); ?>

            <h2><?php esc_html_e( 'Recent calls', 'ai-content-rewriter' ); ?></h2>
            <table class="widefat striped aicr-recent">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'When', 'ai-content-rewriter' ); ?></th>
                        <th><?php esc_html_e( 'Post', 'ai-content-rewriter' ); ?></th>
                        <th><?php esc_html_e( 'Field', 'ai-content-rewriter' ); ?></th>
                        <th><?php esc_html_e( 'Model', 'ai-content-rewriter' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'ai-content-rewriter' ); ?></th>
                        <th class="num"><?php esc_html_e( 'In', 'ai-content-rewriter' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Out', 'ai-content-rewriter' ); ?></th>
                        <th class="num"><?php esc_html_e( 'Cost', 'ai-content-rewriter' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $recent ) ) : ?>
                        <tr><td colspan="8"><em><?php esc_html_e( 'No usage recorded yet.', 'ai-content-rewriter' ); ?></em></td></tr>
                    <?php else : foreach ( $recent as $r ) :
                        $edit = $r['post_id'] ? get_edit_post_link( (int) $r['post_id'] ) : '';
                        $title = $r['post_id'] ? get_the_title( (int) $r['post_id'] ) : '—';
                        ?>
                        <tr>
                            <td><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
                            <td><?php if ( $edit ) : ?><a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $title ); ?></a><?php else : echo esc_html( $title ); endif; ?></td>
                            <td><?php echo esc_html( $r['label'] ); ?></td>
                            <td><?php echo esc_html( $r['model'] ); ?></td>
                            <td><?php echo esc_html( $r['status'] ); ?><?php if ( ! empty( $r['error_message'] ) ) : ?> <span title="<?php echo esc_attr( $r['error_message'] ); ?>">⚠</span><?php endif; ?></td>
                            <td class="num"><?php echo esc_html( number_format_i18n( (int) $r['input_tokens'] ) ); ?></td>
                            <td class="num"><?php echo esc_html( number_format_i18n( (int) $r['output_tokens'] ) ); ?></td>
                            <td class="num">$<?php echo esc_html( number_format( (float) $r['total_cost'], 5 ) ); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Current pricing (USD per 1M tokens)', 'ai-content-rewriter' ); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Model', 'ai-content-rewriter' ); ?></th>
                    <th class="num"><?php esc_html_e( 'Input', 'ai-content-rewriter' ); ?></th>
                    <th class="num"><?php esc_html_e( 'Output', 'ai-content-rewriter' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $prices as $model => $p ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $model ); ?></code></td>
                        <td class="num">$<?php echo esc_html( number_format( (float) $p['input'], 2 ) ); ?></td>
                        <td class="num">$<?php echo esc_html( number_format( (float) $p['output'], 2 ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_table( $rows, $label ) {
        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No data.', 'ai-content-rewriter' ) . '</em></p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead><tr>
                <th><?php echo esc_html( $label ); ?></th>
                <th class="num"><?php esc_html_e( 'Calls', 'ai-content-rewriter' ); ?></th>
                <th class="num"><?php esc_html_e( 'Input tokens', 'ai-content-rewriter' ); ?></th>
                <th class="num"><?php esc_html_e( 'Output tokens', 'ai-content-rewriter' ); ?></th>
                <th class="num"><?php esc_html_e( 'Cost (USD)', 'ai-content-rewriter' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $rows as $r ) : ?>
                <tr>
                    <td><?php echo esc_html( $r['bucket'] ); ?></td>
                    <td class="num"><?php echo esc_html( number_format_i18n( (int) $r['calls'] ) ); ?></td>
                    <td class="num"><?php echo esc_html( number_format_i18n( (int) $r['input_tokens'] ) ); ?></td>
                    <td class="num"><?php echo esc_html( number_format_i18n( (int) $r['output_tokens'] ) ); ?></td>
                    <td class="num">$<?php echo esc_html( number_format( (float) $r['total_cost'], 4 ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
