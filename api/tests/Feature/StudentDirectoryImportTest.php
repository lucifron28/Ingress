<?php

namespace Tests\Feature;

use App\Actions\ImportStudentDirectory;
use App\Models\StudentIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StudentDirectoryImportTest extends TestCase
{
    use RefreshDatabase;

    private string $tempCsvPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempCsvPath = storage_path('framework/testing/test_students.csv');
    }

    protected function tearDown(): void
    {
        if (File::exists($this->tempCsvPath)) {
            File::delete($this->tempCsvPath);
        }
        parent::tearDown();
    }

    public function test_can_import_student_directory_from_csv(): void
    {
        $csvContent = implode("\n", [
            'student_id,name,institutional_email,college',
            'A23-10001,Juan Luna,jluna@student.mseuf.edu.ph,CCMS',
            'A23-10002,Maria Makiling,mmakiling@student.mseuf.edu.ph,CBA',
            'A23-10003,Jose Burgos,jburgos@student.mseuf.edu.ph,CAS',
        ]);

        File::put($this->tempCsvPath, $csvContent);

        $importer = new ImportStudentDirectory();
        $stats = $importer->execute($this->tempCsvPath);

        $this->assertEquals(3, $stats['imported']);
        $this->assertEquals(0, $stats['failed']);
        $this->assertEquals(0, $stats['updated']);
        $this->assertEquals(0, $stats['skipped']);

        $this->assertDatabaseHas('student_identities', [
            'student_id' => 'A23-10001',
            'name' => 'Juan Luna',
            'institutional_email' => 'jluna@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => null,
        ]);
        $this->assertDatabaseHas('student_identities', [
            'student_id' => 'A23-10002',
            'name' => 'Maria Makiling',
            'institutional_email' => 'mmakiling@student.mseuf.edu.ph',
            'college' => 'CBA',
        ]);
    }

    public function test_import_handles_utf8_bom_and_trims_whitespace(): void
    {
        $bom = "\xEF\xBB\xBF";
        $csvContent = $bom . implode("\n", [
            'student_id,name,institutional_email,college',
            ' A23-20001 ,  Apolinario Mabini  ,  amabini@student.mseuf.edu.ph  , CCMS ',
        ]);

        File::put($this->tempCsvPath, $csvContent);

        $importer = new ImportStudentDirectory();
        $stats = $importer->execute($this->tempCsvPath);

        $this->assertEquals(1, $stats['imported']);
        $this->assertDatabaseHas('student_identities', [
            'student_id' => 'A23-20001',
            'name' => 'Apolinario Mabini',
            'institutional_email' => 'amabini@student.mseuf.edu.ph',
            'college' => 'CCMS',
        ]);
    }

    public function test_import_updates_existing_unclaimed_identities(): void
    {
        StudentIdentity::create([
            'student_id' => 'A23-30001',
            'name' => 'Original Name',
            'institutional_email' => 'student30001@student.mseuf.edu.ph',
            'college' => 'CAS',
            'activated_user_id' => null,
        ]);

        $csvContent = implode("\n", [
            'student_id,name,institutional_email,college',
            'A23-30001,Updated Name,student30001@student.mseuf.edu.ph,CCMS',
        ]);

        File::put($this->tempCsvPath, $csvContent);

        $importer = new ImportStudentDirectory();
        $stats = $importer->execute($this->tempCsvPath);

        $this->assertEquals(0, $stats['imported']);
        $this->assertEquals(1, $stats['updated']);
        $this->assertDatabaseHas('student_identities', [
            'student_id' => 'A23-30001',
            'name' => 'Updated Name',
            'college' => 'CCMS',
        ]);
    }

    public function test_import_skips_and_protects_already_activated_identities(): void
    {
        $user = User::factory()->create([
            'email' => 'activated@student.mseuf.edu.ph',
            'role' => User::ROLE_STUDENT,
        ]);

        $identity = StudentIdentity::create([
            'student_id' => 'A23-40001',
            'name' => 'Activated Student',
            'institutional_email' => 'activated@student.mseuf.edu.ph',
            'college' => 'CCMS',
            'activated_user_id' => $user->id,
        ]);

        $csvContent = implode("\n", [
            'student_id,name,institutional_email,college',
            'A23-40001,Attempted Modification,activated@student.mseuf.edu.ph,Engineering',
        ]);

        File::put($this->tempCsvPath, $csvContent);

        $importer = new ImportStudentDirectory();
        $stats = $importer->execute($this->tempCsvPath);

        $this->assertEquals(1, $stats['skipped']);
        $this->assertEquals(0, $stats['updated']);

        // Identity should remain untouched
        $identity->refresh();
        $this->assertEquals('Activated Student', $identity->name);
        $this->assertEquals('CCMS', $identity->college);
    }

    public function test_import_rejects_duplicate_records_within_csv_file(): void
    {
        $csvContent = implode("\n", [
            'student_id,name,institutional_email,college',
            'A23-50001,Student One,s1@student.mseuf.edu.ph,CCMS',
            'A23-50001,Student Duplicate ID,s2@student.mseuf.edu.ph,CCMS',
        ]);

        File::put($this->tempCsvPath, $csvContent);

        $importer = new ImportStudentDirectory();
        $stats = $importer->execute($this->tempCsvPath);

        $this->assertEquals(1, $stats['imported']);
        $this->assertEquals(1, $stats['failed']);
        $this->assertStringContainsString('Duplicate student ID', $stats['failures'][0]['reason']);
    }

    public function test_dry_run_does_not_persist_records(): void
    {
        $csvContent = implode("\n", [
            'student_id,name,institutional_email,college',
            'A23-60001,Simulated Student,sim@student.mseuf.edu.ph,CCMS',
        ]);

        File::put($this->tempCsvPath, $csvContent);

        $importer = new ImportStudentDirectory();
        $stats = $importer->execute($this->tempCsvPath, dryRun: true);

        $this->assertTrue($stats['dry_run']);
        $this->assertEquals(1, $stats['imported']);
        $this->assertDatabaseMissing('student_identities', [
            'student_id' => 'A23-60001',
        ]);
    }

    public function test_artisan_command_imports_directory_file(): void
    {
        $csvContent = implode("\n", [
            'student_id,name,institutional_email,college',
            'A23-70001,CLI Student,cli@student.mseuf.edu.ph,CCMS',
        ]);

        File::put($this->tempCsvPath, $csvContent);

        $this->artisan('students:import', ['file' => $this->tempCsvPath])
            ->expectsOutputToContain('Student directory import completed')
            ->assertExitCode(0);

        $this->assertDatabaseHas('student_identities', [
            'student_id' => 'A23-70001',
            'name' => 'CLI Student',
        ]);
    }

    public function test_artisan_command_supports_dry_run_flag(): void
    {
        $csvContent = implode("\n", [
            'student_id,name,institutional_email,college',
            'A23-80001,CLI Dry Student,clisup@student.mseuf.edu.ph,CCMS',
        ]);

        File::put($this->tempCsvPath, $csvContent);

        $this->artisan('students:import', ['file' => $this->tempCsvPath, '--dry-run' => true])
            ->expectsOutputToContain('Dry run completed successfully')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('student_identities', [
            'student_id' => 'A23-80001',
        ]);
    }

}
