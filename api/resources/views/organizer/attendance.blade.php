@extends('layouts.app')

@section('content')
<header class="page-header page-header--editorial attendance-page-header">
    <x-page-header-watermark />
        <div>
            <div class="attendance-page-header__title-row">
                <h1 class="page-header__title">Event Attendance</h1>
                <span id="live-indicator" class="attendance-connection attendance-connection--checking" role="status" aria-live="polite">
                    <span id="live-indicator-dot" class="attendance-connection__dot"></span>
                    <span id="live-indicator-text">Checking for updates</span>
                </span>
            </div>
            <p class="page-header__subtitle">{{ $event->title }} <span aria-hidden="true">&middot;</span> {{ $event->venue->name }} <span aria-hidden="true">&middot;</span> {{ $event->starts_at->format('M j, Y g:i A') }}–{{ $event->ends_at->format('M j, Y g:i A') }}</p>
        </div>

        <div class="page-header__actions attendance-page-header__actions">
            <a href="{{ route('organizer.events.show', $event) }}" class="btn-secondary attendance-action-button">
                <x-icon name="eye" size="17" />
                <span>Back to Event</span>
            </a>
            <button type="button" onclick="fetchLiveData()" class="btn-primary attendance-action-button">
                <x-icon name="refresh-cw" size="17" />
                <span>Refresh Feed</span>
            </button>
        </div>
</header>

<!-- Core KPI Cards -->
<section class="attendance-stats-grid" aria-label="Attendance statistics">
    <div class="card attendance-stat attendance-stat--primary">
        <div class="attendance-stat__label">Inside Now</div>
        <div id="stat-inside" class="attendance-stat__value mono">{{ $stats['inside_count'] }}</div>
        <div class="attendance-stat__description">Latest movement is entry</div>
    </div>

    <div class="card attendance-stat attendance-stat--primary">
        <div class="attendance-stat__label">Registered</div>
        <div id="stat-registered" class="attendance-stat__value mono">{{ $stats['registered_count'] }}</div>
        <div class="attendance-stat__description">Confirmed tickets issued</div>
    </div>

    <div class="card attendance-stat attendance-stat--primary">
        <div class="attendance-stat__label">Attendance Rate</div>
        <div id="stat-percentage" class="attendance-stat__value mono">{{ $stats['attendance_percentage'] }}%</div>
        <div class="attendance-stat__progress" role="progressbar" aria-label="Attendance rate" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $stats['attendance_percentage'] }}">
            <span id="progress-bar" style="width: {{ $stats['attendance_percentage'] }}%;"></span>
        </div>
    </div>

    <div class="card attendance-stat attendance-stat--primary">
        <div class="attendance-stat__label">Ever Entered</div>
        <div id="stat-checked-in" class="attendance-stat__value mono">{{ $stats['checked_in_count'] }}</div>
        <div class="attendance-stat__description">Unique attendees with an entry</div>
    </div>

    <div class="attendance-secondary-stats" aria-label="Additional attendance statistics">
        <div><span>Total Capacity</span><strong id="stat-capacity" class="mono">{{ $stats['total_capacity'] }}</strong></div>
        <div><span>Outside Now</span><strong id="stat-outside" class="mono">{{ $stats['outside_count'] }}</strong></div>
        <div><span>Re-entries</span><strong id="stat-reentries" class="mono">{{ $stats['reentry_count'] }}</strong></div>
        <div><span>Envergans Inside</span><strong id="stat-envergan-inside" class="mono">{{ $stats['envergan_inside_count'] }}</strong></div>
        <div><span>External Guests Inside</span><strong id="stat-external-inside" class="mono">{{ $stats['external_inside_count'] }}</strong></div>
        <div><span>Not Arrived</span><strong id="stat-not-arrived" class="mono">{{ $stats['not_arrived_count'] }}</strong></div>
    </div>
</section>

