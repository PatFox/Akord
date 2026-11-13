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
| D3 ✅ | A locked contract is fully read-only, comments included. The only thing allowed is "Start new version". |
| D4 ✅ | Only organisation owners can invite people, attach parties to contracts, set required approvers, and start a new version. |
| D5 ✅ | User accounts are global, so an outsider can be invited to a single contract and see nothing else. |
| D6 ✅ | Each agent is its own party. |
| D7 | Automatic locking. **Replaced by D8.** |
| D8 ✅ | Once every active step is approved, **every required party signs off** on the whole contract. The last sign-off locks it. |
| D9 ✅ | Reordering or moving steps does **not** cancel sign-offs. |
| D10 ✅ | A per-contract setting, `contracts.require_human_signoff` (boolean, default false). When it is on, `SignOffContract` rejects agent tokens with `403`. |
| D11 ✅ | Spacing-only edits do **not** cancel approvals or sign-offs. The edit is still saved as a new revision so it shows in history. |
| D12 ✅ | **Database portability.** The schema and queries must work on SQLite (development and tests) and **MySQL 8**, and both are verified. PostgreSQL is a design consideration but untested. Migrations use only the schema builder, with no raw SQL. Anything one engine can't do needs a portable equivalent. See "Database portability" in §2. |

## 2. Data model

| Table | Key fields | Notes |
|---|---|---|
| `organisations` | name, slug | The top tenancy boundary |
| `organisation_user` | organisation_id, user_id, role (`owner`) | Records org owners. Guests have no row. |
| `users` | standard | Global accounts |
| `projects` | organisation_id, name | |
| `parties` | organisation_id, name, type (`individual`/`team`/`agent`) | Org-level, so a party can be reused across contracts |
| `party_user` | party_id, user_id | Agents have no rows here |
| agent tokens | Sanctum `personal_access_tokens`, attached to the agent party | Abilities: `read`, `comment`, `edit`, `approve` |
| `agent_claim_codes` | party_id, code_hash, expires_at, claimed_at | One-time code an agent exchanges for a token |
| `contracts` | project_id, title, version, previous_version_id, agreed_at, agreed_fingerprint, require_human_signoff (bool, default false) | No status column. Agreed means `agreed_at` is set; `draft` and `ready_for_signoff` are worked out when needed. |
| `contract_party` | contract_id, party_id, role (editor/commenter/viewer), is_required_approver | Controls access and approval |
| `invitations` | organisation_id, contract_id, party_id, email, token_hash, expires_at, accepted_at, invited_by | Invitations are per contract |
| `phases` | contract_id, title, position | |
| `steps` | phase_id, position, current_revision_id, archived_at, origin_step_id | `origin_step_id` links the same step across versions |
| `step_revisions` | step_id, number, title, description_md, content_hash, author_party_id, author_user_id, change_note | Append-only, full text in every row. Unique on (step_id, number). `content_hash` hashes the title and description after whitespace is normalised (D11). |
| `comments` | step_id, step_revision_id, parent_id, author_party_id, author_user_id, kind (`question`/`suggestion`/`general`), body_md, resolved_at | Linked to the revision it discusses |
| `approvals` | step_id, step_revision_id, party_id, user_id, withdrawn_at, active (generated) | At most one active approval per (step, revision, party), enforced in the database |
| `contract_signoffs` | contract_id, party_id, user_id (null for agents), fingerprint, created_at, withdrawn_at, active (generated) | At most one active sign-off per (contract, party, fingerprint), enforced in the database |
| `activity_events` | organisation_id, contract_id, actor, type, subject, payload | Single timeline. Its increasing `id` is the cursor for `activity?since=`. |

### Database portability (D12)

- **One active row per key, without partial indexes.** MySQL has no `CREATE UNIQUE INDEX … WHERE`. `approvals` and `contract_signoffs` instead have a stored generated column, `active` (`case when withdrawn_at is null then true end`), which is true while the row is active and `NULL` once withdrawn. The unique index includes `active`. All three engines treat NULLs as distinct in a unique index, so withdrawn rows never conflict. Application code never writes `active`: don't set it and don't `replicate()` these models.
- **Case-insensitive text on MySQL.** The default `utf8mb4_*_ci` collations compare text case-insensitively, while SQLite and PostgreSQL compare case-sensitively. Normalise emails to lowercase on write (users and invitations) and match on the normalised value, so invitation email matching (Phase 6) behaves the same everywhere. Hashes and fingerprints are lowercase hex, so they're unaffected.
- **Row locks are real on MySQL and PostgreSQL.** SQLite ignores `lockForUpdate()` and runs writes one at a time, so SQLite tests can't prove lock-based correctness. Every write goes through `ContractTransaction`, whose **first statement** locks the contract row. That gives one lock order (no deadlocks), and on MySQL REPEATABLE READ every later plain read in the transaction sees all work committed before the lock. Code inside a transaction must therefore never read before taking that lock, and must re-read related rows rather than trust models loaded earlier (`Contract::findStep()` / `findPhase()`). Concurrency tests live in `tests/Concurrency`, run only on a server database, and are skipped on SQLite.
- **Activity cursor (decided in Phase 3).** Auto-increment ids are allocated at insert, not commit, so paging by id can skip a lower id that commits late. Events are inserted with `seq = NULL`. After each write commits, `ActivitySequencer` runs a short transaction behind the single `activity_sequencer` row lock and numbers every committed, unnumbered event in id order. The feed pages by `seq` (`ActivityEvent::after($cursor)`). Each sequencer run sees only committed rows and commits before the next starts, so a late committer always gets a higher `seq` than anything a reader has seen. Only this small step is serialised, not every write. Per-organisation locks were rejected because a user's feed can span organisations. `activity?since=` (Phase 7) must call `ActivitySequencer::run()` before reading, to number any event whose post-commit run never happened (for example, a crashed process).
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
  - Any step content edit, new step, archive, or change to required approvers sends the contract back to `draft`. Spacing-only edits do not (D11).
