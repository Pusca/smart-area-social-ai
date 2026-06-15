# AI System Map

Documento di mappa tecnica del sistema AI di `Social AI`.

Data: `2026-03-20`  
Repo: `C:\dev\smart-area-social-ai`  
Obiettivo: fornire a sviluppatori, revisori esterni o altri LLM una vista unica su provider AI, flussi applicativi, file chiave, sitemap funzionale e punti di debug.

## 1. Executive Summary

L'applicazione e un sistema Laravel multi-tenant per:

- raccogliere profilo brand, asset e knowledge base
- costruire strategia editoriale e calendario contenuti
- creare `ContentItem` con blueprint strutturato
- generare testo, immagini, video e audio usando provider AI diversi
- validare l'allineamento agli asset/brand
- programmare la pubblicazione social

Il centro tecnico della generazione finale e il job:

- `app/Jobs/GenerateAiForContentItem.php`

I nuclei dati principali sono:

- `TenantProfile`: profilo brand e preferenze
- `EditorialStrategy`: strategia persistita del tenant
- `BrandAsset`: libreria asset brand/knowledge
- `AssetVariable`: variabili semantiche tipo persona, location, prodotto, custom
- `ContentPlan`: piano editoriale
- `ContentItem`: singolo contenuto da generare/pubblicare
- `ContentFeedbackEntry`: memoria dei feedback umani
- `SocialAccount` / `SocialPublication`: pubblicazione social

## 2. Provider Matrix Attuale

Il provider matrix di default e definito in `config/generation.php`.

| Area | Provider di default | Alternative registrate | Modello default | File config |
| --- | --- | --- | --- | --- |
| Text generation | `openai` | nessuna alternativa runtime | `gpt-4.1-mini` | `config/generation.php`, `config/openai.php` |
| Text grading/alignment | `openai` | nessuna alternativa runtime | usa stack OpenAI | `config/generation.php`, `config/openai.php` |
| Image generation | `nanobanana` | `openai` | `gemini-2.5-flash-image` | `config/generation.php`, `config/nanobanana.php`, `config/openai.php` |
| Video generation | `runway` | `openai`, `kling` | `gen4.5` | `config/generation.php`, `config/runway.php`, `config/openai.php`, `config/kling.php` |
| Speech/TTS | `openai` | `elevenlabs` | `gpt-4o-mini-tts` | `config/generation.php`, `config/openai.php`, `config/elevenlabs.php` |
| Voice cloning | `elevenlabs` | nessuna altra opzione dichiarata | `eleven_multilingual_v2` | `config/generation.php`, `config/elevenlabs.php` |
| Fine tuning dataset/runtime decoration | OpenAI-centric | n/a | eredita `gpt-4.1-mini` salvo override | `app/Services/AI/*FineTuning*`, `config/openai.php` |

## 3. Modelli e Configurazione per Area

### 3.1 Text / Vision / Grading

Provider:

- `openai`

Default config:

```env
TEXT_PROVIDER_DEFAULT=openai
GRADER_PROVIDER_DEFAULT=openai
OPENAI_TEXT_MODEL=gpt-4.1-mini
OPENAI_VISION_MODEL=gpt-4.1-mini
```

Uso principale:

- generazione caption, CTA, hashtag, prompt e struttura contenuto
- analisi visuale o scelta reference image
- grading dell'allineamento brand/caption tramite `ContentAlignmentService`

File chiave:

- `app/Services/OpenAiService.php`
- `app/Services/AI/ContentAlignmentService.php`
- `app/Support/TextProviderResolver.php`
- `app/Support/GraderProviderResolver.php`

### 3.2 Image Generation

Provider registrati:

- `nanobanana`
- `openai`

Default config:

```env
IMAGE_PROVIDER_DEFAULT=nanobanana
NANOBANANA_IMAGE_MODEL=gemini-2.5-flash-image
OPENAI_IMAGE_MODEL=gpt-image-1
NANOBANANA_ASPECT_RATIO=4:5
```

Uso principale:

- generazione immagini post/statiche
- image edit con reference brand quando serve
- validazione reference-aware tramite `ContentAlignmentService`

