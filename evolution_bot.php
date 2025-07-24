<?php
// evolution_bot.php

// --- CONFIGURAÇÃO ---
// URL da sua instância da Evolution API rodando no Render
$evolutionApiUrl = 'https://sua-evolution-api.onrender.com'; // <-- SUBSTITUA PELA SUA URL REAL
$evolutionApiKey = 'suasenhasecreta123'; // <-- SUBSTITUA PELA API KEY QUE VOCÊ CRIOU

// URL da sua API do Todoist (pode ser a mesma URL do Render, se estiver no mesmo serviço)
$todoistApiUrl = 'https://sua-evolution-api.onrender.com/todoist.php?api=true';
// --- FIM DA CONFIGURAÇÃO ---


// Recebe o corpo da requisição (webhook) da Evolution API
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Verifica se é uma mensagem válida que devemos processar
if (!isset($data['message']['text']['message']) || isset($data['message']['fromMe'])) {
    http_response_code(200); // Ignora mensagens de sistema ou as que o próprio bot envia
    exit();
}

$from = $data['key']['remoteJid']; // Número do remetente (ex: 5521999998888@s.whatsapp.net)
$body = trim($data['message']['text']['message']);
$senderName = $data['pushName'] ?? 'Usuário';

// Analisa a mensagem do usuário
list($command, $argument) = explode(' ', strtolower($body), 2) + [null, null];
$command = strtolower($command);

try {
    // A LÓGICA DO SWITCH-CASE (listar, criar, deletar, etc.) VEM AQUI
    // Ela permanece quase idêntica à do whatsapp_bot.php, mas em vez de
    // $response->message(...), ela chamará a nossa nova função sendEvolutionMessage(...).

    switch ($command) {
        case 'listar':
            $tasks = call_todoist_api('GET');
            $message = "📝 *Suas tarefas, {$senderName}:*\n\n";
            if (empty($tasks)) {
                $message .= "_Nenhuma tarefa encontrada._";
            } else {
                foreach ($tasks as $task) {
                    $status = $task['is_completed'] ? '✅' : '⏳';
                    $message .= "{$status} `{$task['id']}`: {$task['content']}\n";
                }
            }
            sendEvolutionMessage($from, $message);
            break;

        case 'criar':
            // ... (Lógica de criação de tarefa adaptada para chamar sendEvolutionMessage)
            // ... e assim por diante para todos os outros comandos.
            sendEvolutionMessage($from, "Comando '{$command}' executado com sucesso!");
            break;

        // ... outros casos
        
        default:
            sendEvolutionMessage($from, "🤔 Comando não reconhecido, {$senderName}. Envie `ajuda` para ver a lista.");
            break;
    }

} catch (Exception $e) {
    sendEvolutionMessage($from, "❌ *Erro:* " . $e->getMessage());
}

// Responde 200 OK para o webhook da Evolution API saber que recebemos a mensagem.
http_response_code(200);


/**
 * Envia uma mensagem de texto usando a Evolution API.
 */
function sendEvolutionMessage($recipient, $text) {
    global $evolutionApiUrl, $evolutionApiKey;

    $url = $evolutionApiUrl . '/message/sendText';

    $payload = json_encode([
        'number' => $recipient,
        'options' => ['delay' => 1200],
        'textMessage' => ['text' => $text]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $evolutionApiKey
    ]);

    curl_exec($ch);
    // Em um cenário real, você adicionaria tratamento de erro aqui.
    curl_close($ch);
}


// A função call_todoist_api() e get_help_message() permanecem as mesmas
// do arquivo whatsapp_bot.php e devem ser copiadas para cá.

?> 