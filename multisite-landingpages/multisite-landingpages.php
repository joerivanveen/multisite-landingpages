<?php
/*
Plugin Name: Multisite Landingpages
Plugin URI: https://github.com/joerivanveen/each-domain-a-page
Description: Multisite version of ‘Each domain a page’. Assign the slug of a landingpage you created to a domain you own for SEO purposes.
Version: 0.9.1
Author: Ruige hond
Author URI: https://ruigehond.nl
License: GPLv3
Text Domain: multisite-landingpages
Domain Path: /languages/
*/
\defined('ABSPATH') or die();
// This is plugin nr. 11 by Ruige hond. It identifies as: ruigehond011.
\Define('ruigehond011_VERSION', '0.9.1');
// Register hooks for plugin management, functions are at the bottom of this file.
\register_activation_hook(__FILE__, array(new ruigehond011(), 'activate'));
\register_deactivation_hook(__FILE__, array(new ruigehond011(), 'deactivate'));
\register_uninstall_hook(__FILE__, 'ruigehond011_uninstall');
// setup cron hook that checks dns txt records
// https://developer.wordpress.org/plugins/cron/scheduling-wp-cron-events/
\add_action('ruigehond011_check_dns', array(new ruigehond011(), 'cronjob'));
// Startup the plugin
\add_action('init', array(new ruigehond011(), 'initialize'));

