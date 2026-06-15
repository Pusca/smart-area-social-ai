# Smart Area Social AI — Stato Tecnico del Progetto

> Aggiornato: 28 aprile 2026  
> Ramo attivo: `deploy/2026-02-18`

---

## 1. Panoramica dell'applicativo

Smart Area Social AI è un SaaS multi-tenant Laravel 12 per la generazione automatizzata di contenuti social (Instagram, LinkedIn, TikTok, Facebook, Google Business) tramite AI.

Il flusso principale è:
1. L'utente configura il brand (identità, immagini, voce, strategia editoriale)
2. Sceglie un formato (post, reel, storia) e attiva la generazione
3. Un job in coda esegue una pipeline a 6 step (testo → visual → salvataggio)
4. Il contenuto generato viene revisionato e pubblicato

**Stack tecnico**
- Backend: Laravel 12, PHP 8.2+
- Frontend: Blade + Tailwind CSS + Alpine.js + Vite
- Queue: Laravel Queue (driver database, migrabile a Redis)
- DB: MySQL/PostgreSQL (56 migration)
- SSE: Server-Sent Events per progress real-time
- Multi-tenancy: middleware custom (ResolveActiveTenant, EnsureUserHasTenant)

---

## 2. Provider AI integrati

### 2.1 Tabella provider

| Area | Provider | Modello default | Fallback chain |
|------|----------|-----------------|----------------|
| **Testo** | OpenAI | `gpt-4.1-mini` | nessuno |
| **Immagine** | NanoBanana (Gemini 2.5 Flash) | configurato via NanoBanana | OpenAI GPT Image |
| **Immagine** | OpenAI | `gpt-image-1` | NanoBanana |
| **Video** | Google Veo | `veo-3.1-generate-preview` | OpenAI Sora → Kling |
| **Video** | OpenAI (Sora) | `sora-1.0-turbo` | Google Veo → Kling |
| **Video** | Kling | `kling-v3-omni` | Google Veo → OpenAI |
| **Video** | Runway | `gen4.5` | OpenAI → Kling |
| **Audio/TTS** | OpenAI TTS | `gpt-4o-mini-tts` | ElevenLabs |
| **Voice Clone** | ElevenLabs | configurato | nessuno |
| **Grading** | OpenAI | stesso del testo | nessuno |

### 2.2 Come funziona il fallback

La catena di fallback è definita in `config/provider_capabilities.php`.  
Il metodo `ProviderCapabilityRegistry::fallbackProviders()` restituisce solo i provider con API key configurata (`isConfigured()`), quindi un provider senza chiave viene saltato automaticamente.

Il fallback video si attiva da `shouldFallbackFrom[Provider]ToSecondaryProvider()` in `GenerateAiForContentItem.php`. Ogni provider ha una lista di errori che abilitano il fallback (timeout, 502/503/504, missing download URL, ecc.).

> **Nota**: i 429 da Kling (crediti esauriti) erano un problema attivo — risolto riordinando le fallback chain per mettere OpenAI prima di Kling.

### 2.3 Configurazione chiavi API

Variabili `.env` necessarie:

```
OPENAI_API_KEY=
OPENAI_VIDEO_MODEL=sora-1.0-turbo

NANOBANANA_API_KEY=

GOOGLE_VEO_API_KEY=

KLING_ACCESS_KEY=
KLING_SECRET_KEY=

RUNWAY_API_KEY=

ELEVENLABS_API_KEY=
```

---

## 3. Architettura pipeline di generazione

Il core è `app/Jobs/GenerateAiForContentItem.php` (≈9.400 righe — vedi §6 per i problemi).

### Step della pipeline

```
1. BuildGenerationContextStep
   → assembla: brand knowledge, memoria feedback, trend brief, brief creativo
   → output: tenant_profile, strategy, creative_brief, asset_variables

2. ResolveProviderMatrixStep
   → seleziona provider/modello per ogni area (testo, immagine, video, audio)
   → rispetta lock espliciti (ai_meta.video_provider_lock)
   → output: provider_matrix

3. GenerateBaseTextStep
   → chiama OpenAI per caption, hashtag, CTA, struttura narrativa
   → output: ai_caption, ai_hashtags, item_brain

4. BuildVisualPromptStep
   → costruisce il prompt visivo combinando: brief, brand, stile, riferimenti
   → output: visual.prompt, visual.brand_decision, visual.logo_runtime

5. GenerateVisualAssetStep
   → chiama il provider immagine o video
   → gestisce fallback, retry, riferimenti brand, overlay logo
   → output: ai_image_path / video in Storage::disk('public')

6. PersistGenerationOutputsStep
   → salva ai_meta, qualità score, audit trail, genera anteprima
   → aggiorna GenerationRun, emette notifica workspace
```

