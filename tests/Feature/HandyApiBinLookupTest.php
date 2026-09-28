<?php

namespace XLaravel\Payline\BinLookup\HandyApi\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use XLaravel\Payline\BinLookup\HandyApi\HandyApiBinLookup;
use XLaravel\Payline\BinLookup\HandyApi\Tests\TestCase;
use XLaravel\Payline\Enums\CardCategory;
use XLaravel\Payline\Enums\CardScheme;
use XLaravel\Payline\Enums\CardType;

class HandyApiBinLookupTest extends TestCase
{
    private HandyApiBinLookup $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new HandyApiBinLookup();
    }

    public function test_lookup_fills_every_field_handyapi_reports(): void
    {
        $this->fakeBin();

        $profile = $this->provider->lookup('45717360');

        $this->assertSame('45717360', $profile->bin);
        $this->assertSame(CardScheme::Visa, $profile->scheme);
        $this->assertSame(CardType::Debit, $profile->type);
        $this->assertSame('DANKORT', $profile->productType);
        $this->assertSame('JYSKE BANK', $profile->issuer);
        $this->assertSame('DK', $profile->issuerCountry);
        $this->assertSame('handyapi', $profile->source);
        $this->assertSame('Europe', $profile->raw['Country']['Cont']);
    }

    public function test_a_corporate_tier_is_read_as_a_commercial_card(): void
    {
        Http::fake(['*' => Http::response([
            'Status' => 'SUCCESS',
            'Scheme' => 'AMERICAN EXPRESS',
            'Type' => 'CREDIT',
            'Issuer' => 'AMERICAN EXPRESS US CARS',
            'CardTier' => 'CORPORATE',
            'Country' => ['A2' => 'US'],
        ])]);

        $profile = $this->provider->lookup('37828224');

        $this->assertSame(CardScheme::Amex, $profile->scheme);
        $this->assertSame(CardCategory::Commercial, $profile->category);
        $this->assertSame('CORPORATE', $profile->productType);
    }

    public function test_a_tier_that_names_no_category_leaves_it_open(): void
    {
        Http::fake(['*' => Http::response([
            'Status' => 'SUCCESS',
            'Scheme' => 'MASTERCARD',
            'Type' => 'CREDIT',
            'CardTier' => 'STANDARD',
            'Country' => ['A2' => 'TR'],
        ])]);

        $profile = $this->provider->lookup('51015200');

        $this->assertNull($profile->category);
        $this->assertSame('STANDARD', $profile->productType);
    }

    public function test_the_card_family_stays_open_because_handyapi_reports_none(): void
    {
        $this->fakeBin();

        $profile = $this->provider->lookup('45717360');

        $this->assertNull($profile->family);
        $this->assertNull($profile->issuerCode);
        $this->assertNull($profile->currency);
    }

    public function test_a_card_issued_abroad_is_answered_against_a_named_country(): void
    {
        $this->fakeBin();

        $profile = $this->provider->lookup('45717360');

        $this->assertTrue($profile->issuedOutside('TR'));
        $this->assertTrue($profile->issuedIn('DK'));
    }

    public function test_lookup_asks_for_the_first_8_digits(): void
    {
        $this->fakeBin();

        $this->provider->lookup('4571736012345678');

        Http::assertSent(fn ($r) => $r->url() === 'https://data.handyapi.com/bin/45717360');
    }

    public function test_an_api_key_is_sent_when_one_is_configured(): void
    {
        $this->fakeBin();

        (new HandyApiBinLookup(['api_key' => 'secret']))->lookup('45717360');

        Http::assertSent(fn ($r) => $r->header('x-api-key') === ['secret']);
    }

    public function test_no_api_key_header_is_sent_without_one(): void
    {
        $this->fakeBin();

        $this->provider->lookup('45717360');

        Http::assertSent(fn ($r) => $r->header('x-api-key') === []);
    }

    public function test_a_bin_handyapi_does_not_know_resolves_nothing(): void
    {
        Http::fake(['*' => Http::response(['Status' => 'NOT FOUND'])]);

        $this->assertNull($this->provider->lookup('00000000'));
    }

    public function test_an_unreachable_service_resolves_nothing_instead_of_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->assertNull($this->provider->lookup('45717360'));
    }

    public function test_a_refused_request_resolves_nothing(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $this->assertNull($this->provider->lookup('45717360'));
    }

    public function test_the_cache_holds_the_payload_rather_than_the_profile(): void
    {
        $this->fakeBin();

        $this->provider->lookup('45717360');

        $this->assertSame('VISA', Cache::get('payline:bin-lookup:handyapi:45717360')['Scheme']);
    }

    public function test_a_cached_payload_is_mapped_without_asking_again(): void
    {
        Cache::put('payline:bin-lookup:handyapi:45717360', [
            'Status' => 'SUCCESS',
            'Scheme' => 'MASTERCARD',
            'Type' => 'CREDIT',
            'CardTier' => 'CORPORATE',
            'Country' => ['A2' => 'TR'],
        ], 60);

        Http::fake();

        $profile = $this->provider->lookup('45717360');

        $this->assertSame(CardScheme::Mastercard, $profile->scheme);
        $this->assertSame(CardCategory::Commercial, $profile->category);
        $this->assertSame('TR', $profile->issuerCountry);
        Http::assertNothingSent();
    }

    public function test_a_resolved_profile_is_served_from_the_cache(): void
    {
        $this->fakeBin();

        $this->provider->lookup('45717360');
        $this->provider->lookup('45717360');

        Http::assertSentCount(1);
    }

    public function test_a_bin_that_resolves_nothing_is_asked_again(): void
    {
        Http::fake(['*' => Http::response(['Status' => 'NOT FOUND'])]);

        $this->provider->lookup('00000000');
        $this->provider->lookup('00000000');

        Http::assertSentCount(2);
    }

    public function test_caching_is_off_with_a_ttl_of_zero(): void
    {
        $this->fakeBin();

        $provider = new HandyApiBinLookup(['cache_ttl' => 0]);

        $provider->lookup('45717360');
        $provider->lookup('45717360');

        Http::assertSentCount(2);
    }

    public function test_a_configured_base_url_wins(): void
    {
        $this->fakeBin();

        (new HandyApiBinLookup(['base_url' => 'https://mirror.test/']))->lookup('45717360');

        Http::assertSent(fn ($r) => $r->url() === 'https://mirror.test/bin/45717360');
    }

    public function test_the_manager_resolves_the_provider_by_name(): void
    {
        $this->assertInstanceOf(HandyApiBinLookup::class, $this->app->make('payline.bin_lookup')->driver('handyapi'));
    }

    public function test_the_registered_provider_answers_through_the_manager(): void
    {
        $this->fakeBin();

        $profile = $this->app->make('payline.bin_lookup')->lookup('4571736012345678');

        $this->assertSame('DK', $profile->issuerCountry);
    }

    private function fakeBin(): void
    {
        Http::fake(['*' => Http::response([
            'Status' => 'SUCCESS',
            'Scheme' => 'VISA',
            'Type' => 'DEBIT',
            'Issuer' => 'JYSKE BANK',
            'CardTier' => 'DANKORT',
            'Country' => [
                'A2' => 'DK',
                'A3' => 'DNK',
                'N3' => '208',
                'ISD' => '45',
                'Name' => 'Denmark',
                'Cont' => 'Europe',
            ],
            'Luhn' => true,
        ])]);
    }
}
