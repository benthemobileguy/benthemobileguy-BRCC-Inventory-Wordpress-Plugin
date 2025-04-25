//console.log('BRCC Admin JS file loaded!'); // DEBUG - Check if file is loading at all
(function($) { // Start jQuery no-conflict wrapper
    /**
     * BRCC Inventory Tracker Admin JavaScript
     */

// Make chart functions globally accessible

(function ($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        // Initialize datepickers for Daily Sales page
        if ($('#brcc-start-date').length && $('#brcc-end-date').length) {
            $('#brcc-start-date, #brcc-end-date').datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true,
                maxDate: '0'
            });
        }

        // Filter date range
        $('#brcc-filter-date-range').on('click', function (e) {
            e.preventDefault();
            var startDate = $('#brcc-start-date').val();
            var endDate = $('#brcc-end-date').val();

            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }

            if (new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be after end date');
                return;
            }

            window.location.href = brcc_admin.admin_url + '?page=brcc-daily-sales&start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate);
        });

        // Regenerate API key
        $('#regenerate-api-key').on('click', function (e) {
            e.preventDefault();

            if (confirm(brcc_admin.regenerate_key_confirm)) {
                $.ajax({
                    url: brcc_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'brcc_regenerate_api_key',
                        nonce: brcc_admin.nonce
                    },
                    success: function (response) {
                        if (response.success) {
                            $('#api_key').val(response.data.api_key);
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function () {
                        alert(brcc_admin.ajax_error);
                    }
                });
            }
        });

        // Test Eventbrite Connection
        $('#test-eventbrite-connection').on('click', function(e) {
            e.preventDefault();
            var $button = $(this);
            var $statusSpan = $('#eventbrite-test-status');

            $button.prop('disabled', true).text(brcc_admin.testing); // Use localized 'Testing...'
            $statusSpan.removeClass('success error').text('').show();

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_test_eventbrite_connection',
                    nonce: brcc_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $statusSpan.addClass('success').text(response.data.message);
                    } else {
                        $statusSpan.addClass('error').text(response.data.message || brcc_admin.ajax_error);
                    }
                },
                error: function() {
                    $statusSpan.addClass('error').text(brcc_admin.ajax_error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(brcc_admin.test); // Use localized 'Test'
                    // Optional: Hide status message after a delay
                    setTimeout(function() { $statusSpan.fadeOut(); }, 8000);
                }
            });
        });

        // Sync now button
        $('#brcc-sync-now').on('click', function (e) {
            e.preventDefault();
            var $button = $(this);
            var originalText = $button.text();

            $button.text(brcc_admin.syncing).prop('disabled', true);

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_sync_inventory_now',
                    nonce: brcc_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || brcc_admin.ajax_error);
                        $button.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert(brcc_admin.ajax_error);
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });

        // Save product mappings - REMOVED from admin.js, now inline in PHP
        // $('#brcc-save-mappings').on('click', function (e) { ... });

        // Test mapping
        $('.brcc-test-mapping').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            var productId = $button.data('product-id');

            $button.prop('disabled', true).text(brcc_admin.testing);

            // Get mapping values from inputs
            // Get the Ticket ID from the second select dropdown
            var eventbriteId = $('select[name="brcc_product_mappings[' + productId + '][eventbrite_id]"]').val();

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_test_product_mapping',
                    nonce: brcc_admin.nonce,
                    product_id: productId,
                    eventbrite_id: eventbriteId // Send the Ticket ID
                },
                success: function (response) {
                    if (response.success) {
                        // Display message as text for security
                        $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-success').append($('<p>').text(response.data.message))).show(); // Use .text()
                    } else {
                        $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(response.data.message))).show(); // Use .text() for error message
                    }

                    $button.prop('disabled', false).text(brcc_admin.test);

                    // Hide message after 5 seconds
                    setTimeout(function () {
                        $('#brcc-mapping-result').fadeOut();
                    }, 5000);
                },
                error: function () {
                    $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(brcc_admin.ajax_error))).show(); // Use .text()
                    $button.prop('disabled', false).text(brcc_admin.test);
                }
            });
        });

        // Removed chart update button handler - chart now updates based on main view controls

        // Filter logs - Use delegated event binding
        $(document).on('click', '#brcc-filter-logs', function() {
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

        // Reset Today's Sales button - Use delegated event binding
        $(document).on('click', '#brcc-reset-todays-sales', function(e) {
            e.preventDefault();
            if (confirm('Are you sure you want to reset all sales data recorded for today? This cannot be undone.')) {
                var $button = $(this);
                $button.prop('disabled', true).text('Resetting...');

                $.ajax({
                    url: brcc_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'brcc_reset_todays_sales',
                        nonce: brcc_admin.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            window.location.reload(); // Reload to see the updated data
                        } else {
                            alert(response.data.message || 'Error resetting data.');
                            $button.prop('disabled', false).text('Reset Today\'s Sales');
                        }
                    },
                    error: function() {
                        alert(brcc_admin.ajax_error || 'AJAX error.');
                        $button.prop('disabled', false).text('Reset Today\'s Sales');
                    }
                });
            }
        });

        // Initialize date pickers for Daily Sales page
        if ($('#brcc-start-date').length && $('#brcc-end-date').length) {
            $('#brcc-start-date, #brcc-end-date').datepicker({
                dateFormat: 'yy-mm-dd', // Standard format
                changeMonth: true,
                changeYear: true
            });
        }

        // Initialize Select2 for daily sales page
        function initializeDailySalesSelect2() {
            // Clean up any existing Select2 instances
            $('.brcc-select2').each(function() {
                if ($(this).data('select2')) {
                    $(this).select2('destroy');
                }
            });

            // Initialize Select2 with proper dropdown parent
            $('.brcc-select2').select2({
                dropdownParent: $(document.body),
                width: '100%',
                placeholder: 'Select an option',
                allowClear: true
            });
        }

        // Call initialization on page load if we're on the daily sales page
        if ($('body').hasClass('brcc-daily-sales-page')) {
            initializeDailySalesSelect2();
        }

        // Re-initialize Select2 after AJAX content updates
        $(document).on('brcc_content_updated', function() {
            if ($('body').hasClass('brcc-daily-sales-page')) {
                initializeDailySalesSelect2();
            }
        });

    }); // End $(function () { ... });

// Initialize Select2 with search functionality
function initializeSelect2(selector) {
    // Add existence check
    if (!document.querySelector(selector)) {
        console.debug('Select2 initialization skipped - element not found:', selector);
        return;
    }
    
    var $select = $(selector);

    // Only proceed if the element exists
    if (!$select.length) {
        return; // Silently exit if element not found
    }

    // Detect SelectWoo vs Select2
    var isSelectWoo = typeof $.fn.selectWoo === 'function';
    var selectFuncName = isSelectWoo ? 'selectWoo' : 'select2';
    var selectPluginFunc = $.fn[selectFuncName];

    if (typeof selectPluginFunc === 'function') {
        try {
            // Destroy existing instance cleanly if possible
            if ($select.data('select2')) $select.select2('destroy');
            if ($select.data('selectWoo')) $select.selectWoo('destroy');

            // Initialize with simplified options compatible with both
            $select[selectFuncName]({
                width: '100%',
                minimumResultsForSearch: 0,
                dropdownParent: $select.parent(), // Ensure dropdown is positioned correctly
                placeholder: 'Select an option...' // Add a default placeholder
            });
        } catch (error) {
            console.debug('Select2 initialization skipped:', error);
        }
    }
}

// Initialize Select2 for both event and ticket dropdowns on page load
$(document).ready(function() {
    // Calculate dynamic delay based on page load time
    const initDelay = Math.max(100, performance.now() - performance.timing.domContentLoadedEventStart);
    
    setTimeout(function() {
        try {
            // Only initialize Select2 if we're on the product mapping page
            if ($('#brcc-product-mapping-table').length) {
                // Initialize Event dropdowns first
                const $eventSelects = $('.brcc-eventbrite-event-id-select');
                if ($eventSelects.length) {
                    $eventSelects.each(function() {
                        try {
                            initializeSelect2(this);
                        } catch (e) {
                            console.warn('Failed to initialize Select2 for event select:', e);
                        }
                    });
                } else {
                    console.debug('No .brcc-eventbrite-event-id-select elements found on page');
                }

                // Initialize existing Ticket dropdowns
                const $ticketSelects = $('.brcc-eventbrite-ticket-id-select');
                if ($ticketSelects.length) {
                    $ticketSelects.each(function() {
                        try {
                            initializeSelect2(this);
                        } catch (e) {
                            console.warn('Failed to initialize Select2 for ticket select:', e);
                        }
                    });
                }
            }

            // Initialize Select2 for daily sales page if needed
            if ($('body').hasClass('brcc-daily-sales-page')) {
                const $dailySalesSelects = $('.brcc-select2');
                if ($dailySalesSelects.length) {
                    $dailySalesSelects.each(function() {
                        try {
                            initializeSelect2(this);
                        } catch (e) {
                            console.warn('Failed to initialize Select2 for daily sales select:', e);
                        }
                    });
                }
            }
        } catch (e) {
            console.error('Error during Select2 initialization:', e);
        }
    }, initDelay);
});