### Stato su ContentItem

```
queued → pending → succeeded
                 ↘ failed
```

Il progresso viene trasmesso in SSE via `GET /wizard/stream/{contentPlan}`.

---

## 4. Funzionalità principali

### 4.1 Brand Center (`/profile/brand`)
- Upload asset (immagini, video, documenti, loghi)
- Definizione asset variables (personas, voci, stili visuali)
- Persona pack guidata (identità strutturata)
- Voice upload per clonazione voce (ElevenLabs)
- Scraping brand da URL (`BrandScraperService`)
- Demo quickstart (7 giorni, 3 post, provider selezionabile)

### 4.2 Wizard Editoriale (`/wizard`)
- Parametri strategici: settore, tono, pubblico target
- Generazione calendario 7-30 giorni con AI
- Progress real-time via SSE
- Selezione provider video/immagine prima della generazione

### 4.3 Creazione contenuti (`/posts/create`, `/posts/reels/create`)
- Brief manuale + riferimenti immagine
- Selezione provider/modello per generazione
- Generazione a richiesta (immagine o video)
- Edit post-generazione con rigenera parziale

### 4.4 Alter Ego / Persona Digitale (`/alter-ego`)
- Creazione multipli brand persona
- Clonazione voce
- Adattamenti per piattaforma (Instagram, TikTok, LinkedIn)
- Analytics per persona

### 4.5 Pubblicazione Social
- Meta (Instagram/Facebook), LinkedIn, TikTok, Google Business
- OAuth per ogni piattaforma
- Scheduling con pubblicazione automatica via `PublishSocialPublication` job
- Calendario editoriale (`/calendar`)

### 4.6 Integrazione Canva
- Sync template catalog
- Autofill design via API Canva
- Export job con polling asincrono
- Mapping asset brand ↔ variabili Canva

### 4.7 Analytics e apprendimento
- Quality scorecard per ogni contenuto generato
- Learning loop: rileva hook preferiti, formato sottoperformante, CTA efficaci
- Fine-tuning OpenAI sul modello testo (dataset costruito da feedback utente)
- Trend intelligence (aggregazione segnali, sintesi opportunità)

---

## 5. Database — modelli chiave

```
Tenant ←→ User           (via user_tenant_memberships)
Tenant → TenantProfile    (1:1 — strategia, stile, preferenze overlay)
Tenant → BrandAsset*      (immagini, video, loghi, documenti)
Tenant → AssetVariable*   (personas, voci, stili)
Tenant → EditorialStrategy
Tenant → ContentPlan*
ContentPlan → ContentItem*
ContentItem → GenerationRun* → GenerationAttempt*
ContentItem → SocialPublication*
ContentItem → ContentFeedbackEntry*
ContentItem → CanvaDesign*
AlterEgo → ContentItem*   (opzionale)
```

**Colonne JSON critiche** (mancanza di schema formale = rischio di regressione):
- `ContentItem.ai_meta` — context, strategy, provider preferences, feedback loop, asset vars
- `TenantProfile.strategy_blueprint`, `creative_direction`, `overlay_preferences`, `learning_preferences`
- `GenerationRun.requested_provider_matrix`, `quality_scorecard`, `storyboard_meta`, `token_usage`

---

## 6. Problemi tecnici e debito

### 🔴 Critici (impattano la stabilità)

**6.1 `GenerateAiForContentItem.php` — 9.400 righe**  
Un singolo file job contiene: logica di generazione video per 4 provider, storyboard, estensione video, fallback chains, demo preset, metriche, audit, notifiche, utility audio/immagine.  
→ **Rischio**: impossibile testare in isolamento, altissima probabilità di regressioni su modifica  
→ **Azione**: estrarre almeno `VideoGenerationOrchestrator`, `DemoPresetService`, `GenerationNotifierService`

