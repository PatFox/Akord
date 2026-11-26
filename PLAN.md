# Akord — Implementation Plan

Akord is a multi-party contract app built with Laravel. Parties can be individuals, teams or AI agents. Organisations contain projects, projects contain contracts, contracts contain phases, and phases contain steps. Parties comment on steps, edit them and approve them, and every change is kept in history. Editing a step cancels its approvals. When every step is approved, all required parties sign off and the contract locks; any later change goes into a new version.

This document records the agreed plan: v2, as amended by v3 and decisions D8–D10. It was recovered from planning session `01a1d025-90b4` (2026-11-12).

## Original request

> I'm working on some projects where there are multiple involved parties. For example: individuals, teams, AI agents. In some cases, these represent separate sub-projects within a larger project (e.g. the API team, the app team etc).
>
> I want a piece of software where these parties can create, view, edit shared contracts for software development.
>
> There would need to be a way to invite parties e.g. an email with a unique link for humans, and some other method for AI agents.
>
> At the top level, you'd have 'projects'. Within each project, you can create a contract.
>
> Within a contract, you can add a step (or some similar concept). A step is a small unit of work. Perhaps these could be grouped by phases. A step would have a title and a description.
>
> Parties can comment on steps, for example to ask for clarification or to suggest a correction. They can also edit the step directly. All changes would be stored as history so that anyone could view a change at any time.
>
> Once a party is happy that a step has been agreed, then can mark it as 'approved'. However if the step then changes after approval, that is rescinded and the step would need to be re-approved.
>
> This would be a web-based application. I think Laravel would be a good choice, since I'm familiar with it.

## 1. Decisions

| # | Decision |
|---|---|
| Q1 | **A party approves.** The record also stores which user or agent clicked. |
| Q2 | Approval comes from a per-contract list of required approver parties. |
| Q3 | **Only a new revision of a step's title or description cancels its approvals.** Reordering steps, moving a step to another phase, and renaming or reordering phases do not. |
| Q4 | Contracts can be locked. Any later change goes into a new version (v2). *Superseded by D8: locking now needs a final sign-off.* |
| Q5 | Steps are archived, never deleted. |
| Q6 | Agents use a REST API with tokens **and** an MCP server, with the same tokens. Agents check for updates themselves; there are no webhooks. |
| Q7 | Livewire, **with live updates**. |
| Q8 ✅ | Markdown for step descriptions and comments. |
| Q9 | Roles are owner / editor / commenter / viewer. Agents may edit and approve. |
| Q10 | Separate organisations. Owners see every contract in their organisation; everyone else sees only the contracts they were invited to. |
| D1 ✅ | "Owner" is an organisation role. Editor, commenter and viewer are roles a **party** holds on a **contract**, and every member of the party inherits it. |
| D2 ✅ | Editors and commenters can approve; viewers cannot. Only approvals from required parties count. |
| D3 ✅ | A locked contract is fully read-only, comments included. The only thing allowed is "Start new version". (Invitations were always allowed too; D14 adds closed versions to "locked" and lets the status change.) |
| D4 ✅ | Only organisation owners can invite people, attach parties to contracts, set required approvers, and start a new version. |
| D5 ✅ | User accounts are global, so an outsider can be invited to a single contract and see nothing else. |
| D6 ✅ | Each agent is its own party. |
| D7 | Automatic locking. **Replaced by D8.** |
| D8 ✅ | Once every active step is approved, **every required party signs off** on the whole contract. The last sign-off locks it. |
| D9 ✅ | Reordering or moving steps does **not** cancel sign-offs. |
| D10 ✅ | A per-contract setting, `contracts.require_human_signoff` (boolean, default false). When it is on, `SignOffContract` rejects agent tokens with `403`. |
| D11 ✅ | Spacing-only edits do **not** cancel approvals or sign-offs. The edit is still saved as a new revision so it shows in history. |
| D12 ✅ | **Database portability.** The schema and queries must work on SQLite (development and tests) and **MySQL 8**, and both are verified. PostgreSQL is a design consideration but untested. Migrations use only the schema builder, with no raw SQL. Anything one engine can't do needs a portable equivalent. See "Database portability" in §2. |
| D13 ✅ | (2026-11-19) **A new version carries over the approvals** that counted on the agreed contract, since nothing has changed yet. Withdrawn approvals, sign-offs and comments stay with the old version. So a new version starts **ready for sign-off**, and every required party signs off again. |
| D14 ✅ | (2026-11-19) **Each version has a status**: `open` (default), `complete` or `cancelled`, set by people, separate from the derived state. A version that is not open is locked like an agreed one (423) until it is reopened; invitations, the status itself and "start new version" still work. Closing and reopening keeps approvals and sign-offs and sends no notifications. Anyone who may edit steps changes it (owners, editor parties, agents with `edit`), only on the latest version. A new version starts open. The contracts list shows one row per contract (its latest visible version), open ones by default; agents' `list_contracts` also defaults to open. |
| D15 ✅ | (2026-11-19, several per version since 2026-11-20) **Working checklists.** A contract version can have several checklists, each with a name unique within the version ignoring case (e.g. one per team), of stages and items, written by an agent from the plan (its own breakdown, or a copy of the phases and steps); items can link to the contract step they come from. Owners, editor parties and agents with `edit` tick and untick items, recording who and when. They are outside the fingerprint, so they change after agreement too and never affect approvals or sign-offs; a complete or cancelled version locks them. Creating one with an existing name replaces it, which needs `replace` if items are ticked. A new version copies them with their ticks. Activity is recorded, with no emails. |
| D16 ✅ | (2026-11-21) **Archiving a step needs every required party's approval.** Archiving saves a revision with the same text that *proposes* the archive; its content hash differs from the text's, so the step's approvals stop counting and sign-offs are cancelled. The step stays in the contract until every required party approves that revision, and is then archived. Any later revision withdraws the proposal (the same text brings its earlier approvals back). Without required approvers a step is archived at once. |
| D17 ✅ | (2026-11-24) **A party's role can change after agreement.** A role decides access (who ticks checklists and changes the status, and the role the next version copies), not agreed content: it is outside the fingerprint, so changing it never touches approvals or sign-offs. Owners change roles on an agreed version that is open; complete or cancelled versions must be reopened first. Attaching, detaching, required approvers and the sign-off rules stay locked. A required approver still cannot become a viewer. |
| D18 ✅ | (2026-11-24) **Parties can be assigned to a checklist.** Owners assign any party attached to the contract to a checklist; an assigned party ticks and unticks that checklist's items whatever its role (viewers too). Agents still need the `edit` ability. Creating, replacing and adding items still need the edit ability. Assigning works when ticking does (agreed versions included, not complete or cancelled ones), is recorded as activity without emails, survives replacing the checklist by name, is copied into a new version, and ends when the party is detached. |
| D19 ✅ | (2026-11-24) **Owners can delete a cancelled contract.** Only from its latest version, and only once that version is cancelled; it deletes every version of the contract with everything recorded on them (phases, steps, revisions, comments, approvals, sign-offs, invitations, checklists, activity and the in-app notifications about it). Parties, projects and people stay. Nothing records the deletion, and it cannot be undone. Web only: agents cannot delete (owner-only, like roles). |

## 2. Data model

Every entity's `id`, and every foreign key to one, is a random 16-character string (Phase 13). Join-table rows keep internal integer ids.

| Table | Key fields | Notes |
|---|---|---|
| `organisations` | name, slug | The top tenancy boundary |
| `organisation_user` | organisation_id, user_id, role (`owner`) | Records org owners. Guests have no row. |
| `users` | standard | Global accounts |
| `projects` | organisation_id, name | |
| `parties` | organisation_id, name, type (`individual`/`team`/`agent`), agent_abilities (agents only) | Org-level, so a party can be reused across contracts. An agent party's abilities (`read`, `comment`, `edit`, `approve`, `create`) apply to all its credentials |
| `party_user` | party_id, user_id | Agents have no rows here |
| agent tokens | Sanctum `personal_access_tokens`, attached to the agent party | The party's abilities apply, not the token's |
| `agent_claim_codes` | party_id, code_hash, expires_at, claimed_at | One-time code an agent exchanges for a token |
| `agent_connection_links` | party_id, token_hash, created_by, expires_at, used_at | One-time link with which an agent fetches its party's MCP config |
| `contracts` | project_id, title, version, previous_version_id, agreed_at, agreed_fingerprint, require_human_signoff (bool, default false), status (`open`/`complete`/`cancelled`, default `open`) | `status` is set by people (D14). There's no state column: agreed means `agreed_at` is set; `draft` and `ready_for_signoff` are worked out when needed. |
| `contract_party` | contract_id, party_id, role (editor/commenter/viewer), is_required_approver | Controls access and approval |
| `invitations` | organisation_id, contract_id, party_id, email, token_hash, expires_at, accepted_at, invited_by | Invitations are per contract |
| `phases` | contract_id, title, position | |
| `steps` | phase_id, position, current_revision_id, archived_at, origin_step_id | `origin_step_id` links the same step across versions |
| `step_revisions` | step_id, number, title, description_md, content_hash, author_party_id, author_user_id, change_note | Append-only, full text in every row. Unique on (step_id, number). `content_hash` hashes the title and description after whitespace is normalised (D11). |
| `comments` | step_id, step_revision_id, parent_id, author_party_id, author_user_id, kind (`question`/`suggestion`/`general`), body_md, resolved_at | Linked to the revision it discusses |
| `approvals` | step_id, step_revision_id, party_id, user_id, withdrawn_at, active (generated) | At most one active approval per (step, revision, party), enforced in the database |
| `contract_signoffs` | contract_id, party_id, user_id (null for agents), fingerprint, created_at, withdrawn_at, active (generated) | At most one active sign-off per (contract, party, fingerprint), enforced in the database |
| `activity_events` | organisation_id, contract_id, position, actor, type, subject, payload, seq | Single timeline. `seq` (set after commit) is the cursor for `activity?since=`; `position` (1, 2, 3… per contract) orders a contract's events. |
| `checklists` | contract_id, name (≤ 60, unique per contract), created_by_party_id, created_by_user_id | Several per contract version, one tab each (D15) |
| `checklist_stages` | checklist_id, title, position | |
| `checklist_items` | checklist_stage_id, position, title, notes, step_id (nullable), checked_at, checked_by_party_id, checked_by_user_id | Ticked when `checked_at` is set; `step_id` links the contract step the item came from |
| `checklist_party` | checklist_id, party_id | Parties assigned to a checklist, who tick its items whatever their role (D18) |

### Database portability (D12)

- **One active row per key, without partial indexes.** MySQL has no `CREATE UNIQUE INDEX … WHERE`. `approvals` and `contract_signoffs` instead have a stored generated column, `active` (`case when withdrawn_at is null then true end`), which is true while the row is active and `NULL` once withdrawn. The unique index includes `active`. All three engines treat NULLs as distinct in a unique index, so withdrawn rows never conflict. Application code never writes `active`: don't set it and don't `replicate()` these models.
- **Case-insensitive text on MySQL.** The default `utf8mb4_*_ci` collations compare text case-insensitively, while SQLite and PostgreSQL compare case-sensitively. Normalise emails to lowercase on write (users and invitations) and match on the normalised value, so invitation email matching (Phase 6) behaves the same everywhere. Hashes and fingerprints are lowercase hex, so they're unaffected.
- **Row locks are real on MySQL and PostgreSQL.** SQLite ignores `lockForUpdate()` and runs writes one at a time, so SQLite tests can't prove lock-based correctness. Every write goes through `ContractTransaction`, whose **first statement** locks the contract row. That gives one lock order (no deadlocks), and on MySQL REPEATABLE READ every later plain read in the transaction sees all work committed before the lock. Code inside a transaction must therefore never read before taking that lock, and must re-read related rows rather than trust models loaded earlier (`Contract::findStep()` / `findPhase()`). Concurrency tests live in `tests/Concurrency`, run only on a server database, and are skipped on SQLite.
- **Activity cursor (decided in Phase 3, ordering changed in Phase 13).** Events are written inside their transactions, so paging by insertion can skip one that commits late. Events are inserted with `seq = NULL`. After each write commits, `ActivitySequencer` runs a short transaction behind the single `activity_sequencer` row lock and numbers every committed, unnumbered event in its contract's order (`contract_id`, `position`). The feed pages by `seq` (`ActivityEvent::after($cursor)`). Each sequencer run sees only committed rows and commits before the next starts, so a late committer always gets a higher `seq` than anything a reader has seen. Only this small step is serialised, not every write. Per-organisation locks were rejected because a user's feed can span organisations. `activity?since=` (Phase 7) must call `ActivitySequencer::run()` before reading, to number any event whose post-commit run never happened (for example, a crashed process).
- **Running the suite on MySQL:** start a throwaway server (`docker run -d --rm --name akord-mysql-test -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=akord_test -p 127.0.0.1:33061:3306 --tmpfs /var/lib/mysql mysql:8.4`). Then run `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33061 DB_DATABASE=akord_test DB_USERNAME=root DB_PASSWORD=secret php artisan test`. Pass the variables per command rather than `export`ing them, or later runs silently use MySQL too.
- **CI** (`.github/workflows/tests.yml`) runs two jobs on every push to `main` and every pull request:
  - `ci`: the full gate on SQLite (`composer ci:check`: Pint, PHPStan, tests; the concurrency test is skipped).
  - `mysql`: the test suite on a MySQL 8.4 service container, including `tests/Concurrency`. It uses `--fail-on-skipped`, so a misconfigured job can't quietly fall back to SQLite and pass.

### Rules

- **When an approval counts:** it has not been withdrawn, and its revision has the same `content_hash` as the step's current revision. An edit that changes the content creates a revision with a new hash, which cancels older approvals without updating any rows. A spacing-only edit keeps the same hash, so approvals still count (D11).
- **Whitespace normalisation:** trim, convert line endings to `\n`, and collapse every run of whitespace to a single space before hashing. Text is always stored exactly as written; normalisation is used only to compute the hash.
- **Step approved:** every required party has a current approval.
- **Simultaneous edits:** `ReviseStep` requires the revision number the editor started from. If it is stale the edit fails: `409` from the API, a "changed while you were editing" banner in Livewire.
- **Contract states:**
  - `draft`: the default.
  - `ready_for_signoff`: there is at least one active step, and all active steps are approved by all required parties.
  - `agreed` (locked): every required party has signed off on the current fingerprint.
  - Any step content edit, new step, archive proposal, or change to required approvers sends the contract back to `draft`. Spacing-only edits do not (D11).
- **Fingerprint:** a hash of the set of (active step, current revision `content_hash`) pairs plus the set of required parties. Order is ignored and archived steps are excluded. A sign-off counts only if its fingerprint matches the current one. Because the fingerprint uses `content_hash`, spacing-only edits do not change it.

