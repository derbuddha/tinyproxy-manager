<?php
// Shared tinyproxy.log parsing logic, used by both the live "traffic" API action
// (api.php) and the background history logger (traffic-logger.php).

// Docker network shared by tinyproxy and the containers it proxies for - set via
// NETWORK_NAME in docker-compose.yml so it stays in sync with the `networks:`
// section there instead of being duplicated as a literal string.
function getMonitoredNetwork() {
    return getenv('NETWORK_NAME') ?: 'codersrv_default';
}

function parseTinyproxyLogLines(array $logLines) {
    $traffic = [];
    $sourceIpByPid = [];

    foreach ($logLines as $line) {
        if (empty($line)) continue;

        if (!preg_match('/(\w+)\s+(\w+\s+\d+\s+\d+:\d+:\d+)\s+\[(\d+)\]:\s+(.+)$/', $line, $matches)) {
            continue;
        }

        $level = $matches[1];
        $timestamp = $matches[2];
        $pid = $matches[3];
        $message = $matches[4];

        // Extract source IP from Connect line: "Connect (file descriptor X): 192.168.0.48 [192.168.0.48]"
        if (preg_match('/Connect \(file descriptor \d+\):\s+([^\s]+)/', $message, $sourceMatches)) {
            $sourceIpByPid[$pid] = $sourceMatches[1];
        }

        $sourceIp = $sourceIpByPid[$pid] ?? '';
        $entry = null;

        if (preg_match('/Request.*:\s+(GET|POST|CONNECT|HEAD|PUT|DELETE|OPTIONS|PATCH)\s+(.+)/', $message, $reqMatches)) {
            $method = $reqMatches[1];
            $url = $reqMatches[2];
            $domain = parse_url($url)['host'] ?? $url;
            $entry = [
                'timestamp' => $timestamp, 'method' => $method, 'url' => $url,
                'domain' => $domain, 'source' => $sourceIp, 'level' => $level
            ];
        } elseif (strpos($message, 'Proxying refused') !== false || strpos($message, 'filtered') !== false) {
            if (preg_match('/Proxying refused.*"(.+?)"/', $message, $deniedMatches)) {
                $url = $deniedMatches[1];
                $domain = parse_url($url)['host'] ?? $url;
                $entry = [
                    'timestamp' => $timestamp, 'method' => 'BLOCKED', 'url' => $url,
                    'domain' => $domain, 'source' => $sourceIp, 'level' => 'BLOCKED'
                ];
            }
        } elseif (preg_match('/Unauthorized connection from "([^"]+)" \[([^\]]+)\]/', $message, $unauthMatches)) {
            $entry = [
                'timestamp' => $timestamp, 'method' => 'DENIED', 'url' => '',
                'domain' => '', 'source' => $unauthMatches[1] . ' [' . $unauthMatches[2] . ']', 'level' => 'UNAUTHORIZED'
            ];
        }

        if ($entry !== null) {
            // Stable-enough identity for a log line, used to find where a previous ingestion
            // run left off so the same entry isn't archived twice.
            $entry['id'] = $pid . '_' . $timestamp . '_' . $level . '_' . substr(md5($entry['domain'] . $entry['url'] . $entry['source']), 0, 8);
            $traffic[] = $entry;
        }
    }

    return $traffic; // chronological order (oldest first), same order as the log file
}

// Noise filters are a display-only suppression: matching entries are hidden from the Live
// Monitor and the Full Log page, but traffic-history.json still keeps every entry untouched,
// so the stored count and exports remain complete.
function getNoiseFilters() {
    $file = '/app/traffic-noise-filters.txt';
    $filters = [];
    if (file_exists($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') $filters[] = $line;
        }
    }
    return $filters;
}

function isNoiseFiltered(array $entry, array $filters) {
    if (empty($filters)) return false;
    $domain = strtolower($entry['domain'] ?? '');
    $source = strtolower($entry['source'] ?? '');
    foreach ($filters as $f) {
        $f = strtolower(trim($f));
        if ($f === '') continue;
        if (($domain !== '' && strpos($domain, $f) !== false) || ($source !== '' && strpos($source, $f) !== false)) {
            return true;
        }
    }
    return false;
}

