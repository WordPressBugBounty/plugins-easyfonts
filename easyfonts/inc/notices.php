<?php
/**
 * EasyFonts Notices
 *
 * Handles admin notices for the EasyFonts plugin.
 *
 * @package EasyFonts
 * @since 1.2.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

class EasyFonts_Notices {

    public function __construct() {
        add_action( 'admin_notices', [ $this, 'check_incompatible_plugins' ] );
    }

    /**
     * Check for incompatible plugins and display notices if active.
     */
    public function check_incompatible_plugins() {
        /* Only show on the EasyFonts settings page to avoid cluttering other admin areas.
        if ( 'settings_page_easyfonts' !== get_current_screen()->id ) {
            return;
        }*/

        // Check for 'Local Google Fonts'.
        if ( is_plugin_active( 'local-google-fonts/local-google-fonts.php' ) ) {
            $this->display_notice( __( 'The "Local Google Fonts" plugin is active and may cause conflicts with EasyFonts. Please deactivate it to avoid issues.', 'easyfonts' ) );
        }

        // Check for 'OMGF | Host Google Fonts Locally'.
        if ( is_plugin_active( 'host-webfonts-local/host-webfonts-local.php' ) ) {
            $this->display_notice( __( 'The "OMGF | Host Google Fonts Locally" plugin is active and may cause conflicts with EasyFonts. Please deactivate it to avoid issues.', 'easyfonts' ) );
        }
    }

    /**
     * Display an error notice.
     *
     * @param string $message The notice message.
     */
    private function display_notice( $message ) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    }
}

new EasyFonts_Notices();