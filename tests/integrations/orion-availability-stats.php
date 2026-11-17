<?php

// Availability Statistics of one node through Orion's web console: percentage conversion, response validation, the
// login/query/logout sequence, error messages without secrets and the endpoint contract.
// Isolated: the HTTP transport is replaced by scripted responses; credentials are synthetic; no network or SQL.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\NodeAvailabilityStatsController;
use App\Integrations\Orion\OrionAvailabilityStatsParser as Parser;
use App\Integrations\Orion\OrionWebClient;
use App\Integrations\Orion\OrionWebException;
use Illuminate\Support\Facades\Route;

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    $checks++;
}
/** Runs $action and returns the OrionWebException message it must throw. */
function fails(callable $action, string $label): string
{
    try {
        $action();
    } catch (OrionWebException $e) {
        check(true, $label);

        return $e->getMessage();
    }
    throw new RuntimeException('FAIL (no exception): '.$label);
}

const SECRET = 'S3cr3t-sintetico!';
const USER = 'monitor-prueba';
config(['services.orion' => [
    'username' => USER, 'password' => SECRET, 'web_url' => 'http://orion.example.invalid/', 'web_stats_enabled' => true,
]]);

// --- Percentage conversion ----------------------------------------------------------------------------------------
foreach ([
    ['100.000 %', 100.0], ['48.860 %', 48.86], ['48,860 %', 48.86], ['0 %', 0.0], ['0,000 %', 0.0], ['99,5%', 99.5],
    ["48,860\u{00A0}%", 48.86], [' 7.25 % ', 7.25], ['100,000 %', 100.0], ['94.568 %', 94.568],
] as [$text, $expected]) {
    check(abs(Parser::percent($text) - $expected) < 1e-9, 'Percent '.json_encode($text).' = '.$expected);
}
foreach (['N/A', '', '%', '-1 %', '100.001 %', '100,5 %', '1.000,5 %', '12..5 %', '12,5,3 %', 'abc %', '1e2 %'] as $bad) {
    fails(fn () => Parser::percent($bad), 'Reject percent '.json_encode($bad));
}

// --- Response parsing ---------------------------------------------------------------------------------------------
function table(array $rows, array $columns = ['Period', 'Availability', 'NonLocalizedPeriod'], bool $wrap = true): string
{
    $table = ['__type' => 'System.Data.DataTable', 'Columns' => $columns, 'Rows' => $rows];

    return json_encode($wrap ? ['d' => $table] : $table);
}
$live = [
    ['Today', '100.000 %', 'Today'], ['Yesterday', '100.000 %', 'Yesterday'], ['Last 7 Days', '94.568 %', 'Last 7 Days'],
    ['Last 30 Days', '48.860 %', 'Last 30 Days'], ['This Month', '58.376 %', 'This Month'],
    ['Last Month', '42.789 %', 'Last Month'], ['This Year', '51.428 %', 'This Year'],
];
$parsed = Parser::parse(table($live));
check(array_column($parsed, 'periodo') === Parser::PERIODS, 'Seven periods keyed by NonLocalizedPeriod, in order');
check($parsed[5] === ['periodo' => 'Last Month', 'texto' => '42.789 %', 'disponibilidad' => 42.789], 'Original text and number kept');

// A Spanish console: localized Period and decimal comma; the key stays NonLocalizedPeriod.
$es = array_map(fn ($r) => ['Periodo '.$r[0], str_replace('.', ',', $r[1]), $r[2]], array_reverse($live));
$parsedEs = Parser::parse(table($es));
check(array_column($parsedEs, 'periodo') === Parser::PERIODS, 'Localized rows in any order map to the stable keys');
check($parsedEs[3]['texto'] === '48,860 %' && $parsedEs[3]['disponibilidad'] === 48.86, 'Decimal comma converted');
check(Parser::parse(table($live, wrap: false)) === $parsed, 'Unwrapped result (no "d") accepted, as the console does');
$reordered = array_map(fn ($r) => [$r[2], $r[0], $r[1]], $live);
check(Parser::parse(table($reordered, ['NonLocalizedPeriod', 'Period', 'Availability'])) === $parsed, 'Columns located by name');

