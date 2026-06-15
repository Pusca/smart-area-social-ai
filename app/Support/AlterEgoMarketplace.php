<?php

namespace App\Support;

/**
 * Libreria statica di template alter ego pre-configurati.
 * Ogni template copre un archetipo comune con campi realistici pronti all'uso.
 */
class AlterEgoMarketplace
{
    /**
     * @return list<array{id: string, label: string, description: string, archetype_key: string, industry: string, preview: string, data: array<string, mixed>}>
     */
    public static function templates(): array
    {
        return [
            [
                'id'           => 'tech_thought_leader',
                'label'        => 'Tech Thought Leader',
                'description'  => 'Esperto tech che guida la conversazione del settore con analisi incisive.',
                'archetype_key'=> 'thought_leader',
                'industry'     => 'Tecnologia & Innovazione',
                'preview'      => '"L\'AI non sostituisce le persone — amplifica chi sa usarla."',
                'data'         => [
                    'archetype'          => 'thought_leader',
                    'tone'               => 'autorevole',
                    'sentence_style'     => 'brevi_incisive',
                    'vocabulary_level'   => 'tecnico',
                    'audience_role'      => 'mentor',
                    'cta_style'          => 'soft',
                    'topics_owned'       => ['AI', 'innovazione', 'startup', 'futuro del lavoro', 'produttività'],
                    'topics_avoided'     => ['politica', 'gossip', 'contenuti clickbait'],
                    'unique_perspective' => 'Credo che la tecnologia debba essere al servizio delle persone, non il contrario. Ogni strumento vale quanto chi lo usa.',
                    'signature_phrases'  => ["Il futuro si costruisce adesso.", "La semplicità è la forma più avanzata di intelligenza."],
                ],
            ],
            [
                'id'           => 'business_educator',
                'label'        => 'Business Educator',
                'description'  => 'Semplifica business e finanza rendendoli accessibili a tutti.',
                'archetype_key'=> 'educator',
                'industry'     => 'Business & Finanza',
                'preview'      => '"Ogni imprenditore di successo ha iniziato non sapendo una cosa — e l\'ha imparata."',
                'data'         => [
                    'archetype'          => 'educator',
                    'tone'               => 'empatico',
                    'sentence_style'     => 'narrative',
                    'vocabulary_level'   => 'accessibile',
                    'audience_role'      => 'teacher',
                    'cta_style'          => 'domanda',
                    'topics_owned'       => ['finanza personale', 'business', 'crescita professionale', 'marketing'],
                    'topics_avoided'     => ['jargon finanziario', 'contenuti troppo tecnici senza spiegazione'],
                    'unique_perspective' => 'Il business è semplice — siamo noi a complicarlo. La chiarezza è il vero vantaggio competitivo.',
                    'signature_phrases'  => ["Semplice non significa banale.", "Il dato conta, la storia vende."],
                ],
            ],
            [
                'id'           => 'personal_storyteller',
                'label'        => 'Personal Storyteller',
                'description'  => 'Condivide esperienze autentiche che ispirano e connettono.',
                'archetype_key'=> 'storyteller',
                'industry'     => 'Personal Brand & Motivazione',
                'preview'      => '"Non ho avuto un percorso lineare. E sono grato per ogni deviazione."',
                'data'         => [
                    'archetype'          => 'storyteller',
                    'tone'               => 'empatico',
                    'sentence_style'     => 'narrative',
                    'vocabulary_level'   => 'accessibile',
                    'audience_role'      => 'peer',
                    'cta_style'          => 'soft',
                    'topics_owned'       => ['crescita personale', 'esperienze di vita', 'resilienza', 'mindset'],
                    'topics_avoided'     => ['contenuti generici', 'marketing aggressivo', 'finte motivazioni'],
                    'unique_perspective' => 'Le storie vere connettono più di qualsiasi dato. La vulnerabilità è forza, non debolezza.',
                    'signature_phrases'  => ["Non è andata come pensavo — meglio.", "Ogni fallimento ha un titolo di testa."],
                ],
            ],
            [
                'id'           => 'lifestyle_entertainer',
                'label'        => 'Lifestyle Entertainer',
                'description'  => 'Intrattiene con contenuti lifestyle autentici e coinvolgenti.',
                'archetype_key'=> 'entertainer',
                'industry'     => 'Lifestyle & Benessere',
                'preview'      => '"Oggi ho scoperto che il miglior investimento è dormire 8 ore."',
                'data'         => [
                    'archetype'          => 'entertainer',
                    'tone'               => 'colloquiale',
                    'sentence_style'     => 'brevi_incisive',
                    'vocabulary_level'   => 'accessibile',
                    'audience_role'      => 'entertainer',
                    'cta_style'          => 'domanda',
                    'topics_owned'       => ['lifestyle', 'viaggi', 'benessere', 'food', 'routine'],
                    'topics_avoided'     => ['politica', 'controversie', 'contenuti negativi'],
                    'unique_perspective' => 'La vita è troppo corta per contenuti noiosi. L\'autenticità batte sempre la perfezione.',
                    'signature_phrases'  => ["Vivi bene, condividi meglio.", "Il dettaglio fa la differenza."],
                ],
            ],
            [
                'id'           => 'industry_provocateur',
                'label'        => 'Industry Provocateur',
                'description'  => 'Sfida le convenzioni del settore con opinioni forti e documentate.',
                'archetype_key'=> 'provocateur',
                'industry'     => 'Marketing & Comunicazione',
                'preview'      => '"Il 90% del marketing è rumore. Il 10% è quello che ricordi."',
                'data'         => [
                    'archetype'          => 'provocateur',
                    'tone'               => 'diretto',
                    'sentence_style'     => 'brevi_incisive',
                    'vocabulary_level'   => 'misto',
                    'audience_role'      => 'challenger',
                    'cta_style'          => 'domanda',
                    'topics_owned'       => ['marketing', 'comunicazione digitale', 'trend di settore', 'opinioni scomode'],
                    'topics_avoided'     => ['contenuti banali', 'opinioni generiche', 'hype senza sostanza'],
                    'unique_perspective' => 'La verità scomoda è quella che nessuno vuole dirti ma tutti devono sentire.',
                    'signature_phrases'  => ["Non ti dico cosa vuoi sentire.", "Il consenso non è una strategia."],
                ],
            ],
            [
                'id'           => 'community_connector',
                'label'        => 'Community Connector',
                'description'  => 'Costruisce community e relazioni autentiche attorno a valori condivisi.',
                'archetype_key'=> 'connector',
                'industry'     => 'Community & HR',
                'preview'      => '"Le migliori idee nascono quando persone diverse si siedono allo stesso tavolo."',
                'data'         => [
                    'archetype'          => 'connector',
                    'tone'               => 'empatico',
                    'sentence_style'     => 'domande_retoriche',
                    'vocabulary_level'   => 'accessibile',
                    'audience_role'      => 'peer',
                    'cta_style'          => 'domanda',
                    'topics_owned'       => ['community building', 'networking', 'collaborazione', 'leadership'],
                    'topics_avoided'     => ['divisività', 'conflitti', 'elitismo'],
                    'unique_perspective' => 'Insieme si cresce più velocemente. La rete non è un mezzo, è il messaggio.',
                    'signature_phrases'  => ["Chi conosci conta quanto cosa sai.", "La community è il prodotto."],
                ],
            ],
        ];
    }

    public static function findById(string $id): ?array
    {
        foreach (self::templates() as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function archetypeColors(): array
    {
        return [
            'thought_leader' => 'bg-indigo-100 text-indigo-700 border-indigo-200',
            'educator'       => 'bg-emerald-100 text-emerald-700 border-emerald-200',
            'storyteller'    => 'bg-amber-100 text-amber-700 border-amber-200',
            'entertainer'    => 'bg-pink-100 text-pink-700 border-pink-200',
            'provocateur'    => 'bg-red-100 text-red-700 border-red-200',
            'connector'      => 'bg-cyan-100 text-cyan-700 border-cyan-200',
        ];
    }
}
