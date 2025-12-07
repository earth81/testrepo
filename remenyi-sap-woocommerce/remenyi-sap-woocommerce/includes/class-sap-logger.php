<?php
/**
 * SAP Logger - Logging class for SAP integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Remenyi_SAP_Logger {

    /**
     * Log types
     */
    const TYPE_INFO = 'info';
    const TYPE_ERROR = 'error';
    const TYPE_WARNING = 'warning';
    const TYPE_DEBUG = 'debug';
    const TYPE_SYNC = 'sync';
    const TYPE_ORDER = 'order';
    const TYPE_STOCK = 'stock';
    const TYPE_CUSTOMER = 'customer';
    const TYPE_PRODUCT = 'product';

    /**
     * Log a message
     */
    public static function log($type, $message, $context = array()) {
        global $wpdb;

        // Skip debug messages if debug mode is off
        if ($type === self::TYPE_DEBUG && get_option('remenyi_sap_debug_mode') !== 'yes') {
            return;
        }

        $table_name = $wpdb->prefix . 'remenyi_sap_log';

        $wpdb->insert(
            $table_name,
            array(
                'type' => $type,
                'message' => $message,
                'context' => !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );

        // Also log to WooCommerce log if available
        if (function_exists('wc_get_logger') && get_option('remenyi_sap_debug_mode') === 'yes') {
            $logger = wc_get_logger();
            $log_context = array('source' => 'remenyi-sap');

            switch ($type) {
                case self::TYPE_ERROR:
                    $logger->error($message, $log_context);
                    break;
                case self::TYPE_WARNING:
                    $logger->warning($message, $log_context);
                    break;
                case self::TYPE_DEBUG:
                    $logger->debug($message, $log_context);
                    break;
                default:
                    $logger->info($message, $log_context);
            }
        }
    }

    /**
     * Get logs
     */
    public static function get_logs($args = array()) {
        global $wpdb;

        $defaults = array(
            'type' => null,
            'limit' => 100,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'date_from' => null,
            'date_to' => null,
            'search' => null,
        );

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . 'remenyi_sap_log';

        $where = array('1=1');
        $values = array();

        if ($args['type']) {
            $where[] = 'type = %s';
            $values[] = $args['type'];
        }

        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'];
        }

        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'];
        }

        if ($args['search']) {
            $where[] = 'message LIKE %s';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_clause = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        $limit = intval($args['limit']);
        $offset = intval($args['offset']);

        $sql = "SELECT * FROM $table_name WHERE $where_clause ORDER BY $orderby LIMIT $limit OFFSET $offset";

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get log count
     */
    public static function get_log_count($type = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'remenyi_sap_log';

        if ($type) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE type = %s",
                $type
            ));
        }

        return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }

    /**
     * Clear logs
     */
    public static function clear_logs($type = null, $days_old = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'remenyi_sap_log';

        $where = array();
        $values = array();

        if ($type) {
            $where[] = 'type = %s';
            $values[] = $type;
        }

        if ($days_old) {
            $where[] = 'created_at < %s';
            $values[] = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));
        }

        if (empty($where)) {
            return $wpdb->query("TRUNCATE TABLE $table_name");
        }

        $where_clause = implode(' AND ', $where);
        $sql = "DELETE FROM $table_name WHERE $where_clause";

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->query($sql);
    }

    /**
     * Clean old logs (keeps last 30 days)
     */
    public static function cleanup() {
        self::clear_logs(null, 30);
    }
}
