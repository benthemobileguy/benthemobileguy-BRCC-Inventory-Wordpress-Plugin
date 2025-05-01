/**
 * BRCC Inventory Tracker - Date Mappings Handler
 * 
 * This script manages date-specific product mappings between WooCommerce, 
 * Eventbrite, and Square in the admin interface.
 */

// --- Helper Functions (Defined outside document.ready) ---

/**
 * Get the correct WordPress nonce from available sources
 * @return {string} The nonce value
 */
function getCorrectNonce() {
    // Try brcc_admin object first (most reliable)
    if (typeof brcc_admin !== 'undefined' && brcc_admin.nonce) {
        return brcc_admin.nonce;
    }
    
    // Try finding a hidden nonce field in the DOM
    var $nonceField = jQuery('input[name="brcc_nonce"], input[name="_wpnonce"], input[name="nonce"]').first();
    if ($nonceField.length) {
        return $nonceField.val();
    }
    
    // Try data attribute as last resort
    var $nonceElement = jQuery('[data-nonce]').first();
    if ($nonceElement.length) {
        return $nonceElement.attr('data-nonce');
    }
    
    console.warn('Could not find a valid nonce!');
    return '';
}

/**
 * Initialize Select2/SelectWoo dropdowns
 * @param {jQuery} $select - The select element to initialize
 */
function initializeSelectDropdowns($select) {
    if (!$select || !$select.length) {
        console.warn('initializeSelectDropdowns: No select element provided');
        return;
    }

    // Custom matcher function to search by text OR id
    function matchEventOption(params, data) {
        // If there are no search terms, return all options
        if (jQuery.trim(params.term) === '') {
            return data;
        }

        // Skip if data isn't properly formed
        if (typeof data.text === 'undefined' || typeof data.id === 'undefined') {
            return null;
        }

        // Check if term is in text or id (case-insensitive)
        var term = params.term.toLowerCase();
        var text = data.text.toLowerCase();
        var id = data.id.toString().toLowerCase(); // Ensure id is treated as string

        if (text.indexOf(term) > -1 || id.indexOf(term) > -1) {
            return data;
        }

        // Return null if no match is found
        return null;
    }
    
    try {
        var selectOptions = {
            width: '100%',
            minimumResultsForSearch: 5,
            dropdownAutoWidth: true,
            matcher: matchEventOption // Use the custom matcher
        };

        if (typeof jQuery.fn.selectWoo === 'function') {
            // Destroy existing instance first if it exists
            if ($select.data('selectWoo')) $select.selectWoo('destroy');
            $select.selectWoo(selectOptions);
            return true;
        } else if (typeof jQuery.fn.select2 === 'function') {
            // Destroy existing instance first if it exists
            if ($select.data('select2')) $select.select2('destroy');
            $select.select2(selectOptions);
            return true;
        }
    } catch(e) {
        console.error('Select2/SelectWoo initialization failed:', e);
    }
    
    return false; // Failed to initialize
}

/**
 * Format time value with appropriate format
 * @param {string} timeValue - Time value in HH:MM format
 * @param {string} wpTimeFormat - WordPress time format
 * @return {string} Formatted time string
 */
function formatTime(timeValue, wpTimeFormat) {
    if (!timeValue) return '';
    
    try {
        // Use moment.js if available for better formatting
        if (typeof moment === 'function') {
            return moment(timeValue, 'HH:mm').format(wpTimeFormat.replace(/g/g, 'h').replace(/a/g, 'A'));
        }
        
        // Basic formatting fallback
        var timeParts = timeValue.split(':');
        var hour = parseInt(timeParts[0], 10);
        var minute = timeParts[1] || '00';
        var ampm = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12;
        hour = hour ? hour : 12; // Convert 0 to 12
        return hour + ':' + minute + ' ' + ampm;
    } catch(e) {
        console.error('Time formatting error:', e);
        return timeValue; // Return original as fallback
    }
}

/**
 * Show notification message
 * @param {jQuery} $container - Container to show message in
 * @param {string} message - Message text
 * @param {string} type - Message type (success, error, warning, info)
 * @param {number} duration - How long to show in ms (0 for permanent)
 */
function showNotification($container, message, type, duration) {
    type = type || 'info';
    duration = duration || 3000;
    
    var $message = jQuery('<div class="notice notice-' + type + ' inline" style="margin-top: 10px;"><p>' + message + '</p></div>');
    
    // Remove existing notifications
    $container.find('.notice').remove();
    $container.append($message);
    
    if (duration > 0) {
        setTimeout(function() {
            $message.fadeOut(function() { $message.remove(); });
        }, duration);
    }
}

/**
 * Fetch Eventbrite events data and update dropdowns
 * This can be called at any time to ensure we have the events data
 */
