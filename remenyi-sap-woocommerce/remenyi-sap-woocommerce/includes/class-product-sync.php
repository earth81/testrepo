<?php
/**
 * Product Sync - SAP Items to WooCommerce Products
 */

if (!defined('ABSPATH')) {
    exit;
}

class Remenyi_SAP_Product_Sync {

    /**
     * SAP API instance
     */
    private $api;

    /**
     * Hierarchy cache
     */
    private $hierarchy_cache = array();

    /**
     * User tables cache (for attribute values)
     */
    private $user_tables_cache = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Remenyi_SAP_API();
    }

    /**
     * Sync all products from SAP
     */
    public function sync_all_products($since_date = null) {
        Remenyi_SAP_Logger::log('sync', 'Starting product sync', array('since_date' => $since_date));

        // Load hierarchy first
        $this->load_hierarchy();

        // Load user tables for attribute values
        $this->load_user_tables();

        // Get items from SAP
        $items = $this->api->get_web_items($since_date);

        if (is_wp_error($items)) {
            Remenyi_SAP_Logger::log('error', 'Failed to get items from SAP: ' . $items->get_error_message());
            return false;
        }

        $synced = 0;
        $errors = 0;

        foreach ($items as $item) {
            $result = $this->sync_single_product($item);
            if ($result) {
                $synced++;
            } else {
                $errors++;
            }
        }

        Remenyi_SAP_Logger::log('sync', 'Product sync completed', array(
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
     * Sync a single product
     */
    public function sync_single_product($sap_item) {
        try {
            $item_code = $sap_item['ItemCode'];

            // Check if product exists
            $product_id = $this->get_product_by_sku($item_code);

            if ($product_id) {
                // Update existing product
                $product = wc_get_product($product_id);
            } else {
                // Create new product
                $product = new WC_Product_Simple();
            }

            // Set basic data
            $product->set_sku($item_code);
            $product->set_name($sap_item['ItemName']);

            // Set price from PriceList 1
            $price = $this->get_price_from_item($sap_item);
            if ($price !== null) {
                $product->set_regular_price($price);
            }

            // Set weight
            if (!empty($sap_item['SalesUnitWeight'])) {
                $product->set_weight($sap_item['SalesUnitWeight']);
            }

            // Set category based on hierarchy
            $hierarchy_code = !empty($sap_item['U_Webhierarchy']) ? trim($sap_item['U_Webhierarchy']) : null;
            if ($hierarchy_code) {
                Remenyi_SAP_Logger::log('debug', 'Product ' . $item_code . ' has hierarchy: "' . $hierarchy_code . '"');
                $category_id = $this->get_or_create_category($hierarchy_code);
                if ($category_id) {
                    $product->set_category_ids(array($category_id));
                    Remenyi_SAP_Logger::log('debug', 'Product ' . $item_code . ' assigned to category: ' . $category_id);
                } else {
                    Remenyi_SAP_Logger::log('warning', 'Product ' . $item_code . ' could not be assigned to category for hierarchy: ' . $hierarchy_code);
                }
            } else {
                Remenyi_SAP_Logger::log('debug', 'Product ' . $item_code . ' has no hierarchy assigned');
            }

            // Set stock management
            $product->set_manage_stock(true);
            $stock_qty = $this->get_stock_from_item($sap_item);
            $product->set_stock_quantity($stock_qty);
            $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');

            // Save product
            $product_id = $product->save();

            // Save SAP-specific meta
            $this->save_sap_meta($product_id, $sap_item);

            // Set product attributes
            $this->set_product_attributes($product_id, $sap_item);

            Remenyi_SAP_Logger::log('product', 'Product synced: ' . $item_code, array(
                'product_id' => $product_id,
                'name' => $sap_item['ItemName'],
            ));

            return true;

        } catch (Exception $e) {
            Remenyi_SAP_Logger::log('error', 'Failed to sync product: ' . $sap_item['ItemCode'], array(
                'error' => $e->getMessage(),
            ));
            return false;
        }
    }

    /**
     * Get product by SKU
     */
    private function get_product_by_sku($sku) {
        return wc_get_product_id_by_sku($sku);
    }

    /**
     * Get price from SAP item (PriceList 1)
     */
    private function get_price_from_item($sap_item) {
        if (empty($sap_item['ItemPrices'])) {
            return null;
        }

        foreach ($sap_item['ItemPrices'] as $price) {
            if ($price['PriceList'] == 1) {
                return $price['Price'];
            }
        }

        return null;
    }

    /**
     * Get stock from SAP item
     */
    private function get_stock_from_item($sap_item) {
        if (empty($sap_item['ItemWarehouseInfoCollection'])) {
            return 0;
        }

        $total_stock = 0;
        foreach ($sap_item['ItemWarehouseInfoCollection'] as $warehouse) {
            // Available = InStock - Committed
            $available = ($warehouse['InStock'] ?? 0) - ($warehouse['Committed'] ?? 0);
            $total_stock += max(0, $available);
        }

        return $total_stock;
    }

    /**
     * Save SAP-specific meta data
     */
    private function save_sap_meta($product_id, $sap_item) {
        // Save all U_ fields as meta
        foreach ($sap_item as $key => $value) {
            if (strpos($key, 'U_') === 0 && $value !== null && $value !== '') {
                update_post_meta($product_id, '_sap_' . strtolower($key), $value);
            }
        }

        // Save other important fields
        update_post_meta($product_id, '_sap_item_code', $sap_item['ItemCode']);
        update_post_meta($product_id, '_sap_update_date', $sap_item['UpdateDate'] ?? '');
        update_post_meta($product_id, '_sap_update_time', $sap_item['UpdateTime'] ?? '');
        update_post_meta($product_id, '_sap_sales_unit', $sap_item['SalesUnit'] ?? '');

        // YouTube video
        if (!empty($sap_item['U_YoutubeVideo'])) {
            update_post_meta($product_id, '_sap_youtube_video', $sap_item['U_YoutubeVideo']);
        }
    }

    /**
     * Set product attributes from SAP
     */
    private function set_product_attributes($product_id, $sap_item) {
        $attributes = array();

        // Map SAP fields to WooCommerce attributes
        $attribute_map = array(
            'U_Alapanyag' => array('name' => 'Alapanyag', 'table' => 'CPH_ALAPANYAG'),
            'U_Anyagminoseg' => array('name' => 'Anyagminőség', 'table' => 'CPH_ANYAGMINOSEG'),
            'U_Hullamtipus' => array('name' => 'Hullámtípus', 'table' => 'CPH_HULLAMTIPUS'),
            'U_Kivitel' => array('name' => 'Kivitel', 'table' => 'CPH_KIVITEL'),
            'U_ZarasTipus' => array('name' => 'Zárás típusa', 'table' => 'CPH_ZARASTIPUS'),
            'U_PantolasTipusa' => array('name' => 'Pántolás típusa', 'table' => 'CPH_PANTTIPUS'),
            'U_PantszallagTipusa' => array('name' => 'Pántszalag típusa', 'table' => 'CPH_PANTSZALAGTIP'),
            'U_PantolasIranya' => array('name' => 'Pántolás iránya', 'table' => 'CPH_PANTIRANY'),
            'U_Szin' => array('name' => 'Szín', 'table' => null),
            'U_Vastagsag_mm' => array('name' => 'Vastagság (mm)', 'table' => null),
            'U_Vastagsag_my' => array('name' => 'Vastagság (my)', 'table' => null),
            'U_BelsoMeret' => array('name' => 'Belső méret (mm)', 'table' => null),
            'U_KulsoMeret' => array('name' => 'Külső méret (mm)', 'table' => null),
            'U_SzalagSzelesseg' => array('name' => 'Szalag szélesség (mm)', 'table' => null),
            'U_KekercsHosszusag' => array('name' => 'Tekercs hosszúság (m)', 'table' => null),
            'U_FajlagosTomeg' => array('name' => 'Fajlagos tömeg (g/m2)', 'table' => null),
        );

        $position = 0;
        foreach ($attribute_map as $sap_field => $attr_config) {
            if (empty($sap_item[$sap_field])) {
                continue;
            }

            $value = $sap_item[$sap_field];

            // If linked to user table, get the Name
            if ($attr_config['table'] && isset($this->user_tables_cache[$attr_config['table']])) {
                $table_data = $this->user_tables_cache[$attr_config['table']];
                if (isset($table_data[$value])) {
                    $value = $table_data[$value];
                }
            }

            // Create attribute
            $attribute = new WC_Product_Attribute();
            $attribute->set_name($attr_config['name']);
            $attribute->set_options(array($value));
            $attribute->set_position($position);
            $attribute->set_visible(true);
            $attribute->set_variation(false);

            $attributes[] = $attribute;
            $position++;
        }

        if (!empty($attributes)) {
            $product = wc_get_product($product_id);
            if ($product) {
                $product->set_attributes($attributes);
                $product->save();
            }
        }
    }

    /**
     * Load hierarchy from SAP
     */
    private function load_hierarchy() {
        $hierarchy = $this->api->get_web_hierarchy();

        if (is_wp_error($hierarchy)) {
            Remenyi_SAP_Logger::log('error', 'Failed to load hierarchy: ' . $hierarchy->get_error_message());
            return;
        }

        Remenyi_SAP_Logger::log('debug', 'Loading hierarchy, count: ' . count($hierarchy));

        foreach ($hierarchy as $item) {
            $code = $item['Code'];
            $this->hierarchy_cache[$code] = array(
                'name' => $item['Name'],
                'level' => isset($item['U_Level']) ? intval($item['U_Level']) : 1,
                'parent' => !empty($item['U_Recipient']) ? $item['U_Recipient'] : null,
                'status' => isset($item['U_Status']) ? $item['U_Status'] : 'O',
            );

            Remenyi_SAP_Logger::log('debug', 'Hierarchy item: ' . $code . ' = ' . $item['Name'] . ' (parent: ' . ($item['U_Recipient'] ?? 'none') . ', level: ' . ($item['U_Level'] ?? '?') . ')');
        }

        Remenyi_SAP_Logger::log('info', 'Hierarchy loaded: ' . count($this->hierarchy_cache) . ' items');
    }

    /**
     * Load user tables for attribute values
     */
    private function load_user_tables() {
        $tables = array(
            'CPH_ALAPANYAG',
            'CPH_ANYAGMINOSEG',
            'CPH_HULLAMTIPUS',
            'CPH_KIVITEL',
            'CPH_ZARASTIPUS',
            'CPH_PANTTIPUS',
            'CPH_PANTSZALAGTIP',
            'CPH_PANTIRANY',
        );

        foreach ($tables as $table) {
            $data = $this->api->get_user_table($table);

            if (is_wp_error($data)) {
                continue;
            }

            $this->user_tables_cache[$table] = array();
            foreach ($data as $row) {
                $this->user_tables_cache[$table][$row['Code']] = $row['Name'];
            }
        }
    }

    /**
     * Get or create WooCommerce category from hierarchy code
     */
    private function get_or_create_category($hierarchy_code) {
        // Log incoming hierarchy code
        Remenyi_SAP_Logger::log('debug', 'Looking for hierarchy code: ' . $hierarchy_code);

        if (!isset($this->hierarchy_cache[$hierarchy_code])) {
            Remenyi_SAP_Logger::log('warning', 'Hierarchy code not found in cache: ' . $hierarchy_code . '. Available codes: ' . implode(', ', array_keys($this->hierarchy_cache)));
            return null;
        }

        $hierarchy = $this->hierarchy_cache[$hierarchy_code];

        // Skip inactive
        if ($hierarchy['status'] !== 'O') {
            Remenyi_SAP_Logger::log('debug', 'Hierarchy inactive: ' . $hierarchy_code);
            return null;
        }

        // Check if category exists by slug
        $slug = 'sap-' . $hierarchy_code;
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term) {
            Remenyi_SAP_Logger::log('debug', 'Category exists: ' . $slug . ' -> term_id=' . $term->term_id);
            return $term->term_id;
        }

        // Get parent category if exists
        $parent_id = 0;
        if (!empty($hierarchy['parent'])) {
            $parent_code = $hierarchy['parent'];
            if (isset($this->hierarchy_cache[$parent_code])) {
                $parent_id = $this->get_or_create_category($parent_code);
                Remenyi_SAP_Logger::log('debug', 'Parent category resolved: ' . $parent_code . ' -> ' . $parent_id);
            } else {
                Remenyi_SAP_Logger::log('warning', 'Parent hierarchy not found: ' . $parent_code);
            }
        }

        // Create category
        Remenyi_SAP_Logger::log('debug', 'Creating category: ' . $hierarchy['name'] . ' (slug: ' . $slug . ', parent: ' . $parent_id . ')');

        $result = wp_insert_term(
            $hierarchy['name'],
            'product_cat',
            array(
                'slug' => $slug,
                'parent' => $parent_id ?: 0,
            )
        );

        if (is_wp_error($result)) {
            // Check if term exists with different slug (by name)
            $existing = get_term_by('name', $hierarchy['name'], 'product_cat');
            if ($existing) {
                Remenyi_SAP_Logger::log('debug', 'Category already exists by name: ' . $hierarchy['name'] . ' -> term_id=' . $existing->term_id);
                return $existing->term_id;
            }

            Remenyi_SAP_Logger::log('error', 'Failed to create category: ' . $hierarchy['name'], array(
                'error' => $result->get_error_message(),
                'code' => $hierarchy_code
            ));
            return null;
        }

        Remenyi_SAP_Logger::log('info', 'Created category: ' . $hierarchy['name'] . ' (term_id: ' . $result['term_id'] . ')');

        return $result['term_id'];
    }

    /**
     * Get products that need sync (changed in SAP since last sync)
     */
    public function get_products_to_sync() {
        $last_sync = get_option('remenyi_sap_last_product_sync');
        return $this->api->get_web_items($last_sync);
    }

    /**
     * Save last sync timestamp
     */
    public function save_last_sync_time() {
        update_option('remenyi_sap_last_product_sync', date('Y-m-d'));
    }
}
