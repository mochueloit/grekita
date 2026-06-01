<?php

namespace App\Services\Inventory;

use App\Models\Location;
use Illuminate\Support\Str;

class LocationResolver
{
    public const PRIMARY_LOCATION_SLUG = 'puerto-ordaz';

    /** @var array<string, array{name: string, slug: string}> */
    private const KNOWN_ACCOUNTS = [
        '482845934' => ['name' => 'Sede Lechería', 'slug' => 'lecheria'],
        '82385465' => ['name' => 'Sede Puerto Ordaz', 'slug' => 'puerto-ordaz'],
        '7196119' => ['name' => 'Sede Caracas', 'slug' => 'caracas'],
    ];

    /** @var array<string, string> */
    private const LEGACY_SLUGS = [
        'tiendagrekaccs' => 'caracas',
    ];

    public function resolveFromCuentaMl(string $cuentaMl): Location
    {
        $accountId = $this->extractAccountId($cuentaMl);
        $config = self::KNOWN_ACCOUNTS[$accountId] ?? null;

        if ($config !== null) {
            return $this->resolveKnownLocation($config);
        }

        $name = trim(preg_replace('/\s*\(\d+\)\s*$/', '', $cuentaMl) ?: $cuentaMl);
        $slug = Str::slug($name);

        if (isset(self::LEGACY_SLUGS[$slug])) {
            return $this->resolveKnownLocation([
                'name' => 'Sede Caracas',
                'slug' => self::LEGACY_SLUGS[$slug],
            ]);
        }

        return Location::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name],
        );
    }

    /**
     * @param  array{name: string, slug: string}  $config
     */
    private function resolveKnownLocation(array $config): Location
    {
        $legacySlugs = array_keys(array_filter(
            self::LEGACY_SLUGS,
            fn (string $canonicalSlug): bool => $canonicalSlug === $config['slug'],
        ));

        $location = Location::query()
            ->where('slug', $config['slug'])
            ->when($legacySlugs !== [], fn ($query) => $query->orWhereIn('slug', $legacySlugs))
            ->first();

        if ($location !== null) {
            $location->update([
                'name' => $config['name'],
                'slug' => $config['slug'],
            ]);

            return $location->fresh() ?? $location;
        }

        return Location::query()->create($config);
    }

    private function extractAccountId(string $cuentaMl): string
    {
        if (preg_match('/\((\d+)\)/', $cuentaMl, $matches)) {
            return $matches[1];
        }

        return $cuentaMl;
    }
}
