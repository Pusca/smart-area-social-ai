<?php

namespace App\Support;

/**
 * Registro dei preset visivi selezionabili dal cliente.
 *
 * Ogni preset produce:
 *  - positive_modifiers : istruzioni da appendere al prompt immagine
 *  - negative_modifiers : vincoli da iniettare come "NON fare..." nel prompt
 *  - gpt_instruction    : istruzione per GPT quando genera l'image_prompt
 */
class VisualModePresets
{
    public const DEFAULT_MODE = 'real';

    /** @return array<string, array{label: string, description: string, positive_modifiers: string, negative_modifiers: string, gpt_instruction: string}> */
    public static function all(): array
    {
        return [
            'minimal' => [
                'label'       => 'Minimal',
                'description' => 'Spazio bianco dominante, un soggetto solo, geometria pulita, palette neutra. Look editoriale e contemporaneo.',
                'positive_modifiers' =>
                    'Stile fotografico minimal: sfondo neutro o bianco, spazio negativo ampio, un unico soggetto principale al centro, '
                    . 'composizione geometrica pulita, luce naturale e morbida, palette cromatica limitata a 2-3 colori. '
                    . 'Look editoriale contemporaneo, nessun elemento decorativo superfluo, silenzio visivo intenzionale.',
                'negative_modifiers' =>
                    'NON usare: sfondi affollati, elementi multipli in competizione, colori saturi o violenti, '
                    . 'lighting drammatico, texture elaborate, decorazioni eccessive, composizioni caotiche.',
                'gpt_instruction' =>
                    "Il visual_mode e 'minimal': l'image_prompt deve descrivere un visual con sfondo neutro/bianco, un soggetto principale centrato, "
                    . 'composizione pulita e spazio negativo ampio. Palette limitata, nessun elemento decorativo. Look editoriale sobrio.',
            ],

            'real' => [
                'label'       => 'Real',
                'description' => 'Fotografia autentica e candid, ambienti veri, luce naturale. Look documentaristico, senza post-produzione pesante.',
                'positive_modifiers' =>
                    'Stile fotografico autentico e candid: ambienti reali non allestiti, persone in momenti naturali e spontanei, '
                    . 'luce naturale disponibile, post-produzione leggera, grana fotografica accettabile, '
                    . 'composizione imperfetta e vitale. Look documentaristico, reportage visivo, nessun set artificiale.',
                'negative_modifiers' =>
                    'NON usare: set fotografici artificiali, lighting da studio con softbox evidenti, sfondi infiniti, '
                    . 'pose rigide o costruite, ritocco eccessivo, skin airbrushed, look pubblicitario patinato.',
                'gpt_instruction' =>
                    "Il visual_mode e 'real': l'image_prompt deve descrivere un momento autentico e spontaneo in un ambiente reale, "
                    . 'con luce naturale e composizione candid. Evitare set artificiali, pose costruite e post-produzione evidente.',
            ],

            'premium' => [
                'label'       => 'Premium',
                'description' => 'Illuminazione da studio drammatica, materiali di lusso, composizione elaborata. Look high-end e aspirazionale.',
                'positive_modifiers' =>
                    'Stile fotografico premium e luxury: illuminazione da studio professionale con qualita cinematografica, '
                    . 'materiali di pregio (tessuti morbidi, superfici lucide, metalli, legno nobile), '
                    . 'composizione elaborata e curata nel dettaglio, post-produzione ricca, colori profondi e saturi con precisione, '
                    . 'depth of field cinematografico, soggetto principale enfatizzato con luci di rimbalzo. '
                    . 'Look aspirazionale, lusso accessibile, brand premium.',
                'negative_modifiers' =>
                    'NON usare: ambienti ordinari, lighting piatto, prodotti non curati, composizioni approssimative, '
                    . 'colori desaturati, look low-budget o amatoriale.',
                'gpt_instruction' =>
                    "Il visual_mode e 'premium': l'image_prompt deve descrivere un visual con illuminazione cinematografica, "
                    . 'materiali di qualita, composizione curata, depth of field professionale. Look luxury e aspirazionale.',
            ],

            'bold' => [
                'label'       => 'Bold',
                'description' => 'Contrasto forte, colori saturi, ritmo visivo energico. Soggetto di impatto, stop-scroll immediato.',
                'positive_modifiers' =>
                    'Stile fotografico bold e ad alto impatto: contrasto cromatico estremo, colori saturi e vibranti, '
                    . 'composizione dinamica con diagonali e tensione visiva, soggetto principale dominante e potente, '
                    . 'energia visiva immediata, lighting drammatico con ombre nette, '
                    . 'stop-scroll garantito, ritmo visivo rapido e urgente.',
                'negative_modifiers' =>
                    'NON usare: composizioni morbide o timide, colori pastello o desaturati, sfondo bianco piatto, '
                    . 'soggetti piccoli o decentrati senza motivo, lighting piatto uniforme.',
                'gpt_instruction' =>
                    "Il visual_mode e 'bold': l'image_prompt deve descrivere un visual con contrasto forte, colori saturi, "
                    . 'composizione dinamica ad alto impatto e soggetto dominante. Energia visiva immediata.',
            ],

            'brand_content' => [
                'label'       => 'Brand Content',
                'description' => 'Asset del brand protagonisti, prodotti in primo piano, composizione brand-adjacent. Fedele all\'identita aziendale.',
                'positive_modifiers' =>
                    'Stile fotografico brand-centrico: prodotti o servizi del brand in primo piano come protagonisti assoluti, '
                    . 'palette cromatica del brand rispettata con precisione, composizione che esalta l\'identita aziendale, '
                    . 'elementi brandizzati coerenti (materiali, colori, atmosfera), '
                    . 'look coerente con la comunicazione corporate del brand.',
                'negative_modifiers' =>
                    'NON usare: stock generico non collegato al brand, palette estranee all\'identita aziendale, '
                    . 'composizioni che ignorano i prodotti/servizi del brand, elementi visivi in contrasto con lo stile aziendale.',
                'gpt_instruction' =>
                    "Il visual_mode e 'brand_content': l'image_prompt deve mettere prodotti o servizi del brand come protagonisti assoluti, "
                    . "rispettando palette e identita aziendale. Composizione brand-centrica, nessuno stock generico.",
            ],
        ];
    }

    public static function get(string $mode): ?array
    {
        return self::all()[$mode] ?? null;
    }

    public static function getOrDefault(string $mode): array
    {
        return self::all()[$mode] ?? self::all()[self::DEFAULT_MODE];
    }

    /** @return array<string, string> */
    public static function labelsForSelect(): array
    {
        return array_map(fn ($p) => $p['label'], self::all());
    }
}
