<?php

namespace App\Exports;

use App\Models\CampaignRecipient;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Streams the recipient-level report as CSV in chunks (FromQuery), so large campaigns do not exhaust memory. */
class RecipientsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'Campaign', 'Recipient name', 'Recipient email', 'Status', 'Sent at', 'Delivered at',
            'First opened at', 'Opens', 'First clicked at', 'Clicks', 'Unsubscribed at', 'Bounce reason', 'Failure reason',
        ];
    }

    /** @param CampaignRecipient $r */
    public function map($r): array
    {
        $fmt = fn ($d) => $d?->format('Y-m-d H:i:s');

        return array_map([$this, 'safe'], [
            $r->campaign?->name,
            $r->name,
            $r->email,
            $r->status->value,
            $fmt($r->sent_at),
            $fmt($r->delivered_at),
            $fmt($r->first_opened_at),
            $r->open_count,
            $fmt($r->first_clicked_at),
            $r->click_count,
            $fmt($r->unsubscribed_at),
            $r->bounce_reason,
            $r->failure_reason,
        ]);
    }

    /** Prevents CSV/spreadsheet formula injection from contact-supplied values. */
    private function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
