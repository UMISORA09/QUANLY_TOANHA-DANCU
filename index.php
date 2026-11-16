<?php

/**
 * Laravel root index proxy
 * Redirects or includes public/index.php for compatibility with vendor server.php and direct CLI servers.
 */

require_once __DIR__.'/public/index.php';
