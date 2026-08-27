<?php
// Application configuration. Edit to match your server.
// Most values can also be set via environment variables (handy with Docker).

// Time zone: detected from the server automatically, overridable in the UI
// (Settings page) or with the ISPADMIN_TZ environment variable, which wins over both.
// See lib/tz.php. Nothing to configure here.

return [
    'app_name' => 'ISPadmin',
    'version'  => '0.3.0',

    // Header logo (two-tone text). Change it to your own company name.
    'brand_pre'  => 'isp',
    'brand_post' => 'admin',
    'tagline'    => 'customer management · MikroTik',

    'db' => [
        // 'sqlite' (zero-config) or 'mysql'. Can also be set with the DB_DRIVER env variable.
        'driver'      => getenv('DB_DRIVER') ?: 'sqlite',
        'sqlite_path' => getenv('ISPADMIN_SQLITE') ?: (__DIR__ . '/data/ispadmin.sqlite'),
        'mysql' => [
            'host'    => getenv('DB_HOST') ?: '127.0.0.1',
            'port'    => (int)(getenv('DB_PORT') ?: 3306),
            'dbname'  => getenv('DB_NAME') ?: 'ispadmin',
            'user'    => getenv('DB_USER') ?: 'ispadmin',
            'pass'    => getenv('DB_PASS') ?: '',
            'charset' => 'utf8mb4',
        ],
    ],

    'session_name' => 'ispadmin',

    // Default firewall address list used when a customer is suspended or unpaid.
    // Create a matching rule on the router, for example:
    //   /ip firewall filter add chain=forward src-address-list=unpaid action=drop
    'block_address_list' => 'unpaid',

    // A separate firewall address list per status. The actual blocking is done by
    // the drop rule you create on the MikroTik — the app only maintains the lists.
    'block_lists' => [
        'docasne'  => getenv('ISPADMIN_LIST_SUSPENDED')  ?: 'suspended',
        'neplatic' => getenv('ISPADMIN_LIST_UNPAID')     ?: 'unpaid',
        'ukoncena' => getenv('ISPADMIN_LIST_TERMINATED') ?: 'terminated',
    ],

    // Queue speed applied to suspended / unpaid customers. The queue is not disabled,
    // only throttled, so static-IP customers keep a working (but useless) link.
    'block_limit' => getenv('ISPADMIN_BLOCK_LIMIT') ?: '1k/1k',

    // Default account created on first run. CHANGE THE PASSWORD AFTER LOGGING IN.
    'seed_user' => 'admin',
    'seed_pass' => 'changeme',

    // Geo-blocking: restrict access to selected countries only.
    // DISABLED by default so nobody locks themselves out. Enable with ISPADMIN_GEO_ENFORCE=1.
    // Works either from downloaded CIDR lists (see update_geoip.php) or, if your traffic
    // goes through Cloudflare, from the CF-IPCountry header. If the country cannot be
    // determined, access is allowed rather than denied.
    'geo' => [
        'enforce'   => in_array(strtolower((string)getenv('ISPADMIN_GEO_ENFORCE')), ['1', 'true', 'yes', 'on'], true),
        'countries' => array_filter(array_map('trim', explode(',', strtoupper(getenv('ISPADMIN_GEO_COUNTRIES') ?: 'SK')))),
        // Your own fixed public IPs, always allowed. Comma separated.
        'allow_ips' => array_filter(array_map('trim', explode(',', (string)getenv('ISPADMIN_GEO_ALLOW_IPS')))),
        // File holding the allowed CIDR ranges (downloaded by update_geoip.php).
        // Kept next to the database so it survives container rebuilds.
        'cidr_file' => getenv('ISPADMIN_GEO_CIDR_FILE') ?: (dirname(getenv('ISPADMIN_SQLITE') ?: (__DIR__ . '/data/x')) . '/geo-cidr.txt'),
    ],
];
