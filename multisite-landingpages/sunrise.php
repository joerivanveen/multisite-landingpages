<?php

namespace ruigehond011;
// global config flag(s) for the plugin to pickup
global $ruigehond011_txt_record_mandatory;
$ruigehond011_txt_record_mandatory = false;
// call the function that selects blog based on the domain
sunrise();
// intro to multisite setup sunrise stuff:
// https://wordpress.stackexchange.com/a/126176

/**
 * look into ruigehond011_landingpage table to set the multisite environment up for the correct blog for this visitor
 * when not found in the table, nothing is changed, let WordPress handle it further
 * @returns void
 * @since 0.1.0
 * @since 0.9.1 only approved (any non-0 value) domains are considered
 */
function sunrise()
{
    global $wpdb;
    // get the domain
    $domain = \strtolower(\stripslashes($_SERVER['HTTP_HOST']));
    // remove the port number if present
    if (\false !== \strpos($domain, ':')) $domain = \explode(':', $domain)[0];
    // remove www if present
    if (\strpos($domain, 'www.') === 0) $domain = \substr($domain, 4);

    // if the domain is in the landingpage table, setup the global site and blog name for ms-settings.php
    // else do nothing, it will be setup like this multisite works normally
    $base_prefix = $wpdb->base_prefix;
    $table_name = $base_prefix . 'ruigehond011_landingpages';
    $rows = $wpdb->get_results('SELECT rh.domain AS landing_domain, wp.domain AS multisite_domain, rh.post_name FROM ' .
        $table_name . ' rh INNER JOIN ' . $base_prefix . 'blogs wp ON wp.blog_id = rh.blog_id WHERE rh.domain = \'' .
        \addslashes($domain) . '\';');

    //var_dump($wpdb->last_query);
    //var_dump($rows);
    //die();

    if (\count($rows) === 1) {
        $row = $rows[0];
        // set the required global object for ruigehond011 subsite part of the plugin
        global $ruigehond011_slug;
        $ruigehond011_slug = $row->post_name;
        // set the HTTP_HOST to the domain of this blog you want, let WordPress handle it further
        $_SERVER['HTTP_HOST'] = $row->multisite_domain;
        $row = \null;
    } // else it must be 0 rows, just let WordPress handle it then
    $rows = \null;
}