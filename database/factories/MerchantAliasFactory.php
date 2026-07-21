<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\MerchantAlias;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantAlias>
 */
class MerchantAliasFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'alias' => fake()->unique()->word(),
        ];
    }
}