function addNoiseFilter($value) {
    $file = '/app/traffic-noise-filters.txt';
    $value = trim($value);
    if ($value === '') {
        return ['success' => false, 'message' => 'Value is empty'];
    }
    if (in_array($value, getNoiseFilters(), true)) {
        return ['success' => false, 'message' => 'Filter already exists'];
    }
    if (@file_put_contents($file, "\n" . $value, FILE_APPEND | LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Error writing file'];
    }
    return ['success' => true, 'message' => 'Noise filter added'];
}

function deleteNoiseFilter($value) {
    $file = '/app/traffic-noise-filters.txt';
    if (!file_exists($file)) {
        return ['success' => false, 'message' => 'File not found'];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $newLines = [];
    $found = false;
    foreach ($lines as $line) {
        if (trim($line) === trim($value)) {
            $found = true;
            continue;
        }
        if (trim($line) !== '') $newLines[] = $line;
    }
    if (!$found) {
        return ['success' => false, 'message' => 'Filter not found'];
    }
    @file_put_contents($file, implode("\n", $newLines) . "\n", LOCK_EX);
    return ['success' => true, 'message' => 'Noise filter removed'];
}

// Pulls a plain container name (or bare IP, if no name was resolved) out of a traffic
// entry's "source" field. UNAUTHORIZED entries are formatted as "name.network [ip]";
// other entries only ever contain the bare "name.network" or "ip" (no brackets).
function extractContainerLabel($source) {
    $source = trim((string)$source);
    if ($source === '') return '';

    if (preg_match('/^(\S+)\s*\[/', $source, $m)) {
        $source = $m[1];
    }

    if (filter_var($source, FILTER_VALIDATE_IP)) {
        return $source;
    }

    // Strip Tinyproxy's reverse-DNS "container.network" suffix, without mangling a genuine
    // dotted hostname (e.g. a LAN host like "pi.hole").
    return preg_replace('/\.' . preg_quote(getMonitoredNetwork(), '/') . '$/i', '', $source);
}

// Archives newly-seen traffic entries into /app/traffic-history.json, capped at $maxHistory
// entries (oldest dropped first). Safe to call repeatedly/concurrently (web polling and the
// background logger both call this) - a file lock guards the read-merge-write section.
function ingestTrafficHistory($tailLines = 3000, $maxHistory = 10000) {
    $logFile = '/var/log/tinyproxy/tinyproxy.log';
    $historyFile = '/app/traffic-history.json';
    $lockFile = '/app/traffic-history.lock';

    if (!file_exists($logFile)) {
        return ['success' => true, 'message' => 'Log file not found', 'added' => 0];
    }

    $lockHandle = @fopen($lockFile, 'c');
    if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
        return ['success' => false, 'message' => 'Could not acquire history lock', 'added' => 0];
    }

    try {
        $logLines = [];
        $command = "tail -n " . intval($tailLines) . " " . escapeshellarg($logFile) . " 2>/dev/null";
        @exec($command, $logLines);

        $parsed = parseTinyproxyLogLines($logLines);

        $history = [];
        if (file_exists($historyFile)) {
            $decoded = json_decode((string)@file_get_contents($historyFile), true);
            if (is_array($decoded)) $history = $decoded;
        }

        $lastId = !empty($history) ? end($history)['id'] : null;

        if ($lastId === null) {
            $newEntries = $parsed;
        } else {
            $newEntries = [];
            $foundLast = false;
            foreach ($parsed as $entry) {
                if ($foundLast) {
                    $newEntries[] = $entry;
                } elseif ($entry['id'] === $lastId) {
                    $foundLast = true;
                }
            }
            if (!$foundLast) {
                // The last-ingested entry has scrolled out of the tail window (log grew a lot
                // between polls, or was rotated/truncated) - just append everything we have.
                $newEntries = $parsed;
            }
        }

        if (empty($newEntries)) {
            return ['success' => true, 'message' => 'No new entries', 'added' => 0, 'total' => count($history)];
        }

        $history = array_merge($history, $newEntries);
        if (count($history) > $maxHistory) {
            $history = array_slice($history, -$maxHistory);
        }

        @file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT), LOCK_EX);

        return ['success' => true, 'message' => 'History updated', 'added' => count($newEntries), 'total' => count($history)];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