<!-- Gate Breakdown & Recent Admissions Grid -->
<div class="attendance-overview-grid">
    <!-- Gate Admissions Breakdown -->
    <div class="card">
        <div class="attendance-section-heading">
            <h2>Movements by Access Point</h2>
        </div>

        <div id="gate-container" class="attendance-gate-list">
            @forelse ($gateStats as $gate)
                <div class="attendance-gate-row">
                    <div>
                        <span class="attendance-gate-row__code mono">{{ $gate['code'] }}</span>
                        <strong class="attendance-gate-row__name">{{ $gate['name'] }}</strong>
                    </div>
                    <div class="attendance-gate-row__count mono">
                        {{ $gate['count'] }}
                    </div>
                </div>
            @empty
                <p class="attendance-empty">No access points configured.</p>
            @endforelse
        </div>
    </div>

    <!-- Live Recent Admissions Feed -->
    <div class="card attendance-table-card">
        <div class="attendance-table__header">
            <h2 class="attendance-table__title">Recent Movements</h2>
            <span class="attendance-table__meta" id="feed-last-updated">Auto-refreshing</span>
        </div>

        <div class="table-container attendance-table-scroll" role="region" aria-label="Recent movements table" tabindex="0">
            <table>
                <caption class="visually-hidden">Recent event attendance movements</caption>
                <thead>
                    <tr>
                        <th scope="col">Time</th>
                        <th scope="col">Movement</th>
                        <th scope="col">Attendee Name</th>
                        <th scope="col">Student ID</th>
                        <th scope="col">Access Point</th>
                        <th scope="col">Scanned By</th>
                    </tr>
                </thead>
                <tbody id="recent-admissions-tbody">
                    @forelse ($recentAdmissions as $adm)
                        <tr>
                            <td class="mono" style="font-size: 0.8rem; font-weight: 600; color: var(--mseuf-maroon);">
                                {{ $adm->admitted_at->format('h:i:s A') }}
                            </td>
                            <td>{{ ucfirst($adm->direction) }}</td>
                            <td><strong>{{ $adm->ticket?->registration?->attendeeName() ?? 'Attendee' }}</strong></td>
                            <td class="mono" style="font-size: 0.8rem;">{{ $adm->ticket?->registration?->attendeeStudentId() ?? 'N/A' }}</td>
                            <td><span class="badge badge-info">{{ $adm->accessPoint?->name }}</span></td>
                            <td style="font-size: 0.8rem; color: var(--neutral-muted);">{{ $adm->scannedBy?->name ?? 'Staff' }}</td>
                        </tr>
                    @empty
                        <tr id="no-scans-row">
                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--neutral-muted);">
                                No movements recorded yet. Turnstile scanners are ready.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Attendee Directory & Gate Verification Table -->
