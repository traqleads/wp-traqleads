<?php
/**
 * Plugin Name: TraqLeads Tracking
 * Plugin URI:  https://traqleads.com
 * Description: First-party proxy for TraqLeads affiliate tracking. Serves the tracking script and proxies events through your own domain to bypass ad blockers.
 * Version:     1.3.0
 * Author:      TraqLeads
 * Author URI:  https://traqleads.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: traqleads
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TRAQLEADS_VERSION', '1.3.0');

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
// Activation / Deactivation — flush rewrite rules
// ==========================================================================

register_activation_hook(__FILE__, function () {
    traqleads_register_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

// ==========================================================================
// Rewrite Rules
// ==========================================================================

function traqleads_register_rewrite_rules(): void
{
    $path = preg_replace('/[^a-zA-Z0-9_-]/', '', get_option('traqleads_proxy_path', 'tq'));
    if (!$path) {
        $path = 'tq';
    }
    add_rewrite_rule('^' . $path . '/tl\.js$', 'index.php?traqleads_action=script', 'top');
    add_rewrite_rule('^' . $path . '/v[0-9]+/tl\.js$', 'index.php?traqleads_action=script', 'top');
    add_rewrite_rule('^' . $path . '/t/?$', 'index.php?traqleads_action=track', 'top');
}

add_action('init', 'traqleads_register_rewrite_rules');

add_filter('query_vars', function (array $vars): array {
    $vars[] = 'traqleads_action';
    return $vars;
});

// ==========================================================================
// Request Handler — intercept rewrite matches
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
// Serve cached tl.js
// ==========================================================================

function traqleads_serve_script(): void
{
    $cached = get_transient('traqleads_tl_js');

    // Fix M38-2: Verify cached script integrity against stored hash (prevents DB-level tampering)
    if ($cached) {
        $stored_hash = get_transient('traqleads_tl_js_hash');
        if ($stored_hash && hash('sha256', $cached) !== $stored_hash) {
            // Hash mismatch — cached content was tampered with, discard and re-fetch
            delete_transient('traqleads_tl_js');
            delete_transient('traqleads_tl_js_hash');
            $cached = false;
        }
    }

    if (!$cached) {
        $api_url = rtrim(get_option('traqleads_api_url', 'https://traqleads.com/api'), '/');
        $response = wp_remote_get($api_url . '/tl.js', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/javascript'],
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $cached = wp_remote_retrieve_body($response);
            set_transient('traqleads_tl_js', $cached, DAY_IN_SECONDS);
            set_transient('traqleads_tl_js_hash', hash('sha256', $cached), DAY_IN_SECONDS);
        }
    }

    if ($cached) {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-LiteSpeed-Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $cached;
    } else {
        status_header(502);
        header('Content-Type: application/javascript; charset=utf-8');
        echo '/* TraqLeads: could not fetch tracking script */';
    }
    exit;
}

// ==========================================================================
// Proxy tracking events to TraqLeads API
// ==========================================================================

