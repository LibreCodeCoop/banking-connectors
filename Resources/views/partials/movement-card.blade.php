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
                                    @if($singleEntryId !== null)
                                        <section class="mt-4 rounded-lg border p-4" aria-labelledby="bank-details-heading">
                                            <h4 id="bank-details-heading" class="font-semibold text-sm">{{ trans('banking-connectors::general.bank_details_heading') }}</h4>
                                            @if($row['bank_details'] !== [])
                                                @php $groupedDetails=collect($row['bank_details'])->groupBy('group'); @endphp
                                                @foreach($groupedDetails as $groupLabel => $groupDetails)
                                                    <h5 class="text-sm font-medium mt-4">{{ trans($groupLabel) }}</h5>
                                                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2 text-sm">
                                                        @foreach($groupDetails as $detail)
                                                            <div class="min-w-0">
                                                                <dt class="text-gray-500">{{ trans($detail['label']) }}</dt>
                                                                <dd class="font-medium break-all">{{ $detail['value'] }}</dd>
                                                            </div>
                                                        @endforeach
                                                    </dl>
                                                @endforeach
                                            @else
                                                <p class="mt-2 text-sm text-gray-500">{{ trans('banking-connectors::general.bank_details_unavailable') }}</p>
                                            @endif
                                            @if($canReview && $row['provider']==='sicoob' && $row['direction']==='credit' &&
                                                isset($row['bank_details']['pix_end_to_end_id']) &&
                                                preg_match('/^E[0-9]{8}[A-Za-z0-9]{23}$/D',$row['bank_details']['pix_end_to_end_id']['value']) &&
                                                getenv('BANKING_CONNECTORS_SICOOB_PIX_API_URL') && config('queue.default')!=='sync')
                                                <form method="POST" class="mt-4" action="{{ route('banking-connectors.transactions.entry.enrich',[
                                                    'company_id'=>company_id(),'accountId'=>$selected->account_id,'entryId'=>$row['id']
                                                ]) }}">
                                                    @csrf
                                                    <button type="submit" class="border border-purple text-purple rounded-lg px-4 py-2 text-sm">
                                                        {{ trans('banking-connectors::general.bank_enrichment_action') }}
                                                    </button>
                                                    <p class="mt-1 text-xs text-gray-500">{{ trans('banking-connectors::general.bank_enrichment_note') }}</p>
                                                </form>
                                            @endif
                                        </section>
                                    @endif
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
                                            @if ($row['possible_counterparty'] !== null)
                                                <p class="mt-3 text-sm text-blue-800" id="bank-entry-{{ $row['id'] }}-contact-hint">
                                                    {{ trans('banking-connectors::general.possible_counterparty') }}:
                                                    {{ $row['possible_counterparty']['name'] }}
                                                    ({{ trans('banking-connectors::general.counterparty_' . $row['possible_counterparty']['type']) }})
                                                </p>
                                                <p class="mt-1 text-xs text-gray-600">
                                                    {{ trans('banking-connectors::general.counterparty_warning') }}
                                                </p>
                                            @endif
                                            @if($singleEntryId !== null && $row['stage_state']==='pending' &&
                                                $row['native_candidate_category_suggestion'] !== null && $row['category_id']===null)
                                                <p class="mt-3 text-sm text-purple" id="banking-connectors-native-category-suggestion">
                                                    {{ trans('banking-connectors::general.native_candidate_category_suggestion',[
                                                        'category'=>$categoryNames[$row['native_candidate_category_suggestion']]
                                                            ?? trans('banking-connectors::general.category_missing')
                                                    ]) }}
                                                </p>
                                            @endif
                                            @if ($row['learning_suggestion'] !== null)
                                                <p class="mt-3 text-sm font-medium text-purple" id="bank-entry-{{ $row['id'] }}-learning-hint">
                                                    {{ trans('banking-connectors::general.learning_suggested_category') }}:
                                                    {{ $categoryNames[$row['learning_suggestion']['category_id']] ?? trans('banking-connectors::general.category_missing') }}
                                                </p>
                                                <p class="mt-1 text-xs text-gray-600">
                                                    {{ trans('banking-connectors::general.learning_evidence', [
                                                        'count'=>$row['learning_suggestion']['class_examples'],
                                                        'terms'=>$row['learning_suggestion']['shared_terms']
                                                    ]) }}
                                                </p>
                                            @endif
                                            @if ($canReview && $row['stage_state'] !== 'conflict' && $row['stage_state'] !== 'dismissed')
                                                @php
                                                    // Suggestion is preselected, never applied without a
                                                    // deliberate, authorized save by the reviewer.
                                                    $formCategory = $row['category_id']
                                                        ?? ($row['invoice_category_suggestion'] ?? null)
                                                        ?? ($row['native_candidate_category_suggestion'] ?? null)
                                                        ?? ($row['learning_suggestion']['category_id'] ?? null);
                                                @endphp
                                                @if($singleEntryId !== null && $row['stage_state']==='pending' && count($row['candidate_details'])===1)
                                                    <details class="mt-3 text-sm" id="banking-connectors-classify-without-link">
                                                        <summary class="cursor-pointer text-gray-600">{{ trans('banking-connectors::general.classify_without_link') }}</summary>
                                                @endif
                                                <form method="POST"
                                                      action="{{ route('banking-connectors.account.category.update',['company_id'=>company_id(),'accountId'=>$selected->account_id,'entryId'=>$row['id']]) }}"
                                                      class="mt-3 space-y-2">
                                                    @csrf
                                                    <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                                                    <input type="hidden" name="return_filter" value="{{ $filter }}">
                                                    @if($singleEntryId !== null)
                                                        <input type="hidden" name="return_entry_id" value="{{ $singleEntryId }}">
                                                        @if($returnSource !== null)<input type="hidden" name="from" value="{{ $returnSource }}">@endif
                                                    @endif
                                                    <input type="hidden" name="expected_native_category_id" value="{{ $row['native_category_id'] ?? '' }}">
                                                    <label class="text-xs block">
                                                        <span class="block mb-1">{{ trans('banking-connectors::general.select_category') }}</span>
                                                        <select name="category_id" class="border rounded-lg px-3 py-2 w-full">
                                                            <option value="">{{ trans('banking-connectors::general.category_unset') }}</option>
                                                            @foreach ($rowCategories as $cat)
                                                                <option value="{{ $cat->id }}" {{ $formCategory === (int)$cat->id ? 'selected' : '' }}>
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
                                                    <x-button type="submit" kind="secondary" id="banking-connectors-save-classification">
                                                        {{ trans('banking-connectors::general.save_category') }}
                                                    </x-button>
                                                </form>
                                                @if($singleEntryId !== null && $row['stage_state']==='pending' && count($row['candidate_details'])===1)</details>@endif
                                            @endif
                                        </section>
                                        <section>
                                            <h4 class="font-semibold text-sm">{{ trans('banking-connectors::general.association_heading') }}</h4>
                                            @if ($singleEntryId !== null && !empty($row['invoice_candidates']))
                                                <div class="mt-3 space-y-2" id="banking-connectors-invoice-candidates">
                                                    <p class="text-sm font-medium">{{ trans('banking-connectors::general.invoice_candidates') }}</p>
                                                    @foreach($row['invoice_candidates'] as $invoice)
                                                        <div class="rounded-lg border p-3 text-sm">
                                                            <a href="{{ route('invoices.show',['company_id'=>company_id(),'invoice'=>$invoice['id']]) }}"
                                                               class="text-purple underline" target="_blank" rel="noopener noreferrer">
                                                                {{ $invoice['number'] }}
                                                            </a>
                                                            <span class="text-gray-500 block mt-1">{{ $invoice['contact_name'] }}</span>
                                                            <p class="text-xs text-gray-500 mt-1">{{ trans('banking-connectors::general.invoice_match_'.$invoice['reason']) }}</p>
                                                        </div>
                                                    @endforeach
                                                    <p class="text-xs text-amber-700">{{ trans('banking-connectors::general.invoice_candidates_warning') }}</p>
                                                </div>
                                            @endif
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
                                                    @if ($singleEntryId !== null)
                                                        <details class="mt-3 text-sm" id="banking-connectors-association-details" @if(count($row['candidate_details'])===1) open @endif>
                                                            <summary class="cursor-pointer text-purple font-medium">{{ trans('banking-connectors::general.show_match_options') }}</summary>
                                                    @endif
                                                    <p class="mt-2 text-xs text-gray-600">{{ trans('banking-connectors::general.native_candidate_not_proof') }}</p>
                                                    <form method="POST" class="mt-2 space-y-3"
                                                          action="{{ route('banking-connectors.review.decide',['company_id'=>company_id()]) }}">
                                                        @if($singleEntryId !== null)
                                                        <input type="hidden" name="return_entry_id" value="{{ $singleEntryId }}">
                                                        @if($returnSource !== null)<input type="hidden" name="from" value="{{ $returnSource }}">@endif
                                                    @endif
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
                                                                    <option value="{{ $candidate['id'] }}" @if(count($row['candidate_details'])===1) selected @endif>
                                                                        #{{ $candidate['id'] }} · {{ $candidate['date'] }} ·
                                                                        {{ $candidate['currency'] }} {{ number_format($candidate['amount_cents']/100,2,',','.') }}
                                                                        @if ($candidate['category_name']) · {{ $candidate['category_name'] }} @endif
                                                                        @if ($candidate['description']) · {{ $candidate['description'] }} @endif
                                                                    </option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                            @if($singleEntryId !== null)
                                                                @foreach($row['candidate_details'] as $candidate)
                                                                    @if(!empty($candidate['paid_bill']))
                                                                        <p class="mt-2 text-xs text-amber-800">
                                                                            {{ trans('banking-connectors::general.native_candidate_bill_paid') }}:
                                                                            <a href="{{ route('bills.show',['company_id'=>company_id(),'bill'=>$candidate['paid_bill']['id']]) }}"
                                                                               class="text-purple underline" target="_blank" rel="noopener noreferrer">
                                                                                {{ $candidate['paid_bill']['number'] }}
                                                                            </a>
                                                                        </p>
                                                                    @endif
                                                                @endforeach
                                                            @endif
                                                        <x-button type="submit" kind="secondary">
                                                            {{ ($row['native_candidate_category_suggestion'] ?? null)!==null
                                                                ? trans('banking-connectors::general.associate_and_inherit_category')
                                                                : trans('banking-connectors::general.associate_transaction') }}
                                                        </x-button>
                                                    </form>
                                                    @if ($singleEntryId !== null)</details>@endif
                                                @else
                                                    <p class="text-sm text-gray-500 mt-2">
                                                        {{ trans('banking-connectors::general.no_native_candidate') }}
                                                    </p>
                                                @endif
                                            @elseif ($row['stage_state'] === 'conflict')
                                                <p class="text-sm text-amber-800 mt-2">{{ trans('banking-connectors::general.resolve_conflict_first') }}</p>
                                            @else
                                                <p class="text-sm text-gray-500 mt-2">{{ trans('banking-connectors::general.dismiss_entry') }}</p>
                                            @endif
                                            @if ($canReview && in_array($row['stage_state'],['reviewed','dismissed','pending'],true))
                                                @if($singleEntryId !== null)
                                                    <details class="mt-4 text-sm" id="banking-connectors-other-actions">
                                                        <summary class="cursor-pointer text-gray-500">{{ trans('banking-connectors::general.other_review_actions') }}</summary>
                                                @endif
                                                <form method="POST" class="mt-4" action="{{ route('banking-connectors.review.decide',['company_id'=>company_id()]) }}">
                                                        @if($singleEntryId !== null)
                                                        <input type="hidden" name="return_entry_id" value="{{ $singleEntryId }}">
                                                        @if($returnSource !== null)<input type="hidden" name="from" value="{{ $returnSource }}">@endif
                                                    @endif
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
                                                @if($singleEntryId !== null)</details>@endif
                                            @endif
                                        </section>
                                    </div>
                                </article>
