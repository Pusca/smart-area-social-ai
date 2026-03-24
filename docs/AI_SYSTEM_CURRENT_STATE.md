# AI System Current State

Documento aggiornato dello stato architetturale AI di `Social AI`.

Data: `2026-03-21`  
Repo: `C:\dev\smart-area-social-ai`  
Scopo: dare a sviluppatori, revisori o altri LLM una vista reale del sistema dopo i refactor incrementali introdotti su audit, pipeline, capability registry, observability, quality scorecard, feedback strutturato e identity packs.

## 1. Executive Summary

`Social AI` e un'app Laravel multi-tenant che:

- raccoglie profilo brand, asset e knowledge base del tenant
- costruisce strategia editoriale persistita
- genera `ContentPlan` e `ContentItem`
- produce testo, immagini, video e audio con provider AI multipli
- mantiene coerenza di persone, location e prodotti nel tempo
- traccia audit, metriche, quality scorecard e feedback umano
- pubblica i contenuti sui canali social

Il centro tecnico della generazione resta:

- `app/Jobs/GenerateAiForContentItem.php`

Ma oggi il job non e piu solo un monolite runtime: appoggia una pipeline di step, un layer di audit persistito, un registry capability centrale e nuovi layer di quality/feedback/identity.

## 2. Flusso End-to-End

### 2.1 Brand Center

Ingresso principale:

- `app/Http/Controllers/TenantProfileController.php`

Responsabilita:

- aggiorna `TenantProfile`
- gestisce `BrandAsset`
- crea e aggiorna `AssetVariable`
- costruisce persona pack guidati
- sintetizza `identity_pack`
- aggiorna la strategia del tenant

Service chiave:

- `app/Services/AssetVariableService.php`
- `app/Services/AssetIdentityService.php`
- `app/Services/GuidedAssetVariableService.php`
- `app/Services/Editorial/EditorialStrategyService.php`

### 2.2 Strategia e Piano

Componenti principali:

- `app/Services/Editorial/EditorialStrategyService.php`
- `app/Services/Editorial/EditorialPlanBuilder.php`
- `app/Services/Editorial/ContentGenerator.php`

Flusso:

1. il tenant costruisce o aggiorna la strategia
2. il wizard o il quickstart costruiscono un `ContentPlan`
3. `ContentGenerator` crea `ContentItem` con blueprint e `ai_meta` iniziale
4. `GenerationExecution` dispatcha il job di generazione

### 2.3 Generazione Finale

Orchestratore:

- `app/Jobs/GenerateAiForContentItem.php`

Pipeline v1 attuale:

- `BuildGenerationContextStep`
- `ResolveProviderMatrixStep`
- `GenerateBaseTextStep`
- `BuildVisualPromptStep`
- `GenerateVisualAssetStep`
- `PersistGenerationOutputsStep`

File:

- `app/Services/AI/Pipeline/BuildGenerationContextStep.php`
- `app/Services/AI/Pipeline/ResolveProviderMatrixStep.php`
- `app/Services/AI/Pipeline/GenerateBaseTextStep.php`
- `app/Services/AI/Pipeline/BuildVisualPromptStep.php`
- `app/Services/AI/Pipeline/GenerateVisualAssetStep.php`
- `app/Services/AI/Pipeline/PersistGenerationOutputsStep.php`
- `app/Services/AI/Pipeline/GenerationPipelineState.php`

### 2.4 Publishing

Componenti:

- `app/Services/Social/SocialPublishingService.php`
- `app/Models/SocialPublication.php`

Il contenuto generato puo poi passare da:

- `draft`
- `review`
- `approved`
- `scheduled`
- `published`

## 3. Dati Principali del Dominio

Modelli centrali:

- `TenantProfile`
- `EditorialStrategy`
- `ContentPlan`
- `ContentItem`
- `BrandAsset`
- `AssetVariable`
- `ContentFeedbackEntry`
- `GenerationRun`
- `GenerationAttempt`
- `SocialPublication`

Ruoli:

