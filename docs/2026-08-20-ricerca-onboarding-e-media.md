# Ricerca 20/08/2026 — Onboarding solo-URL e overhaul immagini/video

Esito delle due ricerche fatte il 20 agosto 2026 (prezzi e disponibilità verificati sulle pagine ufficiali a quella data). Contesto: vogliamo un onboarding dove l'utente inserisce **solo l'URL del sito** e l'AI compila il profilo brand, e vogliamo **rifare generazione immagini + aggiungere i video** con uno stack minimale.

---

## ⚠️ Scadenze urgenti (indipendenti da ogni decisione)

| Cosa | Quando | Impatto |
|---|---|---|
| **`gpt-image-1` viene ritirato da OpenAI** | **23 ottobre 2026** | La pipeline immagini attuale smette di funzionare. Migrare prima. |
| **Sora 2 API chiude** (OpenAI esce dal video) | 24 settembre 2026 | Non costruire nulla su Sora. |
| Google Imagen 4 già spento (17/08/2026), Nano Banana 1 ritirato il 02/10/2026 | — | Usare i successori (Nano Banana 2 / Pro). |

---

## Parte 1 — Onboarding: da URL a profilo brand

### Raccomandazione

**Firecrawl (crawl del sito) + estrazione link social + 1 chiamata OpenAI structured output; arricchimento social asincrono con Apify.**

Flusso:
1. **Job in coda (~30-60s)**: Firecrawl `/crawl` con `limit: 15-20` pagine → markdown pulito di homepage, servizi, chi siamo, contatti (JS rendering incluso). SDK Composer ufficiale `firecrawl/firecrawl-sdk`, oppure semplice `Http::withToken()->post()`.
2. **Stesso job**: regex sul markdown per gli handle social (instagram.com/, facebook.com/, tiktok.com/, linkedin.com/company/).
3. **Una chiamata Responses API** (gpt-5-mini basta; gpt-5.1 se serve più qualità) con il nostro JSON schema → profilo brand completo, salvato direttamente.
4. **Job di arricchimento asincrono (best-effort, mai bloccante)**: Apify Instagram Profile Scraper (~$0.002/profilo, restituisce bio + **ultimi 12 post con engagement**) → seconda chiamata economica che raffina tono di voce + example_posts. TikTok analogo. **LinkedIn: solo handle, niente scraping** (enforcement aggressivo).

### Costi e sforzo

- **~$0.06–0.15 per onboarding** tutto compreso (Firecrawl ~$0.05, Apify ~$0.01, OpenAI ~$0.01–0.08).
- I free tier (Firecrawl 1.000 crediti/mese + Apify $5/mese) coprono **~50-60 onboarding/mese a costo zero**.
- Sforzo: **1-2 giorni** per crawl+estrazione+profilo, **+1 giorno** per l'arricchimento Apify.

### Alternative valutate e scartate

- **Jina Reader** (`r.jina.ai`): fallback zero-dipendenze, ~$0.002/sito, ma niente crawl — dovremmo scoprire noi le pagine via sitemap.xml (mezza giornata in più). Buon piano B.
- **OpenAI web_search / deep research**: buono come *arricchimento* (recensioni, reputazione: ~$0.05-0.10 extra), **inaffidabile come fonte primaria** per PMI italiane con poca presenza sui motori (rischio allucinazioni su servizi/contatti). Deep research: $0.30-1.50 e 2-10 min a run — overkill.
- **Tavily/Exa**: search-first, non migliori di Firecrawl per "crawla questo sito". **ScrapingBee**: HTML grezzo, livello di astrazione sbagliato.
- **Meta Graph API**: inutile in onboarding (richiede OAuth del cliente); torna utile dopo, per la pubblicazione.
- Note legali: scraping di dati pubblici del cliente stesso, su sua richiesta = posizione di rischio minima (precedenti *hiQ v. LinkedIn*, *Meta v. Bright Data*); documentare il legittimo interesse nei ToS.

