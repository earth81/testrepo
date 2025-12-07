<?php
/**
 * Plugin Name: Reményi SAP WooCommerce Integration
 * Plugin URI: https://remenyi.hu
 * Description: WooCommerce integráció SAP Business One rendszerrel a Reményi Csomagolástechnika Kft. számára
 * Version: 1.0.0
 * Author: ZiDTech
 * Author URI: https://zidtech.hu
 * License: GPL v2 or later
 * Text Domain: remenyi-sap-woo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Plugin constants
define('REMENYI_SAP_VERSION', '1.0.0');
define('REMENYI_SAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REMENYI_SAP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REMENYI_SAP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
final class Remenyi_SAP_WooCommerce {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * SAP API instance
     */
    public $api = null;

    /**
     * Get instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once REMENYI_SAP_PLUGIN_DIR . 'includes/class-sap-api.php';
        require_once REMENYI_SAP_PLUGIN_DIR . 'includes/class-sap-logger.php';
        require_once REMENYI_SAP_PLUGIN_DIR . 'includes/class-product-sync.php';
        require_once REMENYI_SAP_PLUGIN_DIR . 'includes/class-stock-sync.php';
        require_once REMENYI_SAP_PLUGIN_DIR . 'includes/class-customer-sync.php';
        require_once REMENYI_SAP_PLUGIN_DIR . 'includes/class-order-sync.php';

        // Admin
        if (is_admin()) {
            require_once REMENYI_SAP_PLUGIN_DIR . 'admin/class-admin-settings.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('plugins_loaded', array($this, 'on_plugins_loaded'));

        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Cron hooks
        add_action('remenyi_sap_daily_sync', array($this, 'run_daily_sync'));
        add_action('remenyi_sap_hourly_stock_sync', array($this, 'run_hourly_stock_sync'));
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Initialize API
        $this->api = new Remenyi_SAP_API();
    }

    /**
     * On plugins loaded
     */
    public function on_plugins_loaded() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p><strong>Reményi SAP Integration</strong> requires WooCommerce to be installed and active.</p></div>';
            });
            return;
        }

        // Initialize sync classes
        new Remenyi_SAP_Product_Sync();
        new Remenyi_SAP_Stock_Sync();
        new Remenyi_SAP_Customer_Sync();
        new Remenyi_SAP_Order_Sync();

        // Initialize admin
        if (is_admin()) {
            new Remenyi_SAP_Admin_Settings();
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create log table
        $this->create_log_table();

        // Schedule cron events
        if (!wp_next_scheduled('remenyi_sap_daily_sync')) {
            wp_schedule_event(strtotime('today 02:00'), 'daily', 'remenyi_sap_daily_sync');
        }
        if (!wp_next_scheduled('remenyi_sap_hourly_stock_sync')) {
            wp_schedule_event(time(), 'hourly', 'remenyi_sap_hourly_stock_sync');
        }

        // Set default options
        $default_options = array(
            'sap_url' => 'https://sap.remenyi.hu:50000',
            'sap_company_db' => 'REMENYI_TEST',
            'sap_username' => '',
            'sap_password' => '',
            'sync_enabled' => 'no',
            'stock_sync_enabled' => 'yes',
            'order_sync_enabled' => 'yes',
            'debug_mode' => 'no',
        );

        foreach ($default_options as $key => $value) {
            if (get_option('remenyi_sap_' . $key) === false) {
                update_option('remenyi_sap_' . $key, $value);
            }
        }

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('remenyi_sap_daily_sync');
        wp_clear_scheduled_hook('remenyi_sap_hourly_stock_sync');

        flush_rewrite_rules();
    }

    /**
     * Create log table
     */
    private function create_log_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'remenyi_sap_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            type varchar(50) NOT NULL,
            message text NOT NULL,
            context longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY type (type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Run daily sync (products and customers)
     */
    public function run_daily_sync() {
        if (get_option('remenyi_sap_sync_enabled') !== 'yes') {
            return;
        }

        $product_sync = new Remenyi_SAP_Product_Sync();
        $product_sync->sync_all_products();

        $customer_sync = new Remenyi_SAP_Customer_Sync();
        $customer_sync->sync_all_customers();

        Remenyi_SAP_Logger::log('info', 'Daily sync completed');
    }

    /**
     * Run hourly stock sync
     */
    public function run_hourly_stock_sync() {
        if (get_option('remenyi_sap_stock_sync_enabled') !== 'yes') {
            return;
        }

        $stock_sync = new Remenyi_SAP_Stock_Sync();
        $stock_sync->sync_all_stock();

        Remenyi_SAP_Logger::log('info', 'Hourly stock sync completed');
    }
}

/**
 * Get main plugin instance
 */
function remenyi_sap() {
    return Remenyi_SAP_WooCommerce::instance();
}

// Initialize plugin
remenyi_sap();