fails(fn () => Parser::parse('<html>Login</html>'), 'Reject non-JSON (e.g. login page)');
fails(fn () => Parser::parse('{"d":null}'), 'Reject null table');
fails(fn () => Parser::parse(table($live, ['Period', 'Availability'])), 'Reject missing NonLocalizedPeriod column');
check(str_contains(fails(fn () => Parser::parse(table(array_slice($live, 0, 6))), 'Reject missing period'), 'This Year'), 'Missing period named');
fails(fn () => Parser::parse(table([...$live, $live[0]])), 'Reject duplicate period');
fails(fn () => Parser::parse(table([...$live, ['Custom', '1 %', 'Last 2 Hours']])), 'Reject unknown period');
fails(fn () => Parser::parse(table([...array_slice($live, 1), ['Today', null, 'Today']])), 'Reject row without availability');
fails(fn () => Parser::parse(table([...array_slice($live, 1), ['Today', 'Unknown', 'Today']])), 'Reject non-numeric availability');
$long = fails(fn () => Parser::parse(table([...array_slice($live, 1), ['Today', str_repeat('x', 500), 'Today']])), 'Reject long garbage');
check(mb_strlen($long) < 120, 'Orion text in messages is truncated');

// --- Login form ---------------------------------------------------------------------------------------------------
$loginHtml = <<<'HTML'
<html><body><form method="post" action="./Login.aspx" id="aspnetForm">
<input type="hidden" name="__EVENTTARGET" id="__EVENTTARGET" value="" />
<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="vs+/=&amp;x" />
<input type="hidden" name="__VIEWSTATEGENERATOR" id="__VIEWSTATEGENERATOR" value="01070692" />
<input name="ctl00$BodyContent$Username" type="text" />
<input name="ctl00$BodyContent$Password" type="password" />
<a onclick="WebForm_DoPostBackWithOptions(new WebForm_PostBackOptions(&quot;ctl00$BodyContent$ctl05&quot;, &quot;&quot;, true))">Login</a>
</form></body></html>
HTML;
$form = OrionWebClient::loginForm($loginHtml);
check($form['__VIEWSTATE'] === 'vs+/=&x' && $form['__VIEWSTATEGENERATOR'] === '01070692', 'Hidden fields read and decoded');
check($form['__EVENTTARGET'] === 'ctl00$BodyContent$ctl05' && $form['__EVENTARGUMENT'] === '', 'Login button postback target');
fails(fn () => OrionWebClient::loginForm('<html><body>Mantenimiento</body></html>'), 'Reject page without login form');
fails(fn () => OrionWebClient::loginForm(str_replace('WebForm_PostBackOptions', 'X', $loginHtml)), 'Reject form without login button');

check(OrionWebClient::setsCookie(['set-cookie' => ['TestCookieSupport=Supported; path=/', '.ASPXAUTH=ABC123; path=/; HttpOnly']], '.ASPXAUTH'), 'Auth cookie detected');
check(! OrionWebClient::setsCookie(['set-cookie' => ['.ASPXAUTH=; expires=Mon, 11-Oct-1999 00:00:00 GMT; path=/']], '.ASPXAUTH'), 'Cleared auth cookie is not a login');
check(! OrionWebClient::setsCookie([], '.ASPXAUTH'), 'No cookies: no login');

// --- Configuration ------------------------------------------------------------------------------------------------
$base = config('services.orion');
foreach ([
    'Disabled by default' => array_diff_key($base, ['web_stats_enabled' => 1]),
    'Explicitly disabled' => ['web_stats_enabled' => 'false'] + $base,
    'Missing URL' => ['web_url' => ''] + $base,
    'Non-HTTP URL' => ['web_url' => 'ftp://orion.example.invalid'] + $base,
    'URL with credentials' => ['web_url' => 'http://u:p@orion.example.invalid'] + $base,
    'Missing password' => ['password' => ''] + $base,
] as $label => $settings) {
    config(['services.orion' => $settings]);
    $message = fails(fn () => new OrionWebClient, $label);
    check(! str_contains($message, SECRET), $label.': no secret in message');
}
config(['services.orion' => $base]);

// --- Session sequence ---------------------------------------------------------------------------------------------
class FakeWeb extends OrionWebClient
{
    public array $requests = [];
    public int $opened = 0;
    public int $closed = 0;

