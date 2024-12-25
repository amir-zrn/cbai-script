<?php

namespace App\Http\Middleware;

use App\Services\FileLogger;
use App\Services\OpenAI\ImageTokenCalculator;
use Closure;
use App\Models\ApiKey;
use App\Models\ApiUsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrackApiUsage
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $currentUtc = gmdate('Y-m-d H:i:s');

        $wpUserId = $request->header('X-WP-User-ID');
        if (!$wpUserId) {
            return response()->json([
                'error' => 'WordPress User ID is required',
                'timestamp_utc' => $currentUtc
            ], 401);
        }

        // validate api key
        $clientApiKey = $request->header('X-API-Key') ?? $request->query('api_key');
        if (!$clientApiKey) {
            return response()->json([
                'error' => 'API key is required',
                'timestamp_utc' => $currentUtc,
                'wp_user_id' => $wpUserId
            ], 401);
        }

        $apiKeyModel = ApiKey::where('api_key', $clientApiKey)
            ->where('is_active', true)
            ->where('wp_user_id', $wpUserId)
            ->first();

        if (!$apiKeyModel) {
            return response()->json([
                'error' => 'Invalid or inactive API key',
                'timestamp_utc' => $currentUtc,
                'wp_user_id' => $wpUserId
            ], 401);
        }

        // proccess the request through OpenAIظ
        $response = $next($request);

        if (str_starts_with($request->getPathInfo(), '/v1/images')) {
            $requestData = $request->all();
            $tokensUsed = app(ImageTokenCalculator::class)->calculateTokens($requestData)['total_tokens'];
        } else {
            $responseData = json_decode($response->getContent(), true);
            $tokensUsed = $responseData['usage']['total_tokens'] ?? 0;
        }

        if (($apiKeyModel->tokens_used + $tokensUsed) > $apiKeyModel->total_tokens_allocated) {
            return response()->json([
                'error' => 'Token limit exceeded',
                'tokens_remaining' => $apiKeyModel->total_tokens_allocated - $apiKeyModel->tokens_used,
                'tokens_required' => $tokensUsed,
                'timestamp_utc' => $currentUtc,
                'wp_user_id' => $wpUserId
            ], 429);
        }

        try {
            // create new log of how much user used in this request
            app(FileLogger::class)->log([
                'api_key_id' => $apiKeyModel->id,
                'wp_user_id' => $wpUserId,
                'endpoint' => $request->getPathInfo(),
                'method' => $request->method(),
                'tokens_used' => $tokensUsed,
                'ip_address' => $request->ip(),
                'request_data' => [
                    'endpoint' => $request->getPathInfo(),
                    'params' => $request->all()
                ],
                'response_data' => [
                    'usage' => $responseData['usage'] ?? null,
                    'model' => $responseData['model'] ?? null
                ],
                'response_status' => $response->getStatusCode()
            ]);

            // update total token is used by the user
            $apiKeyModel->increment('tokens_used', $tokensUsed);

        } catch (\Exception $e) {
            Log::error('Failed to log API usage', [
                'error' => $e->getMessage(),
                'api_key' => $clientApiKey,
                'wp_user_id' => $wpUserId,
                'timestamp_utc' => $currentUtc
            ]);
        }

        return $response;
    }
}