- `ContentItem` resta il contenitore del contenuto e dello snapshot runtime
- `GenerationRun` e la fonte primaria dell'audit di una generazione
- `GenerationAttempt` e la timeline dei tentativi interni
- `AssetVariable` + `identity_pack` gestiscono continuita visiva e semantica

## 4. Provider e Capability Matrix

Provider attualmente supportati:

| Area | Provider attivi |
| --- | --- |
| Text | `openai` |
| Grader / alignment | `openai` |
| Image | `nanobanana`, `openai` |
| Video | `runway`, `openai`, `kling` |
| Speech | `openai`, `elevenlabs` |
| Voice clone | `elevenlabs` |

Registry centrale:

- `app/Services/AI/ProviderCapabilityRegistry.php`
- `config/provider_capabilities.php`

Ruolo del registry:

- lista provider validi per area
- modello default per provider/area
- supporto `reference_aware`
- supporto `image_to_video` / `text_to-video`
- durate ammesse
- size / aspect ratio ammessi
- timeout e poll timeout
- supporto mux / audio nativo
- supporto strict asset mode
- fallback consentiti

Resolver integrati al registry:

- `app/Support/TextProviderResolver.php`
- `app/Support/GraderProviderResolver.php`
- `app/Support/ImageProviderResolver.php`
- `app/Support/VideoProviderResolver.php`
- `app/Support/SpeechProviderResolver.php`

Matrix finale:

- `app/Services/AI/AiProviderMatrixService.php`

## 5. Provider Runtime Attuali

### 5.1 OpenAI

File principale:

- `app/Services/OpenAiService.php`

Usato per:

- testo
- immagini
- video Sora
- speech/TTS
- validazioni reference-aware di immagini e frame video

Video note attuali:

- il path video usa `input_reference` singolo
- per persona reale del brand il job oggi preferisce una reference primaria persona-first invece del collage generico quando il provider e `openai`
- la validazione finale sui frame video e usata anche per identity locks, non solo per reference esplicite dell'utente

### 5.2 Runway

File principale:

- `app/Services/RunwayService.php`

Usato per:

- `image_to_video`
- video reference-aware
- reel con buona fedelta su asset e scene reference-heavy

Note:

- il modello default attuale consigliato resta `gen4.5` per casi identity-heavy
- il sistema supporta anche modelli Veo, ma la normalizzazione durata e model-specific

### 5.3 Kling

File principale:

- `app/Services/KlingService.php`

Usato per:

- video endpoint-aware
- fallback intra-provider su modelli compatibili
- casi multi-image o image-to-video in base all'endpoint

### 5.4 NanoBanana

File principale:

- `app/Services/NanoBananaService.php`

Usato per:

- immagini statiche brand-aligned

### 5.5 ElevenLabs

Usato per:

- voice clone
- speech synthesis alternativa

## 6. GenerateAiForContentItem: Stato Reale

Il job e ancora il punto piu denso del sistema.

Oggi gestisce:

- orchestrazione pipeline
- provider lock e fallback
- segmentazione video lunga
- stitching via `ffmpeg`
- prompt video e prompt visuali
- asset selection e identity reference routing
- reference validation
- audio attach e mux
- bridge compatibile verso `ai_meta`

Punti gia estratti:

- costruzione contesto
- matrix provider
- generazione testo base
- build prompt visuale
- generazione asset visuale
- persistenza output

Punti ancora pesanti dentro il job:

- logica video provider-specific
- retry/fallback cross-provider
- segmentazione reel lunga
- media assembly e `ffmpeg`
- molte utility di normalizzazione e prompt building

## 7. Audit Persistito

Source of truth runtime:

- `generation_runs`
- `generation_attempts`

File:

- `database/migrations/2026_03_20_120000_create_generation_runs_table.php`
- `database/migrations/2026_03_20_120100_create_generation_attempts_table.php`
- `app/Models/GenerationRun.php`
- `app/Models/GenerationAttempt.php`
- `app/Services/GenerationAuditService.php`