    /** @param array<string, array|Throwable> $responses keyed by "METHOD path" */
    public function __construct(public array $responses)
    {
        parent::__construct();
    }

    protected function open(): void { $this->opened++; }
    protected function close(): void { $this->closed++; }

    protected function send(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $this->requests[] = ['line' => "$method $path", 'body' => (string) $body];
        $response = $this->responses["$method $path"] ?? ['status' => 404, 'headers' => [], 'body' => ''];
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response + ['headers' => [], 'body' => ''];
    }
}
function ok(array $overrides = []): array
{
    global $loginHtml, $live;

    return $overrides + [
        'GET /Orion/Login.aspx' => ['status' => 200, 'body' => $loginHtml],
        'POST /Orion/Login.aspx' => ['status' => 302, 'headers' => [
            'location' => ['/Orion/View.aspx'], 'set-cookie' => ['.ASPXAUTH=TOKEN; path=/; HttpOnly'],
        ]],
        'POST /Orion/Services/AsyncResources.asmx/GetAvailabilityStats' => ['status' => 200, 'body' => table($live)],
        'GET /Orion/Logout.aspx' => ['status' => 302, 'headers' => ['location' => ['/Orion/Login.aspx']]],
    ];
}
$lines = fn (FakeWeb $web) => array_column($web->requests, 'line');

$web = new FakeWeb(ok());
$result = $web->availabilityStats(153);
check($lines($web) === ['GET /Orion/Login.aspx', 'POST /Orion/Login.aspx', 'POST /Orion/Services/AsyncResources.asmx/GetAvailabilityStats', 'GET /Orion/Logout.aspx'], 'Login, query, logout');
check($result['periodos'] === $parsed && $result['sesion_cerrada'] === true, 'Periods returned and session closed');
check($web->opened === 1 && $web->closed === 1, 'Handle opened and closed once');
parse_str($web->requests[1]['body'], $posted);
check($posted['ctl00$BodyContent$Username'] === USER && $posted['ctl00$BodyContent$Password'] === SECRET && $posted['__EVENTTARGET'] === 'ctl00$BodyContent$ctl05', 'Login form posted with the configured account');
check(json_decode($web->requests[2]['body'], true) === ['nodeId' => 153], 'Stats requested with {"nodeId":153}');
$elsewhere = array_filter($web->requests, fn ($r, $i) => $i !== 1 && str_contains($r['body'], SECRET), ARRAY_FILTER_USE_BOTH);
check($elsewhere === [], 'Password sent only in the login form');

$expectSecretFree = function (array $responses, string $label, bool $loggedOut) use ($lines): string {
    $web = new FakeWeb($responses);
    $message = fails(fn () => $web->availabilityStats(153), $label);
    check(! str_contains($message, SECRET) && ! str_contains($message, 'TOKEN') && ! str_contains($message, USER), $label.': message without secrets');
    check($web->closed === 1, $label.': handle closed');
    check(in_array('GET /Orion/Logout.aspx', $lines($web), true) === $loggedOut, $label.': logout '.($loggedOut ? 'attempted' : 'not needed'));

    return $message;
};
$rejected = $expectSecretFree(ok(['POST /Orion/Login.aspx' => ['status' => 200, 'body' => $loginHtml]]), 'Wrong password', false);
check(str_contains($rejected, 'rechazó'), 'Wrong password reported as rejected login');
$expectSecretFree(ok(['POST /Orion/Login.aspx' => ['status' => 302, 'headers' => ['location' => ['/Orion/Login.aspx?ReturnUrl=x']]]]), 'Redirect back to login', false);
$expectSecretFree(ok(['POST /Orion/Login.aspx' => ['status' => 302, 'headers' => ['location' => ['/Orion/View.aspx']]]]), 'Redirect without auth cookie', false);
$expectSecretFree(ok(['GET /Orion/Login.aspx' => ['status' => 503, 'body' => 'down']]), 'Login page unavailable', false);
$expectSecretFree(ok(['GET /Orion/Login.aspx' => new OrionWebException('Error al conectar con la consola web de Orion: timeout')]), 'Network failure', false);
$http = $expectSecretFree(ok(['POST /Orion/Services/AsyncResources.asmx/GetAvailabilityStats' => ['status' => 500, 'body' => 'stack trace']]), 'Stats HTTP 500', true);
check(str_contains($http, 'HTTP 500') && ! str_contains($http, 'stack trace'), 'Stats error names the status, not the body');
$expectSecretFree(ok(['POST /Orion/Services/AsyncResources.asmx/GetAvailabilityStats' => ['status' => 200, 'body' => '<html>Login</html>']]), 'Stats not JSON', true);
$expectSecretFree(ok(['POST /Orion/Services/AsyncResources.asmx/GetAvailabilityStats' => ['status' => 200, 'body' => table(array_slice($live, 0, 3))]]), 'Stats incomplete', true);

