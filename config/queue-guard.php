<?php

declare(strict_types=1);

return [
    'paths' => ['app'],
    'exclude' => ['tests', 'vendor'],

    'rules' => [
        'QG001' => ['enabled' => true, 'severity' => 'error'],
        'QG002' => ['enabled' => true, 'severity' => 'error'],
        'QG003' => ['enabled' => true, 'severity' => 'error'],
        'QG004' => ['enabled' => true, 'severity' => 'warning'],
        'QG005' => ['enabled' => true, 'severity' => 'warning'],
        'QG006' => ['enabled' => true, 'severity' => 'warning'],
        'QG007' => ['enabled' => true, 'severity' => 'error'],
        'QG008' => ['enabled' => true, 'severity' => 'error'],
        'QG009' => ['enabled' => true, 'severity' => 'error'],
    ],

    // Add any custom external service classes (e.g. custom payment gateways, SMS clients)
    'externalCallClasses' => [],

    // Add any domain-specific financial service classes
    'financialCallClasses' => [],

    'maxCallDepth' => 2,
    'baseline' => null,
    'format' => 'console',
    'failOn' => 'error',
];
