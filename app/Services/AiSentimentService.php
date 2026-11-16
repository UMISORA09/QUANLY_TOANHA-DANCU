<?php

namespace App\Services;

use App\Models\Suggestion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiSentimentService
{
    /**
     * Check if a real AI provider is configured in environment.
     */
    public function isAvailable(): bool
    {
        $geminiKey = config('services.gemini.key', env('GEMINI_API_KEY'));
        $openAiKey = config('services.openai.key', env('OPENAI_API_KEY'));

        return filled($geminiKey) || filled($openAiKey);
    }

    /**
     * Perform sentiment analysis on suggestion content.
     *
     * @return array{status: string, sentiment?: string, score?: float, message?: string}
     */
    public function analyze(string $title, string $contentText): array
    {
        if (! $this->isAvailable()) {
            return [
                'status' => 'BLOCKED',
                'message' => 'AI INTEGRATION BLOCKED: Không có AI provider hoặc NLP service nào được cấu hình trong hệ thống.',
            ];
        }

        // Only send sanitized title and content. Never send personal data.
        $textToAnalyze = trim($title."\n\n".$contentText);

        $geminiKey = config('services.gemini.key', env('GEMINI_API_KEY'));
        if (filled($geminiKey)) {
            return $this->analyzeWithGemini($textToAnalyze, $geminiKey);
        }

        $openAiKey = config('services.openai.key', env('OPENAI_API_KEY'));
        if (filled($openAiKey)) {
            return $this->analyzeWithOpenAi($textToAnalyze, $openAiKey);
        }

        return [
            'status' => 'BLOCKED',
            'message' => 'AI INTEGRATION BLOCKED: Nhà cung cấp AI không khả dụng.',
        ];
    }

    /**
     * Safely analyze and update suggestion sentiment without throwing fatal exceptions.
     */
    public function analyzeSuggestion(Suggestion $suggestion): array
    {
        try {
            $result = $this->analyze($suggestion->title, $suggestion->content_text);

            if ($result['status'] === 'SUCCESS' && isset($result['sentiment'], $result['score'])) {
                $suggestion->update([
                    'ai_sentiment' => $result['sentiment'],
                    'ai_sentiment_score' => $result['score'],
                ]);

                return $result;
            }

            // Provider is not configured or blocked: keep NOT_ANALYZED
            $suggestion->update([
                'ai_sentiment' => Suggestion::SENTIMENT_NOT_ANALYZED,
                'ai_sentiment_score' => null,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Lỗi khi phân tích cảm xúc góp ý bằng AI: '.$e->getMessage(), [
                'suggestion_id' => $suggestion->id,
            ]);

            return [
                'status' => 'FAILED',
                'message' => 'AI analysis failed: '.$e->getMessage(),
            ];
        }
    }

    protected function analyzeWithGemini(string $text, string $apiKey): array
    {
        $prompt = "Bạn là chuyên gia phân tích cảm xúc phản hồi/góp ý của cư dân tòa nhà.\n".
            "Hãy phân tích mức độ cảm xúc của đoạn văn bản sau và trả về DUY NHẤT một JSON hợp lệ dạng:\n".
            "{\"sentiment\": \"POSITIVE|NEUTRAL|NEGATIVE|VERY_ANGRY\", \"score\": 0.00}\n".
            "Trong đó 'score' là một số thực trong khoảng từ -1.00 (cực kỳ bức xúc, giận dữ) đến +1.00 (hài lòng, khen ngợi).\n".
            "Văn bản cần phân tích:\n\"\"\"".$text.'"""';

        $response = Http::timeout(10)->retry(2, 500)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key='.$apiKey,
            [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                ],
            ]
        );

        if (! $response->successful()) {
            return [
                'status' => 'FAILED',
                'message' => 'Gemini API trả về lỗi: '.$response->status(),
            ];
        }

        $jsonString = $response->json('candidates.0.content.parts.0.text');
        $parsed = json_decode((string) $jsonString, true);

        if (! is_array($parsed) || ! isset($parsed['sentiment'], $parsed['score'])) {
            return [
                'status' => 'FAILED',
                'message' => 'Phản hồi từ Gemini không khớp định dạng mong đợi.',
            ];
        }

        $validSentiments = ['POSITIVE', 'NEUTRAL', 'NEGATIVE', 'VERY_ANGRY'];
        $sentiment = in_array($parsed['sentiment'], $validSentiments, true) ? $parsed['sentiment'] : 'NEUTRAL';
        $score = max(-1.00, min(1.00, (float) $parsed['score']));

        return [
            'status' => 'SUCCESS',
            'sentiment' => $sentiment,
            'score' => $score,
        ];
    }

    protected function analyzeWithOpenAi(string $text, string $apiKey): array
    {
        $response = Http::timeout(10)->retry(2, 500)->withToken($apiKey)->post(
            'https://api.openai.com/v1/chat/completions',
            [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Bạn là chuyên gia phân tích cảm xúc phản ánh cư dân. Trả về JSON: {"sentiment": "POSITIVE"|"NEUTRAL"|"NEGATIVE"|"VERY_ANGRY", "score": float from -1.00 to 1.00}.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $text,
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
            ]
        );

        if (! $response->successful()) {
            return [
                'status' => 'FAILED',
                'message' => 'OpenAI API trả về lỗi: '.$response->status(),
            ];
        }

        $jsonString = $response->json('choices.0.message.content');
        $parsed = json_decode((string) $jsonString, true);

        if (! is_array($parsed) || ! isset($parsed['sentiment'], $parsed['score'])) {
            return [
                'status' => 'FAILED',
                'message' => 'Phản hồi từ OpenAI không khớp định dạng mong đợi.',
            ];
        }

        $validSentiments = ['POSITIVE', 'NEUTRAL', 'NEGATIVE', 'VERY_ANGRY'];
        $sentiment = in_array($parsed['sentiment'], $validSentiments, true) ? $parsed['sentiment'] : 'NEUTRAL';
        $score = max(-1.00, min(1.00, (float) $parsed['score']));

        return [
            'status' => 'SUCCESS',
            'sentiment' => $sentiment,
            'score' => $score,
        ];
    }
}
