# Persona Pack V1

Ultimo aggiornamento: 2026-03-11

## Obiettivo

Introdurre nel Brand Center una creazione guidata di asset persona, pensata per dare all AI un riferimento piu stabile e realistico per immagini e video futuri.

## Scelta architetturale

In V1 non usiamo Sora per "inventare" la persona di base.

La persona nasce da materiale reale del cliente:
- 4 scatti richiesti
  - frontale
  - tre quarti sinistra
  - tre quarti destra
  - profilo
- 1 scatto mezzo busto opzionale
- 1 video reale opzionale

Questa scelta riduce drift identitario tra i contenuti e rende il sistema piu vendibile per casi reali.

## Cosa salva il sistema

### Brand assets

Ogni file del persona pack viene salvato in `brand_assets` con:
- `kind = image` o `kind = video`
- `meta.source = guided_persona_pack`
- `meta.slot`
- `meta.slot_label`
- `meta.linked_variable_id`

### Asset variable

La variabile persona usa ora anche `profile` JSON con:
- `source_mode`
- `role`
- `identity_summary`
- `immutable_traits`
- `look_notes`
- `styling_notes`
- `prompt_notes`
- `usage_notes`
- `shot_summary`
- `preferred_still_asset_id`
- `reference_video_asset_id`
- `reference_video_path`
- `recommended_prompt`

## Effetto sulla pipeline AI

La pipeline legge ora il persona pack come contesto forte:
- `OpenAiService` riceve istruzioni esplicite per preservare identita e tratti costanti
- `GenerateAiForContentItem` porta `profile` dentro il contesto normalizzato delle variabili
- se esiste un video legato alla persona selezionata, il job prova a preferirlo come riferimento video del brand rispetto a un video generico tenant-level

## Limiti attuali

- V1 non estrae automaticamente frame dal video
- V1 non costruisce ancora embedding o identity matching
- V1 usa il video soprattutto come riferimento strutturato e gancio per gli step successivi

## Step successivi consigliati

1. Estrarre frame automatici dal video reale per completare o rinforzare il pack.
2. Aggiungere una validazione guidata di qualita foto/video prima del salvataggio.
3. Far emergere nel contenuto generato quale persona pack e stato usato.
4. Valutare un flusso simile per `location pack` e `product pack`.