// Re-initialize Select2 after dynamic content updates with enhanced error handling
$(document).on('brcc_content_updated', function() {
    const $selects = $('.brcc-eventbrite-event-id-select, .brcc-eventbrite-ticket-id-select, .brcc-select2');
    if (!$selects.length) {
        console.debug('No select elements found for initialization after content update');
        return;
    }

    $selects.each(function() {
        try {
            initializeSelect2(this);
        } catch (e) {
            console.warn('Failed to initialize Select2 after content update:', e);
        }
    });
});

// Enhanced Save Mappings button handler with multiple approaches for reliability
// Direct binding for immediate elements
$('#brcc-save-mappings').on('click', function(e) {
    handleSaveMappingsClick(e, $(this));
});

// Delegated binding for dynamically added elements
$(document).on('click', '#brcc-save-mappings', function(e) {
    handleSaveMappingsClick(e, $(this));
});

// Shared handler function to prevent code duplication
function handleSaveMappingsClick(e, $button) {
    // Prevent default action and stop event propagation
    e.preventDefault();
    e.stopPropagation();
    
    // Prevent duplicate execution if already processing
    if ($button.data('processing')) {
        return;
    }
    $button.data('processing', true);
    
    // Show processing state
    $button.prop('disabled', true).text(brcc_admin.saving || 'Saving...');

    var mappings = {};

    // Collect all mapping inputs
    $('#brcc-product-mapping-table input[name^="brcc_product_mappings"], #brcc-product-mapping-table select[name^="brcc_product_mappings"]').each(function() {
        var $input = $(this);
        var name = $input.attr('name');
        var matches = name.match(/brcc_product_mappings\[(\d+)\]\[([^\]]+)\]/);

        if (matches && matches.length === 3) {
            var productId = matches[1];
            var field = matches[2];

            if (!mappings[productId]) {
                mappings[productId] = {};
            }

            mappings[productId][field] = $input.val();
        }
    });

    $.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        data: {
            action: 'brcc_save_product_mappings',
            nonce: brcc_admin.nonce,
            mappings: mappings
        },
        success: function(response) {
            if (response.success) {
                $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-success').append($('<p>').text(response.data.message))).show();
            } else {
                $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(response.data.message || 'Error saving mappings'))).show();
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX error:', status, error);
            $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(brcc_admin.ajax_error || 'Error saving mappings'))).show();
        },
        complete: function() {
            $button.prop('disabled', false).text(brcc_admin.save_mappings || 'Save Mappings');
            
            // Reset processing flag after a short delay to prevent rapid clicking
            setTimeout(function() {
                $button.data('processing', false);
            }, 1000);
            
            // Hide message after 5 seconds
            setTimeout(function() {
                $('#brcc-mapping-result').fadeOut();
            }, 5000);
        }
    });
}

    // Suggest Eventbrite ID
    // --- NEW Eventbrite Dropdown Logic ---

    /**
     * Function to load Eventbrite tickets for a selected event dropdown.
     * @param {jQuery} $eventSelect The jQuery object for the event select dropdown.
     */
    function loadEventbriteTickets($eventSelect) {
        var eventId = $eventSelect.val();
        var productId = $eventSelect.data('product-id');
        var $ticketSelect = $('#brcc_eventbrite_ticket_id_select_' + productId);
        var $spinner = $eventSelect.closest('.brcc-mapping-input-group').find('.spinner');
        var previouslySelectedTicket = $ticketSelect.data('selected'); // Get saved ticket ID

        // Destroy existing Select2/SelectWoo instance before modifying options
        if ($ticketSelect.data('select2')) $ticketSelect.select2('destroy');
        if ($ticketSelect.data('selectWoo')) $ticketSelect.selectWoo('destroy');

        // Clear and disable ticket dropdown
        $ticketSelect.html('<option value="">' + (brcc_admin.loading || 'Loading...') + '</option>').prop('disabled', true);
        $spinner.addClass('is-active').css('visibility', 'visible');

        if (!eventId) {
            $ticketSelect.html('<option value="">' + (brcc_admin.select_event_prompt || 'Select Event First...') + '</option>');
            $spinner.removeClass('is-active').css('visibility', 'hidden');
            // Re-initialize Select2 on the empty/disabled dropdown
            initializeSelect2('#brcc_eventbrite_ticket_id_select_' + productId);
            return; // Exit if no event is selected
        }

        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_get_eventbrite_tickets_for_event',
                nonce: brcc_admin.nonce,
                event_id: eventId
            },
            success: function(response) {
                $ticketSelect.empty(); // Clear loading message
                if (response.success) {
                    $ticketSelect.append($('<option>', { value: '', text: (brcc_admin.select_ticket_prompt || 'Select Ticket...') }));
                    if ($.isEmptyObject(response.data)) {
                        $ticketSelect.append($('<option>', { value: '', text: (brcc_admin.no_tickets_found || 'No tickets found'), disabled: true }));
                    } else {
                        $.each(response.data, function(ticketId, ticketLabel) {
                            $ticketSelect.append($('<option>', {
                                value: ticketId,
                                text: ticketLabel
                            }));
                        });
                        // Try to re-select the previously saved ticket ID
                        if (previouslySelectedTicket) {
                            $ticketSelect.val(previouslySelectedTicket);
                        }
                    }
                    // Always enable the dropdown if the AJAX call was successful
                    $ticketSelect.prop('disabled', false);
                    //console.log('Ticket dropdown enabled for product ID:', productId);
                } else {
                    // Handle error - maybe add an error message option?
                    $ticketSelect.append($('<option>', { value: '', text: (brcc_admin.error_loading_tickets || 'Error loading tickets') }));
                    console.error("Error fetching tickets:", response.data);
                }
                // Initialize Select2/SelectWoo on the newly populated/enabled dropdown
                // initializeSelect2('#brcc_eventbrite_ticket_id_select_' + productId); // DEBUG: Temporarily disable initialization within AJAX callback
                $ticketSelect.css('display', 'block').css('visibility', 'visible'); // Ensure standard select is visible
            },
            error: function(xhr, status, error) {
                $ticketSelect.empty().append($('<option>', { value: '', text: (brcc_admin.ajax_error || 'AJAX Error') }));
                console.error("AJAX error fetching tickets:", status, error);
                // Initialize Select2/SelectWoo on the error state dropdown
                // initializeSelect2('#brcc_eventbrite_ticket_id_select_' + productId); // DEBUG: Temporarily disable initialization within AJAX callback
                $ticketSelect.css('display', 'block').css('visibility', 'visible'); // Ensure standard select is visible
            },
            complete: function() {
                $spinner.removeClass('is-active').css('visibility', 'hidden');
                // Trigger change event in case Select2 needs to update its display based on the selected value
                $ticketSelect.trigger('change.select2');
            }
        });
    }
    // Event listener and initial load logic are now handled within the $(document).ready() block above.

    // Clear Eventbrite Cache Button (from Settings)
    $('#clear-eventbrite-cache').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $statusSpan = $('#eventbrite-cache-status'); // Use the correct status span ID

        // Use localized strings if available, otherwise default text
        var clearingText = brcc_admin.clearing_cache || 'Clearing Cache...';
        var defaultText = brcc_admin.clear_cache || 'Clear Event Cache';

        $button.prop('disabled', true).text(clearingText);
        $statusSpan.text('').removeClass('success error').show(); // Clear previous status

        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_clear_eventbrite_cache', // Correct AJAX action
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $statusSpan.addClass('success').text(response.data || 'Cache cleared successfully.');
                    // Suggest reloading the page to see changes in dropdowns elsewhere
                    alert('Eventbrite cache cleared. Please reload the page if you need to see the updated event list in dropdowns.');
                }
                // NOTE: Original code did not handle success:false case here, nor AJAX errors.
            }
        });
    });
}); // End $(document).ready(function() { ... }); for Select2 init

// REMOVED: Fetch Eventbrite Events by Class ID JavaScript block
/**
 * Test Square Connection
 */
$('#brcc-test-square-connection').on('click', function(e) {
    e.preventDefault();

    var $button = $(this);
    $button.prop('disabled', true).text('Testing connection...');

    $.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        data: {
            action: 'brcc_test_square_connection',
            nonce: brcc_admin.nonce
        },
        success: function(response) {
            $button.prop('disabled', false).text('Test Square Connection');

            if (response.success) {
                $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-success').append($('<p>').text(response.data.message))).show(); // Use .text()
            } else {
                $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(response.data.message))).show(); // Use .text()
            }

            // Hide message after 5 seconds
            setTimeout(function() {
                $('#brcc-mapping-result').fadeOut();
            }, 5000);
        },
        error: function() {
            $button.prop('disabled', false).text('Test Square Connection');
            $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(brcc_admin.ajax_error))).show(); // Use .text()
        }
    });
});

/**
 * Fetch Square Catalog
 */
 // ... (existing Square catalog code) ...

