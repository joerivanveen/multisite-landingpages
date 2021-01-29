<?php
/*
Plugin Name: Multisite Landingpages
Plugin URI: https://github.com/joerivanveen/multisite-landingpages
Description: Serves a specific landing page from Wordpress depending on the domain used to access the Wordpress installation.
Version: 0.1.0
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: multisite-landingpages
Domain Path: /languages/
*/
defined('ABSPATH') or die();
// This is plugin nr. 11 by Ruige hond. It identifies as: ruigehond011.
Define('ruigehond011_VERSION', '0.1.0');
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook(__FILE__, array(new ruigehond011(), 'install'));
register_deactivation_hook(__FILE__, array(new ruigehond011(), 'deactivate'));
register_uninstall_hook(__FILE__, 'ruigehond011_uninstall');
// Startup the plugin
add_action('init', array(new ruigehond011(), 'initialize'));

//
class ruigehond011
{
    private $options, $use_canonical, $canonicals, $canonical_prefix, $remove_sitename_from_title = false;
    private $slug, $minute, $wpdb, $blog_id, $table_name, $locale, $post_types = array(); // cached values

    /**
     * ruigehond011 constructor
     * loads settings that are available also based on current url
     * @since 0.1.0
     */
    public function __construct()
    {
        // cache some global vars for this instance
        global $ruigehond011_slug, $ruigehond011_minute, $wpdb, $blog_id;
        // use base prefix to make a table shared by all the blogs
        $this->table_name = $wpdb->base_prefix . 'ruigehond011_landingpages';
        $this->wpdb = $wpdb;
        $this->blog_id = isset($blog_id) ? \intval($blog_id) : \null;
        // get the slug we are using for this request, as far as the plugin is concerned
        // set the slug to the value found in sunrise.php, or to the regular slug if none was found
        $this->slug = (isset($ruigehond011_slug)) ? $ruigehond011_slug : \trim($_SERVER['REQUEST_URI'], '/');
        // set the minute to minute defined in sunrise.php, default to 10
        $this->minute = (isset($ruigehond011_minute)) ? \intval($ruigehond011_minute) : 10;
        // set the options for the current subsite
        $this->options = get_option('ruigehond011');
        if (isset($this->options)) {
            $this->use_canonical = isset($this->options['use_canonical']) and (true === $this->options['use_canonical']);
            if ($this->use_canonical) {
                if (isset($this->options['use_ssl']) and (true === $this->options['use_ssl'])) {
                    $this->canonical_prefix = 'https://';
                } else {
                    $this->canonical_prefix = 'http://';
                }
                if (isset($this->options['use_www']) and (true === $this->options['use_www'])) $this->canonical_prefix .= 'www.';
                // load the canonicals
                $this->canonicals = array();
                if (isset($this->blog_id)) {
                    $rows = $this->wpdb->get_results('SELECT domain, post_name FROM ' . $this->table_name .
                        ' WHERE blog_id = ' . $this->blog_id . ' AND approved = 1;');
                    foreach ($rows as $index => $row) {
                        $this->canonicals[$row->post_name] = $row->domain;
                    }
                    $rows = \null;
                }
            }
            $this->remove_sitename_from_title = isset($this->options['remove_sitename']) and (true === $this->options['remove_sitename']);
        }
        // https://wordpress.stackexchange.com/a/89965
        //if (isset($this->locale)) add_filter('locale', array($this, 'getLocale'), 1, 1);
    }

