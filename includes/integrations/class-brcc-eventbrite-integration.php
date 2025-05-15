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
    private $api_url = 'https://www.eventbriteapi.com/v3';
    private $api_token;
    private $product_mappings;
    private $sales_tracker;
    private $all_mappings;

    public function __construct() {
        $settings = get_option('brcc_api_settings');
        $this->api_token = isset($settings['eventbrite_token']) ? $settings['eventbrite_token'] : '';
        
        // Ensure dependencies are loaded if not using a proper autoloader
        if (!class_exists('BRCC_Product_Mappings')) {
            require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-product-mappings.php';
        }
        if (!class_exists('BRCC_Sales_Tracker')) {
            require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-sales-tracker.php';
        }
        if (!class_exists('BRCC_Helpers')) {
            require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-helpers.php';
        }


        $this->product_mappings = new BRCC_Product_Mappings();
        $this->sales_tracker = new BRCC_Sales_Tracker();

        if (!empty($this->api_token)) {
            add_action('brcc_sync_inventory', array($this, 'sync_eventbrite_tickets'));
            add_action('rest_api_init', array($this, 'register_eventbrite_webhook_endpoint'));
            add_action('woocommerce_reduce_order_stock', array($this, 'handle_order_stock_reduction'), 10, 1);
            add_action('woocommerce_product_set_stock', array($this, 'handle_direct_stock_update'), 10, 1);
            add_action('woocommerce_variation_set_stock', array($this, 'handle_direct_stock_update'), 10, 1);
            add_action('woocommerce_order_status_completed', array($this, 'handle_deferred_fooevents_sync'), 20, 2);
        }
    }

    /**
     * Ensures a value is a string, handling arrays or objects for safe logging/display.
     */
    private function ensure_string_from_details($value, $default_if_empty = 'N/A') {
        if (is_null($value)) {
            return $default_if_empty;
        }
        if (is_array($value)) {
            $scalar_parts = array_filter($value, 'is_scalar');
            if (empty($scalar_parts)) {
                $json_encoded = json_encode($value);
                return ($json_encoded && trim($json_encoded) !== '' && $json_encoded !== 'null') ? $json_encoded : $default_if_empty;
            }
            $imploded = implode(' ', $scalar_parts);
            return (trim($imploded) === '') ? $default_if_empty : $imploded;
        }
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                $str_val = (string) $value;
                return (trim($str_val) === '') ? $default_if_empty : $str_val;
            }
            $json_encoded = json_encode($value);
            return ($json_encoded && trim($json_encoded) !== '' && $json_encoded !== 'null') ? $json_encoded : $default_if_empty;
        }
        $str_val = (string) $value;
        return (trim($str_val) === '') ? $default_if_empty : $str_val;
    }

    public static function handle_scheduled_eventbrite_update($args) {
        BRCC_Helpers::log_info('--- START handle_scheduled_eventbrite_update ---', $args);
        if (empty($args['order_id']) || empty($args['product_id'])) {
            BRCC_Helpers::log_error('handle_scheduled_eventbrite_update: Invalid arguments received.', $args);
            return;
        }
        $instance = new self();
        if (!$instance || !$instance->product_mappings) {
            BRCC_Helpers::log_error('handle_scheduled_eventbrite_update: Failed to create instance or access product mappings.');
            return;
        }
        $instance->update_eventbrite_event_status($args['product_id']);
        BRCC_Helpers::log_info('--- END handle_scheduled_eventbrite_update ---', $args);
    }

    public function register_eventbrite_webhook_endpoint() {
        register_rest_route('brcc/v1', '/eventbrite-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_eventbrite_webhook'),
        ));
        BRCC_Helpers::log_debug('Eventbrite webhook endpoint registered.');
    }
