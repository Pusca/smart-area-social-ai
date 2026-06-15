# Kling Provider V1

Ultimo aggiornamento: 2026-03-12

## Obiettivo

Integrare `Kling` come provider video aggiuntivo, senza sostituire `OpenAI` o `Runway`, con focus iniziale su:

- coerenza della persona reale nei reel
- uso corretto del `persona pack`
- tracciabilita di cio che viene inviato alle API

## Scelta architetturale

- `Runway` resta il preset piu rapido per la modalita reel.
- `Kling` entra come opzione selezionabile nei contenuti singoli manuali.
- `OpenAI` resta disponibile come provider alternativo.
- Non viene fatto nessun fallback silenzioso verso `Kling`.

## Come viene usato Kling

La pipeline sceglie tra tre modalita:

1. `text`
   - nessun riferimento visuale affidabile
   - solo prompt strategico + vincoli brand

2. `image`
   - un solo riferimento davvero utile
   - tipico caso location anchor o singola immagine brand

3. `multi-image`
   - fino a 4 riferimenti separati
   - usata soprattutto quando esiste un solo `persona pack`
   - le immagini non vengono fuse in collage: sono trattate come lo stesso soggetto ripreso da angolazioni diverse

## Board persona

Quando c e una sola variabile `person` risolta:

- i riferimenti vengono ordinati per priorita:
  - front
  - three_quarter_left
  - three_quarter_right
  - half_body
  - profile
- a Kling viene passato un board identitario, non una scena-lock artificiale
- il prompt ribadisce:
  - stessa persona reale
  - stessi lineamenti
  - stessa eta percepita
  - stessi capelli e presenza
  - niente identity drift tra gli shot

## Informazioni inviate alle API

Ogni job salva in `ai_meta.video_generation`:

- `provider`
- `request_summary`
- `reference_input_summary`

Questo serve per capire se il video e stato generato con:

- prompt solo testo
- una sola reference
- board multi-reference

e se i riferimenti sono stati passati come:

- `public_url`
- `data_uri`

## Note API

Campi usati lato create:

- `model_name`
- `mode`
- `prompt`
- `duration`
- `aspect_ratio`
- `cfg_scale`
- `negative_prompt`
- `external_task_id`

Campi reference:

- `image`
- `image_list[].image`

## Perimetro V1

Gia coperto:

- auth JWT `AccessKey/SecretKey`
- create task text/image/multi-image
- query task
- download video
- thumbnail best effort
- prompt e negative prompt Kling-specifici
- board persona multi-angolo

Non ancora coperto:

- `Build Avatar`
- `advanced-custom-elements`
- lip sync
- native audio Kling
- training o persistenza soggetto lato provider

Per ora l audio continua a essere meglio gestito come passaggio separato dopo la generazione video.
