<?php
/**
 * BRCC Eventbrite Integration Class
 * 
 * Handles integration with Eventbrite API for ticket updates with enhanced date and time support
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Eventbrite_Integration {
    /**
     * Eventbrite API base URL
     */
    private $api_url = 'https://www.eventbriteapi.com/v3';
    
    /**
     * Eventbrite API Token
     */
    private $api_token;
    
    /**
     * Product mappings instance
     */
    private $product_mappings;
    
    // private $webhook_secret; // Removed - Not used by Eventbrite webhooks

    /**
     * Sales tracker instance
     */
    private $sales_tracker;

    /**
     * Constructor - setup hooks
     */
    public function __construct() {
        // Get settings
        $settings = get_option('brcc_api_settings');
        
        // Set API token
        $this->api_token = isset($settings['eventbrite_token']) ? $settings['eventbrite_token'] : '';
        // $this->webhook_secret = isset($settings['eventbrite_webhook_secret']) ? $settings['eventbrite_webhook_secret'] : ''; // Removed

        // Initialize product mappings
        $this->product_mappings = new BRCC_Product_Mappings();

        // Initialize sales tracker
        $this->sales_tracker = new BRCC_Sales_Tracker();

        // Add hooks
        if (!empty($this->api_token)) {
            // Hook into product sale with date and time support
            // REMOVED: add_action('brcc_product_sold_with_date', array($this, 'update_eventbrite_ticket_with_date'), 10, 5); // No longer decrementing on each sale
            
            // Original action for backward compatibility (might be removable if not needed)
            // add_action('brcc_product_sold', array($this, 'update_eventbrite_ticket'), 10, 2); 
            
            // Hook into inventory sync
            add_action('brcc_sync_inventory', array($this, 'sync_eventbrite_tickets'));

            // Register webhook endpoint (no longer depends on secret)
            add_action('rest_api_init', array($this, 'register_eventbrite_webhook_endpoint'));

            // --- NEW Hooks for Zero Stock Update ---
            // Hook after stock is reduced during order processing
            add_action('woocommerce_reduce_order_stock', array($this, 'handle_order_stock_reduction'), 10, 1);
            // Hook for direct stock updates (e.g., via admin)
            add_action('woocommerce_product_set_stock', array($this, 'handle_direct_stock_update'), 10, 1);
            add_action('woocommerce_variation_set_stock', array($this, 'handle_direct_stock_update'), 10, 1); // Also for variations
            // --- END NEW Hooks ---
        }
    }

    /**
     * Static handler for the scheduled Eventbrite update action.
     * Instantiates the class and calls the update method.
     *
     * @param array $args Arguments passed from wp_schedule_single_event.
     */
public static function handle_scheduled_eventbrite_update($args) {
    BRCC_Helpers::log_info('--- START handle_scheduled_eventbrite_update ---', $args);

    // Basic validation of args
    if (empty($args['order_id']) || empty($args['product_id'])) { // Removed quantity check as it's not needed for status update
        BRCC_Helpers::log_error('handle_scheduled_eventbrite_update: Invalid arguments received (missing order_id or product_id).', $args);
        return;
    }

    // Need an instance to call the non-static update method
    $instance = new self();

    // Check if instance was created and product_mappings is available
    if (!$instance || !$instance->product_mappings) {
         BRCC_Helpers::log_error('handle_scheduled_eventbrite_update: Failed to create instance or access product mappings.');
         return;
    }

    // Call the new event status update function instead of the old update_eventbrite_ticket_with_date
    $product_id = $args['product_id'];
    // We don't need order_id or quantity for just checking/updating status based on stock
    $instance->update_eventbrite_event_status($product_id);
    
    BRCC_Helpers::log_info('--- END handle_scheduled_eventbrite_update ---', $args);
}

    /**
     * Register webhook endpoint for Eventbrite
     */
    public function register_eventbrite_webhook_endpoint() {
        register_rest_route('brcc/v1', '/eventbrite-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_eventbrite_webhook'),
            // 'permission_callback' => array($this, 'verify_eventbrite_webhook_signature'), // Removed signature verification
        ));
        BRCC_Helpers::log_debug('Eventbrite webhook endpoint registered.'); // Restored debug log
    }
/**
 * Find the WooCommerce Product ID using only our database mappings.
 * First looks for event by date/time, then by event ID.
 *
 * @param string $ticket_class_id Ignored - we don't use this anymore.
 * @param string|null $date Event date (Y-m-d).
 * @param string|null $time Event time (H:i).
 * @param string|null $event_id The Eventbrite Event ID.
 * @return int|null The WooCommerce Product ID, or null if not found.
 */
public function find_product_id_for_event($ticket_class_id = null, $date = null, $time = null, $event_id = null) {
    BRCC_Helpers::log_debug("find_product_id_for_event: Searching for Event ID: {$event_id}, Date: {$date}, Time: {$time}");
    
    $this->load_mappings(); // Ensure mappings are loaded
    
    // FIRST PRIORITY: Find by date and time (most specific match)
    if ($date && $time) {
        $date_time_key = $date . '_' . $time;
        
        // Check all products with date mappings
        foreach ($this->all_mappings as $product_id_key => $mapping_data) {
            if (strpos($product_id_key, '_dates') !== false) {
                $base_product_id = (int) str_replace('_dates', '', $product_id_key);
                
                // Exact date/time match
                if (isset($mapping_data[$date_time_key])) {
                    BRCC_Helpers::log_info("Found exact date+time match: Product ID {$base_product_id} for Date: {$date}, Time: {$time}");
                    return $base_product_id;
                }
                
                // Try with time buffer (for slight time differences)
                foreach ($mapping_data as $mapped_date_time => $specific_mapping) {
                    if (strpos($mapped_date_time, $date . '_') === 0) {  // Key starts with the date
                        $stored_time = substr($mapped_date_time, strlen($date) + 1);  // Extract time part
                        if (BRCC_Helpers::is_time_close($time, $stored_time, 60)) { // 60 minute buffer
                            BRCC_Helpers::log_info("Found date+time (buffer) match: Product ID {$base_product_id} for Date: {$date}, Time: {$time}");
                            return $base_product_id;
                        }
                    }
                }
            }
        }
    }
    
    // SECOND PRIORITY: Find by event ID if provided
    if ($event_id) {
        foreach ($this->all_mappings as $product_id_key => $mapping_data) {
            // Skip "_dates" entries for this check
            if (strpos($product_id_key, '_dates') === false && is_numeric($product_id_key)) {
                $product_id = (int) $product_id_key;
                
                // Check if this product has the matching event ID
                if (isset($mapping_data['eventbrite_event_id']) && $mapping_data['eventbrite_event_id'] == $event_id) {
                    BRCC_Helpers::log_info("Found event ID match: Product ID {$product_id} for Event ID: {$event_id}");
                    return $product_id;
                }
            }
        }
        
        // Also check date-specific mappings for event ID
        foreach ($this->all_mappings as $product_id_key => $mapping_data) {
            if (strpos($product_id_key, '_dates') !== false) {
                $base_product_id = (int) str_replace('_dates', '', $product_id_key);
                
                foreach ($mapping_data as $date_time_key => $specific_mapping) {
                    if (isset($specific_mapping['eventbrite_event_id']) && $specific_mapping['eventbrite_event_id'] == $event_id) {
                        BRCC_Helpers::log_info("Found event ID match in date mapping: Product ID {$base_product_id} for Event ID: {$event_id}");
                        return $base_product_id;
                    }
                }
            }
        }
    }
    
    // Log failure with all possible information
    BRCC_Helpers::log_warning("No mapping found in database. Event ID: {$event_id}, Date: {$date}, Time: {$time}");
    return null;
}
/**
     * Find the Eventbrite Ticket Class ID for a WooCommerce Product ID, potentially matching date/time.
     *
     * @param int $product_id WooCommerce Product ID.
     * @param string|null $date Optional date (Y-m-d).
     * @param string|null $time Optional time (H:i).
     * @return string|null Eventbrite Ticket Class ID or null if not found.
     */
    private function get_eventbrite_ticket_id_for_product($product_id, $date = null, $time = null) {
        BRCC_Helpers::log_debug("get_eventbrite_ticket_id_for_product: Searching for Product ID: {$product_id}, Date: {$date}, Time: {$time}");
        $this->load_mappings(); // Ensure mappings are loaded

        // 1. Check date-specific mappings first if date/time provided
        if ($date) {
            $date_key = $product_id . '_dates';
            if (isset($this->all_mappings[$date_key])) {
                $date_time_key = $date . '_' . ($time ? $time : 'any'); // Use 'any' if time is null

                // Try exact match (or date with 'any' time)
                if (isset($this->all_mappings[$date_key][$date_time_key]['eventbrite_ticket_id'])) {
                    BRCC_Helpers::log_info("Found exact date/time match for ticket ID: Product ID {$product_id}, Date: {$date}, Time: {$time}");
                    return $this->all_mappings[$date_key][$date_time_key]['eventbrite_ticket_id'];
                }
                
                // Try matching date with time buffer if specific time provided
                if ($time) {
                     foreach ($this->all_mappings[$date_key] as $mapped_date_time => $specific_mapping) {
                         if (strpos($mapped_date_time, $date . '_') === 0) { // Key starts with the date
                             $stored_time = substr($mapped_date_time, strlen($date) + 1);
                             if (isset($specific_mapping['eventbrite_ticket_id']) && BRCC_Helpers::is_time_close($time, $stored_time)) {
                                 BRCC_Helpers::log_info("Found date+time (buffer) match for ticket ID: Product ID {$product_id}, Date: {$date}, Time: {$time}");
                                 return $specific_mapping['eventbrite_ticket_id'];
                             }
                         }
                     }
                }
                
                // Fallback: Check if there's an 'any' time entry for the date
                 if (isset($this->all_mappings[$date_key][$date . '_any']['eventbrite_ticket_id'])) {
                     BRCC_Helpers::log_info("Found 'any' time match for ticket ID: Product ID {$product_id}, Date: {$date}");
                     return $this->all_mappings[$date_key][$date . '_any']['eventbrite_ticket_id'];
                 }
            }
        }

        // 2. Check general product mapping
        if (isset($this->all_mappings[$product_id]['eventbrite_ticket_id'])) {
            BRCC_Helpers::log_info("Found general mapping for ticket ID: Product ID {$product_id}");
            return $this->all_mappings[$product_id]['eventbrite_ticket_id'];
        }

        BRCC_Helpers::log_warning("No Eventbrite Ticket ID mapping found for Product ID: {$product_id}, Date: {$date}, Time: {$time}");
        return null;
    }

    /**
     * Load mappings if not already loaded.
     * Placeholder for potential future optimization.
     */
    private function load_mappings() {
        if (!isset($this->all_mappings)) {
             $this->all_mappings = $this->product_mappings->get_all_mappings();
        }
    }
/**
 * Process Eventbrite order details from webhook
 *
 * @param array $order_details The complete order details from Eventbrite API
 * @return boolean Success status
 */

