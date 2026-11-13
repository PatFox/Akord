<?php

namespace App\Notifications;

use App\Models\ActivityEvent;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Step;
use App\Models\User;
use App\Support\ActivityDescription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One activity row, told to one person in the app and, if their email is verified,
 * by email. Only ids and a sentence describing the change are sent; participants'
 * text (descriptions, comment bodies) stays behind the link and its access checks.
 */
class ContractActivity extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ActivityEvent $activity,
        public bool $approvalCancelled = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->hasVerifiedEmail() ? ['database', 'mail'] : ['database'];
    }

    /**
     * Re-checked at delivery: someone removed from the contract since the change hears nothing.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return Contract::query()->visibleTo($notifiable)->whereKey($this->activity->contract_id)->exists();
    }

    /**
     * @return array{activity_id: int, contract_id: int|null, contract: string, type: string, message: string, detail: string|null, url: string}
     */
    public function toArray(User $notifiable): array
    {
        [$message, $detail] = $this->sentences();

        return [
            'activity_id' => $this->activity->id,
            'contract_id' => $this->activity->contract_id,
            'contract' => $this->contractLabel(),
            'type' => $this->activity->type,
            'message' => $message,
            'detail' => $detail,
            'url' => $this->url(),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        [$message, $detail] = $this->sentences();

        // Mail lines are parsed as Markdown: escape participants' titles and names so they
        // can't add links or formatting to an email that comes from Akord.
        $mail = (new MailMessage)
            ->subject($this->contractLabel().': '.$message)
            ->greeting($this->escape($this->contractLabel()))
            ->line($this->escape($message));

        if ($detail !== null) {
            $mail->line($this->escape($detail));
        }

        return $mail->action(__('Open in Akord'), $this->url());
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function sentences(): array
    {
        $title = '“'.$this->activity->contract->title.'”';

        return match ($this->activity->type) {
            'step.revised' => [
                ActivityDescription::for($this->activity),
                $this->approvalCancelled ? $this->text('Your party’s approval of this step was cancelled. Review the new revision and approve it again.') : null,
            ],
            'contract.ready' => [
                $this->text(':contract is ready for sign-off', ['contract' => $title]),
                $this->text('Every step is approved. Each required party now signs off the whole contract.'),
            ],
            'signoffs.invalidated' => [
                $this->text('A change cancelled your sign-off of :contract', ['contract' => $title]),
                $this->text('Review the contract and sign off again.'),
            ],
            'contract.agreed' => [
                $this->text(':contract was agreed', ['contract' => $title]),
                $this->text('Every required party signed off. The contract is now locked.'),
            ],
            'invitation.accepted' => [
                ActivityDescription::for($this->activity),
                $this->text('They accepted your invitation.'),
            ],
            default => [ActivityDescription::for($this->activity), null],
        };
    }

    /**
     * __() is typed string|array (a key may name a translation group); these keys are sentences.
     *
     * @param  array<string, string>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $translated = __($key, $replace);

        return is_string($translated) ? $translated : $key;
    }

    private function contractLabel(): string
    {
        $contract = $this->activity->contract;

        return $contract->title.' v'.$contract->version;
    }

    private function url(): string
    {
        $subject = $this->activity->subject;
        $step = match (true) {
            $subject instanceof Step => $subject,
            $subject instanceof Comment => $subject->step,
            default => null,
        };

        return $step === null
            ? route('contracts.show', $this->activity->contract)
            : route('steps.show', [$this->activity->contract, $step]);
    }

    private function escape(string $text): string
    {
        return (string) preg_replace('/[\\\\`*_{}\[\]()#+\-.!<>|~]/', '\\\\$0', $text);
    }
}
