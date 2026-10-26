<?php

namespace App\Mail;

use App\Models\Feedback;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the support address for every in-app feedback message.
 */
class FeedbackReceived extends Mailable
{
    public function __construct(public readonly Feedback $feedback) {}

    public function envelope(): Envelope
    {
        $contact = $this->feedback->contact;

        return new Envelope(
            subject: sprintf('[DramaLoop feedback] %s', $this->feedback->categoryLabel()),
            // Lets support answer straight from the inbox when the user left an email.
            replyTo: $contact !== null && filter_var($contact, FILTER_VALIDATE_EMAIL)
                ? [new Address($contact)]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.feedback');
    }
}
