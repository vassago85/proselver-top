<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CompanyFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();
        return [
            'uuid' => Str::uuid(),
            'name' => $name,
            'normalized_name' => Str::lower(Str::ascii($name)),
            'address' => fake()->address(),
            'vat_number' => '4' . fake()->numerify('#########'),
            'billing_email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'is_active' => true,
        ];
    }
}
