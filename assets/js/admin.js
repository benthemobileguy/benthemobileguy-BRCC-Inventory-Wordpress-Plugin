/**
 * BRCC Inventory Tracker Admin JavaScript
 * Manages all admin UI interactions for the BRCC Inventory Tracker plugin
 */

(function($) {
    'use strict';

    // Global state
    const state = {
        loading: false,
        currentModalProductId: null,
        requestCache: new Map(),
        eventListeners: new Set()
    };

    // Track active AJAX requests to prevent duplicates
    const activeRequests = new Set();

    // Document ready - Initialize all components
    $(document).ready(function() {
        console.log('BRCC Admin JS initialized');

        fetchAndPopulateEvents(); // Fetch events via AJAX on page load

        // Basic UI components
        initializeDatepickers();
        initializeSelect2ForDropdowns(); // This will now handle event dropdowns too

        // API and connectivity features
        initializeApiKeyRegeneration();
        initializeEventbriteTest();
        initializeClearEventbriteCache();
        initializeSquareConnection();

        // Inventory management
        initializeInventorySync();
        initializeProductMappings();
        initializeProductMappingTest();
        initializeSquareMappingTest();
        initializeInventoryAlerts(); // Initialize inventory alerts functionality

        // Date-based features
        initializeDateRangeFilter();
        initializeResetTodaysSales();
        initializeAttendeeLists();

        // Import functionality
        initializeImportControls();

        // Trigger change events on product mappings to correctly populate ticket dropdowns
        // Moved the event handler setup into initializeSelect2ForDropdowns
        $('.brcc-eventbrite-event-id-select').each(function() {
            if ($(this).val()) {
                $(this).trigger('change');
            }
        });

        // Initialize the Force Sync Tool components
        initializeForceSyncTool();

        // Point 4: Add Event Delegation for Dynamically Added Dropdowns
        // Re-initialize Select2 after AJAX calls that might add new dropdowns
        $(document).ajaxComplete(function(event, xhr, settings) {
            // Check if we need to re-initialize selects based on the AJAX action
            // Specifically target the action that loads date mappings
            if (settings.data && typeof settings.data === 'string' &&
                settings.data.indexOf('action=brcc_get_product_dates') > -1) {
                // Wait a short time for DOM to update after the date mapping table is rendered
                setTimeout(function() {
                    // Find the newly added date event dropdowns within the container populated by the AJAX call
                    // Assuming the AJAX call populates a container like '.brcc-dates-cell' or similar
                    $('.brcc-dates-cell .brcc-date-event').each(function() {
                        const $dropdown = $(this);
                        // Check if it's already initialized to avoid conflicts
                        if (!$dropdown.data('select2') && !$dropdown.data('selectWoo')) {
                            populateEventDropdown($dropdown); // Populate first
                            initializeSelect2($dropdown);     // Then initialize
                        }
                    });
                    // Also initialize ticket dropdowns if they exist in the dynamic content
                     $('.brcc-dates-cell [id^=brcc_manual_eventbrite_id_select_]').each(function() {
                         if (!$(this).data('select2') && !$(this).data('selectWoo')) {
                            initializeSelect2(this);
                        }
                    });
                }, 200); // Adjusted timeout slightly
            }
            // Handle ticket loading separately if needed by its own AJAX action
            else if (settings.data && typeof settings.data === 'string' &&
                     settings.data.indexOf('action=brcc_get_eventbrite_tickets_for_event') > -1) {
                 setTimeout(function() {
                     // Find ticket dropdowns that might need re-initialization after loading options
                     $('[id^=brcc_manual_eventbrite_id_select_]').each(function() {
                         // Re-initialize Select2 after AJAX populates options (handled in loadEventbriteTickets complete callback)
                         // initializeSelect2(this); // This might be redundant if loadEventbriteTickets handles it
                     });
                 }, 150);
            }
        });

        // Point 6: Add global event handler for modals
        // Add a global event handler to initialize select2 on modals when they open
        $(document).on('click', '.brcc-manage-dates', function() {
            // The ajaxComplete handler above should now handle the initialization
            // after the content is loaded, making this specific timeout potentially redundant.
            // However, keeping a targeted re-check might be safer.
            // Let's refine this to ensure it targets the correct elements after the AJAX call
            // associated with '.brcc-manage-dates' finishes.
            // Note: The ajaxComplete handler is generally preferred for dynamic content.
            // This block can be removed if ajaxComplete proves reliable.
            /*
            setTimeout(function() {
                // Find elements within the specific expanded row for this product ID
                var productId = $(this).data('product-id'); // Get product ID from the clicked button
                var $expandedRow = $('#brcc-dates-row-' + productId);

                $expandedRow.find('.brcc-date-event').each(function() {
                    populateEventDropdown($(this));
                    initializeSelect2(this);
                });
                $expandedRow.find('[id^=brcc_manual_eventbrite_id_select_]').each(function() {
                     initializeSelect2(this);
                });
            }, 350); // Adjusted timeout
            */
        });

    });
 
    /**
     * Fetch Eventbrite events via AJAX and populate dropdowns.
     */
    function fetchAndPopulateEvents(callback) {
        console.log("fetchAndPopulateEvents: Fetching events via AJAX...");
        
        
        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'brcc_get_all_eventbrite_events_for_attendees',
                nonce: brcc_admin.nonce,
                selected_date: new Date().toISOString().split('T')[0] // Pass today's date
            },
            success: function(response) {
                console.log("fetchAndPopulateEvents AJAX Response:", response);
                if (response.success && response.data && response.data.events) {
                    console.log("fetchAndPopulateEvents: Events received:", Object.keys(response.data.events).length);
                    
                    // Update the global events object
                    if (typeof brcc_admin !== 'undefined') {
                        brcc_admin.eventbrite_events = response.data.events;
                        console.log("fetchAndPopulateEvents: Updated brcc_admin.eventbrite_events");
                    }
                    
                    // Now populate and initialize dropdowns that exist on the page
                    $('.brcc-eventbrite-event-id-select').each(function() {
                        populateEventDropdown($(this)); // Populate first
                        initializeSelect2(this);     // Then initialize
                    });
                    // Also handle date-specific ones if any are visible initially
                    $('.brcc-date-event').each(function() {
                        if (!$(this).data('select2') && !$(this).data('selectWoo')) {
                           populateEventDropdown($(this));
                           initializeSelect2(this);
                        }
                   });
                   
                   console.log("fetchAndPopulateEvents: Dropdowns populated and initialized.");
                   
                   // Trigger change on pre-selected main dropdowns to load tickets
                   $('.brcc-eventbrite-event-id-select').each(function() {
                       if ($(this).val()) {
                           $(this).trigger('change');
                       }
                   });

                } else {
                    console.error("fetchAndPopulateEvents: Error in response or no events found.", response);
                    // Handle error display if needed (e.g., show message in dropdown)
                     $('.brcc-eventbrite-event-id-select').prop('disabled', false).html('<option value="">Error loading events</option>');
                }
                
                // Execute callback if provided
                if (typeof callback === 'function') {
                    callback();
                }
            },
            error: function(xhr, status, error) {
                console.error("fetchAndPopulateEvents AJAX Error:", status, error);
                console.log("Response text:", xhr.responseText);
                 $('.brcc-eventbrite-event-id-select').prop('disabled', false).html('<option value="">AJAX Error loading events</option>');
                 
                 // Execute callback if provided, even on error
                 if (typeof callback === 'function') {
                     callback();
                 }
            }
        });
    }

    /**
     * Initialize datepickers throughout the admin interface
     */
    function initializeDatepickers() {
        // Standard datepickers
        if ($('.brcc-datepicker').length) {
            $('.brcc-datepicker').datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true
            });
        }
        
        // Daily sales page specific datepickers
        if ($('#brcc-start-date').length && $('#brcc-end-date').length) {
            $('#brcc-start-date, #brcc-end-date').datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true,
                maxDate: '0'
            });
        }
    }

    /**
     * Initialize Select2 for all dropdown menus (Point 2: Replaced Function)
     */
    function initializeSelect2ForDropdowns() {
        // Initialize all selects with the select2 class
        if ($('.brcc-select2').length) {
            $('.brcc-select2').each(function() {
                initializeSelect2(this);
            });
        }

        // Initialize ALL main event dropdowns with Select2
        // Use the correct class from the HTML generated in class-brcc-admin.php
        if ($('.brcc-eventbrite-event-id-select').length) {
            $('.brcc-eventbrite-event-id-select').each(function() {
                populateEventDropdown($(this)); // Populate first
                initializeSelect2(this);     // Then initialize
            });
        }

        // Initialize date-specific event dropdowns that might exist on initial page load
        // (e.g., if the date section was somehow rendered server-side initially)
        // The ajaxComplete handler will take care of dynamically loaded ones.
        if ($('.brcc-date-event').length) {
            $('.brcc-date-event').each(function() {
                 // Check if already initialized by another process
                 if (!$(this).data('select2') && !$(this).data('selectWoo')) {
                    populateEventDropdown($(this)); // Populate first
                    initializeSelect2(this);     // Then initialize
                 }
            });
        }

        // Initialize any manual ticket ID dropdowns (using ID pattern from class-brcc-admin.php)
        $('[id^=brcc_manual_eventbrite_id_select_]').each(function() {
            initializeSelect2(this);
        });

        // Attach the change event to load tickets for events (main mapping table)
        // Use event delegation for potentially dynamic elements
        $(document).off('change.brccEventSelect').on('change.brccEventSelect', '.brcc-eventbrite-event-id-select', function() {
            loadEventbriteTickets($(this));
        });

        // Also handle date-specific events (for the modal/dynamic rows)
        // Use event delegation
         $(document).off('change.brccDateEventSelect').on('change.brccDateEventSelect', '.brcc-date-event', function() { // Changed selector
            // Find the corresponding ticket dropdown within the same row/context
            const $row = $(this).closest('tr'); // Assuming it's in a table row
            // Pass the event select itself to loadEventbriteTickets
            loadEventbriteTickets($(this), $row); // Pass row context if needed by loadEventbriteTickets
        });
    }

    /**
     * Initialize Select2 on a specific element with optimal settings (Point 3: Replaced Function)
     * @param {HTMLElement|jQuery|string} selector - Element or selector to initialize
     */
    function initializeSelect2(selector) {
        let $select;

        // Handle different input types
        if (typeof selector === 'string') {
            $select = $(selector);
        } else if (selector instanceof jQuery) {
            $select = selector;
        } else if (selector instanceof HTMLElement) {
            $select = $(selector);
        } else {
            console.warn('Invalid selector type passed to initializeSelect2:', selector);
            return;
        }

        // Only proceed if element exists
        if (!$select.length) {
            // console.log('initializeSelect2: Element not found for selector:', selector); // Optional debug
            return;
        }

        // Detect SelectWoo vs Select2
        const isSelectWoo = typeof $.fn.selectWoo === 'function';
        const selectFuncName = isSelectWoo ? 'selectWoo' : 'select2';
        const selectPluginFunc = $.fn[selectFuncName];

        if (typeof selectPluginFunc === 'function') {
            try {
                // Destroy existing instance if present to avoid conflicts
                if ($select.data('select2')) $select.select2('destroy');
                if ($select.data('selectWoo')) $select.selectWoo('destroy');

                // Initialize with consistent settings for a better look
                $select[selectFuncName]({
                    width: '100%', // Use full width
                    minimumResultsForSearch: 8, // Show search box after 8 items
                    placeholder: $select.data('placeholder') || $select.find('option[value=""]').text() || 'Select an option...', // Use placeholder data or first option text
                    allowClear: true, // Add a clear button
                    dropdownAutoWidth: true, // Adjust dropdown width automatically
                    // Attempt to attach dropdown to a more stable parent if possible
                    dropdownParent: $select.closest('.brcc-mapping-input-group, .brcc-dates-cell, .wp-list-table, form').length ?
                        $select.closest('.brcc-mapping-input-group, .brcc-dates-cell, .wp-list-table, form') : $('body')
                });
                 // console.log('Select2 initialized for:', selector); // Optional debug
            } catch (error) {
                console.warn(`Select2/SelectWoo initialization failed for selector:`, selector, error);
            }
        } else {
             console.warn('Select2/SelectWoo function not found.');
        }
    }

    /**
     * Load Eventbrite tickets for a selected event (Point 1: Updated Function Start)
     * @param {jQuery} $eventSelect - The jQuery object for the event select dropdown
     * @param {jQuery} [$context] - Optional context (e.g., table row) to find the ticket select within
     */
    function loadEventbriteTickets($eventSelect, $context) {
        const eventId = $eventSelect.val();
        const productId = $eventSelect.data('product-id'); // Assuming product ID is still relevant

        // Determine the context to search within
        const $searchContext = $context || $eventSelect.closest('tr, .brcc-dates-row'); // Find closest row or specific container

        // IMPORTANT CHANGE: Use consistent selector pattern for ticket dropdowns
        // Find the ticket select relative to the event select or within the context
        let $ticketSelect = $searchContext.find('[id^="brcc_manual_eventbrite_id_select_"]');
        // If not found by ID pattern, try finding by class if applicable (adjust class if needed)
        if (!$ticketSelect.length) {
             $ticketSelect = $searchContext.find('.brcc-ticket-selector'); // Example fallback class
        }
         // If still not found, try the original ID pattern based on product ID (less reliable in dynamic contexts)
        if (!$ticketSelect.length && productId) {
             $ticketSelect = $('#brcc_manual_eventbrite_id_select_' + productId);
        }


        // Ensure we found the ticket select
        if (!$ticketSelect.length) {
            console.warn('Could not find the corresponding ticket select dropdown for event select:', $eventSelect);
            return;
        }

        const $spinner = $eventSelect.closest('.brcc-mapping-input-group, .brcc-eventbrite-cell').find('.spinner'); // Adjust spinner context if needed
        const previouslySelectedTicket = $ticketSelect.data('selected') || $ticketSelect.val(); // Get current or data-selected value

        // Create a unique request ID to avoid duplicate requests
        const requestId = `ticket_${eventId}_${productId || $eventSelect.attr('id')}`; // Use event select ID if no product ID

        // If there's already an active request for this combination, abort
        if (activeRequests.has(requestId)) {
            // console.log('Duplicate ticket request aborted:', requestId); // Optional debug
            return;
        }

        // Clean up existing Select2 instance before modifying options
        if ($ticketSelect.data('select2')) $ticketSelect.select2('destroy');
        if ($ticketSelect.data('selectWoo')) $ticketSelect.selectWoo('destroy');

        // Clear and disable ticket dropdown
        $ticketSelect.html('<option value="">' + (brcc_admin.loading || 'Loading...') + '</option>').prop('disabled', true);
        if ($spinner.length) {
            $spinner.addClass('is-active').css('visibility', 'visible');
        } else {
             console.warn('Spinner not found for ticket select:', $ticketSelect);
        }


        if (!eventId) {
            $ticketSelect.html('<option value="">' + (brcc_admin.select_event_prompt || 'Select Event First...') + '</option>');
            if ($spinner.length) $spinner.removeClass('is-active').css('visibility', 'hidden');
            initializeSelect2($ticketSelect); // Re-initialize Select2 after clearing
            return;
        }

        // Add this request to active requests
        activeRequests.add(requestId);
        // console.log('Fetching tickets for request:', requestId); // Optional debug

        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_get_eventbrite_tickets_for_event', // Ensure this AJAX action exists and is correct
                nonce: brcc_admin.nonce,
                event_id: eventId
            },
            success: function(response) {
                $ticketSelect.empty(); // Clear loading message

                if (response.success && typeof response.data === 'object') {
                    $ticketSelect.append($('<option>', {
                        value: '',
                        text: (brcc_admin.select_ticket_prompt || 'Select Ticket...')
                    }));

                    if ($.isEmptyObject(response.data)) {
                        $ticketSelect.append($('<option>', {
                            value: '',
                            text: (brcc_admin.no_tickets_found || 'No tickets found'),
                            disabled: true
                        }));
                    } else {
                        $.each(response.data, function(ticketId, ticketLabel) {
                            $ticketSelect.append($('<option>', {
                                value: ticketId,
                                text: ticketLabel // Assuming label includes name and ID
                            }));
                        });

                        // Try to re-select previously saved/selected ticket
                        if (previouslySelectedTicket) {
                            // Check if the option still exists before setting it
                            if ($ticketSelect.find('option[value="' + previouslySelectedTicket + '"]').length) {
                                 $ticketSelect.val(previouslySelectedTicket);
                            } else {
                                 console.log('Previously selected ticket', previouslySelectedTicket, 'not found in new options.'); // Optional debug
                            }
                        }
                    }

                    $ticketSelect.prop('disabled', false);
                } else {
                     console.error('Error loading tickets:', response.data || 'Unknown error');
                    $ticketSelect.append($('<option>', {
                        value: '',
                        text: (brcc_admin.error_loading_tickets || 'Error loading tickets')
                    }));
                     $ticketSelect.prop('disabled', true); // Keep disabled on error
                }

            },
            error: function(xhr, status, error) {
                console.error('AJAX Error fetching tickets:', status, error);
                $ticketSelect.empty().append($('<option>', {
                    value: '',
                    text: (brcc_admin.ajax_error || 'AJAX Error')
                }));
                 $ticketSelect.prop('disabled', true); // Keep disabled on error
            },
            complete: function() {
                 if ($spinner.length) $spinner.removeClass('is-active').css('visibility', 'hidden');
                 // Re-initialize Select2 AFTER options are populated and value is set
                 initializeSelect2($ticketSelect);
                 $ticketSelect.trigger('change'); // Trigger change in case anything depends on it

                // Remove from active requests when complete
                activeRequests.delete(requestId);
                // console.log('Ticket request complete:', requestId); // Optional debug
            }
        });
    }

    /**
     * Initialize API key regeneration functionality
     */
    function initializeApiKeyRegeneration() {
        $('#regenerate-api-key').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm(brcc_admin.regenerate_key_confirm)) {
                return;
            }
            
            const $button = $(this);
            $button.prop('disabled', true);
            
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_regenerate_api_key',
                    nonce: brcc_admin.nonce
                },
                success: function(response) {
                    if (response.success && response.data && response.data.api_key) {
                        $('#api_key').val(response.data.api_key);
                    } else {
                        alert(response.data && response.data.message ? response.data.message : brcc_admin.ajax_error);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    alert(brcc_admin.ajax_error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
    }

    /**
     * Initialize Eventbrite test connection functionality
     */
    function initializeEventbriteTest() {
        $('#test-eventbrite-connection').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $statusSpan = $('#eventbrite-test-status');
            
            if ($button.prop('disabled')) return;
            
            $button.prop('disabled', true).text(brcc_admin.testing || 'Testing...');
            $statusSpan.removeClass('success error').text('').show();
            
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_test_eventbrite_connection',
                    nonce: brcc_admin.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        $statusSpan.addClass('success').text(response.data.message);
                    } else {
                        $statusSpan.addClass('error').text(
                            response.data && response.data.message ? response.data.message : brcc_admin.ajax_error
                        );
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    $statusSpan.addClass('error').text(brcc_admin.ajax_error);
                },
                complete: function() {
                    $button.prop('disabled', false).text(brcc_admin.test || 'Test');
                    setTimeout(function() { $statusSpan.fadeOut(); }, 8000);
                }
            });
        });
    }

    /**
     * Initialize Clear Eventbrite Cache functionality
     */
    function initializeClearEventbriteCache() {
        $('#clear-eventbrite-cache, #brcc-refresh-eventbrite-cache').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $statusSpan = $('#eventbrite-cache-status, #brcc-refresh-cache-status').first();
            
            if ($button.prop('disabled')) return;
            
            const clearingText = brcc_admin.clearing_cache || 'Clearing Cache...';
            const defaultText = $button.text();
            
            $button.prop('disabled', true).text(clearingText);
            $statusSpan.text('').removeClass('success error').show();
            
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_clear_eventbrite_cache',
                    nonce: brcc_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $statusSpan.addClass('success').text(response.data || 'Cache cleared successfully.');
                        alert('Eventbrite cache cleared. Please reload the page if you need to see the updated event list in dropdowns.');
                    } else {
                        $statusSpan.addClass('error').text(response.data && response.data.message ? response.data.message : brcc_admin.ajax_error);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    $statusSpan.addClass('error').text(brcc_admin.ajax_error || 'Error clearing cache');
                },
                complete: function() {
                    $button.prop('disabled', false).text(defaultText);
                }
            });
        });
    }

    /**
     * Initialize Square connection test
     */
    function initializeSquareConnection() {
        $('#brcc-test-square-connection').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            if ($button.prop('disabled')) return;
            
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
                        $('#brcc-mapping-result').empty().append(
                            $('<div>').addClass('notice notice-success').append(
                                $('<p>').text(response.data.message)
                            )
                        ).show();
                    } else {
                        $('#brcc-mapping-result').empty().append(
                            $('<div>').addClass('notice notice-error').append(
                                $('<p>').text(response.data && response.data.message ? response.data.message : 'Connection failed')
                            )
                        ).show();
                    }
                    
                    setTimeout(function() {
                        $('#brcc-mapping-result').fadeOut();
                    }, 5000);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    $button.prop('disabled', false).text('Test Square Connection');
                    $('#brcc-mapping-result').empty().append(
                        $('<div>').addClass('notice notice-error').append(
                            $('<p>').text(brcc_admin.ajax_error)
                        )
                    ).show();
                }
            });
        });
        
        // Fetch Square catalog
        $('#brcc-fetch-square-catalog').on('click', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            if ($button.prop('disabled')) return;
            
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
                    
                    const $catalogContainer = $('#brcc-square-catalog-items');
                    $catalogContainer.empty();
                    
                    if (response.success && response.data && response.data.catalog) {
                        const catalog = response.data.catalog;
                        
                        if (catalog && catalog.length > 0) {
                            const $table = $('<table>').addClass('wp-list-table widefat fixed striped');
                            const $thead = $('<thead>').appendTo($table);
                            const $tbody = $('<tbody>').appendTo($table);
                            const $trHead = $('<tr>').appendTo($thead);
                            
                            $('<th>').text('Item Name').appendTo($trHead);
                            $('<th>').text('Item ID').appendTo($trHead);
                            $('<th>').text('Description').appendTo($trHead);
                            $('<th>').text('Variations').appendTo($trHead);
                            
                            $.each(catalog, function(i, item) {
                                if (!item) return; // Skip null/undefined items
                                
                                const $tr = $('<tr>').appendTo($tbody);
                                $('<td>').text(item.name || '').appendTo($tr);
                                $('<td>').append($('<code>').text(item.id || '')).appendTo($tr);
                                $('<td>').text(item.description || '').appendTo($tr);
                                
                                const $variationsTd = $('<td>').appendTo($tr);
                                if (item.variations && item.variations.length > 0) {
                                    const $ul = $('<ul>').css({margin: 0, paddingLeft: '20px'}).appendTo($variationsTd);
                                    $.each(item.variations, function(j, variation) {
                                        if (!variation) return; // Skip null/undefined variations
                                        
                                        const $li = $('<li>').appendTo($ul);
                                        $li.append(document.createTextNode((variation.name || '') + ' - '));
                                        $li.append($('<code>').text(variation.id || ''));
                                        $li.append(document.createTextNode(' ($' + (variation.price || '0.00') + ')'));
                                    });
                                } else {
                                    $variationsTd.text('No variations');
                                }
                            });
                            
                            $catalogContainer.append($table);
                        } else {
                            $catalogContainer.append($('<p>').text('No catalog items found.'));
                        }
                        
                        $('#brcc-square-catalog-container').show();
                    } else {
                        $('#brcc-mapping-result').html(
                            '<div class="notice notice-error"><p>' + 
                            (response.data && response.data.message ? response.data.message : 'Failed to fetch catalog') + 
                            '</p></div>'
                        ).show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    $button.prop('disabled', false).text('View Square Catalog');
                    $('#brcc-mapping-result').html(
                        '<div class="notice notice-error"><p>' + brcc_admin.ajax_error + '</p></div>'
                    ).show();
                }
            });
        });
    }

    /**
     * Initialize inventory sync functionality
     */
    function initializeInventorySync() {
        $('#brcc-sync-now').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            
            if ($button.prop('disabled')) return;
            
            const originalText = $button.text();
            
            $button.text(brcc_admin.syncing || 'Syncing...').prop('disabled', true);
            
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
                        alert(response.data && response.data.message ? response.data.message : brcc_admin.ajax_error);
                        $button.text(originalText).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    alert(brcc_admin.ajax_error);
                    $button.text(originalText).prop('disabled', false);
                }
            });
        });
    }

    /**
     * Initialize product mappings save functionality
     */
    function initializeProductMappings() {
        // Direct binding for immediate elements
        $('#brcc-save-mappings').on('click', function(e) {
            handleSaveMappingsClick(e, $(this));
        });
        
        // Delegated binding for dynamically added elements
        $(document).on('click', '#brcc-save-mappings', function(e) {
            handleSaveMappingsClick(e, $(this));
        });
    }

    /**
     * Handle saving product mappings
     * @param {Event} e - Click event
     * @param {jQuery} $button - Button that was clicked
     */
    function handleSaveMappingsClick(e, $button) {
        e.preventDefault();
        e.stopPropagation();
        
        if ($button.prop('disabled') || $button.data('processing')) {
            return;
        }
        
        $button.data('processing', true);
        $button.prop('disabled', true).text(brcc_admin.saving || 'Saving...');
        
        const mappings = {};
        
        $('#brcc-product-mapping-table input[name^="brcc_product_mappings"], #brcc-product-mapping-table select[name^="brcc_product_mappings"]').each(function() {
            const $input = $(this);
            const name = $input.attr('name');
            const matches = name.match(/brcc_product_mappings\[(\d+)\]\[([^\]]+)\]/);
            
            if (matches && matches.length === 3) {
                const productId = matches[1];
                const field = matches[2];
                
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
                    $('#brcc-mapping-result').empty().append(
                        $('<div>').addClass('notice notice-success').append(
                            $('<p>').text(response.data && response.data.message ? response.data.message : 'Mappings saved successfully')
                        )
                    ).show();
                } else {
                    $('#brcc-mapping-result').empty().append(
                        $('<div>').addClass('notice notice-error').append(
                            $('<p>').text(response.data && response.data.message ? response.data.message : 'Error saving mappings')
                        )
                    ).show();
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error:', status, error);
                $('#brcc-mapping-result').empty().append(
                    $('<div>').addClass('notice notice-error').append(
                        $('<p>').text(brcc_admin.ajax_error || 'Error saving mappings')
                    )
                ).show();
            },
            complete: function() {
                $button.prop('disabled', false).text(brcc_admin.save_mappings || 'Save Mappings');
                
                setTimeout(function() {
                    $button.data('processing', false);
                }, 1000);
                
                setTimeout(function() {
                    $('#brcc-mapping-result').fadeOut();
                }, 5000);
            }
        });
    }

    /**
     * Initialize product mapping test functionality
     */
    function initializeProductMappingTest() {
        $(document).on('click', '.brcc-test-mapping', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            if ($button.prop('disabled')) return;
            
            const productId = $button.data('product-id');
            
            $button.prop('disabled', true).text(brcc_admin.testing || 'Testing...');
            
            const eventbriteId = $('select[name="brcc_product_mappings[' + productId + '][eventbrite_id]"]').val() || 
                                 $('input[name="brcc_product_mappings[' + productId + '][manual_eventbrite_id]"]').val();
            const eventbriteEventId = $('select[name="brcc_product_mappings[' + productId + '][eventbrite_event_id]"]').val();
            
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_test_product_mapping',
                    nonce: brcc_admin.nonce,
                    product_id: productId,
                    eventbrite_id: eventbriteId,
                    eventbrite_event_id: eventbriteEventId
                },
                success: function(response) {
                    if (response.success) {
                        $('#brcc-mapping-result').empty().append(
                            $('<div>').addClass('notice notice-success').append(
                                $('<p>').html(response.data.message)
                            )
                        ).show();
                    } else {
                        $('#brcc-mapping-result').empty().append(
                            $('<div>').addClass('notice notice-error').append(
                                $('<p>').html(response.data && response.data.message ? response.data.message : 'Test failed'))
                            ).show();
                        }
                        
                        $button.prop('disabled', false).text(brcc_admin.test || 'Test');
                        
                        setTimeout(function() {
                            $('#brcc-mapping-result').fadeOut();
                        }, 5000);
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        $('#brcc-mapping-result').empty().append(
                            $('<div>').addClass('notice notice-error').append(
                                $('<p>').text(brcc_admin.ajax_error || 'AJAX error occurred')
                            )
                        ).show();
                        $button.prop('disabled', false).text(brcc_admin.test || 'Test');
                    }
                });
            });
        }
    
        /**
         * Initialize Square mapping test functionality
         */
        function initializeSquareMappingTest() {
            $('.brcc-test-square-mapping').on('click', function(e) {
                e.preventDefault();
                
                const $button = $(this);
                if ($button.prop('disabled')) return;
                
                const productId = $button.data('product-id');
                
                $button.prop('disabled', true).text(brcc_admin.testing || 'Testing...');
                
                const squareId = $('input[name="brcc_product_mappings[' + productId + '][square_id]"]').val();
                
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
                            $('#brcc-mapping-result').html('<div class="notice notice-error"><p>' + 
                                (response.data && response.data.message ? response.data.message : 'Test failed') + 
                                '</p></div>').show();
                        }
                        
                        $button.prop('disabled', false).text('Test Square');
                        
                        setTimeout(function() {
                            $('#brcc-mapping-result').fadeOut();
                        }, 5000);
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        $('#brcc-mapping-result').empty().append(
                            $('<div>').addClass('notice notice-error').append(
                                $('<p>').text(brcc_admin.ajax_error || 'AJAX error occurred')
                            )
                        ).show();
                        $button.prop('disabled', false).text('Test Square');
                    }
                });
            });
        }
    
        /**
         * Initialize date range filter functionality
         */
        function initializeDateRangeFilter() {
            $('#brcc-filter-date-range').on('click', function(e) {
                e.preventDefault();
                const startDate = $('#brcc-start-date').val();
                const endDate = $('#brcc-end-date').val();
                
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
        }
    
        /**
         * Initialize Reset Today's Sales functionality
         */
        function initializeResetTodaysSales() {
            $(document).on('click', '#brcc-reset-todays-sales', function(e) {
                e.preventDefault();
                
                const $button = $(this);
                if ($button.prop('disabled')) return;
                
                if (!confirm('Are you sure you want to reset all sales data recorded for today? This cannot be undone.')) {
                    return;
                }
                
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
                            alert(response.data.message || 'Sales data for today has been reset.');
                            window.location.reload();
                        } else {
                            alert(response.data && response.data.message ? response.data.message : 'Error resetting data.');
                           $button.prop('disabled', false).text('Reset Today\'s Sales');
                       }
                   },
                   error: function(xhr, status, error) {
                       console.error('AJAX Error:', status, error);
                       alert(brcc_admin.ajax_error || 'AJAX error.');
                       $button.prop('disabled', false).text('Reset Today\'s Sales');
                   }
               });
           });
       }
   
       /**
        * Initialize attendee list functionality
        */
       function initializeAttendeeLists() {
           console.log('Initializing attendee lists functionality');
           
           // Source filter change event
           $('#brcc-attendee-source-filter').on('change', function() {
               const selectedDate = $('#brcc-attendee-date-select').val();
               if (selectedDate) {
                   $('#brcc-attendee-list-container').html('<p class="brcc-loading-message">Reloading products for ' + selectedDate + ' with filter...</p>');
                   loadProductsForDate(selectedDate);
               }
           });

           // Add explicit change handler for the date selector
           $('#brcc-attendee-date-select').off('change').on('change', function() {
               const selectedDate = $(this).val();
               console.log('Date selected:', selectedDate);
               
               if (selectedDate) {
                   $('#brcc-attendee-list-container').html('<p class="brcc-loading-message">Loading products for ' + selectedDate + '...</p>');
                   loadProductsForDate(selectedDate);
               } else {
                   // Clear the list if the date is cleared
                   $('#brcc-attendee-list-container').html('<p class="brcc-initial-message">' + (brcc_admin.select_date_prompt || 'Please select a date to load attendee lists.') + '</p>');
               }
           });

           // Reinitialize the datepicker to ensure the onSelect event works properly
           if ($('#brcc-attendee-date-select').length) {
               $('#brcc-attendee-date-select').datepicker('destroy').datepicker({
                   dateFormat: 'yy-mm-dd',
                   changeMonth: true,
                   changeYear: true,
                   onSelect: function(dateText) {
                       if (!dateText) {
                           $('#brcc-attendee-list-container').html('<p class="brcc-initial-message">' + (brcc_admin.select_date_prompt || 'Please select a date to load attendee lists.') + '</p>');
                           return;
                       }
                       
                       // Show loading
                       $('#brcc-attendee-list-container').html('<p class="brcc-loading-message">Loading products for ' + dateText + '...</p>');
                       
                       // Load products for this date
                       loadProductsForDate(dateText);
                   }
               });
           }
           
           // Handle refresh button clicks - use delegated events for dynamically added content
           $(document).off('click', '.brcc-refresh-attendees').on('click', '.brcc-refresh-attendees', function() {
               const $button = $(this);
               if ($button.prop('disabled')) return;
               
               const productId = $button.data('product-id');
               const selectedDate = $button.data('date');
               const $targetBlock = $button.closest('.brcc-attendee-product-block');
               
               $button.prop('disabled', true).text(brcc_admin.fetching || 'Fetching...');
               
               if (productId && selectedDate) {
                   fetchAttendeesForProductDate(productId, selectedDate, 1, $targetBlock);
               }
           });
           
           // Handle pagination clicks - use delegated events for dynamically added content
           $(document).off('click', '.brcc-pagination a').on('click', '.brcc-pagination a', function(e) {
               e.preventDefault();
               
               const $link = $(this);
               if ($link.hasClass('disabled')) return;
               
               const $targetBlock = $link.closest('.brcc-attendee-product-block');
               const productId = $targetBlock.data('product-id');
               const page = $link.data('page');
               const selectedDate = $targetBlock.find('.brcc-refresh-attendees').data('date');
               
               if (!productId || !page || !selectedDate) {
                   return;
               }
               
               fetchAttendeesForProductDate(productId, selectedDate, page, $targetBlock);
           });
           
           // Handle pagination input changes - use delegated events for dynamically added content
           $(document).off('change', '.brcc-pagination input.current-page').on('change', '.brcc-pagination input.current-page', function() {
               const $input = $(this);
               const $targetBlock = $input.closest('.brcc-attendee-product-block');
               const productId = $targetBlock.data('product-id');
               let page = parseInt($input.val(), 10);
               const totalPages = parseInt($targetBlock.find('.total-pages').text(), 10) || 1;
               const selectedDate = $targetBlock.find('.brcc-refresh-attendees').data('date');
               
               if (isNaN(page) || page < 1) page = 1;
               if (page > totalPages) page = totalPages;
               
               $input.val(page);
               
               if (productId && selectedDate) {
                   fetchAttendeesForProductDate(productId, selectedDate, page, $targetBlock);
               }
           });
       }
   
       /**
        * Load products for a specific date (Attendee List page)
        * @param {string} selectedDate - Date in YYYY-MM-DD format
        */
       function loadProductsForDate(selectedDate) {
           console.log('Loading products for date:', selectedDate);
           
           // Create unique request ID
           const requestId = `products_${selectedDate}`;
           
           // Cancel if already loading
           if (activeRequests.has(requestId)) {
               return;
           }
           
           $('#brcc-attendee-list-container').html('<p class="brcc-loading-message">Loading products for ' + selectedDate + '...</p>');
           
           // Add to active requests
           activeRequests.add(requestId);
           
           const sourceFilter = $('#brcc-attendee-source-filter').val();
           
           $.ajax({
               url: brcc_admin.ajax_url,
               type: 'POST',
               data: {
                   action: 'brcc_get_products_for_date',
                   nonce: brcc_admin.nonce,
                   selected_date: selectedDate,
                   source_filter: sourceFilter
               },
               success: function(response) {
                   console.log('Products response:', response);
                   
                   if (response.success && response.data && Array.isArray(response.data.products)) {
                       const products = response.data.products;
                       
                       $('#brcc-attendee-list-container').empty();
                       
                       if (products.length > 0) {
                           products.forEach(function(product) {
                               if (!product || !product.product_id || !product.product_name) return;
                               
                               addAttendeeBlock(product.product_id, product.product_name, selectedDate);
                               fetchAttendeesForProductDate(product.product_id, selectedDate, 1, $('#attendees-for-' + product.product_id));
                           });
                       } else {
                           $('#brcc-attendee-list-container').html(
                               '<p class="brcc-no-attendees-message">No products found with events scheduled for ' + selectedDate + '.</p>'
                           );
                       }
                   } else {
                       const errorMsg = (response.data && response.data.message) ? 
                           response.data.message : 'Error loading products for the selected date.';
                       
                       $('#brcc-attendee-list-container').html('<p class="brcc-error-message">' + errorMsg + '</p>');
                   }
               },
               error: function(xhr, status, error) {
                   console.error('AJAX Error:', status, error);
                   const errorMsg = brcc_admin.ajax_error || 'AJAX Error loading products.';
                   $('#brcc-attendee-list-container').html('<p class="brcc-error-message">' + errorMsg + '</p>');
               },
               complete: function() {
                   // Remove from active requests
                   activeRequests.delete(requestId);
               }
           });
       }
   
       /**
        * Add attendee block for a product
        * @param {number} productId - Product ID
        * @param {string} productName - Product name
        * @param {string} selectedDate - Selected date
        */
       function addAttendeeBlock(productId, productName, selectedDate) {
           const containerId = 'attendees-for-' + productId;
           
           // If already exists, don't duplicate
           if ($('#' + containerId).length > 0) {
               return;
           }
           
           const $productBlock = $('<div/>', {
               'id': containerId,
               'class': 'brcc-attendee-product-block',
               'data-product-id': productId
           });
           
           const $header = $('<div/>', {'class': 'brcc-attendee-product-block-header'})
               .append($('<h3/>').text(productName))
               .append($('<button/>', {
                   'type': 'button',
                   'class': 'button button-secondary brcc-refresh-attendees',
                   'data-product-id': productId,
                   'data-date': selectedDate,
                   'text': 'Refresh'
               }));
           
           const $tableContainer = $('<div/>', {'class': 'brcc-attendee-table-container'})
               .html('<p class="brcc-loading-message">Loading attendees...</p>');
           
           $productBlock.append($header).append($tableContainer);
           $('#brcc-attendee-list-container').append($productBlock);
           
           // Fetch attendees immediately after adding the block
           fetchAttendeesForProductDate(productId, selectedDate, 1, $tableContainer);
       }
   
       /**
        * Fetch attendees for a product on a specific date
        * @param {number} productId - Product ID
        * @param {string} selectedDate - Date in YYYY-MM-DD format
        * @param {number} page - Page number
        * @param {jQuery} $targetBlock - Target element to update
        */
       function fetchAttendeesForProductDate(productId, selectedDate, page, $targetBlock) {
           console.log('Fetching attendees:', {productId, selectedDate, page, targetBlock: $targetBlock});
           
           // Create unique request ID
           const requestId = `attendees_${productId}_${selectedDate}_${page}`;
           
           // Cancel if already loading
           if (activeRequests.has(requestId)) {
               return;
           }
           
           const $tableContainer = $targetBlock.find('.brcc-attendee-table-container');
           const $refreshButton = $targetBlock.find('.brcc-refresh-attendees');
           
           $tableContainer.html('<p class="brcc-loading-message">' + (brcc_admin.loading_attendees || 'Loading attendee data...') + '</p>');
           $refreshButton.prop('disabled', true).text(brcc_admin.fetching || 'Fetching...');
           
           const sourceFilter = $('#brcc-attendee-source-filter').val();
           
           // Add to active requests
           activeRequests.add(requestId);
           
           $.ajax({
               url: brcc_admin.ajax_url,
               type: 'POST',
               data: {
                   action: 'brcc_fetch_product_attendees_for_date',
                   nonce: brcc_admin.nonce,
                   product_id: productId,
                   selected_date: selectedDate,
                   page: page,
                   source_filter: sourceFilter
               },
               success: function(response) {
                   console.log('Attendees response:', response);
                   $refreshButton.prop('disabled', false).text('Refresh');
                   
                   if (response.success && response.data) {
                       const attendees = response.data.attendees || [];
                       const totalAttendees = response.data.total_attendees || 0;
                       const totalPages = response.data.total_pages || 1;
                       
                       if (attendees.length === 0) {
                           $tableContainer.html('<p class="brcc-no-attendees-message">' + 
                               (brcc_admin.no_attendees || 'No attendees found for this date and product.') + '</p>');
                           return;
                       }
                       
                       let tableHtml = '<div class="brcc-attendee-count">' + 
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
                       
                       $.each(attendees, function(i, attendee) {
                           if (!attendee) return; // Skip invalid entries
                           
                           tableHtml += '<tr' + (i % 2 === 0 ? ' class="alternate"' : '') + '>' +
                               '<td>' + escapeHtml(attendee.name || 'N/A') + '</td>' +
                               '<td>' + escapeHtml(attendee.email || 'N/A') + '</td>' +
                               '<td>' + escapeHtml(attendee.purchase_date || 'N/A') + '</td>' +
                               '<td>' + escapeHtml(attendee.order_ref || 'N/A') + '</td>' +
                               '<td>' + escapeHtml(attendee.status || 'N/A') + '</td>' +
                               '<td>' + escapeHtml(attendee.source || 'Unknown') + '</td>' +
                               '</tr>';
                       });
                       
                       tableHtml += '</tbody></table>';
                       
                       if (totalPages > 1) {
                           tableHtml += buildPaginationControls(page, totalPages, productId, selectedDate);
                       }
                       
                       $tableContainer.html(tableHtml);
                   } else {
                       const errorMsg = (response.data && response.data.message) ? 
                           response.data.message : 'Error loading attendees. Please try again.';
                           
                       $tableContainer.html('<p class="brcc-error-message">' + errorMsg + '</p>');
                   }
               },
               error: function(xhr, status, error) {
                   console.error('AJAX Error:', status, error);
                   $refreshButton.prop('disabled', false).text('Refresh');
                   
                   const errorMsg = 'AJAX Error loading attendees. Please try again.';
                   $tableContainer.html('<p class="brcc-error-message">' + errorMsg + '</p>');
               },
               complete: function() {
                   // Remove from active requests
                   activeRequests.delete(requestId);
               }
           });
       }
   
       /**
        * Build pagination controls HTML
        * @param {number} currentPage - Current page number
        * @param {number} totalPages - Total number of pages
        * @param {number} productId - Product ID
        * @param {string} selectedDate - Selected date
        * @returns {string} HTML for pagination controls
        */
       function buildPaginationControls(currentPage, totalPages, productId, selectedDate) {
           let paginationHtml = '<div class="brcc-pagination">';
           
           // Previous button
           if (currentPage > 1) {
               paginationHtml += '<a href="#" class="brcc-pagination-link" data-page="' + (currentPage - 1) + 
                   '" data-product-id="' + productId + '" data-date="' + selectedDate + '">« Previous</a>';
           } else {
               paginationHtml += '<span class="brcc-pagination-disabled">« Previous</span>';
           }
           
           // Page numbers
           const startPage = Math.max(1, currentPage - 2);
           const endPage = Math.min(totalPages, startPage + 4);
           
           for (let i = startPage; i <= endPage; i++) {
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
        * Initialize import controls
        */
       function initializeImportControls() {
           if ($('#brcc-start-import').length) {
               const $startButton = $('#brcc-start-import');
               const $wcCheckbox = $('#brcc-import-source-wc');
               const $sqCheckbox = $('#brcc-import-source-sq');
               const $ebCheckbox = $('#brcc-import-source-eb');
               
               // Initialize date pickers for import range
               $('#brcc-import-start-date, #brcc-import-end-date').datepicker({
                   dateFormat: 'yy-mm-dd',
                   changeMonth: true,
                   changeYear: true,
                   maxDate: 0
               });
               
               const importLogContainer = $('#brcc-import-log');
               const importProgressBar = $('#brcc-import-progress-bar');
               const importStatusMessage = $('#brcc-import-status-message');
               const importCompleteButton = $('#brcc-import-complete');
               let importInProgress = false;
               
               // Function to add log messages with memory management
               function addImportLog(message, type) {
                   // Limit log entries to avoid memory issues
                   if (importLogContainer.children().length > 500) {
                       // If too many entries, remove the oldest 100
                       importLogContainer.find('div:lt(100)').remove();
                   }
                   
                   const $logEntry = $('<div>').css('color', type === 'error' ? 'red' : (type === 'warning' ? 'orange' : 'inherit')).text(message);
                   importLogContainer.append($logEntry);
                   
                   // Scroll to bottom
                   const container = importLogContainer[0];
                   if (container) {
                       // Use requestAnimationFrame for smoother scrolling
                       window.requestAnimationFrame(function() {
                           container.scrollTop = container.scrollHeight;
                       });
                   }
               }
               
               // Function to process an import batch
               function processImportBatch(state) {
                   if (!importInProgress) return;
                   
                   state.action = 'brcc_import_batch';
                   state.nonce = $('input[name="brcc_import_nonce"]').val();
                   
                   $.ajax({
                       url: brcc_admin.ajax_url,
                       type: 'POST',
                       data: {
                           state_data: state
                       },
                       success: function(response) {
                           if (!importInProgress) return;
                           
                           if (response.success) {
                               // Append logs
                               if (response.data.logs && response.data.logs.length > 0) {
                                   response.data.logs.forEach(function(log) {
                                       if (log && log.message) {
                                           addImportLog(log.message, log.type || 'info');
                                       }
                                   });
                               }
                               
                               // Update progress
                               const progress = response.data.progress || 0;
                               importProgressBar.val(progress);
                               importStatusMessage.text(response.data.message || 'Processing...');
                               
                               // Process next batch or complete
                               if (response.data.next_state !== null && response.data.next_state !== undefined) {
                                   // Introduce slight delay to prevent overwhelming the server
                                   setTimeout(function() {
                                       processImportBatch(response.data.next_state);
                                   }, 250);
                               } else {
                                   addImportLog('Import completed!', 'success');
                                   importStatusMessage.text('Import completed!');
                                   importProgressBar.val(100);
                                   importCompleteButton.show();
                                   $startButton.prop('disabled', false).text('Start Import');
                                   importInProgress = false;
                               }
                           } else {
                               addImportLog('Error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error during import.'), 'error');
                               importStatusMessage.text('Import failed. Check log for details.');
                               importCompleteButton.show();
                               $startButton.prop('disabled', false).text('Start Import');
                               importInProgress = false;
                           }
                       },
                       error: function(xhr, status, error) {
                           if (!importInProgress) return;
                           
                           console.error('AJAX Error:', status, error);
                           
                           addImportLog('AJAX Error: ' + status + ' - ' + error, 'error');
                           importStatusMessage.text('Import failed due to network or server error.');
                           importCompleteButton.show();
                           $startButton.prop('disabled', false).text('Start Import');
                           importInProgress = false;
                       }
                   });
               }
               
               // Start Import button click
               $startButton.on('click', function() {
                   const $button = $(this);
                   
                   if ($button.prop('disabled')) return;
                   
                   const startDate = $('#brcc-import-start-date').val();
                   const endDate = $('#brcc-import-end-date').val();
                   const sources = $('input[name="brcc_import_sources[]"]:checked').map(function() {
                       return $(this).val();
                   }).get();
                   
                   if (!startDate || !endDate) {
                       alert('Please select both a start and end date.');
                       return;
                   }
                   
                   if (sources.length === 0) {
                       alert('Please select at least one data source (WooCommerce, Square, or Eventbrite).');
                       return;
                   }
                   
                   // Check for unconfigured sources
                   const isSqConfigured = !$sqCheckbox.siblings('span').length;
                   const isEbConfigured = !$ebCheckbox.siblings('span').length;
                   
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
                   importLogContainer.html('');
                   importProgressBar.val(0);
                   importStatusMessage.text('Starting import...');
                   importCompleteButton.hide();
                   importInProgress = true;
                   $('#brcc-import-status').show();
                   addImportLog('Starting import for ' + sources.join(', ') + ' from ' + startDate + ' to ' + endDate + '...');
                   
                   // Start the first batch
                   const initialState = {
                       start_date: startDate,
                       end_date: endDate,
                       sources: sources,
                       source_index: 0,
                       wc_offset: 0,
                       square_cursor: null,
                       eventbrite_page: 1,
                       total_processed: 0,
                       progress_total: 100
                   };
                   
                   processImportBatch(initialState);
               });
               
               // Import Complete button click
               importCompleteButton.on('click', function() {
                   $('#brcc-import-status').hide();
                   importInProgress = false;
               });
           }
       }
   
       /**
        * Utility function to escape HTML for safe output
        * @param {string} str - String to escape
        * @returns {string} Escaped string
        */
       function escapeHtml(str) {
           if (typeof str !== 'string') return '';
           return str
               .replace(/&/g, '&amp;')
               .replace(/</g, '&lt;')
               .replace(/>/g, '&gt;')
               .replace(/"/g, '&quot;')
               .replace(/'/g, '&#039;');
       }
   
       /**
        * Utility function to debounce function calls
        * @param {Function} func - Function to debounce
        * @param {number} wait - Wait time in ms
        * @returns {Function} Debounced function
        */
       function debounce(func, wait) {
           let timeout;
           return function(...args) {
               const later = () => {
                   clearTimeout(timeout);
                   func(...args);
               };
               clearTimeout(timeout);
               timeout = setTimeout(later, wait);
           };
       }
   
       /**
        * Optimize DOM operations by batching them
        * @param {Function} operations - Function containing DOM operations
        */
       function batchDOMOperations(operations) {
           window.requestAnimationFrame(() => {
               operations();
           });
       }
   
/**
     * Initialize the Force Sync Inventory debugging tool
     */
// --- Point 5: Add New Functions ---

    /**
     * Ensure Eventbrite events are loaded and available
     * Useful for populating dropdowns when the data might not be available
     */
    function ensureEventbriteEventsLoaded() {
        // Check if we already have events data and it's not empty
        if (typeof brcc_admin !== 'undefined' &&
            brcc_admin.eventbrite_events &&
            !$.isEmptyObject(brcc_admin.eventbrite_events)) {
            // console.log('Eventbrite events already loaded.'); // Optional debug
            return; // We already have events
        }

        console.log('Eventbrite events not found or empty in global data, fetching them now...');

        // Basic check for required admin data
        if (typeof brcc_admin === 'undefined' || !brcc_admin.ajax_url || !brcc_admin.nonce) {
             console.error('Cannot fetch events: brcc_admin object or required properties missing.');
             // Optionally display an error to the user
             $('<div class="notice notice-error is-dismissible"><p>Error: Plugin admin data is missing. Cannot load Eventbrite events. Please contact support.</p></div>')
                .insertAfter('.wp-heading-inline').first(); // Insert after main page title
             return;
        }


        // Create a loading indicator (optional, could be annoying if it flashes)
        // const $loadingMsg = $('<div class="notice notice-warning is-dismissible"><p>Loading Eventbrite events data...</p></div>');
        // $('#wpbody-content .wrap').first().prepend($loadingMsg); // Prepend inside the main wrap

        // Fetch events using the correct AJAX action if available
        // Note: 'brcc_get_all_eventbrite_events_for_attendees' might not be the right action
        // We need an action that just returns the cached/fetched event list used for localization
        // Let's assume 'brcc_get_cached_events' is a hypothetical correct action for now.
        // If this action doesn't exist, it needs to be created in PHP.
        // OR, we rely solely on the localized data and show an error if it's missing.
        // For now, let's stick to checking the localized data and log an error if missing.

         if (typeof brcc_admin === 'undefined' || !brcc_admin.eventbrite_events || $.isEmptyObject(brcc_admin.eventbrite_events)) {
              console.error('Eventbrite events data is missing from localized script object (brcc_admin.eventbrite_events). Cannot populate dropdowns dynamically.');
               // Display a persistent error message if events are critical
             // $('<div class="notice notice-error is-dismissible"><p>Error: Could not load Eventbrite event list. Event dropdowns may be empty. Please ensure the Eventbrite connection is working and try clearing the Eventbrite cache in settings.</p></div>')
             //    .insertAfter('.wp-heading-inline').first();
         }

        /* // AJAX Call - Keep commented unless a dedicated action exists
        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_get_cached_events', // Hypothetical action
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                // if ($loadingMsg.length) $loadingMsg.remove();

                if (response.success && response.data && response.data.events && !$.isEmptyObject(response.data.events)) {
                    // Make the events data available globally
                    if (typeof brcc_admin === 'undefined') {
                        window.brcc_admin = {};
                    }

                    brcc_admin.eventbrite_events = response.data.events;
                    console.log('Successfully loaded Eventbrite events data via AJAX');

                    // Reinitialize the dropdowns with the new data
                    setTimeout(function() {
                        $('.brcc-eventbrite-event-id-select, .brcc-date-event').each(function() { // Use correct selectors
                            populateEventDropdown($(this));
                            initializeSelect2(this);
                        });
                    }, 100);
                } else {
                    console.error('Failed to load Eventbrite events via AJAX:', response);
                    $('<div class="notice notice-error is-dismissible"><p>Failed to load Eventbrite events data via AJAX. Try refreshing the page or clearing the cache.</p></div>')
                        .insertAfter('.wp-heading-inline').first();
                }
            },
            error: function(xhr, status, error) {
                // if ($loadingMsg.length) $loadingMsg.remove();
                console.error('AJAX Error loading events:', status, error);
                 $('<div class="notice notice-error is-dismissible"><p>AJAX Error loading Eventbrite events data. Please check browser console and network tab.</p></div>')
                        .insertAfter('.wp-heading-inline').first();
            }
        });
        */
    }
/**
 * Fetch Eventbrite events data and update dropdowns
 * This can be called at any time to ensure we have the events data
 */
/**
 * Fetch Eventbrite events data and update dropdowns (for admin.js)
 */
function fetchEventbriteEvents(callback) {
    // Check if we already have events data in brcc_admin
    if (typeof brcc_admin !== 'undefined' && 
        typeof brcc_admin.eventbrite_events !== 'undefined' &&
        Object.keys(brcc_admin.eventbrite_events).length > 0) {
        console.log('admin.js: Using existing Eventbrite events from brcc_admin');
        if (typeof callback === 'function') {
            callback(brcc_admin.eventbrite_events);
        }
        return;
    }

    // Otherwise, fetch via AJAX - ensure we pass the selected date parameter
    $.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'brcc_get_all_eventbrite_events_for_attendees', 
            nonce: brcc_admin.nonce,
            selected_date: new Date().toISOString().split('T')[0] // Today's date in YYYY-MM-DD format
        },
        success: function(response) {
            console.log('admin.js: AJAX response (fetch_eventbrite_events):', response);

            if (response.success && response.data && response.data.events) {
                console.log('admin.js: Fetched Eventbrite events:', response.data.events);
// Add this near the beginning of your jQuery(document).ready function in admin.js
if (typeof brcc_admin !== 'undefined' && 
    (!brcc_admin.eventbrite_events || Object.keys(brcc_admin.eventbrite_events).length === 0)) {
    console.log('admin.js: No Eventbrite events found in brcc_admin, fetching now...');
    // Fetch Eventbrite events data
    fetchEventbriteEvents(function(eventbriteEvents) {
        // You might need to add code here to update any admin.js-specific dropdowns
        // if they're different from the ones in date-mappings.js
        console.log('admin.js: Events fetched and brcc_admin.eventbrite_events updated');
    });
}
                // Update the global events object if brcc_admin exists
                if (typeof brcc_admin !== 'undefined') {
                     brcc_admin.eventbrite_events = response.data.events;
                } else {
                     // Handle case where brcc_admin might not be defined yet
                     window.brcc_admin = { eventbrite_events: response.data.events };
                }

                // Call the callback if provided
                if (typeof callback === 'function') {
                    callback(response.data.events);
                }
            } else {
                console.error('admin.js: Failed to fetch Eventbrite events:', response);
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
            console.error('admin.js: AJAX error fetching Eventbrite events:', status, error);
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
 * Update date-specific Eventbrite event dropdowns with available events data
 */
function updateDateSpecificEventDropdowns() {
    var eventOptions = brcc_admin.eventbrite_events || {};
    
    // Skip if no events available
    if (Object.keys(eventOptions).length === 0) {
        console.log('No Eventbrite events available to populate dropdowns');
        return;
    }
    
    // Store options on all date content containers
    $('.brcc-dates-content').each(function() {
        $(this).data('brcc-event-options', JSON.stringify(eventOptions));
    });
    
    // Update all visible date event dropdowns
    $('.brcc-date-event:visible').each(function() {
        var $select = $(this);
        var currentValue = $select.val();
        
        // Clear existing options except first (placeholder)
        $select.find('option:not(:first)').remove();
        
        // Add new options
        $.each(eventOptions, function(id, name) {
            var $option = $('<option></option>')
                .attr('value', id)
                .text(name);
                
            if (id === currentValue) {
                $option.attr('selected', 'selected');
            }
            
            $select.append($option);
        });
        
        // Reinitialize SelectWoo/Select2
        initializeSelectDropdowns($select);
    });
    
    console.log('Date-specific event dropdowns updated with Eventbrite data');
}
    /**
     * Populate an event dropdown with available events from brcc_admin.eventbrite_events
     * @param {jQuery} $dropdown - The dropdown element
     */
    function populateEventDropdown($dropdown) {
        if (!$dropdown || !$dropdown.length) {
             // console.warn('populateEventDropdown: Invalid dropdown element provided.'); // Optional debug
             return;
        }

        // Save the current value to restore it after populating
        const currentValue = $dropdown.val();
        const currentSelectedText = $dropdown.find('option:selected').text(); // Get text too for comparison
        $dropdown.empty().append($('<option value="">' + (brcc_admin.select_event_prompt || 'Select Event...') + '</option>')); // Add default empty option

        // Check if events are available in the localized object
        if (typeof brcc_admin !== 'undefined' &&
            brcc_admin.eventbrite_events &&
            !$.isEmptyObject(brcc_admin.eventbrite_events)) {

            // Add each event as an option
            $.each(brcc_admin.eventbrite_events, function(eventId, eventName) {
                $dropdown.append($('<option></option>')
                    .attr('value', eventId)
                    .text(eventName)); // eventName should be pre-formatted by PHP
            });

            // Restore the previously selected value if it exists in the new options
            if (currentValue && $dropdown.find('option[value="' + currentValue + '"]').length) {
                 $dropdown.val(currentValue);
            }
             // Optional: Fallback check using text if value changed but text is same (less reliable)
            // else if (currentSelectedText && $dropdown.find('option').filter(function() { return $(this).text() === currentSelectedText; }).length) {
            //     $dropdown.find('option').filter(function() { return $(this).text() === currentSelectedText; }).prop('selected', true);
            // }


        } else {
            // console.warn('populateEventDropdown: No Eventbrite events found in brcc_admin.eventbrite_events.'); // Optional debug
            $dropdown.append($('<option value="" disabled>No events available</option>'));
        }
         // Trigger change event in case Select2 needs to update its display
         $dropdown.trigger('change.select2');
    }
    /**
     * Initialize inventory alerts functionality
     * Handles the sync error resolution for products
     */
    function initializeInventoryAlerts() {
        // Handle sync error resolution
        $(document).on('click', '.brcc-sync-product', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const productId = $button.data('product-id');
            
            if (!productId) {
                console.error('No product ID found for sync button');
                return;
            }
            
            // Disable button and show loading state
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update brcc-spin"></span> Syncing...');
            
            // Get the alert item container
            const $alertItem = $button.closest('.brcc-alert-item');
            
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_sync_product_inventory',
                    nonce: brcc_admin.nonce,
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $alertItem.fadeOut(300, function() {
                            // Check if there are no more alerts
                            if ($('.brcc-alert-item').length <= 1) {
                                // Show the "no alerts" message
                                const $noAlertsMsg = $('<div class="brcc-empty-state">' +
                                    '<span class="dashicons dashicons-yes-alt"></span>' +
                                    '<p>' + (brcc_admin.no_alerts_message || 'No inventory alerts at this time.') + '</p>' +
                                    '</div>');
                                
                                $alertItem.replaceWith($noAlertsMsg);
                            } else {
                                // Just remove this alert
                                $alertItem.remove();
                            }
                            
                            // Show notification
                            $('<div class="notice notice-success is-dismissible"><p>' +
                                (response.data.message || 'Product synced successfully!') +
                                '</p></div>')
                                .insertAfter('.brcc-dashboard-header')
                                .delay(3000)
                                .fadeOut(500, function() {
                                    $(this).remove();
                                });
                        });
                    } else {
                        // Show error and reset button
                        $button.prop('disabled', false).text('Retry');
                        
                        // Show error notification
                        $('<div class="notice notice-error is-dismissible"><p>' +
                            (response.data.message || 'Error syncing product.') +
                            '</p></div>')
                            .insertAfter('.brcc-dashboard-header')
                            .delay(5000)
                            .fadeOut(500, function() {
                                $(this).remove();
                            });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    
                    // Reset button
                    $button.prop('disabled', false).text('Retry');
                    
                    // Show error notification
                    $('<div class="notice notice-error is-dismissible"><p>AJAX Error: ' +
                        error + '</p></div>')
                        .insertAfter('.brcc-dashboard-header')
                        .delay(5000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                }
            });
        });
        
        // Handle card refresh for inventory alerts
        $(document).on('click', '[data-card="inventory-alerts"]', function() {
            // This is already handled by the general card refresh functionality
            // Just adding specific behavior if needed in the future
        });
    }

    function initializeForceSyncTool() {
        const $toolContainer = $('#brcc-force-sync-tool');
        if (!$toolContainer.length) {
            return; // Tool not present on this page
        }

        const $productSelect = $toolContainer.find('#brcc_force_sync_product_id');
        const $dateInput = $toolContainer.find('#brcc_force_sync_date');
        const $button = $toolContainer.find('#brcc-force-sync-button');
        const $spinner = $toolContainer.find('.spinner');
        const $resultDiv = $toolContainer.find('#brcc-force-sync-result');

        // Initialize WC Product Search Select2/SelectWoo if the function exists
        // Ensure brcc_admin object and search_products_nonce are available via wp_localize_script
        if (typeof $productSelect.selectWoo === 'function' && typeof brcc_admin !== 'undefined' && brcc_admin.search_products_nonce) {
             try {
                $productSelect.selectWoo({
                    ajax: {
                        url: ajaxurl, // Global WordPress AJAX URL
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return {
                                term: params.term,
                                action: 'woocommerce_json_search_products_and_variations', // WC AJAX action
                                security: brcc_admin.search_products_nonce
                            };
                        },
                        processResults: function(data) {
                            const terms = [];
                            if (data) {
                                $.each(data, function(id, text) {
                                    terms.push({ id: id, text: text });
                                });
                            }
                            return { results: terms };
                        },
                        cache: true
                    },
                    minimumInputLength: 3,
                    placeholder: $productSelect.data('placeholder') || 'Search for a product...',
                    allowClear: true,
                    width: '100%' // Ensure proper width
                });
            } catch (e) {
                console.error("Error initializing SelectWoo for Force Sync Product:", e);
                 if (typeof $productSelect.select2 === 'function') { // Fallback to basic select2
                     $productSelect.select2({ width: '100%' });
                 }
            }
        } else {
             console.warn("SelectWoo function or required nonce not available for product search.");
             // Fallback to basic select2 if available
             if (typeof $productSelect.select2 === 'function') {
                 $productSelect.select2({ width: '100%' });
             }
        }


        $button.on('click', function() {
            const productId = $productSelect.val();
            const eventDate = $dateInput.val();

            // Basic validation using localized strings if available
            const selectProductAlert = typeof brcc_admin !== 'undefined' && brcc_admin.select_product_alert ? brcc_admin.select_product_alert : 'Please select a product.';
            const selectDateAlert = typeof brcc_admin !== 'undefined' && brcc_admin.select_date_alert ? brcc_admin.select_date_alert : 'Please select an event date.';
            const forceSyncConfirm = typeof brcc_admin !== 'undefined' && brcc_admin.force_sync_confirm ? brcc_admin.force_sync_confirm : 'Are you sure you want to force sync inventory for this product/date? This will overwrite existing WC/EB counts based on recorded sales.';
            const ajaxError = typeof brcc_admin !== 'undefined' && brcc_admin.ajax_error ? brcc_admin.ajax_error : 'An AJAX error occurred.';


            if (!productId) {
                alert(selectProductAlert);
                return;
            }
            if (!eventDate) {
                alert(selectDateAlert);
                return;
            }

            if (!confirm(forceSyncConfirm)) {
                return;
            }

            $button.prop('disabled', true);
            $spinner.addClass('is-active').css('visibility', 'visible');
            $resultDiv.html('').removeClass('notice-success notice-error notice').addClass('notice notice-warning').html('<p>Processing...</p>').show(); // Show processing message

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_sync_inventory_now',
                    nonce: brcc_admin.nonce, // Ensure this nonce is localized
                    product_id: productId,
                    event_date: eventDate
                },
                success: function(response) {
                    $resultDiv.removeClass('notice-warning'); // Remove processing style
                    if (response.success) {
                        $resultDiv.addClass('notice notice-success').html('<p>' + response.data.message + '</p>');
                    } else {
                        $resultDiv.addClass('notice notice-error').html('<p>' + (response.data && response.data.message ? response.data.message : ajaxError) + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Force Sync AJAX Error:', status, error);
                     $resultDiv.removeClass('notice-warning'); // Remove processing style
                    $resultDiv.addClass('notice notice-error').html('<p>' + ajaxError + '</p>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active').css('visibility', 'hidden');
                     // Optionally hide the result message after a delay
                     setTimeout(function() { $resultDiv.fadeOut(); }, 10000);
                }
            });
        });
    } // end initializeForceSyncTool
   })(jQuery);
   