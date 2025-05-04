<?php
/**
 * PayPal Express Checkout functionality for Website A (Client)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PayPal Express Checkout Class
 */
class WPPPC_Express_Checkout {
    
    private static $buttons_added_to_cart = false;
    private static $buttons_added_to_checkout = false;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Add PayPal buttons to checkout page before customer details
        add_action('woocommerce_before_checkout_form', array($this, 'add_express_checkout_button_to_checkout'), 10);
        
        // Add scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handler for creating PayPal order for express checkout
        add_action('wp_ajax_wpppc_create_express_order', array($this, 'ajax_create_express_order'));
        add_action('wp_ajax_nopriv_wpppc_create_express_order', array($this, 'ajax_create_express_order'));
        
        // AJAX handler for completing express order
        add_action('wp_ajax_wpppc_complete_express_order', array($this, 'ajax_complete_express_order'));
        add_action('wp_ajax_nopriv_wpppc_complete_express_order', array($this, 'ajax_complete_express_order'));
        
        // AJAX handler for fetching PayPal order details
        add_action('wp_ajax_wpppc_fetch_paypal_order_details', array($this, 'ajax_fetch_paypal_order_details'));
        add_action('wp_ajax_nopriv_wpppc_fetch_paypal_order_details', array($this, 'ajax_fetch_paypal_order_details'));
    }
    
    /**
     * Check if PayPal gateway is enabled
     */
    private function is_gateway_enabled() {
        // Get the PayPal gateway settings
        $gateway_settings = get_option('woocommerce_paypal_proxy_settings', array());
        
        // Check if enabled
        return isset($gateway_settings['enabled']) && $gateway_settings['enabled'] === 'yes';
    }
    
    /**
     * Add Express Checkout button to checkout page
     */
    public function add_express_checkout_button_to_checkout() {
        // Check if gateway is enabled - EXIT if disabled
        if (!$this->is_gateway_enabled()) {
            return;
        }
        
        if (self::$buttons_added_to_checkout) {
            return;
        }
        self::$buttons_added_to_checkout = true;
        
        // Only show if we have a server
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            wpppc_log("Express Checkout: No PayPal server available for checkout buttons");
            return;
        }
        
        echo '<div class="wpppc-express-checkout-container">';
        echo '<h3>' . __('Express Checkout', 'woo-paypal-proxy-client') . '</h3>';
        echo '<p>' . __('Check out faster with PayPal', 'woo-paypal-proxy-client') . '</p>';
        echo '<div id="wpppc-express-paypal-button-checkout" class="wpppc-express-paypal-button"></div>';
        echo '</div>';
        echo '<div class="wpppc-express-separator"><span>' . __('OR', 'woo-paypal-proxy-client') . '</span></div>';
    }
    
    /**
     * Enqueue scripts and styles for Express Checkout
     */
    public function enqueue_scripts() {
        // Check if gateway is enabled - EXIT if disabled
        if (!$this->is_gateway_enabled()) {
            return;
        }
        
        if (!is_cart() && !is_checkout()) {
            return;
        }
        
        // Enqueue custom express checkout styles
        wp_enqueue_style('wpppc-express-checkout', WPPPC_PLUGIN_URL . 'assets/css/express-checkout.css', array(), WPPPC_VERSION);
        
        // Enqueue custom script for Express Checkout
        wp_enqueue_script('wpppc-express-checkout', WPPPC_PLUGIN_URL . 'assets/js/express-checkout.js', array('jquery'), WPPPC_VERSION, true);
        
        // Get server for button URL
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            return;
        }
        
        // Get API handler with server
        $api_handler = new WPPPC_API_Handler();
        
        // Create base button iframe URL (will be updated with current totals when clicked)
        $iframe_url = $api_handler->generate_express_iframe_url();
        
        // Pass data to JavaScript
        wp_localize_script('wpppc-express-checkout', 'wpppc_express_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpppc-express-nonce'),
            'iframe_url' => $iframe_url,
            'cart_total' => WC()->cart->get_total(''),
            'currency' => get_woocommerce_currency(),
            'shipping_required' => WC()->cart->needs_shipping(),
            'is_checkout_page' => is_checkout(),
            'is_cart_page' => is_cart(),
            'debug_mode' => true
        ));
    }
    
