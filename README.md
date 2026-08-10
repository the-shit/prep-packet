# the-shit/prep-packet

The contract between the intake engine and the output engine: what Asgard hands Lexi, and — structurally, not by convention — never the raw body.

An immutable typed value object plus a versioned JSON Schema. Both sides require this package rather than each implementing a document they agree about in prose, because [the failure mode is silent](https://github.com/jordanpartridge/Asgard/issues/17): one side renames a field, the other parses it as absent, nothing errors, and the packet just quietly gets weaker.

Per [Asgard#17](https://github.com/jordanpartridge/Asgard/issues/17), serving the intake/output split in [Asgard#16](https://github.com/jordanpartridge/Asgard/issues/16).

## Install

The repo is public, but it is **not registered on Packagist**, so a bare `composer require` will not resolve it. Add the VCS repository first — this is the whole install, for Asgard (intake), Lexi (output), or anything else:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/the-shit/prep-packet" }
    ]
}
```

```bash
composer require the-shit/prep-packet:^0.1
```

Public on purpose: the packet carries no secrets, and public avoids the private-registry question entirely. Registering on Packagist would make the bare `composer require` work and is a one-line decision — it just has not been made yet.

Nothing to publish, no service provider, no config. It is a value object and a schema; `use` it and go.

### Verifying the install

```bash
php -r 'require "vendor/autoload.php"; echo TheShit\PrepPacket\Schema::VERSION;'
# 1.0.0
```

Pin against `Schema::VERSION` on both sides. A packet declaring a different version is rejected rather than parsed optimistically, which is the point — see [Versioning](#versioning).

## The shape

```php
use TheShit\PrepPacket\PrepPacket;

$packet = PrepPacket::fromJson($body);   // throws on anything wrong

$packet->canonicalMessage;   // the cleaned ask — what the output engine answers
$packet->keyFacts;           // list<KeyFact>       extracted claims
$packet->routesFired;        // list<RouteReceipt>  work intake already did
$packet->openQuestions;      // list<OpenQuestion>  what still needs judgment
$packet->healthOpsFlags;     // list<HealthOpsFlag> what must not be ignored
$packet->relevance;          // issues, projects, people
$packet->rawRef;             // POINTER to the raw. Never the raw.
$packet->intakeId;           // trace id for the turn
$packet->confidence;         // how much intake believes its own packet
```

## Raw never travels — enforced by the type

The central rule of the split: intake knows the sloppy truth, the output engine responds from prepared work. To Lexi, Jordan is always articulate, because she never sees anything else.

That rule is enforced here rather than documented, because discipline is what fails at 2am. Two mechanics:

**1. There is no field a body could live in.** `RawRef` holds a store, an id, an optional URI, a digest, a byte size, a timestamp. Nothing else. And the packet is *closed* — an unknown key throws:

```php
PrepPacket::fromArray([...packet, 'raw_body' => $ramble]);
// InvalidPrepPacket: unknown field(s) `raw_body`. The contract is closed:
// a field the schema does not define is a producer/consumer drift bug,
// not an extension point. Add it to the schema and bump the version.
```

**2. Pointer fields only accept pointer-shaped strings.** No whitespace, no line breaks, bounded length, restricted charset. Human rambles cannot be spelled that way:

```php
new RawRef(store: 'bifrost', id: "ok so uhh i think the deploy thing is broken again??");
// InvalidPrepPacket: `raw_ref.id` looks like raw content rather than a
// pointer (it contains whitespace). raw_ref is an identifier the output
// engine can hand back to intake — never the body itself.
```

`digest` and `byteSize` exist so a consumer can reason *about* the raw — has it changed, how big was it — without ever holding it. Fetching the body is intake's job, on request, with the pointer.

Prose is still allowed where prose is the product: `canonical_message`, fact statements, route summaries, questions, flag details. The rule is about references, not about all text.

## Confidence — so a weak packet can say so

The specific failure this guards against: intake half-understands the ask, the output engine answers fluently, and the reply is to a question nobody asked. Nobody catches it, because by design the output engine has no raw to compare against. The only way it can know is if the packet tells it.

```php
$packet->confidence->level;   // high | medium | low | unusable
$packet->confidence->score;   // optional 0–1
$packet->confidence->reasons; // required below `high`

