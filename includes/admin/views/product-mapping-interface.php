<?php
/**
 * Product Mapping Interface template
 * 
 * @var array $products List of WooCommerce products
 * @var array $mappings Existing product mappings, structured as:
 *                      $mappings[$product_id] = array(
 *                          'base' => array(
 *                              eventbrite_id (ticket class ID),
 *                              eventbrite_event_id (event ID),
 *                              square_id
 *                          ),
 *                          'dates' => array(
 *                              'YYYY-MM-DD_HH:MM' => array(
 *                                  eventbrite_event_id (event ID),
 *                                  eventbrite_id (ticket class ID),
 *                                  ticket_class_id (same as eventbrite_id),
 *                                  manual_eventbrite_id (same as eventbrite_id),
 *                                  square_id,
 *                                  time
 *                              )
 *                          )
 *                      )
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="brcc-product-mapping-interface">
    <h2><?php _e('Product Mappings', 'brcc-inventory-tracker'); ?></h2>
    <p class="description">
        <?php _e('Map WooCommerce products to Eventbrite events and tickets for inventory tracking.', 'brcc-inventory-tracker'); ?>
    </p>
    
    <?php if (empty($products)): ?>
        <div class="notice notice-warning">
            <p><?php _e('No products found. Please create some WooCommerce products first.', 'brcc-inventory-tracker'); ?></p>
        </div>
    <?php else: ?>
        <div class="brcc-mapping-table-container">
            <table class="wp-list-table widefat fixed striped brcc-mapping-table">
                <thead>
                    <tr>
                        <th><?php _e('WooCommerce Product', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Eventbrite Event', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Eventbrite Ticket Class ID', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Square Item ID', 'brcc-inventory-tracker'); ?></th>
                        <th><?php _e('Actions', 'brcc-inventory-tracker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $product_id = $product->ID;
                        $product_mapping = isset($mappings[$product_id]['base']) ? $mappings[$product_id]['base'] : array();
                        $date_mappings = isset($mappings[$product_id]['dates']) ? $mappings[$product_id]['dates'] : array();
                        
                        $eventbrite_event_id = isset($product_mapping['eventbrite_event_id']) ? $product_mapping['eventbrite_event_id'] : '';
                        $eventbrite_id = isset($product_mapping['eventbrite_id']) ? $product_mapping['eventbrite_id'] : '';
                        $square_id = isset($product_mapping['square_id']) ? $product_mapping['square_id'] : '';
                        ?>
                        <tr data-product-id="<?php echo esc_attr($product_id); ?>">
                            <td>
                                <strong><?php echo esc_html($product->post_title); ?></strong>
                                <div class="brcc-product-meta">
                                    <?php _e('ID:', 'brcc-inventory-tracker'); ?> <?php echo esc_html($product_id); ?>
                                </div>
                                <div class="row-actions">
                                    <span class="manage-dates">
                                        <a href="#" class="brcc-manage-dates" data-action="manage">
                                            <?php _e('Manage Dates', 'brcc-inventory-tracker'); ?>
                                        </a>
                                    </span>
                                    <span class="hide-dates" style="display: none;">
                                        <a href="#" class="brcc-hide-dates" data-action="hide">
                                            <?php _e('Hide Dates', 'brcc-inventory-tracker'); ?>
                                        </a>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <select class="brcc-eventbrite-event-select brcc-select2" data-product-id="<?php echo esc_attr($product_id); ?>">
                                    <option value=""><?php _e('Select an event...', 'brcc-inventory-tracker'); ?></option>
                                    <!-- Events will be loaded via AJAX -->
                                </select>
                                <div class="brcc-loading-events" style="display: none;">
                                    <span class="spinner is-active"></span>
                                    <?php _e('Loading events...', 'brcc-inventory-tracker'); ?>
                                </div>
                                <input type="hidden" class="brcc-eventbrite-event-id" value="<?php echo esc_attr($eventbrite_event_id); ?>" />
                                
                                <!-- Manual entry field for Eventbrite Event ID -->
                                <div style="margin-top: 5px;">
                                    <input type="text" class="brcc-manual-eventbrite-event-id" placeholder="<?php _e('Or enter Event ID manually...', 'brcc-inventory-tracker'); ?>" value="<?php echo esc_attr($eventbrite_event_id); ?>" />
                                    <small style="display: block; color: #666;"><?php _e('Manual Event ID entry', 'brcc-inventory-tracker'); ?></small>
                                </div>
                            </td>
                            <td>
                                <input type="text" class="brcc-eventbrite-id" placeholder="<?php _e('Enter Ticket Class ID...', 'brcc-inventory-tracker'); ?>" value="<?php echo esc_attr($eventbrite_id); ?>" />
                                <button type="button" class="button button-small brcc-suggest-id" title="<?php _e('Suggest Eventbrite Ticket Class ID', 'brcc-inventory-tracker'); ?>">
                                    <?php _e('Suggest', 'brcc-inventory-tracker'); ?>
                                </button>
                            </td>
                            <td>
                                <input type="text" class="brcc-square-id" placeholder="<?php _e('Enter Square ID...', 'brcc-inventory-tracker'); ?>" value="<?php echo esc_attr($square_id); ?>" />
                            </td>
                            <td>
                                <button type="button" class="button button-secondary brcc-test-mapping"><?php _e('Test', 'brcc-inventory-tracker'); ?></button>
                                <div class="brcc-test-results"></div>
                            </td>
                        </tr>
                        <tr class="brcc-date-mappings-row" data-product-id="<?php echo esc_attr($product_id); ?>" style="display: none;">
                            <td colspan="5">
                                <div class="brcc-date-mappings">
                                    <h3><?php printf(__('Date Mappings for Product ID: %s', 'brcc-inventory-tracker'), $product_id); ?></h3>
                                    <p class="description"><?php _e('Manage date-specific Eventbrite & Square mappings below.', 'brcc-inventory-tracker'); ?></p>
                                    
                                    <div class="brcc-date-mappings-list">
                                        <table class="wp-list-table widefat fixed striped">
                                            <thead>
                                                <tr>
                                                    <th><?php _e('Date & Time', 'brcc-inventory-tracker'); ?></th>
                                                    <th><?php _e('Eventbrite Event / Ticket Class ID', 'brcc-inventory-tracker'); ?></th>
                                                    <th><?php _e('Square ID', 'brcc-inventory-tracker'); ?></th>
                                                    <th><?php _e('Actions', 'brcc-inventory-tracker'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($date_mappings)): ?>
                                                    <?php foreach ($date_mappings as $date_key => $date_mapping): ?>
                                                        <?php
                                                        list($date, $time) = explode('_', $date_key);
                                                        ?>
                                                        <tr class="brcc-date-mapping-item" data-date="<?php echo esc_attr($date); ?>" data-time="<?php echo esc_attr($time); ?>">
                                                            <td>
                                                                <input type="text" class="brcc-date-picker" value="<?php echo esc_attr($date); ?>" />
                                                                <input type="text" class="brcc-time-picker" value="<?php echo esc_attr($time); ?>" />
                                                            </td>
                                                            <td>
                                                                <select class="brcc-date-eventbrite-event-select brcc-select2">
                                                                    <option value=""><?php _e('Select an event...', 'brcc-inventory-tracker'); ?></option>
                                                                </select>
                                                                <input type="hidden" class="brcc-date-eventbrite-event-id" value="<?php echo esc_attr($date_mapping['eventbrite_event_id']); ?>" />
                                                                
                                                                <!-- Manual entry field for date-specific Eventbrite Event ID -->
                                                                <div style="margin-top: 5px; margin-bottom: 5px;">
                                                                    <input type="text" class="brcc-manual-date-eventbrite-event-id" placeholder="<?php _e('Or enter Event ID manually...', 'brcc-inventory-tracker'); ?>" value="<?php echo esc_attr($date_mapping['eventbrite_event_id']); ?>" />
                                                                    <small style="display: block; color: #666;"><?php _e('Manual Event ID entry', 'brcc-inventory-tracker'); ?></small>
                                                                </div>
                                                                
                                                                <input type="text" class="brcc-date-eventbrite-id" placeholder="<?php _e('Enter Ticket Class ID...', 'brcc-inventory-tracker'); ?>" value="<?php echo esc_attr($date_mapping['eventbrite_id']); ?>" />
                                                            </td>
                                                            <td>
                                                                <input type="text" class="brcc-date-square-id" placeholder="<?php _e('Enter Square ID...', 'brcc-inventory-tracker'); ?>" value="<?php echo esc_attr($date_mapping['square_id']); ?>" />
                                                            </td>
                                                            <td>
                                                                <button type="button" class="button button-secondary brcc-test-date-mapping"><?php _e('Test', 'brcc-inventory-tracker'); ?></button>
                                                                <button type="button" class="button button-secondary brcc-remove-date-mapping"><?php _e('Remove', 'brcc-inventory-tracker'); ?></button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="brcc-date-mappings-actions">
                                        <button type="button" class="button button-secondary brcc-add-date-mapping">
                                            <?php _e('Add New Date/Time', 'brcc-inventory-tracker'); ?>
                                        </button>
                                        <button type="button" class="button button-primary brcc-save-date-mappings">
                                            <?php _e('Save All Changes', 'brcc-inventory-tracker'); ?>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="brcc-mapping-actions">
            <button type="button" id="brcc-save-mappings" class="button button-primary"><?php _e('Save Mappings', 'brcc-inventory-tracker'); ?></button>
            <button type="button" id="brcc-sync-inventory" class="button button-secondary" style="margin-left: 10px;">
                <span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
                <?php _e('Sync Inventory Now', 'brcc-inventory-tracker'); ?>
            </button>
            <span id="brcc-save-status"></span>
            <div id="brcc-sync-status" style="display: none; margin-top: 10px;" class="notice notice-info">
                <p><?php _e('Syncing inventory...', 'brcc-inventory-tracker'); ?> <span class="spinner is-active" style="float: none; margin: 0;"></span></p>
            </div>
        </div>
        
        <div class="notice notice-info" style="margin-top: 20px;">
            <p><strong><?php _e('Inventory Sync Instructions:', 'brcc-inventory-tracker'); ?></strong></p>
            <ol>
                <li><?php _e('First, make sure all your products are properly mapped to Eventbrite tickets.', 'brcc-inventory-tracker'); ?></li>
                <li><?php _e('Click "Save Mappings" to save your product mappings.', 'brcc-inventory-tracker'); ?></li>
                <li><?php _e('Click "Sync Inventory Now" to synchronize inventory between WooCommerce and Eventbrite.', 'brcc-inventory-tracker'); ?></li>
                <li><?php _e('After the initial sync, inventory will be automatically decremented on both platforms when a sale occurs.', 'brcc-inventory-tracker'); ?></li>
            </ol>
        </div>

        <!-- Template for new date mapping row -->
        <script type="text/template" id="tmpl-date-mapping-row">
            <tr class="brcc-date-mapping-item" data-date="" data-time="">
                <td>
                    <input type="text" class="brcc-date-picker" value="" />
                    <input type="text" class="brcc-time-picker" value="" />
                </td>
                <td>
                    <select class="brcc-date-eventbrite-event-select brcc-select2">
                        <option value=""><?php _e('Select an event...', 'brcc-inventory-tracker'); ?></option>
                    </select>
                    <input type="hidden" class="brcc-date-eventbrite-event-id" value="" />
                    
                    <!-- Manual entry field for date-specific Eventbrite Event ID -->
                    <div style="margin-top: 5px; margin-bottom: 5px;">
                        <input type="text" class="brcc-manual-date-eventbrite-event-id" placeholder="<?php _e('Or enter Event ID manually...', 'brcc-inventory-tracker'); ?>" value="" />
                        <small style="display: block; color: #666;"><?php _e('Manual Event ID entry', 'brcc-inventory-tracker'); ?></small>
                    </div>
                    
                    <input type="text" class="brcc-date-eventbrite-id" placeholder="<?php _e('Enter Ticket Class ID...', 'brcc-inventory-tracker'); ?>" value="" />
                </td>
                <td>
                    <input type="text" class="brcc-date-square-id" placeholder="<?php _e('Enter Square ID...', 'brcc-inventory-tracker'); ?>" value="" />
                </td>
                <td>
                    <button type="button" class="button button-secondary brcc-test-date-mapping"><?php _e('Test', 'brcc-inventory-tracker'); ?></button>
                    <button type="button" class="button button-secondary brcc-remove-date-mapping"><?php _e('Remove', 'brcc-inventory-tracker'); ?></button>
                </td>
            </tr>
        </script>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Initialize Select2
    $('.brcc-select2').select2();
    
    // Initialize date pickers
    $('.brcc-date-picker').datepicker({
        dateFormat: 'yy-mm-dd',
        minDate: 0
    });

    // Initialize time pickers
    $('.brcc-time-picker').timepicker({
        timeFormat: 'HH:mm',
        interval: 30,
        minTime: '0',
        maxTime: '23:30',
        defaultTime: '19',
        startTime: '00:00',
        dynamic: false,
        dropdown: true,
        scrollbar: true
    });
    
    // Load Eventbrite events for all dropdowns
    loadEventbriteEvents();
    
    // Toggle date mappings - Handle "Manage Dates" click
    $('.brcc-manage-dates').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event bubbling
        console.log('Manage Dates clicked');
        
        // Store current scroll position
        var scrollPos = $(window).scrollTop();
        
        var $link = $(this);
        var $row = $link.closest('tr');
        var productId = $row.data('product-id');
        console.log('Product ID:', productId);
        
        // Find the date mappings row using the product ID
        var $dateRow = $('.brcc-date-mappings-row[data-product-id="' + productId + '"]');
        console.log('Date row found:', $dateRow.length);
        
        // Show the date mappings using multiple methods
        $dateRow.css('display', 'table-row');
        $dateRow.show();
        $dateRow.removeClass('hidden');
        
        // Toggle the visibility of the action links
        $link.closest('.row-actions').find('.manage-dates').hide();
        $link.closest('.row-actions').find('.hide-dates').show();
        
        // Clear any error messages that might be displayed
        $('.notice-error').hide();
        
        // Restore scroll position
        setTimeout(function() {
            $(window).scrollTop(scrollPos);
        }, 10);
        
        console.log('Manage Dates processing complete');
        return false; // Prevent any default behavior or event bubbling
    });
    
    // Handle "Hide Dates" click
    $('.brcc-hide-dates').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event bubbling
        
        console.log('Hide Dates clicked');
        
        // Store current scroll position
        var scrollPos = $(window).scrollTop();
        
        // Get the product ID from the data attribute
        var $row = $(this).closest('tr');
        var productId = $row.data('product-id');
        console.log('Product ID:', productId);
        
        // Find the date mappings row using the product ID
        var $dateRow = $('.brcc-date-mappings-row[data-product-id="' + productId + '"]');
        console.log('Date row found:', $dateRow.length);
        
        // Force hide the date mappings row using multiple methods
        $dateRow.css('display', 'none');
        $dateRow.hide();
        $dateRow.addClass('hidden');
        
        // Toggle the visibility of the action links
        $(this).closest('.row-actions').find('.hide-dates').hide();
        $(this).closest('.row-actions').find('.manage-dates').show();
        
        // Clear any error messages that might be displayed
        $('.notice-error').hide();
        
        // Restore scroll position
        setTimeout(function() {
            $(window).scrollTop(scrollPos);
        }, 10);
        
        console.log('Hide Dates processing complete');
        return false;
    });

    // Add new date mapping
    $('.brcc-add-date-mapping').on('click', function() {
        var $table = $(this).closest('.brcc-date-mappings').find('table tbody');
        var template = wp.template('date-mapping-row');
        var $newRow = $(template());
        
        $table.append($newRow);
        
        // Initialize new row's date/time pickers
        $newRow.find('.brcc-date-picker').datepicker({
            dateFormat: 'yy-mm-dd',
            minDate: 0
        });
        
        $newRow.find('.brcc-time-picker').timepicker({
            timeFormat: 'HH:mm',
            interval: 30,
            minTime: '0',
            maxTime: '23:30',
            defaultTime: '19',
            startTime: '00:00',
            dynamic: false,
            dropdown: true,
            scrollbar: true
        });
        
        // Initialize Select2
        $newRow.find('.brcc-select2').select2();
        
        // Load events for the new dropdown
        loadEventbriteEvents();
    });

    // Remove date mapping
    $(document).on('click', '.brcc-remove-date-mapping', function() {
        $(this).closest('tr').remove();
    });
    
    // Save all mappings
    $('#brcc-save-mappings, .brcc-save-date-mappings').on('click', function() {
        var button = $(this);
        var statusContainer = $('#brcc-save-status');
        var mappings = {};
        
        // Collect base mappings
        $('.brcc-mapping-table tbody tr:not(.brcc-date-mappings-row)').each(function() {
            var row = $(this);
            var productId = row.data('product-id');
            var eventbriteEventId = row.find('.brcc-eventbrite-event-id').val();
            var eventbriteId = row.find('.brcc-eventbrite-id').val();
            var squareId = row.find('.brcc-square-id').val();
            
            // Base mapping
            mappings[productId] = {
                'eventbrite_event_id': eventbriteEventId,
                'eventbrite_id': eventbriteId,
                'square_id': squareId
            };

            // Date mappings
            var dateMappings = {};
            row.next('.brcc-date-mappings-row').find('.brcc-date-mapping-item').each(function() {
                var $item = $(this);
                var date = $item.find('.brcc-date-picker').val();
                var time = $item.find('.brcc-time-picker').val();
                
                if (date && time) {
                    var dateKey = date + '_' + time;
                    dateMappings[dateKey] = {
                        'eventbrite_event_id': $item.find('.brcc-date-eventbrite-event-id').val(),
                        'eventbrite_id': $item.find('.brcc-date-eventbrite-id').val(),
                        'ticket_class_id': $item.find('.brcc-date-eventbrite-id').val(),
                        'manual_eventbrite_id': $item.find('.brcc-date-eventbrite-id').val(),
                        'square_id': $item.find('.brcc-date-square-id').val(),
                        'time': time
                    };
                }
            });
            
            if (Object.keys(dateMappings).length > 0) {
                mappings[productId + '_dates'] = dateMappings;
            }
        });
        
        // Save mappings via AJAX
        button.prop('disabled', true).text(brcc_admin.saving);
        statusContainer.html('<span class="spinner is-active"></span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_save_product_mappings',
                mappings: mappings,
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text(brcc_admin.save_mappings);
                
                if (response.success) {
                    statusContainer.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    
                    // Clear status after a delay
                    setTimeout(function() {
                        statusContainer.empty();
                    }, 3000);
                } else {
                    statusContainer.html('<div class="notice notice-error inline"><p>' + (response.data.message || brcc_admin.ajax_error) + '</p></div>');
                }
            },
            error: function() {
                button.prop('disabled', false).text(brcc_admin.save_mappings);
                statusContainer.html('<div class="notice notice-error inline"><p>' + brcc_admin.ajax_error + '</p></div>');
            }
        });
    });
    
    // Test mapping button
    $('.brcc-test-mapping, .brcc-test-date-mapping').on('click', function() {
        var button = $(this);
        var row = button.closest('tr');
        var isDateMapping = button.hasClass('brcc-test-date-mapping');
        var productId, eventbriteEventId, eventbriteId, squareId;
        
        if (isDateMapping) {
            productId = row.closest('.brcc-date-mappings-row').data('product-id');
            eventbriteEventId = row.find('.brcc-date-eventbrite-event-id').val();
            eventbriteId = row.find('.brcc-date-eventbrite-id').val();
            squareId = row.find('.brcc-date-square-id').val();
        } else {
            productId = row.data('product-id');
            eventbriteEventId = row.find('.brcc-eventbrite-event-id').val();
            eventbriteId = row.find('.brcc-eventbrite-id').val();
            squareId = row.find('.brcc-square-id').val();
        }
        
        var resultsContainer = row.find('.brcc-test-results');
        
        button.prop('disabled', true).text(brcc_admin.testing);
        resultsContainer.html('<span class="spinner is-active"></span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_test_product_mapping',
                product_id: productId,
                eventbrite_event_id: eventbriteEventId,
                eventbrite_id: eventbriteId,
                square_id: squareId,
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text(brcc_admin.test);
                
                if (response.success) {
                    resultsContainer.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                } else {
                    resultsContainer.html('<div class="notice notice-error inline"><p>' + (response.data.message || brcc_admin.ajax_error) + '</p></div>');
                }
            },
            error: function() {
                button.prop('disabled', false).text(brcc_admin.test);
                resultsContainer.html('<div class="notice notice-error inline"><p>' + brcc_admin.ajax_error + '</p></div>');
            }
        });
    });
    
    // Suggest Eventbrite ID button
    $('.brcc-suggest-id').on('click', function() {
        var button = $(this);
        var row = button.closest('tr');
        var productId = row.data('product-id');
        var inputField = row.find('.brcc-eventbrite-id');
        
        button.prop('disabled', true);
        button.after('<span class="spinner is-active"></span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_suggest_eventbrite_ticket_class_id',
                product_id: productId,
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                button.prop('disabled', false);
                button.next('.spinner').remove();
                
                if (response.success && response.data.suggestion) {
                    inputField.val(response.data.suggestion.ticket_id);
                    
                    // Show tooltip with suggestion details
                    var tooltipContent = response.data.suggestion.event_name + ' - ' + 
                                        response.data.suggestion.ticket_name + ' (' + 
                                        response.data.suggestion.relevance + '% match)';
                    
                    alert(tooltipContent);
                } else {
                    alert(response.data.message || brcc_admin.ajax_error);
                }
            },
            error: function() {
                button.prop('disabled', false);
                button.next('.spinner').remove();
                alert(brcc_admin.ajax_error);
            }
        });
    });
    
    // Eventbrite event select change
    $(document).on('change', '.brcc-eventbrite-event-select, .brcc-date-eventbrite-event-select', function() {
        var select = $(this);
        var eventId = select.val();
        
        // Update hidden input with the selected event ID
        if (select.hasClass('brcc-eventbrite-event-select')) {
            select.closest('tr').find('.brcc-eventbrite-event-id').val(eventId);
        } else {
            select.siblings('.brcc-date-eventbrite-event-id').val(eventId);
        }
    });
    
    // Function to load Eventbrite events
    function loadEventbriteEvents() {
        $('.brcc-loading-events').show();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_get_all_eventbrite_events_for_attendees',
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                $('.brcc-loading-events').hide();
                
                if (response.success && response.data.events) {
                    var events = response.data.events;
                    
                    // Populate all event dropdowns (both base and date-specific)
                    $('.brcc-eventbrite-event-select, .brcc-date-eventbrite-event-select').each(function() {
                        var select = $(this);
                        var currentValue = select.hasClass('brcc-eventbrite-event-select') ?
                            select.closest('tr').find('.brcc-eventbrite-event-id').val() :
                            select.siblings('.brcc-date-eventbrite-event-id').val();
                        
                        select.empty();
                        select.append('<option value="">' + '<?php _e('Select an event...', 'brcc-inventory-tracker'); ?>' + '</option>');
                        
                        // Add events to dropdown
                        $.each(events, function(eventId, eventName) {
                            var option = $('<option></option>')
                                .attr('value', eventId)
                                .text(eventName);
                                
                            if (eventId === currentValue) {
                                option.attr('selected', 'selected');
                            }
                            
                            select.append(option);
                        });
                        
                        // Refresh Select2
                        select.trigger('change');
                    });
                }
            },
            error: function() {
                $('.brcc-loading-events').hide();
            }
        });
    }
});
</script>

<style>
.brcc-product-mapping-interface {
    margin-top: 20px;
}
.brcc-mapping-table-container {
    margin-top: 20px;
    margin-bottom: 20px;
}
.brcc-mapping-table th {
    padding: 10px;
}
.brcc-mapping-table td {
    padding: 15px 10px;
    vertical-align: top;
}
.brcc-eventbrite-id,
.brcc-square-id,
.brcc-date-eventbrite-id,
.brcc-date-square-id {
    width: 100%;
    max-width: 200px;
}
.brcc-suggest-id {
    margin-top: 5px !important;
}
.brcc-test-results {
    margin-top: 10px;
}
.brcc-test-results .notice {
    margin: 5px 0;
    padding: 5px 10px;
}
.brcc-mapping-actions {
    margin-top: 20px;
}
#brcc-save-status {
    margin-left: 10px;
    display: inline-block;
    vertical-align: middle;
}
#brcc-save-status .notice {
    margin: 0;
    display: inline-block;
}
.brcc-loading-events {
    margin-top: 5px;
}
.brcc-loading-events .spinner {
    float: none;
    margin-top: -3px;
}
.brcc-date-mappings {
    padding: 20px;
    background: #f9f9f9;
    border: 1px solid #e5e5e5;
    margin-top: 10px;
}
.brcc-date-mappings h3 {
    margin-top: 0;
    margin-bottom: 10px;
}
.brcc-date-mappings .description {
    margin-bottom: 20px;
}
.brcc-date-mapping-item td {
    vertical-align: middle;
}
.brcc-date-time input {
    width: 120px;
    margin-right: 10px;
}
.brcc-date-mappings-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e5e5e5;
}
.brcc-date-mappings-actions .button {
    margin-right: 10px;
}
.row-actions {
    margin-top: 5px;
}
.row-actions span {
    padding: 0 5px;
}
.row-actions span:first-child {
    padding-left: 0;
}
.brcc-product-meta {
    color: #666;
    font-size: 12px;
    margin-top: 5px;
}
</style>