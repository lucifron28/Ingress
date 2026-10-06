<?php

namespace App\Actions;

use App\Models\StudentIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SplFileObject;

class ImportStudentDirectory
{
    /**
     * Import student identities from a CSV file.
     *
     * @return array{
     *     imported: int,
     *     updated: int,
     *     skipped: int,
     *     failed: int,
     *     failures: array<int, array{row: int, student_id: ?string, reason: string}>,
     *     dry_run: bool
     * }
     */
    public function execute(string|SplFileObject|UploadedFile $file, bool $dryRun = false): array
    {
        $filePath = match (true) {
            $file instanceof UploadedFile => $file->getRealPath(),
            $file instanceof SplFileObject => $file->getRealPath(),
            is_string($file) => $file,
        };

        if (! is_string($filePath) || ! file_exists($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException("Unable to read CSV file at [{$filePath}].");
        }

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new InvalidArgumentException("Failed to open CSV file stream at [{$filePath}].");
        }

        // Read and strip UTF-8 BOM if present
        $headerLine = fgets($handle);
        if ($headerLine === false) {
            fclose($handle);
            throw new InvalidArgumentException('CSV file is empty.');
        }

        $bom = "\xEF\xBB\xBF";
        if (str_starts_with($headerLine, $bom)) {
            $headerLine = substr($headerLine, 3);
        }

        $rawHeaders = str_getcsv($headerLine);
        if (empty($rawHeaders) || ! is_array($rawHeaders)) {
            fclose($handle);
            throw new InvalidArgumentException('CSV header row could not be parsed.');
        }

        // Normalize header keys: lowercase and trim
        $headers = array_map(fn ($h) => Str::lower(trim((string) $h)), $rawHeaders);
        $headerMap = array_flip($headers);

        $requiredHeaders = ['student_id', 'name', 'institutional_email'];
        foreach ($requiredHeaders as $req) {
            if (! isset($headerMap[$req])) {
                fclose($handle);
                throw new InvalidArgumentException("Missing required CSV column header: [{$req}]. Expected columns: student_id, name, institutional_email, college");
            }
        }

        $stats = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'failures' => [],
            'dry_run' => $dryRun,
        ];

        $seenStudentIdsInFile = [];
        $seenEmailsInFile = [];
        $rowNumber = 1; // Row 1 is header

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Skip blank lines
                if (empty($row) || (count($row) === 1 && $row[0] === null)) {
                    continue;
                }

                $studentIdRaw = isset($headerMap['student_id']) ? ($row[$headerMap['student_id']] ?? null) : null;
                $nameRaw = isset($headerMap['name']) ? ($row[$headerMap['name']] ?? null) : null;
                $emailRaw = isset($headerMap['institutional_email']) ? ($row[$headerMap['institutional_email']] ?? null) : null;
                $collegeRaw = isset($headerMap['college']) ? ($row[$headerMap['college']] ?? null) : null;

                $studentId = is_string($studentIdRaw) ? trim($studentIdRaw) : '';
                $name = is_string($nameRaw) ? trim($nameRaw) : '';
                $email = is_string($emailRaw) ? Str::lower(trim($emailRaw)) : '';
                $college = is_string($collegeRaw) && trim($collegeRaw) !== '' ? trim($collegeRaw) : null;

                // Validation checks
                if ($studentId === '') {
                    $stats['failed']++;
                    $stats['failures'][] = [
                        'row' => $rowNumber,
                        'student_id' => null,
                        'reason' => 'Student ID is required.',
                    ];
                    continue;
                }

                if ($name === '') {
                    $stats['failed']++;
                    $stats['failures'][] = [
                        'row' => $rowNumber,
                        'student_id' => $studentId,
                        'reason' => 'Student name is required.',
                    ];
                    continue;
                }

                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stats['failed']++;
                    $stats['failures'][] = [
                        'row' => $rowNumber,
                        'student_id' => $studentId,
                        'reason' => 'Valid institutional email is required.',
                    ];
                    continue;
                }

                // Check for duplicate student_id within the file
                $normalizedStudentIdKey = Str::upper($studentId);
                if (isset($seenStudentIdsInFile[$normalizedStudentIdKey])) {
                    $stats['failed']++;
                    $stats['failures'][] = [
                        'row' => $rowNumber,
                        'student_id' => $studentId,
                        'reason' => "Duplicate student ID [{$studentId}] in CSV file.",
                    ];
                    continue;
                }
                $seenStudentIdsInFile[$normalizedStudentIdKey] = $rowNumber;

                // Check for duplicate institutional_email within the file
                if (isset($seenEmailsInFile[$email])) {
                    $stats['failed']++;
                    $stats['failures'][] = [
                        'row' => $rowNumber,
                        'student_id' => $studentId,
                        'reason' => "Duplicate institutional email [{$email}] in CSV file.",
                    ];
                    continue;
                }
                $seenEmailsInFile[$email] = $rowNumber;

                // Database identity lookup
                $existingById = StudentIdentity::where('student_id', $studentId)->first();
                $existingByEmail = StudentIdentity::where('institutional_email', $email)->first();

                // Detect conflict where student_id and email belong to different identities
                if ($existingById && $existingByEmail && $existingById->id !== $existingByEmail->id) {
                    $stats['failed']++;
                    $stats['failures'][] = [
                        'row' => $rowNumber,
                        'student_id' => $studentId,
                        'reason' => 'Conflict: Student ID and email belong to different directory records.',
                    ];
                    continue;
                }

                $existing = $existingById ?? $existingByEmail;

                if ($existing) {
                    // If identity is already activated, do NOT overwrite linked user account
                    if ($existing->isActivated()) {
                        if (
                            Str::upper($existing->student_id) !== Str::upper($studentId) ||
                            Str::lower($existing->institutional_email) !== Str::lower($email)
                        ) {
                            $stats['failed']++;
                            $stats['failures'][] = [
                                'row' => $rowNumber,
                                'student_id' => $studentId,
                                'reason' => 'Cannot alter student ID or email for already activated account.',
                            ];
                            continue;
                        }

                        // Identity is already activated: skip to avoid modifying activated identity
                        $stats['skipped']++;
                        continue;
                    }

                    // Existing unclaimed identity: update safe directory information
                    $existing->update([
                        'student_id' => $studentId,
                        'name' => $name,
                        'institutional_email' => $email,
                        'college' => $college,
                    ]);

                    $stats['updated']++;
                } else {
                    // New unclaimed institutional identity
                    StudentIdentity::create([
                        'student_id' => $studentId,
                        'name' => $name,
                        'institutional_email' => $email,
                        'college' => $college,
                        'activated_user_id' => null,
                    ]);

                    $stats['imported']++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            fclose($handle);
        }

        return $stats;
    }
}
