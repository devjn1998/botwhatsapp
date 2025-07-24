<?php

// Configuração do log de erros para adaptar na conversão das URLs
ini_set('error_log', __DIR__ . '/erro_visualizador1.log');

// Configuração do CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-API-KEY");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class Certificado
{
    private PDO $pdo;
    private const ENCRYPTION_KEY = 'sua-chave-secreta-de-32-bytes-aqui';
    private const ENCRYPTION_IV = '23216-bytes-aqui';

    public function __construct()
    {
        $this->pdo = require_once '../painel/config/firebird.php';
    }

    // Utilitário de criptografia
    private function encryptData(string $string): string
    {
        $encrypted = openssl_encrypt($string, 'AES-256-CBC', self::ENCRYPTION_KEY, 0, self::ENCRYPTION_IV);
        return base64_encode($encrypted);
    }

    // Utilitário para converter arrays para UTF-8
    private function converterArrayParaUtf8(array $dados): array
    {
        foreach ($dados as &$linha) {
            foreach ($linha as $chave => $valor) {
                if (is_string($valor)) {
                    $linha[$chave] = mb_convert_encoding($valor, 'UTF-8', 'WINDOWS-1251');
                }
            }
        }
        return $dados;
    }

    // Utilitário para verificar método HTTP
    private function verificarMetodo(string $metodo)
    {
        if ($_SERVER['REQUEST_METHOD'] !== strtoupper($metodo)) {
            $this->responderComErro('Método não permitido.', 405);
        }
    }

    // Utilitário para resposta de sucesso
    private function responderComSucesso(array $dados, int $codigo = 200)
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($dados);
        exit;
    }

    // Utilitário para resposta de erro
    public function responderComErro(string $mensagem, int $codigo, ?Exception $e = null)
    {
        // LOGA O ERRO REAL NO SERVIDOR!
        if ($e !== null) {
            // A função error_log() envia a mensagem para o log de erros do Apache/PHP
            error_log("API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }

        http_response_code($codigo);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['erro' => $mensagem]);
        exit;
    }

    // Utilitário para corrigir caracteres encoding errado
    private function corrigirCaracteresEncodingErrado(string $texto): string {
        $mapa = [
            'Р—Р“O' => 'ÇÃO', // Pode ser 'ÇÃO' ou 'ÇAO'
            'РЈ' => 'Ó', // Pode ser 'Ó' ou 'O'
            'Р“O' => 'ÃO',
            'Р™' => 'É',
            'Й' => 'É', // Pode ser 'É' ou 'E'
            'љ' => 'Ê', // Pode ser 'Ê' ou 'E'
            'К' => 'C', // comum em CONTРКINER → CONTAINER
            'Н' => 'N', // Pode ser 'N' ou 'N'
            'М' => 'M', // Pode ser 'M' ou 'M'
            'Л' => 'L', // Pode ser 'L' ou 'L'
            'Т' => 'T', // Pode ser 'T' ou 'T'
            'Ѓ' => 'Ô', // Pode ser 'Ô' ou 'O'
            'Љ' => 'Ú', // Pode ser 'Ú' ou 'U'
            'Њ' => 'Ü', // Pode ser 'Ü' ou 'U'
            // adicione mais conforme encontrar
        ];

        return strtr($texto, $mapa);
    }

    // Utilitário para corrigir detalhes do equipamento
    private function corrigirDetalhe(array $detalhe): array {
        foreach ($detalhe as $chave => $valor) {
            if (is_string($valor)) {
                $detalhe[$chave] = $this->corrigirCaracteresEncodingErrado($valor);
            }
        }
        return $detalhe;
    }

    // Utilitário para gerar URL do produto
    private function gerarUrlDoProduto(string $caminho): string
    {
        error_log("ANTES: " . $caminho);
        $caminho = $this->corrigirCaracteresEncodingErrado($caminho);
        error_log("DEPOIS: " . $caminho);
        $base64 = base64_encode($caminho);
        $link = "https://rpfilhomacae.dyndns.org/api/show.php?path=" . $base64;
        return $this->encryptData($link);
    }

    // EQUIPAMENTOS

    // URL para estatísticas de equipamentos a vencer
    public function estatisticasEquipamentosaVencer() {
        $this->verificarMetodo('GET');
        $this->buscarPorStatusDocumento('a_vencer');
    }

    // URL para estatísticas de equipamentos vencidos
    public function estatisticasEquipamentosVencidos() {
        $this->verificarMetodo('GET');
        $this->buscarPorStatusDocumento('vencidos');
    }

    // URL para listar todos os equipamentos
    public function getAllProdutos()
    {
        $this->verificarMetodo('GET');
        try {
            $query = $this->pdo->query('
                SELECT
                    E.COD_EQUIP,
                    E.COD_EQUIP_FABRIC,
                    E.DESCRICAO_1,
                    COUNT(C.COD_CERT) AS QUANTIDADE_DOC
                FROM EQUIPAMENTOS E
                LEFT JOIN CERTIFICADOS C ON C.COD_PROD = E.COD_EQUIP
                WHERE TRIM(E.status) IN (\'P\',\'A\',\'L\')
                GROUP BY E.COD_EQUIP, E.COD_EQUIP_FABRIC, E.DESCRICAO_1
                ORDER BY E.COD_EQUIP DESC
            ');
            $result = $query->fetchAll(PDO::FETCH_ASSOC);
            $dados = $this->converterArrayParaUtf8($result);

            if (!$dados) {
                $this->responderComErro('Nenhum produto encontrado.', 404);
            }

            $this->responderComSucesso($dados);

        } catch (Exception $e) {
            $this->responderComErro('Erro ao obter os produtos: ' . $e->getMessage(), 404);
        }
    }

    private function buscarPorStatus($status)
    {
        $sql = '
            SELECT
                E.COD_EQUIP,
                E.COD_EQUIP_FABRIC,
                E.DESCRICAO_1,
                COUNT(C.COD_CERT) AS QUANTIDADE_DOC
            FROM EQUIPAMENTOS E
            LEFT JOIN CERTIFICADOS C ON C.COD_PROD = E.COD_EQUIP
            WHERE TRIM(E.status) = ?
            GROUP BY E.COD_EQUIP, E.COD_EQUIP_FABRIC, E.DESCRICAO_1
            ORDER BY E.COD_EQUIP DESC
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$status]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dados = $this->converterArrayParaUtf8($dados);
        return $this->responderComSucesso($dados);
    }

    private function buscarPorStatusDocumento($status)
    {
        if ($status == "a_vencer") {
            $sql = '
                SELECT
                    E.COD_EQUIP,
                    E.COD_EQUIP_FABRIC,
                    E.DESCRICAO_1,
                    COUNT(C.COD_CERT) AS QUANTIDADE_DOC
                FROM EQUIPAMENTOS E
                LEFT JOIN CERTIFICADOS C ON C.COD_PROD = E.COD_EQUIP
                WHERE TRIM(status) IN (\'P\',\'A\',\'L\')
                AND (C.VALIDADE IS NOT NULL AND C.VALIDADE >= CURRENT_DATE AND C.VALIDADE < DATEADD(30 DAY TO CURRENT_DATE))
                GROUP BY E.COD_EQUIP, E.COD_EQUIP_FABRIC, E.DESCRICAO_1
                ORDER BY E.COD_EQUIP DESC
            ';
        } else {
            $sql = '
                SELECT
                    E.COD_EQUIP,
                    E.COD_EQUIP_FABRIC,
                    E.DESCRICAO_1,
                    COUNT(C.COD_CERT) AS QUANTIDADE_DOC
                FROM EQUIPAMENTOS E
                LEFT JOIN CERTIFICADOS C ON C.COD_PROD = E.COD_EQUIP
                WHERE TRIM(status) IN (\'P\',\'A\',\'L\')
                AND (C.VALIDADE IS NOT NULL AND C.VALIDADE < CURRENT_DATE)
                GROUP BY E.COD_EQUIP, E.COD_EQUIP_FABRIC, E.DESCRICAO_1
                ORDER BY E.COD_EQUIP DESC
            ';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $dados = $this->converterArrayParaUtf8($dados);
        return $this->responderComSucesso($dados);
    }

    // Lista todos com status 'P'
    public function listarParalisados()
    {
        return $this->buscarPorStatus('P');
    }

    // Lista todos com status 'A'
    public function listarAlocados()
    {
        return $this->buscarPorStatus('A');
    }

    // Lista todos com status 'L'
    public function listarLivres()
    {
        return $this->buscarPorStatus('L');
    }

    public function listarEquipamentoPorId(int $codigoEquipamento) {
        $this->verificarMetodo('GET');
        try {
            $stmt = $this->pdo->prepare('SELECT COD_EQUIP_FABRIC, DESCRICAO_1, DESCRICAO_2, ESTOQUE, OBS, DT_CADASTRO, DT_ALTERACAO FROM EQUIPAMENTOS WHERE COD_EQUIP = ?');
            $stmt->execute([$codigoEquipamento]);
            $equipamento = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$equipamento) {
                $this->responderComErro('Equipamento não encontrado.', 404);
            }
            $equipamento = $this->converterArrayParaUtf8([$equipamento])[0];
            $equipamento = $this->corrigirDetalhe($equipamento);

            $certificados = $this->acessarCertificadosProduto($codigoEquipamento);
            $dados = [
                'codigo_equipamento' => $codigoEquipamento,
                'codigo_equipamento_fabricante' => $equipamento['COD_EQUIP_FABRIC'],
                'descricao_1' => $equipamento['DESCRICAO_1'],
                'descricao_2' => $equipamento['DESCRICAO_2'],
                'estoque' => $equipamento['ESTOQUE'],
                'observacao' => $equipamento['OBS'],
                'data_cadastro' => $equipamento['DT_CADASTRO'],
                'data_alteracao' => $equipamento['DT_ALTERACAO'],
                'certificados' => $certificados
            ];
            return $this->responderComSucesso($dados);


        } catch (Exception $e) {
            $this->responderComErro('Erro ao obter o equipamento: ' . $e->getMessage(), 404);
        }
    }

    public function autenticar(string $cpfOUcnpj)
    {

        $isCpf = false;
        $isCnpj = false;

        $this->verificarMetodo('GET');
        $docNumerico = preg_replace('/\D/', '', $cpfOUcnpj);

        if (strlen($docNumerico) === 9) {
            $isCpf = true;
            // Base de CPF (9 dígitos)
            $sql = '
                    SELECT "Cod_Cli", "Nome_Comp", "Cpf"
                    FROM "Clientes"
                    WHERE REPLACE(REPLACE(REPLACE(REPLACE("Cpf", \'.\', \'\'), \'-\', \'\'), \'/\', \'\'), \' \', \'\') LIKE ?
                ';
            $param = $docNumerico . '%';
        } elseif (strlen($docNumerico) === 12) {
            $isCnpj = true;
            // Base de CNPJ (12 dígitos)
            $sql = '
                    SELECT "Cod_Cli", "Nome_Comp", "Cnpj"
                    FROM "Clientes"
                    WHERE REPLACE(REPLACE(REPLACE(REPLACE("Cnpj", \'.\', \'\'), \'/\', \'\'), \'-\', \'\'), \' \', \'\') LIKE ?
                ';
            $param = $docNumerico . '%';
        } else {
            $this->responderComErro('Base de CPF ou CNPJ inválida.', 400);
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$param]);
            $cliente = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cliente = $this->converterArrayParaUtf8($cliente)[0];
            $cliente = $this->corrigirDetalhe($cliente);

            if ($cliente) {
                if ($isCpf === true) {
                    return $this->responderComSucesso([
                    'codigo_cliente' => $cliente['Cod_Cli'],
                    'empresa' => $cliente['Nome_Comp'],
                    'doc' => $cliente['Cpf']
                ]);
                } else {
                    return $this->responderComSucesso([
                        'codigo_cliente' => $cliente['Cod_Cli'],
                        'empresa' => $cliente['Nome_Comp'],
                        'doc' => $cliente['Cnpj']
                    ]);
                }
            } else {
                $this->responderComErro('Cliente não encontrado.', 404);
            }
        } catch (PDOException $e) {
            $this->responderComErro('Erro ao consultar o banco de dados.', 500, $e);
        }
    }

    public function estatisticas()
    {
        $this->verificarMetodo('GET');

        try {
            // 1. Buscar locações ativas
            $stmt = $this->pdo->prepare('SELECT "COD_LOC" FROM "LOCACOES" WHERE TRIM("STATUS") NOT IN (\'C\', \'N\')');
            $stmt->execute();
            $locacoesAtivas = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $totalOsAtivas = count($locacoesAtivas);

            if (empty($locacoesAtivas)) {
                return $this->responderComSucesso([
                    'os_ativas' => 0,
                    'total_certificados' => 0,
                    'total_certificados_validos' => 0,
                    'total_certificados_com_validade' => 0,
                    'certificados_vencidos' => 0,
                    'certificados_a_vencer' => 0,
                    'certificados_sem_validade' => 0
                ]);
            }

            // 2. Buscar os equipamentos das locações
            $codEquipamentos = [];
            $chunksLoc = array_chunk($locacoesAtivas, 1000); // Melhor usar 1000 por segurança
            foreach ($chunksLoc as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $query = 'SELECT DISTINCT "COD_EQUIP" FROM "LIN_LOC" WHERE "COD_LOC" IN (' . $placeholders . ')';
                $stmt = $this->pdo->prepare($query);
                $stmt->execute($chunk);
                $codEquipamentos = array_merge($codEquipamentos, $stmt->fetchAll(PDO::FETCH_COLUMN));
            }

            $codEquipamentos = array_unique($codEquipamentos);

            // 3. Contadores
            $totalCertificadosAlocados = 0;
            $totalCertificadosComValidade = 0;
            $certificadosVencidos = 0;
            $certificadosAVencer = 0;
            $totalCertificadosValidos = 0;
            $totalCertificadosSemValidade = 0;

            // 4. Buscar estatísticas de certificados por chunk
            $chunksEquip = array_chunk($codEquipamentos, 1000); // Mesma lógica
            foreach ($chunksEquip as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $sql = '
                    SELECT
                        COUNT(*) AS total_certificados,
                        SUM(CASE WHEN "VALIDADE" IS NOT NULL THEN 1 ELSE 0 END) AS total_com_validade,
                        SUM(CASE WHEN "VALIDADE" < CURRENT_DATE THEN 1 ELSE 0 END) AS vencidos,
                        SUM(CASE WHEN "VALIDADE" >= CURRENT_DATE AND "VALIDADE" < DATEADD(30 DAY TO CURRENT_DATE) THEN 1 ELSE 0 END) AS a_vencer,
                        SUM(CASE WHEN "VALIDADE" >= DATEADD(30 DAY TO CURRENT_DATE) THEN 1 ELSE 0 END) AS validos,
                        SUM(CASE WHEN "VALIDADE" IS NULL THEN 1 ELSE 0 END) AS sem_validade
                    FROM "CERTIFICADOS"
                    WHERE "COD_PROD" IN (' . $placeholders . ')
                ';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($chunk);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                $totalCertificadosAlocados     += (int)($result['TOTAL_CERTIFICADOS'] ?? 0);
                $totalCertificadosComValidade  += (int)($result['TOTAL_COM_VALIDADE'] ?? 0);
                $certificadosVencidos          += (int)($result['VENCIDOS'] ?? 0);
                $certificadosAVencer           += (int)($result['A_VENCER'] ?? 0);
                $totalCertificadosValidos      += (int)($result['VALIDOS'] ?? 0);
                $totalCertificadosSemValidade  += (int)($result['SEM_VALIDADE'] ?? 0);
            }

            // 5. Retorno final
            return $this->responderComSucesso([
                'os_ativas' => $totalOsAtivas,
                'total_certificados' => $totalCertificadosAlocados,
                'total_certificados_validos' => $totalCertificadosValidos,
                'total_certificados_com_validade' => $totalCertificadosComValidade,
                'certificados_vencidos' => $certificadosVencidos,
                'certificados_a_vencer' => $certificadosAVencer,
                'certificados_sem_validade' => $totalCertificadosSemValidade
            ]);
        } catch (PDOException $e) {
            $this->responderComErro('Erro ao consultar o banco de dados.', 500, $e);
        }
    }

    public function estatisticasEquipamentos()
    {
        $this->verificarMetodo('GET');
        try {
            // Consulta: Resumo dos equipamentos (por status)
            $stmt = $this->pdo->prepare("
                SELECT
                    COUNT(*) AS total_equipamentos,
                    COUNT(CASE WHEN TRIM(status) = 'P' THEN 1 END) AS total_paralisados,
                    COUNT(CASE WHEN TRIM(status) = 'A' THEN 1 END) AS total_alocados,
                    COUNT(CASE WHEN TRIM(status) = 'L' THEN 1 END) AS total_livres
                FROM equipamentos
                WHERE TRIM(status) IN ('P','A','L')
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            // Consulta: Estatísticas dos certificados/datas válidas
            $stmt2 = $this->pdo->prepare("
                SELECT
                    COUNT(C.COD_CERT) AS total_documentos,
                    COUNT(CASE WHEN (C.VALIDADE IS NOT NULL AND C.VALIDADE >= CURRENT_DATE AND C.VALIDADE < DATEADD(30 DAY TO CURRENT_DATE)) THEN 1 END) AS total_documentos_a_vencer,
                    COUNT(CASE WHEN (C.VALIDADE IS NOT NULL AND C.VALIDADE < CURRENT_DATE) THEN 1 END) AS total_documentos_vencidos,
                    COUNT(CASE WHEN (C.VALIDADE IS NOT NULL AND C.VALIDADE >= CURRENT_DATE) THEN 1 END) AS total_documentos_validos
                FROM EQUIPAMENTOS E
                INNER JOIN CERTIFICADOS C ON C.COD_PROD = E.COD_EQUIP
                WHERE TRIM(E.status) IN ('P','A','L')
            ");
            $stmt2->execute();
            $result2 = $stmt2->fetch(PDO::FETCH_ASSOC);

            $this->responderComSucesso([
                'total_equipamentos' => $result['TOTAL_EQUIPAMENTOS'] ?? 0,
                'total_paralisados' => $result['TOTAL_PARALISADOS'] ?? 0,
                'total_alocados'    => $result['TOTAL_ALOCADOS'] ?? 0,
                'total_livres'      => $result['TOTAL_LIVRES'] ?? 0,

                // Certificados só dos equipamentos associados!
                'total_documentos'                => $result2['TOTAL_DOCUMENTOS'] ?? 0,
                'total_documentos_a_vencer' => $result2['TOTAL_DOCUMENTOS_A_VENCER'] ?? 0,
                'total_documentos_validos'        => $result2['TOTAL_DOCUMENTOS_VALIDOS'] ?? 0,
                'total_documentos_vencidos'       => $result2['TOTAL_DOCUMENTOS_VENCIDOS'] ?? 0,
            ]);
        } catch (PDOException $e) {
            $this->responderComErro('Erro ao consultar o banco de dados.', 500, $e);
        }
    }

    // corrigido
    public function listarVencidos()
    {
        return $this->listarEquipamentosPorFiltroCertificados('vencidos');
    }

    // corrigido
    public function listarAVencer()
    {
        return $this->listarEquipamentosPorFiltroCertificados('a_vencer');
    }

    // corrigido
    public function listarTudo()
    {
        $this->verificarMetodo('GET');
        try {
            // 1. Buscar locações com documento do cliente em JOIN
            $stmt = $this->pdo->prepare('
                SELECT
                    l."COD_LOC",
                    l."COD_CLI",
                    l."LOCACAO",
                    l."NOME_RED",
                    l."DT_CONTRATO",
                    CASE
                        WHEN c."Cpf" IS NOT NULL AND TRIM(c."Cpf") <> \'\' THEN c."Cpf"
                        ELSE c."Cnpj"
                    END AS "Documento"
                FROM "LOCACOES" l
                LEFT JOIN "Clientes" c ON c."Cod_Cli" = l."COD_CLI"
                WHERE TRIM(l."STATUS") NOT IN (\'C\', \'N\')
                ORDER BY l."DT_CONTRATO" DESC
            ');
            $stmt->execute();
            $locacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $locacoes = $this->converterArrayParaUtf8($locacoes);

            if (!$locacoes) {
                $this->responderComErro('Nenhuma locação encontrada.', 404);
            }

            // 2. Buscar todos os equipamentos por locação
            $todosEquipamentos = $this->buscarTodosEquipamentosPorLocacoes(array_column($locacoes, 'COD_LOC'));

            // 3. Buscar estatísticas de certificados em lote
            $todosCodigosEquipamentos = [];
            foreach ($todosEquipamentos as $equipamentos) {
                $todosCodigosEquipamentos = array_merge(
                    $todosCodigosEquipamentos,
                    array_column($equipamentos, 'codigo_equipamento')
                );
            }
            $todosCodigosEquipamentos = array_unique($todosCodigosEquipamentos);


            $estatisticasCertificados = $this->buscarEstatisticasCertificadosEmLote($todosCodigosEquipamentos);

            // 4. Montar resposta
            $dados = [];
            $debugTotais = ['avencer' => 0, 'vencidos' => 0, 'validos' => 0];
            foreach ($locacoes as $locacao) {
                $equipamentos = $todosEquipamentos[$locacao['COD_LOC']] ?? [];
                $equipamentosCount = count($equipamentos);

                $avencer = 0;
                $vencidos = 0;
                $com_validade = 0;

                foreach ($equipamentos as $equipamento) {
                    $codEquip = $equipamento['codigo_equipamento'];
                    if (isset($estatisticasCertificados[$codEquip])) {
                        $avencer += $estatisticasCertificados[$codEquip]['total_avencer'];
                        $vencidos += $estatisticasCertificados[$codEquip]['total_vencido'];
                        $com_validade += $estatisticasCertificados[$codEquip]['total_valido'];
                        $com_validade += $estatisticasCertificados[$codEquip]['total_sem_validade'];
                        $com_validade += $estatisticasCertificados[$codEquip]['total_vencido'];
                    }
                }

                $debugTotais['avencer'] += $avencer;
                $debugTotais['vencidos'] += $vencidos;
                $debugTotais['validos'] += $com_validade;

                $dados[] = [
                    'codigo_cliente' => $locacao['COD_CLI'],
                    'codigo_locacao' => $locacao['COD_LOC'],
                    'codigo_os_locacao' => $locacao['LOCACAO'],
                    'nome_reduzido' => $locacao['NOME_RED'],
                    'data_contrato' => $locacao['DT_CONTRATO'],
                    'cpf_ou_cnpj' => $locacao['Documento'] ?? '',
                    'equipamentos_alugados' => $equipamentosCount,
                    'certificados_a_vencer' => $avencer,
                    'certificados_vencidos' => $vencidos,
                    'certificados_com_validade' => $com_validade
                ];
            }

            error_log("TOTAIS FINAIS CALCULADOS:");
            error_log("- A vencer: " . $debugTotais['avencer']);
            error_log("- Vencidos: " . $debugTotais['vencidos']);
            error_log("- Válidos: " . $debugTotais['validos']);

            $this->responderComSucesso($dados);

        } catch (PDOException $e) {
            $this->responderComErro('Erro ao consultar o banco de dados.', 500, $e);
        }
    }

    // corrigido
    public function listarLocacoesCliente(int $codigoCliente)
    {
        $this->verificarMetodo('GET');
        try {
            // 1. Buscar locações do cliente
            $stmt = $this->pdo->prepare('
                SELECT "COD_LOC", "LOCACAO"
                FROM "LOCACOES"
                WHERE "COD_CLI" = ? AND TRIM("STATUS") NOT IN (\'C\', \'N\')
            ');
            $stmt->execute([$codigoCliente]);
            $locacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$locacoes) {
                $this->responderComErro('Nenhuma locação encontrada para este cliente.', 404);
                return;
            }

            $codigosLocacoes = array_column($locacoes, 'COD_LOC');

            // 2. Buscar todos equipamentos dessas locações de uma vez
            $todosEquipamentos = $this->buscarTodosEquipamentosPorLocacoes($codigosLocacoes);

            // 3. Preparar lista de todos equipamentos para buscar estatísticas em lote
            $todosEquipamentosId = [];
            foreach ($todosEquipamentos as $equipamentos) {
                $todosEquipamentosId = array_merge($todosEquipamentosId, array_column($equipamentos, 'codigo_equipamento'));
            }
            $todosEquipamentosId = array_unique($todosEquipamentosId);

            // 4. Buscar estatísticas em lote para todos equipamentos
            $estatisticasPorEquipamento = $this->buscarEstatisticasCertificadosEmLote($todosEquipamentosId);

            // 5. Montar dados por locação
            $dados = [];
            foreach ($locacoes as $locacao) {
                $equipamentos = $todosEquipamentos[$locacao['COD_LOC']] ?? [];
                $doc_a_vencer = 0;
                $doc_vencidos = 0;
                $doc_validos = 0;

                foreach ($equipamentos as $equipamento) {
                    $codEquip = $equipamento['codigo_equipamento'];
                    if (isset($estatisticasPorEquipamento[$codEquip])) {
                        $doc_a_vencer += $estatisticasPorEquipamento[$codEquip]['total_avencer'] ?? 0;
                        $doc_vencidos += $estatisticasPorEquipamento[$codEquip]['total_vencido'] ?? 0;
                        $doc_validos += $estatisticasPorEquipamento[$codEquip]['total_valido'] ?? 0;
                    }
                }

                $dados[] = [
                    'codigo_locacao' => $locacao['COD_LOC'],
                    'codigo_os_locacao' => $locacao['LOCACAO'],
                    'equipamentos_alugados' => count($equipamentos),
                    'certificados_a_vencer' => $doc_a_vencer,
                    'certificados_vencidos' => $doc_vencidos,
                    'certificados_com_validade' => $doc_validos
                ];
            }

            $this->responderComSucesso($dados);
        } catch (PDOException $e) {
            $this->responderComErro('Erro ao consultar o banco de dados.', 500, $e);
        }
    }

    // corrigido
    public function listarEquipamentosClientePorLocacao(int $codigoLocacao, int $codigoCliente)
    {
        $this->verificarMetodo('GET');
        try {
            // Buscar equipamentos da locação
            $equipamentos = $this->listarEquipamentosPorLocacaoInterno($codigoLocacao);
            if (!$equipamentos) {
                $this->responderComErro('Nenhum equipamento encontrado para esta locação.', 404);
                return;
            }

            // Buscar dados do cliente
            $stmt_cnpj = $this->pdo->prepare('
                SELECT "Nome_Comp", CASE WHEN "Cpf" IS NOT NULL AND TRIM("Cpf") <> \'\' THEN "Cpf" ELSE "Cnpj" END AS "Documento"
                FROM "Clientes"
                WHERE "Cod_Cli" = ?
            ');
            $stmt_cnpj->execute([$codigoCliente]);
            $cliente = $stmt_cnpj->fetch(PDO::FETCH_ASSOC);
            $cliente = $this->converterArrayParaUtf8([$cliente])[0] ?? [];

            // Preparar lista de códigos de equipamentos para buscar certificados em lote
            $codigosEquipamentos = array_column($equipamentos, 'codigo_equipamento');

            // Buscar certificados em lote para todos equipamentos
            $certificadosPorEquipamento = $this->buscarCertificadosEmLote($codigosEquipamentos);

            $dados = [];
            foreach ($equipamentos as $equipamento) {
                $certificados = $certificadosPorEquipamento[$equipamento['codigo_equipamento']] ?? [];

                $dados[] = [
                    'nome_cliente' => $cliente['Nome_Comp'] ?? '',
                    'cpf_ou_cnpj' => $cliente['Documento'] ?? '',
                    'codigo_equipamento' => $equipamento['codigo_equipamento'],
                    'numero_item' => $equipamento['numero_item'],
                    'descricao' => $equipamento['descricao'],
                    'certificados' => $certificados
                ];
            }

            $this->responderComSucesso($dados);
        } catch (PDOException $e) {
            $this->responderComErro('Erro ao consultar o banco de dados.', 500, $e);
        }
    }

    // corrigido
    public function listarEquipamentosPorLocacao(int $codigoLocacao)
    {
        $this->verificarMetodo('GET');
        try {
            $equipamentos = $this->listarEquipamentosPorLocacaoInterno($codigoLocacao);
            if (!$equipamentos) {
                $this->responderComErro('Nenhum equipamento encontrado para esta locação.', 404);
                return;
            }

            // Preparar lista de códigos de equipamentos
            $codigosEquipamentos = array_column($equipamentos, 'codigo_equipamento');

            // Buscar certificados em lote para esses equipamentos
            $certificadosPorEquipamento = $this->buscarCertificadosEmLote($codigosEquipamentos);

            $dados = [];
            foreach ($equipamentos as $equipamento) {
                $certificados = $certificadosPorEquipamento[$equipamento['codigo_equipamento']] ?? [];

                $dados[] = [
                    'codigo_equipamento' => $equipamento['codigo_equipamento'],
                    'numero_item' => $equipamento['numero_item'],
                    'descricao' => $equipamento['descricao'],
                    'certificados' => $certificados
                ];
            }

            $this->responderComSucesso($dados);
        } catch (PDOException $e) {
            $this->responderComErro('Erro ao consultar o banco de dados.', 500, $e);
        }
    }

    // Métodos auxiliares internos

    // corrigido
    private function buscarCertificadosEmLote(array $codigosEquipamentos): array
    {
        if (empty($codigosEquipamentos)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codigosEquipamentos), '?'));

        $sql = '
            SELECT "COD_CERT", "COD_PROD", "DT_ALTERACAO", "CAMINHO", "NOME", "VALIDADE"
            FROM "CERTIFICADOS"
            WHERE "COD_PROD" IN (' . $placeholders . ')
            ORDER BY "COD_PROD"
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($codigosEquipamentos);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = $this->converterArrayParaUtf8($result);

        $certificadosPorEquipamento = [];
        foreach ($result as $certificado) {
            $caminhoConvertido = mb_convert_encoding($certificado['CAMINHO'] ?? '', 'UTF-8', 'Windows-1251');
            $token = $this->gerarUrlDoProduto($caminhoConvertido); // Gerar token como antes

            $certificadosPorEquipamento[$certificado['COD_PROD']][] = [
                'codigo_certificado' => $certificado['COD_CERT'],
                'data_alteracao' => $certificado['DT_ALTERACAO'],
                'token_visualizacao' => $token,
                'nome' => $certificado['NOME'],
                'validade' => $certificado['VALIDADE'],
            ];
        }

        return $certificadosPorEquipamento;
    }


    // corrigido
    private function buscarEquipamentosComCertificadosAVencer(array $codigosEquipamentos): array
    {
        if (empty($codigosEquipamentos)) return [];

        $equipamentosComCertificadosAVencer = [];
        $chunks = array_chunk($codigosEquipamentos, 1000);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $sql = '
                SELECT DISTINCT COD_PROD
                FROM CERTIFICADOS
                WHERE COD_PROD IN (' . $placeholders . ')
                AND VALIDADE IS NOT NULL
                AND VALIDADE >= CURRENT_DATE
                AND VALIDADE < DATEADD(30 DAY TO CURRENT_DATE)
            ';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($chunk);
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $equipamentosComCertificadosAVencer = array_merge($equipamentosComCertificadosAVencer, $results);
        }

        return $equipamentosComCertificadosAVencer;
    }

    // corrigido
    private function listarEquipamentosPorFiltroCertificados(string $tipoFiltro)
    {
        $this->verificarMetodo('GET');

        try {
            // Busca locações ativas
            $stmt = $this->pdo->prepare('
                SELECT "COD_LOC", "COD_CLI", "LOCACAO", "NOME_RED", "DT_CONTRATO"
                FROM "LOCACOES"
                WHERE TRIM("STATUS") NOT IN (\'C\', \'N\')
                ORDER BY "DT_CONTRATO" DESC
            ');

            // Busca CPF ou CNPJ do cliente
            $stmt_cnpj = $this->pdo->prepare('
                SELECT CASE
                    WHEN "Cpf" IS NOT NULL AND TRIM("Cpf") <> \'\' THEN "Cpf"
                    ELSE "Cnpj"
                END AS "Documento"
                FROM "Clientes"
                WHERE "Cod_Cli" = ?
            ');

            $stmt->execute();
            $locacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $locacoes = $this->converterArrayParaUtf8($locacoes);

            if (!$locacoes) $this->responderComErro('Nenhuma locação encontrada.', 404);

            // Busca equipamentos por locações
            $todosEquipamentos = $this->buscarTodosEquipamentosPorLocacoes(array_column($locacoes, 'COD_LOC'));

            // Junta todos os códigos de equipamentos
            $todosCodigosEquipamentos = [];
            foreach ($todosEquipamentos as $equipamentos) {
                $todosCodigosEquipamentos = array_merge($todosCodigosEquipamentos, array_column($equipamentos, 'codigo_equipamento'));
            }
            $todosCodigosEquipamentos = array_unique($todosCodigosEquipamentos);

            // Decide qual função chamar para buscar equipamentos filtrados
            if ($tipoFiltro === 'vencidos') {
                $equipamentosFiltrados = $this->buscarEquipamentosComCertificadosVencidos($todosCodigosEquipamentos);
            } elseif ($tipoFiltro === 'a_vencer') {
                $equipamentosFiltrados = $this->buscarEquipamentosComCertificadosAVencer($todosCodigosEquipamentos);
            } else {
                throw new Exception('Filtro inválido para certificados');
            }

            // Estatísticas para todos os equipamentos
            $estatisticasCertificados = $this->buscarEstatisticasCertificadosEmLote($todosCodigosEquipamentos);

            $dados = [];

            foreach ($locacoes as $locacao) {
                $equipamentos = $todosEquipamentos[$locacao['COD_LOC']] ?? [];

                // Verifica se algum equipamento tem certificado do tipo filtrado
                $temEquipamentoFiltrado = false;
                foreach ($equipamentos as $equipamento) {
                    if (in_array($equipamento['codigo_equipamento'], $equipamentosFiltrados, true)) {
                        $temEquipamentoFiltrado = true;
                        break;
                    }
                }

                if ($temEquipamentoFiltrado) {
                    $stmt_cnpj->execute([$locacao['COD_CLI']]);
                    $cliente = $stmt_cnpj->fetch(PDO::FETCH_ASSOC);

                    $avencer = 0;
                    $vencidos = 0;
                    $com_validade = 0;

                    foreach ($equipamentos as $equipamento) {
                        $codEquip = $equipamento['codigo_equipamento'];
                        if (isset($estatisticasCertificados[$codEquip])) {
                            $avencer += $estatisticasCertificados[$codEquip]['total_avencer'];
                            $vencidos += $estatisticasCertificados[$codEquip]['total_vencido'];
                            $com_validade += $estatisticasCertificados[$codEquip]['total_valido'];
                        }
                    }

                    $dados[] = [
                        'codigo_cliente' => $locacao['COD_CLI'],
                        'codigo_locacao' => $locacao['COD_LOC'],
                        'codigo_os_locacao' => $locacao['LOCACAO'],
                        'nome_reduzido' => $locacao['NOME_RED'],
                        'data_contrato' => $locacao['DT_CONTRATO'],
                        'cpf_ou_cnpj' => $cliente['Documento'] ?? '',
                        'equipamentos_alugados' => count($equipamentos),
                        'certificados_a_vencer' => $avencer,
                        'certificados_vencidos' => $vencidos,
                        'certificados_com_validade' => $com_validade
                    ];
                }
            }

            $this->responderComSucesso($dados);

        } catch (PDOException $e) {
            $this->responderComErro('Erro ao consultar o banco de dados.', 500, $e);
        }
    }


    // corrigido
    private function buscarEquipamentosComCertificadosVencidos(array $codigosEquipamentos): array
    {
        if (empty($codigosEquipamentos)) return [];

        $equipamentosComCertificadosVencidos = [];
        $chunks = array_chunk($codigosEquipamentos, 1000);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $sql = '
                SELECT DISTINCT COD_PROD
                FROM CERTIFICADOS
                WHERE COD_PROD IN (' . $placeholders . ')
                AND VALIDADE IS NOT NULL
                AND VALIDADE < CURRENT_DATE
            ';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($chunk);
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $equipamentosComCertificadosVencidos = array_merge($equipamentosComCertificadosVencidos, $results);
        }

        return $equipamentosComCertificadosVencidos;
    }

    // corrigido
    private function estatisticasPorEquipamento(int $codigoEquipamento): array {
        $sql = '
            SELECT
                COALESCE(SUM(CASE
                    WHEN VALIDADE >= CURRENT_DATE AND VALIDADE <= CURRENT_DATE + 30 THEN 1
                    ELSE 0
                END), 0) AS total_avencer,

                COALESCE(SUM(CASE
                    WHEN VALIDADE < CURRENT_DATE THEN 1
                    ELSE 0
                END), 0) AS total_vencido,

                COALESCE(SUM(CASE
                    WHEN VALIDADE IS NOT NULL THEN 1
                    ELSE 0
                END), 0) AS total_valido
            FROM CERTIFICADOS
            WHERE COD_PROD = ?
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$codigoEquipamento]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_avencer' => (int) ($result['TOTAL_AVENCER'] ?? 0),
            'total_vencido' => (int) ($result['TOTAL_VENCIDO'] ?? 0),
            'total_valido'  => (int) ($result['TOTAL_VALIDO'] ?? 0),
        ];
    }

    // corrigido
    private function listarEquipamentosPorLocacaoInterno(int $codigoLocacao): array
    {
        $stmt = $this->pdo->prepare('SELECT COD_EQUIP, NUM_ITEM, DESCRICAO_1 FROM LIN_LOC WHERE COD_LOC = ?');
        $stmt->execute([$codigoLocacao]);
        $equipamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $equipamentos = $this->converterArrayParaUtf8($equipamentos);

        if (!$equipamentos) return [];
        $dados = [];
        foreach ($equipamentos as $equipamento) {
            $dados[] = [
                'codigo_equipamento' => $equipamento['COD_EQUIP'],
                'numero_item' => $equipamento['NUM_ITEM'],
                'descricao' => $equipamento['DESCRICAO_1']
            ];
        }
        return $dados;
    }

    // corrigido!!
    private function acessarCertificadosProduto(int $codigoProduto): array
    {
        $stmt = $this->pdo->prepare('SELECT "COD_CERT", "DT_ALTERACAO", "CAMINHO", "NOME", "VALIDADE" FROM "CERTIFICADOS" WHERE "COD_PROD" = ?');
        $stmt->execute([$codigoProduto]);
        $certificados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $certificados = $this->converterArrayParaUtf8($certificados);

        $dados = [];
        foreach ($certificados as $certificado) {
            $caminho = $certificado['CAMINHO'] ?? '';
            // $caminhoConvertido = mb_convert_encoding($caminho, 'UTF-8', 'Windows-1251');
            $token = $this->gerarUrlDoProduto($caminho);

            $dados[] = [
                'codigo_certificado' => $certificado['COD_CERT'],
                'data_alteracao' => $certificado['DT_ALTERACAO'],
                'token_visualizacao' => $token,
                'nome' =>  $certificado['NOME'],
                'validade' => $certificado['VALIDADE']
            ];
        }
        return $dados;
    }


    // corrigido
    private function buscarTodosEquipamentosPorLocacoes(array $codigosLocacao): array
    {
        if (empty($codigosLocacao)) return [];

        $equipamentosPorLocacao = [];

        // Firebird (e outros) têm limite de ~1500 em listas IN, usamos chunks de 1000
        $chunks = array_chunk($codigosLocacao, 1000);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT COD_LOC, COD_EQUIP, NUM_ITEM, DESCRICAO_1 FROM LIN_LOC WHERE COD_LOC IN ($placeholders)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($chunk);

            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = $this->converterArrayParaUtf8($result);

            foreach ($result as $equipamento) {
                $codLoc = $equipamento['COD_LOC'];
                $equipamentosPorLocacao[$codLoc][] = [
                    'codigo_equipamento' => $equipamento['COD_EQUIP'],
                    'numero_item' => $equipamento['NUM_ITEM'],
                    'descricao' => $equipamento['DESCRICAO_1']
                ];
            }
        }

        return $equipamentosPorLocacao;
    }

    // corrigido
    private function buscarEstatisticasCertificadosEmLote(array $codigosEquipamentos): array
    {
        if (empty($codigosEquipamentos)) return [];

        $estatisticas = [];
        $chunks = array_chunk($codigosEquipamentos, 1000);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $sql = '
                SELECT
                    COD_PROD,
                    COUNT(*) AS total_certificados,
                    SUM(CASE
                        WHEN VALIDADE >= CURRENT_DATE AND VALIDADE <= CURRENT_DATE + 30 THEN 1
                        ELSE 0
                    END) AS total_avencer,
                    SUM(CASE
                        WHEN VALIDADE < CURRENT_DATE THEN 1
                        ELSE 0
                    END) AS total_vencido,
                    SUM(CASE
                        WHEN VALIDADE > CURRENT_DATE THEN 1
                        ELSE 0
                    END) AS total_valido,
                    SUM(CASE
                        WHEN VALIDADE IS NULL OR TRIM(VALIDADE) = \'\' THEN 1
                        ELSE 0
                    END) AS total_sem_validade
                FROM CERTIFICADOS
                WHERE COD_PROD IN (' . $placeholders . ')
                GROUP BY COD_PROD
            ';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($chunk);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $result) {
                $estatisticas[$result['COD_PROD']] = [
                    'total_certificados' => (int) ($result['TOTAL_CERTIFICADOS'] ?? 0),
                    'total_avencer' => (int) ($result['TOTAL_AVENCER'] ?? 0),
                    'total_vencido' => (int) ($result['TOTAL_VENCIDO'] ?? 0),
                    'total_valido'  => (int) ($result['TOTAL_VALIDO'] ?? 0),
                    'total_sem_validade' => (int) ($result['TOTAL_SEM_VALIDADE'] ?? 0),
                ];
            }
        }

        return $estatisticas;
    }

    private function debugCompararComLocacaoEspecifica($locacoes, $todosEquipamentos, $estatisticasCertificados): void
    {
        // Pegar uma locação de exemplo para comparar
        $locacaoExemplo = $locacoes[0];
        $codLocacao = $locacaoExemplo['COD_LOC'];

        error_log("=== COMPARAÇÃO LOCAÇÃO $codLocacao ===");

        // 1. Equipamentos desta locação
        $equipamentos = $todosEquipamentos[$codLocacao] ?? [];
        error_log("EQUIPAMENTOS NESTA LOCAÇÃO: " . count($equipamentos));

        $codigosEquipamentos = array_column($equipamentos, 'codigo_equipamento');
        error_log("CÓDIGOS DOS EQUIPAMENTOS: " . implode(', ', array_slice($codigosEquipamentos, 0, 10)));

        // 2. Buscar certificados APENAS desta locação (simulando busca individual)
        $certificadosIndividual = $this->buscarCertificadosLocacaoIndividual($codigosEquipamentos);

        // 3. Calcular totais da busca individual
        $totalsIndividual = ['avencer' => 0, 'vencidos' => 0, 'validos' => 0];
        foreach ($certificadosIndividual as $stats) {
            $totalsIndividual['avencer'] += $stats['total_avencer'];
            $totalsIndividual['vencidos'] += $stats['total_vencido'];
            $totalsIndividual['validos'] += $stats['total_valido'];
        }

        // 4. Calcular totais da busca em lote
        $totalsLote = ['avencer' => 0, 'vencidos' => 0, 'validos' => 0];
        foreach ($equipamentos as $equipamento) {
            $codEquip = $equipamento['codigo_equipamento'];
            if (isset($estatisticasCertificados[$codEquip])) {
                $totalsLote['avencer'] += $estatisticasCertificados[$codEquip]['total_avencer'];
                $totalsLote['vencidos'] += $estatisticasCertificados[$codEquip]['total_vencido'];
                $totalsLote['validos'] += $estatisticasCertificados[$codEquip]['total_valido'];
            }
        }

        error_log("TOTAIS BUSCA INDIVIDUAL:");
        error_log("- A vencer: " . $totalsIndividual['avencer']);
        error_log("- Vencidos: " . $totalsIndividual['vencidos']);
        error_log("- Válidos: " . $totalsIndividual['validos']);

        error_log("TOTAIS BUSCA EM LOTE:");
        error_log("- A vencer: " . $totalsLote['avencer']);
        error_log("- Vencidos: " . $totalsLote['vencidos']);
        error_log("- Válidos: " . $totalsLote['validos']);

        // 5. Verificar se há diferenças
        $diferencas = [
            'avencer' => $totalsLote['avencer'] - $totalsIndividual['avencer'],
            'vencidos' => $totalsLote['vencidos'] - $totalsIndividual['vencidos'],
            'validos' => $totalsLote['validos'] - $totalsIndividual['validos']
        ];

        error_log("DIFERENÇAS (Lote - Individual):");
        error_log("- A vencer: " . $diferencas['avencer']);
        error_log("- Vencidos: " . $diferencas['vencidos']);
        error_log("- Válidos: " . $diferencas['validos']);

        // 6. Verificar quais equipamentos têm certificados
        $equipamentosComCertificados = 0;
        $equipamentosSemCertificados = [];

        foreach ($equipamentos as $equipamento) {
            $codEquip = $equipamento['codigo_equipamento'];
            if (isset($estatisticasCertificados[$codEquip])) {
                $equipamentosComCertificados++;
            } else {
                $equipamentosSemCertificados[] = $codEquip;
            }
        }

        error_log("EQUIPAMENTOS COM CERTIFICADOS: $equipamentosComCertificados");
        error_log("EQUIPAMENTOS SEM CERTIFICADOS: " . count($equipamentosSemCertificados));
        error_log("EXEMPLOS SEM CERTIFICADOS: " . implode(', ', array_slice($equipamentosSemCertificados, 0, 10)));
    }

    private function buscarCertificadosLocacaoIndividual(array $codigosEquipamentos): array
    {
        if (empty($codigosEquipamentos)) return [];

        $estatisticas = [];

        // Usar chunks também para consistência
        $chunks = array_chunk($codigosEquipamentos, 1000);

        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));

            $sql = '
                SELECT
                    COD_PROD,
                    COUNT(*) AS total_certificados,
                    SUM(CASE
                        WHEN VALIDADE >= CURRENT_DATE AND VALIDADE <= CURRENT_DATE + 30 THEN 1
                        ELSE 0
                    END) AS total_avencer,
                    SUM(CASE
                        WHEN VALIDADE < CURRENT_DATE THEN 1
                        ELSE 0
                    END) AS total_vencido,
                    SUM(CASE
                        WHEN VALIDADE > CURRENT_DATE THEN 1
                        ELSE 0
                    END) AS total_valido
                FROM CERTIFICADOS
                WHERE COD_PROD IN (' . $placeholders . ')
                GROUP BY COD_PROD
            ';

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($chunk);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $result) {
                $estatisticas[$result['COD_PROD']] = [
                    'total_certificados' => (int) ($result['TOTAL_CERTIFICADOS'] ?? 0),
                    'total_avencer' => (int) ($result['TOTAL_AVENCER'] ?? 0),
                    'total_vencido' => (int) ($result['TOTAL_VENCIDO'] ?? 0),
                    'total_valido'  => (int) ($result['TOTAL_VALIDO'] ?? 0),
                ];
            }
        }

        return $estatisticas;
    }
}

// ======= ROTEAMENTO DINÂMICO =======

try {
    $action = $_GET['action'] ?? 'listarTudo';
    $codigoCliente = $_GET['codigoCliente'] ?? null;
    $cnpj = $_GET['cnpj'] ?? null;
    $codigoLocacao = $_GET['codigoLocacao'] ?? null;
    $codigoEquipamento = $_GET['codigoEquipamento'] ?? null;

    $api = new Certificado();

    if (!method_exists($api, $action)) {
        $api->responderComErro('Endpoint não encontrado.', 404);
    }

    if (!is_null($codigoCliente) && !is_null($codigoLocacao)) {
        $api->listarEquipamentosClientePorLocacao($codigoLocacao, $codigoCliente);
    } elseif (!is_null($codigoCliente)) {
        $api->$action($codigoCliente);
    } elseif (!is_null($codigoEquipamento)) {
        $api->$action($codigoEquipamento);
    } elseif (!is_null($cnpj)) {
        $api->$action($cnpj);
    } elseif (!is_null($codigoLocacao)) {
        $api->$action($codigoLocacao);
    } else {
        $api->$action();
    }
} catch (Throwable $t) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['erro' => 'Erro inesperado no servidor.', 'detalhe' => $t->getMessage()]);
}
