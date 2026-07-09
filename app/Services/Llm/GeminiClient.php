<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cheapest practical LLM+web path for this app:
 * Google Gemini Flash + Google Search grounding (built-in web surf).
 *
 * Free tier is usually enough for personal use; paid Flash is still far cheaper
 * than GPT-4o / Claude with a separate search API.
 */
class GeminiClient
{
    public function enabled(): bool
    {
        return filled(config('market_screenr.gemini.api_key'));
    }

    /**
     * @param  array<string, mixed>  $context  Structured stock + preference payload
     * @return array{text: string, model: string, grounded: bool, truncated: bool, finish_reason: ?string}
     */
    public function analyzeStock(array $context, string $question): array
    {
        $apiKey = config('market_screenr.gemini.api_key');
        $model = config('market_screenr.gemini.model');
        $maxOutputTokens = (int) config('market_screenr.gemini.max_output_tokens', 8192);

        if (! $apiKey) {
            throw new \RuntimeException('GEMINI_API_KEY is not set.');
        }

        $system = <<<'PROMPT'
You are a cautious Indian equity research assistant for a personal dashboard.

Rules:
- Use the structured metrics JSON as ground truth for numbers (price, PE, ROCE, holdings, scores).
- You may use Google Search for recent news, quarterly results, concalls, and broker commentary.
- Never invent financial figures that contradict the provided metrics.
- Clearly separate: (1) dashboard facts, (2) web facts, (3) your judgment.
- Format the entire answer in Markdown with short headings and bullet lists.
- Structure:
  ## Snapshot
  ## Valuation & quality
  ## Ownership / flows
  ## Recent developments (web)
  ## Verdict (Enter / Wait / Avoid)
  ## Bull case
  ## Bear case
  ## What would change my mind
  ## Next checks
- Be complete — do not stop mid-sentence. Aim for 500–900 words if the name needs it.
PROMPT;

        $userPrompt = "User preferences / question:\n{$question}\n\n"
            ."This JSON is ONLY for the single stock being analyzed (not the full dashboard universe):\n"
            .json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            urlencode($model),
            urlencode($apiKey),
        );

        $generationConfig = [
            'temperature' => 0.3,
            'maxOutputTokens' => $maxOutputTokens,
        ];

        // Gemini 2.5+ "thinking" tokens count against maxOutputTokens and often
        // truncate the visible answer when the budget is low.
        if (str_contains(strtolower($model), '2.5') || str_contains(strtolower($model), 'flash')) {
            $generationConfig['thinkingConfig'] = [
                'thinkingBudget' => (int) config('market_screenr.gemini.thinking_budget', 1024),
            ];
        }

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'tools' => [
                ['google_search' => (object) []],
            ],
            'generationConfig' => $generationConfig,
        ];

        $response = Http::timeout(90)
            ->acceptJson()
            ->post($url, $payload);

        if ($response->failed()) {
            Log::warning('Gemini analyze failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Gemini request failed: '.$response->status().' '.$response->json('error.message', $response->body()));
        }

        $json = $response->json();
        $parts = data_get($json, 'candidates.0.content.parts', []);
        $textChunks = [];

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text']) && $part['text'] !== '') {
                // Skip thought/signature-only blobs if present
                if (isset($part['thought']) && $part['thought'] === true) {
                    continue;
                }
                $textChunks[] = $part['text'];
            }
        }

        $text = trim(implode("\n\n", $textChunks));
        $finishReason = data_get($json, 'candidates.0.finishReason');
        $truncated = in_array($finishReason, ['MAX_TOKENS', 'LENGTH'], true);
        $grounded = filled(data_get($json, 'candidates.0.groundingMetadata'));

        if ($text === '') {
            $text = 'No response text returned by Gemini.'
                .($finishReason ? " (finishReason={$finishReason})" : '');
        }

        if ($truncated) {
            $text .= "\n\n> ⚠️ Response was truncated by the model token limit. Try again, or raise `GEMINI_MAX_OUTPUT_TOKENS` in `.env`.";
        }

        return [
            'text' => $text,
            'model' => $model,
            'grounded' => $grounded,
            'truncated' => $truncated,
            'finish_reason' => is_string($finishReason) ? $finishReason : null,
        ];
    }
}
