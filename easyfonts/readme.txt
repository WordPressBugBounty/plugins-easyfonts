=== EasyFonts – Host Google Fonts Locally, Fast & Auto-Optimize, GDPR Compliant ===
Contributors: easywpstuff
Tags: google fonts, host google fonts locally, gdpr, core web vitals, font optimization
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Host Google Fonts locally, Auto combine and remove duplicate fonts, Auto preload. pass Core Web Vitals — GDPR compliant, auto-config.

== Description ==

**EasyFonts** is the smart way to **host Google Fonts locally** in WordPress. Activate it and your Google Fonts are detected, downloaded, and served from your own domain — no requests to Google, faster loading, and full **GDPR compliance**.

Unlike basic font plugins, EasyFonts doesn't just self-host blindly. It **measures which fonts actually render in real visitors' browsers**, so it can preload your above-the-fold fonts, flag the ones you never use, and generate **zero-CLS metric-matched fallbacks** — the things that actually move your Core Web Vitals (LCP, CLS) scores.

= Why host Google Fonts locally? =

1. **GDPR / DSGVO:** German courts have ruled that sending visitor IPs to Google Fonts without consent violates GDPR. Self-hosting removes the connection entirely — no data leaves your server.
2. **Speed & Core Web Vitals:** Every call to `fonts.googleapis.com` and `fonts.gstatic.com` adds DNS lookups, connections, and render-blocking delay that hurt LCP and TTFB. Local hosting kills that latency.

= What EasyFonts does =

* **Automatic local hosting** — Detects Google Fonts loaded via `<link>` stylesheets, `@import` rules, inline `@font-face`, **theme/plugin CSS files**, **external/CDN stylesheets**, and the **Web Font Loader (webfont.js)** — then downloads and serves them locally.
* **Combine into one stylesheet** — Merges all hosted font CSS into a single file, removes duplicate `@font-face` rules, and can **inline it as minified CSS** so it's not render-blocking.
* **Used vs Unused font discovery** — Automatically identifies fonts that never render on real pages so you can disable unnecessary downloads and preloads.
* **Real usage measurement** — A lightweight beacon measures which font families and weights actually render, and which appear above the fold — directly on your live pages, no loopback crawler.
* **Smart preloading** — Auto-preloads the above-the-fold fonts that matter (capped, so you never over-preload), boosting LCP.
* **font-display control** — Force `font-display: swap` (or block, fallback, optional, auto) on every face to fix "Ensure text remains visible during webfont load."
* **Per-family & per-weight control** — Toggle Load and Preload for any family or individual weight; disable fonts you don't use.
* **Subset trimming** — Keep only the character sets you need (Latin, Cyrillic, Greek, Vietnamese, Arabic, Devanagari, CJK and more) to cut font weight.
* **Zero-CLS fallbacks** — Reads each font's real metrics and generates size-matched fallback faces (`size-adjust`, `ascent-override`) to eliminate layout shift while fonts load.
* **Async / JS-injected font blocking** — Optionally catches Google Fonts injected by JavaScript at runtime and self-hosts them too (handles fonts most plugins miss).
* **Clean resource hints** — Strips now-useless `preconnect`, `dns-prefetch`, and `preload` tags pointing at Google's servers.
* **Variable font support** — Detects and hosts modern variable fonts as a single optimized file instead of many static weights.
* **CDN support** — Serve hosted fonts and the stylesheet from your CDN.
* **Bunny Fonts support** — Also self-hosts fonts from `fonts.bunny.net`.
* **Import / Export settings** — Move your configuration between sites in one click.
* **Multisite ready** — Works per-site across a network, with tables created automatically for new sites.
* **WP-CLI** — Scan and manage fonts from the command line.
* **Page builder & theme compatible** — Elementor, Divi, Bricks, Beaver Builder, WPBakery, Oxygen, plus Astra, GeneratePress, Kadence, Blocksy, and WooCommerce. Editors are automatically excluded so your styling stays intact while you build.

= Why choose EasyFonts over OMGF and other font plugins? =

* **It measures, not guesses.** EasyFonts sees what actually renders in real browsers, so preload and fallbacks are based on data — not a static guess.
* **Detection most plugins charge for.** Inline CSS, theme/plugin CSS, external stylesheets, and Web Font Loader detection are **free** here — features that are paid add-ons elsewhere.
* **Zero-CLS fallbacks for any font** — generated from real font metrics, not limited to a fixed list.
* **Async JS-injected fonts** — caught and self-hosted, a common gap in other plugins.
* **Truly zero-config.** Activate it and it works; everything else is optional fine-tuning.
* **No data sent anywhere.** All processing is on your server.

