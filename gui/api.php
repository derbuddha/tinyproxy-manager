<?php
require_once __DIR__ . '/traffic-parser.php';
require_once __DIR__ . '/domain-filter.php';

// Prevent PHP errors/warnings from corrupting JSON output
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');

// Ensure clean JSON output even on fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    $output = ob_get_clean();
    
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Discard any previous output
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Internal server error: ' . $error['message']
        ]);
    } else {
        // Normal output
        echo $output;
    }
});

$file = '/app/blocked-domains.txt';

// Safely parse JSON input
$rawInput = file_get_contents('php://input');
$data = null;
if (!empty($rawInput)) {
    $data = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
        exit;
    }
}

function addDomainToFile($file, $domain, $comment = '') {
    $domain = trim($domain);
    $comment = sanitizeDomainComment($comment);

    if (empty($domain)) {
        return ['success' => false, 'message' => 'Domain is empty'];
    }

    if (strlen($domain) < 3) {
        return ['success' => false, 'message' => 'Domain too short'];
    }

    // Tinyproxy cuts a filter line at the first whitespace or unescaped '#',
    // so neither may appear in the pattern itself.
    if (preg_match('/\s/', $domain)) {
        return ['success' => false, 'message' => 'Domain must not contain spaces'];
    }

    if (parseDomainLine($domain)['domain'] !== $domain) {
        return ['success' => false, 'message' => 'Domain must not contain "#" - use the comment field instead'];
    }

    // Check if domain already exists
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) $lines = [];
        foreach ($lines as $line) {
            if (parseDomainLine($line)['domain'] === $domain) {
                return ['success' => false, 'message' => 'Domain already in list'];
            }
        }
    }

    // Add domain - only add a separating newline if the file doesn't end with
    // one already, otherwise the list slowly fills up with blank lines.
    $separator = "\n";
    if (file_exists($file)) {
        // filesize() is served from PHP's stat cache, which can be stale if the
        // file was rewritten earlier in this same request.
        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size === 0) {
            $separator = '';
        } else {
            $tail = @file_get_contents($file, false, null, max(0, $size - 1), 1);
            if ($tail === "\n") $separator = '';
        }
    }

    $result = file_put_contents($file, $separator . formatDomainLine($domain, $comment), FILE_APPEND | LOCK_EX);
    if ($result === false) {
        return ['success' => false, 'message' => 'Error writing file'];
    }

    // Try to restart tinyproxy container
    $output = [];
    $exitCode = 1;
    @exec('docker restart tinyproxy 2>&1', $output, $exitCode);

    if ($exitCode === 0) {
        return ['success' => true, 'message' => 'Domain added and Tinyproxy restarted successfully!', 'restart' => true];
    } else {
        return ['success' => true, 'message' => 'Domain added. Please restart Tinyproxy manually!', 'restart' => false];
    }
}

function deleteDomainFromFile($file, $domain) {
    if (trim($domain) === '') {
        return ['success' => false, 'message' => 'Domain is empty'];
    }

    if (!file_exists($file)) {
        return ['success' => false, 'message' => 'File not found'];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return ['success' => false, 'message' => 'Error reading file'];
    }

    $newLines = [];
    $found = false;
    $domain = trim($domain);

    foreach ($lines as $line) {
        // Match on the domain only, so an entry with a trailing comment is
        // still deletable.
        if (parseDomainLine($line)['domain'] === $domain) {
            $found = true;
            continue;
        }
        // Skip empty lines that were left after deletion
        if (!empty(trim($line))) {
            $newLines[] = $line;
        }
    }

    if (!$found) {
        return ['success' => false, 'message' => 'Domain not found'];
    }

    // Write file with proper formatting - preserve header comments and add single trailing newline
    $content = '';
    foreach ($newLines as $line) {
        $content .= $line . "\n";
    }
    file_put_contents($file, $content, LOCK_EX);

    // Try to restart tinyproxy container
    $output = [];
    $exitCode = 1;
    @exec('docker restart tinyproxy 2>&1', $output, $exitCode);

    if ($exitCode === 0) {
        return ['success' => true, 'message' => 'Domain deleted and Tinyproxy restarted successfully!', 'restart' => true];
    } else {
        return ['success' => true, 'message' => 'Domain deleted. Please restart Tinyproxy manually!', 'restart' => false];
    }
}