File chiave:

- `app/Services/NanoBananaService.php`
- `app/Services/OpenAiService.php`
- `app/Support/ImageProviderResolver.php`
- `app/Support/ImagePromptRealismGuard.php`

### 3.3 Video Generation

Provider registrati:

- `runway`
- `openai`
- `kling`

Default config:

```env
VIDEO_PROVIDER_DEFAULT=runway
RUNWAY_VIDEO_MODEL=gen4.5
OPENAI_VIDEO_MODEL=sora-2
KLING_VIDEO_MODEL=
```

Note operative attuali:

- `runway` e il default di sistema
- `openai` usa stack Sora
- `kling` ha selezione modello dipendente dall'endpoint
- se il provider viene scelto esplicitamente dall'utente, il job applica un lock e non deve cambiare provider in modo nascosto
- se il video richiesto supera i limiti del provider, il job puo segmentare e poi concatenare
- la concatenazione reale richiede `ffmpeg`

Default config complementare:

```env
RUNWAY_VIDEO_SECONDS=8
OPENAI_VIDEO_SECONDS=8
KLING_VIDEO_SECONDS=5
OPENAI_VIDEO_SIZE=720x1280
OPENAI_VIDEO_POLL_TIMEOUT=900
RUNWAY_VIDEO_POLL_TIMEOUT=420
KLING_VIDEO_POLL_TIMEOUT=540
FFMPEG_BINARY=
FFPROBE_BINARY=
```

File chiave:

- `app/Jobs/GenerateAiForContentItem.php`
- `app/Services/RunwayService.php`
- `app/Services/OpenAiService.php`
- `app/Services/KlingService.php`
- `app/Support/VideoProviderResolver.php`

### 3.4 Speech / Voice

Provider registrati:

- speech: `openai`, `elevenlabs`
- voice clone: `elevenlabs`

Default config:

```env
SPEECH_PROVIDER_DEFAULT=openai
VOICE_CLONE_PROVIDER_DEFAULT=elevenlabs
OPENAI_SPEECH_MODEL=gpt-4o-mini-tts
OPENAI_SPEECH_VOICE=alloy
ELEVENLABS_MODEL_ID=eleven_multilingual_v2
```

Uso principale:

- voiceover reel
- audio attach su video generato
- voce persona/brand dal Brand Center

File chiave:

- `app/Services/SpeechSynthesisService.php`
- `app/Services/ElevenLabsService.php`
- `app/Services/OpenAiService.php`
- `app/Support/SpeechProviderResolver.php`

### 3.5 Fine Tuning

Componenti:

- `app/Services/AI/TenantFineTuningService.php`
- `app/Services/AI/TenantFineTuningDatasetService.php`
- `app/Services/OpenAiFineTuningService.php`
- `app/Models/TenantFineTuneRun.php`
- `app/Http/Controllers/SettingsController.php`

Ruolo:

- costruzione dataset tenant-specific
- avvio/sync job di fine-tuning
- decorazione del provider matrix risolto per tenant

Nota pratica:

- il provider matrix passa da `AiProviderMatrixService`, che puo essere decorato dal fine tuning attivo.

## 4. Come Viene Risolto il Provider

La risoluzione avviene cosi:

1. `config/generation.php` definisce i provider di default per text, image, video, speech e grading.
2. I resolver in `app/Support/*ProviderResolver.php` normalizzano valore richiesto, fallback e lista allowed.
3. `app/Services/AI/AiProviderMatrixService.php` costruisce una matrice coerente per il singolo contenuto.
4. `app/Http/Controllers/AiGenerateController.php` applica preferenze esplicite su video e immagine prima del dispatch.
5. `app/Jobs/GenerateAiForContentItem.php` usa la matrice e le informazioni salvate in `ai_meta`.

Logica importante:

- il provider scelto esplicitamente dall'utente viene trattato come lock logico
- il job contiene ancora fallback intra-provider e, in alcuni casi, secondari, ma le selezioni esplicite devono impedire cambi provider nascosti
- `Kling` usa selezione modello endpoint-aware
- `OpenAI` normalizza durata e modello verso valori supportati
- `Runway` normalizza durata in base al modello effettivo