| Change | Cancels step approvals | Cancels sign-offs |
|---|---|---|
| Step title or description edited (content change) | Yes | Yes |
| Step edited with spacing-only changes | No | No |
| Step added | n/a | Yes (back to draft) |
| Archive proposed (D16) | Yes (approvals of its text stop counting) | Yes |
| Archive proposal withdrawn by a new revision | Approvals of the proposal stop counting; earlier approvals of the same text count again | Yes |
| Proposal approved by every required party: step archived | n/a | Nothing left to cancel (the contract wasn't ready while it was pending) |
| Required party added or removed | No | Yes |
| Step reordered or moved to another phase | No | No |
| Phase renamed or reordered | No | No |
| Comment added | No | No |

## 3. Access rules

- **One rule everywhere:** `ContractPolicy` plus a `Contract::visibleTo($actor)` scope.
  - Organisation owner: full access.
  - Anyone else: must belong to a party attached to the contract. That party's role decides what they can do.
  - Agents follow the same rule, further limited by their party's abilities.
- **Contract status (D14):** changed with the edit ability, like steps: owners acting for their own party, editor parties, and agents with `edit`. Commenters and viewers cannot.
- **Projects list:** shows only projects with at least one visible contract; owners see all of them.
- **No global tenant scope.** Every query starts from the actor's visible records, and nested route bindings are checked (a step must belong to the contract in the URL). A global scope was rejected because it tends to be bypassed in jobs and MCP calls.

## 4. Implementation phases

### Phase 1: scaffold ✅ done 2026-11-12

- Livewire starter kit `dev-main` on Laravel 13.42, with Livewire 4.4, Flux 2.21, Blaze, and Fortify. All Fortify features are on: registration, password reset, email verification, 2FA, and passkeys.
- Pest 4.7 with `pest-plugin-laravel`. Pest 5 needs PHP 8.4. `tests/Pest.php` adds `RefreshDatabase` to everything under `tests/Feature`.
- `.env` / `.env.example`: `APP_NAME=Akord`, SQLite, database queue, cache and sessions. Mail goes over SMTP to Mailpit on `127.0.0.1:1025` (Mailpit itself is not installed).
- Reverb and Echo (`install:broadcasting --reverb`, `BROADCAST_CONNECTION=reverb`, `resources/js/echo.js`).
- Sanctum (`install:api`: `routes/api.php` and the `personal_access_tokens` migration). `HasApiTokens` is deliberately **not** on `User`; it goes on the agent `Party` in Phase 7.
- `laravel/mcp` ^1.2, `league/commonmark` ^2.10, `jfcherng/php-diff` ^7.0.
- **MCP authentication:** `Mcp::web()` returns a normal route, so `->middleware(['auth:sanctum', ...])` protects it with the same Sanctum tokens as the REST API. Generators: `make:mcp-server`, `make:mcp-tool`, `make:mcp-resource`. Debugging: `mcp:inspector`.
- **Verified:** `php artisan test` gives 38 passed. `php artisan about` shows broadcasting=reverb, cache=database, database=sqlite, mail=smtp, queue=database, session=database.

### Phase 2: data layer ✅ done 2026-11-12

- **Migrations:** `database/migrations/2026_11_12_1600xx_*`, one per aggregate: organisations, projects, parties, contracts, invitations, phases and steps, comments, approvals, sign-offs, activity events.
- **Database-enforced invariants beyond the §2 table:**
  - Composite foreign keys keep related rows on the same step:
    - `steps (current_revision_id, id) → step_revisions (id, step_id)`: a step's current revision is one of its own revisions.
    - Comments and approvals: `(step_revision_id, step_id) → step_revisions (id, step_id)`.
    - Replies: `(parent_id, step_id) → comments (id, step_id)`.
  - `contracts.previous_version_id` is unique, so a version can be continued only once.
  - At most one active approval or sign-off per key, enforced by a unique index that includes the generated `active` column (see "Database portability"):
    - `approvals (step_id, step_revision_id, party_id, active)`
    - `contract_signoffs (contract_id, party_id, fingerprint, active)`
  - **Delete behaviour:**
    - References to users in history are `nullOnDelete`, so the kit's delete-account flow still works.
    - Membership pivots cascade.
    - Parties, organisations and contracts are restricted (history is never deleted). The one exception is `DeleteContract` (D19), which removes a cancelled contract's rows itself, children first.
- **Enums** (`app/Enums`): `OrganisationRole`, `PartyType`, `ContractRole`, `CommentKind`. Stored as strings and cast on the models.
- **Models** (`app/Models`): `Organisation`, `Project`, `Party`, `Contract`, `ContractParty` (pivot), `Invitation`, `AgentClaimCode`, `Phase`, `Step`, `StepRevision`, `Comment`, `Approval`, `ContractSignoff`, `ActivityEvent`. `User` gains `organisations()` and `parties()`.
  - `StepRevision` computes `content_hash` when created (`StepRevision::contentHash()`, D11). It throws on update or delete (append-only).
  - Scopes:
    - `Contract::visibleTo(User|Party)` and `Project::visibleTo(User|Party)`, following the access rules in §3.
    - `Step::active()`, `Approval::active()`, `ContractSignoff::active()`.
- **Factories** for every model. `StepFactory` creates revision 1 and sets it as current. `PartyFactory` has `team()` and `agent()` states.
- **Tests:** `tests/Feature/DataLayer/{VisibilityTest,IntegrityTest}.php`, 17 tests. They cover visibility for owners, members, agents and outsiders across organisations, and each database invariant above, checking that valid rows are accepted and invalid ones rejected.
- **Left to Phase 3 (actions):** consistency checks that span organisations, such as a party attached to a contract belonging to the contract's organisation, or an invitation's organisation matching its contract's. The database doesn't enforce these.
- `config/sanctum.php`: cast `env()` to string so the kit's `composer test` gate (Pint, Larastan level 7, Pest) passes.
- **Verified:** `composer test` gives Pint pass, Larastan 0 errors, and 55 tests passed. The same 55 tests pass on MySQL 8.4, and MySQL's schema shows the generated `active` columns, the new unique indexes, and every composite foreign key, including the circular one between `steps` and `step_revisions`. `migrate:reset` followed by `migrate` works on both SQLite and MySQL.

### Phase 3: actions ✅ done 2026-11-13

- **Actions** (`app/Actions/{Phases,Steps,Comments,Approvals,Contracts}`), each with a `handle(Actor $actor, …)` method:
  - Phases: `CreatePhase`, `RenamePhase`, `ReorderPhases`.
  - Steps: `CreateStep`, `ReviseStep`, `ReorderSteps`, `MoveStep`, `ArchiveStep`.
  - Comments: `AddComment`, `ResolveComment`.
  - Approvals: `ApproveStep`, `WithdrawApproval`.
  - Contracts: `AttachParty`, `SetRequiredApprover`, `SignOffContract`, `WithdrawSignOff`, `StartNewVersion`, and the read-only `ContractReadiness`.
  - The web UI, the API and MCP must all call these.
- **`Actor`** (`app/Support/Actor.php`): `Actor::user($user, $party)` is a person acting for one of their parties; the party may be left out only for owner administration. `Actor::agent($party, $tokenAbilities)` is an agent.
- **Access rules** (`ContractAccess`):
  - **Owners** may edit and comment as any party of theirs in the organisation, even one not attached to the contract. Approval and sign-off have no owner bypass: they belong to parties attached as editor or commenter (D2).
  - **Content writes** need a party. Without one the action returns 422.
  - **Agents** also need the matching token ability (`read`, `edit`, `comment`, `approve`).
- **Error mapping:** each failure is an exception Laravel renders with the right status:
  - `ModelNotFoundException`: 404. The contract isn't visible to the actor, or a step, phase or party belongs elsewhere.
  - `AuthorizationException`: 403.
  - `ContractLockedException`: 423.
  - `StaleRevisionException` and `StaleFingerprintException`: 409.
  - `InvalidActionException`: 422.
- **Order of checks:** 404 → 403 → 423 → action-specific checks. For sign-off: required approver (403) → D10 (403) → stale fingerprint (409) → not ready (422).
- **Plan refinements made while implementing:**
  - `ApproveStep` takes the revision number the approver reviewed. It returns 409 if the content changed since then, and succeeds if the only difference is spacing (D11). This is the same "approve what you saw" rule as sign-off.
  - Ready for sign-off also requires **at least one required approver**, otherwise nobody could ever sign off.
  - States are always worked out from the data. "Back to draft" in §2 means approvals stop counting and sign-offs are invalidated. Archiving the last unapproved step can make a contract ready (since D16, once every required party approves the proposal).
  - A sign-off from a non-required party is rejected (403) rather than recorded and ignored.
  - `StartNewVersion` also copies parties, roles, required approvers and `require_human_signoff`, because otherwise nobody but owners could see v2. Steps keep their original author, and their change note says which version they came from. Only agreed contracts can get a new version, and only once.
  - Since D13 (2026-11-19) it also copies each step's counting approvals: active, and for a revision with the step's current content hash. They go onto revision 1 of the new step, with the same party and user.
  - The new version then records `contract.ready` on its own timeline, so the feed shows it and the required parties are notified as usual. `contract.version_started` stays on the old version.
  - `AttachParty` also changes an existing party's role. A party from another organisation is reported as missing (404).
  - Identical edits, repeat approvals, repeat sign-offs, re-archiving and re-proposing an archive are no-ops.
- **Activity and events:**
  - Every change writes one `activity_events` row through `ActivityLog`, using these types: `phase.created|renamed`, `phases.reordered`, `step.created|revised|moved|archive_proposed|archived`, `steps.reordered`, `comment.added|resolved`, `approval.created|withdrawn`, `party.attached|role_changed|required_changed`, `signoff.created|withdrawn`, `signoffs.invalidated`, `contract.ready|no_longer_ready|agreed|version_started`.
  - Each row fires **one** event class, `App\Events\ActivityRecorded`, which is dispatched only after the transaction commits. This replaces the per-type event classes in Phase 5.
  - Polymorphic columns store stable short names (`step`, `party`, `user`, …; `Relation::enforceMorphMap`) instead of class names.
- **Email normalisation:** the `App\Casts\NormalisedEmail` cast on `User::email` and `Invitation::email` trims and lowercases on every write. Fortify's `lowercase_usernames` already normalises login, registration, password reset and profile updates. Lookups must call `NormalisedEmail::normalise()`.
- **Migration** `2026_11_13_090000_add_sequence_to_activity_events`: adds `activity_events.seq` and the `activity_sequencer` table.
- **Tests:**
  - `tests/Feature/Actions/*` covers plan tests 1, 2, 3, 4 (every row of the §2 change table), 5, 7, 8 and 13. It also covers roles and token abilities, outsiders getting 404, owner rules, the activity cursor, and the pinned fingerprint (`tests/Unit/ContractFingerprintTest.php`).
  - `tests/Feature/EmailNormalisationTest.php` covers email normalisation.
  - `tests/Concurrency/ConcurrentSignOffTest.php` (test 6) runs two real processes that sign off at the same instant, five rounds.
  - Shared fixture: `tests/Support/Scenario.php`.
- **Verified:**
  - `composer test` on SQLite: Pint pass, Larastan 0 errors, 115 passed plus 1 skipped (concurrency).
  - The same gate on MySQL 8.4: 116 passed.
  - The concurrency test was checked by removing the contract lock: it then failed in the first round, because neither sign-off saw the other and the contract was never agreed. With the lock restored, it passed.
- **Not in this phase:** creating organisations, projects, contracts and parties, and detaching parties. These were done in Phase 4.

### Phase 4: Livewire UI ✅ done 2026-11-13

- **Pages** (Livewire 4 single-file pages under `resources/views/pages`, routes in `routes/web.php`, all behind `auth` + `verified`):
  - `/dashboard`: your organisations (owned or invited), and a form to create one.
  - `/orgs/{slug}`: projects you can see, an organisation switcher, and an owner form for new projects.
  - `/orgs/{slug}/parties` (owners): create individual, team or agent parties, and join or leave them.
  - `/orgs/{slug}/projects/{id}`: contracts with their state and superseded versions, and an owner form for new contracts.
  - `/contracts/{id}`:
    - Header panel: state badge, outstanding approvals, required parties and who has signed off, and **Sign off** (it submits the fingerprint of the state on screen) or Withdraw.
    - Phases: up/down buttons and rename.
    - Steps: tables that double as the **approval matrix** (step × required party). Steps can be dragged to reorder within a phase or to move between phases (Livewire 4 `wire:sort` with handles; checked in a real browser).
    - Inline "add step" and "add phase" forms, the locked banner with "Start new version", and links between versions.
  - `/contracts/{id}/steps/{id}` (scoped binding: a step from another contract is 404). **Removed 2026-11-19**: everything below moved into the contract page (see "Change: steps on the contract page").
    - Approvals, and **Approve revision N** for the revision on screen.
    - The "your approval was cancelled" notice.
    - A Markdown editor with Write/Preview.
    - **Edit conflicts:** a "changed while you were editing" banner keeps the draft and shows the latest text. "Edit on top of revision N" then saves.
    - Archive, comment threads (question/suggestion/general, replies, resolve), and revision history (marking spacing-only revisions) with a word-level diff between any two revisions.
  - `/contracts/{id}/settings` (owners): attached parties with role selects, required-approver toggles and detach; attach a party; the `require_human_signoff` switch; the version list and "Start new version".
- **Acting as:** `App\Support\ActingParties` lists the parties a person may act for on a contract. These are their attached parties, best role first, plus, for owners, their other parties in the organisation. The choice is remembered per contract in the session and re-validated on every request. Pages show a selector when there is more than one.
- **Shared pieces:**
  - `App\Livewire\Concerns\ActsOnContract` reloads and re-authorises the contract on every request.
  - `RunsActions` turns 403/404/409/422/423 into toasts.
  - `ContractPolicy` (`view` → 404 when not visible, `administer` → 403 for non-owners).
  - `Organisation::visibleTo()`.
  - `<x-markdown>`, `<x-contract-breadcrumbs>`, `<x-acting-as>`.
- **Markdown:** `App\Support\Markdown` (GitHub-flavoured CommonMark, `html_input: strip`, `allow_unsafe_links: false`), styled with `@tailwindcss/typography`. **Diffs:** `App\Support\RevisionDiff` (`jfcherng/php-diff`, Combined renderer, word level, escaped).
- **New actions:**
  - `Organisations/{CreateOrganisation, CreateProject, CreateContract, CreateParty, SetOwnPartyMembership}`. `CreateOrganisation` makes the creator owner and gives them a personal party, because content edits are always made as a party.
  - `Contracts/{DetachParty, SetHumanSignOffRequired}`. A required approver must be un-required before it can be detached.
  - Organisation-level actions are owner-only through `OrganisationAccess::ensureOwner()` (404 if not visible, 403 if not owner). They write no activity rows, because the timeline is per contract.
- **Demo data:** `php artisan db:seed --class=DemoSeeder` builds an organisation through the real actions: a draft "Payments API" contract with revisions, comments and partial approvals, and an agreed "Auth integration" contract. Logins are `test@example.com` (owner), `alice@example.com` (API team) and `bob@example.com` (App team), all with password `password`.
- **Deferred to the phase that builds the feature:**
  - **Invitation and agent screens** belong in Phases 6 and 7. Until then, owners add only themselves to parties; the demo seeder sets other members directly.
  - **Disabling Sign off on a live fingerprint change** needs Phase 5. Until then, a stale sign-off is refused (409) with a toast, and the page re-renders the new state.
- **Tests:** `tests/Feature/Pages/*`, 23 tests:
  - Plan test 9 for web routes: outsiders get 404 on every page, members get 403 on owner screens, a step under the wrong contract gets 404, guests are redirected.
  - Owner creation flows.
  - Sign-off from the page, and a stale on-screen state recording nothing.
  - Drag reorder and move; acting-as switching limited to the person's own parties; viewers see no edit controls.
  - Plan test 8 in Livewire: conflict banner, draft kept, rebase-and-save.
  - The cancelled-approval notice.
  - Markdown safety (no raw HTML, `javascript:` links, `onerror`, `iframe`); diff highlighting and escaping; comment, reply and resolve.
  - Settings: roles, required approvers, detach rules, the human sign-off switch, a new version.
- **Verified:** `composer test` on SQLite gives Pint pass, Larastan 0 errors, 138 passed plus 1 skipped. On MySQL 8.4, all 139 pass. Every page was checked in a browser against the demo data, including a real drag between phases saved to the database and the editor preview stripping `<script>` and `javascript:` links.
- PHPStan ignores `trait.unused` for `app/Livewire/Concerns/*`: those traits are used only by single-file pages, which it cannot parse.

### Phase 5: live updates ✅ done 2026-11-13

- **Broadcasting:**
  - `App\Events\ActivityRecorded` is `ShouldBroadcast` + `ShouldDispatchAfterCommit`. It is queued after commit, so an unavailable Reverb never fails an action.
  - Channel: `PrivateChannel('contract.{id}')`. Name: `activity`.
  - Payload: ids and type only (`id`, `type`, `contract_id`, `subject_type`, `subject_id`), never contract text. Pages reload what they show through their normal access checks.
  - Delivery needs the queue worker, which `composer dev` already runs, and Reverb.
- **Channel authorisation** (`routes/channels.php`): `contract.{contract}` allows anyone `ContractPolicy::view` allows, so outsiders get 403 from `/broadcasting/auth`. Agents don't use websockets; they poll `activity?since=` (Q6, Phase 7).
- **Contract page:**
  - Listens on `echo-private:contract.{id},.activity` and re-renders on every event.
  - If the fingerprint moved away from the one the person was reviewing, the reviewed fingerprint is frozen, and **Sign off** becomes "Contract changed; review again". A callout lists what changed and who changed it.
  - "I have reviewed the changes" accepts the new state. A forced sign-off while frozen would submit the old fingerprint and get 409.
  - Changes that leave the fingerprint alone (approvals, comments, reordering) just update the page.
- **Step page:** re-renders live. If someone saves a revision while this person is editing, the conflict banner appears immediately, with the draft kept. (Since 2026-11-19 this is the step component on the contract page, refreshed through `step-refresh.{id}`.)
- `App\Support\ActivityDescription` turns an activity row into a sentence ("Alice Archer edited “Build the API”") for the change list. Phase 8 notifications can reuse it.
- **Not live:** organisation and project lists, which still need a reload.
- **Tests:** `tests/Feature/LiveUpdatesTest.php`:
  - Channel authorisation through a real `/broadcasting/auth` request with the Reverb driver: a member gets 200, an outsider 403.
  - The broadcast's channel, name and text-free payload.
  - The review gate holding sign-off until acknowledged.
  - An approval elsewhere updating the page without the gate.
  - The early edit conflict.
  - The Livewire tests dispatch the real `echo-private:contract.{id},.activity` event name.
- **Verified:**
  - `composer test` on SQLite: Pint pass, Larastan 0 errors, 143 passed plus 1 skipped.
  - MySQL 8.4: 144 passed.
  - **End to end in a browser** (queue worker → Reverb → Echo → Livewire): with Alice's page open, approvals made elsewhere switched it to "Ready for sign-off" without a reload. A step added by the owner then showed the gate with "Test User added step “Late addition”".

### Phase 6: human invitations ✅ done 2026-11-13

- **Inviting** (owners, from contract settings):
  - `InviteToContract(owner, contract, party, email)`. The email is normalised (`NormalisedEmail`).
  - The party must be attached to the contract and must not be an agent; agents use claim codes (Phase 7).
  - Refused (422) when the person is already a member, or already has a pending invitation (resend it instead).
  - Allowed on agreed contracts: joining a party doesn't change the agreed content.
  - Records `invitation.sent`, without the email address, because activity is visible to everyone on the contract.
- **The link:** `/invitations/{token}`. The token is random (64 characters, about 380 bits); only `sha256(token)` is stored. That secret is what makes the link unforgeable; a Laravel URL signature would add nothing, because the database lookup also enforces expiry and single use. Links expire after `InviteToContract::VALID_FOR_DAYS` (7) days and work once.
- **Mail:** `App\Mail\InvitationMail` (Markdown, `resources/views/mail/invitation.blade.php`) is sent **synchronously after commit, never queued**, so the raw token never reaches the jobs or failed-jobs tables. If sending fails, the invitation stays pending and the settings page tells the owner to Resend.
- **Resend** issues a new token and a new expiry, so the old link stops working. **Cancel** sets `revoked_at`. A migration adds `invitations.revoked_at` and `accepted_user_id`.
- **Accepting:** `AcceptInvitation(user, token)` locks the contract row first, then the invitation.
  - Unknown link: 404. Used, cancelled or expired: 410 (`InvitationUnavailableException`). Signed-in email (normalised) different from the invited one: 403.
  - On success the person joins the party, the invitation is marked accepted by that user, and `invitation.accepted` is recorded.
- **Page** (public, auth layout):
  - Guests see the invitation and "Log in" / "Create an account". The page sets `url.intended`, so they come back to it after login or registration.
  - Signed-in people with the right email see "Accept invitation", which leads to the contract. The usual email verification still applies before the contract opens.
  - Someone signed in with a different email is told so and offered Log out.
- **Scope (D1 vs D5):** accepting grants membership of the party, so the person sees every contract the party is attached to. The invite form warns when the chosen party is on other contracts. To share a single contract, invite into a party attached only to that contract.
- **Tests:** `tests/Feature/InvitationTest.php`, 9 tests:
  - Plan test 11: a valid link joins the right party and contract (any capitalisation); used, expired and cancelled links get 410, an unknown link 404, a wrong account 403, and none of them change anything.
  - Only the hash is stored. Resend makes the old link unusable.
  - Owner-only, and agent, unattached, existing-member and duplicate invitations are refused.
  - A guest registers from the link and comes back to accept. A wrong-account warning. A mail-transport failure keeps the invitation.
- **Verified:**
  - `composer test` on SQLite: Pint pass, Larastan 0 errors, 152 passed plus 1 skipped.
  - End to end with a real email (Mailpit): the owner invited carol@example.com into "Guest reviewer". The email arrived with its link; Carol opened it as a guest, registered, came back, accepted, verified her email, and saw only that contract (the other two returned 404). Reopening the link said it had already been used.
- **Local mail:** this machine's `.env` sends SMTP to the Mailpit server at `192.168.1.205:1025` (no username or password). `.env.example` keeps the generic `127.0.0.1:1025`; for a local Mailpit, run `docker run -d --rm --name akord-mailpit -p 127.0.0.1:1025:1025 -p 127.0.0.1:8026:8025 axllent/mailpit`.

### Phase 7: agents ✅ done 2026-11-13

- **Identity:** agents authenticate as their agent `Party`. `Party` uses Sanctum's `HasApiTokens` and implements `Authenticatable`, which `laravel/mcp` requires of the caller; parties have no password and cannot log in to the web app. `config/sanctum.php` sets `guard => []`, so the API accepts bearer tokens only, never browser sessions.
- **Onboarding:**
  - On `/orgs/{slug}/parties/{party}/agent` (owners only, agent parties only), the owner picks abilities. `read` is always granted; `comment`, `edit` and `approve` (which covers sign-off) are optional.
  - Generating a claim code (`AKD-XXXXX-XXXXX-XXXXX-XXXXX`, about 100 bits, no ambiguous characters) shows it **once**; only its hash is stored. It expires after `akord.agents.claim_code_hours` (24) hours.
  - The agent calls `POST /api/v1/agent/claim {code, name}`. Case, spaces and dashes in the code are ignored, and the code works once (row locked; unknown 404, used or expired 410). The reply holds the token, its abilities, the party, and the API and MCP URLs.
  - The page lists recent codes, and each token's abilities and last use, with **Revoke** (the next request gets 401).
- **REST API** (`routes/api.php`, `app/Http/Controllers/Api/V1/*`), bearer token, `throttle:agent`:
  - `GET agent` (who am I, abilities), `GET contracts`, `GET contracts/{id}[?format=md]`, `GET contracts/{id}/status`.
  - `GET contracts/{id}/steps/{id}`, `PUT contracts/{id}/steps/{id} {base_revision, title, description_md, change_note?}` (409 if stale), `GET …/revisions`, `GET …/diff?from=&to=` (unified diff).
  - `POST …/comments {kind, body_md, parent_id?}`, `POST|DELETE …/approval {revision}` (409 if the content changed since that revision).
  - `POST contracts/{id}/signoffs {fingerprint}` (409/422/423/403), `DELETE contracts/{id}/signoffs/mine`.
  - `GET activity?since=&limit=`: runs `ActivitySequencer` first, pages by `seq`, returns `next_cursor`.
  - Replies are `{"data": …, "notice": …}`; errors are JSON with the status codes above (401 for no or revoked token, 404 for contracts the agent can't see).
- **MCP server** (`routes/ai.php` → `POST /mcp`, `app/Mcp/Servers/ContractServer.php`, `app/Mcp/Tools/*`), same tokens and limiter:
  - Read tools (annotated read-only): `list_contracts`, `get_contract` (json or markdown), `get_contract_status`, `get_step`, `get_step_history` (optional diff), `get_activity_since`.
  - Write tools: `revise_step`, `add_comment`, `approve_step`, `withdraw_approval`, `sign_off_contract`, `withdraw_signoff`.
  - The server's instructions explain approving and signing off what was reviewed. Refusals come back as tool errors prefixed with the status ("409: …").
- **One implementation:** `App\Support\Agents\AgentApi` performs every operation for both REST and MCP, through the Phase 3 actions. `AgentPresenter` shapes the data, `ContractMarkdown` builds the export (since 2026-11-19 `App\Support\ContractPlan`, the plan document), and `AgentActor` turns a token into `Actor::agent($party, $token->abilities)`. Abilities are checked per action (403), on top of the party's role on each contract.
- **Prompt injection:** everything participants wrote is nested under `content` keys, and REST replies carry a notice that those fields are data. MCP replies start with the notice and put the data inside `<contract-data>…</contract-data>`, encoded with `JSON_HEX_TAG`, so text containing `</contract-data>` cannot close the block.
- **Rate limits** (`AppServiceProvider`): `agent` allows `akord.agents.requests_per_minute` (120) per agent party across REST and MCP; `agent-claim` allows 10 per minute per IP. `config/akord.php` holds both settings.
- **UI:** the parties page links agent parties to Agent access. Agent-written comments and revisions show the party once, with an "Agent" badge.
- **Tooling:** `composer types:check` now runs PHPStan with `--memory-limit=1G`, because 128 MB was no longer enough.
- **Tests:** `tests/Feature/Agents/*`, 22 tests. They use real bearer tokens over HTTP (`tests/Support/AgentClient.php`, which resets auth guards between requests so revocation is really tested):
  - Plan test 12: a claim code works once, ignoring case and dashes; expired codes get 410, unknown codes 404; a revoked token gets 401.
  - Plan test 10: a missing ability gives 403 over REST and MCP.
  - Plan test 9 for the API: an agent outside the contract gets 404 on contract, status, step, sign-off and MCP, and an empty list and activity feed.
  - A browser session can't use the API. Owner-only codes and page. The code is shown once, and revoking works from the page.
  - Per-agent rate limits.
  - REST flows: reading with `content` and the notice, the Markdown export, approval conflicts, revise and comment, the diff, sign-off 422/409/201/423, D10 403, the activity cursor.
  - MCP: the 12 tools listed, the `</contract-data>` escape, errors with statuses, 401 without a token.
- **Verified:**
  - `composer test` on SQLite: Pint pass, Larastan 0 errors, 174 passed plus 1 skipped.
  - End to end against the running app: claimed a token for "Review bot" with curl (lowercase, no dashes); a second claim got 410. Listed contracts over REST and over MCP JSON-RPC (handshake, tools listed, a call). The bot commented through MCP, the owner saw it on the step page, and revoking on the Agent access page made REST and MCP return 401.

### Phase 7b: MCP sign-in (OAuth) and the omp plugin ✅ done 2026-11-13

Replaces "claim a code with curl, then hand-edit `mcp.json`" for MCP clients. Claim codes stay for REST agents and clients without a browser.

- **OAuth server:** Laravel Passport 13 issues the tokens; `laravel/mcp`'s `Mcp::oauthRoutes()` (`routes/ai.php`) adds discovery (`/.well-known/oauth-protected-resource/mcp`, `/.well-known/oauth-authorization-server`) and dynamic client registration (`POST /oauth/register`, public clients, PKCE S256). An unauthenticated `/mcp` call gets 401 with `WWW-Authenticate: Bearer … resource_metadata="…"`, so standard MCP clients discover the rest. Access tokens last a day, refresh tokens 60 days (`AppServiceProvider::configureOAuth`).
- **Who the token is:** OAuth tokens belong to the owner who approved them (guard `api`, driver `passport`; `User` uses Passport's `HasApiTokens` and `OAuthenticatable`). A new `agent_connections` row (`oauth_client_id`, `party_id`, `approved_by`, `abilities`, `last_used_at`, `revoked_at`) maps the client to an agent party. `AgentActor` resolves it to `Actor::agent($party, $abilities)`; it refuses tokens without the `mcp:use` scope, revoked connections, and approvers who are no longer owners of the party's organisation (403). `/mcp` and `/api/v1` accept both credentials (`auth:sanctum,api`).
- **Consent screen** (`resources/views/oauth/authorize.blade.php`, rendered by Passport's `GET /oauth/authorize` after login): shows the tool's name and where the approval goes, lets the owner pick one of their agent parties and the abilities (read always; comment ticked by default; edit and approve opt-in). `POST /oauth/akord/approve` (`ConnectAgentController`) checks the owner and party, records the connection (replacing an earlier one for the same client and owner), then hands over to Passport's approve action. Cancel uses Passport's deny route. Passport skips the screen while the client still has a valid token for that user, which is safe because disconnecting revokes those tokens. *Abilities moved to the party on 2026-11-22 (see "Change: connection links, abilities set on the party").*
- **Redirect limits:** `config/mcp.php` allows self-registration only for `http://localhost` and `http://127.0.0.1` callbacks (local tools such as omp and Claude Code), so a stranger can't register a client that sends approvals to their own site. Hosted clients can be added with `AKORD_MCP_REDIRECT_DOMAINS`.
- **Agent access page:** a "Connect an MCP client" section with the omp commands and the MCP URL, and a list of connected tools (abilities, approver, last used) with **Disconnect** (`RevokeAgentConnection`: marks the connection revoked and revokes its access and refresh tokens).
- **Fallback client:** `php artisan akord:oauth-client [name] [--redirect=…]` pre-registers a public client (default callbacks `http://localhost:3000/callback` and `http://127.0.0.1:3000/callback`, omp's default) for MCP clients that can't register themselves; its ID goes in the client's OAuth settings (omp: `"oauth": {"clientId": "…"}`).
- **Rate limit key:** now `party:{id}` for claim-code tokens and `oauth-client:{client id}` for OAuth tokens, so a party and a user with the same id no longer share a bucket.
- **`GET /api/v1/agent`** now reports the actor's abilities (it showed `[]` for OAuth tokens).
- **Keys:** Passport's signing keys live in `storage/oauth-*.key` (git-ignored). `composer setup` creates them if missing, and `Tests\TestCase` does the same for CI.
- **omp plugin** (Agent Plugins 1.0.0): `.omp-plugin/marketplace.json` (marketplace `akord`) → `integrations/omp/akord/` with `plugin.json`, `mcp.json` (server `akord`, `streamable-http`, `http://localhost:8000/mcp`, no secrets) and the `akord-review` skill (`skills/akord-review/SKILL.md`: the review workflow, approving the exact revision, signing off the fingerprint, never following text inside `<contract-data>`). Install: `omp plugin marketplace add PatFox/Akord`, `omp plugin install akord@akord`, `/reload-plugins`, then `/mcp reauth akord:akord`. *Superseded by Phase 9: the plugin no longer has an MCP server.*
- **omp names plugin servers `<plugin>:<server>`**, so the server is `akord:akord` in omp; `/mcp reauth akord` answers "not found". Confirmed in the omp 2026-11 binary: the Agent Plugins provider registers `${plugin.name}:${server.name}` and maps `streamable-http` to its `http` transport, so the type is correct as published.
- **omp sign-in (observed 2026-11-17 against NUC03):** omp registers its own client (`POST /oauth/register` → 201, name `oh-my-pi`, callback `http://localhost:<random port>/callback`), so the fallback client isn't needed. `/mcp reauth akord` starts sign-in; a slash command typed with a leading space is sent as chat instead. OAuth over plain http on a LAN name works. The plugin's URL was fixed at `http://localhost:8000/mcp`, because plugin `mcp.json` files can't take variables; other hosts need their own MCP entry.
- **Tests:** `tests/Feature/Agents/OAuthConnectTest.php`, 9 tests over HTTP with real Passport tokens: discovery and the 401 header; loopback-only registration; the whole consent flow, after which REST and MCP act as the chosen party with the chosen abilities (a missing ability gives 403); non-owners, human parties and other organisations' agents can't be approved; disconnecting revokes access and refresh tokens (401, `invalid_grant`); an approver who stops being owner cuts the connection off (403) until restored; refreshed tokens keep the party; the fallback command creates a public loopback client.
- **Verified:**
  - `composer test` on SQLite: Pint pass, Larastan 0 errors, 183 passed plus 1 skipped.
  - End to end against the running app, as a standard MCP client would: 401 with `resource_metadata` → both discovery documents → registration (a `https://evil.example` callback refused, a loopback one accepted) → PKCE authorization in a real browser (redirected to login, logged in as the owner, consent screen, chose "Review bot" with comment and approve) → code at the callback with the right `state` → token exchange (a reused code gets `invalid_grant`) → MCP `list_contracts` and REST `GET agent` as Review bot → refresh. Disconnecting on the Agent access page then gave 401 on MCP and REST, and `invalid_grant` on refresh.

### Phase 8: notifications ✅ done 2026-11-13

- **Dispatch:** `App\Listeners\SendActivityNotifications` (queued, auto-discovered) listens to `ActivityRecorded`, which already fires only after commit. Working out recipients and sending happens on the queue, so a slow or failing mail server never affects the change.
- **Who hears what.** Recipients are people in parties attached to the contract. The person who made the change is never told, and agents never are (they poll the activity feed, Q6). Owners outside attached parties are not told, except about invitations they sent.

  | Activity | Told |
  |---|---|
  | `comment.added` | everyone on the contract |
  | `step.revised`, content change | everyone on the contract; members of parties whose approval it cancelled get "your party's approval of this step was cancelled" |
  | `step.revised`, spacing only (D11) | nobody |
  | `contract.ready` | members of required approver parties |
  | `signoffs.invalidated` | members of the parties that had signed off |
  | `contract.agreed` | everyone on the contract |
  | `invitation.accepted` | the owner who sent the invitation |

- **`step.revised` payload** now also has `cancelled_party_ids`: the parties whose approval counted until that edit. It is recorded at write time, inside the contract lock, so it is exact. The agent activity feed shows it too (ids only).
- **`App\Notifications\ContractActivity`** (queued; one class for every type):
  - Channels: `database` always, plus `mail` when the person's email is verified.
  - `shouldSend` re-checks `Contract::visibleTo()` at delivery, so someone removed from the contract in the meantime hears nothing.
  - Content: the contract ("Payments API v1"), one sentence (reusing `ActivityDescription`), an optional detail line, and a link to the step (edits, comments) or the contract. Step descriptions and comment bodies are never sent; they stay behind the link and its access checks.
  - Emails escape Markdown in participants' text (titles, names), so a step titled `[Click](https://…)` can't put a link in an email that comes from Akord.
- **In app:** `/notifications` (`pages::notifications.index`) lists the latest 100, unread highlighted. Clicking one marks it read and opens its link; "Mark all as read". The header's bell shows the unread count. The count updates on navigation, not live.
- **Migration:** `2026_11_13_112756_create_notifications_table` (Laravel's standard table: uuid, morphs, text, so it is portable under D12).
- **Tests:** `tests/Feature/NotificationsTest.php`, 8 tests:
  - Each row of the table above, including the author, unattached owners, outsiders and non-required parties not being told, and the cancelled-approval flag going only to the affected party.
  - Unverified email gets the in-app notification only.
  - Lost access means no delivery.
  - Markdown escaping in the email.
  - The page: listing, the header bell's count, opening (marks read, redirects to the step), someone else's notification is 404, and mark all read.
- **Verified:**
  - Pint pass and Larastan 0 errors (through `composer test`).
  - `php artisan test` on SQLite: 191 passed plus 1 skipped.
  - CI on push (`5e37ab1`, together with Phase 9): both the SQLite gate and MySQL 8.4 jobs passed.
  - Not yet checked: a browser, or a real email through Mailpit.
- **Running the suite from omp:** omp loads the project `.env` into its own environment, and `composer test` passed those values to PHPUnit as real environment variables (`APP_ENV=local`, `QUEUE_CONNECTION=database`). Those override `phpunit.xml`, so CSRF checks and the queue behaved as in development and unrelated tests failed. Run `php artisan test` with the `.env` keys unset. A normal terminal doesn't have this problem.

### Phase 9: one MCP URL per agent party ✅ done 2026-11-13

**Problem.**
- omp stores one OAuth credential per profile and server URL (`mcp_oauth:profile:<profile>:<url>`), so every repo using the plugin's single `/mcp` URL shared one sign-in.
- Akord tied each sign-in (OAuth client and approving owner) to one agent party, and a new approval for the same client replaced the old one.
- So separate repos, each meant to be a different party (API team, app team), all acted as one.

**Decision (option 1 of 4).** Each agent party has its own URL, and each repo commits its party's URL in `.omp/mcp.json`.
- omp keeps one sign-in per URL, so each repo signs in separately.
- Akord accepts a credential at a party's URL only if it was approved for that party.
- Rejected alternatives:
  - an omp profile per project: it isolates everything else too;
  - claim-code tokens per repo: they still work for headless agents, but are long-lived secrets on disk;
  - one sign-in with a party header: any repo could claim any granted party.

**Changes:**
- **Route** (`routes/ai.php`): `POST /mcp/agents/{party}` serves the same `ContractServer`, behind `auth:sanctum,api`, `throttle:agent` and `EnsureAgentForUrlParty`.
  - `laravel/mcp`'s nested discovery already serves `/.well-known/oauth-protected-resource/mcp/agents/{id}` with `resource` set to that URL.
  - The 401 challenge points at that URL, so clients discover it without configuration.
  - The generic `/mcp` stays, for one-party setups and other MCP clients.
- **Binding** (`AgentActor::from($user, $urlPartyId)`):
  - **At a party URL:**
    - A claim-code token must belong to that party.
    - An OAuth token needs an active connection for (client, approving owner, **that party**).
    - Otherwise the request gets `403` at the HTTP level (`EnsureAgentForUrlParty`), before the MCP server runs, so a misconfigured repo fails when it connects.
    - MCP tools pass the route's party too.
  - **At `/mcp`:** the latest connection for the client and owner, as before.
- **Connections:** approving a client for a party replaces only that client's connection for **that party**. Its connections for other parties are kept (`ConnectAgentController`).
- **Approval screen:**
  - MCP clients send the server URL as the OAuth `resource`. When that is a party URL, the screen is fixed to that party, or explains that the signed-in user doesn't own it.
  - `App\Http\Controllers\Agents\AuthorizationController` replaces Passport's (bound in `AppServiceProvider::register`). Passport skips the screen while the client already holds a valid token for the user. The replacement skips it only if the client is already connected for the party being signed in for, so a client reused for a second party's URL still gets a connection recorded.
  - `App\Support\Agents\PartyMcpUrl` builds and parses party URLs.
- **Agent access page:** shows the party's MCP URL and the `.omp/mcp.json` to commit (`{"mcpServers": {"akord": {"type": "http", "url": "…/mcp/agents/{id}"}}}`), then `/mcp reauth akord` in that repo. *Superseded 2026-11-22 by connection links.*
- **Changing abilities after connecting** (added after a report that "Edit steps" on the Agent access page didn't save). *Superseded 2026-11-22: abilities are the party's, not each credential's.*
  - The checkboxes the user had used were the claim-code form's input for the next code, never a saved setting. They're now labelled so.
  - Each connected tool and each API token now has its own Comment / Edit steps / Approve checkboxes in its row.
  - Every click saves through `App\Actions\Organisations\SetAgentAbilities`. It is owner-only, returns 404 for a credential of another party or a revoked connection, and always keeps `read`.
  - The change applies on the agent's next request, because `AgentActor` reads abilities from the connection or token each time.
  - Before this, changing a connected tool's abilities meant Disconnect and sign in again, because Passport skips the approval screen while the tool's token is valid.
  - Tests: a token gains `edit` and can then revise a step; non-owners get 403 and another party's token 404; a connection gains `approve` and can then approve.
  - Checked in a browser: tick "Edit steps", reload, still ticked; untick, reload, unticked. This used a throwaway claim-code token on Review bot, deleted afterwards.
- **omp plugin 1.1.0:**
  - The review skill only. `integrations/omp/akord/mcp.json` is removed: the plugin's fixed URL could only ever be one party, and alongside a repo's own server it would have added a second set of tools.
  - The skill tells the agent where the config comes from if no Akord tools are available.
- **Known limit.** OAuth tokens are issued per client and user, not per party.
  - If one client id is deliberately reused in several repos (the `akord:oauth-client` fallback client set as `oauth.clientId` in each), its tokens can act at any party URL that client was approved for.
  - omp registers a new client on each sign-in, so this doesn't arise with it [INFERENCE from omp's code: registration runs whenever no `clientId` is configured].
  - Use one fallback client per repo.
- **Tests** (`OAuthConnectTest`, 5 new):
  - Per-party discovery and the 401 challenge.
  - Two repos (two clients) each working at their own URL, and `403` at the other's.
  - A client reused for a second party: shown the screen again, both connections kept, each URL working.
  - Claim-code tokens only at their own party's URL.
  - The approval screen fixed to the URL's party, and refused for a party the user doesn't own.
- **Verified:**
  - Pint pass and Larastan 0 errors.
  - `php artisan test` on SQLite: 196 passed plus 1 skipped.
  - CI on `5e37ab1`: SQLite gate and MySQL 8.4 both passed.
  - Against the running app: `POST /mcp/agents/6` gives 401 with `resource_metadata=…/mcp/agents/6`, and that document has `resource: http://localhost:8000/mcp/agents/6`.
  - End to end with omp (2026-11-17, NUC03): this repo's `.omp/mcp.json` points at `http://akord.nuc03/mcp/agents/au6t2ref4gkdhx01`. `/mcp reauth akord` registered a client, the approval screen was fixed to OMP - Akord, and the connection was saved with read, comment, edit, approve and create, plus an `mcp:use` token. The tools did not appear in the session that ran the sign-in; the server saw no authenticated MCP call (`last_used_at` stayed empty).

### Phase 10: agents change contract structure ✅ done 2026-11-13

**Decisions:**
- Agents can create steps, move them, create phases and archive steps. All four need the editor role and the `edit` ability; archiving has no separate ability.
- Agreed contracts refuse all four with `423`, through the existing lock.

**Position: `after_step_id`**, the same for create and move:
- omitted: the end of the phase;
- `"start"` (`StepOrder::START`; `0` until Phase 13): the start;
- a step id: directly after that active step of the target phase.
- Anything else is `422`: an archived step, a step in another phase, or the step itself.

**Changes:**
- **`App\Support\StepOrder`:**
  - `positionAfter()` resolves `after_step_id`; `place()` inserts a step and renumbers the phase; `compact()` closes gaps.
  - Only active steps are numbered, 1..n.
  - `MoveStep` now uses it, with a new `handleAfter()`; the UI keeps calling `handle()` with a position. `CreateStep` gained `$afterStepId`.
  - `step.created` now records `position`.
- **`AgentApi`:** `createStep`, `moveStep` (`phase_id` omitted keeps the step's phase), `createPhase`, `archiveStep`.
- **REST:**
  - `POST contracts/{c}/phases` → 201
  - `POST contracts/{c}/phases/{p}/steps` → 201, returning the `get_step` shape
  - `POST contracts/{c}/steps/{s}/move`
  - `POST contracts/{c}/steps/{s}/archive`
- **MCP tools:** `create_step`, `move_step`, `create_phase`, `archive_step`.
  - The descriptions say archiving can't be undone and phases can't be deleted.
  - The server instructions say to change structure only when asked.
- **`tools/list` paging:** `laravel/mcp` pages `tools/list` at 15 by default, and with 16 tools `withdraw_signoff` fell onto a second page. `ContractServer::$defaultPaginationLength = 50` keeps every tool in one page, for clients that don't follow `nextCursor`.
- **Skill and plugin:** the skill has a "Changing the structure" section (confirm before archiving). The plugin is now 1.2.0.

**Tests:**
- `tests/Feature/Actions/StepOrderTest.php` (3): placement at the end, start and after a step; moves within and across phases with no position gaps; refused anchors change nothing.
- `tests/Feature/Agents/AgentStructureTest.php` (5):
  - the full REST flow, with the agent's party as author and no user, and activity counts;
  - the same flow over MCP;
  - refusals: commenter 403, no `edit` 403, another contract's phase or an invisible contract 404, missing title or bad anchor 422, an MCP refusal as a `403:` tool error, and all four 423 once agreed;
  - a move keeps approvals and sign-offs, while a new step cancels sign-offs;
  - archiving the last unapproved step makes the contract ready.
- `McpServerTest` now expects the 16 tools.
- Not added: a party-URL author test. `AgentActor` binding at party URLs is already covered by Phase 9's tests.

**Verified:**
- Pint pass, Larastan 0 errors, `php artisan test` 206 passed plus 1 skipped.
- Against the running app, as OMP - Akord at `/mcp/agents/7` with a throwaway token, deleted afterwards:
  - `tools/list` returns 16 tools;
  - `create_step` after step 7 put step 9 between steps 7 and 8, at revision 1 with author OMP - Akord;
  - `move_step` with no arguments sent it to the end;
  - `archive_step` removed it.
  - `create_phase` wasn't run live, because phases can't be deleted.

### Phase 11: agents create contracts ✅ done 2026-11-13

**Decisions** (chosen when option 2 was picked; D4 is otherwise unchanged):
- **New `create` ability**, off by default.
  - It appears with the others on the approval screen, the claim-code form and each credential's row on the Agent access page.
  - Labels now come from one list, `AgentAbilities::optionalLabels()`.
- **Projects:** only projects the agent can already see, through `Project::visibleTo($party)`, meaning projects holding a contract its party is on.
  - Any other project is `404`, so agents never learn other projects exist.
  - To let an agent start contracts in a new project, an owner first attaches it to one contract there.
- **Membership:** the agent's party is attached as **editor**, not as a required approver.
  - Required approvers stay owner-only (D4), so the contract can't become ready for sign-off until an owner sets them.
  - The human sign-off rule (D10) starts off, as for owner-created contracts; only owners change it.
- **No owner notification.** Owners see the contract in the project list and the `contract.created` activity; notifications go to attached parties only (Phase 8).

**Changes:**
- **Action:** `App\Actions\Agents\CreateContractAsAgent`. In one transaction it creates the contract, attaches the party and records `contract.created`; activity is sequenced after commit. People keep using `Organisations\CreateContract`.
- **REST:** `POST /api/v1/contracts {project_id, title}` → 201 with the `get_contract` shape.
- **MCP:** a `create_contract` tool, 17 tools in all, still in one `tools/list` page. The server instructions list it among the structure changes to make only when asked.
- **Skill and plugin:** the skill has a "Starting a contract" section. The plugin is now 1.3.0.

**Tests** (`tests/Feature/Agents/AgentContractCreationTest.php`, 3):
- An agent with `create` starts a contract:
  - it is version 1, draft, with the agent as editor and no required approvers;
  - one `contract.created` activity is recorded, credited to the party with no user;
  - the agent builds a phase and a step and approves it, and the contract is still draft;
  - the owner can see it; people on the agent's other contract can't.
- The same through MCP.
- Refusals change nothing:
  - without `create`: `403`, and a `403:` tool error over MCP;
  - a same-organisation project the agent isn't on: `404`;
  - another organisation's project: `404`;
  - no title: `422`.

**Verified:**
- Pint pass, Larastan 0 errors, `php artisan test` 209 passed plus 1 skipped.
- Not run against the live app, because contracts can't be deleted.

### Phase 12: releases and a stable local instance ✅ done 2026-11-17

**Goal.** A local instance for real use, separate from the dev checkout, installed from versioned releases and updated in place.

**Release** (a tag push publishes it):
- `php scripts/build-release.php vX.Y.Z` builds `dist/akord-vX.Y.Z.zip` and its `.sha256`.
  - Sources: the files git would commit from the working tree (`git ls-files -z --cached --others --exclude-standard`), so a clean tag checkout builds exactly that tag. `-z` matters: without it git quotes non-ASCII names, and the first test build silently left out the 17 Livewire `⚡` pages (`Unable to find component: [pages::organisations.index]`). Listed files that are missing are now printed, and zip entry names are flagged UTF-8.
  - Contents: `composer install --no-dev -o`, compiled `public/build`, and a `VERSION` file. Not shipped: tests, CI, the omp plugin, PLAN.md, tool configs, `node_modules` and the Vite/npm files.
- `.github/workflows/release.yml` runs on `v*` tags. It calls `tests.yml` (both jobs; `tests.yml` now also has `workflow_call`), then builds and runs `gh release create` with the zip, its `.sha256` and `scripts/akord.ps1`. A tag with a suffix (`v1.2.0-rc.1`) becomes a prerelease, which `releases/latest`, and so `update`, skips.
- **Echo reads Reverb settings at runtime.** `partials/head.blade.php` sets `window.akordReverb` (key, host, port, scheme) from `config('broadcasting.connections.reverb')`, and `resources/js/echo.js` uses it. Before this, `VITE_REVERB_*` were compiled into the bundle, so a prebuilt release would have carried one instance's key and port. `VITE_REVERB_*` are gone from `.env.example`.

**Instance** (`scripts/akord.ps1`, Windows PowerShell 5.1; needs PHP 8.3+ on `PATH` and no Node or Composer):
- Commands: `install`, `update`, `start`, `stop`, `restart`, `status`, `uninstall`, and `run` (internal).
- Options: `-Version vX.Y.Z` (default latest), `-Package <zip>` (a local build instead of a download), `-Root`, `-Port`/`-ReverbPort` (install only), `-NoAutostart`, `-Force`.
- **Layout** of `-Root` (default `%LOCALAPPDATA%\Akord`):
  - `shared\.env`: the instance settings.
  - `shared\storage`: logs, Passport keys and compiled views. Linked into each release with a junction.
  - `shared\database\akord.sqlite`.
  - `releases\<version>`, with the `current` junction pointing at the active one.
  - `backups\`, `run\supervisor.pid`, and `akord.ps1` itself (each release brings its own copy).
  - Junctions need no admin rights. Deleting a release unlinks its `storage` junction first, so the recursive delete never reaches the shared storage.
- **Install:**
  - Checks PHP and its extensions, and that both ports are free.
  - Downloads the release and verifies its SHA-256.
  - Writes `shared\.env` from the release's `.env.example`: `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY`, `APP_URL=http://localhost:<port>`, daily logs, an absolute `DB_DATABASE`, random Reverb credentials, and Reverb bound to `127.0.0.1`.
  - Also sets `SESSION_COOKIE=akord_<port>_session`, because browsers share cookies across ports, so dev and the instance on `localhost` would otherwise log each other out. `MAIL_MAILER=log`, so verification links are written to the log.
  - Then `passport:keys`, `migrate --force`, a Startup-folder shortcut (`Akord.lnk`, runs `start` hidden at logon), and `start`.
- **Start:**
  - Copies `shared\.env` into the release and runs `artisan optimize` (config, events, routes and views cached).
  - Starts the hidden supervisor (`akord.ps1 run`) and waits up to 60 s for `/up` to answer 200 and the Reverb port to listen.
  - The supervisor runs `php -S 127.0.0.1:<port>` with Laravel's `server.php`, `reverb:start`, and `queue:work --tries=3 --max-time=3600`.
  - Each child appends its output to `storage\logs\<name>.log`. A child that exits is restarted: after 1 s if it ran for at least 60 s, otherwise with a delay that doubles up to 60 s. `supervisor.log` records every start and exit.
  - `stop` kills the supervisor's whole process tree and waits for both ports to close.
- **Update:**
  - Unpacks the new release and prepares it while the old one keeps running.
  - Then stops, copies the database to `backups\akord-<old version>-<time>.sqlite`, migrates, switches `current`, and starts.
  - If any of that fails, it restores the backup, switches back and starts the old release.
  - Keeps 3 releases and 10 backups.
- **Inherited environment:** every command first removes from its own environment each key in `shared\.env` and the release's `.env.example`. omp and `artisan dev` export the project's `.env`, and real environment variables override `.env` and would be baked in by `config:cache`. This is how, on 2026-11-16, a server started from omp got Git Bash's mangled `SESSION_PATH` (`C:/Program Files/Git/`) and returned 500.
- **Known limits:**
  - On Windows `php -S` handles one request at a time (`PHP_CLI_SERVER_WORKERS` is not supported there). That is enough for one person and a few agents.
  - No manual rollback command. Restore by hand from `backups\` and point `current` at the older release.
  - The `XSRF-TOKEN` and remember-me cookie names don't depend on `SESSION_COOKIE`, so they are still shared with dev on `localhost`. A mismatched remember-me cookie is ignored. Livewire sends its CSRF token from the page, not from that cookie.
  - **PHP needs a CA bundle for outgoing HTTPS.** Without `curl.cainfo` or `openssl.cafile`, the breach check on new passwords in production (`Password::defaults()->uncompromised()`) fails with cURL error 60; Laravel logs it and accepts the password. TLS mail would fail the same way. `install` and `update` warn when neither is set. This machine had neither until 2026-11-17 (see §8).
  - `LOG_LEVEL` must stay `debug` while `MAIL_MAILER=log`: the log transport writes mail at debug level, so with `info` the verification emails vanished. `install` keeps `.env.example`'s `debug`.

**Verified** (2026-11-17, local builds `v0.0.1-test.1` and `v0.0.2-test.1` installed into a throwaway root with `-Package`; the root and builds were deleted afterwards; nothing published):
- `build-release.php`: about 2.5 minutes, 16 MB, 12.8k entries. No tests, `.env`, `public/hot`, dev packages or keys inside.
- `install`, run from a Git Bash shell that had the dev `.env` exported: the cached config held the instance's values (`session.path` `/`, port 8100 and 8101, Reverb on `127.0.0.1`, absolute SQLite path), and the session cookie was `akord_8100_session`.
- Reverb accepted a WebSocket with the instance key (`pusher:connection_established`). `Artisan::queue('inspire')` ran on the worker.
- Killing the Reverb process: the supervisor logged the exit and restarted it 2 s later.
- In a browser: registered, verified through the link from the log, and reached the dashboard. Echo reported `connected` to `localhost:8101` from the prebuilt bundle.
- `update` 0.0.1 → 0.0.2 kept the session and data. `update` to a release whose second migration throws (the first creates a table): exit 1, the database restored without that table, `current` back on 0.0.2, healthy.
- `update` with the active version printed "Already on". A `-Force` reinstall pruned the oldest release, and the shared storage (logs, keys) survived the delete.
- `uninstall`: both ports freed, no processes left.
- Not yet run: the release workflow on GitHub, an install from a published release (download and checksum), and logon autostart.

**Ubuntu server (README "Installing on an Ubuntu server", added 2026-11-17):** a manual alternative to the Windows instance, from the same release zip. There is no script.
- **Server:** Ubuntu 24.04 (PHP 8.3), Apache vhost, PHP through **PHP-FPM** (event MPM, `proxy_fcgi`, recommended) or **mod_php** (prefork).
- **Layout:** `/var/www/akord/{releases,shared,backups}` with a `current` symlink; `storage` and `.env` are symlinks into `shared/`. Everything is owned by `www-data`, and artisan runs as `www-data`.
- **Reverb:** listens on `127.0.0.1:8080`. The vhost proxies `/app/` (with `upgrade=websocket`, Apache 2.4.47+) and `/apps/`, so browsers and the server-side publisher both use `https://<domain>:443`. No app route starts with `/app/` or `/apps/`.
- **Processes:** Reverb and `queue:work` run as systemd units. Certbot handles HTTPS.
- **Updates:** unpack and `optimize` beside the running release, then `artisan down`, stop the units, copy the SQLite file, migrate, switch the symlink, reload PHP, start the units, `artisan up`. Maintenance mode lives in the shared storage, so both releases honour it.
- **Verified** in an `ubuntu:24.04` container from a local build, following the README's commands. Deviations: http on port 8090, no certbot, no systemd (the units' `ExecStart` lines run by hand), and the zip mounted instead of downloaded.
  - With both FPM and mod_php: `/up` 200, and PHP receives `Authorization`. A Sanctum token worked over REST (`/api/v1/agent`) and MCP (`initialize` at `/mcp/agents/1`).
  - Echo connected through the `/app/` proxy, and an event published from the server through `/apps/` reached the browser.
  - Registration, plus verification through the README's `grep` command.
  - Update to a second build and back again, with 503 during maintenance.
  - Switching mod_php → FPM as documented.
  - Without the reload, mod_php kept serving the old release after the symlink switch; with FPM the switch showed at once. So the update step's reload is required for mod_php.
  - `libapache2-mod-php8.3` switches the MPM to prefork by itself.
  - Stopping Apache waits about 10 s while a WebSocket is proxied.

**Publishing a release:** `git tag v0.1.0 && git push origin v0.1.0`. Then install with:

```powershell
Invoke-WebRequest https://github.com/PatFox/Akord/releases/latest/download/akord.ps1 -OutFile akord.ps1
powershell -ExecutionPolicy Bypass -File .\akord.ps1 install
```

Update with `powershell -ExecutionPolicy Bypass -File $env:LOCALAPPDATA\Akord\akord.ps1 update`. Agents use the instance's URL in their repo's `.omp/mcp.json` (`http://localhost:8100/mcp/agents/<id>`).

### Phase 13: random string ids ✅ done 2026-11-17

**Decision.** Every entity's primary key is a random 16-character string instead of an auto-increment integer. Ids in URLs and the API no longer reveal counts or creation order, and can't be enumerated. The database starts blank: the migrations were changed in place, without a conversion step.

- **Format** (`App\Support\RandomId`): 16 characters from `[0-9a-z]`, from `random_int` (about 82 bits).
  - Lower case only, because MySQL's default collations compare case-insensitively (D12).
  - Never all digits, because PHP turns an all-digit array key into an int.
- **Models:** `App\Models\Concerns\HasRandomId`, built on Laravel's `HasUniqueStringIds`.
  - It is used by User, Organisation, Project, Party, AgentClaimCode, AgentConnection, Contract, Invitation, Phase, Step, StepRevision, Comment, Approval, ContractSignoff and ActivityEvent.
  - Route binding answers 404 for a malformed id without querying.
- **Schema:** `AppServiceProvider::configureSchema()` adds the Blueprint macros `randomId()` (`char(16)` primary key), `foreignRandomId()` (`char(16)` with `constrained()`) and `randomIdMorphs()`.
  - Framework tables' keys to our models changed too: sessions, passkeys, Sanctum `tokenable`, Passport `user_id` and `owner`, and notifications `notifiable`.
  - Larastan can't see columns made by these macros, so code reads Sanctum's `tokenable_*` with `getAttribute()`.
- **Unchanged:**
  - Join-table row ids (`organisation_user`, `party_user`, `contract_party`). They are never exposed, and plain pivots can't generate ids on `attach()`.
  - Sanctum token ids, Passport's own ids, passkeys and jobs.
  - Counters: `seq`, revision `number`, `position`, `version`.
- **Ordering no longer comes from ids:**
  - `activity_events.position` numbers a contract's events 1, 2, 3…. `ActivityLog` assigns it while the contract row is locked; every caller locks it first, and the unique `(contract_id, position)` index turns a missing lock into an error.
  - `ActivitySequencer` numbers pending events by `(contract_id, position)`, and the contract page's change list orders by `position`.
  - "Latest connection" in `AgentActor` is ordered by `created_at`, then id, because re-approval creates a new row. `AgentApi::listContracts` uses `created_at`, then id.
  - Where id only breaks ties (steps with the same position, notification recipients), it stays.
  - **Known limit (found 2026-11-18):** lists ordered by `created_at` (comment threads and replies, invitations, claim codes) have no stable order within one second. Timestamps are whole seconds on MySQL, and random ids don't break ties in creation order, while auto-increment ids used to. So two replies posted in the same second can show in either order. `StepPageTest`'s reply test failed once on CI's MySQL because of this; it now spaces its comments a second apart. A real fix would need a sequence column or sub-second timestamps.
- **API and MCP:**
  - Every entity id is a JSON string. Validation and MCP schemas say string, and routes constrain ids to `RandomId::PATTERN`.
  - `after_step_id`'s start value is `"start"` (`StepOrder::START`) instead of `0`.
  - The skill text says so; the plugin is now 1.3.1.
- **Fingerprint:** step and party ids are sorted as strings (`strcmp`), so the pinned test hash changed. That's harmless with no stored sign-offs.
- **Livewire:**
  - Ids inside `wire:click` expressions are quoted (`'{{ $id }}'`).
  - `wire:sort:item` must stay unquoted, because Livewire sends its text unchanged. Found in a browser: a quoted item reached `sortStep` with the quotes, got 404, and the drag silently reverted. `ContractPageTest` now pins the attribute.
- **Tests:**
  - `ActivityCursorTest`: a late commit is delivered after the cursor, and pending events are numbered by `position`, not id (the later event gets the id that sorts first).
  - Lists that were compared in id order, which used to be creation order, now use `toEqualCanonicalizing`.
- **Verified:**
  - Pint pass, Larastan 0 errors.
  - `php artisan test` on SQLite: 210 passed plus 1 skipped, in three runs.
  - MySQL 8.4 (throwaway container, `--fail-on-skipped`): 211 passed, including `tests/Concurrency`.
  - Demo data in a throwaway database: ids are random, and one contract's 20 events were sequenced in position order 1–20.
  - In a browser against that database: moving and renaming a phase, the add-step form, drag-sort (the request carries the bare id, gets 200, and the order persists after reload), the required-approver toggle, and reply.
- **Existing databases** need `migrate:fresh`.
  - The dev database was reset and is blank.
  - NUC03 moved to a new, empty MySQL database with this release (§8).
  - Agents must reconnect, because their OAuth clients and tokens were in the old databases.

### Fix: replies to replies were hidden (2026-11-18)

- **Bug:** `AddComment` accepted any comment on the same step as `parent_id`. The step page and `get_step` load top-level comments and their direct replies only, so a reply to a reply was saved, appeared in the activity feed, and was shown nowhere else. Agents hit it by replying to the latest message of a thread. It is present since Phase 4, not caused by Phase 13. `ContractMarkdown::export` never includes comments, by design.
- **Fix:** threads stay two levels deep.
  - A reply to a reply joins its thread's top-level comment.
  - The comment actually answered is kept as `in_reply_to` in the `comment.added` payload, set only when it differs from `parent_id`.
  - The MCP `parent_id` description and the skill (plugin 1.3.1) say so.
  - Test: `StepPageTest` "a reply to a reply joins its thread".
- **NUC03 data:** the two affected comments were moved to their thread's top-level comment: `hv5omyrbhpe9qwjk` to `qnz7iumccc5fn3km`, and `neb5e12tdq5ldwtc` to `2vjp5sv74uf200xk`. Their original parents are still in their events' payload `parent_id`. No comments are nested deeper now. The code fix was deployed to NUC03 on 2026-11-18 in `v0.1.0-pre.eab7f57` (§8).

### Fix: long change notes failed with 500 on MySQL (2026-11-18)

- **Bug:** the web form, REST API and MCP tools accept change notes up to 500 characters, but `step_revisions.change_note` was `varchar(255)`. MySQL (strict mode) refused longer notes (`1406 Data too long`), so `revise_step` and `create_step` failed with 500. An agent on NUC03 hit it with a 332-character note. SQLite ignores varchar lengths, so the SQLite suite never saw it. It is present since Phase 2.
- **Fix:** migration `2026_11_18_180000_widen_step_revision_change_note` makes the column `varchar(500)` with `->change()`.
  - On MySQL this is an in-place `ALTER`.
  - On SQLite Laravel rebuilds the table. That was checked on demo data: revisions, current-revision links and comments were kept, with no foreign-key violations.
  - The other text fields were already safe: titles are validated to 200 and names to 120, both under 255.
- **Test:** `AgentApiTest` "a change note up to the advertised 500 characters…": 500 characters are saved whole, and 501 give 422. On MySQL it fails without the migration and passes with it. On SQLite it can't fail, so CI's MySQL job is what guards it.
- **Deployed** to NUC03 on 2026-11-18 in `v0.1.0-pre.d2da2cf` (§8). The column is now `varchar(500)`.

### Change: contract list and status (2026-11-19)

- **Contracts list** (`projects/⚡show`): one row per contract, its latest visible version (`Contract::latestVisibleTo`: visible, with no visible next version). A muted `v2` appears from version 2 on. Status links (Open, Complete, Cancelled, All, with counts) are plain links to `?status=`, so changing the filter reloads the page; a missing or unknown value means open. The organisation page counts open contracts per project.
- **Status (D14):** `contracts.status` (migration `2026_11_19_120000_add_status_to_contracts`, existing rows become open), enum `ContractStatus`, action `SetContractStatus` (records `contract.status_changed` with `from`/`to`).
  - `Contract::isLocked()` is agreed or not open. `ContractTransaction` checks it; `allowWhenAgreed` became `allowWhenLocked` (invitations, `StartNewVersion`, `SetContractStatus`). `ContractLockedException::for()` says "Reopen it" for closed versions.
  - Web: a status menu and a "Marked …" callout with **Reopen** on the contract page; edit, comment, approve and sign-off controls hide while closed, and settings controls are disabled.
- **Versions:** the contract page's version badge is a menu of the versions the user can see. The settings page no longer lists versions or starts one; "Start new version" stays in the agreed callout.
- **Agents:** `status` in the contract summary, contract and status shapes. `list_contracts` takes `status` (array, default `["open"]`); `GET /api/v1/contracts?status[]=…`. New MCP tool `set_contract_status` and `PATCH /api/v1/contracts/{id} {status}`. Unknown statuses give 422. Server instructions and the skill say so; plugin 1.4.0.
- **Known limit:** a user attached to v1 and v3 but not v2 sees two rows for that contract, because "latest visible" is worked out one link at a time.

### Change: steps on the contract page (2026-11-19)

- **No step page.** The `steps.show` route and `pages/steps/⚡show` are gone.
- **Step links:** each step's `⋯` menu has **Copy link** (`contracts/{id}#step-{id}`). The Clipboard API needs a secure context, so plain-http installs (like NUC03) fall back to `execCommand('copy')`, then to a prompt showing the link.
  - Opening a step link collapses every phase except the step's own, and expands only that step, with its comments collapsed. The same happens on a hash change without a reload. A link to an archived step opens the archived list.
  - `#step-{id}-comments` does the same with the comments open; comment notifications use it. Other step notifications (edits) use the plain link.
- **One panel** for all phases. A column header lists the required parties; each phase row shows how many steps are fully approved ("N/M" badge across the Rev and comment columns, "N of M approved" as its tooltip; since 2026-11-23, so a long title no longer pushes it onto its own line; a long title also wraps beside the collapse chevron, not under it) and, in the party columns, how many steps each party approved. Phases collapse (one at a time, or "Collapse all phases"); "Expand all steps"/"Collapse all steps" per phase is in its `⋯` menu, and "Show all step text" covers every phase. Collapsing is client-side (Alpine), not saved.
- **Phase titles** are renamed by clicking them: Enter or leaving the field saves, Escape cancels. The rename button is gone; moving a phase is in its `⋯` menu.
- **Each step is a Livewire component** (`pages::contracts.step`), so an edit, approval or comment re-renders only that step. Its row shows the title, revision, comment count and a tick per required party. Expanded, it shows the text, an **Edit** button (inline editor with Write/Preview, change note and the same conflict handling as before), comments (collapsed by default) and, from its `⋯` menu, **History** (revisions and a word diff) and **Archive**.
  - **Ticks:** the acting party's own tick is a button when it is a required approver and may approve: it approves the revision on screen or withdraws. An amber tick means its approval was for an earlier revision. Other parties' ticks are read-only. Parties that are not required approvers no longer get an approve button, since their approvals never counted (D2).
  - **Refreshing:** the contract page receives each broadcast and dispatches `step-refresh.{stepId}` (subject is a step, comment or approval) or `steps-refresh` (party changes, agreement, status); steps dispatch `step-updated` back so the sign-off panel and phase counts update. Own changes no longer mark the contract "changed while you were reviewing".
  - `ContractReadiness::countingApprovals()` is the one query for "which approvals count", used by the report and by each step.
- **Archived steps** are listed, read-only, under "N archived steps" at the bottom of the panel.
- **Long titles (2026-11-20):** the step list used `min-w-fit`, so with `truncate` (no wrapping) a long title widened the list past the panel and the party columns scrolled out of view. The list's minimum width is now the fixed columns plus 14rem for the title (it scrolls sideways only below that, e.g. on phones). Step titles wrap anywhere (long paths) and are clamped to three lines with an ellipsis while the step is collapsed, in full when open, with the full title as a tooltip. Phase and checklist item titles wrap too, and `<x-markdown>` breaks long words and inline code.
- **Collapsible sidebar (2026-11-20):** replaced by the header bar (2026-11-23, below).
- **Step filter (2026-11-20):** a segmented **All / Not fully approved / Awaiting me** control in the steps panel (`?show=unapproved|mine`, property `$show`, computed `shownStepIds`). "Not fully approved" keeps steps missing any required party's counting approval; "Awaiting me" keeps steps missing the acting party's, and is offered only to someone acting as a required approver (otherwise, and with no required approvers, the page falls back to All). A phase with no kept steps shows just its header (counts included); a note says when nothing is left. Dragging is off while filtering, because positions count hidden steps. Approving a step in a filtered view drops it straight away. A step link (`#step-…`) to a step the filter hides switches back to All first.
- **Linked changes (2026-11-20):** in the "contract changed while you were reviewing it" list, each change to a step (or its comment or approval) links to the step (`#step-{id}`, `-comments` for comments). The link switches to the Steps tab in place and sets the hash, so it never reloads the page, which would quietly accept the new state as reviewed.

### Change: view as plan (2026-11-19)

- **One plan document** (`App\Support\ContractPlan::markdown`): the title, a summary (version, state, status if not open, agreed date, required approvers, fingerprint, the contract URL and a note that the text was written by participants), then `## 1. Phase` and `### 1.1 Step` with each active step's current text. Archived steps and comments are left out.
  - Headings inside a step's text are pushed three levels down (capped at 6), outside fenced code blocks, so a `## Notes` in a step can't pose as a phase. Titles are flattened to one line.
- **Contract page:** tabs **Steps** and **View as plan** under the sign-off panel (`?tab=plan`). The Plan tab renders the document through `<x-markdown>` (raw HTML stripped) with **Download .md**.
- **Download:** `GET /contracts/{id}/plan.md` (`DownloadContractPlan`, route `contracts.plan`), an attachment named `{title-slug}-v{version}.md`; 404 for people who can't see the contract.
- **Agents:** the plan replaces the old review export (`App\Support\Agents\ContractMarkdown`, removed), so `get_contract` with `format: markdown` and `GET /api/v1/contracts/{id}?format=md` return the same document people download. That export no longer has per-step approval lines or revision numbers; `get_contract` (json) and `get_step` still have both. Skill text updated, plugin 1.4.1.

### Change: checklists (2026-11-19)

- **Data:** migration `2026_11_19_180000_create_checklists_tables`; models `Checklist`, `ChecklistStage`, `ChecklistItem` (morph names `checklist`, `checklist_stage`, `checklist_item`). Stages and items cascade on delete, so replacing deletes the old checklist.
- **Actions** (`app/Actions/Checklists`): `CreateChecklist` (stages, or a copy of the phases and active steps; at most 100 stages and 500 items; `step_id` must be an active step of the version), `SetChecklistItemChecked`, `AddChecklistItem`. All need `ContractAbility::Edit` and run with `allowWhenLocked`, then refuse complete or cancelled versions themselves (`ChecklistInput::ensureOpen`, 423). Activity types: `checklist.created` (payload `replaced`), `checklist.item_checked`, `checklist.item_unchecked`, `checklist.item_added`; no notifications.
- **`StartNewVersion`** copies the checklist with its ticks; items follow their steps to the new version's copies, and lose the link if the step was archived.
- **Agents:** MCP `get_checklist`, `create_checklist`, `check_item`, `uncheck_item`, `add_checklist_item` (23 tools). REST `GET`/`PUT contracts/{id}/checklist`, `POST …/checklist/stages/{stage}/items`, `POST …/checklist/items/{item}/check|uncheck`. Titles and notes sit under `content`. Server instructions and the skill ("Working through a checklist") explain it; plugin 1.5.0.
- **Web:** a **Checklist** tab with a `done/total` count appears once a checklist exists (`?tab=checklist`). `pages::contracts.checklist` shows a progress bar, stages with their counts, and items with tick boxes, notes, a link to the item's step and who ticked it when. The boxes are disabled for people who may not tick, or when the version is complete or cancelled. Live: the contract page dispatches `checklist-refresh` for `checklist.*` activity; ticking dispatches `checklist-updated` so the tab count updates.

### Change: several named checklists (2026-11-20)

- **Data:** migration `2026_11_20_120000_name_checklists` adds `checklists.name` and swaps the unique index on `contract_id` for one on `(contract_id, name)`. The new index is added first, because MySQL needs an index on `contract_id` for its foreign key. Existing checklists are named after the party that created them. Checked on SQLite (dev data) and MySQL 8.4 (rollback, an old-style checklist, migrate again).
- **Names:** required, up to 60 characters, spaces collapsed. They are matched ignoring case in code (`lower(name)`), because MySQL's collation ignores case and SQLite's doesn't; the unique index is the backstop. At most 20 checklists per version. Lists are ordered by `created_at`, then name, because timestamps are to the second.
- **Agents:** `create_checklist` takes a required `name`; `get_checklist` became `get_checklists` (every checklist, each with `content.name`). REST moved to `GET`/`POST /api/v1/contracts/{id}/checklists`, `POST …/checklists/stages/{stage}/items` and `POST …/checklists/items/{item}/check|uncheck`; the `/checklist` routes are gone. Item and stage ids are unchanged, so ticking needs no checklist id. Skill updated; plugin 1.6.0.
- **Web:** one tab per checklist, "Checklist: API team" with its `done/total`, oldest first (`?tab=checklist-{id}`); tabs wrap onto a second line when there are many, and long names are truncated. `pages::contracts.checklist` takes a `checklistId` and shows the name as its heading. A tab whose checklist was replaced (new id) or belongs to another contract falls back to Steps.
- **Activity:** descriptions name the checklist (":actor ticked “Login” in “App team”"); `checklist.created` records `name` in its payload.
- **Stage numbers (2026-11-24):** the page numbers stages ("1. Database"), and agents often number their stage titles too, so headings read "1. 1. Database". `ChecklistStage::heading()` leaves out the page's number when the title already starts with its own (`1.`, `1)` or `1:`, not followed by a digit, so "1.5 kg limit" is still numbered). Stored titles and the agent API are unchanged. Agents are also told not to number titles: the `title` descriptions of `create_checklist` stages, `create_phase`, `create_step` and `revise_step`, and the skill (plugin **1.7.2**).
- **Not done:** renaming or deleting a checklist (replacing by name is the only way to change one).

### Change: checklist assignees (2026-11-24)

- **Data:** migration `2026_11_24_120000_create_checklist_party_table` (`checklist_party`, unique per checklist and party, cascades with the checklist); `Checklist::assignedParties()`.
- **Rule (D18):** `ContractAccess::authorizeTick()` allows the edit ability, or else a party assigned to the item's checklist (agents still need `edit`); `allowsTick()` drives the tick boxes. `SetChecklistItemChecked` now runs with `View` and checks this after finding the item.
- **Action:** `SetChecklistAssignee` (owner-only, `Administer`; the party must be attached, 422; another organisation's party 404; complete or cancelled 423). Activity types `checklist.party_assigned` and `checklist.party_unassigned` (subject: the party; payload `checklist_id`, `name`), no emails. `CreateChecklist` keeps the assignees when it replaces a checklist, `StartNewVersion` copies them, `DetachParty` removes the party's assignments on that contract.
- **Web:** the checklist tab lists assigned parties; owners assign an attached party from a select and remove one with the badge's ×.
- **Agents:** checklists carry `assigned_parties` (`id`, `name`). `check_item`/`uncheck_item` descriptions, the server instructions and the skill say so; plugin 1.7.3. Agents cannot assign (owner-only, like roles).

### Change: deleting contracts (2026-11-24)

- **Rule (D19):** `App\Actions\Contracts\DeleteContract`, owner-only (`Administer`, 403). 422 when a newer version exists ("delete the contract from there") or the version is not cancelled.
- **How:** its own transaction instead of `ContractTransaction`, which records readiness changes on the contract after the work. Same lock order: the given version's row first, then the older versions. Versions go newest first (later versions' steps point at the first version's through `origin_step_id`, each version at the one before), each with query deletes, children before parents, since the foreign keys restrict and `StepRevision` refuses model deletes: checklists (stages, items, assignees cascade), sign-offs, invitations, activity, notifications whose `data->contract_id` is the version, approvals, replies then comments, `steps.current_revision_id` set to null, revisions, steps, phases, then the contract (`contract_party` cascades).
- **Queue:** `SendActivityNotifications` and `ContractActivity` have `#[DeleteWhenMissingModels]`, so a job whose activity row went with the contract is dropped instead of failing.
- **Web:** the "Marked cancelled" callout on the latest version shows **Delete contract** to owners, with a confirmation naming how many versions go; afterwards the page redirects to the project.
- **Agents:** no tool. The skill now says an owner can delete a cancelled contract in Akord; plugin 1.7.4.
- **Verified:** Pint pass, Larastan 0 errors, `php artisan test` 269 passed plus 1 skipped (three new tests: everything of a two-version contract goes while another contract, the parties and the project stay; the 403/422 rules; the page button and redirect). The three new tests also pass on MySQL 8.4 (a throwaway Docker container), whose foreign keys are checked row by row. In a browser, on a throwaway copy of the dev database: the cancelled callout shows **Reopen** and **Delete contract**; confirming went to the project page, the contract was gone from the **All** list, and its URL returned 404.
- **Fix (2026-11-25): deleting stayed on the page with a 404.** On NUC03 an owner marked a contract cancelled and then deleted it; the contract was deleted, but the page stayed with Livewire's 404 modal (access log: the `POST /livewire-…/update` returned 404). The status change is broadcast through the queue, so its `onActivity` call can reach the browser just as Delete is clicked, and Livewire 4 sends both in one request: `deleteContract`, then `onActivity`, whose `$this->contract->refresh()` threw for the deleted row and turned the whole response, redirect included, into a 404. Locally (no Reverb) it only showed when the two calls were made in the same tick. `onActivity` now leaves for the project page when the contract no longer exists (`leaveDeletedContract()`, shared with `deleteContract`). Test: `ContractPageTest` sends both calls in one update (fails without the fix). In a browser, the same bundled request went from 404 to 200 and landed on the project page.
- **Other open pages (2026-11-25):** people who had the contract open when someone else deleted it got Livewire's 404 modal on their next action. Now:
  - `DeleteContract` broadcasts `App\Events\ContractDeleted` (queued, `toOthers()`, so not to the deleting tab; Livewire sends `X-Socket-ID`) after the commit, as `deleted` on `private-contract.{id}` of every deleted version, with ids only.
  - The contract page has a "This contract has been deleted" dialog (Flux modal, not dismissible, no Escape or close button) whose Alpine block listens for `.deleted` on the version's channel. **OK** loads the project page, or the dashboard for someone who would no longer see the project (not an owner and no other contract of theirs in it; `afterDeleteUrl`, worked out when the page opens).
  - Fallback when the message is missed (no WebSocket): `resources/js/contract-deleted.js` intercepts Livewire requests answered 404 on a page with that dialog and opens it instead of Livewire's error modal. On a contract page a whole-request 404 only happens when a component's contract can no longer be loaded; refusals inside actions are toasts.
  - Tests: the broadcast (one event, both versions' channels) in `LockAndVersionTest`; the dialog and `afterDeleteUrl` (owner, viewer who loses the project, viewer with another contract there) in `ContractPageTest`. In a browser with local Reverb: a delete from another process opened the dialog, Escape and a click outside left it open, OK went to the project; with the broadcast off, the next click (View as plan) got a 404 and showed the dialog, not the error modal. The deleter's tab went straight to the project; that it gets no dialog was not observed directly.

### Change: agents edit checklist items (2026-11-24)

- **Actions** (`app/Actions/Checklists`), same rules as `AddChecklistItem` (the edit ability, so editor parties, owners as their own party, and agents with `edit`; assigned parties still only tick; 423 on complete or cancelled versions; items of another contract 404):
  - `UpdateChecklistItem`: changes only the given `title`, `notes`, `step_id` (empty notes or step clear them; `step_id` must be an active step of the version); the tick stays. 422 when none is given; no change records nothing. Activity `checklist.item_updated` (payload `fields`).
  - `MoveChecklistItem`: within the stage or to another stage of the same checklist (422 otherwise), `after_item_id` as `after_step_id` (omit: end, `"start"`, or an item of the target stage; itself 422). Activity `checklist.item_moved` (`from_stage_id`, `stage_id`, `position`).
  - `DeleteChecklistItem`: ticked or not; closes the gap. Activity `checklist.item_deleted` without a subject; the payload keeps `item_id`, `title`, `stage_id`, `checklist_id`, `name`, `was_checked` for the description.
  - `App\Support\ChecklistItemOrder` keeps a stage's positions 1..n (like `StepOrder`); `ChecklistInput::findItem()` is the shared lookup, also used by `SetChecklistItemChecked`.
- **Agents:** MCP `update_checklist_item`, `move_checklist_item`, `delete_checklist_item` (26 tools). REST `PATCH`/`DELETE contracts/{id}/checklists/items/{item}`, `POST …/items/{item}/move {stage_id?, after_item_id?}`. Server instructions and the skill ("Keeping it up to date": never delete to hide unfinished work, confirm before deleting ticked items) explain them; plugin 1.7.4, together with the D19 skill line. Published with `a497513` on `main` (the marketplace is the repo, so pushing the version bump is the release; 1.7.3 went out with `9d5f8f4`, 1.7.2 never reached `main`). Checked 2026-11-25: GitHub serves 1.7.4 in both JSON files with the new skill text; here `omp plugin marketplace update akord` then `omp plugin upgrade akord@akord` went 1.7.1 → 1.7.4, and the installed skill has both additions.
- **Web:** each item on the checklist tab has an actions menu for whoever may edit (not assigned parties, who only tick): **Edit** opens an inline form (title, Markdown notes, contract step from the version's active steps; a step archived since stays selectable and is only sent when changed), **Move up**/**Move down** within the stage, **Move to stage** (submenu of the checklist's other stages, to the end), and **Delete** (confirmation; it says when the item is ticked). Hidden while the version is complete or cancelled. The tab refreshes live for the new `checklist.*` activity like the rest.
- **Web, adding:** each stage ends with **Add item** for whoever may edit. It opens a form (title, Markdown notes, contract step) that adds at the end of the stage with `AddChecklistItem` and stays open, cleared, for the next item until **Done**. It closes by itself when the stage is gone (checklist replaced) or the version is closed.
- **Web, filter (2026-11-25):** an **All** / **Not ticked** segmented control beside the checklist name, like the steps filter, kept in the URL as `?items=open` (property `itemFilter`; unknown values fall back to all). Not ticked hides ticked items; stages, their counts, the progress bar and **Add item** stay, a stage with nothing left says "All ticked.", and an empty list says "Every item is ticked." **Move up**/**Move down** are hidden while filtering, since positions count the hidden items. Test in `ChecklistTest`; in a browser the API team checklist went from 5 to 3 items and stayed filtered after a reload.
- **Verified:** Pint pass, Larastan 0 errors, `php artisan test` 275 passed plus 1 skipped (new: the action rules and positions in `ChecklistTest`, REST in `AgentApiTest`, MCP in `McpServerTest`, the tab's item controls and **Add item** in `ChecklistTest`). In a browser, on a throwaway copy of the dev database: the menu and its stage submenu open, the editor saved a new title, **Move down** swapped two items, **Delete** asked for confirmation and removed the item (2 of 4 done), and **Add item** added an item with Markdown notes to the end of its stage (5 → 6 items), left the form open and cleared, and **Done** closed it.

### Change: agents reorder phases (2026-11-25)

- **Gap:** the contract page moves phases up and down (`ReorderPhases`), but agents could only move steps.
- **Action:** `ReorderPhases::handleAfter($actor, $contract, $phaseId, $afterPhaseId)` resolves the order inside the contract lock: `after_phase_id` omitted for the end, `"start"` (`StepOrder::START`) for the start, or a phase of the contract to go directly after it; the phase itself or a phase of another contract is 422, a `phase_id` of another contract 404. It shares the position write and the `phases.reordered` activity with `handle()`, which the page still calls. Same rules as before: the `edit` ability, 423 once agreed, approvals and sign-offs untouched.
- **Agents:** MCP `move_phase` (27 tools, listed after `create_phase`); REST `POST contracts/{id}/phases/{phase}/move {after_phase_id?}`, returning `phase_id` and `position`. The server instructions list it among the structure tools used only when asked; the skill's "Changing the structure" explains it; plugin 1.7.5.
- **Verified:** Pint pass, Larastan 0 errors, `php artisan test` 279 passed plus 1 skipped. New in `AgentStructureTest`: end, start and after-a-phase moves over REST and MCP, with the steps staying in their phase; the refusal test now also covers 403 (commenter, no `edit`), 404, the two 422 anchors and 423 for `move`, with the order unchanged. Not run against a live app.
- **Released** with `f47c3e6` on `main` (plugin 1.7.5). Checked 2026-11-26: GitHub serves 1.7.5 in both JSON files and the skill's `move_phase` line; here `omp plugin marketplace update akord` then `omp plugin upgrade akord@akord` went to 1.7.5. Deployed to NUC03 on 2026-11-26 in `v0.1.0-pre.aea6d91` (§8).
- **Live check on NUC03 (2026-11-26):** this session's omp connection still had the 26 tools from before the deploy (`/mcp reload` is a user command), so the check used a throwaway Sanctum token for OMP - Akord at `/mcp/agents/au6t2ref4gkdhx01`, deleted afterwards (no tokens left). `tools/list` returned 27 tools with `move_phase`. On the throwaway contract `fesj3f9r4593taz3` ("Throwaway: move_phase check", phases Alpha, Beta, Gamma, made with `create_contract` and `create_phase`): Alpha with no anchor went to position 3, Gamma with `"start"` to 1, Alpha after itself gave `422: A phase cannot be placed after itself.` with the order unchanged, and two moves put it back to Alpha, Beta, Gamma (4 `phases.reordered` events, no log file for the day). The contract is still open; an owner can cancel and delete it.

### Fix: tests ran as "local" in a shell that exports `.env` (2026-11-24)

- **Bug:** in a shell that exports the `.env` values (`APP_ENV=local`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `MAIL_MAILER=smtp`, …), 28 tests failed: every web POST got 419 (`OAuthConnectTest`, the Fortify auth tests, invitation registration), and queued notifications never ran (`NotificationsTest`). PHP fills `$_SERVER` from the process environment, and Laravel reads `$_SERVER` before `$_ENV`/`getenv()`. phpunit.xml's `<env>` only sets the latter two (even with `force="true"`), so the app booted as `local`, and `PreventRequestForgery` skips CSRF only when the environment is `testing`. Mail could also have gone out over SMTP.
- **Fix:** phpunit.xml sets the test-only settings with `<server>`, which always sets `$_SERVER`. `DB_*` stay `<env>` so that real environment variables still override them, which the MySQL CI job needs. Risk that remains: a shell exporting `DB_DATABASE` would point the tests at that database.
- **Verified:** `vendor/bin/pest`, 266 passed and 1 skipped, in the exporting shell; `OAuthConnectTest`, `NotificationsTest` and `tests/Feature/Auth` (44) also pass with those variables unset.

### Fix: omp sign-in on another callback port (2026-11-20)

- **Bug:** omp's OAuth callback listener defaults to port 3000; when that port is taken (here by another project's Vite), `oauth.callbackPort` in `.omp/mcp.json` moves it. omp then reuses the client it registered earlier, whose only redirect is `http://localhost:<old port>/callback`, and Passport answered `invalid_client` ("Client authentication failed") because league/oauth2-server ignores the port only for `127.0.0.1` and `[::1]`, not `localhost`.
- **Fix:** RFC 8252 §7.3 (any port for loopback redirects). Akord's `AuthorizationController::authorize` first calls `LoopbackRedirect::allowPortChange`: for a public client whose registered redirects are all plain-http loopback (`localhost`, `127.0.0.1`, `[::1]`), a redirect with the same path on another loopback port is added to the client. Other paths, hosts, schemes and confidential clients are untouched and still refused. The token exchange only compares against the authorization code, so it needs nothing.
- **Test:** `OAuthConnectTest` "a local client may come back on another loopback port…": the full flow on a new port, and refusals for another path, host, https and a portless URI.
- **Deployed** to NUC03 on 2026-11-20 in the uncommitted build `v0.1.0-pre.9666667.oauth` (§8). Before that, the workaround was to set `callbackPort` to the port the client was registered with (NUC03's `oauth_clients.redirect_uris`).
- **Cap (after that deploy, not yet on NUC03):** Passport validates the authorize request before sending a guest to log in, so the port rule has to run for guests too, which let anyone add ports. A client now keeps at most 10 redirect URIs: the one it registered, plus the latest others. A deploy-check probe on NUC03 added `http://localhost:3334/callback` to client `01a1f354…` (party `kngbw6thjyj42tpf`), the port that repo now uses.

### Change: long contracts load faster (2026-11-20)

- **Problem:** every step on the contract page is its own Livewire component, and each loaded its own data and repeated the acting party and ability checks: about 45 queries per step. A 160-step test contract took 7,172 queries (3.6 s locally); NUC03's 84-step contract was slow to open on the Steps tab.
- **Fix:** `App\Support\StepListing` loads what the step rows show (current revisions with authors, required parties, counting approvals, the acting party's approval states, comment threads and counts, abilities) for all steps in a fixed number of queries. The page hands one to each step component's mount (`:listing`); it loads lazily, so page re-renders that mount no step load nothing. Later requests of a step component load their own data through the same static helpers. 160 steps: 112 queries, ~1 s; NUC03's 84 steps: 107 queries, ~0.7 s.
- **Test:** `ContractPageTest` "the steps panel loads in the same number of queries however many steps it lists".
- **Not done:** `steps-refresh` (acting party switch, status change, party activity) still re-renders every step component with its own queries; each step still renders ~20 KB of hidden markup.
- **Deployed** to NUC03 on 2026-11-20 in the uncommitted build `v0.1.0-pre.111c1d8.steps`.

### Change: archiving needs every required party's approval (2026-11-21, D16)

- **Data:** migration `2026_11_21_120000_add_proposes_archive_to_step_revisions` adds `step_revisions.proposes_archive` (boolean, default false). `StepRevision::contentHash()` adds an `'archive'` marker to the hashed content of a proposal; the hash of every other revision is unchanged, so stored sign-off fingerprints stay valid.
- **Actions:** `ArchiveStep` saves revision N+1 with the same text and `proposes_archive` (activity `step.archive_proposed`, with `cancelled_party_ids`), or archives at once when the contract has no required approvers. `ArchiveStep::settle()` archives steps whose proposal every required party approves (`step.archived` with `approved_revision`); `ContractTransaction` runs it after every change to an unlocked contract, so it follows the last approval, a required party's removal or a reopening. `ReviseStep` creates a revision even for identical text when the current one is a proposal, withdrawing it (`archive_withdrawn`, `text_changed` in the payload).
- **Web:** the step menu offers **Propose archiving** (or **Archive** without required approvers); a pending step shows an amber "Archiving proposed" badge, a callout naming who proposed it with **Keep step**, and tick tooltips about approving the archive. History marks proposal revisions.
- **Agents:** `archive_step` returns `archived`, `archive_proposed` and `revision`; steps and revisions carry `archive_proposed` / `proposes_archive`; tool descriptions, server instructions and the skill explain it (plugin 1.7.0). The plan document marks pending steps.
- **Notifications:** a proposal tells everyone on the contract; parties whose approval it set aside are told so.
- **Tests:** `ArchiveApprovalTest` (proposal lifecycle, withdrawal, no required approvers, settling on a required party's removal, the hash), a notification test, and the existing archive tests now archive through a proposal (`Scenario::archive()`).
- **MySQL (D12):** checked on NUC03's MySQL 8.4 during the deploy, after a backup (`akord-v0.1.0-pre.111c1d8.steps-20261122-140344.sql`): the column is `tinyint(1) NOT NULL DEFAULT 0` after `description_md`, and the 182 existing revisions are 0. `migrate:rollback` is refused in production (destructive commands are prohibited), so `down()` is not checked on MySQL. The flow was run on MySQL with real data inside a rolled-back transaction (queue, broadcasts and mail discarded): two steps on contract `5iew756h5ph3jl1n`; a proposal (revision 2) stayed active and kept the contract from being ready, was archived only after the second of two required approvals, recorded `step.archive_proposed` and `step.archived`, and keeping the other step restored its original hash. Row counts were unchanged afterwards.
- **Deployed** to NUC03 on 2026-11-22 in the uncommitted build `v0.1.0-pre.111c1d8.d16` (also contains the earlier speed-up). After the switch, compiled views were cleared so the shared Livewire cache is rebuilt from this release.

### Change: connection links, abilities set on the party (2026-11-22)

**Problem.** Connecting an agent meant copying `.omp/mcp.json` from the Agent access page into the repo, and the owner chose the agent's abilities on the OAuth approval screen, so what an agent could do was decided during sign-in, per credential, instead of in Akord.

**Decisions:**
- **Abilities belong to the agent party** (`parties.agent_abilities`), set only on the Agent access page. Every credential of the party (MCP sign-ins and claim-code tokens) gets them, read on every request. Per-contract permissions stay the party's role on each contract (editor, commenter, viewer), set in the contract's settings.
- **A one-time connection link** replaces copying the config. The agent fetches the config with it and saves it git-ignored; the user then runs `/mcp reload` and `/mcp reauth akord` and approves in the browser. omp only lets the user run `/mcp` commands, so the agent cannot finish the sign-in itself. The browser sign-in (OAuth) stays; the link carries no credential.

**Changes:**
- **Data:** migration `2026_11_22_120000_move_agent_abilities_to_parties` adds `parties.agent_abilities` (json, null for people and teams). Each agent party gets every ability any of its live credentials had (active connections, Sanctum tokens; `*` means all), so no connected agent loses one; a party without credentials gets read and comment. It then drops `agent_connections.abilities` and `agent_claim_codes.abilities`. `down()` copies the party's abilities back to each credential. Migration `2026_11_22_120100_create_agent_connection_links_table` adds `agent_connection_links` (`token_hash`, `created_by`, `expires_at`, `used_at`; morph name `agent_connection_link`).
- **Abilities:** `Party::agentAbilities()`; `AgentActor` uses it for both credential kinds; `SetAgentAbilities` sets the party's (owner-only, agent parties only, `read` kept). New agent parties get `AgentAbilities::DEFAULT` (read, comment) in `CreateParty`. Claim codes and their tokens no longer carry abilities (`CreateAgentClaimCode::handle($owner, $agent)`; tokens are created with Sanctum's default `*` and ignored). The approval screen (`oauth/authorize`) and `ConnectAgentController` no longer take abilities. Refusals now say "This agent party lacks the '…' ability."
- **Connection links:** `CreateAgentConnectionLink` (owner-only, agent parties only; 40 random characters, only the hash stored, `akord.agents.connection_link_hours`, default 24). `ConnectionLinkController`, public, `throttle:agent-claim`:
  - `GET /connect/{token}` returns Markdown instructions for the agent and does not use up the link, so link previews and repeated reads are harmless: POST to the link; for omp, merge the `akord` server into `.omp/.mcp.json` (removing an `akord` entry from `.omp/mcp.json`, which would take precedence) and git-ignore it; for Claude Code, `claude mcp add --transport http akord <url>`; then tell the user to run `/mcp reload` and `/mcp reauth akord`.
  - `POST /connect/{token}` uses up the link (row locked) and returns `PartyMcpUrl::config()`, `{"mcpServers": {"akord": {"type": "http", "url": "…/mcp/agents/{id}"}}}`. Used or expired: 410; unknown: 404; errors are JSON or Markdown, not HTML, because an agent reads them.
  - The POST route is excluded from CSRF with `withoutMiddleware(PreventRequestForgery::class)`. Laravel 13 renamed the web group's CSRF middleware; excluding `ValidateCsrfToken` had no effect (419), which only the live check below caught, because tests skip CSRF.
- **Agent access page:** one **Abilities** row for the party (each click saves); **Create connection link** (shown once, with "Connect this repository to Akord: <link>" to paste to the agent) and a list of recent links; the MCP URL for setting a client up by hand; connected tools and tokens without ability columns; each contract with the party's role. The copy-this-`mcp.json` block and `partials/agent-abilities` are gone.
- **Skill** (plugin 1.7.0, not yet released): the config is `.omp/.mcp.json`, git-ignored; abilities and roles are set in Akord; without tools, ask the user for a connection link.
- **Tests:** `AgentConnectionLinkTest` (instructions until used, the config exactly once, then 410; expired 410 and unknown 404; owner-only, agent parties only), party abilities over a token (`AgentAccessTest`) and over an OAuth connection (`OAuthConnectTest`), `AgentClient::on` sets the party's abilities.
- **Verified:**
  - Pint (changed files) pass, Larastan 0 errors, `vendor/bin/pest`: 262 passed plus 1 skipped.
  - Migration on a copy of the dev SQLite database with added credentials: an active `[read, edit]` connection plus a revoked `[read, approve]` one gave `[read, edit]`; a `*` token gave all five; no credentials gave `[read, comment]`; people and teams null; both ability columns dropped; rollback restored each credential's abilities.
  - Against the running app (on that copy): `GET /connect/{token}` twice gave the Markdown (`text/markdown`), the first `curl -sS -X POST` gave the config JSON, the second 410 with the message, and GET then 410.
- **MySQL (D12):** run on NUC03's MySQL 8.4 during the deploy, after a backup (`akord-v0.1.0-pre.111c1d8.d16-20261122-150202.sql`, 38 tables). Before: five agent parties, each with one active connection holding all five abilities, and no tokens. After (69 ms + 116 ms): all five have `["read", "comment", "edit", "approve", "create"]`, people `NULL`; `agent_connections.abilities` and `agent_claim_codes.abilities` are gone; `agent_connection_links` has its unique `token_hash` and both foreign keys. `down()` is not checked on MySQL (destructive commands are prohibited in production).
- **Deployed** to NUC03 on 2026-11-22 in the uncommitted build `v0.1.0-pre.111c1d8.links`. Checked afterwards: `/up` and `/login` 200; `GET /connect/<unknown>` 404 Markdown and `POST` 404 JSON (so the new routes run and the POST is past CSRF); queue and Reverb active; no errors in the log; this repo's OAuth connection (party OMP - Akord) still lists contracts over MCP.
- **Not done:** an end-to-end run with omp (agent follows the link, `/mcp reload`, `/mcp reauth akord`); the Agent access page was not opened in a browser on NUC03.
- **Fix (2026-11-24): the config goes in `.omp/mcp.json`, not `.omp/.mcp.json`.** First real run (repo `api.stepone.nuc03`): the agent wrote `.omp/.mcp.json` as told, then `/mcp reload` + `/mcp reauth akord` gave "Server akord not found" and `/mcp list` "No MCP servers configured". omp reads `.omp/.mcp.json` only as a compatibility source; `/mcp list` shows entries from the primary files (`.omp/mcp.json`, user `mcp.json`) plus servers that connected, and an Akord server can't connect before the sign-in. The link instructions (`ConnectionLinkController`) and the skill now name `.omp/mcp.json` (git-ignored as `/.omp/mcp.json`). The skill fix ships as plugin **1.7.1**: 1.7.0 had already been pushed with the old text, and `omp plugin upgrade` reinstalled that old content under the unchanged version.

### Change: header bar instead of the sidebar (2026-11-23)

- **Layout** (`layouts/app.blade.php`, now the whole page shell; `layouts/app/sidebar` and the unused starter `layouts/app/header` are gone): a floating bar, sticky at the top, as wide as the widest page (`max-w-6xl`), with two rows:
  - Top: the logo (to the dashboard) on the left; on the right a light/dark switch (moon in light mode, sun in dark; `$flux.dark = ! $flux.dark`, saved by Flux in localStorage as `flux.appearance`, so it replaces "System" from the Appearance settings), the notifications bell (unread count as a badge, `99+` above 99) and the user menu (avatar and name; Settings, Log out). The name hides below `sm`.
  - Bottom: the breadcrumb. Pages set it with `<x-slot:breadcrumbs>` inside their root element; Livewire forwards slots from a page to its layout. A page without one gets no second row. Long crumbs shrink and truncate instead of wrapping. The slot renders with the page, so a Livewire update (not a navigation) does not refresh it.
- **Content** is centred under the bar: each page keeps its own `max-w-*`, inside a centred `max-w-6xl` column.
- **Breadcrumbs added** for the dashboard (Organisations), Notifications and the settings pages (Settings › Profile, Security, Appearance).
- `html` has `scroll-pt-36`, so step links (`#step-…`, `scrollIntoView`) stop below the bar.
- **Removed:** the Repository link, `components/desktop-user-menu`, the sidebar-only Flux icon overrides (`folder-git-2`, `chevrons-up-down`, `layout-grid`, `book-open-text`), and the collapsed-sidebar logo variants in `components/app-logo`.
- **Verified:** `vendor/bin/pest`: 262 passed plus 1 skipped. In a browser (light mode): dashboard and contract page at 1400 px, scrolled contract page (bar stays on top), and 390 px with the user menu open.
- **Deployed** to NUC03 on 2026-11-23 in `v0.1.0-pre.9b0075f`, the first build of a pushed commit since the uncommitted builds of 2026-11-20 to 22 (pushed to `main` together with `38ddde7`, which commits those builds). Checked afterwards: `/up` and `/login` 200, `/dashboard` 302 to login, the login page serves the new CSS bundle, queue and Reverb active, no new log entries, and this repo's MCP connection still lists contracts. Signed-in pages were not opened in a browser on NUC03.

### Order of work

Phases 1–13 are done, in order.

## 5. Files and areas

- `app/Models/*`
- `app/Actions/**`
- `app/Policies/ContractPolicy.php` and other policies
- `app/Events/*`
- `app/Livewire/**` and `resources/views/livewire/**`
- `app/Http/Controllers/Api/V1/*` and `app/Http/Resources/*`
- `app/Mcp/Servers/ContractServer.php` and `app/Mcp/Tools/*`
- `routes/web.php`, `routes/api.php`, `routes/channels.php`, `routes/ai.php`
- `database/migrations/*` and `database/factories/*`
- `app/Mail/InvitationMail.php` and `app/Notifications/*`
- `tests/Feature/**`

## 6. Tests (Pest feature tests)

1. **Approvals:**
   - Approve revision N, then edit the step's content: the approval no longer counts, but it stays in history.
   - Approve revision N, then make a spacing-only edit: revision N+1 is recorded and the approval still counts.
   - Reordering, moving the step, or renaming its phase keeps the approval.
2. **Readiness:**
   - All steps approved gives `ready_for_signoff`.
   - Not all approved, or no active steps, gives `draft`, and a sign-off returns `422`.
3. **Sign-off:**
   - All required parties sign off: `agreed` and locked.
   - A sign-off from a non-required party does not count.
   - A sign-off from a viewer party returns `403`.
4. Every "cancels" row in the §2 change table invalidates sign-offs, and every "No" row preserves them.
5. A sign-off with a stale fingerprint returns `409` and records nothing.
6. Two concurrent final sign-offs lock the contract exactly once (one `contract.agreed` event).
7. **After lock:**
   - Every write returns `423`, except `StartNewVersion`.
   - The new version links back to v1, carries over the approvals that counted, has no sign-offs, and starts `ready_for_signoff` (D13; it started in `draft` before 2026-11-19). It starts `open` whatever v1's status.
   - A complete or cancelled version also returns `423` for every write except invitations, until reopened. Closing and reopening keeps approvals and sign-offs (D14).
8. A stale edit (simultaneous edits) returns `409` or the Livewire error, and no revision is lost.
9. **Visibility:**
   - An owner sees every contract in the organisation; a non-owner sees only attached contracts.
   - A user or agent from another organisation gets `404` on web routes, the API, MCP tools, broadcast channel authorisation, `activity?since`, and the status and sign-off endpoints.
10. **Roles:**
    - A viewer cannot comment, edit or approve.
    - A commenter cannot edit.
    - A token without the `approve` ability gets `403`.
11. **Invitations:** expired, reused, or wrong-email links are rejected. A valid link adds the person to the correct party and contract.
12. **Agent tokens:** a claim code works only once, and a revoked token gets `401`.
13. **D10:** with `require_human_signoff` on, an agent's sign-off returns `403` and records nothing. With it off, the agent's sign-off succeeds.
14. **Manual run-through:** two browsers (an owner and an invited guest) plus one agent via `curl` and the MCP Inspector:
    - invite → edit → comment → approve
    - another party edits → the approval-cancelled notice appears live → re-approve
    - "Ready for sign-off" panel appears live → one party signs off → an edit cancels it live
    - re-approve and sign off → locked → start v2

## 7. Risks

- **Cross-organisation leaks**, the main risk. Each entry point gets its own leak test.
- **Approval churn from agents.** Reduced by D11: spacing-only edits don't cancel approvals.
- **Markdown spacing that carries meaning.** Indentation can turn text into a code block or a nested list, and D11 treats that as a spacing-only edit, so it doesn't cancel approvals. This follows directly from D11. The revision is still recorded and its diff can be viewed.
- **Prompt injection** through contract text read by agents.
- **Sign-off races.** Handled by locking the contract row in `SignOffContract`. This can only be proven on MySQL or PostgreSQL (see "Database portability").
- **Activity cursor gaps** on MySQL and PostgreSQL under concurrent writes. Solved by the commit-order sequencer (see "Database portability"); the feed must page by `seq`, never by `id`.
- **Sign-off loops** caused by agents that keep editing. A "freeze for sign-off" mode was deferred.
- **Fingerprint stability.** Tests must pin it.
- **Reverb** needs a supervised process in production.
- **`laravel/mcp` is young** (v1.x), so its API may change between minor versions.
- **`phpseclib/phpseclib` 4.0.1 advisory** (PKSA-h7cz-q7zq-mdkt, unbounded PBKDF work on crafted encrypted keys). Passport 13.11 pins exactly that version, so it can't be upgraded yet. Accepted: Passport only loads its own unencrypted signing keys from `storage/`, never keys from users. Upgrade when Passport allows.
- **OAuth approvals as phishing:** a link to `/oauth/authorize` can be sent to an owner. Limited by loopback-only self-registration, and the consent screen names the tool and where the approval goes.

## 8. Environment and current state

- PHP 8.3.23 (NTS x64), with pdo_sqlite, sqlite3, intl, mbstring, sodium, sockets, zip, bcmath, gd, openssl and curl. Composer 2.8.4, Node v24.20.0, npm 11.19.0, git 2.44.0.
  - CA bundle (2026-11-17): `C:\Program Files\php\extras\ssl\cacert.pem` (curl.se's Mozilla bundle dated 2026-11-05, SHA-256 checked), set as `curl.cainfo` and `openssl.cafile` in `C:\Program Files\php\php.ini`. The previous ini is `php.ini.bak-20261117-110806`. Editing it needs admin. Refresh the bundle now and then from https://curl.se/ca/cacert.pem.
- The global `laravel` installer is broken in bash. Use `composer create-project` instead, or call the installer through `php`.
- Run the app with `composer dev` (`php artisan dev`). It starts serve, `queue:listen`, Vite, and `reverb:start`; Reverb registers itself. Pail is skipped on Windows because it needs `pcntl`.
- **After changing `.env`, restart `composer dev` completely.** `artisan dev` loads `.env` into its own environment and its child processes inherit it as real environment variables, which `.env` never overrides. So `serve`'s automatic "Environment modified. Restarting server" keeps the old values. This is how invitation mail kept going to the old `MAIL_HOST` until a full restart. One-off commands (`tinker`, tests) read `.env` fresh, so they can disagree with a running server.
- Repository: https://github.com/PatFox/Akord (public, MIT). Branch: `main`.
- Fresh clone: run `composer setup`, then fill in `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env`; any random strings work locally. They are blank in `.env.example` so no keys are published.
- Passport signing keys: `storage/oauth-private.key` and `storage/oauth-public.key`, created by `composer setup` (or `php artisan passport:keys`). Never commit them.
- Next: all planned phases are done. Open items:
  - Phase 8 end to end: browser plus Mailpit.
  - Phase 9 end to end with omp in two repos.
- **Reverb port on this machine: 8082** (2026-11-13).
  - 8080 is the `monavate-bank-api` Docker container, and 8081 is the `monavate-bank-mobile` Expo dev server.
  - `.env` sets `REVERB_PORT=8082` and `REVERB_SERVER_PORT=8082`. Since Phase 12 the page passes them to Echo at runtime, so Vite no longer needs restarting; `composer dev` still does (see above). The `VITE_REVERB_*` lines left in this machine's `.env` are unused.
  - `.env.example` now documents `REVERB_SERVER_PORT`, still 8080 by default.
  - Checked in a browser: Echo connected to `ws://localhost:8082` and subscribed to `private-contract.4`. A step the agent added over REST appeared on the owner's open page about 0.9 s later, with the "contract changed while you were reviewing it" notice. The broadcast job reported `DONE`; before the move it failed.
- **Stable instance on this machine:** ports 8100 (web) and 8101 (Reverb), so it runs beside dev (8000, 8082, Vite 5173).
- **Server instance on NUC03 (`http://akord.nuc03`), installed 2026-11-17, moved to MySQL the same day:**
  - **Server:** `pat@192.168.1.205` (key in `~/.ssh/config`), running Ubuntu **26.04**, Apache 2.4.66 and PHP **8.5.4** with **mod_php** (prefork), like the other `.nuc03` vhosts. `sudo` needs a password, so root steps go to the user as a script.
  - **Release:** `v0.1.0-pre.aea6d91`, a local `build-release.php` of the pushed commit `aea6d91`, deployed 2026-11-26 with scp; no GitHub release exists yet. The notes below are from the first update with this procedure (`v0.1.0-pre.9666667.oauth`, 2026-11-20).
    - The update script followed the README with the §8 changes. The `mysqldump` backup is `backups/akord-v0.1.0-pre.9666667-20261120-171745.sql`, and there was nothing to migrate.
    - There was no Apache reload: after 2 minutes in maintenance mode, all 20 probe requests ran the new release.
    - Laravel 13.42 runs on PHP 8.5 without errors.
    - **Release history:**
      - `v0.1.0-pre.aea6d91` (2026-11-26): agents reorder phases (`move_phase`, 27 MCP tools; `POST …/phases/{phase}/move`); deleted contracts redirect and dialog for other open pages (`ContractDeleted` broadcast); checklist **Not ticked** filter. Nothing to migrate; backup before it is `backups/akord-v0.1.0-pre.a497513-20261126-092529.sql` (40 tables). Same script as `9d5f8f4`. Checked afterwards: `/up` and `/login` 200, `/dashboard` 302, queue and Reverb active, the move route in `route:list`, `ContractServer` registers 27 tools, no compiled views owned by `pat`, no log file for the day, this repo's MCP connection lists contracts. `view:clear` leaves Blaze's cache (`storage/framework/views/blaze`, 537 files since 2026-11-22, all `www-data`).
      - `v0.1.0-pre.a497513` (2026-11-25): D19 deleting cancelled contracts; agents and the checklist tab change, move, delete and add checklist items (26 MCP tools). Nothing to migrate; backup before it is `backups/akord-v0.1.0-pre.9d5f8f4-20261125-105036.sql` (40 tables). Same script as `9d5f8f4`. Checked afterwards: `/up` and `/login` 200, `/dashboard` 302, queue and Reverb active, the three new REST item routes in `route:list`, `ContractServer` registers 26 tools, no compiled views owned by `pat`, no log file for the day, this repo's MCP connection lists contracts.
      - `v0.1.0-pre.9d5f8f4` (2026-11-24): D18 checklist assignees, D17 role changes after agreement, stage headings without a repeated number; `create_checklist_party_table` migration (109 ms). Backup before it is `backups/akord-v0.1.0-pre.549611b-20261124-170844.sql` (39 tables). Same procedure as `9b0075f`, run as one script (`sha256sum -c`, unzip and links, cache `config`/`route`/`event`, `down`, stop units, mysqldump, `migrate`, switch `current`, wait out the 125 s, start units, `up`, `view:clear`). Checked afterwards: `/up` and `/login` 200, `/dashboard` 302, queue and Reverb active, `checklist_party` has both foreign keys (`checklist_id` cascading) and the unique pair, the deployed presenter returns `assigned_parties`, no compiled views owned by `pat`, no log file for the day, this repo's MCP connection lists contracts.
      - `v0.1.0-pre.549611b` (2026-11-24): connection-link instructions name `.omp/mcp.json`. Nothing to migrate; backup before it is `backups/akord-v0.1.0-pre.81ebcf5-20261124-110705.sql` (39 tables). Same procedure as `9b0075f` (caches `config`, `route`, `event`; 125 s in maintenance mode; `view:clear`). Checked afterwards: `/up` and `/login` 200, `/dashboard` 302, `/connect/<unknown>` 404, queue and Reverb active, the deployed controller names `.omp/mcp.json`, no log file for the day, this repo's MCP connection lists contracts.
      - `v0.1.0-pre.81ebcf5` (2026-11-23): phase rows (approval badge in its own column, long titles beside the chevron). Nothing to migrate; backup before it is `backups/akord-v0.1.0-pre.9b0075f-20261123-144828.sql` (39 tables). Same procedure as `9b0075f`. Checked afterwards: `/up` and `/login` 200, `/dashboard` 302, queue and Reverb active, this repo's MCP connection lists contracts. The only log entry of the day is omp's expired access token at 14:17 (401, then a successful refresh), not an error in Akord.
      - `v0.1.0-pre.9b0075f` (2026-11-23): header bar instead of the sidebar, centred content, light/dark switch; commits the three `111c1d8.*` builds (`38ddde7`). Nothing to migrate; backup before it is `backups/akord-v0.1.0-pre.111c1d8.links-20261123-141454.sql` (39 tables). Caches `config`, `route` and `event` only, no Apache reload: 125 s in maintenance mode, then `view:clear`.
      - `v0.1.0-pre.111c1d8.links` (2026-11-22): connection links and party abilities, `move_agent_abilities_to_parties` and `create_agent_connection_links_table` migrations; backup before it is `backups/akord-v0.1.0-pre.111c1d8.d16-20261122-150202.sql`. No Apache reload: 125 s in maintenance mode, then `view:clear`.
      - `v0.1.0-pre.111c1d8.d16` (2026-11-22): D16 archive approval, `add_proposes_archive_to_step_revisions` migration (27 ms); backup before it is `backups/akord-v0.1.0-pre.111c1d8.steps-20261122-140344.sql`. Right after it, contract pages returned **500** for about two minutes: see "compiled views" below.
      - `v0.1.0-pre.111c1d8.steps` (2026-11-20): the contract-page speed-up (`StepListing`); backup before it is `backups/akord-v0.1.0-pre.9666667.oauth-20261120-220648.sql`.
      - `v0.1.0-pre.9666667` (2026-11-20): long titles, sidebar, step filter, linked changes, named checklists, `name_checklists` migration (108 ms; the existing checklist became "Mobile App"); backup before it is `backups/akord-v0.1.0-pre.3577ac5.layout-20261120-165350.sql`.
      - `v0.1.0-pre.3577ac5.layout` (2026-11-20): the uncommitted long-title and sidebar build; backup before it is `backups/akord-v0.1.0-pre.3577ac5-20261120-152641.sql`.
      - `v0.1.0-pre.3577ac5` (2026-11-19): D15 checklists, `create_checklists_tables` migration (447 ms); backup before it is `backups/akord-v0.1.0-pre.ada0c9b-20261119-172407.sql`.
      - `v0.1.0-pre.ada0c9b` (2026-11-19): step links and the plan view; backup before it is `backups/akord-v0.1.0-pre.94afd32-20261119-165739.sql`.
      - `v0.1.0-pre.94afd32` (2026-11-19): steps on the contract page; backup before it is `backups/akord-v0.1.0-pre.d355a47-20261119-153345.sql`.
      - `v0.1.0-pre.d355a47` (2026-11-19): D14, `add_status_to_contracts` migration; backup before it is `backups/akord-v0.1.0-pre.451dbae-20261119-140259.sql`.
      - `v0.1.0-pre.451dbae` (2026-11-19): D13; backup before it is `backups/akord-v0.1.0-pre.d2da2cf-20261119-094422.sql`.
      - `v0.1.0-pre.d2da2cf` (2026-11-18): `change_note` migration; backup before it is `backups/akord-v0.1.0-pre.eab7f57-20261118-180222.sql`.
      - `v0.1.0-pre.eab7f57` (2026-11-18): comment-thread fix; backup before it is `backups/akord-v0.1.0-pre.8b88971.ids-20261118-174050.sql`.
      - `v0.1.0-pre.8b88971.ids`: the uncommitted Phase 13 build.
      - `v0.1.0-pre.8b88971`: integer ids, SQLite.
      - All of them are still in `releases/`.
  - **Database:** MySQL **8.4.13**. Database `akord` (utf8mb4), user `akord@localhost` (`caching_sha2_password`, rights on `akord.*` only).
    - Akord connects over the Unix socket: `DB_SOCKET=/var/run/mysqld/mysqld.sock`. The password is only in `shared/.env`.
    - MySQL `root` has its own password (not socket auth), so creating databases or users needs the user (`mysql -u root -p`); `sudo` alone is refused.
    - The database started empty. The SQLite file from the first install, `shared/database/akord.sqlite` (integer ids, one account and organisation), is unused and kept, along with `.env` from before the switch: `backups/env-v0.1.0-pre.8b88971-20261117-164709`.
  - **Layout:** as in the README, rooted at `/var/www/vhosts/akord.nuc03` (`releases/`, `shared/`, `backups/`, `current`). The files are owned by `pat`, not `www-data`. Apache (`www-data`, which is in group `pat`) and `pat` share `shared/storage` and `shared/database` through ACLs: `u:www-data:rwX,u:pat:rwX`, plus the same as default ACLs on the directories. `.env` is 640 with `u:www-data:r`, and the OAuth keys are 660.
  - **Compiled views must be created by `www-data`.** Blade `touch()`es each compiled file to its source's mtime, and only a file's owner may set its timestamps; group or ACL write is not enough. Files compiled as `pat` (by `php artisan optimize`/`view:cache`, or by a script that renders pages) make Apache fail with `touch(): Utime failed: Operation not permitted` (500) as soon as it recompiles one. On 2026-11-22 the deploy's `optimize` and a post-deploy render check left 671 such files; `php artisan view:clear` as `pat` fixed it. After a deploy or any page-rendering check run as `pat`, finish with `view:clear`, and cache only `config`, `route` and `event` (not `optimize`, which also caches views).
    - Compiled views that `www-data` writes have mask `r-x`. That's harmless: Laravel replaces them by renaming. (`view:cache` as `pat` only seemed to work earlier: the files it leaves break Apache's next recompile, as above.)
  - **Vhost:** `/etc/apache2/sites-available/akord.nuc03.conf` follows the house style, with the README's `/app/` and `/apps/` Reverb proxy. Plain http (LAN name, no certificate), so `SESSION_SECURE_COOKIE=false`, and passkeys don't work (no secure context).
  - **Name resolution:** dnsmasq on the server answers `*.nuc03`, but the server itself and this PC resolve through `hosts` files. Both have `akord.nuc03` (`127.0.0.1` on the server, because Akord publishes events to its own URL; `192.168.1.205` on the PC).
  - **Mail** goes to Mailpit: SMTP `127.0.0.1:1025`, UI at `http://mailpit.nuc03`, API at `127.0.0.1:8025`.
  - **Reverb (`127.0.0.1:8080`) and the queue worker** are systemd **user** units, `~/.config/systemd/user/akord-{reverb,queue}.service`, the same way `loom-*` runs. `pat` has lingering on. Manage them with `systemctl --user …` and `journalctl --user -u akord-queue`.
  - **Updating** follows README "Updating on an Ubuntu server" with these changes:
    - the path is `/var/www/vhosts/akord.nuc03`;
    - run artisan as `pat`, without `sudo -u www-data`, and skip the `chown`;
    - `systemctl --user stop|start akord-queue akord-reverb`;
    - **back up with mysqldump** instead of copying the SQLite file: `MYSQL_PWD=… mysqldump -u akord -S /var/run/mysqld/mysqld.sock --single-transaction --no-tablespaces akord > backups/akord-$OLD-$(date +%Y%m%d-%H%M%S).sql` (35 tables, checked). Restore with `mysql -u akord -p -S /var/run/mysqld/mysqld.sock akord < backups/<file>.sql`;
    - the reload is `sudo systemctl reload apache2` (mod_php; the only root step). After the 2026-11-17 switch it wasn't needed: once mod_php's `realpath_cache_ttl` (120 s) had passed in maintenance mode, every request ran the new release (12 page loads created 12 sessions in MySQL and none in SQLite). Wait out the 120 s before `artisan up` if you skip the reload.
  - **Checked after install:**
    - `/up` returns 200 from the PC and from the server, and the login page renders.
    - Echo connected through Apache, and an event published from the server through `/apps/` reached the browser.
    - `Artisan::queue` ran on the worker, a test email reached Mailpit (deleted afterwards), and the web server wrote sessions.
    - PHP receives `Authorization`.
    - No accounts or data were created.
  - **Checked after the MySQL switch:**
    - `/up`, `/login` and `/register` return 200.
    - The schema has `char(16)` ids and `activity_events.position`, all 26 migrations ran, and there are no users.
    - A queued job ran on the worker (0 jobs left, 0 failed), and a broadcast went through `/apps/` (200).
    - No Laravel errors were logged.
  - **From omp:** `artisan tinker <file>` over ssh waits for stdin afterwards, so add `< /dev/null`. `pkill -f` over ssh can match its own command line; use a `[a]rtisan` pattern.
- **Running the dev server from omp:**
  - Start it as a named service and set `proc://akord-dev/mode` to `detached`, so it survives omp restarts.
  - Switching the mode starts a second copy, and stopping a service leaves its child processes running on Windows. Check for leftover `artisan`, `concurrently` and `vite` processes and stop them.
  - Use `TEMP`/`TMP` = `C:\Users\micro\AppData\Local\Temp`. The launcher's temp folder holds locked `sf_proc_*` files that make `composer dev` fail.
