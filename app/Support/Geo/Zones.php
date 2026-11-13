<?php

declare(strict_types=1);

namespace App\Support\Geo;

/**
 * Authored ISO 3166-2 subdivision seed data (spec §3.23 F-3.23-005, WP-24).
 *
 * Ships the four countries the spec's address forms need first — US, CA, AU,
 * IN — imported into the `zones` table by `emp:geo-import`. Codes are the
 * ISO 3166-2 suffix (without the country prefix), matching what /api/v1/zones
 * returns and what MaxMind reports in `subdivisions[0].iso_code`.
 *
 * More countries are added through the admin CRUD or a CSV/JSON import —
 * never by editing this file at deploy time.
 */
final class Zones
{
    /**
     * country code => list of [code, name, type]
     *
     * @return array<string, list<array{0: string, 1: string, 2: string}>>
     */
    public static function seed(): array
    {
        return [
            'US' => self::unitedStates(),
            'CA' => self::canada(),
            'AU' => self::australia(),
            'IN' => self::india(),
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private static function unitedStates(): array
    {
        return [
            ['AL', 'Alabama', 'state'], ['AK', 'Alaska', 'state'], ['AZ', 'Arizona', 'state'],
            ['AR', 'Arkansas', 'state'], ['CA', 'California', 'state'], ['CO', 'Colorado', 'state'],
            ['CT', 'Connecticut', 'state'], ['DE', 'Delaware', 'state'], ['FL', 'Florida', 'state'],
            ['GA', 'Georgia', 'state'], ['HI', 'Hawaii', 'state'], ['ID', 'Idaho', 'state'],
            ['IL', 'Illinois', 'state'], ['IN', 'Indiana', 'state'], ['IA', 'Iowa', 'state'],
            ['KS', 'Kansas', 'state'], ['KY', 'Kentucky', 'state'], ['LA', 'Louisiana', 'state'],
            ['ME', 'Maine', 'state'], ['MD', 'Maryland', 'state'], ['MA', 'Massachusetts', 'state'],
            ['MI', 'Michigan', 'state'], ['MN', 'Minnesota', 'state'], ['MS', 'Mississippi', 'state'],
            ['MO', 'Missouri', 'state'], ['MT', 'Montana', 'state'], ['NE', 'Nebraska', 'state'],
            ['NV', 'Nevada', 'state'], ['NH', 'New Hampshire', 'state'], ['NJ', 'New Jersey', 'state'],
            ['NM', 'New Mexico', 'state'], ['NY', 'New York', 'state'], ['NC', 'North Carolina', 'state'],
            ['ND', 'North Dakota', 'state'], ['OH', 'Ohio', 'state'], ['OK', 'Oklahoma', 'state'],
            ['OR', 'Oregon', 'state'], ['PA', 'Pennsylvania', 'state'], ['RI', 'Rhode Island', 'state'],
            ['SC', 'South Carolina', 'state'], ['SD', 'South Dakota', 'state'], ['TN', 'Tennessee', 'state'],
            ['TX', 'Texas', 'state'], ['UT', 'Utah', 'state'], ['VT', 'Vermont', 'state'],
            ['VA', 'Virginia', 'state'], ['WA', 'Washington', 'state'], ['WV', 'West Virginia', 'state'],
            ['WI', 'Wisconsin', 'state'], ['WY', 'Wyoming', 'state'],
            ['DC', 'District of Columbia', 'district'],
            ['AS', 'American Samoa', 'outlying area'], ['GU', 'Guam', 'outlying area'],
            ['MP', 'Northern Mariana Islands', 'outlying area'], ['PR', 'Puerto Rico', 'outlying area'],
            ['UM', 'United States Minor Outlying Islands', 'outlying area'],
            ['VI', 'Virgin Islands, U.S.', 'outlying area'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private static function canada(): array
    {
        return [
            ['AB', 'Alberta', 'province'], ['BC', 'British Columbia', 'province'],
            ['MB', 'Manitoba', 'province'], ['NB', 'New Brunswick', 'province'],
            ['NL', 'Newfoundland and Labrador', 'province'], ['NS', 'Nova Scotia', 'province'],
            ['ON', 'Ontario', 'province'], ['PE', 'Prince Edward Island', 'province'],
            ['QC', 'Quebec', 'province'], ['SK', 'Saskatchewan', 'province'],
            ['NT', 'Northwest Territories', 'territory'], ['NU', 'Nunavut', 'territory'],
            ['YT', 'Yukon', 'territory'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private static function australia(): array
    {
        return [
            ['NSW', 'New South Wales', 'state'], ['QLD', 'Queensland', 'state'],
            ['SA', 'South Australia', 'state'], ['TAS', 'Tasmania', 'state'],
            ['VIC', 'Victoria', 'state'], ['WA', 'Western Australia', 'state'],
            ['ACT', 'Australian Capital Territory', 'territory'],
            ['NT', 'Northern Territory', 'territory'],
        ];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private static function india(): array
    {
        return [
            ['AP', 'Andhra Pradesh', 'state'], ['AR', 'Arunachal Pradesh', 'state'],
            ['AS', 'Assam', 'state'], ['BR', 'Bihar', 'state'], ['CT', 'Chhattisgarh', 'state'],
            ['GA', 'Goa', 'state'], ['GJ', 'Gujarat', 'state'], ['HR', 'Haryana', 'state'],
            ['HP', 'Himachal Pradesh', 'state'], ['JH', 'Jharkhand', 'state'],
            ['KA', 'Karnataka', 'state'], ['KL', 'Kerala', 'state'], ['MP', 'Madhya Pradesh', 'state'],
            ['MH', 'Maharashtra', 'state'], ['MN', 'Manipur', 'state'], ['ML', 'Meghalaya', 'state'],
            ['MZ', 'Mizoram', 'state'], ['NL', 'Nagaland', 'state'], ['OR', 'Odisha', 'state'],
            ['PB', 'Punjab', 'state'], ['RJ', 'Rajasthan', 'state'], ['SK', 'Sikkim', 'state'],
            ['TN', 'Tamil Nadu', 'state'], ['TG', 'Telangana', 'state'], ['TR', 'Tripura', 'state'],
            ['UP', 'Uttar Pradesh', 'state'], ['UT', 'Uttarakhand', 'state'], ['WB', 'West Bengal', 'state'],
            ['AN', 'Andaman and Nicobar Islands', 'union territory'],
            ['CH', 'Chandigarh', 'union territory'],
            ['DH', 'Dadra and Nagar Haveli and Daman and Diu', 'union territory'],
            ['DL', 'Delhi', 'union territory'],
            ['JK', 'Jammu and Kashmir', 'union territory'],
            ['LA', 'Ladakh', 'union territory'],
            ['LD', 'Lakshadweep', 'union territory'],
            ['PY', 'Puducherry', 'union territory'],
        ];
    }
}
