<?php

// minimum product price check
add_action( 'woocommerce_admin_process_product_object', 'my_minimum_product_price_check', 10, 1 );
add_action( 'woocommerce_new_product', 'my_minimum_product_price_check', 10, 1 );
add_action( 'woocommerce_update_product', 'my_minimum_product_price_check', 10, 1 );

function my_minimum_product_price_check( $product ) {
    $min_price = 2.99; // set your minimum price
    // Convert ID to WC_Product if needed
    if ( is_numeric( $product ) ) {
        $product = wc_get_product( $product );
    }

    if ( ! $product instanceof WC_Product ) {
        return; // safety check
    }
    $regular_price = floatval( $product->get_regular_price() );
    $sale_price    = floatval( $product->get_sale_price() );

    // Check both prices
    if ( ( $regular_price && $regular_price < $min_price ) || ( $sale_price && $sale_price < $min_price ) ) {
        // In admin, show error message
        if ( is_admin() ) {
            WC_Admin_Meta_Boxes::add_error( sprintf(
                __( 'Error: The minimum allowed product price is %s.', 'your-textdomain' ),
                wc_price( $min_price )
            ));
        }

        // For programmatic creation (outside admin), throw error
        if ( ! is_admin() ) {
            wp_die( sprintf(
                __( 'Product creation failed: The minimum allowed price is %s.', 'your-textdomain' ),
                wc_price( $min_price )
            ));
        }

        // Force product to stay as draft instead of publishing
        $product->set_status( 'draft' );
    }
}
