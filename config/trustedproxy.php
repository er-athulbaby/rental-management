<?php

/*
 * Read by Laravel's TrustProxies middleware on every request. Only these addresses (the load balancer or reverse proxy in
 * front of the app) may set X-Forwarded-For, so request()->ip() is the client's and the per-IP sign-in limits and audit
 * IPs are per client. Empty by default: nothing is trusted, and forwarded headers are ignored. Never '*'.
 * A config file rather than trustProxies(at: env(...)) in bootstrap/app.php, which would read nothing under config:cache.
 */
return [
    'proxies' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
