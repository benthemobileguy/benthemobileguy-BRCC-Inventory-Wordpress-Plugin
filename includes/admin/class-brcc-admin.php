<?php

/**
 * BRCC Admin Class
 * 
 * Coordinates admin components and sets up the admin interface.
 * This class serves as the main coordinator for all admin-related functionality,
 * initializing the specialized admin components and setting up the necessary hooks.
 * It follows a modular design pattern where specific functionality is delegated to
 * specialized classes while this class manages their integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BRCC_Admin
{
    /**
     * Admin components
     * 
     * @var BRCC_Admin_AJAX $ajax_handler Handles all AJAX requests
     * @var BRCC_Admin_Settings $settings_handler Manages settings registration and rendering
     * @var BRCC_Admin_Pages $pages_handler Controls page display logic
     * @var BRCC_Admin_Cron $cron_handler Manages scheduled tasks
     */
    private $ajax_handler;
    private $settings_handler;
    private $pages_handler;
    private $cron_handler;
    
    /**
     * Constructor - initialize components and setup hooks
     * 
     * Instantiates all admin component classes and sets up the necessary WordPress hooks
     * for admin menu, scripts, and actions. This follows a composition pattern where
     * specialized functionality is delegated to dedicated classes.
     */
    public function __construct()
    {
        // Initialize components
        $this->ajax_handler = new BRCC_Admin_AJAX();
        $this->settings_handler = new BRCC_Admin_Settings();
        $this->pages_handler = new BRCC_Admin_Pages();
        $this->cron_handler = new BRCC_Admin_Cron();
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Handle log clearing action
        add_action('admin_init', array($this, 'maybe_clear_logs'));
        add_action('admin_init', array($this, 'maybe_export_csv'));
        
        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(BRCC_INVENTORY_TRACKER_PLUGIN_FILE), 
            array($this, 'add_settings_link'));
            
        // Add body class for admin pages
        add_filter('admin_body_class', array($this, 'add_body_class'));
    }

    /**
     * Add admin menu items
     * 
     * Registers all admin menu and submenu pages for the plugin.
     * Each page is associated with a display method from the pages handler class.
     */
    public function add_admin_menu()
    {
        // Main menu
        add_menu_page(
            __('BRCC Inventory', 'brcc-inventory-tracker'),
            __('BRCC Inventory', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-inventory',
            array($this->pages_handler, 'display_dashboard_page'),
            'dashicons-chart-area',
            56
        );
        
        // Dashboard submenu
        add_submenu_page(
            'brcc-inventory',
            __('Dashboard', 'brcc-inventory-tracker'),
            __('Dashboard', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-inventory',
            array($this->pages_handler, 'display_dashboard_page')
        );
        
        // Daily Sales submenu
        add_submenu_page(
            'brcc-inventory',
            __('Daily Sales', 'brcc-inventory-tracker'),
            __('Daily Sales', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-daily-sales',
            array($this->pages_handler, 'display_daily_sales_page')
        );
        
        // Settings submenu
        add_submenu_page(
            'brcc-inventory',
            __('Settings', 'brcc-inventory-tracker'),
            __('Settings', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-settings',
            array($this->pages_handler, 'display_settings_page')
        );
        
        // Logs submenu
        add_submenu_page(
            'brcc-inventory',
            __('Operation Logs', 'brcc-inventory-tracker'),
            __('Operation Logs', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-operation-logs',
            array($this->pages_handler, 'display_operation_logs')
        );
        
        // Import Historical Data submenu
        add_submenu_page(
            'brcc-inventory',
            __('Import History', 'brcc-inventory-tracker'),
            __('Import History', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-import-history',
            array($this->pages_handler, 'display_import_history_page')
        );
        
        // Attendee Lists submenu
        add_submenu_page(
            'brcc-inventory',
            __('Attendee Lists', 'brcc-inventory-tracker'),
            __('Attendee Lists', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-attendee-lists',
            array($this->pages_handler, 'display_attendee_list_page')
        );
        
        // Tools submenu
        add_submenu_page(
            'brcc-inventory',
            __('Tools', 'brcc-inventory-tracker'),
            __('Tools', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-tools',
            array($this->pages_handler, 'display_tools_page')
        );
    }

    /**
     * Enqueue admin scripts and styles
     * 
     * Loads JavaScript and CSS files needed for the admin interface.
     * Only loads on plugin-specific admin pages to avoid conflicts.
     * 
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load our scripts on our plugin pages
        if (strpos($hook, 'brcc-inventory') === false && 
            strpos($hook, 'brcc-settings') === false && 
            strpos($hook, 'brcc-daily-sales') === false) {
            return;
        }

        // Core WordPress UI Components
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

        // Select2
        wp_enqueue_style(
            'select2-css',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            array(),
            '4.1.0-rc.0'
        );
        wp_enqueue_script(
            'select2-js',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            array('jquery'),
            '4.1.0-rc.0',
            true
        );

        // Chart.js for dashboard charts
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js',
            array(),
            '3.7.1',
            true
        );

        // Main Admin CSS
        wp_enqueue_style(
            'brcc-admin-css',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/admin.css',
            array('wp-jquery-ui-dialog'),
            BRCC_INVENTORY_TRACKER_VERSION
        );
        
        // Enhanced Dashboard CSS (only on main dashboard page)
        if ($hook === 'toplevel_page_brcc-inventory') {
            wp_enqueue_style(
                'brcc-dashboard-enhanced-css',
                BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/dashboard-enhanced.css',
                array('brcc-admin-css'),
                BRCC_INVENTORY_TRACKER_VERSION
            );
        }

        // Main Admin JS
        wp_enqueue_script(
            'brcc-admin-js',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-datepicker', 'jquery-ui-dialog', 'select2-js'),
            BRCC_INVENTORY_TRACKER_VERSION,
            true
        );

        // Date Mappings JS (only on settings page)
        if (strpos($hook, 'brcc-settings') !== false) {
            wp_enqueue_script(
                'brcc-date-mappings-js',
                BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/js/date-mappings.js',
                array('brcc-admin-js'),
                BRCC_INVENTORY_TRACKER_VERSION,
                true
            );
        }

        // Localize scripts with necessary data
        wp_localize_script('brcc-admin-js', 'brcc_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'admin_url' => admin_url('admin.php'),
            'nonce' => wp_create_nonce('brcc-admin-nonce'),
            'regenerate_key_confirm' => __('Are you sure you want to regenerate the API key? Any existing connections using the current key will stop working.', 'brcc-inventory-tracker'),
            'ajax_error' => __('An error occurred. Please try again.', 'brcc-inventory-tracker'),
            'syncing' => __('Syncing...', 'brcc-inventory-tracker'),
            'sync_now' => __('Sync Now', 'brcc-inventory-tracker'),
            'saving' => __('Saving...', 'brcc-inventory-tracker'),
            'save_mappings' => __('Save Mappings', 'brcc-inventory-tracker'),
            'testing' => __('Testing...', 'brcc-inventory-tracker'),
            'test' => __('Test', 'brcc-inventory-tracker'),
            'chart_labels' => __('Sales', 'brcc-inventory-tracker'),
            'suggest' => __('Suggest', 'brcc-inventory-tracker'),
            'suggest_tooltip_date' => __('Suggest Eventbrite Ticket ID based on date/time', 'brcc-inventory-tracker'),
            'select_product_prompt' => __('Please select a product to fetch attendees.', 'brcc-inventory-tracker'),
            'fetching' => __('Fetching...', 'brcc-inventory-tracker'),
            'loading_attendees' => __('Loading attendee data...', 'brcc-inventory-tracker'),
            'col_name' => __('Name', 'brcc-inventory-tracker'),
            'col_email' => __('Email', 'brcc-inventory-tracker'),
            'col_source' => __('Source', 'brcc-inventory-tracker'),
            'col_event_date' => __('Event Date', 'brcc-inventory-tracker'),
            'eventbrite_events' => array(), // Initialize as empty
            'col_purchase_date' => __('Purchase Date', 'brcc-inventory-tracker'),
            'no_attendees_found' => __('No attendees found for this product.', 'brcc-inventory-tracker'),
            'error_fetching_attendees' => __('Error fetching attendees.', 'brcc-inventory-tracker'),
            'fetch_attendees_btn' => __('Fetch Attendees', 'brcc-inventory-tracker'),
            'clearing_cache' => __('Clearing Cache...', 'brcc-inventory-tracker'),
            'clear_cache' => __('Clear Event Cache', 'brcc-inventory-tracker'),
            'loading' => __('Loading...', 'brcc-inventory-tracker'),
            'select_event_prompt' => __('Select Event First...', 'brcc-inventory-tracker'),
            'select_ticket_prompt' => __('Select Ticket...', 'brcc-inventory-tracker'),
            'no_tickets_found' => __('No tickets found', 'brcc-inventory-tracker'),
            'error_loading_tickets' => __('Error loading tickets', 'brcc-inventory-tracker'),
            'select_date_prompt' => __('Please select a date to load attendee lists.', 'brcc-inventory-tracker'),
            // Data for Force Sync Tool
            'search_products_nonce' => wp_create_nonce('search-products'), // Nonce for WC product search
            'select_product_alert' => __('Please select a product.', 'brcc-inventory-tracker'),
            'select_date_alert' => __('Please select an event date.', 'brcc-inventory-tracker'),
            'force_sync_confirm' => __('Are you sure you want to force sync inventory for this product/date? This will overwrite existing WC/EB counts based on recorded sales and cannot be undone.', 'brcc-inventory-tracker')
        ));
    }

    /**
     * Add settings link to plugin page
     * 
     * Adds a direct "Settings" link to the plugin's entry on the WordPress plugins page.
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

    /**
     * Maybe clear logs based on admin action
     * 
     * Checks if the user has requested to clear logs and performs the action if authorized.
     * Adds a success notice after clearing logs.
     */
    public function maybe_clear_logs()
    {
        if (isset($_GET['action']) && $_GET['action'] === 'brcc_clear_logs' && 
            isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'brcc-clear-logs') && 
            current_user_can('manage_options')) {
            
            global $wpdb;
            $table_name = $wpdb->prefix . 'brcc_operation_logs';
            $wpdb->query("TRUNCATE TABLE $table_name");
            
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Operation logs cleared successfully.', 'brcc-inventory-tracker') . '</p></div>';
            });
            
            // Redirect to remove action from URL
            wp_redirect(remove_query_arg(array('action', 'nonce')));
            exit;
        }
    }

    /**
     * Maybe export CSV based on admin action
     * 
     * Checks if the user has requested to export sales data as CSV and performs the action if authorized.
     * Generates and outputs a CSV file with sales data for the selected date.
     */
    public function maybe_export_csv()
    {
        if (isset($_GET['action']) && $_GET['action'] === 'brcc_export_csv' && 
            isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'brcc-export-csv') && 
            current_user_can('manage_options')) {
            
            $date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');
            
            // Get sales data
            $sales_tracker = new BRCC_Sales_Tracker();
            $sales_data = $sales_tracker->get_daily_sales($date, $date);
            
            // Set headers for CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=brcc-sales-' . $date . '.csv');
            
            // Create output stream
            $output = fopen('php://output', 'w');
            
            // Add CSV headers
            fputcsv($output, array(
                __('Date', 'brcc-inventory-tracker'),
                __('Product', 'brcc-inventory-tracker'),
                __('Quantity', 'brcc-inventory-tracker'),
                __('Revenue', 'brcc-inventory-tracker'),
                __('Source', 'brcc-inventory-tracker'),
                __('Time', 'brcc-inventory-tracker')
            ));
            
            // Add data rows
            foreach ($sales_data as $sale) {
                $product_name = '';
                if (!empty($sale['product_id'])) {
                    $product = wc_get_product($sale['product_id']);
                    $product_name = $product ? $product->get_name() : $sale['product_id'];
                } else {
                    $product_name = $sale['product_name'] ?? __('Unknown', 'brcc-inventory-tracker');
                }
                
                $time = !empty($sale['timestamp']) ? date_i18n(get_option('time_format'), $sale['timestamp']) : 'N/A';
                
                fputcsv($output, array(
                    date_i18n(get_option('date_format'), strtotime($sale['sale_date'])),
                    $product_name,
                    $sale['quantity'],
                    $sale['revenue'],
                    $sale['source'],
                    $time
                ));
            }
            
            fclose($output);
            exit;
            
            fclose($output);
            exit;
        }
    }

    /**
     * Add body class to admin pages
     * 
     * Adds a custom CSS class to the body element on plugin admin pages
     * to allow for plugin-specific styling.
     * 
     * @param string $classes Existing body classes
     * @return string Modified body classes
     */
    public function add_body_class($classes)
    {
        $screen = get_current_screen();
        if (strpos($screen->id, 'brcc-inventory') !== false) {
            $classes .= ' brcc-admin-page';
        }
        return $classes;
    }
}
