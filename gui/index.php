<?php require_once __DIR__ . '/domain-filter.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tinyproxy Manager</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
</head>
<body>
    <!-- Theme Toggle Button -->
    <button class="theme-toggle" onclick="toggleTheme()" title="Toggle Light/Dark Mode">
        <span class="theme-toggle-icon" id="theme-icon">🌙</span>
        <span class="theme-toggle-text" id="theme-text">Dark</span>
    </button>

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
                <div style="display: flex; gap: 12px; align-items: center;">
                    <button class="btn-restart" onclick="refreshTraffic()">↻ Refresh</button>
                    <a href="history.php" class="btn-restart">📜 Full Log</a>
                    <a href="export.php" class="btn-sync-allow">⬇ Export JSON</a>
                </div>
                <label class="auto-refresh-toggle">
                    <input type="checkbox" id="auto-refresh" checked>
                    <span>Auto-Refresh (5s)</span>
                </label>
            </div>

            <!-- Domain Filter Section (this browser only) -->
            <div class="traffic-filter-section">
                <div class="filter-header">
                    <h3>🔍 Local Domain Filter (this browser only)</h3>
                    <button class="btn-toggle-filter" onclick="toggleFilterPanel()">
                        <span id="filter-toggle-icon">▼</span> <span id="filter-count">(0 filtered)</span>
                    </button>
                </div>
                <div id="filter-panel" class="filter-panel" style="display: none;">
                    <p class="help-text" style="margin-bottom: 10px;">
                        Hide specific domains from the traffic monitor. Stored in this browser only (not shared with other viewers, doesn't affect the Full Log page).
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

            <!-- Noise Filter Section (server-side, applies for everyone) -->
            <div class="traffic-filter-section" id="noise-filter-section">
                <div class="filter-header filter-header-alt">
                    <h3>🔇 Noise Filter (server-wide)</h3>
                    <button class="btn-toggle-filter" onclick="toggleNoiseFilterPanel()">
                        <span id="noise-filter-toggle-icon">▼</span> <span id="noise-filter-count">(0 filters)</span>
                    </button>
                </div>
                <div id="noise-filter-panel" class="filter-panel" style="display: none;">
                    <p class="help-text" style="margin-bottom: 10px;">
                        Hides matching domains/sources from this Live Monitor <strong>and</strong> the Full Log page, for every viewer. The stored traffic history and JSON export still keep every entry - this only affects what's displayed.
                    </p>
                    <div class="filter-input-row">
                        <input type="text" id="noise-filter-input" placeholder="e.g. coder.example.com or 172.19.0.1" />
                        <button class="btn-add" onclick="addNoiseFilter()">Add</button>
                    </div>
                    <div id="noise-filter-list" class="filtered-domains-list">
                        <!-- Dynamically filled -->
                    </div>
                </div>
            </div>

            <div class="container-select-row">
                <form onsubmit="return false;">
                    <label for="live-container-select">Workspace/container:</label>
                    <select id="live-container-select" onchange="onContainerFilterChange()">
                        <option value="">All containers</option>
                    </select>
                    <button type="button" class="btn-blocked-filter" id="blocked-only-btn" aria-pressed="false" title="Show only blocked requests" onclick="toggleBlockedOnlyFilter()">BLOCKED</button>
                    <a href="#" class="btn-clear-filters" id="live-container-clear" style="display: none; text-decoration: none;" onclick="clearContainerFilter(); return false;">Clear</a>
                </form>
            </div>

            <div id="traffic-list">
                <p class="loading">Loading traffic data...</p>
            </div>
        </div>

        <div class="card" id="domain-filter-mode-card">
            <h2>🌐 Domain Filter Mode</h2>
            <p class="help-text" style="margin-bottom: 15px;">
                Choose whether Tinyproxy blocks only the domains in the Blocked list, or blocks everything except the domains in the Allowed list. Only one list is enforced at a time.
            </p>
            <div class="policy-section" id="domain-filter-policy-section">
                <div class="policy-row">
                    <span class="policy-label">Mode:</span>
                    <div class="policy-buttons">
                        <button id="domain-filter-block-btn" class="btn-policy btn-policy-block" onclick="setDomainFilterMode('block')">🚫 Block listed domains</button>
                        <button id="domain-filter-allow-btn" class="btn-policy btn-policy-allow" onclick="setDomainFilterMode('allow')">✅ Allow only listed domains</button>
                    </div>
                    <span class="policy-hint" id="domain-filter-hint"></span>
                </div>
            </div>
        </div>

        <div class="card" id="blocked-domains-card">
            <h2 id="blocked-domains-title">Blocked Domains <span class="domain-card-badge" id="blocked-domains-badge"></span></h2>
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
                <div class="help-text" style="margin-top: 10px;">
                    <strong>Regex Patterns:</strong>
                    <ul class="regex-examples">
                        <li><code>^.*example\.com$</code> Blocks all subdomains of example.com</li>
                        <li><code>^.*\.ads\..*$</code> Blocks all domains with "ads"</li>
                        <li><code>^facebook\.com$</code> Blocks only exact facebook.com</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card" id="allowed-domains-card">
            <h2 id="allowed-domains-title">Allowed Domains <span class="domain-card-badge" id="allowed-domains-badge"></span></h2>
            <div id="allowed-list">
                <?php
                $allowedDomainsFile = '/app/allowed-domains.txt';
                if (file_exists($allowedDomainsFile)) {
                    $lines = file($allowedDomainsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $hasContent = false;
                    foreach ($lines as $line) {
                        // An empty domain means the line is blank or a full-line comment
                        $parsed = parseDomainLine($line);
                        if ($parsed['domain'] === '') continue;
                        $hasContent = true;
                        $escapedDomain = htmlspecialchars($parsed['domain'], ENT_QUOTES, 'UTF-8');
                        $escapedComment = htmlspecialchars($parsed['comment'], ENT_QUOTES, 'UTF-8');
                        $commentClass = $parsed['comment'] === '' ? 'domain-comment empty' : 'domain-comment';
                        $commentText = $parsed['comment'] === '' ? 'No comment' : $escapedComment;
                        echo "<div class='domain-item' data-domain=\"$escapedDomain\" data-comment=\"$escapedComment\">";
                        echo "<div class='domain-main'>";
                        echo "<span class='domain'>$escapedDomain</span>";
                        echo "<span class='$commentClass'>$commentText</span>";
                        echo "</div>";
                        echo "<div class='domain-actions'>";
                        echo "<button class='btn-comment' data-action='edit-comment' title='Add or edit a comment'>💬 Comment</button>";
                        echo "<button class='btn-delete' onclick='deleteAllowedDomain(\"" . addslashes($escapedDomain) . "\")'>Delete</button>";
                        echo "</div>";
                        echo "<div class='comment-edit' hidden>";
                        echo "<input type='text' class='comment-input' maxlength='200' placeholder='e.g. needed for git clone'>";
                        echo "<button class='btn-add' data-action='save-comment'>Save</button>";
                        echo "<button class='btn-cancel' data-action='cancel-comment'>Cancel</button>";
                        echo "</div>";
                        echo "</div>";
                    }
                    if (!$hasContent) {
                        echo "<p class='no-domains'>No domains allowed yet</p>";
                    }
                } else {
                    echo "<p class='error'>File not found!</p>";
                }
                ?>
            </div>
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <h3 style="margin-bottom: 12px;">Add Domain</h3>
                <form id="add-allowed-form">
                    <input type="text" id="new-allowed-domain" placeholder="e.g. ^.*\.github\.com$" required>
                    <input type="text" id="new-allowed-comment" maxlength="200" placeholder="Comment (optional), e.g. needed for git clone">
                    <button type="submit" class="btn-add">Add</button>
                </form>
                <p class="help-text" style="margin-top: 10px;">
                    Only enforced when Domain Filter Mode is <strong>Allow only listed domains</strong> - every other domain is blocked.
                    Comments are stored after a <code>#</code> on the same line and are ignored by Tinyproxy, so editing one never restarts the proxy.
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
                            <h3 id="noproxy-title" style="margin-bottom: 10px;">🔓 NoProxy (Direct Connections)</h3>
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
        // Theme Management
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            updateThemeUI(newTheme);
        }
        
        function updateThemeUI(theme) {
            const icon = document.getElementById('theme-icon');
            const text = document.getElementById('theme-text');
            
            if (theme === 'dark') {
                icon.textContent = '🌙';
                text.textContent = 'Dark';
            } else {
                icon.textContent = '☀️';
                text.textContent = 'Light';
            }
        }
        
        // Load saved theme on page load
        function loadTheme() {
            const savedTheme = localStorage.getItem('theme') || 'dark';
            document.documentElement.setAttribute('data-theme', savedTheme);
            updateThemeUI(savedTheme);
        }
        
        // Initialize theme
        loadTheme();

        let refreshInterval = null;
        let domainFilters = []; // Store filtered domains
        let trafficContainerFilter = ''; // Selected workspace/container (server-side filtered)
        let blockedOnlyFilter = false;   // Show only BLOCKED entries in the Live Monitor

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

        // Workspace/container filter (server-side, restricts which entries the Live Monitor
        // fetches - shares the same set of choices as the Full Log page's dropdown)
        async function loadTrafficContainers() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_traffic_containers'})
                });
                const result = await safeJsonParse(response);
                if (!result.success) return;

                const select = document.getElementById('live-container-select');
                const current = select.value;
                select.innerHTML = '<option value="">All containers</option>' +
                    result.containers.map(c => '<option value="' + escapeHtml(c) + '">' + escapeHtml(c) + '</option>').join('');
                if (result.containers.includes(current)) {
                    select.value = current;
                }
            } catch (error) {
                console.error('Error loading containers:', error);
            }
        }

        function onContainerFilterChange() {
            trafficContainerFilter = document.getElementById('live-container-select').value;
            document.getElementById('live-container-clear').style.display = trafficContainerFilter ? 'inline-block' : 'none';
            refreshTraffic();
        }

        function clearContainerFilter() {
            trafficContainerFilter = '';
            document.getElementById('live-container-select').value = '';
            document.getElementById('live-container-clear').style.display = 'none';
            refreshTraffic();
        }

        // Blocked-only filter (client-side, keeps only entries the proxy rejected)
        function toggleBlockedOnlyFilter() {
            blockedOnlyFilter = !blockedOnlyFilter;
            const btn = document.getElementById('blocked-only-btn');
            btn.classList.toggle('active', blockedOnlyFilter);
            btn.setAttribute('aria-pressed', blockedOnlyFilter ? 'true' : 'false');
            btn.title = blockedOnlyFilter ? 'Show all requests' : 'Show only blocked requests';
            refreshTraffic();
        }

        // Noise Filter (server-wide, affects Live Monitor + Full Log page for everyone)
        async function loadNoiseFilters() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_noise_filters'})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    updateNoiseFilterList(result.filters);
                }
            } catch (error) {
                console.error('Error loading noise filters:', error);
            }
        }

        function updateNoiseFilterList(filters) {
            const listDiv = document.getElementById('noise-filter-list');
            const countSpan = document.getElementById('noise-filter-count');

            countSpan.textContent = '(' + filters.length + ' filters)';

            if (filters.length === 0) {
                listDiv.innerHTML = '<p class="help-text" style="font-style: italic; margin-top: 10px;">No noise filters yet</p>';
                return;
            }

            let html = '<div style="margin-top: 10px;">';
            filters.forEach(f => {
                html += '<div class="filter-tag">';
                html += '<span class="filter-domain">' + escapeHtml(f) + '</span>';
                html += '<button class="filter-remove" onclick="removeNoiseFilter(\'' + escapeHtml(f).replace(/'/g, "\\'") + '\')" title="Remove filter">×</button>';
                html += '</div>';
            });
            html += '</div>';

            listDiv.innerHTML = html;
        }

        function toggleNoiseFilterPanel() {
            const panel = document.getElementById('noise-filter-panel');
            const icon = document.getElementById('noise-filter-toggle-icon');

            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                icon.textContent = '▲';
            } else {
                panel.style.display = 'none';
                icon.textContent = '▼';
            }
        }

        async function addNoiseFilter() {
            const input = document.getElementById('noise-filter-input');
            const value = input.value.trim();

            if (!value) {
                alert('Please enter a domain or IP');
                return;
            }

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'add_noise_filter', value: value})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    input.value = '';
                    showNotification('✓ Noise filter added', 'success');
                    loadNoiseFilters();
                    refreshTraffic();
                } else {
                    showNotification('✗ ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'error');
            }
        }

        async function removeNoiseFilter(value) {
            if (!confirm('Remove noise filter "' + value + '"?')) return;

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'delete_noise_filter', value: value})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    showNotification('✓ Noise filter removed', 'success');
                    loadNoiseFilters();
                    refreshTraffic();
                } else {
                    showNotification('✗ ' + result.message, 'error');
                }
            } catch (error) {
                showNotification('Error: ' + error.message, 'error');
            }
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
                    document.getElementById('new-domain').value = '';
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

        async function deleteAllowedDomain(domain) {
            if (!confirm('Really delete domain "' + domain + '"?')) {
                return;
            }

            const titleElement = document.getElementById('allowed-domains-title');
            const originalTitle = titleElement.textContent;
            titleElement.textContent = '⏳ Restarting Tinyproxy...';

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'delete_allowed_domain', domain: domain})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
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

        document.getElementById('add-allowed-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const domain = document.getElementById('new-allowed-domain').value.trim();
            const comment = document.getElementById('new-allowed-comment').value.trim();

            if (!domain) {
                alert('Please enter a domain');
                return;
            }

            const titleElement = document.getElementById('allowed-domains-title');
            const originalTitle = titleElement.textContent;
            titleElement.textContent = '⏳ Restarting Tinyproxy...';

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'add_allowed_domain', domain: domain, comment: comment})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    document.getElementById('new-allowed-domain').value = '';
                    document.getElementById('new-allowed-comment').value = '';
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

        // Comments on allowed domains. Tinyproxy ignores everything after the
        // "#" on a filter line, so saving one needs no restart and no reload.
        function openCommentEditor(item) {
            const editor = item.querySelector('.comment-edit');
            const input = item.querySelector('.comment-input');
            input.value = item.dataset.comment || '';
            editor.hidden = false;
            item.classList.add('editing');
            input.focus();
            input.select();
        }

        function closeCommentEditor(item) {
            item.querySelector('.comment-edit').hidden = true;
            item.classList.remove('editing');
        }

        async function saveAllowedDomainComment(item) {
            const domain = item.dataset.domain;
            const input = item.querySelector('.comment-input');
            const saveBtn = item.querySelector('[data-action="save-comment"]');
            const comment = input.value.trim();

            saveBtn.disabled = true;
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'set_allowed_domain_comment', domain: domain, comment: comment})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    // The server normalizes the comment (whitespace, length)
                    const saved = typeof result.comment === 'string' ? result.comment : comment;
                    const label = item.querySelector('.domain-comment');
                    item.dataset.comment = saved;
                    label.textContent = saved === '' ? 'No comment' : saved;
                    label.classList.toggle('empty', saved === '');
                    closeCommentEditor(item);
                    showNotification(saved === '' ? '✓ Comment removed' : '✓ Comment saved', 'success');
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error saving comment: ' + error.message);
            } finally {
                saveBtn.disabled = false;
            }
        }

        const allowedList = document.getElementById('allowed-list');
        if (allowedList) {
            allowedList.addEventListener('click', (e) => {
                const button = e.target.closest('[data-action]');
                if (!button) return;
                const item = button.closest('.domain-item');
                if (!item) return;

                if (button.dataset.action === 'edit-comment') {
                    openCommentEditor(item);
                } else if (button.dataset.action === 'save-comment') {
                    saveAllowedDomainComment(item);
                } else if (button.dataset.action === 'cancel-comment') {
                    closeCommentEditor(item);
                }
            });

            allowedList.addEventListener('keydown', (e) => {
                if (!e.target.classList.contains('comment-input')) return;
                const item = e.target.closest('.domain-item');

                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveAllowedDomainComment(item);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closeCommentEditor(item);
                }
            });
        }

        // Domain Filter Mode
        async function loadDomainFilterMode() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'get_config'})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    updateDomainFilterModeUI(result.config.domain_filter_mode || 'block');
                }
            } catch (error) {
                console.error('Error loading domain filter mode:', error);
            }
        }

        function updateDomainFilterModeUI(mode) {
            const blockBtn = document.getElementById('domain-filter-block-btn');
            const allowBtn = document.getElementById('domain-filter-allow-btn');
            const hint = document.getElementById('domain-filter-hint');
            const blockedBadge = document.getElementById('blocked-domains-badge');
            const allowedBadge = document.getElementById('allowed-domains-badge');
            const blockedCard = document.getElementById('blocked-domains-card');
            const allowedCard = document.getElementById('allowed-domains-card');

            if (mode === 'allow') {
                allowBtn.classList.add('active');
                blockBtn.classList.remove('active');
                hint.textContent = 'Only domains in the Allowed list are reachable. Everything else is blocked.';
                blockedBadge.textContent = 'INACTIVE';
                blockedBadge.className = 'domain-card-badge inactive';
                allowedBadge.textContent = 'ACTIVE';
                allowedBadge.className = 'domain-card-badge active';
                blockedCard.classList.add('domain-card-inactive');
                allowedCard.classList.remove('domain-card-inactive');
            } else {
                blockBtn.classList.add('active');
                allowBtn.classList.remove('active');
                hint.textContent = 'Domains in the Blocked list are denied. Everything else is reachable.';
                blockedBadge.textContent = 'ACTIVE';
                blockedBadge.className = 'domain-card-badge active';
                allowedBadge.textContent = 'INACTIVE';
                allowedBadge.className = 'domain-card-badge inactive';
                blockedCard.classList.remove('domain-card-inactive');
                allowedCard.classList.add('domain-card-inactive');
            }
        }

        async function setDomainFilterMode(mode) {
            const blockBtn = document.getElementById('domain-filter-block-btn');
            const allowBtn = document.getElementById('domain-filter-allow-btn');
            blockBtn.disabled = true;
            allowBtn.disabled = true;

            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'set_config', key: 'domain_filter_mode', value: mode})
                });
                const result = await safeJsonParse(response);
                if (result.success) {
                    updateDomainFilterModeUI(mode);
                    showNotification('✓ ' + result.message, 'success');
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

        async function refreshTraffic() {
            try {
                const response = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({action: 'traffic', lines: 100, container: trafficContainerFilter})
                });
                const result = await safeJsonParse(response);
                
                const trafficList = document.getElementById('traffic-list');
                
                if (!result.success) {
                    trafficList.innerHTML = '<p class="error">' + (result.message || 'Error loading traffic data') + '</p>';
                    return;
                }
                
                if (result.traffic.length === 0) {
                    trafficList.innerHTML = trafficContainerFilter
                        ? '<p class="no-traffic">No traffic recorded yet for "' + escapeHtml(trafficContainerFilter) + '".</p>'
                        : '<p class="no-traffic">No traffic recorded yet. Use the proxy to see traffic.</p>';
                    return;
                }
                
                // Apply domain filtering
                const domainFiltered = result.traffic.filter(item => !shouldFilterDomain(item.domain));
                const hiddenCount = result.traffic.length - domainFiltered.length;

                // Apply the blocked-only toggle on top of the domain filters
                const filteredTraffic = blockedOnlyFilter
                    ? domainFiltered.filter(item => item.level === 'BLOCKED')
                    : domainFiltered;

                if (filteredTraffic.length === 0) {
                    trafficList.innerHTML = blockedOnlyFilter
                        ? '<p class="no-traffic">No blocked requests in the current traffic.</p>'
                        : '<p class="no-traffic">All traffic entries are filtered. ' +
                          hiddenCount + ' entries hidden by domain filters.</p>';
                    return;
                }
                
                let html = '<div class="traffic-count">📊 <strong>' + filteredTraffic.length + '</strong> ' +
                    (blockedOnlyFilter ? 'Blocked requests shown' : 'Requests shown');
                if (hiddenCount > 0) {
                    html += ' <span class="filtered-count">(' + hiddenCount + ' filtered out)</span>';
                }
                if (result.hidden_by_noise_filter > 0) {
                    html += ' <span class="filtered-count">· 🔇 ' + result.hidden_by_noise_filter + ' hidden by noise filter</span>';
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
                        html += '<div class="traffic-domain">➜ <span class="copyable" title="Click to copy domain">' + escapeHtml(item.domain) + '</span></div>';
                        html += '<div class="traffic-full-url"><span class="copyable" title="Click to copy full URL">' + escapeHtml(item.url) + '</span></div>';
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

        // Click-to-copy for domains / URLs in the live traffic monitor
        async function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                try {
                    await navigator.clipboard.writeText(text);
                    return true;
                } catch (e) {
                    // Fall through to the legacy path below
                }
            }
            // Fallback for plain-HTTP setups where the Clipboard API is unavailable
            const helper = document.createElement('textarea');
            helper.value = text;
            helper.setAttribute('readonly', '');
            helper.style.position = 'fixed';
            helper.style.top = '-1000px';
            helper.style.opacity = '0';
            document.body.appendChild(helper);
            helper.select();
            helper.setSelectionRange(0, helper.value.length);
            let ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (e) {
                ok = false;
            }
            helper.remove();
            return ok;
        }

        document.getElementById('traffic-list').addEventListener('click', async function(event) {
            const target = event.target.closest('.copyable');
            if (!target) return;

            const value = target.textContent.trim();
            if (!value) return;

            if (await copyToClipboard(value)) {
                target.classList.add('copied');
                setTimeout(() => target.classList.remove('copied'), 900);
                showNotification('📋 Copied: ' + value);
            } else {
                showNotification('Could not copy to clipboard', 'error');
            }
        });

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

            const titleElement = document.getElementById('noproxy-title');
            const originalTitle = titleElement.textContent;
            titleElement.textContent = '⏳ Restarting Tinyproxy...';

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
                    if (result.restart) {
                        showNotification('✓ NoProxy entry added and Tinyproxy restarted!', 'success');
                    } else {
                        showNotification('✓ NoProxy entry added. Please restart Tinyproxy manually!', 'warning');
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
        }

        async function deleteNoproxyEntry(entry) {
            if (!confirm('Really delete NoProxy entry "' + entry + '"?')) {
                return;
            }

            const titleElement = document.getElementById('noproxy-title');
            const originalTitle = titleElement.textContent;
            titleElement.textContent = '⏳ Restarting Tinyproxy...';

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
                    if (result.restart) {
                        showNotification('✓ NoProxy entry deleted and Tinyproxy restarted!', 'success');
                    } else {
                        showNotification('✓ NoProxy entry deleted. Please restart Tinyproxy manually!', 'warning');
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

            const confirmMsg = this.checked
                ? 'Enable Upstream Proxy?\n\nAll traffic will be forwarded to a second proxy.\nEnter host and port, then click "Save Upstream Proxy".'
                : 'Disable Upstream Proxy?\n\nTraffic will go directly to the internet again.\nYour NoProxy entries are kept and restored when you enable it again.';

            if (!confirm(confirmMsg)) {
                this.checked = !this.checked; // Revert toggle
                return;
            }

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
                    listDiv.innerHTML = '<p class="no-domains">No external containers detected on the <code>' + escapeHtml(result.network || 'codersrv_default') + '</code> network.</p>';
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
        loadDomainFilterMode();
        loadNoiseFilters();
        loadTrafficContainers();
        refreshContainers();
        refreshTraffic();
        
        // Start auto-refresh
        refreshInterval = setInterval(refreshTraffic, 5000);
    </script>
</body>
</html>
