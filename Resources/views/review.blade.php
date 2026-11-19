<x-layouts.admin>
    <x-slot name="title">{{ trans('banking-connectors::general.name') }}</x-slot>
    <x-slot name="content">
        @php
            $routeName = $accountContext !== null ? 'banking-connectors.account.show' : 'banking-connectors.review.index';
            $baseParams = $accountContext !== null
                ? ['company_id' => company_id(), 'accountId' => $accountContext->id]
                : ['company_id' => company_id()];
            $panelUrl = fn ($panel, $query = []) => route($routeName, array_merge($baseParams, ['tab' => $panel], $query));
        @endphp
        <x-index.container>
            <div class="max-w-6xl mx-auto space-y-6">
                <header class="rounded-xl bg-white border px-5 py-5 sm:px-7">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            @if ($accountContext !== null)
                                <a href="{{ route('accounts.show', $accountContext->id) }}"
                                   class="text-sm text-purple hover:underline" id="banking-connectors-back-to-account">
                                    &larr; {{ trans('banking-connectors::general.back_to_account') }}
                                </a>
                            @endif
                            <h1 class="text-2xl font-semibold text-black mt-2">{{ trans('banking-connectors::general.name') }}</h1>
                            @if ($accountContext !== null)
                                <p class="mt-1 text-base font-medium text-gray-700" id="banking-connectors-account-context">
                                    {{ trans('banking-connectors::general.account_context', ['account' => $accountContext->name]) }}
                                </p>
                            @endif
                            <p class="mt-2 text-sm text-gray-600">{{ trans('banking-connectors::general.intro') }}</p>
                        </div>
                        @if ($selected !== null)
                            <div class="rounded-lg px-4 py-3 bg-gray-50 border min-w-48 text-sm" aria-label="{{ trans('banking-connectors::general.connection_state') }}">
                                <div class="text-gray-500">{{ trans('banking-connectors::general.provider') }}: {{ strtoupper($selected->provider) }}</div>
                                <div class="font-medium mt-1">{{ trans('banking-connectors::general.connection_state') }}:
                                    {{ trans('banking-connectors::general.state_' . $selected->state) }}
                                </div>
                                <div class="text-gray-500 mt-1">{{ trans('banking-connectors::general.last_success') }}:
                                    {{ $selected->last_success_at ?? '—' }}
                                </div>
                            </div>
                        @endif
                    </div>
                    <nav class="flex flex-wrap gap-2 mt-6 border-t pt-4" aria-label="{{ trans('banking-connectors::general.navigation_label') }}">
                        <a href="{{ $panelUrl('review') }}"
                           id="banking-connectors-tab-review" @if($tab === 'review') aria-current="page" @endif
                           class="rounded-lg px-4 py-2 text-sm border {{ $tab === 'review' ? 'bg-purple text-white border-purple' : 'bg-white text-purple hover:bg-gray-50' }}">
                            {{ trans('banking-connectors::general.tab_review') }}
                            @if ($selected) · {{ $stateCounts['pending'] }} @endif
                        </a>
                        <a href="{{ $panelUrl('settings') }}"
                           id="banking-connectors-tab-settings" @if($tab === 'settings') aria-current="page" @endif
                           class="rounded-lg px-4 py-2 text-sm border {{ $tab === 'settings' ? 'bg-purple text-white border-purple' : 'bg-white text-purple hover:bg-gray-50' }}">
                            {{ trans('banking-connectors::general.tab_settings') }}
                        </a>
                        <a href="{{ $panelUrl('rules') }}"
                           id="banking-connectors-tab-rules" @if($tab === 'rules') aria-current="page" @endif
                           class="rounded-lg px-4 py-2 text-sm border {{ $tab === 'rules' ? 'bg-purple text-white border-purple' : 'bg-white text-purple hover:bg-gray-50' }}">
                            {{ trans('banking-connectors::general.tab_rules') }}
                        </a>
                    </nav>
                </header>

                @if (session('success'))
                    <div class="border border-green-200 bg-green-50 text-green-900 rounded-xl px-5 py-3 text-sm" role="status">
                        {{ session('success') }}
                    </div>
                @endif
                @if (isset($errors) && $errors->any())
                    <div class="border border-red-200 bg-red-50 text-red-900 rounded-xl px-5 py-3 text-sm" role="alert">
                        {{ trans('banking-connectors::general.review_failed') }}
                        <span class="block mt-1">{{ $errors->first() }}</span>
                    </div>
                @endif

                @if ($accountContext === null && $connections->isNotEmpty())
                    <form method="GET" action="{{ $panelUrl($tab) }}" class="rounded-xl border bg-white p-4 flex flex-wrap items-end gap-3">
                        <input type="hidden" name="tab" value="{{ $tab }}">
                        <label class="flex-1 min-w-48 text-sm">
                            <span class="block text-gray-600 mb-2">{{ trans('banking-connectors::general.account') }}</span>
                            <select class="w-full border rounded-lg px-3 py-2" name="connection_id">
                                @foreach ($connections as $connection)
                                    <option value="{{ $connection->id }}" {{ $selected && $selected->id === $connection->id ? 'selected' : '' }}>
                                        {{ $connection->account_name }} — {{ strtoupper($connection->provider) }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="bg-purple text-white rounded-lg px-4 py-2 text-sm">
                            {{ trans('banking-connectors::general.show') }}
                        </button>
                    </form>
                @endif

                @if ($tab === 'settings')
                    <section class="rounded-xl border bg-white p-5 sm:p-7" aria-labelledby="bank-connection-settings-title">
                        <h2 id="bank-connection-settings-title" class="font-semibold text-lg">
                            {{ trans('banking-connectors::general.settings_heading') }}
                        </h2>
                        <p class="text-sm text-gray-600 mt-1">{{ trans('banking-connectors::general.settings_intro') }}</p>

                        @if ($selected === null && $canManage && $availableAccounts->isNotEmpty())
                            <form method="POST" action="{{ route('banking-connectors.connections.create', ['company_id' => company_id()]) }}"
                                  class="grid gap-4 grid-cols-1 sm:grid-cols-3 mt-5 items-end">
                                @csrf
                                <label class="text-sm">
                                    <span class="block mb-2">{{ trans('banking-connectors::general.account') }}</span>
                                    <select name="account_id" class="w-full border rounded-lg px-3 py-2" required>
                                        @foreach ($availableAccounts as $account)
                                            <option value="{{ $account->id }}" {{ $accountContext !== null && $accountContext->id === $account->id ? 'selected' : '' }}>
                                                {{ $account->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="text-sm">
                                    <span class="block mb-2">{{ trans('banking-connectors::general.provider') }}</span>
                                    <select name="provider" class="w-full border rounded-lg px-3 py-2" required>
                                        @foreach ($availableProviders as $provider)
                                            <option value="{{ $provider }}">{{ strtoupper($provider) }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <button type="submit" class="bg-purple text-white px-4 py-2 rounded-lg">
                                    {{ trans('banking-connectors::general.create_connection') }}
                                </button>
                            </form>
                        @elseif ($selected === null)
                            <p class="mt-4 text-sm">{{ trans('banking-connectors::general.no_connections') }}</p>
                        @else
                            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-5 text-sm">
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <dt class="text-gray-500">{{ trans('banking-connectors::general.account') }}</dt>
                                    <dd class="font-medium mt-1">{{ $selected->account_name }}</dd>
                                </div>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <dt class="text-gray-500">{{ trans('banking-connectors::general.provider') }}</dt>
                                    <dd class="font-medium mt-1">{{ strtoupper($selected->provider) }}</dd>
                                </div>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <dt class="text-gray-500">{{ trans('banking-connectors::general.credentials_status') }}</dt>
                                    <dd class="font-medium mt-1">{{ $selected->credentials_checked_at && $selected->fingerprint_key_commitment
                                        && $selected->certificate_valid_to
                                        && strtotime($selected->certificate_valid_to) > time()
                                        && !$selected->last_error_code
                                        ? trans('banking-connectors::general.credentials_verified')
                                        : trans('banking-connectors::general.credentials_pending') }}</dd>
                                    <p class="text-xs text-gray-500 mt-1">{{ $selected->credentials_checked_at ?? '—' }}</p>
                                </div>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <dt class="text-gray-500">{{ trans('banking-connectors::general.certificate_expires') }}</dt>
                                    <dd class="font-medium mt-1">{{ $selected->certificate_valid_to ?? '—' }}</dd>
                                </div>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <dt class="text-gray-500">{{ trans('banking-connectors::general.last_success') }}</dt>
                                    <dd class="font-medium mt-1">{{ $selected->last_success_at ?? '—' }}</dd>
                                </div>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <dt class="text-gray-500">{{ trans('banking-connectors::general.last_error') }}</dt>
                                    <dd class="font-medium mt-1">{{ $selected->last_error_code ?? '—' }}</dd>
                                </div>
                            </dl>
                            @if ($canManage)
                                <form method="POST" class="flex flex-wrap gap-3 items-end mt-6"
                                      action="{{ route('banking-connectors.connections.state', ['company_id'=>company_id(),'connectionId'=>$selected->id]) }}">
                                    @csrf
                                    <label class="text-sm w-full sm:w-80">
                                        <span class="block mb-2">{{ trans('banking-connectors::general.connection_state') }}</span>
                                        <select class="border rounded-lg w-full px-3 py-2" name="state">
                                            <option value="paused" {{ $selected->state === 'paused' ? 'selected' : '' }}>{{ trans('banking-connectors::general.state_paused') }}</option>
                                            <option value="read_only" {{ $selected->state === 'read_only' ? 'selected' : '' }}>{{ trans('banking-connectors::general.state_read_only') }}</option>
                                            @if ($selected->cursor_date && $selected->last_success_at && !$selected->last_error_code && $selected->fingerprint_key_commitment)
                                                <option value="enabled" {{ $selected->state === 'enabled' ? 'selected' : '' }}>{{ trans('banking-connectors::general.state_enabled') }}</option>
                                            @endif
                                        </select>
                                    </label>
                                    <button type="submit" class="bg-purple text-white rounded-lg px-5 py-2">
                                        {{ trans('banking-connectors::general.save_state') }}
                                    </button>
                                </form>
                            @endif
                            <p class="mt-5 p-4 bg-blue-50 rounded-lg text-sm text-blue-900">
                                {{ trans('banking-connectors::general.credentials_external') }}
                            </p>
                        @endif
                    </section>
                @elseif ($tab === 'rules')
                    <section class="rounded-xl border bg-white p-5 sm:p-7" aria-labelledby="bank-classification-rules-title">
                        <h2 class="font-semibold text-lg" id="bank-classification-rules-title">
                            {{ trans('banking-connectors::general.rules_heading') }}
                        </h2>
                        <p class="text-sm text-gray-600 mt-2">{{ trans('banking-connectors::general.rules_intro') }}</p>
                        @if ($selected !== null && $canManage)
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-5">
                                @foreach (['debit','credit'] as $direction)
                                    @php
                                        $directionCategories = $categories->where('type', $direction === 'credit' ? 'income' : 'expense');
                                    @endphp
                                    <form method="POST"
                                          action="{{ route('banking-connectors.account.rule.create', ['company_id'=>company_id(),'accountId'=>$selected->account_id]) }}"
                                          class="p-5 rounded-xl border bg-gray-50 space-y-4">
                                        @csrf
                                        <input type="hidden" name="direction" value="{{ $direction }}">
                                        <h3 class="font-semibold text-base">{{ trans('banking-connectors::general.rule_for_' . $direction) }}</h3>
                                        <label class="text-sm block">
                                            <span class="block mb-2">{{ trans('banking-connectors::general.rule_contains') }}</span>
                                            <input name="description_contains" type="text" minlength="4" maxlength="120"
                                                   required placeholder="{{ trans('banking-connectors::general.rule_example') }}"
                                                   class="border rounded-lg px-3 py-2 w-full">
                                        </label>
                                        <label class="text-sm block">
                                            <span class="block mb-2">{{ trans('banking-connectors::general.category') }}</span>
                                            <select name="category_id" class="border rounded-lg px-3 py-2 w-full" required>
                                                <option value="">—</option>
                                                @foreach ($directionCategories as $category)
                                                    <option value="{{ $category->id }}">{{ $category->name }} ({{ $category->type }})</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <div class="flex flex-wrap items-end gap-3">
                                            <label class="text-xs flex-1">
                                                <span class="block mb-1">{{ trans('banking-connectors::general.rule_priority') }}</span>
                                                <input name="priority" type="number" min="1" max="999" value="100"
                                                       class="border rounded-lg px-3 py-2 w-full">
                                            </label>
                                            <button type="submit" class="rounded-lg bg-purple text-white py-2 px-4 text-sm"
                                                    {{ $directionCategories->isEmpty() ? 'disabled' : '' }}>
                                                {{ trans('banking-connectors::general.create_rule') }}
                                            </button>
                                        </div>
                                    </form>
                                @endforeach
                            </div>
                            <p class="mt-3 text-xs text-amber-800">
                                {{ trans('banking-connectors::general.rule_direction_note') }}
                            </p>
                        @endif
                        @if ($rules === [])
                            <p class="text-sm text-gray-500 p-5 mt-4 rounded-lg bg-gray-50">
                                {{ trans('banking-connectors::general.no_rules') }}
                            </p>
                        @else
                            <div class="overflow-x-auto mt-5">
                                <table class="w-full text-sm text-left">
                                    <thead><tr class="border-b bg-gray-50">
                                        <th class="px-3 py-3">{{ trans('banking-connectors::general.rule_direction') }}</th>
                                        <th class="px-3 py-3">{{ trans('banking-connectors::general.rule_contains') }}</th>
                                        <th class="px-3 py-3">{{ trans('banking-connectors::general.category') }}</th>
                                        <th class="px-3 py-3">{{ trans('banking-connectors::general.rule_priority') }}</th>
                                        <th class="px-3 py-3">{{ trans('banking-connectors::general.rule_status') }}</th>
                                        <th class="px-3 py-3">{{ trans('banking-connectors::general.review_action') }}</th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach ($rules as $rule)
                                            <tr class="border-b">
                                                <td class="px-3 py-3">{{ trans('banking-connectors::general.movement.' . $rule['direction']) }}</td>
                                                <td class="px-3 py-3 font-medium">{{ $rule['description_contains'] }}</td>
                                                <td class="px-3 py-3">{{ $rule['category_name'] }}</td>
                                                <td class="px-3 py-3">{{ $rule['priority'] }}</td>
                                                <td class="px-3 py-3">
                                                    {{ $rule['enabled'] ? trans('banking-connectors::general.rule_active') : trans('banking-connectors::general.rule_inactive') }}
                                                </td>
                                                <td class="px-3 py-3">
                                                    @if ($canManage)
                                                        <form method="POST" action="{{ route('banking-connectors.account.rule.toggle', ['company_id'=>company_id(),'accountId'=>$selected->account_id,'ruleId'=>$rule['id']]) }}">
                                                            @csrf
                                                            <input type="hidden" name="enabled" value="{{ $rule['enabled'] ? 0 : 1 }}">
                                                            <button type="submit" class="text-purple hover:underline">
                                                                {{ $rule['enabled'] ? trans('banking-connectors::general.disable_rule') : trans('banking-connectors::general.enable_rule') }}
                                                            </button>
                                                        </form>
                                                    @else — @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </section>
                @else
                    <section aria-labelledby="bank-movements-title">
                        <div class="flex flex-wrap justify-between items-center gap-3">
                            <div>
                                <h2 id="bank-movements-title" class="text-lg font-semibold">
                                    {{ trans('banking-connectors::general.tab_review') }}
                                </h2>
                                <p class="text-sm text-gray-600 mt-1">{{ trans('banking-connectors::general.review_intro') }}</p>
                            </div>
                            @if ($selected && $canRequest && in_array($selected->state,['read_only','enabled'],true))
                                <form method="POST" class="flex flex-wrap items-end gap-2"
                                      action="{{ route('banking-connectors.connections.request',['company_id'=>company_id(),'connectionId'=>$selected->id]) }}">
                                    @csrf
                                    <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                    <label class="text-xs">
                                        {{ trans('banking-connectors::general.start_date') }}
                                        <input class="border rounded-lg px-3 py-2 block mt-1" type="date" name="start_date"
                                               max="{{ now()->format('Y-m-d') }}">
                                    </label>
                                    <button type="submit" class="rounded-lg bg-purple text-white px-4 py-2 text-sm"
                                            {{ $selected->poll_requested_at ? 'disabled' : '' }}>
                                        {{ trans('banking-connectors::general.sync_now') }}
                                    </button>
                                </form>
                            @endif
                        </div>

                        @if ($selected === null)
                            <div class="mt-5 border rounded-xl bg-white p-7 text-sm text-gray-600">
                                {{ trans('banking-connectors::general.no_connections') }}
                                <a href="{{ $panelUrl('settings') }}" class="block text-purple hover:underline mt-2">
                                    {{ trans('banking-connectors::general.tab_settings') }} &rarr;
                                </a>
                            </div>
                        @else
                            <div class="mt-5 p-4 bg-yellow-50 border border-yellow-200 rounded-xl text-sm text-yellow-900" role="status">
                                {{ trans('banking-connectors::general.read_only') }}
                            </div>
                            @if ($filter === 'pending')
                            <div class="grid grid-cols-3 gap-3 mt-5">
                                @foreach (['suggested','needs_review','unmatched'] as $status)
                                    <div class="border rounded-lg bg-white px-4 py-3">
                                        <div class="text-xs text-gray-500">{{ trans('banking-connectors::general.status.'.$status) }}</div>
                                        <div class="text-xl font-semibold mt-1">{{ $summary[$status] ?? 0 }}</div>
                                    </div>
                                @endforeach
                            </div>
                            @endif
                            <nav class="mt-5 flex flex-wrap gap-2" aria-label="{{ trans('banking-connectors::general.review_filter') }}">
                                @foreach (['pending','reviewed','dismissed','all'] as $state)
                                    <a href="{{ $panelUrl('review',['filter'=>$state,'search_days'=>$searchDays]) }}"
                                       @if($filter === $state) aria-current="page" @endif
                                       class="rounded-lg text-sm px-4 py-2 border {{ $filter === $state ? 'bg-gray-100 font-semibold border-purple' : 'bg-white hover:bg-gray-50' }}">
                                        {{ trans('banking-connectors::general.filter_'.$state) }}
                                        @if (isset($stateCounts[$state])) ({{ $stateCounts[$state] }}) @endif
                                    </a>
                                @endforeach
                            </nav>
                            <form method="GET" action="{{ $panelUrl('review') }}" class="flex flex-wrap items-end gap-3 mt-4 text-sm">
                                <input type="hidden" name="tab" value="review">
                                <input type="hidden" name="filter" value="{{ $filter }}">
                                @if ($accountContext === null)
                                    <input type="hidden" name="connection_id" value="{{ $selected->id }}">
                                @endif
                                <label>
                                    <span class="block mb-1 text-gray-600">{{ trans('banking-connectors::general.search_window') }}</span>
                                    <select name="search_days" class="border rounded-lg px-3 py-2">
                                        @foreach ([3,14,45,90] as $days)
                                            <option value="{{ $days }}" {{ $searchDays === $days ? 'selected' : '' }}>
                                                &plusmn; {{ $days }} {{ trans('banking-connectors::general.days') }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                                <button type="submit" class="border border-purple text-purple rounded-lg px-4 py-2">
                                    {{ trans('banking-connectors::general.find_transactions') }}
                                </button>
                            </form>
                            @forelse ($items as $row)
                                @php
                                    $rowCategories = $categories->where('type',$row['direction']==='credit'?'income':'expense');
                                @endphp
                                <article class="mt-4 bg-white rounded-xl border p-5" id="bank-entry-{{ $row['id'] }}">
                                    <div class="flex flex-wrap justify-between items-start gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs text-gray-500">{{ $row['date'] }} · {{ trans('banking-connectors::general.movement.'.$row['direction']) }}</p>
                                            <h3 class="font-semibold mt-1 break-words">
                                                {{ $row['description'] ?: trans('banking-connectors::general.no_bank_description') }}
                                            </h3>
                                            <p class="text-xs text-gray-500 mt-1">{{ trans('banking-connectors::general.status.'.$row['status']) }}
                                                · {{ trans('banking-connectors::general.reason.'.$row['reason']) }}</p>
                                        </div>
                                        <div class="text-lg font-semibold whitespace-nowrap {{ $row['direction'] === 'credit' ? 'text-green-700' : 'text-red-700' }}">
                                            {{ $row['direction'] === 'credit' ? '+' : '−' }}
                                            {{ $row['currency'] }}
                                            {{ number_format($row['amount_cents']/100,2,',','.') }}
                                        </div>
                                    </div>
                                    <div class="border-t mt-4 pt-4 grid grid-cols-1 lg:grid-cols-2 gap-5">
                                        <section>
                                            <h4 class="font-semibold text-sm">{{ trans('banking-connectors::general.category') }}</h4>
                                            <p class="text-sm mt-1 text-gray-700">
                                                {{ $row['category_id'] !== null
                                                   ? ($categoryNames[$row['category_id']] ?? trans('banking-connectors::general.category_missing'))
                                                   : trans('banking-connectors::general.category_unset') }}
                                                @if ($row['category_source'] !== null)
                                                    · {{ trans('banking-connectors::general.category_source_'.$row['category_source']) }}
                                                @endif
                                            </p>
                                            @if ($canReview && $row['stage_state'] !== 'conflict' && $row['stage_state'] !== 'dismissed')
                                                <form method="POST"
                                                      action="{{ route('banking-connectors.account.category.update',['company_id'=>company_id(),'accountId'=>$selected->account_id,'entryId'=>$row['id']]) }}"
                                                      class="mt-3 space-y-2">
                                                    @csrf
                                                    <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                                    <input type="hidden" name="return_filter" value="{{ $filter }}">
                                                    <input type="hidden" name="expected_native_category_id" value="{{ $row['native_category_id'] ?? '' }}">
                                                    <label class="text-xs block">
                                                        <span class="block mb-1">{{ trans('banking-connectors::general.select_category') }}</span>
                                                        <select name="category_id" class="border rounded-lg px-3 py-2 w-full">
                                                            <option value="">{{ trans('banking-connectors::general.category_unset') }}</option>
                                                            @foreach ($rowCategories as $cat)
                                                                <option value="{{ $cat->id }}" {{ $row['category_id'] === (int)$cat->id ? 'selected' : '' }}>
                                                                    {{ $cat->name }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    </label>
                                                    @if ($row['stage_state'] === 'reviewed' && !$row['native_locked'])
                                                        <label class="flex gap-2 items-center text-xs">
                                                            <input type="checkbox" name="apply_native" value="1">
                                                            {{ trans('banking-connectors::general.apply_native_category') }}
                                                        </label>
                                                        <p class="text-xs text-gray-500">
                                                            {{ trans('banking-connectors::general.native_category_now') }}:
                                                            {{ $row['native_category_name'] ?? '—' }}
                                                        </p>
                                                    @endif
                                                    <button type="submit" class="rounded-lg border border-purple text-purple px-4 py-2 text-sm">
                                                        {{ trans('banking-connectors::general.save_category') }}
                                                    </button>
                                                </form>
                                            @endif
                                        </section>
                                        <section>
                                            <h4 class="font-semibold text-sm">{{ trans('banking-connectors::general.association_heading') }}</h4>
                                            @if ($row['stage_state'] === 'reviewed')
                                                <p class="text-sm text-green-800 mt-2">
                                                    {{ trans('banking-connectors::general.manual_link') }} #{{ $row['linked_transaction_id'] }}
                                                </p>
                                                @if ($row['native_exists'])
                                                    <a class="text-sm text-purple hover:underline block mt-2"
                                                       href="{{ route('transactions.show',$row['linked_transaction_id']) }}">
                                                        {{ trans('banking-connectors::general.open_native_transaction') }} &rarr;
                                                    </a>
                                                @endif
                                            @elseif ($row['stage_state'] === 'pending' && $canReview)
                                                @if ($row['candidate_details'] !== [])
                                                    <form method="POST" class="mt-2 space-y-3"
                                                          action="{{ route('banking-connectors.review.decide',['company_id'=>company_id()]) }}">
                                                        @csrf
                                                        <input type="hidden" name="connection_id" value="{{ $selected->id }}">
                                                        <input type="hidden" name="entry_id" value="{{ $row['id'] }}">
                                                        <input type="hidden" name="operation" value="accept">
                                                        <input type="hidden" name="search_days" value="{{ $searchDays }}">
                                                        <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                                        <label class="text-xs block">
                                                            <span class="block mb-1">{{ trans('banking-connectors::general.select_native_transaction') }}</span>
                                                            <select name="native_transaction_id" class="border rounded-lg px-3 py-2 w-full" required>
                                                                <option value="">—</option>
                                                                @foreach ($row['candidate_details'] as $candidate)
                                                                    <option value="{{ $candidate['id'] }}">
                                                                        #{{ $candidate['id'] }} · {{ $candidate['date'] }} ·
                                                                        {{ $candidate['currency'] }} {{ number_format($candidate['amount_cents']/100,2,',','.') }}
                                                                        @if ($candidate['category_name']) · {{ $candidate['category_name'] }} @endif
                                                                        @if ($candidate['description']) · {{ $candidate['description'] }} @endif
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <button type="submit" class="bg-purple text-white px-4 py-2 rounded-lg text-sm">
                                                            {{ trans('banking-connectors::general.associate_transaction') }}
                                                        </button>
                                                    </form>
                                                @else
                                                    <p class="text-sm text-gray-500 mt-2">
                                                        {{ trans('banking-connectors::general.no_native_candidate') }}
                                                    </p>
                                                    <a class="inline-block text-sm text-purple hover:underline mt-2"
                                                       href="{{ route('transactions.create',['type'=>$row['direction']==='credit'?'income':'expense']) }}">
                                                        {{ trans('banking-connectors::general.create_native_transaction') }} &rarr;
                                                    </a>
                                                @endif
                                            @elseif ($row['stage_state'] === 'conflict')
                                                <p class="text-sm text-amber-800 mt-2">{{ trans('banking-connectors::general.resolve_conflict_first') }}</p>
                                            @else
                                                <p class="text-sm text-gray-500 mt-2">{{ trans('banking-connectors::general.dismiss_entry') }}</p>
                                            @endif
                                            @if ($canReview && in_array($row['stage_state'],['reviewed','dismissed','pending'],true))
                                                <form method="POST" class="mt-4" action="{{ route('banking-connectors.review.decide',['company_id'=>company_id()]) }}">
                                                    @csrf
                                                    <input type="hidden" name="connection_id" value="{{ $selected->id }}">
                                                    <input type="hidden" name="entry_id" value="{{ $row['id'] }}">
                                                    <input type="hidden" name="operation" value="{{ $row['stage_state'] === 'pending' ? 'dismiss' : 'reopen' }}">
                                                    <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                                    <button type="submit" class="text-sm text-purple hover:underline">
                                                        {{ $row['stage_state']==='pending'
                                                           ? trans('banking-connectors::general.dismiss_entry')
                                                           : trans('banking-connectors::general.reopen_entry') }}
                                                    </button>
                                                </form>
                                            @endif
                                        </section>
                                    </div>
                                </article>
                            @empty
                                <div class="mt-5 border rounded-xl bg-white p-7 text-gray-600 text-sm">
                                    {{ trans('banking-connectors::general.no_pending') }}
                                </div>
                            @endforelse
                            <p class="mt-4 text-xs text-gray-500">
                                {{ trans('banking-connectors::general.preview_limit') }}
                            </p>
                        @endif
                    </section>
                @endif
            </div>
        </x-index.container>
    </x-slot>
</x-layouts.admin>