/**
 * Attendee List Page Logic
 */
jQuery(document).ready(function($) {
    // 1. Override the existing event change handler completely
    $(document).off('change', '.brcc-eventbrite-event-id-select');

    // 2. Add a new handler with fixed behavior
    $(document).on('change', '.brcc-eventbrite-event-id-select', function() {
        var $eventSelect = $(this);
        var eventId = $eventSelect.val();
        var productId = $eventSelect.data('product-id');
        var $ticketSelect = $('#brcc_eventbrite_ticket_id_select_' + productId);
        var $spinner = $eventSelect.closest('.brcc-mapping-input-group').find('.spinner');

        //console.log('Event changed - Product ID:', productId, 'Event ID:', eventId);

        // Clear and disable the ticket dropdown while loading
        $ticketSelect.empty().prop('disabled', true);
        $ticketSelect.append($('<option>', {value: '', text: 'Loading tickets...'}));
        $spinner.addClass('is-active').css('visibility', 'visible');

        if (!eventId) {
            $ticketSelect.empty().append($('<option>', {value: '', text: 'Select an event first'}));
            $spinner.removeClass('is-active').css('visibility', 'hidden');
            return;
        }

        // Make the AJAX request to get tickets
        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_get_eventbrite_tickets_for_event',
                nonce: brcc_admin.nonce,
                event_id: eventId
            },
            success: function(response) {
                //console.log('Tickets loaded for product ID:', productId, response);

                // Clear the dropdown
                $ticketSelect.empty();

                if (response.success && typeof response.data === 'object') {
                    // Add a default "select" option
                    $ticketSelect.append($('<option>', {value: '', text: 'Select a ticket...'}));

                    // Add each ticket as an option
                    var ticketCount = 0;
                    $.each(response.data, function(ticketId, ticketName) {
                        $ticketSelect.append($('<option>', {
                            value: ticketId,
                            text: ticketName
                        }));
                        ticketCount++;
                    });

                    //console.log('Added', ticketCount, 'ticket options');

                    // Enable the dropdown if we have tickets
                    $ticketSelect.prop('disabled', false);
                } else {
                    // No tickets or error
                    $ticketSelect.append($('<option>', {
                        value: '',
                        text: 'No tickets available'
                    }));
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading tickets:', error);
                $ticketSelect.empty().append($('<option>', {
                    value: '',
                    text: 'Error loading tickets'
                }));
            },
            complete: function() {
                // Hide the spinner
                $spinner.removeClass('is-active').css('visibility', 'hidden');

                // CRITICAL FIX: Force the dropdown to be enabled again after a short delay
                // This addresses race conditions and other potential issues
                setTimeout(function() {
                    if ($ticketSelect.find('option').length > 1) {
                        $ticketSelect.prop('disabled', false);
                        //console.log('Ticket dropdown force-enabled with', $ticketSelect.find('option').length, 'options');
                    }
                }, 250);
            }
        });
    });

    // 3. Fix for Select2 initialization if needed
    function initializeOrRefreshSelect2() {
        if ($.fn.select2) {
            //console.log('Initializing Select2 for dropdowns');

            // Initialize event dropdowns
            $('.brcc-eventbrite-event-id-select').each(function() {
                var $select = $(this);
                if ($select.data('select2')) {
                    $select.select2('destroy');
                }
                $select.select2({
                    width: '100%',
                    placeholder: 'Select or search for an event'
                });
            });

            // Initialize ticket dropdowns
            $('.brcc-eventbrite-ticket-id-select').each(function() {
                var $select = $(this);
                if (!$select.prop('disabled') && $select.find('option').length > 1) {
                    if ($select.data('select2')) {
                        $select.select2('destroy');
                    }
                    $select.select2({
                        width: '100%',
                        placeholder: 'Select a ticket'
                    });
                }
            });
        }
    }

    // 4. Initialize Select2 on page load
    initializeOrRefreshSelect2();

    // 5. Initialize ticket dropdowns for any already-selected events
    $('.brcc-eventbrite-event-id-select').each(function() {
        if ($(this).val()) {
            $(this).trigger('change');
        }
    });
});

$('#brcc-fetch-square-catalog').on('click', function(e) {
    e.preventDefault();

    var $button = $(this);
    $button.prop('disabled', true).text('Fetching catalog...');

    $.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        data: {
            action: 'brcc_get_square_catalog',
            nonce: brcc_admin.nonce
        },
        success: function(response) {
            $button.prop('disabled', false).text('View Square Catalog');

            var $catalogContainer = $('#brcc-square-catalog-items'); // Target container
            $catalogContainer.empty(); // Clear previous results

            if (response.success) {
                var catalog = response.data.catalog;

                if (catalog && catalog.length > 0) {
                    // Create table structure safely
                    var $table = $('<table>').addClass('wp-list-table widefat fixed striped');
                    var $thead = $('<thead>').appendTo($table);
                    var $tbody = $('<tbody>').appendTo($table);
                    var $trHead = $('<tr>').appendTo($thead);

                    // Add headers safely
                    $('<th>').text('Item Name').appendTo($trHead);
                    $('<th>').text('Item ID').appendTo($trHead);
                    $('<th>').text('Description').appendTo($trHead);
                    $('<th>').text('Variations').appendTo($trHead);

                    // Populate table body safely
                    $.each(catalog, function(i, item) {
                        var $tr = $('<tr>').appendTo($tbody);
                        $('<td>').text(item.name || '').appendTo($tr); // Use .text()
                        $('<td>').append($('<code>').text(item.id || '')).appendTo($tr); // Use .text() within code tag
                        $('<td>').text(item.description || '').appendTo($tr); // Use .text()

                        var $variationsTd = $('<td>').appendTo($tr);
                        if (item.variations && item.variations.length > 0) {
                            var $ul = $('<ul>').css({margin: 0, paddingLeft: '20px'}).appendTo($variationsTd);
                            $.each(item.variations, function(j, variation) {
                                var $li = $('<li>').appendTo($ul);
                                $li.append(document.createTextNode((variation.name || '') + ' - '));
                                $li.append($('<code>').text(variation.id || ''));
                                $li.append(document.createTextNode(' ($' + (variation.price || '0.00') + ')'));
                            });
                        } else {
                            $variationsTd.text('No variations'); // Use .text()
                        }
                    });

                    $catalogContainer.append($table); // Append the generated table
                } else {
                    $catalogContainer.append($('<p>').text('No catalog items found.')); // Use .text()
                }

                $('#brcc-square-catalog-container').show();
            } else {
                $('#brcc-mapping-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>').show();
            }
        },
        error: function() {
            $button.prop('disabled', false).text('View Square Catalog');
            $('#brcc-mapping-result').html('<div class="notice notice-error"><p>' + brcc_admin.ajax_error + '</p></div>').show();
        }
    });
});

/**
 * Test Square Mapping
 */
