<?php

declare(strict_types=1);

namespace App\Shared\Support\Configuration;

/**
 * The nine grouping headings every environment variable belongs to, exactly
 * one of them (Requirement 44.3). The example environment file and the
 * Documentation_Set use the same headings, and a failed boot names the heading
 * of every absent variable (Requirement 44.8).
 */
enum ConfigurationGroup: string
{
    case App = 'APP';
    case Database = 'DATABASE';
    case Redis = 'REDIS';
    case Cache = 'CACHE';
    case Queue = 'QUEUE';
    case Reverb = 'REVERB';
    case Filesystem = 'FILESYSTEM';
    case Mail = 'MAIL';
    case Api = 'API';
}
