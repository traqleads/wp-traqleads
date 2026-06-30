<?php
/**
 * Plugin Name: TraqLeads Tracking
 * Plugin URI:  https://traqleads.com
 * Description: First-party proxy for TraqLeads affiliate tracking. Serves the tracking script and proxies events through your own domain to bypass ad blockers. Self-healing delivery works on managed hosts (Pressable, WP Engine, Kinsta) that block dynamic .js URLs.
 * Version:     1.4.0
 * Author:      TraqLeads
 * Author URI:  https://traqleads.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: traqleads
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TRAQLEADS_VERSION', '1.4.0');

// ==========================================================================
// Auto-update from GitHub (traqleads/wp-traqleads)
// ==========================================================================
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/load-v5p6.php';
$traqleadsUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/traqleads/wp-traqleads/',
    __FILE__,
    'traqleads'
);
$traqleadsUpdateChecker->getVcsApi()->enableReleaseAssets();

// ==========================================================================
// Activation / Deactivation
// ==========================================================================

register_activation_hook(__FILE__, function () {
    traqleads_register_rewrite_rules();
    flush_rewrite_rules();
    if (!wp_next_scheduled('traqleads_health_check_cron')) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'daily', 'traqleads_health_check_cron');
    }
    // Defer the first health check to the next admin page load (avoids a slow
    // loopback during activation, which can fail before the site is fully up).
    update_option('traqleads_health_recheck', 1);
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
    $ts = wp_next_scheduled('traqleads_health_check_cron');
    if ($ts) {
        wp_unschedule_event($ts, 'traqleads_health_check_cron');
    }
});

add_action('traqleads_health_check_cron', 'traqleads_run_health_check');

// ==========================================================================
// Rewrite Rules
// ==========================================================================

function traqleads_get_proxy_path(): string
{
    $path = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) get_option('traqleads_proxy_path', 'tq'));
    return $path !== '' ? $path : 'tq';
}

function traqleads_register_rewrite_rules(): void
{
    $path = traqleads_get_proxy_path();

    // Extensionless (primary) — survives managed-nginx static-file handling that
    // 404s any URL ending in .js before WordPress boots (the Pressable failure).
    add_rewrite_rule('^' . $path . '/tl/?$', 'index.php?traqleads_action=script', 'top');
    add_rewrite_rule('^' . $path . '/v[0-9]+/tl/?$', 'index.php?traqleads_action=script', 'top');

    // Legacy .js paths — kept for backward-compat (Apache/Flywheel-style hosts and
    // already-cached pages from older versions).
    add_rewrite_rule('^' . $path . '/tl\.js$', 'index.php?traqleads_action=script', 'top');
    add_rewrite_rule('^' . $path . '/v[0-9]+/tl\.js$', 'index.php?traqleads_action=script', 'top');

    // Event proxy (extensionless — already works on nginx).
    add_rewrite_rule('^' . $path . '/t/?$', 'index.php?traqleads_action=track', 'top');
}

add_action('init', 'traqleads_register_rewrite_rules');

// One-time flush + recheck on version change (handles auto-updates).
add_action('init', function () {
    if (get_option('traqleads_version') !== TRAQLEADS_VERSION) {
        traqleads_register_rewrite_rules();
        flush_rewrite_rules();
        update_option('traqleads_version', TRAQLEADS_VERSION);
        update_option('traqleads_health_recheck', 1);
        // Force a fresh fetch so the served script picks up the current bootstrap.
        delete_transient('traqleads_tl_js');
        delete_transient('traqleads_tl_js_hash');
    }
});

add_filter('query_vars', function (array $vars): array {
    $vars[] = 'traqleads_action';
    return $vars;
});

// ==========================================================================
// Request Handler — intercept rewrite/query-var matches
// ==========================================================================

add_action('template_redirect', function () {
    $action = get_query_var('traqleads_action');
    if (!$action) {
        return;
    }

    switch ($action) {
        case 'script':
            traqleads_serve_script();
            break;
        case 'track':
            traqleads_handle_track();
            break;
    }
});

// ==========================================================================
// URL helpers (permalink- and subdirectory-safe via home_url())
// ==========================================================================

/**
 * Active script URL chosen by the health check. Falls back to a sensible default
 * before the first health check has run.
 */
