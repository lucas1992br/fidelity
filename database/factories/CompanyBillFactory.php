<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyBill>
 */
class CompanyBillFactory extends Factory
{
    /**
     * Define the model's default state: the current month at the company's fixed amount.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'reference_month' => today()->startOfMonth()->toDateString(),
            'amount' => fn (array $attributes): string => Company::withTrashed()->findOrFail((int) $attributes['company_id'])->monthly_amount,
        ];
    }
}