/**
 * AJAX handler for creating a PayPal order for Express Checkout
 */
public function ajax_create_express_order() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    wpppc_log("Express Checkout: Creating order via AJAX");
    
    try {
        // Make sure cart is not empty
        if (WC()->cart->is_empty()) {
            wpppc_log("Express Checkout: Cart is empty");
            throw new Exception(__('Your cart is empty', 'woo-paypal-proxy-client'));
        }
        
        // Get current checkout totals from AJAX request
        $current_totals = isset($_POST['current_totals']) ? $_POST['current_totals'] : array();
        wpppc_log("Express Checkout: Received current totals: " . json_encode($current_totals));
        
        // Create temporary order with pending status
        $order = wc_create_order();
        
        // Mark as express checkout
        $order->add_meta_data('_wpppc_express_checkout', 'yes');
        
        // STORE the checkout totals in order meta for later use
        if (!empty($current_totals)) {
            update_post_meta($order->get_id(), '_express_checkout_totals', $current_totals);
            wpppc_log("Express Checkout: Stored checkout totals in order meta");
        }
        
        // Add cart items to order
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $variation_id = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : 0;
            
            // Add line item
            $item = new WC_Order_Item_Product();
            $item->set_props(array(
                'product_id'   => $product->get_id(),
                'variation_id' => $variation_id,
                'quantity'     => $cart_item['quantity'],
                'subtotal'     => $cart_item['line_subtotal'],
                'total'        => $cart_item['line_total'],
                'subtotal_tax' => $cart_item['line_subtotal_tax'],
                'total_tax'    => $cart_item['line_tax'],
                'taxes'        => $cart_item['line_tax_data']
            ));
            
            // Add item name
            $item->set_name($product->get_name());
            
            // Add any meta data from cart item
            if (!empty($cart_item['variation'])) {
                foreach ($cart_item['variation'] as $meta_name => $meta_value) {
                    $item->add_meta_data(str_replace('attribute_', '', $meta_name), $meta_value);
                }
            }
            
            // Add the item to the order
            $order->add_item($item);
        }
        
        // Add fees
        foreach (WC()->cart->get_fees() as $fee) {
            $fee_item = new WC_Order_Item_Fee();
            $fee_item->set_props(array(
                'name'      => $fee->name,
                'tax_class' => $fee->tax_class,
                'total'     => $fee->amount,
                'total_tax' => $fee->tax,
                'taxes'     => array(
                    'total' => $fee->tax_data,
                ),
            ));
            $order->add_item($fee_item);
        }
        
        // Add coupons
        foreach (WC()->cart->get_coupons() as $code => $coupon) {
            $coupon_item = new WC_Order_Item_Coupon();
            $coupon_item->set_props(array(
                'code'         => $code,
                'discount'     => WC()->cart->get_coupon_discount_amount($code),
                'discount_tax' => WC()->cart->get_coupon_discount_tax_amount($code),
            ));
            
            if (method_exists($coupon_item, 'add_meta_data')) {
                $coupon_item->add_meta_data('coupon_data', $coupon->get_data());
            }
            
            $order->add_item($coupon_item);
        }
        
        // Set payment method
        $order->set_payment_method('paypal_proxy');
        
        // Apply selected shipping method if provided
        if (!empty($current_totals['shipping_method'])) {
            $shipping_method_id = $current_totals['shipping_method'];
            $packages = WC()->shipping->get_packages();
            
            foreach ($packages as $package_key => $package) {
                if (isset($package['rates'][$shipping_method_id])) {
                    $shipping_rate = $package['rates'][$shipping_method_id];
                    
                    $item = new WC_Order_Item_Shipping();
                    $item->set_props(array(
                        'method_title' => $shipping_rate->get_label(),
                        'method_id'    => $shipping_rate->get_id(),
                        'total'        => wc_format_decimal($shipping_rate->get_cost()),
                        'taxes'        => $shipping_rate->get_taxes(),
                        'instance_id'  => $shipping_rate->get_instance_id(),
                    ));
                    
                    foreach ($shipping_rate->get_meta_data() as $key => $value) {
                        $item->add_meta_data($key, $value, true);
                    }
                    
                    $order->add_item($item);
                }
            }
        }
        
        // Initially set empty addresses - PayPal will provide these later
        $order->set_address(array(), 'billing');
        $order->set_address(array(), 'shipping');
        
        // Calculate totals
        $order->calculate_totals();
        
        // Force the order to use exact checkout totals
        if (isset($current_totals['total'])) {
            update_post_meta($order->get_id(), '_order_total', $current_totals['total']);
        }
        if (isset($current_totals['shipping'])) {
            update_post_meta($order->get_id(), '_order_shipping', $current_totals['shipping']);
        }
        if (isset($current_totals['tax'])) {
            update_post_meta($order->get_id(), '_order_tax', $current_totals['tax']);
        }
        
        // Set order status to pending
        $order->update_status('pending', __('Order created via PayPal Express Checkout', 'woo-paypal-proxy-client'));
        
        // Save the order
        $order->save();
        
        // Use current totals from checkout page
        $order_total = isset($current_totals['total']) ? $current_totals['total'] : $order->get_total();
        $order_subtotal = isset($current_totals['subtotal']) ? $current_totals['subtotal'] : $order->get_subtotal();
        $shipping_total = isset($current_totals['shipping']) ? $current_totals['shipping'] : $order->get_shipping_total();
        $tax_total = isset($current_totals['tax']) ? $current_totals['tax'] : $order->get_total_tax();
        
        wpppc_log("Express Checkout: Using totals from checkout - Total: $order_total, Subtotal: $order_subtotal, Shipping: $shipping_total, Tax: $tax_total");
        
        // Get server to use
        $server_manager = WPPPC_Server_Manager::get_instance();
        $server = $server_manager->get_selected_server();
        
        if (!$server) {
            $server = $server_manager->get_next_available_server();
        }
        
        if (!$server) {
            throw new Exception(__('No PayPal server available', 'woo-paypal-proxy-client'));
        }
        
        // Store server ID in order
        update_post_meta($order->get_id(), '_wpppc_server_id', $server->id);
        
        // Prepare line items for PayPal with detailed information
        $line_items = array();
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            
            if (!$product) continue;
            
            $line_items[] = array(
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'unit_price' => $order->get_item_subtotal($item, false),
                'tax_amount' => $item->get_total_tax(),
                'sku' => $product ? $product->get_sku() : '',
                'product_id' => $product ? $product->get_id() : 0,
                'description' => $product ? wp_trim_words($product->get_short_description(), 15) : ('Product ID: ' . $product->get_id())
            );
        }
        
        // Apply product mapping to line items
        if (function_exists('add_product_mappings_to_items')) {
            $line_items = add_product_mappings_to_items($line_items, $server->id);
            wpppc_log("Express Checkout: Product mapping applied to line items");
        }
        
        // Get customer information if available
        $customer_info = array();
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $customer_info = array(
                'first_name' => $current_user->first_name,
                'last_name' => $current_user->last_name,
                'email' => $current_user->user_email
            );
        }
        
        // Create order data for proxy server with EXACT checkout totals
        $order_data = array(
            'order_id' => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'line_items' => $line_items,
            'cart_total' => $order_subtotal,
            'order_total' => $order_total,
            'tax_total' => $tax_total,
            'shipping_total' => $shipping_total,
            'discount_total' => $order->get_discount_total(),
            'currency' => $order->get_currency(),
            'return_url' => wc_get_checkout_url(),
            'cancel_url' => wc_get_cart_url(),
            'callback_url' => WC()->api_request_url('wpppc_shipping'),
            'needs_shipping' => WC()->cart->needs_shipping(),
            'server_id' => $server->id,
            'customer_info' => $customer_info
        );
        
        // Encode the order data to base64
        $order_data_encoded = base64_encode(json_encode($order_data));
        
        // Generate security hash with the EXACT total
        $timestamp = time();
        $hash_data = $timestamp . $order->get_id() . $order_total . $server->api_key;
        $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
        
        // Create request data with proper format for proxy server
        $request_data = array(
            'api_key' => $server->api_key,
            'timestamp' => $timestamp,
            'hash' => $hash,
            'order_data' => $order_data_encoded
        );
        
        wpppc_log("Express Checkout: Sending properly formatted request to proxy server");
        
        // Send request to proxy server
        $response = wp_remote_post(
            $server->url . '/wp-json/wppps/v1/create-express-checkout',
            array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($request_data)
            )
        );
        
        // Check for errors
        if (is_wp_error($response)) {
            wpppc_log("Express Checkout: Error communicating with proxy server: " . $response->get_error_message());
            throw new Exception(__('Error communicating with proxy server: ', 'woo-paypal-proxy-client') . $response->get_error_message());
        }
        
        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            wpppc_log("Express Checkout: Proxy server returned error code: $response_code");
            wpppc_log("Express Checkout: Response body: " . wp_remote_retrieve_body($response));
            throw new Exception(__('Proxy server returned error', 'woo-paypal-proxy-client'));
        }
        
        // Parse response
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$body || !isset($body['success']) || $body['success'] !== true) {
            $error_message = isset($body['message']) ? $body['message'] : __('Unknown error from proxy server', 'woo-paypal-proxy-client');
            wpppc_log("Express Checkout: Proxy server error: $error_message");
            throw new Exception($error_message);
        }
        
        // Store PayPal order ID in WooCommerce order
        $paypal_order_id = isset($body['paypal_order_id']) ? $body['paypal_order_id'] : '';
        if (!empty($paypal_order_id)) {
            update_post_meta($order->get_id(), '_paypal_order_id', $paypal_order_id);
            wpppc_log("Express Checkout: Stored PayPal order ID: $paypal_order_id for order #{$order->get_id()}");
        } else {
            wpppc_log("Express Checkout: No PayPal order ID received from proxy server");
            throw new Exception(__('No PayPal order ID received from proxy server', 'woo-paypal-proxy-client'));
        }
        
        // Return success with PayPal order ID
        wp_send_json_success(array(
            'order_id' => $order->get_id(),
            'paypal_order_id' => $paypal_order_id,
            'approveUrl' => isset($body['approve_url']) ? $body['approve_url'] : ''
        ));
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error creating order: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
    
