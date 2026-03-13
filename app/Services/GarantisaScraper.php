<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

class GarantisaScraper
{
    private Client $client;
    private CookieJar $cookies;
    private ScraperLogger $logger;
    private string $baseUrl = 'https://garantisa.mcnoc.co/interna';
    private bool $loggedIn = false;

    // Tokens ASP.NET de la última página cargada
    private string $viewState = '';
    private string $viewStateGenerator = '';
    private string $eventValidation = '';

    // Datos del último resultado de búsqueda
    private ?array $lastSearchResult = null;
    private string $lastMasterPageHtml = '';

    private array $accionMap = [
        'CANCELADO'          => '(CANC) Cancelado',
        'CANC'               => '(CANC) Cancelado',
        'EXCLUIR DE GESTION' => '(EXGT) Excluir de Gestion',
        'EXGT'               => '(EXGT) Excluir de Gestion',
        'FALLECIDO'          => '(FLL) Fallecido',
        'FLL'                => '(FLL) Fallecido',
        'ILOCALIZADO'        => '(IL) Ilocalizado',
        'IL'                 => '(IL) Ilocalizado',
        'LOCALIZADO'         => '(LOC) LOCALIZADO',
        'LOC'                => '(LOC) LOCALIZADO',
        'SIN CONTACTO'       => '(SC) SIN CONTACTO',
        'SC'                 => '(SC) SIN CONTACTO',
    ];

    private array $resultadoMap = [
        'CONTACTO CON TERCERO' => 'CT',
        'CT'                   => 'CT',
        'CORREO DE VOZ'        => 'CVOZ',
        'CVOZ'                 => 'CVOZ',
        'NO CONTESTA'          => 'NCT',
        'NCT'                  => 'NCT',
        'NUMERO NO PERTENECE'  => 'NPT',
        'NPT'                  => 'NPT',
    ];

    public function __construct()
    {
        $this->cookies = new CookieJar();
        $this->logger = new ScraperLogger();

        $this->client = new Client([
            'cookies' => $this->cookies,
            'verify'  => false,
            'timeout' => 60,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'es-CO,es;q=0.9',
            ],
        ]);

