<?php
//Required for plugin update
require_once(__DIR__ . '/updates/plugin-update-checker.php');
new FCMPluginUpdateChecker_1_7('https://graphiccaffeine.com/wp-admin/admin-ajax.php', __FILE__);

// Auto update variables fpr plugin
add_filter('all_plugins', function ($plugins) {
    if (isset($plugins['1o-merchant-plugin/1o-merchant-plugin.php'])) {
        $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['update-supported'] = true;
        $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['id'] = 'gc.com/plugins/1omerchantplugin';
        $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['slug'] = '1o-merchant-plugin';
        $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['plugin'] = '1o-merchant-plugin/1o-merchant-plugin.php';
        $plugins['1o-merchant-plugin/1o-merchant-plugin.php']['url'] = 'https://graphiccaffeine.com/fcm-custom-plugins/1o-merchant-plugin/';
    }
    return $plugins;
}, 100);