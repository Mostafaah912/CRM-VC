<?php

declare(strict_types=1);

namespace App\Modules\Ai\Tools;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/** The ONE place a tool gets a database connection: always `ai_readonly` (role hm_ai_readonly), never the default. */
final class AiReadonlyDb
{
    public function connection(): ConnectionInterface
    {
        return DB::connection('ai_readonly');
    }
}
