<?php
/**
 * BRCC Helpers Class
 * 
 * Provides helper functions for the BRCC Inventory Tracker
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class BRCC_Helpers {
    /**
     * Check if test mode is enabled
     * 
     * @return boolean
     */
    public static function is_test_mode() {
        $settings = get_option('brcc_api_settings');
        return isset($settings['test_mode']) && $settings['test_mode'];
    }
    
    /**
     * Check if live logging is enabled
     * 
     * @return boolean
     */
    public static function is_live_logging() {
        $settings = get_option('brcc_api_settings');
        return isset($settings['live_logging']) && $settings['live_logging'];
    }
    /**
 * Log warning
 *
 * @param string $message Warning message
 * @param mixed $context Optional context data (will be serialized)
 */
public static function log_warning($message, $context = null) {
    $log = get_option('brcc_warning_log', array());
    
    $entry = array(
        'timestamp' => time(),
        'message' => $message,
    );
    
    // Add context data if provided
    if ($context !== null) {
        $entry['context'] = $context;
    }
    
    $log[] = $entry;
    
    // Limit log size
    if (count($log) > 100) {
        $log = array_slice($log, -100);
    }
    
    update_option('brcc_warning_log', $log);
    
    // Also log to debug.log if WP_DEBUG and WP_DEBUG_LOG are enabled
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        $log_entry = "[" . date('Y-m-d H:i:s') . "] BRCC Warning: " . $message;
        if ($context !== null) {
            $log_entry .= "\nContext: " . var_export($context, true);
        }
        error_log($log_entry);
    }
    
    // Also log to the main operation log table
    // Extract component/operation from message if possible, otherwise use defaults
    $component = 'Legacy Log';
    $operation = 'Warning';
    // Basic check if context might contain more info
    if (is_array($context) && isset($context['component']) && isset($context['operation'])) {
        $component = $context['component'];
        $operation = $context['operation'];
    } elseif (is_string($context)) {
         // Maybe context string gives a hint? Less reliable.
         // For now, stick to defaults unless context is structured array.
    }
    
    // Combine message and context for the main log message if context exists
    $full_message = $message;
    if ($context !== null) {
        // Append context in a readable format
        $full_message .= ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
    self::log_operation($component, $operation, $full_message, 'warning');
}
    /**
     * Check if any logging is enabled (test mode or live logging)
     * 
     * @return boolean
     */
    public static function should_log() {
        return self::is_test_mode() || self::is_live_logging();
    }
    
    /**
     * Log operation in test mode or live logging mode
     * 
     * @param string $source The source of the operation (WooCommerce, Eventbrite)
     * @param string $operation The operation being performed
     * @param string $details Details about the operation
     */
    public static function log_operation($component, $operation, $message, $log_type = 'info') {
        // Renamed parameters for clarity and consistency with the database table
        // $source -> $component
        // $details -> $message
        // Added $log_type parameter

        if (!self::should_log()) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'brcc_operation_logs';

        // Prepare data for insertion
        $data = array(
            'timestamp' => current_time('mysql', 1), // Use WordPress current time in GMT MySQL format
            'component' => $component,
            'operation' => $operation,
            'message' => $message,
            'log_type' => $log_type, // Use the provided log type
        );

        // Define data formats
        $format = array(
            '%s', // timestamp (string format for datetime)
            '%s', // component
            '%s', // operation
            '%s', // message
            '%s', // log_type
        );

        // Insert the log entry into the database table
        $result = $wpdb->insert($table_name, $data, $format);

        if ($result === false) {
            // Log an error if insertion fails (e.g., to PHP error log)
            error_log("BRCC Log Error: Failed to insert log into database. Error: " . $wpdb->last_error);
            error_log("BRCC Log Error Data: " . print_r($data, true));
        }

        // Note: The previous options table logic and size limiting are removed.
        // Consider adding a database cleanup mechanism (e.g., delete logs older than X days) if needed in the future.
    }
    
    /**
     * Get product name by ID
     * 
     * @param int $product_id
     * @return string
     */
    public static function get_product_name($product_id) {
        $product = wc_get_product($product_id);
        return $product ? $product->get_name() : __('Unknown Product', 'brcc-inventory-tracker') . ' (' . $product_id . ')';
    }
    
    /**
     * Get a readable date format
     * 
     * @param string $date Date in Y-m-d format
     * @return string Formatted date
     */
    public static function format_date($date) {
        if (empty($date)) {
            return '';
        }
        
        return date_i18n(get_option('date_format'), strtotime($date));
    }
    
    /**
     * Check if a plugin is active
     *
     * @param string $plugin_file Plugin file path relative to plugins directory
     * @return boolean
     */
    public static function is_plugin_active($plugin_file) {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        return is_plugin_active($plugin_file);
    }
    
    /**
     * Check if FooEvents is active
     *
     * @return boolean
     */
    public static function is_fooevents_active() {
        return self::is_plugin_active('fooevents/fooevents.php') || 
               self::is_plugin_active('fooevents-for-woocommerce/fooevents.php');
    }
    
    /**
     * Log error
     *
     * @param string $message Error message
     */
    public static function log_error($message) {
        $log = get_option('brcc_error_log', array());
        
        $log[] = array(
            'timestamp' => time(),
            'message' => $message,
        );
        
        // Limit log size
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        
        update_option('brcc_error_log', $log);
        
        // Also log to the main operation log table
        self::log_operation('Legacy Log', 'Error', $message, 'error');
    }
    
    /**
     * Log info
     *
     * @param string $message Info message
     */
    public static function log_info($message) {
        $log = get_option('brcc_info_log', array());
        
        $log[] = array(
            'timestamp' => time(),
            'message' => $message,
        );
        
        // Limit log size
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        
        update_option('brcc_info_log', $log);
        
        // Also log to the main operation log table
        self::log_operation('Legacy Log', 'Info', $message, 'info');
    }

    /**
     * Parse time value to H:i format
     *
     * @param mixed $value Time value to parse
     * @return string|null H:i formatted time or null if parsing fails
     */
    public static function parse_time_value($value) {
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
     * Try to parse a date value to Y-m-d format
     * Enhanced to handle more date formats
     *
     * @param mixed $value Date value to parse
     * @return string|null Y-m-d formatted date or null if parsing fails
     */
    public static function parse_date_value($value) {
        // If already in Y-m-d format
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        
        // Handle array values (some plugins store dates as arrays)
        if (is_array($value) && isset($value['date'])) {
            $value = $value['date'];
        } elseif (is_array($value) && isset($value[0])) {
            $value = $value[0];
        }
        
        // Skip empty or non-string values after potential array extraction
        if (empty($value) || !is_string($value)) {
            return null;
        }
        
        // Try to convert various common date formats
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', $value)) {
            // MM/DD/YYYY or DD/MM/YYYY format
            $parts = explode('/', $value);
            if (count($parts) === 3) {
                // If the year is 2 digits, assume it's 2000+
                if (strlen($parts[2]) === 2) {
                    $parts[2] = '20' . $parts[2];
                }
                
                // Try both MM/DD/YYYY and DD/MM/YYYY interpretations
                // Check if parts are numeric before using strtotime
                if (is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
                    $date1 = strtotime("{$parts[2]}-{$parts[0]}-{$parts[1]}");
                    $date2 = strtotime("{$parts[2]}-{$parts[1]}-{$parts[0]}");
                    
                    // Use the interpretation that gives a valid date
                    if ($date1 !== false && date('Y-m-d', $date1) === "{$parts[2]}-{$parts[0]}-{$parts[1]}") {
                        return date('Y-m-d', $date1);
                    } elseif ($date2 !== false && date('Y-m-d', $date2) === "{$parts[2]}-{$parts[1]}-{$parts[0]}") {
                        return date('Y-m-d', $date2);
                    }
                }
            }
        }
        
        // Try common European formats (DD.MM.YYYY)
        if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{2,4}$/', $value)) {
            $parts = explode('.', $value);
            if (count($parts) === 3) {
                if (strlen($parts[2]) === 2) {
                    $parts[2] = '20' . $parts[2];
                }
                 if (is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2])) {
                    $timestamp = strtotime("{$parts[2]}-{$parts[1]}-{$parts[0]}");
                    if ($timestamp !== false && date('Y-m-d', $timestamp) === "{$parts[2]}-{$parts[1]}-{$parts[0]}") {
                        return date('Y-m-d', $timestamp);
                    }
                }
            }
        }
        
        // Try human readable format (January 1, 2025 or 1 January 2025)
        // Check if it contains letters before trying strtotime
        if (preg_match('/[a-zA-Z]+/', $value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                // Basic validation: check if the formatted date looks reasonable
                // This helps avoid strtotime interpreting numbers like '123' as timestamps
                if (strpos($value, (string)date('Y', $timestamp)) !== false ||
                    strpos(strtolower($value), strtolower(date('F', $timestamp))) !== false ||
                    strpos(strtolower($value), strtolower(date('M', $timestamp))) !== false) {
                    return date('Y-m-d', $timestamp);
                }
            }
        }
        
        // Try to convert using strtotime as a last resort ONLY if it looks like a date
        // Avoid converting plain numbers or unintended strings
        if (strpos($value, '-') !== false || strpos($value, '/') !== false || strpos($value, '.') !== false || preg_match('/\d{4}/', $value)) {
             $timestamp = strtotime($value);
             if ($timestamp !== false) {
                 // Add a check to ensure it's not just interpreting a year or number
                 if (date('Y', $timestamp) > 1970) {
                     return date('Y-m-d', $timestamp);
                 }
             }
        }
        
        return null;
    }

    /**
     * Extract day name from product title
     *
     * @param string $product_title The product title/name
     * @return string|null Day name or null if not found
     */
    public static function extract_day_from_title($product_title) {
        $days = array('sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday');
        $product_title = strtolower($product_title);
        
        foreach ($days as $day) {
            if (strpos($product_title, $day) !== false) {
                return $day;
            }
        }
        
        return null;
    }

    /**
     * Extract time from product title
     *
     * @param string $product_title The product title/name
     * @return string|null Time in H:i format or null if not found
     */
    public static function extract_time_from_title($product_title) {
        $product_title = strtolower($product_title);
        
        // Common show time formats like "8pm", "8:00 pm", "8 PM"
        $time_patterns = array(
            '/(\d{1,2})[ :]?([0-5][0-9])?\s*(am|pm)/i', // 8pm, 8:00pm, 8 pm
            '/(\d{1,2})[:.](\d{2})/i',                  // 20:00, 8.00 (Fixed POSIX class warning)
            '/(\d{1,2})\s*o\'?clock/i'                  // 8 o'clock
        );
        
        foreach ($time_patterns as $pattern) {
            if (preg_match($pattern, $product_title, $matches)) {
                $hour = intval($matches[1]);
                $minute = isset($matches[2]) && !empty($matches[2]) ? intval($matches[2]) : 0;
                
                // Adjust for AM/PM if present
                if (isset($matches[3]) && strtolower($matches[3]) === 'pm' && $hour < 12) {
                    $hour += 12;
                } elseif (isset($matches[3]) && strtolower($matches[3]) === 'am' && $hour === 12) {
                    $hour = 0;
                }
                
                return sprintf('%02d:%02d', $hour, $minute);
            }
        }
        
        return null;
    }

    /**
     * Convert time string to minutes since midnight
     *
     * @param string $time Time in H:i format
     * @return int Minutes since midnight
     */
    private static function time_to_minutes($time) {
        list($hours, $minutes) = explode(':', $time);
        return (intval($hours) * 60) + intval($minutes);
    }

    /**
     * Check if two times are close enough to be considered matching
     *
     * @param string $time1 Time in H:i format
     * @param string $time2 Time in H:i format
     * @param int $buffer_minutes Buffer in minutes to consider times close enough
     * @return bool True if times are close enough
     */
    public static function is_time_close($time1, $time2, $buffer_minutes = 30) {
        if (empty($time1) || empty($time2)) {
            return false;
        }

        $time1_minutes = self::time_to_minutes($time1);
        $time2_minutes = self::time_to_minutes($time2);

        return abs($time1_minutes - $time2_minutes) <= $buffer_minutes;
    }

    /**
     * Get upcoming dates for a specific day of the week
     *
     * @param string $day_name Day name (Sunday, Monday, etc.)
     * @param int $num_dates Number of upcoming dates to return
     * @return array Array of dates in Y-m-d format
     */
    public static function get_upcoming_dates_for_day($day_name, $num_dates = 8) {
        $day_map = array(
            'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6
        );
        
        $day_index = isset($day_map[strtolower($day_name)]) ? $day_map[strtolower($day_name)] : -1;
        
        if ($day_index === -1) {
            return array(); // Invalid day name
        }
        
        $upcoming_dates = array();
        try {
            // Use WordPress timezone
            $timezone = wp_timezone();
            $current_date = new DateTime('now', $timezone);
            $current_date->setTime(0, 0, 0); // Reset time part

            // Get current day of week (0 = Sunday, 6 = Saturday)
            $current_day_index = (int)$current_date->format('w');
            
            // Calculate days until the next target day
            $days_until_next = ($day_index - $current_day_index + 7) % 7;
            if ($days_until_next === 0) {
                // If today is the target day, start from next week unless it's the only date requested
                if ($num_dates > 1) {
                    $days_until_next = 7;
                } else {
                    // If only one date requested and it's today, return today
                    $upcoming_dates[] = $current_date->format('Y-m-d');
                    return $upcoming_dates;
                }
            
            }

            // Set to the first upcoming target day
            if ($days_until_next > 0) {
                $current_date->modify('+' . $days_until_next . ' days');
            }

            // Collect upcoming dates
            for ($i = 0; $i < $num_dates; $i++) {
                // Check if we already added the first date (if it was today)
                if ($i === 0 && $days_until_next === 0 && $num_dates > 1) {
                    $current_date->modify('+7 days'); // Jump to next week if first date was today
                } else if ($i > 0) {
                    $current_date->modify('+7 days'); // Jump to the next occurrence of this day
                }
                $upcoming_dates[] = $current_date->format('Y-m-d');
            }
        } catch (Exception $e) {
            // Log error if DateTime fails
            self::log_error('Error calculating upcoming dates: ' . $e->getMessage());
            return array();
        }

        return $upcoming_dates;
    }
    
    /**
     * Get FooEvents date from order item
     *
     * @param WC_Order_Item $item Order item
     * @return string|null Booking date in Y-m-d format or null if not found
     */
    public static function get_fooevents_date_from_item($item) {
        // Check if FooEvents is active
        if (!self::is_fooevents_active()) {
            return null;
        }
        
        // FooEvents specific meta keys
        $fooevents_keys = array(
            'WooCommerceEventsDate',
            'WooCommerceEventsDateMySQLFormat', // Added based on actual meta keys
            'WooCommerceEventsDateTimestamp',   // Added based on actual meta keys
            'WooCommerceEventsEndDate',         // Added based on actual meta keys
            'WooCommerceEventsEndDateMySQLFormat', // Added based on actual meta keys
            'WooCommerceEventsEndDateTimestamp',   // Added based on actual meta keys
            'WooCommerceEventsTicketDate',
            'WooCommerceEventsProductDate',
            '_event_date',
            '_event_start_date',
            'fooevents_date',
            'fooevents_ticket_date'
        );
        
        foreach ($fooevents_keys as $key) {
            $date_value = $item->get_meta($key);
            if (!empty($date_value)) {
                $parsed_date = self::parse_date_value($date_value);
                if ($parsed_date) {
                    return $parsed_date;
                }
            }
        }
        
        // Check for multiple day events in FooEvents
        $event_id = $item->get_meta('WooCommerceEventsProductID');
        
        if ($event_id) {
            // Get date from post meta directly
            $event_date = get_post_meta($event_id, 'WooCommerceEventsDate', true);
            if (!empty($event_date)) {
                return self::parse_date_value($event_date);
            } // End if (!empty($event_date))
        } // End if ($event_id)
        
        // Check for booking_date_id (often used by booking systems integrated with FooEvents)
        $booking_date_id = $item->get_meta('booking_date_id');
        if (!empty($booking_date_id)) {
            $item_id = $item->get_id(); // Get item ID for logging
            self::log_debug("Item ID: " . $item_id . " - Found booking_date_id: " . $booking_date_id);
            
            // Use our new function to get the date from booking_date_id
            $event_date = self::get_date_from_booking_date_id($booking_date_id);
            
            if ($event_date) {
                self::log_debug("Item ID: " . $item_id . " - Successfully parsed date from booking_date_id: " . $event_date);
                return $event_date;
            }
            
            self::log_debug("Item ID: " . $item_id . " - Could not retrieve date from booking_date_id: " . $booking_date_id);
        }
        
        // If no date found in specific FooEvents meta or via booking_date_id lookup
        return null;
    } // End get_fooevents_date_from_item

