<?php
/**
 * Import History page template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php _e('Import Historical Data', 'brcc-inventory-tracker'); ?></h1>
    
    <?php if (BRCC_Helpers::is_test_mode()): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('Test Mode is ENABLED', 'brcc-inventory-tracker'); ?></strong>
                <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
                <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
            </p>
        </div>
    <?php endif; ?>
    
    <div class="brcc-import-container">
        <div class="brcc-import-form">
            <h2><?php _e('Import Sales Data', 'brcc-inventory-tracker'); ?></h2>
            <p class="description">
                <?php _e('Use this tool to import historical sales data from WooCommerce, Square, and Eventbrite. This will help populate your sales reports with past data.', 'brcc-inventory-tracker'); ?>
            </p>
            
            <form id="brcc-import-form">
                <div class="brcc-form-row">
                    <label for="brcc-import-start-date"><?php _e('Start Date:', 'brcc-inventory-tracker'); ?></label>
                    <input type="text" id="brcc-import-start-date" class="brcc-date-picker" placeholder="YYYY-MM-DD" required />
                </div>
                
                <div class="brcc-form-row">
                    <label for="brcc-import-end-date"><?php _e('End Date:', 'brcc-inventory-tracker'); ?></label>
                    <input type="text" id="brcc-import-end-date" class="brcc-date-picker" placeholder="YYYY-MM-DD" required />
                </div>
                
                <div class="brcc-form-row">
                    <label><?php _e('Data Sources:', 'brcc-inventory-tracker'); ?></label>
                    <div class="brcc-checkbox-group">
                        <label>
                            <input type="checkbox" name="brcc-import-sources[]" value="woocommerce" checked />
                            <?php _e('WooCommerce', 'brcc-inventory-tracker'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="brcc-import-sources[]" value="square" />
                            <?php _e('Square', 'brcc-inventory-tracker'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="brcc-import-sources[]" value="eventbrite" />
                            <?php _e('Eventbrite', 'brcc-inventory-tracker'); ?>
                        </label>
                    </div>
                </div>
                
                <div class="brcc-form-actions">
                    <button type="submit" id="brcc-start-import" class="button button-primary"><?php _e('Start Import', 'brcc-inventory-tracker'); ?></button>
                </div>
            </form>
        </div>
        
        <div class="brcc-import-progress" style="display: none;">
            <h3><?php _e('Import Progress', 'brcc-inventory-tracker'); ?></h3>
            
            <div class="brcc-progress-bar-container">
                <div class="brcc-progress-bar" style="width: 0%;"></div>
            </div>
            
            <div class="brcc-progress-status">
                <span class="brcc-progress-percentage">0%</span>
                <span class="brcc-progress-message"><?php _e('Preparing import...', 'brcc-inventory-tracker'); ?></span>
            </div>
            
            <div class="brcc-import-logs">
                <h4><?php _e('Import Logs', 'brcc-inventory-tracker'); ?></h4>
                <div class="brcc-log-container"></div>
            </div>
            
            <div class="brcc-import-actions">
                <button type="button" id="brcc-cancel-import" class="button button-secondary"><?php _e('Cancel Import', 'brcc-inventory-tracker'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Initialize date pickers
    $('.brcc-date-picker').datepicker({
        dateFormat: 'yy-mm-dd',
        maxDate: 0 // Prevent future dates
    });
    
    // Set default dates (last 30 days)
    var today = new Date();
    var thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    $('#brcc-import-end-date').datepicker('setDate', today);
    $('#brcc-import-start-date').datepicker('setDate', thirtyDaysAgo);
    
    // Import form submission
    $('#brcc-import-form').on('submit', function(e) {
        e.preventDefault();
        
        // Validate form
        var startDate = $('#brcc-import-start-date').val();
        var endDate = $('#brcc-import-end-date').val();
        var sources = [];
        
        $('input[name="brcc-import-sources[]"]:checked').each(function() {
            sources.push($(this).val());
        });
        
        if (!startDate || !endDate) {
            alert('<?php _e('Please select both start and end dates.', 'brcc-inventory-tracker'); ?>');
            return;
        }
        
        if (sources.length === 0) {
            alert('<?php _e('Please select at least one data source.', 'brcc-inventory-tracker'); ?>');
            return;
        }
        
        // Initialize import state
        var importState = {
            start_date: startDate,
            end_date: endDate,
            sources: sources,
            source_index: 0,
            total_processed: 0,
            wc_offset: 0,
            square_cursor: null,
            eventbrite_page: 1,
            nonce: brcc_admin.nonce
        };
        
        // Show progress UI
        $('.brcc-import-form').hide();
        $('.brcc-import-progress').show();
        $('.brcc-log-container').empty();
        
        // Start the import process
        processImportBatch(importState);
    });
    
    // Cancel import button
    $('#brcc-cancel-import').on('click', function() {
        if (confirm('<?php _e('Are you sure you want to cancel the import? Progress will be lost.', 'brcc-inventory-tracker'); ?>')) {
            $('.brcc-import-progress').hide();
            $('.brcc-import-form').show();
        }
    });
    
    // Process import batch
    function processImportBatch(state) {
        // Add log entry for starting batch
        addLogEntry('<?php _e('Processing batch...', 'brcc-inventory-tracker'); ?>', 'info');
        
        // Make AJAX request
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_import_batch',
                state_data: state
            },
            success: function(response) {
                if (response.success) {
                    // Update progress
                    updateProgress(response.data.progress, response.data.message);
                    
                    // Add log entries
                    if (response.data.logs && response.data.logs.length) {
                        response.data.logs.forEach(function(log) {
                            addLogEntry(log.message, log.type || 'info');
                        });
                    }
                    
                    // Check if we need to continue
                    if (response.data.next_state) {
                        // Continue with next batch
                        setTimeout(function() {
                            processImportBatch(response.data.next_state);
                        }, 500); // Small delay to prevent overwhelming the server
                    } else {
                        // Import complete
                        addLogEntry('<?php _e('Import completed successfully!', 'brcc-inventory-tracker'); ?>', 'success');
                        $('#brcc-cancel-import').text('<?php _e('Return to Form', 'brcc-inventory-tracker'); ?>');
                    }
                } else {
                    // Handle error
                    updateProgress(0, response.data.message || '<?php _e('Import failed.', 'brcc-inventory-tracker'); ?>');
                    addLogEntry(response.data.message || '<?php _e('An error occurred during import.', 'brcc-inventory-tracker'); ?>', 'error');
                    $('#brcc-cancel-import').text('<?php _e('Return to Form', 'brcc-inventory-tracker'); ?>');
                }
            },
            error: function() {
                // Handle AJAX error
                updateProgress(0, '<?php _e('Import failed due to server error.', 'brcc-inventory-tracker'); ?>');
                addLogEntry('<?php _e('A server error occurred during import.', 'brcc-inventory-tracker'); ?>', 'error');
                $('#brcc-cancel-import').text('<?php _e('Return to Form', 'brcc-inventory-tracker'); ?>');
            }
        });
    }
    
    // Update progress bar and status
    function updateProgress(percentage, message) {
        $('.brcc-progress-bar').css('width', percentage + '%');
        $('.brcc-progress-percentage').text(percentage + '%');
        
        if (message) {
            $('.brcc-progress-message').text(message);
        }
    }
    
    // Add log entry to the log container
    function addLogEntry(message, type) {
        var logClass = 'brcc-log-' + (type || 'info');
        var timestamp = new Date().toLocaleTimeString();
        var logEntry = $('<div class="brcc-log-entry ' + logClass + '"></div>');
        
        logEntry.html('<span class="brcc-log-time">[' + timestamp + ']</span> ' + message);
        $('.brcc-log-container').append(logEntry);
        
        // Scroll to bottom
        var logContainer = $('.brcc-log-container');
        logContainer.scrollTop(logContainer[0].scrollHeight);
    }
});
</script>

<style>
.brcc-import-container {
    margin-top: 20px;
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
.brcc-progress-bar-container {
    height: 20px;
    background-color: #f0f0f0;
    border-radius: 4px;
    margin-bottom: 10px;
    overflow: hidden;
}
.brcc-progress-bar {
    height: 100%;
    background-color: #0073aa;
    transition: width 0.3s ease;
}
.brcc-progress-status {
    margin-bottom: 20px;
}
.brcc-progress-percentage {
    font-weight: bold;
    margin-right: 10px;
}
.brcc-log-container {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #ddd;
    padding: 10px;
    background-color: #f9f9f9;
    margin-bottom: 20px;
}
.brcc-log-entry {
    margin-bottom: 5px;
    padding: 3px 0;
    border-bottom: 1px solid #eee;
}
.brcc-log-time {
    color: #666;
    margin-right: 5px;
}
.brcc-log-info {
    color: #000;
}
.brcc-log-success {
    color: #4caf50;
}
.brcc-log-warning {
    color: #ff9800;
}
.brcc-log-error {
    color: #f44336;
}
.brcc-import-actions {
    margin-top: 20px;
}
</style>