function fetchEventbriteEvents(callback) {
    // Check if we already have events data in brcc_admin
    if (typeof brcc_admin !== 'undefined' && 
        typeof brcc_admin.eventbrite_events !== 'undefined' &&
        Object.keys(brcc_admin.eventbrite_events).length > 0) {
        console.log('Using existing Eventbrite events from brcc_admin');
        if (typeof callback === 'function') {
            callback(brcc_admin.eventbrite_events);
        }
        return;
    }

    // Otherwise, fetch via AJAX - ensure we pass the selected date parameter
    jQuery.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'brcc_get_all_eventbrite_events_for_attendees', 
            nonce: getCorrectNonce(),
            selected_date: new Date().toISOString().split('T')[0] // Today's date in YYYY-MM-DD format
        },
        success: function(response) {
            console.log('AJAX response (fetch_eventbrite_events):', response);

            if (response.success && response.data && response.data.events) {
                console.log('Fetched Eventbrite events:', response.data.events);

                // Update the global events object if brcc_admin exists
                if (typeof brcc_admin !== 'undefined') {
                     brcc_admin.eventbrite_events = response.data.events;
                } else {
                     // Handle case where brcc_admin might not be defined yet? Unlikely here.
                     window.brcc_admin = { eventbrite_events: response.data.events };
                }

                // Call the callback if provided
                if (typeof callback === 'function') {
                    callback(response.data.events);
                }
            } else {
                console.error('Failed to fetch Eventbrite events:', response);
                 // Ensure brcc_admin.eventbrite_events is at least an empty object
                 if (typeof brcc_admin !== 'undefined') {
                     brcc_admin.eventbrite_events = brcc_admin.eventbrite_events || {};
                 } else {
                      window.brcc_admin = { eventbrite_events: {} };
                 }
                 // Still call callback, but with empty object
                 if (typeof callback === 'function') {
                    callback({});
                }
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error fetching Eventbrite events:', status, error);
            console.log('Response text:', xhr.responseText);
             // Ensure brcc_admin.eventbrite_events is at least an empty object
             if (typeof brcc_admin !== 'undefined') {
                 brcc_admin.eventbrite_events = brcc_admin.eventbrite_events || {};
             } else {
                  window.brcc_admin = { eventbrite_events: {} };
             }
             // Still call callback, but with empty object
             if (typeof callback === 'function') {
                callback({});
            }
        }
    });
}

/**
 * Copy event options from main dropdown to date-specific dropdowns
 * This function finds all the Eventbrite event options available in the main product
 * mapping UI and copies them to the date-specific mapping dropdowns
 */
function copyEventOptionsFromMainDropdown() {
    // Get Eventbrite event options from main product dropdown
    var eventOptions = {};
    var $mainEventDropdowns = jQuery('.brcc-eventbrite-event-id-select:visible');

    if ($mainEventDropdowns.length > 0) {
        console.log('Found main event dropdowns: ' + $mainEventDropdowns.length);
        // Get options from the first main dropdown
        $mainEventDropdowns.first().find('option').each(function() {
            var $option = jQuery(this);
            var value = $option.val();
            var text = $option.text();

            if (value) { // Skip empty values like "Select Event..."
                eventOptions[value] = text;
            }
        });

        if (Object.keys(eventOptions).length === 0) {
            console.log('No event options found in main dropdown');
            // Attempt to use global data if main dropdown is empty
             if (typeof brcc_admin !== 'undefined' && brcc_admin.eventbrite_events && Object.keys(brcc_admin.eventbrite_events).length > 0) {
                 eventOptions = brcc_admin.eventbrite_events;
                 console.log('Using event options from brcc_admin object as fallback:', eventOptions);
             } else {
                 console.log('No event options found in main dropdown or brcc_admin.');
                 return false; // Indicate failure
             }
        } else {
            console.log('Found event options in main dropdown:', eventOptions);
             // Ensure global data matches main dropdown if main dropdown is source
             if (typeof brcc_admin !== 'undefined') {
                 brcc_admin.eventbrite_events = eventOptions;
             }
        }

    } else if (typeof brcc_admin !== 'undefined' && brcc_admin.eventbrite_events && Object.keys(brcc_admin.eventbrite_events).length > 0) {
        // If main dropdown not found/visible, try getting events from brcc_admin object
        eventOptions = brcc_admin.eventbrite_events;
        console.log('Using event options from brcc_admin object (main dropdown not visible):', eventOptions);
    } else {
        console.log('No event sources found (main dropdown not visible and brcc_admin empty/missing)');
        return false; // Indicate failure
    }

    // Store these options on all date content containers for potential use by 'Add New'
    jQuery('.brcc-dates-content').each(function() {
        jQuery(this).data('brcc-event-options', JSON.stringify(eventOptions));
    });

    // Update all visible date event dropdowns using the gathered options
    jQuery('.brcc-date-event:visible').each(function() {
        var $select = jQuery(this);
        var currentValue = $select.val(); // Preserve current selection

        // Clear existing options except first (placeholder)
        $select.find('option:not(:first)').remove();

        // Add new options
        jQuery.each(eventOptions, function(id, name) {
            var $option = jQuery('<option></option>')
                .attr('value', id)
                .text(name);

            if (id == currentValue) { // Use == for potential type difference
                $option.prop('selected', true);
            }

            $select.append($option);
        });

        // Reinitialize SelectWoo/Select2 using the helper function
        initializeSelectDropdowns($select);
    });

    console.log('copyEventOptionsFromMainDropdown finished updating date dropdowns.');
    return true; // Indicate success
}

/**
 * Update date-specific Eventbrite event dropdowns with available events data
 */