/**
     * Extract booking time from order item meta
     *
     * @param WC_Order_Item $item Order item
     * @return string|null Booking time in H:i format or null if not found
     */
    public static function extract_booking_time_from_item($item) {
        if (!is_a($item, 'WC_Order_Item')) {
            // Log if needed: self::log_debug('extract_booking_time_from_item: Item is not a WC_Order_Item.');
            return null;
        }
        
        // First, check for the specific FooEvents hour/minute/period combination
        $hour = $item->get_meta('WooCommerceEventsHour');
        $minutes = $item->get_meta('WooCommerceEventsMinutes');
        $period = $item->get_meta('WooCommerceEventsPeriod');
        
        if (!empty($hour) && !empty($minutes)) {
            // Convert to 24-hour format if period is available
            if (!empty($period) && strtoupper($period) === 'PM' && $hour < 12) {
                $hour = $hour + 12;
            } elseif (!empty($period) && strtoupper($period) === 'AM' && $hour == 12) {
                $hour = 0;
            }
            
            // Format as H:i
            $time = sprintf('%02d:%02d', $hour, $minutes);
            return $time;
        }

        // Common meta keys for time
        $time_meta_keys = array(
            'fooevents_time', // FooEvents specific
            'WooCommerceEventsTime', // FooEvents specific
            'WooCommerceEventsHour', // Added based on actual meta keys
            'WooCommerceEventsMinutes', // Added based on actual meta keys
            'WooCommerceEventsPeriod', // Added based on actual meta keys
            'event_time',
            'ticket_time',
            'booking_time',
            'pa_time', // Product attribute slug
            'time',
            '_event_time',
            '_booking_time',
            'Event Time', // Meta display names
            'Ticket Time',
            'Show Time',
            'Performance Time'
        );

        // Check known keys first
        foreach ($time_meta_keys as $key) {
            $time_value = $item->get_meta($key, true); // Use true for single value
            if (!empty($time_value)) {
                // self::log_debug("extract_booking_time_from_item: Found potential time in known key '{$key}': " . print_r($time_value, true));
                $parsed_time = self::parse_time_value($time_value);
                if ($parsed_time) {
                    // self::log_debug("extract_booking_time_from_item: Parsed time from known key '{$key}' as: {$parsed_time}");
                    return $parsed_time;
                }
            }
        }

        // Check all meta data as a fallback
        $item_meta = $item->get_meta_data();
        foreach ($item_meta as $meta) {
            $meta_data = $meta->get_data();
            $key = $meta_data['key'];
            $value = $meta_data['value'];

            // Check if key suggests time (case-insensitive)
            if (preg_match('/(time|hour|minute)/i', $key)) {
                 // self::log_debug("extract_booking_time_from_item: Checking meta key '{$key}' with value: " . print_r($value, true));
                $parsed_time = self::parse_time_value($value);
                if ($parsed_time) {
                     // self::log_debug("extract_booking_time_from_item: Parsed time from meta key '{$key}' as: {$parsed_time}");
                    return $parsed_time;
                }
            }
        }

        // Check product attributes if it's a variation item
        // Ensure it's a product line item first
        if ($item->is_type('line_item') && $item->get_variation_id()) {
             $product = $item->get_product(); // Get variation product object
             if ($product && $product->is_type('variation')) {
                 $attributes = $product->get_variation_attributes(false); // Get variation attributes (slugs)
                 // self::log_debug("extract_booking_time_from_item: Checking variation attributes: " . print_r($attributes, true));
                 foreach ($attributes as $attr_name => $attr_value) {
                     // Attribute names often start with 'attribute_'
                     $clean_attr_name = str_replace('attribute_', '', $attr_name);
                     if (preg_match('/(time|hour|minute)/i', $clean_attr_name)) {
                         // self::log_debug("extract_booking_time_from_item: Checking attribute '{$clean_attr_name}' with value: {$attr_value}");
                         $parsed_time = self::parse_time_value($attr_value);
                         if ($parsed_time) {
                              // self::log_debug("extract_booking_time_from_item: Parsed time from attribute '{$clean_attr_name}' as: {$parsed_time}");
                             return $parsed_time;
                         }
                     }
                 }
             }
        }

        // self::log_debug('extract_booking_time_from_item: No time found.');
        return null; // No time found
    }
    /**
     * Get date from FooEvents booking_date_id
     *
     * @param string $booking_date_id The booking date ID from FooEvents
     * @return string|null Date in Y-m-d format or null if not found
     */
    public static function get_date_from_booking_date_id($booking_date_id) {
        if (empty($booking_date_id)) {
            return null;
        }
        
        global $wpdb;
        
        // First check: Try to find entries for this product directly
        self::log_debug("Looking for date using booking_date_id: " . $booking_date_id);
        
        // Find all products with booking options
        $products_with_booking = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = 'fooevents_bookings_options_serialized' 
             AND meta_value IS NOT NULL 
             AND meta_value != ''"
        );
        
        foreach ($products_with_booking as $product_id) {
            $bookings_options = get_post_meta($product_id, 'fooevents_bookings_options_serialized', true);
            
            if (!empty($bookings_options)) {
                // The data appears to be JSON in the database
                $bookings_data = json_decode($bookings_options, true);
                
                if (is_array($bookings_data)) {
                    // Check if booking_date_id is a slot ID (first level)
                    if (isset($bookings_data[$booking_date_id])) {
                        self::log_debug("Found booking_date_id as a slot ID: " . $booking_date_id);
                        
                        // For slot IDs, grab the first date in the add_date array
                        if (isset($bookings_data[$booking_date_id]['add_date']) && is_array($bookings_data[$booking_date_id]['add_date'])) {
                            $first_date = reset($bookings_data[$booking_date_id]['add_date']);
                            if (isset($first_date['date'])) {
                                self::log_debug("Using first date from slot: " . $first_date['date']);
                                return self::parse_date_value($first_date['date']);
                            }
                        }
                    }
                    
                    // Check each slot and its dates (second level)
                    foreach ($bookings_data as $slot_id => $slot_info) {
                        if (isset($slot_info['add_date']) && is_array($slot_info['add_date'])) {
                            // Check if this slot has our booking date ID
                            if (isset($slot_info['add_date'][$booking_date_id])) {
                                $date_info = $slot_info['add_date'][$booking_date_id];
                                if (isset($date_info['date'])) {
                                    self::log_debug("Found date via slot: " . $slot_id . ", date: " . $date_info['date']);
                                    return self::parse_date_value($date_info['date']);
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Second check: If we couldn't find it above, try finding the specific product from an order
        $order_item_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                 WHERE meta_key = 'booking_date_id' 
                 AND meta_value = %s 
                 LIMIT 1",
                $booking_date_id
            )
        );
        
        if ($order_item_id) {
            // Get product_id and slot_id from order item
            $product_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                     WHERE order_item_id = %d 
                     AND meta_key = '_product_id'", 
                    $order_item_id
                )
            );
            
            $slot_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta 
                     WHERE order_item_id = %d 
                     AND meta_key = 'booking_slot_id'", 
                    $order_item_id
                )
            );
            
            self::log_debug("Found order_item_id: " . $order_item_id . ", product_id: " . $product_id . ", slot_id: " . $slot_id);
            
            if ($product_id) {
                $bookings_options = get_post_meta($product_id, 'fooevents_bookings_options_serialized', true);
                
                if (!empty($bookings_options)) {
                    $bookings_data = json_decode($bookings_options, true);
                    
                    if (is_array($bookings_data)) {
                        // If we have a slot_id, check that slot specifically
                        if (!empty($slot_id) && isset($bookings_data[$slot_id]['add_date'][$booking_date_id]['date'])) {
                            $date = $bookings_data[$slot_id]['add_date'][$booking_date_id]['date'];
                            self::log_debug("Found date via known slot_id and booking_date_id: " . $date);
                            return self::parse_date_value($date);
                        }
                        
                        // Otherwise check all slots
                        foreach ($bookings_data as $slot_id => $slot_info) {
                            if (isset($slot_info['add_date']) && isset($slot_info['add_date'][$booking_date_id])) {
                                if (isset($slot_info['add_date'][$booking_date_id]['date'])) {
                                    $date = $slot_info['add_date'][$booking_date_id]['date'];
                                    self::log_debug("Found date in product: " . $product_id . ", slot: " . $slot_id . ", date: " . $date);
                                    return self::parse_date_value($date);
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Third check: As a last resort, check if the booking_date_id contains a date (unlikely but possible)
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $booking_date_id, $matches)) {
            $potential_date = $matches[1];
            self::log_debug("Found potential date encoded in the booking_date_id itself: " . $potential_date);
            return $potential_date;
        }
        
        self::log_debug("Could not find date for booking_date_id: " . $booking_date_id . " after trying all methods.");
        return null;
    }
    
    /**
     * Log a debug message to the standard PHP error log (and WP debug.log if enabled)
     *
     * @param string $message Message to log
     * @param mixed $data Optional data to include (will be print_r'd)
     */
    public static function log_debug($message, $data = null) {
        // Check if WP_DEBUG and WP_DEBUG_LOG are enabled
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_entry = "[" . date('Y-m-d H:i:s') . "] BRCC Debug: " . $message;
            if ($data !== null) {
                // Use var_export for potentially better readability than print_r
                $log_entry .= "\nData: " . var_export($data, true);
            }
            // Ensure error_log is called correctly
            error_log($log_entry);
        }
    } // End log_debug
} // End class BRCC_Helpers
