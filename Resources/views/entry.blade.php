<x-layouts.admin>
    <x-slot name="title">{{ trans('banking-connectors::general.single_entry_title') }}</x-slot>
    <x-slot name="content">
        <x-index.container>
            <main class="max-w-5xl mx-auto space-y-5" id="banking-connectors-single-entry">
                <header class="flex flex-wrap justify-between items-start gap-4">
                    <div>
                        <x-link href="{{ $returnUrl }}" id="banking-connectors-back-to-account">
                            &larr; {{ trans('banking-connectors::general.back_to_transactions') }}
                        </x-link>
                        <h1 class="text-2xl font-semibold mt-3">{{ trans('banking-connectors::general.single_entry_title') }}</h1>
                        <p class="text-sm text-gray-500 mt-1">{{ $accountContext?->name }} · {{ strtoupper($selected->provider) }}</p>
                    </div>
                </header>
                @if(session('success'))
                    <div class="rounded-lg border border-green-200 p-4 text-sm" role="status">{{ session('success') }}</div>
                @endif
                @if(isset($errors) && $errors->any())
                    <div class="rounded-lg border border-red-200 p-4 text-sm" role="alert">
                        {{ trans('banking-connectors::general.review_failed') }}: {{ $errors->first() }}
                    </div>
                @endif
                @php $row=$items[0]; @endphp
                @include('banking-connectors::partials.movement-card')
            </main>
        </x-index.container>
    </x-slot>
</x-layouts.admin>
