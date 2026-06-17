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
