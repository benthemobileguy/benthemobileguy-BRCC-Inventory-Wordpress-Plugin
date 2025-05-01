<?php
/**
 * Attendee List page template
 * 
 * @var string $selected_date Selected date
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap brcc-attendee-lists-wrap">
    <div class="brcc-page-header-container">
        <div class="brcc-page-header">
            <h1><?php _e('BRCC Attendee Lists', 'brcc-inventory-tracker'); ?></h1>
            
            <div class="brcc-header-actions">
                <button type="button" id="brcc-refresh-attendees" class="button button-secondary">
                    <span class="dashicons dashicons-update"></span> <?php _e('Refresh', 'brcc-inventory-tracker'); ?>
                </button>
                <button type="button" id="brcc-export-attendees" class="button button-primary">
                    <span class="dashicons dashicons-download"></span> <?php _e('Export to CSV', 'brcc-inventory-tracker'); ?>
                </button>
            </div>
        </div>
        
        <?php if (BRCC_Helpers::is_test_mode()): ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php _e('Test Mode is ENABLED', 'brcc-inventory-tracker'); ?></strong>
                    <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
                    <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
                </p>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="brcc-controls-container">
        <div class="brcc-controls-row">
            <div class="brcc-date-selector">
                <label for="brcc-date-picker"><?php _e('Date:', 'brcc-inventory-tracker'); ?></label>
                <input type="text" id="brcc-date-picker" class="brcc-date-picker brcc-datepicker" value="<?php echo esc_attr($selected_date); ?>" />
                <button type="button" class="button button-secondary" id="brcc-go-to-today"><?php _e('Today', 'brcc-inventory-tracker'); ?></button>
            </div>
            
            <div class="brcc-filters">
                <div class="brcc-source-filter">
                    <label for="brcc-source-filter"><?php _e('Source:', 'brcc-inventory-tracker'); ?></label>
                    <select id="brcc-source-filter" class="brcc-filter-select">
                        <option value="all"><?php _e('All Sources', 'brcc-inventory-tracker'); ?></option>
                        <option value="eventbrite"><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></option>
                        <option value="woocommerce"><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></option>
                    </select>
                </div>
                
                <div class="brcc-search-box">
                    <label for="brcc-attendee-search"><?php _e('Search:', 'brcc-inventory-tracker'); ?></label>
                    <input type="text" id="brcc-attendee-search" placeholder="<?php _e('Search attendees...', 'brcc-inventory-tracker'); ?>" class="regular-text">
                    <button type="button" id="brcc-search-clear" class="button button-secondary">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <div class="brcc-attendee-dashboard">
        <div class="brcc-dashboard-cards">
            <div class="brcc-dashboard-card brcc-total-attendees-card">
                <div class="brcc-card-icon">
                    <span class="dashicons dashicons-groups"></span>
                </div>
                <div class="brcc-card-content">
                    <h3><?php _e('Total Attendees', 'brcc-inventory-tracker'); ?></h3>
                    <div id="brcc-total-attendees" class="brcc-card-value">0</div>
                </div>
            </div>
            
            <div class="brcc-dashboard-card brcc-eventbrite-attendees-card">
                <div class="brcc-card-icon">
                    <span class="dashicons dashicons-tickets-alt"></span>
                </div>
                <div class="brcc-card-content">
                    <h3><?php _e('Eventbrite', 'brcc-inventory-tracker'); ?></h3>
                    <div id="brcc-eventbrite-attendees" class="brcc-card-value">0</div>
                </div>
            </div>
            
            <div class="brcc-dashboard-card brcc-woocommerce-attendees-card">
                <div class="brcc-card-icon">
                    <span class="dashicons dashicons-cart"></span>
                </div>
                <div class="brcc-card-content">
                    <h3><?php _e('WooCommerce', 'brcc-inventory-tracker'); ?></h3>
                    <div id="brcc-woocommerce-attendees" class="brcc-card-value">0</div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="brcc-attendee-list-container">
        <div class="brcc-attendee-list-header">
            <h2><?php echo sprintf(__('Attendees for %s', 'brcc-inventory-tracker'), date_i18n(get_option('date_format'), strtotime($selected_date))); ?></h2>
            <div class="brcc-list-actions">
                <span id="brcc-displaying-num" class="brcc-displaying-num"></span>
            </div>
        </div>
        
        <div id="brcc-attendees-loading" class="brcc-loading">
            <span class="spinner is-active"></span>
            <span class="brcc-loading-text"><?php _e('Loading attendees...', 'brcc-inventory-tracker'); ?></span>
        </div>
        
        <div id="brcc-no-attendees" class="brcc-empty-state" style="display: none;">
            <span class="dashicons dashicons-groups"></span>
            <h3><?php _e('No Attendees Found', 'brcc-inventory-tracker'); ?></h3>
            <p><?php _e('There are no attendees for the selected date.', 'brcc-inventory-tracker'); ?></p>
            <p><?php _e('Try selecting a different date or check your event mappings.', 'brcc-inventory-tracker'); ?></p>
        </div>
        
        <div id="brcc-attendees-table-container" style="display: none;">
            <table id="brcc-attendees-table" class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th class="column-name"><?php _e('Name', 'brcc-inventory-tracker'); ?></th>
                        <th class="column-email"><?php _e('Email', 'brcc-inventory-tracker'); ?></th>
                        <th class="column-product"><?php _e('Product/Event', 'brcc-inventory-tracker'); ?></th>
                        <th class="column-purchase-date"><?php _e('Purchase Date', 'brcc-inventory-tracker'); ?></th>
                        <th class="column-order-ref"><?php _e('Order Ref', 'brcc-inventory-tracker'); ?></th>
                        <th class="column-status"><?php _e('Status', 'brcc-inventory-tracker'); ?></th>
                        <th class="column-source"><?php _e('Source', 'brcc-inventory-tracker'); ?></th>
                    </tr>
                </thead>
                <tbody id="brcc-attendees-list">
                    <!-- Attendees will be loaded via AJAX -->
                </tbody>
            </table>
            
            <div id="brcc-attendees-pagination" class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="pagination-links" id="brcc-pagination-links"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Initialize date picker
    $('#brcc-date-picker').datepicker({
        dateFormat: 'yy-mm-dd',
        onSelect: function(dateText) {
            console.log('Date selected:', dateText);
            window.location.href = '<?php echo admin_url('admin.php?page=brcc-attendee-lists'); ?>&date=' + dateText;
        }
    });
    
    // Also handle manual input in the date field
    $('#brcc-date-picker').on('change', function() {
        var dateText = $(this).val();
        if (dateText) {
            console.log('Date changed manually:', dateText);
            window.location.href = '<?php echo admin_url('admin.php?page=brcc-attendee-lists'); ?>&date=' + dateText;
        }
    });
    
    // Go to today button
    $('#brcc-go-to-today').on('click', function() {
        window.location.href = '<?php echo admin_url('admin.php?page=brcc-attendee-lists'); ?>';
    });
    
    // Load all attendees for the selected date on page load
    fetchAllAttendees(1);
    
    // Refresh button
    $('#brcc-refresh-attendees').on('click', function() {
        fetchAllAttendees(1);
    });
    
    // Source filter change
    $('#brcc-source-filter').on('change', function() {
        filterAttendees();
    });
    
    // Search functionality
    $('#brcc-attendee-search').on('keyup', function() {
        filterAttendees();
    });
    
    // Clear search
    $('#brcc-search-clear').on('click', function() {
        $('#brcc-attendee-search').val('').focus();
        filterAttendees();
    });
    
    // Export attendees button
    $('#brcc-export-attendees').on('click', function() {
        var sourceFilter = $('#brcc-source-filter').val();
        var searchTerm = $('#brcc-attendee-search').val();
        
        // Create a form and submit it to download the CSV
        var form = $('<form></form>')
            .attr('action', ajaxurl)
            .attr('method', 'post')
            .attr('target', '_blank');
            
        form.append($('<input></input>')
            .attr('type', 'hidden')
            .attr('name', 'action')
            .attr('value', 'brcc_export_all_attendees_csv'));
            
        form.append($('<input></input>')
            .attr('type', 'hidden')
            .attr('name', 'selected_date')
            .attr('value', '<?php echo esc_js($selected_date); ?>'));
            
        form.append($('<input></input>')
            .attr('type', 'hidden')
            .attr('name', 'source_filter')
            .attr('value', sourceFilter));
            
        form.append($('<input></input>')
            .attr('type', 'hidden')
            .attr('name', 'search_term')
            .attr('value', searchTerm));
            
        form.append($('<input></input>')
            .attr('type', 'hidden')
            .attr('name', 'nonce')
            .attr('value', brcc_admin.nonce));
            
        $('body').append(form);
        form.submit();
        form.remove();
    });
    
    // Store all attendees for client-side filtering
    var allAttendees = [];
    var currentPage = 1;
    var itemsPerPage = 50;
    
    // Function to fetch all attendees for the selected date
    function fetchAllAttendees(page) {
        var selectedDate = '<?php echo esc_js($selected_date); ?>';
        
        $('#brcc-attendees-loading').show();
        $('#brcc-attendees-table-container').hide();
        $('#brcc-no-attendees').hide();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_fetch_all_attendees_for_date',
                selected_date: selectedDate,
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                $('#brcc-attendees-loading').hide();
                
                if (response.success && response.data.attendees && response.data.attendees.length > 0) {
                    // Store all attendees for client-side filtering
                    allAttendees = response.data.attendees;
                    
                    // Update stats
                    $('#brcc-total-attendees').text(response.data.total_attendees);
                    $('#brcc-eventbrite-attendees').text(response.data.eventbrite_attendees);
                    $('#brcc-woocommerce-attendees').text(response.data.woocommerce_attendees);
                    
                    // Apply initial filtering
                    filterAttendees();
                    
                    $('#brcc-attendees-table-container').show();
                } else {
                    $('#brcc-no-attendees').show();
                    
                    // Reset stats
                    $('#brcc-total-attendees').text('0');
                    $('#brcc-eventbrite-attendees').text('0');
                    $('#brcc-woocommerce-attendees').text('0');
                }
            },
            error: function() {
                $('#brcc-attendees-loading').hide();
                $('#brcc-no-attendees').show();
                
                // Reset stats
                $('#brcc-total-attendees').text('0');
                $('#brcc-eventbrite-attendees').text('0');
                $('#brcc-woocommerce-attendees').text('0');
                
                // Show error notification
                $('<div class="notice notice-error is-dismissible"><p>' + 
                    '<?php _e('Error loading attendees. Please try again.', 'brcc-inventory-tracker'); ?>' + 
                    '</p></div>')
                    .insertAfter('.brcc-page-header-container')
                    .delay(3000)
                    .fadeOut(500, function() {
                        $(this).remove();
                    });
            }
        });
    }
    
    // Function to filter attendees client-side
    function filterAttendees() {
        var sourceFilter = $('#brcc-source-filter').val();
        var searchTerm = $('#brcc-attendee-search').val().toLowerCase();
        
        // Apply filters
        var filteredAttendees = allAttendees.filter(function(attendee) {
            // Source filter
            var matchesSource = sourceFilter === 'all' || 
                (sourceFilter === 'eventbrite' && attendee.source.toLowerCase() === 'eventbrite') ||
                (sourceFilter === 'woocommerce' && attendee.source.toLowerCase() === 'woocommerce');
            
            // Search filter
            var matchesSearch = searchTerm === '' || 
                (attendee.name && attendee.name.toLowerCase().indexOf(searchTerm) > -1) ||
                (attendee.email && attendee.email.toLowerCase().indexOf(searchTerm) > -1) ||
                (attendee.product_name && attendee.product_name.toLowerCase().indexOf(searchTerm) > -1);
            
            return matchesSource && matchesSearch;
        });
        
        // Update pagination
        currentPage = 1;
        updatePagination(filteredAttendees.length);
        
        // Display filtered attendees
        displayAttendees(filteredAttendees, currentPage);
    }
    
    // Function to display attendees with pagination
    function displayAttendees(attendees, page) {
        var start = (page - 1) * itemsPerPage;
        var end = start + itemsPerPage;
        var paginatedAttendees = attendees.slice(start, end);
        
        var attendeesList = $('#brcc-attendees-list');
        attendeesList.empty();
        
        if (paginatedAttendees.length > 0) {
            $.each(paginatedAttendees, function(index, attendee) {
                var row = $('<tr></tr>');
                row.append('<td class="column-name">' + (attendee.name || 'N/A') + '</td>');
                row.append('<td class="column-email">' + (attendee.email || 'N/A') + '</td>');
                row.append('<td class="column-product">' + (attendee.product_name || 'N/A') + '</td>');
                row.append('<td class="column-purchase-date">' + (attendee.purchase_date || 'N/A') + '</td>');
                row.append('<td class="column-order-ref">' + (attendee.order_ref || 'N/A') + '</td>');
                row.append('<td class="column-status">' + (attendee.status || 'N/A') + '</td>');
                
                // Add source with badge
                var sourceClass = 'brcc-source-badge brcc-source-' + attendee.source.toLowerCase();
                row.append('<td class="column-source"><span class="' + sourceClass + '">' + attendee.source + '</span></td>');
                
                attendeesList.append(row);
            });
            
            $('#brcc-attendees-table-container').show();
            $('#brcc-no-attendees').hide();
        } else {
            $('#brcc-attendees-table-container').hide();
            $('#brcc-no-attendees').show();
        }
        
        // Update displaying count
        $('#brcc-displaying-num').text(attendees.length + ' <?php _e('items', 'brcc-inventory-tracker'); ?>');
    }
    
    // Function to update pagination
    function updatePagination(totalItems) {
        var totalPages = Math.ceil(totalItems / itemsPerPage);
        
        var paginationLinks = $('#brcc-pagination-links');
        paginationLinks.empty();
        
        if (totalPages > 1) {
            // Previous page
            if (currentPage > 1) {
                paginationLinks.append('<a class="prev-page" href="#" data-page="' + (currentPage - 1) + '">&laquo;</a>');
            } else {
                paginationLinks.append('<span class="tablenav-pages-navspan" aria-hidden="true">&laquo;</span>');
            }
            
            // Page numbers
            for (var i = 1; i <= totalPages; i++) {
                if (i === currentPage) {
                    paginationLinks.append('<span class="tablenav-pages-navspan current" aria-hidden="true">' + i + '</span>');
                } else {
                    paginationLinks.append('<a class="page-numbers" href="#" data-page="' + i + '">' + i + '</a>');
                }
            }
            
            // Next page
            if (currentPage < totalPages) {
                paginationLinks.append('<a class="next-page" href="#" data-page="' + (currentPage + 1) + '">&raquo;</a>');
            } else {
                paginationLinks.append('<span class="tablenav-pages-navspan" aria-hidden="true">&raquo;</span>');
            }
            
            // Add click handlers for pagination links
            paginationLinks.find('a').on('click', function(e) {
                e.preventDefault();
                currentPage = $(this).data('page');
                filterAttendees();
            });
            
            $('#brcc-attendees-pagination').show();
        } else {
            $('#brcc-attendees-pagination').hide();
        }
    }
});
</script>

<style>
/* Attendee List Page Styles */
.brcc-attendee-lists-wrap {
    max-width: 1200px;
    margin: 0 auto;
}

