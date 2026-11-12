<?php

namespace App\Support\Agents;

use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\ActivityDescription;
use App\Support\ContractAccess;
use App\Support\ReadinessReport;

/**
 * The shapes the REST API and MCP server return. Text written by participants is
 * always nested under a "content" key (see UntrustedContent).
 */
final class AgentPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function contractSummary(Contract $contract, ReadinessReport $report): array
    {
        return [
            'id' => $contract->id,
            'version' => $contract->version,
            'state' => $report->state->value,
            'previous_version_id' => $contract->previous_version_id,
            'project' => ['id' => $contract->project->id, 'name' => $contract->project->name],
            'organisation' => ['id' => $contract->project->organisation->id, 'name' => $contract->project->organisation->name],
            'content' => ['title' => $contract->title],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contract(Contract $contract, ReadinessReport $report, Party $viewer): array
    {
        $outstanding = collect($report->outstanding)->map(fn (array $o) => $o['step_id'].':'.$o['party_id'])->flip();
        $membership = ContractAccess::membership($viewer, $contract);
        $required = Party::query()->whereIn('id', $report->requiredPartyIds)->orderBy('name')->get();

        return [
            ...self::contractSummary($contract, $report),
            'require_human_signoff' => $contract->require_human_signoff,
            'agreed_at' => $contract->agreed_at?->toIso8601String(),
            'fingerprint' => $report->fingerprint,
            'your_party' => [
                'id' => $viewer->id,
                'name' => $viewer->name,
                'role' => $membership?->role->value,
                'required_approver' => (bool) $membership?->is_required_approver,
            ],
            'required_parties' => $required->map(fn (Party $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'signed_off' => in_array($p->id, $report->signedOffPartyIds, true),
            ])->all(),
            'phases' => $contract->phases()->with(['steps' => fn ($steps) => $steps->active()->with('currentRevision')])->get()
                ->map(fn (Phase $phase) => [
                    'id' => $phase->id,
                    'position' => $phase->position,
                    'content' => ['title' => $phase->title],
                    'steps' => $phase->steps->map(fn (Step $step) => [
                        'id' => $step->id,
                        'position' => $step->position,
                        'revision' => $step->currentRevision?->number,
                        'approved_by' => $required->filter(fn (Party $p) => ! $outstanding->has($step->id.':'.$p->id))->pluck('id')->values()->all(),
                        'content' => ['title' => $step->currentRevision?->title],
                    ])->all(),
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(Contract $contract, ReadinessReport $report): array
    {
        return [
            'contract_id' => $contract->id,
            'state' => $report->state->value,
            'fingerprint' => $report->fingerprint,
            'active_steps' => $report->activeStepCount,
            'required_party_ids' => $report->requiredPartyIds,
            'outstanding_approvals' => $report->outstanding,
            'signed_off_party_ids' => $report->signedOffPartyIds,
            'require_human_signoff' => $contract->require_human_signoff,
            'agreed_at' => $contract->agreed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function step(Contract $contract, Step $step, ReadinessReport $report, Party $viewer): array
    {
        $current = $step->currentRevision()->with(['authorParty', 'authorUser'])->firstOrFail();
        $outstanding = collect($report->outstanding)->where('step_id', $step->id)->pluck('party_id')->all();

        $active = Approval::query()->active()->with('revision')->where('step_id', $step->id)->where('party_id', $viewer->id)->get();
        $yourApproval = match (true) {
            $active->contains(fn (Approval $a) => $a->revision->content_hash === $current->content_hash) => 'counts',
            $active->isNotEmpty() => 'cancelled',
            default => 'none',
        };

        return [
            'id' => $step->id,
            'contract_id' => $contract->id,
            'phase_id' => $step->phase_id,
            'archived' => $step->isArchived(),
            'revision' => self::revision($current),
            'approvals' => Party::query()->whereIn('id', $report->requiredPartyIds)->orderBy('name')->get()
                ->map(fn (Party $p) => ['party_id' => $p->id, 'party' => $p->name, 'approved' => ! in_array($p->id, $outstanding, true)])
                ->all(),
            'your_approval' => $yourApproval,
            'comments' => $step->comments()->whereNull('parent_id')
                ->with(['authorParty', 'authorUser', 'revision', 'replies' => fn ($r) => $r->with(['authorParty', 'authorUser', 'revision'])->oldest()])
                ->oldest()->get()
                ->map(fn (Comment $c) => self::comment($c))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function revision(StepRevision $revision, ?StepRevision $previous = null): array
    {
        return [
            'number' => $revision->number,
            'created_at' => $revision->created_at?->toIso8601String(),
            'author' => ['party' => $revision->authorParty->name, 'user' => $revision->authorUser?->name],
            ...($previous === null ? [] : ['content_changed' => $previous->content_hash !== $revision->content_hash]),
            'content' => [
                'title' => $revision->title,
                'description_md' => $revision->description_md,
                'change_note' => $revision->change_note,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function comment(Comment $comment): array
    {
        return [
            'id' => $comment->id,
            'kind' => $comment->kind->value,
            'revision' => $comment->revision->number,
            'resolved' => $comment->resolved_at !== null,
            'created_at' => $comment->created_at?->toIso8601String(),
            'author' => ['party' => $comment->authorParty->name, 'user' => $comment->authorUser?->name],
            'content' => ['body_md' => $comment->body_md],
            'replies' => $comment->relationLoaded('replies') ? $comment->replies->map(fn (Comment $r) => self::comment($r))->all() : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function activity(ActivityEvent $event): array
    {
        return [
            'seq' => $event->seq,
            'id' => $event->id,
            'type' => $event->type,
            'contract_id' => $event->contract_id,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'created_at' => $event->created_at->toIso8601String(),
            'content' => ['description' => ActivityDescription::for($event)],
        ];
    }
}
