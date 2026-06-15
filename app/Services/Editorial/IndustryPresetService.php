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

        // Real Estate
        'realestate'        => 'realestate',
        'immobiliare'       => 'realestate',
        'agenzia immobiliare' => 'realestate',
        'agente immobiliare'  => 'realestate',
        'real estate'       => 'realestate',
        'compravendita'     => 'realestate',
        'affitti'           => 'realestate',

        // Tourism & Hospitality
        'tourism'           => 'tourism',
        'turismo'           => 'tourism',
        'hotel'             => 'tourism',
        'albergo'           => 'tourism',
        'b&b'               => 'tourism',
        'bed and breakfast' => 'tourism',
        'agriturismo'       => 'tourism',
        'travel'            => 'tourism',
        'viaggi'            => 'tourism',
        'hospitality'       => 'tourism',
        'ristrutturazione'  => 'tourism',
        'struttura ricettiva' => 'tourism',

        // Beauty & Wellness
        'beauty'            => 'beauty',
        'estetica'          => 'beauty',
        'parrucchiere'      => 'beauty',
        'barbiere'          => 'beauty',
        'nail art'          => 'beauty',
        'unghie'            => 'beauty',
        'make up'           => 'beauty',
        'trucco'            => 'beauty',
        'spa'               => 'beauty',
        'centro estetico'   => 'beauty',
        'cosmetica'         => 'beauty',
        'skin care'         => 'beauty',
        'skincare'          => 'beauty',
        'capelli'           => 'beauty',
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
            'viral_hooks' => [
                'Guarda come nasce [piatto] dalla preparazione al piatto.',
                'Il segreto di questa [ricetta/piatto] che ci chiedono ogni settimana.',
                'Cosa succede in cucina mezz\'ora prima dell\'apertura.',
                'X ingredienti che non ti aspetti in questo [piatto].',
                'Il piatto che i clienti ordinano sempre al secondo tentativo.',
                'Come scegliamo i fornitori: una visita che non ti aspetti.',
            ],
            'viral_formats' => ['before_after', 'day_in_life', 'storytime_reel', 'tutorial_list'],
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
            'viral_hooks' => [
                'X segnali che il tuo corpo sta cercando di dirti qualcosa.',
                'Il mito su [condizione] che sentiamo più spesso in studio.',
                'Cosa succede davvero durante [procedura comune].',
                'La domanda che ci fate sempre, con la risposta onesta.',
                'X abitudini quotidiane che sembrano innocue ma non lo sono.',
                'Come capire se è il momento giusto per [screening/visita].',
            ],
            'viral_formats' => ['myth_busting', 'educational_carousel', 'response_to_question', 'tutorial_list'],
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
                'primary_cta'   => 'Scopri tutti i dettagli nel link in bio',
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
            'viral_hooks' => [
                'Il motivo per cui il X% dei clienti torna ogni stagione.',
                'Come scegliere [prodotto] senza sbagliare: la guida onesta.',
                'Quello che nessuno ti dice quando compri [categoria] online.',
                'X domande da fare prima di acquistare [prodotto].',
                'Un cliente ha scritto questo e abbiamo cambiato qualcosa.',
                'Unboxing: cosa trovi davvero dentro il pacco.',
            ],
            'viral_formats' => ['before_after', 'educational_carousel', 'tutorial_list', 'response_to_question', 'storytime_reel'],
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
            'viral_hooks' => [
                'X cose che mi avrebbe aiutato sapere prima di [milestone].',
                'Il metodo che uso con ogni nuovo allievo e che cambia tutto.',
                'Perché il metodo classico di [apprendimento] non basta più.',
                'Ho insegnato [topic] per anni. Questo è l\'errore più comune.',
                'Come passare da [stato A] a [stato B] senza perdere tempo.',
                'Il risultato di questo allievo dopo X settimane di lavoro.',
            ],
            'viral_formats' => ['tutorial_list', 'educational_carousel', 'storytime_reel', 'myth_busting', 'before_after'],
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
            'viral_hooks' => [
                'Il consiglio che avrei voluto ricevere quando ho iniziato in [settore].',
                'X domande da fare prima di scegliere [tipo di professionista].',
                'Quello che i colleghi non dicono ad alta voce su [tema].',
                'Come abbiamo risolto [problema tipico] per un cliente in X giorni.',
                'L\'errore che costa di più e che si poteva evitare.',
                'Il cliente pensava di avere un problema di X. Era tutt\'altro.',
            ],
            'viral_formats' => ['unpopular_opinion', 'tutorial_list', 'educational_carousel', 'response_to_question', 'storytime_reel'],
        ],

        // ─── IMMOBILIARE ─────────────────────────────────────────────────────
        'realestate' => [
            'pillars' => [
                'Tour Proprietà',
                'Consigli per Comprare Casa',
                'Il Mercato Immobiliare Oggi',
                'Storie di Clienti Soddisfatti',
                'Prima Casa: Guida Pratica',
                'Il Dietro le Quinte delle Trattative',
            ],
            'rubrics' => [
                ['name' => 'Property Tour',     'weight' => 0.35, 'focus' => 'video e foto delle proprietà'],
                ['name' => 'Educativo',          'weight' => 0.25, 'focus' => 'consigli pratici per acquirenti'],
                ['name' => 'Social Proof',       'weight' => 0.20, 'focus' => 'testimonianze clienti, trattative chiuse'],
                ['name' => 'Mercato & Trend',    'weight' => 0.20, 'focus' => 'aggiornamenti zona, valutazioni'],
            ],
            'cta_rules' => [
                'primary_cta'   => 'Richiedi una valutazione gratuita',
                'secondary_cta' => 'Scopri le proprietà disponibili',
                'link_cta'      => 'Tutti gli immobili nel link in bio',
                'avoid'         => ['affare imperdibile', 'prezzi stracciati', 'ultima occasione'],
            ],
            'brand_voice' => [
                'tone'   => 'professionale, diretto, umano',
                'values' => ['fiducia', 'trasparenza', 'risultati', 'competenza locale'],
            ],
            'analysis_framework' => [
                'primary_goal'   => 'Lead Generation + Brand Awareness',
                'secondary_goal' => 'Social Proof e Referral',
                'content_mix'    => ['property' => 0.4, 'educational' => 0.35, 'social_proof' => 0.25],
            ],
            'viral_hooks' => [
                'Tour di questa proprietà che abbiamo venduto in X giorni.',
                'X errori che fanno perdere soldi quando si vende casa.',
                'Il cliente pensava di vendere a X. L\'abbiamo venduta a Y.',
                'Come valutare un\'offerta immobiliare senza farsi ingannare.',
                'Il quartiere che sta crescendo più velocemente in questo momento.',
                'Prima casa: le X cose che nessuno ti dice in anticipo.',
            ],
            'viral_formats' => ['before_after', 'day_in_life', 'storytime_reel', 'educational_carousel', 'tutorial_list'],
        ],

        // ─── TURISMO & HOSPITALITY ───────────────────────────────────────────
        'tourism' => [
            'pillars' => [
                'Esperienze e Luoghi',
                'Consigli di Viaggio',
                'Dietro le Quinte della Struttura',
                'Testimonianze degli Ospiti',
                'Gastronomia e Territorio',
                'Stagioni e Festività',
            ],
            'rubrics' => [
                ['name' => 'Destination Visual',  'weight' => 0.40, 'focus' => 'paesaggi, struttura, esperienze'],
                ['name' => 'Consigli Pratici',     'weight' => 0.25, 'focus' => 'guide, itinerari, tips'],
                ['name' => 'Social Proof',         'weight' => 0.20, 'focus' => 'recensioni, ospiti felici'],
                ['name' => 'Promo Stagionale',     'weight' => 0.15, 'focus' => 'offerte, pacchetti, disponibilità'],
            ],
            'cta_rules' => [
                'primary_cta'   => 'Prenota il tuo soggiorno',
                'secondary_cta' => 'Scopri le disponibilità',
                'link_cta'      => 'Prenota direttamente dal link in bio',
                'avoid'         => ['offerta shock', 'last minute disperato'],
            ],
            'brand_voice' => [
                'tone'   => 'ispirante, accogliente, autentico',
                'values' => ['esperienza', 'autenticità', 'accoglienza', 'territorio'],
            ],
            'analysis_framework' => [
                'primary_goal'   => 'Prenotazioni + Brand Awareness',
                'secondary_goal' => 'Community e Fidelizzazione',
                'content_mix'    => ['visual' => 0.5, 'educational' => 0.3, 'promo' => 0.2],
            ],
            'viral_hooks' => [
                'Il posto che i turisti non trovano sulle guide ma i locali amano.',
                'X motivi per cui [destinazione] vale davvero il viaggio.',
                'Cosa succede nella nostra struttura prima che gli ospiti arrivino.',
                'L\'esperienza che i nostri ospiti non si aspettavano.',
                'Il piatto locale che non si trova nei ristoranti per turisti.',
                'Tour della [struttura/destinazione] prima dell\'alta stagione.',
            ],
            'viral_formats' => ['day_in_life', 'storytime_reel', 'before_after', 'tutorial_list'],
        ],

        // ─── BEAUTY & WELLNESS ───────────────────────────────────────────────
        'beauty' => [
            'pillars' => [
                'Tutorial e Tecniche',
                'Prima e Dopo',
                'Consigli per la Cura Personale',
                'Prodotti e Ingredienti',
                'Il Team e i Servizi',
                'Tendenze Stagionali',
            ],
            'rubrics' => [
                ['name' => 'Tutorial Visual',   'weight' => 0.35, 'focus' => 'step-by-step, tecniche, risultati'],
                ['name' => 'Before & After',    'weight' => 0.25, 'focus' => 'trasformazioni reali'],
                ['name' => 'Educational',       'weight' => 0.20, 'focus' => 'consigli, ingredienti, routine'],
                ['name' => 'Social Proof',      'weight' => 0.20, 'focus' => 'clienti soddisfatti, recensioni'],
            ],
            'cta_rules' => [
                'primary_cta'   => 'Prenota il tuo appuntamento',
                'secondary_cta' => 'Scopri i nostri trattamenti',
                'link_cta'      => 'Prenota dal link in bio',
                'avoid'         => ['garantiamo risultati', 'effetto immediato', 'rivoluzionario'],
            ],
            'brand_voice' => [
                'tone'   => 'caldo, ispirazionale, competente',
                'values' => ['bellezza', 'cura', 'autenticità', 'professionalità'],
            ],
            'analysis_framework' => [
                'primary_goal'   => 'Prenotazioni + Retention',
                'secondary_goal' => 'Brand Awareness',
                'content_mix'    => ['tutorial' => 0.4, 'social_proof' => 0.35, 'educational' => 0.25],
            ],
            'viral_hooks' => [
                'Il risultato di questa [trattamento] dopo X settimane.',
                'Come faccio [tecnica] che mi chiedete ogni settimana.',
                'X errori nella routine di [cura] che stanno peggiorando il risultato.',
                'Prima e dopo: questa cliente non credeva fosse possibile.',
                'L\'ingrediente che trovi in quasi tutti i prodotti ma che in pochi conoscono.',
                'Quello che vedo ogni giorno e che la maggior parte sbaglia con [cura].',
            ],
            'viral_formats' => ['before_after', 'tutorial_list', 'storytime_reel', 'educational_carousel'],
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