function traqleads_script_url(): string
{
    $active = get_option('traqleads_active_script_url');
    if ($active) {
        return $active;
    }
    return get_option('permalink_structure')
        ? traqleads_dynamic_script_url('dynamic_pretty')
        : traqleads_dynamic_script_url('dynamic_query');
}

function traqleads_dynamic_script_url(string $variant): string
{
    $ver = str_replace('.', '', TRAQLEADS_VERSION);
    if ($variant === 'dynamic_query') {
        return home_url('/?traqleads_action=script&v=' . $ver);
    }
    return home_url('/' . traqleads_get_proxy_path() . '/v' . $ver . '/tl');
}

/**
 * Track endpoint URL. Always reachable: pretty path when permalinks are on,
 * permalink-independent query-var form otherwise. The track POST is always a
 * dynamic PHP request (extensionless), so it works on managed nginx.
 */
function traqleads_track_url(): string
{
    if (get_option('permalink_structure')) {
        return home_url('/' . traqleads_get_proxy_path() . '/t');
    }
    return home_url('/?traqleads_action=track');
}

// ==========================================================================
// Endpoint bootstrap — prepended to every served/written script so the client
// (tl.js) always knows its POST endpoint without relying on parsing ".js" out
// of its own src (which breaks for extensionless / static URLs).
// ==========================================================================

function traqleads_bootstrap_js(): string
{
    $pid      = (string) get_option('traqleads_program_id', '');
    $endpoint = traqleads_track_url();
    return 'window.tl=window.tl||function(){(window.tl.q=window.tl.q||[]).push(arguments)};'
        . 'window.tl("init",{programId:' . wp_json_encode($pid) . ',endpoint:' . wp_json_encode($endpoint) . '});'
        . "\n";
}

// ==========================================================================
// tl.js fetch + cache (HMAC integrity, dogpile lock, stale-while-revalidate)
// ==========================================================================

/**
 * @return string|false Raw tl.js body from the API, or false on failure.
 */
function traqleads_fetch_tl_js(bool $force = false)
{
    if (!$force) {
        $cached = get_transient('traqleads_tl_js');
        if ($cached) {
            $stored = get_transient('traqleads_tl_js_hash');
            // HMAC keyed by a secret outside the DB — a DB-only writer cannot forge it.
            if ($stored && !hash_equals($stored, hash_hmac('sha256', $cached, wp_salt('auth')))) {
                delete_transient('traqleads_tl_js');
                delete_transient('traqleads_tl_js_hash');
                $cached = false;
            }
            if ($cached) {
                return $cached;
            }
        }
    }

    // Dogpile guard: if another request is already fetching, serve the last-known
    // good copy instead of stampeding the API.
    if (get_transient('traqleads_tl_js_lock')) {
        $stale = get_transient('traqleads_tl_js_stale');
        if ($stale) {
            return $stale;
        }
    }
    set_transient('traqleads_tl_js_lock', 1, 15);

    $api_url  = rtrim((string) get_option('traqleads_api_url', 'https://traqleads.com/api'), '/');
    $response = wp_remote_get($api_url . '/tl.js', [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/javascript'],
    ]);

    delete_transient('traqleads_tl_js_lock');

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = wp_remote_retrieve_body($response);
        if ($body !== '') {
            set_transient('traqleads_tl_js', $body, DAY_IN_SECONDS);
            set_transient('traqleads_tl_js_hash', hash_hmac('sha256', $body, wp_salt('auth')), DAY_IN_SECONDS);
            set_transient('traqleads_tl_js_stale', $body, WEEK_IN_SECONDS);
            return $body;
        }
    }

    // Fetch failed — fall back to the last-known good copy if we have one.
    $stale = get_transient('traqleads_tl_js_stale');
    return $stale ?: false;
}

