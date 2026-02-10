<?php
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

function addDomain($domain) {
    global $file;
    $domain = trim($domain);
    
    if (empty($domain)) {
        return ['success' => false, 'message' => 'Domain is empty'];
    }
    
    if (strlen($domain) < 3) {
        return ['success' => false, 'message' => 'Domain too short'];
    }
    
    // Check if domain already exists
    if (file_exists($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) $lines = [];
        foreach ($lines as $line) {
            if (trim($line) === $domain) {
                return ['success' => false, 'message' => 'Domain already in list'];
            }
        }
    }
    
    // Add domain
    $result = file_put_contents($file, "\n" . $domain, FILE_APPEND | LOCK_EX);
    if ($result === false) {
        return ['success' => false, 'message' => 'Error writing file'];
    }
    
    return ['success' => true, 'message' => 'Domain added. Please restart Tinyproxy!'];
}

function deleteDomain($domain) {
    global $file;
    
    if (!file_exists($file)) {
        return ['success' => false, 'message' => 'File not found'];
    }
    
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return ['success' => false, 'message' => 'Error reading file'];
    }
    
    $newLines = [];
    $found = false;
    
    foreach ($lines as $line) {
        if (trim($line) === trim($domain)) {
            $found = true;
            continue;
        }
        $newLines[] = $line;
    }
    
    if (!$found) {
        return ['success' => false, 'message' => 'Domain not found'];
    }
    
    file_put_contents($file, implode("\n", $newLines) . "\n", LOCK_EX);
    
    return ['success' => true, 'message' => 'Domain deleted. Please restart Tinyproxy!'];
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
    
    if (preg_match('/^\s*Upstream\s+http\s+([^:\s]+):(\d+)/m', $content, $matches)) {
        $enabled = true;
        $host = $matches[1];
        $port = $matches[2];
    }
    
    // Extract No (noproxy) entries
    if (preg_match_all('/^\s*No\s+(.+)$/m', $content, $matches)) {
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

function setUpstream($enabled, $host, $port, $noproxy = null) {
    $configFile = '/app/tinyproxy.conf';
    
    if ($enabled) {
        if (empty($host) || empty($port)) {
            return ['success' => false, 'message' => 'Host and port are required'];
        }
        
        if (!is_numeric($port) || $port < 1 || $port > 65535) {
            return ['success' => false, 'message' => 'Invalid port'];
        }
    }
    
    $content = @file_get_contents($configFile);
    if ($content === false) {
        return ['success' => false, 'message' => 'Error reading configuration'];
    }
    
    // Remove existing Upstream and No lines (active or commented)
    $lines = explode("\n", $content);
    $newLines = [];
    $inUpstreamSection = false;
    
    foreach ($lines as $line) {
        // Detect upstream section
        if (preg_match('/# Upstream Proxy Configuration/', $line)) {
            $inUpstreamSection = true;
            $newLines[] = $line;
            continue;
        }
        
        // If we're in upstream section, skip Upstream and No directives
        if ($inUpstreamSection) {
            if (preg_match('/^\s*(#\s*)?(Upstream\s+http\s+\S+:\d+|No\s+.+)/', $line)) {
                continue;
            }
            // Exit upstream section when we hit a blank line followed by non-comment
            if (empty(trim($line)) && !empty($newLines)) {
                $inUpstreamSection = false;
            }
        }
        
        $newLines[] = $line;
    }
    
    // Build upstream section
    $content = implode("\n", $newLines);
    
    $upstreamBlock = '';
    if ($enabled) {
        $upstreamBlock = "Upstream http " . $host . ":" . $port . " \".\"";
        
        // Add noproxy entries if provided
        if ($noproxy !== null && is_array($noproxy)) {
            foreach ($noproxy as $entry) {
                $entry = trim($entry);
                if (!empty($entry)) {
                    $upstreamBlock .= "\nNo " . $entry;
                }
            }
        }
    } else {
        $upstreamBlock = "# Upstream http proxy.example.com:3128 \".\"";
    }
    
    $upstreamBlock .= "\n# No proxy example: No localhost\n# No 192.168.0.0/16";
    
    // Replace the upstream section marker
    $content = preg_replace(
        '/(# Managed by the Tinyproxy GUI)\n(# No proxy example.*\n# No.*)?/s',
        "$1\n" . $upstreamBlock . "\n",
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
    
    return setUpstream(true, $current['host'], $current['port'], $noproxy);
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
    
    return setUpstream(true, $current['host'], $current['port'], array_values($noproxy));
}

function getTraffic($lines = 200) {
    $logFile = '/var/log/tinyproxy/tinyproxy.log';
    $traffic = [];
    
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
    
    // Track source IPs by PID
    $sourceIpByPid = [];
    
    foreach ($logLines as $line) {
        if (empty($line)) continue;
        
        if (preg_match('/(\w+)\s+(\w+\s+\d+\s+\d+:\d+:\d+)\s+\[(\d+)\]:\s+(.+)$/', $line, $matches)) {
            $level = $matches[1];
            $timestamp = $matches[2];
            $pid = $matches[3];
            $message = $matches[4];
            
            // Extract source IP from Connect line: "Connect (file descriptor X): 192.168.0.48 [192.168.0.48]"
            if (preg_match('/Connect \(file descriptor \d+\):\s+([^\s]+)/', $message, $sourceMatches)) {
                $sourceIpByPid[$pid] = $sourceMatches[1];
            }
            
            // Get source IP for this PID
            $sourceIp = $sourceIpByPid[$pid] ?? '';
            
            if (preg_match('/Request.*:\s+(GET|POST|CONNECT|HEAD|PUT|DELETE|OPTIONS|PATCH)\s+(.+)/', $message, $reqMatches)) {
                $method = $reqMatches[1];
                $url = $reqMatches[2];
                
                $parsedUrl = parse_url($url);
                $domain = $parsedUrl['host'] ?? $url;
                
                $traffic[] = [
                    'timestamp' => $timestamp,
                    'method' => $method,
                    'url' => $url,
                    'domain' => $domain,
                    'source' => $sourceIp,
                    'level' => $level
                ];
            }
            else if (strpos($message, 'Proxying refused') !== false || strpos($message, 'filtered') !== false) {
                if (preg_match('/Proxying refused.*"(.+?)"/', $message, $deniedMatches)) {
                    $url = $deniedMatches[1];
                    $parsedUrl = parse_url($url);
                    $domain = $parsedUrl['host'] ?? $url;
                    
                    $traffic[] = [
                        'timestamp' => $timestamp,
                        'method' => 'BLOCKED',
                        'url' => $url,
                        'domain' => $domain,
                        'source' => $sourceIp,
                        'level' => 'BLOCKED'
                    ];
                }
            }
        }
    }
    
    $traffic = array_reverse($traffic);
    
    // Limit to 50 entries
    $traffic = array_slice($traffic, 0, 50);
    
    return [
        'success' => true,
        'traffic' => $traffic,
        'count' => count($traffic)
    ];
}

function getTrafficBlock() {
    global $file;
    
    if (!file_exists($file)) {
        return ['success' => true, 'blocked' => false];
    }
    
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return ['success' => true, 'blocked' => false];
    }
    
    foreach ($lines as $line) {
        if (trim($line) === '# STOP_ALL_TRAFFIC') {
            return ['success' => true, 'blocked' => true];
        }
    }
    
    return ['success' => true, 'blocked' => false];
}

function setTrafficBlock($block) {
    global $file;
    
    if ($block) {
        $lines = file_exists($file) ? file($file, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) $lines = [];
        
        foreach ($lines as $line) {
            if (trim($line) === '# STOP_ALL_TRAFFIC') {
                return ['success' => true, 'blocked' => true, 'message' => 'Traffic is already stopped'];
            }
        }
        
        $lines[] = '# STOP_ALL_TRAFFIC';
        $lines[] = '^.*$';
        $writeResult = @file_put_contents($file, implode("\n", $lines) . "\n", LOCK_EX);
        
        if ($writeResult === false) {
            return [
                'success' => false,
                'blocked' => false,
                'message' => 'Error writing file. No write permissions on ' . $file
            ];
        }
        
        // Try to restart tinyproxy container (may fail if docker socket not available)
        $output = [];
        $exitCode = 1;
        @exec('docker restart tinyproxy 2>&1', $output, $exitCode);
        
        return [
            'success' => true,
            'blocked' => true,
            'message' => 'All traffic has been stopped!',
            'restart' => $exitCode === 0
        ];
    } else {
        if (!file_exists($file)) {
            return ['success' => true, 'blocked' => false, 'message' => 'Traffic was not stopped'];
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) $lines = [];
        $newLines = [];
        $skipNext = false;
        
        foreach ($lines as $line) {
            if (trim($line) === '# STOP_ALL_TRAFFIC') {
                $skipNext = true;
                continue;
            }
            if ($skipNext && trim($line) === '^.*$') {
                $skipNext = false;
                continue;
            }
            $skipNext = false;
            $newLines[] = $line;
        }
        
        $writeResult = @file_put_contents($file, implode("\n", $newLines) . "\n", LOCK_EX);
        
        if ($writeResult === false) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Error writing file. No write permissions on ' . $file
            ];
        }
        
        $output = [];
        $exitCode = 1;
        @exec('docker restart tinyproxy 2>&1', $output, $exitCode);
        
        return [
            'success' => true,
            'blocked' => false,
            'message' => 'Traffic has been enabled!',
            'restart' => $exitCode === 0
        ];
    }
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
        
    case 'stats':
        echo json_encode(getStats());
        break;
        
    case 'traffic':
        $lines = $data['lines'] ?? 100;
        echo json_encode(getTraffic($lines));
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
        
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
}
