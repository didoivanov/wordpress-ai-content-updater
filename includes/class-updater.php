<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Self-update from a public GitHub repo using the "latest release" endpoint.
 *
 * Expectations:
 *  - Tag releases on GitHub with a semver tag (e.g. v0.2.0 or 0.2.0).
 *  - The release should include either:
 *      * a zip asset whose name contains the plugin slug, OR
 *      * the auto-generated source zip (we fall back to zipball_url).
 *
 * The unpacked zip MUST contain a folder named "ai-content-rewriter" with the plugin files.
 * If GitHub's source zip is used, WordPress will rename the extracted folder via upgrader_source_selection.
 */
class AICR_Updater {

    const TRANSIENT = 'aicr_remote_info';

    public function register() {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugins_api' ], 10, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_folder' ], 10, 4 );
        add_filter( 'plugin_action_links_' . AICR_BASENAME, [ $this, 'plugin_action_links' ] );
        add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
        add_action( 'admin_post_aicr_check_updates', [ $this, 'handle_check_updates' ] );
        add_action( 'admin_notices', [ $this, 'maybe_show_check_notice' ] );
    }

    /**
     * Render a notice after a manual update check completes.
     */
    public function maybe_show_check_notice() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        if ( empty( $_GET['aicr_update_check'] ) ) {
            return;
        }
        $status  = sanitize_key( wp_unslash( $_GET['aicr_update_check'] ) );
        $remote  = isset( $_GET['aicr_remote_version'] ) ? sanitize_text_field( wp_unslash( $_GET['aicr_remote_version'] ) ) : '';
        $class   = 'notice notice-success is-dismissible';
        $message = '';
        if ( 'available' === $status ) {
            $class   = 'notice notice-warning is-dismissible';
            $message = sprintf(
                /* translators: 1: remote version, 2: current version */
                esc_html__( 'AI Content Rewriter: update available — %1$s (you have %2$s). Visit the Plugins screen to update.', 'ai-content-rewriter' ),
                esc_html( $remote ),
                esc_html( AICR_VERSION )
            );
        } elseif ( 'checked' === $status ) {
            $message = sprintf(
                /* translators: %s: version */
                esc_html__( 'AI Content Rewriter is up to date (v%s).', 'ai-content-rewriter' ),
                esc_html( AICR_VERSION )
            );
        } elseif ( 'failed' === $status ) {
            $class   = 'notice notice-error is-dismissible';
            $message = esc_html__( 'AI Content Rewriter: could not contact GitHub to check for updates. Try again in a moment.', 'ai-content-rewriter' );
        }
        if ( $message ) {
            echo '<div class="' . esc_attr( $class ) . '"><p>' . $message . '</p></div>';
        }
    }

    /**
     * Add "Check for updates" to the action links on the Plugins screen.
     */
    public function plugin_action_links( $links ) {
        if ( ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=aicr_check_updates' ),
            'aicr_check_updates'
        );
        $extra = [
            'aicr-check' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'ai-content-rewriter' ) . '</a>',
        ];
        return array_merge( $extra, $links );
    }

    /**
     * Add a secondary "Check for updates" link in the plugin row meta (under the description).
     */
    public function plugin_row_meta( $links, $file ) {
        if ( $file !== AICR_BASENAME ) {
            return $links;
        }
        if ( ! current_user_can( 'update_plugins' ) ) {
            return $links;
        }
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=aicr_check_updates' ),
            'aicr_check_updates'
        );
        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'ai-content-rewriter' ) . '</a>';
        return $links;
    }

    /**
     * Manual "check for updates" handler. Clears caches, forces a GitHub fetch, redirects back.
     */
    public function handle_check_updates() {
        if ( ! current_user_can( 'update_plugins' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'ai-content-rewriter' ) );
        }
        check_admin_referer( 'aicr_check_updates' );

        // Drop our cached release info so the next fetch hits GitHub.
        delete_transient( self::TRANSIENT );

        // Force a fresh fetch immediately so we have current info.
        $info = $this->fetch_remote_info( true );

        // Tell WordPress to recheck plugin updates on the next request by clearing its transient.
        delete_site_transient( 'update_plugins' );
        // Also trigger WP's normal update check so the count badge updates.
        if ( function_exists( 'wp_update_plugins' ) ) {
            wp_update_plugins();
        }

        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = admin_url( 'plugins.php' );
        }

        $status = 'checked';
        if ( ! $info ) {
            $status = 'failed';
        } elseif ( ! empty( $info['version'] ) && version_compare( $info['version'], AICR_VERSION, '>' ) ) {
            $status = 'available';
        }
        $redirect = add_query_arg(
            [
                'aicr_update_check'   => $status,
                'aicr_remote_version' => isset( $info['version'] ) ? rawurlencode( $info['version'] ) : '',
            ],
            $redirect
        );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Fetch latest release info from GitHub.
     *
     * @param bool $force Force refresh.
     * @return array|null
     */
    public function fetch_remote_info( $force = false ) {
        if ( ! $force ) {
            $cached = get_transient( self::TRANSIENT );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $url  = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', AICR_GH_USER, AICR_GH_REPO );
        $resp = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ],
        ] );
        if ( is_wp_error( $resp ) ) {
            return null;
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( $code < 200 || $code >= 300 ) {
            return null;
        }
        $body = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
            return null;
        }
        $tag     = ltrim( $body['tag_name'], 'vV' );
        $package = $this->find_package_url( $body );
        $info = [
            'version'       => $tag,
            'package'       => $package,
            'html_url'      => isset( $body['html_url'] ) ? $body['html_url'] : '',
            'published_at'  => isset( $body['published_at'] ) ? $body['published_at'] : '',
            'changelog'     => isset( $body['body'] ) ? (string) $body['body'] : '',
        ];
        set_transient( self::TRANSIENT, $info, 6 * HOUR_IN_SECONDS );
        return $info;
    }

    private function find_package_url( $release ) {
        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                $name = isset( $asset['name'] ) ? strtolower( $asset['name'] ) : '';
                $url  = isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';
                if ( $url && '.zip' === substr( $name, -4 ) ) {
                    return $url;
                }
            }
        }
        return isset( $release['zipball_url'] ) ? $release['zipball_url'] : '';
    }

    public function inject_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }
        $info = $this->fetch_remote_info( false );
        if ( ! $info || empty( $info['version'] ) || empty( $info['package'] ) ) {
            return $transient;
        }
        if ( version_compare( $info['version'], AICR_VERSION, '<=' ) ) {
            return $transient;
        }
        $plugin_data = [
            'slug'        => AICR_SLUG,
            'plugin'      => AICR_BASENAME,
            'new_version' => $info['version'],
            'url'         => $info['html_url'],
            'package'     => $info['package'],
            'tested'      => get_bloginfo( 'version' ),
            'icons'       => [],
            'banners'     => [],
        ];
        $transient->response[ AICR_BASENAME ] = (object) $plugin_data;
        return $transient;
    }

    public function plugins_api( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( empty( $args->slug ) || $args->slug !== AICR_SLUG ) {
            return $result;
        }
        $info = $this->fetch_remote_info( false );
        if ( ! $info ) {
            return $result;
        }
        $res = (object) [
            'name'          => 'AI Content Rewriter',
            'slug'          => AICR_SLUG,
            'version'       => $info['version'],
            'author'        => 'K Web ltd',
            'homepage'      => $info['html_url'],
            'download_link' => $info['package'],
            'sections'      => [
                'description' => 'Rewrite WordPress pages, posts and custom post types (including ACF Pro flexible content/repeaters) with Anthropic Claude.',
                'changelog'   => '<pre>' . esc_html( $info['changelog'] ) . '</pre>',
            ],
        ];
        return $res;
    }

    /**
     * Rename the upgrader source folder to the plugin slug.
     * GitHub zips unpack to "<user>-<repo>-<sha>/", which would otherwise move the plugin to a different folder.
     */
    public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        global $wp_filesystem;
        if ( ! is_object( $wp_filesystem ) ) {
            return $source;
        }
        // Only touch our plugin.
        $is_ours = false;
        if ( isset( $hook_extra['plugin'] ) && AICR_BASENAME === $hook_extra['plugin'] ) {
            $is_ours = true;
        } elseif ( isset( $hook_extra['type'] ) && 'plugin' === $hook_extra['type'] && false !== strpos( $source, AICR_GH_REPO ) ) {
            $is_ours = true;
        }
        if ( ! $is_ours ) {
            return $source;
        }
        $desired = trailingslashit( $remote_source ) . AICR_SLUG;
        if ( $source === trailingslashit( $desired ) ) {
            return $source;
        }
        if ( $wp_filesystem->move( $source, $desired, true ) ) {
            return trailingslashit( $desired );
        }
        return $source;
    }
}
