<?php

declare(strict_types=1);

use App\Controllers\RecurringExpenseController;
use App\Http\HttpException;
use App\Recurring\RecurringExpenseService;

require __DIR__ . '/../src/bootstrap.php';

$service = new RecurringExpenseService(new PDO('sqlite::memory:'));
assertSame('2026-06', $service->previousMonth('2026-07'), 'previousMonth rolls back one month');
assertSame('2025-12', $service->previousMonth('2026-01'), 'previousMonth rolls across years');

$serviceReflection = new ReflectionClass(RecurringExpenseService::class);
$overlapMethod = $serviceReflection->getMethod('monthRangesOverlap');
assertSame(true, $overlapMethod->invoke($service, '2026-01', '2026-06', '2026-06', null), 'touching month windows overlap');
assertSame(false, $overlapMethod->invoke($service, '2026-01', '2026-05', '2026-06', null), 'separated month windows do not overlap');

$controllerReflection = new ReflectionClass(RecurringExpenseController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$generatedActionMethod = $controllerReflection->getMethod('validatedGeneratedTransactionAction');
assertSame('reject', $generatedActionMethod->invoke($controller, 'reject'), 'reject is accepted');
assertSame('update_linked_transaction', $generatedActionMethod->invoke($controller, 'update_linked_transaction'), 'update_linked_transaction is accepted by validator');

expectHttpException(
    static fn() => $generatedActionMethod->invoke($controller, 'mutate_generated'),
    422,
    'invalid generated_transaction_action is rejected'
);

$monthMethod = $controllerReflection->getMethod('validatedMonth');
assertSame('2026-07', $monthMethod->invoke($controller, '2026-07', 'effective_month'), 'valid effective month passes');
expectHttpException(
    static fn() => $monthMethod->invoke($controller, '2026-13', 'effective_month'),
    422,
    'invalid effective month is rejected'
);

fwrite(STDOUT, "Recurring schedule change validation smoke test passed\n");

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fail(sprintf(
            '%s: expected %s, got %s',
            $label,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function expectHttpException(callable $callback, int $status, string $label): void
{
    try {
        $callback();
    } catch (ReflectionException $e) {
        fail($label . ': reflection failed: ' . $e->getMessage());
    } catch (HttpException $e) {
        if ($e->status !== $status) {
            fail($label . ': expected status ' . $status . ', got ' . $e->status);
        }

        return;
    }

    fail($label . ': expected HttpException');
}

function fail(string $message): never
{
    fwrite(STDERR, "Recurring schedule change validation smoke test failed: {$message}\n");
    exit(1);
}
