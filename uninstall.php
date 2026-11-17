<?php
/**
 * Remove every trace of the plugin on uninstall.
 *
 * Deactivating leaves data alone; uninstalling must not. Legacy names from before the
 * options were consolidated are cleaned up too, so an old install ends up just as tidy.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function sefid_uninstall_cleanup() {
    $options = array(
        'sefid_kit',              // current: one row for settings and sync state
        'sefid_wp_kit_options',   // legacy
        'sefid_initial_synced',   // legacy
        'sefid_sync_page',        // legacy
    );
    foreach ($options as $name) {
        delete_option($name);
    }

    $transients = array('sefid_conn_cache', 'sefid_conn_err', 'sefid_offline', 'sefid_update_info');
    foreach ($transients as $name) {
        delete_transient($name);
    }

    foreach (array('sefid_kit_sync_batch', 'sefid_kit_sync_retry', 'sefid_wp_kit_sync_batch') as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $blog_id) {
        switch_to_blog($blog_id);
        sefid_uninstall_cleanup();
        restore_current_blog();
    }
} else {
    sefid_uninstall_cleanup();
}
