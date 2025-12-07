<?php
/**
 * SAP Business One Service Layer API Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Remenyi_SAP_API {

    /**
     * API Base URL
     */
    private $base_url;

    /**
     * Company DB
     */
    private $company_db;

    /**
     * Username
     */
    private $username;

    /**
     * Password
     */
    private $password;

    /**
     * Session ID
     */
    private $session_id = null;

    /**
     * Session expiry timestamp
     */
    private $session_expiry = null;

    /**
     * Cookies for session
     */
    private $cookies = array();

    /**
     * Constructor
     */
    public function __construct() {
        $this->base_url = rtrim(get_option('remenyi_sap_sap_url', 'https://sap.remenyi.hu:50000'), '/');
        $this->company_db = get_option('remenyi_sap_sap_company_db', 'REMENYI_TEST');
        $this->username = get_option('remenyi_sap_sap_username', '');
        $this->password = get_option('remenyi_sap_sap_password', '');

        // Restore session from transient
        $this->restore_session();
    }

    /**
     * Restore session from transient
     */
    private function restore_session() {
        $session_data = get_transient('remenyi_sap_session');
        if ($session_data) {
            $this->session_id = $session_data['session_id'];
            $this->session_expiry = $session_data['expiry'];
            $this->cookies = $session_data['cookies'];
        }
    }

    /**
     * Save session to transient
     */
    private function save_session() {
        $session_data = array(
            'session_id' => $this->session_id,
            'expiry' => $this->session_expiry,
            'cookies' => $this->cookies,
        );
        // Session valid for 25 minutes (SAP session is 30 min)
        set_transient('remenyi_sap_session', $session_data, 25 * MINUTE_IN_SECONDS);
    }

    /**
     * Check if session is valid
     */
    private function is_session_valid() {
        if (empty($this->session_id) || empty($this->session_expiry)) {
            return false;
        }
        // Check if session is about to expire (5 minute buffer)
        return time() < ($this->session_expiry - 300);
    }

    /**
     * Login to SAP
     */
    public function login() {
        $url = $this->base_url . '/b1s/v2/Login';

        $body = array(
            'CompanyDB' => $this->company_db,
            'UserName' => $this->username,
            'Password' => $this->password,
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($body),
            'timeout' => 30,
            'sslverify' => false, // Self-signed cert
        ));

        if (is_wp_error($response)) {
            Remenyi_SAP_Logger::log('error', 'SAP Login failed: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            Remenyi_SAP_Logger::log('error', 'SAP Login failed with status ' . $status_code, array(
                'response' => $response_body
            ));
            return false;
        }

        // Extract cookies from response
        $cookies = wp_remote_retrieve_cookies($response);
        $this->cookies = array();
        foreach ($cookies as $cookie) {
            $this->cookies[$cookie->name] = $cookie->value;
            if ($cookie->name === 'B1SESSION') {
                $this->session_id = $cookie->value;
            }
        }

        // Set session expiry to 30 minutes from now
        $this->session_expiry = time() + (30 * 60);

        // Save session
        $this->save_session();

        Remenyi_SAP_Logger::log('info', 'SAP Login successful');

        return true;
    }

    /**
     * Logout from SAP
     */
    public function logout() {
        if (!$this->session_id) {
            return true;
        }

        $url = $this->base_url . '/b1s/v2/Logout';

        $response = wp_remote_post($url, array(
            'headers' => $this->get_auth_headers(),
            'timeout' => 30,
            'sslverify' => false,
        ));

        // Clear session
        $this->session_id = null;
        $this->session_expiry = null;
        $this->cookies = array();
        delete_transient('remenyi_sap_session');

        return true;
    }

    /**
     * Ensure we have a valid session
     */
    private function ensure_session() {
        if (!$this->is_session_valid()) {
            return $this->login();
        }
        return true;
    }

    /**
     * Get auth headers with cookies
     */
    private function get_auth_headers() {
        $headers = array(
            'Content-Type' => 'application/json',
        );

        if (!empty($this->cookies)) {
            $cookie_string = '';
            foreach ($this->cookies as $name => $value) {
                $cookie_string .= $name . '=' . $value . '; ';
            }
            $headers['Cookie'] = rtrim($cookie_string, '; ');
        }

        return $headers;
    }

    /**
     * Build OData query string (preserves $ in parameter names)
     */
    private function build_odata_query($params) {
        $parts = array();
        foreach ($params as $key => $value) {
            // Key should not be encoded (keep $ as is), value should be encoded
            // but we need to be careful with OData special chars
            $encoded_value = str_replace(
                array('%27', '%20'),
                array("'", '%20'),
                rawurlencode($value)
            );
            $parts[] = $key . '=' . $encoded_value;
        }
        $query = implode('&', $parts);

        // Log the actual URL being built for debugging
        Remenyi_SAP_Logger::log('debug', 'OData query built: ' . $query);

        return $query;
    }

    /**
     * Make API request
     */
    public function request($endpoint, $method = 'GET', $body = null, $params = array()) {
        if (!$this->ensure_session()) {
            return new WP_Error('sap_auth_failed', 'Could not authenticate with SAP');
        }

        // Build URL with params - use custom query string builder for OData
        $url = $this->base_url . '/b1s/v2/' . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url .= '?' . $this->build_odata_query($params);
        }

        // Log the full URL for debugging
        Remenyi_SAP_Logger::log('debug', 'SAP API Request: ' . $method . ' ' . $url);

        $args = array(
            'method' => $method,
            'headers' => $this->get_auth_headers(),
            'timeout' => 60,
            'sslverify' => false,
        );

        if ($body !== null && in_array($method, array('POST', 'PATCH', 'PUT'))) {
            $args['body'] = json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            Remenyi_SAP_Logger::log('error', 'SAP API request failed: ' . $response->get_error_message(), array(
                'endpoint' => $endpoint,
                'method' => $method,
            ));
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        // Handle 401 - session expired
        if ($status_code === 401) {
            $this->session_id = null;
            $this->session_expiry = null;
            delete_transient('remenyi_sap_session');

            // Retry once
            if ($this->login()) {
                return $this->request($endpoint, $method, $body, $params);
            }
            return new WP_Error('sap_auth_expired', 'SAP session expired and could not re-authenticate');
        }

        // Parse JSON response
        $data = json_decode($response_body, true);

        if ($status_code >= 400) {
            $error_message = isset($data['error']['message']['value']) ? $data['error']['message']['value'] : 'Unknown error';
            Remenyi_SAP_Logger::log('error', 'SAP API error: ' . $error_message, array(
                'endpoint' => $endpoint,
                'method' => $method,
                'status' => $status_code,
                'response' => $response_body,
            ));
            return new WP_Error('sap_api_error', $error_message, array('status' => $status_code));
        }

        return $data;
    }

    /**
     * GET request
     */
    public function get($endpoint, $params = array()) {
        return $this->request($endpoint, 'GET', null, $params);
    }

    /**
     * POST request
     */
    public function post($endpoint, $body = array()) {
        return $this->request($endpoint, 'POST', $body);
    }

    /**
     * PATCH request
     */
    public function patch($endpoint, $body = array()) {
        return $this->request($endpoint, 'PATCH', $body);
    }

    /**
     * PUT request
     */
    public function put($endpoint, $body = array()) {
        return $this->request($endpoint, 'PUT', $body);
    }

    /**
     * Get all results with pagination (SAP uses $skip and $top)
     */
    public function get_all($endpoint, $params = array(), $limit = 10000) {
        $all_results = array();
        $skip = 0;
        $top = 500; // Batch size - SAP Service Layer can handle up to 500

        Remenyi_SAP_Logger::log('debug', 'Starting paginated fetch: ' . $endpoint);

        $next_endpoint = $endpoint;
        $next_params = $params;

        do {
            // Apply paging for the current batch if no nextLink override is present
            if (!isset($next_params['$skip'])) {
                $next_params['$skip'] = strval($skip);
            }
            if (!isset($next_params['$top'])) {
                $next_params['$top'] = strval($top);
            }

            $response = $this->get($next_endpoint, $next_params);

            if (is_wp_error($response)) {
                Remenyi_SAP_Logger::log('error', 'Pagination error at skip=' . $skip, array(
                    'endpoint' => $next_endpoint,
                    'params' => $next_params,
                    'error' => $response->get_error_message()
                ));
                return $response;
            }

            $items = isset($response['value']) ? $response['value'] : array();
            $count = count($items);
            $all_results = array_merge($all_results, $items);

            Remenyi_SAP_Logger::log('debug', 'Fetched batch: skip=' . $skip . ', got=' . $count . ', total=' . count($all_results));

            // Detect OData nextLink for continued pagination (covers @odata.nextLink and odata.nextLink keys)
            $next_link = null;
            if (isset($response['@odata.nextLink'])) {
                $next_link = $response['@odata.nextLink'];
            } elseif (isset($response['odata.nextLink'])) {
                $next_link = $response['odata.nextLink'];
            }

            if ($next_link && count($all_results) < $limit) {
                Remenyi_SAP_Logger::log('debug', 'Following nextLink: ' . $next_link);

                // Normalize nextLink to an endpoint usable by request()
                $next_link_path = $next_link;
                $base_marker = '/b1s/v2/';
                if (strpos($next_link_path, $base_marker) !== false) {
                    $next_link_path = substr($next_link_path, strpos($next_link_path, $base_marker) + strlen($base_marker));
                }

                // When using nextLink, params are already included in the URL
                $next_endpoint = ltrim($next_link_path, '/');
                $next_params = array();
                $has_more = true;
            } else {
                $skip += $top;
                $has_more = $count === $top && $skip < $limit;
                $next_endpoint = $endpoint;
                $next_params = $params;
            }

        } while ($has_more);

        Remenyi_SAP_Logger::log('debug', 'Pagination complete: total=' . count($all_results));

        return $all_results;
    }

    // ===========================================
    // SPECIFIC API METHODS
    // ===========================================

    /**
     * Get items (products)
     */
    public function get_items($select = null, $filter = null, $orderby = null) {
        $params = array();

        if ($select) {
            $params['$select'] = $select;
        }
        if ($filter) {
            $params['$filter'] = $filter;
        }
        if ($orderby) {
            $params['$orderby'] = $orderby;
        }

        return $this->get_all('Items', $params);
    }

    /**
     * Get single item
     */
    public function get_item($item_code) {
        return $this->get("Items('" . urlencode($item_code) . "')");
    }

    /**
     * Get web items with all attributes
     */
    public function get_web_items($since_date = null) {
        $select = 'ItemCode,ItemName,SalesUnit,SalesUnitWeight,UpdateDate,UpdateTime,U_Webhierarchy,' .
                  'U_AlagutMerete,U_Alapanyag,U_Anyagminoseg,U_AsztalMagassag,U_BelsoMeret,' .
                  'U_BemelegedesiIdo,U_Benyulas,U_CseveBelsAtmero,U_CseveSuly,U_ElnyujtasMerteke,' .
                  'U_Energiafelhaszn,U_FajlagosTomeg,U_Feszitoero,U_GepMerete,U_HegesztesiIdo,' .
                  'U_HegVarratSzellesseg,U_HegesztFoiaVastagsag,SalesLengthUnit,U_Hullamtipus,' .
                  'U_KapocsMerete,U_Kivitel,U_KulsoMeret,U_KisebbMagassag,SalesHeightUnit,' .
                  'U_MaxTekercsAtmero,U_MaxCsomagMeret,U_Nyomas,U_PantolasErossege,U_PantolasIranya,' .
                  'U_PantolasSebessege,U_PantolasTipusa,U_PantszallagTipusa,U_PantszallagVastagsag,' .
                  'U_RagasztorudAtmero,U_Sebesseg,U_Szakitoszilardsag,U_SzalagSzelesseg,SalesUnitWidth,' .
                  'U_Szin,U_KekercsHosszusag,U_Vastagsag_mm,U_Vastagsag_my,U_YoutubeVideo,U_ZarasTipus,' .
                  'ItemPrices,ItemWarehouseInfoCollection';

        $filter = "U_MOS_InSe eq 'Y'";
        if ($since_date) {
            $filter .= " and UpdateDate ge '" . $since_date . "T00:00:00'";
        }

        return $this->get_items($select, $filter, 'ItemCode');
    }

    /**
     * Get item stock info
     */
    public function get_item_stock() {
        $select = 'ItemCode,ItemWarehouseInfoCollection';
        $filter = "U_MOS_InSe eq 'Y'";

        return $this->get_items($select, $filter);
    }

    /**
     * Get web hierarchy
     */
    public function get_web_hierarchy() {
        return $this->get_all('WEBHIERARCHY');
    }

    /**
     * Get business partners (customers)
     */
    public function get_customers($since_date = null) {
        $filter = "CardType eq 'cCustomer'";
        if ($since_date) {
            $filter .= " and UpdateDate ge '" . $since_date . "T00:00:00'";
        }

        return $this->get_all('BusinessPartners', array('$filter' => $filter));
    }

    /**
     * Get customer by card code
     */
    public function get_customer($card_code) {
        return $this->get("BusinessPartners('" . urlencode($card_code) . "')");
    }

    /**
     * Get customer by tax ID
     */
    public function get_customer_by_tax_id($tax_id) {
        $params = array(
            '$select' => 'CardCode',
            '$filter' => "UnifiedFederalTaxID eq '" . $tax_id . "'",
        );

        $result = $this->get('BusinessPartners', $params);

        if (is_wp_error($result)) {
            return $result;
        }

        if (isset($result['value']) && !empty($result['value'])) {
            return $result['value'][0];
        }

        return null;
    }

    /**
     * Get customer by email
     */
    public function get_customer_by_email($email) {
        $params = array(
            '$filter' => "E_MailL eq '" . $email . "'",
            '$select' => 'CardCode',
        );

        $result = $this->get('view.svc/CPH_TargyalopartnermailB1SLQuery', $params);

        if (is_wp_error($result)) {
            return $result;
        }

        if (isset($result['value']) && !empty($result['value'])) {
            return $result['value'][0];
        }

        return null;
    }

    /**
     * Create new customer
     */
    public function create_customer($data) {
        return $this->post('BusinessPartners', $data);
    }

    /**
     * Update customer
     */
    public function update_customer($card_code, $data) {
        return $this->patch("BusinessPartners('" . urlencode($card_code) . "')", $data);
    }

    /**
     * Create order
     */
    public function create_order($data) {
        return $this->post('Orders', $data);
    }

    /**
     * Update order
     */
    public function update_order($doc_entry, $data) {
        return $this->patch("Orders(" . intval($doc_entry) . ")", $data);
    }

    /**
     * Get order
     */
    public function get_order($doc_entry) {
        return $this->get("Orders(" . intval($doc_entry) . ")");
    }

    /**
     * Get orders by customer
     */
    public function get_orders_by_customer($card_code) {
        $params = array(
            '$filter' => "CardCode eq '" . $card_code . "'",
        );

        return $this->get_all('Orders', $params);
    }

    /**
     * Preview order (without creating)
     */
    public function preview_order($data) {
        return $this->post('OrdersService_Preview', array('Document' => $data));
    }

    /**
     * Get countries
     */
    public function get_countries() {
        $params = array(
            '$select' => 'Code,Name',
        );

        return $this->get_all('Countries', $params);
    }

    /**
     * Get payment terms
     */
    public function get_payment_terms() {
        $params = array(
            '$select' => 'GroupNumber,PaymentTermsGroupName',
        );

        return $this->get_all('PaymentTermsTypes', $params);
    }

    /**
     * Get shipping types
     */
    public function get_shipping_types() {
        $params = array(
            '$select' => 'Code,Name',
        );

        return $this->get_all('ShippingTypes', $params);
    }

    /**
     * Get user-defined table data
     */
    public function get_user_table($table_name) {
        return $this->get_all('U_' . $table_name);
    }

    /**
     * Export PDF (Crystal Report)
     */
    public function export_pdf($doc_code, $doc_key, $object_id) {
        $body = array(
            array(
                'name' => 'DocKey@',
                'type' => 'xsd:decimal',
                'value' => array(array($doc_key)),
            ),
            array(
                'name' => 'ObjectId@',
                'type' => 'xsd:decimal',
                'value' => array(array($object_id)),
            ),
        );

        return $this->post('ExportPDFData?DocCode=' . urlencode($doc_code), $body);
    }

    /**
     * Get item price for customer
     */
    public function get_customer_item_price($card_code, $item_code, $quantity = 1) {
        $body = array(
            'ItemPriceParams' => array(
                'CardCode' => $card_code,
                'ItemCode' => $item_code,
                'Currency' => 'Ft',
                'Date' => date('Y-m-d'),
                'InventoryQuantity' => $quantity,
            ),
        );

        return $this->post('CompanyService_GetItemPrice', $body);
    }

    /**
     * Test connection
     */
    public function test_connection() {
        $result = $this->login();

        if ($result) {
            $this->logout();
            return true;
        }

        return false;
    }
}
