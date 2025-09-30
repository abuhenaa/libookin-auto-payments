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


// Remove Dokan's "Orders" tab from vendor dashboard
add_filter('dokan_get_dashboard_nav', function($urls) {
    unset($urls['orders']);
    return $urls;
});

// Admin menu for royalty summary
add_action('admin_menu', 'libookin_add_royalty_summary_menu');
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
    $table = $wpdb->prefix . 'libookin_royalties';
    $results = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

    $total_sales = $total_royalties = $total_stripe_fees = $total_net_margin = 0;
    $monthly_data = [];

    echo '<div class="wrap">';
    echo '<h1>Résumé des redevances – Détail complet</h1>';
    echo '<a href="' . admin_url('admin.php?page=libookin-royalty-summary&libookin_export_csv=1') . '" class="button button-primary">Télécharger le CSV</a>';
    echo '<table class="widefat" style="margin-top:20px;"><thead><tr>
        <th>Prix TTC (€)</th>
        <th>TVA (5.5%)</th>
        <th>Prix HT (€)</th>
        <th>% Droits</th>
        <th>Droits (€)</th>
        <th>Frais Stripe (€)</th>
        <th>Marge nette (€)</th>
        <th>Date</th></tr></thead><tbody>';

    foreach ($results as $row) {
        $ht = floatval($row->price_ht);
        $royalty = floatval($row->royalty_amount);
        $percent = floatval($row->royalty_percent);
        $vat = round($ht * 0.055, 2);
        $ttc = round($ht + $vat, 2);
        $stripe_fee = round(($ttc * 0.014) + 0.25, 2);
        $net_margin = round($ht - $royalty - $stripe_fee, 2);
        $month = date('Y-m', strtotime($row->created_at));
        if ( ! isset( $monthly_data[$month] ) ) {
            $monthly_data[$month] = [
                'sales'     => 0,
                'royalties' => 0,
                'margin'    => 0,
            ];
        }
        $monthly_data[$month]['sales'] += $ht;
        $monthly_data[$month]['royalties'] += $royalty;
        $monthly_data[$month]['margin'] += $net_margin;

        $total_sales += $ht;
        $total_royalties += $royalty;
        $total_stripe_fees += $stripe_fee;
        $total_net_margin += $net_margin;

        echo "<tr><td>{$ttc}</td><td>{$vat}</td><td>{$ht}</td><td>{$percent}%</td><td>{$royalty}</td><td>{$stripe_fee}</td><td>{$net_margin}</td><td>{$row->created_at}</td></tr>";
    }

    echo '</tbody></table><h2>Résumé global</h2><table class="widefat"><tbody>';
    echo "<tr><td><strong>Total HT</strong></td><td>€ " . number_format($total_sales, 2) . "</td></tr>";
    echo "<tr><td><strong>Total Droits</strong></td><td>€ " . number_format($total_royalties, 2) . "</td></tr>";
    echo "<tr><td><strong>Total Stripe</strong></td><td>€ " . number_format($total_stripe_fees, 2) . "</td></tr>";
    echo "<tr><td><strong>Marge nette</strong></td><td>€ " . number_format($total_net_margin, 2) . "</td></tr>";
    echo '</tbody></table>';

    echo '<canvas id="royaltyChart"></canvas>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    const ctx = document.getElementById("royaltyChart").getContext("2d");
    new Chart(ctx, {
        type: "bar",
        data: {
            labels: ' . json_encode(array_keys($monthly_data)) . ',
            datasets: [
                { label: "HT", backgroundColor: "#3498db", data: ' . json_encode(array_column($monthly_data, "sales")) . ' },
                { label: "Droits", backgroundColor: "#2ecc71", data: ' . json_encode(array_column($monthly_data, "royalties")) . ' },
                { label: "Marge", backgroundColor: "#e67e22", data: ' . json_encode(array_column($monthly_data, "margin")) . ' }
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
add_action('woocommerce_product_options_general_product_data', function() {
    woocommerce_wp_text_input([
        'id' => '_libookin_promo_discount',
        'label' => 'Promo Discount (%)',
        'type' => 'number',
        'custom_attributes' => ['step' => '1', 'min' => '0', 'max' => '100']
    ]);
    woocommerce_wp_text_input([
        'id' => '_libookin_promo_end_date',
        'label' => 'Promo End Date (YYYY-MM-DD)',
        'type' => 'date'
    ]);
});

// Save promo fields
add_action('woocommerce_process_product_meta', function($post_id) {
    if (isset($_POST['_libookin_promo_discount'])) {
        update_post_meta($post_id, '_libookin_promo_discount', sanitize_text_field($_POST['_libookin_promo_discount']));
    }
    if (isset($_POST['_libookin_promo_end_date'])) {
        update_post_meta($post_id, '_libookin_promo_end_date', sanitize_text_field($_POST['_libookin_promo_end_date']));
    }
});

