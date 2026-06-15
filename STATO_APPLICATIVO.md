# Smart Area Social AI — Stato applicativo
> Aggiornato al 30 aprile 2026 · Branch attivo: `deploy/2026-02-18`

---

## Stack tecnico

| Layer | Tecnologia |
|---|---|
| Backend | Laravel 12, PHP 8.2+ |
| Database | SQLite (dev) · PostgreSQL (prod) |
| Frontend | Blade, Alpine.js v3, Tailwind CSS, Vite |
| Queue | Database queue driver |
| Storage | Local disk (Laravel Storage) |
| AI testo | OpenAI GPT-4.1-mini (Responses API) |
| AI immagini | OpenAI gpt-image-1 (Images API) |
| AI video | Google Veo · OpenAI Sora (sora-1.0-turbo) |
| AI audio | OpenAI Whisper-1 (trascrizione) · gpt-4o-mini-tts (TTS) |
| AI video B | NanoBanana · Kling · Runway (provider alternativi) |
| Voce sintetizzata | ElevenLabs |
| Integrazioni social | Meta Graph API · LinkedIn API · TikTok API · Google Business API |
| Integrazione design | Canva Connect API |
| Notifiche push | Web Push (VAPID) |
| Deploy | VPS Linux · php-fpm · dominio: socialai.smartera.it |

---

## Architettura multi-tenant

- **Tenant**: ogni azienda/brand è un `Tenant`
- **User → Tenant**: relazione M:N via `UserTenantMembership`; un utente può gestire più brand (modalità agenzia)
- **TenantProfile**: profilo brand con campi `business_name`, `industry`, `services`, `target`, `default_tone`, `default_goal`, `default_platforms`, `vision`, `values`, `cta`, `notes`, overlay preferences, learning preferences
- **Middleware**: `resolveActiveTenant` → `hasTenant` → `onboardingComplete` (in sequenza)
- **Switch brand**: `AgencyBrandSwitchController` per passare da un brand all'altro in sessione

---

## Moduli principali

### 1. Onboarding AI (voice-first)
**Stato: funzionante**

Flusso per i nuovi utenti per configurare il brand tramite conversazione vocale.

- **Registrazione audio**: `MediaRecorder` API (nessuna dipendenza da Web Speech API)
- **Trascrizione**: OpenAI Whisper-1 via `BrandParsingService::transcribe()`
- **Estrazione campi**: GPT-4.1-mini via `BrandParsingService::chat()` — estrae fino a 11 campi brand da testo libero
- **Salvataggio immediato**: ogni risposta AI che porta nuovi campi chiama `mergeIntoProfile()` e persiste in DB
- **UI**: field rows animate con valore reale, banner "Hai detto" separato dalla textarea, mic con 3 stati visivi (idle / recording / analyzing)
- **Protezione PC**: `echoCancellation + noiseSuppression + autoGainControl` su `getUserMedia` per evitare cattura audio di sistema
- **Upload asset**: immagini brand caricate via dropzone → `OnboardingController::uploadAssets()`
- **Completamento**: `BrandAiController::onboardingComplete()` → `QuickstartOnboardingService::regenerateQuickstart()` → redirect a `plans.generating`

Campi obbligatori (6): `business_name`, `industry`, `services`, `target`, `default_tone`, `default_goal`
Campi opzionali (5): `default_platforms`, `vision`, `values`, `cta`, `notes`

---

### 2. Brand Center (profilo brand)
**Stato: funzionante**

`/profile/brand` — gestione completa del profilo brand.

- Modifica manuale di tutti i campi `TenantProfile`
- **Aggiorna con AI**: pannello voce float (brand.blade.php) — stessa logica Whisper ma in modale slide-in
- **Scraping URL**: `TenantProfileController::scrapeUrl()` → `BrandScraperService` per pre-compilare dal sito web
- **Asset variables**: variabili riutilizzabili (persona guidata, pack immagini, ecc.) via `AssetVariableService`
- **Trend brief**: `TrendBriefService` — brief editoriale basato su trend attuali, aggiornabile manualmente
- **Asset upload/delete**: `BrandAsset` con analisi AI asincrona (`AnalyzeBrandAssetJob`)
- **Overlay preferences**: preferenze per overlay tipografici su immagini generate

---

### 3. Piano editoriale (wizard)
**Stato: funzionante**

`/wizard` — generazione piano editoriale mensile.

- Wizard multi-step: configurazione piattaforme, formati, frequenza, tono
- `EditorialPlanBuilder` → crea `ContentPlan` con N `ContentItem`
- `EditorialStrategyService` → strategia editoriale con `viral_framework`
- Generazione asincrona via Job queue + SSE streaming (`/wizard/stream/{contentPlan}`)
- `ContentAngleEngine` → angoli editoriali per ogni post
- `CreativeBriefCompiler` → brief creativo per ogni item

---

### 4. Pipeline generazione AI
**Stato: funzionante**

