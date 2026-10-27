<?php

declare(strict_types=1);

/**
 * Petit client PHP pour l'API REST de NovaBase (cURL, aucune dépendance).
 * Chaque appel est noté dans le journal (session) pour voir ce qui passe réellement.
 */
final class NovaBase
{
    private const int LOG_SIZE = 20;

    public function __construct(private readonly string $url, private readonly string $key)
    {
    }

    /** @return array{status: int, data: mixed, total: int|null, ms: float, error: string|null, fields: array<string, string>} */
    public function info(): array
    {
        return $this->request('GET', '/');
    }

    /**
     * @param array<string, string|int> $query filtres NovaBase : ['nom' => 'ilike.*café*', 'order' => 'prix.desc', 'limit' => 10]
     *
     * @return array{status: int, data: mixed, total: int|null, ms: float, error: string|null, fields: array<string, string>}
     */
    public function list(string $table, array $query = []): array
    {
        return $this->request('GET', '/data/' . rawurlencode($table), $query);
    }

    /** @return array{status: int, data: mixed, total: int|null, ms: float, error: string|null, fields: array<string, string>} */
    public function find(string $table, int|string $id): array
    {
        return $this->request('GET', '/data/' . rawurlencode($table) . '/' . rawurlencode((string) $id));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{status: int, data: mixed, total: int|null, ms: float, error: string|null, fields: array<string, string>}
     */
    public function create(string $table, array $data): array
    {
        return $this->request('POST', '/data/' . rawurlencode($table), [], $data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{status: int, data: mixed, total: int|null, ms: float, error: string|null, fields: array<string, string>}
     */
    public function update(string $table, int|string $id, array $data): array
    {
        return $this->request('PATCH', '/data/' . rawurlencode($table) . '/' . rawurlencode((string) $id), [], $data);
    }

    /** @return array{status: int, data: mixed, total: int|null, ms: float, error: string|null, fields: array<string, string>} */
    public function delete(string $table, int|string $id): array
    {
        return $this->request('DELETE', '/data/' . rawurlencode($table) . '/' . rawurlencode((string) $id));
    }

    /**
     * Suppression groupée : toutes les lignes qui correspondent aux filtres (un filtre obligatoire).
     * Exemples : ['id' => 'in.(3,7,9)'] ou ['id' => 'gt.0'] pour tout supprimer.
     *
     * @param array<string, string|int> $filters
     *
     * @return array{status: int, data: mixed, total: int|null, ms: float, error: string|null, fields: array<string, string>}
     */
    public function deleteWhere(string $table, array $filters): array
    {
        return $this->request('DELETE', '/data/' . rawurlencode($table), $filters);
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, data: mixed, total: int|null, ms: float, error: string|null, fields: array<string, string>}
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = rtrim($this->url, '/') . ($path === '/' ? '' : $path) . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $headers = ['apikey: ' . $this->key, 'Accept: application/json'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $started = microtime(true);
        $raw = curl_exec($ch);
        $ms = round((microtime(true) - $started) * 1000, 1);
        $shownPath = ($path === '/' ? '/' : $path) . ($query !== [] ? '?' . urldecode(http_build_query($query)) : '');

        if (!is_string($raw)) {
            $result = ['status' => 0, 'data' => null, 'total' => null, 'ms' => $ms, 'error' => 'Serveur injoignable : ' . curl_error($ch), 'fields' => []];
            self::log($method, $shownPath, $result);

            return $result;
        }

        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $total = preg_match('/^x-total-count:\s*(\d+)/mi', substr($raw, 0, $headerSize), $m) === 1 ? (int) $m[1] : null;
        $text = substr($raw, $headerSize);
        $data = $text !== '' ? json_decode($text, true) : null;

        $error = null;
        $fields = [];
        if ($status >= 400 || $status === 0) {
            $err = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
            $error = is_string($err['message'] ?? null) ? $err['message'] : 'Réponse inattendue du serveur (HTTP ' . $status . ').';
            $rawFields = $err['details']['fields'] ?? $err['fields'] ?? [];
            foreach (is_array($rawFields) ? $rawFields : [] as $field => $message) {
                $fields[(string) $field] = (string) $message;
            }
            if ($data === null && $status === 404) {
                $error = 'Adresse introuvable (404) : vérifiez NOVABASE_URL dans config.php.';
            }
        }

        $result = ['status' => $status, 'data' => $data, 'total' => $total, 'ms' => $ms, 'error' => $error, 'fields' => $fields];
        self::log($method, $shownPath, $result);

        return $result;
    }

    /** @return list<array{method: string, path: string, status: int, ms: float, time: string}> */
    public static function journal(): array
    {
        return $_SESSION['api_log'] ?? [];
    }

    public static function clearJournal(): void
    {
        $_SESSION['api_log'] = [];
    }

    /** @param array{status: int, ms: float} $result */
    private static function log(string $method, string $path, array $result): void
    {
        $log = $_SESSION['api_log'] ?? [];
        array_unshift($log, ['method' => $method, 'path' => $path, 'status' => $result['status'], 'ms' => $result['ms'], 'time' => date('H:i:s')]);
        $_SESSION['api_log'] = array_slice($log, 0, self::LOG_SIZE);
    }
}
