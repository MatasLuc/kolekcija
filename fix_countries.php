<?php
require_once __DIR__ . '/db.php';

// Įdėkite detect_country funkciją čia (arba importuokite)
function detect_country(string $title): ?string {
    $t = mb_strtolower($title);
    $map = [
        'lietuva'=>'Lietuva', 'lithuania'=>'Lietuva', 'litauen'=>'Lietuva',
        'jav'=>'JAV', 'usa'=>'JAV', 'america'=>'JAV', 'united states'=>'JAV',
        'vokietija'=>'Vokietija', 'germany'=>'Vokietija', 'deutschland'=>'Vokietija',
        'lenkija'=>'Lenkija', 'poland'=>'Lenkija', 'polska'=>'Lenkija',
        'rusija'=>'Rusija', 'russia'=>'Rusija', 'ssrs'=>'SSRS', 'ussr'=>'SSRS', 'cccp'=>'SSRS',
        'latvija'=>'Latvija', 'latvia'=>'Latvija',
        'estija'=>'Estija', 'estonia'=>'Estija',
        'didžioji britanija'=>'Didžioji Britanija', 'great britain'=>'Didžioji Britanija', 'uk'=>'Didžioji Britanija',
        'prancūzija'=>'Prancūzija', 'france'=>'Prancūzija',
        'italija'=>'Italija', 'italy'=>'Italija',
        'ispanija'=>'Ispanija', 'spain'=>'Ispanija',
        'kinija'=>'Kinija', 'china'=>'Kinija',
        'kanada'=>'Kanada', 'canada'=>'Kanada',
        'australija'=>'Australija', 'australia'=>'Australija',
        'suomija'=>'Suomija', 'finland'=>'Suomija',
        'švedija'=>'Švedija', 'sweden'=>'Švedija',
        'norvegija'=>'Norvegija', 'norway'=>'Norvegija',
        'ukraina'=>'Ukraina', 'ukraine'=>'Ukraina',
        'baltarusija'=>'Baltarusija', 'belarus'=>'Baltarusija',
        'vatikanas'=>'Vatikanas', 'vatican'=>'Vatikanas',
        'izraelis'=>'Izraelis', 'israel'=>'Izraelis',
    ];
    foreach ($map as $search => $std) {
        if (mb_strpos($t, $search) !== false) return $std;
    }
    return null;
}

echo "<h2>Šalių atnaujinimas</h2>";

$stmt = $pdo->query("SELECT id, title FROM products");
$count = 0;
$updated = 0;

while ($row = $stmt->fetch()) {
    $country = detect_country($row['title']);
    if ($country) {
        $upd = $pdo->prepare("UPDATE products SET country = ? WHERE id = ?");
        $upd->execute([$country, $row['id']]);
        $updated++;
    }
    $count++;
}

echo "Patikrinta prekių: $count <br>";
echo "Rasta ir įrašyta šalių: $updated <br>";
echo "Baigta!";
?>
