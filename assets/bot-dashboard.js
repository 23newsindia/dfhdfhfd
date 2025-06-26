jQuery(document).ready(function($) {
    // Debug logging
    function debugLog(message, data) {
        if (typeof botDashboard !== 'undefined' && botDashboard.debug) {
            console.log('[Bot Dashboard] ' + message, data || '');
        }
    }
    
    debugLog('Bot Dashboard script loaded');
    debugLog('botDashboard object:', botDashboard);
    
    // Check if botDashboard object exists
    if (typeof botDashboard === 'undefined') {
        console.error('botDashboard object not found. Script localization failed.');
        showError('Configuration error. Please refresh the page.');
        return;
    }
    
    // Load dashboard stats immediately
    loadBotStats();
    
    // Auto-refresh every 30 seconds
    setInterval(loadBotStats, 30000);
    
    // Unblock bot functionality
    $(document).on('click', '.unblock-bot', function() {
        var ip = $(this).data('ip');
        var button = $(this);
        
        if (!ip) {
            showError('Invalid IP address');
            return;
        }
        
        if (!confirm('Are you sure you want to unblock IP: ' + ip + '?')) {
            return;
        }
        
        button.prop('disabled', true).text('Unblocking...');
        
        debugLog('Unblocking IP:', ip);
        
        $.ajax({
            url: botDashboard.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'bot_blocker_unblock',
                nonce: botDashboard.unblock_nonce,
                ip: ip
            },
            success: function(response) {
                debugLog('Unblock response:', response);
                
                if (response && response.success) {
                    button.closest('tr').fadeOut(function() {
                        $(this).remove();
                    });
                    showNotice('IP unblocked successfully', 'success');
                    // Reload stats after unblocking
                    loadBotStats();
                } else {
                    var errorMsg = response && response.data ? response.data : 'Unknown error';
                    showNotice('Failed to unblock IP: ' + errorMsg, 'error');
                    button.prop('disabled', false).text('Unblock');
                }
            },
            error: function(xhr, status, error) {
                debugLog('Unblock AJAX error:', {xhr: xhr, status: status, error: error});
                
                var errorMsg = 'Error occurred while unblocking IP';
                if (xhr.responseText) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.data) {
                            errorMsg += ': ' + response.data;
                        }
                    } catch (e) {
                        errorMsg += ': ' + error;
                    }
                } else {
                    errorMsg += ': ' + error;
                }
                
                showNotice(errorMsg, 'error');
                button.prop('disabled', false).text('Unblock');
            }
        });
    });
    
    function loadBotStats() {
        debugLog('Loading bot stats...');
        
        $.ajax({
            url: botDashboard.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'bot_blocker_stats',
                nonce: botDashboard.nonce
            },
            success: function(response) {
                debugLog('Stats response:', response);
                
                if (response && response.success && response.data) {
                    var data = response.data;
                    
                    // Update stats with fallback values
                    $('#total-blocked').text(data.total_blocked || 0);
                    $('#today-blocked').text(data.today_blocked || 0);
                    $('#week-blocked').text(data.week_blocked || 0);
                    
                    // Update top blocked IPs
                    var topBlockedHtml = '<ul class="top-blocked-list">';
                    if (data.top_blocked_ips && data.top_blocked_ips.length > 0) {
                        $.each(data.top_blocked_ips, function(index, item) {
                            topBlockedHtml += '<li><strong>' + escapeHtml(item.ip_address) + '</strong> - ' + (item.hits || 0) + ' hits</li>';
                        });
                    } else {
                        topBlockedHtml += '<li>No blocked IPs found</li>';
                    }
                    topBlockedHtml += '</ul>';
                    $('#top-blocked-ips').html(topBlockedHtml);
                } else {
                    var errorMsg = response && response.data ? response.data : 'Unknown error';
                    console.error('Failed to load bot stats:', errorMsg);
                    setErrorValues();
                }
            },
            error: function(xhr, status, error) {
                debugLog('Stats AJAX error:', {xhr: xhr, status: status, error: error});
                
                var errorMsg = 'Failed to load statistics';
                if (xhr.responseText) {
                    // Check if response is HTML (likely an error page)
                    if (xhr.responseText.indexOf('<') === 0) {
                        errorMsg += ' (Server returned HTML instead of JSON - check for PHP errors)';
                        console.error('Server response:', xhr.responseText.substring(0, 200) + '...');
                    } else {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.data) {
                                errorMsg += ': ' + response.data;
                            }
                        } catch (e) {
                            errorMsg += ': ' + error;
                        }
                    }
                } else {
                    errorMsg += ': ' + error;
                }
                
                console.error('AJAX Error loading stats:', errorMsg);
                setErrorValues();
                showError(errorMsg);
            }
        });
    }
    
    function setErrorValues() {
        $('#total-blocked').text('Error');
        $('#today-blocked').text('Error');
        $('#week-blocked').text('Error');
        $('#top-blocked-ips').html('<ul class="top-blocked-list"><li>Error loading data</li></ul>');
    }
    
    function showNotice(message, type) {
        var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + escapeHtml(message) + '</p></div>');
        $('.wrap h1').after(notice);
        
        setTimeout(function() {
            notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    function showError(message) {
        showNotice(message, 'error');
    }
    
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});