// ==========================================================================
// Static-file delivery (obfuscated path in uploads — bulletproof on nginx)
// ==========================================================================

/**
 * @return array{dir:string,file:string,url:string}
 */
function traqleads_static_info(): array
{
    $slug = get_option('traqleads_static_slug');
    if (!$slug) {
        $slug = strtolower(wp_generate_password(8, false, false));
        if ($slug === '') {
            $slug = 'tq' . substr(md5(uniqid('tq', true)), 0, 6);
        }
        update_option('traqleads_static_slug', $slug);
    }
    $fname = get_option('traqleads_static_file');
    if (!$fname) {
        $fname = wp_generate_password(10, false, false) . '.js';
        update_option('traqleads_static_file', $fname);
    }

    $up  = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . $slug;
    return [
        'dir'  => $dir,
        'file' => $dir . '/' . $fname,
        'url'  => trailingslashit($up['baseurl']) . $slug . '/' . $fname,
    ];
}

/**
 * Write the static script file (bootstrap + tl.js body) into uploads.
 *
 * @return bool True on success.
 */
function traqleads_write_static_file(): bool
{
    $body = traqleads_fetch_tl_js();
    if (!$body) {
        return false;
    }
    $content = traqleads_bootstrap_js() . $body;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;
    if (!$wp_filesystem) {
        return false;
    }

    $info = traqleads_static_info();
    if (!$wp_filesystem->is_dir($info['dir']) && !wp_mkdir_p($info['dir'])) {
        return false;
    }

    $ok = $wp_filesystem->put_contents($info['file'], $content, FS_CHMOD_FILE);
    if ($ok) {
        update_option('traqleads_static_hash', substr(hash('sha256', $content), 0, 8));
    }
    return (bool) $ok;
}

function traqleads_static_url_busted(): string
{
    $info = traqleads_static_info();
    $hash = get_option('traqleads_static_hash', '');
    return $info['url'] . ($hash ? '?h=' . $hash : '');
}

// ==========================================================================
// Health check — generate both delivery methods, loopback-test each, auto-pick
// the first that works, self-heal, and alert on state change.
// ==========================================================================