**6.2 Endpoint OpenAI video errato** *(ora corretto)*  
Il codice usava `POST /v1/videos` invece di `POST /v1/video/generations`.  
Anche `n_seconds` (int) era `seconds` (string), e il campo immagine-to-video usava `input_reference` invece di `frames[].image_url`.  
→ **Fix applicato** in `app/Services/OpenAiService.php` — commit corrente

**6.3 Nessun transaction boundary nella pipeline**  
I 6 step aggiornano `ContentItem` senza transazione DB. Se lo step 5 fallisce parzialmente, gli step 1-4 sono già persistiti → stato inconsistente.  
→ **Azione**: wrappare almeno step 4-6 in una transazione o usare pattern saga con compensazione

**6.4 Cache invalidation incompleta**  
La `knowledge pack cache` si invalida solo su CRUD `BrandAsset`. Aggiornamenti a `TenantProfile`, strategia editoriale o feedback non invalidano la cache.  
→ **Rischio**: generazioni con contesto stale  
→ **Azione**: aggiungere `Observer` su `TenantProfile`, `EditorialStrategy`, `ContentFeedbackEntry`

### 🟡 Importanti (impattano qualità e manutenibilità)

**6.5 Classi service troppo grandi**

| File | Righe |
|------|-------|
| `GenerateAiForContentItem.php` | ~9.400 |
| `GenerationQualityScorecardService.php` | ~1.789 |
| `OpenAiService.php` | ~1.611 |
| `EditorialPlanBuilder.php` | ~1.144 |
| `ContentAngleEngine.php` | ~796 |

→ **Azione**: suddivisione per responsabilità (SRP)

**6.6 Test suite non affidabile**  
230 test censiti, ma molti marcati "risky" (codice 7) o "skipped" (codice 8) nel cache PHPUnit.  
Aree con copertura dubbia: SocialPublishingService, fallback chain video, fine-tuning flow.  
→ **Azione**: audit completo del test suite, fix dei test risky, aggiungere test per fallback chain

**6.7 Timeout video stretti**  
- Job timeout: 1200s (20 min)  
- Video poll timeout: 900s (15 min)  
- Margine effettivo: ~300s per il resto della pipeline  
→ **Rischio**: generazioni video lunghe vanno in timeout prima del completamento  
→ **Azione**: aumentare job timeout a 1800s o separare il job video in una coda dedicata

**6.8 `TenantQuotaService` non enforced**  
Il servizio esiste ma non è chiamato nei controller/job.  
→ **Rischio**: costi provider illimitati per tenant in multi-brand  
→ **Azione**: aggiungere check quota prima dell'avvio generazione

**6.9 Demo mode senza guardia esplicita**  
`isDemoMode()` legge `SOCIAL_DEMO_MODE` da env. Se questa variabile viene impostata su prod per sbaglio, tutti generano demo.  
→ **Azione**: aggiungere guard con fallback esplicito + log warning se attivo su APP_ENV=production

**6.10 SSE client timeout per video lunghi**  
Il client SSE si disconnette dopo `generation.sse_max_seconds` (default 300s). Per video che richiedono 10-15 minuti, la UI mostra errore mentre in realtà la generazione continua in coda.  
→ **Azione**: separare il polling SSE dallo stato del job; il frontend dovrebbe fare polling REST dopo la disconnessione SSE

### 🟢 Miglioramenti (non urgenti)

**6.11 `ai_meta` senza schema formale**  
Il campo JSON `ContentItem.ai_meta` cresce organicamente senza validazione. Campi deprecati non vengono mai puliti.  
→ **Azione**: definire un DTO/ValueObject con cast Laravel

**6.12 Costruttori con molte dipendenze**  
`BuildGenerationContextStep` ha 7 dipendenze iniettate nel costruttore. Riducibile con injection per metodo o Service Locator controllato.

**6.13 Admin impersonation senza audit trail**  
`POST /admin/users/{user}/impersonate` non logga chi ha impersonato chi e per quanto tempo.  
→ **Azione**: aggiungere log in tabella dedicata

**6.14 Fine-tuning threshold non enforced**  
Il fine-tuning richiede min 12 esempi ma l'enforcement è solo in validazione form — non blocca il job.

---

## 7. Route principali

