<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Application;

use App\Jobs\Banking\CreateTransaction;
use App\Models\Common\Company;
use App\Models\Setting\Category;
use App\Utilities\TransactionNumber;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\BankingConnectors\Domain\CategoryRuleEngine;
use Modules\BankingConnectors\Domain\NativePostingDecision;
use Modules\BankingConnectors\Infrastructure\NativeTransactionReader;
use Throwable;

/**
 * Idempotent native Akaunting publication, never bank-side payment initiation.
 *
 * The authoritative source is still the bank. Existing native transactions
 * are NOT recreated; ambiguous matches are held for user review. Each native
 * write and immutable source-to-native ledger binding commit atomically.
 */
final class NativePublisher
{
    public function __construct(
        private readonly NativeTransactionReader $native,
        private readonly CategoryWorkflow $categories,
        private readonly NativePostingDecision $policy,
    ) {}

    /**
     * @return array<string,int|bool>
     */
    public function run(int $company, int $connection, bool $dryRun = true, int $max = 50): array
    {
        if ($company < 1 || $connection < 1 || $max < 1 || $max > 100) {
            throw new DomainException('invalid_native_publishing_scope');
        }
        $binding = DB::table('lcc_banking_connectors_connections as c')
            ->join('accounts as a', static function ($join): void {
                $join->on('a.id','=','c.account_id')->on('a.company_id','=','c.company_id');
            })
            ->where('c.id',$connection)->where('c.company_id',$company)
            ->where('a.type','bank')->where('a.enabled',1)->whereNull('a.deleted_at')
            ->first(['c.id','c.account_id','c.provider','c.state','c.native_post_enabled',
                'a.currency_code']);
        if (!$binding) throw new DomainException('bank_connection_not_accessible');
        if (!$dryRun && ((int)$binding->native_post_enabled !== 1 ||
            $binding->state !== 'enabled')) {
            throw new DomainException('native_publication_not_enabled');
        }
        if ($binding->currency_code !== 'BRL') {
            throw new DomainException('native_publication_currency_unsupported');
        }
        // Fail closed when two bank providers map to the same account, rather
        // than posting the same economic fact twice through distinct feeds.
        $otherFeeds=DB::table('lcc_banking_connectors_connections')
            ->where('company_id',$company)->where('account_id',$binding->account_id)
            ->where('id','!=',$connection)
            ->whereIn('state',['read_only','enabled'])->exists();
        if ($otherFeeds) throw new DomainException('multiple_active_bank_feeds_require_review');

        $entries=DB::table('lcc_banking_connectors_entries')
            ->where('company_id',$company)->where('account_id',$binding->account_id)
            ->where('connection_id',$connection)
            ->where('state','pending')
            // Never starve new transactions behind repeated old review holds.
            ->orderBy('last_publication_attempt_at')->orderBy('movement_date')
            ->orderBy('id')->limit($max)->get(['id']);
        $result=['evaluated'=>0,'created'=>0,'held'=>0,'skipped'=>0,
            'errors'=>0,'dry_run'=>$dryRun];
        if ($entries->isEmpty()) return $result;

        $oldCompany=Company::getCurrent();
        $target=Company::find($company);
        if (!$target) throw new DomainException('bank_company_not_found');
        try {
            $target->makeCurrent();
            foreach ($entries as $record) {
                try {
                    $outcome=$this->one($company,(int)$binding->account_id,
                        $connection,(int)$record->id,$dryRun);
                    $result['evaluated']++;
                    $result[$outcome]++;
                } catch (Throwable) {
                    // Financial errors never log raw provider data, invoices,
                    // identities or SQL. Failed entries remain retriable.
                    if (!$dryRun) {
                        DB::table('lcc_banking_connectors_entries')
                            ->where('company_id',$company)->where('account_id',$binding->account_id)
                            ->where('id',$record->id)->where('state','pending')
                            ->update([
                                'publication_issue_code'=>'native_publication_failed',
                                'last_publication_attempt_at'=>now(),
                                'publication_failures'=>DB::raw(
                                    'CASE WHEN publication_failures<999999 THEN publication_failures+1 ELSE publication_failures END'
                                ),
                            ]);
                    }
                    $result['errors']++;
                }
            }
        } finally {
            if ($oldCompany !== null) $oldCompany->makeCurrent();
            else Company::forgetCurrent();
        }
        return $result;
    }