### 7.1 GenerationRun

Traccia:

- tenant
- content item
- provider matrix richiesto e risolto
- output richiesto ed effettivo
- version map
- result summary
- costi
- retry/fallback/downgrade
- provider finale
- failure mode
- quality scorecard

### 7.2 GenerationAttempt

Traccia:

- stage / step / type
- provider richiesto ed effettivo
- modello richiesto ed effettivo
- provider locked
- input/output summary
- input hash
- durate richieste e normalizzate
- retry index
- request id esterni
- error code / error message
- costo stimato / reale
- token usage

### 7.3 Timeline interna

API/helper disponibili:

- `GenerationAuditService::timelineForRun()`
- `GenerationAuditService::timelineForContentItem()`
- `GenerationRun::toTimelineArray()`

## 8. Versioning Esplicito degli Output AI

Registry:

- `app/Services/AI/GenerationVersionRegistry.php`
- `config/ai_versioning.php`

Le versioni salvate oggi includono almeno:

- `prompt_template_version`
- `strategy_composer_version`
- `provider_adapter_versions`
- `alignment_policy_version`
- `asset_selection_policy_version`
- `feedback_synthesis_version`
- `pipeline_version`

Persistenza:

- `generation_runs.version_meta`
- snapshot compatibile in `ContentItem.ai_meta.generation_audit.version_map`

## 9. Observability

Service:

- `app/Services/GenerationMetricsService.php`

Persistenza supportata:

- `estimated_cost_usd`
- `actual_cost_usd`
- `token_usage`
- `runtime_ms`
- `fallback_used`
- `downgrade_used`
- `segment_count`
- `final_provider`
- `failure_mode`
- `retry_count`

Aggregazioni disponibili:

- costo per tenant
- costo per provider
- latenza media per provider
- failure rate
- retry rate
- downgrade rate
- fallback rate
- failure modes

UI admin minimale:

- route `GET /admin/ai/metrics`
- `app/Http/Controllers/AdminWorkspaceController.php`
- `resources/views/admin/generation-metrics.blade.php`

## 10. Quality Scorecard Finale

Service:

- `app/Services/AI/GenerationQualityScorecardService.php`
- `config/ai_quality.php`

Persistenza:

- `generation_runs.quality_scorecard`
- snapshot in `ContentItem.ai_meta.quality_scorecard`

Campi principali:

- `brand_voice_score`
- `visual_identity_score`
- `cta_compliance_score`
- `reference_match_score`
- `realism_score`
- `caption_quality_score`
- `publish_readiness_status`
- `warnings`
- `blocking_reasons`
- `score_sources`

Stati:

- `pass`
- `pass_with_warnings`
- `manual_review_required`
- `blocked`

Nota importante:

- non tutti gli score sono “validated”
- il documento `score_sources` distingue `validated`, `heuristic` e `missing`

## 11. Feedback Loop Umano

Modello centrale:

- `app/Models/ContentFeedbackEntry.php`

Service:

- `app/Services/Feedback/TenantFeedbackSignalSynthesisService.php`
- `app/Services/Feedback/TenantFeedbackMemoryService.php`
- `app/Services/AI/TenantContentIntelligenceService.php`

Categorie strutturate attuali:

- `too_generic`
- `too_salesy`
- `off_brand`
- `person_not_consistent`
- `location_not_consistent`
- `product_deformed`
- `audio_unatural`
- `low_quality_visual`
- `wrong_cta`
- `not_publishable`

Il feedback oggi influenza:

- `knowledge_pack.feedback`
- `feedback_signals`
- retry prompt e correzioni visuali
- filtri tra feedback visuale e feedback solo audio

## 12. Identity Packs Canonici

Persistenza:

- `asset_variables.identity_pack`

Migration:

- `database/migrations/2026_03_21_130000_add_identity_pack_to_asset_variables_table.php`

Service:

- `app/Services/AssetIdentityService.php`
- `app/Services/AssetVariableService.php`
- `app/Services/GuidedAssetVariableService.php`

