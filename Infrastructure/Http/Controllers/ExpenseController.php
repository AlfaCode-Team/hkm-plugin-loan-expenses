<?php

declare(strict_types=1);

namespace Plugins\LoanExpenses\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\LoanExpenses\API\Contracts\ExpenseServiceContract;

/**
 * Branch spending. Thin by rule: parse, delegate, translate.
 */
final class ExpenseController
{
    public function __construct(
        private readonly ExpenseServiceContract $expenses,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::json($this->expenses->list($request->queryAll()));
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->expenses->show((int) $id));
    }

    public function store(Request $request): Response
    {
        return Response::json($this->expenses->record($request->all()), 201);
    }

    public function update(Request $request, string $id): Response
    {
        return Response::json($this->expenses->update((int) $id, $request->all()));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->expenses->delete((int) $id);

        return Response::noContent();
    }

    public function reportByCategory(Request $request): Response
    {
        return Response::json($this->expenses->totalsByCategory($request->queryAll()));
    }

    public function reportByBranch(Request $request): Response
    {
        return Response::json($this->expenses->totalsByBranch($request->queryAll()));
    }

    public function reportDaily(Request $request): Response
    {
        return Response::json($this->expenses->dailyTotalsByBranch($request->queryAll()));
    }
}
