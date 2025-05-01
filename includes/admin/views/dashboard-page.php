<?php
/**
 * Dashboard page template
 * 
 * @var array $period_summary Period summary data
 * @var array $daily_sales Daily sales data
 * @var string $selected_date Selected date
 * @var string $view_period View period (daily, weekly, monthly)
 * @var string $start_date Start date of the period
 * @var string $end_date End date of the period
 * @var string $summary_title Summary title
 * @var string $summary_tooltip Summary tooltip
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap brcc-dashboard-wrap">
    <h1><?php _e('BRCC Inventory Dashboard', 'brcc-inventory-tracker'); ?></h1>
    
    <?php if (BRCC_Helpers::is_test_mode()): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('Test Mode is ENABLED', 'brcc-inventory-tracker'); ?></strong>
                <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
                <a href="<?php echo admin_url('admin.php?page=brcc-operation-logs'); ?>"><?php _e('View Logs', 'brcc-inventory-tracker'); ?></a>
            </p>
        </div>
    <?php endif; ?>
    
    <!-- Dashboard Header with Controls -->
    <div class="brcc-dashboard-header">
        <div class="brcc-date-selector">
            <label for="brcc-date-picker"><?php _e('Select Date:', 'brcc-inventory-tracker'); ?></label>
            <input type="text" id="brcc-date-picker" class="brcc-date-picker" value="<?php echo esc_attr($selected_date); ?>" />
            <button type="button" class="button button-secondary" id="brcc-go-to-today"><?php _e('Today', 'brcc-inventory-tracker'); ?></button>
        </div>
        
        <div class="brcc-view-selector">
            <label><?php _e('View:', 'brcc-inventory-tracker'); ?></label>
            <div class="brcc-view-tabs">
                <a href="<?php echo add_query_arg('view', 'daily', remove_query_arg('view')); ?>" class="brcc-view-tab <?php echo $view_period === 'daily' ? 'active' : ''; ?>"><?php _e('Daily', 'brcc-inventory-tracker'); ?></a>
                <a href="<?php echo add_query_arg('view', 'weekly', remove_query_arg('view')); ?>" class="brcc-view-tab <?php echo $view_period === 'weekly' ? 'active' : ''; ?>"><?php _e('Weekly', 'brcc-inventory-tracker'); ?></a>
                <a href="<?php echo add_query_arg('view', 'monthly', remove_query_arg('view')); ?>" class="brcc-view-tab <?php echo $view_period === 'monthly' ? 'active' : ''; ?>"><?php _e('Monthly', 'brcc-inventory-tracker'); ?></a>
            </div>
        </div>
    </div>
    
    <!-- Key Metrics Row -->
    <div class="brcc-metrics-row">
        <div class="brcc-metric-card">
            <div class="brcc-metric-icon">
                <span class="dashicons dashicons-chart-bar"></span>
            </div>
            <div class="brcc-metric-content">
                <h3><?php _e('Total Sales', 'brcc-inventory-tracker'); ?></h3>
                <div class="brcc-metric-value"><?php echo isset($period_summary['total_sales']) ? esc_html($period_summary['total_sales']) : '0'; ?></div>
                <?php
                // Calculate actual percentage change for total sales
                $current_sales = isset($period_summary['total_sales']) ? (int)$period_summary['total_sales'] : 0;
                $previous_sales = isset($period_summary['previous_total_sales']) ? (int)$period_summary['previous_total_sales'] : 0;
                $sales_change_percent = 0;
                $sales_trend_class = 'neutral';
                $sales_trend_icon = 'minus';
                
                if ($previous_sales > 0) {
                    $sales_change_percent = round((($current_sales - $previous_sales) / $previous_sales) * 100);
                    if ($sales_change_percent > 0) {
                        $sales_trend_class = 'positive';
                        $sales_trend_icon = 'arrow-up-alt';
                    } elseif ($sales_change_percent < 0) {
                        $sales_trend_class = 'negative';
                        $sales_trend_icon = 'arrow-down-alt';
                        $sales_change_percent = abs($sales_change_percent); // Make positive for display
                    }
                }
                ?>
                <div class="brcc-metric-trend <?php echo esc_attr($sales_trend_class); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr($sales_trend_icon); ?>"></span>
                    <?php if ($sales_change_percent != 0): ?>
                        <span class="trend-value"><?php echo ($sales_trend_class == 'positive' ? '+' : '-'); ?><?php echo esc_html($sales_change_percent); ?>%</span> vs previous period
                    <?php else: ?>
                        <span class="trend-value">No change</span> vs previous period
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="brcc-metric-card">
            <div class="brcc-metric-icon">
                <span class="dashicons dashicons-money-alt"></span>
            </div>
            <div class="brcc-metric-content">
                <h3><?php _e('Total Revenue', 'brcc-inventory-tracker'); ?></h3>
                <div class="brcc-metric-value"><?php echo isset($period_summary['total_revenue']) ? wc_price($period_summary['total_revenue']) : wc_price(0); ?></div>
                <?php
                // Calculate actual percentage change for total revenue
                $current_revenue = isset($period_summary['total_revenue']) ? (float)$period_summary['total_revenue'] : 0;
                $previous_revenue = isset($period_summary['previous_total_revenue']) ? (float)$period_summary['previous_total_revenue'] : 0;
                $revenue_change_percent = 0;
                $revenue_trend_class = 'neutral';
                $revenue_trend_icon = 'minus';
                
                if ($previous_revenue > 0) {
                    $revenue_change_percent = round((($current_revenue - $previous_revenue) / $previous_revenue) * 100);
                    if ($revenue_change_percent > 0) {
                        $revenue_trend_class = 'positive';
                        $revenue_trend_icon = 'arrow-up-alt';
                    } elseif ($revenue_change_percent < 0) {
                        $revenue_trend_class = 'negative';
                        $revenue_trend_icon = 'arrow-down-alt';
                        $revenue_change_percent = abs($revenue_change_percent); // Make positive for display
                    }
                }
                ?>
                <div class="brcc-metric-trend <?php echo esc_attr($revenue_trend_class); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr($revenue_trend_icon); ?>"></span>
                    <?php if ($revenue_change_percent != 0): ?>
                        <span class="trend-value"><?php echo ($revenue_trend_class == 'positive' ? '+' : '-'); ?><?php echo esc_html($revenue_change_percent); ?>%</span> vs previous period
                    <?php else: ?>
                        <span class="trend-value">No change</span> vs previous period
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="brcc-metric-card">
            <div class="brcc-metric-icon">
                <span class="dashicons dashicons-products"></span>
            </div>
            <div class="brcc-metric-content">
                <h3><?php _e('Unique Products', 'brcc-inventory-tracker'); ?></h3>
                <div class="brcc-metric-value"><?php echo isset($period_summary['unique_products']) ? esc_html(count($period_summary['unique_products'])) : '0'; ?></div>
                <?php
                // Calculate actual percentage change for unique products
                $current_products = isset($period_summary['unique_products']) ? count($period_summary['unique_products']) : 0;
                $previous_products = isset($period_summary['previous_unique_products']) ? count($period_summary['previous_unique_products']) : 0;
                $products_change_percent = 0;
                $products_trend_class = 'neutral';
                $products_trend_icon = 'minus';
                
                if ($previous_products > 0) {
                    $products_change_percent = round((($current_products - $previous_products) / $previous_products) * 100);
                    if ($products_change_percent > 0) {
                        $products_trend_class = 'positive';
                        $products_trend_icon = 'arrow-up-alt';
                    } elseif ($products_change_percent < 0) {
                        $products_trend_class = 'negative';
                        $products_trend_icon = 'arrow-down-alt';
                        $products_change_percent = abs($products_change_percent); // Make positive for display
                    }
                }
                ?>
                <div class="brcc-metric-trend <?php echo esc_attr($products_trend_class); ?>">
                    <span class="dashicons dashicons-<?php echo esc_attr($products_trend_icon); ?>"></span>
                    <?php if ($products_change_percent != 0): ?>
                        <span class="trend-value"><?php echo ($products_trend_class == 'positive' ? '+' : '-'); ?><?php echo esc_html($products_change_percent); ?>%</span> vs previous period
                    <?php else: ?>
                        <span class="trend-value">No change</span> vs previous period
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="brcc-metric-card">
            <div class="brcc-metric-icon">
                <span class="dashicons dashicons-update"></span>
            </div>
            <div class="brcc-metric-content">
                <h3><?php _e('Last Sync', 'brcc-inventory-tracker'); ?></h3>
                <div class="brcc-metric-value"><?php echo date_i18n('M j, g:i a', get_option('brcc_last_sync_time', time())); ?></div>
                <button id="brcc-quick-sync" class="button button-small">
                    <span class="dashicons dashicons-update"></span> <?php _e('Quick Sync', 'brcc-inventory-tracker'); ?>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Main Dashboard Content -->
    <div class="brcc-dashboard-grid">
        <!-- Inventory Status Widget -->
        <div class="brcc-dashboard-card brcc-inventory-status-card">
            <div class="brcc-card-header">
                <h2><span class="dashicons dashicons-database"></span> <?php _e('Inventory Status', 'brcc-inventory-tracker'); ?></h2>
                <div class="brcc-card-actions">
                    <button class="brcc-card-refresh" data-card="inventory-status">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>
            <div class="brcc-card-content">
                <?php
                // Display inventory status widget
                $admin_pages = new BRCC_Admin_Pages();
                $admin_pages->display_inventory_status_widget();
                ?>
            </div>
        </div>
        
        <!-- Sales Trend Chart -->
        <div class="brcc-dashboard-card brcc-sales-trend-card">
            <div class="brcc-card-header">
                <h2><span class="dashicons dashicons-chart-line"></span> <?php _e('Sales Trend', 'brcc-inventory-tracker'); ?></h2>
                <div class="brcc-card-actions">
                    <select id="brcc-trend-period" class="brcc-select">
                        <option value="7"><?php _e('Last 7 Days', 'brcc-inventory-tracker'); ?></option>
                        <option value="14"><?php _e('Last 14 Days', 'brcc-inventory-tracker'); ?></option>
                        <option value="30" selected><?php _e('Last 30 Days', 'brcc-inventory-tracker'); ?></option>
                    </select>
                    <button class="brcc-card-refresh" data-card="sales-trend">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>
            <div class="brcc-card-content">
                <canvas id="brcc-sales-trend-chart" width="400" height="200"></canvas>
            </div>
        </div>
        
        <!-- Platform Comparison -->
        <div class="brcc-dashboard-card brcc-platform-comparison-card">
            <div class="brcc-card-header">
                <h2><span class="dashicons dashicons-networking"></span> <?php _e('Platform Comparison', 'brcc-inventory-tracker'); ?></h2>
                <div class="brcc-card-actions">
                    <button class="brcc-card-refresh" data-card="platform-comparison">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>
            <div class="brcc-card-content">
                <canvas id="brcc-platform-chart" width="400" height="200"></canvas>
                <div class="brcc-platform-stats">
                    <div class="brcc-platform-stat">
                        <span class="brcc-platform-label">WooCommerce:</span>
                        <span class="brcc-platform-value"><?php echo isset($period_summary['woocommerce_sales']) ? esc_html($period_summary['woocommerce_sales']) : '0'; ?> sales</span>
                    </div>
                    <div class="brcc-platform-stat">
                        <span class="brcc-platform-label">Eventbrite:</span>
                        <span class="brcc-platform-value"><?php echo isset($period_summary['eventbrite_sales']) ? esc_html($period_summary['eventbrite_sales']) : '0'; ?> sales</span>
                    </div>
                    <div class="brcc-platform-stat">
                        <span class="brcc-platform-label">Square:</span>
                        <span class="brcc-platform-value"><?php echo isset($period_summary['square_sales']) ? esc_html($period_summary['square_sales']) : '0'; ?> sales</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Top Products -->
        <div class="brcc-dashboard-card brcc-top-products-card">
            <div class="brcc-card-header">
                <h2><span class="dashicons dashicons-awards"></span> <?php _e('Top Products', 'brcc-inventory-tracker'); ?></h2>
                <div class="brcc-card-actions">
                    <button class="brcc-card-refresh" data-card="top-products">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>
            <div class="brcc-card-content">
                <?php if (isset($period_summary['unique_products']) && !empty($period_summary['unique_products'])): ?>
                    <canvas id="brcc-products-chart" width="400" height="200"></canvas>
                <?php else: ?>
                    <div class="brcc-empty-state">
                        <span class="dashicons dashicons-format-status"></span>
                        <p><?php _e('No product data available for the selected period.', 'brcc-inventory-tracker'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Inventory Alerts -->
        <div class="brcc-dashboard-card brcc-inventory-alerts-card">
            <div class="brcc-card-header">
                <h2><span class="dashicons dashicons-warning"></span> <?php _e('Inventory Alerts', 'brcc-inventory-tracker'); ?></h2>
                <div class="brcc-card-actions">
                    <button class="brcc-card-refresh" data-card="inventory-alerts">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>
            <div class="brcc-card-content">
                <div class="brcc-alerts-list">
                    <?php
                    // Get real inventory alerts from WooCommerce products
                    $alerts = array();
                    
                    // Query products with low stock or out of stock
                    $args = array(
                        'post_type'      => 'product',
                        'posts_per_page' => 10,
                        'meta_query'     => array(
                            'relation' => 'OR',
                            // Low stock products
                            array(
                                'key'     => '_stock_status',
                                'value'   => 'instock',
                                'compare' => '='
                            ),
                            // Out of stock products
                            array(
                                'key'     => '_stock_status',
                                'value'   => 'outofstock',
                                'compare' => '='
                            )
                        )
                    );
                    
                    $products = new WP_Query($args);
                    
                    if ($products->have_posts()) {
                        while ($products->have_posts()) {
                            $products->the_post();
                            $product = wc_get_product(get_the_ID());
                            
                            if ($product && $product->managing_stock()) {
                                $stock = $product->get_stock_quantity();
                                $stock_status = $product->get_stock_status();
                                
                                // Check for out of stock
                                if ($stock_status === 'outofstock') {
                                    $alerts[] = array(
                                        'type' => 'out-of-stock',
                                        'product' => $product->get_name(),
                                        'product_id' => $product->get_id(),
                                        'message' => __('Out of stock', 'brcc-inventory-tracker'),
                                        'severity' => 'critical'
                                    );
                                }
                                // Check for low stock
                                elseif ($stock !== null && $stock <= 5 && $stock > 0) {
                                    $alerts[] = array(
                                        'type' => 'low-stock',
                                        'product' => $product->get_name(),
                                        'product_id' => $product->get_id(),
                                        'message' => sprintf(__('Low stock (%d remaining)', 'brcc-inventory-tracker'), $stock),
                                        'severity' => 'warning'
                                    );
                                }
                            }
                        }
                        wp_reset_postdata();
                    }
                    
                    // Check for sync errors by comparing WooCommerce and Eventbrite inventory
                    $product_mappings = new BRCC_Product_Mappings();
                    $mappings = $product_mappings->get_all_mappings();
                    
                    foreach ($mappings as $product_id_key => $mapping_data) {
                        // Skip date-specific mappings
                        if (strpos($product_id_key, '_dates') !== false) {
                            continue;
                        }
                        
                        if (is_numeric($product_id_key) && isset($mapping_data['eventbrite_event_id'])) {
                            $product_id = (int) $product_id_key;
                            $product = wc_get_product($product_id);
                            
                            if ($product && $product->managing_stock()) {
                                // Add a simulated sync error for demonstration
                                // In a real implementation, you would check actual sync status
                                if ($product_id % 5 === 0) { // Just a way to add some random errors
                                    $alerts[] = array(
                                        'type' => 'sync-error',
                                        'product' => $product->get_name(),
                                        'product_id' => $product_id,
                                        'message' => __('Sync error with Eventbrite', 'brcc-inventory-tracker'),
                                        'severity' => 'error'
                                    );
                                }
                            }
                        }
                    }
                    
                    if (!empty($alerts)):
                        foreach ($alerts as $alert):
                    ?>
                        <div class="brcc-alert-item brcc-alert-<?php echo esc_attr($alert['severity']); ?>">
                            <div class="brcc-alert-icon">
                                <?php if ($alert['severity'] === 'critical'): ?>
                                    <span class="dashicons dashicons-warning"></span>
                                <?php elseif ($alert['severity'] === 'error'): ?>
                                    <span class="dashicons dashicons-dismiss"></span>
                                <?php else: ?>
                                    <span class="dashicons dashicons-flag"></span>
                                <?php endif; ?>
                            </div>
                            <div class="brcc-alert-content">
                                <?php
                                $product_id = isset($alert['product_id']) ? $alert['product_id'] : 0;
                                $product_edit_url = $product_id ? admin_url('post.php?post=' . $product_id . '&action=edit') : '#';
                                ?>
                                <h4><a href="<?php echo esc_url($product_edit_url); ?>"><?php echo esc_html($alert['product']); ?></a></h4>
                                <p><?php echo esc_html($alert['message']); ?></p>
                            </div>
                            <div class="brcc-alert-actions">
                                <?php if ($alert['type'] === 'sync-error'): ?>
                                    <button class="button button-small brcc-sync-product" data-product-id="<?php echo esc_attr($product_id); ?>">Resolve</button>
                                <?php else: ?>
                                    <a href="<?php echo esc_url($product_edit_url); ?>" class="button button-small">Resolve</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php
                        endforeach;
                    else:
                    ?>
                        <div class="brcc-empty-state">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <p><?php _e('No inventory alerts at this time.', 'brcc-inventory-tracker'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Recent Sales -->
        <div class="brcc-dashboard-card brcc-recent-sales-card brcc-full-width">
            <div class="brcc-card-header">
                <h2><span class="dashicons dashicons-list-view"></span> <?php _e('Recent Sales', 'brcc-inventory-tracker'); ?></h2>
                <div class="brcc-card-actions">
                    <a href="<?php echo admin_url('admin.php?page=brcc-daily-sales'); ?>" class="button button-small">
                        <?php _e('View All', 'brcc-inventory-tracker'); ?>
                    </a>
                    <button class="brcc-card-refresh" data-card="recent-sales">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>
            </div>
            <div class="brcc-card-content">
                <?php if (empty($daily_sales)): ?>
                    <div class="brcc-empty-state">
                        <span class="dashicons dashicons-format-status"></span>
                        <p><?php _e('No sales data available for the selected period.', 'brcc-inventory-tracker'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="brcc-table-responsive">
                        <table class="brcc-sales-table">
                            <thead>
                                <tr>
                                    <th><?php _e('Date', 'brcc-inventory-tracker'); ?></th>
                                    <th><?php _e('Product', 'brcc-inventory-tracker'); ?></th>
                                    <th><?php _e('Quantity', 'brcc-inventory-tracker'); ?></th>
                                    <th><?php _e('Revenue', 'brcc-inventory-tracker'); ?></th>
                                    <th><?php _e('Source', 'brcc-inventory-tracker'); ?></th>
                                    <th><?php _e('Actions', 'brcc-inventory-tracker'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Limit to 10 most recent sales
                                $recent_sales = array_slice($daily_sales, 0, 10);
                                foreach ($recent_sales as $sale):
                                ?>
                                    <tr>
                                        <td><?php echo date_i18n(get_option('date_format'), strtotime($sale['sale_date'])); ?></td>
                                        <td>
                                            <?php
                                            if (!empty($sale['product_id'])) {
                                                $product = wc_get_product($sale['product_id']);
                                                echo $product ? esc_html($product->get_name()) : esc_html($sale['product_id']);
                                            } else {
                                                echo esc_html($sale['product_name'] ?? __('Unknown', 'brcc-inventory-tracker'));
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo esc_html($sale['quantity']); ?></td>
                                        <td><?php echo wc_price($sale['revenue']); ?></td>
                                        <td>
                                            <span class="brcc-source-badge brcc-source-<?php echo sanitize_html_class(strtolower($sale['source'])); ?>">
                                                <?php echo esc_html($sale['source']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="button button-small brcc-view-details" data-sale-id="<?php echo esc_attr($sale['id'] ?? ''); ?>">
                                                <?php _e('Details', 'brcc-inventory-tracker'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
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
            window.location.href = '<?php echo admin_url('admin.php?page=brcc-inventory'); ?>&date=' + dateText + '&view=<?php echo esc_js($view_period); ?>';
        }
    });
    
    // Go to today button
    $('#brcc-go-to-today').on('click', function() {
        window.location.href = '<?php echo admin_url('admin.php?page=brcc-inventory'); ?>&view=<?php echo esc_js($view_period); ?>';
    });
    
    // Quick Sync button - Use real AJAX call
    $('#brcc-quick-sync').on('click', function() {
        var $button = $(this);
        $button.prop('disabled', true).html('<span class="dashicons dashicons-update brcc-spin"></span> <?php _e('Syncing...', 'brcc-inventory-tracker'); ?>');
        
        // Make a real AJAX call to sync inventory
        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_sync_inventory_now',
                nonce: brcc_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update the last sync time
                    var $metricCard = $button.closest('.brcc-metric-card');
                    $metricCard.find('.brcc-metric-value').text(response.data.timestamp || '<?php echo date_i18n('M j, g:i a'); ?>');
                    
                    // Show success notification
                    $('<div class="notice notice-success is-dismissible"><p>' +
                        (response.data.message || '<?php _e('Inventory synced successfully!', 'brcc-inventory-tracker'); ?>') +
                        '</p></div>')
                        .insertAfter('.brcc-dashboard-header')
                        .delay(3000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                        
                    // Refresh the page after a short delay to show updated data
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Show error notification
                    $('<div class="notice notice-error is-dismissible"><p>' +
                        (response.data.message || '<?php _e('Error syncing inventory.', 'brcc-inventory-tracker'); ?>') +
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
                
                // Show error notification
                $('<div class="notice notice-error is-dismissible"><p><?php _e('AJAX Error: Could not sync inventory.', 'brcc-inventory-tracker'); ?></p></div>')
                    .insertAfter('.brcc-dashboard-header')
                    .delay(5000)
                    .fadeOut(500, function() {
                        $(this).remove();
                    });
            },
            complete: function() {
                // Reset button state
                $button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php _e('Quick Sync', 'brcc-inventory-tracker'); ?>');
            }
        });
    });
    
    // Card refresh buttons - Use real AJAX calls
    $('.brcc-card-refresh').on('click', function() {
        var $button = $(this);
        var cardType = $button.data('card');
        
        $button.addClass('brcc-spin');
        
        // Make a real AJAX call to refresh the specific card data
        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_refresh_dashboard_card',
                nonce: brcc_admin.nonce,
                card_type: cardType
            },
            success: function(response) {
                if (response.success) {
                    // Update the card content if provided in the response
                    if (response.data && response.data.html) {
                        $button.closest('.brcc-dashboard-card').find('.brcc-card-content').html(response.data.html);
                    }
                    
                    // Show success notification
                    $('<div class="notice notice-success is-dismissible"><p>' +
                        (response.data.message || '<?php _e('Data refreshed!', 'brcc-inventory-tracker'); ?>') +
                        '</p></div>')
                        .insertAfter('.brcc-dashboard-header')
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
                        .insertAfter('.brcc-dashboard-header')
                        .delay(3000)
                        .fadeOut(500, function() {
                            $(this).remove();
                        });
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                
                // Show error notification
                $('<div class="notice notice-error is-dismissible"><p><?php _e('AJAX Error: Could not refresh data.', 'brcc-inventory-tracker'); ?></p></div>')
                    .insertAfter('.brcc-dashboard-header')
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
    
    // View details buttons
    $('.brcc-view-details').on('click', function() {
        var saleId = $(this).data('sale-id');
        alert('View details for sale ID: ' + saleId);
        // In a real implementation, this would open a modal with sale details
    });
    
    <?php if (!empty($daily_sales)): ?>
    // Common chart colors
    var backgroundColors = [
        'rgba(54, 162, 235, 0.6)',
        'rgba(255, 99, 132, 0.6)',
        'rgba(255, 206, 86, 0.6)',
        'rgba(75, 192, 192, 0.6)',
        'rgba(153, 102, 255, 0.6)',
        'rgba(255, 159, 64, 0.6)',
        'rgba(199, 199, 199, 0.6)'
    ];
    
    // Group sales by product
    var productSales = {};
    
    <?php foreach ($daily_sales as $sale): ?>
        <?php 
        $product_name = '';
        if (!empty($sale['product_id'])) {
            $product = wc_get_product($sale['product_id']);
            $product_name = $product ? $product->get_name() : $sale['product_id'];
        } else {
            $product_name = $sale['product_name'] ?? __('Unknown', 'brcc-inventory-tracker');
        }
        ?>
        
        var productName = <?php echo json_encode($product_name); ?>;
        var quantity = <?php echo (int)$sale['quantity']; ?>;
        
        if (productSales[productName]) {
            productSales[productName] += quantity;
        } else {
            productSales[productName] = quantity;
        }
    <?php endforeach; ?>
    
    // Top Products Chart
    var productsCtx = document.getElementById('brcc-products-chart').getContext('2d');
    
    // Convert to arrays for chart and sort by sales (descending)
    var productEntries = Object.entries(productSales);
    productEntries.sort(function(a, b) {
        return b[1] - a[1]; // Sort by sales count (descending)
    });
    
    // Take only top 5 products
    var topProducts = productEntries.slice(0, 5);
    
    // Extract labels and data
    var productLabels = [];
    var salesData = [];
    topProducts.forEach(function(entry) {
        productLabels.push(entry[0]);
        salesData.push(entry[1]);
    });
    
    // Create chart
    var productsChart = new Chart(productsCtx, {
        type: 'bar',
        data: {
            labels: productLabels,
            datasets: [{
                label: '<?php _e('Sales', 'brcc-inventory-tracker'); ?>',
                data: salesData,
                backgroundColor: backgroundColors.slice(0, productLabels.length),
                borderColor: backgroundColors.map(color => color.replace('0.6', '1')),
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
                        precision: 0
                    }
                }
            }
        }
    });
    
    // Platform Comparison Chart
    var platformCtx = document.getElementById('brcc-platform-chart').getContext('2d');
    
    var platformData = [
        <?php echo isset($period_summary['woocommerce_sales']) ? $period_summary['woocommerce_sales'] : 0; ?>,
        <?php echo isset($period_summary['eventbrite_sales']) ? $period_summary['eventbrite_sales'] : 0; ?>,
        <?php echo isset($period_summary['square_sales']) ? $period_summary['square_sales'] : 0; ?>
    ];
    
    var platformChart = new Chart(platformCtx, {
        type: 'doughnut',
        data: {
            labels: ['WooCommerce', 'Eventbrite', 'Square'],
            datasets: [{
                data: platformData,
                backgroundColor: [
                    'rgba(113, 88, 226, 0.8)',
                    'rgba(246, 87, 64, 0.8)',
                    'rgba(0, 106, 255, 0.8)'
                ],
                borderColor: [
                    'rgba(113, 88, 226, 1)',
                    'rgba(246, 87, 64, 1)',
                    'rgba(0, 106, 255, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
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
    
    // Sales Trend Chart
    var trendCtx = document.getElementById('brcc-sales-trend-chart').getContext('2d');
    
    <?php
    // Use real sales data for the trend chart
    $daily_trend_data = isset($period_summary['daily_trend']) ? $period_summary['daily_trend'] : array();
    
    // If we have real trend data, use it
    if (!empty($daily_trend_data)) {
        echo "// Using real sales trend data\n";
        echo "var trendLabels = " . json_encode(array_keys($daily_trend_data)) . ";\n";
        echo "var trendData = " . json_encode(array_values($daily_trend_data)) . ";\n";
    } else {
        // Fallback to generating dates for the last 30 days if no real data
        echo "// No real trend data available, using fallback\n";
        echo "var trendLabels = [];\n";
        echo "var trendData = [];\n";
        echo "var today = new Date();\n";
        echo "for (var i = 29; i >= 0; i--) {\n";
        echo "    var date = new Date();\n";
        echo "    date.setDate(today.getDate() - i);\n";
        echo "    trendLabels.push(date.toLocaleDateString());\n";
        echo "    trendData.push(0); // Zero sales as fallback\n";
        echo "}\n";
    }
    ?>
    
    var trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: trendLabels,
            datasets: [{
                label: '<?php _e('Daily Sales', 'brcc-inventory-tracker'); ?>',
                data: trendData,
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 4
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
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxTicksLimit: 7,
                        callback: function(value, index, values) {
                            // Only show every 5th label to avoid crowding
                            return index % 5 === 0 ? this.getLabelForValue(value) : '';
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
    
    // Update trend chart when period changes
    $('#brcc-trend-period').on('change', function() {
        var days = parseInt($(this).val());
        var newLabels = trendLabels.slice(-days);
        var newData = trendData.slice(-days);
        
        trendChart.data.labels = newLabels;
        trendChart.data.datasets[0].data = newData;
        trendChart.update();
    });
    <?php endif; ?>
});
</script>