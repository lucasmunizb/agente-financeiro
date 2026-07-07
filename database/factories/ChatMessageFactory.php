<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    protected $model = ChatMessage::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => 'user',
            'body' => $this->faker->sentence(),
            'fontes' => null,
            'aprovado' => null,
            'tem_anexo' => false,
        ];
    }
}
