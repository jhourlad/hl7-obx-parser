<?php

namespace App\Services;

class ObxService
{
    /**
     * Parse HL7 OBX segment
     * @param $string
     * @return object|void
     */
    public function parse($string): object
    {
        $message = json_decode($string);
        if(!$message) {
            return;
        }

        $obx = array_values(
            array_filter(
                $message->OBX, function ($e) {
                    return $e->value_type == 'FT';
                }
            )
        );

        $obx = $obx[0]->value;
        $obx = str_replace(["\\.nf\\", "\\R\\", "Np", "DR.", "...", "\\F\\", "\\H\\", "\\N\\"], ['', "\\.br\\", '', '', '', '', '', ''], $obx);
        $obx = explode("\\.br\\", $obx);
        $obx = preg_replace('/^[0-9]+/', '', $obx);
        $obx = $this->parseLineItems($obx);
        $data = [
            'medicare_number' => '',
            'patient' => $message->PID->fullname,
            'referrer' => !empty($message->PV1) ? $message->PV1->referring_provider[1] . ' ' . $message->PV1->referring_provider[2] : null,
            'phone_enquiries' => null,
            'name_of_test' => $message->OBR->report_name,
            'requested_tests' => $message->OBR->report_name,
            'requested' => null,
            'reported' => $message->MSH->date,
            'requestcomplete' => true,
        ];

        if (count($obx) > 0) { // > 30
            for ($a = 0; $a < count($obx); $a++) { //<= 24
                $tmp = explode(':', $obx[$a]);

                if (count($tmp) == 1) {
                    // $data[$a] = $tmp;
                } elseif ($a == 2) {
                    if ($this->hasDate($tmp[1])) {
                        $birthdate = $this->hasDate($tmp[1]) ? date_format($this->hasDate($tmp[1]), 'Y-m-d') : null;
                        $data['birthdate'] = trim(str_replace('Age', '', $birthdate));
                        $data['age'] = trim(str_replace(['Sex', 'Y'], '', $tmp[2])) * 1;
                        $data['sex'] = trim($tmp[3]);
                    }
                } else {
                    $key = strtolower(trim($tmp[0]));
                    $key = str_replace(['  ', ' '], [' ', '_'], $key);
                    $tmp[1] = trim($tmp[1]);

                    if ($key == 'start_patient' || $key == 'referred_by') {
                        if ($key == 'start_patient') {
                            $key = 'patient';
                        } elseif ($key == 'referred_by') {
                            $key = 'referrer';
                        }
                        $tmp = explode(',', $tmp[1]);
                        $value = count($tmp) > 1 ? trim($tmp[1]) . ' ' . trim($tmp[0]) : $tmp[0];
                    } elseif (strtolower($tmp[1]) == 'y') {
                        $value = 1;
                    } elseif (strtolower($tmp[1]) == 'n') {
                        $value = 0;
                    } else {
                        $value = $tmp[1];
                    }

                    if (!in_array($key, ['start_of_result'])) {
                        if (is_string($value) && ($tmp = $this->hasDate($value))) {
                            $value = date_format($tmp, 'Y-m-d');
                        }
                        $data[$key] = $value;
                    }
                }
            }
        }

        $data['preview'] = $obx;
        return (object) $data;
    }

    /**
     * Checks for Hl7 string date then convert to Date() object
     * @param $str
     * @return DateTime|false
     */
    protected function hasDate($str) : \DateTime
    {
        if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/', $str, $matches)) {
            if (checkdate($matches[2], $matches[1], $matches[3])) {
                return date_create("{$matches[3]}-$matches[2]-$matches[1]");
            }
        }
        return false;
    }

    /**
     * Parse clinical details line items
     * @param $items
     * @return mixed
     */
    protected function parseLineItems($items): array
    {
        for ($a = 30; $a < count($items); $a++) {
            $data = explode('  ', $items[$a]);

            $data = array_map(
                function ($d) {
                    return trim($d);
                }, $data
            );

            $data = array_filter(
                $data, function ($x) {
                    return $x != '';
                }
            );

            $data = array_values($data);
            switch (count($data)) {
            case 3:
                $data = [
                    str_pad($data[0], 30),
                    str_pad($data[1], 10),
                    str_pad($data[2], 20),
                ];
                break;

            case 4:
                $data = [
                    str_pad($data[0], 30),
                    str_pad($data[1], 10),
                    str_pad($data[2], 20),
                    $this->range_pad($data[3]),
                ];
                break;

            case 5:
                $data = [
                    str_pad($data[0], 30),
                    str_pad($data[1], 10),
                    str_pad($data[2], 20),
                    $this->range_pad($data[3] . ' ' . $data[4]),
                ];
                break;

            case 6:
                $data = [
                    str_pad($data[0], 30),
                    str_pad($data[2], 10),
                    str_pad($data[3], 10),
                    str_pad($data[4], 10),
                    str_pad($data[5], 10),
                    $this->range_pad($data[1]),
                ];
                break;

            case 7:
                $data = [
                    str_pad($data[0], 30),
                    str_pad($data[1], 10),
                    str_repeat(' ', 20),
                    $this->range_pad($data[2]),
                    PHP_EOL,
                    str_pad($data[3], 30),
                    str_pad($data[4], 10),
                    str_pad($data[5], 20),
                    $this->range_pad($data[6]),
                ];
                break;

            case 8:
                $data = [
                    str_pad($data[0], 30),
                    str_pad($data[1], 10),
                    str_pad($data[2], 20),
                    $this->range_pad($data[3]),
                    PHP_EOL,
                    str_pad($data[4], 30),
                    str_pad($data[5], 10),
                    str_pad($data[6], 20),
                    $this->range_pad($data[7]),
                ];
                break;
            }
            $items[$a] = implode('', $data);
        }
        return $items;
    }

    /**
     * Pad clinical results range string
     * @param $str
     * @return mixed|string
     */
    protected function range_pad($str): string
    {
        if (!str_contains($str, '(')) {
            return $str;
        }

        if (str_contains($str, '-')) {
            preg_match('/(.+ )?\((.+)\-(.+)\)/', $str, $tmp);
            return str_pad($tmp[1], 5) . '(' . str_pad($tmp[2], 6, ' ', STR_PAD_BOTH) . ' - ' . str_pad($tmp[3], 6, ' ', STR_PAD_BOTH) . ')';
        } else {
            preg_match('/(.+ )?\((.+)?\)/', $str, $tmp);
            return str_pad($tmp[1], 5) . '(' . str_pad($tmp[2], 14, ' ', STR_PAD_BOTH) . ' )';
        }
    }
}
