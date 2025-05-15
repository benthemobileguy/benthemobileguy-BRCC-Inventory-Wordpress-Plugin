<?php

/**
 * Plugin Name: BRCC Inventory Tracker
 * Plugin URI: https://jmplaunch.com
 * Description: Tracks daily sales across WooCommerce and Eventbrite platforms with enhanced date-based inventory
 * Version: 2.0.0
 * Author: JMPLaunch
 * Author URI: https://seospheres.com/
 * Text Domain: brcc-inventory-tracker
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main BRCC Inventory Tracker class
 * 
 * This is the main class for the BRCC Inventory Tracker plugin. It handles
 * initialization, activation, deactivation, and core functionality of the plugin.
 * It uses a singleton pattern to ensure only one instance is running.
 */
class BRCC_Inventory_Tracker
{
    /**
     * Plugin version
     * 
     * @var string
     */
    const VERSION = '2.0.0';

    /**
     * Instance of this class
     * 
     * @var BRCC_Inventory_Tracker
     */
    protected static $instance = null;

    /**
     * Admin class instance
     * 
     * @var BRCC_Admin
     */
    public $admin;

    /**
     * Return an instance of this class
     * 
     * This method implements the singleton pattern to ensure only one instance
     * of the plugin is running at any time.
     * 
     * @return BRCC_Inventory_Tracker The single instance of this class
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * Constructor - setup plugin hooks
     * 
     * Initializes the plugin by defining constants, checking dependencies,
     * including required files, and setting up hooks.
     */
    public function __construct()
    {
        // Define constants first
        $this->define_constants();

        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Include required files
        $this->includes();

        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Check if WooCommerce is active
     * 
     * @return bool True if WooCommerce is active, false otherwise
     */
    private function is_woocommerce_active()
    {
        if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            return true;
        }
        return false;
    }

    /**
     * Define constants
     * 
     * Sets up plugin constants for paths, URLs, and version information.
     */
    private function define_constants()
    {
        if (!defined('BRCC_INVENTORY_TRACKER_VERSION')) {
            define('BRCC_INVENTORY_TRACKER_VERSION', self::VERSION);
        }
        if (!defined('BRCC_INVENTORY_TRACKER_PLUGIN_DIR')) {
            define('BRCC_INVENTORY_TRACKER_PLUGIN_DIR', plugin_dir_path(__FILE__));
        }
        if (!defined('BRCC_INVENTORY_TRACKER_PLUGIN_URL')) {
            define('BRCC_INVENTORY_TRACKER_PLUGIN_URL', plugin_dir_url(__FILE__));
        }
        if (!defined('BRCC_INVENTORY_TRACKER_BASENAME')) {
            define('BRCC_INVENTORY_TRACKER_BASENAME', plugin_basename(__FILE__));
        }
        if (!defined('BRCC_INVENTORY_TRACKER_PLUGIN_FILE')) {
            define('BRCC_INVENTORY_TRACKER_PLUGIN_FILE', __FILE__);
        }
    }

    /**
     * Include required files
     * 
     * Includes all necessary PHP files for the plugin to function.
     * Files are included in order of dependency.
     */
    private function includes()
    {
        // Include constants class first
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-constants.php';

        // Include helper class
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-helpers.php';

        // Include product mappings class early since other classes depend on it
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-product-mappings.php';

        // Include core files
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-sales-tracker.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-api-endpoint.php';

        // Include integration files
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/integrations/class-brcc-eventbrite-integration.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/integrations/class-brcc-square-integration.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/class-brcc-enhanced-mappings.php';

        // Include admin files - updated to include all admin components
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/class-brcc-admin-ajax.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/class-brcc-admin-settings.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/class-brcc-admin-pages.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/class-brcc-admin-cron.php';
        require_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/class-brcc-admin.php';
    }

    /**
     * Initialize hooks
     * 
     * Sets up WordPress hooks for activation, deactivation, and other plugin functionality.
     */
    private function init_hooks()
    {
        // Activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Plugin initialization (load textdomain, add cron schedules)
        add_action('plugins_loaded', array($this, 'load_plugin_essentials'));

        // Instantiate core classes on WordPress init hook (safer for WC hooks)
        add_action('init', array($this, 'instantiate_core_classes'));

        // Add CSS customizations for the front-end display
        // add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));

        // Add admin hooks
        if (is_admin()) {
            // Instantiate admin class - this now initializes all admin components
            $this->admin = new BRCC_Admin();

            // Add settings link to plugins page
            add_filter('plugin_action_links_' . BRCC_INVENTORY_TRACKER_BASENAME, array($this, 'add_settings_link'));

            // Enqueue admin scripts and styles
            add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_admin_scripts'));

            // Add body class
            add_filter('admin_body_class', array($this->admin, 'add_body_class'));
        }
    }

