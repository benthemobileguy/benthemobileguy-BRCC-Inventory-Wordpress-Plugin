<?php
/**
 * Sales Table template
 * 
 * @var array $sales_data Sales data to display
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="brcc-sales-table-container">
    <?php if (empty($sales_data)): ?>
        <div class="notice notice-info">
            <p><?php _e('No sales data available for the selected period.', 'brcc-inventory-tracker'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Product', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Quantity', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Revenue', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Source', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Time', 'brcc-inventory-tracker'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales_data as $sale): ?>
                    <tr>
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
                        <td><?php echo esc_html($sale['source']); ?></td>
                        <td>
                            <?php 
                            if (!empty($sale['timestamp'])) {
                                echo date_i18n(get_option('time_format'), $sale['timestamp']);
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th><?php _e('Total', 'brcc-inventory-tracker'); ?></th>
                    <th>
                        <?php 
                        $total_quantity = array_sum(array_column($sales_data, 'quantity'));
                        echo esc_html($total_quantity);
                        ?>
                    </th>
                    <th>
                        <?php 
                        $total_revenue = array_sum(array_column($sales_data, 'revenue'));
                        echo wc_price($total_revenue);
                        ?>
                    </th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>