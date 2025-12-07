/**
 * Reményi SAP WooCommerce Admin JavaScript
 */

(function($) {
    'use strict';

    var RemenyiSAP = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Test connection button
            $('#test-sap-connection').on('click', this.testConnection);

            // Manual sync buttons
            $('.manual-sync').on('click', this.manualSync);

            // Clear logs button
            $('#clear-logs').on('click', this.clearLogs);

            // View context buttons
            $(document).on('click', '.view-context', this.viewContext);

            // Close modal
            $('.context-modal-close, #context-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#context-modal').hide();
                }
            });

            // ESC key to close modal
            $(document).on('keyup', function(e) {
                if (e.key === 'Escape') {
                    $('#context-modal').hide();
                }
            });
        },

        testConnection: function() {
            var $btn = $(this);
            var $status = $('#connection-status');

            $btn.prop('disabled', true).addClass('loading');
            $status.removeClass('success error').text('Tesztelés...');

            $.ajax({
                url: remenyiSap.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'remenyi_sap_test_connection',
                    nonce: remenyiSap.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.addClass('success').text('✓ ' + response.data);
                    } else {
                        $status.addClass('error').text('✗ ' + response.data);
                    }
                },
                error: function() {
                    $status.addClass('error').text('✗ Hálózati hiba');
                },
                complete: function() {
                    $btn.prop('disabled', false).removeClass('loading');
                }
            });
        },

        manualSync: function() {
            var $btn = $(this);
            var type = $btn.data('type');
            var $status = $('#sync-status');
            var $result = $('#sync-result');
            var originalText = $btn.text();

            // Confirm for large syncs
            if (type === 'products') {
                if (!confirm('A teljes termék szinkronizálás hosszabb időt vehet igénybe. Folytatja?')) {
                    return;
                }
            }

            $btn.prop('disabled', true).text('Folyamatban...');
            $status.show().find('#sync-message').text('Szinkronizálás folyamatban: ' + type + '...');
            $result.hide();

            $.ajax({
                url: remenyiSap.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'remenyi_sap_manual_sync',
                    type: type,
                    nonce: remenyiSap.nonce
                },
                timeout: 300000, // 5 minutes timeout
                success: function(response) {
                    $status.hide();

                    if (response.success) {
                        var data = response.data;
                        var html = '<div class="notice notice-success"><p>';
                        html += '<strong>Szinkronizálás sikeres!</strong><br>';
                        html += 'Összesen: ' + data.total + ' | ';
                        html += 'Sikeres: ' + data.synced + ' | ';
                        html += 'Hibás: ' + data.errors;
                        html += '</p></div>';
                        $result.html(html).show();

                        // Refresh page after 2 seconds to update timestamps
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>').show();
                    }
                },
                error: function(xhr, status, error) {
                    $status.hide();
                    var message = 'Hálózati hiba';
                    if (status === 'timeout') {
                        message = 'A kérés időtúllépés miatt megszakadt. Ellenőrizze a naplókat.';
                    }
                    $result.html('<div class="notice notice-error"><p>' + message + '</p></div>').show();
                },
                complete: function() {
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        clearLogs: function() {
            if (!confirm('Biztosan törli az összes naplóbejegyzést?')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.ajax({
                url: remenyiSap.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'remenyi_sap_clear_logs',
                    nonce: remenyiSap.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Hiba: ' + response.data);
                    }
                },
                error: function() {
                    alert('Hálózati hiba');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        },

        viewContext: function() {
            // Use attr() to get the raw value and avoid automatic parsing
            var context = $(this).attr('data-context');
            var $modal = $('#context-modal');
            var $data = $('#context-data');

            try {
                // Try to parse and pretty print JSON strings
                var parsed = JSON.parse(context);
                $data.text(JSON.stringify(parsed, null, 2));
            } catch (e) {
                // If already an object or not valid JSON, stringify safely
                if (typeof context === 'object') {
                    $data.text(JSON.stringify(context, null, 2));
                } else {
                    $data.text(context);
                }
            }

            $modal.show();
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        RemenyiSAP.init();
    });

})(jQuery);
