<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

enum AiMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
