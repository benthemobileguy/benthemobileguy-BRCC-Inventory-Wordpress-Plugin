<?php
/**
 * Operation Logs page template
 * 
 * @var array $logs Operation logs
 * @var int $page Current page
 * @var int $total_pages Total pages
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php _e('BRCC Operation Logs', 'brcc-inventory-tracker'); ?></h1>
    
    <?php if (BRCC_Helpers::is_test_mode()): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php _e('Test Mode is ENABLED', 'brcc-inventory-tracker'); ?></strong>
                <?php _e('Inventory operations are being logged without making actual changes.', 'brcc-inventory-tracker'); ?>
            </p>
        </div>
    <?php endif; ?>
    
    <div class="brcc-logs-actions">
        <a href="<?php echo wp_nonce_url(add_query_arg('action', 'brcc_clear_logs'), 'brcc-clear-logs', 'nonce'); ?>" class="button button-secondary" onclick="return confirm('<?php _e('Are you sure you want to clear all logs? This cannot be undone.', 'brcc-inventory-tracker'); ?>');">
            <?php _e('Clear All Logs', 'brcc-inventory-tracker'); ?>
        </a>
    </div>
    
    <?php if (empty($logs)): ?>
        <div class="notice notice-info">
            <p><?php _e('No operation logs available.', 'brcc-inventory-tracker'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="15%"><?php _e('Timestamp', 'brcc-inventory-tracker'); ?></th>
                    <th width="10%"><?php _e('Component', 'brcc-inventory-tracker'); ?></th>
                    <th width="15%"><?php _e('Operation', 'brcc-inventory-tracker'); ?></th>
                    <th><?php _e('Message', 'brcc-inventory-tracker'); ?></th>
                    <th width="10%"><?php _e('Type', 'brcc-inventory-tracker'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $type_class = '';
                    switch ($log->log_type) {
                        case 'error':
                            $type_class = 'brcc-log-error';
                            break;
                        case 'warning':
                            $type_class = 'brcc-log-warning';
                            break;
                        case 'success':
                            $type_class = 'brcc-log-success';
                            break;
                        default:
                            $type_class = 'brcc-log-info';
                            break;
                    }
                    ?>
                    <tr class="<?php echo esc_attr($type_class); ?>">
                        <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->timestamp)); ?></td>
                        <td><?php echo esc_html($log->component); ?></td>
                        <td><?php echo esc_html($log->operation); ?></td>
                        <td><?php echo wp_kses_post($log->message); ?></td>
                        <td>
                            <?php
                            switch ($log->log_type) {
                                case 'error':
                                    echo '<span class="brcc-log-badge brcc-log-badge-error">' . __('Error', 'brcc-inventory-tracker') . '</span>';
                                    break;
                                case 'warning':
                                    echo '<span class="brcc-log-badge brcc-log-badge-warning">' . __('Warning', 'brcc-inventory-tracker') . '</span>';
                                    break;
                                case 'success':
                                    echo '<span class="brcc-log-badge brcc-log-badge-success">' . __('Success', 'brcc-inventory-tracker') . '</span>';
                                    break;
                                default:
                                    echo '<span class="brcc-log-badge brcc-log-badge-info">' . __('Info', 'brcc-inventory-tracker') . '</span>';
                                    break;
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php echo sprintf(_n('%s item', '%s items', $total_logs, 'brcc-inventory-tracker'), number_format_i18n($total_logs)); ?>
                    </span>
                    <span class="pagination-links">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ));
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.brcc-log-error {
    background-color: #ffeeee;
}
.brcc-log-warning {
    background-color: #fffbea;
}
.brcc-log-success {
    background-color: #eeffee;
}
.brcc-log-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}
.brcc-log-badge-error {
    background-color: #f44336;
    color: white;
}
.brcc-log-badge-warning {
    background-color: #ff9800;
    color: white;
}
.brcc-log-badge-success {
    background-color: #4caf50;
    color: white;
}
.brcc-log-badge-info {
    background-color: #2196f3;
    color: white;
}
</style>