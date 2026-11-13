@extends('layouts.app', ['title' => 'Student access settings'])

@section('content')
<div style="max-width: 900px; margin: 0 auto;">
    <header style="margin-bottom: 1.5rem;">
        <p style="margin-bottom: .35rem; color: var(--mseuf-maroon); font-size: .75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;">Cloud administration</p>
        <h1 style="font-size: 1.75rem; font-weight: 750; color: var(--neutral-dark);">Student access</h1>
        <p style="margin-top: .35rem; color: var(--neutral-muted);">Choose which email addresses can create student accounts and make new student registrations.</p>
    </header>

    <section class="card" aria-labelledby="policy-title">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
            <div>
                <h2 id="policy-title" style="font-size: 1.15rem; color: var(--neutral-dark);">Require institutional student email</h2>
                <p style="margin-top: .4rem; color: var(--neutral-muted);">
                    Current policy:
                    @if ($requiresInstitutionalEmail)
                        <span class="badge badge-warning">Institutional email required</span>
                    @else
                        <span class="badge badge-success">Personal email allowed</span>
                    @endif
                </p>
            </div>
        </div>

        @if ($requiresInstitutionalEmail)
            <p style="margin-top: 1rem; color: var(--neutral-body);">New student accounts and registrations must use the exact <code>{{ '@'.$institutionalDomain }}</code> domain. Existing verified student accounts keep access to their account and tickets, but personal-email accounts cannot make new student registrations. Student-only event and ticket-category eligibility remains a separate rule.</p>
        @else
            <p style="margin-top: 1rem; color: var(--neutral-body);">Any valid email address may be used for a Firebase-verified student account. Email verification proves access to the mailbox, not current enrollment. Student-only event and ticket-category eligibility remains a separate rule.</p>
        @endif

        <form method="POST" action="{{ route('admin.settings.student-access') }}" style="margin-top: 1.25rem;">
            @csrf
            <input type="hidden" name="require_institutional_email" value="0">
            <label for="require-institutional-email" style="display: flex; align-items: flex-start; gap: .75rem; color: var(--neutral-dark); font-weight: 650;">
                <input id="require-institutional-email" type="checkbox" name="require_institutional_email" value="1" {{ $requiresInstitutionalEmail ? 'checked' : '' }} style="width: 1.1rem; height: 1.1rem; margin-top: .15rem; accent-color: var(--mseuf-maroon);">
                Require <code>{{ '@'.$institutionalDomain }}</code> for new student accounts and registrations
            </label>
            @error('require_institutional_email')
                <p class="badge-error" role="alert" style="margin-top: .75rem;">{{ $message }}</p>
            @enderror
            <div style="display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1.25rem;">
                <button class="btn-primary" type="submit">Save student access policy</button>
                <a class="btn-secondary" href="{{ route('organizer.dashboard') }}">Back to dashboard</a>
            </div>
        </form>
    </section>

    <section class="card" aria-labelledby="audit-title" style="margin-top: 1.25rem;">
        <h2 id="audit-title" style="font-size: 1.1rem; color: var(--neutral-dark);">Recent policy changes</h2>
        <p style="margin: .35rem 0 1rem; color: var(--neutral-muted);">Each setting change records the administrator, previous state, new state and time in UTC.</p>
        @if ($audits->isEmpty())
            <p style="color: var(--neutral-muted);">No changes recorded. The policy started in the permissive state.</p>
        @else
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Changed</th>
                            <th scope="col">Administrator</th>
                            <th scope="col">Previous</th>
                            <th scope="col">New</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($audits as $audit)
                            <tr>
                                <td>{{ \Carbon\CarbonImmutable::parse($audit->created_at)->utc()->format('M j, Y g:i A') }} UTC</td>
                                <td>{{ $audit->admin_email }} · #{{ $audit->admin_user_id }}</td>
                                <td>{{ $audit->old_required ? 'Institutional required' : 'Personal allowed' }}</td>
                                <td>{{ $audit->new_required ? 'Institutional required' : 'Personal allowed' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
