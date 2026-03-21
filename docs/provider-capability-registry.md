# Provider Capability Registry

`App\Services\AI\ProviderCapabilityRegistry` centralizza capability e limiti runtime dei provider AI.

## Obiettivo

Ridurre logica duplicata tra resolver, matrix e job, mantenendo `config/generation.php` come sorgente dei default applicativi.

## Sorgenti dati

- `config/generation.php`: provider default e provider allowed per area
- `config/provider_capabilities.php`: capability, fallback, modelli, durate, timeout, ratio/size
- `config/openai.php`, `config/runway.php`, `config/kling.php`, `config/nanobanana.php`, `config/elevenlabs.php`: model default e timeout reali

## Aree coperte

- `text`
- `grader`
- `alignment` come alias di `grader`
- `image`
- `video`
- `speech`
- `voice_clone`

## API principali

- `allowedProviders($area)`
- `defaultProvider($area)`
- `resolveProvider($area, $preferred, $fallback)`
- `resolveConfiguredProvider($area, $preferred, $fallback)`
- `supportsArea($provider, $area)`
- `isConfigured($provider, $area)`
- `defaultModel($provider, $area, $context = [])`
- `normalizeModel($provider, $area, $model = '', $context = [])`
- `normalizeVideoDuration($provider, $seconds, $model = '', $context = [])`
- `maxVideoDuration($provider, $model = '', $context = [])`
- `fallbackProviders($provider, $area, $locked = false)`
- `capabilityMismatch($provider, $area, $requirements = [])`
- `summary($provider, $area, $context = [])`

## Integrazione attuale

Usato da:

- resolver `Text/Image/Video/Speech/GraderProviderResolver`
- `App\Services\AI\AiProviderMatrixService`
- `App\Jobs\GenerateAiForContentItem`

## Note pratiche

- il job continua a contenere orchestrazione e policy runtime, ma non deve piu conoscere durate e modelli supportati in modo sparso
- per `Kling` il registry lascia il model vuoto quando serve scelta endpoint-aware a runtime
- per `Runway` il registry gestisce alias modello e vincoli di durata `gen4.5` vs `veo3*`
- per `OpenAI` il registry normalizza il video su `sora-2*` e durate `4/8/12`