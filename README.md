# payline-handyapi-bin-lookup

[![Tests](https://github.com/x-laravel/payline-handyapi-bin-lookup/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/payline-handyapi-bin-lookup/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

[HandyAPI](https://www.handyapi.com/bin-list) BIN lookup provider for
[x-laravel/payline](https://github.com/x-laravel/payline).

HandyAPI answers the scheme, the funding type, the issuing bank, the issuing country and
the card tier behind the first eight digits of a card number, for cards issued anywhere
including Turkey. It names no card family, so commission rates keyed on `bonus` or
`maximum` will not match a profile resolved here.

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- x-laravel/payline

## Installation

```bash
composer require x-laravel/payline-handyapi-bin-lookup
```

## Configuration

Name `handyapi` as the BIN lookup driver in `config/payline.php`:

```php
'bin_lookup' => [
    'default' => env('PAYLINE_BIN_LOOKUP_DRIVER', 'handyapi'),
    'drivers' => [
        'handyapi' => [
            'api_key' => env('HANDYAPI_KEY'),
        ],
    ],
],
```

| Key | Default | Meaning |
|-----|---------|---------|
| `api_key` | `null` | Sent as `x-api-key` when set |
| `base_url` | `https://data.handyapi.com` | Address to query |
| `cache_ttl` | `2592000` | Seconds a resolved profile is kept; `0` turns caching off |
| `cache_store` | `null` | Cache store name; the application default when absent |
| `timeout` | `5` | Seconds to wait for an answer |

The endpoint answers without a key at a rate limited by address. Register for one before
relying on it in earnest; the header goes out only when `api_key` is set.

There is one address for everyone, so `payline.test_mode` does not apply here.

## Failure Is Quiet

Every failure resolves to `null` rather than an exception: a BIN the service does not
hold, a refused key, a timeout and an unreachable host alike. A BIN lookup only improves
gateway selection, so it must never be the reason a payment fails. Routing falls back to
the default gateway, exactly as it does when no lookup is configured.

A BIN that is not held comes back as HTTP 200 with `{"Status":"NOT FOUND"}`, so the
provider reads `Status` rather than the status code.

A resolved profile is cached for 30 days, since a BIN belongs to an issuer for as long
as the range exists. An answer that resolves nothing is not cached, so a newly issued
range works the next time it is asked about.

## What HandyAPI Fills

| `CardProfile` | HandyAPI | Note |
|---------------|----------|------|
| `bin` | the queried digits | |
| `scheme` | `Scheme` | |
| `type` | `Type` | |
| `category` | `CardTier` | only when the tier names one, such as `CORPORATE` |
| `productType` | `CardTier` | such as `CLASSIC`, `STANDARD`, `DANKORT` |
| `issuer` | `Issuer` | |
| `issuerCountry` | `Country.A2` | ISO 3166-1 alpha-2 |
| `source` | always `handyapi` | |
| `raw` | the whole payload | the Luhn flag and the country's other codes live here |

`family`, `issuerCode`, `currency`, `prepaid`, `numberLength` and `localSchemes` stay
null.

The tier fills two fields because HandyAPI folds two ideas into one: `CORPORATE` and
`BUSINESS` say the card is commercial, while `CLASSIC`, `STANDARD` and `PLATINUM` only
name a product level and leave the category open.

## Usage

Payline calls the provider on its own while routing a payment. To ask directly:

```php
use XLaravel\Payline\BinLookupManager;

$profile = app(BinLookupManager::class)->lookup('4571736012345678');

$profile?->issuerCountry;          // 'DK'
$profile?->issuedOutside('TR');    // true
$profile?->issuer;                 // 'JYSKE BANK'
```

A routing policy can then keep foreign cards on one gateway:

```php
class ForeignCardsGoToQnb implements GatewayRoutingPolicy
{
    public function allows(Gateway $gateway, PaymentRequest $request, TransactionType $operation): bool
    {
        $profile = $request->card?->profile ?? $request->cardProfile;

        if ($profile === null || ! $profile->issuedOutside(config('app.country'))) {
            return true;
        }

        return $gateway->getName() === 'qnb';
    }
}
```

A profile that names no country answers `false` to both `issuedIn()` and
`issuedOutside()`, so a policy written this way steps aside rather than rejecting a card
it knows nothing about.

## Testing

```bash
composer test
```

```bash
docker compose --profile php84 up --build
```

## License

MIT
