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
// This is plugin nr. 7 by Ruige hond. It identifies as: ruigehond011.
Define('ruigehond011_VERSION', '0.1.0');
// Register hooks for plugin management, functions are at the bottom of this file.
register_activation_hook(__FILE__, array(new ruigehond011(), 'install'));
register_deactivation_hook(__FILE__, 'ruigehond011_deactivate');
register_uninstall_hook(__FILE__, 'ruigehond011_uninstall');
// Startup the plugin
add_action('init', array(new ruigehond011(), 'initialize'));

//
class ruigehond011
{
    private $options, $options_changed, $use_canonical, $canonicals, $canonical_prefix, $remove_sitename_from_title = false;
    private $slug, $locale, $post_types = array(); // cached values

    /**
     * ruigehond011 constructor
     * loads settings that are available also based on current url
     * @since 0.1.0
     */
    public function __construct()
    {
        // get the slug we are using for this request, as far as the plugin is concerned
        global $ruigehond011;
        if (isset($ruigehond011)) {
            $this->slug = $ruigehond011->slug;
        } else { // use the regular slug
            $this->slug = \trim($_SERVER['REQUEST_URI'], '/');
        }
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
            }
            $this->remove_sitename_from_title = isset($this->options['remove_sitename']) and (true === $this->options['remove_sitename']);
        }
        // https://wordpress.stackexchange.com/a/89965
        //if (isset($this->locale)) add_filter('locale', array($this, 'getLocale'), 1, 1);
    }

    /**
     * Makes sure options are saved at the end of the request when they changed since the beginning
     * @since 1.0.0
     * @since 1.3.0: generate notice upon fail
     */
    public function __shutdown()
    {
        if ($this->options_changed === true) {
            if (false === update_option('ruigehond011', $this->options, true)) {
                trigger_error(__('Failed saving options (multisite landingpages)', 'multisite-landingpages'), E_USER_NOTICE);
            }
        }
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
        if ($index = strrpos($url, '/', -2)) { // skip over the trailing slash
            $proposed_slug = str_replace('/', '', str_replace('www-', '', substr($url, $index + 1)));
            if (isset($this->canonicals[$proposed_slug])) {
                $url = $this->canonical_prefix . $this->canonicals[$proposed_slug];
            }
        }

        return $url;
    }

    /**
     * sets $this->slug based on the domain for which we need to find a page
     * registers the current page if applicable
     * also updates $this->locale when requested
     */
    private function setSlugAndLocaleFromDomainAndRegister()
    {
        if (isset($this->slug)) return;
        $domain = $_SERVER['HTTP_HOST'];
        // strip www
        if (strpos($domain, 'www.') === 0) $domain = substr($domain, 4);
        // make slug by replacing dot with hyphen
        $slug = str_replace('.', '-', $domain);
        /**
         * And register here if applicable:
         */
        if ($this->use_canonical) {
            if (!isset($this->canonicals[$slug])) { // if not already in the options table
                $this->options['canonicals'][$slug] = $domain; // remember original domain for slug
                $this->options_changed = true; // flag for update (in __shutdown)
                $this->canonicals[$slug] = $domain; // also remember for current request
            }
        }
        $this->slug = $slug;
        // @since 1.3.0
        if (isset($this->options['locales']) and ($locales = $this->options['locales'])) {
            if (isset($locales[$slug])) $this->locale = $locales[$slug];
        }
    }

    /**
     * Expects a string where each name=>value pair is on a new row and uses = as separator, so:
     * name-one=value-one
     * etc. keys and values are trimmed and returned as a proper named array / associative array
     * @param $associative_array_as_string
     * @return array
     * @since 1.3.0
     */
    private function stringToArray($associative_array_as_string)
    {
        if (is_array($associative_array_as_string)) return $associative_array_as_string;
        $arr = explode("\n", $associative_array_as_string);
        if (count($arr) > 0) {
            $ass = array();
            foreach ($arr as $index => $str) {
                $val = explode('=', $str);
                if (count($val) === 2) {
                    $ass[trim($val[0])] = trim($val[1]);
                }
            }

            return $ass;
        } else {
            return array();
        }
    }

    /**
     * the reverse of stringToArray()
     * @param $associative_array array to be converted to string
     * @return string formatted for textarea
     * @since 1.3.0
     */
    private function arrayToString($associative_array)
    {
        $return = array();
        foreach ($associative_array as $name => $value) {
            $return[] = $name . ' = ' . $value;
        }

        return implode("\n", $return);
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
        // register a new section in the page
        add_settings_section(
            'each_domain_a_page_settings', // section id
            __('Set your options', 'multisite-landingpages'), // title
            function () {
                echo '<p>';
                echo __('This plugin matches a slug to the domain used to access your Wordpress installation and shows that page or post.', 'multisite-landingpages');
                echo '<br/><strong>';
                echo __('The rest of your site keeps working as usual.', 'multisite-landingpages');
                echo '</strong><br/><br/>';
                /* TRANSLATORS: arguments here are '.', '-', 'example-com', 'www.example.com', 'www' */
                echo sprintf(__('Typing your slug: replace %1$s (dot) with %2$s (hyphen). A page or post with slug %3$s would show for the domain %4$s (with or without the %5$s).', 'multisite-landingpages'),
                    '<strong>.</strong>', '<strong>-</strong>', '<strong>example-com</strong>', '<strong>www.example.com</strong>', 'www');
                echo ' <em>';
                echo __('Of course the domain must reach your Wordpress installation as well.', 'multisite-landingpages');
                echo '</em></p><h2>Canonicals?</h2><p><strong>';
                echo __('This plugin works out of the box.', 'multisite-landingpages');
                echo '</strong><br/>';
                echo __('However if you want your landing pages to correctly identify with the domain, you should activate the canonicals option below.', 'multisite-landingpages');
                echo ' ';
                echo __('This makes the plugin slightly slower, it will however return the domain in most cases.', 'multisite-landingpages');
                echo ' ';
                echo __('Each canonical is activated by visiting your site once using that domain.', 'multisite-landingpages');
                echo ' ';
                echo __('SEO plugins like Yoast may or may not interfere with this. If they do, you can probably set the desired canonical for your landing page there.', 'multisite-landingpages');
                echo '</p><h2>Locales?</h2><p>';
                echo sprintf(__('If the default language of this installation is ‘%s’, you can use different locales for your slugs.', 'multisite-landingpages'), 'English (United States)');
                echo ' ';
                echo __('Otherwise this is not recommended since translation files will already be loaded and using a different locale will involve loading them again.', 'multisite-landingpages');
                echo ' ';
                echo __('Use valid WordPress locales with an underscore, e.g. nl_NL, and make sure they are available in your installation.', 'multisite-landingpages');
                echo ' <em>';
                echo __('Not all locales are supported by all themes.', 'multisite-landingpages');
                echo '</em></p>';
            }, //callback
            'ruigehond011' // page
        );
        // add the settings (checkboxes)
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
                'each_domain_a_page_settings',
                [
                    'label_for' => $short_text,
                    'class' => 'ruigehond_row',
                    'options' => $this->options,
                    'option_name' => $setting_name,
                ]
            );
        }
        // blogmeta options holding domainname->slug, and the plugin needs to figure out what type of post the slug actually is
        // use wpdb->base_prefix for the table name, that way it is shared between all subsites...
        // blog_id, domain, slug, approved, date_created
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
                case 'locales':
                    $options['locales'] = $this->stringToArray($value);
                    break;
                default:
                    $options[$key] = $value;
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
        add_submenu_page(
            null, // this will hide the settings page in the "settings" menu
            'Each domain a page',
            'Each domain a page',
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
        global $wpdb; // use base prefix to make a table shared by all the blogs
        $table_name = $wpdb->base_prefix . 'ruigehond011_landingpage';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            $sql = 'CREATE TABLE ' . $table_name . ' (
						domain VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\' NOT NULL PRIMARY KEY,
						blog_id BIGINT NOT NULL,
						site_id BIGINT NOT NULL,
						post_name VARCHAR(200) CHARACTER SET \'utf8mb4\' COLLATE \'utf8mb4_unicode_520_ci\',
						date_created TIMESTAMP NOT NULL DEFAULT NOW(),
						approved TINYINT NOT NULL DEFAULT 0)
					DEFAULT CHARACTER SET = utf8mb4
					COLLATE = utf8mb4_bin;
					';
            $wpdb->query($sql);
            //to approve: date_created > DATE_SUB(now(), INTERVAL 10 MINUTE)
        }
    }
}

/**
 * proxy functions for deactivate and uninstall
 */
function ruigehond011_deactivate()
{
    // deactivate can be done per site
    // remove settings
    delete_option('ruigehond011');
    // remove entries in the landingpage table as well

}

function ruigehond011_uninstall()
{
    // uninstall is always a network remove, so you can safely remove the proprietary table here
    global $wpdb; // use base prefix to access the table shared by all the blogs
    $table_name = $wpdb->base_prefix . 'ruigehond011_landingpage';
    if ($wpdb->get_var('SHOW TABLES LIKE \'' . $table_name . '\';') === $table_name) {
        $wpdb->query('DROP TABLE ' . $table_name . ';');
    }
}

function ruigehond011_display_warning()
{
    /* Check transient, if available display it */
    if ($warning = get_transient('ruigehond011_warning')) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . $warning . '</p></div>';
        /* Delete transient, only display this notice once. */
        delete_transient('ruigehond011_warning');
        /* remember it as an option though, for the settings page as reference */
        $option = get_option('ruigehond011');
        $option['warning'] = $warning;
        update_option('ruigehond011', $option, true);
    }
}