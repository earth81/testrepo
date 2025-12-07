<?php
/**
 * Order Sync - WooCommerce Orders to SAP Sales Orders
 */

if (!defined('ABSPATH')) {
    exit;
}

class Remenyi_SAP_Order_Sync {

    /**
     * SAP API instance
     */
    private $api;

    /**
     * Customer sync instance
     */
    private $customer_sync;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Remenyi_SAP_API();
        $this->customer_sync = new Remenyi_SAP_Customer_Sync();

        // Hook into order status changes
        add_action('woocommerce_order_status_processing', array($this, 'on_order_processing'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'on_order_completed'), 10, 1);

        // Hook into payment complete
        add_action('woocommerce_payment_complete', array($this, 'on_payment_complete'), 10, 1);

        // Hook for SimplePay payment ID update
        add_action('woocommerce_order_status_changed', array($this, 'on_order_status_changed'), 10, 4);

        // Add SAP order info to admin order page
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'display_sap_order_info'), 10, 1);

        // Add meta box for SAP actions
        add_action('add_meta_boxes', array($this, 'add_sap_meta_box'));

        // Handle manual sync action
        add_action('wp_ajax_remenyi_sap_sync_order', array($this, 'ajax_sync_order'));
    }

    /**
     * Order status changed to processing
     */
    public function on_order_processing($order_id) {
        if (get_option('remenyi_sap_order_sync_enabled') !== 'yes') {
            return;
        }

        $this->sync_order_to_sap($order_id);
    }

    /**
     * Order status changed to completed
     */
    public function on_order_completed($order_id) {
        // Could trigger invoice creation in SAP if needed
    }

    /**
     * Payment complete
     */
    public function on_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Update SimplePay ID if present
        $simple_pay_id = $order->get_transaction_id();
        if ($simple_pay_id) {
            $this->update_simple_pay_id($order_id, $simple_pay_id);
        }
    }

    /**
     * Order status changed - check for SimplePay transaction
     */
    public function on_order_status_changed($order_id, $old_status, $new_status, $order) {
        // Try to get SimplePay transaction ID from various meta keys
        $simple_pay_id = $order->get_transaction_id();

        if (!$simple_pay_id) {
            // Try common SimplePay meta keys
            $simple_pay_id = $order->get_meta('_simplepay_transaction_id');
        }

        if (!$simple_pay_id) {
            $simple_pay_id = $order->get_meta('_transaction_id');
        }

        if ($simple_pay_id) {
            $this->update_simple_pay_id($order_id, $simple_pay_id);
        }
    }

    /**
     * Sync order to SAP
     */
    public function sync_order_to_sap($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            Remenyi_SAP_Logger::log('error', 'Order not found: ' . $order_id);
            return false;
        }

        // Check if already synced
        $sap_doc_entry = $order->get_meta('_sap_doc_entry');
        if ($sap_doc_entry) {
            Remenyi_SAP_Logger::log('debug', 'Order already synced: ' . $order_id . ' (DocEntry: ' . $sap_doc_entry . ')');
            return $sap_doc_entry;
        }

        // Get or create customer in SAP
        $card_code = $this->get_or_create_customer($order);
        if (!$card_code) {
            Remenyi_SAP_Logger::log('error', 'Could not get/create customer for order: ' . $order_id);
            return false;
        }

        // Build order data
        $order_data = $this->build_order_data($order, $card_code);

        // Create order in SAP
        $result = $this->api->create_order($order_data);

        if (is_wp_error($result)) {
            Remenyi_SAP_Logger::log('error', 'Failed to create order in SAP: ' . $result->get_error_message(), array(
                'order_id' => $order_id,
                'order_data' => $order_data,
            ));

            // Add order note
            $order->add_order_note('SAP szinkronizálás sikertelen: ' . $result->get_error_message());

            return false;
        }

        // Save SAP order info
        $doc_entry = $result['DocEntry'] ?? null;
        $doc_num = $result['DocNum'] ?? null;

        $order->update_meta_data('_sap_doc_entry', $doc_entry);
        $order->update_meta_data('_sap_doc_num', $doc_num);
        $order->update_meta_data('_sap_synced_at', current_time('mysql'));
        $order->save();

        // Add order note
        $order->add_order_note(sprintf(
            'SAP rendelés létrehozva - DocEntry: %s, DocNum: %s',
            $doc_entry,
            $doc_num
        ));

        Remenyi_SAP_Logger::log('order', 'Order synced to SAP', array(
            'order_id' => $order_id,
            'doc_entry' => $doc_entry,
            'doc_num' => $doc_num,
        ));

        return $doc_entry;
    }

    /**
     * Get or create customer for order
     */
    private function get_or_create_customer($order) {
        $customer_id = $order->get_customer_id();

        if ($customer_id) {
            // Registered customer
            $card_code = $this->customer_sync->get_sap_card_code($customer_id);

            if (!$card_code) {
                // Create customer in SAP
                $card_code = $this->customer_sync->sync_customer_to_sap($customer_id, $order);
            }

            return $card_code;
        } else {
            // Guest customer - check if already created for this order
            $card_code = $order->get_meta('_sap_card_code');

            if (!$card_code) {
                // Try to find by email
                $email = $order->get_billing_email();
                $existing = $this->customer_sync->find_customer_by_email($email);

                if ($existing && isset($existing['CardCode'])) {
                    $card_code = $existing['CardCode'];
                    $order->update_meta_data('_sap_card_code', $card_code);
                    $order->save();
                }
            }

            return $card_code;
        }
    }

    /**
     * Build order data for SAP
     */
    private function build_order_data($order, $card_code) {
        $data = array(
            'CardCode' => $card_code,
            'DocDate' => $order->get_date_created()->format('Y-m-d'),
            'DocDueDate' => $order->get_date_created()->modify('+7 days')->format('Y-m-d'),
            'Comments' => $this->build_order_comments($order),
            'DocumentLines' => array(),
        );

        // Payment method mapping
        $payment_method = $order->get_payment_method();
        $payment_group_code = $this->map_payment_method($payment_method);
        if ($payment_group_code !== null) {
            $data['PaymentGroupCode'] = $payment_group_code;
        }

        // Shipping method mapping
        $shipping_methods = $order->get_shipping_methods();
        if (!empty($shipping_methods)) {
            $shipping_method = reset($shipping_methods);
            $transport_code = $this->map_shipping_method($shipping_method->get_method_id());
            if ($transport_code !== null) {
                $data['TransportationCode'] = $transport_code;
            }
        }

        // Get billing/shipping address codes from customer
        // For now, use defaults
        // $data['PayToCode'] = 'Számlázási cím';
        // $data['ShipToCode'] = 'Szállítási cím';

        // Add line items
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $sku = $product->get_sku();
            if (empty($sku)) {
                continue;
            }

            $line = array(
                'ItemCode' => $sku,
                'Quantity' => $item->get_quantity(),
                'UnitPrice' => $order->get_item_subtotal($item, false, false),
                'TaxCode' => $this->get_tax_code($item),
            );

            // Add discount if present
            $discount = $item->get_subtotal() - $item->get_total();
            if ($discount > 0) {
                $line['DiscountPercent'] = ($discount / $item->get_subtotal()) * 100;
            }

            $data['DocumentLines'][] = $line;
        }

        // Add shipping as a line item if needed
        $shipping_total = $order->get_shipping_total();
        if ($shipping_total > 0) {
            $shipping_item_code = get_option('remenyi_sap_shipping_item_code', 'SHIPPING');
            if ($shipping_item_code) {
                $data['DocumentLines'][] = array(
                    'ItemCode' => $shipping_item_code,
                    'Quantity' => 1,
                    'UnitPrice' => $shipping_total,
                    'TaxCode' => 'K27',
                );
            }
        }

        // Add SimplePay ID if present
        $simple_pay_id = $order->get_transaction_id();
        if ($simple_pay_id) {
            $data['U_SimpleID'] = $simple_pay_id;
        }

        // Add custom order notes/comments
        $customer_note = $order->get_customer_note();
        if ($customer_note) {
            $data['U_CustomerNote'] = substr($customer_note, 0, 254);
        }

        return $data;
    }

    /**
     * Build order comments
     */
    private function build_order_comments($order) {
        $comments = array();

        $comments[] = 'WooCommerce rendelés #' . $order->get_order_number();

        if ($order->get_customer_note()) {
            $comments[] = 'Vevő megjegyzése: ' . $order->get_customer_note();
        }

        return implode("\n", $comments);
    }

    /**
     * Map WooCommerce payment method to SAP payment terms
     */
    private function map_payment_method($payment_method) {
        $mapping = array(
            'bacs' => 1,           // Átutalás
            'cod' => 2,            // Utánvét
            'simplepay' => 1,      // SimplePay - Előre fizetés
            'stripe' => 1,         // Stripe - Előre fizetés
            'paypal' => 1,         // PayPal - Előre fizetés
        );

        // Allow custom mapping via filter
        $mapping = apply_filters('remenyi_sap_payment_method_mapping', $mapping);

        return $mapping[$payment_method] ?? get_option('remenyi_sap_default_payment_terms', -1);
    }

    /**
     * Map WooCommerce shipping method to SAP transport code
     */
    private function map_shipping_method($shipping_method) {
        $mapping = array(
            'flat_rate' => 3,
            'free_shipping' => 3,
            'local_pickup' => 1,
        );

        // Allow custom mapping via filter
        $mapping = apply_filters('remenyi_sap_shipping_method_mapping', $mapping);

        return $mapping[$shipping_method] ?? get_option('remenyi_sap_default_shipping_type', 4);
    }

    /**
     * Get SAP tax code from order item
     */
    private function get_tax_code($item) {
        $tax_class = $item->get_tax_class();

        // Default Hungarian 27% VAT
        $tax_mapping = array(
            '' => 'K27',          // Standard rate
            'reduced-rate' => 'K5',   // Reduced rate (example)
            'zero-rate' => 'K0',      // Zero rate
        );

        return $tax_mapping[$tax_class] ?? 'K27';
    }

    /**
     * Update SimplePay ID in SAP order
     */
    public function update_simple_pay_id($order_id, $simple_pay_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $doc_entry = $order->get_meta('_sap_doc_entry');
        if (!$doc_entry) {
            return false;
        }

        $result = $this->api->update_order($doc_entry, array(
            'U_SimpleID' => $simple_pay_id,
        ));

        if (is_wp_error($result)) {
            Remenyi_SAP_Logger::log('error', 'Failed to update SimplePay ID in SAP: ' . $result->get_error_message(), array(
                'order_id' => $order_id,
                'doc_entry' => $doc_entry,
            ));
            return false;
        }

        $order->update_meta_data('_sap_simple_id', $simple_pay_id);
        $order->save();

        $order->add_order_note(sprintf(
            'SAP SimplePay azonosító frissítve: %s',
            $simple_pay_id
        ));

        Remenyi_SAP_Logger::log('order', 'SimplePay ID updated in SAP', array(
            'order_id' => $order_id,
            'doc_entry' => $doc_entry,
            'simple_pay_id' => $simple_pay_id,
        ));

        return true;
    }

    /**
     * Display SAP order info on admin order page
     */
    public function display_sap_order_info($order) {
        $doc_entry = $order->get_meta('_sap_doc_entry');
        $doc_num = $order->get_meta('_sap_doc_num');
        $synced_at = $order->get_meta('_sap_synced_at');

        if (!$doc_entry) {
            echo '<p><strong>SAP státusz:</strong> <span style="color: orange;">Nincs szinkronizálva</span></p>';
            return;
        }

        echo '<div class="sap-order-info" style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-left: 4px solid #0073aa;">';
        echo '<h4 style="margin: 0 0 10px;">SAP Business One</h4>';
        echo '<p style="margin: 5px 0;"><strong>DocEntry:</strong> ' . esc_html($doc_entry) . '</p>';
        echo '<p style="margin: 5px 0;"><strong>DocNum:</strong> ' . esc_html($doc_num) . '</p>';
        echo '<p style="margin: 5px 0;"><strong>Szinkronizálva:</strong> ' . esc_html($synced_at) . '</p>';

        $simple_id = $order->get_meta('_sap_simple_id');
        if ($simple_id) {
            echo '<p style="margin: 5px 0;"><strong>SimplePay ID:</strong> ' . esc_html($simple_id) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Add SAP meta box to order page
     */
    public function add_sap_meta_box() {
        add_meta_box(
            'remenyi_sap_order_actions',
            'SAP Business One',
            array($this, 'render_sap_meta_box'),
            'shop_order',
            'side',
            'high'
        );

        // WooCommerce HPOS compatibility
        add_meta_box(
            'remenyi_sap_order_actions',
            'SAP Business One',
            array($this, 'render_sap_meta_box'),
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }

    /**
     * Render SAP meta box
     */
    public function render_sap_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) {
            return;
        }

        $doc_entry = $order->get_meta('_sap_doc_entry');
        $doc_num = $order->get_meta('_sap_doc_num');

        if ($doc_entry) {
            echo '<p><strong>DocEntry:</strong> ' . esc_html($doc_entry) . '</p>';
            echo '<p><strong>DocNum:</strong> ' . esc_html($doc_num) . '</p>';
            echo '<p style="color: green;">✓ Szinkronizálva</p>';
        } else {
            echo '<p style="color: orange;">Nincs szinkronizálva az SAP-val</p>';
            echo '<button type="button" class="button" id="remenyi-sap-sync-order" data-order-id="' . $order->get_id() . '">Szinkronizálás most</button>';
            ?>
            <script>
            jQuery(document).ready(function($) {
                $('#remenyi-sap-sync-order').on('click', function() {
                    var btn = $(this);
                    var orderId = btn.data('order-id');
                    btn.prop('disabled', true).text('Folyamatban...');

                    $.post(ajaxurl, {
                        action: 'remenyi_sap_sync_order',
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce('remenyi_sap_sync_order'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Hiba: ' + response.data);
                            btn.prop('disabled', false).text('Szinkronizálás most');
                        }
                    });
                });
            });
            </script>
            <?php
        }
    }

    /**
     * AJAX handler for manual order sync
     */
    public function ajax_sync_order() {
        check_ajax_referer('remenyi_sap_sync_order', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error('Nincs jogosultság');
        }

        $order_id = intval($_POST['order_id']);
        $result = $this->sync_order_to_sap($order_id);

        if ($result) {
            wp_send_json_success(array('doc_entry' => $result));
        } else {
            wp_send_json_error('Szinkronizálás sikertelen');
        }
    }

    /**
     * Get order preview from SAP
     */
    public function preview_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        $card_code = $this->get_or_create_customer($order);
        if (!$card_code) {
            return null;
        }

        $order_data = $this->build_order_data($order, $card_code);

        // Remove SAP-specific fields for preview
        unset($order_data['U_SimpleID']);
        unset($order_data['U_CustomerNote']);

        return $this->api->preview_order($order_data);
    }
}
