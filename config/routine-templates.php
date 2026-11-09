<?php

return [
    'default' => [
        'label' => 'Start from scratch',
        'preferred_activity_start' => null,
        'work_windows' => [],
        'review_times' => ['morning' => null, 'afternoon' => null, 'evening' => null],
    ],
    'nunez-week1' => [
        'label' => 'Week one: one 15-minute weekday block at 7 PM',
        'preferred_activity_start' => '19:00',
        'work_windows' => [
            'mon' => [['start' => '19:00', 'end' => '19:15']],
            'tue' => [['start' => '19:00', 'end' => '19:15']],
            'wed' => [['start' => '19:00', 'end' => '19:15']],
            'thu' => [['start' => '19:00', 'end' => '19:15']],
            'fri' => [['start' => '19:00', 'end' => '19:15']],
        ],
        'review_times' => ['morning' => '10:45', 'afternoon' => '14:00', 'evening' => '20:00'],
    ],
];
