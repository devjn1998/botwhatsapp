<?php

// servicos.php

// Configurações das constantes token e API_URL
define('TODOIST_API_URL', 'https://api.todoist.com/rest/v2/tasks');
define('TODOIST_HEADERS', [
    'Authorization: Bearer 610c013f1e84f8404060e50669e4364d5750dcf5', // <-- SEU TOKEN DO TODOIST
    'Content-Type: application/json'
]);
// ID do projeto 
define('PROJECT_ID', '2357294269'); 


$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? $_GET['id'] : null;
$action = isset($_GET['action']) ? $_GET['action'] : null;

// Requisições

if ($method === 'POST') {
    // Cria ou atualiza
    $data = json_decode(file_get_contents('php://input'), true);

    if ($id) { // Atualiza
        $url = TODOIST_API_URL . '/' . $id;
        
        if (empty($data)) { // Se o quando eu enviar uma requisição POST e o corpo da requisição estiver vazio ele gera um erro
            http_response_code(400);
            echo json_encode(['error' => 'E necessario fornecer dados para atualizar o servico.']);
            exit();
        }

    } else { // Se não ter erros ele cria
        $url = TODOIST_API_URL;

        if (empty($data) || !isset($data['content'])) {
            http_response_code(400);
            echo json_encode(['error' => 'O conteudo do servico e obrigatorio para criacao.']);
            exit();
        }
        // Adiciona o ID do projeto específico deste endpoint automaticamente
        $data['project_id'] = PROJECT_ID;
        // Opcional: Se 'parent_id' for enviado, a tarefa será uma subtarefa
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    header('Content-Type: application/json');
    http_response_code($http_code);
    echo $response;
    exit();

} elseif ($method === 'GET') {
    // Get
    if ($id) { // Se tiver um ID Ele lista 1 tarefa do projeto
        $url = TODOIST_API_URL . '/' . $id;
    } else { // Se não ele lê todos
        $url = TODOIST_API_URL . '?project_id=' . PROJECT_ID;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, TODOIST_HEADERS);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    header('Content-Type: application/json');
    http_response_code($http_code);
    echo $response;
    exit();

} elseif ($method === 'DELETE') {
    // Delete
    if ($action === 'delete_all') { // Deleta todos as tarefas do projeto
        // Busca todas as tarefas do projeto para pegar os IDs
        $get_url = TODOIST_API_URL . '?project_id=' . PROJECT_ID;
        $ch_get = curl_init($get_url);
        curl_setopt($ch_get, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_get, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
        $tasks_json = curl_exec($ch_get);
        curl_close($ch_get);

        $tasks = json_decode($tasks_json, true);
        $deleted_count = 0;
        $errors = [];

        // 2. Itera sobre esses IDS e deleta
        foreach ($tasks as $task) {
            $delete_url = TODOIST_API_URL . '/' . $task['id'];
            $ch_delete = curl_init($delete_url);
            curl_setopt($ch_delete, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch_delete, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
            curl_setopt($ch_delete, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch_delete);
            $http_code_delete = curl_getinfo($ch_delete, CURLINFO_HTTP_CODE);
            curl_close($ch_delete);

            if ($http_code_delete === 204) $deleted_count++;
            else $errors[] = ['id' => $task['id'], 'status' => $http_code_delete];
        }

        http_response_code(200); // retorna 200 "Ok"
        echo json_encode(['message' => 'Processo de exclusao em massa concluido.', 'deleted_count' => $deleted_count, 'errors' => $errors]);
        exit();

    } elseif ($id) { // Se mencionar o ID ele deleta essa tarefa específica
        $url = TODOIST_API_URL . '/' . $id;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        http_response_code($http_code);
        exit();

    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Para deletar, forneca um ID ou use ?action=delete_all']);
        exit();
    }
} else {
    // Trata o erro caso não for um método permitido
    http_response_code(405);
    echo json_encode(['error' => 'Metodo nao permitido. Use GET, POST ou DELETE.']);
    exit();
}

?> 