    /**
     * initialize the plugin, sets up necessary filters and actions.
     * @since 1.0.0
     */
    public function initialize()
    {
        // for ajax requests that (hopefully) use get_admin_url() you need to set them to the current domain if
        // applicable to avoid cross origin errors
        add_filter('admin_url', array($this, 'adminUrl'));
        if (is_admin()) {
            load_plugin_textdomain('multisite-landingpages', false, dirname(plugin_basename(__FILE__)) . '/languages/');
            add_action('admin_init', array($this, 'settings'));
            add_action('admin_menu', array($this, 'menuitem')); // necessary to have the page accessible to user
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'settingslink')); // settings link on plugins page
            if ($this->onSettingsPage()) ruigehond011_display_warning();
        } else {
            // original
            add_action('parse_request', array($this, 'get')); // passes WP_Query object
            if ($this->use_canonical) {
                // fix the canonical url for functions that get the url, subject to additions...
                foreach (array(
                             'post_link',
                             'page_link',
                             'post_type_link',
                             'get_canonical_url',
                             'wpseo_opengraph_url', // Yoast
                             'wpseo_canonical', // Yoast
                         ) as $filter) {
                    add_filter($filter, array($this, 'fixUrl'), 99, 1);
                }
            }
        }
    }

    /**
     * Returns a relative url for pages that are accessed on a different domain than the original blog enabling
     * ajax calls without the dreaded cross origin errors (as long as people use the recommended get_admin_url())
     * @param $url
     * @return string|string[]
     * @since 1.3.0
     */
    public function adminUrl($url)
    {
        if ($this->postType($this->slug)) return str_replace(get_site_url(), '', $url);

        return $url;
    }

    /**
     * Hook for the locale, set with ->initialize()
     * @param $locale
     * @return string the locale set by multisite-landingpages, fallback to the current one (just) set by Wordpress
     * @since 1.3.0
     */
    public function getLocale($locale)
    {
        return isset($this->locale) ? $this->locale : $locale;
    }

    /**
     * ‘get’ is the actual functionality of the plugin
     *
     * @param $query Object holding the query prepared by Wordpress
     * @return mixed Object is returned either unchanged, or the request has been updated with the post to show
     */
    public function get($query)
    {
        $slug = $this->slug;
        if (($type = $this->postType($slug))) { // fails when post not found, null is returned which is falsy
            if ($this->remove_sitename_from_title) {
                if (has_action('wp_head', '_wp_render_title_tag') == 1) {
                    remove_action('wp_head', '_wp_render_title_tag', 1);
                    add_action('wp_head', array($this, 'render_title_tag'), 1);
                }
                add_filter('wpseo_title', array($this, 'get_title'), 1);
            }
            if ($type === 'page') {
                $query->query_vars['pagename'] = $slug;
                $query->query_vars['request'] = $slug;
                $query->query_vars['did_permalink'] = true;
            } elseif ($type === 'post') {
                $query->query_vars['name'] = $slug;
                $query->request = $slug;
                $query->matched_query = 'name=' . $slug . '$page='; // TODO paging??
                $query->did_permalink = true;
            } // does not work with custom post types (yet) TODO redirect to homepage?
        }

        return $query;
    }

    /**
     * substitute for standard wp title rendering to remove the site name
     * @since 1.2.2
     */
    public function render_title_tag()
    {
        echo '<title>' . get_the_title() . '</title>';
    }

    /**
     * substitute title for yoast
     * @since 1.3.0
     */
    public function get_title()
    {
        return get_the_title();
    }

    /**
     * @param string $url Wordpress inputs the url it has calculated for a post
     * @return string if this url has a slug that is one of ours, the correct full domain name is returned, else unchanged
     */
    public function fixUrl($url) //, and $post if arguments is set to 2 in stead of one in add_filter (during initialize)
    {
        if ($index = \strrpos($url, '/', -2)) { // skip over the trailing slash
            $proposed_slug = \str_replace('/', '', \str_replace('www-', '', \substr($url, $index + 1)));
            if (isset($this->canonicals[$proposed_slug])) {
                $url = $this->canonical_prefix . $this->canonicals[$proposed_slug];
            }
        }

        return $url;
    }


    /**
     * @param $slug
     * @return string|null The post-type, or null when not found for this slug
     */
    private function postType($slug)
    {
        if (isset($this->post_types[$slug])) return $this->post_types[$slug];
        global $wpdb;
        $sql = 'SELECT post_type FROM ' . $wpdb->prefix . 'posts 
        WHERE post_name = \'' . addslashes($slug) . '\' AND post_status = \'publish\';';
        $type = $wpdb->get_var($sql);
        $this->post_types[$slug] = $type;

        return $type;
    }

    /**
     * @return bool true if we are currently on the settings page of this plugin, false otherwise
     */
    private function onSettingsPage()
    {
        return (isset($_GET['page']) && $_GET['page'] === 'multisite-landingpages');
    }

    /**
     * Checks if the required lines for webfonts to work are present in the htaccess
     *
     * @return bool true when the lines are found, false otherwise
     */
    private function htaccessContainsLines()
    {
        $htaccess = get_home_path() . ".htaccess";
        if (file_exists($htaccess)) {
            $str = file_get_contents($htaccess);
            if ($start = strpos($str, '<FilesMatch "\.(eot|ttf|otf|woff)$">')) {
                if (strpos($str, 'Header set Access-Control-Allow-Origin "*"', $start)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * admin stuff
     */
    public function settings()
    {
        /**
         * register a new setting, call this function for each setting
         * Arguments: (Array)
         * - group, the same as in settings_fields, for security / nonce etc.
         * - the name of the options
         * - the function that will validate the options, valid options are automatically saved by WP
         */
        register_setting('ruigehond011', 'ruigehond011', array($this, 'settings_validate'));
        // new landing page:
        add_settings_section(
            'multisite_landingpages_new',
            __('New landingpage', 'multisite-landingpages'),
            function () {
                echo '<p>';
                echo __('In the DNS settings of your desired domain point it at this WordPress installation.', 'multisite-landingpages');
                echo ' ';
                echo __('Fill in the domain name (without protocol or irrelevant subdomains) below to add it.', 'multisite-landingpages');
                echo ' ';
                echo __('The domain must be reachable from this WordPress installation, allow some time for the DNS settings to propagate.', 'multisite-landingpages');
                echo '</p>';
            },
            'ruigehond011'
        );
        // add the necessary field
        add_settings_field(
            'ruigehond011_new',
            'Domain (without www)', // title
            function ($args) {
                echo '<input type="text" name="ruigehond011[domain_new]"/> ';
                echo '<input type="submit" name="submit" value="';
                echo __('Add', 'multisite-landingpages');
                echo '" class="button button-primary"/>';
            },
            'ruigehond011',
            'multisite_landingpages_new',
            [
                'class' => 'ruigehond_row',
            ]
        );
        // landing pages section
        add_settings_section(
            'multisite_landingpages_domains',
            __('Domains and slugs', 'multisite-landingpages'),
            function () {
                echo '<p>';
                echo __('For each domain that you added, you can assign a slug from a page or regular post.', 'multisite-landingpages');
                echo ' ';
                echo __('When someone visits your site using the domain, they will see the appropriate page or regular post.', 'multisite-landingpages');
                echo ' <em>';
                echo __('Custom post types are not yet supported.', 'multisite-landingpages');
                echo '</em><br/><strong>';
                echo __('The rest of your site keeps working as usual.', 'multisite-landingpages');
                echo '</strong></p><input type="hidden" name="ruigehond011[__delete__]"/>';
            },
            'ruigehond011'
        );
        // actual landing pages here
        $rows = $this->wpdb->get_results('SELECT domain, post_name, date_created > DATE_SUB(now(), INTERVAL ' .
            $this->minute . ' MINUTE) AS is_new, approved FROM ' . $this->table_name . ' WHERE blog_id = ' .
            $this->blog_id . ' ORDER BY domain;');
        foreach ($rows as $index => $row) {
            $domain = $row->domain;
            $slug = $row->post_name;
            add_settings_field(
                'ruigehond011_' . $domain,
                $domain, // title
                function ($args) {
                    $domain = $args['domain'];
                    $slug = $args['slug'];
                    echo '<input type="text" name="ruigehond011[';
                    echo $domain;
                    echo ']" value="';
                    echo $slug;
                    echo '"/> ';
                    // delete button
                    echo '<input type="submit" class="button" value="×" data-domain="';
                    echo $domain;
                    echo '" onclick="var val = this.getAttribute(\'data-domain\');if (confirm(\'Delete \'+val+\'?\')) {var f = this.form;f[\'ruigehond011[__delete__]\'].value=val;f.submit();}else{return false;}"/> ';
                    if ($args['approved']) {
                        echo '<span class="notice-success notice">';
                        echo __('validated', 'multisite-landingpages');
                        echo '</span>';
                        if ($args['in_canonicals']) {
                            echo ' (';
                            echo __('slug loaded in canonicals', 'multisite-landingpages');
                            echo ')';
                        }
                    } elseif ($args['is_new']) {
                        echo '<span class="notice-warning notice">';
                        echo __('visit your site with this domain to validate', 'multisite-landingpages');
                        echo '</span>';
                    } else {
                        echo '<span class="notice-error notice">';
                        echo __('expired', 'multisite-landingpages');
                        echo '</span>';
                    }
                },
                'ruigehond011',
                'multisite_landingpages_domains',
                [
                    'slug' => $slug,
                    'is_new' => $row->is_new,
                    'approved' => $row->approved,
                    'in_canonicals' => isset($this->canonicals[$slug]),
                    'domain' => $domain,
                    'class' => 'ruigehond_row',
                ]
            );
        }
        // register a new section in the page
        add_settings_section(
            'multisite_landingpages_settings', // section id
            __('General options', 'multisite-landingpages'), // title
            function () {
                echo '<p>';
                echo __('If you want your landing pages to correctly identify with the domain, you should activate the canonicals option below.', 'multisite-landingpages');
                echo ' ';
                echo __('This makes the plugin slightly slower, it will however return the domain in most cases.', 'multisite-landingpages');
                echo ' ';
                echo __('SEO plugins like Yoast may or may not interfere with this. If they do, you can probably set the desired canonical for your landing page there.', 'multisite-landingpages');
                echo '</p>';
            }, //callback
            'ruigehond011' // page
        );
        // add the checkboxes
        foreach (array(
                     'use_canonical' => __('Use domains as canonical url', 'multisite-landingpages'),
                     'use_www' => __('Canonicals must include www', 'multisite-landingpages'),
                     'use_ssl' => __('All domains have an SSL certificate installed', 'multisite-landingpages'),
                     'remove_sitename' => __('Use only post title as document title', 'multisite-landingpages'),
                 ) as $setting_name => $short_text) {
            add_settings_field(
                'ruigehond011_' . $setting_name,
                $setting_name, // title
                function ($args) {
                    $setting_name = $args['option_name'];
                    $options = $args['options'];
                    // boolval = bugfix: old versions save ‘true’ as ‘1’
                    $checked = boolval((isset($options[$setting_name])) ? $options[$setting_name] : false);
                    // make checkbox that transmits 1 or 0, depending on status
                    echo '<label><input type="hidden" name="ruigehond011[';
                    echo $setting_name;
                    echo ']" value="';
                    echo((true === $checked) ? '1' : '0');
                    echo '"><input type="checkbox"';
                    if (true === $checked) echo ' checked="checked"';
                    echo ' onclick="this.previousSibling.value=1-this.previousSibling.value"/>';
                    echo $args['label_for'];
                    echo '</label><br/>';
                },
                'ruigehond011',
                'multisite_landingpages_settings',
                [
                    'label_for' => $short_text,
                    'class' => 'ruigehond_row',
                    'options' => $this->options,
                    'option_name' => $setting_name,
                ]
            );
        }
        // display warning about htaccess conditionally
        if ($this->onSettingsPage()) { // show warning only on own options page
            if (isset($this->options['htaccess_warning'])) {
                if ($this->htaccessContainsLines()) { // maybe the user added the lines already by hand
                    //@since 1.3.0 bugfix:
                    //unset($this->options['htaccess_warning']); <- this results in an error in update_option, hurray for WP :-(
                    $this->options['htaccess_warning'] = null; // fortunately also returns false with isset()
                    $this->options_changed = true;
                    echo '<div class="notice"><p>' . __('Warning status cleared.', 'multisite-landingpages') . '</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>' . $this->options['htaccess_warning'] . '</p></div>';
                }
            }
        }
    }

    /**
     * Validates settings, especially formats the locales to an object ready for use before storing the option
     * @param $input
     * @return array
     * @since 1.3.0
     */
    public function settings_validate($input)
    {
        $options = (array)get_option('ruigehond011');
        foreach ($input as $key => $value) {
            switch ($key) {
                // on / off flags (1 vs 0 on form submit, true / false otherwise
                case 'use_canonical':
                case 'use_www':
                case 'use_ssl':
                case 'remove_sitename':
                    $options[$key] = ($value === '1' or $value === true);
                    break;
                case 'domain_new':
                    if ($value === '') break; // empty values don’t need to be processed
                    // test domain utf-8 characters: όνομα.gr
                    // todo test this with the intl extension of php enabled...
                    if (\function_exists('idn_to_ascii')) {
                        $value = \idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    }
                    if (\dns_check_record($value, 'A') or \dns_check_record($value, 'AAAA')) {
                        // if the domain exists, insert it in the landingpage table
                        $site_id = get_current_network_id();
                        $this->wpdb->query('INSERT INTO ' . $this->table_name .
                            ' (domain, blog_id, site_id, post_name) VALUES(\'' .
                            \addslashes($value) . '\', ' . $this->blog_id . ',' . $site_id . ', \'\')');
                    } else { // message the user...
                        if (\function_exists('idn_to_ascii')) {
                            \set_transient('ruigehond011_warning',
                                __('Domain name not found, DNS propagation may take some time', 'multisite-landingpages'));
                        } else {
                            \set_transient('ruigehond011_warning',
                                __('Domain name not found, DNS propagation may take some time', 'multisite-landingpages') .
                                '<br/><em>' .
                                __('Please note: international domainnames must be put in using ascii notation (punycode)', 'multisite-landingpages') .
                                '</em>');
                        }
                    }
                    break;
                case '__delete__':
                    $this->wpdb->query('DELETE FROM ' . $this->table_name . ' WHERE domain = \'' .
                        \addslashes($value) . '\';');
                    break;
                default: // this must be a slug change
                    // update the domain - slug combination
                    $this->wpdb->query('UPDATE ' . $this->table_name . ' SET post_name = \'' .
                        \addslashes(\sanitize_title($value)) . '\' WHERE domain = \'' .
                        \addslashes($key) . '\';');
            }
        }

        return $options;
    }

    public function settingspage()
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

    public function settingslink($links)
    {
        $url = get_admin_url() . 'options-general.php?page=multisite-landingpages';
        if (isset($this->options['htaccess_warning'])) {
            $settings_link = '<a style="color: #ffb900;" href="' . $url . '">' . __('Warning', 'multisite-landingpages') . '</a>';
        } else {
            $settings_link = '<a href="' . $url . '">' . __('Settings', 'multisite-landingpages') . '</a>';
        }
        array_unshift($links, $settings_link);

        return $links;
    }

    public function menuitem()
    {
        add_options_page(
            'Landingpages',
            'Landingpages',
            'manage_options',
            'multisite-landingpages',
            array($this, 'settingspage')
        );
    }

    /**
     * plugin management functions
     */
    public function install()
    {
        $this->options_changed = true;  // will save with autoload true, and also the htaccess_warning when generated
        // add cross origin for fonts to the htaccess
        if (!$this->htaccessContainsLines()) {
            $htaccess = get_home_path() . ".htaccess";
            $lines = array();
            $lines[] = '<IfModule mod_headers.c>';
            $lines[] = '<FilesMatch "\.(eot|ttf|otf|woff)$">';
            $lines[] = 'Header set Access-Control-Allow-Origin "*"';
            $lines[] = '</FilesMatch>';
            $lines[] = '</IfModule>';
            if (!insert_with_markers($htaccess, "ruigehond011", $lines)) {
                foreach ($lines as $key => $line) {
                    $lines[$key] = htmlentities($line);
                }
                $warning = '<strong>multisite-landingpages</strong><br/>';
                $warning .= __('In order for webfonts to work on alternative domains you need to add the following lines to your .htaccess:', 'multisite-landingpages');
                $warning .= '<br/><em>(';
                $warning .= __('In addition you need to have mod_headers available.', 'multisite-landingpages');
                $warning .= ')</em><br/>&nbsp;<br/>';
                $warning .= '<CODE>' . implode('<br/>', $lines) . '</CODE>';
                // report the lines to the user
                $this->options['htaccess_warning'] = $warning;
            }
        }
        // check if the table already exists, if not create it
        if ($this->wpdb->get_var('SHOW TABLES LIKE \'' . $this->table_name . '\'') !== $this->table_name) {
            $sql = 'CREATE TABLE ' . $this->table_name . ' (
						domain VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\' NOT NULL PRIMARY KEY,
						blog_id BIGINT NOT NULL,
						site_id BIGINT NOT NULL,
						post_name VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\',
						date_created TIMESTAMP NOT NULL DEFAULT NOW(),
						approved TINYINT NOT NULL DEFAULT 0)
					DEFAULT CHARACTER SET = utf8mb4
					COLLATE = utf8mb4_bin;
					';
            $this->wpdb->query($sql);
            //to approve: date_created > DATE_SUB(now(), INTERVAL 10 MINUTE)
        }
    }

    public function deactivate()
    {
        // deactivate can be done per site
        // remove settings
        delete_option('ruigehond011');
        // remove entries in the landingpage table as well
        if (isset($this->blog_id)) {
            $this->wpdb->query('DELETE FROM ' . $this->table_name . ' WHERE blog_id = ' . $this->blog_id . ';');
        }
    }

    public function uninstall()
    {
        // uninstall is always a network remove, so you can safely remove the proprietary table here
        if ($this->wpdb->get_var('SHOW TABLES LIKE \'' . $this->table_name . '\';') === $this->table_name) {
            $this->wpdb->query('DROP TABLE ' . $this->table_name . ';');
        }
    }

}

/**
 * uninstall proxy function
 */
function ruigehond011_uninstall()
{
    $hond = new ruigehond011();
    $hond->uninstall();
}

function ruigehond011_display_warning()
{
    /* Check transient, if available display it */
    if ($warning = get_transient('ruigehond011_warning')) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . $warning . '</p></div>';
        /* Delete transient, only display this notice once. */
        delete_transient('ruigehond011_warning');
        /* remember it as an option though, for the settings page as reference
        $option = get_option('ruigehond011');
        $option['warning'] = $warning;
        update_option('ruigehond011', $option, true);*/
    }
}