<div class="card attendance-roster-card">
    <div class="attendance-roster__header">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h2 class="attendance-table__title">Attendee Roster</h2>
                <p class="attendance-table__description">Current attendee presence and last movement. Identity policy: {{ $event->identity_verification_level === 'id_check' ? 'approved ID check' : 'standard identity details' }}.</p>
            </div>

            <!-- Search and Filter Form -->
            <form method="GET" action="{{ route('organizer.attendance', $event) }}" class="attendance-roster__filters">
                <label class="visually-hidden" for="attendance-search">Search attendee roster</label>
                <div class="attendance-search-field">
                    <x-icon name="search" size="17" />
                    <input id="attendance-search" type="search" name="search" value="{{ request('search') }}" placeholder="Search name, email, or ID" class="form-input">
                </div>

                <label class="visually-hidden" for="attendance-status">Filter attendees by presence</label>
                <select id="attendance-status" name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="checked_in" {{ request('status') === 'checked_in' ? 'selected' : '' }}>Inside Now</option>
                    <option value="outside" {{ request('status') === 'outside' ? 'selected' : '' }}>Outside</option>
                    <option value="not_arrived" {{ request('status') === 'not_arrived' ? 'selected' : '' }}>Not Arrived</option>
                </select>

                <button type="submit" class="btn-primary attendance-action-button"><x-icon name="filter" size="17" /><span>Filter</span></button>
                @if (request()->hasAny(['search', 'status']))
                    <a href="{{ route('organizer.attendance', $event) }}" class="btn-secondary attendance-action-button">Clear</a>
                @endif
                <a href="{{ route('organizer.attendance.export', $event) }}" class="btn-secondary attendance-action-button"><x-icon name="download" size="17" /><span>Export CSV</span></a>
            </form>
        </div>
    </div>

    <div class="table-container attendance-table-scroll" role="region" aria-label="Attendee roster table" tabindex="0">
        <table>
            <caption class="visually-hidden">Attendee roster and current presence</caption>
            <thead>
                <tr>
                    <th scope="col">Attendee Name</th>
                    <th scope="col">Student ID</th>
                    <th scope="col">Email</th>
                    <th scope="col">Ticket Ref</th>
                    <th scope="col">Presence</th>
                    <th scope="col">Last Gate</th>
                    <th scope="col">Last Movement</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($attendees as $reg)
                    @php
                        $ticket = $reg->ticket;
                        $admission = $ticket?->latestAdmission;
                        $isInside = $admission?->direction === \App\Models\Admission::DIRECTION_ENTRY;
                        $isOutside = $admission?->direction === \App\Models\Admission::DIRECTION_EXIT;
                    @endphp
                    <tr>
                        <td><strong>{{ $reg->attendeeName() }}</strong></td>
                        <td class="mono" style="font-size: 0.8rem;">{{ $reg->attendeeStudentId() ?: 'N/A' }}</td>
                        <td style="font-size: 0.8rem; color: var(--neutral-muted);">{{ $reg->attendeeEmail() }}</td>
                        <td class="mono" style="font-size: 0.8rem;">{{ $reg->ticket?->public_id ?? 'N/A' }}</td>
                        <td>
                            @if ($isInside)
                                <span class="badge badge-success">Inside</span>
                            @elseif ($isOutside)
                                <span class="badge badge-info">Outside</span>
                            @else
                                <span class="badge badge-warning">Not Arrived</span>
                            @endif
                        </td>
                        <td>
                            @if ($admission && $admission->accessPoint)
                                <span class="badge badge-info">{{ $admission->accessPoint->name }}</span>
                            @else
                                <span style="color: var(--neutral-muted); font-size: 0.8rem;">—</span>
                            @endif
                        </td>
                        <td class="mono" style="font-size: 0.8rem;">
                            @if ($admission)
                                {{ ucfirst($admission->direction) }} at {{ $admission->admitted_at->format('M d, h:i:s A') }}
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
    const requestTimeoutMs = typeof INGRESS_POLL_TIMEOUT_MS !== 'undefined' ? INGRESS_POLL_TIMEOUT_MS : 4500;
    let lastSuccessfulUpdate = null;
    let isFetching = false;
    function setConnectionLive() {
        const indicator = document.getElementById('live-indicator');
        if (indicator) {
            indicator.classList.remove('attendance-connection--lost');
            indicator.classList.remove('attendance-connection--checking');
            indicator.classList.add('attendance-connection--live');
        }
        const text = document.getElementById('live-indicator-text');
        if (text) text.textContent = 'Updates live';
    }

    function setConnectionLost() {
        const indicator = document.getElementById('live-indicator');
        if (indicator) {
            indicator.classList.remove('attendance-connection--live');
            indicator.classList.remove('attendance-connection--checking');
            indicator.classList.add('attendance-connection--lost');
        }
        const text = document.getElementById('live-indicator-text');
        if (text) text.textContent = 'Updates unavailable';

        const lastUpdatedEl = document.getElementById('feed-last-updated');
        if (lastUpdatedEl) {
            lastUpdatedEl.textContent = lastSuccessfulUpdate
                ? 'Last successful update ' + lastSuccessfulUpdate
                : 'Connection lost';
        }
    }

    async function fetchLiveData() {
        if (isFetching) return;
        isFetching = true;

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), requestTimeoutMs);
        try {
            const res = await fetch(feedUrl, {
                signal: controller.signal,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!res.ok) {
                setConnectionLost();
                return;
            }

            const data = await res.json();

            // Update KPIs
            const statCapacity = document.getElementById('stat-capacity');
            if (statCapacity) statCapacity.textContent = data.stats.total_capacity;

            const statRegistered = document.getElementById('stat-registered');
            if (statRegistered) statRegistered.textContent = data.stats.registered_count;

            const statCheckedIn = document.getElementById('stat-checked-in');
            if (statCheckedIn) statCheckedIn.textContent = data.stats.checked_in_count;

            const statInside = document.getElementById('stat-inside');
            if (statInside) statInside.textContent = data.stats.inside_count;

            const statOutside = document.getElementById('stat-outside');
            if (statOutside) statOutside.textContent = data.stats.outside_count;

            const statReentries = document.getElementById('stat-reentries');
            if (statReentries) statReentries.textContent = data.stats.reentry_count;

            const statEnverganInside = document.getElementById('stat-envergan-inside');
            if (statEnverganInside) statEnverganInside.textContent = data.stats.envergan_inside_count;

            const statExternalInside = document.getElementById('stat-external-inside');
            if (statExternalInside) statExternalInside.textContent = data.stats.external_inside_count;

            const statNotArrived = document.getElementById('stat-not-arrived');
            if (statNotArrived) statNotArrived.textContent = data.stats.not_arrived_count;

            const statPercentage = document.getElementById('stat-percentage');
            if (statPercentage) statPercentage.textContent = data.stats.attendance_percentage + '%';

            const progressBar = document.getElementById('progress-bar');
            if (progressBar) {
                progressBar.style.width = data.stats.attendance_percentage + '%';
                progressBar.parentElement?.setAttribute('aria-valuenow', data.stats.attendance_percentage);
            }

            // Update Gate Breakdown safely using DOM APIs
            const gateContainer = document.getElementById('gate-container');
            if (gateContainer && data.gate_stats) {
                gateContainer.textContent = '';
                if (data.gate_stats.length === 0) {
                    const emptyP = document.createElement('p');
                    emptyP.className = 'attendance-empty';
                    emptyP.textContent = 'No access points configured.';
                    gateContainer.appendChild(emptyP);
                } else {
                    data.gate_stats.forEach(gate => {
                        const item = document.createElement('div');
                        item.className = 'attendance-gate-row';

                        const left = document.createElement('div');

                        const codeSpan = document.createElement('span');
                        codeSpan.className = 'attendance-gate-row__code mono';
                        codeSpan.textContent = gate.code;

                        const nameStrong = document.createElement('strong');
                        nameStrong.className = 'attendance-gate-row__name';
                        nameStrong.textContent = gate.name;

                        left.appendChild(codeSpan);
                        left.appendChild(nameStrong);

                        const countDiv = document.createElement('div');
                        countDiv.className = 'attendance-gate-row__count mono';
                        countDiv.textContent = gate.count;

                        item.appendChild(left);
                        item.appendChild(countDiv);
                        gateContainer.appendChild(item);
                    });
                }
            }

            // Update Recent Admissions Feed safely using DOM APIs
            const tbody = document.getElementById('recent-admissions-tbody');
            if (tbody && data.recent_admissions) {
                tbody.textContent = '';
                if (data.recent_admissions.length === 0) {
                    const emptyTr = document.createElement('tr');
                    emptyTr.id = 'no-scans-row';
                    const emptyTd = document.createElement('td');
                    emptyTd.colSpan = 6;
                    emptyTd.style.textAlign = 'center';
                    emptyTd.style.padding = '2rem';
                    emptyTd.style.color = 'var(--neutral-muted)';
                    emptyTd.textContent = 'No movements recorded yet. Turnstile scanners are ready.';
                    emptyTr.appendChild(emptyTd);
                    tbody.appendChild(emptyTr);
                } else {
                    data.recent_admissions.forEach(adm => {
                        const tr = document.createElement('tr');

                        const tdTime = document.createElement('td');
                        tdTime.className = 'mono';
                        tdTime.style.fontSize = '0.8rem';
                        tdTime.style.fontWeight = '600';
                        tdTime.style.color = 'var(--mseuf-maroon)';
                        tdTime.textContent = adm.admitted_at;

                        const tdDirection = document.createElement('td');
                        const direction = adm.direction === 'exit' ? 'Exit' : 'Entry';
                        tdDirection.textContent = direction;

                        const tdName = document.createElement('td');
                        const strongName = document.createElement('strong');
                        strongName.textContent = adm.attendee_name;
                        tdName.appendChild(strongName);

                        const tdId = document.createElement('td');
                        tdId.className = 'mono';
                        tdId.style.fontSize = '0.8rem';
                        tdId.textContent = adm.student_id;

                        const tdGate = document.createElement('td');
                        const gateBadge = document.createElement('span');
                        gateBadge.className = 'badge badge-info';
                        gateBadge.textContent = adm.access_point;
                        tdGate.appendChild(gateBadge);

                        const tdStaff = document.createElement('td');
                        tdStaff.style.fontSize = '0.8rem';
                        tdStaff.style.color = 'var(--neutral-muted)';
                        tdStaff.textContent = adm.scanned_by;

                        tr.appendChild(tdTime);
                        tr.appendChild(tdDirection);
                        tr.appendChild(tdName);
                        tr.appendChild(tdId);
                        tr.appendChild(tdGate);
                        tr.appendChild(tdStaff);

                        tbody.appendChild(tr);
                    });
                }
            }

            lastSuccessfulUpdate = new Date().toLocaleTimeString();
            const lastUpdatedEl = document.getElementById('feed-last-updated');
            if (lastUpdatedEl) {
                lastUpdatedEl.textContent = 'Updated ' + lastSuccessfulUpdate;
            }
            setConnectionLive();
        } catch (e) {
            setConnectionLost();
        } finally {
            clearTimeout(timeoutId);
            isFetching = false;
        }
    }

    // Keep the live feed current during event operations.
    setInterval(fetchLiveData, 5000);
</script>
@endsection
