<?php

namespace App\Assistant\Providers;

use App\Assistant\AssistantProvider;
use App\Assistant\AssistantReply;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Answers without an AI model, for trying the assistant for free and for tests. It picks a
 * lookup from keywords in the question, makes it through the same tool loop a real model uses,
 * and writes the answer from the result. Real wording, judgement and free-form drafting need
 * one of the real providers.
 */
class TestProvider implements AssistantProvider
{
    protected const DRAFT = '/\b(draft|write|compose|email|e-mail|letter|message|reminder)\b/i';

    public function key(): string
    {
        return 'test';
    }

    public function label(): string
    {
        return 'Test mode';
    }

    public function description(): string
    {
        return 'Free. Answers simple questions about your data from keywords, without an AI model. Use it to try the assistant.';
    }

    public function fields(): array
    {
        return [];
    }

    public function reply(string $system, array $messages, array $tools, array $credentials): AssistantReply
    {
        $question = '';
        foreach (array_reverse($messages) as $message) {
            if ($message['role'] === 'user') {
                $question = (string) $message['content'];
                break;
            }
        }

        $last = end($messages);
        if ($last && $last['role'] === 'tool') {
            $results = [];
            foreach (array_reverse($messages) as $message) {
                if ($message['role'] !== 'tool') {
                    break;
                }
                $results[] = [$message['name'], json_decode($message['content'], true) ?: []];
            }

            return new AssistantReply($this->answer($question, array_reverse($results)), [], 0, 0, 'test');
        }

        $call = $this->lookupFor($question, array_column($tools, 'name'));

        return $call
            ? new AssistantReply(null, [['id' => 'test_'.Str::random(10), 'name' => $call[0], 'arguments' => $call[1]]], 0, 0, 'test')
            : new AssistantReply($this->genericDraft($question), [], 0, 0, 'test');
    }

    /**
     * @param  list<string>  $available
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    protected function lookupFor(string $question, array $available): ?array
    {
        $lower = Str::lower($question);
        $period = $this->period($lower);

        if (preg_match('/\b([A-Z]{2,6}-\d{2,})\b/', $question, $number)) {
            return ['get_record', ['number' => $number[1]]];
        }
        if (in_array('invoice_summary', $available, true) && preg_match('/\b(owe|owes|owing|overdue|unpaid|outstanding|invoices?|debtors?|sales)\b/', $lower)) {
            return ['invoice_summary', array_filter(['period' => $period])];
        }
        if (preg_match(self::DRAFT, $lower)) {
            return null;
        }
        if (preg_match('/\b(how much|how many|total|totals|spent|spend|sum|count|most)\b/', $lower)) {
            $groupBy = match (true) {
                str_contains($lower, 'categor') => 'category',
                (bool) preg_match('/\b(per|each|by) month|month by month\b/', $lower) => 'month',
                (bool) preg_match('/\b(by|per) status\b/', $lower) => 'status',
                (bool) preg_match('/\b(by|per|which) (customer|client|supplier|contact)\b/', $lower) => 'contact',
                default => null,
            };

            $app = preg_match('/\bspen(d|t|ding)\b/', $lower) && ! str_contains($lower, 'expense') ? $question.' (expenses)' : $question;

            return ['record_totals', array_filter(['app' => $app, 'period' => $period, 'group_by' => $groupBy])];
        }
        if (preg_match('/\b(contacts?|customers?|clients?|suppliers?|leads?)\b/', $lower, $type) && ! preg_match('/\b(list|show|find|recent|latest)\b.*\b(for|of)\b/', $lower)) {
            $types = ['contact' => null, 'customer' => 'customer', 'client' => 'customer', 'supplier' => 'supplier', 'lead' => 'lead'];
            preg_match('/\b(?:called|named)\s+["\']?([\p{L}\d .&\'-]{2,40})/u', $question, $name);

            return ['find_contacts', array_filter(['type' => $types[rtrim($type[1], 's')] ?? null, 'search' => isset($name[1]) ? trim($name[1], " \"'.?") : null])];
        }
        if (preg_match('/\b(list|show|find|which|recent|latest|search|open)\b/', $lower)) {
            return ['find_records', array_filter(['app' => $question, 'period' => $period, 'limit' => 10])];
        }

        return ['workspace_overview', []];
    }

    protected function period(string $lower): ?string
    {
        foreach (['last month' => 'last_month', 'this month' => 'this_month', 'last week' => 'last_week', 'this week' => 'this_week',
            'last year' => 'last_year', 'this year' => 'this_year', 'yesterday' => 'yesterday', 'today' => 'today'] as $words => $period) {
            if (str_contains($lower, $words)) {
                return $period;
            }
        }

        return null;
    }

    /** @param  list<array{0: string, 1: array<string, mixed>}>  $results */
    protected function answer(string $question, array $results): string
    {
        $parts = [];
        foreach ($results as [$name, $result]) {
            if (isset($result['error'])) {
                $parts[] = 'I could not look that up: '.$result['error'];

                continue;
            }
            $parts[] = match ($name) {
                'workspace_overview' => $this->overview($result),
                'record_totals' => $this->totals($result),
                'find_records' => $this->records($result),
                'get_record' => preg_match(self::DRAFT, $question) ? $this->recordEmail($result) : $this->recordSummary($result),
                'find_contacts' => $this->contacts($result),
                'invoice_summary' => preg_match(self::DRAFT, $question) ? $this->reminderEmail($result) : $this->invoices($result),
                default => '',
            };
        }

        return trim(implode("\n\n", array_filter($parts)));
    }

