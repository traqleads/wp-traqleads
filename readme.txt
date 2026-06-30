=== TraqLeads Tracking ===
Contributors: traqleads
Tags: affiliate, tracking, referral, analytics
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

First-party proxy for TraqLeads affiliate tracking. Bypasses ad blockers by serving the tracking script and proxying events through your own domain.

== Description ==

TraqLeads Tracking is a lightweight WordPress plugin that routes all affiliate tracking through your own domain. Ad blockers cannot interfere because the browser only sees same-origin requests.

**How it works:**

1. The plugin serves the TraqLeads tracking script (`tl.js`) from your site instead of `traqleads.com`.
2. All tracking events (page views, form submissions, clicks) are sent to your site first.
3. Your server forwards the data to the TraqLeads API — invisible to ad blockers.

**Features:**

* Self-healing delivery — automatically picks a script URL that works on your host (clean URL, query string, or a static file), so tracking works even on managed hosts like Pressable, WP Engine, and Kinsta that block dynamic .js URLs
* Built-in diagnostics — a self-test detects a broken script URL and emails the details so it can be fixed
* Configurable proxy path to avoid any detection patterns
* Automatic script caching (24-hour refresh)
* Passes real visitor IP and User-Agent for accurate geo-location
* Works with all WordPress form plugins (Contact Form 7, WPForms, Gravity Forms, etc.)
* Settings page under Settings > TraqLeads

== Installation ==

1. Upload the `traqleads` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Go to **Settings > TraqLeads** and enter your Program ID.
4. That's it — tracking is now active on all frontend pages.

== Configuration ==

* **Program ID** — Your program UUID from the TraqLeads dashboard.
* **API URL** — The TraqLeads API base URL (default: `https://traqleads.com/api`).
* **Proxy Path** — The URL prefix for proxy endpoints (default: `tq`). Change to any short path. Re-save Permalinks after changing.

== Changelog ==

= 1.4.0 =
* Fixed: tracking script returned 404 on managed-nginx hosts (Pressable, WP Engine, Kinsta) that serve any URL ending in ".js" as a static file before WordPress runs. The script is now served from an extensionless URL, with automatic fallbacks.
* New: self-healing delivery — the plugin generates both a dynamic route and a static file in uploads, loopback-tests each, and automatically uses whichever works on the host.
* New: built-in diagnostics with a "Run Diagnostics" button and a delivery-status panel; if no delivery method works, the plugin emails the full diagnostics.
* New: the served script now carries its own event-endpoint configuration, so tracking keeps working regardless of the script URL scheme or permalink settings (including plain permalinks and subdirectory installs).
* Fixed: a fatal error when saving settings on PHP 7.4 (used a PHP 8.0-only function).
* Fixed: rate limiting now uses a true fixed window instead of an ever-extending one.
* Security: event requests are bound to the configured Program ID; request body size is capped; real client IP is used behind proxies/CDNs; API URL is validated against private/loopback addresses; cached-script integrity now uses a keyed HMAC.
* Docs: corrected the manual opt-out snippet (use the traqleads_auto_inject filter).

= 1.3.2 =
* Added "Flush Rewrite Rules" button to admin settings page — fixes 404 errors on the tracking script URL without needing to visit Settings > Permalinks.

= 1.3.1 =
* Auto-flush rewrite rules on plugin update (fixes 404 on versioned script URL after upgrade).

= 1.3.0 =
* Versioned script URL path for reliable browser cache busting (/v130/tl.js).

= 1.2.0 =
* Added auto-update support via GitHub releases.
* Added Clear Script Cache button to admin settings.
* Fixed LiteSpeed caching tl.js response (added no-store headers).

= 1.0.0 =
* Initial release.
* First-party proxy for tl.js and tracking events.
* Admin settings page.
* Automatic script caching.
