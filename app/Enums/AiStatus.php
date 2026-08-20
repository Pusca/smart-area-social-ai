<?php

namespace App\Enums;

enum AiStatus: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Generating = 'generating';
    case Done = 'done';
    case Error = 'error';

    /** Stati in cui la pipeline sta ancora lavorando (usati per il polling UI). */
    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Generating;
    }

    public function label(): string
    {
        return match ($this) {
            self::Idle => 'Da generare',
            self::Queued => 'In coda',
            self::Generating => 'Generazione…',
            self::Done => 'Pronto',
            self::Error => 'Errore',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Done => 'bg-green-100 text-green-700',
            self::Error => 'bg-red-100 text-red-700',
            self::Queued, self::Generating => 'bg-yellow-100 text-yellow-700',
            self::Idle => 'bg-gray-100 text-gray-700',
        };
    }

    /** @return list<string> */
    public static function busyValues(): array
    {
        return [self::Queued->value, self::Generating->value];
    }
}