function updateDateSpecificEventDropdowns() {
    var eventOptions = (typeof brcc_admin !== 'undefined' && brcc_admin.eventbrite_events) ? brcc_admin.eventbrite_events : {};

    // Skip if no events available
    if (Object.keys(eventOptions).length === 0) {
        console.log('No Eventbrite events available to populate dropdowns');
        // Optionally add a disabled "No events" option
         jQuery('.brcc-date-event:visible').each(function() {
             var $select = jQuery(this);
             var currentValue = $select.val();
             $select.find('option:not(:first)').remove(); // Clear existing options except placeholder
             $select.append('<option value="" disabled>No events found</option>');
             initializeSelectDropdowns($select); // Reinitialize
         });
        return;
    }

    // Store options on all date content containers (might not be necessary if using global brcc_admin)
    jQuery('.brcc-dates-content').each(function() {
        jQuery(this).data('brcc-event-options', JSON.stringify(eventOptions));
    });

    // Update all visible date event dropdowns
    jQuery('.brcc-date-event:visible').each(function() {
        var $select = jQuery(this);
        var currentValue = $select.val(); // Get current value before clearing

        // Clear existing options except first (placeholder)
        $select.find('option:not(:first)').remove();

        // Add new options
        jQuery.each(eventOptions, function(id, name) {
            var $option = jQuery('<option></option>')
                .attr('value', id)
                .text(name);

            // Check if this option's value matches the previously selected value
            if (id == currentValue) { // Use == for potential type difference
                $option.prop('selected', true); // Use prop for selected state
            }

            $select.append($option);
        });

        // Reinitialize SelectWoo/Select2
        initializeSelectDropdowns($select); // Use the existing helper
    });

    console.log('Date-specific event dropdowns updated with Eventbrite data');
}

/**
 * Initialize any existing datepickers on page load
 */
function initExistingDatepickers() {
    try {
        if (typeof jQuery.fn.datepicker === 'function') {
            jQuery('.brcc-datepicker, .brcc-new-datepicker').datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true
            });
        }
    } catch (e) {
        console.error('Error initializing existing datepickers:', e);
    }
}

