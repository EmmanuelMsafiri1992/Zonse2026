<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One visitor's answers to a public form, kept as they were sent, with a link to the record they became.
 *
 * @property list<array{label: string, value: ?string}> $data the answers in the form's order
 */
class FormSubmission extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'web_form_id', 'subject_type', 'subject_id', 'data', 'ip_hash'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(WebForm::class, 'web_form_id');
    }

    /** The first few answers on one line, for alerts. */
    public function summary(int $answers = 3): string
    {
        return collect($this->data)->filter(fn (array $answer) => filled($answer['value']))->take($answers)
            ->map(fn (array $answer) => $answer['label'].': '.$answer['value'])->join(' · ');
    }

    /** The contact, ticket or app record the answers created. */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