Struttura logica del pack:

- `version`
- `type`
- `strictness_level`
- `descriptor`
- `canonical_assets`
- `invariants`
- `transformables`
- `visual_tags`
- `positive_examples`
- `negative_examples`
- `prompt_notes`
- `usage_notes`
- `identity_signals`

Tipi supportati:

- `person`
- `location`
- `product`
- `custom`

Uso runtime:

- priorita agli asset canonici nelle reference
- separazione chiara tra elementi da mantenere e elementi che possono cambiare
- lock identitario in prompt immagine/video
- source refs piu leggibili sul contenuto

## 13. Video System Attuale

### 13.1 Provider Lock

Regola:

- se l'utente seleziona un provider video, il job lo tratta come lock
- i retry interni restano consentiti
- il cross-provider fallback non deve avvenire se il provider e locked

### 13.2 Reel Lunghi

Regola:

- per `reel`, il target di default e `20s`
- se il provider non supporta clip singole cosi lunghe, il job prova segmentazione
- la concatenazione reale richiede `ffmpeg`

Se `ffmpeg` manca:

- il job degrada a single-clip fallback
- il downgrade viene tracciato nei metadati e nella scorecard

### 13.3 Sora / OpenAI Video

Stato attuale del percorso OpenAI:

- usa un solo `input_reference`
- normalizza modelli `sora-2*`
- normalizza durate supportate `4/8/12`
- valida il frame finale con OpenAI vision quando serve

Aggiornamento piu recente:

- se il contenuto ha una sola persona reale del brand e piu reference, il sistema evita il collage generico e usa la reference primaria persona-first
- la validazione reference-aware finale parte anche per `asset_identity` / `identity_pack`, non solo per immagini selezionate manualmente dall'utente

Conseguenza pratica:

- migliore stabilita sul volto della persona reale
- meno falsi warning “reference match non validato” nei casi identity-driven

### 13.4 Runway

Percorso preferibile quando:

- servono persone/asset reali con piu fedelta
- il reel e reference-heavy
- il caso e identity-first

### 13.5 Kling

Percorso utile quando:

- il tenant/account supporta il modello giusto per l'endpoint
- il caso beneficia del suo `image_to_video`

## 14. ai_meta: Ruolo Attuale

`ContentItem.ai_meta` non e stato rimosso.

Oggi serve come snapshot compatibile del runtime e contiene ancora:

- `tenant_profile`
- `brand_assets`
- `asset_variables`
- `asset_identity`
- `provider_matrix`
- `strategy`
- `plan`
- `item_brain`
- `memory_summary`
- `knowledge_pack`
- `examples`
- `negative_examples`
- `feedback_signals`
- `generation_audit`
- `quality_scorecard`

Source of truth nuova:

- audit -> `GenerationRun` / `GenerationAttempt`
- quality -> `GenerationRun.quality_scorecard`

Snapshot compatibile:

- `ai_meta.generation_audit.*`
- `ai_meta.quality_scorecard`

Riduzioni future sicure:

- eliminare il doppione `asset_variables_catalog`
- alleggerire gradualmente snapshot pesanti gia ricostruibili da DB e run audit

## 15. File Chiave da Leggere

Ordine consigliato:

1. `routes/web.php`
2. `app/Http/Controllers/TenantProfileController.php`
3. `app/Http/Controllers/PlanWizardController.php`
4. `app/Http/Controllers/ContentItemController.php`
5. `app/Http/Controllers/AiGenerateController.php`
6. `app/Services/Editorial/EditorialStrategyService.php`
7. `app/Services/Editorial/EditorialPlanBuilder.php`
8. `app/Services/Editorial/ContentGenerator.php`
9. `app/Jobs/GenerateAiForContentItem.php`
10. `app/Services/OpenAiService.php`
11. `app/Services/RunwayService.php`
12. `app/Services/KlingService.php`
13. `app/Services/NanoBananaService.php`
14. `app/Services/AI/AiProviderMatrixService.php`
15. `app/Services/AI/ProviderCapabilityRegistry.php`
16. `app/Services/GenerationAuditService.php`
17. `app/Services/GenerationMetricsService.php`
18. `app/Services/AI/GenerationQualityScorecardService.php`
19. `app/Services/Feedback/TenantFeedbackSignalSynthesisService.php`
20. `app/Services/AssetIdentityService.php`
21. `tests/Unit/GenerateAiForContentItemTest.php`

