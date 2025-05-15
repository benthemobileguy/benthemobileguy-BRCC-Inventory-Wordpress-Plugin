<?php
/**
 * BRCC Admin AJAX Class
 * 
 * Handles all AJAX callbacks for the admin interface.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BRCC_Admin_AJAX {

    /**
     * Initialize the class and set up hooks
     */
    public function __construct() {
        // Register all AJAX actions
        add_action('wp_ajax_brcc_regenerate_api_key', array($this, 'regenerate_api_key'));
        add_action('wp_ajax_brcc_sync_inventory_now', array($this, 'sync_inventory_now'));
        add_action('wp_ajax_brcc_save_product_mappings', array($this, 'save_product_mappings'));
        add_action('wp_ajax_brcc_test_product_mapping', array($this, 'test_product_mapping'));
        add_action('wp_ajax_brcc_test_square_connection', array($this, 'test_square_connection'));
        add_action('wp_ajax_brcc_get_square_catalog', array($this, 'get_square_catalog'));
        add_action('wp_ajax_brcc_test_square_mapping', array($this, 'test_square_mapping'));
        add_action('wp_ajax_brcc_import_batch', array($this, 'import_batch'));
        add_action('wp_ajax_brcc_suggest_eventbrite_ticket_class_id', array($this, 'suggest_eventbrite_ticket_class_id'));
        add_action('wp_ajax_brcc_get_all_eventbrite_events_for_attendees', array($this, 'get_all_eventbrite_events_for_attendees'));
        add_action('wp_ajax_brcc_test_eventbrite_connection', array($this, 'test_eventbrite_connection'));
        add_action('wp_ajax_brcc_get_product_dates', array($this, 'get_product_dates'));
        add_action('wp_ajax_brcc_save_product_date_mappings', array($this, 'save_product_date_mappings'));
        add_action('wp_ajax_brcc_test_product_date_mapping', array($this, 'test_product_date_mapping'));
        add_action('wp_ajax_brcc_get_products_for_date', array($this, 'get_products_for_date'));
        add_action('wp_ajax_brcc_fetch_product_attendees_for_date', array($this, 'fetch_product_attendees_for_date'));
        add_action('wp_ajax_brcc_reset_todays_sales', array($this, 'reset_todays_sales'));
        add_action('wp_ajax_brcc_get_eventbrite_tickets_for_event', array($this, 'get_eventbrite_tickets_for_event'));
        add_action('wp_ajax_brcc_clear_eventbrite_cache', array($this, 'clear_eventbrite_cache'));
        add_action('wp_ajax_brcc_import_historical_data', array($this, 'import_historical_data'));
        add_action('wp_ajax_brcc_sync_product_inventory', array($this, 'sync_product_inventory'));
        add_action('wp_ajax_brcc_refresh_dashboard_card', array($this, 'refresh_dashboard_card'));
        add_action('wp_ajax_brcc_get_sales_comparison', array($this, 'get_sales_comparison'));
        add_action('wp_ajax_brcc_fetch_all_attendees_for_date', array($this, 'fetch_all_attendees_for_date'));
        add_action('wp_ajax_brcc_export_all_attendees_csv', array($this, 'export_all_attendees_csv'));
        add_action('wp_ajax_brcc_fix_fooevents_metadata_action', array($this, 'handle_fix_fooevents_metadata'));
    }
    
    /**
     * AJAX: Regenerate API key
     */
    public function regenerate_api_key()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        // Generate new API key
        $api_key = 'brcc_' . md5(uniqid(rand(), true));

        // Update settings
        $settings = get_option('brcc_api_settings', array());
        $settings['api_key'] = $api_key;
        update_option('brcc_api_settings', $settings);

        wp_send_json_success(array(
            'message' => __('API key regenerated successfully.', 'brcc-inventory-tracker'),
            'api_key' => $api_key
        ));
    }

    /**
     * AJAX: Sync inventory now
     */
    public function sync_inventory_now()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        // Get product_id and event_date if provided
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $event_date = isset($_POST['event_date']) ? sanitize_text_field($_POST['event_date']) : '';
        
        // Determine if this is a force sync for a specific product/date or a general sync
        $is_force_sync = ($product_id && $event_date);
        
        // Log sync initiation
        if (BRCC_Helpers::is_test_mode()) {
            if ($is_force_sync) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Force Sync',
                    sprintf(__('Force sync triggered for Product ID: %d, Date: %s', 'brcc-inventory-tracker'),
                        $product_id,
                        $event_date
                    )
                );
            } else {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Manual Sync',
                    __('Manual sync triggered from admin dashboard', 'brcc-inventory-tracker')
                );
            }
        } else if (BRCC_Helpers::should_log()) {
            if ($is_force_sync) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Force Sync',
                    sprintf(__('Force sync triggered for Product ID: %d, Date: %s (Live Mode)', 'brcc-inventory-tracker'),
                        $product_id,
                        $event_date
                    )
                );
            } else {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Manual Sync',
                    __('Manual sync triggered from admin dashboard (Live Mode)', 'brcc-inventory-tracker')
                );
            }
        }

        // Trigger sync action with appropriate parameters
        if ($is_force_sync) {
            // Pass product_id and event_date for force sync
            do_action('brcc_sync_inventory', true, $product_id, $event_date);
        } else {
            // General sync
            do_action('brcc_sync_inventory', true);
        }

        // Update last sync time
        update_option('brcc_last_sync_time', time());

        // Prepare response message
        $message = $is_force_sync
            ? sprintf(__('Inventory synchronized successfully for Product ID: %d, Date: %s.', 'brcc-inventory-tracker'), $product_id, $event_date)
            : __('Inventory synchronized successfully.', 'brcc-inventory-tracker');
            
        wp_send_json_success(array(
            'message' => $message,
            'timestamp' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            'test_mode' => BRCC_Helpers::is_test_mode()
        ));
    }

    /**
     * AJAX: Save product mappings
     */
    public function save_product_mappings()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        // Get mappings from request
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        // Sanitize mappings
        $sanitized_mappings = array();
        foreach ($mappings as $product_id => $mapping) {
            // Standardize base mapping keys on save
            $ticket_id = isset($mapping['manual_eventbrite_id']) ? sanitize_text_field($mapping['manual_eventbrite_id']) : ''; // Get the primary ID
            $sanitized_mappings[absint($product_id)] = array(
                'manual_eventbrite_id' => $ticket_id, // Save primary ID
                'ticket_class_id' => $ticket_id,      // Save primary ID under this key too
                'eventbrite_id' => $ticket_id,        // Save primary ID for backward compatibility
                'eventbrite_event_id' => isset($mapping['eventbrite_event_id']) ? sanitize_text_field($mapping['eventbrite_event_id']) : '', // Event ID from dropdown
                'square_id' => isset($mapping['square_id']) ? sanitize_text_field($mapping['square_id']) : '' // Square ID input
            );
        }

        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Save Mappings',
                sprintf(__('Would save %d product mappings', 'brcc-inventory-tracker'), count($sanitized_mappings))
            );

            wp_send_json_success(array(
                'message' => __('Product mappings would be saved in Test Mode.', 'brcc-inventory-tracker') . ' ' .
                    __('(No actual changes made)', 'brcc-inventory-tracker')
            ));
            return;
        }

        // Log in live mode if enabled
        if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Save Mappings',
                sprintf(__('Saving %d product mappings (Live Mode)', 'brcc-inventory-tracker'), count($sanitized_mappings))
            );
        }

        // Load existing mappings to preserve date-specific ones
        $product_mapping_instance = new BRCC_Product_Mappings(); // Use the class to ensure consistency
        $all_mappings = $product_mapping_instance->get_all_mappings(); // Gets cached or loaded mappings

        // Update only the base mappings from the submitted data
        foreach ($sanitized_mappings as $product_id => $base_mapping) {
             // Ensure the product ID key exists
             if (!isset($all_mappings[$product_id])) {
                 $all_mappings[$product_id] = array(); // Initialize if it's a new product being mapped
             }
             // Update/set the base mapping fields using standardized keys
             // unset($all_mappings[$product_id]['eventbrite_id']); // Remove old key if it exists - Keep this commented for now to avoid data loss if old key was used differently
             $all_mappings[$product_id]['eventbrite_event_id'] = $base_mapping['eventbrite_event_id']; // Event ID
             $all_mappings[$product_id]['square_id'] = $base_mapping['square_id']; // Square ID
             $all_mappings[$product_id]['eventbrite_id'] = $base_mapping['manual_eventbrite_id']; // Save Ticket Class ID under 'eventbrite_id'
             unset($all_mappings[$product_id]['manual_eventbrite_id']); // Remove the old key
             // IMPORTANT: Do NOT touch $all_mappings[$product_id . '_dates'] here
        }

        // Save the merged mappings
        update_option('brcc_product_mappings', $all_mappings);

        wp_send_json_success(array(
            'message' => __('Product mappings saved successfully.', 'brcc-inventory-tracker')
        ));
    }

    /**
     * AJAX: Test product mapping
     */
    public function test_product_mapping()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $manual_eventbrite_id = isset($_POST['manual_eventbrite_id']) ? sanitize_text_field($_POST['manual_eventbrite_id']) : ''; // Expect manual ID
        $eventbrite_event_id = isset($_POST['eventbrite_event_id']) ? sanitize_text_field($_POST['eventbrite_event_id']) : ''; // Expect event ID

        $results = array();

        // Get the product name for more informative messages
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #$product_id";
        $eventbrite_event_id = isset($_POST['eventbrite_event_id']) ? sanitize_text_field($_POST['eventbrite_event_id']) : ''; // Read correct POST key

        // Log test action
        if (BRCC_Helpers::is_test_mode()) {
            if (!empty($eventbrite_event_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Eventbrite Connection',
                    sprintf(
                        __('Testing Eventbrite connection for product ID %s with Eventbrite Event ID %s', 'brcc-inventory-tracker'), // Updated log message
                        $product_id,
                        $eventbrite_event_id
                    )
                );
            }
        } else if (BRCC_Helpers::should_log()) {
            if (!empty($eventbrite_event_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Eventbrite Connection',
                    sprintf(
                        __('Testing Eventbrite connection for product ID %s with Eventbrite Event ID %s (Live Mode)', 'brcc-inventory-tracker'), // Updated log message
                        $product_id,
                        $eventbrite_event_id
                    )
                );
            }
        }

        // Basic validation for Eventbrite Event ID
        if (!empty($eventbrite_event_id)) {
            $settings = get_option('brcc_api_settings', array());
            $has_eventbrite_token = !empty($settings['eventbrite_token']);

            if (!$has_eventbrite_token) {
                $results[] = __('Eventbrite configuration incomplete. Please add API Token in plugin settings.', 'brcc-inventory-tracker');
            } else {
                $results[] = sprintf(
                    __('Eventbrite Event ID "%s" is linked to product "%s". Eventbrite credentials are configured.', 'brcc-inventory-tracker'),
                    $eventbrite_event_id,
                    $product_name
                );

                // Test connection if class is available
                if (class_exists('BRCC_Eventbrite_Integration')) {
                    $eventbrite = new BRCC_Eventbrite_Integration();
                    $eventbrite_test = $eventbrite->test_connection();

                    if (is_wp_error($eventbrite_test)) {
                        $results[] = __('Eventbrite API test failed:', 'brcc-inventory-tracker') . ' ' . $eventbrite_test->get_error_message();
                    } else {
                        $results[] = __('Eventbrite API connection successful!', 'brcc-inventory-tracker');
                        
                        // Test ticket validation using manual ID and event ID
                        if (!empty($manual_eventbrite_id) && !empty($eventbrite_event_id) && method_exists($eventbrite, 'validate_ticket_belongs_to_event')) {
                            $validation = $eventbrite->validate_ticket_belongs_to_event($manual_eventbrite_id, $eventbrite_event_id);
                            if (is_wp_error($validation)) {
                                $results[] = __('Ticket validation failed:', 'brcc-inventory-tracker') . ' ' . $validation->get_error_message();
                            } elseif ($validation === true) {
                                $results[] = __('Ticket ID belongs to the selected Event ID. ✓', 'brcc-inventory-tracker');
                            } else {
                                $results[] = __('WARNING: Ticket ID may not belong to the selected Event ID!', 'brcc-inventory-tracker');
                            }
                        }
                    }
                }
            }
        }

        if (empty($results)) {
            $results[] = __('No tests performed. Please select an Eventbrite Event ID.', 'brcc-inventory-tracker'); // Updated result message
        }

        // Add test mode notice
        if (BRCC_Helpers::is_test_mode()) {
            $results[] = __('Note: Tests work normally even in Test Mode.', 'brcc-inventory-tracker');
        }

        wp_send_json_success(array(
            'message' => implode('<br>', $results)
        ));
    }

    /**
     * AJAX: Test Square connection
     */
    public function test_square_connection()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        // Initialize Square integration
        if (!class_exists('BRCC_Square_Integration')) {
            wp_send_json_error(array(
                'message' => __('Square integration class not found.', 'brcc-inventory-tracker')
            ));
            return;
        }
        
        $square = new BRCC_Square_Integration();

        // Test connection
        $result = $square->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Square API connection failed: %s', 'brcc-inventory-tracker'),
                    $result->get_error_message()
                )
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => __('Square API connection successful!', 'brcc-inventory-tracker')
        ));
    }

    /**
     * AJAX: Get Square catalog
     */
    public function get_square_catalog()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        // Initialize Square integration
        if (!class_exists('BRCC_Square_Integration')) {
            wp_send_json_error(array(
                'message' => __('Square integration class not found.', 'brcc-inventory-tracker')
            ));
            return;
        }
        
        $square = new BRCC_Square_Integration();

        // Get catalog items
        $catalog = $square->get_catalog_items();

        if (is_wp_error($catalog)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Failed to retrieve Square catalog: %s', 'brcc-inventory-tracker'),
                    $catalog->get_error_message()
                )
            ));
            return;
        }

        wp_send_json_success(array(
            'catalog' => $catalog
        ));
    }

    /**
     * AJAX: Test Square mapping
     */
    public function test_square_mapping()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $square_id = isset($_POST['square_id']) ? sanitize_text_field($_POST['square_id']) : '';

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        $results = array();

        // Get the product name for more informative messages
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #$product_id";

        // Log test action
        if (BRCC_Helpers::is_test_mode()) {
            if (!empty($square_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Square Connection',
                    sprintf(
                        __('Testing Square connection for product ID %s with Square ID %s', 'brcc-inventory-tracker'),
                        $product_id,
                        $square_id
                    )
                );
            }
        } else if (BRCC_Helpers::should_log()) {
            if (!empty($square_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Square Connection',
                    sprintf(
                        __('Testing Square connection for product ID %s with Square ID %s (Live Mode)', 'brcc-inventory-tracker'),
                        $product_id,
                        $square_id
                    )
                );
            }
        }

        // Basic validation for Square ID
        if (!empty($square_id)) {
            $settings = get_option('brcc_api_settings');
            $has_square_token = !empty($settings['square_access_token']);
            $has_square_location = !empty($settings['square_location_id']);

            if (!$has_square_token || !$has_square_location) {
                $results[] = __('Square configuration incomplete. Please add Access Token and Location ID in plugin settings.', 'brcc-inventory-tracker');
            } else {
                $results[] = sprintf(
                    __('Square ID "%s" is linked to product "%s". Square credentials are configured.', 'brcc-inventory-tracker'),
                    $square_id,
                    $product_name
                );

                // Test connection if class is available
                if (class_exists('BRCC_Square_Integration')) {
                    $square = new BRCC_Square_Integration();
                    $square_test = $square->test_connection();

                    if (is_wp_error($square_test)) {
                        $results[] = __('Square API test failed:', 'brcc-inventory-tracker') . ' ' . $square_test->get_error_message();
                    } else {
                        $results[] = __('Square API connection successful!', 'brcc-inventory-tracker');

                        // Try to get the specific item
                        $item = $square->get_catalog_item($square_id);
                        if (is_wp_error($item)) {
                            $results[] = __('Square item lookup failed:', 'brcc-inventory-tracker') . ' ' . $item->get_error_message();
                        } else {
                            $results[] = sprintf(
                                __('Successfully found Square item: %s', 'brcc-inventory-tracker'),
                                isset($item['name']) ? $item['name'] : $square_id
                            );
                        }
                    }
                }
            }
        }

        if (empty($results)) {
            $results[] = __('No tests performed. Please enter a Square Catalog ID.', 'brcc-inventory-tracker');
        }

        // Add test mode notice
        if (BRCC_Helpers::is_test_mode()) {
            $results[] = __('Note: Tests work normally even in Test Mode.', 'brcc-inventory-tracker');
        }

        wp_send_json_success(array(
            'message' => implode('<br>', $results)
        ));
    }

    /**
     * AJAX: Import batch
     */
    public function import_batch() {
        // Security checks
        if (!isset($_POST['state_data']['nonce']) || !wp_verify_nonce($_POST['state_data']['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        // Get state object passed from JavaScript
        $state = isset($_POST['state_data']) && is_array($_POST['state_data']) ? $_POST['state_data'] : null;

        if (!$state) {
            wp_send_json_error(array('message' => __('Invalid request state.', 'brcc-inventory-tracker')));
            return;
        }

        // Extract parameters from state
        $start_date = isset($state['start_date']) ? sanitize_text_field($state['start_date']) : null;
        $end_date = isset($state['end_date']) ? sanitize_text_field($state['end_date']) : null;
        $sources = isset($state['sources']) && is_array($state['sources']) ? array_map('sanitize_text_field', $state['sources']) : array();
        $batch_size = 25; // Process 25 items per batch

        // Validate dates
        if (!$start_date || !$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_send_json_error(array('message' => __('Invalid date range provided.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (strtotime($start_date) > strtotime($end_date)) {
            wp_send_json_error(array('message' => __('Start date cannot be after end date.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (empty($sources)) {
            wp_send_json_error(array('message' => __('No data sources selected.', 'brcc-inventory-tracker')));
            return;
        }

        $logs = array();
        $processed_count_total = 0;
        $next_offset = null; // Assume completion unless set otherwise
        $progress_message = '';
        $progress = 0;

        try {
            $current_source_index = $state['source_index'];
            $total_processed = $state['total_processed'];
            
            if ($current_source_index >= count($sources)) {
                // Should not happen if JS stops calling, but handle defensively
                wp_send_json_success(array(
                    'message' => 'Import already completed.',
                    'logs' => $logs,
                    'progress' => 100,
                    'next_offset' => null, // Signal JS to stop
                ));
                return;
            }

            // Add check for valid source index before accessing
            if (!isset($sources[$current_source_index])) {
                 $error_msg = 'Error: Invalid source index.';
                 throw new Exception($error_msg);
            }
            
            $current_source = $sources[$current_source_index];
            $logs[] = array('message' => "--- Starting batch for source: {$current_source} ---", 'type' => 'info');

            $batch_result = array(
                'processed_count' => 0,
                'next_offset' => null, // Contains next WC offset or Square cursor
                'source_complete' => false,
                'logs' => array()
            );

            // Process batch for the current source
            if ($current_source === 'woocommerce') {
                $offset = $state['wc_offset'];
                $logs[] = array('message' => "Processing WooCommerce batch (Offset: {$offset})...", 'type' => 'info');
                $sales_tracker = new BRCC_Sales_Tracker();
                if (method_exists($sales_tracker, 'import_woocommerce_batch')) {
                    $batch_result = $sales_tracker->import_woocommerce_batch($start_date, $end_date, $offset, $batch_size);
                    $state['wc_offset'] = $batch_result['next_offset']; // Update WC offset for next time
                } else {
                    $logs[] = array('message' => "WooCommerce import logic not found in BRCC_Sales_Tracker.", 'type' => 'error');
                    $batch_result['source_complete'] = true; // Skip this source
                }
            } elseif ($current_source === 'square') {
                $cursor = $state['square_cursor'];
                $logs[] = array('message' => "Processing Square batch " . ($cursor ? "(Cursor: {$cursor})" : "(First batch)") . "...", 'type' => 'info');
                $square_integration = new BRCC_Square_Integration();
                if (method_exists($square_integration, 'import_square_batch')) {
                    $batch_result = $square_integration->import_square_batch($start_date, $end_date, $cursor, $batch_size);
                    $state['square_cursor'] = $batch_result['next_offset']; // Update Square cursor for next time
                } else {
                    $logs[] = array('message' => "Square import logic not found in BRCC_Square_Integration.", 'type' => 'error');
                    $batch_result['source_complete'] = true; // Skip this source
                }
            } elseif ($current_source === 'eventbrite') {
                $page = isset($state['eventbrite_page']) ? absint($state['eventbrite_page']) : 1; // Get current page from state
                $logs[] = array('message' => "Processing Eventbrite batch (Page: {$page})...", 'type' => 'info');
                $sales_tracker = new BRCC_Sales_Tracker(); // Sales tracker handles the recording
                if (method_exists($sales_tracker, 'import_eventbrite_batch')) {
                    $batch_result = $sales_tracker->import_eventbrite_batch($start_date, $end_date, $page, $batch_size);
                    $state['eventbrite_page'] = $batch_result['next_offset']; // Update Eventbrite page for next time
                } else {
                    $logs[] = array('message' => "Eventbrite import logic not found in BRCC_Sales_Tracker.", 'type' => 'error');
                    $batch_result['source_complete'] = true; // Skip this source
                }
            } else {
                $logs[] = array('message' => "Unknown import source: {$current_source}", 'type' => 'error');
                $batch_result['source_complete'] = true; // Skip unknown source
            }

            // Merge logs from the batch
            $logs = array_merge($logs, $batch_result['logs']);
            $total_processed += $batch_result['processed_count'];
            $state['total_processed'] = $total_processed;

            // Determine next state
            $next_state_param = null; // This will be passed back to JS as the 'offset' for the next call
            if ($batch_result['source_complete']) {
                $logs[] = array('message' => "--- Finished processing source: {$current_source} ---", 'type' => 'info');
                $state['source_index']++; // Move to next source index
                // Reset offsets/cursors/pages for the next source (if any)
                $state['wc_offset'] = 0;
                $state['square_cursor'] = null;
                $state['eventbrite_page'] = 1; // Reset page for next source

                if ($state['source_index'] >= count($sources)) {
                    // All sources are complete
                    $progress = 100;
                    $progress_message = "Import finished. Processed {$total_processed} total items.";
                    $logs[] = array('message' => $progress_message, 'type' => 'success');
                } else {
                    // More sources to process
                    $next_state_param = $state; // Pass the updated state for the next source
                    // Estimate progress based on completed sources
                    $progress = min(99, round(($state['source_index'] / count($sources)) * 100));
                    $progress_message = "Finished {$current_source}. Moving to next source (" . (isset($sources[$state['source_index']]) ? $sources[$state['source_index']] : 'N/A') . ")...";
                }
            } else {
                // Current source needs more batches
                $next_state_param = $state; // Pass the updated state (with new offset/cursor/page)
                // Progress estimation is difficult without total counts, use source index + 0.5 for rough estimate
                $progress = min(99, round((($state['source_index'] + 0.5) / count($sources)) * 100));
                $progress_message = "Processed batch for {$current_source}. Total items processed so far: {$total_processed}. Continuing...";
            }
        } catch (Exception $e) {
            // Handle exceptions during batch processing
            $error_msg = 'Import Error: ' . $e->getMessage();
            $logs[] = array('message' => $error_msg, 'type' => 'error');
            wp_send_json_error(array(
                'message' => $error_msg,
                'logs' => $logs,
            ));
            return;
        }

        // Send success response (even if moving to next batch/source)
        wp_send_json_success(array(
            'message' => $progress_message,
            'logs' => $logs,
            'progress' => $progress,
            'next_state' => $next_state_param // Send back the state for the next JS call
        ));
    }

    /**
     * AJAX: Suggest Eventbrite ID for a product
     * AJAX handler to suggest Eventbrite Ticket Class IDs for a product.
     */
    public function suggest_eventbrite_ticket_class_id() { // Renamed function
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }
        BRCC_Helpers::log_operation('Admin', 'Suggest Eventbrite ID', 'Attempting to suggest ID for Product ID: ' . $product_id);

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => __('Product not found.', 'brcc-inventory-tracker')));
            return;
        }
        
        $product_sku = $product->get_sku();
        
        // Check if Eventbrite integration class exists
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            wp_send_json_error(array('message' => __('Eventbrite Integration is not available.', 'brcc-inventory-tracker')));
            return;
        }
        
        try {
            $eventbrite_integration = new BRCC_Eventbrite_Integration();
            
            // Get suggestions (passing product object, no specific date/time)
            // Pass SKU to the suggestion function (assuming it suggests ticket class IDs)
            $suggestions = $eventbrite_integration->suggest_eventbrite_ids_for_product($product, null, null, $product_sku);
            
            // Check if the suggestion function returned an error
            if (is_wp_error($suggestions)) {
                $error_message = $suggestions->get_error_message();
                BRCC_Helpers::log_error('Suggest Eventbrite Ticket Class ID Error: suggest_eventbrite_ids_for_product returned WP_Error: ' . $error_message); // Updated log message
                wp_send_json_error(array('message' => $error_message));
                return;
            }
            
            if (empty($suggestions)) {
                BRCC_Helpers::log_operation('Admin', 'Suggest Eventbrite ID Result', 'No relevant Eventbrite events/tickets found for Product ID: ' . $product_id);
                wp_send_json_error(array('message' => __('No relevant Eventbrite events/tickets found.', 'brcc-inventory-tracker')));
                return;
            }
            
            // Log the top suggestion details
            $top_suggestion = $suggestions[0];
            $log_details = sprintf(
                'Suggestion found for Product ID %d: Ticket ID %s (Event: "%s", Ticket: "%s", Relevance: %s)',
                $product_id,
                $top_suggestion['ticket_id'],
                $top_suggestion['event_name'],
                $top_suggestion['ticket_name'],
                $top_suggestion['relevance']
            );
            BRCC_Helpers::log_operation('Admin', 'Suggest Eventbrite ID Result', $log_details);
            
            // Return the top suggestion
            wp_send_json_success(array(
                'message' => __('Suggestion found.', 'brcc-inventory-tracker'),
                'suggestion' => $top_suggestion // Send the highest relevance suggestion
            ));
        } catch (Exception $e) {
            BRCC_Helpers::log_error('Suggest Eventbrite ID Exception: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'An unexpected error occurred while suggesting ID.'));
        }
    }

 /**
 * AJAX handler to get all Eventbrite events for the Attendee List dropdown.
 */
public function get_all_eventbrite_events_for_attendees() {
    // Check nonce and capability
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
        wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        return;
    }
    
    if (!current_user_can('manage_options')) { // Check capability
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        return;
    }
   
    // Make the date parameter optional
    $selected_date = isset($_POST['selected_date']) ? sanitize_text_field($_POST['selected_date']) : date('Y-m-d');

    // Validate date format if provided
    if ($selected_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
        wp_send_json_error(array('message' => __('Invalid date format provided.', 'brcc-inventory-tracker')));
        return;
    }

    // Make date optional to support both old and new code
    // (Remove this condition that requires a date)
    /*
    if (!$selected_date) {
        wp_send_json_error(array('message' => __('Please select a date first.', 'brcc-inventory-tracker')));
        return;
    }
    */
    
    // --- Cache Check ---
    $cache_key = $selected_date ? 'brcc_events_for_date_' . $selected_date : 'brcc_eventbrite_events_list';
    $cached_events = get_transient($cache_key);

    if (false !== $cached_events) {
        // Ensure array keys are preserved if needed by Select2/frontend
        wp_send_json_success(array('events' => (array) $cached_events)); // Cast to array just in case
        return;
    }

    // --- Fetch from API if not cached ---
    if (!class_exists('BRCC_Eventbrite_Integration')) {
        wp_send_json_error(array('message' => __('Eventbrite Integration class not found.', 'brcc-inventory-tracker')));
        return;
    }
    
    $eventbrite_integration = new BRCC_Eventbrite_Integration();
    $events_for_dropdown = array();
    $error_message = null;

    try {
        // Choose method based on whether a date was provided
        if ($selected_date && method_exists($eventbrite_integration, 'get_events_for_date')) {
            // Fetch events specifically for the selected date
            $events_on_date = $eventbrite_integration->get_events_for_date($selected_date);
            
            if (is_wp_error($events_on_date)) {
                $error_message = $events_on_date->get_error_message();
            } elseif (is_array($events_on_date)) {
                // Process the result (assuming it's an array of event objects/arrays)
                foreach ($events_on_date as $event) {
                    // Adjust keys based on actual return structure of get_events_for_date
                    $event_id = isset($event['id']) ? $event['id'] : null;
                    $event_name = isset($event['name']['text']) ? $event['name']['text'] : (isset($event['name']) ? $event['name'] : null); // Eventbrite often uses name.text
                    $event_time = isset($event['start']['local']) ? date('g:i A', strtotime($event['start']['local'])) : ''; // Format time

                    if ($event_id && $event_name) {
                        // Append time AND ID to the name for clarity in the dropdown
                        $event_display_name = $event_name;
                        if ($event_time) {
                            $event_display_name .= ' (' . $event_time . ')';
                        }
                        $event_display_name .= ' [' . $event_id . ']'; // Add ID in brackets
                        $events_for_dropdown[$event_id] = $event_display_name;
                    }
                }
            }
        } else {
            // Fallback to organization events if no date or the date-specific method doesn't exist
            $events_result = $eventbrite_integration->get_organization_events(array('live', 'draft', 'started'));
            
            if (is_wp_error($events_result)) {
                $error_message = $events_result->get_error_message();
            } elseif (is_array($events_result)) {
                foreach ($events_result as $event) {
                    if (isset($event['id']) && isset($event['name']['text'])) {
                        // Add ID to label for clarity (using brackets for consistency)
                        $events_for_dropdown[$event['id']] = esc_html($event['name']['text']) . ' [' . $event['id'] . ']';
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $error_message = "Exception getting events: " . $e->getMessage();
    }

    if ($error_message) {
        wp_send_json_error(array(
            'message' => __('Error fetching Eventbrite events: ', 'brcc-inventory-tracker') . $error_message
        ));
        return;
    }
    
    // Sort events alphabetically by name (label)
    uasort($events_for_dropdown, function($a, $b) {
        return strcasecmp($a, $b);
    });

    // --- Save to Cache ---
    set_transient($cache_key, $events_for_dropdown, 1 * HOUR_IN_SECONDS); // Cache for 1 hour
    
    wp_send_json_success(array('events' => $events_for_dropdown));
}

    /**
     * AJAX handler to test Eventbrite connection.
     */
    public function test_eventbrite_connection() {
        // Check nonce and capabilities
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'brcc-inventory-tracker')));
            return;
        }

        // Instantiate the Eventbrite integration class
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            wp_send_json_error(array('message' => __('Eventbrite integration class not found.', 'brcc-inventory-tracker')));
            return;
        }
        
        $eventbrite = new BRCC_Eventbrite_Integration();
        $result = $eventbrite->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => __('Eventbrite connection failed:', 'brcc-inventory-tracker') . ' ' . $result->get_error_message()
            ));
        } elseif ($result === true) {
            wp_send_json_success(array(
                'message' => __('Eventbrite connection successful!', 'brcc-inventory-tracker')
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Eventbrite connection test returned an unexpected result.', 'brcc-inventory-tracker')
            ));
        }
    }

    /**
     * AJAX: Get product dates
     */
    public function get_product_dates()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        // Pagination parameters
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 25; // Default 25 per page
        $offset = ($page - 1) * $per_page;
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }
        
        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => __('Product not found.', 'brcc-inventory-tracker')));
            return;
        }

        // Get date-specific mappings
        $all_mappings = get_option('brcc_product_mappings', array());
        $date_mappings = isset($all_mappings[$product_id . '_dates']) ? $all_mappings[$product_id . '_dates'] : array();
        
        // Prepare date mappings for display
        $dates = array();
        
        // Sort all date mappings chronologically first
        uksort($date_mappings, function($a, $b) {
            return strtotime($a) - strtotime($b);
        });

        $total_mappings = count($date_mappings);
        $total_pages = ceil($total_mappings / $per_page);

        // Get the slice for the current page
        $paged_mappings = array_slice($date_mappings, $offset, $per_page, true); // Keep keys

        if (!empty($paged_mappings)) {
            foreach ($paged_mappings as $date => $mapping) {
                // Get inventory for this date if available
                $inventory = null; // Placeholder - Implement if needed

                // Format date for display
                $formatted_date = date_i18n(get_option('date_format'), strtotime($date));

                // Get saved values, defaulting to empty strings or null
                $event_id = isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : '';
                // Retrieve ticket class ID checking multiple keys
                $retrieved_ticket_id = '';
                if (isset($mapping['manual_eventbrite_id']) && !empty($mapping['manual_eventbrite_id'])) {
                    $retrieved_ticket_id = $mapping['manual_eventbrite_id'];
                } elseif (isset($mapping['ticket_class_id']) && !empty($mapping['ticket_class_id'])) {
                    $retrieved_ticket_id = $mapping['ticket_class_id'];
                } elseif (isset($mapping['eventbrite_id']) && !empty($mapping['eventbrite_id'])) {
                    $retrieved_ticket_id = $mapping['eventbrite_id'];
                }

                $square_id = isset($mapping['square_id']) ? $mapping['square_id'] : '';
                $time = isset($mapping['time']) ? $mapping['time'] : null;

                // Format time for display if it exists
                $formatted_time = $time ? date_i18n(get_option('time_format'), strtotime($time)) : '';

                // Use retrieved ticket class ID for display name if available
                $ticket_name = '';
                if (!empty($retrieved_ticket_id)) {
                    $ticket_name = sprintf(__('Ticket Class ID: %s', 'brcc-inventory-tracker'), $retrieved_ticket_id);
                } elseif (!empty($event_id)) {
                    // Fallback to event ID if ticket class ID is empty but event ID exists
                    $ticket_name = sprintf(__('Event ID: %s', 'brcc-inventory-tracker'), $event_id);
                }


                $dates[] = array(
                   'date' => $date,
                   'formatted_date' => $formatted_date,
                   'time' => $time, // Send raw time value
                   'formatted_time' => $formatted_time, // Send formatted time
                   'inventory' => $inventory,
                   'eventbrite_event_id' => $event_id, // From dropdown selection
                   // Send the retrieved ID under all relevant keys for frontend consistency
                   'manual_eventbrite_id' => $retrieved_ticket_id,
                   'ticket_class_id' => $retrieved_ticket_id,
                   'eventbrite_ticket_class_id' => $retrieved_ticket_id, // Keep this for potential JS usage? Or remove? Keeping for now.
                   'eventbrite_id' => $retrieved_ticket_id, // For backward compatibility if JS checks it
                   'square_id' => $square_id, // Square ID
                   'ticket_name' => $ticket_name, // Display name (can be improved)
                   // 'manual_eventbrite_id' => isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : '', // This is now handled above
                );
            }
        }

        wp_send_json_success(array(
            'dates' => $dates, // Only dates for the current page
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_mappings' => $total_mappings,
                'total_pages' => $total_pages
            )
        ));
    }

    /**
     * AJAX: Save product date mappings
     */
    public function save_product_date_mappings()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']): 0;
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => __('Product not found.', 'brcc-inventory-tracker')));
            return;
        }

        // Check if test mode is enabled
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Save Date Mappings',
                sprintf(__('Would save %d date mappings for product ID %d', 'brcc-inventory-tracker'), count($mappings), $product_id)
            );

            wp_send_json_success(array(
                'message' => __('Date mappings would be saved in Test Mode.', 'brcc-inventory-tracker') . ' ' .
                    __('(No actual changes made)', 'brcc-inventory-tracker')
            ));
            return;
        }

        // Log in live mode if enabled
        if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Save Date Mappings',
                sprintf(__('Saving %d date mappings for product ID %d (Live Mode)', 'brcc-inventory-tracker'), count($mappings), $product_id)
            );
        }

        // Process mappings
        $date_mappings = array();
        foreach ($mappings as $mapping) {
            if (empty($mapping['date'])) continue;
            
            $date = sanitize_text_field($mapping['date']);
            $time = isset($mapping['time']) ? sanitize_text_field($mapping['time']) : null;
            $event_id = isset($mapping['eventbrite_event_id']) ? sanitize_text_field($mapping['eventbrite_event_id']) : '';
            
            // Check multiple possible sources for the ticket class ID from POST data
            $retrieved_ticket_id = '';
            if (isset($mapping['manual_eventbrite_id']) && !empty($mapping['manual_eventbrite_id'])) {
                $retrieved_ticket_id = sanitize_text_field($mapping['manual_eventbrite_id']);
            } elseif (isset($mapping['ticket_class_id']) && !empty($mapping['ticket_class_id'])) {
                $retrieved_ticket_id = sanitize_text_field($mapping['ticket_class_id']);
            } elseif (isset($mapping['eventbrite_id']) && !empty($mapping['eventbrite_id'])) {
                $retrieved_ticket_id = sanitize_text_field($mapping['eventbrite_id']);
            }
            
            $square_id = isset($mapping['square_id']) ? sanitize_text_field($mapping['square_id']) : ''; // Get Square ID

            // Save the retrieved ID under all relevant keys
            $current_mapping = array(
                'eventbrite_event_id' => $event_id, // From dropdown
                'manual_eventbrite_id' => $retrieved_ticket_id,
                'ticket_class_id' => $retrieved_ticket_id,
                'eventbrite_id' => $retrieved_ticket_id, // For backward compatibility
                'square_id' => $square_id,
            );

            // Add time only if it exists
            if ($time) {
                $current_mapping['time'] = $time;
            }

            $date_mappings[$date] = $current_mapping;
        }

        // Get all existing mappings
        $all_mappings = get_option('brcc_product_mappings', array());
        
        // Update date mappings for this product
        if (empty($date_mappings)) {
            // If no mappings, remove the date mapping entry
            unset($all_mappings[$product_id . '_dates']);
        } else {
            // Save the date mappings
            $all_mappings[$product_id . '_dates'] = $date_mappings;
        }
        
        // Update mappings
        $updated = update_option('brcc_product_mappings', $all_mappings);

        wp_send_json_success(array(
            'message' => __('Date mappings saved successfully.', 'brcc-inventory-tracker'),
            'count' => count($date_mappings)
        ));
    }

    /**
     * AJAX: Test product date mapping
     */
    public function test_product_date_mapping()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $eventbrite_event_id = isset($_POST['eventbrite_event_id']) ? sanitize_text_field($_POST['eventbrite_event_id']) : '';
        $manual_eventbrite_id = isset($_POST['manual_eventbrite_id']) ? sanitize_text_field($_POST['manual_eventbrite_id']) : ''; // Expect manual ID

        if (empty($product_id) || empty($date)) {
            wp_send_json_error(array('message' => __('Product ID and date are required.', 'brcc-inventory-tracker')));
            return;
        }

        $results = array();

        // Get the product name for more informative messages
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #$product_id";

        // Format the date for display
        $formatted_date = date_i18n(get_option('date_format'), strtotime($date));

        // Log test action
        if (BRCC_Helpers::is_test_mode()) {
            if (!empty($manual_eventbrite_id)) { // Log based on manual ID
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Date Mapping',
                    sprintf(
                        __('Testing date mapping for product ID %s on date %s with Eventbrite ID %s', 'brcc-inventory-tracker'),
                        $product_id,
                        $formatted_date,
                        $manual_eventbrite_id // Log manual ID
                    )
                );
            }
        } else if (BRCC_Helpers::should_log()) {
            if (!empty($manual_eventbrite_id)) { // Log based on manual ID
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Date Mapping',
                    sprintf(
                        __('Testing date mapping for product ID %s on date %s with Eventbrite ID %s (Live Mode)', 'brcc-inventory-tracker'),
                        $product_id,
                        $formatted_date,
                        $manual_eventbrite_id // Log manual ID
                    )
                );
            }
        }

        // Basic validation for Eventbrite ID and Event ID
        if (empty($eventbrite_event_id)) {
            $results[] = __('Please select an Eventbrite Event.', 'brcc-inventory-tracker');
        } else {
            $results[] = sprintf(
                __('Event ID "%s" is configured for product "%s" on %s.', 'brcc-inventory-tracker'),
                $eventbrite_event_id,
                $product_name,
                $formatted_date
            );
        }

        // Check if manual ID is provided
        if (empty($manual_eventbrite_id)) {
            $results[] = __('Please provide an Eventbrite Ticket Class ID.', 'brcc-inventory-tracker');
        } else {
            $results[] = sprintf(
                __('Ticket Class ID "%s" is configured for product "%s" on %s.', 'brcc-inventory-tracker'),
                $manual_eventbrite_id, // Use manual ID in message
                $product_name,
                $formatted_date
            );
        }

        // Validate connection using both event ID and manual ticket ID
        if (!empty($eventbrite_event_id) && !empty($manual_eventbrite_id)) {
            $settings = get_option('brcc_api_settings', array());
            $has_eventbrite_token = !empty($settings['eventbrite_token']);

            if (!$has_eventbrite_token) {
                $results[] = __('Eventbrite configuration incomplete. Please add API Token in plugin settings.', 'brcc-inventory-tracker');
            } else {
                // Test connection if class is available
                if (class_exists('BRCC_Eventbrite_Integration')) {
                    $eventbrite = new BRCC_Eventbrite_Integration();
                    $eventbrite_test = $eventbrite->test_connection();

                    if (is_wp_error($eventbrite_test)) {
                        $results[] = __('Eventbrite API test failed:', 'brcc-inventory-tracker') . ' ' . $eventbrite_test->get_error_message();
                    } else {
                        $results[] = __('Eventbrite API connection successful!', 'brcc-inventory-tracker');
                        
                        // Validate that the ticket belongs to the event
                        if (method_exists($eventbrite, 'validate_ticket_belongs_to_event')) {
                            $valid = $eventbrite->validate_ticket_belongs_to_event($manual_eventbrite_id, $eventbrite_event_id); // Use manual ID
                            if (is_wp_error($valid)) {
                                $results[] = __('Error validating ticket/event relationship:', 'brcc-inventory-tracker') . ' ' . $valid->get_error_message();
                            } elseif ($valid === true) {
                                $results[] = __('Ticket ID belongs to the selected Event ID. ✓', 'brcc-inventory-tracker');
                            } else {
                                $results[] = __('WARNING: Ticket ID may not belong to the selected Event ID!', 'brcc-inventory-tracker');
                            }
                        } else {
                            $results[] = __('NOTICE: Unable to validate ticket/event relationship (validation method not available).', 'brcc-inventory-tracker');
                        }
                    }
                }
            }
        }

        if (empty($results)) {
            $results[] = __('No tests performed. Please provide Eventbrite Event and Ticket IDs.', 'brcc-inventory-tracker');
        }

        // Add test mode notice
        if (BRCC_Helpers::is_test_mode()) {
            $results[] = __('Note: Tests work normally even in Test Mode.', 'brcc-inventory-tracker');
        }

        wp_send_json_success(array(
            'message' => implode('<br>', $results)
        ));
    }

    /**
     * AJAX handler to get WooCommerce products (ticket classes) that have events on a specific date.
     */
    public function get_products_for_date() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brcc-inventory-tracker')]);
            return;
        }

        if (!isset($_POST['selected_date']) || !method_exists('BRCC_Helpers', 'parse_date_value') || BRCC_Helpers::parse_date_value($_POST['selected_date']) === null) {
            wp_send_json_error(['message' => __('Invalid or missing date format. Please use YYYY-MM-DD.', 'brcc-inventory-tracker')]);
            return;
        }

        $selected_date = sanitize_text_field($_POST['selected_date']);
        $source_filter = isset($_POST['source_filter']) ? sanitize_text_field($_POST['source_filter']) : 'all';
        $products_on_date = [];

        try {
            $product_mappings_instance = new BRCC_Product_Mappings();
            $all_mappings = $product_mappings_instance->get_all_mappings();

            if (empty($all_mappings)) {
                wp_send_json_success(['products' => []]);
                return;
            }

            $products_found_ids = []; // Store IDs of products confirmed for the date

            // Iterate through mappings to find products with date-specific entries for the selected date
            foreach ($all_mappings as $product_id_key => $mapping_data) {
                // Check only date-specific mapping arrays
                if (strpos($product_id_key, '_dates') !== false && is_array($mapping_data)) {
                    $base_product_id = (int) str_replace('_dates', '', $product_id_key);

                    foreach ($mapping_data as $date_key => $date_mapping) {
                        // Check if the key is the exact date OR starts with the date followed by '_' (time-specific)
                        if ($date_key === $selected_date || strpos($date_key, $selected_date . '_') === 0) {
                            // Apply source filter using standardized keys
                            $has_eventbrite = !empty($date_mapping['eventbrite_event_id']) || !empty($date_mapping['manual_eventbrite_id']);
                            $has_woocommerce = true; // All products are WooCommerce products

                            if ($source_filter === 'all' ||
                                ($source_filter === 'eventbrite' && $has_eventbrite) ||
                                ($source_filter === 'woocommerce' && $has_woocommerce)) {
                                if (!in_array($base_product_id, $products_found_ids)) {
                                    $products_found_ids[] = $base_product_id;
                                }
                            }
                            break; // Found a relevant entry for this date, move to next product
                        }
                    }
                }
            }

            // Fetch Product Names
            $products_on_date = [];
            if (!empty($products_found_ids)) {
                $args = array(
                    'post_type' => 'product',
                    'posts_per_page' => -1,
                    'post_status' => 'publish',
                    'post__in' => $products_found_ids,
                    'orderby' => 'title',
                    'order' => 'ASC'
                );
                $products_query = new WP_Query($args);

                if ($products_query->have_posts()) {
                    while ($products_query->have_posts()) {
                        $products_query->the_post();
                        $products_on_date[] = [
                            'product_id' => get_the_ID(),
                            'product_name' => get_the_title()
                        ];
                    }
                    wp_reset_postdata();
                }
            }

            wp_send_json_success(['products' => $products_on_date]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => __('An unexpected error occurred.', 'brcc-inventory-tracker')]);
        }
    }

    /**
     * AJAX handler to fetch attendees for a specific Product ID on a specific date.
     */
    public function fetch_product_attendees_for_date() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brcc-inventory-tracker')]);
            return;
        }
       
        if (!isset($_POST['product_id']) || !is_numeric($_POST['product_id'])) {
            wp_send_json_error(['message' => __('Invalid or missing Product ID.', 'brcc-inventory-tracker')]);
            return;
        }

        if (!isset($_POST['selected_date']) || !method_exists('BRCC_Helpers', 'parse_date_value') || BRCC_Helpers::parse_date_value($_POST['selected_date']) === null) {
            wp_send_json_error(['message' => __('Invalid or missing date format. Please use YYYY-MM-DD.', 'brcc-inventory-tracker')]);
            return;
        }

        $product_id = absint($_POST['product_id']);
        $selected_date = sanitize_text_field($_POST['selected_date']);
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = 50; // Default per page
        $source_filter = isset($_POST['source_filter']) ? sanitize_text_field($_POST['source_filter']) : 'all'; // 'all', 'eventbrite', 'woocommerce'

        $all_attendees = []; // Initialize array to hold merged attendees

        // Fetch from Eventbrite
        if (!class_exists('BRCC_Eventbrite_Integration') || !class_exists('BRCC_Product_Mappings')) {
            wp_send_json_error(['message' => __('Required components not available.', 'brcc-inventory-tracker')]);
            return;
        }

        $eventbrite_integration = new BRCC_Eventbrite_Integration();
        $product_mappings = new BRCC_Product_Mappings();
        $event_id_to_fetch = null;

        // Find Eventbrite Event ID from mapping
        $date_mapping = $product_mappings->get_product_mappings($product_id, $selected_date);

        if ($date_mapping && !empty($date_mapping['eventbrite_event_id'])) {
            $event_id_to_fetch = $date_mapping['eventbrite_event_id'];

            try {
                // Fetch ALL attendees from Eventbrite for this event
                $eventbrite_data = $eventbrite_integration->get_event_attendees($event_id_to_fetch);

                if (is_wp_error($eventbrite_data)) {
                    // Log error but continue to fetch WC data
                    BRCC_Helpers::log_error("Attendee Fetch: Error fetching Eventbrite attendees for Event ID {$event_id_to_fetch}.", $eventbrite_data);
                } elseif (is_array($eventbrite_data) && isset($eventbrite_data['attendees']) && is_array($eventbrite_data['attendees'])) {
                    // Correctly loop over the attendees array within the returned structure
                    foreach ($eventbrite_data['attendees'] as $attendee) {
                        // Basic sanitization & Formatting
                        // Check if 'profile' exists before accessing nested keys
                        $profile = $attendee['profile'] ?? [];
                        $name = isset($profile['name']) ? sanitize_text_field($profile['name']) : 'N/A';
                        $email = isset($profile['email']) ? sanitize_email($profile['email']) : 'N/A';
                        $purchase_date_raw = isset($attendee['created']) ? $attendee['created'] : null;
                        $purchase_date_formatted = $purchase_date_raw ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($purchase_date_raw)) : 'N/A';
                        $order_ref = isset($attendee['order_id']) ? sanitize_text_field($attendee['order_id']) : 'N/A';
                        $status = isset($attendee['status']) ? sanitize_text_field($attendee['status']) : 'N/A'; // Eventbrite status ('attending', etc.)

                        $all_attendees[] = [
                            'name' => $name,
                            'email' => $email,
                            'purchase_date' => $purchase_date_formatted,
                            'order_ref' => $order_ref,
                            'status' => $status,
                            'source' => 'Eventbrite' // Add source
                        ];
                    }
                }
            } catch (Exception $e) {
                BRCC_Helpers::log_error("Attendee Fetch: Exception fetching Eventbrite attendees: " . $e->getMessage());
                // Continue to fetch WC data
            }
        }

        // Fetch from WooCommerce
        $wc_attendees = [];
        $args = array(
            'status' => array('wc-processing', 'wc-completed'), // Only include orders likely paid/valid
            'limit' => -1, // Get all matching orders
            'return' => 'ids', // Get only IDs first for better performance
        );
        $query = new WC_Order_Query($args);
        $order_ids = $query->get_orders();

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            $order_date_obj = $order->get_date_created();
            $order_purchase_date = $order_date_obj ? $order_date_obj->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : 'N/A';

            foreach ($order->get_items() as $item_id => $item) {
                // Check if the item is for the product we are looking for
                $item_product_id = $item->get_product_id();
                $item_variation_id = $item->get_variation_id();
                $actual_product_id = $item_variation_id ? $item_variation_id : $item_product_id;

                if ($actual_product_id == $product_id) {
                    // Check if the item's event date matches the selected date
                    if (method_exists('BRCC_Helpers', 'get_fooevents_date_from_item')) {
                        $item_event_date = BRCC_Helpers::get_fooevents_date_from_item($item);
                        $item_event_time = method_exists('BRCC_Helpers', 'extract_booking_time_from_item') ?
                            BRCC_Helpers::extract_booking_time_from_item($item) : null;

                        BRCC_Helpers::log_debug(sprintf(
                            "[Attendee List] Order #%s, Item #%s: Extracted date: %s, time: %s",
                            $order->get_id(),
                            $item_id,
                            $item_event_date ?: 'NULL',
                            $item_event_time ?: 'NULL'
                        ));

                        // Ensure date was extracted AND matches
                        if ($item_event_date !== null && $item_event_date === $selected_date) {
                            // Get FooEvents ticket details
                            $ticket_meta = BRCC_Helpers::_get_fooevents_ticket_meta_by_order_product(
                                $order->get_id(),
                                $actual_product_id,
                                $item_id
                            );

                            // Match found! Extract details.
                            $wc_attendees[] = [
                                'name' => $order->get_formatted_billing_full_name() ?: 'N/A',
                                'email' => $order->get_billing_email() ?: 'N/A',
                                'purchase_date' => $order_purchase_date,
                                'order_ref' => $order->get_order_number(), // WC Order number
                                'status' => $order->get_status(), // WC Order status
                                'source' => 'WooCommerce',
                                'ticket_id' => $ticket_meta ? ($ticket_meta['ticket_id'] ?? 'N/A') : 'N/A',
                                'booking_slot' => $item_event_time ?: 'N/A'
                            ];

                            BRCC_Helpers::log_debug(sprintf(
                                "[Attendee List] Order #%s, Item #%s: Added attendee %s (%s)",
                                $order->get_id(),
                                $item_id,
                                $order->get_formatted_billing_full_name() ?: 'N/A',
                                $order->get_billing_email() ?: 'N/A'
                            ));
                        }
                    }
                }
            }
        }

        // Merge Results
        $all_attendees = array_merge($all_attendees, $wc_attendees);

        // Apply Source Filter
        if ($source_filter !== 'all' && !empty($all_attendees)) {
            $filtered_attendees = array_filter($all_attendees, function($attendee) use ($source_filter) {
                return isset($attendee['source']) && strtolower($attendee['source']) === strtolower($source_filter);
            });
            $all_attendees = array_values($filtered_attendees); // Re-index array
        }

        // Handle Pagination & Send Response
        $total_attendees = count($all_attendees); // Count after filtering
        $total_pages = $per_page > 0 ? ceil($total_attendees / $per_page) : 1;
        $offset = ($page - 1) * $per_page;
        // Ensure per_page is positive for array_slice length
        $length = $per_page > 0 ? $per_page : null;
        $paginated_attendees = $length ? array_slice($all_attendees, $offset, $length) : $all_attendees;

        $formatted_data = [
            'attendees' => $paginated_attendees,
            'total_attendees' => $total_attendees,
            'current_page' => $page,
            'total_pages' => $total_pages,
            'per_page' => $per_page
        ];

        wp_send_json_success($formatted_data);
    }

    /**
     * AJAX: Reset today's sales data
     */
    public function reset_todays_sales() {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        if (!class_exists('BRCC_Sales_Tracker')) {
            wp_send_json_error(array('message' => __('Sales tracker class not found.', 'brcc-inventory-tracker')));
            return;
        }

        $sales_tracker = new BRCC_Sales_Tracker();
        $reset_result = $sales_tracker->reset_todays_sales_data();

        if ($reset_result) {
            BRCC_Helpers::log_operation('Admin', 'Reset Sales Data', 'Successfully reset sales data for today: ' . date('Y-m-d'));
            wp_send_json_success(array('message' => __('Sales data for today has been reset.', 'brcc-inventory-tracker')));
        } else {
            BRCC_Helpers::log_operation('Admin', 'Reset Sales Data', 'Failed to reset sales data for today: ' . date('Y-m-d'));
            wp_send_json_error(array('message' => __('Failed to reset sales data. Please try again.', 'brcc-inventory-tracker')));
        }
    }
    
    /**
     * AJAX handler to fetch all attendees for a specific date from both Eventbrite and WooCommerce.
     * This provides a merged list of all attendees without requiring product selection.
     */
    public function fetch_all_attendees_for_date() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brcc-inventory-tracker')]);
            return;
        }
        
        if (!isset($_POST['selected_date']) || !method_exists('BRCC_Helpers', 'parse_date_value') || BRCC_Helpers::parse_date_value($_POST['selected_date']) === null) {
            wp_send_json_error(['message' => __('Invalid or missing date format. Please use YYYY-MM-DD.', 'brcc-inventory-tracker')]);
            return;
        }
        
        $selected_date = sanitize_text_field($_POST['selected_date']);
        BRCC_Helpers::log_debug("[Attendee List AJAX] fetch_all_attendees_for_date: Received request for date: " . $selected_date);

        $all_attendees = [];
        $eventbrite_count = 0;
        $woocommerce_count = 0;
        
        // Get all products mapped to this date
        if (!class_exists('BRCC_Product_Mappings')) {
            wp_send_json_error(['message' => __('Required components not available.', 'brcc-inventory-tracker')]);
            return;
        }
        
        $product_mappings = new BRCC_Product_Mappings();
        $all_mappings = $product_mappings->get_all_mappings();
        $products_for_date = [];
        
        // Find all products mapped to this date
        foreach ($all_mappings as $product_id_key => $mapping_data) {
            if (strpos($product_id_key, '_dates') !== false && is_array($mapping_data)) {
                $base_product_id = (int) str_replace('_dates', '', $product_id_key);
                
                foreach ($mapping_data as $date_key => $date_mapping) {
                    if ($date_key === $selected_date || strpos($date_key, $selected_date . '_') === 0) {
                        $products_for_date[] = [
                            'product_id' => $base_product_id,
                            'mapping' => $date_mapping
                        ];
                        break;
                    }
                }
            }
        }
        BRCC_Helpers::log_debug("[Attendee List AJAX] Products mapped to {$selected_date}: " . count($products_for_date), ['products_for_date' => $products_for_date]);
        
        // If no products found for this date, return empty result
        if (empty($products_for_date)) {
            BRCC_Helpers::log_debug("[Attendee List AJAX] No products found explicitly mapped to {$selected_date}. Returning empty.");
            wp_send_json_success([
                'attendees' => [],
                'total_attendees' => 0,
                'eventbrite_attendees' => 0,
                'woocommerce_attendees' => 0,
                'message' => __('No products found for the selected date.', 'brcc-inventory-tracker')
            ]);
            return;
        }
        
        // Track WooCommerce attendee emails to avoid duplicates
        $woocommerce_attendee_emails = [];
        BRCC_Helpers::log_debug("[Attendee List AJAX] Starting WooCommerce attendee fetch for {$selected_date}.");
        
        // Fetch attendees from WooCommerce FIRST
        $args = [
            'status' => ['wc-processing', 'wc-completed'],
            'limit' => -1,
            'return' => 'ids',
        ];
        $query = new WC_Order_Query($args);
        $order_ids = $query->get_orders();
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            
            $order_date_obj = $order->get_date_created();
            $order_purchase_date = $order_date_obj ? $order_date_obj->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : 'N/A';
            
            foreach ($order->get_items() as $item_id => $item) {
                $item_product_id = $item->get_product_id();
                $item_variation_id = $item->get_variation_id();
                $actual_product_id = $item_variation_id ? $item_variation_id : $item_product_id;
                
                // Check if this product is in our list of products for the date
                $product_found = false;
                foreach ($products_for_date as $product_data) {
                    if ($product_data['product_id'] == $actual_product_id) {
                        $product_found = true;
                        break;
                    }
                }
                
                if ($product_found) {
                    // Check if the item's event date matches the selected date
                    if (method_exists('BRCC_Helpers', 'get_fooevents_date_from_item')) {
                        $item_event_date = BRCC_Helpers::get_fooevents_date_from_item($item);
                        BRCC_Helpers::log_debug(
                            "[Attendee List AJAX] WC Item #{$item_id} (Product #{$actual_product_id}) in Order #{$order_id}: Extracted FooEvents date: " . ($item_event_date ?: 'NULL') . ". Comparing with selected: {$selected_date}"
                        );
                        
                        if ($item_event_date !== null && $item_event_date === $selected_date) {
                            BRCC_Helpers::log_debug("[Attendee List AJAX] WC Item #{$item_id}: Date match! Adding attendee.");
                            $product = wc_get_product($actual_product_id);
                            $product_name = $product ? $product->get_name() : 'Unknown Product';
                            $customer_email = $order->get_billing_email() ?: 'N/A';
                            
                            $all_attendees[] = [
                                'name' => $order->get_formatted_billing_full_name() ?: 'N/A',
                                'email' => $customer_email,
                                'product_name' => $product_name,
                                'product_id' => $actual_product_id,
                                'purchase_date' => $order_purchase_date,
                                'order_ref' => $order->get_order_number(),
                                'status' => $order->get_status(),
                                'source' => 'WooCommerce'
                            ];
                            
                            // Track this email to avoid duplicates from Eventbrite
                            if ($customer_email !== 'N/A') {
                                $woocommerce_attendee_emails[strtolower($customer_email)] = true;
                            }
                            
                            $woocommerce_count++;
                        }
                    }
                }
            }
        }
        
        // NOW fetch attendees from Eventbrite AFTER WooCommerce
        if (class_exists('BRCC_Eventbrite_Integration')) {
            $eventbrite_integration = new BRCC_Eventbrite_Integration();
            
            foreach ($products_for_date as $product_data) {
                $mapping = $product_data['mapping'];
                
                if (!empty($mapping['eventbrite_event_id'])) {
                    $event_id = $mapping['eventbrite_event_id'];
                    
                    try {
                        $eventbrite_data = $eventbrite_integration->get_event_attendees($event_id);
                        
                        if (is_wp_error($eventbrite_data)) {
                            BRCC_Helpers::log_error("Attendee Fetch: Error fetching Eventbrite attendees for Event ID {$event_id}.", $eventbrite_data);
                        } elseif (is_array($eventbrite_data) && isset($eventbrite_data['attendees']) && is_array($eventbrite_data['attendees'])) {
                            // Get product name
                            $product = wc_get_product($product_data['product_id']);
                            $product_name = $product ? $product->get_name() : 'Unknown Product';
                            
                            foreach ($eventbrite_data['attendees'] as $attendee) {
                                $profile = $attendee['profile'] ?? [];
                                $name = isset($profile['name']) ? sanitize_text_field($profile['name']) : 'N/A';
                                $email = isset($profile['email']) ? sanitize_email($profile['email']) : 'N/A';
                                
                                // Skip this attendee if we already have them from WooCommerce
                                if ($email !== 'N/A' && isset($woocommerce_attendee_emails[strtolower($email)])) {
                                    BRCC_Helpers::log_debug("Attendee Fetch: Skipping Eventbrite attendee {$name} ({$email}) as they already exist in WooCommerce records.");
                                    continue;
                                }
                                
                                $purchase_date_raw = isset($attendee['created']) ? $attendee['created'] : null;
                                $purchase_date_formatted = $purchase_date_raw ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($purchase_date_raw)) : 'N/A';
                                $order_ref = isset($attendee['order_id']) ? sanitize_text_field($attendee['order_id']) : 'N/A';
                                $status = isset($attendee['status']) ? sanitize_text_field($attendee['status']) : 'N/A';
                                
                                $all_attendees[] = [
                                    'name' => $name,
                                    'email' => $email,
                                    'product_name' => $product_name,
                                    'product_id' => $product_data['product_id'],
                                    'purchase_date' => $purchase_date_formatted,
                                    'order_ref' => $order_ref,
                                    'status' => $status,
                                    'source' => 'Eventbrite'
                                ];
                                
                                $eventbrite_count++;
                            }
                        }
                    } catch (Exception $e) {
                        BRCC_Helpers::log_error("Attendee Fetch: Exception fetching Eventbrite attendees: " . $e->getMessage());
                    }
                }
            }
        }
        
        // Sort attendees by purchase date (newest first)
        usort($all_attendees, function($a, $b) {
            // Ensure purchase_date exists and is valid before using in strtotime
            $time_a = isset($a['purchase_date']) ? strtotime($a['purchase_date']) : 0;
            $time_b = isset($b['purchase_date']) ? strtotime($b['purchase_date']) : 0;
            return $time_b - $time_a; // Sort descending (newest first)
        });

        BRCC_Helpers::log_debug("[Attendee List AJAX] Final data being sent to JS:", [
            'count_all_attendees' => count($all_attendees),
            'eventbrite_count' => $eventbrite_count,
            'woocommerce_count' => $woocommerce_count,
            'first_few_attendees' => array_slice($all_attendees, 0, 5) // Log first 5 to check structure
        ]);
        
        wp_send_json_success([
            'attendees' => $all_attendees,
            'total_attendees' => count($all_attendees),
            'eventbrite_attendees' => $eventbrite_count,
            'woocommerce_attendees' => $woocommerce_count
        ]);
    }
    
    /**
     * AJAX handler to export all attendees for a date to CSV
     */
    public function export_all_attendees_csv() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'brcc-inventory-tracker'));
        }
        
        if (!isset($_POST['selected_date']) || !method_exists('BRCC_Helpers', 'parse_date_value') || BRCC_Helpers::parse_date_value($_POST['selected_date']) === null) {
            wp_die(__('Invalid or missing date format. Please use YYYY-MM-DD.', 'brcc-inventory-tracker'));
        }
        
        $selected_date = sanitize_text_field($_POST['selected_date']);
        $source_filter = isset($_POST['source_filter']) ? sanitize_text_field($_POST['source_filter']) : 'all';
        $search_term = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';
        
        // Get all attendees for the date
        $all_attendees = $this->get_all_attendees_for_date($selected_date);
        
        // Apply filters
        if ($source_filter !== 'all' || !empty($search_term)) {
            $filtered_attendees = [];
            $search_term = strtolower($search_term);
            
            foreach ($all_attendees as $attendee) {
                // Source filter
                $matches_source = $source_filter === 'all' ||
                    (strtolower($attendee['source']) === strtolower($source_filter));
                
                // Search filter
                $matches_search = empty($search_term) ||
                    (isset($attendee['name']) && stripos($attendee['name'], $search_term) !== false) ||
                    (isset($attendee['email']) && stripos($attendee['email'], $search_term) !== false) ||
                    (isset($attendee['product_name']) && stripos($attendee['product_name'], $search_term) !== false);
                
                if ($matches_source && $matches_search) {
                    $filtered_attendees[] = $attendee;
                }
            }
            
            $all_attendees = $filtered_attendees;
        }
        
        // Generate CSV
        $filename = 'attendees-' . $selected_date . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Add CSV headers
        fputcsv($output, [
            __('Name', 'brcc-inventory-tracker'),
            __('Email', 'brcc-inventory-tracker'),
            __('Product/Event', 'brcc-inventory-tracker'),
            __('Purchase Date', 'brcc-inventory-tracker'),
            __('Order Ref', 'brcc-inventory-tracker'),
            __('Status', 'brcc-inventory-tracker'),
            __('Source', 'brcc-inventory-tracker')
        ]);
        
        // Add attendee data
        foreach ($all_attendees as $attendee) {
            fputcsv($output, [
                $attendee['name'] ?? 'N/A',
                $attendee['email'] ?? 'N/A',
                $attendee['product_name'] ?? 'N/A',
                $attendee['purchase_date'] ?? 'N/A',
                $attendee['order_ref'] ?? 'N/A',
                $attendee['status'] ?? 'N/A',
                $attendee['source'] ?? 'N/A'
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    /**
     * Helper method to get all attendees for a date
     *
     * @param string $selected_date The date to get attendees for
     * @return array Array of attendees
     */
    private function get_all_attendees_for_date($selected_date) {
        $all_attendees = [];
        
        // Get all products mapped to this date
        if (!class_exists('BRCC_Product_Mappings')) {
            return $all_attendees;
        }
        
        $product_mappings = new BRCC_Product_Mappings();
        $all_mappings = $product_mappings->get_all_mappings();
        $products_for_date = [];
        
        // Find all products mapped to this date
        foreach ($all_mappings as $product_id_key => $mapping_data) {
            if (strpos($product_id_key, '_dates') !== false && is_array($mapping_data)) {
                $base_product_id = (int) str_replace('_dates', '', $product_id_key);
                
                foreach ($mapping_data as $date_key => $date_mapping) {
                    if ($date_key === $selected_date || strpos($date_key, $selected_date . '_') === 0) {
                        $products_for_date[] = [
                            'product_id' => $base_product_id,
                            'mapping' => $date_mapping
                        ];
                        break;
                    }
                }
            }
        }
        
        // If no products found for this date, return empty result
        if (empty($products_for_date)) {
            return $all_attendees;
        }
        
        // Fetch attendees from Eventbrite
        if (class_exists('BRCC_Eventbrite_Integration')) {
            $eventbrite_integration = new BRCC_Eventbrite_Integration();
            
            foreach ($products_for_date as $product_data) {
                $mapping = $product_data['mapping'];
                
                if (!empty($mapping['eventbrite_event_id'])) {
                    $event_id = $mapping['eventbrite_event_id'];
                    
                    try {
                        $eventbrite_data = $eventbrite_integration->get_event_attendees($event_id);
                        
                        if (!is_wp_error($eventbrite_data) && is_array($eventbrite_data) && isset($eventbrite_data['attendees']) && is_array($eventbrite_data['attendees'])) {
                            // Get product name
                            $product = wc_get_product($product_data['product_id']);
                            $product_name = $product ? $product->get_name() : 'Unknown Product';
                            
                            foreach ($eventbrite_data['attendees'] as $attendee) {
                                $profile = $attendee['profile'] ?? [];
                                $name = isset($profile['name']) ? sanitize_text_field($profile['name']) : 'N/A';
                                $email = isset($profile['email']) ? sanitize_email($profile['email']) : 'N/A';
                                $purchase_date_raw = isset($attendee['created']) ? $attendee['created'] : null;
                                $purchase_date_formatted = $purchase_date_raw ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($purchase_date_raw)) : 'N/A';
                                $order_ref = isset($attendee['order_id']) ? sanitize_text_field($attendee['order_id']) : 'N/A';
                                $status = isset($attendee['status']) ? sanitize_text_field($attendee['status']) : 'N/A';
                                
                                $all_attendees[] = [
                                    'name' => $name,
                                    'email' => $email,
                                    'product_name' => $product_name,
                                    'product_id' => $product_data['product_id'],
                                    'purchase_date' => $purchase_date_formatted,
                                    'order_ref' => $order_ref,
                                    'status' => $status,
                                    'source' => 'Eventbrite'
                                ];
                            }
                        }
                    } catch (Exception $e) {
                        // Log error but continue
                    }
                }
            }
        }
        
        // Fetch attendees from WooCommerce
        $args = [
            'status' => ['wc-processing', 'wc-completed'],
            'limit' => -1,
            'return' => 'ids',
        ];
        $query = new WC_Order_Query($args);
        $order_ids = $query->get_orders();
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            
            $order_date_obj = $order->get_date_created();
            $order_purchase_date = $order_date_obj ? $order_date_obj->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : 'N/A';
            
            foreach ($order->get_items() as $item_id => $item) {
                $item_product_id = $item->get_product_id();
                $item_variation_id = $item->get_variation_id();
                $actual_product_id = $item_variation_id ? $item_variation_id : $item_product_id;
                
                // Check if this product is in our list of products for the date
                $product_found = false;
                foreach ($products_for_date as $product_data) {
                    if ($product_data['product_id'] == $actual_product_id) {
                        $product_found = true;
                        break;
                    }
                }
                
                if ($product_found) {
                    // Check if the item's event date matches the selected date
                    if (method_exists('BRCC_Helpers', 'get_fooevents_date_from_item')) {
                        $item_event_date = BRCC_Helpers::get_fooevents_date_from_item($item);
                        
                        if ($item_event_date !== null && $item_event_date === $selected_date) {
                            $product = wc_get_product($actual_product_id);
                            $product_name = $product ? $product->get_name() : 'Unknown Product';
                            
                            $all_attendees[] = [
                                'name' => $order->get_formatted_billing_full_name() ?: 'N/A',
                                'email' => $order->get_billing_email() ?: 'N/A',
                                'product_name' => $product_name,
                                'product_id' => $actual_product_id,
                                'purchase_date' => $order_purchase_date,
                                'order_ref' => $order->get_order_number(),
                                'status' => $order->get_status(),
                                'source' => 'WooCommerce'
                            ];
                        }
                    }
                }
            }
        }
        
        // Sort attendees by purchase date (newest first)
        usort($all_attendees, function($a, $b) {
            return strtotime($b['purchase_date']) - strtotime($a['purchase_date']);
        });
        
        return $all_attendees;
    } // End of get_all_attendees_for_date method


    /**
     * AJAX: Get Eventbrite tickets for event
     */
    public function get_eventbrite_tickets_for_event() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'brcc-inventory-tracker'), 403);
            return;
        }

        $event_id = isset($_POST['event_id']) ? sanitize_text_field($_POST['event_id']) : '';

        if (empty($event_id)) {
            wp_send_json_error(__('Event ID is required.', 'brcc-inventory-tracker'), 400);
            return;
        }

        BRCC_Helpers::log_debug('ajax_get_eventbrite_tickets_for_event: Fetching tickets for Event ID: ' . $event_id);

        if (!class_exists('BRCC_Eventbrite_Integration')) {
            wp_send_json_error(__('Eventbrite integration class not found.', 'brcc-inventory-tracker'), 500);
            return;
        }
        
        $eventbrite_integration = new BRCC_Eventbrite_Integration();
        $event_details = $eventbrite_integration->get_eventbrite_event($event_id);

        if (is_wp_error($event_details)) {
            BRCC_Helpers::log_error('ajax_get_eventbrite_tickets_for_event: API error fetching event details.', $event_details);
            wp_send_json_error(sprintf(__('Error fetching event details: %s', 'brcc-inventory-tracker'), $event_details->get_error_message()), 500);
            return;
        }

        $ticket_options = array();
        if (isset($event_details['ticket_classes']) && is_array($event_details['ticket_classes'])) {
            foreach ($event_details['ticket_classes'] as $ticket_class) {
                // Skip free tickets
                if (isset($ticket_class['free']) && $ticket_class['free']) {
                    continue;
                }
                if (isset($ticket_class['id']) && isset($ticket_class['name'])) {
                    $ticket_options[$ticket_class['id']] = sprintf('%s (%s)', $ticket_class['name'], $ticket_class['id']);
                }
            }
            asort($ticket_options); // Sort tickets by name
        } else {
            BRCC_Helpers::log_warning('ajax_get_eventbrite_tickets_for_event: No ticket_classes found in event details for Event ID: ' . $event_id);
        }

        BRCC_Helpers::log_debug('ajax_get_eventbrite_tickets_for_event: Returning ' . count($ticket_options) . ' ticket options.');
        wp_send_json_success($ticket_options);
    }

    /**
     * AJAX: Clear Eventbrite cache
     */
    public function clear_eventbrite_cache() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'brcc-inventory-tracker'), 403);
            return;
        }

        // Get API token to construct the Org ID cache key
        $settings = get_option('brcc_api_settings');
        $api_token = isset($settings['eventbrite_token']) ? $settings['eventbrite_token'] : '';
        $org_id_cache_key = 'brcc_eb_org_id_' . md5($api_token);

        // Construct the default events cache key (assuming default status 'live', include_series true)
        $status = 'live';
        $include_series = true;
        $events_cache_key = 'brcc_eb_org_events_' . md5(serialize([$status, $include_series]));

        // Delete all relevant transients
        $deleted_old = delete_transient('brcc_eventbrite_events_list'); // Keep for legacy reasons? Or remove? Let's keep for now.
        $deleted_org_id = delete_transient($org_id_cache_key);
        $deleted_events = delete_transient($events_cache_key);

        $deleted = $deleted_old || $deleted_org_id || $deleted_events; // Consider it deleted if any were deleted

        if ($deleted) {
            BRCC_Helpers::log_info('ajax_clear_eventbrite_cache: Eventbrite events cache cleared successfully.');
            wp_send_json_success(__('Eventbrite events cache cleared.', 'brcc-inventory-tracker'));
        } else {
            BRCC_Helpers::log_warning('ajax_clear_eventbrite_cache: Attempted to clear cache, but transient was not found (might have expired or never existed).');
            wp_send_json_success(__('Eventbrite events cache was already clear or expired.', 'brcc-inventory-tracker'));
        }
    }

    /**
     * AJAX: Import historical data
     */
    public function import_historical_data() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'brcc-inventory-tracker'), 403);
        }

        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : null;
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : null;
        $sources = isset($_POST['sources']) && is_array($_POST['sources']) ? array_map('sanitize_text_field', $_POST['sources']) : array();

        if (!$start_date || !$end_date || empty($sources)) {
            wp_send_json_error(__('Missing required parameters (start date, end date, or sources).', 'brcc-inventory-tracker'), 400);
        }

        // Validate date format (basic check)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
             wp_send_json_error(__('Invalid date format. Please use YYYY-MM-DD.', 'brcc-inventory-tracker'), 400);
        }

        BRCC_Helpers::log_info("Starting historical import. Range: {$start_date} to {$end_date}. Sources: " . implode(', ', $sources));

        $results = [
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'log' => [],
            'success' => true,
            'message' => __('Import process initiated.', 'brcc-inventory-tracker')
        ];

        // Instantiate necessary classes
        $sales_tracker = new BRCC_Sales_Tracker();
        $product_mappings = new BRCC_Product_Mappings(); // Needed for mapping

        // --- Placeholder for Import Logic ---
        // This section needs significant development to handle pagination, API calls, mapping, and recording.
        // A robust solution would likely use background processing (Action Scheduler).

        $results['log'][] = "Processing sources: " . implode(', ', $sources);

        if (in_array('woocommerce', $sources)) {
            $results['log'][] = "--- Processing WooCommerce ---";
            try {
                $args = array(
                    'limit' => -1, // Get all orders in the range (potential performance issue)
                    'type' => 'shop_order',
                    'status' => array('wc-completed', 'wc-processing'), // Consider which statuses count as sales
                    'date_created' => $start_date . '...' . $end_date,
                    'return' => 'ids',
                );
                $order_ids = wc_get_orders($args);
                $results['log'][] = "Found " . count($order_ids) . " WooCommerce orders in the date range.";

                foreach ($order_ids as $order_id) {
                    $order = wc_get_order($order_id);
                    if (!$order) {
                        $results['log'][] = "Error: Could not retrieve WC Order #{$order_id}. Skipping.";
                        $results['errors']++;
                        continue;
                    }

                    $results['processed']++;
                    $order_date = $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null;
                    $customer_name = $order->get_formatted_billing_full_name();
                    $customer_email = $order->get_billing_email();
                    $currency = $order->get_currency();

                    foreach ($order->get_items() as $item_id => $item) {
                        if (!is_a($item, 'WC_Order_Item_Product')) continue;

                        $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
                        $quantity = $item->get_quantity();
                        $gross_amount = $item->get_total(); // Item total

                        // Extract booking date/time if available (using existing helpers)
                        $booking_date = BRCC_Helpers::get_fooevents_date_from_item($item);
                        $booking_time = BRCC_Helpers::extract_booking_time_from_item($item);
                        
                        $event_details_for_log = [
                            'wc_order_id' => $order_id,
                            'item_id' => $item_id,
                            'booking_date' => $booking_date,
                            'booking_time' => $booking_time,
                            'order_date' => $order_date
                        ];

                        // Record the sale in the tracker
                        $recorded = $sales_tracker->record_sale(
                            $product_id,
                            $quantity,
                            'woocommerce_import', // Source identifier
                            $order_id . '_' . $item_id, // Unique source ID
                            $customer_name,
                            $customer_email,
                            $gross_amount,
                            $currency,
                            $event_details_for_log
                        );

                        if ($recorded) {
                            $results['imported']++;
                            $results['log'][] = "Imported WC Order #{$order_id}, Item #{$item_id} (Product ID: {$product_id}, Qty: {$quantity})";
                        } else {
                            $results['skipped']++;
                            $results['log'][] = "Skipped WC Order #{$order_id}, Item #{$item_id} (Product ID: {$product_id}) - Likely already recorded or error.";
                        }
                    }
                }
                 $results['log'][] = "--- Finished WooCommerce Processing ---";
            } catch (Exception $e) {
                $results['log'][] = "Error during WooCommerce processing: " . $e->getMessage();
                $results['errors']++;
            }
        }

        if (in_array('eventbrite', $sources)) {
             $results['log'][] = "--- Processing Eventbrite ---";
             try {
                 // Ensure Eventbrite integration class is loaded
                 if (!class_exists('BRCC_Eventbrite_Integration')) {
                     // Attempt to include it if not loaded (adjust path if necessary)
                     $integration_path = BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/integrations/class-brcc-eventbrite-integration.php';
                     if (file_exists($integration_path)) {
                         include_once $integration_path;
                     }
                 }

                 if (class_exists('BRCC_Eventbrite_Integration')) {
                     $eventbrite_integration = new BRCC_Eventbrite_Integration();
                     
                     // Check if Eventbrite API token is configured
                     $settings = get_option('brcc_api_settings');
                     if (empty($settings['eventbrite_token'])) {
                          throw new Exception("Eventbrite API token is not configured in settings.");
                     }

                     // TODO: Need a method in BRCC_Eventbrite_Integration like get_orders_by_date_range($start_date, $end_date)
                     // This method would need to handle pagination and date filtering via the Eventbrite API.
                     // Using get_user_orders is inefficient and fetches ALL orders.
                     
                     $results['log'][] = "[Warning] Eventbrite import currently fetches ALL user orders page by page due to lack of specific date filtering method. This may be slow and import unwanted data if not filtered manually below.";
                     
                     $page = 1;
                     $has_more_items = true;
                     while ($has_more_items) {
                         $results['log'][] = "Fetching Eventbrite orders page {$page}...";
                         // Ensure method exists before calling
                         if (!method_exists($eventbrite_integration, 'get_user_orders')) {
                             throw new Exception("Method get_user_orders does not exist in BRCC_Eventbrite_Integration.");
                         }
                         $eb_orders_response = $eventbrite_integration->get_user_orders($page);
                         
                         if (is_wp_error($eb_orders_response)) {
                             $results['log'][] = "Error fetching Eventbrite orders (Page {$page}): " . $eb_orders_response->get_error_message();
                             $results['errors']++;
                             $has_more_items = false; // Stop on error
                             break;
                         }
                         
                         if (empty($eb_orders_response['orders'])) {
                              $results['log'][] = "No more Eventbrite orders found (Page {$page}).";
                              $has_more_items = false;
                              break;
                         }
                         
                         $results['log'][] = "Processing " . count($eb_orders_response['orders']) . " Eventbrite orders (Page {$page})...";

                         foreach ($eb_orders_response['orders'] as $eb_order) {
                             // Basic date check (less efficient than API filtering)
                             $order_created_date = null;
                             if (isset($eb_order['created'])) {
                                 try {
                                     // Ensure timezone consistency - Eventbrite usually returns UTC
                                     $dt = new DateTime($eb_order['created'], new DateTimeZone('UTC'));
                                     // Convert to site's timezone for comparison if needed, or keep UTC
                                     // $dt->setTimezone(wp_timezone());
                                     $order_created_date = $dt->format('Y-m-d');
                                 } catch (Exception $e) {
                                      $results['log'][] = "Warning: Could not parse date for EB Order #{$eb_order['id']}: " . $eb_order['created'];
                                 }
                             }
                             
                             // Skip if date is outside the requested range
                             if (!$order_created_date || $order_created_date < $start_date || $order_created_date > $end_date) {
                                 // $results['log'][] = "Skipping EB Order #{$eb_order['id']} (Date {$order_created_date} outside range {$start_date} - {$end_date})."; // Logged too frequently
                                 continue;
                             }

                             $results['processed']++;
                             
                             // Need full order details including attendees and event info
                             // Ensure fetch_eventbrite_order_details method exists
                             if (!method_exists($eventbrite_integration, 'fetch_eventbrite_order_details')) {
                                 throw new Exception("Method fetch_eventbrite_order_details does not exist in BRCC_Eventbrite_Integration.");
                             }
                             $full_order_details = $eventbrite_integration->fetch_eventbrite_order_details($eb_order['resource_uri']);
                             
                             if (is_wp_error($full_order_details)) {
                                 $results['log'][] = "Error fetching full details for EB Order #{$eb_order['id']}: " . $full_order_details->get_error_message();
                                 $results['errors']++;
                                 continue;
                             }
                             
                             // --- Replicated/Adapted Logic from process_order_for_product ---
                             // Ensure necessary data exists before proceeding
                             if (!isset($full_order_details['event']['id']) || !isset($full_order_details['id'])) {
                                 $results['log'][] = "Skipped EB Order #{$eb_order['id']} - Missing essential data (event ID or order ID) in full details.";
                                 $results['errors']++;
                                 continue;
                             }

                             $event_id = $full_order_details['event']['id'];
                             $start_time_str = $full_order_details['event']['start']['local'] ?? null;
                             $event_date = null;
                             $event_time = null;
                             if ($start_time_str) { try { $dt = new DateTime($start_time_str); $event_date = $dt->format('Y-m-d'); $event_time = $dt->format('H:i'); } catch (Exception $e) {} }

                             // Ensure find_product_id_for_event method exists
                             if (!method_exists($product_mappings, 'find_product_id_for_event')) {
                                 throw new Exception("Method find_product_id_for_event does not exist in BRCC_Product_Mappings.");
                             }
                             $product_id = $product_mappings->find_product_id_for_event(null, $event_date, $event_time, $event_id);

                             if ($product_id) {
                                 $source_order_id = $full_order_details['id'];
                                 $customer_name = $full_order_details['name'] ?? 'N/A';
                                 $customer_email = $full_order_details['email'] ?? 'N/A';
                                 $gross_amount = isset($full_order_details['costs']['gross']['value']) ? ($full_order_details['costs']['gross']['value'] / 100) : 0;
                                 $currency = $full_order_details['costs']['gross']['currency'] ?? 'CAD';
                                 
                                 // Calculate quantity from attendees
                                 $quantity = 0;
                                 if (isset($full_order_details['attendees']) && is_array($full_order_details['attendees'])) {
                                     foreach ($full_order_details['attendees'] as $attendee) {
                                         // Ensure attendee belongs to the correct event and has quantity
                                         if (isset($attendee['event_id']) && $attendee['event_id'] == $event_id && isset($attendee['quantity'])) {
                                             $quantity += (int)$attendee['quantity'];
                                         }
                                     }
                                 }
                                 // If quantity couldn't be determined from attendees, maybe default or skip?
                                 if ($quantity === 0) {
                                      $results['log'][] = "Warning: Could not determine quantity for EB Order #{$source_order_id}. Skipping item or defaulting to 1?";
                                      // Option 1: Skip this order item
                                      // $results['skipped']++; continue;
                                      // Option 2: Default to 1 (use with caution)
                                      $quantity = 1;
                                 }


                                 $event_details_for_log = [ 'event_id' => $event_id, 'event_date' => $event_date, 'event_time' => $event_time, 'order_date' => $order_created_date ];

                                 // Ensure record_sale method exists
                                 if (!method_exists($sales_tracker, 'record_sale')) {
                                     throw new Exception("Method record_sale does not exist in BRCC_Sales_Tracker.");
                                 }
                                 $recorded = $sales_tracker->record_sale( $product_id, $quantity, 'eventbrite_import', $source_order_id, $customer_name, $customer_email, $gross_amount, $currency, $event_details_for_log );

                                 if ($recorded) {
                                     $results['imported']++;
                                     $results['log'][] = "Imported EB Order #{$source_order_id} (Product ID: {$product_id}, Qty: {$quantity})";
                                 } else {
                                     $results['skipped']++;
                                     $results['log'][] = "Skipped EB Order #{$source_order_id} (Product ID: {$product_id}) - Likely already recorded or mapping error.";
                                 }
                             } else {
                                 $results['skipped']++;
                                 $results['log'][] = "Skipped EB Order #{$eb_order['id']} - Could not find mapped WC product for Event ID {$event_id} (Date: {$event_date}, Time: {$event_time}).";
                             }
                             // --- End Replicated Logic ---
                         } // End foreach order loop
                         
                         // Check for pagination
                         if (isset($eb_orders_response['pagination']['has_more_items']) && $eb_orders_response['pagination']['has_more_items']) {
                             $page++;
                             // Optional: Add a small delay to avoid hitting rate limits aggressively
                             // sleep(1);
                         } else {
                             $has_more_items = false;
                         }
                     } // End while loop
                 } else {
                     $results['log'][] = "Error: BRCC_Eventbrite_Integration class not found.";
                     $results['errors']++;
                 }
                  $results['log'][] = "--- Finished Eventbrite Processing ---";
             } catch (Exception $e) {
                 $results['log'][] = "Error during Eventbrite processing: " . $e->getMessage();
                 $results['errors']++;
             }
        }

        if (in_array('square', $sources)) {
             $results['log'][] = "--- Processing Square ---";
             try {
                 // Ensure Square integration class is loaded
                 if (!class_exists('BRCC_Square_Integration')) {
                      $integration_path = BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/integrations/class-brcc-square-integration.php';
                     if (file_exists($integration_path)) {
                         include_once $integration_path;
                     } else {
                          throw new Exception("Square integration file not found at: " . $integration_path);
                     }
                 }

                 if (class_exists('BRCC_Square_Integration')) {
                     $square_integration = new BRCC_Square_Integration();

                     // Check if Square API settings are configured
                     $settings = get_option('brcc_api_settings');
                     if (empty($settings['square_access_token']) || empty($settings['square_location_id'])) {
                         throw new Exception("Square Access Token or Location ID is not configured in settings.");
                     }
                     
                     // Ensure the new method exists
                     if (!method_exists($square_integration, 'get_orders_for_date_range')) {
                         throw new Exception("Method get_orders_for_date_range does not exist in BRCC_Square_Integration class. Please ensure the integration file is updated.");
                     }

                     // Fetch orders using the new method
                     $square_orders = $square_integration->get_orders_for_date_range($start_date, $end_date);

                     if (is_wp_error($square_orders)) {
                         $results['log'][] = "Error fetching Square orders: " . $square_orders->get_error_message();
                         $results['errors']++;
                     } elseif (empty($square_orders)) {
                         $results['log'][] = "No completed Square orders found in the specified date range.";
                     } else {
                         $results['log'][] = "Found " . count($square_orders) . " completed Square orders. Processing...";
                         
                         foreach ($square_orders as $sq_order) {
                             $results['processed']++;
                             $order_id = $sq_order['id'] ?? 'unknown';
                             $order_created_at = $sq_order['created_at'] ?? null; // Keep original timestamp if needed
                             $order_date_local = $order_created_at ? $square_integration->convert_to_local_date($order_created_at) : null; // Use helper for local date
                             
                             // Extract customer info (might be under 'customer_id' needing another lookup, or directly in order)
                             // This part might need refinement based on Square API structure for Orders
                             $customer_name = 'N/A'; // Placeholder
                             $customer_email = 'N/A'; // Placeholder
                             // You might need to fetch customer details using $sq_order['customer_id'] if present

                             if (isset($sq_order['line_items']) && is_array($sq_order['line_items'])) {
                                 foreach ($sq_order['line_items'] as $item) {
                                     $item_name = $item['name'] ?? '';
                                     $catalog_object_id = $item['catalog_object_id'] ?? null;
                                     $variation_id = $item['catalog_object_id'] ?? null; // Assuming variation ID is the catalog object ID for line items
                                     $quantity = isset($item['quantity']) ? intval($item['quantity']) : 0;
                                     $gross_money = $item['total_money'] ?? ['amount' => 0, 'currency' => 'CAD']; // Use total_money per line item
                                     $gross_amount = $gross_money['amount'] / 100; // Amount is in cents
                                     $currency = $gross_money['currency'];

                                     if (!$catalog_object_id || $quantity <= 0) {
                                         $results['log'][] = "Skipping item in Square Order #{$order_id} - Missing catalog ID or zero quantity.";
                                         continue;
                                     }

                                     // Find mapped WC product ID
                                     // Ensure find_product_by_square_id method exists
                                     if (!method_exists($square_integration, 'find_product_by_square_id')) {
                                         throw new Exception("Method find_product_by_square_id does not exist in BRCC_Square_Integration.");
                                     }
                                     $product_id = $square_integration->find_product_by_square_id($catalog_object_id);

                                     if ($product_id) {
                                         // Use order date as event date, extract time from name
                                         $event_date = $order_date_local;
                                         $event_time = BRCC_Helpers::extract_time_from_title($item_name); // Use helper

                                         $event_details_for_log = [
                                             'sq_order_id' => $order_id,
                                             'item_name' => $item_name,
                                             'catalog_object_id' => $catalog_object_id,
                                             'event_date' => $event_date, // Based on order creation
                                             'event_time' => $event_time, // Extracted from name
                                             'order_date' => $order_created_at // Original timestamp
                                         ];

                                         // Ensure record_sale method exists
                                         if (!method_exists($sales_tracker, 'record_sale')) {
                                             throw new Exception("Method record_sale does not exist in BRCC_Sales_Tracker.");
                                         }
                                         $recorded = $sales_tracker->record_sale(
                                             $product_id,
                                             $quantity,
                                             'square_import', // Source identifier
                                             $order_id . '_' . ($item['uid'] ?? $catalog_object_id), // Unique source ID (use line item uid if available)
                                             $customer_name,
                                             $customer_email,
                                             $gross_amount,
                                             $currency,
                                             $event_details_for_log
                                         );

                                         if ($recorded) {
                                             $results['imported']++;
                                             $results['log'][] = "Imported Square Order #{$order_id}, Item: {$item_name} (Product ID: {$product_id}, Qty: {$quantity})";
                                         } else {
                                             $results['skipped']++;
                                             $results['log'][] = "Skipped Square Order #{$order_id}, Item: {$item_name} (Product ID: {$product_id}) - Likely already recorded or error.";
                                         }
                                     } else {
                                         $results['skipped']++;
                                         $results['log'][] = "Skipped Square Order #{$order_id}, Item: {$item_name} (Catalog ID: {$catalog_object_id}) - No WC product mapping found.";
                                     }
                                 } // End foreach line_item
                             } else {
                                  $results['log'][] = "Skipping Square Order #{$order_id} - No line items found.";
                             }
                         } // End foreach square_order
                     }
                 } else {
                      $results['log'][] = "Error: BRCC_Square_Integration class not found.";
                      $results['errors']++;
                 }
                 $results['log'][] = "--- Finished Square Processing ---";
             } catch (Exception $e) {
                 $results['log'][] = "Error during Square processing: " . $e->getMessage();
                 $results['errors']++;
             }
        }

        // --- End Placeholder ---


        $results['message'] = sprintf(
            __('Import attempt finished. Processed: %d, Imported: %d, Skipped: %d, Errors: %d. Check log for details.', 'brcc-inventory-tracker'),
            $results['processed'],
            $results['imported'],
            $results['skipped'],
            $results['errors']
        );
        if ($results['errors'] > 0) {
             $results['success'] = false;
        }

        BRCC_Helpers::log_info("Historical import finished. Results: " . json_encode($results));

        if ($results['success']) {
            wp_send_json_success($results);
        } else {
            // Send success response even with processing errors, as the AJAX call itself succeeded.
            // The 'success' flag within the data indicates the import status.
             wp_send_json_success($results);
            // Alternatively, send error if you want the AJAX .fail() handler to catch it:
            // wp_send_json_error($results, 500);
        }
    } // End of import_historical_data method

    /**
     * AJAX: Refresh dashboard card
     * Used to refresh specific dashboard cards with real-time data
     */
    public function refresh_dashboard_card()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }
        
        // Get card type and date from request
        $card_type = isset($_POST['card_type']) ? sanitize_text_field($_POST['card_type']) : '';
        $selected_date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');
        
        if (empty($card_type)) {
            wp_send_json_error(array('message' => __('Card type is required.', 'brcc-inventory-tracker')));
            return;
        }
        
        // Initialize response data
        $response = array(
            'message' => sprintf(__('%s data refreshed successfully!', 'brcc-inventory-tracker'), ucfirst($card_type)),
            'card_type' => $card_type,
            'date' => $selected_date
        );
        
        // Get sales tracker instance
        $sales_tracker = new BRCC_Sales_Tracker();
        
        // Get fresh data based on card type
        switch ($card_type) {
            case 'sales-by-source':
                // Refresh sales by source data
                $sales_data = $sales_tracker->get_daily_sales($selected_date, $selected_date);
                
                // Calculate sales by source
                $sales_by_source = array();
                foreach ($sales_data as $sale) {
                    $source = isset($sale['source']) ? sanitize_text_field($sale['source']) : 'Unknown';
                    $quantity = isset($sale['quantity']) ? (int)$sale['quantity'] : 0;
                    
                    if (!isset($sales_by_source[$source])) {
                        $sales_by_source[$source] = 0;
                    }
                    $sales_by_source[$source] += $quantity;
                }
                
                $response['data'] = array(
                    'sales_by_source' => $sales_by_source
                );
                $response['message'] = __('Sales by source data refreshed successfully!', 'brcc-inventory-tracker');
                break;
                
            case 'sales-by-hour':
                // Refresh sales by hour data
                $sales_data = $sales_tracker->get_daily_sales($selected_date, $selected_date);
                
                // Calculate sales by hour
                $sales_by_hour = array_fill(0, 24, 0); // Initialize hours 0-23
                foreach ($sales_data as $sale) {
                    $timestamp = isset($sale['timestamp']) ? (int)$sale['timestamp'] : null;
                    $quantity = isset($sale['quantity']) ? (int)$sale['quantity'] : 0;
                    
                    if ($timestamp) {
                        $hour = (int)date('G', $timestamp); // 'G' for 24-hour format without leading zeros
                        if (isset($sales_by_hour[$hour])) {
                            $sales_by_hour[$hour] += $quantity;
                        }
                    }
                }
                
                $response['data'] = array(
                    'sales_by_hour' => $sales_by_hour
                );
                $response['message'] = __('Sales by hour data refreshed successfully!', 'brcc-inventory-tracker');
                break;
                
            case 'sales-by-category':
                // Refresh sales by category data
                $sales_data = $sales_tracker->get_daily_sales($selected_date, $selected_date);
                
                // Calculate sales by category
                $sales_by_category = array();
                foreach ($sales_data as $sale) {
                    $product_id = isset($sale['product_id']) ? (int)$sale['product_id'] : 0;
                    $quantity = isset($sale['quantity']) ? (int)$sale['quantity'] : 0;
                    
                    if ($product_id) {
                        $product = wc_get_product($product_id);
                        if ($product) {
                            $categories = $product->get_category_ids();
                            if (!empty($categories)) {
                                foreach ($categories as $category_id) {
                                    $category = get_term($category_id, 'product_cat');
                                    if ($category && !is_wp_error($category)) {
                                        $category_name = $category->name;
                                        if (!isset($sales_by_category[$category_name])) {
                                            $sales_by_category[$category_name] = 0;
                                        }
                                        $sales_by_category[$category_name] += $quantity;
                                    }
                                }
                            } else {
                                // No category assigned
                                if (!isset($sales_by_category['Uncategorized'])) {
                                    $sales_by_category['Uncategorized'] = 0;
                                }
                                $sales_by_category['Uncategorized'] += $quantity;
                            }
                        }
                    }
                }
                
                $response['data'] = array(
                    'sales_by_category' => $sales_by_category
                );
                $response['message'] = __('Sales by category data refreshed successfully!', 'brcc-inventory-tracker');
                break;
                
            case 'sales-comparison':
                // This is handled by the get_sales_comparison method
                $response['message'] = __('Please use the comparison dropdown to refresh comparison data.', 'brcc-inventory-tracker');
                break;
                
            case 'sales-details':
                // Refresh sales details data
                $sales_data = $sales_tracker->get_daily_sales($selected_date, $selected_date);
                
                $response['data'] = array(
                    'sales_data' => $sales_data
                );
                $response['message'] = __('Sales details refreshed successfully!', 'brcc-inventory-tracker');
                break;
                
            default:
                // Unknown card type
                wp_send_json_error(array('message' => sprintf(__('Unknown card type: %s', 'brcc-inventory-tracker'), $card_type)));
                return;
        }
        
        // Return the response with the refreshed data
        wp_send_json_success($response);
    }

    /**
     * AJAX: Sync inventory for a specific product
     * Used by the dashboard to resolve sync errors
     */
    public function sync_product_inventory()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
            
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }

        // Get product ID from request
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }
        
        // Get product information for better messaging
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : sprintf(__('Product #%d', 'brcc-inventory-tracker'), $product_id);
        
        // Log sync initiation
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Sync Product',
                sprintf(__('Sync triggered for product: %s (ID: %d)', 'brcc-inventory-tracker'),
                    $product_name,
                    $product_id
                )
            );
        } else if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Sync Product',
                sprintf(__('Sync triggered for product: %s (ID: %d) (Live Mode)', 'brcc-inventory-tracker'),
                    $product_name,
                    $product_id
                )
            );
        }
        
        // Get product mappings to check if this product has Eventbrite mapping
        $product_mappings = new BRCC_Product_Mappings();
        $mappings = $product_mappings->get_all_mappings();
        
        $has_eventbrite_mapping = false;
        if (isset($mappings[$product_id]) && !empty($mappings[$product_id]['eventbrite_event_id'])) {
            $has_eventbrite_mapping = true;
        }
        
        if (!$has_eventbrite_mapping) {
            wp_send_json_error(array(
                'message' => sprintf(__('Product "%s" does not have an Eventbrite mapping.', 'brcc-inventory-tracker'), $product_name)
            ));
            return;
        }
        
        // Trigger sync for this specific product
        do_action('brcc_sync_inventory', true, $product_id);
        
        // Update last sync time
        update_option('brcc_last_sync_time', time());
        
        // Return success response
        wp_send_json_success(array(
            'message' => sprintf(__('Inventory synchronized successfully for "%s".', 'brcc-inventory-tracker'), $product_name),
            'product_id' => $product_id,
            'product_name' => $product_name,
            'timestamp' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            'test_mode' => BRCC_Helpers::is_test_mode()
        ));
    }
    
    /**
     * AJAX: Get sales comparison data
     * Used to compare sales data between different periods
/**
     * AJAX: Fix FooEvents Order Item Metadata
     */
    public function handle_fix_fooevents_metadata() {
        error_log('[BRCC DEBUG] handle_fix_fooevents_metadata: AJAX call received. POST data: ' . print_r($_POST, true));
        // Verify nonce
        check_ajax_referer('brcc_fix_fooevents_metadata_nonce', 'nonce');
        error_log('[BRCC DEBUG] handle_fix_fooevents_metadata: Nonce check passed.');

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'brcc-inventory-tracker')], 403);
            return;
        }

        // Get and sanitize parameters
        $days_to_look_back = isset($_POST['days']) ? intval($_POST['days']) : 30;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;

        if ($days_to_look_back <= 0) {
            $days_to_look_back = 30;
        }
        if ($limit <= 0) {
            $limit = 50;
        }

        // Call the helper function
        if (!class_exists('BRCC_Helpers')) {
            error_log('[BRCC DEBUG] handle_fix_fooevents_metadata: BRCC_Helpers class not found.');
            wp_send_json_error(['message' => 'BRCC_Helpers class not found.'], 500);
            return;
        }
        error_log('[BRCC DEBUG] handle_fix_fooevents_metadata: BRCC_Helpers class exists. Proceeding to log attempt.');

        // Log the attempt
        if (BRCC_Helpers::should_log() || BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'Admin AJAX',
                'Fix FooEvents Metadata',
                sprintf(
                    'Attempting to fix metadata for orders in the last %d days, limit %d. Test Mode: %s',
                    $days_to_look_back,
                    $limit,
                    BRCC_Helpers::is_test_mode() ? 'Yes' : 'No'
                )
            );
        }
        error_log('[BRCC DEBUG] handle_fix_fooevents_metadata: Calling BRCC_Helpers::fix_missing_fooevents_metadata with days=' . $days_to_look_back . ', limit=' . $limit);
        $results = BRCC_Helpers::fix_missing_fooevents_metadata($days_to_look_back, $limit);
        error_log('[BRCC DEBUG] handle_fix_fooevents_metadata: Result from helper: ' . print_r($results, true));

        // Send the JSON response
        if (isset($results['error'])) {
            error_log('[BRCC DEBUG] handle_fix_fooevents_metadata: Sending JSON error: ' . print_r($results['error'], true));
            wp_send_json_error(['message' => $results['error'], 'stats' => $results['stats'] ?? null, 'log_messages' => $results['log_messages'] ?? []], 400);
        } else {
            $response_data = [
                'message' => __('Metadata fix process completed.', 'brcc-inventory-tracker'),
                'stats' => $results['stats'] ?? [],
                'log_messages' => $results['log_messages'] ?? []
            ];
            error_log('[BRCC DEBUG] handle_fix_fooevents_metadata: Sending JSON success. Data: ' . print_r($response_data, true));
            wp_send_json_success($response_data);
        }
    }

    public function get_sales_comparison()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            return;
        }
        
        // Get parameters
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');
        $comparison_type = isset($_POST['comparison_type']) ? sanitize_text_field($_POST['comparison_type']) : 'previous_day';
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(array('message' => __('Invalid date format. Please use YYYY-MM-DD.', 'brcc-inventory-tracker')));
            return;
        }
        
        // Calculate comparison date based on comparison type
        $comparison_date = '';
        switch ($comparison_type) {
            case 'previous_day':
                $comparison_date = date('Y-m-d', strtotime($date . ' -1 day'));
                $comparison_label = __('Previous Day', 'brcc-inventory-tracker');
                break;
                
            case 'previous_week':
                $comparison_date = date('Y-m-d', strtotime($date . ' -1 week'));
                $comparison_label = __('Same Day Last Week', 'brcc-inventory-tracker');
                break;
                
            case 'previous_month':
                $comparison_date = date('Y-m-d', strtotime($date . ' -1 month'));
                $comparison_label = __('Same Day Last Month', 'brcc-inventory-tracker');
                break;
                
            default:
                wp_send_json_error(array('message' => __('Invalid comparison type.', 'brcc-inventory-tracker')));
                return;
        }
        
        // Log the comparison request for debugging
        BRCC_Helpers::log_debug("get_sales_comparison: Comparing {$date} with {$comparison_date} ({$comparison_type})");
        
        // Get sales data for both dates
        $sales_tracker = new BRCC_Sales_Tracker();
        $current_sales = $sales_tracker->get_daily_sales($date, $date);
        $comparison_sales = $sales_tracker->get_daily_sales($comparison_date, $comparison_date);
        
        // Prepare data for chart
        $labels = array(__('Total Sales', 'brcc-inventory-tracker'), __('Revenue', 'brcc-inventory-tracker'), __('Unique Products', 'brcc-inventory-tracker'));
        
        // Calculate metrics for current date
        $current_total_sales = 0;
        $current_total_revenue = 0;
        $current_unique_products = array();
        
        foreach ($current_sales as $sale) {
            $current_total_sales += isset($sale['quantity']) ? (int)$sale['quantity'] : 0;
            $current_total_revenue += isset($sale['revenue']) ? (float)$sale['revenue'] : 0;
            if (isset($sale['product_id']) && !empty($sale['product_id'])) {
                $current_unique_products[$sale['product_id']] = true;
            }
        }
        
        // Calculate metrics for comparison date
        $comparison_total_sales = 0;
        $comparison_total_revenue = 0;
        $comparison_unique_products = array();
        
        foreach ($comparison_sales as $sale) {
            $comparison_total_sales += isset($sale['quantity']) ? (int)$sale['quantity'] : 0;
            $comparison_total_revenue += isset($sale['revenue']) ? (float)$sale['revenue'] : 0;
            if (isset($sale['product_id']) && !empty($sale['product_id'])) {
                $comparison_unique_products[$sale['product_id']] = true;
            }
        }
        
        // Prepare data arrays
        $current_data = array(
            $current_total_sales,
            $current_total_revenue,
            count($current_unique_products)
        );
        
        $comparison_data = array(
            $comparison_total_sales,
            $comparison_total_revenue,
            count($comparison_unique_products)
        );
        
        // Format dates for labels
        $current_date_formatted = date_i18n(get_option('date_format'), strtotime($date));
        $comparison_date_formatted = date_i18n(get_option('date_format'), strtotime($comparison_date));
        
        // Log the comparison results for debugging
        BRCC_Helpers::log_debug("get_sales_comparison: Comparison results - Current: " . json_encode($current_data) . ", Comparison: " . json_encode($comparison_data));
        
        // Send response with additional data
        wp_send_json_success(array(
            'labels' => $labels,
            'current_data' => $current_data,
            'comparison_data' => $comparison_data,
            'current_label' => sprintf(__('%s', 'brcc-inventory-tracker'), $current_date_formatted),
            'comparison_label' => sprintf(__('%s (%s)', 'brcc-inventory-tracker'), $comparison_date_formatted, $comparison_label),
            'current_date' => $date,
            'comparison_date' => $comparison_date,
            'has_data' => (!empty($current_sales) || !empty($comparison_sales)),
            'timestamp' => current_time('timestamp')
        ));
    }
} // End of BRCC_Admin_AJAX class
