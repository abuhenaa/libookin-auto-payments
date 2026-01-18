<?php

// minimum product price check
add_action( 'woocommerce_admin_process_product_object', 'my_minimum_product_price_check', 10, 1 );
add_action( 'woocommerce_new_product', 'my_minimum_product_price_check', 10, 1 );
add_action( 'woocommerce_update_product', 'my_minimum_product_price_check', 10, 1 );

function my_minimum_product_price_check( $product ) {
    $min_price = 0.99;
    // Convert ID to WC_Product if needed
    if ( is_numeric( $product ) ) {
        $product = wc_get_product( $product );
    }

    if ( !$product instanceof WC_Product ) {
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
            ) );
        }

        // For programmatic creation (outside admin), throw error
        if ( !is_admin() ) {
            wp_die( sprintf(
                __( 'Product creation failed: The minimum allowed price is %s.', 'your-textdomain' ),
                wc_price( $min_price )
            ) );
        }

        // Force product to stay as draft instead of publishing
        $product->set_status( 'draft' );
    }
}

// Admin menu for royalty summary
add_action( 'admin_menu', 'libookin_add_royalty_summary_menu' );
function libookin_add_royalty_summary_menu() {
    add_menu_page(
        'Royalty Summary',
        'Royalty Summary',
        'manage_options',
        'libookin-royalty-summary',
        'libookin_render_royalty_summary_page',
        'dashicons-chart-bar',
        56
    );
}