.brcc-page-header-container {
    margin-bottom: 20px;
}

.brcc-page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.brcc-header-actions {
    display: flex;
    gap: 10px;
}

.brcc-controls-container {
    background-color: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.brcc-controls-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
}

.brcc-date-selector {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.brcc-filters {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.brcc-source-filter {
    display: flex;
    align-items: center;
    gap: 5px;
}

.brcc-search-box {
    display: flex;
    align-items: center;
    gap: 5px;
    position: relative;
}

.brcc-search-box input {
    min-width: 200px;
}

.brcc-search-box button {
    padding: 0;
    width: 30px;
    height: 30px;
}

.brcc-attendee-dashboard {
    margin-bottom: 20px;
}

.brcc-dashboard-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.brcc-dashboard-card {
    background-color: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    display: flex;
    align-items: center;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.brcc-card-icon {
    margin-right: 15px;
}

.brcc-card-icon .dashicons {
    font-size: 36px;
    width: 36px;
    height: 36px;
    color: #007cba;
}

.brcc-card-content h3 {
    margin: 0 0 5px 0;
    font-size: 14px;
    color: #23282d;
}

.brcc-card-value {
    font-size: 24px;
    font-weight: 600;
    color: #23282d;
}

.brcc-total-attendees-card .brcc-card-icon .dashicons {
    color: #007cba;
}

.brcc-eventbrite-attendees-card .brcc-card-icon .dashicons {
    color: #f05537;
}

.brcc-woocommerce-attendees-card .brcc-card-icon .dashicons {
    color: #96588a;
}

.brcc-attendee-list-container {
    background-color: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}

.brcc-attendee-list-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.brcc-attendee-list-header h2 {
    margin: 0;
    font-size: 18px;
    color: #23282d;
}

.brcc-list-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.brcc-displaying-num {
    color: #555;
    font-style: italic;
}

.brcc-loading {
    text-align: center;
    padding: 30px;
}

.brcc-loading .spinner {
    float: none;
    margin: 0 10px 0 0;
}

.brcc-loading-text {
    vertical-align: middle;
    color: #555;
}

.brcc-empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #555;
}

.brcc-empty-state .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #a0a5aa;
    margin-bottom: 15px;
}

.brcc-empty-state h3 {
    margin: 0 0 10px 0;
    font-size: 18px;
    color: #23282d;
}

.brcc-empty-state p {
    margin: 0 0 10px 0;
    font-size: 14px;
}

/* Table styles */
.brcc-attendees-table {
    width: 100%;
    border-collapse: collapse;
}

.brcc-attendees-table th {
    text-align: left;
    padding: 8px;
}

.brcc-attendees-table td {
    padding: 8px;
    vertical-align: middle;
}

.brcc-source-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    color: #fff;
    background-color: #999; /* Default */
}

.brcc-source-badge.brcc-source-woocommerce {
    background-color: #96588a;
}

.brcc-source-badge.brcc-source-eventbrite {
    background-color: #f05537;
}

/* Responsive adjustments */
@media screen and (max-width: 782px) {
    .brcc-controls-row {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .brcc-date-selector,
    .brcc-filters {
        width: 100%;
        margin-bottom: 10px;
    }
    
    .brcc-dashboard-cards {
        grid-template-columns: 1fr;
    }
    
    .brcc-attendee-list-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .brcc-list-actions {
        margin-top: 10px;
    }
}
</style>