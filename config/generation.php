<?php

return [
    'force_sync' => (bool) env('GENERATION_FORCE_SYNC', false),
    'after_response_enabled' => (bool) env('GENERATION_AFTER_RESPONSE_ENABLED', true),
    'after_response_batch_limit' => (int) env('GENERATION_AFTER_RESPONSE_BATCH_LIMIT', 2),
    'queue_auto_kick' => (bool) env('GENERATION_QUEUE_AUTO_KICK', true),
    'queue_http_drain_fallback' => (bool) env('GENERATION_QUEUE_HTTP_DRAIN_FALLBACK', true),
    'processor_batch_limit' => (int) env('GENERATION_PROCESSOR_BATCH_LIMIT', 1),
    'active_drawer_limit' => (int) env('GENERATION_ACTIVE_DRAWER_LIMIT', 5),
    'queued_nudge_after_seconds' => (int) env('GENERATION_QUEUED_NUDGE_AFTER_SECONDS', 6),
    'queued_recovery_retry_seconds' => (int) env('GENERATION_QUEUED_RECOVERY_RETRY_SECONDS', 20),
    'queued_stale_after_seconds' => (int) env('GENERATION_QUEUED_STALE_AFTER_SECONDS', 150),
    'queued_recovery_grace_seconds' => (int) env('GENERATION_QUEUED_RECOVERY_GRACE_SECONDS', 60),
    'pending_stale_after_seconds' => (int) env('GENERATION_PENDING_STALE_AFTER_SECONDS', 900),

    'text_provider_default' => env('TEXT_PROVIDER_DEFAULT', 'openai'),
    'text_providers' => ['openai'],

    'grader_provider_default' => env('GRADER_PROVIDER_DEFAULT', env('TEXT_PROVIDER_DEFAULT', 'openai')),
    'grader_providers' => ['openai'],

    'speech_provider_default' => env('SPEECH_PROVIDER_DEFAULT', 'openai'),
    'speech_providers' => ['openai', 'elevenlabs'],
    'voice_clone_provider_default' => env('VOICE_CLONE_PROVIDER_DEFAULT', 'elevenlabs'),

    'video_provider_default' => env('VIDEO_PROVIDER_DEFAULT', 'runway'),
    'video_providers' => ['runway'],
    'locked_video_provider_failover' => (bool) env('GENERATION_LOCKED_VIDEO_PROVIDER_FAILOVER', false),

    'image_provider_default' => env('IMAGE_PROVIDER_DEFAULT', 'runway'),
    'image_providers' => ['runway'],

    'video_auto_audio' => (bool) env('VIDEO_AUTO_AUDIO', true),
    'strict_asset_mode' => (bool) env('GENERATION_STRICT_ASSET_MODE', true),

    'knowledge_pack_examples_limit' => (int) env('GENERATION_KNOWLEDGE_PACK_EXAMPLES_LIMIT', 5),
    'knowledge_pack_negative_examples_limit' => (int) env('GENERATION_KNOWLEDGE_PACK_NEGATIVE_EXAMPLES_LIMIT', 4),
    'knowledge_pack_asset_rows_limit' => (int) env('GENERATION_KNOWLEDGE_PACK_ASSET_ROWS_LIMIT', 48),
    'knowledge_pack_asset_items_per_kind_limit' => (int) env('GENERATION_KNOWLEDGE_PACK_ASSET_ITEMS_PER_KIND_LIMIT', 6),
    'knowledge_pack_asset_signal_limit' => (int) env('GENERATION_KNOWLEDGE_PACK_ASSET_SIGNAL_LIMIT', 10),

    'fine_tuning_enabled' => (bool) env('GENERATION_FINE_TUNING_ENABLED', true),
    'fine_tuning_min_examples' => (int) env('GENERATION_FINE_TUNING_MIN_EXAMPLES', 12),
    'fine_tuning_max_examples' => (int) env('GENERATION_FINE_TUNING_MAX_EXAMPLES', 80),
    'fine_tuning_validation_examples' => (int) env('GENERATION_FINE_TUNING_VALIDATION_EXAMPLES', 6),
    'fine_tuning_auto_activate' => (bool) env('GENERATION_FINE_TUNING_AUTO_ACTIVATE', true),

    'alignment_enabled' => (bool) env('GENERATION_ALIGNMENT_ENABLED', true),
    'alignment_text_min_score' => (float) env('GENERATION_ALIGNMENT_TEXT_MIN_SCORE', 0.64),
    'alignment_image_reference_validation' => (bool) env('GENERATION_ALIGNMENT_IMAGE_REFERENCE_VALIDATION', true),
    'alignment_image_reference_min_confidence' => (float) env('GENERATION_ALIGNMENT_IMAGE_REFERENCE_MIN_CONFIDENCE', 0.55),

    // Se il punteggio euristico supera questa soglia, si salta la chiamata LLM di grading.
    // Risparmio: ogni generazione con contenuto già ottimo evita 1 chiamata OpenAI.
    // Default 0.85 = 85% di coverage heuristica è considerata sufficiente.
    'alignment_heuristic_short_circuit_threshold' => (float) env('GENERATION_ALIGNMENT_HEURISTIC_SHORT_CIRCUIT', 0.85),

    // TTL in secondi per la cache del knowledge pack base (memoria, asset, trend) per tenant.
    // 1800 = 30 minuti. Invalida automaticamente quando cambiano asset o arriva nuovo feedback.
    'knowledge_pack_base_cache_ttl' => (int) env('GENERATION_KNOWLEDGE_PACK_BASE_CACHE_TTL', 1800),

    'ffmpeg_binary' => env('FFMPEG_BINARY', ''),
    'ffprobe_binary' => env('FFPROBE_BINARY', ''),

    // ── SSE (Server-Sent Events) per progresso generazione ───────────────────
    // Usato da PlanWizardController::stream() in sostituzione del polling JS.

    // Durata massima di una connessione SSE aperta (secondi).
    // Dopo questo tempo viene inviato un evento 'error' con reason='timeout'.
    'sse_max_seconds' => (int) env('GENERATION_SSE_MAX_SECONDS', 300),

    // Intervallo tra i poll al DB durante lo streaming (secondi).
    // Valori più bassi = più reattivo ma più query. Default 2 sec.
    'sse_poll_interval' => (int) env('GENERATION_SSE_POLL_INTERVAL', 2),
];
