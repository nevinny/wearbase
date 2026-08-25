<?php
$proj = '/Volumes/SAMSUNG-origin/Users/zyablik/work/wearbase';
require $proj . '/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv($proj . '/.env');

$kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'dev', false);
$kernel->boot();
$conn = $kernel->getContainer()->get('doctrine')->getConnection();

// агрегаторы/каталоги/дистрибьюторы — кривой enriched-email, не бренд
// (free-mail mail.ru/gmail НЕ деним — это норм для микро-бренда, просто email_matches_site=no)
$denyDomains = ['zoon.ru','salita.ru','2gis.ru','flamp.ru','wildberries.ru','ozon.ru','lamoda.ru'];

$sql = "SELECT b.id, b.title, b.slug, b.email,
               (SELECT l.link_url FROM brand_link l
                  WHERE l.brand_id=b.id AND l.link_type='website' AND l.status='active'
                  ORDER BY l.id LIMIT 1) AS website,
               COALESCE(b.city,'') city,
               COALESCE(b.founding_year,'') yr, ROUND(p.top_retrieval_score,3) score,
               p.source_count src
        FROM brand b
        JOIN brand_rag_pipeline p ON p.brand_id = b.id
        WHERE b.status='new' AND b.contact_status='enriched'
          AND b.email IS NOT NULL AND b.email<>''
          AND p.status='done' AND p.grounded=1 AND p.has_own_site=1
          AND p.source_count BETWEEN 4 AND 15
          AND EXISTS(SELECT 1 FROM brand_link l WHERE l.brand_id=b.id
                       AND l.link_type IN('instagram','vk','telegram') AND l.status='active')
          AND EXISTS(SELECT 1 FROM brand_style_brand sb WHERE sb.brand_id=b.id)
        ORDER BY p.top_retrieval_score DESC, p.source_count DESC
        LIMIT 120";

function host(?string $u): string {
    if (!$u) return '';
    $h = parse_url(str_contains($u,'://') ? $u : "http://$u", PHP_URL_HOST) ?: $u;
    $h = strtolower(preg_replace('/^www\./','',$h));
    // base domain = последние 2 метки (грубо, хватает для .ru/.com/.store)
    $p = explode('.', $h);
    return count($p) >= 2 ? implode('.', array_slice($p, -2)) : $h;
}

$rows = $conn->fetchAllAssociative($sql);
$out = __DIR__ . '/cold-sales-candidates.csv';
$fp = fopen($out, 'w');
fputcsv($fp, ['id','title','slug','email','website','email_matches_site','city','founding_year','rag_score','sources','catalog_url']);
$kept = 0;
foreach ($rows as $r) {
    $emailDomain = strtolower(substr(strrchr($r['email'], '@') ?: '', 1));
    $emailBase = host($emailDomain);
    if (in_array($emailDomain, $denyDomains, true)) continue; // кривой контакт
    $match = ($emailBase && $emailBase === host($r['website'])) ? 'yes' : 'no';
    fputcsv($fp, [
        $r['id'], $r['title'], $r['slug'], $r['email'], $r['website'], $match,
        $r['city'], $r['yr'], $r['score'], $r['src'],
        'https://wearbase.ru/ru/brands/' . $r['slug'],
    ]);
    if (++$kept >= 50) break;
}
fclose($fp);
fwrite(STDERR, "wrote $kept rows to $out\n");