## 5. Flusso End-to-End del Sistema

### 5.1 Brand Setup

Ingresso utente:

- `/profile/brand`

Controller:

- `app/Http/Controllers/TenantProfileController.php`

Responsabilita:

- salva `TenantProfile`
- carica logo, immagini, video, audio, documenti
- salva knowledge text e reference links
- crea/distrugge `BrandAsset`
- crea/distrugge `AssetVariable`
- gestisce persona pack guidato
- aggiorna o rigenera `EditorialStrategy`

Service coinvolti:

- `app/Services/Editorial/EditorialStrategyService.php`
- `app/Services/AssetVariableService.php`
- `app/Services/AssetIdentityService.php`
- `app/Services/GuidedAssetVariableService.php`
- `app/Services/Onboarding/QuickstartOnboardingService.php`

### 5.2 Quickstart / Demo Onboarding

Ingresso utente:

- quickstart dal Brand Center

Service principale:

- `app/Services/Onboarding/QuickstartOnboardingService.php`

Cosa fa:

- crea/aggiorna il profilo base
- salva asset iniziali
- crea eventuale variabile rapida
- rigenera strategia tenant
- costruisce un `ContentPlan` demo
- genera 3 `ContentItem` iniziali
- dispatcha i job di generazione

### 5.3 Strategia Editoriale

Persistenza strategica principale:

- `app/Services/Editorial/EditorialStrategyService.php`

Funzioni:

- `refreshForTenant()`
- `forTenant()`
- `applyStudioInputs()`
- `toRuntimeContext()`

Contenuti prodotti:

- `brand_voice`
- `pillars`
- `rubrics`
- `cta_rules`
- `constraints`
- `analysis_framework`
- `visual_system`
- `publishing_system`

Nota importante:

- `StrategyBrainService` esiste come composer strategico standalone e ha test dedicati, ma dal codice attuale il flusso runtime principale passa soprattutto da `EditorialStrategyService` + `EditorialPlanBuilder` + `ContentGenerator`.

### 5.4 Piano Editoriale

Ingresso utente:

- `/wizard`

Controller:

- `app/Http/Controllers/PlanWizardController.php`

Service principali:

- `app/Services/Editorial/EditorialPlanBuilder.php`
- `app/Services/Editorial/ContentHistoryAnalyzer.php`
- `app/Services/Editorial/ContentGenerator.php`

Flusso:

1. il wizard raccoglie periodo, obiettivi e preferenze
2. `EditorialPlanBuilder` distribuisce rubrics, pillars, format, trend slot, CTA e date
3. `ContentGenerator` crea i `ContentItem` iniziali con `ai_meta` strutturato
4. `GenerationExecution` decide se generare sync, after-response o via queue

### 5.5 Generazione del Singolo Contenuto

Dispatcher:

- `app/Support/GenerationExecution.php`
- `app/Http/Controllers/AiGenerateController.php`

Orchestratore:

- `app/Jobs/GenerateAiForContentItem.php`

Fasi principali del job:

1. carica `ContentItem`, piano e stato AI
2. fonde `brand_assets` da DB e `ai_meta`
3. carica strategia, profile, feedback, memory e knowledge pack
4. risolve asset variables e asset identity
5. risolve il provider matrix
6. genera il testo base con OpenAI
7. costruisce prompt immagine/video allineati a strategia e asset
8. genera immagine o video col provider scelto
9. applica alignment / validation
10. opzionalmente genera audio e lo muxa sul video
11. salva asset finali, `ai_status`, `ai_generated_at`, errori e metadati

### 5.6 Allineamento, Feedback e Memoria

Service principali:

- `app/Services/MemoryBuilderService.php`
- `app/Services/AI/TenantContentIntelligenceService.php`
- `app/Services/AI/ContentAlignmentService.php`
- `app/Services/Feedback/TenantFeedbackMemoryService.php`

Ruolo:

