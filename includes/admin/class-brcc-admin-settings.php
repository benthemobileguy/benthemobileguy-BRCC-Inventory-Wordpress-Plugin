<?php
/**
 * BRCC Admin Settings Class
 * 
 * Handles registration and rendering of settings fields for the BRCC Inventory Tracker plugin.
 * This class is responsible for registering all plugin settings with the WordPress Settings API,
 * and providing the callback functions for rendering the settings fields in the admin interface.
 * It follows the WordPress Settings API pattern for organizing and displaying settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BRCC_Admin_Settings {

    /**
     * Initialize the class
     * 
     * Sets up the necessary WordPress hooks for registering settings.
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register plugin settings
     * 
     * Registers all plugin settings with the WordPress Settings API.
     * Organizes settings into sections and fields for better organization.
     * Each field is associated with a callback function that renders the field.
     */
    public function register_settings() {
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
            array($this, 'eventbrite_org_id_callback'),
            'brcc_api_settings',
            'brcc_api_settings_section'
        );
        
        // Eventbrite Webhook Secret Key
        add_settings_field(
            'eventbrite_webhook_secret',
            __('Eventbrite Webhook Secret', 'brcc-inventory-tracker'),
            array($this, 'eventbrite_webhook_secret_callback'),
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
     * Settings section description
     * 
     * Renders the description for the API settings section.
     */
    public function api_settings_section_callback() {
        echo '<p>' . __('Configure API settings for Eventbrite inventory integration.', 'brcc-inventory-tracker') . '</p>';
    }

    /**
     * API Key field callback
     * 
     * Renders the API Key field in the settings form.
     * The API key is read-only and can only be regenerated via the "Regenerate Key" button.
     */
    public function api_key_field_callback() {
        $options = get_option('brcc_api_settings', array());
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';
    ?>
        <input type="text" id="api_key" name="brcc_api_settings[api_key]" value="<?php echo esc_attr($api_key); ?>" class="regular-text" readonly />
        <p class="description"><?php _e('This key is used to authenticate API requests.', 'brcc-inventory-tracker'); ?></p>
        <button type="button" class="button button-secondary" id="regenerate-api-key"><?php _e('Regenerate Key', 'brcc-inventory-tracker'); ?></button>
    <?php
    }

    /**
     * Eventbrite Token callback
     * 
     * Renders the Eventbrite API Token field in the settings form.
     * Includes buttons for testing the connection and clearing the event cache.
     */
    public function eventbrite_token_callback() {
        $options = get_option('brcc_api_settings', array());
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
     * 
     * Renders the Eventbrite Organization ID field in the settings form.
     * This ID is required for fetching events from Eventbrite.
     */
    public function eventbrite_org_id_callback() {
        $options = get_option('brcc_api_settings', array());
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
     *
     * Renders the Eventbrite Webhook Secret field in the settings form.
     * This key is used for validating incoming webhooks from Eventbrite.
     */
    public function eventbrite_webhook_secret_callback() {
        $options = get_option('brcc_api_settings', array());
        $value = isset($options['eventbrite_webhook_secret']) ? $options['eventbrite_webhook_secret'] : '';
    ?>
        <input type="password" id="eventbrite_webhook_secret" name="brcc_api_settings[eventbrite_webhook_secret]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description">
            <?php _e('Enter your Eventbrite Webhook Secret. This is used to verify that incoming webhook requests are genuinely from Eventbrite.', 'brcc-inventory-tracker'); ?>
            <br/><em><?php _e('You create this secret yourself and configure it in your Eventbrite webhook settings.', 'brcc-inventory-tracker'); ?></em>
        </p>
    <?php
    }

    /**
     * Square Access Token callback
     * 
     * Renders the Square Access Token field in the settings form.
     * This token is used for authenticating with the Square API.
     */
    public function square_access_token_callback() {
        $options = get_option('brcc_api_settings', array());
        $value = isset($options['square_access_token']) ? $options['square_access_token'] : '';
    ?>
        <input type="password" id="square_access_token" name="brcc_api_settings[square_access_token]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Access Token.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Location ID callback
     * 
     * Renders the Square Location ID field in the settings form.
     * This ID is required for interacting with a specific Square location.
     */
    public function square_location_id_callback() {
        $options = get_option('brcc_api_settings', array());
        $value = isset($options['square_location_id']) ? $options['square_location_id'] : '';
    ?>
        <input type="text" id="square_location_id" name="brcc_api_settings[square_location_id]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Location ID.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Webhook Signature Key callback
     * 
     * Renders the Square Webhook Signature Key field in the settings form.
     * This key is used for validating incoming webhooks from Square.
     */
    public function square_webhook_signature_key_callback() {
        $options = get_option('brcc_api_settings', array());
        $value = isset($options['square_webhook_signature_key']) ? $options['square_webhook_signature_key'] : '';
    ?>
        <input type="password" id="square_webhook_signature_key" name="brcc_api_settings[square_webhook_signature_key]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description"><?php _e('Enter your Square Webhook Signature Key for validating incoming webhooks.', 'brcc-inventory-tracker'); ?></p>
    <?php
    }

    /**
     * Square Sandbox Mode callback
     * 
     * Renders the Square Sandbox Mode checkbox in the settings form.
     * When enabled, the plugin will use the Square Sandbox environment for testing.
     */
    public function square_sandbox_callback() {
        $options = get_option('brcc_api_settings', array());
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
     * Test Mode callback
     * 
     * Renders the Test Mode checkbox in the settings form.
     * When enabled, the plugin will log operations but not make actual inventory changes.
     */
    public function test_mode_callback() {
        $options = get_option('brcc_api_settings', array());
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
     * 
     * Renders the Live Logging checkbox in the settings form.
     * When enabled, the plugin will log operations while making actual inventory changes.
     */
    public function live_logging_callback() {
        $options = get_option('brcc_api_settings', array());
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
     * 
     * Renders the Sync Interval field in the settings form.
     * This determines how often the plugin will sync inventory with external services.
     */
    public function sync_interval_callback() {
        $options = get_option('brcc_api_settings', array());
        $value = isset($options['sync_interval']) ? absint($options['sync_interval']) : 15;
    ?>
        <input type="number" id="sync_interval" name="brcc_api_settings[sync_interval]" value="<?php echo esc_attr($value); ?>" class="small-text" min="5" max="1440" />
        <p class="description"><?php _e('How often to sync inventory with Eventbrite (minimum 5 minutes).', 'brcc-inventory-tracker'); ?></p>
    <?php
    }
}