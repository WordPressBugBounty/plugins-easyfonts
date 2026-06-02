<?php
/**
 * Admin page — hosts the React single-page app.
 *
 * @package EasyFonts
 */

namespace EasyFonts\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu and mounts the SPA.
 */
class AdminPage {

	const SLUG = 'easyfonts';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . EASYFONTS_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'conflict_notice' ) );
	}

	/**
	 * Warn when OMGF (free or Pro) is active alongside Easy Fonts. Running both
	 * means two plugins rewrite the same Google Fonts — duplicate work, possible
	 * double <link>/preload, and conflicting caches. We advise deactivating OMGF.
	 * Shown on all admin screens (so it's seen), dismissible per-user.
	 */
	public function conflict_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$omgf = array(
			'host-webfonts-local/host-webfonts-local.php', // OMGF (free)
			'host-google-fonts-pro/host-google-fonts-pro.php', // OMGF Pro
		);

		$active = array();
		foreach ( $omgf as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				$active[] = $plugin;
			}
		}

		if ( empty( $active ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo '<strong>' . esc_html__( 'Easy Fonts:', 'easyfonts' ) . '</strong> ';
		echo esc_html__( 'OMGF is also active. Running two Google-Fonts optimizers at once causes duplicate processing and conflicting font output. Please deactivate OMGF and let Easy Fonts handle your fonts.', 'easyfonts' );
		echo ' ';

		// One-click deactivate link for the first detected OMGF plugin.
		$deactivate = wp_nonce_url(
			self_admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $active[0] ) ),
			'deactivate-plugin_' . $active[0]
		);
		echo '<a href="' . esc_url( $deactivate ) . '" class="button button-small" style="margin-left:4px;">'
			. esc_html__( 'Deactivate OMGF', 'easyfonts' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Add the menu entry.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'Easy Fonts', 'easyfonts' ),
			__( 'Easy Fonts', 'easyfonts' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			self::menu_icon(),
			81
		);
	}

	/**
	 * Stylized "E" mark as a data-URI SVG for the admin menu. Uses the default
	 * WordPress menu-icon grey so it sits naturally among the other sidebar
	 * icons (WordPress doesn't recolor data-URI icons per state).
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			. '<path fill="#a7aaad" d="M5 4H19V7.4H9V10.5H17V13.9H9V17H19V20H5Z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Settings link on the plugins list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'easyfonts' ) . '</a>' );

		return $links;
	}

	/**
	 * Mount point.
	 */
	public function render(): void {
		// WordPress relocates admin notices to just after the first heading
		// inside .wrap (via its notice/updates JS). To keep stray notices
		// (SEO plugins, etc.) OUT of our app container, we provide a decoy
		// screen-reader heading + an explicit .wp-header-end anchor at the very
		// top of .wrap. WordPress targets those, so notices land here — above
		// and outside the Easy Fonts UI — instead of breaking our <h1>.
		echo '<div class="wrap">'
			. '<h1 class="screen-reader-text">' . esc_html__( 'Easy Fonts', 'easyfonts' ) . '</h1>'
			. '<hr class="wp-header-end" style="margin:0;border:0;height:0;">'
			. '<div id="easyfonts-admin-root">'
			. '<noscript>' . esc_html__( 'Easy Fonts requires JavaScript.', 'easyfonts' ) . '</noscript>'
			. '</div></div>';
	}

	/**
	 * Enqueue the compiled admin bundle on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$dist = EASYFONTS_DIR . 'assets/admin/dist/';
		$url  = EASYFONTS_URL . 'assets/admin/dist/';

		$deps    = array();
		$version = EASYFONTS_VERSION;

		// @wordpress/scripts convention: index.asset.php returns deps + version.
		$asset_file = $dist . 'index.asset.php';

		if ( is_readable( $asset_file ) ) {
			$asset   = include $asset_file;
			$deps    = $asset['dependencies'] ?? array();
			$version = $asset['version'] ?? $version;
		}

		if ( is_readable( $dist . 'index.js' ) ) {
			wp_enqueue_script( 'easyfonts-admin', $url . 'index.js', $deps, $version, true );

			wp_localize_script(
				'easyfonts-admin',
				'EasyFontsAdmin',
				array(
					'root'      => esc_url_raw( rest_url( 'easyfonts/v1' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'version'   => EASYFONTS_VERSION,
					'siteUrl'   => home_url(),
					'optimizer' => self::optimizer_cta(),
				)
			);
		}

		if ( is_readable( $dist . 'index.css' ) ) {
			wp_enqueue_style( 'easyfonts-admin', $url . 'index.css', array(), $version );
		}

		if ( is_readable( $dist . 'style-index.css' ) ) {
			wp_enqueue_style( 'easyfonts-admin-style', $url . 'style-index.css', array(), $version );
		}

		// Bundled admin fonts (Public Sans + JetBrains Mono, latin woff2), served
		// locally from the plugin. Injected here so the URL resolves to the real
		// plugin location; attached to whichever admin stylesheet handle exists.
		$handle = wp_style_is( 'easyfonts-admin-style', 'enqueued' ) ? 'easyfonts-admin-style'
			: ( wp_style_is( 'easyfonts-admin', 'enqueued' ) ? 'easyfonts-admin' : '' );

		if ( '' !== $handle ) {
			wp_add_inline_style( $handle, self::font_face_css() );
		}
	}

	/**
	 * Smart CTA for the sibling Easy Optimizer plugin. Three states:
	 *  - not installed → link to the plugin-install search (user installs it).
	 *  - installed but inactive → one-click (nonce'd) activation link.
	 *  - active → link straight to its settings screen.
	 *
	 * We deliberately do NOT auto-install/activate via AJAX: installing another
	 * plugin requires the user's explicit action on the standard WordPress
	 * screen (caps + nonces + .org guidelines). This just routes them correctly.
	 *
	 * @return array{label:string,url:string,state:string}
	 */
	/**
	 * CTA for the sibling Easy Optimizer plugin. Two states:
	 *  - active → link to its settings screen.
	 *  - not active (not installed OR installed-inactive) → link to the
	 *    plugin-install search, where the user installs and/or activates it
	 *    themselves on the standard WordPress screen.
	 *
	 * URLs are NOT passed through esc_url_raw() here: that converts & into
	 * &amp; entities, which then arrive literally in the href and break the
	 * page ("link has expired"). React escapes the href safely on output.
	 *
	 * @return array{label:string,url:string,state:string}
	 */
	private static function optimizer_cta(): array {
		$slug = 'easy-optimizer/easy-optimizer.php';

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( $slug ) ) {
			return array(
				'label' => __( 'Open Easy Optimizer', 'easyfonts' ),
				'url'   => admin_url( 'admin.php?page=easy-optimizer' ),
				'state' => 'active',
			);
		}

		// Not active (whether installed or not) → send them to the install /
		// activate search screen and let them do it there.
		return array(
			'label' => __( 'Activate Easy Optimizer', 'easyfonts' ),
			'url'   => self_admin_url( 'plugin-install.php?s=speed%2520optimizer%2520by%2520fluxpress&tab=search&type=term' ),
			'state' => 'install',
		);
	}

	/**
	 * @font-face declarations for the bundled admin fonts.
	 *
	 * @return string
	 */
	private static function font_face_css(): string {
		$base = esc_url_raw( EASYFONTS_URL . 'assets/admin/fonts/' );
		$out  = '';

		$faces = array(
			array( 'Public Sans', 400, 'public-sans-latin-400-normal.woff2' ),
			array( 'Public Sans', 500, 'public-sans-latin-500-normal.woff2' ),
			array( 'Public Sans', 600, 'public-sans-latin-600-normal.woff2' ),
			array( 'Public Sans', 700, 'public-sans-latin-700-normal.woff2' ),
			array( 'Public Sans', 800, 'public-sans-latin-800-normal.woff2' ),
			array( 'JetBrains Mono', 400, 'jetbrains-mono-latin-400-normal.woff2' ),
			array( 'JetBrains Mono', 500, 'jetbrains-mono-latin-500-normal.woff2' ),
			array( 'JetBrains Mono', 600, 'jetbrains-mono-latin-600-normal.woff2' ),
			array( 'JetBrains Mono', 700, 'jetbrains-mono-latin-700-normal.woff2' ),
		);

		foreach ( $faces as $face ) {
			$out .= sprintf(
				"@font-face{font-family:'%s';font-style:normal;font-weight:%d;font-display:swap;src:url('%s%s') format('woff2');}",
				$face[0],
				$face[1],
				$base,
				$face[2]
			);
		}

		return $out;
	}
}