function traqleads_run_health_check(): array
{
    $pid = get_option('traqleads_program_id');
    if (!$pid) {
        return ['method' => 'none', 'reason' => 'not_configured', 'results' => []];
    }

    // Refresh the cached script and (re)generate the static file.
    traqleads_fetch_tl_js(true);
    $static_ok = traqleads_write_static_file();

    // Candidate URLs in priority order: cleanest first, bulletproof last.
    $candidates = [];
    if (get_option('permalink_structure')) {
        $candidates[] = ['method' => 'dynamic_pretty', 'url' => traqleads_dynamic_script_url('dynamic_pretty')];
    }
    $candidates[] = ['method' => 'dynamic_query', 'url' => traqleads_dynamic_script_url('dynamic_query')];
    if ($static_ok) {
        $candidates[] = ['method' => 'static', 'url' => traqleads_static_url_busted()];
    }

    $results     = [];
    $picked      = null;
    $got_any_http = false; // distinguish "broken" from "loopback blocked"

    foreach ($candidates as $c) {
        $resp = wp_remote_get($c['url'], [
            'timeout'     => 6,
            'redirection' => 2,
            'sslverify'   => true,
            'headers'     => ['Accept' => 'application/javascript'],
            'user-agent'  => 'TraqLeads-HealthCheck/' . TRAQLEADS_VERSION,
        ]);

        if (is_wp_error($resp)) {
            $results[$c['method']] = ['url' => $c['url'], 'status' => 'error', 'detail' => $resp->get_error_message()];
            continue; // inconclusive — cannot reach our own URL
        }

        $got_any_http = true;
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $is_js = ($code === 200 && $body !== '' && strpos(ltrim($body), '<') !== 0 && strpos($body, 'window.tl') !== false);

        $results[$c['method']] = ['url' => $c['url'], 'status' => $code, 'is_js' => $is_js];
        if ($is_js && !$picked) {
            $picked = $c;
        }
    }

    $prev_method = (string) get_option('traqleads_delivery_method', '');

    if ($picked) {
        $method = $picked['method'];
        update_option('traqleads_delivery_method', $method);
        update_option('traqleads_active_script_url', $picked['url']);
    } elseif ($got_any_http) {
        // We reached our URLs but none served valid JS — genuinely broken.
        $method = 'none';
        update_option('traqleads_delivery_method', 'none');
    } else {
        // Every candidate was a connection error (loopback blocked) — inconclusive.
        // Keep the current method; never break a possibly-working site on this signal.
        $method = $prev_method !== '' ? $prev_method : (get_option('permalink_structure') ? 'dynamic_pretty' : 'dynamic_query');
        if (!get_option('traqleads_active_script_url')) {
            update_option('traqleads_delivery_method', $method);
            update_option('traqleads_active_script_url', traqleads_script_url());
        }
    }

    update_option('traqleads_health_state', ['method' => $method, 'time' => time(), 'results' => $results]);
    update_option('traqleads_health_checked', time());

    // Alert only when delivery lands in (or moves between) trouble states, and only
    // on change — never spam, and never alert the first healthy dynamic pick.
    $trouble = ($method === 'none' || $method === 'static');
    if ($method !== $prev_method && ($prev_method !== '' || $trouble) && ($picked || $got_any_http)) {
        traqleads_send_health_alert($method, $results);
    }

    return ['method' => $method, 'results' => $results];
}

function traqleads_send_health_alert(string $method, array $results): void
{
    $to = apply_filters('traqleads_alert_email', 'contact@raaz.me');
    if (!$to) {
        return;
    }

    $site = home_url();
    $host = wp_parse_url($site, PHP_URL_HOST);
    $up   = wp_upload_dir();

    $subject = sprintf('[TraqLeads] %s — tracking delivery now "%s"', $host, $method);

    $lines   = [];
    $lines[] = $method === 'none'
        ? 'WARNING: no tracking-script delivery method is working on this site.'
        : ($method === 'static'
            ? 'NOTICE: the site fell back to the static-file delivery method (dynamic URL failed).'
            : 'NOTICE: tracking delivery method changed to ' . $method . '.');
    $lines[] = '';
    $lines[] = 'Site:            ' . $site;
    $lines[] = 'Admin:           ' . admin_url('options-general.php?page=traqleads');
    $lines[] = 'Delivery method: ' . $method;
    $lines[] = 'Program ID:      ' . get_option('traqleads_program_id');
    $lines[] = 'Plugin version:  ' . TRAQLEADS_VERSION;
    $lines[] = 'WordPress / PHP: ' . get_bloginfo('version') . ' / ' . PHP_VERSION;
    $lines[] = 'Server:          ' . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'unknown');
    $lines[] = 'Permalinks:      ' . (get_option('permalink_structure') ?: '(plain)');
    $lines[] = 'Uploads writable:' . (is_writable($up['basedir']) ? ' yes' : ' no');
    $lines[] = '';
    $lines[] = 'Candidate self-test results:';
    foreach ($results as $m => $r) {
        if (isset($r['is_js'])) {
            $status = $r['is_js'] ? 'OK (200, JS)' : 'FAIL (HTTP ' . $r['status'] . ')';
        } else {
            $status = 'UNREACHABLE (' . ($r['detail'] ?? 'error') . ')';
        }
        $lines[] = sprintf('  - %-14s %s', $m, $status);
        $lines[] = sprintf('    %s', $r['url']);
    }
    $lines[] = '';
    $lines[] = 'Time: ' . current_time('mysql');

    wp_mail($to, $subject, implode("\n", $lines));
}

