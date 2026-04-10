<?php

namespace App\Services\Editorial;

/**
 * Fornisce preset strategici preconfigurati per industry verticali.
 *
 * Ogni preset definisce il punto di partenza ottimale per:
 *   - pillars (temi editoriali)
 *   - rubrics (distribuzione contenuti)
 *   - cta_rules (CTA preferite per il settore)
 *   - brand_voice (tono e valori di default)
 *   - analysis_framework (obiettivi e target)
 *
 * I preset vengono applicati come `$overrides` in EditorialStrategyService::refreshForTenant()
 * quando il tenant non ha ancora una strategia personalizzata.
 * L'utente può sempre sovrascrivere manualmente qualsiasi campo.
 *
 * Per aggiungere una nuova industry: aggiungere una entry in PRESETS
 * e mappare alias in normalizeIndustry().
 */
class IndustryPresetService
{
    /**
     * Mappa di normalizzazione: alias → chiave canonica.
     * Permette di matchare "ristorante", "food", "ristorazione" → "food".
     *
     * @var array<string, string>
     */
    private const INDUSTRY_ALIASES = [
        // Food & Restaurant
        'food'              => 'food',
        'ristorante'        => 'food',
        'ristorazione'      => 'food',
        'bar'               => 'food',
        'caffè'             => 'food',
        'caffe'             => 'food',
        'pasticceria'       => 'food',
        'pizzeria'          => 'food',
        'food & beverage'   => 'food',
        'cucina'            => 'food',
        'chef'              => 'food',

        // Healthcare
        'healthcare'        => 'healthcare',
        'salute'            => 'healthcare',
        'medico'            => 'healthcare',
        'clinica'           => 'healthcare',
        'studio medico'     => 'healthcare',
        'dentista'          => 'healthcare',
        'fisioterapia'      => 'healthcare',
        'wellness'          => 'healthcare',
        'benessere'         => 'healthcare',
        'farmacia'          => 'healthcare',
        'psicologia'        => 'healthcare',

        // E-commerce & Retail
        'ecommerce'         => 'ecommerce',
        'e-commerce'        => 'ecommerce',
        'retail'            => 'ecommerce',
        'moda'              => 'ecommerce',
        'fashion'           => 'ecommerce',
        'abbigliamento'     => 'ecommerce',
        'negozio'           => 'ecommerce',
        'shop'              => 'ecommerce',
        'arredamento'       => 'ecommerce',
        'beauty'            => 'ecommerce',
        'cosmetici'         => 'ecommerce',

        // Education & Infoproducts
        'education'         => 'education',
        'formazione'        => 'education',
        'coaching'          => 'education',
        'consulenza'        => 'education',
        'corso online'      => 'education',
        'infoprodotti'      => 'education',
        'training'          => 'education',
        'istruzione'        => 'education',
        'scuola'            => 'education',
        'academy'           => 'education',

        // Professional Services
        'professional'      => 'professional',
        'agenzia'           => 'professional',
        'studio'            => 'professional',
        'avvocato'          => 'professional',
        'commercialista'    => 'professional',
        'architetto'        => 'professional',
        'ingegnere'         => 'professional',
        'marketing'         => 'professional',
        'comunicazione'     => 'professional',
        'tecnologia'        => 'professional',
        'software'          => 'professional',
        'it'                => 'professional',
    ];

