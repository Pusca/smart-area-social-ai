<?php

namespace App\Services\Editorial;

use Illuminate\Support\Str;

/**
 * Fornisce il framework di contenuto virale aggiornato (2025-2026).
 *
 * Ogni piattaforma ha meccaniche di distribuzione diverse:
 * - Instagram Reels: primato dell'hook visivo nei primi 1.5s, save-rate come segnale chiave
 * - TikTok: hook entro 0.5s, native trend formats, completion rate prioritario
 * - LinkedIn: narrative personale + insight operativo, saves + commenti qualificati
 * - Facebook: community signal, condivisione organica, video nativo
 *
 * Il framework viene iniettato nella strategia editoriale come `viral_framework`
 * e consumato dal pipeline di generazione testo.
 */
class ViralContentFrameworkService
{
    /**
     * Costruisce il viral framework per il tenant.
     *
     * @param  array<int, string>  $platforms
     * @param  array<int, string>  $formats
     * @param  array<string, mixed>  $industryPreset
     * @param  string  $industry
     * @return array<string, mixed>
     */
    public function build(
        array $platforms,
        array $formats,
        array $industryPreset = [],
        string $industry = ''
    ): array {
        $primaryPlatform = $this->normalizePlatform($platforms[0] ?? 'instagram');
        $hasVideo = !empty(array_intersect(['reel', 'video'], $formats));
        $hasCarousel = in_array('carousel', $formats, true);

        return [
            'platform_rules'     => $this->buildPlatformRules($platforms),
            'hook_framework'     => $this->buildHookFramework($primaryPlatform, $hasVideo),
            'content_formats'    => $this->buildContentFormats($platforms, $hasVideo, $hasCarousel),
            'virality_mechanics' => $this->viralityMechanics(),
            'copy_formulas'      => $this->copyFormulas($primaryPlatform),
            'posting_schedule'   => $this->buildPostingSchedule($platforms),
            'industry_hooks'     => $this->industryHooks($industry, $industryPreset),
            'guardrails'         => $this->viralGuardrails(),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Regole per piattaforma
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $platforms
     * @return array<string, array<string, mixed>>
     */
    private function buildPlatformRules(array $platforms): array
    {
        $all = [
            'instagram' => [
                'algorithm_signal'   => 'Save-rate e shares sono i segnali algoritmici più forti su Instagram. Ogni contenuto deve guadagnarsi un salvataggio.',
                'hook_window_ms'     => 1500,
                'hook_rule'          => 'La prima riga del caption e il primo frame del video devono fermare lo scroll. Senza hook non c\'è reach.',
                'caption_structure'  => 'Riga 1: fermascroll (claim, domanda, dato inaspettato). Riga 2: contesto o tensione. Corpo: valore concreto. Chiusura: CTA salvataggio o commento.',
                'reel_structure'     => '0-1.5s: contrasto visivo o claim breve. 1.5-4s: setup del valore. 4-12s: sviluppo. Ultimi 2s: payoff + CTA.',
                'carousel_structure' => 'Cover: promessa che spinge allo swipe. Card 2-5: progressione di valore. Ultima card: takeaway + CTA soft.',
                'formats_ranked'     => ['reel', 'carousel', 'post'],
                'banned'             => ['lunghi paragrafi senza a-capo', 'emoji casuali ogni riga', 'hashtag nel corpo del testo'],
                'best_hashtag_count' => '3-7 hashtag specifici, non di massa',
                'engagement_triggers'=> ['domanda alla fine', 'invito a taggare qualcuno', 'salva per dopo', 'confronto nella risposta'],
            ],
            'tiktok' => [
                'algorithm_signal'   => 'Completion rate è il segnale numero uno. Se non guardano fino in fondo, il video non viene distribuito.',
                'hook_window_ms'     => 500,
                'hook_rule'          => 'Nei primi 0.5 secondi deve succedere qualcosa. Pattern interrupt visivo, frase forte o situazione inaspettata.',
                'caption_structure'  => 'Caption breve (max 150 caratteri). Serve solo a dare contesto, non a sostituire il video.',
                'reel_structure'     => '0-0.5s: hook immediato. 0.5-2s: promessa del payoff. 2-12s: sviluppo rapido. Ultimi 2s: payoff o CTA commento.',
                'carousel_structure' => 'Prima slide: contrasto o domanda forte. Progressione rapida. Ultima slide: payoff condivisibile.',
                'formats_ranked'     => ['reel', 'post', 'carousel'],
                'banned'             => ['intro lente con logo', 'musica casuale non trending', 'testo illeggibile su video'],
                'native_formats'     => ['POV: ...', 'Tell me without telling me...', 'Things I wish I knew before...', 'Day in my life as...', 'Rating: ...', '3 things that...'],
                'engagement_triggers'=> ['domanda diretta nei commenti', 'trend sonoro in uso', 'duet invitation', 'stitch invitation'],
            ],
            'linkedin' => [
                'algorithm_signal'   => 'Commenti e salvataggi contano più dei like. I post con dwell time alto (lettura lunga) ottengono più reach.',
                'hook_window_ms'     => 3000,
                'hook_rule'          => 'Prima riga: insight controintuitivo, errore comune o dato sorprendente. Deve spingere a cliccare "Mostra altro".',
                'caption_structure'  => 'Riga 1: hook forte (max 180 caratteri). Spaziatura verticale. Sviluppo in paragrafi brevi. Lista quando serve chiarezza. Chiusura: domanda o takeaway.',
                'carousel_structure' => 'Cover: tesi netta. Card 2-4: prove o passaggi operativi. Ultima card: next step o invito al commento.',
                'formats_ranked'     => ['post', 'carousel', 'video'],
                'banned'             => ['autoreferenzialità eccessiva', 'content motivazionale generico', 'annunci di premi o certificati senza valore reale'],
                'top_formats'        => ['Unpopular opinion: ...', 'I spent X years doing Y and here\'s what I learned:', 'Nobody talks about X but here\'s why it matters:', 'My honest take on X:', 'Lesson learned the hard way:'],
                'engagement_triggers'=> ['domanda aperta', 'posizione netta su tema di settore', 'invito a condividere esperienza simile'],
            ],
            'facebook' => [
                'algorithm_signal'   => 'Condivisioni organiche e commenti profondi (non solo emoji) aumentano il reach. Il video nativo batte i link.',
                'hook_window_ms'     => 2000,
                'hook_rule'          => 'Apri con scena, situazione o domanda relazionabile. Il copy deve sembrare scritto da una persona, non da un brand.',
                'caption_structure'  => 'Tono caldo e conversazionale. Apri con scena umana. Sviluppa con valore pratico. Chiudi con domanda semplice o CTA relazionale.',
                'formats_ranked'     => ['video', 'post', 'carousel'],
                'banned'             => ['tono corporativo', 'call-to-action aggressivi', 'link nel corpo del post'],
                'engagement_triggers'=> ['domanda di community', 'sondaggio', 'invito a condividere esperienza', 'tag amico/collega'],
            ],
        ];

        $result = [];
        foreach ($platforms as $p) {
            $key = $this->normalizePlatform($p);
            if (isset($all[$key])) {
                $result[$key] = $all[$key];
            }
        }

        return $result ?: ['instagram' => $all['instagram']];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Hook framework
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildHookFramework(string $platform, bool $hasVideo): array
    {
        $structures = [
            'curiosity_gap'      => 'Apri con informazione parziale che forza a continuare. Es: "C\'è qualcosa che quasi tutti ignorano su X."',
            'pattern_interrupt'  => 'Rompi l\'aspettativa del feed. Contraddizione, numero inaspettato, affermazione senza premessa.',
            'specificity_hook'   => 'I numeri e i dettagli concreti battono le affermazioni generiche. "3 clienti su 5" > "molti clienti".',
            'stakes_hook'        => 'Rendi evidente perché il contenuto è rilevante ADESSO. Cosa succede se non leggi fino in fondo?',
            'relatability_hook'  => 'Descrivi una situazione che il target riconosce come propria. La familiarità ferma lo scroll.',
            'contrarian_hook'    => 'Prendi posizione contro la saggezza convenzionale del settore. Richiede autorevolezza ma genera commenti.',
            'result_first_hook'  => 'Mostra il risultato prima del processo. "Come ho ottenuto X senza fare Y."',
        ];

        $videoSpecific = $hasVideo ? [
            'visual_hook_rules' => [
                'frame_0_1s'   => 'Primo frame: soggetto riconoscibile, azione in corso o testo leggibile in meno di 1 secondo.',
                'no_intro'     => 'Zero secondi di intro con logo, musica o "ciao ragazzi". L\'hook è il primo fotogramma.',
                'movement'     => 'Il movimento nei primi secondi (gesto, cambio scena, comparsa di testo) aumenta il retention.',
                'text_overlay' => 'Il testo nei primi secondi deve essere leggibile a schermo bloccato e senza audio.',
            ],
        ] : [];

        $platformSpecific = match ($platform) {
            'tiktok'    => ['platform_note' => 'Su TikTok l\'hook visivo conta più del copy. Cosa vedi nel primo frame definisce il CTR.'],
            'linkedin'  => ['platform_note' => 'Su LinkedIn l\'hook deve passare il test "vale 3 secondi del mio tempo?". Insight operativi battono motivazionale.'],
            'facebook'  => ['platform_note' => 'Su Facebook l\'hook deve suonare come un amico che ti racconta qualcosa, non un brand che annuncia.'],
            default     => ['platform_note' => 'Su Instagram la prima riga del caption è visibile senza espandere: deve spingere al click "Altro".'],
        };

        return array_merge(['structures' => $structures], $videoSpecific, $platformSpecific);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Content formats virali 2025-2026
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $platforms
     * @return array<string, array<string, string>>
     */
    private function buildContentFormats(array $platforms, bool $hasVideo, bool $hasCarousel): array
    {
        $formats = [
            'educational_carousel' => [
                'description'   => 'Carousel educativo: insegna qualcosa di pratico in 5-8 slide. Cover con promessa, sviluppo per passaggi, chiusura con takeaway.',
                'virality_why'  => 'Save-rate alto. Le persone salvano contenuti che vogliono rileggere.',
                'platforms'     => 'Instagram, LinkedIn',
                'hook_formula'  => '"X cose che non sapevi su [topic]" oppure "La guida definitiva a [topic] in X slide"',
            ],
            'before_after' => [
                'description'   => 'Trasformazione visibile: mostra lo stato prima e dopo. Funziona per qualsiasi settore.',
                'virality_why'  => 'Proof visiva immediata. Il cervello elabora la trasformazione prima del copy.',
                'platforms'     => 'Instagram, TikTok, Facebook',
                'hook_formula'  => '"Prima/Dopo", "Come siamo passati da X a Y", "Risultato dopo Z giorni"',
            ],
            'storytime_reel' => [
                'description'   => 'Racconta una storia reale con inizio, conflitto e risoluzione. 15-60 secondi.',
                'virality_why'  => 'Il narrative engagement aumenta il completion rate e i commenti empatici.',
                'platforms'     => 'TikTok, Instagram Reels, Facebook Video',
                'hook_formula'  => '"La cosa più strana che mi sia mai capitata con [topic]", "Come ho sbagliato X e cosa ho imparato"',
            ],
            'myth_busting' => [
                'description'   => 'Smaschera un\'idea sbagliata comune nel settore. Più specifica la falsa credenza, più forte la risposta.',
                'virality_why'  => 'Genera commenti di chi è d\'accordo E di chi non lo è. Il dissenso controllato è ottimo per il reach.',
                'platforms'     => 'Tutti',
                'hook_formula'  => '"FALSO: [mito comune]", "Smetti di credere che [credenza sbagliata]", "Nessuno ti dice che..."',
            ],
            'tutorial_list' => [
                'description'   => 'Lista di X consigli, errori o passaggi concreti. Veloce da consumare, facile da salvare.',
                'virality_why'  => 'Chiarezza immediata del valore. Il formato lista riduce la friction cognitiva.',
                'platforms'     => 'Instagram, LinkedIn, TikTok',
                'hook_formula'  => '"X errori che stanno sabotando il tuo [topic]", "X cose da fare prima di [azione]"',
            ],
            'pov_format' => [
                'description'   => 'POV (Point of View): metti l\'utente nella situazione. "POV: sei un cliente che entra per la prima volta."',
                'virality_why'  => 'Immedesimazione immediata. Il formato è nativo TikTok ma funziona su tutti i feed brevi.',
                'platforms'     => 'TikTok, Instagram Reels',
                'hook_formula'  => '"POV: [situazione]", "Il giorno in cui ho capito che..."',
            ],
            'unpopular_opinion' => [
                'description'   => 'Prendi una posizione controintuitiva o impopolare nel settore. Deve essere difendibile, non provocatoria per provocare.',
                'virality_why'  => 'Polarizza. Chi è d\'accordo commenta con supporto, chi non lo è commenta con dissenso. Reach alto.',
                'platforms'     => 'LinkedIn, Instagram, TikTok',
                'hook_formula'  => '"Hot take:", "Unpopular opinion:", "Dico una cosa che non piace a molti:"',
            ],
            'day_in_life' => [
                'description'   => 'Mostra la giornata tipo, il dietro le quinte, il processo. Autentico e non eccessivamente prodotto.',
                'virality_why'  => 'Costruisce fiducia e connessione. Le persone comprano da chi conoscono.',
                'platforms'     => 'TikTok, Instagram Reels, Stories',
                'hook_formula'  => '"Come funziona davvero [processo]", "Cosa succede realmente quando..."',
            ],
            'response_to_question' => [
                'description'   => 'Risponde a una domanda vera del target. Può essere reale o costruita come rappresentativa.',
                'virality_why'  => 'Chi ha la stessa domanda guarda fino in fondo e salva il contenuto.',
                'platforms'     => 'Tutti',
                'hook_formula'  => '"Mi chiedete spesso [domanda]. La risposta onesta è:", "La domanda che tutti si fanno su [topic]:"',
            ],
        ];

        if (!$hasVideo) {
            unset($formats['storytime_reel'], $formats['pov_format'], $formats['day_in_life']);
        }

        if (!$hasCarousel) {
            unset($formats['educational_carousel']);
        }

        return $formats;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Meccaniche di viralità
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function viralityMechanics(): array
    {
        return [
            'social_currency'   => 'Condividiamo cose che ci fanno sembrare intelligenti, aggiornati o con buon gusto. Ogni contenuto deve dare qualcosa da condividere.',
            'triggers'          => 'Le persone condividono quando qualcosa gli ricorda un altro concetto. Collega il contenuto a qualcosa che il target pensa già.',
            'emotion'           => 'I contenuti che generano emozione forte (gioia, sorpresa, ispirazione, rabbia costruttiva) vengono condivisi. Non la paura o la tristezza.',
            'practical_value'   => 'Contenuto utile = salvataggio = reach. Il valore pratico è la forma più scalabile di viralità organica.',
            'story'             => 'Il formato narrativo aumenta il dwell time e il recall. Le storie reali battono i claim teorici.',
            'specificity'       => 'Più specifico il claim, più credibile e condivisibile. "3 clienti su 5" > "la maggior parte dei clienti".',
            'identity_signal'   => 'Le persone condividono contenuti che esprimono chi sono o chi vogliono essere. Parla all\'identità, non solo al bisogno.',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Formule di copy
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array<string, string>>
     */
    private function copyFormulas(string $platform): array
    {
        $universal = [
            'PAS' => [
                'name'        => 'Problem → Agitate → Solution',
                'structure'   => 'Identifica il problema. Approfondisci la tensione. Porta la soluzione.',
                'best_for'    => 'Conversion, lead generation',
                'example'     => '"Gestire i social richiede ore ogni settimana [P]. Senza una strategia, si posta a caso e si perde tempo su contenuti che non convertono [A]. [Brand] automatizza questo con AI [S]."',
            ],
            'AIDA' => [
                'name'        => 'Attention → Interest → Desire → Action',
                'structure'   => 'Hook forte. Sviluppa curiosità. Crea desiderio con benefici concreti. CTA chiara.',
                'best_for'    => 'Post promozionali, annunci, lancio',
            ],
            'STOR' => [
                'name'        => 'Situation → Tension → Outcome → Relevance',
                'structure'   => 'Contesto. Problema o momento critico. Come si è risolto. Perché conta per chi legge.',
                'best_for'    => 'Storytelling, brand awareness, social proof',
            ],
            'WHY-HOW-WHAT' => [
                'name'        => 'Why → How → What (Simon Sinek invertito)',
                'structure'   => 'Apri con il perché (motivazione, valori, missione). Poi il come (processo, approccio). Poi il cosa (offerta, prodotto).',
                'best_for'    => 'Brand storytelling, contenuto autoriale',
            ],
            'VALUE_LADDER' => [
                'name'        => 'Free value → Soft ask',
                'structure'   => 'Dai valore reale gratis (consiglio, insight, checklist). Alla fine, CTA morbida verso offerta o contatto.',
                'best_for'    => 'Lead generation, trust building',
            ],
        ];

        $platformSpecific = match ($platform) {
            'linkedin' => [
                'HOOK_SCROLL' => [
                    'name'      => 'Hook → Mostra Altro → Sviluppo → CTA',
                    'structure' => 'Prima riga: insight forte entro 180 caratteri. Spaziatura verticale per leggibilità. Sviluppo in paragrafi brevi. Domanda finale.',
                    'best_for'  => 'Tutti i post LinkedIn',
                ],
            ],
            'tiktok' => [
                'NATIVE_HOOK' => [
                    'name'      => 'Hook nativo → Setup → Payoff rapido',
                    'structure' => '"POV: ..." o "Quando [situazione]..." nei primi 2 secondi. Setup in 3-5 secondi. Payoff prima dei 15 secondi.',
                    'best_for'  => 'Reels, short video TikTok',
                ],
            ],
            default => [
                'CAPTION_FORMULA' => [
                    'name'      => 'Hook → Tensione → Valore → CTA',
                    'structure' => 'Prima riga fermascroll. Seconda riga contesto. Corpo: valore in paragrafi brevi. Chiusura: CTA salvataggio o commento.',
                    'best_for'  => 'Post Instagram, Facebook',
                ],
            ],
        };

        return array_merge($universal, $platformSpecific);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Orari di pubblicazione ottimali (2025)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $platforms
     * @return array<string, array<string, mixed>>
     */
    private function buildPostingSchedule(array $platforms): array
    {
        $schedule = [
            'instagram' => [
                'best_days'          => ['Martedì', 'Mercoledì', 'Giovedì'],
                'best_times'         => ['09:00', '12:00', '19:00'],
                'avoid'              => ['Domenica mattina', 'Lunedì molto presto', 'Tarda notte'],
                'frequency'          => '4-7 post/settimana per account attivo. Reels: almeno 3/settimana per mantenere reach.',
                'notes'              => 'I Reels distribuiti il mercoledì 9-11am hanno storicamente il miglior reach iniziale.',
            ],
            'tiktok' => [
                'best_days'          => ['Martedì', 'Mercoledì', 'Giovedì', 'Venerdì'],
                'best_times'         => ['07:00', '16:00', '21:00'],
                'avoid'              => ['Sabato mattina presto', 'Mercoledì pomeriggio tardo'],
                'frequency'          => '1-4 video/giorno per crescita rapida. Minimo 3/settimana per account in fase di crescita.',
                'notes'              => 'La consistenza conta più della perfezione su TikTok. Meglio 4 video discreti che 1 perfetto a settimana.',
            ],
            'linkedin' => [
                'best_days'          => ['Martedì', 'Mercoledì', 'Giovedì'],
                'best_times'         => ['08:00', '10:00', '12:00', '17:00'],
                'avoid'              => ['Weekend', 'Venerdì pomeriggio'],
                'frequency'          => '3-5 post/settimana. Più frequenza non sempre = più reach su LinkedIn.',
                'notes'              => 'Il martedì e giovedì mattina (8-10am) sono i momenti con più engagement professionale.',
            ],
            'facebook' => [
                'best_days'          => ['Mercoledì', 'Giovedì', 'Venerdì'],
                'best_times'         => ['13:00', '15:00', '19:00'],
                'avoid'              => ['Mattino presto tutti i giorni', 'Notte'],
                'frequency'          => '1-2 post/giorno massimo. Su Facebook la qualità batte la quantità.',
                'notes'              => 'I video nativi (caricati direttamente, non link YouTube) ottengono 10x più reach dei link.',
            ],
        ];

        $result = [];
        foreach ($platforms as $p) {
            $key = $this->normalizePlatform($p);
            if (isset($schedule[$key])) {
                $result[$key] = $schedule[$key];
            }
        }

        return $result ?: ['instagram' => $schedule['instagram']];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Hook templates per industry
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $industryPreset
     * @return array<string, mixed>
     */
    private function industryHooks(string $industry, array $industryPreset): array
    {
        $presetHooks = (array) data_get($industryPreset, 'viral_hooks', []);
        $builtIn     = $this->getBuiltInIndustryHooks($industry);

        return [
            'hook_templates'    => !empty($presetHooks) ? $presetHooks : $builtIn['templates'],
            'avoid_hooks'       => $builtIn['avoid'],
            'top_formats'       => !empty(data_get($industryPreset, 'viral_formats', [])) ? $industryPreset['viral_formats'] : $builtIn['formats'],
            'emotional_triggers'=> $builtIn['triggers'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getBuiltInIndustryHooks(string $industry): array
    {
        $normalized = Str::lower(trim($industry));

        $defaults = [
            'templates' => [
                'Il motivo per cui [problema comune nel settore] sta succedendo anche a te.',
                'Ho fatto [azione] per X anni e questo è quello che ho imparato davvero.',
                'Quello che nessuno ti dice su [topic del settore].',
                'X errori che stai probabilmente facendo con [tema].',
                'Prima di [azione comune], leggi questo.',
            ],
            'avoid'     => [
                'Il segreto che cambierà la tua vita',
                'Trucchi per risultati garantiti',
                'Formula magica per il successo',
            ],
            'formats'   => ['educational_carousel', 'myth_busting', 'before_after'],
            'triggers'  => ['curiosità', 'utilità pratica', 'riconoscimento del problema'],
        ];

        $industry_map = [
            'food' => [
                'templates' => [
                    'Il piatto che ogni settimana ruba la scena al tavolo.',
                    'Come abbiamo preparato [piatto] prima che arrivassero le prenotazioni del weekend.',
                    'X cose che fanno la differenza tra un buon [piatto] e uno indimenticabile.',
                    'Il nostro chef ti mostra come non commettere questo errore in cucina.',
                    'Dietro le quinte: come nasce il menù del mese.',
                ],
                'avoid'     => ['Venite a mangiare da noi', 'Il miglior ristorante della zona'],
                'formats'   => ['before_after', 'day_in_life', 'tutorial_list', 'storytime_reel'],
                'triggers'  => ['appetito visivo', 'curiosità sul processo', 'identità locale'],
            ],
            'healthcare' => [
                'templates' => [
                    'X segnali che il tuo corpo sta cercando di dirti qualcosa.',
                    'Il mito su [condizione] che sentiamo più spesso in studio.',
                    'Cosa succede realmente quando [procedura comune].',
                    'La domanda che ci fate sempre e che merita una risposta onesta.',
                    'X abitudini quotidiane che sembrano innocue ma non lo sono.',
                ],
                'avoid'     => ['Vieni da noi per guarire', 'Risultati garantiti', 'Il miglior medico della città'],
                'formats'   => ['myth_busting', 'educational_carousel', 'response_to_question', 'tutorial_list'],
                'triggers'  => ['salute', 'sicurezza', 'informazione affidabile'],
            ],
            'ecommerce' => [
                'templates' => [
                    'Il motivo per cui il X% dei nostri clienti torna ogni mese.',
                    'Come scegliere [prodotto] senza sbagliare: la guida pratica.',
                    'Quello che non ti dicono quando compri [categoria prodotto] online.',
                    'X domande da fare prima di acquistare [prodotto].',
                    'Il cliente ha detto questo e abbiamo cambiato qualcosa.',
                ],
                'avoid'     => ['Acquista ora!', 'Offerta imperdibile', 'Solo per oggi'],
                'formats'   => ['before_after', 'educational_carousel', 'tutorial_list', 'response_to_question'],
                'triggers'  => ['valore del prodotto', 'fiducia nel brand', 'riprova sociale'],
            ],
            'education' => [
                'templates' => [
                    'X cose che mi avrebbe aiutato sapere prima di [milestone].',
                    'Il metodo che uso con i miei studenti e che funziona davvero.',
                    'Perché il metodo classico di [apprendimento] non funziona più.',
                    'Ho insegnato [topic] per X anni. Questo è l\'errore più comune.',
                    'Come passare da [stato A] a [stato B] in X settimane.',
                ],
                'avoid'     => ['Cambia la tua vita', 'Diventa esperto in 30 giorni'],
                'formats'   => ['tutorial_list', 'educational_carousel', 'storytime_reel', 'myth_busting'],
                'triggers'  => ['crescita personale', 'competenza', 'risultati misurabili'],
            ],
            'professional' => [
                'templates' => [
                    'Il consiglio che avrei voluto ricevere quando ho iniziato in [settore].',
                    'X domande che dovresti fare prima di scegliere [servizio/consulente].',
                    'Quello che i colleghi non dicono ad alta voce su [tema professionale].',
                    'Come abbiamo risolto [problema tipico del settore] per un cliente.',
                    'L\'errore che costa di più e che si poteva evitare.',
                ],
                'avoid'     => ['I migliori professionisti del settore', 'Anni di esperienza garantiscono...'],
                'formats'   => ['unpopular_opinion', 'tutorial_list', 'educational_carousel', 'response_to_question'],
                'triggers'  => ['autorevolezza', 'fiducia', 'competenza pratica'],
            ],
        ];

        foreach ($industry_map as $key => $data) {
            if (str_contains($normalized, $key)) {
                return $data;
            }
        }

        return $defaults;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Guardrail anti-clickbait
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function viralGuardrails(): array
    {
        return [
            'permitted_virality' => [
                'Curiosità legittima: l\'hook promette e il contenuto mantiene.',
                'Specificità: numeri, dettagli, casi reali. Mai affermazioni vague.',
                'Valore primo: il contenuto dà qualcosa di utile prima di chiedere.',
                'Autenticità: racconta il vero, inclusi errori e processi reali.',
                'Posizione netta: avere un punto di vista chiaro è più virale del generico.',
            ],
            'hard_banned' => [
                'Clickbait non rispettato: prometti X nel hook, non consegnare X nel corpo.',
                'Urgenza artificiale: "solo oggi", "ultimi posti" senza che sia reale.',
                'Promesse garantite: "risultati sicuri", "funziona sempre".',
                'Sensazionalismo: "sconvolgente", "impossibile", "ti cambierà la vita".',
                'Fuffa marketing: "il segreto che nessuno vuole condividere".',
                'Trend non pertinenti: usare un trend audio/visivo scollegato dal messaggio.',
            ],
            'brand_safety_note' => 'La viralità non vale se compromette la credibilità del brand. Meglio 10.000 impression qualificate che 100.000 da un\'audience sbagliata.',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Utility
    // ──────────────────────────────────────────────────────────────────────────

    private function normalizePlatform(string $platform): string
    {
        $platform = Str::lower(trim($platform));
        return match ($platform) {
            'ig', 'insta'    => 'instagram',
            'fb'             => 'facebook',
            'li', 'lnkd'    => 'linkedin',
            'tt', 'tik'     => 'tiktok',
            default          => $platform,
        };
    }
}
