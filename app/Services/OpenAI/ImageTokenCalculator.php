<?php

namespace App\Services\OpenAI;


use Gioni06\Gpt3Tokenizer\Gpt3Tokenizer;
use Gioni06\Gpt3Tokenizer\Gpt3TokenizerConfig;

class ImageTokenCalculator
{
    private const BASE_COST = 1000; // base token cost for 256x256 resolution
    private static ?Gpt3Tokenizer $tokenizer = null;

    private static function getTokenizer(): Gpt3Tokenizer
    {
        if (self::$tokenizer === null) {
            self::$tokenizer = new Gpt3Tokenizer(new Gpt3TokenizerConfig());
        }
        return self::$tokenizer;
    }

    /**
     * Calculate the total token cost of an image generation request
     *
     * @param array $requestBody
     * @return array
     */
    public static function calculateTokens(array $requestBody): array
    {
        $numImages = $requestBody['n'] ?? 1;
        $size = $requestBody['size'] ?? '1024x1024';
        $prompt = $requestBody['prompt'] ?? '';
        $isVariation = isset($requestBody['image']) && isset($requestBody['variations']);
        $isEdit = isset($requestBody['image']) && isset($requestBody['mask']);

        $baseCost = self::BASE_COST;
        $baseCost = self::adjustForResolution($baseCost, $size);
        $baseCost = self::adjustForOperationType($baseCost, $isVariation, $isEdit);
        $promptCost = self::calculatePromptCost($prompt);

        $totalCost = ($baseCost * $numImages) + $promptCost;

        return [
            'total_tokens' => $totalCost,
            'breakdown' => [
                'base_cost' => $baseCost,
                'num_images' => $numImages,
                'prompt_cost' => $promptCost,
                'size' => $size,
                'type' => $isVariation ? 'variation' : ($isEdit ? 'edit' : 'generation')
            ]
        ];
    }

    /**
     * generate the cost based on the resolution
     *
     * @param int $baseCost
     * @param string $size
     * @return int
     */
    private static function adjustForResolution(int $baseCost, string $size): int
    {
        return match ($size) {
            '256x256' => $baseCost,
            '512x512' => $baseCost * 2,
            '1024x1024' => $baseCost * 4,
            '1792x1024', '1024x1792' => $baseCost * 6,
            default => $baseCost
        };
    }

    /**
     * adjust for operation type (request for a variation of image or edited version)
     *
     * @param int $baseCost
     * @param bool $isVariation
     * @param bool $isEdit
     * @return int
     */
    private static function adjustForOperationType(int $baseCost, bool $isVariation, bool $isEdit): int
    {
        if ($isVariation) {
            return (int)($baseCost * 0.5);
        } elseif ($isEdit) {
            return (int)($baseCost * 0.75);
        }
        return $baseCost;
    }

    /**
     * calculate the cost of the prompt of image generation
     *
     * @param string $prompt
     * @return int
     */
    private static function calculatePromptCost(string $prompt): int
    {
        return count(self::getTokenizer()->encode($prompt));
    }
}
