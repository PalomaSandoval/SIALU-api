<?php

namespace App\Integrations\Orion;

use DOMDocument;
use DOMElement;

/**
 * Read-only session on Orion's web console (Orion Platform 2015.1, ASP.NET forms login) to obtain the exact values
 * of the «Availability Statistics» resource. Each session() logs in once, queries one or more nodes on the same
 * connection and always logs out.
 *
 * Disabled unless ORION_WEB_STATS_ENABLED=true: the console only listens on HTTP, so the login sends the password
 * in clear text. Cookies live only in the cURL handle's memory and are discarded with it; nothing is written to disk
 * or logged.
 */
class OrionWebClient
{
    public const LOGIN_PATH = '/Orion/Login.aspx';
    public const LOGOUT_PATH = '/Orion/Logout.aspx';
    public const STATS_PATH = '/Orion/Services/AsyncResources.asmx/GetAvailabilityStats';
    public const AUTH_COOKIE = '.ASPXAUTH';

    /** Largest NodeID accepted (Orion's NodeID is a SQL int). */
    public const MAX_NODE_ID = 2147483647;

    private string $baseUrl;
    private string $username;
    private string $password;
    private string $caFile;

    /** @var \CurlHandle|null Open only during one session() call. */
    private $curl = null;

    private bool $inSession = false;

    private bool $loggedIn = false;

    public function __construct()
    {
        if (! filter_var(config('services.orion.web_stats_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new OrionWebException('La consulta a la consola web de Orion está deshabilitada (ORION_WEB_STATS_ENABLED).');
        }

        $this->baseUrl = rtrim(trim((string) config('services.orion.web_url')), '/');
        $parts = parse_url($this->baseUrl);
        if ($this->baseUrl === '' || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])) {
            throw new OrionWebException('ORION_WEB_URL no está configurado o no es una URL http(s) válida.');
        }

        $this->username = trim((string) config('services.orion.username'));
        $this->password = (string) config('services.orion.password');
        if ($this->username === '' || $this->password === '') {
            throw new OrionWebException('ORION_USERNAME u ORION_PASSWORD no están configurados.');
        }

        $this->caFile = trim((string) config('services.orion.ca_file'));
    }

    /**
     * «Availability Statistics» of one node in its own session (login, query, logout).
     * `vacia` is true when Orion answered an empty table: that is not proof of a node without data until the caller
     * confirms the NodeID exists in Orion.Nodes (Orion answers the same for a NodeID that does not exist).
     *
     * @return array{periodos: list<array{periodo: string, texto: string, disponibilidad: float}>, vacia: bool, sesion_cerrada: bool}
     */
    public function availabilityStats(int $nodeId): array
    {
        self::assertNodeId($nodeId);
        $session = $this->session(function () use ($nodeId): ?array {
            $response = $this->fetchStats($nodeId);
            if ($response['status'] !== 200) {
                throw new OrionWebException('GetAvailabilityStats respondió HTTP '.$response['status'].'.');
            }

            return OrionAvailabilityStatsParser::parseOrEmpty($response['body']);
        });

        return ['periodos' => $session['resultado'] ?? [], 'vacia' => $session['resultado'] === null, 'sesion_cerrada' => $session['sesion_cerrada']];
    }

    /**
     * Runs $work inside one web session: a single login, any number of fetchStats() calls on the same connection,
     * and a logout that is always attempted (finally), whatever $work does. The cURL handle and its in-memory
     * cookies are discarded at the end.
     *
     * @template T
     *
     * @param  callable(self): T  $work
     * @return array{resultado: T, sesion_cerrada: bool}
     */
    public function session(callable $work): array
    {
        if ($this->curl !== null || $this->inSession) {
            throw new OrionWebException('Ya hay una sesión web de Orion abierta en este cliente.');
        }
        $this->open();
        $this->inSession = true;
        $this->loggedIn = false;
        $closed = false;
        try {
            $this->login();
            $this->loggedIn = true;
            $result = $work($this);
        } finally {
            if ($this->loggedIn) {
                $closed = $this->logout();
            }
            $this->inSession = false;
            $this->loggedIn = false;
            $this->close();
        }

        return ['resultado' => $result, 'sesion_cerrada' => $closed];
    }

    /**
     * One GetAvailabilityStats request on the open session; the body is returned untouched for evidence.
     * A 302 to the login page means the session expired: callers may relogin() once and repeat.
     *
     * @return array{status: int, body: string, sesion_vencida: bool}
     */
    public function fetchStats(int $nodeId): array
    {
        self::assertNodeId($nodeId);
        if (! $this->inSession || ! $this->loggedIn) {
            throw new OrionWebException('No hay una sesión web de Orion abierta.');
        }
        $response = $this->send('POST', self::STATS_PATH, [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
        ], json_encode(['nodeId' => $nodeId], JSON_THROW_ON_ERROR));
        $location = $response['headers']['location'][0] ?? '';

        return [
            'status' => $response['status'],
            'body' => $response['body'],
            'sesion_vencida' => $response['status'] === 302 && stripos($location, 'Login.aspx') !== false,
        ];
    }