// Run the deferred / throttled health check + version flush from admin.
add_action('admin_init', function () {
    if (!get_option('traqleads_program_id')) {
        return;
    }
    if (get_option('traqleads_health_recheck')) {
        delete_option('traqleads_health_recheck');
        traqleads_run_health_check();
        return;
    }
    // Fallback for hosts with wp-cron disabled: at most once per day.
    $last = (int) get_option('traqleads_health_checked', 0);
    if (time() - $last > DAY_IN_SECONDS) {
        traqleads_run_health_check();
    }
});

// Re-pick delivery when configuration that affects URLs changes.
add_action('add_option_traqleads_program_id', 'traqleads_flag_recheck');
add_action('update_option_traqleads_program_id', 'traqleads_flag_recheck');
function traqleads_flag_recheck(): void
{
    update_option('traqleads_health_recheck', 1);
}

add_action('update_option_traqleads_proxy_path', function () {
    traqleads_register_rewrite_rules();
    flush_rewrite_rules();
    update_option('traqleads_health_recheck', 1);
});

// ==========================================================================
// Serve the tracking script (dynamic delivery)
// ==========================================================================

function traqleads_serve_script(): void
{
    $body = traqleads_fetch_tl_js();

    if ($body) {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-LiteSpeed-Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo traqleads_bootstrap_js() . $body; // phpcs:ignore — raw JS output
    } else {
        status_header(502);
        header('Content-Type: application/javascript; charset=utf-8');
        echo '/* TraqLeads: could not fetch tracking script */';
    }
    exit;
}

// ==========================================================================
// Proxy tracking events to the TraqLeads API
// ==========================================================================

/**
 * Best-known client IP. Behind a CDN/reverse proxy REMOTE_ADDR is the edge IP,
 * so prefer the first hop of X-Forwarded-For (filterable for untrusted setups).
 */
function traqleads_client_ip(): string
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';

    if (apply_filters('traqleads_trust_proxy', true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return $remote;
}

function traqleads_handle_track(): void
{
    // CORS preflight — mirror the API's public-pixel policy.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        status_header(204);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        status_header(405);
        header('Content-Type: application/json');
        echo wp_json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    // Cap the request body (tracking payloads are tiny) to avoid memory/worker abuse.
    $max = 65536;
    if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > $max) {
        status_header(413);
        header('Content-Type: application/json');
        echo wp_json_encode(['ok' => false, 'error' => 'Payload too large']);
        exit;
    }
    $body = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($body === false) {
        $body = '';
    }
    if (strlen($body) > $max) {
        status_header(413);
        header('Content-Type: application/json');
        echo wp_json_encode(['ok' => false, 'error' => 'Payload too large']);
        exit;
    }

    $ip = traqleads_client_ip();

    // Fixed-window rate limit (per real client IP, per calendar minute).
    $bucket = 'traqleads_rate_' . md5($ip) . '_' . floor(time() / 60);
    $count  = (int) get_transient($bucket);
    if ($count >= 60) {
        status_header(429);
        header('Content-Type: application/json');
        header('Retry-After: 60');
        echo wp_json_encode(['ok' => false, 'error' => 'Rate limit exceeded']);
        exit;
    }
    set_transient($bucket, $count + 1, 70);

    // Bind events to this site's program so the proxy cannot be used to inject
    // events for other programs. Filterable for advanced multi-program setups.
    if (apply_filters('traqleads_enforce_program', true)) {
        $configured = get_option('traqleads_program_id');
        if ($configured) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['p']) && $decoded['p'] !== $configured) {
                status_header(403);
                header('Content-Type: application/json');
                header('Access-Control-Allow-Origin: *');
                echo wp_json_encode(['ok' => false, 'error' => 'Program mismatch']);
                exit;
            }
        }
    }

    $api_url  = rtrim((string) get_option('traqleads_api_url', 'https://traqleads.com/api'), '/');
    $response = wp_remote_post($api_url . '/t', [
        'headers' => [
            'Content-Type'    => 'application/json',
            'X-Forwarded-For' => $ip,
            'User-Agent'      => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
        ],
        'body'    => $body,
        'timeout' => 15,
    ]);

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    if (!is_wp_error($response)) {
        status_header(wp_remote_retrieve_response_code($response));
        echo wp_remote_retrieve_body($response); // phpcs:ignore — passthrough of API JSON
    } else {
        status_header(502);
        echo wp_json_encode(['ok' => false]);
    }
    exit;
}

