<?php
/**
 * Daily Sales page template
 * 
 * @var string $selected_date Selected date
 * @var array $sales_data Sales data for the selected date
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap brcc-daily-sales-wrap">
    <h1><?php _e('BRCC Daily Sales Report', 'brcc-inventory-tracker'); ?></h1>

    <?php if (BRCC_Helpers::is_test_mode()): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('Test Mode is ENABLED', 'brcc-inventory-tracker'); ?></strong>
                <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
                <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
            </p>
        </div>
    <?php endif; ?>

    <!-- Header Controls -->
    <div class="brcc-page-header-container">
        <div class="brcc-page-header">
            <div class="brcc-date-selector">
                <label for="brcc-date-picker"><?php _e('Select Date:', 'brcc-inventory-tracker'); ?></label>
                <input type="text" id="brcc-date-picker" class="brcc-date-picker brcc-datepicker" value="<?php echo esc_attr($selected_date); ?>" />
                <button type="button" class="button button-secondary" id="brcc-go-to-today"><?php _e('Today', 'brcc-inventory-tracker'); ?></button>
            </div>
            <div class="brcc-page-actions">
                <div class="brcc-action-buttons">
                    <button type="button" class="button button-primary" id="brcc-print-report">
                        <span class="dashicons dashicons-printer"></span> <?php _e('Print Report', 'brcc-inventory-tracker'); ?>
                    </button>
                    <a href="<?php echo add_query_arg(array('action' => 'brcc_export_csv', 'date' => $selected_date, 'nonce' => wp_create_nonce('brcc-export-csv'))); ?>" class="button button-secondary">
                        <span class="dashicons dashicons-database-export"></span> <?php _e('Export CSV', 'brcc-inventory-tracker'); ?>
                    </a>
                    <button type="button" class="button button-secondary" id="brcc-reset-todays-sales" <?php echo $selected_date !== current_time('Y-m-d') ? 'disabled' : ''; ?>>
                        <span class="dashicons dashicons-trash"></span> <?php _e('Reset Today\'s Sales', 'brcc-inventory-tracker'); ?>
                    </button>
                </div>
            </div>
        </div>
        
        <div class="brcc-comparison-row">
            <div class="brcc-comparison-selector">
                <label for="brcc-comparison-type"><?php _e('Compare with:', 'brcc-inventory-tracker'); ?></label>
                <select id="brcc-comparison-type" class="brcc-comparison-select">
                    <option value="none"><?php _e('No comparison', 'brcc-inventory-tracker'); ?></option>
                    <option value="previous_day"><?php _e('Previous day', 'brcc-inventory-tracker'); ?></option>
                    <option value="previous_week"><?php _e('Same day last week', 'brcc-inventory-tracker'); ?></option>
                    <option value="previous_month"><?php _e('Same day last month', 'brcc-inventory-tracker'); ?></option>
                </select>
            </div>
        </div>
    </div>

    <?php if (empty($sales_data)): ?>
        <div class="brcc-dashboard-card">
             <div class="brcc-card-content brcc-empty-state">
                 <span class="dashicons dashicons-info-outline"></span>
                 <p><?php _e('No sales data available for the selected date.', 'brcc-inventory-tracker'); ?></p>
                 <p><?php echo sprintf(__('Select a different date or wait for sales to be recorded for %s.', 'brcc-inventory-tracker'), date_i18n(get_option('date_format'), strtotime($selected_date))); ?></p>
             </div>
        </div>
    <?php else: ?>
        <?php
        // Calculate summary statistics
        $total_sales = 0;
        $total_revenue = 0.0;
        $unique_products = array();
        $sales_by_source = array();
        $sales_by_hour = array_fill(0, 24, 0); // Initialize hours 0-23

        foreach ($sales_data as $sale) {
            $quantity = isset($sale['quantity']) ? (int)$sale['quantity'] : 0;
            $revenue = isset($sale['revenue']) ? (float)$sale['revenue'] : 0.0;
            $source = isset($sale['source']) ? sanitize_text_field($sale['source']) : 'Unknown';
            $product_id = isset($sale['product_id']) ? $sale['product_id'] : null;
            $timestamp = isset($sale['timestamp']) ? (int)$sale['timestamp'] : null;

            $total_sales += $quantity;
            $total_revenue += $revenue;
            if ($product_id) {
                $unique_products[$product_id] = true;
            }

            // Aggregate sales by source
            if (!isset($sales_by_source[$source])) {
                $sales_by_source[$source] = 0;
            }
            $sales_by_source[$source] += $quantity;

            // Aggregate sales by hour
            if ($timestamp) {
                $hour = (int)date('G', $timestamp); // 'G' for 24-hour format without leading zeros
                if (isset($sales_by_hour[$hour])) {
                    $sales_by_hour[$hour] += $quantity;
                }
            }
        }
        ?>

        <!-- Summary Metrics Row -->
        <div class="brcc-metrics-row">
            <div class="brcc-metric-card">
                <div class="brcc-metric-icon"><span class="dashicons dashicons-chart-bar"></span></div>
                <div class="brcc-metric-content">
                    <h3><?php _e('Total Sales', 'brcc-inventory-tracker'); ?></h3>
                    <div class="brcc-metric-value"><?php echo esc_html($total_sales); ?></div>
                </div>
            </div>
            <div class="brcc-metric-card">
                 <div class="brcc-metric-icon"><span class="dashicons dashicons-money-alt"></span></div>
                 <div class="brcc-metric-content">
                    <h3><?php _e('Total Revenue', 'brcc-inventory-tracker'); ?></h3>
                    <div class="brcc-metric-value"><?php echo wc_price($total_revenue); ?></div>
                 </div>
            </div>
            <div class="brcc-metric-card">
                 <div class="brcc-metric-icon"><span class="dashicons dashicons-products"></span></div>
                 <div class="brcc-metric-content">
                    <h3><?php _e('Unique Products Sold', 'brcc-inventory-tracker'); ?></h3>
                    <div class="brcc-metric-value"><?php echo esc_html(count($unique_products)); ?></div>
                 </div>
            </div>
             <div class="brcc-metric-card">
                 <div class="brcc-metric-icon"><span class="dashicons dashicons-cart"></span></div>
                 <div class="brcc-metric-content">
                    <h3><?php _e('Avg. Order Value', 'brcc-inventory-tracker'); ?></h3>
                    <div class="brcc-metric-value"><?php echo $total_sales > 0 ? wc_price($total_revenue / $total_sales) : wc_price(0); ?></div>
                 </div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="brcc-dashboard-grid brcc-charts-row">
            <div class="brcc-dashboard-card brcc-sales-by-source-card">
                <div class="brcc-card-header">
                    <h2><span class="dashicons dashicons-networking"></span> <?php _e('Sales by Source', 'brcc-inventory-tracker'); ?></h2>
                    <div class="brcc-card-actions">
                        <button type="button" class="brcc-card-refresh" data-card="sales-by-source">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                    </div>
                </div>
                <div class="brcc-card-content">
                    <canvas id="brcc-sales-by-source-chart" height="250"></canvas>
                </div>
            </div>
            <div class="brcc-dashboard-card brcc-sales-by-hour-card">
                <div class="brcc-card-header">
                    <h2><span class="dashicons dashicons-clock"></span> <?php _e('Sales by Hour', 'brcc-inventory-tracker'); ?></h2>
                    <div class="brcc-card-actions">
                        <button type="button" class="brcc-card-refresh" data-card="sales-by-hour">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                    </div>
                </div>
                <div class="brcc-card-content">
                    <canvas id="brcc-sales-by-hour-chart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Charts Row 2 -->
        <div class="brcc-dashboard-grid brcc-charts-row">
            <div class="brcc-dashboard-card brcc-sales-by-category-card">
                <div class="brcc-card-header">
                    <h2><span class="dashicons dashicons-category"></span> <?php _e('Sales by Category', 'brcc-inventory-tracker'); ?></h2>
                    <div class="brcc-card-actions">
                        <button type="button" class="brcc-card-refresh" data-card="sales-by-category">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                    </div>
                </div>
                <div class="brcc-card-content">
                    <canvas id="brcc-sales-by-category-chart" height="250"></canvas>
                </div>
            </div>
            <div class="brcc-dashboard-card brcc-comparison-card">
                <div class="brcc-card-header">
                    <h2><span class="dashicons dashicons-chart-line"></span> <?php _e('Sales Comparison', 'brcc-inventory-tracker'); ?></h2>
                    <div class="brcc-card-actions">
                        <button type="button" class="brcc-card-refresh" data-card="sales-comparison">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                    </div>
                </div>
                <div class="brcc-card-content">
                    <div id="brcc-comparison-placeholder" class="brcc-empty-state">
                        <span class="dashicons dashicons-chart-bar"></span>
                        <p><?php _e('Select a comparison period from the dropdown above to see comparative data.', 'brcc-inventory-tracker'); ?></p>
                    </div>
                    <canvas id="brcc-comparison-chart" height="250" style="display: none;"></canvas>
                </div>
            </div>
        </div>

        <!-- Sales Details Table -->
        <div class="brcc-dashboard-card brcc-sales-details-card">
            <div class="brcc-card-header">
                <h2><span class="dashicons dashicons-list-view"></span> <?php echo sprintf(__('Sales Details for %s', 'brcc-inventory-tracker'), date_i18n(get_option('date_format'), strtotime($selected_date))); ?></h2>
                <div class="brcc-card-actions">
                    <button type="button" class="brcc-card-refresh" data-card="sales-details">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>
            <div class="brcc-card-content">
                <!-- Search and Filter Controls -->
                <div class="brcc-table-filters">
                    <div class="brcc-search-box">
                        <input type="text" id="brcc-sales-search" placeholder="<?php _e('Search products...', 'brcc-inventory-tracker'); ?>" class="regular-text">
                        <button type="button" id="brcc-sales-search-clear" class="button button-secondary">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="brcc-filter-controls">
                        <select id="brcc-source-filter" class="brcc-filter-select">
                            <option value=""><?php _e('All Sources', 'brcc-inventory-tracker'); ?></option>
                            <?php
                            // Get unique sources from sales data
                            $sources = array();
                            foreach ($sales_data as $sale) {
                                if (!empty($sale['source']) && !in_array($sale['source'], $sources)) {
                                    $sources[] = $sale['source'];
                                }
                            }
                            sort($sources);
                            foreach ($sources as $source) {
                                echo '<option value="' . esc_attr($source) . '">' . esc_html($source) . '</option>';
                            }
                            ?>
                        </select>
                        
                        <select id="brcc-sort-by" class="brcc-filter-select">
                            <option value="time"><?php _e('Sort by Time', 'brcc-inventory-tracker'); ?></option>
                            <option value="product"><?php _e('Sort by Product', 'brcc-inventory-tracker'); ?></option>
                            <option value="quantity"><?php _e('Sort by Quantity', 'brcc-inventory-tracker'); ?></option>
                            <option value="revenue"><?php _e('Sort by Revenue', 'brcc-inventory-tracker'); ?></option>
                        </select>
                    </div>
                </div>
                
                <div class="brcc-table-responsive">
                    <table class="wp-list-table widefat fixed striped brcc-sales-details-table">
                        <thead>
                            <tr>
                                <th scope="col" class="manage-column column-time"><?php _e('Time', 'brcc-inventory-tracker'); ?></th>
                                <th scope="col" class="manage-column column-product"><?php _e('Product', 'brcc-inventory-tracker'); ?></th>
                                <th scope="col" class="manage-column column-quantity num"><?php _e('Quantity', 'brcc-inventory-tracker'); ?></th>
                                <th scope="col" class="manage-column column-revenue num"><?php _e('Revenue', 'brcc-inventory-tracker'); ?></th>
                                <th scope="col" class="manage-column column-source"><?php _e('Source', 'brcc-inventory-tracker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sales_data as $sale): ?>
                                <tr>
                                    <td class="column-time">
                                        <?php
                                        if (!empty($sale['timestamp'])) {
                                            echo date_i18n(get_option('time_format'), $sale['timestamp']);
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td class="column-product">
                                        <?php
                                        if (!empty($sale['product_id'])) {
                                            $product = wc_get_product($sale['product_id']);
                                            if ($product) {
                                                echo '<a href="' . esc_url(get_edit_post_link($sale['product_id'])) . '">' . esc_html($product->get_name()) . '</a>';
                                            } else {
                                                echo esc_html($sale['product_id']);
                                            }
                                        } else {
                                            echo esc_html($sale['product_name'] ?? __('Unknown', 'brcc-inventory-tracker'));
                                        }
                                        ?>
                                    </td>
                                    <td class="column-quantity num"><?php echo esc_html($sale['quantity']); ?></td>
                                    <td class="column-revenue num"><?php echo wc_price($sale['revenue']); ?></td>
                                    <td class="column-source">
                                         <span class="brcc-source-badge brcc-source-<?php echo sanitize_html_class(strtolower($sale['source'])); ?>">
                                            <?php echo esc_html($sale['source']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // Show notification when page loads with a date parameter
    <?php if (isset($_GET['date'])): ?>
    $('<div class="notice notice-info is-dismissible"><p>' +
        '<?php echo sprintf(__('Viewing sales data for %s', 'brcc-inventory-tracker'), date_i18n(get_option('date_format'), strtotime($selected_date))); ?>' +
        '</p></div>')
        .insertAfter('.brcc-page-header-container')
        .delay(3000)
        .fadeOut(500, function() {
            $(this).remove();
        });
    <?php endif; ?>
    // Initialize date picker
    $('#brcc-date-picker').datepicker({
        dateFormat: 'yy-mm-dd',
        onSelect: function(dateText) {
            console.log('Date selected:', dateText); // Debug log
            // Force redirect to the page with the selected date
            window.location.href = '<?php echo admin_url('admin.php?page=brcc-daily-sales'); ?>&date=' + dateText;
        }
    });
    
    // Also handle manual input in the date field
    $('#brcc-date-picker').on('change', function() {
        var dateText = $(this).val();
        if (dateText) {
            console.log('Date changed manually:', dateText); // Debug log
            window.location.href = '<?php echo admin_url('admin.php?page=brcc-daily-sales'); ?>&date=' + dateText;
        }
    });
    
    // Go to today button
    $('#brcc-go-to-today').on('click', function() {
        window.location.href = '<?php echo admin_url('admin.php?page=brcc-daily-sales'); ?>';
    });
    
    // Reset today's sales button
    $('#brcc-reset-todays-sales').on('click', function() {
        if (confirm('<?php _e('Are you sure you want to reset today\'s sales data? This cannot be undone.', 'brcc-inventory-tracker'); ?>')) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'brcc_reset_todays_sales',
                    nonce: brcc_admin.nonce
                },
                beforeSend: function() {
                    $('#brcc-reset-todays-sales').prop('disabled', true)
                        .html('<span class="dashicons dashicons-update brcc-spin"></span> <?php _e('Resetting...', 'brcc-inventory-tracker'); ?>');
                },
                success: function(response) {
                    if (response.success) {
                        // Show success notification instead of alert
                        $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>')
                            .insertAfter('.brcc-page-header')
                            .delay(3000)
                            .fadeOut(500, function() {
                                $(this).remove();
                            });
                        
                        // Reload after a short delay to show the notification
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        // Show error notification instead of alert
                        $('<div class="notice notice-error is-dismissible"><p>' +
                            (response.data.message || brcc_admin.ajax_error) + '</p></div>')
                            .insertAfter('.brcc-page-header')
                            .delay(5000)
                            .fadeOut(500, function() {
                                $(this).remove();
                            });
                            
                        // Reset button with correct text and icon
                        $('#brcc-reset-todays-sales').prop('disabled', false)
                            .html('<span class="dashicons dashicons-trash"></span> <?php _e('Reset Today\'s Sales', 'brcc-inventory-tracker'); ?>');
                    }
                },
                error: function() {
                    // Show error notification instead of alert
                    $('<div class="notice notice-error is-dismissible"><p>' + brcc_admin.ajax_error + '</p></div>')
                        .insertAfter('.brcc-page-header')
                        .delay(5000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                        
                    // Reset button with correct text and icon
                    $('#brcc-reset-todays-sales').prop('disabled', false)
                        .html('<span class="dashicons dashicons-trash"></span> <?php _e('Reset Today\'s Sales', 'brcc-inventory-tracker'); ?>');
                }
            });
        }
    });

    <?php if (!empty($sales_data)): ?>
    // Initialize Charts
    if (typeof Chart !== 'undefined') {
        // Common chart colors
        var backgroundColors = [
            'rgba(54, 162, 235, 0.7)', // Blue
            'rgba(255, 99, 132, 0.7)', // Red
            'rgba(255, 206, 86, 0.7)', // Yellow
            'rgba(75, 192, 192, 0.7)', // Teal
            'rgba(153, 102, 255, 0.7)', // Purple
            'rgba(255, 159, 64, 0.7)', // Orange
            'rgba(199, 199, 199, 0.7)'  // Grey
        ];
        var borderColors = backgroundColors.map(color => color.replace('0.7', '1'));

        // --- Sales by Source Chart ---
        var sourceCtx = document.getElementById('brcc-sales-by-source-chart');
        if (sourceCtx) {
            var sourceData = <?php echo json_encode(array_values($sales_by_source)); ?>;
            var sourceLabels = <?php echo json_encode(array_keys($sales_by_source)); ?>;

            new Chart(sourceCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: sourceLabels,
                    datasets: [{
                        data: sourceData,
                        backgroundColor: backgroundColors.slice(0, sourceLabels.length),
                        borderColor: borderColors.slice(0, sourceLabels.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var label = context.label || '';
                                    var value = context.parsed || 0;
                                    var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    var percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return label + ': ' + value + ' sales (' + percentage + '%)';
                                }
                            }
                        }
                    },
                    cutout: '60%'
                }
            });
        } else {
            console.error("Canvas element #brcc-sales-by-source-chart not found.");
        }

        // --- Sales by Hour Chart ---
        var hourCtx = document.getElementById('brcc-sales-by-hour-chart');
        if (hourCtx) {
            var hourLabels = [];
            for (var h = 0; h < 24; h++) {
                hourLabels.push(h + ':00'); // Simple hour labels
            }
            var hourData = <?php echo json_encode(array_values($sales_by_hour)); ?>;

            new Chart(hourCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: hourLabels,
                    datasets: [{
                        label: '<?php _e('Sales Count', 'brcc-inventory-tracker'); ?>',
                        data: hourData,
                        backgroundColor: 'rgba(75, 192, 192, 0.6)', // Teal
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                             callbacks: {
                                label: function(context) {
                                    return context.parsed.y + ' sales';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0 // Ensure whole numbers for sales count
                            }
                        },
                        x: {
                             grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        } else {
             console.error("Canvas element #brcc-sales-by-hour-chart not found.");
        }

    } else {
        console.error("Chart.js library not loaded.");
    }
    <?php endif; ?>
    
    // Print Report functionality
    $('#brcc-print-report').on('click', function() {
        window.print();
    });
    
    // Card refresh buttons
    $('.brcc-card-refresh').on('click', function() {
        var $button = $(this);
        var cardType = $button.data('card');
        
        $button.addClass('brcc-spin');
        
        // Make a real AJAX call to refresh the specific card data
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_refresh_dashboard_card',
                nonce: brcc_admin.nonce,
                card_type: cardType,
                date: '<?php echo esc_js($selected_date); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // Show success notification
                    $('<div class="notice notice-success is-dismissible"><p>' +
                        (response.data.message || '<?php _e('Data refreshed!', 'brcc-inventory-tracker'); ?>') +
                        '</p></div>')
                        .insertAfter('.brcc-page-header')
                        .delay(2000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                        
                    // If no specific HTML was returned, refresh the page
                    if (!response.data || !response.data.html) {
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    }
                } else {
                    // Show error notification
                    $('<div class="notice notice-error is-dismissible"><p>' +
                        (response.data.message || '<?php _e('Error refreshing data.', 'brcc-inventory-tracker'); ?>') +
                        '</p></div>')
                        .insertAfter('.brcc-page-header')
                        .delay(3000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                }
            },
            error: function() {
                // Show error notification
                $('<div class="notice notice-error is-dismissible"><p><?php _e('AJAX Error: Could not refresh data.', 'brcc-inventory-tracker'); ?></p></div>')
                    .insertAfter('.brcc-page-header')
                    .delay(3000)
                    .fadeOut(500, function() {
                        $(this).remove();
                    });
            },
            complete: function() {
                // Reset button state
                $button.removeClass('brcc-spin');
            }
        });
    });
    
    // Table search and filter functionality
    $('#brcc-sales-search').on('keyup', function() {
        filterSalesTable();
    });
    
    $('#brcc-sales-search-clear').on('click', function() {
        $('#brcc-sales-search').val('').focus();
        filterSalesTable();
    });
    
    $('#brcc-source-filter, #brcc-sort-by').on('change', function() {
        filterSalesTable();
    });
    
    function filterSalesTable() {
        var searchText = $('#brcc-sales-search').val().toLowerCase();
        var sourceFilter = $('#brcc-source-filter').val();
        var sortBy = $('#brcc-sort-by').val();
        
        // Get all rows
        var $rows = $('.brcc-sales-details-table tbody tr');
        
        // First filter rows based on search and source filter
        $rows.each(function() {
            var $row = $(this);
            var productText = $row.find('.column-product').text().toLowerCase();
            var sourceText = $row.find('.column-source').text().toLowerCase();
            
            var matchesSearch = searchText === '' || productText.indexOf(searchText) > -1;
            var matchesSource = sourceFilter === '' || sourceText.indexOf(sourceFilter.toLowerCase()) > -1;
            
            if (matchesSearch && matchesSource) {
                $row.show();
            } else {
                $row.hide();
            }
        });
        
        // Then sort visible rows
        var $visibleRows = $rows.filter(':visible').detach();
        
        $visibleRows.sort(function(a, b) {
            var aValue, bValue;
            
            switch(sortBy) {
                case 'product':
                    aValue = $(a).find('.column-product').text().toLowerCase();
                    bValue = $(b).find('.column-product').text().toLowerCase();
                    return aValue.localeCompare(bValue);
                    
                case 'quantity':
                    aValue = parseInt($(a).find('.column-quantity').text(), 10);
                    bValue = parseInt($(b).find('.column-quantity').text(), 10);
                    return bValue - aValue; // Descending order
                    
                case 'revenue':
                    // Extract numeric value from price string (remove currency symbol)
                    aValue = parseFloat($(a).find('.column-revenue').text().replace(/[^0-9.-]+/g, ''));
                    bValue = parseFloat($(b).find('.column-revenue').text().replace(/[^0-9.-]+/g, ''));
                    return bValue - aValue; // Descending order
                    
                case 'time':
                default:
                    // Default sort by time (assuming time is in first column)
                    aValue = $(a).find('.column-time').text();
                    bValue = $(b).find('.column-time').text();
                    return aValue.localeCompare(bValue);
            }
        });
        
        // Append sorted rows back to table
        $('.brcc-sales-details-table tbody').append($visibleRows);
    }
    
    // Comparison dropdown handling
    $('#brcc-comparison-type').on('change', function() {
        var comparisonType = $(this).val();
        var $dropdown = $(this);
        
        // Add visual feedback to the dropdown
        $dropdown.addClass('brcc-dropdown-active');
        
        // Show immediate feedback message
        $('<div class="notice notice-info is-dismissible"><p>' +
            '<?php _e('Comparison type changed to: ', 'brcc-inventory-tracker'); ?>' +
            $dropdown.find('option:selected').text() +
            '</p></div>')
            .insertAfter('.brcc-page-header')
            .delay(2000)
            .fadeOut(500, function() {
                $(this).remove();
            });
        
        if (comparisonType === 'none') {
            $('#brcc-comparison-placeholder').show();
            $('#brcc-comparison-chart').hide();
            $dropdown.removeClass('brcc-dropdown-active');
            return;
        }
        
        // Show loading state with more visible indicator
        $('#brcc-comparison-placeholder').html('<div class="brcc-loading"><span class="dashicons dashicons-update brcc-spin"></span> <strong><?php _e('Loading comparison data...', 'brcc-inventory-tracker'); ?></strong></div>').show();
        $('#brcc-comparison-chart').hide();
        
        console.log('Fetching comparison data for: ' + comparisonType); // Debug log
        
        // Make AJAX call to get comparison data
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'brcc_get_sales_comparison',
                nonce: brcc_admin.nonce,
                date: '<?php echo esc_js($selected_date); ?>',
                comparison_type: comparisonType
            },
            success: function(response) {
                if (response.success && response.data) {
                    // Hide placeholder and show chart
                    $('#brcc-comparison-placeholder').hide();
                    $('#brcc-comparison-chart').show();
                    
                    // Initialize comparison chart with the data
                    initComparisonChart(response.data);
                    
                    // Show success notification
                    $('<div class="notice notice-success is-dismissible"><p>' +
                        '<?php _e('Comparison data loaded successfully!', 'brcc-inventory-tracker'); ?>' +
                        '</p></div>')
                        .insertAfter('.brcc-page-header')
                        .delay(2000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                } else {
                    // Show error message in placeholder
                    $('#brcc-comparison-placeholder').html('<div class="brcc-error-message">' +
                        (response.data.message || '<?php _e('Error loading comparison data.', 'brcc-inventory-tracker'); ?>') +
                        '</div>').show();
                    $('#brcc-comparison-chart').hide();
                    console.error('Error response:', response); // Debug log
                    
                    // Show error notification
                    $('<div class="notice notice-error is-dismissible"><p>' +
                        (response.data.message || '<?php _e('Error loading comparison data.', 'brcc-inventory-tracker'); ?>') +
                        '</p></div>')
                        .insertAfter('.brcc-page-header')
                        .delay(3000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                }
                
                // Remove active class from dropdown
                $('#brcc-comparison-type').removeClass('brcc-dropdown-active');
            },
            error: function(xhr, status, error) {
                // Show error message in placeholder
                $('#brcc-comparison-placeholder').html('<div class="brcc-error-message">' +
                    '<?php _e('AJAX Error: Could not load comparison data.', 'brcc-inventory-tracker'); ?> (' + status + ')' +
                    '</div>').show();
                $('#brcc-comparison-chart').hide();
                console.error('AJAX error:', status, error, xhr.responseText); // Debug log
                
                // Show error notification
                $('<div class="notice notice-error is-dismissible"><p>' +
                    '<?php _e('AJAX Error: Could not load comparison data.', 'brcc-inventory-tracker'); ?>' +
                    '</p></div>')
                    .insertAfter('.brcc-page-header')
                    .delay(3000)
                    .fadeOut(500, function() {
                        $(this).remove();
                    });
                    
                // Remove active class from dropdown
                $('#brcc-comparison-type').removeClass('brcc-dropdown-active');
            },
            error: function() {
                // Show error message in placeholder
                $('#brcc-comparison-placeholder').html('<div class="brcc-error-message"><?php _e('AJAX Error: Could not load comparison data.', 'brcc-inventory-tracker'); ?></div>');
            }
        });
    });
    
    function initComparisonChart(data) {
        var ctx = document.getElementById('brcc-comparison-chart').getContext('2d');
        
        // Destroy existing chart if it exists
        if (window.comparisonChart) {
            window.comparisonChart.destroy();
        }
        
        // Create new chart
        window.comparisonChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: data.labels || [],
                datasets: [
                    {
                        label: data.current_label || '<?php _e('Current', 'brcc-inventory-tracker'); ?>',
                        data: data.current_data || [],
                        backgroundColor: 'rgba(54, 162, 235, 0.7)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: data.comparison_label || '<?php _e('Comparison', 'brcc-inventory-tracker'); ?>',
                        data: data.comparison_data || [],
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }
    
    <?php if (!empty($sales_data)): ?>
    // Initialize Sales by Category Chart
    if (typeof Chart !== 'undefined') {
        var categoryCtx = document.getElementById('brcc-sales-by-category-chart');
        if (categoryCtx) {
            // In a real implementation, you would calculate this data from the sales data
            // For now, we'll use placeholder data
            var categoryData = [15, 12, 8, 5, 3];
            var categoryLabels = ['Category A', 'Category B', 'Category C', 'Category D', 'Category E'];
            
            new Chart(categoryCtx.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        data: categoryData,
                        backgroundColor: backgroundColors.slice(0, categoryLabels.length),
                        borderColor: borderColors.slice(0, categoryLabels.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var label = context.label || '';
                                    var value = context.parsed || 0;
                                    var total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    var percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return label + ': ' + value + ' sales (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
        } else {
            console.error("Canvas element #brcc-sales-by-category-chart not found.");
        }
    }
    <?php endif; ?>
});
</script>
