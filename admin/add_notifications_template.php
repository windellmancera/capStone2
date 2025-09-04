<!-- NOTIFICATION SYSTEM TEMPLATE FOR ADMIN PAGES -->

<!-- Replace the existing notification button with this: -->
<!-- Real-Time Notification System -->
<div class="relative">
    <button id="notificationBtn" class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-gray-700/30 transition-colors relative">
        <i class="fas fa-bell text-lg"></i>
        <!-- Notification Badge -->
        <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center font-bold hidden">
            0
        </span>
    </button>
    
    <!-- Notification Dropdown -->
    <div id="notificationDropdown" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 opacity-0 invisible transition-all duration-200 transform scale-95 origin-top-right z-50 max-h-96 overflow-y-auto">
        <div class="p-4 border-b border-gray-100">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                <div class="flex items-center space-x-2">
                    <button onclick="markAllAsRead()" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                        Mark all read
                    </button>
                    <button onclick="clearAllNotifications()" class="text-sm text-red-600 hover:text-red-800 font-medium">
                        Clear all
                    </button>
                </div>
            </div>
        </div>
        
        <div id="notificationList" class="p-2">
            <!-- Notifications will be loaded here -->
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-bell text-3xl mb-3"></i>
                <p>No notifications yet</p>
            </div>
        </div>
        
        <div class="p-3 border-t border-gray-100 bg-gray-50">
            <div class="text-xs text-gray-500 text-center">
                Real-time updates every 3 seconds
            </div>
            <!-- Debug Info -->
            <div id="debugInfo" class="mt-2 text-xs text-gray-400 text-center hidden">
                <div>Status: <span id="connectionStatus">Connecting...</span></div>
                <div>Last Update: <span id="lastUpdate">Never</span></div>
                <div>Notifications: <span id="notificationCount">0</span></div>
            </div>
            <button onclick="toggleDebug()" class="text-xs text-blue-600 hover:text-blue-800 mt-1">
                Toggle Debug
            </button>
        </div>
    </div>
</div>

<!-- Add this CSS to the <style> section: -->
/*
Notification Animation Classes
*/
.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: .5;
    }
}

.animate-bounce {
    animation: bounce 1s infinite;
}

@keyframes bounce {
    0%, 20%, 53%, 80%, 100% {
        transform: translate3d(0,0,0);
    }
    40%, 43% {
        transform: translate3d(0,-30px,0);
    }
    70% {
        transform: translate3d(0,-15px,0);
    }
    90% {
        transform: translate3d(0,-4px,0);
    }
}

<!-- Add this JavaScript before the closing </script> tag: -->
// Real-Time Notification System using Server-Sent Events (SSE)
console.log('Initializing real-time SSE notification system...');

const notificationBtn = document.getElementById('notificationBtn');
const notificationDropdown = document.getElementById('notificationDropdown');
const notificationBadge = document.getElementById('notificationBadge');
const notificationList = document.getElementById('notificationList');

let notifications = [];
let unreadCount = 0;
let eventSource = null;

