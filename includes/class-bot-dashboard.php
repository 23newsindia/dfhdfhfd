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
        
        // Add AJAX handlers for both systems
        add_action('wp_ajax_bot_blocker_stats', array($this, 'get_bot_stats'));
        add_action('wp_ajax_bot_blocker_unblock', array($this, 'unblock_bot'));
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
        if ($hook !== 'security-settings_page_security-bot-dashboard') {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'bot-dashboard',
            plugin_dir_url(dirname(__FILE__)) . 'assets/bot-dashboard.js',
            array('jquery'),
            '1.0.0',
            true
        );
        
        wp_localize_script('bot-dashboard', 'botDashboard', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('security_bot_stats'),
            'unblock_nonce' => wp_create_nonce('security_bot_unblock')
        ));
        
        wp_enqueue_style(
            'bot-dashboard',
            plugin_dir_url(dirname(__FILE__)) . 'assets/bot-dashboard.css',
            array(),
            '1.0.0'
        );
    }
    
    public function get_bot_stats() {
        check_ajax_referer('security_bot_stats', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'security_blocked_bots';
        
        $stats = array(
            'total_blocked' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE is_blocked = 1"),
            'today_blocked' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE is_blocked = 1 AND DATE(last_seen) = CURDATE()"),
            'week_blocked' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE is_blocked = 1 AND last_seen >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'top_blocked_ips' => $wpdb->get_results("SELECT ip_address, SUM(hits) as hits FROM {$table_name} WHERE is_blocked = 1 GROUP BY ip_address ORDER BY hits DESC LIMIT 10", ARRAY_A)
        );
        
        wp_send_json_success($stats);
    }
    
    public function unblock_bot() {
        check_ajax_referer('security_bot_unblock', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $ip = sanitize_text_field($_POST['ip']);
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'security_blocked_bots';
        
        $result = $wpdb->update(
            $table_name,
            array('is_blocked' => 0),
            array('ip_address' => $ip),
            array('%d'),
            array('%s')
        );
        
        if ($result !== false) {
            wp_send_json_success('IP unblocked successfully');
        } else {
            wp_send_json_error('Failed to unblock IP');
        }
    }
    
    public function render_dashboard_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $blocked_bots = $this->bot_protection->get_blocked_bots(20);
        $recent_activity = $this->bot_protection->get_bot_activity(30);
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-shield-alt"></span> Bot Protection Dashboard</h1>
            
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
                                                <div class="bot-user-agent"><?php echo esc_html(substr($bot->user_agent ?? '', 0, 100)); ?>...</div>
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
        <?php
    }
}