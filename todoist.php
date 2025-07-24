<?php

// Configurações das constantes token e API_URL
define('TODOIST_API_URL', 'https://api.todoist.com/rest/v2/tasks');
define('TODOIST_HEADERS', [
    'Authorization: Bearer 610c013f1e84f8404060e50669e4364d5750dcf5', // <-- COLOQUE SEU TOKEN REAL AQUI
    'Content-Type: application/json'
]);

// Roteador de API: Verifica se a requisição é para a API ou para a página HTML
if (isset($_GET['api'])) {
    handle_api_request();
} else {
    render_html_page();
}

function handle_api_request() {
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            create_or_update_task();
            break;
        case 'GET':
            get_tasks();
            break;
        case 'DELETE':
            delete_task();
            break;
        default:
            send_response(405, ['error' => 'Metodo nao permitido.']);
            break;
    }
}

function send_response($status_code, $data = null, $headers = []) {
    header('Content-Type: application/json');
    foreach ($headers as $header) {
        header($header);
    }
    http_response_code($status_code);
    if ($data) {
        echo json_encode($data);
    }
    exit();
}

function get_tasks() {
    $ch = curl_init(TODOIST_API_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, TODOIST_HEADERS);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    send_response($http_code, json_decode($response));
}
 
function create_or_update_task() {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($_GET['id']) && !empty($_GET['id'])) { // Update
        $task_id = $_GET['id'];
        $url = TODOIST_API_URL . '/' . $task_id;

        if (empty($data)) {
            send_response(400, ['error' => 'E necessario fornecer dados para atualizar a tarefa.']);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        http_response_code($http_code);
        echo $response; // A API do Todoist já retorna JSON, então passamos direto

    } else { // Create
        if (empty($data) || !isset($data['content'])) {
            send_response(400, ['error' => 'O conteudo da tarefa e obrigatorio para criacao.']);
        }

        $ch = curl_init(TODOIST_API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        http_response_code($http_code);
        echo $response;
    }
    exit();
}


function delete_task() {
    if (isset($_GET['action']) && $_GET['action'] === 'delete_all') {
        // 1. Busca todas as tarefas para pegar os IDs
        $ch_get = curl_init(TODOIST_API_URL);
        curl_setopt($ch_get, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_get, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
        $tasks_json = curl_exec($ch_get);
        $http_code_get = curl_getinfo($ch_get, CURLINFO_HTTP_CODE);
        curl_close($ch_get);

        if ($http_code_get !== 200) {
            send_response(500, ['error' => 'Falha ao buscar tarefas para exclusao.', 'details' => json_decode($tasks_json)]);
        }

        $tasks = json_decode($tasks_json, true);
        $deleted_count = 0;
        $errors = [];

        foreach ($tasks as $task) {
            $task_id = $task['id'];
            $url = TODOIST_API_URL . '/' . $task_id;
            $ch_delete = curl_init($url);
            curl_setopt($ch_delete, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_delete, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch_delete, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
            curl_exec($ch_delete);
            $http_code_delete = curl_getinfo($ch_delete, CURLINFO_HTTP_CODE);
            curl_close($ch_delete);

            if ($http_code_delete === 204) { $deleted_count++; } 
            else { $errors[] = ['id' => $task_id, 'status' => $http_code_delete]; }
        }
        send_response(200, ['message' => 'Processo de exclusao em massa concluido.', 'deleted_count' => $deleted_count, 'errors' => $errors]);

    } elseif (isset($_GET['id']) && !empty($_GET['id'])) { // Deleta por ID
        $task_id = $_GET['id'];
        $url = TODOIST_API_URL . '/' . $task_id;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, TODOIST_HEADERS);
        curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        send_response($http_code);
    } else {
        send_response(400, ['error' => 'Para deletar, forneca um ID de tarefa ou use ?action=delete_all para deletar todas.']);
    }
}

function render_html_page() {
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cliente Todoist</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f4; color: #333; line-height: 1.6; padding: 20px; }
        .container { max-width: 700px; margin: auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #d34336; text-align: center; }
        ul { list-style-type: none; padding: 0; }
        li { padding: 12px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        li:last-child { border-bottom: none; }
        .task-content { cursor: pointer; }
        .task-content.completed { text-decoration: line-through; color: #aaa; }
        .actions button { background: none; border: none; cursor: pointer; padding: 5px; font-size: 16px; }
        .actions .edit-btn { color: #555; }
        .actions .delete-btn { color: #d34336; }
        form { display: flex; margin-bottom: 20px; }
        input[type="text"] { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        button[type="submit"] { padding: 10px 15px; background-color: #d34336; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 10px; }
        button[type="submit"]:hover { background-color: #b73227; }
        .delete-all-btn { display: block; width: 100%; padding: 10px; background-color: #555; color: white; border: none; border-radius: 4px; cursor: pointer; margin-top: 20px; }
        .delete-all-btn:hover { background-color: #444; }
        .feedback { padding: 12px; margin-bottom: 15px; border-radius: 4px; text-align: center; display: none; }
        .feedback.success { background-color: #d4edda; color: #155724; }
        .feedback.error { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

<div class="container">
    <h1>Minhas Tarefas (Todoist)</h1>
    <div id="feedback" class="feedback"></div>

    <form id="task-form">
        <input type="hidden" id="task-id" value="">
        <input type="text" id="task-content" placeholder="Adicionar nova tarefa..." required>
        <button type="submit">Adicionar</button>
    </form>

    <ul id="task-list"></ul>

    <button id="delete-all" class="delete-all-btn">Deletar Todas as Tarefas</button>
</div>

<script>
    const API_URL = 'todoist.php?api=true';
    const taskList = document.getElementById('task-list');
    const taskForm = document.getElementById('task-form');
    const taskContentInput = document.getElementById('task-content');
    const taskIdInput = document.getElementById('task-id');
    const submitButton = taskForm.querySelector('button[type="submit"]');
    const deleteAllButton = document.getElementById('delete-all');
    const feedbackDiv = document.getElementById('feedback');

    function showFeedback(message, isError = false) {
        feedbackDiv.textContent = message;
        feedbackDiv.className = `feedback ${isError ? 'error' : 'success'}`;
        feedbackDiv.style.display = 'block';
        setTimeout(() => {
            feedbackDiv.style.display = 'none';
        }, 3000);
    }

    async function fetchTasks() {
        try {
            const response = await fetch(API_URL);
            if (!response.ok) throw new Error('Falha ao buscar tarefas.');
            const tasks = await response.json();
            renderTasks(tasks);
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    function renderTasks(tasks) {
        taskList.innerHTML = '';
        if (tasks.length === 0) {
            taskList.innerHTML = '<li>Nenhuma tarefa encontrada.</li>';
            return;
        }
        tasks.forEach(task => {
            const li = document.createElement('li');
            li.dataset.id = task.id;

            const content = document.createElement('span');
            content.className = 'task-content';
            content.textContent = task.content;
            if (task.is_completed) {
                content.classList.add('completed');
            }
            content.onclick = () => toggleTaskCompletion(task.id, !task.is_completed);

            const actions = document.createElement('div');
            actions.className = 'actions';
            
            const editBtn = document.createElement('button');
            editBtn.className = 'edit-btn';
            editBtn.innerHTML = '✏️';
            editBtn.onclick = () => setupEdit(task);

            const deleteBtn = document.createElement('button');
            deleteBtn.className = 'delete-btn';
            deleteBtn.innerHTML = '🗑️';
            deleteBtn.onclick = () => deleteTask(task.id);

            actions.appendChild(editBtn);
            actions.appendChild(deleteBtn);
            li.appendChild(content);
            li.appendChild(actions);
            taskList.appendChild(li);
        });
    }
    
    taskForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const content = taskContentInput.value.trim();
        const id = taskIdInput.value;
        if (!content) return;

        const url = id ? `${API_URL}&id=${id}` : API_URL;
        const method = 'POST';

        try {
            const response = await fetch(url, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content: content })
            });

            if (!response.ok && response.status !== 200) { // 200 para update, 201 para create
                 const errorData = await response.json();
                 throw new Error(errorData.error || 'Falha ao salvar a tarefa.');
            }
            
            showFeedback(`Tarefa ${id ? 'atualizada' : 'criada'} com sucesso!`);
            resetForm();
            fetchTasks();
        } catch (error) {
            showFeedback(error.message, true);
        }
    });

    function setupEdit(task) {
        taskIdInput.value = task.id;
        taskContentInput.value = task.content;
        submitButton.textContent = 'Atualizar';
        taskContentInput.focus();
    }

    function resetForm() {
        taskIdInput.value = '';
        taskContentInput.value = '';
        submitButton.textContent = 'Adicionar';
    }

    async function deleteTask(id) {
        if (!confirm('Tem certeza que deseja deletar esta tarefa?')) return;
        try {
            const response = await fetch(`${API_URL}&id=${id}`, { method: 'DELETE' });
            if (response.status !== 204) throw new Error('Falha ao deletar tarefa.');
            showFeedback('Tarefa deletada com sucesso!');
            fetchTasks();
        } catch (error) {
            showFeedback(error.message, true);
        }
    }
    
    async function toggleTaskCompletion(id, is_completed) {
         try {
            const response = await fetch(`${API_URL}&id=${id}/close`, {
                method: 'POST'
            });

            if (response.status !== 204) {
                 throw new Error('Falha ao atualizar o status da tarefa.');
            }
            
            const action = is_completed ? 'fechada' : 'reaberta';
            showFeedback(`Tarefa ${action} com sucesso!`);
            fetchTasks();
        } catch (error) {
            showFeedback(error.message, true);
        }
    }

    deleteAllButton.addEventListener('click', async () => {
        if (!confirm('ATENÇÃO: Isso deletará TODAS as tarefas. Deseja continuar?')) return;
        try {
            const response = await fetch(`${API_URL}&action=delete_all`, { method: 'DELETE' });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Falha na exclusão em massa.');
            showFeedback(`${result.deleted_count} tarefa(s) deletada(s).`, result.errors.length > 0);
            fetchTasks();
        } catch (error) {
            showFeedback(error.message, true);
        }
    });

    // Carrega as tarefas ao iniciar a página
    document.addEventListener('DOMContentLoaded', fetchTasks);
</script>

</body>
</html>
<?php
}
// FIM DO ARQUIVO
?>