= How it works =

1. Install and activate EasyFonts.
2. Open **Easy Fonts** in your admin menu.
3. Click **Optimize now** (or just let visitors browse) — fonts are detected, self-hosted, and measured automatically.
4. Review **Used vs Unused** fonts, fine-tune Load/Preload, and adjust settings if you like.
5. Done — your Google Fonts now load locally, faster, and GDPR-compliant.

== Installation ==

1. Upload the `easyfonts` folder to `/wp-content/plugins/`, or install via **Plugins → Add New**.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Open **Easy Fonts** from the admin menu.
4. Click **Optimize now** or visit your site's front end — fonts are downloaded and cached automatically.
5. (Optional) Adjust subsets, font-display, CDN, preloading, and other settings.

== Frequently Asked Questions ==

= Does this make my site GDPR compliant for Google Fonts? =

Yes. Once active, all Google Fonts are served from your own server — no requests to fonts.googleapis.com or fonts.gstatic.com, so no visitor IPs are sent to Google.

= Will it improve my PageSpeed / Core Web Vitals score? =

Yes. Removing external font connections, combining (or inlining) the stylesheet, preloading above-the-fold fonts, and adding zero-CLS fallbacks typically improve LCP and CLS in PageSpeed Insights, GTmetrix, and Lighthouse.

= How is EasyFonts different from just self-hosting? =

EasyFonts measures which fonts actually render in real browsers, then preloads the important ones, flags unused ones, and matches fallback metrics to prevent layout shift — going well beyond a static download-and-serve.

= Does it work with my theme and page builder? =

Yes. It processes the final HTML output, so it catches fonts from any theme, plugin, or builder — Elementor, Divi, Bricks, Beaver Builder, WPBakery, Oxygen, Astra, GeneratePress, Kadence, and more. Builder editors are automatically excluded so fonts display correctly while editing.

= Does it support variable fonts? =

Yes. EasyFonts detects variable fonts and hosts them as a single optimized file instead of many separate weight files.

= Does it support Bunny Fonts? =

Yes. Fonts from fonts.bunny.net are detected and self-hosted alongside Google Fonts.

= Can I use a CDN? =

Yes. Point the CDN setting at your pull-zone and the hosted fonts and stylesheet are served through your CDN.

= Will it work with caching plugins? =

Yes — WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, and others. After optimizing, clear your page cache so visitors get the updated HTML.

= How do I clear the font cache? =

Open **Easy Fonts → Dashboard** and click **Empty cache**. Fonts are re-detected automatically on the next visit or via **Optimize now**.

= Does it handle Google Fonts from multiple plugins at once? =

Yes. It finds font stylesheets across your whole page — themes, plugins, and builders — merges them into one file, and removes duplicates.

== Screenshots ==

1. Dashboard — hosted font stats, maintenance, and live detector status
2. Fonts — used vs unused families with per-weight Load and Preload control
3. Settings — subsets, font-display, CDN, inline CSS, and more
4. Usage — what actually renders, measured in real browsers

== Changelog ==


