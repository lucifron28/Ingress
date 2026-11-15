<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Student account · Ingress</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color: #17202b; background: #f5f6f8; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; padding: 24px; }
        main { width: min(100%, 720px); margin: 8vh auto; }
        header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
        .brand { margin: 0; color: #800000; font-size: .86rem; font-weight: 750; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0 0 8px; color: #171b22; font-size: 1.7rem; }
        .card { padding: clamp(22px, 6vw, 36px); border: 1px solid #dfe3e8; border-radius: 14px; background: #fff; box-shadow: 0 18px 50px rgb(24 32 43 / 8%); }
        p { color: #596575; line-height: 1.65; }
        .email { color: #273243; font-weight: 650; }
        button { padding: 10px 14px; border: 1px solid #670000; border-radius: 7px; color: #fff; background: #800000; font: inherit; font-weight: 700; cursor: pointer; }
        a { color: #800000; font-weight: 650; }
    </style>
</head>
<body>
    <main>
        <header>
            <p class="brand">Ingress · MSEUF</p>
            <form method="post" action="{{ route('student.logout') }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        </header>
        <section class="card" aria-labelledby="student-title">
            <h1 id="student-title">Your student account is ready</h1>
            <p class="email">{{ $user->email }}</p>
            <p>Your email is verified. Browse events, register for eligible tickets, and return here to open or download your current tickets.</p>
            <p>Email verification confirms mailbox ownership. It does not confirm current enrollment.</p>
            <p><a href="{{ route('public.events.index') }}">Browse events</a></p>
            @if ($user->hasEventManagementAccess())
                <p><a href="{{ route('organizer.dashboard') }}">Manage assigned events</a></p>
            @endif
            <a href="{{ route('login') }}">Organizer sign in</a>
        </section>

        <section class="card" style="margin-top: 18px;" aria-labelledby="tickets-title">
            <h2 id="tickets-title">Your tickets</h2>
            @forelse ($registrations as $registration)
                @php
                    $ticketIsActive = $registration->status === \App\Models\Registration::STATUS_CONFIRMED
                        && $registration->ticket?->isActive();
                @endphp
                <article style="border-top: 1px solid #dfe3e8; padding: 16px 0;">
                    <h3>{{ $registration->event->title }}</h3>
                    <p>{{ \App\Support\EventSchedule::local($registration->event->starts_at)->format('M j, Y · g:i A') }} PHT · {{ $registration->event->venue->name }}</p>
                    <p>Category: {{ $registration->ticketCategory?->name ?? 'General Admission' }} · Ticket status: {{ $ticketIsActive ? 'Active' : ucfirst($registration->status === \App\Models\Registration::STATUS_CANCELLED ? 'cancelled' : ($registration->ticket?->status ?? 'unavailable')) }}</p>
                    @if ($ticketIsActive)
                        <p><a href="{{ route('public.tickets.show', $registration) }}">View ticket</a> · <a href="{{ route('public.tickets.pdf', $registration) }}">Download PDF</a></p>
                    @endif
                </article>
            @empty
                <p>You do not have any account tickets yet. Public guest tickets are available from the event page without signing in.</p>
            @endforelse
        </section>
    </main>
</body>
</html>
