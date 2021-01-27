<?php

namespace ruigehond011;
/**
 * configurable variable $ruigehond011_minute: after entering a landingpage domain the site must be visited
 * within this number of minutes to validate the domain, integers only.
 */
global $ruigehond011_minute;
$ruigehond011_minute = 10;
// call the function that selects blog based on the domain
sunrise();
// multi site setup info used:
// https://wordpress.stackexchange.com/a/126176

/**
 * look into ruigehond011_landingpage table to set the multisite environment up for the correct blog for this visitor
 * when not found in the table, nothing is changed, let WordPress handle it further
 * @returns void
 * @since 0.1.0
 */
function sunrise()
{
    global $wpdb, $ruigehond011_minute;
    // get the domain
    $domain = \strtolower(\stripslashes($_SERVER['HTTP_HOST']));
    // remove the port number if present
    if (\false !== \strpos($domain, ':')) {
        $domain = \explode(':', $domain)[0];
    }

    // if the domain is in the landingpage table, setup the global site and blog name for ms-settings.php
    // else do nothing, it will be setup like this multisite works normally
    $base_prefix = $wpdb->base_prefix;
    $table_name = $base_prefix . 'ruigehond011_landingpages';
    $rows = $wpdb->get_results('SELECT rh.domain AS landing_domain, wp.domain AS multisite_domain, rh.blog_id, rh.post_name, rh.date_created > DATE_SUB(now(), INTERVAL ' .
        $ruigehond011_minute . ' MINUTE) AS is_new, rh.approved FROM ' .
        $table_name . ' rh INNER JOIN ' . $base_prefix . 'blogs wp ON wp.blog_id = rh.blog_id WHERE rh.domain = \'' .
        \addslashes($domain) . '\';');

//    var_dump($wpdb->last_query);
//    die();

    if (count($rows) === 1) {
        $row = $rows[0];
        if ($row->approved !== '1') { // approve it when it is recent
            if ($row->is_new === '1') {
                $wpdb->query('UPDATE ' . $table_name .
                    ' SET approved = 1 WHERE domain = \'' . addslashes($row->landing_domain) . '\';');
                if ($wpdb->rows_affected !== 1) {
                    wp_die('Sunrise() error in multisite-landingpage');
                }
            } else {
                $rows = \null;
                // (attempt to) remove the entry
                $wpdb->query('DELETE FROM ' . $table_name .
                    ' WHERE domain = \'' . addslashes($row->landing_domain) . '\';');
                return;
            }
        }
        // set the required global object for ruigehond011 subsite part of the plugin
        global $ruigehond011_slug;
        $ruigehond011_slug = $row->post_name;
        // set the HTTP_HOST to the domain of this blog you want, let WordPress handle it further
        $_SERVER['HTTP_HOST'] = $row->multisite_domain;
        $row = \null;
    } // else it must be 0 rows, just let WordPress handle it then
    $rows = \null;
}