- **Fingerprint:** a hash of the set of (active step, current revision `content_hash`) pairs plus the set of required parties. Order is ignored and archived steps are excluded. A sign-off counts only if its fingerprint matches the current one. Because the fingerprint uses `content_hash`, spacing-only edits do not change it.

| Change | Cancels step approvals | Cancels sign-offs |
|---|---|---|
| Step title or description edited (content change) | Yes | Yes |
| Step edited with spacing-only changes | No | No |
| Step added | n/a | Yes (back to draft) |
| Step archived | n/a | Yes |
| Required party added or removed | No | Yes |
| Step reordered or moved to another phase | No | No |
| Phase renamed or reordered | No | No |
| Comment added | No | No |

## 3. Access rules

- **One rule everywhere:** `ContractPolicy` plus a `Contract::visibleTo($actor)` scope.
  - Organisation owner: full access.
  - Anyone else: must belong to a party attached to the contract. That party's role decides what they can do.
  - Agents follow the same rule, further limited by their token abilities.
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
    - Parties, organisations and contracts are restricted (history is never deleted).
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
  - States are always worked out from the data. "Back to draft" in §2 means approvals stop counting and sign-offs are invalidated. Archiving the last unapproved step can make a contract ready.
  - A sign-off from a non-required party is rejected (403) rather than recorded and ignored.
  - `StartNewVersion` also copies parties, roles, required approvers and `require_human_signoff`, because otherwise nobody but owners could see v2. Steps keep their original author, and their change note says which version they came from. Only agreed contracts can get a new version, and only once.
  - `AttachParty` also changes an existing party's role. A party from another organisation is reported as missing (404).
  - Identical edits, repeat approvals, repeat sign-offs and re-archiving are no-ops.