    private function one(int $company,int $account,int $connection,int $entryId,bool $dryRun): string
    {
        return DB::transaction(function () use ($company,$account,$connection,$entryId,$dryRun): string {
            $entry=DB::table('lcc_banking_connectors_entries')
                ->where('id',$entryId)->where('company_id',$company)
                ->where('account_id',$account)->where('connection_id',$connection)
                ->lockForUpdate()->first();
            if (!$entry) throw new DomainException('bank_entry_not_accessible');
            $row=(array)$entry;
            if ($row['state']!=='pending') return 'skipped';
            $publication=DB::table('lcc_banking_connectors_native_publications')
                ->where('company_id',$company)->where('entry_id',$entryId)->first();
            if ($publication || $entry->linked_transaction_id !== null) return 'skipped';

            // Read all native income/expense candidates near the bank event.
            // Truncation/unknown facts are never interpreted as no candidate.
            $near=$this->native->near($company,$account,(string)$entry->movement_date,45);
            $category=$this->resolvedCategory($company,$account,$row);
            $decision=$this->policy->plan($row,$near,$category,
                $this->possibleOutstandingDocument($company,$row));
            if ($decision['action'] === 'hold') {
                if (!$dryRun) DB::table('lcc_banking_connectors_entries')
                    ->where('id',$entryId)->where('company_id',$company)
                    ->update([
                        'publication_issue_code'=>$decision['reason'],
                        'last_publication_attempt_at'=>now(),
                    ]);
                return 'held';
            }
            if ($decision['action'] === 'skip') return 'skipped';
            if ($dryRun) return 'created';

            $id=$category ?? $this->provisionalCategory($company,(string)$entry->direction);
            // Distinct name/number for the verified source row. Public source
            // identifiers remain HMAC-only in the private stage.
            $number='BC-'. $company . '-' . $entryId;
            if (DB::table('transactions')->where('company_id',$company)
                ->where('number',$number)->whereNull('deleted_at')->exists()) {
                throw new DomainException('native_transaction_number_reserved');
            }
            $amount=number_format(((int)$entry->amount_cents)/100,2,'.','');
            $type=$entry->direction === 'credit' ? 'income':'expense';
            // Native event/number/tax handling must run in this same outer DB
            // transaction. Never write the transactions table via raw SQL.
            $transaction=(new CreateTransaction([
                'company_id'=>$company,
                'type'=>$type,
                'number'=>$number,
                'account_id'=>$account,
                'paid_at'=>$entry->movement_date.' 12:00:00',
                'amount'=>$amount,
                'currency_code'=>'BRL',
                'currency_rate'=>1,
                'category_id'=>$id,
                'contact_id'=>null,
                'document_id'=>null,
                'description'=>$entry->bank_description ?: 'Bank movement',
                'payment_method'=>'offline',
                'reference'=>'bank-feed-'.$entryId,
                'parent_id'=>0,
                'created_from'=>'banking-connectors::bank-feed',
                'created_by'=>null,
            ]))->handle();
            if ((int)$transaction->company_id !== $company ||
                (int)$transaction->account_id !== $account ||
                (string)$transaction->type !== $type) {
                throw new DomainException('native_bank_publication_scope_mismatch');
            }
            DB::table('lcc_banking_connectors_native_publications')->insert([
                'company_id'=>$company,'account_id'=>$account,
                'connection_id'=>$connection,'entry_id'=>$entryId,
                'native_transaction_id'=>(int)$transaction->id,
                'outcome'=>'created','reason_code'=>(string)$decision['reason'],
                'occurred_at'=>now(),
            ]);
            $changed=DB::table('lcc_banking_connectors_entries')
                ->where('id',$entryId)->where('company_id',$company)
                ->where('account_id',$account)->where('connection_id',$connection)
                ->where('state','pending')->whereNull('linked_transaction_id')
                ->update(['state'=>'reviewed',
                    'linked_transaction_id'=>(int)$transaction->id,
                    'category_id'=>$category,
                    'category_source'=>$category===null ? null :
                        (($entry->category_source==='manual')?'manual':'rule'),
                    'publication_issue_code'=>null,
                    'last_publication_attempt_at'=>now(),
                    'updated_at'=>now()]);
            if ($changed !== 1) throw new DomainException('concurrent_native_publication');
            return 'created';
        },3);
    }

