<?php
// Application configuration. Edit to match your server.
// Most values can also be set via environment variables (handy with Docker).

// Time zone: detected from the server automatically, overridable in the UI
// (Settings page) or with the ISPADMIN_TZ environment variable, which wins over both.
// See lib/tz.php. Nothing to configure here.

return [
    'app_name' => 'ISPadmin',
    'version'  => '0.4.0',

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

    // PPPoE authentication via RADIUS (FreeRADIUS + rlm_sql sharing this database).
    // OFF by default. Requires the MySQL driver — FreeRADIUS reads the same database.
    // Even when enabled, a router only uses RADIUS once "PPPoE via RADIUS" is switched on
    // for it in the Routers page; other routers keep the classic /ppp secret provisioning.
    // See the "PPPoE via RADIUS" section of README.md.
    'radius' => [
        'enabled' => in_array(strtolower((string)getenv('ISPADMIN_RADIUS')), ['1', 'true', 'yes', 'on'], true),

        // Unpaid / suspended PPPoE customers:
        //   'restrict' = Access-Accept with the status address-list (and optional throttle /
        //                Filter-Id), so the existing firewall rules on the router keep working;
        //   'reject'   = Access-Reject, no session at all.
        // Terminated customers are always rejected.
        'auth_mode' => getenv('ISPADMIN_RADIUS_MODE') === 'reject' ? 'reject' : 'restrict',

        // Optional extras for restricted sessions. Empty = not sent.
        'restrict_rate'      => getenv('ISPADMIN_RADIUS_RESTRICT_RATE') ?: '',   // e.g. '1M/1M'; empty keeps plan speed (same as DHCP)
        'restrict_filter_id' => getenv('ISPADMIN_RADIUS_FILTER_ID') ?: '',       // e.g. 'walled-garden'

        // How the PPPoE password is stored in radcheck:
        //   'cleartext' = Cleartext-Password — PAP, CHAP and MS-CHAPv2 all work (default);
        //   'nt'        = NT-Password (MD4 hash) — PAP and MS-CHAPv2 only, CHAP will FAIL.
        // Note: customers.pppoe_pass keeps the cleartext anyway (the form shows it and the
        // non-RADIUS /ppp secret path needs it), so 'nt' only hardens the radcheck table.
        'password_storage' => getenv('ISPADMIN_RADIUS_PW_STORAGE') === 'nt' ? 'nt' : 'cleartext',

        // Mikrotik-Rate-Limit template. Placeholders: {ul} {dl} (max-limit, same units as the
        // Simple Queue path) and {ul_at} {dl_at} (max / plan aggregation, i.e. limit-at).
        // With aggregation use e.g. '{ul}/{dl} 0/0 0/0 0/0 8 {ul_at}/{dl_at}'.
        'rate_limit_template' => '{ul}/{dl}',

        // Customers without a static IP get this Framed-Pool (empty = the PPP profile decides).
        'framed_pool' => getenv('ISPADMIN_RADIUS_POOL') ?: '',

        // Accounting: show sessions in the UI and send Acct-Interim-Interval (seconds, 0 = off).
        'accounting'       => true,
        'interim_interval' => (int)(getenv('ISPADMIN_RADIUS_INTERIM') ?: 300),

        // Address the MikroTiks use to reach FreeRADIUS (shown in the generated router config).
        'address' => getenv('RADIUS_ADDRESS') ?: '',

        // FreeRADIUS server, used only by the RADIUS Test button.
        'server'       => getenv('RADIUS_SERVER') ?: '127.0.0.1',
        'auth_port'    => 1812,
        'local_secret' => getenv('RADIUS_LOCAL_SECRET') ?: '',

        // Live changes on a running session (RFC 5176, sent to the NAS on UDP 3799):
        //   status change -> 'disconnect' (PoD; the CPE redials within seconds and gets the new
        //                    profile — works on every RouterOS) or 'coa' (re-apply attributes)
        //   plan change   -> always CoA with the new Mikrotik-Rate-Limit
        'coa' => [
            'status_method' => getenv('ISPADMIN_RADIUS_COA_STATUS') === 'coa' ? 'coa' : 'disconnect',
            'port'          => 3799,
            'timeout'       => 2,
            // Source address for CoA packets. The router only accepts CoA from an address
            // listed in its /radius table, so with several IPs on the host set it explicitly.
            'source_ip'     => getenv('RADIUS_COA_SOURCE') ?: '',
        ],
    ],

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