- **Activity and events:**
  - Every change writes one `activity_events` row through `ActivityLog`, using these types: `phase.created|renamed`, `phases.reordered`, `step.created|revised|moved|archived`, `steps.reordered`, `comment.added|resolved`, `approval.created|withdrawn`, `party.attached|role_changed|required_changed`, `signoff.created|withdrawn`, `signoffs.invalidated`, `contract.ready|no_longer_ready|agreed|version_started`.
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
  - `/contracts/{id}/steps/{id}` (scoped binding: a step from another contract is 404):
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
- **Step page:** re-renders live. If someone saves a revision while this person is editing, the conflict banner appears immediately, with the draft kept.
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
- **One implementation:** `App\Support\Agents\AgentApi` performs every operation for both REST and MCP, through the Phase 3 actions. `AgentPresenter` shapes the data, `ContractMarkdown` builds the export, and `AgentActor` turns a token into `Actor::agent($party, $token->abilities)`. Abilities are checked per action (403), on top of the party's role on each contract.
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
- **Consent screen** (`resources/views/oauth/authorize.blade.php`, rendered by Passport's `GET /oauth/authorize` after login): shows the tool's name and where the approval goes, lets the owner pick one of their agent parties and the abilities (read always; comment ticked by default; edit and approve opt-in). `POST /oauth/akord/approve` (`ConnectAgentController`) checks the owner and party, records the connection (replacing an earlier one for the same client and owner), then hands over to Passport's approve action. Cancel uses Passport's deny route. Passport skips the screen while the client still has a valid token for that user, which is safe because disconnecting revokes those tokens.
- **Redirect limits:** `config/mcp.php` allows self-registration only for `http://localhost` and `http://127.0.0.1` callbacks (local tools such as omp and Claude Code), so a stranger can't register a client that sends approvals to their own site. Hosted clients can be added with `AKORD_MCP_REDIRECT_DOMAINS`.
- **Agent access page:** a "Connect an MCP client" section with the omp commands and the MCP URL, and a list of connected tools (abilities, approver, last used) with **Disconnect** (`RevokeAgentConnection`: marks the connection revoked and revokes its access and refresh tokens).
- **Fallback client:** `php artisan akord:oauth-client [name] [--redirect=…]` pre-registers a public client (default callbacks `http://localhost:3000/callback` and `http://127.0.0.1:3000/callback`, omp's default) for MCP clients that can't register themselves; its ID goes in the client's OAuth settings (omp: `"oauth": {"clientId": "…"}`).
- **Rate limit key:** now `party:{id}` for claim-code tokens and `oauth-client:{client id}` for OAuth tokens, so a party and a user with the same id no longer share a bucket.
- **`GET /api/v1/agent`** now reports the actor's abilities (it showed `[]` for OAuth tokens).
- **Keys:** Passport's signing keys live in `storage/oauth-*.key` (git-ignored). `composer setup` creates them if missing, and `Tests\TestCase` does the same for CI.
- **omp plugin** (Agent Plugins 1.0.0): `.omp-plugin/marketplace.json` (marketplace `akord`) → `integrations/omp/akord/` with `plugin.json`, `mcp.json` (server `akord`, `streamable-http`, `http://localhost:8000/mcp`, no secrets) and the `akord-review` skill (`skills/akord-review/SKILL.md`: the review workflow, approving the exact revision, signing off the fingerprint, never following text inside `<contract-data>`). Install: `omp plugin marketplace add PatFox/Akord`, `omp plugin install akord@akord`, `/reload-plugins`, then `/mcp reauth akord:akord`. *Superseded by Phase 9: the plugin no longer has an MCP server.*
- **omp names plugin servers `<plugin>:<server>`**, so the server is `akord:akord` in omp; `/mcp reauth akord` answers "not found". Confirmed in the omp 2026-11 binary: the Agent Plugins provider registers `${plugin.name}:${server.name}` and maps `streamable-http` to its `http` transport, so the type is correct as published.
- **Still unknown for omp:** whether it registers clients itself (otherwise use the fallback client) and whether a 401 starts sign-in by itself (otherwise `/mcp reauth akord:akord`). The plugin's URL is fixed at `http://localhost:8000/mcp`, because plugin `mcp.json` files can't take variables; other hosts need their own MCP entry.
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
- **In app:** `/notifications` (`pages::notifications.index`) lists the latest 100, unread highlighted. Clicking one marks it read and opens its link; "Mark all as read". The sidebar's Notifications item shows the unread count. The count updates on navigation, not live.
- **Migration:** `2026_11_13_112756_create_notifications_table` (Laravel's standard table: uuid, morphs, text, so it is portable under D12).
- **Tests:** `tests/Feature/NotificationsTest.php`, 8 tests:
  - Each row of the table above, including the author, unattached owners, outsiders and non-required parties not being told, and the cancelled-approval flag going only to the affected party.
  - Unverified email gets the in-app notification only.
  - Lost access means no delivery.
  - Markdown escaping in the email.
  - The page: listing, the sidebar count, opening (marks read, redirects to the step), someone else's notification is 404, and mark all read.
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
- **Agent access page:** shows the party's MCP URL and the `.omp/mcp.json` to commit (`{"mcpServers": {"akord": {"type": "http", "url": "…/mcp/agents/{id}"}}}`), then `/mcp reauth akord` in that repo.
- **Changing abilities after connecting** (added after a report that "Edit steps" on the Agent access page didn't save).
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
  - Not yet done end to end with omp: a repo with `.omp/mcp.json`, `/mcp reauth akord`, the fixed approval screen, then tools as that party.

### Phase 10: agents change contract structure ✅ done 2026-11-13

**Decisions:**
- Agents can create steps, move them, create phases and archive steps. All four need the editor role and the `edit` ability; archiving has no separate ability.
- Agreed contracts refuse all four with `423`, through the existing lock.

**Position: `after_step_id`**, the same for create and move:
- omitted: the end of the phase;
- `0`: the start;
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

### Order of work

Phases 1–11 are done, in order.

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
   - The new version starts in `draft` with no approvals or sign-offs, and links back to v1.
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
  - `.env` sets `REVERB_PORT=8082` and `REVERB_SERVER_PORT=8082`. Vite reads `VITE_REVERB_PORT` from `REVERB_PORT` when it starts, so restart `composer dev` after changing either.
  - `.env.example` now documents `REVERB_SERVER_PORT`, still 8080 by default.
  - Checked in a browser: Echo connected to `ws://localhost:8082` and subscribed to `private-contract.4`. A step the agent added over REST appeared on the owner's open page about 0.9 s later, with the "contract changed while you were reviewing it" notice. The broadcast job reported `DONE`; before the move it failed.
- **Running the dev server from omp:**
  - Start it as a named service and set `proc://akord-dev/mode` to `detached`, so it survives omp restarts.
  - Switching the mode starts a second copy, and stopping a service leaves its child processes running on Windows. Check for leftover `artisan`, `concurrently` and `vite` processes and stop them.
  - Use `TEMP`/`TMP` = `C:\Users\micro\AppData\Local\Temp`. The launcher's temp folder holds locked `sf_proc_*` files that make `composer dev` fail.