`GenerateAiForContentItem` (Job) → `ContentGenerationOrchestrator` → 6 step:

| Step | Classe | Funzione |
|---|---|---|
| 1 | `ResolveProviderMatrixStep` | Sceglie provider AI per testo/immagine/video |
| 2 | `BuildGenerationContextStep` | Assembla contesto brand + trend + alter ego + feedback |
| 3 | `GenerateBaseTextStep` | Genera caption, hashtag, CTA, image_prompt via GPT |
| 4 | `BuildVisualPromptStep` | Ottimizza prompt visuale + overlay brief |
| 5 | `GenerateVisualAssetStep` | Genera immagine (gpt-image-1) o video (Veo/Sora/Kling/Runway) |
| 6 | `PersistGenerationOutputsStep` | Salva risultati, scorecard qualità, audit |

Servizi di supporto alla pipeline:
- `AiProviderMatrixService` — routing dinamico provider per tipo contenuto
- `ContentAlignmentService` — verifica allineamento testo/visual al brand
- `GenerationQualityScorecardService` — scorecard qualità post-generazione
- `IdentityGuard` — protezione identità soggetti reali in immagini/video
- `ImageUnderstandingService` — analisi visiva asset brand
- `AssetScoringEngine` — scoring immagini brand per selezione migliore
- `PublishReadinessGate` — gate pre-pubblicazione

---

### 5. Alter Ego digitale
**Stato: funzionante**

`/alter-ego` — persona digitale da applicare ai contenuti.

- Wizard di creazione in step (`AlterEgoController::storeStep()`)
- Analisi voce da campioni testo (`OpenAiService::extractVoiceFromSamples()`)
- Upload media (foto/video) per il persona
- Import template da marketplace
- `AlterEgoConsistencyService` → scorecard aderenza caption al persona
- `AlterEgoAnalyticsService` → metriche performance per alter ego
- Multi alter-ego per tenant, con uno di default

Campi alter ego: nome, bio, tono, stile frasi, vocabolario, frasi caratteristiche, temi di proprietà, prospettiva unica, ruolo audience, persona_prompt, platform_adaptations

---

### 6. Gestione post
**Stato: funzionante**

`/posts` — libreria contenuti generati.

- Vista lista con filtri (piattaforma, formato, stato)
- Vista singolo post con preview Instagram-style
- Rigenerazione singolo post / piano completo
- Feedback per gusto del tenant (`ContentFeedbackController`)
- Invio a Canva per editing (`CanvaIntegrationController`)
- Feed generazioni attive in polling (`posts.generation.feed`)

---

### 7. Pubblicazione social
**Stato: funzionante (connessioni OAuth attive)**

- **Meta (Facebook/Instagram)**: OAuth via Graph API, `MetaGraphService`
- **LinkedIn**: OAuth, `LinkedInApiService`
- **TikTok**: OAuth, `TikTokApiService`
- **Google Business**: OAuth, `GoogleBusinessApiService`
- `SocialPublishingService` → dispatcher via `SocialPublisherRegistry`
- `PublishSocialPublication` Job per pubblicazione asincrona
- Calendario contenuti: `CalendarController` con approvazione post

---

### 8. Integrazione Canva
**Stato: funzionante**

- OAuth Canva Connect
- `CanvaTemplateCatalogService` → catalogo template disponibili
- `CanvaDesignGenerationService` → creazione design da template + contenuto AI
- `CanvaExportService` → export design completato
- `PollCanvaExportJob` + `PollCanvaDesignAutofillJob` (polling asincrono)
- Mapping template per formato/piattaforma

---

### 9. Intelligence e learning
**Stato: funzionante**

- `TenantFeedbackMemoryService` → memoria feedback per tenant
- `TenantFeedbackSignalSynthesisService` → sintesi segnali positivi/negativi
- `TenantLearningLoopService` → loop apprendimento da performance
- `TenantContentIntelligenceService` → intelligenza editoriale accumulata
- `MemoryBuilderService` → costruzione knowledge pack per ogni generazione
- `TrendIntelligenceService` + `TrendOpportunitySynthesisService` → trend da 6 adapter (config, curated, hashtag, creator best practice, editorial memory, internal performance)

---

### 10. Admin platform
**Stato: funzionante**

`/admin` (middleware `platformAdmin`)

- Lista workspace e tenant
- Metriche generazione AI aggregata
- Aggiornamento tenant/utenti
- Impersonazione utente/tenant per debug
- `GenerationMetricsService` + `GenerationAuditService`

---

## Modelli DB principali