function traqleads_handle_track(): void
{
    // CORS preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        status_header(204);
        exit;
    }

    // Only accept POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        status_header(405);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    // Fix L38-1: Per-IP rate limiting (60 requests/minute) to prevent tracking flood
    $ip_hash = 'traqleads_rate_' . md5($_SERVER['REMOTE_ADDR'] ?? '');
    $count   = (int) get_transient($ip_hash);
    if ($count >= 60) {
        status_header(429);
        header('Content-Type: application/json');
        header('Retry-After: 60');
        echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded']);
        exit;
    }
    set_transient($ip_hash, $count + 1, MINUTE_IN_SECONDS);

    $api_url = rtrim(get_option('traqleads_api_url', 'https://traqleads.com/api'), '/');
    $body    = file_get_contents('php://input');

    // Forward the request server-side
    $response = wp_remote_post($api_url . '/t', [
        'headers' => [
            'Content-Type'    => 'application/json',
            'X-Forwarded-For' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'User-Agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ],
        'body'    => $body,
        'timeout' => 15,
    ]);

    // Return the API response to the browser
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    if (!is_wp_error($response)) {
        status_header(wp_remote_retrieve_response_code($response));
        echo wp_remote_retrieve_body($response);
    } else {
        status_header(502);
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ==========================================================================
// Inject tracking script in footer
// ==========================================================================

add_action('wp_footer', function () {
    // Fix L38-2: Allow themes/plugins to disable auto-injection
    if (!apply_filters('traqleads_auto_inject', true)) {
        return;
    }

    // Skip if not configured
    $pid = get_option('traqleads_program_id');
    if (!$pid) {
        return;
    }

    $path = preg_replace('/[^a-zA-Z0-9_-]/', '', get_option('traqleads_proxy_path', 'tq'));
    if (!$path) {
        $path = 'tq';
    }

    $ver = str_replace('.', '', TRAQLEADS_VERSION);
    printf(
        '<script async src="/%s/v%s/tl.js" data-tl="%s"></script>' . "\n",
        esc_attr($path),
        esc_attr($ver),
        esc_attr($pid)
    );
});

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
    // Fix M38-3: Enforce HTTPS on API URL to prevent unencrypted tracking data
    register_setting('traqleads_settings', 'traqleads_api_url', [
        'type'              => 'string',
        'sanitize_callback' => function ($value) {
            $url = esc_url_raw($value);
            if ($url && !str_starts_with($url, 'https://')) {
                add_settings_error(
                    'traqleads_api_url',
                    'https_required',
                    __('API URL must use HTTPS to protect tracking data in transit.', 'traqleads'),
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
            echo '<p>Connect your site to TraqLeads for affiliate tracking. The plugin proxies all tracking through your own domain so ad blockers cannot interfere.</p>';
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
            . '<p class="description">URL prefix for the tracking proxy. Your site will serve the script at <code>/%s/tl.js</code> and accept events at <code>/%s/t</code>. Change this to any short path you like (letters, numbers, hyphens, underscores). <strong>Save and re-save Permalinks after changing this.</strong></p>',
            esc_attr($val),
            esc_attr($val),
            esc_attr($val)
        );
    }, 'traqleads', 'traqleads_main');
});

// ==========================================================================
// Clear script cache action
// ==========================================================================

add_action('admin_post_traqleads_clear_cache', function () {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'traqleads'));
    }
    check_admin_referer('traqleads_clear_cache');
    delete_transient('traqleads_tl_js');
    delete_transient('traqleads_tl_js_hash');
    wp_redirect(add_query_arg([
        'page'    => 'traqleads',
        'cleared' => '1',
    ], admin_url('options-general.php')));
    exit;
});

// Re-flush rewrite rules when proxy path setting changes
add_action('update_option_traqleads_proxy_path', function () {
    traqleads_register_rewrite_rules();
    flush_rewrite_rules();
});

function traqleads_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $path = preg_replace('/[^a-zA-Z0-9_-]/', '', get_option('traqleads_proxy_path', 'tq')) ?: 'tq';
    $pid  = get_option('traqleads_program_id', '');
    ?>
    <div class="wrap">
        <h1>TraqLeads Tracking</h1>
        <?php if (isset($_GET['cleared']) && $_GET['cleared'] === '1'): ?>
            <div class="notice notice-success is-dismissible"><p><?php _e('Script cache cleared. The tracking script will be re-fetched on the next page load.', 'traqleads'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('traqleads_settings');
            do_settings_sections('traqleads');
            submit_button();
            ?>
        </form>

        <hr />
        <h2>Status</h2>
        <?php if ($pid): ?>
            <table class="widefat striped" style="max-width: 600px;">
                <tbody>
                    <tr>
                        <td><strong>Script URL</strong></td>
                        <td><code><?php echo esc_html(home_url('/' . $path . '/tl.js')); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Tracking Endpoint</strong></td>
                        <td><code><?php echo esc_html(home_url('/' . $path . '/t')); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong>Script Cached</strong></td>
                        <td><?php echo get_transient('traqleads_tl_js') ? 'Yes' : 'No (will fetch on first request)'; ?></td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top: 12px;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="traqleads_clear_cache" />
                    <?php wp_nonce_field('traqleads_clear_cache'); ?>
                    <?php submit_button(__('Clear Script Cache', 'traqleads'), 'secondary', 'submit', false); ?>
                </form>
                <span style="margin-left: 8px; color: #646970; font-size: 13px;">Forces a fresh fetch of tl.js from the TraqLeads API.</span>
            </p>

            <h3>Manual Installation (optional)</h3>
            <p>The plugin auto-injects the script on all frontend pages. If you prefer manual placement, disable the auto-inject by adding this to your theme's <code>functions.php</code>:</p>
            <pre style="background: #f0f0f1; padding: 10px; max-width: 600px;"><code>remove_action('wp_footer', 'traqleads_inject_script');</code></pre>
            <p>Then add this wherever you want the script:</p>
            <pre style="background: #f0f0f1; padding: 10px; max-width: 600px;"><code>&lt;script async src="/<?php echo esc_html($path); ?>/tl.js" data-tl="<?php echo esc_html($pid); ?>"&gt;&lt;/script&gt;</code></pre>
        <?php else: ?>
            <p><strong>Not configured.</strong> Enter your Program ID above to enable tracking.</p>
        <?php endif; ?>
    </div>
    <?php
}