    /**
     * Preset completi per ogni vertical.
     *
     * @var array<string, array<string, mixed>>
     */
    private const PRESETS = [

        // ─── FOOD & RESTAURANT ──────────────────────────────────────────────
        'food' => [
            'pillars' => [
                'Il Piatto del Giorno',
                'Dietro le Quinte della Cucina',
                'Storia degli Ingredienti',
                'L\'Esperienza dei Clienti',
                'Stagionalità e Territorio',
                'Il Team e la Passione',
            ],
            'rubrics' => [
                ['name' => 'Appetizing Visual',   'weight' => 0.35, 'focus' => 'immagini e video del cibo'],
                ['name' => 'Storia e Cultura',     'weight' => 0.20, 'focus' => 'origine e tradizione dei piatti'],
                ['name' => 'Social Proof',         'weight' => 0.20, 'focus' => 'recensioni e momenti dei clienti'],
                ['name' => 'Dietro le Quinte',     'weight' => 0.15, 'focus' => 'cucina, team, preparazioni'],
                ['name' => 'Promo & Offerte',      'weight' => 0.10, 'focus' => 'eventi, menù speciali, prenotazioni'],
            ],
            'cta_rules' => [
                'primary_cta'   => 'Prenota il tuo tavolo',
                'secondary_cta' => 'Vieni a trovarci',
                'link_cta'      => 'Scopri il menù completo nel link in bio',
                'avoid'         => ['acquista ora', 'clicca qui'],
            ],
            'brand_voice' => [
                'tone'   => 'caldo, autentico, conviviale',
                'values' => ['qualità', 'territorio', 'accoglienza', 'passione'],
            ],
            'analysis_framework' => [
                'primary_goal'    => 'Drive to Store + Brand Awareness',
                'secondary_goal'  => 'Community Building',
                'content_mix'     => ['visual' => 0.5, 'story' => 0.3, 'promo' => 0.2],
            ],
        ],

        // ─── HEALTHCARE ─────────────────────────────────────────────────────
        'healthcare' => [
            'pillars' => [
                'Educazione alla Salute',
                'Prevenzione e Benessere',
                'Il Nostro Team di Specialisti',
                'Storie di Pazienti (anonimizzate)',
                'Novità e Tecnologie',
                'Consigli Pratici Quotidiani',
            ],
            'rubrics' => [
                ['name' => 'Educativo',       'weight' => 0.40, 'focus' => 'informazione medica accessibile'],
                ['name' => 'Autorevolezza',   'weight' => 0.25, 'focus' => 'expertise del team, pubblicazioni'],
                ['name' => 'Fiducia',         'weight' => 0.20, 'focus' => 'casi di successo, testimonianze'],
                ['name' => 'Prevenzione',     'weight' => 0.15, 'focus' => 'screening, check-up, stile di vita'],
            ],
            'cta_rules' => [
                'primary_cta'   => 'Prenota una consulenza',
                'secondary_cta' => 'Scopri i nostri servizi',
                'link_cta'      => 'Tutte le informazioni nel link in bio',
                'avoid'         => ['compra', 'offerta', 'sconto', 'guarisci subito'],
                'compliance_note' => 'Evitare claim terapeutici non documentati.',
            ],
            'brand_voice' => [
                'tone'   => 'professionale, empatico, rassicurante',
                'values' => ['salute', 'cura', 'professionalità', 'fiducia'],
            ],
            'analysis_framework' => [
                'primary_goal'   => 'Trust Building + Lead Generation',
                'secondary_goal' => 'Education',
                'content_mix'    => ['educational' => 0.5, 'authority' => 0.3, 'social_proof' => 0.2],
            ],
        ],

        // ─── E-COMMERCE & RETAIL ────────────────────────────────────────────
        'ecommerce' => [
            'pillars' => [
                'Prodotto in Spotlight',
                'Come si Usa / Tutorial',
                'Unboxing e Recensioni',
                'Dietro al Brand',
                'Outfit & Styling',
                'Lanci e Novità',
            ],
            'rubrics' => [
                ['name' => 'Product Showcase', 'weight' => 0.35, 'focus' => 'prodotto al centro, visual curato'],
                ['name' => 'How-to & Uso',     'weight' => 0.25, 'focus' => 'tutorial, casi d\'uso, valore'],
                ['name' => 'Social Proof',     'weight' => 0.20, 'focus' => 'UGC, recensioni, unboxing'],
                ['name' => 'Brand Story',      'weight' => 0.10, 'focus' => 'valori, origine, team'],
                ['name' => 'Promo',            'weight' => 0.10, 'focus' => 'lanci, drop, offerte speciali'],
            ],
            'cta_rules' => [
                'primary_cta'   => 'Acquista ora',
                'secondary_cta' => 'Scopri la collezione',
                'link_cta'      => 'Link diretto nel bio',
                'avoid'         => ['offerta shock', 'prezzi stracciati'],
            ],
            'brand_voice' => [
                'tone'   => 'aspirazionale, diretto, moderno',
                'values' => ['stile', 'qualità', 'innovazione', 'lifestyle'],
            ],
            'analysis_framework' => [
                'primary_goal'   => 'Conversione + Awareness',
                'secondary_goal' => 'Retention e Repeat Purchase',
                'content_mix'    => ['product' => 0.4, 'educational' => 0.3, 'social_proof' => 0.3],
            ],
        ],

        // ─── EDUCATION & INFOPRODUCTS ────────────────────────────────────────
        'education' => [
            'pillars' => [
                'Pillole di Conoscenza',
                'Il Metodo e l\'Approccio',
                'Storie di Trasformazione',
                'FAQ e Dubbi Comuni',
                'Dietro le Quinte del Percorso',
                'Risultati Concreti degli Allievi',
            ],
            'rubrics' => [
                ['name' => 'Valore Gratuito',   'weight' => 0.35, 'focus' => 'tips, mini-lezioni, insights'],
                ['name' => 'Autorità',           'weight' => 0.25, 'focus' => 'expertise, risultati, metodo'],
                ['name' => 'Social Proof',       'weight' => 0.20, 'focus' => 'alumni, testimonianze, case study'],
                ['name' => 'Conversione',        'weight' => 0.20, 'focus' => 'iscrizioni, consulenza gratuita'],
            ],
            'cta_rules' => [
                'primary_cta'   => 'Scopri il programma completo',
                'secondary_cta' => 'Prenota una sessione gratuita',
                'link_cta'      => 'Tutti i dettagli nel link in bio',
                'avoid'         => ['impara in 24 ore', 'successo garantito', 'risultati immediati'],
            ],
            'brand_voice' => [
                'tone'   => 'autorevole, incoraggiante, concreto',
                'values' => ['crescita', 'metodo', 'risultati', 'comunità'],
            ],
            'analysis_framework' => [
                'primary_goal'   => 'Authority Building + Lead Generation',
                'secondary_goal' => 'Community Growth',
                'content_mix'    => ['educational' => 0.4, 'authority' => 0.3, 'social_proof' => 0.3],
            ],
        ],

        // ─── PROFESSIONAL SERVICES ───────────────────────────────────────────
        'professional' => [
            'pillars' => [
                'Insight di Settore',
                'Case Study e Risultati',
                'Il Nostro Approccio',
                'FAQ dei Clienti',
                'News e Trend del Settore',
                'Il Team e i Valori',
            ],
            'rubrics' => [
                ['name' => 'Thought Leadership', 'weight' => 0.35, 'focus' => 'opinioni, analisi, trend'],
                ['name' => 'Case Study',         'weight' => 0.25, 'focus' => 'progetti, risultati misurabili'],
                ['name' => 'Expertise',          'weight' => 0.20, 'focus' => 'metodologia, processi, strumenti'],
                ['name' => 'Team & Cultura',     'weight' => 0.20, 'focus' => 'persone, valori, dietro le quinte'],
            ],
            'cta_rules' => [
                'primary_cta'   => 'Parlaci del tuo progetto',
                'secondary_cta' => 'Scopri come lavoriamo',
                'link_cta'      => 'Contattaci dal link in bio',
                'avoid'         => ['offerta', 'sconto', 'clicca ora'],
            ],
            'brand_voice' => [
                'tone'   => 'professionale, diretto, competente',
                'values' => ['expertise', 'risultati', 'trasparenza', 'partnership'],
            ],
            'analysis_framework' => [
                'primary_goal'   => 'Lead Generation + Authority',
                'secondary_goal' => 'Retention e Referral',
                'content_mix'    => ['thought_leadership' => 0.4, 'case_study' => 0.3, 'team' => 0.3],
            ],
        ],
    ];

    /**
     * Restituisce il preset per una industry, oppure null se non mappata.
     *
     * @return array<string, mixed>|null
     */
    public function getPresetForIndustry(string $industry): ?array
    {
        $key = $this->normalizeIndustry($industry);
        if ($key === null) {
            return null;
        }

        return self::PRESETS[$key] ?? null;
    }

    /**
     * Restituisce true se l'industry ha un preset disponibile.
     */
    public function hasPreset(string $industry): bool
    {
        return $this->normalizeIndustry($industry) !== null;
    }

    /**
     * Restituisce tutte le industry supportate (chiavi canoniche).
     *
     * @return array<int, string>
     */
    public function supportedIndustries(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * Normalizza un'industry string alla chiave canonica del preset.
     * Confronto case-insensitive e trim.
     */
    private function normalizeIndustry(string $industry): ?string
    {
        $normalized = strtolower(trim($industry));
        if ($normalized === '') {
            return null;
        }

        // Match diretto sulla chiave canonica
        if (isset(self::PRESETS[$normalized])) {
            return $normalized;
        }

        // Match tramite alias
        return self::INDUSTRY_ALIASES[$normalized] ?? null;
    }
}
