<?php
namespace KatalysMerchantPlugin;

/**
 * Hook to register our new routes from the controller with WordPress.
 */
add_action('rest_api_init', function() {
    $oneO_controller = new OneO_REST_DataController();
    $oneO_controller->register_routes();
});
