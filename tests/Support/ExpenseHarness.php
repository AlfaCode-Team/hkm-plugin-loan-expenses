<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanExpenses\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\LoanOrganisation\Infrastructure\Persistence\StaffDirectory;
use Plugins\LoanExpenses\Application\Services\ExpenseService;
use Plugins\LoanExpenses\Infrastructure\Persistence\ExpenseRepository;
use Plugins\LoanExpenses\Infrastructure\Persistence\LegacyIdAllocator;
use Plugins\LoanSync\Infrastructure\Persistence\RevisionRepository;
use Tests\Unit\Plugins\LoanExpenses\Support\TestDatabase;

final class ExpenseHarness
{
    /** The staff id the seeded user maps to — intentionally NOT the user id. */
    public const int STAFF_ID = 907;

    public readonly DatabasePort $db;
    public readonly ExpenseService $expenses;
    public readonly RevisionRepository $revisions;
    public readonly TestDatabase $database;

    public function __construct(string $userId = '7')
    {
        // SQLite unless HKM_TEST_DB says otherwise. The same tests then run
        // against MySQL, PostgreSQL or SQL Server without a second suite.
        //
        // The schema is assembled from the PLUGINS that own each table, so
        // this suite tests against the same migrations a deployment runs.
        $this->database = TestDatabase::provision('loan_expenses', [
            \Plugins\LoanSync\Provider::class,
            \Plugins\LoanOrganisation\Provider::class,
            \Plugins\LoanExpenses\Provider::class,
        ]);
        $this->db        = $this->database->port;
        $this->revisions = new RevisionRepository($this->db);


        // `recorded_by` is a staff_members.id, so the signed-in USER must map
        // to a staff row. The staff id is deliberately DIFFERENT from the user
        // id, so a test that confuses the two fails instead of passing by luck.
        $this->db->execute(
            'INSERT INTO staff_members (id, user_id, name, role, branch_id, banned, rev, created_at, updated_at)'
            . ' VALUES (:id, :user, :name, :role, :branch, :banned, 0, :created, :updated)',
            [
                'id'   => self::STAFF_ID,
                // `branch_id` and `banned` are BOUND, not written into the SQL
                // as 1 and 0: PostgreSQL refuses an integer literal in a
                // boolean column, while a bound value is sent as text and
                // coerced. A guest has no account at all, so the seeded staff
                // row points at nothing rather than at the empty string.
                'branch' => 1,
                'banned' => false,
                'user'   => $userId === '' ? null : $userId,
                'name' => 'Ruth Achieng',
                'role' => 'officer',
                'created' => '2026-11-13 08:00:00',
                'updated' => '2026-11-13 08:00:00',
            ],
        );

        $this->expenses = new ExpenseService(
            expenses:    new ExpenseRepository($this->db),
            ids:         new LegacyIdAllocator($this->db),
            revisions:   $this->revisions,
            transaction: new TransactionManager($this->db),
            clock:       new SystemClock(),
            staff:       new StaffDirectory($this->db),
            identity:    new Identity(
                userId:      $userId,
                tenantId:    'tenant-test',
                roles:       ['manager'],
                permissions: [],
                tokenType:   'jwt',
            ),
        );
    }

    /** @return array<string, mixed> */
    public static function input(
        string $category = 'boda_fare',
        int $amount = 5000,
        string $spentOn = '2026-11-10',
        int $branchId = 1,
    ): array {
        return [
            'branch_id'      => $branchId,
            'category'       => $category,
            'amount'         => $amount,
            'description'    => 'boda to the trading centre',
            'payment_method' => 'cash',
            'spent_on'       => $spentOn,
        ];
    }

    public function destroy(): void
    {
        $this->database->destroy();
    }
}
