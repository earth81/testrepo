<?php
/**
 * Customer Sync - WooCommerce Customers to SAP Business Partners
 */

if (!defined('ABSPATH')) {
    exit;
}

class Remenyi_SAP_Customer_Sync {

    /**
     * SAP API instance
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new Remenyi_SAP_API();

        // Hook into WooCommerce customer registration
        add_action('woocommerce_created_customer', array($this, 'on_customer_created'), 10, 3);

        // Hook into customer update
        add_action('woocommerce_update_customer', array($this, 'on_customer_updated'), 10, 1);

        // Hook into checkout to create/update customer
        add_action('woocommerce_checkout_order_processed', array($this, 'on_checkout_processed'), 10, 3);
    }

    /**
     * Sync all customers from SAP to WooCommerce
     * (Used for initial import or daily sync)
     */
    public function sync_all_customers($since_date = null) {
        Remenyi_SAP_Logger::log('customer', 'Starting customer sync from SAP', array('since_date' => $since_date));

        $customers = $this->api->get_customers($since_date);

        if (is_wp_error($customers)) {
            Remenyi_SAP_Logger::log('error', 'Failed to get customers from SAP: ' . $customers->get_error_message());
            return false;
        }

        $synced = 0;
        $errors = 0;

        foreach ($customers as $customer) {
            $result = $this->import_customer_from_sap($customer);
            if ($result) {
                $synced++;
            } else {
                $errors++;
            }
        }

        update_option('remenyi_sap_last_customer_sync', date('Y-m-d'));

        Remenyi_SAP_Logger::log('customer', 'Customer sync completed', array(
            'total' => count($customers),
            'synced' => $synced,
            'errors' => $errors,
        ));

        return array(
            'total' => count($customers),
            'synced' => $synced,
            'errors' => $errors,
        );
    }

