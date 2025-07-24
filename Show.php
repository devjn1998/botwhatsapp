<?php

// === CONFIGURAÇÃO DE AMBIENTE ===
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/erro_visualizador.log'); // Salva erros de debug neste arquivo

// === FUNÇÃO DE DEBUG SIMPLES ===
function debug($mensagem)
{
    error_log("[DEBUG] " . $mensagem);
}

// === 1. RECEBE E DECODIFICA O PARÂMETRO BASE64 ===
$base64Path = $_GET['path'] ?? '';

if (!$base64Path) {
    http_response_code(400);
    exit('Erro: Parâmetro "path" ausente.');
}

$caminho_recebido = base64_decode($base64Path);

if (!$caminho_recebido) {
    http_response_code(400);
    exit('Erro: Caminho inválido ou não pôde ser decodificado.');
}

if (preg_match('#^\\\\{1,2}srv-rpfilho\\\\intranet#i', $caminho_recebido)) {
    $caminho_recebido = preg_replace('#^\\\\{1,2}srv-rpfilho\\\\intranet#i', 'I:', $caminho_recebido);
    debug("Convertido UNC para I:: $caminho_recebido");
}

debug("Caminho base64 decodificado: $caminho_recebido");

// === 2. NORMALIZA CAMINHO ===
$caminho_normalizado = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $caminho_recebido);
$caminho_normalizado = preg_replace('#' . preg_quote(DIRECTORY_SEPARATOR) . '+#', DIRECTORY_SEPARATOR, $caminho_normalizado);
debug("Caminho normalizado: $caminho_normalizado");

// === 3. CONVERTE MAPEAMENTOS DE UNIDADES PARA UNC ===
$mapeamentos = [
    'I:' => '\\\\srv-rpfilho\\intranet',
    'S:' => '\\\\srv-rpfilho\\intranet\\15- CIC',
];

foreach ($mapeamentos as $letra => $unc) {
    if (str_starts_with($caminho_normalizado, $letra)) {
        $caminho_normalizado = str_replace($letra, $unc, $caminho_normalizado);
        debug("Convertido $letra para UNC: $caminho_normalizado");
        break;
    }
}

// === 4. VERIFICA SE O ARQUIVO EXISTE ===
if (!file_exists($caminho_normalizado)) {
    http_response_code(404);
    debug("Arquivo não encontrado: $caminho_normalizado");
    exit("Erro: Arquivo não encontrado.<br><code>" . htmlspecialchars($caminho_normalizado) . "</code>");
}

// === 5. VERIFICA SE É LEGÍVEL ===
if (!is_readable($caminho_normalizado)) {
    http_response_code(403);
    debug("Arquivo não legível: $caminho_normalizado");
    exit("Erro: Arquivo não tem permissões de leitura.<br><code>" . htmlspecialchars($caminho_normalizado) . "</code>");
}

// === 6. DETECTA MIME E INFORMAÇÕES DO ARQUIVO ===
$filename = basename($caminho_normalizado);
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

$mime = match ($extension) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'pdf' => 'application/pdf',
    default => mime_content_type($caminho_normalizado),
};

debug("MIME detectado: $mime");
debug("Tamanho do arquivo: " . filesize($caminho_normalizado));

// === 7. LIMPA BUFFER E ENVIA O ARQUIVO ===
if (ob_get_level()) {
    ob_end_clean();
}

header("Content-Type: $mime");
header("Content-Disposition: inline; filename=\"" . $filename . "\"");
header("Content-Length: " . filesize($caminho_normalizado));
header("Cache-Control: public, max-age=86400");
header("Pragma: public");

// === 8. ENVIA CONTEÚDO DO ARQUIVO ===
$sucesso = readfile($caminho_normalizado);

if ($sucesso === false) {
    http_response_code(500);
    debug("Erro ao ler o arquivo com readfile.");
    exit("Erro ao carregar o arquivo.");
}

exit;
