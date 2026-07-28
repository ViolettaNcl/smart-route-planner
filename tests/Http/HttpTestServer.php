<?php

namespace Tests\Http;

/**
 * Мини-обвязка для HTTP-интеграционных тестов: поднимает встроенный
 * веб-сервер PHP (`php -S`) в отдельном процессе, направленный на папку
 * public/ — то есть тесты стучатся по-настоящему через HTTP в реальные
 * api/*.php файлы, а не вызывают классы напрямую.
 *
 * Зачем это отдельно от обычных unit-тестов (см. RoutePlannerTest и др.):
 * unit-тесты в этом проекте проверяют классы (App\RoutePlanner,
 * App\ML\KMeansDaySplitter, ...) напрямую, с поддельными зависимостями
 * (FakeGeocoder и т.п.) — они не видят HTTP-обвязку: разбор $_POST,
 * коды ответа (405/422/429), заголовки, JSON-энкодинг. Баг в этом слое
 * ("забыли проверить входные данные в конкретном api/*.php") unit-тесты
 * просто не заметят. HttpTestServer закрывает именно этот пробел.
 */
class HttpTestServer
{
    private $process = null;
    private array $pipes = [];
    private int $port;
    private string $baseUrl;

    public function __construct(private string $publicDir)
    {
        $this->port = 8100 + random_int(0, 800);
        $this->baseUrl = "http://127.0.0.1:{$this->port}";
    }

    public function start(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = sprintf(
            'php -S 127.0.0.1:%d -t %s',
            $this->port,
            escapeshellarg($this->publicDir)
        );

        $this->process = proc_open($command, $descriptors, $this->pipes);

        if ($this->process === false) {
            throw new \RuntimeException('Не удалось запустить php -S для HTTP-тестов.');
        }

        // Не блокируем чтение stdout/stderr дочернего процесса, иначе можем
        // зависнуть, если PHP dev server решит туда что-то написать.
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->waitUntilReady();
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                return;
            }
            usleep(50_000);
        }

        throw new \RuntimeException("php -S на порту {$this->port} не поднялся за 5 секунд.");
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    public function post(string $path, array $formData): array
    {
        return $this->request('POST', $path, http_build_query($formData));
    }

    /**
     * @return array{status: int, body: array<mixed>|null, raw: string}
     */
    private function request(string $method, string $path, ?string $body): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 10,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return ['status' => $status, 'body' => $decoded, 'raw' => (string) $raw];
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_get_status($this->process);
        if ($status['running'] ?? false) {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
        $this->process = null;
    }
}
