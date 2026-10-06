<?php

namespace App\Console\Commands;

use App\Actions\ImportStudentDirectory;
use Illuminate\Console\Command;
use Throwable;

class ImportStudentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'students:import
                            {file : Path to the CSV directory file}
                            {--dry-run : Validate and parse without saving to database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import institutional student directory records from a CSV file';

    /**
     * Execute the console command.
     */
    public function handle(ImportStudentDirectory $importer): int
    {
        $filePath = $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! file_exists($filePath) || ! is_readable($filePath)) {
            $this->error("Cannot read file at [{$filePath}]. Please provide a valid CSV path.");

            return Command::FAILURE;
        }

        $this->info("Importing student directory from [{$filePath}]" . ($dryRun ? ' (DRY RUN)' : '') . '...');

        try {
            $stats = $importer->execute($filePath, $dryRun);
        } catch (Throwable $e) {
            $this->error("Import failed with error: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Imported (New)', $stats['imported']],
                ['Updated (Unclaimed)', $stats['updated']],
                ['Skipped (Already Activated)', $stats['skipped']],
                ['Failed (Invalid / Conflict)', $stats['failed']],
                ['Mode', $stats['dry_run'] ? 'Dry Run (Simulated)' : 'Committed'],
            ]
        );

        if (! empty($stats['failures'])) {
            $this->warn("\nFailures Encountered (" . count($stats['failures']) . '):');
            $this->table(
                ['Row', 'Student ID', 'Reason'],
                array_map(fn ($f) => [
                    $f['row'],
                    $f['student_id'] ?? 'N/A',
                    $f['reason'],
                ], array_slice($stats['failures'], 0, 50))
            );

            if (count($stats['failures']) > 50) {
                $this->line('... and ' . (count($stats['failures']) - 50) . ' more failures.');
            }
        }

        $this->info($dryRun ? 'Dry run completed successfully.' : 'Student directory import completed.');

        return Command::SUCCESS;
    }
}
