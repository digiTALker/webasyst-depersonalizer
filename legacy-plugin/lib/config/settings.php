<?php
return array(
    'retention_days' => array(
        'title'        => _wp('Retention days'),
        'description'  => _wp('Orders and contacts older than this number of days will be depersonalized.'),
        'value'        => 365,
        'control_type' => waHtmlControl::INPUT,
    ),
    'keep_geo' => array(
        'title'        => _wp('Keep geo statistics'),
        'description'  => _wp('Preserve country, region and city information.'),
        'value'        => 0,
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    'wipe_comments' => array(
        'title'        => _wp('Wipe order comments'),
        'value'        => 0,
        'control_type' => waHtmlControl::CHECKBOX,
    ),
    'anonymize_contact_id' => array(
        'title'        => _wp('Replace contact_id with anonymous contact'),
        'description'  => _wp('Store depersonalized orders under a special anonymous contact.'),
        'value'        => 0,
        'control_type' => waHtmlControl::CHECKBOX,
    ),
);
