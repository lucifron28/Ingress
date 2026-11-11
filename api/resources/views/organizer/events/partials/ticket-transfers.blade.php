@if (auth()->user()->can('update', $event))
    @php
        $ticketTransfersEnabled = $event->ticket_transfers_allowed && $event->snapshot_prepared_at === null;
    @endphp
    <section id="event-transfers" class="card event-transfers" tabindex="-1" aria-labelledby="event-transfers-title">
        <header class="event-transfers__header">
            <div>
                <h2 id="event-transfers-title" class="event-section-title">Ticket Transfers</h2>
                @if ($event->snapshot_prepared_at)
                    <p class="event-transfers__description">Transfers are disabled after the event snapshot was prepared.</p>
                @elseif ($event->ticket_transfers_allowed)
                    <p class="event-transfers__description">Ticket transfers are enabled in event settings.</p>
                @else
                    <p class="event-transfers__description">Ticket transfers are disabled in event settings.</p>
                @endif
            </div>
            <span class="badge badge-neutral">{{ $ticketTransfersEnabled ? 'Enabled' : 'Disabled' }}</span>
        </header>

        @if ($ticketTransfers->total() === 0)
            <p class="event-transfers__empty">No transfer requests yet.</p>
        @else
            <p class="event-transfers__summary">
                {{ $ticketTransfers->total() }} transfer request{{ $ticketTransfers->total() === 1 ? '' : 's' }}
                @if ($pendingTicketTransferCount > 0)
                    <span class="badge badge-neutral">{{ $pendingTicketTransferCount }} pending</span>
                @endif
            </p>

            <details class="event-transfer-history" @if ($pendingTicketTransferCount > 0 || request()->boolean('transfer_history')) open @endif>
                <summary>View Ticket transfer history</summary>
                <div id="event-transfer-history" class="event-transfer-history__content">
                    <ol class="event-transfer-history__list">
                        @forelse ($ticketTransfers as $transfer)
                            <li class="event-transfer-item">
                                <div class="event-transfer-item__header">
                                    <p class="event-transfer-item__ticket">Ticket {{ $transfer->ticket_public_id }}</p>
                                    <span class="badge badge-neutral">{{ ucfirst($transfer->status) }}</span>
                                </div>
                                <dl class="event-transfer-item__details">
                                    <div class="event-transfer-item__field">
                                        <dt>From</dt>
                                        <dd>{{ $transfer->from_name }} <span class="event-transfer-item__email">({{ $transfer->from_email }})</span></dd>
                                    </div>
                                    <div class="event-transfer-item__field">
                                        <dt>To</dt>
                                        <dd>{{ $transfer->recipient_name }} <span class="event-transfer-item__email">({{ $transfer->recipient_email }})</span></dd>
                                    </div>
                                    <div class="event-transfer-item__field">
                                        <dt>Requested</dt>
                                        <dd><time datetime="{{ $transfer->requested_at->format('c') }}">{{ $transfer->requested_at->format('M d, Y h:i A') }}</time></dd>
                                    </div>
                                    @if ($transfer->accepted_at)
                                        <div class="event-transfer-item__field">
                                            <dt>Accepted</dt>
                                            <dd><time datetime="{{ $transfer->accepted_at->format('c') }}">{{ $transfer->accepted_at->format('M d, Y h:i A') }}</time></dd>
                                        </div>
                                    @endif
                                    @if ($transfer->declined_at)
                                        <div class="event-transfer-item__field">
                                            <dt>Declined</dt>
                                            <dd><time datetime="{{ $transfer->declined_at->format('c') }}">{{ $transfer->declined_at->format('M d, Y h:i A') }}</time></dd>
                                        </div>
                                    @endif
                                    @if ($transfer->cancelled_at)
                                        <div class="event-transfer-item__field">
                                            <dt>Cancelled</dt>
                                            <dd><time datetime="{{ $transfer->cancelled_at->format('c') }}">{{ $transfer->cancelled_at->format('M d, Y h:i A') }}</time></dd>
                                        </div>
                                    @endif
                                    @if ($transfer->expired_at)
                                        <div class="event-transfer-item__field">
                                            <dt>Expired</dt>
                                            <dd><time datetime="{{ $transfer->expired_at->format('c') }}">{{ $transfer->expired_at->format('M d, Y h:i A') }}</time></dd>
                                        </div>
                                    @endif
                                </dl>
                            </li>
                        @empty
                            <li class="event-transfer-history__empty">No transfer requests on this page.</li>
                        @endforelse
                    </ol>
                    {{ $ticketTransfers->appends(['transfer_history' => 1])->links() }}
                </div>
            </details>
        @endif
    </section>
@endif
