# Multisite landingpages

Serves a specific page or post from WordPress depending on the domain used to access your WordPress site.

## Description

This plugin has been developed for and tested with a WordPress Multisite hosting company in the U.S. They have agreed to release this plugin for free.
You may need some technical knowledge to set this up. It may also be that you need some specific compatibility or functionality, please use your local programmer to adjust this plugin or contact me.
This is the multisite version of my Each-domain-a-page plugin, for non-multisite environments Each-domain-a-page is recommended.

# Easy
For owners of subsites it is now easy to add landing pages to their sites for different domain names. They simply type in any domain name they own, and then the slug they would like to serve for that domain.
‘Multisite landingpages’ enforces a dns txt record proving ownership, this can be switched off (for the entire multisite).

# Compatibility
The plugin is specifically compatible with:
- WPMU Domain Mapping plugin (now deprecated).
- WP Rocket caching.
- Cartflows (step) post type.
- Yoast SEO plugin.

## Installation
Put the plugin in your plugins folder and follow the below instructions. If you need help or customization contact me.

## Documented working (admin)
The network administrator does not have any settings, nor do they need any.
Of course, they can ‘network activate’ or deactivate and uninstall this plugin.

The plugin uses three config settings that you can put in your wp-config file.

`define('RUIGEHOND011_TXT_RECORD_MANDATORY', true);`
default: true; When true domains can only be added if they contain a mandatory txt record, proving ownership.

`define('RUIGEHOND011_DOMAIN_MAPPING_IS_PRESENT', false);`
default: false; when true Multisite Landingpages takes into account the relevant settings in the Domain Mapping plugin (now deprecated by WPMU).

`define('RUIGEHOND011_WP_ROCKET_CACHE_DIR', '/path/to/dir/wp-content/cache/wp-rocket');`
default: not present. When present, **the dir must be valid and writable**. Multisite Landingpages will invalidate cache per domain when it can, and warn when it can’t.
When not present you must invalidate any cache yourself when you’re done changing your settings.

This plugin can only work using the ‘sunrise’ drop in structure, so a site administrator must do the following:
Copy the sunrise.php file of this plugin to the wp-content directory, or add its code to an existing sunrise.php, ensuring it does not conflict.
NOTE: currently the sunrise of domain-mapping (WPMU) is taken and this plugin is added to ensure compatibility.
Set the sunrise constant in wp-config.php, somewhere below the multisite constants would be appropriate:
`define('SUNRISE', true);`

Multisite-landingpages creates a small table holding the domain names put in by subsite admins. The domain column is the primary key so queries should run fast even with many domains.

The following is only true if the global config `ruigehond011_txt_record_mandatory = true`:
Subsite administrators must put a TXT record in their DNS for any domain they want to add to prove they own it. This TXT record is unique for each subsite and installation (it uses the uuid4 functionality) and displayed to the administrator on the settings page.
If the TXT record is not present the domain will not be added.
When the TXT record is no longer found, a warning will be displayed on the settings page next to the entry. The landing page will keep working however as long as the domain is correctly pointed at the installation.
When someone else adds the domain (while proving ownership), the domain is assigned to that subsite, and not visible anymore to the old subsite.

When `ruigehond011_txt_record_mandatory = false` admins cannot prove ownership, therefore the transfer of a domain as described in the above paragraph is NOT possible. Domains that are in the table cannot be added by another subsite.

For custom fonts to work the following code must be added to .htaccess:

```
<IfModule mod_headers.c>
<FilesMatch "\.(eot|ttf|otf|woff|woff2)$">
Header set Access-Control-Allow-Origin "*"
</FilesMatch>
</IfModule>
```
The plugin will attempt to do this and warn when failed. The lines will be clearly marked by #ruigehond011 so you can find them in your .htaccess.

