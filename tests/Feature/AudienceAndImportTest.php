<?php

namespace Tests\Feature;

use App\Enums\SuppressionReason;
use App\Models\Audience;
use App\Models\AudienceGroup;
use App\Models\CsvImport;
use App\Models\User;
use App\Services\SuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AudienceAndImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mailing.csv.disk' => 'local']);
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    public function test_contact_can_be_added_and_email_is_normalised(): void
    {
        $this->post('/audiences', ['name' => 'Jane', 'email' => '  Jane@Example.COM ', 'status' => 'active', 'subscription_status' => 'subscribed'])->assertRedirect();

        $this->assertDatabaseHas('audiences', ['email' => 'jane@example.com']);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Audience::factory()->create(['email' => 'dup@example.com']);

        $this->post('/audiences', ['email' => 'DUP@example.com', 'status' => 'active', 'subscription_status' => 'subscribed'])->assertSessionHasErrors('email');
    }

    public function test_deleting_a_contact_keeps_suppression_entry(): void
    {
        $contact = Audience::factory()->create();
        app(SuppressionService::class)->add($contact->email, SuppressionReason::Unsubscribed);

        $this->delete("/audiences/{$contact->id}");

        $this->assertSoftDeleted($contact);
        $this->assertDatabaseHas('suppression_list', ['email' => $contact->email]);
    }

    public function test_csv_import_creates_valid_rows_and_reports_the_rest(): void
    {
        Audience::factory()->create(['email' => 'existing@example.com']);
        app(SuppressionService::class)->add('blocked@example.com', SuppressionReason::Bounced);
        $group = AudienceGroup::factory()->create();

        $csv = "Email,Name,Company\n"
            ."new1@example.com,One,Acme\n"
            ."NEW2@example.com,Two,Acme\n"
            ."existing@example.com,Dup,Acme\n"
            ."new1@example.com,Repeat,Acme\n"
            ."blocked@example.com,Blocked,Acme\n"
            ."not-an-email,Bad,Acme\n"
            .",Empty,Acme\n";

        $this->post('/imports', ['file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv), 'group_ids' => [$group->id]])->assertRedirect();

        $import = CsvImport::firstOrFail();
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->imported_count);
        $this->assertSame(3, $import->skipped_count);
        $this->assertSame(2, $import->failed_count);
        $this->assertSame(2, $group->audiences()->count());
        $this->assertDatabaseHas('audiences', ['email' => 'new2@example.com']);
    }

    public function test_csv_without_email_column_is_rejected(): void
    {
        $this->post('/imports', ['file' => UploadedFile::fake()->createWithContent('bad.csv', "name,company\nA,B\n")])->assertSessionHasErrors();

        $this->assertSame(0, CsvImport::count());
    }
}
