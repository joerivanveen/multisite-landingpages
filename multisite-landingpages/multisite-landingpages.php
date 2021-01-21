<?php
/*
Plugin Name: Multisite landingpages
Plugin URI: https://github.com/joerivanveen/multisite-landingpages
Description: Serves a specific landing page from WordPress depending on the domain used to access the multisite installation.
Version: 0.1.0
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: each-domain-a-page
Domain Path: /languages/
*/
// This is plugin nr. 11 by Ruige hond. It identifies as: ruigehond011.
Define('RUIGEHOND011_VERSION', '0.1.0');
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook(__FILE__, array(new ruigehond011(), 'install'));
register_deactivation_hook(__FILE__, 'ruigehond011_deactivate');
register_uninstall_hook(__FILE__, 'ruigehond011_uninstall');
// Startup the plugin
add_action('init', array(new ruigehond011(), 'initialize'));

// there is a multisite setting to assign domains to blogids, only the owner of the network can do this

// each site can assign its assigned domains to a certain slug

// this is the version you extend from and also the name of the class in (class)file ruigehond.php
if (!class_exists('ruigehond_0_3_5', false)) {
    include_once(dirname(__FILE__) . '/includes/ruigehond.php'); // base class
}

// the class
class ruigehond011 extends ruigehond_0_3_5
{
    /**
     * ruigehond011 constructor
     * @since 0.1.0
     */
    public function __construct()
    {
        parent::__construct('ruigehond011');

    }

    /**
     * Initialize the plugin for use by WordPress, upon init hook
     * @since 0.1.0
     */
    public function initialize()
    {
        // initialize the plugin here...
    }

    public function install()
    {
        // do nothing
    }

    public function multiSettings()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>' . esc_html(get_admin_page_title()) . '</h1><form action="options.php" method="post">';
        // output security fields for the registered setting
        settings_fields('ruigehond011');
        // output setting sections and their fields
        do_settings_sections('ruigehond011');
        // output save settings button
        submit_button(__('Save settings', 'multisite-landingpages'));
        echo '</form></div>';
    }


    /**
     * create site settings menu item
     * @since 0.1.0
     */
    public function multiMenu()
    {
        add_submenu_page(
            'settings.php',
            'Multisige landingpages',
            'Landingpages',
            'manage_options',
            'multisite-landingpages',
            array($this, 'multiSettings')
        );
    }

}

/**
 * proxy functions for deactivate and uninstall
 */
function ruigehond011_deactivate()
{
    // nothing to do here, you can keep the original settings
}

function ruigehond011_uninstall()
{
    // remove settings
    delete_option('ruigehond007');
}

