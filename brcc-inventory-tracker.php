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
