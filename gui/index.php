<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tinyproxy Manager</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>🔒 Tinyproxy Domain Manager</h1>
        
        <div class="card" id="killswitch-card">
            <h2>🛑 Traffic Kill-Switch</h2>
            <p class="help-text" style="margin-bottom: 15px;">
                Immediately stops all traffic through the proxy. All requests will be blocked until traffic is re-enabled.
            </p>
            <div id="killswitch-container">
                <button id="killswitch-btn" class="btn-killswitch" onclick="toggleTrafficBlock()">
                    <span class="killswitch-icon">⏹</span>
                    <span class="killswitch-text">Stop All Traffic</span>
                </button>
            </div>
            <div id="killswitch-status"></div>
        </div>

        <div class="card" id="container-card">
            <h2>🐳 Container Access Control</h2>
            <div class="container-controls">
                <button class="btn-restart" onclick="refreshContainers()">↻ Refresh</button>
                <button class="btn-sync-allow" onclick="syncAllowRules()">⚡ Sync IPs &amp; Restart</button>
            </div>
            <div class="policy-section" id="policy-section">
                <div class="policy-row">
                    <span class="policy-label">New clients:</span>
                    <div class="policy-buttons">
                        <button id="policy-block-btn" class="btn-policy btn-policy-block" onclick="setClientPolicy('block')">🔒 Block new</button>
                        <button id="policy-allow-btn" class="btn-policy btn-policy-allow" onclick="setClientPolicy('allow')">🔓 Allow new</button>
                    </div>
                    <span class="policy-hint" id="policy-hint"></span>
                </div>
            </div>
            <div id="container-list">
                <p class="loading">Loading containers...</p>
            </div>
        </div>

        <div class="card">
            <h2>🔴 Live-Traffic Monitor</h2>
            <div class="traffic-controls">
                <button class="btn-restart" onclick="refreshTraffic()">↻ Refresh</button>
                <label class="auto-refresh-toggle">
                    <input type="checkbox" id="auto-refresh" checked>
                    <span>Auto-Refresh (5s)</span>
                </label>
            </div>

            <!-- Domain Filter Section -->
            <div class="traffic-filter-section">
                <div class="filter-header">
                    <h3>🔍 Domain Filter</h3>
                    <button class="btn-toggle-filter" onclick="toggleFilterPanel()">
                        <span id="filter-toggle-icon">▼</span> <span id="filter-count">(0 filtered)</span>
                    </button>
                </div>
                <div id="filter-panel" class="filter-panel" style="display: none;">
                    <p class="help-text" style="margin-bottom: 10px;">
                        Hide specific domains from the traffic monitor. Useful for reducing clutter from frequent or uninteresting domains.
                    </p>
                    <div class="filter-input-row">
                        <input type="text" id="filter-domain-input" placeholder="e.g. example.com, ads.example.com" />
                        <button class="btn-add" onclick="addDomainFilter()">Add Filter</button>
                        <button class="btn-clear-filters" onclick="clearAllFilters()">Clear All</button>
                    </div>
                    <div id="filtered-domains-list" class="filtered-domains-list">
                        <!-- Dynamically filled -->
                    </div>
                </div>
            </div>

            <div id="traffic-list">
                <p class="loading">Loading traffic data...</p>
            </div>
        </div>

        <div class="card">
            <h2 id="blocked-domains-title">Blocked Domains</h2>
            <div id="blocked-list">
                <?php
                $file = '/app/blocked-domains.txt';
                if (file_exists($file)) {
                    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $hasContent = false;
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line) || substr($line, 0, 1) === '#') continue;
                        $hasContent = true;
                        $escapedLine = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
                        echo "<div class='domain-item'>";
                        echo "<span class='domain'>$escapedLine</span>";
                        echo "<button class='btn-delete' onclick='deleteDomain(\"" . addslashes($escapedLine) . "\")'>Delete</button>";
                        echo "</div>";
                    }
                    if (!$hasContent) {
                        echo "<p class='no-domains'>No domains blocked yet</p>";
                    }
                } else {
                    echo "<p class='error'>File not found!</p>";
                }
                ?>
            </div>
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <h3 style="margin-bottom: 12px;">Add Domain</h3>
                <form id="add-form">
                    <input type="text" id="new-domain" placeholder="e.g. ^.*example\.com$" required>
                    <button type="submit" class="btn-add">Add</button>
                </form>
                <p class="help-text" style="margin-top: 10px;">
                    <strong>Regex Patterns:</strong><br>
                    • <code>^.*example\.com$</code> - Blocks all subdomains of example.com<br>
                    • <code>^.*\.ads\..*$</code> - Blocks all domains with "ads"<br>
                    • <code>^facebook\.com$</code> - Blocks only exact facebook.com
                </p>
            </div>
        </div>

        <div class="card">
            <h2>Proxy Status & Info</h2>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Proxy Port:</span>
                    <span class="info-value">8888</span>
                </div>
                <div class="info-item">
                    <span class="info-label">GUI Port:</span>
                    <span class="info-value">8080</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Container:</span>
                    <span class="info-value">tinyproxy</span>
                </div>
            </div>
            <p class="help-text" style="margin-top: 15px;">
                After changes to the blocklist: <code>docker-compose restart tinyproxy</code>
            </p>
            <div id="status"></div>
        </div>

        <div class="card">
            <h2>🔄 Upstream Proxy (Proxy Forwarding)</h2>
            <p class="help-text" style="margin-bottom: 15px;">
                Configure a second proxy through which Tinyproxy forwards all traffic (Proxy Chaining).
            </p>
            <div id="upstream-config">
                <div class="upstream-form">
                    <label class="upstream-toggle">
                        <input type="checkbox" id="upstream-enabled">
                        <span>Enable Upstream Proxy</span>
                    </label>

                    <div id="upstream-fields" style="display: none; margin-top: 15px;">
                        <div class="form-row">
                            <div class="form-field">
                                <label for="upstream-host">Proxy Host/IP:</label>
                                <input type="text" id="upstream-host" placeholder="e.g. proxy.example.com or 192.168.1.100">
                            </div>
                            <div class="form-field">
                                <label for="upstream-port">Proxy Port:</label>
                                <input type="number" id="upstream-port" placeholder="e.g. 3128" min="1" max="65535">
                            </div>
                        </div>
                        <button class="btn-add" onclick="saveUpstream()" style="margin-top: 10px;">Save Upstream Proxy</button>

                        <div id="noproxy-section" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                            <h3 style="margin-bottom: 10px;">🔓 NoProxy (Direct Connections)</h3>
                            <p class="help-text" style="margin-bottom: 10px;">
                                Domains/IPs that should NOT be routed through the Upstream Proxy (direct access).
                            </p>

                            <div id="noproxy-list" style="margin-bottom: 10px;">
                                <!-- Dynamically filled -->
                            </div>

                            <div class="form-row">
                                <input type="text" id="noproxy-entry" placeholder="e.g. localhost, 192.168.0.0/16, .local" style="flex: 1;">
                                <button class="btn-add" onclick="addNoproxyEntry()">Add</button>
                            </div>
                            <p class="help-text" style="margin-top: 5px; font-size: 0.9em;">
                                Examples: <code>localhost</code>, <code>192.168.0.0/16</code>, <code>.local</code>, <code>10.0.0.0/8</code>
                            </p>
                        </div>
                    </div>
                </div>
                <div id="upstream-status" style="margin-top: 15px;"></div>
            </div>
        </div>

        <div class="card">
            <h2>Test Proxy Configuration</h2>
            <div class="test-commands">
                <p><strong>Linux/macOS Terminal:</strong></p>
                <code>curl -x http://localhost:8888 http://example.com</code>
                
                <p style="margin-top: 10px;"><strong>Browser (Firefox):</strong></p>
                <p class="help-text">
                    Settings → Network → Connection Settings<br>
                    Manual Proxy Configuration: <code>localhost:8888</code>
                </p>
            </div>
        </div>
    </div>

    <script>
        let refreshInterval = null;
        let domainFilters = []; // Store filtered domains

        // Notification system
        function showNotification(message, type = 'success') {
            // Remove any existing notification
            const existing = document.getElementById('notification-toast');
            if (existing) {
                existing.remove();
            }

            // Create notification element
            const notification = document.createElement('div');
            notification.id = 'notification-toast';
            notification.className = 'notification-toast notification-' + type;
            notification.textContent = message;
            
            // Add to body
            document.body.appendChild(notification);
            
            // Trigger animation
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }, 3000);
        }

        // Load filters from localStorage on startup
        function loadFilters() {
            const saved = localStorage.getItem('trafficDomainFilters');
            if (saved) {
                try {
                    domainFilters = JSON.parse(saved);
                } catch (e) {
                    domainFilters = [];
                }
            }
            updateFilterDisplay();
        }

        // Save filters to localStorage
        function saveFilters() {
            localStorage.setItem('trafficDomainFilters', JSON.stringify(domainFilters));
            updateFilterDisplay();
        }

        // Toggle filter panel visibility
        function toggleFilterPanel() {
            const panel = document.getElementById('filter-panel');
            const icon = document.getElementById('filter-toggle-icon');
            
            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                icon.textContent = '▲';
            } else {
                panel.style.display = 'none';
                icon.textContent = '▼';
            }
        }

        // Add domain to filter list
        function addDomainFilter() {
            const input = document.getElementById('filter-domain-input');
            const domain = input.value.trim().toLowerCase();
            
            if (!domain) {
                alert('Please enter a domain');
                return;
            }
            
            if (domainFilters.includes(domain)) {
                alert('Domain already filtered');
                return;
            }
            
            domainFilters.push(domain);
            saveFilters();
            input.value = '';
            
            // Refresh traffic to apply filter
            refreshTraffic();
        }

        // Remove domain from filter list
        function removeDomainFilter(domain) {
            domainFilters = domainFilters.filter(d => d !== domain);
            saveFilters();
            refreshTraffic();
        }

        // Clear all filters
        function clearAllFilters() {
            if (domainFilters.length === 0) {
                return;
            }
            
            if (!confirm('Clear all domain filters?')) {
                return;
            }
            
            domainFilters = [];
            saveFilters();
            refreshTraffic();
        }

        // Update filter display
        function updateFilterDisplay() {
            const listDiv = document.getElementById('filtered-domains-list');
            const countSpan = document.getElementById('filter-count');
            
            countSpan.textContent = '(' + domainFilters.length + ' filtered)';
            
            if (domainFilters.length === 0) {
                listDiv.innerHTML = '<p class="help-text" style="font-style: italic; margin-top: 10px;">No domains filtered yet</p>';
                return;
            }
            
            let html = '<div style="margin-top: 10px;">';
            domainFilters.forEach(domain => {
                html += '<div class="filter-tag">';
                html += '<span class="filter-domain">' + escapeHtml(domain) + '</span>';
                html += '<button class="filter-remove" onclick="removeDomainFilter(\'' + escapeHtml(domain).replace(/'/g, "\\'") + '\')" title="Remove filter">×</button>';
                html += '</div>';
            });
            html += '</div>';
            
            listDiv.innerHTML = html;
        }

        // Check if a domain should be filtered
        function shouldFilterDomain(domain) {
            const domainLower = domain.toLowerCase();
            return domainFilters.some(filter => {
                // Exact match or contains match
                return domainLower === filter || domainLower.includes(filter);
            });
        }

        // Helper: safely parse JSON response, handles non-JSON errors
        async function safeJsonParse(response) {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('API returned non-JSON:', text.substring(0, 500));
                return { success: false, message: 'Invalid server response (no JSON). Status: ' + response.status };
            }
        }

        async function deleteDomain(domain) {
            if (!confirm('Really delete domain "' + domain + '"?')) {
                return;
            }
            
            // Show hourglass in title
            const titleElement = document.getElementById('blocked-domains-title');
            const originalTitle = titleElement.textContent;
            titleElement.textContent = '⏳ Restarting Tinyproxy...';
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'delete', domain: domain})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    // Show restart notification if tinyproxy was restarted
                    if (result.restart) {
                        showNotification('✓ Domain deleted and Tinyproxy restarted!', 'success');
                    } else {
                        showNotification('✓ Domain deleted. Please restart Tinyproxy manually!', 'warning');
                    }
                    setTimeout(() => location.reload(), 1500);
                } else {
                    titleElement.textContent = originalTitle;
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                titleElement.textContent = originalTitle;
                alert('Error deleting: ' + error.message);
            }
        }

        document.getElementById('add-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const domain = document.getElementById('new-domain').value.trim();
            
            if (!domain) {
                alert('Please enter a domain');
                return;
            }
            
            // Show hourglass in title
            const titleElement = document.getElementById('blocked-domains-title');
            const originalTitle = titleElement.textContent;
            titleElement.textContent = '⏳ Restarting Tinyproxy...';
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'add', domain: domain})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    // Show restart notification if tinyproxy was restarted
                    if (result.restart) {
                        showNotification('✓ Domain added and Tinyproxy restarted!', 'success');
                    } else {
                        showNotification('✓ Domain added. Please restart Tinyproxy manually!', 'warning');
                    }
                    setTimeout(() => location.reload(), 1500);
                } else {
                    titleElement.textContent = originalTitle;
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                titleElement.textContent = originalTitle;
                alert('Error adding: ' + error.message);
            }
        });

        async function refreshTraffic() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'traffic', lines: 100})
                });
                const result = await safeJsonParse(response);
                
                const trafficList = document.getElementById('traffic-list');
                
                if (!result.success) {
                    trafficList.innerHTML = '<p class="error">' + (result.message || 'Error loading traffic data') + '</p>';
                    return;
                }
                
                if (result.traffic.length === 0) {
                    trafficList.innerHTML = '<p class="no-traffic">No traffic recorded yet. Use the proxy to see traffic.</p>';
                    return;
                }
                
                // Apply domain filtering
                const filteredTraffic = result.traffic.filter(item => !shouldFilterDomain(item.domain));
                const hiddenCount = result.traffic.length - filteredTraffic.length;
                
                if (filteredTraffic.length === 0) {
                    trafficList.innerHTML = '<p class="no-traffic">All traffic entries are filtered. ' + 
                        hiddenCount + ' entries hidden by domain filters.</p>';
                    return;
                }
                
                let html = '<div class="traffic-count">📊 <strong>' + filteredTraffic.length + '</strong> Requests shown';
                if (hiddenCount > 0) {
                    html += ' <span class="filtered-count">(' + hiddenCount + ' filtered out)</span>';
                }
                html += '</div>';
                html += '<div class="traffic-items">';
                
                filteredTraffic.forEach(item => {
                    const isBlocked = item.level === 'BLOCKED';
                    const isUnauthorized = item.level === 'UNAUTHORIZED';
                    const methodClass = isBlocked ? 'method-blocked'
                                      : isUnauthorized ? 'method-unauthorized'
                                      : 'method-' + item.method.toLowerCase();
                    const itemClass = isBlocked ? 'traffic-item blocked'
                                    : isUnauthorized ? 'traffic-item unauthorized'
                                    : 'traffic-item';

                    html += '<div class="' + itemClass + '">';
                    html += '<div class="traffic-time">' + escapeHtml(item.timestamp) + '</div>';
                    html += '<div class="traffic-method ' + methodClass + '">' + escapeHtml(item.method) + '</div>';

                    html += '<div class="traffic-url">';
                    if (isUnauthorized) {
                        html += '<div class="traffic-source">⛔ ' + escapeHtml(item.source) + '</div>';
                        html += '<div class="traffic-domain unauthorized-label">IP not in Allow list — connection rejected</div>';
                    } else {
                        if (item.source && item.source !== '') {
                            html += '<div class="traffic-source">📍 ' + escapeHtml(item.source) + '</div>';
                        }
                        html += '<div class="traffic-domain">➜ ' + escapeHtml(item.domain) + '</div>';
                        html += '<div class="traffic-full-url">' + escapeHtml(item.url) + '</div>';
                    }
                    html += '</div>';
                    html += '</div>';
                });
                
                html += '</div>';
                trafficList.innerHTML = html;
                
            } catch (error) {
                document.getElementById('traffic-list').innerHTML = 
                    '<p class="error">Error loading: ' + escapeHtml(error.message) + '</p>';
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Auto-refresh functionality
        document.getElementById('auto-refresh').addEventListener('change', function() {
            if (this.checked) {
                refreshInterval = setInterval(refreshTraffic, 5000);
            } else {
                if (refreshInterval) {
                    clearInterval(refreshInterval);
                    refreshInterval = null;
                }
            }
        });

        // Upstream proxy management
        async function loadUpstream() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_upstream'})
                });
                const result = await safeJsonParse(response);
                
                if (result.success) {
                    document.getElementById('upstream-enabled').checked = result.enabled;
                    document.getElementById('upstream-host').value = result.host || '';
                    document.getElementById('upstream-port').value = result.port || '';
                    
                    if (result.enabled) {
                        document.getElementById('upstream-fields').style.display = 'block';
                        document.getElementById('noproxy-section').style.display = 'block';
                        document.getElementById('upstream-status').innerHTML = 
                            '<p class="success">✓ Upstream Proxy active: ' + result.host + ':' + result.port + '</p>';
                        
                        // Load noproxy entries
                        updateNoproxyList(result.noproxy || []);
                    } else {
                        document.getElementById('upstream-status').innerHTML = 
                            '<p class="help-text">No Upstream Proxy configured</p>';
                    }
                }
            } catch (error) {
                console.error('Error loading Upstream configuration:', error);
            }
        }
        
        function updateNoproxyList(entries) {
            const listDiv = document.getElementById('noproxy-list');
            if (!entries || entries.length === 0) {
                listDiv.innerHTML = '<p class="help-text" style="font-style: italic;">No NoProxy entries configured</p>';
                return;
            }
            
            let html = '';
            entries.forEach(entry => {
                html += '<div class="domain-item" style="margin-bottom: 5px;">';
                html += '<span class="domain">' + escapeHtml(entry) + '</span>';
                html += '<button class="btn-delete" onclick="deleteNoproxyEntry(\'' + escapeHtml(entry).replace(/'/g, "\\'") + '\')">Delete</button>';
                html += '</div>';
            });
            listDiv.innerHTML = html;
        }
        
        async function addNoproxyEntry() {
            const entry = document.getElementById('noproxy-entry').value.trim();
            
            if (!entry) {
                alert('Please enter an entry');
                return;
            }
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'add_noproxy',
                        entry: entry
                    })
                });
                const result = await safeJsonParse(response);
                
                if (result.success) {
                    document.getElementById('noproxy-entry').value = '';
                    document.getElementById('upstream-status').innerHTML = 
                        '<p class="success">✓ ' + result.message + '</p>';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error adding: ' + error.message);
            }
        }
        
        async function deleteNoproxyEntry(entry) {
            if (!confirm('Really delete NoProxy entry "' + entry + '"?')) {
                return;
            }
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'delete_noproxy',
                        entry: entry
                    })
                });
                const result = await safeJsonParse(response);
                
                if (result.success) {
                    document.getElementById('upstream-status').innerHTML = 
                        '<p class="success">✓ NoProxy entry deleted. Please restart Tinyproxy!</p>';
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error deleting: ' + error.message);
            }
        }

        async function saveUpstream() {
            const enabled = document.getElementById('upstream-enabled').checked;
            const host = document.getElementById('upstream-host').value.trim();
            const port = document.getElementById('upstream-port').value.trim();
            
            if (enabled && (!host || !port)) {
                alert('Please enter host and port');
                return;
            }
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'set_upstream',
                        enabled: enabled,
                        host: host,
                        port: port
                    })
                });
                const result = await safeJsonParse(response);
                
                if (result.success) {
                    document.getElementById('upstream-status').innerHTML = 
                        '<p class="success">✓ ' + result.message + '</p>';
                    setTimeout(() => location.reload(), 2000);
                } else {
                    document.getElementById('upstream-status').innerHTML = 
                        '<p class="error">✗ ' + result.message + '</p>';
                }
            } catch (error) {
                alert('Error saving: ' + error.message);
            }
        }

        // Toggle upstream fields visibility
        document.getElementById('upstream-enabled').addEventListener('change', function() {
            const fields = document.getElementById('upstream-fields');
            if (this.checked) {
                fields.style.display = 'block';
            } else {
                fields.style.display = 'none';
                saveUpstream(); // Auto-save when disabling
            }
        });

        // Kill-Switch: Load status and toggle
        async function loadTrafficBlockStatus() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_traffic_block'})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    updateKillswitchUI(result.blocked);
                }
            } catch (error) {
                console.error('Error loading Kill-Switch status:', error);
            }
        }

        function updateKillswitchUI(blocked) {
            const btn = document.getElementById('killswitch-btn');
            const card = document.getElementById('killswitch-card');
            const statusDiv = document.getElementById('killswitch-status');
            
            if (blocked) {
                btn.className = 'btn-killswitch active';
                btn.innerHTML = '<span class="killswitch-icon">▶</span><span class="killswitch-text">Enable Traffic</span>';
                card.classList.add('killswitch-active');
                statusDiv.innerHTML = '<p class="killswitch-warning">⚠️ ALL TRAFFIC IS STOPPED – All proxy requests are being blocked!</p>';
            } else {
                btn.className = 'btn-killswitch';
                btn.innerHTML = '<span class="killswitch-icon">⏹</span><span class="killswitch-text">Stop All Traffic</span>';
                card.classList.remove('killswitch-active');
                statusDiv.innerHTML = '';
            }
        }

        async function toggleTrafficBlock() {
            const btn = document.getElementById('killswitch-btn');
            const isCurrentlyBlocked = btn.classList.contains('active');
            
            const confirmMsg = isCurrentlyBlocked 
                ? 'Enable traffic again?' 
                : 'WARNING: Stop all traffic through the proxy?\n\nAll requests will be blocked!';
            
            if (!confirm(confirmMsg)) return;
            
            btn.disabled = true;
            btn.innerHTML = '<span class="killswitch-icon">⏳</span><span class="killswitch-text">Please wait...</span>';
            
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'set_traffic_block',
                        block: !isCurrentlyBlocked
                    })
                });
                const result = await safeJsonParse(response);
                
                if (result.success) {
                    updateKillswitchUI(result.blocked);
                    // Reload page after short delay to reflect blocked-domains changes
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Error: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            } finally {
                btn.disabled = false;
            }
        }

        // Container Access Control
        async function refreshContainers() {
            const listDiv = document.getElementById('container-list');
            listDiv.innerHTML = '<p class="loading">Loading containers...</p>';

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_containers'})
                });
                const result = await safeJsonParse(response);

                if (!result.success) {
                    listDiv.innerHTML = '<p class="error">' + escapeHtml(result.message || 'Error loading containers') + '</p>';
                    return;
                }

                if (result.containers.length === 0) {
                    listDiv.innerHTML = '<p class="no-domains">No external containers detected on the <code>codersrv_default</code> network.</p>';
                    return;
                }

                const allowAll = result.policy === 'allow';

                let html = '';
                if (allowAll) {
                    html += '<div class="policy-info-banner">🔓 Policy: <strong>Allow new</strong> — all containers can connect. Switch to "Block new" to manage access individually.</div>';
                }

                html += '<table class="container-table">';
                html += '<thead><tr>';
                html += '<th>Container</th>';
                html += '<th>IP Address</th>';
                html += '<th>Status</th>';
                html += '<th>Action</th>';
                html += '</tr></thead><tbody>';

                result.containers.forEach(container => {
                    const effectiveAllowed = container.allowed;
                    const isOnline    = container.online !== false;
                    const rowClass    = effectiveAllowed ? 'container-row-allowed' : 'container-row-blocked';
                    const statusClass = effectiveAllowed ? 'status-allowed' : 'status-blocked';
                    const statusText  = effectiveAllowed ? '✓ Allowed' : '✗ Blocked';
                    const btnClass    = container.allowed ? 'btn-container-block' : 'btn-container-allow';
                    const btnText     = container.allowed ? 'Block' : 'Allow';
                    const safeName    = escapeHtml(container.name).replace(/'/g, "\\'");
                    const offlineBadge = isOnline ? '' : ' <span class="container-offline">(offline)</span>';

                    html += '<tr class="' + rowClass + (isOnline ? '' : ' container-row-offline') + '">';
                    html += '<td class="container-name">' + escapeHtml(container.name) + offlineBadge + '</td>';
                    html += '<td class="container-ip">' + escapeHtml(container.ip) + '</td>';
                    html += '<td><span class="container-status ' + statusClass + '">' + statusText + '</span></td>';
                    html += '<td><button class="' + btnClass + '" onclick="toggleContainer(\'' + safeName + '\', ' + !container.allowed + ')">' + btnText + '</button></td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';
                listDiv.innerHTML = html;
            } catch (error) {
                listDiv.innerHTML = '<p class="error">Error: ' + escapeHtml(error.message) + '</p>';
            }
        }

        async function toggleContainer(name, allow) {
            const action = allow ? 'Allow' : 'Block';
            if (!confirm(action + ' container "' + name + '"?')) return;

            const h2 = document.querySelector('#container-card h2');
            const origTitle = h2.textContent;
            h2.textContent = '⏳ Updating...';

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'set_container_allow', name: name, allow: allow})
                });
                const result = await safeJsonParse(response);

                h2.textContent = origTitle;

                if (result.success) {
                    showNotification('✓ ' + result.message, 'success');
                    refreshContainers();
                } else {
                    showNotification('✗ ' + result.message, 'error');
                }
            } catch (error) {
                h2.textContent = origTitle;
                showNotification('Error: ' + error.message, 'error');
            }
        }

        async function syncAllowRules() {
            if (!confirm('Re-sync container IPs and restart Tinyproxy?')) return;

            const h2 = document.querySelector('#container-card h2');
            const origTitle = h2.textContent;
            h2.textContent = '⏳ Syncing...';

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'sync_allow_rules'})
                });
                const result = await safeJsonParse(response);

                h2.textContent = origTitle;

                if (result.success) {
                    showNotification('✓ ' + result.message, 'success');
                    refreshContainers();
                } else {
                    showNotification('✗ ' + result.message, 'error');
                }
            } catch (error) {
                h2.textContent = origTitle;
                showNotification('Error: ' + error.message, 'error');
            }
        }

        // Client policy
        async function loadClientPolicy() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_config'})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    updatePolicyUI(result.config.new_client_policy);
                }
            } catch (error) {
                console.error('Error loading client policy:', error);
            }
        }

        function updatePolicyUI(policy) {
            const blockBtn = document.getElementById('policy-block-btn');
            const allowBtn = document.getElementById('policy-allow-btn');
            const hint     = document.getElementById('policy-hint');
            const card     = document.getElementById('container-card');

            if (policy === 'allow') {
                blockBtn.classList.remove('active');
                allowBtn.classList.add('active');
                hint.textContent = 'All new containers can use the proxy automatically.';
                card.classList.add('policy-allow-mode');
                card.classList.remove('policy-block-mode');
            } else {
                allowBtn.classList.remove('active');
                blockBtn.classList.add('active');
                hint.textContent = 'New containers are blocked until explicitly allowed.';
                card.classList.remove('policy-allow-mode');
                card.classList.add('policy-block-mode');
            }
        }

        async function setClientPolicy(policy) {
            const blockBtn = document.getElementById('policy-block-btn');
            const allowBtn = document.getElementById('policy-allow-btn');
            blockBtn.disabled = true;
            allowBtn.disabled = true;

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'set_config', key: 'new_client_policy', value: policy})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    updatePolicyUI(policy);
                    showNotification('✓ ' + result.message, 'success');
                    refreshContainers();
                } else {
                    showNotification('✗ ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'error');
            } finally {
                blockBtn.disabled = false;
                allowBtn.disabled = false;
            }
        }

        // Initial load
        loadFilters();
        loadUpstream();
        loadTrafficBlockStatus();
        loadClientPolicy();
        refreshContainers();
        refreshTraffic();
        
        // Start auto-refresh
        refreshInterval = setInterval(refreshTraffic, 5000);
    </script>
</body>
</html>
