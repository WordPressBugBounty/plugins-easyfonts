<?php
/**
 * Plugin Name: EasyFonts
 * Plugin URI: https://easywpstuff.com
 * Description: Automatically Host existing google fonts locally on your server
 * Version: 1.2.0
 * Author: Uzair
 * Author URI: https://easywpstuff.com
 * License: GPL2
 * Text Domain: easyfonts
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'EASYFONTS_VERSION', '1.2.0' );
define( 'EASYFONTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EASYFONTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EASYFONTS_UPLOAD_DIR', wp_upload_dir()['basedir'] . '/easyfonts' );
$upload_baseurl = wp_upload_dir()['baseurl'];
if ( is_ssl() ) {
    $upload_baseurl = set_url_scheme( $upload_baseurl, 'https' );
}
define( 'EASYFONTS_UPLOAD_URL', $upload_baseurl . '/easyfonts' );

require_once EASYFONTS_PLUGIN_DIR . '/lib/simple_html_dom.php';
include_once EASYFONTS_PLUGIN_DIR . '/inc/options.php';
include_once EASYFONTS_PLUGIN_DIR . '/inc/notices.php';

class EasyFonts {

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action( 'template_redirect', [ $this, 'maybe_start_buffering' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_options_styles' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_settings_link' ] );
        register_uninstall_hook( __FILE__, [ __CLASS__, 'uninstall' ] );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'easyfonts', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Get base URL considering SSL.
     *
     * @return string
     */
    private static function get_base_url() {
        return is_ssl() ? set_url_scheme( wp_upload_dir()['baseurl'], 'https' ) : wp_upload_dir()['baseurl'];
    }

    public function maybe_start_buffering() {
        if ( is_admin() ) {
            return;
        }

        if ( $this->should_process() ) {
            ob_start( [ $this, 'combined_callback' ] );
        }

        add_filter( 'wordpress_prepare_output', [ $this, 'after_smart_slider' ], 11 );
        add_filter( 'groovy_menu_final_output', [ $this, 'after_smart_slider' ], 11 );
    }

    private function should_process() {
        $options = get_option( 'easyfonts_options', [] );
        return ! empty( $options['host_link'] ) || ! empty( $options['host_import'] ) || ! empty( $options['process_fontface'] ) || ! empty( $options['remove_hints'] ) || ! empty( $options['remove_scripts'] );
    }

    public function combined_callback( $buffer ) {
        $options = get_option( 'easyfonts_options', [] );

        if ( ! empty( $options['host_link'] ) ) {
            $buffer = $this->process_content_link_tag( $buffer );
        }
        if ( ! empty( $options['host_import'] ) ) {
            $buffer = $this->process_content_import( $buffer );
        }
        if ( ! empty( $options['process_fontface'] ) ) {
            $buffer = $this->download_gstatic_fonts( $buffer );
        }
        if ( ! empty( $options['remove_hints'] ) ) {
            $buffer = $this->remove_resource_hints( $buffer );
        }
        if ( ! empty( $options['remove_scripts'] ) ) {
            $buffer = $this->remove_font_scripts( $buffer );
        }

        return $buffer;
    }

    public function after_smart_slider( $buffer ) {
        $options = get_option( 'easyfonts_options', [] );
        if ( ! empty( $options['host_link'] ) ) {
            $buffer = $this->process_content_link_tag( $buffer );
        }
        return $buffer;
    }

    /**
     * Fetch remote file content.
     *
     * @param string $url URL to fetch.
     * @return string|false Content or false on error.
     */
    private function get_remote_file( $url ) {
        $response = wp_remote_get( $url, [
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
        ] );
        if ( is_wp_error( $response ) ) {
            error_log( __( 'EasyFonts remote fetch error: ', 'easyfonts' ) . $response->get_error_message() );
            return false;
        }
        return wp_remote_retrieve_body( $response );
    }

    /**
     * Process @font-face in CSS and download fonts.
     *
     * @param string $css CSS content.
     * @return string Processed CSS.
     */
    private function process_font_face_declarations( $css ) {
        if ( preg_match_all( '/@font-face\s*\{([^}]+)\}/', $css, $matches ) ) {
            foreach ( $matches[1] as $match ) {
                if ( preg_match_all( '/src\s*:\s*([^;]+);/', $match, $srcs ) ) {
                    foreach ( $srcs[1] as $src ) {
                        if ( preg_match( '/url\(([^)]+)\)/', $src, $url_match ) ) {
                            $font_url = trim( $url_match[1], "'\"" );
                            $font_filename = substr( hash( 'sha256', $font_url ), 0, 10 ) . '.' . pathinfo( $font_url, PATHINFO_EXTENSION );
                            $local_path = EASYFONTS_UPLOAD_DIR . '/' . $font_filename;
                            if ( ! file_exists( $local_path ) ) {
                                $font_data = $this->get_remote_file( $font_url );
                                if ( $font_data !== false ) {
                                    file_put_contents( $local_path, $font_data );
                                }
                            }
                            $css = str_replace( $src, "url('" . EASYFONTS_UPLOAD_URL . "/" . $font_filename . "')", $css );
                        }
                    }
                }
            }
        }
        return $css;
    }

    private function process_html_with_dom( $content, $callback ) {
        $html = hgfl_str_get_html( $content, false, true, 'UTF-8', false, PHP_EOL, ' ' );
        if ( empty( $html ) ) {
            return $content;
        }
        $callback( $html );
        return $html->save();
    }

    private function process_content_link_tag( $content ) {
        return $this->process_html_with_dom( $content, function( $html ) {
            if ( ! wp_mkdir_p( EASYFONTS_UPLOAD_DIR ) ) {
                error_log( __( 'EasyFonts could not create directory: ', 'easyfonts' ) . EASYFONTS_UPLOAD_DIR );
                return;
            }
            $apply_bunny = apply_filters( 'easyfonts_bunnyfonts', true );
            $providers = [ 'fonts.googleapis.com' ];
            if ( $apply_bunny ) {
                $providers[] = 'fonts.bunny.net';
            }
            foreach ( $html->find( 'link[rel=stylesheet]' ) as $link ) {
                $href = $link->href;
                $matched = false;
                foreach ( $providers as $provider ) {
                    if ( strpos( $href, $provider ) !== false ) {
                        $matched = true;
                        break;
                    }
                }
                if ( ! $matched ) {
                    continue;
                }
                if ( strpos( $href, '//' ) === 0 ) {
                    $href = 'https:' . $href;
                }
                $decoded_url = rawurldecode( htmlspecialchars_decode( $href ) );
                $filename = substr( hash( 'sha256', $decoded_url ), 0, 10 ) . '.css';
                $local_path = EASYFONTS_UPLOAD_DIR . '/' . $filename;
                if ( ! file_exists( $local_path ) ) {
                    $css = $this->get_remote_file( $decoded_url );
                    if ( $css === false ) {
                        continue;
                    }
                    if ( ! preg_match_all( '/@font-face\s*\{([^}]+)\}/', $css, $matches ) ) {
                        continue;
                    }
                    $css = $this->process_font_face_declarations( $css );
                    file_put_contents( $local_path, $css );
                }
                $link->href = EASYFONTS_UPLOAD_URL . '/' . $filename;
            }
        } );
    }

    private function process_content_import( $content ) {
        return $this->process_html_with_dom( $content, function( $html ) {
            if ( ! wp_mkdir_p( EASYFONTS_UPLOAD_DIR ) ) {
                error_log( __( 'EasyFonts could not create directory: ', 'easyfonts' ) . EASYFONTS_UPLOAD_DIR );
                return;
            }
            foreach ( $html->find( 'style' ) as $style ) {
                if ( preg_match_all( '/@import\s+(url\()?\s*([^\)]+)\s*(\))?/', $style->innertext, $matches ) ) {
                    foreach ( $matches[2] as $match ) {
                        if ( strpos( $match, 'fonts.googleapis.com' ) === false ) {
                            continue;
                        }
                        $url = trim( $match, "'\"" );
                        if ( strpos( $url, '//' ) === 0 ) {
                            $url = 'https:' . $url;
                        }
                        $decoded_url = rawurldecode( htmlspecialchars_decode( $url ) );
                        $filename = substr( hash( 'sha256', $decoded_url ), 0, 10 ) . '.css';
                        $local_path = EASYFONTS_UPLOAD_DIR . '/' . $filename;
                        if ( ! file_exists( $local_path ) ) {
                            $css = $this->get_remote_file( $decoded_url );
                            if ( $css === false ) {
                                continue;
                            }
                            $css = $this->process_font_face_declarations( $css );
                            file_put_contents( $local_path, $css );
                        }
                        $style->innertext = str_replace( $match, EASYFONTS_UPLOAD_URL . '/' . $filename, $style->innertext );
                    }
                }
            }
        } );
    }

    private function remove_resource_hints( $content ) {
        return $this->process_html_with_dom( $content, function( $html ) {
            $links = $html->find( 'link[rel=preload], link[rel=preconnect], link[rel=dns-prefetch]' );
            foreach ( $links as $link ) {
                if ( preg_match( '/(https:\/\/|\/\/)(fonts\.googleapis\.com|fonts\.gstatic\.com)/', $link->href ) ) {
                    $link->outertext = '';
                }
            }
            $styles = $html->find( 'style' );
            foreach ( $styles as $style ) {
                if ( strpos( $style->innertext, 'fonts.googleapis.com' ) !== false || strpos( $style->innertext, 'fonts.gstatic.com' ) !== false ) {
                    $style->innertext = preg_replace( '#/\*(?:[^*]*(?:\*(?!/))*)*\*/#', '', $style->innertext );
                }
            }
        } );
    }

    private function remove_font_scripts( $content ) {
        return $this->process_html_with_dom( $content, function( $html ) {
            $scripts = $html->find( 'script' );
            foreach ( $scripts as $script ) {
                if ( strpos( $script->innertext, 'WebFontConfig' ) !== false || strpos( $script->innertext, 'webfont.js' ) !== false ) {
                    $script->outertext = '';
                }
            }
        } );
    }

    private function download_gstatic_fonts( $content ) {
        if ( ! wp_mkdir_p( EASYFONTS_UPLOAD_DIR ) ) {
            error_log( __( 'EasyFonts could not create directory: ', 'easyfonts' ) . EASYFONTS_UPLOAD_DIR );
            return $content;
        }
        $content = preg_replace( '/(url\s?\(["\']?)\/\/(fonts\.gstatic\.com[^"\']+)/', '$1https://$2', $content );
        $html = hgfl_str_get_html( $content, false, true, 'UTF-8', false, PHP_EOL, ' ' );
        if ( empty( $html ) ) {
            return $content;
        }
        foreach ( $html->find( 'style' ) as $style ) {
            if ( preg_match_all( '/url\(([^)]+)\)/', $style->innertext, $matches ) ) {
                foreach ( $matches[1] as $match ) {
                    $url = trim( $match, "'\"" );
                    if ( strpos( $url, '//' ) === 0 ) {
                        $url = 'https:' . $url;
                    }
                    if ( strpos( $url, 'fonts.gstatic.com' ) === false ) {
                        continue;
                    }
                    $path_parts = pathinfo( $url );
                    $extension = $path_parts['extension'] ?? '';
                    if ( empty( $extension ) ) {
                        $extension = strpos( $url, '/l/font' ) !== false ? 'svg' : 'woff2';
                    }
                    $filename = substr( hash( 'sha256', $url ), 0, 10 ) . '.' . $extension;
                    $local_path = EASYFONTS_UPLOAD_DIR . '/' . $filename;
                    if ( ! file_exists( $local_path ) ) {
                        $font_data = $this->get_remote_file( $url );
                        if ( $font_data !== false ) {
                            file_put_contents( $local_path, $font_data );
                        }
                    }
                    $content = str_replace( $url, EASYFONTS_UPLOAD_URL . '/' . $filename, $content );
                }
            }
        }
        return $content;
    }

    public function enqueue_options_styles() {
        if ( 'settings_page_easyfonts' === get_current_screen()->id ) {
            wp_enqueue_style( 'easyfonts-options-styles', EASYFONTS_PLUGIN_URL . 'assets/style.css', [], EASYFONTS_VERSION );
        }
    }

    public function add_settings_link( $links ) {
        $settings_link = '<a href="options-general.php?page=easyfonts">' . __( 'Settings', 'easyfonts' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public static function uninstall() {
        delete_option( 'easyfonts_options' );
        $dir = EASYFONTS_UPLOAD_DIR;
        if ( is_dir( $dir ) ) {
            $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
            foreach ( $files as $fileinfo ) {
                $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
                $todo( $fileinfo->getRealPath() );
            }
            rmdir( $dir );
        }
    }
}

new EasyFonts();