public function process_eventbrite_order($order_details, $request) {
    if (empty($order_details) || !isset($order_details['event'])) {
        BRCC_Helpers::log_error('process_eventbrite_order: Invalid or missing event data in order details.', ['order_details' => $order_details, 'request_body' => $request->get_body()]);
        return false;
    }

    // Extract event data from order details
    $event_id = $order_details['event']['id'] ?? null;

    // Extract date and time from the event
    $start_time = $order_details['event']['start']['local'] ?? null;
    $date = null;
    $time = null;

    if ($start_time) {
        try {
            $date_time = new DateTime($start_time);
            $date = $date_time->format('Y-m-d');
            $time = $date_time->format('H:i');
        } catch (Exception $e) {
            BRCC_Helpers::log_error('process_eventbrite_order: Error formatting date/time: ' . $e->getMessage(), ['order_details' => $order_details]);
            return false;
        }
    }

    BRCC_Helpers::log_info("process_eventbrite_order: Processing event ID: {$event_id}, Date: {$date}, Time: {$time}");

    
    // Find product using our database mapping (ignoring ticket class ID)
    $product_id = $this->product_mappings->find_product_id_for_event(null, $date, $time, $event_id);

    if ($product_id) {
        // Process the order with the found product ID
        $result = $this->process_order_for_product($product_id, $order_details);
        if ($result) {
            BRCC_Helpers::log_info("process_eventbrite_order: Successfully processed order for product ID: {$product_id}");
            return true;
        } else {
            BRCC_Helpers::log_error("process_eventbrite_order: Failed to process order for product ID: {$product_id}", ['order_details' => $order_details]);
            return false;
        }
    } else {
        BRCC_Helpers::log_error("process_eventbrite_order: Could not find matching product for event. Event ID: {$event_id}, Date: {$date}, Time: {$time}");
        return false;
    }
} // <-- Add this closing brace for process_eventbrite_order

    /**
     * Process the order for a specific product ID after it's been matched.
     * Records the sale using the Sales Tracker.
     *
     * @param int $product_id The WooCommerce Product ID.
     * @param array $order_details The full order details from Eventbrite API.
     * @return boolean True if processing/recording was successful, false otherwise.
     */
    private function process_order_for_product($product_id, $order_details) {
        BRCC_Helpers::log_info('process_order_for_product: Starting processing.', ['product_id' => $product_id, 'eventbrite_order_id' => $order_details['id'] ?? 'N/A']);

        if (empty($product_id) || empty($order_details) || !isset($order_details['id'])) {
            BRCC_Helpers::log_error('process_order_for_product: Invalid input.', ['product_id' => $product_id, 'order_details_present' => !empty($order_details)]);
            return false;
        }

        // Ensure sales tracker is available
        if (!$this->sales_tracker) {
             BRCC_Helpers::log_error('process_order_for_product: Sales Tracker not initialized.');
             return false;
        }

        // --- Extract Data ---
        $source = 'eventbrite';
        $source_order_id = $order_details['id'];
        $customer_name = $order_details['name'] ?? 'N/A'; // Full name
        $customer_email = $order_details['email'] ?? 'N/A';
        $gross_amount = isset($order_details['costs']['gross']['value']) ? ($order_details['costs']['gross']['value'] / 100) : 0; // Eventbrite value is in cents
        $currency = $order_details['costs']['gross']['currency'] ?? 'CAD';

        // Calculate quantity - Sum of quantities from all attendees in the order
        $quantity = 0;
        if (isset($order_details['attendees']) && is_array($order_details['attendees'])) {
            foreach ($order_details['attendees'] as $attendee) {
                // Ensure the attendee belongs to the event we matched (though usually the order is for one event)
                if (isset($attendee['event_id']) && $attendee['event_id'] == $order_details['event_id']) {
                     $quantity += $attendee['quantity'] ?? 0;
                }
            }
        }
         if ($quantity === 0) {
             // Fallback or default if attendees structure is unexpected or quantity is zero
             $quantity = 1; // Default to 1 if calculation fails? Or log an error?
             BRCC_Helpers::log_warning('process_order_for_product: Could not determine quantity from attendees, defaulting to 1.', ['attendees' => $order_details['attendees'] ?? null]);
         }


        // Extract Event Details for richer logging
        $event_details_for_log = [];
        if (isset($order_details['event'])) {
             $event_details_for_log['name'] = $order_details['event']['name']['text'] ?? 'N/A';
             $event_details_for_log['id'] = $order_details['event']['id'] ?? 'N/A';
             if (isset($order_details['event']['start']['local'])) {
                 try {
                     $dt = new DateTime($order_details['event']['start']['local']);
                     $event_details_for_log['date'] = $dt->format('Y-m-d');
                     $event_details_for_log['time'] = $dt->format('H:i');
                 } catch (Exception $e) {
                     // Ignore formatting errors for logging
                 }
             }
        }


        // --- Record Sale ---
        try {
            $recorded = $this->sales_tracker->record_sale(
                $product_id,
                $quantity,
                $source,
                $source_order_id,
                $customer_name,
                $customer_email,
                $gross_amount,
                $currency,
                $event_details_for_log // Pass extracted event details
            );

            if ($recorded) {
                BRCC_Helpers::log_info('process_order_for_product: Sale recorded successfully.', ['product_id' => $product_id, 'eventbrite_order_id' => $source_order_id, 'quantity' => $quantity]);
                
                // Log this specific operation for the UI Log Viewer
                $log_details = sprintf(
                    __('Recorded sale for Product ID %d (Qty: %d) from Eventbrite Order %s. Customer: %s', 'brcc-inventory-tracker'),
                    $product_id,
                    $quantity,
                    $source_order_id,
                    $customer_name
                );
                BRCC_Helpers::log_operation('Eventbrite Webhook', 'Sale Recorded', $log_details);

// Immediately update WooCommerce stock
                $stock_update_note = sprintf('Eventbrite Order #%s', $source_order_id);
                $stock_updated = $this->sales_tracker->update_woocommerce_stock($product_id, $quantity, $stock_update_note);
                if ($stock_updated) {
                    BRCC_Helpers::log_info('process_order_for_product: WooCommerce stock updated successfully.', ['product_id' => $product_id, 'quantity_deducted' => $quantity]);
                    $log_details_stock = sprintf(
                        __('Triggered WooCommerce stock update for Product ID %d (Quantity: -%d) due to Eventbrite Order #%s.', 'brcc-inventory-tracker'),
                        $product_id,
                        $quantity,
                        $source_order_id
                    );
                    BRCC_Helpers::log_operation('WooCommerce Sync', 'Update Stock (from Eventbrite)', $log_details_stock);
                } else {
                    // Log if stock update failed, but don't necessarily fail the whole process
                    BRCC_Helpers::log_warning('process_order_for_product: Failed to update WooCommerce stock after Eventbrite sale recording.', ['product_id' => $product_id, 'quantity_to_deduct' => $quantity]);
                }
                return true;
            } else {
                BRCC_Helpers::log_error('process_order_for_product: Sales Tracker failed to record sale.', ['product_id' => $product_id, 'eventbrite_order_id' => $source_order_id]);
                return false;
            }
        } catch (Exception $e) {
            BRCC_Helpers::log_error('process_order_for_product: Exception during sale recording.', [
                'product_id' => $product_id,
                'eventbrite_order_id' => $source_order_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

     /**
      * Fetch full order details from Eventbrite API URL provided by webhook.
      *
      * @param string $api_url The API URL for the order.
      * @return array|WP_Error Order details array or WP_Error on failure.
      */
    private function fetch_eventbrite_order_details($api_url) {
        if (empty($this->api_token)) {
            return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
        }
        
        // Add expand parameter to get attendee and event details
        $url = add_query_arg('expand', 'attendees,event', $api_url);
        BRCC_Helpers::log_debug('fetch_eventbrite_order_details: Fetching order details from URL: ' . $url);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 20
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        BRCC_Helpers::log_debug('fetch_eventbrite_order_details: API Response', ['status_code' => $status_code, 'body' => $body ]);

        if ($status_code !== 200 || isset($body['error'])) {
             $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching order details.', 'brcc-inventory-tracker');
             if ($status_code !== 200) {
                  $error_message = sprintf(__('Eventbrite API returned status %d fetching order details.', 'brcc-inventory-tracker'), $status_code);
             }
             $error_message .= sprintf(' (URL: %s, Status Code: %d)', esc_url($api_url), $status_code);
             return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
       }

        return $body;
    }

    /**
     * Process incoming Eventbrite webhook request.
     *
     * @param WP_REST_Request $request The incoming request object.
     * @return WP_REST_Response Response object.
     */
    public function process_eventbrite_webhook(WP_REST_Request $request) {
        BRCC_Helpers::log_info('--- START process_eventbrite_webhook ---');
        
        // Get JSON payload
        $payload = $request->get_json_params();
        BRCC_Helpers::log_debug('process_eventbrite_webhook: Received payload', ['payload' => $payload]);

        // Basic validation
        if (empty($payload) || !isset($payload['config']['action']) || !isset($payload['api_url'])) {
            BRCC_Helpers::log_error('process_eventbrite_webhook: Invalid or missing payload data.', ['payload' => $payload]);
            return new WP_REST_Response(array('message' => 'Invalid payload'), 400);
        }

        // Check if it's an order placement action
        $action = $payload['config']['action'];
        if ($action !== 'order.placed') {
             BRCC_Helpers::log_info('process_eventbrite_webhook: Ignoring non-order.placed action.', ['action' => $action]);
             // Return 200 OK even if we ignore it, as Eventbrite expects a success response.
             return new WP_REST_Response(array('message' => 'Action ignored'), 200);
        }

        $api_url = $payload['api_url'];
        BRCC_Helpers::log_info('process_eventbrite_webhook: Processing order.placed action.', ['api_url' => $api_url]);

        // Fetch full order details from the provided API URL
        $order_details = $this->fetch_eventbrite_order_details($api_url);

        if (is_wp_error($order_details)) {
            BRCC_Helpers::log_error('process_eventbrite_webhook: Error fetching order details.', [
                'api_url' => $api_url,
                'error_code' => $order_details->get_error_code(),
                'error_message' => $order_details->get_error_message()
            ]);
            // Return a server error response
            return new WP_REST_Response(array('message' => 'Error fetching order details: ' . $order_details->get_error_message()), 500);
        }

// Process the fetched order details using the dedicated function
$processing_result = $this->process_eventbrite_order($order_details, $request);


        if ($processing_result) {
            BRCC_Helpers::log_info('process_eventbrite_webhook: Successfully processed webhook.', ['api_url' => $api_url]);
            return new WP_REST_Response(array('message' => 'Webhook processed successfully'), 200);
        } else {
            BRCC_Helpers::log_error('process_eventbrite_webhook: Failed to process order details.', ['api_url' => $api_url, 'order_details' => $order_details]);
            // Return a server error response if processing failed
            return new WP_REST_Response(array('message' => 'Failed to process order details'), 500);
        }
        
        BRCC_Helpers::log_info('--- END process_eventbrite_webhook ---');
    }

    /**
     * Get all events from Eventbrite for organization
     * 
     * @param string $status Event status ('live', 'draft', 'started', 'ended', 'completed', 'canceled')
     * @param int $page_size Number of events per page
     * @param bool $include_series Whether to include series parent events
     * @return array|WP_Error Array of events or WP_Error on failure
     */
    public function get_organization_events($status = 'live', $include_series = true) {
        if (empty($this->api_token)) {
            return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
        }

        // --- Caching Implementation ---
        $cache_key = 'brcc_eb_org_events_' . md5(serialize([$status, $include_series])); // Key based on parameters
        $cached_events = get_transient($cache_key);

        if (false !== $cached_events) {
            BRCC_Helpers::log_info('get_organization_events: Returning cached events.', ['status' => $status, 'include_series' => $include_series]);
            return $cached_events;
        }
        BRCC_Helpers::log_info('get_organization_events: No valid cache found, fetching from API.', ['status' => $status, 'include_series' => $include_series]);
        // --- End Caching Check ---

        $organization_id = $this->get_organization_id();
        if (is_wp_error($organization_id)) {
            BRCC_Helpers::log_error('get_organization_events: Failed to get Organization ID.', $organization_id);
            return $organization_id;
        }

        $all_events = array();
        $continuation = null;
        $page_number = 1;
        $page_size = 100; // Use a larger page size for fewer requests

        BRCC_Helpers::log_info('get_organization_events: Starting fetch loop.', ['status' => $status, 'include_series' => $include_series]);

        do {
            $url = $this->api_url . '/organizations/' . $organization_id . '/events/';
            $params = array(
                'status' => $status,
                'page_size' => $page_size,
                'expand' => 'ticket_classes' // Expand ticket classes to get capacity info
            );
            
            if ($continuation) {
                $params['continuation'] = $continuation;
            }
            
            $url = add_query_arg($params, $url);
            
            BRCC_Helpers::log_info('get_organization_events: Fetching page ' . $page_number, ['url' => $url]);

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30 // Increased timeout
            ));

            if (is_wp_error($response)) {
                BRCC_Helpers::log_error('get_organization_events: WP Error fetching events.', $response);
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code !== 200 || isset($body['error'])) {
                 $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching events.', 'brcc-inventory-tracker');
                 if ($status_code !== 200) {
                      $error_message = sprintf(__('Eventbrite API returned status %d fetching events.', 'brcc-inventory-tracker'), $status_code);
                 }
                 BRCC_Helpers::log_error('get_organization_events: API Error', array(
                     'status_code' => $status_code,
                     'error_message' => $error_message,
                     'response_body' => $body
                 ));
                 return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
            }

            if (isset($body['events']) && is_array($body['events'])) {
                foreach ($body['events'] as $event) {
                    // Filter out series parents if not requested
                    if (!$include_series && isset($event['series_id']) && $event['series_id']) {
                        continue;
                    }
                    $all_events[] = $event;
                }
            }

            // Check for continuation token
            $continuation = isset($body['pagination']['continuation']) ? $body['pagination']['continuation'] : null;
            $page_number++;

        } while ($continuation && $page_number < 10); // Add a safety limit (e.g., 10 pages)

        BRCC_Helpers::log_info('get_organization_events: Finished fetch loop. Total events fetched: ' . count($all_events));

        // --- Caching Implementation ---
        set_transient($cache_key, $all_events, HOUR_IN_SECONDS); // Cache for 1 hour
        BRCC_Helpers::log_info('get_organization_events: Stored fetched events in cache.', ['cache_key' => $cache_key]);
        // --- End Caching ---

        return $all_events;
    }

    /**
     * Get events belonging to a specific series ID
     * 
     * @param string $series_id The Eventbrite Series ID
     * @param array $statuses Array of statuses to fetch (e.g., ['live', 'started'])
     * @return array|WP_Error Array of event instances or WP_Error on failure
     */
    public function get_events_by_class_id($series_id, $statuses = ['live', 'started', 'ended', 'draft', 'canceled']) {
        if (empty($this->api_token)) {
            return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
        }
        if (empty($series_id)) {
            return new WP_Error('missing_series_id', __('Eventbrite Series ID is required.', 'brcc-inventory-tracker'));
        }

        // --- Caching Implementation ---
        $cache_key = 'brcc_eb_series_events_' . md5(serialize([$series_id, $statuses]));
        $cached_events = get_transient($cache_key);

        if (false !== $cached_events) {
            BRCC_Helpers::log_info('get_events_by_class_id: Returning cached events for series.', ['series_id' => $series_id, 'statuses' => $statuses]);
            return $cached_events;
        }
        BRCC_Helpers::log_info('get_events_by_class_id: No valid cache found, fetching from API.', ['series_id' => $series_id, 'statuses' => $statuses]);
        // --- End Caching Check ---

        $all_events = array();
        $continuation = null;
        $page_number = 1;
        $page_size = 100; // Use a larger page size

        BRCC_Helpers::log_info('get_events_by_class_id: Starting fetch loop for series.', ['series_id' => $series_id, 'statuses' => $statuses]);

        do {
            $url = $this->api_url . '/series/' . $series_id . '/events/';
            $params = array(
                'status' => implode(',', $statuses), // Comma-separated list of statuses
                'page_size' => $page_size,
                'expand' => 'ticket_classes' // Expand ticket classes
            );

            if ($continuation) {
                $params['continuation'] = $continuation;
            }

            $url = add_query_arg($params, $url);
            
            BRCC_Helpers::log_info('get_events_by_class_id: Fetching page ' . $page_number, ['url' => $url]);

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30 // Increased timeout
            ));

            if (is_wp_error($response)) {
                BRCC_Helpers::log_error('get_events_by_class_id: WP Error fetching events for series.', $response);
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code !== 200 || isset($body['error'])) {
                 $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching series events.', 'brcc-inventory-tracker');
                 if ($status_code !== 200) {
                      $error_message = sprintf(__('Eventbrite API returned status %d fetching series events.', 'brcc-inventory-tracker'), $status_code);
                 }
                 BRCC_Helpers::log_error('get_events_by_class_id: API Error', array(
                     'series_id' => $series_id,
                     'status_code' => $status_code,
                     'error_message' => $error_message,
                     'response_body' => $body
                 ));
                 return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
            }

            if (isset($body['events']) && is_array($body['events'])) {
                $all_events = array_merge($all_events, $body['events']);
            }

            // Check for continuation token
            $continuation = isset($body['pagination']['continuation']) ? $body['pagination']['continuation'] : null;
            $page_number++;

        } while ($continuation && $page_number < 10); // Safety limit

        BRCC_Helpers::log_info('get_events_by_class_id: Finished fetch loop. Total events fetched: ' . count($all_events));

        // --- Caching Implementation ---
        set_transient($cache_key, $all_events, HOUR_IN_SECONDS); // Cache for 1 hour
        BRCC_Helpers::log_info('get_events_by_class_id: Stored fetched series events in cache.', ['cache_key' => $cache_key]);
        // --- End Caching ---

        return $all_events;
    }

    /**
     * Get Eventbrite Organization ID
     */
    public function get_organization_id() {
        // --- Caching Implementation ---
        $cache_key = 'brcc_eb_org_id_' . md5($this->api_token); // Key based on token
        $cached_org_id = get_transient($cache_key);

        if (false !== $cached_org_id) {
            BRCC_Helpers::log_info('get_organization_id: Returning cached Organization ID.');
            return $cached_org_id;
        }
        BRCC_Helpers::log_info('get_organization_id: No valid cache found, fetching from API.');
        // --- End Caching Check ---

        $user_info = $this->get_user_info();
        
        if (is_wp_error($user_info)) {
            return $user_info;
        }
        
        if (isset($user_info['organizations'][0]['id'])) {
            $org_id = $user_info['organizations'][0]['id'];
            // --- Caching Implementation ---
            set_transient($cache_key, $org_id, DAY_IN_SECONDS); // Cache for 1 day
            BRCC_Helpers::log_info('get_organization_id: Stored fetched Organization ID in cache.', ['cache_key' => $cache_key]);
            // --- End Caching ---
            return $org_id;
        }
        
        return new WP_Error('org_id_not_found', __('Could not determine Eventbrite Organization ID.', 'brcc-inventory-tracker'));
    }

    /**
     * Get Eventbrite User Info (includes organization ID)
     */
    public function get_user_info() {
        if (empty($this->api_token)) {
            return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
        }
        
        $url = $this->api_url . '/users/me/?expand=organizations';
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 20
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200 || isset($body['error'])) {
             $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching user info.', 'brcc-inventory-tracker');
             if ($status_code !== 200) {
                  $error_message = sprintf(__('Eventbrite API returned status %d fetching user info.', 'brcc-inventory-tracker'), $status_code);
             }
             return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
        }
        
        return $body;
    }
    
    /**
     * Get user orders from Eventbrite
     * 
     * @param int $page Page number
     * @param int $page_size Number of orders per page
     * @return array|WP_Error Array of orders or WP_Error on failure
     */
    public function get_user_orders($page = 1, $page_size = 50) {
        if (empty($this->api_token)) {
            return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
        }
        
        $url = $this->api_url . '/users/me/orders/';
        $params = array(
            'page' => $page,
            'page_size' => $page_size,
            'expand' => 'event,attendees' // Expand event and attendee details
        );
        
        $url = add_query_arg($params, $url);
        
        BRCC_Helpers::log_info('get_user_orders: Fetching page ' . $page, ['url' => $url]);

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30 // Increased timeout
        ));

        if (is_wp_error($response)) {
            BRCC_Helpers::log_error('get_user_orders: WP Error fetching orders.', $response);
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200 || isset($body['error'])) {
             $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching orders.', 'brcc-inventory-tracker');
             if ($status_code !== 200) {
                  $error_message = sprintf(__('Eventbrite API returned status %d fetching orders.', 'brcc-inventory-tracker'), $status_code);
             }
             BRCC_Helpers::log_error('get_user_orders: API Error', array(
                 'status_code' => $status_code,
                 'error_message' => $error_message,
                 'response_body' => $body
             ));
             return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
        }

        return $body; // Return the full response including pagination info
    }

    /**
     * Get events for a specific day of the week
     * 
     * @param string $day_name Day name (e.g., 'Monday')
     * @param string $status Event status
     * @return array|WP_Error Array of events or WP_Error on failure
     */
    public function get_events_by_day($day_name, $status = 'live') {
        $organization_id = $this->get_organization_id();
        if (is_wp_error($organization_id)) {
            return $organization_id;
        }
        
        $all_events = $this->get_organization_events($status);
        if (is_wp_error($all_events)) {
            return $all_events;
        }
        
        $events_for_day = array();
        $day_name_lower = strtolower($day_name);
        
        foreach ($all_events as $event) {
            if (isset($event['start']['local'])) {
                try {
                    $event_date = new DateTime($event['start']['local']);
                    if (strtolower($event_date->format('l')) === $day_name_lower) {
                        $events_for_day[] = $event;
                    }
                } catch (Exception $e) {
                    // Ignore events with invalid dates
                }
            }
        }
        
        return $events_for_day;
    }

    /**
     * Suggest Eventbrite Event/Ticket IDs based on product, date, and time
     * 
     * @param WC_Product $product WooCommerce product object
     * @param string $date Date in Y-m-d format
     * @param string|null $time Time in H:i format (optional)
     * @param string|null $product_sku Product SKU (optional, for better matching)
     * @return array Array of suggestions (event_id, event_name, ticket_id, ticket_name, capacity, start_time)
     */
    public function suggest_eventbrite_ids_for_product($product, $date, $time = null, $product_sku = null) { // Added $product_sku
        if (!$product) {
            return array();
        }
        
        $product_name = $product->get_name();
        $product_id = $product->get_id();
        if (!$product_sku) { // Get SKU if not provided
            $product_sku = $product->get_sku();
        }
        
        BRCC_Helpers::log_info('suggest_eventbrite_ids_for_product: Starting suggestion process.', [
            'product_id' => $product_id, 'product_name' => $product_name, 'sku' => $product_sku, 'date' => $date, 'time' => $time
        ]);

        // 1. Get all 'live' events for the organization (cached)
        $live_events = $this->get_organization_events('live');
        if (is_wp_error($live_events)) {
            BRCC_Helpers::log_error('suggest_eventbrite_ids_for_product: Error fetching live events.', $live_events);
            return array();
        }
        
        $suggestions = array();
        $target_datetime = null;
        if ($date) {
            try {
                $target_datetime = new DateTime($date . ' ' . ($time ? $time : '00:00:00'), wp_timezone());
            } catch (Exception $e) {
                BRCC_Helpers::log_warning('suggest_eventbrite_ids_for_product: Invalid date/time provided.', ['date' => $date, 'time' => $time]);
                return array(); // Invalid date/time format
            }
        }

        BRCC_Helpers::log_info('suggest_eventbrite_ids_for_product: Processing ' . count($live_events) . ' live events.');

        foreach ($live_events as $event) {
            $event_id = $event['id'];
            $event_name = isset($event['name']['text']) ? $event['name']['text'] : 'N/A';
            $event_start_str = isset($event['start']['local']) ? $event['start']['local'] : null;
            $event_datetime = null;

            if ($event_start_str) {
                try {
                    $event_datetime = new DateTime($event_start_str, wp_timezone());
                } catch (Exception $e) {
                    // Ignore events with invalid start dates
                    continue;
                }
            }

            // --- Matching Logic ---
            $match_score = 0;
            $match_reasons = [];

            // a. Date/Time Match (if target date provided)
            if ($target_datetime && $event_datetime) {
                $date_matches = ($target_datetime->format('Y-m-d') === $event_datetime->format('Y-m-d'));
                if ($date_matches) {
                    $match_score += 5; // High score for date match
                    $match_reasons[] = 'Date Match';
                    if ($time) {
                        // Check if time is close (within buffer)
                        if (BRCC_Helpers::is_time_close($target_datetime->format('H:i'), $event_datetime->format('H:i'))) {
                            $match_score += 5; // Extra score for close time
                            $match_reasons[] = 'Time Match (Close)';
                        }
                    }
                } else {
                    // If date doesn't match, skip this event entirely if a date was specified
                     continue;
                }
            } elseif ($target_datetime && !$event_datetime) {
                 // If target date provided but event has no date, skip
                 continue;
            }

            // b. Name Match (partial match on product name)
            if (stripos($event_name, $product_name) !== false) {
                $match_score += 3;
                $match_reasons[] = 'Name Match (Product Name)';
            }
            
            // c. SKU Match (if SKU exists in event name)
            if (!empty($product_sku) && stripos($event_name, $product_sku) !== false) {
                 $match_score += 2; // Lower score than name, but still relevant
                 $match_reasons[] = 'SKU Match';
            }

            // --- Process Ticket Classes if Match Score is High Enough ---
            if ($match_score > 0 && isset($event['ticket_classes']) && is_array($event['ticket_classes'])) {
                 BRCC_Helpers::log_debug('suggest_eventbrite_ids_for_product: Potential match found.', [
                     'event_id' => $event_id, 'event_name' => $event_name, 'score' => $match_score, 'reasons' => $match_reasons
                 ]);

                foreach ($event['ticket_classes'] as $ticket) {
                    $ticket_id = $ticket['id'];
                    $ticket_name = isset($ticket['name']) ? $ticket['name'] : 'N/A';
                    $capacity = isset($ticket['capacity']) ? $ticket['capacity'] : 'N/A';
                    
                    // Add ticket-level matching if needed (e.g., ticket name vs product name/variation)
                    $ticket_match_score = $match_score; // Inherit event score
                    if (stripos($ticket_name, $product_name) !== false) {
                         $ticket_match_score += 1; // Small boost if ticket name also matches
                         $match_reasons[] = 'Name Match (Ticket Name)';
                    }

                    $suggestions[] = array(
                        'event_id' => $event_id,
                        'event_name' => $event_name,
                        'ticket_id' => $ticket_id,
                        'ticket_name' => $ticket_name,
                        'capacity' => $capacity,
                        'start_time' => $event_datetime ? $event_datetime->format('Y-m-d H:i:s') : 'N/A',
                        'match_score' => $ticket_match_score,
                        'match_reasons' => implode(', ', $match_reasons) // Add reasons for clarity
                    );
                }
            }
        }
        
        // Sort suggestions by match score (descending)
        usort($suggestions, function($a, $b) {
            return $b['match_score'] <=> $a['match_score'];
        });

        BRCC_Helpers::log_info('suggest_eventbrite_ids_for_product: Found ' . count($suggestions) . ' suggestions.');
        // Limit the number of suggestions returned
        return array_slice($suggestions, 0, 10); 
    }

    /**
     * Test connection to a specific Eventbrite ticket via event endpoint
     */
    public function test_ticket_via_event($event_id, $ticket_id) {
        if (empty($event_id) || empty($ticket_id)) {
            return new WP_Error('missing_ids', __('Event ID and Ticket ID are required.', 'brcc-inventory-tracker'));
        }
        
        $url = $this->api_url . '/events/' . $event_id . '/ticket_classes/' . $ticket_id . '/';
        BRCC_Helpers::log_debug('test_ticket_via_event: Testing URL: ' . $url); // Restored debug log
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 20
        ));
        
        if (is_wp_error($response)) {
            BRCC_Helpers::log_error('test_ticket_via_event: WP Error', $response);
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        BRCC_Helpers::log_debug('test_ticket_via_event: API Response', array('status_code' => $status_code, 'body' => $body)); // Restored debug log
        
        if ($status_code !== 200) {
            $error_message = isset($body['error_description']) ? $body['error_description'] : sprintf(__('Eventbrite API returned status %d.', 'brcc-inventory-tracker'), $status_code);
            return new WP_Error('api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
        }
        
        // Success if we get a 200 response
        return true;
    }

    /**
     * Test if an Eventbrite event exists by fetching its details
     */
    public function test_event_exists($event_id) {
        if (empty($event_id)) {
            return new WP_Error('missing_id', __('Event ID is required.', 'brcc-inventory-tracker'));
        }

        BRCC_Helpers::log_debug('test_event_exists: Testing Event ID: ' . $event_id); // Restored debug log
        $event_details = $this->get_eventbrite_event($event_id);

        if (is_wp_error($event_details)) {
            BRCC_Helpers::log_error('test_event_exists: Failed to fetch event details.', array(
                'event_id' => $event_id,
                'error_code' => $event_details->get_error_code(),
                'error_message' => $event_details->get_error_message()
            ));
            // Return the WP_Error object
            return $event_details;
        }

        // If we didn't get an error, the event exists
        BRCC_Helpers::log_debug('test_event_exists: Event found.', array('event_id' => $event_id)); // Restored debug log
        return true;
    }

    /**
     * Get Eventbrite ticket information
     * Note: This endpoint seems unreliable in Eventbrite's API (often 404).
     * Prefer fetching event details with ticket_classes expanded.
     */
    public function get_eventbrite_ticket($ticket_id) {
        // Prepare API request
        $url = $this->api_url . '/ticket_classes/' . $ticket_id . '/';
        
        // Add log at the start of the function
        BRCC_Helpers::log_debug('get_eventbrite_ticket: Function entered', array('ticket_id' => $ticket_id, 'url' => $url)); // Restored debug log
        // Debug log removed in previous step
        
        // Temporarily increase resources for this specific call
        @ini_set('memory_limit', '256M');
        @set_time_limit(60); // Set execution time limit to 60 seconds for this script run

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30 // Increase request timeout slightly more
        ));
        

        if (is_wp_error($response)) { // Original check remains
            // Keep the original helper log just in case
            BRCC_Helpers::log_error(
                'get_eventbrite_ticket: wp_remote_get failed',
                array(
                    'ticket_id' => $ticket_id,
                    'url' => $url,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message()
                )
            );
            return $response; // Return the WP_Error
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Log the full response for debugging
        BRCC_Helpers::log_debug('get_eventbrite_ticket: API Response', array(
            'ticket_id' => $ticket_id,
            'status_code' => $status_code,
            'body' => $body
        ));

        // Check for API errors or non-200 status codes
        if ($status_code !== 200 || isset($body['error'])) {
            $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error', 'brcc-inventory-tracker');
            if ($status_code === 404) {
                $error_message = __('Eventbrite Ticket Class not found (404).', 'brcc-inventory-tracker');
            } elseif ($status_code !== 200) {
                 $error_message = sprintf(__('Eventbrite API returned status %d.', 'brcc-inventory-tracker'), $status_code);
            }
            
            // Log the error
            BRCC_Helpers::log_error('get_eventbrite_ticket: API Error', array(
                'ticket_id' => $ticket_id,
                'status_code' => $status_code,
                'error_message' => $error_message,
                'response_body' => $body // Include response body for context
            ));
            
            return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
        }
        
        // Return the decoded body on success
        return $body;
    }

    /**
     * Get Eventbrite event information
     */
    public function get_eventbrite_event($event_id) {
        // Prepare API request
        $url = $this->api_url . '/events/' . $event_id . '/?expand=ticket_classes'; // Expand ticket classes
        
        // Add log at the start of the function
        BRCC_Helpers::log_debug('get_eventbrite_event: Function entered', array('event_id' => $event_id, 'url' => $url)); // Restored debug log
        // Debug log removed in previous step
        
        // Temporarily increase resources for this specific call
        @ini_set('memory_limit', '256M');
        @set_time_limit(60); // Set execution time limit to 60 seconds for this script run

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_token,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 30 // Increase request timeout slightly more
        ));
        

        if (is_wp_error($response)) { // Original check remains
            // Keep the original helper log just in case
            BRCC_Helpers::log_error(
                'get_eventbrite_event: wp_remote_get failed',
                array(
                    'event_id' => $event_id,
                    'url' => $url,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message()
                )
            );
            return $response; // Return the WP_Error
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Log the full response for debugging
        BRCC_Helpers::log_debug('get_eventbrite_event: API Response', array(
            'event_id' => $event_id,
            'status_code' => $status_code,
            'body' => $body
        ));

        // Check for API errors or non-200 status codes
        if ($status_code !== 200 || isset($body['error'])) {
            $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error', 'brcc-inventory-tracker');
            if ($status_code === 404) {
                $error_message = __('Eventbrite Event not found (404).', 'brcc-inventory-tracker');
            } elseif ($status_code !== 200) {
                 $error_message = sprintf(__('Eventbrite API returned status %d.', 'brcc-inventory-tracker'), $status_code);
            }
            
            // Log the error
            BRCC_Helpers::log_error('get_eventbrite_event: API Error', array(
                'event_id' => $event_id,
                'status_code' => $status_code,
                'error_message' => $error_message,
                'response_body' => $body // Include response body for context
            ));
            
            return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
        }
        
        // Return the decoded body on success
        return $body;
    }

    /**
     * Get events for a specific date
     * 
     * @param string $date Date in Y-m-d format
     * @param string $status Comma-separated list of event statuses
     * @return array|WP_Error Array of events or WP_Error on failure
     */
    public function get_events_for_date($date, $status = 'live,started') { // Default to live and started events for a specific date
        $organization_id = $this->get_organization_id();
        if (is_wp_error($organization_id)) {
            return $organization_id;
        }
        
        // --- Caching Implementation ---
        $cache_key = 'brcc_eb_events_for_date_' . md5(serialize([$date, $status]));
        $cached_events = get_transient($cache_key);

        if (false !== $cached_events) {
            BRCC_Helpers::log_info('get_events_for_date: Returning cached events.', ['date' => $date, 'status' => $status]);
            return $cached_events;
        }
        BRCC_Helpers::log_info('get_events_for_date: No valid cache found, fetching from API.', ['date' => $date, 'status' => $status]);
        // --- End Caching Check ---

        $all_events = array();
        $continuation = null;
        $page_number = 1;
        $page_size = 100; // Use a larger page size

        // Format date range for Eventbrite API (start and end of the target day in UTC)
        try {
            $start_date_obj = new DateTime($date . ' 00:00:00', wp_timezone());
            $end_date_obj = new DateTime($date . ' 23:59:59', wp_timezone());
            
            // Convert to UTC for Eventbrite API query
            $start_date_obj->setTimezone(new DateTimeZone('UTC'));
            $end_date_obj->setTimezone(new DateTimeZone('UTC'));
            
            $start_date_utc = $start_date_obj->format('Y-m-d\TH:i:s\Z');
            $end_date_utc = $end_date_obj->format('Y-m-d\TH:i:s\Z');
            
        } catch (Exception $e) {
            return new WP_Error('invalid_date', __('Invalid date format provided.', 'brcc-inventory-tracker'));
        }

        BRCC_Helpers::log_info('get_events_for_date: Starting fetch loop.', ['date' => $date, 'status' => $status, 'start_utc' => $start_date_utc, 'end_utc' => $end_date_utc]);

        do {
            $url = $this->api_url . '/organizations/' . $organization_id . '/events/';
            $params = array(
                'status' => $status,
                'start_date.range_start' => $start_date_utc,
                'start_date.range_end' => $end_date_utc,
                'page_size' => $page_size,
                'expand' => 'ticket_classes' // Expand ticket classes
            );
            
            if ($continuation) {
                $params['continuation'] = $continuation;
            }
            
            $url = add_query_arg($params, $url);
            
            BRCC_Helpers::log_info('get_events_for_date: Fetching page ' . $page_number, ['url' => $url]);

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30 // Increased timeout
            ));

            if (is_wp_error($response)) {
                BRCC_Helpers::log_error('get_events_for_date: WP Error fetching events.', $response);
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code !== 200 || isset($body['error'])) {
                 $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching events for date.', 'brcc-inventory-tracker');
                 if ($status_code !== 200) {
                      $error_message = sprintf(__('Eventbrite API returned status %d fetching events for date.', 'brcc-inventory-tracker'), $status_code);
                 }
                 BRCC_Helpers::log_error('get_events_for_date: API Error', array(
                     'date' => $date,
                     'status_code' => $status_code,
                     'error_message' => $error_message,
                     'response_body' => $body
                 ));
                 return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
            }

            if (isset($body['events']) && is_array($body['events'])) {
                $all_events = array_merge($all_events, $body['events']);
            }

            // Check for continuation token
            $continuation = isset($body['pagination']['continuation']) ? $body['pagination']['continuation'] : null;
            $page_number++;

        } while ($continuation && $page_number < 10); // Safety limit

        BRCC_Helpers::log_info('get_events_for_date: Finished fetch loop. Total events fetched: ' . count($all_events));

        // --- Caching Implementation ---
        set_transient($cache_key, $all_events, HOUR_IN_SECONDS); // Cache for 1 hour
        BRCC_Helpers::log_info('get_events_for_date: Stored fetched events in cache.', ['cache_key' => $cache_key]);
        // --- End Caching ---

        return $all_events;
    }

    /**
     * Convert UTC timestamp to Toronto time (H:i format)
     */
    private function convert_to_toronto_time($utc_timestamp) {
        try {
            $dt = new DateTime($utc_timestamp, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone(BRCC_Constants::TORONTO_TIMEZONE));
            return $dt->format('H:i');
        } catch (Exception $e) {
            return null; // Return null on error
        }
    }
    
    /**
     * Extract date from order meta
     * 
     * @param int $order_id Order ID
     * @param int $product_id Product ID
     * @return string|null Date in Y-m-d format or null
     */
    // private function extract_date_from_order($order_id, $product_id) {
    //     // Check FooEvents first
    //     if (BRCC_Helpers::is_fooevents_active()) {
    //         $fooevents_date = get_post_meta($order_id, 'WooCommerceEventsDate', true);
    //         if ($fooevents_date) {
    //             return BRCC_Helpers::parse_date_value($fooevents_date);
    //         }
    //     }
        
    //     // Check other common meta keys
    //     $order = wc_get_order($order_id);
    //     if ($order) {
    //         foreach ($order->get_items() as $item) {
    //             if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
    //                 $date = BRCC_Helpers::get_booking_date_from_item($item);
    //                 if ($date) return $date;
    //             }
    //         }
    //     }
        
    //     return null;
    // }

    /**
     * Extract time from order meta
     * 
     * @param int $order_id Order ID
     * @param int $product_id Product ID
     * @return string|null Time in H:i format or null
     */
    private function extract_time_from_order($order_id, $product_id) {
        // Check FooEvents first
        if (BRCC_Helpers::is_fooevents_active()) {
            $fooevents_time = get_post_meta($order_id, 'WooCommerceEventsHour', true);
            if ($fooevents_time) {
                $minute = get_post_meta($order_id, 'WooCommerceEventsMinutes', true);
                $ampm = get_post_meta($order_id, 'WooCommerceEventsPeriod', true);
                return BRCC_Helpers::parse_time_value($fooevents_time . ':' . $minute . ' ' . $ampm);
            }
        }
        
        // Check other common meta keys
        $order = wc_get_order($order_id);
        if ($order) {
            foreach ($order->get_items() as $item) {
                if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                    $time = BRCC_Helpers::extract_booking_time_from_item($item); // Use the new helper
                    if ($time) return $time;
                }
            }
        }
        
        return null;
    }

    /**
     * Update Eventbrite ticket based on WooCommerce order (Original - potentially deprecated)
     */
    public function update_eventbrite_ticket($order_id, $order) {
        // This function might be deprecated in favor of update_eventbrite_ticket_with_date
        // Or potentially used as a fallback if date/time cannot be determined
        BRCC_Helpers::log_warning('update_eventbrite_ticket called (potentially deprecated)', ['order_id' => $order_id]);
        
        // Example: Loop through items and call update without date/time
        // foreach ($order->get_items() as $item) {
        //     $product_id = $item->get_product_id();
        //     $quantity = $item->get_quantity();
        //     // Find mapping without date/time
        //     // Call update function
        // }
    }

    /**
     * Extract booking time from order item meta
     * 
     * @param WC_Order_Item $item Order item
     * @return string|null Time in H:i format or null
     */
    private function extract_booking_time($item) {
        // Check FooEvents first
        if (BRCC_Helpers::is_fooevents_active()) {
            $fooevents_time = $item->get_meta('WooCommerceEventsHour', true);
            if ($fooevents_time) {
                $minute = $item->get_meta('WooCommerceEventsMinutes', true);
                $ampm = $item->get_meta('WooCommerceEventsPeriod', true);
                return BRCC_Helpers::parse_time_value($fooevents_time . ':' . ($minute ?? '00') . ' ' . $ampm);
            }
        }
        
        // Check other common meta keys
        $time_meta_keys = array('event_time', 'ticket_time', 'booking_time', 'pa_time', 'time', '_event_time', '_booking_time');
        foreach ($time_meta_keys as $key) {
            $time_value = $item->get_meta($key, true);
            if ($time_value) {
                $parsed_time = BRCC_Helpers::parse_time_value($time_value);
                if ($parsed_time) return $parsed_time;
            }
        }
        
        // Check all meta as fallback
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            if (preg_match('/time/i', $data['key'])) {
                $parsed_time = BRCC_Helpers::parse_time_value($data['value']);
                if ($parsed_time) return $parsed_time;
            }
        }
        
        return null;
    }

    /**
     * Extract booking date from order item meta
     * 
     * @param WC_Order_Item $item Order item
     * @return string|null Date in Y-m-d format or null
     */
    private function extract_booking_date($item) {
        // Check FooEvents first
        if (BRCC_Helpers::is_fooevents_active()) {
            $fooevents_date = $item->get_meta('WooCommerceEventsDate', true);
            if ($fooevents_date) {
                return BRCC_Helpers::parse_date_value($fooevents_date);
            }
        }
        
        // Check other common meta keys
        $date_meta_keys = array('event_date', 'ticket_date', 'booking_date', 'pa_date', 'date', '_event_date', '_booking_date');
        foreach ($date_meta_keys as $key) {
            $date_value = $item->get_meta($key, true);
            if ($date_value) {
                $parsed_date = BRCC_Helpers::parse_date_value($date_value);
                if ($parsed_date) return $parsed_date;
            }
        }
        
        // Check all meta as fallback
        foreach ($item->get_meta_data() as $meta) {
            $data = $meta->get_data();
            if (preg_match('/date/i', $data['key'])) {
                $parsed_date = BRCC_Helpers::parse_date_value($data['value']);
                if ($parsed_date) return $parsed_date;
            }
        }
        
        return null;
    }

    /**
     * Sync inventory based on Eventbrite attendee data (called by cron or manually)
     * This function aims to reconcile stock based on actual attendees fetched from Eventbrite.
     * 
     * @param bool $manual_daily_sync Flag indicating if this is the manual daily sync run.
     */
    public function sync_eventbrite_tickets($manual_daily_sync = false) {
        BRCC_Helpers::log_info('--- START sync_eventbrite_tickets ---', ['manual_daily_sync' => $manual_daily_sync]);
        
        // Get all product mappings
        $this->load_mappings();
        $mappings = $this->all_mappings;
        
        if (empty($mappings)) {
            BRCC_Helpers::log_warning('sync_eventbrite_tickets: No product mappings found. Aborting sync.');
            BRCC_Helpers::log_info('--- END sync_eventbrite_tickets (No Mappings) ---');
            return;
        }

        $sales_tracker = new BRCC_Sales_Tracker(); // Instantiate sales tracker

        // Iterate through each mapped product
        foreach ($mappings as $product_id_key => $mapping_data) {
            
            // Handle date-specific mappings
            if (strpos($product_id_key, '_dates') !== false) {
                $wc_product_id = (int) str_replace('_dates', '', $product_id_key);
                
                foreach ($mapping_data as $date_time_key => $specific_mapping) {
                    if (isset($specific_mapping['eventbrite_event_id'])) {
                        $event_id = $specific_mapping['eventbrite_event_id'];
                        list($date, $time) = explode('_', $date_time_key); // Extract date/time from key
                        
                        BRCC_Helpers::log_info('sync_eventbrite_tickets: Processing date-specific mapping.', [
                            'wc_product_id' => $wc_product_id, 'event_id' => $event_id, 'date' => $date, 'time' => $time
                        ]);
                        
                        // Fetch attendees for this specific event
                        $attendees_data = $this->get_event_attendees($event_id);
                        
                        if (!is_wp_error($attendees_data) && isset($attendees_data['attendees'])) {
                            $quantity_sold = count($attendees_data['attendees']); // Simple count for now
                            
                            BRCC_Helpers::log_info('sync_eventbrite_tickets: Fetched attendees.', [
                                'event_id' => $event_id, 'attendee_count' => $quantity_sold
                            ]);

                            // Update WooCommerce stock based on attendee count
                            // Note: This overwrites current stock, might need adjustment based on desired logic
                            // $sales_tracker->update_woocommerce_stock($wc_product_id, $quantity_sold, 'Eventbrite Sync (Attendee Count)');
                            // TODO: Re-evaluate stock update logic. Should it SET stock or DECREMENT?
                            // For now, let's assume we need to record the sale difference if not already recorded by webhook.
                            // This part needs careful consideration of how webhooks and sync interact.
                            // Perhaps just log the discrepancy for now?
                             BRCC_Helpers::log_warning('sync_eventbrite_tickets: Attendee count based stock update logic needs review.', [
                                 'wc_product_id' => $wc_product_id, 'event_id' => $event_id, 'attendee_count' => $quantity_sold
                             ]);

                        } else {
                            $error_message = is_wp_error($attendees_data) ? $attendees_data->get_error_message() : 'Attendees key missing.';
                            BRCC_Helpers::log_error('sync_eventbrite_tickets: Failed to fetch attendees for event.', [
                                'event_id' => $event_id, 'error' => $error_message
                            ]);
                        }
                    }
                }
            } 
            // Handle general (non-date-specific) mappings
            elseif (is_numeric($product_id_key) && isset($mapping_data['eventbrite_event_id'])) {
                 $wc_product_id = (int) $product_id_key;
                 $event_id = $mapping_data['eventbrite_event_id'];

                 BRCC_Helpers::log_info('sync_eventbrite_tickets: Processing general mapping.', [
                     'wc_product_id' => $wc_product_id, 'event_id' => $event_id
                 ]);

                 // Fetch attendees for this event
                 $attendees_data = $this->get_event_attendees($event_id);

                 if (!is_wp_error($attendees_data) && isset($attendees_data['attendees'])) {
                     $quantity_sold = count($attendees_data['attendees']); // Simple count

                     BRCC_Helpers::log_info('sync_eventbrite_tickets: Fetched attendees.', [
                         'event_id' => $event_id, 'attendee_count' => $quantity_sold
                     ]);
                     
                     // Update WooCommerce stock
                     // $sales_tracker->update_woocommerce_stock($wc_product_id, $quantity_sold, 'Eventbrite Sync (Attendee Count)');
                     // TODO: Review stock update logic here as well.
                      BRCC_Helpers::log_warning('sync_eventbrite_tickets: Attendee count based stock update logic needs review.', [
                          'wc_product_id' => $wc_product_id, 'event_id' => $event_id, 'attendee_count' => $quantity_sold
                      ]);

                 } else {
                     $error_message = is_wp_error($attendees_data) ? $attendees_data->get_error_message() : 'Attendees key missing.';
                     BRCC_Helpers::log_error('sync_eventbrite_tickets: Failed to fetch attendees for event.', [
                         'event_id' => $event_id, 'error' => $error_message
                     ]);
                 }
            }
        }
        
        BRCC_Helpers::log_info('--- END sync_eventbrite_tickets ---');
    }

    /**
     * Get date/time specific inventory count from product meta
     * 
     * @param int $product_id WooCommerce Product ID
     * @param string $date Date in Y-m-d format
     * @param string|null $time Time in H:i format (optional)
     * @return int|null Inventory count or null if not found/applicable
     */
    private function get_date_time_specific_inventory($product_id, $date, $time = null) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }

        // Check if using enhanced date mappings
        $enhanced_mappings = new BRCC_Enhanced_Mappings();
        $date_inventory = $enhanced_mappings->get_inventory_for_date($product_id, $date, $time);

        if ($date_inventory !== null) {
            BRCC_Helpers::log_debug(sprintf(
                'get_date_time_specific_inventory: Found enhanced mapping inventory for Product ID %d, Date %s, Time %s: %d',
                $product_id, $date, $time ?? 'N/A', $date_inventory
            ));
            return $date_inventory;
        }

        // Fallback: Check older meta structure (less likely needed with enhanced mappings)
        $meta_key_base = '_brcc_inventory_' . $date;
        
        // Try specific time slot first
        if ($time) {
            $time_key = str_replace(':', '', $time); // Format time for key (e.g., 1400)
            $meta_key = $meta_key_base . '_' . $time_key;
            $stock = get_post_meta($product_id, $meta_key, true);
            if ($stock !== '') { // Check if meta exists, even if 0
                 BRCC_Helpers::log_debug(sprintf(
                    'get_date_time_specific_inventory: Found legacy meta inventory for Product ID %d, Date %s, Time %s: %s',
                    $product_id, $date, $time, $stock
                ));
                return intval($stock);
            }
        }
        
        // Try date-only meta if time-specific not found or no time provided
        $stock = get_post_meta($product_id, $meta_key_base, true);
        if ($stock !== '') {
             BRCC_Helpers::log_debug(sprintf(
                'get_date_time_specific_inventory: Found legacy meta inventory for Product ID %d, Date %s (no time): %s',
                $product_id, $date, $stock
            ));
            return intval($stock);
        }

        // If no date-specific inventory found, return null (or maybe product's main stock?)
        // Returning null indicates date-specific logic doesn't apply or isn't set up.
         BRCC_Helpers::log_debug(sprintf(
            'get_date_time_specific_inventory: No date-specific inventory found for Product ID %d, Date %s, Time %s. Returning null.',
            $product_id, $date, $time ?? 'N/A'
        ));
        return null; 
    }

  /**
   * Update date/time specific inventory in product meta
   * 
   * @param int $product_id WooCommerce Product ID
   * @param string $date Date in Y-m-d format
   * @param string|null $time Time in H:i format (optional)
   * @param int $quantity New inventory quantity
   * @return bool True on success, false on failure
   */
  private function update_date_time_specific_inventory($product_id, $date, $time, $quantity) {
      $product = wc_get_product($product_id);
      if (!$product) {
          BRCC_Helpers::log_error(sprintf(
              'update_date_time_specific_inventory: Product not found: %d', $product_id
          ));
          return false;
      }

      // Use Enhanced Mappings if available
      $enhanced_mappings = new BRCC_Enhanced_Mappings();
      if ($enhanced_mappings->update_inventory_for_date($product_id, $date, $time, $quantity)) {
          BRCC_Helpers::log_info(sprintf(
              'update_date_time_specific_inventory: Successfully updated enhanced mapping inventory for Product ID %d, Date %s, Time %s to %d',
              $product_id, $date, $time ?? 'N/A', $quantity
          ));
          // Trigger action after successful update
          do_action('brcc_date_inventory_updated', $product_id, $date, $time, $quantity);
          return true;
      }

      // Fallback to trying older meta structure (less likely needed)
      $meta_key_base = '_brcc_inventory_' . $date;
      $meta_key = $time ? $meta_key_base . '_' . str_replace(':', '', $time) : $meta_key_base;

      // Check if the specific key exists to decide whether to update date-only or time-specific
      $existing_meta = get_post_meta($product_id, $meta_key, true);

      if ($existing_meta !== '' || $time) { // Update the specific key if it exists OR if a time was provided
          $updated = update_post_meta($product_id, $meta_key, $quantity);
          if ($updated) {
               BRCC_Helpers::log_info(sprintf(
                  'update_date_time_specific_inventory: Successfully updated legacy meta inventory for Product ID %d, Key %s to %d',
                  $product_id, $meta_key, $quantity
              ));
              do_action('brcc_date_inventory_updated', $product_id, $date, $time, $quantity);
              return true;
          } else {
               BRCC_Helpers::log_error(sprintf(
                  'update_date_time_specific_inventory: Failed to update legacy meta inventory for Product ID %d, Key %s',
                  $product_id, $meta_key
              ));
              return false;
          }
      } elseif (!$time) { // Only update date-only key if no time was provided AND time-specific key didn't exist
           $updated = update_post_meta($product_id, $meta_key_base, $quantity);
            if ($updated) {
               BRCC_Helpers::log_info(sprintf(
                  'update_date_time_specific_inventory: Successfully updated legacy meta inventory for Product ID %d, Key %s to %d',
                  $product_id, $meta_key_base, $quantity
              ));
              do_action('brcc_date_inventory_updated', $product_id, $date, null, $quantity); // Time is null here
              return true;
          } else {
               BRCC_Helpers::log_error(sprintf(
                  'update_date_time_specific_inventory: Failed to update legacy meta inventory for Product ID %d, Key %s',
                  $product_id, $meta_key_base
              ));
              return false;
          }
      }

      // If we reach here, no suitable slot was found to update
      BRCC_Helpers::log_warning(sprintf(
          'update_date_time_specific_inventory: Could not find suitable meta key/structure to update inventory for Product ID %d, Date %s, Time %s.',
          $product_id, $date, $time ?? 'N/A'
      ));
      return false;
  }

  /**
   * Check if two times are close within a buffer
   */
  private function is_time_close($time1, $time2, $buffer_minutes = BRCC_Constants::TIME_BUFFER_MINUTES) {
      try {
          $t1 = new DateTime($time1);
          $t2 = new DateTime($time2);
          $diff = abs($t1->getTimestamp() - $t2->getTimestamp()) / 60; // Difference in minutes
          return $diff <= $buffer_minutes;
      } catch (Exception $e) {
          return false; // Error parsing dates/times
      }
  }
  /**
   * Update Eventbrite ticket capacity via API
   */
  public function update_eventbrite_ticket_capacity($ticket_id, $capacity) {
    // --- START Log ---
    BRCC_Helpers::log_info('--- START update_eventbrite_ticket_capacity ---', ['ticket_id' => $ticket_id, 'requested_capacity' => $capacity]);
    // --- END Log ---
      // --- START Log ---
      // Note: Eventbrite API v3 recommends /events/{event_id}/ticket_classes/{ticket_class_id}/
      // The current URL /ticket_classes/{id}/ might be deprecated or less reliable.
      // Consider refactoring to fetch event_id first if issues persist.
      $url = $this->api_url . '/ticket_classes/' . $ticket_id . '/';
      // Debug log removed in previous step
      // --- END Log ---
      
      // Ensure capacity is non-negative integer
      $capacity = max(0, intval($capacity)); 
      
      $data = json_encode(array(
          'ticket_class' => array(
              'capacity' => $capacity,
          ),
      ));

      BRCC_Helpers::log_info(sprintf(
          'update_eventbrite_ticket_capacity: Sending update for Ticket ID %s. New Capacity: %d',
          $ticket_id, $capacity
      ));
      // Debug log removed in previous step
      

      // Use POST for updates as per Eventbrite API v3 documentation
      // --- START Log ---
      BRCC_Helpers::log_info('update_eventbrite_ticket_capacity: Sending API request to Eventbrite...', ['url' => $url]);
      // --- END Log ---
      $response = wp_remote_post($url, array(
          'method' => 'POST', // Use POST for updates
          'headers' => array(
              'Authorization' => 'Bearer ' . $this->api_token,
              'Content-Type' => 'application/json',
          ),
          'body' => $data,
          'timeout' => 20
      ));
      // --- START Log ---
      BRCC_Helpers::log_info('update_eventbrite_ticket_capacity: Received API response from Eventbrite.');
      // --- END Log ---

      if (is_wp_error($response)) {
          BRCC_Helpers::log_error(sprintf(
              'update_eventbrite_ticket_capacity: wp_remote_post failed for Ticket ID %s. Error: %s',
              $ticket_id, $response->get_error_message()
          ));
          BRCC_Helpers::log_info('--- END update_eventbrite_ticket_capacity (WP Error) ---'); // Log exit point
          return $response;
      }

      $status_code = wp_remote_retrieve_response_code($response);
      $body = json_decode(wp_remote_retrieve_body($response), true);

      // --- START Log ---
      // Log status and body separately for clarity
      // Debug logs removed in previous step
      // Optionally log raw body if JSON decoding might fail or hide issues
      // $response_body_raw = wp_remote_retrieve_body($response);
      // BRCC_Helpers::log_debug('update_eventbrite_ticket_capacity: API Response Raw Body.', ['raw_body' => $response_body_raw]); // Keep this one potentially useful one? Or remove? Removing for now.
      // --- END Log ---

      if ($status_code !== 200 || isset($body['error'])) {
           $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error during capacity update.', 'brcc-inventory-tracker');
           if ($status_code !== 200) {
                $error_message = sprintf(__('Eventbrite API returned status %d during capacity update.', 'brcc-inventory-tracker'), $status_code);
           }
           BRCC_Helpers::log_error('update_eventbrite_ticket_capacity: API Error', array(
               'ticket_id' => $ticket_id,
               'status_code' => $status_code,
               'error_message' => $error_message,
               'response_body' => $body
           ));
           BRCC_Helpers::log_info('--- END update_eventbrite_ticket_capacity (API Error) ---'); // Log exit point
           return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
      }
      
      BRCC_Helpers::log_info('--- END update_eventbrite_ticket_capacity (Success) ---', ['ticket_id' => $ticket_id, 'new_capacity' => $capacity]); // Log exit point
      return $body; // Return response body on success
  }

  /**
   * Test connection to Eventbrite API
   */
  public function test_connection() {
      $user_info = $this->get_user_info();
      
      if (is_wp_error($user_info)) {
          return $user_info; // Return WP_Error object
      }
      
      // If we get user info without error, connection is successful
      return true;
  }

  /**
   * Get Eventbrite URLs for admin settings page
   */
  public function get_eventbrite_urls($event_id = '', $ticket_id = '') {
      $base_url = 'https://www.eventbrite.ca'; // Use .ca as requested
      
      $urls = array(
          'dashboard' => $base_url . '/manage/events/',
          'api_keys' => $base_url . '/platform/api-keys/',
          'webhooks' => $base_url . '/platform/webhooks/'
      );
      
      if (!empty($event_id)) {
          $urls['event_dashboard'] = $base_url . '/manage/events/' . $event_id . '/details';
          $urls['event_tickets'] = $base_url . '/manage/events/' . $event_id . '/tickets';
          
          if (!empty($ticket_id)) {
              // Note: Direct link to edit ticket class is complex, linking to tickets page is safer
              $urls['edit_ticket'] = $base_url . '/manage/events/' . $event_id . '/tickets'; 
          }
      }
      
      return $urls;
  }

  /**
   * Test connection to a specific Eventbrite ticket
   * 
   * @param string $ticket_id Eventbrite Ticket Class ID
   * @return bool|WP_Error True if connection successful, WP_Error otherwise
   */
  public function test_ticket_connection($ticket_id) {
      if (empty($ticket_id)) {
          return new WP_Error('missing_id', __('Ticket ID is required.', 'brcc-inventory-tracker'));
      }
      
      // Add log at the start of the function
      BRCC_Helpers::log_debug('test_ticket_connection: Function entered', array('ticket_id' => $ticket_id)); // Restored debug log
      
      // Use the get_eventbrite_ticket function which already handles API call and error checking
      $ticket_details = $this->get_eventbrite_ticket($ticket_id);
      
      // Check the result
      if (is_wp_error($ticket_details)) {
          // Log the specific error from get_eventbrite_ticket
          BRCC_Helpers::log_error('test_ticket_connection: Failed to get ticket details.', array(
              'ticket_id' => $ticket_id,
              'error_code' => $ticket_details->get_error_code(),
              'error_message' => $ticket_details->get_error_message()
          ));
          return $ticket_details; // Return the WP_Error object
      }
      
      // If no error, the connection was successful
      BRCC_Helpers::log_debug('test_ticket_connection: Successfully retrieved ticket details.', array('ticket_id' => $ticket_id)); // Restored debug log
      return true;
  }

  /**
   * Get attendees for a specific event
   * 
   * @param string $event_id Eventbrite Event ID
   * @param int $page Page number
   * @param int $per_page Results per page
   * @return array|WP_Error Array of attendees or WP_Error on failure
   */
  public function get_event_attendees($event_id, $page = 1, $per_page = 25) { // Reduced default per_page
      if (empty($this->api_token)) {
          return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
      }
      if (empty($event_id)) {
          return new WP_Error('missing_event_id', __('Event ID is required.', 'brcc-inventory-tracker'));
      }

      // --- Caching Implementation ---
      // Cache key includes page number for pagination support
      $cache_key = 'brcc_eb_attendees_' . md5(serialize([$event_id, $page, $per_page]));
      $cached_attendees = get_transient($cache_key);

      if (false !== $cached_attendees) {
          BRCC_Helpers::log_info('get_event_attendees: Returning cached attendees.', ['event_id' => $event_id, 'page' => $page]);
          return $cached_attendees;
      }
      BRCC_Helpers::log_info('get_event_attendees: No valid cache found, fetching from API.', ['event_id' => $event_id, 'page' => $page]);
      // --- End Caching Check ---

      $all_attendees = array();
      $continuation = null;
      $current_page = 1; // Start from page 1 for API call logic
      $page_size = 50; // Use standard page size for API

      BRCC_Helpers::log_info('get_event_attendees: Starting fetch loop.', ['event_id' => $event_id]);

      do {
          $url = $this->api_url . '/events/' . $event_id . '/attendees/';
          $params = array(
              'page_size' => $page_size,
              // 'status' => 'attending', // Filter by status if needed
          );

          if ($continuation) {
              $params['continuation'] = $continuation;
          }

          $url = add_query_arg($params, $url);
          
          BRCC_Helpers::log_info('get_event_attendees: Fetching page ' . $current_page, ['url' => $url]);

          // Temporarily increase resources
          @ini_set('memory_limit', '256M');
          @set_time_limit(60);

          $response = wp_remote_get($url, array(
              'headers' => array(
                  'Authorization' => 'Bearer ' . $this->api_token,
                  'Content-Type' => 'application/json',
              ),
              'timeout' => 30 // Increased timeout
          ));

          if (is_wp_error($response)) {
              BRCC_Helpers::log_error('get_event_attendees: WP Error fetching attendees.', $response);
              return $response;
          }

          $status_code = wp_remote_retrieve_response_code($response);
          $body = json_decode(wp_remote_retrieve_body($response), true);

          if ($status_code !== 200 || isset($body['error'])) {
               $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching attendees.', 'brcc-inventory-tracker');
               if ($status_code !== 200) {
                    $error_message = sprintf(__('Eventbrite API returned status %d fetching attendees.', 'brcc-inventory-tracker'), $status_code);
               }
               BRCC_Helpers::log_error('get_event_attendees: API Error', array(
                   'event_id' => $event_id,
                   'status_code' => $status_code,
                   'error_message' => $error_message,
                   'response_body' => $body
               ));
               return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
          }

          if (isset($body['attendees']) && is_array($body['attendees'])) {
              $all_attendees = array_merge($all_attendees, $body['attendees']);
          }

          // Check for continuation token
          $continuation = isset($body['pagination']['continuation']) ? $body['pagination']['continuation'] : null;
          $current_page++;

      } while ($continuation && $current_page < 20); // Safety limit (e.g., 20 pages * 50 = 1000 attendees)

      BRCC_Helpers::log_info('get_event_attendees: Finished fetch loop. Total attendees fetched: ' . count($all_attendees));

      // Prepare the final result structure including pagination info
      $result_data = array(
          'attendees' => $all_attendees,
          'pagination' => $body['pagination'] ?? null // Include pagination info from the last call
      );

      // --- Caching Implementation ---
      // Only cache the full result if pagination is complete (no continuation token)
      if (!$continuation) {
           set_transient($cache_key, $result_data, HOUR_IN_SECONDS / 2); // Cache for 30 minutes
           BRCC_Helpers::log_info('get_event_attendees: Stored fetched attendees in cache.', ['cache_key' => $cache_key]);
      } else {
           BRCC_Helpers::log_info('get_event_attendees: Not caching result due to pagination continuation.', ['cache_key' => $cache_key]);
      }
      // --- End Caching ---

      return $result_data; // Return structure with attendees and pagination
  }

    /**
     * Get organization series (recurring event masters)
     * 
     * @return array|WP_Error Array of series data or WP_Error on failure
     */
    public function get_organization_series() {
        if (empty($this->api_token)) {
            return new WP_Error('missing_token', __('Eventbrite API token is not configured.', 'brcc-inventory-tracker'));
        }

        $organization_id = $this->get_organization_id();
        if (is_wp_error($organization_id)) {
            return $organization_id;
        }

        // --- Caching Implementation ---
        $cache_key = 'brcc_eb_org_series_' . md5($organization_id);
        $cached_series = get_transient($cache_key);

        if (false !== $cached_series) {
            BRCC_Helpers::log_info('get_organization_series: Returning cached series.');
            return $cached_series;
        }
        BRCC_Helpers::log_info('get_organization_series: No valid cache found, fetching from API.');
        // --- End Caching Check ---

        $all_series = array();
        $continuation = null;
        $page_number = 1;
        $page_size = 100;

        BRCC_Helpers::log_info('get_organization_series: Starting fetch loop.');

        do {
            $url = $this->api_url . '/organizations/' . $organization_id . '/series/';
            $params = array(
                'page_size' => $page_size,
            );

            if ($continuation) {
                $params['continuation'] = $continuation;
            }

            $url = add_query_arg($params, $url);
            
            BRCC_Helpers::log_info('get_organization_series: Fetching page ' . $page_number, ['url' => $url]);

            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30 // Increased timeout
            ));

            if (is_wp_error($response)) {
                BRCC_Helpers::log_error('get_organization_series: WP Error fetching series.', $response);
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            error_log('[BRCC Debug] get_organization_series: API Response Body: ' . print_r($body, true)); // Log the raw body

            if ($status_code !== 200 || isset($body['error'])) {
                 $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error fetching series.', 'brcc-inventory-tracker');
                 if ($status_code !== 200) {
                      $error_message = sprintf(__('Eventbrite API returned status %d fetching series.', 'brcc-inventory-tracker'), $status_code);
                 }
                 BRCC_Helpers::log_error('get_organization_series: API Error', array(
                     'status_code' => $status_code,
                     'error_message' => $error_message,
                     'response_body' => $body
                 ));
                 return new WP_Error('eventbrite_api_error', $error_message, array('status' => $status_code, 'response_body' => $body));
            }

            // Adjusted key from 'series' to 'event_series' based on debug log
            if (isset($body['event_series']) && is_array($body['event_series'])) {
                 error_log('[BRCC Debug] get_organization_series: Found ' . count($body['event_series']) . ' series in this page.'); // Log count per page
                $all_series = array_merge($all_series, $body['event_series']);
            } else {
                 error_log('[BRCC Debug] get_organization_series: Key "event_series" not found or not an array in page ' . $page_number);
            }


            // Check for continuation token
            $continuation = isset($body['pagination']['continuation']) ? $body['pagination']['continuation'] : null;
            $page_number++;

        } while ($continuation && $page_number < 10); // Safety limit

        BRCC_Helpers::log_info('get_organization_series: Finished fetch loop. Total series fetched: ' . count($all_series));
        error_log('[BRCC Debug] get_organization_series: Total series fetched before formatting: ' . count($all_series)); // Log total count

        // Format the data to return only ID and Name
        $series_data = array();
        if (!empty($all_series)) {
            foreach ($all_series as $series) {
                 error_log('[BRCC Debug] get_organization_series: Processing series item: ' . print_r($series, true)); // Log each series item
                if (isset($series['id']) && isset($series['name'])) { // Check if 'name' exists directly
                    $series_data[] = array(
                        'id' => $series['id'],
                        'name' => $series['name'] // Use direct 'name' field
                    );
                } elseif (isset($series['id']) && isset($series['name']['text'])) { // Fallback for older structure?
                     $series_data[] = array(
                        'id' => $series['id'],
                        'name' => $series['name']['text']
                    );
                } else {
                     error_log('[BRCC Debug] get_organization_series: Skipping series item due to missing id or name: ' . print_r($series, true));
                }
            }
        }

        error_log('[BRCC Debug] get_organization_series: Final formatted series data being returned: ' . print_r($series_data, true)); // Log final formatted data
        BRCC_Helpers::log_info('get_organization_series: Extracted ' . count($series_data) . ' series with ID and Name.');
        return $series_data;
        if (isset($body['event_series']) && is_array($body['event_series'])) {
            foreach ($body['event_series'] as $series) {
                $series_data[] = array(
                    'id' => isset($series['id']) ? $series['id'] : '',
                    'name' => isset($series['name']) ? $series['name'] : ''
                );
            }
        }

        return $series_data;
    }
    // --- NEW FUNCTIONS FOR ZERO STOCK UPDATE ---

    /**
     * Callback for the woocommerce_reduce_order_stock hook.
     * Iterates through order items and checks stock status.
     *
     * @param WC_Order $order The order object.
     */
    public function handle_order_stock_reduction($order) {
        // BRCC_Helpers::log_debug('handle_order_stock_reduction: Triggered for Order ID: ' . $order->get_id()); // Removed debug log
        if (!is_a($order, 'WC_Order')) {
             BRCC_Helpers::log_error('handle_order_stock_reduction: Invalid order object received.');
             return;
        }
        foreach ($order->get_items() as $item_id => $item) { // Added item_id for logging
            if (!is_a($item, 'WC_Order_Item_Product')) {
                continue;
            }
            $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
            $product = wc_get_product($product_id);
            $quantity_sold = $item->get_quantity(); // Get quantity sold in this order item

            if ($product && $product->managing_stock()) {
                // Stock has already been reduced by WooCommerce at this point.
                // Now, attempt to decrement Eventbrite capacity.

                // 1. Get booking date/time from item meta
                $booking_date = BRCC_Helpers::get_fooevents_date_from_item($item); // Use helper
                $booking_time = BRCC_Helpers::extract_booking_time_from_item($item); // Use helper

                // 2. Find Eventbrite Ticket ID
                $ticket_id = $this->get_eventbrite_ticket_id_for_product($product_id, $booking_date, $booking_time);

                if ($ticket_id) {
                    BRCC_Helpers::log_info("handle_order_stock_reduction: Found Eventbrite Ticket ID {$ticket_id} for WC Product ID {$product_id}. Attempting capacity update.", ['order_id' => $order->get_id(), 'item_id' => $item_id]);

                    // 3. Get current Eventbrite capacity
                    $ticket_details = $this->get_eventbrite_ticket($ticket_id);

                    if (!is_wp_error($ticket_details) && isset($ticket_details['capacity'])) {
                        $current_capacity = intval($ticket_details['capacity']);
                        // Check if capacity is unlimited (-1)
                        if ($current_capacity === -1) {
                             BRCC_Helpers::log_info("handle_order_stock_reduction: Eventbrite Ticket ID {$ticket_id} has unlimited capacity. Skipping decrement.", ['order_id' => $order->get_id()]);
                        } else {
                            $new_capacity = $current_capacity - $quantity_sold;
                            $new_capacity = max(0, $new_capacity); // Ensure capacity doesn't go below zero

                            BRCC_Helpers::log_info("handle_order_stock_reduction: Current EB Capacity: {$current_capacity}, Quantity Sold: {$quantity_sold}, New Capacity: {$new_capacity}", ['ticket_id' => $ticket_id]);

                            // 4. Update Eventbrite capacity
                            $update_result = $this->update_eventbrite_ticket_capacity($ticket_id, $new_capacity);

                            if (!is_wp_error($update_result)) {
                                BRCC_Helpers::log_info("handle_order_stock_reduction: Successfully updated Eventbrite capacity for Ticket ID {$ticket_id} to {$new_capacity}.", ['order_id' => $order->get_id()]);
                                 $log_details_eb = sprintf(
                                    __('Triggered Eventbrite capacity update for Ticket ID %s (New Capacity: %d) due to WooCommerce Order #%s.', 'brcc-inventory-tracker'),
                                    $ticket_id,
                                    $new_capacity,
                                    $order->get_id()
                                );
                                BRCC_Helpers::log_operation('Eventbrite Sync', 'Update Capacity (from WC)', $log_details_eb);
                            } else {
                                BRCC_Helpers::log_error("handle_order_stock_reduction: Failed to update Eventbrite capacity for Ticket ID {$ticket_id}.", [
                                    'order_id' => $order->get_id(),
                                    'error_code' => $update_result->get_error_code(),
                                    'error_message' => $update_result->get_error_message()
                                ]);
                            }
                        }
                    } else {
                         $error_message = is_wp_error($ticket_details) ? $ticket_details->get_error_message() : 'Capacity key missing or invalid response.';
                         BRCC_Helpers::log_error("handle_order_stock_reduction: Failed to get current Eventbrite capacity for Ticket ID {$ticket_id}. Error: " . $error_message, ['order_id' => $order->get_id(), 'response' => $ticket_details]);
                    }
                } else {
                     BRCC_Helpers::log_warning("handle_order_stock_reduction: Could not find Eventbrite Ticket ID mapping for WC Product ID {$product_id}. Skipping capacity update.", ['order_id' => $order->get_id(), 'item_id' => $item_id, 'booking_date' => $booking_date, 'booking_time' => $booking_time]);
                }

                // Removed call to update_eventbrite_event_status as capacity update handles the sync now.
                // $this->update_eventbrite_event_status($product_id);

            }
        }
    }

    /**
     * Callback for woocommerce_product_set_stock and woocommerce_variation_set_stock hooks.
     *
     * @param WC_Product $product The product object whose stock was set.
     */
    public function handle_direct_stock_update($product) {
        if (!is_a($product, 'WC_Product')) {
             BRCC_Helpers::log_error('handle_direct_stock_update: Invalid product object received.');
            return;
        }
        
        $product_id = $product->get_id();
        // BRCC_Helpers::log_debug('handle_direct_stock_update: Triggered for Product ID: ' . $product_id); // Removed debug log

        if ($product->managing_stock()) {
            // This hook fires AFTER stock is set. We need to sync the *status* to Eventbrite.
            // For direct stock updates, we primarily care if it hit zero.
            // A full capacity sync might be better handled by a dedicated sync button/cron.
            $this->update_eventbrite_event_status($product_id);
        }
    }

    /**
     * Checks the stock status of a WooCommerce product and updates the corresponding
     * Eventbrite event/ticket status (e.g., sets to sold out if WC stock <= 0).
     * This does NOT decrement capacity based on individual sales.
     *
     * @param int $product_id WooCommerce Product ID.
     */
    public function update_eventbrite_event_status($product_id) {
        BRCC_Helpers::log_info('update_eventbrite_event_status: Checking status for Product ID: ' . $product_id);
        $product = wc_get_product($product_id);

        if (!$product) {
            BRCC_Helpers::log_error('update_eventbrite_event_status: Product not found: ' . $product_id);
            return;
        }

        // Check if stock management is enabled and stock is zero or less
        if ($product->managing_stock() && $product->get_stock_quantity() <= 0) {
            BRCC_Helpers::log_info('update_eventbrite_event_status: Product ID ' . $product_id . ' has stock <= 0. Attempting to find and update Eventbrite ticket status.');

            // Need to find the corresponding Eventbrite Ticket ID(s)
            // This might involve checking general mapping and date-specific mappings
            
            // 1. Check general mapping
            $this->load_mappings();
            $general_ticket_id = $this->all_mappings[$product_id]['eventbrite_ticket_id'] ?? null;
            if ($general_ticket_id) {
                 BRCC_Helpers::log_info('update_eventbrite_event_status: Found general mapping. Ticket ID: ' . $general_ticket_id);
                 $this->set_eventbrite_ticket_sold_out($general_ticket_id, $product_id);
            }

            // 2. Check date-specific mappings
            $date_key = $product_id . '_dates';
            if (isset($this->all_mappings[$date_key])) {
                 BRCC_Helpers::log_info('update_eventbrite_event_status: Checking date-specific mappings for Product ID: ' . $product_id);
                 foreach ($this->all_mappings[$date_key] as $date_time_key => $specific_mapping) {
                     $date_ticket_id = $specific_mapping['eventbrite_ticket_id'] ?? null;
                     if ($date_ticket_id && $date_ticket_id !== $general_ticket_id) { // Avoid double-updating if same ID used
                          BRCC_Helpers::log_info('update_eventbrite_event_status: Found date-specific mapping. Date/Time Key: ' . $date_time_key . ', Ticket ID: ' . $date_ticket_id);
                          $this->set_eventbrite_ticket_sold_out($date_ticket_id, $product_id);
                     }
                 }
            }
            
            if (!$general_ticket_id && !isset($this->all_mappings[$date_key])) {
                 BRCC_Helpers::log_warning('update_eventbrite_event_status: No Eventbrite ticket mapping found for Product ID: ' . $product_id . '. Cannot update status.');
            }

        } else {
             BRCC_Helpers::log_info('update_eventbrite_event_status: Product ID ' . $product_id . ' is in stock or not managing stock. No status change needed on Eventbrite.');
             // Potentially add logic here to re-enable the Eventbrite ticket if it was previously set to sold out?
             // This requires tracking the previous state or fetching current Eventbrite status.
        }
    }

