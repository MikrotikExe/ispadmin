<?php
/** Odstrani diakritiku a zmensi pismena - pre vyhladavanie bez diakritiky. */
function noacc(string $s): string
{
    $map = [
        'á'=>'a','Á'=>'a','ä'=>'a','Ä'=>'a','â'=>'a','Â'=>'a','à'=>'a','À'=>'a',
        'č'=>'c','Č'=>'c','ć'=>'c','Ć'=>'c','ď'=>'d','Ď'=>'d',
        'é'=>'e','É'=>'e','ě'=>'e','Ě'=>'e','è'=>'e','È'=>'e','ê'=>'e','Ê'=>'e','ë'=>'e','Ë'=>'e',
        'í'=>'i','Í'=>'i','î'=>'i','Î'=>'i','ï'=>'i','Ï'=>'i',
        'ĺ'=>'l','Ĺ'=>'l','ľ'=>'l','Ľ'=>'l','ł'=>'l','Ł'=>'l',
        'ň'=>'n','Ň'=>'n','ñ'=>'n','Ñ'=>'n',
        'ó'=>'o','Ó'=>'o','ô'=>'o','Ô'=>'o','ö'=>'o','Ö'=>'o','ò'=>'o','Ò'=>'o',
        'ŕ'=>'r','Ŕ'=>'r','ř'=>'r','Ř'=>'r','š'=>'s','Š'=>'s','ś'=>'s','Ś'=>'s',
        'ť'=>'t','Ť'=>'t',
        'ú'=>'u','Ú'=>'u','ů'=>'u','Ů'=>'u','ü'=>'u','Ü'=>'u','ù'=>'u','Ù'=>'u','û'=>'u','Û'=>'u',
        'ý'=>'y','Ý'=>'y','ÿ'=>'y','ž'=>'z','Ž'=>'z','ź'=>'z','Ź'=>'z','ż'=>'z','Ż'=>'z',
    ];
    return strtolower(strtr($s, $map));
}