// Royalty summary page
function libookin_render_royalty_summary_page() {
    global $wpdb;
    $table   = $wpdb->prefix . 'libookin_royalties';
    $charity_table = $wpdb->prefix . 'libookin_charity_earnings';
    $results = $wpdb->get_results( "SELECT r.*, c.charity_name FROM $table as r LEFT JOIN $charity_table as c ON r.order_id = c.order_id ORDER BY r.created_at DESC" );

    $total_sales  = $total_royalties  = $total_stripe_fees  = $total_net_margin  = 0;
    $monthly_data = [  ];

    echo '<div class="wrap">';
    echo '<h1>Résumé des redevances – Détail complet</h1>';
    echo '<a href="' . admin_url( 'admin.php?page=libookin-royalty-summary&libookin_export_csv=1' ) . '" class="button button-primary">Télécharger le CSV</a>';
    echo '<table class="widefat table-striped table-hover" style="margin-top:20px;"><thead><tr>
        <th>Vendeur Nom</th>
        <th>Produit</th>
        <th>Prix TTC (€)</th>
        <th>TVA (5.5%)</th>
        <th>Prix HT (€)</th>
        <th>% Droits</th>
        <th>Droits (€)</th>
        <th>Frais Stripe (€)</th>
        <th>Marge nette (€)</th>
        <th>Date</th></tr></thead><tbody>';

    $grouped_data = [];
    foreach( $results as $row ){
        $grouped_data[$row->order_id][] = $row;
    }

    foreach ( $grouped_data as $order_id => $rows ) {

        //first checking if the rows has more than one row
        $total_vendors = count( $rows );
        if( $total_vendors > 1 && !empty( $rows[0]->charity_name )  ) {

            $vendor_id = [];
            $vendors_products = [];
            $vendors_names    = [];

            foreach ( $rows as $row ) {
                //add user names
                $vendor_id[] = $row->vendor_id;
                $vendors_names[] = get_user_by( 'id', $row->vendor_id )->display_name;
                $product_id  = intval( $row->product_id );
                $vendors_products[] = get_the_title( $product_id );
                $ht          = floatval( $row->price_ht );
                $royalty     = floatval( $row->royalty_amount );
                $percent     = floatval( $row->royalty_percent );
                $vat         =  $ht * 0.055;
                $ttc         =  $ht + $vat;
                $stripe_fee  = ( $ttc * 0.014 ) + 0.25;
                $net_margin  = round( $ht - ($royalty * ($total_vendors + 1)) - $stripe_fee, 2 );
                
                
            }
            //convert vendors products to normal multi line text
            $vendor_id = implode( ",", $vendor_id );
            $vendors_products = implode( "<br>", $vendors_products );
            //inserting charity name from charity table
            $vendors_names[] = $rows[0]->charity_name . __( " (Charity)", "libookin-auto-payments" );
            $vendors_name = implode( "<br>", $vendors_names );
            $total_vendors += 1;
            $total_royalty = $royalty * $total_vendors;
            echo "<tr class='libookin-bundle-product'><td>" . __( "Bundle product", "libookin-auto-payments" ) . " <i class='dashicons dashicons-plus'></i></td></tr>";
            echo "<tr class='libookin-bundle-details'><td>{$vendors_name}</td><td>{$vendors_products}</td><td>". round( $ttc, 2 )."</td><td>{$vat}</td><td>". round( $ht, 2 )."</td><td>{$percent}% x {$total_vendors}</td><td> " . round( $total_royalty, 2 ). "</td><td>". round( $stripe_fee, 2 )."</td><td> ". round( $net_margin, 2 )."</td><td>{$row->created_at}</td></tr>";

            $total_sales += $ttc;
            $total_royalties += $royalty;
            $total_stripe_fees += $stripe_fee;
            $total_net_margin += $net_margin;
            
        }else{
            $vendor_info = $rows[0]->vendor_id;
            $vendor_user = get_userdata( $vendor_info );
            $vendor_name = $vendor_user ? $vendor_user->display_name : '';
            $product_id  = intval( $rows[0]->product_id );
            $ht          = floatval( $rows[0]->price_ht );
            $royalty     = floatval( $rows[0]->royalty_amount );
            $percent     = floatval( $rows[0]->royalty_percent );
            $vat         = $ht * 0.055;
            $ttc         = $ht + $vat;
            $stripe_fee  = ( $ttc * 0.014 ) + 0.25;
            $net_margin  = $ht - $royalty - $stripe_fee;
            $month       = date( 'Y-m', strtotime( $rows[0]->created_at ) );
            if ( !isset( $monthly_data[ $month ] ) ) {
                $monthly_data[ $month ] = [
                    'sales'     => 0,
                    'royalties' => 0,
                    'margin'    => 0,
                ];
            }
            $monthly_data[ $month ][ 'sales' ] += $ht;
            $monthly_data[ $month ][ 'royalties' ] += $royalty;
            $monthly_data[ $month ][ 'margin' ] += $net_margin;

            $total_sales += $ttc;
            $total_royalties += $royalty;
            $total_stripe_fees += $stripe_fee;
            $total_net_margin += $net_margin;
            $order_id = $rows[0]->order_id;

            echo "<tr><td>{$vendor_name}</td><td>{$product_id}</td><td>". round( $ttc, 2 )."</td><td>". round( $vat, 2 )."</td><td>". round( $ht, 2 )."</td><td>{$percent}%</td><td>". round( $royalty, 2 )."</td><td>". round( $stripe_fee, 2 )."</td><td> ". round( $net_margin, 2 )."</td><td>{$rows[0]->created_at}</td></tr>";
        }
    }

    echo '</tbody></table><h2>Résumé global</h2><table class="widefat"><tbody>';
    echo "<tr><td><strong>Total TTC</strong></td><td>€ " . number_format( ($total_sales + ($total_sales * 0.055 ) - $total_stripe_fees), 2 ) . "</td></tr>";
    echo "<tr><td><strong>Total HT</strong></td><td>€ " . number_format( ($total_sales - $total_stripe_fees), 2 ) . "</td></tr>";
    echo "<tr><td><strong>Total Droits</strong></td><td>€ " . number_format( $total_royalties, 2 ) . "</td></tr>";
    echo "<tr><td><strong>Total Stripe</strong></td><td>€ " . number_format( $total_stripe_fees, 2 ) . "</td></tr>";
    echo "<tr><td><strong>Marge nette</strong></td><td>€ " . number_format( $total_net_margin, 2 ) . "</td></tr>";
    echo '</tbody></table>';

    echo '<canvas id="royaltyChart"></canvas>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    const ctx = document.getElementById("royaltyChart").getContext("2d");
    new Chart(ctx, {
        type: "bar",
        data: {
            labels: ' . json_encode( array_keys( $monthly_data ) ) . ',
            datasets: [
                { label: "HT", backgroundColor: "#3498db", data: ' . json_encode( array_column( $monthly_data, "sales" ) ) . ' },
                { label: "Droits", backgroundColor: "#2ecc71", data: ' . json_encode( array_column( $monthly_data, "royalties" ) ) . ' },
                { label: "Marge", backgroundColor: "#e67e22", data: ' . json_encode( array_column( $monthly_data, "margin" ) ) . ' }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: "bottom" },
                title: { display: true, text: "Vue mensuelle des performances" }
            }
        }
    });
    </script>';
    echo '</div>';
}

