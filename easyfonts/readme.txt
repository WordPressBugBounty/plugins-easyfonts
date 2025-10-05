=== EasyFonts: Local Google Fonts – Fast & Super lightweight (30kb) ===
Contributors: easywpstuff
Donate link: 
Tags: Tags: local google fonts, google fonts, gdpr, fonts, performance, dsgvo, font optimization, self host fonts, privacy, speed optimization, elementor fonts, divi fonts
Requires at least: 5.0
Tested up to: 6.8.3
Requires PHP: 5.6
Stable tag: 1.2
License: GNU General Public License v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Host Google Fonts locally on your server for faster loading 🚀, and full 💯 GDPR/DSGVO compliance. Super lightweight plugin⚡, No server & cpu overload.

== Description ==

Struggling with slow-loading Google Fonts or GDPR compliance issues? EasyFonts is your lightweight solution to **host Google Fonts locally** on your WordPress site. Automatically cache and serve fonts from your server, eliminating external calls to Google that can slow down your site and violate privacy laws like GDPR/DSGVO.

In 2022, a German court ruled that loading Google Fonts from Google's servers violates GDPR by logging user IPs for analytics (more on [WPTavern](https://wptavern.com/german-court-fines-website-owner-for-violating-the-gdpr-by-using-google-hosted-fonts)). With EasyFonts, you avoid this entirely – fonts load from your domain, boosting speed and ensuring compliance.

EasyFonts scans your site, downloads used Google Fonts (and Bunny Fonts), and replaces external links. No coding needed! Improve page speed, reduce DNS requests, and enhance user experience.

= Why Choose EasyFonts for Local Google Fonts? =
- **GDPR/DSGVO Compliant:** No data sent to Google – keep user privacy intact.
- **Faster Site Speed:** Local hosting reduces latency and external dependencies.
- **Easy Setup:** Activate, configure in settings, and let it handle the rest.
- **Lightweight & Efficient:** Minimal impact on your site's resources.
- **Broad Compatibility:** Works with most themes, page builders, and plugins.

= How It Works =
1. EasyFonts detects Google Fonts loaded via &lt;link&gt; and @import, or @font-face inside inline &lt;style&gt;.
2. It downloads and caches them locally in WOFF2 format (lightweight and supported by 96%+ of browsers).
3. Replaces external URLs with your site's domain.
4. Optionally removes resource hints and WebFontLoader for cleaner, faster loads.

Test your site before/after: Use browser dev tools (Network tab) to check for "fonts.googleapis.com" or "fonts.gstatic.com" requests – they should disappear!

Compatible with Elementor, WPBakery, Divi, WooCommerce, Smart Slider 3, Groovy Menu, and more. If you're using Bunny Fonts, they're supported too!

= Benefits of Hosting Google Fonts Locally =
- Reduce page load times by up to 30% with local caching.
- Avoid Cumulative Layout Shift from slow font loading.
- Legal peace of mind for EU users.
- Better SEO: Faster sites rank higher on Google.

Ready to optimize? Install now and see the difference!

== Features ==
* Automatically detect, cache, and **host Google Fonts locally** using <link> tags.
* Support for Bunny Fonts local hosting.
* Handle @import statements in inline <style> tags for local loading.
* Process @font-face declarations in inline styles and replace with local URLs.
* Remove unnecessary resource hints (preload, preconnect, dns-prefetch) to Google's domains.
* Eliminate WebFontLoader (webfont.js) for cleaner code.
* WOFF2 format only for optimal compression and browser support.
* Compatibility with popular page builders: **Host Elementor Google Fonts locally**, WPBakery, Divi, and any theme.
* Works with WooCommerce, Smart Slider 3, Groovy Menu, and more.
* Lightweight – no bloat, just performance gains.

== Plugin Compatibility ==
EasyFonts integrates seamlessly with:
- Elementor: Host Elementor Google Fonts on your local server.
- WPBakery Page Builder: Full support for local font loading.
- Divi Theme: Easily host Google Fonts locally for Divi.
- WooCommerce: Fixes checkout issues with external fonts.
- Smart Slider 3 and Groovy Menu: Added in recent updates.
- Most other themes and plugins – if issues arise, contact support!

== Frequently Asked Questions ==

= Is it legal to host Google Fonts locally on my server? =
Yes! Google Fonts are open-source under licenses allowing commercial or personal use on any site.

= Why does EasyFonts use only WOFF2 format for local fonts? =
WOFF2 is the most efficient, lightweight format with 30% better compression than WOFF. It's supported by over 96% of modern browsers, ensuring fast loads without compatibility issues.

= How does hosting Google Fonts locally make my site GDPR/DSGVO compliant? =
External Google Fonts send user data (like IPs) to Google's servers. Local hosting keeps everything on your server, preventing data transfers and ensuring compliance.

= How can I check if my site is using Google Fonts externally? =
Open browser dev tools (F12), go to the Network tab, filter by "Fonts", and reload. Look for requests to "fonts.googleapis.com" or "fonts.gstatic.com". With EasyFonts, these should be gone!

= Does this work with Elementor or Divi? =
Absolutely! It hosts fonts locally for Elementor, Divi, WPBakery, and more. No extra setup needed.

= What if my fonts include special characters or subsets? =
EasyFonts handles standard subsets. If issues occur, check your theme/plugin settings or contact support.

= Will this improve my site's speed? =
Yes – local fonts reduce external requests, DNS lookups, and latency. Test with tools like Google PageSpeed Insights.

= Can I use this with Bunny Fonts? =
Yes, full support added in v1.1.4.

= What if I have multiple Google Fonts on different pages? =
EasyFonts caches them all automatically for site-wide optimization.

= Is there a pro version or add-ons? =
Currently free and feature-packed. Future updates may add more!

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate via the 'Plugins' menu in WordPress.
3. Go to Settings > EasyFonts to configure and download your site's Google Fonts locally.
4. Browse your site to trigger detection – that's it!

For best results, clear your cache after activation.


== Screenshots ==
1. [Settings Page: Configure local font hosting options.](assets/screenshot-1.png)

== Changelog ==
= 1.2 - October 5, 2025 =
* Optimized speed and plugin structure for better performance.

= 1.1.4 - [Date] =
* Added Bunny Fonts support.

= 1.1.3 - [Date] =
* Fixed security issues.

= 1.1.2 - [Date] =
* Resolved WooCommerce checkout font issues.

= 1.1.1 - [Date] =
* General improvements.

= 1.1.0 - [Date] =
* Minor fixes; added Smart Slider 3 and Groovy Menu support.

= 1.0.4 - [Date] =
* Fixed special characters in Google Fonts URLs.

= 1.0.3 - [Date] =
* Handled fonts starting with //.

= 1.0.2 - [Date] =
* Fixed HTTP protocol issues.

= 1.0.0 - [Date] =
* Initial release.