<?php

/**
 * BRCC Product Mappings Class
 * 
 * Manages product mappings for date-based and time-based inventory between WooCommerce and Eventbrite
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Product_Mappings
{
    /**
     * Stores all mappings loaded during the request.
     * @var array|null
     */
    private $all_mappings = null;

    /**
     * Constructor - setup hooks
     */
    public function __construct()
    {
        // Admin AJAX handlers
        add_action('wp_ajax_brcc_save_product_date_mappings', array($this, 'ajax_save_product_date_mappings'));
        add_action('wp_ajax_brcc_get_product_dates', array($this, 'ajax_get_product_dates'));
        add_action('wp_ajax_brcc_test_date_mapping_ajax', array($this, 'ajax_test_product_date_mapping')); // Corrected action name
    }

    /**
     * Loads mappings from the database option if not already loaded.
     */
    private function load_mappings() {
        if ($this->all_mappings === null) {
            // Retrieve WITHOUT default value initially to check if option exists
            $mappings = get_option('brcc_product_mappings');
            // If it returned false (option not found or error), set to empty array
            $this->all_mappings = ($mappings === false) ? array() : $mappings;
            // Log only if debugging is intended
            // BRCC_Helpers::log_debug('load_mappings: Loaded mappings from option.', $this->all_mappings);
        }
    }

    /**
     * Get product mappings including Square support
     *
     * @param int $product_id Product ID
     * @param string $date Optional event date in Y-m-d format
     * @param string $time Optional event time in H:i format
     * @return array Product mappings
     */
    public function get_product_mappings($product_id, $date = null, $time = null)
    {
        $this->load_mappings(); // Ensure mappings are loaded
        $all_mappings = $this->all_mappings;
        
        // For debugging
        error_log("DEBUG: Getting mappings for product: {$product_id}, date: {$date}, time: {$time}");
        
        // Check for date+time specific mapping first (exact match)
        $date_time_key = $date && $time ? $date . '_' . $time : null;
        if ($date_time_key && isset($all_mappings[$product_id . '_dates'][$date_time_key])) {
            $mapping = $all_mappings[$product_id . '_dates'][$date_time_key];
            
            // Log what we found
            error_log("DEBUG: Found exact date+time mapping for {$date_time_key}: " . print_r($mapping, true));
            
            // Ensure ticket_class_id is properly set in the returned data, prioritizing manual
            // Prioritize manual_eventbrite_id for ticket_class_id
            if (!isset($mapping['ticket_class_id']) || empty($mapping['ticket_class_id'])) {
                $mapping['ticket_class_id'] = isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : '';
            }
            // Ensure manual_eventbrite_id is also set if missing but ticket_class_id exists
             if (!isset($mapping['manual_eventbrite_id']) || empty($mapping['manual_eventbrite_id'])) {
                $mapping['manual_eventbrite_id'] = isset($mapping['ticket_class_id']) ? $mapping['ticket_class_id'] : '';
            }
            
            return $mapping;
        }

        // Check for date+time specific mapping using time buffer if exact match failed
        if ($time && isset($all_mappings[$product_id . '_dates'])) {
            $date_mappings = $all_mappings[$product_id . '_dates'];
            foreach ($date_mappings as $key => $mapping_data) {
                // Check if the key contains the correct date and a time component
                if (strpos($key, $date . '_') === 0) {
                    $stored_time = substr($key, strlen($date . '_'));
                    // Use is_time_close for comparison
                    if (BRCC_Helpers::is_time_close($time, $stored_time)) {
                        error_log("DEBUG: Found close date+time mapping for {$key} (requested {$time}): " . print_r($mapping_data, true));
                        // Ensure ticket_class_id is properly set
                        // Prioritize manual_eventbrite_id for ticket_class_id
                        if (!isset($mapping_data['ticket_class_id']) || empty($mapping_data['ticket_class_id'])) {
                            $mapping_data['ticket_class_id'] = isset($mapping_data['manual_eventbrite_id']) ? $mapping_data['manual_eventbrite_id'] : '';
                        }
                        // Ensure manual_eventbrite_id is also set
                        if (!isset($mapping_data['manual_eventbrite_id']) || empty($mapping_data['manual_eventbrite_id'])) {
                            $mapping_data['manual_eventbrite_id'] = isset($mapping_data['ticket_class_id']) ? $mapping_data['ticket_class_id'] : '';
                        }
                        return $mapping_data; // Return the first close match
                    }
                }
            }
        }
        
        // Then check for date-only mapping
        if ($date && isset($all_mappings[$product_id . '_dates'][$date])) {
            $mapping = $all_mappings[$product_id . '_dates'][$date];
            
            // Log what we found
            error_log("DEBUG: Found date-only mapping for {$date}: " . print_r($mapping, true));
            
            // Ensure ticket_class_id is properly set in the returned data, prioritizing manual
            // Prioritize manual_eventbrite_id for ticket_class_id
            if (!isset($mapping['ticket_class_id']) || empty($mapping['ticket_class_id'])) {
                $mapping['ticket_class_id'] = isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : '';
            }
             // Ensure manual_eventbrite_id is also set
            if (!isset($mapping['manual_eventbrite_id']) || empty($mapping['manual_eventbrite_id'])) {
                $mapping['manual_eventbrite_id'] = isset($mapping['ticket_class_id']) ? $mapping['ticket_class_id'] : '';
            }
            
            return $mapping;
        }
        
        // Fall back to default product mapping (not date specific)
        $default_mapping = isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : array();

        // Ensure default mapping has expected keys, prioritizing manual_eventbrite_id
        // Prioritize manual_eventbrite_id for ticket_class_id
        $default_mapping['ticket_class_id'] = isset($default_mapping['ticket_class_id']) && !empty($default_mapping['ticket_class_id'])
            ? $default_mapping['ticket_class_id']
            : (isset($default_mapping['manual_eventbrite_id']) ? $default_mapping['manual_eventbrite_id'] : '');

        // Ensure manual_eventbrite_id is set, falling back to ticket_class_id if necessary
        $default_mapping['manual_eventbrite_id'] = isset($default_mapping['manual_eventbrite_id']) && !empty($default_mapping['manual_eventbrite_id'])
            ? $default_mapping['manual_eventbrite_id']
            : (isset($default_mapping['ticket_class_id']) ? $default_mapping['ticket_class_id'] : '');

        $default_mapping['square_id'] = isset($default_mapping['square_id']) ? $default_mapping['square_id'] : '';
        // $default_mapping['eventbrite_id'] = isset($default_mapping['eventbrite_id']) ? $default_mapping['eventbrite_id'] : $default_mapping['ticket_class_id']; // REMOVED: Backward compat for eventbrite_id

        // Log fallback
        error_log("DEBUG: Using default mapping for product {$product_id}: " . print_r($default_mapping, true));
        
        return $default_mapping;
    }

    /**
     * Get all product mappings stored in the option.
     *
     * @return array All stored product mappings.
     */
    public function get_all_mappings() {
        $this->load_mappings(); // Ensure mappings are loaded
        // BRCC_Helpers::log_debug('get_all_mappings: Returning loaded mappings.', $this->all_mappings); // Optional debug
        return $this->all_mappings;
    }

    /**
     * Find the Eventbrite Event ID associated with a given Eventbrite Ticket Class ID.
     * Searches through all mappings to find an event ID that contains this ticket.
     *
     * @param string $ticket_class_id The Eventbrite Ticket Class ID to search for.
     * @return string|null The Eventbrite Event ID if found, or null if not found.
     */
    public function find_event_id_for_ticket_id($ticket_class_id) {
        BRCC_Helpers::log_debug("find_event_id_for_ticket_id: Searching for Event ID for Ticket ID: {$ticket_class_id}"); // Restored debug log
        
        if (empty($ticket_class_id)) {
            BRCC_Helpers::log_debug("find_event_id_for_ticket_id: Ticket ID empty, returning null."); // Restored debug log
            return null;
        }
        
        $this->load_mappings(); // Ensure mappings are loaded
        $all_mappings = $this->all_mappings;
        
        // First, check if any mapping contains the explicit eventbrite_event_id
        foreach ($all_mappings as $product_id_key => $mapping_data) {
            // Skip date collections
            if (strpos($product_id_key, '_dates') !== false) {
                continue;
            }
            
            // Check if this is a base product mapping with both eventbrite_id and eventbrite_event_id
            // Base mapping check (prioritize manual_eventbrite_id)
            if (is_numeric($product_id_key) && isset($mapping_data['eventbrite_event_id']) && !empty($mapping_data['eventbrite_event_id'])) {
                // Check only manual_eventbrite_id
                if (isset($mapping_data['manual_eventbrite_id']) && $mapping_data['manual_eventbrite_id'] === $ticket_class_id)
                {
                    BRCC_Helpers::log_info("Found Event ID {$mapping_data['eventbrite_event_id']} for Ticket ID {$ticket_class_id} in base product mapping");
                    return $mapping_data['eventbrite_event_id'];
                }
            }
        }
        
        // Next, check date-based mappings
        foreach ($all_mappings as $product_id_key => $mapping_data) {
            if (strpos($product_id_key, '_dates') !== false) {
                // This is a date collection
                foreach ($mapping_data as $date_key => $date_mapping) {
                    // Date-specific mapping check (prioritize manual_eventbrite_id)
                    if (isset($date_mapping['eventbrite_event_id']) && !empty($date_mapping['eventbrite_event_id'])) {
                        // Check only manual_eventbrite_id
                        if (isset($date_mapping['manual_eventbrite_id']) && $date_mapping['manual_eventbrite_id'] === $ticket_class_id)
                        {
                            BRCC_Helpers::log_info("Found Event ID {$date_mapping['eventbrite_event_id']} for Ticket ID {$ticket_class_id} in date-specific mapping");
                            return $date_mapping['eventbrite_event_id'];
                        }
                    }
                }
            }
        }
        
        // Removed redundant loop

        // --- API Fallback Removed ---
        // The Eventbrite API does not provide a reliable way to get an Event ID
        // directly from only a Ticket Class ID without searching all events.
        // This function now ONLY returns an Event ID if it was explicitly stored
        // alongside the Ticket ID in the mapping data (which currently it is not).

        BRCC_Helpers::log_warning("find_event_id_for_ticket_id: Could not determine Event ID for Ticket ID {$ticket_class_id} from mapping data.");
        return null; // Return null if not found in mappings or via API
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
     * Find the WooCommerce Product ID associated with a given Eventbrite Ticket Class ID.
     * Prioritizes date/time specific mappings if date/time are provided.
     *
     * @param string $ticket_class_id The Eventbrite Ticket Class ID.
     * @param string|null $date Optional event date (Y-m-d) for context.
     * @param string|null $time Optional event time (H:i) for context.
     * @return int|null The WooCommerce Product ID, or null if not found.
     */
    public function find_product_id_for_ticket_id($ticket_class_id, $date = null, $time = null) {
        BRCC_Helpers::log_debug("find_product_id_for_ticket_id: Searching for Ticket ID: {$ticket_class_id}, Date: {$date}, Time: {$time}"); // Restored debug log
        
        if (empty($ticket_class_id)) {
            BRCC_Helpers::log_debug("find_product_id_for_ticket_id: Ticket ID empty, returning null."); // Restored debug log
            return null;
        }
    
        $this->load_mappings(); // Ensure mappings are loaded
        $all_mappings = $this->all_mappings;
        BRCC_Helpers::log_debug("find_product_id_for_ticket_id: Retrieved all mappings."); // Restored debug log
        
        // FIRST: Try to find a date/time specific mapping if date is provided
        if ($date) {
            foreach ($all_mappings as $product_id_key => $mapping_data) {
                // Only check keys that end with '_dates'
                if (strpos($product_id_key, '_dates') !== false) {
                    $base_product_id = (int) str_replace('_dates', '', $product_id_key);
                    
                    // Check date+time specific mappings
                    if ($time) {
                        $time_key = $date . '_' . $time;
                        // Date+Time Exact Check (prioritize manual_eventbrite_id)
                        if (isset($mapping_data[$time_key])) {
                            $specific_mapping = $mapping_data[$time_key];
                            // Check only manual_eventbrite_id
                            if (isset($specific_mapping['manual_eventbrite_id']) && $specific_mapping['manual_eventbrite_id'] == $ticket_class_id)
                            {
                                BRCC_Helpers::log_info("Found exact date+time match: Product ID {$base_product_id} for Ticket ID {$ticket_class_id}");
                                return $base_product_id;
                            }
                        }
                        
                        // Try with time buffer (for slight time differences)
                        foreach ($mapping_data as $date_time_key => $specific_mapping) {
                            if (strpos($date_time_key, $date . '_') === 0) {  // Key starts with the date
                                $stored_time = substr($date_time_key, strlen($date) + 1);  // Extract time part
                                // Date+Time Buffer Check (prioritize manual_eventbrite_id)
                                if (BRCC_Helpers::is_time_close($time, $stored_time)) {
                                    // Check only manual_eventbrite_id
                                    if (isset($specific_mapping['manual_eventbrite_id']) && $specific_mapping['manual_eventbrite_id'] == $ticket_class_id)
                                    {
                                        BRCC_Helpers::log_info("Found date+time (buffer) match: Product ID {$base_product_id} for Ticket ID {$ticket_class_id}");
                                        return $base_product_id;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Check date-only mapping
                    // Date Only Check (prioritize manual_eventbrite_id)
                    if (isset($mapping_data[$date])) {
                        $specific_mapping = $mapping_data[$date];
                        // Check only manual_eventbrite_id
                        if (isset($specific_mapping['manual_eventbrite_id']) && $specific_mapping['manual_eventbrite_id'] == $ticket_class_id)
                        {
                            BRCC_Helpers::log_info("Found date-only match: Product ID {$base_product_id} for Ticket ID {$ticket_class_id}");
                            return $base_product_id;
                        }
                    }
                }
            }
        }
        
        // SECOND: Try to find a regular product mapping (no date specificity)
        foreach ($all_mappings as $product_id_key => $mapping_data) {
            // Only check keys that are numeric (regular product IDs)
            if (is_numeric($product_id_key)) {
                $product_id = (int) $product_id_key;
                // Base Mapping Check (prioritize manual_eventbrite_id)
                // Check only manual_eventbrite_id
                if (isset($mapping_data['manual_eventbrite_id']) && $mapping_data['manual_eventbrite_id'] == $ticket_class_id)
                {
                    BRCC_Helpers::log_info("Found default mapping match: Product ID {$product_id} for Ticket ID {$ticket_class_id}");
                    return $product_id;
                }
            }
        }
    
        BRCC_Helpers::log_warning("No mapping found for Ticket ID: {$ticket_class_id}, Date: {$date}, Time: {$time}");
        return null;
    }

    /**
     * Save product mapping with Square support
     * 
     * @param int $product_id Product ID
     * @param array $mapping Mapping data (manual_eventbrite_id, square_id, eventbrite_event_id)
     * @param string $date Optional event date in Y-m-d format
     * @param string $time Optional event time in H:i format
     * @return boolean Success or failure
     */
    public function save_product_mapping($product_id, $mapping, $date = null, $time = null)
    {
        $this->load_mappings(); // Ensure mappings are loaded
        $all_mappings = $this->all_mappings;

        // Ensure Square ID field exists
        if (!isset($mapping['square_id'])) {
            $mapping['square_id'] = '';
        }

        if (!$date) {
            // Save default mapping (no date)
            // This function seems deprecated or for simple mapping? Standardize keys anyway.
            $all_mappings[$product_id] = array(
                'manual_eventbrite_id' => sanitize_text_field(isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : ''),
                'eventbrite_event_id' => sanitize_text_field(isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : ''),
                'square_id' => sanitize_text_field(isset($mapping['square_id']) ? $mapping['square_id'] : '')
            );
        } else {
            // Save date-specific mapping
            if (!isset($all_mappings[$product_id . '_dates'])) {
                $all_mappings[$product_id . '_dates'] = array();
            }

            // Use date+time as key if time is provided
            $key = $time ? $date . '_' . $time : $date;

            // Standardize keys for date-specific mapping, prioritizing manual_eventbrite_id
            $manual_id = sanitize_text_field(isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : '');
            $all_mappings[$product_id . '_dates'][$key] = array(
                'eventbrite_event_id' => sanitize_text_field(isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : ''), // Event ID
                'ticket_class_id' => $manual_id, // Use manual_eventbrite_id value
                'manual_eventbrite_id' => $manual_id, // Ensure manual_eventbrite_id is saved
                'eventbrite_id' => $manual_id, // Keep backward compatibility using manual_eventbrite_id value
                'square_id' => sanitize_text_field(isset($mapping['square_id']) ? $mapping['square_id'] : '')
            );
        }

        $updated = update_option('brcc_product_mappings', $all_mappings);
        if ($updated) {
            // Update the internal cache as well
            $this->all_mappings = $all_mappings;
        }
        return $updated;
    }
    
    /**
     * Get product event dates with time
     * 
     * @param int $product_id Product ID
     * @param bool $intelligent_date_detection
     * @return array Event dates, times and inventory levels
     */
    public function get_product_dates($product_id, $intelligent_date_detection = true)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return array();
        }

        // Initialize dates array
        $dates = array();

        // 1. Try to extract FooEvents dates first
        // Note: $this->get_fooevents_dates() needs to be correctly implemented to fetch FooEvents Bookings data
        $fooevents_dates = $this->get_fooevents_dates($product);
        if (!empty($fooevents_dates)) {
            // Assuming get_fooevents_dates returns data in the expected format:
            // array('date' => 'Y-m-d', 'formatted_date' => '...', 'time' => 'H:i', 'formatted_time' => '...', 'inventory' => ...)
            return $fooevents_dates; // Return immediately if FooEvents dates are found
        }

        // 2. Get booking slots from product meta (WC Bookings, generic)
        $booking_slots = $this->get_product_booking_slots($product);
        if (!empty($booking_slots)) {
            $wc_booking_dates = array(); // Use a separate array for clarity
            foreach ($booking_slots as $slot) {
                $wc_booking_dates[] = array(
                    'date' => $slot['date'],
                    'formatted_date' => date_i18n(get_option('date_format'), strtotime($slot['date'])),
                    'time' => isset($slot['time']) ? $slot['time'] : '',
                    'formatted_time' => isset($slot['time']) && !empty($slot['time']) ?
                        date('g:i A', strtotime("1970-01-01 " . $slot['time'])) : '',
                    'inventory' => isset($slot['inventory']) ? $slot['inventory'] : null
                );
            }
            return $wc_booking_dates; // Return if we found WC Bookings/generic slots here
        }

        // Title parsing and Eventbrite day matching removed for simplicity and reliability.
        // Rely on FooEvents, WC Bookings, or explicit mappings.

        // 5. Fallback: If NO dates were found by any method, create a set of upcoming dates
        // We use the $dates variable initialized at the start. If it's still empty here, apply fallback.
        if (empty($dates)) {
            $fallback_dates = array(); // Use a separate array for fallback
            // Generate the next 7 days as a fallback
            $current_date = new DateTime();
            for ($i = 1; $i <= 7; $i++) {
                $date = $current_date->modify('+1 day')->format('Y-m-d');
                $fallback_dates[] = array(
                    'date' => $date,
                    'formatted_date' => date_i18n(get_option('date_format'), strtotime($date)),
                    'time' => '',
                    'formatted_time' => '',
                    'inventory' => null,  // No inventory data
                    'is_fallback' => true
                );
            }
            return $fallback_dates; // Return fallback dates
        }

        // If somehow dates were populated but not returned earlier (shouldn't happen with current logic), return them.
        // Otherwise, this will return an empty array if no dates were found and fallback wasn't triggered (also unlikely).
        return $dates;
    }

    /**
     * Parse time value to H:i format
     *
     * @param mixed $value Time value to parse
     * @return string|null H:i formatted time or null if parsing fails
     */
    private function parse_time_value($value)
    {
        if (empty($value)) {
            return null;
        }

        // If already in H:i format
        if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            return $value;
        }

        // Common formats: "8:00 PM", "8 PM", "20:00"
        if (preg_match('/(\d{1,2})[:.]?(\d{2})?\s*(am|pm)?/i', $value, $matches)) {
            $hour = intval($matches[1]);
            $minute = isset($matches[2]) && !empty($matches[2]) ? intval($matches[2]) : 0;

            // Adjust for AM/PM if present
            if (isset($matches[3])) {
                $ampm = strtolower($matches[3]);
                if ($ampm === 'pm' && $hour < 12) {
                    $hour += 12;
                } elseif ($ampm === 'am' && $hour === 12) {
                    $hour = 0;
                }
            }

            return sprintf('%02d:%02d', $hour, $minute);
        }

        // Try strtotime as last resort
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('H:i', $timestamp);
        }

        return null;
    }

    /**
     * Get product event dates from Eventbrite with enhanced time support
     * 
     * @param int $product_id Product ID
     * @param string $manual_eventbrite_id Eventbrite ticket class ID
     * @return array Event dates and inventory levels from Eventbrite
     */
    public function get_product_dates_from_eventbrite($product_id, $manual_eventbrite_id)
    {
        $dates = array();

        // Initialize Eventbrite integration
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            return $dates;
        }

        $eventbrite = new BRCC_Eventbrite_Integration();

        // Test connection first
        $connection_test = $eventbrite->test_ticket_connection($eventbrite_id);

        if (is_wp_error($connection_test)) {
            // Add error information to the dates array
            $dates[] = array(
                'date' => current_time('Y-m-d'),
                'formatted_date' => date_i18n(get_option('date_format')),
                'time' => '',
                'formatted_time' => '',
                'inventory' => null,
                'error' => $connection_test->get_error_message(),
                'eventbrite_connection_failed' => true
            );
            return $dates;
        }

        // If connection succeeded, add this date
        if (isset($connection_test['event_date']) && !empty($connection_test['event_date'])) {
            $dates[] = array(
                'date' => $connection_test['event_date'],
                'formatted_date' => date_i18n(get_option('date_format'), strtotime($connection_test['event_date'])),
                'time' => $connection_test['event_time'],
                'formatted_time' => !empty($connection_test['event_time']) ?
                    date('g:i A', strtotime("1970-01-01 " . $connection_test['event_time'])) : '',
                'inventory' => $connection_test['available'],
                'manual_eventbrite_id' => $manual_eventbrite_id, // Return the ID used for the query
                'eventbrite_event_id' => $connection_test['event_id'],
                'eventbrite_name' => $connection_test['event_name'],
                'eventbrite_venue' => $connection_test['venue_name'],
                'eventbrite_time' => $connection_test['event_time'],
                'capacity' => $connection_test['capacity'],
                'sold' => $connection_test['sold'],
                'available' => $connection_test['available'],
                'from_eventbrite' => true,
                'eventbrite_connection_successful' => true
            );
        }

        // Get event ID from the connection test
        $event_id = isset($connection_test['event_id']) ? $connection_test['event_id'] : '';

        // Removed logic that attempted to find other related Eventbrite events
        // based on day name or title similarity, as it was unreliable.
        // This function now only returns data for the specific ticket ID tested.

        return $dates;
    }

    /**
     * Suggest Eventbrite ID based on product name, date and time
     * 
     * @param int $product_id Product ID
     * @param string $date Date in Y-m-d format
     * @param string $time Time in H:i format
     * @return string Suggested Eventbrite ID or empty string
     * Suggests potential Eventbrite Ticket Class IDs based on product, date, and time.
     *
     * @param int $product_id Product ID
     * @param string $date Date string
     * @param string|null $time Time string or null
     * @return array Suggestions
     */
    public function suggest_eventbrite_ticket_class_id($product_id, $date, $time = null)
    {
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            return '';
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        $eventbrite = new BRCC_Eventbrite_Integration();
        // Assuming suggest_eventbrite_ids_for_product suggests ticket class IDs
        $suggestions = $eventbrite->suggest_eventbrite_ids_for_product($product, $date, $time);

        // Return the top suggestion's ticket_id
        if (!empty($suggestions) && isset($suggestions[0]['ticket_id'])) {
            return $suggestions[0]['ticket_id'];
        }

        return '';
    }

    /**
     * Get dates specifically from FooEvents
     * 
     * @param WC_Product $product Product object
     * @return array Array of dates with inventory
     */
    private function get_fooevents_dates($product)
    {
        $dates = array();
        $product_id = $product->get_id();

        // Check if FooEvents is active
        if (!function_exists('is_plugin_active') || !is_plugin_active('fooevents/fooevents.php')) {
            return $dates;
        }

        // --- START: Check for FooEvents Bookings Serialized Options ---
        // Check specifically for FooEvents Bookings options (serialized JSON)
        $serialized_options = get_post_meta($product_id, 'fooevents_bookings_options_serialized', true);

        if (!empty($serialized_options)) {
            $booking_options = json_decode($serialized_options, true); // Decode JSON string into an associative array

            if (is_array($booking_options)) {
                foreach ($booking_options as $session_id => $session_data) {
                    // Extract time for this session
                    $session_time_string = null;
                    if (isset($session_data['add_time']) && $session_data['add_time'] === 'enabled' && isset($session_data['hour']) && isset($session_data['minute'])) {
                        $hour = intval($session_data['hour']);
                        $minute = intval($session_data['minute']);
                        $period = isset($session_data['period']) ? strtolower($session_data['period']) : '';

                        if ($period === 'p.m.' && $hour < 12) {
                            $hour += 12;
                        } elseif ($period === 'a.m.' && $hour === 12) { // Handle 12 AM
                            $hour = 0;
                        }
                        // Ensure hour is within valid range after adjustment
                        $hour = $hour % 24;
                        $session_time_string = sprintf('%02d:%02d', $hour, $minute);
                    }

                    // Structure 1: Nested 'add_date' array (seen in product 4061 log)
                    if (isset($session_data['add_date']) && is_array($session_data['add_date'])) {
                        foreach ($session_data['add_date'] as $slot_id => $slot_data) {
                            if (isset($slot_data['date'])) {
                                $date_string = BRCC_Helpers::parse_date_value($slot_data['date']); // Use existing date parser
                                if ($date_string) {
                                    $formatted_time = $session_time_string ? date('g:i A', strtotime("1970-01-01 " . $session_time_string)) : '';
                                    $stock = isset($slot_data['stock']) ? intval($slot_data['stock']) : $product->get_stock_quantity();

                                    $dates[] = array(
                                        'date' => $date_string,
                                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                                        'time' => $session_time_string, // Use the session's time
                                        'formatted_time' => $formatted_time,
                                        'inventory' => $stock,
                                        'source' => 'fooevents_bookings_serialized_s1' // Identify the source structure
                                    );
                                }
                            }
                        }
                    }
                    // Structure 2: Flat keys like '*_add_date' (seen in product 11192 log)
                    else {
                        foreach ($session_data as $key => $value) {
                            if (strpos($key, '_add_date') !== false && !empty($value)) { // Check key and ensure value is not empty
                                $date_string = BRCC_Helpers::parse_date_value($value);
                                if ($date_string) {
                                    $stock_key = str_replace('_add_date', '_stock', $key);
                                    $stock = isset($session_data[$stock_key]) ? intval($session_data[$stock_key]) : $product->get_stock_quantity();
                                    $formatted_time = $session_time_string ? date('g:i A', strtotime("1970-01-01 " . $session_time_string)) : '';

                                    $dates[] = array(
                                        'date' => $date_string,
                                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                                        'time' => $session_time_string,
                                        'formatted_time' => $formatted_time,
                                        'inventory' => $stock,
                                        'source' => 'fooevents_bookings_serialized_s2' // Identify the source structure
                                    );
                                }
                            }
                        }
                    }
                }

                // If we found dates from the serialized options, return them
                if (!empty($dates)) {
                    // Sort dates chronologically before returning
                    usort($dates, function ($a, $b) {
                        $time_a = $a['time'] ? strtotime($a['date'] . ' ' . $a['time']) : strtotime($a['date']);
                        $time_b = $b['time'] ? strtotime($b['date'] . ' ' . $b['time']) : strtotime($b['date']);
                        return $time_a <=> $time_b;
                    });
                    return $dates;
                }
            }
        }
        // --- END: Check for FooEvents Bookings Serialized Options ---

        // If no serialized booking options found, proceed with original FooEvents date checks

        // FooEvents stores event dates differently based on type of event
        $event_type = get_post_meta($product_id, 'fooevents_event_type', true);

        // Single date event
        $event_date = get_post_meta($product_id, 'fooevents_event_date', true);
        if (!empty($event_date)) {
            $date_string = BRCC_Helpers::parse_date_value($event_date);
            if ($date_string) {
                $stock = $product->get_stock_quantity();
                // Try to get time
                $event_time = get_post_meta($product_id, 'fooevents_event_time', true);
                $time_string = $this->parse_time_value($event_time);

                $dates[] = array(
                    'date' => $date_string,
                    'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                    'time' => $time_string,
                    'formatted_time' => $time_string ? date('g:i A', strtotime("1970-01-01 " . $time_string)) : '',
                    'inventory' => $stock,
                    'source' => 'fooevents_single'
                );
            }
        }

        // Multi-day event
        $event_dates = get_post_meta($product_id, 'fooevents_event_dates', true);
        if (!empty($event_dates) && is_array($event_dates)) {
            foreach ($event_dates as $event_date) {
                $date_string = BRCC_Helpers::parse_date_value($event_date);
                if ($date_string) {
                    // Each date may have a time
                    $event_times = get_post_meta($product_id, 'fooevents_event_times', true);
                    $time_string = '';
                    $formatted_time = '';

                    if (is_array($event_times) && isset($event_times[$date_string])) {
                        $time_string = $this->parse_time_value($event_times[$date_string]);
                        $formatted_time = $time_string ? date('g:i A', strtotime("1970-01-01 " . $time_string)) : '';
                    }

                    // Each date shares the same stock quantity in FooEvents
                    $stock = $product->get_stock_quantity();
                    $dates[] = array(
                        'date' => $date_string,
                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                        'time' => $time_string,
                        'formatted_time' => $formatted_time,
                        'inventory' => $stock,
                        'source' => 'fooevents_multi'
                    );
                }
            }
        }

        // Serialized event dates for multi-day events
        $serialized_dates = get_post_meta($product_id, 'fooevents_event_dates_serialized', true);
        if (!empty($serialized_dates)) {
            $unserialized_dates = maybe_unserialize($serialized_dates);
            if (is_array($unserialized_dates)) {
                foreach ($unserialized_dates as $index => $event_date) {
                    $date_string = BRCC_Helpers::parse_date_value($event_date);
                    if ($date_string) {
                        // Try to get time for this index
                        $serialized_times = get_post_meta($product_id, 'fooevents_event_times_serialized', true);
                        $unserialized_times = maybe_unserialize($serialized_times);
                        $time_string = '';
                        $formatted_time = '';

                        if (is_array($unserialized_times) && isset($unserialized_times[$index])) {
                            $time_string = $this->parse_time_value($unserialized_times[$index]);
                            $formatted_time = $time_string ? date('g:i A', strtotime("1970-01-01 " . $time_string)) : '';
                        }

                        $stock = $product->get_stock_quantity();
                        $dates[] = array(
                            'date' => $date_string,
                            'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                            'time' => $time_string,
                            'formatted_time' => $formatted_time,
                            'inventory' => $stock,
                            'source' => 'fooevents_serialized'
                        );
                    }
                }
            }
        }

        // Individual day inventories (Only in some versions of FooEvents)
        $day_slots = get_post_meta($product_id, 'fooevents_event_day_slots', true);
        if (!empty($day_slots) && is_array($day_slots)) {
            foreach ($day_slots as $date => $slot) {
                $date_string = BRCC_Helpers::parse_date_value($date);
                if ($date_string) {
                    // Try to get time
                    $time_string = '';
                    $formatted_time = '';
                    if (isset($slot['time'])) {
                        $time_string = $this->parse_time_value($slot['time']);
                        $formatted_time = $time_string ? date('g:i A', strtotime("1970-01-01 " . $time_string)) : '';
                    }

                    $dates[] = array(
                        'date' => $date_string,
                        'formatted_date' => date_i18n(get_option('date_format'), strtotime($date_string)),
                        'time' => $time_string,
                        'formatted_time' => $formatted_time,
                        'inventory' => isset($slot['stock']) ? $slot['stock'] : null,
                        'source' => 'fooevents_day_slots'
                    );
                }
            }
        }

        return $dates;
    }

    /**
     * Get booking slots from a product
     * 
     * @param WC_Product $product Product object
     * @return array Array of slots with date, time, and inventory
     */
    private function get_product_booking_slots($product)
    {
        $booking_slots = array();

        // Check if this is a SmartCrawl product with bookings
        $slots = $product->get_meta('_booking_slots');
        if (!empty($slots) && is_array($slots)) {
            foreach ($slots as $date => $slot_data) {
                $time = '';
                // Try to extract time from slot data
                if (isset($slot_data['time'])) {
                    $time = $slot_data['time'];
                }

                $booking_slots[] = array(
                    'date' => $date,
                    'time' => $time,
                    'inventory' => isset($slot_data['inventory']) ? $slot_data['inventory'] : null
                );
            }
            return $booking_slots;
        }

        // Try to get _wc_booking_availability (used by WooCommerce Bookings)
        $availability = $product->get_meta('_wc_booking_availability');
        if (!empty($availability) && is_array($availability)) {
            foreach ($availability as $slot) {
                if (isset($slot['from']) && isset($slot['to'])) {
                    $from_date = new DateTime($slot['from']);
                    $to_date = new DateTime($slot['to']);
                    $interval = new DateInterval('P1D');
                    $date_range = new DatePeriod($from_date, $interval, $to_date);

                    $time = '';
                    if (isset($slot['from_time']) && isset($slot['to_time'])) {
                        $time = $slot['from_time'];
                    }

                    foreach ($date_range as $date) {
                        $date_string = $date->format('Y-m-d');
                        $booking_slots[] = array(
                            'date' => $date_string,
                            'time' => $time,
                            'inventory' => isset($slot['qty']) ? $slot['qty'] : null
                        );
                    }
                }
            }
            return $booking_slots;
        }

        // For SmartCrawl products (from your screenshot)
        $bookings = $product->get_meta('_product_booking_slots');
        if (empty($bookings)) {
            // For products that use a different schema, look for properties like 'bookings' or 'slots'
            foreach (array('_wc_slots', '_event_slots', '_bookings', '_event_dates') as $possible_meta_key) {
                $meta_value = $product->get_meta($possible_meta_key);
                if (!empty($meta_value)) {
                    $bookings = $meta_value;
                    break;
                }
            }
        }

        if (!empty($bookings) && is_array($bookings)) {
            foreach ($bookings as $booking) {
                if (isset($booking['date'])) {
                    $time = '';
                    if (isset($booking['time'])) {
                        $time = $booking['time'];
                    } else if (isset($booking['hour']) && isset($booking['minute'])) {
                        $time = sprintf('%02d:%02d', $booking['hour'], $booking['minute']);
                    }

                    $inventory = null;
                    if (isset($booking['stock'])) {
                        $inventory = $booking['stock'];
                    } elseif (isset($booking['inventory'])) {
                        $inventory = $booking['inventory'];
                    } elseif (isset($booking['quantity'])) {
                        $inventory = $booking['quantity'];
                    }

                    $booking_slots[] = array(
                        'date' => $booking['date'],
                        'time' => $time,
                        'inventory' => $inventory
                    );
                }
            }
            return $booking_slots;
        }

        // If no booking-specific data found, get product variations based on date attributes
        if ($product->is_type('variable')) {
            $variations = $product->get_available_variations();

            foreach ($variations as $variation_data) {
                $variation_id = $variation_data['variation_id'];
                $variation = wc_get_product($variation_id);

                if (!$variation) continue;

                // Look for date-related attributes
                $attributes = $variation->get_attributes();
                $date_attr = null;
                $time_attr = null;

                foreach ($attributes as $attr_name => $attr_value) {
                    $lower_name = strtolower($attr_name);
                    if (strpos($lower_name, 'date') !== false || strpos($lower_name, 'day') !== false) {
                        $date_attr = BRCC_Helpers::parse_date_value($attr_value);
                    } else if (strpos($lower_name, 'time') !== false || strpos($lower_name, 'hour') !== false) {
                        $time_attr = $this->parse_time_value($attr_value);
                    }
                }

                if ($date_attr) {
                    $booking_slots[] = array(
                        'date' => $date_attr,
                        'time' => $time_attr ?: '',
                        'inventory' => $variation->get_stock_quantity()
                    );
                }
            }

            if (!empty($booking_slots)) {
                return $booking_slots;
            }
        }

        // Check product name for dates as a fallback
        $product_name = $product->get_name();
        $day_name = BRCC_Helpers::extract_day_from_title($product_name);
        $time_info = BRCC_Helpers::extract_time_from_title($product_name);

        if ($day_name) {
            $upcoming_dates = BRCC_Helpers::get_upcoming_dates_for_day($day_name);
            foreach ($upcoming_dates as $date) {
                $booking_slots[] = array(
                    'date' => $date,
                    'time' => $time_info ?: '',
                    'inventory' => null // Can't determine stock this way
                );
            }

            if (!empty($booking_slots)) {
                return $booking_slots;
            }
        }

        return $booking_slots;
    }

    /**
     * AJAX: Get product dates including all time slots
     */
    public function ajax_get_product_dates()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
        // Get pagination parameters
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 25;
        $offset = ($page - 1) * $per_page;

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        // Get existing mappings
        $this->load_mappings(); // Ensure mappings are loaded
        $all_mappings = $this->all_mappings;
        $date_mappings = isset($all_mappings[$product_id . '_dates']) ? $all_mappings[$product_id . '_dates'] : array();

        // Get base Eventbrite ID for suggestions
        $base_mapping = isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : array(
            'manual_eventbrite_id' => '', // Use standardized key
            'eventbrite_event_id' => '',
        );
        
        // Sort date mappings chronologically
        uksort($date_mappings, function($a, $b) {
            return strtotime($a) - strtotime($b);
        });
        
        // Get total count and calculate pages
        $total_mappings = count($date_mappings);
        $total_pages = ceil($total_mappings / $per_page);
        
        // Slice the mappings for the current page
        $paged_mappings = array_slice($date_mappings, $offset, $per_page, true);
        
        // Build dates array for display
        $dates_to_display = array();
        foreach ($paged_mappings as $date_key => $mapping_data) {
            // Parse date and time from the key
            $actual_date = $date_key;
            $actual_time = null;
            if (strpos($date_key, '_') !== false) {
                list($actual_date, $actual_time) = explode('_', $date_key, 2);
            }

            // Format date and time for display
            $date_timestamp = strtotime($actual_date);
            $formatted_date = $date_timestamp ? date_i18n(get_option('date_format'), $date_timestamp) : $actual_date;
            $formatted_time = '';
            if ($actual_time) {
                // Combine with a dummy date for strtotime to parse time correctly
                $time_timestamp = strtotime('1970-01-01 ' . $actual_time);
                if ($time_timestamp) {
                    $formatted_time = date_i18n(get_option('time_format'), $time_timestamp);
                } else {
                    $formatted_time = $actual_time; // Fallback if formatting fails
                }
            }

            // Prepare the data structure expected by the JavaScript
            $dates_to_display[] = array(
                'date' => $actual_date, // YYYY-MM-DD
                'formatted_date' => $formatted_date,
                'time' => $actual_time, // HH:MM or null
                'formatted_time' => $formatted_time,
                'inventory' => null, // Inventory is not stored in mapping
                'eventbrite_event_id' => isset($mapping_data['eventbrite_event_id']) ? $mapping_data['eventbrite_event_id'] : '',
                'manual_eventbrite_id' => isset($mapping_data['manual_eventbrite_id']) ? $mapping_data['manual_eventbrite_id'] : '', // Use standardized key
                'square_id' => isset($mapping_data['square_id']) ? $mapping_data['square_id'] : '', // Add Square ID
                'from_mappings' => true // Indicate this came from saved data
            );
        }

        // Fetch available Eventbrite events for dropdown
        $available_events = array();
        if (class_exists('BRCC_Eventbrite_Integration')) {
            $eventbrite_integration = new BRCC_Eventbrite_Integration();
            $events_result = $eventbrite_integration->get_organization_events(array('live', 'draft', 'started'));
            
            if (!is_wp_error($events_result) && is_array($events_result)) {
                foreach ($events_result as $event) {
                    if (isset($event['id']) && isset($event['name']['text'])) {
                        // Add ID to label for clarity
                        $available_events[$event['id']] = esc_html($event['name']['text']) . ' (' . $event['id'] . ')';
                    }
                }
                asort($available_events); // Sort by name
            } else {
                // Log error if fetching failed
                error_log('ajax_get_product_dates: Failed to fetch organization events: ' . 
                    (is_wp_error($events_result) ? $events_result->get_error_message() : 'Unknown error'));
            }
        }

        // Prepare the response data
        $response_data = array(
            'dates' => $dates_to_display,
            'events' => $available_events,
            'base_ticket_class_id' => $base_mapping['manual_eventbrite_id'] ?? '', // Use standardized key
            'base_event_id' => $base_mapping['eventbrite_event_id'] ?? '',
            'pagination' => array(
                'current_page' => $page,
                'per_page' => $per_page,
                'total_mappings' => $total_mappings,
                'total_pages' => $total_pages
            )
        );

        // Send the JSON response
        wp_send_json_success($response_data);
    }

    /**
     * AJAX: Save product date mappings with enhancements
     */
    public function ajax_save_product_date_mappings()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Get product ID
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }

        // Get mappings from request
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        // Add more debug logging to see what's being received from the form:
        error_log('DEBUG: Received mappings data: ' . print_r($mappings, true));
        error_log('DEBUG: Form field values for ticket class IDs:');
        foreach ($mappings as $idx => $mapping) {
            error_log('DEBUG: Mapping ' . $idx . ' ticket class ID (manual_eventbrite_id): ' .
                (isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : 'not set') .
                // ' | (eventbrite_ticket_class_id): ' . // REMOVED old debug key
                (isset($mapping['eventbrite_ticket_class_id']) ? $mapping['eventbrite_ticket_class_id'] : 'not set'));
        }

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
        $this->load_mappings(); // Ensure mappings are loaded
        $all_mappings = $this->all_mappings;

        // Initialize or reset date mappings for this product
        // IMPORTANT: PRESERVE any mappings that aren't in the current page view
        if (!isset($all_mappings[$product_id . '_dates'])) {
            $all_mappings[$product_id . '_dates'] = array();
        }

        // Build a new array with only the submitted mappings
        $new_date_mappings = array();
        $successful_mappings = 0;

        // Process mappings
        foreach ($mappings as $mapping) {
            if (empty($mapping['date'])) continue;
            
            $date = sanitize_text_field($mapping['date']);
            $time = isset($mapping['time']) ? sanitize_text_field($mapping['time']) : null;
            $event_id = isset($mapping['eventbrite_event_id']) ? sanitize_text_field($mapping['eventbrite_event_id']) : '';
            
            // Check for both possible keys, prioritizing the correct one
            // Assuming form might send 'manual_eventbrite_id' or 'eventbrite_ticket_class_id'
            // Check multiple possible sources for the ticket class ID, prioritizing manual_eventbrite_id
            $ticket_class_id = '';
            if (isset($mapping['manual_eventbrite_id']) && !empty($mapping['manual_eventbrite_id'])) {
                $ticket_class_id = sanitize_text_field($mapping['manual_eventbrite_id']);
            } elseif (isset($mapping['ticket_class_id']) && !empty($mapping['ticket_class_id'])) { // Check 'ticket_class_id' next
                $ticket_class_id = sanitize_text_field($mapping['ticket_class_id']);
            } elseif (isset($mapping['eventbrite_id']) && !empty($mapping['eventbrite_id'])) { // Fallback to 'eventbrite_id'
                $ticket_class_id = sanitize_text_field($mapping['eventbrite_id']);
            }
            
            // REMOVED: Fallback if the primary keys are empty but 'eventbrite_id' exists (old way)
            // if (empty($ticket_class_id) && isset($mapping['eventbrite_id'])) {
            //      $ticket_class_id = sanitize_text_field($mapping['eventbrite_id']);
            // }

            $square_id = isset($mapping['square_id']) ? sanitize_text_field($mapping['square_id']) : '';

            // Create the date key - include time if provided
            $date_key = $time ? $date . '_' . $time : $date; // Moved key creation here for logging

            // Make sure all IDs are explicitly set in the mapping
            $current_mapping = array(
                'eventbrite_event_id' => $event_id,
                'manual_eventbrite_id' => $ticket_class_id, // Explicitly set this
                'ticket_class_id' => $ticket_class_id,      // Also set this for consistency
                // 'eventbrite_id' => $ticket_class_id,        // REMOVED: Keep backward compatibility
                'square_id' => $square_id
            );

            // Log exactly what we're saving for this specific entry
            error_log("DEBUG: Preparing mapping for date_key {$date_key} with manual_eventbrite_id: {$ticket_class_id}");

            // Add time only if it exists
            if ($time) {
                $current_mapping['time'] = $time;
                $key = $date . '_' . $time;
            } else {
                $key = $date;
            }

            // Only add if we have a ticket class ID or Square ID
            if (!empty($ticket_class_id) || !empty($square_id)) {
                $new_date_mappings[$key] = $current_mapping;
                $successful_mappings++;
                
                // Add debug log
                if (class_exists('BRCC_Helpers') && method_exists('BRCC_Helpers', 'log_debug')) {
                    BRCC_Helpers::log_debug('Mapping created/updated for date/time key ' . $key . ':', $current_mapping);
                } else {
                     error_log('DEBUG: Mapping created/updated for date/time key ' . $key . ': ' . print_r($current_mapping, true));
                }
            } else {
                 error_log('DEBUG: Skipping mapping for key ' . $key . ' due to missing Ticket Class ID and Square ID.');
            }
        }
        
        // Sort the new mappings by date (key) before saving
        uksort($new_date_mappings, function($a, $b) {
            // Extract date part for comparison
            $date_a = explode('_', $a)[0];
            $date_b = explode('_', $b)[0];
            $time_a = strpos($a, '_') !== false ? strtotime(explode('_', $a)[1]) : 0;
            $time_b = strpos($b, '_') !== false ? strtotime(explode('_', $b)[1]) : 0;

            $date_compare = strtotime($date_a) - strtotime($date_b);
            if ($date_compare === 0) {
                return $time_a - $time_b; // Sort by time if dates are the same
            }
            return $date_compare;
        });

        // Replace the old date mappings entirely with the new set
        $all_mappings[$product_id . '_dates'] = $new_date_mappings;

        // Before update_option, log the entire structure being saved for this product's dates
        error_log('DEBUG: Final date mappings structure to save for product ' . $product_id . ': ' . print_r($new_date_mappings, true));

        // Save all mappings back to the option
        $updated = update_option('brcc_product_mappings', $all_mappings);

        // Verify what was saved
        $verification = get_option('brcc_product_mappings');
        error_log('DEBUG: Update option result: ' . ($updated ? 'Success' : 'No change/Failed'));

        if (isset($verification[$product_id . '_dates'])) {
            error_log('DEBUG: Verification: Saved date mappings for product ' . $product_id . ': ' . print_r($verification[$product_id . '_dates'], true));
            
            // Check specifically for the manual_eventbrite_id and ticket_class_id
            foreach ($verification[$product_id . '_dates'] as $date_key => $mapping) {
                $manual_id = isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : 'NOT SET';
                $ticket_id = isset($mapping['ticket_class_id']) ? $mapping['ticket_class_id'] : 'NOT SET'; // Keep checking ticket_class_id for now
                // $eventbrite_id = isset($mapping['eventbrite_id']) ? $mapping['eventbrite_id'] : 'NOT SET'; // REMOVED: Also check old key

                error_log("DEBUG: Verification for date_key {$date_key}: manual_eventbrite_id={$manual_id}, ticket_class_id={$ticket_id}"); // Removed eventbrite_id from log
            }
            
            // Check specifically for the manual_eventbrite_id and ticket_class_id
            foreach ($verification[$product_id . '_dates'] as $date_key => $mapping) {
                $manual_id = isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : 'NOT SET';
                $ticket_id = isset($mapping['ticket_class_id']) ? $mapping['ticket_class_id'] : 'NOT SET'; // Keep checking ticket_class_id for now
                // $eventbrite_id = isset($mapping['eventbrite_id']) ? $mapping['eventbrite_id'] : 'NOT SET'; // REMOVED: Also check old key

                error_log("DEBUG: Verification for date_key {$date_key}: manual_eventbrite_id={$manual_id}, ticket_class_id={$ticket_id}"); // Removed eventbrite_id from log
            }
        } else {
             error_log('DEBUG: Verification: No date mappings found for product ' . $product_id . ' after update.');
        }
        // End verification debug

        if ($updated) {
            // Update the internal cache as well
            $this->all_mappings = $all_mappings;
        }

        // Return success with count
        wp_send_json_success(array(
            'message' => sprintf(
                __('Successfully saved %d date mappings.', 'brcc-inventory-tracker'),
                $successful_mappings
            ),
            'count' => $successful_mappings
        ));
    }

  /**
     * AJAX: Test product date mapping with time support
     */
    public function ajax_test_product_date_mapping()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        $time = isset($_POST['time']) ? sanitize_text_field($_POST['time']) : '';
        // Get IDs from POST - expecting standardized keys now
        // Ensure we're checking for different possible key names from POST data
        $ticket_class_id = isset($_POST['manual_eventbrite_id']) && !empty($_POST['manual_eventbrite_id']) ? sanitize_text_field($_POST['manual_eventbrite_id']) :
                        (isset($_POST['ticket_class_id']) && !empty($_POST['ticket_class_id']) ? sanitize_text_field($_POST['ticket_class_id']) :
                        (isset($_POST['eventbrite_id']) && !empty($_POST['eventbrite_id']) ? sanitize_text_field($_POST['eventbrite_id']) : ''));
        $event_id = isset($_POST['eventbrite_event_id']) ? sanitize_text_field($_POST['eventbrite_event_id']) : '';

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
            return;
        }
        // Ticket Class ID is required by JS validation, so we expect it here.
        if (empty($ticket_class_id)) {
             wp_send_json_error(array(
                 'message' => __('Ticket Class ID is required to run a test.', 'brcc-inventory-tracker'),
                 'status' => 'error'
             ));
             return;
        }
        // Event ID is optional for the test itself, but required for the specific ticket-in-event test.

        $eventbrite_integration = new BRCC_Eventbrite_Integration();

        // --- Test Logic ---
        // Check if BOTH IDs are provided for the specific test
        if (!empty($event_id)) {
            // --- Test BOTH Event ID and Ticket Class ID ---
            BRCC_Helpers::log_operation('Admin', 'Test Eventbrite Mapping', sprintf('Testing Product ID %d - Event ID %s, Ticket Class ID %s', $product_id, $event_id, $ticket_class_id));
            $test_result = $eventbrite_integration->test_ticket_via_event($event_id, $ticket_class_id); // Use correct variables

            if (is_wp_error($test_result)) {
                wp_send_json_error(array(
                    'message' => sprintf(__('Eventbrite API test failed: %s', 'brcc-inventory-tracker'), $test_result->get_error_message()),
                    'status' => 'error'
                ));
            } elseif ($test_result === true) {
                // Fetch details for confirmation message
                $event_details = $eventbrite_integration->get_eventbrite_event($event_id);
                $ticket_details = null;
                if (!is_wp_error($event_details) && isset($event_details['ticket_classes'])) {
                    foreach ($event_details['ticket_classes'] as $tc) {
                        if ($tc['id'] === $ticket_class_id) { $ticket_details = $tc; break; } // Use correct variable
                    }
                }
                wp_send_json_success(array(
                    'message' => sprintf(__('Test successful! Found Ticket Class "%s" in Event "%s".', 'brcc-inventory-tracker'),
                        isset($ticket_details['name']) ? $ticket_details['name'] : $ticket_class_id, // Use correct variable
                        isset($event_details['name']['text']) ? $event_details['name']['text'] : $event_id // Use correct variable
                    ),
                    'status' => 'success',
                    'details' => array(
                        'event_id' => $event_id, 'ticket_id' => $ticket_class_id, // Use correct variable
                        'event_name' => isset($event_details['name']['text']) ? $event_details['name']['text'] : null,
                        'ticket_name' => isset($ticket_details['name']) ? $ticket_details['name'] : null, // This is the Ticket Class Name
                        'event_time' => isset($event_details['start']['local']) ? date('H:i', strtotime($event_details['start']['local'])) : null,
                        'venue_name' => isset($event_details['venue']['name']) ? $event_details['venue']['name'] : null,
                    )
                ));
            } else { // $test_result === false (Ticket Class not found in specified Event)
                 wp_send_json_error(array(
                     'message' => sprintf(__('Test failed: Ticket Class ID %s was not found within Event ID %s.', 'brcc-inventory-tracker'), $ticket_class_id, $event_id), // Use correct variables
                     'status' => 'warning'
                 ));
            }
        } else {
             // --- Only Ticket Class ID provided (Event ID is empty) ---
             // We require both for a specific test.
             wp_send_json_error(array(
                 'message' => __('Please select both a Ticket Class ID and an Event ID to test the specific mapping.', 'brcc-inventory-tracker'),
                 'status' => 'warning' // Use warning as it's not a technical error
             ));
        }
    }

    /**
     * Get common time slots for dropdown
     * 
     * @return array Array of time options
     */
    private function get_common_times()
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

