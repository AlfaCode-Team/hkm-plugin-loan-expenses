<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanOrganisation\API\Contracts\StaffDirectoryContract;
use Plugins\LoanExpenses\API\Contracts\ExpenseServiceContract;
use Plugins\LoanExpenses\Application\Services\ExpenseService;
use Plugins\LoanExpenses\Infrastructure\Http\Controllers\ExpenseController;
use Plugins\LoanExpenses\Infrastructure\Persistence\ExpenseRepository;
use Plugins\LoanExpenses\Infrastructure\Persistence\LegacyIdAllocator;
use Plugins\LoanSync\API\Contracts\SyncRevisionContract;

/**
 * Expenses — owns 'expenses.management'.
 *
 * Everything is request-scoped, so the DatabasePort it receives is the
 * REQUEST's: on a tenancy host that is the business's own database. A binding
 * in the core container would pin the central connection for the life of the
 * worker and report one business's spending to another.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'expenses.management';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['database.management', 'sync.offline', 'organisation.structure'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [ExpenseServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            ExpenseRepository::class,
            static fn($c): ExpenseRepository => new ExpenseRepository($c->make(DatabasePort::class)),
        );

        $container->bindInternal(
            LegacyIdAllocator::class,
            static fn($c): LegacyIdAllocator => new LegacyIdAllocator($c->make(DatabasePort::class)),
        );

        $container->bind(
            ExpenseServiceContract::class,
            static fn($c): ExpenseService => new ExpenseService(
                expenses:    $c->make(ExpenseRepository::class),
                ids:         $c->make(LegacyIdAllocator::class),
                revisions:   $c->make(SyncRevisionContract::class),
                transaction: $c->make(TransactionManager::class),
                // The scaffold binds no ClockPort; an injectable clock is what
                // makes date-range reporting testable, so fall back rather than
                // fail to resolve.
                clock:       $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock(),
                identity:    $c->make(Identity::class),
                staff:       $c->make(StaffDirectoryContract::class),
            ),
        );

        $container->bind(
            ExpenseController::class,
            static fn($c): ExpenseController => new ExpenseController($c->make(ExpenseServiceContract::class)),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Routes are declared in module.json; nothing to hook.
    }
}
