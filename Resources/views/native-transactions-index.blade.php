{{-- SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors --}}
{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Module override of the short native transaction shell, preserving its
     existing components, slots, tabs, totals, scripts and standard filters. --}}
<x-layouts.admin>
    <x-slot name="title">
        {{ trans_choice('general.transactions', 2) }}
    </x-slot>
    <x-slot name="favorite"
        title="{{ trans_choice('general.transactions', 2) }}"
        icon="receipt_long"
        route="transactions.index"
    ></x-slot>
    <x-slot name="buttons">
        <x-transactions.index.buttons :type="$type" />
    </x-slot>
    <x-slot name="moreButtons">
        <x-transactions.index.more-buttons :type="$type" />
    </x-slot>
    <x-slot name="content">
        @include('banking-connectors::partials.native-feed-filters')
        @if ($bankFeedFilter === 'all')
            <x-transactions.index.content
                :type="$type"
                :transactions="$transactions"
                :total-transactions="$total_transactions"
                :summary-items="$summary_amounts"
            />
        @endif
    </x-slot>
    <x-transactions.script :type="$type" />
</x-layouts.admin>
