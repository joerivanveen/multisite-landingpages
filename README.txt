# multisite-landingpages
You can point a supplementary domain at your installation and have one of your subsites serve a specific landingpage for that.

## Documented working (admin)
The network administrator does not have any settings.
Of course, they can ‘network activate’ or deactivate and uninstall this plugin.

However, this plugin can only work using the ‘sunrise’ drop in structure, so a site administrator must do the following:
Copy the sunrise.php file of this plugin to the wp-content directory, or add its code to an existing sunrise.php, ensuring it does not conflict.
Set the sunrise constant in wp-config.php, somewhere below the multisite constants would be appropriate: define('SUNRISE', true);

Multisite-landingpages creates a small table holding the domain names put in by subsite admins. The domain column is the primary key so queries should run fast even with many domains.

Subsite administrators must put a TXT record in their DNS for any domain they want to add to prove they own it. This TXT record is unique for each subsite and installation (it uses the uuid4 functionality) and displayed to the administrator on the settings page.
If the TXT record is not present the domain will not be added.
A cron job runs every hour updating the entries according to the presence of their corresponding TXT records, to account for transfer of ownership.
To allow for temporary unavailability of DNS servers only after 3 tries a domain is suspended and will not work anymore.
Or, when someone else adds the domain and proves ownership, the domain is assigned to that subsite (and not visible anymore to the old subsite).

For custom fonts to work the following code must be added to .htaccess:

<IfModule mod_headers.c>
<FilesMatch "\.(eot|ttf|otf|woff)$">
Header set Access-Control-Allow-Origin "*"
</FilesMatch>
</IfModule>

The plugin will attempt to do this and warn when failed. The lines will be clearly marked by #ruigehond011 so you can find them in your .htaccess.

## Documented working (subsite)
Subsite administrators get a ‘settings’ page called ‘Landingpages’ once the plugin is active.
At the top is displayed the TXT record containing the guid they must add to the DNS records for the domains they want to add.
A domain will be added when the record is found, after that they can assign a slug, which must be of a page or a regular post type (custom post types not supported).
The plugin will match a domain name to a slug and show the page or post of that slug then. If no match occurs, the plugin has no influence.
If the ‘canonicals’ option is checked however the plugin will always actively rewrite links to any of the landingpage domains of the current subsite.

### Note about international domainnames
International domains, containing utf-8 characters, will be stored in punycode (ascii notation). Either automatically (when available) or they must be put in as such by the user. Upon failure a clear warning will be shown.