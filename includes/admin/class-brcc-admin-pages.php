<?php
/**
 * BRCC Admin Pages Class
 * 
 * Handles admin page display logic for the BRCC Inventory Tracker plugin.
 * This class is responsible for preparing data and rendering the various admin pages
 * using template files. It follows the MVC pattern where this class acts as the controller,
 * preparing data (model) and passing it to the view templates for rendering.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BRCC_Admin_Pages {

    /**
     * Display dashboard page
     * 
     * Prepares data and renders the main dashboard page.
     * This page shows sales summaries, charts, and inventory status.
     */
    public function display_dashboard_page() {
        // Prepare data
        $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');
        $view_period = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'daily';
        
        // Calculate other variables needed by the template
        $start_date = $selected_date;
        $end_date = $selected_date;
        $summary_title = __('Daily Summary', 'brcc-inventory-tracker');
        $summary_tooltip = sprintf(__('Sales data for %s', 'brcc-inventory-tracker'), 
            date_i18n(get_option('date_format'), strtotime($selected_date)));
            
        // Calculate data for other periods if needed
        if ($view_period === 'weekly') {
            // Calculate start of week (Monday) and end of week (Sunday)
            $start_date = date('Y-m-d', strtotime('monday this week', strtotime($selected_date)));
            $end_date = date('Y-m-d', strtotime('sunday this week', strtotime($selected_date)));
            $summary_title = __('Weekly Summary', 'brcc-inventory-tracker');
            $summary_tooltip = sprintf(__('Sales data for week of %s to %s', 'brcc-inventory-tracker'),
                date_i18n(get_option('date_format'), strtotime($start_date)),
                date_i18n(get_option('date_format'), strtotime($end_date)));
        } elseif ($view_period === 'monthly') {
            // Calculate start and end of month
            $start_date = date('Y-m-01', strtotime($selected_date));
            $end_date = date('Y-m-t', strtotime($selected_date));
            $summary_title = __('Monthly Summary', 'brcc-inventory-tracker');
            $summary_tooltip = sprintf(__('Sales data for %s', 'brcc-inventory-tracker'),
                date_i18n('F Y', strtotime($selected_date)));
        }
        
        // Get sales tracker data
        $sales_tracker = new BRCC_Sales_Tracker();
        $period_summary = $sales_tracker->get_summary_by_period($start_date, $end_date);
        $nested_daily_sales = $sales_tracker->get_daily_sales($start_date, $end_date);
        
        // Transform nested daily sales data into a flat structure for the dashboard
        $daily_sales = $this->flatten_daily_sales($nested_daily_sales);
        
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/dashboard-page.php');
    }
    
    /**
     * Flatten the nested daily sales data into a format suitable for the dashboard
     *
     * @param array $nested_sales The nested sales data from get_daily_sales
     * @return array Flattened sales data
     */
    private function flatten_daily_sales($nested_sales) {
        $flattened_sales = [];
        
        // Process each date
        foreach ($nested_sales as $date => $products) {
            // Process each product for this date
            foreach ($products as $product_key => $product_data) {
                // Create a flattened sale entry
                $sale_entry = [
                    'sale_date' => $date,
                    'product_id' => $product_data['product_id'] ?? null,
                    'product_name' => $product_data['name'] ?? null,
                    'quantity' => $product_data['quantity'] ?? 0,
                    'revenue' => 0, // Default value
                    'source' => 'Unknown' // Default value
                ];
                
                // Calculate revenue from orders if available
                if (isset($product_data['orders']) && is_array($product_data['orders'])) {
                    $revenue = 0;
                    $sources = [];
                    
                    foreach ($product_data['orders'] as $order) {
                        $revenue += (float)($order['amount'] ?? 0);
                        if (!empty($order['source'])) {
                            $sources[] = $order['source'];
                        }
                    }
                    
                    $sale_entry['revenue'] = $revenue;
                    
                    // Set the source based on the most common source in orders
                    if (!empty($sources)) {
                        $source_counts = array_count_values($sources);
                        arsort($source_counts);
                        $sale_entry['source'] = key($source_counts);
                    } else {
                        // Try to determine source from individual counters
                        foreach (['woocommerce', 'eventbrite', 'square'] as $possible_source) {
                            if (isset($product_data[$possible_source]) && $product_data[$possible_source] > 0) {
                                $sale_entry['source'] = ucfirst($possible_source);
                                break;
                            }
                        }
                    }
                }
                
                $flattened_sales[] = $sale_entry;
            }
        }
        
        // Sort by date (newest first)
        usort($flattened_sales, function($a, $b) {
            return strtotime($b['sale_date']) - strtotime($a['sale_date']);
        });
        
        return $flattened_sales;
    }
    
    /**
     * Display settings page
     * 
     * Renders the settings page with tabs for different setting groups.
     * This includes general settings, product mappings, and date mappings.
     */
    public function display_settings_page() {
        // Check if we need to display the product mapping interface
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/settings-page.php');
    }
    
    /**
     * Display product mapping interface
     * 
     * Prepares data and renders the product mapping interface.
     * This interface allows mapping WooCommerce products to Eventbrite events and Square items.
     * Handles both base product mappings and date-specific mappings.
     */
    public function display_product_mapping_interface() {
        // Get all products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        );
        $products = get_posts($args);
        
        // Get existing mappings
        $product_mappings = new BRCC_Product_Mappings();
        $mappings = $product_mappings->get_all_mappings();
        
        // Prepare data for the template
        $prepared_mappings = array();
        foreach ($mappings as $key => $mapping) {
            if (strpos($key, '_dates') !== false) {
                // This is a date-specific mapping
                $product_id = str_replace('_dates', '', $key);
                if (!isset($prepared_mappings[$product_id])) {
                    $prepared_mappings[$product_id] = array(
                        'base' => isset($mappings[$product_id]) ? $mappings[$product_id] : array(),
                        'dates' => array()
                    );
                }
                $prepared_mappings[$product_id]['dates'] = $mapping;
            } elseif (is_numeric($key)) {
                // This is a base product mapping
                if (!isset($prepared_mappings[$key])) {
                    $prepared_mappings[$key] = array(
                        'base' => $mapping,
                        'dates' => isset($mappings[$key . '_dates']) ? $mappings[$key . '_dates'] : array()
                    );
                } else {
                    $prepared_mappings[$key]['base'] = $mapping;
                }
            }
        }
        
        // Pass the prepared mappings to the template
        $mappings = $prepared_mappings;
        
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/product-mapping-interface.php');
    }
    
    /**
     * Display operation logs
     * 
     * Prepares data and renders the operation logs page.
     * This page shows logs of plugin operations with pagination.
     */
    public function display_operation_logs() {
        // Get logs from database
        global $wpdb;
        $table_name = $wpdb->prefix . 'brcc_operation_logs';
        
        // Handle pagination
        $page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
        $per_page = 50;
        $offset = ($page - 1) * $per_page;
        
        // Get total count for pagination
        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_logs / $per_page);
        
        // Get logs with pagination
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
        
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/logs-page.php');
    }
    
    /**
     * Display import history page
     * 
     * Renders the import history page.
     * This page allows importing historical sales data from various sources.
     */
    public function display_import_history_page() {
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/import-history-page.php');
    }
    
    /**
     * Display attendee list page
     * 
     * Prepares data and renders the attendee list page.
     * This page shows attendees for events on a specific date.
     */
    public function display_attendee_list_page() {
        // Get date from query string or use today's date
        $selected_date = isset($_GET['date']) ? sanitize_text_field($_GET['date']) : current_time('Y-m-d');
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
            $selected_date = current_time('Y-m-d');
        }
        
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/attendee-list-page.php');
    }
    
    /**
     * Display tools page
     * 
     * Renders the tools page.
     * This page provides various utility tools for managing the plugin.
     */
    public function display_tools_page() {
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/tools-page.php');
    }
    
    /**
     * Display sales table
     * 
     * Renders a table of sales data.
     * This is a reusable component used by multiple pages.
     * 
     * @param array $sales_data Array of sales data to display
     */
    private function display_sales_table($sales_data) {
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/sales-table.php');
    }
    
    /**
     * Display inventory status widget
     * 
     * Prepares data and renders the inventory status widget.
     * This widget shows the current sync status and allows manual syncing.
     */
    public function display_inventory_status_widget() {
        // Get last sync time
        $last_sync_time = get_option('brcc_last_sync_time', 0);
        $last_sync_formatted = $last_sync_time ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $last_sync_time) : __('Never', 'brcc-inventory-tracker');
        
        // Get sync interval
        $settings = get_option('brcc_api_settings', array());
        $sync_interval = isset($settings['sync_interval']) ? absint($settings['sync_interval']) : 15;
        
        // Calculate next sync time
        $next_sync_time = $last_sync_time + ($sync_interval * 60);
        $next_sync_formatted = $last_sync_time ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_sync_time) : __('N/A', 'brcc-inventory-tracker');
        
        // Include the template
        include(BRCC_INVENTORY_TRACKER_PLUGIN_DIR . 'includes/admin/views/inventory-status-widget.php');
    }
}