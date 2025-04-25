<?php

/**
 * BRCC Mappings Class
 * 
 * Provides enhanced date-time mapping functions for Eventbrite integration
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Enhanced_Mappings
{
    /**
     * Constructor - setup hooks
     */
    public function __construct()
    {
        // Register AJAX handlers
        add_action('wp_ajax_brcc_get_product_dates', array($this, 'ajax_get_product_dates_enhanced'));
        add_action('wp_ajax_brcc_save_product_date_mappings', array($this, 'ajax_save_product_date_mappings_enhanced'));
        // REMOVED: add_action('wp_ajax_brcc_get_event_details_from_id', array($this, 'ajax_get_event_details_from_id')); 

        // Enqueue enhanced scripts
        // Remove enhanced scripts
        // add_action('admin_enqueue_scripts', array($this, 'enqueue_enhanced_scripts'), 20);
    }

    /**
     * Enqueue enhanced scripts and styles
     */
    public function enqueue_enhanced_scripts($hook)
    {
        // Only load on plugin pages
        if (strpos($hook, 'brcc-') === false) {
            return;
        }

        // Add enhanced CSS
        wp_enqueue_style(
            'brcc-date-mappings-enhanced',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/date-mappings-enhanced.css',
            array(),
            BRCC_INVENTORY_TRACKER_VERSION
        );

        // Add enhanced JS - use version with timestamp to avoid caching
        wp_enqueue_script(
            'brcc-date-mappings-enhanced',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/js/date-mappings-enhanced.js',
            array('jquery'),
            BRCC_INVENTORY_TRACKER_VERSION . '.' . time(),
            true
        );
    }

    /**
     * Get product date mappings, prioritizing saved data.
     * 
     * @param int $product_id Product ID
     * @param bool $fetch_from_eventbrite (Optional) Whether to fetch suggestions from Eventbrite
     * @return array Response data containing dates and other info.
     */
    public function get_product_dates_enhanced($product_id, $fetch_from_eventbrite = false) // Keep param for potential future use
    {
        // Get the product - needed for context like name if fetching suggestions
        $product = wc_get_product($product_id);
        if (!$product) {
            return array(
                'dates' => array(),
                'message' => __('Product not found.', 'brcc-inventory-tracker')
            );
        }

        // --- Start: Prioritize SAVED Data ---
        $all_mappings = get_option('brcc_product_mappings', array());
        $saved_date_mappings = isset($all_mappings[$product_id . '_dates']) ? $all_mappings[$product_id . '_dates'] : array();
        
        $dates_from_saved = array();

        foreach ($saved_date_mappings as $key => $mapping_data) {
            $date = null;
            $time = null;

            // Extract date and potentially time from the key
            if (strpos($key, '_') !== false) {
                // Key likely contains date and time (e.g., "2025-12-31_14:00")
                list($date_part, $time_part) = explode('_', $key, 2);
                // Basic validation
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_part) && preg_match('/^\d{2}:\d{2}$/', $time_part)) {
                    $date = $date_part;
                    $time = $time_part;
                }
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                 // Key is just the date
                 $date = $key;
            }

            // If we couldn't parse a valid date from the key, skip this saved entry
            if (!$date) {
                continue; 
            }

            // Format date and time for display
            $timestamp = strtotime($date . ($time ? ' ' . $time : ''));
            $formatted_date = $timestamp ? date_i18n(get_option('date_format'), $timestamp) : $date;
            $formatted_time = $time ? date_i18n(get_option('time_format'), $timestamp) : null;

            $dates_from_saved[$key] = array( // Use the original key to preserve structure
                'date' => $date,
                'time' => $time, // Store H:i format if available
                'formatted_date' => $formatted_date,
                'formatted_time' => $formatted_time,
                'eventbrite_id' => isset($mapping_data['eventbrite_id']) ? $mapping_data['eventbrite_id'] : '', // Dropdown ID
                'square_id' => isset($mapping_data['square_id']) ? $mapping_data['square_id'] : '',
                'manual_eventbrite_id' => isset($mapping_data['manual_eventbrite_id']) ? $mapping_data['manual_eventbrite_id'] : '', // Manual ID
                'from_saved' => true // Indicate this came from saved data
            );
        }
        // --- End: Prioritize SAVED Data ---


        // --- Start: Optional Eventbrite Suggestions (if needed in future) ---
        // This section could be re-added later if suggestion fetching is desired.
        // For now, we focus on retrieving what was saved.
        $suggestions = array();
        // $eventbrite_integration = null; // Initialize - Moved down
        // $product_mappings = null; // Initialize - Not needed here

        /* // Example structure if suggestions were re-enabled:
        if ($fetch_from_eventbrite) {
             $eventbrite_integration = new BRCC_Eventbrite_Integration();
             $product_mappings = new BRCC_Product_Mappings();
             // ... [Logic to fetch suggestions based on product name, day, time etc.] ...
             // ... [Logic to merge suggestions with $dates_from_saved, avoiding duplicates] ...
             // Make sure suggestions don't overwrite 'from_saved' data unless explicitly intended.
        }
        */
        // --- End: Optional Eventbrite Suggestions ---


        // --- Final Preparation ---
        // Sort the results, e.g., by date
        uasort($dates_from_saved, function($a, $b) {
            $time_a = strtotime($a['date'] . ($a['time'] ? ' ' . $a['time'] : ' 00:00:00'));
            $time_b = strtotime($b['date'] . ($b['time'] ? ' ' . $b['time'] : ' 00:00:00'));
            return $time_a - $time_b;
        });

        // Get base ID for context if needed (might not be necessary anymore)
        $base_mapping = isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : array();
        $base_id = isset($base_mapping['eventbrite_id']) ? $base_mapping['eventbrite_id'] : '';

        // --- Get available event options for dropdowns ---
        // ** MODIFICATION START: Skip fetching all events to prevent timeout **
        $event_options = array(); 
        BRCC_Helpers::log_info('get_product_dates_enhanced: Skipping full event list fetch for dropdown population.');
        
        // ** Add currently saved IDs to the options so they are selectable **
        foreach($dates_from_saved as $saved_data) {
            $current_eb_id = $saved_data['eventbrite_id'];
            $current_manual_id = $saved_data['manual_eventbrite_id'];
            
            // Add dropdown ID if not already present
            if (!empty($current_eb_id) && !isset($event_options[$current_eb_id])) {
                 // We don't have the name here, just use the ID
                 $event_options[$current_eb_id] = 'Event/Ticket ID: ' . $current_eb_id; 
            }
            // Add manual ID if different and not already present
            if (!empty($current_manual_id) && $current_manual_id !== $current_eb_id && !isset($event_options[$current_manual_id])) {
                 $event_options[$current_manual_id] = 'Event/Ticket ID: ' . $current_manual_id;
            }
        }
        
        /* // ** ORIGINAL CODE - COMMENTED OUT **
        if (!$eventbrite_integration) $eventbrite_integration = new BRCC_Eventbrite_Integration();
        $org_events = $eventbrite_integration->get_organization_events('live,started,ended'); // Fetch broader range
        if (!is_wp_error($org_events)) {
             foreach ($org_events as $event) {
                 if (isset($event['id']) && isset($event['name']['text'])) {
                     // Consider adding date/time to the option text for clarity
                     $option_text = $event['name']['text'];
                     if(isset($event['start']['local'])) {
                         $option_text .= ' (' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($event['start']['local'])) . ')';
                     }
                     // We might need ticket class IDs here instead of event IDs depending on what's saved/used
                     // $event_options[$event['id']] = $option_text; 
                     
                     // Let's assume we need ticket class IDs for the dropdown
                     if (isset($event['ticket_classes']) && is_array($event['ticket_classes'])) {
                         foreach($event['ticket_classes'] as $ticket) {
                             if (isset($ticket['id']) && isset($ticket['name'])) {
                                 // Prevent duplicates, maybe add event name for context
                                 if (!isset($event_options[$ticket['id']])) {
                                      $event_options[$ticket['id']] = $ticket['name'] . ' - ' . $event['name']['text'];
                                 }
                             }
                         }
                     }
                 }
             }
        } else {
            BRCC_Helpers::log_error('get_product_dates_enhanced: Failed to get organization events for dropdown.', $org_events);
        }
        */ // ** END ORIGINAL CODE **
        // ** MODIFICATION END **


        return array(
            'dates' => array_values($dates_from_saved), // Return as a simple array for JS
            'events' => $event_options, // Pass event options for dropdowns (now only contains saved IDs)
            'base_id' => $base_id, // Keep for context if needed elsewhere
            'suggestions' => $suggestions, // Keep if suggestions are added back
            'availableTimes' => $this->get_common_times() // Keep if needed
        );
    }

    /**
     * AJAX handler for fetching product dates with enhanced support
     */
    public function ajax_get_product_dates_enhanced()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        // Check if we should fetch from Eventbrite (parameter might be removed if suggestions aren't used)
        $fetch_from_eventbrite = isset($_POST['fetch_from_eventbrite']) && $_POST['fetch_from_eventbrite'] == 'true';

        // Get enhanced product dates (now prioritizes saved data and skips full event fetch)
        $response = $this->get_product_dates_enhanced($product_id, $fetch_from_eventbrite);

        // Send success even if dates are empty, JS handles the display
        wp_send_json_success($response);
    }

    /**
     * AJAX handler for saving product date mappings with enhanced support
     */
    public function ajax_save_product_date_mappings_enhanced()
    {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        // Get mappings from request
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        // Check if test mode is enabled
        if (method_exists('BRCC_Helpers', 'is_test_mode') && BRCC_Helpers::is_test_mode()) {
            if (method_exists('BRCC_Helpers', 'log_operation')) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Save Date Mappings',
                    sprintf(
                        __('Would save %d date mappings for product ID %s', 'brcc-inventory-tracker'),
                        count($mappings),
                        $product_id
                    )
                );
            }

            wp_send_json_success(array(
                'message' => __('Product date mappings would be saved in Test Mode.', 'brcc-inventory-tracker') . ' ' .
                    __('(No actual changes made)', 'brcc-inventory-tracker')
            ));
            return;
        }

        // Log in live mode if enabled
        if (method_exists('BRCC_Helpers', 'should_log') && BRCC_Helpers::should_log() && method_exists('BRCC_Helpers', 'log_operation')) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Save Date Mappings',
                sprintf(
                    __('Saving %d date mappings for product ID %s (Live Mode)', 'brcc-inventory-tracker'),
                    count($mappings),
                    $product_id
                )
            );
        }

        // Get all existing mappings
        $all_mappings = get_option('brcc_product_mappings', array());

        // Initialize date mappings for this product if needed
        // We will overwrite the specific keys sent, not clear the whole array
        if (!isset($all_mappings[$product_id . '_dates'])) {
             $all_mappings[$product_id . '_dates'] = array();
        }
        // REMOVED: $all_mappings[$product_id . '_dates'] = array(); // Don't clear all, just update sent ones

        // Keep track of keys processed to remove old ones if necessary (optional, depends on desired behavior)
        $processed_keys = array(); 

        // Process each mapping sent from JS
        $successful_mappings = 0;
        foreach ($mappings as $mapping) {
            // Basic validation
            if (!isset($mapping['date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mapping['date'])) {
                continue; // Skip if date is missing or invalid format
            }
            
            $date = sanitize_text_field($mapping['date']);
            // Get time from JS if sent, otherwise null
            $time = isset($mapping['time']) && preg_match('/^\d{2}:\d{2}$/', $mapping['time']) ? sanitize_text_field($mapping['time']) : null; 
            $eventbrite_id = isset($mapping['eventbrite_event_id']) ? sanitize_text_field($mapping['eventbrite_event_id']) : ''; // Dropdown value
            $square_id = isset($mapping['square_id']) ? sanitize_text_field($mapping['square_id']) : ''; // Keep if used
            $manual_eventbrite_id = isset($mapping['manual_eventbrite_id']) ? sanitize_text_field($mapping['manual_eventbrite_id']) : ''; // Manual input value

            // Determine the primary ID to use (prefer manual if provided)
            $primary_eventbrite_id = !empty($manual_eventbrite_id) ? $manual_eventbrite_id : $eventbrite_id;

            // Skip if no date or no ID (neither dropdown nor manual) is provided
            // Also skip if no Square ID is provided, assuming one is needed for a valid mapping row now
            if (empty($date) || (empty($primary_eventbrite_id) && empty($square_id))) {
                 continue;
            }

            // Create a key that includes time if available
            $key = $time ? $date . '_' . $time : $date;
            $processed_keys[$key] = true; // Mark this key as processed

            // Save mapping using the determined key
            $all_mappings[$product_id . '_dates'][$key] = array(
                'eventbrite_id' => $eventbrite_id, // Still save dropdown value if needed elsewhere
                'square_id' => $square_id,
                'manual_eventbrite_id' => $manual_eventbrite_id // Save manual ID
            );

            $successful_mappings++;
            
        }

        // Optional: Remove mappings for keys that were NOT sent in this save request
        // This ensures that removing a row in the UI and saving removes it from storage.
        // If you want saves to be purely additive/update, comment out this loop.
        foreach (array_keys($all_mappings[$product_id . '_dates']) as $existing_key) {
            if (!isset($processed_keys[$existing_key])) {
                unset($all_mappings[$product_id . '_dates'][$existing_key]);
                 BRCC_Helpers::log_debug('ajax_save_product_date_mappings_enhanced: Removing stale mapping key.', ['product_id' => $product_id, 'key' => $existing_key]);
            }
        }


        // Save all mappings
        update_option('brcc_product_mappings', $all_mappings);

        wp_send_json_success(array(
            'message' => sprintf(
                __('Successfully saved %d date mappings for this product.', 'brcc-inventory-tracker'),
                $successful_mappings
            )
        ));
    }

    // REMOVED: AJAX handler to fetch Eventbrite event details (like time) from an ID.
    /*
    public function ajax_get_event_details_from_id() {
        // ... (code removed) ...
    }
    */


    /**
     * Get common time slots for dropdown
     * 
     * @return array Array of time options
     */
    public function get_common_times()
    {
        $times = array();

        // Add common time slots
        for ($hour = 8; $hour <= 23; $hour++) {
            $hour_12 = $hour % 12;
            if ($hour_12 == 0) $hour_12 = 12;
            $ampm = $hour >= 12 ? 'PM' : 'AM';

            // Add full hour
            $time_24h = sprintf('%02d:00', $hour);
            $time_12h = $hour_12 . ':00 ' . $ampm;
            $times[] = array(
                'value' => $time_24h,
                'label' => $time_12h
            );

            // Add half hour
            $time_24h = sprintf('%02d:30', $hour);
            $time_12h = $hour_12 . ':30 ' . $ampm;
            $times[] = array(
                'value' => $time_24h,
                'label' => $time_12h
            );
        }

        return $times;
    }
}

// Initialize the enhanced mappings
new BRCC_Enhanced_Mappings();