//
class ruigehond011
{
    private $options, $use_canonical, $canonicals, $canonical_prefix, $remove_sitename_from_title = false;
    private $slug, $minute, $wpdb, $blog_id, $txt_record, $table_name, $locale, $post_types = array(); // cached values
    private $db_version;

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
        $this->options = \get_option('ruigehond011');
        $options_changed = false;
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
            // get the txt_record value or set it when not available yet
            if (\false === isset($this->options['txt_record'])) { // add the guid to use for txt_record for this subsite
                $this->options['txt_record'] = 'multisite-landingpages-' . \wp_generate_uuid4();
                $options_changed = \true;
            }
            $this->txt_record = $this->options['txt_record'];
            // get the database version number, set it when not yet available
            if (\false === isset($this->options['db_version'])) {
                $this->options['db_version'] = '0.0.0';
                $options_changed = \true;
            }
            $this->db_version = $this->options['db_version'];
            // update the options if they were changed during construction
            if (\true === $options_changed) {
                \update_option('ruigehond011', $this->options);
            }
        }
        // https://wordpress.stackexchange.com/a/89965
        //if (isset($this->locale)) add_filter('locale', array($this, 'getLocale'), 1, 1);
    }

    /**
     * initialize the plugin, sets up necessary filters and actions.
     * @since 0.1.0
     */
    public function initialize()
    {
        // for ajax requests that (hopefully) use get_admin_url() you need to set them to the current domain if
        // applicable to avoid cross origin errors
        \add_filter('admin_url', array($this, 'adminUrl'));
        if (is_admin()) {
            // seems excessive but no better stable solution found yet
            // update check only on admin, so make sure to be admin after updating :-)
            $this->updateWhenNecessary();
            \load_plugin_textdomain('multisite-landingpages', false, \dirname(\plugin_basename(__FILE__)) . '/languages/');
            \add_action('admin_init', array($this, 'settings'));
            \add_action('admin_menu', array($this, 'menuitem')); // necessary to have the page accessible to user
            \add_filter('plugin_action_links_' . \plugin_basename(__FILE__), array($this, 'settingslink')); // settings link on plugins page
            if ($this->onSettingsPage()) ruigehond011_display_warning();
        } else { // regular visitor
            \add_action('parse_request', array($this, 'get')); // passes WP_Query object
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
                    \add_filter($filter, array($this, 'fixUrl'), 99, 1);
                }
            }
        }
    }

    /**
     * Returns a relative url for pages that are accessed on a different domain than the original blog enabling
     * ajax calls without the dreaded cross origin errors (as long as people use the recommended get_admin_url())
     * @param $url
     * @return string|string[]
     * @since 0.9.0
     */
    public function adminUrl($url)
    {
        $slug = $this->slug;
        if (isset($this->canonicals[$slug])) {
            return \str_replace(\get_site_url(), $this->fixUrl($slug), $url);
        }

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
                if (\has_action('wp_head', '_wp_render_title_tag') == 1) {
                    \remove_action('wp_head', '_wp_render_title_tag', 1);
                    \add_action('wp_head', array($this, 'render_title_tag'), 1);
                }
                \add_filter('wpseo_title', array($this, 'get_title'), 1);
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
     * @since 0.1.0
     */
    public function render_title_tag()
    {
        echo '<title>' . get_the_title() . '</title>';
    }

    /**
     * substitute title for yoast
     * @since 0.1.0
     */
    public function get_title()
    {
        return get_the_title();
    }

    /**
     * @param string $url Wordpress inputs the url it has calculated for a post
     * @return string if this url has a slug that is one of ours, the correct full domain name is returned, else unchanged
     * @since 0.9.0
     */
    public function fixUrl($url) //, and $post if arguments is set to 2 in stead of one in add_filter (during initialize)
    {
        // -2 = skip over trailing slash, if no slashes are found, $url must be a clean slug, else, extract the last part
        $proposed_slug = (\false === ($index = \strrpos($url, '/', -2))) ? $url : \substr($url, $index + 1);
        $proposed_slug = \trim($proposed_slug, '/');

        if (isset($this->canonicals[$proposed_slug])) {
            $url = $this->canonical_prefix . $this->canonicals[$proposed_slug];
        }

        return $url;
    }


    /**
     * @param $slug
     * @return string|null The post-type, or null when not found for this slug
     * @since 0.1.0
     */
    private function postType($slug)
    {
        if (isset($this->post_types[$slug])) return $this->post_types[$slug];
        $sql = 'SELECT post_type FROM ' . $this->wpdb->prefix . 'posts 
        WHERE post_name = \'' . \addslashes($slug) . '\' AND post_status = \'publish\';';
        $type = $this->wpdb->get_var($sql);
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
        $htaccess = \get_home_path() . ".htaccess";
        if (\file_exists($htaccess)) {
            $str = \file_get_contents($htaccess);
            if ($start = \strpos($str, '<FilesMatch "\.(eot|ttf|otf|woff)$">')) {
                if (\strpos($str, 'Header set Access-Control-Allow-Origin "*"', $start)) {
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
                echo __('In the DNS settings of your desired domain point the A and / or AAAA records at this WordPress installation.', 'multisite-landingpages');
                echo '<br/>';
                echo \sprintf(__('Add a TXT record with value: %s', 'multisite-landingpages'),
                    '<strong>' . $this->txt_record . '</strong>');
                echo '<br/>';
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
                echo __('For each domain, you can assign a ‘URL Slug’ from a page or regular post.', 'multisite-landingpages');
                echo ' ';
                echo __('When someone visits your site using the domain, they will see the assigned page or regular post.', 'multisite-landingpages');
                echo ' <em>';
                echo __('Custom post types are not yet supported.', 'multisite-landingpages');
                echo '</em><br/><strong>';
                echo __('The rest of your site keeps working as usual.', 'multisite-landingpages');
                echo '</strong></p><input type="hidden" name="ruigehond011[__delete__]"/>';
            },
            'ruigehond011'
        );
        // actual landing pages here
        $rows = $this->wpdb->get_results('SELECT domain, post_name, approved FROM ' .
            $this->table_name . ' WHERE blog_id = ' . $this->blog_id . ' ORDER BY domain;');
        foreach ($rows as $index => $row) {
            $domain = $row->domain;
            $slug = $row->post_name;
            add_settings_field(
                'ruigehond011_' . $domain,
                $domain, // title
                function ($args) {
                    $domain = $args['domain'];
                    $slug = $args['slug'];
                    $approved = \intval($args['approved']);
                    echo '<input type="text" name="ruigehond011[';
                    echo $domain;
                    echo ']" value="';
                    echo $slug;
                    echo '"/> ';
                    // delete button
                    echo '<input type="submit" class="button" value="×" data-domain="';
                    echo $domain;
                    echo '" onclick="var val = this.getAttribute(\'data-domain\');if (confirm(\'Delete \'+val+\'?\')) {var f = this.form;f[\'ruigehond011[__delete__]\'].value=val;f.submit();}else{return false;}"/> ';
                    if ($approved === 1) {
                        echo '<span class="notice-success notice">';
                        echo __('valid', 'multisite-landingpages');
                        echo '</span>';
                        if ($args['in_canonicals']) {
                            echo ' (';
                            echo __('slug loaded in canonicals', 'multisite-landingpages');
                            echo ')';
                        }
                    } elseif ($approved === 0) {
                        echo '<span class="notice-error notice">';
                        echo __('suspended, check your TXT record', 'multisite-landingpages');
                        echo '</span>';
                    } else {
                        echo '<span class="notice-warning notice" data-approved="';
                        echo $approved;
                        echo '">';
                        echo __('TXT record could not be verified', 'multisite-landingpages');
                        echo '</span>';
                    }
                },
                'ruigehond011',
                'multisite_landingpages_domains',
                [
                    'slug' => $slug,
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
                    $checked = \boolval((isset($options[$setting_name])) ? $options[$setting_name] : false);
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
            if (($warning = \get_option('ruigehond011_htaccess_warning'))) {
                if ($this->htaccessContainsLines()) { // maybe the user added the lines already by hand
                    \delete_option('ruigehond011_htaccess_warning');
                    echo '<div class="notice"><p>';
                    echo __('Warning status cleared.', 'multisite-landingpages');
                    echo '</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>';
                    echo $warning;
                    echo '</p></div>';
                }
            }
        }
    }

    /**
     * Validates settings, handles saving and deleting of the landingpage domains directly
     * @param $input
     * @return array
     * @since 0.9.0
     */
    public function settings_validate($input)
    {
        $options = (array)\get_option('ruigehond011');
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
                    // remove www
                    if (\strpos($value, 'www.') === 0) $value = \substr($value, 4);
                    // test domain utf-8 characters: όνομα.gr
                    if (\function_exists('idn_to_ascii')) {
                        $value = \idn_to_ascii($value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                    }
                    // @since 0.9.1: get the txt record, only then the domain is considered valid
                    if (\true === $this->checkTxtRecord($value, $this->txt_record)) {
                        // if the txt record exists remove any previous entries from the landingpage table
                        $this->wpdb->query('DELETE FROM ' . $this->table_name .
                            ' WHERE domain = \'' . \addslashes($value) . '\'');
                        // and insert this one into the landingpage table
                        $site_id = \get_current_network_id();
                        $this->wpdb->query('INSERT INTO ' . $this->table_name .
                            ' (domain, blog_id, site_id, post_name, txt_record, approved) VALUES(\'' .
                            \addslashes($value) . '\', ' . $this->blog_id . ',' . $site_id . ', \'\', \'' .
                            \addslashes($this->txt_record) . '\', 1)');
                        //var_dump($this->wpdb->last_query);
                        //die(' opa');
                    } else { // message the user...
                        if (\function_exists('idn_to_ascii')) {
                            \set_transient('ruigehond011_warning',
                                __('Please add the required TXT record, note that DNS propagation can take several hours', 'multisite-landingpages'));
                        } else {
                            \set_transient('ruigehond011_warning',
                                __('Please add the required TXT record, note that DNS propagation can take several hours', 'multisite-landingpages') .
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

    /**
     * @param $domain
     * @param $txt_value
     * @return bool whether the txt_value was found in the txt records for this $domain
     */
    public function checkTxtRecord($domain, $txt_value)
    {
        if (\is_array(($dns_records = \dns_get_record($domain, DNS_TXT)))) {
            // check for the record
            //var_dump($dns_records);
            //die(' opa');
            foreach ($dns_records as $index => $record) {
                if (\is_array($record) and isset($record['txt']) and $record['txt'] === $txt_value) {
                    return \true;
                }
            }
        }
        return \false;
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
        $url = \get_admin_url() . 'options-general.php?page=multisite-landingpages';
        $settings_link = '<a href="' . $url . '">' . __('Settings', 'multisite-landingpages') . '</a>';
        \array_unshift($links, $settings_link);

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

    public function cronjob()
    {
        $dns_records = $this->wpdb->get_results('SELECT domain, txt_record, approved FROM ' . $this->table_name);
        // for each record, you need to check if the txt is available, update the approved value if it changed
        foreach ($dns_records as $index => $record) {
            if (\true === $this->checkTxtRecord($record->domain, $record->txt_record)) {
                if (\intval($record->approved) !== 1) { // re-approve it / reset it
                    $this->wpdb->query('UPDATE ' . $this->table_name .
                        ' SET approved = 1 WHERE domain = \'' . \addslashes($record->domain) . '\'');
                }
            } elseif (($approved = \intval($record->approved)) > 0) {
                if ($approved === 3) { // disapprove it
                    $this->wpdb->query('UPDATE ' . $this->table_name .
                        ' SET approved = 0 WHERE domain = \'' . \addslashes($record->domain) . '\'');
                } else { // count the approved value one up
                    $approved = \intval($record->approved) + 1;
                    $this->wpdb->query('UPDATE ' . $this->table_name .
                        ' SET approved = ' . \strval($approved) . ' WHERE domain = \'' . \addslashes($record->domain) . '\'');
                }
            }
        }
    }

    /**
     * plugin management functions
     */
    public function updateWhenNecessary()
    {
        if (\version_compare($this->db_version, '0.9.1') < 0) {
            // on busy sites this can be called several times, so suppress the errors
            $this->wpdb->suppress_errors = true;
            // the txt_record added to the landingpage table
            if (\is_null($this->wpdb->get_var("SHOW COLUMNS FROM $this->table_name LIKE 'txt_record'"))) {
                $sql = 'ALTER TABLE ' . $this->table_name .
                    ' ADD COLUMN txt_record VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\' AFTER post_name;';
                if ($this->wpdb->query($sql)) {
                    // register current version, but keep incremental updates (for when someone skips a version)
                    $this->options['db_version'] = '0.9.1';
                    \update_option('ruigehond011', $this->options);
                    \set_transient('ruigehond011_warning',
                        \sprintf(__('multisite-landingpages updated database to %s', 'multisite-landingpages'), '0.9.1'));
                }
            }
            $this->wpdb->suppress_errors = false;
        }

    }

    public function activate($networkwide)
    {
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
                $warning .= __('In order for webfonts to work on alternative domains you need to have the following lines in your .htaccess', 'multisite-landingpages');
                $warning .= '<br/><em>(';
                $warning .= __('In addition you need to have mod_headers available.', 'multisite-landingpages');
                $warning .= ')</em><br/>&nbsp;<br/>';
                $warning .= '<CODE>' . \implode('<br/>', $lines) . '</CODE>';
                // report the lines to the user
                \update_option('ruigehond011_htaccess_warning', $warning);
            }
        }
        // check if the table already exists, if not create it
        if ($this->wpdb->get_var('SHOW TABLES LIKE \'' . $this->table_name . '\'') !== $this->table_name) {
            $sql = 'CREATE TABLE ' . $this->table_name . ' (
						domain VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\' NOT NULL PRIMARY KEY,
						blog_id BIGINT NOT NULL,
						site_id BIGINT NOT NULL,
						post_name VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\',
						txt_record VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\',
						date_created TIMESTAMP NOT NULL DEFAULT NOW(),
						approved TINYINT NOT NULL DEFAULT 0)
					DEFAULT CHARACTER SET = utf8mb4
					COLLATE = utf8mb4_bin;
					';
            $this->wpdb->query($sql);
        }
        // add the cron job that checks the dns txt records, if not already active
        if (\false === \wp_next_scheduled('ruigehond011_check_dns')) {
            \wp_schedule_event(time(), 'hourly', 'ruigehond011_check_dns');
        }
    }

    public function deactivate($network_deactivate)
    {
        // deactivate can be done per site or the whole network
        if (\true === $network_deactivate) { // loop through all sites
            if (\false === \wp_is_large_network()) {
                $blogs = get_sites();
                foreach ($blogs as $index => $blog) {
                    \switch_to_blog($blog->blog_id);
                    // remove active plugin (apparently this is not done automatically)
                    $plugins = \get_option('active_plugins');
                    // remove this as an active plugin: multisite-landingpages/multisite-landingpages.php
                    if (($key = array_search('multisite-landingpages/multisite-landingpages.php', $plugins)) !== false) {
                        unset($plugins[$key]);
                        \update_option('active_plugins', $plugins);
                    }
                    // remove options
                    \delete_option('ruigehond011');
                    \delete_option('ruigehond011_htaccess_warning'); // should this be present...
                    \restore_current_blog(); // NOTE restore everytime to prevent inconsistent state
                }
            }
        } else {
            // remove options and entries for this blog only
            \delete_option('ruigehond011');
            \delete_option('ruigehond011_htaccess_warning'); // should this be present...
            // remove entries in the landingpage table as well
            // not necessary for network deactivate as the table is dropped on uninstall
            if (isset($this->blog_id)) {
                $this->wpdb->query('DELETE FROM ' . $this->table_name . ' WHERE blog_id = ' . $this->blog_id . ';');
            }
        }
    }

    public function network_uninstall()
    {
        // deactivate all instances
        $this->deactivate(\true);
        // uninstall is always a network remove, so you can safely remove the proprietary table here
        if ($this->wpdb->get_var('SHOW TABLES LIKE \'' . $this->table_name . '\';') === $this->table_name) {
            $this->wpdb->query('DROP TABLE ' . $this->table_name . ';');
        }
        // forget about the cron job as well
        $timestamp = wp_next_scheduled('ruigehond011_check_dns');
        wp_unschedule_event($timestamp, 'ruigehond011_check_dns'); // also all future events are unscheduled
    }
}

function ruigehond011_uninstall()
{
    $hond = new ruigehond011();
    $hond->network_uninstall();
}

function ruigehond011_display_warning()
{
    /* Check transient, if available display it */
    if (($warning = \get_transient('ruigehond011_warning'))) {
        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo $warning;
        echo '</p></div>';
        /* Delete transient, only display this notice once. */
        \delete_transient('ruigehond011_warning');
    }
}