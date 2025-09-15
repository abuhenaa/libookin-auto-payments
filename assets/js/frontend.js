/**
 * Frontend JavaScript for Libookin Auto Payments
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Create Stripe Connect account
        $('#create-stripe-account').on('click', function(e) {
            e.preventDefault();
            
            const button = $(this);
            button.prop('disabled', true).text('Creating account...');
            
            $.ajax({
                url: libookin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'create_stripe_connect_account',
                    vendor_id: libookin_ajax.user_id,
                    nonce: libookin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Stripe Connect account created successfully! Please refresh the page.');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('An error occurred while creating the account.');
                },
                complete: function() {
                    button.prop('disabled', false).text('Setup Payment Account');
                }
            });
        });

        // Refresh account status
        $('#refresh-account-status').on('click', function(e) {
            e.preventDefault();
            
            const accountId = $(this).data('account-id');
            const button = $(this);
            button.prop('disabled', true).text('Refreshing...');
            
            $.ajax({
                url: libookin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_stripe_account_status',
                    account_id: accountId,
                    nonce: libookin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('An error occurred while refreshing status.');
                },
                complete: function() {
                    button.prop('disabled', false).text('Refresh Status');
                }
            });
        });

        // Auto-refresh balance every 30 seconds on royalties page
        if ($('.libookin-royalties-dashboard').length > 0) {
            setInterval(function() {
                refreshVendorBalance();
            }, 30000);
        }

        function refreshVendorBalance() {
            $.ajax({
                url: libookin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_vendor_balance',
                    nonce: libookin_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        updateBalanceDisplay(response.data);
                    }
                }
            });
        }

        function updateBalanceDisplay(data) {
            $('.balance-card.available .amount').text('€' + data.available.toFixed(2));
            $('.balance-card.pending .amount').text('€' + data.pending.toFixed(2));
            $('.balance-card.total .amount').text('€' + data.total_year.toFixed(2));
            $('.balance-card.total .description').text(data.books_sold + ' books sold');
        }
    });

})(jQuery);
