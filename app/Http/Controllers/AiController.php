<?php

namespace App\Http\Controllers;

use App\Services\OpenAIClient;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function index()
    {
        return view('ai');
    }

    public function generate(Request $request, OpenAIClient $openai)
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:200'],
            'platform' => ['required', 'in:instagram,facebook,tiktok'],
            'tone' => ['required', 'in:professionale,amichevole,ironico,tecnico,commerciale'],
            'goal' => ['required', 'in:lead,brand,engagement,vendita'],
            'lang' => ['required', 'in:it,en'],
        ]);

        $topic    = $validated['topic'];
        $platform = $validated['platform'];
        $tone     = $validated['tone'];
        $goal     = $validated['goal'];
        $lang     = $validated['lang'];

        // Schema output (Structured Outputs). :contentReference[oaicite:5]{index=5}
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'hook' => ['type' => 'string'],
                'caption' => ['type' => 'string'],
                'hashtags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 3,
                    'maxItems' => 20,
                ],
                'cta' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ],
            'required' => ['hook', 'caption', 'hashtags', 'cta', 'notes'],
        ];

        $system = "Sei un content strategist per Smartera. "
            . "Genera contenuti pronti da pubblicare, coerenti con il tono richiesto, senza citare policy o metadati. "
            . "Lingua: {$lang}. Piattaforma: {$platform}. Obiettivo: {$goal}. Tono: {$tone}.";

        $user = "Tema/argomento: {$topic}\n"
            . "Vincoli:\n"
            . "- Hook breve e forte\n"
            . "- Caption adatta alla piattaforma\n"
            . "- Hashtag pertinenti\n"
            . "- CTA chiara\n"
            . "- Notes: 1-2 consigli su visual o formato (reel/carousel/post)\n";

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        try {
            $result = $openai->generateStructured($messages, $schema, 900);

            return response()->json([
                'ok' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
