<?php

declare(strict_types=1);

use App\Controllers\BudgetSettingsController;
use App\Http\HttpException;

require __DIR__ . '/../src/bootstrap.php';

$validator = new BudgetSettingsValidatorHarness();

$settings = $validator->validate([
    'monthly_income' => '6200.00',
    'allocation_mode' => 'percent',
    'needs_percent' => '50.00',
    'wants_percent' => '30.00',
    'savings_percent' => '20.00',
]);
assertField($settings, 'income_source_type', 'monthly', 'old payload defaults to monthly income');
assertField($settings, 'primary_monthly_income', '6200.00', 'old payload backfills primary monthly income');
assertField($settings, 'side_income_type', 'none', 'old payload has no side income');
assertField($settings, 'needs_percent', '50.00', 'old payload preserves percent allocation');

$settings = $validator->validate([
    'monthly_income' => '866.67',
    'income_source_type' => 'hourly',
    'primary_monthly_income' => null,
    'primary_hourly_rate' => '20.00',
    'primary_weekly_hours' => '10.00',
    'side_income_type' => 'none',
    'side_income_label' => 'Ignored label',
    'side_monthly_income' => null,
    'side_hourly_rate' => null,
    'side_weekly_hours' => null,
    'allocation_mode' => 'percent',
    'needs_percent' => '50.00',
    'wants_percent' => '30.00',
    'savings_percent' => '20.00',
]);
assertField($settings, 'primary_monthly_income', null, 'hourly primary income leaves monthly field null');
assertField($settings, 'primary_hourly_rate', '20.00', 'hourly primary income stores rate');
assertField($settings, 'primary_weekly_hours', '10.00', 'hourly primary income stores hours');
assertField($settings, 'side_income_label', null, 'side label is discarded when side income is none');

$settings = $validator->validate([
    'monthly_income' => '770.00',
    'income_source_type' => 'hourly',
    'primary_monthly_income' => null,
    'primary_hourly_rate' => '15.00',
    'primary_weekly_hours' => '10.00',
    'side_income_type' => 'monthly',
    'side_income_label' => 'Tutoring',
    'side_monthly_income' => '120.00',
    'side_hourly_rate' => null,
    'side_weekly_hours' => null,
    'allocation_mode' => 'amount',
    'needs_amount' => '385.00',
    'wants_amount' => '231.00',
    'savings_amount' => '154.00',
]);
assertField($settings, 'monthly_income', '770.00', 'hourly primary plus monthly side income computes monthly total');
assertField($settings, 'side_income_label', 'Tutoring', 'active side income preserves label');
assertField($settings, 'allocation_mode', 'amount', 'amount allocation is accepted');

$settings = $validator->validate([
    'monthly_income' => '628.33',
    'income_source_type' => 'hourly',
    'primary_monthly_income' => null,
    'primary_hourly_rate' => '12.00',
    'primary_weekly_hours' => '10.00',
    'side_income_type' => 'hourly',
    'side_income_label' => 'Babysitting',
    'side_monthly_income' => null,
    'side_hourly_rate' => '25.00',
    'side_weekly_hours' => '1.00',
    'allocation_mode' => 'percent',
    'needs_percent' => '50.00',
    'wants_percent' => '30.00',
    'savings_percent' => '20.00',
]);
assertField($settings, 'side_hourly_rate', '25.00', 'hourly side income stores rate');
assertField($settings, 'side_weekly_hours', '1.00', 'hourly side income stores hours');

$validator->expectValidationError(
    [
        'monthly_income' => '866.66',
        'income_source_type' => 'hourly',
        'primary_monthly_income' => null,
        'primary_hourly_rate' => '20.00',
        'primary_weekly_hours' => '10.00',
        'side_income_type' => 'none',
        'allocation_mode' => 'percent',
        'needs_percent' => '50.00',
        'wants_percent' => '30.00',
        'savings_percent' => '20.00',
    ],
    'monthly_income',
    'mismatched income breakdown is rejected'
);

$validator->expectValidationError(
    [
        'monthly_income' => '6200.00',
        'allocation_mode' => 'percent',
        'needs_percent' => '50.00',
        'wants_percent' => '30.00',
        'savings_percent' => '19.99',
    ],
    'allocation_mode',
    'percent allocation must total 100.00'
);

$validator->expectValidationError(
    [
        'monthly_income' => '6200.00',
        'allocation_mode' => 'amount',
        'needs_amount' => '3100.00',
        'wants_amount' => '1860.00',
        'savings_amount' => '1239.99',
    ],
    'allocation_mode',
    'amount allocation must total monthly income'
);

$validator->expectValidationError(
    [
        'monthly_income' => '0.00',
        'income_source_type' => 'hourly',
        'primary_hourly_rate' => '0.00',
        'primary_weekly_hours' => '10.00',
        'side_income_type' => 'none',
        'allocation_mode' => 'percent',
        'needs_percent' => '50.00',
        'wants_percent' => '30.00',
        'savings_percent' => '20.00',
    ],
    'primary_hourly_rate',
    'hourly rate must be positive'
);

fwrite(STDOUT, "BudgetSettingsController validation smoke test passed\n");

final class BudgetSettingsValidatorHarness
{
    private object $controller;
    private ReflectionMethod $method;

    public function __construct()
    {
        $reflection = new ReflectionClass(BudgetSettingsController::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();
        $this->method = $reflection->getMethod('settingsFromPayload');
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,string|null>
     */
    public function validate(array $payload): array
    {
        $result = $this->method->invoke($this->controller, $payload);
        if (!is_array($result)) {
            fail('validator did not return an array');
        }

        return $result;
    }

    /** @param array<string,mixed> $payload */
    public function expectValidationError(array $payload, string $field, string $label): void
    {
        try {
            $this->validate($payload);
        } catch (HttpException $e) {
            if ($e->status !== 422) {
                fail($label . ': expected status 422, got ' . $e->status);
            }

            foreach ($e->details() as $detail) {
                if (($detail['field'] ?? '') === $field) {
                    return;
                }
            }

            fail($label . ': expected validation error for field ' . $field);
        }

        fail($label . ': expected validation error');
    }
}

/** @param array<string,string|null> $settings */
function assertField(array $settings, string $field, ?string $expected, string $label): void
{
    $actual = $settings[$field] ?? null;
    if ($actual !== $expected) {
        fail(sprintf(
            '%s: expected %s to be %s, got %s',
            $label,
            $field,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "BudgetSettingsController validation smoke test failed: {$message}\n");
    exit(1);
}