$('.brcc-test-square-mapping').on('click', function(e) {
    e.preventDefault();

    var $button = $(this);
    var productId = $button.data('product-id');

    $button.prop('disabled', true).text(brcc_admin.testing);

    // Get mapping values from inputs
    var squareId = $('input[name="brcc_product_mappings[' + productId + '][square_id]"]').val();

    $.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        data: {
            action: 'brcc_test_square_mapping',
            nonce: brcc_admin.nonce,
            product_id: productId,
            square_id: squareId
        },
        success: function(response) {
            if (response.success) {
                $('#brcc-mapping-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>').show();
            } else {
                $('#brcc-mapping-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>').show();
            }

            $button.prop('disabled', false).text('Test Square');

            // Hide message after 5 seconds
            setTimeout(function() {
                $('#brcc-mapping-result').fadeOut();
            }, 5000);
        },
        error: function() {
            $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(brcc_admin.ajax_error))).show(); // Use .text()
            $button.prop('disabled', false).text('Test Square');
        }
    });
});

    // --- Import History Page ---
    if ($('#brcc-start-import').length) {
        var $startButton = $('#brcc-start-import');
        var $wcCheckbox = $('#brcc-import-source-wc');
        var $sqCheckbox = $('#brcc-import-source-sq');
        var $ebCheckbox = $('#brcc-import-source-eb'); // New Eventbrite checkbox

        // Check initial configuration status (assuming PHP added data attributes or classes)
        var isSqConfigured = !$sqCheckbox.siblings('span').length; // Check if warning span exists
        var isEbConfigured = !$ebCheckbox.siblings('span').length; // Check if warning span exists

        // Initialize date pickers for import range
        $('#brcc-import-start-date, #brcc-import-end-date').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            maxDate: 0 // Allow selecting past dates
        });
        var importLogContainer = $('#brcc-import-log');
        var importProgressBar = $('#brcc-import-progress-bar');
        var importStatusMessage = $('#brcc-import-status-message');
        var importCompleteButton = $('#brcc-import-complete');
        var importInProgress = false;

        // Function to add log messages
        function addImportLog(message, type) {
            var logClass = type === 'error' ? 'color: red;' : (type === 'warning' ? 'color: orange;' : '');
            // Use text() to set content safely, then wrap if needed or apply style directly
            var $logEntry = $('<div>').css('color', type === 'error' ? 'red' : (type === 'warning' ? 'orange' : 'inherit')).text(message);
            importLogContainer.append($logEntry);
            importLogContainer.scrollTop(importLogContainer[0].scrollHeight); // Scroll to bottom
        }

        // Function to process an import batch
        function processImportBatch(state) { // Pass the whole state object
            // data.offset = offset; // Offset is now part of the state object
            state.action = 'brcc_import_batch'; // New AJAX action
            state.nonce = $('input[name="brcc_import_nonce"]').val(); // Get the correct nonce from the hidden field

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: { // Wrap state object in a key
                    state_data: state
                },
                success: function(response) {
                    if (!importInProgress) return; // Stop if cancelled

                    if (response.success) {
                        // Append logs
                        if (response.data.logs && response.data.logs.length > 0) {
                            response.data.logs.forEach(function(log) {
                                addImportLog(log.message, log.type);
                            });
                        }

                        // Update progress
                        var progress = response.data.progress || 0;
                        importProgressBar.val(progress);
                        importStatusMessage.text(response.data.message || 'Processing...'); // Use .text()

                        // Process next batch or complete
                        if (response.data.next_state !== null && response.data.next_state !== undefined) {
                            // Update progress total if provided (might change after first batch)
                            if (response.data.progress_total) {
                                importProgressBar.attr('max', response.data.progress_total);
                            }
                            processImportBatch(response.data.next_state); // Pass the updated state
                        } else {
                            addImportLog('Import completed!', 'success');
                            importStatusMessage.text('Import completed!'); // Use .text()
                            importProgressBar.val(100);
                            importCompleteButton.show();
                            $('#brcc-start-import').prop('disabled', false).text('Start Import');
                            importInProgress = false;
                        }
                    } else {
                        addImportLog('Error: ' + (response.data.message || 'Unknown error during import.'), 'error');
                        importStatusMessage.text('Import failed. Check log for details.'); // Use .text()
                        importCompleteButton.show();
                         $('#brcc-start-import').prop('disabled', false).text('Start Import');
                        importInProgress = false;
                    }
                },
                error: function(xhr, status, error) {
                    if (!importInProgress) return;
                    addImportLog('AJAX Error: ' + status + ' - ' + error, 'error');
                    importStatusMessage.text('Import failed due to network or server error.'); // Use .text()
                    importCompleteButton.show();
                     $('#brcc-start-import').prop('disabled', false).text('Start Import');
                    importInProgress = false;
                }
            });
        }

        // Start Import button click
        $('#brcc-start-import').on('click', function() {
            var $button = $(this);
            var startDate = $('#brcc-import-start-date').val();
            var endDate = $('#brcc-import-end-date').val();
            var sources = $('input[name="brcc_import_sources[]"]:checked').map(function() {
                return $(this).val();
            }).get();

            if (!startDate || !endDate) {
                alert('Please select both a start and end date.');
                return;
            }
            if (sources.length === 0) {
                alert('Please select at least one data source (WooCommerce, Square, or Eventbrite).'); // Updated message
                return;
            }
            // Add check for selecting unconfigured sources
            if (($sqCheckbox.is(':checked') && !isSqConfigured) || ($ebCheckbox.is(':checked') && !isEbConfigured)) {
                 alert('Please configure the API settings for all selected sources before starting the import.');
                 return;
            }
             if (new Date(startDate) > new Date(endDate)) {
                 alert('Start date cannot be after end date.');
                 return;
             }

            if (!confirm('Start importing historical data from ' + startDate + ' to ' + endDate + '? This might take a while.')) {
                return;
            }

            // Prepare UI
            $button.prop('disabled', true).text('Importing...');
            importLogContainer.html(''); // Clear previous logs
            importProgressBar.val(0);
            importStatusMessage.text('Starting import...'); // Use .text()
            importCompleteButton.hide();
            $('#brcc-import-status').show();
            importInProgress = true;
            addImportLog('Starting import for ' + sources.join(', ') + ' from ' + startDate + ' to ' + endDate + '...');

            // Start the first batch - Initial state
            var initialState = {
                start_date: startDate,
                end_date: endDate,
                sources: sources,
                current_source_index: 0,
                wc_offset: 0,
                square_cursor: null,
                eventbrite_page: 1, // Start page for Eventbrite
                total_processed: 0,
                progress_total: 100 // Placeholder total, backend might update this
            };
            processImportBatch(initialState);
        });

        // Import Complete button click
        importCompleteButton.on('click', function() {
            $('#brcc-import-status').hide();
            importInProgress = false; // Allow starting a new import
        });
    }
    // --- End Import History Page ---
    jQuery(document).ready(function($) {
        // Add expand/collapse functionality
        $(document).on('click', '.brcc-manage-dates', function(e) {
            e.preventDefault();

            var $button = $(this);
            var productId = $button.data('product-id');
            var $row = $button.closest('tr');
            var $expandedRow = $('#brcc-dates-row-' + productId);

            // If expanded row exists, toggle it
            if ($expandedRow.length) {
                $expandedRow.toggle();
                $button.text($expandedRow.is(':visible') ? 'Hide Dates' : 'Manage Dates');
                return;
            }

            // Otherwise, create new expanded row
            $button.text('Loading...');

            // Create expanded row with a placeholder
            var colspan = $row.find('td').length;
            var $newRow = $('<tr id="brcc-dates-row-' + productId + '" class="brcc-dates-row"></tr>');
            var $cell = $('<td colspan="' + colspan + '" class="brcc-dates-cell"></td>');
            $cell.append('<div class="brcc-dates-loading"><span class="spinner is-active"></span> Loading date mappings...</div>');
            $newRow.append($cell);
            $row.after($newRow);

            // Load date mappings via AJAX
            // $.ajax({
            //     url: brcc_admin.ajax_url,
            //     type: 'POST',
            //     data: {
            //         action: 'brcc_get_product_dates',
            //         nonce: brcc_admin.nonce,
            //         product_id: productId
            //     },
            //     success: function(response) {
            //         if (response.success) {
            //             renderDateMappings($cell, productId, response.data);
            //             $button.text('Hide Dates');
            //         } else {
            //             $cell.html('<div class="notice notice-error inline"><p>' +
            //                        (response.data.message || 'Error loading date mappings.') + '</p></div>');
            //         $button.text('Manage Dates');
            //     }
            // },
            // error: function() {
            //     $cell.html('<div class="notice notice-error inline"><p>' +
            //               (brcc_admin.ajax_error || 'Error loading date mappings.') + '</p></div>');
            //     $button.text('Manage Dates');
            // }
            // });
        });

        // Function to render date mappings UI
        function renderDateMappings($container, productId, data) {
            var dates = data.dates || [];
            var eventOptions = brcc_admin.eventbrite_events || {};

            // Clear loading indicator
            $container.empty();

            // Get the base event ID (if any) from the main row
            var baseEventId = $('#brcc_eventbrite_event_id_select_' + productId).val() || '';

            // Add heading and controls
            var $header = $('<div class="brcc-dates-header"></div>');
            $header.append('<h3>Date-Specific Mappings for Product ID: ' + productId + '</h3>');
            // Add correct descriptive text
            $header.append('<p class="description">' + 'Select Eventbrite Event and Ticket IDs for specific dates below. If no mapping is found for a specific date, the main Event ID mapping (if set) will be used as a fallback.' + '</p>');

            // Add "Add New Date" button
            var $addButton = $('<button type="button" class="button brcc-add-date-mapping" data-product-id="' +
                             productId + '">Add New Date Mapping</button>');
            $header.append($addButton);

            $container.append($header);

            // Create dates table
            var $table = $('<table class="wp-list-table widefat fixed striped brcc-dates-table"></table>');
            var $thead = $('<thead><tr>' +
                         '<th>Date</th>' +
                         '<th>Inventory</th>' +
                         '<th>Event</th>' +
                         '<th>Ticket</th>' +
                         '<th>Actions</th>' +
                         '</tr></thead>');
            var $tbody = $('<tbody id="brcc-dates-table-body-' + productId + '"></tbody>');

            $table.append($thead).append($tbody);
            $container.append($table);

            if (dates.length === 0) {
                $tbody.append('<tr><td colspan="5">No date-specific mappings found. Click "Add New Date Mapping" to create one.</td></tr>');
            } else {
                // Add each date mapping
                dates.forEach(function(date) {
                    addDateRow($tbody, productId, date, eventOptions, baseEventId);
                });
            }

            // Add save button
            var $footer = $('<div class="brcc-dates-footer"></div>');
            var $saveButton = $('<button type="button" class="button button-primary brcc-save-date-mappings" data-product-id="' +
                              productId + '">Save Date Mappings</button>');
            $footer.append($saveButton);
            $container.append($footer);

            // Initialize any Select2 dropdowns in the date mappings
            if ($.fn.select2) {
                $container.find('select').select2({
                    width: '100%',
                    dropdownParent: $container // This ensures dropdowns appear above other elements
                });
            }
        }

        // Function to add a date row to the table
        function addDateRow($tbody, productId, date, eventOptions, baseEventId) {
            console.log('addDateRow called with:', productId, date, eventOptions, baseEventId);
            var dateId = date.date.replace(/\D/g, ''); // Used for unique IDs
            var rowId = 'brcc-date-row-' + productId + '-' + dateId;

            var $row = $('<tr id="' + rowId + '" data-date="' + date.date + '"></tr>');

            // Date cell
            $row.append('<td>' + date.formatted_date + '</td>');

            // Inventory cell
            $row.append('<td>' + (date.inventory !== null ? date.inventory : 'N/A') + '</td>');

            // Event dropdown cell
            var $eventCell = $('<td></td>');
            var $eventSelect = $('<select class="brcc-date-event-select" data-product-id="' + productId +
                               '" data-date="' + date.date + '"></select>');

            // Add options to event select
            $eventSelect.append('<option value="">Select Event...</option>');
            $.each(eventOptions, function(index, event) {
                var selected = date.eventbrite_event_id == event.id ? ' selected' : '';
                $eventSelect.append('<option value="' + event.id + '"' + selected + '>' + event.name + '</option>');
            });

            // If the base product has an event and this date doesn't, preselect the base event
            if (!date.eventbrite_event_id && baseEventId) {
                $eventSelect.val(baseEventId);
            }

            $eventCell.append($eventSelect);
            $row.append($eventCell);

            // Ticket dropdown cell
            var $ticketCell = $('<td></td>');
            var $ticketSelect = $('<select class="brcc-date-ticket-select" data-product-id="' + productId +
                                '" data-date="' + date.date + '" ' +
                                (date.eventbrite_event_id ? '' : 'disabled') + '></select>');

            // Initial option
            $ticketSelect.append('<option value="">Select Ticket...</option>');

            // If we have a ticket ID already, add it as an option
            if (date.eventbrite_id) {
                $ticketSelect.append('<option value="' + date.eventbrite_id + '" selected>' +
                                   (date.ticket_name || 'Ticket ID: ' + date.eventbrite_id) + '</option>');
            }

            $ticketCell.append($ticketSelect);
            $row.append($ticketCell);

            // Actions cell
            var $actionsCell = $('<td></td>');
            var $testButton = $('<button type="button" class="button brcc-test-date-mapping" data-product-id="' +
                              productId + '" data-date="' + date.date + '">Test</button>');
            var $removeButton = $('<button type="button" class="button brcc-remove-date-mapping" data-product-id="' +
                                productId + '" data-date="' + date.date + '">Remove</button>');

            $actionsCell.append($testButton).append(' ').append($removeButton);
            $row.append($actionsCell);

            // Add the row to the table
            $tbody.append($row);

            // Add event handler for event selection
            $eventSelect.on('change', function() {
                var eventId = $(this).val();
                loadTicketsForDateEvent(productId, date.date, eventId, $ticketSelect);
            });

            // If we preselected the base event, trigger change to load tickets
            if ((!date.eventbrite_event_id && baseEventId) || (date.eventbrite_event_id && date.eventbrite_event_id !== '')) {
                var eventId = $eventSelect.val();
                loadTicketsForDateEvent(productId, date.date, eventId, $ticketSelect);
            }
        }

        // Function to load tickets for an event in a date row
        function loadTicketsForDateEvent(productId, dateString, eventId, $ticketSelect) {
            if (!eventId) {
                $ticketSelect.empty()
                    .append('<option value="">Select Event First...</option>')
                    .prop('disabled', true);
                return;
            }

            // Update dropdown status
            $ticketSelect.empty()
                .append('<option value="">Loading Tickets...</option>')
                .prop('disabled', true);

            // Show a loading indicator
            var $spinner = $('<span class="spinner is-active" style="float: none; margin: 0 4px;"></span>');
            $ticketSelect.after($spinner);

            // Fetch tickets via AJAX
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_get_eventbrite_tickets_for_event',
                    nonce: brcc_admin.nonce,
                    event_id: eventId
                },
                success: function(response) {
                    $ticketSelect.empty();

                    if (response.success && !$.isEmptyObject(response.data)) {
                        $ticketSelect.append('<option value="">Select Ticket...</option>');

                        $.each(response.data, function(ticketId, ticketLabel) {
                            $ticketSelect.append('<option value="' + ticketId + '">' + ticketLabel + '</option>');
                        });

                        $ticketSelect.prop('disabled', false);
                    } else {
                        $ticketSelect.append('<option value="">No tickets available</option>');
                    }
                },
                error: function() {
                    $ticketSelect.empty()
                        .append('<option value="">Error loading tickets</option>');
                },
                complete: function() {
                    $spinner.remove();
                }
            });
        }

        // Add date mapping button handler
        $(document).on('click', '.brcc-add-date-mapping', function() {
            var productId = $(this).data('product-id');
            var $tbody = $('#brcc-dates-table-body-' + productId);

            // Show datepicker dialog
            var $dialog = $('<div title="Select Date for Mapping"></div>');
            $dialog.append('<p>Choose a date for this product:</p>');
            var $datepicker = $('<input type="text" class="brcc-datepicker">');
            $dialog.append($datepicker);

            $('body').append($dialog);

            $datepicker.datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true
            });

            $dialog.dialog({
                modal: true,
                width: 400,
                buttons: {
                    "Add Mapping": function() {
                        var selectedDate = $datepicker.val();
                        if (selectedDate) {
                            // Format the date for display
                            var displayDate = $.datepicker.formatDate(
                                'MM d, yy',
                                new Date(selectedDate)
                            );

                            // Remove "No mappings" row if present
                            $tbody.find('tr td[colspan="5"]').parent().remove();

                            // Add a new date row
                            var dateObj = {
                                date: selectedDate,
                                formatted_date: displayDate,
                                inventory: null,
                                eventbrite_event_id: '',
                                eventbrite_id: ''
                            };

                            // Get event options from the first row (assuming all rows have the same event options)
                            var eventOptions = {};
                            $tbody.find('tr:first-child .brcc-date-event-select option').each(function() {
                                var val = $(this).val();
                                if (val) eventOptions[val] = $(this).text();
                            });

                            // Get base event ID
                            var baseEventId = $('#brcc_eventbrite_event_id_select_' + productId).val() || '';

                            // Add the new row
                            addDateRow($tbody, productId, dateObj, eventOptions, baseEventId);
                        }
                        $(this).dialog("close");
                    },
                    Cancel: function() {
                        $(this).dialog("close");
                    }
                },
                close: function() {
                    $(this).remove();
                }
            });
        });

        // Remove date mapping button handler
        $(document).on('click', '.brcc-remove-date-mapping', function(e) {
            //console.log('Remove button clicked:', $(this).data('product-id'), $(this).data('date'), e.target);
            e.preventDefault(); // Explicitly prevent default action
            e.stopPropagation(); // Explicitly stop event bubbling

            var $button = $(this);
            var productId = $button.data('product-id');
            var date = $button.data('date');

            if (confirm('Are you sure you want to remove this date mapping?')) {
                var $row = $button.closest('tr');
                $row.fadeOut(300, function() {
                    $row.remove();

                    // If no rows left, add a "No mappings" message
                    var $tbody = $('#brcc-dates-table-body-' + productId);
                    if ($tbody.find('tr').length === 0) {
                        $tbody.append('<tr><td colspan="5">No date-specific mappings found. Click "Add New Date Mapping" to create one.</td></tr>');
                    }
                });
            }
        });

        // Save date mappings button handler
        $(document).on('click', '.brcc-save-date-mappings', function() {
            var $button = $(this);
            var productId = $button.data('product-id');
            var $tbody = $('#brcc-dates-table-body-' + productId);

            // Disable button and show spinner
            $button.prop('disabled', true).text('Saving...');
            var $spinner = $('<span class="spinner is-active" style="float: none; margin-left: 10px;"></span>');
            $button.after($spinner);

            // Collect all date mappings
            var mappings = [];
            $tbody.find('tr').each(function() {
                var $row = $(this);
                var date = $row.data('date');

                // Skip rows without a date (like "No mappings" message row)
                if (!date) return;

                var eventId = $row.find('.brcc-date-event-select').val();
                var ticketId = $row.find('.brcc-date-ticket-select').val();

                mappings.push({
                    date: date,
                    eventbrite_event_id: eventId || '',
                    eventbrite_id: ticketId || ''
                });
            });

            // Save mappings via AJAX
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_save_product_date_mappings',
                    nonce: brcc_admin.nonce,
                    product_id: productId,
                    mappings: mappings
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        // Remove any previous messages first
                        $button.siblings('.notice').remove();
                        var $message = $('<div class="notice notice-success inline"><p>' +
                                       (response.data.message || 'Date mappings saved successfully.') + '</p></div>');
                        $button.before($message);

                        setTimeout(function() {
                            $message.fadeOut();
                        }, 3000);
                    } else {
                        // Show error message
                        // Remove any previous messages first
                        $button.siblings('.notice').remove();
                        var $message = $('<div class="notice notice-error inline"><p>' +
                                       (response.data.message || 'Error saving date mappings.') + '</p></div>');
                        $button.before($message);
                    }
                },
                error: function() {
                    // Show error message
                    // Remove any previous messages first
                    $button.siblings('.notice').remove();
                    var $message = $('<div class="notice notice-error inline"><p>' +
                                   (brcc_admin.ajax_error || 'Error saving date mappings.') + '</p></div>');
                    $button.before($message);
                },
                complete: function() {
                    // Re-enable button and remove spinner
                    $button.prop('disabled', false).text('Save Date Mappings');
                    $spinner.remove();
                }
            });
        });

        // Test date mapping button handler
        $(document).on('click', '.brcc-test-date-mapping', function() {
            var $button = $(this);
            var productId = $button.data('product-id');
            var date = $button.data('date');
            var $row = $button.closest('tr');

            var eventId = $row.find('.brcc-date-event-select').val();
            var ticketId = $row.find('.brcc-date-ticket-select').val();

            // Show validation errors if needed
            if (!eventId) {
                alert('Please select an Event first.');
                return;
            }

            if (!ticketId) {
                alert('Please select a Ticket.');
                return;
            }

            // Disable button and show spinner
            $button.prop('disabled', true).text('Testing...');
            var $spinner = $('<span class="spinner is-active" style="float: none; margin-left: 5px;"></span>');
            $button.after($spinner);

            // Test mapping via AJAX
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_test_product_date_mapping',
                    nonce: brcc_admin.nonce,
                    product_id: productId,
                    date: date,
                    eventbrite_event_id: eventId,
                    eventbrite_id: ticketId
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        var $message = $('<div class="notice notice-success inline"><p>' +
                                       (response.data.message || 'Test successful.') + '</p></div>');
                        $button.after($message);
                    } else {
                        // Show error message
                        var $message = $('<div class="notice notice-error inline"><p>' +
                                       (response.data.message || 'Test failed.') + '</p></div>');
                        $button.after($message);
                    }

                    // Remove message after a few seconds
                    setTimeout(function() {
                        $message.fadeOut(function() {
                            $message.remove();
                        });
                    }, 5000);
                },
                error: function() {
                    // Show error message
                    var $message = $('<div class="notice notice-error inline"><p>' +
                                   (brcc_admin.ajax_error || 'Test request failed.') + '</p></div>');
                    $button.after($message);

                    // Remove message after a few seconds
                    setTimeout(function() {
                        $message.fadeOut(function() {
                            $message.remove();
                        });
                    }, 5000);
                },
                complete: function() {
                    // Re-enable button and remove spinner
                    $button.prop('disabled', false).text('Test');
                    $spinner.remove();
                }
            });
        });
    }); // End jQuery(document).ready for date mappings

    // --- Attendee List Page Logic (Refactored for Lazy Loading by Date) ---
    if ($('#brcc-attendee-date-select').length) { // Check if date select exists
        var $dateSelect = $('#brcc-attendee-date-select');
        var $attendeeResultDiv = $('#brcc-attendee-list-container'); // Use the correct container ID from PHP

        // --- Function to display attendees and pagination ---
        // (Ensure this function is defined correctly, possibly moved from previous location)
        function displayAttendees(data, $targetContainer) {
            // Clear previous content/spinners within the specific table container
            $targetContainer.find('.spinner, p:contains("Loading"), .notice, .brcc-guest-list-table, .brcc-pagination').remove();

            var attendees = data.attendees || [];
            var totalAttendees = data.total_attendees || 0;
            var perPage = data.per_page || 50; // Use per_page from response if available
            var totalPages = Math.ceil(totalAttendees / perPage);
            var currentPageForSection = data.current_page || 1; // Use current_page from response
            var productId = $targetContainer.closest('.brcc-attendee-product-block').data('product-id'); // Get product ID for pagination data attributes

            if (attendees.length === 0 && totalAttendees === 0) {
                $targetContainer.append('<p class="brcc-no-attendees-message"><i>' + (brcc_admin.no_attendees_found || 'No attendees found for this product on the selected date.') + '</i></p>');
                return;
            }

            // Build Table
            // Build Table Header with Source column
            var table = '<table class="wp-list-table widefat fixed striped brcc-guest-list-table"><thead><tr>' +
                        '<th class="column-name">' + (brcc_admin.col_name || 'Name') + '</th>' +
                        '<th class="column-email">' + (brcc_admin.col_email || 'Email') + '</th>' +
                        '<th class="column-source">' + (brcc_admin.col_source || 'Source') + '</th>' + // Add Source column header
                        '<th class="column-purchase-date">' + (brcc_admin.col_purchase_date || 'Purchase Date') + '</th>' +
                        '<th class="column-order-ref">' + (brcc_admin.col_order_ref || 'Order Ref') + '</th>' +
                        '<th class="column-status">' + (brcc_admin.col_status || 'Status') + '</th>' +
                        '</tr></thead><tbody>';

            // Build Table Body Rows
            $.each(attendees, function(index, attendee) {
                // Basic sanitization helper (replace potentially harmful characters)
                function sanitize(str) {
                    if (typeof str !== 'string') return str; // Return non-strings as is
                    var temp = document.createElement('div');
                    temp.textContent = str;
                    return temp.innerHTML;
                }

                table += '<tr>' +
                         '<td class="column-name">' + sanitize(attendee.name || 'N/A') + '</td>' +
                         '<td class="column-email">' + sanitize(attendee.email || 'N/A') + '</td>' +
                         '<td class="column-source">' + sanitize(attendee.source || 'N/A') + '</td>' + // Add Source data cell
                         '<td class="column-purchase-date">' + sanitize(attendee.purchase_date || 'N/A') + '</td>' +
                         '<td class="column-order-ref">' + sanitize(attendee.order_ref || 'N/A') + '</td>' +
                         '<td class="column-status">' + sanitize(attendee.status || 'N/A') + '</td>' +
                         '</tr>';
            });
            table += '</tbody></table>';
            $targetContainer.append(table);

            // Build Pagination
            if (totalPages > 1) {
                var paginationIdSuffix = productId ? '_' + productId : ''; // Use product ID for unique pagination
                var selectedDate = $targetContainer.closest('.brcc-attendee-product-block').find('.brcc-refresh-attendees').data('date'); // Get date for pagination links

                var paginationHtml = '<div class="brcc-pagination tablenav-pages" data-product-id="' + productId + '"><span class="displaying-num">' + totalAttendees + ' items</span><span class="pagination-links">';
                if (currentPageForSection > 1) {
                    paginationHtml += '<a class="prev-page button" data-page="' + (currentPageForSection - 1) + '" data-date="' + selectedDate + '" href="#">&laquo;</a>';
                } else {
                    paginationHtml += '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
                }
                paginationHtml += '<span class="paging-input"><label for="current-page-selector' + paginationIdSuffix + '" class="screen-reader-text">Current Page</label>' +
                                  '<input class="current-page" id="current-page-selector' + paginationIdSuffix + '" type="text" name="paged" value="' + currentPageForSection + '" size="2" aria-describedby="table-paging"> of ' +
                                  '<span class="total-pages">' + totalPages + '</span></span>';
                if (currentPageForSection < totalPages) {
                    paginationHtml += '<a class="next-page button" data-page="' + (currentPageForSection + 1) + '" data-date="' + selectedDate + '" href="#">&raquo;</a>';
                } else {
                    paginationHtml += '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
                }
                paginationHtml += '</span></div>';
                $targetContainer.append(paginationHtml);
            }
        }

        // --- Event Listeners ---

        // Date Picker Selection - Initiates Product Loading
        $dateSelect.datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            onSelect: function(dateText) {
                console.log('Date selected:', dateText);
                if (!dateText) { // Handle clearing the date
                    $attendeeResultDiv.html('<p class="brcc-initial-message">' + (brcc_admin.select_date_prompt || 'Please select a date to load attendee lists.') + '</p>');
                    return;
                }

                // Extract date and time
                var selectedDate = dateText.split(' ')[0]; // Get the date part
                var selectedTime = dateText.split(' ')[1] || ''; // Get the time part (if any)

                // Clear the main container and show loading message
                $attendeeResultDiv.html('<p class="brcc-loading-message">Loading products for ' + selectedDate + '...</p>');
                // Start the process to load products for this date
                loadProductsForDate(selectedDate, selectedTime);
            }
        });

        // Source Filter Selection - Reloads products/attendees for the current date
        $('#brcc-attendee-source-filter').on('change', function() {
            var selectedDate = $dateSelect.val(); // Get the currently selected date
            if (selectedDate) {
                //console.log('Source filter changed, reloading products for date:', selectedDate);
                // Clear the main container and show loading message
                $attendeeResultDiv.html('<p class="brcc-loading-message">Reloading products for ' + selectedDate + ' with filter...</p>');
                loadProductsForDate(selectedDate); // Reload products/attendees for the current date
            }
        });

        /**
         * Initiates loading products (ticket classes) for the selected date via AJAX.
         * @param {string} selectedDate - The date in YYYY-MM-DD format.
         */
        function loadProductsForDate(selectedDate, selectedTime) {
            //console.log('Initiating product load for date:', selectedDate);
            $attendeeResultDiv.html('<p class="brcc-loading-message">Loading products for ' + selectedDate + '...</p>'); // Ensure loading message is shown

            // AJAX call to get products for the date
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_get_products_for_date', // Use the correct PHP action
                    nonce: brcc_admin.nonce,
                    selected_date: selectedDate
                },
                success: function(response) {
                    if (response.success && response.data && response.data.products) {
                        var products = response.data.products;
                        if (products.length > 0) {
                            $attendeeResultDiv.empty(); // Clear loading message
                            products.forEach(function(product) {
                                addAttendeeBlock(product.product_id, product.product_name, selectedDate);
                                // Fetch attendees for the newly added block immediately
                                console.log('About to fetch attendees for product ID: ' + product.product_id); // Added log
                                fetchAttendeesForProductDate(product.product_id, selectedDate, 1, $('#attendees-for-' + product.product_id), selectedTime);
                            });
                        } else {
                            $attendeeResultDiv.html('<p class="brcc-no-attendees-message">No products found with events scheduled for ' + selectedDate + '.</p>');
                        }
                    } else {
                         // Use response.data or response.data.message if available
                         var errorMsg = (response.data && response.data.message) ? response.data.message : 'Error loading products for the selected date.';
                         $attendeeResultDiv.html('<p class="brcc-error-message">' + errorMsg + '</p>');
                         console.error("Error fetching products:", response);
                    }
                },
                error: function(xhr, status, error) {
                     var errorMsg = brcc_admin.ajax_error || 'AJAX Error loading products.';
                     // Try to get more specific error from response
                     if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                         errorMsg = xhr.responseJSON.data.message;
                     } else if (xhr.responseText) {
                         // Fallback for non-JSON errors or different structures
                         try {
                             var response = JSON.parse(xhr.responseText);
                             if (response.data && response.data.message) {
                                 errorMsg = response.data.message;
                             }
                         } catch (e) { /* Ignore parsing error */ }
                     }
                     $attendeeResultDiv.html('<p class="brcc-error-message">' + errorMsg + '</p>');
                     console.error("AJAX error fetching products:", status, error, xhr.responseText);
                }
            });
        }

        /**
         * Adds the HTML structure for a single product's attendee list block.
         * @param {string|number} productId - The WooCommerce product ID.
         * @param {string} productName - The name of the product.
         * @param {string} selectedDate - The selected date (YYYY-MM-DD).
         */
        function addAttendeeBlock(productId, productName, selectedDate) {
             var containerId = 'attendees-for-' + productId; // Use product ID for uniqueness
             // Check if block already exists (e.g., from a previous load attempt)
             if ($('#' + containerId).length > 0) {
                 //console.log('Block already exists for product:', productId);
                 // Optionally: Trigger a refresh instead of returning?
                 // fetchAttendeesForProductDate(productId, selectedDate, 1, $('#' + containerId));
                 return; // Don't add duplicates for now
             }

             var $productBlock = $('<div/>', {
                 'id': containerId,
                 'class': 'brcc-attendee-product-block',
                 'data-product-id': productId // Store product ID
             });

             var $header = $('<div/>', {'class': 'brcc-attendee-product-block-header'})
                 .append($('<h3/>').text(productName)) // Use text() for safety
                 .append($('<button/>', {
                     'type': 'button',
                     'class': 'button button-secondary brcc-refresh-attendees',
                     'data-product-id': productId, // Link refresh to product ID
                     'data-date': selectedDate,
                     'text': 'Refresh',
                     'click': function(e) { // Attach click handler here
                         e.preventDefault();
                         var $thisButton = $(this);
                         var productId = $thisButton.data('product-id');
                         var selectedDate = $thisButton.data('date');
                         var $targetBlock = $('#attendees-for-' + productId); // Find the target block

                         $thisButton.prop('disabled', true).text(brcc_admin.fetching || 'Fetching...');

                         // Clear existing content and show loading message
                         $targetBlock.find('.brcc-attendee-table-container').html('<p class="brcc-loading-message">' + (brcc_admin.loading_attendees || 'Loading attendee data...') + '</p>');

                         // Fetch attendees for the product and date
                         fetchAttendeesForProductDate(productId, selectedDate, 1, $targetBlock);
                     }
                 }));

             var $tableContainer = $('<div/>', {'class': 'brcc-attendee-table-container'})
                 .html('<p class="brcc-loading-message">Loading attendees...</p>'); // Initial loading state

             $productBlock.append($header).append($tableContainer);
             $attendeeResultDiv.append($productBlock);
        }

