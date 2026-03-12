# Current State Handoff

Ultimo aggiornamento: 2026-03-11

## Stato raggiunto

- App Laravel multi-tenant con auth, dashboard, calendario, wizard piano editoriale, gestione post e pipeline AI.
- Base Meta publishing gia aggiunta:
  - collegamento account Facebook Page / Instagram Business
  - sync social accounts da Settings
  - coda `social_publications`
  - job/command per pubblicazione programmata
- Fix gia fatti in precedenza:
  - autorizzazioni tenant sulle rotte AI
  - `/profile` fuori dal middleware tenant
  - correzione calcoli date nei builder/dashboard
- Onboarding quickstart gia attivo dopo registrazione:
  - raccolta dati minimi brand
  - salvataggio asset iniziali / variabile rapida
  - demo automatica da 7 giorni / 3 contenuti
  - dopo la generazione la demo puo essere salvata nel workspace, rigenerata o eliminata
  - se l utente salva o elimina, il blocco quickstart viene chiuso e non viene piu riproposto in Brand Center
  - quando il quickstart scompare, il Brand Center mostra un invito discreto a completare i campi brand e gli asset mancanti per aiutare meglio strategia e contenuti AI
- Base notifiche evento presente ma inbox rimossa dalla UI:
  - tabella `notifications`
  - trigger su generazione AI, publish Meta e connessioni social
  - route/pagina inbox non piu esposte in navigazione
  - da riusare come sorgente per vere push browser/app
- UX attesa generazione migliorata:
  - overlay animato su create/edit/wizard generate
  - stima tempi e stato macchina in lavorazione
  - overlay esteso anche al quickstart brand: creazione e rigenerazione demo iniziale ora mostrano la stessa attesa guidata
  - online il flag `GENERATION_FORCE_SYNC` non blocca piu la request fino al timeout:
    - i job partono `after response`
    - quickstart e rigenerazioni contenuto usano ora pagine di avanzamento dedicate
    - l utente resta su una schermata di attesa reale e viene riportato alla sezione corretta appena la generazione finisce
- Feedback loop tenant-aware ora attivo:
  - tabella `content_feedback_entries`
  - feedback positivo/negativo direttamente dentro il dettaglio contenuto
  - motivo obbligatorio sui feedback negativi
  - opzione `salva e rigenera` con obiezione passata come vincolo forte alla rigenerazione
  - memoria feedback agganciata a `MemoryBuilderService`, quindi valida anche per generazioni future singole e di massa
  - il wizard ora riceve anche `preferred_formats`, `preferred_platforms`, `positive_signals` e `hard_avoid_rules` gia in fase di costruzione del piano
- Provider immagini riallineato:
  - default globale resta `nanobanana`
  - scelta `openai` riattivata solo per contenuti singoli manuali
  - flussi wizard/demo continuano forzati sul default
  - prompt rafforzati per preservare location reali (ufficio, edificio, showroom, negozio) quando usate come riferimento
- Provider video rinforzato:
  - se `Runway` fallisce con errore generico provider-side, il job prova fallback automatico su `OpenAI video`
  - resta salvato sia il provider richiesto sia quello effettivamente usato nell ultima generazione
  - gli errori `Runway` ora riportano dettaglio piu leggibile quando presente (`code + message`)
- Persona pack V1 nel Brand Center:
  - nuova creazione guidata per variabili `person`
  - supporta 4 scatti chiave, 1 mezzo busto opzionale e 1 video reale opzionale
  - salva metadati strutturati su `asset_variables.profile` e `brand_assets.meta`
  - la pipeline AI legge ora questi campi per preservare meglio identita, volto e tratti della persona nei contenuti futuri
  - documentazione dedicata in `docs/persona-pack-v1.md`
- Pipeline video/copy migliorata:
  - `video_prompt` e `video_voiceover` ora sono separati dal prompt immagine
  - per video con piu location reali il sistema prova a mostrarle in sequenza, senza fonderle in una scena impossibile
  - copy social irrigidito contro linguaggio da consulenza, KPI inventati, percentuali o risultati non presenti nel contesto
  - angoli e `key_points` del wizard sono stati riallineati a logiche piu social e meno "business deck"
- Feedback UI resa piu smart:
  - pollice su diretto e persistente
  - pollice giu con modale per obiezione + opzione rigenerazione
- Quickstart corretto:
  - demo iniziale ora tratta Instagram e Facebook come canali distinti, non come stringa unica
  - aggiunto stato persistente `quickstart_dismissed_at` per distinguere demo attiva da quickstart chiuso
