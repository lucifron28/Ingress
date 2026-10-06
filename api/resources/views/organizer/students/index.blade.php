@extends('layouts.app')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <div>
        <h1 style="font-size: 1.75rem; font-weight: 700; color: var(--neutral-dark);">Student Directory &amp; Activation</h1>
        <p style="color: var(--neutral-muted); font-size: 0.875rem;">Institutional Student Directory, CSV Roster Ingestion &amp; Account Claim Status</p>
    </div>
</div>

<!-- KPI Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--neutral-muted); margin-bottom: 0.25rem;">Total Directory Records</div>
        <div style="font-size: 2rem; font-weight: 700; color: var(--neutral-dark);">{{ number_format($stats['total']) }}</div>
    </div>
    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--state-success); margin-bottom: 0.25rem;">Activated Accounts</div>
        <div style="font-size: 2rem; font-weight: 700; color: var(--state-success);">{{ number_format($stats['activated']) }}</div>
    </div>
    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--state-warning); margin-bottom: 0.25rem;">Unclaimed Records</div>
        <div style="font-size: 2rem; font-weight: 700; color: var(--state-warning);">{{ number_format($stats['unclaimed']) }}</div>
    </div>
</div>

<!-- CSV Ingestion Section -->
<div class="card" style="margin-bottom: 2rem;">
    <h2 style="font-size: 1.125rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 0.5rem;">Import Institutional Student Directory (CSV)</h2>
    <p style="font-size: 0.8125rem; color: var(--neutral-muted); margin-bottom: 1.25rem;">
        Upload official university student enrollment files. Required columns: <span class="mono">student_id</span>, <span class="mono">name</span>, <span class="mono">institutional_email</span>, optional: <span class="mono">college</span>.
    </p>

    <form method="POST" action="{{ route('organizer.students.import') }}" enctype="multipart/form-data" style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end;">
        @csrf
        <div style="flex: 1; min-width: 280px;">
            <label class="form-label" for="csv_file">Select CSV File</label>
            <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv,text/plain" required class="form-input" style="padding: 0.35rem 0.5rem;">
        </div>

        <div style="display: flex; align-items: center; gap: 0.5rem; padding-bottom: 0.5rem;">
            <input type="checkbox" name="dry_run" id="dry_run" value="1" {{ old('dry_run') ? 'checked' : '' }} style="accent-color: var(--mseuf-maroon); width: 1.1rem; height: 1.1rem;">
            <label for="dry_run" style="font-size: 0.875rem; color: var(--neutral-dark); cursor: pointer; user-select: none;">
                Dry run / Validate only
            </label>
        </div>

        <button type="submit" class="btn-primary">
            <span>&#8679;</span> Upload &amp; Process Directory
        </button>
    </form>

    @if (session('import_stats'))
        @php $import = session('import_stats'); @endphp
        <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--neutral-border);">
            <h3 style="font-size: 0.9375rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 0.75rem;">
                Last Ingestion Summary ({{ $import['dry_run'] ? 'Simulation' : 'Committed' }})
            </h3>
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
                <div style="padding: 0.5rem 0.75rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; font-size: 0.8125rem;">
                    <strong>New Imported:</strong> {{ $import['imported'] }}
                </div>
                <div style="padding: 0.5rem 0.75rem; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 4px; font-size: 0.8125rem;">
                    <strong>Updated (Unclaimed):</strong> {{ $import['updated'] }}
                </div>
                <div style="padding: 0.5rem 0.75rem; background: #fefce8; border: 1px solid #fef08a; border-radius: 4px; font-size: 0.8125rem;">
                    <strong>Skipped (Active):</strong> {{ $import['skipped'] }}
                </div>
                <div style="padding: 0.5rem 0.75rem; background: {{ $import['failed'] > 0 ? '#fef2f2' : '#f8fafc' }}; border: 1px solid {{ $import['failed'] > 0 ? '#fecaca' : '#e2e8f0' }}; border-radius: 4px; font-size: 0.8125rem;">
                    <strong>Failed / Conflicts:</strong> {{ $import['failed'] }}
                </div>
            </div>

            @if (!empty($import['failures']))
                <details style="margin-top: 0.5rem; font-size: 0.8125rem;">
                    <summary style="cursor: pointer; color: var(--state-error); font-weight: 600;">
                        View {{ count($import['failures']) }} Failure Reasons
                    </summary>
                    <div style="margin-top: 0.5rem; max-height: 200px; overflow-y: auto; background: #fff1f2; padding: 0.75rem; border-radius: 4px; border: 1px solid #fecdd3;">
                        @foreach ($import['failures'] as $fail)
                            <div style="margin-bottom: 0.35rem; color: #9f1239;">
                                <strong>Row {{ $fail['row'] }}</strong> ({{ $fail['student_id'] ?? 'N/A' }}): {{ $fail['reason'] }}
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
    @endif
