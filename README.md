# Smart Area Social AI

Applicazione Laravel 12 multi-tenant per pianificazione editoriale, generazione AI di contenuti social e publishing Meta.

## Stack

- PHP 8.2+, Laravel 12, Breeze, Tailwind, Vite
- Queue/job Laravel con driver database
- OpenAI, NanoBanana, Runway, Kling
- Meta publishing, Web Push, PWA shell

## Flusso prodotto

1. Auth e tenant
   - Gli utenti lavorano sempre dentro un tenant.
   - `EnsureUserHasTenant` protegge tutta l'area applicativa.
   - `/profile` resta fuori dal middleware tenant per la gestione account.
2. Brand Center
   - Route principale: `/profile/brand`.
   - Modello centrale: `TenantProfile`.
   - Qui si gestiscono brand assets, variabili asset e quickstart onboarding.
3. Quickstart demo
   - `QuickstartOnboardingService` raccoglie i dati minimi brand.
   - Genera una demo iniziale da 7 giorni con 3 contenuti.
   - La demo puo essere salvata nel workspace, rigenerata o eliminata.
4. Wizard editoriale
   - Route principali: `/wizard`, `/wizard/done`, `/plans/{plan}/generating`.
   - Usa `EditorialStrategyService`, `EditorialPlanBuilder` e `ContentGenerator`.
   - Crea un `ContentPlan`, costruisce i `ContentItem` e li mette in coda per la generazione AI.
5. Creazione contenuti singoli
   - Route principali: `/posts/create` e `/posts/reels/create`.
   - Permette brief manuale, scelta provider, immagini di riferimento e variabili asset.
   - I contenuti singoli usano la stessa pipeline AI del wizard, ma con maggiore controllo manuale.
6. Generazione AI
   - Job centrale: `GenerateAiForContentItem`.
   - Genera copy, CTA, hashtag, prompt visuale, immagine o video.
   - Integra strategia editoriale, memoria tenant, feedback loop e asset reali.
7. Review e publishing
   - Dashboard, libreria post e calendario leggono i `ContentItem`.
   - `SocialPublishingService` sincronizza le pubblicazioni pianificate verso Meta.
   - La pubblicazione effettiva passa dalla tabella `social_publications` e dai job dedicati.

## Mappa del codice

- `app/Http/Controllers/TenantProfileController.php`: Brand Center e quickstart.
- `app/Http/Controllers/PlanWizardController.php`: wizard editoriale e progress generation.
- `app/Http/Controllers/ContentItemController.php`: contenuti singoli, edit, libreria e generazione on demand.
- `app/Jobs/GenerateAiForContentItem.php`: pipeline AI principale.
- `app/Services/Editorial/*`: strategia, piano editoriale, anti-duplicati e generazione blueprint.
- `app/Services/Onboarding/QuickstartOnboardingService.php`: onboarding demo iniziale.
- `app/Services/Social/SocialPublishingService.php`: sincronizzazione pubblicazioni Meta.
- `routes/web.php`: mappa principale delle route applicative.
- `docs/current-state-handoff.md`: stato corrente e decisioni di prodotto.

## Modelli chiave

- `Tenant`, `User`
- `TenantProfile`
- `BrandAsset`, `AssetVariable`
- `ContentPlan`, `ContentItem`
- `SocialAccount`, `SocialPublication`
- `ContentFeedbackEntry`

## Avvio locale

Setup iniziale:

```bash
composer setup
```

Sviluppo locale:

```bash
composer dev
```

Test:

```bash
php artisan test
```

## Note operative

- In locale `GenerationExecution` gira in sync per evitare dipendenza da un worker persistente.
- In ambienti non locali la generazione passa per queue o `afterResponse`, a seconda della configurazione.
- Il default globale per le immagini e `nanobanana`; `openai` resta sbloccato per i contenuti singoli manuali.
- La repo contiene gia il flusso Meta publishing, ma l'UX end-to-end e ancora in evoluzione.
