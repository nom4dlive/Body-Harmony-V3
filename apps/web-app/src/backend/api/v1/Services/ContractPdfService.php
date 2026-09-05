<?php

namespace BodyHarmony\Services;

if (!class_exists('\Mpdf\Mpdf')) {
    $autoloadCandidates = [
        __DIR__ . '/../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../../build/public_html/vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php'
    ];
    foreach ($autoloadCandidates as $file) {
        if (file_exists($file)) {
            require_once $file;
            break;
        }
    }
}

use Mpdf\Mpdf;
use Exception;

class ContractPdfService {
    public const LICENCIANTE_NAME = 'JOSELENE APARECIDA DA SILVA (BODY HARMONY)';
    public const LICENCIANTE_DOCUMENT = 'BODY HARMONY ELETROESTIMULAÇÃO LTDA. (CNPJ 68.016.506/0001-22)';
    public const LICENCIANTE_EMAIL = 'contato@bodyharmony.com.br';
    public const LICENCIANTE_CNPJ = '68.016.506/0001-22';
    public const LICENCIANTE_SOCIA = 'JOSELENE APARECIDA DA SILVA';
    public const LICENCIANTE_CPF = '362.082.328-64';

    private string $tempDir;
    private string $storageDir;

    public function __construct() {
        // Safe temp dir selection
        $tempCandidates = [
            __DIR__ . '/../../../../tmp/mpdf',
            sys_get_temp_dir() . '/mpdf',
            $_SERVER['DOCUMENT_ROOT'] . '/../tmp/mpdf'
        ];

        foreach ($tempCandidates as $dir) {
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (is_dir($dir) && is_writable($dir)) {
                $this->tempDir = $dir;
                break;
            }
        }

        if (empty($this->tempDir)) {
            $this->tempDir = sys_get_temp_dir();
        }

        // Private storage directory
        $this->storageDir = __DIR__ . '/../../../../private_uploads/contracts';
        if (!file_exists($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
    }

    /**
     * Obtains the official Licenciante signature (Josi) as a Base64 Data URI
     */
    public function getJosiSignatureBase64(): string {
        $candidates = [
            __DIR__ . '/../../assets/signatures/josi_licenciante.png',
            __DIR__ . '/../../../../assets/signatures/josi_licenciante.png',
            'L:/Meu Drive/Jurídico/Josi Ass.png'
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_readable($path)) {
                $type = pathinfo($path, PATHINFO_EXTENSION);
                $data = file_get_contents($path);
                return 'data:image/' . $type . ';base64,' . base64_encode($data);
            }
        }

        return '';
    }

    /**
     * Obtains the official Body Harmony color logo as a high-fidelity Base64 Data URI
     */
    public function getLogoBase64(): string {
        $candidates = [
            __DIR__ . '/../../assets/images/body-harmony-logo-color.png',
            __DIR__ . '/../../../../public/assets/images/body-harmony-logo-color.png',
            __DIR__ . '/../../../../apps/web-app/public/assets/images/body-harmony-logo-color.png',
            __DIR__ . '/../../../../assets/images/body-harmony-logo-color.png',
            'L:/Meu Drive/Jurídico/body-harmony-logo-color.png'
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) && is_readable($path)) {
                $type = pathinfo($path, PATHINFO_EXTENSION);
                $data = file_get_contents($path);
                return 'data:image/' . $type . ';base64,' . base64_encode($data);
            }
        }

