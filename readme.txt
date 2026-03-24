=== TraqLeads Tracking ===
Contributors: traqleads
Tags: affiliate, tracking, referral, analytics
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
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

* Zero JavaScript configuration — the script auto-detects the proxy endpoint
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
