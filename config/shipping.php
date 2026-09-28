<?php

$provinceCodes = [
    '1',
    '4',
    '8',
    '11',
    '12',
    '14',
    '15',
    '19',
    '20',
    '22',
    '24',
    '25',
    '31',
    '33',
    '37',
    '38',
    '40',
    '42',
    '44',
    '46',
    '48',
    '51',
    '52',
    '56',
    '66',
    '68',
    '75',
    '79',
    '80',
    '82',
    '86',
    '91',
    '92',
    '96',
];

$regions = [];

foreach ($provinceCodes as $code) {
    $regions['province_' . $code] = [$code];
}

return [
    'locations' => [
        'base_url' => 'https://provinces.open-api.vn/api/v2',
        'cache_seconds' => 86400,
    ],

    /*
     * Key khớp delivery_methods.region.
     * Value là danh sách province_id được phép giao.
     *
     * NULL trên delivery_methods.region = toàn quốc.
     *
     * Có thể bổ sung vùng gồm nhiều tỉnh sau này:
     * 'custom_region' => ['...', '...'],
     */
    'regions' => $regions,
];
