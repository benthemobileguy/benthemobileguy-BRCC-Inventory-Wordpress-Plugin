<?php
/**
 * Tools page template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php _e('BRCC Inventory Tools', 'brcc-inventory-tracker'); ?></h1>
    
    <?php if (BRCC_Helpers::is_test_mode()): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('Test Mode is ENABLED', 'brcc-inventory-tracker'); ?></strong>
                <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
                <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
            </p>
        </div>
    <?php endif; ?>
    
    <div class="brcc-tools-container">
        <div class="brcc-tool-card">
            <h2><?php _e('Force Sync Inventory', 'brcc-inventory-tracker'); ?></h2>
            <p class="description">
                <?php _e('Force sync inventory for a specific product and date. For Eventbrite, this pushes the current WooCommerce stock level to the corresponding Eventbrite ticket for the selected date. For Square, this triggers the standard sync process (ignoring the selected product/date).', 'brcc-inventory-tracker'); ?>
            </p>
            
            <div class="brcc-tool-form">
                <div class="brcc-form-row">
                    <label for="brcc-force-sync-product"><?php _e('Product:', 'brcc-inventory-tracker'); ?></label>
                    <select id="brcc-force-sync-product" class="brcc-select2">
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
                </div>
                
                <div class="brcc-form-row">
                    <label for="brcc-force-sync-date"><?php _e('Event Date:', 'brcc-inventory-tracker'); ?></label>
                    <input type="text" id="brcc-force-sync-date" class="brcc-date-picker" placeholder="YYYY-MM-DD" />
                </div>
                
                <div class="brcc-form-actions">
                    <button type="button" id="brcc-force-sync-button" class="button button-primary"><?php _e('Force Sync', 'brcc-inventory-tracker'); ?></button>
                </div>
                
                <div id="brcc-force-sync-result" class="brcc-tool-result"></div>
            </div>
        </div>
        
        <div class="brcc-tool-card">
            <h2><?php _e('Send Test Email', 'brcc-inventory-tracker'); ?></h2>
            <p class="description">
                <?php _e('Send a test email with the daily attendee list for today. This is useful for testing the email functionality.', 'brcc-inventory-tracker'); ?>
            </p>
            
            <div class="brcc-tool-form">
                <div class="brcc-form-row">
                    <label for="brcc-test-email"><?php _e('Email Address:', 'brcc-inventory-tracker'); ?></label>
                    <input type="email" id="brcc-test-email" placeholder="<?php _e('Enter email address...', 'brcc-inventory-tracker'); ?>" value="<?php echo esc_attr(get_option('admin_email')); ?>" />
                </div>
                
                <div class="brcc-form-actions">
                    <button type="button" id="brcc-send-test-email" class="button button-primary"><?php _e('Send Test Email', 'brcc-inventory-tracker'); ?></button>
                </div>
                
                <div id="brcc-test-email-result" class="brcc-tool-result"></div>
            </div>
        </div>
        
        <div class="brcc-tool-card">
            <h2><?php _e('Clear Cache', 'brcc-inventory-tracker'); ?></h2>
            <p class="description">
                <?php _e('Clear various caches used by the plugin. This can help resolve issues with data not updating.', 'brcc-inventory-tracker'); ?>
            </p>
            
            <div class="brcc-tool-form">
                <div class="brcc-form-row">
                    <label><?php _e('Select Cache to Clear:', 'brcc-inventory-tracker'); ?></label>
                    <div class="brcc-checkbox-group">
                        <label>
                            <input type="checkbox" name="brcc-clear-cache[]" value="eventbrite" checked />
                            <?php _e('Eventbrite Events Cache', 'brcc-inventory-tracker'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="brcc-clear-cache[]" value="product_mappings" />
                            <?php _e('Product Mappings Cache', 'brcc-inventory-tracker'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="brcc-clear-cache[]" value="sales_data" />
                            <?php _e('Sales Data Cache', 'brcc-inventory-tracker'); ?>
                        </label>
                    </div>
                </div>
                
                <div class="brcc-form-actions">
                    <button type="button" id="brcc-clear-cache-button" class="button button-primary"><?php _e('Clear Cache', 'brcc-inventory-tracker'); ?></button>
                </div>
                
                <div id="brcc-clear-cache-result" class="brcc-tool-result"></div>
            </div>
        </div>
        
        <div class="brcc-tool-card">
            <h2><?php _e('System Information', 'brcc-inventory-tracker'); ?></h2>
            <p class="description">
                <?php _e('View system information that can be helpful for troubleshooting.', 'brcc-inventory-tracker'); ?>
            </p>
            
            <div class="brcc-tool-form">
                <div class="brcc-system-info">
                    <table class="widefat">
                        <tbody>
                            <tr>
                                <th><?php _e('WordPress Version:', 'brcc-inventory-tracker'); ?></th>
                                <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('PHP Version:', 'brcc-inventory-tracker'); ?></th>
                                <td><?php echo esc_html(phpversion()); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('WooCommerce Version:', 'brcc-inventory-tracker'); ?></th>
                                <td><?php echo defined('WC_VERSION') ? esc_html(WC_VERSION) : __('Not installed', 'brcc-inventory-tracker'); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Plugin Version:', 'brcc-inventory-tracker'); ?></th>
                                <td><?php echo defined('BRCC_INVENTORY_TRACKER_VERSION') ? esc_html(BRCC_INVENTORY_TRACKER_VERSION) : __('Unknown', 'brcc-inventory-tracker'); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Test Mode:', 'brcc-inventory-tracker'); ?></th>
                                <td><?php echo BRCC_Helpers::is_test_mode() ? __('Enabled', 'brcc-inventory-tracker') : __('Disabled', 'brcc-inventory-tracker'); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Live Logging:', 'brcc-inventory-tracker'); ?></th>
                                <td><?php echo BRCC_Helpers::should_log() ? __('Enabled', 'brcc-inventory-tracker') : __('Disabled', 'brcc-inventory-tracker'); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Last Sync:', 'brcc-inventory-tracker'); ?></th>
                                <td>
                                    <?php 
                                    $last_sync_time = get_option('brcc_last_sync_time', 0);
                                    echo $last_sync_time ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync_time) : __('Never', 'brcc-inventory-tracker');
                                    ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Initialize date picker
    $('.brcc-date-picker').datepicker({
        dateFormat: 'yy-mm-dd',
        maxDate: 0 // Prevent future dates
    });
    
    // Initialize Select2
    $('.brcc-select2').select2();
    
    // Force Sync button
    $('#brcc-force-sync-button').on('click', function() {
        var productId = $('#brcc-force-sync-product').val();
        var eventDate = $('#brcc-force-sync-date').val();
        
        if (!productId) {
            alert(brcc_admin.select_product_alert);
            return;
        }
        
        if (!eventDate) {
            alert(brcc_admin.select_date_alert);
            return;
        }
        
        if (!confirm(brcc_admin.force_sync_confirm)) {
            return;
        }
        
        var resultContainer = $('#brcc-force-sync-result');
        resultContainer.html('<div class="notice notice-info"><p>' + brcc_admin.loading + '</p></div>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_sync_inventory_now',
                product_id: productId,
                event_date: eventDate,
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultContainer.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    resultContainer.html('<div class="notice notice-error"><p>' + (response.data.message || brcc_admin.ajax_error) + '</p></div>');
                }
            },
            error: function() {
                resultContainer.html('<div class="notice notice-error"><p>' + brcc_admin.ajax_error + '</p></div>');
            }
        });
    });
    
    // Send Test Email button
    $('#brcc-send-test-email').on('click', function() {
        var email = $('#brcc-test-email').val();
        
        if (!email) {
            alert('<?php _e('Please enter an email address.', 'brcc-inventory-tracker'); ?>');
            return;
        }
        
        var resultContainer = $('#brcc-test-email-result');
        resultContainer.html('<div class="notice notice-info"><p>' + brcc_admin.loading + '</p></div>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_send_test_email',
                email: email,
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultContainer.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    resultContainer.html('<div class="notice notice-error"><p>' + (response.data.message || brcc_admin.ajax_error) + '</p></div>');
                }
            },
            error: function() {
                resultContainer.html('<div class="notice notice-error"><p>' + brcc_admin.ajax_error + '</p></div>');
            }
        });
    });
    
    // Clear Cache button
    $('#brcc-clear-cache-button').on('click', function() {
        var caches = [];
        $('input[name="brcc-clear-cache[]"]:checked').each(function() {
            caches.push($(this).val());
        });
        
        if (caches.length === 0) {
            alert('<?php _e('Please select at least one cache to clear.', 'brcc-inventory-tracker'); ?>');
            return;
        }
        
        var resultContainer = $('#brcc-clear-cache-result');
        resultContainer.html('<div class="notice notice-info"><p>' + brcc_admin.loading + '</p></div>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_clear_cache',
                caches: caches,
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    resultContainer.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    resultContainer.html('<div class="notice notice-error"><p>' + (response.data.message || brcc_admin.ajax_error) + '</p></div>');
                }
            },
            error: function() {
                resultContainer.html('<div class="notice notice-error"><p>' + brcc_admin.ajax_error + '</p></div>');
            }
        });
    });
});
</script>

<style>
.brcc-tools-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.brcc-tool-card {
    background-color: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
.brcc-tool-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}
.brcc-form-row {
    margin-bottom: 15px;
}
.brcc-form-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}
.brcc-checkbox-group {
    margin-top: 5px;
}
.brcc-checkbox-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: normal;
}
.brcc-form-actions {
    margin-top: 20px;
}
.brcc-tool-result {
    margin-top: 15px;
}
.brcc-system-info table {
    width: 100%;
}
.brcc-system-info th {
    width: 40%;
    text-align: left;
}
</style>