/**
 * Replaces the trailing comment of an existing entry. Comments are invisible to
 * Tinyproxy's filter parser, so this never needs a proxy restart.
 */
function setDomainCommentInFile($file, $domain, $comment) {
    $domain = trim($domain);
    $comment = sanitizeDomainComment($comment);

    if ($domain === '') {
        return ['success' => false, 'message' => 'Domain is empty'];
    }

    if (!file_exists($file)) {
        return ['success' => false, 'message' => 'File not found'];
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return ['success' => false, 'message' => 'Error reading file'];
    }

    $found = false;
    foreach ($lines as $i => $line) {
        if (parseDomainLine($line)['domain'] === $domain) {
            $lines[$i] = formatDomainLine($domain, $comment);
            $found = true;
        }
    }

    if (!$found) {
        return ['success' => false, 'message' => 'Domain not found'];
    }

    if (file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing file'];
    }

    return [
        'success' => true,
        'message' => $comment === '' ? 'Comment removed' : 'Comment saved',
        'comment' => $comment
    ];
}

function addDomain($domain) {
    global $file;
    return addDomainToFile($file, $domain);
}

function deleteDomain($domain) {
    global $file;
    return deleteDomainFromFile($file, $domain);
}

function addAllowedDomain($domain, $comment = '') {
    return addDomainToFile('/app/allowed-domains.txt', $domain, $comment);
}

function deleteAllowedDomain($domain) {
    return deleteDomainFromFile('/app/allowed-domains.txt', $domain);
}

function setAllowedDomainComment($domain, $comment) {
    return setDomainCommentInFile('/app/allowed-domains.txt', $domain, $comment);
}

function getStats() {
    global $file;
    
    $count = 0;
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) $lines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && substr($line, 0, 1) !== '#') {
                $count++;
            }
        }
    }
    
    return [
        'success' => true,
        'blocked_count' => $count,
        'file_exists' => file_exists($file)
    ];
}

function getUpstream() {
    $configFile = '/app/tinyproxy.conf';
    
    if (!file_exists($configFile)) {
        return [
            'success' => true,
            'enabled' => false,
            'host' => '',
            'port' => '',
            'noproxy' => []
        ];
    }
    
    $content = @file_get_contents($configFile);
    if ($content === false) {
        return [
            'success' => true,
            'enabled' => false,
            'host' => '',
            'port' => '',
            'noproxy' => [],
            'message' => 'Error reading configuration'
        ];
    }
    
    $enabled = false;
    $host = '';
    $port = '';
    $noproxy = [];
    
    if (preg_match('/^\s*Upstream\s+([^:\s]+):(\d+)/m', $content, $matches)) {
        $enabled = true;
        $host = $matches[1];
        $port = $matches[2];
    }
    
    // Extract no upstream (noproxy) entries
    if (preg_match_all('/^\s*no\s+upstream\s+"?([^"\n]+)"?$/mi', $content, $matches)) {
        $noproxy = array_map('trim', $matches[1]);
    }
    
    return [
        'success' => true,
        'enabled' => $enabled,
        'host' => $host,
        'port' => $port,
        'noproxy' => $noproxy
    ];
}

// A NoProxy entry is written verbatim into tinyproxy.conf as: no upstream "<entry>"
// Anything outside this charset (quotes, whitespace, newlines) could close the
// string and inject arbitrary directives - e.g. an Allow rule - so it is rejected.
// Covers hostnames, leading-dot suffixes and CIDR: localhost, .local, 192.168.0.0/16
function isValidNoproxyEntry($entry) {
    return (bool) preg_match('/^[A-Za-z0-9._\\-\\/]+$/', trim($entry));
}

// Same reasoning for the upstream host. IPv6 is not supported: getUpstream()
// splits host from port on the first colon.
function isValidUpstreamHost($host) {
    return (bool) preg_match('/^[A-Za-z0-9._-]+$/', trim($host));
}

