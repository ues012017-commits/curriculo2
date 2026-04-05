<?php
// =================================================================
//  KONEX CREATIVE — FUNÇÕES AUXILIARES
//  Responsabilidades: logging, sanitização, rate limiting, segurança
// =================================================================

/**
 * Registra uma ação no sistema de logs (tabela `logs`).
 *
 * @param PDO    $pdo       Conexão PDO
 * @param string $nivel     'info', 'warning' ou 'error'
 * @param string $acao      Nome da ação (ex: 'login_falha', 'admin_login')
 * @param string $detalhes  Detalhes adicionais
 * @param int|null $usuario_id  ID do usuário (ou null)
 * @param string|null $ip   IP do cliente (ou null para auto-detect)
 */
function registrarLog(PDO $pdo, string $nivel, string $acao, string $detalhes = '', ?int $usuario_id = null, ?string $ip = null): void
{
    try {
        if ($ip === null) {
            $ip = getClientIP();
        }
        $stmt = $pdo->prepare(
            "INSERT INTO logs (nivel, acao, detalhes, usuario_id, ip, created_at) VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$nivel, substr($acao, 0, 80), substr($detalhes, 0, 5000), $usuario_id, $ip]);
    } catch (Exception $e) {
        // Falha no log não deve quebrar a aplicação
    }
}

/**
 * Obtém o IP real do cliente, levando em conta proxies.
 * Valida com FILTER_VALIDATE_IP para evitar spoofing.
 */
function getClientIP(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $candidate = filter_var(trim($forwarded[0]), FILTER_VALIDATE_IP);
        if ($candidate !== false) {
            $ip = $candidate;
        }
    }

    return $ip;
}

/**
 * Rate limiting baseado em IP e ação.
 * Usa a tabela `logs` para contar tentativas recentes.
 *
 * @param PDO    $pdo          Conexão PDO
 * @param string $acao         Nome da ação para limitar
 * @param int    $maxTentativas  Máximo de tentativas no intervalo
 * @param int    $intervaloMin   Intervalo em minutos
 * @param string|null $ip      IP (auto-detect se null)
 * @return bool  True se excedeu o limite
 */
function rateLimitExcedido(PDO $pdo, string $acao, int $maxTentativas = 5, int $intervaloMin = 15, ?string $ip = null): bool
{
    try {
        if ($ip === null) {
            $ip = getClientIP();
        }
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM logs WHERE acao = ? AND ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$acao, $ip, $intervaloMin]);
        return (int)$stmt->fetchColumn() >= $maxTentativas;
    } catch (Exception $e) {
        return false; // Na dúvida, permite (não bloqueia por erro de DB)
    }
}

/**
 * Sanitiza uma string para uso seguro — escapa caracteres especiais HTML e trim.
 */
function sanitizeString(string $input, int $maxLength = 255): string
{
    $input = trim($input);
    $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($maxLength > 0 && mb_strlen($input) > $maxLength) {
        $input = mb_substr($input, 0, $maxLength);
    }
    return $input;
}

/**
 * Sanitiza um e-mail: trim, lowercase e valida formato.
 *
 * @return string|false  E-mail limpo ou false se inválido
 */
function sanitizeEmail(string $email)
{
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return $email;
}

/**
 * Sanitiza um CPF: remove tudo que não for dígito.
 */
function sanitizeCPF(string $cpf): string
{
    return preg_replace('/[^0-9]/', '', $cpf);
}

/**
 * Valida se uma senha atende aos requisitos mínimos.
 */
function validarSenha(string $senha, int $minLength = 6): bool
{
    return strlen($senha) >= $minLength;
}

/**
 * Verifica se a requisição contém campos obrigatórios.
 *
 * @param array  $request   Dados da requisição
 * @param array  $campos    Lista de campos obrigatórios
 * @return string|null  Mensagem de erro ou null se OK
 */
function validarCamposObrigatorios(array $request, array $campos): ?string
{
    foreach ($campos as $campo) {
        if (empty(trim($request[$campo] ?? ''))) {
            return "O campo '{$campo}' é obrigatório.";
        }
    }
    return null;
}

/**
 * Limpa sessões admin expiradas.
 */
function limparSessoesExpiradas(PDO $pdo): void
{
    try {
        $pdo->exec("DELETE FROM admin_sessions WHERE expires_at < NOW()");
    } catch (Exception $e) {
        // silencioso
    }
}