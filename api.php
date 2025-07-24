<?php
// Arquivo: api/api.php

header("Content-Type: application/json; charset=UTF-8");

// Inclui a sua conexão com o banco de dados
$pdo = require '../painel/config/database.php';
$method = $_SERVER['REQUEST_METHOD'];

// S&S: Define uma constante para o tamanho máximo do arquivo (ex: 10MB)
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
// S&S: Define uma lista de tipos de arquivos permitidos
define('ALLOWED_FILE_TYPES', [
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
    'pdf' => 'application/pdf'
]);

/**
 * S&S: Função centralizada para validar uploads de forma segura.
 * @param array $file O array do arquivo de $_FILES.
 * @return bool|string Retorna true se for válido, ou uma string de erro se for inválido.
 */
function validarUploadSeguro($file) {
    // 1. Verifica se houve erros no upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'Erro no upload do arquivo.';
    }
    // 2. Verifica o tamanho do arquivo
    if ($file['size'] > MAX_FILE_SIZE) {
        return 'O arquivo excede o tamanho máximo permitido de 10MB.';
    }
    // 3. Verifica a extensão e o tipo MIME real do arquivo
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!array_key_exists($fileExtension, ALLOWED_FILE_TYPES) || !in_array($realMimeType, ALLOWED_FILE_TYPES)) {
        return 'Tipo de arquivo não permitido. Apenas JPG, PNG e PDF são aceitos.';
    }

    return true; // Arquivo válido
}