// --- Document Ready ---
jQuery(document).ready(function($) {
    // Prevent multiple initializations
    if (window.brccDateMappingsInitialized) {
        console.warn('date-mappings.js: Already initialized. Skipping.');
        return;
    }

    console.log('date-mappings.js: Initialization started');
    
    // --- Configuration ---
    // Ensure we have the brcc_admin object from PHP
    if (typeof brcc_admin === 'undefined') {
        console.error('date-mappings.js: brcc_admin object is UNDEFINED! Check wp_localize_script in PHP.');
        // Create fallback object to prevent JS errors
        window.brcc_admin = {
            ajax_url: ajaxurl || '/wp-admin/admin-ajax.php',
            nonce: '',
            wp_date_format: 'F j, Y',
            wp_time_format: 'g:i a',
            saving: 'Saving...',
            testing: 'Testing...',
            test: 'Test',
            ajax_error: 'An error occurred. Please try again.'
        };
    } else {
        console.log('date-mappings.js: brcc_admin object loaded successfully');
        // Ensure date/time formats are available
        brcc_admin.wp_date_format = brcc_admin.wp_date_format || 'F j, Y';
        brcc_admin.wp_time_format = brcc_admin.wp_time_format || 'g:i a';
    }
    
    // Ensure we have events data by fetching it if needed
    if (typeof brcc_admin !== 'undefined' && 
        (!brcc_admin.eventbrite_events || Object.keys(brcc_admin.eventbrite_events).length === 0)) {
        console.log('date-mappings.js: No Eventbrite events found in brcc_admin, fetching now...');
        fetchEventbriteEvents(function(eventbriteEvents) {
            // After fetching, copy to date-specific dropdowns
            copyEventOptionsFromMainDropdown(); // Use the copy function
        });
    }

    /**
     * Main function to render the date mappings interface
     * @param {jQuery} $container - Container to render content in
     * @param {number} productId - Product ID
     * @param {Object} data - Data from AJAX call
     */
    window.renderSimpleDateMappings = function($container, productId, data) {
        console.log('renderSimpleDateMappings: Rendering for product ID ' + productId);
        
        // Clear loading indicator
        $container.empty();
        
        var dates = data.dates || [];
        // **Explicitly use the global brcc_admin.eventbrite_events**
        var eventOptions = (typeof brcc_admin !== 'undefined' && brcc_admin.eventbrite_events) ? brcc_admin.eventbrite_events : {};
        console.log('renderSimpleDateMappings: Using eventOptions:', eventOptions); // Add log

        var $content = $('<div class="brcc-dates-content"></div>');
        // Store event options data on the container for later use by 'Add New' button
        $content.data('brcc-event-options', JSON.stringify(eventOptions));
        
        // Add title and description
        $content.append('<h3>Date Mappings for Product ID: ' + productId + '</h3>');
        $content.append('<p class="description">Manage date-specific Eventbrite & Square mappings below.</p>');
        
        // Add table
        var $table = $('<table class="wp-list-table widefat fixed striped"></table>');
        var $thead = $('<thead><tr><th>Date & Time</th><th>Eventbrite Event / Ticket ID</th><th>Square ID</th><th>Actions</th></tr></thead>');
        var $tbody = $('<tbody></tbody>');
        
        if (dates.length === 0) {
            $tbody.append('<tr><td colspan="4">No date mappings found. Use the "Add New Date/Time" button below to create mappings.</td></tr>');
        } else {
            // Render each date mapping row
            dates.forEach(function(date_data) {
                // Add data-time attribute if time exists
                var timeAttr = date_data.time ? ' data-time="' + date_data.time + '"' : '';
                var $row = $('<tr data-date="' + date_data.date + '"' + timeAttr + '></tr>');
                
                // Display formatted date and time if available
                // Use formatted date and time directly from PHP response
                var dateDisplay = date_data.formatted_date || date_data.date;
                if (date_data.formatted_time) { // Check if formatted_time exists
                    dateDisplay += ' at ' + date_data.formatted_time;
                }
                $row.append('<td>' + dateDisplay + '</td>');
                
                // --- Eventbrite Column ---
                var $eventbriteCell = $('<td></td>');
                
                // Event dropdown - Use eventbrite_event_id as the name attribute
                var $eventSelect = $('<select class="brcc-date-event" name="eventbrite_event_id[' + date_data.date + ']"></select>');
                $eventSelect.append('<option value="">Select Event</option>');

                // Populate dropdown options using the correct eventOptions variable
                if (Object.keys(eventOptions).length > 0) {
                    $.each(eventOptions, function(id, name) {
                        // Ensure name is a usable string, provide fallback
                        var displayName = (typeof name === 'string' && name.trim() !== '') ? name.trim() : 'Unnamed Event';
                        if (typeof name !== 'string' || name.trim() === '') {
                            console.warn(`Event name for ID ${id} is invalid or empty:`, name);
                        }
                        // Combine name and ID for display
                        var optionText = `${displayName} (ID: ${id})`;
                        var selected = (id == date_data.eventbrite_event_id) ? ' selected' : ''; // Use == for potential type difference
                        $eventSelect.append('<option value="' + id + '"' + selected + '>' + optionText + '</option>');
                    });
                } else {
                     console.warn('renderSimpleDateMappings: No eventOptions available to populate dropdown for date:', date_data.date);
                     // Optionally add a disabled option indicating no events loaded
                     $eventSelect.append('<option value="" disabled>Could not load events</option>');
                }
                $eventbriteCell.append($eventSelect);

                // Ticket ID input - This is the key part we need to fix
                // Try all possible sources of the ticket class ID for maximum compatibility
                var ticketIdValue = '';
                
                // Log all possible sources for debugging
                console.log('Ticket class ID sources for date ' + date_data.date + ':', {
                    'ticket_class_id': date_data.ticket_class_id,
                    'manual_eventbrite_id': date_data.manual_eventbrite_id,
                    'eventbrite_id': date_data.eventbrite_id
                });
                
                // Check each possible key in order of preference
                if (date_data.ticket_class_id) {
                    ticketIdValue = date_data.ticket_class_id;
                    console.log('Using ticket_class_id for ' + date_data.date + ':', ticketIdValue);
                } else if (date_data.manual_eventbrite_id) {
                    ticketIdValue = date_data.manual_eventbrite_id;
                    console.log('Using manual_eventbrite_id for ' + date_data.date + ':', ticketIdValue);
                } else if (date_data.eventbrite_id) {
                    ticketIdValue = date_data.eventbrite_id;
                    console.log('Using eventbrite_id for ' + date_data.date + ':', ticketIdValue);
                }
                
                // The input field's NAME attribute remains 'manual_eventbrite_id' because PHP expects it from that POST key on save
                var $ticketIdInput = $('<input type="text" class="brcc-manual-event-id" name="manual_eventbrite_id[' + date_data.date + ']" value="' + ticketIdValue + '" placeholder="Enter Ticket Class ID" style="width: 100%; margin-top: 5px;">');
                $eventbriteCell.append($ticketIdInput);

                $row.append($eventbriteCell);
                
                // --- Square Column ---
                var $squareCell = $('<td></td>');
                // Ensure square_id is used for the Square ID input field value
                var $squareIdInput = $('<input type="text" class="brcc-square-id" name="square_id[' + date_data.date + ']" value="' + (date_data.square_id || '') + '" placeholder="Enter Square ID" style="width: 100%;">');
                $squareCell.append($squareIdInput);
                $row.append($squareCell);
                
                // --- Actions Column ---
                var $actionsCell = $('<td></td>');
                var $testBtn = $('<button type="button" class="button brcc-test-date-mapping" data-date="' + date_data.date + '">Test</button>');
                var $removeBtn = $('<button type="button" class="button brcc-remove-date" data-date="' + date_data.date + '" style="margin-left: 5px;">Remove</button>');
                $actionsCell.append($testBtn).append($removeBtn);
                
                // Add placeholder for test results
                var $resultArea = $('<div class="brcc-date-test-result" style="margin-top: 5px;"></div>');
                $actionsCell.append($resultArea);
                
                $row.append($actionsCell);
                $tbody.append($row);
            });
        }
        
        $table.append($thead).append($tbody);

        // Add action buttons
        var $buttons = $('<div class="brcc-dates-actions" style="margin-top: 15px;"></div>');
        var $addBtn = $('<button type="button" class="button brcc-add-date" data-product-id="' + productId + '">Add New Date/Time</button>');
        var $saveBtn = $('<button type="button" class="button button-primary brcc-save-dates" data-product-id="' + productId + '" style="margin-left: 10px;">Save All Changes</button>');
        $buttons.append($addBtn).append($saveBtn);

        // Add status message area
        var $statusArea = $('<div class="brcc-save-status" style="display: inline-block; margin-left: 10px;"></div>');
        $buttons.append($statusArea);

        $content.append($buttons); // Buttons added first
        $content.append($table); // Table added second
        $container.append($content);
        
        // Select2/SelectWoo initialization is now handled by the ajaxComplete handler in admin.js
        // after this rendering function completes.
        
        console.log('renderSimpleDateMappings: Rendering complete');
    };

    // --- Event Handlers ---

    // Initialize the select2/selectWoo fields on page load for consistency
    setTimeout(function() {
        // Fetch Eventbrite events and populate all dropdowns on page load
        fetchEventbriteEvents(function(eventbriteEvents) {
            // After fetching, copy to date-specific dropdowns
            copyEventOptionsFromMainDropdown(); // Use the copy function
        });
    }, 500);
    
    // Remove potentially conflicting handlers first
    $(document).off('click', '.brcc-manage-dates');
    $(document).off('click', '.brcc-add-date');
    $(document).off('click', '.brcc-cancel-add-date');
    $(document).off('click', '.brcc-confirm-add-date');
    $(document).off('click', '.brcc-save-dates');
    $(document).off('click', '.brcc-remove-date');
    $(document).off('click', '.brcc-test-date-mapping');
    
    /**
     * Handler for the initial "Manage Dates" button
     */
    $(document).on('click', '.brcc-manage-dates', function(e) {
        e.preventDefault();
        console.log('Manage Dates button clicked');
        
        var $button = $(this);
        var productId = $button.data('product-id');
        var $row = $button.closest('tr');
        var $expandedRow = $('#brcc-dates-row-' + productId);
        
        // Toggle existing row if it exists
        if ($expandedRow.length) {
            if ($expandedRow.is(':visible')) {
                $expandedRow.hide();
                $button.text('Manage Dates');
            } else {
                $expandedRow.show();
                $button.text('Hide Dates');

                // Add this line to ensure event dropdowns are populated when showing existing row
                updateDateSpecificEventDropdowns(); // Use the correct update function
            }
            return;
        }
        
        // Create new row
        $button.text('Loading...').prop('disabled', true);
        
        var colspan = $row.find('td').length;
        var $newRow = $('<tr id="brcc-dates-row-' + productId + '" class="brcc-dates-row"></tr>');
        var $cell = $('<td colspan="' + colspan + '" class="brcc-dates-cell"></td>');
        
        // Add loading indicator
        $cell.html('<div class="brcc-dates-loading"><span class="spinner is-active"></span> Loading date mappings...</div>');
        $newRow.append($cell);
        $row.after($newRow);
        
        // Get nonce value
        var nonce = getCorrectNonce();
        
        // AJAX request to get dates
        $.ajax({
            url: typeof brcc_admin !== 'undefined' ? brcc_admin.ajax_url : ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'brcc_get_product_dates',
                nonce: nonce,
                product_id: productId,
                page: 1
            },
            success: function(response) {
                console.log('AJAX response (get_product_dates):', response); // Log entire response

                if (response && response.success) {
                    // Use the globally defined render function
                    window.renderSimpleDateMappings($cell, productId, response.data);
                    $button.text('Hide Dates').prop('disabled', false);

                    // Update dropdowns with event data
                    updateDateSpecificEventDropdowns(); // Use the correct update function
                } else {
                    var errorMsg = (response && response.data && response.data.message) ? 
                        response.data.message : 'Error loading date mappings.';
                    $cell.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>');
                    $button.text('Manage Dates').prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error (get_product_dates):', status, error);
                console.log('Response Text:', xhr.responseText);
                
                var errorMessage = 'Error loading date mappings.';
                try {
                    var errorData = JSON.parse(xhr.responseText);
                    if (errorData && errorData.data && errorData.data.message) {
                        errorMessage = errorData.data.message;
                    }
                } catch(e) {
                    // Use default error message if JSON parsing fails
                }
                
                $cell.html('<div class="notice notice-error inline"><p>' + errorMessage + '</p></div>');
                $button.text('Manage Dates').prop('disabled', false);
            }
        });
    });
    
    /**
     * Add Date button handler
     */
    $(document).on('click', '.brcc-add-date', function() {
        console.log('Add Date button clicked');
        
        var $button = $(this);
        var productId = $button.data('product-id');
        var $tableBody = $button.closest('.brcc-dates-content').find('tbody');
        
        // Check if target exists
        if ($tableBody.length === 0) {
            console.error('Add Date Error: Target tbody not found');
            alert('Error: Could not add date row. Please try again.');
            return;
        }
        
        // Clear "no mappings" message if it exists
        var $noMappingsRow = $tableBody.find('td[colspan="4"]:contains("No date mappings found")').closest('tr');
        if ($noMappingsRow.length) {
            $noMappingsRow.remove();
        }
        
        // Remove any existing new date rows
        $tableBody.find('.brcc-new-date-row').remove();
        
        // Create new date input row
        var $dateRow = $('<tr class="brcc-new-date-row"></tr>');
        
        // --- Date/Time Cell ---
        var $dateTimeCell = $('<td></td>');
        var $dateInput = $('<input type="text" class="brcc-new-datepicker" style="width: 60%;" placeholder="Select date...">');
        
        // Time Dropdown
        var $timeSelect = $('<select class="brcc-new-time" style="width: 35%; margin-left: 5%;"></select>');
        
        // Populate time options
        var commonTimes = [ 
            { value: "", label: "No Time (All Day)" },
            { value: "08:00", label: "8:00 AM" }, { value: "08:30", label: "8:30 AM" },
            { value: "09:00", label: "9:00 AM" }, { value: "09:30", label: "9:30 AM" },
            { value: "10:00", label: "10:00 AM" }, { value: "10:30", label: "10:30 AM" },
            { value: "11:00", label: "11:00 AM" }, { value: "11:30", label: "11:30 AM" },
            { value: "12:00", label: "12:00 PM" }, { value: "12:30", label: "12:30 PM" },
            { value: "13:00", label: "1:00 PM" }, { value: "13:30", label: "1:30 PM" },
            { value: "14:00", label: "2:00 PM" }, { value: "14:30", label: "2:30 PM" },
            { value: "15:00", label: "3:00 PM" }, { value: "15:30", label: "3:30 PM" },
            { value: "16:00", label: "4:00 PM" }, { value: "16:30", label: "4:30 PM" },
            { value: "17:00", label: "5:00 PM" }, { value: "17:30", label: "5:30 PM" },
            { value: "18:00", label: "6:00 PM" }, { value: "18:30", label: "6:30 PM" },
            { value: "19:00", label: "7:00 PM" }, { value: "19:30", label: "7:30 PM" },
            { value: "20:00", label: "8:00 PM" }, { value: "20:30", label: "8:30 PM" },
            { value: "21:00", label: "9:00 PM" }, { value: "21:30", label: "9:30 PM" },
            { value: "22:00", label: "10:00 PM" }, { value: "22:30", label: "10:30 PM" },
            { value: "23:00", label: "11:00 PM" }
        ];
        
        $.each(commonTimes, function(index, time) {
            $timeSelect.append('<option value="' + time.value + '">' + time.label + '</option>');
        });
        
        $dateTimeCell.append($dateInput).append($timeSelect);
        
        // --- Eventbrite Cell ---
        var $eventbriteCell = $('<td></td>');
        
        // Retrieve stored event options
        var $contentContainer = $button.closest('.brcc-dates-content');
        var eventOptionsJson = $contentContainer.data('brcc-event-options');
        var eventOptions = {};
        try {
            if (eventOptionsJson) {
                eventOptions = JSON.parse(eventOptionsJson);
            }
        } catch(e) {
            console.error("Failed to parse stored event options:", e);
        }

        // Create the new select dropdown and populate it directly
        var $eventSelect = $('<select class="brcc-date-event"></select>');
        $eventSelect.append('<option value="">Select Event</option>'); // Add default option

        // Populate dropdown options from stored data
        $.each(eventOptions, function(id, name) {
            $eventSelect.append('<option value="' + id + '">' + name + '</option>');
        });
        
        $eventbriteCell.append($eventSelect);
        
        // Add ticket ID input
        var $ticketIdInput = $('<input type="text" class="brcc-manual-event-id" placeholder="Enter Ticket Class ID" style="width: 100%; margin-top: 5px;">');
        $eventbriteCell.append($ticketIdInput);
        
        // --- Square Cell ---
        var $squareCell = $('<td></td>');
        var $squareIdInput = $('<input type="text" class="brcc-square-id" placeholder="Enter Square ID" style="width: 100%;">');
        $squareCell.append($squareIdInput);
        
        // --- Actions Cell ---
        var       $actionsCell = $('<td></td>');
        var $addButton = $('<button type="button" class="button button-primary brcc-confirm-add-date">Add</button>');
        var $cancelButton = $('<button type="button" class="button brcc-cancel-add-date" style="margin-left: 5px;">Cancel</button>');
        $actionsCell.append($addButton).append($cancelButton);
        
        // Append cells to row
        $dateRow.append($dateTimeCell).append($eventbriteCell).append($squareCell).append($actionsCell);
        
        // Add to top of table
        $tableBody.prepend($dateRow);
        
        // Initialize datepicker
        try {
            if (typeof $.fn.datepicker === 'function') {
                $dateInput.datepicker({
                    dateFormat: 'yy-mm-dd',
                    changeMonth: true,
                    changeYear: true
                }).datepicker('show');
            } else {
                console.warn('jQuery UI Datepicker not found');
                $dateInput.attr('type', 'date'); // Fallback to HTML5 date input
            }
        } catch (e) {
            console.error('Error initializing datepicker:', e);
        }
        
        // Initialize select dropdowns
        setTimeout(function() {
            try {
                initializeSelectDropdowns($eventSelect);
                initializeSelectDropdowns($timeSelect);
            } catch (e) {
                console.error('Error initializing select dropdowns:', e);
            }
        }, 100);
    });
    
    /**
     * Cancel add date button handler
     */
    $(document).on('click', '.brcc-cancel-add-date', function() {
        $(this).closest('tr').remove();
        
        // Check if table is now empty
        var $tableBody = $(this).closest('tbody');
        if ($tableBody.find('tr').length === 0) {
            $tableBody.append('<tr><td colspan="4">No date mappings found. Use the "Add New Date/Time" button below to create mappings.</td></tr>');
        }
    });
    
    /**
     * Confirm add date button handler
     */
    $(document).on('click', '.brcc-confirm-add-date', function() {
        var $row = $(this).closest('tr');
        var $dateInput = $row.find('.brcc-new-datepicker');
        var dateValue = $dateInput.val();
        var timeValue = $row.find('.brcc-new-time').val() || '';
        var eventValue = $row.find('.brcc-date-event').val() || '';
        var ticketValue = $row.find('.brcc-manual-event-id').val() || '';
        var squareValue = $row.find('.brcc-square-id').val() || '';
        
        // Validate date
        if (!dateValue) {
            alert('Please select a date');
            $dateInput.focus();
            return;
        }
        
        // Validate inputs - at least one ID should be filled
        if (!eventValue && !ticketValue && !squareValue) {
            alert('Please select an Event ID or enter a Ticket Class ID or Square ID');
            return;
        }
        
        // Format date for display
        var displayDate, displayTime = '';
        try {
            // Use local date format
            var dateParts = dateValue.split('-');
            var dateObj = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
            displayDate = dateObj.toLocaleDateString();
            
            if (timeValue) {
                var timeParts = timeValue.split(':');
                var timeObj = new Date();
                timeObj.setHours(timeParts[0], timeParts[1], 0);
                displayTime = timeObj.toLocaleTimeString([], {hour: 'numeric', minute:'2-digit'});
            }
        } catch(e) {
            console.error('Date formatting error:', e);
            displayDate = dateValue;
            displayTime = timeValue;
        }
        
        // Create data attributes
        var timeAttr = timeValue ? ' data-time="' + timeValue + '"' : '';
        
        // Create a regular mapping row
        var $newRow = $('<tr data-date="' + dateValue + '"' + timeAttr + '></tr>');
        $newRow.append('<td>' + displayDate + (displayTime ? ' at ' + displayTime : '') + '</td>');
        
        // --- Eventbrite Cell ---
        var $eventbriteCell = $('<td></td>');
        var $clonedSelect = $row.find('.brcc-date-event').clone();
        var $clonedTicketInput = $row.find('.brcc-manual-event-id').clone();
        $eventbriteCell.append($clonedSelect);
        $eventbriteCell.append($clonedTicketInput);
        $newRow.append($eventbriteCell);
        
        // --- Square Cell ---
        var $squareCell = $('<td></td>');
        var $clonedSquareInput = $row.find('.brcc-square-id').clone();
        $squareCell.append($clonedSquareInput);
        $newRow.append($squareCell);
        
        // --- Actions Cell ---
        var $actionsCell = $('<td></td>');
        var $testBtn = $('<button type="button" class="button brcc-test-date-mapping" data-date="' + dateValue + '">Test</button>');
        var $removeBtn = $('<button type="button" class="button brcc-remove-date" data-date="' + dateValue + '" style="margin-left: 5px;">Remove</button>');
        $actionsCell.append($testBtn).append($removeBtn);
        
        // Add placeholder for test results
        var $resultArea = $('<div class="brcc-date-test-result" style="margin-top: 5px;"></div>');
        $actionsCell.append($resultArea);
        
        $newRow.append($actionsCell);
        
        // Replace the add row with the new row
        $row.after($newRow);
        $row.remove();
        
        // Initialize select dropdown for the newly added row
        setTimeout(function() {
            try {
                initializeSelectDropdowns($clonedSelect);
            } catch (e) {
                console.error('Error initializing select after adding:', e);
            }
        }, 100);
        
        // Indicate changes need to be saved
        var $content = $newRow.closest('.brcc-dates-content');
        showNotification($content.find('.brcc-save-status'), 'New date added. Click "Save All Changes" to save.', 'info', 5000);
    });
    
    /**
     * Save all dates button handler
     */
    $(document).on('click', '.brcc-save-dates', function() {
        var $button = $(this);
        var productId = $button.data('product-id');
        var $content = $button.closest('.brcc-dates-content');
        var $tbody = $content.find('tbody');
        var $statusArea = $content.find('.brcc-save-status');
        
        // Clear previous messages
        $statusArea.empty();
        
        // Disable button and show processing state
        $button.prop('disabled', true).text(brcc_admin.saving || 'Saving...');
        var $spinner = $('<span class="spinner is-active" style="float: none; margin-left: 5px; vertical-align: middle;"></span>');
        $button.after($spinner);
        
        // Collect mappings
        var mappings = [];
        $tbody.find('tr[data-date]').each(function() {
            var $row = $(this);
            var date = $row.data('date');
            var time = $row.data('time') || null;
            var eventId = $row.find('.brcc-date-event').val() || '';

            // Get the value from the manual Eventbrite Ticket Class ID input field
            var manualTicketId = $row.find('input.brcc-manual-event-id').val() || '';
            var squareId = $row.find('.brcc-square-id').val() || '';
            
            // Only include the mapping if at least one ID is provided
            if (date && (eventId || manualTicketId || squareId)) {
                console.log('Collecting Ticket Class ID for date ' + date + ':', manualTicketId); // Log for troubleshooting
                
                var mappingData = {
                    date: date,
                    eventbrite_event_id: eventId,
                    // Send with ALL possible key names for maximum compatibility
                    manual_eventbrite_id: manualTicketId,
                    ticket_class_id: manualTicketId,
                    eventbrite_id: manualTicketId,
                    square_id: squareId
                };
                
                // Include time only if it exists
                if (time) {
                    mappingData.time = time;
                }
                
                mappings.push(mappingData);
            }
        });
        
        console.log('Saving mappings:', mappings);
        
        // AJAX request to save mappings
        $.ajax({
            url: typeof brcc_admin !== 'undefined' ? brcc_admin.ajax_url : ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'brcc_save_product_date_mappings',
                nonce: getCorrectNonce(),
                product_id: productId,
                mappings: mappings
            },
            success: function(response) {
                console.log('Save response:', response);
                
                if (response.success) {
                    showNotification(
                        $statusArea, 
                        response.data.message || 'Date mappings saved successfully.',
                        'success',
                        5000
                    );
                    
                    // Update the "Manage Dates" button to show there are mappings
                    var mappingsCount = mappings.length;
                    var $manageBtn = $('.brcc-manage-dates[data-product-id="' + productId + '"]');
                    if (mappingsCount > 0) {
                        $manageBtn.html('Manage Dates <span class="dashicons dashicons-calendar-alt" style="vertical-align: text-bottom;"></span>');
                    }
                } else {
                    showNotification(
                        $statusArea,
                        response.data.message || 'Error saving date mappings.',
                        'error',
                        0
                    );
                }
            },
            error: function(xhr, status, error) {
                console.error('Save error:', status, error, xhr.responseText);
                showNotification(
                    $statusArea,
                    'Error saving date mappings. Check console for details.',
                    'error',
                    0
                );
            },
            complete: function() {
                // Re-enable button and remove spinner
                $button.prop('disabled', false).text('Save All Changes');
                $spinner.remove();
            }
        });
    });
   
    /**
     * Remove date button handler
     */
    $(document).on('click', '.brcc-remove-date', function() {
        var $row = $(this).closest('tr');
        var date = $row.data('date');
        var time = $row.data('time');
        var dateDisplay = date + (time ? ' ' + time : '');
        
        if (confirm('Remove mapping for ' + dateDisplay + '?')) {
            $row.fadeOut(300, function() {
                var $tbody = $row.closest('tbody');
                $row.remove();
                
                // Add "no mappings" message if table is now empty
                if ($tbody.find('tr').length === 0) {
                    $tbody.append('<tr><td colspan="4">No date mappings found. Use the "Add New Date/Time" button below to create mappings.</td></tr>');
                }
                
                // Indicate changes need to be saved
                var $content = $tbody.closest('.brcc-dates-content');
                showNotification($content.find('.brcc-save-status'), 'Date removed. Click "Save All Changes" to save.', 'warning', 5000);
            });
        }
    });
   
    /**
     * Test mapping button handler
     */
    $(document).on('click', '.brcc-test-date-mapping', function() {
        var $button = $(this);
        var $row = $button.closest('tr');
        var date = $row.data('date');
        var time = $row.data('time') || '';
        var eventId = $row.find('.brcc-date-event').val() || '';
        var ticketId = $row.find('.brcc-manual-event-id').val() || '';
        var $resultArea = $row.find('.brcc-date-test-result');
        
        // Clear previous results
        $resultArea.empty().hide();
        
        // Check if we have necessary data
        if (!ticketId) {
            $resultArea.html('<div class="notice notice-error inline"><p>Please enter a Ticket Class ID to test.</p></div>').show();
            return;
        }
        
        // Show testing state
        $button.prop('disabled', true).text(brcc_admin.testing || 'Testing...');
        
        // Get the product ID from the save button
        var productId = $button.closest('.brcc-dates-content').find('.brcc-save-dates').data('product-id');
        
        // AJAX request to test mapping
        $.ajax({
            url: typeof brcc_admin !== 'undefined' ? brcc_admin.ajax_url : ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'brcc_test_product_date_mapping',
                nonce: getCorrectNonce(),
                product_id: productId,
                date: date,
                time: time,
                eventbrite_event_id: eventId, // Event ID is correct
                manual_eventbrite_id: ticketId // Send ticket ID using the primary key name for consistency
            },
            success: function(response) {
                console.log('Test response:', response);
                
                // Determine the message and class based on the response
                var messageClass = response.success ? 'success' : 'error';
                var messageText = response.data ? response.data.message : (response.success ? 'Test successful!' : 'Test failed.');
                
                if (response.data && response.data.status === 'warning') {
                    messageClass = 'warning';
                }
                
                $resultArea.html('<div class="notice notice-' + messageClass + ' inline"><p>' + messageText + '</p></div>').show();
                
                // Auto-hide after a delay if successful
                if (response.success) {
                    setTimeout(function() {
                        $resultArea.fadeOut();
                    }, 5000);
                }
            },
            error: function(xhr, status, error) {
                console.error('Test error:', status, error, xhr.responseText);
                
                var errorMsg = 'Error testing mapping. Check console for details.';
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response && response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                } catch (e) {
                    // Use default error message
                }
                
                $resultArea.html('<div class="notice notice-error inline"><p>' + errorMsg + '</p></div>').show();
            },
            complete: function() {
                // Reset button state
                $button.prop('disabled', false).text(brcc_admin.test || 'Test');
            }
        });
    });
   
    // --- Initialize UI Elements on Page Load ---
   
    // Run initialization
    initExistingDatepickers();

    // Initialize the select2/selectWoo fields on page load for consistency
    setTimeout(function() {
        // Initialize event dropdowns in the main product mapping table
        $('.brcc-eventbrite-event-id-select').each(function() {
            initializeSelectDropdowns($(this));
        });
    }, 500);
   
    console.log('date-mappings.js: Initialization complete');
    window.brccDateMappingsInitialized = true; // Set flag after successful initialization
}); // End of jQuery(document).ready()