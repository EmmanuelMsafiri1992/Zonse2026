<?php

namespace App\Models;

use App\Tenancy\BelongsToWorkspace;
use Database\Factories\SmsMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Contacts\Models\Contact;

class SmsMessage extends Model
{
    /** @use HasFactory<SmsMessageFactory> */
    use BelongsToWorkspace, HasFactory;

    public const STATUSES = ['queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed'];

    public const PURPOSES = [
        'manual' => 'Message', 'bulk' => 'Bulk message', 'test' => 'Test', 'invoice' => 'Invoice',
        'receipt' => 'Payment receipt', 'overdue' => 'Overdue reminder', 'appointment' => 'Appointment reminder',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'queued', 'purpose' => 'manual', 'segments' => 1];

    protected $fillable = [
        'workspace_id', 'contact_id', 'subject_type', 'subject_id', 'to', 'body', 'segments', 'purpose', 'status',
        'provider', 'provider_message_id', 'error', 'batch', 'sent_by', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'segments' => 'integer'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? ucfirst($this->purpose);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /**
     * Billable parts: 160 characters for plain GSM text (153 each when split), 70 / 67 once any other character is used.
     */
    public static function segmentsFor(string $body): int
    {
        $length = mb_strlen($body);
        $gsm = preg_match('/^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅå_ÆæßÉ!"#%&\'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\[\]~|€\\\\]*$/u', $body) === 1;
        [$single, $part] = $gsm ? [160, 153] : [70, 67];

        return $length <= $single ? 1 : (int) ceil($length / $part);
    }
}