| Modello | Tabella | Note |
|---|---|---|
| `User` | users | Auth standard Laravel |
| `Tenant` | tenants | Brand/azienda |
| `UserTenantMembership` | user_tenant_memberships | M:N utenti-tenant |
| `TenantProfile` | tenant_profiles | Profilo brand completo |
| `BrandAsset` | brand_assets | Immagini/video/documenti brand |
| `AssetVariable` | asset_variables | Variabili riutilizzabili (persona, pack, ecc.) |
| `ContentPlan` | content_plans | Piano editoriale mensile |
| `ContentItem` | content_items | Singolo post/reel/video |
| `GenerationRun` | generation_runs | Log esecuzione generazione AI |
| `GenerationAttempt` | generation_attempts | Tentativi singolo step |
| `ContentFeedbackEntry` | content_feedback_entries | Feedback utente sui contenuti |
| `EditorialStrategy` | editorial_strategies | Strategia editoriale + viral framework |
| `AlterEgo` | alter_egos | Persona digitale |
| `SocialAccount` | social_accounts | Account social connessi (OAuth) |
| `SocialPublication` | social_publications | Pubblicazioni programmate/inviate |
| `TrendSignal` | trend_signals | Segnali di trend |
| `TrendBrief` | trend_briefs | Brief trend per tenant |
| `CanvaConnection` | canva_connections | Token OAuth Canva |
| `CanvaDesign` | canva_designs | Design Canva associati a post |
| `CanvaExportJob` | canva_export_jobs | Job export Canva |
| `PushSubscription` | push_subscriptions | Subscriptions Web Push |
| `TenantFineTuneRun` | tenant_fine_tune_runs | Job fine-tuning OpenAI |

---

## Job asincroni

| Job | Trigger | Funzione |
|---|---|---|
| `GenerateAiForContentItem` | Creazione/rigenerazione post | Pipeline completa 6 step |
| `PublishSocialPublication` | Approvazione calendario | Pubblica su social |
| `AnalyzeBrandAssetJob` | Upload asset brand | Analisi AI immagine/video |
| `PollCanvaDesignAutofillJob` | Creazione design Canva | Polling completamento autofill |
| `PollCanvaExportJob` | Export design Canva | Polling export completato |

---

## Endpoint API principali

### Onboarding (no `onboardingComplete`)
```
GET  /onboarding
POST /onboarding/assets
POST /onboarding/complete
POST /ai/brand/chat                ← conversazione AI brand
POST /ai/brand/transcribe-chat     ← audio → Whisper → estrazione → DB
POST /ai/brand/apply               ← salva campi estratti in TenantProfile
POST /ai/brand/onboarding-complete ← merge + quickstart generation
```

### App (con `onboardingComplete`)
```
GET  /dashboard
GET  /posts
GET  /posts/{id}/edit
GET  /wizard
GET  /wizard/stream/{plan}   ← SSE streaming progress generazione
GET  /calendar
GET  /profile/brand
POST /profile/brand/scrape-url
POST /ai/brand/apply
GET  /alter-ego
GET  /settings
GET  /admin
```

---

## Provider AI e routing

Il `AiProviderMatrixService` decide il provider in base a:
- Tipo contenuto (immagine, video, testo)
- Configurazione tenant
- Formato richiesto (feed, reel, story, video)

Provider disponibili per video: **Veo** (Google), **Sora** (OpenAI), **Kling**, **Runway**, **NanoBanana**
Provider immagini: **gpt-image-1** (generazione), **gpt-image-1 edit** (con riferimenti brand)

---

## Debito tecnico noto

| Area | Problema | Priorità |
|---|---|---|
| `GenerateAiForContentItem.php` | Job monolitico residuo (anche se `ContentGenerationOrchestrator` è estratto) | Alta |
| Fine-tuning | Infrastruttura presente (`TenantFineTuneRun`), UI non completa end-to-end | Media |
| `ai_meta` JSON | Duplica parzialmente dati di `generation_runs` | Bassa |
| Canva export | Polling via Job — da migrare a webhook | Bassa |
| Notifiche UI | Web Push infrastruttura presente, UX non completata | Media |
| Test | 55 test presenti — copertura da verificare dopo refactoring orchestrator | Media |

---

## Variabili d'ambiente rilevanti

```
OPENAI_API_KEY
OPENAI_BASE_URL
OPENAI_TEXT_MODEL        # default: gpt-4.1-mini
OPENAI_IMAGE_MODEL       # default: gpt-image-1
OPENAI_VIDEO_MODEL       # default: sora-1.0-turbo
OPENAI_SPEECH_MODEL      # default: gpt-4o-mini-tts
OPENAI_VISION_MODEL      # default: gpt-4.1-mini
GOOGLE_VEO_*             # credenziali Google Veo
NANO_BANANA_API_KEY
KLING_*
RUNWAY_*
ELEVENLABS_API_KEY
META_APP_ID / META_APP_SECRET
LINKEDIN_CLIENT_ID / LINKEDIN_CLIENT_SECRET
TIKTOK_CLIENT_KEY / TIKTOK_CLIENT_SECRET
GOOGLE_OAUTH_*
CANVA_CLIENT_ID / CANVA_CLIENT_SECRET
VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY
```