- Brand system riallineato ai nuovi asset:
  - `logo-socialai.png` e `icona-socialai.png` copiati in `public/brand`
  - componenti logo aggiornati per usare sempre gli asset veri, non placeholder testuali
  - palette globale ora derivata dal logo Social AI
  - layout guest/app/admin, homepage, auth, dashboard e wizard start riallineati a una UX piu chiara e meno tecnica
  - shell app ulteriormente semplificata:
    - header desktop con solo logo, navigazione essenziale e accesso account
    - header mobile leggero con logo + shortcut rapidi
    - bottom nav mobile ridotta a 4 voci
  - nuove versioni logo/icona sincronizzate da `app/` a `public/brand`
  - ridotti i CTA neri residui nelle aree operative principali
  - aggiunta protezione `overflow-x-hidden` nella shell e nella dashboard per evitare scroll orizzontale su smartphone
  - pagina `Crea` ora separa in modo esplicito:
    - contenuto singolo on demand
    - modalita `Crea reel` con preset dedicato e Runway preimpostato
    - piano editoriale / insieme di contenuti
  - la sezione contenuti ora espone una CTA diretta `Crea reel`
  - il prompt `Runway` per i reel e ora piu reel-first:
    - hook iniziale entro il primo secondo
    - progressione in 3-5 shot concatenati
    - payoff visivo finale coerente con brand, strategia e obiettivo del contenuto
  - il piano editoriale ora richiede minimo `2` contenuti anche lato validazione/controller, non solo lato copy
  - header desktop con logo piu grande e copy pre-login riscritto in ottica benefici/possibilita, meno descrittivo-tecnico
  - landing ulteriormente ripulita dal linguaggio "prova" nelle CTA principali, con messaggi piu orientati al valore percepito

## Decisioni architetturali correnti

- Non creare un secondo wizard separato per il brand.
- Riutilizzare `TenantProfile`, `EditorialStrategyService`, `EditorialPlanBuilder` e `ContentGenerator`.
- Il primo flusso utente diventa:
  1. registrazione
  2. quickstart brand con pochi campi
  3. generazione automatica demo da 7 giorni / 3 contenuti
  4. rifinitura opzionale del profilo completo
- La pagina Brand deve avere due livelli:
  - quickstart in alto per il primo impatto
  - controlli completi sotto, senza perdere logiche esistenti

## Obiettivo del quickstart

- Chiedere solo i dati minimi per una demo credibile:
  - nome attivita
  - settore
  - cosa vende / servizi
  - target
  - CTA principale facoltativa
  - logo facoltativo
  - almeno 1 immagine reale di riferimento
  - variabile asset rapida facoltativa
- Generare subito un piano demo:
  - durata: 7 giorni
  - contenuti: 3
  - mix: 2 post immagine + 1 reel
  - piattaforme: Instagram + Facebook
- La demo va chiaramente presentata come prova rigenerabile o eliminabile.

## File chiave gia coinvolti

- `app/Http/Controllers/Auth/RegisteredUserController.php`
- `app/Http/Controllers/TenantProfileController.php`
- `app/Http/Controllers/ContentFeedbackController.php`
- `resources/views/wizard/brand.blade.php`
- `resources/views/posts/edit.blade.php`
- `resources/views/partials/generation-loader.blade.php`
- `app/Services/Editorial/EditorialPlanBuilder.php`
- `app/Services/Editorial/ContentGenerator.php`
- `app/Services/Editorial/EditorialStrategyService.php`
- `app/Services/Feedback/TenantFeedbackMemoryService.php`
- `app/Services/Notification/WorkspaceNotificationService.php`
- `app/Services/Social/SocialPublishingService.php`
- `app/Jobs/GenerateAiForContentItem.php`
- `app/Jobs/PublishSocialPublication.php`
- `routes/web.php`

## Nota tecnica importante

- `Tenant` aveva `fillable` incompleto rispetto alla registrazione (`plan`, `is_active`), quindi il setup iniziale tenant va allineato nel refactor onboarding.

## Dopo il quickstart

Argomenti da definire insieme nel passo successivo:

- set minimo e set avanzato dei campi brand da dare all AI
- logica abbonamenti/quote ricorrenti
- workflow publish Meta end-to-end con UX completa
- retry/log/manual publish nel calendario
- notifiche push browser da costruire sopra la base eventi gia presente
- dashboard o report dedicato per leggere i pattern di feedback del tenant

## Nota recente: video AI e moderation fallback

- I reel con `persona pack` reale o contesti wellness/beauty possono essere bloccati da OpenAI video per moderation.
- Il job video ora deve:
  - fare un retry con prompt piu sobrio e sicuro
  - declassare nomi propri e linguaggio sensibile tipo `massaggio tecnico` verso formulazioni piu professionali
  - applicare questo guard rail gia prima della chiamata a OpenAI nei casi `persona reale + wellness/beauty`
  - filtrare i riferimenti visuali in modo che nelle image references non entrino file video del persona pack
  - usare il feedback negativo video come correzione forte: la rigenerazione deve cambiare davvero regia, ordine scene e apertura, non solo fare micro-varianti
  - usare il persona pack come board identitaria multi-angolo quando c e un solo soggetto persona, evitando di fonderlo in una scena-lock innaturale
  - non cambiare provider automaticamente se l utente ha scelto `OpenAI`: dopo il retry sicuro resta su OpenAI e, se ancora bloccato, fallisce in modo esplicito
- File chiave:
  - `app/Jobs/GenerateAiForContentItem.php`
  - `app/Services/OpenAiService.php`
  - `tests/Unit/GenerateAiForContentItemTest.php`