/**
 * Fetches attendees for a specific product and date
 * @param {number} productId - The product ID to fetch attendees for
 * @param {string} selectedDate - Date string in YYYY-MM-DD format
 * @param {number} page - The page number to fetch (for pagination)
 * @param {jQuery} $targetBlock - The jQuery element to update with results
 */
function fetchAttendeesForProductDate(productId, selectedDate, page, $targetBlock, selectedTime) {
   // Show loading indicator in the table container
   var $tableContainer = $targetBlock.find('.brcc-attendee-table-container');
   $tableContainer.html('<p class="brcc-loading-message">' + (brcc_admin.loading_attendees || 'Loading attendee data...') + '</p>');
   
   // Get the current source filter value
   var sourceFilter = $('#brcc-attendee-source-filter').val();
   
   console.log('Fetching attendees for Product ID:', productId, 'Date:', selectedDate, 'Page:', page, 'Source Filter:', sourceFilter, 'Time:', selectedTime);
   
   // Make the AJAX request
   $.ajax({
       url: brcc_admin.ajax_url,
       type: 'POST',
       data: {
           action: 'brcc_fetch_product_attendees_for_date', // This must exactly match the WordPress action hook
           nonce: brcc_admin.nonce,
           product_id: productId,
           selected_date: selectedDate,
           page: page,
           source_filter: sourceFilter,
           selected_time: selectedTime
       },
        success: function(response) {
            console.log('Attendee fetch success:', response);
            
            // Re-enable the refresh button if it exists
            $targetBlock.find('.brcc-refresh-attendees').prop('disabled', false).text('Refresh');
            
            if (response.success && response.data) {
                var attendees = response.data.attendees || [];
                var totalAttendees = response.data.total_attendees || 0;
                var totalPages = response.data.total_pages || 1;
                
                // If no attendees found
                if (attendees.length === 0) {
                    $tableContainer.html('<p class="brcc-no-attendees-message">' + 
                        (brcc_admin.no_attendees || 'No attendees found for this date and product.') + '</p>');
                    return;
                }
                
                // Build the attendee table
                var tableHtml = '<div class="brcc-attendee-count">' + 
                    'Showing ' + attendees.length + ' of ' + totalAttendees + ' total attendees</div>' +
                    '<table class="widefat brcc-attendee-table">' +
                    '<thead><tr>' +
                    '<th>Name</th>' +
                    '<th>Email</th>' +
                    '<th>Purchase Date</th>' +
                    '<th>Order/Ticket Ref</th>' +
                    '<th>Status</th>' +
                    '<th>Source</th>' +
                    '</tr></thead><tbody>';
                
                // Add each attendee row
                $.each(attendees, function(i, attendee) {
                    tableHtml += '<tr' + (i % 2 === 0 ? ' class="alternate"' : '') + '>' +
                        '<td>' + (attendee.name || 'N/A') + '</td>' +
                        '<td>' + (attendee.email || 'N/A') + '</td>' +
                        '<td>' + (attendee.purchase_date || 'N/A') + '</td>' +
                        '<td>' + (attendee.order_ref || 'N/A') + '</td>' +
                        '<td>' + (attendee.status || 'N/A') + '</td>' +
                        '<td>' + (attendee.source || 'Unknown') + '</td>' +
                        '</tr>';
                });
                
                tableHtml += '</tbody></table>';
                
                // Add pagination if needed
                if (totalPages > 1) {
                    tableHtml += buildPaginationControls(page, totalPages, productId, selectedDate);
                }
                
                // Update the table container with the results
                $tableContainer.html(tableHtml);
                
                // Attach pagination click handlers
                attachPaginationHandlers($tableContainer, productId, selectedDate, $targetBlock);
            } else {
                // Handle error in response
                var errorMsg = (response.data && response.data.message) ? 
                    response.data.message : 
                    'Error loading attendees. Please try again.';
                    
                $tableContainer.html('<p class="brcc-error-message">' + errorMsg + '</p>');
                console.error('Error in attendee fetch response:', response);
            }
        },
        error: function(xhr, status, error) {
            // Re-enable the refresh button
            $targetBlock.find('.brcc-refresh-attendees').prop('disabled', false).text('Refresh');
            
            // Try to get more specific error from response
            var errorMsg = 'AJAX Error loading attendees. Please try again.';
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                errorMsg = xhr.responseJSON.data.message;
            } else if (xhr.responseText) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.data && response.data.message) {
                        errorMsg = response.data.message;
                    }
                } catch (e) { /* Ignore parsing error */ }
            }
            
            $tableContainer.html('<p class="brcc-error-message">' + errorMsg + '</p>');
            console.error('AJAX error fetching attendees:', status, error, xhr.responseText);
        }
    });
}

