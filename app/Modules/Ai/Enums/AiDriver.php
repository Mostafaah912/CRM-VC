<?php

declare(strict_types=1);

namespace App\Modules\Ai\Enums;

/** Value is what is stored in ai_insights.provider (varchar(20)) and what AI_PROVIDER holds. */
enum AiDriver: string
{
    case Gemini = 'gemini';
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
}
