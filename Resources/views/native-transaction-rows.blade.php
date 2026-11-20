{{-- Native Akaunting transaction component; only linked-bank badge added. --}}
{{-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
    <x-table>
        <x-table.thead>
            <x-table.tr>
                @if (! $hideBulkAction)
                <x-table.th kind="bulkaction">
                    <x-index.bulkaction.all />
                </x-table.th>
                @endif

                @stack('paid_at_and_number_at_th_start')
                @if (! $hidePaidAt || ! $hideNumber)
                <x-table.th class="{{ $classPaidAtAndNumber }}">
                    @stack('paid_at_th_start')
                    @if (! $hidePaidAt)
                    <x-slot name="first">
                        <x-sortablelink column="paid_at" title="{{ trans($textPaidAt) }}" />
                    </x-slot>
                    @endif
                    @stack('paid_at_th_end')

                    @stack('number_th_start')
                    @if (! $hideNumber)
                    <x-slot name="second">
                        <x-sortablelink column="number" title="{{ trans_choice($textNumber, 1) }}" />
                    </x-slot>
                    @endif
                    @stack('number_th_end')
                </x-table.th>
                @endif
                @stack('paid_at_and_number_at_th_end')

                @stack('type_and_category_th_start')
                @if (! $hideType || ! $hideCategory)
                <x-table.th class="{{ $classTypeAndCategory }}" hidden-mobile>
                    @stack('type_th_start')
                    @if (! $hideType)
                    <x-slot name="first">
                        <x-sortablelink column="type" title="{{ trans_choice($textType, 1) }}" />
                    </x-slot>
                    @endif
                    @stack('type_th_end')

                    @stack('category_th_start')
                    @if (! $hideCategory)
                    <x-slot name="second">
                        <x-sortablelink column="category.name" title="{{ trans_choice($textCategory, 1) }}" />
                    </x-slot>
                    @endif
                    @stack('category_th_end')
                </x-table.th>
                @endif
                @stack('type_and_category_th_end')

                @stack('account_th_start')
                @if (! $hideAccount)
                <x-table.th class="{{ $classAccount }}">
                    @stack('account_inside_th_start')
                    <x-sortablelink column="account.name" title="{{ trans_choice($textAccount, 1) }}" />
                    @stack('account_inside_th_end')
                </x-table.th>
                @endif
                @stack('account_th_end')

                @stack('contact_and_document_th_start')
                @if (! $hideContact || ! $hideDocument)
                <x-table.th class="{{ $classContactAndDocument }}" hidden-mobile>
                    @stack('contact_th_start')
                    @if (! $hideContact)
                    <x-slot name="first">
                        <x-sortablelink column="contact.name" title="{{ trans_choice($textContact, 1) }}" />
                    </x-slot>
                    @endif
                    @stack('contact_th_end')

                    @stack('document_th_start')
                    @if (! $hideDocument)
                    <x-slot name="second">
                        <x-sortablelink column="document.document_number" title="{{ trans_choice($textDocument, 1) }}" />
                    </x-slot>
                    @endif
                    @stack('document_th_end')
                </x-table.th>
                @endif
                @stack('contact_and_document_th_end')

                @stack('amount_th_start')
                @if (! $hideAmount)
                <x-table.th class="{{ $classAmount }}" kind="amount">
                    @stack('amount_inside_th_start')
                    <x-sortablelink column="amount" title="{{ trans($textAmount) }}" />
                    @stack('amount_inside_th_end')
                </x-table.th>
                @endif
                @stack('amount_th_end')
            </x-table.tr>
        </x-table.thead>

        <x-table.tbody>
            @foreach($transactions as $item)
                @php
                    $isBankSource=$bankFeedRendering??false;
                    $bankStatus=null;
                    $bankActions=[];
                    $bankEntryUrl=null;
                    if ($isBankSource) {
                        $provisional=in_array($item->native_category_code,[
                            'banking_connectors_pending_income',
                            'banking_connectors_pending_expense',
                        ],true);
                        $bankStatus=$item->state==='conflict'?'conflict'
                            : ($item->state==='dismissed'?'ignored'
                            : ($item->publication_outcome==='created'
                                ? ($provisional?'uncategorized':'posted')
                                : ($item->native_transaction_id!==null?'matched'
                                : ($item->category_id===null?'uncategorized':'unlinked'))));
                        $bankActions=\Modules\BankingConnectors\Http\ViewComposers\NativeTransactionActions::bankActions($item);
                        $bankEntryUrl=route('banking-connectors.transactions.entry',[
                            'company_id'=>company_id(),
                            'accountId'=>$item->account_id,'entryId'=>$item->id,
                        ]);
                    }
                @endphp
                <x-table.tr href="{{ $isBankSource ? $bankEntryUrl : route('transactions.show', $item->id) }}">
                    @if (! $hideBulkAction)
                    <x-table.td kind="bulkaction">
                        <x-index.bulkaction.single id="{{ $item->id }}" name="{{ $item->contact->name }}" />
                    </x-table.td>
                    @endif

                    @stack('paid_at_and_number_at_td_start')
                    @if (! $hidePaidAt || ! $hideNumber)
                    <x-table.td class="{{ $classPaidAtAndNumber }}">
                        @stack('paid_at_td_start')
                        @if (! $hidePaidAt)
                        <x-slot name="first" class="font-bold truncate" override="class">
                            <x-date date="{{ $isBankSource ? $item->movement_date : $item->paid_at }}" />
                        </x-slot>
                        @endif
                        @stack('paid_at_td_end')

                        @stack('number_td_start')
                        @if (! $hideNumber)
                        <x-slot name="second">
                            @if($isBankSource)
                                {{ trans('banking-connectors::general.native_row_bank_source') }} #{{ $item->id }}
                            @else
                            {{ $item->number }}
                            @if(isset($bankingNativeLinked[(int)$item->id]))
                                <span class="text-xs text-purple font-semibold block mt-0.5"
                                      data-banking-connectors-linked="{{ (int)$item->id }}">
                                    {{ trans('banking-connectors::general.native_row_bank_linked') }}
                                </span>
                            @endif
                            @endif
                        </x-slot>
                        @endif
                        @stack('number_td_end')
                    </x-table.td>
                    @endif
                    @stack('paid_at_and_number_at_td_end')

                    @stack('type_and_category_td_start')
                    @if (! $hideType || ! $hideCategory)
                    <x-table.td class="{{ $classTypeAndCategory }}" hidden-mobile>
                        @stack('type_td_start')
                        @if (! $hideType)
                        <x-slot name="first">
                            {{ $isBankSource
                                ? trans_choice($item->direction==='credit'?'general.incomes':'general.expenses',1)
                                : $item->type_title }}
                        </x-slot>
                        @endif
                        @stack('type_td_end')

                        @stack('category_td_start')
                        @if (! $hideCategory)
                        <x-slot name="second" class="flex items-center">
                            @if($isBankSource)
                                {{ $item->source_category_name ?? $item->native_category_name ??
                                    trans('banking-connectors::general.native_status_uncategorized') }}
                            @else
                                <x-index.category :model="$item->category" />
                            @endif
                        </x-slot>
                        @endif
                        @stack('category_td_end')
                    </x-table.td>
                    @endif
                    @stack('type_and_category_td_end')

                    @stack('account_td_start')
                    @if (! $hideAccount)
                    <x-table.td class="{{ $classAccount }}">
                        @stack('account_td_inside_start')
                        @if($isBankSource)
                            <div class="font-medium truncate">{{ $item->account_name }}</div>
                            <div class="text-xs text-gray-500 mt-1" data-bank-row-state="{{ $item->id }}">
                                {{ trans('banking-connectors::general.native_status_'.$bankStatus) }}
                            </div>
                            @if($item->bank_description)
                                <div class="text-xs text-gray-600 mt-1" data-bank-row-narration="{{ $item->id }}">
                                    {{ \Illuminate\Support\Str::limit((string)$item->bank_description,90) }}
                                </div>
                            @endif
                            @if($item->publication_issue_code)
                                <div class="text-xs text-amber-700 mt-1">
                                    @php
                                        $issueKey='banking-connectors::general.publication_issue.'.$item->publication_issue_code;
                                    @endphp
                                    {{ trans($issueKey)===$issueKey
                                        ? trans('banking-connectors::general.native_review_required')
                                        : trans($issueKey) }}
                                </div>
                            @endif
                        @else
                            {{ $item->account->name }}
                            @if(isset($bankingNativeLinked[(int)$item->id]))
                                <div class="text-xs text-purple mt-1" data-banking-bank-details="{{ (int)$item->id }}">
                                    {{ trans('banking-connectors::general.native_row_bank_linked') }}
                                    @if($bankingNativeLinked[(int)$item->id]->bank_description)
                                        — {{ \Illuminate\Support\Str::limit(
                                            $bankingNativeLinked[(int)$item->id]->bank_description,90) }}
                                    @endif
                                </div>
                            @elseif(!empty($bankingNativeConnected[(int)$item->account_id]))
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ trans('banking-connectors::general.native_filter_transaction_connected') }}
                                </div>
                            @endif
                        @endif
                        @stack('account_td_inside_end')
                    </x-table.td>
                    @endif
                    @stack('account_td_end')

                    @stack('contact_and_document_td_start')
                    @if (! $hideContact || ! $hideDocument)
                    <x-table.td class="{{ $classContactAndDocument }}" hidden-mobile>
                        @stack('contact_td_start')
                        @if (! $hideContact)
                        <x-slot name="first">
                            @if($isBankSource)
                                @if($item->native_contact_name)
                                    {{ $item->native_contact_name }}
                                @else
                                    <x-empty-data />
                                @endif
                            @else
                                {{ $item->contact->name }}
                            @endif
                        </x-slot>
                        @endif
                        @stack('contact_td_end')

                        @stack('document_td_start')
                        @if (! $hideDocument)
                        <x-slot name="second" class="w-20 font-normal group">
                            @if($isBankSource)
                                @if($item->native_document_number)
                                    {{ $item->native_document_number }}
                                @else
                                    <x-empty-data />
                                @endif
                            @elseif ($item->document)
                                <div data-tooltip-target="tooltip-information-{{ $item->document_id }}" data-tooltip-placement="{{ language()->direction() === 'rtl' ? 'right' : 'left' }}" override="class">
                                    <x-link href="{{ route($item->route_name, $item->route_id) }}" class="font-normal truncate border-b border-black border-dashed" override="class">
                                        {{ $item->document->document_number }}
                                    </x-link>

                                    <div class="w-28 absolute h-10 ltr:-ml-12 rtl:-mr-12 -mt-6"></div>

                                    <x-documents.index.information :document="$item->document" />
                                </div>
                            @else
                                <x-empty-data />
                            @endif
                        </x-slot>
                        @endif
                        @stack('document_td_end')
                    </x-table.td>
                    @endif
                    @stack('contact_and_document_td_end')

                    @stack('amount_td_start')
                    @if (! $hideAmount)
                    <x-table.td class="{{ $classAmount }}" kind="amount">
                        @stack('amount_td_inside_start')
                        @if($isBankSource)
                            <x-money :amount="((float)$item->amount_cents / 100)" :currency="$item->currency" />
                        @else
                            <x-money :amount="$item->amount" :currency="$item->currency_code" />
                        @endif
                        @stack('amount_td_inside_end')
                    </x-table.td>
                    @endif
                    @stack('amount_td_end')

                    <x-table.td kind="action">
                        @if($isBankSource)
                            <x-table.actions :model="$item" :actions="$bankActions" />
                        @else
                            <x-table.actions :model="$item" />
                        @endif
                    </x-table.td>
                </x-table.tr>
            @endforeach
            @if(($bankFeedRendering??false) && count($transactions)===0)
                <x-table.tr>
                    <x-table.td colspan="7">
                        {{ trans('banking-connectors::general.native_no_movements') }}
                    </x-table.td>
                </x-table.tr>
            @endif
        </x-table.tbody>
    </x-table>

    <x-pagination :items="$transactions" />