// Reads the NoProxy list out of a tinyproxy.conf. While the upstream proxy is
// enabled the entries are live "no upstream" lines; while it is disabled they
// are parked as "# SAVED no upstream" lines so a disable/enable round-trip
// does not lose them.
function getNoproxyEntries($content) {
    $entries = [];
    if (preg_match_all('/^\s*no\s+upstream\s+"?([^"\n]+)"?\s*$/mi', $content, $matches)) {
        $entries = array_map('trim', $matches[1]);
    }
    if (empty($entries) && preg_match_all('/^\s*#\s*SAVED\s+no\s+upstream\s+"?([^"\n]+)"?\s*$/mi', $content, $matches)) {
        $entries = array_map('trim', $matches[1]);
    }
    return array_values(array_filter($entries, 'isValidNoproxyEntry'));
}

// One-time migration for configs written before the UPSTREAM markers existed:
// strips the legacy upstream directives and wraps the section in
// UPSTREAM_START/END, matching the DOMAIN_FILTER and CONTAINER_ALLOW blocks.
function migrateUpstreamMarkers($content) {
    $lines = explode("\n", $content);
    $newLines = [];
    $inUpstreamSection = false;

    foreach ($lines as $line) {
        if (preg_match('/# Managed by the Tinyproxy GUI/', $line)) {
            $inUpstreamSection = true;
            $newLines[] = $line;
            $newLines[] = '# UPSTREAM_START';
            $newLines[] = '# UPSTREAM_END';
            continue;
        }

        if ($inUpstreamSection) {
            // Drop legacy directives and their example comments
            if (preg_match('/^\s*(#\s*)?(SAVED\s+)?(Upstream\s+(http\s+)?\S+:\d+|no\s+upstream\s+.+)/i', $line)) {
                continue;
            }
            if (preg_match('/^\s*#\s*(no upstream proxy for internal|No proxy example|No 192\.168)/i', $line)) {
                continue;
            }
            // A real directive ends the section
            if (preg_match('/^[A-Za-z]/', $line)) {
                $inUpstreamSection = false;
            }
        }

        $newLines[] = $line;
    }

    return implode("\n", $newLines);
}

