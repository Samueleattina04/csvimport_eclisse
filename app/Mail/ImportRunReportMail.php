<?php

namespace App\Mail;

use App\Models\ImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ImportRunReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ImportRun $importRun,
        public readonly string $category,
    ) {}

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine())
            ->view('emails.import-run-report');
    }

    private function subjectLine(): string
    {
        $label = match ($this->category) {
            'anomaly' => 'Anomalia rilevata',
            'failure' => 'Import con errori',
            default => 'Import completato',
        };

        return "[Eclisse Sync] {$label} - import #{$this->importRun->id}";
    }
}