```
GET  /dashboard                         dashboard
GET  /calendar                          calendario editoriale
GET  /profile/brand                     brand center
GET  /wizard                            wizard editoriale
GET  /wizard/stream/{plan}              SSE progress generation
POST /wizard/generate                   avvia generazione piano
POST /ai/generate                       generazione singola
POST /ai/content/{item}/generate        rigenera contenuto
GET  /posts/create                      crea post
GET  /posts/reels/create                crea reel
GET  /alter-ego                         lista alter ego
GET  /settings/social/{platform}/...    OAuth social
POST /settings/fine-tuning/start        avvia fine-tuning
GET  /admin                             dashboard admin (platformAdmin)
```

---

## 8. Job e processi background

| Job | Timeout | Retry | Scopo |
|-----|---------|-------|-------|
| `GenerateAiForContentItem` | 1200s | 3 (30s, 60s) | Pipeline completa di generazione AI |
| `PublishSocialPublication` | default | default | Pubblica post schedulati |
| `PollCanvaDesignAutofillJob` | default | default | Polling completamento design Canva |
| `PollCanvaExportJob` | default | default | Polling export Canva |

**Modalità esecuzione** (controllata da `GenerationExecution`):
- `sync` — esecuzione sincrona nella request (dev)
- `after_response` — esecuzione dopo la risposta HTTP (staging/light prod)
- `queue` — coda Laravel (prod)

---

## 9. Configurazioni critiche da verificare su ogni deploy

```env
# Provider AI — almeno uno per area deve essere configurato
OPENAI_API_KEY=                   # testo, immagine, video (Sora), TTS
NANOBANANA_API_KEY=               # immagine (consigliato come primario)
GOOGLE_VEO_API_KEY=               # video (consigliato come primario)
KLING_ACCESS_KEY=                 # video (fallback)
KLING_SECRET_KEY=
RUNWAY_API_KEY=                   # video (opzionale)
ELEVENLABS_API_KEY=               # voice clone

# Modelli video
OPENAI_VIDEO_MODEL=sora-1.0-turbo  # NON sora-2 (endpoint diverso)

# Generazione
GENERATION_FORCE_SYNC=false       # true solo in dev
SOCIAL_DEMO_MODE=false            # MAI true in prod

# Queue
QUEUE_CONNECTION=database          # o redis in prod

# Social OAuth (almeno uno per piattaforma target)
META_APP_ID=
META_APP_SECRET=
LINKEDIN_CLIENT_ID=
LINKEDIN_CLIENT_SECRET=
TIKTOK_CLIENT_KEY=
TIKTOK_CLIENT_SECRET=
```

---

## 10. Comandi utili

```bash
# Deploy standard
git pull origin deploy/2026-02-18
composer install --no-dev --optimize-autoloader
php artisan config:clear
php artisan cache:clear
php artisan migrate --force

# Workers
php artisan queue:work --timeout=1300 --tries=3

# Fine-tuning sync
php artisan tinker --execute="app(\App\Services\AI\TenantFineTuningService::class)->syncAll()"

# Debug generazione
php artisan tinker
# → GenerationRun::latest()->first()->quality_scorecard

# Test (esclude i risky)
php artisan test --exclude-group risky
```

---

## 11. Roadmap tecnica consigliata

### Breve termine (immediato)
- [x] Fix endpoint OpenAI Sora (`/v1/video/generations`)
- [x] Fix fallback chain video (OpenAI prima di Kling)
- [ ] Aggiungere `Observer` per cache invalidation su TenantProfile/Strategy
- [ ] Aumentare job timeout video a 1800s o usare coda dedicata
- [ ] Enforcing `TenantQuotaService` nei controller

### Medio termine (1-2 mesi)
- [ ] Estrarre `VideoGenerationOrchestrator` da `GenerateAiForContentItem`
- [ ] Aggiungere transaction boundary agli step 4-6 della pipeline
- [ ] Audit e fix del test suite (target: 0 test risky/skipped)
- [ ] DTO formale per `ContentItem.ai_meta`
- [ ] Audit log per admin impersonation

### Lungo termine (3-6 mesi)
- [ ] Suddivisione `GenerationQualityScorecardService` e `OpenAiService`
- [ ] Separare job video da job testo/immagine (code diverse, timeout diversi)
- [ ] Event sourcing per stati di generazione (replace status machine)
- [ ] Rate limiting per tenant (abuse prevention)
- [ ] Schema migration per colonne JSON → colonne typed o cast DTO

---

*Documento generato automaticamente da analisi codebase. Aggiornare dopo ogni sprint significativo.*
