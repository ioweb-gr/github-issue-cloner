<?php
/*
 * Copyright (c) 2025. IOWEB TECHNOLOGIES
 */

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TranslationService
{
    private $client;
    private $googleKey;

    public function __construct(HttpClientInterface $client, string $googleKey)
    {
        $this->client = $client;
        $this->googleKey = $googleKey;
    }

    public function translate(string $text, string $targetLanguage): string
    {
        if (!$text || !$targetLanguage || !$this->googleKey) return $text;
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $this->googleKey;
        $payload = [
            'contents' => [[
                'parts' => [[
                    'text' => "Translate to {$targetLanguage} preserving links/images; keep meaning:\n\n" . $text . " .Make sure to only provide the translated text nothing extra or additional."
                ]]
            ]]
        ];
        $response = $this->client->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);
        $data = $response->toArray(false);
        return $data['candidates'][0]['content']['parts'][0]['text'] ?? $text;
    }
}
