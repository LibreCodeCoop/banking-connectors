<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Modules\BankingConnectors\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\Events\Common\SearchStringApplying;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\BankingConnectors\Application\ReadOnlySynchronizer;
use Modules\BankingConnectors\Application\NativePublisher;
use Modules\BankingConnectors\Domain\NativePostingDecision;
use Modules\BankingConnectors\Console\Commands\PublishConnection;
use Modules\BankingConnectors\Application\ReviewDecisionService;
use Modules\BankingConnectors\Application\ReviewPreview;
use Modules\BankingConnectors\Application\ConnectionControl;
use Modules\BankingConnectors\Application\CategoryWorkflow;
use Modules\BankingConnectors\Application\CategoryLearningAdvisor;
use Modules\BankingConnectors\Application\BankConnectorRegistry;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobConnector;
use Modules\BankingConnectors\Application\HealthSnapshot;
use Modules\BankingConnectors\Console\Commands\PollConnection;
use Modules\BankingConnectors\Console\Commands\PollAll;
use Modules\BankingConnectors\Console\Commands\BankHealth;
use Modules\BankingConnectors\Console\Commands\ProbeSicoobCredentials;
use Modules\BankingConnectors\Domain\AkauntingTransactionMapper;
use Modules\BankingConnectors\Domain\MatchingEngine;
use Modules\BankingConnectors\Infrastructure\NativeTransactionReader;
use Modules\BankingConnectors\Infrastructure\PdoEntryStage;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobStatementMapper;
use Modules\BankingConnectors\Connectors\Sicoob\SicoobCredentialResolver;
use Modules\BankingConnectors\Http\ViewComposers\AccountActions;
use Modules\BankingConnectors\Http\ViewComposers\NativeTransactionsIndex;
use Modules\BankingConnectors\Http\ViewComposers\NativeBankSearch;
use Modules\BankingConnectors\Http\ViewComposers\NativeTransactionActions;

/**
 * Registers the module's dedicated tables and read-only domain services.
 * No banking jobs, public routes or financial record mutations are enabled.
 */
final class Main extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BankConnectorRegistry::class, static function (): BankConnectorRegistry {
            $registry = new BankConnectorRegistry();
            $registry->register(new SicoobConnector());
            return $registry;
        });
        $this->loadRoutesFrom(__DIR__ . '/../Routes/admin.php');
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        $this->app->bind(MatchingEngine::class, MatchingEngine::class);
        $this->app->bind(AkauntingTransactionMapper::class, AkauntingTransactionMapper::class);
        $this->app->bind(NativeTransactionReader::class, static function (): NativeTransactionReader {
            return new NativeTransactionReader(
                DB::connection()->getPdo(),
                new AkauntingTransactionMapper(),
                DB::connection()->getTablePrefix(),
            );
        });
        $this->app->bind(ReviewPreview::class, ReviewPreview::class);
        $this->app->bind(NativePostingDecision::class, NativePostingDecision::class);
        $this->app->bind(NativePublisher::class, NativePublisher::class);
        $this->app->bind(ReviewDecisionService::class, static function (): ReviewDecisionService {
            return new ReviewDecisionService(
                DB::connection()->getPdo(),
                app(NativeTransactionReader::class),
                app(MatchingEngine::class),
                DB::connection()->getTablePrefix(),
            );
        });
        $this->app->bind(HealthSnapshot::class, static function (): HealthSnapshot {
            return new HealthSnapshot(DB::connection()->getPdo(), DB::connection()->getTablePrefix());
        });
        $this->app->bind(ConnectionControl::class, static function (): ConnectionControl {
            return new ConnectionControl(DB::connection()->getPdo(), DB::connection()->getTablePrefix());
        });
        $this->app->bind(CategoryLearningAdvisor::class, static function (): CategoryLearningAdvisor {
            return new CategoryLearningAdvisor(DB::connection()->getPdo(), DB::connection()->getTablePrefix());
        });
        $this->app->bind(CategoryWorkflow::class, static function (): CategoryWorkflow {
            return new CategoryWorkflow(DB::connection()->getPdo(), DB::connection()->getTablePrefix());
        });
        $this->app->bind(ReadOnlySynchronizer::class, static function (): ReadOnlySynchronizer {
            return new ReadOnlySynchronizer(
                DB::connection()->getPdo(),
                app(PdoEntryStage::class),
                DB::connection()->getTablePrefix(),
            );
        });
        $this->app->bind(SicoobStatementMapper::class, SicoobStatementMapper::class);
        $this->app->bind(SicoobCredentialResolver::class, SicoobCredentialResolver::class);
        $this->app->bind(PdoEntryStage::class, static function (): PdoEntryStage {
            return new PdoEntryStage(
                DB::connection()->getPdo(),
                DB::connection()->getTablePrefix(),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'banking-connectors');
        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'banking-connectors');
        // Integrate with each native bank account's action dropdown rather than
        // publishing a second sidebar entry. The composer checks ACL and tenant.
        View::composer('components.accounts.index.accounts', AccountActions::class);
        // Reuse native tabs/search component and the existing row hover menu.
        // Only the Transactions route is augmented; the original ledger and
        // all other Akaunting components remain unaffected.
        View::composer('components.transactions.index.content', NativeTransactionsIndex::class);
        View::composer('components.transactions.index.transaction',
            NativeTransactionActions::class . '@prepare');
        View::composer('components.table.actions',
            NativeTransactionActions::class . '@append');
        View::composer('components.search-string',
            NativeBankSearch::class . '@extendSearch');
        Event::listen(SearchStringApplying::class,
            NativeBankSearch::class . '@applyNativeSearch');
        if ($this->app->runningInConsole()) {
            $this->commands([
                PollConnection::class, PollAll::class, PublishConnection::class,
                BankHealth::class, ProbeSicoobCredentials::class,
            ]);
            // Explicit opt-in only; installing the module does not contact
            // banks or enable any connection. Akaunting's existing scheduler
            // remains the sole scheduling infrastructure.
            if (getenv('BANKING_CONNECTORS_AUTO_CREDENTIAL_PROBE') === 'yes') {
                $this->app->booted(static function ($app): void {
                    $app->make(Schedule::class)
                        ->command('banking-connectors:sicoob-credential-probe')
                        ->dailyAt('04:00');
                });
            }
            if (getenv('BANKING_CONNECTORS_AUTO_POLL_ENABLED') === 'yes') {
                $this->app->booted(static function ($app): void {
                    // Explicit user refreshes get a one-minute fallback if
                    // the Redis worker is unavailable. Unattended polling
                    // remains 15-minute to respect bank API throttling.
                    $app->make(Schedule::class)
                        ->command('banking-connectors:poll-all --pending-only')
                        ->everyMinute()
                        ->withoutOverlapping();
                    $app->make(Schedule::class)
                        ->command('banking-connectors:poll-all')
                        ->everyFifteenMinutes()
                        ->withoutOverlapping();
                });
            }
        }
    }
}
