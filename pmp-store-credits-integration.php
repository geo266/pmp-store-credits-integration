<?php

/**
 * Plugin Name: PMP Store Credits Integration
 * Description: Integrates store credits with Paid Memberships Pro - awards credits on signup/renewal, handles expiration, and WooCommerce integration
 * Version: 1.0
 * Author: Nulla Labs
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}


class PMP_Store_Credits {
    
    private $credit_amount = 5; // £5 credit amount
    private $target_membership_level = 1; // Membership Level ID
    private $expiration_days = 30;
    
        public function check_pending_level_assignments() {
        if (!current_user_can('manage_options')) return;
        
        // Get recently changed memberships (last 5 minutes)
        global $wpdb;
        $changes = $wpdb->get_results(
            "SELECT user_id, membership_id 
             FROM {$wpdb->prefix}pmpro_memberships_users 
             WHERE modified > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             AND membership_id = {$this->target_membership_level}
             AND status = 'active'"
        );
        
        foreach ($changes as $change) {
            $credits = $this->get_user_credits($change->user_id);
            if ($credits <= 0) {
                $this->add_credits($change->user_id, $this->credit_amount, 'admin_change');
                $this->debug_log("Detected direct level assignment for user {$change->user_id}");
            }
        }
    }
    
    public function display_credit_status_debug() {
        if (!is_user_logged_in()) return '';
        
        $user_id = get_current_user_id();
        return sprintf(
            '<div class="credit-debug"><strong>DEBUG:</strong> User %d | Credits: £%.2f | Membership: %s</div>',
            $user_id,
            $this->get_user_credits($user_id),
            pmpro_getMembershipLevelForUser($user_id) ? 'Active' : 'None'
        );
    }
    
    public function __construct() {
    // Don't hook to plugins_loaded - we're already being called from there
    // Just initialize immediately
    $this->init();
    
    // Database setup - can be done early
    register_activation_hook(__FILE__, array($this, 'create_credits_table'));
    }
    
    public function init() {
    // Only proceed if we're past the plugins_loaded hook to avoid translation issues
    if (!did_action('plugins_loaded')) {
        return;
    }
    
    // Check for admin-level changes on every admin page load
    if (is_admin()) {
        add_action('admin_init', [$this, 'check_pending_level_assignments']);
    }
    
    // Ultimate membership change handler
add_action('pmpro_after_change_membership_level', function($level_id, $user_id) {
    $old_level = pmpro_getMembershipLevelForUser($user_id);
    
    // If changing FROM target level TO anything else
    if ($old_level && $old_level->id == $this->target_membership_level && 
        $level_id != $this->target_membership_level) {
        
        $this->debug_log("Membership revoked - User: {$user_id}, New Level: {$level_id}");
        $this->remove_all_credits($user_id);
        
        // Force frontend refresh if user is active
        if (get_current_user_id() == $user_id) {
            echo '<script>jQuery(document.body).trigger("wc_fragment_refresh");</script>';
        }
    }
}, 10, 2);
    
    // Temporary debug endpoint
    add_action('init', function() {
        if (isset($_GET['test_auto_credit'])) {
            $user_id = get_current_user_id();
            $level = pmpro_getMembershipLevelForUser($user_id);
            
            error_log("=== AUTO CREDIT DEBUG ===");
            error_log("User ID: ".$user_id);
            error_log("Current Level: ".($level ? $level->id : 'none'));
            error_log("Target Level: ".$this->target_membership_level);
            error_log("Should Award: ".($level && $level->id == $this->target_membership_level ? 'YES' : 'NO'));
            
            wp_die("Check your error log. User ".$user_id." should ".
                   ($level && $level->id == $this->target_membership_level ? '' : 'NOT ').
                   "receive credits.");
        }
    });
    
    // Enqueue scripts
    add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    
    // PMP Hooks - only add if PMP is active
    if (function_exists('pmpro_getMembershipLevelForUser')) {
        // Credit awarding hooks
        add_action('pmpro_after_checkout', [$this, 'award_credits_after_checkout'], 10, 2);
        add_action('pmpro_subscription_payment_completed', [$this, 'award_credits_on_payment']);
        
        // Enhanced cancellation handling - handles ALL cancellation scenarios
        add_action('pmpro_after_change_membership_level', function($level_id, $user_id) {
            $old_level = pmpro_getMembershipLevelForUser($user_id);
            
            // Case 1: Complete cancellation (level_id = 0)
            // Case 2: Changed to different paid level
            if (($level_id == 0 || $level_id != $this->target_membership_level) && 
                $old_level && $old_level->id == $this->target_membership_level) {
                $this->debug_log("Membership changed from target level - User: $user_id, New Level: $level_id");
                $this->remove_all_credits($user_id);
                
                // Force WooCommerce to refresh if user is on cart/checkout
                if (class_exists('WooCommerce') && (is_cart() || is_checkout())) {
                    WC()->cart->calculate_totals();
                }
            }
        }, 10, 2);
        
        // Additional expiration hook as backup
        add_action('pmpro_membership_post_membership_expiry', [$this, 'remove_credits_on_cancellation'], 10, 2);
    }
    
    // WooCommerce Integration - only add if WooCommerce is active
    if (class_exists('WooCommerce')) {
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_credit_discount'], 20);
        add_action('woocommerce_checkout_order_processed', [$this, 'deduct_credits_on_purchase']);
        add_action('woocommerce_account_dashboard', [$this, 'display_credits_on_account_page']);
        add_action('woocommerce_before_cart_table', [$this, 'display_credit_form_on_cart']);
        add_action('woocommerce_before_checkout_form', [$this, 'display_credit_form_on_checkout']);
        
        // Add credit status to cart/checkout for debugging
        add_action('woocommerce_after_cart_table', function() {
            if (current_user_can('manage_options')) {
                $this->debug_info();
            }
        });
    }
    
    // Admin interface
    add_action('admin_menu', array($this, 'add_admin_menu'));
    add_action('wp_ajax_apply_store_credit', array($this, 'ajax_apply_credit'));
    add_action('wp_ajax_nopriv_apply_store_credit', array($this, 'ajax_apply_credit'));
    
    // Shortcodes
    add_shortcode('store_credits_balance', array($this, 'display_credits_balance'));
    add_shortcode('store_credits_form', array($this, 'display_credit_form'));
    
    // Cron jobs
    add_action('wp', array($this, 'schedule_credit_expiration'));
    add_action('pmp_expire_credits', array($this, 'expire_old_credits'));
    
    // Database management
    add_action('init', array($this, 'check_and_create_table'));
    
    // Debug tools
    if (current_user_can('manage_options')) {
        add_action('wp_footer', array($this, 'debug_info'));
        add_shortcode('credit_status', [$this, 'display_credit_status_debug']);
    }
}
    
    public function award_credits_after_checkout($user_id, $order) {
    $this->debug_log("Checkout completed - User: $user_id, Level: " . ($order->membership_id ?? 'N/A'));
    
    // Always use the level from ORDER first
    if (isset($order->membership_id) && $order->membership_id == $this->target_membership_level) {
        $this->add_credits($user_id, $this->credit_amount, 'checkout');
    }
}

    public function award_credits_on_level_change($level_id, $user_id) {
        $this->debug_log("Level changed - User: $user_id, New Level: $level_id");
        
        // Check both the new level and current status
        if ($level_id == $this->target_membership_level) {
            $level = pmpro_getMembershipLevelForUser($user_id);
            if ($level && $level->status === 'active') {
                $this->add_credits($user_id, $this->credit_amount, 'level_change');
            }
        }
    }
    
    public function award_credits_on_payment($order) {
        $this->debug_log("Payment completed - User: {$order->user_id}, Level: {$order->membership_id}");
        
        if ($order->membership_id == $this->target_membership_level) {
            $this->add_credits($order->user_id, $this->credit_amount, 'renewal');
        }
    }
    
    private function debug_log($message) {
        error_log('[PMP CREDITS] ' . $message);
        if (defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
            echo "<!-- PMP DEBUG: $message -->\n";
        }
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_enqueue_script('pmp-credits-js', plugin_dir_url(__FILE__) . 'assets/credits.js', array('jquery'), '1.0', true);
        wp_localize_script('pmp-credits-js', 'pmp_credits_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pmp_credits_nonce')
        ));
    }
    
    // Database Management
    public function check_and_create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pmp_store_credits';
        
        // Check if table exists
        if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_credits_table();
        }
    }
    
    public function create_credits_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pmp_store_credits';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            credit_amount decimal(10,2) NOT NULL,
            earned_date datetime DEFAULT CURRENT_TIMESTAMP,
            expiration_date datetime NOT NULL,
            status varchar(20) DEFAULT 'active',
            source varchar(50) DEFAULT 'membership',
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY expiration_date (expiration_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Log table creation for debugging
        error_log('PMP Store Credits: Attempting to create table ' . $table_name);
        
        // Check if table was created successfully
        if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            error_log('PMP Store Credits: Table created successfully');
        } else {
            error_log('PMP Store Credits: Table creation failed');
        }
    }
    
    // Credit Management Functions
       public function award_credits_on_signup($user_id, $order) {
        error_log("award_credits_on_signup called for user $user_id");
        
        $membership_level = pmpro_getMembershipLevelForUser($user_id);
        error_log("Detected membership level: " . print_r($membership_level, true));
        
        if ($membership_level && $membership_level->id == $this->target_membership_level) {
            error_log("User $user_id has target membership level. Awarding credits.");
            $this->add_credits($user_id, $this->credit_amount, 'signup');
        } else {
            error_log("User $user_id does NOT have target membership level.");
        }
    }
    
    public function award_credits_on_renewal($order) {
        if (!$order || !isset($order->user_id)) return;
        
        $user_id = $order->user_id;
        $membership_level = pmpro_getMembershipLevelForUser($user_id);
        
        if ($membership_level && $membership_level->id == $this->target_membership_level) {
            $this->add_credits($user_id, $this->credit_amount, 'renewal');
        }
    }
    
        public function remove_credits_on_cancellation($user_id, $level_id) {
        $this->debug_log("Membership expired/cancelled - User: $user_id, Level: $level_id");
        
        if ($level_id == $this->target_membership_level) {
            $this->debug_log("Removing credits for cancelled membership - User: $user_id");
            $this->remove_all_credits($user_id);
            
            // Also clear any active WooCommerce session
            if (class_exists('WooCommerce') && WC()->session) {
                WC()->session->__unset('use_store_credits');
                $this->debug_log("Cleared WooCommerce credit session for user: $user_id");
            }
        }
    }
    
    public function handle_membership_change($level_id, $user_id) {
        // If user is downgraded or cancelled from target level, remove credits
        if ($level_id != $this->target_membership_level) {
            $this->remove_all_credits($user_id);
        }
    }
    
    private function add_credits($user_id, $amount, $source = 'membership') {
        global $wpdb;
        
        error_log("Attempting to add credits - User: $user_id, Amount: $amount, Source: $source");
        
        // Remove existing active credits (non-stackable)
        $this->remove_all_credits($user_id);
        
        $table_name = $wpdb->prefix . 'pmp_store_credits';
        $expiration_date = date('Y-m-d H:i:s', strtotime('+' . $this->expiration_days . ' days'));
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'credit_amount' => $amount,
                'expiration_date' => $expiration_date,
                'source' => $source,
                'status' => 'active'
            ),
            array('%d', '%f', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            error_log("Failed to add credits for user $user_id. Database error: " . $wpdb->last_error);
        } else {
            error_log("Successfully added £$amount credits to user $user_id");
        }
        
        return $result;
    }
    
       private function remove_all_credits($user_id) {
    global $wpdb;
    
    // 1. Database update (mark credits as cancelled)
    $wpdb->update(
        $wpdb->prefix . 'pmp_store_credits',
        ['status' => 'cancelled'],
        ['user_id' => $user_id, 'status' => 'active'],
        ['%s'],
        ['%d', '%s']
    );
    
    // 2. Clear caches
    $cache_keys = [
        'user_credits_' . $user_id,
        'pmpro_user_' . $user_id . '_level',
        'wc_cart_totals_' . $user_id
    ];
    
    foreach ($cache_keys as $key) {
        wp_cache_delete($key, 'pmp_store_credits');
        wp_cache_delete($key, 'woocommerce');
    }
    
    // 3. Only clear WooCommerce session if WooCommerce is loaded and user is on the frontend
    if (class_exists('WooCommerce') && function_exists('WC') && !is_admin()) {
        if (isset(WC()->session)) {
            WC()->session->__unset('use_store_credits');
        }
        if (method_exists(WC()->cart, 'calculate_totals')) {
            WC()->cart->calculate_totals();
        }
    }
    
    // 4. Clean up transients
    delete_transient('pmpro_credits_' . $user_id);
    
    $this->debug_log("Removed all credits for user {$user_id}");
}
    
    public function get_user_credits($user_id) {
    // First check - mandatory membership verification
    if (function_exists('pmpro_hasMembershipLevel') && 
        !pmpro_hasMembershipLevel($this->target_membership_level, $user_id)) {
        return 0; // Immediately return 0 if no valid membership
    }

    global $wpdb;
    $credits = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(credit_amount) FROM {$wpdb->prefix}pmp_store_credits 
         WHERE user_id = %d AND status = 'active' AND expiration_date > NOW()",
        $user_id
    ));
    
    return floatval($credits ?: 0);
}
    
   function award_credits_to_existing_members() {
    error_log('Credit awarding function started'); // Debug line
    
    try {
        // Get all Level 1 members without existing credits
        $args = array(
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => 'membership_level',
                    'value' => '1',
                    'compare' => '='
                ),
                array(
                    'key' => 'user_credits',
                    'compare' => 'NOT EXISTS'
                )
            )
        );
        
        error_log('Preparing user query'); // Debug line
        $users = get_users($args);
        error_log('Found ' . count($users) . ' users to process'); // Debug line
        
        if (!empty($users)) {
            foreach ($users as $user) {
                error_log('Processing user ID: ' . $user->ID); // Debug line
                $current_credits = get_user_meta($user->ID, 'user_credits', true);
                
                if (empty($current_credits)) {
                    error_log('Adding credits to user ID: ' . $user->ID); // Debug line
                    update_user_meta($user->ID, 'user_credits', 5);
                }
            }
        }
        
        error_log('Credit awarding completed successfully'); // Debug line
        return true;
        
    } catch (Exception $e) {
        error_log('Credit awarding failed: ' . $e->getMessage()); // Debug line
        return false;
    }
}
    
            public function apply_credit_discount() {
        if (!is_user_logged_in()) return;
        
        $user_id = get_current_user_id();
        $credits = $this->get_user_credits($user_id);
        
        $this->debug_log("Credit check - User: $user_id, Credits: $credits, Session: " 
            . (WC()->session->get('use_store_credits') ? 'Applied' : 'Not applied'));
    
        if ($credits > 0) {
            // Auto-apply if not already set
            if (WC()->session->get('use_store_credits') !== 'applied') {
                WC()->session->set('use_store_credits', 'applied');
            }
            
            $cart_total = (float) WC()->cart->get_subtotal();
            $discount = min($credits, $cart_total);
            
            if ($discount > 0) {
                WC()->cart->add_fee(
                    __('Store Credit', 'pmp-store-credits'), 
                    -$discount,
                    false // Not taxable
                );
                $this->debug_log("Applied £$discount credit discount");
            }
        }
    }
    
    public function deduct_credits_on_purchase($order_id) {
        if (!WC()->session->get('use_store_credits')) return;
        
        $order = wc_get_order($order_id);
        $user_id = $order->get_user_id();
        
        if (!$user_id) return;
        
        $credits = $this->get_user_credits($user_id);
        
        foreach ($order->get_fees() as $fee) {
            if ($fee->get_name() == 'Store Credit Applied') {
                $used_credits = abs($fee->get_total());
                $this->deduct_credits($user_id, $used_credits);
                break;
            }
        }
        
        // Clear session
        WC()->session->set('use_store_credits', false);
    }
    
    private function deduct_credits($user_id, $amount) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pmp_store_credits';
        
        $wpdb->update(
            $table_name,
            array('status' => 'used'),
            array('user_id' => $user_id, 'status' => 'active'),
            array('%s'),
            array('%d', '%s')
        );
    }
    
    // Auto-display functions
    public function display_credits_on_account_page() {
        if (!is_user_logged_in()) return;
        
        $user_id = get_current_user_id();
        $credits = $this->get_user_credits($user_id);
        
        echo '<div class="woocommerce-store-credits" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px;">
                <h3>Your Store Credits</h3>
                <p style="font-size: 18px; font-weight: bold; color: #28a745;">£' . number_format($credits, 2) . '</p>
                <p style="font-size: 14px; color: #666;">Credits expire 30 days after being earned</p>
              </div>';
    }
    
    public function display_credit_form_on_cart() {
        echo $this->display_credit_form();
    }
    
    public function display_credit_form_on_checkout() {
        echo $this->display_credit_form();
    }
    
    // Debug function to check if credits are being awarded
    public function debug_info() {
        if (!current_user_can('manage_options')) return;
        
        $user_id = get_current_user_id();
        $credits = $this->get_user_credits($user_id);
        $membership = pmpro_getMembershipLevelForUser($user_id);
        
        echo '<div style="position: fixed; bottom: 10px; right: 10px; background: white; border: 1px solid #ccc; padding: 10px; z-index: 9999; font-size: 12px;">
                <strong>Debug Info:</strong><br>
                User ID: ' . $user_id . '<br>
                Credits: £' . number_format($credits, 2) . '<br>
                Membership Level: ' . ($membership ? $membership->id : 'None') . '<br>
                Target Level: ' . $this->target_membership_level . '
              </div>';
    }
    
    public function ajax_apply_credit() {
        check_ajax_referer('pmp_credits_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_die('Not logged in');
        }
        
        $user_id = get_current_user_id();
        $credits = $this->get_user_credits($user_id);
        
        if ($credits > 0) {
            WC()->session->set('use_store_credits', true);
            wp_send_json_success(array('message' => 'Store credits applied to cart'));
        } else {
            wp_send_json_error(array('message' => 'No credits available'));
        }
    }
    
    // Shortcodes
    public function display_credits_balance($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to view your store credits.</p>';
        }
        
        $user_id = get_current_user_id();
        $credits = $this->get_user_credits($user_id);
        
        return '<div class="store-credits-balance">
                    <h3>Your Store Credits</h3>
                    <p class="credits-amount">£' . number_format($credits, 2) . '</p>
                    <p class="credits-expiry">Credits expire 30 days after being earned</p>
                </div>';
    }
    
    public function display_credit_form($atts) {
        if (!is_user_logged_in() || !function_exists('WC')) {
            return '';
        }
        
        $user_id = get_current_user_id();
        $credits = $this->get_user_credits($user_id);
        
        if ($credits <= 0) {
            return ''; // Don't show anything if no credits, keeps layout cleaner
        }
        
        // FIX: Added inline styles for width:100%, clear:both, and flex-basis:100% 
        // This forces the credit box to take a full row and prevents squishing the checkout columns.
        $output = '<div class="store-credits-form" style="width: 100%; flex-basis: 100%; display: block; clear: both; margin-bottom: 20px; padding: 15px; background: #f7f7f7; border-radius: 5px;">
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                        <span style="font-weight:600;">Available Credits: £' . number_format($credits, 2) . '</span>
                        <button type="button" id="apply-store-credits" class="button alt" style="margin-left:15px;">
                            Apply Discount
                        </button>
                    </div>
                    <div id="credits-message" style="margin-top:10px;"></div>
                </div>';

        // Only output JS if we aren't in an AJAX call to prevent duplication
        if (!wp_doing_ajax()) {
            $output .= '<script>
                jQuery(document).ready(function($) {
                    // Use "body" delegation to ensure it works after AJAX updates
                    $("body").on("click", "#apply-store-credits", function(e) {
                        e.preventDefault();
                        var $btn = $(this);
                        $btn.addClass("loading").prop("disabled", true); // Visual feedback
                        
                        $.ajax({
                            url: pmp_credits_ajax.ajax_url,
                            type: "POST",
                            data: {
                                action: "apply_store_credit",
                                nonce: pmp_credits_ajax.nonce
                            },
                            success: function(response) {
                                $btn.removeClass("loading").prop("disabled", false);
                                if (response.success) {
                                    $("#credits-message").html("<div class=\"woocommerce-message\" role=\"alert\">" + response.data.message + "</div>");
                                    $("body").trigger("update_checkout");
                                } else {
                                    $("#credits-message").html("<div class=\"woocommerce-error\" role=\"alert\">" + response.data.message + "</div>");
                                }
                            },
                            error: function() {
                                $btn.removeClass("loading").prop("disabled", false);
                                $("#credits-message").html("<p style=\"color: red;\">Connection error. Please try again.</p>");
                            }
                        });
                    });
                });
                </script>';
        }
        
        return $output;
    }
    
    // Cron Job for Credit Expiration
    public function schedule_credit_expiration() {
        if (!wp_next_scheduled('pmp_expire_credits')) {
            wp_schedule_event(time(), 'daily', 'pmp_expire_credits');
        }
    }
    
    public function expire_old_credits() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pmp_store_credits';
    
    $this->debug_log("Starting credit expiration process");
    
    // 1. First, identify active members who have expiring credits BEFORE we expire them
    $members_with_expiring_credits = $wpdb->get_results(
        "SELECT DISTINCT c.user_id 
         FROM $table_name c
         INNER JOIN {$wpdb->prefix}pmpro_memberships_users pmu ON c.user_id = pmu.user_id
         WHERE c.status = 'active' 
         AND c.expiration_date < NOW()
         AND pmu.membership_id = {$this->target_membership_level} 
         AND pmu.status = 'active'"
    );
    
    $this->debug_log("Found " . count($members_with_expiring_credits) . " active members with expiring credits");
    
    // 2. Now expire the old credits
    $expired_count = $wpdb->query(
        "UPDATE $table_name 
         SET status = 'expired' 
         WHERE status = 'active' 
         AND expiration_date < NOW()"
    );
    
    $this->debug_log("Expired $expired_count credit records");
    
    // 3. Award new credits to active members who had expiring credits
    $renewed_count = 0;
    foreach ($members_with_expiring_credits as $member) {
        // Double-check membership is still active (in case it changed during the process)
        if (function_exists('pmpro_hasMembershipLevel') && 
            pmpro_hasMembershipLevel($this->target_membership_level, $member->user_id)) {
            
            $this->add_credits($member->user_id, $this->credit_amount, 'auto_renewal');
            $renewed_count++;
            $this->debug_log("Auto-renewed credits for active member: {$member->user_id}");
        }
    }
    
    $this->debug_log("Credit expiration process completed. Renewed credits for {$renewed_count} members.");
    
    return $renewed_count;
}
    
    // Admin Interface
    public function add_admin_menu() {
        // Try multiple parent menus to ensure it appears somewhere
        if (function_exists('pmpro_getOption')) {
            // If PMP is active, add under Memberships
            add_submenu_page(
                'memberships',
                'Store Credits',
                'Store Credits',
                'manage_options',
                'pmp-store-credits',
                array($this, 'admin_page')
            );
        } else {
            // If PMP not detected, add under Tools
            add_submenu_page(
                'tools.php',
                'Store Credits',
                'Store Credits',
                'manage_options',
                'pmp-store-credits',
                array($this, 'admin_page')
            );
        }
        
        // Also add as a top-level menu for easier access during testing
        add_menu_page(
            'Store Credits',
            'Store Credits',
            'manage_options',
            'pmp-store-credits-main',
            array($this, 'admin_page'),
            'dashicons-tickets-alt',
            30
        );
    }
    
    public function admin_page() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'pmp_store_credits';
        
        // Plugin status check
        $pmp_active = function_exists('pmpro_getOption');
        $woo_active = class_exists('WooCommerce');
        
        // Force table creation if it doesn't exist
        if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->create_credits_table();
            echo '<div class="notice notice-info"><p>Database table created!</p></div>';
        }
        
        // Handle form submissions
if (isset($_POST['award_credits']) && wp_verify_nonce($_POST['_wpnonce'], 'award_credits')) {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

    if ($user_id <= 0) {
        echo '<div class="notice notice-error"><p>Error: Invalid User ID.</p></div>';
    } elseif ($amount <= 0) {
        echo '<div class="notice notice-error"><p>Error: Credit amount must be greater than 0.</p></div>';
    } else {
        $result = $this->add_credits($user_id, $amount, 'admin');
        if ($result === false) {
            echo '<div class="notice notice-error"><p>Error: Failed to award credits. Check logs.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Credits awarded successfully!</p></div>';
        }
    }
}
        
        // Handle manual credit award to existing members
        if (isset($_POST['award_existing']) && wp_verify_nonce($_POST['_wpnonce'], 'award_existing')) {
            $awarded = $this->award_credits_to_existing_members();
            echo '<div class="notice notice-success"><p>Credits awarded to ' . $awarded . ' existing members!</p></div>';
        }
        
        // Get recent credit history
        $credits = $wpdb->get_results(
            "SELECT c.*, u.display_name 
             FROM $table_name c 
             JOIN {$wpdb->users} u ON c.user_id = u.ID 
             ORDER BY c.earned_date DESC 
             LIMIT 50"
        );
        
        ?>
        <div class="wrap">
            <h1>Store Credits Management</h1>
            
            <!-- Plugin Status -->
            <div class="card">
                <h2>Plugin Status</h2>
                <p><strong>Paid Memberships Pro:</strong> <?php echo $pmp_active ? '<span style="color: green;">✓ Active</span>' : '<span style="color: red;">✗ Not Active</span>'; ?></p>
                <p><strong>WooCommerce:</strong> <?php echo $woo_active ? '<span style="color: green;">✓ Active</span>' : '<span style="color: red;">✗ Not Active</span>'; ?></p>
                <p><strong>Database Table:</strong> <?php echo ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) ? '<span style="color: green;">✓ Exists</span>' : '<span style="color: red;">✗ Missing</span>'; ?></p>
            </div>
            
            <!-- Award Credits to Existing Members -->
            <div class="card">
                <h2>Award Credits to Existing Members</h2>
                <p>This will award £5 credits to all current members of Level <?php echo $this->target_membership_level; ?> who don't already have credits.</p>
                <form method="post">
                    <?php wp_nonce_field('award_existing'); ?>
                    <p class="submit">
                        <input type="submit" name="award_existing" class="button button-secondary" value="Award Credits to Existing Members" 
                               onclick="return confirm('Are you sure? This will award credits to all qualifying members.');" />
                    </p>
                </form>
            </div>
            
            <div class="card">
                <h2>Award Credits to Individual User</h2>
                <form method="post">
                    <?php wp_nonce_field('award_credits'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="user_id">User ID</label></th>
                            <td><input type="number" id="user_id" name="user_id" required /></td>
                        </tr>
                        <tr>
                            <th><label for="amount">Credit Amount</label></th>
                            <td><input type="number" id="amount" name="amount" step="0.01" required /></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="award_credits" class="button button-primary" value="Award Credits" />
                    </p>
                </form>
            </div>
            
            <div class="card">
                <h2>Recent Credits</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Source</th>
                            <th>Earned Date</th>
                            <th>Expiration Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($credits)): ?>
                        <tr>
                            <td colspan="6">No credits found. Try awarding credits to existing members first.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($credits as $credit): ?>
                        <tr>
                            <td><?php echo esc_html($credit->display_name); ?></td>
                            <td>£<?php echo number_format($credit->credit_amount, 2); ?></td>
                            <td><?php echo esc_html($credit->source); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($credit->earned_date)); ?></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($credit->expiration_date)); ?></td>
                            <td><?php echo esc_html($credit->status); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Debug Info -->
            <div class="card">
                <h2>Debug Information</h2>
                <p><strong>Current User ID:</strong> <?php echo get_current_user_id(); ?></p>
                <p><strong>Current User Credits:</strong> £<?php echo number_format($this->get_user_credits(get_current_user_id()), 2); ?></p>
                <?php if ($pmp_active): ?>
                    <?php $membership = pmpro_getMembershipLevelForUser(get_current_user_id()); ?>
                    <p><strong>Current Membership Level:</strong> <?php echo $membership ? $membership->id . ' (' . $membership->name . ')' : 'None'; ?></p>
                <?php endif; ?>
                <p><strong>Target Membership Level:</strong> <?php echo $this->target_membership_level; ?></p>
                <p><strong>Credit Amount:</strong> £<?php echo $this->credit_amount; ?></p>
            </div>
        </div>
        <?php
    }
}

// Fix: Initialize plugin with delayed timing to avoid conflicts
add_action('plugins_loaded', function() {
    // Initialize with priority 20 to let other plugins load first
    new PMP_Store_Credits();
}, 20);

// Deactivation hook to clear scheduled events
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('pmp_expire_credits');
});
?>