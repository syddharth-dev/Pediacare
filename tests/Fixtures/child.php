<?php

declare(strict_types=1);

return [
    'first_name' => 'FAKE Child', 'middle_name' => '', 'last_name' => 'FAKE Family', 'suffix' => '',
    'sex' => 'F', 'date_of_birth' => (new DateTimeImmutable('today'))->modify('-1 year')->format('Y-m-d'),
    'place_of_birth' => 'FAKE Birthplace', 'purok_id' => '7', 'address' => 'FAKE Address',
    'mother_name' => 'FAKE Mother', 'father_name' => '', 'guardian_name' => 'FAKE Guardian',
    'guardian_relation' => 'Mother', 'contact_no' => 'FAKE Not a phone', 'philhealth_no' => '',
    'birth_weight_kg' => '', 'birth_length_cm' => '', 'notes' => '',
];
