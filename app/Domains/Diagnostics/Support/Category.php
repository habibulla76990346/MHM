<?php

namespace App\Domains\Diagnostics\Support;

enum Category: string
{
    case Environment = 'environment';
    case Php = 'php';
    case Filesystem = 'filesystem';
    case Database = 'database';
    case CacheSession = 'cache_session';
    case QueueCron = 'queue_cron';
    case Network = 'network';
    case Mail = 'mail';
    case AiProviders = 'ai_providers';
    case Payments = 'payments';
    case Security = 'security';
    case Application = 'application';

    public function label(): string
    {
        return match ($this) {
            self::Php => 'PHP',
            self::CacheSession => 'Cache & session',
            self::QueueCron => 'Queue & cron',
            self::AiProviders => 'AI providers',
            default => ucfirst(str_replace('_', ' ', $this->value)),
        };
    }
}