function setUpstream($enabled, $host, $port, $noproxy = null) {
    $configFile = '/app/tinyproxy.conf';
    
    if ($enabled) {
        if (empty($host) || empty($port)) {
            return ['success' => false, 'message' => 'Host and port are required'];
        }
        
        if (!isValidUpstreamHost($host)) {
            return ['success' => false, 'message' => 'Invalid host (allowed: letters, digits, dot, hyphen, underscore)'];
        }
        
        if (!is_numeric($port) || $port < 1 || $port > 65535) {
            return ['success' => false, 'message' => 'Invalid port'];
        }
    }
    
    $content = @file_get_contents($configFile);
    if ($content === false) {
        return ['success' => false, 'message' => 'Error reading configuration'];
    }
    
    // Preserve the existing NoProxy list unless the caller passes one explicitly.
    // Without this, saving a changed host/port would silently drop every entry.
    // An explicitly passed empty array still means "clear the list".
    if ($noproxy === null) {
        $noproxy = getNoproxyEntries($content);
    }
    $noproxy = array_values(array_filter(array_map('trim', $noproxy), 'isValidNoproxyEntry'));
    
    if (strpos($content, '# UPSTREAM_START') === false) {
        $content = migrateUpstreamMarkers($content);
    }
    if (strpos($content, '# UPSTREAM_START') === false) {
        return ['success' => false, 'message' => 'UPSTREAM markers not found in tinyproxy.conf'];
    }
    
    // Build upstream section
    $blockLines = [];
    if ($enabled) {
        $blockLines[] = 'Upstream ' . $host . ':' . $port;
        if (!empty($noproxy)) {
            $blockLines[] = '# no upstream proxy for internal websites and unqualified hosts';
            foreach ($noproxy as $entry) {
                $blockLines[] = 'no upstream "' . $entry . '"';
            }
        }
    } else {
        // Park the entries as comments so enabling the upstream proxy again restores them
        $blockLines[] = '# Upstream proxy.example.com:3128';
        foreach ($noproxy as $entry) {
            $blockLines[] = '# SAVED no upstream "' . $entry . '"';
        }
    }
    $upstreamBlock = implode("\n", $blockLines);
    
    // Replace only what sits between the markers. preg_replace_callback avoids
    // having to escape $ and backslashes coming from host/entry values.
    $content = preg_replace_callback(
        '/(# UPSTREAM_START\n).*?(# UPSTREAM_END)/s',
        function ($m) use ($upstreamBlock) {
            return $m[1] . $upstreamBlock . "\n" . $m[2];
        },
        $content
    );
    
    if (@file_put_contents($configFile, $content, LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing configuration'];
    }
    
    return [
        'success' => true,
        'message' => 'Upstream proxy configured. Please restart Tinyproxy!'
    ];
}

function addNoproxy($entry) {
    $configFile = '/app/tinyproxy.conf';
    $entry = trim($entry);
    
    if (empty($entry)) {
        return ['success' => false, 'message' => 'Entry is empty'];
    }
    
    if (!isValidNoproxyEntry($entry)) {
        return ['success' => false, 'message' => 'Invalid entry (allowed: letters, digits, dot, hyphen, underscore, slash - e.g. .local or 192.168.0.0/16)'];
    }
    
    // Get current upstream configuration
    $current = getUpstream();
    if (!$current['enabled']) {
        return ['success' => false, 'message' => 'Upstream Proxy must be enabled to use NoProxy'];
    }
    
    // Check if entry already exists
    if (in_array($entry, $current['noproxy'])) {
        return ['success' => false, 'message' => 'Entry already exists'];
    }
    
    // Add new entry
    $noproxy = $current['noproxy'];
    $noproxy[] = $entry;
    
    $result = setUpstream(true, $current['host'], $current['port'], $noproxy);
    
    // Try to restart tinyproxy container
    if ($result['success']) {
        $output = [];
        $exitCode = 1;
        @exec('docker restart tinyproxy 2>&1', $output, $exitCode);
        
        if ($exitCode === 0) {
            $result['message'] = 'NoProxy entry added and Tinyproxy restarted successfully!';
            $result['restart'] = true;
        } else {
            $result['message'] = 'NoProxy entry added. Please restart Tinyproxy manually!';
            $result['restart'] = false;
        }
    }
    
    return $result;
}

function deleteNoproxy($entry) {
    $configFile = '/app/tinyproxy.conf';
    
    // Get current upstream configuration
    $current = getUpstream();
    if (!$current['enabled']) {
        return ['success' => false, 'message' => 'Upstream Proxy is not enabled'];
    }
    
    // Remove entry
    $noproxy = array_filter($current['noproxy'], function($item) use ($entry) {
        return trim($item) !== trim($entry);
    });
    
    if (count($noproxy) === count($current['noproxy'])) {
        return ['success' => false, 'message' => 'Entry not found'];
    }
    
    $result = setUpstream(true, $current['host'], $current['port'], array_values($noproxy));
    
    // Try to restart tinyproxy container
    if ($result['success']) {
        $output = [];
        $exitCode = 1;
        @exec('docker restart tinyproxy 2>&1', $output, $exitCode);
        
        if ($exitCode === 0) {
            $result['message'] = 'NoProxy entry deleted and Tinyproxy restarted successfully!';
            $result['restart'] = true;
        } else {
            $result['message'] = 'NoProxy entry deleted. Please restart Tinyproxy manually!';
            $result['restart'] = false;
        }
    }
    
    return $result;
}

function getTraffic($lines = 200, $container = '') {
    $logFile = '/var/log/tinyproxy/tinyproxy.log';

    if (!file_exists($logFile)) {
        return [
            'success' => true,
            'message' => 'Log file not found',
            'traffic' => [],
            'count' => 0
        ];
    }

    // Read last N lines of log file
    $logLines = [];
    $command = "tail -n " . intval($lines) . " " . escapeshellarg($logFile) . " 2>/dev/null";
    @exec($command, $logLines);

    $allTraffic = parseTinyproxyLogLines($logLines);
    $allTraffic = array_reverse($allTraffic);

    // Filter noise (and, if requested, restrict to one workspace/container) before capping
    // to 50, so genuinely useful entries aren't crowded out by ones that just get hidden anyway.
    $filters = getNoiseFilters();
    $container = trim((string)$container);
    $traffic = [];
    $hiddenByNoiseFilter = 0;
    foreach ($allTraffic as $entry) {
        if (isNoiseFiltered($entry, $filters)) {
            $hiddenByNoiseFilter++;
            continue;
        }
        if ($container !== '' && extractContainerLabel($entry['source'] ?? '') !== $container) {
            continue;
        }
        if (count($traffic) < 50) {
            $traffic[] = $entry;
        }
    }

    // Also archive newly-seen entries into the capped history store. The background
    // logger does this too, so this is a cheap redundant trigger for whenever the GUI is open.
    ingestTrafficHistory();

    return [
        'success' => true,
        'traffic' => $traffic,
        'count' => count($traffic),
        'hidden_by_noise_filter' => $hiddenByNoiseFilter
    ];
}

function getNetworkContainers() {
    $output = [];
    $network = escapeshellarg(getMonitoredNetwork());
    @exec("docker network inspect $network --format '{{json .Containers}}' 2>/dev/null", $output);
    $jsonStr = implode('', $output);
    $map = [];

    if (!empty($jsonStr)) {
        $raw = json_decode($jsonStr, true);
        if (is_array($raw)) {
            foreach ($raw as $id => $info) {
                $name = $info['Name'] ?? '';
                $ip = preg_replace('/\/\d+$/', '', $info['IPv4Address'] ?? '');
                if (!empty($name) && !empty($ip)) {
                    $map[$name] = $ip;
                }
            }
        }
    }
    return $map;
}

function getAllowedContainerNames() {
    $allowedFile = '/app/allowed-containers.txt';
    $allowed = [];
    if (file_exists($allowedFile)) {
        $lines = file($allowedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && $line[0] !== '#') {
                $allowed[] = $line;
            }
        }
    }
    return $allowed;
}

