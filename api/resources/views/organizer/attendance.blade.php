@extends('layouts.app')

@section('content')
<div style="margin-bottom: 2rem;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem;">
        <div>
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.25rem;">
                <h1 style="font-size: 1.75rem; font-weight: 700; color: var(--neutral-dark);">Event Attendance: {{ $event->title }}</h1>
                <span id="live-indicator" style="display: inline-flex; align-items: center; gap: 0.4rem; background: #ecfdf5; color: #065f46; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.6rem; border-radius: 9999px; border: 1px solid #a7f3d0;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; display: inline-block;"></span>
                    LAN LIVE
                </span>
            </div>
            <p style="color: var(--neutral-muted); font-size: 0.875rem;">
                Venue: <strong>{{ $event->venue->name }}</strong> &bull; Schedule: {{ $event->starts_at->format('M d, Y h:i A') }} to {{ $event->ends_at->format('h:i A') }}
            </p>
        </div>

        <div style="display: flex; gap: 0.5rem;">
            <a href="{{ route('organizer.events.show', $event) }}" class="btn-secondary">
                Back to Event
            </a>
            <button onclick="fetchLiveData()" class="btn-primary" style="padding: 0.5rem 0.85rem; font-size: 0.8rem;">
                Refresh Feed
            </button>
        </div>
    </div>
</div>

<!-- Core KPI Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--neutral-muted); margin-bottom: 0.25rem;">Total Capacity</div>
        <div id="stat-capacity" style="font-size: 2rem; font-weight: 700; color: var(--neutral-dark);" class="mono">{{ $stats['total_capacity'] }}</div>
        <div style="font-size: 0.75rem; color: var(--neutral-muted); margin-top: 0.25rem;">Configured venue limit</div>
    </div>

    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--mseuf-maroon); margin-bottom: 0.25rem;">Registered</div>
        <div id="stat-registered" style="font-size: 2rem; font-weight: 700; color: var(--mseuf-maroon);" class="mono">{{ $stats['registered_count'] }}</div>
        <div style="font-size: 0.75rem; color: var(--neutral-muted); margin-top: 0.25rem;">Confirmed tickets issued</div>
    </div>

    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--state-success); margin-bottom: 0.25rem;">Checked In</div>
        <div id="stat-checked-in" style="font-size: 2rem; font-weight: 700; color: var(--state-success);" class="mono">{{ $stats['checked_in_count'] }}</div>
        <div style="font-size: 0.75rem; color: var(--neutral-muted); margin-top: 0.25rem;">Admitted at turnstiles</div>
    </div>

    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--state-warning); margin-bottom: 0.25rem;">Not Arrived</div>
        <div id="stat-not-arrived" style="font-size: 2rem; font-weight: 700; color: var(--state-warning);" class="mono">{{ $stats['not_arrived_count'] }}</div>
        <div style="font-size: 0.75rem; color: var(--neutral-muted); margin-top: 0.25rem;">Pending admission</div>
    </div>

    <div class="card">
        <div style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--neutral-dark); margin-bottom: 0.25rem;">Attendance Rate</div>
        <div id="stat-percentage" style="font-size: 2rem; font-weight: 700; color: var(--neutral-dark);" class="mono">{{ $stats['attendance_percentage'] }}%</div>
        <div style="width: 100%; background: #e2e8f0; height: 6px; border-radius: 3px; margin-top: 0.5rem; overflow: hidden;">
            <div id="progress-bar" style="width: {{ $stats['attendance_percentage'] }}%; background: var(--mseuf-maroon); height: 100%;"></div>
        </div>
    </div>
</div>

