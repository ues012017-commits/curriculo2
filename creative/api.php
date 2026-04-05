<?php
// ================================================================
// KONEX API - VERSÃO FINAL COMPLETA
// ================================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

// CONFIG BANCO
define('DB_HOST', 'localhost');
define('DB_NAME', 'iubsit15_konex');
define('DB_USER', 'iubsit15_konexuser');
define('DB_PASS', '@Vanvan123');

// HEADERS E CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// RESPOSTA JSON
function jsonResponse($data){
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// CONEXÃO BANCO
try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e){
    http_response_code(500);
    jsonResponse(['status' => 'erro', 'msg' => 'Erro na conexão com o banco de dados']);
}

require_once __DIR__ . '/functions.php';

// VALIDAR TOKEN ADMIN (helper)
function validarTokenAdmin(PDO $pdo, string $token): bool {
    if (!$token) return false;
    try {
        $stmt = $pdo->prepare("SELECT token FROM admin_sessions WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

// PEGAR AÇÃO
$inputJSON = file_get_contents('php://input');
$request = json_decode($inputJSON, true) ?: $_POST;
$acao = $request['acao'] ?? $_GET['acao'] ?? '';

// ================================================================
// TESTE API
// ================================================================
if (!$acao){
    jsonResponse([
        'status' => 'ok',
        'msg' => 'API ONLINE',
        'banco' => 'conectado'
    ]);
}

// ================================================================
// CONFIG PUBLICA
// ================================================================
if ($acao === 'get_public_config'){
    $result = [
        'status' => 'sucesso',
        'msg' => 'API OK',
        'banco' => 'conectado'
    ];

    // Include public config values the frontend needs
    try {
        $publicKeys = ['loja_video_url', 'site_nome', 'whatsapp_suporte'];
        $placeholders = implode(',', array_fill(0, count($publicKeys), '?'));
        $stmt = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ($placeholders)");
        $stmt->execute($publicKeys);
        while ($row = $stmt->fetch()) {
            $result[$row['chave']] = $row['valor'];
        }
    } catch(Exception $e) {}

    jsonResponse($result);
}

// ================================================================
// CONFIG PUBLICA DA LOJA (planos, preços — sem token admin)
// ================================================================
if ($acao === 'get_store_config'){
    try {
        $publicKeys = [
            'nome_plano_basico','nome_plano_profissional','nome_plano_agencia','nome_plano_enterprise',
            'desc_plano_basico','desc_plano_profissional','desc_plano_agencia','desc_plano_enterprise',
            'valor_basico','valor_profissional','valor_agencia','valor_enterprise',
            'creditos_basico','creditos_profissional','creditos_agencia','creditos_enterprise',
            'site_nome','site_url','credito_gratis_ativo','credito_gratis_qtd'
        ];
        $placeholders = implode(',', array_fill(0, count($publicKeys), '?'));
        $stmt = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ($placeholders)");
        $stmt->execute($publicKeys);
        $configs = [];
        while ($row = $stmt->fetch()) {
            $configs[$row['chave']] = $row['valor'];
        }
        jsonResponse(['status' => 'sucesso', 'configs' => $configs]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar configurações da loja']);
    }
}

// ================================================================
// LOGIN ADMIN
// ================================================================
if ($acao === 'admin_login'){
    $senha = $request['senha'] ?? '';

    if ($senha === 'konex2026'){
        $token = bin2hex(random_bytes(32));

        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $exp = date('Y-m-d H:i:s', time() + 86400);

            $stmt = $pdo->prepare("INSERT INTO admin_sessions (token, admin_user, ip, expires_at) VALUES (?, 'admin', ?, ?)");
            $stmt->execute([$token, $ip, $exp]);
            registrarLog($pdo, 'info', 'admin_login', 'Login admin realizado', null, $ip);
        } catch(Exception $e){}

        jsonResponse([
            'status' => 'sucesso',
            'token' => $token
        ]);
    }

    jsonResponse([
        'status' => 'erro',
        'msg' => 'Senha incorreta'
    ]);
}

// ================================================================
// VALIDAR TOKEN ADMIN (endpoint)
// ================================================================
if ($acao === 'admin_check'){
    $token = $request['token'] ?? '';

    if (!$token){
        jsonResponse(['status'=>'erro']);
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM admin_sessions WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);

        if ($stmt->fetch()){
            jsonResponse(['status'=>'sucesso']);
        }
    } catch(Exception $e){}

    jsonResponse(['status'=>'erro']);
}

// ================================================================
// TOKEN GUARD — all admin_* endpoints below require valid token
// ================================================================
$adminActions = [
    'admin_stats','admin_logout','admin_usuarios','admin_add_usuario',
    'admin_edit_usuario','admin_delete_usuario','admin_toggle_usuario',
    'admin_add_creditos','admin_force_password','admin_pedidos',
    'admin_aprovar_pedido','admin_delete_pedido_individual','admin_limpar_pedidos',
    'admin_transacoes','admin_delete_transacao_individual','admin_limpar_transacoes',
    'admin_leads','admin_delete_lead_individual','admin_limpar_leads',
    'admin_get_configs','admin_save_configs','admin_financeiro',
    'admin_fraude_stats','admin_delete_ip_suspeito','admin_logs','admin_limpar_logs'
];

if (in_array($acao, $adminActions)) {
    $token = $request['token'] ?? '';
    if (!validarTokenAdmin($pdo, $token)) {
        jsonResponse(['status' => 'erro', 'msg' => 'Token inválido ou expirado']);
    }
}

// ================================================================
// ADMIN LOGOUT
// ================================================================
if ($acao === 'admin_logout'){
    try {
        $stmt = $pdo->prepare("DELETE FROM admin_sessions WHERE token = ?");
        $stmt->execute([$request['token']]);
    } catch(Exception $e){}

    jsonResponse(['status' => 'sucesso', 'msg' => 'Logout realizado']);
}

// ================================================================
// ADMIN STATS (enhanced)
// ================================================================
if ($acao === 'admin_stats'){
    $data = [
        'status' => 'sucesso',
        'total_usuarios' => 0,
        'usuarios_ativos' => 0,
        'total_downloads' => 0,
        'cred_vendidos' => 0,
        'total_pedidos' => 0,
        'receita_total' => 0,
        'total_leads' => 0,
        'conversao' => 0,
        'ultimos_pedidos' => []
    ];

    try {
        $data['total_usuarios'] = (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
        $data['usuarios_ativos'] = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1")->fetchColumn();
        $data['total_downloads'] = (int) $pdo->query("SELECT COUNT(*) FROM transacoes WHERE tipo = 'consumo'")->fetchColumn();
        $data['cred_vendidos'] = (int) $pdo->query("SELECT COALESCE(SUM(quantidade), 0) FROM transacoes WHERE tipo = 'compra'")->fetchColumn();
        $data['total_pedidos'] = (int) $pdo->query("SELECT COUNT(*) FROM pedidos")->fetchColumn();
        $data['receita_total'] = (float) $pdo->query("SELECT COALESCE(SUM(valor), 0) FROM pedidos WHERE status = 'aprovado'")->fetchColumn();
        $data['total_leads'] = (int) $pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();

        if ($data['total_leads'] > 0) {
            $data['conversao'] = round(($data['usuarios_ativos'] / $data['total_leads']) * 100, 2);
        }

        $stmt = $pdo->query("SELECT id, email, plano, creditos, valor, status, created_at FROM pedidos ORDER BY created_at DESC LIMIT 10");
        $data['ultimos_pedidos'] = $stmt->fetchAll();
    } catch(Exception $e){}

    jsonResponse($data);
}

// ================================================================
// ADMIN USUARIOS
// ================================================================
if ($acao === 'admin_usuarios'){
    $page = max(1, (int)($request['page'] ?? 1));
    $limit = max(1, min(100, (int)($request['limit'] ?? 20)));
    $busca = trim($request['busca'] ?? '');
    $offset = ($page - 1) * $limit;

    try {
        $where = '';
        $params = [];
        if ($busca !== '') {
            $where = "WHERE email LIKE ? OR nome LIKE ?";
            $params = ["%$busca%", "%$busca%"];
        }

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios $where");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("SELECT id, email, nome, cpf, creditos, plano, ativo, created_at, updated_at FROM usuarios $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll();

        jsonResponse(['status' => 'sucesso', 'usuarios' => $usuarios, 'total' => $total]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar usuários']);
    }
}

// ================================================================
// ADMIN ADD USUARIO
// ================================================================
if ($acao === 'admin_add_usuario'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';
    $nome = sanitizeString($request['nome'] ?? '', 120);
    $cpf = sanitizeCPF($request['cpf'] ?? '');
    $plano = $request['plano'] ?? 'avulso';
    $creditos = max(0, (int)($request['creditos'] ?? 0));

    if (!$email) jsonResponse(['status' => 'erro', 'msg' => 'Email inválido']);
    if (!validarSenha($senha)) jsonResponse(['status' => 'erro', 'msg' => 'Senha deve ter pelo menos 6 caracteres']);

    try {
        $hash = password_hash($senha, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha_hash, nome, cpf, plano, creditos) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$email, $hash, $nome, $cpf, $plano, $creditos]);

        registrarLog($pdo, 'info', 'admin_add_usuario', "Usuário criado: $email");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Usuário criado!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao criar usuário. Email já existe?']);
    }
}

// ================================================================
// ADMIN EDIT USUARIO
// ================================================================
if ($acao === 'admin_edit_usuario'){
    $usuario_id = (int)($request['usuario_id'] ?? 0);
    if (!$usuario_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);

    $nome = sanitizeString($request['nome'] ?? '', 120);
    $email = sanitizeEmail($request['email'] ?? '');
    $cpf = sanitizeCPF($request['cpf'] ?? '');
    $plano = $request['plano'] ?? 'avulso';
    $senha = $request['senha'] ?? '';

    if (!$email) jsonResponse(['status' => 'erro', 'msg' => 'Email inválido']);

    try {
        if ($senha !== '') {
            $hash = password_hash($senha, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, cpf = ?, plano = ?, senha_hash = ? WHERE id = ?");
            $stmt->execute([$nome, $email, $cpf, $plano, $hash, $usuario_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, email = ?, cpf = ?, plano = ? WHERE id = ?");
            $stmt->execute([$nome, $email, $cpf, $plano, $usuario_id]);
        }

        registrarLog($pdo, 'info', 'admin_edit_usuario', "Usuário #$usuario_id atualizado");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Usuário atualizado!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao atualizar usuário']);
    }
}

// ================================================================
// ADMIN DELETE USUARIO
// ================================================================
if ($acao === 'admin_delete_usuario'){
    $usuario_id = (int)($request['usuario_id'] ?? 0);
    if (!$usuario_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);

    try {
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$usuario_id]);
        registrarLog($pdo, 'warning', 'admin_delete_usuario', "Usuário #$usuario_id removido");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Usuário removido!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao remover usuário']);
    }
}

// ================================================================
// ADMIN TOGGLE USUARIO (ativar/desativar)
// ================================================================
if ($acao === 'admin_toggle_usuario'){
    $usuario_id = (int)($request['usuario_id'] ?? 0);
    if (!$usuario_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);

    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET ativo = NOT ativo WHERE id = ?");
        $stmt->execute([$usuario_id]);
        registrarLog($pdo, 'info', 'admin_toggle_usuario', "Status do usuário #$usuario_id alternado");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Status do usuário atualizado!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao alterar status']);
    }
}

// ================================================================
// ADMIN ADD CREDITOS
// ================================================================
if ($acao === 'admin_add_creditos'){
    $usuario_id = (int)($request['usuario_id'] ?? 0);
    $quantidade = (int)($request['quantidade'] ?? 0);
    $descricao = sanitizeString($request['descricao'] ?? 'Ajuste manual admin', 255);
    $operacao = $request['operacao'] ?? '';

    if (!$usuario_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);

    try {
        if ($operacao === 'set') {
            $quantidade = max(0, $quantidade);
            $stmt = $pdo->prepare("UPDATE usuarios SET creditos = ? WHERE id = ?");
            $stmt->execute([$quantidade, $usuario_id]);
        } elseif ($quantidade > 0) {
            $stmt = $pdo->prepare("UPDATE usuarios SET creditos = creditos + ? WHERE id = ?");
            $stmt->execute([$quantidade, $usuario_id]);
        } elseif ($quantidade < 0) {
            $stmt = $pdo->prepare("UPDATE usuarios SET creditos = GREATEST(0, CAST(creditos AS SIGNED) + ?) WHERE id = ?");
            $stmt->execute([$quantidade, $usuario_id]);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("INSERT INTO transacoes (usuario_id, tipo, quantidade, descricao, ip) VALUES (?, 'manual', ?, ?, ?)");
        $stmt->execute([$usuario_id, $quantidade, $descricao, $ip]);

        registrarLog($pdo, 'info', 'admin_add_creditos', "Créditos ajustados para usuário #$usuario_id: $quantidade ($operacao)");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Créditos atualizados!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao atualizar créditos']);
    }
}

// ================================================================
// ADMIN FORCE PASSWORD
// ================================================================
if ($acao === 'admin_force_password'){
    $usuario_id = (int)($request['usuario_id'] ?? 0);
    $senha = $request['senha'] ?? '';

    if (!$usuario_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);
    if (!validarSenha($senha)) jsonResponse(['status' => 'erro', 'msg' => 'Senha deve ter pelo menos 6 caracteres']);

    try {
        $hash = password_hash($senha, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $usuario_id]);

        registrarLog($pdo, 'warning', 'admin_force_password', "Senha forçada para usuário #$usuario_id");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Senha atualizada!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao atualizar senha']);
    }
}

// ================================================================
// ADMIN PEDIDOS
// ================================================================
if ($acao === 'admin_pedidos'){
    $page = max(1, (int)($request['page'] ?? 1));
    $limit = max(1, min(100, (int)($request['limit'] ?? 20)));
    $busca = trim($request['busca'] ?? '');
    $status_filter = trim($request['status'] ?? '');
    $plano_filter = trim($request['plano'] ?? '');
    $de = trim($request['de'] ?? '');
    $ate = trim($request['ate'] ?? '');
    $offset = ($page - 1) * $limit;

    try {
        $where = [];
        $params = [];

        if ($busca !== '') {
            $where[] = "email LIKE ?";
            $params[] = "%$busca%";
        }
        if ($status_filter !== '') {
            $where[] = "status = ?";
            $params[] = $status_filter;
        }
        if ($plano_filter !== '') {
            $where[] = "plano = ?";
            $params[] = $plano_filter;
        }
        if ($de !== '') {
            $where[] = "created_at >= ?";
            $params[] = "$de 00:00:00";
        }
        if ($ate !== '') {
            $where[] = "created_at <= ?";
            $params[] = "$ate 23:59:59";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM pedidos $whereSql");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("SELECT id, usuario_id, email, plano, creditos, valor, status, gateway, gateway_id, ip, created_at, updated_at FROM pedidos $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $pedidos = $stmt->fetchAll();

        jsonResponse(['status' => 'sucesso', 'pedidos' => $pedidos, 'total' => $total]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar pedidos']);
    }
}

// ================================================================
// ADMIN APROVAR PEDIDO
// ================================================================
if ($acao === 'admin_aprovar_pedido'){
    $pedido_id = (int)($request['pedido_id'] ?? 0);
    if (!$pedido_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);

    try {
        $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
        $stmt->execute([$pedido_id]);
        $pedido = $stmt->fetch();

        if (!$pedido) jsonResponse(['status' => 'erro', 'msg' => 'Pedido não encontrado']);
        if ($pedido['status'] !== 'pendente') jsonResponse(['status' => 'erro', 'msg' => 'Pedido não está pendente']);

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("UPDATE pedidos SET status = 'aprovado', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$pedido_id]);

        // Find or create user by email
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$pedido['email']]);
        $user = $stmt->fetch();

        if ($user) {
            $uid = $user['id'];
            $stmt = $pdo->prepare("UPDATE usuarios SET creditos = creditos + ? WHERE id = ?");
            $stmt->execute([$pedido['creditos'], $uid]);
        } else {
            $hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha_hash, plano, creditos) VALUES (?, ?, ?, ?)");
            $stmt->execute([$pedido['email'], $hash, $pedido['plano'], $pedido['creditos']]);
            $uid = $pdo->lastInsertId();
        }

        // Update pedido with usuario_id
        $stmt = $pdo->prepare("UPDATE pedidos SET usuario_id = ? WHERE id = ?");
        $stmt->execute([$uid, $pedido_id]);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("INSERT INTO transacoes (usuario_id, tipo, quantidade, descricao, referencia, ip) VALUES (?, 'compra', ?, ?, ?, ?)");
        $stmt->execute([$uid, $pedido['creditos'], "Pedido #$pedido_id aprovado (admin)", "pedido_$pedido_id", $ip]);

        $pdo->commit();

        registrarLog($pdo, 'info', 'admin_aprovar_pedido', "Pedido #$pedido_id aprovado, $pedido[creditos] créditos para $pedido[email]");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Pedido aprovado e créditos liberados!']);
    } catch(Exception $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao aprovar pedido']);
    }
}

// ================================================================
// ADMIN DELETE PEDIDO INDIVIDUAL
// ================================================================
if ($acao === 'admin_delete_pedido_individual'){
    $pedido_id = (int)($request['pedido_id'] ?? 0);
    if (!$pedido_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);

    try {
        $stmt = $pdo->prepare("DELETE FROM pedidos WHERE id = ?");
        $stmt->execute([$pedido_id]);
        registrarLog($pdo, 'warning', 'admin_delete_pedido', "Pedido #$pedido_id removido");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Pedido removido!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao remover pedido']);
    }
}

// ================================================================
// ADMIN LIMPAR PEDIDOS
// ================================================================
if ($acao === 'admin_limpar_pedidos'){
    try {
        $pdo->exec("DELETE FROM pedidos");
        registrarLog($pdo, 'warning', 'admin_limpar_pedidos', 'Histórico de pedidos limpo');
        jsonResponse(['status' => 'sucesso', 'msg' => 'Histórico de pedidos limpo!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao limpar pedidos']);
    }
}

// ================================================================
// ADMIN TRANSACOES
// ================================================================
if ($acao === 'admin_transacoes'){
    $page = max(1, (int)($request['page'] ?? 1));
    $limit = max(1, min(100, (int)($request['limit'] ?? 20)));
    $busca = trim($request['busca'] ?? '');
    $tipo_filter = trim($request['tipo'] ?? '');
    $de = trim($request['de'] ?? '');
    $ate = trim($request['ate'] ?? '');
    $offset = ($page - 1) * $limit;

    try {
        $where = [];
        $params = [];

        if ($busca !== '') {
            $where[] = "u.email LIKE ?";
            $params[] = "%$busca%";
        }
        if ($tipo_filter !== '') {
            $where[] = "t.tipo = ?";
            $params[] = $tipo_filter;
        }
        if ($de !== '') {
            $where[] = "t.created_at >= ?";
            $params[] = "$de 00:00:00";
        }
        if ($ate !== '') {
            $where[] = "t.created_at <= ?";
            $params[] = "$ate 23:59:59";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM transacoes t LEFT JOIN usuarios u ON t.usuario_id = u.id $whereSql");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("SELECT t.id, u.email, t.tipo, t.quantidade, t.descricao, t.referencia, t.ip, t.created_at FROM transacoes t LEFT JOIN usuarios u ON t.usuario_id = u.id $whereSql ORDER BY t.id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $transacoes = $stmt->fetchAll();

        jsonResponse(['status' => 'sucesso', 'transacoes' => $transacoes, 'total' => $total]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar transações']);
    }
}

// ================================================================
// ADMIN DELETE TRANSACAO INDIVIDUAL
// ================================================================
if ($acao === 'admin_delete_transacao_individual'){
    $transacao_id = (int)($request['transacao_id'] ?? 0);
    if (!$transacao_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);

    try {
        $stmt = $pdo->prepare("DELETE FROM transacoes WHERE id = ?");
        $stmt->execute([$transacao_id]);
        registrarLog($pdo, 'warning', 'admin_delete_transacao', "Transação #$transacao_id removida");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Transação removida!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao remover transação']);
    }
}

// ================================================================
// ADMIN LIMPAR TRANSACOES
// ================================================================
if ($acao === 'admin_limpar_transacoes'){
    try {
        $pdo->exec("DELETE FROM transacoes");
        registrarLog($pdo, 'warning', 'admin_limpar_transacoes', 'Histórico de transações limpo');
        jsonResponse(['status' => 'sucesso', 'msg' => 'Histórico de transações limpo!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao limpar transações']);
    }
}

// ================================================================
// ADMIN LEADS
// ================================================================
if ($acao === 'admin_leads'){
    $page = max(1, (int)($request['page'] ?? 1));
    $limit = max(1, min(100, (int)($request['limit'] ?? 20)));
    $busca = trim($request['busca'] ?? '');
    $offset = ($page - 1) * $limit;

    try {
        $where = '';
        $params = [];
        if ($busca !== '') {
            $where = "WHERE nome LIKE ? OR email LIKE ? OR telefone LIKE ?";
            $params = ["%$busca%", "%$busca%", "%$busca%"];
        }

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM leads $where");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("SELECT id, nome, email, telefone, origem, ip, created_at FROM leads $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $leads = $stmt->fetchAll();

        jsonResponse(['status' => 'sucesso', 'leads' => $leads, 'total' => $total]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar leads']);
    }
}

// ================================================================
// ADMIN DELETE LEAD INDIVIDUAL
// ================================================================
if ($acao === 'admin_delete_lead_individual'){
    $lead_id = (int)($request['lead_id'] ?? 0);
    if (!$lead_id) jsonResponse(['status' => 'erro', 'msg' => 'ID inválido']);

    try {
        $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
        $stmt->execute([$lead_id]);
        jsonResponse(['status' => 'sucesso', 'msg' => 'Lead removido!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao remover lead']);
    }
}

// ================================================================
// ADMIN LIMPAR LEADS
// ================================================================
if ($acao === 'admin_limpar_leads'){
    try {
        $pdo->exec("DELETE FROM leads");
        registrarLog($pdo, 'warning', 'admin_limpar_leads', 'Leads limpos');
        jsonResponse(['status' => 'sucesso', 'msg' => 'Leads limpos!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao limpar leads']);
    }
}

// ================================================================
// ADMIN GET CONFIGS
// ================================================================
if ($acao === 'admin_get_configs'){
    try {
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes");
        $rows = $stmt->fetchAll();
        $configs = [];
        foreach ($rows as $row) {
            $configs[$row['chave']] = $row['valor'];
        }
        jsonResponse(['status' => 'sucesso', 'configs' => $configs]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar configurações']);
    }
}

// ================================================================
// ADMIN SAVE CONFIGS
// ================================================================
if ($acao === 'admin_save_configs'){
    $configs = $request['configs'] ?? [];
    if (!is_array($configs) || empty($configs)) {
        jsonResponse(['status' => 'erro', 'msg' => 'Nenhuma configuração enviada']);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?");
        foreach ($configs as $chave => $valor) {
            $chave = sanitizeString((string)$chave, 80);
            $valor = (string)$valor;
            $stmt->execute([$chave, $valor, $valor]);
        }
        registrarLog($pdo, 'info', 'admin_save_configs', 'Configurações salvas: ' . implode(', ', array_keys($configs)));
        jsonResponse(['status' => 'sucesso', 'msg' => 'Configurações salvas!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao salvar configurações']);
    }
}

// ================================================================
// ADMIN FINANCEIRO
// ================================================================
if ($acao === 'admin_financeiro'){
    $de = trim($request['de'] ?? '');
    $ate = trim($request['ate'] ?? '');

    try {
        $where = [];
        $params = [];
        if ($de !== '') {
            $where[] = "created_at >= ?";
            $params[] = "$de 00:00:00";
        }
        if ($ate !== '') {
            $where[] = "created_at <= ?";
            $params[] = "$ate 23:59:59";
        }

        $dateFilter = $where ? ' AND ' . implode(' AND ', $where) : '';

        // Receita e pedidos aprovados
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) as receita, COUNT(*) as pedidos FROM pedidos WHERE status = 'aprovado' $dateFilter");
        $stmt->execute($params);
        $row = $stmt->fetch();
        $receita = (float)$row['receita'];
        $pedidos = (int)$row['pedidos'];
        $ticket = $pedidos > 0 ? round($receita / $pedidos, 2) : 0;

        // Transacoes: vendidos + pdfs
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantidade), 0) FROM transacoes WHERE tipo = 'compra' $dateFilter");
        $stmt->execute($params);
        $vendidos = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transacoes WHERE tipo = 'consumo' $dateFilter");
        $stmt->execute($params);
        $pdfs = (int) $stmt->fetchColumn();

        // Saldo total
        $saldo = (int) $pdo->query("SELECT COALESCE(SUM(creditos), 0) FROM usuarios")->fetchColumn();

        // Série diária
        $stmt = $pdo->prepare("SELECT DATE(created_at) as dia, SUM(valor) as receita FROM pedidos WHERE status = 'aprovado' $dateFilter GROUP BY DATE(created_at) ORDER BY dia ASC");
        $stmt->execute($params);
        $serie = $stmt->fetchAll();

        jsonResponse([
            'status' => 'sucesso',
            'receita' => $receita,
            'pedidos' => $pedidos,
            'ticket' => $ticket,
            'vendidos' => $vendidos,
            'pdfs' => $pdfs,
            'saldo' => $saldo,
            'serie' => $serie
        ]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar dados financeiros']);
    }
}

// ================================================================
// ADMIN FRAUDE STATS
// ================================================================
if ($acao === 'admin_fraude_stats'){
    try {
        $total_bonus = (int) $pdo->query("SELECT COUNT(*) FROM transacoes WHERE tipo = 'bonus'")->fetchColumn();

        $stmt = $pdo->query("SELECT ip as ip_cadastro, COUNT(DISTINCT usuario_id) as total FROM transacoes WHERE tipo = 'bonus' AND ip IS NOT NULL GROUP BY ip HAVING total > 1 ORDER BY total DESC");
        $ips_suspeitos = $stmt->fetchAll();

        jsonResponse([
            'status' => 'sucesso',
            'total_bonus' => $total_bonus,
            'ips_suspeitos' => $ips_suspeitos
        ]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar dados de fraude']);
    }
}

// ================================================================
// ADMIN DELETE IP SUSPEITO
// ================================================================
if ($acao === 'admin_delete_ip_suspeito'){
    $ip = trim($request['ip'] ?? '');
    if ($ip === '') jsonResponse(['status' => 'erro', 'msg' => 'IP inválido']);

    try {
        $stmt = $pdo->prepare("DELETE FROM transacoes WHERE tipo = 'bonus' AND ip = ?");
        $stmt->execute([$ip]);
        registrarLog($pdo, 'warning', 'admin_delete_ip_suspeito', "Transações bonus do IP $ip removidas");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Transações do IP removidas!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao remover transações do IP']);
    }
}

// ================================================================
// ADMIN LOGS
// ================================================================
if ($acao === 'admin_logs'){
    $page = max(1, (int)($request['page'] ?? 1));
    $limit = max(1, min(100, (int)($request['limit'] ?? 20)));
    $busca = trim($request['busca'] ?? '');
    $nivel_filter = trim($request['nivel'] ?? '');
    $offset = ($page - 1) * $limit;

    try {
        $where = [];
        $params = [];

        if ($nivel_filter !== '') {
            $where[] = "l.nivel = ?";
            $params[] = $nivel_filter;
        }
        if ($busca !== '') {
            $where[] = "(l.acao LIKE ? OR l.detalhes LIKE ?)";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM logs l $whereSql");
        $stmtCount->execute($params);
        $total = (int) $stmtCount->fetchColumn();

        $stmt = $pdo->prepare("SELECT l.id, l.nivel, l.acao, l.detalhes, l.usuario_id, u.email as usuario_email, l.ip, l.created_at FROM logs l LEFT JOIN usuarios u ON l.usuario_id = u.id $whereSql ORDER BY l.id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        jsonResponse(['status' => 'sucesso', 'logs' => $logs, 'total' => $total]);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao buscar logs']);
    }
}

// ================================================================
// ADMIN LIMPAR LOGS
// ================================================================
if ($acao === 'admin_limpar_logs'){
    try {
        $pdo->exec("DELETE FROM logs");
        jsonResponse(['status' => 'sucesso', 'msg' => 'Logs limpos!']);
    } catch(Exception $e){
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao limpar logs']);
    }
}

// ================================================================
// LOGIN USUARIO (validar)
// ================================================================
if ($acao === 'validar'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';

    if (!$email || !$senha) {
        jsonResponse(['status' => 'erro', 'msg' => 'Preencha e-mail e senha.']);
    }

    if (rateLimitExcedido($pdo, 'login_falha', 10, 15)) {
        jsonResponse(['status' => 'erro', 'msg' => 'Muitas tentativas. Aguarde 15 minutos.']);
    }

    try {
        $stmt = $pdo->prepare("SELECT id, senha_hash, nome, cpf, creditos, plano, ativo FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            registrarLog($pdo, 'warning', 'login_falha', "Tentativa de login falhou: $email");
            jsonResponse(['status' => 'erro', 'msg' => 'E-mail ou senha incorretos.']);
        }

        if (!$user['ativo']) {
            jsonResponse(['status' => 'erro', 'msg' => 'Conta desativada. Entre em contato com o suporte.']);
        }

        registrarLog($pdo, 'info', 'login_ok', "Login: $email", $user['id']);
        jsonResponse([
            'status'   => 'sucesso',
            'id'       => $user['id'],
            'nome'     => $user['nome'],
            'cpf'      => $user['cpf'],
            'creditos' => $user['creditos'],
            'plano'    => $user['plano']
        ]);
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro interno ao validar credenciais.']);
    }
}

// ================================================================
// REGISTRAR USUARIO (registrar)
// ================================================================
if ($acao === 'registrar'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';
    $nome  = sanitizeString($request['nome'] ?? '', 120);
    $cpf   = sanitizeCPF($request['cpf'] ?? '');

    if (!$email) {
        jsonResponse(['status' => 'erro', 'msg' => 'E-mail inválido.']);
    }
    if (!validarSenha($senha)) {
        jsonResponse(['status' => 'erro', 'msg' => 'Senha deve ter pelo menos 6 caracteres.']);
    }
    if (!$nome) {
        jsonResponse(['status' => 'erro', 'msg' => 'Nome é obrigatório.']);
    }

    if (rateLimitExcedido($pdo, 'registro', 5, 60)) {
        jsonResponse(['status' => 'erro', 'msg' => 'Muitos registros recentes. Aguarde um pouco.']);
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(['status' => 'erro', 'msg' => 'Este e-mail já está cadastrado.']);
        }

        $senhaHash = password_hash($senha, PASSWORD_BCRYPT);

        // Check if free credits are enabled
        $creditosIniciais = 0;
        try {
            $stmtCfg = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'credito_gratis_ativo'");
            $stmtCfg->execute();
            $cfgAtivo = $stmtCfg->fetchColumn();
            if ($cfgAtivo === '1') {
                $stmtQtd = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'credito_gratis_qtd'");
                $stmtQtd->execute();
                $creditosIniciais = (int)($stmtQtd->fetchColumn() ?: 0);
            }
        } catch (Exception $e) {}

        $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha_hash, nome, cpf, creditos) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$email, $senhaHash, $nome, $cpf ?: null, $creditosIniciais]);

        $userId = $pdo->lastInsertId();
        registrarLog($pdo, 'info', 'registro', "Novo usuário: $email", (int)$userId);

        jsonResponse(['status' => 'sucesso', 'msg' => 'Conta criada com sucesso!', 'id' => $userId]);
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao criar conta. Tente novamente.']);
    }
}

// ================================================================
// ATUALIZAR CPF
// ================================================================
if ($acao === 'atualizar_cpf'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';
    $cpf   = sanitizeCPF($request['cpf'] ?? '');

    if (!$email || !$senha) {
        jsonResponse(['status' => 'erro', 'msg' => 'Credenciais inválidas.']);
    }
    if (strlen($cpf) !== 11) {
        jsonResponse(['status' => 'erro', 'msg' => 'CPF inválido.']);
    }

    try {
        $stmt = $pdo->prepare("SELECT id, senha_hash FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            jsonResponse(['status' => 'erro', 'msg' => 'Credenciais inválidas.']);
        }

        $stmt = $pdo->prepare("UPDATE usuarios SET cpf = ? WHERE id = ?");
        $stmt->execute([$cpf, $user['id']]);

        jsonResponse(['status' => 'sucesso', 'msg' => 'CPF atualizado!']);
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao atualizar CPF.']);
    }
}

// ================================================================
// IA — PARSE RESUME (ai_parse_resume)
// ================================================================
if ($acao === 'ai_parse_resume'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';

    if (!$email || !$senha) {
        jsonResponse(['status' => 'erro', 'msg' => 'Faça login antes de usar a IA.']);
    }

    // Validate user
    try {
        $stmt = $pdo->prepare("SELECT id, senha_hash, creditos, ativo FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            jsonResponse(['status' => 'erro', 'msg' => 'Credenciais inválidas.']);
        }

        if (!$user['ativo']) {
            jsonResponse(['status' => 'erro', 'msg' => 'Conta desativada.']);
        }
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao validar usuário.']);
    }

    // Check AI daily limits
    try {
        $limiteUsuario = 30;
        $stmtLim = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ia_limite_usuario_dia'");
        $stmtLim->execute();
        $valLim = $stmtLim->fetchColumn();
        if ($valLim !== false) $limiteUsuario = (int)$valLim;

        if ($limiteUsuario > 0) {
            $stmtUso = $pdo->prepare("SELECT usos_hoje, ultima_data FROM ia_limites WHERE usuario_id = ?");
            $stmtUso->execute([$user['id']]);
            $uso = $stmtUso->fetch();
            $hoje = date('Y-m-d');

            if ($uso && $uso['ultima_data'] === $hoje && $uso['usos_hoje'] >= $limiteUsuario) {
                $usoStr = $limiteUsuario === 1 ? 'uso' : 'usos';
                jsonResponse(['status' => 'erro', 'msg' => "Limite diário de IA atingido ($limiteUsuario $usoStr). Tente novamente amanhã."]);
            }
        }
    } catch (Exception $e) {}

    // Get LLM config
    try {
        $cfgKeys = ['llm_enabled', 'llm_provider', 'llm_model', 'llm_endpoint', 'llm_api_key'];
        $placeholders = implode(',', array_fill(0, count($cfgKeys), '?'));
        $stmtCfg = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ($placeholders)");
        $stmtCfg->execute($cfgKeys);
        $llmCfg = [];
        while ($row = $stmtCfg->fetch()) {
            $llmCfg[$row['chave']] = $row['valor'];
        }
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao carregar configuração da IA.']);
    }

    if (empty($llmCfg['llm_enabled']) || $llmCfg['llm_enabled'] !== '1') {
        jsonResponse(['status' => 'erro', 'msg' => 'A funcionalidade de IA está desativada. Ative-a no painel admin (llm_enabled).']);
    }

    $apiKey = $llmCfg['llm_api_key'] ?? '';
    if (!$apiKey) {
        jsonResponse(['status' => 'erro', 'msg' => 'Chave de API da IA não configurada. Configure no painel admin (llm_api_key).']);
    }

    $endpoint = $llmCfg['llm_endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
    $model    = $llmCfg['llm_model'] ?? 'gpt-4o-mini';
    $provider = $llmCfg['llm_provider'] ?? 'openai';

    // Build prompt
    $texto  = $request['texto'] ?? '';
    $images = $request['images'] ?? [];

    $systemPrompt = 'Você é um assistente especializado em extrair dados de currículos. '
        . 'Retorne APENAS um JSON válido (sem markdown, sem ```json) com a seguinte estrutura: '
        . '{"nome":"","email":"","telefone":"","endereco_completo":"","linkedin":"","github":"","objetivo":"",'
        . '"habilidades":[""],"idiomas":[{"idioma":"","nivel":""}],'
        . '"experiencias":[{"cargo":"","empresa":"","periodo":"","descricao":""}],'
        . '"formacao":[{"curso":"","instituicao":"","periodo":""}]}';

    // Build messages based on provider
    $messages = [['role' => 'system', 'content' => $systemPrompt]];

    if (!empty($images)) {
        // Vision mode — send images
        $contentParts = [['type' => 'text', 'text' => 'Extraia todos os dados deste currículo em formato JSON:']];
        foreach ($images as $img) {
            $base64 = $img;
            if (strpos($img, 'data:') !== 0) {
                $base64 = 'data:image/png;base64,' . $img;
            }
            $contentParts[] = ['type' => 'image_url', 'image_url' => ['url' => $base64]];
        }
        $messages[] = ['role' => 'user', 'content' => $contentParts];
    } else {
        $messages[] = ['role' => 'user', 'content' => "Extraia os dados do seguinte currículo em formato JSON:\n\n" . $texto];
    }

    // Call LLM
    $payload = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.2,
        'max_tokens'  => 3000
    ], JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];

    // Support Gemini provider
    if ($provider === 'gemini') {
        $endpoint = str_contains($endpoint, '?') ? $endpoint : $endpoint . '?key=' . $apiKey;
        $headers = ['Content-Type: application/json'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        registrarLog($pdo, 'error', 'ai_parse_resume', "cURL error: $curlError", $user['id']);
        jsonResponse(['status' => 'erro', 'msg' => 'Erro de conexão com a IA. Tente novamente.']);
    }

    if ($httpCode !== 200) {
        registrarLog($pdo, 'error', 'ai_parse_resume', "HTTP $httpCode: $response", $user['id']);
        jsonResponse(['status' => 'erro', 'msg' => "Erro na API da IA (HTTP $httpCode). Verifique a chave e endpoint no admin."]);
    }

    $decoded = json_decode($response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';

    if (!$content) {
        jsonResponse(['status' => 'erro', 'msg' => 'A IA não retornou conteúdo.']);
    }

    // Clean markdown delimiters if present
    $content = preg_replace('/^```json\s*/i', '', trim($content));
    $content = preg_replace('/\s*```$/i', '', $content);

    $result = json_decode($content, true);
    if (!$result) {
        jsonResponse(['status' => 'erro', 'msg' => 'A IA retornou dados em formato inválido.']);
    }

    // Update daily AI usage
    try {
        $hoje = date('Y-m-d');
        $stmtUso = $pdo->prepare("INSERT INTO ia_limites (usuario_id, usos_hoje, ultima_data) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE usos_hoje = IF(ultima_data = VALUES(ultima_data), usos_hoje + 1, 1), ultima_data = VALUES(ultima_data)");
        $stmtUso->execute([$user['id'], $hoje]);
    } catch (Exception $e) {}

    registrarLog($pdo, 'info', 'ai_parse_resume', "IA usada por $email", $user['id']);

    jsonResponse(['status' => 'sucesso', 'result' => $result]);
}

// ================================================================
// VERIFICAR CREDENCIAIS / SYNC (verificar)
// Alias de validar — usado pelo frontend para sincronizar créditos
// ================================================================
if ($acao === 'verificar'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';

    if (!$email || !$senha) {
        jsonResponse(['status' => 'erro', 'msg' => 'Credenciais inválidas.']);
    }

    try {
        $stmt = $pdo->prepare("SELECT id, senha_hash, nome, cpf, creditos, plano, ativo FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            jsonResponse(['status' => 'erro', 'msg' => 'E-mail ou senha incorretos.']);
        }

        if (!$user['ativo']) {
            jsonResponse(['status' => 'erro', 'msg' => 'Conta desativada.']);
        }

        jsonResponse([
            'status'   => 'sucesso',
            'id'       => $user['id'],
            'nome'     => $user['nome'],
            'cpf'      => $user['cpf'],
            'creditos' => $user['creditos'],
            'plano'    => $user['plano']
        ]);
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro interno ao verificar credenciais.']);
    }
}

// ================================================================
// CONSUMIR CRÉDITO (consumir)
// Consome 1 crédito para download de PDF oficial
// ================================================================
if ($acao === 'consumir'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';

    if (!$email || !$senha) {
        jsonResponse(['status' => 'erro', 'msg' => 'Credenciais inválidas.']);
    }

    try {
        $stmt = $pdo->prepare("SELECT id, senha_hash, creditos, ativo FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            jsonResponse(['status' => 'erro', 'msg' => 'Credenciais inválidas.']);
        }

        if (!$user['ativo']) {
            jsonResponse(['status' => 'erro', 'msg' => 'Conta desativada.']);
        }

        if ((int)$user['creditos'] <= 0) {
            jsonResponse(['status' => 'erro', 'msg' => 'Sem créditos disponíveis. Adquira um plano na loja.']);
        }

        // Deduct 1 credit
        $pdo->prepare("UPDATE usuarios SET creditos = creditos - 1 WHERE id = ? AND creditos > 0")->execute([$user['id']]);

        // Record transaction
        $ip = getClientIP();
        $pdo->prepare(
            "INSERT INTO transacoes (usuario_id, tipo, quantidade, descricao, ip, created_at) VALUES (?, 'consumo', -1, 'Download PDF oficial', ?, NOW())"
        )->execute([$user['id'], $ip]);

        // Get updated credit count
        $stmtCred = $pdo->prepare("SELECT creditos FROM usuarios WHERE id = ?");
        $stmtCred->execute([$user['id']]);
        $newCredits = (int)$stmtCred->fetchColumn();

        registrarLog($pdo, 'info', 'consumir', "Crédito consumido por $email", $user['id']);

        jsonResponse([
            'status'   => 'sucesso',
            'creditos' => $newCredits,
            'msg'      => 'Crédito consumido com sucesso!'
        ]);
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao consumir crédito.']);
    }
}

// ================================================================
// GERAR PAGAMENTO (gerar_pagamento)
// Cria pedido e retorna link de pagamento
// ================================================================
if ($acao === 'gerar_pagamento'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';
    $plano = trim($request['plano'] ?? '');

    if (!$email || !$senha) {
        jsonResponse(['status' => 'erro', 'msg' => 'Faça login antes de comprar.']);
    }

    // Validate user
    try {
        $stmt = $pdo->prepare("SELECT id, senha_hash, ativo FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            jsonResponse(['status' => 'erro', 'msg' => 'Credenciais inválidas.']);
        }

        if (!$user['ativo']) {
            jsonResponse(['status' => 'erro', 'msg' => 'Conta desativada.']);
        }
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao validar usuário.']);
    }

    // Load plan configs
    $planosValidos = ['basico', 'profissional', 'agencia', 'enterprise'];
    if (!in_array($plano, $planosValidos)) {
        jsonResponse(['status' => 'erro', 'msg' => 'Plano inválido.']);
    }

    try {
        $cfgKeys = [
            "valor_$plano", "creditos_$plano", "nome_plano_$plano",
            "mp_link_$plano", "mp_access_token", "gateway_ativo", "site_url"
        ];
        $placeholders = implode(',', array_fill(0, count($cfgKeys), '?'));
        $stmtCfg = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ($placeholders)");
        $stmtCfg->execute($cfgKeys);
        $cfg = [];
        while ($row = $stmtCfg->fetch()) {
            $cfg[$row['chave']] = $row['valor'];
        }
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao carregar configuração de pagamento.']);
    }

    $valor = (float)($cfg["valor_$plano"] ?? 0);
    $creditos = (int)($cfg["creditos_$plano"] ?? 0);
    $nomePlano = $cfg["nome_plano_$plano"] ?? ucfirst($plano);

    if ($valor <= 0 || $creditos <= 0) {
        jsonResponse(['status' => 'erro', 'msg' => 'Plano não configurado. Contate o suporte.']);
    }

    // Create pedido record
    $ip = getClientIP();
    try {
        $stmtPed = $pdo->prepare(
            "INSERT INTO pedidos (usuario_id, email, plano, creditos, valor, status, gateway, ip, created_at) VALUES (?, ?, ?, ?, ?, 'pendente', 'mercadopago', ?, NOW())"
        );
        $stmtPed->execute([$user['id'], $email, $plano, $creditos, $valor, $ip]);
        $pedidoId = (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao criar pedido.']);
    }

    // Check if there's a simple Mercado Pago link configured
    $mpLink = trim($cfg["mp_link_$plano"] ?? '');
    if ($mpLink) {
        registrarLog($pdo, 'info', 'gerar_pagamento', "Pedido #$pedidoId criado (link direto, plano $plano)", $user['id']);
        jsonResponse([
            'status'    => 'sucesso',
            'link'      => $mpLink,
            'pedido_id' => $pedidoId
        ]);
    }

    // Try Mercado Pago API if access_token is configured
    $mpToken = trim($cfg['mp_access_token'] ?? '');
    if ($mpToken) {
        $siteUrl = trim($cfg['site_url'] ?? '');
        $preference = [
            'items' => [[
                'title'       => "KONEX - Plano $nomePlano ({$creditos} créditos)",
                'quantity'    => 1,
                'unit_price'  => $valor,
                'currency_id' => 'BRL'
            ]],
            'external_reference' => "KNX_$pedidoId",
            'payment_methods' => [
                'installments' => 1
            ]
        ];

        if ($siteUrl) {
            $preference['back_urls'] = [
                'success' => $siteUrl,
                'failure' => $siteUrl,
                'pending' => $siteUrl
            ];
            $preference['auto_return'] = 'approved';
            $preference['notification_url'] = rtrim($siteUrl, '/') . '/api.php?acao=webhook_mp';
        }

        $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($preference),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $mpToken
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $mpResponse = curl_exec($ch);
        $mpHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            registrarLog($pdo, 'error', 'gerar_pagamento', "cURL error: $curlErr", $user['id']);
            jsonResponse(['status' => 'erro', 'msg' => 'Erro ao conectar com o gateway de pagamento.']);
        }

        $mpData = json_decode($mpResponse, true);

        if ($mpHttpCode >= 200 && $mpHttpCode < 300 && !empty($mpData['init_point'])) {
            // Update pedido with gateway info
            try {
                $pdo->prepare("UPDATE pedidos SET gateway_id = ?, gateway_json = ? WHERE id = ?")
                    ->execute([$mpData['id'] ?? '', json_encode($mpData), $pedidoId]);
            } catch (Exception $e) {}

            registrarLog($pdo, 'info', 'gerar_pagamento', "Pedido #$pedidoId criado (API MP, plano $plano)", $user['id']);

            // Check for PIX data
            if (!empty($mpData['point_of_interaction']['transaction_data']['qr_code'])) {
                $pixCode = $mpData['point_of_interaction']['transaction_data']['qr_code'];
                $pixQr = $mpData['point_of_interaction']['transaction_data']['qr_code_base64'] ?? '';
                jsonResponse([
                    'status'         => 'sucesso',
                    'pedido_id'      => $pedidoId,
                    'pix_copia_cola' => $pixCode,
                    'pix_qr_base64'  => $pixQr,
                    'link'           => $mpData['init_point']
                ]);
            }

            jsonResponse([
                'status'    => 'sucesso',
                'link'      => $mpData['init_point'],
                'pedido_id' => $pedidoId
            ]);
        } else {
            registrarLog($pdo, 'error', 'gerar_pagamento', "MP HTTP $mpHttpCode: $mpResponse", $user['id']);
            jsonResponse(['status' => 'erro', 'msg' => 'Erro ao gerar link de pagamento. Tente novamente.']);
        }
    }

    // No payment gateway configured
    jsonResponse(['status' => 'erro', 'msg' => 'Nenhum gateway de pagamento configurado. Contate o suporte.']);
}

// ================================================================
// VERIFICAR PAGAMENTO (verificar_pagamento)
// Polling do status de um pedido
// ================================================================
if ($acao === 'verificar_pagamento'){
    $pedidoId = (int)($request['pedido_id'] ?? 0);

    if ($pedidoId <= 0) {
        jsonResponse(['status' => 'erro', 'msg' => 'ID de pedido inválido.']);
    }

    try {
        $stmt = $pdo->prepare("SELECT status FROM pedidos WHERE id = ?");
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            jsonResponse(['status' => 'nao_encontrado']);
        }

        jsonResponse(['status' => $pedido['status'] === 'aprovado' ? 'aprovado' : 'pendente']);
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao verificar pagamento.']);
    }
}

// ================================================================
// IA — SUGESTÕES DE MELHORIA (ai_suggest_improvements)
// ================================================================
if ($acao === 'ai_suggest_improvements'){
    $email = sanitizeEmail($request['email'] ?? '');
    $senha = $request['senha'] ?? '';

    if (!$email || !$senha) {
        jsonResponse(['status' => 'erro', 'msg' => 'Faça login para usar a IA.']);
    }

    // Validate user
    try {
        $stmt = $pdo->prepare("SELECT id, senha_hash, ativo FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha_hash'])) {
            jsonResponse(['status' => 'erro', 'msg' => 'Credenciais inválidas.']);
        }

        if (!$user['ativo']) {
            jsonResponse(['status' => 'erro', 'msg' => 'Conta desativada.']);
        }
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao validar usuário.']);
    }

    $texto = trim($request['texto'] ?? '');
    $tipo  = trim($request['tipo'] ?? 'resumo');

    if (!$texto) {
        jsonResponse(['status' => 'erro', 'msg' => 'Texto vazio.']);
    }

    // Load LLM config
    try {
        $cfgKeys = ['llm_enabled', 'llm_provider', 'llm_model', 'llm_endpoint', 'llm_api_key'];
        $placeholders = implode(',', array_fill(0, count($cfgKeys), '?'));
        $stmtCfg = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ($placeholders)");
        $stmtCfg->execute($cfgKeys);
        $llmCfg = [];
        while ($row = $stmtCfg->fetch()) {
            $llmCfg[$row['chave']] = $row['valor'];
        }
    } catch (Exception $e) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro ao carregar configuração da IA.']);
    }

    if (empty($llmCfg['llm_enabled']) || $llmCfg['llm_enabled'] !== '1') {
        jsonResponse(['status' => 'erro', 'msg' => 'IA desativada.']);
    }

    $apiKey = $llmCfg['llm_api_key'] ?? '';
    if (!$apiKey) {
        jsonResponse(['status' => 'erro', 'msg' => 'Chave de API da IA não configurada.']);
    }

    $endpoint = $llmCfg['llm_endpoint'] ?? 'https://api.openai.com/v1/chat/completions';
    $model    = $llmCfg['llm_model'] ?? 'gpt-4o-mini';
    $provider = $llmCfg['llm_provider'] ?? 'openai';

    $systemPrompt = 'Você é um consultor de carreira. Analise o texto de currículo fornecido e retorne APENAS um JSON válido (sem markdown) com a estrutura: {"sugestoes":["sugestão 1","sugestão 2","sugestão 3"]} contendo 3-5 sugestões práticas de melhoria.';

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => "Analise este $tipo de currículo e sugira melhorias:\n\n$texto"]
    ];

    $payload = json_encode([
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.4,
        'max_tokens'  => 1000
    ], JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];

    if ($provider === 'gemini') {
        $endpoint = str_contains($endpoint, '?') ? $endpoint : $endpoint . '?key=' . $apiKey;
        $headers = ['Content-Type: application/json'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        jsonResponse(['status' => 'erro', 'msg' => 'Erro na API da IA.']);
    }

    $decoded = json_decode($response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';

    if (!$content) {
        jsonResponse(['status' => 'erro', 'msg' => 'IA não retornou conteúdo.']);
    }

    $content = preg_replace('/^```json\s*/i', '', trim($content));
    $content = preg_replace('/\s*```$/i', '', $content);

    $result = json_decode($content, true);
    if (!$result || !isset($result['sugestoes'])) {
        jsonResponse(['status' => 'erro', 'msg' => 'Formato de resposta inválido.']);
    }

    jsonResponse(['status' => 'sucesso', 'result' => $result]);
}

// ================================================================
// WEBHOOK MERCADO PAGO (webhook_mp)
// Recebe notificações de pagamento do Mercado Pago
// ================================================================
if ($acao === 'webhook_mp'){
    $whData = json_decode(file_get_contents('php://input'), true) ?: [];

    $topic = $_GET['topic'] ?? $_GET['type'] ?? $whData['action'] ?? $whData['type'] ?? '';
    $paymentId = $_GET['id'] ?? $whData['data']['id'] ?? '';

    // Sanitizar $paymentId — deve ser apenas numérico
    $paymentId = preg_replace('/[^0-9]/', '', (string)$paymentId);

    if (strpos($topic, 'payment') !== false && $paymentId && ctype_digit($paymentId)) {
        $mpToken = getConfig('mp_access_token', '', $pdo);
        if ($mpToken) {
            $ch = curl_init("https://api.mercadopago.com/v1/payments/$paymentId");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $mpToken]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $payment = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if (isset($payment['status'])) {
                $status = $payment['status'];
                $liberarPending = getConfig('mp_liberar_pending', '0', $pdo);

                if ($status === 'approved' || ($liberarPending === '1' && ($status === 'pending' || $status === 'in_process'))) {

                    $pedidoIdRaw = $payment['external_reference'] ?? '';
                    $pedidoIdWh = (int) str_replace("KNX_", "", $pedidoIdRaw);

                    if ($pedidoIdWh > 0) {
                        try {
                            $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ? AND status != 'aprovado'");
                            $stmt->execute([$pedidoIdWh]);
                            $pedido = $stmt->fetch();

                            if ($pedido) {
                                $pdo->prepare("UPDATE pedidos SET status = 'aprovado', gateway_id = ? WHERE id = ?")
                                    ->execute([$paymentId, $pedidoIdWh]);
                                $pdo->prepare("UPDATE usuarios SET creditos = creditos + ? WHERE email = ?")
                                    ->execute([$pedido['creditos'], $pedido['email']]);

                                $stmtU = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
                                $stmtU->execute([$pedido['email']]);
                                $uid = $stmtU->fetchColumn();

                                if ($uid) {
                                    try {
                                        $pdo->prepare(
                                            "INSERT INTO transacoes (usuario_id, tipo, quantidade, descricao, referencia, created_at) VALUES (?, 'compra', ?, ?, ?, NOW())"
                                        )->execute([$uid, $pedido['creditos'], "Compra Webhook MP (Plano {$pedido['plano']})", $paymentId]);
                                    } catch (Exception $e) {}
                                }

                                registrarLog($pdo, 'info', 'webhook_mp', "Pagamento aprovado pedido #$pedidoIdWh", $uid ?: null);
                            }
                        } catch (Exception $e) {
                            registrarLog($pdo, 'error', 'webhook_mp', "Erro ao processar pedido #$pedidoIdWh: " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    http_response_code(200);
    echo "OK";
    exit;
}

// ================================================================
// FALLBACK
// ================================================================
jsonResponse([
    'status'=>'erro',
    'msg'=>'Ação inválida'
]);