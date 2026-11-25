<?php
declare(strict_types=1);

/**
 * Prueba de rendimiento y disponibilidad HTTP (Requisito No Funcional)
 * Ejecuta N peticiones concurrentes usando curl_multi contra el endpoint de salud.
 */
$url = $argv[1] ?? 'http://localhost:8080/restaurante/public/index.php?health=1';
$totalRequests = (int)($argv[2] ?? 20);

$mh = curl_multi_init();
$handles = [];
$start = microtime(true);

for ($i = 0; $i < $totalRequests; $i++) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);

$elapsedMs = round((microtime(true) - $start) * 1000, 2);
$ok = 0;
$fail = 0;

foreach ($handles as $ch) {
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code === 200) {
        $ok++;
    } else {
        $fail++;
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

$avgMs = round($elapsedMs / max(1, $totalRequests), 2);
echo "[LOAD TEST] URL: {$url}\n";
echo "[LOAD TEST] Peticiones concurrentes: {$totalRequests} | Exitosas (200 OK): {$ok} | Fallidas: {$fail}\n";
echo "[LOAD TEST] Tiempo total: {$elapsedMs} ms | Latencia promedio: {$avgMs} ms/req\n";

if ($fail > 0) {
    fwrite(STDERR, "[LOAD TEST] ERROR: Se detectaron peticiones fallidas.\n");
    exit(1);
}