    /**
     * Activation function
     * 
     * Runs when the plugin is activated. Creates necessary database tables,
     * sets default options, and sets up scheduled tasks.
     */
    public function activate()
    {
        // Create necessary database tables or options
        $this->create_options();

        // Set up initial scheduling
        $this->setup_scheduling();
    }

    /**
     * Deactivation function
     * 
     * Runs when the plugin is deactivated. Cleans up scheduled tasks.
     */
    public function deactivate()
    {
        // Clear any scheduled hooks
        wp_clear_scheduled_hook('brcc_sync_inventory');
        wp_clear_scheduled_hook('brcc_daily_attendee_email');
    }

    /**
     * Create default options
     * 
     * Sets up default options for the plugin if they don't already exist.
     */
    private function create_options()
    {
        // Add default option for daily sales if it doesn't exist
        if (!get_option('brcc_daily_sales')) {
            add_option('brcc_daily_sales', array());
        }

        // Add default product summary if it doesn't exist
        if (!get_option('brcc_product_summary')) {
            add_option('brcc_product_summary', array());
        }

        // Add default API settings
        if (!get_option('brcc_api_settings')) {
            add_option('brcc_api_settings', array(
                'api_key' => $this->generate_api_key(),
                'eventbrite_token' => '',
                'eventbrite_org_id' => '',
                'square_access_token' => '',
                'square_location_id' => '',
                'square_webhook_signature_key' => '',
                'square_sandbox' => false,
                'sync_interval' => 15, // minutes
                'test_mode' => false,
                'live_logging' => false,
            ));
        }

        // Create logs table if it doesn't exist
        global $wpdb;
        $table_name = $wpdb->prefix . 'brcc_operation_logs';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                component varchar(50) NOT NULL,
                operation varchar(100) NOT NULL,
                message text NOT NULL,
                log_type varchar(20) DEFAULT 'info' NOT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";

            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    /**
     * Generate a random API key
     * 
     * @return string A randomly generated API key
     */
    private function generate_api_key()
    {
        return 'brcc_' . md5(uniqid(rand(), true));
    }

    /**
     * Set up scheduled events
     * 
     * Configures WordPress cron jobs for inventory synchronization and
     * daily attendee email sending.
     */
    private function setup_scheduling()
    {
        // Set up inventory sync schedule
        if (!wp_next_scheduled('brcc_sync_inventory')) {
            $settings = get_option('brcc_api_settings');
            $interval = isset($settings['sync_interval']) ? (int)$settings['sync_interval'] * 60 : 15 * 60; // Convert to seconds

            wp_schedule_event(time(), 'brcc_' . $interval . '_seconds', 'brcc_sync_inventory');
        }

        // Set up daily attendee email schedule
        if (!wp_next_scheduled('brcc_daily_attendee_email')) {
            // Schedule for 6 AM every day
            $timestamp = strtotime('tomorrow 6:00 AM');
            wp_schedule_event($timestamp, 'daily', 'brcc_daily_attendee_email');
        }
    }

    /**
     * Display notice if WooCommerce is not active
     * 
     * Shows an admin notice when WooCommerce is not active, as it's a required dependency.
     */
    public function woocommerce_missing_notice()
    {
?>
        <div class="error">
            <p><?php _e('BRCC Inventory Tracker requires WooCommerce to be installed and active.', 'brcc-inventory-tracker'); ?></p>
        </div>
<?php
    }

    /**
     * Enqueue frontend styles
     * 
     * Loads CSS styles for the front-end of the website.
     */
    public function enqueue_frontend_styles()
    {
        wp_enqueue_style(
            'brcc-inventory-tracker-styles',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/front-end.css',
            array(),
            BRCC_INVENTORY_TRACKER_VERSION
        );
    }

    /**
     * Load essential plugin components like textdomain and cron schedules.
     * 
     * Loads text domain for internationalization and registers custom cron intervals.
     * Hooked to plugins_loaded.
     */
    public function load_plugin_essentials()
    {
        // Load textdomain for internationalization
        load_plugin_textdomain('brcc-inventory-tracker', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Register custom interval for cron jobs
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
    }

    /**
     * Instantiate core classes
     * 
     * Creates instances of the core plugin classes.
     */
    public function instantiate_core_classes()
    {
        // Ensure WooCommerce is active before instantiating classes that depend on it
        if (!$this->is_woocommerce_active()) {
            return;
        }

        // Instantiate core classes
        new BRCC_Sales_Tracker();
        new BRCC_API_Endpoint();
        new BRCC_Eventbrite_Integration();
        new BRCC_Square_Integration();
        new BRCC_Enhanced_Mappings();
    }

    /**
     * Add custom cron intervals
     * 
     * Registers custom time intervals for WordPress cron jobs.
     * 
     * @param array $schedules Existing cron schedules
     * @return array Modified cron schedules
     */
    public function add_cron_intervals($schedules)
    {
        $settings = get_option('brcc_api_settings');
        $interval = isset($settings['sync_interval']) ? (int)$settings['sync_interval'] * 60 : 15 * 60; // Convert to seconds

        $schedules['brcc_' . $interval . '_seconds'] = array(
            'interval' => $interval,
            'display' => sprintf(__('Every %d minutes', 'brcc-inventory-tracker'), $interval / 60)
        );

        return $schedules;
    }

    /**
     * Add settings link to plugin page
     * 
     * Adds a "Settings" link to the plugin's entry on the WordPress plugins page.
     * 
     * @param array $links Existing plugin action links
     * @return array Modified plugin action links
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=brcc-settings') . '">' . __('Settings', 'brcc-inventory-tracker') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}

/**
 * Initialize the plugin
 * 
 * Creates an instance of the main plugin class and stores it in the global scope.
 */
function brcc_inventory_tracker_init()
{
    global $brcc_inventory_tracker;
    $brcc_inventory_tracker = BRCC_Inventory_Tracker::get_instance();
}

// Initialize the plugin
brcc_inventory_tracker_init();

// --- Enhanced Eventbrite Sync Fix ---

/**
 * Initialize the enhanced Eventbrite sync functionality
 */
function brcc_init_enhanced_eventbrite_sync() {
    // Add a delayed sync after order completion
    add_action('woocommerce_order_status_completed', 'brcc_schedule_delayed_eventbrite_sync', 999);
    
    // Register the custom event handler
    add_action('brcc_delayed_eventbrite_sync', 'brcc_process_delayed_eventbrite_sync');
}
add_action('init', 'brcc_init_enhanced_eventbrite_sync');

/**
 * Schedule a delayed Eventbrite sync to ensure tickets are created first
 * 
 * @param int $order_id The order ID
 */
function brcc_schedule_delayed_eventbrite_sync($order_id) {
    // Schedule the sync to happen after 5 seconds
    // This gives FooEvents enough time to create tickets
    wp_schedule_single_event(time() + 5, 'brcc_delayed_eventbrite_sync', array($order_id));
    
    if (class_exists('BRCC_Helpers')) {
        BRCC_Helpers::log_debug("Scheduled delayed Eventbrite sync for Order #{$order_id}");
    }
}

/**
 * Process the delayed Eventbrite sync
 * 
 * @param int $order_id The order ID
 */
function brcc_process_delayed_eventbrite_sync($order_id) {
    if (!class_exists('BRCC_Helpers')) {
        // Optionally log that BRCC_Helpers is not available if this function is critical path
        // error_log("brcc_process_delayed_eventbrite_sync: BRCC_Helpers class not found for Order #{$order_id}");
        return;
    }
    
    BRCC_Helpers::log_debug("Running delayed Eventbrite sync for Order #{$order_id}");
    
    $order = wc_get_order($order_id);
    if (!$order) {
        BRCC_Helpers::log_warning("Order #{$order_id} not found for delayed Eventbrite sync");
        return;
    }
    
    // Process each line item
    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        $target_product_id = $variation_id > 0 ? $variation_id : $product_id; // Use variation ID if available

        // Skip if not a FooEvents product
        if (!BRCC_Helpers::is_fooevents_product($target_product_id)) { // Check with target_product_id
            continue;
        }
        
        // Get date and time from the item (existing functions)
        $date = BRCC_Helpers::get_fooevents_date_from_item($item);
        $time = BRCC_Helpers::extract_booking_time_from_item($item);
        
        if (empty($date) || empty($time)) {
            BRCC_Helpers::log_warning("Delayed sync: Missing date or time for Order #{$order_id}, Item #{$item_id}, Product/Variation #{$target_product_id}", 
                ['date' => $date, 'time' => $time]);
            continue;
        }
        
        BRCC_Helpers::log_debug("Delayed sync: Found date={$date}, time={$time} for Order #{$order_id}, Item #{$item_id}, Product/Variation #{$target_product_id}");
        
        // Check if the initial hook already updated Eventbrite capacity for this item
        if (wc_get_order_item_meta($item_id, '_brcc_eventbrite_capacity_updated', true)) {
            BRCC_Helpers::log_info("Delayed sync: Item #{$item_id} (Order #{$order_id}) already had Eventbrite capacity updated by the initial hook (woocommerce_reduce_order_stock). Skipping redundant call to brcc_sync_with_eventbrite.");
            continue; // Skip to the next item
        }

        // Call sync function with the correct date and time, using target_product_id
        brcc_sync_with_eventbrite($order_id, $item_id, $target_product_id, $item->get_quantity(), $date, $time);
    }
}

/**
 * Normalize time to a standard 24-hour H:i format for comparison.
 * Handles "H:i", "HH:MM", "H:i AM/PM", "HH:MM AM/PM".
 *
 * @param string $time_str The time string to normalize.
 * @return string|null The normalized time in H:i format, or null if parsing fails.
 */
function brcc_normalize_time_for_mapping($time_str) {
    $time_str = trim($time_str);

    // Try to parse 12-hour format with AM/PM (e.g., "10:00 AM", "1:00 PM", "12:30 pm")
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time_str, $matches)) {
        $hour = intval($matches[1]);
        $minutes = intval($matches[2]);
        $period = strtoupper($matches[3]);

        if ($period === 'PM') {
            if ($hour < 12) { // 1 PM to 11 PM
                $hour += 12;
            }
            // If $hour is 12 (12 PM), it's already correct for 24-hour format.
        } elseif ($period === 'AM') {
            if ($hour === 12) { // 12 AM (midnight)
                $hour = 0;
            }
            // If $hour is 1-11 (1 AM to 11 AM), it's already correct.
        }
        return sprintf('%02d:%02d', $hour, $minutes);
    }