// ==========================================================================
// Inject tracking script in footer
// ==========================================================================

function traqleads_inject_script(): void
{
    // Allow themes/plugins to disable auto-injection.
    if (!apply_filters('traqleads_auto_inject', true)) {
        return;
    }

    $pid = get_option('traqleads_program_id');
    if (!$pid) {
        return;
    }

    printf(
        '<script async src="%s" data-tl="%s"></script>' . "\n",
        esc_url(traqleads_script_url()),
        esc_attr($pid)
    );
}
add_action('wp_footer', 'traqleads_inject_script');

// ==========================================================================
// Admin Settings Page (Settings > TraqLeads)
// ==========================================================================

add_action('admin_menu', function () {
    add_options_page(
        'TraqLeads Tracking',
        'TraqLeads',
        'manage_options',
        'traqleads',
        'traqleads_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('traqleads_settings', 'traqleads_program_id', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);
    // Enforce HTTPS and block private/loopback hosts (SSRF) on the API URL.
    register_setting('traqleads_settings', 'traqleads_api_url', [
        'type'              => 'string',
        'sanitize_callback' => function ($value) {
            $url = esc_url_raw($value);
            if ($url && stripos($url, 'https://') !== 0) { // PHP 7.4-safe (no str_starts_with)
                add_settings_error(
                    'traqleads_api_url',
                    'https_required',
                    __('API URL must use HTTPS to protect tracking data in transit.', 'traqleads'),
                    'error'
                );
                return get_option('traqleads_api_url', 'https://traqleads.com/api');
            }
            if ($url && !wp_http_validate_url($url)) {
                add_settings_error(
                    'traqleads_api_url',
                    'invalid_host',
                    __('API URL host is not allowed (private, loopback, and link-local addresses are blocked).', 'traqleads'),
                    'error'
                );
                return get_option('traqleads_api_url', 'https://traqleads.com/api');
            }
            return $url;
        },
        'default'           => 'https://traqleads.com/api',
    ]);
    register_setting('traqleads_settings', 'traqleads_proxy_path', [
        'type'              => 'string',
        'sanitize_callback' => function ($value) {
            $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
            return $clean ?: 'tq';
        },
        'default'           => 'tq',
    ]);

    add_settings_section(
        'traqleads_main',
        'Tracking Configuration',
        function () {
            echo '<p>Connect your site to TraqLeads for affiliate tracking. The plugin proxies all tracking through your own domain so ad blockers cannot interfere, and automatically picks a delivery method that works on your host.</p>';
        },
        'traqleads'
    );

    add_settings_field('traqleads_program_id', 'Program ID', function () {
        $val = get_option('traqleads_program_id', '');
        printf(
            '<input type="text" name="traqleads_program_id" value="%s" class="regular-text" placeholder="e.g. 15d8f99b-7273-45a7-b247-36123107a7b5" />'
            . '<p class="description">Your program UUID from the TraqLeads dashboard.</p>',
            esc_attr($val)
        );
    }, 'traqleads', 'traqleads_main');

    add_settings_field('traqleads_api_url', 'API URL', function () {
        $val = get_option('traqleads_api_url', 'https://traqleads.com/api');
        printf(
            '<input type="url" name="traqleads_api_url" value="%s" class="regular-text" />'
            . '<p class="description">TraqLeads API base URL. Default: <code>https://traqleads.com/api</code></p>',
            esc_attr($val)
        );
    }, 'traqleads', 'traqleads_main');

    add_settings_field('traqleads_proxy_path', 'Proxy Path', function () {
        $val = get_option('traqleads_proxy_path', 'tq');
        printf(
            '<input type="text" name="traqleads_proxy_path" value="%s" class="regular-text" />'
            . '<p class="description">URL prefix for the tracking proxy (letters, numbers, hyphens, underscores). The plugin serves the script under <code>/%s/</code> and accepts events at <code>/%s/t</code>.</p>',
            esc_attr($val),
            esc_attr($val),
            esc_attr($val)
        );
    }, 'traqleads', 'traqleads_main');
});

// ==========================================================================
// Admin actions
// ==========================================================================

add_action('admin_post_traqleads_clear_cache', function () {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'traqleads'));
    }
    check_admin_referer('traqleads_clear_cache');
    delete_transient('traqleads_tl_js');
    delete_transient('traqleads_tl_js_hash');
    delete_transient('traqleads_tl_js_stale');
    traqleads_run_health_check(); // refresh + regenerate static + re-pick
    wp_redirect(add_query_arg(['page' => 'traqleads', 'cleared' => '1'], admin_url('options-general.php')));
    exit;
});

