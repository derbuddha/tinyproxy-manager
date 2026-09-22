<?php
require_once __DIR__ . '/traffic-parser.php';
require_once __DIR__ . '/auth.php';

// No-op unless KEYCLOAK_ENABLED is set; otherwise redirects to Keycloak.
authRequirePage();

$historyFile = '/app/traffic-history.json';
$history = [];
if (file_exists($historyFile)) {
    $decoded = json_decode((string)file_get_contents($historyFile), true);
    if (is_array($decoded)) $history = $decoded;
}
$storedTotal = count($history);

// Newest first
$history = array_reverse($history);

// Noise filters are display-only - applied before pagination so hidden entries don't
// leave gaps or shrink pages. The stored history itself (and exports) stay complete.
$filters = getNoiseFilters();
$visibleHistory = [];
$hiddenByNoiseFilter = 0;
foreach ($history as $entry) {
    if (isNoiseFiltered($entry, $filters)) {
        $hiddenByNoiseFilter++;
        continue;
    }
    $visibleHistory[] = $entry;
}
$containerList = getKnownContainerLabels();

// Filter to a single workspace/container, if one was selected
$selectedContainer = isset($_GET['container']) ? trim($_GET['container']) : '';
if ($selectedContainer !== '') {
    $visibleHistory = array_values(array_filter($visibleHistory, function ($entry) use ($selectedContainer) {
        return extractContainerLabel($entry['source'] ?? '') === $selectedContainer;
    }));
}