function getBlockedContainerNames() {
    $blockedFile = '/app/blocked-containers.txt';
    $blocked = [];
    if (file_exists($blockedFile)) {
        $lines = file($blockedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line) && $line[0] !== '#') {
                $blocked[] = $line;
            }
        }
    }
    return $blocked;
}

function getCoderContainers() {
    $networkContainers = getNetworkContainers();
    $allowedContainers = getAllowedContainerNames();
    $blockedContainers = getBlockedContainerNames();
    $policy = getProxyConfig()['config']['new_client_policy'] ?? 'block';

    $result = [];
    $seenNames = [];

    foreach ($networkContainers as $name => $ip) {
        if ($name === 'tinyproxy' || $name === 'tinyproxy-gui') continue;

        if ($policy === 'allow') {
            $effectiveAllowed = !in_array($name, $blockedContainers);
        } else {
            $effectiveAllowed = in_array($name, $allowedContainers);
        }

        $result[] = [
            'name'    => $name,
            'ip'      => $ip,
            'allowed' => $effectiveAllowed,
            'online'  => true
        ];
        $seenNames[] = $name;
    }

    // Always show explicitly blocked containers even when offline
    foreach ($blockedContainers as $name) {
        if (in_array($name, $seenNames)) continue;
        $result[] = [
            'name'    => $name,
            'ip'      => '—',
            'allowed' => false,
            'online'  => false
        ];
    }

    usort($result, function($a, $b) {
        if ($a['allowed'] !== $b['allowed']) return $a['allowed'] ? 1 : -1;
        return strcmp($a['name'], $b['name']);
    });

    return ['success' => true, 'containers' => $result, 'policy' => $policy, 'network' => getMonitoredNetwork()];
}

function getProxyConfig() {
    $configFile = '/app/proxy-config.json';
    $defaults = ['new_client_policy' => 'block', 'domain_filter_mode' => 'block', 'traffic_blocked' => false];

    if (!file_exists($configFile)) {
        return ['success' => true, 'config' => $defaults];
    }

    $content = @file_get_contents($configFile);
    if ($content === false) {
        return ['success' => true, 'config' => $defaults];
    }

    $config = json_decode($content, true);
    if (!is_array($config)) {
        return ['success' => true, 'config' => $defaults];
    }

    return ['success' => true, 'config' => array_merge($defaults, $config)];
}

