<?php
/**
 * Inventory Status Widget template
 * 
 * @var string $last_sync_formatted Last sync time formatted
 * @var string $next_sync_formatted Next sync time formatted
 * @var int $sync_interval Sync interval in minutes
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="brcc-inventory-status-widget">
    <h3><?php _e('Inventory Sync Status', 'brcc-inventory-tracker'); ?></h3>
    
    <div class="brcc-status-info">
        <div class="brcc-status-row">
            <span class="brcc-status-label"><?php _e('Last Sync:', 'brcc-inventory-tracker'); ?></span>
            <span class="brcc-status-value"><?php echo esc_html($last_sync_formatted); ?></span>
        </div>
        
        <div class="brcc-status-row">
            <span class="brcc-status-label"><?php _e('Next Scheduled Sync:', 'brcc-inventory-tracker'); ?></span>
            <span class="brcc-status-value"><?php echo esc_html($next_sync_formatted); ?></span>
        </div>
        
        <div class="brcc-status-row">
            <span class="brcc-status-label"><?php _e('Sync Interval:', 'brcc-inventory-tracker'); ?></span>
            <span class="brcc-status-value"><?php echo esc_html($sync_interval); ?> <?php _e('minutes', 'brcc-inventory-tracker'); ?></span>
        </div>
    </div>
    
    <div class="brcc-status-actions">
        <button type="button" id="brcc-sync-now" class="button button-primary"><?php _e('Sync Now', 'brcc-inventory-tracker'); ?></button>
    </div>
    
    <div id="brcc-sync-status" class="brcc-sync-status"></div>
</div>

<script>
jQuery(document).ready(function($) {
    // Sync Now button
    $('#brcc-sync-now').on('click', function() {
        var button = $(this);
        var statusContainer = $('#brcc-sync-status');
        
        button.prop('disabled', true).text(brcc_admin.syncing);
        statusContainer.html('<div class="notice notice-info inline"><p>' + brcc_admin.syncing + '</p></div>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_sync_inventory_now',
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text(brcc_admin.sync_now);
                
                if (response.success) {
                    statusContainer.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    
                    // Update last sync time
                    $('.brcc-status-row:first-child .brcc-status-value').text(response.data.timestamp);
                    
                    // Calculate and update next sync time
                    var nextSyncDate = new Date();
                    nextSyncDate.setMinutes(nextSyncDate.getMinutes() + <?php echo esc_js($sync_interval); ?>);
                    
                    // Format the date based on WordPress settings (simplified)
                    var formattedNextSync = nextSyncDate.toLocaleString();
                    $('.brcc-status-row:nth-child(2) .brcc-status-value').text(formattedNextSync);
                    
                    // Show test mode notice if applicable
                    if (response.data.test_mode) {
                        statusContainer.append('<div class="notice notice-warning inline"><p><?php _e('Test Mode is enabled. No actual inventory changes were made.', 'brcc-inventory-tracker'); ?></p></div>');
                    }
                } else {
                    statusContainer.html('<div class="notice notice-error inline"><p>' + (response.data.message || brcc_admin.ajax_error) + '</p></div>');
                }
            },
            error: function() {
                button.prop('disabled', false).text(brcc_admin.sync_now);
                statusContainer.html('<div class="notice notice-error inline"><p>' + brcc_admin.ajax_error + '</p></div>');
            }
        });
    });
});
</script>

<style>
.brcc-inventory-status-widget {
    background-color: #f9f9f9;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
    padding: 15px;
}
.brcc-status-info {
    margin-bottom: 15px;
}
.brcc-status-row {
    margin-bottom: 8px;
}
.brcc-status-label {
    font-weight: bold;
    margin-right: 5px;
}
.brcc-status-actions {
    margin-bottom: 10px;
}
.brcc-sync-status {
    margin-top: 10px;
}
.brcc-sync-status .notice {
    margin: 5px 0;
}
</style>