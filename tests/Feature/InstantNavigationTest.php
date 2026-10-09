<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInstantNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InstantNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::post('/_navigate/save', fn (Request $request) => $request->validate(['name' => ['required']]) ? redirect('/_navigate/saved') : null);
            Route::post('/_navigate/pay', fn () => redirect()->away('https://pay.example.com/checkout/123'));
        });
    }

    /** @return array<string, string> */
    protected function navigateHeaders(?string $referer = null): array
    {
        return [HandleInstantNavigation::HEADER => '1', 'Referer' => $referer ?? url('/contacts/create')];
    }

    public function test_a_failed_form_sent_in_page_goes_back_to_the_page_it_was_on(): void
    {
        $this->withHeaders($this->navigateHeaders())
            ->post('/_navigate/save', [])
            ->assertRedirect(url('/contacts/create'))
            ->assertSessionHasErrors('name');
    }

    public function test_a_failed_form_goes_back_to_the_page_on_screen_rather_than_the_address_bar(): void
    {
        $this->withHeaders($this->navigateHeaders(url('/dashboard')) + [HandleInstantNavigation::PAGE_HEADER => url('/contacts/create')])
            ->post('/_navigate/save', [])
            ->assertRedirect(url('/contacts/create'))
            ->assertSessionHasErrors('name');
    }

    public function test_a_page_header_naming_another_site_is_ignored(): void
    {
        $this->withHeaders($this->navigateHeaders(url('/dashboard')) + [HandleInstantNavigation::PAGE_HEADER => 'https://evil.example.com/steal'])
            ->post('/_navigate/save', [])
            ->assertRedirect(url('/dashboard'));
    }

    public function test_a_successful_form_sent_in_page_redirects_as_usual(): void
    {
        $this->withHeaders($this->navigateHeaders())
            ->post('/_navigate/save', ['name' => 'Chikondi'])
            ->assertRedirect('/_navigate/saved');
    }

    public function test_a_redirect_to_another_site_is_handed_back_as_a_header(): void
    {
        $this->withHeaders($this->navigateHeaders())
            ->post('/_navigate/pay')
            ->assertNoContent()
            ->assertHeader(HandleInstantNavigation::LOCATION_HEADER, 'https://pay.example.com/checkout/123');
    }

    public function test_ordinary_requests_are_left_alone(): void
    {
        $this->post('/_navigate/pay')->assertRedirect('https://pay.example.com/checkout/123');
    }
}
