<?php

namespace App\Services\Pipeline;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddingService
{
    public function __construct(
        private string $baseUrl = '',
        private string $apiKey = '',
        private string $model = 'bge-m3',
        private int $dimension = 1024,
        private int $batchSize = 50,
        private int $timeout = 30,
    ) {
        $this->baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : rtrim((string) config('analytics.embeddings.base_url', 'https://api.openai.com/v1'), '/');
        $this->apiKey = $apiKey !== '' ? $apiKey : (string) config('analytics.embeddings.api_key', '');
        $this->model = $model !== 'bge-m3' ? $model : (string) config('analytics.embeddings.model', 'bge-m3');
        $this->dimension = $dimension !== 1024 ? $dimension : (int) config('analytics.embeddings.dimension', 1024);
        $this->batchSize = $batchSize !== 50 ? $batchSize : (int) config('analytics.embeddings.batch_size', 50);
        $this->timeout = $timeout !== 30 ? $timeout : (int) config('analytics.embeddings.timeout', 30);
    }

    /**
     * @return list<float>|null
     */
    public function embedText(string $text): ?array
    {
        $cleanText = trim($text);
        if ($cleanText === '') {
            return null;
        }

        $result = $this->embedBatch([$cleanText]);

        return $result[0] ?? null;
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array
    {
        $filtered = array_filter(array_map('trim', $texts));
        if (empty($filtered)) {
            return [];
        }

        $allEmbeddings = [];
        $chunks = array_chunk($filtered, $this->batchSize);

        foreach ($chunks as $chunk) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                    ->timeout($this->timeout)
                    ->post("{$this->baseUrl}/embeddings", [
                        'model' => $this->model,
                        'input' => array_values($chunk),
                    ]);

                if ($response->successful()) {
                    $data = $response->json('data');
                    if (is_array($data)) {
                        foreach ($data as $item) {
                            $embedding = $item['embedding'] ?? [];
                            if (is_array($embedding) && count($embedding) === $this->dimension) {
                                $allEmbeddings[] = array_map('floatval', $embedding);
                            }
                        }
                    }
                } else {
                    Log::error("Embedding API error: {$response->status()} - {$response->body()}");
                }
            } catch (\Throwable $e) {
                Log::error("Embedding request failed: {$e->getMessage()}", [
                    'exception' => $e,
                ]);
            }
        }

        return $allEmbeddings;
    }
}