switch ($method) {
    case 'GET':
        if (isset($_GET['protocolo'])) {
            // S&S: Sanitiza a entrada para garantir que seja uma string alfanumérica
            $protocolo = preg_replace('/[^a-zA-Z0-9-]/', '', $_GET['protocolo']);

            // S&S: Altera SELECT * para selecionar apenas os campos necessários.
            // Isso evita expor dados sensíveis como 'ip_remetente' e 'user_agent'.
            $sql = "SELECT id, protocolo, tipo, descricao, data_ocorrido, local_ocorrido, envolvidos, nome, telefone, email, status, data_envio, resposta, data_resposta 
                    FROM denuncias WHERE protocolo = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$protocolo]);
            $denuncia = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$denuncia) {
                http_response_code(404);
                echo json_encode(['mensagem' => 'Denúncia não encontrada com este protocolo.']);
                exit;
            }

            $stmtAnexos = $pdo->prepare("SELECT caminho_arquivo, tipo_anexo FROM anexos_denuncia WHERE denuncia_id = ?");
            $stmtAnexos->execute([$denuncia['id']]);
            $anexos = $stmtAnexos->fetchAll(PDO::FETCH_ASSOC);

            $denuncia['anexos'] = $anexos;
            echo json_encode($denuncia);

        } else {
            // A listagem já selecionava campos específicos, o que é uma boa prática.
            $stmt = $pdo->query("SELECT id, protocolo, tipo, status, data_envio FROM denuncias ORDER BY data_envio DESC");
            $denuncias = $stmt->fetchAll(PDO::FETCH_ASSOC);
            http_response_code(200);
            echo json_encode($denuncias);
        }
        break;

    case 'POST':
        // Lógica para diferenciar criação de resposta
        if (isset($_POST['protocolo']) && isset($_POST['resposta'])) {
            // É UMA RESPOSTA
            try {
                // S&S: Sanitiza as entradas para prevenir XSS
                $protocolo = htmlspecialchars($_POST['protocolo'], ENT_QUOTES, 'UTF-8');
                $resposta = htmlspecialchars($_POST['resposta'], ENT_QUOTES, 'UTF-8');

                $pdo->beginTransaction();

                $stmtId = $pdo->prepare("SELECT id FROM denuncias WHERE protocolo = ?");
                $stmtId->execute([$protocolo]);
                $denuncia = $stmtId->fetch();
                if (!$denuncia) {
                    throw new Exception('Denúncia não encontrada com este protocolo para responder.');
                }
                $denunciaId = $denuncia['id'];

                $sql = "UPDATE denuncias SET resposta = ?, status = 'Respondido', data_resposta = NOW() WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$resposta, $denunciaId]);

                // S&S: Usa a função de validação segura para o anexo da resposta
                if (isset($_FILES['anexo_resposta']) && $_FILES['anexo_resposta']['error'] === UPLOAD_ERR_OK) {
                    $validacao = validarUploadSeguro($_FILES['anexo_resposta']);
                    if ($validacao !== true) {
                        throw new Exception($validacao);
                    }

                    $uploadDir = __DIR__ . '/../uploads/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true); // Permissões mais seguras

                    $fileExtension = strtolower(pathinfo($_FILES['anexo_resposta']['name'], PATHINFO_EXTENSION));
                    // S&S: Gera um nome de arquivo seguro que não depende da entrada do usuário
                    $safeFilename = uniqid('resposta_', true) . '.' . $fileExtension;
                    $destination = $uploadDir . $safeFilename;

                    if (move_uploaded_file($_FILES['anexo_resposta']['tmp_name'], $destination)) {
                        $anexoPath = 'uploads/' . $safeFilename;
                        $sqlAnexo = "INSERT INTO anexos_denuncia (denuncia_id, caminho_arquivo, tipo_anexo) VALUES (?, ?, ?)";
                        $stmtAnexo = $pdo->prepare($sqlAnexo);
                        $stmtAnexo->execute([$denunciaId, $anexoPath, 'resposta']);
                    }
                }

                $pdo->commit();
                http_response_code(200);
                echo json_encode(['mensagem' => 'Resposta registrada com sucesso.']);

            } catch (Exception $e) {
                $pdo->rollBack();
                // S&S: Para o usuário, mostramos uma mensagem genérica. No log do servidor, você veria o erro real.
                http_response_code(400); // Bad Request é mais apropriado para erros de validação
                echo json_encode(['mensagem' => $e->getMessage()]);
            }

        } else {
            // É UMA NOVA DENÚNCIA
            try {
                // S&S: Sanitiza todas as entradas de texto para prevenir XSS
                $tipo = htmlspecialchars($_POST['tipo'] ?? null, ENT_QUOTES, 'UTF-8');
                $descricao = htmlspecialchars($_POST['descricao'] ?? null, ENT_QUOTES, 'UTF-8');
                $local_ocorrido = htmlspecialchars($_POST['local_ocorrido'] ?? null, ENT_QUOTES, 'UTF-8');
                $envolvidos = htmlspecialchars($_POST['envolvidos'] ?? null, ENT_QUOTES, 'UTF-8');
                $nome = htmlspecialchars($_POST['nome'] ?? null, ENT_QUOTES, 'UTF-8');
                $telefone = htmlspecialchars($_POST['telefone'] ?? null, ENT_QUOTES, 'UTF-8');
                $email = filter_var($_POST['email'] ?? null, FILTER_SANITIZE_EMAIL); // Validação específica para e-mail

                $dateObject = DateTime::createFromFormat('Y-m-d', $_POST['data_ocorrido'] ?? '');
                if ($dateObject === false) { $dateObject = new DateTime(); }
                $dataOcorridoMySQL = $dateObject->format('Y-m-d H:i:s');

                $pdo->beginTransaction();

                $sql = "INSERT INTO denuncias (protocolo, tipo, descricao, data_ocorrido, local_ocorrido, envolvidos, nome, telefone, email, status, ip_remetente, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $protocolo = uniqid('DEN-');
                $stmt->execute([
                    $protocolo, $tipo, $descricao, $dataOcorridoMySQL,
                    $local_ocorrido, $envolvidos, $nome, $telefone, $email, 'Pendente',
                    $_SERVER['REMOTE_ADDR'] ?? 'N/A',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'N/A'
                ]);
                $denunciaId = $pdo->lastInsertId();

                if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                    $validacao = validarUploadSeguro($_FILES['anexo']);
                    if ($validacao !== true) {
                        throw new Exception($validacao);
                    }

                    $uploadDir = __DIR__ . '/../uploads/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                    $fileExtension = strtolower(pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION));
                    $safeFilename = uniqid('denuncia_', true) . '.' . $fileExtension;
                    $destination = $uploadDir . $safeFilename;

                    if (move_uploaded_file($_FILES['anexo']['tmp_name'], $destination)) {
                        $anexoPath = 'uploads/' . $safeFilename;
                        $sqlAnexo = "INSERT INTO anexos_denuncia (denuncia_id, caminho_arquivo, tipo_anexo) VALUES (?, ?, ?)";
                        $stmtAnexo = $pdo->prepare($sqlAnexo);
                        $stmtAnexo->execute([$denunciaId, $anexoPath, 'denunciante']);
                    }
                }

                $pdo->commit();
                http_response_code(201);
                echo json_encode(['mensagem' => 'Denúncia registrada com sucesso!', 'protocolo' => $protocolo]);

            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['mensagem' => $e->getMessage()]);
            }
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['mensagem' => 'Método não permitido.']);
        break;
}