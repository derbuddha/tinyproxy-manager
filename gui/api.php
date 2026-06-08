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
        
        // If we're in upstream section, skip Upstream and no upstream directives
        if ($inUpstreamSection) {
            if (preg_match('/^\s*(#\s*)?(Upstream\s+(http\s+)?\S+:\d+|no\s+upstream\s+.+)/i', $line)) {
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
        $upstreamBlock = "Upstream " . $host . ":" . $port;
        
        // Add noproxy entries if provided
        if ($noproxy !== null && is_array($noproxy)) {
            $upstreamBlock .= "\n# no upstream proxy for internal websites and unqualified hosts";
            foreach ($noproxy as $entry) {
                $entry = trim($entry);
                if (!empty($entry)) {
                    $upstreamBlock .= "\nno upstream \"" . $entry . "\"";
                }
            }
        }
    } else {
        $upstreamBlock = "# Upstream proxy.example.com:3128";
    }
    
    $upstreamBlock .= "\n# No proxy example: no upstream \".internal.example.com\"\n# No 192.168.0.0/16";
    
    // Replace the upstream section marker - find and replace everything after "# Managed by the Tinyproxy GUI"
    // until we hit an empty line or end of upstream section
    $content = preg_replace(
        '/(# Managed by the Tinyproxy GUI\n)(?:.*?\n)*?((?=\n\n)|(?=\n[A-Z])|$)/s',
        "$1" . $upstreamBlock . "\n",
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
            else if (preg_match('/Unauthorized connection from "([^"]+)" \[([^\]]+)\]/', $message, $unauthMatches)) {
                $traffic[] = [
                    'timestamp' => $timestamp,
                    'method' => 'DENIED',
                    'url' => '',
                    'domain' => '',
                    'source' => $unauthMatches[1] . ' [' . $unauthMatches[2] . ']',
                    'level' => 'UNAUTHORIZED'
                ];
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

function getNetworkContainers() {
    $output = [];
    @exec('docker network inspect codersrv_default --format \'{{json .Containers}}\' 2>/dev/null', $output);
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
    foreach ($networkContainers as $name => $ip) {
        if ($name === 'tinyproxy' || $name === 'tinyproxy-gui') continue;

        if ($policy === 'allow') {
            $effectiveAllowed = !in_array($name, $blockedContainers);
        } else {
            $effectiveAllowed = in_array($name, $allowedContainers);
        }

        $result[] = [
            'name' => $name,
            'ip'   => $ip,
            'allowed' => $effectiveAllowed
        ];
    }

    usort($result, function($a, $b) {
        if ($a['allowed'] !== $b['allowed']) return $a['allowed'] ? 1 : -1;
        return strcmp($a['name'], $b['name']);
    });

    return ['success' => true, 'containers' => $result, 'policy' => $policy];
}

function getProxyConfig() {
    $configFile = '/app/proxy-config.json';
    $defaults = ['new_client_policy' => 'block'];

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
    $configFile = '/app/proxy-config.json';
    $allowed = ['new_client_policy' => ['allow', 'block']];

    if (!array_key_exists($key, $allowed)) {
        return ['success' => false, 'message' => 'Unknown config key'];
    }

    if (!in_array($value, $allowed[$key])) {
        return ['success' => false, 'message' => 'Invalid value for ' . $key];
    }

    $current = getProxyConfig()['config'];
    $current[$key] = $value;

    if (@file_put_contents($configFile, json_encode($current, JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing config file'];
    }

    return ['success' => true, 'message' => 'Config updated'];
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
            $syncResult = syncAllowRules();
            if ($syncResult['success']) {
                $restartOut = [];
                $exitCode = 1;
                @exec('docker restart tinyproxy 2>&1', $restartOut, $exitCode);
                $result['restart'] = $exitCode === 0;
                $result['message'] .= $exitCode === 0
                    ? ' Tinyproxy restarted!'
                    : ' Please restart Tinyproxy manually!';
            }
        }
        echo json_encode($result);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
}