/**
 * Sets an Eventbrite ticket class to sold out by updating its sales status.
 * Note: Eventbrite API for directly setting "Sold Out" status might be limited.
 * This might involve setting capacity to 0 or managing sales end dates.
 * For now, we log the intent and potentially send a notification.
 *
 * @param string $ticket_id Eventbrite Ticket Class ID.
 * @param int $product_id WooCommerce Product ID (for context).
 */
public function set_eventbrite_ticket_sold_out($ticket_id, $product_id) { // Added $product_id parameter
    BRCC_Helpers::log_info('set_eventbrite_ticket_sold_out: Attempting to mark Ticket ID ' . $ticket_id . ' as sold out (linked to WC Product ID ' . $product_id . ').');

    // Option 1: Update capacity to 0 (More reliable via API)
    // We already have a function for this: update_eventbrite_ticket_capacity
    // Let's call that instead of trying to manipulate sales status directly.
    $update_result = $this->update_eventbrite_ticket_capacity($ticket_id, 0);

    if (!is_wp_error($update_result)) {
        BRCC_Helpers::log_info('set_eventbrite_ticket_sold_out: Successfully set capacity to 0 for Ticket ID ' . $ticket_id . '.');
        $this->send_sold_out_notification($product_id, $ticket_id); // Send notification on success
        return true;
    } else {
        BRCC_Helpers::log_error('set_eventbrite_ticket_sold_out: Failed to set capacity to 0 for Ticket ID ' . $ticket_id . '.', [
            'error_code' => $update_result->get_error_code(),
            'error_message' => $update_result->get_error_message()
        ]);
        return false;
    }


    // Option 2: Try to update sales_status (Less reliable, might not be directly settable)
    /*
    $url = $this->api_url . '/ticket_classes/' . $ticket_id . '/';
    $data = json_encode(array(
        'ticket_class' => array(
            // 'sales_status' => 'sold_out', // This field might not be directly writable or exist
            // Alternative: Set sales end date to the past?
             'sales_end' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600) // Set sales end to 1 hour ago UTC
        ),
    ));

    BRCC_Helpers::log_info('set_eventbrite_ticket_sold_out: Sending API request to update status/sales_end for Ticket ID: ' . $ticket_id);

    $response = wp_remote_post($url, array(
        'method' => 'POST',
        'headers' => array(
            'Authorization' => 'Bearer ' . $this->api_token,
            'Content-Type' => 'application/json',
        ),
        'body' => $data,
        'timeout' => 20
    ));

    if (is_wp_error($response)) {
        BRCC_Helpers::log_error('set_eventbrite_ticket_sold_out: wp_remote_post failed.', [
            'ticket_id' => $ticket_id,
            'error' => $response->get_error_message()
        ]);
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($status_code !== 200 || isset($body['error'])) {
        $error_message = isset($body['error_description']) ? $body['error_description'] : __('Unknown Eventbrite API error setting ticket sold out.', 'brcc-inventory-tracker');
        if ($status_code !== 200) {
             $error_message = sprintf(__('Eventbrite API returned status %d setting ticket sold out.', 'brcc-inventory-tracker'), $status_code);
        }
        BRCC_Helpers::log_error('set_eventbrite_ticket_sold_out: API Error', array(
            'ticket_id' => $ticket_id,
            'status_code' => $status_code,
            'error_message' => $error_message,
            'response_body' => $body
        ));
        return false;
    }

    BRCC_Helpers::log_info('set_eventbrite_ticket_sold_out: Successfully updated status/sales_end for Ticket ID: ' . $ticket_id);
    $this->send_sold_out_notification($product_id, $ticket_id); // Send notification
    return true;
    */
}

    /**
     * Send notification when an Eventbrite ticket is marked as sold out.
     *
     * @param int $product_id WooCommerce Product ID.
     * @param string $ticket_id Eventbrite Ticket Class ID.
     */
    private function send_sold_out_notification($product_id, $ticket_id) {
        $settings = get_option('brcc_api_settings');
        $notify_email = isset($settings['notification_email']) ? sanitize_email($settings['notification_email']) : '';

        if (empty($notify_email)) {
            return; // No notification email configured
        }

        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : 'Unknown Product (ID: ' . $product_id . ')';
        $product_link = $product ? get_edit_post_link($product_id) : '#';
        
        // Try to get Eventbrite event details for more context
        // This requires finding the event_id associated with the ticket_id
        // For simplicity, we'll skip fetching the event name for now.
        // A more robust implementation would fetch the event details.
        $eventbrite_ticket_link = sprintf('https://www.eventbrite.ca/manage/events/EVENT_ID_PLACEHOLDER/tickets/%s', $ticket_id); // Placeholder

        $subject = sprintf(__('Eventbrite Ticket Sold Out: %s', 'brcc-inventory-tracker'), $product_name);
        $message = sprintf(
            __("The Eventbrite ticket associated with WooCommerce product '%s' (ID: %d) has been marked as sold out because the WooCommerce stock reached zero.\n\nWooCommerce Product: %s\nEventbrite Ticket ID: %s\n\nPlease verify the status on Eventbrite:\n%s\n\n(Note: The Eventbrite link requires the Event ID, which could not be automatically determined in this notification.)", 'brcc-inventory-tracker'),
            $product_name,
            $product_id,
            $product_link,
            $ticket_id,
            $eventbrite_ticket_link 
        );

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        // Send the email
        wp_mail($notify_email, $subject, $message, $headers);

        BRCC_Helpers::log_info('Sold out notification sent.', ['email' => $notify_email, 'product_id' => $product_id, 'ticket_id' => $ticket_id]);
    }


    /**
     * Validate if a ticket class belongs to a specific event
     * 
     * @param string $ticket_id Ticket Class ID
     * @param string $event_id Event ID
     * @return bool|WP_Error True if valid, false if not, WP_Error on API failure
     */
    public function validate_ticket_belongs_to_event($ticket_id, $event_id) {
         BRCC_Helpers::log_debug('validate_ticket_belongs_to_event: Validating Ticket ID ' . $ticket_id . ' against Event ID ' . $event_id);
         $ticket_details = $this->get_eventbrite_ticket($ticket_id);

         if (is_wp_error($ticket_details)) {
              BRCC_Helpers::log_error('validate_ticket_belongs_to_event: Failed to get ticket details.', $ticket_details);
             return $ticket_details; // Propagate API error
         }

         if (isset($ticket_details['event_id']) && $ticket_details['event_id'] == $event_id) {
              BRCC_Helpers::log_debug('validate_ticket_belongs_to_event: Validation successful.');
             return true;
         } else {
              BRCC_Helpers::log_warning('validate_ticket_belongs_to_event: Validation failed.', [
                  'expected_event_id' => $event_id,
                  'actual_event_id' => $ticket_details['event_id'] ?? 'Not Found'
              ]);
             return false;
         }
    }
    /**
     * Test function to process a specific order via webhook logic
     */
    public function test_process_order($order_id) {
        BRCC_Helpers::log_info('--- START test_process_order ---', ['order_id' => $order_id]);
        $order = wc_get_order($order_id);
        if (!$order) {
            BRCC_Helpers::log_error('test_process_order: Order not found.', ['order_id' => $order_id]);
            return false;
        }

        // Simulate the stock reduction hook
        $this->handle_order_stock_reduction($order);

        BRCC_Helpers::log_info('--- END test_process_order ---', ['order_id' => $order_id]);
        return true;
    }

} // End class BRCC_Eventbrite_Integration
?>