    /** Replaces an expired session inside session(): best-effort logout, then a new login on the same handle. */
    public function relogin(): void
    {
        if (! $this->inSession) {
            throw new OrionWebException('No hay una sesión web de Orion abierta.');
        }
        if ($this->loggedIn) {
            $this->logout();
            $this->loggedIn = false;
        }
        $this->login();
        $this->loggedIn = true;
    }

    private static function assertNodeId(int $nodeId): void
    {
        if ($nodeId < 1 || $nodeId > self::MAX_NODE_ID) {
            throw new OrionWebException('NodeID inválido.');
        }
    }

    private function login(): void
    {
        $page = $this->send('GET', self::LOGIN_PATH);
        if ($page['status'] !== 200) {
            throw new OrionWebException('La página de inicio de sesión de Orion respondió HTTP '.$page['status'].'.');
        }
        $form = self::loginForm($page['body']);
        $form['ctl00$BodyContent$Username'] = $this->username;
        $form['ctl00$BodyContent$Password'] = $this->password;

        $response = $this->send('POST', self::LOGIN_PATH, ['Content-Type: application/x-www-form-urlencoded'], http_build_query($form));
        $location = $response['headers']['location'][0] ?? '';
        $authenticated = $response['status'] === 302 && stripos($location, 'Login.aspx') === false
            && self::setsCookie($response['headers'], self::AUTH_COOKIE);

        if (! $authenticated) {
            // Orion answers a rejected login by rendering the form again (HTTP 200) without the auth cookie.
            throw new OrionWebException($response['status'] === 200
                ? 'Orion rechazó el inicio de sesión web de la cuenta configurada.'
                : 'Respuesta inesperada al iniciar sesión web en Orion (HTTP '.$response['status'].').');
        }
    }

    /** Best effort: the session cookies are discarded with the cURL handle either way. */
    private function logout(): bool
    {
        try {
            $response = $this->send('GET', self::LOGOUT_PATH);
        } catch (OrionWebException) {
            return false;
        }
        $location = $response['headers']['location'][0] ?? '';

        // Orion redirects to the login page; a ReturnUrl back to Logout.aspx means the session was not recognised.
        return $response['status'] === 302 && stripos($location, 'Login.aspx') !== false && stripos($location, 'Logout') === false;
    }

    /**
     * Hidden ASP.NET fields of the login form plus the postback target of its login button.
     *
     * @return array<string, string>
     */
    public static function loginForm(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $form = $loaded ? $dom->getElementById('aspnetForm') : null;
        if (! $form instanceof DOMElement) {
            throw new OrionWebException('La página de inicio de sesión de Orion no contiene el formulario esperado.');
        }

        $fields = [];
        foreach ($form->getElementsByTagName('input') as $input) {
            if (strtolower($input->getAttribute('type')) === 'hidden' && $input->getAttribute('name') !== '') {
                $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            }
        }
        if (! isset($fields['__VIEWSTATE'])) {
            throw new OrionWebException('La página de inicio de sesión de Orion no contiene __VIEWSTATE.');
        }

        // The login button is a LinkButton: WebForm_PostBackOptions("ctl00$BodyContent$ctlNN", ...).
        if (! preg_match('/WebForm_PostBackOptions\((?:&quot;|")(ctl00\$BodyContent\$[A-Za-z0-9_$]+)(?:&quot;|")/', $html, $match)) {
            throw new OrionWebException('La página de inicio de sesión de Orion no contiene el botón de acceso esperado.');
        }
        $fields['__EVENTTARGET'] = $match[1];
        $fields['__EVENTARGUMENT'] = '';

        return $fields;
    }

    /** @param array<string, list<string>> $headers */
    public static function setsCookie(array $headers, string $name): bool
    {
        foreach ($headers['set-cookie'] ?? [] as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$cookieName, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($cookieName) === $name && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    protected function open(): void
    {
        $curl = curl_init();
        if ($curl === false) {
            throw new OrionWebException('No fue posible iniciar cURL.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // Empty file name: cURL keeps cookies in memory only and never writes them out.
            CURLOPT_COOKIEFILE => '',
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ] + ($this->caFile !== '' ? [CURLOPT_CAINFO => $this->caFile] : []));
        $this->curl = $curl;
    }

    protected function close(): void
    {
        if ($this->curl !== null) {
            curl_close($this->curl);
            $this->curl = null;
        }
    }

    /**
     * One request on the open session. Header names are returned in lower case.
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    protected function send(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $responseHeaders = [];
        curl_setopt_array($this->curl, [
            CURLOPT_URL => $this->baseUrl.$path,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt_array($this->curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => (string) $body]);
        } else {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        }

        $result = curl_exec($this->curl);
        // Clear the form so the password does not stay attached to the handle.
        if ($method === 'POST') {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, '');
        }
        if ($result === false) {
            // cURL's message names the host and the failure only, never the request body.
            throw new OrionWebException('Error al conectar con la consola web de Orion: '.curl_error($this->curl));
        }

        return ['status' => (int) curl_getinfo($this->curl, CURLINFO_HTTP_CODE), 'headers' => $responseHeaders, 'body' => (string) $result];
    }
}
