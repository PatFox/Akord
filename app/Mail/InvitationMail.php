<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent synchronously (never queued) so the raw token is never stored in the jobs table.
 */
class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(public Invitation $invitation, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You are invited to “:contract” on :app', ['contract' => $this->invitation->contract->title, 'app' => config('app.name')]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'url' => route('invitations.show', ['token' => $this->token]),
                'inviter' => $this->invitation->inviter->name ?? __('An owner'),
                'organisation' => $this->invitation->organisation->name,
                'contract' => $this->invitation->contract->title,
                'party' => $this->invitation->party->name,
                'expires' => $this->invitation->expires_at->toFormattedDayDateString(),
            ],
        );
    }
}
