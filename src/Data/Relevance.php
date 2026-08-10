<?php

declare(strict_types=1);

namespace TheShit\PrepPacket\Data;

use TheShit\PrepPacket\Guard;

/**
 * What this turn connects to — issues, projects, people.
 *
 * All tokens, not prose: these are handles the output engine can look up or
 * cite, and keeping them token-shaped is what stops "relevance" from becoming
 * a back door for smuggling narrative text into the packet.
 */
final readonly class Relevance
{
    /**
     * @param  list<string>  $issues  e.g. `jordanpartridge/Asgard#17`
     * @param  list<string>  $projects
     * @param  list<string>  $people
     */
    public function __construct(
        public array $issues = [],
        public array $projects = [],
        public array $people = [],
    ) {
        foreach ($issues as $index => $issue) {
            Guard::token($issue, "relevance.issues[{$index}]");
        }

        foreach ($projects as $index => $project) {
            Guard::token($project, "relevance.projects[{$index}]");
        }

        foreach ($people as $index => $person) {
            Guard::token($person, "relevance.people[{$index}]");
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Guard::noUnknownFields($data, ['issues', 'projects', 'people'], 'relevance');

        return new self(
            issues: Guard::stringList(Guard::arrayField($data, 'issues'), 'relevance.issues', 256),
            projects: Guard::stringList(Guard::arrayField($data, 'projects'), 'relevance.projects', 256),
            people: Guard::stringList(Guard::arrayField($data, 'people'), 'relevance.people', 256),
        );
    }

    public function isEmpty(): bool
    {
        return $this->issues === [] && $this->projects === [] && $this->people === [];
    }

    /**
     * @return array{issues: list<string>, projects: list<string>, people: list<string>}
     */
    public function toArray(): array
    {
        return [
            'issues' => $this->issues,
            'projects' => $this->projects,
            'people' => $this->people,
        ];
    }
}