</div>

<!-- Search & Filtering -->
<div class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
    <form method="GET" action="{{ route('organizer.students.index') }}" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
        <div style="flex: 1; min-width: 240px;">
            <input type="text" name="search" value="{{ $search }}" placeholder="Search by student ID, name, email, or college..." class="form-input">
        </div>

        <div style="min-width: 160px;">
            <select name="status" class="form-select">
                <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All Activation States</option>
                <option value="activated" {{ $status === 'activated' ? 'selected' : '' }}>Activated Only</option>
                <option value="unclaimed" {{ $status === 'unclaimed' ? 'selected' : '' }}>Unclaimed Only</option>
            </select>
        </div>

        <button type="submit" class="btn-secondary">Search</button>
        @if ($search !== '' || $status !== 'all')
            <a href="{{ route('organizer.students.index') }}" class="btn-secondary" style="color: var(--neutral-muted);">Clear</a>
        @endif
    </form>
</div>

<!-- Student Directory Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1rem 1.25rem; border-bottom: 1px solid var(--neutral-border); display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 1rem; font-weight: 600; color: var(--neutral-dark);">Directory Records</h2>
        <span style="font-size: 0.75rem; color: var(--neutral-muted);">Showing {{ $students->total() }} total students</span>
    </div>

    @if ($students->isEmpty())
        <div style="padding: 3rem; text-align: center; color: var(--neutral-muted);">
            <p>No student records found matching the criteria.</p>
        </div>
    @else
        <div class="table-container" style="border: none;">
            <table>
                <thead>
                    <tr>
                        <th>Student ID</th>
                        <th>Full Name</th>
                        <th>Institutional Email</th>
                        <th>College</th>
                        <th>Account Status</th>
                        <th>Activation Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($students as $student)
                        <tr>
                            <td class="mono" style="font-weight: 600; color: var(--mseuf-maroon);">
                                {{ $student->student_id }}
                            </td>
                            <td style="font-weight: 500;">
                                {{ $student->name }}
                            </td>
                            <td class="mono" style="font-size: 0.8125rem;">
                                {{ $student->institutional_email }}
                            </td>
                            <td>
                                {{ $student->college ?: '—' }}
                            </td>
                            <td>
                                @if ($student->isActivated())
                                    <span class="badge badge-success">&#10003; Activated</span>
                                @else
                                    <span class="badge badge-warning">&#9888; Unclaimed</span>
                                @endif
                            </td>
                            <td style="font-size: 0.8125rem; color: var(--neutral-muted);">
                                @if ($student->isActivated())
                                    {{ $student->activatedUser?->created_at?->format('M d, Y h:i A') ?: $student->updated_at->format('M d, Y') }}
                                @else
                                    <span style="color: var(--neutral-muted);">Awaiting Claim</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($students->hasPages())
            <div style="padding: 1rem 1.25rem; border-top: 1px solid var(--neutral-border);">
                {{ $students->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
