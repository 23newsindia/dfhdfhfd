<?php
// includes/class-bot-dashboard.php

if (!defined('ABSPATH')) {
    exit;
}

class BotDashboard {
    private $bot_protection;
    
    public function __construct($bot_protection) {
        $this->bot_protection = $bot_protection;
    }
    
    public function init() {
        add_action('admin_menu', array($this, 'add_dashboard_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_dashboard_scripts'));
        
        // Add AJAX handlers - these need to be registered properly
        add_action('wp_ajax_bot_blocker_stats', array($this, 'get_bot_stats'));
        add_action('wp_ajax_bot_blocker_unblock', array($this, 'unblock_bot'));
        
        // Also add nopriv handlers for debugging (remove in production)
        add_action('wp_ajax_nopriv_bot_blocker_stats', array($this, 'handle_unauthorized_request'));
        add_action('wp_ajax_nopriv_bot_blocker_unblock', array($this, 'handle_unauthorized_request'));
    }
    
    public function handle_unauthorized_request() {
        wp_send_json_error('Unauthorized access');
    }
    
    public function add_dashboard_page() {
        add_submenu_page(
            'security-settings',
            'Bot Protection Dashboard',
            'Bot Dashboard',
            'manage_options',
            'security-bot-dashboard',
            array($this, 'render_dashboard_page')
        );
    }
    
    public function enqueue_dashboard_scripts($hook) {
        // Only load on our dashboard page
        if ($hook !== 'security-settings_page_security-bot-dashboard') {
            return;
        }
        
        // Ensure jQuery is loaded
        wp_enqueue_script('jquery');
        
        // Enqueue our dashboard script
        wp_enqueue_script(
            'bot-dashboard',
            plugin_dir_url(dirname(__FILE__)) . 'assets/bot-dashboard.js',
            array('jquery'),
            '1.0.1', // Increment version to force reload
            true
        );
        
        // Localize script with proper data
        wp_localize_script('bot-dashboard', 'botDashboard', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('security_bot_stats'),
            'unblock_nonce' => wp_create_nonce('security_bot_unblock'),
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ));
        