Fonti: [Firecrawl pricing](https://www.firecrawl.dev/pricing) · [firecrawl-php SDK](https://github.com/firecrawl/firecrawl-php) · [OpenAI pricing](https://developers.openai.com/api/docs/pricing) · [Jina Reader](https://jina.ai/reader/) · [Apify IG Profile Scraper](https://apify.com/apify/instagram-profile-scraper) · [Apify TikTok](https://apify.com/clockworks/tiktok-scraper) · [Apify FB Pages](https://apify.com/apify/facebook-pages-scraper)

---

## Parte 2 — Immagini e video: lo stack minimale

### Panorama immagini (ago 2026)

| Modello | Prezzo/immagine | Testo nell'immagine | Coerenza brand |
|---|---|---|---|
| **OpenAI GPT Image 2** (successore drop-in di gpt-image-1) | ~$0.04-0.05 (media) | **Migliore sul mercato** (anche paragrafi) | Reference multi-immagine, edit, inpainting |
| **Google Nano Banana 2** (`gemini-3.1-flash-image`) | $0.067 a 1K (batch −50%) | Ottimo su testi brevi/loghi | Editing conversazionale, reference images |
| **Google Nano Banana Pro** (`gemini-3-pro-image`) | ~2-3× NB2 | Top anche multilingua | **Fino a 14 reference images, 5 soggetti coerenti** — miglior "brand kit" |
| Flux 2 / Ideogram 3 / Recraft V4 | $0.025-0.09 | inferiori/niche | Kontext editing (Flux), vector/SVG (Recraft) |

### Panorama video (reel 5-15s, image-to-video)

| Modello | Prezzo | Audio | Note |
|---|---|---|---|
| **Google Veo 3.1 Fast** | **$0.10-0.12/s** (Lite $0.05-0.08/s) | **Nativo incluso** | Image-to-video, reference images, API pubblica Gemini |
| **Kling 3.0** | $0.084-0.168/s (2.5 Turbo $0.07/s su fal) | Nativo (3.0) | Qualità i2v top; accesso diretto scomodo → meglio via aggregatore |
| Runway Gen-4.5 | $0.12/s | — | Più caro a parità di qualità |
| MiniMax Hailuo H3 / Wan 2.5 / Seedance 2.5 | $0.05-0.22/s | vario | Alternative via aggregatori |
| Gemini Omni Flash | ~$0.10/s | in arrivo | Editing video conversazionale — la frontiera, da tenere d'occhio |

### Aggregatori (fal.ai / Replicate)

Una sola chiave + una sola forma di API HTTP per quasi tutti i modelli sopra (incluse Nano Banana 2, GPT Image 2, Veo 3.1, Kling 3.0), con **webhook** (comodi per i job Laravel). Contro: markup 10-50% su alcuni modelli premium e un intermediario in più nella catena di affidabilità.

### Raccomandazione

**Stack scelto: OpenAI (testo + immagini) + Google Gemini (video).** Due vendor, entrambi già mainstream, zero aggregatori:

1. **Immagini → GPT Image 2**: è un **drop-in sugli stessi endpoint** `/v1/images/generations` + `/v1/images/edits` che già chiamiamo, ha il miglior text rendering del mercato (decisivo per grafiche social) e supporta reference images per la coerenza brand (foto/logo del tenant!). Migrazione obbligata comunque entro il 23/10. Sforzo: **2-4 giorni** incluso il passaggio a edit-con-reference.
2. **Video → Veo 3.1 Fast via Gemini API**: audio nativo, image-to-video dal visual generato, $0.80-0.96 per un reel da 8s (Lite: ~$0.40-0.64). API `predictLongRunning` + polling → si sposa con la coda database esistente. Sforzo: **~1 settimana** (job nuovo, storage MP4, stato su `AiStatus` esistente).

**Costo per post: ~$0.05 (immagine) / ~$0.60-1.00 (reel 8s con audio).**

Alternative registrate:
- **Tutto Google** (Nano Banana 2/Pro + Veo): un solo vendor media, brand-kit fino a 14 reference; ma testo-in-immagine inferiore a GPT Image 2 e riscrittura completa del layer immagini.
- **fal.ai come gateway unico**: massima flessibilità di modelli (Kling a $0.07/s) e webhook; da considerare se in futuro vorremo cambiare modelli spesso.

Fonti: [Gemini API pricing](https://ai.google.dev/gemini-api/docs/pricing) · [gpt-image-2](https://developers.openai.com/api/docs/models/gpt-image-2) · [OpenAI image guide](https://developers.openai.com/api/docs/guides/image-generation) · [confronto GPT Image 2 / NB2 / Flux 2](https://ropewalk.ai/blog/gpt-image-2-vs-nano-banana-2-vs-imagen-4-vs-flux-2-2026) · [Nano Banana Pro](https://blog.google/innovation-and-ai/products/nano-banana-pro/) · [chiusura Sora](https://www.atlascloud.ai/blog/guides/sora-is-dead-best-sora-alternatives-after-the-openai-sora-shutdown-in-2026) · [Kling 3.0](https://aijourn.com/what-is-the-kling-3-0-api-features-pricing-how-to-use-it/) · [fal.ai models](https://fal.ai/models) · [Replicate official](https://replicate.com/collections/official) · [markup fal](https://ofox.ai/blog/fal-ai-alternatives-video-generation-api-2026/) · [Gemini Omni Flash](https://www.eesel.ai/blog/gemini-omni-flash-pricing)

---

## Parte 3 — Layer template per le grafiche con testo (decisione 20/08)

Per i post con testo in overlay (card promo, tips, quote, story) la diffusione pura non basta: il testo renderizzato è l'unico affidabile al 100%. Architettura decisa:

1. **Claude Design come studio interno** (non è incorporabile nel SaaS: strumento interattivo, niente API): progettiamo lì la libreria di template — card promo, educational/tips, quote, story — l'utente li rifinisce visivamente e li approva.
2. **Ogni template approvato diventa un template Blade/HTML parametrizzato** nel SaaS: colori/font/logo del tenant + testi AI come variabili.
3. **A runtime**: GPT Image 2 genera il layer fotografico → il template si riempie → **`spatie/browsershot`** (Puppeteer) renderizza il PNG finale. Costo per grafica: centesimi di token, testo perfetto, modificabile senza rigenerare.

Stato: da fare dopo la ristrutturazione (prima bug + nuova struttura). Il divieto attuale di testo nelle immagini nei prompt resta valido per il layer fotografico.

## Stato di attuazione (aggiornato 20/08/2026, sera)

Fatto nella sessione del 20/08 (commit `9a1c719` → `1735ff8`):

- ✅ **Bug bloccanti**: tenant creato alla registrazione (signup self-service funzionante), form edit post riparato (campo status mancante → salvataggio falliva sempre; select per piattaforma/formato; hashtag e CTA editabili), caption manuale non più persa, `default_goal` 120→500, poller senza reload continui (aggiorna i badge sul posto, 1 reload a fine generazione), Brand in navbar.
- ✅ **Wizard a 1 step**: solo nome/date/post-a-settimana/goal; tono+piattaforme+formati dal profilo (chips di riepilogo); generazione parte al submit; done page = pagina di avanzamento con barra X/N e "Rigenera piano". Label post/settimana corretta con stima live del totale.
- ✅ **Leve qualità prompt**: scheda attività (services/target/cta/industry/notes) promossa a istruzioni; anti-ripetizione tra post fratelli (aperture già usate passate al writer); regole di copy per formato (reel/story/live/blog); giorno di pubblicazione in italiano nel contesto; effort ideazione dedicato (`OPENAI_IDEATION_EFFORT`, default medium); fallback image prompt basato su topic+servizi.
- ✅ **Migrazione GPT Image 2**: default `gpt-image-2` in config (env override possibile).
- ✅ **Onboarding solo-URL**: `SiteCrawler` (homepage + pagine rilevanti; driver Firecrawl auto-attivo con `FIRECRAWL_API_KEY`, fallback nativo) + estrazione canali social → `tenant_profiles.social_links` + job `BuildBrandProfileFromWebsite` che salva il profilo (solo campi vuoti, mai sovrascrive l'utente) con stato in cache e polling UI. La registrazione atterra su /brand.

Secondo giro del 20/08 (commit `23bf177`, `64ce738`, 52 test verdi):

- ✅ **Default contenuti dedotti in onboarding**: `default_tone` scelto dall'AI dal sito (enum dei 5 toni), `default_platforms` dai social effettivamente trovati, `default_formats` di conseguenza — sempre solo su campi vuoti.
- ✅ **Arricchimento Apify**: job `EnrichBrandFromSocials` (dietro `APIFY_TOKEN`, best-effort, auto-dispatched se trovato Instagram) — caption reali → `example_posts`, sintesi voce del brand → `brand_voice` (solo se vuoti).
- ✅ **Verifica email obbligatoria** (`MustVerifyEmail`); post-verifica → onboarding se manca il profilo.
- ✅ **Login con Google** (Socialite): nuovo utente = nuovo tenant owner con email verificata; account esistenti linkati per email; bottone visibile solo con `GOOGLE_CLIENT_ID` configurato.

## Prossimi passi rimasti (in ordine)

1. **Video Veo 3.1** come nuova feature reel (Gemini API, `predictLongRunning` + polling in coda).
2. **Layer template** per grafiche con testo (Parte 3): sessione Claude Design → template Blade + Browsershot.
3. Vista di revisione unificata (fondere /posts, /content-items, /calendar) + uso di `social_links` nella UI del profilo.
4. Chiavi da procurare: Firecrawl (JS rendering crawl), Apify (`APIFY_TOKEN`), Google OAuth (`GOOGLE_CLIENT_ID/SECRET`, redirect `https://.../auth/google/callback`). Mail: in produzione serve un mailer vero (ora `MAIL_MAILER=log`) per la verifica email.
5. **Deploy automatico**: webhook GitHub → endpoint sul server che fa `git pull` + `composer install` + `artisan migrate --force` + restart queue worker. L'utente ha chiesto anche di valutare hosting su Hetzner ("così lo vediamo sempre lì") — servono accessi SSH.
6. `composer audit` segnala 41 advisory su 14 pacchetti: pianificare un `composer update` controllato.
