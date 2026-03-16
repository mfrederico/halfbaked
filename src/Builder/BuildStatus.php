<?php

declare(strict_types=1);

namespace HalfBaked\Builder;

/**
 * Build pipeline status progression.
 */
enum BuildStatus: string
{
    case Scanning = 'scanning';
    case Decomposing = 'decomposing';
    case Generating = 'generating';
    case Validating = 'validating';
    case Assembling = 'assembling';
    case Done = 'done';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badge(): string
    {
        return match ($this) {
            self::Scanning => '<span class="badge bg-info">Scanning</span>',
            self::Decomposing => '<span class="badge bg-info">Decomposing</span>',
            self::Generating => '<span class="badge bg-primary">Generating</span>',
            self::Validating => '<span class="badge bg-warning text-dark">Validating</span>',
            self::Assembling => '<span class="badge bg-secondary">Assembling</span>',
            self::Done => '<span class="badge bg-success">Done</span>',
            self::Failed => '<span class="badge bg-danger">Failed</span>',
        };
    }
}
