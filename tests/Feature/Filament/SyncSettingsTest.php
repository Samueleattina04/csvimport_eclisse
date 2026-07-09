<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\SyncSettings;
use App\Models\SyncSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SyncSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_page_loads_the_current_settings(): void
    {
        SyncSetting::current()->update([
            'csv_source_url' => 'https://example.test/export.csv',
            'anomaly_drop_threshold_percent' => 42,
        ]);

        Livewire::test(SyncSettings::class)
            ->assertOk()
            ->assertSchemaStateSet([
                'csv_source_url' => 'https://example.test/export.csv',
                'anomaly_drop_threshold_percent' => 42,
            ]);
    }

    public function test_saving_updates_the_singleton_row(): void
    {
        SyncSetting::current();

        Livewire::test(SyncSettings::class)
            ->fillForm([
                'csv_source_url' => 'https://updated.test/export.csv',
                'anomaly_drop_threshold_percent' => 15,
                'dry_run_mode' => false,
                'notify_on_failure' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $setting = SyncSetting::current();

        $this->assertSame('https://updated.test/export.csv', $setting->csv_source_url);
        $this->assertSame(15, $setting->anomaly_drop_threshold_percent);
        $this->assertFalse($setting->dry_run_mode);
        $this->assertTrue($setting->notify_on_failure);

        $this->assertSame(1, SyncSetting::count());
    }

    public function test_csv_source_url_is_required(): void
    {
        SyncSetting::current();

        Livewire::test(SyncSettings::class)
            ->fillForm(['csv_source_url' => ''])
            ->call('save')
            ->assertHasFormErrors(['csv_source_url' => 'required']);
    }
}
