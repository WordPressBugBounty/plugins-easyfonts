=== EasyFonts – Host Google Fonts Locally, GDPR Compliant, Faster Loading ===
Contributors: easywpstuff
Tags: google fonts, local google fonts, disable google fonts, gdpr, core web vitals
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Host Google Fonts locally to speed up your site, improve Core Web Vitals, and ensure GDPR compliance. A lightweight (35KB), zero-config plugin.

== Description ==

**EasyFonts** is the lightest, fastest WordPress plugin to **host Google Fonts locally** on your own server. Just activate the plugin, and your Google Fonts are automatically downloaded, cached, and served directly from your domain. No manual uploads, no complex settings, and no external requests.

At only **35KB**, EasyFonts is built for absolute performance. Unlike bloated alternatives that take up 2MB of space and require endless configuration, EasyFonts does one job perfectly: it **disables external Google Fonts** and self-hosts them so your site loads faster, passes Core Web Vitals, and stays strictly compliant with European privacy laws.

= Why Host Google Fonts Locally? =

When your WordPress site loads fonts from Google's servers, you face two massive issues:

1. **GDPR (DSGVO) Violations:** A German court ruled that sending visitor IP addresses to Google without consent violates GDPR. Sites are actively being fined for this.
2. **Slow Core Web Vitals:** Every external connection to `fonts.googleapis.com` and `fonts.gstatic.com` adds latency, destroying your Time to First Byte (TTFB) and Largest Contentful Paint (LCP) scores.

EasyFonts eliminates both problems instantly. It rewrites your CSS to serve fonts locally—stopping Google from tracking your users and drastically speeding up your site. 

= Key Features & Performance Benefits =

* **Modern Variable Font Support (NEW)** — Unlike older plugins that download 10+ separate font files for different weights, EasyFonts uses modern browser detection to download a **single, highly-optimized Variable Font file**. This saves massive amounts of bandwidth.
* **Combine Font Stylesheets** — Merges all locally hosted font CSS into a single, optimized file placed right after `<body>`. Exact duplicate font faces are automatically removed to reduce HTTP requests.
* **Fix FOIT with Font Display Control** — Force a `font-display: swap` value on every `@font-face` declaration to eliminate the "Flash of Invisible Text" and pass Google Lighthouse audits.
* **Disable Google Fonts** — Completely severs all connections to Google's servers, ensuring 100% GDPR compliance.
* **Automatic Local Hosting** — Detects Google Fonts loaded via `<link>` stylesheets, `@import` rules, and inline `@font-face` declarations.
* **Clean Resource Hints** — Strips unnecessary `preconnect`, `dns-prefetch`, and `preload` tags pointing to external font servers to clean up your `<head>`.
* **Bunny Fonts Support** — Also processes and locally hosts fonts from `fonts.bunny.net`.
* **Auto Cache Clear** — Whenever you save settings, the font cache is instantly cleared and regenerated fresh to prevent broken layouts.
* **Page Builder Compatible** — Works flawlessly with Elementor, Divi, WPBakery, GeneratePress, Astra, Kadence, and WooCommerce.

= What Makes EasyFonts Beat the Competition? =

* **35KB Size:** Competitors weigh between 500KB and 2MB. EasyFonts is 60x smaller.
* **Zero Database Bloat:** Stores only one single option row. No custom tables, no transient spam.
* **Zero Config Required:** Just enable and save. No scanning, no waiting, no manual font selection.
* **No Premium Upsells:** Every pro-level feature (combining CSS, variable fonts, font-display control, deduplication) is 100% free. 

= How It Works =

1. Install and activate EasyFonts.
2. Go to **Settings → Easy Fonts**.
3. Enable the options you need (most users: enable all checkboxes and set Font Display to `swap`).
4. Click **Save Changes** — the cache clears automatically.
5. Visit your homepage (or click **Preload Fonts**) — fonts are downloaded and cached.
6. Done. All Google Fonts are now served from your domain.

== Installation ==