function db_driver(): string
{
    $cfg = require __DIR__ . '/../config.php';
    return $cfg['db']['driver'];
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = require __DIR__ . '/../config.php';
    $d = $cfg['db'];

    if ($d['driver'] === 'mysql') {
        $m = $d['mysql'];
        $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['dbname']};charset={$m['charset']}";
        $pdo = new PDO($dsn, $m['user'], $m['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $path = $d['sqlite_path'];
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $fresh = !file_exists($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        // vyhladavanie bez diakritiky priamo v SQL
        $pdo->sqliteCreateFunction('noacc', 'noacc', 1);
        if ($fresh) {
            init_sqlite_schema($pdo, $cfg);
        }
    }
    ensure_user_role($pdo, $d['driver']);
    ensure_superadmin($pdo, $cfg);
    ensure_soft_delete($pdo, $d['driver']);
    ensure_router_address($pdo, $d['driver']);
    ensure_networks($pdo, $d['driver']);
    ensure_arp($pdo, $d['driver']);
    ensure_pppoe($pdo, $d['driver']);
    ensure_last_login($pdo, $d['driver']);
    return $pdo;
}

/** Doplni stlpec last_login (kedy sa konto naposledy prihlasilo). */
function ensure_last_login(PDO $pdo, string $driver): void
{
    try {
        if ($driver === 'mysql') {
            $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login'")->fetch();
            if (!$has) $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME NULL");
            $hasIp = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_ip'")->fetch();
            if (!$hasIp) $pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(64) NULL");
        } else {
            $info = $pdo->query('PRAGMA table_info(users)')->fetchAll();
            $has = false; $hasIp = false;
            foreach ($info as $c) {
                if (($c['name'] ?? '') === 'last_login') $has = true;
                if (($c['name'] ?? '') === 'last_login_ip') $hasIp = true;
            }
            if (!$has) $pdo->exec("ALTER TABLE users ADD COLUMN last_login TEXT NULL");
            if (!$hasIp) $pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip TEXT NULL");
        }
    } catch (Throwable $e) { /* ticho */ }
}

/** Doplni stlpec pre PPPoE pripojenie na zakaznika. */
function ensure_pppoe(PDO $pdo, string $driver): void
{
    $add = [
        'conn_type'     => ['mysql' => "VARCHAR(10) NOT NULL DEFAULT 'dhcp'", 'sqlite' => "TEXT NOT NULL DEFAULT 'dhcp'"],
        'pppoe_user'    => ['mysql' => "VARCHAR(120) NOT NULL DEFAULT ''", 'sqlite' => "TEXT NOT NULL DEFAULT ''"],
        'pppoe_pass'    => ['mysql' => "VARCHAR(120) NOT NULL DEFAULT ''", 'sqlite' => "TEXT NOT NULL DEFAULT ''"],
        'pppoe_profile' => ['mysql' => "VARCHAR(120) NOT NULL DEFAULT ''", 'sqlite' => "TEXT NOT NULL DEFAULT ''"],
    ];
    try {
        foreach ($add as $col => $types) {
            if ($driver === 'mysql') {
                $has = $pdo->query("SHOW COLUMNS FROM customers LIKE '$col'")->fetch();
                if (!$has) $pdo->exec("ALTER TABLE customers ADD COLUMN $col {$types['mysql']}");
            } else {
                $info = $pdo->query('PRAGMA table_info(customers)')->fetchAll();
                $has = false;
                foreach ($info as $c) { if (($c['name'] ?? '') === $col) { $has = true; break; } }
                if (!$has) $pdo->exec("ALTER TABLE customers ADD COLUMN $col {$types['sqlite']}");
            }
        }
    } catch (Throwable $e) { /* ticho */ }
}

/** Doplni stlpce pre spravu ARP (reply-only siete) na router. */
function ensure_arp(PDO $pdo, string $driver): void
{
    $add = [
        'manage_arp'    => ['mysql' => 'TINYINT NOT NULL DEFAULT 0', 'sqlite' => 'INTEGER NOT NULL DEFAULT 0'],
        'arp_interface' => ['mysql' => 'VARCHAR(120) NOT NULL DEFAULT \'\'', 'sqlite' => 'TEXT NOT NULL DEFAULT \'\''],
    ];
    try {
        foreach ($add as $col => $types) {
            if ($driver === 'mysql') {
                $has = $pdo->query("SHOW COLUMNS FROM routers LIKE '$col'")->fetch();
                if (!$has) $pdo->exec("ALTER TABLE routers ADD COLUMN $col {$types['mysql']}");
            } else {
                $info = $pdo->query('PRAGMA table_info(routers)')->fetchAll();
                $has = false;
                foreach ($info as $c) { if (($c['name'] ?? '') === $col) { $has = true; break; } }
                if (!$has) $pdo->exec("ALTER TABLE routers ADD COLUMN $col {$types['sqlite']}");
            }
        }
    } catch (Throwable $e) { /* ticho */ }
}

/** Vytvori tabulku networks (siete/skupiny viazane na router) + stlpce na zakaznikovi. */
function ensure_networks(PDO $pdo, string $driver): void
{
    try {
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS networks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                router_id INT NOT NULL,
                name VARCHAR(160) NOT NULL,
                subnet VARCHAR(64) NOT NULL DEFAULT '',
                parent_queue VARCHAR(160) NOT NULL DEFAULT '',
                note VARCHAR(255) NOT NULL DEFAULT '',
                active TINYINT NOT NULL DEFAULT 1
            )");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS networks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                router_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                subnet TEXT NOT NULL DEFAULT '',
                parent_queue TEXT NOT NULL DEFAULT '',
                note TEXT NOT NULL DEFAULT '',
                active INTEGER NOT NULL DEFAULT 1
            )");
        }
    } catch (Throwable $e) { /* ticho */ }

    // stlpce na zakaznikovi: network_id + realna rychlost (override programu)
    $add = [
        'network_id'   => ['mysql' => 'INT NULL',     'sqlite' => 'INTEGER'],
        'real_ul_kbit' => ['mysql' => 'INT NOT NULL DEFAULT 0', 'sqlite' => 'INTEGER NOT NULL DEFAULT 0'],
        'real_dl_kbit' => ['mysql' => 'INT NOT NULL DEFAULT 0', 'sqlite' => 'INTEGER NOT NULL DEFAULT 0'],
    ];
    try {
        foreach ($add as $col => $types) {
            if ($driver === 'mysql') {
                $has = $pdo->query("SHOW COLUMNS FROM customers LIKE '$col'")->fetch();
                if (!$has) {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN $col {$types['mysql']}");
                }
            } else {
                $info = $pdo->query('PRAGMA table_info(customers)')->fetchAll();
                $has = false;
                foreach ($info as $c) {
                    if (($c['name'] ?? '') === $col) { $has = true; break; }
                }
                if (!$has) {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN $col {$types['sqlite']}");
                }
            }
        }
    } catch (Throwable $e) { /* ticho */ }
}