        $this->logger->step('INIT', 'Scraper inicializado');
    }

    public function getLogSessionId(): string
    {
        return $this->logger->getSessionId();
    }

    public function getLogFile(): string
    {
        return $this->logger->getLogFile();
    }

    public function writeSummary(int $total, int $success, int $failed): void
    {
        $this->logger->summary($total, $success, $failed);
    }

    // =========================================================================
    //  LOGIN
    // =========================================================================

    public function login(): bool
    {
        $this->logger->separator('LOGIN');

        try {
            // 1) GET Login.aspx para obtener ViewState
            $this->logger->step('LOGIN', 'GET Login.aspx para obtener tokens');
            $this->logger->request('GET', "{$this->baseUrl}/Login.aspx");

            $response = $this->client->get("{$this->baseUrl}/Login.aspx", [
                'http_errors' => false,
            ]);

            $html = (string) $response->getBody();
            $status = $response->getStatusCode();

            $this->logger->response($status, $html, $this->flattenHeaders($response->getHeaders()));
            $this->logCookies();

            if ($status !== 200) {
                $this->logger->step('LOGIN', "✗ GET Login.aspx retornó status {$status}");
                return false;
            }

            // Extraer tokens (login page NO tiene __EVENTVALIDATION, es normal)
            $this->extractTokens($html);

            // 2) POST Login.aspx con credenciales
            $this->logger->step('LOGIN', 'Buscando campos del formulario...');
            $this->logFormFields($html);

            $formParams = [
                '__EVENTTARGET'        => '',
                '__EVENTARGUMENT'      => '',
                '__VIEWSTATE'          => $this->viewState,
                '__VIEWSTATEGENERATOR' => $this->viewStateGenerator,
                '__EVENTVALIDATION'    => $this->eventValidation,
                'txtUsr'               => 'ASESCO',
                'txtPwd'               => 'Ase2025+*',
                'btnLogin'             => 'Ingresar',
                'RadScriptManager1_TSM' => '',
            ];

            $this->logger->step('LOGIN', 'POST Login.aspx con credenciales');
            $logParams = $formParams;
            $logParams['txtPwd'] = '***OCULTO***';
            $this->logger->request('POST', "{$this->baseUrl}/Login.aspx", ['form_params' => $logParams]);

            $response = $this->client->post("{$this->baseUrl}/Login.aspx", [
                'form_params' => $formParams,
                'http_errors' => false,
            ]);

            $html = (string) $response->getBody();
            $status = $response->getStatusCode();

            $this->logger->response($status, $html, $this->flattenHeaders($response->getHeaders()));
            $this->logCookies();

            // Buscar mensajes de error visibles en la respuesta
            $errorMessages = [];
            // Buscar labels de error ASP.NET
            if (preg_match_all('/<span[^>]*class="[^"]*error[^"]*"[^>]*>(.*?)<\/span>/is', $html, $m)) {
                $errorMessages = array_merge($errorMessages, array_filter(array_map('strip_tags', $m[1])));
            }
            // Buscar alertas JavaScript
            if (preg_match_all('/alert\(["\']([^"\']+)["\']\)/i', $html, $m)) {
                $errorMessages = array_merge($errorMessages, $m[1]);
            }
            // Buscar Radalert / Telerik notifications
            if (preg_match_all('/radalert\(["\']([^"\']+)["\']/i', $html, $m)) {
                $errorMessages = array_merge($errorMessages, $m[1]);
            }
            // Buscar divs con display:inline o visible que contengan texto de error
            if (preg_match_all('/<(?:span|div|label)[^>]*(?:color\s*:\s*red|class="[^"]*(?:error|warning|mensaje)[^"]*")[^>]*>(.*?)<\/(?:span|div|label)>/is', $html, $m)) {
                $errorMessages = array_merge($errorMessages, array_filter(array_map('strip_tags', $m[1])));
            }
            // Buscar contenido de Notificacion (RadNotification)
            if (preg_match('/Notificacion.*?content["\s:]+["\']([^"\']+)["\']/is', $html, $m)) {
                $errorMessages[] = $m[1];
            }

            if (!empty($errorMessages)) {
                $this->logger->step('LOGIN', 'MENSAJES DE ERROR ENCONTRADOS: ' . json_encode($errorMessages, JSON_UNESCAPED_UNICODE));
            }

            // Guardar más del body para debug (últimos 3000 chars donde suelen estar los scripts)
            $bodyEnd = substr($html, -3000);
            $this->logger->step('LOGIN', 'FINAL DEL HTML (últimos 3000 chars): ' . $bodyEnd);

            // Verificar login exitoso: la respuesta NO debe contener txtUsr/btnLogin
            $indicators = [
                'btnLogin (aún en login)' => str_contains($html, 'name="btnLogin"'),
                'txtUsr (aún en login)'   => str_contains($html, 'name="txtUsr"'),
                'error/alerta visible'    => !empty($errorMessages),
            ];

            $this->logger->step('LOGIN', 'Indicadores: ' . json_encode($indicators));

            if ($indicators['btnLogin (aún en login)'] || $indicators['txtUsr (aún en login)']) {
                // Guardar HTML completo de la respuesta fallida para debug
                $debugFile = storage_path('logs/debug_login_fallido_' . time() . '.html');
                file_put_contents($debugFile, $html);
                $this->logger->step('LOGIN', '✗ LOGIN FALLIDO - formulario de login sigue presente');
                $this->logger->step('LOGIN', 'HTML completo guardado en: ' . basename($debugFile));
                return false;
            }

            $this->loggedIn = true;
            $this->logger->step('LOGIN', '✓ LOGIN EXITOSO (status ' . $status . ', sin formulario de login)');
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('LOGIN', $e);
            return false;
        }
    }

    // =========================================================================
    //  NAVEGAR A MASTERPAGE (directo, sin pasar por módulos)
    // =========================================================================

    private function loadMasterPage(): bool
    {
        $this->logger->step('NAV', 'GET MasterPage.aspx');
        $this->logger->request('GET', "{$this->baseUrl}/M_Gestion/MasterPage.aspx");

        $response = $this->client->get("{$this->baseUrl}/M_Gestion/MasterPage.aspx", [
            'http_errors' => false,
        ]);

        $html = (string) $response->getBody();
        $status = $response->getStatusCode();

        $this->logger->response($status, $html, $this->flattenHeaders($response->getHeaders()));

        if ($status !== 200) {
            $this->logger->step('NAV', "✗ MasterPage retornó status {$status}");
            return false;
        }

        // Extraer título
        $title = $this->extractTitle($html);
        $this->logger->step('NAV', "Título de MasterPage: {$title}");

        // Verificar sesión expirada SOLO por título
        // NOTA: NO buscar "SesionExpirada.aspx" en el HTML porque aparece como valor
        // de un campo hidden de RadNotification1 y genera falsos positivos
        if (stripos($title, 'Fin de sesi') !== false || stripos($title, 'Inicio de sesi') !== false) {
            $this->logger->step('NAV', '⚠ SESIÓN EXPIRADA (detectada por título)');
            return false;
        }

        // Verificar que estamos en la página correcta
        if (stripos($title, 'Gesti') === false && stripos($title, 'Collect') === false) {
            $this->logger->step('NAV', "⚠ Título inesperado: {$title}");
            return false;
        }

        $this->lastMasterPageHtml = $html;
        $this->extractTokens($html);

        $this->logger->step('NAV', '✓ MasterPage cargada correctamente');
        return true;
    }

    // =========================================================================
    //  BUSCAR CÉDULA (GetResults + PostBack rsb1)
    // =========================================================================

    private function buscarCedula(string $cedula): bool
    {
        $this->logger->separator("BUSCAR CÉDULA: {$cedula}");

        try {
            // 1) Cargar MasterPage para obtener tokens y contexto
            $this->logger->step('BUSCAR', 'Cargando MasterPage para contexto');

            if (!$this->loadMasterPage()) {
                // Intentar re-login
                $this->logger->step('BUSCAR', 'MasterPage falló, intentando re-login...');
                if (!$this->login() || !$this->loadMasterPage()) {
                    $this->logger->step('BUSCAR', '✗ No se pudo cargar MasterPage después de re-login');
                    return false;
                }
            }

            // Guardar HTML de debug
            $debugFile = storage_path("logs/debug_masterpage_{$cedula}_" . time() . ".html");
            file_put_contents($debugFile, $this->lastMasterPageHtml);
            $this->logger->step('BUSCAR', "MasterPage HTML guardado en: {$debugFile}");

            // 2) POST GetResults (búsqueda AJAX del RadSearchBox)
            $this->logger->step('BUSCAR', "POST GetResults con SearchBoxContext: Text={$cedula}");

            $jsonBody = [
                'context' => [
                    'Text' => $cedula,
                    'Key'  => '',
                ],
            ];

            $this->logger->request('POST', "{$this->baseUrl}/M_Gestion/MasterPage.aspx/GetResults", [
                'json' => $jsonBody,
                'headers' => [
                    'Content-Type'    => 'application/json; charset=utf-8',
                    'X-Requested-With' => 'XMLHttpRequest',
                ],
            ]);

            $response = $this->client->post("{$this->baseUrl}/M_Gestion/MasterPage.aspx/GetResults", [
                'json' => $jsonBody,
                'headers' => [
                    'Content-Type'     => 'application/json; charset=utf-8',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                    'Referer'          => "{$this->baseUrl}/M_Gestion/MasterPage.aspx",
                    'Origin'           => 'https://garantisa.mcnoc.co',
                ],
                'http_errors' => false,
            ]);

            $body = (string) $response->getBody();
            $status = $response->getStatusCode();

            $this->logger->response($status, $body, $this->flattenHeaders($response->getHeaders()));

            if ($status !== 200) {
                $this->logger->step('BUSCAR', "✗ GetResults retornó status {$status}");
                return false;
            }

            $json = json_decode($body, true);
            if (!$json || empty($json['d'])) {
                $this->logger->step('BUSCAR', '✗ GetResults no retornó resultados');
                $this->logger->step('BUSCAR', 'Body: ' . $body);
                return false;
            }

            $this->lastSearchResult = $json['d'][0];
            $resultValue = $this->lastSearchResult['Value'] ?? '';
            $resultText = $this->lastSearchResult['Text'] ?? '';

            $this->logger->step('BUSCAR', "✓ Resultado encontrado: Value={$resultValue}, Text={$resultText}");

            // 3) Simular PostBack del RadSearchBox para "seleccionar" el crédito
            return $this->postBackSearchBox($cedula, $resultValue, $resultText);

        } catch (\Throwable $e) {
            $this->logger->error('BUSCAR', $e);
            return false;
        }
    }

    // =========================================================================
    //  POSTBACK DEL SEARCHBOX (seleccionar crédito en sesión del servidor)
    // =========================================================================

    private function postBackSearchBox(string $cedula, string $value, string $text): bool
    {
        $this->logger->separator("POSTBACK RSB1: seleccionar crédito {$value}");

        try {
            // Construir el ClientState del RadSearchBox con el item seleccionado
            $rsb1ClientState = json_encode([
                'logEntries'  => [],
                'value'       => '',
                'text'        => $cedula,
                'enabled'     => true,
                'checkedIndices' => [],
                'checkedItemsTextOverflows' => false,
            ]);

            // Construir form params para el PostBack
            $formParams = [
                'RadScriptManager1_TSM' => '',
                '__EVENTTARGET'         => 'rsb1',
                '__EVENTARGUMENT'       => json_encode([
                    'Text'  => $text,
                    'Value' => $value,
                ]),
                '__VIEWSTATE'           => $this->viewState,
                '__VIEWSTATEGENERATOR'  => $this->viewStateGenerator,
                '__EVENTVALIDATION'     => $this->eventValidation,
                'rsb1'                  => $cedula,
                'rsb1_ClientState'      => $rsb1ClientState,
            ];

            // Agregar todos los ClientState de los controles que encontremos en el HTML
            $this->appendClientStates($formParams, $this->lastMasterPageHtml);

            $this->logger->step('POSTBACK', 'POST MasterPage.aspx con __EVENTTARGET=rsb1');
            $this->logger->request('POST', "{$this->baseUrl}/M_Gestion/MasterPage.aspx", [
                'form_params' => $formParams,
            ]);

            $response = $this->client->post("{$this->baseUrl}/M_Gestion/MasterPage.aspx", [
                'form_params' => $formParams,
                'http_errors' => false,
            ]);

            $html = (string) $response->getBody();
            $status = $response->getStatusCode();

            $this->logger->response($status, $html, $this->flattenHeaders($response->getHeaders()));

            if ($status !== 200) {
                $this->logger->step('POSTBACK', "✗ PostBack retornó status {$status}");
                return false;
            }

            // Verificar que la página respondió correctamente
            $title = $this->extractTitle($html);
            $this->logger->step('POSTBACK', "Título después de PostBack: {$title}");

            // Actualizar tokens con la respuesta del PostBack
            $this->lastMasterPageHtml = $html;
            $this->extractTokens($html);

            // Verificar que el botón de gestión se habilitó (indica que el crédito fue seleccionado)
            $gestionEnabled = !str_contains($html, 'btnGestion') || !str_contains($html, 'rbDisabled');
            $this->logger->step('POSTBACK', 'btnGestion habilitado: ' . ($gestionEnabled ? 'SÍ' : 'NO'));

            // Guardar HTML de debug post-postback
            $debugFile = storage_path("logs/debug_postback_{$cedula}_" . time() . ".html");
            file_put_contents($debugFile, $html);
            $this->logger->step('POSTBACK', "HTML post-PostBack guardado en: {$debugFile}");

            $this->logger->step('POSTBACK', '✓ PostBack completado');
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('POSTBACK', $e);
            return false;
        }
    }

    // =========================================================================
    //  GUARDAR GESTIÓN
    // =========================================================================

    public function guardarGestion(string $accion, string $resultado, string $comentario): array
    {
        $this->logger->separator('GUARDAR GESTIÓN');

        $this->logger->step('GUARDAR', "Acción (del Excel): {$accion}");
        $this->logger->step('GUARDAR', "Resultado (del Excel): {$resultado}");
        $this->logger->step('GUARDAR', "Comentario: {$comentario}");

        try {
            // PASO 1: AJAX PostBack btnGestion para cargar el formulario de gestión
            $ajaxBody = $this->ajaxClickBtnGestion();
            if ($ajaxBody === false) {
                return ['success' => false, 'error' => 'No se pudo cargar el formulario de gestión (btnGestion AJAX falló)'];
            }

            // Buscar la acción en los items reales del dropdown
            $accionItem = $this->findAccionInResponse($ajaxBody, strtoupper(trim($accion)));
            if (!$accionItem) {
                return ['success' => false, 'error' => "No se encontró la acción '{$accion}' en las opciones disponibles"];
            }
            $this->logger->step('GUARDAR', "Acción mapeada: text={$accionItem['text']}, value={$accionItem['value']}");

            // PASO 2: AJAX PostBack DdlHist_Ge_Accion para seleccionar acción y cargar resultados
            $ajaxBody2 = $this->ajaxSelectAccion($accionItem['text'], $accionItem['value']);
            if ($ajaxBody2 === false) {
                return ['success' => false, 'error' => 'No se pudo seleccionar la acción (DdlHist_Ge_Accion AJAX falló)'];
            }

            // Extraer el item real del resultado desde la respuesta AJAX (text + value del itemData)
            $resultadoItem = $this->findResultadoInResponse($ajaxBody2, strtoupper(trim($resultado)));
            if (!$resultadoItem) {
                return ['success' => false, 'error' => "No se encontró el resultado '{$resultado}' en las opciones disponibles para la acción '{$accion}'"];
            }
            $this->logger->step('GUARDAR', "Resultado mapeado: text={$resultadoItem['text']}, value={$resultadoItem['value']}");

            // PASO 3: AJAX PostBack BtnGuardar con acción + resultado + comentario
            return $this->ajaxGuardar($accionItem['text'], $accionItem['value'], $resultadoItem['text'], $resultadoItem['value'], $comentario);

        } catch (\Throwable $e) {
            $this->logger->error('GUARDAR', $e);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Buscar la acción en los items del DdlHist_Ge_Accion de la respuesta AJAX.
     * Busca por código (LOC, SC), nombre (LOCALIZADO, SIN CONTACTO), o parcial.
     */
    private function findAccionInResponse(string $ajaxBody, string $buscar): ?array
    {
        // Extraer itemData del script de inicialización de DdlHist_Ge_Accion
        $items = [];
        if (preg_match('/"DdlHist_Ge_Accion"[^}]*"itemData":\[([^\]]+)\]/', $ajaxBody, $m)) {
            $decoded = json_decode("[{$m[1]}]", true);
            if ($decoded) {
                $items = $decoded;
            }
        }

        // También extraer textos del HTML del dropdown para completar
        $htmlTexts = [];
        if (preg_match('/DdlHist_Ge_Accion_DropDown.*?<ul[^>]*>(.*?)<\/ul>/s', $ajaxBody, $m)) {
            if (preg_match_all('/<li[^>]*>([^<]+)<\/li>/', $m[1], $lis)) {
                $htmlTexts = $lis[1];
            }
        }

        // Combinar: si itemData solo tiene value, agregar text del HTML
        if (!empty($items) && !empty($htmlTexts) && count($items) === count($htmlTexts)) {
            foreach ($items as $i => &$item) {
                if (!isset($item['text']) || empty($item['text'])) {
                    $item['text'] = trim($htmlTexts[$i]);
                }
            }
            unset($item);
        } elseif (empty($items) && !empty($htmlTexts)) {
            foreach ($htmlTexts as $text) {
                $items[] = ['text' => trim($text), 'value' => ''];
            }
        }

        if (empty($items)) {
            $this->logger->step('GUARDAR', '✗ No se encontraron items de acción');
            return null;
        }

        $textos = array_map(fn($i) => $i['text'] ?? $i['value'] ?? '?', $items);
        $this->logger->step('GUARDAR', 'Items acción disponibles: ' . implode(', ', $textos));

        // RONDA 1: Match exacto por código entre paréntesis — "LOC" matchea "(LOC) ..."
        foreach ($items as $item) {
            $text = $item['text'] ?? '';
            $value = $item['value'] ?? '';
            if (preg_match('/\((\w+)\)/', $text, $codeMatch)) {
                if (strtoupper(trim($codeMatch[1])) === $buscar) {
                    $this->logger->step('GUARDAR', "✓ Acción match por código: {$text}");
                    return ['text' => $text, 'value' => $value ?: $codeMatch[1]];
                }
            }
        }

        // RONDA 2: Match exacto por nombre después del paréntesis
        // Ej: "LOCALIZADO" matchea "(LOC) LOCALIZADO" pero NO "(IL) Ilocalizado"
        foreach ($items as $item) {
            $text = $item['text'] ?? '';
            $value = $item['value'] ?? '';
            if (preg_match('/\)\s*(.+)$/', $text, $nameMatch)) {
                $itemName = strtoupper(trim($nameMatch[1]));
                // Quitar prefijos tipo "LOC:" para comparar
                $cleanName = preg_replace('/^\w+:/', '', $itemName);
                $cleanName = trim($cleanName);
                if ($itemName === $buscar || $cleanName === $buscar) {
                    $this->logger->step('GUARDAR', "✓ Acción match por nombre: {$text}");
                    return ['text' => $text, 'value' => $value ?: ''];
                }
            }
        }

        // RONDA 3: Match parcial con word boundary — "LOCALIZADO" como palabra completa
        // Evita que "Ilocalizado" matchee con "LOCALIZADO"
        foreach ($items as $item) {
            $text = $item['text'] ?? '';
            $value = $item['value'] ?? '';
            if (preg_match('/\b' . preg_quote($buscar, '/') . '\b/i', $text)) {
                $this->logger->step('GUARDAR', "✓ Acción match word boundary: {$text}");
                return ['text' => $text, 'value' => $value ?: ''];
            }
        }

        $this->logger->step('GUARDAR', "✗ No se encontró acción para: {$buscar}");
        return null;
    }

    /**
     * Buscar el item de resultado en la respuesta AJAX del DdlHist_Ge_Resultado.
     * Devuelve ['text' => '(REN       ) RENUENTE', 'value' => 'REN       ,0'] o null.
     *
     * Busca por: código exacto (REN), nombre completo (RENUENTE), o parcial.
     */
    private function findResultadoInResponse(string $ajaxBody, string $buscar): ?array
    {
        // Extraer itemData del script de inicialización de DdlHist_Ge_Resultado
        $items = [];
        if (preg_match('/"DdlHist_Ge_Resultado"[^}]*"itemData":\[([^\]]+)\]/', $ajaxBody, $m)) {
            $this->logger->step('GUARDAR', "itemData resultado raw: [{$m[1]}]");
            $decoded = json_decode("[{$m[1]}]", true);
            if ($decoded) {
                $items = $decoded;
            }
        }

        // Fallback: extraer del HTML del dropdown
        if (empty($items) && preg_match('/DdlHist_Ge_Resultado_DropDown.*?<ul[^>]*>(.*?)<\/ul>/s', $ajaxBody, $m)) {
            if (preg_match_all('/<li[^>]*>([^<]+)<\/li>/', $m[1], $lis)) {
                foreach ($lis[1] as $liText) {
                    $items[] = ['text' => trim($liText), 'value' => ''];
                }
            }
        }

        if (empty($items)) {
            $this->logger->step('GUARDAR', '✗ No se encontraron items de resultado en la respuesta AJAX');
            return null;
        }

        $textos = array_map(fn($i) => $i['text'] ?? '?', $items);
        $this->logger->step('GUARDAR', 'Items resultado disponibles: ' . implode(', ', $textos));

        // RONDA 1: Match exacto por código entre paréntesis
        foreach ($items as $item) {
            $text = $item['text'] ?? '';
            if (preg_match('/\((\w+)\s*\)/', $text, $codeMatch)) {
                if (strtoupper(trim($codeMatch[1])) === $buscar) {
                    $this->logger->step('GUARDAR', "✓ Resultado match por código: {$text}");
                    return $item;
                }
            }
        }

        // RONDA 2: Match exacto por nombre después del paréntesis
        foreach ($items as $item) {
            $text = $item['text'] ?? '';
            if (preg_match('/\)\s*(.+)$/', $text, $nameMatch)) {
                if (strtoupper(trim($nameMatch[1])) === $buscar) {
                    $this->logger->step('GUARDAR', "✓ Resultado match por nombre: {$text}");
                    return $item;
                }
            }
        }

        // RONDA 3: Match word boundary
        foreach ($items as $item) {
            $text = $item['text'] ?? '';
            if (preg_match('/\b' . preg_quote($buscar, '/') . '\b/i', $text)) {
                $this->logger->step('GUARDAR', "✓ Resultado match word boundary: {$text}");
                return $item;
            }
        }

        $this->logger->step('GUARDAR', "✗ No se encontró resultado para: {$buscar}");
        return null;
    }

    private function getAccionValue(string $accion): string
    {
        $map = [
            'CANCELADO' => 'CANC', 'CANC' => 'CANC',
            'EXCLUIR DE GESTION' => 'EXGT', 'EXGT' => 'EXGT',
            'FALLECIDO' => 'FLL', 'FLL' => 'FLL',
            'ILOCALIZADO' => 'IL', 'IL' => 'IL',
            'LOCALIZADO' => 'LOC', 'LOC' => 'LOC',
            'SIN CONTACTO' => 'SC', 'SC' => 'SC',
        ];
        return $map[$accion] ?? $accion;
    }

    private function getResultadoValue(string $resultado): string
    {
        $map = [
            'CONTACTO CON TERCERO' => 'CT', 'CT' => 'CT',
            'CORREO DE VOZ' => 'CVOZ', 'CVOZ' => 'CVOZ',
            'NO CONTESTA' => 'NCT', 'NCT' => 'NCT',
            'NUMERO NO PERTENECE' => 'NPT', 'NPT' => 'NPT',
        ];
        return $map[$resultado] ?? $resultado;
    }

    // =========================================================================
    //  PASO 1: AJAX PostBack btnGestion → cargar formulario de gestión
    // =========================================================================

    private function ajaxClickBtnGestion(): string|false
    {
        $this->logger->separator('AJAX POSTBACK btnGestion');

        try {
            $formParams = [
                'RadScriptManager1'     => 'RadAjaxManager1SU|btnGestion',
                '__EVENTTARGET'         => 'btnGestion',
                '__EVENTARGUMENT'       => '',
                '__VIEWSTATE'           => $this->viewState,
                '__VIEWSTATEGENERATOR'  => $this->viewStateGenerator,
                '__EVENTVALIDATION'     => $this->eventValidation,
                '__ASYNCPOST'           => 'true',
                'RadAjaxControlID'      => 'RadAjaxManager1',
            ];

            $this->appendClientStates($formParams, $this->lastMasterPageHtml);

            $this->logger->step('GESTION', 'AJAX POST btnGestion (partial postback)');
            $this->logger->request('POST', "{$this->baseUrl}/M_Gestion/MasterPage.aspx", ['form_params' => $formParams]);

            $response = $this->client->post("{$this->baseUrl}/M_Gestion/MasterPage.aspx", [
                'form_params' => $formParams,
                'headers' => [
                    'X-MicrosoftAjax'  => 'Delta=true',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer'          => "{$this->baseUrl}/M_Gestion/MasterPage.aspx",
                ],
                'http_errors' => false,
            ]);

            $body   = (string) $response->getBody();
            $status = $response->getStatusCode();
            $this->logger->response($status, $body, $this->flattenHeaders($response->getHeaders()));

            if ($status !== 200) {
                $this->logger->step('GESTION', "✗ AJAX btnGestion retornó status {$status}");
                return false;
            }

            // Debug
            $debugFile = storage_path('logs/debug_ajax_btngestion_' . time() . '.txt');
            file_put_contents($debugFile, $body);
            $this->logger->step('GESTION', "Respuesta AJAX guardada en: {$debugFile}");

            // Actualizar tokens ASP.NET
            $this->extractTokensFromAjaxResponse($body);

            // Actualizar el HTML combinado (MasterPage + AJAX) para ClientStates
            $this->lastMasterPageHtml .= "\n" . $body;

            // Verificar que el formulario de gestión se cargó
            $hasAccion     = str_contains($body, 'DdlHist_Ge_Accion');
            $hasResultado  = str_contains($body, 'DdlHist_Ge_Resultado');
            $hasComentario = str_contains($body, 'TxtHist_Ge_Comentario');
            $hasGuardar    = str_contains($body, 'BtnGuardar');

            $this->logger->step('GESTION', "Campos: Acción={$this->yn($hasAccion)}, Resultado={$this->yn($hasResultado)}, Comentario={$this->yn($hasComentario)}, Guardar={$this->yn($hasGuardar)}");

            if (!$hasAccion || !$hasComentario || !$hasGuardar) {
                $this->logger->step('GESTION', '✗ Formulario de gestión no se cargó correctamente');
                return false;
            }

            $this->logger->step('GESTION', '✓ Formulario de gestión cargado');
            return $body;

        } catch (\Throwable $e) {
            $this->logger->error('GESTION', $e);
            return false;
        }
    }

    // =========================================================================
    //  PASO 2: AJAX PostBack DdlHist_Ge_Accion → seleccionar acción y cargar resultados
    // =========================================================================

    private function ajaxSelectAccion(string $accionText, string $accionValue): string|false
    {
        $this->logger->separator("AJAX SELECT ACCIÓN: {$accionText} ({$accionValue})");

        try {
            // ClientState del RadComboBox con el valor seleccionado
            $accionClientState = json_encode([
                'logEntries'                => [],
                'value'                     => $accionValue,
                'text'                      => $accionText,
                'enabled'                   => true,
                'checkedIndices'            => [],
                'checkedItemsTextOverflows' => false,
            ]);

            $formParams = [
                'RadScriptManager1'              => 'RadAjaxManager1SU|DdlHist_Ge_Accion',
                '__EVENTTARGET'                  => 'DdlHist_Ge_Accion',
                '__EVENTARGUMENT'                => '',
                '__VIEWSTATE'                    => $this->viewState,
                '__VIEWSTATEGENERATOR'           => $this->viewStateGenerator,
                '__EVENTVALIDATION'              => $this->eventValidation,
                '__ASYNCPOST'                    => 'true',
                'RadAjaxControlID'               => 'RadAjaxManager1',
                'DdlHist_Ge_Accion'              => $accionText,
                'DdlHist_Ge_Accion_ClientState'  => $accionClientState,
            ];

            // Agregar todos los ClientStates del HTML acumulado
            $this->appendClientStates($formParams, $this->lastMasterPageHtml);

            $this->logger->step('ACCION', 'AJAX POST DdlHist_Ge_Accion (partial postback)');
            $this->logger->request('POST', "{$this->baseUrl}/M_Gestion/MasterPage.aspx", ['form_params' => $formParams]);

            $response = $this->client->post("{$this->baseUrl}/M_Gestion/MasterPage.aspx", [
                'form_params' => $formParams,
                'headers' => [
                    'X-MicrosoftAjax'  => 'Delta=true',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer'          => "{$this->baseUrl}/M_Gestion/MasterPage.aspx",
                ],
                'http_errors' => false,
            ]);

            $body   = (string) $response->getBody();
            $status = $response->getStatusCode();
            $this->logger->response($status, $body, $this->flattenHeaders($response->getHeaders()));

            if ($status !== 200) {
                $this->logger->step('ACCION', "✗ AJAX DdlHist_Ge_Accion retornó status {$status}");
                return false;
            }

            // Debug
            $debugFile = storage_path('logs/debug_ajax_accion_' . time() . '.txt');
            file_put_contents($debugFile, $body);
            $this->logger->step('ACCION', "Respuesta AJAX guardada en: {$debugFile}");

            // Actualizar tokens
            $this->extractTokensFromAjaxResponse($body);
            $this->lastMasterPageHtml .= "\n" . $body;

            // Verificar que DdlHist_Ge_Resultado ahora tiene items
            $hasResultadoItems = str_contains($body, 'DdlHist_Ge_Resultado') && !str_contains($body, '"itemData":[]');
            $this->logger->step('ACCION', 'DdlHist_Ge_Resultado con items: ' . $this->yn($hasResultadoItems));

            // Extraer los items de resultado disponibles para debug
            if (preg_match('/"DdlHist_Ge_Resultado".*?"itemData":\[([^\]]*)\]/', $body, $m)) {
                $this->logger->step('ACCION', "Items resultado: [{$m[1]}]");
            }

            $this->logger->step('ACCION', '✓ Acción seleccionada');
            return $body;

        } catch (\Throwable $e) {
            $this->logger->error('ACCION', $e);
            return false;
        }
    }

    // =========================================================================
    //  PASO 3: AJAX PostBack BtnGuardar → guardar gestión
    // =========================================================================

    private function ajaxGuardar(string $accionText, string $accionValue, string $resultadoText, string $resultadoValue, string $comentario): array
    {
        $this->logger->separator("AJAX GUARDAR: acción={$accionText}, resultado={$resultadoText}");

        try {
            // ClientStates de los ComboBoxes con valores seleccionados
            $accionClientState = json_encode([
                'logEntries'                => [],
                'value'                     => $accionValue,
                'text'                      => $accionText,
                'enabled'                   => true,
                'checkedIndices'            => [],
                'checkedItemsTextOverflows' => false,
            ]);

            $resultadoClientState = json_encode([
                'logEntries'                => [],
                'value'                     => $resultadoValue,
                'text'                      => $resultadoText,
                'enabled'                   => true,
                'checkedIndices'            => [],
                'checkedItemsTextOverflows' => false,
            ]);

            $formParams = [
                'RadScriptManager1'                  => 'RadAjaxManager1SU|BtnGuardar',
                '__EVENTTARGET'                      => 'BtnGuardar',
                '__EVENTARGUMENT'                    => '',
                '__VIEWSTATE'                        => $this->viewState,
                '__VIEWSTATEGENERATOR'               => $this->viewStateGenerator,
                '__EVENTVALIDATION'                  => $this->eventValidation,
                '__ASYNCPOST'                        => 'true',
                'RadAjaxControlID'                   => 'RadAjaxManager1',
                'DdlHist_Ge_Accion'                  => $accionText,
                'DdlHist_Ge_Accion_ClientState'      => $accionClientState,
                'DdlHist_Ge_Resultado'               => $resultadoText,
                'DdlHist_Ge_Resultado_ClientState'   => $resultadoClientState,
                'TxtHist_Ge_Comentario'              => $comentario,
                'TxtHist_Ge_Comentario_ClientState'  => json_encode(['enabled' => true, 'emptyMessage' => '', 'validationText' => $comentario, 'valueAsString' => $comentario, 'lastSetTextBoxValue' => $comentario]),
            ];

            // Agregar todos los ClientStates del HTML acumulado
            $this->appendClientStates($formParams, $this->lastMasterPageHtml);

            $this->logger->step('GUARDAR', 'AJAX POST BtnGuardar (partial postback)');
            $this->logger->request('POST', "{$this->baseUrl}/M_Gestion/MasterPage.aspx", ['form_params' => $formParams]);

            $response = $this->client->post("{$this->baseUrl}/M_Gestion/MasterPage.aspx", [
                'form_params' => $formParams,
                'headers' => [
                    'X-MicrosoftAjax'  => 'Delta=true',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer'          => "{$this->baseUrl}/M_Gestion/MasterPage.aspx",
                ],
                'http_errors' => false,
            ]);

            $body   = (string) $response->getBody();
            $status = $response->getStatusCode();
            $this->logger->response($status, $body, $this->flattenHeaders($response->getHeaders()));

            // Debug
            $debugFile = storage_path('logs/debug_ajax_guardar_' . time() . '.txt');
            file_put_contents($debugFile, $body);
            $this->logger->step('GUARDAR', "Respuesta guardar guardada en: {$debugFile}");

            if ($status !== 200) {
                return ['success' => false, 'error' => "Guardar gestión retornó status {$status}"];
            }

            // Actualizar tokens
            $this->extractTokensFromAjaxResponse($body);

            // Verificar errores en la respuesta AJAX
            $hasPageRedirect = str_contains($body, 'pageRedirect');
            $hasError = preg_match('/\|error\|/', $body);
            $hasAlert = preg_match('/alert\s*\(/', $body);

            if ($hasPageRedirect) {
                $this->logger->step('GUARDAR', '⚠ Respuesta contiene pageRedirect (posible sesión expirada)');
                return ['success' => false, 'error' => 'Sesión expirada durante el guardado'];
            }

            if ($hasError) {
                // Extraer mensaje de error
                if (preg_match('/\|error\|\d+\|([^|]+)\|/', $body, $m)) {
                    $this->logger->step('GUARDAR', "✗ Error del servidor: {$m[1]}");
                    return ['success' => false, 'error' => "Error del servidor: {$m[1]}"];
                }
                $this->logger->step('GUARDAR', '✗ Error desconocido en la respuesta');
                return ['success' => false, 'error' => 'Error desconocido al guardar'];
            }

            $this->logger->step('GUARDAR', '✓ Gestión guardada exitosamente');
            return ['success' => true];

        } catch (\Throwable $e) {
            $this->logger->error('GUARDAR', $e);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function yn(bool $val): string
    {
        return $val ? 'SÍ' : 'NO';
    }

    /**
     * Extraer tokens ASP.NET de una respuesta AJAX partial update
     * Formato: length|type|id|content|
     */
    private function extractTokensFromAjaxResponse(string $body): void
    {
        // __VIEWSTATE en respuesta AJAX
        if (preg_match('/\|hiddenField\|__VIEWSTATE\|([^|]+)\|/', $body, $m)) {
            $this->viewState = $m[1];
            $this->logger->step('AJAX', '__VIEWSTATE actualizado desde respuesta AJAX');
        }

        // __VIEWSTATEGENERATOR
        if (preg_match('/\|hiddenField\|__VIEWSTATEGENERATOR\|([^|]+)\|/', $body, $m)) {
            $this->viewStateGenerator = $m[1];
        }

        // __EVENTVALIDATION
        if (preg_match('/\|hiddenField\|__EVENTVALIDATION\|([^|]+)\|/', $body, $m)) {
            $this->eventValidation = $m[1];
            $this->logger->step('AJAX', '__EVENTVALIDATION actualizado desde respuesta AJAX');
        }

        $this->logger->tokens([
            '__VIEWSTATE'          => $this->viewState,
            '__VIEWSTATEGENERATOR' => $this->viewStateGenerator,
            '__EVENTVALIDATION'    => $this->eventValidation,
        ]);
    }

    /**
     * Extraer tokens ASP.NET de una respuesta AJAX partial update
     * Formato: length|type|id|content|
     */

    public function procesarCedula(string $cedula, string $accion, string $resultado, string $comentario): array
    {
        $this->logger->separator("PROCESAR CÉDULA: {$cedula}");

        try {
            // 1) Buscar la cédula (incluye PostBack para seleccionar el crédito)
            if (!$this->buscarCedula($cedula)) {
                return ['success' => false, 'error' => "No se encontró la cédula {$cedula} o falló la selección"];
            }

            // 2) Guardar la gestión
            $result = $this->guardarGestion($accion, $resultado, $comentario);

            $this->logger->step('PROCESAR', 'Resultado final: ' . json_encode($result));
            return $result;

        } catch (\Throwable $e) {
            $this->logger->error('PROCESAR', $e);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    //  UTILIDADES
    // =========================================================================

    /**
     * Extraer tokens ASP.NET (__VIEWSTATE, __VIEWSTATEGENERATOR, __EVENTVALIDATION)
     */
    private function extractTokens(string $html): void
    {
        // __VIEWSTATE
        if (preg_match('/name="__VIEWSTATE"[^>]*value="([^"]*)"/', $html, $m)) {
            $this->viewState = $m[1];
        } elseif (preg_match('/id="__VIEWSTATE"[^>]*value="([^"]*)"/', $html, $m)) {
            $this->viewState = $m[1];
        }

        // __VIEWSTATEGENERATOR
        if (preg_match('/name="__VIEWSTATEGENERATOR"[^>]*value="([^"]*)"/', $html, $m)) {
            $this->viewStateGenerator = $m[1];
        } elseif (preg_match('/id="__VIEWSTATEGENERATOR"[^>]*value="([^"]*)"/', $html, $m)) {
            $this->viewStateGenerator = $m[1];
        }

        // __EVENTVALIDATION (puede no existir en login/módulos, es normal)
        $this->eventValidation = '';
        $strategies = [
            '/name="__EVENTVALIDATION"[^>]*value="([^"]*)"/',
            '/id="__EVENTVALIDATION"[^>]*value="([^"]*)"/',
            '/__EVENTVALIDATION[|]([^|]+)[|]/',
            '/hiddenField[|]__EVENTVALIDATION[|]([^|]+)/',
        ];

        foreach ($strategies as $i => $pattern) {
            if (preg_match($pattern, $html, $m) && !empty($m[1])) {
                $this->eventValidation = $m[1];
                break;
            }
        }

        if (empty($this->eventValidation)) {
            $this->logger->step('EXTRACT', '⚠ No se encontró __EVENTVALIDATION (puede ser normal)');
        }

        $this->logger->tokens([
            '__VIEWSTATE'          => $this->viewState,
            '__VIEWSTATEGENERATOR' => $this->viewStateGenerator,
            '__EVENTVALIDATION'    => $this->eventValidation,
        ]);
    }

    /**
     * Extraer título de la página
     */
    private function extractTitle(string $html): string
    {
        if (preg_match('/<title>\s*(.*?)\s*<\/title>/si', $html, $m)) {
            return trim($m[1]);
        }
        return '(sin título)';
    }

    /**
     * Buscar un campo por nombre en el HTML
     */
    private function findFieldName(string $html, array $candidates): string
    {
        foreach ($candidates as $name) {
            // Buscar como name="..." o id="..."
            $nameEscaped = preg_quote($name, '/');
            $idVersion = str_replace('\\$', '_', $nameEscaped);

            if (preg_match('/name="' . $nameEscaped . '"/', $html) ||
                preg_match('/id="' . $idVersion . '"/', $html)) {
                return $name;
            }
        }
        return '';
    }

    /**
     * Agregar todos los campos _ClientState encontrados en el HTML
     */
    private function appendClientStates(array &$formParams, string $html): void
    {
        // Buscar todos los inputs hidden con nombre que termine en _ClientState
        if (preg_match_all('/name="([^"]*_ClientState)"[^>]*(?:value="([^"]*)")?/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $name = $match[1];
                $value = $match[2] ?? '';
                // No sobreescribir si ya fue establecido manualmente
                if (!isset($formParams[$name])) {
                    $formParams[$name] = $value;
                }
            }
        }

        // También buscar con value antes de name
        if (preg_match_all('/value="([^"]*)"[^>]*name="([^"]*_ClientState)"/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = $match[1];
                $name = $match[2];
                if (!isset($formParams[$name])) {
                    $formParams[$name] = $value;
                }
            }
        }
    }

    /**
     * Log de campos del formulario encontrados en el HTML
     */
    private function logFormFields(string $html): void
    {
        $fields = [];
        if (preg_match_all('/name="([^"]+)"/', $html, $matches)) {
            $fields = array_unique($matches[1]);
            // Filtrar campos irrelevantes (WebResource, ScriptResource, etc.)
            $fields = array_filter($fields, function ($f) {
                return !str_starts_with($f, 'ctl') || str_contains($f, 'ClientState');
            });
        }
        $this->logger->step('LOGIN', 'Campos encontrados: ' . implode(', ', array_values($fields)));
    }

    /**
     * Log de cookies activas
     */
    private function logCookies(): void
    {
        $cookies = [];
        foreach ($this->cookies as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $this->logger->cookies($cookies);
    }

    /**
     * Aplanar headers de Guzzle (array de arrays → array de strings)
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[$name] = implode('; ', $values);
        }
        return $flat;
    }
}
