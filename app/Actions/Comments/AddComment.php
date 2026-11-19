<?php

namespace App\Actions\Comments;

use App\Enums\CommentKind;
use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Step;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Comments on a step's current revision, optionally replying to a comment on the same step.
 *
 * Threads are two levels deep (a comment and its replies), as the contract page and get_step show
 * them. A reply to a reply joins the thread of its top-level comment; the comment it answered
 * is kept in the activity payload as `in_reply_to`.
 */
final class AddComment
{
    public function handle(Actor $actor, Step $step, CommentKind $kind, string $bodyMd, ?Comment $parent = null): Comment
    {
        return ContractTransaction::run($step->phase->contract, $actor, ContractAbility::Comment, function (Contract $contract, ActivityLog $log) use ($actor, $step, $kind, $bodyMd, $parent): Comment {
            $step = $contract->findStep($step->id);

            if ($step->isArchived()) {
                throw new InvalidActionException('Archived steps cannot be commented on.');
            }

            $answered = null;

            if ($parent !== null) {
                $answered = Comment::query()->whereKey($parent->id)->where('step_id', $step->id)->firstOrFail();
                $parent = $answered;

                while ($parent->parent_id !== null) {
                    $parent = Comment::query()->whereKey($parent->parent_id)->where('step_id', $step->id)->firstOrFail();
                }
            }

            $comment = Comment::query()->create([
                'step_id' => $step->id,
                'step_revision_id' => $step->current_revision_id,
                'parent_id' => $parent?->id,
                'author_party_id' => ContractAccess::actingParty($actor)->getKey(),
                'author_user_id' => $actor->user?->getKey(),
                'kind' => $kind,
                'body_md' => $bodyMd,
            ]);

            $payload = ['step_id' => $step->id, 'kind' => $kind->value, 'parent_id' => $parent?->id];

            if ($answered !== null && $parent !== $answered) {
                $payload['in_reply_to'] = $answered->id;
            }

            $log->record('comment.added', $comment, $payload);

            return $comment;
        });
    }
}
