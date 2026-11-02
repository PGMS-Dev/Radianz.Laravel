<?php

namespace Radianz\Laravel;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Str;
use Throwable;

final class ErrorSender
{
    public const LEVELS = [
        'error' => 'Error',
        'critical' => 'Critical',
        'alert' => 'Fatal',
        'emergency' => 'Fatal',
    ];

    private bool $sending = false;
    private int $resumeAt = 0;
    private bool $includeMessages;

    public function __construct(
        private ClientInterface $http,
        private array $config,
        private string $application,
        private string $environment,
        private string $basePath,
        private string $groupingKey = '',
    ) {
        $this->includeMessages = filter_var($config['include_messages'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function onLog(MessageLogged $event): void
    {
        if ($this->sending || !$this->configured() || !isset(self::LEVELS[$event->level])) {
            return;
        }

        $probe = $event->context['exception'] ?? null;
        if (!$probe instanceof RadianzTestException && time() < $this->resumeAt) {
            return;
        }
        $this->sending = true;
        try {
            $id = $this->send($event);
            $this->resumeAt = 0;
            if ($probe instanceof RadianzTestException) {
                $probe->acceptedId = $id;
            }
        } catch (Throwable $error) {
            if ($error instanceof TransferException || $error instanceof IngestionException && $error->shouldPause()) {
                $this->resumeAt = time() + 60;
            }
            if ($probe instanceof RadianzTestException) {
                $probe->failure = $error instanceof IngestionException
                    ? $error->getMessage() : 'Ingestion failed. Check the network and TLS connection.';
            }
        } finally {
            $this->sending = false;
        }
    }

    public function configured(): bool
    {
        return is_string($this->config['client_id'] ?? null) && trim($this->config['client_id']) !== ''
            && is_string($this->config['api_key'] ?? null) && trim($this->config['api_key']) !== '';
    }

    public function send(MessageLogged $event): string
    {
        $endpoint = $this->config['endpoint'] ?? null;
        if (!is_string($endpoint) || !filter_var($endpoint, FILTER_VALIDATE_URL) || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new IngestionException('RADIANZ_ENDPOINT must be an HTTPS URL. Check cached configuration.');
        }

        $id = (string) Str::uuid();
        $exception = $event->context['exception'] ?? null;
        $payload = [
            'id' => $id,
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'level' => self::LEVELS[$event->level],
            'applicationName' => mb_substr($this->application, 0, 200),
            'environment' => mb_substr($this->environment, 0, 50),
            'sourceContext' => 'laravel',
            'message' => $exception instanceof Throwable ? $this->className(get_class($exception))
                : $this->logLabel($event),
        ];
        if ($this->includeMessages) {
            $payload['message'] = $this->message((string) $event->message);
        }
        if ($exception instanceof Throwable) {
            $payload['exception'] = $this->exception($exception);
        }

        $headers = ['ClientId' => $this->config['client_id'], 'ApiKey' => $this->config['api_key']];
        if (!empty($this->config['release'])) {
            $headers['X-Application-Version'] = $this->config['release'];
        }
        $response = $this->http->request('POST', $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'allow_redirects' => false,
            'http_errors' => false,
            'connect_timeout' => 2.0,
            'timeout' => 3.0,
        ]);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new IngestionException(in_array($status, [401, 403], true)
                ? "Credentials rejected (HTTP $status). Check the Radianz log token and client ID."
                : "Ingestion refused (HTTP $status).", $status);
        }
        $result = json_decode((string) $response->getBody(), true);
        if (($result['success'] ?? null) !== true || ($result['processedCount'] ?? null) !== 1) {
            throw new IngestionException('The server did not confirm ingestion of one event.');
        }

        return $id;
    }

    private function message(string $text): string
    {
        return mb_substr(mb_scrub($text, 'UTF-8'), 0, 4000);
    }

    private function logLabel(MessageLogged $event): string
    {
        $label = 'Laravel '.$event->level;
        if ($this->groupingKey === '') {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
                $path = $this->relativePath($frame['file'] ?? '');
                if (isset($frame['file']) && !str_starts_with($path, 'vendor/')
                    && !str_starts_with($frame['file'], __DIR__.DIRECTORY_SEPARATOR)) {
                    return $label.' in '.$path.':'.($frame['line'] ?? 0);
                }
            }
            return $label;
        }
        $template = preg_replace('/\b(?:[a-f\d]{8}(?:-[a-f\d]{4}){3}-[a-f\d]{12}|\d+)\b/i', '{id}', (string) $event->message);
        return $label.' ['.substr(hash_hmac('sha256', 'radianz.log:'.$template, $this->groupingKey), 0, 16).']';
    }

    private function exception(Throwable $exception, int $depth = 0): array
    {
        $trace = $exception->getTrace();
        $origin = $this->relativePath($exception->getFile());
        $site = $origin.' '.$this->callableName($trace[0] ?? []);
        $frames = ['#0 '.$origin.':'.$exception->getLine()];
        foreach (array_slice($trace, 0, 24) as $index => $frame) {
            $path = $this->relativePath($frame['file'] ?? '');
            $frames[] = '#'.($index + 1).' '.$path.':'.($frame['line'] ?? 0).' '.$this->callableName($frame);
            if (str_starts_with($origin, 'vendor/') && $path !== '[internal]' && !str_starts_with($path, 'vendor/')) {
                $origin = $path;
                $site .= ' from '.$path.' '.$this->callableName($trace[$index + 1] ?? []);
            }
        }
        // Radianz fingerprints "at " frames. Keep line numbers in the diagnostic frames only.
        array_unshift($frames, 'at '.trim($site));
        $details = [
            'type' => $this->className(get_class($exception)),
            'message' => $this->includeMessages ? $this->message($exception->getMessage()) : $this->className(get_class($exception)),
            'stackTrace' => implode("\n", $frames),
        ];
        if ($depth < 3 && $exception->getPrevious()) {
            $details['innerException'] = $this->exception($exception->getPrevious(), $depth + 1);
        }
        return $details;
    }

    private function className(string $class): string
    {
        return explode("\0", $class, 2)[0];
    }

    private function callableName(array $frame): string
    {
        $function = $frame['function'] ?? '';
        return $this->className($frame['class'] ?? '').($frame['type'] ?? '').
            (str_starts_with($function, '{closure') ? '{closure}' : $function);
    }

    private function relativePath(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';
        $relative = str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
        return $relative ?: '[internal]';
    }
}
