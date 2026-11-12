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
- **Running the suite on MySQL:** start a throwaway server (`docker run -d --rm --name akord-mysql-test -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=akord_test -p 127.0.0.1:33061:3306 --tmpfs /var/lib/mysql mysql:8.4`). Then run `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33061 DB_DATABASE=akord_test DB_USERNAME=root DB_PASSWORD=secret php artisan test`. Pass the variables per command rather than `export`ing them, or later runs silently use MySQL too. The kit's CI (`.github/workflows/tests.yml`) currently runs SQLite only, so the concurrency test doesn't run in CI.

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

### Phase 5: live updates

- Private channel `contract.{id}`, authorised by `ContractPolicy`.
- Event: `App\Events\ActivityRecorded` (one per activity row, dispatched after commit). Phase 5 makes it `ShouldBroadcast` on `contract.{id}`, and clients branch on `activity.type`. The readiness transitions are activity types (`contract.ready`, `contract.no_longer_ready`, `signoffs.invalidated`, `contract.agreed`), so they need no separate classes.
- Livewire components listen for it. Broadcasting happens only after commit.

### Phase 6: human invitations

- An owner invites an email address to a contract and party. The email carries a signed link; only the token hash is stored. Tokens are single-use and expire.
- To accept, the person signs up or logs in, and the account email must match the invite. They are then added to the party.
- Mail class: `app/Mail/InvitationMail.php`.

### Phase 7: agents

- **Onboarding:**
  - An owner creates an agent party and a one-time claim code, shown once.
  - The agent calls `POST /api/v1/agent/claim` and receives a Sanctum token.
  - The owner can see last-used time and revoke the token.
- **REST API** under `/api/v1/...`:
  - Contracts: list, detail, and export (`?format=md|json`).
  - Steps, revisions and diffs; comments; approve and withdraw.
  - `activity?since=<cursor>`.
  - `GET /api/v1/contracts/{id}/status`: state, fingerprint, what is outstanding, and sign-offs.
  - `POST /api/v1/contracts/{id}/signoffs` with body `{fingerprint}`: returns `409`, `422`, `423`, or `403` (D10).
  - `DELETE /api/v1/contracts/{id}/signoffs/mine`.
  - Errors: `409` for conflicts, `423` for locked contracts.
- **MCP** (`routes/ai.php`, `app/Mcp/Servers/ContractServer.php`, `app/Mcp/Tools/*`), using the same token:
  - Read: `list_contracts`, `get_contract`, `get_step`, `get_step_history`, `get_activity_since`, `get_contract_status`.
  - Write: `revise_step`, `add_comment`, `approve_step`, `withdraw_approval`, `sign_off_contract`, `withdraw_signoff`.
- Per-agent rate limits.
- Replies mark contract content clearly as data, as a defence against prompt injection.

### Phase 8: notifications (queued, email and in-app)

- New comment.
- Step edited, including a note that your approval was cancelled.
- Contract ready for sign-off.
- Sign-offs invalidated by a change.
- Contract agreed.
- Invite accepted.
- Activity types: `signoff.created`, `signoff.withdrawn`, `signoffs.invalidated`, `contract.ready`, `contract.agreed`.

### Order of work

Phase 1 setup first. Then Phases 2–3 (data model and actions, including `ContractReadiness` and `SignOffContract`), with tests 1–8 and 13 written alongside.

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

## 8. Environment and current state

- PHP 8.3.23 (NTS x64), with pdo_sqlite, sqlite3, intl, mbstring, sodium, sockets, zip, bcmath, gd, openssl and curl. Composer 2.8.4, Node v24.20.0, npm 11.19.0, git 2.44.0.
- The global `laravel` installer is broken in bash. Use `composer create-project` instead, or call the installer through `php`.
- Run the app with `composer dev` (`php artisan dev`). It starts serve, `queue:listen`, Vite, and `reverb:start`; Reverb registers itself. Pail is skipped on Windows because it needs `pcntl`.
- Repository: https://github.com/PatFox/Akord (public, MIT). Branch: `main`.
- Fresh clone: run `composer setup`, then fill in `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env`; any random strings work locally. They are blank in `.env.example` so no keys are published.
- Next: Phase 5 (live updates), which broadcasts `ActivityRecorded` and disables Sign off when the fingerprint changes.
