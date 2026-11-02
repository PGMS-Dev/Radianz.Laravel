<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;
use Radianz\Laravel\ErrorSender;
use Radianz\Laravel\RadianzServiceProvider;
use Illuminate\Log\Events\MessageLogged;

require __DIR__.'/vendor/autoload.php';

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

foreach (['bootstrap/cache', 'storage/logs', 'config', 'app'] as $directory) {
    if (!is_dir(__DIR__.'/'.$directory)) {
        mkdir(__DIR__.'/'.$directory, 0777, true);
    }
}
$app = Application::configure(basePath: __DIR__)->withExceptions()->create();
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
check($app->getProvider(RadianzServiceProvider::class) !== null, 'Composer package discovery failed');
config(['logging.default' => 'single']);
$defaults = config('radianz');
foreach ([null, ['client_id' => 'test-client']] as $incomplete) {
    config(['radianz' => $incomplete]);
    $app->forgetInstance(ErrorSender::class);
    report(new RuntimeException('An incomplete connector must not break exception handling'));
    check($kernel->call('radianz:test') === 1 && str_contains($kernel->output(), 'RADIANZ_API_KEY'), 'Incomplete configuration');
}
config(['radianz' => $defaults]);
$app->forgetInstance(ErrorSender::class);
check($kernel->call('radianz:test') === 1, 'Expected a diagnostic for missing credentials');
config(['radianz.api_key' => 'test-key', 'radianz.client_id' => 'test-client', 'radianz.release' => 'test-release']);
$accepted = new Response(200, [], '{"success":true,"processedCount":1}');
$mock = new MockHandler([$accepted, $accepted, new Response(503), new Response(401), $accepted]);
$requests = [];
$stack = HandlerStack::create($mock);
$stack->push(Middleware::history($requests));
$sender = new ErrorSender(new Client(['handler' => $stack]), config('radianz'), 'Matrix', 'qa', __DIR__);
$app->instance(ErrorSender::class, $sender);

Log::warning('Ignored by Radianz');
report(new RuntimeException('secret-sentinel', 0, new LogicException('secret-sentinel')));
Log::critical('secret-sentinel', ['token' => 'secret-sentinel']);
check(count($requests) === 2, 'Expected one reported exception and one critical log');
$request = $requests[0]['request'];
$body = (string) $request->getBody();
$data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
check($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/api/v1/logs', 'Ingestion endpoint');
check($request->getHeaderLine('ClientId') === 'test-client' && $request->getHeaderLine('ApiKey') === 'test-key', 'Credentials');
check($request->getHeaderLine('X-Application-Version') === 'test-release', 'Release header');
check($data['level'] === 'Error' && $data['environment'] === 'qa', 'Level and environment');
check($data['exception']['type'] === RuntimeException::class && $data['exception']['innerException']['type'] === LogicException::class, 'Exception chain');
check(str_contains($data['exception']['stackTrace'], 'check.php:'), 'Throw location');
check(!str_contains($body, 'secret-sentinel') && !str_contains((string) $requests[1]['request']->getBody(), 'secret-sentinel'), 'Private data reached transport');
check(json_decode((string) $requests[1]['request']->getBody(), true)['level'] === 'Critical', 'Critical level');
Log::error('Application continues on ingestion failure');
Log::error('Do not block the worker again during the same outage');
check(count($requests) === 3, 'Unexpected retry');
check($kernel->call('radianz:test') === 1 && str_contains($kernel->output(), 'Credentials rejected'), 'Invalid-key diagnostic');
check($kernel->call('radianz:test') === 0 && str_contains($kernel->output(), 'Accepted'), 'Artisan smoke test');
config(['logging.channels.single.level' => 'critical']);
Log::forgetChannel('single');
check($kernel->call('radianz:test') === 1 && str_contains($kernel->output(), 'channel level'), 'Filtered diagnostic must fail');
check(count($requests) === 5, 'Filtered diagnostic should not bypass the logger');

function fromCheckout(): RuntimeException
{
    return new RuntimeException('private-outer', 0, new LogicException('private-inner'));
}

function fromRefund(): RuntimeException
{
    return new RuntimeException('private-outer');
}

function payloadFor(array $overrides, MessageLogged $event): array
{
    $mock = new MockHandler([new Response(200, [], '{"success":true,"processedCount":1}')]);
    $requests = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($requests));
    $sender = new ErrorSender(new Client(['handler' => $stack]), array_replace(config('radianz'), $overrides), 'Matrix', 'qa', __DIR__, 'application-test-key');
    $sender->send($event);
    return json_decode((string) $requests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
}

$error = new MessageLogged('error', 'private-main', ['exception' => fromCheckout()]);
foreach ([null, false, 'false', 'off', 'no', 'typo', true, 'true'] as $setting) {
    $payload = payloadFor(['include_messages' => $setting], $error);
    $expected = $setting === true || $setting === 'true';
    check(($payload['message'] === 'private-main') === $expected, 'Top-level message opt-in');
    check(($payload['exception']['message'] === 'private-outer') === $expected, 'Exception message opt-in');
    check(($payload['exception']['innerException']['message'] === 'private-inner') === $expected, 'Nested message opt-in');
}
$baseline = payloadFor([], $error)['exception']['stackTrace'];
$repeat = payloadFor([], new MessageLogged('error', 'private-main', ['exception' => fromCheckout()]))['exception']['stackTrace'];
$distinct = payloadFor([], new MessageLogged('error', 'private-main', ['exception' => fromRefund()]))['exception']['stackTrace'];
$symbol = explode("\n", $baseline)[0];
check($symbol === 'at check.php fromCheckout' && !str_contains($baseline, __DIR__), 'Stable symbol and private relative paths');
check($baseline !== $repeat && $symbol === explode("\n", $repeat)[0], 'Different call lines must share the symbolic frame');
check($symbol !== explode("\n", $distinct)[0], 'Different methods must remain distinct');
$manual = payloadFor([], new MessageLogged('error', 'Order 123 failed'))['message'];
check($manual === payloadFor(['api_key' => 'rotated'], new MessageLogged('error', 'Order 456 failed'))['message'], 'Stable manual group after key rotation');
check($manual !== payloadFor([], new MessageLogged('error', 'Shipment 456 failed'))['message'], 'Different manual log templates');
echo 'PASS PHP '.PHP_VERSION.' / Laravel '.$app->version().PHP_EOL;