- costruire memoria storica del tenant
- selezionare esempi e negative examples
- sintetizzare feedback signals
- validare caption e immagini generate
- influenzare retry e miglioramenti futuri

### 5.7 Publishing

Ingresso utente:

- calendario approvazione
- impostazioni social/meta

Service principale:

- `app/Services/Social/SocialPublishingService.php`

Ruolo:

- approva contenuto
- costruisce caption finale
- risolve asset pubblicabile
- crea/aggiorna `SocialPublication`
- aggiorna stato item tra `approved`, `scheduled`, `published`, `failed`

## 6. Video System: Regole Attuali

Questa e la parte piu delicata del sistema.

### 6.1 Provider e Lock

File:

- `app/Http/Controllers/AiGenerateController.php`
- `app/Jobs/GenerateAiForContentItem.php`

Regola:

- se l'utente seleziona esplicitamente un provider video, quella scelta viene salvata e deve restare il provider effettivo del job
- il sistema puo fare retry interni sullo stesso provider
- i fallback cross-provider non devono scattare quando il provider e locked

### 6.2 Durata Video

File:

- `app/Jobs/GenerateAiForContentItem.php`

Regole note:

- per `reel`, se non esiste una durata esplicita, il target default e `20s`
- il form edit del contenuto salva `video_duration_seconds_requested`
- la durata viene normalizzata per provider
- `OpenAI` accetta solo durate supportate dal suo stack
- `Runway` ha vincoli diversi in base al modello
- `Kling` ha default piu corto e logica endpoint-specific

### 6.3 Video Lunghi / Segmentazione

Quando la durata richiesta supera il massimo del provider:

- il job puo splittare il reel in segmenti logici
- genera prompt e shot continuity
- concatena i file solo se `ffmpeg` e disponibile

Se `ffmpeg` non e disponibile:

- il sistema degrada verso clip singola entro i limiti del provider
- salva il motivo del downgrade nei metadati runtime

### 6.4 Audio su Video

File:

- `app/Services/SpeechSynthesisService.php`
- `app/Jobs/GenerateAiForContentItem.php`

Funzioni:

- crea narrazione sintetica
- estrae/controlla traccia audio esistente
- muxa audio e video via `ffmpeg`

### 6.5 Asset Fidelity / Riconoscimento Persona e Brand

Le leve principali sono:

- `asset_variables`
- `asset_identity`
- image reference pools
- brand image selection
- prompt hint per persona/prodotto/location
- strict asset mode

File chiave:

- `app/Services/AssetVariableService.php`
- `app/Services/AssetIdentityService.php`
- `app/Jobs/GenerateAiForContentItem.php`
- `app/Services/AI/ContentAlignmentService.php`

## 7. Dati e Metadati Runtime

Il contenitore principale della memoria runtime del contenuto e `ContentItem.ai_meta`.

Chiavi usate spesso:

- `tenant_profile`
- `brand_assets`
- `asset_variables_catalog`
- `asset_variables`
- `asset_identity`
- `plan`
- `strategy`
- `item_brain`
- `memory_summary`
- `knowledge_pack`
- `examples`
- `negative_examples`
- `feedback_signals`
- `provider_matrix`
- `feedback_loop`

Uso pratico:

- ricostruire contesto del job
- mantenere coerenza tra piani, asset e provider
- salvare fallback, retry, output e decisioni di runtime

## 8. Sitemap Funzionale delle Pagine

Routing principale in `routes/web.php`.

### 8.1 Area Admin

- `/admin`
- gestione tenant e utenti
- impersonation

### 8.2 Area Workspace Tenant

- `/dashboard`
- `/calendar`
- `/profile/brand`
- `/wizard`
- `/ai`
- `/settings`
- `/posts`
- `/content-items`

### 8.3 Route Operative AI

- `POST /ai/generate`
- `POST /ai/content/{contentItem}/generate`
- `POST /ai/plan/{contentPlan}/generate`
- `POST /ai/content/{contentItem}/image`

### 8.4 Route Social

- `GET /settings/social/meta/redirect`
- `GET /settings/social/meta/callback`
- `POST /settings/social/accounts/{socialAccount}/disconnect`