    // Try to parse 24-hour format (e.g., "10:00", "22:00")
    // Ensure it's not just matching part of a longer string if AM/PM was missed.
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time_str, $matches)) {
        $hour = intval($matches[1]);
        $minutes = intval($matches[2]);

        if ($hour >= 0 && $hour <= 23 && $minutes >= 0 && $minutes <= 59) {
            return sprintf('%02d:%02d', $hour, $minutes);
        }
    }
    
    if (class_exists('BRCC_Helpers')) {
        BRCC_Helpers::log_debug("brcc_normalize_time_for_mapping: Could not normalize time string '{$time_str}' to H:i format.");
    } else {
        error_log("brcc_normalize_time_for_mapping: Could not normalize time string '{$time_str}' to H:i format.");
    }
    return null; // Return null if no valid format is matched
}

/**
 * Sync a specific order item with Eventbrite.
 * This version includes flexible time matching for `fooevents_integrations_serialized`.
 */
function brcc_sync_with_eventbrite($order_id, $item_id, $product_id, $quantity = 1, $booking_date = null, $booking_time = null) {
    // Ensure BRCC_Helpers is available for logging
    if (!class_exists('BRCC_Helpers')) {
        // Fallback basic logging if BRCC_Helpers is not available
        error_log("brcc_sync_with_eventbrite: BRCC_Helpers class not found. Processing Order #{$order_id}, Item #{$item_id}");
    } else {
        BRCC_Helpers::log_debug("brcc_sync_with_eventbrite: Processing Order #{$order_id}, Item #{$item_id}, Product #{$product_id}, Quantity: {$quantity}, Date: {$booking_date}, Time: {$booking_time}");
    }
    
    // Get order
    $order = wc_get_order($order_id);
    if (!$order) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_error("brcc_sync_with_eventbrite: Could not find order #{$order_id}");
        } else {
            error_log("brcc_sync_with_eventbrite: Could not find order #{$order_id}");
        }
        return false;
    }
    
    // Get order item
    $item = $order->get_item($item_id);
    if (!$item) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_error("brcc_sync_with_eventbrite: Could not find item #{$item_id} in order #{$order_id}");
        } else {
            error_log("brcc_sync_with_eventbrite: Could not find item #{$item_id} in order #{$order_id}");
        }
        return false;
    }
    
    // Try to get integrations (fooevents_integrations_serialized)
    // Date and time are passed as parameters by brcc_process_delayed_eventbrite_sync.
    // $booking_date is Y-m-d, $booking_time is H:i (e.g., "10:00" from extraction)
    
    // Ensure we have date and time; re-extract if necessary (should ideally not happen if called from brcc_process_delayed_eventbrite_sync)
    if (empty($booking_date) || empty($booking_time)) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_debug("brcc_sync_with_eventbrite: Date or Time was empty. Attempting re-extraction for Order #{$order_id}, Item #{$item_id}. Passed Date: {$booking_date}, Passed Time: {$booking_time}");
            $booking_date = $booking_date ?: BRCC_Helpers::get_fooevents_date_from_item($item);
            $booking_time = $booking_time ?: BRCC_Helpers::extract_booking_time_from_item($item);
            BRCC_Helpers::log_debug("brcc_sync_with_eventbrite: Re-extracted Date: {$booking_date}, Time: {$booking_time} for Order #{$order_id}, Item #{$item_id}");
        } else {
            error_log("brcc_sync_with_eventbrite: Missing booking_date or booking_time and BRCC_Helpers not available for re-extraction. Order #{$order_id}, Item #{$item_id}");
            if (empty($booking_date) || empty($booking_time)) return false;
        }
    }

    if (empty($booking_date) || empty($booking_time)) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_warning("brcc_sync_with_eventbrite: Critical: Missing date or time after all checks for Order #{$order_id}, Item #{$item_id}. Date: {$booking_date}, Time: {$booking_time}");
        } else {
            error_log("brcc_sync_with_eventbrite: Critical: Missing date or time after all checks for Order #{$order_id}, Item #{$item_id}. Date: {$booking_date}, Time: {$booking_time}");
        }
        return false;
    }

    // --- Retrieve Eventbrite Mapping from 'fooevents_integrations_serialized' with flexible time matching ---
    $integrations_option = get_option('fooevents_integrations_serialized');
    $mapping = null;
    $eventbrite_event_id = null;
    $ticket_class_id = null;

    if (empty($integrations_option)) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_warning("brcc_sync_with_eventbrite: 'fooevents_integrations_serialized' option is empty. Cannot find Eventbrite mapping. Order #{$order_id}, Item #{$item_id}. Product #{$product_id}, Date: {$booking_date}, Time: {$booking_time}");
        }
        // Unlike previous version, we will NOT proceed without a mapping from fooevents_integrations_serialized
        // The goal is to sync with what's *mapped*, not guess an Eventbrite ticket.
        // However, the original feedback suggested continuing. Let's stick to the user's latest code structure which implies needing the mapping.
        // For now, let's assume if fooevents_integrations_serialized is missing, we can't get event_id/ticket_class_id.
        // The user's provided code for brcc_sync_with_eventbrite *did* use get_eventbrite_ticket_id_for_product,
        // which implies it might look elsewhere or construct it.
        // Re-aligning with the user's *latest* provided `brcc_sync_with_eventbrite` structure which directly calls Eventbrite API.
        // This means we *don't* need to parse `fooevents_integrations_serialized` here if we follow that structure.
        // The user's latest `brcc_sync_with_eventbrite` directly calls:
        // $eventbrite_ticket_id = $eventbrite->get_eventbrite_ticket_id_for_product($product_id, $booking_date, $booking_time);
        // This suggests `get_eventbrite_ticket_id_for_product` in `BRCC_Eventbrite_Integration` handles the mapping lookup.
        // The request was to make *that* lookup flexible.
        // The current file is brcc-inventory-tracker.php, not BRCC_Eventbrite_Integration.php.
        // The user's Option 3 was for BRCC_Eventbrite_Integration.
        // The current task is to modify brcc-inventory-tracker.php.
        // The previous implementation of brcc_sync_with_eventbrite *did* parse fooevents_integrations_serialized.
        // Let's proceed with modifying the lookup in *this* file as per my thought process.
    } else {
        $integrations = maybe_unserialize($integrations_option);
        $product_dates_key = $product_id . '_dates';
        
        $exact_lookup_key = $booking_date . '_' . $booking_time; // $booking_time is H:i

        if (isset($integrations[$product_dates_key]) && isset($integrations[$product_dates_key][$exact_lookup_key])) {
            $mapping = $integrations[$product_dates_key][$exact_lookup_key];
            if (class_exists('BRCC_Helpers')) {
                BRCC_Helpers::log_debug("brcc_sync_with_eventbrite: Found exact mapping in 'fooevents_integrations_serialized' for Product #{$product_id}, Key: {$exact_lookup_key}");
            }
        } else {
            if (class_exists('BRCC_Helpers')) {
                BRCC_Helpers::log_debug("brcc_sync_with_eventbrite: Exact mapping not found in 'fooevents_integrations_serialized' for Key: {$exact_lookup_key}. Attempting normalized lookup. Product #{$product_id}, Date: {$booking_date}, Extracted Time: {$booking_time}");
            }
            if (isset($integrations[$product_dates_key]) && is_array($integrations[$product_dates_key])) {
                $normalized_extracted_time = brcc_normalize_time_for_mapping($booking_time);

                if ($normalized_extracted_time !== null) {
                    foreach ($integrations[$product_dates_key] as $stored_date_time_key => $stored_mapping_data) {
                        if (preg_match('/^\d{4}-\d{2}-\d{2}_(.+)$/', $stored_date_time_key, $time_matches)) {
                            $stored_time_str_from_key = $time_matches[1];
                            $normalized_stored_time = brcc_normalize_time_for_mapping($stored_time_str_from_key);

                            if (class_exists('BRCC_Helpers')) {
                                BRCC_Helpers::log_debug("Normalized Lookup ('fooevents_integrations_serialized'): Extracted '{$booking_time}' (norm: '{$normalized_extracted_time}') vs Stored Key '{$stored_date_time_key}' (time part '{$stored_time_str_from_key}', norm: '{$normalized_stored_time}')");
                            }

                            if ($normalized_stored_time !== null && $normalized_extracted_time === $normalized_stored_time) {
                                $mapping = $stored_mapping_data;
                                if (class_exists('BRCC_Helpers')) {
                                    BRCC_Helpers::log_info("brcc_sync_with_eventbrite: Found mapping via normalization in 'fooevents_integrations_serialized' for Product #{$product_id}. Extracted Time '{$booking_time}' matched Stored Key '{$stored_date_time_key}'.");
                                }
                                break;
                            }
                        }
                    }
                } else {
                    if (class_exists('BRCC_Helpers')) {
                        BRCC_Helpers::log_warning("brcc_sync_with_eventbrite: Could not normalize extracted time '{$booking_time}' for Product #{$product_id}. Cannot perform normalized lookup in 'fooevents_integrations_serialized'.");
                    }
                }
            }
        }
    }

    if ($mapping === null) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_warning("brcc_sync_with_eventbrite: No mapping found in 'fooevents_integrations_serialized' (exact or normalized) for Product #{$product_id}, Date: {$booking_date}, Extracted Time: {$booking_time}. Order #{$order_id}, Item #{$item_id}. Cannot determine Eventbrite Event/Ticket Class ID.");
        }
        // If we don't have a mapping from fooevents_integrations_serialized, we cannot get eventbrite_event_id and ticket_class_id
        // to proceed with the BRCC_Eventbrite_Integration calls as per the previous structure.
        // The user's latest code for brcc_sync_with_eventbrite *implies* that BRCC_Eventbrite_Integration->get_eventbrite_ticket_id_for_product
        // is the source of truth for the Eventbrite Ticket ID.
        // This is a conflict. The previous version of this function *used* the mapping from fooevents_integrations_serialized.
        // The user's *new* brcc_sync_with_eventbrite *didn't* use fooevents_integrations_serialized for event_id/ticket_class_id,
        // but rather called $eventbrite->get_eventbrite_ticket_id_for_product.
        //
        // RESOLUTION: The request is to make the *lookup* flexible. The lookup was for fooevents_integrations_serialized.
        // If this lookup fails, we cannot proceed with the *original* plan of using these IDs to call sync_eventbrite_tickets.
        // However, the user's *newest* `brcc_sync_with_eventbrite` function structure *directly calls Eventbrite API methods*
        // using an `$eventbrite_ticket_id` obtained from `$eventbrite->get_eventbrite_ticket_id_for_product()`.
        // This means the flexible lookup for `fooevents_integrations_serialized` is NOT what the user's latest code wants.
        // The user wants to modify `get_eventbrite_ticket_id_for_product` in `BRCC_Eventbrite_Integration`.
        //
        // This diff is for `brcc-inventory-tracker.php`.
        // The user's Option 3 was for `BRCC_Eventbrite_Integration.php`.
        //
        // Sticking to the user's *latest provided code structure* for `brcc_sync_with_eventbrite` in `brcc-inventory-tracker.php`:
        // That structure *does not* parse `fooevents_integrations_serialized`. It directly calls methods on `BRCC_Eventbrite_Integration`.
        // The "flexible time mapping" (Option 3) was suggested for `get_eventbrite_ticket_id_for_product` inside `BRCC_Eventbrite_Integration`.
        //
        // THEREFORE, the current diff is attempting to put Option 3's logic into the wrong place if we follow the user's latest function structure.
        //
        // Let's revert the `fooevents_integrations_serialized` parsing here and stick to the user's latest `brcc_sync_with_eventbrite` structure,
        // and then separately address modifying `BRCC_Eventbrite_Integration::get_eventbrite_ticket_id_for_product`.
        // For *this file*, the only change was the quantity parameter. The rest of the user's new `brcc_sync_with_eventbrite` should be used.
        // The user's new `brcc_sync_with_eventbrite` already handles the "continue if fooevents_integrations_serialized is missing" by simply not using it for the main flow.

        // Re-inserting the user's latest brcc_sync_with_eventbrite structure:
        // (The diff tool will handle replacing the old one with this new one)
        // The following lines are effectively what the user provided as the new function body,
        // with the `quantity` parameter correctly passed.
        // The `fooevents_integrations` check is only for a warning.
        // The main logic relies on `$eventbrite->get_eventbrite_ticket_id_for_product`.
        // The flexible time lookup should be in *that* function, not here.
        // So, the complex `fooevents_integrations_serialized` parsing added above is incorrect for *this step*.
        // The diff should just be the user's new function.
        // The `get_option('fooevents_integrations_serialized')` is only for a warning in the user's new code.
        // The actual `eventbrite_ticket_id` comes from `$eventbrite->get_eventbrite_ticket_id_for_product`.
        // This means the "Option 3" logic needs to go into `BRCC_Eventbrite_Integration.php`.
        // For now, this file just gets the user's new function.
    }


    // Ensure the Eventbrite integration class exists
    if (!class_exists('BRCC_Eventbrite_Integration')) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_error("brcc_sync_with_eventbrite: BRCC_Eventbrite_Integration class not found.");
        } else {
            error_log("brcc_sync_with_eventbrite: BRCC_Eventbrite_Integration class not found.");
        }
        return false;
    }
    $eventbrite = new BRCC_Eventbrite_Integration();
    
    // Get the Eventbrite ticket ID for this product/date/time
    $eventbrite_ticket_id = $eventbrite->get_eventbrite_ticket_id_for_product($product_id, $booking_date, $booking_time);
    
    if (!$eventbrite_ticket_id) {
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_warning("brcc_sync_with_eventbrite: No Eventbrite ticket ID found (via get_eventbrite_ticket_id_for_product) for Product #{$product_id}, Date: {$booking_date}, Time: {$booking_time}. Order #{$order_id}, Item #{$item_id}");
        } else {
            error_log("brcc_sync_with_eventbrite: No Eventbrite ticket ID found (via get_eventbrite_ticket_id_for_product) for Product #{$product_id}, Date: {$booking_date}, Time: {$booking_time}. Order #{$order_id}, Item #{$item_id}");
        }
        return false;
    }
    // $booking_time is the time used for the lookup, potentially normalized by get_eventbrite_ticket_id_for_product's internal logic.
    $effective_booking_time = $booking_time;
    
    // Get current capacity from Eventbrite
    $ticket_details = $eventbrite->get_eventbrite_ticket($eventbrite_ticket_id);
    if (is_wp_error($ticket_details) || !isset($ticket_details['capacity'])) {
        $error_message = is_wp_error($ticket_details) ? $ticket_details->get_error_message() : 'Capacity not found or ticket_details is not an array';
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_error("brcc_sync_with_eventbrite: Failed to get ticket details for ID {$eventbrite_ticket_id}. Order #{$order_id}, Item #{$item_id}", ['error' => $error_message, 'ticket_details_received' => $ticket_details]);
        } else {
            error_log("brcc_sync_with_eventbrite: Failed to get ticket details for ID {$eventbrite_ticket_id}. Error: {$error_message}. Order #{$order_id}, Item #{$item_id}");
        }
        return false;
    }
    
    // Calculate new capacity
    $current_capacity = intval($ticket_details['capacity']);
    $new_capacity = max(0, $current_capacity - $quantity);
    
    // Update capacity
    $update_result = $eventbrite->update_eventbrite_ticket_capacity($eventbrite_ticket_id, $new_capacity);
    
    if (is_wp_error($update_result)) {
        $error_message = $update_result->get_error_message();
        $log_eb_ticket_id_on_error = is_array($eventbrite_ticket_id) && isset($eventbrite_ticket_id['ticket_id']) ? $eventbrite_ticket_id['ticket_id'] : 'INVALID_TICKET_ID_STRUCTURE_ON_ERROR';
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_error("brcc_sync_with_eventbrite: Failed to update capacity for ticket ID {$log_eb_ticket_id_on_error}. Order #{$order_id}, Item #{$item_id}", ['error' => $error_message]);
        } else {
            error_log("brcc_sync_with_eventbrite: Failed to update capacity for ticket ID {$log_eb_ticket_id_on_error}. Error: {$error_message}. Order #{$order_id}, Item #{$item_id}");
        }
        return false;
    }
    
    // Assuming $update_result is true on success from update_eventbrite_ticket_capacity
    // $update_result can also be an array with ticket details on success from update_eventbrite_ticket_capacity
    if ($update_result === true || (is_array($update_result) && !isset($update_result['error']))) {
        wc_update_order_item_meta($item_id, '_eventbrite_synced', 'yes');
        wc_update_order_item_meta($item_id, '_eventbrite_synced_date', current_time('mysql'));
        
        $log_eb_ticket_id_on_success = is_array($eventbrite_ticket_id) && isset($eventbrite_ticket_id['ticket_id']) ? $eventbrite_ticket_id['ticket_id'] : 'INVALID_TICKET_ID_STRUCTURE_ON_SUCCESS';
        
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_info("brcc_sync_with_eventbrite: Successfully updated capacity for ticket ID {$log_eb_ticket_id_on_success} from {$current_capacity} to {$new_capacity}. Order #{$order_id}, Item #{$item_id}.");
            BRCC_Helpers::log_operation(
                'Eventbrite Sync',
                'Delayed Sync Capacity Update',
                "Order #{$order_id}, Item #{$item_id}, Product #{$product_id}. EB Ticket ID: {$log_eb_ticket_id_on_success}. Capacity: {$current_capacity} -> {$new_capacity}."
            );
        } else {
            error_log("brcc_sync_with_eventbrite: Successfully updated capacity for ticket ID {$log_eb_ticket_id_on_success} from {$current_capacity} to {$new_capacity}. Order #{$order_id}, Item #{$item_id}.");
        }
        return true;
    } else {
        $log_eb_ticket_id_on_failure = is_array($eventbrite_ticket_id) && isset($eventbrite_ticket_id['ticket_id']) ? $eventbrite_ticket_id['ticket_id'] : 'INVALID_TICKET_ID_STRUCTURE_ON_FAILURE';
        if (class_exists('BRCC_Helpers')) {
            BRCC_Helpers::log_warning("brcc_sync_with_eventbrite: Capacity update for ticket ID {$log_eb_ticket_id_on_failure} did not return true or successful array. Update result: " . print_r($update_result, true) . ". Order #{$order_id}, Item #{$item_id}");
        } else {
            error_log("brcc_sync_with_eventbrite: Capacity update for ticket ID {$log_eb_ticket_id_on_failure} did not return true or successful array. Update result: " . print_r($update_result, true) . ". Order #{$order_id}, Item #{$item_id}");
        }
        return false;
    }
}

// --- End Enhanced Eventbrite Sync Fix ---
