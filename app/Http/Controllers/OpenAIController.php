<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use App\Services\FileLogger;
use GuzzleHttp\Exception\GuzzleException;
use App\Services\OpenAI\ImageTokenCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class OpenAIController extends Controller
{
    private const OPENAI_API_URL = 'https://api.openai.com/';

    /**
     * @var Client
     */
    private Client $client;

    /**
     * @var FileLogger
     */
    private FileLogger $logger;

    /**
     * @var Gpt3Tokenizer
     */
    private Gpt3Tokenizer $tokenizer;

    private const ALLOWED_SIZES = [
        '256x256',
        '512x512',
        '1024x1024',
        '1792x1024',
        '1024x1792'
    ];

    private const QUALITY_MULTIPLIERS = [
        'standard' => 1,
        'hd' => 2
    ];

    private const MODEL_ENCODINGS = [
        //GPT-4o models
        'gpt-4o' => 'o200k_base',
        'gpt-o1' => 'o200k_base',
        // GPT-4 models
        'gpt-4' => 'cl100k_base',
        'gpt-4-0314' => 'cl100k_base',
        'gpt-4-0613' => 'cl100k_base',
        'gpt-4-32k' => 'cl100k_base',
        'gpt-4-32k-0314' => 'cl100k_base',
        'gpt-4-32k-0613' => 'cl100k_base',
        // GPT-3.5 models
        'gpt-3.5-turbo' => 'cl100k_base',
        'gpt-3.5-turbo-0301' => 'cl100k_base',
        'gpt-3.5-turbo-0613' => 'cl100k_base',
        'gpt-3.5-turbo-16k' => 'cl100k_base',
        'gpt-3.5-turbo-16k-0613' => 'cl100k_base',
        // Legacy models
        'text-davinci-003' => 'p50k_base',
        'text-davinci-002' => 'p50k_base',
        'text-curie-001' => 'p50k_base',
        'text-babbage-001' => 'p50k_base',
        'text-ada-001' => 'p50k_base',
        'davinci' => 'p50k_base',
        'curie' => 'p50k_base',
        'babbage' => 'p50k_base',
        'ada' => 'p50k_base',
        // Whisper models (for speech-to-text tasks)
        'whisper-1' => 'cl100k_base'
    ];

    public function __construct(FileLogger $logger)
    {
        $this->logger = $logger;
        $this->tokenizer = new Gpt3Tokenizer(new Gpt3TokenizerConfig());
        $this->client = new Client([
            'base_uri' => self::OPENAI_API_URL,
            'headers' => [
                'Authorization' => 'Bearer ' . config('openai.api_key'),
                'Content-Type' => 'application/json',
            ]
        ]);
    }

    /**
     * Proxy request to OpenAI Chat Completions
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function chatCompletions(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/chat/completions', $request);
    }

    /**
     * Proxy request to OpenAI Completions
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function completions(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/completions', $request);
    }

    /**
     * Proxy request to OpenAI Batch
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batches(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/batches', $request);
    }

    /**
     * Proxy request to OpenAI Batch and retrieve a batch
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function retrieveBatch(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/batches/' . $request->route('batchId'), $request);
    }

    /**
     * Proxy request to OpenAI Batch and cancel a batch
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelBatch(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/batches/' . $request->route('batchId') . '/cancel', $request);
    }

    /**
     * Proxy request to OpenAI to Upload a file
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function uploadFile(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/files', $request);
    }

    /**
     * Proxy request to OpenAI to retrieve a file
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function retrieveFile(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/files/' . $request->route('fileId'), $request);
    }

    /**
     * Proxy request to OpenAI to retrieve a file Content
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function retrieveFileContent(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/files/' . $request->route('fileId') . '/content', $request);
    }

    /**
     * Proxy request to OpenAI to delete a file
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteFile(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/files/' . $request->route('fileId'), $request);
    }

    /**
     * Create images with DALL·E 3
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createImage(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/images/generations', $request);
    }

    /**
     * Edit images with DALL·E
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function editImage(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/images/edits', $request);
    }

    /**
     * Create image variations
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createImageVariation(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/images/variations', $request);
    }

    /**
     * Create moderation check
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createModeration(Request $request): JsonResponse
    {
        return $this->proxyToOpenAI('/moderations', $request);
    }

    /**
     * Validate Image Request
     *
     * @param Request $request
     * @return JsonResponse|null
     */
    private function validateImageRequest(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'prompt' => 'required_without:image|string|max:4000',
            'size' => 'sometimes|in:256x256,512x512,1024x1024,1792x1024,1024x1792',
            'quality' => 'sometimes|in:standard,hd',
            'n' => 'sometimes|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'details' => $validator->errors(),
                'timestamp_utc' => gmdate('Y-m-d H:i:s')
            ], 422);
        }

        return null;
    }

    /**
     * Generic method to proxy requests to OpenAI
     *
     * @param string $endpoint
     * @param Request $request
     * @return JsonResponse
     */
    private function proxyToOpenAI(string $endpoint, Request $request): JsonResponse
    {

        try {
            $userId = $request->header('X-WP-User-ID');
            if (!$userId || !is_numeric($userId)) {
                return response()->json([
                    'error' => 'Invalid user ID',
                    'timestamp_utc' => gmdate('Y-m-d H:i:s')
                ], 401);
            }
            $stats = $this->logger->getUserStats($userId);
            $totalAllowed = ApiKey::where('api_key', $request->header('X-API-Key'))->value('total_tokens_allocated');
            $remainingTokens = $totalAllowed - $stats['total_tokens'];

            // token calculation before making the request
            $requestBody = $request->all();
            if (!$this->validateRequest($requestBody, $endpoint)) {
                return response()->json([
                    'error' => 'Invalid request parameters',
                    'timestamp_utc' => gmdate('Y-m-d H:i:s')
                ], 422);
            }
            $estimatedTokens = $this->calculateRequestTokens($requestBody, $endpoint);

            // check if estimated tokens would exceed remaining tokens
            if ($estimatedTokens > $remainingTokens) {
                return response()->json([
                    'error' => 'Insufficient tokens',
                    'remaining_tokens' => $remainingTokens,
                    'estimated_required' => $estimatedTokens,
                    'timestamp_utc' => gmdate('Y-m-d H:i:s')
                ], 403);
            }

            $cacheKey = 'api_calls:' . $userId . ':' . date('Y-m-d-H-i');
            $calls = Cache::get($cacheKey, 0);
            if ($calls >= 60) {
                return response()->json([
                    'error' => 'Rate limit exceeded',
                    'timestamp_utc' => gmdate('Y-m-d H:i:s')
                ], 429);
            }
            Cache::put($cacheKey, $calls + 1, config('openai.rate_limit', 60));

            $response = $this->client->post('v1' . $endpoint, [
                'json' => $requestBody,
                'http_errors' => false
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json([
                    'error' => 'Invalid response from OpenAI',
                    'timestamp_utc' => gmdate('Y-m-d H:i:s')
                ], 500);
            }
            return response()->json($responseBody, $response->getStatusCode());

        } catch (GuzzleException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            $errorMessage = match ($statusCode) {
                401 => 'Authentication error with OpenAI',
                429 => 'OpenAI rate limit exceeded',
                500, 502, 503, 504 => 'OpenAI service temporarily unavailable',
                default => 'OpenAI API request failed'
            };

            return response()->json([
                'error' => $errorMessage,
                'message' => $e->getMessage(),
                'timestamp_utc' => gmdate('Y-m-d H:i:s'),
                'wp_user_id' => $request->header('X-WP-User-ID')
            ], $statusCode);
        }
    }


    /**
     * calculate the tokens that the request MIGHT use
     *
     * @param array $requestBody
     * @param string $endpoint
     * @return int
     */
    private function calculateRequestTokens(array $requestBody, string $endpoint): int
    {
        $promptTokens = 0;

        // change normal image generation to use the new token calculation
        if (str_starts_with($endpoint, '/images')) {
            $tokenCalculation = ImageTokenCalculator::calculateTokens($requestBody);
            return $tokenCalculation['total_tokens'];
        }

        switch ($endpoint) {
            case '/chat/completions':
                if (isset($requestBody['messages'])) {
                    foreach ($requestBody['messages'] as $message) {
                        $promptTokens += count($this->tokenizer->encode($message['content']));
                    }
                }
                break;

            case '/completions':
                if (isset($requestBody['prompt'])) {
                    if (is_array($requestBody['prompt'])) {
                        foreach ($requestBody['prompt'] as $prompt) {
                            $promptTokens += count($this->tokenizer->encode($prompt));
                        }
                    } else {
                        $promptTokens += count($this->tokenizer->encode($requestBody['prompt']));
                    }
                }
                break;

            case '/moderations':
                if (isset($requestBody['input'])) {
                    if (is_array($requestBody['input'])) {
                        foreach ($requestBody['input'] as $text) {
                            $promptTokens += count($this->tokenizer->encode($text));
                        }
                    } else {
                        $promptTokens += count($this->tokenizer->encode($requestBody['input']));
                    }
                }
                break;
        }

        // Add 10% safety buffer
        return (int) ceil($promptTokens * 1.1);
    }

    /**
     * Validate Request
     *
     * @param array $requestBody
     * @param string $endpoint
     * @return bool
     */
    private function validateRequest(array $requestBody, string $endpoint): bool
    {
        $validators = [
            '/chat/completions' => [
                'messages' => 'required|array',
                'messages.*.role' => 'required|string|in:system,user,assistant',
                'messages.*.content' => 'required|string',
                'model' => 'required|string|in:' . implode(',', array_keys(self::MODEL_ENCODINGS))
            ],
            '/completions' => [
                'prompt' => 'required|string'
            ],
            '/images/generations' => [
                'prompt' => 'required|string|max:4000',
                'size' => 'sometimes|string|in:' . implode(',', self::ALLOWED_SIZES)
            ]
        ];

        if (isset($validators[$endpoint])) {
            $validator = Validator::make($requestBody, $validators[$endpoint]);
            return !$validator->fails();
        }
        return true;
    }
}
