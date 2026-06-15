<?php

namespace App\Support;

class UiStatus
{
    /**
     * @return array{key:string,label:string,description:string,badge:string,pill:string,tone:string}
     */
    public static function ai(?string $status): array
    {
        $normalized = strtolower(trim((string) $status));

        return match ($normalized) {
            'done' => [
                'key' => 'done',
                'label' => 'Completato',
                'description' => 'Testo e visual pronti.',
                'badge' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                'pill' => 'badge-success',
                'tone' => 'success',
            ],
            'error', 'failed' => [
                'key' => 'error',
                'label' => 'Da verificare',
                'description' => 'Generazione non riuscita. Controlla e rigenera.',
                'badge' => 'border-red-200 bg-red-50 text-red-700',
                'pill' => 'badge-danger',
                'tone' => 'danger',
            ],
            'queued', 'pending' => [
                'key' => 'processing',
                'label' => 'In lavorazione',
                'description' => 'Elaborazione in corso.',
                'badge' => 'border-amber-200 bg-amber-50 text-amber-700',
                'pill' => 'badge-warning',
                'tone' => 'warning',
            ],
            default => [
                'key' => 'idle',
                'label' => 'Non avviato',
                'description' => 'Non ancora inviato alla generazione.',
                'badge' => 'border-gray-200 bg-gray-50 text-gray-700',
                'pill' => 'badge-neutral',
                'tone' => 'neutral',
            ],
        };
    }

    /**
     * @return array{key:string,label:string,description:string,badge:string,tone:string}
     */
    public static function publication(?string $status): array
    {
        $normalized = strtolower(trim((string) $status));

        return match ($normalized) {
            'published' => [
                'key' => 'published',
                'label' => 'Pubblicato',
                'description' => 'Contenuto online.',
                'badge' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                'tone' => 'success',
            ],
            'scheduled' => [
                'key' => 'scheduled',
                'label' => 'Programmato',
                'description' => 'In calendario, in attesa di pubblicazione.',
                'badge' => 'border-indigo-200 bg-indigo-50 text-indigo-700',
                'tone' => 'info',
            ],
            'approved' => [
                'key' => 'approved',
                'label' => 'Approvato',
                'description' => 'Pronto alla programmazione.',
                'badge' => 'border-cyan-200 bg-cyan-50 text-cyan-700',
                'tone' => 'info',
            ],
            'review' => [
                'key' => 'review',
                'label' => 'In revisione',
                'description' => 'Da controllare prima della pubblicazione.',
                'badge' => 'border-amber-200 bg-amber-50 text-amber-700',
                'tone' => 'warning',
            ],
            'failed' => [
                'key' => 'failed',
                'label' => 'Non pubblicato',
                'description' => 'Pubblicazione non riuscita.',
                'badge' => 'border-red-200 bg-red-50 text-red-700',
                'tone' => 'danger',
            ],
            default => [
                'key' => 'draft',
                'label' => 'Bozza',
                'description' => 'In lavorazione.',
                'badge' => 'border-gray-200 bg-gray-50 text-gray-700',
                'tone' => 'neutral',
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function publicationOptions(): array
    {
        return [
            'draft' => 'Bozza',
            'review' => 'In revisione',
            'approved' => 'Approvato',
            'scheduled' => 'Programmato',
            'published' => 'Pubblicato',
            'failed' => 'Non pubblicato',
        ];
    }
}

