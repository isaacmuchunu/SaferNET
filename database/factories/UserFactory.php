<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Institution;
use App\Models\Subcounty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Cde,
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function cde(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Cde, 'subcounty_id' => null, 'institution_id' => null]);
    }

    public function scde(?Subcounty $subcounty = null): static
    {
        return $this->state(fn (): array => [
            'role' => UserRole::Scde,
            'subcounty_id' => $subcounty?->id ?? Subcounty::factory(),
            'institution_id' => null,
        ]);
    }

    public function hoi(?Institution $institution = null): static
    {
        return $this->forInstitution($institution, UserRole::Hoi);
    }

    public function clm(?Institution $institution = null): static
    {
        return $this->forInstitution($institution, UserRole::Clm);
    }

    public function service(?Institution $institution = null): static
    {
        return $this->forInstitution($institution, UserRole::Service);
    }

    private function forInstitution(?Institution $institution, UserRole $role): static
    {
        $institution ??= Institution::factory()->create();

        return $this->state(fn (): array => [
            'role' => $role,
            'institution_id' => $institution->id,
            'subcounty_id' => $institution->subcounty_id,
        ]);
    }
}
