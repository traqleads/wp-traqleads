<?php
/**
 * TraqLeads Tracking — Uninstall
 *
 * Fix M38-1: Clean up all plugin options and transients when the plugin is deleted
 * via the WordPress admin Plugins screen.
 *
 * @package TraqLeads
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('traqleads_program_id');
delete_option('traqleads_api_url');
delete_option('traqleads_proxy_path');

// Remove cached script and integrity hash
delete_transient('traqleads_tl_js');
delete_transient('traqleads_tl_js_hash');

// Flush rewrite rules to remove our custom routes
flush_rewrite_rules();