$web = new FakeWeb(ok(['GET /Orion/Logout.aspx' => new OrionWebException('timeout')]));
check($web->availabilityStats(153)['sesion_cerrada'] === false, 'Failed logout does not hide the data, it is reported');
$web = new FakeWeb(ok(['GET /Orion/Logout.aspx' => ['status' => 302, 'headers' => ['location' => ['/Orion/Login.aspx?ReturnUrl=%2fOrion%2fLogout.aspx']]]]));
check($web->availabilityStats(153)['sesion_cerrada'] === false, 'Logout of an unrecognised session is not reported as closed');
foreach ([0, -1, OrionWebClient::MAX_NODE_ID + 1] as $bad) {
    $web = new FakeWeb(ok());
    fails(fn () => $web->availabilityStats($bad), 'Client rejects NodeID '.$bad);
    check($web->requests === [], 'No request for NodeID '.$bad);
}

// --- Endpoint -----------------------------------------------------------------------------------------------------
$route = Route::getRoutes()->getByName('api.v1.monitoreo.disponibilidad.nodo.orion');
check($route !== null && $route->uri() === 'api/v1/monitoreo/disponibilidad/nodos/{nodeId}/orion' && $route->methods() === ['GET', 'HEAD'], 'Route registered as GET');
check(in_array('sialu.auth', $route->gatherMiddleware(), true), 'Route requires the SIALU session');

class FakeController extends NodeAvailabilityStatsController
{
    public function __construct(private Closure $make) {}
    protected function client(): OrionWebClient { return ($this->make)(); }
}
$call = function (string $id, Closure $make): array {
    $response = (new FakeController($make))->show($id);

    return [$response->getStatusCode(), $response->getData(true)];
};
foreach (['0', '-5', 'abc', '01', '1.5', '153 ', '2147483648', '99999999999'] as $bad) {
    [$status] = $call($bad, fn () => throw new RuntimeException('client must not be built'));
    check($status === 422, 'Endpoint rejects NodeID '.json_encode($bad));
}
[$status, $body] = $call('153', fn () => new FakeWeb(ok()));
check($status === 200 && $body['data']['node_id'] === 153 && count($body['data']['periodos']) === 7, 'Endpoint returns seven periods');
check($body['data']['periodos'][3] === ['periodo' => 'Last 30 Days', 'texto' => '48.860 %', 'disponibilidad' => 48.86], 'Endpoint period shape');
check($body['data']['sesion_cerrada'] === true && isset($body['data']['consultado_en']), 'Endpoint reports query time and closed session');

config(['services.orion' => ['web_stats_enabled' => false] + $base]);
[$status, $body] = $call('153', fn () => new OrionWebClient);
check($status === 503 && str_contains($body['message'], 'deshabilitada'), 'Disabled by configuration: 503 that says so');
config(['services.orion' => $base]);
[$status, $body] = $call('153', fn () => new FakeWeb(ok(['POST /Orion/Login.aspx' => ['status' => 200, 'body' => $loginHtml]])));
check($status === 503 && str_contains($body['message'], 'rechazó') && ! str_contains(json_encode($body), SECRET), 'Rejected login: clear 503 without secrets');
[$status, $body] = $call('153', fn () => throw new LogicException('internal '.SECRET));
check($status === 503 && $body === ['message' => 'No fue posible consultar Orion.'], 'Unexpected failure: generic 503');

echo "OK orion-availability-stats: {$checks} checks\n";