## Documented working (subsite)
Subsite administrators get a ‘settings’ page called ‘Landingpages’ once the plugin is active.
At the top is displayed the TXT record containing the guid they must add to the DNS records for the domains they want to add. (Unless this is set to false in wp-config.)
A domain will be added when the record is found, after that they can assign a slug, which must be of a page or a regular post type (custom post types not supported out of the box).
The plugin will match a domain name to a slug and show the page or post of that slug then. If no match occurs, the plugin has no influence.
If the ‘canonicals’ option is checked however the plugin will always actively rewrite links to any of the landingpage domains of the current subsite.

## Note about international domainnames
International domains, containing utf-8 characters, will be stored in punycode (ascii notation). Either automatically (when available) or they must be put in as such by the user. Upon failure a warning will be shown.

## Note about deactivation
If a subsite administrator deactivates the plugin, its entries in the landingpages / domains table are removed.
On a network deactivation the table is left in the database for the admin to prune, to conserve resources. It will be dropped on uninstall.
On a network deactivation the options are removed for each subsite, as long as wp_is_large_network() returns false. For large networks, the admin should cleanup the relevant options. They are prefixed by ‘ruigehond011’.

# Test scenarios

To work alongside the (now deprecated) Domain Mapping plugin by WPMU and the WP-Rocket caching plugin, Multisite-landingpages has a few methods that influence those plugins.
If you want to test the txt record check (default true) you need to prepare at least one domain with the correct text record. The string you need is displayed on the settings page. If you deactivate and reactivate the plugin, the mandatory string changes, so don’t do that during testing (or on a production machine). However, already added domains will keep working, the check is only performed upon adding.
Note: multisite-landingpages shows only 1 page (or post) on a domain, and hence it assumes whenever that domain is visited, that one page / post is requested, as such it ignores the rest of the uri. Combined with domain mapping this can lead to a ‘bug’: if you set the same domain as Landingpage as you use elsewhere or even the main domain, WordPress can display content that is fetched based on the uri that is DIFFERENT from the slug filled in with the domain, this will make your title and canonical wrong. This is actually not a bug, but an assumption by multisite-landingpages and you should NOT put a domain as landingpage that you use elsewhere in WordPress, WordPress or the appropriate plugin where you use that domain has its own functions to deal with that.

1. Set txt record mandatory to true,
   1. Add a random domain (e.g. altavista.com) on the settings page, the plugin will display a message that the domain cannot be added.
   2. Add a domain with the correct txt record, this should be added without warning.
   3. Add a subdomain for a domain where you have the txt record in place, it should also add without warning.
   4. Add different slugs on the subdomain and the landingpage domain and surf to those landingpages confirming the content belonging to the respective slug is shown.

The following scenarios are mainly for the use case where the txt record is not mandatory, the domain mapping plugin is present and the cache dir is present as well. To prepare, add two domains using the domain mapping plugin: landingpage1.com and landingpage2.com. Use a subsite that is NOT the primary blog for all testing. After testing, repeat steps 3 and 4 with only one domain (landingpage1.com) in the domain mapping plugin.

In this document I will use the following self explanatory domain names, substitute them with the actual ones during testing. 
- test.com (the main install/blog 1)
- blogx.test.com, or test.com/blogx (the subsite we are using to test) (directory mode currently not tested)
- landingpage1.com
- landingpage2.com

You should mix the slugs using both a slug for a page and a slug for a post because the inner workings are different. Ideally you would perform steps 3 and 4 twice, first using all post slugs, second using all page slugs.

2. Check wp rocket cache dir operation:
	1. If the path is incorrect, multisite-landingpages displays a warning message on its settingspage. 
    2. If the path is correct but the dir is not writeable by the internet user in your filesystem (try chmod 555 to test), the same message is displayed. 
    3. Put in the correct path and the dir should be writeable, no message is displayed
