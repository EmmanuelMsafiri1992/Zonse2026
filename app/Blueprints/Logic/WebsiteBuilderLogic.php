<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Contacts\Models\Contact;

/**
 * Website & landing pages: every page has its own URL slug, written the web way however it was typed.
 * Landing pages and blog posts need an SEO description of at most 160 characters before they go
 * live. Form submissions come from published pages, need an email or phone to answer, and aren't
 * taken twice from the same person while one is still open. Submissions stuffed with links are
 * marked spam, and converting one makes or finds the contact behind it.
 */
class WebsiteBuilderLogic extends AppLogic
{
    /**
     * Longest SEO description search engines show in full.
     */
    public const SEO_LENGTH = 160;

    /**
     * Links in a message that mark it as spam.
     */
    protected const SPAM_LINKS = 3;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return $entity->key === 'pages' ? $this->validatePage($payload, $existing) : $this->validateLead($payload, $existing);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validatePage(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $slug = Str::slug((string) ($data['slug'] ?? ''));
        if (filled($data['slug'] ?? null) && $slug === '') {
            $errors['data.slug'] = 'Use letters or numbers in the slug.';
        } elseif ($slug !== '' && ($taken = $this->records('pages')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $page) => $page->value('slug') === $slug))) {
            $errors['data.slug'] = '/'.$slug.' is already used by '.$taken->title.'.';
        }
        $description = trim((string) ($data['seo_description'] ?? ''));
        if (mb_strlen($description) > self::SEO_LENGTH) {
            $errors['data.seo_description'] = 'Keep it to '.self::SEO_LENGTH.' characters so it shows in full; it is '.mb_strlen($description).'.';
        } elseif ($payload['status'] === 'published' && $description === '' && in_array($data['type'] ?? null, ['landing_page', 'blog_post'], true)) {
            $errors['data.seo_description'] = 'Write an SEO description before publishing.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateLead(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        if ($payload['status'] === 'spam') {
            return [];
        }
        $errors = [];
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $phone = preg_replace('/\D/', '', (string) ($data['phone'] ?? ''));
        if ($email === '' && $phone === '') {
            $errors['data.email'] = 'Take an email or phone number so someone can answer.';
        }
        if (! $existing && filled($data['page'] ?? null) && ($page = $this->records('pages')->find($data['page'])) && $page->status !== 'published') {
            $errors['data.page'] = $page->title.' is not published.';
        }
        if ($payload['status'] !== 'converted' && ($email !== '' || $phone !== '')) {
            $open = $this->records('leads')->whereIn('status', ['new', 'contacted'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $lead) => ($email !== '' && mb_strtolower((string) $lead->value('email')) === $email) || ($phone !== '' && preg_replace('/\D/', '', (string) $lead->value('phone')) === $phone));
            if ($open) {
                $errors['data.email'] = 'This person already has an open submission, '.$open->number.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'pages') {
            $this->put($record, ['slug' => Str::slug((string) $record->value('slug'))]);
            if ($record->status === 'published' && ! $record->occurs_on) {
                $record->occurs_on = today();
            }

            return;
        }
        $record->occurs_on ??= today();
        if (filled($record->value('email'))) {
            $this->put($record, ['email' => mb_strtolower(trim((string) $record->value('email')))]);
        }
        if (! $record->exists && preg_match_all('#https?://|www\.#i', (string) $record->value('message')) >= self::SPAM_LINKS) {
            $record->status = 'spam';
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'pages') {
            return match ($record->status) {
                'published' => ['unpublish' => ['label' => 'Unpublish', 'icon' => 'eye-off']],
                default => ['publish' => ['label' => 'Publish', 'icon' => 'globe']],
            };
        }

        return match ($record->status) {
            'new' => ['contacted' => ['label' => 'Contacted', 'icon' => 'phone'], 'convert' => ['label' => 'Convert to customer', 'icon' => 'user-check'], 'spam' => ['label' => 'Spam', 'icon' => 'ban']],
            'contacted' => ['convert' => ['label' => 'Convert to customer', 'icon' => 'user-check'], 'spam' => ['label' => 'Spam', 'icon' => 'ban']],
            'spam' => ['not_spam' => ['label' => 'Not spam', 'icon' => 'undo-2']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'publish':
                $type = $record->value('type');
                if (in_array($type, ['landing_page', 'blog_post'], true) && blank($record->value('seo_description'))) {
                    throw ValidationException::withMessages(['seo_description' => 'Write an SEO description before publishing.']);
                }
                $record->update(['status' => 'published', 'occurs_on' => $record->occurs_on ?? today()]);

                return $record->title.' is live at /'.$record->value('slug').'.';
            case 'unpublish':
                $record->update(['status' => 'unpublished']);

                return $record->title.' unpublished.';
            case 'contacted':
                $record->update(['status' => 'contacted', 'data' => [...$record->data, '_contacted_at' => now()->toDateTimeString()]]);

                return $record->title.' marked contacted.';
            case 'convert':
                $contact = $record->contact ?? $this->contactFor($record);
                $record->update(['status' => 'converted', 'contact_id' => $contact->id, 'data' => [...$record->data, '_converted_at' => now()->toDateTimeString()]]);

                return $record->title.' converted; '.$contact->name.' is now a customer.';
            case 'spam':
                $record->update(['status' => 'spam']);

                return $record->title.' marked as spam.';
            default:
                $record->update(['status' => 'new']);

                return $record->title.' is back in the inbox.';
        }
    }

    /**
     * The contact behind a submission: one already on file with the same email or phone, or a new customer.
     */
    protected function contactFor(Record $lead): Contact
    {
        $email = (string) $lead->value('email');
        $phone = (string) $lead->value('phone');
        $existing = $email === '' && $phone === '' ? null : Contact::query()->where(fn ($query) => $query
            ->when($email !== '', fn ($query) => $query->orWhere('email', $email))
            ->when($phone !== '', fn ($query) => $query->orWhere('phone', $phone)))->first();
        if ($existing) {
            if ($existing->type === 'lead') {
                $existing->update(['type' => 'customer']);
            }

            return $existing;
        }

        return Contact::query()->create(['name' => (string) $lead->title, 'type' => 'customer', 'kind' => 'person', 'email' => $email ?: null, 'phone' => $phone ?: null]);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'pages') {
            return [];
        }
        $leads = $this->linked('leads', 'page', $record)->get();
        $real = $leads->where('status', '!=', 'spam');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Performance', 'icon' => 'line-chart', 'stats' => [
            ['label' => 'Views', 'value' => number_format($this->number($record, 'views'))],
            ['label' => 'Submissions', 'value' => $real->count()],
            ['label' => 'Submission rate', 'value' => $this->rate($real->count(), $this->number($record, 'views'))],
            ['label' => 'Converted', 'value' => $real->where('status', 'converted')->count(), 'tone' => 'success'],
        ]]]];
    }

    /**
     * A share as a percentage, or a dash when there is nothing to divide by.
     */
    protected function rate(float|int $part, float|int $whole): string
    {
        return $whole > 0 ? number_format($part / $whole * 100, 1).'%' : '—';
    }

    public function homeCards(): array
    {
        $new = $this->records('leads')->where('status', 'new')->orderBy('created_at')->get();
        $pages = $this->records('pages')->pluck('title', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'New submissions', 'icon' => 'inbox', 'empty' => 'No new submissions.',
            'rows' => $new->map(fn (Record $lead) => [
                'label' => $lead->title, 'sub' => $pages[$lead->value('page')] ?? null, 'value' => $lead->created_at?->diffForHumans(), 'href' => $lead->url(),
                'tone' => $lead->created_at?->lt(now()->subDay()) ? 'danger' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $leads = $this->dated('leads', $from, $to)->get();
        $pages = $this->records('pages')->get()->keyBy('id');

        $byPage = $leads->groupBy(fn (Record $lead) => (int) $lead->value('page'))
            ->map(function (Collection $group, int $id) use ($pages) {
                $page = $pages[$id] ?? null;
                $real = $group->where('status', '!=', 'spam');

                return [$page?->title ?? 'No page', $page ? number_format($this->number($page, 'views')) : '—', $real->count(), $page ? $this->rate($real->count(), $this->number($page, 'views')) : '—', $real->where('status', 'converted')->count(), $this->rate($real->where('status', 'converted')->count(), $real->count()), $group->where('status', 'spam')->count()];
            })->sortBy(0)->values()->all();

        $top = $pages->where('status', 'published')->sortByDesc(fn (Record $page) => $this->number($page, 'views'))->take(20)
            ->map(fn (Record $page) => [$page->title, '/'.$page->value('slug'), ucfirst(str_replace('_', ' ', (string) $page->value('type'))), number_format($this->number($page, 'views'))])->values()->all();

        return [
            ['title' => 'Submissions by page', 'columns' => ['Page', 'Views', 'Submissions', 'Submission rate', 'Converted', 'Conversion rate', 'Spam'], 'rows' => $byPage],
            ['title' => 'Top pages', 'columns' => ['Page', 'URL', 'Type', 'Views'], 'rows' => $top],
        ];
    }
}
