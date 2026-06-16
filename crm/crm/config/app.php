<?php
/**
 * Main Application Configuration
 */
return [
    'name' => 'CRM Travel Agency',
    'version' => '1.0.0',
    'debug' => filter_var(getenv('APP_DEBUG') ?: true, FILTER_VALIDATE_BOOLEAN),
    'url' => getenv('APP_URL') ?: 'http://localhost/crm',
    'timezone' => 'Asia/Tehran',
    
    // Feature Toggles - enable/disable features
    'features' => [
        'payment_gateway' => true,
        'sms' => true,
        'pipelines' => true,
        'reports' => true,
        'activity_log' => true,
    ],
    
    // Database
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'crm_travel',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    
    // Payment Gateway (Zibal)
    'zibal' => [
        'merchant' => getenv('ZIBAL_MERCHANT') ?: 'zibal',
        'callback_url' => getenv('ZIBAL_CALLBACK_URL') ?: 'http://localhost/crm/payment/verify',
        'api_url' => 'https://gateway.zibal.ir',
    ],
    
    // SMS (IPPanel)
    'sms' => [
        'api_token' => getenv('SMS_API_TOKEN') ?: '',
        'from_number' => getenv('SMS_FROM_NUMBER') ?: '+983000505',
        'api_url' => 'https://edge.ippanel.com/v1/api/send',
    ],
    
    // Pagination
    'pagination' => [
        'per_page' => 20,
    ],
];