if ($packet->shouldDegrade()) {
    // ask rather than answer
}
```

A reason is mandatory whenever confidence is not `high` — an unexplained "I am not sure" tells downstream to distrust the packet without telling it what to check. `"The ask mentions two unrelated projects; I picked the more recent one"` is actionable; silence is not.

`shouldDegrade()` also trips on a **blocking** open question, because intake can be certain of everything it extracted and still be missing the one fact that decides the answer:

```php
new OpenQuestion('Which environment?', QuestionAudience::Human, blocking: true);
```

Two more honesty signals:

```php
$packet->failedRoutes();  // routes that timed out / failed — why the packet is thin
$packet->urgentFlags();   // warning+critical flags that must be surfaced, not just noted
```

## Empty packets are rejected loudly

A packet with a canonical message and nothing behind it is intake reporting success it did not have:

```php
// InvalidPrepPacket: the packet carries no canonical message, no key facts,
// no routes fired and no open questions. An empty packet is an intake
// failure, not a valid handoff — emit one with confidence `unusable` and a
// reason instead.
```

Intake failing to understand the input is a real outcome and must be reportable — as an `unusable` packet with reasons, so downstream knows the turn happened and went nowhere. What is not allowed is a thin packet that *looks* fine.

Name collisions are rejected too (`routes_fired`, `health_ops_flags`, case-insensitively), because a duplicate silently overwrites its twin downstream.

## Versioning

```php
Schema::VERSION;      // '1.0.0'
Schema::path();       // resources/schema/prep-packet-v1.json
Schema::definition(); // decoded
```

Explicit from v1. A packet declaring any other version is rejected rather than parsed optimistically. Adding a field means bumping the version — the closed contract makes that a decision rather than an accident.

The PHP type is the enforcing implementation; the JSON Schema is the language-neutral statement of the same shape for non-PHP consumers. A test asserts the two agree field-for-field, required-for-required, enum-for-enum. Without that check the schema becomes stale documentation and the drift this package exists to prevent reappears inside the package itself.

## `intake_id` is the trace id

Not a parallel correlation scheme. The same id is the observability trace id and the packet key, so one turn — raw intake, routes fired, packet handoff, the reply — is a single trace spanning both apps rather than two log streams to reconcile.

## For intake (Asgard): producing a packet

Intake is the only side that constructs one. Build it with the typed constructor so the contract validates at the point of creation, not at the point of handoff:

```php
use TheShit\PrepPacket\PrepPacket;
use TheShit\PrepPacket\Data\{Confidence, KeyFact, OpenQuestion, RawRef, RouteReceipt};
use TheShit\PrepPacket\Enums\{QuestionAudience, RouteStatus};

$packet = new PrepPacket(
    intakeId: $traceId,                    // same id you hand the tracer
    canonicalMessage: $cleanedAsk,         // never the raw body
    confidence: Confidence::medium(['GitHub route timed out; issue links may be incomplete.']),
    rawRef: new RawRef(store: 'bifrost', id: "webhook_call:{$call->id}", byteSize: $call->size),
    keyFacts: [new KeyFact('The pipeline moved to Docker on 2026-08-07.', sourceRef: 'gh:Asgard#22')],
    routesFired: [new RouteReceipt('github.issues.search', RouteStatus::TimedOut)],
    openQuestions: [new OpenQuestion('Which environment?', QuestionAudience::Human, blocking: true)],
);

$wire = $packet->toJson();
```

Four things intake must get right, each enforced:

1. **`rawRef` is a pointer.** Put the archive id there, never the body. The type rejects anything whitespace-bearing, so this fails at construction rather than at the boundary.
2. **`intakeId` is the trace id**, not a second correlation scheme. Same id in the tracer, the packet, and the official record.
3. **Be honest in `confidence`.** A reason is mandatory below `high`. Half-understood asks must say so — the output engine has no raw to check against and will otherwise answer fluently and wrongly.
4. **Never emit a thin packet.** If intake could not make sense of the input, emit `Confidence::unusable([...])` with reasons. An empty packet throws.

## For output (Lexi): consuming one

```php
$packet = PrepPacket::fromJson($request->getContent());   // throws InvalidPrepPacket on anything wrong

if ($packet->shouldDegrade()) {
    // ask rather than answer — low/unusable confidence, or a blocking question
}

foreach ($packet->urgentFlags() as $flag) { /* must be surfaced, not just noted */ }

$answer = $this->respond($packet->canonicalMessage, $packet->keyFacts);
```

Catch `InvalidPrepPacket` and treat it as a failed handoff. Do not fall back to raw on a parse failure — that is the silent-degrade path #16 exists to close.

## Quality

```bash
composer test      # pest
composer lint      # pint
composer analyse   # phpstan level 5
```

Framework-free — the only runtime requirement is PHP 8.3. A contract both apps depend on should not drag a framework across the boundary with it.
