<?php
// ================================================================
// KONEX API - VERSÃO FINAL LIMPA
// ================================================================

// DEBUG (pode desligar mudando para 0 em produção)
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

// Lidar com requisições de pré-verificação (CORS Preflight)
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
// VALIDAR TOKEN ADMIN
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
// STATS SIMPLES
// ================================================================
if ($acao === 'admin_stats'){
    $usuarios = 0;
    $pedidos = 0;

    try {
        $usuarios = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    } catch(Exception $e){}

    try {
        $pedidos = $pdo->query("SELECT COUNT(*) FROM pedidos")->fetchColumn();
    } catch(Exception $e){}

    jsonResponse([
        'status'=>'sucesso',
        'usuarios'=>$usuarios,
        'pedidos'=>$pedidos
    ]);
}

// ================================================================
// FALLBACK
// ================================================================
jsonResponse([
    'status'=>'erro',
    'msg'=>'Ação inválida'
]);