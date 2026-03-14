<?php
/**
 * Yuno Payment Gateway uninstall handler.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin options
delete_option('woocommerce_yuno_settings');