/** Doplni adresne stlpce do routers v existujucich DB. */
function ensure_router_address(PDO $pdo, string $driver): void
{
    $cols = ['ulica', 'cislo_domu', 'mesto', 'vchod', 'poschodie', 'parent_queue', 'siet'];
    try {
        foreach ($cols as $col) {
            if ($driver === 'mysql') {
                $has = $pdo->query("SHOW COLUMNS FROM routers LIKE '$col'")->fetch();
                if (!$has) {
                    $pdo->exec("ALTER TABLE routers ADD COLUMN $col VARCHAR(128) NOT NULL DEFAULT ''");
                }
            } else {
                $info = $pdo->query('PRAGMA table_info(routers)')->fetchAll();
                $has = false;
                foreach ($info as $c) {
                    if (($c['name'] ?? '') === $col) { $has = true; break; }
                }
                if (!$has) {
                    $pdo->exec("ALTER TABLE routers ADD COLUMN $col TEXT NOT NULL DEFAULT ''");
                }
            }
        }
    } catch (Throwable $e) {
        // ticho
    }
}

/** Doplni stlpce pre kos (soft delete) do existujucich DB. */
function ensure_soft_delete(PDO $pdo, string $driver): void
{
    $cols = ['deleted_at', 'deleted_by', 'prev_status'];
    try {
        foreach ($cols as $col) {
            if ($driver === 'mysql') {
                $has = $pdo->query("SHOW COLUMNS FROM customers LIKE '$col'")->fetch();
                if (!$has) {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN $col VARCHAR(32) NULL");
                }
            } else {
                $info = $pdo->query('PRAGMA table_info(customers)')->fetchAll();
                $has = false;
                foreach ($info as $c) {
                    if (($c['name'] ?? '') === $col) { $has = true; break; }
                }
                if (!$has) {
                    $pdo->exec("ALTER TABLE customers ADD COLUMN $col TEXT");
                }
            }
        }
    } catch (Throwable $e) {
        // ticho
    }
}

/** Automaticky odstrani zakaznikov v kosi starsich ako 30 dni. */
function purge_expired_trash(): void
{
    $cfg = require __DIR__ . '/../config.php';
    $driver = $cfg['db']['driver'];
    try {
        if ($driver === 'mysql') {
            db()->exec("DELETE FROM customers WHERE deleted_at IS NOT NULL AND deleted_at < (NOW() - INTERVAL 30 DAY)");
        } else {
            db()->exec("DELETE FROM customers WHERE deleted_at IS NOT NULL AND deleted_at < datetime('now','-30 days')");
        }
    } catch (Throwable $e) {
        // ticho
    }
}

/** Zabezpeci, ze existuje aspon jeden administrator (povysi seed_user, inak najstarsi ucet). */
function ensure_superadmin(PDO $pdo, array $cfg): void
{
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'administrator'")->fetchColumn();
        if ($cnt > 0) {
            return;
        }
        // skus povysit nakonfigurovany seed ucet
        $upd = $pdo->prepare("UPDATE users SET role = 'administrator' WHERE username = ?");
        $upd->execute([$cfg['seed_user']]);
        if ($upd->rowCount() > 0) {
            return;
        }
        // inak povys najstarsi ucet
        $id = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($id !== false) {
            $pdo->prepare("UPDATE users SET role = 'administrator' WHERE id = ?")->execute([$id]);
        }
    } catch (Throwable $e) {
        // ticho
    }
}

/** Doplni stlpec users.role do uz existujucich DB a povysi sucasnych userov na admin. */
function ensure_user_role(PDO $pdo, string $driver): void
{
    try {
        if ($driver === 'mysql') {
            $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
            if (!$has) {
                $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'user'");
                $pdo->exec("UPDATE users SET role = 'admin'");
            }
        } else {
            $cols = $pdo->query('PRAGMA table_info(users)')->fetchAll();
            $has = false;
            foreach ($cols as $c) {
                if (($c['name'] ?? '') === 'role') { $has = true; break; }
            }
            if (!$has) {
                $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'user'");
                $pdo->exec("UPDATE users SET role = 'admin'");
            }
        }
    } catch (Throwable $e) {
        // ticho - ak zlyha, appka bezi dalej (role sa doriesi rucne)
    }
}

