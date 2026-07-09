<?php

namespace App\Services\Rollback;

use App\Models\ImportRun;
use App\Services\Import\ImportPipeline;

class RollbackPipeline
{
    public function __construct(
        private readonly RollbackStagingImporter $stagingImporter = new RollbackStagingImporter,
        private readonly ImportPipeline $importPipeline = new ImportPipeline,
    ) {}

    public function run(ImportRun $importRun, ImportRun $targetRun): ImportRun
    {
        $importRun = $this->stagingImporter->run($importRun, $targetRun);

        return $this->importPipeline->continueAfterStaging($importRun);
    }
}
