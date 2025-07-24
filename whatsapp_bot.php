<?php
// whatsapp_bot.php
require __DIR__ . '/vendor/autoload.php';

use Twilio\TwiML\MessagingResponse;

// --- CONFIGURAÇÃO ---
// Substitua com suas credenciais do Twilio
$twilioAccountSid = getenv('TWILIO_ACCOUNT_SID') ?: 'SEU_ACCOUNT_SID_AQUI';
$twilioAuthToken = getenv('TWILIO_AUTH_TOKEN') ?: 'SEU_AUTH_TOKEN_AQUI';

// URL base da sua API do Todoist
// Quando usar ngrok, será algo como: 'http://<hash>.ngrok.io/todoist.php?api=true'
$todoistApiUrl = getenv('TODOIST_API_URL') ?: 'https://28c2b6c448b5.ngrok-free.app/api/todoist.php?api=true'; 

// --- LISTA DE PERMISSÃO (WHITELIST) ---
$allowedNumbers = [
    'whatsapp:+5522974029231',
    'whatsapp:+5522999281818',
    'whatsapp:+5522991013760',
];
// --- FIM DA CONFIGURAÇÃO ---


// Recebe a mensagem do WhatsApp enviada pelo Twilio
$from = $_POST['From'];
$body = trim($_POST['Body']);

// --- VERIFICAÇÃO DE SEGURANÇA ---
if (!in_array($from, $allowedNumbers)) {
    // Se o número não estiver na lista, não faz nada.
    // Isso impede que qualquer pessoa use seu bot.
    http_response_code(200); // Responde com 200 OK para a Twilio não registrar erro.
    exit();
}

// Prepara a resposta
$response = new MessagingResponse();

// Analisa a mensagem do usuário
list($command, $argument) = explode(' ', strtolower($body), 2) + [null, null];
$command = strtolower($command);

// --- LÓGICA DO BOT ---
try {
    switch ($command) {
        case 'projetos':
            $projects = call_todoist_api('GET_PROJECTS');
            $message = "📂 *Seus projetos:*\n\n";
            if (empty($projects)) {
                $message .= "_Nenhum projeto encontrado._";
            } else {
                foreach ($projects as $project) {
                    $message .= "`{$project['id']}`: {$project['name']}\n";
                }
            }
            $response->message($message);
            break;

        case 'listar':
        case 'ver':
            $tasks = call_todoist_api('GET');
            $message = "📝 *Suas tarefas:*\n\n";
            if (empty($tasks)) {
                $message .= "_Nenhuma tarefa encontrada._";
            } else {
                foreach ($tasks as $task) {
                    $status = $task['is_completed'] ? '✅' : '⏳';
                    $message .= "{$status} `{$task['id']}`: {$task['content']}\n";
                }
            }
            $response->message($message);
            break;

        case 'criar':
        case 'adicionar':
            if (empty($argument)) {
                throw new Exception("Para criar uma tarefa, use o formato:\n`criar <projeto> | <nome da tarefa>`");
            }

            // Mapeia os nomes dos projetos (em minúsculas) para os IDs
            $projectMap = [
                'inbox' => '2357282452',
                'servicos' => '2357294269',
                'devops' => '2357294302',
                'manutencao' => '2357294331'
            ];

            // Analisa o argumento para separar projeto e tarefa
            $parts = explode('|', $argument, 2);
            if (count($parts) < 2) {
                throw new Exception("Formato inválido. Use: `criar <projeto> | <tarefa>`\n\n*Projetos disponíveis:*\n`servicos`, `devops`, `manutencao`, `inbox`");
            }

            $projectName = strtolower(trim($parts[0]));
            $taskContent = trim($parts[1]);

            if (empty($taskContent)) {
                throw new Exception("O conteúdo da tarefa não pode ser vazio.");
            }

            // Encontra o ID do projeto no mapa
            if (!isset($projectMap[$projectName])) {
                throw new Exception("Projeto '{$projectName}' não encontrado.\n\n*Projetos disponíveis:*\n`servicos`, `devops`, `manutencao`, `inbox`");
            }
            $projectId = $projectMap[$projectName];

            // Prepara os dados para a API
            $data = [
                'content' => $taskContent,
                'project_id' => $projectId
            ];
            
            $newTask = call_todoist_api('POST', $data);
            $response->message("✅ Tarefa criada com sucesso no projeto *{$projectName}*!\n\n`{$newTask['id']}`: {$newTask['content']}");
            
            // Dispara a notificação
            $notificationMessage = "Nova tarefa criada por {$from}:\n\n*Projeto:* {$projectName}\n*Tarefa:* {$taskContent}";
            send_notification($notificationMessage);
            break;

        case 'deletar':
        case 'apagar':
             if (empty($argument)) {
                throw new Exception("Para deletar, envie: `deletar <ID da tarefa>` ou `deletar tudo`.");
            }
            if ($argument === 'tudo') {
                $result = call_todoist_api('DELETE', null, '&action=delete_all');
                $response->message("🗑️ Processo de exclusão em massa concluído. {$result['deleted_count']} tarefas foram deletadas.");
                
                // Dispara a notificação
                $notificationMessage = "{$result['deleted_count']} tarefas foram deletadas por {$from}.";
                send_notification($notificationMessage);
            } else {
                call_todoist_api('DELETE', null, '&id=' . urlencode($argument));
                $response->message("🗑️ Tarefa `{$argument}` deletada com sucesso.");

                // Dispara a notificação
                $notificationMessage = "A tarefa `{$argument}` foi deletada por {$from}.";
                send_notification($notificationMessage);
            }
            break;
        
        case 'atualizar':
        case 'editar':
            list($id, $newContent) = explode(' ', $argument, 2) + [null, null];
             if (empty($id) || empty($newContent)) {
                throw new Exception("Para atualizar, envie: `atualizar <ID> <novo conteúdo>`");
            }
            $updatedTask = call_todoist_api('POST', ['content' => $newContent], '&id=' . urlencode($id));
            $response->message("✏️ Tarefa `{$id}` atualizada para: {$updatedTask['content']}");

            // Dispara a notificação
            $notificationMessage = "A tarefa `{$id}` foi atualizada por {$from} para:\n*Novo conteúdo:* {$newContent}";
            send_notification($notificationMessage);
            break;
            
        case 'ajuda':
        case 'comandos':
            $response->message(get_help_message());
            break;

        default:
             $response->message("🤔 Comando não reconhecido. Envie `ajuda` para ver a lista de comandos.");
            break;
    }
} catch (Exception $e) {
    $response->message("❌ *Erro:* " . $e->getMessage());
}

