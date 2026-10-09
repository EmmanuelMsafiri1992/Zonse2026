<?php

namespace App\Inbox\Drivers;

use App\Inbox\ChannelDriver;
use App\Inbox\InboxException;
use App\Models\InboxChannel;
use App\Models\InboxConversation;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * A support address. Incoming mail arrives from an inbound-parse service (Mailgun, Postmark,
 * SendGrid or any service posting from / subject / text); replies go out through the app's mailer.
 */
class EmailDriver implements ChannelDriver
{
    public function key(): string
    {
        return 'email';
    }

    public function label(): string
    {
        return 'Email';
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function description(): string
    {
        return 'A support address such as help@yourbusiness.com. Replies are sent from that address.';
    }

    public function addressLabel(): string
    {
        return 'Email address';
    }

    public function setupHelp(): string
    {
        return "Forward the address to your email provider's inbound parse (Mailgun Routes, Postmark Inbound or SendGrid Inbound Parse) and have it post to this URL.";
    }

    public function fields(): array
    {
        return [];
    }

    public function authorize(Request $request, InboxChannel $channel): bool
    {
        return true;
    }

    public function parse(Request $request, InboxChannel $channel): array
    {
        $from = (string) ($request->input('FromFull.Email') ?: $request->input('sender') ?: $request->input('from') ?: $request->input('From'));
        $name = $request->input('FromName') ?: $request->input('FromFull.Name');
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>/', $from, $match)) {
            $name = $name ?: trim($match[1]);
            $from = $match[2];
        }
        $email = Str::lower(trim($from));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [];
        }

        $body = (string) ($request->input('stripped-text') ?: $request->input('StrippedTextReply') ?: $request->input('TextBody')
            ?: $request->input('text') ?: $request->input('body-plain') ?: '');
        if (trim($body) === '') {
            $html = (string) ($request->input('HtmlBody') ?: $request->input('html') ?: $request->input('body-html') ?: '');
            $body = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $html) ?? ''), ENT_QUOTES | ENT_HTML5));
        }

        return [[
            'handle' => $email,
            'name' => $name ? Str::limit((string) $name, 180, '') : null,
            'subject' => Str::limit((string) ($request->input('subject') ?: $request->input('Subject') ?: ''), 180, '') ?: null,
            'body' => $body,
            'external_id' => ($request->input('Message-Id') ?: $request->input('MessageID') ?: $request->input('message_id')) ?: null,
        ]];
    }

    public function send(InboxChannel $channel, InboxConversation $conversation, string $body): string
    {
        $workspaceName = $channel->workspace?->name;
        $subject = match (true) {
            ! $conversation->subject => 'Message from '.$workspaceName,
            Str::startsWith(Str::lower($conversation->subject), 're:') => $conversation->subject,
            default => 'Re: '.$conversation->subject,
        };

        try {
            Mail::raw($body, function (Message $message) use ($channel, $conversation, $subject, $workspaceName) {
                $message->to($conversation->handle, $conversation->name)->subject($subject);
                if ($channel->address) {
                    $message->from($channel->address, $workspaceName)->replyTo($channel->address, $workspaceName);
                }
            });
        } catch (Throwable $e) {
            throw new InboxException('The email could not be sent: '.$e->getMessage(), false, $e);
        }

        return 'mail-'.Str::uuid();
    }
}
