<?php
/**
 * BRCC Cron Class
 * 
 * Handles scheduled tasks for the BRCC Inventory Tracker plugin.
 * This class is responsible for managing and executing scheduled tasks using WordPress cron.
 * It handles tasks such as sending daily attendee emails and other periodic operations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class BRCC_Admin_Cron {

    /**
     * Initialize the class
     * 
     * Sets up the necessary WordPress hooks for scheduled tasks.
     */
    public function __construct() {
        // Set up cron hooks
        add_action('brcc_daily_attendee_email', array($this, 'send_daily_attendee_email'));
    }
    
    /**
     * Sends the daily attendee list email via WP-Cron.
     * 
     * Generates and sends an email containing the list of attendees for events scheduled
     * for the current day. The email includes attendee information from both Eventbrite
     * and WooCommerce sources.
     * 
     * This method is hooked to the 'brcc_daily_attendee_email' cron event, which is
     * typically scheduled to run once per day in the early morning.
     */
    public function send_daily_attendee_email() {
        // Get settings
        $settings = get_option('brcc_api_settings', array());
        $test_mode = isset($settings['test_mode']) ? (bool) $settings['test_mode'] : false;
        
        // Log the start of the email process
        if ($test_mode) {
            BRCC_Helpers::log_operation('Cron', 'Daily Attendee Email', 'Starting daily attendee email process (Test Mode)');
        } else {
            BRCC_Helpers::log_operation('Cron', 'Daily Attendee Email', 'Starting daily attendee email process');
        }
        
        // Get today's date
        $today = current_time('Y-m-d');
        
        // Get products scheduled for today
        $product_mappings = new BRCC_Product_Mappings();
        $products_today = $product_mappings->get_products_for_date($today);
        
        if (empty($products_today)) {
            BRCC_Helpers::log_operation('Cron', 'Daily Attendee Email', 'No products scheduled for today (' . $today . ')');
            return;
        }
        
        // Build email content
        $email_content = '';
        $total_attendees = 0;
        
        foreach ($products_today as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;
            
            $product_name = $product->get_name();
            $email_content .= '<h3>' . esc_html($product_name) . '</h3>';
            
            // Get attendees for this product
            $attendees = array();
            
            // Get from Eventbrite if integration is available
            if (class_exists('BRCC_Eventbrite_Integration')) {
                $eventbrite = new BRCC_Eventbrite_Integration();
                $mapping = $product_mappings->get_product_mappings($product_id, $today);
                
                if (!empty($mapping['eventbrite_event_id'])) {
                    $event_id = $mapping['eventbrite_event_id'];
                    $eventbrite_data = $eventbrite->get_event_attendees($event_id);
                    
                    if (!is_wp_error($eventbrite_data) && isset($eventbrite_data['attendees'])) {
                        foreach ($eventbrite_data['attendees'] as $attendee) {
                            if (isset($attendee['profile']['name']) && isset($attendee['profile']['email'])) {
                                $attendees[] = array(
                                    'name' => sanitize_text_field($attendee['profile']['name']),
                                    'email' => sanitize_email($attendee['profile']['email']),
                                    'source' => 'Eventbrite'
                                );
                            }
                        }
                    }
                }
            }
            
            // Get from WooCommerce
            $args = array(
                'status' => array('wc-processing', 'wc-completed'),
                'limit' => -1,
                'return' => 'ids',
            );
            $query = new WC_Order_Query($args);
            $order_ids = $query->get_orders();
            
            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) continue;
                
                foreach ($order->get_items() as $item) {
                    $item_product_id = $item->get_product_id();
                    $item_variation_id = $item->get_variation_id();
                    $actual_product_id = $item_variation_id ? $item_variation_id : $item_product_id;
                    
                    if ($actual_product_id == $product_id) {
                        // Check if the item's event date matches today
                        if (method_exists('BRCC_Helpers', 'get_fooevents_date_from_item')) {
                            $item_event_date = BRCC_Helpers::get_fooevents_date_from_item($item);
                            
                            if ($item_event_date !== null && $item_event_date === $today) {
                                $attendees[] = array(
                                    'name' => $order->get_formatted_billing_full_name(),
                                    'email' => $order->get_billing_email(),
                                    'source' => 'WooCommerce'
                                );
                            }
                        }
                    }
                }
            }
            
            // Add attendees to email content
            if (!empty($attendees)) {
                $email_content .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
                $email_content .= '<tr style="background-color: #f2f2f2;">';
                $email_content .= '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">' . __('Name', 'brcc-inventory-tracker') . '</th>';
                $email_content .= '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">' . __('Email', 'brcc-inventory-tracker') . '</th>';
                $email_content .= '<th style="padding: 8px; text-align: left; border: 1px solid #ddd;">' . __('Source', 'brcc-inventory-tracker') . '</th>';
                $email_content .= '</tr>';
                
                foreach ($attendees as $attendee) {
                    $email_content .= '<tr>';
                    $email_content .= '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' . esc_html($attendee['name']) . '</td>';
                    $email_content .= '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' . esc_html($attendee['email']) . '</td>';
                    $email_content .= '<td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' . esc_html($attendee['source']) . '</td>';
                    $email_content .= '</tr>';
                }
                
                $email_content .= '</table>';
                $total_attendees += count($attendees);
            } else {
                $email_content .= '<p>' . __('No attendees found for this product.', 'brcc-inventory-tracker') . '</p>';
            }
        }
        
        // Send email if we have attendees
        if ($total_attendees > 0) {
            $to = get_option('admin_email');
            $subject = sprintf(__('[%s] Daily Attendee List for %s', 'brcc-inventory-tracker'), 
                get_bloginfo('name'), 
                date_i18n(get_option('date_format'), strtotime($today)));
            
            $headers = array('Content-Type: text/html; charset=UTF-8');
            
            $email_body = '<html><body>';
            $email_body .= '<h2>' . sprintf(__('Attendee List for %s', 'brcc-inventory-tracker'), 
                date_i18n(get_option('date_format'), strtotime($today))) . '</h2>';
            $email_body .= '<p>' . sprintf(__('Total Attendees: %d', 'brcc-inventory-tracker'), $total_attendees) . '</p>';
            $email_body .= $email_content;
            $email_body .= '</body></html>';
            
            if ($test_mode) {
                // In test mode, just log the email
                BRCC_Helpers::log_operation('Cron', 'Daily Attendee Email', 
                    sprintf('Would send email to %s with subject "%s" containing %d attendees (Test Mode)', 
                        $to, $subject, $total_attendees));
            } else {
                // In live mode, actually send the email
                $sent = wp_mail($to, $subject, $email_body, $headers);
                
                if ($sent) {
                    BRCC_Helpers::log_operation('Cron', 'Daily Attendee Email', 
                        sprintf('Successfully sent email to %s with subject "%s" containing %d attendees', 
                            $to, $subject, $total_attendees));
                } else {
                    BRCC_Helpers::log_error('Cron', 'Daily Attendee Email', 
                        sprintf('Failed to send email to %s with subject "%s"', $to, $subject));
                }
            }
        } else {
            BRCC_Helpers::log_operation('Cron', 'Daily Attendee Email', 
                'No attendees found for any products scheduled for today (' . $today . ')');
        }
    }
}