1. Upload the `easyfonts` folder to `/wp-content/plugins/` or install via **Plugins → Add New** in your WordPress dashboard.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Go to **Settings → Easy Fonts** to configure.
4. Enable the options you want and click **Save Changes**.
5. Click **Preload Fonts** or simply visit your homepage — fonts will be downloaded and cached automatically.

== Frequently Asked Questions ==

= Does EasyFonts work with my theme? =

Yes. EasyFonts works with any WordPress theme that loads Google Fonts — including Astra, GeneratePress, OceanWP, Kadence, Blocksy, Hello Elementor, Divi, TwentyTwentyFive, and any theme or child theme. It processes the final HTML output, so it catches fonts loaded by themes, plugins, and page builders alike.

= Does this plugin make my site GDPR compliant for Google Fonts? =

Yes. Once EasyFonts is active and configured, all Google Fonts are served from your own server. No requests are made to fonts.googleapis.com or fonts.gstatic.com, so no visitor data is sent to Google.

= Will this improve my page speed score? =

Yes. Hosting fonts locally eliminates external DNS lookups and HTTP requests to Google's servers. Combined with the stylesheet merging and resource hint removal features, most sites see a measurable improvement in PageSpeed Insights, GTmetrix, and Lighthouse scores.

= Does it work with Elementor, WPBakery, and Divi? =

Yes. EasyFonts processes the entire HTML output using output buffering, so it catches Google Fonts loaded by any page builder including Elementor, WPBakery, Divi, Beaver Builder, Bricks, and Breakdance.

= What is the font-display option? =

The `font-display` CSS property controls browser behavior while web fonts load. Setting it to `swap` ensures your text is always visible using a fallback font while custom fonts download — this fixes the "Ensure text remains visible during webfont load" warning in Lighthouse.

= Does EasyFonts support Bunny Fonts? =

Yes. EasyFonts automatically processes fonts from fonts.bunny.net in addition to Google Fonts.

= How do I clear the font cache? =

Go to **Settings → Easy Fonts** and click **Remove All Stored Fonts**. The cache also clears automatically whenever you save settings.

= Will it work with caching plugins? =

Yes. EasyFonts works with WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, and all other caching plugins. After configuring EasyFonts, clear your page cache so the updated HTML is served to visitors.

= Can I use this with a CDN? =

Yes. The locally hosted font files will be served through your CDN just like any other file in your uploads directory.

= Does it handle multiple Google Fonts stylesheets from different plugins? =

Yes. The Combine Font Stylesheets feature specifically addresses this — it finds all font stylesheets across your entire page (from themes, plugins, and page builders), merges them into one file, and removes duplicates.

== Screenshots ==

1. EasyFonts settings page showing all configuration options
2. Hosted fonts table showing locally cached font families and variants
3. Server speed test integration with PageSpeed Insights results

== Changelog ==

= 1.3.0 =
* New: Combine Font Stylesheets — merges all locally hosted font CSS into a single file with deduplication
* New: Font Display control — set font-display (swap, block, fallback, optional, auto) on all @font-face declarations
* New: Auto cache clear when settings are saved

= 1.2.0 =
* New: Process @font-face from inline style blocks (gstatic fonts)
* New: Built-in server speed test via Google PageSpeed Insights
* New: Preload fonts button for one-click font caching
* Improved: SSL handling for upload URLs
* Improved: Bunny Fonts support via filter

= 1.1.0 =
* New: Remove Resource Hints (preconnect, dns-prefetch, preload)
* New: Remove WebFont.js inline scripts
* Improved: Better compatibility with Smart Slider and Groovy Menu

= 1.0.0 =
* Initial release
* Host Google Fonts from link tags locally
* Host Google Fonts from @import rules locally
* Admin settings page with toggle controls

== Upgrade Notice ==

= 1.3.0 =
New features: Combine font stylesheets into one file, font-display control, and auto cache clear on settings change. Recommended update for better performance and Core Web Vitals scores.