    /**
     * Import a customer from SAP to WooCommerce
     */
    private function import_customer_from_sap($sap_customer) {
        try {
            $card_code = $sap_customer['CardCode'];

            // Find contact employee email
            $email = null;
            if (!empty($sap_customer['ContactEmployees'])) {
                foreach ($sap_customer['ContactEmployees'] as $contact) {
                    if (!empty($contact['E_Mail'])) {
                        $email = $contact['E_Mail'];
                        break;
                    }
                }
            }

            if (!$email) {
                $email = $sap_customer['EmailAddress'] ?? null;
            }

            if (!$email) {
                Remenyi_SAP_Logger::log('debug', 'Skipping customer without email: ' . $card_code);
                return false;
            }

            // Check if customer exists
            $user = get_user_by('email', $email);

            if (!$user) {
                // Create new WooCommerce customer
                $user_id = wc_create_new_customer($email, '', wp_generate_password());
                if (is_wp_error($user_id)) {
                    return false;
                }
            } else {
                $user_id = $user->ID;
            }

            // Update customer meta
            update_user_meta($user_id, '_sap_card_code', $card_code);
            update_user_meta($user_id, 'billing_company', $sap_customer['CardName'] ?? '');
            update_user_meta($user_id, 'billing_phone', $sap_customer['Phone1'] ?? '');

            // Update addresses
            $this->update_customer_addresses($user_id, $sap_customer);

            return true;

        } catch (Exception $e) {
            Remenyi_SAP_Logger::log('error', 'Failed to import customer: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle new customer registration in WooCommerce
     */
    public function on_customer_created($customer_id, $new_customer_data, $password_generated) {
        if (get_option('remenyi_sap_sync_enabled') !== 'yes') {
            return;
        }

        // Queue the customer for SAP sync
        $this->queue_customer_sync($customer_id);
    }

    /**
     * Handle customer update in WooCommerce
     */
    public function on_customer_updated($customer_id) {
        if (get_option('remenyi_sap_sync_enabled') !== 'yes') {
            return;
        }

        $this->queue_customer_sync($customer_id);
    }

    /**
     * Handle checkout - create or update customer in SAP
     */
    public function on_checkout_processed($order_id, $posted_data, $order) {
        if (get_option('remenyi_sap_sync_enabled') !== 'yes') {
            return;
        }

        $customer_id = $order->get_customer_id();

        if ($customer_id) {
            // Registered customer
            $this->sync_customer_to_sap($customer_id, $order);
        } else {
            // Guest checkout - create customer in SAP
            $this->create_guest_customer_in_sap($order);
        }
    }

    /**
     * Queue customer for sync (using Action Scheduler if available)
     */
    private function queue_customer_sync($customer_id) {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'remenyi_sap_sync_customer', array($customer_id));
        } else {
            // Immediate sync if no Action Scheduler
            $this->sync_customer_to_sap($customer_id);
        }
    }

    /**
     * Sync a WooCommerce customer to SAP
     */
    public function sync_customer_to_sap($customer_id, $order = null) {
        $customer = new WC_Customer($customer_id);

        // Check if customer already exists in SAP
        $sap_card_code = get_user_meta($customer_id, '_sap_card_code', true);

        if ($sap_card_code) {
            // Update existing customer
            return $this->update_customer_in_sap($sap_card_code, $customer, $order);
        } else {
            // Create new customer
            return $this->create_customer_in_sap($customer, $order);
        }
    }

    /**
     * Create new customer in SAP
     */
    private function create_customer_in_sap($customer, $order = null) {
        // Generate unique card code
        $card_code = 'WEB' . str_pad($customer->get_id(), 6, '0', STR_PAD_LEFT);

        // Build customer data
        $data = $this->build_customer_data($customer, $order, $card_code);

        $result = $this->api->create_customer($data);

        if (is_wp_error($result)) {
            Remenyi_SAP_Logger::log('error', 'Failed to create customer in SAP: ' . $result->get_error_message(), array(
                'customer_id' => $customer->get_id(),
            ));
            return false;
        }

        // Save SAP card code
        update_user_meta($customer->get_id(), '_sap_card_code', $card_code);

        Remenyi_SAP_Logger::log('customer', 'Customer created in SAP', array(
            'customer_id' => $customer->get_id(),
            'card_code' => $card_code,
        ));

        return $card_code;
    }

    /**
     * Update existing customer in SAP
     */
    private function update_customer_in_sap($card_code, $customer, $order = null) {
        // Check for new addresses to add
        $data = array();

        // Check if we need to add new addresses
        if ($order) {
            $billing_address = $this->build_address_from_order($order, 'billing');
            $shipping_address = $this->build_address_from_order($order, 'shipping');

            // TODO: Check if addresses already exist in SAP before adding
            // For now, we'll skip address updates on existing customers
        }

        // Update contact if needed
        $contact_data = $this->build_contact_data($customer, $order);
        if (!empty($contact_data)) {
            $data['ContactEmployees'] = array($contact_data);
        }

        if (empty($data)) {
            return $card_code;
        }

        $result = $this->api->update_customer($card_code, $data);

        if (is_wp_error($result)) {
            Remenyi_SAP_Logger::log('error', 'Failed to update customer in SAP: ' . $result->get_error_message(), array(
                'card_code' => $card_code,
            ));
            return false;
        }

        Remenyi_SAP_Logger::log('customer', 'Customer updated in SAP', array(
            'card_code' => $card_code,
        ));

        return $card_code;
    }

    /**
     * Create guest customer in SAP
     */
    private function create_guest_customer_in_sap($order) {
        // Generate unique card code for guest
        $card_code = 'GUEST' . str_pad($order->get_id(), 6, '0', STR_PAD_LEFT);

        $data = array(
            'CardCode' => $card_code,
            'CardName' => $order->get_billing_company() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'CardType' => 'cCustomer',
            'Phone1' => $order->get_billing_phone(),
            'Currency' => 'Ft',
            'PayTermsGrpCode' => -1,
            'ContactEmployees' => array(
                array(
                    'CardCode' => $card_code,
                    'Name' => 'WEB',
                    'Active' => 'tYES',
                    'FirstName' => $order->get_billing_first_name(),
                    'LastName' => $order->get_billing_last_name(),
                    'E_Mail' => $order->get_billing_email(),
                    'MobilePhone' => $order->get_billing_phone(),
                ),
            ),
            'BPAddresses' => array(
                $this->build_address_from_order($order, 'billing'),
                $this->build_address_from_order($order, 'shipping'),
            ),
        );

        $result = $this->api->create_customer($data);

        if (is_wp_error($result)) {
            Remenyi_SAP_Logger::log('error', 'Failed to create guest customer in SAP: ' . $result->get_error_message(), array(
                'order_id' => $order->get_id(),
            ));
            return false;
        }

        // Save SAP card code to order meta
        $order->update_meta_data('_sap_card_code', $card_code);
        $order->save();

        Remenyi_SAP_Logger::log('customer', 'Guest customer created in SAP', array(
            'order_id' => $order->get_id(),
            'card_code' => $card_code,
        ));

        return $card_code;
    }

    /**
     * Build customer data for SAP
     */
    private function build_customer_data($customer, $order = null, $card_code = null) {
        $data = array(
            'CardCode' => $card_code,
            'CardName' => $customer->get_billing_company() ?: $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name(),
            'CardType' => 'cCustomer',
            'Phone1' => $customer->get_billing_phone(),
            'Currency' => 'Ft',
            'PayTermsGrpCode' => -1,
        );

        // Add shipping type if set
        $shipping_type = get_option('remenyi_sap_default_shipping_type', 4);
        if ($shipping_type) {
            $data['ShippingType'] = intval($shipping_type);
        }

        // Add contact (tárgyalópartner)
        $contact = $this->build_contact_data($customer, $order);
        $contact['CardCode'] = $card_code;
        $contact['Name'] = 'WEB';
        $data['ContactEmployees'] = array($contact);

        // Add addresses
        $data['BPAddresses'] = array();

        // Billing address
        $billing = $this->build_address($customer, 'billing');
        if ($billing) {
            $data['BPAddresses'][] = $billing;
        }

        // Shipping address
        $shipping = $this->build_address($customer, 'shipping');
        if ($shipping) {
            $data['BPAddresses'][] = $shipping;
        }

        // If no addresses from customer, try from order
        if (empty($data['BPAddresses']) && $order) {
            $data['BPAddresses'][] = $this->build_address_from_order($order, 'billing');
            $data['BPAddresses'][] = $this->build_address_from_order($order, 'shipping');
        }

        return $data;
    }

    /**
     * Build contact data
     */
    private function build_contact_data($customer, $order = null) {
        $first_name = $customer->get_billing_first_name();
        $last_name = $customer->get_billing_last_name();
        $email = $customer->get_email();
        $phone = $customer->get_billing_phone();

        // Override with order data if available
        if ($order) {
            $first_name = $first_name ?: $order->get_billing_first_name();
            $last_name = $last_name ?: $order->get_billing_last_name();
            $email = $email ?: $order->get_billing_email();
            $phone = $phone ?: $order->get_billing_phone();
        }

        return array(
            'Active' => 'tYES',
            'FirstName' => $first_name,
            'LastName' => $last_name,
            'E_Mail' => $email,
            'MobilePhone' => $phone,
        );
    }

    /**
     * Build address from customer
     */
    private function build_address($customer, $type) {
        $prefix = $type . '_';
        $method_prefix = 'get_' . $type . '_';

        $street = $customer->{$method_prefix . 'address_1'}();
        $city = $customer->{$method_prefix . 'city'}();
        $postcode = $customer->{$method_prefix . 'postcode'}();
        $country = $customer->{$method_prefix . 'country'}();

        if (empty($street) || empty($city)) {
            return null;
        }

        $address_name = $type === 'billing' ? 'Számlázási cím' : 'Szállítási cím';
        $address_type = $type === 'billing' ? 'bo_BillTo' : 'bo_ShipTo';

        // Add address line 2 if present
        $address_2 = $customer->{$method_prefix . 'address_2'}();
        if ($address_2) {
            $street .= ' ' . $address_2;
        }

        return array(
            'AddressName' => $address_name,
            'Street' => $street,
            'ZipCode' => $postcode,
            'City' => $city,
            'Country' => $country ?: 'HU',
            'AddressType' => $address_type,
        );
    }

    /**
     * Build address from order
     */
    private function build_address_from_order($order, $type) {
        $prefix = $type . '_';
        $method_prefix = 'get_' . $type . '_';

        $street = $order->{$method_prefix . 'address_1'}();
        $city = $order->{$method_prefix . 'city'}();
        $postcode = $order->{$method_prefix . 'postcode'}();
        $country = $order->{$method_prefix . 'country'}();

        $address_name = $type === 'billing' ? 'Számlázási cím' : 'Szállítási cím';
        $address_type = $type === 'billing' ? 'bo_BillTo' : 'bo_ShipTo';

        // Add address line 2 if present
        $address_2 = $order->{$method_prefix . 'address_2'}();
        if ($address_2) {
            $street .= ' ' . $address_2;
        }

        return array(
            'AddressName' => $address_name,
            'Street' => $street ?: 'N/A',
            'ZipCode' => $postcode ?: 'N/A',
            'City' => $city ?: 'N/A',
            'Country' => $country ?: 'HU',
            'AddressType' => $address_type,
        );
    }

    /**
     * Update customer addresses from SAP data
     */
    private function update_customer_addresses($user_id, $sap_customer) {
        if (empty($sap_customer['BPAddresses'])) {
            return;
        }

        foreach ($sap_customer['BPAddresses'] as $address) {
            $type = $address['AddressType'] === 'bo_BillTo' ? 'billing' : 'shipping';

            update_user_meta($user_id, $type . '_address_1', $address['Street'] ?? '');
            update_user_meta($user_id, $type . '_city', $address['City'] ?? '');
            update_user_meta($user_id, $type . '_postcode', $address['ZipCode'] ?? '');
            update_user_meta($user_id, $type . '_country', $address['Country'] ?? 'HU');
        }
    }

    /**
     * Get SAP card code for a customer
     */
    public function get_sap_card_code($customer_id) {
        return get_user_meta($customer_id, '_sap_card_code', true);
    }

    /**
     * Find customer by email in SAP
     */
    public function find_customer_by_email($email) {
        return $this->api->get_customer_by_email($email);
    }

    /**
     * Find customer by tax ID in SAP
     */
    public function find_customer_by_tax_id($tax_id) {
        return $this->api->get_customer_by_tax_id($tax_id);
    }
}
