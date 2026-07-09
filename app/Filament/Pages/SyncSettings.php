<?php

namespace App\Filament\Pages;

use App\Models\SyncSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

/**
 * Pagina "singleton": non c'e' un elenco di record, esiste sempre e solo la
 * riga restituita da SyncSetting::current(), quindi il form la carica e
 * salva direttamente senza passare da un ImportRunResource-style CRUD.
 */
class SyncSettings extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Impostazioni';

    protected static ?string $title = 'Impostazioni sync';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.sync-settings';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(SyncSetting::current()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Sorgente CSV')
                    ->schema([
                        TextInput::make('csv_source_url')
                            ->label('URL del CSV')
                            ->required()
                            ->url()
                            ->columnSpanFull(),
                        TimePicker::make('scheduled_run_time')
                            ->label('Orario sync notturno')
                            ->seconds(false),
                        Toggle::make('auto_sync_enabled')
                            ->label('Sync automatico notturno attivo'),
                    ])
                    ->columns(2),

                Section::make('Modalita\' e sicurezza')
                    ->schema([
                        Toggle::make('dry_run_mode')
                            ->label('Dry-run di default')
                            ->helperText('Se attivo, il sync notturno automatico calcola solo il piano senza applicarlo a Shopify. Il pulsante "Importa ora" resta comunque selezionabile manualmente per una run live.')
                            ->live(),
                        TextInput::make('anomaly_drop_threshold_percent')
                            ->label('Soglia anomalia (% calo prodotti)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Notifiche')
                    ->schema([
                        TextInput::make('notification_email')
                            ->label('Email di notifica')
                            ->email()
                            ->columnSpanFull(),
                        Toggle::make('notify_on_success')->label('Notifica su successo'),
                        Toggle::make('notify_on_failure')->label('Notifica su fallimento'),
                        Toggle::make('notify_on_anomaly')->label('Notifica su anomalia'),
                        Textarea::make('slack_webhook_url')
                            ->label('Slack webhook URL')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => filled($get('notify_on_success')) || filled($get('notify_on_failure')) || filled($get('notify_on_anomaly'))),
                    ])
                    ->columns(3),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Salva')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SyncSetting::current()->update($data);

        Notification::make()
            ->title('Impostazioni salvate')
            ->success()
            ->send();
    }
}