    /**
     * A native unpaid invoice/bill can already represent the economic fact
     * even without a transaction row. Never book a duplicate income/expense
     * merely because matching transactions were not found.
     */
    private function possibleOutstandingDocument(int $company,array $entry): bool
    {
        $kind=$entry['direction']==='credit'?'invoice':'bill';
        $date=(new \DateTimeImmutable((string)$entry['movement_date']));
        $amount=((int)$entry['amount_cents'])/100;
        return DB::table('documents')
            ->where('company_id',$company)
            ->where('type',$kind)
            ->where('currency_code',$entry['currency'])
            ->whereNull('deleted_at')
            ->whereNotIn('status',['draft','paid','cancelled'])
            ->whereBetween('issued_at',[
                $date->modify('-90 days')->format('Y-m-d').' 00:00:00',
                $date->modify('+45 days')->format('Y-m-d').' 23:59:59'
            ])
            ->where(function($q) use($amount):void {
                $q->where(function($exact)use($amount):void {
                    $exact->where('amount','>=',$amount-0.005)
                        ->where('amount','<=',$amount+0.005);
                })->orWhere(function($partial)use($amount):void {
                    $partial->where('status','partial')->where('amount','>=',$amount);
                });
            })
            ->exists();
    }

    private function resolvedCategory(int $company,int $account,array $entry): ?int
    {
        $value=null;
        if (($entry['category_source']??null)==='manual') {
            $value=isset($entry['category_id'])?(int)$entry['category_id']:null;
        } else {
            $rule=(new CategoryRuleEngine())->suggest(
                $entry['bank_description']??null,(string)$entry['direction'],
                $this->categories->rules($company,$account)
            );
            $value=$rule['category_id']??null;
        }
        if ($value===null) return null;
        $type=$entry['direction']==='credit'?'income':'expense';
        if (!DB::table('categories')->where('id',$value)->where('company_id',$company)
            ->where('type',$type)->where('enabled',1)->whereNull('deleted_at')->exists()) {
            return null;
        }
        return $value;
    }

    private function provisionalCategory(int $company,string $direction): int
    {
        $type=$direction==='credit'?'income':'expense';
        $code='banking_connectors_pending_'.$type;
        $row=Category::where('company_id',$company)->where('code',$code)
            ->whereNull('deleted_at')->first();
        if ($row) {
            if ($row->type!==$type || !$row->enabled) {
                throw new DomainException('native_pending_category_unavailable');
            }
            return (int)$row->id;
        }
        // Uses the Akaunting native category model; no arbitrary fallback ID.
        $row=Category::create([
            'company_id'=>$company,'code'=>$code,
            'name'=>$type==='income'?'A classificar - Entradas bancarias':
                'A classificar - Saidas bancarias',
            'type'=>$type,'color'=>'#64748B','enabled'=>1,
            'created_from'=>'banking-connectors::bank-feed','created_by'=>null,
        ]);
        return (int)$row->id;
    }
}
