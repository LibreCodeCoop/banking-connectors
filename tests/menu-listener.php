<?php
// SPDX-FileCopyrightText: 2026 LibreCode Coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace App\Traits {
    if (!trait_exists(Modules::class, false)) {
        trait Modules {
            public function moduleIsEnabled(string $alias): bool {
                return $alias === 'banking-connectors';
            }
        }
    }
}
namespace App\Events\Menu {
    class AdminCreated {
        public function __construct(public object $menu) {}
    }
}
namespace {
    final class FakeBankingAuth {
        public static ?object $current = null;
        public function user(): ?object { return self::$current; }
    }
    function auth(): FakeBankingAuth {
        return new FakeBankingAuth();
    }
    function trans(string $key): string {
        return $key;
    }
    require dirname(__DIR__) . '/Listeners/AddToAdminMenu.php';

    $events = new class {
        public array $calls = [];
        public function route(
            string $route, string $title, array $params,
            int $order, array $options,
        ): void {
            $this->calls[] = compact('route','title','params','order','options');
        }
    };
    $listener = new \Modules\BankingConnectors\Listeners\AddToAdminMenu();
    FakeBankingAuth::$current = null;
    $listener->handle(new \App\Events\Menu\AdminCreated($events));
    if ($events->calls !== []) throw new \RuntimeException('Unauthenticated menu disclosure');

    FakeBankingAuth::$current = new class {
        public function can(string $permission): bool {
            return $permission === 'read-banking-accounts';
        }
    };
    $listener->handle(new \App\Events\Menu\AdminCreated($events));
    if ($events->calls !== []) throw new \RuntimeException('Restricted menu disclosure');

    FakeBankingAuth::$current = new class {
        public function can(string $permission): bool {
            return in_array($permission, ['read-banking-accounts','read-banking-transactions'], true);
        }
    };
    $listener->handle(new \App\Events\Menu\AdminCreated($events));
    if (count($events->calls) !== 1
        || $events->calls[0]['route'] !== 'banking-connectors.review.index'
        || $events->calls[0]['options']['icon'] !== 'account_balance') {
        throw new \RuntimeException('Authorized bank menu route missing');
    }

    $disabled = new class extends \Modules\BankingConnectors\Listeners\AddToAdminMenu {
        public function moduleIsEnabled(string $alias): bool { return false; }
    };
    $disabled->handle(new \App\Events\Menu\AdminCreated($events));
    if (count($events->calls) !== 1) {
        throw new \RuntimeException('Disabled banking module leaked a menu item');
    }
    echo "PASS native banking sidebar item requires enabled module and read permissions\n";
}