<!-- Gate Breakdown & Recent Admissions Grid -->
<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; margin-bottom: 2rem;">
    <!-- Gate Admissions Breakdown -->
    <div class="card">
        <h2 style="font-size: 1rem; font-weight: 600; color: var(--neutral-dark); margin-bottom: 1rem; border-bottom: 1px solid var(--neutral-border); padding-bottom: 0.5rem;">
            Throughput by Gate
        </h2>

        <div id="gate-container" style="display: flex; flex-direction: column; gap: 0.75rem;">
            @forelse ($gateStats as $gate)
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: #f8fafc; border: 1px solid var(--neutral-border); border-radius: 4px;">
                    <div>
                        <span class="mono" style="font-size: 0.75rem; font-weight: 700; background: #e2e8f0; padding: 0.15rem 0.4rem; border-radius: 3px; margin-right: 0.5rem;">{{ $gate['code'] }}</span>
                        <strong style="font-size: 0.875rem;">{{ $gate['name'] }}</strong>
                    </div>
                    <div class="mono" style="font-size: 1.1rem; font-weight: 700; color: var(--mseuf-maroon);">
                        {{ $gate['count'] }}
                    </div>
                </div>
            @empty
                <p style="color: var(--neutral-muted); font-size: 0.85rem;">No gates configured.</p>
            @endforelse
        </div>
    </div>

    <!-- Live Recent Admissions Feed -->
    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 0.85rem 1.25rem; border-bottom: 1px solid var(--neutral-border); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h2 style="font-size: 1rem; font-weight: 600; color: var(--neutral-dark);">Recent Admissions (Live Feed)</h2>
            <span style="font-size: 0.75rem; color: var(--neutral-muted);" id="feed-last-updated">Auto-refreshing</span>
        </div>

        <div class="table-container" style="border: none;">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Attendee Name</th>
                        <th>Student ID</th>
                        <th>Gate</th>
                        <th>Scanned By</th>
                    </tr>
                </thead>
                <tbody id="recent-admissions-tbody">
                    @forelse ($recentAdmissions as $adm)
                        <tr>
                            <td class="mono" style="font-size: 0.8rem; font-weight: 600; color: var(--mseuf-maroon);">
                                {{ $adm->admitted_at->format('h:i:s A') }}
                            </td>
                            <td><strong>{{ $adm->ticket?->registration?->user?->name ?? 'Attendee' }}</strong></td>
                            <td class="mono" style="font-size: 0.8rem;">{{ $adm->ticket?->registration?->user?->student_id ?? 'N/A' }}</td>
                            <td><span class="badge badge-info">{{ $adm->accessPoint?->name }}</span></td>
                            <td style="font-size: 0.8rem; color: var(--neutral-muted);">{{ $adm->scannedBy?->name ?? 'Staff' }}</td>
                        </tr>
                    @empty
                        <tr id="no-scans-row">
                            <td colspan="5" style="text-align: center; padding: 2rem; color: var(--neutral-muted);">
                                No admissions recorded yet. Turnstile scanners are ready.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Attendee Directory & Gate Verification Table -->
<div class="card" style="padding: 0; overflow: hidden;">
    <div style="padding: 1.25rem; border-bottom: 1px solid var(--neutral-border);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--neutral-dark);">Attendee Roster</h2>
                <p style="font-size: 0.8rem; color: var(--neutral-muted);">Complete registration roster and entrance admission timestamps</p>
            </div>

            <!-- Search and Filter Form -->
            <form method="GET" action="{{ route('organizer.attendance', $event) }}" style="display: flex; gap: 0.5rem; align-items: center;">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search student name, email, ID..." class="form-input" style="width: 240px; height: 36px;">

                <select name="status" class="form-select" style="width: 140px; height: 36px;">
                    <option value="">All Statuses</option>
                    <option value="checked_in" {{ request('status') === 'checked_in' ? 'selected' : '' }}>Checked In</option>
                    <option value="not_arrived" {{ request('status') === 'not_arrived' ? 'selected' : '' }}>Not Arrived</option>
                </select>

                <button type="submit" class="btn-primary" style="height: 36px; padding: 0 0.85rem; font-size: 0.8rem;">Filter</button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('organizer.attendance', $event) }}" class="btn-secondary" style="height: 36px; padding: 0 0.85rem; font-size: 0.8rem;">Clear</a>
                @endif
            </form>
        </div>
    </div>

    <div class="table-container" style="border: none;">
        <table>
            <thead>
                <tr>
                    <th>Student Name</th>
                    <th>Student ID</th>
                    <th>Email</th>
                    <th>Ticket Ref</th>
                    <th>Admission Status</th>
                    <th>Gate Used</th>
                    <th>Admitted At</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attendees as $reg)
                    @php
                        $ticket = $reg->ticket;
                        $admission = $ticket
                            ? $ticket->admissions->firstWhere('credential_version', $ticket->credential_version)
                            : null;
                        $isCheckedIn = (bool) $admission;
                    @endphp
                    <tr>
                        <td><strong>{{ $reg->user->name }}</strong></td>
                        <td class="mono" style="font-size: 0.8rem;">{{ $reg->user->student_id ?: 'N/A' }}</td>
                        <td style="font-size: 0.8rem; color: var(--neutral-muted);">{{ $reg->user->email }}</td>
                        <td class="mono" style="font-size: 0.8rem;">{{ $reg->ticket?->public_id ?? 'N/A' }}</td>
                        <td>
                            @if ($isCheckedIn)
                                <span class="badge badge-success">Admitted</span>
                            @else
                                <span class="badge badge-warning">Not Arrived</span>
                            @endif
                        </td>
                        <td>
                            @if ($isCheckedIn && $admission->accessPoint)
                                <span class="badge badge-info">{{ $admission->accessPoint->name }}</span>
                            @else
                                <span style="color: var(--neutral-muted); font-size: 0.8rem;">—</span>
                            @endif
                        </td>
                        <td class="mono" style="font-size: 0.8rem;">
                            @if ($isCheckedIn)
                                {{ $admission->admitted_at->format('M d, h:i:s A') }}
                            @else
                                <span style="color: var(--neutral-muted);">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem; color: var(--neutral-muted);">
                            No registered attendees match the filter criteria.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($attendees->hasPages())
        <div style="padding: 1rem 1.25rem; border-top: 1px solid var(--neutral-border);">
            {{ $attendees->links() }}
        </div>
    @endif
