<?php
/**
 * Stock Sync - SAP Stock to WooCommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Remenyi_SAP_Stock_Sync {

    /**
     * SAP API instance
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Remenyi_SAP_API();

        // Hook into add to cart for real-time stock check
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_stock_on_add_to_cart'), 10, 5);

        // Hook into checkout for final stock validation
        add_action('woocommerce_check_cart_items', array($this, 'validate_cart_stock'));
    }

    /**
     * Sync all stock from SAP
     */
    public function sync_all_stock() {
        Remenyi_SAP_Logger::log('stock', 'Starting stock sync');

        $items = $this->api->get_item_stock();

        if (is_wp_error($items)) {
            Remenyi_SAP_Logger::log('error', 'Failed to get stock from SAP: ' . $items->get_error_message());
            return false;
        }

        $synced = 0;
        $errors = 0;

        foreach ($items as $item) {
            $result = $this->sync_single_stock($item['ItemCode'], $item['ItemWarehouseInfoCollection']);
            if ($result) {
                $synced++;
            } else {
                $errors++;
            }
        }

        update_option('remenyi_sap_last_stock_sync', current_time('mysql'));

        Remenyi_SAP_Logger::log('stock', 'Stock sync completed', array(
            'total' => count($items),
            'synced' => $synced,
            'errors' => $errors,
        ));

        return array(
            'total' => count($items),
            'synced' => $synced,
            'errors' => $errors,
        );
    }

    /**
     * Sync stock for a single product
     */
    public function sync_single_stock($item_code, $warehouse_info) {
        $product_id = wc_get_product_id_by_sku($item_code);

        if (!$product_id) {
            return false;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        // Calculate available stock
        $available = $this->calculate_available_stock($warehouse_info);

        // Update product stock
        $product->set_stock_quantity($available);
        $product->set_stock_status($available > 0 ? 'instock' : 'outofstock');
        $product->save();

        // Save detailed stock info as meta
        $this->save_stock_details($product_id, $warehouse_info);

        Remenyi_SAP_Logger::log('debug', 'Stock updated: ' . $item_code . ' = ' . $available);

        return true;
    }

    /**
     * Calculate available stock from warehouse info
     * Available = InStock - Committed
     */
    private function calculate_available_stock($warehouse_info) {
        if (empty($warehouse_info)) {
            return 0;
        }

        $total_available = 0;

        foreach ($warehouse_info as $warehouse) {
            $in_stock = $warehouse['InStock'] ?? 0;
            $committed = $warehouse['Committed'] ?? 0;
            $available = $in_stock - $committed;

            $total_available += max(0, $available);
        }

        return $total_available;
    }

    /**
     * Save detailed stock information
     */
    private function save_stock_details($product_id, $warehouse_info) {
        $total_in_stock = 0;
        $total_ordered = 0;
        $total_committed = 0;

        foreach ($warehouse_info as $warehouse) {
            $total_in_stock += $warehouse['InStock'] ?? 0;
            $total_ordered += $warehouse['Ordered'] ?? 0;
            $total_committed += $warehouse['Committed'] ?? 0;
        }

        update_post_meta($product_id, '_sap_stock_in_stock', $total_in_stock);
        update_post_meta($product_id, '_sap_stock_ordered', $total_ordered);
        update_post_meta($product_id, '_sap_stock_committed', $total_committed);
        update_post_meta($product_id, '_sap_stock_available', $total_in_stock - $total_committed);
        update_post_meta($product_id, '_sap_stock_updated', current_time('mysql'));
    }

    /**
     * Get real-time stock from SAP for a specific item
     */
    public function get_realtime_stock($item_code) {
        $item = $this->api->get_item($item_code);

        if (is_wp_error($item)) {
            return null;
        }

        if (!isset($item['ItemWarehouseInfoCollection'])) {
            return 0;
        }

        return $this->calculate_available_stock($item['ItemWarehouseInfoCollection']);
    }

    /**
     * Validate stock when adding to cart
     */
    public function validate_stock_on_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array()) {
        if (!$passed) {
            return $passed;
        }

        // Only check if real-time stock check is enabled
        if (get_option('remenyi_sap_realtime_stock_check') !== 'yes') {
            return $passed;
        }

        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (!$product) {
            return $passed;
        }

        $sku = $product->get_sku();
        if (empty($sku)) {
            return $passed;
        }

        // Get real-time stock from SAP
        $available = $this->get_realtime_stock($sku);

        if ($available === null) {
            // Could not check stock, allow to proceed
            return $passed;
        }

        // Check if we have enough stock
        $cart_quantity = $this->get_cart_quantity($product_id);
        $total_requested = $cart_quantity + $quantity;

        if ($total_requested > $available) {
            wc_add_notice(
                sprintf(
                    __('Sajnáljuk, de a "%s" termékből csak %d db érhető el.', 'remenyi-sap-woo'),
                    $product->get_name(),
                    $available
                ),
                'error'
            );

            // Update local stock
            $this->sync_single_stock($sku, array(array('InStock' => $available, 'Committed' => 0)));

            return false;
        }

        return $passed;
    }

    /**
     * Validate cart stock before checkout
     */
    public function validate_cart_stock() {
        if (!WC()->cart) {
            return;
        }

        // Only check if real-time stock check is enabled
        if (get_option('remenyi_sap_realtime_stock_check') !== 'yes') {
            return;
        }

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $sku = $product->get_sku();

            if (empty($sku)) {
                continue;
            }

            // Get real-time stock
            $available = $this->get_realtime_stock($sku);

            if ($available === null) {
                continue;
            }

            $quantity = $cart_item['quantity'];

            if ($quantity > $available) {
                wc_add_notice(
                    sprintf(
                        __('A "%s" termékből csak %d db érhető el. Kérjük, csökkentse a mennyiséget.', 'remenyi-sap-woo'),
                        $product->get_name(),
                        $available
                    ),
                    'error'
                );

                // Update cart quantity to available amount
                if ($available > 0) {
                    WC()->cart->set_quantity($cart_item_key, $available);
                } else {
                    WC()->cart->remove_cart_item($cart_item_key);
                }
            }
        }
    }

    /**
     * Get quantity of product already in cart
     */
    private function get_cart_quantity($product_id) {
        $quantity = 0;

        if (!WC()->cart) {
            return $quantity;
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['product_id'] == $product_id) {
                $quantity += $cart_item['quantity'];
            }
        }

        return $quantity;
    }

    /**
     * Get stock status text for display
     */
    public static function get_stock_status_text($product_id) {
        $in_stock = get_post_meta($product_id, '_sap_stock_in_stock', true);
        $ordered = get_post_meta($product_id, '_sap_stock_ordered', true);
        $available = get_post_meta($product_id, '_sap_stock_available', true);

        $status = array();

        if ($available > 0) {
            $status[] = sprintf(__('Készleten: %d db', 'remenyi-sap-woo'), $available);
        } else {
            $status[] = __('Nincs készleten', 'remenyi-sap-woo');
        }

        if ($ordered > 0) {
            $status[] = sprintf(__('Beérkezés alatt: %d db', 'remenyi-sap-woo'), $ordered);
        }

        return implode(' | ', $status);
    }
}