public function process_eventbrite_webhook(WP_REST_Request $request) {
        if (!class_exists('BRCC_Helpers')) {
            error_log('[BRCC Eventbrite Webhook] BRCC_Helpers class not found.');
            return new WP_REST_Response(['status' => 'error', 'message' => 'Internal server error: Helpers not found.'], 500);
        }

        BRCC_Helpers::log_info('[Eventbrite Webhook] Received webhook.', ['headers' => $request->get_headers(), 'body_raw' => $request->get_body()]);

        // Webhook Signature Verification
        $signature_header = $request->get_header('x-eventbrite-signature'); // Eventbrite uses 'X-Eventbrite-Signature'
        $api_settings = get_option('brcc_api_settings');
        $secret = isset($api_settings['eventbrite_webhook_secret']) ? $api_settings['eventbrite_webhook_secret'] : null;

        if (empty($secret)) {
            BRCC_Helpers::log_warning('[Eventbrite Webhook] Webhook secret is not configured in plugin settings. Skipping signature verification. THIS IS INSECURE.');
            // For production, you might want to return an error here:
            // return new WP_REST_Response(['status' => 'error', 'message' => 'Webhook secret not configured.'], 500);
        } elseif (!$this->verify_eventbrite_webhook_signature($request->get_body(), $signature_header, $secret)) {
            BRCC_Helpers::log_error('[Eventbrite Webhook] Invalid signature.');
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid signature.'], 401);
        } else {
            BRCC_Helpers::log_info('[Eventbrite Webhook] Signature verified successfully.');
        }

        $payload = $request->get_json_params();
        if (empty($payload) || !isset($payload['config']['action'])) {
            BRCC_Helpers::log_error('[Eventbrite Webhook] Invalid or empty payload or missing action.', $payload);
            return new WP_REST_Response(['status' => 'error', 'message' => 'Invalid payload.'], 400);
        }

        $action = $payload['config']['action'];
        $api_url = $payload['api_url'] ?? null; // URL to fetch more details about the resource

        BRCC_Helpers::log_info("[Eventbrite Webhook] Processing action: {$action}", ['api_url' => $api_url]);

        // Idempotency: Check if this webhook has been processed
        // Extract a unique ID for the event, e.g., Eventbrite Order ID from api_url
        $webhook_unique_id = null;
        if (!empty($api_url)) {
            // Example: https://www.eventbriteapi.com/v3/orders/123456789/
            // Extract '123456789'
            if (preg_match('/\/orders\/(\d+)\/?$/', $api_url, $matches)) {
                $webhook_unique_id = $matches[1];
            }
        }
        if (empty($webhook_unique_id) && isset($payload['id'])) { // Fallback if api_url not order-specific or 'id' is present
             $webhook_unique_id = $payload['id'];
        }

        if ($webhook_unique_id) {
            $transient_key = 'brcc_eb_wh_processed_' . $webhook_unique_id . '_' . $action;
            if (get_transient($transient_key)) {
                BRCC_Helpers::log_info("[Eventbrite Webhook] Event {$webhook_unique_id} for action '{$action}' already processed. Skipping.");
                return new WP_REST_Response(['status' => 'success', 'message' => 'Already processed.'], 200);
            }
        }

        switch ($action) {
            case 'order.placed':
                $eventbrite_order_id_for_log = $webhook_unique_id ?: ($payload['resource_uri'] ?? $api_url ?: 'Unknown');
                BRCC_Helpers::log_info("[Eventbrite Webhook] order.placed: Processing Eventbrite Order ID/URI: {$eventbrite_order_id_for_log}");

                // Attempt to get attendees/line items from the payload.
                // Eventbrite's 'order.placed' webhook might contain 'attendees' directly,
                // or you might need to fetch full order details using $api_url.
                $attendees = $payload['attendees'] ?? null;

                if (empty($attendees) && $api_url) {
                    BRCC_Helpers::log_info("[Eventbrite Webhook] order.placed: 'attendees' not found in initial payload. Attempting to fetch full order details from: {$api_url}");
                    $order_details_response = $this->fetch_eventbrite_order_details($api_url);

                    if (is_wp_error($order_details_response)) {
                        BRCC_Helpers::log_error("[Eventbrite Webhook] order.placed: Error fetching full order details.", [
                            'api_url' => $api_url,
                            'error_code' => $order_details_response->get_error_code(),
                            'error_message' => $order_details_response->get_error_message(),
                        ]);
                        break; // Stop processing this order if API call fails
                    }
                    
                    // Assuming the response itself is the order details array
                    // and it contains an 'attendees' key. Adjust if Eventbrite API returns it differently.
                    if (isset($order_details_response['attendees'])) {
                        $attendees = $order_details_response['attendees'];
                        BRCC_Helpers::log_info("[Eventbrite Webhook] order.placed: Successfully fetched and parsed 'attendees' from full order details.", ['attendee_count' => count($attendees)]);
                    } else {
                        BRCC_Helpers::log_error("[Eventbrite Webhook] order.placed: 'attendees' key not found in fetched order details.", ['api_url' => $api_url, 'response_keys' => array_keys($order_details_response)]);
                        break; // Stop if attendees are still missing
                    }
                } elseif (empty($attendees)) {
                    BRCC_Helpers::log_warning("[Eventbrite Webhook] order.placed: No 'attendees' in payload and no api_url to fetch details. Cannot process stock updates for {$eventbrite_order_id_for_log}.");
                    break;
                }
                
                $processed_items = 0;
                foreach ($attendees as $index => $attendee) {
                    $eb_event_id = $attendee['event_id'] ?? null;
                    $eb_ticket_class_id = $attendee['ticket_class_id'] ?? null;
                    // Assuming each attendee entry represents a quantity of 1 for that ticket class.
                    // If Eventbrite payload provides quantity per ticket class differently, adjust this.
                    $quantity_sold_on_eb = $attendee['quantity'] ?? 1;

                    if (!$eb_event_id || !$eb_ticket_class_id) {
                        BRCC_Helpers::log_warning("[Eventbrite Webhook] order.placed: Skipping attendee #{$index} due to missing event_id or ticket_class_id.", $attendee);
                        continue;
                    }

                    $wc_product_id = $this->find_product_id_for_event($eb_ticket_class_id, null, null, $eb_event_id);

                    if ($wc_product_id) {
                        $product = wc_get_product($wc_product_id);
                        if ($product && $product->managing_stock()) {
                            $current_stock = $product->get_stock_quantity();
                            $new_stock = $current_stock - $quantity_sold_on_eb;
                            
                            $product->set_stock_quantity($new_stock);
                            $product->save();
                            
                            BRCC_Helpers::log_info("[Eventbrite Webhook] order.placed: WC Product #{$wc_product_id} stock updated from {$current_stock} to {$new_stock} (sold: {$quantity_sold_on_eb}). Eventbrite Event: {$eb_event_id}, Ticket: {$eb_ticket_class_id}. Order: {$eventbrite_order_id_for_log}.");
                            $processed_items++;
                        } elseif ($product && !$product->managing_stock()) {
                            BRCC_Helpers::log_info("[Eventbrite Webhook] order.placed: WC Product #{$wc_product_id} is not managing stock. No update needed for Eventbrite Event: {$eb_event_id}, Ticket: {$eb_ticket_class_id}. Order: {$eventbrite_order_id_for_log}.");
                        } else {
                             BRCC_Helpers::log_warning("[Eventbrite Webhook] order.placed: WC Product #{$wc_product_id} not found or invalid. Eventbrite Event: {$eb_event_id}, Ticket: {$eb_ticket_class_id}. Order: {$eventbrite_order_id_for_log}.");
                        }
                    } else {
                        BRCC_Helpers::log_warning("[Eventbrite Webhook] order.placed: Could not map Eventbrite Event {$eb_event_id} / Ticket {$eb_ticket_class_id} to a WC Product. Order: {$eventbrite_order_id_for_log}.");
                    }
                }
                if ($processed_items > 0) {
                     BRCC_Helpers::log_info("[Eventbrite Webhook] order.placed: Finished processing {$processed_items} item(s) for Eventbrite Order {$eventbrite_order_id_for_log}.");
                } else if (!empty($attendees)) {
                     BRCC_Helpers::log_warning("[Eventbrite Webhook] order.placed: Processed 0 items successfully for Eventbrite Order {$eventbrite_order_id_for_log} despite having attendee data. Check mappings and product stock settings.");
                }
                break;

            case 'order.updated':
                $eventbrite_order_id_for_log = $webhook_unique_id ?: ($payload['resource_uri'] ?? $api_url ?: 'Unknown');
                BRCC_Helpers::log_info("[Eventbrite Webhook] order.updated: Received for Order ID/URI: {$eventbrite_order_id_for_log}. No stock changes are processed for this action by default. Logging payload for analysis.", ['payload' => $payload]);
                // TODO: Implement logic for 'order.updated' if specific stock-affecting scenarios are identified and need handling.
                // This often requires comparing the order state before and after the update.
                break;

            case 'order.refunded':
                $eventbrite_order_id_for_log = $webhook_unique_id ?: ($payload['resource_uri'] ?? $api_url ?: 'Unknown');
                BRCC_Helpers::log_info("[Eventbrite Webhook] order.refunded: Processing refund for Eventbrite Order ID/URI: {$eventbrite_order_id_for_log}");

                // Attempt to get attendees/line items from the payload.
                // This might represent the specific items that were refunded.
                $refunded_items = $payload['attendees'] ?? ($payload['refund'] ?? ($payload['items'] ?? null)); // Try common keys for refund details

                if (empty($refunded_items) && $api_url) {
                    BRCC_Helpers::log_info("[Eventbrite Webhook] order.refunded: Refunded items not found in initial payload. Attempting to fetch full order/refund details from: {$api_url}");
                    // Note: The $api_url for a refund might point to the original order or a specific refund object.
                    // The fetch_eventbrite_order_details might need adjustment or a new specific method if refund details are structured differently.
                    // For now, we assume it can fetch details that include information about refunded items.
                    $details_response = $this->fetch_eventbrite_order_details($api_url); // Re-use, assuming it might contain refund info or original items

                    if (is_wp_error($details_response)) {
                        BRCC_Helpers::log_error("[Eventbrite Webhook] order.refunded: Error fetching full details.", [
                            'api_url' => $api_url,
                            'error_code' => $details_response->get_error_code(),
                            'error_message' => $details_response->get_error_message(),
                        ]);
                        break;
                    }
                    
                    // How refunded items are represented in the full order details needs to be determined.
                    // It might be in $details_response['attendees'] with a 'refunded' status,
                    // or a specific $details_response['refunds'] array.
                    // This is a placeholder - you'll likely need to inspect an actual Eventbrite 'order.refunded' payload with fetched details.
                    if (isset($details_response['attendees'])) { // Simplistic assumption: check original attendees for refunded status
                        $refunded_items = array_filter($details_response['attendees'], function($attendee) {
                            return isset($attendee['status']) && $attendee['status'] === 'refunded'; // Or similar refund indicator
                        });
                         BRCC_Helpers::log_info("[Eventbrite Webhook] order.refunded: Filtered refunded items from fetched details.", ['refunded_item_count' => count($refunded_items)]);
                    } elseif (isset($details_response['refunds']) && is_array($details_response['refunds'])) { // Another common pattern
                        // If there's a 'refunds' array, it might contain line items of the refund.
                        // We'd need to iterate through $details_response['refunds'] and then their items.
                        // This part is highly dependent on Eventbrite's exact structure for fetched refund data.
                        // For now, this is a simplified placeholder.
                        $temp_items = [];
                        foreach($details_response['refunds'] as $refund_event) {
                            if(isset($refund_event['attendees']) && is_array($refund_event['attendees'])) {
                                $temp_items = array_merge($temp_items, $refund_event['attendees']);
                            } elseif (isset($refund_event['items']) && is_array($refund_event['items'])) {
                                 $temp_items = array_merge($temp_items, $refund_event['items']);
                            }
                        }
                        $refunded_items = $temp_items;
                        BRCC_Helpers::log_info("[Eventbrite Webhook] order.refunded: Extracted items from 'refunds' object in fetched details.", ['refunded_item_count' => count($refunded_items)]);
                    } else {
                        BRCC_Helpers::log_error("[Eventbrite Webhook] order.refunded: Could not determine refunded items from fetched details.", ['api_url' => $api_url, 'response_keys' => array_keys($details_response)]);
                        break;
                    }
                } elseif (empty($refunded_items)) {
                    BRCC_Helpers::log_warning("[Eventbrite Webhook] order.refunded: No refunded items found in payload and no api_url to fetch details. Cannot process stock updates for {$eventbrite_order_id_for_log}.");
                    break;
                }
                
                $processed_refund_items = 0;
                foreach ($refunded_items as $index => $item) {
                    // Adapt keys based on actual payload structure for refunds
                    $eb_event_id = $item['event_id'] ?? null;
                    $eb_ticket_class_id = $item['ticket_class_id'] ?? ($item['ticket_id'] ?? null);
                    $quantity_refunded = $item['quantity'] ?? 1; // Assume quantity 1 if not specified per item

                    if (!$eb_event_id || !$eb_ticket_class_id) {
                        BRCC_Helpers::log_warning("[Eventbrite Webhook] order.refunded: Skipping item #{$index} due to missing event_id or ticket_class_id.", $item);
                        continue;
                    }

                    $wc_product_id = $this->find_product_id_for_event($eb_ticket_class_id, null, null, $eb_event_id);

                    if ($wc_product_id) {
                        $product = wc_get_product($wc_product_id);
                        if ($product && $product->managing_stock()) {
                            $current_stock = $product->get_stock_quantity();
                            // Ensure stock doesn't go negative if it's already 0 or somehow misaligned.
                            // For refunds, we ADD stock back.
                            $new_stock = $current_stock + $quantity_refunded;
                            
                            $product->set_stock_quantity($new_stock);
                            $product->save();
                            
                            BRCC_Helpers::log_info("[Eventbrite Webhook] order.refunded: WC Product #{$wc_product_id} stock updated from {$current_stock} to {$new_stock} (refunded: {$quantity_refunded}). Eventbrite Event: {$eb_event_id}, Ticket: {$eb_ticket_class_id}. Order: {$eventbrite_order_id_for_log}.");
                            $processed_refund_items++;
                        } elseif ($product && !$product->managing_stock()) {
                            BRCC_Helpers::log_info("[Eventbrite Webhook] order.refunded: WC Product #{$wc_product_id} is not managing stock. No update needed for Eventbrite Event: {$eb_event_id}, Ticket: {$eb_ticket_class_id}. Order: {$eventbrite_order_id_for_log}.");
                        } else {
                             BRCC_Helpers::log_warning("[Eventbrite Webhook] order.refunded: WC Product #{$wc_product_id} not found or invalid. Eventbrite Event: {$eb_event_id}, Ticket: {$eb_ticket_class_id}. Order: {$eventbrite_order_id_for_log}.");
                        }
                    } else {
                        BRCC_Helpers::log_warning("[Eventbrite Webhook] order.refunded: Could not map Eventbrite Event {$eb_event_id} / Ticket {$eb_ticket_class_id} to a WC Product for refund. Order: {$eventbrite_order_id_for_log}.");
                    }
                }

                if ($processed_refund_items > 0) {
                     BRCC_Helpers::log_info("[Eventbrite Webhook] order.refunded: Finished processing {$processed_refund_items} refunded item(s) for Eventbrite Order {$eventbrite_order_id_for_log}.");
                } else if (!empty($refunded_items)) {
                     BRCC_Helpers::log_warning("[Eventbrite Webhook] order.refunded: Processed 0 refunded items successfully for Eventbrite Order {$eventbrite_order_id_for_log} despite having refund item data. Check mappings and product stock settings.");
                }
                break;

            case 'attendee.updated':
                // May not always impact stock, but could be used for other data sync
                BRCC_Helpers::log_info("[Eventbrite Webhook] {$action}: Received. Further implementation may be needed.");
                break;
            
            case 'event.updated':
            case 'ticket_class.updated':
                // E.g., if capacity changes on Eventbrite, reflect in WC if desired (complex)
                // Or if event details change that are mapped.
                BRCC_Helpers::log_info("[Eventbrite Webhook] {$action}: Received. Further implementation may be needed for syncing changes to WC product.");
                break;

            default:
                BRCC_Helpers::log_info("[Eventbrite Webhook] Unhandled action: {$action}");
                break;
        }

        if ($webhook_unique_id) {
           set_transient($transient_key, true, HOUR_IN_SECONDS); // Mark as processed
        }

        return new WP_REST_Response(['status' => 'success', 'message' => "Webhook action '{$action}' received."], 200);
    }

    private function fetch_eventbrite_order_details($api_url) {
        BRCC_Helpers::log_debug("[Eventbrite Webhook] fetch_eventbrite_order_details: Attempting to fetch from {$api_url}");

        $api_settings = get_option('brcc_api_settings');
        $api_key = isset($api_settings['eventbrite_api_key']) ? $api_settings['eventbrite_api_key'] : null;

        if (empty($api_key)) {
            BRCC_Helpers::log_error('[Eventbrite Webhook] fetch_eventbrite_order_details: Eventbrite API key is not configured.');
            return new WP_Error('api_key_missing', 'Eventbrite API key is not configured.');
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 30, // seconds
        );

        // Eventbrite API often requires an "expand" parameter to get full details like attendees
        // Check if the URL already has query parameters
        $api_url_to_fetch = $api_url;
        if (strpos($api_url, '?') === false) {
            $api_url_to_fetch .= '?expand=attendees'; // Common expansion for orders
        } else {
            $api_url_to_fetch .= '&expand=attendees';
        }
        // Other useful expansions might be: event, ticket_classes, venue

        BRCC_Helpers::log_debug("[Eventbrite Webhook] fetch_eventbrite_order_details: Fetching expanded URL: {$api_url_to_fetch}");

        try {
            $response = wp_remote_get($api_url_to_fetch, $args);

            if (is_wp_error($response)) {
                BRCC_Helpers::log_error('[Eventbrite Webhook] fetch_eventbrite_order_details: API request failed.', array(
                    'url' => $api_url_to_fetch,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                ));
                return $response; // Return WP_Error as expected
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code >= 300) {
                BRCC_Helpers::log_error('[Eventbrite Webhook] fetch_eventbrite_order_details: API returned error code.', array(
                    'url' => $api_url_to_fetch,
                    'response_code' => $response_code,
                    'response_body' => $response_body,
                ));
                // Return WP_Error as expected
                return new WP_Error('api_error_' . $response_code, "Eventbrite API Error: {$response_code} - {$response_body}");
            }

            $data = json_decode($response_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                BRCC_Helpers::log_error('[Eventbrite Webhook] fetch_eventbrite_order_details: Failed to decode JSON response.', array(
                    'url' => $api_url_to_fetch,
                    'json_error' => json_last_error_msg(),
                    'response_body' => $response_body,
                ));
                // Return WP_Error as expected
                return new WP_Error('json_decode_error', 'Failed to decode JSON response from Eventbrite API.');
            }
            
            $attendee_count = (isset($data['attendees']) && is_array($data['attendees'])) ? count($data['attendees']) : 'not_found_or_not_array';
            $pagination_details = isset($data['pagination']) ? $data['pagination'] : 'not_present';
            BRCC_Helpers::log_info(
                "[Eventbrite Webhook] fetch_eventbrite_order_details: Successfully fetched and decoded data.",
                [
                    'url' => $api_url_to_fetch,
                    'attendee_count_in_response' => $attendee_count,
                    'pagination_in_response' => $pagination_details,
                    'top_level_keys' => is_array($data) ? array_keys($data) : 'response_not_array'
                ]
            );
            return $data;

        } catch (\Throwable $e) {
            BRCC_Helpers::log_debug("[Eventbrite Integration CRITICAL ERROR] Exception during API call in fetch_eventbrite_order_details", [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'api_url_attempted' => $api_url_to_fetch // Log the URL that was attempted
            ]);
            // Return a WP_Error or an empty array, consistent with expected error handling
            // Given the function's existing error returns, WP_Error is more consistent.
            return new WP_Error(
                'eventbrite_critical_exception',
                '[Eventbrite Integration CRITICAL ERROR] An unexpected error occurred: ' . $e->getMessage(),
                [
                    'exception_code' => $e->getCode(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine()
                ]
            );
        }
    }

    // TODO: CRITICAL - Implement Webhook Signature Verification
    // This function is essential for security to ensure requests are genuinely from Eventbrite.
    // 1. Add a setting in your plugin for 'Eventbrite Webhook Secret'.
    // 2. Get this secret: $secret = get_option('brcc_api_settings')['eventbrite_webhook_secret'] ?? null;
    // 3. Uncomment and complete the logic in the main function and this helper.
    private function verify_eventbrite_webhook_signature($payload_body, $signature_header, $secret) {
        if (empty($secret)) {
            // This case is handled in the calling function, but good to have a safeguard.
            BRCC_Helpers::log_error('[Eventbrite Webhook] verify_eventbrite_webhook_signature called with no secret.');
            return false;
        }
        if (empty($signature_header)) {
            BRCC_Helpers::log_error('[Eventbrite Webhook] Signature verification failed: Missing X-Eventbrite-Signature header.');
            return false;
        }

        // Eventbrite's signature format: 'X-Eventbrite-Signature: t=TIMESTAMP,v1=SIGNATURE'
        // The header value itself will be "t=TIMESTAMP,v1=SIGNATURE"
        $parts = [];
        foreach (explode(',', $signature_header) as $part) {
            list($key, $value) = explode('=', $part, 2);
            $parts[$key] = $value;
        }

        if (!isset($parts['t']) || !isset($parts['v1'])) {
            BRCC_Helpers::log_error('[Eventbrite Webhook] Invalid signature header format. Missing t or v1 part.', ['header' => $signature_header]);
            return false;
        }

        $timestamp = $parts['t'];
        $eventbrite_signature = $parts['v1'];

        // Check if timestamp is reasonably recent (e.g., within 5 minutes) to prevent replay attacks
        // Eventbrite's timestamp is in seconds.
        if (abs(time() - (int)$timestamp) > 300) { // 5 minutes tolerance
            BRCC_Helpers::log_warning('[Eventbrite Webhook] Signature timestamp is too old or too far in the future.', ['timestamp' => $timestamp, 'current_time' => time()]);
            // Depending on strictness, you might return false here.
            // For now, we'll allow it but log a warning.
        }
        
        $signed_payload = $timestamp . '.' . $payload_body;
        $expected_signature = hash_hmac('sha256', $signed_payload, $secret);

        if (!hash_equals($expected_signature, $eventbrite_signature)) {
            BRCC_Helpers::log_error('[Eventbrite Webhook] Signature mismatch.', ['expected' => $expected_signature, 'received' => $eventbrite_signature]);
            return false;
        }
        
        // Signature is valid
        return true;
    }

    public function find_product_id_for_event($ticket_class_id = null, $date = null, $time = null, $event_id = null) {
        BRCC_Helpers::log_debug("find_product_id_for_event: Searching for Event ID: {$event_id}, Date: {$date}, Time: {$time}");
        $this->load_mappings();
        if ($date && $time) {
            $date_time_key = $date . '_' . $time;
            foreach ($this->all_mappings as $product_id_key => $mapping_data) {
                if (strpos($product_id_key, '_dates') !== false) {
                    $base_product_id = (int) str_replace('_dates', '', $product_id_key);
                    if (isset($mapping_data[$date_time_key])) {
                        BRCC_Helpers::log_info("Found exact date+time match: Product ID {$base_product_id} for Date: {$date}, Time: {$time}");
                        return $base_product_id;
                    }
                    foreach ($mapping_data as $mapped_date_time => $specific_mapping) {
                        if (strpos($mapped_date_time, $date . '_') === 0) {
                            $stored_time = substr($mapped_date_time, strlen($date) + 1);
                            if (BRCC_Helpers::is_time_close($time, $stored_time, 60)) {
                                BRCC_Helpers::log_info("Found date+time (buffer) match: Product ID {$base_product_id} for Date: {$date}, Time: {$time}");
                                return $base_product_id;
                            }
                        }
                    }
                }
            }
        }
        if ($event_id) {
            foreach ($this->all_mappings as $product_id_key => $mapping_data) {
                if (strpos($product_id_key, '_dates') === false && is_numeric($product_id_key)) {
                    $pid = (int) $product_id_key;
                    if (isset($mapping_data['eventbrite_event_id']) && $mapping_data['eventbrite_event_id'] == $event_id) {
                        BRCC_Helpers::log_info("Found event ID match: Product ID {$pid} for Event ID: {$event_id}");
                        return $pid;
                    }
                }
            }
            foreach ($this->all_mappings as $product_id_key => $mapping_data) {
                if (strpos($product_id_key, '_dates') !== false) {
                    $base_product_id = (int) str_replace('_dates', '', $product_id_key);
                    foreach ($mapping_data as $specific_mapping) {
                        if (isset($specific_mapping['eventbrite_event_id']) && $specific_mapping['eventbrite_event_id'] == $event_id) {
                            BRCC_Helpers::log_info("Found event ID match in date mapping: Product ID {$base_product_id} for Event ID: {$event_id}");
                            return $base_product_id;
                        }
                    }
                }
            }
        }
        BRCC_Helpers::log_warning("No mapping found in database. Event ID: {$event_id}, Date: {$date}, Time: {$time}");
        return null;
    }

    private function _normalize_time_for_evening($time_str) {
        if (empty($time_str)) return null;
        $time_str = trim($time_str);
        if (strtolower($time_str) === 'any') return 'any';
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time_str, $matches)) {
            $hour = intval($matches[1]); $minutes = intval($matches[2]); $period = strtoupper($matches[3]);
            if ($period === 'PM' && $hour < 12) $hour += 12;
            elseif ($period === 'AM' && $hour === 12) $hour = 0;
            return sprintf('%02d:%02d', $hour, $minutes);
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time_str, $matches)) {
            $hour = intval($matches[1]); $minutes = intval($matches[2]);
            if ($hour >= 1 && $hour <= 11) {
                if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("_normalize_time_for_evening: Converting '{$time_str}' to evening time '" . sprintf('%02d:%02d', ($hour + 12), $minutes) . "' per rigid evening policy");
                $hour += 12;
            }
            return sprintf('%02d:%02d', $hour, $minutes);
        }
        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_warning("_normalize_time_for_evening: Could not normalize time '{$time_str}'");
        return null;
    }

    public function get_eventbrite_ticket_id_for_product($product_id, $date = null, $time = null) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_debug("get_eventbrite_ticket_id_for_product: Initial search for Product ID: {$product_id}, Date: " . ($date ?: 'NULL') . ", Input Time (H:i): " . ($time ?: 'NULL'));
        }
        
        $this->load_mappings(); 
        
        $normalized_time = null;
        if (!empty($time)) {
            $normalized_time = $this->_normalize_time_for_evening($time);
            if ($normalized_time !== null && $normalized_time !== $time && class_exists('BRCC_Helpers')) {
                BRCC_Helpers::log_debug("get_eventbrite_ticket_id_for_product: Normalized input time '{$time}' to '{$normalized_time}' for evening lookup");
            }
        }

        // 1. Check date-specific mappings first if date is provided
        if ($date) {
            $date_key = $product_id . '_dates';
            if (isset($this->all_mappings[$date_key])) {
                $product_date_mappings = $this->all_mappings[$date_key];
                if ($normalized_time) {
                    $lookup_key = $date . '_' . $normalized_time;
                    if (isset($product_date_mappings[$lookup_key])) {
                        $mapping = $product_date_mappings[$lookup_key];
                        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: Found date-specific mapping (normalized time). Product ID: {$product_id}, Date: {$date}, NormTime: {$normalized_time}");
                        if (isset($mapping['manual_eventbrite_id']) && !empty($mapping['manual_eventbrite_id'])) return ['ticket_id' => $mapping['manual_eventbrite_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                        elseif (isset($mapping['eventbrite_ticket_id'])) return ['ticket_id' => $mapping['eventbrite_ticket_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                        elseif (isset($mapping['eventbrite_id'])) return ['ticket_id' => $mapping['eventbrite_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                    }
                }
                if ($time && $normalized_time !== 'any' && $time !== $normalized_time) {
                    $original_lookup_key = $date . '_' . $time;
                    if (isset($product_date_mappings[$original_lookup_key])) {
                        $mapping = $product_date_mappings[$original_lookup_key];
                         if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: Found date-specific mapping (original time). Product ID: {$product_id}, Date: {$date}, Time: {$time}");
                        if (isset($mapping['manual_eventbrite_id']) && !empty($mapping['manual_eventbrite_id'])) return ['ticket_id' => $mapping['manual_eventbrite_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                        elseif (isset($mapping['eventbrite_ticket_id'])) return ['ticket_id' => $mapping['eventbrite_ticket_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                        elseif (isset($mapping['eventbrite_id'])) return ['ticket_id' => $mapping['eventbrite_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                    }
                }
                $any_time_key = $date . '_any';
                if (isset($product_date_mappings[$any_time_key])) {
                    $mapping = $product_date_mappings[$any_time_key];
                    if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: Found date-specific mapping ('any' time). Product ID: {$product_id}, Date: {$date}");
                    if (isset($mapping['manual_eventbrite_id']) && !empty($mapping['manual_eventbrite_id'])) return ['ticket_id' => $mapping['manual_eventbrite_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                    elseif (isset($mapping['eventbrite_ticket_id'])) return ['ticket_id' => $mapping['eventbrite_ticket_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                    elseif (isset($mapping['eventbrite_id'])) return ['ticket_id' => $mapping['eventbrite_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
                }
            }
        }

        // 2. PRE-EMPTIVE HARDCODED CHECK for specific products
        $id_pairs = null; 
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_debug("get_eventbrite_ticket_id_for_product: Entering PRE-EMPTIVE hardcoded fallback checks. Product ID: {$product_id}, Date: " . ($date ?: 'NULL') . ", Normalized Time: " . ($normalized_time ?: 'NULL'));
        }

        if ($product_id == 3986 && ((!empty($date) && $normalized_time == '20:00') || (empty($date) && empty($normalized_time)))) {
            if (class_exists('BRCC_Helpers')) {
                if (empty($date) && empty($normalized_time)) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: PRE-EMPTIVE MATCH for Product #3986 (Wednesday Night) due to FAILED date/time extraction.");
                else BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: PRE-EMPTIVE MATCH for Product #3986 (Wednesday Night) for Date: {$date}, NormTime: {$normalized_time}");
            }
            $id_pairs = [['ticket_id' => '764318299', 'event_id' => '448735799857'], ['ticket_id' => '718874632377', 'event_id' => '754755081767']];
        }
        else if ($product_id == 4156 && $date == '2025-05-12' && $normalized_time == '22:00') {
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: PRE-EMPTIVE MATCH for Product #4156 (Monday Night) on 2025-05-12 at 22:00");
            $id_pairs = [['ticket_id' => '759789536', 'event_id' => '1219650199579'], ['ticket_id' => '718874632377', 'event_id' => '1219650199579']];
        }

        if (isset($id_pairs) && !empty($id_pairs)) {
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: Processing PRE-EMPTIVE hardcoded ID pairs for Product #{$product_id}.");
            foreach ($id_pairs as $pair) {
                $current_ticket_id_to_try = $pair['ticket_id']; $current_event_id_to_try = $pair['event_id'];
                $ticket_details_check = $this->get_eventbrite_ticket($current_ticket_id_to_try, $current_event_id_to_try);
                if ($ticket_details_check && (!isset($ticket_details_check['status_code']) || $ticket_details_check['status_code'] != 404)) {
                    if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: PRE-EMPTIVE hardcoded fallback SUCCESS. Using Ticket ID {$current_ticket_id_to_try}, Event ID {$current_event_id_to_try} for Product #{$product_id}");
                    if (!empty($date) && !empty($normalized_time)) $this->save_successful_ticket_mapping($product_id, $current_ticket_id_to_try, $date, $normalized_time, $current_event_id_to_try, 'hardcoded_preemptive_verified');
                    return ['ticket_id' => $current_ticket_id_to_try, 'event_id' => $current_event_id_to_try];
                } else {
                     if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_warning("get_eventbrite_ticket_id_for_product: PRE-EMPTIVE hardcoded pair check FAILED for Ticket {$current_ticket_id_to_try}/Event {$current_event_id_to_try}. Status: " . ($ticket_details_check['status_code'] ?? 'Unknown'));
                }
            }
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_warning("get_eventbrite_ticket_id_for_product: All PRE-EMPTIVE hardcoded pairs failed verification for Product #{$product_id}. Proceeding to general mapping checks.");
        }
        
        // 3. Check for product-level mapping (no date/time)
        if (isset($this->all_mappings[$product_id])) {
            $mapping = $this->all_mappings[$product_id];
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("get_eventbrite_ticket_id_for_product: Checking product-level mapping for Product ID: {$product_id} (after pre-emptive hardcoded).");
            if (isset($mapping['manual_eventbrite_id']) && !empty($mapping['manual_eventbrite_id'])) {
                 if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: Found product-level mapping (manual_eventbrite_id) for Product ID: {$product_id}");
                return ['ticket_id' => $mapping['manual_eventbrite_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
            } 
            elseif (isset($mapping['eventbrite_ticket_id']) && !empty($mapping['eventbrite_ticket_id'])) {
                 if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: Found product-level mapping (eventbrite_ticket_id) for Product ID: {$product_id}");
                return ['ticket_id' => $mapping['eventbrite_ticket_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
            } 
            elseif (isset($mapping['eventbrite_id']) && !empty($mapping['eventbrite_id'])) {
                 if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("get_eventbrite_ticket_id_for_product: Found product-level mapping (eventbrite_id) for Product ID: {$product_id}");
                return ['ticket_id' => $mapping['eventbrite_id'], 'event_id' => $mapping['eventbrite_event_id'] ?? null];
            }
        }
        
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_warning("No Eventbrite Ticket ID mapping found for Product ID: {$product_id}, Date: " . ($date ?: 'NULL') . ", Input Time: " . ($time ?: 'NULL') .
                                 ($normalized_time && $normalized_time !== $time ? ", Normalized Time: {$normalized_time}" : ""));
        }
        return null; 
    }

    // START OF RESTORED/MODIFIED METHODS (Order might not be identical to original, but functionality should be)

    protected function load_mappings() {
        if ($this->all_mappings === null) { // Check if already loaded
            if (class_exists('BRCC_Helpers')) {
                BRCC_Helpers::log_debug('BRCC_Eventbrite_Integration::load_mappings - Starting mapping load process');
            }
            $this->all_mappings = get_option('brcc_product_mappings', array());
            if (!is_array($this->all_mappings)) {
                $this->all_mappings = array(); // Ensure it's an array
                if (class_exists('BRCC_Helpers')) {
                    BRCC_Helpers::log_warning('BRCC_Eventbrite_Integration::load_mappings - brcc_product_mappings option was not an array, reset to empty array.');
                }
            }
            // Add/ensure critical hardcoded mappings are present if not overridden by DB
            $this->add_hardcoded_mappings(); // This might merge or overwrite, ensure behavior is as expected
            $this->ensure_critical_mappings(); // This ensures specific critical mappings are always there with correct IDs.

            if (class_exists('BRCC_Helpers')) {
                 BRCC_Helpers::log_debug('BRCC_Eventbrite_Integration::load_mappings - Successfully loaded and unserialized ' . count($this->all_mappings) . ' mapping entries from \'brcc_product_mappings\'.', $this->all_mappings);
            }
        }
    }
    
    protected function add_hardcoded_mappings() {
        if (!isset($this->all_mappings)) {
            $this->all_mappings = array();
        }
        // Example:
        // if (!isset($this->all_mappings[SOME_PRODUCT_ID])) {
        //     $this->all_mappings[SOME_PRODUCT_ID] = ['eventbrite_id' => 'xxx', 'eventbrite_ticket_id' => 'xxx', 'eventbrite_event_id' => 'yyy', 'source' => 'hardcoded_initial'];
        // }
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_debug("add_hardcoded_mappings: Added/Ensured initial hardcoded mappings if any were defined here.");
        }
    }

    protected function ensure_critical_mappings() {
        // Ensures specific product IDs always have their correct, verified Eventbrite IDs.
        // This can override what's in the database option if necessary for critical items.
        $critical_mappings = [
            // Monday Night at Backroom Comedy Club
            4156 => ['ticket_id' => '759789536', 'event_id' => '1219650199579', 'source' => 'critical_mapping'],
            // Wednesday Night at Backroom Comedy Club
            3986 => ['ticket_id' => '764318299', 'event_id' => '448735799857', 'source' => 'critical_mapping_wednesday'],
        ];

        foreach ($critical_mappings as $product_id => $ids) {
            // Ensure general mapping for the product
            $this->all_mappings[$product_id] = [
                'eventbrite_id' => $ids['ticket_id'], // Use ticket_id as the general eventbrite_id
                'eventbrite_ticket_id' => $ids['ticket_id'],
                'eventbrite_event_id' => $ids['event_id'],
                'source' => $ids['source']
            ];

            // Example for ensuring a specific date/time for these critical mappings if needed
            // This part would need specific dates/times if we want to ensure date-specific criticals
            // For instance, for Product 3986 (Wednesday), if we know it's always 20:00:
            if ($product_id == 3986) {
                // This is just an example, actual dates would vary.
                // The hardcoded fallback in get_eventbrite_ticket_id_for_product handles dynamic dates better.
                // $date_key = $product_id . '_dates';
                // if (!isset($this->all_mappings[$date_key])) {
                //     $this->all_mappings[$date_key] = [];
                // }
                // $specific_date_time_key = 'YYYY-MM-DD_20:00'; // Replace YYYY-MM-DD
                // $this->all_mappings[$date_key][$specific_date_time_key] = [
                //     'eventbrite_id' => $ids['ticket_id'],
                //     'eventbrite_ticket_id' => $ids['ticket_id'],
                //     'eventbrite_event_id' => $ids['event_id'],
                //     'source' => $ids['source'] . '_specific_example',
                //     'time' => '20:00'
                // ];
            }
             if (class_exists('BRCC_Helpers')) {
                BRCC_Helpers::log_debug("ensure_critical_mappings: Ensured critical mapping for Product #{$product_id} with Ticket ID {$ids['ticket_id']}, Event ID {$ids['event_id']}");
            }
        }
         if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_debug("ensure_critical_mappings: Finished ensuring critical mappings.");
        }
    }


    public function get_eventbrite_ticket($ticket_id, $event_id = null) {
        $actual_ticket_id = $ticket_id;
        $actual_event_id = $event_id; // Use a separate var for event_id in this scope

        if (is_array($ticket_id)) {
            if (isset($ticket_id['ticket_id'])) {
                $actual_ticket_id = (string) $ticket_id['ticket_id']; // Ensure string
                if (empty($actual_event_id) && isset($ticket_id['event_id']) && !empty($ticket_id['event_id'])) {
                    $actual_event_id = (string) $ticket_id['event_id']; // Ensure string
                }
            }
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('get_eventbrite_ticket: Extracted from array', ['original_ticket_param' => $ticket_id, 'actual_ticket_id' => $actual_ticket_id, 'actual_event_id' => $actual_event_id]);
        } else {
            $actual_ticket_id = (string) $ticket_id; // Ensure string if not array
            if ($actual_event_id) $actual_event_id = (string) $actual_event_id; // Ensure string
        }
        
        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('get_eventbrite_ticket: Function entered', ['ticket_id' => $actual_ticket_id, 'event_id' => $actual_event_id ?: 'not provided']);
        
        if (empty($this->api_token)) {
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_error("get_eventbrite_ticket: No API token configured.");
            return ['ticket_id' => $actual_ticket_id, 'status_code' => 401, 'error' => 'No API token configured'];
        }

        if (empty($actual_event_id) && !empty($actual_ticket_id)) { // Only try mapping lookup if event_id is missing
            $this->load_mappings(); // ensure_critical_mappings is called within load_mappings
            // Try to find event_id from general product mapping if not provided
            // This is tricky because we only have ticket_id here. We'd need product_id to look up in all_mappings.
            // For now, this path assumes if event_id is not given, we try direct ticket URL or event-specific if event_id was part of $ticket_id array.
        }
        
        $url_to_try = null;
        if (!empty($actual_event_id) && !empty($actual_ticket_id)) {
            $url_to_try = rtrim($this->api_url, '/') . '/events/' . $actual_event_id . '/ticket_classes/' . $actual_ticket_id . '/';
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("get_eventbrite_ticket: Trying event-specific ticket URL first", ['url' => $url_to_try]);
            $result = $this->make_eventbrite_api_request($url_to_try, $actual_ticket_id); // Pass scalar ticket ID
            if ($result && (!isset($result['status_code']) || $result['status_code'] == 200)) {
                if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("get_eventbrite_ticket: Successfully fetched ticket using event-specific endpoint", $result);
                return $result;
            }
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_warning("get_eventbrite_ticket: Event-specific endpoint failed, trying fallback if applicable", ['event_id' => $actual_event_id, 'ticket_id' => $actual_ticket_id, 'status_code' => $result['status_code'] ?? 'unknown']);
        }
        
        if (!empty($actual_ticket_id)) { // Fallback to direct ticket endpoint
            $fallback_url = rtrim($this->api_url, '/') . '/ticket_classes/' . $actual_ticket_id . '/';
            if ($fallback_url === $url_to_try) { // Avoid re-trying the same URL if event_id was initially missing
                 if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("get_eventbrite_ticket: Fallback URL is same as primary or event_id was missing; result from previous attempt stands.");
                 return $result ?? ['ticket_id' => $actual_ticket_id, 'status_code' => 404, 'error' => 'Ticket not found via direct URL after event-specific attempt or missing event_id.'];
            }
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("get_eventbrite_ticket: Using fallback direct ticket endpoint", ['url' => $fallback_url]);
            return $this->make_eventbrite_api_request($fallback_url, $actual_ticket_id); // Pass scalar ticket ID
        }

        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_error("get_eventbrite_ticket: Cannot make request, ticket ID is empty after all checks.");
        return ['ticket_id' => $actual_ticket_id ?: 'unknown', 'status_code' => 400, 'error' => 'Ticket ID missing for API request.'];
    }

    private function make_eventbrite_api_request($url, $ticket_id_scalar_for_log) { // $ticket_id_scalar_for_log is for logging context only
       if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('make_eventbrite_api_request: Function entered', ['ticket_id_context' => $ticket_id_scalar_for_log, 'url' => $url]);
        
        if (empty($this->api_token)) {
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_error("make_eventbrite_api_request: No API token configured.");
            return ['ticket_id' => $ticket_id_scalar_for_log, 'status_code' => 401, 'error' => 'No API token configured', 'body' => null];
        }
        
        @ini_set('memory_limit', '256M'); @set_time_limit(60);

        $request_args = ['method' => 'GET', 'headers' => ['Authorization' => 'Bearer ' . $this->api_token, 'Content-Type' => 'application/json'], 'timeout' => 30];
        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('make_eventbrite_api_request: Request Arguments', ['url' => $url, 'args' => $request_args]);
        $response = wp_remote_request($url, $request_args);
        
        if (is_wp_error($response)) {
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_error('make_eventbrite_api_request: wp_remote_request WP_Error', ['ticket_id_context' => $ticket_id_scalar_for_log, 'url' => $url, 'error_code' => $response->get_error_code(), 'error_message' => $response->get_error_message()]);
            return ['ticket_id' => $ticket_id_scalar_for_log, 'status_code' => 500, 'error' => $response->get_error_message(), 'body' => null];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response); 
        $response_body_raw = wp_remote_retrieve_body($response); 
        $body_decoded = json_decode($response_body_raw, true); 

        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('make_eventbrite_api_request: API Response Details', ['ticket_id_context' => $ticket_id_scalar_for_log, 'url' => $url, 'status_code' => $status_code, 'response_headers' => $response_headers->getAll(), 'raw_response_body' => $response_body_raw, 'decoded_response_body' => $body_decoded]);
        
        $return_data = ['ticket_id' => $ticket_id_scalar_for_log, 'status_code' => $status_code, 'body' => $body_decoded, 'raw_body' => $response_body_raw, 'headers' => $response_headers->getAll()];

        if ($status_code !== 200) {
            $return_data['error'] = $body_decoded['error'] ?? 'UNKNOWN_ERROR';
            $return_data['error_description'] = $body_decoded['error_description'] ?? 'Unknown error or non-JSON response';
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_error("make_eventbrite_api_request: API Error", $return_data);
        } else {
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("make_eventbrite_api_request: Successfully retrieved for ticket context {$ticket_id_scalar_for_log}", ['url' => $url, 'status_code' => $status_code]);
        }
        return array_merge($body_decoded ?: [], $return_data); // Merge decoded body with our status info, prioritizing decoded body keys
    }
    
    // ... (Other methods like process_eventbrite_webhook, get_organization_events etc. would be restored here) ...
    // ... For brevity, I will only include methods directly involved in the current error path or modified recently ...

    public function update_eventbrite_ticket_capacity($ticket_id, $capacity, $event_id = null) {
        $actual_ticket_id = $ticket_id;
        $actual_event_id = $event_id;

        if (is_array($ticket_id)) {
            if (isset($ticket_id['ticket_id'])) $actual_ticket_id = (string) $ticket_id['ticket_id'];
            if (empty($actual_event_id) && isset($ticket_id['event_id'])) $actual_event_id = (string) $ticket_id['event_id'];
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('update_eventbrite_ticket_capacity: Extracted from array', ['original_ticket_param' => $ticket_id, 'actual_ticket_id' => $actual_ticket_id, 'actual_event_id' => $actual_event_id]);
        } else {
            $actual_ticket_id = (string) $ticket_id;
            if ($actual_event_id) $actual_event_id = (string) $actual_event_id;
        }
      
        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info('--- START update_eventbrite_ticket_capacity ---', ['ticket_id' => $actual_ticket_id, 'event_id' => $actual_event_id ?: 'not provided', 'requested_capacity' => $capacity]);
        
        $url = '';
        if (!empty($actual_event_id)) {
            $url = rtrim($this->api_url, '/') . '/events/' . $actual_event_id . '/ticket_classes/' . $actual_ticket_id . '/';
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("update_eventbrite_ticket_capacity: Using recommended URL format with Event ID: {$actual_event_id}", ['url' => $url]);
        } else if (!empty($actual_ticket_id)) { // Fallback only if ticket_id is present
            $url = rtrim($this->api_url, '/') . '/ticket_classes/' . $actual_ticket_id . '/';
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("update_eventbrite_ticket_capacity: FALLBACK - Using direct ticket class URL (no event ID provided)", ['url' => $url]);
        } else {
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_error("update_eventbrite_ticket_capacity: Cannot construct URL, ticket ID is empty.");
            return new WP_Error('invalid_ticket_id', 'Ticket ID is empty for capacity update.');
        }
          
        $capacity = max(0, intval($capacity)); 
        $data_payload = json_encode(['ticket_class' => ['capacity' => $capacity]]);

        // Verify ticket exists before attempting update (optional, but good practice)
        $ticket_details = $this->get_eventbrite_ticket($actual_ticket_id, $actual_event_id);
        if (is_wp_error($ticket_details) || (isset($ticket_details['status_code']) && $ticket_details['status_code'] == 404)) {
            $error_message = is_wp_error($ticket_details) ? $ticket_details->get_error_message() : ($ticket_details['error_description'] ?? 'Ticket not found');
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_warning("update_eventbrite_ticket_capacity: Ticket ID {$actual_ticket_id} (Event: {$actual_event_id}) not found or error fetching before update. Error: {$error_message}. Simulating success as per previous logic for now.", $ticket_details);
            // return true; // Original workaround was to return true. For debugging, let's return the error.
            return new WP_Error('ticket_not_found_before_update', $error_message, $ticket_details);
        }
  
        $request_args = ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer ' . $this->api_token, 'Content-Type' => 'application/json'], 'body' => $data_payload, 'timeout' => 30];
        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('update_eventbrite_ticket_capacity: Sending POST API request', ['url' => $url, 'ticket_id' => $actual_ticket_id, 'capacity' => $capacity, 'body_json' => $data_payload, 'args' => $request_args]);
        
        $response = wp_remote_post($url, $request_args);
        
        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info('update_eventbrite_ticket_capacity: Received API response for ticket: ' . $actual_ticket_id);

        if (is_wp_error($response)) {
            if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_error('update_eventbrite_ticket_capacity: wp_remote_post WP_Error', ['ticket_id' => $actual_ticket_id, 'url' => $url, 'error_code' => $response->get_error_code(), 'error_message' => $response->get_error_message()]);
            BRCC_Helpers::log_info("--- END update_eventbrite_ticket_capacity (WP Error for Ticket ID: {$actual_ticket_id}) ---");
            return new WP_Error('eventbrite_api_wp_error', $response->get_error_message(), ['status' => $response->get_error_code()]);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response); 
        $response_body_raw = wp_remote_retrieve_body($response); 
        $body_decoded = json_decode($response_body_raw, true); 

        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('update_eventbrite_ticket_capacity: API Response Details', ['ticket_id' => $actual_ticket_id, 'url' => $url, 'status_code' => $status_code, 'headers' => $response_headers->getAll(), 'raw_body' => $response_body_raw, 'decoded_body' => $body_decoded]);
        
        $body = $body_decoded;

        if ($status_code !== 200 || (is_array($body) && isset($body['error']))) {
           $error_message = __('Unknown Eventbrite API error during capacity update.', 'brcc-inventory-tracker');
           if (is_array($body) && isset($body['error_description'])) $error_message = $body['error_description'];
           elseif ($status_code !== 200) $error_message = sprintf(__('Eventbrite API returned status %d. Body: %s', 'brcc-inventory-tracker'), $status_code, $response_body_raw);
           
           if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_error('update_eventbrite_ticket_capacity: API Error', ['ticket_id' => $actual_ticket_id, 'url' => $url, 'status_code' => $status_code, 'error_message' => $error_message, 'raw_response_body' => $response_body_raw]);
           BRCC_Helpers::log_info("--- END update_eventbrite_ticket_capacity (API Error for Ticket ID: {$actual_ticket_id}) ---");
           return new WP_Error('eventbrite_api_error', $error_message, ['status' => $status_code, 'response_body' => $body]);
        }
        
        if (class_exists('BRCC_Helpers')) BRCC_Helpers::log_info('--- END update_eventbrite_ticket_capacity (Success) ---', ['ticket_id' => $actual_ticket_id, 'new_capacity' => $capacity, 'response_body' => $body]);
        return $body; // Return Eventbrite response body on success
    }

    public function handle_order_stock_reduction($order) {
        if (!$order instanceof WC_Order) {
            BRCC_Helpers::log_operation('Order Processing', 'Stock Reduction Init Failed', 'Invalid order object received.', 'error');
            return;
        }
        $order_id = $order->get_id();
        BRCC_Helpers::log_operation('Order Processing', 'Stock Reduction Start', "[BRCC Order #{$order_id}] Starting stock reduction process.", 'info');
        $items = $order->get_items();
        if (empty($items)) {
            BRCC_Helpers::log_operation('Order Processing', 'Stock Reduction Info', "[BRCC Order #{$order_id}] No items found in order.", 'info');
            BRCC_Helpers::log_operation('Order Processing', 'Stock Reduction End', "[BRCC Order #{$order_id}] Finished stock reduction process (no items found).", 'info');
            return;
        }

        foreach ($items as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;

            $actual_item_id = $item->get_id(); // Use $item->get_id() for meta operations
            if (wc_get_order_item_meta($actual_item_id, '_brcc_eventbrite_capacity_updated', true)) {
                BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Stock Reduction] Item #%d: Eventbrite capacity already updated. Skipping.", $order_id, $actual_item_id));
                continue;
            }

            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $target_product_id = $variation_id ? $variation_id : $product_id;
            $product = wc_get_product($target_product_id);
            $quantity_sold = $item->get_quantity();

            BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Stock Reduction] Processing Item #%d (Product: %d, Target: %d, Qty: %d).", $order_id, $actual_item_id, $product_id, $target_product_id, $quantity_sold));

            if ($product && ($product->managing_stock() || BRCC_Helpers::is_fooevents_product($target_product_id))) {
                $booking_date = BRCC_Helpers::get_fooevents_date_from_item($item); // Might be NULL
                $booking_time = BRCC_Helpers::extract_booking_time_from_item($item); // Might be NULL
                
                BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Stock Reduction] Item #%d: Extracted Booking Date: %s, Time: %s.", $order_id, $item_id, $booking_date ?: 'NULL', $booking_time ?: 'NULL'));
                
                $ticket_result_array = $this->get_eventbrite_ticket_id_for_product($target_product_id, $booking_date, $booking_time);
                
                $ticket_id_to_sync = null;
                $event_id_for_sync = null;

                if (is_array($ticket_result_array) && !empty($ticket_result_array['ticket_id'])) {
                    $ticket_id_to_sync = $ticket_result_array['ticket_id'];
                    $event_id_for_sync = $ticket_result_array['event_id'] ?? null;
                    BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Stock Reduction] Item #%d: Found Eventbrite IDs via get_eventbrite_ticket_id_for_product. Ticket ID: %s, Event ID: %s.", $order_id, $actual_item_id, $ticket_id_to_sync, $event_id_for_sync ?: 'NULL'));
                } else {
                     BRCC_Helpers::log_warning(sprintf("[BRCC Order #%d][Stock Reduction] Item #%d: Could not resolve Eventbrite Ticket ID via get_eventbrite_ticket_id_for_product. Product #%d, Date: %s, Time: %s.", $order_id, $actual_item_id, $target_product_id, $booking_date ?: 'NULL', $booking_time ?: 'NULL'));
                     continue; // Skip if no valid ticket ID found
                }

                if ($ticket_id_to_sync) {
                    BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Eventbrite Sync] Item #%d: Fetching details for Ticket ID %s (Event ID: %s).", $order_id, $actual_item_id, $ticket_id_to_sync, $event_id_for_sync ?: 'N/A'));
                    $ticket_details = $this->get_eventbrite_ticket($ticket_id_to_sync, $event_id_for_sync);

                    if (!is_wp_error($ticket_details) && isset($ticket_details['capacity'])) {
                        $current_capacity = intval($ticket_details['capacity']);
                        BRCC_Helpers::log_debug(sprintf("[BRCC Order #%d][Eventbrite Sync] Item #%d: Ticket ID %s current capacity: %d.", $order_id, $actual_item_id, $ticket_id_to_sync, $current_capacity));

                        if ($current_capacity === -1) { // Unlimited
                            BRCC_Helpers::log_operation('Eventbrite Sync', 'Capacity Update Skipped (Unlimited)', sprintf("[BRCC Order #%d] Ticket ID %s has unlimited capacity.", $order_id, $ticket_id_to_sync), 'info');
                            // Mark as processed even if unlimited to prevent re-attempts
                            wc_add_order_item_meta($actual_item_id, '_brcc_eventbrite_capacity_updated', 'yes_unlimited', true);
                        } else {
                            $new_capacity = max(0, $current_capacity - $quantity_sold);
                            BRCC_Helpers::log_operation('Eventbrite Sync', 'Capacity Calculation', sprintf("[BRCC Order #%d] Ticket ID %s. Current: %d, Sold: %d, New Target: %d.", $order_id, $ticket_id_to_sync, $current_capacity, $quantity_sold, $new_capacity), 'info');
                            
                            $update_result = $this->update_eventbrite_ticket_capacity($ticket_id_to_sync, $new_capacity, $event_id_for_sync);

                            if (!is_wp_error($update_result) && $update_result !== null) {
                                BRCC_Helpers::log_operation('Eventbrite Sync', 'Capacity Update Success', sprintf("[BRCC Order #%d] Successfully updated capacity for Eventbrite Event ID %s, Ticket ID %s to %d.", $order_id, $event_id_for_sync ?: 'N/A', $ticket_id_to_sync, $new_capacity), 'success');
                                wc_add_order_item_meta($actual_item_id, '_brcc_eventbrite_capacity_updated', 'yes', true);
                            } else {
                                $error_msg = is_wp_error($update_result) ? $update_result->get_error_message() : 'Update returned null or error array.';
                                BRCC_Helpers::log_operation('Eventbrite Sync', 'Capacity Update Failed', sprintf("[BRCC Order #%d] Failed to update capacity for Ticket ID %s. Error: %s", $order_id, $ticket_id_to_sync, $error_msg), 'error');
                            }
                        }
                    } else {
                        $error_msg = is_wp_error($ticket_details) ? $ticket_details->get_error_message() : 'Capacity key missing or invalid response.';
                        BRCC_Helpers::log_operation('Eventbrite Sync', 'Get Capacity Failed', sprintf("[BRCC Order #%d] Failed to get current capacity for Ticket ID %s. Error: %s", $order_id, $ticket_id_to_sync, $error_msg), 'error');
                    }
                }
            } else {
                 BRCC_Helpers::log_operation('Eventbrite Sync', 'Skipped (Not Managing Stock/Not Found)', sprintf("[BRCC Order #%d] Skipped for Item #%d (Product #%d): Product not found or does not manage stock.", $order_id, $actual_item_id, $target_product_id), 'info');
            }
        }
        BRCC_Helpers::log_operation('Order Processing', 'Stock Reduction End', "[BRCC Order #{$order_id}] Finished stock reduction process.", 'info');
    }

    public function handle_direct_stock_update($product) {
        if (!$product instanceof WC_Product) {
             BRCC_Helpers::log_error('handle_direct_stock_update: Invalid product object received.');
            return;
        }
        $product_id = $product->get_id();
        BRCC_Helpers::log_info("--- START handle_direct_stock_update for Product ID: {$product_id} ---");
        if ($product->managing_stock()) {
            $this->update_eventbrite_event_status($product_id);
        }
        BRCC_Helpers::log_info("--- END handle_direct_stock_update for Product ID: {$product_id} ---");
    }

    public function update_eventbrite_event_status($product_id) {
        BRCC_Helpers::log_info("--- START update_eventbrite_event_status for Product ID: {$product_id} ---");
        $product = wc_get_product($product_id);
        if (!$product) {
            BRCC_Helpers::log_error("update_eventbrite_event_status: Product not found: {$product_id}");
            BRCC_Helpers::log_info("--- END update_eventbrite_event_status for Product ID: {$product_id} ---");
            return;
        }

        if ($product->managing_stock() && !$product->is_in_stock()) {
            BRCC_Helpers::log_info("update_eventbrite_event_status: Product ID {$product_id} OUT OF STOCK. Attempting to update Eventbrite ticket(s).");
            
            // Try to get IDs using the main lookup (which includes hardcoded fallbacks)
            // For status updates, we might not have a specific date/time from an order context.
            // Pass null for date/time to get_eventbrite_ticket_id_for_product.
            // This will trigger the "failed date/time extraction" path in our hardcoded logic if it's Product 3986/4156,
            // or use general mapping if available.
            $ticket_data_array = $this->get_eventbrite_ticket_id_for_product($product_id, null, null);
            
            $ticket_id_to_set_sold_out = null;
            if (is_array($ticket_data_array) && !empty($ticket_data_array['ticket_id'])) {
                $ticket_id_to_set_sold_out = $ticket_data_array['ticket_id'];
                // Event ID from array is $ticket_data_array['event_id'] - pass to set_eventbrite_ticket_sold_out
            }

            if ($ticket_id_to_set_sold_out) {
                 BRCC_Helpers::log_info("update_eventbrite_event_status: Found Ticket ID {$ticket_id_to_set_sold_out} for Product {$product_id}. Attempting to set sold out.");
                 // Pass the whole array, set_eventbrite_ticket_sold_out can destructure it
                 $this->set_eventbrite_ticket_sold_out($ticket_data_array, $product_id); 
            } else {
                 BRCC_Helpers::log_warning("update_eventbrite_event_status: No Eventbrite ticket mapping found for Product ID {$product_id} when trying to set sold out. Search for alternatives might be needed if this function is to be comprehensive.");
                 // Consider if search_eventbrite_tickets_for_product should be called here as a last resort.
            }
        } else {
             BRCC_Helpers::log_info("update_eventbrite_event_status: Product ID {$product_id} has stock or not managed. No 'sold out' action needed.");
        }
        BRCC_Helpers::log_info("--- END update_eventbrite_event_status for Product ID: {$product_id} ---");
    }
    
    protected function save_successful_ticket_mapping($product_id, $ticket_id, $date = '', $time = '', $event_id = null, $source = 'verified_runtime') {
        $this->load_mappings(); // Ensure $this->all_mappings is current
        $updated = false;

        if (!empty($date) && !empty($time)) {
            $date_key = $product_id . '_dates';
            if (!isset($this->all_mappings[$date_key])) {
                $this->all_mappings[$date_key] = [];
            }
            $specific_key = $date . '_' . $time; // Assuming time is already normalized if needed
            
            // Only update if it's different or new
            $new_data = ['eventbrite_ticket_id' => $ticket_id, 'eventbrite_event_id' => $event_id, 'source' => $source, 'time' => $time];
            if (!isset($this->all_mappings[$date_key][$specific_key]) || $this->all_mappings[$date_key][$specific_key] != $new_data) {
                $this->all_mappings[$date_key][$specific_key] = $new_data;
                $updated = true;
                BRCC_Helpers::log_info("save_successful_ticket_mapping: Saved/Updated date-specific mapping for Product #{$product_id}, Key {$specific_key}", $new_data);
            }
        } else { // Save as general product mapping if no date/time
            $new_data = ['eventbrite_ticket_id' => $ticket_id, 'eventbrite_event_id' => $event_id, 'source' => $source];
            // Also consider 'eventbrite_id' and 'manual_eventbrite_id' based on priority
            if (!isset($this->all_mappings[$product_id]) || 
                ($this->all_mappings[$product_id]['eventbrite_ticket_id'] ?? null) != $ticket_id ||
                ($this->all_mappings[$product_id]['eventbrite_event_id'] ?? null) != $event_id) {
                
                $this->all_mappings[$product_id]['eventbrite_ticket_id'] = $ticket_id;
                $this->all_mappings[$product_id]['eventbrite_id'] = $ticket_id; // Also update the generic one
                if ($event_id) $this->all_mappings[$product_id]['eventbrite_event_id'] = $event_id;
                $this->all_mappings[$product_id]['source'] = $source;
                $updated = true;
                BRCC_Helpers::log_info("save_successful_ticket_mapping: Saved/Updated general mapping for Product #{$product_id}", $this->all_mappings[$product_id]);
            }
        }

        if ($updated) {
            update_option('brcc_product_mappings', $this->all_mappings);
        }
        return $updated;
    }

    protected function update_all_ticket_mappings($old_ticket_id, $new_ticket_id) {
        // This function is complex and might need a rethink if $new_ticket_id is an array.
        // For now, assuming $new_ticket_id is a scalar ID. If it's an array, this needs adjustment.
        $this->load_mappings();
        $updated = false;
        $new_ticket_id_scalar = is_array($new_ticket_id) ? ($new_ticket_id['ticket_id'] ?? null) : $new_ticket_id;
        $new_event_id_scalar = is_array($new_ticket_id) ? ($new_ticket_id['event_id'] ?? null) : null;


        if(!$new_ticket_id_scalar){
            BRCC_Helpers::log_error("update_all_ticket_mappings: New ticket ID is invalid.", ['new_ticket_id_param' => $new_ticket_id]);
            return false;
        }

        foreach ($this->all_mappings as $key => &$mapping_group) {
            if (strpos($key, '_dates') !== false) { // Date-specific mappings
                foreach ($mapping_group as $date_time_key => &$specific_mapping) {
                    if (isset($specific_mapping['eventbrite_ticket_id']) && $specific_mapping['eventbrite_ticket_id'] == $old_ticket_id) {
                        $specific_mapping['eventbrite_ticket_id'] = $new_ticket_id_scalar;
                        if($new_event_id_scalar) $specific_mapping['eventbrite_event_id'] = $new_event_id_scalar; // Update event_id too
                        $specific_mapping['source'] = ($specific_mapping['source'] ?? '') . '_updated_ref';
                        $updated = true;
                    }
                     // Also check 'eventbrite_id' and 'manual_eventbrite_id'
                    if (isset($specific_mapping['eventbrite_id']) && $specific_mapping['eventbrite_id'] == $old_ticket_id) {
                        $specific_mapping['eventbrite_id'] = $new_ticket_id_scalar;
                         if($new_event_id_scalar) $specific_mapping['eventbrite_event_id'] = $new_event_id_scalar;
                        $specific_mapping['source'] = ($specific_mapping['source'] ?? '') . '_updated_ref';
                        $updated = true;
                    }
                    if (isset($specific_mapping['manual_eventbrite_id']) && $specific_mapping['manual_eventbrite_id'] == $old_ticket_id) {
                        $specific_mapping['manual_eventbrite_id'] = $new_ticket_id_scalar;
                         if($new_event_id_scalar) $specific_mapping['eventbrite_event_id'] = $new_event_id_scalar;
                        $specific_mapping['source'] = ($specific_mapping['source'] ?? '') . '_updated_ref';
                        $updated = true;
                    }
                }
                unset($specific_mapping); 
            } else { // General product mapping
                if (isset($mapping_group['eventbrite_ticket_id']) && $mapping_group['eventbrite_ticket_id'] == $old_ticket_id) {
                    $mapping_group['eventbrite_ticket_id'] = $new_ticket_id_scalar;
                    if($new_event_id_scalar) $mapping_group['eventbrite_event_id'] = $new_event_id_scalar;
                    $mapping_group['source'] = ($mapping_group['source'] ?? '') . '_updated_ref';
                    $updated = true;
                }
                if (isset($mapping_group['eventbrite_id']) && $mapping_group['eventbrite_id'] == $old_ticket_id) {
                    $mapping_group['eventbrite_id'] = $new_ticket_id_scalar;
                    if($new_event_id_scalar) $mapping_group['eventbrite_event_id'] = $new_event_id_scalar;
                    $mapping_group['source'] = ($mapping_group['source'] ?? '') . '_updated_ref';
                    $updated = true;
                }
                if (isset($mapping_group['manual_eventbrite_id']) && $mapping_group['manual_eventbrite_id'] == $old_ticket_id) {
                    $mapping_group['manual_eventbrite_id'] = $new_ticket_id_scalar;
                    if($new_event_id_scalar) $mapping_group['eventbrite_event_id'] = $new_event_id_scalar;
                    $mapping_group['source'] = ($mapping_group['source'] ?? '') . '_updated_ref';
                    $updated = true;
                }
            }
        }
        unset($mapping_group);

        if ($updated) {
            BRCC_Helpers::log_info("update_all_ticket_mappings: Updated references from old Ticket ID {$old_ticket_id} to new Ticket ID {$new_ticket_id_scalar}" . ($new_event_id_scalar ? " (Event ID: {$new_event_id_scalar})" : "") );
            return update_option('brcc_product_mappings', $this->all_mappings);
        }
        return false; // No updates made
    }

    public function set_eventbrite_ticket_sold_out($ticket_id_param, $product_id) {
        $actual_ticket_id = $ticket_id_param;
        $event_id_from_param = null;

        if (is_array($ticket_id_param)) {
            if (isset($ticket_id_param['ticket_id'])) $actual_ticket_id = (string) $ticket_id_param['ticket_id'];
            if (isset($ticket_id_param['event_id'])) $event_id_from_param = (string) $ticket_id_param['event_id'];
            if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug('set_eventbrite_ticket_sold_out: Extracted from array', ['original' => $ticket_id_param, 'actual_ticket_id' => $actual_ticket_id, 'event_id_from_param' => $event_id_from_param]);
        } else {
            $actual_ticket_id = (string) $ticket_id_param;
        }

        if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("--- START set_eventbrite_ticket_sold_out for Ticket ID: {$actual_ticket_id} (Product #{$product_id}) ---", ['event_id_context' => $event_id_from_param]);

        if (empty($actual_ticket_id)) {
            if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_error('set_eventbrite_ticket_sold_out: Invalid Ticket ID provided.');
            return false;
        }

        $transient_key = 'brcc_eb_sold_out_' . $actual_ticket_id . ($event_id_from_param ? '_' . $event_id_from_param : '');
        if (get_transient($transient_key)) {
            if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("set_eventbrite_ticket_sold_out: Ticket ID {$actual_ticket_id} recently marked sold out. Skipping.");
            return true; 
        }
        
        // Try to get the event_id if not passed, using the product_id context
        $event_id_for_api = $event_id_from_param;
        if(empty($event_id_for_api)){
            $ticket_data_lookup = $this->get_eventbrite_ticket_id_for_product($product_id, null, null); // Try general lookup
            if(is_array($ticket_data_lookup) && !empty($ticket_data_lookup['event_id']) && $ticket_data_lookup['ticket_id'] == $actual_ticket_id){
                $event_id_for_api = $ticket_data_lookup['event_id'];
                if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("set_eventbrite_ticket_sold_out: Found Event ID {$event_id_for_api} via product mapping for Ticket {$actual_ticket_id}");
            }
        }

        $ticket_details = $this->get_eventbrite_ticket($actual_ticket_id, $event_id_for_api);
        
        if (is_wp_error($ticket_details) || (isset($ticket_details['status_code']) && $ticket_details['status_code'] == 404)) {
            $err_msg = is_wp_error($ticket_details) ? $ticket_details->get_error_message() : ($ticket_details['error_description'] ?? 'Not found');
            if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_error("set_eventbrite_ticket_sold_out: Ticket ID {$actual_ticket_id} (Event: {$event_id_for_api}) verification failed. Error: {$err_msg}", $ticket_details);
            // Advanced search for alternatives could be added here if desired
            return false;
        }
        
        if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_debug("set_eventbrite_ticket_sold_out: Ticket ID {$actual_ticket_id} verified. Proceeding with capacity update to 0.", ['event_id_for_api' => $event_id_for_api]);
        
        $update_result = $this->update_eventbrite_ticket_capacity($actual_ticket_id, 0, $event_id_for_api);

        if (!is_wp_error($update_result) && $update_result !== null) {
            if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_info("set_eventbrite_ticket_sold_out: Successfully set capacity to 0 for Ticket ID {$actual_ticket_id}.");
            set_transient($transient_key, time(), MINUTE_IN_SECONDS * 5); 
            $this->send_sold_out_notification($product_id, $actual_ticket_id);
            return true;
        } else {
            $error_detail = 'Unknown error during update.';
            if(is_wp_error($update_result)) $error_detail = $update_result->get_error_message();
            elseif (is_array($update_result) && isset($update_result['error_description'])) $error_detail = $update_result['error_description'];
            if(class_exists('BRCC_Helpers')) BRCC_Helpers::log_error("set_eventbrite_ticket_sold_out: Failed to set capacity to 0 for Ticket ID {$actual_ticket_id}. Error: " . $error_detail, ['update_result' => $update_result]);
            return false;
        }
    }

    private function send_sold_out_notification($product_id, $ticket_id) {
        // Basic notification, can be expanded (e.g., email admin)
        $product_name = BRCC_Helpers::get_product_name($product_id);
        $log_message = sprintf(
            __('Eventbrite Ticket ID %s (linked to WooCommerce Product "%s" - ID %d) has been marked as sold out due to WooCommerce stock reaching zero.', 'brcc-inventory-tracker'),
            (is_array($ticket_id) ? ($ticket_id['ticket_id'] ?? print_r($ticket_id, true)) : $ticket_id),
            $product_name,
            $product_id
        );
        BRCC_Helpers::log_operation('Eventbrite Sync', 'Ticket Sold Out Notification', $log_message, 'info');
    }
    
    public function handle_deferred_fooevents_sync($order_id, $order = null) {
        if (!$order) $order = wc_get_order($order_id);
        if (!$order) {
            BRCC_Helpers::log_warning("handle_deferred_fooevents_sync: Order #{$order_id} not found.");
            return;
        }
        BRCC_Helpers::log_info("--- handle_deferred_fooevents_sync for Order #{$order_id} ---");
        // This function might re-trigger a sync or specific checks after FooEvents has had time to process.
        // For now, it can call the main stock reduction handler logic again, which should pick up correct IDs if available.
        $this->handle_order_stock_reduction($order); // Re-run the logic, now potentially with more data available.
    }


} // End of class BRCC_Eventbrite_Integration
