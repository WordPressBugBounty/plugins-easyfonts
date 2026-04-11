<?php
if ( ! defined( 'WPINC' ) ) {
    die;
}

class EasyFonts_Options {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_options_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_speed_check_assets' ] );
    }

    public function add_options_page() {
        add_options_page(
            __( 'Easy Fonts Options', 'easyfonts' ),
            __( 'Easy Fonts', 'easyfonts' ),
            'manage_options',
            'easyfonts',
            [ $this, 'options_page_html' ]
        );
    }

    public function register_settings() {
        register_setting( 'easyfonts', 'easyfonts_options' );

        add_settings_section( 'easyfonts_main', __( 'Easy Fonts Settings', 'easyfonts' ), null, 'easyfonts' );

        add_settings_field( 'host_link', __( 'Process Google fonts Stylesheet', 'easyfonts' ), [ $this, 'checkbox_field' ], 'easyfonts', 'easyfonts_main', [ 'label_for' => 'host_link', 'desc' => __( 'Download Google Fonts from <code>&lt;link&gt;</code> and host them locally.', 'easyfonts' ) ] );
        add_settings_field( 'host_import', __( 'Process @import inline style', 'easyfonts' ), [ $this, 'checkbox_field' ], 'easyfonts', 'easyfonts_main', [ 'label_for' => 'host_import', 'desc' => __( 'Process <code>@import</code> rules from inline <code>&lt;style&gt;</code> tags.', 'easyfonts' ) ] );
        add_settings_field( 'process_fontface', __( 'Process @font-face statement', 'easyfonts' ), [ $this, 'checkbox_field' ], 'easyfonts', 'easyfonts_main', [ 'label_for' => 'process_fontface', 'desc' => __( 'Process <code>@font-face</code> from inline <code>&lt;style&gt;</code> tags.', 'easyfonts' ) ] );
        add_settings_field( 'remove_hints', __( 'Remove Resource Hints', 'easyfonts' ), [ $this, 'checkbox_field' ], 'easyfonts', 'easyfonts_main', [ 'label_for' => 'remove_hints', 'desc' => __( 'Remove resource hints like <code>preconnect</code>, <code>prefetch</code>.', 'easyfonts' ) ] );
        add_settings_field( 'remove_scripts', __( 'Remove webfont.js fonts', 'easyfonts' ), [ $this, 'checkbox_field' ], 'easyfonts', 'easyfonts_main', [ 'label_for' => 'remove_scripts', 'desc' => __( 'Remove Google Fonts loading from <code>webfont.js</code> inline scripts.', 'easyfonts' ) ] );
        add_settings_field( 'combine_fonts', __( 'Combine Font Stylesheets', 'easyfonts' ), [ $this, 'checkbox_field' ], 'easyfonts', 'easyfonts_main', [ 'label_for' => 'combine_fonts', 'desc' => __( 'Merge all locally hosted font CSS files into a single file and remove duplicates.', 'easyfonts' ) ] );
        add_settings_field( 'font_display', __( 'Font Display', 'easyfonts' ), [ $this, 'select_field' ], 'easyfonts', 'easyfonts_main', [ 'label_for' => 'font_display', 'desc' => __( 'Set <code>font-display</code> on all <code>@font-face</code> declarations for better loading control.', 'easyfonts' ) ] );
    }

    public function checkbox_field( $args ) {
        $options = get_option( 'easyfonts_options', [] );
        $id = $args['label_for'];
        $checked = ! empty( $options[ $id ] ) ? 'checked' : '';
        echo '<div class="checkbox-wrapper-2"><label for="easyfonts_options[' . esc_attr( $id ) . ']"><input class="sc-gJwTLC ikxBAC" type="checkbox" id="easyfonts_options[' . esc_attr( $id ) . ']" name="easyfonts_options[' . esc_attr( $id ) . ']" value="1" ' . $checked . '><span class="slider"></span></label></div>';
        if ( ! empty( $args['desc'] ) ) {
            echo '<p class="description">' . wp_kses_post( $args['desc'] ) . '</p>';
        }
    }

    public function select_field( $args ) {
        $options = get_option( 'easyfonts_options', [] );
        $id = $args['label_for'];
        $current = ! empty( $options[ $id ] ) ? $options[ $id ] : 'none';
        $choices = [
            'none'     => __( 'Disabled', 'easyfonts' ),
            'auto'     => 'auto',
            'block'    => 'block',
            'swap'     => 'swap',
            'fallback' => 'fallback',
            'optional' => 'optional',
        ];
        echo '<select name="easyfonts_options[' . esc_attr( $id ) . ']" id="easyfonts_options[' . esc_attr( $id ) . ']" class="easyfonts-select">';
        foreach ( $choices as $val => $label ) {
            $selected = selected( $current, $val, false );
            echo '<option value="' . esc_attr( $val ) . '" ' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        if ( ! empty( $args['desc'] ) ) {
            echo '<p class="description">' . wp_kses_post( $args['desc'] ) . '</p>';
        }
    }

    public function options_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->handle_actions();

        $options = get_option( 'easyfonts_options', [] );
        ?><div class="easymain">
        <div class="easyfontwrap">
            <div class="heading"><h1><?php esc_html_e( 'Easy Fonts Options', 'easyfonts' ); ?></h1></div>
            <p class="confirm"><?php printf( __( 'This plugin is free, fast, and extremely lightweight (only 30KB). If you find it useful, <a href="%s" target="_blank">Support Us with 5⭐ Rating</a>', 'easyfonts' ), 'https://wordpress.org/support/plugin/easyfonts/reviews/#new-post' ); ?></p>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'easyfonts' );
                do_settings_sections( 'easyfonts' );
                submit_button( __( 'Save Changes', 'easyfonts' ), 'primary', 'submit', true, array( 'id' => 'easyfonts_submit' ) );
                ?>
            </form>
            <form method="post">
                <?php wp_nonce_field( 'easyfonts_actions', 'easyfonts_nonce' ); ?>
                <button type="submit" name="easyfonts_clear_cache" class="button remove"><?php esc_html_e( 'Remove All stored Fonts', 'easyfonts' ); ?></button>
                <?php if ( ! empty( $options['host_link'] ) || ! empty( $options['host_import'] ) || ! empty( $options['process_fontface'] ) ) : ?>
                    <button type="submit" name="easyfonts_preload" class="button preload"><?php esc_html_e( 'Preload Fonts', 'easyfonts' ); ?></button>
                <?php endif; ?>
            </form>
            <?php
            if ( ! empty( $options['host_link'] ) || ! empty( $options['host_import'] ) || ! empty( $options['combine_fonts'] ) ) {
                $this->list_styles();
            }
            ?>
        </div><div class="easy">
