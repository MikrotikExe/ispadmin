<?php
/**
 * Časová zóna aplikácie.
 *
 * Poradie priority (prvá platná vyhrá):
 *   1. env premenná ISPADMIN_TZ  – pre Docker / hromadné nasadenie
 *   2. nastavenie v DB           – ručná voľba na stránke Settings
 *   3. časová zóna servera       – autodetekcia z /etc/timezone alebo /etc/localtime
 *   4. UTC                       – posledná záchrana
 *
 * Vďaka bodu 3 sedia časy hneď po inštalácii bez toho, aby niekto niečo nastavoval.
 */

/** Zistí časovú zónu operačného systému. Vráti null, ak sa nedá určiť. */
function tz_detect_server(): ?string
{
    // 1) Debian / Ubuntu: /etc/timezone obsahuje priamo napr. "Europe/Bratislava"
    if (@is_readable('/etc/timezone')) {
        $tz = trim((string)@file_get_contents('/etc/timezone'));
        if ($tz !== '' && tz_is_valid($tz)) {
            return $tz;
        }
    }

    // 2) Väčšina distribúcií: /etc/localtime je symlink do /usr/share/zoneinfo/...
    if (@is_link('/etc/localtime')) {
        $target = (string)@readlink('/etc/localtime');
        if (preg_match('#zoneinfo/(.+)$#', $target, $m) && tz_is_valid($m[1])) {
            return $m[1];
        }
    }

    // 3) Fallback: hodnota z php.ini (často len "UTC", ale keď je nastavená inak, použijeme ju)
    $ini = trim((string)ini_get('date.timezone'));
    if ($ini !== '' && tz_is_valid($ini)) {
        return $ini;
    }

    return null;
}

/** Je to platný identifikátor časovej zóny? */
function tz_is_valid(string $tz): bool
{
    static $all = null;
    if ($all === null) {
        // ALL_WITH_BC zahrna aj Etc/* a starsie nazvy (Asia/Calcutta a pod.),
        // ktore sa realne vyskytuju v /etc/timezone na starsich systemoch
        $all = array_flip(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));
    }
    return isset($all[$tz]);
}

/**
 * Vráti časovú zónu, ktorú má aplikácia použiť, spolu s jej zdrojom.
 * @return array{tz: string, source: string}  source: env | db | server | default
 */
function tz_resolve(): array
{
    $env = trim((string)getenv('ISPADMIN_TZ'));
    if ($env !== '' && tz_is_valid($env)) {
        return ['tz' => $env, 'source' => 'env'];
    }

    if (function_exists('setting_get')) {
        $db = (string)setting_get('timezone', '');
        if ($db !== '' && tz_is_valid($db)) {
            return ['tz' => $db, 'source' => 'db'];
        }
    }

    $srv = tz_detect_server();
    if ($srv !== null) {
        return ['tz' => $srv, 'source' => 'server'];
    }

    return ['tz' => 'UTC', 'source' => 'default'];
}

/** Nastaví časovú zónu pre celý beh skriptu. Vracia použitú zónu. */
function tz_apply(): string
{
    $r = tz_resolve();
    date_default_timezone_set($r['tz']);
    return $r['tz'];
}
