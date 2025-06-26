jQuery(document).ready(function($) {
    // Load dashboard stats
    loadBotStats();
    
    // Auto-refresh every 30 seconds
    setInterval(loadBotStats, 30000);
    
    // Unblock bot functionality
    $(document).on('click', '.unblock-bot', function() {
        var ip = $(this).data('ip');
        var button = $(this);
        
        if (!confirm('Are you sure you want to unblock IP: ' + ip + '?')) {
            return;
        }
        
        button.prop('disabled', true).text('Unblocking...');
        
        $.ajax({
            url: botDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'bot_blocker_unblock',
                nonce: botDashboard.unblock_nonce,
                ip: ip
            },
            success: function(response) {
                if (response.success) {
                    button.closest('tr').fadeOut(function() {
                        $(this).remove();
                    });
                    showNotice('IP unblocked successfully', 'success');
                } else {
                    showNotice('Failed to unblock IP: ' + response.data, 'error');
                    button.prop('disabled', false).text('Unblock');
                }
            },
            error: function() {
                showNotice('Error occurred while unblocking IP', 'error');
                button.prop('disabled', false).text('Unblock');
            }
        });
    });
    
    function loadBotStats() {
        $.ajax({
            url: botDashboard.ajaxurl,
            type: 'POST',
            data: {
                action: 'bot_blocker_stats',
                nonce: botDashboard.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    $('#total-blocked').text(data.total_blocked);
                    $('#today-blocked').text(data.today_blocked);
                    $('#week-blocked').text(data.week_blocked);
                    
                    // Update top blocked IPs
                    var topBlockedHtml = '<ul class="top-blocked-list">';
                    if (data.top_blocked_ips && data.top_blocked_ips.length > 0) {
                        $.each(data.top_blocked_ips, function(index, item) {
                            topBlockedHtml += '<li><strong>' + item.ip_address + '</strong> - ' + item.hits + ' hits</li>';
                        });
                    } else {
                        topBlockedHtml += '<li>No blocked IPs found</li>';
                    }
                    topBlockedHtml += '</ul>';
                    $('#top-blocked-ips').html(topBlockedHtml);
                }
            },
            error: function() {
                console.log('Failed to load bot stats');
            }
        });
    }
    
    function showNotice(message, type) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after(notice);
        
        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
});