@if ($checkPermissionCreate)
    @can($permissionCreate)
        @if (! $hideIncomeCreate)
            <x-link href="{{ route($routeIncomeCreate[0], $routeIncomeCreate[1]) }}" kind="primary" id="index-more-actions-new-income-transaction">
                {{ trans('general.title.new', ['type' => trans_choice($textIncomeCreate, 1)]) }}
            </x-link>
        @endif

        @if(isset($bankSyncConnections) && $bankSyncConnections->isNotEmpty())
            @if($bankSyncConnections->count()===1)
                @php $bankSync=$bankSyncConnections->first(); @endphp
                <form method="POST" action="{{ route('banking-connectors.connections.request',['company_id'=>company_id(),'connectionId'=>$bankSync->id]) }}" class="inline-flex">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                    <button type="submit" class="border rounded-lg px-4 py-2 text-purple" {{ $bankSync->poll_requested_at ? 'disabled' : '' }}>
                        {{ trans('banking-connectors::general.sync_now') }}
                    </button>
                </form>
            @else
                <x-dropdown id="banking-connectors-sync-dropdown">
                    <x-slot name="trigger"><span class="text-purple">{{ trans('banking-connectors::general.sync_now') }}</span></x-slot>
                    @foreach($bankSyncConnections as $bankSync)
                        <form method="POST" action="{{ route('banking-connectors.connections.request',['company_id'=>company_id(),'connectionId'=>$bankSync->id]) }}">
                            @csrf
                            <input type="hidden" name="request_key" value="{{ bin2hex(random_bytes(16)) }}">
                            <button type="submit" class="block w-full text-left px-4 py-2" {{ $bankSync->poll_requested_at ? 'disabled' : '' }}>{{ $bankSync->name }}</button>
                        </form>
                    @endforeach
                </x-dropdown>
            @endif
        @endif

        @if (! $hideExpenseCreate)
            <x-link href="{{ route($routeExpenseCreate[0], $routeExpenseCreate[1]) }}" kind="primary" id="index-more-actions-new-expense-transaction">
                {{ trans('general.title.new', ['type' => trans_choice($textExpenseCreate, 1)]) }}
            </x-link>
        @endif
    @endcan
@else
    @if (! $hideIncomeCreate)
        <x-link href="{{ route($routeIncomeCreate[0], $routeIncomeCreate[1]) }}" kind="primary" id="index-more-actions-new-income-transaction">
            {{ trans('general.title.new', ['type' => trans_choice($textIncomeCreate, 1)]) }}
        </x-link>
    @endif

    @if (! $hideExpenseCreate)
        <x-link href="{{ route($routeExpenseCreate[0], $routeExpenseCreate[1]) }}" kind="primary" id="index-more-actions-new-expense-transaction">
            {{ trans('general.title.new', ['type' => trans_choice($textExpenseCreate, 1)]) }}
        </x-link>
    @endif
@endif