add_action('admin_post_traqleads_flush_rules', function () {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'traqleads'));
    }
    check_admin_referer('traqleads_flush_rules');
    traqleads_register_rewrite_rules();
    flush_rewrite_rules();
    wp_redirect(add_query_arg(['page' => 'traqleads', 'flushed' => '1'], admin_url('options-general.php')));
    exit;
});

add_action('admin_post_traqleads_run_diagnostics', function () {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'traqleads'));
    }
    check_admin_referer('traqleads_run_diagnostics');
    traqleads_register_rewrite_rules();
    flush_rewrite_rules();
    traqleads_run_health_check();
    wp_redirect(add_query_arg(['page' => 'traqleads', 'diagnosed' => '1'], admin_url('options-general.php')));
    exit;
});

// ==========================================================================
// Settings page UI
// ==========================================================================

function traqleads_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $pid    = get_option('traqleads_program_id', '');
    $method = get_option('traqleads_delivery_method', '');
    $state  = get_option('traqleads_health_state', []);
    $labels = [
        'dynamic_pretty' => 'Dynamic (clean URL)',
        'dynamic_query'  => 'Dynamic (query string)',
        'static'         => 'Static file (uploads)',
        'none'           => 'None working',
        ''               => 'Not yet tested',
    ];
    ?>
    <div class="wrap">
        <h1>TraqLeads Tracking</h1>
        <?php if (isset($_GET['cleared'])): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Script cache cleared and delivery re-checked.', 'traqleads'); ?></p></div>
        <?php endif; ?>
        <?php if (isset($_GET['flushed'])): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Rewrite rules flushed.', 'traqleads'); ?></p></div>
        <?php endif; ?>
        <?php if (isset($_GET['diagnosed'])): ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Diagnostics complete — see the delivery status below.', 'traqleads'); ?></p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('traqleads_settings');
            do_settings_sections('traqleads');
            submit_button();
            ?>
        </form>

        <?php if ($pid): ?>
            <hr />
            <h2><?php esc_html_e('Delivery Status', 'traqleads'); ?></h2>
            <table class="widefat striped" style="max-width: 760px;">
                <tbody>
                    <tr>
                        <td style="width:200px;"><strong><?php esc_html_e('Active method', 'traqleads'); ?></strong></td>
                        <td>
                            <?php echo esc_html($labels[$method] ?? $method); ?>
                            <?php if ($method === 'static'): ?>
                                <span style="color:#996800;">— dynamic URL failed on this host; serving a static file (works everywhere).</span>
                            <?php elseif ($method === 'none'): ?>
                                <span style="color:#b32d2e;">— no method is working. Check that the proxy path is reachable and uploads is writable.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Injected script URL', 'traqleads'); ?></strong></td>
                        <td><code><?php echo esc_html(traqleads_script_url()); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Event endpoint', 'traqleads'); ?></strong></td>
                        <td><code><?php echo esc_html(traqleads_track_url()); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Last checked', 'traqleads'); ?></strong></td>
                        <td><?php echo $state && !empty($state['time']) ? esc_html(human_time_diff($state['time']) . ' ago') : esc_html__('never', 'traqleads'); ?></td>
                    </tr>
                </tbody>
            </table>

            <?php if (!empty($state['results'])): ?>
                <h3 style="margin-top:18px;"><?php esc_html_e('Self-test results', 'traqleads'); ?></h3>
                <table class="widefat striped" style="max-width: 760px;">
                    <thead><tr><th><?php esc_html_e('Method', 'traqleads'); ?></th><th><?php esc_html_e('Result', 'traqleads'); ?></th><th>URL</th></tr></thead>
                    <tbody>
                    <?php foreach ($state['results'] as $m => $r): ?>
                        <tr>
                            <td><?php echo esc_html($labels[$m] ?? $m); ?></td>
                            <td>
                                <?php
                                if (isset($r['is_js'])) {
                                    echo $r['is_js']
                                        ? '<span style="color:#1a7f37;">✓ 200 (JS)</span>'
                                        : '<span style="color:#b32d2e;">✗ HTTP ' . esc_html($r['status']) . '</span>';
                                } else {
                                    echo '<span style="color:#996800;">unreachable</span>';
                                }
                                ?>
                            </td>
                            <td><code style="font-size:11px;"><?php echo esc_html($r['url']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p style="margin-top: 14px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="traqleads_run_diagnostics" />
                    <?php wp_nonce_field('traqleads_run_diagnostics'); ?>
                    <?php submit_button(__('Run Diagnostics', 'traqleads'), 'primary', 'submit', false); ?>
                </form>
                <span style="margin-left: 8px; color: #646970; font-size: 13px;">Flushes rewrite rules, regenerates the static file, re-tests every delivery method, and picks the best one.</span>
            </p>
            <p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="traqleads_clear_cache" />
                    <?php wp_nonce_field('traqleads_clear_cache'); ?>
                    <?php submit_button(__('Clear Script Cache', 'traqleads'), 'secondary', 'submit', false); ?>
                </form>
                <span style="margin-left: 8px; color: #646970; font-size: 13px;">Forces a fresh fetch of tl.js from the TraqLeads API.</span>
            </p>

            <h3><?php esc_html_e('Manual Installation (optional)', 'traqleads'); ?></h3>
            <p>The plugin auto-injects the script on all frontend pages. To disable auto-injection, add this to your theme's <code>functions.php</code>:</p>
            <pre style="background: #f0f0f1; padding: 10px; max-width: 760px;"><code>add_filter('traqleads_auto_inject', '__return_false');</code></pre>
            <p>Then place the script wherever you want it (the URL below is the method currently active on your host):</p>
            <pre style="background: #f0f0f1; padding: 10px; max-width: 760px;"><code>&lt;script async src="<?php echo esc_html(traqleads_script_url()); ?>" data-tl="<?php echo esc_html($pid); ?>"&gt;&lt;/script&gt;</code></pre>
        <?php else: ?>
            <hr />
            <p><strong><?php esc_html_e('Not configured.', 'traqleads'); ?></strong> <?php esc_html_e('Enter your Program ID above to enable tracking.', 'traqleads'); ?></p>
        <?php endif; ?>
    </div>
    <?php
}