if (!notificationBtn || !notificationDropdown) {
    console.error('Notification elements not found!');
} else {
    // Connect to real-time server
    function connectToRealTimeServer() {
        if (eventSource) {
            eventSource.close();
        }
        
        console.log('Connecting to admin real-time notification server...');
        eventSource = new EventSource('real_time_server.php');
        
        eventSource.onopen = function(event) {
            console.log('✅ Connected to admin real-time notifications');
            showNotificationAction('Connected to real-time notifications! 🚀', 'success');
            updateDebugInfo('Connected', unreadCount);
        };
        
        eventSource.addEventListener('notifications', function(event) {
            const data = JSON.parse(event.data);
            console.log('🔄 Real-time notifications received:', data);
            
            notifications = data.notifications;
            unreadCount = data.unread_count;
            
            updateBadge();
            renderNotifications();
            updateDebugInfo('Connected', unreadCount);
        });
        
        eventSource.addEventListener('count_update', function(event) {
            const data = JSON.parse(event.data);
            const newCount = data.unread_count;
            if (newCount !== unreadCount) {
                unreadCount = newCount;
                updateBadge();
                updateDebugInfo('Connected', unreadCount);
            }
        });
        
        eventSource.addEventListener('error', function(event) {
            console.error('❌ SSE Error:', event);
            updateDebugInfo('Error - Reconnecting', unreadCount);
            setTimeout(connectToRealTimeServer, 5000);
        });
        
        eventSource.onerror = function(event) {
            console.error('❌ SSE Connection error:', event);
            eventSource.close();
            setTimeout(connectToRealTimeServer, 5000);
        };
    }
    
    // Initialize real-time connection
    connectToRealTimeServer();
    
    // Update badge
    function updateBadge() {
        if (unreadCount > 0) {
            notificationBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
            notificationBadge.classList.remove('hidden');
            notificationBadge.classList.add('animate-pulse');
        } else {
            notificationBadge.classList.add('hidden');
            notificationBadge.classList.remove('animate-pulse');
        }
    }
    
    // Render notifications
    function renderNotifications() {
        if (notifications.length === 0) {
            notificationList.innerHTML = `
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-bell text-3xl mb-3"></i>
                    <p>No notifications</p>
                </div>
            `;
            return;
        }
        
        notificationList.innerHTML = notifications.map(notification => `
            <div class="notification-item p-3 border-b border-gray-100 last:border-b-0 hover:bg-gray-50 transition-colors cursor-pointer" 
                 data-notification-id="${notification.id}" 
                 data-notification-type="${notification.type}">
                <div class="flex items-start space-x-3">
                    <div class="flex-shrink-0 mt-1">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center ${getTypeColor(notification.type)}">
                            <i class="${getTypeIcon(notification.type)} text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-medium text-gray-900">${notification.title}</h4>
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getTypeBadgeColor(notification.type)}">
                                ${notification.priority}
                            </span>
                        </div>
                        <p class="text-sm text-gray-600 mt-1">${notification.message}</p>
                        <div class="flex items-center justify-between mt-2">
                            <span class="text-xs text-gray-400">${getTimeAgo(notification.timestamp)}</span>
                            <button onclick="event.stopPropagation(); markAsRead('${notification.id}')" 
                                    class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                Mark read
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
        
        // Add click event to notification items
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', function() {
                const id = this.dataset.notificationId;
                const type = this.dataset.notificationType;
                handleNotificationClick(id, type);
            });
        });
    }
    
    // Helper functions
    function getTypeIcon(type) {
        switch (type) {
            case 'success': return 'fas fa-check-circle';
            case 'warning': return 'fas fa-exclamation-triangle';
            case 'error': return 'fas fa-times-circle';
            case 'alert': return 'fas fa-bell';
            default: return 'fas fa-info-circle';
        }
    }
    
    function getTypeColor(type) {
        switch (type) {
            case 'success': return 'bg-green-500';
            case 'warning': return 'bg-yellow-500';
            case 'error': return 'bg-red-500';
            case 'alert': return 'bg-blue-500';
            default: return 'bg-gray-500';
        }
    }
    
    function getTypeBadgeColor(type) {
        switch (type) {
            case 'success': return 'bg-green-100 text-green-800';
            case 'warning': return 'bg-yellow-100 text-yellow-800';
            case 'error': return 'bg-red-100 text-red-800';
            case 'alert': return 'bg-blue-100 text-blue-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    }
    
    function getTimeAgo(timestamp) {
        const now = Math.floor(Date.now() / 1000);
        const diff = now - timestamp;
        
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return Math.floor(diff / 86400) + 'd ago';
    }
    
    function handleNotificationClick(id, type) {
        markAsRead(id);
        showNotificationAction('Notification action triggered! ✅', 'success');
        
        const item = document.querySelector(`[data-notification-id="${id}"]`);
        if (item) {
            item.style.border = '2px solid #3b82f6';
            item.style.backgroundColor = '#eff6ff';
            setTimeout(() => {
                item.style.border = '';
                item.style.backgroundColor = '';
            }, 2000);
        }
    }
    
    function showNotificationAction(message, type) {
        const actionDiv = document.createElement('div');
        actionDiv.className = `fixed top-4 right-4 z-50 px-4 py-2 rounded-lg shadow-lg text-white font-medium transform transition-all duration-300 ${
            type === 'success' ? 'bg-green-500' :
            type === 'warning' ? 'bg-yellow-500' :
            type === 'error' ? 'bg-red-500' :
            'bg-blue-500'
        }`;
        actionDiv.textContent = message;
        
        document.body.appendChild(actionDiv);
        
        setTimeout(() => {
            actionDiv.style.transform = 'translateX(0)';
        }, 100);
        
        setTimeout(() => {
            actionDiv.style.transform = 'translateX(100%)';
            setTimeout(() => {
                document.body.removeChild(actionDiv);
            }, 300);
        }, 3000);
    }
    
    function toggleDropdown() {
        const isVisible = !notificationDropdown.classList.contains('invisible');
        
        if (isVisible) {
            notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
        } else {
            notificationDropdown.classList.remove('invisible', 'opacity-0', 'scale-95');
        }
    }
    
    // Add click event
    notificationBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        toggleDropdown();
    });
    
    // Close when clicking outside
    document.addEventListener('click', function(e) {
        if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
            notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
        }
    });
    
    // Clean up on page unload
    window.addEventListener('beforeunload', function() {
        if (eventSource) {
            eventSource.close();
        }
    });
    
    console.log('Real-time SSE notification system initialized successfully!');
}

// Global notification action functions
function markAsRead(notificationId) {
    console.log('Marking notification as read:', notificationId);
    
    fetch('mark_notification_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `notification_id=${notificationId}&notification_type=general`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('✅ Notification marked as read');
            
            if (unreadCount > 0) {
                unreadCount--;
                updateBadge();
            }
            
            notifications = notifications.filter(n => n.id !== notificationId);
            renderNotifications();
            
            showNotificationAction('Notification marked as read! ✅', 'success');
        } else {
            console.error('❌ Failed to mark notification as read:', data.error);
            showNotificationAction('Failed to mark as read! ❌', 'error');
        }
    })
    .catch(error => {
        console.error('❌ Error marking notification as read:', error);
        showNotificationAction('Error marking as read! ❌', 'error');
    });
}

function markAllAsRead() {
    console.log('Marking all notifications as read');
    
    fetch('notification_sync.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=mark_all_read'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('✅ All notifications marked as read');
            
            unreadCount = 0;
            notifications = [];
            updateBadge();
            renderNotifications();
            
            showNotificationAction('All notifications marked as read! ✅', 'success');
        } else {
            console.error('❌ Failed to mark all notifications as read:', data.error);
            showNotificationAction('Failed to mark all as read! ❌', 'error');
        }
    })
    .catch(error => {
        console.error('❌ Error marking all notifications as read:', error);
        showNotificationAction('Error marking all as read! ❌', 'error');
    });
}

function clearAllNotifications() {
    console.log('Clearing all notifications');
    
    if (!confirm('Are you sure you want to clear all notifications? This action cannot be undone.')) {
        return;
    }
    
    fetch('notification_sync.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=clear_all'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('✅ All notifications cleared');
            
            unreadCount = 0;
            notifications = [];
            updateBadge();
            renderNotifications();
            
            showNotificationAction('All notifications cleared! ✅', 'success');
        } else {
            console.error('❌ Failed to clear all notifications:', data.error);
            showNotificationAction('Failed to clear all! ❌', 'error');
        }
    })
    .catch(error => {
        console.error('❌ Error clearing all notifications:', error);
        showNotificationAction('Error clearing all! ❌', 'error');
    });
}

// Debug functions
function toggleDebug() {
    const debugInfo = document.getElementById('debugInfo');
    debugInfo.classList.toggle('hidden');
}

function updateDebugInfo(status, count) {
    const connectionStatus = document.getElementById('connectionStatus');
    const lastUpdate = document.getElementById('lastUpdate');
    const notificationCount = document.getElementById('notificationCount');
    
    if (connectionStatus) connectionStatus.textContent = status;
    if (lastUpdate) lastUpdate.textContent = new Date().toLocaleTimeString();
    if (notificationCount) notificationCount.textContent = count;
}
