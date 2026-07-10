<?php
// Konfiguracia aplikacie. Skopiruj a uprav podla servera.

// Casova zona pre vsetky casy v aplikacii (prihlasenia, zmeny...). Default SR.
date_default_timezone_set(getenv('ISPADMIN_TZ') ?: 'Europe/Bratislava');

return [
    'app_name' => 'ISPadmin',
    'version'  => '0.2.0',

    // Logo v hlavičke (dvojfarebný text). Zmeň podľa seba – napr. názov tvojej firmy.
    'brand_pre'  => 'isp',
    'brand_post' => 'admin',
    'tagline'    => 'správa zákazníkov · MikroTik',

    'db' => [
        // 'sqlite' (zero-config) alebo 'mysql'. Riadi sa aj env premennou DB_DRIVER.
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

    'session_name' => 'mt_ispadmin',

    // Pri stave Docasne odpojeny / Neplatic sa IP prida do tohto address-listu.
    // Na routeri si sprav firewall pravidlo: chain=forward src/dst-address-list=neplatici action=drop
    'block_address_list' => 'neplatici',
    // samostatny firewall address-list pre kazdy stav (blok rieši drop pravidlo na MikroTiku)
    'block_lists' => [
        'docasne'  => getenv('ISPADMIN_LIST_DOCASNE')  ?: 'docasne_odpojeni',
        'neplatic' => getenv('ISPADMIN_LIST_NEPLATIC') ?: 'neplatici',
        'ukoncena' => getenv('ISPADMIN_LIST_UKONCENA') ?: 'vypovede',
    ],
    // rychlost fronty pre docasne odpojenych / neplaticov (statickí zákazníci: fronta sa nevypína, len zníži)
    'block_limit' => getenv('ISPADMIN_BLOCK_LIMIT') ?: '1k/1k',

    // Default prihlasovaci ucet pri prvom spusteni (zmen heslo po prihlaseni).
    'seed_user' => 'admin',
    'seed_pass' => 'changeme',

    // Geo-ochrana: povolit pristup len z vybranych krajin (cez Cloudflare hlavicku CF-IPCountry).
    // Default VYPNUTE, aby sa nikto nezamkol. Zapni cez ISPADMIN_GEO_ENFORCE=1.
    // Funguje len ak prevadzka ide cez Cloudflare (inak sa krajina neda zistit a pristup sa povoli).
    'geo' => [
        'enforce'   => in_array(strtolower((string)getenv('ISPADMIN_GEO_ENFORCE')), ['1', 'true', 'yes', 'on'], true),
        'countries' => array_filter(array_map('trim', explode(',', strtoupper(getenv('ISPADMIN_GEO_COUNTRIES') ?: 'SK')))),
        'allow_ips' => array_filter(array_map('trim', explode(',', (string)getenv('ISPADMIN_GEO_ALLOW_IPS')))),
        // Subor so zoznamom povolenych CIDR rozsahov (stiahne update_geoip.php). Default v /data (perzistentne).
        'cidr_file' => getenv('ISPADMIN_GEO_CIDR_FILE') ?: (dirname(getenv('ISPADMIN_SQLITE') ?: (__DIR__ . '/data/x')) . '/geo-cidr.txt'),
    ],
];
