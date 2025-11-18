/**
 * Admin JavaScript for Libookin Auto Payments
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Trigger manual payout
        $('#trigger-manual-payout').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to trigger manual payouts? This will process all eligible vendors.')) {
                return;
            }
            
            const button = $(this);
            button.prop('disabled', true).text('Processing...');
            
            $.ajax({
                url: libookin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'trigger_manual_payout',
                    nonce: libookin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Payout batch scheduled successfully for ' + response.data.vendor_count + ' vendors (€' + response.data.total_amount.toFixed(2) + ')');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('An error occurred while processing the request.');
                },
                complete: function() {
                    button.prop('disabled', false).text('Trigger Manual Payout');
                }
            });
        });

        // Get payout preview
        $('#get-payout-preview').on('click', function(e) {
            e.preventDefault();
            
            $.ajax({
                url: libookin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_payout_preview',
                    nonce: libookin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        displayPayoutPreview(response.data);
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('An error occurred while fetching preview.');
                }
            });
        });

        function displayPayoutPreview(data) {
            let html = '<h3>Payout Preview</h3>';
            html += '<p>Total vendors: ' + data.vendor_count + '</p>';
            html += '<p>Total amount: €' + data.total_amount.toFixed(2) + '</p>';
            html += '<p>Next payout date: ' + data.next_payout_date + '</p>';
            
            if (data.vendors.length > 0) {
                html += '<table class="widefat"><thead><tr>';
                html += '<th>Vendor</th><th>Email</th><th>Amount</th><th>Royalties</th>';
                html += '</tr></thead><tbody>';
                
                data.vendors.forEach(function(vendor) {
                    html += '<tr>';
                    html += '<td>' + vendor.name + '</td>';
                    html += '<td>' + vendor.email + '</td>';
                    html += '<td>€' + vendor.total_pending.toFixed(2) + '</td>';
                    html += '<td>' + vendor.royalty_count + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
            }
            
            $('#payout-preview-container').html(html);
        }
    });

    //bundle product details
    jQuery(document).ready(function($){
    $(".libookin-bundle-product").on("click", function(){
        var detailsRow = $(this).next(".libookin-bundle-details");
        $(this).find("i").toggleClass("dashicons-plus dashicons-minus");
            detailsRow.slideToggle(200);
        });
    });


})(jQuery);