function setProxyConfig($key, $value) {
    $allowed = [
        'new_client_policy' => ['allow', 'block'],
        'domain_filter_mode' => ['allow', 'block'],
    ];

    if (!array_key_exists($key, $allowed)) {
        return ['success' => false, 'message' => 'Unknown config key'];
    }

    if (!in_array($value, $allowed[$key])) {
        return ['success' => false, 'message' => 'Invalid value for ' . $key];
    }

    return setInternalConfigValue($key, $value);
}

// Writes proxy-config.json directly, bypassing the user-facing key/value allowlist above.
// Used for internal state (e.g. traffic_blocked) that isn't set via the generic set_config action.
function setInternalConfigValue($key, $value) {
    $configFile = '/app/proxy-config.json';
    $current = getProxyConfig()['config'];
    $current[$key] = $value;

    if (@file_put_contents($configFile, json_encode($current, JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing config file'];
    }

    return ['success' => true, 'message' => 'Config updated'];
}

function syncDomainFilterMode() {
    $tinyproxyConf = '/app/tinyproxy.conf';
    $mode = getProxyConfig()['config']['domain_filter_mode'] ?? 'block';

    if ($mode === 'allow') {
        $filterFile = '/etc/tinyproxy/allowed-domains.txt';
        $defaultDeny = 'Yes';
    } else {
        $filterFile = '/etc/tinyproxy/blocked-domains.txt';
        $defaultDeny = 'No';
    }

    $newBlock = 'Filter "' . $filterFile . '"' . "\n" . 'FilterDefaultDeny ' . $defaultDeny;

    $content = @file_get_contents($tinyproxyConf);
    if ($content === false) {
        return ['success' => false, 'message' => 'Error reading tinyproxy.conf'];
    }

    if (strpos($content, '# DOMAIN_FILTER_START') === false) {
        return ['success' => false, 'message' => 'DOMAIN_FILTER markers not found in tinyproxy.conf'];
    }

    $content = preg_replace(
        '/(# DOMAIN_FILTER_START\n).*?(\n# DOMAIN_FILTER_END)/s',
        '$1' . $newBlock . '$2',
        $content
    );

    if (@file_put_contents($tinyproxyConf, $content, LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing tinyproxy.conf'];
    }

    return ['success' => true, 'message' => 'Domain filter mode synchronized'];
}

function syncAllowRules($allowedNames = null) {
    $configFile = '/app/tinyproxy.conf';

    $proxyConfig = getProxyConfig()['config'];
    $newClientPolicy = $proxyConfig['new_client_policy'] ?? 'block';

    if ($allowedNames === null) {
        $allowedNames = getAllowedContainerNames();
    }

    if ($newClientPolicy === 'allow') {
        $networkContainers = getNetworkContainers();
        $blockedNames = getBlockedContainerNames();
        $allowLines = ['Allow 127.0.0.1'];
        foreach ($blockedNames as $name) {
            if (isset($networkContainers[$name])) {
                $ip = $networkContainers[$name];
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $allowLines[] = "Deny $ip";
                }
            }
        }
        $allowLines[] = 'Allow 0.0.0.0/0';
    } else {
        $networkContainers = getNetworkContainers();
        $allowLines = ['Allow 127.0.0.1'];
        foreach ($allowedNames as $name) {
            if (isset($networkContainers[$name])) {
                $ip = $networkContainers[$name];
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $allowLines[] = "Allow $ip";
                }
            }
        }
    }

    $newBlock = implode("\n", $allowLines);

    $content = @file_get_contents($configFile);
    if ($content === false) {
        return ['success' => false, 'message' => 'Error reading tinyproxy.conf'];
    }

    if (strpos($content, '# CONTAINER_ALLOW_START') !== false) {
        $content = preg_replace(
            '/(# CONTAINER_ALLOW_START\n).*?(# CONTAINER_ALLOW_END)/s',
            '$1' . $newBlock . "\n" . '$2',
            $content
        );
    } else {
        $content .= "\n# CONTAINER_ALLOW_START\n" . $newBlock . "\n# CONTAINER_ALLOW_END\n";
    }

    if (@file_put_contents($configFile, $content, LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing tinyproxy.conf'];
    }

    return ['success' => true, 'message' => 'Allow rules synchronized'];
}

function setContainerAllow($containerName, $allow) {
    $allowedFile = '/app/allowed-containers.txt';
    $blockedFile = '/app/blocked-containers.txt';
    $containerName = trim($containerName);

    if (empty($containerName)) {
        return ['success' => false, 'message' => 'Container name is empty'];
    }

    $allowed = getAllowedContainerNames();
    $blocked = getBlockedContainerNames();

    if ($allow) {
        if (!in_array($containerName, $allowed)) {
            $allowed[] = $containerName;
        }
        $blocked = array_values(array_filter($blocked, fn($n) => $n !== $containerName));
    } else {
        $allowed = array_values(array_filter($allowed, fn($n) => $n !== $containerName));
        if (!in_array($containerName, $blocked)) {
            $blocked[] = $containerName;
        }
    }

    $content = "# Allowed containers for tinyproxy access\n# Managed by the Tinyproxy GUI\n";
    foreach ($allowed as $name) {
        $content .= $name . "\n";
    }

    if (@file_put_contents($allowedFile, $content, LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing allowed containers file'];
    }

    $bcontent = "# Blocked containers (explicit denies in Allow-new mode)\n# Managed by the Tinyproxy GUI\n";
    foreach ($blocked as $name) {
        $bcontent .= $name . "\n";
    }

    if (@file_put_contents($blockedFile, $bcontent, LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing blocked containers file'];
    }

    $updateResult = syncAllowRules($allowed);
    if (!$updateResult['success']) return $updateResult;

    $restartOutput = [];
    $exitCode = 1;
    @exec('docker restart tinyproxy 2>&1', $restartOutput, $exitCode);

    $action = $allow ? 'allowed' : 'blocked';
    return [
        'success' => true,
        'message' => "Container \"$containerName\" $action and Tinyproxy restarted!",
        'restart' => $exitCode === 0
    ];
}

function getTrafficBlock() {
    $blocked = getProxyConfig()['config']['traffic_blocked'] ?? false;
    return ['success' => true, 'blocked' => (bool)$blocked];
}

// The kill-switch overrides the CONTAINER_ALLOW block directly (Deny 0.0.0.0/0), so it takes
// effect regardless of container access policy or domain filter mode - both of which only ever
// look at connections that already passed the Allow/Deny check.
function setTrafficBlock($block) {
    $tinyproxyConf = '/app/tinyproxy.conf';

    $content = @file_get_contents($tinyproxyConf);
    if ($content === false) {
        return ['success' => false, 'blocked' => !$block, 'message' => 'Error reading tinyproxy.conf'];
    }

    if (strpos($content, '# CONTAINER_ALLOW_START') === false) {
        return ['success' => false, 'blocked' => !$block, 'message' => 'CONTAINER_ALLOW markers not found in tinyproxy.conf'];
    }

    if ($block) {
        $content = preg_replace(
            '/(# CONTAINER_ALLOW_START\n).*?(\n# CONTAINER_ALLOW_END)/s',
            '$1' . 'Deny 0.0.0.0/0' . '$2',
            $content
        );
        if (@file_put_contents($tinyproxyConf, $content, LOCK_EX) === false) {
            return ['success' => false, 'blocked' => false, 'message' => 'Error writing tinyproxy.conf'];
        }
    } else {
        // Rebuild the normal Allow/Deny rules from the current container access policy
        $syncResult = syncAllowRules();
        if (!$syncResult['success']) {
            return ['success' => false, 'blocked' => true, 'message' => $syncResult['message']];
        }
    }

    setInternalConfigValue('traffic_blocked', $block);

    $output = [];
    $exitCode = 1;
    @exec('docker restart tinyproxy 2>&1', $output, $exitCode);

    return [
        'success' => true,
        'blocked' => $block,
        'message' => $block ? 'All traffic has been stopped!' : 'Traffic has been enabled!',
        'restart' => $exitCode === 0
    ];
}

// === Main Logic ===

if (!$data || !is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$action = $data['action'] ?? '';

switch ($action) {
    case 'add':
        $domain = $data['domain'] ?? '';
        echo json_encode(addDomain($domain));
        break;
        
    case 'delete':
        $domain = $data['domain'] ?? '';
        echo json_encode(deleteDomain($domain));
        break;

    case 'add_allowed_domain':
        $domain = $data['domain'] ?? '';
        $comment = $data['comment'] ?? '';
        echo json_encode(addAllowedDomain($domain, $comment));
        break;

    case 'delete_allowed_domain':
        $domain = $data['domain'] ?? '';
        echo json_encode(deleteAllowedDomain($domain));
        break;

    case 'set_allowed_domain_comment':
        $domain = $data['domain'] ?? '';
        $comment = $data['comment'] ?? '';
        echo json_encode(setAllowedDomainComment($domain, $comment));
        break;

    case 'get_noise_filters':
        echo json_encode(['success' => true, 'filters' => getNoiseFilters()]);
        break;

    case 'add_noise_filter':
        $value = $data['value'] ?? '';
        echo json_encode(addNoiseFilter($value));
        break;

    case 'delete_noise_filter':
        $value = $data['value'] ?? '';
        echo json_encode(deleteNoiseFilter($value));
        break;
        
    case 'stats':
        echo json_encode(getStats());
        break;
        
    case 'traffic':
        $lines = $data['lines'] ?? 100;
        $container = $data['container'] ?? '';
        echo json_encode(getTraffic($lines, $container));
        break;

    case 'get_traffic_containers':
        echo json_encode(['success' => true, 'containers' => getKnownContainerLabels()]);
        break;

    case 'get_upstream':
        echo json_encode(getUpstream());
        break;
        
    case 'set_upstream':
        $enabled = $data['enabled'] ?? false;
        $host = $data['host'] ?? '';
        $port = $data['port'] ?? '';
        echo json_encode(setUpstream($enabled, $host, $port));
        break;
        
    case 'get_traffic_block':
        echo json_encode(getTrafficBlock());
        break;
        
    case 'set_traffic_block':
        $block = $data['block'] ?? false;
        echo json_encode(setTrafficBlock($block));
        break;
        
    case 'add_noproxy':
        $entry = $data['entry'] ?? '';
        echo json_encode(addNoproxy($entry));
        break;

    case 'delete_noproxy':
        $entry = $data['entry'] ?? '';
        echo json_encode(deleteNoproxy($entry));
        break;

    case 'get_containers':
        echo json_encode(getCoderContainers());
        break;

    case 'set_container_allow':
        $name  = $data['name']  ?? '';
        $allow = $data['allow'] ?? false;
        echo json_encode(setContainerAllow($name, (bool)$allow));
        break;

    case 'sync_allow_rules':
        $result = syncAllowRules();
        if ($result['success']) {
            $restartOut = [];
            $exitCode = 1;
            @exec('docker restart tinyproxy 2>&1', $restartOut, $exitCode);
            $result['restart'] = $exitCode === 0;
            $result['message'] = $exitCode === 0
                ? 'Allow rules synchronized and Tinyproxy restarted!'
                : 'Allow rules synchronized. Please restart Tinyproxy manually!';
        }
        echo json_encode($result);
        break;

    case 'get_config':
        echo json_encode(getProxyConfig());
        break;

    case 'set_config':
        $key   = $data['key']   ?? '';
        $value = $data['value'] ?? '';
        $result = setProxyConfig($key, $value);
        if ($result['success']) {
            $syncResult = $key === 'domain_filter_mode' ? syncDomainFilterMode() : syncAllowRules();
            if ($syncResult['success']) {
                $restartOut = [];
                $exitCode = 1;
                @exec('docker restart tinyproxy 2>&1', $restartOut, $exitCode);
                $result['restart'] = $exitCode === 0;
                $result['message'] .= $exitCode === 0
                    ? ' Tinyproxy restarted!'
                    : ' Please restart Tinyproxy manually!';
            } else {
                $result['message'] .= ' Warning: ' . $syncResult['message'];
            }
        }
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
}