/**
 * Builds pagination controls HTML
 */
function buildPaginationControls(currentPage, totalPages, productId, selectedDate) {
    var paginationHtml = '<div class="brcc-pagination">';
    
    // Previous button
    if (currentPage > 1) {
        paginationHtml += '<a href="#" class="brcc-pagination-link" data-page="' + (currentPage - 1) + 
            '" data-product-id="' + productId + '" data-date="' + selectedDate + '">« Previous</a>';
    } else {
        paginationHtml += '<span class="brcc-pagination-disabled">« Previous</span>';
    }
    
    // Page numbers (show 5 pages centered around current page)
    var startPage = Math.max(1, currentPage - 2);
    var endPage = Math.min(totalPages, startPage + 4);
    
    // Adjust if we're near the end
    if (endPage - startPage < 4) {
        startPage = Math.max(1, endPage - 4);
    }
    
    for (var i = startPage; i <= endPage; i++) {
        if (i === currentPage) {
            paginationHtml += '<span class="brcc-pagination-current">' + i + '</span>';
        } else {
            paginationHtml += '<a href="#" class="brcc-pagination-link" data-page="' + i + 
                '" data-product-id="' + productId + '" data-date="' + selectedDate + '">' + i + '</a>';
        }
    }
    
    // Next button
    if (currentPage < totalPages) {
        paginationHtml += '<a href="#" class="brcc-pagination-link" data-page="' + (currentPage + 1) + 
            '" data-product-id="' + productId + '" data-date="' + selectedDate + '">Next »</a>';
    } else {
        paginationHtml += '<span class="brcc-pagination-disabled">Next »</span>';
    }
    
    paginationHtml += '</div>';
    return paginationHtml;
}

