# Generation Audit Runtime

Layer esplicito di audit/runtime per la generazione AI.

## Obiettivo

Introdurre audit persistente delle esecuzioni senza rimuovere `ContentItem.ai_meta` e affiancarlo con una timeline leggibile dei tentativi.

## Nuove entita

### `generation_runs`

Rappresenta una singola esecuzione del job di generazione per un `ContentItem`.

Campi principali:

- `content_item_id`
- `content_plan_id`
- `tenant_id`
- `run_key`
- `status`
- `requested_provider_matrix`
- `resolved_provider_matrix`
- `requested_output`
- `effective_output`
- `version_meta`
- `result_summary`
- `attempt_count`
- `runtime_ms`
- `last_error`
- `started_at`
- `finished_at`
- `completed_at`
- `failed_at`

### `generation_attempts`

Rappresenta un singolo step eseguito dentro una run.

Campi principali:

- `generation_run_id`
- `content_item_id`
- `parent_attempt_id`
- `sequence`
- `type`
- `stage`
- `step`
- `status`
- `provider_requested`
- `provider_effective`
- `model_requested`
- `model_effective`
- `provider_locked`
- `request_mode`
- `input_summary`
- `input_hash`
- `output_summary`
- `output_references`
- `requested_duration_seconds`
- `normalized_duration_seconds`
- `retry_index`
- `external_request_id`
- `error_code`
- `error_message`
- `runtime_ms`
- `started_at`
- `finished_at`
- `completed_at`
- `failed_at`

## Integrazione attuale

`GenerateAiForContentItem` ora registra:

- apertura della run
- versioning minimo del pipeline
- attempt `text_blueprint`
- attempt `visual_asset`
- attempt `demo_preset` quando `app.demo_mode = true`
- chiusura `succeeded` o `failed`

Helper interno disponibile:

- `App\Services\GenerationAuditService::timelineForRun()`
- `App\Services\GenerationAuditService::timelineForContentItem()`
- `App\Models\GenerationRun::toTimelineArray()`

## Backward compatibility

`ContentItem.ai_meta` resta attivo e continua a contenere il contesto runtime.

In piu ora contiene un ponte leggero compatibile:

- `ai_meta.generation_audit.latest_run_id`
- `ai_meta.generation_audit.latest_run_key`
- `ai_meta.generation_audit.latest_status`

## Scope attuale

Questo step non sostituisce ancora:

- `generation_segments`
- `generation_artifacts`
- policy engine esplicito per retry/fallback
- dashboard costi/latency

Serve a rendere osservabile il flusso reale prima di spezzare il job in pipeline piu piccole.