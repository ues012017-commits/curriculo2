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

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['status' => 'erro', 'msg' => 'Método não permitido.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['html'])) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['status' => 'erro', 'msg' => 'HTML do currículo não fornecido.']);
    exit;
}

$htmlContent = $input['html'];
$cssVars = $input['cssVars'] ?? [];
$nome = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $input['nome'] ?? 'Curriculo');

// Sanitize HTML content: strip dangerous tags (script, iframe, etc.) while preserving layout tags
$allowedTags = '<div><span><p><br><img><strong><b><em><i><u><h1><h2><h3><h4><h5><h6><ul><ol><li><table><tr><td><th><thead><tbody><a><hr><section><article><header><footer><nav><main>';
$htmlContent = strip_tags($htmlContent, $allowedTags);
// Remove any event handler attributes (onclick, onerror, onload, etc.)
$htmlContent = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $htmlContent);
// Remove javascript: URLs
$htmlContent = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', '', $htmlContent);

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
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { margin: 0; padding: 0; background: #ffffff; color: #1e293b; font-family: ' . htmlspecialchars($cssVars['--user-font'] ?? "'Inter', sans-serif", ENT_QUOTES, 'UTF-8') . '; }

  /* CV Content Reset */
  .cv-content { display: block; width: 210mm; min-height: 297mm; box-sizing: border-box; font-size: calc(11px * var(--user-scale, 1)); line-height: 1.5; font-family: var(--user-font); padding: var(--cv-margin, 40px); background: #ffffff; color: #1e293b; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word; }
  .cv-content * { box-sizing: border-box; max-width: 100%; }
  .palette-custom { --prim: var(--user-color); --sec: var(--secondary); --txt-h: var(--user-color); --txt-main: var(--user-font-color); }

  /* Typography */
  .cv-name { font-size: calc(2.2em * var(--title-scale, 1)); font-weight: 900; line-height: 1.1; margin-bottom: .2em; text-transform: uppercase; color: var(--txt-h); }
  .cv-role { font-size: calc(1.3em * var(--title-scale, 1)); font-weight: 600; color: var(--prim); margin-bottom: .8em; text-transform: uppercase; letter-spacing: 1px; }
  .cv-contact { font-size: calc(0.9em * var(--text-scale, 1)); margin-bottom: .5em; color: var(--txt-main); }
  .item-box { margin-bottom: var(--cv-spacing, 15px); }
  .item-title { font-weight: 700; font-size: calc(1.1em * var(--title-scale, 1)); color: var(--txt-main); }
  .item-sub { font-size: calc(0.9em * var(--text-scale, 1)); font-style: italic; color: var(--txt-main); opacity: .8; margin-top: .2em; }
  .item-meta { font-size: calc(0.85em * var(--text-scale, 1)); color: var(--txt-main); opacity: .75; margin-top: .2em; }
  .item-desc { margin-top: .3em; font-size: calc(1em * var(--text-scale, 1)); white-space: pre-wrap; text-align: justify; color: var(--txt-main); }
  [class*="var-"] .section-title { font-size: calc(1.2em * var(--title-scale, 1)); font-weight: 800; text-transform: uppercase; color: var(--prim); margin-top: calc(var(--cv-spacing, 15px) * 1.5); margin-bottom: calc(var(--cv-spacing, 15px) * 0.8); }

  /* Section Title Styles */
  .var-1 .section-title { border-bottom: 2px solid var(--prim); padding-bottom: 5px; }
  .var-2 .section-title { background: var(--prim); color: #fff; padding: 5px 10px; border-radius: 5px; display: inline-block; }
  .var-3 .section-title { text-align: center; }
  .var-4 .section-title { border-left: 4px solid var(--prim); padding-left: 10px; }
  .var-5 .section-title { border-top: 1px solid var(--prim); border-bottom: 1px solid var(--prim); padding: 5px 0; text-align: center; }
  .var-6 .section-title { font-style: italic; border-bottom: 1px dashed var(--prim); }
  .var-7 .section-title { text-decoration: underline; text-decoration-color: var(--prim); text-decoration-thickness: 3px; }
  .var-8 .section-title { background: linear-gradient(90deg, var(--prim), transparent); color: #fff; padding: 5px 10px; display: inline-block; }
  .var-9 .section-title { letter-spacing: 3px; border-bottom: 1px solid rgba(0,0,0,0.1); }

  /* Layout System */
  .layout-classic { padding: var(--cv-margin, 40px); }
  .layout-geo { padding: 0 !important; }
  .layout-geo .header-bg { background: var(--prim); height: auto; min-height: 180px; clip-path: polygon(0 0, 100% 0, 100% 85%, 0 100%); color: var(--sidebar-text-color, #ffffff); padding: var(--cv-margin, 40px); padding-bottom: calc(var(--cv-margin, 40px) + 20px); }
  .layout-geo .main-content { padding: 0 var(--cv-margin, 40px) var(--cv-margin, 40px) var(--cv-margin, 40px); margin-top: 10px; }
  .layout-minimal { padding: calc(var(--cv-margin, 40px) * 1.5); text-align: center; }
  .layout-boxed { padding: calc(var(--cv-margin, 40px) * 0.7); background: #fff; }
  .layout-boxed .inner-border { border: 2px solid var(--prim); padding: var(--cv-margin, 40px); height: 100%; border-radius: 8px; }

  /* Sidebar text colors */
  .layout-geo .header-bg .cv-name, .layout-geo .header-bg .cv-role, .layout-geo .header-bg .cv-contact { color: var(--sidebar-text-color, #ffffff) !important; }

  .cv-photo { object-fit: cover; }
  img { max-width: 100%; height: auto; }
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
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['status' => 'erro', 'msg' => 'Erro ao gerar PDF: ' . $e->getMessage()]);
        exit;
    }
}

// Option 2: If no PDF library is available, return error
header('Content-Type: application/json');
http_response_code(501);
echo json_encode([
    'status' => 'erro',
    'msg' => 'Nenhuma biblioteca de PDF configurada no servidor. Instale dompdf via Composer: composer require dompdf/dompdf'
]);