/**
 * Attaches click handlers to pagination links
 */
function attachPaginationHandlers($container, productId, selectedDate, $targetBlock) {
    $container.find('.brcc-pagination-link').on('click', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        fetchAttendeesForProductDate(productId, selectedDate, page, $targetBlock);
    });
}


        // Refresh button click handler (delegated)
        $attendeeResultDiv.on('click', '.brcc-refresh-attendees', function() {
            var $button = $(this);
            var productId = $button.data('product-id');
            var selectedDate = $button.data('date');
            var $targetBlock = $button.closest('.brcc-attendee-product-block'); // Get the parent block

            if (productId && selectedDate) {
                fetchAttendeesForProductDate(productId, selectedDate, 1, $targetBlock); // Fetch page 1 again
            }
        });

        // Pagination click handler (delegated)
        $attendeeResultDiv.on('click', '.brcc-pagination a', function(e) {
            e.preventDefault();
            var $link = $(this);
            var $targetBlock = $link.closest('.brcc-attendee-product-block'); // Find the parent block
            var productId = $targetBlock.data('product-id'); // Get product ID from block
            var page = $link.data('page');
            // Get date from refresh button in the same block - safer than assuming $dateSelect has the right value
            var selectedDate = $targetBlock.find('.brcc-refresh-attendees').data('date');

            if ($link.hasClass('disabled') || !productId || !page || !selectedDate) {
                console.warn('Pagination click ignored - missing data or disabled.');
                return;
            }

            fetchAttendeesForProductDate(productId, selectedDate, page, $targetBlock);
        });

        // Pagination Input Change (Delegated)
        $attendeeResultDiv.on('change', '.brcc-pagination input.current-page', function() {
            var $input = $(this);
            var $targetBlock = $input.closest('.brcc-attendee-product-block');
            var productId = $targetBlock.data('product-id');
            var page = parseInt($input.val(), 10);
            var totalPages = parseInt($targetBlock.find('.total-pages').text(), 10);
            var selectedDate = $targetBlock.find('.brcc-refresh-attendees').data('date');

            if (isNaN(page) || page < 1) page = 1;
            if (page > totalPages) page = totalPages;
            $input.val(page); // Correct input value if needed

            if (productId && selectedDate) {
                 fetchAttendeesForProductDate(productId, selectedDate, page, $targetBlock);
            } else {
                 console.error("Could not determine product ID or date for pagination input.");
            }
        });

    } // End Attendee List Page Logic specific block

})(jQuery); 

