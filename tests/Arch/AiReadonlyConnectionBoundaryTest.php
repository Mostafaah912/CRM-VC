<?php

declare(strict_types=1);

use Tests\Arch\Scanner;

/**
 * P7-01 / GATE 4 (PRD §19, D7): `ai_readonly` (config/database.php) is the one connection allowed
 * to carry the AI Analyst's reads — SELECT-only, 9 PII-free tables, no write grants. `ai_admin` is
 * the separate, CREATEROLE-capable connection `AiReadonlyRoleService` uses to manage that role.
 * No other module may reference either: that would mean a non-AI feature depending on the AI
 * role's deliberately narrow grants, or reaching for a connection carrying a privilege escalation
 * (CREATEROLE) no other module has any legitimate reason to touch.
 */
it('confines the ai_readonly and ai_admin connections to the Ai module', function () {
    $violations = [];

    foreach (Scanner::modules() as $module) {
        if ($module === 'Ai') {
            continue;
        }

        $violations = [
            ...$violations,
            ...Scanner::violations(Scanner::phpFiles(["app/Modules/{$module}"]), ['/ai_readonly/', '/ai_admin/']),
        ];
    }

    expect($violations)->toBeEmpty();
});

it('never routes a controller or job through the ai_readonly or ai_admin connection', function () {
    $files = Scanner::phpFiles(['app/Http/Controllers', 'app/Jobs']);

    expect(Scanner::violations($files, ['/ai_readonly/', '/ai_admin/']))->toBeEmpty();
});