    /** @param  array<string, mixed>  $result */
    protected function overview(array $result): string
    {
        $lines = [];
        foreach ($result['apps'] as $app) {
            $lists = array_map(fn (array $list) => $list['records'].' '.Str::lower($list['name']), $app['lists']);
            $lines[] = '- **'.$app['app'].'**: '.implode(', ', $lists);
        }

        return ($lines ? '**'.$result['workspace']."** has these apps switched on:\n\n".implode("\n", $lines) : 'No apps are switched on yet.')
            ."\n\nYou also have ".$result['contacts'].' '.Str::plural('contact', $result['contacts']).'. '
            .'Try asking "How much did we spend this month by category?", "Show recent '.Str::lower($result['apps'][0]['lists'][0]['name'] ?? 'records').'" or "Who owes us money?".';
    }

    /** @param  array<string, mixed>  $result */
    protected function totals(array $result): string
    {
        $text = '**'.$result['list'].'** ('.$result['range'].'): '.$result['count'].' '.Str::plural('record', $result['count'])
            .($result['totals'] ? ', totalling **'.$this->money($result['totals']).'**.' : '.');
        foreach ($result['groups'] ?? [] as $group) {
            $text .= "\n- ".$group['group'].': '.$group['count'].($group['totals'] ? ' · '.$this->money($group['totals']) : '');
        }

        return $text.(isset($result['note']) ? "\n\n".$result['note'] : '');
    }

    /** @param  array<string, mixed>  $result */
    protected function records(array $result): string
    {
        if (! $result['records']) {
            return 'Nothing found in **'.$result['list'].'**.';
        }
        $text = $result['matching'].' found in **'.$result['list'].'**'.($result['matching'] > $result['showing'] ? ', the latest '.$result['showing'].':' : ':');
        foreach ($result['records'] as $record) {
            $text .= "\n- [".$record['number'].' · '.$record['title'].']('.$record['url'].') — '.$record['status']
                .(isset($record['amount']) ? ' · '.Money::format($record['amount'], $record['currency'] ?? null) : '');
        }

        return $text;
    }

    /** @param  array<string, mixed>  $result */
    protected function recordSummary(array $result): string
    {
        $text = '**['.$result['number'].' · '.$result['title'].']('.$result['url'].')** is a '.Str::lower($result['list']).' in '.$result['app']
            .', currently **'.$result['status'].'**'.(isset($result['amount']) ? ', for '.Money::format($result['amount'], $result['currency'] ?? null) : '').'.';
        foreach ($result['fields'] ?? [] as $label => $value) {
            $text .= "\n- ".$label.': '.$value;
        }
        $text .= "\n\nCreated ".$result['created'].', last updated '.$result['updated'].'.';
        if ($result['comments'] ?? []) {
            $latest = $result['comments'][0];
            $text .= ' Latest comment from '.$latest['by'].' ('.$latest['on'].'): "'.Str::limit($latest['text'], 160).'"';
        }

        return $text;
    }