function init_sqlite_schema(PDO $pdo, array $cfg): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user'
        );
        CREATE TABLE routers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            host TEXT NOT NULL,
            api_port INTEGER NOT NULL DEFAULT 8728,
            use_ssl INTEGER NOT NULL DEFAULT 0,
            api_user TEXT NOT NULL,
            api_pass TEXT NOT NULL,
            dhcp_server TEXT NOT NULL DEFAULT '',
            parent_queue TEXT NOT NULL DEFAULT '',
            siet TEXT NOT NULL DEFAULT '',
            ulica TEXT NOT NULL DEFAULT '',
            cislo_domu TEXT NOT NULL DEFAULT '',
            mesto TEXT NOT NULL DEFAULT '',
            vchod TEXT NOT NULL DEFAULT '',
            poschodie TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE programs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            aggregation INTEGER NOT NULL DEFAULT 1,
            dl_group INTEGER NOT NULL DEFAULT 0,
            ul_group INTEGER NOT NULL DEFAULT 0,
            dl_user INTEGER NOT NULL DEFAULT 0,
            ul_user INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT,
            updated_by TEXT
        );
        CREATE TABLE customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contract_no TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pripojeny',
            meno TEXT NOT NULL DEFAULT '',
            priezvisko TEXT NOT NULL DEFAULT '',
            firma TEXT NOT NULL DEFAULT '',
            ulica TEXT NOT NULL DEFAULT '',
            cislo_domu TEXT NOT NULL DEFAULT '',
            vchod TEXT NOT NULL DEFAULT '',
            poschodie TEXT NOT NULL DEFAULT '',
            mesto TEXT NOT NULL DEFAULT '',
            telefon TEXT NOT NULL DEFAULT '',
            mail TEXT NOT NULL DEFAULT '',
            router_id INTEGER,
            siet TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            mac TEXT NOT NULL DEFAULT '',
            ont_mac TEXT NOT NULL DEFAULT '',
            program_id INTEGER,
            zariadenie TEXT NOT NULL DEFAULT 'Router',
            ont_sn TEXT NOT NULL DEFAULT '',
            poznamka TEXT NOT NULL DEFAULT '',
            updated_at TEXT,
            updated_by TEXT,
            deleted_at TEXT,
            deleted_by TEXT,
            prev_status TEXT,
            FOREIGN KEY (router_id) REFERENCES routers(id) ON DELETE SET NULL,
            FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL
        );
        CREATE TABLE change_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER,
            contract_no TEXT,
            who TEXT,
            action TEXT,
            created_at TEXT
        );
    SQL);

    // seed pouzivatela (najvyssia rola administrator)
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
    $stmt->execute([$cfg['seed_user'], password_hash($cfg['seed_pass'], PASSWORD_DEFAULT), 'administrator']);

    // seed ukazkovych programov (rychlost v kbit = Mbit*1024) — uprav podla vlastnej ponuky
    // ul/dl Skupina = 0 (skupinovy strop zatial nepouzity), agregacia = 1 (uprav v UI)
    // Pozn.: pri Optika/TV/Office web neuvadza upload -> doplneny odhad, over a uprav.
    $programs = [
        // [name, aggregation, dl_group, ul_group, dl_user, ul_user]
        ['Home Mini', 1, 0, 0, 2048, 512],
        ['Home Lite', 1, 0, 0, 25600, 5120],
        ['Home Štandard', 1, 0, 0, 28672, 5120],
        ['Home Klasik', 1, 0, 0, 35840, 5120],
        ['Home Maxi', 1, 0, 0, 40960, 5120],
        ['Home SENIOR', 1, 0, 0, 4096, 512],
        ['Office Lite', 1, 0, 0, 15360, 15360],
        ['Office Klasik', 1, 0, 0, 20480, 20480],
        ['Office Maxi', 1, 0, 0, 30720, 30720],
        ['TV + Optika Mini', 1, 0, 0, 40960, 10240],
        ['TV + Optika Štandard', 1, 0, 0, 51200, 15360],
        ['TV + Optika Maxi', 1, 0, 0, 81920, 20480],
        ['Optika Mini', 1, 0, 0, 40960, 10240],
        ['Optika Štandard', 1, 0, 0, 71680, 20480],
        ['Optika Maxi', 1, 0, 0, 102400, 25600],
    ];
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare('INSERT INTO programs
        (name, aggregation, dl_group, ul_group, dl_user, ul_user, updated_at, updated_by)
        VALUES (?,?,?,?,?,?,?,?)');
    foreach ($programs as $p) {
        $ins->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $now, 'seed']);
    }
}

function log_change(int $customerId, string $contractNo, string $who, string $action): void
{
    $stmt = db()->prepare('INSERT INTO change_log (customer_id, contract_no, who, action, created_at)
        VALUES (?,?,?,?,?)');
    $stmt->execute([$customerId, $contractNo, $who, $action, date('Y-m-d H:i:s')]);
}
