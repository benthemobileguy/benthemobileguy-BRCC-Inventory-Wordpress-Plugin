<?php
/**
 * Settings page template
 * 
 * @var string $active_tab Active settings tab
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php _e('BRCC Inventory Settings', 'brcc-inventory-tracker'); ?></h1>
    
    <?php if (BRCC_Helpers::is_test_mode()): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('Test Mode is ENABLED', 'brcc-inventory-tracker'); ?></strong>
                <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
                <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
            </p>
        </div>
    <?php endif; ?>
    
    <h2 class="nav-tab-wrapper">
        <a href="<?php echo admin_url('admin.php?page=brcc-settings&tab=general'); ?>" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>"><?php _e('General Settings', 'brcc-inventory-tracker'); ?></a>
        <a href="<?php echo admin_url('admin.php?page=brcc-settings&tab=product-mappings'); ?>" class="nav-tab <?php echo $active_tab === 'product-mappings' ? 'nav-tab-active' : ''; ?>"><?php _e('Product Mappings', 'brcc-inventory-tracker'); ?></a>
        <a href="<?php echo admin_url('admin.php?page=brcc-settings&tab=date-mappings'); ?>" class="nav-tab <?php echo $active_tab === 'date-mappings' ? 'nav-tab-active' : ''; ?>"><?php _e('Date Mappings', 'brcc-inventory-tracker'); ?></a>
    </h2>
    
    <div class="brcc-settings-content">
        <?php if ($active_tab === 'general'): ?>
            <form method="post" action="options.php">
                <?php
                settings_fields('brcc_api_settings');
                do_settings_sections('brcc_api_settings');
                submit_button();
                ?>
            </form>
        <?php elseif ($active_tab === 'product-mappings'): ?>
            <?php 
            $admin_pages = new BRCC_Admin_Pages();
            $admin_pages->display_product_mapping_interface(); 
            ?>
        <?php elseif ($active_tab === 'date-mappings'): ?>
            <div class="brcc-date-mappings-container">
                <h2><?php _e('Product Date Mappings', 'brcc-inventory-tracker'); ?></h2>
                <p class="description">
                    <?php _e('Map products to specific dates and Eventbrite events. This allows you to track inventory for date-specific events.', 'brcc-inventory-tracker'); ?>
                </p>
                
                <div class="brcc-date-mappings-controls">
                    <div class="brcc-product-selector">
                        <label for="brcc-product-select"><?php _e('Select Product:', 'brcc-inventory-tracker'); ?></label>
                        <select id="brcc-product-select" class="brcc-select2">
                            <option value=""><?php _e('Select a product...', 'brcc-inventory-tracker'); ?></option>
                            <?php
                            $args = array(
                                'post_type' => 'product',
                                'posts_per_page' => -1,
                                'post_status' => 'publish',
                                'orderby' => 'title',
                                'order' => 'ASC'
                            );
                            $products = get_posts($args);
                            
                            foreach ($products as $product) {
                                echo '<option value="' . esc_attr($product->ID) . '">' . esc_html($product->post_title) . '</option>';
                            }
                            ?>
                        </select>
                        <button type="button" id="brcc-load-product-dates" class="button button-secondary"><?php _e('Load Dates', 'brcc-inventory-tracker'); ?></button>
                    </div>
                </div>
                
                <div id="brcc-date-mappings-list" class="brcc-date-mappings-list">
                    <p class="brcc-empty-state"><?php _e('Select a product to view and manage its date mappings.', 'brcc-inventory-tracker'); ?></p>
                </div>
                
                <div id="brcc-date-mappings-form" class="brcc-date-mappings-form" style="display: none;">
                    <h3><?php _e('Add New Date Mapping', 'brcc-inventory-tracker'); ?></h3>
                    
                    <div class="brcc-form-row">
                        <label for="brcc-date-input"><?php _e('Event Date:', 'brcc-inventory-tracker'); ?></label>
                        <input type="text" id="brcc-date-input" class="brcc-date-picker" placeholder="YYYY-MM-DD" />
                    </div>
                    
                    <div class="brcc-form-row">
                        <label for="brcc-time-input"><?php _e('Event Time (optional):', 'brcc-inventory-tracker'); ?></label>
                        <input type="text" id="brcc-time-input" class="brcc-time-picker" placeholder="HH:MM" />
                    </div>
                    
                    <div class="brcc-form-row">
                        <label for="brcc-eventbrite-event-select"><?php _e('Eventbrite Event:', 'brcc-inventory-tracker'); ?></label>
                        <select id="brcc-eventbrite-event-select" class="brcc-select2">
                            <option value=""><?php _e('Select an event...', 'brcc-inventory-tracker'); ?></option>
                            <!-- Events will be loaded via AJAX -->
                        </select>
                        <button type="button" id="brcc-refresh-events" class="button button-secondary"><?php _e('Refresh Events', 'brcc-inventory-tracker'); ?></button>
                    </div>
                    
                    <div class="brcc-form-row">
                        <label for="brcc-eventbrite-ticket-select"><?php _e('Eventbrite Ticket:', 'brcc-inventory-tracker'); ?></label>
                        <select id="brcc-eventbrite-ticket-select" class="brcc-select2">
                            <option value=""><?php _e('Select event first...', 'brcc-inventory-tracker'); ?></option>
                            <!-- Tickets will be loaded via AJAX -->
                        </select>
                    </div>
                    
                    <div class="brcc-form-row">
                        <label for="brcc-manual-eventbrite-id"><?php _e('Manual Eventbrite Ticket ID:', 'brcc-inventory-tracker'); ?></label>
                        <input type="text" id="brcc-manual-eventbrite-id" placeholder="<?php _e('Enter ID manually...', 'brcc-inventory-tracker'); ?>" />
                        <button type="button" id="brcc-suggest-ticket-id" class="button button-secondary" title="<?php _e('Suggest Eventbrite Ticket ID based on date/time', 'brcc-inventory-tracker'); ?>"><?php _e('Suggest', 'brcc-inventory-tracker'); ?></button>
                    </div>
                    
                    <div class="brcc-form-row">
                        <label for="brcc-square-id"><?php _e('Square Item ID:', 'brcc-inventory-tracker'); ?></label>
                        <input type="text" id="brcc-square-id" placeholder="<?php _e('Enter Square ID...', 'brcc-inventory-tracker'); ?>" />
                    </div>
                    
                    <div class="brcc-form-actions">
                        <button type="button" id="brcc-add-date-mapping" class="button button-primary"><?php _e('Add Date Mapping', 'brcc-inventory-tracker'); ?></button>
                        <button type="button" id="brcc-test-date-mapping" class="button button-secondary"><?php _e('Test Mapping', 'brcc-inventory-tracker'); ?></button>
                        <button type="button" id="brcc-cancel-date-mapping" class="button button-secondary"><?php _e('Cancel', 'brcc-inventory-tracker'); ?></button>
                    </div>
                    
                    <div id="brcc-date-mapping-test-results" class="brcc-test-results"></div>
                </div>
                
                <div class="brcc-date-mappings-actions">
                    <button type="button" id="brcc-add-new-date-mapping" class="button button-primary"><?php _e('Add New Date Mapping', 'brcc-inventory-tracker'); ?></button>
                    <button type="button" id="brcc-save-date-mappings" class="button button-primary"><?php _e('Save All Mappings', 'brcc-inventory-tracker'); ?></button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>