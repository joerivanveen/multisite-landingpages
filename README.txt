# multisite-landingpages
You can point a supplementary domain at your installation and have one of your subsites serve a specific landingpage for that.

## Documented working (admin)
The network administrator does not have any settings.
Of course, they can ‘network activate’ or deactivate and uninstall this plugin.

However, this plugin can only work using the ‘sunrise’ drop in structure, so a site administrator must do the following:
Copy the sunrise.php file of this plugin to the wp-content directory, or add its code to an existing sunrise.php, ensuring it does not conflict.
Set the sunrise constant in wp-config.php, somewhere below the multisite constants would be appropriate: define('SUNRISE', true);

Multisite-landingpages creates a small table holding the domain names put in by subsite admins. The domain column is the primary key so queries should run fast even with many domains.

Subsite administrators must validate an assigned domain within a set couple of minutes (default is 10), this can be configured in the code in sunrise.php.

For custom fonts to work the following code must be added to .htaccess:

<IfModule mod_headers.c>
<FilesMatch "\.(eot|ttf|otf|woff)$">
Header set Access-Control-Allow-Origin "*"
</FilesMatch>
</IfModule>

The plugin will attempt to do this and warn when failed. The lines will be clearly marked by #ruigehond011 so you can find them in your .htaccess.

## Documented working (subsite)
Subsite administrators get a ‘settings’ page called ‘Landingpages’ once the plugin is active.
They may put in domain names they have pointed the A and / or AAAA record towards the WordPress installation.
After they put it in, they can assign a slug, which must be of a page or a regular post type (custom post types not supported).
The plugin will match a domain name to a slug and show the page or post of that slug then. If no match occurs, the plugin has no influence whatsoever.

When a subsite admin puts in a domain, they must visit that domain within 10 minutes to validate. If they fail to do that the domain will be marked ‘expired’. The admin can delete it, or it will be deleted automatically the next time this WordPress installation is visited by that domain.

### Note about international domainnames
International domains, containing utf-8 characters, will be stored in punycode (ascii notation). Either automatically (when available) or they must be put in as such by the user. Upon failure a clear warning will be shown.