<x-layouts.admin>
    <x-slot name="title">{{ trans('banking-connectors::general.name') }}</x-slot>
    <x-slot name="content">
        <x-index.container>
            <section class="mb-6">
                @if ($accountContext !== null)
                    <x-link
                        href="{{ route('accounts.show', $accountContext->id) }}"
                        class="text-sm text-purple hover:underline mb-3 inline-block"
                        override="class"
                        id="banking-connectors-back-to-account"
                    >{{ trans('banking-connectors::general.back_to_account') }}</x-link>
                @endif
                <h1 class="text-xl font-semibold text-black">{{ trans('banking-connectors::general.name') }}</h1>
                @if ($accountContext !== null)
                    <p class="mt-1 text-base font-medium text-gray-800" id="banking-connectors-account-context">
                        {{ trans('banking-connectors::general.account_context', ['account' => $accountContext->name]) }}
                    </p>
                @endif
                <p class="mt-2 text-sm text-gray-600">{{ trans('banking-connectors::general.intro') }}</p>
            </section>

            <div class="mb-5 rounded-xl bg-yellow-50 border border-yellow-200 px-5 py-4 text-sm text-yellow-900" role="status">
                {{ trans('banking-connectors::general.read_only') }}
            </div>
            @if (session('success'))
                <div class="mb-5 rounded-xl bg-green-50 border border-green-200 px-5 py-3 text-sm text-green-800" role="status">{{ session('success') }}</div>
            @endif
            @if (isset($errors) && $errors->any())
                <div class="mb-5 rounded-xl bg-red-50 border border-red-200 px-5 py-3 text-sm text-red-800" role="alert">{{ trans('banking-connectors::general.review_failed') }}</div>
            @endif

            @if ($canManage && $availableAccounts->isNotEmpty() && ($accountContext === null || $connections->isEmpty()))
                <section class="rounded-xl border bg-white p-5 mb-6" aria-label="{{ trans('banking-connectors::general.new_connection') }}">
                    <h2 class="text-base font-semibold mb-3">{{ trans('banking-connectors::general.new_connection') }}</h2>
                    <form method="POST" action="{{ route('banking-connectors.connections.create', ['company_id' => company_id()]) }}" class="flex flex-col sm:flex-row gap-3 items-end">
                        @csrf
                        <label class="flex-1 w-full">
                            <span class="block text-sm text-gray-600 mb-2">{{ trans('banking-connectors::general.provider') }}</span>
                            <select name="provider" required class="w-full border rounded-md px-3 py-2">
                                @foreach ($availableProviders as $providerId)
                                    <option value="{{ $providerId }}">{{ strtoupper($providerId) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex-1 w-full">
                            <span class="block text-sm text-gray-600 mb-2">{{ trans('banking-connectors::general.account') }}</span>
                            <select name="account_id" required class="w-full border rounded-md px-3 py-2">
                                @foreach ($availableAccounts as $account)
                                    <option value="{{ $account->id }}" {{ $accountContext !== null && (int) $accountContext->id === (int) $account->id ? 'selected' : '' }}>{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="bg-purple text-white rounded-md px-4 py-2">{{ trans('banking-connectors::general.add_paused') }}</button>
                    </form>
                    <p class="mt-2 text-xs text-gray-600">{{ trans('banking-connectors::general.credentials_external') }}</p>
                </section>
            @endif

            @if ($connections->isEmpty())
                <div class="border rounded-xl bg-white px-6 py-10 text-gray-600">{{ trans('banking-connectors::general.no_connections') }}</div>
            @else
                @if ($accountContext === null || $connections->count() > 1)
                <form method="GET" class="mb-6 rounded-xl border bg-white px-5 py-4 flex items-end gap-4" action="{{ $accountContext !== null
                    ? route('banking-connectors.account.show', ['company_id' => company_id(), 'accountId' => $accountContext->id])
                    : route('banking-connectors.review.index', ['company_id' => company_id()]) }}">
                    <label class="flex-1">
                        <span class="block text-sm mb-2 text-gray-600">{{ trans('banking-connectors::general.account') }}</span>
                        <select class="w-full border rounded-md px-3 py-2" name="connection_id">
                            @foreach ($connections as $connection)
                                <option value="{{ $connection->id }}" {{ $selected && $selected->id === $connection->id ? 'selected' : '' }}>
                                    {{ $connection->account_name }} ({{ strtoupper($connection->provider) }})
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="bg-purple text-white rounded-md py-2 px-5">{{ trans('banking-connectors::general.show') }}</button>
                </form>
                @endif

                @if ($selected !== null)
                    <p class="text-sm text-gray-600 mb-4">
                        {{ trans('banking-connectors::general.connection_state') }}: {{ $selected->state }}
                        @if ($selected->last_success_at)
                            · {{ trans('banking-connectors::general.last_success') }}: {{ $selected->last_success_at }}
                        @endif
                    </p>

                    @if ($selected->last_error_code)
                        <p class="text-sm text-amber-800 mb-3" role="status">
                            {{ trans('banking-connectors::general.last_error') }}: {{ $selected->last_error_code }}
                        </p>
                    @endif
                    @if ($selected->next_retry_at)
                        <p class="text-xs text-gray-600 mb-3">{{ trans('banking-connectors::general.next_retry') }}: {{ $selected->next_retry_at }}</p>
                    @endif
                    @if ($selected->poll_requested_at)
                        <p class="text-sm text-gray-700 mb-3" role="status">{{ trans('banking-connectors::general.pending_request') }}</p>
                    @endif

                    @if (in_array($selected->provider, $availableProviders, true) && $canManage)
                        <section class="rounded-xl border bg-white p-5 mb-6">
                            <h2 class="text-base font-semibold mb-3">{{ trans('banking-connectors::general.controls') }}</h2>
                            <form class="flex flex-col sm:flex-row items-end gap-3 mb-4" method="POST" action="{{ route('banking-connectors.connections.state', ['company_id' => company_id(), 'connectionId' => $selected->id]) }}">
                                @csrf
                                <label class="flex-1">
                                    <span class="block text-sm text-gray-600 mb-2">{{ trans('banking-connectors::general.connection_state') }}</span>
                                    <select class="w-full border rounded-md px-3 py-2" name="state">
                                        <option value="paused" {{ $selected->state === 'paused' ? 'selected' : '' }}>{{ trans('banking-connectors::general.state_paused') }}</option>
                                        <option value="read_only" {{ $selected->state === 'read_only' ? 'selected' : '' }}>{{ trans('banking-connectors::general.state_read_only') }}</option>
                                        @if ($selected->cursor_date && $selected->last_success_at && !$selected->last_error_code && $selected->fingerprint_key_commitment)
                                            <option value="enabled" {{ $selected->state === 'enabled' ? 'selected' : '' }}>{{ trans('banking-connectors::general.state_enabled') }}</option>
                                        @endif
                                    </select>
                                </label>
                                <button class="border rounded-md px-4 py-2" type="submit">{{ trans('banking-connectors::general.save_state') }}</button>
                            </form>

                            @if ($canRequest && in_array($selected->state, ['read_only', 'enabled'], true))
                                <form class="flex flex-col sm:flex-row items-end gap-3" method="POST" action="{{ route('banking-connectors.connections.request', ['company_id' => company_id(), 'connectionId' => $selected->id]) }}">
                                    @csrf
                                    <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                    <label class="flex-1">
                                        <span class="block text-sm text-gray-600 mb-2">{{ trans('banking-connectors::general.start_date') }}</span>
                                        <input class="w-full border rounded-md px-3 py-2" type="date" name="start_date" max="{{ now()->timezone('America/Sao_Paulo')->format('Y-m-d') }}" {{ $selected->cursor_date ? '' : 'required' }}>
                                    </label>
                                    <button type="submit" class="bg-purple text-white rounded-md px-4 py-2" {{ $selected->poll_requested_at ? 'disabled' : '' }}>
                                        {{ trans('banking-connectors::general.sync_now') }}
                                    </button>
                                </form>
                                <p class="mt-2 text-xs text-gray-600">{{ trans('banking-connectors::general.sync_async_note') }}</p>
                            @endif
                        </section>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                        @foreach (['suggested','needs_review','unmatched'] as $status)
                            <div class="rounded-xl border bg-white p-4">
                                <div class="text-gray-600 text-sm">{{ trans('banking-connectors::general.status.' . $status) }}</div>
                                <div class="font-semibold text-2xl mt-2">{{ $summary[$status] ?? 0 }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="overflow-x-auto bg-white rounded-xl border">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 border-b text-gray-600">
                                <tr>
                                    <th class="px-4 py-3">{{ trans('banking-connectors::general.date') }}</th>
                                    <th class="px-4 py-3">{{ trans('banking-connectors::general.direction') }}</th>
                                    <th class="px-4 py-3">{{ trans('banking-connectors::general.amount') }}</th>
                                    <th class="px-4 py-3">{{ trans('banking-connectors::general.result') }}</th>
                                    <th class="px-4 py-3">{{ trans('banking-connectors::general.candidates') }}</th>
                                    @if ($canReview)
                                        <th class="px-4 py-3">{{ trans('banking-connectors::general.review_action') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($items as $row)
                                    <tr class="border-b last:border-0">
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $row['date'] }}</td>
                                        <td class="px-4 py-3">{{ trans('banking-connectors::general.movement.' . $row['direction']) }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            {{ $row['currency'] }}
                                            {{ substr(str_pad((string) $row['amount_cents'], 3, '0', STR_PAD_LEFT), 0, -2) }},{{ substr(str_pad((string) $row['amount_cents'], 3, '0', STR_PAD_LEFT), -2) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="font-medium">{{ trans('banking-connectors::general.status.' . $row['status']) }}</span>
                                            <span class="block text-xs text-gray-500">{{ trans('banking-connectors::general.reason.' . $row['reason']) }}</span>
                                        </td>
                                        <td class="px-4 py-3">
                                            @if ($row['candidate_ids'] === [])
                                                —
                                            @else
                                                {{ implode(', ', $row['candidate_ids']) }}
                                            @endif
                                        </td>
                                        @if ($canReview)
                                            <td class="px-4 py-3">
                                                @if ($row['stage_state'] === 'pending')
                                                    <div class="flex flex-col gap-2 min-w-48">
                                                        @foreach ($row['candidate_ids'] as $candidateId)
                                                            <form method="POST" action="{{ route('banking-connectors.review.decide', ['company_id' => company_id()]) }}">
                                                                @csrf
                                                                <input type="hidden" name="connection_id" value="{{ $selected->id }}">
                                                                <input type="hidden" name="entry_id" value="{{ $row['id'] }}">
                                                                <input type="hidden" name="native_transaction_id" value="{{ $candidateId }}">
                                                                <input type="hidden" name="operation" value="accept">
                                                                <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                                                <button type="submit" class="text-purple hover:underline text-left">{{ trans('banking-connectors::general.accept_candidate', ['id' => $candidateId]) }}</button>
                                                            </form>
                                                        @endforeach
                                                        <form method="POST" action="{{ route('banking-connectors.review.decide', ['company_id' => company_id()]) }}"
                                                            onsubmit="return confirm(this.dataset.confirm)"
                                                            data-confirm="{{ trans('banking-connectors::general.dismiss_confirm') }}">
                                                            @csrf
                                                            <input type="hidden" name="connection_id" value="{{ $selected->id }}">
                                                            <input type="hidden" name="entry_id" value="{{ $row['id'] }}">
                                                            <input type="hidden" name="operation" value="dismiss">
                                                            <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                                            <button type="submit" class="text-gray-600 hover:underline text-left">{{ trans('banking-connectors::general.dismiss_entry') }}</button>
                                                        </form>
                                                    </div>
                                                @else
                                                    <span class="text-xs text-amber-700">{{ trans('banking-connectors::general.resolve_conflict_first') }}</span>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td class="p-8 text-center text-gray-500" colspan="{{ $canReview ? 6 : 5 }}">{{ trans('banking-connectors::general.no_pending') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-4 text-xs text-gray-500">{{ trans('banking-connectors::general.preview_limit') }}</p>
                @endif
            @endif
        </x-index.container>
    </x-slot>
</x-layouts.admin>