### 8.5 Route Push

- `GET /push/public-key`
- `POST /push/subscribe`
- `POST /push/test`

## 9. Sitemap Tecnica dei File

### 9.1 Config

Percorso: `config/`

- `generation.php`: provider defaults, strict mode, ffmpeg, fine tuning, alignment
- `openai.php`: modelli e timeout OpenAI
- `runway.php`: modello Runway, endpoint e polling
- `kling.php`: modello, endpoint e limiti Kling
- `nanobanana.php`: provider immagini Gemini/Nano Banana
- `elevenlabs.php`: speech clone e voice params
- `editorial.php`: policy e vincoli editoriali
- `meta.php`: integrazione Meta/Instagram/Facebook

### 9.2 Routes

Percorso: `routes/`

- `web.php`: tutte le route app principali
- `auth.php`: autenticazione
- `console.php`: comandi console

### 9.3 Controllers

Percorso: `app/Http/Controllers/`

- `TenantProfileController.php`: Brand Center, asset, variables, quickstart
- `PlanWizardController.php`: piano editoriale e progress
- `ContentItemController.php`: CRUD contenuti, create reel, durata video, asset refs
- `AiGenerateController.php`: avvio generazione e provider preference
- `AiController.php`: endpoint AI generici
- `CalendarController.php`: approvazione e calendario
- `SettingsController.php`: fine tuning e settaggi
- `SocialAccountController.php`: connessioni Meta
- `AdminWorkspaceController.php`: area admin

### 9.4 Jobs

Percorso: `app/Jobs/`

- `GenerateAiForContentItem.php`: orchestratore principale AI
- `PublishSocialPublication.php`: pubblicazione effettiva social

### 9.5 Services AI / Generation

Percorso: `app/Services/`

- `OpenAiService.php`: text, image, video, speech OpenAI
- `RunwayService.php`: create/poll/download Runway
- `KlingService.php`: create/poll/download Kling
- `NanoBananaService.php`: image generation Gemini/Nano Banana
- `ElevenLabsService.php`: TTS/clone ElevenLabs
- `SpeechSynthesisService.php`: orchestrazione speech provider
- `MemoryBuilderService.php`: memoria storica tenant
- `AssetVariableService.php`: catalogo e resolve variabili asset
- `AssetIdentityService.php`: identity consistency e asset meta
- `GuidedAssetVariableService.php`: persona pack guidato
- `StrategyBrainService.php`: composer strategico standalone

### 9.6 Services Editoriali

Percorso: `app/Services/Editorial/`

- `EditorialStrategyService.php`: strategia persistita tenant
- `EditorialPlanBuilder.php`: costruzione piano editoriale
- `ContentGenerator.php`: crea `ContentItem` con blueprint/meta
- `ContentHistoryAnalyzer.php`: storico contenuti
- `DuplicateContentGuard.php`: fingerprint e anti-duplicati
- `TrendBriefService.php`: trend per il piano

### 9.7 Services AI di Supporto

Percorso: `app/Services/AI/`

- `AiProviderMatrixService.php`: matrice provider finale
- `TenantContentIntelligenceService.php`: examples, negatives, asset library, signals
- `ContentAlignmentService.php`: grading testo e validazione immagine
- `TenantFineTuningService.php`: lifecycle fine tuning
- `TenantFineTuningDatasetService.php`: dataset builder

### 9.8 Social Services

Percorso: `app/Services/Social/`

- `SocialPublishingService.php`: scheduling e status
- `MetaGraphService.php`: integrazione API Meta
- `SocialAssetUrlService.php`: URL pubblici asset

### 9.9 Support / Resolver

Percorso: `app/Support/`

- `GenerationExecution.php`: dispatch sync/async
- `TextProviderResolver.php`
- `GraderProviderResolver.php`
- `ImageProviderResolver.php`
- `VideoProviderResolver.php`
- `SpeechProviderResolver.php`
- `ImagePromptRealismGuard.php`
- `PublicMediaUrl.php`
- `UiStatus.php`

### 9.10 Models

Percorso: `app/Models/`

