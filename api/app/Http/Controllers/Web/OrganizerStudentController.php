<?php

namespace App\Http\Controllers\Web;

use App\Actions\ImportStudentDirectory;
use App\Http\Controllers\Controller;
use App\Models\StudentIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class OrganizerStudentController extends Controller
{
    /**
     * Display student directory listings and activation statistics.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status', 'all');

        $query = StudentIdentity::query()->with('activatedUser');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('student_id', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%")
                    ->orWhere('institutional_email', 'ilike', "%{$search}%")
                    ->orWhere('college', 'ilike', "%{$search}%");
            });
        }

        if ($status === 'activated') {
            $query->whereNotNull('activated_user_id');
        } elseif ($status === 'unclaimed') {
            $query->whereNull('activated_user_id');
        }

        $students = $query->orderBy('name')->paginate(25)->withQueryString();

        $stats = [
            'total' => StudentIdentity::count(),
            'activated' => StudentIdentity::whereNotNull('activated_user_id')->count(),
            'unclaimed' => StudentIdentity::whereNull('activated_user_id')->count(),
        ];

        return view('organizer.students.index', compact('students', 'stats', 'search', 'status'));
    }

    /**
     * Handle CSV import upload for student directory records.
     */
    public function import(Request $request, ImportStudentDirectory $importer): RedirectResponse
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $dryRun = $request->boolean('dry_run');
        $file = $request->file('csv_file');

        try {
            $stats = $importer->execute($file, $dryRun);
        } catch (Throwable $e) {
            return back()->with('error', 'CSV Import Failed: ' . $e->getMessage());
        }

        $message = $dryRun
            ? "Dry run validation complete: {$stats['imported']} new, {$stats['updated']} updated, {$stats['skipped']} skipped, {$stats['failed']} failed."
            : "Directory imported successfully: {$stats['imported']} imported, {$stats['updated']} updated, {$stats['skipped']} skipped, {$stats['failed']} failed.";

        return back()
            ->with('success', $message)
            ->with('import_stats', $stats);
    }
}