// Optimize setTimeout handlers
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Apply debouncing to handlers
const debouncedInitSelect2 = debounce(initializeSelect2, 100);
$(document).ready(function() {
    // Use debounced version
    $('.brcc-select2').each(function() {
        debouncedInitSelect2(this);
    });
});

// Optimize DOM operations
function batchDOMOperations(operations) {
    // Use requestAnimationFrame for DOM updates
    requestAnimationFrame(() => {
        operations();
    });
}

// Apply to table updates
function updateTable($container, data) {
    batchDOMOperations(() => {
        const fragment = document.createDocumentFragment();
        // Build table in memory
        const table = document.createElement('table');
        // ... table building logic ...
        fragment.appendChild(table);
        // Single DOM update
        $container.empty().append(fragment);
    });
}

/**
 * Enhanced Select2/SelectWoo initialization with better error handling and retry mechanism
 * @param {jQuery|HTMLElement} selector - The select element to initialize
 * @param {number} retries - Number of retry attempts
 * @returns {boolean} - Whether initialization was successful
 */
function initializeSelect2(selector, retries = 3) {
    // Check if Select2/SelectWoo is loaded
    if (!$.fn.select2 && !$.fn.selectWoo) {
        console.warn('Select2/SelectWoo not loaded');
        return false;
    }

    // Convert selector to jQuery object if needed
    const $select = $(selector);
    if (!$select.length) {
        console.warn('Invalid selector provided to initializeSelect2');
        return false;
    }

    try {
        // Destroy existing instances
        if ($select.data('select2')) $select.select2('destroy');
        if ($select.data('selectWoo')) $select.selectWoo('destroy');

        // Initialize with proper configuration
        if (typeof $.fn.selectWoo === 'function') {
            $select.selectWoo({
                width: '100%',
                dropdownParent: $select.parent(),
                placeholder: 'Select an option...',
                minimumResultsForSearch: 5,
                dropdownAutoWidth: true
            });
        } else {
            $select.select2({
                width: '100%',
                dropdownParent: $select.parent(),
                placeholder: 'Select an option...',
                minimumResultsForSearch: 5,
                dropdownAutoWidth: true
            });
        }
        return true;
    } catch (error) {
        console.warn('Select2 initialization failed:', error);
        if (retries > 0) {
            console.log(`Retrying initialization (${retries} attempts remaining)...`);
            setTimeout(() => initializeSelect2(selector, retries - 1), 100);
        }
        return false;
    }
}

// Initialize Select2 for both event and ticket dropdowns on page load
$(document).ready(function() {
    // Calculate dynamic delay based on page load time
    const initDelay = Math.max(100, performance.now() - performance.timing.domContentLoadedEventStart);
    
    setTimeout(function() {
        try {
            // Only initialize Select2 if we're on the product mapping page
            if ($('#brcc-product-mapping-table').length) {
                // Initialize Event dropdowns first
                const $eventSelects = $('.brcc-eventbrite-event-id-select');
                if ($eventSelects.length) {
                    $eventSelects.each(function() {
                        try {
                            initializeSelect2(this);
                        } catch (e) {
                            console.warn('Failed to initialize Select2 for event select:', e);
                        }
                    });
                } else {
                    console.debug('No .brcc-eventbrite-event-id-select elements found on page');
                }

                // Initialize existing Ticket dropdowns
                const $ticketSelects = $('.brcc-eventbrite-ticket-id-select');
                if ($ticketSelects.length) {
                    $ticketSelects.each(function() {
                        try {
                            initializeSelect2(this);
                        } catch (e) {
                            console.warn('Failed to initialize Select2 for ticket select:', e);
                        }
                    });
                }
            }

            // Initialize Select2 for daily sales page if needed
            if ($('body').hasClass('brcc-daily-sales-page')) {
                const $dailySalesSelects = $('.brcc-select2');
                if ($dailySalesSelects.length) {
                    $dailySalesSelects.each(function() {
                        try {
                            initializeSelect2(this);
                        } catch (e) {
                            console.warn('Failed to initialize Select2 for daily sales select:', e);
                        }
                    });
                }
            }
        } catch (e) {
            console.error('Error during Select2 initialization:', e);
        }
    }, initDelay);
});

// Re-initialize Select2 after dynamic content updates with enhanced error handling
$(document).on('brcc_content_updated', function() {
    const $selects = $('.brcc-eventbrite-event-id-select, .brcc-eventbrite-ticket-id-select, .brcc-select2');
    if (!$selects.length) {
        console.debug('No select elements found for initialization after content update');
        return;
    }

    $selects.each(function() {
        try {
            initializeSelect2(this);
        } catch (e) {
            console.warn('Failed to initialize Select2 after content update:', e);
        }
    });
});
