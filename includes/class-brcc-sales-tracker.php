<?php
/**
 * BRCC Sales Tracker Class
 * 
 * Handles tracking of daily sales data from WooCommerce, Eventbrite, and Square with enhanced support for date-based inventory
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Sales_Tracker {
    /**
     * Constructor - setup hooks
     */
    public function __construct() {
        // Hook into WooCommerce order status changes to processing or completed
        add_action('woocommerce_order_status_processing', array($this, 'handle_order_paid'), 10, 2);
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_paid'), 10, 2);

        // Register shortcode for displaying sales data
        add_shortcode('brcc_sales_data', array($this, 'sales_data_shortcode'));
        
        // Add shortcode for event-specific sales
        add_shortcode('brcc_event_sales', array($this, 'event_sales_shortcode'));
    }

    /**
     * Handle actions when an order status changes to processing or completed.
     * Records the sale and updates daily totals.
     *
     * @param int      $order_id Order ID.
     * @param WC_Order $order    Order object.
     */
    public function handle_order_paid($order_id, $order) {
        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Hook fired. New Status: %s.", $order_id, $order->get_status()));

        $order_key = 'wc_paid_' . $order_id;
        if ($this->is_sale_processed($order_key)) {
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Already processed with key '%s'. Skipping.", $order_id, $order_key));
            return;
        }

        if (!$order) {
            $order = wc_get_order($order_id);
            if (!$order) {
                BRCC_Helpers::log_operation('Sales Tracking', 'Order Paid Init Failed', sprintf("[BRCC Order #%d] Could not retrieve order object.", $order_id), 'error');
                return;
            }
        }
        $current_status = $order->get_status();
        BRCC_Helpers::log_operation('Sales Tracking', 'Order Paid Init', sprintf("[BRCC Order #%d] Processing order with status '%s'.", $order_id, $current_status), 'info');

        if (BRCC_Helpers::is_test_mode()) {
            $message = sprintf("[BRCC Order #%d] Test Mode: Order status changed to '%s'. Sales tracking update would occur.", $order_id, $current_status);
            BRCC_Helpers::log_operation('Sales Tracking', 'Order Paid (Test Mode)', $message, 'info');
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Test Mode enabled. Skipping actual update, triggering test actions.", $order_id));
            do_action('brcc_product_sold', $order_id, $order); // Allow other test logs
            return;
        } else {
            if (BRCC_Helpers::should_log()) {
                 $message = sprintf("[BRCC Order #%d] Live Mode: Order status changed to '%s'. Updating sales tracking.", $order_id, $current_status);
                 BRCC_Helpers::log_operation('Sales Tracking', 'Order Paid (Live Mode)', $message, 'info');
                 BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Live logging enabled and operation logged.", $order_id));
            } else {
                 BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Live logging is disabled.", $order_id));
            }
        }

        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Getting date and options.", $order_id));
        $date = current_time('Y-m-d');
        $daily_sales = get_option('brcc_daily_sales', []);
        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Current Date: %s. Retrieved daily_sales option.", $order_id, $date));

        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Getting order items.", $order_id));
        $items = $order->get_items();
        $product_date_quantities = [];
        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Found %d items. Starting item loop.", $order_id, count($items)));

        foreach ($items as $item_id => $item) {
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Processing Item #%d (Type: %s).", $order_id, $item_id, (is_object($item) ? get_class($item) : gettype($item))));

            $product_id = $item->get_product_id();
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Product ID: %s.", $order_id, $item_id, $product_id));

            $product = wc_get_product($product_id);
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: wc_get_product(%s) returned: %s.", $order_id, $item_id, $product_id, ($product ? get_class($product) : 'null')));

            if (!$product) {
                $message = sprintf("[BRCC Order #%d][Order Paid Hook] Skipped Item #%d: Product lookup failed for Product ID %s.", $order_id, $item_id, $product_id);
                BRCC_Helpers::log_operation('Sales Tracking', 'Item Processing Warning', $message, 'warning', ['order_id' => $order_id, 'item_id' => $item_id, 'product_id' => $product_id]);
                continue;
            }

            $quantity = $item->get_quantity();
            $product_name = $product->get_name();
            $sku = $product->get_sku();
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d (Product #%s '%s', SKU: '%s'): Quantity: %d.", $order_id, $item_id, $product_id, $product_name, $sku ?: 'N/A', $quantity));

            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Calling get_booking_date_from_item.", $order_id, $item_id));
            $booking_date = $this->get_booking_date_from_item($item);
            $booking_date_str = $booking_date ?: 'N/A';
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Extracted Booking Date: %s.", $order_id, $item_id, $booking_date_str));
            
            $summary_product_key = $product_id . ($booking_date ? '_' . $booking_date : '');
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Summary product_key: %s.", $order_id, $item_id, $summary_product_key));
            if (!isset($product_date_quantities[$summary_product_key])) {
                BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Initializing product_date_quantities for key '%s'.", $order_id, $item_id, $summary_product_key));
                $product_date_quantities[$summary_product_key] = [
                    'product_id' => $product_id,
                    'name' => $product_name,
                    'sku' => $sku,
                    'booking_date' => $booking_date,
                    'quantity' => 0
                ];
            }
            $product_date_quantities[$summary_product_key]['quantity'] += $quantity;
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Updated quantity for key '%s' to %d.", $order_id, $item_id, $summary_product_key, $product_date_quantities[$summary_product_key]['quantity']));
            
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Updating daily sales for date %s.", $order_id, $item_id, $date));
            if (!isset($daily_sales[$date])) {
                BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Initializing daily_sales for date '%s'.", $order_id, $item_id, $date));
                $daily_sales[$date] = array();
            }
            
            $daily_sales_product_key = $booking_date ? $product_id . '_' . $booking_date : (string)$product_id; // Ensure key is string
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Daily sales product_key: %s.", $order_id, $item_id, $daily_sales_product_key));
            
            if (isset($daily_sales[$date][$daily_sales_product_key])) {
                 BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Updating existing entry in daily_sales for key '%s'.", $order_id, $item_id, $daily_sales_product_key), ['existing_data' => $daily_sales[$date][$daily_sales_product_key]]);
                 if (!is_array($daily_sales[$date][$daily_sales_product_key])) {
                     BRCC_Helpers::log_operation('Sales Tracking', 'Data Corruption Warning', sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Corrupted scalar data found for daily_sales entry (key: %s). Overwriting.", $order_id, $item_id, $daily_sales_product_key), 'warning', ['order_id' => $order_id, 'item_id' => $item_id, 'key' => $daily_sales_product_key]);
                     $daily_sales[$date][$daily_sales_product_key] = array(
                         'name' => $product_name, 'sku' => $sku, 'product_id' => $product_id, 'booking_date' => $booking_date,
                         'quantity' => $quantity, 'revenue' => $item->get_total(), 'woocommerce' => $quantity,
                         'eventbrite' => 0, 'square' => 0, // Initialize other sources
                         'timestamp' => current_time('timestamp'), 'source' => 'woocommerce'
                     );
                 } else {
                     $daily_sales[$date][$daily_sales_product_key]['quantity'] = ($daily_sales[$date][$daily_sales_product_key]['quantity'] ?? 0) + $quantity;
                     $daily_sales[$date][$daily_sales_product_key]['revenue'] = ($daily_sales[$date][$daily_sales_product_key]['revenue'] ?? 0) + $item->get_total();
                     $daily_sales[$date][$daily_sales_product_key]['woocommerce'] = ($daily_sales[$date][$daily_sales_product_key]['woocommerce'] ?? 0) + $quantity;
                     $daily_sales[$date][$daily_sales_product_key]['timestamp'] = current_time('timestamp');
                     // Ensure 'source' is updated or set if missing
                     $daily_sales[$date][$daily_sales_product_key]['source'] = 'woocommerce';
                 }
            } else {
                BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d: Creating new entry in daily_sales for key '%s'.", $order_id, $item_id, $daily_sales_product_key));
                $daily_sales[$date][$daily_sales_product_key] = array(
                    'name' => $product_name, 'sku' => $sku, 'product_id' => $product_id, 'booking_date' => $booking_date,
                    'quantity' => $quantity, 'revenue' => $item->get_total(), 'woocommerce' => $quantity,
                    'eventbrite' => 0, 'square' => 0,
                    'timestamp' => current_time('timestamp'), 'source' => 'woocommerce'
                );
            }
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Item #%d (Product #%s, Qty: %d, Booking Date: %s) processed for daily sales.", $order_id, $item_id, $product_id, $quantity, $booking_date_str));
        }
        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Finished item loop. Saving daily sales data.", $order_id));

        update_option('brcc_daily_sales', $daily_sales);
        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Daily sales data saved. Updating daily product summary for date %s.", $order_id, $date));

        $this->update_daily_product_summary($date, $product_date_quantities);
        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Daily product summary updated. Scheduling tasks (if any).", $order_id));

        // Schedule tasks for each item (if needed for other integrations)
        // Note: Eventbrite sync is now handled by stock reduction hooks, not scheduled here.
        // This loop currently doesn't do much other than prepare args, kept for structure.
        foreach ($product_date_quantities as $summary_product_key => $data) {
            $schedule_args = array(
                'order_id' => $order_id,
                'product_id' => $data['product_id'],
                'quantity' => $data['quantity'],
                'booking_date' => $data['booking_date'],
                'booking_time' => null
            );
            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Order Paid Hook] Prepared schedule_args for product_key '%s'.", $order_id, $summary_product_key), $schedule_args);
        }
        BRCC_Helpers::log_operation('Sales Tracking', 'Order Paid End', sprintf("[BRCC Order #%d] Finished processing paid order.", $order_id), 'info');
    }
    
    /**
     * Update daily product summary for better reporting
     */
    public function update_daily_product_summary($date, $product_date_quantities) {
        $product_summary = get_option('brcc_product_summary', array());
        
        if (!isset($product_summary[$date])) {
            $product_summary[$date] = array();
        }
        
        // Group by product first, then by date
        foreach ($product_date_quantities as $product_key => $data) {
            $product_id = $data['product_id'];
            
            // Create product entry if it doesn't exist
            if (!isset($product_summary[$date][$product_id])) {
                $product_summary[$date][$product_id] = array(
                    'name' => $data['name'],
                    'sku' => $data['sku'],
                    'total_quantity' => 0,
                    'dates' => array()
                );
            }
            
            // Update total quantity for this product
            $product_summary[$date][$product_id]['total_quantity'] += $data['quantity'];
            
            // Update date-specific data
            if ($data['booking_date']) {
                if (!isset($product_summary[$date][$product_id]['dates'][$data['booking_date']])) {
                    $product_summary[$date][$product_id]['dates'][$data['booking_date']] = 0;
                }
                $product_summary[$date][$product_id]['dates'][$data['booking_date']] += $data['quantity'];
            }
        }
        
        update_option('brcc_product_summary', $product_summary);
    }

    /**
     * Get booking date from order item with enhanced detection
     * 
     * @param WC_Order_Item $item Order item
     * @return string|null Booking date in Y-m-d format or null if not found
     */
    private function get_booking_date_from_item($item) {
        $item_id = $item->get_id();
        $log_prefix = sprintf("[BRCC Item #%d][Date Extraction]", $item_id);

        BRCC_Helpers::log_debug("$log_prefix Starting booking date extraction.");
        BRCC_Helpers::log_debug("$log_prefix Checking FooEvents specific meta.");
        
        $fooevents_date = BRCC_Helpers::get_fooevents_date_from_item($item); // This helper likely has its own logging
        BRCC_Helpers::log_debug("$log_prefix FooEvents check result: " . var_export($fooevents_date, true));
        if ($fooevents_date) {
            BRCC_Helpers::log_debug("$log_prefix Found FooEvents date: '$fooevents_date'. Returning.");
            return $fooevents_date;
        }
        
        BRCC_Helpers::log_debug("$log_prefix Checking standard item meta.");
        $item_meta = $item->get_meta_data();
        
        $date_meta_keys = array(
            'fooevents_event_date', 'WooCommerceEventsDate', // Common FooEvents Keys
            'event_date', 'ticket_date', 'booking_date', 'pa_date', 'date', // General Keys
            '_event_date', '_booking_date', 'Event Date', 'Ticket Date', 'Show Date', 'Performance Date'
        );
        
        BRCC_Helpers::log_debug("$log_prefix Looping through known meta keys for date.", ['keys_to_check' => $date_meta_keys]);
        foreach ($date_meta_keys as $key) {
            $date_value = $item->get_meta($key);
            if (!empty($date_value)) {
                BRCC_Helpers::log_debug("$log_prefix Found potential date in known key '$key': " . (is_array($date_value) ? wp_json_encode($date_value) : $date_value));
                $parsed_date = BRCC_Helpers::parse_date_value($date_value);
                if ($parsed_date) {
                    BRCC_Helpers::log_debug("$log_prefix Parsed date from known key '$key' as: '$parsed_date'. Returning.");
                    return $parsed_date;
                } else {
                     BRCC_Helpers::log_debug("$log_prefix Failed to parse date from known key '$key'. Value: " . (is_array($date_value) ? wp_json_encode($date_value) : $date_value));
                }
            }
        }
        BRCC_Helpers::log_debug("$log_prefix Finished known meta keys loop. No definitive date found yet.");

        BRCC_Helpers::log_debug("$log_prefix Type of \$item_meta: " . gettype($item_meta) . ". Attempting to loop through ALL meta data if iterable.");
        if (is_array($item_meta) || is_object($item_meta)) {
             // To avoid overly verbose logs with full meta dumps, we'll log individual checks inside the loop.
        } else {
             BRCC_Helpers::log_debug("$log_prefix \$item_meta is not iterable. Skipping full meta scan.");
        }

        if (is_iterable($item_meta)) {
            foreach ($item_meta as $meta) {
                $meta_data = $meta->get_data();
                $key = $meta_data['key'];
                $value = $meta_data['value'];
                // Log only if key matches a date-like pattern to reduce noise
                if (preg_match('/(date|day|event|show|performance|time)/i', $key)) {
                    BRCC_Helpers::log_debug("$log_prefix Checking all meta - Key: '$key', Value: " . (is_array($value) ? wp_json_encode($value) : $value));
                    $date_value = BRCC_Helpers::parse_date_value($value);
                    if ($date_value) {
                        BRCC_Helpers::log_debug("$log_prefix Parsed date from all_meta key '$key' as: '$date_value'. Returning.");
                        return $date_value;
                    } else {
                        BRCC_Helpers::log_debug("$log_prefix Failed to parse date from all_meta key '$key'.");
                    }
                }
            }
            BRCC_Helpers::log_debug("$log_prefix Finished ALL meta data loop.");
        }
        
        BRCC_Helpers::log_debug("$log_prefix Checking product attributes as fallback.");
        $product_id = $item->get_product_id();
        $product = wc_get_product($product_id);
        
        if ($product && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            $parent = wc_get_product($parent_id);
            
            if ($parent) {
                $attributes = $product->get_attributes();
                BRCC_Helpers::log_debug("$log_prefix Product #$product_id is a variation. Looping through attributes.", ['attributes' => $attributes]);
                foreach ($attributes as $attr_name => $attr_value) {
                     BRCC_Helpers::log_debug("$log_prefix Checking attribute: '$attr_name' with value: '$attr_value'.");
                    if (preg_match('/(date|day|event|show|performance)/i', $attr_name)) {
                         BRCC_Helpers::log_debug("$log_prefix Attribute '$attr_name' matches date pattern. Trying to parse value.");
                        $date_value = BRCC_Helpers::parse_date_value($attr_value);
                        if ($date_value) {
                             BRCC_Helpers::log_debug("$log_prefix Parsed date from attribute '$attr_name' as: '$date_value'. Returning.");
                            return $date_value;
                        } else {
                             BRCC_Helpers::log_debug("$log_prefix Failed to parse date from attribute '$attr_name'.");
                        }
                    }
                }
                 BRCC_Helpers::log_debug("$log_prefix Finished attribute loop for variation.");
            } else {
                 BRCC_Helpers::log_debug("$log_prefix Parent product #$parent_id not found for variation #$product_id.");
            }
        } else {
            BRCC_Helpers::log_debug("$log_prefix Product #$product_id is not a variation or product object not found. Skipping attribute check.");
        }
        
        BRCC_Helpers::log_debug("$log_prefix No booking date found in meta or attributes. Returning null.");
        return null;
    }

    /**
     * Check if a sale has already been processed
     *
     * @param string $order_key Unique identifier for the order/sale item
     * @return bool True if already processed
     */
    public function is_sale_processed($order_key) {
        if (empty($order_key)) {
            BRCC_Helpers::log_operation('Sales Tracking', 'Process Check Warning', 'is_sale_processed called with empty key.', 'warning');
            return false;
        }
        
        $max_processed_sales = apply_filters('brcc_max_processed_sales', 1000);
        $processed_sales = get_option('brcc_processed_sales', array());
        
        if (isset($processed_sales[$order_key])) {
            BRCC_Helpers::log_debug(sprintf("[Sales Tracking][Process Check] Key '%s' found in processed list. Returning true.", $order_key));
            return true;
        }
        
        BRCC_Helpers::log_debug(sprintf("[Sales Tracking][Process Check] Key '%s' not found. Adding to processed list.", $order_key));
        $processed_sales[$order_key] = time();

        if (count($processed_sales) > $max_processed_sales) {
            asort($processed_sales);
            $processed_sales = array_slice($processed_sales, -$max_processed_sales, null, true);
            BRCC_Helpers::log_operation('Sales Tracking', 'Process List Maintenance', sprintf("Trimmed processed sales list to %d entries.", $max_processed_sales), 'info');
        }

        update_option('brcc_processed_sales', $processed_sales);
        
        return false;
    }

    /**
     * Records a sale from any source (WooCommerce, Eventbrite, Square, etc.).
     * Updates daily sales totals and product summaries.
     *
     * @param int    $product_id      WooCommerce Product ID.
     * @param int    $quantity        Quantity sold.
     * @param string $source          Source of the sale (e.g., 'woocommerce', 'eventbrite', 'square').
     * @param string $source_order_id Unique identifier for the order/transaction from the source.
     * @param string $customer_name   Customer's name.
     * @param string $customer_email  Customer's email.
     * @param float  $gross_amount    Total amount for this part of the sale.
     * @param string $currency        Currency code (e.g., 'CAD').
     * @param array  $event_details   Optional details about the event (name, date, time).
     * @return bool True if the sale was recorded successfully, false otherwise.
     */
    public function record_sale($product_id, $quantity, $source, $source_order_id, $customer_name, $customer_email, $gross_amount, $currency, $event_details = []) {
        $log_context = [
            'product_id' => $product_id, 'quantity' => $quantity, 'source' => $source, 'source_order_id' => $source_order_id,
            'customer_name' => $customer_name, 'customer_email' => $customer_email, 'gross_amount' => $gross_amount, 'currency' => $currency,
            'event_details' => $event_details
        ];
        $log_message_prefix = sprintf("[BRCC Sale][Source: %s][Order #%s][Product #%d]", $source, $source_order_id, $product_id);
        BRCC_Helpers::log_operation('Sales Tracking', 'Record Sale Start', "$log_message_prefix Starting to record sale.", 'info', $log_context);

        // --- Prevent Duplicate Processing ---
        $order_key = $source . '_' . $source_order_id . '_' . $product_id;
        if ($this->is_sale_processed($order_key)) {
            $message = "$log_message_prefix Sale already processed (Order Key: $order_key). Skipping.";
            BRCC_Helpers::log_operation('Sales Tracking', 'Record Sale Skipped (Duplicate)', $message, 'warning', ['order_key' => $order_key, 'original_context' => $log_context]);
            return true;
        }

        // --- Get Product Info ---
        $product = wc_get_product($product_id);
        if (!$product) {
            $message = "$log_message_prefix Could not find product. Aborting sale record.";
            BRCC_Helpers::log_operation('Sales Tracking', 'Record Sale Failed (Product Not Found)', $message, 'error', ['product_id' => $product_id, 'original_context' => $log_context]);
            return false;
        }
        $product_name = $product->get_name();
        $sku = $product->get_sku();
        BRCC_Helpers::log_debug("$log_message_prefix Product: '$product_name' (SKU: " . ($sku ?: 'N/A') . ").");

        // --- Determine Dates ---
        $sale_date = current_time('Y-m-d');
        $booking_date = $event_details['date'] ?? null;
        $booking_date_str = $booking_date ?: 'N/A';
        BRCC_Helpers::log_debug("$log_message_prefix Sale Date: $sale_date, Booking Date: $booking_date_str.");

        // --- Update Daily Sales Option ---
        $daily_sales = get_option('brcc_daily_sales', []);
        if (!isset($daily_sales[$sale_date])) {
            $daily_sales[$sale_date] = [];
            BRCC_Helpers::log_debug("$log_message_prefix Initialized daily_sales for $sale_date.");
        }

        $daily_key = $booking_date ? $product_id . '_' . $booking_date : (string)$product_id;
        BRCC_Helpers::log_debug("$log_message_prefix Daily sales key: $daily_key.");

        if (!isset($daily_sales[$sale_date][$daily_key])) {
            BRCC_Helpers::log_debug("$log_message_prefix Initializing new entry for daily_sales key $daily_key on $sale_date.");
            $daily_sales[$sale_date][$daily_key] = [
                'name' => $product_name, 'sku' => $sku, 'product_id' => $product_id, 'booking_date' => $booking_date,
                'quantity' => 0, 'woocommerce' => 0, 'eventbrite' => 0, 'square' => 0, // Initialize all known sources
            ];
        }

        // Ensure specific source and quantity keys exist before incrementing
        if (!isset($daily_sales[$sale_date][$daily_key][$source])) {
             $daily_sales[$sale_date][$daily_key][$source] = 0;
        }
         if (!isset($daily_sales[$sale_date][$daily_key]['quantity'])) { // Overall quantity for the product on that day/booking_date
             $daily_sales[$sale_date][$daily_key]['quantity'] = 0;
         }

        // Update quantities
        $daily_sales[$sale_date][$daily_key]['quantity'] += $quantity;
        $daily_sales[$sale_date][$daily_key][$source] += $quantity;
        // Update timestamp for this entry
        $daily_sales[$sale_date][$daily_key]['timestamp'] = current_time('timestamp');


        if (!isset($daily_sales[$sale_date][$daily_key]['orders'])) {
            $daily_sales[$sale_date][$daily_key]['orders'] = [];
        }
        $daily_sales[$sale_date][$daily_key]['orders'][] = [
             'source' => $source, 'order_id' => $source_order_id, 'quantity' => $quantity,
             'amount' => $gross_amount, 'currency' => $currency,
             'customer' => $customer_name . ' (' . $customer_email . ')',
             'timestamp' => current_time('mysql')
        ];
        BRCC_Helpers::log_debug("$log_message_prefix Updated daily sales data in memory for key $daily_key.", ['current_data' => $daily_sales[$sale_date][$daily_key]]);

        update_option('brcc_daily_sales', $daily_sales);
        BRCC_Helpers::log_debug("$log_message_prefix Saved updated brcc_daily_sales option to database.", ['sale_date' => $sale_date, 'daily_key' => $daily_key]);

        // --- Update Product Summary Option ---
        $product_summary_update = [
            $daily_key => [
                'product_id' => $product_id, 'name' => $product_name, 'sku' => $sku,
                'booking_date' => $booking_date, 'quantity' => $quantity
            ]
        ];
        $this->update_daily_product_summary($sale_date, $product_summary_update);
        BRCC_Helpers::log_debug("$log_message_prefix Called update_daily_product_summary for $sale_date.", ['summary_update_payload' => $product_summary_update]);

        $final_message = "$log_message_prefix Sale (Qty: $quantity) recorded successfully. Order Key: $order_key.";
        BRCC_Helpers::log_operation('Sales Tracking', 'Record Sale Success', $final_message, 'success', ['order_key' => $order_key, 'final_context' => $log_context]);
        return true;
    }

    /**
     * Update the stock quantity for a WooCommerce product.
     *
     * @param int $product_id The ID of the WooCommerce product.
     * @param int $quantity_sold The quantity that was sold (positive number).
     * @param string $context Optional context for logging (e.g., 'WooCommerce Order', 'Square Sale').
     */
    public function update_woocommerce_stock($product_id, $quantity, $note = '', $force = false) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            BRCC_Helpers::log_error('update_woocommerce_stock: Product not found: ' . $product_id);
            return false;
        }
        
        // Check if product manages stock or if we're forcing the update
        if (!$product->managing_stock() && !$force) {
            BRCC_Helpers::log_info('update_woocommerce_stock: Product ID ' . $product_id . ' does not manage stock and force is false. Skipping update.');
            return false; // Return false as no update was made
        }
        
        // Get current stock
        $current_stock = $product->get_stock_quantity();
        $initial_stock_value = $current_stock; // Store initial value for logging
        
        // If stock is not managed but we are forcing, set a default value and enable management
        if ($current_stock === null && $force) {
            // If forcing update on a product that doesn't track stock,
            // initialize stock tracking. Setting initial stock is tricky,
            // maybe log a warning instead or use a configurable default.
            // For now, let's assume reducing from an 'infinite' or unknown state.
            // We will still proceed to set the stock status based on reduction.
            // Let's enable stock management.
            BRCC_Helpers::log_warning('update_woocommerce_stock: Forcing stock update for Product ID ' . $product_id . ' which was not managing stock. Enabling stock management.');
            $product->set_manage_stock(true);
            // We cannot reliably calculate $new_stock if $current_stock was null.
            // Let's just set it to 0 if quantity > 0, assuming it's now sold out.
            // Or perhaps better: don't set quantity, just status if needed.
            // For simplicity now, let's skip quantity setting if initial was null.
            $new_stock = null; // Indicate we can't calculate a new quantity
        } else {
             // Calculate new stock only if current stock is known
             $new_stock = $current_stock - $quantity;
        }

        $stock_updated = false;
        if ($new_stock !== null) {
            // Ensure stock doesn't go below zero if it's managed numerically
             $new_stock = max(0, $new_stock);
             
            // Set new stock quantity only if it changed or if we forced management on
            if ($new_stock !== $initial_stock_value || ($current_stock === null && $force)) {
                 $product->set_stock_quantity($new_stock);
                 $stock_updated = true;
                 BRCC_Helpers::log_info('update_woocommerce_stock: Set stock quantity for Product ID ' . $product_id . ' to ' . $new_stock);
            }
        } else if ($force && $current_stock === null) {
            // If stock was null and we forced, maybe just mark as out of stock if quantity > 0?
             if ($quantity > 0) {
                 $product->set_stock_status('outofstock');
                 $stock_updated = true;
                 BRCC_Helpers::log_info('update_woocommerce_stock: Forced update for non-managed Product ID ' . $product_id . '. Set status to outofstock.');
             }
        }

        // Save product if any changes were made (stock quantity or manage_stock flag or status)
        if ($stock_updated) {
            $product->save();
            BRCC_Helpers::log_info('update_woocommerce_stock: Saved product ' . $product_id . ' after stock update. (' . $note . ')');
            return true; // Indicate success
        } else {
             BRCC_Helpers::log_info('update_woocommerce_stock: No stock changes needed or made for Product ID ' . $product_id . '. (' . $note . ')');
             return false; // Indicate no update was performed
        }
    }
    
    /**
     * Record sales from Eventbrite
     * Note: Duplicate check is now expected to happen *before* calling this function using is_sale_processed().
     */
    public function record_eventbrite_sale($product_id, $quantity, $booking_date = null, $order_key = null) { // Changed $order_id to $order_key
        // Add start/end tracking for this function
        BRCC_Helpers::log_debug("Starting record_eventbrite_sale for product: {$product_id}, quantity: {$quantity}, key: {$order_key}");
        
        // Duplicate check logic removed - now handled by is_sale_processed() before this call.

        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            $date_info = $booking_date ? " for date {$booking_date}" : "";
            $order_info = $order_key ? " (Order Key: {$order_key})" : ""; // Use order_key for logging
            BRCC_Helpers::log_operation(
                'Eventbrite',
                'Record Sale',
                sprintf(__('Product ID: %s, Quantity: %s%s%s. Would record Eventbrite sale.', 'brcc-inventory-tracker'),
                    $product_id,
                    $quantity,
                    $date_info,
                    $order_info // Use order_key for logging
                )
            );
            return;
        } else if (BRCC_Helpers::should_log()) {
            $date_info = $booking_date ? " for date {$booking_date}" : "";
            $order_info = $order_key ? " (Order Key: {$order_key})" : ""; // Use order_key for logging
            BRCC_Helpers::log_operation(
                'Eventbrite',
                'Record Sale',
                sprintf(__('Product ID: %s, Quantity: %s%s%s. Recording Eventbrite sale. (Live Mode)', 'brcc-inventory-tracker'),
                    $product_id,
                    $quantity,
                    $date_info,
                    $order_info // Use order_key for logging
                )
            );
        }

        // Get the current date (WordPress timezone)
        $date = current_time('Y-m-d');

        // Get existing daily sales data
        $daily_sales = get_option('brcc_daily_sales', []);
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return; // Exit if product not found
        }
        
        $product_name = $product->get_name();
        $sku = $product->get_sku();

        // Create unique key for product + booking date
        $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;

        // Update daily sales for the product
        if (!isset($daily_sales[$date])) {
            $daily_sales[$date] = array();
        }
        
        // Get the product price
        $price = $product->get_price();
        $revenue = $quantity * $price;

        if (isset($daily_sales[$date][$product_key])) {
            // Update existing entry
            $daily_sales[$date][$product_key]['quantity'] += $quantity;
            $daily_sales[$date][$product_key]['revenue'] = isset($daily_sales[$date][$product_key]['revenue']) ?
                ($daily_sales[$date][$product_key]['revenue'] + $revenue) : $revenue;
            $daily_sales[$date][$product_key]['eventbrite'] = isset($daily_sales[$date][$product_key]['eventbrite']) ?
                ($daily_sales[$date][$product_key]['eventbrite'] + $quantity) : $quantity;
            $daily_sales[$date][$product_key]['timestamp'] = current_time('timestamp');
            $daily_sales[$date][$product_key]['source'] = 'eventbrite';
        } else {
            // Create new entry
            $daily_sales[$date][$product_key] = array(
                'name' => $product_name,
                'sku' => $sku,
                'product_id' => $product_id,
                'booking_date' => $booking_date,
                'quantity' => $quantity,
                'revenue' => $revenue,
                'woocommerce' => 0,
                'eventbrite' => $quantity,
                'square' => 0,
                'timestamp' => current_time('timestamp'),
                'source' => 'eventbrite'
            );
        }

        // Save the updated daily sales data
        update_option('brcc_daily_sales', $daily_sales);
        
        // Also update product summary data
        $product_date_quantities = array(
            $product_key => array(
                'product_id' => $product_id,
                'name' => $product_name,
                'sku' => $sku,
                'booking_date' => $booking_date,
                'quantity' => $quantity
            )
        );
        
        $this->update_daily_product_summary($date, $product_date_quantities);
        
        // After saving the daily sales data, verify it worked
        $daily_sales_after = get_option('brcc_daily_sales', []);
        // $date is already defined above
        // $product_key is already defined above
        
        if (isset($daily_sales_after[$date][$product_key])) {
            BRCC_Helpers::log_info("Successfully updated daily sales. New total: {$daily_sales_after[$date][$product_key]['quantity']}");
        } else {
            BRCC_Helpers::log_error("Failed to update daily sales for product: {$product_id}, date: {$date}, key: {$product_key}");
        }
        
        // Mark the order as processed using the specific option requested
        // Note: is_sale_processed() already marks the key in 'brcc_processed_sales'
        if ($order_key) {
            $processed_orders = get_option('brcc_processed_eventbrite_orders', array());
            $processed_orders[$order_key] = time(); // Store timestamp
            
            // Trim the array if it exceeds the maximum size (using the same filter as is_sale_processed)
            $max_processed_sales = apply_filters('brcc_max_processed_sales', 1000);
            if (count($processed_orders) > $max_processed_sales) {
                asort($processed_orders); // Sort by time ascending
                $processed_orders = array_slice($processed_orders, -$max_processed_sales, null, true);
                BRCC_Helpers::log_info('record_eventbrite_sale: Trimmed brcc_processed_eventbrite_orders list to ' . $max_processed_sales . ' entries.');
            }
            
            update_option('brcc_processed_eventbrite_orders', $processed_orders);
            BRCC_Helpers::log_debug("Marked order as processed in brcc_processed_eventbrite_orders: {$order_key}");
        }
        
        BRCC_Helpers::log_debug("Completed record_eventbrite_sale for product: {$product_id}");
    }

    /**
     * Reset only today's sales data without affecting historical data
     */
    public function reset_todays_sales_data() {
        $daily_sales = get_option('brcc_daily_sales', []);
        $today = date('Y-m-d');

        if (isset($daily_sales[$today])) {
            unset($daily_sales[$today]);
            update_option('brcc_daily_sales', $daily_sales);
            return true;
        }

        return false;
    }
    
    /**
     * Record sales from Square
     */
    public function record_square_sale($product_id, $quantity, $booking_date = null) {
        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            $date_info = $booking_date ? " for date {$booking_date}" : "";
            BRCC_Helpers::log_operation(
                'Square',
                'Record Sale',
                sprintf(__('Product ID: %s, Quantity: %s%s. Would record Square sale.', 'brcc-inventory-tracker'), 
                    $product_id, 
                    $quantity,
                    $date_info
                )
            );
            return;
        } else if (BRCC_Helpers::should_log()) {
            $date_info = $booking_date ? " for date {$booking_date}" : "";
            BRCC_Helpers::log_operation(
                'Square',
                'Record Sale',
                sprintf(__('Product ID: %s, Quantity: %s%s. Recording Square sale. (Live Mode)', 'brcc-inventory-tracker'), 
                    $product_id, 
                    $quantity,
                    $date_info
                )
            );
        }

        // Get the current date (WordPress timezone)
        $date = current_time('Y-m-d');

        // Get existing daily sales data
        $daily_sales = get_option('brcc_daily_sales', []);
        
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        $product_name = $product->get_name();
        $sku = $product->get_sku();

        // Create unique key for product + booking date
        $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;

        // Update daily sales for the product
        if (!isset($daily_sales[$date])) {
            $daily_sales[$date] = array();
        }
        
        // Get the product price
        $price = $product->get_price();
        $revenue = $quantity * $price;

        if (isset($daily_sales[$date][$product_key])) {
            // Update existing entry
            $daily_sales[$date][$product_key]['quantity'] += $quantity;
            $daily_sales[$date][$product_key]['revenue'] = isset($daily_sales[$date][$product_key]['revenue']) ?
                ($daily_sales[$date][$product_key]['revenue'] + $revenue) : $revenue;
            $daily_sales[$date][$product_key]['square'] = isset($daily_sales[$date][$product_key]['square']) ?
                ($daily_sales[$date][$product_key]['square'] + $quantity) : $quantity;
            $daily_sales[$date][$product_key]['timestamp'] = current_time('timestamp');
            $daily_sales[$date][$product_key]['source'] = 'square';
        } else {
            // Create new entry
            $daily_sales[$date][$product_key] = array(
                'name' => $product_name,
                'sku' => $sku,
                'product_id' => $product_id,
                'booking_date' => $booking_date,
                'quantity' => $quantity,
                'revenue' => $revenue,
                'woocommerce' => 0,
                'eventbrite' => 0,
                'square' => $quantity,
                'timestamp' => current_time('timestamp'),
                'source' => 'square'
            );
        }

        // Save the updated daily sales data
        update_option('brcc_daily_sales', $daily_sales);
        
        // Also update product summary data
        $product_date_quantities = array(
            $product_key => array(
                'product_id' => $product_id,
                'name' => $product_name,
                'sku' => $sku,
                'booking_date' => $booking_date,
                'quantity' => $quantity
            )
        );
        
        $this->update_daily_product_summary($date, $product_date_quantities);
    }

    /**
    /**
     * Get daily sales data
     */
    public function get_daily_sales($start_date = null, $end_date = null, $product_id = null, $booking_date = null) {
        $daily_sales = get_option('brcc_daily_sales', []);

        // If no date is specified, return all data
        if (null === $start_date && null === $end_date) {
            return $daily_sales;
        }

        $filtered_sales = [];

        // Create a date range
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Include end_date in the range

        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start, $interval, $end);

        // Process each day in the range
        foreach ($date_range as $date) {
            $date_string = $date->format('Y-m-d');

            // If date is specified but no product_id, return all products for that date
            if (isset($daily_sales[$date_string])) {
                $filtered_sales[$date_string] = $daily_sales[$date_string];
            }
        }

        // Handle product and booking date filtering if provided
        if ($product_id) {
            foreach ($filtered_sales as $date_string => $products) {
                foreach ($products as $product_key => $product_data) {
                    $productIdMatches = (isset($product_data['product_id']) && $product_data['product_id'] == $product_id);
                    $bookingDateMatches = (!$booking_date || (isset($product_data['booking_date']) && $product_data['booking_date'] == $booking_date));

                    if (!$productIdMatches || !$bookingDateMatches) {
                        unset($filtered_sales[$date_string][$product_key]);
                    }
                }
                // Remove the date if it has no products after filtering
                if (empty($filtered_sales[$date_string])) {
                    unset($filtered_sales[$date_string]);
                }
            }
        }

        return $filtered_sales;
    }

    /**
     * Get product summary data with date-specific information
     */
    public function get_product_summary($start_date, $end_date = null) {
        $product_summary = get_option('brcc_product_summary', array());
        $result = array();
        
        // If end_date is not specified, use start_date
        if (null === $end_date) {
            $end_date = $start_date;
        }
        
        // Create a date range
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Include end_date in the range
        
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start, $interval, $end);
        
        // Loop through each date in the range
        foreach ($date_range as $date) {
            $date_string = $date->format('Y-m-d');
            
            if (isset($product_summary[$date_string])) {
                foreach ($product_summary[$date_string] as $product_id => $data) {
                    // Defensive check for data format
                    if (!is_array($data) ||
                        !isset($data['name']) ||
                        !isset($data['sku']) ||
                        !isset($data['total_quantity'])) {
                        
                        if (class_exists('BRCC_Helpers')) { // Check if helpers class is available for logging
                            BRCC_Helpers::log_error(
                                "get_product_summary: Unexpected data format encountered.",
                                [
                                    'date' => $date_string,
                                    'product_id' => $product_id,
                                    'retrieved_data' => $data
                                ]
                            );
                        } else {
                            error_log("[BRCC Sales Tracker] get_product_summary: Unexpected data format for product_key {$product_id} on date {$date_string}. Expected array with name, sku, total_quantity.");
                        }
                        continue; // Skip this malformed entry
                    }

                    if (!isset($result[$product_id])) {
                        $result[$product_id] = array(
                            'name' => $data['name'], // Now safe to access
                            'sku' => $data['sku'],   // Now safe to access
                            'total_quantity' => 0,
                            'dates' => array()
                        );
                    }
                    
                    // Ensure 'total_quantity' is numeric before adding
                    $result[$product_id]['total_quantity'] += is_numeric($data['total_quantity']) ? (float)$data['total_quantity'] : 0;
                    
                    // Merge date-specific data
                    if (isset($data['dates']) && is_array($data['dates']) && !empty($data['dates'])) {
                        foreach ($data['dates'] as $event_date => $qty) {
                            if (!isset($result[$product_id]['dates'][$event_date])) {
                                $result[$product_id]['dates'][$event_date] = 0;
                            }
                            // Ensure $qty is numeric before adding
                            $result[$product_id]['dates'][$event_date] += is_numeric($qty) ? (float)$qty : 0;
                        }
                    }
                }
            }
        }
        
        return $result;
    }

    /**
     * Get total sales for a date range
     */
    public function get_total_sales($start_date, $end_date = null) {
        $daily_sales = get_option('brcc_daily_sales', []);
        $total_sales = array();
        
        // If end_date is not specified, use start_date
        if (null === $end_date) {
            $end_date = $start_date;
        }
        
        // Create a date range
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Include end_date in the range
        
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start, $interval, $end);
        
        // Loop through each date in the range
        foreach ($date_range as $date) {
            $date_string = $date->format('Y-m-d');
            
            if (isset($daily_sales[$date_string])) {
                foreach ($daily_sales[$date_string] as $product_key => $product_data) {
                    // Defensive Check: Ensure product_data is an array before processing
                    if (!is_array($product_data)) {
                        BRCC_Helpers::log_error(sprintf( // Corrected typo: log_warning -> log_error
                            'sales_data_shortcode: Corrupted data found for date %s, product_key %s. Expected array, got %s. Skipping entry.',
                            $date_string, $product_key, gettype($product_data)
                        ));
                        continue; // Skip this corrupted entry
                    }

                    // Check if this is a product with booking date
                    $booking_date = isset($product_data['booking_date']) ? $product_data['booking_date'] : null;
                    $product_id = isset($product_data['product_id']) ? $product_data['product_id'] : $product_key;
                    
                    // Create a unique key for the total sales array
                    $total_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;
                    
                    if (!isset($total_sales[$total_key])) {
                        $total_sales[$total_key] = array(
                            'name' => isset($product_data['name']) ? $product_data['name'] : 'N/A', // Added isset check
                            'sku' => isset($product_data['sku']) ? $product_data['sku'] : '', // Added isset check
                            'product_id' => $product_id,
                            'booking_date' => $booking_date,
                            'quantity' => 0,
                            'woocommerce' => 0,
                            'eventbrite' => 0,
                            'square' => 0
                        );
                    }
                    
                    $total_sales[$total_key]['quantity'] += $product_data['quantity'];
                    
                    // Add source-specific quantities
                    if (isset($product_data['woocommerce'])) {
                        $total_sales[$total_key]['woocommerce'] += $product_data['woocommerce'];
                    }
                    
                    if (isset($product_data['eventbrite'])) {
                        $total_sales[$total_key]['eventbrite'] += $product_data['eventbrite'];
                    }
                    
                    if (isset($product_data['square'])) {
                        $total_sales[$total_key]['square'] += $product_data['square'];
                    }
                }
            }
        }
        
        return $total_sales;
    }
    
    /**
     * Get summary by period with daily breakdowns
     * 
     * @param string $start_date Start date in Y-m-d format
     * @param string $end_date End date in Y-m-d format
     * @return array Summary data including daily breakdowns
     */
    public function get_summary_by_period($start_date, $end_date = null) {
        $daily_sales = $this->get_daily_sales($start_date, $end_date);
        $summary = array(
            'total_sales' => 0,
            'total_revenue' => 0,
            'woocommerce_sales' => 0,
            'eventbrite_sales' => 0,
            'square_sales' => 0,
            'unique_products' => array(),
            'days' => array()
        );
        
        // If end_date is not specified, use start_date
        if (null === $end_date) {
            $end_date = $start_date;
        }
        
        // Create a date range
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Include end_date in the range
        
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($start, $interval, $end);
        
        // Process each day in the range
        foreach ($date_range as $date) {
            $date_string = $date->format('Y-m-d');
            
            $day_summary = array(
                'date' => $date_string,
                'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                'total_sales' => 0,
                'woocommerce_sales' => 0,
                'eventbrite_sales' => 0,
                'square_sales' => 0,
                'products' => array()
            );
            
            if (isset($daily_sales[$date_string])) {
                foreach ($daily_sales[$date_string] as $product_key => $product_data) {
                    // Add to day summary
                    if (is_array($product_data)) {
                        // Add to day summary
                        if (isset($product_data['quantity'])) {
                            $day_summary['total_sales'] += $product_data['quantity'];
                        }
                        if (isset($product_data['revenue'])) {
                            $day_summary['total_revenue'] = isset($day_summary['total_revenue']) ?
                                $day_summary['total_revenue'] + $product_data['revenue'] : $product_data['revenue'];
                        }
                        $day_summary['woocommerce_sales'] += isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0;
                        $day_summary['eventbrite_sales'] += isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0;
                        $day_summary['square_sales'] += isset($product_data['square']) ? $product_data['square'] : 0;
                        
                        // Add product details
                        $day_summary['products'][$product_key] = $product_data;
                        
                        // Add to overall summary
                        if (isset($product_data['quantity'])) {
                            $summary['total_sales'] += $product_data['quantity'];
                        }
                        if (isset($product_data['revenue'])) {
                            $summary['total_revenue'] = isset($summary['total_revenue']) ?
                                $summary['total_revenue'] + $product_data['revenue'] : $product_data['revenue'];
                        }
                        $summary['woocommerce_sales'] += isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0;
                        $summary['eventbrite_sales'] += isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0;
                        $summary['square_sales'] += isset($product_data['square']) ? $product_data['square'] : 0;
                        
                        // Track unique products
                        if (isset($product_data['product_id'])) {
                            $summary['unique_products'][$product_data['product_id']] = true;
                        }
}
                         // Log or handle the case where data is not as expected
                         // For now, just skip adding to total to prevent warning
                         BRCC_Helpers::log_error('get_product_summary: Unexpected data format for product_key ' . $product_key . ' on date ' . $date_string . '. Expected array with quantity.', $product_data);
                    $summary['woocommerce_sales'] += isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0;
                    $summary['eventbrite_sales'] += isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0;
                    $summary['square_sales'] += isset($product_data['square']) ? $product_data['square'] : 0;
                }
            }
            
            $summary['days'][$date_string] = $day_summary;
        }
        
        // Log the summary data before returning
        BRCC_Helpers::log_debug('get_summary_by_period: Returning summary data:', $summary);

        return $summary;
    }
    
    /**
     * Import historical sales data
     * 
     * @param array $historical_data Array of historical sales data
     * @return boolean Success or failure
     */
    public function import_historical_sales($historical_data) {
        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'WooCommerce',
                'Import Historical',
                sprintf(__('Would import historical sales data for %d dates', 'brcc-inventory-tracker'), 
                    count($historical_data)
                )
            );
            return true;
        } else if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'WooCommerce',
                'Import Historical',
                sprintf(__('Importing historical sales data for %d dates (Live Mode)', 'brcc-inventory-tracker'), 
                    count($historical_data)
                )
            );
        }
        
        // Get existing daily sales data
        $daily_sales = get_option('brcc_daily_sales', []);
        
        // Loop through historical data and add to daily sales
        foreach ($historical_data as $date => $products) {
            if (!isset($daily_sales[$date])) {
                $daily_sales[$date] = array();
            }
            
            $product_date_quantities = array();
            
            foreach ($products as $product_key => $product_data) {
                // Ensure product_id is set
                if (!isset($product_data['product_id'])) {
                    $product_data['product_id'] = is_numeric($product_key) ? $product_key : null;
                }
                
                // Track for summary update
                $product_date_quantities[$product_key] = array(
                    'product_id' => $product_data['product_id'],
                    'name' => $product_data['name'],
                    'sku' => isset($product_data['sku']) ? $product_data['sku'] : '',
                    'booking_date' => isset($product_data['booking_date']) ? $product_data['booking_date'] : null,
                    'quantity' => $product_data['quantity']
                );
                
                if (isset($daily_sales[$date][$product_key])) {
                    // Update existing entry
                    $daily_sales[$date][$product_key]['quantity'] += $product_data['quantity'];
                    
                    if (isset($product_data['woocommerce'])) {
                        if (isset($daily_sales[$date][$product_key]['woocommerce'])) {
                            $daily_sales[$date][$product_key]['woocommerce'] += $product_data['woocommerce'];
                        } else {
                            $daily_sales[$date][$product_key]['woocommerce'] = $product_data['woocommerce'];
                        }
                    }
                    
                    if (isset($product_data['eventbrite'])) {
                        if (isset($daily_sales[$date][$product_key]['eventbrite'])) {
                            $daily_sales[$date][$product_key]['eventbrite'] += $product_data['eventbrite'];
                        } else {
                            $daily_sales[$date][$product_key]['eventbrite'] = $product_data['eventbrite'];
                        }
                    }
                    
                    if (isset($product_data['square'])) {
                        if (isset($daily_sales[$date][$product_key]['square'])) {
                            $daily_sales[$date][$product_key]['square'] += $product_data['square'];
                        } else {
                            $daily_sales[$date][$product_key]['square'] = $product_data['square'];
                        }
                    }
                } else {
                    // Initialize square field if not present
                    if (!isset($product_data['square'])) {
                        $product_data['square'] = 0;
                    }
                    
                    // Add new entry
                    $daily_sales[$date][$product_key] = $product_data;
                }
            }
            
            // Update product summary for this date
            $this->update_daily_product_summary($date, $product_date_quantities);
        }
        
        // Save updated daily sales data
        return update_option('brcc_daily_sales', $daily_sales);
    }
    
    /**
     * Import historical WooCommerce orders
     * 
     * @param string $start_date Start date in Y-m-d format
     * @param string $end_date End date in Y-m-d format
     * @return boolean Success or failure
     */
    public function import_from_woocommerce($start_date, $end_date) {
        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'WooCommerce',
                'Import from WooCommerce',
                sprintf(__('Would import WooCommerce orders from %s to %s', 'brcc-inventory-tracker'), 
                    $start_date,
                    $end_date
                )
            );
            return true;
        } else if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'WooCommerce',
                'Import from WooCommerce',
                sprintf(__('Importing WooCommerce orders from %s to %s (Live Mode)', 'brcc-inventory-tracker'), 
                    $start_date,
                    $end_date
                )
            );
        }
        
        // Get orders in date range
        $args = array(
            'status' => 'completed',
            'limit' => -1,
            'date_created' => $start_date . '...' . $end_date
        );
        
        $orders = wc_get_orders($args);
        
        if (empty($orders)) {
            return false;
        }
        
        $historical_data = array();
        
        foreach ($orders as $order) {
            // Get order completion date
            $date_completed = $order->get_date_completed();
            if (!$date_completed) {
                continue;
            }
            
            $date = $date_completed->date('Y-m-d');
            
            if (!isset($historical_data[$date])) {
                $historical_data[$date] = array();
            }
            
            $items = $order->get_items();
            foreach ($items as $item) {
                $product_id = $item->get_product_id();
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    continue;
                }
                
                $quantity = $item->get_quantity();
                $product_name = $product->get_name();
                $sku = $product->get_sku();
                
                // Get booking date if available using enhanced detection
                $booking_date = $this->get_booking_date_from_item($item);
                
                // Create unique key for product + booking date
                $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;
                
                if (isset($historical_data[$date][$product_key])) {
                    $historical_data[$date][$product_key]['quantity'] += $quantity;
                    $historical_data[$date][$product_key]['woocommerce'] += $quantity;
                } else {
                    $historical_data[$date][$product_key] = array(
                        'name' => $product_name,
                        'sku' => $sku,
                        'product_id' => $product_id,
                        'booking_date' => $booking_date,
                        'quantity' => $quantity,
                        'woocommerce' => $quantity,
                        'eventbrite' => 0,
                        'square' => 0
                    );
                }
            }
        }
        
        return $this->import_historical_sales($historical_data);
    }
    
    /**
     * Shortcode for displaying sales data
     */
    public function sales_data_shortcode($atts) {
        // Parse shortcode attributes
        $atts = shortcode_atts(array(
            'date' => current_time('Y-m-d'),
            'days' => 7,
            'show_dates' => 'yes', // Show event dates breakdown
            'show_summary' => 'yes' // Show product summary
        ), $atts, 'brcc_sales_data');
        
        // Calculate start date based on days parameter
        $date = new DateTime($atts['date']);
        $start_date = clone $date;
        $start_date->modify('-' . ($atts['days'] - 1) . ' days');
        
        // Get sales data
        $sales_data = $this->get_total_sales($start_date->format('Y-m-d'), $date->format('Y-m-d'));
        
        // Get summary data if enabled
        $show_summary = filter_var($atts['show_summary'], FILTER_VALIDATE_BOOLEAN);
        $show_dates = filter_var($atts['show_dates'], FILTER_VALIDATE_BOOLEAN);
        
        $product_summary = $show_summary ? $this->get_product_summary($start_date->format('Y-m-d'), $date->format('Y-m-d')) : [];
        $period_summary = $this->get_summary_by_period($start_date->format('Y-m-d'), $date->format('Y-m-d'));
        
        // Generate HTML output
        $output = '<div class="brcc-sales-data">';
        $output .= '<h3>' . sprintf(__('Sales Data (%s to %s)', 'brcc-inventory-tracker'), 
                   $start_date->format('M j, Y'), 
                   $date->format('M j, Y')) . '</h3>';
        
        // Add period summary totals
        $output .= '<div class="brcc-period-summary">';
        $output .= '<h4>' . __('Period Summary', 'brcc-inventory-tracker') . '</h4>';
        $output .= '<table class="brcc-period-summary-table">';
        $output .= '<tr>';
        $output .= '<th>' . __('Total Sales', 'brcc-inventory-tracker') . '</th>';
        $output .= '<th>' . __('WooCommerce', 'brcc-inventory-tracker') . '</th>';
        $output .= '<th>' . __('Eventbrite', 'brcc-inventory-tracker') . '</th>';
        $output .= '<th>' . __('Square', 'brcc-inventory-tracker') . '</th>';
        $output .= '</tr>';
        $output .= '<tr>';
        $output .= '<td><strong>' . $period_summary['total_sales'] . '</strong></td>';
        $output .= '<td>' . $period_summary['woocommerce_sales'] . '</td>';
        $output .= '<td>' . $period_summary['eventbrite_sales'] . '</td>';
        $output .= '<td>' . $period_summary['square_sales'] . '</td>';
        $output .= '</tr>';
        $output .= '</table>';
        $output .= '</div>';
        
        if (empty($sales_data)) {
            $output .= '<p>' . __('No sales data available for this period.', 'brcc-inventory-tracker') . '</p>';
        } else {
            // Show detailed breakdown with dates
            if ($show_dates) {
                $output .= '<h4>' . __('Detailed Sales by Event Date', 'brcc-inventory-tracker') . '</h4>';
                $output .= '<table class="brcc-sales-table">';
                $output .= '<thead><tr>';
                $output .= '<th>' . __('Product', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('SKU', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('Event Date', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('Total Qty', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('WooCommerce', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('Eventbrite', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('Square', 'brcc-inventory-tracker') . '</th>';
                $output .= '</tr></thead>';
                $output .= '<tbody>';
                
                // Sort by product name first, then by date
                $sorted_sales = $sales_data;
                uasort($sorted_sales, function($a, $b) {
                    // Sort by product name
                    $name_compare = strcmp($a['name'], $b['name']);
                    if ($name_compare !== 0) {
                        return $name_compare;
                    }
                    
                    // If same product, sort by date
                    $a_date = isset($a['booking_date']) ? $a['booking_date'] : '';
                    $b_date = isset($b['booking_date']) ? $b['booking_date'] : '';
                    return strcmp($a_date, $b_date);
                });
                
                foreach ($sorted_sales as $product_data) {
                    $output .= '<tr>';
                    $output .= '<td>' . esc_html($product_data['name']) . '</td>';
                    $output .= '<td>' . esc_html($product_data['sku']) . '</td>';
                    $output .= '<td>' . esc_html(isset($product_data['booking_date']) ? date_i18n(get_option('date_format'), strtotime($product_data['booking_date'])) : '—') . '</td>';
                    $output .= '<td>' . esc_html($product_data['quantity']) . '</td>';
                    $output .= '<td>' . esc_html(isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0) . '</td>';
                    $output .= '<td>' . esc_html(isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0) . '</td>';
                    $output .= '<td>' . esc_html(isset($product_data['square']) ? $product_data['square'] : 0) . '</td>';
                    $output .= '</tr>';
                }
                
                $output .= '</tbody></table>';
            }
            
            // Show product summary if enabled
            if ($show_summary && !empty($product_summary)) {
                $output .= '<h4>' . __('Sales Summary by Product', 'brcc-inventory-tracker') . '</h4>';
                $output .= '<table class="brcc-sales-summary-table">';
                $output .= '<thead><tr>';
                $output .= '<th>' . __('Product', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('SKU', 'brcc-inventory-tracker') . '</th>';
                $output .= '<th>' . __('Total Sales', 'brcc-inventory-tracker') . '</th>';
                $output .= '</tr></thead>';
                $output .= '<tbody>';
                
                // Sort by product name
                uasort($product_summary, function($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
                
                foreach ($product_summary as $product_id => $data) {
                    $output .= '<tr class="brcc-product-row">';
                    $output .= '<td>' . esc_html($data['name']) . '</td>';
                    $output .= '<td>' . esc_html($data['sku']) . '</td>';
                    $output .= '<td>' . esc_html($data['total_quantity']) . '</td>';
                    $output .= '</tr>';
                    
                    // Add date-specific rows if available
                    if (!empty($data['dates'])) {
                        // Sort dates chronologically
                        ksort($data['dates']);
                        
                        foreach ($data['dates'] as $date => $quantity) {
                            $formatted_date = date_i18n(get_option('date_format'), strtotime($date));
                            $output .= '<tr class="brcc-date-row">';
                            $output .= '<td class="brcc-indent">— ' . __('Event Date:', 'brcc-inventory-tracker') . ' ' . esc_html($formatted_date) . '</td>';
                            $output .= '<td></td>';
                            $output .= '<td>' . esc_html($quantity) . '</td>';
                            $output .= '</tr>';
                        }
                    }
                }
                
                $output .= '</tbody></table>';
            }
        }
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Shortcode for displaying event-specific sales data
     */
    public function event_sales_shortcode($atts) {
        // Parse shortcode attributes
        $atts = shortcode_atts(array(
            'start_date' => '', // Start date for events (optional)
            'end_date' => '',   // End date for events (optional)
            'product_id' => 0,  // Filter by product ID (optional)
            'days' => 30,       // Default range if no dates specified
        ), $atts, 'brcc_event_sales');
        
        // Calculate date range
        $end_date = !empty($atts['end_date']) ? $atts['end_date'] : current_time('Y-m-d');
        $start_date = !empty($atts['start_date']) ? $atts['start_date'] : date('Y-m-d', strtotime('-' . $atts['days'] . ' days', strtotime($end_date)));
        
        // Get product summary with date breakdowns
        $product_summary = $this->get_product_summary($start_date, $end_date);
        
        // Filter by product ID if specified
        if (!empty($atts['product_id'])) {
            if (isset($product_summary[$atts['product_id']])) {
                $product_summary = array($atts['product_id'] => $product_summary[$atts['product_id']]);
            } else {
                $product_summary = array();
            }
        }
        
        // Generate HTML output
        $output = '<div class="brcc-event-sales">';
        $output .= '<h3>' . sprintf(__('Event Sales Report (%s to %s)', 'brcc-inventory-tracker'), 
                   date_i18n(get_option('date_format'), strtotime($start_date)), 
                   date_i18n(get_option('date_format'), strtotime($end_date))) . '</h3>';
        
                   if (empty($product_summary)) {
                    $output .= '<p>' . __('No event sales data available for this period.', 'brcc-inventory-tracker') . '</p>';
                } else {
                    $output .= '<table class="brcc-event-sales-table">';
                    $output .= '<thead><tr>';
                    $output .= '<th>' . __('Product', 'brcc-inventory-tracker') . '</th>';
                    $output .= '<th>' . __('Event Date', 'brcc-inventory-tracker') . '</th>';
                    $output .= '<th>' . __('Sales', 'brcc-inventory-tracker') . '</th>';
                    $output .= '</tr></thead>';
                    $output .= '<tbody>';
                    
                    foreach ($product_summary as $product_id => $data) {
                        // Skip products with no date-specific data
                        if (empty($data['dates'])) {
                            continue;
                        }
                        
                        $first_row = true;
                        
                        // Sort dates chronologically
                        ksort($data['dates']);
                        
                        foreach ($data['dates'] as $date => $quantity) {
                            $output .= '<tr>';
                            
                            if ($first_row) {
                                $output .= '<td rowspan="' . count($data['dates']) . '">' . esc_html($data['name']) . '</td>';
                                $first_row = false;
                            }
                            
                            $formatted_date = date_i18n(get_option('date_format'), strtotime($date));
                            $output .= '<td>' . esc_html($formatted_date) . '</td>';
                            $output .= '<td>' . esc_html($quantity) . '</td>';
                            $output .= '</tr>';
                        }
                        
                        // Add a total row for this product
                        $output .= '<tr class="brcc-total-row">';
                        $output .= '<td colspan="2"><strong>' . __('Total for', 'brcc-inventory-tracker') . ' ' . esc_html($data['name']) . '</strong></td>';
                        $output .= '<td><strong>' . esc_html($data['total_quantity']) . '</strong></td>';
                        $output .= '</tr>';
                    }
                    
                    $output .= '</tbody></table>';
                }
                
                $output .= '</div>';
                
                return $output;
            }
            
            /**
             * Import a batch of historical WooCommerce orders
             *
             * @param string $start_date Start date (Y-m-d)
             * @param string $end_date End date (Y-m-d)
             * @param int $offset Number of orders to skip
             * @param int $limit Number of orders per batch
             * @return array Result array with processed_count, next_offset, source_complete, logs
             */
            public function import_woocommerce_batch($start_date, $end_date, $offset, $limit) {
                $logs = array();
                $processed_count = 0;
                $source_complete = false;
            
                $logs[] = array('message' => "Querying WooCommerce orders from {$start_date} to {$end_date}, offset {$offset}, limit {$limit}...", 'type' => 'info');
            
                // Query orders
                $args = array(
                    'limit'        => $limit,
                    'offset'       => $offset,
                    'orderby'      => 'date',
                    'order'        => 'ASC',
                    'status'       => array('wc-completed'), // Only completed orders
                    'date_created' => $start_date . '...' . $end_date, // Filter by date created
                    'return'       => 'ids', // Get only IDs for performance
                );
                BRCC_Helpers::log_debug("import_woocommerce_batch: Querying orders with args:", $args); // Restored debug log
                $order_ids = wc_get_orders($args);
                $found_count = is_array($order_ids) ? count($order_ids) : 0; // Handle potential non-array return
                BRCC_Helpers::log_debug("import_woocommerce_batch: Found {$found_count} order IDs."); // Restored debug log
                
                if (empty($order_ids)) {
                    $logs[] = array('message' => "No more WooCommerce orders found in this batch/date range.", 'type' => 'info');
                    $source_complete = true;
                } else {
                    BRCC_Helpers::log_debug("import_woocommerce_batch: Starting loop for {$found_count} orders."); // Restored debug log
                    $logs[] = array('message' => "Found " . count($order_ids) . " WooCommerce order(s) in this batch.", 'type' => 'info');
                    foreach ($order_ids as $order_id) {
                        try {
                            $order = wc_get_order($order_id); // This can be resource intensive
                            if (!$order) {
                                $logs[] = array('message' => "Could not retrieve order #{$order_id}.", 'type' => 'warning');
                                continue;
                            }
                
                            $order_date_completed = $order->get_date_completed();
                            // Use date created if completion date is missing (unlikely for completed orders)
                            $sale_date = $order_date_completed ? $order_date_completed->date('Y-m-d') : $order->get_date_created()->date('Y-m-d');
                
                            $items = $order->get_items();
                            foreach ($items as $item_id => $item) {
                                $product_id = $item->get_product_id();
                                $quantity = $item->get_quantity();
                                
                                if (!$product_id || $quantity <= 0) {
                                    continue;
                                }
                
                                // Get booking/event date if available
                                $booking_date = $this->get_booking_date_from_item($item);
                
                                // Record the historical sale
                                $recorded = $this->record_historical_sale('WooCommerce', $product_id, $quantity, $sale_date, $booking_date, $order_id);
                                // Note: $recorded is false if it was skipped (e.g., duplicate)
                            }
                            
                            $processed_count++; // Increment processed order count
                            
                            // Log progress periodically
                            if ($processed_count % 5 === 0) { // Log every 5 orders processed // Restored debug log block
                                BRCC_Helpers::log_debug("import_woocommerce_batch: Processed {$processed_count}/{$found_count} orders in this batch so far...");
                            }
                            
                            $logs[] = array('message' => "Processed order #{$order_id} (Date: {$sale_date}).", 'type' => 'info');

                        } catch (Exception $e) {
                            $error_msg = "Error processing WooCommerce order #{$order_id}: " . $e->getMessage();
                            $logs[] = array('message' => $error_msg, 'type' => 'error');
                            BRCC_Helpers::log_error("Historical Import (WooCommerce): " . $error_msg);
                            // Continue to the next order instead of stopping the batch
                        }
                    }
            
                    // Check if this was the last batch
                    if (count($order_ids) < $limit) {
                        $logs[] = array('message' => "Last batch processed for WooCommerce in this date range.", 'type' => 'info');
                        $source_complete = true;
                    }
                }
                BRCC_Helpers::log_debug("import_woocommerce_batch: Batch finished. Processed: {$processed_count}. Source Complete: " . ($source_complete ? 'Yes' : 'No') . ". Next Offset: " . ($source_complete ? 'null' : $offset + $found_count)); // Restored debug log
                
                
                return array(
                    'processed_count' => $processed_count,
                    'next_offset'     => $source_complete ? null : $offset + $found_count, // Use actual count for next offset
                    'source_complete' => $source_complete,
                    'logs'            => $logs
                );
            }
            
            /**
             * Record a historical sale without triggering live syncs
             *
             * @param string $source 'WooCommerce', 'Square', 'Eventbrite'
             * @param int $product_id
             * @param int $quantity
             * @param string $sale_date Original date of the sale (Y-m-d)
             * @param string|null $booking_date Optional booking/event date (Y-m-d)
             * @param string $order_ref Optional reference ID (WC Order ID, Square Order ID, etc.)
             */
            private function record_historical_sale($source, $product_id, $quantity, $sale_date, $booking_date = null, $order_ref = '') {
                
                // Basic validation
                if (empty($product_id) || empty($quantity) || empty($sale_date)) {
                    return false;
                }

                // --- Duplicate Check ---
                $source_key = strtolower($source); // 'woocommerce', 'square', 'eventbrite'
                if (!empty($order_ref)) {
                    $imported_refs = get_option('brcc_imported_refs', array());
                    if (isset($imported_refs[$source_key][$order_ref])) {
                        // Already imported, skip
                        // Optional: Log this skip? Maybe too verbose for normal operation.
                        // BRCC_Helpers::log_debug("Historical Import: Skipping duplicate {$source} sale ref {$order_ref}.");
                        return false; // Indicate skipped
                    }
                }
                // --- End Duplicate Check ---
                
                // Get product details
                $product = wc_get_product($product_id);
                if (!$product) {
                     BRCC_Helpers::log_error("Historical Import: Product ID {$product_id} not found for {$source} sale ref {$order_ref}.");
                    return false;
                }
                $product_name = $product->get_name();
                $sku = $product->get_sku();
            
                // Get existing daily sales data
                $daily_sales = get_option('brcc_daily_sales', []);
            
                // Create unique key for product + booking date
                $product_key = $booking_date ? $product_id . '_' . $booking_date : $product_id;
            
                // Ensure the sale date entry exists
                if (!isset($daily_sales[$sale_date])) {
                    $daily_sales[$sale_date] = array();
                }
            
                // Update daily sales for the product on the original sale date
                $source_key = strtolower($source); // 'woocommerce', 'square', 'eventbrite'
                
                if (isset($daily_sales[$sale_date][$product_key])) {
                    // Increment total quantity
                    $daily_sales[$sale_date][$product_key]['quantity'] = ($daily_sales[$sale_date][$product_key]['quantity'] ?? 0) + $quantity;
                    // Increment source-specific quantity
                    $daily_sales[$sale_date][$product_key][$source_key] = ($daily_sales[$sale_date][$product_key][$source_key] ?? 0) + $quantity;
                } else {
                    // Create new entry for this product/booking date on the sale date
                    $daily_sales[$sale_date][$product_key] = array(
                        'name'         => $product_name,
                        'sku'          => $sku,
                        'product_id'   => $product_id,
                        'booking_date' => $booking_date,
                        'quantity'     => $quantity,
                        'woocommerce'  => ($source_key === 'woocommerce' ? $quantity : 0),
                        'eventbrite'   => ($source_key === 'eventbrite' ? $quantity : 0),
                        'square'       => ($source_key === 'square' ? $quantity : 0),
                    );
                }
            
                // Save the updated daily sales data
                $sales_updated = update_option('brcc_daily_sales', $daily_sales);
            
                // Also update product summary data for the original sale date
                $product_date_quantities = array(
                    $product_key => array(
                        'product_id'   => $product_id,
                        'name'         => $product_name,
                        'sku'          => $sku,
                        'booking_date' => $booking_date,
                        'quantity'     => $quantity
                    )
                );
                $summary_updated = $this->update_daily_product_summary($sale_date, $product_date_quantities);

                // --- Mark as Imported ---
                if ($sales_updated && $summary_updated && !empty($order_ref)) {
                    $imported_refs = get_option('brcc_imported_refs', array()); // Get fresh copy
                    if (!isset($imported_refs[$source_key])) {
                        $imported_refs[$source_key] = array();
                    }
                    $imported_refs[$source_key][$order_ref] = true; // Mark this ref as imported for this source
                    update_option('brcc_imported_refs', $imported_refs);
                } elseif (empty($order_ref)) {
                     BRCC_Helpers::log_warning("Historical Import: Order reference was empty for {$source} sale of Product ID {$product_id} on {$sale_date}. Cannot mark as imported.");
                }
                // --- End Mark as Imported ---

                // DO NOT trigger live sync actions like do_action('brcc_product_sold_with_date', ...)

                return true; // Return true even if marking failed but data was saved
            }
            /**
             * Import a batch of historical Eventbrite orders.
             *
             * @param string $start_date Start date (Y-m-d).
             * @param string $end_date End date (Y-m-d).
             * @param int $page Page number to fetch.
             * @param int $batch_size Number of orders per page (max 50 for Eventbrite).
             * @return array Result array with processed_count, next_offset (page), source_complete, logs.
             */
            public function import_eventbrite_batch($start_date, $end_date, $page, $batch_size) {
                $logs = array();
                $processed_count = 0;
                $next_page = null;
                $source_complete = false;
                $batch_size = min(50, $batch_size); // Eventbrite max page size is 50
                
                BRCC_Helpers::log_debug("import_eventbrite_batch: Function started.", ['start' => $start_date, 'end' => $end_date, 'page' => $page]); // Re-added debug log
                $logs[] = array('message' => "Starting Eventbrite import batch - Page: {$page}", 'type' => 'info');

                try {
                    if (!class_exists('BRCC_Eventbrite_Integration')) {
                        throw new Exception('Eventbrite Integration class not found.');
                    }
                    $eventbrite_integration = new BRCC_Eventbrite_Integration();
                    $product_mappings = new BRCC_Product_Mappings();

                    // Format dates for Eventbrite API (ISO 8601 UTC)
                    // Add time component to ensure full day coverage in UTC
                    // Note: Eventbrite API date filtering can be inconsistent, especially for 'created'.
                    // It's often better to fetch pages and filter locally.
                    // $start_utc = gmdate('Y-m-d\TH:i:s\Z', strtotime($start_date . ' 00:00:00'));
                    // $end_utc = gmdate('Y-m-d\TH:i:s\Z', strtotime($end_date . ' 23:59:59'));

                    // Fetch orders from Eventbrite API using /users/me/orders
                    // We need to implement get_user_orders in the integration class
                    if (!method_exists($eventbrite_integration, 'get_user_orders')) {
                         throw new Exception('Required method get_user_orders does not exist in BRCC_Eventbrite_Integration.');
                    }
                    BRCC_Helpers::log_debug("import_eventbrite_batch: Calling get_user_orders.", ['page' => $page, 'size' => $batch_size]); // Re-added debug log
                    $api_response = $eventbrite_integration->get_user_orders($page, $batch_size);
                    BRCC_Helpers::log_debug("import_eventbrite_batch: Returned from get_user_orders."); // Re-added debug log

                    if (is_wp_error($api_response)) {
                        throw new Exception('Eventbrite API Error: ' . $api_response->get_error_message());
                    }

                    if (!isset($api_response['orders']) || !is_array($api_response['orders'])) {
                         throw new Exception('Invalid response structure from Eventbrite API (missing orders array). Response: ' . print_r($api_response, true));
                    }

                    $orders = $api_response['orders'];
                    $pagination = $api_response['pagination'] ?? null;

                    $logs[] = array('message' => "Fetched " . count($orders) . " orders from Eventbrite API (Page: {$page}). Filtering by date range {$start_date} to {$end_date}...", 'type' => 'info');

                    foreach ($orders as $order) {
                        $order_created_str = $order['created'] ?? null;
                        $order_id_ref = $order['id'] ?? 'N/A';

                        if (!$order_created_str) {
                            $logs[] = array('message' => "Skipping order {$order_id_ref}: Missing creation date.", 'type' => 'warning');
                            continue;
                        }

                        // Check if order date is within the requested range
                        $order_timestamp = strtotime($order_created_str);
                        $order_date_ymd = date('Y-m-d', $order_timestamp);

                        if ($order_date_ymd < $start_date || $order_date_ymd > $end_date) {
                             // $logs[] = array('message' => "Skipping order {$order_id_ref}: Date {$order_date_ymd} outside requested range ({$start_date} - {$end_date}).", 'type' => 'debug'); // Too verbose, comment out
                             continue;
                        }

                        // Process attendees within the order
                        // Eventbrite order structure might vary. We might need attendee details if not expanded in the order call.
                        // Assuming attendees are included or need separate fetching (more complex).
                        // For simplicity now, let's assume the order implies 1 unit sold per relevant ticket class found in mappings.
                        // A more robust solution would fetch attendee details if needed.

                        // We need the Ticket Class ID from the order/attendee to map it.
                        // Let's assume for now the order details include attendee/ticket info if expanded.
                        // If not, this logic needs adjustment to fetch attendees per order.
                        
                        $attendees = $order['attendees'] ?? []; // Assuming attendees are expanded
                        if (empty($attendees)) {
                             // If attendees aren't expanded, we might need another API call here, or make assumptions.
                             // For now, log a warning if attendees are missing.
                             $logs[] = array('message' => "Order {$order_id_ref}: Attendees data not found or empty in API response. Cannot process sales accurately without Ticket Class IDs.", 'type' => 'warning');
                             continue;
                        }

                        $attendees_processed_in_order = 0;
                        foreach ($attendees as $attendee) {
                             $ticket_class_id = $attendee['ticket_class_id'] ?? null;
                             $attendee_status = $attendee['status'] ?? 'unknown';
                             $attendee_cancelled = $attendee['cancelled'] ?? false;
                             $attendee_refunded = $attendee['refunded'] ?? false;
                             
                             // Try to get event date for context mapping
                             $event_start_local = $order['event']['start']['local'] ?? null;
                             $booking_date = $event_start_local ? date('Y-m-d', strtotime($event_start_local)) : null;

                             // Skip cancelled/refunded, only process valid tickets
                             if ($attendee_cancelled || $attendee_refunded || $attendee_status === 'Refunded' || $attendee_status === 'Cancelled') {
                                 // $logs[] = array('message' => "Skipping attendee in order {$order_id_ref}: Status is cancelled/refunded.", 'type' => 'debug'); // Too verbose
                                 continue;
                             }

                             if (!$ticket_class_id) {
                                 $logs[] = array('message' => "Skipping attendee in order {$order_id_ref}: Missing Ticket Class ID.", 'type' => 'warning');
                                 continue;
                             }

                             // Find mapped product ID
                             $wc_product_id = $product_mappings->find_product_id_for_ticket_id($ticket_class_id, $booking_date); // Pass booking date for context

                             if ($wc_product_id) {
                                 // Record the sale (quantity is usually 1 per attendee)
                                 $this->record_historical_sale('Eventbrite', $wc_product_id, 1, $order_date_ymd, $booking_date, $order_id_ref);
                                 $attendees_processed_in_order++;
                             } else {
                                 $logs[] = array('message' => "Skipping attendee sale in order {$order_id_ref}: No WC product mapping found for Eventbrite Ticket Class ID {$ticket_class_id} (Booking Date: {$booking_date}).", 'type' => 'warning');
                             }
                        }
                         if ($attendees_processed_in_order > 0) {
                             $processed_count++; // Count orders with at least one processed attendee
                             $logs[] = array('message' => "Processed {$attendees_processed_in_order} attendee(s) for Order ID {$order_id_ref}.", 'type' => 'info');
                         }
                    }

                    // Handle pagination
                    if ($pagination && isset($pagination['has_more_items']) && $pagination['has_more_items']) {
                        $next_page = ($pagination['page_number'] ?? $page) + 1;
                        $logs[] = array('message' => "More Eventbrite pages exist. Next page: {$next_page}", 'type' => 'info');
                    } else {
                        $source_complete = true;
                        $logs[] = array('message' => "Finished processing all Eventbrite pages for this date range.", 'type' => 'info');
                    }

                } catch (Exception $e) {
                    $logs[] = array('message' => 'Error during Eventbrite import batch: ' . $e->getMessage(), 'type' => 'error');
                    // Do NOT set $source_complete = true here. Allow the process to potentially continue to the next source.
                    // The batch for this specific page failed, but maybe the next source can proceed.
                    // We also won't have a valid $next_page, so the loop for Eventbrite will naturally stop.
                    $next_page = null; // Ensure we don't try to continue Eventbrite pagination after an error
                }

                return array(
                    'processed_count' => $processed_count,
                    'next_offset'     => $next_page, // Page number or null
                    'source_complete' => $source_complete,
                    'logs'            => $logs
                );
            } // End import_eventbrite_batch

        } // End class BRCC_Sales_Tracker
        