= 2.0.2 =
* New: per-page font scoping. Each page now loads only the fonts it actually uses. Many themes (e.g. Blocksy) enqueue their whole Google-fonts set on every page; Easy Fonts now measures what each page really renders (in a real browser) and leaves the unused families off that page's stylesheet — while still hosting them on the pages that do use them. Matching is done at the family level and is self-correcting: a font is scoped out only after the page has been measured, it returns automatically if the page starts using it, and a font is never dropped from a page that hasn't been measured yet.
* Fixed: a font declared on a page is no longer wrongly dropped from the combined CSS because of a weight/style mismatch (e.g. a single-weight family used at a bold weight). Usage is now matched per family.
* Improved: the combined stylesheet is unique per page font-set and shared when identical. Pages that use the same fonts reuse the same cached file; a page with a different set gets its own. Identical sets are never regenerated, saving CPU and disk.
* Improved: used/unused classification stays current. Beacon measurements now refresh hourly instead of being locked for a week, so a font that starts (or stops) being used is reflected quickly, and "Optimize" forces an immediate fresh measurement of the homepage.
* Fixed: preloads are accurate and page-specific. A font is preloaded on a page only when it actually renders above the fold there (matched per family, so a face the browser shows at a different weight than the hosted file — e.g. a single-weight family shown bold — still preloads correctly). A font above the fold on just one page is preloaded only on that page; a font above the fold on every page is preloaded everywhere from a cached set, with no per-page lookup. Your manual "always preload" choices are respected wherever the family is used.
* Fixed: page counts and usage are keyed by the page path, so cache-buster and probe parameters (and tracking/builder query strings) can no longer inflate the dashboard with duplicate rows.
* Improved: URL exclusions now match precisely — "/cart" no longer also excludes "/cart-guide". Patterns match the exact path or a path-segment prefix, and support wildcards (e.g. "/shop/*").
* Improved: when the master toggle is off, Easy Fonts adds nothing at all to the front end (no measurement or helper scripts).
* Improved: front-end scripts are now served minified.
* Performance: fewer database writes per page render (font registration is batched), usage logging is batched into a single write and rate-limited per page, and a dead database index was retired.
* Security/hardening: strict sanitisation of all values written into the generated @font-face CSS; stylesheet fetching is restricted to public hosts (internal/private addresses are blocked) with parsed-host provider matching; and local stylesheet reads are limited to genuine .css files with path-traversal blocked.
* Developer: filters `easyfonts_page_scope_fonts` (set false to disable per-page scoping), `easyfonts_force_families` (always-load safelist), `easyfonts_preload_cap`, and `easyfonts_beacon_throttle_hours`.

= 2.0.1 =
* Fixed: rare white screen / blank front-end on some hosts (esp. FastCGI/cgi-fcgi). Font binaries are no longer downloaded during a visitor's page render — they are fetched by a non-blocking background warm request, so a slow or blocked outbound connection can never stall the page.
* Fixed: the output buffer now fails open — if anything goes wrong while optimising a page, the original HTML is always returned instead of a blank page.
* Improved: provider <link> tags are only removed once their fonts are confirmed hosted locally, so the page never loses its fonts while the cache warms.
* Improved: provider stylesheet CSS is cached, so font detection no longer hits the network on every page load.
* Hardened: guard for WordPress's HTML API, safer regex handling, and PHP 7.4 compatibility shims.
* Note: no settings, styling, or features changed. After activation (or after changing fonts) the first visit serves the original fonts for a moment while the cache warms in the background, then switches to self-hosted automatically. You can also warm immediately from Settings → Optimize.
* Fixed: the real cause of the blank front-end — two regular expressions that scanned the whole page (the inline <style> processor and the Web Font Loader remover) could exhaust the PCRE2 engine on large pages (e.g. page-builder inline CSS or big inline scripts) and either return nothing or crash the PHP worker, blanking the page with no catchable error. Both are now linear text scans that cannot backtrack or overflow.
* Hardened: every remaining regular expression that rewrites page/CSS content now falls back to the original content if the engine fails, so a pattern can never blank the page. Added a final safety net that discards the processed page if it ever comes out empty.
* Added: a size cap for individual inline <style> blocks (filterable) to skip pathologically large blocks safely.

= 2.0.0 =
* New: real-browser usage measurement — see which fonts render and which are above the fold.
* New: smart auto-preload of above-the-fold fonts (capped) for better LCP; your manual choices are always respected.
* New: zero-CLS metric-matched fallback fonts generated from real font metrics.
* New: detect and self-host fonts from inline CSS, theme/plugin CSS, external/CDN stylesheets, and the Web Font Loader (webfont.js).
* New: optional async / JS-injected Google Fonts blocker.
* New: full subset selection (Latin, Cyrillic, Greek, Vietnamese, Arabic, Devanagari, CJK, and more).
* New: CDN support for hosted fonts and the stylesheet.
* New: inline the combined stylesheet as minified CSS (not render-blocking).
* New: per-family and per-weight Load / Preload control, with used vs unused grouping.
* New: settings Import / Export.
* New: Multisite support and WP-CLI commands.
* New: redesigned, faster admin interface.
* Improved: variable font detection and single-file hosting.
* Improved: font-display control across all @font-face declarations.
* Improved: resource-hint cleanup and duplicate @font-face removal.

== Upgrade Notice ==

= 2.0.0 =
Major update: real-browser usage measurement, smart preloading, zero-CLS fallbacks, broader font detection (inline/theme/external/webfont.js), subsets, CDN, and a redesigned admin. Recommended for everyone.
