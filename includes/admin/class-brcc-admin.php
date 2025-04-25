<?php

/**
 * BRCC Admin Class
 * 
 * Handles admin interface for the BRCC Inventory Tracker with date-based inventory support
 */

if (!defined('ABSPATH')) {
    exit;
}

class BRCC_Admin
{
    /**
     * Constructor - setup hooks
     */
    public function __construct()
    {
        // Add menu items
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . BRCC_INVENTORY_TRACKER_BASENAME, array($this, 'add_settings_link'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Handle log clearing action
        add_action('admin_init', array($this, 'maybe_clear_logs'));
        add_action('admin_init', array($this, 'maybe_export_csv')); // Add hook for CSV export

        // AJAX handlers
        add_action('wp_ajax_brcc_regenerate_api_key', array($this, 'ajax_regenerate_api_key'));
        add_action('wp_ajax_brcc_sync_inventory_now', array($this, 'ajax_sync_inventory_now'));
        add_action('wp_ajax_brcc_save_product_mappings', array($this, 'ajax_save_product_mappings'));
        add_action('wp_ajax_brcc_test_product_mapping', array($this, 'ajax_test_product_mapping'));
        add_action('wp_ajax_brcc_test_square_connection', array($this, 'ajax_test_square_connection'));
        add_action('wp_ajax_brcc_get_square_catalog', array($this, 'ajax_get_square_catalog'));
        add_action('wp_ajax_brcc_test_square_mapping', array($this, 'ajax_test_square_mapping'));
        add_action('wp_ajax_brcc_import_batch', array($this, 'ajax_import_batch'));
        add_action('wp_ajax_brcc_suggest_eventbrite_id', array($this, 'ajax_suggest_eventbrite_id'));
        add_action('wp_ajax_brcc_get_all_eventbrite_events_for_attendees', array($this, 'ajax_get_all_eventbrite_events_for_attendees'));
        add_action('wp_ajax_brcc_test_eventbrite_connection', array($this, 'ajax_test_eventbrite_connection'));
        add_action('wp_ajax_brcc_get_product_dates', array($this, 'ajax_get_product_dates'));
        add_action('wp_ajax_brcc_save_product_date_mappings', array($this, 'ajax_save_product_date_mappings'));
        add_action('wp_ajax_brcc_test_product_date_mapping', array($this, 'ajax_test_product_date_mapping'));
        add_action('wp_ajax_brcc_get_products_for_date', array($this, 'ajax_get_products_for_date'));
        add_action('wp_ajax_brcc_fetch_product_attendees_for_date', array($this, 'ajax_fetch_product_attendees_for_date'));
        add_action('wp_ajax_brcc_reset_todays_sales', array($this, 'ajax_reset_todays_sales'));

        // Add modal styles to footer
        add_action('admin_footer', array($this, 'add_modal_styles'));

        // Add date mappings JS to footer
        add_action('admin_footer', array($this, 'add_date_mappings_js'));

        add_filter('admin_body_class', array($this, 'add_body_class'));
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu()
    {
        // Main menu
        add_menu_page(
            __('BRCC Inventory', 'brcc-inventory-tracker'),
            __('BRCC Inventory', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-inventory',
            array($this, 'display_dashboard_page'),
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
            array($this, 'display_dashboard_page')
        );

        // Daily Sales submenu
        add_submenu_page(
            'brcc-inventory',
            __('Daily Sales', 'brcc-inventory-tracker'),
            __('Daily Sales', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-daily-sales',
            array($this, 'display_daily_sales_page')
        );

        // Settings submenu
        add_submenu_page(
            'brcc-inventory',
            __('Settings', 'brcc-inventory-tracker'),
            __('Settings', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-settings',
            array($this, 'display_settings_page')
        );

        // Logs submenu
        add_submenu_page(
            'brcc-inventory',
            __('Operation Logs', 'brcc-inventory-tracker'),
            __('Operation Logs', 'brcc-inventory-tracker'),
            'manage_options',
            'brcc-operation-logs',
            array($this, 'display_operation_logs')
        );

        // Import Historical Data submenu
        add_submenu_page(
            'brcc-inventory',
            __('Import History', 'brcc-inventory-tracker'),
            __('Import History', 'brcc-inventory-tracker'),
            'manage_options', // Only admins can import
            'brcc-import-history',
            array($this, 'display_import_page') // We will create this function next
        );
        
        // Attendee Lists submenu
        add_submenu_page(
            'brcc-inventory',
            __('Attendee Lists', 'brcc-inventory-tracker'),
            __('Attendee Lists', 'brcc-inventory-tracker'),
            'manage_options', // Or appropriate capability
            'brcc-attendee-lists',
            array($this, 'display_attendee_list_page')
        );
        
        // Tools submenu
        add_submenu_page(
            'brcc-inventory',
            __('Tools', 'brcc-inventory-tracker'),
            __('Tools', 'brcc-inventory-tracker'),
            'manage_options', // Capability needed
            'brcc-tools',
            array($this, 'display_tools_page') // New callback function
        );
    }

    /**
     * Enqueue admin scripts and styles
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

        // Select2 (single enqueue for all pages)
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

        // Main Admin CSS
        wp_enqueue_style(
            'brcc-admin-css',
            BRCC_INVENTORY_TRACKER_PLUGIN_URL . 'assets/css/admin.css',
            array('wp-jquery-ui-dialog'),
            BRCC_INVENTORY_TRACKER_VERSION
        );

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
        $eventbrite_events = $this->get_cached_eventbrite_events();
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
            'col_purchase_date' => __('Purchase Date', 'brcc-inventory-tracker'),
            'no_attendees_found' => __('No attendees found for this product.', 'brcc-inventory-tracker'),
            'error_fetching_attendees' => __('Error fetching attendees.', 'brcc-inventory-tracker'),
            'fetch_attendees_btn' => __('Fetch Attendees', 'brcc-inventory-tracker'),
            'eventbrite_events' => $eventbrite_events
        ));
    }

    /**
     * Add settings link to plugin page
     */
    public function add_settings_link($links)
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=brcc-settings') . '">' . __('Settings', 'brcc-inventory-tracker') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Register plugin settings
     */
    public function register_settings()
    {
        register_setting('brcc_api_settings', 'brcc_api_settings');

        // API Settings section
        add_settings_section(
            'brcc_api_settings_section',
            __('API Settings', 'brcc-inventory-tracker'),
            array($this, 'api_settings_section_callback'),
            'brcc_api_settings'
        );

        // API Key field
        add_settings_field(
            'api_key',
            __('API Key', 'brcc-inventory-tracker'),
            array($this, 'api_key_field_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Eventbrite settings
        add_settings_field(
            'eventbrite_token',
            __('Eventbrite API Token', 'brcc-inventory-tracker'),
            array($this, 'eventbrite_token_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );
        
        // Eventbrite Organization ID field
        add_settings_field(
            'eventbrite_org_id',
            __('Eventbrite Organization ID', 'brcc-inventory-tracker'),
            array($this, 'eventbrite_org_id_callback'), // Need to create this callback function
            'brcc_api_settings',
            'brcc_api_settings_section'
        );
        

        // Square Access Token
        add_settings_field(
            'square_access_token',
            __('Square Access Token', 'brcc-inventory-tracker'),
            array($this, 'square_access_token_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Square Location ID
        add_settings_field(
            'square_location_id',
            __('Square Location ID', 'brcc-inventory-tracker'),
            array($this, 'square_location_id_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Square Webhook Signature Key
        add_settings_field(
            'square_webhook_signature_key',
            __('Square Webhook Signature Key', 'brcc-inventory-tracker'),
            array($this, 'square_webhook_signature_key_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Square Sandbox Mode
        add_settings_field(
            'square_sandbox',
            __('Square Sandbox Mode', 'brcc-inventory-tracker'),
            array($this, 'square_sandbox_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Test Mode field
        add_settings_field(
            'test_mode',
            __('Test Mode', 'brcc-inventory-tracker'),
            array($this, 'test_mode_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Live Logging field
        add_settings_field(
            'live_logging',
            __('Live Mode with Logs', 'brcc-inventory-tracker'),
            array($this, 'live_logging_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );

        // Sync interval
        add_settings_field(
            'sync_interval',
            __('Sync Interval (minutes)', 'brcc-inventory-tracker'),
            array($this, 'sync_interval_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );
    }
    /**
     * Square Access Token callback
     */
    public function square_access_token_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['square_access_token']) ? $options['square_access_token'] : '';
?>
        <input type="password" id="square_access_token" name="brcc_api_settings[square_access_token]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Access Token.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Location ID callback
     */
    public function square_location_id_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['square_location_id']) ? $options['square_location_id'] : '';
    ?>
        <input type="text" id="square_location_id" name="brcc_api_settings[square_location_id]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Location ID.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Webhook Signature Key callback
     */
    public function square_webhook_signature_key_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['square_webhook_signature_key']) ? $options['square_webhook_signature_key'] : '';
    ?>
        <input type="password" id="square_webhook_signature_key" name="brcc_api_settings[square_webhook_signature_key]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Webhook Signature Key for validating incoming webhooks.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Sandbox Mode callback
     */
    public function square_sandbox_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['square_sandbox']) ? $options['square_sandbox'] : false;
    ?>
        <label>
            <input type="checkbox" id="square_sandbox" name="brcc_api_settings[square_sandbox]" value="1" <?php checked($value, true); ?> />
            <?php _e('Enable Square Sandbox mode (for testing)', 'brcc-inventory-tracker'); ?>
        </label>
        <p class="description"><?php _e('When enabled, the plugin will use the Square Sandbox environment.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }
    /**
     * Settings section description
     */
    public function api_settings_section_callback()
    {
        echo '<p>' . __('Configure API settings for Eventbrite inventory integration.', 'brcc-inventory-tracker') . '</p>';
    }

    /**
     * API Key field callback
     */
    public function api_key_field_callback()
    {
        $options = get_option('brcc_api_settings');
    ?>
        <input type="text" id="api_key" name="brcc_api_settings[api_key]" value="<?php echo esc_attr($options['api_key']); ?>" class="regular-text" readonly />
        <p class="description"><?php _e('This key is used to authenticate API requests.', 'brcc-inventory-tracker'); ?></p>
        <button type="button" class="button button-secondary" id="regenerate-api-key"><?php _e('Regenerate Key', 'brcc-inventory-tracker'); ?></button>
    <?php
    }

    /**
     * Eventbrite Token callback
     */
    public function eventbrite_token_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['eventbrite_token']) ? $options['eventbrite_token'] : '';
    ?>
        <input type="password" id="eventbrite_token" name="brcc_api_settings[eventbrite_token]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <button type="button" class="button button-secondary" id="test-eventbrite-connection"><?php _e('Test Connection', 'brcc-inventory-tracker'); ?></button>
        <button type="button" class="button button-secondary" id="clear-eventbrite-cache"><?php _e('Clear Event Cache', 'brcc-inventory-tracker'); ?></button>
        <span id="eventbrite-test-status"></span>
        <span id="eventbrite-cache-status"></span>
        <p class="description"><?php _e('Enter your Eventbrite Private Token (found under Account Settings -> Developer Links -> API Keys).', 'brcc-inventory-tracker'); ?></p>
    <?php
}

/**
 * Eventbrite Organization ID callback
 */
public function eventbrite_org_id_callback() {
    $options = get_option('brcc_api_settings');
    $value = isset($options['eventbrite_org_id']) ? $options['eventbrite_org_id'] : '';
    ?>
    <input type="text" id="eventbrite_org_id" name="brcc_api_settings[eventbrite_org_id]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
    <p class="description">
        <?php _e('Enter your Eventbrite Organization ID. You can usually find this in the URL of your organizer profile page (e.g., eventbrite.com/o/your-name-XXXXXXXXX).', 'brcc-inventory-tracker'); ?>
        <br/><em><?php _e('This is required for fetching events.', 'brcc-inventory-tracker'); ?></em>
    </p>
    <?php
}

/**
 * Eventbrite Webhook Secret callback 
 */
// public function eventbrite_webhook_secret_callback() { ... }

/**
     * Test Mode callback
     */
    public function test_mode_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['test_mode']) ? $options['test_mode'] : false;
    ?>
        <label>
            <input type="checkbox" id="test_mode" name="brcc_api_settings[test_mode]" value="1" <?php checked($value, true); ?> />
            <?php _e('Enable test mode (logs operations but does not modify inventory)', 'brcc-inventory-tracker'); ?>
        </label>
        <p class="description"><?php _e('When enabled, all inventory operations will be logged but no actual inventory changes will be made. Use this to test the plugin on a production site without affecting live inventory.', 'brcc-inventory-tracker'); ?></p>
        <?php if ($value): ?>
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php _e('Test Mode is currently ENABLED.', 'brcc-inventory-tracker'); ?></strong>
                    <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
                    <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                </p>
            </div>
        <?php endif; ?>
    <?php
    }

    /**
     * Live Logging callback
     */
    public function live_logging_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['live_logging']) ? $options['live_logging'] : false;
    ?>
        <label>
            <input type="checkbox" id="live_logging" name="brcc_api_settings[live_logging]" value="1" <?php checked($value, true); ?> />
            <?php _e('Enable logging in live mode (logs operations while actually making inventory changes)', 'brcc-inventory-tracker'); ?>
        </label>
        <p class="description"><?php _e('When enabled, inventory operations will be logged while making actual changes to inventory. This helps with troubleshooting in production environments.', 'brcc-inventory-tracker'); ?></p>
        <?php if ($value): ?>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php _e('Live Logging is currently ENABLED.', 'brcc-inventory-tracker'); ?></strong>
                    <?php _e('Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?>
                    <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                </p>
            </div>
        <?php endif; ?>
    <?php
    }

    /**
     * Sync interval callback
     */
    public function sync_interval_callback()
    {
        $options = get_option('brcc_api_settings');
        $value = isset($options['sync_interval']) ? $options['sync_interval'] : 15;
    ?>
        <input type="number" id="sync_interval" name="brcc_api_settings[sync_interval]" value="<?php echo esc_attr($value); ?>" class="small-text" min="5" step="1" />
        <p class="description"><?php _e('How often should inventory be synchronized (in minutes).', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Display dashboard page with improved UI
     */
    public function display_dashboard_page()
    {
        // Determine selected date and view period
        $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');
        $view_period = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'daily'; // Default to 'daily'

        // Calculate start and end dates based on view period
        $start_date = $selected_date;
        $end_date = $selected_date;
        $summary_title = __('Daily Summary', 'brcc-inventory-tracker');
        $summary_tooltip = sprintf(__('Sales data for %s', 'brcc-inventory-tracker'), date_i18n(get_option('date_format'), strtotime($selected_date)));

        if ($view_period === 'weekly') {
            // Assuming week starts on Monday
            $start_date = date('Y-m-d', strtotime('monday this week', strtotime($selected_date)));
            $end_date = date('Y-m-d', strtotime('sunday this week', strtotime($selected_date)));
            $summary_title = __('Weekly Summary', 'brcc-inventory-tracker');
            $summary_tooltip = sprintf(__('Sales data for the week of %s to %s', 'brcc-inventory-tracker'), date_i18n(get_option('date_format'), strtotime($start_date)), date_i18n(get_option('date_format'), strtotime($end_date)));
        } elseif ($view_period === 'monthly') {
            $start_date = date('Y-m-01', strtotime($selected_date));
            $end_date = date('Y-m-t', strtotime($selected_date));
            $summary_title = __('Monthly Summary', 'brcc-inventory-tracker');
             $summary_tooltip = sprintf(__('Sales data for %s', 'brcc-inventory-tracker'), date_i18n('F Y', strtotime($selected_date)));
        }

        // Get sales tracker
        $sales_tracker = new BRCC_Sales_Tracker();

        // Get period summary based on calculated dates
        $period_summary = $sales_tracker->get_summary_by_period($start_date, $end_date);
        
        // Get sales data for the detailed table (optional, can be added later)
        // $detailed_sales = $sales_tracker->get_daily_sales($start_date, $end_date); // Need modification in get_daily_sales if range needed

    ?>
        <div class="wrap">
            <h1><?php _e('BRCC Inventory Dashboard', 'brcc-inventory-tracker'); ?></h1>

            <?php if (BRCC_Helpers::is_test_mode()): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('Test Mode is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('All inventory operations are being logged but no actual inventory changes are being made.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a> |
                        <a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>"><?php _e('Disable Test Mode', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php elseif (BRCC_Helpers::is_live_logging()): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('Live Logging is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

           
            <!-- Period Summary Widget -->
            <div class="brcc-widget brcc-period-summary">
                <div class="brcc-widget-header">
                    <h3><?php _e('Period Summary', 'brcc-inventory-tracker'); ?></h3>
                    <div class="brcc-widget-actions">
                        <button type="button" id="brcc-sync-now" class="button button-secondary">
                            <?php _e('Sync Now', 'brcc-inventory-tracker'); ?>
                        </button>
                    </div>
                </div>
                <div class="brcc-period-summary">
                    <table class="brcc-period-summary-table">
                        <thead>
                            <tr>
                                <th><?php _e('Total Sales', 'brcc-inventory-tracker'); ?></th>
                                <th><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></th>
                                <th><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong><?php echo esc_html($period_summary['total_sales']); ?></strong></td>
                                <td><?php echo esc_html($period_summary['woocommerce_sales']); ?></td>
                                <td><?php echo esc_html($period_summary['eventbrite_sales']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Inventory Status Widget -->
            <?php $this->display_inventory_status_widget(); ?>
     
        </div>
    <?php
    }

    /**
     * Displays the Eventbrite Inventory Status widget on the dashboard.
     * Shows low stock and recently sold-out items linked to Eventbrite.
     */
    private function display_inventory_status_widget() {
        // Ensure mapping class is loaded
        if (!class_exists('BRCC_Product_Mappings')) {
             echo '<div class="brcc-widget brcc-full-width"><p>' . __('Error: Product Mappings class not found.', 'brcc-inventory-tracker') . '</p></div>';
             return;
        }

        $mappings_instance = new BRCC_Product_Mappings();
        $all_mappings = $mappings_instance->get_all_mappings();
        // BRCC_Helpers::log_debug('display_inventory_status_widget: All mappings retrieved:', $all_mappings); // Removed debug log
        $eventbrite_product_ids = []; // Store IDs of products with any Eventbrite mapping

        // Identify all products with at least one Eventbrite mapping (default or date-specific)
        // BRCC_Helpers::log_debug('display_inventory_status_widget: Starting loop through mappings...'); // Removed debug log
        foreach ($all_mappings as $key => $mapping_data) {
            // BRCC_Helpers::log_debug("display_inventory_status_widget: Processing key: $key"); // Removed debug log
            if (strpos($key, '_dates') === false && is_numeric($key)) {
                // Base product mapping
                $product_id = (int) $key;
                if (is_array($mapping_data) && !empty($mapping_data['eventbrite_id'])) {
                    // BRCC_Helpers::log_debug("display_inventory_status_widget: Found Eventbrite ID in base mapping for $product_id: " . $mapping_data['eventbrite_id']); // Removed debug log
                    $eventbrite_product_ids[$product_id] = $product_id; // Add using key to ensure uniqueness
                }
            } elseif (strpos($key, '_dates') !== false) {
                // Date-specific mappings
                $base_product_id = (int) str_replace('_dates', '', $key);
                if (is_array($mapping_data)) {
                    foreach ($mapping_data as $date_key => $specific_mapping) {
                        if (is_array($specific_mapping) && !empty($specific_mapping['eventbrite_id'])) {
                            // BRCC_Helpers::log_debug("display_inventory_status_widget: Found Eventbrite ID in date mapping for $base_product_id (Date Key: $date_key): " . $specific_mapping['eventbrite_id']); // Removed debug log
                            $eventbrite_product_ids[$base_product_id] = $base_product_id; // Add using key
                            break; // Found one Eventbrite mapping for this product, no need to check other dates
                        }
                    }
                }
            }
        }
// BRCC_Helpers::log_debug('display_inventory_status_widget: Finished loop. Identified Eventbrite Product IDs:', $eventbrite_product_ids); // Removed debug log
        if (empty($eventbrite_product_ids)) {
            // Removed duplicate check from next line
            echo '<div class="brcc-widget brcc-full-width brcc-inventory-status"><p>' . __('No products are currently mapped to Eventbrite.', 'brcc-inventory-tracker') . '</p></div>';
            return;
        }

        $low_stock_threshold = max(1, intval(get_option('woocommerce_notify_low_stock_amount', 1)));
        $sold_out_recency_days = 7;
        $sold_out_threshold_timestamp = time() - ($sold_out_recency_days * DAY_IN_SECONDS);

        $low_stock_products = [];
        $recently_sold_out_products = [];

        // Now iterate through the identified product IDs
        foreach ($eventbrite_product_ids as $product_id) {
            $product = wc_get_product($product_id);
            // Ensure product exists and manages stock
            if (!$product || !$product->managing_stock()) {
                continue;
            }

            $current_stock = $product->get_stock_quantity();

            // Low Stock Check
            if (is_numeric($current_stock) && $current_stock <= $low_stock_threshold && $current_stock > 0) {
                 $low_stock_products[$product_id] = [
                     'name' => $product->get_name(),
                     'stock' => $current_stock,
                     'link' => admin_url('post.php?post=' . $product_id . '&action=edit')
                 ];
            }

            // Recently Sold Out Check
            $sold_out_timestamp = get_post_meta($product_id, '_brcc_eventbrite_sold_out_timestamp', true);
            if (!empty($sold_out_timestamp) && $sold_out_timestamp >= $sold_out_threshold_timestamp) {
                 if ($current_stock !== null && $current_stock <= 0) {
                     $recently_sold_out_products[$product_id] = [
                         'name' => $product->get_name(),
                         'timestamp' => $sold_out_timestamp,
                         'link' => admin_url('post.php?post=' . $product_id . '&action=edit')
                     ];
                 }
            }
        }
        ?>
        <div class="brcc-widget brcc-full-width brcc-inventory-status">
             <h2><?php _e('Eventbrite Inventory Status', 'brcc-inventory-tracker'); ?></h2>
             <div class="brcc-status-columns">
                 <div class="brcc-status-column">
                     <h3>
                         <?php _e('Low Stock Items', 'brcc-inventory-tracker'); ?>
                         <span class="brcc-tooltip">
                             <span class="dashicons dashicons-info-outline"></span>
                             <span class="brcc-tooltip-text"><?php printf(__('Products linked to Eventbrite with stock at or below %d.', 'brcc-inventory-tracker'), $low_stock_threshold); ?></span>
                         </span>
                     </h3>
                     <?php if (!empty($low_stock_products)): ?>
                         <ul>
                             <?php foreach ($low_stock_products as $id => $data): ?>
                                 <li>
                                     <a href="<?php echo esc_url($data['link']); ?>" target="_blank" title="<?php esc_attr_e('Edit Product', 'brcc-inventory-tracker'); ?>">
                                         <?php echo esc_html($data['name']); ?> (ID: <?php echo esc_html($id); ?>)
                                     </a> - <?php printf(__('Stock: %d', 'brcc-inventory-tracker'), $data['stock']); ?>
                                 </li>
                             <?php endforeach; ?>
                         </ul>
                     <?php else: ?>
                         <p><?php _e('No Eventbrite-linked products are currently low on stock.', 'brcc-inventory-tracker'); ?></p>
                     <?php endif; ?>
                 </div>
                 <div class="brcc-status-column">
                     <h3>
                         <?php _e('Recently Sold Out via Plugin', 'brcc-inventory-tracker'); ?>
                          <span class="brcc-tooltip">
                             <span class="dashicons dashicons-info-outline"></span>
                             <span class="brcc-tooltip-text"><?php printf(__('Products marked as SOLD OUT on Eventbrite by this plugin within the last %d days (and still out of stock).', 'brcc-inventory-tracker'), $sold_out_recency_days); ?></span>
                         </span>
                     </h3>
                     <?php if (!empty($recently_sold_out_products)): ?>
                         <ul>
                             <?php foreach ($recently_sold_out_products as $id => $data): ?>
                                 <li>
                                     <a href="<?php echo esc_url($data['link']); ?>" target="_blank" title="<?php esc_attr_e('Edit Product', 'brcc-inventory-tracker'); ?>">
                                         <?php echo esc_html($data['name']); ?> (ID: <?php echo esc_html($id); ?>)
                                     </a> - <?php printf(__('Marked Sold Out: %s', 'brcc-inventory-tracker'), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $data['timestamp'])); ?>
                                 </li>
                             <?php endforeach; ?>
                         </ul>
                     <?php else: ?>
                         <p><?php _e('No Eventbrite-linked products were recently marked as sold out by the plugin (or they have been restocked).', 'brcc-inventory-tracker'); ?></p>
                     <?php endif; ?>
                 </div>
             </div>
        </div>
        <?php
    }
    /**
     * Display daily sales page
     */
    public function display_daily_sales_page()
    {
        // Get date range from query parameters
        // Default to current day if no dates are specified
        $default_date = date('Y-m-d');
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : $default_date;
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : $default_date;

        // Get sales tracker
        $sales_tracker = new BRCC_Sales_Tracker();

        // Get filtered sales data
        $filtered_sales = array();
        // Pass start and end dates to get_daily_sales
        $all_sales = $sales_tracker->get_daily_sales($start_date, $end_date);

        // No longer need to filter, as get_daily_sales now handles it
        // foreach ($all_sales as $date => $products) {
        //     if ($date >= $start_date && $date <= $end_date) {
                $filtered_sales[$date] = $products;
        //     }
        // }

        // Sort by date (descending) - not needed as get_daily_sales should return sorted data
        // krsort($filtered_sales);

        // Get period summary
        $period_summary = $sales_tracker->get_summary_by_period($start_date, $end_date);

    ?>
        <div class="wrap">
            <h1><?php _e('Daily Sales', 'brcc-inventory-tracker'); ?></h1>

            <?php if (BRCC_Helpers::is_test_mode()): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('Test Mode is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('All inventory operations are being logged but no actual inventory changes are being made.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a> |
                        <a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>"><?php _e('Disable Test Mode', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php elseif (BRCC_Helpers::is_live_logging()): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('Live Logging is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <div class="brcc-date-range-filter">
                <label for="brcc-start-date"><?php _e('Start Date:', 'brcc-inventory-tracker'); ?></label>
                <input type="text" id="brcc-start-date" class="brcc-datepicker" value="<?php echo esc_attr($start_date); ?>" />

                <label for="brcc-end-date"><?php _e('End Date:', 'brcc-inventory-tracker'); ?></label>
                <input type="text" id="brcc-end-date" class="brcc-datepicker" value="<?php echo esc_attr($end_date); ?>" />

                <button type="button" class="button button-primary" id="brcc-filter-date-range"><?php _e('Filter', 'brcc-inventory-tracker'); ?></button>
                <button type="button" class="button button-secondary" id="brcc-reset-todays-sales" style="margin-left: 10px;"><?php _e('Reset Today\'s Sales', 'brcc-inventory-tracker'); ?></button>
            </div>

            <!-- Period Summary Widget -->
            <div class="brcc-dashboard-widgets">
                <div class="brcc-widget brcc-full-width">
                    <h2><?php _e('Period Summary', 'brcc-inventory-tracker'); ?></h2>
                    <div class="brcc-period-summary">
                        <table class="brcc-period-summary-table">
                            <tr>
                                <th><?php _e('Total Sales', 'brcc-inventory-tracker'); ?></th>
                                <th><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></th>
                                <th><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></th>
                            </tr>
                            <tr>
                                <td><strong><?php echo $period_summary['total_sales']; ?></strong></td>
                                <td><?php echo $period_summary['woocommerce_sales']; ?></td>
                                <td><?php echo $period_summary['eventbrite_sales']; ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="brcc-export-buttons">
                <!-- Add direct download link -->
                <a href="#" id="brcc-direct-download" class="button button-primary" style="margin-left: 10px;">
                    <?php _e('Download CSV', 'brcc-inventory-tracker'); ?>
                </a>

                <?php if (BRCC_Helpers::is_test_mode()): ?>
                    <span class="description" style="margin-left: 10px;">
                        <?php _e('Note: Exports will work normally even in Test Mode', 'brcc-inventory-tracker'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Add inline script for direct download -->
            <script type="text/javascript">
                (function() {
                    // Get the direct download link
                    var directLink = document.getElementById('brcc-direct-download');
                    if (!directLink) return;

                    // Add click event handler
                    directLink.addEventListener('click', function(e) {
                        // Get date values
                        var startDate = document.getElementById('brcc-start-date').value;
                        var endDate = document.getElementById('brcc-end-date').value;

                        // Validate date inputs
                        if (!startDate || !endDate) {
                            alert('<?php _e('Please select both start and end dates', 'brcc-inventory-tracker'); ?>');
                            e.preventDefault();
                            return false;
                        }

                        // Set the download URL
                        this.href = '<?php echo admin_url('admin.php'); ?>' +
                            '?page=brcc-daily-sales' +
                            '&action=export_csv' +
                            '&start_date=' + encodeURIComponent(startDate) +
                            '&end_date=' + encodeURIComponent(endDate) +
                            '&nonce=<?php echo wp_create_nonce('brcc-admin-nonce'); ?>';

                        // Open in new tab to trigger download
                        this.target = '_blank';
                    });
                })();
            </script>

            <div id="brcc-daily-sales-data">
                <?php foreach ($filtered_sales as $date => $products) : ?>
                    <div class="brcc-daily-sales-card">
                        <h3><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($date))); ?></h3>
                        <?php $this->display_sales_table($products); ?>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($filtered_sales)) : ?>
                    <p><?php _e('No sales data available for the selected date range.', 'brcc-inventory-tracker'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    /**
     * Display operation logs
     */
    public function display_operation_logs()
    {
        $logs = get_option('brcc_operation_logs', []);
    ?>
        <div class="wrap">
            <h1><?php _e('Operation Logs', 'brcc-inventory-tracker'); ?></h1>

            <?php
            // Show notices about current logging modes
            $settings = get_option('brcc_api_settings');
            $test_mode = isset($settings['test_mode']) ? $settings['test_mode'] : false;
            $live_logging = isset($settings['live_logging']) ? $settings['live_logging'] : false;

            if ($test_mode) {
            ?>
                <div class="notice notice-warning">
                    <p><?php _e('Test Mode is currently enabled. All inventory operations are being logged but no actual inventory changes are being made.', 'brcc-inventory-tracker'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>" class="button button-secondary"><?php _e('Go to Settings', 'brcc-inventory-tracker'); ?></a></p>
                </div>
            <?php
            } elseif ($live_logging) {
            ?>
                <div class="notice notice-info">
                    <p><?php _e('Live Logging is currently enabled. Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>" class="button button-secondary"><?php _e('Go to Settings', 'brcc-inventory-tracker'); ?></a></p>
                </div>
            <?php
            } else {
            ?>
                <div class="notice notice-info">
                    <p><?php _e('Logging is currently disabled. Enable Test Mode or Live Logging in settings to track inventory operations.', 'brcc-inventory-tracker'); ?></p>
                    <p><a href="<?php echo admin_url('admin.php?page=brcc-settings'); ?>" class="button button-secondary"><?php _e('Go to Settings', 'brcc-inventory-tracker'); ?></a></p>
                </div>
            <?php
            }

            if (isset($_GET['cleared'])) {
            ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Logs have been cleared.', 'brcc-inventory-tracker'); ?></p>
                </div>
            <?php
            }
            ?>

            <div class="brcc-log-filter">
                <label for="brcc-log-source"><?php _e('Filter by Source:', 'brcc-inventory-tracker'); ?></label>
                <select id="brcc-log-source">
                    <option value=""><?php _e('All Sources', 'brcc-inventory-tracker'); ?></option>
                    <option value="WooCommerce"><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></option>
                    <option value="Eventbrite"><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></option>
                    <option value="Admin"><?php _e('Admin', 'brcc-inventory-tracker'); ?></option>
                    <option value="API"><?php _e('API', 'brcc-inventory-tracker'); ?></option>
                </select>

                <label for="brcc-log-mode"><?php _e('Filter by Mode:', 'brcc-inventory-tracker'); ?></label>
                <select id="brcc-log-mode">
                    <option value=""><?php _e('All Modes', 'brcc-inventory-tracker'); ?></option>
                    <option value="test"><?php _e('Test Mode', 'brcc-inventory-tracker'); ?></option>
                    <option value="live"><?php _e('Live Mode', 'brcc-inventory-tracker'); ?></option>
                </select>

                <button type="button" id="brcc-filter-logs" class="button button-primary"><?php _e('Filter', 'brcc-inventory-tracker'); ?></button>
            </div>

            <?php if (empty($logs)): ?>
                <p><?php _e('No operation logs available.', 'brcc-inventory-tracker'); ?></p>
            <?php else: ?>
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=brcc-operation-logs&clear=1'), 'brcc-clear-logs'); ?>" class="button button-secondary">
                            <?php _e('Clear Logs', 'brcc-inventory-tracker'); ?>
                        </a>
                    </div>
                    <br class="clear">
                </div>

                <table class="wp-list-table widefat fixed striped brcc-logs-table">
                    <thead>
                        <tr>
                            <th width="15%"><?php _e('Date/Time', 'brcc-inventory-tracker'); ?></th>
                            <th width="10%"><?php _e('Source', 'brcc-inventory-tracker'); ?></th>
                            <th width="10%"><?php _e('Mode', 'brcc-inventory-tracker'); ?></th>
                            <th width="15%"><?php _e('Operation', 'brcc-inventory-tracker'); ?></th>
                            <th><?php _e('Details', 'brcc-inventory-tracker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($logs) as $log): ?>
                            <tr class="brcc-log-row"
                                data-source="<?php echo esc_attr($log['source']); ?>"
                                data-mode="<?php echo isset($log['test_mode']) && $log['test_mode'] ? 'test' : 'live'; ?>">
                                <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $log['timestamp']); ?></td>
                                <td><?php echo esc_html($log['source']); ?></td>
                                <td>
                                    <?php if (isset($log['test_mode']) && $log['test_mode']): ?>
                                        <span class="brcc-test-mode-badge"><?php _e('Test', 'brcc-inventory-tracker'); ?></span>
                                    <?php else: ?>
                                        <span class="brcc-live-mode-badge"><?php _e('Live', 'brcc-inventory-tracker'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($log['operation']); ?></td>
                                <td><?php echo esc_html($log['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        $('#brcc-filter-logs').on('click', function() {
                            var source = $('#brcc-log-source').val();
                            var mode = $('#brcc-log-mode').val();

                            $('.brcc-log-row').show();

                            if (source) {
                                $('.brcc-log-row').not('[data-source="' + source + '"]').hide();
                            }

                            if (mode) {
                                $('.brcc-log-row').not('[data-mode="' + mode + '"]').hide();
                            }
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
    <?php
    }

    /**
     * Handle clearing logs
     */
    public function maybe_clear_logs()
    {
        if (
            isset($_GET['page']) && $_GET['page'] === 'brcc-operation-logs' &&
            isset($_GET['clear']) && $_GET['clear'] === '1' &&
            isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'brcc-clear-logs')
        ) {

            delete_option('brcc_operation_logs');

            wp_redirect(admin_url('admin.php?page=brcc-operation-logs&cleared=1'));
            exit;
        }
    }

    /**
     * Display settings page
     */
    public function display_settings_page()
    {
    ?>
        <div class="wrap">
            <h1><?php _e('BRCC Inventory Settings', 'brcc-inventory-tracker'); ?></h1>

            <?php if (BRCC_Helpers::is_test_mode()): ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php _e('Test Mode is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('All inventory operations are being logged but no actual inventory changes are being made.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php elseif (BRCC_Helpers::is_live_logging()): ?>
                <div class="notice notice-info">
                    <p>
                        <strong><?php _e('Live Logging is enabled.', 'brcc-inventory-tracker'); ?></strong>
                        <?php _e('Inventory operations are being logged while making actual changes.', 'brcc-inventory-tracker'); ?>
                        <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('brcc_api_settings');
                do_settings_sections('brcc_api_settings');
                submit_button();
                ?>
            </form>

            <hr>

            <?php $this->display_product_mapping_interface(); ?>

            <hr>

            <h2><?php _e('API Documentation', 'brcc-inventory-tracker'); ?></h2>

            <p><?php _e('The BRCC Inventory Tracker exposes the following REST API endpoints:', 'brcc-inventory-tracker'); ?></p>

            <table class="wp-list-table widefat fixed">
                <thead>
                    <tr>
                        <th><?php _e('Endpoint', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Method', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Description', 'brcc-inventory-tracker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>/wp-json/brcc/v1/inventory</code></td>
                        <td>GET</td>
                        <td><?php _e('Get current inventory levels including date-based inventory.', 'brcc-inventory-tracker'); ?></td>
                    </tr>
                    <tr>
                        <td><code>/wp-json/brcc/v1/inventory/update</code></td>
                        <td>POST</td>
                        <td><?php _e('Update inventory levels. Can include a "date" parameter for date-specific inventory.', 'brcc-inventory-tracker'); ?></td>
                    </tr>
                </tbody>
            </table>

            <p><?php _e('Authentication is required for all API requests using the API key.', 'brcc-inventory-tracker'); ?></p>

            <h3><?php _e('Example Request with Date Parameter', 'brcc-inventory-tracker'); ?></h3>

            <pre><code>curl -X POST \
https://your-site.com/wp-json/brcc/v1/inventory/update \
-H 'X-BRCC-API-Key: YOUR_API_KEY' \
-H 'Content-Type: application/json' \
-d '{
  "products": [
      {
          "id": 123,
          "date": "2025-03-15",
          "stock": 10
      }
  ]
}'</code></pre>
        </div>

        <?php $this->add_modal_styles(); ?>
        <?php $this->add_date_mappings_js(); ?>
    <?php
    }

    /**
     * Display sales table with date support
     */
    private function display_sales_table($sales_data)
    {
        if (empty($sales_data)) {
            echo '<p>' . __('No sales data available for this period.', 'brcc-inventory-tracker') . '</p>';
            return;
        }

        // Table header
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Product', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Total', 'brcc-inventory-tracker'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($sales_data as $product_id => $data) {
                    $product_name = BRCC_Helpers::get_product_name($product_id);
                    $woo_sales = isset($data['woocommerce']) ? $data['woocommerce'] : 0;
                    $eventbrite_sales = isset($data['eventbrite']) ? $data['eventbrite'] : 0;
                    $total_sales = isset($data['quantity']) ? $data['quantity'] : 0;
                    ?>
                    <tr>
                        <td><?php echo esc_html($product_name); ?></td>
                        <td><?php echo esc_html($woo_sales); ?></td>
                        <td><?php echo esc_html($eventbrite_sales); ?></td>
                        <td><?php echo esc_html($total_sales); ?></td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Handle CSV export
     */
    public function maybe_export_csv()
    {
        // Early return if not a CSV export request
        if (
            !isset($_GET['page']) || $_GET['page'] !== 'brcc-daily-sales' ||
            !isset($_GET['action']) || $_GET['action'] !== 'export_csv'
        ) {
            return;
        }

        // Check nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'brcc-admin-nonce')) {
            wp_die(__('Security check failed.', 'brcc-inventory-tracker'));
        }

        // Check if user has permission
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'brcc-inventory-tracker'));
        }

        // Get date parameters with defaults
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-7 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');

        try {
            // Get sales data
            $sales_tracker = new BRCC_Sales_Tracker();
            $sales_data = $sales_tracker->get_total_sales($start_date, $end_date);

            // Filename with dates
            $filename = 'brcc-sales-' . $start_date . '-to-' . $end_date . '.csv';

            // Set headers for CSV download - IMPORTANT: No output before headers
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            // Create output stream
            $output = fopen('php://output', 'w');

            // Add BOM for Excel UTF-8 compatibility
            fputs($output, "\xEF\xBB\xBF");

            // Add header row
            fputcsv($output, array(
                __('Product', 'brcc-inventory-tracker'),
                __('SKU', 'brcc-inventory-tracker'),
                __('Event Date', 'brcc-inventory-tracker'),
                __('Total Quantity', 'brcc-inventory-tracker'),
                __('WooCommerce', 'brcc-inventory-tracker'),
                __('Eventbrite', 'brcc-inventory-tracker')
            ));

            // Add data rows
            foreach ($sales_data as $product_data) {
                $booking_date = isset($product_data['booking_date']) ? $product_data['booking_date'] : '';
                $formatted_date = $booking_date ? date_i18n(get_option('date_format'), strtotime($booking_date)) : '';

                fputcsv($output, array(
                    isset($product_data['name']) ? $product_data['name'] : 'Unknown',
                    isset($product_data['sku']) ? $product_data['sku'] : '',
                    $formatted_date,
                    isset($product_data['quantity']) ? $product_data['quantity'] : 0,
                    isset($product_data['woocommerce']) ? $product_data['woocommerce'] : 0,
                    isset($product_data['eventbrite']) ? $product_data['eventbrite'] : 0
                ));
            }

            // Close output stream
            fclose($output);

            // Exit after sending CSV to prevent WordPress from sending additional output
            exit;
        } catch (Exception $e) {
            // Log any errors
            error_log('CSV Export Error: ' . $e->getMessage());
            wp_die('Error generating CSV: ' . $e->getMessage());
        }
    }

    /**
     * Display product mapping with Square support
     */
    public function display_product_mapping_interface()
    {
    ?>
        <div style="margin-bottom: 15px;">
            <button type="button" id="brcc-refresh-eventbrite-cache" class="button button-secondary">
                <?php _e('Refresh Eventbrite Events Cache', 'brcc-inventory-tracker'); ?>
            </button>
            <span id="brcc-refresh-cache-status" style="margin-left: 10px;"></span>
            <p class="description"><?php _e('Click this if new events were added or removed in Eventbrite and they are not showing in the dropdown below. The cache automatically refreshes every hour.', 'brcc-inventory-tracker'); ?></p>
        </div>
    <?php
        // Fetch cached events once for the entire table
        $cached_events = $this->get_cached_eventbrite_events();
        // Fetch cached events once for the entire table
        $cached_events = $this->get_cached_eventbrite_events(); // <<< THIS LINE WAS MISSING
        $event_options_html = '<option value="">' . esc_html__('Select Event...', 'brcc-inventory-tracker') . '</option>';
        if (is_array($cached_events)) {
        	foreach ($cached_events as $event_id => $event_label) {
        		// Removed debug log
        		$event_options_html .= sprintf(
        			'<option value="%s">%s</option>',
        			esc_attr($event_id),
                    esc_html($event_label) // Use the label directly from the cached array
                );
            }
        } elseif (is_wp_error($cached_events)) {
            // Display error if fetching failed
            echo '<div class="notice notice-error"><p>' . sprintf(
                __('Error fetching Eventbrite events: %s', 'brcc-inventory-tracker'),
                esc_html($cached_events->get_error_message())
            ) . '</p></div>';
        } else {
            // Handle unexpected return type
            echo '<div class="notice notice-warning"><p>' . __('Could not load Eventbrite events.', 'brcc-inventory-tracker') . '</p></div>';
        }

    ?>
        <h2><?php _e('Product Mapping', 'brcc-inventory-tracker'); ?></h2>

        <p><?php _e('Map your WooCommerce products to Eventbrite and Square items. For products with date-based inventory, use the "Manage Dates" button to set up mappings for specific dates.', 'brcc-inventory-tracker'); ?></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('WooCommerce Product', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('SKU', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Eventbrite Event ID', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Eventbrite Ticket ID', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Square Catalog ID', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Date Mappings', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Actions', 'brcc-inventory-tracker'); ?></th>
                </tr>
            </thead>
            <tbody id="brcc-product-mapping-table">
                <?php
                // Get product mappings
                $all_mappings = get_option('brcc_product_mappings', array());

                // Get all WooCommerce products
                $products = wc_get_products(array(
                    'limit' => -1,
                    'status' => 'publish',
                ));

                if (!empty($products)) {
                    foreach ($products as $product) {
                        $product_id = $product->get_id();
                        $mapping = isset($all_mappings[$product_id]) ? $all_mappings[$product_id] : array(
                            'eventbrite_id' => '',
                            'square_id' => '',
                            // Ensure eventbrite_event_id is also initialized
                            'eventbrite_event_id' => '',
                            'eventbrite_class_id' => '' // Initialize class ID
                        );

                        // Check if this product has date-based inventory
                        $has_dates = isset($all_mappings[$product_id . '_dates']) && !empty($all_mappings[$product_id . '_dates']);
                ?>
                        <tr>
                            <td><?php echo esc_html($product->get_name()); ?></td>
                            <td><?php echo esc_html($product->get_sku()); ?></td>
                            <?php /*<td> Eventbrite Class ID Column REMOVED </td>*/ ?>
                            <td class="brcc-eventbrite-event-cell"> <?php /* Event ID Column */ ?>
                                <div class="brcc-mapping-input-group">
                                    <?php // Event Dropdown ?>
                                    <select
                                        id="brcc_eventbrite_event_id_select_<?php echo $product_id; ?>"
                                        name="brcc_product_mappings[<?php echo $product_id; ?>][eventbrite_event_id]"
                                        class="brcc-eventbrite-event-id-select"
                                        data-product-id="<?php echo $product_id; ?>"
                                        data-selected="<?php echo esc_attr(isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : ''); ?>"
                                        style="width: 100%;">
                                        <?php echo str_replace(
                                            'value="' . esc_attr(isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : '') . '"',
                                            'value="' . esc_attr(isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : '') . '" selected="selected"',
                                            $event_options_html
                                        ); ?>
                                    </select>
                                    <?php // Spinner moved next to ticket dropdown ?>
                                </div>
                            </td>
                            <td class="brcc-eventbrite-ticket-cell">
                                <div class="brcc-mapping-input-group">
                                    <?php
                                    $event_selected = !empty($mapping['eventbrite_event_id']);
                                    $ticket_selected = isset($mapping['eventbrite_id']) ? $mapping['eventbrite_id'] : '';
                                    ?>

                                    <?php // Display the actual Eventbrite ticket ID if available ?>
                                    <?php if (!empty($ticket_selected)) : ?>
                                        <span class="brcc-actual-ticket-id">
                                            <?php echo esc_html($ticket_selected); ?>
                                        </span>
                                        <br />
                                    <?php endif; ?>

                                    <select
                                        id="brcc_eventbrite_ticket_id_select_<?php echo $product_id; ?>"
                                        name="brcc_product_mappings[<?php echo $product_id; ?>][eventbrite_id]"
                                        class=""
                                        data-product-id="<?php echo $product_id; ?>"
                                        data-selected="<?php echo esc_attr($ticket_selected); ?>"
                                        style="width: 100%;"
                                        <?php disabled(!$event_selected); ?>
                                    >
                                        <option value=""><?php echo $event_selected ? esc_html__('Select Ticket...', 'brcc-inventory-tracker') : esc_html__('Select Event First...', 'brcc-inventory-tracker'); ?></option>
                                        <?php
                                        if (!empty($ticket_selected) && $event_selected) {
                                            $option_text = sprintf(__('Ticket ID: %s', 'brcc-inventory-tracker'), esc_html($ticket_selected));
                                            echo '<option value="' . esc_attr($ticket_selected) . '" selected="selected">' . $option_text . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <span class="spinner" style="float: none; vertical-align: middle; margin-left: 5px; visibility: hidden;"></span>

                                    <input type="text"
                                           name="brcc_product_mappings[<?php echo $product_id; ?>][manual_eventbrite_id]"
                                           value="<?php echo esc_attr(isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : ''); ?>"
                                           class="regular-text brcc-manual-base-ticket-id"
                                           placeholder="<?php esc_attr_e('Enter Ticket ID Manually', 'brcc-inventory-tracker'); ?>"
                                           style="width: 100%; margin-top: 2px;" />
                                </div>
                            </td>
                            <td>
                                <input type="text"
                                    name="brcc_product_mappings[<?php echo $product_id; ?>][square_id]"
                                    value="<?php echo esc_attr(isset($mapping['square_id']) ? $mapping['square_id'] : ''); ?>"
                                    class="regular-text" />
                            </td>
                            <td>
                                <button type="button"
                                    class="button brcc-manage-dates"
                                    data-product-id="<?php echo $product_id; ?>">
                                    <?php echo $has_dates ? __('Manage Dates', 'brcc-inventory-tracker') : __('Add Dates', 'brcc-inventory-tracker'); ?>
                                    <?php if ($has_dates): ?>
                                        <span class="dashicons dashicons-calendar-alt" style="vertical-align: text-bottom;"></span>
                                    <?php endif; ?>
                                </button>
                            </td>
                            <td>
                                <button type="button"
                                    class="button brcc-test-mapping"
                                    data-product-id="<?php echo $product_id; ?>">
                                    <?php _e('Test', 'brcc-inventory-tracker'); ?>
                                </button>
                                <button type="button"
                                    class="button brcc-test-square-mapping"
                                    data-product-id="<?php echo $product_id; ?>">
                                    <?php _e('Test Square', 'brcc-inventory-tracker'); ?>
                                </button>
                            </td>
                        </tr>
                        <?php // The expandable row will be added here by JS if needed ?>
                    <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="7"><?php _e('No products found.', 'brcc-inventory-tracker'); ?></td>
                    </tr>
                <?php
                }
                ?>
            </tbody>
        </table>

        <p>
            <button type="button" id="brcc-save-mappings" class="button button-primary">
                <?php _e('Save Mappings', 'brcc-inventory-tracker'); ?>
            </button>
        </p>

        <div id="brcc-mapping-result" style="display: none;"></div>

        <!-- Test Square Connection Button -->
        <div class="brcc-section-connector">
            <h3><?php _e('Square Connection', 'brcc-inventory-tracker'); ?></h3>
            <p><?php _e('Test your Square connection and view available catalog items.', 'brcc-inventory-tracker'); ?></p>
            <button type="button" id="brcc-test-square-connection" class="button button-secondary">
                <?php _e('Test Square Connection', 'brcc-inventory-tracker'); ?>
            </button>
            <button type="button" id="brcc-fetch-square-catalog" class="button button-secondary">
                <?php _e('View Square Catalog', 'brcc-inventory-tracker'); ?>
            </button>
        </div>

        <!-- Square Catalog Items Container -->
        <div id="brcc-square-catalog-container" style="display: none; margin-top: 20px;">
            <h3><?php _e('Square Catalog Items', 'brcc-inventory-tracker'); ?></h3>
            <div id="brcc-square-catalog-items"></div>
        </div>

        <?php /* <!-- Date Mappings Modal HTML REMOVED --> */ ?>
        <?php /*
        <div id="brcc-date-mappings-modal" style="display: none;">
            ... modal content ...
        </div>
        */ ?>
    <?php
    }

    /**
     * Helper function to get cached Eventbrite events or fetch if cache is empty/expired.
     *
     * @return array|WP_Error Array of [event_id => 'Event Name (ID)'] or WP_Error on failure.
     */
    private function get_cached_eventbrite_events() {
        $transient_key = 'brcc_eventbrite_events_list';
        $cached_events = get_transient($transient_key);

        if ($cached_events !== false) {
            BRCC_Helpers::log_debug('get_cached_eventbrite_events: Returning cached events.');
            return $cached_events;
        }

        BRCC_Helpers::log_info('get_cached_eventbrite_events: Cache miss. Fetching events from Eventbrite API.');
        
        if (!class_exists('BRCC_Eventbrite_Integration')) {
            return new WP_Error('class_missing', 'BRCC_Eventbrite_Integration class not found.');
        }
        $eventbrite_integration = new BRCC_Eventbrite_Integration();
        
        // Fetch only LIVE events for mapping dropdowns (reverted from live,started due to potential errors)
        // Let the underlying get_organization_events handle pagination to get ALL live events
        $events_result = $eventbrite_integration->get_organization_events('live');

        // --- START DEBUG ---
        error_log('[BRCC DEBUG] Raw Eventbrite API Result in get_cached_eventbrite_events:');
        if (is_wp_error($events_result)) {
            error_log('[BRCC DEBUG] API returned WP_Error: ' . $events_result->get_error_message());
        } elseif (is_array($events_result)) {
            error_log('[BRCC DEBUG] API returned array with ' . count($events_result) . ' items.');
            // Optionally log the first few items if needed, but be mindful of log size
            // error_log('[BRCC DEBUG] First few items: ' . print_r(array_slice($events_result, 0, 2), true));
        } else {
            error_log('[BRCC DEBUG] API returned unexpected type: ' . gettype($events_result));
            error_log('[BRCC DEBUG] Raw value: ' . print_r($events_result, true));
        }
        // --- END DEBUG ---

        if (is_wp_error($events_result)) {
            BRCC_Helpers::log_error('get_cached_eventbrite_events: API error fetching events.', $events_result);
            // Cache the error for a short period to avoid repeated failed calls
            set_transient($transient_key, $events_result, MINUTE_IN_SECONDS * 5);
            return $events_result;
        }

        if (empty($events_result) || !is_array($events_result)) {
             BRCC_Helpers::log_warning('get_cached_eventbrite_events: No live events returned or invalid format from API.');
             $processed_events = array(); // Return empty array if no live events
        } else {
            $processed_events = array();
            $added_series_ids = array(); // Keep track of added parent series IDs
            $current_timestamp = time(); // Get current time for filtering past events

            foreach ($events_result as $event) {
                // Skip past events (TEMPORARILY DISABLED FOR DEBUGGING)
                $event_timestamp = isset($event['start']['local']) ? strtotime($event['start']['local']) : false;
                /*
                if (!$event_timestamp || $event_timestamp < $current_timestamp) {
                    // BRCC_Helpers::log_debug('get_cached_eventbrite_events: Skipping past event.', ['id' => $event['id'] ?? 'N/A', 'start' => $event['start']['local'] ?? 'N/A']); // Keep logging off
                    continue; // Skip this event if it's in the past or has no valid start time
                }
                */

                // Process the event (could be a standalone event or a child occurrence)
                if (isset($event['id']) && isset($event['name']['text'])) {
                    $start_time_str = '';
                    // Format the start time using the improved format
                    if ($event_timestamp) {
                        // Use WP date/time formats via date_i18n for localization
                        $date_format = get_option('date_format', 'M j, Y');
                        $time_format = get_option('time_format', 'g:i A');
                        $start_time_str = ' - ' . date_i18n($date_format . ' ' . $time_format, $event_timestamp);
                    }
                    // Decode HTML entities from the name
                    $event_name_decoded = isset($event['name']['text']) ? htmlspecialchars_decode($event['name']['text'], ENT_QUOTES) : 'Unnamed Event';
                    // Format as: Name - Date Time | ID: 12345
                    $processed_events[$event['id']] = sprintf('%s%s | ID: %s', $event_name_decoded, $start_time_str, $event['id']);
                }

                // If it's a child occurrence, also add the parent series ID if not already added
                if (
                    isset($event['is_series']) && $event['is_series'] === true &&
                    isset($event['is_series_parent']) && $event['is_series_parent'] === false &&
                    isset($event['series_id']) && !isset($processed_events[$event['series_id']]) &&
                    !in_array($event['series_id'], $added_series_ids)
                ) {
                    // Decode HTML entities from the name and mark it as a series
                    $parent_name_decoded = isset($event['name']['text']) ? htmlspecialchars_decode($event['name']['text'], ENT_QUOTES) : 'Unnamed Event';
                    $parent_name_formatted = $parent_name_decoded . ' (Series)';
                    // Add parent series without a specific time appended
                    $processed_events[$event['series_id']] = sprintf('%s (%s)', $parent_name_formatted, $event['series_id']);
                    $added_series_ids[] = $event['series_id']; // Mark parent as added
                }
            }

            // Sort events by date/time
            uasort($processed_events, function($a, $b) {
                // Extract timestamp from the formatted string (or use 0 if not found/parent series)
                $a_time_str = strpos($a, ' - ') !== false ? substr($a, strpos($a, ' - ') + 3) : null;
                $b_time_str = strpos($b, ' - ') !== false ? substr($b, strpos($b, ' - ') + 3) : null;
                
                $a_time = $a_time_str ? strtotime(str_replace(' | ', ' ', $a_time_str)) : 0; // Use 0 for series parents or if time missing
                $b_time = $b_time_str ? strtotime(str_replace(' | ', ' ', $b_time_str)) : 0; // Use 0 for series parents or if time missing

                // If times are the same (e.g., both are series parents or have same time), sort alphabetically by the full label
                if ($a_time === $b_time) {
                    return strcmp($a, $b);
                }
                
                // Otherwise, sort by time (ascending)
                return $a_time - $b_time;
            });

        } // This closes the 'else' block starting at line 1582

        // Cache the processed list for 1 hour
        set_transient($transient_key, $processed_events, HOUR_IN_SECONDS);
        BRCC_Helpers::log_info('get_cached_eventbrite_events: Successfully fetched and cached ' . count($processed_events) . ' events.');
        
        return $processed_events;
    }

    /**
     * AJAX handler to get Eventbrite ticket classes for a specific event.
     */
    public function ajax_get_eventbrite_tickets_for_event() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'brcc-inventory-tracker'), 403);
        }

        $event_id = isset($_POST['event_id']) ? sanitize_text_field($_POST['event_id']) : '';

        if (empty($event_id)) {
            wp_send_json_error(__('Event ID is required.', 'brcc-inventory-tracker'), 400);
        }

        BRCC_Helpers::log_debug('ajax_get_eventbrite_tickets_for_event: Fetching tickets for Event ID: ' . $event_id);

        if (!class_exists('BRCC_Eventbrite_Integration')) {
            wp_send_json_error(__('Eventbrite integration class not found.', 'brcc-inventory-tracker'), 500);
        }
        $eventbrite_integration = new BRCC_Eventbrite_Integration();
        $event_details = $eventbrite_integration->get_eventbrite_event($event_id);

        if (is_wp_error($event_details)) {
            BRCC_Helpers::log_error('ajax_get_eventbrite_tickets_for_event: API error fetching event details.', $event_details);
            wp_send_json_error(sprintf(__('Error fetching event details: %s', 'brcc-inventory-tracker'), $event_details->get_error_message()), 500);
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

    // Removed erroneous empty function definition for ajax_clear_eventbrite_cache here.
    // The correct definition exists later in the file.
    /**
     * AJAX handler to fetch Eventbrite events based on a Class ID.
     * Used to populate the event dropdown when a Class ID is provided.
     */
    // REMOVED: public function ajax_get_eventbrite_events_by_class() { ... }

    /**
     * AJAX handler to clear the Eventbrite events cache.
     * Corresponds to the button on the Settings page.
     */
    public function ajax_clear_eventbrite_cache() {
        check_ajax_referer('brcc-admin-nonce', 'nonce'); // Add nonce check back
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'brcc-inventory-tracker'), 403);
        }

        $deleted = delete_transient('brcc_eventbrite_events_list');

        if ($deleted) {
            BRCC_Helpers::log_info('ajax_clear_eventbrite_cache: Eventbrite events cache cleared successfully.');
            wp_send_json_success(__('Eventbrite events cache cleared.', 'brcc-inventory-tracker'));
        } else {
            BRCC_Helpers::log_warning('ajax_clear_eventbrite_cache: Attempted to clear cache, but transient was not found (might have expired or never existed).');
            wp_send_json_success(__('Eventbrite events cache was already clear or expired.', 'brcc-inventory-tracker'));
        }
    }
    /**
     * AJAX: Test Square connection
     */
    public function ajax_test_square_connection()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Initialize Square integration
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
    public function ajax_get_square_catalog()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Initialize Square integration
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

    public function ajax_test_square_mapping()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
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
     * Add CSS for the date mappings modal
     */
    public function add_modal_styles()
    {
    ?>
        <style type="text/css">
            /* Modal Styles */
            #brcc-date-mappings-modal {
                position: fixed;
                z-index: 100000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                overflow: auto;
                background-color: rgba(0, 0, 0, 0.4);
            }

            .brcc-modal-content {
                background-color: #fefefe;
                margin: 5% auto;
                padding: 20px;
                border: 1px solid #888;
                width: 80%;
                max-width: 900px;
                border-radius: 4px;
            }

            .brcc-modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 1px solid #ddd;
                padding-bottom: 10px;
                margin-bottom: 20px;
            }

            .brcc-modal-header h2 {
                margin: 0;
            }

            .brcc-modal-close {
                font-size: 24px;
                font-weight: bold;
                cursor: pointer;
            }

            .brcc-modal-footer {
                margin-top: 20px;
                text-align: right;
                border-top: 1px solid #ddd;
                padding-top: 15px;
            }

            .brcc-modal-footer button {
                margin-left: 10px;
            }

            #brcc-dates-table {
                margin-top: 15px;
            }

            .brcc-date-test-result {
                margin-top: 5px;
                display: none;
            }

            .brcc-test-mode-badge {
                background-color: #f0c33c;
                color: #333;
                font-size: 12px;
                font-weight: bold;
                padding: 2px 8px;
                border-radius: 10px;
            }

            .brcc-live-mode-badge {
                background-color: #46b450;
                color: #fff;
                font-size: 12px;
                font-weight: bold;
                padding: 2px 8px;
                border-radius: 10px;
            }

            .brcc-full-width {
                width: 100% !important;
                flex-basis: 100% !important;
            }

            .brcc-period-summary-table {
                width: 100%;
                border-collapse: collapse;
            }

            .brcc-period-summary-table th,
            .brcc-period-summary-table td {
                padding: 10px;
                text-align: center;
                border: 1px solid #ddd;
            }

            .brcc-period-summary-table th {
                background-color: #f5f5f5;
            }
        </style>
    <?php
    }

    /**
     * Add JavaScript for date-based mappings
     */
    public function add_date_mappings_js()
    {
    ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var currentProductId = null;
                var datesMappings = {};

                // Open modal when "View/Edit Dates" button is clicked
                $(document).on('click', '.brcc-view-dates', function() {
                    currentProductId = $(this).data('product-id');

                    // Reset modal content
                    $('#brcc-dates-table-body').html('');
                    $('#brcc-dates-table').hide();
                    $('#brcc-no-dates').hide();
                    $('#brcc-dates-loading').show();

                    // Open modal
                    $('#brcc-date-mappings-modal').show();

                    // Load dates for this product
                    $.ajax({
                        url: brcc_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'brcc_get_product_dates',
                            nonce: brcc_admin.nonce,
                            product_id: currentProductId
                        },
                        success: function(response) {
                            $('#brcc-dates-loading').hide();

                            if (response.success && response.data.dates && response.data.dates.length > 0) {
                                // Store date mappings for this product
                                datesMappings[currentProductId] = response.data.dates;

                                // Populate table
                                var html = '';
                                $.each(response.data.dates, function(index, date) {
                                    html += '<tr data-date="' + date.date + '">';
                                    html += '<td>' + date.formatted_date + '</td>';
                                    html += '<td>' + (date.inventory !== null ? date.inventory : 'N/A') + '</td>';
                                    html += '<td><input type="text" class="regular-text date-eventbrite-id" value="' + (date.eventbrite_id || '') + '" /></td>';
                                    html += '<td><button type="button" class="button brcc-test-date-mapping" data-date="' + date.date + '">' + brcc_admin.test + '</button>';
                                    html += '<div class="brcc-date-test-result"></div></td>';
                                    html += '</tr>';
                                });

                                $('#brcc-dates-table-body').html(html);
                                $('#brcc-dates-table').show();
                            } else {
                                $('#brcc-no-dates').show();
                            }
                        },
                        error: function() {
                            $('#brcc-dates-loading').hide();
                            $('#brcc-no-dates').html('<p>' + brcc_admin.ajax_error + '</p>').show();
                        }
                    });
                });

                // Close modal
                $('.brcc-modal-close, #brcc-close-modal').on('click', function() {
                    $('#brcc-date-mappings-modal').hide();
                });

                // Click outside to close
                $(window).on('click', function(event) {
                    if ($(event.target).is('#brcc-date-mappings-modal')) {
                        $('#brcc-date-mappings-modal').hide();
                    }
                });

                // Save date mappings
                $('#brcc-save-date-mappings').on('click', function() {
                    var $button = $(this);
                    $button.prop('disabled', true).text(brcc_admin.saving);

                    // Collect all date mappings for the current product
                    var mappings = [];
                    $('#brcc-dates-table-body tr').each(function() {
                        var $row = $(this);
                        var date = $row.data('date');
                        var eventbriteId = $row.find('.date-eventbrite-id').val();

                        mappings.push({
                            date: date,
                            eventbrite_id: eventbriteId
                        });
                    });

                    // Save via AJAX
                    $.ajax({
                        url: brcc_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'brcc_save_product_date_mappings',
                            nonce: brcc_admin.nonce,
                            product_id: currentProductId,
                            mappings: mappings
                        },
                        success: function(response) {
                            $button.prop('disabled', false).text('<?php _e('Save Date Mappings', 'brcc-inventory-tracker'); ?>');

                            if (response.success) {
                                // Update button text to reflect saved mappings
                                $('.brcc-view-dates[data-product-id="' + currentProductId + '"]').text('<?php _e('View/Edit Dates', 'brcc-inventory-tracker'); ?>');

                                // Show success message
                                alert(response.data.message);

                                // Close modal
                                $('#brcc-date-mappings-modal').hide();
                            } else {
                                alert(response.data.message || brcc_admin.ajax_error);
                            }
                        },
                        error: function() {
                            $button.prop('disabled', false).text('<?php _e('Save Date Mappings', 'brcc-inventory-tracker'); ?>');
                            alert(brcc_admin.ajax_error);
                        }
                    });
                });

                // Test date mapping
                $(document).on('click', '.brcc-test-date-mapping', function() {
                    var $button = $(this);
                    var date = $button.data('date');
                    var $row = $button.closest('tr');
                    var eventbriteId = $row.find('.date-eventbrite-id').val();
                    var $resultContainer = $row.find('.brcc-date-test-result');

                    $button.prop('disabled', true).text(brcc_admin.testing);
                    $resultContainer.hide();

                    $.ajax({
                        url: brcc_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'brcc_test_product_date_mapping',
                            nonce: brcc_admin.nonce,
                            product_id: currentProductId,
                            date: date,
                            eventbrite_id: eventbriteId
                        },
                        success: function(response) {
                            $button.prop('disabled', false).text(brcc_admin.test);

                            if (response.success) {
                                $resultContainer.html(response.data.message).show();
                            } else {
                                $resultContainer.html(response.data.message || brcc_admin.ajax_error).show();
                            }

                            // Hide the result after a few seconds
                            setTimeout(function() {
                                $resultContainer.fadeOut();
                            }, 8000);
                        },
                        error: function() {
                            $button.prop('disabled', false).text(brcc_admin.test);
                            $resultContainer.html(brcc_admin.ajax_error).show();

                            // Hide the result after a few seconds
                            setTimeout(function() {
                                $resultContainer.fadeOut();
                            }, 8000);
                        }
                    });
                });
            });
        </script>
        <?php
    }

    // Date Mapping AJAX Handlers are now added at the end of the class

    /**
     * AJAX: Regenerate API key
     */
    public function ajax_regenerate_api_key()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
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
    public function ajax_sync_inventory_now()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Log sync initiation
        if (BRCC_Helpers::is_test_mode()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Manual Sync',
                __('Manual sync triggered from admin dashboard', 'brcc-inventory-tracker')
            );
        } else if (BRCC_Helpers::should_log()) {
            BRCC_Helpers::log_operation(
                'Admin',
                'Manual Sync',
                __('Manual sync triggered from admin dashboard (Live Mode)', 'brcc-inventory-tracker')
            );
        }

        // Trigger sync action
        // Trigger sync action, passing true to indicate manual/daily sync
        do_action('brcc_sync_inventory', true);

        // Update last sync time
        update_option('brcc_last_sync_time', time());

        wp_send_json_success(array(
            'message' => __('Inventory synchronized successfully.', 'brcc-inventory-tracker'),
            'timestamp' => date_i18n(get_option('date_format') . ' ' . get_option('time_format')),
            'test_mode' => BRCC_Helpers::is_test_mode()
        ));
    }

    /**
     * AJAX: Save product mappings
     */
    public function ajax_save_product_mappings()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        // Get mappings from request
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        // Sanitize mappings
        $sanitized_mappings = array();
        foreach ($mappings as $product_id => $mapping) {
            $sanitized_mappings[absint($product_id)] = array(
                'eventbrite_id' => isset($mapping['eventbrite_id']) ? sanitize_text_field($mapping['eventbrite_id']) : '', // Ticket ID from dropdown
                'manual_eventbrite_id' => isset($mapping['manual_eventbrite_id']) ? sanitize_text_field($mapping['manual_eventbrite_id']) : '', // Ticket ID from manual input
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
             // Update/set the base mapping fields
             $all_mappings[$product_id]['eventbrite_id'] = $base_mapping['eventbrite_id'];
             $all_mappings[$product_id]['eventbrite_event_id'] = $base_mapping['eventbrite_event_id'];
             $all_mappings[$product_id]['square_id'] = $base_mapping['square_id'];
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
    public function ajax_test_product_mapping()
    {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $eventbrite_id = isset($_POST['eventbrite_id']) ? sanitize_text_field($_POST['eventbrite_id']) : '';

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
                    __('Eventbrite Event ID "%s" is linked to product "%s". Eventbrite credentials are configured.', 'brcc-inventory-tracker'), // Updated result message
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
     * AJAX: Get chart data
     */
    public function ajax_get_chart_data() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
            return;
        }

        try {
            $data = $this->get_chart_data();
            wp_send_json_success($data);
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }

    /**
     * Display Import Historical Data page
     */
    public function display_import_page()
    {
        ?>
        <div class="wrap">
            <h1><?php _e('Import Historical Sales Data', 'brcc-inventory-tracker'); ?></h1>
            <p><?php _e('Import past sales data from WooCommerce and/or Square to include it in the dashboard and reports.', 'brcc-inventory-tracker'); ?></p>
            <p><strong><?php _e('Important:', 'brcc-inventory-tracker'); ?></strong> <?php _e('Importing historical data will NOT affect your current live inventory on Eventbrite or Square.', 'brcc-inventory-tracker'); ?></p>

            <div id="brcc-import-controls">
                <h3><?php _e('Import Settings', 'brcc-inventory-tracker'); ?></h3>
                <table class="form-table">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="brcc-import-start-date"><?php _e('Start Date', 'brcc-inventory-tracker'); ?></label></th>
                            <td><input type="text" id="brcc-import-start-date" name="brcc_import_start_date" class="brcc-datepicker" placeholder="YYYY-MM-DD" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="brcc-import-end-date"><?php _e('End Date', 'brcc-inventory-tracker'); ?></label></th>
                            <td><input type="text" id="brcc-import-end-date" name="brcc_import_end_date" class="brcc-datepicker" placeholder="YYYY-MM-DD" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php _e('Data Sources', 'brcc-inventory-tracker'); ?></th>
                            <td>
                                <fieldset>
                                    <label for="brcc-import-source-wc">
                                        <input type="checkbox" id="brcc-import-source-wc" name="brcc_import_sources[]" value="woocommerce" checked="checked" />
                                        <?php _e('WooCommerce Orders', 'brcc-inventory-tracker'); ?>
                                    </label><br>
                                    <label for="brcc-import-source-sq">
                                        <input type="checkbox" id="brcc-import-source-sq" name="brcc_import_sources[]" value="square" />
                                        <?php _e('Square Orders', 'brcc-inventory-tracker'); ?>
                                        <?php
                                        // Check if Square is configured
                                        $settings = get_option('brcc_api_settings');
                                        $square_configured = !empty($settings['square_access_token']) && !empty($settings['square_location_id']);
                                        if (!$square_configured) {
                                            echo ' <span style="color: red;">(' . esc_html__('Square API not configured in Settings', 'brcc-inventory-tracker') . ')</span>'; // Escaped output
                                        }
                                        ?>
                                    </label><br>
                                    <label for="brcc-import-source-eb">
                                        <input type="checkbox" id="brcc-import-source-eb" name="brcc_import_sources[]" value="eventbrite" />
                                        <?php _e('Eventbrite Orders', 'brcc-inventory-tracker'); ?>
                                        <?php
                                        // Check if Eventbrite is configured
                                        $eventbrite_configured = !empty($settings['eventbrite_token']); // Add Org ID check if needed later
                                        if (!$eventbrite_configured) {
                                            echo ' <span style="color: red;">(' . esc_html__('Eventbrite API not configured in Settings', 'brcc-inventory-tracker') . ')</span>';
                                        }
                                        ?>
                                    </label>
                                </fieldset>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <?php wp_nonce_field('brcc-import-history-nonce', 'brcc_import_nonce'); ?>
                    <button type="button" id="brcc-start-import" class="button button-primary" <?php disabled(!$square_configured, true); ?>>
                        <?php _e('Start Import', 'brcc-inventory-tracker'); ?>
                    </button>
                    <?php if (!$square_configured): ?>
                <p style="color: red;"><?php _e('Square import disabled until API is configured in Settings.', 'brcc-inventory-tracker'); ?></p>
            <?php endif; ?>
            </p>
            </div>

            <div id="brcc-import-status" style="margin-top: 20px; padding: 15px; border: 1px solid #ccc; background-color: #f9f9f9; display: none;">
                <h3><?php _e('Import Status', 'brcc-inventory-tracker'); ?></h3>
                <div id="brcc-import-progress">
                    <p><?php _e('Import process started. Please do not close this window.', 'brcc-inventory-tracker'); ?></p>
                    <progress id="brcc-import-progress-bar" value="0" max="100" style="width: 100%;"></progress>
                    <p id="brcc-import-status-message"></p>
                </div>
                <div id="brcc-import-log" style="max-height: 300px; overflow-y: auto; background: #fff; border: 1px solid #eee; padding: 10px; margin-top: 10px; font-family: monospace; font-size: 12px; white-space: pre-wrap;">
                    <!-- Log messages will appear here -->
                </div>
                <button type="button" id="brcc-import-complete" class="button button-secondary" style="display: none; margin-top: 10px;"><?php _e('Import Complete - Close', 'brcc-inventory-tracker'); ?></button>
            </div>

        </div><!-- .wrap -->
<?php
    }

    /**
     * AJAX: Process a batch of historical data import
     */
    public function ajax_import_batch() {
        // Security checks
        if (!isset($_POST['state_data']['nonce']) || !wp_verify_nonce($_POST['state_data']['nonce'], 'brcc-import-history-nonce')) {
            BRCC_Helpers::log_error('ajax_import_batch: Nonce check failed.');
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
        $batch_size = 25; // Process 25 items per batch (Adjust as needed for performance/memory)

        // Validate dates
        if (!$start_date || !$end_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            wp_send_json_error(array('message' => __('Invalid date range provided.', 'brcc-inventory-tracker')));
        }
        if (strtotime($start_date) > strtotime($end_date)) {
            wp_send_json_error(array('message' => __('Start date cannot be after end date.', 'brcc-inventory-tracker')));
        }
        if (empty($sources)) {
            wp_send_json_error(array('message' => __('No data sources selected.', 'brcc-inventory-tracker')));
        }

        $logs = array();
        $processed_count_total = 0;
        $next_offset = null; // Assume completion unless set otherwise
        $progress_message = '';
        $progress = 0;

        try {
            // State is now directly passed and validated above

            $current_source_index = $state['source_index'];
            $total_processed      = $state['total_processed'];
            // BRCC_Helpers::log_debug('ajax_import_batch: Current State', $state); // Removed debug log
            
            if ($current_source_index >= count($sources)) {
                // Should not happen if JS stops calling, but handle defensively
                wp_send_json_success(array(
                    'message'       => 'Import already completed.',
                    'logs'          => $logs,
                    'progress'      => 100,
                    'next_offset'   => null, // Signal JS to stop
                ));
            }

            // Add check for valid source index before accessing
            if (!isset($sources[$current_source_index])) {
                 $error_msg = 'Error: Invalid source index.';
                 BRCC_Helpers::log_error($error_msg . ' State: ' . print_r($state, true) . ' Sources: ' . print_r($sources, true));
                 throw new Exception($error_msg);
            }
            $current_source = $sources[$current_source_index];
            // BRCC_Helpers::log_debug("ajax_import_batch: Processing source: {$current_source} at index {$current_source_index}. Offset/Cursor: " . ($current_source === 'woocommerce' ? $state['wc_offset'] : $state['square_cursor'])); // Removed debug log
            $logs[] = array('message' => "--- Starting batch for source: {$current_source} ---", 'type' => 'info');

            $batch_result = array(
                'processed_count' => 0,
                'next_offset'     => null, // Contains next WC offset or Square cursor
                'source_complete' => false,
                'logs'            => array()
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
                $page = isset($state['eventbrite_page']) ? absint($state['eventbrite_page']) : 1; // Get current page from state (Added isset check)
                $logs[] = array('message' => "Processing Eventbrite batch (Page: {$page})...", 'type' => 'info');
                $sales_tracker = new BRCC_Sales_Tracker(); // Sales tracker handles the recording
                if (method_exists($sales_tracker, 'import_eventbrite_batch')) {
                    BRCC_Helpers::log_debug("ajax_import_batch: Calling import_eventbrite_batch", ['start' => $start_date, 'end' => $end_date, 'page' => $page]); // Re-added debug log
                    BRCC_Helpers::log_debug("ajax_import_batch: Calling import_eventbrite_batch", ['start' => $start_date, 'end' => $end_date, 'page' => $page]); // Re-added debug log
                    $batch_result = $sales_tracker->import_eventbrite_batch($start_date, $end_date, $page, $batch_size);
                    $state['eventbrite_page'] = $batch_result['next_offset']; // Update Eventbrite page for next time (next_offset holds next page number or null)
                } else {
                    $logs[] = array('message' => "Eventbrite import logic not found in BRCC_Sales_Tracker.", 'type' => 'error');
                    $batch_result['source_complete'] = true; // Skip this source
                }
            } else { // This 'else' now correctly follows the 'elseif eventbrite' block from line 2641
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
            } // End of 'else' for source_complete check
        } // <<< Closing brace for the 'try' block starting around line 2584
        catch (Exception $e) {
            // Handle exceptions during batch processing
            $error_msg = 'Import Error: ' . $e->getMessage();
            BRCC_Helpers::log_error($error_msg . ' State: ' . print_r($state ?? [], true));
            $logs[] = array('message' => $error_msg, 'type' => 'error');
            wp_send_json_error(array(
                'message' => $error_msg,
                'logs' => $logs,
            ));
        }

        // Send success response (even if moving to next batch/source)
        wp_send_json_success(array(
            'message'       => $progress_message,
            'logs'          => $logs,
            'progress'      => $progress,
            'next_state'    => $next_state_param // Send back the state for the next JS call (will be null if complete)
        ));
    } // End ajax_import_batch method
        // Removed misplaced closing brace and orphaned parenthesis from lines below
        // Removed duplicate catch block and stray brace from lines below

    // The closing brace for the class is correctly placed after all methods (line 2490)


    /**
     * AJAX: Suggest Eventbrite ID for a product
     */
    public function ajax_suggest_eventbrite_id() {
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            BRCC_Helpers::log_error('Suggest Eventbrite ID Error: Nonce check failed.');
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Error: Insufficient permissions.');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }
        
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        if (empty($product_id)) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Error: Product ID missing.');
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
        }
        
        BRCC_Helpers::log_operation('Admin', 'Suggest Eventbrite ID', 'Attempting to suggest ID for Product ID: ' . $product_id);

        $product = wc_get_product($product_id);
        if (!$product) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Error: Product not found for ID: ' . $product_id);
             wp_send_json_error(array('message' => __('Product not found.', 'brcc-inventory-tracker')));
        }
        $product_sku = $product->get_sku();
        // BRCC_Helpers::log_debug("Suggest Eventbrite ID: Product SKU found: '{$product_sku}'"); // Removed debug log
        
        // Check if Eventbrite integration class exists
        if (!class_exists('BRCC_Eventbrite_Integration')) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Error: BRCC_Eventbrite_Integration class not found.');
             wp_send_json_error(array('message' => __('Eventbrite Integration is not available.', 'brcc-inventory-tracker')));
        }
        
        try {
            $eventbrite_integration = new BRCC_Eventbrite_Integration();
            
            // Get suggestions (passing product object, no specific date/time)
            // Pass SKU to the suggestion function (we'll modify the function next)
            $suggestions = $eventbrite_integration->suggest_eventbrite_ids_for_product($product, null, null, $product_sku);
            
            // Check if the suggestion function returned an error
            if (is_wp_error($suggestions)) {
                 /** @var WP_Error $suggestions */ // Hint for Intelephense
                 $error_message = $suggestions->get_error_message();
                 BRCC_Helpers::log_error('Suggest Eventbrite ID Error: suggest_eventbrite_ids_for_product returned WP_Error: ' . $error_message);
                 wp_send_json_error(array('message' => $error_message));
            } elseif (empty($suggestions)) {
                 BRCC_Helpers::log_operation('Admin', 'Suggest Eventbrite ID Result', 'No relevant Eventbrite events/tickets found for Product ID: ' . $product_id);
                 wp_send_json_error(array('message' => __('No relevant Eventbrite events/tickets found.', 'brcc-inventory-tracker')));
            } else {
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
            }
        } catch (Exception $e) {
             BRCC_Helpers::log_error('Suggest Eventbrite ID Exception: ' . $e->getMessage());
             wp_send_json_error(array('message' => 'An unexpected error occurred while suggesting ID.'));
        }
    } // Added missing closing brace for the previous function
       /**
        * AJAX handler for suggesting Eventbrite Ticket ID for a specific date/time.
        */
       // REMOVED: ajax_suggest_eventbrite_ticket_id_for_date()
   /**
    * Display the Attendee Lists page
    */
   public function display_attendee_list_page() {
       ?>
       <div class="wrap brcc-attendee-list-page"> <?php // Added class for styling ?>
           <h1><?php _e('Attendee Lists by Date', 'brcc-inventory-tracker'); ?></h1>
           <p><?php _e('Select a date to view attendee lists for all products (ticket classes) scheduled on that day.', 'brcc-inventory-tracker'); ?></p>

           <div class="brcc-controls-container"> <?php // Container for controls - will be styled as a row ?>
               <div class="brcc-control-group">
                   <label for="brcc-attendee-date-select"><?php _e('Choose Date:', 'brcc-inventory-tracker'); ?></label>
                   <input type="text" id="brcc-attendee-date-select" name="brcc_attendee_date" class="brcc-datepicker" placeholder="YYYY-MM-DD" style="vertical-align: middle;" autocomplete="off"/>
               </div>

               <div class="brcc-control-group"> <?php // Add filter dropdown ?>
                   <label for="brcc-attendee-source-filter"><?php _e('Filter Source:', 'brcc-inventory-tracker'); ?></label>
                   <select id="brcc-attendee-source-filter" name="brcc-attendee-source-filter" style="vertical-align: middle;">
                       <option value="all" selected><?php _e('All Sources', 'brcc-inventory-tracker'); ?></option>
                       <option value="woocommerce"><?php _e('WooCommerce Only', 'brcc-inventory-tracker'); ?></option>
                       <option value="eventbrite"><?php _e('Eventbrite Only', 'brcc-inventory-tracker'); ?></option>
                   </select>
               </div>

               <?php /* Removed Product/Event selector - will be handled by lazy loading
               <div class="brcc-control-group">
                   <label for="brcc-attendee-product-select"><?php _e('Choose Product:', 'brcc-inventory-tracker'); ?></label>
                   <select id="brcc-attendee-product-select" name="brcc_attendee_product_id" style="min-width: 300px; max-width: 500px; width: auto; vertical-align: middle;" disabled>
                       <option value=""><?php _e('-- Select Date First --', 'brcc-inventory-tracker'); ?></option>
                       <?php // Options might be populated later if needed for filtering, but not for initial load ?>
                   </select>
                   <span id="brcc-product-loading-spinner" class="spinner" style="float: none; vertical-align: middle; margin-left: 5px; visibility: hidden;"></span>
               </div>
               */ ?>

               <?php // Removed Add Event Button ?>
           </div>

           <hr> <?php // Separator ?>

           <div id="brcc-attendee-list-container" style="margin-top: 20px;">
               <!-- Lazy-loaded product attendee lists will appear here -->
               <p class="brcc-initial-message"><?php _e('Please select a date to load attendee lists.', 'brcc-inventory-tracker'); ?></p>
           </div>
       </div>
       <?php
   }


   /**
    * Sends the daily attendee list email via WP-Cron.
    */
   public function send_daily_attendee_email() {
       BRCC_Helpers::log_info('Starting daily attendee email cron job.');

       $target_date = date('Y-m-d', strtotime('+1 day')); // Get tomorrow's date
       $email_subject = sprintf(__('Attendee List for %s - %s', 'brcc-inventory-tracker'), get_bloginfo('name'), $target_date);
       $email_body = '<h1>' . sprintf(__('Attendee List for %s', 'brcc-inventory-tracker'), $target_date) . '</h1>';
       $email_body .= '<p>' . sprintf(__('Generated on: %s', 'brcc-inventory-tracker'), date('Y-m-d H:i:s')) . '</p>';
       $email_body .= '<hr>';

       $found_attendees = false;
       $errors = array();

       // Get all product mappings
       $all_mappings = get_option('brcc_product_mappings', array());
       $eventbrite_integration = new BRCC_Eventbrite_Integration(); // Instantiate once

       foreach ($all_mappings as $product_id => $mapping) {
           // Skip date collections or products without an Eventbrite Event ID
           if (strpos($product_id, '_dates') !== false || empty($mapping['eventbrite_event_id'])) {
               continue;
           }

           $event_id = $mapping['eventbrite_event_id'];
           $product = wc_get_product($product_id);
           if (!$product) continue;

           // Check if the Eventbrite event associated with this product occurs on the target date
           // We need to fetch the event details to check its date
           $event_details = $eventbrite_integration->get_eventbrite_event($event_id);

           if (is_wp_error($event_details)) {
               $errors[] = sprintf(__('Error fetching details for Eventbrite Event ID %s: %s', 'brcc-inventory-tracker'), $event_id, $event_details->get_error_message());
               continue;
           }

           $event_start_date = isset($event_details['start']['local']) ? date('Y-m-d', strtotime($event_details['start']['local'])) : null;

           // If the event is not on the target date, skip it
           if ($event_start_date !== $target_date) {
               continue;
           }

           $email_body .= '<h2>' . sprintf(__('Attendees for: %s', 'brcc-inventory-tracker'), esc_html($product->get_name())) . '</h2>';

           // Fetch attendees
           $attendees = array();

           // Fetch WooCommerce Orders for this product on the target date (more specific if possible)
           // Note: WC Orders don't directly store the 'event date', only purchase date.
           // We might need to rely on product variations or custom meta if filtering by event date is needed here.
           // For now, we'll fetch based on product ID and assume they relate to the event if the event is tomorrow.
           try {
               $args = array(
                   'limit' => -1,
                   'status' => array('wc-processing', 'wc-completed'),
               );
               $orders = wc_get_orders($args);
               $wc_attendees_for_product = 0;

               foreach ($orders as $order) {
                    $found_product = false;
                    foreach ($order->get_items() as $item_id => $item) {
                        if ($item->get_product_id() == $product_id || $item->get_variation_id() == $product_id) {
                            $found_product = true;
                            break;
                        }
                    }
                    if ($found_product) {
                       $attendees[] = array(
                           'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                           'email' => $order->get_billing_email(),
                           'source' => 'WooCommerce',
                           'purchase_date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : '',
                       );
                       $wc_attendees_for_product++;
                    }
               }
                if ($wc_attendees_for_product > 0) $found_attendees = true;
           } catch (Exception $e) {
               $errors[] = sprintf(__('Error fetching WooCommerce orders for Product ID %s: %s', 'brcc-inventory-tracker'), $product_id, $e->getMessage());
           }

           // Fetch Eventbrite Attendees
           try {
               $eventbrite_attendees = $eventbrite_integration->get_event_attendees($event_id);

               if (is_wp_error($eventbrite_attendees)) {
                    $errors[] = sprintf(__('Error fetching Eventbrite attendees for Event ID %s: %s', 'brcc-inventory-tracker'), $event_id, $eventbrite_attendees->get_error_message());
               } elseif (is_array($eventbrite_attendees)) {
                   if (count($eventbrite_attendees) > 0) $found_attendees = true;
                   foreach ($eventbrite_attendees as $attendee) {
                       $attendees[] = array(
                           'name' => isset($attendee['profile']['name']) ? $attendee['profile']['name'] : 'N/A',
                           'email' => isset($attendee['profile']['email']) ? $attendee['profile']['email'] : 'N/A',
                           'source' => 'Eventbrite',
                           'purchase_date' => isset($attendee['created']) ? date('Y-m-d H:i:s', strtotime($attendee['created'])) : '',
                       );
                   }
               }
           } catch (Exception $e) {
                $errors[] = sprintf(__('Error fetching Eventbrite attendees for Event ID %s: %s', 'brcc-inventory-tracker'), $event_id, $e->getMessage());
           }
        // Removed misplaced closing brace from previous line

           // Format table for this product
           if (!empty($attendees)) {
                // Sort by name for easier reading
                usort($attendees, function($a, $b) {
                    return strcmp(strtolower($a['name']), strtolower($b['name']));
                });

                $email_body .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
                $email_body .= '<thead><tr style="background-color: #f2f2f2;"><th>Name</th><th>Email</th><th>Source</th><th>Purchase Date</th></tr></thead>';
                $email_body .= '<tbody>';
                foreach ($attendees as $att) {
                    $email_body .= '<tr>';
                    $email_body .= '<td>' . esc_html($att['name']) . '</td>';
                    $email_body .= '<td>' . esc_html($att['email']) . '</td>';
                    $email_body .= '<td>' . esc_html($att['source']) . '</td>';
                    $email_body .= '<td>' . esc_html($att['purchase_date']) . '</td>';
                    $email_body .= '</tr>';
                }
                $email_body .= '</tbody></table>';
           } else {
               $email_body .= '<p>' . __('No attendees found for this event.', 'brcc-inventory-tracker') . '</p>';
           }
            $email_body .= '<hr>';

       } // End foreach product mapping

       // Add errors to email body if any occurred
       if (!empty($errors)) {
           $email_body .= '<h2>' . __('Errors Encountered:', 'brcc-inventory-tracker') . '</h2>';
           $email_body .= '<ul>';
           foreach ($errors as $error) {
               $email_body .= '<li>' . esc_html($error) . '</li>';
           }
           $email_body .= '</ul>';
           $email_subject .= ' (' . __('Errors Occurred', 'brcc-inventory-tracker') . ')';
       }

       // Only send email if attendees were found or errors occurred
       if ($found_attendees || !empty($errors)) {
           $to = 'webadmin@jmplaunch.com, backroomcomedyclub@gmail.com';
           $headers = array('Content-Type: text/html; charset=UTF-8');

           BRCC_Helpers::log_info('Sending daily attendee email to: ' . $to);
           wp_mail($to, $email_subject, $email_body, $headers);
       } else {
            BRCC_Helpers::log_info('No attendees found for tomorrow or errors occurred. Skipping daily email.');
       }

        BRCC_Helpers::log_info('Finished daily attendee email cron job.');
  }

   /**
    * AJAX handler to fetch all relevant Eventbrite events for the Attendee List dropdown.
    * Uses the same cached event list as the mapping settings page.
    * Hooked to: wp_ajax_brcc_get_all_eventbrite_events_for_attendees
    * Fetches Eventbrite events, optionally filtered by a specific date.
    */
   public function ajax_get_all_eventbrite_events_for_attendees() {
       // Check nonce and capability
       if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
           wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
       }
       if (!current_user_can('manage_options')) { // Check capability
           wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
       }

       $selected_date = isset($_POST['selected_date']) ? sanitize_text_field($_POST['selected_date']) : null;

       // Validate date format if provided
       if ($selected_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
           wp_send_json_error(array('message' => __('Invalid date format provided.', 'brcc-inventory-tracker')));
           return;
       }

       // Ensure a date is selected, as this handler now requires it
       if (!$selected_date) {
            wp_send_json_error(array('message' => __('Please select a date first.', 'brcc-inventory-tracker')));
            return;
       }
       // --- Cache Check ---
       $cache_key = 'brcc_events_for_date_' . $selected_date;
       $cached_events = get_transient($cache_key);

       if (false !== $cached_events) {
           BRCC_Helpers::log_info("Attendee Event Dropdown: Using cached events for date: $selected_date");
           // Ensure array keys are preserved if needed by Select2/frontend
           wp_send_json_success(array('events' => (array) $cached_events)); // Cast to array just in case
           return;
       }

       // --- Fetch from API if not cached ---
       BRCC_Helpers::log_info("Attendee Event Dropdown: Cache miss. Fetching events for date: $selected_date from API.");

       // Fetch the list of events (ID => Label) for the specific date
       if (!class_exists('BRCC_Eventbrite_Integration')) {
            wp_send_json_error(array('message' => __('Eventbrite Integration class not found.', 'brcc-inventory-tracker')));
            return;
       }
       $eventbrite_integration = new BRCC_Eventbrite_Integration();
       $events_for_dropdown = array();
       $error_message = null;

       // Ensure the date-specific function exists
       if (!method_exists($eventbrite_integration, 'get_events_for_date')) {
            wp_send_json_error(array('message' => __('Required function get_events_for_date not found in integration class.', 'brcc-inventory-tracker')));
            return;
       }

       try {
           // Fetch events specifically for the selected date
           $events_on_date = $eventbrite_integration->get_events_for_date($selected_date);

           if (is_wp_error($events_on_date)) {
               $error_message = $events_on_date->get_error_message();
               BRCC_Helpers::log_error("Error in get_events_for_date for $selected_date: " . $error_message);
           } elseif (is_array($events_on_date)) {
               // Process the result (assuming it's an array of event objects/arrays)
               foreach ($events_on_date as $event) {
                   // Adjust keys based on actual return structure of get_events_for_date
                   $event_id = isset($event['id']) ? $event['id'] : null;
                   $event_name = isset($event['name']['text']) ? $event['name']['text'] : (isset($event['name']) ? $event['name'] : null); // Eventbrite often uses name.text
                   $event_time = isset($event['start']['local']) ? date('g:i A', strtotime($event['start']['local'])) : ''; // Format time

                   if ($event_id && $event_name) {
                       // Append time to the name for clarity in the dropdown
                       $events_for_dropdown[$event_id] = $event_name . ($event_time ? ' (' . $event_time . ')' : '');
                   }
               }
               if (empty($events_for_dropdown)) {
                    // Don't treat empty results as an error, just send empty array
                    BRCC_Helpers::log_info("No events found for date: $selected_date");
               }
           } else {
                $error_message = __('Unexpected response format from get_events_for_date.', 'brcc-inventory-tracker');
                BRCC_Helpers::log_error($error_message . " Response: " . print_r($events_on_date, true));
           }

       } catch (Throwable $e) {
            $error_message = "Exception calling get_events_for_date: " . $e->getMessage();
            BRCC_Helpers::log_error($error_message); // Log exception
       }


       if ($error_message) {
           wp_send_json_error(array(
               'message' => __('Error fetching Eventbrite events: ', 'brcc-inventory-tracker') . $error_message
           ));
       } else {
           // Sort events alphabetically by name (label)
           uasort($events_for_dropdown, function($a, $b) {
               return strcasecmp($a, $b);
           });
           // Remove duplicate events (already done by using event_id as key initially, but array_unique handles value duplicates if names/times were identical but IDs different somehow)
           // $events_for_dropdown = array_unique($events_for_dropdown); // Keep keys with uasort

           // --- Save to Cache ---
           set_transient($cache_key, $events_for_dropdown, 1 * HOUR_IN_SECONDS); // Cache for 1 hour
           BRCC_Helpers::log_info("Attendee Event Dropdown: Saved fetched events to cache for date: $selected_date");
           wp_send_json_success(array('events' => $events_for_dropdown));
       }
   }
    
    // Removed ajax_get_event_series function
    
           // TODO: Add logic to find relevant WooCommerce/FooEvents Products/Shows that act as series?
    
           // The following lines were part of the removed ajax_get_event_series function
           // and caused a syntax error. They are now removed.
           /*
           if (!empty($errors)) {
                wp_send_json_error(array(
                    'message' => implode('; ', $errors),
                    'series' => $series_for_dropdown // Send empty array on error
                ));
           } else {
                wp_send_json_success(array(
           */
           // End of removed block
           // These lines were also part of the removed function and are now deleted.

   // This closing brace was part of the removed ajax_get_event_series function and is now deleted.

   /**
    * AJAX: Test Eventbrite Connection
    */
   public function ajax_test_eventbrite_connection() {
       // Check nonce
       if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
           wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
       }

       // Instantiate the Eventbrite integration class
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
    public function ajax_get_product_dates()
    {
        // Pagination parameters
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 25; // Default 25 per page
        $offset = ($page - 1) * $per_page;
        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

                // --- DEBUGGING: Confirm function start and class existence ---
                BRCC_Helpers::log_debug('ajax_get_product_dates: Function started for Product ID: ' . ($_POST['product_id'] ?? 'N/A'));
                $integration_exists = class_exists('BRCC_Eventbrite_Integration');
                BRCC_Helpers::log_debug('ajax_get_product_dates: BRCC_Eventbrite_Integration class exists? ' . ($integration_exists ? 'Yes' : 'No'));
                // --- END DEBUGGING ---
        
                $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        
                if (empty($product_id)) {
                    wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
                }
        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => __('Product not found.', 'brcc-inventory-tracker')));
        }

        // Get date-specific mappings
        $all_mappings = get_option('brcc_product_mappings', array());
        $date_mappings = isset($all_mappings[$product_id . '_dates']) ? $all_mappings[$product_id . '_dates'] : array();
        // Roo Debug Log: Log data loaded from option
        BRCC_Helpers::log_debug('ajax_get_product_dates: Loaded date mappings for Product ID ' . $product_id . ' from option:', $date_mappings);

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

                // Get ticket name if available (Placeholder - real lookup might be slow here)
                $ticket_name = '';
                 // Get saved values, defaulting to empty strings or null
                 $event_id = isset($mapping['eventbrite_event_id']) ? $mapping['eventbrite_event_id'] : '';
                 $ticket_class_id = isset($mapping['eventbrite_ticket_class_id']) ? $mapping['eventbrite_ticket_class_id'] : '';
                 $square_id = isset($mapping['square_id']) ? $mapping['square_id'] : '';
                 $time = isset($mapping['time']) ? $mapping['time'] : null;

                 // Format time for display if it exists
                 $formatted_time = $time ? date_i18n(get_option('time_format'), strtotime($time)) : '';

                 // Use ticket class ID for display name if available
                 $ticket_name = '';
                 if (!empty($ticket_class_id)) {
                     $ticket_name = sprintf(__('Ticket Class ID: %s', 'brcc-inventory-tracker'), $ticket_class_id);
                 } elseif (!empty($event_id)) {
                     // Fallback to event ID if ticket class ID is empty but event ID exists
                     // You might want to fetch the event name here if needed, but keep it simple for now
                     $ticket_name = sprintf(__('Event ID: %s', 'brcc-inventory-tracker'), $event_id);
                 }


                $dates[] = array(
                   'date' => $date,
                   'formatted_date' => $formatted_date,
                   'time' => $time, // Send raw time value
                   'formatted_time' => $formatted_time, // Send formatted time
                   'inventory' => $inventory,
                   'eventbrite_event_id' => $event_id, // From dropdown selection
                   // Use correct key, check old key for backward compatibility
                   'eventbrite_ticket_class_id' => isset($mapping['eventbrite_ticket_class_id'])
                       ? $mapping['eventbrite_ticket_class_id']
                       : (isset($mapping['eventbrite_id']) ? $mapping['eventbrite_id'] : ''),
                   'eventbrite_id' => isset($mapping['eventbrite_id']) ? $mapping['eventbrite_id'] : '', // Keep old key for backward compatibility if needed elsewhere
                   'square_id' => $square_id, // Square ID
                   'ticket_name' => $ticket_name, // Display name (can be improved)
                   'manual_eventbrite_id' => isset($mapping['manual_eventbrite_id']) ? $mapping['manual_eventbrite_id'] : '', // Add manual ticket ID
               );
            }
        }

        // Suggestion logic might need adjustment with pagination, perhaps only show on page 1 if empty?
        // For now, removing the suggestion logic as it complicates pagination.
        // It can be added back to the JS if needed when page 1 is loaded and $total_mappings is 0.

        // Sorting is now done before slicing
        
        // Get Eventbrite events for dropdown
        $events = array();
        // --- Get Eventbrite events ONLY from CACHE during AJAX ---
        // The cache should be populated by the main page load or a background task.
        // Avoid triggering a potentially long API fetch within this AJAX handler.
        $transient_key = 'brcc_eventbrite_events_list'; // Use the same key as get_cached_eventbrite_events
        $events = get_transient($transient_key);

        if ($events === false) {
            // Cache is empty or expired, return empty array for dropdowns in this context.
            BRCC_Helpers::log_warning('ajax_get_product_dates: Eventbrite events cache is empty. Dropdowns will be empty. Cache needs refresh.');
            $events = array();
        } elseif (is_wp_error($events)) {
            // Handle case where an error object was cached
             BRCC_Helpers::log_error('ajax_get_product_dates: WP_Error found in events cache.', $events);
            $events = array();
        }
        // --- END Cache Check ---

        // --- DEBUGGING: Log the events array immediately after fetching/checking ---
            BRCC_Helpers::log_debug('ajax_get_product_dates: Events data fetched/retrieved:', $events);
            BRCC_Helpers::log_debug('ajax_get_product_dates: Type of events data:', gettype($events));
            // --- END DEBUGGING ---
// Removed stray closing brace from previous edit

        // --- DEBUGGING: Log the events array before sending ---
        // BRCC_Helpers::log_debug('ajax_get_product_dates: Events data being sent to JS:', $events); // Moved earlier
        // BRCC_Helpers::log_debug('ajax_get_product_dates: Type of events data:', gettype($events)); // Moved earlier
        // --- END DEBUGGING ---

        wp_send_json_success(array(
            'dates' => $dates, // Only dates for the current page
            //'events' => $events,
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
    public function ajax_save_product_date_mappings()
    {
        // DEBUG: Log incoming POST data
        if (isset($_POST['mappings'])) {
            error_log('DEBUG: Incoming AJAX data for save_product_date_mappings: ' . print_r($_POST['mappings'], true));
        } else {
            error_log('DEBUG: No mappings data received in POST for save_product_date_mappings.');
        }

        // Check nonce and capability
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $mappings = isset($_POST['mappings']) ? $_POST['mappings'] : array();

        if (empty($product_id)) {
            wp_send_json_error(array('message' => __('Product ID is required.', 'brcc-inventory-tracker')));
        }

        // Get the product
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => __('Product not found.', 'brcc-inventory-tracker')));
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
        $first_manual_key = null; // For verification log
        $expected_manual_value = null; // For verification log
        foreach ($mappings as $mapping) {
            if (empty($mapping['date'])) continue;
            
            $date = sanitize_text_field($mapping['date']);
            $time = isset($mapping['time']) ? sanitize_text_field($mapping['time']) : null;
            $event_id = isset($mapping['eventbrite_event_id']) ? sanitize_text_field($mapping['eventbrite_event_id']) : '';
            
            // Check for both possible keys, prioritizing the correct one
            $ticket_class_id = isset($mapping['manual_eventbrite_id'])
                ? sanitize_text_field($mapping['manual_eventbrite_id'])
                : (isset($mapping['eventbrite_ticket_class_id']) ? sanitize_text_field($mapping['eventbrite_ticket_class_id']) : '');
            
            $square_id = isset($mapping['square_id']) ? sanitize_text_field($mapping['square_id']) : ''; // Get Square ID

            $current_mapping = array(
                'eventbrite_event_id' => $event_id, // From dropdown
                'manual_eventbrite_id' => $ticket_class_id, // From manual input field
                'eventbrite_id' => $ticket_class_id, // Keep old key for backward compatibility if needed elsewhere
                'square_id' => $square_id,
                'ticket_class_id' => $ticket_class_id // Save ticket class ID
            );

            // Store the first processed manual key and value for verification
            if ($first_manual_key === null && !empty($ticket_class_id)) {
                $first_manual_key = $date; // Use $date as the key for verification
                $expected_manual_value = $ticket_class_id;
                BRCC_Helpers::log_debug('ajax_save_product_date_mappings: Storing first manual key for verification: ' . $first_manual_key . ' with value: ' . $expected_manual_value);
            }

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
            // Roo Debug Log: Log data being prepared for saving
            BRCC_Helpers::log_debug('ajax_save_product_date_mappings: Preparing to save date mappings for Product ID ' . $product_id, $date_mappings);
        }
        
        // Update mappings
        // Roo Debug Log: Log the entire structure being saved
        BRCC_Helpers::log_debug('ajax_save_product_date_mappings: Final data structure BEFORE update_option:', $all_mappings); // Added more specific log message
        // DEBUG: Log data just before saving with error_log
        error_log('DEBUG: Data to be saved to brcc_product_mappings: ' . print_r($all_mappings, true));
        $updated = update_option('brcc_product_mappings', $all_mappings);

        // DEBUG: Immediately verify if the first manual_eventbrite_id was saved (if applicable)
        if ($updated && $first_manual_key !== null) {
             $saved_value = get_option('brcc_product_mappings');
             $saved_date_mapping = $saved_value[$product_id.'_dates'][$first_manual_key] ?? null;

             if (!isset($saved_date_mapping['manual_eventbrite_id'])) {
                 error_log('DEBUG: VERIFICATION FAILED - manual_eventbrite_id missing after save!');
                 error_log('DEBUG: Product ID: ' . $product_id);
                 error_log('DEBUG: Date Key Checked: ' . $first_manual_key);
                 error_log('DEBUG: Expected Manual ID: ' . $expected_manual_value);
                 error_log('DEBUG: Actual saved value for product dates: ' . print_r($saved_value[$product_id.'_dates'] ?? 'Not Set', true));
                 error_log('DEBUG: Full saved option: ' . print_r($saved_value, true));
             } else {
                 error_log('DEBUG: VERIFICATION PASSED - manual_eventbrite_id found after save for key ' . $product_id.'_dates'.'['.$first_manual_key.']');
             }
        } elseif ($updated) {
             error_log('DEBUG: Option updated, but no manual_eventbrite_id was processed in this request for verification.');
        } elseif (!$updated) {
             error_log('DEBUG: update_option returned false. Option may not have changed or an error occurred.');
        }


        wp_send_json_success(array(
            'message' => __('Date mappings saved successfully.', 'brcc-inventory-tracker'),
            'count' => count($date_mappings) // Use the count from the loop
        ));
    }

    /**
     * AJAX: Test product date mapping
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
        $eventbrite_event_id = isset($_POST['eventbrite_event_id']) ? sanitize_text_field($_POST['eventbrite_event_id']) : '';
        $eventbrite_id = isset($_POST['eventbrite_id']) ? sanitize_text_field($_POST['eventbrite_id']) : '';

        if (empty($product_id) || empty($date)) {
            wp_send_json_error(array('message' => __('Product ID and date are required.', 'brcc-inventory-tracker')));
        }

        $results = array();

        // Get the product name for more informative messages
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : "Product #$product_id";

        // Format the date for display
        $formatted_date = date_i18n(get_option('date_format'), strtotime($date));

        // Log test action
        if (BRCC_Helpers::is_test_mode()) {
            if (!empty($eventbrite_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Date Mapping',
                    sprintf(
                        __('Testing date mapping for product ID %s on date %s with Eventbrite ID %s', 'brcc-inventory-tracker'),
                        $product_id,
                        $formatted_date,
                        $eventbrite_id
                    )
                );
            }
        } else if (BRCC_Helpers::should_log()) {
            if (!empty($eventbrite_id)) {
                BRCC_Helpers::log_operation(
                    'Admin',
                    'Test Date Mapping',
                    sprintf(
                        __('Testing date mapping for product ID %s on date %s with Eventbrite ID %s (Live Mode)', 'brcc-inventory-tracker'),
                        $product_id,
                        $formatted_date,
                        $eventbrite_id
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

        if (empty($eventbrite_id)) {
            $results[] = __('Please select an Eventbrite Ticket.', 'brcc-inventory-tracker');
        } else {
            $results[] = sprintf(
                __('Ticket ID "%s" is configured for product "%s" on %s.', 'brcc-inventory-tracker'),
                $eventbrite_id,
                $product_name,
                $formatted_date
            );
        }

        if (!empty($eventbrite_event_id) && !empty($eventbrite_id)) {
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
                        $valid = $eventbrite->validate_ticket_belongs_to_event($eventbrite_id, $eventbrite_event_id);
                        if (is_wp_error($valid)) {
                            $results[] = __('Error validating ticket/event relationship:', 'brcc-inventory-tracker') . ' ' . $valid->get_error_message();
                        } elseif ($valid === true) {
                            $results[] = __('Ticket ID belongs to the selected Event ID. ✓', 'brcc-inventory-tracker');
                        } else {
                            $results[] = __('WARNING: Ticket ID may not belong to the selected Event ID!', 'brcc-inventory-tracker');
                        }
                    }
                    
                } // End of if (!empty($eventbrite_id)) block
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
    public function ajax_get_products_for_date() {
        check_ajax_referer('brcc-admin-nonce', 'nonce');

        if (!isset($_POST['selected_date']) || BRCC_Helpers::parse_date_value($_POST['selected_date']) === null) {
            wp_send_json_error(['message' => __('Invalid or missing date format. Please use YYYY-MM-DD.', 'brcc-inventory-tracker')]);
        }

        $selected_date = sanitize_text_field($_POST['selected_date']);
        $products_on_date = [];

        // TODO: Implement logic to find products with events on $selected_date
        // This likely involves:
        // 1. Querying WooCommerce products (maybe filter by category or type if applicable).
        // 2. For each product, checking its Eventbrite mappings (both general and date-specific).
        // 3. Using BRCC_Eventbrite_Integration methods (like get_events_for_date or checking mappings)
        //    to see if *any* linked Eventbrite event occurs on $selected_date.
        // 4. If a product has an event on that date, add it to the $products_on_date array.

        try {
            BRCC_Helpers::log_debug("ajax_get_products_for_date: Received request for date: {$selected_date}");
            $product_mappings_instance = new BRCC_Product_Mappings();
            $all_mappings = $product_mappings_instance->get_all_mappings();

            if (empty($all_mappings)) {
                 BRCC_Helpers::log_debug("ajax_get_products_for_date: No mappings found in options.");
                 wp_send_json_success(['products' => []]);
                 return;
            }
            BRCC_Helpers::log_debug("ajax_get_products_for_date: Loaded all mappings.", $all_mappings);

            $products_found_ids = []; // Store IDs of products confirmed for the date

            // Iterate through mappings to find products with date-specific entries for the selected date
            foreach ($all_mappings as $product_id_key => $mapping_data) {
                // Check only date-specific mapping arrays
                if (strpos($product_id_key, '_dates') !== false && is_array($mapping_data)) {
                    $base_product_id = (int) str_replace('_dates', '', $product_id_key);
                    BRCC_Helpers::log_debug("ajax_get_products_for_date: Checking date mappings for Product ID: {$base_product_id}");

                    $found_match_for_product = false;
                    foreach ($mapping_data as $date_key => $date_mapping) {
                        // Check if the key is the exact date OR starts with the date followed by '_' (time-specific)
                        if ($date_key === $selected_date || strpos($date_key, $selected_date . '_') === 0) {
                            BRCC_Helpers::log_debug("ajax_get_products_for_date: Found matching date key '{$date_key}' for Product {$base_product_id}");
                            // The existence of *any* mapping entry for this date means the product is relevant.
                            if (!in_array($base_product_id, $products_found_ids)) {
                                $products_found_ids[] = $base_product_id;
                                BRCC_Helpers::log_debug("ajax_get_products_for_date: Added Product {$base_product_id} based on existence of date-specific mapping entry for {$selected_date}");
                            }
                            $found_match_for_product = true;
                            break; // Found a relevant entry for this date, move to next product
                        }
                    }
                    if (!$found_match_for_product) {
                         BRCC_Helpers::log_debug("ajax_get_products_for_date: No matching date key found within _dates array for Product {$base_product_id} and date {$selected_date}.");
                    }
                }
            } // End loop through mappings

            BRCC_Helpers::log_debug("ajax_get_products_for_date: Finished checking date-specific mappings. Found IDs:", $products_found_ids);

            // --- Fetch Product Names ---
            $products_on_date = [];
            if (!empty($products_found_ids)) {
                BRCC_Helpers::log_debug("ajax_get_products_for_date: Fetching names for product IDs:", $products_found_ids);
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
                BRCC_Helpers::log_debug("ajax_get_products_for_date: Found product data:", $products_on_date);
            } else {
                 BRCC_Helpers::log_debug("ajax_get_products_for_date: No product IDs matched the date via date-specific mappings. Returning empty list.");
            }

            wp_send_json_success(['products' => $products_on_date]);

        } catch (Exception $e) {
            BRCC_Helpers::log_error("ajax_get_products_for_date: Exception caught - " . $e->getMessage());
            wp_send_json_error(['message' => __('An unexpected error occurred.', 'brcc-inventory-tracker')]);
        }
}

    /**
     * AJAX handler to fetch attendees for a specific Product ID on a specific date.
     */
    public function ajax_fetch_product_attendees_for_date() {
        BRCC_Helpers::log_info("Attendee Fetch.....");
        check_ajax_referer('brcc-admin-nonce', 'nonce');
       
        if (!isset($_POST['product_id']) || !is_numeric($_POST['product_id'])) {
            wp_send_json_error(['message' => __('Invalid or missing Product ID.', 'brcc-inventory-tracker')]);
        }
        if (!isset($_POST['selected_date']) || BRCC_Helpers::parse_date_value($_POST['selected_date']) === null) {
            wp_send_json_error(['message' => __('Invalid or missing date format. Please use YYYY-MM-DD.', 'brcc-inventory-tracker')]);
        }

        $product_id = absint($_POST['product_id']);
        $selected_date = sanitize_text_field($_POST['selected_date']);
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = 50; // Or make configurable
        $source_filter = isset($_POST['source_filter']) ? sanitize_text_field($_POST['source_filter']) : 'all'; // 'all', 'eventbrite', 'woocommerce'

        $all_attendees = []; // Initialize array to hold merged attendees

        // --- 1. Fetch from Eventbrite ---
        BRCC_Helpers::log_info("Attendee Fetch: Starting for Product {$product_id} on {$selected_date}");
        $eventbrite_integration = new BRCC_Eventbrite_Integration();
        $product_mappings = new BRCC_Product_Mappings();
        $event_id_to_fetch = null;

        // Find Eventbrite Event ID from mapping
        $date_mapping = $product_mappings->get_product_mappings($product_id, $selected_date);
        // $debug_info['mapping_data'] = $date_mapping; // Removed debug store

        if ($date_mapping && !empty($date_mapping['eventbrite_event_id'])) {
            $event_id_to_fetch = $date_mapping['eventbrite_event_id'];
            // $debug_info['event_id_found'] = $event_id_to_fetch; // Removed debug store
            BRCC_Helpers::log_info("Attendee Fetch: Found mapped Eventbrite Event ID: {$event_id_to_fetch}");

            try {
                // Fetch ALL attendees from Eventbrite for this event (handle pagination later)
                // Note: get_event_attendees fetches all pages internally now
                $eventbrite_data = $eventbrite_integration->get_event_attendees($event_id_to_fetch);
                // $debug_info['eventbrite_api_result_type'] = is_wp_error($eventbrite_data) ? 'WP_Error' : (isset($eventbrite_data['attendees']) ? 'Success (with attendees key)' : 'Success (unknown format)'); // Removed debug store

                if (is_wp_error($eventbrite_data)) {
                    // $debug_info['eventbrite_api_error'] = $eventbrite_data->get_error_message(); // Removed debug store
                    BRCC_Helpers::log_error("Attendee Fetch: Error fetching Eventbrite attendees for Event ID {$event_id_to_fetch}.", $eventbrite_data);
                    // Don't stop here, just log the error and continue to fetch WC data
                } elseif (isset($eventbrite_data['attendees']) && is_array($eventbrite_data['attendees'])) {
                    $attendee_count = count($eventbrite_data['attendees']);
                    // $debug_info['eventbrite_attendee_count'] = $attendee_count; // Removed debug store
                    BRCC_Helpers::log_info("Attendee Fetch: Received " . $attendee_count . " attendees from Eventbrite API for Event ID {$event_id_to_fetch}.");
                    foreach ($eventbrite_data['attendees'] as $attendee) {
                        // Basic sanitization & Formatting
                        $name = isset($attendee['profile']['name']) ? sanitize_text_field($attendee['profile']['name']) : 'N/A';
                        $email = isset($attendee['profile']['email']) ? sanitize_email($attendee['profile']['email']) : 'N/A';
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
                // --- BRCC DEBUG: Log count after processing Eventbrite attendees ---
                BRCC_Helpers::log_info("Attendee Fetch: Processed Eventbrite attendees. Current count in \$all_attendees: " . count($all_attendees));
                // --- END BRCC DEBUG ---
            } catch (Exception $e) {
                // $debug_info['eventbrite_api_exception'] = $e->getMessage(); // Removed debug store
                BRCC_Helpers::log_error("Attendee Fetch: Exception fetching Eventbrite attendees for Event ID {$event_id_to_fetch}. Error: " . $e->getMessage());
                // Continue to fetch WC data
            }
        } else {
            // $debug_info['event_id_found'] = false; // Removed debug store
            BRCC_Helpers::log_info("Attendee Fetch: No Eventbrite Event ID mapped for Product {$product_id} on {$selected_date}. Skipping Eventbrite fetch.");
        }

        // --- 2. Fetch from WooCommerce ---
        BRCC_Helpers::log_info("Attendee Fetch: Fetching WooCommerce orders for Product {$product_id} on {$selected_date}");
        $wc_attendees = [];
        $args = array(
            'status' => array('wc-processing', 'wc-completed'), // Only include orders likely paid/valid
            'limit' => -1, // Get all matching orders (Warning: can be slow on sites with many orders)
            'return' => 'ids', // Get only IDs first for better performance
        );
        $query = new WC_Order_Query($args);
        $order_ids = $query->get_orders();

        BRCC_Helpers::log_info("Attendee Fetch: Found " . count($order_ids) . " potentially relevant WooCommerce order IDs.");

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                BRCC_Helpers::log_warning("Attendee Fetch: Could not get order object for ID {$order_id}");
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
                    // Check if the item's event date matches the selected date using the helper
                    $item_event_date = BRCC_Helpers::get_fooevents_date_from_item($item);

                    // --- Stricter Check: Ensure date was extracted AND matches ---
                    if ($item_event_date !== null && $item_event_date === $selected_date) {
                        // Match found! Extract details.
                        $wc_attendees[] = [
                            'name' => $order->get_formatted_billing_full_name() ?: 'N/A',
                            'email' => $order->get_billing_email() ?: 'N/A',
                            'purchase_date' => $order_purchase_date,
                            'order_ref' => $order->get_order_number(), // WC Order number
                            'status' => $order->get_status(), // WC Order status
                            'source' => 'WooCommerce'
                        ];
                        // Note: This adds one entry per matching item. If an order has 2 tickets for the same event, it adds 2 entries.
                    }
                }
            }
        }
        BRCC_Helpers::log_info("Attendee Fetch: Found " . count($wc_attendees) . " matching attendees/sales in WooCommerce orders for date {$selected_date}.");

        // --- 3. Merge Results ---
        $all_attendees = array_merge($all_attendees, $wc_attendees);
        BRCC_Helpers::log_info("Attendee Fetch: Total merged attendees before filtering: " . count($all_attendees));

        // --- 4. Apply Source Filter ---
        if ($source_filter !== 'all' && !empty($all_attendees)) {
            $filtered_attendees = array_filter($all_attendees, function($attendee) use ($source_filter) {
                // Ensure 'source' key exists and handle case-insensitivity
                return isset($attendee['source']) && strtolower($attendee['source']) === strtolower($source_filter);
            });
            $all_attendees = array_values($filtered_attendees); // Re-index array
            BRCC_Helpers::log_info("Attendee Fetch: Attendees after filtering by '{$source_filter}': " . count($all_attendees));
        }


        // --- 5. Handle Pagination & Send Response ---
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
            // 'debug_info' => $debug_info // Removed debug info
        ];

        wp_send_json_success($formatted_data);

    
        } // End ajax_fetch_product_attendees_for_date
    
        /**
         * AJAX: Reset today's sales data
         */
        public function ajax_reset_todays_sales() {
            // Check nonce and capability
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'brcc-admin-nonce')) {
                wp_send_json_error(array('message' => __('Security check failed.', 'brcc-inventory-tracker')));
            }
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Insufficient permissions.', 'brcc-inventory-tracker')));
            }
    
            $sales_tracker = new BRCC_Sales_Tracker();
            $reset_result = $sales_tracker->reset_todays_sales_data();
    
            if ($reset_result) {
                BRCC_Helpers::log_operation('Admin', 'Reset Sales Data', 'Successfully reset sales data for today: ' . date('Y-m-d'));
                wp_send_json_success(array('message' => __('Sales data for today has been reset.', 'brcc-inventory-tracker')));
            } else {
                BRCC_Helpers::log_warning('Admin', 'Reset Sales Data', 'Attempted to reset sales data for today, but no data was found for today: ' . date('Y-m-d'));
                wp_send_json_error(array('message' => __('No sales data found for today to reset.', 'brcc-inventory-tracker')));
            }
        }
    
        /**
         * Display the Tools page content
         */
        public function display_tools_page() {
            ?>
            <div class="wrap brcc-admin-wrap">
                <h1><?php _e('BRCC Inventory Tools', 'brcc-inventory-tracker'); ?></h1>
    
                <?php
                // Handle Test Eventbrite Order Processing form submission
                if (isset($_POST['brcc_test_eventbrite_order_nonce'], $_POST['brcc_test_eventbrite_order_id'])) {
                    // Verify nonce
                    if (!wp_verify_nonce($_POST['brcc_test_eventbrite_order_nonce'], 'brcc_test_eventbrite_order_action')) {
                        wp_die(__('Security check failed!', 'brcc-inventory-tracker'));
                    }
    
                    // Check user capability
                    if (!current_user_can('manage_options')) {
                        wp_die(__('You do not have permission to perform this action.', 'brcc-inventory-tracker'));
                    }
    
                    $order_id = sanitize_text_field($_POST['brcc_test_eventbrite_order_id']);
    
                    if (empty($order_id) || !ctype_digit($order_id)) {
                         add_settings_error(
                            'brcc-tools-notices',
                            'invalid-order-id',
                            __('Please enter a valid numeric Eventbrite Order ID.', 'brcc-inventory-tracker'),
                            'error'
                        );
                    } else {
                        BRCC_Helpers::log_info("Manual Test Triggered: Processing Eventbrite Order ID: $order_id");
                        // Ensure the integration class file is included
                        if (!class_exists('BRCC_Eventbrite_Integration')) {
                             // Define plugin dir constant if not defined (adjust path as needed)
                             if (!defined('BRCC_INVENTORY_TRACKER_PLUGIN_DIR')) {
                                 define('BRCC_INVENTORY_TRACKER_PLUGIN_DIR', plugin_dir_path(dirname(__FILE__, 2)) . '/'); // Adjust depth if needed
                             }
                             include_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/integrations/class-brcc-eventbrite-integration.php';
                        }
                        
                        if (class_exists('BRCC_Eventbrite_Integration')) {
                            $eventbrite_integration = new BRCC_Eventbrite_Integration();
                            // Check if the method exists before calling
                            if (method_exists($eventbrite_integration, 'test_process_order')) {
                                $result = $eventbrite_integration->test_process_order($order_id);
                                if ($result === true) {
                                     add_settings_error(
                                        'brcc-tools-notices',
                                        'test-success',
                                        sprintf(__('Attempted to process Eventbrite Order ID %s. Check Operation Logs and Debug Logs for details.', 'brcc-inventory-tracker'), esc_html($order_id)),
                                        'success' // Use 'success' type for visual feedback
                                    );
                                } else {
                                     add_settings_error(
                                        'brcc-tools-notices',
                                        'test-failed',
                                         sprintf(__('Failed to initiate processing for Eventbrite Order ID %s. Check Debug Logs for errors (e.g., fetch failed).', 'brcc-inventory-tracker'), esc_html($order_id)),
                                        'error'
                                    );
                                }
                            } else {
                                 add_settings_error(
                                    'brcc-tools-notices',
                                    'method-not-found',
                                    __('Error: The test_process_order method does not exist in the Eventbrite integration class.', 'brcc-inventory-tracker'),
                                    'error'
                                );
                            }
                        } else {
                             add_settings_error(
                                'brcc-tools-notices',
                                'class-not-found',
                                __('Error: The BRCC_Eventbrite_Integration class could not be found.', 'brcc-inventory-tracker'),
                                'error'
                            );
                        }
                    }
                     settings_errors('brcc-tools-notices');
                }
                ?>
    
                <div class="brcc-card">
                    <h2><?php _e('Test Eventbrite Order Processing', 'brcc-inventory-tracker'); ?></h2>
                    <p><?php _e('Manually trigger the processing logic for a specific Eventbrite order. This uses the same functions as the webhook handler.', 'brcc-inventory-tracker'); ?></p>
                    <form method="post" action="">
                        <?php wp_nonce_field('brcc_test_eventbrite_order_action', 'brcc_test_eventbrite_order_nonce'); ?>
                        <table class="form-table">
                            <tr valign="top">
                                <th scope="row">
                                    <label for="brcc_test_eventbrite_order_id"><?php _e('Eventbrite Order ID', 'brcc-inventory-tracker'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="brcc_test_eventbrite_order_id" name="brcc_test_eventbrite_order_id" value="" class="regular-text" placeholder="e.g., 1234567890" />
                                    <p class="description"><?php _e('Enter the numeric Order ID from Eventbrite.', 'brcc-inventory-tracker'); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(__('Process Test Order', 'brcc-inventory-tracker')); ?>
                    </form>
                </div>
    
                <hr>
    
                <div class="brcc-card">
                    <h2><?php _e('Manual Inventory Sync', 'brcc-inventory-tracker'); ?></h2>
                    <p><?php _e('Manually trigger an inventory sync for **today\'s** events and orders between Eventbrite, Square, and WooCommerce. This checks for new sales/attendees for the current day based on the latest data from the platforms.', 'brcc-inventory-tracker'); ?></p>
                    <?php
                    // Handle Manual Sync form submission
                    if (isset($_POST['brcc_manual_sync_nonce'])) {
                        // Verify nonce
                        if (!wp_verify_nonce($_POST['brcc_manual_sync_nonce'], 'brcc_manual_sync_action')) {
                            wp_die(__('Security check failed!', 'brcc-inventory-tracker'));
                        }
    
                        // Check user capability
                        if (!current_user_can('manage_options')) {
                            wp_die(__('You do not have permission to perform this action.', 'brcc-inventory-tracker'));
                        }
    
                        BRCC_Helpers::log_info("Manual Sync Triggered by Admin.");
                        
                        // Ensure the integration class file is included
                        if (!class_exists('BRCC_Eventbrite_Integration')) {
                             if (!defined('BRCC_INVENTORY_TRACKER_PLUGIN_DIR')) {
                                 define('BRCC_INVENTORY_TRACKER_PLUGIN_DIR', plugin_dir_path(dirname(__FILE__, 2)) . '/');
                             }
                             include_once BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/integrations/class-brcc-eventbrite-integration.php';
                        }
    
                        if (class_exists('BRCC_Eventbrite_Integration')) {
                            $eventbrite_integration = new BRCC_Eventbrite_Integration();
                            if (method_exists($eventbrite_integration, 'sync_eventbrite_tickets')) {
                                // Running sync directly might time out on large sites.
                                // Consider scheduling an immediate background task instead for robustness.
                                // For now, run directly as requested.
                                $eventbrite_integration->sync_eventbrite_tickets();
                                add_settings_error(
                                    'brcc-tools-notices',
                                    'sync-triggered',
                                    __('Manual inventory sync initiated. Check Operation Logs and Debug Logs for details. The process runs in the background.', 'brcc-inventory-tracker'),
                                    'success'
                                );
                            } else {
                                 add_settings_error(
                                    'brcc-tools-notices',
                                    'sync-method-not-found',
                                    __('Error: The sync_eventbrite_tickets method does not exist in the Eventbrite integration class.', 'brcc-inventory-tracker'),
                                    'error'
                                );
                            }
                        } else {
                             add_settings_error(
                                'brcc-tools-notices',
                                'sync-class-not-found',
                                __('Error: The BRCC_Eventbrite_Integration class could not be found for sync.', 'brcc-inventory-tracker'),
                                'error'
                            );
                        }
                        settings_errors('brcc-tools-notices'); // Display notices here as well
                    }
                    ?>
                    <form method="post" action="">
                        <?php wp_nonce_field('brcc_manual_sync_action', 'brcc_manual_sync_nonce'); ?>
                        <?php submit_button(__('Run Manual Sync Now', 'brcc-inventory-tracker'), 'secondary'); ?>
                    </form>
                </div>
    
    
            </div>
            <?php
        }
    
        /**
         * Add body class for daily sales page
         */
        public function add_body_class($classes) {
            global $pagenow;
            if ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'brcc-daily-sales') {
                $classes .= ' brcc-daily-sales-page';
            }
            return $classes;
        }
    
    } // End class BRCC_Admin