// Add promo fields to product
add_action( 'woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_text_input( [
        'id'                => '_libookin_promo_discount',
        'label'             => 'Promo Discount (%)',
        'type'              => 'number',
        'custom_attributes' => [ 'step' => '1', 'min' => '0', 'max' => '100' ],
     ] );
    woocommerce_wp_text_input( [
        'id'    => '_libookin_promo_end_date',
        'label' => 'Promo End Date (YYYY-MM-DD)',
        'type'  => 'date',
     ] );
} );

// Save promo fields
add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
    if ( isset( $_POST[ '_libookin_promo_discount' ] ) ) {
        update_post_meta( $post_id, '_libookin_promo_discount', sanitize_text_field( $_POST[ '_libookin_promo_discount' ] ) );
    }
    if ( isset( $_POST[ '_libookin_promo_end_date' ] ) ) {
        update_post_meta( $post_id, '_libookin_promo_end_date', sanitize_text_field( $_POST[ '_libookin_promo_end_date' ] ) );
    }
} );

//export libookin royalties to CSV
add_action( 'admin_init', 'libookin_export_royalties_to_csv' );
function libookin_export_royalties_to_csv() {
    if ( isset( $_GET[ 'libookin_export_csv' ] ) && $_GET[ 'libookin_export_csv' ] == '1' ) {
        global $wpdb;
        $table   = $wpdb->prefix . 'libookin_royalties';
        $results = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC", ARRAY_A );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=libookin_royalties.csv' );
        $output = fopen( 'php://output', 'w' );

        fputcsv( $output, [ 'Vendeur', 'Vendeur Nom', 'Produit', 'Prix TTC (€)', 'TVA (5.5%)', 'Prix HT (€)', '% Droits', 'Droits (€)', 'Frais Stripe (€)', 'Marge nette (€)', 'Date' ] );

        foreach ( $results as $row ) {
            $vendor_info = $row[ 'vendor_id' ];
            $vendor_user = get_userdata( $vendor_info );
            $vendor_name = $vendor_user ? $vendor_user->display_name : '';
            $product_id  = intval( $row[ 'product_id' ] );
            $ht         = floatval( $row[ 'price_ht' ] );
            $royalty    = floatval( $row[ 'royalty_amount' ] );
            $percent    = floatval( $row[ 'royalty_percent' ] );
            $vat        = round( $ht * 0.055, 2 );
            $ttc        = round( $ht + $vat, 2 );
            $stripe_fee = round( ( $ttc * 0.014 ) + 0.25, 2 );
            $net_margin = round( $ht - $royalty - $stripe_fee, 2 );
            fputcsv( $output, [ $vendor_info, $vendor_name, $product_id, $ttc, $vat, $ht, $percent . '%', $royalty, $stripe_fee, $net_margin, $row[ 'created_at' ] ] );
        }
        fclose( $output );
        exit;
    }
}