3. Open four tabs in your browser and surf to the four urls above each in a tab and check if the standard settings are working:
    1. You have the two landingpage domains mapped in the Domain mapping plugin which is set to (default): ‘Directed to mapped (primary) domain’ for the purpose of testing, set Landingpage2.com as primary.
        1. test.com shows the main site
        2. Blogx.text.com shows the home page of the test site, under the (primary) domainname (so it REDIRECTS to landingpage2.com)
        3. Landingpage1.com shows the same content as ii
        4. Landingpage2.com shows the same content as ii
    2. Go to settings->Landingpages in your test subsite and input landingpage1.com and set a specific slug (that you will be able to recognize), surf to the original urls again using your 4 tabs
        1. Still shows the main site
        2. Still redirects to landingpage2.com
        3. Now shows the specific slug you entered
        4. Still shows the test sites homepage
    3. In settings->Landingpages in your test subsite input landingpage2.com and set a specific slug.
        1. Still shows the main site
        2. Shows the specific slug you just entered
        3. Still shows the slug from ii.
        4. Shows the same as b
    4. In your test subsite, go to Tools->domainmapping and flip the behaviour to ‘Directed to original domain’
        1. Still shows the main site
        2. Shows the homepage with original subsite url in the address bar
        3. Same as iii.
        4. Same as iii. (this is the important one to check)
    5. In settings->landingpages set different slugs (for instance swap them)
        1. Still shows the main site
        2. Same as iv.
        3. Shows the slug from iv.d now or whatever slug you just set
        4. Shows the slug from iv.c now or whatever slug you just set
    6. Swap the slugs back but add some gibberish to the Landingpages2.com slug so it does not exist in your installation
        1. Still shows the main site
        2. Same as iv.
        3. Shows the ‘correct’ slug from iv.c
        4. Shows the subsite homepage, but with the landingpage domain as address
    7. Correct the landingpages2.com slug so it is correct again, just check tab d, it should now show the contents of that slug.
    8. Go into tools->domain mapping for your subsite again and set redirect to ‘Directed to mapped (primary) domain’
        1. Still shows the main site
        2. Should redirect to d, but caching may interfere here
        3. No change
        4. No change
    9. Go to settings->landingpages and remove landingpage1.com entry
        1. Still shows the main site
        2. Redirects to langingpage2.com showing appropriate slug
        3. same as b
        4. this is landingpage2.com itself, still shows that slug
    10. in settings->landingpages remove landingpage2.com entry
        1. Still shows the main site
        2. Shows subsite homepage
        3. Shows subsite homepage
        4. Shows subsite homepage

This concludes most testing of the redirect stuff. Scenarios maybe added once incompatibilities are discovered.

4. Keep using the same tabs while testing the canonicals option
    1. Re-add landingpage1.com in settings->landingpages and set its appropriate slug, for this example we use ‘slug-landing-1’. Below, UNCHECK all the options and save settings.
        1. Main site
        2. Shows homepage under landingpage2.com
        3. Shows the appropriate slug from landingpage1.com
        4. Same as b
    2. This is just a basic test. First, in tab b. surf to a page that contains a link to your landingpage1.com page or post, we will call this page A. The link should be in the regular permalink format (e.g., because landingpage2.com is your primary domain in the domain mapping plugin: landingpage2.com/slug-landing-1). Click on it to verify that you see that page or post. Inspect elements and check that the ‘canonical’ entry shows the same uri (landingpage2.com/slug-landing-1).
    3. Go back to page A
    4. In settings->landingpages check only the box use_canonical and save the page
    5. It should display a warning that you need to ‘CLEAR CACHE’ if you want to see the changes immediately. Head over to settings->WP Rocket and do just that. (NOTE: again, during my testing this did not work, I had to manually remove the files.)
    6. In tab ii, click again on the link. The canonical property in the head should now show your landingpage1.com domain (the address still shows the old permalink, that is to avoid unnecessary redirects and good practice according to google…)
    7. Go back to page A and refresh. The original link should now point to landingpage1.com

   8. Now remove landingpage2.com from the domain mapping plugin leaving only landingpage1.com and perform the above again.