$total = count($visibleHistory);
$perPageOptions = [20, 50, 100, 1000, 'all'];
$perPageParam = isset($_GET['per_page']) ? trim((string)$_GET['per_page']) : '100';
if (!in_array($perPageParam, ['20', '50', '100', '1000', 'all'], true)) {
    $perPageParam = '100';
}
$perPage = $perPageParam === 'all' ? max(1, $total) : (int)$perPageParam;
$totalPages = max(1, (int)ceil($total / $perPage));
$page = isset($_GET['page']) ? max(1, min($totalPages, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;
$pageItems = array_slice($visibleHistory, $offset, $perPage);
$containerQS = $selectedContainer !== '' ? '&container=' . urlencode($selectedContainer) : '';
$perPageQS = '&per_page=' . urlencode($perPageParam);

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tinyproxy Manager - Full Traffic Log</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
    <script src="searchable-select.js?v=<?php echo filemtime(__DIR__ . '/searchable-select.js'); ?>"></script>
    <?php authRenderSessionGuardScript(); ?>
</head>
<body>
    <!-- Top bar: signed-in user (only with Keycloak login enabled) + theme toggle -->
    <div class="topbar">
        <?php authRenderUserBadge(); ?>
        <button class="theme-toggle" onclick="toggleTheme()" title="Toggle Light/Dark Mode">
            <span class="theme-toggle-icon" id="theme-icon">🌙</span>
            <span class="theme-toggle-text" id="theme-text">Dark</span>
        </button>
    </div>

    <div class="container">
        <h1>📜 Full Traffic Log</h1>

        <div class="card">
            <div class="traffic-controls">
                <div style="display: flex; gap: 12px; align-items: center;">
                    <a href="index.php" class="btn-restart">← Back to Dashboard</a>
                    <a href="export.php" class="btn-sync-allow">⬇ Export as JSON</a>
                    <button class="btn-restart" onclick="refreshHistoryPage()">↻ Refresh</button>
                </div>
                <label class="auto-refresh-toggle">
                    <input type="checkbox" id="history-auto-refresh" checked onchange="toggleHistoryAutoRefresh()">
                    <span>Auto-Refresh (10s)</span>
                </label>
            </div>

            <!-- Noise Filter Section (server-side, applies for everyone, shared with the dashboard) -->
            <div class="traffic-filter-section" id="noise-filter-section" style="margin-bottom: 20px;">
                <div class="filter-header filter-header-alt">
                    <h3>🔇 Noise Filter (server-wide)</h3>
                    <button class="btn-toggle-filter" onclick="toggleNoiseFilterPanel()">
                        <span id="noise-filter-toggle-icon">▼</span> <span id="noise-filter-count">(0 filters)</span>
                    </button>
                </div>
                <div id="noise-filter-panel" class="filter-panel" style="display: none;">
                    <p class="help-text" style="margin-bottom: 10px;">
                        Hides matching domains/sources from this page <strong>and</strong> the dashboard's Live Monitor, for every viewer. Matches the request domain or the source IP/name (substring, case-insensitive). The stored history and JSON export still keep every entry.
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
                <form method="get" id="container-filter-form">
                    <label for="container-select">Workspace/container:</label>
                    <select name="container" id="container-select" onchange="this.form.submit()">
                        <option value="">All containers</option>
                        <?php foreach ($containerList as $c): ?>
                            <option value="<?php echo h($c); ?>" <?php echo $c === $selectedContainer ? 'selected' : ''; ?>><?php echo h($c); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label for="per-page-select" style="margin-left: 16px;">Show:</label>
                    <select name="per_page" id="per-page-select" onchange="this.form.submit()">
                        <?php foreach ($perPageOptions as $opt): ?>
                            <option value="<?php echo h($opt); ?>" <?php echo ((string)$opt === $perPageParam) ? 'selected' : ''; ?>>
                                <?php echo $opt === 'all' ? 'ALL' : h($opt); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if ($selectedContainer !== ''): ?>
                        <a href="history.php" class="btn-clear-filters" style="text-decoration: none; display: inline-block;">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <div id="log-content">
            <div class="traffic-count">
                📊 <strong><?php echo $storedTotal; ?></strong> total entries stored (capped at 10,000)
                <?php if ($hiddenByNoiseFilter > 0): ?>
                    <span class="filtered-count">· 🔇 <?php echo $hiddenByNoiseFilter; ?> hidden by noise filter</span>
                <?php endif; ?>
                <?php if ($selectedContainer !== ''): ?>
                    <span class="filtered-count">· filtered to <?php echo h($selectedContainer); ?></span>
                <?php endif; ?>
                — showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $total; ?> visible)
            </div>

            <?php if (empty($pageItems)): ?>
                <p class="no-traffic">No traffic recorded yet.</p>
            <?php else: ?>
            <div class="traffic-items">
                <?php foreach ($pageItems as $item):
                    $isBlocked = ($item['level'] ?? '') === 'BLOCKED';
                    $isUnauthorized = ($item['level'] ?? '') === 'UNAUTHORIZED';
                    $methodClass = $isBlocked ? 'method-blocked' : ($isUnauthorized ? 'method-unauthorized' : 'method-' . strtolower($item['method'] ?? ''));
                    $itemClass = $isBlocked ? 'traffic-item blocked' : ($isUnauthorized ? 'traffic-item unauthorized' : 'traffic-item');
                ?>
                <div class="<?php echo $itemClass; ?>">
                    <div class="traffic-time"><?php echo h($item['timestamp'] ?? ''); ?></div>
                    <div class="traffic-method <?php echo $methodClass; ?>"><?php echo h($item['method'] ?? ''); ?></div>
                    <div class="traffic-url">
                        <?php if ($isUnauthorized): ?>
                            <div class="traffic-source">⛔ <?php echo h($item['source'] ?? ''); ?></div>
                            <div class="traffic-domain unauthorized-label">IP not in Allow list — connection rejected</div>
                        <?php else: ?>
                            <?php if (!empty($item['source'])): ?>
                                <div class="traffic-source">📍 <?php echo h($item['source']); ?></div>
                            <?php endif; ?>
                            <div class="traffic-domain">➜ <?php echo h($item['domain'] ?? ''); ?></div>
                            <div class="traffic-full-url"><?php echo h($item['url'] ?? ''); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a class="btn-restart" href="?page=<?php echo ($page - 1) . $containerQS . $perPageQS; ?>">← Prev</a>
                <?php endif; ?>
                <span class="pagination-info">Page <?php echo $page; ?> / <?php echo $totalPages; ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn-restart" href="?page=<?php echo ($page + 1) . $containerQS . $perPageQS; ?>">Next →</a>
                <?php endif; ?>
            </div>
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

        async function safeJsonParse(response) {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('API returned non-JSON:', text.substring(0, 500));
                return { success: false, message: 'Invalid server response (no JSON). Status: ' + response.status };
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

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
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error: ' + error.message);
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
                    location.reload();
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        // Auto-refresh: re-fetches this same URL (same page/filter/query string) and swaps in
        // just the log content, so scroll position and open panels aren't disturbed like a
        // full page reload would do.
        let historyAutoRefreshInterval = null;

        async function refreshHistoryPage() {
            try {
                const response = await fetch(window.location.href, { cache: 'no-store' });
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');

                const newContent = doc.getElementById('log-content');
                const curContent = document.getElementById('log-content');
                if (newContent && curContent) {
                    curContent.innerHTML = newContent.innerHTML;
                }
            } catch (error) {
                console.error('Auto-refresh failed:', error);
            }
        }

        function toggleHistoryAutoRefresh() {
            const checkbox = document.getElementById('history-auto-refresh');
            if (checkbox.checked) {
                historyAutoRefreshInterval = setInterval(refreshHistoryPage, 10000);
            } else if (historyAutoRefreshInterval) {
                clearInterval(historyAutoRefreshInterval);
                historyAutoRefreshInterval = null;
            }
        }

        if (document.getElementById('history-auto-refresh').checked) {
            historyAutoRefreshInterval = setInterval(refreshHistoryPage, 10000);
        }

        loadNoiseFilters();
        SearchableSelect.enhance('container-select', {placeholder: 'All containers - type to filter'});
    </script>
</body>
</html>
