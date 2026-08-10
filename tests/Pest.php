<?php

declare(strict_types=1);

/**
 * A minimal valid packet, as a mutable array, so a test can break exactly one
 * thing and assert the contract notices.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function packetArray(array $overrides = []): array
{
    return array_merge([
        'schema_version' => '1.0.0',
        'intake_id' => '01JQ8Z4M7X9K2N5P',
        'canonical_message' => 'Summarise what changed in the deploy pipeline this week.',
        'confidence' => ['level' => 'high', 'score' => 0.9, 'reasons' => []],
        'key_facts' => [
            ['statement' => 'The deploy pipeline moved to Docker on 2026-08-07.', 'source_ref' => 'gh:Asgard#22'],
        ],
        'routes_fired' => [
            ['route' => 'github.issues.search', 'status' => 'succeeded', 'summary' => 'Found 3 issues.'],
        ],
        'open_questions' => [],
        'health_ops_flags' => [],
        'relevance' => ['issues' => ['jordanpartridge/Asgard#17'], 'projects' => [], 'people' => []],
        'raw_ref' => ['store' => 'bifrost', 'id' => 'webhook_call:91827'],
    ], $overrides);
}

/**
 * A realistic sloppy human ramble — the thing the output engine must never see.
 */
function rawRamble(): string
{
    return "ok so uhh i think the deploy thing is broken again?? maybe\n"
        ."not sure if its the docker one or the other one, anyway can you\n"
        .'look at it and also what happened with that issue from tuesday';
}
