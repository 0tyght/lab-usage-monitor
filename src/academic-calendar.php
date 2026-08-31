<?php

declare(strict_types=1);

function semester_labels(): array
{
    // Keep the existing database value "summer" to preserve historical records.
    return ['1'=>'1 — ภาคต้น', '2'=>'2 — ภาคปลาย', 'summer'=>'3 — ภาคฤดูร้อน'];
}

function academic_term_code(int $year, string $semester): string
{
    return $year . '/' . ($semester === 'summer' ? '3' : $semester);
}

function nu_academic_presets(): array
{
    // Undergraduate regular program only. No guessed dates for other years.
    // Inclusive end dates are the day before the announced semester break.
    return [2569=>[
        'source'=>'https://reg6.nu.ac.th/registrar/calendar.asp?schedulegroupid=1000&acadyear=2569&semester=1',
        'source_label'=>'ปฏิทิน ม.นเรศวร ปี 2569 · ปริญญาตรี ภาคปกติ',
        'checked_on'=>'2026-08-31',
        'terms'=>[
            '1'=>['start'=>'2026-06-22', 'end'=>'2026-10-25'],
            '2'=>['start'=>'2026-11-16', 'end'=>'2027-03-21', 'source'=>'https://reg6.nu.ac.th/registrar/calendar.asp?schedulegroupid=1000&acadyear=2569&semester=2'],
            'summer'=>['start'=>'2027-03-29', 'end'=>'2027-05-30', 'source'=>'https://reg6.nu.ac.th/registrar/calendar.asp?schedulegroupid=1000&acadyear=2569&semester=3'],
        ],
    ], 2568=>[
        'source'=>'https://reg6.nu.ac.th/publish/NUREG_calendar2568_U20250408.pdf',
        'source_label'=>'ม.นเรศวร ปี 2568 · ปริญญาตรี ภาคปกติ · หน้า 3',
        'checked_on'=>'2026-08-31',
        'terms'=>[
            '1'=>['start'=>'2025-06-23', 'end'=>'2025-10-26'],
            '2'=>['start'=>'2025-11-17', 'end'=>'2026-03-22'],
            'summer'=>['start'=>'2026-03-30', 'end'=>'2026-05-31'],
        ],
    ]];
}

function valid_iso_date(string $date): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
        && checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4));
}

function thai_date_label(string $value): string
{
    if (!valid_iso_date($value)) return $value;
    $months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return (int)substr($value, 8, 2) . ' ' . $months[(int)substr($value, 5, 2)-1] . ' ' . ((int)substr($value, 0, 4)+543);
}

function thai_month_label(string $value): string
{
    $months = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
    return $months[(int)substr($value, 5, 2)-1] . ' ' . ((int)substr($value, 0, 4)+543);
}