## 16. File/Area di UI Importanti

- `resources/views/posts/create.blade.php`
- `resources/views/posts/edit.blade.php`
- `resources/views/posts/generating.blade.php`
- `resources/views/content-items/show.blade.php`
- `resources/views/admin/generation-metrics.blade.php`

## 17. Route Funzionali Principali

- `/profile/brand`
- `/wizard`
- `/posts`
- `/content-items`
- `/settings`
- `/admin`
- `/admin/ai/metrics`

## 18. Env e Config Chiave

### Provider

```env
VIDEO_PROVIDER_DEFAULT=runway
IMAGE_PROVIDER_DEFAULT=nanobanana
SPEECH_PROVIDER_DEFAULT=openai
VOICE_CLONE_PROVIDER_DEFAULT=elevenlabs
```

### OpenAI

```env
OPENAI_TEXT_MODEL=gpt-4.1-mini
OPENAI_IMAGE_MODEL=gpt-image-1
OPENAI_VIDEO_MODEL=sora-2
OPENAI_SPEECH_MODEL=gpt-4o-mini-tts
OPENAI_VIDEO_POLL_TIMEOUT=900
```

### Runway

```env
RUNWAY_VIDEO_MODEL=gen4.5
RUNWAY_VIDEO_SECONDS=8
RUNWAY_VIDEO_POLL_TIMEOUT=420
```

### Kling

```env
KLING_VIDEO_MODEL=
KLING_VIDEO_MODE=pro
KLING_VIDEO_SECONDS=5
KLING_VIDEO_POLL_TIMEOUT=540
```

### Runtime generation

```env
GENERATION_STRICT_ASSET_MODE=true
VIDEO_AUTO_AUDIO=true
FFMPEG_BINARY=
FFPROBE_BINARY=
```

## 19. Documenti Tecnici Correlati

Per approfondimenti piu mirati:

- `docs/AI_SYSTEM_MAP.md`
- `docs/generation-audit-v1.md`
- `docs/provider-capability-registry.md`

## 20. Rischi Residui / Limiti Attuali

- `GenerateAiForContentItem.php` resta ancora molto grande
- `ai_meta` e ancora ricco di snapshot pesanti
- la quality scorecard usa ancora componenti euristiche in assenza di validazione finale
- il sistema video lungo dipende ancora da `ffmpeg`
- il path Sora resta piu fragile di Runway nei casi “stessa persona reale del brand”

## 21. Sintesi Finale

Oggi l'app non e piu solo “un job AI che genera contenuti”.

E una piattaforma AI multi-tenant con:

- pipeline step-based
- audit persistito
- capability registry centrale
- versioning esplicito
- observability e metriche
- quality scorecard
- feedback loop strutturato
- identity continuity per persone, location e prodotti

Il comportamento attuale migliore puo essere riassunto cosi:

1. il contenuto nasce da strategia + piano + asset reali del tenant
2. il job costruisce un contesto ricco ma auditabile
3. il provider viene risolto con capability e lock chiari
4. il sistema cerca di mantenere continuita visuale reale
5. il risultato finale viene misurato, classificato e reso leggibile anche lato debug

Per capire il sistema nel suo stato corrente, il file piu importante resta:

- `app/Jobs/GenerateAiForContentItem.php`

Per capire dove sta andando il sistema, i file piu importanti sono:

- `app/Services/AI/Pipeline/*`
- `app/Services/GenerationAuditService.php`
- `app/Services/AI/ProviderCapabilityRegistry.php`
- `app/Services/AI/GenerationQualityScorecardService.php`
- `app/Services/AssetIdentityService.php`