{{-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Rows imported from a bank are NOT native transactions until published or
     manually linked. Render inside Akaunting's own tab/table/search layout. --}}
<section id="bank-feed-native-transactions" aria-label="{{ trans('banking-connectors::general.native_bank_tab') }}">
    <div class="flex flex-wrap justify-between gap-3 items-center mb-4">
        <p class="text-sm text-gray-600">
            {{ trans('banking-connectors::general.native_feed_source_note') }}
        </p>
        @can('read-banking-accounts')
            <x-link href="{{ route('accounts.index',['company_id'=>company_id()]) }}"
                    class="text-purple text-sm">
                {{ trans('banking-connectors::general.native_setup_link') }}
            </x-link>
        @endcan
    </div>

    <x-table>
        <x-table.thead>
            <x-table.tr>
                <x-table.th>{{ trans('banking-connectors::general.date') }}</x-table.th>
                <x-table.th hidden-mobile>{{ trans('banking-connectors::general.account') }}</x-table.th>
                <x-table.th>{{ trans('banking-connectors::general.native_bank_description') }}</x-table.th>
                <x-table.th hidden-mobile>{{ trans('banking-connectors::general.category') }}</x-table.th>
                <x-table.th>{{ trans('banking-connectors::general.native_status') }}</x-table.th>
                <x-table.th kind="amount">{{ trans('banking-connectors::general.amount') }}</x-table.th>
            </x-table.tr>
        </x-table.thead>

        <x-table.tbody>
            @forelse($bankFeedRows as $row)
                @php
                    $provisional = in_array($row->native_category_code,[
                        'banking_connectors_pending_income',
                        'banking_connectors_pending_expense',
                    ],true);
                    $status = $row->state==='conflict' ? 'conflict'
                        : ($row->state==='dismissed' ? 'ignored'
                        : ($row->publication_outcome==='created'
                            ? ($provisional ? 'uncategorized':'posted')
                            : ($row->linked_transaction_id!==null ? 'matched'
                            : ($row->category_id===null?'uncategorized':'unlinked'))));
                    $canNative=$row->linked_transaction_id!==null
                        && $row->native_deleted_at===null
                        && (int)$row->reconciled===0
                        && $row->document_id===null
                        && (int)$row->parent_id===0
                        && $row->split_id===null;
                    $entryUrl=route('banking-connectors.transactions.review',[
                        'company_id'=>company_id(),
                        'accountId'=>$row->account_id,
                        'filter'=>$row->state==='pending'?'pending':'all',
                    ]);
                    $actions=[[
                        'title'=>trans('banking-connectors::general.native_resolve'),
                        'icon'=>'fact_check','url'=>$entryUrl,
                        'permission'=>'read-banking-transactions',
                    ]];
                    if ($row->linked_transaction_id!==null && $row->native_deleted_at===null) {
                        $actions[]=[
                            'title'=>trans('banking-connectors::general.open_native_transaction'),
                            'icon'=>'visibility',
                            'url'=>route('transactions.show',[
                                'company_id'=>company_id(),
                                'transaction'=>$row->linked_transaction_id,
                            ]),
                            'permission'=>'read-banking-transactions',
                        ];
                    }
                    if ($row->publication_issue_code==='possible_internal_transfer'
                        && $bankFeedCanClassify && Route::has('transfers.create')) {
                        $actions[]=[
                            'title'=>trans('banking-connectors::general.native_create_transfer'),
                            'icon'=>'swap_horiz',
                            'url'=>route('transfers.create',['company_id'=>company_id()]),
                            'permission'=>'create-banking-transactions',
                        ];
                    }
                @endphp
                <x-table.tr href="{{ $entryUrl }}">
                    <x-table.td>
                        <x-slot name="first" class="font-bold"><x-date date="{{ $row->movement_date }}" /></x-slot>
                    </x-table.td>
                    <x-table.td hidden-mobile>{{ $row->account_name }}</x-table.td>
                    <x-table.td>
                        <x-slot name="first">
                            {{ trans('banking-connectors::general.movement.'.$row->direction) }}
                        </x-slot>
                        <x-slot name="second">
                            {{ $row->bank_description ?? trans('banking-connectors::general.no_bank_description') }}
                        </x-slot>
                    </x-table.td>
                    <x-table.td hidden-mobile>
                        @if($bankFeedCanClassify && !in_array($row->state,['conflict','dismissed'],true)
                            && ($row->linked_transaction_id===null || $canNative))
                            <form method="POST" class="flex items-center gap-2"
                                x-on:click.stop
                                action="{{ route('banking-connectors.account.category.update',[
                                    'company_id'=>company_id(),
                                    'accountId'=>$row->account_id,
                                    'entryId'=>$row->id,
                                ]) }}">
                                @csrf
                                <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                <input type="hidden" name="return_to" value="transactions">
                                <input type="hidden" name="return_filter" value="{{ $bankFeedFilter }}">
                                <input type="hidden" name="apply_native" value="{{ $canNative?'1':'0' }}">
                                @if($canNative)
                                    <input type="hidden" name="expected_native_category_id" value="{{ $row->native_category_id }}">
                                @endif
                                <select name="category_id"
                                    aria-label="{{ trans('banking-connectors::general.category') }}"
                                    class="border rounded px-2 py-2 max-w-40 text-xs" required>
                                    <option value="">{{ trans('banking-connectors::general.category_unset') }}</option>
                                    @foreach($bankFeedCategories->where('type',
                                            $row->direction==='credit'?'income':'expense') as $category)
                                        <option value="{{ $category->id }}"
                                            {{ (int)($row->category_id ?? $row->native_category_id)===(int)$category->id?'selected':'' }}>
                                            {{ $category->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="text-purple text-xs underline">
                                    {{ trans('banking-connectors::general.save_category') }}
                                </button>
                            </form>
                        @else
                            {{ $row->native_category_name ?? trans('banking-connectors::general.category_unset') }}
                        @endif
                    </x-table.td>
                    <x-table.td>
                        <x-slot name="first">
                            {{ trans('banking-connectors::general.native_status_'.$status) }}
                        </x-slot>
                        @if($row->publication_issue_code)
                            <x-slot name="second">
                                @php
                                    $reasonKey='banking-connectors::general.publication_issue.'.$row->publication_issue_code;
                                    $reason=trans($reasonKey);
                                @endphp
                                {{ $reason===$reasonKey
                                    ? trans('banking-connectors::general.native_review_required')
                                    : $reason }}
                            </x-slot>
                        @endif
                    </x-table.td>
                    <x-table.td kind="amount">
                        {{ number_format((int)$row->amount_cents/100,2,',','.') }}
                        {{ $row->currency }}
                    </x-table.td>
                    <x-table.td kind="action">
                        <x-table.actions :model="$row" :actions="$actions" />
                    </x-table.td>
                </x-table.tr>
            @empty
                <x-table.tr>
                    <x-table.td colspan="7">
                        {{ trans('banking-connectors::general.native_no_movements') }}
                    </x-table.td>
                </x-table.tr>
            @endforelse
        </x-table.tbody>
    </x-table>
    <x-pagination :items="$bankFeedRows" />
</section>
