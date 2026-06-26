<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\CategoryKeyword>
 */
class CategoryKeywordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'palavra_chave' => fake()->unique()->word(),
        ];
    }
}
