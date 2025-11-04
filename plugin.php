<?php
// AnonGuard depersonalizer plugin configuration
return array(
    'name'               => 'AnonGuard — деперсонификация заказов и контактов',
    'vendor'             => 'incyber',
    'version'            => '0.4.1',
    'img'                => 'img/icon.png',
    'shop_version_from'  => '8.0',
    'shop_version_to'    => '',
    'description'        => 'Обезличивает персональные данные в заказах и контактах старше заданного срока.',
    'license'            => 'proprietary',
    'author'             => 'Incyber',
    'handlers'           => array(
        'backend_menu' => 'backendMenu',
    ),
);
