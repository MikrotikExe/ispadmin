<?php
/**
 * Jednoduchý i18n systém.
 *
 * - Zdrojový jazyk je slovenčina — kľúčom prekladu je priamo slovenský text.
 * - Preklady sú v lang/<kod>.php (vracia pole 'slovenský text' => 'preklad').
 * - Chýbajúci preklad spadne na angličtinu (lang/en.php), potom na slovenčinu.
 * - Jazyk sa vyberá: ?lang=xx (uloží sa do cookie) -> cookie -> Accept-Language -> sk.
 *
 * Pridanie nového jazyka: skopíruj lang/en.php na lang/xx.php, prelož hodnoty
 * a pridaj kód + názov do LANGS nižšie.
 */

const LANGS = [
    'sk' => 'Slovenčina',
    'cs' => 'Čeština',
    'en' => 'English',
    'de' => 'Deutsch',
    'pl' => 'Polski',
    'hu' => 'Magyar',
    'ro' => 'Română',
    'uk' => 'Українська',
    'lv' => 'Latviešu',
    'ru' => 'Русский',
];

const LANG_COOKIE = 'ispadmin_lang';

/** Aktuálny jazyk (dvojznakový kód z LANGS). */
function lang_current(): string
{
    static $lang = null;
    if ($lang !== null) return $lang;

    $get = (string)($_GET['lang'] ?? '');
    if ($get !== '' && isset(LANGS[$get])) {
        $lang = $get;
        if (!headers_sent()) {
            setcookie(LANG_COOKIE, $lang, [
                'expires'  => time() + 86400 * 365,
                'path'     => '/',
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[LANG_COOKIE] = $lang;
        return $lang;
    }

    $cookie = (string)($_COOKIE[LANG_COOKIE] ?? '');
    if ($cookie !== '' && isset(LANGS[$cookie])) return $lang = $cookie;

    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
        $code = strtolower(substr(trim($part), 0, 2));
        if (isset(LANGS[$code])) return $lang = $code;
    }
    return $lang = 'sk';
}

/** Slovník aktuálneho jazyka (s EN fallbackom). */
function lang_dict(): array
{
    static $dict = null;
    if ($dict !== null) return $dict;

    $lang = lang_current();
    if ($lang === 'sk') return $dict = [];

    $load = static function (string $code): array {
        $f = __DIR__ . '/../lang/' . basename($code) . '.php';
        if (!is_file($f)) return [];
        $a = require $f;
        return is_array($a) ? $a : [];
    };

    $en  = $load('en');
    $cur = $lang === 'en' ? [] : $load($lang);
    return $dict = $cur + $en; // preklad jazyka má prednosť, EN je fallback
}

/** Preloží text; nepreložené texty vráti po slovensky. Voliteľné %s placeholdery cez $args. */
function t(string $s, ...$args): string
{
    $out = lang_dict()[$s] ?? $s;
    return $args ? vsprintf($out, $args) : $out;
}

/** Vykreslí <select> na prepnutie jazyka (reload s ?lang=xx, ostatné parametre zachová). */
function lang_selector(string $class = 'lang-select'): void
{
    $cur = lang_current();
    $qs = $_GET;
    unset($qs['lang']);
    $base = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    $extra = http_build_query($qs);
    $url = htmlspecialchars($base . '?' . ($extra !== '' ? $extra . '&' : '') . 'lang=', ENT_QUOTES);
    echo '<select class="' . htmlspecialchars($class, ENT_QUOTES) . '" onchange="location.href=\'' . $url . '\'+this.value" title="Language" aria-label="Language">';
    foreach (LANGS as $code => $name) {
        $sel = $code === $cur ? ' selected' : '';
        echo '<option value="' . $code . '"' . $sel . '>' . htmlspecialchars($name, ENT_QUOTES) . '</option>';
    }
    echo '</select>';
}
