<?php

namespace App\Support;

use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\ContractSignoff;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Step;

/**
 * One-line, human description of an activity row ("Alice Archer edited “Build the API”").
 */
final class ActivityDescription
{
    public static function for(ActivityEvent $event): string
    {
        $actor = $event->actorUser->name ?? $event->actorParty->name ?? __('Someone');
        $subject = self::subjectLabel($event);
        $payload = $event->payload ?? [];

        return match ($event->type) {
            'phase.created' => __(':actor added phase :subject', compact('actor', 'subject')),
            'phase.renamed' => __(':actor renamed a phase to :subject', compact('actor', 'subject')),
            'phases.reordered' => __(':actor reordered the phases', compact('actor')),
            'step.created' => __(':actor added step :subject', compact('actor', 'subject')),
            'step.revised' => ($payload['content_changed'] ?? true)
                ? __(':actor edited :subject', compact('actor', 'subject'))
                : __(':actor changed spacing in :subject', compact('actor', 'subject')),
            'step.moved' => __(':actor moved :subject', compact('actor', 'subject')),
            'steps.reordered' => __(':actor reordered steps in :subject', compact('actor', 'subject')),
            'step.archived' => __(':actor archived :subject', compact('actor', 'subject')),
            'comment.added' => __(':actor commented on :subject', compact('actor', 'subject')),
            'comment.resolved' => __(':actor resolved a comment on :subject', compact('actor', 'subject')),
            'approval.created' => __(':actor approved :subject', compact('actor', 'subject')),
            'approval.withdrawn' => __(':actor withdrew approval of :subject', compact('actor', 'subject')),
            'party.attached' => __(':actor attached :subject', compact('actor', 'subject')),
            'party.detached' => __(':actor detached :subject', compact('actor', 'subject')),
            'party.role_changed' => __(':actor changed the role of :subject', compact('actor', 'subject')),
            'party.required_changed' => ($payload['required'] ?? false)
                ? __(':actor made :subject a required approver', compact('actor', 'subject'))
                : __(':actor removed :subject as a required approver', compact('actor', 'subject')),
            'signoff.created' => __(':actor signed off', compact('actor')),
            'signoff.withdrawn' => __(':actor withdrew their sign-off', compact('actor')),
            'signoffs.invalidated' => __('Sign-offs were cancelled by a change'),
            'contract.ready' => __('The contract is ready for sign-off'),
            'contract.no_longer_ready' => __('The contract is no longer ready for sign-off'),
            'contract.agreed' => __('The contract was agreed'),
            'contract.created' => __(':actor created the contract', compact('actor')),
            'contract.version_started' => __(':actor started a new version', compact('actor')),
            'contract.status_changed' => match ($payload['to'] ?? null) {
                'open' => __(':actor reopened the contract', compact('actor')),
                'complete' => __(':actor marked the contract complete', compact('actor')),
                'cancelled' => __(':actor marked the contract cancelled', compact('actor')),
                default => __(':actor changed the contract status', compact('actor')),
            },
            'invitation.sent' => __(':actor invited someone to :party', ['actor' => $actor, 'party' => self::partyName($payload)]),
            'invitation.resent' => __(':actor resent an invitation to :party', ['actor' => $actor, 'party' => self::partyName($payload)]),
            'invitation.cancelled' => __(':actor cancelled an invitation to :party', ['actor' => $actor, 'party' => self::partyName($payload)]),
            'invitation.accepted' => __(':actor joined :subject', compact('actor', 'subject')),
            'contract.human_signoff_changed' => __(':actor changed the human sign-off rule', compact('actor')),
            'checklist.created' => ($payload['replaced'] ?? false)
                ? __(':actor replaced the checklist :name', ['actor' => $actor, 'name' => self::checklistName($event)])
                : __(':actor created the checklist :name', ['actor' => $actor, 'name' => self::checklistName($event)]),
            'checklist.item_checked' => __(':actor ticked :subject in :name', ['actor' => $actor, 'subject' => $subject, 'name' => self::checklistName($event)]),
            'checklist.item_unchecked' => __(':actor unticked :subject in :name', ['actor' => $actor, 'subject' => $subject, 'name' => self::checklistName($event)]),
            'checklist.item_added' => __(':actor added :subject to :name', ['actor' => $actor, 'subject' => $subject, 'name' => self::checklistName($event)]),
            default => __(':actor made a change', compact('actor')),
        };
    }

    /**
     * “API team”, from the payload (creation) or the item's checklist; "the checklist" when neither is known any more.
     */
    private static function checklistName(ActivityEvent $event): string
    {
        $subject = $event->subject;
        $name = match (true) {
            $subject instanceof Checklist => $subject->name,
            $subject instanceof ChecklistItem => $subject->stage?->checklist?->name,
            default => $event->payload['name'] ?? null,
        };

        return is_string($name) ? '“'.$name.'”' : __('the checklist');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function partyName(array $payload): string
    {
        $partyId = $payload['party_id'] ?? null;
        $name = is_string($partyId) ? Party::query()->whereKey($partyId)->value('name') : null;

        return is_string($name) ? '“'.$name.'”' : __('a party');
    }

    private static function subjectLabel(ActivityEvent $event): string
    {
        $subject = $event->subject;

        $label = match (true) {
            $subject instanceof Step => $subject->currentRevision?->title,
            $subject instanceof Comment, $subject instanceof Approval => $subject->step->currentRevision?->title,
            $subject instanceof Phase => $subject->title,
            $subject instanceof Party => $subject->name,
            $subject instanceof Contract => $subject->title,
            $subject instanceof ContractSignoff => $subject->party->name,
            $subject instanceof ChecklistItem => $subject->title,
            default => null,
        };

        return $label === null ? __('an item') : '“'.$label.'”';
    }
}
