<?php

namespace Tests\Feature\Edu;

use App\Models\User;
use Database\Seeders\EduGeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EduLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(EduGeoSeeder::class);
        Sanctum::actingAs(User::create(['name' => 'Админ', 'email' => 'admin@example.com', 'password' => 'secret', 'role' => 'admin']));
    }

    public function test_edu_validation_errors_are_in_macedonian(): void
    {
        $this->postJson('/api/edu-regions', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Полето назив е задолжително.')
            ->assertJsonPath('message', 'Полето назив е задолжително.');

        $this->postJson('/api/edu-regions', ['name' => str_repeat('а', 101), 'sort_order' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Полето назив не смее да има повеќе од 100 знаци.')
            ->assertJsonPath('errors.sort_order.0', 'Полето редослед мора да биде цел број.')
            ->assertJsonPath('message', 'Полето назив не смее да има повеќе од 100 знаци. (и уште 1 грешка)');

        // The locale is restored after the edu request.
        $this->assertSame('en', App::getLocale());
    }

    public function test_non_edu_validation_errors_stay_in_english(): void
    {
        // Hit an edu route first to prove the locale doesn't leak.
        $this->postJson('/api/edu-regions', [])->assertStatus(422);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The email field is required.')
            ->assertJsonPath('errors.password.0', 'The password field is required.');

        $this->assertSame('en', App::getLocale());
    }

    public function test_the_locale_is_mk_only_while_handling_an_edu_request(): void
    {
        Route::middleware('api')->get('/api/edu-locale-probe', fn () => ['locale' => App::getLocale()]);
        Route::middleware('api')->get('/api/locale-probe', fn () => ['locale' => App::getLocale()]);

        $this->getJson('/api/edu-locale-probe')->assertExactJson(['locale' => 'mk']);
        $this->getJson('/api/locale-probe')->assertExactJson(['locale' => 'en']);
        $this->assertSame('en', App::getLocale());
    }

    public function test_every_english_validation_key_has_a_macedonian_translation(): void
    {
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $mk = require lang_path('mk/validation.php');

        $missing = $this->missingKeys($en, $mk);
        $this->assertSame([], $missing, 'Missing in lang/mk/validation.php: '.implode(', ', $missing));

        foreach (['auth', 'pagination', 'passwords'] as $file) {
            $enFile = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php");
            $mkFile = require lang_path("mk/{$file}.php");
            $this->assertSame([], $this->missingKeys($enFile, $mkFile), "Missing keys in lang/mk/{$file}.php");
        }
    }

    public function test_placeholders_are_kept_in_macedonian_validation_messages(): void
    {
        $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');
        $mk = require lang_path('mk/validation.php');
        unset($en['custom'], $en['attributes'], $mk['custom'], $mk['attributes']);

        foreach ($this->flatten($en) as $key => $line) {
            preg_match_all('/:[a-z_]+/', $line, $enPlaceholders);
            preg_match_all('/:[a-z_]+/', data_get($mk, $key), $mkPlaceholders);

            $this->assertEqualsCanonicalizing(
                array_unique($enPlaceholders[0]),
                array_unique($mkPlaceholders[0]),
                "Placeholder mismatch in validation.{$key}"
            );
        }
    }

    /** @return list<string> */
    private function missingKeys(array $en, array $mk, string $prefix = ''): array
    {
        $missing = [];

        foreach ($en as $key => $value) {
            $path = $prefix.$key;

            if (! array_key_exists($key, $mk)) {
                $missing[] = $path;
            } elseif (is_array($value)) {
                if (! is_array($mk[$key])) {
                    $missing[] = $path.' (expected array)';
                } else {
                    $missing = [...$missing, ...$this->missingKeys($value, $mk[$key], $path.'.')];
                }
            }
        }

        return $missing;
    }

    /** @return array<string, string> */
    private function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }
}