// Envia a resposta de volta para o WhatsApp
header('Content-Type: text/xml');
echo $response;


/**
 * Função auxiliar para chamar a API do Todoist.
 */
function call_todoist_api($method, $data = null, $queryParams = '') {
    global $todoistApiUrl;
    
    $isDirectApiCall = ($method === 'GET_PROJECTS');
    
    if ($isDirectApiCall) {
        $baseUrl = 'https://api.todoist.com/rest/v2/';
        $endpoint = 'projects';
        $url = $baseUrl . $endpoint;
    } else {
        $url = $todoistApiUrl . $queryParams;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = [];

    if ($isDirectApiCall) {
        // Chamada direta para a API do Todoist, requer autenticação
        define('TODOIST_TOKEN', '610c013f1e84f8404060e50669e4364d5750dcf5'); // Token do todoist.php
        $headers[] = 'Authorization: Bearer ' . TODOIST_TOKEN;
        $method = 'GET'; // A requisição real é GET
    }

    if ($method === 'POST' && $data) {
        $headers[] = 'Content-Type: application/json';
    }

    switch ($method) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception("Erro de conexão com a API: " . $curlError);
    }

    if ($httpCode >= 400) {
        $errorData = json_decode($apiResponse, true);
        $errorMessage = isset($errorData['error']) ? $errorData['error'] : 'Ocorreu um erro na API.';
        throw new Exception($errorMessage . " (Código: {$httpCode})");
    }
    
    if ($httpCode === 204) { // No content, para DELETE
        return ['status' => 'success'];
    }

    return json_decode($apiResponse, true);
}

/**
 * Envia uma notificação para todos os números autorizados.
 * ATENÇÃO: Esta função requer um número Twilio comprado e Mensagens de Modelo aprovadas.
 */
function send_notification($messageBody) {
    global $twilioAccountSid, $twilioAuthToken, $allowedNumbers;

    // --- CONFIGURAÇÃO DE NOTIFICAÇÃO ---
    // Substitua pelo seu número Twilio comprado (não o do Sandbox)
    $twilioFromNumber = 'whatsapp:+14155238886'; // <-- SUBSTITUA PELO SEU NÚMERO TWILIO REAL
    
    // ATENÇÃO: Para enviar notificações, você precisa usar um contentSid (ID do Modelo de Mensagem)
    // que você criou e foi aprovado no console da Twilio.
    // O 'body' será usado como fallback ou para canais que não são WhatsApp.
    $contentSid = null; // <-- COLOQUE O ID DO SEU MODELO APROVADO AQUI

    $client = new Twilio\Rest\Client($twilioAccountSid, $twilioAuthToken);

    foreach ($allowedNumbers as $toNumber) {
        try {
            $messageData = [
                'from' => $twilioFromNumber,
                'body' => $messageBody // Fallback
            ];

            if ($contentSid) {
                $messageData['contentSid'] = $contentSid;
                // Se o seu modelo tiver variáveis, você precisará preenchê-las aqui.
                // Ex: 'contentVariables' => json_encode(['1' => 'valor1', '2' => 'valor2'])
            }

            $client->messages->create($toNumber, $messageData);

        } catch (Exception $e) {
            // Em um cenário real, você faria um log deste erro
            // error_log("Falha ao enviar notificação para {$toNumber}: " . $e->getMessage());
        }
    }
}

/**
 * Retorna a mensagem de ajuda com os comandos.
 */
function get_help_message() {
    return "*Comandos disponíveis:*\n\n" .
           "📂 `projetos` - Lista todos os seus projetos e seus IDs.\n" .
           "📖 `listar` - Mostra todas as suas tarefas.\n" .
           "➕ `criar <projeto> | <tarefa>` - Adiciona uma tarefa a um projeto (ex: `criar devops | configurar novo servidor`).\n" .
           "🔄 `atualizar <ID> <novo conteúdo>` - Edita uma tarefa existente.\n" .
           "🗑️ `deletar <ID>` - Apaga uma tarefa específica.\n" .
           "💣 `deletar tudo` - Apaga TODAS as suas tarefas.\n" .
           "❓ `ajuda` - Mostra esta mensagem.";
} 