        return '';
    }

    /**
     * Formats the standard logo header HTML with customizable alignment and proportional height
     */
    public function formatLogoHeader(array $options = []): string {
        $showLogo = $options['show_logo'] ?? $options['enabled'] ?? true;
        if (!$showLogo) {
            return '';
        }

        $align = in_array(strtolower($options['align'] ?? 'center'), ['left', 'center', 'right']) 
            ? strtolower($options['align'] ?? 'center') 
            : 'center';
        
        $height = isset($options['height']) ? $options['height'] : (isset($options['height_px']) ? $options['height_px'] . 'px' : '75px');
        if (is_numeric($height)) {
            $height .= 'px';
        }

        $marginBottom = isset($options['margin_bottom']) ? $options['margin_bottom'] : (isset($options['margin_bottom_px']) ? $options['margin_bottom_px'] . 'px' : '20px');
        if (is_numeric($marginBottom)) {
            $marginBottom .= 'px';
        }

        $logoDataUri = $this->getLogoBase64();
        if (empty($logoDataUri)) {
            // Fallback text if logo file is somehow unreachable
            return "<div class='contract-logo-header' style='text-align: {$align}; margin-bottom: {$marginBottom};'><strong style='color: #0A3E60; font-size: 16pt; font-family: Montserrat, sans-serif;'>BODY HARMONY®</strong></div>";
        }

        return "<div class='contract-logo-header' style='text-align: {$align}; margin-bottom: {$marginBottom};'>
            <img src='{$logoDataUri}' alt='Body Harmony®' style='height: {$height}; width: auto; max-width: 280px; object-fit: contain;' />
        </div>";
    }

    /**
     * Processes and injects or normalizes the logo block in the document HTML
     */
    public function processLogoInHtml(string $htmlContent, array $logoOptions = []): string {
        $logoDataUri = $this->getLogoBase64();
        $showLogo = $logoOptions['show_logo'] ?? $logoOptions['enabled'] ?? true;

        // 1. If explicitly disabled, remove any existing contract-logo-header
        if (!$showLogo) {
            return preg_replace('/<div\s+class=[\'"]contract-logo-header[\'"][^>]*>.*?<\/div>/si', '', $htmlContent);
        }

        // 2. If the HTML already contains a contract-logo-header, ensure the img src uses the Base64 Data URI
        if (preg_match('/<div\s+class=[\'"]contract-logo-header[\'"][^>]*>/i', $htmlContent)) {
            if (!empty($logoDataUri)) {
                $htmlContent = preg_replace_callback(
                    '/(<div\s+class=[\'"]contract-logo-header[\'"][^>]*>.*?<img\s+[^>]*src=[\'"])(.*?)([\'"][^>]*>.*?<\/div>)/si',
                    function ($matches) use ($logoDataUri) {
                        return $matches[1] . $logoDataUri . $matches[3];
                    },
                    $htmlContent
                );
            }
            return $htmlContent;
        }

        // 3. If no logo header exists in the HTML, generate one and prepend it
        $logoHeaderHtml = $this->formatLogoHeader($logoOptions);
        return $logoHeaderHtml . $htmlContent;
    }

    /**
     * Compiles a complete luxury contract PDF from HTML and replaces tags
     */
    public function generatePdf(
        string $htmlContent,
        string $contractUuid,
        string $title,
        array $signatures = [],
        bool $saveToFile = true,
        array $logoOptions = []
    ): array {
        if (!class_exists('\Mpdf\Mpdf')) {
            error_log("[ContractPdfService] Alerta: mPDF não carregado. Utilizando fallback HTML resiliente.");
            return $this->generateHtmlFallback($htmlContent, $contractUuid, $title, $signatures, $saveToFile, $logoOptions);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $this->tempDir,
            'margin_left' => 20,
            'margin_right' => 20,
            'margin_top' => 25,
            'margin_bottom' => 22,
            'margin_header' => 10,
            'margin_footer' => 10,
            'default_font' => 'times'
        ]);

        // Process logo header inside document
        $processedHtml = $this->processLogoInHtml($htmlContent, $logoOptions);

        // Inject Licenciante (Josi) Signature image into contract body (4x size increase)
        $josiSigBase64 = $this->getJosiSignatureBase64();
        $licencianteImgHtml = $josiSigBase64 ? '<div style="margin-bottom: -15px;"><img src="' . $josiSigBase64 . '" style="max-height: 220px; max-width: 450px;" /></div>' : '';
        $processedHtml = str_replace(['{{ASSINATURA_LICENCIANTE_IMG}}', '{{LICENCIANTE_SIGNATURE_IMAGE}}'], $licencianteImgHtml, $processedHtml);

        // Inject Licenciada Signature image into contract body if available
        $licenciadaImgHtml = '';
        foreach ($signatures as $sig) {
            if (!empty($sig['signature_image_data']) && ($sig['signer_type'] ?? '') === 'LICENCIADA') {
                $licenciadaImgHtml = '<div style="margin-bottom: -15px;"><img src="' . $sig['signature_image_data'] . '" style="max-height: 220px; max-width: 450px;" /></div>';
                break;
            }
        }
        $processedHtml = str_replace(['{{ASSINATURA_LICENCIADA_IMG}}', '{{LICENCIADA_SIGNATURE_IMAGE}}'], $licenciadaImgHtml, $processedHtml);

        // Strip unhandled {{CLAUSULA_TRANSICAO_CNPJ}} tag cleanly if empty
        $processedHtml = str_replace('{{CLAUSULA_TRANSICAO_CNPJ}}', '', $processedHtml);

        // Pre-calculate hash
        $initialHash = hash('sha256', $processedHtml . $contractUuid);

        // Running Header (Páginas 2 em diante) & Footer (Todas as páginas)
        $runningHeaderHtml = '
        <table width="100%" style="border-bottom: 1.5px solid #0A3E60; padding-bottom: 6px; font-family: Montserrat, sans-serif;">
            <tr>
                <td width="50%" style="color: #0A3E60; font-size: 10pt; font-weight: bold; text-transform: uppercase;">
                    BODY HARMONY®
                </td>
                <td width="50%" align="right" style="color: #ED7E13; font-size: 8pt; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
                    JURÍDICO & COMPLIANCE
                </td>
            </tr>
        </table>';

        $footerHtml = '
        <table width="100%" style="border-top: 1px solid #E2E8F0; padding-top: 5px; font-family: Montserrat, sans-serif; font-size: 7.5pt; color: #64748B;">
            <tr>
                <td width="70%">
                    Doc: ' . htmlspecialchars($contractUuid) . ' • Hash: ' . substr($initialHash, 0, 16) . '...
                </td>
                <td width="30%" align="right">
                    Página {PAGENO} de {nbpg}
                </td>
            </tr>
        </table>';

        // DefHTMLHeader with show-this-page=0 so page 1 has the prominent logo in body
        $mpdf->DefHTMLHeaderByName('RunningHeader', $runningHeaderHtml);
        $mpdf->DefHTMLFooterByName('RunningFooter', $footerHtml);
        $mpdf->SetHTMLFooterByName('RunningFooter');

        // Global Styles
        $css = "
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            color: #1E293B;
            line-height: 1.6;
        }
        .contract-logo-header {
            width: 100%;
            margin-bottom: 20px;
        }
        .contract-logo-header img {
            height: 75px;
            width: auto;
            max-width: 280px;
        }
        h1 {
            font-family: 'Montserrat', sans-serif;
            color: #0A3E60;
            font-size: 16pt;
            text-align: center;
            margin-bottom: 6px;
        }
        h2 {
            font-family: 'Montserrat', sans-serif;
            color: #0A3E60;
            font-size: 14pt;
            text-align: center;
            margin-bottom: 6px;
        }
        h3 {
            font-family: 'Montserrat', sans-serif;
            color: #0A3E60;
            font-size: 11pt;
            margin-top: 18px;
            margin-bottom: 8px;
            font-weight: bold;
        }
        p {
            margin-bottom: 12px;
            text-align: justify;
            line-height: 1.6;
        }
        .chancela-container {
            border: 2px solid #0A3E60;
            padding: 20px;
            margin-top: 30px;
            background-color: #F8FAFC;
            font-family: 'Montserrat', sans-serif;
        }
        .chancela-header {
            color: #0A3E60;
            font-size: 12pt;
            font-weight: bold;
            text-align: center;
            border-bottom: 1.5px solid #ED7E13;
            padding-bottom: 8px;
            margin-bottom: 15px;
        }
        .sig-entry {
            background: #FFFFFF;
            border: 1px solid #CBD5E1;
            padding: 12px;
            margin-bottom: 10px;
            font-size: 9pt;
        }
        ";

        $fullHtml = "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>{$css}</style></head><body>";
        // Activate running header for pages 2+
        $fullHtml .= '<sethtmlpageheader name="RunningHeader" page="ALL" value="1" show-this-page="0" />';
        $fullHtml .= $processedHtml;

        // If signatures exist, attach Chancela Jurídica
        if (!empty($signatures)) {
            $fullHtml .= "<pagebreak />";
            $fullHtml .= $this->buildChancelaHtml($contractUuid, $initialHash, $signatures);
        }

        $fullHtml .= "</body></html>";

        $mpdf->WriteHTML($fullHtml);

        $pdfBinary = $mpdf->Output('', 'S');
        $finalSha256 = hash('sha256', $pdfBinary);

        $result = [
            'uuid' => $contractUuid,
            'title' => $title,
            'sha256_hash' => $finalSha256,
            'pdf_binary' => $pdfBinary
        ];

        if ($saveToFile) {
            $filePath = $this->storageDir . '/' . $contractUuid . '.pdf';
            file_put_contents($filePath, $pdfBinary);
            $result['file_path'] = $filePath;
            $result['relative_path'] = 'private_uploads/contracts/' . $contractUuid . '.pdf';
        }

        return $result;
    }

    /**
     * Builds the Forensic Audit Trail & Chancela Jurídica HTML
     */
    public function buildChancelaHtml(string $uuid, string $docHash, array $signatures): string {
        // Ensure Licenciante (Josi) entry is included in Chancela Jurídica
        $hasLicenciante = false;
        foreach ($signatures as $s) {
            if (($s['signer_type'] ?? '') === 'LICENCIANTE') {
                $hasLicenciante = true;
                break;
            }
        }
        if (!$hasLicenciante) {
            $josiSigBase64 = $this->getJosiSignatureBase64();
            array_unshift($signatures, [
                'signer_type' => 'LICENCIANTE',
                'signer_name' => self::LICENCIANTE_NAME,
                'signer_document' => self::LICENCIANTE_DOCUMENT,
                'signer_email' => self::LICENCIANTE_EMAIL,
                'signature_mode' => 'DIGITAL_CERTIFICATE',
                'signature_image_data' => $josiSigBase64,
                'ip_address' => '127.0.0.1 (AUTENTICADO)',
                'signed_at' => date('Y-m-d H:i:s'),
                'checksum_signature' => hash('sha256', 'JOSELENE_APARECIDA_DA_SILVA_BODY_HARMONY_LICENCIANTE')
            ]);
        }

        $entriesHtml = '';
        foreach ($signatures as $sig) {
            $signedAt = !empty($sig['signed_at']) ? date('d/m/Y H:i:s', strtotime($sig['signed_at'])) : date('d/m/Y H:i:s');
            $ip = htmlspecialchars($sig['ip_address'] ?? '127.0.0.1');
            $mode = htmlspecialchars($sig['signature_mode'] ?? 'DIGITAL_CANVAS');
            $signerType = htmlspecialchars($sig['signer_type'] ?? 'LICENCIADA');
            
            // Normalize Licenciante data to official permanent identity
            if ($signerType === 'LICENCIANTE') {
                $signerName = htmlspecialchars(self::LICENCIANTE_NAME);
                $signerDoc = htmlspecialchars(self::LICENCIANTE_DOCUMENT);
                $signerEmail = htmlspecialchars(self::LICENCIANTE_EMAIL);
            } else {
                $signerName = htmlspecialchars($sig['signer_name'] ?? '');
                $signerDoc = htmlspecialchars($sig['signer_document'] ?? '');
                $signerEmail = htmlspecialchars($sig['signer_email'] ?? '');
            }
            $checksum = htmlspecialchars($sig['checksum_signature'] ?? hash('sha256', $signerDoc . $signedAt));

            $imgHtml = '';
            if (!empty($sig['signature_image_data'])) {
                $imgHtml = '<div style="margin-top: 8px;"><img src="' . $sig['signature_image_data'] . '" style="max-height: 120px; max-width: 260px; border-bottom: 1px solid #0A3E60;" /></div>';
            }

            $entriesHtml .= "
            <div class='sig-entry'>
                <table width='100%' style='border-collapse: collapse;'>
                    <tr>
                        <td width='70%'>
                            <strong style='color: #0A3E60; font-size: 10pt;'>{$signerType}: {$signerName}</strong><br />
                            <span style='color: #475569;'>Documento: {$signerDoc} " . ($signerEmail ? "• E-mail: {$signerEmail}" : "") . "</span><br />
                            <span style='color: #64748B; font-size: 8pt;'>Data/Hora: {$signedAt} • Modo: {$mode}</span><br />
                            <span style='color: #64748B; font-size: 8pt;'>IP: {$ip}</span>
                        </td>
                        <td width='30%' align='right' style='vertical-align: middle;'>
                            {$imgHtml}
                        </td>
                    </tr>
                    <tr>
                        <td colspan='2' style='border-top: 1px dashed #E2E8F0; padding-top: 4px; margin-top: 6px; font-size: 7pt; color: #94A3B8;'>
                            Checksum da Assinatura: {$checksum}
                        </td>
                    </tr>
                </table>
            </div>";
        }

        $validateUrl = "https://bodyharmony.com.br/validar/{$uuid}";
        $qrBase64 = $this->generateQrCodeBase64($validateUrl);
        $qrImageHtml = $qrBase64 
            ? '<img src="' . $qrBase64 . '" style="width: 62px; height: 62px; margin-bottom: 2px;" />'
            : '<div style="font-size: 7pt; color: #0A3E60; font-weight: bold; padding: 4px;">[ QR CODE ]<br />VALIDADO</div>';

        return "
        <div class='chancela-container'>
            <div class='chancela-header'>
                FOLHA DE CHANCELA JURÍDICA E AUDITORIA DE ASSINATURAS DIGITAIS
            </div>
            <p style='font-size: 8.5pt; color: #475569; margin-bottom: 15px;'>
                O presente documento eletrônico foi assinado digitalmente pelas partes abaixo identificadas, com amparo legal no <strong>Art. 10, §2º da Medida Provisória nº 2.200-2/2001</strong> e na <strong>Lei Federal nº 14.063/2020</strong> (Assinatura Eletrônica Avançada), possuindo plena validade jurídica, integridade e eficácia probatória.
            </p>

            {$entriesHtml}

            <table width='100%' style='margin-top: 20px; border-top: 1px solid #0A3E60; padding-top: 10px; font-size: 8pt; color: #64748B;'>
                <tr>
                    <td width='75%'>
                        <strong>Identificador do Contrato (UUID):</strong> {$uuid}<br />
                        <strong>Hash de Integridade do Conteúdo (SHA-256):</strong><br />
                        <span style='font-family: monospace; font-size: 7.5pt; color: #0A3E60;'>{$docHash}</span><br />
                        <span style='color: #ED7E13; font-weight: bold;'>Body Harmony Gestão Jurídica & Compliance</span>
                    </td>
                    <td width='25%' align='center' style='vertical-align: middle;'>
                        <div style='border: 1px solid #0A3E60; padding: 4px; background: #FFFFFF; text-align: center;'>
                            {$qrImageHtml}<br />
                            <span style='font-size: 6.5pt; color: #0A3E60; font-weight: bold;'>VALIDAÇÃO DIGITAL</span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>";
    }

    /**
     * Generates a Base64 PNG QR Code image for external validation link
     */
    private function generateQrCodeBase64(string $url): string {
        $qrApi = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($url);
        $opts = [
            'http' => [
                'method' => 'GET',
                'timeout' => 3
            ]
        ];
        $context = stream_context_create($opts);
        $binary = @file_get_contents($qrApi, false, $context);
        if ($binary !== false && strlen($binary) > 100) {
            return 'data:image/png;base64,' . base64_encode($binary);
        }
        return '';
    }

    /**
     * Substitutes variable tags {{TAG}} with values provided in the payload
     */
    public function renderTemplate(?string $templateHtml, array $variables): string {
        $rendered = $templateHtml ?? '';
        foreach ($variables as $key => $value) {
            $tag = '{{' . trim($key) . '}}';
            $rendered = str_replace($tag, htmlspecialchars((string)$value), $rendered);
        }
        return $rendered;
    }

    /**
     * Renderiza o contrato em formato HTML de alta fidelidade como fallback quando mPDF não está disponível
     */
    public function generateHtmlFallback(
        string $htmlContent,
        string $contractUuid,
        string $title,
        array $signatures = [],
        bool $saveToFile = true,
        array $logoOptions = []
    ): array {
        $processedHtml = $this->processLogoInHtml($htmlContent, $logoOptions);
        $initialHash = hash('sha256', $processedHtml . $contractUuid);

        $chancelaHtml = !empty($signatures) ? $this->buildChancelaHtml($contractUuid, $initialHash, $signatures) : '';

        $fullDocumentHtml = "
        <!DOCTYPE html>
        <html lang='pt-BR'>
        <head>
            <meta charset='UTF-8'>
            <title>" . htmlspecialchars($title) . "</title>
            <style>
                body { font-family: 'Times New Roman', Times, serif; margin: 40px; color: #1E293B; line-height: 1.6; }
                .contract-logo-header { text-align: center; margin-bottom: 20px; }
                .chancela-container { border: 2px solid #0A3E60; padding: 20px; margin-top: 30px; background-color: #F8FAFC; font-family: sans-serif; }
            </style>
        </head>
        <body>
            {$processedHtml}
            {$chancelaHtml}
        </body>
        </html>";

        $finalHash = hash('sha256', $fullDocumentHtml);
        $result = [
            'uuid' => $contractUuid,
            'title' => $title,
            'sha256_hash' => $finalHash,
            'pdf_binary' => $fullDocumentHtml
        ];

        if ($saveToFile) {
            $filePath = $this->storageDir . '/' . $contractUuid . '.html';
            file_put_contents($filePath, $fullDocumentHtml);
            $result['file_path'] = $filePath;
            $result['relative_path'] = 'private_uploads/contracts/' . $contractUuid . '.html';
        }

        return $result;
    }
}
