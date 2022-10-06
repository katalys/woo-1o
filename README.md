# woo-1o
1o to WooCommerce Merchant Plugin

Main plugin files for requests and responses are located in:
 1. **Core Plugin File**: katalys-shop/assets/inc/functions.php
 2. **GRAPHQL Requests**: katalys-shop/assets/inc/graphql-requests.php
 3. **Plugin Setup**: katalys-shop/1o-merchant-plugin.php

There should be no need to edit any other files (especially the vendor files) as these are code library files with no settings needed.

# Sending to Wordpress.org

Instructions: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/

```
# Create an SVN clone in the directory svn
svn co https://plugins.svn.wordpress.org/katalys-shop svn

# Replace the trunk folder
rm -rf svn/trunk/
cp -r trunk svn/

# Commit changes to remove SVN server
cd svn/
svn add trunk/*
svn ci -m 'release version XXXXX' --username katalysdev
# >> enter password
```