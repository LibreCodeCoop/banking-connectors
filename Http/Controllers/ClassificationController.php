<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\BankingConnectors\Application\CategoryWorkflow;

/** Explicit company- and account-scoped financial classification controls. */
final class ClassificationController extends Controller
{
    public function __construct(private readonly CategoryWorkflow $workflow) {}

    public function createRule(Request $request): JsonResponse|RedirectResponse
    {
        [$company,$account] = $this->scope($request, false);
        $v=$request->validate([
            'direction'=>'required|in:credit,debit',
            'description_contains'=>'required|string|min:4|max:120',
            'category_id'=>'required|integer|min:1',
            'priority'=>'required|integer|min:1|max:999',
        ]);
        return $this->respond($request,$company,$account,'rules',function () use($company,$account,$v): array {
            $id=$this->workflow->createRule($company,$account,(int)auth()->id(),
                (string)$v['direction'],(string)$v['description_contains'],
                (int)$v['category_id'],(int)$v['priority']);
            return ['rule_id'=>$id];
        });
    }

    public function toggleRule(Request $request): JsonResponse|RedirectResponse
    {
        [$company,$account]=$this->scope($request,false);
        $rule=filter_var($request->route('ruleId'),FILTER_VALIDATE_INT);
        abort_unless($rule!==false && $rule>0,404);
        $v=$request->validate(['enabled'=>'required|in:0,1']);
        return $this->respond($request,$company,$account,'rules',function () use($company,$account,$rule,$v): array {
            $this->workflow->enableRule($company,$account,(int)$rule,(string)$v['enabled']==='1');
            return ['rule_id'=>(int)$rule,'enabled'=>(string)$v['enabled']==='1'];
        });
    }

    public function classify(Request $request): JsonResponse|RedirectResponse
    {
        [$company,$account]=$this->scope($request,true);
        $entry=filter_var($request->route('entryId'),FILTER_VALIDATE_INT);
        abort_unless($entry!==false && $entry>0,404);
        $v=$request->validate([
            'category_id'=>'nullable|integer|min:1',
            'apply_native'=>'nullable|in:0,1',
            'expected_native_category_id'=>'nullable|integer|min:1',
            'request_key'=>['required','regex:/^[a-f0-9]{32}$/D'],
            'return_filter'=>'nullable|in:pending,reviewed,dismissed,all,needs_action,uncategorized,unlinked,conflict,imported,posted,matched,ignored',
            'return_to'=>'nullable|in:transactions',
        ]);
        $category=isset($v['category_id'])?(int)$v['category_id']:null;
        $expected=isset($v['expected_native_category_id'])
            ?(int)$v['expected_native_category_id']:null;
        return $this->respond($request,$company,$account,'review',function () use($company,$account,$entry,$category,$v,$expected): array {
            return $this->workflow->classify(
                $company,$account,(int)$entry,(int)auth()->id(),$category,
                ($v['apply_native']??'0')==='1',$expected,(string)$v['request_key']
            );
        });
    }

    private function scope(Request $request,bool $requiresTransactionWrite): array
    {
        abort_unless(auth()->check() && auth()->id()!==null,401);
        abort_unless(
            auth()->user()->can('read-banking-accounts') &&
            auth()->user()->can('read-banking-transactions') &&
            auth()->user()->can('update-banking-accounts') &&
            (!$requiresTransactionWrite || auth()->user()->can('update-banking-transactions')),
            403
        );
        $company=function_exists('company_id')?(int)company_id():0;
        $account=filter_var($request->route('accountId'),FILTER_VALIDATE_INT);
        abort_unless($company>0 && $account!==false && $account>0,404);
        $valid=DB::table('accounts')->where('id',$account)
            ->where('company_id',$company)->where('type','bank')
            ->where('enabled',1)->whereNull('deleted_at')->exists();
        abort_unless($valid,404);
        return [$company,(int)$account];
    }

    /** @param \Closure():array<string,mixed> $operation */
    private function respond(
        Request $request,int $company,int $account,string $tab,\Closure $operation
    ): JsonResponse|RedirectResponse {
        try {
            $result=$operation();
        } catch (DomainException|InvalidArgumentException $error) {
            // Domain messages are fixed codes. No bank details or secrets.
            if ($request->expectsJson()) {
                return response()->json(['error'=>$error->getMessage()],409);
            }
            return back()->withErrors(['classification'=>$error->getMessage()]);
        }
        if ($request->expectsJson()) {
            return response()->json(['data'=>$result],200);
        }
        if ($tab==='review' && $request->input('return_to')==='transactions') {
            $filter=(string)$request->input('return_filter','needs_action');
            $valid=['needs_action','uncategorized','unlinked','conflict',
                'imported','posted','matched','ignored'];
            $filter=in_array($filter,$valid,true)?$filter:'needs_action';
            return redirect()->route('transactions.index',[
                'company_id'=>$company,
                'search'=>'bank_movement:'.$filter.' account_id:'.$account,
            ])->with('success',trans('banking-connectors::general.classification_saved'));
        }
        return redirect()->route($tab==='review'
            ?'banking-connectors.transactions.review'
            :'banking-connectors.account.show',[
            'company_id'=>$company,'accountId'=>$account,'tab'=>$tab,
            'filter'=>$tab==='review'?(string)$request->input('return_filter','pending'):null,
        ])->with('success', trans('banking-connectors::general.classification_saved'));
    }
}
