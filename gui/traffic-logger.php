<?php
// Background daemon: periodically archives new tinyproxy.log entries into the capped
// traffic-history.json store, independent of whether the web GUI is open. Started by
// entrypoint.sh alongside php-fpm/nginx.
require_once __DIR__ . '/traffic-parser.php';

while (true) {
    ingestTrafficHistory();
    sleep(10);
}