</div>

<script>
    const feedUrl = "{{ route('organizer.attendance.feed', $event) }}";

    async function fetchLiveData() {
        try {
            const res = await fetch(feedUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!res.ok) return;
            const data = await res.json();

            // Update KPIs
            document.getElementById('stat-capacity').innerText = data.stats.total_capacity;
            document.getElementById('stat-registered').innerText = data.stats.registered_count;
            document.getElementById('stat-checked-in').innerText = data.stats.checked_in_count;
            document.getElementById('stat-not-arrived').innerText = data.stats.not_arrived_count;
            document.getElementById('stat-percentage').innerText = data.stats.attendance_percentage + '%';
            document.getElementById('progress-bar').style.width = data.stats.attendance_percentage + '%';

            // Update Gate Breakdown
            const gateContainer = document.getElementById('gate-container');
            if (data.gate_stats && data.gate_stats.length > 0) {
                gateContainer.innerHTML = data.gate_stats.map(gate => `
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: #f8fafc; border: 1px solid var(--neutral-border); border-radius: 4px;">
                        <div>
                            <span class="mono" style="font-size: 0.75rem; font-weight: 700; background: #e2e8f0; padding: 0.15rem 0.4rem; border-radius: 3px; margin-right: 0.5rem;">\${gate.code}</span>
                            <strong style="font-size: 0.875rem;">\${gate.name}</strong>
                        </div>
                        <div class="mono" style="font-size: 1.1rem; font-weight: 700; color: var(--mseuf-maroon);">
                            \${gate.count}
                        </div>
                    </div>
                `).join('');
            }

            // Update Recent Admissions Feed
            const tbody = document.getElementById('recent-admissions-tbody');
            if (data.recent_admissions && data.recent_admissions.length > 0) {
                tbody.innerHTML = data.recent_admissions.map(adm => `
                    <tr>
                        <td class="mono" style="font-size: 0.8rem; font-weight: 600; color: var(--mseuf-maroon);">
                            \${adm.admitted_at}
                        </td>
                        <td><strong>\${adm.attendee_name}</strong></td>
                        <td class="mono" style="font-size: 0.8rem;">\${adm.student_id}</td>
                        <td><span class="badge badge-info">\${adm.access_point}</span></td>
                        <td style="font-size: 0.8rem; color: var(--neutral-muted);">\${adm.scanned_by}</td>
                    </tr>
                `).join('');
            }

            document.getElementById('feed-last-updated').innerText = 'Updated ' + new Date().toLocaleTimeString();
        } catch (e) {
            // Fail silent on packet drop
        }
    }

    // Auto-poll every 5 seconds for live LAN operation
    setInterval(fetchLiveData, 5000);
</script>
@endsection
