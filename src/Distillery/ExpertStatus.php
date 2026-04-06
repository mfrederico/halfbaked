<?php

declare(strict_types=1);

namespace HalfBaked\Distillery;

enum ExpertStatus: string
{
    case Pending = 'pending';
    case Cloning = 'cloning';
    case Detecting = 'detecting';
    case Extracting = 'extracting';
    case Distilling = 'distilling';
    case Training = 'training';
    case SelfDistilling = 'self_distilling';
    case Exporting = 'exporting';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Cloning => 'Cloning Repository',
            self::Detecting => 'Detecting Language',
            self::Extracting => 'Extracting Code',
            self::Distilling => 'Generating Dataset',
            self::Training => 'Training Model',
            self::SelfDistilling => 'Self-Distilling',
            self::Exporting => 'Exporting GGUF',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Cloning, self::Detecting, self::Extracting, self::Exporting => 'info',
            self::Distilling => 'primary',
            self::Training, self::SelfDistilling => 'warning',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }
}
