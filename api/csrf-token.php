<?php
/**
 * Hands the current session's CSRF token to the page.
 *
 * Same-origin only in practice: there is no CORS header here, so a page on
 * another origin can issue the request but cannot read the response.
 */

declare(strict_types=1);

require __DIR__ . '/session.php';

app_session_start();
security_headers();

json_ok(['token' => csrf_token()]);
