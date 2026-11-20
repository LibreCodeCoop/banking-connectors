{{-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
<section class="max-w-full rounded-xl border bg-white p-5 mb-6 space-y-4" id="bank-feed-native-transactions">
    <div class="flex flex-wrap justify-between items-start gap-3">
        <div>
            <h2 class="font-semibold text-lg">{{ trans('banking-connectors::general.native_bank_feed') }}</h2>
            <p class="text-sm text-gray-600">{{ trans('banking-connectors::general.native_feed_intro') }}</p>
        </div>
        <a class="text-sm text-purple hover:underline" href="{{ route('accounts.index',['company_id'=>company_id()]) }}">
            {{ trans('banking-connectors::general.native_setup_link') }}
        </a>
    </div>
    <nav class="flex flex-wrap gap-2" aria-label="{{ trans('banking-connectors::general.native_feed_filters') }}">
        @foreach (['all','needs_action','uncategorized','unlinked','conflict','imported','posted','matched','ignored'] as $name)
            <a href="{{ route('transactions.index',array_filter([
                    'company_id'=>company_id(),'bank_feed'=>$name,
                    'bank_account_id'=>$bankFeedAccount,
                ],static fn($v)=>$v!==null)) }}"
               class="rounded-lg px-3 py-2 text-sm border {{ $bankFeedFilter===$name ? 'bg-purple text-white' : 'bg-white text-purple' }}"
               @if($bankFeedFilter===$name) aria-current="page" @endif>
                {{ trans('banking-connectors::general.native_filter_'.$name) }}
                <span class="text-xs">({{ $bankFeedCounts[$name] }})</span>
            </a>
        @endforeach
    </nav>
    @if ($bankFeedAccounts->count() > 1)
        <form method="GET" action="{{ route('transactions.index',['company_id'=>company_id()]) }}"
              class="flex flex-wrap gap-2 items-end text-sm">
            <input type="hidden" name="bank_feed" value="{{ $bankFeedFilter }}">
            <label>
                {{ trans('banking-connectors::general.account') }}
                <select name="bank_account_id" class="border rounded px-3 py-2">
                    <option value="">{{ trans('banking-connectors::general.native_all_bank_accounts') }}</option>
                    @foreach ($bankFeedAccounts as $account)
                        <option value="{{ $account->id }}" {{ $bankFeedAccount===(int)$account->id?'selected':'' }}>{{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
            <button type="submit" class="rounded-lg px-4 py-2 bg-purple text-white">{{ trans('banking-connectors::general.show') }}</button>
        </form>
    @endif
    @if($bankFeedFilter!=='all')
        <p class="text-xs text-gray-600">{{ trans('banking-connectors::general.native_feed_source_note') }}</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b text-left">
                    <th class="p-3">{{ trans('banking-connectors::general.date') }}</th>
                    <th class="p-3">{{ trans('banking-connectors::general.account') }}</th>
                    <th class="p-3">{{ trans('banking-connectors::general.movement.debit') }} / {{ trans('banking-connectors::general.movement.credit') }}</th>
                    <th class="p-3">{{ trans('banking-connectors::general.amount') }}</th>
                    <th class="p-3">{{ trans('banking-connectors::general.category') }}</th>
                    <th class="p-3">{{ trans('banking-connectors::general.native_status') }}</th>
                    <th class="p-3">{{ trans('banking-connectors::general.review_action') }}</th>
                </tr></thead>
                <tbody>
                @forelse($bankFeedRows as $row)
                    @php
                        $nativeIsPending = in_array($row->native_category_code,[
                            'banking_connectors_pending_income','banking_connectors_pending_expense'
                        ],true);
                        $status = $row->state==='conflict' ? 'conflict'
                            : ($row->state==='dismissed' ? 'ignored'
                            : ($row->publication_outcome==='created'
                                ? ($nativeIsPending ? 'uncategorized':'posted')
                                : ($row->linked_transaction_id!==null ? 'matched'
                                : ($row->category_id===null?'uncategorized':'unlinked'))));
                        $categories = $bankFeedCategories->where('type',$row->direction==='credit'?'income':'expense');
                        $canNative = $row->linked_transaction_id!==null
                            && !$row->native_deleted_at
                            && (int)$row->reconciled===0
                            && $row->document_id===null
                            && (int)$row->parent_id===0
                            && $row->split_id===null;
                    @endphp
                    <tr class="border-b align-top">
                        <td class="p-3 whitespace-nowrap">{{ $row->movement_date }}</td>
                        <td class="p-3">{{ $row->account_name }}</td>
                        <td class="p-3 max-w-72">
                            <strong>{{ trans('banking-connectors::general.movement.'.$row->direction) }}</strong>
                            <p class="text-xs mt-1">{{ $row->bank_description ?? trans('banking-connectors::general.no_bank_description') }}</p>
                        </td>
                        <td class="p-3 whitespace-nowrap">
                            {{ number_format($row->amount_cents / 100,2,',','.') }} {{ $row->currency }}
                        </td>
                        <td class="p-3">
                            @if($bankFeedCanClassify && $row->state!=='conflict' && $row->state!=='dismissed' && (!$row->linked_transaction_id || $canNative))
                                <form method="POST"
                                    action="{{ route('banking-connectors.account.category.update',[
                                        'company_id'=>company_id(),'accountId'=>$row->account_id,'entryId'=>$row->id
                                    ]) }}" class="space-y-2">
                                    @csrf
                                    <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                    <input type="hidden" name="return_to" value="transactions">
                                    <input type="hidden" name="return_filter" value="{{ $bankFeedFilter }}">
                                    <input type="hidden" name="apply_native" value="{{ $canNative?'1':'0' }}">
                                    @if($canNative)
                                        <input type="hidden" name="expected_native_category_id" value="{{ $row->native_category_id }}">
                                    @endif
                                    <select name="category_id" class="rounded border px-2 py-2 max-w-48" required>
                                        <option value="">{{ trans('banking-connectors::general.category_unset') }}</option>
                                        @foreach($categories as $category)
                                            <option value="{{ $category->id }}" {{ ($row->category_id ?? $row->native_category_id)===(int)$category->id?'selected':'' }}>{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="px-3 py-1 border rounded text-purple">
                                        {{ trans('banking-connectors::general.save_category') }}
                                    </button>
                                </form>
                            @else
                                {{ $row->native_category_name ?? trans('banking-connectors::general.category_unset') }}
                            @endif
                        </td>
                        <td class="p-3">
                            {{ trans('banking-connectors::general.native_status_'.$status) }}
                            @if($row->publication_issue_code)
                                <p class="text-xs text-amber-800 mt-1">
                                    {{ trans('banking-connectors::general.publication_issue.'.$row->publication_issue_code) !==
                                        'banking-connectors::general.publication_issue.'.$row->publication_issue_code
                                        ? trans('banking-connectors::general.publication_issue.'.$row->publication_issue_code)
                                        : trans('banking-connectors::general.native_review_required') }}
                                </p>
                            @endif
                        </td>
                        <td class="p-3">
                            @if($row->linked_transaction_id!==null && $row->native_deleted_at===null)
                                <a class="text-purple underline block mb-2" href="{{ route('transactions.show',[
                                    'company_id'=>company_id(),'transaction'=>$row->linked_transaction_id
                                ]) }}">{{ trans('banking-connectors::general.open_native_transaction') }}</a>
                            @endif
                            @if($row->publication_issue_code==='possible_internal_transfer'
                                && $bankFeedCanClassify && Route::has('transfers.create'))
                                <a class="text-purple underline block mb-2"
                                   href="{{ route('transfers.create',['company_id'=>company_id()]) }}">
                                    {{ trans('banking-connectors::general.native_create_transfer') }}
                                </a>
                            @endif
                            <a class="text-purple underline" href="{{ route('banking-connectors.transactions.review',[
                                'company_id'=>company_id(),'accountId'=>$row->account_id,'filter'=>$row->state==='pending'?'pending':'all'
                            ]) }}">{{ trans('banking-connectors::general.native_resolve') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-4 text-gray-500">{{ trans('banking-connectors::general.native_no_movements') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if(method_exists($bankFeedRows,'links'))
            {{ $bankFeedRows->links() }}
        @endif
    @endif
</section>
