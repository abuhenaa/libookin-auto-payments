<?php
function create_test_stripe_accounts() {
    $test_vendors = [
        ['user_id' => 123, 'country' => 'FR', 'email' => 'vendor1@test.com'],
        ['user_id' => 124, 'country' => 'DE', 'email' => 'vendor2@test.com'],
    ];
    
    $stripe_manager = new Libookin_Stripe_Connect_Manager();
    
    foreach ($test_vendors as $vendor) {
        $result = $stripe_manager->create_connect_account(
            $vendor['user_id'], 
            $vendor['country'], 
            $vendor['email']
        );
        
        if (!is_wp_error($result)) {
            echo "Created account for vendor {$vendor['user_id']}: {$result['account_id']}\n";
        }
    }
}
//add_action('init', 'create_test_stripe_accounts');