- `Tenant.php`
- `TenantProfile.php`
- `EditorialStrategy.php`
- `ContentPlan.php`
- `ContentItem.php`
- `BrandAsset.php`
- `AssetVariable.php`
- `ContentFeedbackEntry.php`
- `SocialAccount.php`
- `SocialPublication.php`
- `TenantFineTuneRun.php`
- `PushSubscription.php`
- `TrendBrief.php`

### 9.11 Views

Percorso: `resources/views/`

- `wizard/brand.blade.php`: Brand Center
- `wizard/start.blade.php`: avvio wizard piano
- `wizard/done.blade.php`: conferma wizard
- `plans/generating.blade.php`: progress piano
- `posts/create.blade.php`: creazione contenuto/reel
- `posts/edit.blade.php`: edit contenuto e durata video
- `posts/generating.blade.php`: progress contenuto
- `posts/index.blade.php`: lista contenuti
- `content-items/index.blade.php`: gallery
- `content-items/show.blade.php`: dettaglio contenuto
- `calendar/index.blade.php`: calendario
- `settings.blade.php`: settings e fine tuning
- `ai.blade.php`: area AI generica

### 9.12 Tests

Percorso: `tests/`

Unit test chiave:

- `tests/Unit/GenerateAiForContentItemTest.php`
- `tests/Unit/OpenAiServiceTest.php`
- `tests/Unit/RunwayServiceTest.php`
- `tests/Unit/KlingServiceTest.php`
- `tests/Unit/NanoBananaServiceTest.php`
- `tests/Unit/ImagePromptRealismGuardTest.php`
- `tests/Unit/StrategyBrainServiceTest.php`

Feature test chiave:

- `tests/Feature/EditorialGenerationTest.php`
- `tests/Feature/GenerationProgressTest.php`
- `tests/Feature/ReelCreatePresetTest.php`
- `tests/Feature/BrandQuickstartTest.php`
- `tests/Feature/BrandKnowledgeAssetsTest.php`
- `tests/Feature/BrandVariableAssetExtensionTest.php`
- `tests/Feature/AssetIdentityFlowTest.php`
- `tests/Feature/ContentFeedbackTest.php`
- `tests/Feature/SocialPublishingTest.php`
- `tests/Feature/ImageProviderEnforcementTest.php`

## 10. Dove Nascono Asset, Strategia e Contenuti

### Asset

Creazione e ingestione:

- `TenantProfileController::store()`
- `TenantProfileController::storeBrandAssetUpload()`
- `TenantProfileController::storeBrandKnowledgeText()`
- `TenantProfileController::storeBrandLinkReference()`

Organizzazione semantica:

- `AssetVariableService`
- `AssetIdentityService`
- `GuidedAssetVariableService`

Uso in generazione:

- `ContentItemController` costruisce reference pool iniziale
- `GenerateAiForContentItem` seleziona e filtra immagini/video di riferimento

### Strategia Brand

Generazione/persistenza:

- `EditorialStrategyService::refreshForTenant()`
- `EditorialStrategyService::applyStudioInputs()`

Uso runtime:

- serializzata in `ContentPlan.strategy`
- copiata in `ContentItem.ai_meta.strategy`
- usata dal job AI come blueprint di brand voice, visual system e publishing rules

### Contenuti

Blueprint iniziale:

- `EditorialPlanBuilder`
- `ContentGenerator`

Generazione finale AI:

- `GenerateAiForContentItem`

Pubblicazione:

- `SocialPublishingService`

## 11. Ordine di Lettura Consigliato per Altri

Per un'altra persona o un altro LLM, l'ordine migliore e:

1. `routes/web.php`
2. `app/Http/Controllers/TenantProfileController.php`
3. `app/Http/Controllers/PlanWizardController.php`
4. `app/Http/Controllers/ContentItemController.php`
5. `app/Http/Controllers/AiGenerateController.php`
6. `app/Services/Editorial/EditorialStrategyService.php`
7. `app/Services/Editorial/EditorialPlanBuilder.php`
8. `app/Services/Editorial/ContentGenerator.php`
9. `app/Jobs/GenerateAiForContentItem.php`
10. `app/Services/OpenAiService.php`, `RunwayService.php`, `KlingService.php`, `NanoBananaService.php`
11. `app/Services/AI/TenantContentIntelligenceService.php`
12. `app/Services/AI/ContentAlignmentService.php`
13. `tests/Unit/GenerateAiForContentItemTest.php`
14. `tests/Feature/EditorialGenerationTest.php`

