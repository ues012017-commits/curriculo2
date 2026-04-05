<?php
/**
 * gerar_pdf.php — Backend PDF Generator for Konex Creative
 * 
 * Receives compiled HTML + CSS variables via POST and generates a PDF.
 * Requires a PDF rendering library (e.g., Dompdf, wkhtmltopdf, or Puppeteer).
 * 
 * Expected POST body (JSON):
 * {
 *   "html": "<div class='cv-content'>...</div>",
 *   "cssVars": { "--user-font": "...", "--cv-margin": "40px", ... },
 *   "email": "user@email.com",
 *   "nome": "Full Name"
 * }
 */

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'erro', 'msg' => 'Método não permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['html'])) {
    http_response_code(400);
    echo json_encode(['status' => 'erro', 'msg' => 'HTML do currículo não fornecido.']);
    exit;
}

$htmlContent = $input['html'];
$cssVars = $input['cssVars'] ?? [];
$nome = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $input['nome'] ?? 'Curriculo');

// Build CSS variables string for the document
$cssVarStr = '';
foreach ($cssVars as $prop => $val) {
    // Sanitize property names to only allow CSS custom property format
    if (preg_match('/^--[a-zA-Z0-9_-]+$/', $prop)) {
        $cssVarStr .= htmlspecialchars($prop, ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '; ';
    }
}

// Build full HTML document for PDF rendering
$fullHtml = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
  :root { ' . $cssVarStr . ' }
  @page { size: A4; margin: 0; }
  body { margin: 0; padding: 0; font-family: ' . htmlspecialchars($cssVars['--user-font'] ?? "'Inter', sans-serif", ENT_QUOTES, 'UTF-8') . '; }
  .cv-content { width: 210mm; min-height: 297mm; box-sizing: border-box; }
</style>
</head>
<body>
<div style="' . htmlspecialchars($cssVarStr, ENT_QUOTES, 'UTF-8') . '">' . $htmlContent . '</div>
</body>
</html>';

// ─── PDF GENERATION ───
// This section requires a PDF library to be installed.
// Supported options (uncomment the one you have installed):

// Option 1: Dompdf (composer require dompdf/dompdf)
if (class_exists('Dompdf\Dompdf') || file_exists(__DIR__ . '/vendor/autoload.php')) {
    try {
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
        }
        
        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => true,
            'defaultFont' => 'sans-serif',
        ]);
        $dompdf->loadHtml($fullHtml);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Output PDF as download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Curriculo_' . $nome . '.pdf"');
        echo $dompdf->output();
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'erro', 'msg' => 'Erro ao gerar PDF: ' . $e->getMessage()]);
        exit;
    }
}

// Option 2: If no PDF library is available, return error
http_response_code(501);
echo json_encode([
    'status' => 'erro',
    'msg' => 'Nenhuma biblioteca de PDF configurada no servidor. Instale dompdf via Composer: composer require dompdf/dompdf'
]);