        // Enqueue dashboard styles
        wp_enqueue_style(
            'bot-dashboard',
            plugin_dir_url(dirname(__FILE__)) . 'assets/bot-dashboard.css',
            array(),
            '1.0.1'
        );
    }
    
    public function get_bot_stats() {
        // Verify nonce first
        if (!check_ajax_referer('security_bot_stats', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'security_blocked_bots';
            
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
            
            if (!$table_exists) {
                // Try to create the table
                if ($this->bot_protection && method_exists($this->bot_protection, 'ensure_table_exists')) {
                    $this->bot_protection->ensure_table_exists();
                }
                
                // Return default stats if table still doesn't exist
                $stats = array(
                    'total_blocked' => 0,
                    'today_blocked' => 0,
                    'week_blocked' => 0,
                    'top_blocked_ips' => array()
                );
                wp_send_json_success($stats);
                return;
            }
            
            // Get stats from database
            $stats = array(
                'total_blocked' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE is_blocked = 1"),
                'today_blocked' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE is_blocked = 1 AND DATE(last_seen) = CURDATE()"),
                'week_blocked' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE is_blocked = 1 AND last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
                'top_blocked_ips' => $wpdb->get_results("SELECT ip_address, SUM(hits) as hits FROM {$table_name} WHERE is_blocked = 1 GROUP BY ip_address ORDER BY hits DESC LIMIT 10", ARRAY_A)
            );
            
            // Ensure top_blocked_ips is an array
            if (!$stats['top_blocked_ips']) {
                $stats['top_blocked_ips'] = array();
            }
            
            wp_send_json_success($stats);
            
        } catch (Exception $e) {
            error_log('Bot Dashboard Stats Error: ' . $e->getMessage());
            wp_send_json_error('Database error: ' . $e->getMessage());
        }
    }
    
    public function unblock_bot() {
        // Verify nonce
        if (!check_ajax_referer('security_bot_unblock', 'nonce', false)) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        // Validate IP parameter
        if (!isset($_POST['ip']) || empty($_POST['ip'])) {
            wp_send_json_error('IP address is required');
            return;
        }
        
        $ip = sanitize_text_field($_POST['ip']);
        
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            wp_send_json_error('Invalid IP address format');
            return;
        }
        
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'security_blocked_bots';
            
            // Update database
            $result = $wpdb->update(
                $table_name,
                array('is_blocked' => 0),
                array('ip_address' => $ip),
                array('%d'),
                array('%s')
            );
            
            // Also remove from transient cache if using BotBlackhole
            $blocked_transient = 'bot_blocked_' . md5($ip);
            delete_transient($blocked_transient);
            
            if ($result !== false) {
                wp_send_json_success('IP unblocked successfully');
            } else {
                wp_send_json_error('Failed to unblock IP - IP may not exist in database');
            }
            
        } catch (Exception $e) {
            error_log('Bot Dashboard Unblock Error: ' . $e->getMessage());
            wp_send_json_error('Database error: ' . $e->getMessage());
        }
    }
    
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        // Get data for initial display
        $blocked_bots = array();
        $recent_activity = array();
        
        try {
            if ($this->bot_protection && method_exists($this->bot_protection, 'get_blocked_bots')) {
                $blocked_bots = $this->bot_protection->get_blocked_bots(20);
            }
            
            if ($this->bot_protection && method_exists($this->bot_protection, 'get_bot_activity')) {
                $recent_activity = $this->bot_protection->get_bot_activity(30);
            }
        } catch (Exception $e) {
            error_log('Bot Dashboard Render Error: ' . $e->getMessage());
        }
        
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-shield-alt"></span> Bot Protection Dashboard</h1>
            
            <!-- Debug Info (remove in production) -->
            <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
            <div class="notice notice-info">
                <p><strong>Debug Info:</strong></p>
                <ul>
                    <li>AJAX URL: <?php echo admin_url('admin-ajax.php'); ?></li>
                    <li>Current User Can Manage: <?php echo current_user_can('manage_options') ? 'Yes' : 'No'; ?></li>
                    <li>Bot Protection Class: <?php echo get_class($this->bot_protection); ?></li>
                    <li>jQuery Loaded: <span id="jquery-status">Checking...</span></li>
                </ul>
            </div>
            <?php endif; ?>
            
            <div class="bot-dashboard-stats">
                <div class="bot-stat-card">
                    <h3>Total Blocked</h3>
                    <div class="stat-number" id="total-blocked">Loading...</div>
                </div>
                <div class="bot-stat-card">
                    <h3>Blocked Today</h3>
                    <div class="stat-number" id="today-blocked">Loading...</div>
                </div>
                <div class="bot-stat-card">
                    <h3>Blocked This Week</h3>
                    <div class="stat-number" id="week-blocked">Loading...</div>
                </div>
            </div>
            
            <div class="bot-dashboard-content">
                <div class="bot-dashboard-section">
                    <h2>Currently Blocked IPs</h2>
                    <div class="bot-table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Hits</th>
                                    <th>Reason</th>
                                    <th>Last Seen</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($blocked_bots)): ?>
                                    <tr>
                                        <td colspan="5">No blocked bots found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($blocked_bots as $bot): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html($bot->ip_address); ?></strong>
                                                <?php if (!empty($bot->user_agent)): ?>
                                                <div class="bot-user-agent"><?php echo esc_html(substr($bot->user_agent, 0, 100)); ?>...</div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html($bot->hits ?? 0); ?></td>
                                            <td><?php echo esc_html($bot->block_reason ?? $bot->blocked_reason ?? 'Unknown'); ?></td>
                                            <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($bot->last_seen ?? $bot->timestamp ?? 'now'))); ?></td>
                                            <td>
                                                <button class="button unblock-bot" data-ip="<?php echo esc_attr($bot->ip_address); ?>">
                                                    Unblock
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="bot-dashboard-section">
                    <h2>Recent Bot Activity</h2>
                    <div class="bot-table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Status</th>
                                    <th>Hits</th>
                                    <th>Reason</th>
                                    <th>Last Request</th>
                                    <th>Last Seen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_activity)): ?>
                                    <tr>
                                        <td colspan="6">No recent activity found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_activity as $activity): ?>
                                        <tr class="<?php echo ($activity->is_blocked ?? 0) ? 'blocked-row' : 'warning-row'; ?>">
                                            <td>
                                                <strong><?php echo esc_html($activity->ip_address); ?></strong>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo ($activity->is_blocked ?? 0) ? 'blocked' : 'monitoring'; ?>">
                                                    <?php echo ($activity->is_blocked ?? 0) ? 'Blocked' : 'Monitoring'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo esc_html($activity->hits ?? 0); ?></td>
                                            <td><?php echo esc_html($activity->block_reason ?? $activity->blocked_reason ?? 'Unknown'); ?></td>
                                            <td>
                                                <code><?php echo esc_html(substr($activity->request_uri ?? '', 0, 50)); ?>...</code>
                                            </td>
                                            <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($activity->last_seen ?? $activity->timestamp ?? 'now'))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="bot-dashboard-section">
                    <h2>Top Blocked IPs</h2>
                    <div id="top-blocked-ips">Loading...</div>
                </div>
            </div>
        </div>
        
        <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
        <script>
        // Debug jQuery status
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('jquery-status').textContent = typeof jQuery !== 'undefined' ? 'Yes' : 'No';
        });
        </script>
        <?php endif; ?>
        <?php
    }
}