## 12. Checklist Operativa di Debug

Se una generazione fallisce, controllare in questo ordine:

1. `ContentItem.ai_status`
2. `ContentItem.ai_error`
3. `ContentItem.ai_meta.provider_matrix`
4. `ContentItem.ai_meta.asset_variables` e `asset_identity`
5. provider scelto vs provider locked
6. durata video richiesta vs durata supportata dal provider
7. disponibilita `ffmpeg` / `ffprobe`
8. timeout provider e queue worker
9. reference image disponibili e accessibili
10. `strict_asset_mode`

Verifiche server tipiche:

```bash
php artisan optimize:clear
php artisan config:clear
php artisan queue:restart
which ffmpeg
which ffprobe
```

## 13. Env Keys Più Importanti

### Core generation

```env
GENERATION_FORCE_SYNC=
GENERATION_STRICT_ASSET_MODE=true
VIDEO_AUTO_AUDIO=true
FFMPEG_BINARY=
FFPROBE_BINARY=
```

### OpenAI

```env
OPENAI_TEXT_MODEL=gpt-4.1-mini
OPENAI_VISION_MODEL=gpt-4.1-mini
OPENAI_IMAGE_MODEL=gpt-image-1
OPENAI_VIDEO_MODEL=sora-2
OPENAI_SPEECH_MODEL=gpt-4o-mini-tts
OPENAI_SPEECH_VOICE=alloy
OPENAI_VIDEO_SIZE=720x1280
OPENAI_VIDEO_SECONDS=8
OPENAI_VIDEO_POLL_TIMEOUT=900
```

### Runway

```env
VIDEO_PROVIDER_DEFAULT=runway
RUNWAY_VIDEO_MODEL=gen4.5
RUNWAY_VIDEO_SECONDS=8
RUNWAY_VIDEO_RATIO=
RUNWAY_VIDEO_POLL_TIMEOUT=420
```

### Kling

```env
KLING_VIDEO_MODEL=
KLING_VIDEO_MODE=pro
KLING_VIDEO_SECONDS=5
KLING_VIDEO_RATIO=9:16
KLING_VIDEO_POLL_TIMEOUT=540
```

### Nano Banana

```env
IMAGE_PROVIDER_DEFAULT=nanobanana
NANOBANANA_IMAGE_MODEL=gemini-2.5-flash-image
NANOBANANA_ASPECT_RATIO=4:5
```

### ElevenLabs

```env
VOICE_CLONE_PROVIDER_DEFAULT=elevenlabs
ELEVENLABS_MODEL_ID=eleven_multilingual_v2
ELEVENLABS_OUTPUT_FORMAT=mp3_44100_128
```

## 14. Sintesi Finale

Architetturalmente il sistema e diviso in quattro livelli:

1. acquisizione brand e asset
2. costruzione strategia e piano
3. orchestrazione AI multi-provider per asset finali
4. pubblicazione e feedback loop

Il file piu importante per capire il comportamento reale e:

- `app/Jobs/GenerateAiForContentItem.php`

Il secondo punto critico e:

- `TenantProfileController` per capire da dove arrivano asset, knowledge, variables e strategia

Il terzo e:

- `EditorialStrategyService` + `EditorialPlanBuilder` + `ContentGenerator` per capire come nasce il contenuto prima della generazione AI vera e propria

Se serve una seconda versione del documento, i possibili step successivi sono:

- aggiungere diagrammi Mermaid
- aggiungere tabella completa dei campi DB
- aggiungere sequenza dettagliata di `ai_meta` per ogni formato (`post`, `story`, `reel`)
- aggiungere matrice provider x capability x limiti runtime