    /** @param  array<string, mixed>  $result */
    protected function recordEmail(array $result): string
    {
        $name = $result['contact_details']['name'] ?? null;

        return 'Here is a draft you can copy and adjust:'
            ."\n\n**Subject:** ".$result['title'].' ('.$result['number'].')'
            ."\n\nDear ".($name ?? 'Sir or Madam').','
            ."\n\nI am writing about ".$result['title'].' (reference '.$result['number'].'), which is currently '.Str::lower($result['status'])
            .(isset($result['amount']) ? ', for '.Money::format($result['amount'], $result['currency'] ?? null) : '').'.'
            ."\n\nPlease let me know if you have any questions, or if there is anything you need from us."
            ."\n\nKind regards";
    }

    /** @param  array<string, mixed>  $result */
    protected function contacts(array $result): string
    {
        if (! $result['contacts']) {
            return 'No matching contacts.';
        }
        $text = $result['matching'].' '.Str::plural('contact', $result['matching']).':';
        foreach ($result['contacts'] as $contact) {
            $text .= "\n- [".$contact['name'].']('.$contact['url'].') — '.$contact['type']
                .(isset($contact['phone']) ? ' · '.$contact['phone'] : '').(isset($contact['owes']) ? ' · owes '.Money::format($contact['owes']) : '');
        }

        return $text;
    }

    /** @param  array<string, mixed>  $result */
    protected function invoices(array $result): string
    {
        if (! $result['open_invoices']) {
            return 'Nobody owes you anything right now: there are no unpaid invoices.'
                .($result['invoices_issued'] ? ' Invoiced ('.$result['range'].'): **'.$this->money($result['invoiced']).'**.' : '');
        }
        $text = 'Customers owe **'.$this->money($result['still_owed_all_time']).'** in total'
            .($result['overdue'] ? ', of which **'.$this->money($result['overdue']).'** is overdue' : '').'.';
        foreach ($result['open_invoices'] as $invoice) {
            $text .= "\n- [".$invoice['number'].']('.$invoice['url'].') · '.($invoice['customer'] ?? 'No customer').' · '.Money::format($invoice['balance'], $invoice['currency'])
                .($invoice['days_overdue'] ? ' · '.$invoice['days_overdue'].' '.Str::plural('day', $invoice['days_overdue']).' overdue' : ($invoice['due'] ? ' · due '.$invoice['due'] : ''));
        }

        return $text;
    }

    /** @param  array<string, mixed>  $result */
    protected function reminderEmail(array $result): string
    {
        $overdue = array_values(array_filter($result['open_invoices'], fn (array $invoice) => $invoice['days_overdue'] > 0)) ?: $result['open_invoices'];
        if (! $overdue) {
            return 'There are no unpaid invoices, so there is nobody to remind.';
        }
        $invoice = $overdue[0];

        return 'Here is a reminder for '.($invoice['customer'] ?? 'the customer').', who has the oldest unpaid invoice. Copy it and adjust as needed:'
            ."\n\n**Subject:** Payment reminder: invoice ".$invoice['number']
            ."\n\nDear ".($invoice['customer'] ?? 'customer').','
            ."\n\nThis is a friendly reminder that invoice ".$invoice['number'].' for '.Money::format($invoice['balance'], $invoice['currency'])
            .($invoice['due'] ? ' was due on '.$invoice['due'] : ' is still unpaid').'. If you have already paid, thank you and please ignore this message. '
            .'Otherwise, we would be grateful if you could settle it at your earliest convenience.'
            ."\n\nKind regards";
    }

    protected function genericDraft(string $question): string
    {
        return 'In test mode I can only draft from a record or from your unpaid invoices. Mention a record number (for example "Draft an email about EXP-0004") '
            .'or ask "Draft a reminder for overdue invoices". Choose Anthropic or OpenAI in assistant settings for free-form writing.';
    }

    /** @param  array<string, float>  $totals */
    protected function money(array $totals): string
    {
        return implode(' + ', array_map(fn ($amount, $currency) => Money::format($amount, $currency), $totals, array_keys($totals))) ?: '0.00';
    }
}
