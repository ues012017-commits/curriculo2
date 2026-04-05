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
    jsonResponse([
        'status' => 'sucesso',
        'msg' => 'API OK',
        'banco' => 'conectado'
    ]);
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
// FALLBACK
// ================================================================
jsonResponse([
    'status'=>'erro',
    'msg'=>'Ação inválida'
]);