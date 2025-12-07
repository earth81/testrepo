<?php
/**
 * Admin Settings Page for SAP Integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Remenyi_SAP_Admin_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

        // AJAX handlers
        add_action('wp_ajax_remenyi_sap_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_remenyi_sap_manual_sync', array($this, 'ajax_manual_sync'));
        add_action('wp_ajax_remenyi_sap_clear_logs', array($this, 'ajax_clear_logs'));
    }

    /**
     * Add admin menu page
     */
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            'SAP Integráció',
            'SAP Integráció',
            'manage_woocommerce',
            'remenyi-sap',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Connection settings
        register_setting('remenyi_sap_settings', 'remenyi_sap_sap_url');
        register_setting('remenyi_sap_settings', 'remenyi_sap_sap_company_db');
        register_setting('remenyi_sap_settings', 'remenyi_sap_sap_username');
        register_setting('remenyi_sap_settings', 'remenyi_sap_sap_password');

        // Sync settings
        register_setting('remenyi_sap_settings', 'remenyi_sap_sync_enabled');
        register_setting('remenyi_sap_settings', 'remenyi_sap_stock_sync_enabled');
        register_setting('remenyi_sap_settings', 'remenyi_sap_order_sync_enabled');
        register_setting('remenyi_sap_settings', 'remenyi_sap_realtime_stock_check');
        register_setting('remenyi_sap_settings', 'remenyi_sap_debug_mode');

        // Mapping settings
        register_setting('remenyi_sap_settings', 'remenyi_sap_default_shipping_type');
        register_setting('remenyi_sap_settings', 'remenyi_sap_default_payment_terms');
        register_setting('remenyi_sap_settings', 'remenyi_sap_shipping_item_code');
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_remenyi-sap') {
            return;
        }

        wp_enqueue_style(
            'remenyi-sap-admin',
            REMENYI_SAP_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            REMENYI_SAP_VERSION
        );

        wp_enqueue_script(
            'remenyi-sap-admin',
            REMENYI_SAP_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            REMENYI_SAP_VERSION,
            true
        );

        wp_localize_script('remenyi-sap-admin', 'remenyiSap', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('remenyi_sap_admin'),
        ));
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1>SAP Business One Integráció</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=remenyi-sap&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    Beállítások
                </a>
                <a href="?page=remenyi-sap&tab=sync" class="nav-tab <?php echo $active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    Szinkronizálás
                </a>
                <a href="?page=remenyi-sap&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    Naplók
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'sync':
                        $this->render_sync_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        $this->render_settings_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings tab
     */
    private function render_settings_tab() {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('remenyi_sap_settings'); ?>

            <h2>SAP Kapcsolat</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">SAP Service Layer URL</th>
                    <td>
                        <input type="url" name="remenyi_sap_sap_url" value="<?php echo esc_attr(get_option('remenyi_sap_sap_url', 'https://sap.remenyi.hu:50000')); ?>" class="regular-text" />
                        <p class="description">Például: https://sap.remenyi.hu:50000</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Vállalat adatbázis</th>
                    <td>
                        <input type="text" name="remenyi_sap_sap_company_db" value="<?php echo esc_attr(get_option('remenyi_sap_sap_company_db', 'REMENYI_TEST')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Felhasználónév</th>
                    <td>
                        <input type="text" name="remenyi_sap_sap_username" value="<?php echo esc_attr(get_option('remenyi_sap_sap_username', '')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Jelszó</th>
                    <td>
                        <input type="password" name="remenyi_sap_sap_password" value="<?php echo esc_attr(get_option('remenyi_sap_sap_password', '')); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Kapcsolat tesztelése</th>
                    <td>
                        <button type="button" class="button" id="test-sap-connection">Kapcsolat tesztelése</button>
                        <span id="connection-status"></span>
                    </td>
                </tr>
            </table>

            <h2>Szinkronizálás beállítások</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Automatikus szinkronizálás</th>
                    <td>
                        <label>
                            <input type="checkbox" name="remenyi_sap_sync_enabled" value="yes" <?php checked(get_option('remenyi_sap_sync_enabled'), 'yes'); ?> />
                            Termékek és vevők napi szinkronizálása (02:00-kor)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Készlet szinkronizálás</th>
                    <td>
                        <label>
                            <input type="checkbox" name="remenyi_sap_stock_sync_enabled" value="yes" <?php checked(get_option('remenyi_sap_stock_sync_enabled'), 'yes'); ?> />
                            Óránkénti készlet szinkronizálás
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Valós idejű készlet ellenőrzés</th>
                    <td>
                        <label>
                            <input type="checkbox" name="remenyi_sap_realtime_stock_check" value="yes" <?php checked(get_option('remenyi_sap_realtime_stock_check'), 'yes'); ?> />
                            Készlet ellenőrzés kosárba rakáskor és pénztárnál
                        </label>
                        <p class="description">Ez növeli a betöltési időt, de biztosítja a pontos készletinformációt.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Rendelés szinkronizálás</th>
                    <td>
                        <label>
                            <input type="checkbox" name="remenyi_sap_order_sync_enabled" value="yes" <?php checked(get_option('remenyi_sap_order_sync_enabled'), 'yes'); ?> />
                            Rendelések automatikus küldése az SAP-ba
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Debug mód</th>
                    <td>
                        <label>
                            <input type="checkbox" name="remenyi_sap_debug_mode" value="yes" <?php checked(get_option('remenyi_sap_debug_mode'), 'yes'); ?> />
                            Részletes naplózás engedélyezése
                        </label>
                    </td>
                </tr>
            </table>

            <h2>Alapértelmezett értékek</h2>
            <table class="form-table">
                <tr>
                    <th scope="row">Alapértelmezett szállítási mód (SAP)</th>
                    <td>
                        <input type="number" name="remenyi_sap_default_shipping_type" value="<?php echo esc_attr(get_option('remenyi_sap_default_shipping_type', '4')); ?>" class="small-text" />
                        <p class="description">SAP ShippingType kód</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Alapértelmezett fizetési feltétel (SAP)</th>
                    <td>
                        <input type="number" name="remenyi_sap_default_payment_terms" value="<?php echo esc_attr(get_option('remenyi_sap_default_payment_terms', '-1')); ?>" class="small-text" />
                        <p class="description">SAP PaymentGroupCode (-1 = nincs beállítva)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Szállítási költség cikkkód</th>
                    <td>
                        <input type="text" name="remenyi_sap_shipping_item_code" value="<?php echo esc_attr(get_option('remenyi_sap_shipping_item_code', 'SHIPPING')); ?>" class="regular-text" />
                        <p class="description">SAP cikk kód a szállítási költséghez (hagyja üresen, ha nincs)</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Beállítások mentése'); ?>
        </form>
        <?php
    }

    /**
     * Render sync tab
     */
    private function render_sync_tab() {
        $last_product_sync = get_option('remenyi_sap_last_product_sync', 'Még nem futott');
        $last_stock_sync = get_option('remenyi_sap_last_stock_sync', 'Még nem futott');
        $last_customer_sync = get_option('remenyi_sap_last_customer_sync', 'Még nem futott');
        ?>
        <h2>Szinkronizálás állapota</h2>

        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th>Típus</th>
                    <th>Utolsó futás</th>
                    <th>Művelet</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Termékek</strong></td>
                    <td><?php echo esc_html($last_product_sync); ?></td>
                    <td>
                        <button type="button" class="button manual-sync" data-type="products">Szinkronizálás most</button>
                    </td>
                </tr>
                <tr>
                    <td><strong>Készlet</strong></td>
                    <td><?php echo esc_html($last_stock_sync); ?></td>
                    <td>
                        <button type="button" class="button manual-sync" data-type="stock">Szinkronizálás most</button>
                    </td>
                </tr>
                <tr>
                    <td><strong>Vevők</strong></td>
                    <td><?php echo esc_html($last_customer_sync); ?></td>
                    <td>
                        <button type="button" class="button manual-sync" data-type="customers">Szinkronizálás most</button>
                    </td>
                </tr>
            </tbody>
        </table>

        <div id="sync-status" style="margin-top: 20px; display: none;">
            <div class="notice notice-info">
                <p><span class="spinner is-active" style="float: left; margin-top: 0;"></span> <span id="sync-message">Szinkronizálás folyamatban...</span></p>
            </div>
        </div>

        <div id="sync-result" style="margin-top: 20px; display: none;"></div>

        <h2 style="margin-top: 30px;">Ütemezett feladatok</h2>
        <?php
        $next_daily = wp_next_scheduled('remenyi_sap_daily_sync');
        $next_hourly = wp_next_scheduled('remenyi_sap_hourly_stock_sync');
        ?>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th>Feladat</th>
                    <th>Következő futás</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Napi szinkronizálás (termékek + vevők)</td>
                    <td><?php echo $next_daily ? wp_date('Y-m-d H:i:s', $next_daily) : 'Nincs ütemezve'; ?></td>
                </tr>
                <tr>
                    <td>Óránkénti készlet szinkronizálás</td>
                    <td><?php echo $next_hourly ? wp_date('Y-m-d H:i:s', $next_hourly) : 'Nincs ütemezve'; ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render logs tab
     */
    private function render_logs_tab() {
        $type_filter = isset($_GET['log_type']) ? sanitize_text_field($_GET['log_type']) : '';
        $page = isset($_GET['log_page']) ? max(1, intval($_GET['log_page'])) : 1;
        $per_page = 50;

        $logs = Remenyi_SAP_Logger::get_logs(array(
            'type' => $type_filter ?: null,
            'limit' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ));

        $total = Remenyi_SAP_Logger::get_log_count($type_filter ?: null);
        $total_pages = ceil($total / $per_page);
        ?>
        <h2>Naplóbejegyzések</h2>

        <div style="margin-bottom: 15px;">
            <form method="get" style="display: inline;">
                <input type="hidden" name="page" value="remenyi-sap" />
                <input type="hidden" name="tab" value="logs" />
                <select name="log_type">
                    <option value="">Összes típus</option>
                    <option value="info" <?php selected($type_filter, 'info'); ?>>Info</option>
                    <option value="error" <?php selected($type_filter, 'error'); ?>>Hiba</option>
                    <option value="warning" <?php selected($type_filter, 'warning'); ?>>Figyelmeztetés</option>
                    <option value="sync" <?php selected($type_filter, 'sync'); ?>>Szinkronizálás</option>
                    <option value="order" <?php selected($type_filter, 'order'); ?>>Rendelés</option>
                    <option value="stock" <?php selected($type_filter, 'stock'); ?>>Készlet</option>
                    <option value="customer" <?php selected($type_filter, 'customer'); ?>>Vevő</option>
                    <option value="product" <?php selected($type_filter, 'product'); ?>>Termék</option>
                </select>
                <button type="submit" class="button">Szűrés</button>
            </form>

            <button type="button" class="button" id="clear-logs" style="margin-left: 10px;">Naplók törlése</button>
        </div>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th style="width: 150px;">Dátum</th>
                    <th style="width: 100px;">Típus</th>
                    <th>Üzenet</th>
                    <th style="width: 50px;">Részletek</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)) : ?>
                    <tr>
                        <td colspan="4">Nincsenek naplóbejegyzések.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html($log->created_at); ?></td>
                            <td>
                                <span class="log-type log-type-<?php echo esc_attr($log->type); ?>">
                                    <?php echo esc_html($log->type); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                            <td>
                                <?php if ($log->context) : ?>
                                    <button type="button" class="button-link view-context" data-context="<?php echo esc_attr($log->context); ?>">
                                        Megtekintés
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1) : ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    $base_url = add_query_arg(array(
                        'page' => 'remenyi-sap',
                        'tab' => 'logs',
                        'log_type' => $type_filter,
                    ), admin_url('admin.php'));

                    for ($i = 1; $i <= $total_pages; $i++) {
                        $url = add_query_arg('log_page', $i, $base_url);
                        $class = $i === $page ? 'current' : '';
                        echo '<a href="' . esc_url($url) . '" class="' . $class . '">' . $i . '</a> ';
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <div id="context-modal" style="display: none;">
            <div class="context-modal-content">
                <span class="context-modal-close">&times;</span>
                <h3>Részletek</h3>
                <pre id="context-data"></pre>
            </div>
        </div>

        <style>
            .log-type {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
            .log-type-error { background: #dc3545; color: white; }
            .log-type-warning { background: #ffc107; color: black; }
            .log-type-info { background: #17a2b8; color: white; }
            .log-type-sync { background: #28a745; color: white; }
            .log-type-order { background: #6f42c1; color: white; }
            .log-type-stock { background: #fd7e14; color: white; }
            .log-type-customer { background: #20c997; color: white; }
            .log-type-product { background: #e83e8c; color: white; }
            .log-type-debug { background: #6c757d; color: white; }

            #context-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 100000;
            }
            .context-modal-content {
                background: white;
                max-width: 600px;
                margin: 100px auto;
                padding: 20px;
                border-radius: 5px;
                max-height: 70vh;
                overflow: auto;
            }
            .context-modal-close {
                float: right;
                font-size: 24px;
                cursor: pointer;
            }
            #context-data {
                background: #f5f5f5;
                padding: 15px;
                overflow: auto;
                max-height: 400px;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('.view-context').on('click', function() {
                // Use attr() instead of data() to get raw string value
                var context = $(this).attr('data-context');
                try {
                    var parsed = JSON.parse(context);
                    $('#context-data').text(JSON.stringify(parsed, null, 2));
                } catch(e) {
                    // If already an object (jQuery auto-parsed), stringify it
                    if (typeof context === 'object') {
                        $('#context-data').text(JSON.stringify(context, null, 2));
                    } else {
                        $('#context-data').text(context);
                    }
                }
                $('#context-modal').show();
            });

            $('.context-modal-close, #context-modal').on('click', function(e) {
                if (e.target === this) {
                    $('#context-modal').hide();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Test SAP connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('remenyi_sap_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nincs jogosultság');
        }

        $api = new Remenyi_SAP_API();
        $result = $api->test_connection();

        if ($result) {
            wp_send_json_success('Kapcsolat sikeres!');
        } else {
            wp_send_json_error('Kapcsolódás sikertelen. Ellenőrizze a beállításokat és a naplókat.');
        }
    }

    /**
     * AJAX: Manual sync
     */
    public function ajax_manual_sync() {
        check_ajax_referer('remenyi_sap_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nincs jogosultság');
        }

        $type = sanitize_text_field($_POST['type']);

        switch ($type) {
            case 'products':
                $sync = new Remenyi_SAP_Product_Sync();
                $result = $sync->sync_all_products();
                break;

            case 'stock':
                $sync = new Remenyi_SAP_Stock_Sync();
                $result = $sync->sync_all_stock();
                break;

            case 'customers':
                $sync = new Remenyi_SAP_Customer_Sync();
                $result = $sync->sync_all_customers();
                break;

            default:
                wp_send_json_error('Ismeretlen szinkronizálás típus');
                return;
        }

        if ($result === false) {
            wp_send_json_error('Szinkronizálás sikertelen. Ellenőrizze a naplókat.');
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('remenyi_sap_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Nincs jogosultság');
        }

        Remenyi_SAP_Logger::clear_logs();

        wp_send_json_success('Naplók törölve');
    }
}
