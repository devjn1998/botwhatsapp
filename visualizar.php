<?php

// === CONFIGURAÇÃO ===
// Substitua por seus valores reais
define('ENCRYPTION_KEY', 'sua-chave-secreta-de-32-bytes-aqui'); // 32 bytes exatos
define('ENCRYPTION_IV', '23216-bytes-aqui');                    // 16 bytes exatos

// === Função de Descriptografia ===
function decryptDataProxy(string $token): string|false {
    $decoded_token = base64_decode($token);
    return openssl_decrypt($decoded_token, 'AES-256-CBC', ENCRYPTION_KEY, 0, ENCRYPTION_IV);
}

// === Início do fluxo ===
if (isset($_GET['token'])) {
    $token = $_GET['token'];

    if (!$token) {
        http_response_code(400);
        die("Token de acesso não fornecido.");
    }

    $url_real = decryptDataProxy($token);

    if ($url_real === false) {
        http_response_code(403);
        die("Token inválido ou expirado.");
    }

    // === Busca conteúdo com cURL ===
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url_real);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $file_content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($http_code !== 200) {
        http_response_code(502); // Bad Gateway
        die("Não foi possível buscar o arquivo de origem. → " . htmlspecialchars($url_real));
    }

    // === Define extensão com base no Content-Type ===
    $mime_to_ext = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    $extension = $mime_to_ext[$content_type] ?? 'bin';
    $nome_arquivo_generico = 'arquivo.' . $extension;

    // Exibir inline imagens/pdfs, baixar o resto
    $disposition = in_array($content_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'])
        ? 'inline'
        : 'attachment';

    header('Content-Type: ' . ($content_type ?: 'application/octet-stream'));
    header("Content-Disposition: $disposition; filename=\"$nome_arquivo_generico\"");
    header('Pragma: public');
    header('Cache-Control: public');
    header('Content-Transfer-Encoding: binary');

    $fp = fopen('php://output', 'wb');
    fwrite($fp, $file_content);
    fclose($fp);
    exit;
}

// Nenhum token fornecido
http_response_code(400);
echo "Token ausente ou inválido.";
exit;
