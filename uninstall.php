<?php
/**
 * TraqLeads Tracking — Uninstall
 *
 * Clean up all plugin options, transients, the static delivery file, and the
 * scheduled health check when the plugin is deleted via the admin Plugins screen.
 *
 * @package TraqLeads
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Settings.
delete_option('traqleads_program_id');
delete_option('traqleads_api_url');
delete_option('traqleads_proxy_path');

// Delivery / health-check state.
delete_option('traqleads_version');
delete_option('traqleads_delivery_method');
delete_option('traqleads_active_script_url');
delete_option('traqleads_health_state');
delete_option('traqleads_health_checked');
delete_option('traqleads_health_recheck');
delete_option('traqleads_static_hash');

// Cached script + integrity hash + stale copy + locks.
delete_transient('traqleads_tl_js');
delete_transient('traqleads_tl_js_hash');
delete_transient('traqleads_tl_js_stale');
delete_transient('traqleads_tl_js_lock');

// Remove the generated static delivery folder from uploads.
$slug  = get_option('traqleads_static_slug');
$fname = get_option('traqleads_static_file');
if ($slug) {
    $up  = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . $slug;

    if (!function_exists('WP_Filesystem')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();
    global $wp_filesystem;
    if ($wp_filesystem && $wp_filesystem->is_dir($dir)) {
        if ($fname && $wp_filesystem->exists($dir . '/' . $fname)) {
            $wp_filesystem->delete($dir . '/' . $fname);
        }
        $wp_filesystem->rmdir($dir); // only removes if empty — safe
    }
}
delete_option('traqleads_static_slug');
delete_option('traqleads_static_file');

// Unschedule the health check cron.
$ts = wp_next_scheduled('traqleads_health_check_cron');
if ($ts) {
    wp_unschedule_event($ts, 'traqleads_health_check_cron');
}

// Flush rewrite rules to remove our custom routes.
flush_rewrite_rules();
