<?php
// fileoo.com/clear_cache.php
// Clears the OPcache memory cache on the server.
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "OPcache reset successfully!";
    } else {
        echo "Failed to reset OPcache.";
    }
} else {
    echo "OPcache is not enabled or opcache_reset function is disabled on this server.";
}