/**
 * AJAX handler for completing an Express Checkout order
 */
public function ajax_complete_express_order() {
    check_ajax_referer('wpppc-express-nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
    
    wpppc_log("DEBUG: Express Checkout: Starting order completion for order #$order_id, PayPal order $paypal_order_id");
    
    try {
        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception(__('Order not found', 'woo-paypal-proxy-client'));
        }
        
        // RESTORE the original checkout totals
        $stored_totals = get_post_meta($order->get_id(), '_express_checkout_totals', true);
        
        if (!empty($stored_totals)) {
            wpppc_log("DEBUG: Express Checkout: Restoring stored totals: " . json_encode($stored_totals));
            
            // 1. First, ensure the shipping method is properly set and won't be recalculated
            if (!empty($stored_totals['shipping_method'])) {
                // Check if shipping method is already in order, if not add it
                $shipping_items = $order->get_items('shipping');
                $has_matching_shipping = false;
                
                foreach ($shipping_items as $item) {
                    if ($item->get_method_id() . ':' . $item->get_instance_id() === $stored_totals['shipping_method']) {
                        $has_matching_shipping = true;
                        // Ensure the shipping amount matches the stored value
                        $item->set_props(array(
                            'total' => $stored_totals['shipping']
                        ));
                        $item->save();
                    }
                }
                
                // If shipping method doesn't exist, try to find and add it
                if (!$has_matching_shipping) {
                    $packages = WC()->shipping->get_packages();
                    foreach ($packages as $package_key => $package) {
                        if (isset($package['rates'][$stored_totals['shipping_method']])) {
                            $shipping_rate = $package['rates'][$stored_totals['shipping_method']];
                            
                            $item = new WC_Order_Item_Shipping();
                            $item->set_props(array(
                                'method_title' => $shipping_rate->get_label(),
                                'method_id'    => $shipping_rate->get_id(),
                                'total'        => $stored_totals['shipping'],
                                'instance_id'  => $shipping_rate->get_instance_id(),
                            ));
                            
                            foreach ($shipping_rate->get_meta_data() as $key => $value) {
                                $item->add_meta_data($key, $value, true);
                            }
                            
                            $order->add_item($item);
                            wpppc_log("DEBUG: Added missing shipping method to order");
                        }
                    }
                }
            }
            
            // 2. Force exact totals
            update_post_meta($order->get_id(), '_order_total', $stored_totals['total']);
            update_post_meta($order->get_id(), '_order_shipping', $stored_totals['shipping']);
            update_post_meta($order->get_id(), '_order_tax', $stored_totals['tax']);
            update_post_meta($order->get_id(), '_cart_tax', $stored_totals['tax']);
            
            // 3. Set tax lines to prevent recalculation
            $tax_items = $order->get_items('tax');
            $total_tax_set = 0;
            
            foreach ($tax_items as $item) {
                $tax_total = $stored_totals['tax'] - $total_tax_set;
                $item->set_props(array(
                    'tax_total' => $tax_total,
                    'shipping_tax_total' => 0
                ));
                $item->save();
                $total_tax_set += $tax_total;
            }
            
            // If no tax items exist but we have tax, create one
            if (empty($tax_items) && $stored_totals['tax'] > 0) {
                $item = new WC_Order_Item_Tax();
                $item->set_props(array(
                    'rate_id'          => 0,
                    'label'            => 'Tax',
                    'compound'         => false,
                    'tax_total'        => $stored_totals['tax'],
                    'shipping_tax_total' => 0,
                ));
                $order->add_item($item);
            }
            
            // 4. Force the order total to use our exact values
            $order->set_total($stored_totals['total']);
            
            // 5. Save the order
            $order->save();
        }
        
        // Continue with payment capture...
        $response = wp_remote_post(
            $server->url . '/wp-json/wppps/v1/capture-express-payment',
            array(
                'timeout' => 30,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($request_data)
            )
        );
        
        // Get transaction ID from response
        $transaction_id = isset($body['transaction_id']) ? $body['transaction_id'] : '';
        $seller_protection = isset($body['seller_protection']) ? $body['seller_protection'] : 'UNKNOWN';
        
        // Complete payment ONLY after all totals are set
        if (!empty($transaction_id)) {
            // Mark order as paid without recalculating
            update_post_meta($order->get_id(), '_paid_date', current_time('mysql'));
            update_post_meta($order->get_id(), '_transaction_id', $transaction_id);
            
            // Use a simple status update instead of payment_complete to avoid recalculation
            $order->update_status('processing', sprintf(
                __('Payment completed via PayPal Express Checkout. Transaction ID: %s, PayPal Order ID: %s', 'woo-paypal-proxy-client'),
                $transaction_id,
                $paypal_order_id
            ));
            
            // Add detailed notes with exact totals
            if (!empty($stored_totals)) {
                $order->add_order_note(sprintf(
                    __('Express Checkout Completed. Shipping: %s, Tax: %s, Total: %s', 'woo-paypal-proxy-client'),
                    wc_price($stored_totals['shipping']),
                    wc_price($stored_totals['tax']),
                    wc_price($stored_totals['total'])
                ));
            }
            
            update_post_meta($order->get_id(), '_paypal_transaction_id', $transaction_id);
            update_post_meta($order->get_id(), '_paypal_seller_protection', $seller_protection);
            
            // Track server usage
            $order_amount = floatval($order->get_total());
            $result = $server_manager->add_server_usage($server_id, $order_amount);
        }
        
        wpppc_log("DEBUG: Express Checkout: Order totals AFTER completion:");
        wpppc_log("DEBUG: Order total: " . $order->get_total());
        wpppc_log("DEBUG: Subtotal: " . $order->get_subtotal());
        wpppc_log("DEBUG: Shipping total: " . $order->get_shipping_total());
        wpppc_log("DEBUG: Tax total: " . $order->get_total_tax());
        
        // Log shipping methods for debugging
        $shipping_items = $order->get_items('shipping');
        foreach ($shipping_items as $item) {
            wpppc_log("DEBUG: Shipping method: " . $item->get_name() . " - " . $item->get_total());
        }
        
        // Mirror order to server
        $api_handler = new WPPPC_API_Handler($server_id);
        $mirror_response = $api_handler->mirror_order_to_server($order, $paypal_order_id, $transaction_id);
        
        // Empty the cart
        WC()->cart->empty_cart();
        
        // Return success with redirect URL
        wp_send_json_success(array(
            'redirect' => $order->get_checkout_order_received_url()
        ));
        
    } catch (Exception $e) {
        wpppc_log("Express Checkout: Error completing order: " . $e->getMessage());
        wp_send_json_error(array(
            'message' => $e->getMessage()
        ));
    }
    
    wp_die();
}
    
    /**
     * AJAX handler for fetching PayPal order details and updating the WooCommerce order
     */
    public function ajax_fetch_paypal_order_details() {
        check_ajax_referer('wpppc-express-nonce', 'nonce');
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $paypal_order_id = isset($_POST['paypal_order_id']) ? sanitize_text_field($_POST['paypal_order_id']) : '';
        
        wpppc_log("Express Checkout: Fetching PayPal order details for order #$order_id, PayPal order $paypal_order_id");
        
        try {
            // Get order
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Order not found', 'woo-paypal-proxy-client'));
            }
            
            // Get server ID from order
            $server_id = get_post_meta($order->get_id(), '_wpppc_server_id', true);
            if (!$server_id) {
                throw new Exception(__('Server ID not found for order', 'woo-paypal-proxy-client'));
            }
            
            // Get server
            $server_manager = WPPPC_Server_Manager::get_instance();
            $server = $server_manager->get_server($server_id);
            
            if (!$server) {
                throw new Exception(__('PayPal server not found', 'woo-paypal-proxy-server'));
            }
            
            // Generate security parameters
            $timestamp = time();
            $hash_data = $timestamp . $paypal_order_id . $server->api_key;
            $hash = hash_hmac('sha256', $hash_data, $server->api_secret);
            
            // Call the endpoint on Website B to get PayPal order details
            $response = wp_remote_post(
                $server->url . '/wp-json/wppps/v1/get-paypal-order',
                array(
                    'timeout' => 30,
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => json_encode(array(
                        'api_key' => $server->api_key,
                        'paypal_order_id' => $paypal_order_id,
                        'timestamp' => $timestamp,
                        'hash' => $hash
                    ))
                )
            );
            
            // Check for errors
            if (is_wp_error($response)) {
                throw new Exception(__('Error communicating with proxy server: ', 'woo-paypal-proxy-client') . $response->get_error_message());
            }
            
            // Get response code
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                throw new Exception(__('Proxy server returned error code: ', 'woo-paypal-proxy-client') . $response_code);
            }
            
            // Parse response
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!$body || !isset($body['success']) || $body['success'] !== true) {
                $error_message = isset($body['message']) ? $body['message'] : __('Unknown error from proxy server', 'woo-paypal-proxy-client');
                throw new Exception($error_message);
            }
            
            // Get PayPal order details
            $order_details = isset($body['order_details']) ? $body['order_details'] : null;
            
            if (!$order_details) {
                throw new Exception(__('No order details in response', 'woo-paypal-proxy-client'));
            }
            
            wpppc_log("Express Checkout: Successfully retrieved PayPal order details. Processing address data.");
            
            remove_action('woocommerce_checkout_update_order_meta', 'woocommerce_checkout_must_be_logged_in');
        remove_action('woocommerce_checkout_update_order_meta', array($this, 'apply_order_meta'));
        
            
            // Process billing address data
            if (!empty($order_details['payer'])) {
                $billing_address = array();
                
                // Get payer name
                if (!empty($order_details['payer']['name'])) {
                    $billing_address['first_name'] = isset($order_details['payer']['name']['given_name']) ? 
                        $order_details['payer']['name']['given_name'] : '';
                    $billing_address['last_name'] = isset($order_details['payer']['name']['surname']) ? 
                        $order_details['payer']['name']['surname'] : '';
                }
                
                // Get email
                if (!empty($order_details['payer']['email_address'])) {
                    $billing_address['email'] = $order_details['payer']['email_address'];
                }
                
                // Get address
                if (!empty($order_details['payer']['address'])) {
                    $billing_address['address_1'] = isset($order_details['payer']['address']['address_line_1']) ? 
                        $order_details['payer']['address']['address_line_1'] : '';
                    $billing_address['address_2'] = isset($order_details['payer']['address']['address_line_2']) ? 
                        $order_details['payer']['address']['address_line_2'] : '';
                    $billing_address['city'] = isset($order_details['payer']['address']['admin_area_2']) ? 
                        $order_details['payer']['address']['admin_area_2'] : '';
                    $billing_address['state'] = isset($order_details['payer']['address']['admin_area_1']) ? 
                        $order_details['payer']['address']['admin_area_1'] : '';
                    $billing_address['postcode'] = isset($order_details['payer']['address']['postal_code']) ? 
                        $order_details['payer']['address']['postal_code'] : '';
                    $billing_address['country'] = isset($order_details['payer']['address']['country_code']) ? 
                        $order_details['payer']['address']['country_code'] : '';
                }
                
                // Set billing address if we have minimum data
                if (!empty($billing_address['first_name'])) {
                    $order->set_address($billing_address, 'billing');
                    update_post_meta($order->get_id(), '_wpppc_billing_address', $billing_address);
                }
            }
            
            // Process shipping address data
            if (!empty($order_details['purchase_units']) && is_array($order_details['purchase_units'])) {
                foreach ($order_details['purchase_units'] as $unit) {
                    if (!empty($unit['shipping'])) {
                        $shipping_address = array();
                        
                        // Get name
                        if (!empty($unit['shipping']['name'])) {
                            if (!empty($unit['shipping']['name']['full_name'])) {
                                $name_parts = explode(' ', $unit['shipping']['name']['full_name'], 2);
                                $shipping_address['first_name'] = $name_parts[0];
                                $shipping_address['last_name'] = isset($name_parts[1]) ? $name_parts[1] : '';
                            } else if (!empty($unit['shipping']['name']['given_name'])) {
                                $shipping_address['first_name'] = $unit['shipping']['name']['given_name'];
                                $shipping_address['last_name'] = !empty($unit['shipping']['name']['surname']) ? 
                                    $unit['shipping']['name']['surname'] : '';
                            }
                        }
                        
                        // Get address
                        if (!empty($unit['shipping']['address'])) {
                            $address = $unit['shipping']['address'];
                            $shipping_address['address_1'] = isset($address['address_line_1']) ? $address['address_line_1'] : '';
                            $shipping_address['address_2'] = isset($address['address_line_2']) ? $address['address_line_2'] : '';
                            $shipping_address['city'] = isset($address['admin_area_2']) ? $address['admin_area_2'] : '';
                            $shipping_address['state'] = isset($address['admin_area_1']) ? $address['admin_area_1'] : '';
                            $shipping_address['postcode'] = isset($address['postal_code']) ? $address['postal_code'] : '';
                            $shipping_address['country'] = isset($address['country_code']) ? $address['country_code'] : '';
                        }
                        
                        // Set shipping address if we have minimum data
                        if (!empty($shipping_address['first_name']) && !empty($shipping_address['address_1'])) {
                            $order->set_address($shipping_address, 'shipping');
                            update_post_meta($order->get_id(), '_wpppc_shipping_address', $shipping_address);
                        }
                        
                        break; // We only need the first shipping address
                    }
                }
            }
            
            // Save the order
            $order->save();
            
            // Return success
            wp_send_json_success(array(
                'message' => 'Order details retrieved and addresses updated',
                'has_billing' => !empty($billing_address),
                'has_shipping' => !empty($shipping_address)
            ));
            
        } catch (Exception $e) {
            wpppc_log("Express Checkout: Error fetching order details: " . $e->getMessage());
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
        
        wp_die();
    }
}

// Initialize Express Checkout
add_action('init', function() {
    new WPPPC_Express_Checkout();
});