<div id="speed-results">
    <h1>Is Your Hosting Slowing You Down?</h1><p>
	Your hosting plays a key role in website speed and user experience. Analyze your server’s performance now, and explore better solutions if needed.
	</p>
</div>
			<button id="check-speed-btn" class="button button-primary">Run Speed Test</button></div></div>
        <?php
    }

    private function handle_actions() {
        if ( empty( $_POST['easyfonts_nonce'] ) || ! wp_verify_nonce( $_POST['easyfonts_nonce'], 'easyfonts_actions' ) ) {
            return;
        }

        if ( isset( $_POST['easyfonts_clear_cache'] ) ) {
            $this->clear_font_cache();
        }

        if ( isset( $_POST['easyfonts_preload'] ) ) {
            $this->preload_fonts();
        }
    }

    private function clear_font_cache() {
        $dir = EASYFONTS_UPLOAD_DIR;
        if ( is_dir( $dir ) ) {
            $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
            foreach ( $files as $fileinfo ) {
                $todo = ( $fileinfo->isDir() ? 'rmdir' : 'unlink' );
                $todo( $fileinfo->getRealPath() );
            }
            rmdir( $dir );
        }
        add_settings_error( 'easyfonts_messages', 'easyfonts_message', __( 'The fonts have been removed.', 'easyfonts' ), 'success' );
    }

    private function preload_fonts() {
        $current_user = wp_get_current_user();
        if ( ! user_can( $current_user, 'manage_options' ) ) {
            return;
        }
        $tokens = $current_user->get_session_tokens();
        $options = [
            'cookies' => $tokens,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.3',
            ],
        ];
        $home_url = home_url( '?easyfonts_preload=1' );
        $response = wp_remote_get( $home_url, $options );
        if ( is_wp_error( $response ) ) {
            add_settings_error( 'easyfonts_messages', 'easyfonts_message', __( 'Preload failed: ', 'easyfonts' ) . $response->get_error_message(), 'error' );
        } else {
            add_settings_error( 'easyfonts_messages', 'easyfonts_message', __( 'The fonts have been preloaded.', 'easyfonts' ), 'success' );
        }
    }
	
	

    private function list_styles() {
        $options = get_option( 'easyfonts_options', [] );
        $combine_enabled = ! empty( $options['combine_fonts'] );
        $css_files = [];
        $style_data = [];

        if ( ! is_dir( EASYFONTS_UPLOAD_DIR ) ) {
            echo '<p>' . esc_html__( 'Fonts styles are not found. Preload the font first or visit the homepage.', 'easyfonts' ) . '</p>';
            return;
        }
        $dir = new DirectoryIterator( EASYFONTS_UPLOAD_DIR );
        foreach ( $dir as $file ) {
            if ( ! $file->isFile() || $file->getExtension() !== 'css' ) {
                continue;
            }
            $fname = $file->getFilename();
            // When combine is enabled, only show combined file
            if ( $combine_enabled && strpos( $fname, '_combined.css' ) === false ) {
                continue;
            }
            // When combine is disabled, skip combined files
            if ( ! $combine_enabled && strpos( $fname, '_combined.css' ) !== false ) {
                continue;
            }
            $css_files[] = $fname;
        }
        if ( empty( $css_files ) ) {
            echo '<p>' . esc_html__( 'Fonts styles are not found. Preload the font first or visit the homepage.', 'easyfonts' ) . '</p>';
            return;
        }
        foreach ( $css_files as $file_name ) {
            if ( strpos( $file_name, '..' ) !== false ) {
                continue;
            }
            $file_path = EASYFONTS_UPLOAD_DIR . '/' . $file_name;
            if ( ! is_readable( $file_path ) ) {
                continue;
            }
            $file_content = file_get_contents( $file_path );
            $font_family = [];
            $variant_italic = [];
            $variant_normal = [];
            preg_match_all( '/@font-face\s*{[^}]+}/', $file_content, $matches );
            if ( empty( $matches[0] ) ) {
                continue;
            }
            foreach ( $matches[0] as $font_face ) {
                if ( preg_match( '/font-family:\s*[\'"]?([^;\'"]+)[\'"]?;/', $font_face, $family_match ) ) {
                    $font_family[] = trim( $family_match[1] );
                }
                if ( preg_match( '/font-style:\s*([^;]+);/', $font_face, $style_match ) ) {
                    $style = trim( $style_match[1] );
                    if ( preg_match( '/font-weight:\s*([^;]+);/', $font_face, $weight_match ) ) {
                        $weight = trim( $weight_match[1] );
                        if ( $style === 'italic' ) {
                            $variant_italic[] = $weight;
                        } elseif ( $style === 'normal' ) {
                            $variant_normal[] = $weight;
                        }
                    }
                }
            }
            $font_family = array_unique( $font_family );
            $variant_italic = array_unique( $variant_italic );
            $variant_normal = array_unique( $variant_normal );
            $style_data[] = [
                'file_url' => esc_url( EASYFONTS_UPLOAD_URL . '/' . $file_name ),
                'font_families' => esc_html( implode( ', ', $font_family ) ),
                'variant' => esc_html( 'italic: ' . implode( ', ', $variant_italic ) . ' | normal: ' . implode( ', ', $variant_normal ) ),
            ];
        }
        if ( empty( $style_data ) ) {
            return;
        }
        echo '<table class="styled-table"><thead><tr><th>' . esc_html__( 'Hosted Fonts CSS URL', 'easyfonts' ) . '</th><th>' . esc_html__( 'Font Families', 'easyfonts' ) . '</th><th>' . esc_html__( 'Variants', 'easyfonts' ) . '</th></tr></thead><tbody>';
        foreach ( $style_data as $style ) {
            echo '<tr><td><a href="' . $style['file_url'] . '" target="_blank" rel="noopener">' . $style['file_url'] . '</a></td><td>' . $style['font_families'] . '</td><td>' . $style['variant'] . '</td></tr>';
        }
        echo '</tbody></table>';
    }
	public function enqueue_speed_check_assets( $hook ) {
    if ( 'settings_page_easyfonts' !== $hook ) {
        return;
    }

    wp_enqueue_script( 'jquery' ); // Ensure jQuery.

    // Localize PHP values into JS safely
    wp_localize_script( 'jquery', 'speedCheckData', array(
        'apiKey'     => 'AIzaSyA3CHybGfa0lOaXRwjc42lJDxAZsG3Rwos',
        'pageUrl'    => home_url(),
        'phpVersion' => phpversion(),
        'affiliateLink' => 'https://unified.cloudways.com/signup?id=662383&coupon=EASYWPSTUFF',
    ) );

    $script = <<<JS
    jQuery(document).ready(function($) {
        $('#check-speed-btn').click(function() {
		   var btn = $(this); 
            btn.text('🔍 Checking Site Speed…').prop('disabled', true);
            var apiKey = speedCheckData.apiKey;
            var pageUrl = speedCheckData.pageUrl;
            var phpVersion = speedCheckData.phpVersion;
            var affiliateLink = speedCheckData.affiliateLink;

            var mobileApiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' + encodeURIComponent(pageUrl) + '&strategy=mobile&key=' + apiKey;
            var desktopApiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=' + encodeURIComponent(pageUrl) + '&strategy=desktop&key=' + apiKey;

            $('#speed-results').html('<div class="lds-hourglass"></div><p class="checking-speed">⏳Fetching Performance Data…... Please wait.</p>');

            $.when(
                $.get(mobileApiUrl),
                $.get(desktopApiUrl)
            ).done(function(mobileResponse, desktopResponse) {
                var mobileScore = Math.round(mobileResponse[0].lighthouseResult.categories.performance.score * 100);
                var desktopScore = Math.round(desktopResponse[0].lighthouseResult.categories.performance.score * 100);
                var mobileSpeed = (mobileResponse[0].lighthouseResult.audits['speed-index'].numericValue / 1000).toFixed(2);
                var desktopSpeed = (desktopResponse[0].lighthouseResult.audits['speed-index'].numericValue / 1000).toFixed(2);

                var resultHtml = '<div class="result-item mobile-score"><strong>📱 Mobile Score:</strong> <span class="score">' + mobileScore + '</span></div>';
                resultHtml += '<div class="result-item desktop-score"><strong>💻 Desktop Score:</strong> <span class="score">' + desktopScore + '</span></div>';
                resultHtml += '<div class="result-item mobile-speed"><strong>📱 Mobile Speed:</strong> <span class="speed">' + mobileSpeed + ' sec</span></div>';
                resultHtml += '<div class="result-item desktop-speed"><strong>💻 Desktop Speed:</strong> <span class="speed">' + desktopSpeed + ' sec</span></div>';
                resultHtml += '<div class="result-item php-version"><strong>🐘 PHP Version:</strong> ' + phpVersion + '</div>';

                var shouldRecommend = false;

                if (mobileScore < 50 || desktopScore < 50) {
                    shouldRecommend = true;
                    resultHtml += '<div class="warning"><span style="color:red;">⚠️ Low PageSpeed score detected!</span></div>';
                }

                if (mobileSpeed > 3 || desktopSpeed > 2) {
                    shouldRecommend = true;
                    resultHtml += '<div class="warning"><span style="color:red;">⚠️ Your server is slow!</span></div>';
                }

                if (parseFloat(phpVersion) < 7.4) {
                    shouldRecommend = true;
                    resultHtml += '<div class="warning"><span style="color:red;">⚠️ Your PHP version is outdated!</span></div>';
                }

                if (shouldRecommend) {
                    resultHtml += '<div class="recommendation"> <a class="downeasy" href="' + affiliateLink + '" target="_blank">Switch to Faster Hosting</a></div>';
                } else {
                    resultHtml += '<div class="optimized"><span style="color: #9dffa7;font-size: 20px;">✅ Your server is well-optimized!</span></div>';
                }

                $('#speed-results').html(resultHtml);
				$('#check-speed-btn').hide();
            }).fail(function() {
                $('#speed-results').html('<p style="color:red;">⚠️ Error fetching speed test results.</p>');
            });
        });
    });
JS;

    wp_add_inline_script( 'jquery', $script );
   }
}

new EasyFonts_Options();