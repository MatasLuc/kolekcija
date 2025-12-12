<?php
require_once __DIR__ . '/config.php';

// --- SISTEMINĖS FUNKCIJOS ---

function flash(string $key, ?string $message = null): ?string
{
    if ($message === null) {
        if (!isset($_SESSION['flash'][$key])) {
            return null;
        }
        $value = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $value;
    }

    $_SESSION['flash'][$key] = $message;
    return null;
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    global $pdo;
    $stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        return null;
    }

    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];

    return [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }

    if (!is_admin()) {
        header('Location: index.php');
        exit;
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            die('Saugumo klaida: CSRF patikra nepavyko. Bandykite perkrauti puslapį.');
        }
    }
}

// --- ŠALIŲ ATPAŽINIMO FUNKCIJA (Atnaujinta) ---
function detect_country(string $title): ?string {
    // Išsivalome simbolius geresnei paieškai
    $cleanTitle = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($title));
    $t = ' ' . $cleanTitle . ' ';

    // Pagrindinis žodynas
    $map = [
        // --- EUROPA ---
        'lietuva' => 'Lietuva', 'lithuania' => 'Lietuva', 'litauen' => 'Lietuva',
        'latvija' => 'Latvija', 'latvia' => 'Latvija',
        'estija' => 'Estija', 'estonia' => 'Estija',
        'lenkija' => 'Lenkija', 'poland' => 'Lenkija', 'polska' => 'Lenkija',
        'vokietija' => 'Vokietija', 'germany' => 'Vokietija', 'deutschland' => 'Vokietija', 'frg' => 'Vokietija', 'gdr' => 'Vokietija', 'dr' => 'Vokietija',
        'prancūzija' => 'Prancūzija', 'france' => 'Prancūzija',
        'italija' => 'Italija', 'italy' => 'Italija',
        'ispanija' => 'Ispanija', 'spain' => 'Ispanija',
        'portugalija' => 'Portugalija', 'portugal' => 'Portugalija',
        'graikija' => 'Graikija', 'greece' => 'Graikija',
        'suomija' => 'Suomija', 'finland' => 'Suomija',
        'švedija' => 'Švedija', 'sweden' => 'Švedija',
        'norvegija' => 'Norvegija', 'norway' => 'Norvegija',
        'airija' => 'Airija', 'ireland' => 'Airija',
        'rumunija' => 'Rumunija', 'romania' => 'Rumunija',
        'čekija' => 'Čekija', 'czech' => 'Čekija',
        'čekoslovakija' => 'Čekoslovakija', 'czechoslovakia' => 'Čekoslovakija',
        'slovėnija' => 'Slovėnija', 'slovenia' => 'Slovėnija',
        'vengrija' => 'Vengrija', 'hungary' => 'Vengrija',
        'makedonija' => 'Makedonija', 'macedonia' => 'Makedonija',
        'ukraina' => 'Ukraina', 'ukraine' => 'Ukraina',
        'baltarusija' => 'Baltarusija', 'belarus' => 'Baltarusija',
        'padniestrė' => 'Padniestrė', 'transnistria' => 'Padniestrė',
        'vatikanas' => 'Vatikanas', 'vatican' => 'Vatikanas',
        'bulgarija' => 'Bulgarija', 'bulgaria' => 'Bulgarija',
        'gibraltaras' => 'Gibraltaras', 'gibraltar' => 'Gibraltaras',
        'jungtinė karalystė' => 'Didžioji Britanija', 'didžioji britanija' => 'Didžioji Britanija', 'great britain' => 'Didžioji Britanija', 'uk' => 'Didžioji Britanija', 'anglija' => 'Didžioji Britanija', 'england' => 'Didžioji Britanija',
        'jugoslavija' => 'Jugoslavija', 'yugoslavia' => 'Jugoslavija',
        'turkija' => 'Turkija', 'turkey' => 'Turkija',
        'moldova' => 'Moldova',
        'kroatija' => 'Kroatija', 'croatia' => 'Kroatija',
        'slovakija' => 'Slovakija', 'slovakia' => 'Slovakija',
        'kipras' => 'Kipras', 'cyprus' => 'Kipras',
        'bosnija' => 'Bosnija ir Hercegovina', 'bosnia' => 'Bosnija ir Hercegovina', 'hercegovina' => 'Bosnija ir Hercegovina',
        'šveicarija' => 'Šveicarija', 'switzerland' => 'Šveicarija', 'swiss' => 'Šveicarija',
        'malta' => 'Malta',
        'austrija' => 'Austrija', 'austria' => 'Austrija',
        'nyderlandai' => 'Nyderlandai', 'netherlands' => 'Nyderlandai', 'holland' => 'Nyderlandai',
        'san marinas' => 'San Marinas', 'san marino' => 'San Marinas',
        'džersis' => 'Džersis', 'jersey' => 'Džersis',
        'belgija' => 'Belgija', 'belgium' => 'Belgija',
        'gernsis' => 'Gernsis', 'guernsey' => 'Gernsis',
        'albanija' => 'Albanija', 'albania' => 'Albanija',
        'islandija' => 'Islandija', 'iceland' => 'Islandija',
        'gruzija' => 'Gruzija', 'georgia' => 'Gruzija', 'sakartvelas' => 'Gruzija',
        'monakas' => 'Monakas', 'monaco' => 'Monakas',

        // --- ŠIAURĖS IR PIETŲ AMERIKA IR KARIBAI ---
        'jav' => 'JAV', 'usa' => 'JAV', 'america' => 'JAV', 'united states' => 'JAV',
        'kanada' => 'Kanada', 'canada' => 'Kanada',
        'meksika' => 'Meksika', 'mexico' => 'Meksika',
        'čilė' => 'Čilė', 'chile' => 'Čilė',
        'peru' => 'Peru',
        'panama' => 'Panama',
        'nikaragva' => 'Nikaragva', 'nicaragua' => 'Nikaragva',
        'brazilija' => 'Brazilija', 'brazil' => 'Brazilija',
        'belizas' => 'Belizas', 'belize' => 'Belizas',
        'haitis' => 'Haitis', 'haiti' => 'Haitis',
        'venesuela' => 'Venesuela', 'venezuela' => 'Venesuela',
        'paragvajus' => 'Paragvajus', 'paraguay' => 'Paragvajus',
        'argentina' => 'Argentina',
        'kuba' => 'Kuba', 'cuba' => 'Kuba',
        'falkland' => 'Folklando salos', 'folklando' => 'Folklando salos',
        'urugvajus' => 'Urugvajus', 'uruguay' => 'Urugvajus',
        'kaimanų salos' => 'Kaimanų Salos', 'cayman' => 'Kaimanų Salos',
        'ekvadoras' => 'Ekvadoras', 'ecuador' => 'Ekvadoras',
        'kolumbija' => 'Kolumbija', 'colombia' => 'Kolumbija',
        'gajana' => 'Gajana', 'guyana' => 'Gajana',
        'bolivija' => 'Bolivija', 'bolivia' => 'Bolivija',
        'hondūras' => 'Hondūras', 'honduras' => 'Hondūras',
        'trinidadas' => 'Trinidadas ir Tobagas', 'trinidad' => 'Trinidadas ir Tobagas',
        'rytų karibai' => 'Rytų Karibai', 'eastern caribbean' => 'Rytų Karibai',
        'kosta rika' => 'Kosta Rika', 'costa rica' => 'Kosta Rika',
        'bahamai' => 'Bahamai', 'bahamas' => 'Bahamai',
        'gvatemala' => 'Gvatemala', 'guatemala' => 'Gvatemala',
        'salvadoras' => 'Salvadoras', 'el salvador' => 'Salvadoras',
        'jamaika' => 'Jamaika', 'jamaica' => 'Jamaika',
        'surinamas' => 'Surinamas', 'suriname' => 'Surinamas',
        'nyderlandų antilai' => 'Nyderlandų Antilai', 'netherlands antilles' => 'Nyderlandų Antilai',
        'aruba' => 'Aruba',
        'dominikos respublika' => 'Dominikos Respublika', 'dominican republic' => 'Dominikos Respublika', 'dominikos resp' => 'Dominikos Respublika', 'dominika' => 'Dominikos Respublika',

        // --- AZIJA ---
        'rusija' => 'Rusija', 'russia' => 'Rusija', 'ssrs' => 'SSRS', 'ussr' => 'SSRS', 'cccp' => 'SSRS',
        'kinija' => 'Kinija', 'china' => 'Kinija',
        'japonija' => 'Japonija', 'japan' => 'Japonija',
        'pietų korėja' => 'Pietų Korėja', 'south korea' => 'Pietų Korėja', 'korea' => 'Pietų Korėja',
        'šiaurės korėja' => 'Šiaurės Korėja', 'north korea' => 'Šiaurės Korėja', 'dprk' => 'Šiaurės Korėja',
        'izraelis' => 'Izraelis', 'israel' => 'Izraelis',
        'indija' => 'Indija', 'india' => 'Indija',
        'pakistanas' => 'Pakistanas', 'pakistan' => 'Pakistanas',
        'bangladešas' => 'Bangladešas', 'bangladesh' => 'Bangladešas',
        'šri lanka' => 'Šri Lanka', 'sri lanka' => 'Šri Lanka',
        'kambodža' => 'Kambodža', 'cambodia' => 'Kambodža',
        'filipinai' => 'Filipinai', 'philippines' => 'Filipinai',
        'brunėjus' => 'Brunėjus', 'brunei' => 'Brunėjus',
        'jemenas' => 'Jemenas', 'yemen' => 'Jemenas',
        'jordanija' => 'Jordanija', 'jordan' => 'Jordanija',
        'bahreinas' => 'Bahreinas', 'bahrain' => 'Bahreinas',
        'hong kong' => 'Hong Kong', 'honkongas' => 'Hong Kong',
        'makao' => 'Makao', 'macau' => 'Makao',
        'maldyvai' => 'Maldyvai', 'maldives' => 'Maldyvai',
        'libanas' => 'Libanas', 'lebanon' => 'Libanas',
        'kataras' => 'Kataras', 'qatar' => 'Kataras',
        'azerbaidžanas' => 'Azerbaidžanas', 'azerbaijan' => 'Azerbaidžanas',
        'sirija' => 'Sirija', 'syria' => 'Sirija',
        'tadžikistanas' => 'Tadžikistanas', 'tajikistan' => 'Tadžikistanas',
        'tailandas' => 'Tailandas', 'thailand' => 'Tailandas',
        'indonezija' => 'Indonezija', 'indonesia' => 'Indonezija',
        'kazachstanas' => 'Kazachstanas', 'kazakhstan' => 'Kazachstanas',
        'uzbekistanas' => 'Uzbekistanas', 'uzbekistan' => 'Uzbekistanas',
        'iranas' => 'Iranas', 'iran' => 'Iranas',
        'saudo arabija' => 'Saudo Arabija', 'saudi arabia' => 'Saudo Arabija',
        'osmanas' => 'Osmanų Imperija', 'ottoman' => 'Osmanų Imperija',
        'kirkizija' => 'Kirgizija', 'kirgizija' => 'Kirgizija', 'kyrgyzstan' => 'Kirgizija',
        'mongolija' => 'Mongolija', 'mongolia' => 'Mongolija',
        'nepalas' => 'Nepalas', 'nepal' => 'Nepalas',
        'mianmaras' => 'Mianmaras', 'myanmar' => 'Mianmaras', 'burma' => 'Mianmaras',
        'malaizija' => 'Malaizija', 'malaysia' => 'Malaizija',
        'omanas' => 'Omanas', 'oman' => 'Omanas',
        'butanas' => 'Butanas', 'bhutan' => 'Butanas',
        'taivanas' => 'Taivanas', 'taiwan' => 'Taivanas',
        'irakas' => 'Irakas', 'iraq' => 'Irakas',
        'turkmėnija' => 'Turkmėnija', 'turkmenistan' => 'Turkmėnija',
        'kalnų karabachas' => 'Kalnų Karabachas', 'nagorno' => 'Kalnų Karabachas',
        'armėnija' => 'Armėnija', 'armenia' => 'Armėnija',
        'laosas' => 'Laosas', 'laos' => 'Laosas',
        'tatarstanas' => 'Tatarstanas', 'tatarstan' => 'Tatarstanas',
        'singapūras' => 'Singapūras', 'singapore' => 'Singapūras',
        'vietnamas' => 'Vietnamas', 'vietnam' => 'Vietnamas',

        // --- AFRIKA ---
        'pietų sudanas' => 'Pietų Sudanas', 'south sudan' => 'Pietų Sudanas',
        'sudanas' => 'Sudanas', 'sudan' => 'Sudanas',
        'liberija' => 'Liberija', 'liberia' => 'Liberija',
        'tanzanija' => 'Tanzanija', 'tanzania' => 'Tanzanija',
        'mozambikas' => 'Mozambikas', 'mozambique' => 'Mozambikas',
        'senegalas' => 'Senegalas', 'senegal' => 'Senegalas',
        'lesotas' => 'Lesotas', 'lesotho' => 'Lesotas',
        'libija' => 'Libija', 'libya' => 'Libija',
        'afganistanas' => 'Afganistanas', 'afghanistan' => 'Afganistanas',
        'zambija' => 'Zambija', 'zambia' => 'Zambija',
        'mauricijus' => 'Mauricijus', 'mauritius' => 'Mauricijus',
        'centrinė afrika' => 'Centrinė Afrika', 'central africa' => 'Centrinė Afrika', 'car' => 'Centrinė Afrika',
        'etiopija' => 'Etiopija', 'ethiopia' => 'Etiopija',
        'san tomė' => 'San Tomė ir Prinsipė', 'sao tome' => 'San Tomė ir Prinsipė',
        'esvatinis' => 'Esvatinis', 'eswatini' => 'Esvatinis', 'swaziland' => 'Esvatinis',
        'madagaskaras' => 'Madagaskaras', 'madagascar' => 'Madagaskaras',
        'ruanda' => 'Ruanda', 'rwanda' => 'Ruanda',
        'marokas' => 'Marokas', 'morocco' => 'Marokas',
        'burundis' => 'Burundis', 'burundi' => 'Burundis',
        'malavis' => 'Malavis', 'malawi' => 'Malavis',
        'nigerija' => 'Nigerija', 'nigeria' => 'Nigerija',
        'mauritanija' => 'Mauritanija', 'mauritania' => 'Mauritanija',
        'pietų afrika' => 'Pietų Afrika', 'pietų afrikos' => 'Pietų Afrika', 'south africa' => 'Pietų Afrika', 'rsa' => 'Pietų Afrika',
        'angola' => 'Angola',
        'malis' => 'Malis', 'mali' => 'Malis',
        'komorai' => 'Komorai', 'comoros' => 'Komorai',
        'tunisas' => 'Tunisas', 'tunisia' => 'Tunisas',
        'džibutis' => 'Džibutis', 'djibouti' => 'Džibutis',
        'zairas' => 'Zairas', 'zaire' => 'Zairas',
        'kenija' => 'Kenija', 'kenya' => 'Kenija',
        'egiptas' => 'Egiptas', 'egypt' => 'Egiptas',
        'žaliasis kyšulys' => 'Žaliasis Kyšulys', 'cape verde' => 'Žaliasis Kyšulys',
        'gambija' => 'Gambija', 'gambia' => 'Gambija',
        'namibija' => 'Namibija', 'namibia' => 'Namibija',
        'siera leonė' => 'Siera Leonė', 'sierra leone' => 'Siera Leonė',
        'somalilandas' => 'Somalilandas', 'somaliland' => 'Somalilandas',
        'biafra' => 'Biafra',
        'alžyras' => 'Alžyras', 'algeria' => 'Alžyras',
        'gana' => 'Gana', 'ghana' => 'Gana',
        'togas' => 'Togas', 'togo' => 'Togas',
        'uganda' => 'Uganda',
        'eritrėja' => 'Eritrėja', 'eritrea' => 'Eritrėja',
        'zimbabvė' => 'Zimbabvė', 'zimbabwe' => 'Zimbabvė',
        'dramblio kaulo krantas' => 'Dramblio Kaulo Krantas', 'ivory coast' => 'Dramblio Kaulo Krantas', 'cote d\'ivoire' => 'Dramblio Kaulo Krantas',
        'somalis' => 'Somalis', 'somalia' => 'Somalis',
        'botsvana' => 'Botsvana', 'botswana' => 'Botsvana',
        'gvinėja' => 'Gvinėja', 'guinea' => 'Gvinėja',
        'katanga' => 'Katanga',

        // --- OKEANIJA IR KITA ---
        'australija' => 'Australija', 'australia' => 'Australija',
        'naujoji zelandija' => 'Naujoji Zelandija', 'new zealand' => 'Naujoji Zelandija', 'nz' => 'Naujoji Zelandija',
        'fiji' => 'Fiji', 'fidžis' => 'Fiji',
        'sint maarten' => 'Sint Maarten',
        'kiurasao' => 'Kiurasao', 'curacao' => 'Kiurasao',
        'prancūzijos polinezija' => 'Prancūzijos Polinezija', 'french polynesia' => 'Prancūzijos Polinezija',
        'papua' => 'Papua Naujoji Gvinėja', 'papua new guinea' => 'Papua Naujoji Gvinėja',
        'kokosų' => 'Kokosų (Kilingo) Salos', 'cocos' => 'Kokosų (Kilingo) Salos',
        'velykų sala' => 'Velykų Sala', 'easter island' => 'Velykų Sala',
        'niujė' => 'Niujė', 'niue' => 'Niujė',
        'tristanas da kunja' => 'Tristanas da Kunja', 'tristan da cunha' => 'Tristanas da Kunja',
    ];

    foreach ($map as $search => $standard) {
        // Ieškome pilno žodžio (\b... \b)
        if (preg_match('/\b' . preg_quote($search, '/') . '\b/u', $cleanTitle)) {
            return $standard;
        }
    }
    return null;
}
// --- KATEGORIJOS ATPAŽINIMO FUNKCIJA ---
function detect_category(string $url): ?string {
    // Tikriname, ar nuorodoje yra specifinis fragmentas
    if (strpos($url, '3520-numizmatika-monetos') !== false) {
        return 'Numizmatika (monetos)';
    }
    if (strpos($url, '3580-bonistika-banknotai') !== false) {
        return 'Bonistika (banknotai)';
    }
    if (strpos($url, '3530-faleristika-ordinai-medaliai-zenkliukai') !== false) {
        return 'Faleristika (ordinai, medaliai, ženkliukai)';
    }
    if (strpos($url, '3535-filumenistika-degtukai-etiketes') !== false) {
        return 'Filumenistika (degtukai, etiketės)';
    }
    if (strpos($url, '3540-kiti-daiktai-kolekcionavimui') !== false) {
        return 'Kiti daiktai kolekcionavimui';
    }
    if (strpos($url, '656094-reikmenys-kolekcionavimui') !== false) {
        return 'Reikmenys kolekcionavimui';
    }
    
    return null; // Kategorija neatpažinta
}